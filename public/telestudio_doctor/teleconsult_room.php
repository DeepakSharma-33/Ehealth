<?php
require_once __DIR__ . '/../../config/config.php';
start_session();
if (!is_logged_in()) { header('Location: ../index.php'); exit; }

$role = get_session_role();
$uid  = (string)get_session_id();
$uname = get_session_name();
$sid  = (int)($_GET['sessionId'] ?? 0);
if (!$sid) { header('Location: ../index.php'); exit; }

// ── WHEREBY CONFIG ──────────────────────────────────────────
function createWherebyMeeting(int $sessionId): array {
    if (WHEREBY_API_KEY === '') {
        return [];
    }

    $payload = json_encode([
        'isLocked'        => false,
        'roomNamePrefix'  => 'teleconsult-'.$sessionId,
        'roomNamePattern' => 'uuid',
        'roomMode'        => 'group',
        'endDate'         => date('Y-m-d\TH:i:s.000\Z', strtotime('+3 hours')),
        'fields'          => ['roomUrl', 'meetingId']
    ]);
    $ch = curl_init(WHEREBY_API_URL . '/meetings');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer '.WHEREBY_API_KEY,
            'Content-Type: application/json',
        ],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);
    return $raw ? (json_decode($raw, true) ?? []) : [];
}

$db   = db();
ensure_patient_revisit_schema($db);

// ── FETCH SESSION ───────────────────────────────────────────
$stmt = $db->prepare(
    'SELECT ts.*, p.age, p.gender, p.mobile_no, p.photo_filename, COALESCE(p.patient_flag, \'N\') AS patient_flag,
            ehd.mobile_no AS ehealth_doctor_mobile,
            v.bp_systolic, v.bp_diastolic, v.heart_rate, v.temperature,
            v.spo2, v.weight, v.height, v.bmi, v.resp_rate, v.recorded_at as vitals_at,
            EXISTS(
                SELECT 1
                FROM diagnosis_records dr
                WHERE dr.patient_id = ts.patient_id
            ) AS has_ehealth_history,
            EXISTS(
                SELECT 1
                FROM telestudio_diagnosis td
                WHERE td.patient_id = ts.patient_id
            ) AS has_tele_history
     FROM teleconsult_sessions ts
     LEFT JOIN patients p ON p.patient_id = ts.patient_id
     LEFT JOIN ehealth_center_doctors ehd ON ehd.doctor_id = ts.ehealth_doctor_id
     LEFT JOIN patient_vitals v ON v.id = (
         SELECT id FROM patient_vitals WHERE patient_id = ts.patient_id ORDER BY recorded_at DESC LIMIT 1
     )
     WHERE ts.id = ? LIMIT 1'
);
$stmt->bind_param('i', $sid);
$stmt->execute();
$sess = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$sess) { header('Location: ../index.php'); exit; }

// Auth check
if ($role === 'doctor'            && $sess['ehealth_doctor_id']    !== $uid) { header('Location: ../ehealth_center_doctor/patient_queue.php'); exit; }
if ($role === 'telestudio_doctor' && $sess['telestudio_doctor_id'] !== $uid) { header('Location: ../telestudio_doctor/telestudio_queue.php'); exit; }

$isTeleStudio = ($role === 'telestudio_doctor');

