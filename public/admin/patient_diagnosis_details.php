<?php
require_once __DIR__ . '/../../config/config.php';
require_role('admin', 'admin_login.php');

$db = db();

function table_columns(mysqli $db, string $table): array {
    $safe = $db->real_escape_string($table);
    $res = $db->query("SHOW COLUMNS FROM `{$safe}`");
    if (!$res) return [];
    $cols = [];
    while ($row = $res->fetch_assoc()) {
        $cols[] = $row['Field'];
    }
    $res->free();
    return $cols;
}

function pick_column(array $cols, array $candidates): string {
    foreach ($candidates as $c) {
        if (in_array($c, $cols, true)) return $c;
    }
    return '';
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
        if (table_exists($db, $table)) $out[] = $table;
    }
    return $out;
}

function e(mixed $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES);
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

function parse_medicines_json(string $json): array {
    $json = trim($json);
    if ($json === '') return [];
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) return [];
    $out = [];
    foreach ($decoded as $row) {
        if (!is_array($row)) continue;
        $name = trim((string)($row['name'] ?? ''));
        if ($name === '') continue;
        $out[] = [
            'medicine_name' => $name,
            'dosage' => trim((string)($row['dosage'] ?? '')),
            'timing_list' => is_array($row['timing'] ?? null)
                ? array_values(array_filter(array_map(static fn($x) => trim((string)$x), $row['timing']), static fn($x) => $x !== ''))
                : parse_timing_list($row['timing'] ?? ''),
            'meal_instruction' => trim((string)($row['mealInstruction'] ?? '')),
            'duration' => trim((string)($row['duration'] ?? '')),
        ];
    }
    return $out;
}

$patientId = strtoupper(trim((string)($_GET['patient_id'] ?? '')));
$error = '';
$patient = null;
$ehealthRows = [];
$teleRows = [];

