<?php
require_once __DIR__ . '/../../config/config.php';
require_role('patient', 'patient_login.php');

$patientId = (string)($_SESSION['patient_id'] ?? '');
if ($patientId === '') {
    http_response_code(403);
    echo 'Patient ID not found in session.';
    exit;
}

$db = db();
$patientStmt = $db->prepare(
    'SELECT patient_id, full_name, age, gender, mobile_no, address_full, photo_filename
     FROM patients WHERE patient_id = ? LIMIT 1'
);
$patientStmt->bind_param('s', $patientId);
$patientStmt->execute();
$patient = $patientStmt->get_result()->fetch_assoc();
$patientStmt->close();

if (!$patient) {
    http_response_code(404);
    echo 'Patient not found.';
    exit;
}

$photoUrl = patient_photo_url($patient['photo_filename'] ?? null);
$base = app_base_url();

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

$records = [];

// eHealth (in-person) diagnosis history
$ehStmt = $db->prepare(
    'SELECT d.id, d.diagnosis_date, d.chief_complaint, d.provisional_diagnosis, d.remarks,
            COALESCE(ed.full_name, "") AS doctor_name
     FROM diagnosis_records d
     LEFT JOIN ehealth_center_doctors ed ON ed.doctor_id = d.doctor_id
     WHERE d.patient_id = ?
     ORDER BY d.diagnosis_date DESC'
);
$ehStmt->bind_param('s', $patientId);
$ehStmt->execute();
$ehRows = $ehStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$ehStmt->close();

foreach ($ehRows as $row) {
    $records[] = [
        'type' => 'EHEALTH',
        'id' => (int)$row['id'],
        'date' => (string)$row['diagnosis_date'],
        'doctor' => (string)($row['doctor_name'] ?? '-'),
        'summary' => (string)($row['provisional_diagnosis'] ?: ($row['chief_complaint'] ?: '-')),
        'payment' => '',
        'source_table' => '',
        'download_url' => $base . '/public/ehealth_center_doctor/print_diagnosis.php?diagnosisId=' . (int)$row['id'] . '&type=ehealth&autoprint=1',
        'payment_done' => true,
    ];
}

// Teleconsultation history (telestudio_diagnosis / teleconsult_diagnosis)
$teleTables = existing_tables($db, ['telestudio_diagnosis', 'teleconsult_diagnosis']);
foreach ($teleTables as $tbl) {
    $sql = "SELECT td.*, ts.payment_method,
                   COALESCE(td.telestudio_doctor_name, tdoc.full_name, '') AS telestudio_doctor_name
            FROM {$tbl} td
            LEFT JOIN teleconsult_sessions ts ON ts.id = td.session_id
            LEFT JOIN telestudio_doctors tdoc ON tdoc.doctor_id = td.telestudio_doctor_id
            WHERE td.patient_id = ?
            ORDER BY td.diagnosis_date DESC";
    $ts = $db->prepare($sql);
    if (!$ts) continue;
    $ts->bind_param('s', $patientId);
    $ts->execute();
    $rows = $ts->get_result()->fetch_all(MYSQLI_ASSOC);
    $ts->close();

    foreach ($rows as $row) {
        $summary = (string)($row['primary_diagnosis'] ?? '');
        if ($summary === '') $summary = (string)($row['chief_complaint'] ?? '');
        if ($summary === '') $summary = (string)($row['consultation_notes'] ?? '');
        if ($summary === '') $summary = '-';

        $paymentDone = trim((string)($row['payment_method'] ?? '')) !== '';

        $records[] = [
            'type' => 'TELECONSULT',
            'id' => (int)$row['id'],
            'date' => (string)($row['diagnosis_date'] ?? ''),
            'doctor' => (string)($row['telestudio_doctor_name'] ?? '-'),
            'summary' => $summary,
            'payment' => $paymentDone ? 'Paid' : 'Pending',
            'source_table' => $tbl,
            'download_url' => $base . '/public/ehealth_center_doctor/print_diagnosis.php?diagnosisId=' . (int)$row['id'] . '&type=teleconsult&srcTable=' . rawurlencode($tbl) . '&autoprint=1',
            'payment_done' => $paymentDone,
        ];
    }
}

