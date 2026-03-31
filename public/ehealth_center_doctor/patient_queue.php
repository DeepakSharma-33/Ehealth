<?php
require_once __DIR__ . '/../../config/config.php';
require_role('doctor', 'doctor_login.php');

if (isset($_POST['logout'])) {
    logout_user();
    header('Location: doctor_login.php');
    exit;
}

if (isset($_POST['mark_revisit_completed'])) {
    $patientId = trim((string)($_POST['patient_id'] ?? ''));
    if ($patientId !== '') {
        $db = db();
        $upd = $db->prepare("UPDATE patients SET diagnosis_status = 'completed' WHERE patient_id = ?");
        $upd->bind_param('s', $patientId);
        $upd->execute();
        $upd->close();
    }
    header('Location: patient_queue.php');
    exit;
}

$doctor_id = (string)get_session_id();
$doctor_name = htmlspecialchars(get_session_name());
$db = db();
ensure_patient_revisit_schema($db);

/*
Queue behavior:
1) Show only patients with diagnosis_status='pending'.
2) Show only patients whose current vitals cycle is completed (vitals_recorded=1).
3) Use latest vitals entry for the patient card.
4) Hide patients who currently have an active teleconsult session
   (waiting/accepted/active) for this eHealth doctor.
5) Re-show patients automatically when teleconsult is cancelled/rejected.
6) Show revisited patients
*/
$stmt = $db->prepare(
    "SELECT p.patient_id, p.full_name, p.age, p.gender, p.mobile_no, p.photo_filename,
            COALESCE(p.patient_flag, 'N') AS patient_flag,
            v.bp_systolic, v.bp_diastolic, v.heart_rate, v.temperature, v.spo2, v.weight,
            ts.status AS tele_status, ts.reject_message AS tele_reject_message
     FROM patients p
     INNER JOIN patient_vitals v ON v.id = (
         SELECT id
         FROM patient_vitals
         WHERE patient_id = p.patient_id
         ORDER BY recorded_at DESC
         LIMIT 1
     )
     LEFT JOIN teleconsult_sessions ts ON ts.id = (
         SELECT t2.id
         FROM teleconsult_sessions t2
         WHERE t2.patient_id = p.patient_id
           AND t2.ehealth_doctor_id = ?
         ORDER BY t2.id DESC
         LIMIT 1
     )
     WHERE p.diagnosis_status = 'pending'
       AND COALESCE(p.vitals_recorded, 0) = 1
       AND NOT EXISTS (
           SELECT 1
           FROM teleconsult_sessions ta
           WHERE ta.patient_id = p.patient_id
             AND ta.ehealth_doctor_id = ?
             AND ta.status IN ('waiting','accepted','active')
       )
     ORDER BY p.created_at DESC"
);
$stmt->bind_param('ss', $doctor_id, $doctor_id);
$stmt->execute();
$result = $stmt->get_result();

