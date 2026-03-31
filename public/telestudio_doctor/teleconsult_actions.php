<?php
ob_start(); // Buffer ALL output â€” prevents stray whitespace/warnings from breaking JSON
// â”€â”€ Central AJAX handler for all teleconsult session actions â”€
require_once __DIR__ . '/../../config/config.php';
start_session();
if (!is_logged_in()) {
    ob_end_clean();
    http_response_code(401);
    echo json_encode(['success'=>false,'error'=>'Unauthorized']);
    exit;
}

ob_end_clean(); // Clear any stray output from config/session includes
header('Content-Type: application/json');

// Catch ALL PHP errors and return as JSON (prevents "Network error" on frontend)
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    while(ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['success'=>false,'error'=>"PHP Error $errno: $errstr in $errfile:$errline"]);
    exit;
});
register_shutdown_function(function() {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        while(ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode(['success'=>false,'error'=>'PHP Fatal: '.$e['message'].' in '.$e['file'].':'.$e['line']]);
    }
});
$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = trim($_GET['action'] ?? $body['action'] ?? '');
$db     = db();
$role   = get_session_role();
$uid    = (string)get_session_id();
$uname  = get_session_name();

// â”€â”€ Helper â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function getSession(mysqli $db, int $id): ?array {
    $s = $db->prepare('SELECT * FROM teleconsult_sessions WHERE id = ? LIMIT 1');
    $s->bind_param('i', $id);
    $s->execute();
    $row = $s->get_result()->fetch_assoc();
    $s->close();
    return $row ?: null;
}

function getSignalFilePath(int $sid): string {
    $dir = __DIR__ . '/../../tmp/call_signals';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    return $dir . '/session_' . $sid . '.json';
}

function setCallReceivedSignal(int $sid): void {
    $file = getSignalFilePath($sid);
    @file_put_contents($file, json_encode([
        'received' => true,
        'receivedAt' => date('c')
    ]));
}

function getCallReceivedSignal(int $sid): bool {
    $file = getSignalFilePath($sid);
    if (!is_file($file)) return false;
    $raw = @file_get_contents($file);
    if ($raw === false || $raw === '') return false;
    $data = json_decode($raw, true);
    return !empty($data['received']);
}

function clearCallReceivedSignal(int $sid): void {
    $file = getSignalFilePath($sid);
    if (is_file($file)) @unlink($file);
}

function ensureTeleconsultPaymentColumns(mysqli $db): void {
    static $done = false;
    if ($done) return;
    $done = true;

    $db->query("ALTER TABLE teleconsult_sessions ADD COLUMN IF NOT EXISTS payment_method ENUM('Cash','UPI') NULL AFTER remarks");
    $db->query("ALTER TABLE teleconsult_sessions ADD COLUMN IF NOT EXISTS payment_amount DECIMAL(8,2) NOT NULL DEFAULT 50.00 AFTER payment_method");
    $db->query("ALTER TABLE teleconsult_sessions ADD COLUMN IF NOT EXISTS payment_collected_by_role ENUM('doctor','executive') NULL AFTER payment_amount");
    $db->query("ALTER TABLE teleconsult_sessions ADD COLUMN IF NOT EXISTS payment_collected_by_id VARCHAR(20) NULL AFTER payment_collected_by_role");
    $db->query("ALTER TABLE teleconsult_sessions ADD COLUMN IF NOT EXISTS payment_collected_by_name VARCHAR(255) NULL AFTER payment_collected_by_id");
}

// eHealth side marks that call screen is received/opened.
if ($action === 'mark_received') {
    if (!$uid) { echo json_encode(['success'=>false,'error'=>'Unauthorized']); exit; }
    if ($role !== 'doctor') { echo json_encode(['success'=>false,'error'=>'Forbidden']); exit; }
    $sid = (int)($body['sessionId'] ?? $_GET['sessionId'] ?? 0);
    $sess = getSession($db, $sid);
    if (!$sess || $sess['ehealth_doctor_id'] !== $uid) { echo json_encode(['success'=>false,'error'=>'Session not found']); exit; }
    setCallReceivedSignal($sid);
    echo json_encode(['success'=>true]);
    exit;
}

