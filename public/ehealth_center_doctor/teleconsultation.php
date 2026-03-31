<?php
require_once __DIR__ . '/../../config/config.php';
require_role('doctor', 'doctor_login.php');

$doctor_id = get_session_id();

if (isset($_POST['logout'])) {
    logout_user();
    header('Location: doctor_login.php');
    exit;
}

// ── AJAX: save teleconsult pre-notes ────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    $patientId = trim($body['patientId'] ?? '');
    $teleconsultReason = trim((string)($body['teleconsultReason'] ?? ''));

    if (!$patientId) { echo json_encode(['success'=>false,'error'=>'Patient ID required.']); exit; }
    if ($teleconsultReason === '') { echo json_encode(['success'=>false,'error'=>'Telemedicine reason is required.']); exit; }

    $db  = db();
    $chk = $db->prepare('SELECT patient_id FROM patients WHERE patient_id = ? LIMIT 1');
    $chk->bind_param('s', $patientId);
    $chk->execute();
    if ($chk->get_result()->num_rows === 0) { echo json_encode(['success'=>false,'error'=>'Patient not found.']); exit; }
    $chk->close();

    $db->begin_transaction();
    try {
        // Save into diagnosis_records with teleconsultation_recommended = 1
        // Fields not relevant to teleconsult are saved as empty
        $ins = $db->prepare(
            'INSERT INTO diagnosis_records
             (patient_id, doctor_id, chief_complaint, clinical_notes, provisional_diagnosis,
              investigation_prescribed, review_after, teleconsultation_recommended,
              teleconsultation_reason, remarks, diagnosis_date)
             VALUES (?,?,?,?,?,?,?,?,?,?,NOW())'
        );
        $provisionalDiagnosis    = '';
        $investigationPrescribed = '';
        $reviewAfter             = '';
        $teleconsultRecommended  = 1;
        $chiefComplaint = $teleconsultReason;
        $clinicalNotes = '';
        $remarks = '';

        $ins->bind_param('sssssssiss',
            $patientId, $doctor_id, $chiefComplaint, $clinicalNotes,
            $provisionalDiagnosis, $investigationPrescribed, $reviewAfter,
            $teleconsultRecommended, $teleconsultReason, $remarks
        );
        $ins->execute();
        $diagnosisId = $db->insert_id;
        $ins->close();

        // Update patient diagnosis_status to completed
        $upd2 = $db->prepare("UPDATE patients SET diagnosis_status = 'completed' WHERE patient_id = ?");
        $upd2->bind_param('s', $patientId);
        $upd2->execute();
        if ($db->affected_rows === 0) {
            // Column might be NULL — try forcing it
            $db->query("ALTER TABLE patients MODIFY COLUMN diagnosis_status ENUM('pending','completed') DEFAULT 'pending'");
            $db->query("UPDATE patients SET diagnosis_status = 'completed' WHERE patient_id = '".$db->real_escape_string($patientId)."'");
        }
        $upd2->close();

        $db->commit();
        echo json_encode(['success'=>true,'diagnosisId'=>$diagnosisId,'patientId'=>$patientId]);
    } catch (Exception $e) {
        $db->rollback();
        echo json_encode(['success'=>false,'error'=>'DB error: '.$e->getMessage()]);
    }
    exit;
}

$patient_id = htmlspecialchars(trim($_GET['patientId'] ?? ''));
if (!$patient_id) {
    header('Location: patient_queue.php');
    exit;
}

$db   = db();
$stmt = $db->prepare(
    "SELECT p.patient_id, p.full_name, p.age, p.gender, p.mobile_no, p.photo_filename,
            v.bp_systolic, v.bp_diastolic, v.heart_rate, v.temperature, v.spo2, v.weight, v.recorded_at
     FROM patients p
     LEFT JOIN patient_vitals v ON v.id = (
         SELECT id FROM patient_vitals WHERE patient_id = p.patient_id ORDER BY recorded_at DESC LIMIT 1
     )
     WHERE p.patient_id = ?"
);
$stmt->bind_param('s', $patient_id);
$stmt->execute();
$patient = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$patient) {
    header('Location: patient_queue.php');
    exit;
}

