<?php
require_once __DIR__ . '/../../config/config.php';
start_session();

header('Content-Type: application/json');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$role = (string)get_session_role();
if (!in_array($role, ['doctor', 'telestudio_doctor'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

$pid = strtoupper(trim((string)($_GET['patientId'] ?? '')));
if (!preg_match('/^[A-Z0-9]{3,20}$/', $pid)) {
    echo json_encode(['success' => false, 'error' => 'Valid patient ID is required.']);
    exit;
}

$db = db();
ensure_patient_revisit_schema($db);

$patientStmt = $db->prepare(
    "SELECT patient_id, full_name, age, gender, mobile_no, address_full, photo_filename,
            COALESCE(patient_flag, 'N') AS patient_flag
     FROM patients
     WHERE patient_id = ?
     LIMIT 1"
);
$patientStmt->bind_param('s', $pid);
$patientStmt->execute();
$patient = $patientStmt->get_result()->fetch_assoc();
$patientStmt->close();

if (!$patient) {
    echo json_encode(['success' => false, 'error' => "Patient {$pid} not found."]);
    exit;
}

$patient['photo_url'] = patient_photo_url($patient['photo_filename'] ?? null);
unset($patient['photo_filename']);

// eHealth center diagnosis history (in-person diagnosis records)
$ehStmt = $db->prepare(
    "SELECT d.id, d.patient_id, d.doctor_id, COALESCE(ed.full_name, '') AS doctor_name,
            d.chief_complaint, d.clinical_notes,
            d.provisional_diagnosis, d.investigation_prescribed, d.review_after,
            d.teleconsultation_reason, d.remarks, d.diagnosis_date,
            v.bp_systolic, v.bp_diastolic, v.heart_rate, v.temperature, v.spo2, v.resp_rate,
            v.weight, v.height, v.bmi, v.recorded_at AS vitals_at
     FROM diagnosis_records d
     LEFT JOIN ehealth_center_doctors ed ON ed.doctor_id = d.doctor_id
     LEFT JOIN patient_vitals v ON v.id = (
         SELECT pv.id
         FROM patient_vitals pv
         WHERE pv.patient_id = d.patient_id
           AND pv.recorded_at <= d.diagnosis_date
         ORDER BY pv.recorded_at DESC
         LIMIT 1
     )
     WHERE d.patient_id = ?
       AND COALESCE(d.teleconsultation_recommended, 0) = 0
     ORDER BY d.diagnosis_date DESC"
);
$ehStmt->bind_param('s', $pid);
$ehStmt->execute();
$ehealthRows = $ehStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$ehStmt->close();

$diagnosisIds = [];
foreach ($ehealthRows as $row) {
    $diagnosisIds[] = (int)$row['id'];
}
$diagnosisIds = array_values(array_unique(array_filter($diagnosisIds)));

$medicinesByDiagnosis = [];
if (!empty($diagnosisIds)) {
    $ph = implode(',', array_fill(0, count($diagnosisIds), '?'));
    $types = str_repeat('i', count($diagnosisIds));
    $medStmt = $db->prepare(
        "SELECT diagnosis_id, medicine_name, dosage, timing, meal_instruction, duration
         FROM prescriptions
         WHERE diagnosis_id IN ({$ph})
         ORDER BY id ASC"
    );
    $medStmt->bind_param($types, ...$diagnosisIds);
    $medStmt->execute();
    $medRows = $medStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $medStmt->close();

    foreach ($medRows as $med) {
        $dId = (int)$med['diagnosis_id'];
        $timingRaw = trim((string)($med['timing'] ?? ''));
        $timingDecoded = json_decode($timingRaw, true);
        if (!is_array($timingDecoded)) {
            $timingDecoded = $timingRaw !== '' ? [$timingRaw] : [];
        }
        $med['timing_list'] = $timingDecoded;
        $medicinesByDiagnosis[$dId][] = $med;
    }
}

foreach ($ehealthRows as &$row) {
    $row['medicines'] = $medicinesByDiagnosis[(int)$row['id']] ?? [];
}
unset($row);

// Teleconsultation diagnosis history
$teleStmt = $db->prepare(
    "SELECT td.id, td.session_id, td.patient_id,
            td.ehealth_doctor_id, td.ehealth_doctor_name,
            td.telestudio_doctor_id, td.telestudio_doctor_name,
            td.chief_complaint, td.clinical_notes, td.consultation_notes,
            td.primary_diagnosis, td.prescription, td.medicines_json,
            td.review_after, td.investigation, td.remarks, td.diagnosis_date,
            ts.teleconsult_reason,
            v.bp_systolic, v.bp_diastolic, v.heart_rate, v.temperature, v.spo2, v.resp_rate,
            v.weight, v.height, v.bmi, v.recorded_at AS vitals_at
     FROM telestudio_diagnosis td
     LEFT JOIN teleconsult_sessions ts ON ts.id = td.session_id
     LEFT JOIN patient_vitals v ON v.id = (
         SELECT pv.id
         FROM patient_vitals pv
         WHERE pv.patient_id = td.patient_id
           AND pv.recorded_at <= td.diagnosis_date
         ORDER BY pv.recorded_at DESC
         LIMIT 1
     )
     WHERE td.patient_id = ?
     ORDER BY td.diagnosis_date DESC"
);
$teleStmt->bind_param('s', $pid);
$teleStmt->execute();
$teleRows = $teleStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$teleStmt->close();

$isOld = !empty($ehealthRows) || !empty($teleRows) || (($patient['patient_flag'] ?? 'N') === 'O');

echo json_encode([
    'success' => true,
    'patient' => $patient,
    'is_old' => $isOld,
    'ehealth_history' => $ehealthRows,
    'tele_history' => $teleRows,
]);
