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

$patientCols = table_columns($db, 'patients');
$emailCol = pick_column($patientCols, ['email', 'email_id', 'email_address']);
$addressCol = pick_column($patientCols, ['address_full', 'address', 'full_address']);
$photoCol = pick_column($patientCols, ['photo_filename', 'photo', 'photo_path', 'image']);
$createdCol = pick_column($patientCols, ['created_at', 'registered_at', 'created_on', 'created_date', 'registration_date', 'reg_date']);

$selectCols = ['patient_id', 'full_name', 'mobile_no'];
if ($emailCol !== '') $selectCols[] = "{$emailCol} AS email";
if ($addressCol !== '') $selectCols[] = "{$addressCol} AS address_full";
if ($photoCol !== '') $selectCols[] = "{$photoCol} AS photo_filename";
if ($createdCol !== '') $selectCols[] = "{$createdCol} AS created_at";

$sql = 'SELECT ' . implode(', ', $selectCols) . ' FROM patients';
if ($createdCol !== '') {
    $sql .= " ORDER BY {$createdCol} DESC";
} else {
    $sql .= " ORDER BY patient_id DESC";
}
$rows = $db->query($sql)->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patients - Admin</title>
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Segoe UI',sans-serif; background:#eef7f1; min-height:100vh; }
        .nav { background:#2e7d32; color:#fff; height:56px; padding:0 32px; display:flex; align-items:center; justify-content:space-between; }
        .nav a { color:#fff; text-decoration:none; font-weight:600; }
        .page { max-width:1100px; margin:0 auto; padding:28px 18px 60px; }
        .title { font-size:26px; font-weight:700; color:#1b4332; margin-bottom:16px; }
        .card { background:#fff; border-radius:14px; padding:16px; box-shadow:0 2px 12px rgba(27,67,50,.08); }
        table { width:100%; border-collapse:collapse; font-size:13px; }
        th, td { padding:8px; border-bottom:1px solid #e2eee6; text-align:left; }
        th { font-size:12px; text-transform:uppercase; color:#557063; letter-spacing:.4px; background:#f4fbf6; }
        .photo { width:40px; height:40px; border-radius:50%; background:#e7f3ec; display:flex; align-items:center; justify-content:center; font-weight:700; color:#1b5e20; overflow:hidden; border:2px solid #c8e6c9; }
        .photo img { width:100%; height:100%; object-fit:cover; display:block; }
        .addr { max-width:280px; white-space:normal; line-height:1.35; color:#31473a; }
        .btn {
            display:inline-block;
            padding:6px 10px;
            background:#1f6f32;
            color:#fff;
            border-radius:8px;
            font-size:12px;
            font-weight:700;
            text-decoration:none;
        }
        .muted { color:#6a8072; }
    </style>
</head>
<body>
    <div class="nav">
        <div>SRMS EHEALTH - Patients</div>
        <a href="view_analysis.php">Back to Analysis</a>
    </div>
    <div class="page">
        <div class="title">Patients</div>
        <div class="card">
            <table>
                <thead>
                    <tr>
                        <th>Photo</th>
                        <th>Patient ID</th>
                        <th>Name</th>
                        <th>Mobile</th>
                        <th>Email</th>
                        <th>Address</th>
                        <th>Registered</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="8">No patients found.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                        <?php
                            $photoUrl = patient_photo_url($r['photo_filename'] ?? null);
                            $initials = '';
                            $nameRaw = trim((string)($r['full_name'] ?? ''));
                            if ($nameRaw !== '') {
                                $parts = preg_split('/\s+/', $nameRaw);
                                $initials = strtoupper(substr((string)($parts[0] ?? ''), 0, 1))
                                         . strtoupper(substr((string)($parts[1] ?? ''), 0, 1));
                            }
                        ?>
                        <tr>
                            <td>
                                <div class="photo">
                                    <?php if ($photoUrl): ?>
                                        <img src="<?= htmlspecialchars($photoUrl) ?>" alt="Patient Photo">
                                    <?php else: ?>
                                        <?= htmlspecialchars($initials !== '' ? $initials : 'NA') ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td><?= htmlspecialchars($r['patient_id']) ?></td>
                            <td><?= htmlspecialchars($r['full_name']) ?></td>
                            <td><?= htmlspecialchars($r['mobile_no'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($r['email'] ?? '-') ?></td>
                            <td class="addr"><?= htmlspecialchars($r['address_full'] ?? '-') ?></td>
                            <td><?= !empty($r['created_at']) ? date('d M Y, h:i A', strtotime($r['created_at'])) : '-' ?></td>
                            <td><a class="btn" href="patient_diagnosis_details.php?patient_id=<?= urlencode($r['patient_id']) ?>">View Diagnosis Details</a></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
