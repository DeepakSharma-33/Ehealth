<?php
require_once __DIR__ . '/../../config/config.php';
start_session();

if (!is_logged_in()) {
    http_response_code(401);
    echo 'Unauthorized';
    exit;
}

$role = (string)get_session_role();
if (!in_array($role, ['doctor', 'executive', 'telestudio_doctor', 'patient'], true)) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$diagnosisId = (int)($_GET['diagnosisId'] ?? 0);
if ($diagnosisId <= 0) {
    http_response_code(400);
    echo 'Invalid diagnosis ID.';
    exit;
}

$requestedType = strtolower(trim((string)($_GET['type'] ?? 'ehealth')));
$type = in_array($requestedType, ['tele', 'teleconsult', 'teleconsultation'], true)
    ? 'teleconsult'
    : 'ehealth';

$db = db();

function e(mixed $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES);
}

function table_exists(mysqli $db, string $table): bool {
    $safe = $db->real_escape_string($table);
    $res = $db->query("SHOW TABLES LIKE '{$safe}'");
    if (!$res) return false;
    $exists = $res->num_rows > 0;
    $res->free();
    return $exists;
}

function existing_tables(mysqli $db, array $candidates): array {
    $out = [];
    foreach ($candidates as $table) {
        if (table_exists($db, $table)) {
            $out[] = $table;
        }
    }
    return $out;
}

function parse_timing_list(mixed $raw): array {
    $txt = trim((string)$raw);
    if ($txt === '') return [];

    $decoded = json_decode($txt, true);
    if (is_array($decoded)) {
        $out = [];
        foreach ($decoded as $item) {
            $v = trim((string)$item);
            if ($v !== '') $out[] = $v;
        }
        return $out;
    }

    return [$txt];
}

function ensure_teleconsult_payment_columns(mysqli $db): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $db->query("ALTER TABLE teleconsult_sessions ADD COLUMN IF NOT EXISTS payment_method ENUM('Cash','UPI') NULL AFTER remarks");
    $db->query("ALTER TABLE teleconsult_sessions ADD COLUMN IF NOT EXISTS payment_amount DECIMAL(8,2) NOT NULL DEFAULT 50.00 AFTER payment_method");
    $db->query("ALTER TABLE teleconsult_sessions ADD COLUMN IF NOT EXISTS payment_collected_by_role ENUM('doctor','executive') NULL AFTER payment_amount");
    $db->query("ALTER TABLE teleconsult_sessions ADD COLUMN IF NOT EXISTS payment_collected_by_id VARCHAR(20) NULL AFTER payment_collected_by_role");
    $db->query("ALTER TABLE teleconsult_sessions ADD COLUMN IF NOT EXISTS payment_collected_by_name VARCHAR(255) NULL AFTER payment_collected_by_id");
    $db->query("ALTER TABLE teleconsult_sessions ADD COLUMN IF NOT EXISTS payment_collected_at DATETIME NULL AFTER payment_collected_by_name");
}

$teleTables = existing_tables($db, ['telestudio_diagnosis', 'teleconsult_diagnosis']);
$source = $type;
$diag = null;

if ($source === 'teleconsult') {
    if (empty($teleTables)) {
        http_response_code(404);
        echo 'Teleconsult diagnosis table not found.';
        exit;
    }

    $requestedTable = strtolower(trim((string)($_GET['srcTable'] ?? '')));
    $candidateTables = $teleTables;
    if ($requestedTable !== '' && in_array($requestedTable, $teleTables, true)) {
        $candidateTables = [$requestedTable];
    }

    foreach ($candidateTables as $tbl) {
        $sql = "SELECT * FROM {$tbl} WHERE id = ? LIMIT 1";
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            continue;
        }
        $stmt->bind_param('i', $diagnosisId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            $diag = $row;
            break;
        }
    }

    if (!$diag) {
        http_response_code(404);
        echo 'Teleconsult diagnosis not found.';
        exit;
    }
} else {
    $stmt = $db->prepare('SELECT * FROM diagnosis_records WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $diagnosisId);
    $stmt->execute();
    $diag = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$diag) {
        http_response_code(404);
        echo 'Diagnosis record not found.';
        exit;
    }
}

$teleSession = null;
$teleSessionId = 0;
$paymentPending = false;
$paymentError = '';
$executiveList = [];

