<?php
require_once __DIR__ . '/../../config/config.php';
start_session();

// Already logged in → go to dashboard
if (is_logged_in() && get_session_role() === 'patient') {
    header('Location: patient_dashboard.php');
    exit;
}

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $patient_id = strtoupper(trim($_POST['patientId'] ?? ''));
    $password   = $_POST['password'] ?? '';

    if (!$patient_id || !$password) {
        $error = 'Patient ID and password are required.';
    } else {
        $db   = db();
        $stmt = $db->prepare('SELECT id, patient_id, full_name, password FROM patients WHERE patient_id = ? LIMIT 1');
        $stmt->bind_param('s', $patient_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $isValid = false;

        if ($row) {
            // 1. Normal verify (bcrypt or plain-text via config.php)
            $isValid = verify_password($password, $row['password']);

            // 2. First-time fallback: default password == patient_id (e.g. P2687)
            if (!$isValid && strcasecmp($password, $row['patient_id']) === 0) {
                $newHash = hash_password($row['patient_id']);
                $up = $db->prepare('UPDATE patients SET password = ? WHERE id = ? LIMIT 1');
                $up->bind_param('si', $newHash, $row['id']);
                $up->execute();
                $up->close();
                $isValid = true;
            }
        }

        if ($isValid) {
            set_user_session([
                'id'   => $row['id'],
                'role' => 'patient',
                'name' => $row['full_name'],
            ]);
            $_SESSION['patient_id'] = $row['patient_id'];
            header('Location: patient_dashboard.php');
            exit;
        } else {
            $error = 'Invalid Patient ID or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Login - eHealth System</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap');
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'DM Sans', 'Segoe UI', sans-serif; background: linear-gradient(135deg, #1a6b4a 0%, #2d8f63 100%); min-height: 100vh; display: flex; flex-direction: column; }

        /* ── Navbar ── */
        .navbar { background: #134d36; padding: 0 40px; height: 56px; display: flex; align-items: center; justify-content: space-between; box-shadow: 0 2px 8px rgba(0,0,0,0.2); }
        .navbar-brand { display: flex; align-items: center; gap: 10px; text-decoration: none; color: white; }
        .brand-icon { width: 36px; height: 36px; background: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 18px; }
        .brand-name { font-size: 20px; font-weight: 700; letter-spacing: 1.5px; color: white; }
        .nav-links { display: flex; gap: 40px; list-style: none; }
        .nav-links a { color: white; text-decoration: none; font-size: 14px; font-weight: 500; opacity: .92; transition: opacity .2s; }
        .nav-links a:hover { opacity: 1; text-decoration: underline; }

        /* ── Page body ── */
        .page-body { flex: 1; display: flex; align-items: center; justify-content: center; padding: 40px 20px; }

        /* ── Card ── */
        .card { background: white; border-radius: 18px; box-shadow: 0 20px 60px rgba(0,0,0,0.25); width: 100%; max-width: 460px; padding: 48px 44px 40px; animation: fadeUp .4s ease both; }
        @keyframes fadeUp { from{opacity:0;transform:translateY(24px)} to{opacity:1;transform:translateY(0)} }
        .card-header { text-align: center; margin-bottom: 28px; }
        .avatar { width: 72px; height: 72px; background: linear-gradient(135deg, #1a6b4a, #2d8f63); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 34px; margin: 0 auto 16px; box-shadow: 0 6px 20px rgba(26,107,74,.35); }
        .card-header h1 { font-size: 26px; font-weight: 700; color: #1a1a2e; margin-bottom: 6px; }
        .card-header p  { font-size: 14px; color: #6c757d; }

        /* ── Info box ── */
        .info-box { background: linear-gradient(135deg, #d8f3dc, #f0faf2); border: 1.5px solid #b7e4c7; border-radius: 12px; padding: 16px 18px; margin-bottom: 22px; font-size: 13px; color: #1b4332; line-height: 1.6; }
        .info-box-title { font-weight: 700; margin-bottom: 6px; display: flex; align-items: center; gap: 6px; }

        /* ── Alert ── */
        .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 22px; font-size: 14px; line-height: 1.5; }
        .alert-error   { background: #fdecea; color: #b71c1c; border: 1px solid #f5c6cb; }
        .alert-success { background: #e8f5e9; color: #1b5e20; border: 1px solid #c3e6cb; }

        /* ── Form ── */
        .form-group { margin-bottom: 22px; }
        .form-group label { display: block; font-size: 13px; font-weight: 600; color: #333; margin-bottom: 8px; }
        .required { color: #e74c3c; }
        .input-wrapper { position: relative; }
        .input-wrapper input { width: 100%; padding: 13px 16px; border: 2px solid #e0e0e0; border-radius: 10px; font-size: 15px; font-family: inherit; background: #f7f9ff; color: #1a1a2e; transition: border-color .25s, box-shadow .25s; outline: none; }
        .input-wrapper input:focus { border-color: #1a6b4a; background: white; box-shadow: 0 0 0 3px rgba(26,107,74,.12); }
        .input-wrapper input::placeholder { color: #aaa; }
        .toggle-password { position: absolute; right: 14px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; font-size: 18px; color: #888; line-height: 1; transition: color .2s; }
        .toggle-password:hover { color: #1a6b4a; }
        .forgot-row { text-align: right; margin-top: -10px; margin-bottom: 28px; }
        .forgot-row a { font-size: 13px; color: #1a6b4a; text-decoration: none; font-weight: 500; }
        .forgot-row a:hover { text-decoration: underline; }

        /* ── Button ── */
        .btn-login { width: 100%; padding: 15px; background: linear-gradient(135deg, #1a6b4a, #2d8f63); color: white; border: none; border-radius: 10px; font-size: 15px; font-weight: 700; letter-spacing: .8px; text-transform: uppercase; cursor: pointer; box-shadow: 0 4px 16px rgba(26,107,74,.35); transition: opacity .2s, transform .15s; }
        .btn-login:hover { opacity: .93; transform: translateY(-1px); }
        .btn-login:disabled { opacity: .65; cursor: not-allowed; transform: none; }

        .divider { border: none; border-top: 1px solid #eee; margin: 28px 0 20px; }
        .card-footer { text-align: center; font-size: 14px; color: #6c757d; }
        .card-footer a { color: #1a6b4a; text-decoration: none; font-weight: 600; }
        .card-footer a:hover { text-decoration: underline; }

        .spinner-inline { display: inline-block; width: 16px; height: 16px; border: 2px solid rgba(255,255,255,0.4); border-top-color: white; border-radius: 50%; animation: spin .7s linear infinite; vertical-align: middle; margin-right: 8px; }
        @keyframes spin { to { transform: rotate(360deg); } }

        @media (max-width: 520px) { .card { padding: 36px 24px 32px; } .navbar { padding: 0 20px; } .nav-links { gap: 20px; } }
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
            <div class="avatar">🧑‍⚕️</div>
            <h1>Patient Login</h1>
            <p>Access your eHealth medical records</p>
        </div>

        <div class="info-box">
            <div class="info-box-title">📱 First time logging in?</div>
            Your Patient ID is your default password. It was given to you at registration (e.g. <strong>P202603001</strong>).
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error">❌ <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success">✅ <?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label for="patientId">Patient ID <span class="required">*</span></label>
                <div class="input-wrapper">
                    <input type="text" name="patientId" id="patientId"
                           value="<?= htmlspecialchars(strtoupper($_POST['patientId'] ?? '')) ?>"
                           placeholder="Enter your Patient ID (e.g. P202603001)"
                           maxlength="10" autocomplete="username" required
                           style="text-transform:uppercase">
                </div>
            </div>

            <div class="form-group">
                <label for="password">Password <span class="required">*</span></label>
                <div class="input-wrapper">
                    <input type="password" name="password" id="password"
                           placeholder="Enter your password"
                           autocomplete="current-password" required>
                    <button type="button" class="toggle-password" id="togglePassword">👁</button>
                </div>
            </div>

            <div class="forgot-row"><a href="#">Forgot Password?</a></div>

            <button type="submit" class="btn-login">LOGIN TO MY RECORDS</button>
        </form>

        <hr class="divider">
        <div class="card-footer">
            Need to register?&nbsp;<a href="/ehealth/public/executive/executive_dashboard.php">Visit eHealth Center</a>
        </div>

    </div>
</div>

<script>
    // Toggle password
    document.getElementById('togglePassword').addEventListener('click', function() {
        const p = document.getElementById('password');
        const isText = p.type === 'text';
        p.type = isText ? 'password' : 'text';
        this.textContent = isText ? '👁' : '🙈';
    });
    // Auto-uppercase patient ID
    document.getElementById('patientId').addEventListener('input', function() {
        this.value = this.value.toUpperCase();
    });
</script>

</body>
</html>

