<?php
require_once __DIR__ . '/../../config/config.php';
start_session();

if (is_logged_in() && get_session_role() === 'telestudio_doctor') {
    header('Location: telestudio_dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login    = trim($_POST['login']    ?? '');
    $password = trim($_POST['password'] ?? '');

    if (!$login || !$password) {
        $error = 'Please enter your Doctor ID / Mobile Number and password.';
    } else {
        $db = db();
        // Try matching by mobile_no OR doctor_id
        $stmt = $db->prepare(
            'SELECT doctor_id, full_name, password FROM telestudio_doctors
             WHERE mobile_no = ? OR doctor_id = ? LIMIT 1'
        );
        $stmt->bind_param('ss', $login, $login);
        $stmt->execute();
        $doctor = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$doctor) {
            $error = 'No account found with this Doctor ID or mobile number.';
        } elseif (!verify_password($password, $doctor['password'])) {
            $error = 'Incorrect password. Please try again.';
        } else {
            set_user_session([
                'id'   => $doctor['doctor_id'],
                'role' => 'telestudio_doctor',
                'name' => $doctor['full_name'],
            ]);
            header('Location: telestudio_dashboard.php');
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
    <title>Telestudio Doctor Login — eHealth</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

        html, body {
            height: 100%;
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #1a6b4a 0%, #2d8f63 100%);
        }

        body { display: flex; flex-direction: column; min-height: 100vh; }

        /* ── NAVBAR ── */
        .navbar {
            background: #134d36;
            height: 56px;
            padding: 0 32px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0;
        }
        .nav-left {
            display: flex; align-items: center; gap: 10px;
            text-decoration: none;
        }
        .nav-logo-circle {
            width: 34px; height: 34px;
            background: white; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 14px; font-weight: 800; color: #2e7d32;
        }
        .nav-brand-text {
            font-size: 16px; font-weight: 800;
            letter-spacing: 2.5px; color: white;
        }
        .nav-right { display: flex; gap: 36px; }
        .nav-right a {
            color: rgba(255,255,255,0.92);
            text-decoration: none;
            font-size: 13px; font-weight: 600;
            letter-spacing: 0.4px;
        }
        .nav-right a:hover { color: white; }

        /* ── PAGE CENTER ── */
        .page-center {
            flex: 1;
            display: flex; align-items: center; justify-content: center;
            padding: 48px 16px;
        }

        /* ── CARD ── */
        .card {
            background: white;
            border-radius: 18px;
            padding: 44px 40px 36px;
            width: 100%; max-width: 450px;
            box-shadow: 0 24px 64px rgba(0,0,0,0.18);
        }

        /* Avatar circle */
        .avatar {
            width: 72px; height: 72px;
            background: #2e7d32; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 30px;
            margin: 0 auto 20px;
            box-shadow: 0 4px 18px rgba(46,125,50,0.35);
        }

        .card-title {
            text-align: center;
            font-size: 22px; font-weight: 800;
            color: #111827; margin-bottom: 6px;
        }
        .card-sub {
            text-align: center;
            font-size: 13.5px; color: #9ca3af;
            margin-bottom: 16px;
        }

        /* Badge */
        .badge-wrap { display: flex; justify-content: center; margin-bottom: 28px; }
        .badge {
            display: inline-flex; align-items: center; gap: 6px;
            background: #f0fdf4; color: #2e7d32;
            border: 1.5px solid #bbf7d0;
            padding: 5px 16px; border-radius: 20px;
            font-size: 12.5px; font-weight: 600;
        }

        /* Error alert */
        .alert-error {
            background: #fef2f2; border: 1px solid #fecaca;
            border-radius: 10px; padding: 11px 14px;
            margin-bottom: 20px;
            display: flex; align-items: center; gap: 8px;
            font-size: 13px; color: #dc2626; font-weight: 500;
            animation: shake 0.4s ease;
        }
        @keyframes shake {
            0%,100%{transform:translateX(0);}
            25%{transform:translateX(-5px);}
            75%{transform:translateX(5px);}
        }

        /* Form fields */
        .field { margin-bottom: 18px; }
        .field-label {
            display: block; font-size: 13px; font-weight: 600;
            color: #374151; margin-bottom: 7px;
        }
        .field-label .req { color: #dc2626; margin-left: 2px; }

        .input-wrap { position: relative; }
        .input {
            width: 100%;
            padding: 12px 44px 12px 14px;
            border: 1.5px solid #e5e7eb;
            border-radius: 10px;
            font-size: 14px; font-family: 'Inter', sans-serif;
            color: #111827; background: #f9fafb;
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s, background 0.2s;
        }
        .input::placeholder { color: #9ca3af; }
        .input:focus {
            border-color: #2e7d32; background: white;
            box-shadow: 0 0 0 3px rgba(46,125,50,0.1);
        }

        .eye-btn {
            position: absolute; right: 12px; top: 50%;
            transform: translateY(-50%);
            background: none; border: none; cursor: pointer;
            color: #9ca3af; display: flex; align-items: center;
            padding: 2px; transition: color 0.2s;
        }
        .eye-btn:hover { color: #6b7280; }

        /* Forgot row */
        .forgot-row {
            display: flex; justify-content: flex-end;
            margin-top: -8px; margin-bottom: 22px;
        }
        .forgot-link {
            font-size: 12.5px; font-weight: 600;
            color: #2e7d32; text-decoration: none;
        }
        .forgot-link:hover { text-decoration: underline; }

        /* Submit button */
        .btn-submit {
            width: 100%; padding: 14px;
            background: #2e7d32; color: white;
            border: none; border-radius: 10px;
            font-size: 13.5px; font-weight: 700;
            font-family: 'Inter', sans-serif;
            letter-spacing: 1.5px; text-transform: uppercase;
            cursor: pointer;
            box-shadow: 0 4px 14px rgba(46,125,50,0.3);
            transition: background 0.2s, transform 0.15s, box-shadow 0.2s;
        }
        .btn-submit:hover {
            background: #1b5e20;
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(46,125,50,0.4);
        }
        .btn-submit:active { transform: translateY(0); }
        .btn-submit:disabled { opacity: 0.65; cursor: not-allowed; transform: none; }

        /* Footer */
        .card-footer {
            text-align: center; margin-top: 22px;
            font-size: 13px; color: #9ca3af;
        }
        .card-footer a { color: #2e7d32; font-weight: 700; text-decoration: none; }
        .card-footer a:hover { text-decoration: underline; }

        @media (max-width: 480px) {
            .navbar { padding: 0 16px; }
            .nav-right { gap: 20px; }
            .card { padding: 32px 20px 28px; }
        }
    </style>
</head>
<body>

<nav class="navbar">
    <a href="<?= htmlspecialchars(app_base_url()) ?>/" class="nav-left">
        <span style="display:flex;align-items:center;gap:14px;line-height:1;"><img src="<?= htmlspecialchars(app_base_url()) ?>/public/assets/SRMS_TRUST_LOGO.png" alt="SRMST Trust Logo" style="height:48px;width:auto;display:block;object-fit:contain;filter:drop-shadow(0 1px 3px rgba(0,0,0,.35));"><span style="font-size:22px;font-weight:800;letter-spacing:1px;color:#fff;text-shadow:0 1px 2px rgba(0,0,0,.35);white-space:nowrap;">SRMS EHEALTH</span></span>
    </a>
    <div class="nav-right">
        <a href="<?= htmlspecialchars(app_base_url()) ?>/index.php">HOME</a>
        <a href="<?= htmlspecialchars(app_base_url()) ?>/about.php">ABOUT</a>
        <a href="<?= htmlspecialchars(app_base_url()) ?>/contact.php">CONTACT</a>
    </div>
</nav>

<div class="page-center">
    <div class="card">

        <div class="avatar">&#127973;</div>
        <div class="card-title">Telestudio Doctor Login</div>
        <div class="card-sub">Access your specialist teleconsultation dashboard</div>

        <div class="badge-wrap">
            <div class="badge">&#127973; Hospital Telestudio</div>
        </div>

        <?php if ($error): ?>
        <div class="alert-error">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <form method="POST" id="loginForm">

            <div class="field">
                <label class="field-label" for="login">
                    Doctor ID / Mobile Number <span class="req">*</span>
                </label>
                <div class="input-wrap">
                    <input
                        type="text"
                        id="login"
                        name="login"
                        class="input"
                        placeholder="e.g. TSD1234 or mobile number"
                        autocomplete="username"
                        required
                        value="<?= htmlspecialchars($_POST['login'] ?? '') ?>">
                </div>
            </div>

            <div class="field">
                <label class="field-label" for="password">
                    Password <span class="req">*</span>
                </label>
                <div class="input-wrap">
                    <input
                        type="password"
                        id="password"
                        name="password"
                        class="input"
                        placeholder="Enter your password"
                        autocomplete="current-password"
                        required>
                    <button type="button" class="eye-btn" id="eyeBtn">
                        <svg id="eyeIcon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                            <circle cx="12" cy="12" r="3"/>
                        </svg>
                    </button>
                </div>
            </div>

            <div class="forgot-row">
                <a href="#" class="forgot-link">Forgot Password?</a>
            </div>

            <button type="submit" class="btn-submit" id="submitBtn">
                Login to Dashboard
            </button>

        </form>

        <div class="card-footer">
            Not registered yet? <a href="#">Register as Telestudio Doctor</a>
        </div>

    </div>
</div>

<script>
// Eye toggle
const pwdInput = document.getElementById('password');
const eyeBtn   = document.getElementById('eyeBtn');
const eyeOpen  = `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>`;
const eyeOff   = `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>`;
eyeBtn.addEventListener('click', () => {
    const show = pwdInput.type === 'password';
    pwdInput.type = show ? 'text' : 'password';
    eyeBtn.innerHTML = show ? eyeOff : eyeOpen;
});

// Loading state
document.getElementById('loginForm').addEventListener('submit', function() {
    const btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.textContent = 'Signing in...';
});
</script>
</body>
</html>
