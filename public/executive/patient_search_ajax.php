<?php
require_once __DIR__ . '/../../config/config.php';
require_role('executive', 'executive_login.php');
header('Content-Type: application/json');

$db = db();
ensure_patient_revisit_schema($db);

function sanitize_patient_id(string $value): string {
    $pid = strtoupper(trim($value));
    return preg_match('/^[A-Z0-9]{3,20}$/', $pid) ? $pid : '';
}

function sanitize_phone(string $value): string {
    $digits = preg_replace('/\D/', '', $value);
    return strlen($digits) === 10 ? $digits : '';
}

function fetch_patient_card(mysqli $db, string $pid): ?array {
    $stmt = $db->prepare(
        "SELECT p.patient_id, p.full_name, p.age, p.gender, p.mobile_no,
                COALESCE(p.patient_flag, 'N') AS stored_flag,
                COALESCE(p.diagnosis_status, 'pending') AS diagnosis_status,
                COALESCE(p.vitals_recorded, 0) AS vitals_recorded,
                EXISTS(
                    SELECT 1
                    FROM diagnosis_records dr
                    WHERE dr.patient_id = p.patient_id
                ) AS has_diagnosis
         FROM patients p
         WHERE p.patient_id = ?
         LIMIT 1"
    );
    $stmt->bind_param('s', $pid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return null;

    $row['patient_flag'] = ((int)$row['has_diagnosis'] === 1) ? 'O' : ($row['stored_flag'] ?? 'N');
    unset($row['stored_flag']);
    return $row;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = $_POST;
    if (!$payload) {
        $payload = json_decode(file_get_contents('php://input'), true) ?? [];
    }

    $action = trim((string)($payload['action'] ?? ''));
    if ($action !== 'mark_revisited') {
        echo json_encode(['success' => false, 'error' => 'Invalid action.']);
        exit;
    }

    $pid = sanitize_patient_id((string)($payload['patient_id'] ?? ''));
    if ($pid === '') {
        echo json_encode(['success' => false, 'error' => 'Valid patient ID is required.']);
        exit;
    }

    $patientBeforeUpdate = fetch_patient_card($db, $pid);
    if (!$patientBeforeUpdate) {
        echo json_encode(['success' => false, 'error' => "Patient {$pid} not found."]);
        exit;
    }
    if ((int)($patientBeforeUpdate['has_diagnosis'] ?? 0) !== 1) {
        echo json_encode([
            'success' => false,
            'error' => 'Only patients with at least one completed diagnosis can be marked revisited.'
        ]);
        exit;
    }

    $update = $db->prepare(
        "UPDATE patients
         SET patient_flag = 'O',
             diagnosis_status = 'pending',
             vitals_recorded = 0
         WHERE patient_id = ?"
    );
    $update->bind_param('s', $pid);
    $update->execute();
    $update->close();

    $patient = fetch_patient_card($db, $pid);
    echo json_encode([
        'success' => true,
        'message' => 'Patient marked as revisited.',
        'patient' => $patient,
    ]);
    exit;
}

// Backward-compatible single-field lookup (`q`) used by older UI.
$legacyQ = trim((string)($_GET['q'] ?? ''));
$pid     = sanitize_patient_id((string)($_GET['patient_id'] ?? ''));
$phone   = sanitize_phone((string)($_GET['phone'] ?? ''));
$oldOnly = ((string)($_GET['old_only'] ?? '') === '1');

if ($legacyQ !== '' && $pid === '' && $phone === '') {
    $stmt = $db->prepare(
        "SELECT patient_id, full_name, age, gender, mobile_no
         FROM patients
         WHERE patient_id = ? OR mobile_no = ? OR aadhar_no = ?
         LIMIT 1"
    );
    $stmt->bind_param('sss', $legacyQ, $legacyQ, $legacyQ);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) {
        echo json_encode(['found' => true] + $row);
    } else {
        echo json_encode(['found' => false]);
    }
    exit;
}

if ($pid === '' && $phone === '') {
    echo json_encode(['success' => false, 'error' => 'Enter Patient ID or 10-digit mobile number.']);
    exit;
}

$conditions = [];
$types = '';
$params = [];

if ($pid !== '') {
    $conditions[] = 'patient_id = ?';
    $types .= 's';
    $params[] = $pid;
}
if ($phone !== '') {
    $conditions[] = 'mobile_no = ?';
    $types .= 's';
    $params[] = $phone;
}

$sql = "SELECT p.patient_id, p.full_name, p.age, p.gender, p.mobile_no,
               COALESCE(p.patient_flag, 'N') AS stored_flag,
               COALESCE(p.diagnosis_status, 'pending') AS diagnosis_status,
               COALESCE(p.vitals_recorded, 0) AS vitals_recorded,
               EXISTS(
                   SELECT 1
                   FROM diagnosis_records dr
                   WHERE dr.patient_id = p.patient_id
               ) AS has_diagnosis
        FROM patients p
        WHERE " . implode(' OR ', $conditions) . '
        ' . ($oldOnly ? "AND EXISTS (SELECT 1 FROM diagnosis_records drx WHERE drx.patient_id = p.patient_id)" : '') . '
        ORDER BY p.created_at DESC
        LIMIT 100';

$stmt = $db->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

foreach ($rows as &$row) {
    $row['patient_flag'] = ((int)$row['has_diagnosis'] === 1) ? 'O' : ($row['stored_flag'] ?? 'N');
    unset($row['stored_flag']);
}
unset($row);

echo json_encode([
    'success' => true,
    'count' => count($rows),
    'patients' => $rows,
]);
