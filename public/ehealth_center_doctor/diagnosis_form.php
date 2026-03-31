<?php
require_once __DIR__ . '/../../config/config.php';
require_role('doctor', 'doctor_login.php');

$doctor_id = get_session_id();

if (isset($_POST['logout'])) {
    logout_user();
    header('Location: doctor_login.php');
    exit;
}

// ── AJAX: get patient ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'get_patient') {
    header('Content-Type: application/json');
    $pid = trim($_GET['patientId'] ?? '');
    if (!$pid) { echo json_encode(['success'=>false,'error'=>'Patient ID required.']); exit; }

    $db   = db();
    $stmt = $db->prepare('SELECT * FROM patients WHERE patient_id = ? LIMIT 1');
    $stmt->bind_param('s', $pid);
    $stmt->execute();
    $patient = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$patient) { echo json_encode(['success'=>false,'error'=>'No patient found with ID: '.htmlspecialchars($pid)]); exit; }

    $vs = $db->prepare('SELECT * FROM patient_vitals WHERE patient_id = ? ORDER BY recorded_at DESC LIMIT 1');
    $vs->bind_param('s', $pid);
    $vs->execute();
    $vitals = $vs->get_result()->fetch_assoc();
    $vs->close();

    $patient['photoUrl'] = patient_photo_url($patient['photo_filename'] ?? null) ?: null;
    echo json_encode(['success'=>true,'patient'=>$patient,'latestVitals'=>$vitals]);
    exit;
}