if (!preg_match('/^[A-Z0-9]{3,20}$/', $patientId)) {
    $error = 'Valid patient ID is required.';
} else {
    $patientCols = table_columns($db, 'patients');
    $emailCol = pick_column($patientCols, ['email', 'email_id', 'email_address']);
    $addressCol = pick_column($patientCols, ['address_full', 'address', 'full_address']);
    $photoCol = pick_column($patientCols, ['photo_filename', 'photo', 'photo_path', 'image']);

    $selectCols = ['patient_id', 'full_name', 'age', 'gender', 'mobile_no'];
    if ($emailCol !== '') $selectCols[] = "{$emailCol} AS email";
    if ($addressCol !== '') $selectCols[] = "{$addressCol} AS address_full";
    if ($photoCol !== '') $selectCols[] = "{$photoCol} AS photo_filename";

    $stmt = $db->prepare('SELECT ' . implode(', ', $selectCols) . ' FROM patients WHERE patient_id = ? LIMIT 1');
    $stmt->bind_param('s', $patientId);
    $stmt->execute();
    $patient = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$patient) {
        $error = 'Patient not found.';
    } else {
        $prescriptionsExists = table_exists($db, 'prescriptions');

        if (table_exists($db, 'diagnosis_records')) {
            $ehStmt = $db->prepare(
                "SELECT d.*, COALESCE(ed.full_name, '') AS doctor_name,
                        ed.qualification, ed.specialization, ed.registration_number, ed.license_number,
                        v.bp_systolic, v.bp_diastolic, v.heart_rate, v.temperature, v.spo2, v.resp_rate,
                        v.weight, v.height, v.bmi, v.recorded_at AS vitals_at
                 FROM diagnosis_records d
                 LEFT JOIN ehealth_center_doctors ed ON ed.doctor_id = d.doctor_id
                 LEFT JOIN patient_vitals v ON v.id = (
                     SELECT pv.id
                     FROM patient_vitals pv
                     WHERE pv.patient_id = d.patient_id
                       AND pv.recorded_at <= d.diagnosis_date
                     ORDER BY pv.recorded_at DESC
                     LIMIT 1
                 )
                 WHERE d.patient_id = ?
                 ORDER BY d.diagnosis_date DESC"
            );
            $ehStmt->bind_param('s', $patientId);
            $ehStmt->execute();
            $ehealthRows = $ehStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $ehStmt->close();
        }

        $diagnosisIds = [];
        foreach ($ehealthRows as $row) {
            $diagnosisIds[] = (int)$row['id'];
        }
        $diagnosisIds = array_values(array_unique(array_filter($diagnosisIds)));

        $medicinesByDiagnosis = [];
        if ($prescriptionsExists && !empty($diagnosisIds)) {
            $ph = implode(',', array_fill(0, count($diagnosisIds), '?'));
            $types = str_repeat('i', count($diagnosisIds));
            $medStmt = $db->prepare(
                "SELECT diagnosis_id, medicine_name, dosage, timing, meal_instruction, duration
                 FROM prescriptions
                 WHERE diagnosis_id IN ({$ph})
                 ORDER BY id ASC"
            );
            $medStmt->bind_param($types, ...$diagnosisIds);
            $medStmt->execute();
            $medRows = $medStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $medStmt->close();

            foreach ($medRows as $med) {
                $dId = (int)$med['diagnosis_id'];
                $timingRaw = trim((string)($med['timing'] ?? ''));
                $timingDecoded = json_decode($timingRaw, true);
                if (!is_array($timingDecoded)) {
                    $timingDecoded = $timingRaw !== '' ? [$timingRaw] : [];
                }
                $med['timing_list'] = $timingDecoded;
                $medicinesByDiagnosis[$dId][] = $med;
            }
        }

        foreach ($ehealthRows as &$row) {
            $row['medicines'] = $medicinesByDiagnosis[(int)$row['id']] ?? [];
        }
        unset($row);

        $teleTables = existing_tables($db, ['telestudio_diagnosis', 'teleconsult_diagnosis']);
        $telePrescStmt = null;
        if ($prescriptionsExists) {
            $telePrescStmt = $db->prepare(
                'SELECT medicine_name, dosage, timing, meal_instruction, duration
                 FROM prescriptions
                 WHERE diagnosis_id = ? AND patient_id = ?
                 ORDER BY id ASC'
            );
        }

        foreach ($teleTables as $tbl) {
            $sql = "SELECT td.*, ts.teleconsult_reason,
                           COALESCE(td.telestudio_doctor_name, tdoc.full_name, '') AS telestudio_doctor_name,
                           COALESCE(td.ehealth_doctor_name, ed.full_name, '') AS ehealth_doctor_name,
                           ed.qualification AS ehealth_qualification, ed.specialization AS ehealth_specialization,
                           ed.registration_number AS ehealth_registration, ed.license_number AS ehealth_license,
                           tdoc.qualification AS telestudio_qualification, tdoc.specialization AS telestudio_specialization,
                           tdoc.registration_number AS telestudio_registration, tdoc.license_number AS telestudio_license,
                           v.bp_systolic, v.bp_diastolic, v.heart_rate, v.temperature, v.spo2, v.resp_rate,
                           v.weight, v.height, v.bmi, v.recorded_at AS vitals_at
                    FROM {$tbl} td
                    LEFT JOIN teleconsult_sessions ts ON ts.id = td.session_id
                    LEFT JOIN telestudio_doctors tdoc ON tdoc.doctor_id = td.telestudio_doctor_id
                    LEFT JOIN ehealth_center_doctors ed ON ed.doctor_id = td.ehealth_doctor_id
                    LEFT JOIN patient_vitals v ON v.id = (
                        SELECT pv.id
                        FROM patient_vitals pv
                        WHERE pv.patient_id = td.patient_id
                          AND pv.recorded_at <= td.diagnosis_date
                        ORDER BY pv.recorded_at DESC
                        LIMIT 1
                    )
                    WHERE td.patient_id = ?
                    ORDER BY td.diagnosis_date DESC";
            $ts = $db->prepare($sql);
            if (!$ts) continue;
            $ts->bind_param('s', $patientId);
            $ts->execute();
            $rows = $ts->get_result()->fetch_all(MYSQLI_ASSOC);
            $ts->close();

            foreach ($rows as $row) {
                $row['source_table'] = $tbl;
                $row['medicines'] = [];

                $medJson = trim((string)($row['medicines_json'] ?? ''));
                $row['medicines'] = parse_medicines_json($medJson);

                if (empty($row['medicines']) && $telePrescStmt) {
                    $diagId = (int)($row['id'] ?? 0);
                    if ($diagId > 0) {
                        $telePrescStmt->bind_param('is', $diagId, $patientId);
                        $telePrescStmt->execute();
                        $medRows = $telePrescStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                        foreach ($medRows as $med) {
                            $med['timing_list'] = parse_timing_list($med['timing'] ?? '');
                            $row['medicines'][] = $med;
                        }
                    }
                }

                $teleRows[] = $row;
            }
        }

        if ($telePrescStmt) {
            $telePrescStmt->close();
        }
    }
}