if ($source === 'teleconsult') {
    ensure_teleconsult_payment_columns($db);
    $teleSessionId = (int)($diag['session_id'] ?? 0);

    if ($teleSessionId > 0) {
        $s = $db->prepare(
            'SELECT ts.*,
                    ed.full_name AS ehealth_doctor_full_name,
                    td.full_name AS telestudio_doctor_full_name
             FROM teleconsult_sessions ts
             LEFT JOIN ehealth_center_doctors ed ON ed.doctor_id = ts.ehealth_doctor_id
             LEFT JOIN telestudio_doctors td ON td.doctor_id = ts.telestudio_doctor_id
             WHERE ts.id = ? LIMIT 1'
        );
        $s->bind_param('i', $teleSessionId);
        $s->execute();
        $teleSession = $s->get_result()->fetch_assoc();
        $s->close();
    }

    $paymentPending = empty($teleSession) || trim((string)($teleSession['payment_method'] ?? '')) === '';

    if ($paymentPending && in_array($role, ['doctor', 'executive'], true)) {
        $res = $db->query("SELECT executive_id, full_name FROM executives ORDER BY full_name ASC");
        if ($res) {
            while ($row = $res->fetch_assoc()) $executiveList[] = $row;
            $res->free();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $source === 'teleconsult' && isset($_POST['collect_payment'])) {
    if (!in_array($role, ['doctor', 'executive'], true)) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
    if ($teleSessionId <= 0) {
        $paymentError = 'Teleconsult session not linked. Cannot collect payment.';
    } else {
        $paymentMethod = trim((string)($_POST['payment_method'] ?? ''));
        $collectorRole = trim((string)($_POST['collector_role'] ?? ''));
        $collectorId = '';
        $collectorName = '';

        if (!in_array($paymentMethod, ['Cash', 'UPI'], true)) {
            $paymentError = 'Please select payment method.';
        } elseif (!in_array($collectorRole, ['doctor', 'executive'], true)) {
            $paymentError = 'Please select collector.';
        } elseif ($role === 'doctor' && $collectorRole !== 'doctor') {
            $paymentError = 'Doctor must collect payment as doctor.';
        } elseif ($role === 'executive' && $collectorRole !== 'executive') {
            $paymentError = 'Executive must collect payment as executive.';
        } else {
            if ($collectorRole === 'doctor') {
                $collectorId = (string)get_session_id();
                $collectorName = get_session_name() ?: 'Doctor';
            } else {
                $collectorId = (string)get_session_id();
                $collectorName = get_session_name() ?: 'Executive';
            }
        }

        if ($paymentError === '') {
            $amount = 50.00;
            $upd = $db->prepare('UPDATE teleconsult_sessions SET payment_method = ?, payment_amount = ?, payment_collected_by_role = ?, payment_collected_by_id = ?, payment_collected_by_name = ?, payment_collected_at = NOW() WHERE id = ?');
            $upd->bind_param('sdsssi', $paymentMethod, $amount, $collectorRole, $collectorId, $collectorName, $teleSessionId);
            $upd->execute();
            $upd->close();

            $url = strtok($_SERVER['REQUEST_URI'], '?');
            $qs  = $_GET;
            $redir = $url . '?' . http_build_query($qs);
            header('Location: ' . $redir);
            exit;
        }
    }
}

$patientId = trim((string)($diag['patient_id'] ?? ''));
if ($patientId === '') {
    http_response_code(404);
    echo 'Patient not found for diagnosis.';
    exit;
}

$patientStmt = $db->prepare(
    'SELECT patient_id, full_name, age, gender, mobile_no, address_full, photo_filename
     FROM patients
     WHERE patient_id = ?
     LIMIT 1'
);
$patientStmt->bind_param('s', $patientId);
$patientStmt->execute();
$patient = $patientStmt->get_result()->fetch_assoc();
$patientStmt->close();

if (!$patient) {
    http_response_code(404);
    echo 'Patient record not found.';
    exit;
}

$sessionPatientId = (string)($_SESSION['patient_id'] ?? '');
if ($role === 'patient') {
    if ($sessionPatientId === '' || $sessionPatientId !== $patientId) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
}

$patientPhotoUrl = patient_photo_url($patient['photo_filename'] ?? null);
$patientInitials = '';
$patientNameRaw = trim((string)($patient['full_name'] ?? ''));
if ($patientNameRaw !== '') {
    $parts = preg_split('/\s+/', $patientNameRaw);
    $first = strtoupper(substr((string)($parts[0] ?? ''), 0, 1));
    $second = strtoupper(substr((string)($parts[1] ?? ''), 0, 1));
    $patientInitials = $first . $second;
}
$patientAddress = trim((string)($patient['address_full'] ?? ''));

$diagnosisAt = trim((string)($diag['diagnosis_date'] ?? ''));
if ($diagnosisAt === '') {
    $diagnosisAt = date('Y-m-d H:i:s');
}

$vitalsStmt = $db->prepare(
    'SELECT bp_systolic, bp_diastolic, heart_rate, temperature, spo2, weight, recorded_at
     FROM patient_vitals
     WHERE patient_id = ?
       AND recorded_at <= ?
     ORDER BY recorded_at DESC
     LIMIT 1'
);
$vitalsStmt->bind_param('ss', $patientId, $diagnosisAt);
$vitalsStmt->execute();
$vitals = $vitalsStmt->get_result()->fetch_assoc();
$vitalsStmt->close();

$doctorId = '';
$doctorName = get_session_name() ?: 'Doctor';
$doctorQualification = '';
$doctorSpecialization = '';
$doctorReg = '';

$chiefComplaint = '';
$primaryDiagnosis = '';
$clinicalNotes = '';
$investigation = '';
$reviewAfter = '';
$remarks = '';
$medicines = [];

if ($source === 'teleconsult') {
    $doctorId = trim((string)($diag['telestudio_doctor_id'] ?? ''));
    $doctorName = trim((string)($diag['telestudio_doctor_name'] ?? '')) ?: $doctorName;

    if ($doctorId !== '') {
        $docStmt = $db->prepare(
            'SELECT full_name, qualification, specialization, registration_number, license_number
             FROM telestudio_doctors
             WHERE doctor_id = ?
             LIMIT 1'
        );
        if ($docStmt) {
            $docStmt->bind_param('s', $doctorId);
            $docStmt->execute();
            $doc = $docStmt->get_result()->fetch_assoc();
            $docStmt->close();

            if ($doc) {
                $doctorName = trim((string)($doc['full_name'] ?? '')) ?: $doctorName;
                $doctorQualification = trim((string)($doc['qualification'] ?? ''));
                $doctorSpecialization = trim((string)($doc['specialization'] ?? ''));
                $doctorReg = trim((string)($doc['registration_number'] ?? ($doc['license_number'] ?? '')));
            }
        }
    }

    $chiefComplaint = trim((string)($diag['chief_complaint'] ?? ($diag['consultation_notes'] ?? '')));
    $primaryDiagnosis = trim((string)($diag['primary_diagnosis'] ?? ''));
    $clinicalNotes = trim((string)($diag['consultation_notes'] ?? ($diag['clinical_notes'] ?? '')));
    $investigation = trim((string)($diag['investigation'] ?? ''));
    $reviewAfter = trim((string)($diag['review_after'] ?? ''));
    $remarks = trim((string)($diag['remarks'] ?? ''));

    $medJson = trim((string)($diag['medicines_json'] ?? ''));
    $decoded = json_decode($medJson, true);
    if (is_array($decoded)) {
        foreach ($decoded as $row) {
            if (!is_array($row)) continue;
            $name = trim((string)($row['name'] ?? ''));
            if ($name === '') continue;
            $medicines[] = [
                'medicine_name' => $name,
                'dosage' => trim((string)($row['dosage'] ?? '')),
                'timing_list' => is_array($row['timing'] ?? null)
                    ? array_values(array_filter(array_map(static fn($x) => trim((string)$x), $row['timing']), static fn($x) => $x !== ''))
                    : parse_timing_list($row['timing'] ?? ''),
                'meal_instruction' => trim((string)($row['mealInstruction'] ?? '')),
                'duration' => trim((string)($row['duration'] ?? '')),
            ];
        }
    }

    if (empty($medicines)) {
        $medStmt = $db->prepare(
            'SELECT medicine_name, dosage, timing, meal_instruction, duration
             FROM prescriptions
             WHERE diagnosis_id = ? AND patient_id = ?
             ORDER BY id ASC'
        );
        $medStmt->bind_param('is', $diagnosisId, $patientId);
        $medStmt->execute();
        $rows = $medStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $medStmt->close();

        foreach ($rows as $row) {
            $medicines[] = [
                'medicine_name' => trim((string)($row['medicine_name'] ?? '')),
                'dosage' => trim((string)($row['dosage'] ?? '')),
                'timing_list' => parse_timing_list($row['timing'] ?? ''),
                'meal_instruction' => trim((string)($row['meal_instruction'] ?? '')),
                'duration' => trim((string)($row['duration'] ?? '')),
            ];
        }
    }
} else {
    $doctorId = trim((string)($diag['doctor_id'] ?? ''));

    if ($doctorId !== '') {
        $docStmt = $db->prepare(
            'SELECT full_name, qualification, specialization, registration_number, license_number
             FROM ehealth_center_doctors
             WHERE doctor_id = ?
             LIMIT 1'
        );
        if ($docStmt) {
            $docStmt->bind_param('s', $doctorId);
            $docStmt->execute();
            $doc = $docStmt->get_result()->fetch_assoc();
            $docStmt->close();

            if ($doc) {
                $doctorName = trim((string)($doc['full_name'] ?? '')) ?: $doctorName;
                $doctorQualification = trim((string)($doc['qualification'] ?? ''));
                $doctorSpecialization = trim((string)($doc['specialization'] ?? ''));
                $doctorReg = trim((string)($doc['registration_number'] ?? ($doc['license_number'] ?? '')));
            }
        }
    }

    $chiefComplaint = trim((string)($diag['chief_complaint'] ?? ''));
    $primaryDiagnosis = trim((string)($diag['provisional_diagnosis'] ?? ''));
    $clinicalNotes = trim((string)($diag['clinical_notes'] ?? ''));
    $investigation = trim((string)($diag['investigation_prescribed'] ?? ''));
    $reviewAfter = trim((string)($diag['review_after'] ?? ''));
    $remarks = trim((string)($diag['remarks'] ?? ''));

    $medStmt = $db->prepare(
        'SELECT medicine_name, dosage, timing, meal_instruction, duration
         FROM prescriptions
         WHERE diagnosis_id = ? AND patient_id = ? AND doctor_id = ?
         ORDER BY id ASC'
    );
    $medStmt->bind_param('iss', $diagnosisId, $patientId, $doctorId);
    $medStmt->execute();
    $rows = $medStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $medStmt->close();

    foreach ($rows as $row) {
        $medicines[] = [
            'medicine_name' => trim((string)($row['medicine_name'] ?? '')),
            'dosage' => trim((string)($row['dosage'] ?? '')),
            'timing_list' => parse_timing_list($row['timing'] ?? ''),
            'meal_instruction' => trim((string)($row['meal_instruction'] ?? '')),
            'duration' => trim((string)($row['duration'] ?? '')),
        ];
    }
}

$centerDoctorId = '';
$centerDoctorName = '';
$teleDoctorId = $doctorId;
$teleDoctorName = $doctorName;
if ($source === 'teleconsult' && !empty($teleSession)) {
    $centerDoctorId = trim((string)($teleSession['ehealth_doctor_id'] ?? ''));
    $centerDoctorName = trim((string)($teleSession['ehealth_doctor_full_name'] ?? ($teleSession['ehealth_doctor_name'] ?? '')));
    $teleDoctorId = trim((string)($teleSession['telestudio_doctor_id'] ?? '')) ?: $doctorId;
    $teleDoctorName = trim((string)($teleSession['telestudio_doctor_full_name'] ?? '')) ?: $doctorName;
}

$ts = strtotime($diagnosisAt) ?: time();
$diagnosisDateHuman = date('d M Y', $ts);
$diagnosisTimeHuman = date('h:i A', $ts);

$patientInline = [];
$patientInline[] = 'ID: ' . ($patient['patient_id'] ?: '-');
$patientInline[] = 'Name: ' . ($patient['full_name'] ?: '-');
$patientInline[] = 'Age/Gender: ' . trim((string)($patient['age'] ?? '-')) . '/' . trim((string)($patient['gender'] ?? '-'));
$patientInline[] = 'Mobile: ' . ($patient['mobile_no'] ?: '-');
$patientInlineText = implode(' | ', $patientInline);

$vitalsInline = [];
if (!empty($vitals['bp_systolic']) || !empty($vitals['bp_diastolic'])) {
    $vitalsInline[] = 'BP: ' . trim((string)$vitals['bp_systolic']) . '/' . trim((string)$vitals['bp_diastolic']) . ' mmHg';
}
if (!empty($vitals['heart_rate'])) $vitalsInline[] = 'Pulse: ' . e($vitals['heart_rate']) . ' bpm';
if (!empty($vitals['temperature'])) $vitalsInline[] = 'Temp: ' . e($vitals['temperature']) . ' C';
if (!empty($vitals['spo2'])) $vitalsInline[] = 'SpO2: ' . e($vitals['spo2']) . '%';
if (!empty($vitals['weight'])) $vitalsInline[] = 'Wt: ' . e($vitals['weight']) . ' kg';

$doctorBottom = $doctorName ?: 'Doctor';
$doctorBottomMeta = implode(' | ', array_filter([
    $doctorQualification,
    $doctorSpecialization,
    $doctorReg !== '' ? ('Reg: ' . $doctorReg) : '',
]));

$base = app_base_url();
$logoUrl = $base . '/public/assets/SRMS_TRUST_LOGO.png';
$backUrl = $base . '/public/common/diagnosis_print_center.php';
if ($role === 'telestudio_doctor') {
    $backUrl = $base . '/public/telestudio_doctor/telestudio_history.php';
}
if ($role === 'patient') {
    $backUrl = $base . '/public/patient/patient_medical_records.php';
}

$slipTitle = $source === 'teleconsult' ? 'Teleconsult Diagnosis Slip' : 'Diagnosis Slip';
$printMode = strtolower(trim((string)($_GET['print'] ?? '')));
$paymentDone = ($source === 'teleconsult' && !$paymentPending && $teleSessionId > 0 && !empty($teleSession));
$isPaymentSlipView = ($source === 'teleconsult' && $printMode === 'payment' && $paymentDone);
$paymentSlipUrl = '';
if ($paymentDone) {
    $qs = $_GET;
    $qs['print'] = 'payment';
    $paymentSlipUrl = $base . '/public/ehealth_center_doctor/print_diagnosis.php?' . http_build_query($qs);
}
$paymentMethod = $paymentDone ? trim((string)($teleSession['payment_method'] ?? '')) : '';
$paymentAmount = $paymentDone ? (float)($teleSession['payment_amount'] ?? 50.00) : 50.00;
$paymentCollectorRole = $paymentDone ? trim((string)($teleSession['payment_collected_by_role'] ?? '')) : '';
$paymentCollectorId = $paymentDone ? trim((string)($teleSession['payment_collected_by_id'] ?? '')) : '';
$paymentCollectorName = $paymentDone ? trim((string)($teleSession['payment_collected_by_name'] ?? '')) : '';
$paymentCollectorLabel = '';
if ($paymentDone && ($paymentCollectorName !== '' || $paymentCollectorId !== '')) {
    $roleLabel = $paymentCollectorRole === 'executive' ? 'Executive' : 'Doctor';
    $paymentCollectorLabel = $roleLabel . ': ' . $paymentCollectorName . ($paymentCollectorId !== '' ? ' (' . $paymentCollectorId . ')' : '');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($slipTitle) ?> - <?= e($patient['patient_id']) ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: Arial, sans-serif;
            background: #eef6ef;
            padding: 16px;
            color: #122013;
        }

        .toolbar {
            max-width: 760px;
            margin: 0 auto 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            font-size: 13px;
        }

        .toolbar a {
            color: #1f6f32;
            text-decoration: none;
            font-weight: 700;
        }

        .toolbar a:hover { text-decoration: underline; }

        .print-btn {
            border: none;
            background: #1f6f32;
            color: #fff;
            padding: 7px 14px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 700;
            text-decoration: none;
            display: inline-block;
        }
        .toolbar a.print-btn { color: #fff; }

        .pay-card {
            max-width: 560px;
            margin: 30px auto 0;
            background: #fff;
            border: 1px solid #d8e7d9;
            border-radius: 12px;
            padding: 18px 18px 16px;
        }
        .pay-title {
            font-size: 16px;
            font-weight: 800;
            color: #1f6f32;
            margin-bottom: 6px;
        }
        .pay-sub { font-size: 12px; color: #546e57; margin-bottom: 12px; }
        .pay-amount { font-size: 22px; font-weight: 800; color: #1f6f32; margin-bottom: 12px; }
        .pay-methods, .collector-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 12px;
        }
        .pay-option input { position: absolute; opacity: 0; width: 0; height: 0; }
        .pay-option label {
            border: 2px solid #cfe2d1;
            border-radius: 10px;
            padding: 10px 12px;
            font-size: 13px;
            font-weight: 700;
            color: #204a2a;
            background: #fff;
            cursor: pointer;
            display: block;
        }
        .pay-option input:checked + label {
            border-color: #1f6f32;
            background: #eef6ef;
        }
        .pay-box {
            border: 1px solid #d8e7d9;
            border-radius: 10px;
            padding: 10px 12px;
            font-size: 12px;
            color: #2c4a32;
            margin-bottom: 12px;
        }
        .pay-box select {
            margin-top: 6px;
            width: 100%;
            border: 1px solid #bfd5c1;
            border-radius: 8px;
            padding: 8px 10px;
            font-size: 13px;
        }
        .pay-info { display: grid; grid-template-columns: 78px 1fr; gap: 12px; margin-bottom: 12px; align-items: start; }
        .pay-photo {
            width: 78px;
            height: 78px;
            border-radius: 10px;
            overflow: hidden;
            border: 1px solid #d8e7d9;
            background: #eef6ef;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            font-weight: 800;
            color: #3b5b43;
        }
        .pay-photo img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .pay-patient-name { font-size: 14px; font-weight: 800; color: #1f6f32; }
        .pay-patient-meta { font-size: 12px; color: #2c4a32; margin-top: 2px; }
        .pay-address { font-size: 12px; color: #4b6250; margin-top: 4px; }
        .pay-section { border-top: 1px dashed #d8e7d9; padding-top: 10px; margin-top: 10px; }
        .pay-kv { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
        .pay-kv div { font-size: 12px; color: #2c4a32; }
        .pay-kv strong { color: #1f6f32; }
        .pay-collector { font-size: 12px; font-weight: 700; color: #1f6f32; margin-top: 8px; }
        .payment-slip {
            display: none;
            max-width: 620px;
            margin: 30px auto 0;
            background: #fff;
            border: 1px solid #d8e7d9;
            border-radius: 12px;
            padding: 16px 18px;
        }
        .ps-head { display: flex; justify-content: space-between; align-items: center; gap: 12px; border-bottom: 1px solid #d8e7d9; padding-bottom: 8px; margin-bottom: 10px; }
        .ps-left { display: flex; align-items: center; gap: 10px; text-decoration: none; color: inherit; }
        .ps-logo { height: 38px; width: auto; display: block; }
        .ps-title { font-size: 16px; font-weight: 800; color: #1f6f32; }
        .ps-sub { font-size: 12px; color: #4b6250; }
        .ps-meta { font-size: 11px; color: #546e57; text-align: right; }
        .ps-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; font-size: 12px; color: #2c4a32; }
        .ps-block { margin-top: 8px; font-size: 12px; color: #2c4a32; }
        .ps-collector { margin-top: 10px; font-size: 12px; font-weight: 700; color: #1f6f32; }

        body.print-payment .payment-slip { display: block; }
        body.print-payment .slip { display: none; }
        body.print-payment .pay-card { display: none; }
        .pay-actions { display: flex; gap: 10px; }
        .pay-actions button {
            flex: 1;
            border: none;
            border-radius: 8px;
            padding: 10px 12px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
        }
        .pay-actions .btn-submit { background: #1f6f32; color: #fff; }
        .pay-actions .btn-cancel { background: #e0e0e0; color: #333; }
        .pay-error { background: #fdecea; color: #b71c1c; border: 1px solid #f5c6cb; padding: 8px 10px; border-radius: 8px; margin-bottom: 10px; font-size: 12px; }
        .pay-note { font-size: 11px; color: #6d7f70; margin-top: 8px; }

        .slip {
            max-width: 760px;
            margin: 0 auto;
            background: #fff;
            border: 1px solid #d8e7d9;
            padding: 14px 16px;
        }

        .head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            border-bottom: 1px solid #d8e7d9;
            padding-bottom: 8px;
            margin-bottom: 10px;
        }

        .head-left {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
            text-decoration: none;
            color: inherit;
        }

        .head-logo {
            height: 38px;
            width: auto;
            object-fit: contain;
        }

        .head-title {
            font-size: 14px;
            font-weight: 800;
            line-height: 1.2;
        }

        .head-sub {
            font-size: 11px;
            color: #4a5e4d;
            margin-top: 2px;
        }

        .head-meta {
            text-align: right;
            font-size: 11px;
            line-height: 1.4;
            white-space: nowrap;
        }

        .line {
            font-size: 12px;
            line-height: 1.4;
            margin-bottom: 6px;
        }

        .line strong {
            color: #1f6f32;
            font-size: 11px;
            letter-spacing: 0.2px;
            text-transform: uppercase;
            margin-right: 6px;
        }

        .section {
            margin-top: 8px;
            border-top: 1px dashed #d8e7d9;
            padding-top: 7px;
        }

        .section-title {
            font-size: 11px;
            color: #1f6f32;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            font-weight: 800;
            margin-bottom: 4px;
        }

        .section-body {
            font-size: 12px;
            line-height: 1.45;
            white-space: pre-wrap;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 6px;
            font-size: 11px;
        }

        th, td {
            border: 1px solid #dbe8dc;
            padding: 5px 6px;
            vertical-align: top;
        }

        th {
            background: #f2f8f2;
            color: #1f6f32;
            text-align: left;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .muted { color: #667c69; }

        .foot {
            margin-top: 14px;
            padding-top: 10px;
            border-top: 1px solid #d8e7d9;
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            gap: 12px;
        }

        .foot-note {
            font-size: 10px;
            color: #6d7f70;
            line-height: 1.4;
            max-width: 66%;
        }

        .sig {
            min-width: 220px;
            text-align: center;
        }

        .sig-line {
            border-top: 1px solid #2a3b2c;
            margin: 22px 0 5px;
        }

        .sig-name {
            font-size: 13px;
            font-weight: 800;
        }

        .sig-meta {
            font-size: 10px;
            color: #4a5e4d;
            margin-top: 2px;
            line-height: 1.35;
        }

        @media print {
            body { background: #fff; padding: 0; }
            .toolbar { display: none !important; }
            .slip {
                max-width: 100%;
                border: none;
                padding: 0;
            }
            body.print-payment .slip { display: none !important; }
            body.print-payment .pay-card { display: none !important; }
            body.print-payment .payment-slip { display: block !important; border: none !important; padding: 0 !important; margin: 0 !important; }
            body:not(.print-payment) .payment-slip { display: none !important; }
            body:not(.print-payment) .pay-card { display: none !important; }
            @page {
                margin: 0.35cm;
                size: A4;
            }
        }
    </style>
</head>
<body class="<?= $isPaymentSlipView ? 'print-payment' : '' ?>">
<div class="toolbar">
    <a href="<?= e($backUrl) ?>">Back</a>
    <?php if (!($source === 'teleconsult' && $paymentPending)): ?>
        <button class="print-btn" onclick="window.print()">Print</button>
    <?php endif; ?>
    <?php if ($paymentSlipUrl !== '' && !$isPaymentSlipView): ?>
        <a class="print-btn" href="<?= e($paymentSlipUrl) ?>" target="_blank">Payment Slip</a>
    <?php endif; ?>
</div>

<?php if ($source === 'teleconsult' && $paymentPending): ?>
    <?php if (!in_array($role, ['doctor', 'executive'], true)): ?>
        <div class="pay-card">
            <div class="pay-title">Payment Pending</div>
            <div class="pay-sub">Payment must be collected at the eHealth center before printing this slip.</div>
            <div class="pay-note">Please ask the center doctor/executive to collect payment and print the slip.</div>
        </div>
    <?php elseif ($teleSessionId <= 0): ?>
        <div class="pay-card">
            <div class="pay-title">Payment Pending</div>
            <div class="pay-sub">Teleconsult session is not linked to this diagnosis.</div>
            <div class="pay-note">Payment cannot be collected. Please contact admin.</div>
        </div>
    <?php else: ?>
        <form class="pay-card" method="POST">
            <input type="hidden" name="collect_payment" value="1">
            <div class="pay-title">Teleconsultation Payment</div>
            <div class="pay-sub">Collect payment to unlock printing.</div>
            <div class="pay-amount">Amount: <?= e('INR ' . number_format($paymentAmount, 2)) ?></div>

            <?php if ($paymentError !== ''): ?>
                <div class="pay-error"><?= e($paymentError) ?></div>
            <?php endif; ?>

            <div class="pay-info">
                <div class="pay-photo">
                    <?php if ($patientPhotoUrl !== ''): ?>
                        <img src="<?= e($patientPhotoUrl) ?>" alt="Patient Photo">
                    <?php else: ?>
                        <?= e($patientInitials !== '' ? $patientInitials : 'NA') ?>
                    <?php endif; ?>
                </div>
                <div>
                    <div class="pay-patient-name"><?= e($patient['full_name'] ?? '-') ?></div>
                    <div class="pay-patient-meta">Patient ID: <?= e($patient['patient_id'] ?? '-') ?></div>
                    <div class="pay-patient-meta">Phone: <?= e($patient['mobile_no'] ?? '-') ?></div>
                    <div class="pay-address">Address: <?= e($patientAddress !== '' ? $patientAddress : '-') ?></div>
                </div>
            </div>

            <div class="pay-section">
                <div class="pay-kv">
                    <div><strong>Teleconsult Date:</strong> <?= e($diagnosisDateHuman) ?></div>
                    <div><strong>Teleconsult Time:</strong> <?= e($diagnosisTimeHuman) ?></div>
                    <div><strong>Telestudio Doctor:</strong> <?= e($teleDoctorName !== '' ? $teleDoctorName : '-') ?><?= $teleDoctorId !== '' ? ' (' . e($teleDoctorId) . ')' : '' ?></div>
                    <div><strong>eHealth Doctor:</strong> <?= e($centerDoctorName !== '' ? $centerDoctorName : '-') ?><?= $centerDoctorId !== '' ? ' (' . e($centerDoctorId) . ')' : '' ?></div>
                </div>
            </div>

            <div class="pay-methods">
                <div class="pay-option">
                    <input type="radio" id="pmCash" name="payment_method" value="Cash" checked>
                    <label for="pmCash">Cash</label>
                </div>
                <div class="pay-option">
                    <input type="radio" id="pmUpi" name="payment_method" value="UPI">
                    <label for="pmUpi">UPI</label>
                </div>
            </div>

            <div class="collector-row">
                <?php if ($role === 'doctor'): ?>
                    <div class="pay-option">
                        <input type="radio" id="crDoctor" name="collector_role" value="doctor" checked>
                        <label for="crDoctor">Collected by Doctor</label>
                    </div>
                <?php else: ?>
                    <div class="pay-option">
                        <input type="radio" id="crExecutive" name="collector_role" value="executive" checked>
                        <label for="crExecutive">Collected by Executive</label>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($role === 'doctor'): ?>
                <div class="pay-box" id="collectorDoctorBox">
                    Doctor: <?= e(get_session_name() ?: 'Doctor') ?>
                </div>
            <?php else: ?>
                <div class="pay-box" id="collectorExecutiveBox">
                    Executive: <?= e(get_session_name() ?: 'Executive') ?>
                </div>
            <?php endif; ?>

            <div class="pay-actions">
                <button class="btn-submit" type="submit">Collect &amp; Unlock</button>
                <button class="btn-cancel" type="button" onclick="window.location.href='<?= e($backUrl) ?>'">Cancel</button>
            </div>
            <?php
                $currentDoctorLabel = trim((string)get_session_name()) ?: 'Doctor';
                $currentExecutiveLabel = '';
                if ($role === 'executive') {
                    $currentExecutiveLabel = trim((string)get_session_name()) ?: 'Executive';
                }
                $initialCollectorLabel = $role === 'executive' ? $currentExecutiveLabel : $currentDoctorLabel;
            ?>
            <div class="pay-collector" id="collectorSummary"
                 data-doctor="<?= e($currentDoctorLabel) ?>"
                 data-executive="<?= e($currentExecutiveLabel) ?>">
                Collected by: <?= e($initialCollectorLabel !== '' ? $initialCollectorLabel : 'Doctor') ?>
            </div>
            <div class="pay-note">Slip will open after payment is saved.</div>
        </form>
    <?php endif; ?>
<?php else: ?>
<div class="slip">
    <div class="head">
        <a href="<?= htmlspecialchars(app_base_url()) ?>/" class="head-left">
            <img src="<?= e($logoUrl) ?>" alt="SRMS Trust Logo" class="head-logo">
            <div>
                <div class="head-title">SRMS EHEALTH CENTER</div>
                <div class="head-sub"><?= e($slipTitle) ?></div>
            </div>
        </a>
        <div class="head-meta">
            <div><strong>Date:</strong> <?= e($diagnosisDateHuman) ?></div>
            <div><strong>Time:</strong> <?= e($diagnosisTimeHuman) ?></div>
            <div><strong>Slip:</strong> <?= e(strtoupper($source) . '-' . $diagnosisId) ?></div>
        </div>
    </div>

    <div class="line"><strong>Patient</strong><?= e($patientInlineText) ?></div>
    <?php if (!empty($patient['address_full'])): ?>
        <div class="line"><strong>Address</strong><?= e($patient['address_full']) ?></div>
    <?php endif; ?>
    <?php if (!empty($vitalsInline)): ?>
        <div class="line"><strong>Vitals</strong><?= e(implode(' | ', $vitalsInline)) ?></div>
    <?php endif; ?>

    <?php if ($chiefComplaint !== ''): ?>
        <div class="section">
            <div class="section-title">Chief Complaint</div>
            <div class="section-body"><?= e($chiefComplaint) ?></div>
        </div>
    <?php endif; ?>

    <?php if ($primaryDiagnosis !== ''): ?>
        <div class="section">
            <div class="section-title">Diagnosis</div>
            <div class="section-body"><?= e($primaryDiagnosis) ?></div>
        </div>
    <?php endif; ?>

    <?php if ($clinicalNotes !== ''): ?>
        <div class="section">
            <div class="section-title">Clinical Notes</div>
            <div class="section-body"><?= e($clinicalNotes) ?></div>
        </div>
    <?php endif; ?>

    <?php if ($investigation !== ''): ?>
        <div class="section">
            <div class="section-title">Investigation</div>
            <div class="section-body"><?= e($investigation) ?></div>
        </div>
    <?php endif; ?>

    <?php if (!empty($medicines)): ?>
        <div class="section">
            <div class="section-title">Medicines</div>
            <table>
                <thead>
                    <tr>
                        <th style="width:5%">#</th>
                        <th style="width:30%">Medicine</th>
                        <th style="width:14%">Dose</th>
                        <th style="width:21%">Timing</th>
                        <th style="width:15%">Meal</th>
                        <th style="width:15%">Duration</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($medicines as $idx => $med): ?>
                        <tr>
                            <td><?= $idx + 1 ?></td>
                            <td><?= e($med['medicine_name'] ?? '') ?></td>
                            <td><?= e($med['dosage'] ?? '-') ?></td>
                            <td><?= e(!empty($med['timing_list']) ? implode(', ', $med['timing_list']) : '-') ?></td>
                            <td><?= e($med['meal_instruction'] ?? '-') ?></td>
                            <td><?= e($med['duration'] ?? '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if ($reviewAfter !== '' || $remarks !== ''): ?>
        <div class="section">
            <div class="section-title">Follow-up</div>
            <div class="section-body">
                <?php if ($reviewAfter !== ''): ?>
                    <div><strong class="muted">Review:</strong> <?= e($reviewAfter) ?></div>
                <?php endif; ?>
                <?php if ($remarks !== ''): ?>
                    <div style="margin-top:3px;"><strong class="muted">Remarks:</strong> <?= e($remarks) ?></div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="foot">
        <div class="foot-note">
            Computer generated diagnosis slip. Please follow prescribed instructions.
        </div>
        <div class="sig">
            <div class="sig-line"></div>
            <div class="sig-name"><?= e($doctorBottom) ?></div>
            <?php if ($doctorBottomMeta !== ''): ?>
                <div class="sig-meta"><?= e($doctorBottomMeta) ?></div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php if ($paymentDone): ?>
    <div class="payment-slip">
        <div class="ps-head">
            <a href="<?= htmlspecialchars(app_base_url()) ?>/" class="ps-left">
                <img src="<?= e($logoUrl) ?>" alt="SRMS Trust Logo" class="ps-logo">
                <div>
                    <div class="ps-title">Teleconsultation Payment Receipt</div>
                    <div class="ps-sub">SRMS eHealth Center</div>
                </div>
            </a>
            <div class="ps-meta">
                <div><strong>Teleconsult:</strong> <?= e($diagnosisDateHuman) ?> <?= e($diagnosisTimeHuman) ?></div>
                <div><strong>Printed:</strong> <?= e(date('d M Y h:i A')) ?></div>
            </div>
        </div>

        <div class="pay-info">
            <div class="pay-photo">
                <?php if ($patientPhotoUrl !== ''): ?>
                    <img src="<?= e($patientPhotoUrl) ?>" alt="Patient Photo">
                <?php else: ?>
                    <?= e($patientInitials !== '' ? $patientInitials : 'NA') ?>
                <?php endif; ?>
            </div>
            <div>
                <div class="pay-patient-name"><?= e($patient['full_name'] ?? '-') ?></div>
                <div class="pay-patient-meta">Patient ID: <?= e($patient['patient_id'] ?? '-') ?></div>
                <div class="pay-patient-meta">Phone: <?= e($patient['mobile_no'] ?? '-') ?></div>
                <div class="pay-address">Address: <?= e($patientAddress !== '' ? $patientAddress : '-') ?></div>
            </div>
        </div>

        <div class="ps-grid">
            <div><strong>Payment Method:</strong> <?= e($paymentMethod !== '' ? $paymentMethod : '-') ?></div>
            <div><strong>Amount:</strong> <?= e('INR ' . number_format($paymentAmount, 2)) ?></div>
            <div><strong>Telestudio Doctor:</strong> <?= e($teleDoctorName !== '' ? $teleDoctorName : '-') ?><?= $teleDoctorId !== '' ? ' (' . e($teleDoctorId) . ')' : '' ?></div>
            <div><strong>eHealth Doctor:</strong> <?= e($centerDoctorName !== '' ? $centerDoctorName : '-') ?><?= $centerDoctorId !== '' ? ' (' . e($centerDoctorId) . ')' : '' ?></div>
        </div>

        <div class="ps-block">
            <strong>Session ID:</strong> <?= e((string)$teleSessionId) ?>
        </div>

        <?php if ($paymentCollectorLabel !== ''): ?>
            <div class="ps-collector">Collected by: <?= e($paymentCollectorLabel) ?></div>
        <?php else: ?>
            <div class="ps-collector">Collected by: -</div>
        <?php endif; ?>
    </div>
<?php endif; ?>
<?php endif; ?>
<?php if ($source === 'teleconsult' && $paymentPending && $teleSessionId > 0 && in_array($role, ['doctor', 'executive'], true)): ?>
<script>
    const summary = document.getElementById('collectorSummary');
    if (summary) {
        const doctorLabel = summary.dataset.doctor || 'Doctor';
        const executiveLabel = summary.dataset.executive || 'Executive';
        summary.textContent = 'Collected by: ' + (<?= json_encode($role) ?> === 'executive' ? executiveLabel : doctorLabel);
    }
</script>
<?php endif; ?>
<?php if (!($source === 'teleconsult' && $paymentPending) && isset($_GET['autoprint']) && $_GET['autoprint'] === '1'): ?>
<script>
    window.addEventListener('load', () => { window.print(); });
</script>
<?php endif; ?>
</body>
</html>
