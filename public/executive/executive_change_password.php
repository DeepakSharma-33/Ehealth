<?php
require_once __DIR__ . '/../../config/config.php';
start_session();

if (!is_logged_in() || get_session_role() !== 'executive') {
    json_error('Unauthorized', 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed', 405);
}

$oldPassword = $_POST['oldPassword'] ?? '';
$newPassword = $_POST['newPassword'] ?? '';

if (!$oldPassword || !$newPassword) { json_error('All fields are required.'); }
if (strlen($newPassword) < 6)       { json_error('New password must be at least 6 characters.'); }

// get_session_id() returns the numeric DB id (set via set_user_session(['id' => $row['id']]))
// Use the numeric `id` column, NOT the `executive_id` string column
$numericId = get_session_id();
$db        = db();

$st = $db->prepare('SELECT password FROM executives WHERE id = ?');
$st->bind_param('i', $numericId);
$st->execute();
$row = $st->get_result()->fetch_assoc();
$st->close();

if (!$row)                                            { json_error('Executive not found.', 404); }
if (!verify_password($oldPassword, $row['password'])) { json_error('Current password is incorrect.'); }

$hashed = hash_password($newPassword);
$up = $db->prepare('UPDATE executives SET password = ? WHERE id = ?');
$up->bind_param('si', $hashed, $numericId);
$up->execute();
$up->close();

json_success([], 'Password updated successfully.');