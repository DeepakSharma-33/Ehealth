<?php
require_once __DIR__ . '/../../config/config.php';
require_role('patient', 'patient_login.php');

if (isset($_POST['logout'])) {
    logout_user();
    header('Location: patient_login.php');
    exit;
}

$patient_id   = $_SESSION['patient_id'] ?? '';
$patient_name = htmlspecialchars(get_session_name());
$first_name   = htmlspecialchars(explode(' ', get_session_name())[0]);
$numeric_id   = get_session_id(); // DB auto-increment id

$db = db();

// ── Fetch full patient record ──────────────────────────────
$stmt = $db->prepare('SELECT * FROM patients WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $numeric_id);
$stmt->execute();
$patient = $stmt->get_result()->fetch_assoc();
$stmt->close();

// ── Photo path ─────────────────────────────────────────────
$photo_url = patient_photo_url($patient['photo_filename'] ?? null);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Dashboard - eHealth System</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=DM+Serif+Display&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root {
            --green-dark:  #1b4332;
            --green-nav:   #2d6a4f;
            --green-mid:   #40916c;
            --green-light: #d8f3dc;
            --green-pale:  #f0faf2;
            --white:       #ffffff;
            --border:      #b7e4c7;
            --text:        #1b2e22;
            --muted:       #52796f;
        }
        body { font-family: 'DM Sans', sans-serif; background: var(--green-light); min-height: 100vh; }

        /* ── Navbar ── */
        .navbar { background: var(--green-nav); padding: 0 40px; height: 56px; display: flex; align-items: center; justify-content: space-between; box-shadow: 0 2px 12px rgba(0,0,0,0.18); position: sticky; top: 0; z-index: 100; }
        .navbar-brand { display: flex; align-items: center; gap: 10px; text-decoration: none; color: white; }
        .brand-icon { width: 34px; height: 34px; background: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 17px; }
        .brand-name { font-size: 19px; font-weight: 700; letter-spacing: 1.5px; }
        .nav-right { display: flex; align-items: center; gap: 24px; }
        .nav-links { display: flex; gap: 32px; list-style: none; }
        .nav-links a { color: rgba(255,255,255,0.88); text-decoration: none; font-size: 13px; font-weight: 500; transition: color .2s; }
        .nav-links a:hover { color: white; }

        /* User pill with dropdown */
        .user-pill { display: flex; align-items: center; gap: 8px; background: rgba(255,255,255,0.15); border-radius: 20px; padding: 5px 14px 5px 8px; cursor: pointer; position: relative; }
        .user-pill-avatar { width: 26px; height: 26px; background: var(--green-light); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 13px; overflow: hidden; }
        .user-pill-avatar img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }
        .user-pill-name { font-size: 13px; font-weight: 600; color: white; }
        .dropdown { display: none; position: absolute; top: 34px; right: 0; background: white; border-radius: 10px; box-shadow: 0 8px 24px rgba(0,0,0,0.2); min-width: 170px; z-index: 200; }
        .user-pill:hover .dropdown,
        .user-pill:focus-within .dropdown { display: block; }
        .dropdown a { display: block; padding: 10px 16px; color: var(--text); text-decoration: none; font-size: 13px; font-weight: 500; transition: background .2s; }
        .dropdown a:hover { background: var(--green-pale); }
        .dropdown a:first-child { border-radius: 10px 10px 0 0; }
        .dropdown .logout-item { border-top: 1px solid #eee; border-radius: 0 0 10px 10px; }

        /* ── Page ── */
        .page-body { max-width: 1100px; margin: 0 auto; padding: 36px 20px 60px; }

        /* ── Hero ── */
        .hero { text-align: center; margin-bottom: 36px; }
        .hero h1 { font-family: 'DM Serif Display', serif; font-size: 34px; color: var(--green-dark); margin-bottom: 6px; }
        .hero p { font-size: 15px; color: var(--muted); }
        .hero-wave { display: inline-block; animation: wave 1.8s infinite; transform-origin: 70% 70%; }
        @keyframes wave { 0%,100%{transform:rotate(0)} 10%,30%{transform:rotate(14deg)} 20%{transform:rotate(-8deg)} 40%{transform:rotate(-4deg)} 50%{transform:rotate(10deg)} }

        /* ── Patient profile card ── */
        .profile-card { background: white; border-radius: 20px; padding: 28px 32px; margin-bottom: 28px; box-shadow: 0 2px 16px rgba(27,67,50,0.09); display: flex; gap: 28px; align-items: center; flex-wrap: wrap; }
        .profile-photo { width: 90px; height: 90px; border-radius: 50%; border: 4px solid var(--green-mid); object-fit: cover; flex-shrink: 0; background: var(--green-light); display: flex; align-items: center; justify-content: center; font-size: 36px; }
        .profile-photo img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }
        .profile-info { flex: 1; }
        .profile-name { font-size: 22px; font-weight: 700; color: var(--green-dark); margin-bottom: 8px; }
        .profile-meta { display: flex; flex-wrap: wrap; gap: 14px; }
        .meta-chip { background: var(--green-pale); border: 1px solid var(--border); border-radius: 20px; padding: 4px 14px; font-size: 12px; font-weight: 600; color: var(--green-nav); }
        .profile-badge { background: var(--green-nav); color: white; font-size: 13px; font-weight: 700; padding: 8px 20px; border-radius: 20px; letter-spacing: 1px; flex-shrink: 0; }

        /* ── Section title ── */
        .section-title { font-family: 'DM Serif Display', serif; font-size: 24px; color: var(--green-dark); margin-bottom: 24px; text-align: center; }

        /* ── Dashboard cards ── */
        .dashboard-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 22px; max-width: 900px; margin: 0 auto; }
        .dashboard-card { background: white; border-radius: 20px; padding: 30px 26px; box-shadow: 0 2px 16px rgba(27,67,50,0.09); text-align: center; transition: transform .2s, box-shadow .2s; text-decoration: none; display: block; }
        .dashboard-card:hover { transform: translateY(-4px); box-shadow: 0 8px 24px rgba(27,67,50,0.15); }
        .card-icon-wrap { width: 76px; height: 76px; background: var(--green-nav); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 18px; font-size: 34px; box-shadow: 0 4px 14px rgba(45,106,79,0.3); }
        .card-title { font-size: 19px; font-weight: 700; color: var(--green-dark); margin-bottom: 10px; }
        .card-description { font-size: 13px; color: var(--muted); line-height: 1.6; }

        @media (max-width: 640px) { .navbar { padding: 0 16px; } .nav-links { display: none; } .profile-card { flex-direction: column; text-align: center; } .profile-meta { justify-content: center; } .dashboard-grid { grid-template-columns: 1fr; } }
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
        <div class="user-pill">
            <div class="user-pill-avatar">
                <?php if ($photo_url): ?>
                    <img src="<?= $photo_url ?>" alt="<?= $first_name ?>">
                <?php else: ?>
                    👤
                <?php endif; ?>
            </div>
            <span class="user-pill-name"><?= $first_name ?></span>
            <div class="dropdown">
                <a href="patient_dashboard.php">🏠 Dashboard</a>
                <a href="patient_change_password.php">🔒 Change Password</a>
                <form method="POST" style="display:block">
                    <button type="submit" name="logout" style="width:100%;text-align:left;padding:10px 16px;background:none;border:none;font-family:inherit;font-size:13px;font-weight:500;color:#c62828;cursor:pointer;border-top:1px solid #eee;border-radius:0 0 10px 10px;" onmouseover="this.style.background='#fff0f0'" onmouseout="this.style.background='none'">
                        🚪 Logout
                    </button>
                </form>
            </div>
        </div>
    </div>