$patients = [];
while ($row = $result->fetch_assoc()) {
    $patients[] = $row;
}
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Queue - eHealth Center</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', sans-serif; background: #a8e6b0; min-height: 100vh; }

        .navbar {
            background: #2e7d32; height: 60px; padding: 0 32px;
            display: flex; align-items: center; justify-content: space-between;
            box-shadow: 0 2px 10px rgba(0,0,0,0.18);
            position: sticky; top: 0; z-index: 100;
        }
        .navbar-brand { display: flex; align-items: center; gap: 10px; text-decoration: none; }
        .brand-circle {
            width: 36px; height: 36px; border-radius: 50%; background: white;
            display: flex; align-items: center; justify-content: center;
            font-size: 15px; color: #2e7d32; font-weight: 800;
        }
        .brand-name { color: white; font-size: 19px; font-weight: 800; letter-spacing: 1.4px; }
        .nav-right { display: flex; align-items: center; gap: 20px; }
        .nav-link { color: rgba(255,255,255,0.92); text-decoration: none; font-size: 13px; font-weight: 600; }
        .btn-logout {
            border: 2px solid white; background: transparent; color: white;
            border-radius: 18px; padding: 6px 16px; font-size: 12px; font-weight: 700; cursor: pointer;
        }

        .page-header {
            background: white; border-bottom: 1px solid #e5e7eb; padding: 18px 30px;
            display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap;
        }
        .page-header h1 { font-size: 24px; color: #1b5e20; }
        .queue-badge {
            background: #2e7d32; color: white; border-radius: 18px;
            padding: 6px 14px; font-size: 13px; font-weight: 700;
        }

        .controls {
            padding: 16px 30px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap;
        }
        .search-input {
            flex: 1; min-width: 220px; border: 2px solid #c8e0ca; border-radius: 10px;
            padding: 10px 14px; font-size: 14px; outline: none;
        }
        .search-input:focus { border-color: #2e7d32; }
        .btn-refresh {
            border: none; border-radius: 10px; background: #2e7d32; color: white;
            padding: 10px 16px; font-size: 13px; font-weight: 700; cursor: pointer;
        }

        .patients-grid {
            padding: 0 30px 40px;
            display: grid; gap: 16px;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
        }
        .patient-card {
            background: white; border-radius: 16px; padding: 20px;
            box-shadow: 0 3px 14px rgba(0,0,0,0.09);
            border: 2px solid transparent; cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s, border-color 0.2s;
        }
        .patient-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 24px rgba(0,0,0,0.13);
            border-color: #2e7d32;
        }
        .patient-card.old-patient {
            border-color: #ffb74d;
            box-shadow: 0 3px 16px rgba(251, 140, 0, 0.17);
        }
        .patient-card.old-patient:hover {
            border-color: #ef6c00;
        }

        .patient-header { display: flex; align-items: center; gap: 12px; margin-bottom: 12px; }
        .patient-photo {
            width: 52px; height: 52px; border-radius: 50%; object-fit: cover; border: 2px solid #e5e7eb;
        }
        .patient-photo-placeholder {
            width: 52px; height: 52px; border-radius: 50%; background: #e8f5e9;
            display: flex; align-items: center; justify-content: center; border: 2px solid #c8e6c9;
            font-size: 22px;
        }
        .patient-name { font-size: 16px; font-weight: 800; color: #111827; }
        .patient-id-badge {
            display: inline-block; margin-top: 3px;
            background: #e8f5e9; color: #2e7d32; border-radius: 10px; padding: 2px 10px;
            font-size: 11px; font-weight: 700;
        }

        .vitals-badge {
            margin-bottom: 10px;
            display: inline-block; border-radius: 10px; padding: 4px 10px;
            font-size: 11px; font-weight: 700;
        }
        .vitals-yes { background: #e8f5e9; color: #2e7d32; }
        .vitals-no { background: #fff3e0; color: #e65100; }
        .revisited-badge {
            margin: -2px 0 10px;
            display: inline-block;
            border-radius: 10px;
            padding: 4px 10px;
            background: #fff3e0;
            color: #ef6c00;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.3px;
        }

        .tele-reject-note {
            margin-bottom: 10px;
            border: 1px solid #fecdd3; background: #fff1f2; color: #9f1239;
            border-radius: 10px; padding: 9px 11px; font-size: 12px; line-height: 1.45;
        }
        .tele-reject-note b { display: block; margin-bottom: 2px; }

        .details-grid {
            margin-bottom: 14px;
            display: grid; grid-template-columns: 1fr 1fr; gap: 6px 10px;
            font-size: 12px; color: #4b5563;
        }
        .details-grid span { color: #111827; font-weight: 700; }

        .btn-diagnose {
            width: 100%; border: none; border-radius: 10px;
            background: linear-gradient(135deg, #2e7d32, #43a047); color: white;
            padding: 11px; font-size: 13px; font-weight: 800; cursor: pointer;
        }
        .btn-retry { background: linear-gradient(135deg, #dc2626, #ef4444); }
        .btn-revisit {
            width: 100%;
            border: none; border-radius: 10px;
            background: linear-gradient(135deg, #ef6c00, #f59e0b); color: white;
            padding: 10px; font-size: 12px; font-weight: 800; cursor: pointer;
            margin-top: 8px;
        }

        .empty-state {
            grid-column: 1 / -1;
            background: white; border-radius: 16px; padding: 50px 20px; text-align: center;
            box-shadow: 0 3px 14px rgba(0,0,0,0.09);
        }
        .empty-state h3 { font-size: 22px; color: #4b5563; margin-bottom: 8px; }
        .empty-state p { color: #6b7280; font-size: 14px; }

        @media (max-width: 600px) {
            .navbar { padding: 0 14px; }
            .page-header, .controls { padding-left: 14px; padding-right: 14px; }
            .patients-grid { padding-left: 14px; padding-right: 14px; }
        }
    </style>
</head>
<body>

<nav class="navbar">
    <a href="<?= htmlspecialchars(app_base_url()) ?>/" class="navbar-brand">
        <span style="display:flex;align-items:center;gap:14px;line-height:1;"><img src="<?= htmlspecialchars(app_base_url()) ?>/public/assets/SRMS_TRUST_LOGO.png" alt="SRMST Trust Logo" style="height:48px;width:auto;display:block;object-fit:contain;filter:drop-shadow(0 1px 3px rgba(0,0,0,.35));"><span style="font-size:22px;font-weight:800;letter-spacing:1px;color:#fff;text-shadow:0 1px 2px rgba(0,0,0,.35);white-space:nowrap;">SRMS EHEALTH</span></span>
    </a>
    <div class="nav-right">
        <a class="nav-link" href="doctor_dashboard.php">Back to Dashboard</a>
        <a class="nav-link" href="<?= htmlspecialchars(app_base_url()) ?>/about.php">ABOUT</a>
        <a class="nav-link" href="<?= htmlspecialchars(app_base_url()) ?>/contact.php">CONTACT</a>
        <form method="POST" style="display:inline">
            <button type="submit" name="logout" class="btn-logout">LOGOUT</button>
        </form>
    </div>
</nav>

<div class="page-header">
    <h1>Patient Queue</h1>
    <div class="queue-badge" id="queueCount"><?= count($patients) ?> Patients</div>
</div>

<div class="controls">
    <input type="text" class="search-input" id="searchInput" placeholder="Search by name, ID or mobile..." oninput="filterPatients()">
    <button class="btn-refresh" type="button" onclick="window.location.reload()">Refresh</button>
</div>

<div class="patients-grid" id="patientsGrid">
<?php if (empty($patients)): ?>
    <div class="empty-state">
        <h3>No Pending Patients</h3>
        <p>No patients are currently available in your queue.</p>
    </div>
<?php else: ?>
    <?php foreach ($patients as $p):
        $bp = ($p['bp_systolic'] && $p['bp_diastolic']) ? ($p['bp_systolic'].'/'.$p['bp_diastolic']) : 'N/A';
        $temp = $p['temperature'] ? ($p['temperature'].' C') : 'N/A';
        $hr = $p['heart_rate'] ? ($p['heart_rate'].' bpm') : 'N/A';
        $photo = patient_photo_url($p['photo_filename'] ?? null);
        $pid = htmlspecialchars($p['patient_id']);
        $name = htmlspecialchars($p['full_name']);
        $teleStatus = strtolower(trim((string)($p['tele_status'] ?? '')));
        $isTeleRejected = in_array($teleStatus, ['cancelled', 'cancled', 'canceled'], true);
        $teleRejectMsg = trim((string)($p['tele_reject_message'] ?? ''));
        $isRevisited = (($p['patient_flag'] ?? 'N') === 'O');
    ?>
    <div class="patient-card <?= $isRevisited ? 'old-patient' : '' ?>"
         onclick="window.location.href='consultation_select.php?patientId=<?= $pid ?>'"
         data-name="<?= strtolower($name) ?>"
         data-id="<?= strtolower($pid) ?>"
         data-mobile="<?= htmlspecialchars((string)$p['mobile_no']) ?>">

        <div class="patient-header">
            <?php if ($photo): ?>
                <img src="<?= $photo ?>" alt="<?= $name ?>" class="patient-photo">
            <?php else: ?>
                <div class="patient-photo-placeholder">P</div>
            <?php endif; ?>
            <div>
                <div class="patient-name"><?= $name ?></div>
                <span class="patient-id-badge"><?= $pid ?></span>
            </div>
        </div>

        <div class="vitals-badge vitals-yes">Vitals Recorded</div>
        <?php if ($isRevisited): ?>
        <div class="revisited-badge">Revisited Patient</div>
        <?php endif; ?>

        <?php if ($isTeleRejected): ?>
        <div class="tele-reject-note">
            <b>Teleconsultation rejected - try again.</b>
            <?= $teleRejectMsg ? htmlspecialchars($teleRejectMsg) : 'Please open consultation and select teleconsultation again.' ?>
        </div>
        <?php endif; ?>

        <div class="details-grid">
            <div><span>Age:</span> <?= htmlspecialchars((string)$p['age']) ?> yrs</div>
            <div><span>Gender:</span> <?= htmlspecialchars((string)$p['gender']) ?></div>
            <div><span>Mobile:</span> <?= htmlspecialchars((string)$p['mobile_no']) ?></div>
            <div><span>BP:</span> <?= htmlspecialchars($bp) ?></div>
            <div><span>Temp:</span> <?= htmlspecialchars($temp) ?></div>
            <div><span>HR:</span> <?= htmlspecialchars($hr) ?></div>
        </div>

        <button class="btn-diagnose <?= $isTeleRejected ? 'btn-retry' : '' ?>"
                onclick="event.stopPropagation(); window.location.href='consultation_select.php?patientId=<?= $pid ?>'">
            <?= $isTeleRejected ? 'Teleconsultation Rejected - Try Again ->' : 'Start Diagnosis ->' ?>
        </button>
        <?php if ($isTeleRejected): ?>
        <form method="POST" onsubmit="event.stopPropagation();" style="margin:0;">
            <input type="hidden" name="patient_id" value="<?= $pid ?>">
            <button class="btn-revisit" type="submit" name="mark_revisit_completed" onclick="event.stopPropagation();">
                Ask to Revisit Again ->
            </button>
        </form>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
<?php endif; ?>
</div>

<script>
function filterPatients() {
    const q = document.getElementById('searchInput').value.toLowerCase().trim();
    const cards = document.querySelectorAll('.patient-card');
    let visible = 0;
    cards.forEach((card) => {
        const ok = !q || card.dataset.name.includes(q) || card.dataset.id.includes(q) || card.dataset.mobile.includes(q);
        card.style.display = ok ? '' : 'none';
        if (ok) visible++;
    });
    document.getElementById('queueCount').textContent = visible + ' Patients';
}

setTimeout(() => window.location.reload(), 10000);
</script>
<script src="../assets/doctor_notifications.js"></script>
</body>
</html>
