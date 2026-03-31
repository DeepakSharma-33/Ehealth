<?php
require_once __DIR__ . '/../../config/config.php';
require_role('telestudio_doctor', 'telestudio_doctor_login.php');

$db = db();
$doctor_id = (string)get_session_id();
$doctor_name = (string)get_session_name();

$stmt = $db->prepare(
    "SELECT
        td.id,
        td.session_id,
        td.patient_id,
        td.ehealth_doctor_id,
        td.ehealth_doctor_name,
        td.telestudio_doctor_id,
        td.telestudio_doctor_name,
        td.chief_complaint,
        td.clinical_notes,
        td.consultation_notes,
        td.primary_diagnosis,
        td.prescription,
        td.medicines_json,
        td.review_after,
        td.investigation,
        td.remarks,
        td.diagnosis_date,
        ts.teleconsult_reason,
        ts.status AS session_status,
        p.full_name AS patient_name,
        p.age AS patient_age,
        p.gender AS patient_gender,
        p.mobile_no AS patient_mobile,
        p.address_full AS patient_address,
        p.photo_filename AS patient_photo_filename,
        ehd.mobile_no AS ehealth_doctor_mobile,
        v.bp_systolic,
        v.bp_diastolic,
        v.heart_rate,
        v.temperature,
        v.spo2,
        v.resp_rate,
        v.weight,
        v.height,
        v.bmi,
        v.recorded_at AS vitals_at
     FROM telestudio_diagnosis td
     LEFT JOIN teleconsult_sessions ts ON ts.id = td.session_id
     LEFT JOIN patients p ON p.patient_id = td.patient_id
     LEFT JOIN ehealth_center_doctors ehd ON ehd.doctor_id = td.ehealth_doctor_id
     LEFT JOIN patient_vitals v ON v.id = (
         SELECT pv.id
         FROM patient_vitals pv
         WHERE pv.patient_id = td.patient_id
           AND pv.recorded_at <= td.diagnosis_date
         ORDER BY pv.recorded_at DESC
         LIMIT 1
     )
     WHERE td.telestudio_doctor_id = ?
     ORDER BY td.diagnosis_date DESC
     LIMIT 500"
);
$stmt->bind_param('s', $doctor_id);
$stmt->execute();
$historyRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function fmt_dt(?string $dateTime): string
{
    if (!$dateTime) {
        return '-';
    }
    $ts = strtotime($dateTime);
    if (!$ts) {
        return (string)$dateTime;
    }
    return date('d M Y, h:i A', $ts);
}

function vitals_line(array $row): string
{
    $bp = ((string)($row['bp_systolic'] ?? '') !== '' && (string)($row['bp_diastolic'] ?? '') !== '')
        ? ($row['bp_systolic'] . '/' . $row['bp_diastolic'])
        : '-';
    $hr = (string)($row['heart_rate'] ?? '-');
    $temp = (string)($row['temperature'] ?? '-');
    $spo2 = (string)($row['spo2'] ?? '-');
    $rr = (string)($row['resp_rate'] ?? '-');
    $bmi = (string)($row['bmi'] ?? '-');
    return "BP {$bp} | HR {$hr} | Temp {$temp} | SpO2 {$spo2} | RR {$rr} | BMI {$bmi}";
}

function medicine_lines(?string $medicinesJson): array
{
    if (!$medicinesJson) {
        return [];
    }
    $arr = json_decode($medicinesJson, true);
    if (!is_array($arr)) {
        return [];
    }
    $lines = [];
    foreach ($arr as $med) {
        if (!is_array($med)) {
            continue;
        }
        $name = trim((string)($med['name'] ?? $med['medicine_name'] ?? '-'));
        $dosage = trim((string)($med['dosage'] ?? ''));
        $timingRaw = $med['timing'] ?? [];
        $timing = '';
        if (is_array($timingRaw)) {
            $timing = implode(', ', array_values(array_filter(array_map('strval', $timingRaw))));
        } else {
            $timing = trim((string)$timingRaw);
        }
        $meal = trim((string)($med['mealInstruction'] ?? $med['meal_instruction'] ?? ''));
        $duration = trim((string)($med['duration'] ?? ''));
        $parts = [$name];
        if ($dosage !== '') {
            $parts[] = "({$dosage})";
        }
        if ($timing !== '') {
            $parts[] = "- {$timing}";
        }
        if ($meal !== '') {
            $parts[] = "- {$meal}";
        }
        if ($duration !== '') {
            $parts[] = "- {$duration}";
        }
        $lines[] = trim(implode(' ', $parts));
    }
    return $lines;
}

