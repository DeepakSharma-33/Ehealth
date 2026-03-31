<?php
require_once __DIR__ . '/../../config/config.php';
require_role('pharmacy_staff', 'pharmacy_login.php');

$db = db();
ensure_pharmacy_dispense_table($db);
ensure_pharmacy_dispense_extra_columns($db);

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

function ensure_pharmacy_dispense_extra_columns(mysqli $db): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $db->query("ALTER TABLE pharmacy_dispense ADD COLUMN IF NOT EXISTS payment_method ENUM('Cash','UPI') NULL AFTER total_amount");
    $db->query("ALTER TABLE pharmacy_dispense ADD COLUMN IF NOT EXISTS paid_at DATETIME NULL AFTER payment_method");
}

function normalize_med_key(string $name, string $dosage, string $duration): string {
    return strtolower(trim($name)) . '|' . strtolower(trim($dosage)) . '|' . strtolower(trim($duration));
}

function normalize_payment_method(mixed $value, string $default = ''): string {
    $method = trim((string)$value);
    if (in_array($method, ['Cash', 'UPI'], true)) {
        return $method;
    }
    return $default;
}

$diagnosisId = (int)($_GET['diagnosisId'] ?? 0);
if ($diagnosisId <= 0) {
    http_response_code(400);
    echo 'Invalid diagnosis ID.';
    exit;
}

$requestedType = strtolower(trim((string)($_GET['type'] ?? 'ehealth')));
$source = in_array($requestedType, ['tele', 'teleconsult', 'teleconsultation'], true)
    ? 'teleconsult'
    : 'ehealth';

$diagnosisTable = '';
$diag = null;

