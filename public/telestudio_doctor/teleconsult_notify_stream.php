<?php
// SSE: eHealth doctor listens for session status changes
require_once __DIR__ . '/../../config/config.php';
start_session();
if (!is_logged_in()) { http_response_code(401); exit; }

@ini_set('output_buffering','off');
@ini_set('zlib.output_compression',false);
while(ob_get_level()) ob_end_clean();

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');
set_time_limit(0);
ignore_user_abort(false);

$sid = (int)($_GET['sessionId'] ?? 0);
if (!$sid) { echo "data: {\"error\":\"no session\"}\n\n"; flush(); exit; }

$db        = db();
$lastStatus = '';

function sseEvent(string $evt, array $data): void {
    echo "event: $evt\n";
    echo 'data: '.json_encode($data)."\n\n";
    flush();
}

// Send initial state
$s = $db->prepare('SELECT status, whereby_room_url, accept_wait_message, reject_message FROM teleconsult_sessions WHERE id = ? LIMIT 1');
$s->bind_param('i', $sid);
$s->execute();
$row = $s->get_result()->fetch_assoc();
$s->close();
$lastStatus = $row['status'] ?? '';
sseEvent('init', [
    'status'        => $lastStatus,
    'roomUrl'       => $row['whereby_room_url'] ?? '',
    'waitMessage'   => $row['accept_wait_message'] ?? '',
    'rejectMessage' => $row['reject_message'] ?? ''
]);

while (true) {
    if (connection_aborted()) break;
    sleep(1);

    $s = $db->prepare('SELECT status, whereby_room_url, started_at, accept_wait_message, reject_message FROM teleconsult_sessions WHERE id = ? LIMIT 1');
    $s->bind_param('i', $sid);
    $s->execute();
    $row = $s->get_result()->fetch_assoc();
    $s->close();

    if (!$row) break;
    $newStatus = $row['status'];

    if ($newStatus !== $lastStatus) {
        $lastStatus = $newStatus;
        sseEvent('status_change', [
            'status'        => $newStatus,
            'roomUrl'       => $row['whereby_room_url'] ?? '',
            'waitMessage'   => $row['accept_wait_message'] ?? '',
            'rejectMessage' => $row['reject_message'] ?? '',
            'sessionId'     => $sid
        ]);
        if ($newStatus === 'completed' || $newStatus === 'cancelled') break;
    }

    echo ": heartbeat\n\n";
    flush();
}