$uniquePatients = [];
$preparedRows = [];
foreach ($historyRows as $row) {
    $patientId = (string)($row['patient_id'] ?? '');
    if ($patientId !== '') {
        $uniquePatients[$patientId] = true;
    }

    $row['patient_photo_url'] = patient_photo_url($row['patient_photo_filename'] ?? null);
    $row['diagnosis_at_human'] = fmt_dt($row['diagnosis_date'] ?? null);
    $row['vitals_at_human'] = fmt_dt($row['vitals_at'] ?? null);
    $row['vitals_used_line'] = vitals_line($row);
    $row['medicine_lines'] = medicine_lines($row['medicines_json'] ?? null);

    $searchBlob = implode(' ', [
        (string)($row['patient_id'] ?? ''),
        (string)($row['patient_name'] ?? ''),
        (string)($row['patient_mobile'] ?? ''),
        (string)($row['ehealth_doctor_name'] ?? ''),
        (string)($row['ehealth_doctor_id'] ?? ''),
        (string)($row['ehealth_doctor_mobile'] ?? ''),
        (string)($row['teleconsult_reason'] ?? ''),
        (string)($row['chief_complaint'] ?? ''),
        (string)($row['clinical_notes'] ?? ''),
        (string)($row['consultation_notes'] ?? ''),
        (string)($row['primary_diagnosis'] ?? ''),
    ]);
    $row['search_blob'] = strtolower(preg_replace('/\s+/', ' ', trim($searchBlob)));

    $preparedRows[] = $row;
}

