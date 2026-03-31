<?php
require_once __DIR__ . '/../../config/config.php';
require_role('doctor', 'doctor_login.php');

if (isset($_POST['logout'])) {
    logout_user();
    header('Location: doctor_login.php');
    exit;
}

$patient_id = isset($_GET['patientId']) ? htmlspecialchars($_GET['patientId']) : '';
if (!$patient_id) {
    header('Location: patient_queue.php');
    exit;
}

$db = db();
$stmt = $db->prepare(
    "SELECT p.patient_id, p.full_name, p.age, p.gender, p.mobile_no, p.photo_filename, p.vitals_recorded,
            v.bp_systolic, v.bp_diastolic, v.heart_rate, v.temperature, v.spo2, v.weight
     FROM patients p
     LEFT JOIN patient_vitals v ON v.id = (
         SELECT id FROM patient_vitals WHERE patient_id = p.patient_id ORDER BY recorded_at DESC LIMIT 1
     )
     WHERE p.patient_id = ?"
);
$stmt->bind_param('s', $patient_id);
$stmt->execute();
$result = $stmt->get_result();
$patient = $result->fetch_assoc();

if (!$patient) {
    header('Location: patient_queue.php');
    exit;
}

$doctor_name = htmlspecialchars(get_session_name());
$name   = htmlspecialchars($patient['full_name']);
$age    = htmlspecialchars($patient['age']);
$gender = htmlspecialchars($patient['gender']);
$photo  = patient_photo_url($patient['photo_filename'] ?? null);
$bp     = ($patient['bp_systolic'] && $patient['bp_diastolic']) ? $patient['bp_systolic'].'/'.$patient['bp_diastolic'].' mmHg' : 'N/A';
$hr     = $patient['heart_rate'] ? $patient['heart_rate'].' bpm' : 'N/A';
$temp   = $patient['temperature'] ? $patient['temperature'].'°C' : 'N/A';
$spo2   = $patient['spo2'] ? $patient['spo2'].'%' : 'N/A';
$hasVitals = !empty($patient['bp_systolic']) || !empty($patient['heart_rate']) || !empty($patient['temperature']) || !empty($patient['spo2']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Select Consultation Type — eHealth Center</title>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;600;700;800&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --green-dark:  #1b5e20;
            --green-mid:   #2e7d32;
            --green-light: #43a047;
            --green-pale:  #e8f5e9;
            --green-mist:  #f1f8f2;
            --teal:        #00897b;
            --teal-light:  #e0f2f1;
            --text-dark:   #0d1f12;
            --text-mid:    #3a4f3c;
            --text-soft:   #6b7f6d;
            --border:      #c8e0ca;
            --white:       #ffffff;
            --shadow-sm:   0 2px 8px rgba(30,80,30,0.08);
            --shadow-md:   0 8px 32px rgba(30,80,30,0.12);
            --shadow-lg:   0 20px 60px rgba(30,80,30,0.18);
        }

        body {
            font-family: 'Sora', sans-serif;
            background: var(--green-mist);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            background-image:
                radial-gradient(ellipse 80% 60% at 10% 0%, rgba(46,125,50,0.08) 0%, transparent 60%),
                radial-gradient(ellipse 60% 50% at 90% 100%, rgba(0,137,123,0.06) 0%, transparent 60%);
        }

        /* ── NAVBAR ── */
        .navbar {
            background: var(--green-dark);
            height: 60px;
            padding: 0 36px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 2px 12px rgba(0,0,0,0.22);
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .navbar-brand { display: flex; align-items: center; gap: 10px; text-decoration: none; }
        .brand-circle {
            width: 36px; height: 36px;
            background: white; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 17px; color: var(--green-dark); font-weight: 800;
        }
        .brand-name { font-size: 18px; font-weight: 800; letter-spacing: 2px; color: white; }
        .nav-right { display: flex; align-items: center; gap: 22px; }
        .nav-link { color: rgba(255,255,255,0.85); text-decoration: none; font-size: 13px; font-weight: 500; transition: color 0.2s; }
        .nav-link:hover { color: white; }
        .btn-logout {
            border: 2px solid rgba(255,255,255,0.6);
            background: transparent; color: white;
            padding: 5px 16px; border-radius: 20px;
            font-size: 12px; font-weight: 700;
            cursor: pointer; font-family: inherit;
            letter-spacing: 0.5px;
            transition: all 0.2s;
        }
        .btn-logout:hover { background: white; color: var(--green-dark); border-color: white; }

        /* ── MAIN ── */
        .main {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 48px 24px 60px;
        }

        /* ── BREADCRUMB ── */
        .breadcrumb {
            font-size: 12px;
            color: var(--text-soft);
            font-family: 'JetBrains Mono', monospace;
            margin-bottom: 36px;
            letter-spacing: 0.3px;
        }
        .breadcrumb a { color: var(--green-mid); text-decoration: none; }
        .breadcrumb a:hover { text-decoration: underline; }
        .breadcrumb span { margin: 0 6px; }

        /* ── PATIENT STRIP ── */
        .patient-strip {
            background: white;
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 20px 28px;
            display: flex;
            align-items: center;
            gap: 20px;
            width: 100%;
            max-width: 740px;
            margin-bottom: 40px;
            box-shadow: var(--shadow-sm);
            animation: slideDown 0.4s cubic-bezier(0.22, 1, 0.36, 1) both;
        }
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-16px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .patient-photo {
            width: 60px; height: 60px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--green-pale);
            flex-shrink: 0;
        }
        .patient-avatar {
            width: 60px; height: 60px;
            border-radius: 50%;
            background: var(--green-pale);
            display: flex; align-items: center; justify-content: center;
            font-size: 28px;
            border: 3px solid var(--border);
            flex-shrink: 0;
        }
        .patient-info { flex: 1; }
        .patient-info-name {
            font-size: 18px; font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 3px;
        }
        .patient-info-meta {
            font-size: 13px; color: var(--text-soft);
            display: flex; gap: 16px; flex-wrap: wrap;
        }
        .patient-info-meta b { color: var(--text-mid); font-weight: 600; }
        .vitals-pill {
            font-size: 11px; font-weight: 700;
            padding: 4px 12px; border-radius: 20px;
            letter-spacing: 0.4px;
            flex-shrink: 0;
        }
        .vitals-pill.yes { background: var(--green-pale); color: var(--green-mid); }
        .vitals-pill.no  { background: #fff3e0; color: #e65100; }

        .vitals-mini {
            display: flex; gap: 12px; flex-wrap: wrap;
            margin-top: 6px;
        }
        .vitals-mini-item {
            font-size: 12px;
            font-family: 'JetBrains Mono', monospace;
            color: var(--text-mid);
        }
        .vitals-mini-item span { color: var(--text-soft); margin-right: 3px; }

        /* ── SECTION TITLE ── */
        .section-title {
            font-size: 13px;
            font-weight: 700;
            letter-spacing: 2px;
            color: var(--text-soft);
            text-transform: uppercase;
            margin-bottom: 20px;
            text-align: center;
            animation: fadeIn 0.5s 0.15s both;
        }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

        /* ── CARDS WRAPPER ── */
        .cards-wrapper {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            width: 100%;
            max-width: 740px;
        }

        /* ── OPTION CARD ── */
        .option-card {
            background: white;
            border: 2px solid var(--border);
            border-radius: 20px;
            padding: 36px 28px 32px;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            text-decoration: none;
            cursor: pointer;
            position: relative;
            overflow: hidden;
            transition: transform 0.25s cubic-bezier(0.22,1,0.36,1),
                        box-shadow 0.25s ease,
                        border-color 0.25s ease;
            box-shadow: var(--shadow-sm);
            animation: cardRise 0.5s cubic-bezier(0.22,1,0.36,1) both;
        }
        .option-card:nth-child(1) { animation-delay: 0.18s; }
        .option-card:nth-child(2) { animation-delay: 0.28s; }
        @keyframes cardRise {
            from { opacity: 0; transform: translateY(24px) scale(0.97); }
            to   { opacity: 1; transform: translateY(0) scale(1); }
        }

        .option-card::before {
            content: '';
            position: absolute;
            inset: 0;
            opacity: 0;
            transition: opacity 0.3s ease;
            border-radius: 18px;
        }
        .option-card.diagnosis::before  { background: linear-gradient(135deg, rgba(46,125,50,0.05), rgba(67,160,71,0.03)); }
        .option-card.teleconsult::before { background: linear-gradient(135deg, rgba(0,137,123,0.05), rgba(0,188,212,0.03)); }

        .option-card:hover {
            transform: translateY(-6px) scale(1.01);
            box-shadow: var(--shadow-lg);
        }
        .option-card:hover::before { opacity: 1; }
        .option-card.diagnosis:hover  { border-color: var(--green-mid); }
        .option-card.teleconsult:hover { border-color: var(--teal); }

        .option-card:active { transform: translateY(-2px) scale(0.99); }

        /* Icon blob */
        .card-icon-wrap {
            width: 88px; height: 88px;
            border-radius: 28px;
            display: flex; align-items: center; justify-content: center;
            font-size: 42px;
            margin-bottom: 22px;
            position: relative;
            transition: transform 0.3s ease;
        }
        .option-card:hover .card-icon-wrap { transform: scale(1.08) rotate(-3deg); }
        .diagnosis  .card-icon-wrap { background: var(--green-pale); }
        .teleconsult .card-icon-wrap { background: var(--teal-light); }

        /* Pulse ring on hover */
        .card-icon-wrap::after {
            content: '';
            position: absolute;
            inset: -6px;
            border-radius: 34px;
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        .diagnosis  .card-icon-wrap::after { border: 2px solid rgba(46,125,50,0.25); }
        .teleconsult .card-icon-wrap::after { border: 2px solid rgba(0,137,123,0.25); }
        .option-card:hover .card-icon-wrap::after { opacity: 1; }

        .card-title {
            font-size: 19px;
            font-weight: 800;
            color: var(--text-dark);
            margin-bottom: 10px;
            letter-spacing: -0.2px;
        }
        .diagnosis  .card-title { color: var(--green-dark); }
        .teleconsult .card-title { color: #00695c; }

        .card-desc {
            font-size: 13.5px;
            color: var(--text-soft);
            line-height: 1.65;
            margin-bottom: 28px;
            font-weight: 400;
        }

        .card-btn {
            width: 100%;
            padding: 13px;
            border: none;
            border-radius: 12px;
            font-family: 'Sora', sans-serif;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            letter-spacing: 0.3px;
            transition: opacity 0.2s, transform 0.15s;
            pointer-events: none; /* card itself is the link */
        }
        .option-card:hover .card-btn { transform: scale(1.02); }
        .diagnosis  .card-btn { background: linear-gradient(135deg, var(--green-mid), var(--green-light)); color: white; }
        .teleconsult .card-btn { background: linear-gradient(135deg, #00897b, #26a69a); color: white; }

        .card-tag {
            position: absolute;
            top: 14px; right: 14px;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 1px;
            text-transform: uppercase;
            padding: 3px 10px;
            border-radius: 20px;
        }
        .diagnosis  .card-tag { background: var(--green-pale); color: var(--green-mid); }
        .teleconsult .card-tag { background: var(--teal-light); color: var(--teal); }

        /* ── BACK LINK ── */
        .back-link {
            margin-top: 32px;
            font-size: 13px;
            color: var(--text-soft);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: color 0.2s;
            animation: fadeIn 0.5s 0.4s both;
        }
        .back-link:hover { color: var(--green-mid); }

        /* ── RESPONSIVE ── */
        @media (max-width: 600px) {
            .navbar { padding: 0 16px; }
            .main { padding: 32px 16px 50px; }
            .patient-strip { padding: 16px 18px; flex-wrap: wrap; }
            .cards-wrapper { grid-template-columns: 1fr; }
            .option-card { padding: 28px 22px 26px; }
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

<!-- MAIN -->
<main class="main">

    <!-- Breadcrumb -->
    <div class="breadcrumb">
        <a href="doctor_dashboard.php">Dashboard</a>
        <span>›</span>
        <a href="patient_queue.php">Patient Queue</a>
        <span>›</span>
        Select Consultation Type
    </div>

    <!-- Patient Strip -->
    <div class="patient-strip">
        <?php if ($photo): ?>
            <img src="<?= $photo ?>" alt="<?= $name ?>" class="patient-photo">
        <?php else: ?>
            <div class="patient-avatar">👤</div>
        <?php endif; ?>

        <div class="patient-info">
            <div class="patient-info-name"><?= $name ?></div>
            <div class="patient-info-meta">
                <span><b>ID:</b> <?= $patient_id ?></span>
                <span><b>Age:</b> <?= $age ?> yrs</span>
                <span><b>Gender:</b> <?= $gender ?></span>
            </div>
            <?php if ($hasVitals): ?>
            <div class="vitals-mini">
                <div class="vitals-mini-item"><span>BP</span><?= $bp ?></div>
                <div class="vitals-mini-item"><span>HR</span><?= $hr ?></div>
                <div class="vitals-mini-item"><span>Temp</span><?= $temp ?></div>
                <div class="vitals-mini-item"><span>SpO2</span><?= $spo2 ?></div>
            </div>
            <?php endif; ?>
        </div>

        <div class="vitals-pill <?= $hasVitals ? 'yes' : 'no' ?>">
            <?= $hasVitals ? '❤️ Vitals Ready' : '⚠️ No Vitals' ?>
        </div>
    </div>

    <!-- Section label -->
    <p class="section-title">How would you like to proceed?</p>

    <!-- Two option cards -->
    <div class="cards-wrapper">

        <!-- Diagnosis Card -->
        <a class="option-card diagnosis"
           href="diagnosis_form.php?patientId=<?= $patient_id ?>">
            <span class="card-tag">In-Person</span>
            <div class="card-icon-wrap">🩺</div>
            <div class="card-title">Diagnosis Form</div>
            <p class="card-desc">
                Conduct a full in-person clinical assessment, record symptoms, findings, prescribe medications, and generate a diagnosis report.
            </p>
            <button class="card-btn">Open Diagnosis Form →</button>
        </a>

        <!-- Teleconsultation Card -->
        <a class="option-card teleconsult"
           href="telestudio_doctors_available.php?patientId=<?= $patient_id ?>">
            <span class="card-tag">Remote</span>
            <div class="card-icon-wrap">📹</div>
            <div class="card-title">Teleconsultation</div>
            <p class="card-desc">
                Start a remote video consultation session, review patient history, provide guidance, and issue an e-prescription remotely.
            </p>
            <button class="card-btn">Start Teleconsultation →</button>
        </a>

    </div>

    <a href="patient_queue.php" class="back-link">← Back to Patient Queue</a>

</main>

<script src="../assets/doctor_notifications.js"></script>
</body>
</html>