// Telestudio side checks if eHealth has received/opened call.
if ($action === 'get_received') {
    if (!$uid) { echo json_encode(['success'=>false,'error'=>'Unauthorized']); exit; }
    if ($role !== 'telestudio_doctor') { echo json_encode(['success'=>false,'error'=>'Forbidden']); exit; }
    $sid = (int)($body['sessionId'] ?? $_GET['sessionId'] ?? 0);
    $sess = getSession($db, $sid);
    if (!$sess || $sess['telestudio_doctor_id'] !== $uid) { echo json_encode(['success'=>false,'error'=>'Session not found']); exit; }
    echo json_encode(['success'=>true, 'received'=>getCallReceivedSignal($sid)]);
    exit;
}

// â”€â”€ CREATE SESSION (eHealth doctor, after clicking Start Consultation) â”€â”€
if ($action === 'create_session') {
    if ($role !== 'doctor') { echo json_encode(['success'=>false,'error'=>'Only eHealth doctors can create sessions']); exit; }

    ensureTeleconsultPaymentColumns($db);

    $patientId          = trim($body['patientId']         ?? '');
    $patientName        = trim($body['patientName']        ?? '');
    $telestudioDoctorId = trim($body['telestudioDoctorId'] ?? '');
    $telestudioName     = trim($body['telestudioName']     ?? '');
    $teleconsultReason  = trim($body['teleconsultReason']  ?? '');
    $chiefComplaint     = trim($body['chiefComplaint']     ?? '');
    $clinicalNotes      = trim($body['clinicalNotes']      ?? '');
    $remarks            = trim($body['remarks']            ?? '');
    $diagnosisId        = (int)($body['diagnosisId']       ?? 0);
    if (!$patientId || !$telestudioDoctorId || !$teleconsultReason) { echo json_encode(['success'=>false,'error'=>'Teleconsultation reason is required']); exit; }

    // Look up actual patient name from DB
    $pq = $db->prepare('SELECT full_name FROM patients WHERE patient_id = ? LIMIT 1');
    $pq->bind_param('s', $patientId);
    $pq->execute();
    $prow = $pq->get_result()->fetch_assoc();
    $pq->close();
    if ($prow && $prow['full_name']) $patientName = $prow['full_name'];

    // Get doctor name only â€” room is always created fresh on start_call
    $dr = $db->prepare('SELECT full_name FROM telestudio_doctors WHERE doctor_id = ? LIMIT 1');
    $dr->bind_param('s', $telestudioDoctorId);
    $dr->execute();
    $drRow = $dr->get_result()->fetch_assoc();
    $dr->close();
    $tsName = $drRow['full_name'] ?? $telestudioName;

    $ins = $db->prepare(
        'INSERT INTO teleconsult_sessions
         (diagnosis_id, patient_id, patient_name, ehealth_doctor_id, ehealth_doctor_name,
          telestudio_doctor_id, telestudio_doctor_name, teleconsult_reason, chief_complaint, clinical_notes,
          remarks, status)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,\'waiting\')'
    );
    $diagId = ($diagnosisId > 0) ? (int)$diagnosisId : null;
    $ins->bind_param('issssssssss',
        $diagId, $patientId, $patientName, $uid, $uname,
        $telestudioDoctorId, $tsName, $teleconsultReason, $chiefComplaint, $clinicalNotes,
        $remarks
    );
    $ins->execute();
    $sessionId = $db->insert_id;
    $ins->close();

    echo json_encode(['success'=>true,'sessionId'=>$sessionId]);
    exit;
}

// â”€â”€ ACCEPT (TeleStudio doctor accepts the request) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if ($action === 'accept') {
    if (!$uid) { echo json_encode(['success'=>false,'error'=>'Unauthorized']); exit; }
    $sid = (int)($body['sessionId'] ?? 0);
    $waitMessage = trim($body['waitMessage'] ?? '');
    $sess = getSession($db, $sid);
    if (!$sess || $sess['telestudio_doctor_id'] !== $uid) { echo json_encode(['success'=>false,'error'=>'Session not found']); exit; }
    if (!$waitMessage) { echo json_encode(['success'=>false,'error'=>'Wait message is required']); exit; }

    $waitEsc = $db->real_escape_string($waitMessage);
    $db->query("UPDATE teleconsult_sessions
                SET status='accepted', accepted_at=NOW(), accept_wait_message='$waitEsc', reject_message=NULL
                WHERE id=$sid");
    echo json_encode(['success'=>true]);
    exit;
}

