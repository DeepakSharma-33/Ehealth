<?php
require_once __DIR__ . '/../../config/config.php';
start_session();

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$role = get_session_role();
$uid  = (string)get_session_id();
if (!in_array($role, ['doctor', 'telestudio_doctor'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

$sinceMs = (int)($_GET['since'] ?? 0);
if ($sinceMs <= 0) {
    $sinceMs = (int)round((microtime(true) - 60) * 1000);
}

$sinceSec = (int)floor($sinceMs / 1000);
$sinceSql = date('Y-m-d H:i:s', $sinceSec);

$db = db();
$events = [];

if ($role === 'doctor') {
    $q = $db->prepare(
        "SELECT id, patient_id, patient_name, telestudio_doctor_name, status,
                accept_wait_message, reject_message, accepted_at, started_at, ended_at
         FROM teleconsult_sessions
         WHERE ehealth_doctor_id = ?
           AND (
                (status='accepted'  AND accepted_at IS NOT NULL AND accepted_at > ?)
             OR (status='active'    AND started_at  IS NOT NULL AND started_at  > ?)
             OR (status='cancelled' AND ended_at    IS NOT NULL AND ended_at    > ?)
           )
         ORDER BY COALESCE(started_at, accepted_at, ended_at) ASC
         LIMIT 20"
    );
    $q->bind_param('ssss', $uid, $sinceSql, $sinceSql, $sinceSql);
    $q->execute();
    $rows = $q->get_result()->fetch_all(MYSQLI_ASSOC);
    $q->close();

    foreach ($rows as $r) {
        if ($r['status'] === 'accepted') {
            $msg = trim((string)($r['accept_wait_message'] ?? ''));
            $events[] = [
                'id'      => 'accepted-'.$r['id'].'-'.$r['accepted_at'],
                'type'    => 'accepted',
                'title'   => 'Teleconsultation Accepted',
                'message' => $msg ?: ($r['telestudio_doctor_name'].' accepted your request.'),
                'url'     => '/ehealth/public/ehealth_center_doctor/teleconsult_waiting.php?sessionId='.$r['id'],
                'timeMs'  => strtotime((string)$r['accepted_at']) * 1000,
            ];
        } elseif ($r['status'] === 'active') {
            $events[] = [
                'id'      => 'active-'.$r['id'].'-'.$r['started_at'],
                'type'    => 'active',
                'title'   => 'Teleconsultation Call Started',
                'message' => $r['telestudio_doctor_name'].' has started the call.',
                'url'     => '/ehealth/public/ehealth_center_doctor/teleconsult_waiting.php?sessionId='.$r['id'],
                'timeMs'  => strtotime((string)$r['started_at']) * 1000,
            ];
        } elseif ($r['status'] === 'cancelled') {
            $msg = trim((string)($r['reject_message'] ?? ''));
            $events[] = [
                'id'      => 'cancelled-'.$r['id'].'-'.$r['ended_at'],
                'type'    => 'cancelled',
                'title'   => 'Teleconsultation Request Declined',
                'message' => $msg ?: 'The TeleStudio doctor declined the request.',
                'url'     => '/ehealth/public/ehealth_center_doctor/telestudio_doctors_available.php?patientId='.urlencode((string)$r['patient_id']),
                'timeMs'  => strtotime((string)$r['ended_at']) * 1000,
            ];
        }
    }
} else {
    $q = $db->prepare(
        "SELECT id, patient_id, patient_name, ehealth_doctor_name, created_at
         FROM teleconsult_sessions
         WHERE telestudio_doctor_id = ?
           AND status = 'waiting'
           AND created_at > ?
         ORDER BY created_at ASC
         LIMIT 20"
    );
    $q->bind_param('ss', $uid, $sinceSql);
    $q->execute();
    $rows = $q->get_result()->fetch_all(MYSQLI_ASSOC);
    $q->close();

    foreach ($rows as $r) {
        $events[] = [
            'id'      => 'waiting-'.$r['id'].'-'.$r['created_at'],
            'type'    => 'waiting',
            'title'   => 'New Teleconsultation Request',
            'message' => 'Patient '.$r['patient_name'].' referred by '.$r['ehealth_doctor_name'].'.',
            'url'     => '/ehealth/public/telestudio_doctor/telestudio_queue.php',
            'timeMs'  => strtotime((string)$r['created_at']) * 1000,
        ];
    }
}

$serverTimeMs = (int)round(microtime(true) * 1000);
echo json_encode([
    'success'      => true,
    'events'       => $events,
    'serverTimeMs' => $serverTimeMs,
]);
