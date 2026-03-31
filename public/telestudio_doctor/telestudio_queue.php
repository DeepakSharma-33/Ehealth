<?php
require_once __DIR__ . '/../../config/config.php';
require_role('telestudio_doctor', 'telestudio_doctor_login.php');
$db  = db();
$uid = (string)get_session_id();

// Fetch active sessions for this telestudio doctor
$result = $db->prepare(
    'SELECT ts.*, p.age, p.gender, p.mobile_no, p.photo_filename,
            ehd.mobile_no AS ehealth_doctor_mobile,
            v.bp_systolic, v.bp_diastolic, v.heart_rate, v.temperature, v.spo2
     FROM teleconsult_sessions ts
     LEFT JOIN patients p ON p.patient_id = ts.patient_id
     LEFT JOIN ehealth_center_doctors ehd ON ehd.doctor_id = ts.ehealth_doctor_id
     LEFT JOIN patient_vitals v ON v.id = (
         SELECT id FROM patient_vitals WHERE patient_id = ts.patient_id ORDER BY recorded_at DESC LIMIT 1
     )
     WHERE ts.telestudio_doctor_id = ?
       AND ts.status IN (\'waiting\',\'accepted\',\'active\')
     ORDER BY ts.created_at ASC'
);
$result->bind_param('s', $uid);
$result->execute();
$sessions = $result->get_result()->fetch_all(MYSQLI_ASSOC);
$result->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Consultation Queue — TeleStudio</title>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;600;700;800&display=swap" rel="stylesheet">
    <style>
        *,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
        :root{--g-dark:#1b5e20;--g-mid:#2e7d32;--g-pale:#e8f5e9;--teal:#00897b;--gray-400:#9ca3af;--gray-500:#6b7280;--gray-900:#111827;}
        body{font-family:'Sora',sans-serif;background:#a8e6b0;min-height:100vh;}

        .navbar{background:var(--g-mid);height:58px;padding:0 28px;display:flex;align-items:center;gap:12px;box-shadow:0 2px 8px rgba(0,0,0,0.15);}
        .nav-logo{width:34px;height:34px;background:white;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:800;color:var(--g-mid);}
        .nav-brand{font-size:16px;font-weight:800;letter-spacing:2px;color:white;margin-right:auto;}
        .nav-back{color:rgba(255,255,255,0.85);text-decoration:none;font-size:13px;font-weight:600;}
        .nav-dr{font-size:13px;color:rgba(255,255,255,0.8);}

        /* LIVE PILL */
        .live-pill{display:flex;align-items:center;gap:6px;background:rgba(255,255,255,0.12);border:1px solid rgba(255,255,255,0.2);padding:4px 12px;border-radius:16px;font-size:12px;font-weight:700;color:white;}
        .live-dot{width:7px;height:7px;border-radius:50%;background:#4caf50;animation:lp 1.5s infinite;}
        @keyframes lp{0%,100%{opacity:1;}50%{opacity:0.3;}}

        .page{max-width:960px;margin:0 auto;padding:28px 20px 60px;}
        .page-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px;}
        .page-title{font-size:22px;font-weight:800;color:#1a3d1c;}
        .queue-count{background:white;padding:8px 18px;border-radius:20px;font-size:13px;font-weight:700;color:var(--g-mid);box-shadow:0 2px 8px rgba(0,0,0,0.07);}

        /* SESSION CARD */
        .session-card{
            background:white;border-radius:16px;padding:24px;margin-bottom:16px;
            box-shadow:0 3px 14px rgba(0,0,0,0.07);
            border-left:5px solid #e5e7eb;
            transition:transform 0.2s,box-shadow 0.2s,border-color 0.3s;
            animation:slideIn 0.4s cubic-bezier(0.22,1,0.36,1);
        }
        @keyframes slideIn{from{opacity:0;transform:translateY(16px);}to{opacity:1;transform:translateY(0);}}
        .session-card:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(0,0,0,0.11);}
        .session-card[data-status="waiting"]{border-left-color:#f59e0b;}
        .session-card[data-status="accepted"]{border-left-color:#16a34a;}
        .session-card[data-status="active"]{border-left-color:var(--teal);}

        .card-top{display:flex;align-items:flex-start;gap:16px;margin-bottom:18px;}
        .patient-ava{width:56px;height:56px;border-radius:50%;background:var(--g-mid);display:flex;align-items:center;justify-content:center;font-size:22px;color:white;font-weight:800;flex-shrink:0;}
        .patient-photo{width:56px;height:56px;border-radius:50%;object-fit:cover;}
        .patient-info{flex:1;}
        .patient-name{font-size:17px;font-weight:800;color:var(--gray-900);}
        .patient-id{display:inline-block;background:var(--g-pale);color:var(--g-mid);padding:2px 10px;border-radius:12px;font-size:11px;font-weight:700;margin:4px 0 6px;}
        .patient-meta{font-size:12px;color:var(--gray-500);}
        .patient-meta span{margin-right:12px;}

        .status-pill{margin-left:auto;padding:5px 14px;border-radius:20px;font-size:12px;font-weight:800;white-space:nowrap;}
        .status-pill.waiting{background:#fef3c7;color:#92400e;}
        .status-pill.accepted{background:#dcfce7;color:#166534;}
        .status-pill.active{background:#e0f2f1;color:#005a52;}

        /* NOTES PREVIEW */
        .notes-preview{background:#f9fafb;border-radius:10px;padding:12px 14px;margin-bottom:16px;font-size:13px;color:var(--gray-500);border-left:3px solid #e5e7eb;}
        .notes-preview strong{color:var(--gray-900);display:block;margin-bottom:3px;font-size:12px;text-transform:uppercase;letter-spacing:0.5px;}

        /* EHEALTH DOCTOR ROW */
        .ehealth-row{display:flex;align-items:center;gap:10px;margin-bottom:16px;padding:10px 14px;background:var(--g-pale);border-radius:10px;}
        .ehealth-icon{font-size:18px;}
        .ehealth-label{font-size:12px;color:var(--gray-500);}
        .ehealth-name{font-size:13px;font-weight:700;color:var(--g-mid);}
        .ehealth-sub{font-size:12px;color:var(--gray-500);margin-top:2px;}
        .wait-time{margin-left:auto;font-size:12px;color:var(--gray-400);font-weight:600;}

        /* VITALS */
        .vitals-row{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;}
        .vital-chip{background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:5px 12px;font-size:12px;font-weight:700;color:var(--g-mid);}
        .vital-chip span{font-weight:400;color:var(--gray-500);}

        /* ACTION BUTTONS */
        .card-actions{display:flex;gap:10px;flex-wrap:wrap;}
        .btn{padding:10px 22px;border-radius:10px;border:none;font-size:13px;font-weight:700;font-family:'Sora',sans-serif;cursor:pointer;transition:all 0.15s;white-space:nowrap;}
        .btn:disabled{opacity:0.45;cursor:not-allowed;}
        .btn-accept{background:#16a34a;color:white;box-shadow:0 3px 10px rgba(22,163,74,0.3);}
        .btn-accept:hover:not(:disabled){background:#15803d;transform:translateY(-1px);}
        .btn-reject{background:#b71c1c;color:white;box-shadow:0 3px 10px rgba(183,28,28,0.3);}
        .btn-reject:hover:not(:disabled){background:#8e1616;transform:translateY(-1px);}
        .btn-call{background:var(--teal);color:white;box-shadow:0 3px 10px rgba(0,137,123,0.3);}
        .btn-call:hover:not(:disabled){background:#00695c;transform:translateY(-1px);}
        .btn-notify{background:#6366f1;color:white;}
        .btn-notify:hover:not(:disabled){background:#4f46e5;}
        .btn-join{background:linear-gradient(135deg,var(--teal),#00695c);color:white;animation:glow 2s infinite;}
        @keyframes glow{0%,100%{box-shadow:0 3px 10px rgba(0,137,123,0.3);}50%{box-shadow:0 3px 20px rgba(0,137,123,0.6);}}
        .message-box{width:100%;background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:10px 12px;}
        .message-label{font-size:11px;font-weight:800;letter-spacing:0.4px;text-transform:uppercase;color:var(--gray-500);margin-bottom:6px;}
        .msg-select,.msg-other{
            width:100%;padding:8px 10px;border:1.5px solid #d1d5db;border-radius:8px;
            font-size:12.5px;font-family:'Sora',sans-serif;color:var(--gray-700);outline:none;background:white;
        }
        .msg-select:focus,.msg-other:focus{border-color:var(--g-mid);box-shadow:0 0 0 2px rgba(46,125,50,0.1);}
        .msg-other{display:none;margin-top:8px;}

        /* EMPTY STATE */
        .empty{text-align:center;padding:60px 20px;background:white;border-radius:16px;box-shadow:0 3px 14px rgba(0,0,0,0.07);}
        .empty-icon{font-size:52px;margin-bottom:16px;}
        .empty h3{font-size:18px;font-weight:700;color:var(--gray-500);margin-bottom:8px;}
        .empty p{font-size:14px;color:var(--gray-400);}

        /* TOAST */
        #toast{position:fixed;bottom:24px;left:50%;transform:translateX(-50%) translateY(80px);background:#1b5e20;color:white;padding:12px 24px;border-radius:20px;font-size:13.5px;font-weight:600;font-family:'Sora',sans-serif;box-shadow:0 8px 28px rgba(0,0,0,0.2);z-index:9999;transition:transform 0.35s cubic-bezier(0.22,1,0.36,1),opacity 0.3s;opacity:0;pointer-events:none;white-space:nowrap;}
    </style>
</head>
<body>

<nav class="navbar">
    <a href="<?= htmlspecialchars(app_base_url()) ?>/" style="display:flex;align-items:center;gap:14px;line-height:1;margin-right:auto;text-decoration:none;"><img src="<?= htmlspecialchars(app_base_url()) ?>/public/assets/SRMS_TRUST_LOGO.png" alt="SRMST Trust Logo" style="height:48px;width:auto;display:block;object-fit:contain;filter:drop-shadow(0 1px 3px rgba(0,0,0,.35));"><span style="font-size:22px;font-weight:800;letter-spacing:1px;color:#fff;text-shadow:0 1px 2px rgba(0,0,0,.35);white-space:nowrap;">SRMS EHEALTH</span></a>
    <div class="live-pill"><div class="live-dot"></div><span>Live Queue</span></div>
    <a href="telestudio_dashboard.php" class="nav-back">← Dashboard</a>
    <a href="<?= htmlspecialchars(app_base_url()) ?>/about.php" class="nav-back">ABOUT</a>
    <a href="<?= htmlspecialchars(app_base_url()) ?>/contact.php" class="nav-back">CONTACT</a>
    <span class="nav-dr"><?= htmlspecialchars(get_session_name()) ?></span>
</nav>

<div class="page">
    <div class="page-header">
        <div class="page-title">&#128203; Consultation Queue</div>
        <div class="queue-count" id="queueCount"><?= count($sessions) ?> request<?= count($sessions) != 1 ? 's' : '' ?></div>
    </div>

    <div id="queueContainer">
    <?php if (empty($sessions)): ?>
    <div class="empty">
        <div class="empty-icon">&#128203;</div>
        <h3>No pending consultations</h3>
        <p>New requests will appear here automatically when eHealth doctors send them.</p>
    </div>
    <?php else: ?>
    <?php foreach ($sessions as $sess):
        $initials = strtoupper(substr($sess['patient_name'],0,1));
        $bp = ($sess['bp_systolic'] && $sess['bp_diastolic']) ? $sess['bp_systolic'].'/'.$sess['bp_diastolic'].' mmHg' : null;
        $waitMins = round((time() - strtotime($sess['created_at'])) / 60);
        $photo = patient_photo_url($sess['photo_filename'] ?? null);
    ?>
    <div class="session-card" id="card-<?= $sess['id'] ?>" data-status="<?= $sess['status'] ?>">
        <div class="card-top">
            <?php if ($photo): ?>
            <img src="<?= htmlspecialchars($photo) ?>" class="patient-photo">
            <?php else: ?>
            <div class="patient-ava"><?= $initials ?></div>
            <?php endif; ?>
            <div class="patient-info">
                <div class="patient-name"><?= htmlspecialchars($sess['patient_name']) ?></div>
                <div class="patient-id"><?= htmlspecialchars($sess['patient_id']) ?></div>
                <div class="patient-meta">
                    <span>&#128100; <?= htmlspecialchars($sess['age'] ?? '—') ?> yrs, <?= htmlspecialchars($sess['gender'] ?? '—') ?></span>
                    <span>&#128222; <?= htmlspecialchars($sess['mobile_no'] ?? '—') ?></span>
                </div>
            </div>
            <div>
                <div class="status-pill <?= $sess['status'] ?>"><?= ucfirst($sess['status']) ?></div>
            </div>
        </div>

        <?php if ($sess['teleconsult_reason'] || $sess['chief_complaint'] || $sess['clinical_notes']): ?>
        <div class="notes-preview">
            <?php if ($sess['teleconsult_reason']): ?>
            <strong>Teleconsultation Reason</strong><?= htmlspecialchars($sess['teleconsult_reason']) ?>
            <?php endif; ?>
            <?php if ($sess['chief_complaint']): ?>
            <strong>Chief Complaint</strong><?= htmlspecialchars($sess['chief_complaint']) ?>
            <?php endif; ?>
            <?php if ($sess['clinical_notes']): ?>
            <strong style="margin-top:6px;">Clinical Notes</strong><?= htmlspecialchars($sess['clinical_notes']) ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="ehealth-row">
            <span class="ehealth-icon">&#127973;</span>
            <div>
                <div class="ehealth-label">Referred by eHealth Doctor</div>
                <div class="ehealth-name"><?= htmlspecialchars($sess['ehealth_doctor_name']) ?></div>
                <div class="ehealth-sub">
                    ID: <?= htmlspecialchars($sess['ehealth_doctor_id'] ?? 'â€”') ?> |
                    Phone: <?= htmlspecialchars($sess['ehealth_doctor_mobile'] ?? 'â€”') ?>
                </div>
            </div>
            <div class="wait-time">&#9201; <?= $waitMins ?> min ago</div>
        </div>

        <?php if ($bp || $sess['heart_rate'] || $sess['temperature']): ?>
        <div class="vitals-row">
            <?php if ($bp): ?><div class="vital-chip">BP <span><?= $bp ?></span></div><?php endif; ?>
            <?php if ($sess['heart_rate']): ?><div class="vital-chip">HR <span><?= $sess['heart_rate'] ?> bpm</span></div><?php endif; ?>
            <?php if ($sess['temperature']): ?><div class="vital-chip">Temp <span><?= $sess['temperature'] ?>°C</span></div><?php endif; ?>
            <?php if ($sess['spo2']): ?><div class="vital-chip">SpO2 <span><?= $sess['spo2'] ?>%</span></div><?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="card-actions" id="actions-<?= $sess['id'] ?>">
            <?php if ($sess['status'] === 'waiting'): ?>
            <div class="message-box">
                <div class="message-label">Accept Message</div>
                <select class="msg-select" id="waitMsgSelect-<?= $sess['id'] ?>" onchange="onMessageTemplateChange('waitMsgSelect-<?= $sess['id'] ?>','waitMsgOther-<?= $sess['id'] ?>')">
                    <option value="">Select wait message</option>
                    <option value="Calling now, please keep the waiting page open.">Calling now, please keep the waiting page open.</option>
                    <option value="Please wait, I am in another call.">Please wait, I am in another call.</option>
                    <option value="Please wait 1 minute, I will join shortly.">Please wait 1 minute, I will join shortly.</option>
                    <option value="Please wait 2 minutes, I will join shortly.">Please wait 2 minutes, I will join shortly.</option>
                    <option value="Please wait 3 minutes, I will join shortly.">Please wait 3 minutes, I will join shortly.</option>
                    <option value="__other__">Other (Specify)</option>
                </select>
                <input type="text" class="msg-other" id="waitMsgOther-<?= $sess['id'] ?>" placeholder="Type custom wait message...">
            </div>

            <div class="message-box">
                <div class="message-label">Reject Message</div>
                <select class="msg-select" id="rejectMsgSelect-<?= $sess['id'] ?>" onchange="onMessageTemplateChange('rejectMsgSelect-<?= $sess['id'] ?>','rejectMsgOther-<?= $sess['id'] ?>')">
                    <option value="">Select reject message</option>
                    <option value="My shift is over. Please connect again after some time to another doctor.">My shift is over. Please connect again after some time to another doctor.</option>
                    <option value="I am currently unavailable. Please connect to another doctor.">I am currently unavailable. Please connect to another doctor.</option>
                    <option value="Please try another TeleStudio specialist for this case.">Please try another TeleStudio specialist for this case.</option>
                    <option value="__other__">Other (Specify)</option>
                </select>
                <input type="text" class="msg-other" id="rejectMsgOther-<?= $sess['id'] ?>" placeholder="Type custom reject message...">
            </div>

            <button class="btn btn-accept" onclick="acceptSession(<?= $sess['id'] ?>, this)">&#10003; Accept</button>
            <button class="btn btn-reject" onclick="rejectSession(<?= $sess['id'] ?>, this)">&#10005; Reject</button>
            <?php elseif ($sess['status'] === 'accepted'): ?>
            <button class="btn btn-call" onclick="startCall(<?= $sess['id'] ?>, this)">&#128222; Call Now</button>
            <button class="btn btn-notify" onclick="sendNotify(<?= $sess['id'] ?>, this)">&#128276; Notify eHealth Doctor</button>
            <?php elseif ($sess['status'] === 'active'): ?>
            <button class="btn btn-join" onclick="joinCall(<?= $sess['id'] ?>)">&#128249; Rejoin Call</button>
            <button class="btn btn-notify" onclick="sendNotify(<?= $sess['id'] ?>, this)">&#128276; Re-Notify</button>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
    </div>
</div>

<div id="toast"></div>

<script>
const ACTIONS_URL = 'teleconsult_actions.php';
let notifyTimers = {};
const RING_ENABLED = false;
const RING_URLS = ['../assets/ringtone.mp3', '../assets/notification.wav', '../assets/notification.mp3'];
let ringAudio = null;
let ringAudioIdx = 0;
let ringActive = false;

function ringUrl() {
    return RING_URLS[Math.min(ringAudioIdx, RING_URLS.length - 1)];
}

function ensureRingAudio() {
    if (ringAudio) return ringAudio;
    try {
        ringAudio = new Audio(ringUrl());
        ringAudio.loop = true;
        ringAudio.preload = 'auto';
        ringAudio.addEventListener('error', () => {
            if (ringAudioIdx < RING_URLS.length - 1) {
                ringAudioIdx++;
                ringAudio = null;
            }
        }, { once: true });
    } catch (_) {
        ringAudio = null;
    }
    return ringAudio;
}

function warmupRingAudio() {
    if (!RING_ENABLED) return;
    const audio = ensureRingAudio();
    if (!audio) return;
    const p = audio.play();
    if (p && typeof p.then === 'function') {
        p.then(() => {
            audio.pause();
            audio.currentTime = 0;
            ringActive = false;
        }).catch(() => {});
    }
    syncRingState();
}

function startRing() {
    if (!RING_ENABLED) return;
    if (ringActive) return;
    const audio = ensureRingAudio();
    if (!audio) return;
    try { audio.currentTime = 0; } catch (_) {}
    const p = audio.play();
    if (p && typeof p.then === 'function') {
        p.then(() => { ringActive = true; }).catch(() => {
            if (ringAudioIdx < RING_URLS.length - 1) {
                ringAudioIdx++;
                ringAudio = null;
                startRing();
            }
        });
    } else {
        ringActive = true;
    }
}

function stopRing() {
    if (!RING_ENABLED) return;
    if (!ringAudio) return;
    try {
        ringAudio.pause();
        ringAudio.currentTime = 0;
    } catch (_) {}
    ringActive = false;
}

function waitingCount() {
    return document.querySelectorAll('.session-card[data-status="waiting"]').length;
}

function syncRingState() {
    if (!RING_ENABLED) return;
    if (waitingCount() > 0) startRing();
    else stopRing();
}

['click', 'keydown', 'touchstart'].forEach((evt) => {
    window.addEventListener(evt, warmupRingAudio, { once: true, passive: true });
});

function onMessageTemplateChange(selectId, otherId) {
    const sel   = document.getElementById(selectId);
    const other = document.getElementById(otherId);
    if (!sel || !other) return;
    const isOther = sel.value === '__other__';
    other.style.display = isOther ? 'block' : 'none';
    if (!isOther) other.value = '';
}

function getSelectedMessage(selectId, otherId) {
    const sel = document.getElementById(selectId);
    if (!sel) return '';
    if (sel.value === '__other__') return (document.getElementById(otherId)?.value || '').trim();
    return sel.value.trim();
}

function updateQueueCount(delta) {
    const el = document.getElementById('queueCount');
    if (!el) return;
    const m = el.textContent.match(/^\d+/);
    if (!m) return;
    let n = parseInt(m[0], 10) + delta;
    if (n < 0) n = 0;
    el.textContent = `${n} request${n === 1 ? '' : 's'}`;
}

async function acceptSession(sid, btn) {
    const waitMessage = getSelectedMessage(`waitMsgSelect-${sid}`, `waitMsgOther-${sid}`);
    if (!waitMessage) {
        showToast('❌ Please choose or type a wait message before accepting.', true);
        return;
    }

    btn.disabled = true; btn.textContent = '⏳ Accepting…';
    const res  = await fetch(`${ACTIONS_URL}?action=accept`, {
        method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({sessionId: sid, waitMessage})
    });
    if (res.status === 401) {
        showToast('❌ Session expired. Please login again.', true);
        btn.disabled = false; btn.textContent = '✓ Accept';
        return;
    }
    const data = await res.json();
    if (data.success) {
        showToast('✅ Request accepted — Call button is now active');
        const card = document.getElementById('card-'+sid);
        card.dataset.status = 'accepted';
        card.querySelector('.status-pill').className = 'status-pill accepted';
        card.querySelector('.status-pill').textContent = 'Accepted';
        document.getElementById('actions-'+sid).innerHTML =
            `<button class="btn btn-call" onclick="startCall(${sid}, this)">📞 Call Now</button>
             <button class="btn btn-notify" onclick="sendNotify(${sid}, this)">🔔 Notify eHealth Doctor</button>`;
    } else {
        btn.disabled = false; btn.textContent = '✓ Accept';
        showToast('❌ ' + (data.error || 'Failed'), true);
    }
    syncRingState();
}

async function rejectSession(sid, btn) {
    const rejectMessage = getSelectedMessage(`rejectMsgSelect-${sid}`, `rejectMsgOther-${sid}`);
    if (!rejectMessage) {
        showToast('❌ Please choose or type a reject message before rejecting.', true);
        return;
    }
    if (!confirm('Reject this consultation request?')) return;

    btn.disabled = true; btn.textContent = '⏳ Rejecting…';
    const res = await fetch(`${ACTIONS_URL}?action=reject`, {
        method:'POST',
        credentials:'same-origin',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({sessionId: sid, rejectMessage})
    });
    if (res.status === 401) {
        showToast('❌ Session expired. Please login again.', true);
        btn.disabled = false; btn.textContent = '✕ Reject';
        return;
    }
    const data = await res.json();
    if (data.success) {
        showToast('✅ Request rejected and message sent.');
        const card = document.getElementById('card-'+sid);
        if (card) card.remove();
        updateQueueCount(-1);
    } else {
        btn.disabled = false; btn.textContent = '✕ Reject';
        showToast('❌ ' + (data.error || 'Failed'), true);
    }
    syncRingState();
}

async function startCall(sid, btn) {
    btn.disabled = true; btn.textContent = '⏳ Starting…';
    const res  = await fetch(`${ACTIONS_URL}?action=start_call`, {
        method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({sessionId: sid})
    });
    if (res.status === 401) {
        showToast('❌ Session expired. Please login again.', true);
        btn.disabled = false; btn.textContent = '📞 Call Now';
        return;
    }
    const data = await res.json();
    if (data.success) {
        showToast('📹 Call started! Joining room…');
        // Update card
        const card = document.getElementById('card-'+sid);
        card.dataset.status = 'active';
        card.querySelector('.status-pill').className = 'status-pill active';
        card.querySelector('.status-pill').textContent = 'Active';
        document.getElementById('actions-'+sid).innerHTML =
            `<button class="btn btn-join" onclick="joinCall(${sid})">📹 Rejoin Call</button>
             <button class="btn btn-notify" onclick="sendNotify(${sid}, this)">🔔 Re-Notify</button>`;
        // Redirect to call room after short delay
        setTimeout(() => {
            window.location.href = `teleconsult_room.php?sessionId=${sid}&role=telestudio`;
        }, 800);
    } else {
        btn.disabled = false; btn.textContent = '📞 Call Now';
        showToast('❌ ' + (data.error || 'Failed'), true);
    }
    syncRingState();
}

async function sendNotify(sid, btn) {
    const orig = btn.textContent;
    btn.disabled = true; btn.textContent = '⏳ Sending…';

    const res = await fetch(`${ACTIONS_URL}?action=notify`, {
        method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({sessionId: sid})
    });
    if (res.status === 401) {
        showToast('❌ Session expired. Please login again.', true);
        btn.disabled = false;
        btn.textContent = orig;
        return;
    }
    showToast('🔔 Notification sent to eHealth doctor');
    btn.textContent = '✅ Sent!';
    // Re-enable after 10s
    clearTimeout(notifyTimers[sid]);
    notifyTimers[sid] = setTimeout(() => {
        btn.disabled = false;
        btn.textContent = orig;
    }, 10000);
}

function joinCall(sid) {
    stopRing();
    window.location.href = `teleconsult_room.php?sessionId=${sid}&role=telestudio`;
}

// SSE for new incoming sessions
const sse = new EventSource('../ehealth_center_doctor/teleconsult_notify_stream.php?listenNew=1&doctorId=<?= $uid ?>');
// (handled via queue auto-refresh for simplicity)

// Auto-refresh queue every 15s to pick up new sessions
setInterval(() => {
    fetch(window.location.href, {headers:{'X-Requested-With':'fetch'}})
    .then(r => r.text()).then(html => {
        const parser = new DOMParser();
        const doc    = parser.parseFromString(html, 'text/html');
        const newContainer = doc.getElementById('queueContainer');
        const newCount     = doc.getElementById('queueCount');
        if (newContainer) {
            // Only update cards that aren't currently being interacted with
            const current = document.getElementById('queueContainer');
            if (current.innerHTML !== newContainer.innerHTML) {
                // Add only new cards without removing active ones
                const newIds = [...newContainer.querySelectorAll('.session-card')].map(c=>c.id);
                const curIds = [...current.querySelectorAll('.session-card')].map(c=>c.id);
                newIds.filter(id=>!curIds.includes(id)).forEach(id => {
                    const card = newContainer.querySelector('#'+id);
                    if (card) current.prepend(card.cloneNode(true));
                });
                if (newCount) document.getElementById('queueCount').textContent = newCount.textContent;
                syncRingState();
            }
        }
    });
}, 15000);

let toastTimer;
function showToast(msg, isErr=false) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.style.background = isErr ? '#b71c1c' : '#1b5e20';
    t.style.transform = 'translateX(-50%) translateY(0)';
    t.style.opacity = '1';
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { t.style.transform='translateX(-50%) translateY(80px)'; t.style.opacity='0'; }, 3500);
}

syncRingState();
window.addEventListener('beforeunload', () => {
    stopRing();
    if (sse) sse.close();
});
</script>
<script src="../assets/doctor_notifications.js"></script>
</body>
</html>