$photoUrl = $patient ? patient_photo_url($patient['photo_filename'] ?? null) : '';
$initials = '';
$patientNameRaw = trim((string)($patient['full_name'] ?? ''));
if ($patientNameRaw !== '') {
    $parts = preg_split('/\s+/', $patientNameRaw);
    $initials = strtoupper(substr((string)($parts[0] ?? ''), 0, 1))
              . strtoupper(substr((string)($parts[1] ?? ''), 0, 1));
}

function format_datetime(?string $raw): string {
    $raw = trim((string)$raw);
    if ($raw === '') return '-';
    $ts = strtotime($raw);
    if (!$ts) return '-';
    return date('d M Y, h:i A', $ts);
}

function vitals_line(array $row): string {
    $parts = [];
    $bpSys = trim((string)($row['bp_systolic'] ?? ''));
    $bpDia = trim((string)($row['bp_diastolic'] ?? ''));
    if ($bpSys !== '' || $bpDia !== '') $parts[] = 'BP: ' . $bpSys . '/' . $bpDia . ' mmHg';
    if (!empty($row['heart_rate'])) $parts[] = 'Pulse: ' . $row['heart_rate'] . ' bpm';
    if (!empty($row['temperature'])) $parts[] = 'Temp: ' . $row['temperature'] . ' C';
    if (!empty($row['spo2'])) $parts[] = 'SpO2: ' . $row['spo2'] . '%';
    if (!empty($row['resp_rate'])) $parts[] = 'Resp: ' . $row['resp_rate'] . '/min';
    if (!empty($row['weight'])) $parts[] = 'Wt: ' . $row['weight'] . ' kg';
    if (!empty($row['height'])) $parts[] = 'Ht: ' . $row['height'] . ' cm';
    if (!empty($row['bmi'])) $parts[] = 'BMI: ' . $row['bmi'];
    return implode(' | ', $parts);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Diagnosis Details</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        :root {
            --bg:#eef7f1;
            --green:#1f6f32;
            --green-dark:#145224;
            --ink:#13301d;
            --muted:#56705c;
            --card:#ffffff;
            --border:#d6e6da;
            --accent:#f0a500;
        }
        body {
            font-family:'Plus Jakarta Sans', sans-serif;
            background:var(--bg);
            min-height:100vh;
        }
        .nav {
            background:#2e7d32;
            color:#fff;
            height:56px;
            padding:0 32px;
            display:flex;
            align-items:center;
            justify-content:space-between;
        }
        .nav a { color:#fff; text-decoration:none; font-weight:700; }
        .page { max-width:1100px; margin:0 auto; padding:26px 18px 60px; }
        .title {
            font-family:'Fraunces', serif;
            font-size:30px;
            color:var(--ink);
            margin-bottom:18px;
        }
        .card {
            background:var(--card);
            border-radius:16px;
            padding:16px 18px;
            box-shadow:0 8px 24px rgba(20,60,35,.08);
            margin-bottom:20px;
        }
        .patient-card {
            display:flex;
            gap:16px;
            align-items:center;
        }
        .photo {
            width:72px;
            height:72px;
            border-radius:50%;
            overflow:hidden;
            background:#e8f5e9;
            border:3px solid #c8e6c9;
            display:flex;
            align-items:center;
            justify-content:center;
            font-weight:700;
            color:var(--green-dark);
            font-size:22px;
        }
        .photo img { width:100%; height:100%; object-fit:cover; display:block; }
        .patient-meta { color:var(--muted); font-size:13px; margin-top:4px; }
        .section-title {
            font-size:15px;
            font-weight:800;
            color:var(--green);
            text-transform:uppercase;
            letter-spacing:.6px;
            margin:20px 0 12px;
        }
        .diag-card {
            background:var(--card);
            border-radius:14px;
            border:1px solid var(--border);
            padding:14px 16px;
            margin-bottom:14px;
        }
        .diag-head {
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:12px;
            margin-bottom:8px;
        }
        .badge {
            display:inline-flex;
            align-items:center;
            padding:4px 10px;
            border-radius:999px;
            font-size:11px;
            font-weight:800;
            letter-spacing:.4px;
        }
        .badge.eh { background:#e8f5e9; color:#1b5e20; border:1px solid #c8e6c9; }
        .badge.tele { background:#e3f2fd; color:#0d47a1; border:1px solid #bbdefb; }
        .diag-date { font-size:12px; color:var(--muted); }
        .diag-row { font-size:13px; color:#1f3627; margin-bottom:6px; }
        .diag-row strong { color:var(--green-dark); }
        .diag-section { margin-top:10px; padding-top:10px; border-top:1px dashed #d7e5da; }
        .diag-section-title {
            font-size:12px;
            font-weight:800;
            color:var(--green);
            text-transform:uppercase;
            letter-spacing:.5px;
            margin-bottom:4px;
        }
        .diag-body { font-size:13px; color:#1f3627; white-space:pre-wrap; }
        table { width:100%; border-collapse:collapse; font-size:12px; margin-top:8px; }
        th, td { padding:6px 6px; border:1px solid #e1eee4; text-align:left; }
        th { background:#f4fbf6; text-transform:uppercase; letter-spacing:.4px; font-size:11px; color:var(--muted); }
        .empty {
            color:var(--muted);
            font-size:13px;
            padding:14px 0;
        }
        .error {
            background:#fdecea;
            color:#7f1d1d;
            border:1px solid #f5c6cb;
            padding:12px 14px;
            border-radius:10px;
        }
        @media (max-width: 720px) {
            .patient-card { flex-direction:column; text-align:center; }
            .diag-head { flex-direction:column; align-items:flex-start; }
        }
    </style>
</head>
<body>
    <div class="nav">
        <div>SRMS EHEALTH - Patient Details</div>
        <a href="analysis_patients.php">Back to Patients</a>
    </div>
    <div class="page">
        <div class="title">Patient Diagnosis Details</div>

        <?php if ($error !== ''): ?>
            <div class="card error"><?= e($error) ?></div>
        <?php elseif ($patient): ?>
            <div class="card patient-card">
                <div class="photo">
                    <?php if ($photoUrl): ?>
                        <img src="<?= e($photoUrl) ?>" alt="Patient Photo">
                    <?php else: ?>
                        <?= e($initials !== '' ? $initials : 'NA') ?>
                    <?php endif; ?>
                </div>
                <div>
                    <div style="font-size:20px;font-weight:800;color:var(--ink);">
                        <?= e($patient['full_name'] ?? '-') ?>
                    </div>
                    <div class="patient-meta">Patient ID: <?= e($patient['patient_id'] ?? '-') ?> | Age/Gender: <?= e(($patient['age'] ?? '-') . '/' . ($patient['gender'] ?? '-')) ?> | Mobile: <?= e($patient['mobile_no'] ?? '-') ?></div>
                    <div class="patient-meta">Email: <?= e($patient['email'] ?? '-') ?></div>
                    <div class="patient-meta">Address: <?= e($patient['address_full'] ?? '-') ?></div>
                </div>
            </div>

            <div class="section-title">eHealth Center Diagnoses</div>
            <?php if (empty($ehealthRows)): ?>
                <div class="card empty">No eHealth diagnosis records found.</div>
            <?php else: ?>
                <?php foreach ($ehealthRows as $row): ?>
                    <?php
                        $doctorName = trim((string)($row['doctor_name'] ?? ''));
                        $doctorId = trim((string)($row['doctor_id'] ?? ''));
                        $doctorMeta = implode(' | ', array_filter([
                            trim((string)($row['qualification'] ?? '')),
                            trim((string)($row['specialization'] ?? '')),
                            trim((string)($row['registration_number'] ?? ($row['license_number'] ?? ''))) !== ''
                                ? 'Reg: ' . trim((string)($row['registration_number'] ?? ($row['license_number'] ?? '')))
                                : ''
                        ]));
                        $vitals = vitals_line($row);
                        $teleReason = trim((string)($row['teleconsultation_reason'] ?? ''));
                    ?>
                    <div class="diag-card">
                        <div class="diag-head">
                            <span class="badge eh">EHEALTH</span>
                            <div class="diag-date"><?= e(format_datetime($row['diagnosis_date'] ?? '')) ?></div>
                        </div>
                        <div class="diag-row"><strong>Doctor:</strong> <?= e($doctorName !== '' ? $doctorName : '-') ?><?= $doctorId !== '' ? ' (' . e($doctorId) . ')' : '' ?></div>
                        <?php if ($doctorMeta !== ''): ?>
                            <div class="diag-row"><strong>Doctor Details:</strong> <?= e($doctorMeta) ?></div>
                        <?php endif; ?>
                        <?php if ($vitals !== ''): ?>
                            <div class="diag-row"><strong>Vitals:</strong> <?= e($vitals) ?></div>
                        <?php endif; ?>
                        <?php if ($teleReason !== ''): ?>
                            <div class="diag-row"><strong>Teleconsultation Reason:</strong> <?= e($teleReason) ?></div>
                        <?php endif; ?>

                        <?php if (!empty($row['chief_complaint'])): ?>
                            <div class="diag-section">
                                <div class="diag-section-title">Chief Complaint</div>
                                <div class="diag-body"><?= e($row['chief_complaint']) ?></div>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($row['provisional_diagnosis'])): ?>
                            <div class="diag-section">
                                <div class="diag-section-title">Diagnosis</div>
                                <div class="diag-body"><?= e($row['provisional_diagnosis']) ?></div>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($row['clinical_notes'])): ?>
                            <div class="diag-section">
                                <div class="diag-section-title">Clinical Notes</div>
                                <div class="diag-body"><?= e($row['clinical_notes']) ?></div>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($row['investigation_prescribed'])): ?>
                            <div class="diag-section">
                                <div class="diag-section-title">Investigation</div>
                                <div class="diag-body"><?= e($row['investigation_prescribed']) ?></div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($row['medicines'])): ?>
                            <div class="diag-section">
                                <div class="diag-section-title">Medicines</div>
                                <table>
                                    <thead>
                                        <tr>
                                            <th style="width:5%">#</th>
                                            <th>Medicine</th>
                                            <th>Dose</th>
                                            <th>Timing</th>
                                            <th>Meal</th>
                                            <th>Duration</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($row['medicines'] as $idx => $med): ?>
                                            <tr>
                                                <td><?= $idx + 1 ?></td>
                                                <td><?= e($med['medicine_name'] ?? '-') ?></td>
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

                        <?php if (!empty($row['review_after']) || !empty($row['remarks'])): ?>
                            <div class="diag-section">
                                <div class="diag-section-title">Follow-up</div>
                                <div class="diag-body">
                                    <?php if (!empty($row['review_after'])): ?>
                                        <div><strong>Review:</strong> <?= e($row['review_after']) ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($row['remarks'])): ?>
                                        <div><strong>Remarks:</strong> <?= e($row['remarks']) ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <div class="section-title">Teleconsultation Diagnoses</div>
            <?php if (empty($teleRows)): ?>
                <div class="card empty">No teleconsultation diagnosis records found.</div>
            <?php else: ?>
                <?php foreach ($teleRows as $row): ?>
                    <?php
                        $teleDoctorName = trim((string)($row['telestudio_doctor_name'] ?? ''));
                        $teleDoctorId = trim((string)($row['telestudio_doctor_id'] ?? ''));
                        $teleDoctorMeta = implode(' | ', array_filter([
                            trim((string)($row['telestudio_qualification'] ?? '')),
                            trim((string)($row['telestudio_specialization'] ?? '')),
                            trim((string)($row['telestudio_registration'] ?? ($row['telestudio_license'] ?? ''))) !== ''
                                ? 'Reg: ' . trim((string)($row['telestudio_registration'] ?? ($row['telestudio_license'] ?? '')))
                                : ''
                        ]));

                        $centerDoctorName = trim((string)($row['ehealth_doctor_name'] ?? ''));
                        $centerDoctorId = trim((string)($row['ehealth_doctor_id'] ?? ''));
                        $centerDoctorMeta = implode(' | ', array_filter([
                            trim((string)($row['ehealth_qualification'] ?? '')),
                            trim((string)($row['ehealth_specialization'] ?? '')),
                            trim((string)($row['ehealth_registration'] ?? ($row['ehealth_license'] ?? ''))) !== ''
                                ? 'Reg: ' . trim((string)($row['ehealth_registration'] ?? ($row['ehealth_license'] ?? '')))
                                : ''
                        ]));
                        $vitals = vitals_line($row);
                        $teleNotes = trim((string)($row['consultation_notes'] ?? ''));
                        if ($teleNotes === '') {
                            $teleNotes = trim((string)($row['clinical_notes'] ?? ''));
                        }
                    ?>
                    <div class="diag-card">
                        <div class="diag-head">
                            <span class="badge tele">TELECONSULT</span>
                            <div class="diag-date"><?= e(format_datetime($row['diagnosis_date'] ?? '')) ?></div>
                        </div>
                        <div class="diag-row"><strong>Telestudio Doctor:</strong> <?= e($teleDoctorName !== '' ? $teleDoctorName : '-') ?><?= $teleDoctorId !== '' ? ' (' . e($teleDoctorId) . ')' : '' ?></div>
                        <?php if ($teleDoctorMeta !== ''): ?>
                            <div class="diag-row"><strong>Telestudio Details:</strong> <?= e($teleDoctorMeta) ?></div>
                        <?php endif; ?>
                        <div class="diag-row"><strong>eHealth Doctor:</strong> <?= e($centerDoctorName !== '' ? $centerDoctorName : '-') ?><?= $centerDoctorId !== '' ? ' (' . e($centerDoctorId) . ')' : '' ?></div>
                        <?php if ($centerDoctorMeta !== ''): ?>
                            <div class="diag-row"><strong>eHealth Details:</strong> <?= e($centerDoctorMeta) ?></div>
                        <?php endif; ?>
                        <?php if ($vitals !== ''): ?>
                            <div class="diag-row"><strong>Vitals:</strong> <?= e($vitals) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($row['teleconsult_reason'])): ?>
                            <div class="diag-row"><strong>Teleconsultation Reason:</strong> <?= e($row['teleconsult_reason']) ?></div>
                        <?php endif; ?>

                        <?php if (!empty($row['chief_complaint'])): ?>
                            <div class="diag-section">
                                <div class="diag-section-title">Chief Complaint</div>
                                <div class="diag-body"><?= e($row['chief_complaint']) ?></div>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($row['primary_diagnosis'])): ?>
                            <div class="diag-section">
                                <div class="diag-section-title">Diagnosis</div>
                                <div class="diag-body"><?= e($row['primary_diagnosis']) ?></div>
                            </div>
                        <?php endif; ?>
                        <?php if ($teleNotes !== ''): ?>
                            <div class="diag-section">
                                <div class="diag-section-title">Clinical Notes</div>
                                <div class="diag-body"><?= e($teleNotes) ?></div>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($row['investigation'])): ?>
                            <div class="diag-section">
                                <div class="diag-section-title">Investigation</div>
                                <div class="diag-body"><?= e($row['investigation']) ?></div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($row['medicines'])): ?>
                            <div class="diag-section">
                                <div class="diag-section-title">Medicines</div>
                                <table>
                                    <thead>
                                        <tr>
                                            <th style="width:5%">#</th>
                                            <th>Medicine</th>
                                            <th>Dose</th>
                                            <th>Timing</th>
                                            <th>Meal</th>
                                            <th>Duration</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($row['medicines'] as $idx => $med): ?>
                                            <tr>
                                                <td><?= $idx + 1 ?></td>
                                                <td><?= e($med['medicine_name'] ?? '-') ?></td>
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

                        <?php if (!empty($row['prescription'])): ?>
                            <div class="diag-section">
                                <div class="diag-section-title">Prescription Notes</div>
                                <div class="diag-body"><?= e($row['prescription']) ?></div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($row['review_after']) || !empty($row['remarks'])): ?>
                            <div class="diag-section">
                                <div class="diag-section-title">Follow-up</div>
                                <div class="diag-body">
                                    <?php if (!empty($row['review_after'])): ?>
                                        <div><strong>Review:</strong> <?= e($row['review_after']) ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($row['remarks'])): ?>
                                        <div><strong>Remarks:</strong> <?= e($row['remarks']) ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</body>
</html>
