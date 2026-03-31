<?php
require_once __DIR__ . '/../../config/config.php';
start_session();

// Already logged in → go to dashboard
if (is_logged_in() && get_session_role() === 'admin') {
    header('Location: admin_dashboard.php');
    exit;
}

$error   = '';
$success = '';

// ── Handle login form POST ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim($_POST['identifier'] ?? '');
    $password   = $_POST['password'] ?? '';

    if ($identifier === '' || $password === '') {
        $error = 'Username/email and password are required.';
    } else {
        $db   = db();
        $stmt = $db->prepare('SELECT * FROM admins WHERE username = ? OR email = ? LIMIT 1');
        $stmt->bind_param('ss', $identifier, $identifier);
        $stmt->execute();
        $admin = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$admin || !verify_password($password, $admin['password'])) {
            $error = 'Invalid username/email or password.';
        } else {
            set_user_session([
                'id'   => $admin['id'],
                'role' => 'admin',
                'name' => $admin['name'],
            ]);
            header('Location: admin_dashboard.php');
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
    <title>Admin Login - eHealth</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        header {
            background: linear-gradient(to right, #4CAF50, #45a049);
            padding: 15px 50px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        .logo { display: flex; align-items: center; color: white; font-size: 24px; font-weight: bold; text-decoration: none; }
        .logo-icon {
            width: 40px; height: 40px; background: white; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            margin-right: 10px; color: #4CAF50; font-size: 20px;
        }
        nav { display: flex; gap: 30px; }
        nav a { color: white; text-decoration: none; font-size: 16px; font-weight: 500; }
        nav a:hover { opacity: 0.8; }

        .container { flex: 1; display: flex; justify-content: center; align-items: center; padding: 20px; }

        .login-card {
            background: white; border-radius: 20px; padding: 40px;
            width: 100%; max-width: 450px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        }
        .login-header { text-align: center; margin-bottom: 30px; }
        .admin-icon {
            width: 80px; height: 80px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 50%; margin: 0 auto 15px;
            display: flex; align-items: center; justify-content: center; font-size: 40px;
        }
        .login-header h1 { color: #2c3e50; font-size: 28px; margin-bottom: 8px; }
        .login-header p  { color: #7f8c8d; font-size: 15px; }

        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; color: #2c3e50; font-weight: 500; margin-bottom: 8px; font-size: 14px; }
        .required { color: #e74c3c; }
        .form-group input {
            width: 100%; padding: 13px 16px; border: 1px solid #ddd;
            border-radius: 8px; font-size: 15px; transition: border-color 0.3s;
        }
        .form-group input:focus { outline: none; border-color: #667eea; }

        .password-wrap { position: relative; }
        .toggle-pw {
            position: absolute; right: 14px; top: 50%; transform: translateY(-50%);
            cursor: pointer; user-select: none; font-size: 18px;
        }

        .alert {
            padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 14px;
        }
        .alert-error   { background: #ffe6e6; color: #d32f2f; border-left: 4px solid #d32f2f; }
        .alert-success { background: #e6ffe6; color: #2e7d32; border-left: 4px solid #2e7d32; }

        .login-btn {
            width: 100%; padding: 14px;
            background: linear-gradient(to right, #667eea, #764ba2);
            color: white; border: none; border-radius: 8px;
            font-size: 16px; font-weight: 600; cursor: pointer;
            margin-top: 10px; text-transform: uppercase; letter-spacing: 0.5px;
            transition: opacity 0.2s, transform 0.2s;
        }
        .login-btn:hover { opacity: 0.92; transform: translateY(-1px); }

        .back-link { text-align: center; margin-top: 20px; }
        .back-link a { color: #667eea; text-decoration: none; font-size: 14px; }
        .back-link a:hover { text-decoration: underline; }

        @media (max-width: 500px) {
            header { padding: 15px 20px; }
            .login-card { padding: 30px 20px; }
        }
    </style>
</head>
<body>

<header>
    <a href="<?= htmlspecialchars(app_base_url()) ?>/" class="logo">
        <span style="display:flex;align-items:center;gap:14px;line-height:1;"><img src="<?= htmlspecialchars(app_base_url()) ?>/public/assets/SRMS_TRUST_LOGO.png" alt="SRMST Trust Logo" style="height:48px;width:auto;display:block;object-fit:contain;filter:drop-shadow(0 1px 3px rgba(0,0,0,.35));"><span style="font-size:22px;font-weight:800;letter-spacing:1px;color:#fff;text-shadow:0 1px 2px rgba(0,0,0,.35);white-space:nowrap;">SRMS EHEALTH</span></span>
    </a>
    <nav>
        <a href="/ehealth/index.php">HOME</a>
        <a href="<?= htmlspecialchars(app_base_url()) ?>/about.php">ABOUT</a>
        <a href="<?= htmlspecialchars(app_base_url()) ?>/contact.php">CONTACT</a>
    </nav>
</header>

<div class="container">
    <div class="login-card">

        <div class="login-header">
            <div class="admin-icon">🔐</div>
            <h1>Admin Login</h1>
            <p>Access administrative dashboard</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error">❌ <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success">✅ <?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label>Username / Email <span class="required">*</span></label>
                <input type="text" name="identifier"
                       value="<?= htmlspecialchars($_POST['identifier'] ?? '') ?>"
                       placeholder="admin or admin@ehealth.com" required autocomplete="username">
            </div>

            <div class="form-group">
                <label>Password <span class="required">*</span></label>
                <div class="password-wrap">
                    <input type="password" name="password" id="passwordInput"
                           placeholder="Enter your password" required autocomplete="current-password">
                    <span class="toggle-pw" onclick="togglePassword()">👁️</span>
                </div>
            </div>

            <button type="submit" class="login-btn">LOGIN TO DASHBOARD</button>
        </form>

        <div class="back-link">
            <a href="/">← Back to Home</a>
        </div>

    </div>
</div>

<script>
    function togglePassword() {
        const inp = document.getElementById('passwordInput');
        const btn = document.querySelector('.toggle-pw');
        if (inp.type === 'password') { inp.type = 'text';     btn.textContent = '🙈'; }
        else                         { inp.type = 'password'; btn.textContent = '👁️'; }
    }
</script>
</body>
</html>

