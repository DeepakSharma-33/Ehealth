<?php
require_once __DIR__ . '/../../config/config.php';
require_role('pharmacy_staff', 'pharmacy_login.php');

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current = $_POST['current_password'] ?? '';
    $new     = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (!$current || !$new || !$confirm) {
        $error = 'All fields are required.';
    } elseif ($new !== $confirm) {
        $error = 'New password and confirm password do not match.';
    } elseif (strlen($new) < 6) {
        $error = 'New password must be at least 6 characters.';
    } else {
        $pharmId = get_session_id();
        $db = db();
        $st = $db->prepare('SELECT password FROM pharmacy_staff WHERE id = ? LIMIT 1');
        $st->bind_param('i', $pharmId);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();

        if (!$row) {
            $error = 'Pharmacy staff account not found.';
        } elseif (!verify_password($current, $row['password'])) {
            $error = 'Current password is incorrect.';
        } else {
            $hashed = hash_password($new);
            $up = $db->prepare('UPDATE pharmacy_staff SET password = ? WHERE id = ?');
            $up->bind_param('si', $hashed, $pharmId);
            $up->execute();
            $up->close();
            $success = 'Password updated successfully.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password - Pharmacy Staff</title>
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Segoe UI',sans-serif; background:#f1f5f9; min-height:100vh; display:flex; flex-direction:column; }
        .nav { background:#2e7d32; color:#fff; height:56px; padding:0 32px; display:flex; align-items:center; justify-content:space-between; }
        .nav a { color:#fff; text-decoration:none; font-weight:600; }
        .page { flex:1; display:flex; align-items:center; justify-content:center; padding:24px; }
        .card { background:#fff; border-radius:16px; padding:28px 30px; width:100%; max-width:420px; box-shadow:0 12px 40px rgba(0,0,0,.12); }
        .card h1 { font-size:22px; margin-bottom:16px; color:#1b4332; }
        .form-group { margin-bottom:14px; }
        label { font-size:13px; font-weight:600; color:#2c3e35; display:block; margin-bottom:6px; }
        input { width:100%; padding:12px 14px; border:1.5px solid #d7e3dc; border-radius:8px; font-size:14px; }
        .btn { width:100%; padding:12px; background:#2e7d32; color:#fff; border:none; border-radius:8px; font-weight:700; cursor:pointer; }
        .alert { padding:10px 12px; border-radius:8px; margin-bottom:12px; font-size:13px; }
        .alert-error { background:#fdecea; color:#b71c1c; border:1px solid #f5c6cb; }
        .alert-success { background:#e8f5e9; color:#1b5e20; border:1px solid #c3e6cb; }
    </style>
</head>
<body>
    <div class="nav">
        <div>SRMS EHEALTH - Pharmacy Staff</div>
        <a href="pharmacy_dashboard.php">Back to Dashboard</a>
    </div>
    <div class="page">
        <div class="card">
            <h1>Change Password</h1>
            <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
            <form method="POST">
                <div class="form-group">
                    <label for="current_password">Current Password</label>
                    <input type="password" id="current_password" name="current_password" required>
                </div>
                <div class="form-group">
                    <label for="new_password">New Password</label>
                    <input type="password" id="new_password" name="new_password" required>
                </div>
                <div class="form-group">
                    <label for="confirm_password">Confirm New Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" required>
                </div>
                <button type="submit" class="btn">Update Password</button>
            </form>
        </div>
    </div>
</body>
</html>