// ── AJAX: update vitals ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'update_vitals') {
    header('Content-Type: application/json');
    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    $pid    = trim($body['patientId'] ?? '');
    $notes  = trim($body['notes'] ?? '');

    // Helper: return null for empty strings, cast otherwise
    $intOrNull   = fn($v) => ($v !== null && $v !== '') ? (int)$v   : null;
    $floatOrNull = fn($v) => ($v !== null && $v !== '') ? (float)$v : null;

    $bp_sys  = $intOrNull($body['bp_systolic']  ?? null);
    $bp_dia  = $intOrNull($body['bp_diastolic'] ?? null);
    $hr      = $intOrNull($body['heart_rate']   ?? null);
    $temp    = $floatOrNull($body['temperature'] ?? null);
    $spo2    = $intOrNull($body['spo2']          ?? null);
    $resp    = $intOrNull($body['resp_rate']     ?? null);
    $weight  = $floatOrNull($body['weight']      ?? null);
    $height  = $floatOrNull($body['height']      ?? null);
    $bmi     = ($weight && $height > 0) ? round($weight / (($height / 100) ** 2), 1) : null;

    if (!$pid) { echo json_encode(['success'=>false,'error'=>'Patient ID required.']); exit; }

    $db = db();

    // Helper to safely quote a nullable value for raw SQL
    $sq = fn($v) => $v !== null ? $db->real_escape_string($v) : null;
    $sqlVal = fn($v, $quoted = false) => $v !== null
        ? ($quoted ? "'" . $db->real_escape_string($v) . "'" : $v)
        : 'NULL';

    // Check if vitals row already exists
    $chk = $db->prepare('SELECT id FROM patient_vitals WHERE patient_id = ? ORDER BY recorded_at DESC LIMIT 1');
    $chk->bind_param('s', $pid);
    $chk->execute();
    $existing = $chk->get_result()->fetch_assoc();
    $chk->close();

    $escapedNotes = $db->real_escape_string($notes);
    $escapedPid   = $db->real_escape_string($pid);

    if ($existing) {
        $sql = "UPDATE patient_vitals SET
            bp_systolic  = {$sqlVal($bp_sys)},
            bp_diastolic = {$sqlVal($bp_dia)},
            heart_rate   = {$sqlVal($hr)},
            temperature  = {$sqlVal($temp)},
            spo2         = {$sqlVal($spo2)},
            weight       = {$sqlVal($weight)},
            height       = {$sqlVal($height)},
            bmi          = {$sqlVal($bmi)},
            notes        = '{$escapedNotes}',
            recorded_at  = NOW()
            WHERE id = {$existing['id']}";

        // resp_rate column: only include if column exists to avoid crash on older DBs
        $cols = $db->query("SHOW COLUMNS FROM patient_vitals LIKE 'resp_rate'");
        if ($cols && $cols->num_rows > 0) {
            $sql = str_replace(
                "bp_systolic",
                "resp_rate = {$sqlVal($resp)}, bp_systolic",
                $sql
            );
        }
    } else {
        $exec_id   = (string)get_session_id();
        $exec_name = $db->real_escape_string(get_session_name());

        // Check resp_rate column existence
        $cols     = $db->query("SHOW COLUMNS FROM patient_vitals LIKE 'resp_rate'");
        $hasResp  = $cols && $cols->num_rows > 0;
        $cols2    = $db->query("SHOW COLUMNS FROM patient_vitals LIKE 'height'");
        $hasHeight = $cols2 && $cols2->num_rows > 0;

        $colList = "patient_id, bp_systolic, bp_diastolic, heart_rate, temperature, spo2, weight, bmi, notes, recorded_by_exec_id, recorded_by_exec_name";
        $valList = "'{$escapedPid}', {$sqlVal($bp_sys)}, {$sqlVal($bp_dia)}, {$sqlVal($hr)}, {$sqlVal($temp)}, {$sqlVal($spo2)}, {$sqlVal($weight)}, {$sqlVal($bmi)}, '{$escapedNotes}', '{$exec_id}', '{$exec_name}'";

        if ($hasResp) {
            $colList = "patient_id, bp_systolic, bp_diastolic, heart_rate, temperature, spo2, resp_rate, weight, bmi, notes, recorded_by_exec_id, recorded_by_exec_name";
            $valList = "'{$escapedPid}', {$sqlVal($bp_sys)}, {$sqlVal($bp_dia)}, {$sqlVal($hr)}, {$sqlVal($temp)}, {$sqlVal($spo2)}, {$sqlVal($resp)}, {$sqlVal($weight)}, {$sqlVal($bmi)}, '{$escapedNotes}', '{$exec_id}', '{$exec_name}'";
        }
        if ($hasHeight) {
            $colList = str_replace('weight, bmi', 'weight, height, bmi', $colList);
            $valList = str_replace("{$sqlVal($weight)}, {$sqlVal($bmi)}", "{$sqlVal($weight)}, {$sqlVal($height)}, {$sqlVal($bmi)}", $valList);
        }

        $sql = "INSERT INTO patient_vitals ($colList) VALUES ($valList)";
    }

    if ($db->query($sql)) {
        $db->query("UPDATE patients SET vitals_recorded = 1 WHERE patient_id = '{$escapedPid}'");
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'DB error: ' . $db->error]);
    }
    exit;
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    $patientId               = trim($body['patientId'] ?? '');
    $chiefComplaint          = trim($body['chiefComplaint'] ?? '');
    $clinicalNotes           = trim($body['clinicalNotes'] ?? '');
    $provisionalDiagnosis    = trim($body['provisionalDiagnosis'] ?? '');
    $investigationPrescribed = trim($body['investigationPrescribed'] ?? '');
    $reviewAfter             = trim($body['reviewAfter'] ?? '');
    $remarks                 = trim($body['remarks'] ?? '');
    $medicines               = $body['medicines'] ?? [];

    // teleconsultation_recommended always 0 from this form
    $teleconsultationRecommended = 0;
    $teleconsultationReason      = '';

    if (!$patientId) { echo json_encode(['success'=>false,'error'=>'Patient ID required.']); exit; }

    $db  = db();
    $chk = $db->prepare('SELECT patient_id FROM patients WHERE patient_id = ? LIMIT 1');
    $chk->bind_param('s', $patientId);
    $chk->execute();
    if ($chk->get_result()->num_rows === 0) { echo json_encode(['success'=>false,'error'=>'Patient not found.']); exit; }
    $chk->close();

    $db->begin_transaction();
    try {
        $ins = $db->prepare(
            'INSERT INTO diagnosis_records
             (patient_id, doctor_id, chief_complaint, clinical_notes, provisional_diagnosis,
              investigation_prescribed, review_after, teleconsultation_recommended,
              teleconsultation_reason, remarks, diagnosis_date)
             VALUES (?,?,?,?,?,?,?,?,?,?,NOW())'
        );
        $ins->bind_param('sssssssiss',
            $patientId, $doctor_id, $chiefComplaint, $clinicalNotes,
            $provisionalDiagnosis, $investigationPrescribed, $reviewAfter,
            $teleconsultationRecommended, $teleconsultationReason, $remarks
        );
        $ins->execute();
        $diagnosisId = $db->insert_id;
        $ins->close();

        foreach ($medicines as $med) {
            $name   = trim($med['name'] ?? '');
            $dosage = $med['dosage'] ?? '';
            if (!$name) continue;
            $timing = json_encode(is_array($med['timing'] ?? null) ? $med['timing'] : [$med['timing']]);
            $meal   = $med['mealInstruction'] ?? '';
            $dur    = $med['duration'] ?? '';
            $pm = $db->prepare(
                'INSERT INTO prescriptions (diagnosis_id, patient_id, doctor_id, medicine_name, dosage, timing, meal_instruction, duration, prescribed_at)
                 VALUES (?,?,?,?,?,?,?,?,NOW())'
            );
            $pm->bind_param('isssssss', $diagnosisId, $patientId, $doctor_id, $name, $dosage, $timing, $meal, $dur);
            $pm->execute();
            $pm->close();
        }

        $upd = $db->prepare("UPDATE patients SET diagnosis_status='completed' WHERE patient_id=?");
        $upd->bind_param('s', $patientId);
        $upd->execute();
        $upd->close();

        $db->commit();
        echo json_encode(['success'=>true,'message'=>'Diagnosis saved successfully.','diagnosisId'=>$diagnosisId]);
    } catch (Exception $e) {
        $db->rollback();
        echo json_encode(['success'=>false,'error'=>'DB error: '.$e->getMessage()]);
    }
    exit;
}

