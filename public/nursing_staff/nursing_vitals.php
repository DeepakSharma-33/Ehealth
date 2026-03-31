<?php
require_once __DIR__ . '/../../config/config.php';
require_role('nursing_staff', 'nursing_login.php');

if (isset($_POST['logout'])) {
    logout_user();
    header('Location: nursing_login.php');
    exit;
}

$nurse_name = htmlspecialchars(get_session_name());
$nursing_id   = $_SESSION['nursing_id'] ?? '';
$prefill_pid = strtoupper(trim($_GET['pid'] ?? ''));
if (!preg_match('/^[A-Z0-9]{3,20}$/', $prefill_pid)) {
    $prefill_pid = '';
}

// ── AJAX: Search patient ────────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'search_patient') {
    header('Content-Type: application/json');
    $pid = strtoupper(trim($_GET['patient_id'] ?? ''));
    if (!$pid) { echo json_encode(['success' => false, 'error' => 'Patient ID is required.']); exit; }

    $db   = db();
    $stmt = $db->prepare('SELECT patient_id, full_name, dob, gender, mobile_no, photo_filename FROM patients WHERE patient_id = ? LIMIT 1');
    $stmt->bind_param('s', $pid);
    $stmt->execute();
    $row  = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) { echo json_encode(['success' => false, 'error' => "Patient {$pid} not found."]); exit; }
    $row['age'] = $row['dob'] ? (int) date_diff(date_create($row['dob']), date_create('today'))->y : '—';
    $row['photo_url'] = patient_photo_url($row['photo_filename'] ?? null);
    unset($row['photo_filename']);
    echo json_encode(['success' => true, 'patient' => $row]);
    exit;
}

// ── AJAX: Vitals history ────────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'vitals_history') {
    header('Content-Type: application/json');
    $pid  = strtoupper(trim($_GET['patient_id'] ?? ''));
    $db   = db();
    $stmt = $db->prepare(
        'SELECT id, patient_id, bp_systolic, bp_diastolic, heart_rate, temperature,
                spo2, resp_rate, weight, height, bmi, notes,
                recorded_by_exec_id, recorded_by_exec_name, recorded_at
         FROM patient_vitals WHERE patient_id = ? ORDER BY recorded_at DESC'
    );
    $stmt->bind_param('s', $pid);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    echo json_encode(['success' => true, 'vitals' => $rows]);
    exit;
}

// ── AJAX: Save vitals ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_vitals') {
    header('Content-Type: application/json');

    $pid    = strtoupper(trim($_POST['patientId'] ?? ''));
    $bpSys  = $_POST['bpSystolic']  !== '' ? (int)$_POST['bpSystolic']    : null;
    $bpDia  = $_POST['bpDiastolic'] !== '' ? (int)$_POST['bpDiastolic']   : null;
    $hr     = $_POST['heartRate']   !== '' ? (int)$_POST['heartRate']     : null;
    $temp   = $_POST['temperature'] !== '' ? (float)$_POST['temperature'] : null;
    $spo2   = $_POST['spo2']        !== '' ? (int)$_POST['spo2']          : null;
    $rr     = $_POST['respRate']    !== '' ? (int)$_POST['respRate']      : null;
    $weight = $_POST['weight']      !== '' ? (float)$_POST['weight']      : null;
    $height = $_POST['height']      !== '' ? (float)$_POST['height']      : null;
    $bmi    = $_POST['bmi']         !== '' ? (float)$_POST['bmi']         : null;
    $notes  = trim($_POST['notes']  ?? '') ?: null;

    if (!$pid) { echo json_encode(['success' => false, 'error' => 'Patient ID is required.']); exit; }

    // Range validation
    foreach ([
        ['bpSystolic',  $bpSys,  50,  250],
        ['bpDiastolic', $bpDia,  30,  150],
        ['heartRate',   $hr,     20,  300],
        ['temperature', $temp,   28,   45],
        ['spo2',        $spo2,   50,  100],
        ['respRate',    $rr,      1,   80],
        ['weight',      $weight, 0.5, 500],
        ['height',      $height,  20, 300],
    ] as [$field, $val, $min, $max]) {
        if ($val !== null && ($val < $min || $val > $max)) {
            echo json_encode(['success' => false, 'error' => "{$field} value {$val} is out of range ({$min}–{$max})."]);
            exit;
        }
    }

    $db = db();

    // Verify patient exists
    $st = $db->prepare('SELECT patient_id FROM patients WHERE patient_id = ? LIMIT 1');
    $st->bind_param('s', $pid);
    $st->execute();
    if (!$st->get_result()->fetch_assoc()) {
        $st->close();
        echo json_encode(['success' => false, 'error' => "Patient {$pid} not found."]);
        exit;
    }
    $st->close();

    // NULL-safe helpers
    $nInt = fn($v) => $v === null ? 'NULL' : (int)$v;
    $nDec = fn($v) => $v === null ? 'NULL' : number_format((float)$v, 1, '.', '');
    $nStr = fn($v) => $v === null ? 'NULL' : "'" . $db->real_escape_string($v) . "'";
    $esc  = fn($v) => $db->real_escape_string((string)$v);

    $db->query("INSERT INTO patient_vitals
        (patient_id, bp_systolic, bp_diastolic, heart_rate, temperature,
         spo2, resp_rate, weight, height, bmi, notes,
         recorded_by_exec_id, recorded_by_exec_name)
        VALUES (
            '{$esc($pid)}',
            {$nInt($bpSys)}, {$nInt($bpDia)}, {$nInt($hr)}, {$nDec($temp)},
            {$nInt($spo2)}, {$nInt($rr)}, {$nDec($weight)}, {$nDec($height)}, {$nDec($bmi)},
            {$nStr($notes)},
            '{$esc($nursing_id)}', '{$esc($nurse_name)}'
        )");

    if ($db->error) { echo json_encode(['success' => false, 'error' => 'DB error: ' . $db->error]); exit; }

    $db->query("UPDATE patients SET vitals_recorded = TRUE WHERE patient_id = '{$esc($pid)}'");

    echo json_encode(['success' => true, 'message' => 'Vitals saved successfully.']);
    exit;
}

