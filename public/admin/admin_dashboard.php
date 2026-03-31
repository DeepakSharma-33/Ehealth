<?php
require_once __DIR__ . '/../../config/config.php';
require_role('admin', 'admin_login.php');

// ── Handle logout ────────────────────────────────────────────
if (isset($_POST['logout'])) {
    logout_user();
    header('Location: admin_login.php');
    exit;
}

$admin_name = htmlspecialchars(get_session_name() ?: 'Administrator');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - eHealth</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: linear-gradient(135deg, #a8e6cf 0%, #88d8b0 100%); min-height: 100vh; }
        header { background: linear-gradient(to right, #4CAF50, #45a049); padding: 15px 50px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .logo { display: flex; align-items: center; color: white; font-size: 24px; font-weight: bold; text-decoration: none; }
        .logo-icon { width: 40px; height: 40px; background: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin-right: 10px; color: #4CAF50; font-size: 20px; }
        nav { display: flex; gap: 25px; align-items: center; }
        nav a { color: white; text-decoration: none; font-size: 15px; font-weight: 500; }
        nav a:hover { opacity: 0.8; }
        .logout-btn { background: rgba(231,76,60,0.2); color: white; border: 2px solid white; padding: 8px 20px; border-radius: 20px; font-size: 14px; font-weight: 600; cursor: pointer; transition: background 0.3s; }
        .logout-btn:hover { background: rgba(231,76,60,0.4); }
        .container { max-width: 1300px; margin: 0 auto; padding: 40px 20px; }
        .welcome-section { text-align: center; margin-bottom: 35px; color: #2c5f2d; }
        .welcome-section h1 { font-size: 42px; font-weight: 700; margin-bottom: 8px; }
        .welcome-section p { font-size: 18px; color: #4a5f4a; }
        .section-title { text-align: center; font-size: 34px; color: #2c5f2d; margin-bottom: 35px; font-weight: 700; }
        .cards-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 25px; }
        .card { background: white; border-radius: 18px; padding: 35px 25px; text-align: center; box-shadow: 0 4px 15px rgba(0,0,0,0.09); transition: transform 0.25s, box-shadow 0.25s; cursor: pointer; text-decoration: none; display: block; color: inherit; }
        .card:hover { transform: translateY(-5px); box-shadow: 0 8px 25px rgba(0,0,0,0.14); }
        .card.disabled { opacity: 0.75; cursor: default; }
        .card.disabled:hover { transform: none; box-shadow: 0 4px 15px rgba(0,0,0,0.09); }
        .card-icon { width: 90px; height: 90px; background: linear-gradient(135deg, #4CAF50, #45a049); border-radius: 50%; margin: 0 auto 20px; display: flex; align-items: center; justify-content: center; font-size: 44px; }
        .card h3 { font-size: 22px; color: #2c3e50; font-weight: 600; margin-bottom: 5px; }
        .card .sub { font-size: 17px; font-weight: 700; color: #2c3e50; margin-bottom: 12px; }
        .card p { font-size: 14px; color: #7f8c8d; line-height: 1.6; margin-bottom: 22px; }
        .card-btn { background: linear-gradient(to right, #4CAF50, #45a049); color: white; border: none; padding: 12px 35px; border-radius: 22px; font-size: 15px; font-weight: 600; cursor: pointer; display: inline-block; transition: transform 0.2s, box-shadow 0.2s; }
        .card-btn:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(76,175,80,0.4); }
        .card-btn:disabled { opacity: 0.55; cursor: not-allowed; transform: none; box-shadow: none; }
        .badge { display: inline-block; background: #ffc107; color: #333; padding: 3px 10px; border-radius: 10px; font-size: 11px; font-weight: 600; margin-left: 6px; vertical-align: middle; }
        .chatbot { position: fixed; bottom: 28px; right: 28px; z-index: 100; }
        .chat-bubble { background: white; padding: 14px 18px; border-radius: 16px; box-shadow: 0 4px 15px rgba(0,0,0,0.18); margin-bottom: 12px; font-size: 14px; display: none; max-width: 190px; }
        .chat-bubble.show { display: block; animation: popUp 0.3s ease; }
        @keyframes popUp { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
        .chat-icon { width: 56px; height: 56px; background: linear-gradient(135deg, #667eea, #764ba2); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 28px; cursor: pointer; box-shadow: 0 4px 14px rgba(0,0,0,0.25); margin-left: auto; transition: transform 0.3s; }
        .chat-icon:hover { transform: scale(1.1); }
        @media (max-width: 768px) { header { padding: 15px 20px; flex-direction: column; gap: 12px; } .welcome-section h1 { font-size: 30px; } .cards-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>

<header>
    <a href="<?= htmlspecialchars(app_base_url()) ?>/" class="logo"><span style="display:flex;align-items:center;gap:14px;line-height:1;"><img src="<?= htmlspecialchars(app_base_url()) ?>/public/assets/SRMS_TRUST_LOGO.png" alt="SRMST Trust Logo" style="height:48px;width:auto;display:block;object-fit:contain;filter:drop-shadow(0 1px 3px rgba(0,0,0,.35));"><span style="font-size:22px;font-weight:800;letter-spacing:1px;color:#fff;text-shadow:0 1px 2px rgba(0,0,0,.35);white-space:nowrap;">SRMS EHEALTH</span></span></a>
    <nav>
        <a href="/ehealth/index.php">HOME</a>
        <a href="<?= htmlspecialchars(app_base_url()) ?>/about.php">ABOUT</a>
        <a href="<?= htmlspecialchars(app_base_url()) ?>/contact.php">CONTACT</a>
        <form method="POST" style="display:inline">
            <button type="submit" name="logout" class="logout-btn">LOGOUT</button>
        </form>
    </nav>
</header>

<div class="container">
    <div class="welcome-section">
        <h1>Welcome, <?= $admin_name ?> 👋</h1>
        <p>Manage your eHealth platform from here</p>
    </div>

    <h2 class="section-title">Admin Dashboard</h2>

    <div class="cards-grid">
        <a href="../ehealth_center_doctor/doctor_registration.php" class="card">
            <div class="card-icon">👨‍⚕️</div><h3>Add Doctor</h3><div class="sub">Ehealth Center</div>
            <p>Register new medical professionals to the system and manage their profiles</p>
            <span class="card-btn">Add Doctor</span>
        </a>
        <a href="/ehealth/public/telestudio_doctor/telestudio_doctor_registration.php" class="card">
            <div class="card-icon">👨‍⚕️</div><h3>Add Doctor</h3><div class="sub">Telestudio</div>
            <p>Register specialist telestudio doctors and manage their profiles</p>
            <span class="card-btn">Add Doctor</span>
        </a>
        <a href="../executive/executive_registration.php" class="card">
            <div class="card-icon">👔</div><h3>Add Executive</h3><div class="sub">Ehealth Center</div>
            <p>Onboard administrative staff and management members to the platform</p>
            <span class="card-btn">Add Executive</span>
        </a>
        <a href="view_analysis.php" class="card">
            <div class="card-icon">🧪</div><h3>View Analysis</h3>
            <p>Access detailed healthcare statistics, insights, and platform analytics reports</p>
            <span class="card-btn">View Analysis</span>
        </a>
        <a href="website_flow.php" class="card">
            <div class="card-icon">&#128506;</div><h3>Website Flow</h3>
            <p>Open a visual website flow map that shows the full patient, staff, doctor, telemedicine, pharmacy, and admin journey.</p>
            <span class="card-btn">Open Flow</span>
        </a>
        <a href="../nursing_staff/nursing_registration.php" class="card">
            <div class="card-icon">N</div><h3>Nursing Staff</h3><div class="sub">Ehealth Center</div>
            <p>Register nursing staff members and manage their profiles</p>
            <span class="card-btn">Register Nursing</span>
        </a>
        <a href="../technician/technician_registration.php" class="card">
            <div class="card-icon">🔧</div><h3>Add Technician</h3>
            <p>Onboard medical technicians and laboratory staff to the system</p>
            <span class="card-btn">Add Technician</span>
        </a>
        <a href="../pharmacy/pharmacy_registration.php" class="card">
            <div class="card-icon">💊</div><h3>Add Pharmacy Staff</h3>
            <p>Register pharmacy staff and manage medicine dispensing operations</p>
            <span class="card-btn">Add Pharmacy</span>
        </a>
        <a href="add_admin.php" class="card">
            <div class="card-icon">🔐</div><h3>Add Admin</h3>
            <p>Create new administrator accounts and manage system access permissions</p>
            <span class="card-btn">Add Admin</span>
        </a>
        <a href="change_password.php" class="card">
            <div class="card-icon">🔑</div><h3>Change Password</h3>
            <p>Update your account password and manage security settings</p>
            <span class="card-btn">Change Password</span>
        </a>
    </div>
</div>

<div class="chatbot">
    <div class="chat-bubble" id="chatBubble">Hi Admin! Need any help? 😊</div>
    <div class="chat-icon" onclick="toggleChat()">🤖</div>
</div>

<script>
    setTimeout(() => {
        const b = document.getElementById('chatBubble');
        b.classList.add('show');
        setTimeout(() => b.classList.remove('show'), 5000);
    }, 1500);
    function toggleChat() {
        const b = document.getElementById('chatBubble');
        b.classList.toggle('show');
        if (b.classList.contains('show')) setTimeout(() => b.classList.remove('show'), 5000);
    }
    window.onpageshow = (e) => { if (e.persisted) window.location.reload(); };
</script>
</body>
</html>