$totalCount = count($preparedRows);
$patientCount = count($uniquePatients);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Consultation History - Telestudio</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root {
            --g-dark: #1b5e20;
            --g-mid: #2e7d32;
            --g-light: #43a047;
            --g-soft: #eaf6ec;
            --g-border: #c9e1cd;
            --teal: #00796b;
            --text-dark: #0e2214;
            --text-mid: #2d4732;
            --text-soft: #607663;
            --white: #fff;
            --warn: #b85a00;
        }

        body {
            min-height: 100vh;
            font-family: 'Inter', sans-serif;
            color: var(--text-dark);
            background: #a9ddb0;
            background-image:
                radial-gradient(ellipse 60% 45% at 0% 0%, rgba(27,94,32,0.14) 0%, transparent 60%),
                radial-gradient(ellipse 45% 40% at 100% 100%, rgba(0,121,107,0.12) 0%, transparent 60%);
        }

        .navbar {
            height: 58px;
            background: var(--g-dark);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 18px;
            position: sticky;
            top: 0;
            z-index: 50;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
        }
        .brand {
            color: #fff;
            text-decoration: none;
            font-size: 18px;
            font-weight: 800;
            letter-spacing: 1.1px;
        }
        .back-btn {
            color: #fff;
            text-decoration: none;
            font-size: 13px;
            font-weight: 700;
            border: 1px solid rgba(255, 255, 255, 0.32);
            border-radius: 20px;
            padding: 7px 12px;
            background: rgba(255, 255, 255, 0.12);
        }

        .page {
            max-width: 1220px;
            margin: 0 auto;
            padding: 16px;
            display: grid;
            gap: 12px;
        }

        .hero {
            background: var(--white);
            border: 1px solid var(--g-border);
            border-radius: 14px;
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.08);
            padding: 18px;
        }
        .hero h1 {
            font-size: clamp(22px, 3vw, 30px);
            margin-bottom: 6px;
            font-weight: 800;
        }
        .hero p { color: var(--text-mid); font-size: 14px; font-weight: 600; }
        .hero-meta {
            margin-top: 10px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .chip {
            border: 1px solid var(--g-border);
            border-radius: 18px;
            background: var(--g-soft);
            color: var(--g-dark);
            font-size: 12px;
            font-weight: 800;
            padding: 6px 10px;
        }

        .controls {
            background: var(--white);
            border: 1px solid var(--g-border);
            border-radius: 14px;
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.08);
            padding: 12px;
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 10px;
        }
        .search-input {
            width: 100%;
            border: 2px solid var(--g-border);
            border-radius: 10px;
            padding: 10px 12px;
            font-size: 14px;
            font-weight: 700;
            color: var(--text-dark);
        }
        .search-input:focus {
            outline: none;
            border-color: var(--g-mid);
            box-shadow: 0 0 0 3px rgba(46, 125, 50, 0.12);
        }
        .hint {
            align-self: center;
            color: var(--text-soft);
            font-size: 12px;
            font-weight: 600;
        }

        .history-panel {
            background: var(--white);
            border: 1px solid var(--g-border);
            border-radius: 14px;
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.08);
            padding: 12px;
        }
        .history-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 10px;
            flex-wrap: wrap;
        }
        .history-head h2 {
            font-size: 17px;
            font-weight: 800;
            color: var(--g-dark);
        }
        .shown {
            font-size: 12px;
            font-weight: 800;
            color: var(--text-mid);
            background: #f6fbf7;
            border: 1px solid var(--g-border);
            border-radius: 15px;
            padding: 5px 10px;
        }
        .history-scroll {
            max-height: 66vh;
            overflow: auto;
            display: grid;
            gap: 10px;
            padding-right: 2px;
        }

        .history-card {
            border: 1px solid var(--g-border);
            border-left: 5px solid var(--teal);
            border-radius: 12px;
            background: #fff;
            padding: 12px;
            display: grid;
            gap: 9px;
        }
        .card-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 8px;
            flex-wrap: wrap;
        }
        .patient-wrap {
            display: flex;
            gap: 10px;
            align-items: center;
            min-width: 0;
        }
        .p-photo {
            width: 52px;
            height: 52px;
            border-radius: 50%;
            background: var(--g-soft);
            border: 2px solid var(--g-border);
            object-fit: cover;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--g-mid);
            font-size: 21px;
            font-weight: 800;
        }
        .p-name { font-size: 16px; font-weight: 800; color: var(--text-dark); }
        .p-id {
            margin-top: 3px;
            display: inline-block;
            font-family: 'JetBrains Mono', monospace;
            font-size: 11px;
            font-weight: 700;
            background: var(--g-soft);
            color: var(--g-mid);
            border-radius: 14px;
            padding: 2px 8px;
        }
        .p-meta {
            margin-top: 5px;
            font-size: 12px;
            color: var(--text-mid);
            line-height: 1.4;
        }
        .diag-date {
            font-family: 'JetBrains Mono', monospace;
            font-size: 12px;
            color: var(--text-soft);
            font-weight: 700;
        }

        .rows { display: grid; gap: 6px; }
        .row { font-size: 13px; color: var(--text-mid); line-height: 1.45; }
        .row b { color: var(--text-dark); }

        .med-box {
            border: 1px solid var(--g-border);
            background: #f8fcf9;
            border-radius: 10px;
            padding: 8px;
            display: grid;
            gap: 5px;
        }
        .med-title {
            font-size: 12px;
            font-weight: 800;
            color: var(--g-dark);
        }
        .med-item {
            font-size: 12px;
            color: var(--text-mid);
            line-height: 1.45;
        }
        .vitals-chip {
            border: 1px solid var(--g-border);
            background: #eef8f0;
            border-radius: 18px;
            padding: 6px 10px;
            font-size: 11px;
            font-weight: 700;
            color: var(--g-dark);
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .btn-mini {
            text-decoration: none;
            border: 1px solid var(--g-border);
            background: #fff;
            color: var(--text-mid);
            border-radius: 8px;
            padding: 6px 9px;
            font-size: 12px;
            font-weight: 700;
        }
        .btn-mini:hover {
            border-color: var(--g-mid);
            color: var(--g-dark);
        }

        .empty-box {
            border: 1px dashed #b9cdbd;
            border-radius: 12px;
            background: #f7fbf8;
            color: var(--text-soft);
            text-align: center;
            padding: 20px 12px;
            font-size: 13px;
            font-weight: 700;
        }
        .empty-box.warn {
            color: var(--warn);
            border-color: #edc8a2;
            background: #fff8f0;
            display: none;
        }

        @media (max-width: 860px) {
            .controls { grid-template-columns: 1fr; }
            .history-scroll { max-height: 70vh; }
        }
    </style>
</head>
<body>
<nav class="navbar">
    <a class="brand" href="<?= htmlspecialchars(app_base_url()) ?>/"><span style="display:flex;align-items:center;gap:14px;line-height:1;"><img src="<?= htmlspecialchars(app_base_url()) ?>/public/assets/SRMS_TRUST_LOGO.png" alt="SRMST Trust Logo" style="height:48px;width:auto;display:block;object-fit:contain;filter:drop-shadow(0 1px 3px rgba(0,0,0,.35));"><span style="font-size:22px;font-weight:800;letter-spacing:1px;color:#fff;text-shadow:0 1px 2px rgba(0,0,0,.35);white-space:nowrap;">SRMS EHEALTH</span></span></a>
    <div style="display:flex;gap:12px;align-items:center;">
        <a class="back-btn" href="telestudio_dashboard.php">Back to Dashboard</a>
        <a class="back-btn" href="<?= htmlspecialchars(app_base_url()) ?>/about.php">ABOUT</a>
        <a class="back-btn" href="<?= htmlspecialchars(app_base_url()) ?>/contact.php">CONTACT</a>
    </div>
</nav>

<main class="page">
    <section class="hero">
        <h1>My Consultation History</h1>
        <p>All teleconsultation diagnoses done by you are listed below.</p>
        <div class="hero-meta">
            <div class="chip">Doctor: <?= h($doctor_name) ?> (<?= h($doctor_id) ?>)</div>
            <div class="chip">Total Records: <?= h((string)$totalCount) ?></div>
            <div class="chip">Unique Patients: <?= h((string)$patientCount) ?></div>
        </div>
    </section>

    <section class="controls">
        <input id="searchInput" class="search-input" type="text" placeholder="Search by Patient ID / Name / Mobile / eHealth Doctor / Diagnosis..." autocomplete="off">
        <div class="hint">Live filter works on all cards below</div>
    </section>

    <section class="history-panel">
        <div class="history-head">
            <h2>Consultation Records</h2>
            <div class="shown">Showing <span id="shownCount"><?= h((string)$totalCount) ?></span> of <?= h((string)$totalCount) ?></div>
        </div>

        <?php if ($totalCount === 0): ?>
            <div class="empty-box">No consultation history found for your account yet.</div>
        <?php else: ?>
            <div id="historyScroll" class="history-scroll">
                <?php foreach ($preparedRows as $row): ?>
                    <?php
                    $patientName = (string)($row['patient_name'] ?? 'Unknown Patient');
                    $patientId = (string)($row['patient_id'] ?? '-');
                    $patientAge = (string)($row['patient_age'] ?? '-');
                    $patientGender = (string)($row['patient_gender'] ?? '-');
                    $patientMobile = (string)($row['patient_mobile'] ?? '-');
                    $patientAddress = trim((string)($row['patient_address'] ?? ''));
                    $ehealthDoctorName = (string)($row['ehealth_doctor_name'] ?? '-');
                    $ehealthDoctorId = (string)($row['ehealth_doctor_id'] ?? '-');
                    $ehealthDoctorMobile = (string)($row['ehealth_doctor_mobile'] ?? '-');
                    $teleReason = (string)($row['teleconsult_reason'] ?? '-');
                    $chiefComplaint = trim((string)($row['chief_complaint'] ?? ''));
                    $clinicalNotes = trim((string)($row['clinical_notes'] ?? ''));
                    $consultationNotes = trim((string)($row['consultation_notes'] ?? ''));
                    $primaryDiagnosis = trim((string)($row['primary_diagnosis'] ?? ''));
                    $investigation = trim((string)($row['investigation'] ?? ''));
                    $reviewAfter = trim((string)($row['review_after'] ?? ''));
                    $remarks = trim((string)($row['remarks'] ?? ''));
                    $sessionStatus = trim((string)($row['session_status'] ?? ''));
                    $medicineLines = $row['medicine_lines'] ?? [];
                    $patientHistoryUrl = '../ehealth_center_doctor/view_history.php?patientId=' . rawurlencode($patientId) . '&tab=tele';
                    ?>
                    <article class="history-card" data-search="<?= h((string)($row['search_blob'] ?? '')) ?>">
                        <div class="card-top">
                            <div class="patient-wrap">
                                <?php if (!empty($row['patient_photo_url'])): ?>
                                    <img src="<?= h((string)$row['patient_photo_url']) ?>" alt="Patient Photo" class="p-photo">
                                <?php else: ?>
                                    <div class="p-photo">P</div>
                                <?php endif; ?>
                                <div>
                                    <div class="p-name"><?= h($patientName) ?></div>
                                    <div class="p-id"><?= h($patientId) ?></div>
                                    <div class="p-meta">Age: <?= h($patientAge) ?> | Gender: <?= h($patientGender) ?> | Mobile: <?= h($patientMobile) ?></div>
                                </div>
                            </div>
                            <div class="diag-date"><?= h((string)$row['diagnosis_at_human']) ?></div>
                        </div>

                        <div class="rows">
                            <div class="row"><b>eHealth Doctor:</b> <?= h($ehealthDoctorName) ?> (<?= h($ehealthDoctorId) ?>) | Phone: <?= h($ehealthDoctorMobile) ?></div>
                            <div class="row"><b>Teleconsult Reason:</b> <?= h($teleReason !== '' ? $teleReason : '-') ?></div>
                            <div class="row"><b>Chief Complaint:</b> <?= h($chiefComplaint !== '' ? $chiefComplaint : '-') ?></div>
                            <div class="row"><b>Clinical Notes:</b> <?= h($clinicalNotes !== '' ? $clinicalNotes : '-') ?></div>
                            <div class="row"><b>Consultation Notes:</b> <?= h($consultationNotes !== '' ? $consultationNotes : '-') ?></div>
                            <div class="row"><b>Primary Diagnosis:</b> <?= h($primaryDiagnosis !== '' ? $primaryDiagnosis : '-') ?></div>
                            <div class="row"><b>Investigation:</b> <?= h($investigation !== '' ? $investigation : '-') ?></div>
                            <div class="row"><b>Review After:</b> <?= h($reviewAfter !== '' ? $reviewAfter : '-') ?></div>
                            <div class="row"><b>Remarks:</b> <?= h($remarks !== '' ? $remarks : '-') ?></div>
                            <div class="row"><b>Session Status:</b> <?= h($sessionStatus !== '' ? strtoupper($sessionStatus) : '-') ?></div>
                            <?php if ($patientAddress !== ''): ?>
                                <div class="row"><b>Patient Address:</b> <?= h($patientAddress) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="med-box">
                            <div class="med-title">Medicines</div>
                            <?php if (!empty($medicineLines)): ?>
                                <?php foreach ($medicineLines as $line): ?>
                                    <div class="med-item"><?= h((string)$line) ?></div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="med-item">None</div>
                            <?php endif; ?>
                        </div>

                        <div class="vitals-chip">
                            <span><b>Vitals Used:</b> <?= h((string)$row['vitals_used_line']) ?></span>
                            <span>|</span>
                            <span>Recorded: <?= h((string)$row['vitals_at_human']) ?></span>
                        </div>

                        <div class="actions">
                            <a class="btn-mini" href="<?= h($patientHistoryUrl) ?>" target="_blank" rel="noopener">Open Patient Full History</a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
            <div id="noMatchBox" class="empty-box warn">No records match this search.</div>
        <?php endif; ?>
    </section>
</main>

<script>
(function() {
    const input = document.getElementById('searchInput');
    const cards = Array.from(document.querySelectorAll('.history-card'));
    const shownCount = document.getElementById('shownCount');
    const noMatchBox = document.getElementById('noMatchBox');

    if (!input || cards.length === 0 || !shownCount) {
        return;
    }

    function applyFilter() {
        const q = input.value.trim().toLowerCase();
        let shown = 0;
        cards.forEach((card) => {
            const text = String(card.dataset.search || '');
            const hit = (q === '') || text.includes(q);
            card.style.display = hit ? '' : 'none';
            if (hit) shown++;
        });
        shownCount.textContent = String(shown);
        if (noMatchBox) {
            noMatchBox.style.display = shown > 0 ? 'none' : 'block';
        }
    }

    input.addEventListener('input', applyFilter);
})();
</script>
</body>
</html>
