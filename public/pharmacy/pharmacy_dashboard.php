<?php
require_once __DIR__ . '/../../config/config.php';
require_role('pharmacy_staff', 'pharmacy_login.php');

if (isset($_POST['logout'])) {
    logout_user();
    header('Location: pharmacy_login.php');
    exit;
}

$pharm_name = htmlspecialchars(get_session_name() ?: 'Pharmacy Staff');
$pharm_id   = htmlspecialchars($_SESSION['pharmacy_id'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pharmacy Dashboard - eHealth</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', sans-serif; background: #e6f4ea; min-height: 100vh; color: #1b3a1e; }
        .navbar {
            background: #2e7d32; height: 58px; padding: 0 32px;
            display: flex; align-items: center; justify-content: space-between;
            box-shadow: 0 2px 10px rgba(0,0,0,0.18);
        }
        .navbar-brand { display: flex; align-items: center; gap: 10px; text-decoration: none; color: white; }
        .brand-name { font-size: 20px; font-weight: 800; letter-spacing: 1.5px; }
        .nav-right { display: flex; align-items: center; gap: 16px; }
        .nav-right a { color: white; text-decoration: none; font-size: 12px; font-weight: 700; }
        .pill { background: rgba(255,255,255,0.15); color: white; padding: 6px 14px; border-radius: 18px; font-size: 12px; font-weight: 600; }
        .logout-btn { border: 2px solid white; background: transparent; color: white; padding: 6px 16px; border-radius: 18px; font-size: 12px; font-weight: 700; cursor: pointer; }
        .hero { text-align: center; padding: 40px 20px 20px; }
        .hero h1 { font-size: 30px; font-weight: 800; color: #1b5e20; margin-bottom: 6px; }
        .hero p { font-size: 14px; color: #3d6b41; }
        .dashboard { max-width: 900px; margin: 0 auto; padding: 20px 20px 60px; }
        .cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 22px; }
        .card { background: white; border-radius: 18px; padding: 32px 26px; text-align: center; text-decoration: none; color: inherit; box-shadow: 0 4px 18px rgba(46,125,50,0.12); transition: transform .2s, box-shadow .2s; }
        .card:hover { transform: translateY(-4px); box-shadow: 0 10px 28px rgba(46,125,50,0.2); }
        .card-icon { width: 70px; height: 70px; border-radius: 50%; background: linear-gradient(135deg, #2e7d32, #43a047); display: flex; align-items: center; justify-content: center; font-size: 28px; color: white; margin: 0 auto 16px; }
        .card h2 { font-size: 18px; font-weight: 700; margin-bottom: 8px; }
        .card p { font-size: 13px; color: #6b7f6d; line-height: 1.5; }
        @media (max-width: 600px) { .navbar { padding: 0 16px; } }
    </style>
</head>
<body>
<nav class="navbar">
    <a href="<?= htmlspecialchars(app_base_url()) ?>/" class="navbar-brand">
        <span style="display:flex;align-items:center;gap:14px;line-height:1;"><img src="<?= htmlspecialchars(app_base_url()) ?>/public/assets/SRMS_TRUST_LOGO.png" alt="SRMST Trust Logo" style="height:42px;width:auto;display:block;object-fit:contain;filter:drop-shadow(0 1px 3px rgba(0,0,0,.35));"><span class="brand-name">SRMS EHEALTH</span></span>
    </a>
    <div class="nav-right">
        <a href="<?= htmlspecialchars(app_base_url()) ?>/about.php">ABOUT</a>
        <a href="<?= htmlspecialchars(app_base_url()) ?>/contact.php">CONTACT</a>
        <div class="pill">ID: <?= $pharm_id ?></div>
        <form method="POST" style="display:inline">
            <button type="submit" name="logout" class="logout-btn">LOGOUT</button>
        </form>
    </div>
</nav>

<div class="hero">
    <h1>Welcome, <?= $pharm_name ?></h1>
    <p>Pharmacy Staff Dashboard</p>
</div>

<div class="dashboard">
    <div class="cards">
        <a href="provide_medicine.php" class="card">
            <div class="card-icon">💊</div>
            <h2>Provide Medicine</h2>
            <p>Prepare and dispense medicines based on approved prescriptions.</p>
        </a>
        <a href="change_password.php" class="card">
            <div class="card-icon">P</div>
            <h2>Change Password</h2>
            <p>Update your account password to keep your profile secure.</p>
        </a>
    </div>
</div>
</body>
</html>