usort($records, static function ($a, $b) {
    $ta = strtotime((string)$a['date']) ?: 0;
    $tb = strtotime((string)$b['date']) ?: 0;
    return $tb <=> $ta;
});
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Diagnosis History - Patient</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=DM+Serif+Display&display=swap" rel="stylesheet">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        :root {
            --green-dark:#1b4332;
            --green-nav:#2d6a4f;
            --green-mid:#40916c;
            --green-light:#d8f3dc;
            --green-pale:#f0faf2;
            --border:#b7e4c7;
            --text:#1b2e22;
            --muted:#52796f;
            --accent:#f0a500;
            --danger:#b71c1c;
        }
        body { font-family:'DM Sans', sans-serif; background:var(--green-light); min-height:100vh; }
        .navbar { background:var(--green-nav); padding:0 32px; height:56px; display:flex; align-items:center; justify-content:space-between; box-shadow:0 2px 12px rgba(0,0,0,.18); }
        .navbar-brand { display:flex; align-items:center; gap:12px; color:#fff; text-decoration:none; font-weight:700; letter-spacing:1px; }
        .page { max-width:1100px; margin:0 auto; padding:28px 18px 60px; }
        .title { font-family:'DM Serif Display', serif; font-size:28px; color:var(--green-dark); text-align:center; margin-bottom:18px; }
        .profile { background:#fff; border-radius:16px; padding:16px 18px; display:flex; gap:16px; align-items:center; box-shadow:0 2px 12px rgba(27,67,50,.08); margin-bottom:20px; }
        .profile-photo { width:64px; height:64px; border-radius:50%; background:var(--green-light); overflow:hidden; display:flex; align-items:center; justify-content:center; font-size:24px; border:3px solid var(--green-mid); }
        .profile-photo img { width:100%; height:100%; object-fit:cover; }
        .profile-meta { color:var(--muted); font-size:13px; }
        .table-wrap { background:#fff; border-radius:16px; padding:16px; box-shadow:0 2px 12px rgba(27,67,50,.08); }
        table { width:100%; border-collapse:collapse; font-size:13px; }
        th, td { text-align:left; padding:10px 8px; border-bottom:1px solid #e3efe7; vertical-align:top; }
        th { color:var(--green-dark); font-weight:700; background:var(--green-pale); font-size:12px; text-transform:uppercase; letter-spacing:.4px; }
        .badge { display:inline-block; padding:4px 10px; border-radius:14px; font-size:11px; font-weight:700; letter-spacing:.4px; }
        .badge.eh { background:#e8f5e9; color:#1b5e20; border:1px solid #c8e6c9; }
        .badge.tele { background:#e3f2fd; color:#0d47a1; border:1px solid #bbdefb; }
        .badge.pending { background:#fdecea; color:#b71c1c; border:1px solid #f5c6cb; }
        .btn { display:inline-block; padding:7px 12px; border-radius:8px; font-size:12px; font-weight:700; text-decoration:none; border:1px solid var(--border); color:var(--green-dark); }
        .btn-download { background:var(--accent); color:#1a1a1a; border-color:var(--accent); }
        .btn-disabled { background:#eee; color:#999; border-color:#ddd; cursor:not-allowed; }
        .hint { font-size:12px; color:var(--muted); margin-top:10px; }
        @media (max-width: 720px) {
            .profile { flex-direction:column; text-align:center; }
            table { font-size:12px; }
            th, td { padding:8px 6px; }
        }
        @media print { .navbar, .hint { display:none !important; } }
    </style>
 </head>
<body>
    <nav class="navbar">
        <a href="<?= htmlspecialchars(app_base_url()) ?>/" class="navbar-brand">
            <span style="display:flex;align-items:center;gap:12px;line-height:1;">
                <img src="<?= htmlspecialchars(app_base_url()) ?>/public/assets/SRMS_TRUST_LOGO.png" alt="SRMST Trust Logo" style="height:40px;width:auto;display:block;object-fit:contain;">
                <span>SRMS EHEALTH</span>
            </span>
        </a>
        <div style="display:flex;gap:16px;align-items:center;">
            <a href="patient_dashboard.php" style="color:#fff;text-decoration:none;font-weight:600;">Back to Dashboard</a>
            <a href="<?= htmlspecialchars(app_base_url()) ?>/about.php" style="color:#fff;text-decoration:none;font-weight:600;">ABOUT</a>
            <a href="<?= htmlspecialchars(app_base_url()) ?>/contact.php" style="color:#fff;text-decoration:none;font-weight:600;">CONTACT</a>
        </div>
    </nav>

    <div class="page">
        <div class="title">Diagnosis History</div>

        <div class="profile">
            <div class="profile-photo">
                <?php if ($photoUrl): ?>
                    <img src="<?= htmlspecialchars($photoUrl) ?>" alt="Patient Photo">
                <?php else: ?>
                    👤
                <?php endif; ?>
            </div>
            <div>
                <div style="font-size:18px;font-weight:700;color:var(--green-dark);"><?= htmlspecialchars($patient['full_name']) ?></div>
                <div class="profile-meta">Patient ID: <?= htmlspecialchars($patient['patient_id']) ?> | Mobile: <?= htmlspecialchars($patient['mobile_no'] ?? '-') ?></div>
                <div class="profile-meta">Address: <?= htmlspecialchars($patient['address_full'] ?? '-') ?></div>
            </div>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Type</th>
                        <th>Date/Time</th>
                        <th>Doctor</th>
                        <th>Summary</th>
                        <th>Status</th>
                        <th>PDF</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($records)): ?>
                    <tr><td colspan="6" style="text-align:center;color:var(--muted);padding:20px;">No diagnosis history found.</td></tr>
                <?php else: ?>
                    <?php foreach ($records as $rec): ?>
                        <?php $dateTxt = $rec['date'] ? date('d M Y, h:i A', strtotime($rec['date'])) : '-'; ?>
                        <tr>
                            <td>
                                <?php if ($rec['type'] === 'TELECONSULT'): ?>
                                    <span class="badge tele">TELECONSULT</span>
                                <?php else: ?>
                                    <span class="badge eh">EHEALTH</span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($dateTxt) ?></td>
                            <td><?= htmlspecialchars($rec['doctor'] ?: '-') ?></td>
                            <td><?= htmlspecialchars($rec['summary']) ?></td>
                            <td>
                                <?php if ($rec['type'] === 'TELECONSULT' && !$rec['payment_done']): ?>
                                    <span class="badge pending">Payment Pending</span>
                                <?php else: ?>
                                    <span class="badge eh">Ready</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($rec['type'] === 'TELECONSULT' && !$rec['payment_done']): ?>
                                    <span class="btn btn-disabled">Download PDF</span>
                                <?php else: ?>
                                    <a class="btn btn-download" target="_blank" href="<?= htmlspecialchars($rec['download_url']) ?>">Download PDF</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
            <div class="hint">Download opens a printable slip. Use the browser print dialog to save as PDF.</div>
        </div>
    </div>
</body>
</html>
