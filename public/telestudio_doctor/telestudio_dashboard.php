<?php
require_once __DIR__ . '/../../config/config.php';
require_role('telestudio_doctor', 'telestudio_doctor_login.php');

$db          = db();
$doctor_id   = get_session_id();
$doctor_name = get_session_name();

// Fetch full doctor info
$stmt = $db->prepare('SELECT * FROM telestudio_doctors WHERE doctor_id = ? LIMIT 1');
$stmt->bind_param('s', $doctor_id);
$stmt->execute();
$doctor = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Handle availability toggle (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $status = (int)($body['available'] ?? 0);
    // Check if availability column exists, add it if not
    $db->query("ALTER TABLE telestudio_doctors ADD COLUMN IF NOT EXISTS is_available TINYINT(1) DEFAULT 0");
    $upd = $db->prepare('UPDATE telestudio_doctors SET is_available = ? WHERE doctor_id = ?');
    $upd->bind_param('is', $status, $doctor_id);
    $upd->execute();
    $upd->close();
    echo json_encode(['success' => true, 'available' => $status]);
    exit;
}

// Get current availability
$is_available = (int)($doctor['is_available'] ?? 0);

// Stats
$queue_count = 0;
$res = $db->query("SELECT COUNT(*) AS c FROM teleconsult_sessions WHERE telestudio_doctor_id = '$doctor_id' AND status = 'waiting'");
if ($res) { $row = $res->fetch_assoc(); $queue_count = (int)($row['c'] ?? 0); }