$db = db();
ensure_patient_revisit_schema($db);
$queue = [];
$q = $db->query(
    "SELECT p.patient_id, p.full_name, p.age, p.gender, p.mobile_no, p.photo_filename,
            COALESCE(p.patient_flag, 'N') AS patient_flag
     FROM patients p
     WHERE COALESCE(p.diagnosis_status, 'pending') = 'pending'
       AND COALESCE(p.vitals_recorded, 0) = 0
     ORDER BY p.created_at DESC"
);
if ($q) {
    while ($row = $q->fetch_assoc()) {
        $row['photo_url'] = patient_photo_url($row['photo_filename'] ?? null);
        $queue[] = $row;
    }
}
$queue_count = count($queue);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enter Vitals - eHealth System</title>
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
            --error:       #c0392b;
            --warn-bg:     #fff3cd;
            --warn-text:   #856404;
            --good-bg:     #d4edda;
            --good-text:   #155724;
            --bad-bg:      #f8d7da;
            --bad-text:    #721c24;
        }
        body { font-family: 'DM Sans', sans-serif; background: var(--green-light); min-height: 100vh; }

        /* ── Navbar ── */
        .navbar { background: var(--green-nav); padding: 0 40px; height: 56px; display: flex; align-items: center; justify-content: space-between; box-shadow: 0 2px 12px rgba(0,0,0,0.18); position: sticky; top: 0; z-index: 100; }
        .navbar-brand { display: flex; align-items: center; gap: 10px; text-decoration: none; color: white; }
        .brand-icon { width: 34px; height: 34px; background: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 17px; }
        .brand-name { font-size: 19px; font-weight: 700; letter-spacing: 1.5px; }
        .nav-right { display: flex; align-items: center; gap: 20px; }
        .nav-links { display: flex; gap: 32px; list-style: none; }
        .nav-links a { color: rgba(255,255,255,0.88); text-decoration: none; font-size: 13px; font-weight: 500; letter-spacing: 0.5px; transition: color .2s; }
        .nav-links a:hover { color: white; }
        .nurse-pill { display: flex; align-items: center; gap: 8px; background: rgba(255,255,255,0.15); border-radius: 20px; padding: 5px 14px 5px 8px; }
        .nurse-pill-avatar { width: 26px; height: 26px; background: var(--green-light); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 13px; }
        .nurse-pill-name { font-size: 13px; font-weight: 600; color: white; }
        .logout-btn { background: rgba(231,76,60,0.2); color: white; border: 2px solid rgba(255,255,255,0.5); padding: 6px 14px; border-radius: 16px; font-size: 12px; font-weight: 600; cursor: pointer; font-family: inherit; transition: background .2s; }
        .logout-btn:hover { background: rgba(231,76,60,0.4); }

        /* ── Page ── */
        .page-body { max-width: 1000px; margin: 0 auto; padding: 32px 20px 60px; }
        .page-title { margin-bottom: 28px; }
        .page-title h1 { font-family: 'DM Serif Display', serif; font-size: 28px; color: var(--green-dark); margin-bottom: 4px; }
        .page-title p { font-size: 14px; color: var(--muted); }

        /* ── Cards ── */
        .card { background: var(--white); border-radius: 16px; box-shadow: 0 2px 16px rgba(27,67,50,0.09); margin-bottom: 20px; overflow: hidden; }
        .card-header { padding: 18px 24px 14px; border-bottom: 1.5px solid var(--green-light); display: flex; align-items: center; gap: 10px; }
        .card-header-icon { width: 34px; height: 34px; background: var(--green-light); border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 17px; }
        .card-header h2 { font-size: 16px; font-weight: 700; color: var(--green-dark); }
        .card-body { padding: 22px 24px; }

        /* ── Queue ── */
        .queue-count { margin-left: auto; background: var(--green-light); color: var(--green-dark); padding: 4px 10px; border-radius: 12px; font-size: 12px; font-weight: 700; }
        .queue-meta-text { font-size: 13px; color: var(--muted); margin-bottom: 14px; }
        .queue-list { display: grid; gap: 12px; }
        .queue-item { width: 100%; text-align: left; background: white; border: 1.5px solid var(--green-light); border-radius: 14px; padding: 14px 16px; display: flex; align-items: center; justify-content: space-between; gap: 12px; cursor: pointer; transition: transform .2s, box-shadow .2s, border-color .2s; font-family: inherit; }
        .queue-item:hover { transform: translateY(-2px); box-shadow: 0 6px 18px rgba(27,67,50,0.12); border-color: var(--green-mid); }
        .queue-item:focus { outline: 3px solid rgba(64,145,108,.25); outline-offset: 2px; }
        .queue-left { display: flex; align-items: center; gap: 12px; min-width: 0; }
        .queue-photo { width: 44px; height: 44px; border-radius: 50%; object-fit: cover; border: 2px solid var(--green-light); }
        .queue-photo-placeholder { width: 44px; height: 44px; border-radius: 50%; background: var(--green-light); border: 2px solid var(--green-mid); display: flex; align-items: center; justify-content: center; font-size: 18px; color: var(--green-dark); }
        .queue-text { min-width: 0; }
        .queue-name { font-size: 15px; font-weight: 700; color: var(--green-dark); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .queue-meta { font-size: 12px; color: var(--muted); margin-top: 4px; display: flex; flex-wrap: wrap; gap: 10px; }
        .queue-right { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
        .queue-badge { font-size: 11px; font-weight: 700; padding: 4px 9px; border-radius: 12px; }
        .queue-badge.new { background: var(--green-pale); color: var(--green-dark); }
        .queue-badge.old { background: #fff3e0; color: #e65100; }
        .queue-action { font-size: 12px; font-weight: 700; color: white; background: var(--green-nav); border-radius: 12px; padding: 6px 10px; white-space: nowrap; }
        .queue-empty { text-align: center; font-size: 14px; color: var(--muted); padding: 24px 12px; border: 1.5px dashed var(--border); border-radius: 12px; background: #f7fbf8; }

        /* ── Search ── */
        .search-row { display: flex; gap: 12px; align-items: flex-end; }
        .search-input-wrap { flex: 1; }
        .search-input-wrap label { display: block; font-size: 13px; font-weight: 600; color: var(--text); margin-bottom: 7px; }
        .search-input-wrap input { width: 100%; padding: 11px 16px; border: 2px solid var(--border); border-radius: 10px; font-size: 15px; font-family: inherit; color: var(--text); background: var(--green-pale); transition: border-color .2s; letter-spacing: 1px; font-weight: 600; }
        .search-input-wrap input:focus { outline: none; border-color: var(--green-mid); box-shadow: 0 0 0 3px rgba(64,145,108,.12); background: white; }
        .search-btn { padding: 11px 26px; background: var(--green-nav); color: white; border: none; border-radius: 10px; font-size: 14px; font-weight: 700; font-family: inherit; cursor: pointer; transition: background .2s, transform .1s; white-space: nowrap; }
        .search-btn:hover { background: var(--green-dark); transform: translateY(-1px); }
        .search-btn:disabled { opacity: .6; cursor: not-allowed; transform: none; }

        /* ── Alert ── */
        .alert { padding: 12px 16px; border-radius: 9px; font-size: 14px; margin-bottom: 16px; border-left: 4px solid transparent; animation: slideIn .25s ease; display: none; }
        @keyframes slideIn { from{opacity:0;transform:translateY(-6px)} to{opacity:1;transform:translateY(0)} }
        .alert.show-error   { background: var(--bad-bg);  color: var(--bad-text);  border-color: var(--error); display: block; }
        .alert.show-success { background: var(--good-bg); color: var(--good-text); border-color: var(--green-mid); display: block; }
        .alert.show-info    { background: #e3f2fd; color: #0d47a1; border-color: #1565c0; display: block; }

        /* ── Patient Banner ── */
        .patient-banner { display: none; background: linear-gradient(135deg, var(--green-pale), #e8f5e9); border: 1.5px solid var(--border); border-radius: 14px; padding: 18px 22px; margin-bottom: 20px; align-items: center; gap: 18px; animation: slideIn .3s ease; }
        .patient-banner.show { display: flex; }
        .patient-photo { width: 72px; height: 72px; border-radius: 50%; object-fit: cover; border: 3px solid var(--green-mid); flex-shrink: 0; }
        .patient-photo-placeholder { width: 72px; height: 72px; border-radius: 50%; background: var(--green-light); border: 3px solid var(--green-mid); display: flex; align-items: center; justify-content: center; font-size: 28px; flex-shrink: 0; }
        .patient-info { flex: 1; }
        .patient-name { font-size: 20px; font-weight: 700; color: var(--green-dark); margin-bottom: 6px; }
        .patient-meta { display: flex; flex-wrap: wrap; gap: 16px; }
        .meta-item { font-size: 13px; color: var(--muted); }
        .meta-item strong { color: var(--text); font-weight: 600; }
        .patient-id-badge { background: var(--green-nav); color: white; font-size: 12px; font-weight: 700; padding: 5px 14px; border-radius: 20px; letter-spacing: 1px; flex-shrink: 0; }

        /* ── Sections ── */
        .section-title { display: flex; align-items: center; gap: 10px; font-size: 16px; font-weight: 700; color: var(--green-dark); margin-bottom: 18px; padding-bottom: 12px; border-bottom: 2px solid var(--green-light); }
        .section-icon { font-size: 20px; }

        /* ── Vitals grid ── */
        .vitals-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 18px; margin-bottom: 20px; }
        .vital-field { display: flex; flex-direction: column; }
        .vital-field label { font-size: 12px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: .5px; margin-bottom: 7px; display: flex; align-items: center; gap: 5px; }
        .info-tip { width: 16px; height: 16px; background: var(--green-light); border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 10px; color: var(--green-nav); cursor: help; font-style: normal; position: relative; }
        .info-tip:hover::after { content: attr(data-tip); position: absolute; bottom: 24px; left: 50%; transform: translateX(-50%); background: var(--green-dark); color: white; font-size: 11px; padding: 5px 10px; border-radius: 6px; white-space: nowrap; z-index: 10; pointer-events: none; }

        .input-with-unit { display: flex; align-items: center; border: 2px solid var(--border); border-radius: 10px; overflow: hidden; transition: border-color .2s, box-shadow .2s; background: white; }
        .input-with-unit:focus-within { border-color: var(--green-mid); box-shadow: 0 0 0 3px rgba(64,145,108,.1); }
        .input-with-unit input { flex: 1; padding: 11px 14px; border: none; outline: none; font-size: 15px; font-family: inherit; font-weight: 600; color: var(--text); background: transparent; min-width: 0; }
        .input-with-unit input::placeholder { font-weight: 400; color: #aaa; }
        .unit-badge { padding: 0 12px; font-size: 12px; font-weight: 600; color: var(--muted); background: var(--green-pale); border-left: 1px solid var(--border); height: 100%; display: flex; align-items: center; white-space: nowrap; }

        .bp-row { display: flex; align-items: center; border: 2px solid var(--border); border-radius: 10px; overflow: hidden; transition: border-color .2s, box-shadow .2s; background: white; }
        .bp-row:focus-within { border-color: var(--green-mid); box-shadow: 0 0 0 3px rgba(64,145,108,.1); }
        .bp-row input { flex: 1; padding: 11px 12px; border: none; outline: none; font-size: 15px; font-family: inherit; font-weight: 600; color: var(--text); background: transparent; min-width: 0; }
        .bp-sep { padding: 0 6px; font-size: 20px; font-weight: 700; color: var(--muted); }
        .bp-unit { padding: 0 12px; font-size: 12px; font-weight: 600; color: var(--muted); background: var(--green-pale); border-left: 1px solid var(--border); height: 44px; display: flex; align-items: center; white-space: nowrap; }

        .vital-status { margin-top: 5px; font-size: 12px; font-weight: 600; padding: 2px 9px; border-radius: 10px; display: inline-block; opacity: 0; transition: opacity .25s; }
        .vital-status.show   { opacity: 1; }
        .vital-status.normal { background: var(--good-bg); color: var(--good-text); }
        .vital-status.low    { background: #e3f2fd; color: #0d47a1; }
        .vital-status.high   { background: var(--bad-bg); color: var(--bad-text); }
        .vital-status.hint   { background: var(--warn-bg); color: var(--warn-text); }

        .bmi-display { padding: 11px 14px; background: var(--green-pale); border: 2px solid var(--border); border-radius: 10px; font-size: 20px; font-weight: 800; color: var(--green-dark); text-align: center; min-height: 46px; display: flex; align-items: center; justify-content: center; }

        .notes-textarea { width: 100%; padding: 12px 16px; border: 2px solid var(--border); border-radius: 10px; font-size: 14px; font-family: inherit; color: var(--text); resize: vertical; min-height: 90px; transition: border-color .2s; }
        .notes-textarea:focus { outline: none; border-color: var(--green-mid); box-shadow: 0 0 0 3px rgba(64,145,108,.1); }

        .readonly-field { padding: 11px 14px; background: var(--green-pale); border: 2px solid var(--border); border-radius: 10px; font-size: 14px; font-weight: 700; color: var(--green-dark); }

        .btn-row { display: flex; gap: 14px; justify-content: flex-end; padding-top: 24px; border-top: 2px solid var(--green-light); flex-wrap: wrap; }
        .btn { padding: 12px 28px; border-radius: 10px; font-size: 14px; font-weight: 700; font-family: inherit; cursor: pointer; transition: all .2s; border: 2px solid transparent; display: flex; align-items: center; gap: 8px; }
        .btn-save  { background: var(--green-nav); color: white; border-color: var(--green-nav); }
        .btn-print { background: #2196f3; color: white; border-color: #2196f3; }
        .btn-reset { background: white; color: var(--muted); border-color: var(--border); }
        .btn:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,.12); }
        .btn:disabled { opacity: .6; cursor: not-allowed; transform: none; }

        .history-link { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 600; color: var(--green-nav); text-decoration: none; padding: 7px 16px; border: 1.5px solid var(--green-nav); border-radius: 8px; transition: all .2s; margin-bottom: 20px; float: right; }
        .history-link:hover { background: var(--green-nav); color: white; }

        .form-row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; margin-bottom: 18px; }
        .hidden { display: none !important; }

        /* ── Toast ── */
        .save-success-overlay { display: none; position: fixed; inset: 0; z-index: 1200; align-items: center; justify-content: center; padding: 20px; background: rgba(12,24,18,0.35); backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px); }
        .save-success-overlay.show { display: flex; }
        .save-success-card { width: 100%; max-width: 420px; background: white; border: 1.5px solid var(--border); border-radius: 18px; box-shadow: 0 24px 70px rgba(0,0,0,0.28); padding: 28px 24px; text-align: center; }
        .save-success-icon { width: 56px; height: 56px; margin: 0 auto 14px; border-radius: 50%; background: var(--green-light); color: var(--green-dark); display: flex; align-items: center; justify-content: center; font-size: 28px; }
        .save-success-title { font-size: 22px; font-weight: 800; color: var(--green-dark); margin-bottom: 8px; }
        .save-success-text { font-size: 14px; color: var(--muted); margin-bottom: 20px; }
        .save-success-btn { display: inline-flex; align-items: center; justify-content: center; text-decoration: none; width: 100%; padding: 12px 16px; border-radius: 10px; background: var(--green-nav); color: white; border: 2px solid var(--green-nav); font-size: 14px; font-weight: 700; transition: background .2s, transform .1s; }
        .save-success-btn:hover { background: var(--green-dark); transform: translateY(-1px); }
        .toast { display: none !important; }

        /* ── History modal ── */
        .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 200; align-items: center; justify-content: center; }
        .modal-overlay.show { display: flex; }
        .modal { background: white; border-radius: 16px; width: 92%; max-width: 800px; max-height: 82vh; overflow: hidden; display: flex; flex-direction: column; box-shadow: 0 20px 60px rgba(0,0,0,0.3); }
        .modal-header { padding: 18px 24px; border-bottom: 1.5px solid var(--green-light); display: flex; justify-content: space-between; align-items: center; }
        .modal-header h3 { font-size: 16px; font-weight: 700; color: var(--green-dark); }
        .modal-close { background: none; border: none; font-size: 22px; cursor: pointer; color: var(--muted); line-height: 1; }
        .modal-body { overflow-y: auto; padding: 20px 24px; }
        .history-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .history-table th { background: var(--green-pale); color: var(--green-dark); font-weight: 700; padding: 10px 12px; text-align: left; border-bottom: 2px solid var(--border); white-space: nowrap; }
        .history-table td { padding: 10px 12px; border-bottom: 1px solid var(--green-light); color: var(--text); }
        .history-table tr:hover td { background: var(--green-pale); }
        .no-history { text-align: center; padding: 32px; color: var(--muted); font-size: 14px; }

        @media print { .navbar, .search-section, .btn-row, .history-link { display: none !important; } .page-body { padding: 0; } .card { box-shadow: none; border: 1px solid #ccc; } }
        @media (max-width: 640px) { .navbar { padding: 0 16px; } .nav-links { display: none; } .page-body { padding: 20px 12px; } .form-row-2 { grid-template-columns: 1fr; } .btn-row { justify-content: stretch; } .btn { justify-content: center; flex: 1; } }
    </style>
</head>
<body>

<!-- Navbar -->
<nav class="navbar">
    <a href="<?= htmlspecialchars(app_base_url()) ?>/" class="navbar-brand">
        <span style="display:flex;align-items:center;gap:14px;line-height:1;"><img src="<?= htmlspecialchars(app_base_url()) ?>/public/assets/SRMS_TRUST_LOGO.png" alt="SRMST Trust Logo" style="height:48px;width:auto;display:block;object-fit:contain;filter:drop-shadow(0 1px 3px rgba(0,0,0,.35));"><span style="font-size:22px;font-weight:800;letter-spacing:1px;color:#fff;text-shadow:0 1px 2px rgba(0,0,0,.35);white-space:nowrap;">SRMS EHEALTH</span></span>
    </a>
    <ul class="nav-links">
            <li><a href="nursing_dashboard.php">DASHBOARD</a></li>
            <li><a href="<?= htmlspecialchars(app_base_url()) ?>/about.php">ABOUT</a></li>
        <li><a href="<?= htmlspecialchars(app_base_url()) ?>/contact.php">CONTACT</a></li>
</ul>
    <div class="nav-right">
        <div class="nurse-pill">
            <div class="nurse-pill-avatar">👤</div>
            <span class="nurse-pill-name"><?= $nurse_name ?></span>
        </div>
        <form method="POST" style="display:inline">
            <button type="submit" name="logout" class="logout-btn">LOGOUT</button>
        </form>
    </div>
</nav>

<!-- Toast -->
<div class="toast" id="toast" aria-hidden="true"></div>

<!-- Save Success Modal -->
<div class="save-success-overlay" id="saveSuccessOverlay">
    <div class="save-success-card">
        <div class="save-success-icon">&#10003;</div>
        <div class="save-success-title">Vitals Saved</div>
        <div class="save-success-text">Form has been reset successfully.</div>
        <a href="nursing_dashboard.php" class="save-success-btn">Back to Dashboard</a>
    </div>
</div>

<!-- History Modal -->
<div class="modal-overlay" id="historyModal">
    <div class="modal">
        <div class="modal-header">
            <h3>📋 Vitals History — <span id="modalPatientId"></span></h3>
            <button class="modal-close" onclick="closeModal()">✕</button>
        </div>
        <div class="modal-body" id="modalBody">
            <p class="no-history">Loading…</p>
        </div>
    </div>
</div>

<div class="page-body">

    <div class="page-title">
        <h1>💉 Enter Patient Vitals</h1>
        <p>Search for a patient and record their vital signs</p>
    </div>

    <!-- Nursing Queue -->
    <div class="card">
        <div class="card-header">
            <div class="card-header-icon">📋</div>
            <h2>Nursing Queue</h2>
            <span class="queue-count"><?= $queue_count ?></span>
        </div>
        <div class="card-body">
            <div class="queue-meta-text">
                Patients waiting for vitals. If a patient is not listed, use the search below.
            </div>
            <div class="queue-list">
                <?php if (empty($queue)): ?>
                    <div class="queue-empty">No patients are waiting for vitals right now.</div>
                <?php else: ?>
                    <?php foreach ($queue as $p): ?>
                        <?php
                            $pid = $p['patient_id'] ?? '';
                            $isOld = (($p['patient_flag'] ?? 'N') === 'O');
                            $age = ($p['age'] ?? '') !== '' ? $p['age'] : '—';
                            $gender = $p['gender'] ?? '—';
                            $mobile = $p['mobile_no'] ?? '—';
                            $photo = $p['photo_url'] ?? '';
                        ?>
                        <button type="button" class="queue-item" data-pid="<?= htmlspecialchars($pid) ?>">
                            <div class="queue-left">
                                <?php if ($photo): ?>
                                    <img src="<?= htmlspecialchars($photo) ?>" class="queue-photo" alt="Patient Photo">
                                <?php else: ?>
                                    <div class="queue-photo-placeholder">👤</div>
                                <?php endif; ?>
                                <div class="queue-text">
                                    <div class="queue-name"><?= htmlspecialchars($p['full_name'] ?? '—') ?></div>
                                    <div class="queue-meta">
                                        <span>ID: <strong><?= htmlspecialchars($pid) ?></strong></span>
                                        <span>Age: <strong><?= htmlspecialchars((string)$age) ?></strong></span>
                                        <span>Gender: <strong><?= htmlspecialchars((string)$gender) ?></strong></span>
                                        <span>Mobile: <strong><?= htmlspecialchars((string)$mobile) ?></strong></span>
                                    </div>
                                </div>
                            </div>
                            <div class="queue-right">
                                <span class="queue-badge <?= $isOld ? 'old' : 'new' ?>">
                                    <?= $isOld ? 'Revisited' : 'New' ?>
                                </span>
                                <span class="queue-action">Enter Vitals</span>
                            </div>
                        </button>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Patient Search -->
    <div class="card search-section">
        <div class="card-header">
            <div class="card-header-icon">🔍</div>
            <h2>Patient Lookup</h2>
        </div>
        <div class="card-body">
            <div class="alert" id="alertBox"></div>
            <div class="search-row">
                <div class="search-input-wrap">
                    <label for="patientIdInput">Patient ID</label>
                    <input type="text" id="patientIdInput" placeholder="e.g. P4823" autocomplete="off" style="text-transform:uppercase" value="<?= htmlspecialchars($prefill_pid) ?>">
                </div>
                <button class="search-btn" id="searchBtn" onclick="searchPatient()">🔍 Search Patient</button>
            </div>
        </div>
    </div>

    <!-- Patient Banner -->
    <div class="patient-banner" id="patientBanner">
        <img id="bannerPhoto" class="patient-photo hidden" src="" alt="Patient Photo">
        <div class="patient-photo-placeholder" id="bannerPhotoPlaceholder">👤</div>
        <div class="patient-info">
            <div class="patient-name" id="bannerName">—</div>
            <div class="patient-meta">
                <div class="meta-item">ID: <strong id="bannerPatientId">—</strong></div>
                <div class="meta-item">Age: <strong id="bannerAge">—</strong></div>
                <div class="meta-item">Gender: <strong id="bannerGender">—</strong></div>
                <div class="meta-item">Mobile: <strong id="bannerMobile">—</strong></div>
            </div>
        </div>
        <div class="patient-id-badge" id="bannerBadge">—</div>
    </div>

    <!-- Vitals Form -->
    <div id="vitalsSection" class="hidden">

        <div style="overflow:hidden;margin-bottom:4px;">
            <a href="#" class="history-link" onclick="loadHistory(event)">📋 View Vitals History</a>
        </div>

        <form id="vitalsForm" novalidate>

            <!-- Vital Signs card -->
            <div class="card">
                <div class="card-header">
                    <div class="card-header-icon">❤️</div>
                    <h2>Vital Signs</h2>
                </div>
                <div class="card-body">
                    <div class="section-title"><span class="section-icon">🩺</span>Vital Signs</div>
                    <div class="vitals-grid">

                        <div class="vital-field" style="grid-column: span 2">
                            <label>Blood Pressure <i class="info-tip" data-tip="Normal: 120/80 mmHg">i</i></label>
                            <div class="bp-row">
                                <input type="number" id="bpSystolic"  placeholder="120" min="50"  max="250" oninput="updateBPStatus()">
                                <span class="bp-sep">/</span>
                                <input type="number" id="bpDiastolic" placeholder="80"  min="30"  max="150" oninput="updateBPStatus()">
                                <span class="bp-unit">mmHg</span>
                            </div>
                            <span class="vital-status" id="bpStatus"></span>
                        </div>

                        <div class="vital-field">
                            <label>Heart Rate <i class="info-tip" data-tip="Normal: 60–100 bpm">i</i></label>
                            <div class="input-with-unit">
                                <input type="number" id="heartRate" placeholder="72" min="30" max="220" oninput="updateStatus('heartRate','hrStatus',60,100)">
                                <span class="unit-badge">bpm</span>
                            </div>
                            <span class="vital-status" id="hrStatus"></span>
                        </div>

                        <div class="vital-field">
                            <label>Temperature <i class="info-tip" data-tip="Normal: 36.5–37.5°C">i</i></label>
                            <div class="input-with-unit">
                                <input type="number" id="temperature" placeholder="37.0" min="30" max="45" step="0.1" oninput="updateStatus('temperature','tempStatus',36.5,37.5)">
                                <span class="unit-badge">°C</span>
                            </div>
                            <span class="vital-status" id="tempStatus"></span>
                        </div>

                        <div class="vital-field">
                            <label>SpO2 <i class="info-tip" data-tip="Normal: 95–100%">i</i></label>
                            <div class="input-with-unit">
                                <input type="number" id="spo2" placeholder="98" min="50" max="100" oninput="updateStatus('spo2','spo2Status',95,100)">
                                <span class="unit-badge">%</span>
                            </div>
                            <span class="vital-status" id="spo2Status"></span>
                        </div>

                        <div class="vital-field">
                            <label>Respiratory Rate <i class="info-tip" data-tip="Normal: 12–20 /min">i</i></label>
                            <div class="input-with-unit">
                                <input type="number" id="respRate" placeholder="16" min="5" max="60" oninput="updateStatus('respRate','rrStatus',12,20)">
                                <span class="unit-badge">/min</span>
                            </div>
                            <span class="vital-status" id="rrStatus"></span>
                        </div>

                    </div>

                    <div class="section-title" style="margin-top:24px;"><span class="section-icon">📏</span>Body Measurements</div>
                    <div class="vitals-grid">
                        <div class="vital-field">
                            <label>Weight</label>
                            <div class="input-with-unit">
                                <input type="number" id="weight" placeholder="70.5" min="1" max="300" step="0.1" oninput="calcBMI()">
                                <span class="unit-badge">kg</span>
                            </div>
                        </div>
                        <div class="vital-field">
                            <label>Height</label>
                            <div class="input-with-unit">
                                <input type="number" id="height" placeholder="170" min="30" max="250" oninput="calcBMI()">
                                <span class="unit-badge">cm</span>
                            </div>
                        </div>
                        <div class="vital-field">
                            <label>BMI</label>
                            <div class="bmi-display" id="bmiDisplay">—</div>
                            <div style="text-align:center;margin-top:4px;">
                                <span class="vital-status" id="bmiStatus"></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Notes card -->
            <div class="card">
                <div class="card-header">
                    <div class="card-header-icon">📝</div>
                    <h2>Additional Notes</h2>
                </div>
                <div class="card-body">
                    <label style="font-size:13px;font-weight:600;color:var(--muted);display:block;margin-bottom:8px;">Observations / Notes <span style="font-weight:400;">(Optional)</span></label>
                    <textarea class="notes-textarea" id="notes" placeholder="Any additional observations, symptoms or notes…"></textarea>
                </div>
            </div>

            <!-- Recorded By card -->
            <div class="card">
                <div class="card-header">
                    <div class="card-header-icon">👨‍💼</div>
                    <h2>Recorded By</h2>
                </div>
                <div class="card-body">
                    <div class="form-row-2">
                        <div>
                            <label style="font-size:13px;font-weight:600;color:var(--muted);display:block;margin-bottom:8px;">Nursing Staff Name</label>
                            <div class="readonly-field"><?= $nurse_name ?></div>
                        </div>
                        <div>
                            <label style="font-size:13px;font-weight:600;color:var(--muted);display:block;margin-bottom:8px;">Nursing Staff ID</label>
                            <div class="readonly-field"><?= htmlspecialchars($nursing_id) ?></div>
                        </div>
                    </div>
                    <div class="btn-row">
                        <button type="button" class="btn btn-reset" onclick="resetForm()">🔄 Reset Form</button>
                        <button type="button" class="btn btn-print" onclick="saveAndPrint()">🖨️ Save &amp; Print</button>
                        <button type="button" class="btn btn-save" id="saveBtn" onclick="saveVitals()">💾 Save Vitals</button>
                    </div>
                </div>
            </div>

        </form>
    </div>
</div>

<script>
    let currentPatientId = null;
    let currentBMI       = null;

    async function fetchJsonWithTimeout(url, options = {}, timeoutMs = 12000) {
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), timeoutMs);
        try {
            const res = await fetch(url, { ...options, signal: controller.signal });
            const text = await res.text();
            let data = null;
            if (text) {
                try { data = JSON.parse(text); }
                catch { throw new Error('Unexpected server response.'); }
            }
            if (!res.ok) {
                throw new Error((data && data.error) ? data.error : `Request failed (${res.status}).`);
            }
            return data || {};
        } finally {
            clearTimeout(timeoutId);
        }
    }

    // ── Search ──────────────────────────────────────────────────
    async function searchPatient() {
        const pid = document.getElementById('patientIdInput').value.trim().toUpperCase();
        if (!pid) { showAlert('Please enter a Patient ID.', 'error'); return false; }

        const btn = document.getElementById('searchBtn');
        btn.disabled = true; btn.textContent = '⏳ Searching…';
        let found = false;

        try {
            const data = await fetchJsonWithTimeout(`?action=search_patient&patient_id=${encodeURIComponent(pid)}`);
            if (data.success) {
                const p = data.patient;
                const bannerPhoto = document.getElementById('bannerPhoto');
                const photoPlaceholder = document.getElementById('bannerPhotoPlaceholder');
                currentPatientId = p.patient_id;
                document.getElementById('bannerName').textContent      = p.full_name;
                document.getElementById('bannerPatientId').textContent = p.patient_id;
                document.getElementById('bannerAge').textContent       = p.age;
                document.getElementById('bannerGender').textContent    = p.gender;
                document.getElementById('bannerMobile').textContent    = p.mobile_no;
                document.getElementById('bannerBadge').textContent     = p.patient_id;

                if (p.photo_url) {
                    bannerPhoto.src = p.photo_url;
                    bannerPhoto.classList.remove('hidden');
                    photoPlaceholder.classList.add('hidden');
                } else {
                    bannerPhoto.src = '';
                    bannerPhoto.classList.add('hidden');
                    photoPlaceholder.classList.remove('hidden');
                }

                document.getElementById('patientBanner').classList.add('show');
                document.getElementById('vitalsSection').classList.remove('hidden');
                hideAlert();
                found = true;
            } else {
                showAlert(data.error, 'error');
                document.getElementById('bannerPhoto').src = '';
                document.getElementById('bannerPhoto').classList.add('hidden');
                document.getElementById('bannerPhotoPlaceholder').classList.remove('hidden');
                document.getElementById('patientBanner').classList.remove('show');
                document.getElementById('vitalsSection').classList.add('hidden');
                currentPatientId = null;
            }
        } catch (err) {
            if (err && err.name === 'AbortError') showAlert('Search timed out. Please check DB/server and try again.', 'error');
            else showAlert((err && err.message) ? err.message : 'Server error. Please try again.', 'error');
        }
        finally { btn.disabled = false; btn.textContent = '🔍 Search Patient'; }
        return found;
    }

    document.getElementById('patientIdInput').addEventListener('keydown', e => {
        if (e.key === 'Enter') { e.preventDefault(); searchPatient(); }
    });

    document.querySelectorAll('.queue-item').forEach(btn => {
        btn.addEventListener('click', async () => {
            const pid = btn.getAttribute('data-pid');
            if (!pid) return;
            document.getElementById('patientIdInput').value = pid;
            const ok = await searchPatient();
            if (ok) {
                document.getElementById('vitalsSection').scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    });

    document.getElementById('bannerPhoto').addEventListener('error', function() {
        this.src = '';
        this.classList.add('hidden');
        document.getElementById('bannerPhotoPlaceholder').classList.remove('hidden');
    });

    // Auto-fill flow from registration slip / existing-patient search (?pid=...).
    window.addEventListener('DOMContentLoaded', () => {
        const prefilledId = document.getElementById('patientIdInput').value.trim();
        if (prefilledId) {
            searchPatient();
        }
    });

    // ── Save vitals ─────────────────────────────────────────────
    async function saveVitals(andPrint = false) {
        if (!currentPatientId) { showAlert('Please search for a patient first.', 'error'); return; }

        const btn = document.getElementById('saveBtn');
        btn.disabled = true; btn.textContent = '⏳ Saving…';

        const body = new URLSearchParams({
            action:      'save_vitals',
            patientId:   currentPatientId,
            bpSystolic:  document.getElementById('bpSystolic').value,
            bpDiastolic: document.getElementById('bpDiastolic').value,
            heartRate:   document.getElementById('heartRate').value,
            temperature: document.getElementById('temperature').value,
            spo2:        document.getElementById('spo2').value,
            respRate:    document.getElementById('respRate').value,
            weight:      document.getElementById('weight').value,
            height:      document.getElementById('height').value,
            bmi:         currentBMI !== null ? currentBMI : '',
            notes:       document.getElementById('notes').value,
        });

        try {
            const data = await fetchJsonWithTimeout(window.location.href, { method: 'POST', body });
            if (data.success) {
                if (andPrint) window.print();
                resetAfterSave();
                showSaveSuccessModal();
            } else {
                showAlert(data.error || 'Failed to save vitals.', 'error');
            }
        } catch (err) {
            if (err && err.name === 'AbortError') showAlert('Save timed out. Please check DB/server and try again.', 'error');
            else showAlert((err && err.message) ? err.message : 'Server error. Please try again.', 'error');
        }
        finally { btn.disabled = false; btn.textContent = '💾 Save Vitals'; }
    }

    function saveAndPrint() { saveVitals(true); }

    // ── Vital status pills ───────────────────────────────────────
    function updateStatus(inputId, statusId, low, high) {
        const val = parseFloat(document.getElementById(inputId).value);
        const el  = document.getElementById(statusId);
        if (isNaN(val)) { el.className = 'vital-status'; el.textContent = ''; return; }
        el.classList.add('show');
        if      (val < low)  { el.className = 'vital-status show low';    el.textContent = '↓ Low'; }
        else if (val > high) { el.className = 'vital-status show high';   el.textContent = '↑ High'; }
        else                 { el.className = 'vital-status show normal'; el.textContent = '✓ Normal'; }
    }

    function updateBPStatus() {
        const sys = parseFloat(document.getElementById('bpSystolic').value);
        const dia = parseFloat(document.getElementById('bpDiastolic').value);
        const el  = document.getElementById('bpStatus');
        if (isNaN(sys) || isNaN(dia)) { el.className = 'vital-status'; el.textContent = ''; return; }
        el.classList.add('show');
        if      (sys < 90  || dia < 60)  { el.className = 'vital-status show low';    el.textContent = '↓ Low BP'; }
        else if (sys > 140 || dia > 90)  { el.className = 'vital-status show high';   el.textContent = '↑ High BP'; }
        else if (sys > 120 || dia > 80)  { el.className = 'vital-status show hint';   el.textContent = '⚠ Elevated'; }
        else                             { el.className = 'vital-status show normal'; el.textContent = '✓ Normal'; }
    }

    function calcBMI() {
        const w = parseFloat(document.getElementById('weight').value);
        const h = parseFloat(document.getElementById('height').value) / 100;
        const bmiEl = document.getElementById('bmiDisplay');
        const bmiSt = document.getElementById('bmiStatus');
        if (!w || !h || h <= 0) { bmiEl.textContent = '—'; bmiSt.className = 'vital-status'; currentBMI = null; return; }
        const bmi = +(w / (h * h)).toFixed(1);
        currentBMI = bmi;
        bmiEl.textContent = bmi;
        bmiSt.classList.add('show');
        if      (bmi < 18.5) { bmiSt.className = 'vital-status show low';    bmiSt.textContent = 'Underweight'; }
        else if (bmi < 25)   { bmiSt.className = 'vital-status show normal'; bmiSt.textContent = 'Normal'; }
        else if (bmi < 30)   { bmiSt.className = 'vital-status show hint';   bmiSt.textContent = 'Overweight'; }
        else                 { bmiSt.className = 'vital-status show high';   bmiSt.textContent = 'Obese'; }
    }

    // ── Reset ────────────────────────────────────────────────────
    function resetForm() {
        ['bpSystolic','bpDiastolic','heartRate','temperature','spo2','respRate','weight','height','notes']
            .forEach(id => { document.getElementById(id).value = ''; });
        ['bpStatus','hrStatus','tempStatus','spo2Status','rrStatus','bmiStatus']
            .forEach(id => { const el = document.getElementById(id); el.className = 'vital-status'; el.textContent = ''; });
        document.getElementById('bmiDisplay').textContent = '—';
        currentBMI = null;
    }

    function resetAfterSave() {
        resetForm();
        hideAlert();
        currentPatientId = null;
        document.getElementById('patientIdInput').value = '';
        document.getElementById('patientBanner').classList.remove('show');
        document.getElementById('vitalsSection').classList.add('hidden');
        document.getElementById('bannerPhoto').src = '';
        document.getElementById('bannerPhoto').classList.add('hidden');
        document.getElementById('bannerPhotoPlaceholder').classList.remove('hidden');
        document.getElementById('bannerName').textContent = '';
        document.getElementById('bannerPatientId').textContent = '';
        document.getElementById('bannerAge').textContent = '';
        document.getElementById('bannerGender').textContent = '';
        document.getElementById('bannerMobile').textContent = '';
        document.getElementById('bannerBadge').textContent = '';
    }

    function showSaveSuccessModal() {
        document.getElementById('saveSuccessOverlay').classList.add('show');
    }

    // ── History modal ────────────────────────────────────────────
    async function loadHistory(e) {
        e.preventDefault();
        if (!currentPatientId) return;
        document.getElementById('modalPatientId').textContent = currentPatientId;
        document.getElementById('modalBody').innerHTML = '<p class="no-history">Loading…</p>';
        document.getElementById('historyModal').classList.add('show');

        const res  = await fetch(`?action=vitals_history&patient_id=${encodeURIComponent(currentPatientId)}`);
        const data = await res.json();

        if (!data.vitals || data.vitals.length === 0) {
            document.getElementById('modalBody').innerHTML = '<p class="no-history">No vitals recorded yet for this patient.</p>';
            return;
        }

        let html = `<table class="history-table"><thead><tr>
            <th>Date &amp; Time</th><th>BP (mmHg)</th><th>HR (bpm)</th>
            <th>Temp (°C)</th><th>SpO2 (%)</th><th>RR (/min)</th>
            <th>BMI</th><th>Recorded By</th>
        </tr></thead><tbody>`;

        data.vitals.forEach(v => {
            const bp   = (v.bp_systolic && v.bp_diastolic) ? `${v.bp_systolic}/${v.bp_diastolic}` : '—';
            const date = v.recorded_at ? v.recorded_at.replace('T',' ').slice(0,16) : '—';
            html += `<tr>
                <td>${date}</td><td>${bp}</td>
                <td>${v.heart_rate ?? '—'}</td><td>${v.temperature ?? '—'}</td>
                <td>${v.spo2 ?? '—'}</td><td>${v.resp_rate ?? '—'}</td>
                <td>${v.bmi ?? '—'}</td><td>${v.recorded_by_exec_name ?? '—'}</td>
            </tr>`;
        });
        html += '</tbody></table>';
        document.getElementById('modalBody').innerHTML = html;
    }

    function closeModal() { document.getElementById('historyModal').classList.remove('show'); }
    document.getElementById('historyModal').addEventListener('click', function(e) { if (e.target === this) closeModal(); });

    // ── Alert / Toast ────────────────────────────────────────────
    function showAlert(msg, type) {
        const el = document.getElementById('alertBox');
        el.className = `alert show-${type}`;
        el.textContent = msg;
    }
    function hideAlert() {
        const el = document.getElementById('alertBox');
        el.className = 'alert';
        el.textContent = '';
    }
</script>

</body>
</html>

