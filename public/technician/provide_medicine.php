<?php
require_once __DIR__ . '/../../config/config.php';
require_role('technician', 'technician_login.php');

if (isset($_POST['logout'])) {
    logout_user();
    header('Location: technician_login.php');
    exit;
}

$tech_name = htmlspecialchars(get_session_name() ?: 'Technician');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Provide Medicine - Technician</title>
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Segoe UI',sans-serif; background:#f5f8fb; min-height:100vh; color:#1f2937; }
        .navbar {
            background:#2e7d32; height:58px; padding:0 32px;
            display:flex; align-items:center; justify-content:space-between;
            box-shadow:0 2px 10px rgba(0,0,0,0.18);
        }
        .navbar a { color:#fff; text-decoration:none; font-weight:700; }
        .wrap { max-width:900px; margin:0 auto; padding:28px 20px 60px; }
        .title { margin:12px 0 20px; }
        .title h1 { font-size:26px; color:#1b5e20; margin-bottom:6px; }
        .title p { font-size:14px; color:#4b5563; }
        .card {
            background:#fff; border-radius:16px; padding:26px 24px;
            box-shadow:0 10px 30px rgba(0,0,0,0.08);
        }
        .card h2 { font-size:18px; margin-bottom:10px; color:#111827; }
        .card p { font-size:14px; color:#4b5563; line-height:1.6; }
        .actions { margin-top:16px; display:flex; gap:12px; flex-wrap:wrap; }
        .btn {
            padding:10px 16px; border-radius:8px; border:1.5px solid #2e7d32;
            background:#2e7d32; color:#fff; font-weight:700; font-size:13px; cursor:pointer;
        }
        .btn.secondary { background:transparent; color:#2e7d32; }
    </style>
</head>
<body>
<nav class="navbar">
    <a href="<?= htmlspecialchars(app_base_url()) ?>/">SRMS EHEALTH</a>
    <div style="display:flex;gap:16px;align-items:center;">
        <a href="technician_dashboard.php">Back to Dashboard</a>
        <a href="<?= htmlspecialchars(app_base_url()) ?>/about.php">ABOUT</a>
        <a href="<?= htmlspecialchars(app_base_url()) ?>/contact.php">CONTACT</a>
    </div>
    <form method="POST" style="display:inline">
        <button type="submit" name="logout" class="btn secondary" style="border-color:#fff;color:#fff">Logout</button>
    </form>
</nav>

<div class="wrap">
    <div class="title">
        <h1>Provide Medicine</h1>
        <p>Welcome, <?= $tech_name ?>. Use this area to manage medicine dispensing tasks.</p>
    </div>

    <div class="card">
        <h2>Module Ready</h2>
        <p>
            This page is set up for medicine dispensing workflows. If you want patient lookup,
            prescription validation, stock updates, or billing, tell me the fields you need and I will build it.
        </p>
        <div class="actions">
            <a class="btn" href="technician_dashboard.php">Go to Dashboard</a>
            <a class="btn secondary" href="/ehealth/index.php">Home</a>
        </div>
    </div>
</div>
</body>
</html>
