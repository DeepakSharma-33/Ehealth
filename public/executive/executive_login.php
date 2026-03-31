<?php
require_once __DIR__ . '/../../config/config.php';
start_session();

// Already logged in as executive → go to dashboard
if (is_logged_in() && get_session_role() === 'executive') {
    header('Location: executive_dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login    = trim($_POST['executiveId'] ?? '');
    $password = $_POST['password'] ?? '';   // do NOT trim — passwords may have spaces

    if (!$login || !$password) {
        $error = 'Executive ID / Mobile and password are required.';
    } else {
        $db = db();
        $st = $db->prepare(
            'SELECT id, executive_id, full_name, password
             FROM executives
             WHERE executive_id = ? OR mobile_no = ?
             LIMIT 1'
        );
        $st->bind_param('ss', $login, $login);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();

        $isValid = false;

        if ($row) {
            // 1. Normal verify (bcrypt or plain-text legacy — handled by config.php)
            $isValid = verify_password($password, $row['password']);

            // 2. First-time fallback: executive types their Executive ID as password.
            //    Registration stored a random hash they may never have seen.
            //    Accept Executive ID as password and re-hash it for future logins.
            if (!$isValid && strcasecmp($password, $row['executive_id']) === 0) {
                $newHash = hash_password($row['executive_id']);
                $up = $db->prepare('UPDATE executives SET password = ? WHERE id = ? LIMIT 1');
                $up->bind_param('si', $newHash, $row['id']);
                $up->execute();
                $up->close();
                $isValid = true;
            }
        }

        if ($isValid) {
            // Use set_user_session() from config so is_logged_in() works correctly
            set_user_session([
                'id'   => $row['id'],          // numeric DB id — is_logged_in() needs this non-empty
                'role' => 'executive',
                'name' => $row['full_name'],
            ]);
            // Store executive_id string separately for dashboard display / business logic
            $_SESSION['executive_id'] = $row['executive_id'];

            header('Location: executive_dashboard.php');
            exit;
        } else {
            $error = 'Invalid Executive ID / Mobile or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Executive Login - eHealth</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap');
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'DM Sans','Segoe UI',sans-serif; background:linear-gradient(135deg,#1a6b4a 0%,#2d8f63 100%); min-height:100vh; display:flex; flex-direction:column; }
        .navbar { background:#134d36; padding:0 40px; height:56px; display:flex; align-items:center; justify-content:space-between; box-shadow:0 2px 8px rgba(0,0,0,0.2); }
        .navbar-brand { display:flex; align-items:center; gap:10px; text-decoration:none; color:white; }
        .brand-icon { width:36px; height:36px; background:white; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:18px; }
        .brand-name { font-size:20px; font-weight:700; letter-spacing:1.5px; }
        .nav-links { display:flex; gap:40px; list-style:none; }
        .nav-links a { color:white; text-decoration:none; font-size:14px; font-weight:500; opacity:.92; }
        .nav-links a:hover { opacity:1; text-decoration:underline; }
        .page-body { flex:1; display:flex; align-items:center; justify-content:center; padding:40px 20px; }
        .card { background:white; border-radius:18px; box-shadow:0 20px 60px rgba(0,0,0,0.25); width:100%; max-width:460px; padding:48px 44px 40px; animation:fadeUp .4s ease both; }
        @keyframes fadeUp { from{opacity:0;transform:translateY(24px)} to{opacity:1;transform:translateY(0)} }
        .card-header { text-align:center; margin-bottom:28px; }
        .avatar { width:72px; height:72px; background:linear-gradient(135deg,#2e7d32,#43a047); border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:34px; margin:0 auto 16px; box-shadow:0 6px 20px rgba(46,125,50,.35); }
        .card-header h1 { font-size:26px; font-weight:700; color:#1a1a2e; margin-bottom:6px; }
        .card-header p { font-size:14px; color:#6c757d; }
        .alert { padding:12px 16px; border-radius:8px; margin-bottom:18px; font-size:14px; line-height:1.5; }
        .alert-error { background:#fdecea; color:#b71c1c; border:1px solid #f5c6cb; }
        .alert-info  { background:#e8f5e9; color:#1b5e20; border:1px solid #a5d6a7; }
        .form-group { margin-bottom:22px; }
        .form-group label { display:block; font-size:13px; font-weight:600; color:#333; margin-bottom:8px; }
        .required { color:#e74c3c; }
        .input-wrapper { position:relative; }
        .input-wrapper input { width:100%; padding:13px 16px; border:2px solid #e0e0e0; border-radius:10px; font-size:15px; font-family:inherit; background:#f7f9ff; outline:none; transition:border-color .25s; color:#1a1a2e; }
        .input-wrapper input:focus { border-color:#2e7d32; background:white; box-shadow:0 0 0 3px rgba(46,125,50,.12); }
        .input-wrapper input::placeholder { color:#aaa; }
        .toggle-password { position:absolute; right:14px; top:50%; transform:translateY(-50%); background:none; border:none; cursor:pointer; font-size:18px; color:#888; line-height:1; }
        .toggle-password:hover { color:#2e7d32; }
        .forgot-row { text-align:right; margin-top:-10px; margin-bottom:28px; }
        .forgot-row a { font-size:13px; color:#2e7d32; text-decoration:none; font-weight:500; }
        .btn-login { width:100%; padding:15px; background:linear-gradient(135deg,#2e7d32,#43a047); color:white; border:none; border-radius:10px; font-size:15px; font-weight:700; letter-spacing:.8px; text-transform:uppercase; cursor:pointer; box-shadow:0 4px 16px rgba(46,125,50,.35); transition:opacity .2s,transform .15s; }
        .btn-login:hover { opacity:.93; transform:translateY(-1px); }
        hr.divider { border:none; border-top:1px solid #eee; margin:28px 0 20px; }
        .card-footer { text-align:center; font-size:14px; color:#6c757d; }
        .card-footer a { color:#2e7d32; text-decoration:none; font-weight:600; }
        @media(max-width:520px){ .card{padding:36px 24px 32px;} .navbar{padding:0 20px;} .nav-links{gap:20px;} }
    </style>
</head>
<body>

<nav class="navbar">
    <a href="<?= htmlspecialchars(app_base_url()) ?>/" class="navbar-brand">
        <span style="display:flex;align-items:center;gap:14px;line-height:1;"><img src="<?= htmlspecialchars(app_base_url()) ?>/public/assets/SRMS_TRUST_LOGO.png" alt="SRMST Trust Logo" style="height:48px;width:auto;display:block;object-fit:contain;filter:drop-shadow(0 1px 3px rgba(0,0,0,.35));"><span style="font-size:22px;font-weight:800;letter-spacing:1px;color:#fff;text-shadow:0 1px 2px rgba(0,0,0,.35);white-space:nowrap;">SRMS EHEALTH</span></span>
    </a>
    <ul class="nav-links">
        <li><a href="/ehealth/index.php">HOME</a></li>
        <li><a href="/ehealth/public/admin/admin_login.php">ADMIN</a></li>
            <li><a href="<?= htmlspecialchars(app_base_url()) ?>/about.php">ABOUT</a></li>
        <li><a href="<?= htmlspecialchars(app_base_url()) ?>/contact.php">CONTACT</a></li>
</ul>
</nav>

<div class="page-body">
    <div class="card">

        <div class="card-header">
            <div class="avatar">🧑‍💼</div>
            <h1>Executive Login</h1>
            <p>Access your eHealth executive dashboard</p>
        </div>

        <div class="alert alert-info">
            💡 <strong>First time logging in?</strong> Use your <strong>Executive ID</strong> as your password (e.g. <code>E202603001</code>)
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error">❌ <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label>Executive ID / Mobile Number <span class="required">*</span></label>
                <div class="input-wrapper">
                    <input type="text" name="executiveId"
                           value="<?= htmlspecialchars($_POST['executiveId'] ?? '') ?>"
                           placeholder="e.g. E202603001 or mobile number"
                           maxlength="15" required autocomplete="username">
                </div>
            </div>

            <div class="form-group">
                <label>Password <span class="required">*</span></label>
                <div class="input-wrapper">
                    <input type="password" name="password" id="password"
                           placeholder="Enter your password"
                           required autocomplete="current-password">
                    <button type="button" class="toggle-password" id="togglePassword" aria-label="Toggle password">👁</button>
                </div>
            </div>

            <div class="forgot-row"><a href="#">Forgot Password?</a></div>

            <button type="submit" class="btn-login">LOGIN TO DASHBOARD</button>
        </form>

        <hr class="divider">
        <div class="card-footer">Back to &nbsp;<a href="/ehealth/index.php">Home</a></div>

    </div>
</div>

<script>
    document.getElementById('togglePassword').addEventListener('click', function () {
        const p = document.getElementById('password');
        const isText = p.type === 'text';
        p.type = isText ? 'password' : 'text';
        this.textContent = isText ? '👁' : '🙈';
    });
</script>
</body>
</html>