$prefill_id = htmlspecialchars(trim($_GET['patientId'] ?? ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Diagnosis Form — eHealth Center</title>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;600;700;800&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
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
        }

        body {
            font-family: 'Sora', sans-serif;
            background: #a8e6b0;
            min-height: 100vh;
            background-image:
                radial-gradient(ellipse 70% 50% at 5% 0%, rgba(27,94,32,0.15) 0%, transparent 55%),
                radial-gradient(ellipse 50% 40% at 95% 100%, rgba(67,160,71,0.1) 0%, transparent 55%);
        }

        /* ── NAVBAR ── */
        .navbar {
            background: var(--g-dark);
            height: 60px; padding: 0 36px;
            display: flex; align-items: center; justify-content: space-between;
            box-shadow: 0 2px 12px rgba(0,0,0,0.22);
            position: sticky; top: 0; z-index: 100;
        }
        .navbar-brand { display: flex; align-items: center; gap: 10px; text-decoration: none; }
        .brand-circle { width: 36px; height: 36px; background: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 17px; color: var(--g-dark); font-weight: 800; }
        .brand-name { font-size: 18px; font-weight: 800; letter-spacing: 2px; color: white; }
        .nav-right { display: flex; align-items: center; gap: 22px; }
        .nav-link { color: rgba(255,255,255,0.85); text-decoration: none; font-size: 13px; font-weight: 500; transition: color 0.2s; }
        .nav-link:hover { color: white; }
        .btn-logout { border: 2px solid rgba(255,255,255,0.6); background: transparent; color: white; padding: 5px 16px; border-radius: 20px; font-size: 12px; font-weight: 700; cursor: pointer; font-family: inherit; letter-spacing: 0.5px; transition: all 0.2s; }
        .btn-logout:hover { background: white; color: var(--g-dark); border-color: white; }

        /* ── PAGE WRAPPER ── */
        .page-wrap { max-width: 960px; margin: 0 auto; padding: 36px 24px 60px; }

        /* ── BREADCRUMB ── */
        .breadcrumb { font-size: 12px; color: rgba(255,255,255,0.75); font-family: 'JetBrains Mono', monospace; margin-bottom: 20px; }
        .breadcrumb a { color: rgba(255,255,255,0.85); text-decoration: none; }
        .breadcrumb a:hover { color: white; }
        .breadcrumb span { margin: 0 6px; }

        /* ── PAGE TITLE ── */
        .page-title-row { display: flex; align-items: center; gap: 14px; margin-bottom: 28px; }
        .page-title-icon { width: 52px; height: 52px; background: white; border-radius: 16px; display: flex; align-items: center; justify-content: center; font-size: 26px; box-shadow: 0 4px 12px rgba(0,0,0,0.12); }
        .page-title h1 { font-size: 26px; font-weight: 800; color: white; letter-spacing: -0.3px; }
        .page-title p { font-size: 13px; color: rgba(255,255,255,0.8); margin-top: 2px; }

        /* ── PATIENT SEARCH BAR ── */
        .search-card { background: white; border-radius: 16px; padding: 22px 28px; margin-bottom: 20px; box-shadow: 0 3px 14px rgba(0,0,0,0.08); display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }
        .search-card label { font-size: 13px; font-weight: 700; color: var(--text-mid); white-space: nowrap; }
        .search-input { flex: 1; min-width: 160px; padding: 10px 16px; border: 2px solid var(--g-border); border-radius: 10px; font-size: 14px; font-family: 'Sora', sans-serif; font-weight: 600; outline: none; transition: border-color 0.2s; color: var(--text-dark); }
        .search-input:focus { border-color: var(--g-mid); box-shadow: 0 0 0 3px rgba(46,125,50,0.1); }
        .btn-load { padding: 10px 22px; background: linear-gradient(135deg, var(--g-mid), var(--g-light)); color: white; border: none; border-radius: 10px; font-size: 14px; font-weight: 700; cursor: pointer; font-family: 'Sora', sans-serif; transition: opacity 0.2s; white-space: nowrap; }
        .btn-load:hover { opacity: 0.88; }
        .search-error { width: 100%; font-size: 12px; color: var(--red); background: var(--red-pale); padding: 8px 12px; border-radius: 8px; display: none; }

        /* ── PATIENT BANNER ── */
        .patient-banner { background: white; border-radius: 16px; padding: 22px 28px; margin-bottom: 20px; box-shadow: 0 3px 14px rgba(0,0,0,0.08); display: none; border-left: 5px solid var(--g-mid); }
        .patient-banner.show { display: flex; align-items: center; gap: 20px; flex-wrap: wrap; }
        .patient-photo { width: 64px; height: 64px; border-radius: 50%; object-fit: cover; border: 3px solid var(--g-pale); flex-shrink: 0; }
        .patient-avatar { width: 64px; height: 64px; border-radius: 50%; background: var(--g-pale); display: flex; align-items: center; justify-content: center; font-size: 30px; border: 3px solid var(--g-border); flex-shrink: 0; }
        .banner-info { flex: 1; }
        .banner-name { font-size: 20px; font-weight: 800; color: var(--text-dark); }
        .banner-id { font-size: 12px; font-weight: 700; background: var(--g-pale); color: var(--g-mid); padding: 3px 12px; border-radius: 20px; display: inline-block; margin: 4px 0 8px; }
        .banner-meta { display: flex; gap: 20px; flex-wrap: wrap; font-size: 13px; color: var(--text-soft); }
        .banner-meta b { color: var(--text-mid); font-weight: 600; }
        .btn-history { margin-left: auto; padding: 9px 18px; background: white; color: var(--g-mid); border: 2px solid var(--g-mid); border-radius: 10px; font-size: 13px; font-weight: 700; cursor: pointer; font-family: 'Sora', sans-serif; transition: all 0.2s; white-space: nowrap; }
        .btn-history:hover { background: var(--g-mid); color: white; }

        /* ── VITALS STRIP ── */
        .vitals-strip { background: white; border-radius: 16px; padding: 20px 28px; margin-bottom: 20px; box-shadow: 0 3px 14px rgba(0,0,0,0.08); display: none; }
        .vitals-strip.show { display: block; }
        .vitals-strip-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }
        .vitals-strip-title { font-size: 14px; font-weight: 700; color: var(--g-mid); display: flex; align-items: center; gap: 8px; }
        .vitals-strip-ts { font-size: 12px; color: var(--text-soft); font-family: 'JetBrains Mono', monospace; }
        .vitals-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 12px; }
        .vital-card { background: var(--g-mist); border: 1px solid var(--g-border); border-radius: 12px; padding: 14px 12px; text-align: center; }
        .vital-label { font-size: 11px; font-weight: 700; color: var(--text-soft); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px; }
        .vital-value { font-size: 20px; font-weight: 800; color: var(--g-mid); font-family: 'JetBrains Mono', monospace; }
        .vital-unit { font-size: 11px; font-weight: 600; color: var(--text-soft); margin-left: 2px; }

        /* ── VITALS EDIT BUTTON ── */
        .btn-edit-vitals { padding: 7px 14px; background: white; color: var(--g-mid); border: 2px solid var(--g-mid); border-radius: 8px; font-size: 12px; font-weight: 700; cursor: pointer; font-family: 'Sora', sans-serif; transition: all 0.2s; white-space: nowrap; }
        .btn-edit-vitals:hover { background: var(--g-mid); color: white; }

        /* ── VITALS EDIT MODAL ── */
        .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.45); z-index: 500; align-items: center; justify-content: center; padding: 20px; }
        .modal-overlay.show { display: flex; }
        .modal-box { background: white; border-radius: 20px; padding: 32px; width: 100%; max-width: 600px; box-shadow: 0 20px 60px rgba(0,0,0,0.25); animation: modalIn 0.25s cubic-bezier(0.22,1,0.36,1); max-height: 90vh; overflow-y: auto; }
        @keyframes modalIn { from { opacity:0; transform:scale(0.94) translateY(12px); } to { opacity:1; transform:scale(1) translateY(0); } }
        .modal-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; }
        .modal-title { font-size: 18px; font-weight: 800; color: var(--g-dark); }
        .modal-close { width: 32px; height: 32px; border-radius: 50%; border: 2px solid var(--g-border); background: white; color: var(--text-soft); font-size: 16px; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.2s; }
        .modal-close:hover { background: var(--red-pale); color: var(--red); border-color: #ffcdd2; }
        .vitals-edit-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .vitals-edit-field label { display: block; font-size: 12px; font-weight: 700; color: var(--text-mid); margin-bottom: 5px; text-transform: uppercase; letter-spacing: 0.5px; }
        .vitals-edit-field input { width: 100%; padding: 10px 14px; border: 2px solid var(--g-border); border-radius: 10px; font-size: 14px; font-family: 'Sora', sans-serif; color: var(--text-dark); background: #fafcfa; outline: none; transition: border-color 0.2s; }
        .vitals-edit-field input:focus { border-color: var(--g-mid); box-shadow: 0 0 0 3px rgba(46,125,50,0.08); background: white; }
        .vitals-edit-field .field-unit { font-size: 11px; color: var(--text-soft); margin-top: 3px; }
        .bp-row { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
        .modal-footer { display: flex; gap: 10px; margin-top: 24px; }
        .btn-modal-save { flex: 1; padding: 12px; background: linear-gradient(135deg, var(--g-mid), var(--g-light)); color: white; border: none; border-radius: 10px; font-size: 14px; font-weight: 700; cursor: pointer; font-family: 'Sora', sans-serif; transition: opacity 0.2s; }
        .btn-modal-save:hover { opacity: 0.88; }
        .btn-modal-cancel { padding: 12px 22px; background: white; color: var(--text-soft); border: 2px solid var(--g-border); border-radius: 10px; font-size: 14px; font-weight: 700; cursor: pointer; font-family: 'Sora', sans-serif; transition: all 0.2s; }
        .btn-modal-cancel:hover { border-color: var(--text-mid); color: var(--text-dark); }
        .modal-alert { display: none; padding: 10px 14px; border-radius: 8px; margin-bottom: 14px; font-size: 13px; font-weight: 600; }
        .modal-alert.show { display: block; }
        .modal-alert.success { background: #e8f5e9; color: var(--g-mid); }
        .modal-alert.error   { background: var(--red-pale); color: var(--red); }

        /* ── MAIN FORM CARD ── */
        .form-card { background: white; border-radius: 16px; padding: 32px 36px; box-shadow: 0 3px 14px rgba(0,0,0,0.08); display: none; }
        .form-card.show { display: block; }

        /* Alert */
        .alert { display: none; padding: 13px 18px; border-radius: 10px; margin-bottom: 24px; font-size: 13px; font-weight: 600; }
        .alert.show { display: block; }
        .alert.success { background: #e8f5e9; color: #2e7d32; border: 1px solid #c8e6c9; }
        .alert.error   { background: var(--red-pale); color: var(--red); border: 1px solid #ffcdd2; }

        /* Section headers inside form */
        .form-section-title {
            font-size: 11px; font-weight: 800; letter-spacing: 2px;
            text-transform: uppercase; color: var(--g-mid);
            border-bottom: 2px solid var(--g-pale);
            padding-bottom: 10px; margin: 28px 0 20px;
        }
        .form-section-title:first-child { margin-top: 0; }

        .form-group { margin-bottom: 20px; }
        .form-label { display: block; font-size: 13px; font-weight: 700; color: var(--text-mid); margin-bottom: 7px; }
        .form-label .opt { font-weight: 400; color: var(--text-soft); font-size: 11px; margin-left: 4px; }
        .form-control {
            width: 100%; padding: 11px 15px;
            border: 2px solid var(--g-border); border-radius: 10px;
            font-size: 14px; font-family: 'Sora', sans-serif;
            min-height: 88px; resize: vertical;
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

        /* Review After — custom other input */
        .review-other-wrap { margin-top: 10px; display: none; }
        .review-other-wrap.show { display: block; }
        .review-other-input { width: 100%; padding: 10px 14px; border: 2px solid var(--g-border); border-radius: 10px; font-size: 14px; font-family: 'Sora', sans-serif; outline: none; background: #fafcfa; color: var(--text-dark); transition: border-color 0.2s; }
        .review-other-input:focus { border-color: var(--g-mid); box-shadow: 0 0 0 3px rgba(46,125,50,0.08); background: white; }

        /* ── MEDICINE TABLE ── */
        .btn-add-medicine {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 9px 18px;
            background: var(--g-pale); color: var(--g-mid);
            border: 2px dashed var(--g-mid); border-radius: 10px;
            font-size: 13px; font-weight: 700; cursor: pointer;
            font-family: 'Sora', sans-serif; margin-bottom: 14px;
            transition: background 0.2s;
        }
        .btn-add-medicine:hover { background: #d4edda; }

        .med-table-wrap { overflow-x: auto; border-radius: 12px; border: 1px solid var(--g-border); }
        .medicine-table { width: 100%; border-collapse: collapse; font-size: 13px; display: none; }
        .medicine-table.show { display: table; }
        .medicine-table th { background: var(--g-mid); color: white; padding: 11px 12px; text-align: left; font-size: 12px; font-weight: 700; letter-spacing: 0.3px; }
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

        /* ── FORM ACTION BUTTONS ── */
        .form-buttons { display: flex; gap: 12px; margin-top: 32px; flex-wrap: wrap; }
        .btn-primary {
            flex: 1; min-width: 160px; padding: 14px;
            background: linear-gradient(135deg, var(--g-mid), var(--g-light));
            color: white; border: none; border-radius: 12px;
            font-size: 15px; font-weight: 700; cursor: pointer;
            font-family: 'Sora', sans-serif; transition: opacity 0.2s, transform 0.15s;
        }
        .btn-primary:hover { opacity: 0.9; transform: translateY(-1px); }
        .btn-primary:active { transform: translateY(0); }
        .btn-secondary {
            flex: 1; min-width: 160px; padding: 14px;
            background: white; color: var(--g-mid);
            border: 2px solid var(--g-mid); border-radius: 12px;
            font-size: 15px; font-weight: 700; cursor: pointer;
            font-family: 'Sora', sans-serif; transition: all 0.2s;
        }
        .btn-secondary:hover { background: var(--g-mid); color: white; }
        .btn-reset {
            padding: 14px 22px;
            background: #f5f5f5; color: var(--text-soft);
            border: 2px solid #e0e0e0; border-radius: 12px;
            font-size: 14px; font-weight: 700; cursor: pointer;
            font-family: 'Sora', sans-serif; transition: all 0.2s;
        }
        .btn-reset:hover { background: #eeeeee; color: var(--text-mid); }

        @media (max-width: 640px) {
            .navbar { padding: 0 16px; }
            .page-wrap { padding: 24px 14px 50px; }
            .form-card { padding: 22px 18px; }
            .form-buttons { flex-direction: column; }
            .search-card { padding: 16px 18px; }
            .patient-banner.show { flex-wrap: wrap; }
        }
    </style>
</head>
<body>

<!-- NAVBAR -->
<nav class="navbar">
    <a href="<?= htmlspecialchars(app_base_url()) ?>/" class="navbar-brand">
        <span style="display:flex;align-items:center;gap:14px;line-height:1;"><img src="<?= htmlspecialchars(app_base_url()) ?>/public/assets/SRMS_TRUST_LOGO.png" alt="SRMST Trust Logo" style="height:48px;width:auto;display:block;object-fit:contain;filter:drop-shadow(0 1px 3px rgba(0,0,0,.35));"><span style="font-size:22px;font-weight:800;letter-spacing:1px;color:#fff;text-shadow:0 1px 2px rgba(0,0,0,.35);white-space:nowrap;">SRMS EHEALTH</span></span>
    </a>
    <div class="nav-right">
        <a href="patient_queue.php" class="nav-link">← Patient Queue</a>
        <a href="<?= htmlspecialchars(app_base_url()) ?>/about.php" class="nav-link">ABOUT</a>
        <a href="<?= htmlspecialchars(app_base_url()) ?>/contact.php" class="nav-link">CONTACT</a>
        <form method="POST" style="display:inline">
            <button type="submit" name="logout" class="btn-logout">LOGOUT</button>
        </form>
    </div>
</nav>

<div class="page-wrap">

    <!-- Breadcrumb -->
    <div class="breadcrumb">
        <a href="doctor_dashboard.php">Dashboard</a>
        <span>›</span>
        <a href="patient_queue.php">Patient Queue</a>
        <span>›</span>
        Diagnosis Form
    </div>

    <!-- Page Title -->
    <div class="page-title-row">
        <div class="page-title-icon">🩺</div>
        <div class="page-title">
            <h1>Diagnosis Form</h1>
            <p>In-person clinical assessment &amp; prescription</p>
        </div>
    </div>

    <!-- Patient Search -->
    <div class="search-card">
        <label for="patientIdInput">Patient ID</label>
        <input type="text" class="search-input" id="patientIdInput"
               placeholder="e.g. P3062" autocomplete="off"
               value="<?= $prefill_id ?>">
        <button class="btn-load" id="loadPatientBtn">🔍 Load Patient</button>
        <div class="search-error" id="searchError"></div>
    </div>

    <!-- Patient Banner -->
    <div class="patient-banner" id="patientBanner">
        <div id="patientPhotoSection"></div>
        <div class="banner-info">
            <div class="banner-name" id="patientNameLarge"></div>
            <div class="banner-id" id="patientIdBadge"></div>
            <div class="banner-meta">
                <span><b>Age:</b> <span id="patientAge"></span></span>
                <span><b>Gender:</b> <span id="patientGender"></span></span>
                <span><b>Mobile:</b> <span id="patientMobile"></span></span>
            </div>
        </div>
        <button class="btn-history" id="viewHistoryBtn">📋 History</button>
    </div>

    <!-- Vitals Strip -->
    <div class="vitals-strip" id="vitalsSection">
        <div class="vitals-strip-header">
            <div class="vitals-strip-title">❤️ Latest Vital Signs</div>
            <div style="display:flex;align-items:center;gap:12px;">
                <div class="vitals-strip-ts" id="vitalsTimestamp"></div>
                <button class="btn-edit-vitals" id="editVitalsBtn" onclick="openVitalsModal()">✏️ Edit Vitals</button>
            </div>
        </div>
        <div class="vitals-grid" id="vitalsGrid"></div>
    </div>

    <!-- Main Form Card -->
    <div class="form-card" id="formSection">
        <div class="alert" id="alertBox"></div>

        <form id="diagnosisForm">

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

            <!-- Medicines -->
            <div class="form-group">
                <label class="form-label">Medicines Prescribed <span class="opt">(optional)</span></label>
                <button type="button" class="btn-add-medicine" id="addMedicineBtn">➕ Add Medicine</button>
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

            <!-- Review After -->
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

            <!-- Remarks -->
            <div class="form-group">
                <label class="form-label">Remarks / Additional Instructions <span class="opt">(optional)</span></label>
                <textarea class="form-control" id="remarks" placeholder="Any additional instructions or notes for the patient…"></textarea>
            </div>

            <!-- Buttons -->
            <div class="form-buttons">
                <button type="submit" class="btn-primary" id="saveBtn">💾 Save Diagnosis</button>
                <button type="button" class="btn-secondary" id="savePrintBtn">🖨️ Save &amp; Print</button>
                <button type="button" class="btn-reset" id="resetBtn">🔄 Reset</button>
            </div>

        </form>
    </div><!-- /form-card -->

</div><!-- /page-wrap -->

<!-- ── VITALS EDIT MODAL ── -->
<div class="modal-overlay" id="vitalsModal">
    <div class="modal-box">
        <div class="modal-header">
            <div class="modal-title">✏️ Edit Vital Signs</div>
            <button class="modal-close" onclick="closeVitalsModal()">✕</button>
        </div>
        <div class="modal-alert" id="modalAlert"></div>
        <div class="vitals-edit-grid">
            <div class="vitals-edit-field" style="grid-column:1/-1;">
                <label>Blood Pressure</label>
                <div class="bp-row">
                    <div>
                        <input type="number" id="v_bp_systolic" placeholder="Systolic" min="60" max="250">
                        <div class="field-unit">Systolic (mmHg)</div>
                    </div>
                    <div>
                        <input type="number" id="v_bp_diastolic" placeholder="Diastolic" min="40" max="160">
                        <div class="field-unit">Diastolic (mmHg)</div>
                    </div>
                </div>
            </div>
            <div class="vitals-edit-field">
                <label>Heart Rate</label>
                <input type="number" id="v_heart_rate" placeholder="e.g. 72" min="30" max="220">
                <div class="field-unit">bpm</div>
            </div>
            <div class="vitals-edit-field">
                <label>Temperature</label>
                <input type="number" id="v_temperature" placeholder="e.g. 98.6" step="0.1" min="90" max="110">
                <div class="field-unit">°C</div>
            </div>
            <div class="vitals-edit-field">
                <label>SpO2</label>
                <input type="number" id="v_spo2" placeholder="e.g. 98" min="70" max="100">
                <div class="field-unit">%</div>
            </div>
            <div class="vitals-edit-field">
                <label>Respiratory Rate</label>
                <input type="number" id="v_resp_rate" placeholder="e.g. 16" min="8" max="60">
                <div class="field-unit">breaths/min</div>
            </div>
            <div class="vitals-edit-field">
                <label>Weight</label>
                <input type="number" id="v_weight" placeholder="e.g. 65.5" step="0.1" min="1" max="300">
                <div class="field-unit">kg</div>
            </div>
            <div class="vitals-edit-field">
                <label>Height</label>
                <input type="number" id="v_height" placeholder="e.g. 170" step="0.1" min="50" max="250">
                <div class="field-unit">cm</div>
            </div>
            <div class="vitals-edit-field" style="grid-column:1/-1;">
                <label>Notes <span style="font-weight:400;text-transform:none;letter-spacing:0;">(optional)</span></label>
                <input type="text" id="v_notes" placeholder="Any additional notes about vitals…" style="font-size:13px;">
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn-modal-save" id="saveVitalsBtn" onclick="saveVitals()">💾 Save Vitals</button>
            <button class="btn-modal-cancel" onclick="closeVitalsModal()">Cancel</button>
        </div>
    </div>
</div>

<script>
let currentPatient   = null;
let medicineCounter  = 0;
let savedDiagnosisId = null;

document.addEventListener('DOMContentLoaded', () => {
    bindEvents();
    const pid = document.getElementById('patientIdInput').value.trim();
    if (pid) loadPatient(pid);
});

function bindEvents() {
    document.getElementById('loadPatientBtn').addEventListener('click', () => {
        const pid = document.getElementById('patientIdInput').value.trim();
        if (pid) loadPatient(pid);
        else showAlert('Please enter a Patient ID.', 'error');
    });
    document.getElementById('patientIdInput').addEventListener('keypress', e => {
        if (e.key === 'Enter') document.getElementById('loadPatientBtn').click();
    });
    document.getElementById('addMedicineBtn').addEventListener('click', addMedicineRow);
    document.getElementById('diagnosisForm').addEventListener('submit', handleSave);
    document.getElementById('savePrintBtn').addEventListener('click', handleSaveAndPrint);
    document.getElementById('resetBtn').addEventListener('click', resetForm);
    document.getElementById('viewHistoryBtn').addEventListener('click', () => {
        if (currentPatient) window.open(`view_history.php?patientId=${currentPatient.patient_id}`, '_blank');
    });
}

function onReviewChange(sel) {
    const wrap = document.getElementById('reviewOtherWrap');
    wrap.classList.toggle('show', sel.value === 'other');
    if (sel.value !== 'other') document.getElementById('reviewOtherInput').value = '';
}

async function loadPatient(patientId) {
    const errEl = document.getElementById('searchError');
    errEl.style.display = 'none';
    ['formSection','patientBanner','vitalsSection'].forEach(id =>
        document.getElementById(id).classList.remove('show'));
    document.getElementById('loadPatientBtn').textContent = '⏳ Loading…';

    try {
        const res  = await fetch(`diagnosis_form.php?action=get_patient&patientId=${encodeURIComponent(patientId)}`);
        const data = await res.json();
        if (data.success) {
            currentPatient = data.patient;
            renderPatientBanner(data.patient);
            renderVitals(data.latestVitals);
            document.getElementById('formSection').classList.add('show');
        } else {
            errEl.textContent   = '❌ ' + (data.error || 'Patient not found.');
            errEl.style.display = 'block';
        }
    } catch (e) {
        errEl.textContent   = '❌ Network error. Please try again.';
        errEl.style.display = 'block';
    }
    document.getElementById('loadPatientBtn').textContent = '🔍 Load Patient';
}

function renderPatientBanner(p) {
    document.getElementById('patientNameLarge').textContent = p.full_name;
    document.getElementById('patientIdBadge').textContent   = p.patient_id;
    document.getElementById('patientAge').textContent       = p.age + ' years';
    document.getElementById('patientGender').textContent    = p.gender;
    document.getElementById('patientMobile').textContent    = p.mobile_no;
    const ps = document.getElementById('patientPhotoSection');
    ps.innerHTML = p.photoUrl
        ? `<img src="${p.photoUrl}" class="patient-photo" alt="photo">`
        : `<div class="patient-avatar">👤</div>`;
    document.getElementById('patientBanner').classList.add('show');
}

function renderVitals(v) {
    if (!v) { document.getElementById('vitalsSection').classList.remove('show'); return; }
    const hasAny = ['bp_systolic','bp_diastolic','heart_rate','temperature','spo2','resp_rate','weight','height','bmi']
        .some(k => v[k] !== null && v[k] !== undefined && v[k] !== '');
    if (!hasAny) { document.getElementById('vitalsSection').classList.remove('show'); return; }
    currentVitals = v;
    if (v.recorded_at) {
        const ts = new Date(v.recorded_at).toLocaleString('en-IN', { day:'numeric', month:'short', year:'numeric', hour:'2-digit', minute:'2-digit' });
        document.getElementById('vitalsTimestamp').textContent = 'Recorded: ' + ts;
    } else {
        document.getElementById('vitalsTimestamp').textContent = '';
    }
    const val = (x) => (x === null || x === undefined || x === '') ? '—' : x;
    const bp = (v.bp_systolic && v.bp_diastolic) ? `${v.bp_systolic}/${v.bp_diastolic}` : '—';
    let bmi = val(v.bmi);
    if ((bmi === '—' || bmi === '') && v.weight && v.height) {
        const h = parseFloat(v.height);
        const w = parseFloat(v.weight);
        if (h > 0 && w > 0) bmi = (w / ((h / 100) ** 2)).toFixed(1);
    }
    const vCard = (label, val, unit) =>
        `<div class="vital-card"><div class="vital-label">${label}</div><div class="vital-value">${val}<span class="vital-unit">${unit}</span></div></div>`;
    document.getElementById('vitalsGrid').innerHTML =
        vCard('Blood Pressure', bp, 'mmHg') +
        vCard('Heart Rate',  val(v.heart_rate),  'bpm') +
        vCard('Temperature', val(v.temperature), '°C')  +
        vCard('SpO2',        val(v.spo2),        '%')   +
        vCard('Resp. Rate',  val(v.resp_rate),   'br/min') +
        vCard('Weight',      val(v.weight),      'kg')  +
        vCard('Height',      val(v.height),      'cm')  +
        vCard('BMI',         bmi,                'kg/m2');
    document.getElementById('vitalsSection').classList.add('show');
}

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
                <option>1 tablet</option><option>2 tablets</option>
                <option>½ tablet</option>
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
        <td><button type="button" class="btn-remove-med" onclick="removeMedRow(${id})">✕</button></td>`;
    tbody.appendChild(row);
    document.getElementById('medicineTable').classList.add('show');
}

function onDosageChange(sel, id) {
    const otherInput = document.getElementById(`dosage-other-${id}`);
    otherInput.style.display = sel.value === 'other' ? 'block' : 'none';
    if (sel.value !== 'other') otherInput.value = '';
}

function onDurationChange(sel, id) {
    const otherInput = document.getElementById(`duration-other-${id}`);
    otherInput.style.display = sel.value === 'other' ? 'block' : 'none';
    if (sel.value !== 'other') otherInput.value = '';
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
        const name     = row.querySelector('.med-name').value.trim();
        const dosageSel = row.querySelector('.med-dosage');
        const dosageOther = row.querySelector('.med-dosage-other');
        const dosage   = dosageSel.value === 'other'
            ? (dosageOther ? dosageOther.value.trim() : '')
            : dosageSel.value;
        const meal         = row.querySelector('.med-meal').value;
        const durationSel   = row.querySelector('.med-duration');
        const durationOther = row.querySelector('.med-duration-other');
        const duration      = durationSel.value === 'other'
            ? (durationOther ? durationOther.value.trim() : '')
            : durationSel.value;
        const timing   = [...row.querySelectorAll('input[type=checkbox]:checked')].map(cb => cb.value);
        if (name) meds.push({ name, dosage, timing, mealInstruction: meal, duration });
    });
    return meds;
}

function getReviewAfterValue() {
    const sel = document.getElementById('reviewAfter');
    if (sel.value === 'other') {
        return document.getElementById('reviewOtherInput').value.trim();
    }
    return sel.value;
}

function buildPayload() {
    return {
        patientId             : currentPatient.patient_id,
        chiefComplaint        : document.getElementById('chiefComplaint').value.trim(),
        clinicalNotes         : document.getElementById('clinicalNotes').value.trim(),
        provisionalDiagnosis  : document.getElementById('provisionalDiagnosis').value.trim(),
        investigationPrescribed: document.getElementById('investigationPrescribed').value.trim(),
        reviewAfter           : getReviewAfterValue(),
        remarks               : document.getElementById('remarks').value.trim(),
        medicines             : collectMedicines()
    };
}

async function handleSave(e) {
    e.preventDefault();
    if (!currentPatient) { showAlert('Load a patient first.', 'error'); return; }
    await saveDiagnosis();
}

async function saveDiagnosis(printAfter = false) {
    const btn = document.getElementById('saveBtn');
    btn.disabled = true; btn.textContent = '⏳ Saving…';
    try {
        const res  = await fetch('diagnosis_form.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(buildPayload())
        });
        const data = await res.json();
        if (data.success) {
            savedDiagnosisId = data.diagnosisId;
            showAlert('✅ Diagnosis saved successfully!', 'success');
            if (printAfter) openPrintWindow(data.diagnosisId);
            setTimeout(() => window.location.href = 'patient_queue.php', 2200);
            return data.diagnosisId;
        } else {
            showAlert('❌ ' + (data.error || 'Failed to save.'), 'error');
            return null;
        }
    } catch (err) {
        showAlert('❌ Network error. Please try again.', 'error');
        return null;
    } finally {
        btn.disabled = false; btn.textContent = '💾 Save Diagnosis';
    }
}

async function handleSaveAndPrint() {
    if (!currentPatient) { showAlert('Load a patient first.', 'error'); return; }
    await saveDiagnosis(true);
}

function openPrintWindow(diagnosisId) {
    window.open(`print_diagnosis.php?diagnosisId=${diagnosisId}`, '_blank', 'width=900,height=750');
}

function resetForm() {
    if (!confirm('Reset the entire form? All entered data will be lost.')) return;
    document.getElementById('diagnosisForm').reset();
    document.getElementById('medicineTableBody').innerHTML = '';
    document.getElementById('medicineTable').classList.remove('show');
    document.getElementById('reviewOtherWrap').classList.remove('show');
    savedDiagnosisId = null;
}

function showAlert(msg, type = 'error') {
    const el = document.getElementById('alertBox');
    el.className = `alert ${type} show`;
    el.textContent = msg;
    el.scrollIntoView({ behavior: 'smooth', block: 'center' });
    if (type === 'error') setTimeout(() => el.classList.remove('show'), 5000);
}// ── VITALS EDIT MODAL ──────────────────────────────────────
let currentVitals = null;

function openVitalsModal() {
    if (!currentPatient) return;
    // Pre-fill with current vitals if available
    if (currentVitals) {
        document.getElementById('v_bp_systolic').value  = currentVitals.bp_systolic  || '';
        document.getElementById('v_bp_diastolic').value = currentVitals.bp_diastolic || '';
        document.getElementById('v_heart_rate').value   = currentVitals.heart_rate   || '';
        document.getElementById('v_temperature').value  = currentVitals.temperature  || '';
        document.getElementById('v_spo2').value         = currentVitals.spo2         || '';
        document.getElementById('v_resp_rate').value    = currentVitals.resp_rate    || '';
        document.getElementById('v_weight').value       = currentVitals.weight       || '';
        document.getElementById('v_height').value       = currentVitals.height       || '';
        document.getElementById('v_notes').value        = currentVitals.notes        || '';
    }
    document.getElementById('modalAlert').className = 'modal-alert';
    document.getElementById('vitalsModal').classList.add('show');
}

function closeVitalsModal() {
    document.getElementById('vitalsModal').classList.remove('show');
}

// Close modal on overlay click
document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('vitalsModal').addEventListener('click', function(e) {
        if (e.target === this) closeVitalsModal();
    });
});

async function saveVitals() {
    if (!currentPatient) return;
    const btn = document.getElementById('saveVitalsBtn');
    btn.disabled = true; btn.textContent = '⏳ Saving…';

    const payload = {
        patientId  : currentPatient.patient_id,
        bp_systolic : document.getElementById('v_bp_systolic').value  || null,
        bp_diastolic: document.getElementById('v_bp_diastolic').value || null,
        heart_rate  : document.getElementById('v_heart_rate').value   || null,
        temperature : document.getElementById('v_temperature').value  || null,
        spo2        : document.getElementById('v_spo2').value         || null,
        resp_rate   : document.getElementById('v_resp_rate').value    || null,
        weight      : document.getElementById('v_weight').value       || null,
        height      : document.getElementById('v_height').value       || null,
        notes       : document.getElementById('v_notes').value.trim() || null
    };

    try {
        const res  = await fetch('diagnosis_form.php?action=update_vitals', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(payload)
        });
        const data = await res.json();
        if (data.success) {
            showModalAlert('✅ Vitals updated successfully!', 'success');
            // Refresh displayed vitals
            currentVitals = { ...currentVitals, ...payload };
            renderVitals(currentVitals);
            setTimeout(closeVitalsModal, 1200);
        } else {
            showModalAlert('❌ ' + (data.error || 'Failed to update vitals.'), 'error');
        }
    } catch (e) {
        showModalAlert('❌ Network error. Please try again.', 'error');
    }
    btn.disabled = false; btn.textContent = '💾 Save Vitals';
}

function showModalAlert(msg, type) {
    const el = document.getElementById('modalAlert');
    el.className = `modal-alert ${type} show`;
    el.textContent = msg;
}
</script>
<script src="../assets/doctor_notifications.js"></script>
</body>
</html>