if ($source === 'teleconsult') {
    $teleTables = existing_tables($db, ['telestudio_diagnosis', 'teleconsult_diagnosis']);
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
        if (!$stmt) continue;
        $stmt->bind_param('i', $diagnosisId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            $diag = $row;
            $diagnosisTable = $tbl;
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
    $diagnosisTable = 'diagnosis_records';
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

$patientPhotoUrl = patient_photo_url($patient['photo_filename'] ?? null);
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
$doctorName = 'Doctor';
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

$teleSession = null;
$teleSessionId = 0;
$paymentDone = false;
$paymentMethod = '';
$paymentAmount = 0.00;
$paymentCollectorLabel = '';
$centerDoctorId = '';
$centerDoctorName = '';
$teleDoctorId = $doctorId;
$teleDoctorName = $doctorName;

if ($source === 'teleconsult') {
    ensure_teleconsult_payment_columns($db);
    $teleSessionId = (int)($diag['session_id'] ?? 0);

    if ($teleSessionId > 0) {
        $s = $db->prepare(
            'SELECT ts.*, ed.full_name AS ehealth_doctor_full_name, td.full_name AS telestudio_doctor_full_name
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

    $paymentMethod = trim((string)($teleSession['payment_method'] ?? ''));
    $paymentDone = $paymentMethod !== '';
    $paymentAmount = $teleSession ? (float)($teleSession['payment_amount'] ?? 50.00) : 50.00;

    if ($paymentDone) {
        $collectorRole = trim((string)($teleSession['payment_collected_by_role'] ?? ''));
        $collectorId = trim((string)($teleSession['payment_collected_by_id'] ?? ''));
        $collectorName = trim((string)($teleSession['payment_collected_by_name'] ?? ''));
        if ($collectorName !== '' || $collectorId !== '') {
            $roleLabel = $collectorRole === 'executive' ? 'Executive' : 'Doctor';
            $paymentCollectorLabel = $roleLabel . ': ' . $collectorName . ($collectorId !== '' ? ' (' . $collectorId . ')' : '');
        }
    }

    if (!empty($teleSession)) {
        $centerDoctorId = trim((string)($teleSession['ehealth_doctor_id'] ?? ''));
        $centerDoctorName = trim((string)($teleSession['ehealth_doctor_full_name'] ?? ($teleSession['ehealth_doctor_name'] ?? '')));
        $teleDoctorId = trim((string)($teleSession['telestudio_doctor_id'] ?? '')) ?: $doctorId;
        $teleDoctorName = trim((string)($teleSession['telestudio_doctor_full_name'] ?? '')) ?: $doctorName;
    } else {
        $centerDoctorId = trim((string)($diag['ehealth_doctor_id'] ?? ''));
        $centerDoctorName = trim((string)($diag['ehealth_doctor_name'] ?? ''));
    }
}

$dispenseStmt = $db->prepare('SELECT * FROM pharmacy_dispense WHERE diagnosis_type = ? AND diagnosis_id = ? LIMIT 1');
$dispenseStmt->bind_param('si', $source, $diagnosisId);
$dispenseStmt->execute();
$dispenseRow = $dispenseStmt->get_result()->fetch_assoc();
$dispenseStmt->close();

$dispenseSaved = !empty($dispenseRow);
$paymentMethodSaved = normalize_payment_method($dispenseRow['payment_method'] ?? '', '');
$pharmacyPaymentMethod = $paymentMethodSaved !== '' ? $paymentMethodSaved : 'Cash';
$editMode = isset($_GET['edit']) && $_GET['edit'] === '1';
$isReadOnly = $dispenseSaved && !$editMode;
$dispenseSavedAt = trim((string)($dispenseRow['paid_at'] ?? ($dispenseRow['updated_at'] ?? ($dispenseRow['created_at'] ?? ''))));
$dispenseSavedAtHuman = '';
if ($dispenseSavedAt !== '') {
    $savedTs = strtotime($dispenseSavedAt);
    if ($savedTs) {
        $dispenseSavedAtHuman = date('d M Y h:i A', $savedTs);
    }
}

$savedMeds = [];
if ($dispenseRow && !empty($dispenseRow['medicines_json'])) {
    $decoded = json_decode((string)$dispenseRow['medicines_json'], true);
    if (is_array($decoded)) $savedMeds = $decoded;
}

$savedByKey = [];
$savedByIndex = [];
foreach ($savedMeds as $idx => $m) {
    if (!is_array($m)) continue;
    $name = trim((string)($m['name'] ?? $m['medicine_name'] ?? $m['medicine'] ?? ''));
    $dosage = trim((string)($m['dosage'] ?? ''));
    $duration = trim((string)($m['duration'] ?? ''));
    $key = normalize_med_key($name, $dosage, $duration);
    if ($key !== '||') $savedByKey[$key] = $m;
    $savedByIndex[$idx] = $m;
}

foreach ($medicines as $idx => $med) {
    $key = normalize_med_key((string)($med['medicine_name'] ?? ''), (string)($med['dosage'] ?? ''), (string)($med['duration'] ?? ''));
    $saved = $savedByKey[$key] ?? ($savedByIndex[$idx] ?? null);
    $price = $saved ? (float)($saved['price'] ?? 0) : 0.00;
    $availability = $saved ? (string)($saved['availability'] ?? 'Available') : 'Available';
    if (!in_array($availability, ['Available', 'NA'], true)) {
        $availability = 'Available';
    }
    $medicines[$idx]['price'] = $price;
    $medicines[$idx]['availability'] = $availability;
}

$teleCharge = $source === 'teleconsult' ? $paymentAmount : 0.00;
$teleChargeApplied = ($source === 'teleconsult' && !$paymentDone) ? $paymentAmount : 0.00;
$medTotal = 0.00;
foreach ($medicines as $med) {
    $medTotal += (float)($med['price'] ?? 0);
}
$grandTotal = $medTotal + $teleChargeApplied;

$saveMessage = '';
$saveError = '';
if (isset($_GET['saved']) && $_GET['saved'] === '1' && $dispenseSaved) {
    $saveMessage = 'Diagnosis provided saved successfully.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $contentType = (string)($_SERVER['CONTENT_TYPE'] ?? '');
    $payload = [];
    if (stripos($contentType, 'application/json') !== false) {
        $payload = json_decode(file_get_contents('php://input'), true) ?? [];
    } else {
        $payload = $_POST;
    }

    if (($payload['action'] ?? '') === 'save_dispense') {
        $medInput = $payload['medicines'] ?? $payload['medicines_json'] ?? [];
        if (is_string($medInput)) {
            $medInput = json_decode($medInput, true) ?? [];
        }
        $selectedPaymentMethod = normalize_payment_method(
            $payload['payment_method'] ?? $payload['pharmacy_payment_method'] ?? '',
            $pharmacyPaymentMethod
        );

        $cleanMeds = [];
        $medTotal = 0.00;

        if (is_array($medInput)) {
            foreach ($medInput as $m) {
                if (!is_array($m)) continue;
                $name = trim((string)($m['name'] ?? $m['medicine_name'] ?? ''));
                if ($name === '') continue;
                $dosage = trim((string)($m['dosage'] ?? ''));
                $duration = trim((string)($m['duration'] ?? ''));
                $timing = $m['timing'] ?? $m['timing_list'] ?? [];
                if (!is_array($timing)) $timing = [$timing];
                $meal = trim((string)($m['meal'] ?? $m['meal_instruction'] ?? ''));
                $price = is_numeric($m['price'] ?? null) ? (float)$m['price'] : 0.00;
                if ($price < 0) $price = 0.00;
                $availability = strtoupper(trim((string)($m['availability'] ?? 'AVAILABLE')));
                $availability = $availability === 'NA' ? 'NA' : 'Available';

                $cleanMeds[] = [
                    'name' => $name,
                    'dosage' => $dosage,
                    'timing' => $timing,
                    'meal' => $meal,
                    'duration' => $duration,
                    'price' => round($price, 2),
                    'availability' => $availability,
                ];
                $medTotal += $price;
            }
        }

        $teleCharge = $source === 'teleconsult' ? $paymentAmount : 0.00;
        $telePaid = ($source === 'teleconsult' && $paymentDone) ? 1 : 0;
        $teleChargeApplied = ($source === 'teleconsult' && !$paymentDone) ? $paymentAmount : 0.00;
        $grandTotal = $medTotal + $teleChargeApplied;

        $pharmStaffId = (int)(get_session_id() ?? 0);
        $pharmStaffName = get_session_name() ?: 'Pharmacy Staff';

        $ehealthDocId = $source === 'teleconsult'
            ? ($centerDoctorId !== '' ? $centerDoctorId : (string)($diag['ehealth_doctor_id'] ?? ''))
            : $doctorId;

        $telestudioDocId = $source === 'teleconsult'
            ? ($teleDoctorId !== '' ? $teleDoctorId : (string)($diag['telestudio_doctor_id'] ?? ''))
            : null;

        $medJson = json_encode($cleanMeds);

        $stmt = $db->prepare(
            'INSERT INTO pharmacy_dispense
             (diagnosis_type, diagnosis_id, patient_id, ehealth_doctor_id, telestudio_doctor_id,
              pharmacy_staff_id, pharmacy_staff_name, telemedicine_charge, telemedicine_paid, total_amount, payment_method, paid_at, medicines_json)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW(),?)
             ON DUPLICATE KEY UPDATE
                patient_id = VALUES(patient_id),
                ehealth_doctor_id = VALUES(ehealth_doctor_id),
                telestudio_doctor_id = VALUES(telestudio_doctor_id),
                pharmacy_staff_id = VALUES(pharmacy_staff_id),
                pharmacy_staff_name = VALUES(pharmacy_staff_name),
                telemedicine_charge = VALUES(telemedicine_charge),
                telemedicine_paid = VALUES(telemedicine_paid),
                total_amount = VALUES(total_amount),
                payment_method = VALUES(payment_method),
                paid_at = NOW(),
                medicines_json = VALUES(medicines_json),
                updated_at = NOW()'
        );

        $stmt->bind_param(
            'sisssisdidss',
            $source,
            $diagnosisId,
            $patientId,
            $ehealthDocId,
            $telestudioDocId,
            $pharmStaffId,
            $pharmStaffName,
            $teleCharge,
            $telePaid,
            $grandTotal,
            $selectedPaymentMethod,
            $medJson
        );

        $ok = $stmt->execute();
        $err = $stmt->error;
        $stmt->close();

        if ($ok) {
            $redirectQuery = [
                'diagnosisId' => $diagnosisId,
                'type' => $source,
                'saved' => '1',
            ];
            if ($source === 'teleconsult' && $diagnosisTable !== '') {
                $redirectQuery['srcTable'] = $diagnosisTable;
            }
            $redirectUrl = app_base_url() . '/public/pharmacy/pharmacy_slip.php?' . http_build_query($redirectQuery);
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
                json_success(['total' => $grandTotal, 'redirect' => $redirectUrl], 'Saved');
            }
            header('Location: ' . $redirectUrl);
            exit;
        } else {
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
                json_error('Save failed: ' . $err, 500);
            }
            $saveError = 'Save failed: ' . $err;
        }
    }
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
$backUrl = $base . '/public/pharmacy/provide_medicine.php';
$slipQuery = [
    'diagnosisId' => $diagnosisId,
    'type' => $source,
];
if ($source === 'teleconsult' && $diagnosisTable !== '') {
    $slipQuery['srcTable'] = $diagnosisTable;
}
$viewUrl = $base . '/public/pharmacy/pharmacy_slip.php?' . http_build_query($slipQuery);
$editUrl = $viewUrl . '&edit=1';

$teleNote = '';
if ($source === 'teleconsult') {
    if ($paymentDone) {
        $teleNote = 'Telemedicine charges already paid';
        if ($paymentMethod !== '') $teleNote .= ' (' . $paymentMethod . ')';
        if ($paymentCollectorLabel !== '') $teleNote .= ' - ' . $paymentCollectorLabel;
    } elseif ($dispenseSaved && $paymentMethodSaved !== '') {
        $teleNote = 'Telemedicine charges included in this pharmacy slip (' . $paymentMethodSaved . ').';
    } else {
        $teleNote = 'Telemedicine charges will be included on this pharmacy slip until paid at the center.';
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pharmacy Slip - <?= e($patient['patient_id']) ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: Arial, sans-serif;
            background: #eef6ef;
            padding: 16px;
            color: #122013;
        }
        .toolbar {
            max-width: 860px;
            margin: 0 auto 10px;
            display: flex;
            gap: 8px;
            align-items: center;
            justify-content: space-between;
        }
        .toolbar a {
            text-decoration: none;
            color: #1f6f32;
            font-size: 13px;
            font-weight: 700;
        }
        .toolbar-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }
        .toolbar .btn {
            border: none;
            background: #1f6f32;
            color: white;
            padding: 8px 12px;
            border-radius: 7px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .toolbar .btn-light {
            background: #dce8de;
            color: #1f6f32;
        }
        .notice {
            max-width: 860px;
            margin: 0 auto 10px;
            padding: 10px 12px;
            border-radius: 8px;
            font-size: 12px;
        }
        .notice.success { background: #e8f5e9; color: #1b5e20; border: 1px solid #c8e6c9; }
        .notice.error { background: #fdecea; color: #b71c1c; border: 1px solid #f5c6cb; }
        .status-banner {
            max-width: 860px;
            margin: 0 auto 10px;
            padding: 12px 14px;
            border-radius: 10px;
            background: #edf7ef;
            border: 1px solid #c8e6c9;
            color: #1b5e20;
        }
        .status-title {
            font-size: 14px;
            font-weight: 800;
            margin-bottom: 4px;
        }
        .status-text {
            font-size: 12px;
            line-height: 1.45;
        }

        .slip {
            max-width: 860px;
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
        .head-logo { height: 38px; width: auto; object-fit: contain; }
        .head-title { font-size: 14px; font-weight: 800; line-height: 1.2; }
        .head-sub { font-size: 11px; color: #4a5e4d; margin-top: 2px; }
        .head-meta { text-align: right; font-size: 11px; line-height: 1.4; white-space: nowrap; }

        .line { font-size: 12px; line-height: 1.4; margin-bottom: 6px; }
        .line strong { color: #1f6f32; font-size: 11px; letter-spacing: 0.2px; text-transform: uppercase; margin-right: 6px; }
        .section { margin-top: 8px; border-top: 1px dashed #d8e7d9; padding-top: 7px; }
        .section-title { font-size: 11px; color: #1f6f32; text-transform: uppercase; letter-spacing: 0.8px; font-weight: 800; margin-bottom: 4px; }
        .section-body { font-size: 12px; line-height: 1.45; white-space: pre-wrap; }

        table { width: 100%; border-collapse: collapse; margin-top: 6px; font-size: 11px; }
        th, td { border: 1px solid #dbe8dc; padding: 5px 6px; vertical-align: top; }
        th { background: #f2f8f2; color: #1f6f32; text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; }
        .price-input { width: 70px; padding: 4px 6px; font-size: 11px; }
        .avail-select { width: 90px; padding: 4px 6px; font-size: 11px; }
        .print-value { display: none; font-size: 11px; }
        .payment-methods {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 8px;
        }
        .payment-option {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 10px;
            border: 1px solid #d8e7d9;
            border-radius: 8px;
            font-size: 12px;
            color: #27412c;
        }
        .readonly-note {
            font-size: 11px;
            color: #5c6f5f;
            margin-top: 6px;
        }

        .totals {
            margin-top: 10px;
            border-top: 1px solid #d8e7d9;
            padding-top: 8px;
            font-size: 12px;
        }
        .totals-row { display: flex; justify-content: space-between; margin-bottom: 4px; }
        .totals-row strong { color: #1f6f32; }
        .totals-note { font-size: 11px; color: #5c6f5f; margin-top: 6px; }

        .foot {
            margin-top: 14px;
            padding-top: 10px;
            border-top: 1px solid #d8e7d9;
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            gap: 12px;
        }
        .foot-note { font-size: 10px; color: #6d7f70; line-height: 1.4; max-width: 66%; }
        .sig { min-width: 220px; text-align: center; }
        .sig-line { border-top: 1px solid #2a3b2c; margin: 22px 0 5px; }
        .sig-name { font-size: 13px; font-weight: 800; }
        .sig-meta { font-size: 10px; color: #4a5e4d; margin-top: 2px; line-height: 1.35; }
        body.readonly .price-input,
        body.readonly .avail-select,
        body.readonly .edit-only {
            display: none !important;
        }
        body.readonly .print-value {
            display: inline;
        }

        @media print {
            body { background: #fff; padding: 0; }
            .toolbar, .notice, .status-banner { display: none !important; }
            .slip { max-width: 100%; border: none; padding: 0; }
            .price-input, .avail-select, .edit-only { display: none !important; }
            .print-value { display: inline; }
            @page { margin: 0.35cm; size: A4; }
        }
    </style>
</head>
<body class="<?= $isReadOnly ? 'readonly' : 'editing' ?>">
<div class="toolbar">
    <a href="<?= e($backUrl) ?>">Back</a>
    <div class="toolbar-actions">
        <?php if ($isReadOnly): ?>
            <a class="btn" href="<?= e($editUrl) ?>">Provide Diagnosis Again</a>
        <?php else: ?>
            <button class="btn" type="button" id="saveBtn"><?= $dispenseSaved ? 'Save Changes' : 'Save Diagnosis' ?></button>
            <?php if ($dispenseSaved): ?>
                <a class="btn btn-light" href="<?= e($viewUrl) ?>">Cancel Edit</a>
            <?php endif; ?>
        <?php endif; ?>
        <button class="btn" type="button" onclick="window.print()">Print</button>
    </div>
</div>

<?php if ($saveMessage !== ''): ?>
    <div class="notice success"><?= e($saveMessage) ?></div>
<?php elseif ($saveError !== ''): ?>
    <div class="notice error"><?= e($saveError) ?></div>
<?php endif; ?>
<?php if ($dispenseSaved && !$editMode): ?>
    <div class="status-banner">
        <div class="status-title">Diagnosis Provided</div>
        <div class="status-text">
            Saved by <?= e($dispenseRow['pharmacy_staff_name'] ?? 'Pharmacy Staff') ?>
            <?php if ($dispenseSavedAtHuman !== ''): ?>
                on <?= e($dispenseSavedAtHuman) ?>
            <?php endif; ?>
            using <?= e($pharmacyPaymentMethod) ?>.
        </div>
    </div>
<?php elseif ($dispenseSaved && $editMode): ?>
    <div class="notice success">Provide diagnosis again mode is active. Update the slip and save again.</div>
<?php endif; ?>

<div class="slip" id="slipRoot">
    <div class="head">
        <a href="<?= e($base) ?>/" class="head-left">
            <img src="<?= e($logoUrl) ?>" alt="SRMS Trust Logo" class="head-logo">
            <div>
                <div class="head-title">SRMS EHEALTH CENTER</div>
                <div class="head-sub"><?= $source === 'teleconsult' ? 'Teleconsult Diagnosis Slip' : 'Diagnosis Slip' ?></div>
            </div>
        </a>
        <div class="head-meta">
            <div><strong>Date:</strong> <?= e($diagnosisDateHuman) ?></div>
            <div><strong>Time:</strong> <?= e($diagnosisTimeHuman) ?></div>
            <div><strong>Slip:</strong> <?= e(strtoupper($source) . '-' . $diagnosisId) ?></div>
        </div>
    </div>

    <div class="line"><strong>Patient</strong><?= e($patientInlineText) ?></div>
    <?php if (!empty($patientAddress)): ?>
        <div class="line"><strong>Address</strong><?= e($patientAddress) ?></div>
    <?php endif; ?>
    <?php if (!empty($vitalsInline)): ?>
        <div class="line"><strong>Vitals</strong><?= e(implode(' | ', $vitalsInline)) ?></div>
    <?php endif; ?>
    <?php if ($source === 'teleconsult'): ?>
        <div class="line"><strong>eHealth Doctor</strong><?= e($centerDoctorName ?: '-') ?><?= $centerDoctorId ? ' (' . e($centerDoctorId) . ')' : '' ?></div>
        <div class="line"><strong>TeleStudio Doctor</strong><?= e($teleDoctorName ?: '-') ?><?= $teleDoctorId ? ' (' . e($teleDoctorId) . ')' : '' ?></div>
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
                        <th style="width:4%">#</th>
                        <th style="width:24%">Medicine</th>
                        <th style="width:12%">Dose</th>
                        <th style="width:18%">Timing</th>
                        <th style="width:12%">Meal</th>
                        <th style="width:12%">Duration</th>
                        <th style="width:9%">Price</th>
                        <th style="width:9%">Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($medicines as $idx => $med): ?>
                    <tr class="med-row"
                        data-name="<?= e($med['medicine_name'] ?? '') ?>"
                        data-dosage="<?= e($med['dosage'] ?? '') ?>"
                        data-duration="<?= e($med['duration'] ?? '') ?>"
                        data-meal="<?= e($med['meal_instruction'] ?? '') ?>"
                        data-timing="<?= e(!empty($med['timing_list']) ? implode(', ', $med['timing_list']) : '') ?>">
                        <td><?= $idx + 1 ?></td>
                        <td><?= e($med['medicine_name'] ?? '') ?></td>
                        <td><?= e($med['dosage'] ?? '-') ?></td>
                        <td><?= e(!empty($med['timing_list']) ? implode(', ', $med['timing_list']) : '-') ?></td>
                        <td><?= e($med['meal_instruction'] ?? '-') ?></td>
                        <td><?= e($med['duration'] ?? '-') ?></td>
                        <td>
                            <input type="number" class="price-input" min="0" step="0.01" value="<?= e(number_format((float)($med['price'] ?? 0), 2, '.', '')) ?>">
                            <span class="print-value price-print"></span>
                        </td>
                        <td>
                            <select class="avail-select">
                                <option value="Available" <?= ($med['availability'] ?? 'Available') === 'Available' ? 'selected' : '' ?>>Available</option>
                                <option value="NA" <?= ($med['availability'] ?? '') === 'NA' ? 'selected' : '' ?>>NA</option>
                            </select>
                            <span class="print-value avail-print"></span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <div class="section">
        <div class="section-title">Payment Method</div>
        <div class="line"><strong>Mode</strong><span id="paymentMethodLabel"><?= e($pharmacyPaymentMethod) ?></span></div>
        <div class="payment-methods edit-only">
            <label class="payment-option">
                <input type="radio" name="payment_method" value="Cash" <?= $pharmacyPaymentMethod === 'Cash' ? 'checked' : '' ?>>
                <span>Cash</span>
            </label>
            <label class="payment-option">
                <input type="radio" name="payment_method" value="UPI" <?= $pharmacyPaymentMethod === 'UPI' ? 'checked' : '' ?>>
                <span>UPI</span>
            </label>
        </div>
        <?php if ($dispenseSavedAtHuman !== ''): ?>
            <div class="readonly-note">Last saved: <?= e($dispenseSavedAtHuman) ?></div>
        <?php endif; ?>
    </div>

    <div class="totals" id="totalsBox"
         data-telecharge="<?= e(number_format($teleCharge, 2, '.', '')) ?>"
         data-telepaid="<?= $paymentDone ? '1' : '0' ?>">
        <div class="totals-row"><span>Medicines Total</span><strong id="medTotal">INR <?= e(number_format($medTotal, 2)) ?></strong></div>
        <?php if ($source === 'teleconsult'): ?>
            <div class="totals-row">
                <span>Telemedicine Charges<?= $paymentDone ? ' (Paid)' : '' ?></span>
                <strong id="teleCharge">INR <?= e(number_format($teleCharge, 2)) ?></strong>
            </div>
            <?php if ($teleNote !== ''): ?>
                <div class="totals-note"><?= e($teleNote) ?></div>
            <?php endif; ?>
        <?php endif; ?>
        <div class="totals-row"><span>Grand Total</span><strong id="grandTotal">INR <?= e(number_format($grandTotal, 2)) ?></strong></div>
    </div>

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

<script>
const saveBtn = document.getElementById('saveBtn');
const paymentMethodInputs = document.querySelectorAll('input[name="payment_method"]');
const paymentMethodLabel = document.getElementById('paymentMethodLabel');

function toNumber(val) {
    const n = parseFloat(val);
    return Number.isFinite(n) ? n : 0;
}

function formatMoney(n) {
    return 'INR ' + n.toFixed(2);
}

function getPaymentMethod() {
    const checked = document.querySelector('input[name="payment_method"]:checked');
    return checked ? checked.value : 'Cash';
}

function updatePaymentMethodLabel() {
    if (paymentMethodLabel) {
        paymentMethodLabel.textContent = getPaymentMethod();
    }
}

function updatePrintValues(row) {
    const priceInput = row.querySelector('.price-input');
    const availSelect = row.querySelector('.avail-select');
    const pricePrint = row.querySelector('.price-print');
    const availPrint = row.querySelector('.avail-print');
    if (pricePrint) pricePrint.textContent = formatMoney(toNumber(priceInput.value));
    if (availPrint) availPrint.textContent = availSelect.value;
}

function collectMedicines() {
    const meds = [];
    document.querySelectorAll('.med-row').forEach(row => {
        const name = row.dataset.name || '';
        if (!name) return;
        const dosage = row.dataset.dosage || '';
        const duration = row.dataset.duration || '';
        const meal = row.dataset.meal || '';
        const timing = (row.dataset.timing || '').split(',').map(x => x.trim()).filter(Boolean);
        const price = toNumber(row.querySelector('.price-input').value);
        const availability = row.querySelector('.avail-select').value || 'Available';
        meds.push({ name, dosage, duration, meal, timing, price, availability });
    });
    return meds;
}

function updateTotals() {
    let medTotal = 0;
    document.querySelectorAll('.price-input').forEach(inp => {
        medTotal += toNumber(inp.value);
    });
    const totalsBox = document.getElementById('totalsBox');
    const teleCharge = toNumber(totalsBox.dataset.telecharge);
    const telePaid = totalsBox.dataset.telepaid === '1';
    const teleApplied = telePaid ? 0 : teleCharge;
    const grandTotal = medTotal + teleApplied;
    const medTotalEl = document.getElementById('medTotal');
    const grandTotalEl = document.getElementById('grandTotal');
    if (medTotalEl) medTotalEl.textContent = formatMoney(medTotal);
    if (grandTotalEl) grandTotalEl.textContent = formatMoney(grandTotal);
    return { medTotal, grandTotal };
}

async function saveDispense() {
    if (!saveBtn) return;
    saveBtn.disabled = true;
    saveBtn.textContent = 'Saving...';
    const payload = {
        action: 'save_dispense',
        medicines: collectMedicines(),
        payment_method: getPaymentMethod()
    };
    try {
        const res = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(payload)
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Save failed');
        localStorage.setItem('pharmacy_dispense_updated', JSON.stringify({
            diagnosisId: <?= json_encode((string)$diagnosisId) ?>,
            source: <?= json_encode($source) ?>,
            at: Date.now()
        }));
        if (data.redirect) {
            window.location.href = data.redirect;
            return;
        }
    } catch (err) {
        alert(err.message || 'Save failed');
    } finally {
        saveBtn.disabled = false;
        saveBtn.textContent = <?= json_encode($dispenseSaved ? 'Save Changes' : 'Save Diagnosis') ?>;
    }
}

if (saveBtn) {
    saveBtn.addEventListener('click', saveDispense);
}

// initialize print values and totals
window.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.med-row').forEach(row => {
        const priceInput = row.querySelector('.price-input');
        const availSelect = row.querySelector('.avail-select');
        updatePrintValues(row);
        priceInput.addEventListener('input', () => { updatePrintValues(row); updateTotals(); });
        availSelect.addEventListener('change', () => { updatePrintValues(row); });
    });
    paymentMethodInputs.forEach(input => {
        input.addEventListener('change', updatePaymentMethodLabel);
    });
    updatePaymentMethodLabel();
    updateTotals();
});
</script>
</body>
</html>