// â”€â”€ REJECT (TeleStudio doctor rejects the request with message) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if ($action === 'reject') {
    if (!$uid) { echo json_encode(['success'=>false,'error'=>'Unauthorized']); exit; }
    $sid = (int)($body['sessionId'] ?? 0);
    $rejectMessage = trim($body['rejectMessage'] ?? '');
    $sess = getSession($db, $sid);
    if (!$sess || $sess['telestudio_doctor_id'] !== $uid) { echo json_encode(['success'=>false,'error'=>'Session not found']); exit; }
    if (!$rejectMessage) { echo json_encode(['success'=>false,'error'=>'Reject message is required']); exit; }

    $rejEsc = $db->real_escape_string($rejectMessage);
    $db->query("UPDATE teleconsult_sessions
                SET status='cancelled', reject_message='$rejEsc', ended_at=NOW()
                WHERE id=$sid");
    $pid = $db->real_escape_string($sess['patient_id']);
    $db->query("UPDATE patients SET diagnosis_status='pending' WHERE patient_id='$pid'");
    echo json_encode(['success'=>true]);
    exit;
}

// â”€â”€ START CALL (TeleStudio doctor clicks Call) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if ($action === 'start_call') {
    if (!$uid) { echo json_encode(['success'=>false,'error'=>'Unauthorized']); exit; }
    $sid = (int)($body['sessionId'] ?? 0);
    $sess = getSession($db, $sid);
    if (!$sess || $sess['telestudio_doctor_id'] !== $uid) { echo json_encode(['success'=>false,'error'=>'Session not found']); exit; }
    clearCallReceivedSignal($sid);

    // Always create a fresh room via cURL (reliable on XAMPP, unlike file_get_contents)
    if (WHEREBY_API_KEY === '') {
        echo json_encode(['success'=>false, 'error'=>'Video calling is not configured. Set WHEREBY_API_KEY in .env.']);
        exit;
    }

    $payload = json_encode([
        'isLocked'        => false,
        'roomNamePrefix'  => 'teleconsult-'.$sid,
        'roomNamePattern' => 'uuid',
        'roomMode'        => 'group',
        'endDate'         => date('Y-m-d\\TH:i:s.000\\Z', strtotime('+3 hours')),
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
    $raw     = curl_exec($ch);
    $curlErr = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $meeting = ($raw && !$curlErr) ? (json_decode($raw, true) ?? []) : [];

    if (!empty($meeting['roomUrl'])) {
        $rUrl = $db->real_escape_string($meeting['roomUrl']);
        $db->query("UPDATE teleconsult_sessions
                    SET whereby_room_url='$rUrl'
                    WHERE id=$sid");
        $sess['whereby_room_url'] = $meeting['roomUrl'];
    } else {
        // API failed â€” return error so doctor sees it instead of blank room
        $errDetail = $curlErr ?: ('HTTP '.$httpCode.': '.substr($raw,0,300));
        echo json_encode(['success'=>false, 'error'=>'Could not create video room. '.$errDetail]);
        exit;
    }

    $db->query("UPDATE teleconsult_sessions SET status='active', started_at=NOW() WHERE id=$sid");
    echo json_encode(['success'=>true, 'roomUrl'=>$sess['whereby_room_url']]);
    exit;
}

// â”€â”€ NOTIFY (TeleStudio sends extra notification to eHealth) â”€â”€
if ($action === 'notify') {
    if (!$uid) { echo json_encode(['success'=>false,'error'=>'Unauthorized']); exit; }
    $sid = (int)($body['sessionId'] ?? 0);
    $sess = getSession($db, $sid);
    if (!$sess || $sess['telestudio_doctor_id'] !== $uid) { echo json_encode(['success'=>false,'error'=>'Session not found']); exit; }
    // Keep as a safe no-op update for compatibility with schemas without updated_at.
    $db->query("UPDATE teleconsult_sessions SET status=status WHERE id=$sid");
    echo json_encode(['success'=>true]);
    exit;
}

// â”€â”€ END CONSULTATION â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if ($action === 'end') {
    $sid = (int)($body['sessionId'] ?? 0);
    $db->query("UPDATE teleconsult_sessions SET status='completed', ended_at=NOW() WHERE id=$sid AND (telestudio_doctor_id='$uid' OR ehealth_doctor_id='$uid')");
    // Update patient diagnosis_status
    $sess = getSession($db, $sid);
    if ($sess) {
        $pid = $db->real_escape_string($sess['patient_id']);
        $db->query("UPDATE patients SET diagnosis_status='completed' WHERE patient_id='$pid'");
    }
    echo json_encode(['success'=>true]);
    exit;
}

// â”€â”€ SAVE TELESTUDIO DIAGNOSIS â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if ($action === 'save_diagnosis') {
    if (!$uid) { echo json_encode(['success'=>false,'error'=>'Unauthorized']); exit; }
    $sid              = (int)($body['sessionId']         ?? 0);
    $chiefComplaint   = trim($body['chiefComplaint']     ?? '');
    $clinicalNotes    = trim($body['clinicalNotes']      ?? '');
    $consultNotes     = trim($body['consultationNotes']  ?? '');
    $primaryDiag      = trim($body['primaryDiagnosis']   ?? '');
    $prescription     = trim($body['prescription']       ?? '');
    $reviewAfter      = trim($body['reviewAfter']        ?? '');
    $investigation    = trim($body['investigation']      ?? '');
    $remarksOut       = trim($body['remarks']            ?? '');
    $medicines        = $body['medicines'] ?? [];
    $medJson          = json_encode($medicines);

    $sess = getSession($db, $sid);
    if (!$sess || $sess['telestudio_doctor_id'] !== $uid) { echo json_encode(['success'=>false,'error'=>'Session not found']); exit; }

    // Check already saved
    $chk = $db->query("SELECT id FROM telestudio_diagnosis WHERE session_id=$sid LIMIT 1");
    if ($chk && $chk->num_rows > 0) {
        // UPDATE
        $upd = $db->prepare('UPDATE telestudio_diagnosis SET chief_complaint=?, clinical_notes=?, consultation_notes=?, primary_diagnosis=?, prescription=?, medicines_json=?, review_after=?, investigation=?, remarks=? WHERE session_id=?');
        $upd->bind_param(
            'sssssssssi',
            $chiefComplaint,
            $clinicalNotes,
            $consultNotes,
            $primaryDiag,
            $prescription,
            $medJson,
            $reviewAfter,
            $investigation,
            $remarksOut,
            $sid
        );
        $upd->execute();
        $upd->close();
    } else {
        // INSERT
        $ins = $db->prepare(
            'INSERT INTO telestudio_diagnosis
             (session_id, patient_id, ehealth_doctor_id, ehealth_doctor_name,
              telestudio_doctor_id, telestudio_doctor_name,
              chief_complaint, clinical_notes, consultation_notes, primary_diagnosis, prescription,
              medicines_json, review_after, investigation, remarks)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $ins->bind_param('i'.str_repeat('s', 14),
            $sid, $sess['patient_id'],
            $sess['ehealth_doctor_id'], $sess['ehealth_doctor_name'],
            $uid, $uname,
            $chiefComplaint, $clinicalNotes,
            $consultNotes, $primaryDiag, $prescription,
            $medJson, $reviewAfter, $investigation, $remarksOut
        );
        $ins->execute();
        $ins->close();
    }

    // Mark session completed
    $db->query("UPDATE teleconsult_sessions SET status='completed', ended_at=NOW() WHERE id=$sid");
    $pid = $db->real_escape_string($sess['patient_id']);
    $db->query("UPDATE patients SET diagnosis_status='completed' WHERE patient_id='$pid'");

    echo json_encode(['success'=>true]);
    exit;
}

// â”€â”€ GET SESSION STATUS (polling fallback) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if ($action === 'get_status') {
    $sid  = (int)($_GET['sessionId'] ?? $body['sessionId'] ?? 0);
    $sess = getSession($db, $sid);
    if (!$sess) { echo json_encode(['success'=>false,'error'=>'Not found']); exit; }
    echo json_encode([
        'success'       => true,
        'status'        => $sess['status'],
        'roomUrl'       => $sess['whereby_room_url'],
        'waitMessage'   => $sess['accept_wait_message'] ?? '',
        'rejectMessage' => $sess['reject_message'] ?? ''
    ]);
    exit;
}

// Debug: show raw response if ?debug=1
if (isset($_GET['debug'])) {
    echo json_encode([
        'success' => false,
        'error'   => 'Unknown action: '.$action,
        'debug'   => [
            'action'  => $action,
            'role'    => $role,
            'uid'     => $uid,
            'method'  => $_SERVER['REQUEST_METHOD'],
            'body_keys' => array_keys($body),
        ]
    ], JSON_PRETTY_PRINT);
} else {
    echo json_encode(['success'=>false,'error'=>'Unknown action: '.$action]);
}