$history_count = 0;
$res2 = $db->query("SELECT COUNT(*) AS c FROM telestudio_diagnosis WHERE telestudio_doctor_id = '$doctor_id'");
if ($res2) { $row2 = $res2->fetch_assoc(); $history_count = (int)($row2['c'] ?? 0); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Telestudio Dashboard — eHealth</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
        :root {
            --g-dark:  #1b5e20;
            --g-mid:   #2e7d32;
            --g-light: #43a047;
            --g-pale:  #c8e6c9;
            --g-bg:    #b9deba;
            --white:   #ffffff;
            --gray-500:#6b7280;
            --gray-700:#374151;
            --gray-900:#111827;
        }
        html, body { height: 100%; font-family: 'Inter', sans-serif; }
        body { min-height: 100vh; background: #a8d5aa; display: flex; flex-direction: column; }

        /* NAVBAR */
        .navbar {
            background: var(--g-mid); height: 58px; padding: 0 32px;
            display: flex; align-items: center; justify-content: space-between;
            flex-shrink: 0; box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }
        .nav-left { display: flex; align-items: center; gap: 10px; text-decoration: none; }
        .nav-logo {
            width: 36px; height: 36px; background: white; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 15px; font-weight: 800; color: var(--g-mid);
        }
        .nav-brand { font-size: 17px; font-weight: 800; letter-spacing: 2.5px; color: white; }
        .nav-right { display: flex; align-items: center; gap: 24px; }
        .nav-right a.home-link {
            color: rgba(255,255,255,0.92); text-decoration: none;
            font-size: 13px; font-weight: 600; letter-spacing: 0.4px;
        }
        .nav-right a.home-link:hover { color: white; }
        .btn-logout {
            padding: 7px 20px; border: 2px solid white; border-radius: 20px;
            background: none; color: white; font-size: 13px; font-weight: 700;
            font-family: 'Inter', sans-serif; cursor: pointer; letter-spacing: 0.5px;
            transition: background 0.2s, color 0.2s;
        }
        .btn-logout:hover { background: white; color: var(--g-mid); }
        .nav-avatar {
            width: 36px; height: 36px; background: rgba(255,255,255,0.2);
            border-radius: 50%; display: flex; align-items: center;
            justify-content: center; font-size: 18px; cursor: pointer;
        }

        /* AVAILABILITY BAR */
        .avail-bar {
            background: #1a4d1e; padding: 12px 32px;
            display: flex; align-items: center; justify-content: center; gap: 16px;
            flex-shrink: 0;
        }
        .avail-label { color: rgba(255,255,255,0.9); font-size: 14px; font-weight: 600; }
        /* Toggle switch */
        .toggle-wrap { position: relative; display: inline-block; width: 52px; height: 28px; }
        .toggle-wrap input { opacity: 0; width: 0; height: 0; }
        .toggle-slider {
            position: absolute; inset: 0; background: #555; border-radius: 28px;
            cursor: pointer; transition: background 0.3s;
        }
        .toggle-slider::before {
            content: ''; position: absolute;
            width: 22px; height: 22px; border-radius: 50%;
            background: white; bottom: 3px; left: 3px;
            transition: transform 0.3s;
            box-shadow: 0 1px 4px rgba(0,0,0,0.3);
        }
        .toggle-wrap input:checked + .toggle-slider { background: #4caf50; }
        .toggle-wrap input:checked + .toggle-slider::before { transform: translateX(24px); }

        .status-badge {
            padding: 5px 16px; border-radius: 20px; font-size: 13px; font-weight: 700;
            display: flex; align-items: center; gap: 7px;
            background: rgba(255,255,255,0.12); color: white;
            transition: background 0.3s;
        }
        .status-badge .dot {
            width: 8px; height: 8px; border-radius: 50%;
            background: #9ca3af; transition: background 0.3s;
        }
        .status-badge.online .dot { background: #4caf50; }
        .status-badge.online { background: rgba(76,175,80,0.2); }
        .avail-hint { color: rgba(255,255,255,0.6); font-size: 13px; }

        /* HERO */
        .hero {
            text-align: center; padding: 48px 20px 20px;
        }
        .hero h1 {
            font-size: clamp(26px, 4vw, 38px); font-weight: 800;
            color: #1a3d1c; margin-bottom: 8px;
        }
        .hero-sub { font-size: 15px; color: #3a6b3d; margin-bottom: 18px; font-weight: 500; }
        .hero-badge {
            display: inline-flex; align-items: center; gap: 7px;
            background: white; color: var(--g-mid);
            border: 1.5px solid var(--g-pale);
            padding: 7px 20px; border-radius: 24px;
            font-size: 13px; font-weight: 700;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }

        /* DASHBOARD TITLE */
        .dash-title {
            text-align: center; font-size: clamp(22px, 3vw, 30px);
            font-weight: 800; color: #1a3d1c; margin: 32px 0 28px;
        }

        /* CARDS GRID */
        .cards-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            max-width: 1180px;
            margin: 0 auto 60px;
            padding: 0 24px;
        }

        .dash-card {
            background: white; border-radius: 18px;
            padding: 36px 28px 28px;
            display: flex; flex-direction: column; align-items: center;
            text-align: center;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .dash-card:hover { transform: translateY(-4px); box-shadow: 0 10px 30px rgba(0,0,0,0.13); }

        .card-icon-wrap {
            width: 80px; height: 80px; border-radius: 50%;
            background: var(--g-mid);
            display: flex; align-items: center; justify-content: center;
            font-size: 34px; margin-bottom: 20px;
            box-shadow: 0 4px 16px rgba(46,125,50,0.3);
        }

        .card-title-text {
            font-size: 16px; font-weight: 800; color: var(--gray-900);
            margin-bottom: 12px;
        }
        .card-desc {
            font-size: 13px; color: var(--gray-500);
            line-height: 1.6; flex: 1; margin-bottom: 24px;
        }
        .card-badge-count {
            font-size: 11px; font-weight: 700; color: var(--g-mid);
            background: #f0fdf4; border: 1px solid #bbf7d0;
            padding: 2px 10px; border-radius: 10px; margin-bottom: 12px;
        }

        .card-btn {
            width: 100%; padding: 13px;
            background: var(--g-mid); color: white;
            border: none; border-radius: 10px;
            font-size: 14px; font-weight: 700;
            font-family: 'Inter', sans-serif;
            cursor: pointer; text-decoration: none;
            display: block; text-align: center;
            transition: background 0.2s, transform 0.15s;
            box-shadow: 0 3px 10px rgba(46,125,50,0.25);
        }
        .card-btn:hover { background: var(--g-dark); transform: translateY(-1px); }

        @media (max-width: 900px) {
            .cards-grid { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 520px) {
            .cards-grid { grid-template-columns: 1fr; }
            .avail-bar { flex-wrap: wrap; gap: 10px; }
        }
    </style>
</head>
<body>

<!-- NAVBAR -->
<nav class="navbar">
    <a href="<?= htmlspecialchars(app_base_url()) ?>/" class="nav-left">
        <span style="display:flex;align-items:center;gap:14px;line-height:1;margin-right:auto;"><img src="<?= htmlspecialchars(app_base_url()) ?>/public/assets/SRMS_TRUST_LOGO.png" alt="SRMST Trust Logo" style="height:48px;width:auto;display:block;object-fit:contain;filter:drop-shadow(0 1px 3px rgba(0,0,0,.35));"><span style="font-size:22px;font-weight:800;letter-spacing:1px;color:#fff;text-shadow:0 1px 2px rgba(0,0,0,.35);white-space:nowrap;">SRMS EHEALTH</span></span>
    </a>
    <div class="nav-right">
        <a href="/ehealth/" class="home-link">HOME</a>
        <a href="<?= htmlspecialchars(app_base_url()) ?>/about.php" class="home-link">ABOUT</a>
        <a href="<?= htmlspecialchars(app_base_url()) ?>/contact.php" class="home-link">CONTACT</a>
        <form method="POST" action="telestudio_logout.php" style="display:inline">
            <button type="submit" class="btn-logout">LOGOUT</button>
        </form>
        <div class="nav-avatar">&#128100;</div>
    </div>
</nav>

<!-- AVAILABILITY BAR -->
<div class="avail-bar" id="availBar">
    <span class="avail-label">Your Availability Status:</span>
    <label class="toggle-wrap">
        <input type="checkbox" id="availToggle" <?= $is_available ? 'checked' : '' ?>>
        <span class="toggle-slider"></span>
    </label>
    <div class="status-badge <?= $is_available ? 'online' : '' ?>" id="statusBadge">
        <div class="dot"></div>
        <span id="statusText"><?= $is_available ? 'Online' : 'Offline' ?></span>
    </div>
    <span class="avail-hint">Toggle ON to appear available to eHealth Center doctors</span>
</div>

<!-- HERO -->
<div class="hero">
    <h1>Welcome, <?= htmlspecialchars($doctor_name) ?></h1>
    <p class="hero-sub">Your trusted teleconsultation partner</p>
    <div class="hero-badge">&#127973; Hospital Telestudio Specialist</div>
</div>

<div class="dash-title">Telestudio Doctor Dashboard</div>

<!-- CARDS -->
<div class="cards-grid">

    <div class="dash-card">
        <div class="card-icon-wrap">&#128197;</div>
        <div class="card-title-text">My Availability</div>
        <div class="card-desc">Toggle your availability using the banner above. When ON, eHealth center doctors can see and call you for teleconsultations.</div>
        <a href="#" class="card-btn" onclick="document.getElementById('availToggle').click(); return false;">Toggle Status</a>
    </div>

    <div class="dash-card">
        <div class="card-icon-wrap">&#128203;</div>
        <div class="card-title-text">Consultation Queue</div>
        <?php if ($queue_count > 0): ?>
        <div class="card-badge-count"><?= $queue_count ?> waiting</div>
        <?php endif; ?>
        <div class="card-desc">View waiting calls from eHealth Center doctors. Pick up queued consultations one by one when you are free.</div>
        <a href="telestudio_queue.php" class="card-btn">View Queue</a>
    </div>

    <div class="dash-card">
        <div class="card-icon-wrap">&#128196;</div>
        <div class="card-title-text">Consultation History</div>
        <?php if ($history_count > 0): ?>
        <div class="card-badge-count"><?= $history_count ?> records</div>
        <?php endif; ?>
        <div class="card-desc">Access records of all past teleconsultations, your specialist notes, diagnoses, and treatment plans given to patients.</div>
        <a href="telestudio_history.php" class="card-btn">View History</a>
    </div>

    <div class="dash-card">
        <div class="card-icon-wrap">&#128274;</div>
        <div class="card-title-text">Change Password</div>
        <div class="card-desc">Update your login credentials to keep your Telestudio account secure and protected at all times.</div>
        <a href="telestudio_change_password.php" class="card-btn">Change Password</a>
    </div>

</div>

<script>
const toggle     = document.getElementById('availToggle');
const badge      = document.getElementById('statusBadge');
const statusText = document.getElementById('statusText');

// Optimistic UI update + persist to DB
toggle.addEventListener('change', async () => {
    const isOn = toggle.checked;
    updateStatusUI(isOn);

    // Ripple animation on avail bar
    const bar = document.getElementById('availBar');
    bar.style.transition = 'background 0.4s';
    bar.style.background = isOn ? '#1a5c1e' : '#1a4d1e';

    try {
        const res = await fetch('telestudio_dashboard.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ available: isOn ? 1 : 0 })
        });
        const data = await res.json();
        if (!data.success) throw new Error('Save failed');
        showToast(isOn ? '✅ You are now Online — visible to eHealth doctors' : '⭕ You are now Offline');
    } catch(e) {
        // Revert UI if save failed
        toggle.checked = !isOn;
        updateStatusUI(!isOn);
        showToast('❌ Could not update status. Check connection.', true);
    }
});

function updateStatusUI(isOn) {
    statusText.textContent = isOn ? 'Online' : 'Offline';
    badge.className = 'status-badge' + (isOn ? ' online' : '');
}

// ── Toast notification ──────────────────────────────────────
function showToast(msg, isError = false) {
    let t = document.getElementById('liveToast');
    if (!t) {
        t = document.createElement('div');
        t.id = 'liveToast';
        t.style.cssText = `
            position:fixed; bottom:28px; left:50%; transform:translateX(-50%) translateY(80px);
            background:#1b5e20; color:white; padding:12px 24px; border-radius:24px;
            font-size:14px; font-weight:600; font-family:Inter,sans-serif;
            box-shadow:0 8px 28px rgba(0,0,0,0.25); z-index:9999;
            transition:transform 0.35s cubic-bezier(0.22,1,0.36,1), opacity 0.3s;
            opacity:0; pointer-events:none; white-space:nowrap;
        `;
        document.body.appendChild(t);
    }
    if (isError) t.style.background = '#b71c1c';
    else t.style.background = '#1b5e20';
    t.textContent = msg;
    t.style.transform = 'translateX(-50%) translateY(0)';
    t.style.opacity = '1';
    clearTimeout(t._timer);
    t._timer = setTimeout(() => {
        t.style.transform = 'translateX(-50%) translateY(80px)';
        t.style.opacity = '0';
    }, 3000);
}
</script>
<script src="../assets/doctor_notifications.js"></script>
</body>
</html>