</nav>

<div class="page-body">

    <!-- Hero -->
    <div class="hero">
        <h1>Welcome, <?= $first_name ?> <span class="hero-wave">👋</span></h1>
        <p>Your eHealth patient portal</p>
    </div>

    <!-- Profile Card -->
    <div class="profile-card">
        <div class="profile-photo">
            <?php if ($photo_url): ?>
                <img src="<?= $photo_url ?>" alt="<?= $patient_name ?>">
            <?php else: ?>
                👤
            <?php endif; ?>
        </div>
        <div class="profile-info">
            <div class="profile-name"><?= $patient_name ?></div>
            <div class="profile-meta">
                <span class="meta-chip">🎂 Age: <?= htmlspecialchars($patient['age'] ?? '—') ?></span>
                <span class="meta-chip">⚧ <?= htmlspecialchars($patient['gender'] ?? '—') ?></span>
                <span class="meta-chip">📱 <?= htmlspecialchars($patient['mobile_no'] ?? '—') ?></span>
                <span class="meta-chip">📍 <?= htmlspecialchars($patient['district'] ?? '') ?>, <?= htmlspecialchars($patient['state'] ?? '') ?></span>
                <?php if (!empty($patient['abha_id'])): ?>
                    <span class="meta-chip">🏥 ABHA: <?= htmlspecialchars($patient['abha_id']) ?></span>
                <?php endif; ?>
            </div>
        </div>
        <div class="profile-badge"><?= htmlspecialchars($patient_id) ?></div>
    </div>

    <!-- Section -->
    <div class="section-title">Patient Dashboard</div>

    <div class="dashboard-grid">

        <a href="patient_medical_records.php" class="dashboard-card">
            <div class="card-icon-wrap">📋</div>
            <div class="card-title">View Medical Records</div>
            <div class="card-description">Access your complete medical history including vitals, diagnoses, and treatment records.</div>
        </a>

        <a href="patient_change_password.php" class="dashboard-card">
            <div class="card-icon-wrap">🔒</div>
            <div class="card-title">Change Password</div>
            <div class="card-description">Update your account password to keep your patient profile secure and protected.</div>
        </a>

        <a href="patient_vitals_history.php" class="dashboard-card">
            <div class="card-icon-wrap">💓</div>
            <div class="card-title">Vitals History</div>
            <div class="card-description">View all your previously recorded vital signs with trends and history.</div>
        </a>

    </div>

</div>

</body>
</html>