$selectedTeleDoctorId = trim((string)($_GET['doctorId'] ?? ''));
$selectedTeleDoctorName = trim((string)($_GET['doctorName'] ?? ''));
if ($selectedTeleDoctorId !== '') {
    $docPickStmt = $db->prepare('SELECT full_name FROM telestudio_doctors WHERE doctor_id = ? LIMIT 1');
    if ($docPickStmt) {
        $docPickStmt->bind_param('s', $selectedTeleDoctorId);
        $docPickStmt->execute();
        $pickedRow = $docPickStmt->get_result()->fetch_assoc();
        $docPickStmt->close();
        if ($pickedRow && trim((string)($pickedRow['full_name'] ?? '')) !== '') {
            $selectedTeleDoctorName = trim((string)$pickedRow['full_name']);
        }
    }
}


$name    = htmlspecialchars($patient['full_name']);
$age     = htmlspecialchars($patient['age']);
$gender  = htmlspecialchars($patient['gender']);
$mobile  = htmlspecialchars($patient['mobile_no']);
$photo   = patient_photo_url($patient['photo_filename'] ?? null);
$bp      = ($patient['bp_systolic'] && $patient['bp_diastolic']) ? $patient['bp_systolic'].'/'.$patient['bp_diastolic'] : '—';
$hr      = $patient['heart_rate']  ?: '—';
$temp    = $patient['temperature'] ?: '—';
$spo2    = $patient['spo2']        ?: '—';
$weight  = $patient['weight']      ?: '—';
$hasVitals = !empty($patient['bp_systolic']) || !empty($patient['heart_rate']) || !empty($patient['temperature']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teleconsultation — eHealth Center</title>
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
            --teal:     #00897b;
            --teal-light:#e0f2f1;
            --teal-mid: #00695c;
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
                radial-gradient(ellipse 50% 40% at 95% 100%, rgba(0,137,123,0.1) 0%, transparent 55%);
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
        .page-wrap { max-width: 800px; margin: 0 auto; padding: 36px 24px 60px; }

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

        /* ── PATIENT BANNER ── */
        .patient-banner {
            background: white; border-radius: 16px; padding: 22px 28px;
            margin-bottom: 20px; box-shadow: 0 3px 14px rgba(0,0,0,0.08);
            display: flex; align-items: center; gap: 20px; flex-wrap: wrap;
            border-left: 5px solid var(--teal);
            animation: slideDown 0.4s cubic-bezier(0.22,1,0.36,1) both;
        }
        @keyframes slideDown { from { opacity:0; transform:translateY(-14px); } to { opacity:1; transform:translateY(0); } }
        .patient-photo { width: 64px; height: 64px; border-radius: 50%; object-fit: cover; border: 3px solid var(--teal-light); flex-shrink: 0; }
        .patient-avatar { width: 64px; height: 64px; border-radius: 50%; background: var(--teal-light); display: flex; align-items: center; justify-content: center; font-size: 30px; border: 3px solid #b2dfdb; flex-shrink: 0; }
        .banner-info { flex: 1; }
        .banner-name { font-size: 20px; font-weight: 800; color: var(--text-dark); }
        .banner-id { font-size: 12px; font-weight: 700; background: var(--teal-light); color: var(--teal); padding: 3px 12px; border-radius: 20px; display: inline-block; margin: 4px 0 8px; }
        .banner-meta { display: flex; gap: 20px; flex-wrap: wrap; font-size: 13px; color: var(--text-soft); }
        .banner-meta b { color: var(--text-mid); font-weight: 600; }
        .btn-history { margin-left: auto; padding: 9px 18px; background: white; color: var(--teal); border: 2px solid var(--teal); border-radius: 10px; font-size: 13px; font-weight: 700; cursor: pointer; font-family: 'Sora', sans-serif; transition: all 0.2s; white-space: nowrap; }
        .btn-history:hover { background: var(--teal); color: white; }

        /* ── VITALS STRIP ── */
        .vitals-strip {
            background: white; border-radius: 16px; padding: 20px 28px;
            margin-bottom: 20px; box-shadow: 0 3px 14px rgba(0,0,0,0.08);
            animation: slideDown 0.4s 0.08s cubic-bezier(0.22,1,0.36,1) both;
        }
        .vitals-strip-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }
        .vitals-strip-title { font-size: 14px; font-weight: 700; color: var(--teal); display: flex; align-items: center; gap: 8px; }
        .vitals-strip-ts { font-size: 12px; color: var(--text-soft); font-family: 'JetBrains Mono', monospace; }
        .vitals-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 12px; }
        .vital-card { background: var(--teal-light); border: 1px solid #b2dfdb; border-radius: 12px; padding: 14px 10px; text-align: center; }
        .vital-label { font-size: 10.5px; font-weight: 700; color: var(--teal-mid); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px; }
        .vital-value { font-size: 18px; font-weight: 800; color: var(--teal); font-family: 'JetBrains Mono', monospace; }
        .vital-unit { font-size: 10px; font-weight: 600; color: #80cbc4; margin-left: 2px; }

        /* ── NO VITALS NOTICE ── */
        .no-vitals-notice {
            background: #fff8e1; border: 1px solid #ffe082; border-radius: 12px;
            padding: 13px 18px; margin-bottom: 20px; font-size: 13px; color: #795548;
            display: flex; align-items: center; gap: 10px;
        }

        /* ── FORM CARD ── */
        .form-card {
            background: white; border-radius: 16px; padding: 32px 36px;
            box-shadow: 0 3px 14px rgba(0,0,0,0.08);
            animation: slideDown 0.4s 0.14s cubic-bezier(0.22,1,0.36,1) both;
        }

        /* Alert */
        .alert { display: none; padding: 13px 18px; border-radius: 10px; margin-bottom: 24px; font-size: 13px; font-weight: 600; }
        .alert.show { display: block; }
        .alert.success { background: #e0f2f1; color: var(--teal-mid); border: 1px solid #b2dfdb; }
        .alert.error   { background: var(--red-pale); color: var(--red); border: 1px solid #ffcdd2; }

        /* Section headers */
        .form-section-title {
            font-size: 11px; font-weight: 800; letter-spacing: 2px;
            text-transform: uppercase; color: var(--teal);
            border-bottom: 2px solid var(--teal-light);
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
            min-height: 100px; resize: vertical;
            color: var(--text-dark); background: #fafcfa;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .form-control:focus { outline: none; border-color: var(--teal); box-shadow: 0 0 0 3px rgba(0,137,123,0.08); background: white; }
        .form-control::placeholder { color: #aec4ae; }

        .selected-doctor {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            background: #f2faf3;
            border: 1px solid #c7dfca;
            border-radius: 12px;
            padding: 12px 14px;
            margin-bottom: 16px;
            font-size: 13px;
        }
        .selected-doctor strong { color: var(--teal-mid); }


        /* ── START TELECONSULT BUTTON ── */
        .teleconsult-action {
            margin-top: 32px;
            background: linear-gradient(135deg, #e0f2f1, #f1f8f2);
            border: 2px solid #b2dfdb;
            border-radius: 16px;
            padding: 24px 28px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            flex-wrap: wrap;
        }
        .teleconsult-action-text h3 { font-size: 16px; font-weight: 800; color: var(--teal-mid); margin-bottom: 4px; }
        .teleconsult-action-text p  { font-size: 13px; color: var(--text-soft); }

        .btn-start-teleconsult {
            padding: 14px 32px;
            background: linear-gradient(135deg, var(--teal), #26a69a);
            color: white; border: none; border-radius: 12px;
            font-size: 15px; font-weight: 800;
            cursor: pointer; font-family: 'Sora', sans-serif;
            display: flex; align-items: center; gap: 10px;
            transition: transform 0.2s, box-shadow 0.2s;
            animation: tele-pulse 2.5s infinite;
            white-space: nowrap;
        }
        @keyframes tele-pulse {
            0%,100% { box-shadow: 0 0 0 0 rgba(0,137,123,0.4); }
            50%      { box-shadow: 0 0 0 10px rgba(0,137,123,0); }
        }
        .btn-start-teleconsult:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(0,137,123,0.35); animation: none; }
        .btn-start-teleconsult:active { transform: translateY(0); }
        .btn-start-teleconsult:disabled { background: #b0bec5; cursor: not-allowed; animation: none; box-shadow: none; }

        /* Save & back row */
        .form-buttons-row { display: flex; gap: 12px; margin-top: 20px; flex-wrap: wrap; }
        .btn-save {
            flex: 1; min-width: 140px; padding: 13px;
            background: linear-gradient(135deg, var(--g-mid), var(--g-light));
            color: white; border: none; border-radius: 12px;
            font-size: 14px; font-weight: 700; cursor: pointer;
            font-family: 'Sora', sans-serif; transition: opacity 0.2s;
        }
        .btn-save:hover { opacity: 0.88; }
        .btn-back-queue {
            padding: 13px 22px;
            background: white; color: var(--text-mid);
            border: 2px solid var(--g-border); border-radius: 12px;
            font-size: 14px; font-weight: 700; cursor: pointer;
            font-family: 'Sora', sans-serif; transition: all 0.2s;
            text-decoration: none; display: inline-flex; align-items: center;
        }
        .btn-back-queue:hover { border-color: var(--text-mid); color: var(--text-dark); }

        @media (max-width: 640px) {
            .navbar { padding: 0 16px; }
            .page-wrap { padding: 24px 14px 50px; }
            .form-card { padding: 22px 18px; }
            .vitals-grid { grid-template-columns: repeat(3, 1fr); }
            .teleconsult-action { flex-direction: column; text-align: center; }
            .btn-start-teleconsult { width: 100%; justify-content: center; }
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
        <a href="consultation_select.php?patientId=<?= $patient_id ?>">Select Consultation</a>
        <span>›</span>
        Teleconsultation
    </div>

    <!-- Page Title -->
    <div class="page-title-row">
        <div class="page-title-icon">📹</div>
        <div class="page-title">
            <h1>Teleconsultation</h1>
            <p>Remote consultation — enter reason, then start</p>
        </div>
    </div>

    <!-- Patient Banner -->
    <div class="patient-banner">
        <?php if ($photo): ?>
            <img src="<?= $photo ?>" alt="<?= $name ?>" class="patient-photo">
        <?php else: ?>
            <div class="patient-avatar">👤</div>
        <?php endif; ?>
        <div class="banner-info">
            <div class="banner-name"><?= $name ?></div>
            <div class="banner-id"><?= $patient_id ?></div>
            <div class="banner-meta">
                <span><b>Age:</b> <?= $age ?> yrs</span>
                <span><b>Gender:</b> <?= $gender ?></span>
                <span><b>Mobile:</b> <?= $mobile ?></span>
            </div>
        </div>
        <button class="btn-history" onclick="window.open('view_history.php?patientId=<?= $patient_id ?>','_blank')">📋 History</button>
    </div>

    <!-- Vitals Strip -->
    <?php if ($hasVitals): ?>
    <div class="vitals-strip">
        <div class="vitals-strip-header">
            <div class="vitals-strip-title">❤️ Latest Vital Signs</div>
            <?php if ($patient['recorded_at']): ?>
            <div class="vitals-strip-ts">
                Recorded: <?= date('d M Y, H:i', strtotime($patient['recorded_at'])) ?>
            </div>
            <?php endif; ?>
        </div>
        <div class="vitals-grid">
            <div class="vital-card"><div class="vital-label">Blood Pressure</div><div class="vital-value"><?= $bp ?><span class="vital-unit">mmHg</span></div></div>
            <div class="vital-card"><div class="vital-label">Heart Rate</div><div class="vital-value"><?= $hr ?><span class="vital-unit">bpm</span></div></div>
            <div class="vital-card"><div class="vital-label">Temperature</div><div class="vital-value"><?= $temp ?><span class="vital-unit">°C</span></div></div>
            <div class="vital-card"><div class="vital-label">SpO2</div><div class="vital-value"><?= $spo2 ?><span class="vital-unit">%</span></div></div>
            <div class="vital-card"><div class="vital-label">Weight</div><div class="vital-value"><?= $weight ?><span class="vital-unit">kg</span></div></div>
        </div>
    </div>
    <?php else: ?>
    <div class="no-vitals-notice">⚠️ No vitals recorded yet for this patient.</div>
    <?php endif; ?>

    <!-- Notes Form -->
    <div class="form-card">
        <div class="alert" id="alertBox"></div>

        <div class="form-section-title">Telemedicine Reason</div>
        <div class="form-group">
            <label class="form-label">Reason <span style="color:var(--red)">*</span></label>
            <textarea class="form-control" id="teleconsultReason" style="min-height:90px;"
                placeholder="Enter reason for telemedicine consultation..." required></textarea>
        </div>

        <div class="form-section-title">TeleStudio Doctor</div>
        <div class="selected-doctor">
            <div>
                <?php if ($selectedTeleDoctorId !== ''): ?>
                    <div><strong><?= htmlspecialchars($selectedTeleDoctorName !== '' ? $selectedTeleDoctorName : $selectedTeleDoctorId) ?></strong></div>
                    <div style="font-size:11px;color:var(--text-soft);">Doctor ID: <?= htmlspecialchars($selectedTeleDoctorId) ?></div>
                <?php else: ?>
                    <div><strong>No TeleStudio doctor selected.</strong></div>
                    <div style="font-size:11px;color:var(--text-soft);">Please select doctor first.</div>
                <?php endif; ?>
            </div>
            <?php if ($selectedTeleDoctorId === ''): ?>
                <a href="telestudio_doctors_available.php?patientId=<?= urlencode($patient_id) ?>" class="btn-back-queue" style="padding:8px 12px;font-size:12px;">Choose Doctor</a>
            <?php endif; ?>
        </div>

        <!-- Start Teleconsultation -->
        <div class="teleconsult-action">
            <div class="teleconsult-action-text">
                <h3>📹 Ready to Start?</h3>
                <p>Reason will be saved automatically when you start the session.</p>
            </div>
            <button type="button" class="btn-start-teleconsult" id="startBtn" onclick="handleStartTeleconsult()">
                📹 Start Teleconsultation
            </button>
        </div>

        <div class="form-buttons-row">
            <a href="patient_queue.php" class="btn-back-queue">← Back to Queue</a>
        </div>

    </div>

</div>

<script>
const PATIENT_ID = <?= json_encode($patient_id) ?>;
const PATIENT_NAME = <?= json_encode((string)$patient['full_name']) ?>;
const SELECTED_TELE_DOCTOR_ID = <?= json_encode($selectedTeleDoctorId) ?>;
const SELECTED_TELE_DOCTOR_NAME = <?= json_encode($selectedTeleDoctorName) ?>;
let isSaving = false;

async function saveNotes() {
    const reason = document.getElementById('teleconsultReason').value.trim();
    if (!reason) {
        showAlert('❌ Telemedicine reason is required.', 'error');
        return { success: false, error: 'Telemedicine reason is required.' };
    }

    const payload = {
        patientId: PATIENT_ID,
        teleconsultReason: reason
    };

    const res  = await fetch('teleconsultation.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify(payload)
    });
    return await res.json();
}

async function handleStartTeleconsult() {
    if (!SELECTED_TELE_DOCTOR_ID) {
        showAlert('❌ Please select TeleStudio doctor first.', 'error');
        return;
    }
    if (isSaving) return;
    isSaving = true;
    const btn = document.getElementById('startBtn');
    btn.disabled = true;
    btn.innerHTML = '⏳ Saving & Starting…';

    try {
        const data = await saveNotes();
        if (!data.success) {
            showAlert('❌ ' + (data.error || 'Failed to save reason.'), 'error');
            btn.disabled = false;
            btn.innerHTML = '📹 Start Teleconsultation';
            isSaving = false;
            return;
        }

        const teleconsultReason = document.getElementById('teleconsultReason').value.trim();

        const createRes = await fetch('../telestudio_doctor/teleconsult_actions.php?action=create_session', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                patientId: PATIENT_ID,
                patientName: PATIENT_NAME,
                telestudioDoctorId: SELECTED_TELE_DOCTOR_ID,
                telestudioName: SELECTED_TELE_DOCTOR_NAME,
                teleconsultReason,
                chiefComplaint: teleconsultReason,
                clinicalNotes: '',
                remarks: '',
                diagnosisId: data.diagnosisId || 0
            })
        });

        const createData = await createRes.json();
        if (!createData.success) {
            showAlert('❌ ' + (createData.error || 'Failed to create teleconsult session.'), 'error');
            btn.disabled = false;
            btn.innerHTML = '📹 Start Teleconsultation';
            isSaving = false;
            return;
        }

        showAlert('✅ Teleconsultation started. Opening waiting room…', 'success');
        setTimeout(() => {
            window.location.href = `teleconsult_waiting.php?sessionId=${createData.sessionId}`;
        }, 700);
    } catch (e) {
        showAlert('❌ Network error. Please try again.', 'error');
        btn.disabled = false;
        btn.innerHTML = '📹 Start Teleconsultation';
    }
    isSaving = false;
}

function showAlert(msg, type = 'error') {
    const el = document.getElementById('alertBox');
    el.className = `alert ${type} show`;
    el.textContent = msg;
    el.scrollIntoView({ behavior: 'smooth', block: 'center' });
    if (type === 'error') setTimeout(() => el.classList.remove('show'), 5000);
}

</script>
<script src="../assets/doctor_notifications.js"></script>
</body>
</html>
