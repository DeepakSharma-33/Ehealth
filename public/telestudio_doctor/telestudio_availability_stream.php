<?php
// ── SSE Stream: pushes availability updates to connected eHealth doctors ──
require_once __DIR__ . '/../../config/config.php';
start_session();

// Must be logged in as doctor or telestudio_doctor to connect
if (!is_logged_in()) {
    http_response_code(401);
    exit;
}

// Disable output buffering completely
@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', false);
while (ob_get_level()) ob_end_clean();

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no'); // Disable nginx buffering
header('Connection: keep-alive');

set_time_limit(0);
ignore_user_abort(false);

$db = db();
$last_hash = '';

function sendEvent(string $event, array $data): void {
    echo "event: {$event}\n";
    echo 'data: ' . json_encode($data) . "\n\n";
    flush();
}

// Send initial state immediately
function getAvailabilitySnapshot(mysqli $db): array {
    $result = $db->query(
        "SELECT doctor_id, full_name, qualification, specialization, experience_years, is_available
         FROM telestudio_doctors ORDER BY full_name ASC"
    );
    $doctors = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $doctors[] = [
                'doctor_id'        => $row['doctor_id'],
                'full_name'        => $row['full_name'],
                'qualification'    => $row['qualification'] ?? '',
                'specialization'   => $row['specialization'] ?? '',
                'experience_years' => (int)($row['experience_years'] ?? 0),
                'is_available'     => (bool)$row['is_available'],
            ];
        }
    }
    return $doctors;
}

// Send initial snapshot
$snapshot = getAvailabilitySnapshot($db);
$last_hash = md5(json_encode($snapshot));
sendEvent('snapshot', ['doctors' => $snapshot]);

// Poll for changes every 2 seconds
while (true) {
    if (connection_aborted()) break;

    sleep(1);

    // Re-query (use new connection to avoid stale cache)
    $db->query('DO 1'); // keepalive ping
    $snapshot = getAvailabilitySnapshot($db);
    $new_hash  = md5(json_encode($snapshot));

    if ($new_hash !== $last_hash) {
        $last_hash = $new_hash;
        sendEvent('update', ['doctors' => $snapshot]);
    }

    // Heartbeat every cycle to keep connection alive
    echo ": heartbeat\n\n";
    flush();
}