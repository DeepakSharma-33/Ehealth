<?php
require_once __DIR__ . '/../../config/config.php';
start_session();

// Already logged in → go to dashboard
if (is_logged_in() && get_session_role() === 'doctor') {
    header('Location: doctor_dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim($_POST['identifier'] ?? '');
    $password   = $_POST['password'] ?? '';

    if ($identifier === '' || $password === '') {
        $error = 'Doctor ID/Mobile and password are required.';
    } else {
        $db   = db();
        $stmt = $db->prepare('SELECT doctor_id, full_name, password FROM ehealth_center_doctors WHERE doctor_id = ? OR mobile_no = ? LIMIT 1');
        $stmt->bind_param('ss', $identifier, $identifier);
        $stmt->execute();
        $doctor = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$doctor || !verify_password($password, $doctor['password'])) {
            $error = 'Invalid Doctor ID/Mobile or password.';
        } else {
            set_user_session([
                'id'   => $doctor['doctor_id'],
                'role' => 'doctor',
                'name' => $doctor['full_name'],
            ]);
            header('Location: doctor_dashboard.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Doctor Login - eHealth Center</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', sans-serif; background: linear-gradient(135deg, #1a6b4a 0%, #2d8f63 100%); min-height: 100vh; display: flex; flex-direction: column; }

        .navbar { background: #134d36; padding: 0 40px; height: 56px; display: flex; align-items: center; justify-content: space-between; box-shadow: 0 2px 8px rgba(0,0,0,0.2); }
        .navbar-brand { display: flex; align-items: center; gap: 10px; text-decoration: none; }
        .brand-icon { width: 36px; height: 36px; background: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 18px; }
        .brand-name { font-size: 20px; font-weight: 700; letter-spacing: 1.5px; color: white; }
        .nav-links { display: flex; gap: 40px; list-style: none; }
        .nav-links a { color: white; text-decoration: none; font-size: 14px; font-weight: 500; opacity: 0.92; }
        .nav-links a:hover { opacity: 1; text-decoration: underline; }

        .page-body { flex: 1; display: flex; align-items: center; justify-content: center; padding: 40px 20px; }

        .card { background: white; border-radius: 18px; box-shadow: 0 20px 60px rgba(0,0,0,0.25); width: 100%; max-width: 460px; padding: 48px 44px 40px; animation: fadeUp 0.4s ease both; }
        @keyframes fadeUp { from { opacity: 0; transform: translateY(24px); } to { opacity: 1; transform: translateY(0); } }

        .card-header { text-align: center; margin-bottom: 34px; }
        .avatar { width: 72px; height: 72px; background: linear-gradient(135deg, #2e7d32, #43a047); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 34px; margin: 0 auto 16px; box-shadow: 0 6px 20px rgba(46,125,50,0.35); }
        .card-header h1 { font-size: 26px; font-weight: 700; color: #1a1a2e; margin-bottom: 4px; }
        .card-header h3 { font-size: 15px; font-weight: 500; color: #2e7d32; margin-bottom: 6px; }
        .card-header p { font-size: 14px; color: #6c757d; }

        .form-group { margin-bottom: 22px; }
        .form-group label { display: block; font-size: 13px; font-weight: 600; color: #333; margin-bottom: 8px; }
        .required { color: #e74c3c; }
        .input-wrapper { position: relative; }
        .input-wrapper input { width: 100%; padding: 13px 16px; border: 2px solid #e0e0e0; border-radius: 10px; font-size: 15px; background: #f7f9ff; color: #1a1a2e; transition: border-color 0.25s, background 0.25s; outline: none; }
        .input-wrapper input:focus { border-color: #2e7d32; background: white; box-shadow: 0 0 0 3px rgba(46,125,50,0.12); }
        .toggle-pw { position: absolute; right: 14px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; font-size: 18px; color: #888; }
        .toggle-pw:hover { color: #2e7d32; }

        .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 22px; font-size: 14px; }
        .alert-error { background: #fdecea; color: #b71c1c; border: 1px solid #f5c6cb; }

        .btn-login { width: 100%; padding: 15px; background: linear-gradient(135deg, #2e7d32, #43a047); color: white; border: none; border-radius: 10px; font-size: 15px; font-weight: 700; letter-spacing: 0.8px; text-transform: uppercase; cursor: pointer; transition: opacity 0.2s, transform 0.15s; box-shadow: 0 4px 16px rgba(46,125,50,0.35); }
        .btn-login:hover { opacity: 0.93; transform: translateY(-1px); }

        .divider { border: none; border-top: 1px solid #eee; margin: 28px 0 20px; }
        .card-footer { text-align: center; font-size: 14px; color: #6c757d; }
        .card-footer a { color: #2e7d32; text-decoration: none; font-weight: 600; }
        .card-footer a:hover { text-decoration: underline; }

        @media (max-width: 520px) { .card { padding: 36px 24px 32px; } .navbar { padding: 0 20px; } }
    </style>
</head>
<body>

<nav class="navbar">
    <a href="<?= htmlspecialchars(app_base_url()) ?>/" class="navbar-brand">
        <span style="display:flex;align-items:center;gap:14px;line-height:1;"><img src="<?= htmlspecialchars(app_base_url()) ?>/public/assets/SRMS_TRUST_LOGO.png" alt="SRMST Trust Logo" style="height:48px;width:auto;display:block;object-fit:contain;filter:drop-shadow(0 1px 3px rgba(0,0,0,.35));"><span style="font-size:22px;font-weight:800;letter-spacing:1px;color:#fff;text-shadow:0 1px 2px rgba(0,0,0,.35);white-space:nowrap;">SRMS EHEALTH</span></span>
    </a>
    <ul class="nav-links">
        <li><a href="/ehealth/index.php">HOME</a></li>
        <li><a href="<?= htmlspecialchars(app_base_url()) ?>/about.php">ABOUT</a></li>
        <li><a href="<?= htmlspecialchars(app_base_url()) ?>/contact.php">CONTACT</a></li>
    </ul>
</nav>

<div class="page-body">
    <div class="card">
        <div class="card-header">
            <div class="avatar">👨‍⚕️</div>
            <h1>Doctor Login</h1>
            <h3>(Ehealth Center)</h3>
            <p>Access your eHealth Center medical dashboard</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error">❌ <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label>Doctor ID / Mobile Number <span class="required">*</span></label>
                <div class="input-wrapper">
                    <input type="text" name="identifier"
                           value="<?= htmlspecialchars($_POST['identifier'] ?? '') ?>"
                           placeholder="e.g. DOC1234 or mobile number" required autocomplete="username">
                </div>
            </div>
            <div class="form-group">
                <label>Password <span class="required">*</span></label>
                <div class="input-wrapper">
                    <input type="password" name="password" id="pwInput"
                           placeholder="Enter your password" required autocomplete="current-password">
                    <button type="button" class="toggle-pw" onclick="togglePw()">👁</button>
                </div>
            </div>
            <button type="submit" class="btn-login">LOGIN TO DASHBOARD</button>
        </form>

        <hr class="divider">
        <div class="card-footer">
            Not registered yet? &nbsp;<a href="#">Contact Admin</a>
        </div>
    </div>
</div>

<script>
    function togglePw() {
        const i = document.getElementById('pwInput');
        const b = document.querySelector('.toggle-pw');
        if (i.type === 'password') { i.type = 'text';     b.textContent = '🙈'; }
        else                        { i.type = 'password'; b.textContent = '👁'; }
    }
</script>
</body>
</html>

