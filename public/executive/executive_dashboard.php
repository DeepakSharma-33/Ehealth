<?php
require_once __DIR__ . '/../../config/config.php';
require_role('executive', 'executive_login.php');

$db       = db();
$execId   = get_session_id();   // executive_id (int)
$execName = get_session_name();

// ── Stats ─────────────────────────────────────────────────────
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Executive Dashboard - eHealth</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Nunito:wght@400;500;600;700;800&display=swap');
        :root { --green-dark:#2e7d32; --green-mid:#388e3c; --green-light:#a5d6a7; --green-pale:#e8f5e9; --text-dark:#1b3a1e; --text-mid:#3d6b41; --text-muted:#6b9a6f; }
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Nunito','Segoe UI',sans-serif; background:linear-gradient(160deg,#b9dfbb 0%,#d4edd6 50%,#c5e8c7 100%); min-height:100vh; color:var(--text-dark); }
        .navbar { background:var(--green-dark); height:58px; padding:0 40px; display:flex; align-items:center; justify-content:space-between; box-shadow:0 2px 12px rgba(0,0,0,.18); position:sticky; top:0; z-index:100; }
        .navbar-brand { display:flex; align-items:center; gap:10px; text-decoration:none; }
        .brand-icon { width:38px; height:38px; background:white; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:20px; }
        .brand-name { font-size:20px; font-weight:800; letter-spacing:1.5px; color:white; }
        .nav-right { display:flex; align-items:center; gap:36px; }
        .nav-links { display:flex; gap:36px; list-style:none; }
        .nav-links a { color:white; text-decoration:none; font-size:14px; font-weight:600; opacity:.9; }
        .nav-links a:hover { opacity:1; }
        .profile-btn { width:40px; height:40px; background:rgba(255,255,255,.18); border:2px solid rgba(255,255,255,.5); border-radius:50%; display:flex; align-items:center; justify-content:center; cursor:pointer; font-size:20px; color:white; position:relative; }
        .profile-btn:hover { background:rgba(255,255,255,.3); }
        .profile-dropdown { display:none; position:absolute; top:52px; right:0; background:white; border-radius:12px; box-shadow:0 8px 30px rgba(0,0,0,.18); min-width:220px; overflow:hidden; z-index:200; }
        .profile-dropdown.show { display:block; }
        .dropdown-header { padding:16px 18px 12px; border-bottom:1px solid #f0f0f0; }
        .dropdown-header .exec-name { font-size:15px; font-weight:700; color:var(--text-dark); }
        .dropdown-header .exec-id { font-size:12px; color:var(--text-muted); margin-top:2px; }
        .dropdown-item { display:flex; align-items:center; gap:10px; padding:12px 18px; font-size:14px; color:var(--text-dark); text-decoration:none; cursor:pointer; border:none; background:none; width:100%; text-align:left; font-family:inherit; transition:background .15s; }
        .dropdown-item:hover { background:var(--green-pale); }
        .dropdown-item.logout { color:#c0392b; }
        .dropdown-item.logout:hover { background:#fdecea; }
        .hero { text-align:center; padding:56px 20px 44px; }
        .hero h1 { font-size:clamp(26px,4vw,38px); font-weight:800; color:var(--text-dark); margin-bottom:10px; }
        .hero p { font-size:16px; color:var(--text-mid); font-weight:500; }
        .dashboard-section { max-width:1100px; margin:0 auto; padding:0 24px 60px; }
        .section-title { text-align:center; font-size:clamp(22px,3vw,30px); font-weight:800; color:var(--text-dark); margin-bottom:40px; }
        .cards-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:28px; }
        .card { background:white; border-radius:20px; padding:40px 28px 36px; box-shadow:0 4px 20px rgba(46,125,50,.12); text-align:center; cursor:pointer; text-decoration:none; color:inherit; display:flex; flex-direction:column; align-items:center; transition:transform .25s,box-shadow .25s; border:2px solid transparent; }
        .card:hover { transform:translateY(-6px); box-shadow:0 10px 36px rgba(46,125,50,.22); border-color:var(--green-light); }
        .card-icon-wrap { width:80px; height:80px; background:linear-gradient(135deg,var(--green-mid),var(--green-dark)); border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:36px; margin-bottom:22px; box-shadow:0 6px 20px rgba(46,125,50,.3); transition:transform .25s; }
        .card:hover .card-icon-wrap { transform:scale(1.08) rotate(-4deg); }
        .card h2 { font-size:19px; font-weight:800; color:var(--text-dark); margin-bottom:12px; }
        .card p { font-size:14px; color:var(--text-muted); line-height:1.6; }
        /* Modal */
        .modal-overlay { display:none; position:fixed; inset:0; background:rgba(20,60,22,.45); z-index:500; align-items:center; justify-content:center; backdrop-filter:blur(3px); padding:20px; }
        .modal-overlay.show { display:flex; }
        .modal-box { background:white; border-radius:20px; width:100%; max-width:420px; padding:36px 36px 30px; box-shadow:0 24px 60px rgba(0,0,0,.25); }
        .modal-header { text-align:center; margin-bottom:24px; }
        .modal-icon { font-size:38px; margin-bottom:10px; }
        .modal-header h3 { font-size:20px; font-weight:800; color:var(--text-dark); margin-bottom:4px; }
        .modal-header p { font-size:13px; color:var(--text-muted); }
        .modal-alert { padding:10px 14px; border-radius:8px; font-size:13px; margin-bottom:18px; font-weight:600; line-height:1.5; display:none; }
        .modal-alert.error   { background:#fdecea; color:#b71c1c; border:1px solid #f5c6cb; display:block; }
        .modal-alert.success { background:#e8f5e9; color:#1b5e20; border:1px solid #c3e6cb; display:block; }
        .modal-field { margin-bottom:16px; }
        .modal-field label { display:block; font-size:12px; font-weight:700; color:var(--text-dark); margin-bottom:6px; text-transform:uppercase; letter-spacing:.4px; }
        .req { color:#e74c3c; }
        .modal-input-wrap { position:relative; }
        .modal-input-wrap input { width:100%; padding:11px 40px 11px 14px; border:2px solid #e0e0e0; border-radius:10px; font-size:14px; font-family:inherit; background:#f7f9ff; outline:none; transition:border-color .2s; }
        .modal-input-wrap input:focus { border-color:var(--green-mid); background:white; box-shadow:0 0 0 3px rgba(46,125,50,.1); }
        .eye-btn { position:absolute; right:10px; top:50%; transform:translateY(-50%); background:none; border:none; cursor:pointer; font-size:16px; color:#999; }
        .eye-btn:hover { color:var(--green-dark); }
        .modal-actions { display:flex; gap:12px; margin-top:24px; }
        .btn-cancel { flex:1; padding:12px; background:#f5f5f5; color:#555; border:none; border-radius:10px; font-size:14px; font-weight:700; cursor:pointer; font-family:inherit; }
        .btn-cancel:hover { background:#ebebeb; }
        .btn-save { flex:2; padding:12px; background:linear-gradient(135deg,var(--green-mid),var(--green-dark)); color:white; border:none; border-radius:10px; font-size:14px; font-weight:700; cursor:pointer; font-family:inherit; }
        .btn-save:hover { opacity:.92; }
        .btn-save:disabled { opacity:.6; cursor:not-allowed; }
        @media(max-width:1080px){ .cards-grid{grid-template-columns:repeat(2,1fr);} }
        @media(max-width:600px){ .cards-grid{grid-template-columns:1fr;} .navbar{padding:0 16px;} }
    </style>
</head>
<body>
<nav class="navbar">
    <a href="<?= htmlspecialchars(app_base_url()) ?>/" class="navbar-brand">
        <span style="display:flex;align-items:center;gap:14px;line-height:1;"><img src="<?= htmlspecialchars(app_base_url()) ?>/public/assets/SRMS_TRUST_LOGO.png" alt="SRMST Trust Logo" style="height:48px;width:auto;display:block;object-fit:contain;filter:drop-shadow(0 1px 3px rgba(0,0,0,.35));"><span style="font-size:22px;font-weight:800;letter-spacing:1px;color:#fff;text-shadow:0 1px 2px rgba(0,0,0,.35);white-space:nowrap;">SRMS EHEALTH</span></span>
    </a>
    <div class="nav-right">
        <ul class="nav-links"><li><a href="/ehealth/">HOME</a></li>        <li><a href="<?= htmlspecialchars(app_base_url()) ?>/about.php">ABOUT</a></li>
        <li><a href="<?= htmlspecialchars(app_base_url()) ?>/contact.php">CONTACT</a></li>
</ul>
        <div class="profile-btn" id="profileBtn">
            👤
            <div class="profile-dropdown" id="profileDropdown">
                <div class="dropdown-header">
                    <div class="exec-name"><?= htmlspecialchars($execName) ?></div>
                    <div class="exec-id">ID: <?= htmlspecialchars($execId) ?></div>
                </div>
                <a href="executive_profile.php" class="dropdown-item">⚙️ &nbsp;My Profile</a>
                <button class="dropdown-item" id="changePassBtn">🔒 &nbsp;Change Password</button>
                <a href="executive_logout.php" class="dropdown-item logout">🚪 &nbsp;Logout</a>
            </div>
        </div>
    </div>
</nav>

<div class="hero">
    <h1>Welcome, <?= htmlspecialchars($execName) ?> 👋</h1>
    <p>Your eHealth operations dashboard</p>
</div>

<div class="dashboard-section">
    <h2 class="section-title">Executive Dashboard</h2>
    <div class="cards-grid">
        <a href="patient_select.php" class="card">
            <div class="card-icon-wrap">📋</div>
            <h2>Register Patient</h2>
            <p>Onboard new patients into the eHealth system with complete demographic and contact information.</p>
        </a>
        <a href="../patient/patient_records.php" class="card">
            <div class="card-icon-wrap">🗂️</div>
            <h2>View Patient Records</h2>
            <p>Search, browse, and manage existing patient medical records, history, and diagnostic information.</p>
        </a>
        <a href="../patient/patient_vitals.php" class="card">
            <div class="card-icon-wrap">💉</div>
            <h2>Enter Vitals</h2>
            <p>Record patient vitals including blood pressure, temperature, pulse rate, oxygen level, and weight.</p>
        </a>
        <a href="print_registration_slip.php" class="card">
            <div class="card-icon-wrap">🖨️</div>
            <h2>Print Registration Slip</h2>
            <p>Search any registered patient and print the registration slip again whenever needed.</p>
        </a>
        <a href="../common/diagnosis_print_center.php" class="card">
            <div class="card-icon-wrap">📄</div>
            <h2>Print Diagnosis</h2>
            <p>View today's diagnosis slips and search older diagnosis by Patient ID or Mobile Number for reprint.</p>
        </a>
        <a href="#" class="card" onclick="openChangePassword(event)">
            <div class="card-icon-wrap">🔒</div>
            <h2>Change Password</h2>
            <p>Update your account password to keep your executive profile secure and protected.</p>
        </a>
    </div>
</div>

<!-- Change Password Modal -->
<div class="modal-overlay" id="modalOverlay">
    <div class="modal-box">
        <div class="modal-header">
            <div class="modal-icon">🔒</div>
            <h3>Change Password</h3>
            <p>Enter your current and new password below</p>
        </div>
        <div class="modal-alert" id="modalAlert"></div>
        <div class="modal-field">
            <label>Current Password <span class="req">*</span></label>
            <div class="modal-input-wrap">
                <input type="password" id="oldPassword" placeholder="Enter current password">
                <button type="button" class="eye-btn" data-target="oldPassword">👁</button>
            </div>
        </div>
        <div class="modal-field">
            <label>New Password <span class="req">*</span></label>
            <div class="modal-input-wrap">
                <input type="password" id="newPassword" placeholder="Min. 6 characters">
                <button type="button" class="eye-btn" data-target="newPassword">👁</button>
            </div>
        </div>
        <div class="modal-field">
            <label>Confirm New Password <span class="req">*</span></label>
            <div class="modal-input-wrap">
                <input type="password" id="confirmPassword" placeholder="Repeat new password">
                <button type="button" class="eye-btn" data-target="confirmPassword">👁</button>
            </div>
        </div>
        <div class="modal-actions">
            <button class="btn-cancel" id="modalCancel">Cancel</button>
            <button class="btn-save"   id="modalSave">Update Password</button>
        </div>
    </div>
</div>

<script>
    // Dropdown
    const profileBtn      = document.getElementById('profileBtn');
    const profileDropdown = document.getElementById('profileDropdown');
    profileBtn.addEventListener('click', e => { e.stopPropagation(); profileDropdown.classList.toggle('show'); });
    document.addEventListener('click', () => profileDropdown.classList.remove('show'));

    // Modal
    const modalOverlay = document.getElementById('modalOverlay');
    function openChangePassword(e) {
        if (e) e.preventDefault();
        ['oldPassword','newPassword','confirmPassword'].forEach(id => document.getElementById(id).value = '');
        showModalAlert('', '');
        document.getElementById('modalSave').disabled = false;
        document.getElementById('modalSave').textContent = 'Update Password';
        modalOverlay.classList.add('show');
        document.getElementById('oldPassword').focus();
    }
    document.getElementById('modalCancel').addEventListener('click', () => modalOverlay.classList.remove('show'));
    document.getElementById('changePassBtn').addEventListener('click', openChangePassword);
    modalOverlay.addEventListener('click', e => { if (e.target === modalOverlay) modalOverlay.classList.remove('show'); });

    // Eye toggles
    document.querySelectorAll('.eye-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const input = document.getElementById(btn.dataset.target);
            const isText = input.type === 'text';
            input.type = isText ? 'password' : 'text';
            btn.textContent = isText ? '👁' : '🙈';
        });
    });

    function showModalAlert(msg, type) {
        const el = document.getElementById('modalAlert');
        el.className = 'modal-alert ' + type;
        el.textContent = msg;
        el.style.display = msg ? 'block' : 'none';
    }

    document.getElementById('modalSave').addEventListener('click', async () => {
        const oldPass  = document.getElementById('oldPassword').value.trim();
        const newPass  = document.getElementById('newPassword').value;
        const confPass = document.getElementById('confirmPassword').value;
        if (!oldPass)             { showModalAlert('⚠️ Please enter your current password.', 'error'); return; }
        if (!newPass)             { showModalAlert('⚠️ Please enter a new password.', 'error'); return; }
        if (newPass.length < 6)   { showModalAlert('⚠️ New password must be at least 6 characters.', 'error'); return; }
        if (newPass !== confPass) { showModalAlert('⚠️ Passwords do not match.', 'error'); return; }
        const saveBtn = document.getElementById('modalSave');
        saveBtn.disabled = true;
        saveBtn.textContent = 'Updating...';
        const formData = new FormData();
        formData.append('oldPassword', oldPass);
        formData.append('newPassword', newPass);
        try {
            const res  = await fetch('executive_change_password.php', { method:'POST', body:formData });
            const data = await res.json();
            if (data.success) {
                showModalAlert('✅ Password updated successfully!', 'success');
                setTimeout(() => modalOverlay.classList.remove('show'), 1800);
            } else {
                showModalAlert('❌ ' + (data.error || 'Failed to update password.'), 'error');
                saveBtn.disabled = false;
                saveBtn.textContent = 'Update Password';
            }
        } catch (_) {
            showModalAlert('❌ Could not connect to server.', 'error');
            saveBtn.disabled = false;
            saveBtn.textContent = 'Update Password';
        }
    });
</script>
</body>
</html>

