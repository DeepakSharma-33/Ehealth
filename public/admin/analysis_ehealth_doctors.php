<?php
require_once __DIR__ . '/../../config/config.php';
require_role('admin', 'admin_login.php');

$db = db();
$rows = $db->query('SELECT doctor_id, full_name, mobile_no, created_at FROM ehealth_center_doctors ORDER BY created_at DESC')->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>eHealth Doctors - Admin</title>
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
    </style>
</head>
<body>
    <div class="nav">
        <div>SRMS EHEALTH - eHealth Doctors</div>
        <a href="view_analysis.php">Back to Analysis</a>
    </div>
    <div class="page">
        <div class="title">eHealth Center Doctors</div>
        <div class="card">
            <table>
                <thead>
                    <tr><th>Doctor ID</th><th>Name</th><th>Mobile</th><th>Registered</th></tr>
                </thead>
                <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="4">No doctors found.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><?= htmlspecialchars($r['doctor_id']) ?></td>
                            <td><?= htmlspecialchars($r['full_name']) ?></td>
                            <td><?= htmlspecialchars($r['mobile_no'] ?? '-') ?></td>
                            <td><?= $r['created_at'] ? date('d M Y, h:i A', strtotime($r['created_at'])) : '-' ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
