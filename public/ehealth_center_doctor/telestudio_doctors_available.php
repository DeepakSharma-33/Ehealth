<?php
require_once __DIR__ . '/../../config/config.php';
require_role('doctor', 'doctor_login.php');

$db = db();
$db->query("ALTER TABLE telestudio_doctors ADD COLUMN IF NOT EXISTS is_available TINYINT(1) DEFAULT 0");

$result = $db->query(
    "SELECT doctor_id, full_name, qualification, specialization, experience_years, is_available
     FROM telestudio_doctors ORDER BY is_available DESC, full_name ASC"
);
$doctors = [];
if ($result) while ($row = $result->fetch_assoc()) $doctors[] = $row;

$patientId       = $_GET['patientId'] ?? '';
$available_count = count(array_filter($doctors, fn($d) => $d['is_available']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Available TeleStudio Doctors — eHealth</title>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;600;700;800&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }
        :root {
            --g-dark:#1b5e20; --g-mid:#2e7d32; --g-light:#43a047;
            --g-pale:#e8f5e9; --g-border:#c8e0ca;
            --teal:#00897b;
            --gray-400:#9ca3af; --gray-500:#6b7280; --gray-700:#374151; --gray-900:#111827;
        }
        html,body { min-height:100vh; font-family:'Sora',sans-serif; background:#a8e6b0; }

        /* NAVBAR */
        .navbar {
            background:var(--g-mid); height:58px; padding:0 28px;
            display:flex; align-items:center; gap:14px;
            box-shadow:0 2px 8px rgba(0,0,0,0.15);
        }
        .nav-logo { width:34px; height:34px; background:white; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:14px; font-weight:800; color:var(--g-mid); }
        .nav-brand { font-size:16px; font-weight:800; letter-spacing:2px; color:white; margin-right:auto; }
        .nav-doctor { font-size:13px; color:rgba(255,255,255,0.8); font-weight:500; }

        /* LIVE INDICATOR in navbar */
        .live-pill {
            display:flex; align-items:center; gap:6px;
            background:rgba(255,255,255,0.12); border:1px solid rgba(255,255,255,0.2);
            padding:4px 12px; border-radius:16px; font-size:12px; font-weight:700; color:white;
        }
        .live-dot {
            width:7px; height:7px; border-radius:50%; background:#4caf50;
            animation:livePulse 1.5s infinite;
        }
        @keyframes livePulse { 0%,100%{opacity:1;transform:scale(1);} 50%{opacity:0.4;transform:scale(0.6);} }

        /* BREADCRUMB */
        .breadcrumb {
            background:rgba(255,255,255,0.3); padding:10px 28px;
            display:flex; align-items:center; gap:8px;
            font-size:13px; color:var(--g-dark); font-weight:500;
        }
        .breadcrumb a { color:var(--g-mid); text-decoration:none; font-weight:600; }
        .breadcrumb a:hover { text-decoration:underline; }
        .breadcrumb span { color:var(--gray-500); }

        /* PAGE */
        .page { max-width:1100px; margin:0 auto; padding:28px 24px 60px; }

        /* PAGE HEADER */
        .page-header {
            background:white; border-radius:16px; padding:26px 28px;
            margin-bottom:20px; display:flex; align-items:center; justify-content:space-between; gap:20px;
            box-shadow:0 3px 16px rgba(0,0,0,0.07);
        }
        .header-left h1 { font-size:22px; font-weight:800; color:var(--gray-900); margin-bottom:4px; }
        .header-left p { font-size:13px; color:var(--gray-500); }
        .header-stats { display:flex; gap:14px; flex-shrink:0; }
        .stat-pill {
            display:flex; flex-direction:column; align-items:center;
            background:var(--g-pale); border-radius:12px; padding:12px 20px;
            border:1px solid var(--g-border); min-width:88px;
            transition: all 0.4s ease;
        }
        .stat-pill .num { font-size:24px; font-weight:800; color:var(--g-mid); line-height:1; transition: all 0.3s; }
        .stat-pill .lbl { font-size:11px; font-weight:600; color:var(--gray-500); margin-top:3px; }
        .stat-pill.online-stat .num { color:#16a34a; }
        .stat-pill.online-stat { background:#f0fdf4; border-color:#bbf7d0; }

        /* PATIENT STRIP */
        .patient-strip {
            background:white; border-radius:12px; padding:13px 20px; margin-bottom:20px;
            display:flex; align-items:center; gap:14px;
            border-left:4px solid var(--teal);
            box-shadow:0 2px 10px rgba(0,0,0,0.06);
        }
        .patient-strip .lbl strong { font-size:14px; font-weight:700; color:var(--gray-900); }
        .patient-strip .lbl span { font-size:12px; color:var(--gray-500); margin-left:8px; }

        /* FILTER BAR */
        .filter-bar { display:flex; align-items:center; gap:10px; margin-bottom:18px; flex-wrap:wrap; }
        .filter-btn {
            padding:7px 18px; border-radius:20px; border:1.5px solid var(--g-border);
            background:white; font-size:13px; font-weight:600; font-family:'Sora',sans-serif;
            cursor:pointer; color:var(--gray-700); transition:all 0.15s;
        }
        .filter-btn.active,.filter-btn:hover { background:var(--g-mid); color:white; border-color:var(--g-mid); }
        .search-input {
            margin-left:auto; padding:8px 16px; border:1.5px solid var(--g-border);
            border-radius:20px; font-size:13px; font-family:'Sora',sans-serif;
            background:white; outline:none; width:220px; transition:border-color 0.2s, box-shadow 0.2s;
        }
        .search-input:focus { border-color:var(--g-mid); box-shadow:0 0 0 3px rgba(46,125,50,0.1); }

        /* GRID */
        .doctors-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:18px; }

        /* CARD */
        .doctor-card {
            background:white; border-radius:16px; padding:26px 22px 20px;
            box-shadow:0 3px 14px rgba(0,0,0,0.07);
            display:flex; flex-direction:column;
            position:relative; overflow:hidden;
            border:2px solid transparent;
            transition: transform 0.25s, box-shadow 0.25s, border-color 0.4s, opacity 0.4s;
        }
        .doctor-card:hover { transform:translateY(-3px); box-shadow:0 8px 28px rgba(0,0,0,0.12); }
        .doctor-card.available { border-color:#bbf7d0; }
        .doctor-card.offline { opacity:0.68; }

        /* Top strip */
        .card-strip { position:absolute; top:0; left:0; right:0; height:4px; background:#e5e7eb; transition:background 0.5s; }
        .doctor-card.available .card-strip { background:linear-gradient(90deg,#4caf50,#00897b); }

        /* Status flash animation when status changes */
        @keyframes cardFlash {
            0%   { box-shadow:0 0 0 0 rgba(76,175,80,0); }
            30%  { box-shadow:0 0 0 8px rgba(76,175,80,0.25); }
            100% { box-shadow:0 3px 14px rgba(0,0,0,0.07); }
        }
        .doctor-card.just-changed { animation:cardFlash 0.7s ease-out; }

        .doctor-avatar {
            width:60px; height:60px; border-radius:50%; background:var(--g-mid);
            display:flex; align-items:center; justify-content:center;
            font-size:22px; font-weight:800; color:white; margin-bottom:14px;
            transition:background 0.4s; box-shadow:0 3px 10px rgba(46,125,50,0.25);
        }
        .doctor-card.offline .doctor-avatar { background:#9ca3af; box-shadow:none; }

        .doctor-name { font-size:15px; font-weight:800; color:var(--gray-900); margin-bottom:3px; }
        .doctor-spec { font-size:12.5px; color:var(--teal); font-weight:600; margin-bottom:8px; }
        .doctor-qual {
            font-size:12px; color:var(--gray-500); background:var(--g-pale);
            padding:3px 10px; border-radius:8px; display:inline-block;
            margin-bottom:8px; font-weight:600; transition:background 0.4s;
        }
        .doctor-card.offline .doctor-qual { background:#f3f4f6; color:#9ca3af; }
        .doctor-exp { font-size:12px; color:var(--gray-500); margin-bottom:14px; }
        .doctor-exp strong { color:var(--gray-700); }

        /* Status row */
        .status-row { display:flex; align-items:center; gap:8px; margin-bottom:16px; }
        .status-dot { width:9px; height:9px; border-radius:50%; background:#9ca3af; flex-shrink:0; transition:background 0.4s, box-shadow 0.4s; }
        .doctor-card.available .status-dot { background:#16a34a; box-shadow:0 0 0 3px rgba(22,163,74,0.2); animation:statusPulse 2s infinite; }
        @keyframes statusPulse { 0%,100%{box-shadow:0 0 0 3px rgba(22,163,74,0.2);} 50%{box-shadow:0 0 0 6px rgba(22,163,74,0.07);} }
        .status-text { font-size:12.5px; font-weight:700; color:#9ca3af; transition:color 0.3s; }
        .doctor-card.available .status-text { color:#16a34a; }

        /* Action button */
        .btn-call {
            width:100%; padding:11px; border-radius:10px;
            font-size:13.5px; font-weight:700; font-family:'Sora',sans-serif;
            cursor:pointer; border:none; display:block; text-align:center;
            text-decoration:none; margin-top:auto;
            transition:background 0.2s, transform 0.15s, opacity 0.3s;
        }
        .btn-call.active-btn {
            background:var(--g-mid); color:white;
            box-shadow:0 3px 10px rgba(46,125,50,0.25);
        }
        .btn-call.active-btn:hover { background:var(--g-dark); transform:translateY(-1px); }
        .btn-call.offline-btn {
            background:#e5e7eb; color:#9ca3af;
            box-shadow:none; cursor:not-allowed;
        }

        /* EMPTY STATE */
        .empty-state {
            grid-column:1/-1; text-align:center; padding:60px 20px;
            background:white; border-radius:16px;
        }
        .empty-state .ei { font-size:48px; margin-bottom:16px; }
        .empty-state h3 { font-size:18px; font-weight:700; color:var(--gray-700); margin-bottom:8px; }
        .empty-state p { font-size:14px; color:var(--gray-400); }

        /* LIVE STATUS BAR at bottom */
        .live-bar {
            text-align:center; font-size:12px; color:rgba(0,0,0,0.45);
            margin-top:20px; font-weight:500; display:flex; align-items:center;
            justify-content:center; gap:8px;
        }
        .live-bar-dot { width:7px; height:7px; border-radius:50%; background:#4caf50; animation:livePulse 1.5s infinite; }

        /* TOAST */
        #toast {
            position:fixed; bottom:28px; left:50%; transform:translateX(-50%) translateY(80px);
            background:#1b5e20; color:white; padding:12px 24px; border-radius:24px;
            font-size:13.5px; font-weight:600; font-family:'Sora',sans-serif;
            box-shadow:0 8px 28px rgba(0,0,0,0.2); z-index:9999;
            transition:transform 0.35s cubic-bezier(0.22,1,0.36,1), opacity 0.3s;
            opacity:0; pointer-events:none; white-space:nowrap;
        }

        @media (max-width:900px) { .doctors-grid { grid-template-columns:repeat(2,1fr); } }
        @media (max-width:560px) { .doctors-grid { grid-template-columns:1fr; } .header-stats { display:none; } }
    </style>
</head>
<body>

<nav class="navbar">
    <a href="<?= htmlspecialchars(app_base_url()) ?>/" style="display:flex;align-items:center;gap:14px;line-height:1;margin-right:auto;text-decoration:none;"><img src="<?= htmlspecialchars(app_base_url()) ?>/public/assets/SRMS_TRUST_LOGO.png" alt="SRMST Trust Logo" style="height:48px;width:auto;display:block;object-fit:contain;filter:drop-shadow(0 1px 3px rgba(0,0,0,.35));"><span style="font-size:22px;font-weight:800;letter-spacing:1px;color:#fff;text-shadow:0 1px 2px rgba(0,0,0,.35);white-space:nowrap;">SRMS EHEALTH</span></a>
    <div class="live-pill"><div class="live-dot"></div><span>Live</span></div>
    <a href="<?= htmlspecialchars(app_base_url()) ?>/about.php" style="color:rgba(255,255,255,0.85);text-decoration:none;font-size:13px;font-weight:600;">ABOUT</a>
    <a href="<?= htmlspecialchars(app_base_url()) ?>/contact.php" style="color:rgba(255,255,255,0.85);text-decoration:none;font-size:13px;font-weight:600;">CONTACT</a>
    <span class="nav-doctor"><?= htmlspecialchars(get_session_name()) ?></span>
</nav>

<div class="breadcrumb">
    <a href="patient_queue.php">Patient Queue</a>
    <span>›</span>
    <?php if ($patientId): ?>
    <a href="consultation_select.php?patientId=<?= htmlspecialchars($patientId) ?>">Select Consultation</a>
    <span>›</span>
    <?php endif; ?>
    <span>TeleStudio Doctors</span>
</div>

<div class="page">

    <div class="page-header">
        <div class="header-left">
            <h1>&#128249; Available TeleStudio Doctors</h1>
            <p>Select a specialist for Patient<?= $patientId ? ' <strong>' . htmlspecialchars($patientId) . '</strong>' : '' ?> — status updates live, no refresh needed</p>
        </div>
        <div class="header-stats">
            <div class="stat-pill online-stat">
                <div class="num" id="onlineCount"><?= $available_count ?></div>
                <div class="lbl">Online Now</div>
            </div>
            <div class="stat-pill">
                <div class="num" id="totalCount"><?= count($doctors) ?></div>
                <div class="lbl">Total Doctors</div>
            </div>
        </div>
    </div>

    <?php if ($patientId): ?>
    <div class="patient-strip">
        <span style="font-size:22px">&#128100;</span>
        <div class="lbl">
            <strong>Patient ID: <?= htmlspecialchars($patientId) ?></strong>
            <span>Teleconsultation requested</span>
        </div>
    </div>
    <?php endif; ?>

    <div class="filter-bar">
        <button class="filter-btn active" onclick="applyFilter('all',this)">All Doctors</button>
        <button class="filter-btn" onclick="applyFilter('available',this)">Online Only</button>
        <button class="filter-btn" onclick="applyFilter('offline',this)">Offline</button>
        <input type="text" class="search-input" id="searchInput" placeholder="&#128269; Search name or specialty…" oninput="applySearch(this.value)">
    </div>

    <div class="doctors-grid" id="doctorsGrid">
        <?php foreach ($doctors as $doc): ?>
        <?php
            $avail = (bool)$doc['is_available'];
            $init  = strtoupper(substr($doc['full_name'], 0, 1));
        ?>
        <div class="doctor-card <?= $avail ? 'available' : 'offline' ?>"
             id="card-<?= htmlspecialchars($doc['doctor_id']) ?>"
             data-docid="<?= htmlspecialchars($doc['doctor_id']) ?>"
             data-name="<?= strtolower(htmlspecialchars($doc['full_name'])) ?>"
             data-spec="<?= strtolower(htmlspecialchars($doc['specialization'] ?? '')) ?>"
             data-status="<?= $avail ? 'available' : 'offline' ?>">
            <div class="card-strip"></div>
            <div class="doctor-avatar"><?= $init ?></div>
            <div class="doctor-name"><?= htmlspecialchars($doc['full_name']) ?></div>
            <div class="doctor-spec"><?= htmlspecialchars($doc['specialization'] ?? 'General') ?></div>
            <?php if (!empty($doc['qualification'])): ?>
            <div class="doctor-qual"><?= htmlspecialchars($doc['qualification']) ?></div>
            <?php endif; ?>
            <?php if ($doc['experience_years'] > 0): ?>
            <div class="doctor-exp"><strong><?= (int)$doc['experience_years'] ?> years</strong> experience</div>
            <?php endif; ?>
            <div class="status-row">
                <div class="status-dot"></div>
                <span class="status-text"><?= $avail ? 'Online — Available' : 'Offline' ?></span>
            </div>
            <?php if ($avail): ?>
            <a class="btn-call active-btn" href="teleconsultation.php?patientId=<?= urlencode($patientId) ?>&doctorId=<?= urlencode($doc['doctor_id']) ?>&doctorName=<?= urlencode($doc['full_name']) ?>">&#128222; Start Consultation</a>
            <?php else: ?>
            <button class="btn-call offline-btn" disabled>Currently Offline</button>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php if (empty($doctors)): ?>
        <div class="empty-state">
            <div class="ei">&#128249;</div>
            <h3>No TeleStudio doctors registered yet</h3>
            <p>Doctors will appear here once they register on the TeleStudio portal.</p>
        </div>
        <?php endif; ?>
    </div>

    <div class="live-bar">
        <div class="live-bar-dot"></div>
        <span>Live — updates instantly when doctors go online or offline</span>
        <span id="lastSeen" style="color:#aaa"></span>
    </div>

</div>

<div id="toast"></div>

<script>
// ── CONFIG ─────────────────────────────────────────────────
const PATIENT_ID = <?= json_encode($patientId) ?>;
const SSE_URL    = 'telestudio_availability_stream.php';

let currentFilter = 'all';
let currentSearch = '';
let prevStates    = {};  // docId -> boolean (was available)

// Capture initial states
document.querySelectorAll('.doctor-card').forEach(card => {
    prevStates[card.dataset.docid] = card.dataset.status === 'available';
});

// ── SSE CONNECTION ──────────────────────────────────────────
let sse;
let sseRetryDelay = 2000;

function connectSSE() {
    if (sse) sse.close();
    sse = new EventSource(SSE_URL);

    sse.addEventListener('snapshot', e => {
        const data = JSON.parse(e.data);
        renderDoctors(data.doctors, false); // silent on initial load
    });

    sse.addEventListener('update', e => {
        const data = JSON.parse(e.data);
        renderDoctors(data.doctors, true); // show toast on changes
    });

    sse.onopen = () => {
        sseRetryDelay = 2000;
        updateLastSeen();
    };

    sse.onerror = () => {
        sse.close();
        // Reconnect with backoff
        setTimeout(() => {
            sseRetryDelay = Math.min(sseRetryDelay * 1.5, 30000);
            connectSSE();
        }, sseRetryDelay);
    };
}

connectSSE();

// ── Kill SSE on navigation to prevent connection stacking ──
window.addEventListener('beforeunload', () => { if (sse) sse.close(); });
document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
        if (sse) sse.close();
    } else {
        connectSSE(); // reconnect when tab comes back
    }
});

// ── RENDER DOCTORS FROM SSE DATA ───────────────────────────
function renderDoctors(doctors, notify) {
    const grid = document.getElementById('doctorsGrid');

    // Build a map of existing cards
    const existingCards = {};
    grid.querySelectorAll('.doctor-card').forEach(c => { existingCards[c.dataset.docid] = c; });

    let onlineCount = 0;

    doctors.forEach(doc => {
        const isAvail = !!doc.is_available;
        if (isAvail) onlineCount++;
        const wasAvail = prevStates[doc.doctor_id];
        const changed  = wasAvail !== undefined && wasAvail !== isAvail;

        let card = existingCards[doc.doctor_id];

        if (!card) {
            // New doctor appeared — create card
            card = buildCard(doc);
            grid.appendChild(card);
        } else {
            // Update existing card
            updateCard(card, doc, isAvail, changed, notify);
        }

        prevStates[doc.doctor_id] = isAvail;
    });

    // Update counters
    document.getElementById('onlineCount').textContent = onlineCount;
    document.getElementById('totalCount').textContent  = doctors.length;

    applyVisibility();
    updateLastSeen();
}

function updateCard(card, doc, isAvail, changed, notify) {
    const wasAvail = card.dataset.status === 'available';
    if (wasAvail === isAvail) return; // no change needed

    // Swap classes
    card.classList.remove('available', 'offline');
    card.classList.add(isAvail ? 'available' : 'offline');
    card.dataset.status = isAvail ? 'available' : 'offline';

    // Update avatar color handled by CSS
    const statusDot  = card.querySelector('.status-dot');
    const statusText = card.querySelector('.status-text');
    const btnWrap    = card.querySelector('.btn-call');

    statusText.textContent = isAvail ? 'Online — Available' : 'Offline';

    if (isAvail) {
        const a = document.createElement('a');
        a.href      = `teleconsultation.php?patientId=${encodeURIComponent(PATIENT_ID)}&doctorId=${encodeURIComponent(doc.doctor_id)}&doctorName=${encodeURIComponent(doc.full_name || '')}`;
        a.className = 'btn-call active-btn';
        a.innerHTML = '&#128222; Start Consultation';
        btnWrap.replaceWith(a);
    } else {
        const btn = document.createElement('button');
        btn.className = 'btn-call offline-btn';
        btn.disabled  = true;
        btn.textContent = 'Currently Offline';
        btnWrap.replaceWith(btn);
    }

    // Flash animation
    card.classList.remove('just-changed');
    void card.offsetWidth; // reflow
    card.classList.add('just-changed');

    if (notify && changed) {
        const name = doc.full_name;
        if (isAvail) showToast(`🟢 ${name} is now Online`);
        else         showToast(`⭕ ${name} went Offline`, false, '#555');
    }
}

function buildCard(doc) {
    const isAvail = !!doc.is_available;
    const init    = doc.full_name.charAt(0).toUpperCase();
    const div     = document.createElement('div');
    div.className = `doctor-card ${isAvail ? 'available' : 'offline'} just-changed`;
    div.id        = `card-${doc.doctor_id}`;
    div.dataset.docid  = doc.doctor_id;
    div.dataset.name   = doc.full_name.toLowerCase();
    div.dataset.spec   = (doc.specialization || '').toLowerCase();
    div.dataset.status = isAvail ? 'available' : 'offline';

    div.innerHTML = `
        <div class="card-strip"></div>
        <div class="doctor-avatar">${init}</div>
        <div class="doctor-name">${esc(doc.full_name)}</div>
        <div class="doctor-spec">${esc(doc.specialization || 'General')}</div>
        ${doc.qualification ? `<div class="doctor-qual">${esc(doc.qualification)}</div>` : ''}
        ${doc.experience_years > 0 ? `<div class="doctor-exp"><strong>${doc.experience_years} years</strong> experience</div>` : ''}
        <div class="status-row">
            <div class="status-dot"></div>
            <span class="status-text">${isAvail ? 'Online — Available' : 'Offline'}</span>
        </div>
        ${isAvail
            ? `<a href="teleconsultation.php?patientId=${encodeURIComponent(PATIENT_ID)}&doctorId=${encodeURIComponent(doc.doctor_id)}&doctorName=${encodeURIComponent(doc.full_name || '')}" class="btn-call active-btn">&#128222; Start Consultation</a>`
            : `<button class="btn-call offline-btn" disabled>Currently Offline</button>`
        }
    `;
    return div;
}

function esc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── FILTER & SEARCH ─────────────────────────────────────────
function applyFilter(type, btn) {
    currentFilter = type;
    document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    applyVisibility();
}

function applySearch(val) {
    currentSearch = val.toLowerCase().trim();
    applyVisibility();
}

function applyVisibility() {
    document.querySelectorAll('.doctor-card').forEach(card => {
        const matchFilter = currentFilter === 'all' || card.dataset.status === currentFilter;
        const matchSearch = !currentSearch ||
            card.dataset.name.includes(currentSearch) ||
            card.dataset.spec.includes(currentSearch);
        card.style.display = (matchFilter && matchSearch) ? '' : 'none';
    });
}

// ── TOAST ───────────────────────────────────────────────────
let toastTimer;
function showToast(msg, isError = false, bg = null) {
    const t = document.getElementById('toast');
    t.textContent   = msg;
    t.style.background = bg || (isError ? '#b71c1c' : '#1b5e20');
    t.style.transform  = 'translateX(-50%) translateY(0)';
    t.style.opacity    = '1';
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => {
        t.style.transform = 'translateX(-50%) translateY(80px)';
        t.style.opacity   = '0';
    }, 3500);
}

// ── LAST SEEN ────────────────────────────────────────────────
function updateLastSeen() {
    const el = document.getElementById('lastSeen');
    if (el) el.textContent = '· Last updated ' + new Date().toLocaleTimeString([], {hour:'numeric',minute:'2-digit'});
}
</script>
<script src="../assets/doctor_notifications.js"></script>
</body>
</html>

