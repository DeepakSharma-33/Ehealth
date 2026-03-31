<?php
require_once __DIR__ . '/../../config/config.php';
require_role('doctor', 'doctor_login.php');

if (isset($_POST['logout'])) {
    logout_user();
    header('Location: doctor_login.php');
    exit;
}

$doctor_id   = get_session_id();
$doctor_name = htmlspecialchars(get_session_name() ?: 'Doctor');

// Latest teleconsult session for quick "Waiting Room" access.
$latest_teleconsult_session_id = 0;
$latest_q = db()->prepare(
    "SELECT id
     FROM teleconsult_sessions
     WHERE ehealth_doctor_id = ?
       AND status IN ('waiting','accepted','active')
     ORDER BY created_at DESC, id DESC
     LIMIT 1"
);
if ($latest_q) {
    $latest_q->bind_param('s', $doctor_id);
    $latest_q->execute();
    $latest_row = $latest_q->get_result()->fetch_assoc();
    $latest_q->close();
    if (!empty($latest_row['id'])) {
        $latest_teleconsult_session_id = (int)$latest_row['id'];
    }
}
$teleconsult_waiting_url = $latest_teleconsult_session_id > 0
    ? ('teleconsult_waiting.php?sessionId=' . $latest_teleconsult_session_id)
    : 'teleconsult_waiting.php';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Doctor Dashboard - eHealth Center</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', sans-serif; background: #a8e6b0; min-height: 100vh; display: flex; flex-direction: column; }

        .navbar { background: #2e7d32; height: 60px; padding: 0 36px; display: flex; align-items: center; justify-content: space-between; box-shadow: 0 2px 10px rgba(0,0,0,0.18); position: sticky; top: 0; z-index: 100; }
        .navbar-brand { display: flex; align-items: center; gap: 10px; text-decoration: none; }
        .brand-circle { width: 38px; height: 38px; background: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 18px; font-weight: 700; color: #2e7d32; }
        .brand-name { font-size: 20px; font-weight: 800; letter-spacing: 1.6px; color: white; }
        .nav-right { display: flex; align-items: center; gap: 30px; }
        .nav-links { display: flex; gap: 30px; list-style: none; }
        .nav-links a { color: white; text-decoration: none; font-size: 15px; font-weight: 500; opacity: 0.92; }
        .nav-links a:hover { opacity: 1; }
        .btn-logout { border: 2px solid white; background: transparent; color: white; padding: 7px 22px; border-radius: 20px; font-size: 14px; font-weight: 700; cursor: pointer; font-family: inherit; transition: background 0.2s, color 0.2s; }
        .btn-logout:hover { background: white; color: #2e7d32; }

        .hero { text-align: center; padding: 50px 20px 30px; }
        .hero h1 { font-size: 40px; font-weight: 800; color: #1b5e20; margin-bottom: 8px; }
        .hero p { font-size: 17px; color: #2e7d32; opacity: 0.85; }

        .section-title { text-align: center; font-size: 30px; font-weight: 700; color: #1b5e20; margin-bottom: 30px; }

        .cards-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 24px; max-width: 1100px; margin: 0 auto; padding: 0 20px 60px; }
        .card { background: white; border-radius: 18px; padding: 36px 28px; text-align: center; box-shadow: 0 4px 16px rgba(0,0,0,0.09); transition: transform 0.25s, box-shadow 0.25s; cursor: pointer; text-decoration: none; display: block; color: inherit; }
        .card:hover { transform: translateY(-5px); box-shadow: 0 10px 28px rgba(0,0,0,0.14); }

        .card-icon { font-size: 52px; margin-bottom: 16px; }
        .card-title { font-size: 20px; font-weight: 700; color: #1a1a2e; margin-bottom: 10px; }
        .card-desc { font-size: 14px; color: #6c757d; line-height: 1.6; margin-bottom: 22px; }
        .card-btn { background: linear-gradient(135deg, #2e7d32, #43a047); color: white; border: none; padding: 11px 32px; border-radius: 20px; font-size: 14px; font-weight: 700; cursor: pointer; display: inline-block; transition: transform 0.2s, box-shadow 0.2s; }
        .card-btn:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(46,125,50,0.4); }

        /* Password Modal */
        .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 500; align-items: center; justify-content: center; }
        .modal-overlay.open { display: flex; }
        .modal { background: white; border-radius: 18px; padding: 36px; width: 100%; max-width: 420px; box-shadow: 0 20px 60px rgba(0,0,0,0.3); animation: fadeUp 0.3s ease; }
        @keyframes fadeUp { from { opacity:0; transform:translateY(20px);} to { opacity:1; transform:translateY(0);} }
        .modal h2 { font-size: 22px; font-weight: 700; color: #1a1a2e; margin-bottom: 24px; }
        .modal-close { float: right; background: none; border: none; font-size: 20px; cursor: pointer; color: #888; margin-top: -4px; }
        .pw-group { margin-bottom: 18px; }
        .pw-group label { display: block; font-size: 13px; font-weight: 600; color: #333; margin-bottom: 7px; }
        .pw-wrap { position: relative; }
        .pw-wrap input { width: 100%; padding: 12px 44px 12px 14px; border: 2px solid #e0e0e0; border-radius: 10px; font-size: 14px; outline: none; }
        .pw-wrap input:focus { border-color: #2e7d32; }
        .pw-eye { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; font-size: 17px; }
        .modal-alert { padding: 10px 14px; border-radius: 8px; font-size: 13px; margin-bottom: 16px; display: none; }
        .modal-alert.error { background: #fdecea; color: #b71c1c; border: 1px solid #f5c6cb; }
        .modal-alert.success { background: #e8f5e9; color: #1b5e20; border: 1px solid #c3e6cb; }
        .btn-save { width: 100%; padding: 13px; background: linear-gradient(135deg, #2e7d32, #43a047); color: white; border: none; border-radius: 10px; font-size: 15px; font-weight: 700; cursor: pointer; margin-top: 8px; }

        @media (max-width: 600px) { .navbar { padding: 0 16px; } .hero h1 { font-size: 28px; } .cards-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>

<nav class="navbar">
    <a href="<?= htmlspecialchars(app_base_url()) ?>/" class="navbar-brand">
        <span style="display:flex;align-items:center;gap:14px;line-height:1;"><img src="<?= htmlspecialchars(app_base_url()) ?>/public/assets/SRMS_TRUST_LOGO.png" alt="SRMST Trust Logo" style="height:48px;width:auto;display:block;object-fit:contain;filter:drop-shadow(0 1px 3px rgba(0,0,0,.35));"><span style="font-size:22px;font-weight:800;letter-spacing:1px;color:#fff;text-shadow:0 1px 2px rgba(0,0,0,.35);white-space:nowrap;">SRMS EHEALTH</span></span>
    </a>
    <div class="nav-right">
        <ul class="nav-links">
            <li><a href="/ehealth/">HOME</a></li>
            <li><a href="<?= htmlspecialchars(app_base_url()) ?>/about.php">ABOUT</a></li>
                <li><a href="<?= htmlspecialchars(app_base_url()) ?>/contact.php">CONTACT</a></li>
</ul>
        <form method="POST" style="display:inline">
            <button type="submit" name="logout" class="btn-logout">LOGOUT</button>
        </form>
    </div>
</nav>

<div class="hero">
    <h1>Welcome, <?= $doctor_name ?> 👨‍⚕️</h1>
    <p>eHealth Center — Doctor Portal &nbsp;|&nbsp; ID: <strong><?= htmlspecialchars($doctor_id) ?></strong></p>
</div>

<h2 class="section-title">Doctor Dashboard</h2>

<div class="cards-grid">

    <a href="patient_queue.php" class="card">
        <div class="card-icon">🩺</div>
        <div class="card-title">Patient Queue</div>
        <div class="card-desc">View and manage your current patient queue, start consultations and record diagnoses.</div>
        <span class="card-btn">View Queue</span>
    </a>

    <a href="<?= htmlspecialchars($teleconsult_waiting_url) ?>" class="card">
        <div class="card-icon">📹</div>
        <div class="card-title">Teleconsult Waiting</div>
        <div class="card-desc">Open the current teleconsult waiting room directly and join as soon as the TeleStudio doctor starts.</div>
        <span class="card-btn">Open Waiting</span>
    </a>

    <a href="diagnosis_form.php" class="card">
        <div class="card-icon">📝</div>
        <div class="card-title">Diagnosis</div>
        <div class="card-desc">Examine patients, record diagnoses, prescribe medicines, and initiate teleconsultation when needed.</div>
        <span class="card-btn">Open Form</span>
    </a>

    <a href="view_history.php" class="card">
        <div class="card-icon">📋</div>
        <div class="card-title">View History</div>
        <div class="card-desc">Access past patient records, previous diagnoses, prescriptions, and teleconsultation reports.</div>
        <span class="card-btn">View History</span>
    </a>

    <a href="../common/diagnosis_print_center.php" class="card">
        <div class="card-icon">🖨️</div>
        <div class="card-title">Print Diagnosis</div>
        <div class="card-desc">Print today's diagnosis slips and search older slips by Patient ID or Mobile Number.</div>
        <span class="card-btn">Open Print Center</span>
    </a>

    <div class="card" onclick="openPasswordModal()">
        <div class="card-icon">🔒</div>
        <div class="card-title">Change Password</div>
        <div class="card-desc">Update your login credentials to keep your account secure and protected.</div>
        <button class="card-btn" onclick="event.stopPropagation(); openPasswordModal()">Change Password</button>
    </div>

</div>

<!-- Change Password Modal -->
<div class="modal-overlay" id="pwModal">
    <div class="modal">
        <button class="modal-close" onclick="closePasswordModal()">✕</button>
        <h2>🔒 Change Password</h2>
        <div class="modal-alert" id="pwAlert"></div>
        <div class="pw-group">
            <label>Current Password</label>
            <div class="pw-wrap">
                <input type="password" id="currentPw" placeholder="Enter current password">
                <button class="pw-eye" type="button" onclick="togglePw('currentPw', this)">👁</button>
            </div>
        </div>
        <div class="pw-group">
            <label>New Password</label>
            <div class="pw-wrap">
                <input type="password" id="newPw" placeholder="Min. 6 characters">
                <button class="pw-eye" type="button" onclick="togglePw('newPw', this)">👁</button>
            </div>
        </div>
        <div class="pw-group">
            <label>Confirm New Password</label>
            <div class="pw-wrap">
                <input type="password" id="confirmPw" placeholder="Re-enter new password">
                <button class="pw-eye" type="button" onclick="togglePw('confirmPw', this)">👁</button>
            </div>
        </div>
        <button class="btn-save" onclick="savePassword()">Save Password</button>
    </div>
</div>

<script>
    const DOCTOR_ID = '<?= htmlspecialchars($doctor_id) ?>';

    function openPasswordModal()  { document.getElementById('pwModal').classList.add('open'); }
    function closePasswordModal() { document.getElementById('pwModal').classList.remove('open'); clearPwModal(); }

    function togglePw(id, btn) {
        const i = document.getElementById(id);
        if (i.type === 'password') { i.type = 'text';     btn.textContent = '🙈'; }
        else                        { i.type = 'password'; btn.textContent = '👁'; }
    }

    function clearPwModal() {
        ['currentPw','newPw','confirmPw'].forEach(id => document.getElementById(id).value = '');
        const a = document.getElementById('pwAlert');
        a.style.display = 'none'; a.className = 'modal-alert';
    }

    function showPwAlert(msg, type) {
        const a = document.getElementById('pwAlert');
        a.textContent   = msg;
        a.className     = 'modal-alert ' + type;
        a.style.display = 'block';
    }

    async function savePassword() {
        const current = document.getElementById('currentPw').value;
        const newPw   = document.getElementById('newPw').value;
        const confirm = document.getElementById('confirmPw').value;

        if (!current || !newPw || !confirm) { showPwAlert('All fields are required.', 'error'); return; }
        if (newPw.length < 6)               { showPwAlert('New password must be at least 6 characters.', 'error'); return; }
        if (newPw !== confirm)              { showPwAlert('New passwords do not match.', 'error'); return; }

        try {
            const res  = await fetch('change_password.php', {
                method : 'POST',
                headers: { 'Content-Type': 'application/json' },
                body   : JSON.stringify({ currentPassword: current, newPassword: newPw })
            });
            const data = await res.json();
            if (data.success) {
                showPwAlert('✅ Password changed successfully!', 'success');
                setTimeout(closePasswordModal, 1500);
            } else {
                showPwAlert('❌ ' + (data.error || 'Failed to change password.'), 'error');
            }
        } catch (e) {
            showPwAlert('❌ Connection error. Please try again.', 'error');
        }
    }

    window.onpageshow = (e) => { if (e.persisted) window.location.reload(); };
</script>
<script src="../assets/doctor_notifications.js"></script>
</body>
</html>

