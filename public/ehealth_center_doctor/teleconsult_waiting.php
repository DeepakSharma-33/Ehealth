<?php
require_once __DIR__ . '/../../config/config.php';
require_role('doctor', 'doctor_login.php');

if (isset($_POST['logout'])) {
    logout_user();
    header('Location: doctor_login.php');
    exit;
}

$doctor_id = (string)get_session_id();
$doctor_name = htmlspecialchars(get_session_name() ?: 'Doctor');
$selected_sid = (int)($_GET['sessionId'] ?? 0);

function time_ago(?string $dt): string {
    if (!$dt) return 'just now';
    $ts = strtotime($dt);
    if (!$ts) return 'just now';
    $diff = max(0, time() - $ts);
    if ($diff < 60) return $diff . ' sec ago';
    if ($diff < 3600) return floor($diff / 60) . ' min ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hr ago';
    return floor($diff / 86400) . ' day ago';
}

$db = db();
$stmt = $db->prepare(
    "SELECT ts.id, ts.patient_id, ts.patient_name, ts.telestudio_doctor_id, ts.telestudio_doctor_name,
            ts.teleconsult_reason, ts.chief_complaint, ts.clinical_notes,
            ts.status, ts.accept_wait_message, ts.reject_message, ts.whereby_room_url,
            ts.created_at, ts.accepted_at, ts.started_at,
            p.age, p.gender, p.mobile_no, p.photo_filename,
            v.bp_systolic, v.bp_diastolic, v.heart_rate, v.temperature, v.spo2
     FROM teleconsult_sessions ts
     LEFT JOIN patients p ON p.patient_id = ts.patient_id
     LEFT JOIN patient_vitals v ON v.id = (
         SELECT id FROM patient_vitals WHERE patient_id = ts.patient_id ORDER BY recorded_at DESC LIMIT 1
     )
     WHERE ts.ehealth_doctor_id = ?
       AND ts.status IN ('waiting','accepted','active')
     ORDER BY FIELD(ts.status,'active','accepted','waiting'), ts.created_at ASC"
);
$stmt->bind_param('s', $doctor_id);
$stmt->execute();
$sessions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$queue_count = count($sessions);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teleconsult Waiting Queue - eHealth</title>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root {
            --g-dark:#1b5e20; --g-mid:#2e7d32; --g-pale:#e8f5e9;
            --teal:#00897b; --text-dark:#111827; --text-mid:#4b5563; --text-soft:#6b7280;
        }
        body { font-family:'Sora',sans-serif; background:#a8e6b0; min-height:100vh; }

        .navbar {
            background:var(--g-mid); height:58px; padding:0 28px;
            display:flex; align-items:center; gap:12px; box-shadow:0 2px 8px rgba(0,0,0,0.15);
        }
        .nav-logo { width:34px; height:34px; background:#fff; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:14px; font-weight:800; color:var(--g-mid); }
        .nav-brand { font-size:16px; font-weight:800; letter-spacing:2px; color:#fff; margin-right:auto; }
        .nav-link { color:rgba(255,255,255,0.88); text-decoration:none; font-size:13px; font-weight:600; }
        .nav-link:hover { color:#fff; }
        .nav-doctor { color:rgba(255,255,255,0.88); font-size:13px; }
        .btn-logout {
            border:1.5px solid rgba(255,255,255,0.7); background:transparent; color:#fff;
            border-radius:18px; padding:6px 14px; font-size:12px; font-weight:700; cursor:pointer;
            font-family:'Sora',sans-serif;
        }

        .page { max-width:980px; margin:0 auto; padding:24px 20px 50px; }
        .head {
            background:#fff; border-radius:16px; padding:20px 22px; margin-bottom:18px;
            box-shadow:0 3px 14px rgba(0,0,0,0.08); display:flex; align-items:center; gap:12px; flex-wrap:wrap;
        }
        .head h1 { font-size:24px; font-weight:800; color:var(--text-dark); }
        .head p { font-size:13px; color:var(--text-soft); }
        .count-pill {
            margin-left:auto; background:var(--g-pale); color:var(--g-mid); border:1px solid #c8e0ca;
            padding:8px 14px; border-radius:18px; font-size:12px; font-weight:800;
        }

        .tools { display:flex; gap:10px; align-items:center; margin-bottom:14px; flex-wrap:wrap; }
        .search {
            flex:1; min-width:220px; padding:10px 14px; border:2px solid #c8e0ca; border-radius:10px;
            font-size:13px; font-family:'Sora',sans-serif; outline:none; background:#fff;
        }
        .search:focus { border-color:var(--g-mid); }
        .btn-refresh {
            padding:10px 16px; border:none; border-radius:10px; background:var(--g-mid); color:#fff;
            font-family:'Sora',sans-serif; font-size:13px; font-weight:700; cursor:pointer;
        }

        .queue-wrap { display:grid; gap:14px; }
        .card {
            background:#fff; border-radius:16px; padding:20px 22px; box-shadow:0 3px 14px rgba(0,0,0,0.08);
            border-left:5px solid #e5e7eb;
        }
        .card.waiting { border-left-color:#f59e0b; }
        .card.accepted { border-left-color:#16a34a; }
        .card.active { border-left-color:var(--teal); }
        .card.selected { outline:2px solid var(--g-mid); }

        .top { display:flex; align-items:flex-start; gap:14px; margin-bottom:12px; }
        .photo, .avatar {
            width:54px; height:54px; border-radius:50%; flex-shrink:0;
            display:flex; align-items:center; justify-content:center;
        }
        .photo { object-fit:cover; border:2px solid #e5e7eb; }
        .avatar { background:#e8f5e9; color:var(--g-mid); font-size:22px; font-weight:800; border:2px solid #c8e0ca; }
        .pname { font-size:17px; font-weight:800; color:var(--text-dark); }
        .pid { display:inline-block; margin-top:4px; background:#e8f5e9; color:var(--g-mid); border-radius:14px; padding:3px 10px; font-size:11px; font-weight:700; }
        .meta { margin-top:8px; font-size:12px; color:var(--text-mid); display:flex; gap:14px; flex-wrap:wrap; }
        .status {
            margin-left:auto; border-radius:16px; padding:6px 12px; font-size:11px; font-weight:800; text-transform:uppercase;
        }
        .status.waiting { background:#fef3c7; color:#92400e; }
        .status.accepted { background:#dcfce7; color:#166534; }
        .status.active { background:#e0f2f1; color:#0f766e; }

        .note {
            background:#f9fafb; border:1px solid #e5e7eb; border-radius:10px;
            padding:10px 12px; margin-bottom:10px; font-size:12px; color:var(--text-mid);
        }
        .note b { color:#111827; }
        .message {
            margin-bottom:10px; background:#eef6ff; border:1px solid #bfdbfe; color:#1e3a8a;
            border-radius:10px; padding:9px 12px; font-size:12px; font-weight:600;
        }
        .vitals { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:12px; }
        .chip {
            background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; padding:4px 10px;
            font-size:11.5px; color:#166534; font-weight:700;
        }
        .chip span { font-weight:500; color:#4b5563; }

        .footer {
            display:flex; align-items:center; gap:10px; flex-wrap:wrap;
        }
        .doctor-line { font-size:12px; color:var(--text-soft); margin-right:auto; }
        .doctor-msg-bottom {
            margin-top:10px; background:#eef6ff; border:1px solid #bfdbfe; color:#1e3a8a;
            border-radius:10px; padding:9px 12px; font-size:12px; font-weight:600;
        }
        .btn {
            text-decoration:none; border:none; border-radius:10px; padding:9px 14px; font-size:12px; font-weight:800;
            font-family:'Sora',sans-serif; cursor:pointer;
        }
        .btn-secondary { background:#f3f4f6; color:#374151; }
        .btn-primary { background:var(--teal); color:#fff; }
        .btn-disabled { background:#e5e7eb; color:#9ca3af; cursor:not-allowed; }

        .empty {
            background:#fff; border-radius:16px; padding:48px 20px; text-align:center;
            box-shadow:0 3px 14px rgba(0,0,0,0.08);
        }
        .empty h3 { font-size:20px; color:#4b5563; margin-bottom:8px; }
        .empty p { font-size:14px; color:#6b7280; margin-bottom:16px; }
        .empty a {
            display:inline-block; text-decoration:none; background:var(--g-mid); color:#fff;
            border-radius:10px; padding:10px 16px; font-size:13px; font-weight:700;
        }

        @media (max-width:640px) {
            .navbar { padding:0 14px; }
            .page { padding:16px 12px 38px; }
            .top { gap:10px; }
            .status { margin-left:0; }
        }
    </style>
</head>
<body>

<nav class="navbar">
    <a href="<?= htmlspecialchars(app_base_url()) ?>/" style="display:flex;align-items:center;gap:14px;line-height:1;margin-right:auto;text-decoration:none;"><img src="<?= htmlspecialchars(app_base_url()) ?>/public/assets/SRMS_TRUST_LOGO.png" alt="SRMST Trust Logo" style="height:48px;width:auto;display:block;object-fit:contain;filter:drop-shadow(0 1px 3px rgba(0,0,0,.35));"><span style="font-size:22px;font-weight:800;letter-spacing:1px;color:#fff;text-shadow:0 1px 2px rgba(0,0,0,.35);white-space:nowrap;">SRMS EHEALTH</span></a>
    <a class="nav-link" href="doctor_dashboard.php">&#8592; Dashboard</a>
    <a class="nav-link" href="<?= htmlspecialchars(app_base_url()) ?>/about.php">ABOUT</a>
    <a class="nav-link" href="<?= htmlspecialchars(app_base_url()) ?>/contact.php">CONTACT</a>
    <span class="nav-doctor"><?= $doctor_name ?></span>
    <form method="POST" style="display:inline">
        <button type="submit" name="logout" class="btn-logout">LOGOUT</button>
    </form>
</nav>

<div class="page">
    <div class="head">
        <div>
            <h1>Teleconsult Waiting Queue</h1>
            <p>All referred patients stay in queue until consultation is completed or cancelled.</p>
        </div>
        <div class="count-pill" id="queueCount"><?= $queue_count ?> session<?= $queue_count === 1 ? '' : 's' ?></div>
    </div>

    <div class="tools">
        <input type="text" class="search" id="searchInput" placeholder="Search by patient name, ID, mobile..." oninput="filterCards()">
        <button class="btn-refresh" type="button" onclick="window.location.reload()">Refresh</button>
    </div>

    <div class="queue-wrap" id="queueContainer">
        <?php if (empty($sessions)): ?>
            <div class="empty">
                <h3>No Active Teleconsult Sessions</h3>
                <p>No waiting/accepted/active teleconsultation found for you right now.</p>
                <a href="patient_queue.php">Go to Patient Queue</a>
            </div>
        <?php else: ?>
            <?php foreach ($sessions as $s):
                $sid = (int)$s['id'];
                $status = $s['status'];
                $is_selected = ($selected_sid > 0 && $selected_sid === $sid);
                $photo = patient_photo_url($s['photo_filename'] ?? null);
                $patient_name = htmlspecialchars($s['patient_name'] ?? '');
                $patient_id = htmlspecialchars($s['patient_id'] ?? '');
                $bp = (!empty($s['bp_systolic']) && !empty($s['bp_diastolic'])) ? ($s['bp_systolic'] . '/' . $s['bp_diastolic'] . ' mmHg') : '—';
                $time_label = time_ago($s['created_at'] ?? null);
            ?>
            <div
                class="card <?= htmlspecialchars($status) ?><?= $is_selected ? ' selected' : '' ?>"
                id="card-<?= $sid ?>"
                data-search="<?= strtolower($patient_name . ' ' . $patient_id . ' ' . htmlspecialchars($s['mobile_no'] ?? '')) ?>"
            >
                <div class="top">
                    <?php if ($photo): ?>
                        <img src="<?= $photo ?>" class="photo" alt="patient">
                    <?php else: ?>
                        <div class="avatar"><?= strtoupper(substr($patient_name ?: 'P', 0, 1)) ?></div>
                    <?php endif; ?>
                    <div style="flex:1">
                        <div class="pname"><?= $patient_name ?></div>
                        <div class="pid"><?= $patient_id ?></div>
                        <div class="meta">
                            <span>Age: <?= htmlspecialchars((string)($s['age'] ?? '—')) ?></span>
                            <span>Gender: <?= htmlspecialchars((string)($s['gender'] ?? '—')) ?></span>
                            <span>Mobile: <?= htmlspecialchars((string)($s['mobile_no'] ?? '—')) ?></span>
                            <span>Requested: <?= htmlspecialchars($time_label) ?></span>
                        </div>
                    </div>
                    <div class="status <?= htmlspecialchars($status) ?>"><?= htmlspecialchars($status) ?></div>
                </div>

                <?php if (!empty($s['teleconsult_reason']) || !empty($s['chief_complaint']) || !empty($s['clinical_notes'])): ?>
                    <div class="note">
                        <?php if (!empty($s['teleconsult_reason'])): ?><b>Reason:</b> <?= htmlspecialchars($s['teleconsult_reason']) ?><br><?php endif; ?>
                        <?php if (!empty($s['chief_complaint'])): ?><b>Complaint:</b> <?= htmlspecialchars($s['chief_complaint']) ?><br><?php endif; ?>
                        <?php if (!empty($s['clinical_notes'])): ?><b>Clinical Notes:</b> <?= htmlspecialchars($s['clinical_notes']) ?><?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="vitals">
                    <div class="chip">BP <span><?= htmlspecialchars($bp) ?></span></div>
                    <div class="chip">HR <span><?= htmlspecialchars((string)($s['heart_rate'] ?: '—')) ?> bpm</span></div>
                    <div class="chip">Temp <span><?= htmlspecialchars((string)($s['temperature'] ?: '—')) ?> &deg;C</span></div>
                    <div class="chip">SpO2 <span><?= htmlspecialchars((string)($s['spo2'] ?: '—')) ?>%</span></div>
                </div>

                <div class="footer">
                    <div class="doctor-line">TeleStudio Specialist: <b><?= htmlspecialchars((string)($s['telestudio_doctor_name'] ?? '—')) ?></b></div>

                    <?php if ($status === 'active'): ?>
                        <a class="btn btn-primary" href="../telestudio_doctor/teleconsult_room.php?sessionId=<?= $sid ?>&role=ehealth">Join Call Now</a>
                    <?php elseif ($status === 'accepted'): ?>
                        <button class="btn btn-secondary" type="button" onclick="window.location.href='teleconsult_waiting.php?sessionId=<?= $sid ?>'">Open This Session</button>
                    <?php else: ?>
                        <button class="btn btn-disabled" type="button" disabled>Waiting for Acceptance</button>
                    <?php endif; ?>
                </div>

                <?php if (!empty($s['accept_wait_message'])): ?>
                    <div class="doctor-msg-bottom">Message from doctor: <?= htmlspecialchars($s['accept_wait_message']) ?></div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<script>
const SELECTED_SESSION_ID = <?= $selected_sid ?>;
const ACTIONS_URL = '../telestudio_doctor/teleconsult_actions.php';
const QUEUE_RING_ENABLED = false;
const QUEUE_RING_URLS = ['../assets/ringtone.mp3', '../assets/notification.wav', '../assets/notification.mp3'];
let queueRingAudio = null;
let queueRingIdx = 0;
let queueRingOn = false;

function queueRingUrl() {
    return QUEUE_RING_URLS[Math.min(queueRingIdx, QUEUE_RING_URLS.length - 1)];
}

function ensureQueueRingAudio() {
    if (queueRingAudio) return queueRingAudio;
    try {
        queueRingAudio = new Audio(queueRingUrl());
        queueRingAudio.loop = true;
        queueRingAudio.preload = 'auto';
        queueRingAudio.addEventListener('error', () => {
            if (queueRingIdx < QUEUE_RING_URLS.length - 1) {
                queueRingIdx++;
                queueRingAudio = null;
            }
        }, { once: true });
    } catch (_) {
        queueRingAudio = null;
    }
    return queueRingAudio;
}

function warmupQueueRing() {
    if (!QUEUE_RING_ENABLED) return;
    const audio = ensureQueueRingAudio();
    if (!audio) return;
    const p = audio.play();
    if (p && typeof p.then === 'function') {
        p.then(() => {
            audio.pause();
            audio.currentTime = 0;
            queueRingOn = false;
        }).catch(() => {});
    }
    syncQueueRing();
}

function startQueueRing() {
    if (!QUEUE_RING_ENABLED) return;
    if (queueRingOn) return;
    const audio = ensureQueueRingAudio();
    if (!audio) return;
    try { audio.currentTime = 0; } catch (_) {}
    const p = audio.play();
    if (p && typeof p.then === 'function') {
        p.then(() => { queueRingOn = true; }).catch(() => {
            if (queueRingIdx < QUEUE_RING_URLS.length - 1) {
                queueRingIdx++;
                queueRingAudio = null;
                startQueueRing();
            }
        });
    } else {
        queueRingOn = true;
    }
}

function stopQueueRing() {
    if (!QUEUE_RING_ENABLED) return;
    if (!queueRingAudio) return;
    try {
        queueRingAudio.pause();
        queueRingAudio.currentTime = 0;
    } catch (_) {}
    queueRingOn = false;
}

function activeSessionCount() {
    return document.querySelectorAll('#queueContainer .card.active').length;
}

function syncQueueRing() {
    if (!QUEUE_RING_ENABLED) return;
    if (activeSessionCount() > 0) startQueueRing();
    else stopQueueRing();
}

window.stopQueueRing = stopQueueRing;
['click', 'keydown', 'touchstart'].forEach((evt) => {
    window.addEventListener(evt, warmupQueueRing, { once: true, passive: true });
});

document.querySelectorAll('#queueContainer .btn-primary').forEach((el) => {
    el.addEventListener('click', stopQueueRing);
});

function markActiveCallsReceived() {
    const activeCards = document.querySelectorAll('#queueContainer .card.active[id^="card-"]');
    activeCards.forEach((card) => {
        const sid = (card.id || '').replace('card-', '').trim();
        if (!sid) return;
        fetch(ACTIONS_URL + '?action=mark_received', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ sessionId: Number(sid) })
        }).catch(() => {});
    });
}

function filterCards() {
    const q = document.getElementById('searchInput').value.toLowerCase().trim();
    const cards = document.querySelectorAll('#queueContainer .card');
    let visible = 0;
    cards.forEach((card) => {
        const ok = !q || card.dataset.search.includes(q);
        card.style.display = ok ? '' : 'none';
        if (ok) visible++;
    });
    const countEl = document.getElementById('queueCount');
    if (countEl) countEl.textContent = `${visible} session${visible === 1 ? '' : 's'}`;
}

if (SELECTED_SESSION_ID > 0) {
    const el = document.getElementById('card-' + SELECTED_SESSION_ID);
    if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

syncQueueRing();
markActiveCallsReceived();
document.addEventListener('visibilitychange', () => {
    if (document.hidden) stopQueueRing();
    else {
        syncQueueRing();
        markActiveCallsReceived();
    }
});
window.addEventListener('beforeunload', stopQueueRing);

setTimeout(() => window.location.reload(), 12000);
</script>
<script src="../assets/doctor_notifications.js"></script>
</body>
</html>