// ── CREATE WHEREBY ROOM IF NOT EXISTS ───────────────────────
if (!$sess['whereby_room_url']) {
    $meeting = createWherebyMeeting($sid);
    if (!empty($meeting['roomUrl'])) {
        $roomUrl = $meeting['roomUrl'];
        $hostUrl = $meeting['hostRoomUrl'] ?? $roomUrl;
        $escR = $db->real_escape_string($roomUrl);
        $escH = $db->real_escape_string($hostUrl);
        $db->query("UPDATE teleconsult_sessions
                    SET whereby_room_url='$escR', whereby_host_url='$escH'
                    WHERE id=$sid");
        $sess['whereby_room_url'] = $roomUrl;
        $sess['whereby_host_url'] = $hostUrl;
    }
}

// BOTH doctors join using the SAME roomUrl — this is what puts them in the same room
// hostRoomUrl contains a roomKey JWT which causes "host already in room" conflicts
// Plain roomUrl works for both — Whereby handles it correctly
$wherebyUrl = $sess['whereby_room_url'] ?? '';

$bp      = ($sess['bp_systolic'] && $sess['bp_diastolic']) ? $sess['bp_systolic'].'/'.$sess['bp_diastolic'] : '—';
$photo   = patient_photo_url($sess['photo_filename'] ?? null);
$vitalsTs = $sess['vitals_at'] ? date('d M Y, h:i A', strtotime($sess['vitals_at'])) : null;
$isOldPatient = (($sess['patient_flag'] ?? 'N') === 'O')
    || ((int)($sess['has_ehealth_history'] ?? 0) === 1)
    || ((int)($sess['has_tele_history'] ?? 0) === 1);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teleconsultation — <?= htmlspecialchars($sess['patient_name']) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;600;700;800&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            /* same as diagnosis_form.php */
            --g-dark:   #1b5e20;
            --g-mid:    #2e7d32;
            --g-light:  #43a047;
            --g-pale:   #e8f5e9;
            --g-mist:   #f1f8f2;
            --g-border: #c8e0ca;
            --text-dark:#0d1f12;
            --text-mid: #3a4f3c;
            --text-soft:#6b7f6d;
            --white:    #ffffff;
            --red:      #c62828;
            --red-pale: #ffebee;
            /* dark panel */
            --panel-bg: #0d1b2a;
            --panel-2:  #13243a;
            --panel-bd: #1e3a52;
            --teal:     #00897b;
        }

        html, body { height: 100%; font-family: 'Sora', sans-serif; background: var(--panel-bg); color: var(--text-dark); overflow: hidden; }

        /* ── TOP BAR ── */
        .topbar {
            background: var(--g-dark);
            height: 54px; padding: 0 28px;
            display: flex; align-items: center; gap: 14px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.25); flex-shrink: 0; z-index: 20;
        }
        .topbar-logo { width: 32px; height: 32px; background: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 15px; font-weight: 800; color: var(--g-dark); flex-shrink: 0; }
        .topbar-brand { font-size: 15px; font-weight: 800; letter-spacing: 2px; color: white; margin-right: auto; }
        .topbar-session { font-size: 12px; color: rgba(255,255,255,0.7); font-family: 'JetBrains Mono', monospace; }
        .topbar-timer { font-family: 'JetBrains Mono', monospace; font-size: 13px; font-weight: 700; color: #a5d6a7; background: rgba(255,255,255,0.1); padding: 4px 12px; border-radius: 8px; }
        .conn-dot { width: 8px; height: 8px; border-radius: 50%; background: #4caf50; animation: pulse 1.5s infinite; }
        @keyframes pulse { 0%,100%{opacity:1;} 50%{opacity:0.4;} }

        /* ── APP LAYOUT ── */
        .app { display: flex; flex-direction: column; height: 100vh; }
        .main { display: flex; flex: 1; overflow: hidden; }

        /* ── LEFT: VIDEO ── */
        .video-col {
            width: 60%; flex-shrink: 0;
            display: flex; flex-direction: column;
            background: #07111c;
            border-right: 1px solid var(--panel-bd);
        }
        .whereby-wrap { flex: 1; position: relative; overflow: hidden; }
        #whereby-iframe { width: 100%; height: 100%; border: none; display: block; }
        .no-room {
            display: flex; flex-direction: column; align-items: center;
            justify-content: center; height: 100%; gap: 12px; color: #7a9bb8;
        }
        .no-room .icon { font-size: 52px; }
        .no-room p { font-size: 14px; }

        .video-bar {
            background: var(--panel-2); border-top: 1px solid var(--panel-bd);
            padding: 12px 20px; display: flex; align-items: center; gap: 10px; flex-shrink: 0;
        }
        .btn-vid {
            padding: 9px 18px; border-radius: 10px; border: none;
            font-size: 13px; font-weight: 700; font-family: 'Sora', sans-serif;
            cursor: pointer; transition: all 0.15s; display: flex; align-items: center; gap: 7px;
        }
        .btn-notify { background: rgba(99,102,241,0.15); color: #818cf8; border: 1px solid rgba(99,102,241,0.3); }
        .btn-notify:hover { background: rgba(99,102,241,0.28); }
        .btn-notify:disabled { opacity: 0.4; cursor: not-allowed; }
        .btn-end { background: rgba(198,40,40,0.15); color: #ef9a9a; border: 1px solid rgba(198,40,40,0.3); margin-left: auto; }
        .btn-end:hover { background: rgba(198,40,40,0.28); }

        .history-dock {
            margin-top: 14px;
            background: #fff;
            border: 1px solid var(--g-border);
            border-radius: 14px;
            box-shadow: 0 3px 14px rgba(0,0,0,0.08);
            display: flex;
            flex-direction: column;
        }
        .history-dock-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 12px 14px;
            border-bottom: 1px solid #dcebdc;
        }
        .history-dock-title { font-size: 12px; font-weight: 800; color: var(--g-dark); letter-spacing: 0.3px; }
        .history-dock-tag {
            font-size: 10px;
            font-weight: 800;
            background: #fff3e0;
            color: #e65100;
            border-radius: 12px;
            padding: 3px 8px;
            letter-spacing: 0.4px;
        }
        .history-tab-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            padding: 10px 14px;
            border-bottom: 1px solid #d2e8d5;
        }
        .history-tab-btn {
            border: 1.5px solid #b9d5bc;
            border-radius: 9px;
            background: #fff;
            color: var(--text-mid);
            padding: 8px 10px;
            font-size: 11px;
            font-weight: 800;
            cursor: pointer;
            font-family: 'Sora', sans-serif;
        }
        .history-tab-btn.active {
            border-color: var(--g-mid);
            color: var(--g-dark);
            background: var(--g-pale);
        }
        .history-dock-scroll {
            max-height: 330px;
            overflow-y: auto;
            padding: 10px 14px;
            display: grid;
            gap: 8px;
        }
        .history-dock-card {
            background: #fff;
            border: 1px solid #d2e8d5;
            border-left: 4px solid var(--g-mid);
            border-radius: 10px;
            padding: 9px 10px;
        }
        .history-dock-card.tele { border-left-color: var(--teal); }
        .hd-row { font-size: 11.5px; color: #38523b; line-height: 1.4; margin-bottom: 4px; }
        .hd-row:last-child { margin-bottom: 0; }
        .hd-row b { color: var(--text-dark); font-weight: 700; }
        .hd-date {
            font-family: 'JetBrains Mono', monospace;
            font-size: 10px;
            color: #617e65;
            margin-bottom: 6px;
        }
        .hd-vitals {
            margin-top: 5px;
            background: #eef8f0;
            border: 1px solid #d9ebdc;
            border-radius: 8px;
            padding: 4px 6px;
            font-size: 10.5px;
            color: #2a4a2f;
        }
        .hd-empty {
            font-size: 12px;
            color: #5f7d63;
            text-align: center;
            padding: 16px 10px;
        }

        /* ── RIGHT PANEL ── */
        .right-col { flex: 1; display: flex; flex-direction: column; overflow: hidden; background: #a8e6b0; background-image: radial-gradient(ellipse 70% 50% at 5% 0%, rgba(27,94,32,0.12) 0%, transparent 55%); }
        .right-scroll { flex: 1; overflow-y: auto; padding: 18px 20px 20px; }
        .right-scroll::-webkit-scrollbar { width: 5px; }
        .right-scroll::-webkit-scrollbar-thumb { background: var(--g-border); border-radius: 4px; }

        /* ── PATIENT BANNER (same style as diagnosis_form.php) ── */
        .patient-banner {
            background: white; border-radius: 16px; padding: 20px 24px;
            margin-bottom: 14px; box-shadow: 0 3px 14px rgba(0,0,0,0.08);
            border-left: 5px solid var(--g-mid);
            display: flex; align-items: center; gap: 18px; flex-wrap: wrap;
        }
        .patient-photo { width: 60px; height: 60px; border-radius: 50%; object-fit: cover; border: 3px solid var(--g-pale); flex-shrink: 0; }
        .patient-avatar { width: 60px; height: 60px; border-radius: 50%; background: var(--g-pale); display: flex; align-items: center; justify-content: center; font-size: 28px; border: 3px solid var(--g-border); flex-shrink: 0; }
        .banner-name { font-size: 20px; font-weight: 800; color: var(--text-dark); }
        .banner-id { font-size: 12px; font-weight: 700; background: var(--g-pale); color: var(--g-mid); padding: 3px 12px; border-radius: 20px; display: inline-block; margin: 4px 0 8px; }
        .banner-meta { display: flex; gap: 18px; flex-wrap: wrap; font-size: 13px; color: var(--text-soft); }
        .banner-meta b { color: var(--text-mid); font-weight: 600; }

        /* ── VITALS STRIP (same style) ── */
        .vitals-strip { background: white; border-radius: 16px; padding: 18px 24px; margin-bottom: 14px; box-shadow: 0 3px 14px rgba(0,0,0,0.08); }
        .vitals-strip-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; }
        .vitals-strip-title { font-size: 13px; font-weight: 700; color: var(--g-mid); }
        .vitals-strip-ts { font-size: 11px; color: var(--text-soft); font-family: 'JetBrains Mono', monospace; }
        .vitals-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(110px, 1fr)); gap: 10px; }
        .vital-card { background: var(--g-mist); border: 1px solid var(--g-border); border-radius: 10px; padding: 12px 10px; text-align: center; }
        .vital-label { font-size: 10px; font-weight: 700; color: var(--text-soft); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 5px; }
        .vital-value { font-size: 18px; font-weight: 800; color: var(--g-mid); font-family: 'JetBrains Mono', monospace; }
        .vital-unit { font-size: 10px; font-weight: 600; color: var(--text-soft); }

        /* ── DOCTORS ROW ── */
        .doctors-card { background: white; border-radius: 16px; padding: 18px 24px; margin-bottom: 14px; box-shadow: 0 3px 14px rgba(0,0,0,0.08); display: flex; gap: 16px; flex-wrap: wrap; }
        .doc-item { flex: 1; min-width: 160px; display: flex; align-items: center; gap: 10px; }
        .doc-ava { width: 38px; height: 38px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 15px; font-weight: 800; color: white; flex-shrink: 0; }
        .doc-ava.eh { background: var(--g-mid); }
        .doc-ava.ts { background: var(--teal); }
        .doc-role { font-size: 10px; color: var(--text-soft); text-transform: uppercase; letter-spacing: 0.5px; }
        .doc-name { font-size: 13px; font-weight: 800; color: var(--text-dark); }
        .doc-id { font-size: 11px; color: var(--text-soft); font-family: 'JetBrains Mono', monospace; }
        .doc-divider { width: 1px; background: var(--g-border); align-self: stretch; }

        /* ── PRE-NOTES CARD ── */
        .prenotes-card { background: white; border-radius: 16px; padding: 20px 24px; margin-bottom: 14px; box-shadow: 0 3px 14px rgba(0,0,0,0.08); border-left: 5px solid #f59e0b; }
        .prenotes-heading { font-size: 11px; font-weight: 800; letter-spacing: 2px; text-transform: uppercase; color: var(--g-mid); margin-bottom: 14px; display: flex; align-items: center; gap: 6px; }
        .prenote-block { margin-bottom: 12px; }
        .prenote-block:last-child { margin-bottom: 0; }
        .prenote-label { font-size: 11px; font-weight: 700; color: var(--text-soft); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 5px; }
        .prenote-text { font-size: 13.5px; color: var(--text-dark); line-height: 1.65; background: var(--g-mist); border: 1px solid var(--g-border); border-radius: 10px; padding: 10px 14px; }

        /* ── EHEALTH READONLY NOTICE ── */
        .readonly-notice { background: #e3f2fd; border: 1px solid #90caf9; border-radius: 12px; padding: 14px 18px; text-align: center; font-size: 13px; color: #1565c0; font-weight: 600; margin-bottom: 14px; }

        /* ── DIAGNOSIS FORM (exact same styles as diagnosis_form.php) ── */
        .form-card { background: white; border-radius: 16px; padding: 28px 32px; box-shadow: 0 3px 14px rgba(0,0,0,0.08); margin-bottom: 14px; }
        .alert { display: none; padding: 13px 18px; border-radius: 10px; margin-bottom: 20px; font-size: 13px; font-weight: 600; }
        .alert.show { display: block; }
        .alert.success { background: #e8f5e9; color: var(--g-mid); border: 1px solid #c8e6c9; }
        .alert.error   { background: var(--red-pale); color: var(--red); border: 1px solid #ffcdd2; }

        .form-section-title {
            font-size: 11px; font-weight: 800; letter-spacing: 2px;
            text-transform: uppercase; color: var(--g-mid);
            border-bottom: 2px solid var(--g-pale);
            padding-bottom: 10px; margin: 24px 0 18px;
        }
        .form-section-title:first-child { margin-top: 0; }

        .form-group { margin-bottom: 18px; }
        .form-label { display: block; font-size: 13px; font-weight: 700; color: var(--text-mid); margin-bottom: 7px; }
        .form-label .opt { font-weight: 400; color: var(--text-soft); font-size: 11px; margin-left: 4px; }
        .form-control {
            width: 100%; padding: 11px 15px;
            border: 2px solid var(--g-border); border-radius: 10px;
            font-size: 14px; font-family: 'Sora', sans-serif;
            min-height: 80px; resize: vertical;
            color: var(--text-dark);
            transition: border-color 0.2s, box-shadow 0.2s;
            background: #fafcfa;
        }
        .form-control:focus { outline: none; border-color: var(--g-mid); box-shadow: 0 0 0 3px rgba(46,125,50,0.08); background: white; }
        .form-control::placeholder { color: #aec4ae; }
        .form-select {
            width: 100%; padding: 11px 15px;
            border: 2px solid var(--g-border); border-radius: 10px;
            font-size: 14px; font-family: 'Sora', sans-serif;
            color: var(--text-dark); background: #fafcfa;
            transition: border-color 0.2s; cursor: pointer; outline: none;
        }
        .form-select:focus { border-color: var(--g-mid); box-shadow: 0 0 0 3px rgba(46,125,50,0.08); background: white; }
        .review-other-wrap { margin-top: 10px; display: none; }
        .review-other-wrap.show { display: block; }
        .review-other-input { width: 100%; padding: 10px 14px; border: 2px solid var(--g-border); border-radius: 10px; font-size: 14px; font-family: 'Sora', sans-serif; outline: none; background: #fafcfa; color: var(--text-dark); transition: border-color 0.2s; }
        .review-other-input:focus { border-color: var(--g-mid); }

        /* ── MEDICINE TABLE (exact same as diagnosis_form.php) ── */
        .btn-add-medicine {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 9px 18px; background: var(--g-pale); color: var(--g-mid);
            border: 2px dashed var(--g-mid); border-radius: 10px;
            font-size: 13px; font-weight: 700; cursor: pointer;
            font-family: 'Sora', sans-serif; margin-bottom: 12px;
            transition: background 0.2s;
        }
        .btn-add-medicine:hover { background: #d4edda; }
        .med-table-wrap { overflow-x: auto; border-radius: 12px; border: 1px solid var(--g-border); }
        .medicine-table { width: 100%; border-collapse: collapse; font-size: 13px; display: none; }
        .medicine-table.show { display: table; }
        .medicine-table th { background: var(--g-mid); color: white; padding: 10px 12px; text-align: left; font-size: 12px; font-weight: 700; letter-spacing: 0.3px; }
        .medicine-table td { padding: 8px 10px; border-bottom: 1px solid var(--g-pale); vertical-align: middle; }
        .medicine-table tr:last-child td { border-bottom: none; }
        .medicine-table tr:hover td { background: var(--g-mist); }
        .medicine-table input, .medicine-table select {
            width: 100%; padding: 6px 9px;
            border: 1.5px solid var(--g-border); border-radius: 7px;
            font-size: 12.5px; font-family: 'Sora', sans-serif;
            color: var(--text-dark); outline: none; background: white;
            transition: border-color 0.2s;
        }
        .medicine-table input:focus, .medicine-table select:focus { border-color: var(--g-mid); }
        .timing-checkboxes { display: flex; flex-wrap: wrap; gap: 5px; }
        .timing-checkboxes label { display: flex; align-items: center; gap: 3px; font-size: 11.5px; cursor: pointer; white-space: nowrap; color: var(--text-mid); }
        .timing-checkboxes input[type=checkbox] { accent-color: var(--g-mid); cursor: pointer; }
        .btn-remove-med { background: var(--red-pale); color: var(--red); border: 1px solid #ffcdd2; width: 28px; height: 28px; border-radius: 7px; cursor: pointer; font-size: 13px; display: flex; align-items: center; justify-content: center; }
        .btn-remove-med:hover { background: #ffcdd2; }

        /* ── SAVE ROW ── */
        .save-row {
            background: white; border-top: 2px solid var(--g-border);
            padding: 14px 20px; display: flex; align-items: center;
            gap: 12px; flex-shrink: 0; flex-wrap: wrap;
        }
        .btn-save-diag {
            flex: 1; min-width: 160px; padding: 13px;
            background: linear-gradient(135deg, var(--g-mid), var(--g-light));
            color: white; border: none; border-radius: 12px;
            font-size: 14px; font-weight: 700; font-family: 'Sora', sans-serif;
            cursor: pointer; transition: opacity 0.2s, transform 0.15s;
            box-shadow: 0 4px 14px rgba(46,125,50,0.25);
        }
        .btn-save-diag:hover { opacity: 0.9; transform: translateY(-1px); }
        .btn-save-diag:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }

        /* TOAST */
        #toast { position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%) translateY(80px); background: var(--g-dark); color: white; padding: 11px 24px; border-radius: 20px; font-size: 13px; font-weight: 600; z-index: 9999; transition: transform 0.3s, opacity 0.3s; opacity: 0; pointer-events: none; white-space: nowrap; box-shadow: 0 4px 20px rgba(0,0,0,0.2); }

        @media (max-width: 900px) {
            .main { flex-direction: column; }
            .video-col { width: 100%; height: 58vh; min-height: 340px; border-right: none; border-bottom: 1px solid var(--panel-bd); }
            .history-dock-scroll { max-height: 240px; }
            html, body { overflow: auto; }
        }
    </style>
</head>
<body>
<div class="app">

<!-- TOP BAR -->
<div class="topbar">
    <a href="<?= htmlspecialchars(app_base_url()) ?>/" style="display:flex;align-items:center;gap:14px;line-height:1;margin-right:auto;text-decoration:none;"><img src="<?= htmlspecialchars(app_base_url()) ?>/public/assets/SRMS_TRUST_LOGO.png" alt="SRMST Trust Logo" style="height:48px;width:auto;display:block;object-fit:contain;filter:drop-shadow(0 1px 3px rgba(0,0,0,.35));"><span style="font-size:22px;font-weight:800;letter-spacing:1px;color:#fff;text-shadow:0 1px 2px rgba(0,0,0,.35);white-space:nowrap;">SRMS EHEALTH</span></a>
    <span class="topbar-session">Session #<?= $sid ?></span>
    <div class="conn-dot"></div>
    <div class="topbar-timer" id="callTimer">00:00:00</div>
</div>

<div class="main">

<!-- ── LEFT: WHEREBY VIDEO ── -->
<div class="video-col">
    <div class="whereby-wrap">
        <?php
        // Plain iframe — same approach as the working Node.js project
        // hostRoomUrl for TeleStudio (host privileges), roomUrl for eHealth (guest)
        // Both doctors get the same roomUrl — only displayName differs
        $cleanRoom   = $wherebyUrl ? strtok($wherebyUrl, '?') : '';
        $displayName = urlencode($uname . ($isTeleStudio ? ' (Specialist)' : ' (eHealth)'));
        $iframeSrc   = $cleanRoom ? ($cleanRoom . '?embed&displayName=' . $displayName) : '';
        ?>
        <?php if ($iframeSrc): ?>
        <iframe
            id="whereby-iframe"
            src="<?= htmlspecialchars($iframeSrc) ?>"
            allow="camera; microphone; fullscreen; speaker; display-capture; autoplay"
            allowfullscreen
            style="width:100%;height:100%;border:none;display:block;"
        ></iframe>
        <?php else: ?>
        <!-- eHealth doctor waiting for room to be created by TeleStudio doctor -->
        <div class="no-room" id="noRoomMsg">
            <div class="icon">&#128249;</div>
            <p>Connecting to video room…</p>
            <p style="font-size:12px;color:#4a6b82;">Please wait while the specialist prepares the call.</p>
            <div style="margin-top:16px;display:flex;align-items:center;gap:8px;font-size:12px;color:#4a6b82;">
                <div style="width:8px;height:8px;border-radius:50%;background:#00bfa5;animation:pulse 1s infinite;"></div>
                <span id="pollStatus">Connecting…</span>
            </div>
        </div>
        <div id="wherebyContainer" style="width:100%;height:100%;display:none;"></div>
        <?php endif; ?>
    </div>

    <div class="video-bar">
        <?php if ($isTeleStudio): ?>
        <button class="btn-vid btn-notify" id="notifyBtn" onclick="sendNotify()">&#128276; Notify eHealth Doctor</button>
        <?php endif; ?>
        <?php if ($isTeleStudio): ?>
        <button class="btn-vid btn-end" id="endBtn" onclick="endConsultation()">&#9632; End Video (Keep Form)</button>
        <?php else: ?>
        <button class="btn-vid btn-end" id="endBtn" onclick="endConsultation()">&#9632; End Session</button>
        <?php endif; ?>
    </div>

</div>

<!-- ── RIGHT: PATIENT INFO + FORM ── -->
<div class="right-col">
    <div class="right-scroll">

        <!-- PATIENT BANNER -->
        <div class="patient-banner">
            <?php if ($photo): ?>
            <img src="<?= $photo ?>" class="patient-photo" alt="patient">
            <?php else: ?>
            <div class="patient-avatar">&#128100;</div>
            <?php endif; ?>
            <div style="flex:1;">
                <div class="banner-name"><?= htmlspecialchars($sess['patient_name']) ?></div>
                <div class="banner-id"><?= htmlspecialchars($sess['patient_id']) ?></div>
                <div class="banner-meta">
                    <?php if ($sess['age']): ?><span><b>Age:</b> <?= htmlspecialchars($sess['age']) ?> yrs</span><?php endif; ?>
                    <?php if ($sess['gender']): ?><span><b>Gender:</b> <?= htmlspecialchars($sess['gender']) ?></span><?php endif; ?>
                    <?php if ($sess['mobile_no']): ?><span><b>Mobile:</b> <?= htmlspecialchars($sess['mobile_no']) ?></span><?php endif; ?>
                </div>
            </div>
        </div>

        <!-- VITALS STRIP -->
        <?php if ($sess['bp_systolic'] || $sess['heart_rate'] || $sess['temperature']): ?>
        <div class="vitals-strip">
            <div class="vitals-strip-header">
                <div class="vitals-strip-title">&#10084;&#65039; Latest Vital Signs</div>
                <?php if ($vitalsTs): ?><div class="vitals-strip-ts">Recorded: <?= $vitalsTs ?></div><?php endif; ?>
            </div>
            <div class="vitals-grid">
                <div class="vital-card"><div class="vital-label">Blood Pressure</div><div class="vital-value"><?= $bp ?><span class="vital-unit"> mmHg</span></div></div>
                <?php if ($sess['heart_rate']): ?>
                <div class="vital-card"><div class="vital-label">Heart Rate</div><div class="vital-value"><?= $sess['heart_rate'] ?><span class="vital-unit"> bpm</span></div></div>
                <?php endif; ?>
                <?php if ($sess['temperature']): ?>
                <div class="vital-card"><div class="vital-label">Temperature</div><div class="vital-value"><?= $sess['temperature'] ?><span class="vital-unit"> °C</span></div></div>
                <?php endif; ?>
                <?php if ($sess['spo2']): ?>
                <div class="vital-card"><div class="vital-label">SpO2</div><div class="vital-value"><?= $sess['spo2'] ?><span class="vital-unit"> %</span></div></div>
                <?php endif; ?>
                <?php if ($sess['weight']): ?>
                <div class="vital-card"><div class="vital-label">Weight</div><div class="vital-value"><?= $sess['weight'] ?><span class="vital-unit"> kg</span></div></div>
                <?php endif; ?>
                <?php if ($sess['bmi']): ?>
                <div class="vital-card"><div class="vital-label">BMI</div><div class="vital-value"><?= $sess['bmi'] ?></div></div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- DOCTORS ROW -->
        <div class="doctors-card">
            <div class="doc-item">
                <div class="doc-ava eh"><?= strtoupper(substr($sess['ehealth_doctor_name'],0,1)) ?></div>
                <div>
                    <div class="doc-role">eHealth Center Doctor</div>
                    <div class="doc-name"><?= htmlspecialchars($sess['ehealth_doctor_name']) ?></div>
                    <div class="doc-id">
                        ID: <?= htmlspecialchars($sess['ehealth_doctor_id']) ?> |
                        Phone: <?= htmlspecialchars($sess['ehealth_doctor_mobile'] ?? 'â€”') ?>
                    </div>
                </div>
            </div>
            <div class="doc-divider"></div>
            <div class="doc-item">
                <div class="doc-ava ts"><?= strtoupper(substr($sess['telestudio_doctor_name'],0,1)) ?></div>
                <div>
                    <div class="doc-role">TeleStudio Specialist</div>
                    <div class="doc-name"><?= htmlspecialchars($sess['telestudio_doctor_name']) ?></div>
                    <div class="doc-id"><?= htmlspecialchars($sess['telestudio_doctor_id']) ?></div>
                </div>
            </div>
        </div>

        <!-- PRE-CONSULTATION NOTES FROM EHEALTH DOCTOR -->
        <?php if ($sess['teleconsult_reason'] || $sess['chief_complaint'] || $sess['clinical_notes'] || $sess['remarks']): ?>
        <div class="prenotes-card">
            <div class="prenotes-heading">&#128203; Pre-Consultation Notes &nbsp;<span style="font-size:10px;font-weight:500;color:var(--text-soft);text-transform:none;letter-spacing:0;">by <?= htmlspecialchars($sess['ehealth_doctor_name']) ?></span></div>
            <?php if ($sess['teleconsult_reason']): ?>
            <div class="prenote-block">
                <div class="prenote-label">&#9888;&#65039; Teleconsultation Reason</div>
                <div class="prenote-text"><?= nl2br(htmlspecialchars($sess['teleconsult_reason'])) ?></div>
            </div>
            <?php endif; ?>
            <?php if ($sess['chief_complaint']): ?>
            <div class="prenote-block">
                <div class="prenote-label">&#128308; Current Complaint</div>
                <div class="prenote-text"><?= nl2br(htmlspecialchars($sess['chief_complaint'])) ?></div>
            </div>
            <?php endif; ?>
            <?php if ($sess['clinical_notes']): ?>
            <div class="prenote-block">
                <div class="prenote-label">&#128203; Clinical Notes</div>
                <div class="prenote-text"><?= nl2br(htmlspecialchars($sess['clinical_notes'])) ?></div>
            </div>
            <?php endif; ?>
            <?php if ($sess['remarks']): ?>
            <div class="prenote-block">
                <div class="prenote-label">&#128161; Remarks</div>
                <div class="prenote-text"><?= nl2br(htmlspecialchars($sess['remarks'])) ?></div>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- ── DIAGNOSIS FORM (TeleStudio) or readonly notice (eHealth) ── -->
        <?php if ($isTeleStudio): ?>

        <div class="form-card" id="formSection">
            <div class="alert" id="alertBox"></div>

            <div class="form-section-title">Clinical Assessment</div>

            <div class="form-group">
                <label class="form-label">Current Complaint <span class="opt">(optional)</span></label>
                <textarea class="form-control" id="chiefComplaint" placeholder="Describe the patient's main complaints…"></textarea>
            </div>

            <div class="form-group">
                <label class="form-label">Clinical Notes <span class="opt">(optional)</span></label>
                <textarea class="form-control" id="clinicalNotes" placeholder="Physical examination findings, observations…"></textarea>
            </div>

            <div class="form-group">
                <label class="form-label">Provisional Diagnosis</label>
                <textarea class="form-control" id="provisionalDiagnosis" placeholder="Enter diagnosis…"></textarea>
            </div>

            <div class="form-group">
                <label class="form-label">Investigation Prescribed <span class="opt">(optional)</span></label>
                <textarea class="form-control" id="investigationPrescribed" placeholder="Lab tests, X-rays, scans recommended…"></textarea>
            </div>

            <div class="form-section-title">Prescription</div>

            <div class="form-group">
                <label class="form-label">Medicines Prescribed <span class="opt">(optional)</span></label>
                <button type="button" class="btn-add-medicine" id="addMedicineBtn" onclick="addMedicineRow()">&#10133; Add Medicine</button>
                <div class="med-table-wrap">
                    <table class="medicine-table" id="medicineTable">
                        <thead>
                            <tr>
                                <th style="width:24%">Medicine Name</th>
                                <th style="width:14%">Dosage</th>
                                <th style="width:24%">Timing</th>
                                <th style="width:15%">Meal</th>
                                <th style="width:14%">Duration</th>
                                <th style="width:7%"></th>
                            </tr>
                        </thead>
                        <tbody id="medicineTableBody"></tbody>
                    </table>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Review After <span class="opt">(optional)</span></label>
                <select class="form-select" id="reviewAfter" onchange="onReviewChange(this)">
                    <option value="">Select review period</option>
                    <option value="1 day">1 day</option>
                    <option value="2 days">2 days</option>
                    <option value="3 days">3 days</option>
                    <option value="4 days">4 days</option>
                    <option value="5 days">5 days</option>
                    <option value="1 week">1 week</option>
                    <option value="2 weeks">2 weeks</option>
                    <option value="3 weeks">3 weeks</option>
                    <option value="1 month">1 month</option>
                    <option value="other">Other (Please type)</option>
                </select>
                <div class="review-other-wrap" id="reviewOtherWrap">
                    <input type="text" class="review-other-input" id="reviewOtherInput" placeholder="Please specify review period…">
                </div>
            </div>

            <div class="form-section-title">Additional Notes</div>

            <div class="form-group">
                <label class="form-label">Remarks / Additional Instructions <span class="opt">(optional)</span></label>
                <textarea class="form-control" id="diagRemarks" placeholder="Any additional instructions or notes for the patient…"></textarea>
            </div>

        </div><!-- /form-card -->

        <?php else: ?>
        <div class="readonly-notice">
            &#128249; TeleStudio specialist <?= htmlspecialchars($sess['telestudio_doctor_name']) ?> is conducting the diagnosis.<br>
            <span style="font-size:12px;font-weight:400;opacity:0.8;">The diagnosis form will be filled by the TeleStudio doctor.</span>
        </div>
        <?php endif; ?>

        <?php if ($isOldPatient): ?>
        <div class="history-dock" id="historyDock" data-patient-id="<?= htmlspecialchars($sess['patient_id']) ?>">
            <div class="history-dock-head">
                <div class="history-dock-title">Patient Diagnosis History</div>
                <div class="history-dock-tag">REVISITED</div>
            </div>
            <div class="history-tab-row">
                <button class="history-tab-btn active" type="button" data-tab="ehealth">eHealth Center History</button>
                <button class="history-tab-btn" type="button" data-tab="tele">Teleconsultation History</button>
            </div>
            <div class="history-dock-scroll" id="historyDockScroll">
                <div class="hd-empty">Loading history...</div>
            </div>
        </div>
        <?php endif; ?>

    </div><!-- /right-scroll -->

    <!-- SAVE ROW (TeleStudio only) -->
    <?php if ($isTeleStudio): ?>
    <div class="save-row">
        <button class="btn-save-diag" id="saveBtn" onclick="saveDiagnosis()">&#128190; Save Diagnosis &amp; End Consultation</button>
    </div>
    <?php endif; ?>

</div><!-- /right-col -->
</div><!-- /main -->
</div><!-- /app -->

<div id="toast"></div>

<script>
const SESSION_ID    = <?= $sid ?>;
const IS_TELESTUDIO = <?= $isTeleStudio ? 'true' : 'false' ?>;
const ACTIONS_URL   = IS_TELESTUDIO
    ? 'teleconsult_actions.php'
    : '../telestudio_doctor/teleconsult_actions.php';
const HISTORY_API_URL = '../common/patient_history_api.php';
const SHOULD_RING   = false;
const CALL_RING_URLS = ['../assets/ringtone.mp3', '../assets/notification.wav', '../assets/notification.mp3'];
let callRingAudio = null;
let callRingIdx = 0;
let callRingOn = false;
let receivedPollTimer = null;

function callRingUrl() {
    return CALL_RING_URLS[Math.min(callRingIdx, CALL_RING_URLS.length - 1)];
}

function ensureCallRingAudio() {
    if (callRingAudio) return callRingAudio;
    try {
        callRingAudio = new Audio(callRingUrl());
        callRingAudio.loop = true;
        callRingAudio.preload = 'auto';
        callRingAudio.addEventListener('error', () => {
            if (callRingIdx < CALL_RING_URLS.length - 1) {
                callRingIdx++;
                callRingAudio = null;
            }
        }, { once: true });
    } catch (_) {
        callRingAudio = null;
    }
    return callRingAudio;
}

function warmupCallRing() {
    if (!IS_TELESTUDIO) return;
    const audio = ensureCallRingAudio();
    if (!audio) return;
    const p = audio.play();
    if (p && typeof p.then === 'function') {
        p.then(() => {
            audio.pause();
            audio.currentTime = 0;
            callRingOn = false;
            startCallRing();
        }).catch(() => {});
    }
}

function startCallRing() {
    if (!IS_TELESTUDIO || callRingOn) return;
    const audio = ensureCallRingAudio();
    if (!audio) return;
    try { audio.currentTime = 0; } catch (_) {}
    const p = audio.play();
    if (p && typeof p.then === 'function') {
        p.then(() => { callRingOn = true; }).catch(() => {
            if (callRingIdx < CALL_RING_URLS.length - 1) {
                callRingIdx++;
                callRingAudio = null;
                startCallRing();
            }
        });
    } else {
        callRingOn = true;
    }
}

function stopCallRing() {
    if (!callRingAudio) return;
    try {
        callRingAudio.pause();
        callRingAudio.currentTime = 0;
    } catch (_) {}
    callRingOn = false;
}

async function markCallReceived() {
    if (IS_TELESTUDIO) return;
    try {
        await fetch(ACTIONS_URL + '?action=mark_received', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ sessionId: SESSION_ID })
        });
    } catch (_) {}
}

async function pollCallReceived() {
    if (!IS_TELESTUDIO) return;
    try {
        const res = await fetch(ACTIONS_URL + '?action=get_received&sessionId=' + SESSION_ID, {
            method: 'GET',
            credentials: 'same-origin',
            cache: 'no-store'
        });
        if (!res.ok) return;
        const data = await res.json();
        if (data && data.success && data.received) {
            stopCallRing();
            if (receivedPollTimer) {
                clearInterval(receivedPollTimer);
                receivedPollTimer = null;
            }
        }
    } catch (_) {}
}

if (IS_TELESTUDIO) {
    if (SHOULD_RING) {
        startCallRing();
        receivedPollTimer = setInterval(pollCallReceived, 2000);
        setTimeout(pollCallReceived, 1000);
        ['click', 'keydown', 'touchstart'].forEach((evt) => {
            window.addEventListener(evt, warmupCallRing, { once: true, passive: true });
        });
    }
} else {
    setTimeout(markCallReceived, 600);
}

// ── TIMER ────────────────────────────────────────────────────
let timerSecs = 0;
const timerEl = document.getElementById('callTimer');
setInterval(() => {
    timerSecs++;
    const h = String(Math.floor(timerSecs/3600)).padStart(2,'0');
    const m = String(Math.floor((timerSecs%3600)/60)).padStart(2,'0');
    const s = String(timerSecs%60).padStart(2,'0');
    timerEl.textContent = `${h}:${m}:${s}`;
}, 1000);

let dockHistoryData = null;
let dockActiveTab = 'ehealth';

function historyEsc(v) {
    return String(v ?? '').replace(/[&<>"']/g, (m) => (
        {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]
    ));
}

function historyFmtDate(v) {
    if (!v) return '-';
    const d = new Date(String(v).replace(' ', 'T'));
    if (Number.isNaN(d.getTime())) return String(v);
    return d.toLocaleString('en-IN', {
        day: '2-digit', month: 'short', year: 'numeric',
        hour: '2-digit', minute: '2-digit'
    });
}

function historyVitalsLine(r) {
    const bp = (r.bp_systolic && r.bp_diastolic) ? `${r.bp_systolic}/${r.bp_diastolic}` : '-';
    return `BP ${bp} | HR ${r.heart_rate ?? '-'} | Temp ${r.temperature ?? '-'} | SpO2 ${r.spo2 ?? '-'} | RR ${r.resp_rate ?? '-'} | BMI ${r.bmi ?? '-'}`;
}

function historyParseTeleMeds(mJson) {
    if (!mJson) return [];
    try {
        const arr = JSON.parse(mJson);
        return Array.isArray(arr) ? arr : [];
    } catch {
        return [];
    }
}

function renderDockCards() {
    const box = document.getElementById('historyDockScroll');
    if (!box || !dockHistoryData) return;

    const rows = dockActiveTab === 'tele'
        ? (dockHistoryData.tele_history || [])
        : (dockHistoryData.ehealth_history || []);

    if (!rows.length) {
        box.innerHTML = `<div class="hd-empty">No ${dockActiveTab === 'tele' ? 'teleconsultation' : 'eHealth center'} history found.</div>`;
        return;
    }

    if (dockActiveTab === 'tele') {
        box.innerHTML = rows.map((r) => {
            const meds = historyParseTeleMeds(r.medicines_json);
            const medText = meds.length
                ? meds.map((m) => `${m.name || '-'} ${m.dosage ? `(${m.dosage})` : ''}`).join(', ')
                : 'None';
            const chiefVal = r.chief_complaint || r.consultation_notes || '-';
            const clinicalVal = r.clinical_notes || r.consultation_notes || '-';
            const consultVal = r.consultation_notes || r.clinical_notes || r.chief_complaint || '-';

            return `
            <article class="history-dock-card tele">
                <div class="hd-date">${historyEsc(historyFmtDate(r.diagnosis_date))}</div>
                <div class="hd-row"><b>eHealth Doctor:</b> ${historyEsc(r.ehealth_doctor_name || '-')} (${historyEsc(r.ehealth_doctor_id || '-')})</div>
                <div class="hd-row"><b>TeleStudio Specialist:</b> ${historyEsc(r.telestudio_doctor_name || '-')} (${historyEsc(r.telestudio_doctor_id || '-')})</div>
                <div class="hd-row"><b>Teleconsult Reason:</b> ${historyEsc(r.teleconsult_reason || '-')}</div>
                <div class="hd-row"><b>Chief Complaint:</b> ${historyEsc(chiefVal)}</div>
                <div class="hd-row"><b>Clinical Notes:</b> ${historyEsc(clinicalVal)}</div>
                <div class="hd-row"><b>Consultation Notes:</b> ${historyEsc(consultVal)}</div>
                <div class="hd-row"><b>Diagnosis:</b> ${historyEsc(r.primary_diagnosis || '-')}</div>
                <div class="hd-row"><b>Investigation:</b> ${historyEsc(r.investigation || '-')}</div>
                <div class="hd-row"><b>Review After:</b> ${historyEsc(r.review_after || '-')}</div>
                <div class="hd-row"><b>Remarks:</b> ${historyEsc(r.remarks || '-')}</div>
                <div class="hd-row"><b>Medicines:</b> ${historyEsc(medText)}</div>
                <div class="hd-vitals"><b>Vitals Used:</b> ${historyEsc(historyVitalsLine(r))}</div>
            </article>`;
        }).join('');
        return;
    }

    box.innerHTML = rows.map((r) => {
        const meds = Array.isArray(r.medicines) ? r.medicines : [];
        const medText = meds.length
            ? meds.map((m) => `${m.medicine_name || '-'} ${m.dosage ? `(${m.dosage})` : ''}`).join(', ')
            : 'None';
        return `
        <article class="history-dock-card">
            <div class="hd-date">${historyEsc(historyFmtDate(r.diagnosis_date))}</div>
            <div class="hd-row"><b>Doctor:</b> ${historyEsc(r.doctor_name || '-')}${r.doctor_id ? ` (${historyEsc(r.doctor_id)})` : ''}</div>
            <div class="hd-row"><b>Chief Complaint:</b> ${historyEsc(r.chief_complaint || '-')}</div>
            <div class="hd-row"><b>Clinical Notes:</b> ${historyEsc(r.clinical_notes || '-')}</div>
            <div class="hd-row"><b>Diagnosis:</b> ${historyEsc(r.provisional_diagnosis || '-')}</div>
            <div class="hd-row"><b>Investigation:</b> ${historyEsc(r.investigation_prescribed || '-')}</div>
            <div class="hd-row"><b>Review After:</b> ${historyEsc(r.review_after || '-')}</div>
            <div class="hd-row"><b>Remarks:</b> ${historyEsc(r.remarks || '-')}</div>
            <div class="hd-row"><b>Medicines:</b> ${historyEsc(medText)}</div>
            <div class="hd-vitals"><b>Vitals Used:</b> ${historyEsc(historyVitalsLine(r))}</div>
        </article>`;
    }).join('');
}

function initHistoryDockTabs() {
    const tabs = document.querySelectorAll('.history-tab-btn');
    if (!tabs.length) return;
    tabs.forEach((btn) => {
        btn.addEventListener('click', () => {
            dockActiveTab = btn.dataset.tab === 'tele' ? 'tele' : 'ehealth';
            tabs.forEach((x) => x.classList.toggle('active', x === btn));
            renderDockCards();
        });
    });
}

async function loadHistoryDock() {
    const dock = document.getElementById('historyDock');
    if (!dock) return;
    const pid = dock.dataset.patientId || '';
    if (!pid) return;

    const box = document.getElementById('historyDockScroll');
    if (box) box.innerHTML = '<div class="hd-empty">Loading history...</div>';

    try {
        const res = await fetch(`${HISTORY_API_URL}?patientId=${encodeURIComponent(pid)}`, { credentials: 'same-origin' });
        const data = await res.json();
        if (!data.success) {
            if (box) box.innerHTML = `<div class="hd-empty">${historyEsc(data.error || 'Could not load history.')}</div>`;
            return;
        }
        dockHistoryData = data;
        renderDockCards();
    } catch {
        if (box) box.innerHTML = '<div class="hd-empty">Server error while loading history.</div>';
    }
}

initHistoryDockTabs();
loadHistoryDock();

// ── REVIEW AFTER ─────────────────────────────────────────────
function onReviewChange(sel) {
    const wrap = document.getElementById('reviewOtherWrap');
    if (wrap) wrap.classList.toggle('show', sel.value === 'other');
}

// ── MEDICINE TABLE (identical logic to diagnosis_form.php) ───
let medicineCounter = 0;

function addMedicineRow() {
    medicineCounter++;
    const id    = medicineCounter;
    const tbody = document.getElementById('medicineTableBody');
    const row   = document.createElement('tr');
    row.id = `med-row-${id}`;
    row.innerHTML = `
        <td><input type="text" class="med-name" placeholder="Medicine name"></td>
        <td>
            <select class="med-dosage" onchange="onDosageChange(this,${id})">
                <option value="">Select</option>
                <option>100mg</option><option>250mg</option><option>500mg</option>
                <option>1g</option><option>50mg</option><option>200mg</option>
                <option>400mg</option><option>625mg</option>
                <option>1 tablet</option><option>2 tablets</option><option>½ tablet</option>
                <option>1 capsule</option><option>2 capsules</option>
                <option>5ml</option><option>10ml</option><option>15ml</option>
                <option>1 tsp</option><option>2 tsp</option>
                <option value="other">Other (type)</option>
            </select>
            <input type="text" class="med-dosage-other" id="dosage-other-${id}"
                   placeholder="Type dosage…"
                   style="display:none;margin-top:5px;width:100%;padding:5px 8px;border:1.5px solid var(--g-border);border-radius:7px;font-size:12.5px;font-family:'Sora',sans-serif;outline:none;">
        </td>
        <td><div class="timing-checkboxes">
            <label><input type="checkbox" value="Morning"> Morning</label>
            <label><input type="checkbox" value="Afternoon"> Afternoon</label>
            <label><input type="checkbox" value="Evening"> Evening</label>
            <label><input type="checkbox" value="Night"> Night</label>
        </div></td>
        <td><select class="med-meal">
            <option value="">Select</option>
            <option>Before Meal</option><option>After Meal</option>
            <option>Empty Stomach</option><option>With Meal</option>
        </select></td>
        <td>
            <select class="med-duration" onchange="onDurationChange(this,${id})">
                <option value="">Select</option>
                <option>1 day</option><option>2 days</option><option>3 days</option>
                <option>4 days</option><option>5 days</option>
                <option>1 week</option><option>2 weeks</option><option>3 weeks</option>
                <option>1 month</option>
                <option value="other">Other (Please type)</option>
            </select>
            <input type="text" class="med-duration-other" id="duration-other-${id}"
                   placeholder="Please specify…"
                   style="display:none;margin-top:5px;width:100%;padding:5px 8px;border:1.5px solid var(--g-border);border-radius:7px;font-size:12.5px;font-family:'Sora',sans-serif;outline:none;">
        </td>
        <td><button type="button" class="btn-remove-med" onclick="removeMedRow(${id})">&#x2715;</button></td>
    `;
    tbody.appendChild(row);
    document.getElementById('medicineTable').classList.add('show');
}

function onDosageChange(sel, id) {
    const inp = document.getElementById(`dosage-other-${id}`);
    inp.style.display = sel.value === 'other' ? 'block' : 'none';
    if (sel.value !== 'other') inp.value = '';
}
function onDurationChange(sel, id) {
    const inp = document.getElementById(`duration-other-${id}`);
    inp.style.display = sel.value === 'other' ? 'block' : 'none';
    if (sel.value !== 'other') inp.value = '';
}
function removeMedRow(id) {
    const row = document.getElementById(`med-row-${id}`);
    if (row) row.remove();
    if (!document.getElementById('medicineTableBody').children.length)
        document.getElementById('medicineTable').classList.remove('show');
}

function collectMedicines() {
    const meds = [];
    document.querySelectorAll('#medicineTableBody tr').forEach(row => {
        const name      = row.querySelector('.med-name').value.trim();
        const dosageSel = row.querySelector('.med-dosage');
        const dosageOth = row.querySelector('.med-dosage-other');
        const dosage    = dosageSel.value === 'other' ? (dosageOth?.value.trim()||'') : dosageSel.value;
        const meal      = row.querySelector('.med-meal').value;
        const durSel    = row.querySelector('.med-duration');
        const durOth    = row.querySelector('.med-duration-other');
        const duration  = durSel.value === 'other' ? (durOth?.value.trim()||'') : durSel.value;
        const timing    = [...row.querySelectorAll('input[type=checkbox]:checked')].map(c=>c.value);
        if (name) meds.push({ name, dosage, timing, mealInstruction: meal, duration });
    });
    return meds;
}

function getReviewAfterValue() {
    const sel = document.getElementById('reviewAfter');
    if (!sel) return '';
    return sel.value === 'other' ? document.getElementById('reviewOtherInput').value.trim() : sel.value;
}

// ── SAVE DIAGNOSIS ───────────────────────────────────────────
async function saveDiagnosis() {
    if (!confirm('Save diagnosis and end consultation? This will close this window.')) return;

    const btn = document.getElementById('saveBtn');
    btn.disabled = true; btn.textContent = '⏳ Saving…';

    const payload = {
        sessionId              : SESSION_ID,
        chiefComplaint         : document.getElementById('chiefComplaint')?.value.trim() || '',
        clinicalNotes          : document.getElementById('clinicalNotes')?.value.trim() || '',
        consultationNotes      : document.getElementById('clinicalNotes')?.value.trim() || '',
        primaryDiagnosis       : document.getElementById('provisionalDiagnosis')?.value.trim() || '',
        investigation          : document.getElementById('investigationPrescribed')?.value.trim() || '',
        medicines              : collectMedicines(),
        reviewAfter            : getReviewAfterValue(),
        remarks                : document.getElementById('diagRemarks')?.value.trim() || '',
    };

    try {
        const res  = await fetch(ACTIONS_URL + '?action=save_diagnosis', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        if (res.status === 401) {
            showAlert('❌ Session expired. Please login again and then save diagnosis.', 'error');
            btn.disabled = false; btn.textContent = '💾 Save Diagnosis & End Consultation';
            return;
        }
        const data = await res.json();
        if (data.success) {
            stopCallRing();
            if (receivedPollTimer) {
                clearInterval(receivedPollTimer);
                receivedPollTimer = null;
            }
            showAlert('✅ Diagnosis saved. Closing consultation…', 'success');
            setTimeout(() => {
                window.location.href = IS_TELESTUDIO
                    ? 'telestudio_queue.php'
                    : '../ehealth_center_doctor/patient_queue.php';
            }, 1200);
        } else {
            showAlert('❌ ' + (data.error || 'Failed to save. Please try again.'), 'error');
            btn.disabled = false; btn.textContent = '💾 Save Diagnosis & End Consultation';
        }
    } catch (e) {
        showAlert('❌ Network error. Please try again.', 'error');
        btn.disabled = false; btn.textContent = '💾 Save Diagnosis & End Consultation';
    }
}

// ── NOTIFY ────────────────────────────────────────────────────
let notifyTimer = null;
async function sendNotify() {
    const btn = document.getElementById('notifyBtn');
    if (!btn || btn.disabled) return;
    btn.disabled = true; btn.textContent = '⏳ Sending…';
    await fetch(ACTIONS_URL + '?action=notify', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ sessionId: SESSION_ID })
    });
    showToast('🔔 Notification sent to eHealth doctor');
    btn.textContent = '✅ Sent!';
    clearTimeout(notifyTimer);
    notifyTimer = setTimeout(() => {
        if (btn) { btn.disabled = false; btn.textContent = '🔔 Notify eHealth Doctor'; }
    }, 10000);
}

// ── END SESSION ───────────────────────────────────────────────
async function endConsultation() {
    if (IS_TELESTUDIO) {
        if (!confirm('End video call now and keep the diagnosis form open?')) return;
        stopCallRing();
        if (receivedPollTimer) {
            clearInterval(receivedPollTimer);
            receivedPollTimer = null;
        }

        const iframe = document.getElementById('whereby-iframe');
        if (iframe) iframe.src = 'about:blank';

        const container = document.getElementById('wherebyContainer');
        if (container) { container.innerHTML = ''; container.style.display = 'none'; }

        const noRoom = document.getElementById('noRoomMsg');
        if (noRoom) noRoom.style.display = 'flex';

        const statusEl = document.getElementById('pollStatus');
        if (statusEl) statusEl.textContent = 'Video ended. Continue diagnosis.';

        const btn = document.getElementById('endBtn');
        if (btn) { btn.disabled = true; btn.textContent = '■ Video Ended'; }

        showToast('Video ended. Diagnosis form remains open.');
        return;
    }

    if (!confirm('End this teleconsultation session?')) return;
    await fetch(ACTIONS_URL + '?action=end', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ sessionId: SESSION_ID })
    });
    window.location.href = IS_TELESTUDIO
        ? 'telestudio_queue.php'
        : '../ehealth_center_doctor/patient_queue.php';
}

// ── POLL FOR ROOM URL (eHealth doctor only) ─────────────────
<?php if (!$isTeleStudio && !$wherebyUrl): ?>
(function() {
    let pollCount = 0;
    const maxPolls = 60;

    function pollForRoom() {
        pollCount++;
        const statusEl = document.getElementById('pollStatus');
        if (statusEl) statusEl.textContent = 'Connecting… (' + pollCount + ')';

        fetch(ACTIONS_URL + '?action=get_status&sessionId=' + SESSION_ID, { credentials: 'same-origin' })
            .then(r => r.json())
            .then(data => {
                if (data.success && data.roomUrl) {
                    // Inject plain iframe — same as Node.js project
                    const container  = document.getElementById('wherebyContainer');
                    const noRoom     = document.getElementById('noRoomMsg');
                    const dName      = encodeURIComponent('<?= addslashes($uname) ?> (eHealth)');
                    const src        = data.roomUrl.split('?')[0] + '?embed&displayName=' + dName;

                    const iframe     = document.createElement('iframe');
                    iframe.src       = src;
                    iframe.allow     = 'camera; microphone; fullscreen; speaker; display-capture';
                    iframe.style.cssText = 'width:100%;height:100%;border:none;display:block;';
                    container.appendChild(iframe);
                    container.style.display = 'block';
                    if (noRoom) noRoom.style.display = 'none';
                } else if (pollCount < maxPolls) {
                    setTimeout(pollForRoom, 2000);
                } else {
                    if (statusEl) statusEl.textContent = 'Could not connect. Please refresh.';
                }
            })
            .catch(() => { if (pollCount < maxPolls) setTimeout(pollForRoom, 3000); });
    }

    setTimeout(pollForRoom, 1000);
})();
<?php endif; ?>

// ── HELPERS ───────────────────────────────────────────────────
let toastTimer;
function showToast(msg) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.style.transform = 'translateX(-50%) translateY(0)'; t.style.opacity = '1';
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { t.style.transform='translateX(-50%) translateY(80px)'; t.style.opacity='0'; }, 3500);
}
function showAlert(msg, type) {
    const el = document.getElementById('alertBox');
    if (!el) return;
    el.className = `alert ${type} show`;
    el.textContent = msg;
    el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    document.querySelector('.right-scroll').scrollTop = 0;
}
window.addEventListener('beforeunload', () => {
    stopCallRing();
    if (receivedPollTimer) {
        clearInterval(receivedPollTimer);
        receivedPollTimer = null;
    }
});
</script>
<script src="../assets/doctor_notifications.js"></script>
</body>
</html>
