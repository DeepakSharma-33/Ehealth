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
    'SELECT patient_id, full_name, mobile_no, photo_filename
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

$vStmt = $db->prepare(
    'SELECT bp_systolic, bp_diastolic, heart_rate, temperature, spo2, weight, height, bmi, resp_rate, recorded_at
     FROM patient_vitals
     WHERE patient_id = ?
     ORDER BY recorded_at DESC'
);
$vStmt->bind_param('s', $patientId);
$vStmt->execute();
$vitals = $vStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$vStmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vitals History - Patient</title>
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
        .btn-print { display:inline-block; padding:7px 12px; border-radius:8px; font-size:12px; font-weight:700; text-decoration:none; border:1px solid var(--border); background:var(--accent); color:#1a1a1a; }
        .hint { font-size:12px; color:var(--muted); margin-top:10px; }
        @media (max-width: 720px) {
            .profile { flex-direction:column; text-align:center; }
            table { font-size:12px; }
            th, td { padding:8px 6px; }
        }
        @media print { .navbar, .hint, .btn-print { display:none !important; } }
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
        <div class="title">Vitals History</div>

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
            </div>
            <div style="margin-left:auto;">
                <button class="btn-print" onclick="window.print()">Print</button>
            </div>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Date/Time</th>
                        <th>BP</th>
                        <th>Pulse</th>
                        <th>Temp (C)</th>
                        <th>SpO2</th>
                        <th>Weight</th>
                        <th>Height</th>
                        <th>BMI</th>
                        <th>Resp Rate</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($vitals)): ?>
                    <tr><td colspan="9" style="text-align:center;color:var(--muted);padding:20px;">No vitals recorded yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($vitals as $v): ?>
                        <?php $dt = $v['recorded_at'] ? date('d M Y, h:i A', strtotime($v['recorded_at'])) : '-'; ?>
                        <tr>
                            <td><?= htmlspecialchars($dt) ?></td>
                            <td><?= htmlspecialchars(($v['bp_systolic'] ?? '-') . '/' . ($v['bp_diastolic'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars($v['heart_rate'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($v['temperature'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($v['spo2'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($v['weight'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($v['height'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($v['bmi'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($v['resp_rate'] ?? '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
            <div class="hint">Use the Print button to save a PDF.</div>
        </div>
    </div>
</body>
</html>
