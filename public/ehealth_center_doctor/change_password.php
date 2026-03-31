<?php
// POST /ehealth_center_doctor/change_password.php
// Called via fetch() from doctor_dashboard.php
require_once __DIR__ . '/../../config/config.php';
start_session();
header('Content-Type: application/json');

if (!is_logged_in() || get_session_role() !== 'doctor') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

$body            = json_decode(file_get_contents('php://input'), true) ?? [];
$currentPassword = $body['currentPassword'] ?? '';
$newPassword     = $body['newPassword'] ?? '';
$doctorId        = get_session_id();

if (!$currentPassword || !$newPassword) {
    echo json_encode(['success' => false, 'error' => 'All fields are required.']);
    exit;
}
if (strlen($newPassword) < 6) {
    echo json_encode(['success' => false, 'error' => 'New password must be at least 6 characters.']);
    exit;
}

$db   = db();
$stmt = $db->prepare('SELECT password FROM ehealth_center_doctors WHERE doctor_id = ? LIMIT 1');
$stmt->bind_param('s', $doctorId);
$stmt->execute();
$row  = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    echo json_encode(['success' => false, 'error' => 'Doctor not found.']);
    exit;
}

if (!verify_password($currentPassword, $row['password'])) {
    echo json_encode(['success' => false, 'error' => 'Current password is incorrect.']);
    exit;
}

$hashed = hash_password($newPassword);
$upd    = $db->prepare('UPDATE ehealth_center_doctors SET password = ? WHERE doctor_id = ?');
$upd->bind_param('ss', $hashed, $doctorId);
$upd->execute();
$upd->close();

echo json_encode(['success' => true, 'message' => 'Password changed successfully.']);