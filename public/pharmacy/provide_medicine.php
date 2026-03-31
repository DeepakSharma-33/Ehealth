<?php
require_once __DIR__ . '/../../config/config.php';
require_role('pharmacy_staff', 'pharmacy_login.php');

if (isset($_POST['logout'])) {
    logout_user();
    header('Location: pharmacy_login.php');
    exit;
}

$db = db();

function e(mixed $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES);
}

function table_exists(mysqli $db, string $table): bool {
    $safe = $db->real_escape_string($table);
    $res = $db->query("SHOW TABLES LIKE '{$safe}'");
    if (!$res) return false;
    $exists = $res->num_rows > 0;
    $res->free();
    return $exists;
}

function existing_tables(mysqli $db, array $candidates): array {
    $out = [];
    foreach ($candidates as $table) {
        if (table_exists($db, $table)) {
            $out[] = $table;
        }
    }
    return $out;
}

function table_columns(mysqli $db, string $table): array {
    $safe = $db->real_escape_string($table);
    $res = $db->query("SHOW COLUMNS FROM `{$safe}`");
    if (!$res) return [];
    $cols = [];
    while ($row = $res->fetch_assoc()) {
        $cols[] = $row['Field'];
    }
    $res->free();
    return $cols;
}

function bind_dynamic(mysqli_stmt $stmt, string $types, array $params): void {
    if ($types === '' || empty($params)) return;
    $refs = [];
    foreach ($params as $k => $v) {
        $refs[$k] = &$params[$k];
    }
    array_unshift($refs, $types);
    call_user_func_array([$stmt, 'bind_param'], $refs);
}

function with_patient_photo(array $row): array {
    $row['photo_url'] = patient_photo_url($row['photo_filename'] ?? null);
    unset($row['photo_filename']);
    return $row;
}

function attach_dispense_state(mysqli $db, array $rows): array {
    if (empty($rows) || !table_exists($db, 'pharmacy_dispense')) {
        foreach ($rows as &$row) {
            $row['dispensed'] = false;
            $row['action_label'] = 'Provide Diagnosis';
        }
        unset($row);
        return $rows;
    }

    $cols = table_columns($db, 'pharmacy_dispense');
    $paymentMethodCol = in_array('payment_method', $cols, true) ? 'payment_method' : '';
    $savedAtCol = in_array('paid_at', $cols, true)
        ? 'paid_at'
        : (in_array('updated_at', $cols, true) ? 'updated_at' : (in_array('created_at', $cols, true) ? 'created_at' : ''));

    $typeToIds = [
        'ehealth' => [],
        'teleconsult' => [],
    ];

    foreach ($rows as $row) {
        $type = (string)($row['source'] ?? '');
        $id = (int)($row['diagnosis_id'] ?? 0);
        if ($id > 0 && isset($typeToIds[$type])) {
            $typeToIds[$type][$id] = true;
        }
    }

    $conditions = [];
    $types = '';
    $params = [];
    foreach ($typeToIds as $type => $idsAssoc) {
        $ids = array_keys($idsAssoc);
        if (empty($ids)) continue;
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $conditions[] = "(diagnosis_type = '{$type}' AND diagnosis_id IN ({$ph}))";
        $types .= str_repeat('i', count($ids));
        foreach ($ids as $id) {
            $params[] = (int)$id;
        }
    }

    if (empty($conditions)) {
        foreach ($rows as &$row) {
            $row['dispensed'] = false;
            $row['action_label'] = 'Provide Diagnosis';
        }
        unset($row);
        return $rows;
    }

    $select = 'diagnosis_type, diagnosis_id';
    if ($paymentMethodCol !== '') {
        $select .= ', payment_method';
    }
    if ($savedAtCol !== '') {
        $select .= ", {$savedAtCol} AS saved_at";
    }

    $map = [];
    $sql = "SELECT {$select} FROM pharmacy_dispense WHERE " . implode(' OR ', $conditions);
    $stmt = $db->prepare($sql);
    if ($stmt) {
        bind_dynamic($stmt, $types, $params);
        $stmt->execute();
        $records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($records as $record) {
            $key = (string)($record['diagnosis_type'] ?? '') . '|' . (int)($record['diagnosis_id'] ?? 0);
            $map[$key] = $record;
        }
    }

    foreach ($rows as &$row) {
        $key = (string)($row['source'] ?? '') . '|' . (int)($row['diagnosis_id'] ?? 0);
        $saved = $map[$key] ?? null;
        $row['dispensed'] = $saved !== null;
        $row['dispense_payment_method'] = $saved['payment_method'] ?? '';
        $row['dispense_saved_at'] = $saved['saved_at'] ?? '';
        $row['action_label'] = $saved ? 'Diagnosis Provided' : 'Provide Diagnosis';
    }
    unset($row);

    return $rows;
}

if (isset($_GET['action'])) {
    $action = trim((string)$_GET['action']);

    if ($action === 'doctor_lookup') {
        $doctorId = strtoupper(trim((string)($_GET['doctor_id'] ?? '')));
        if ($doctorId === '') {
            json_error('Doctor ID is required.');
        }

        $stmt = $db->prepare('SELECT doctor_id, full_name, specialization FROM ehealth_center_doctors WHERE doctor_id = ? LIMIT 1');
        $stmt->bind_param('s', $doctorId);
        $stmt->execute();
        $doc = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$doc) {
            json_error('No eHealth center doctor found with that ID.', 404);
        }

        json_success(['doctor' => $doc]);
    }

    if ($action === 'queue') {
        $doctorId = strtoupper(trim((string)($_GET['doctor_id'] ?? '')));
        if ($doctorId === '') {
            json_error('Doctor ID is required.');
        }

        $rows = [];

        $stmt = $db->prepare(
            "SELECT d.id AS diagnosis_id,
                    d.diagnosis_date,
                    p.patient_id,
                    p.full_name,
                    p.mobile_no,
                    p.address_full,
                    p.photo_filename,
                    d.doctor_id AS ehealth_doctor_id,
                    COALESCE(ed.full_name, d.doctor_id, '') AS ehealth_doctor_name
             FROM diagnosis_records d
             INNER JOIN patients p ON p.patient_id = d.patient_id
             LEFT JOIN ehealth_center_doctors ed ON ed.doctor_id = d.doctor_id
             WHERE d.doctor_id = ?
               AND DATE(d.diagnosis_date) = CURDATE()
             ORDER BY d.diagnosis_date DESC
             LIMIT 300"
        );
        $stmt->bind_param('s', $doctorId);
        $stmt->execute();
        $ehRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($ehRows as $row) {
            $row['source'] = 'ehealth';
            $row['source_table'] = 'diagnosis_records';
            $row = with_patient_photo($row);
            $rows[] = $row;
        }

        $teleTables = existing_tables($db, ['telestudio_diagnosis', 'teleconsult_diagnosis']);
        foreach ($teleTables as $tbl) {
            $sql = "SELECT td.id AS diagnosis_id,
                           td.diagnosis_date,
                           p.patient_id,
                           p.full_name,
                           p.mobile_no,
                           p.address_full,
                           p.photo_filename,
                           td.ehealth_doctor_id,
                           COALESCE(NULLIF(td.ehealth_doctor_name,''), ed.full_name, td.ehealth_doctor_id, '') AS ehealth_doctor_name,
                           td.telestudio_doctor_id,
                           COALESCE(NULLIF(td.telestudio_doctor_name,''), tsd.full_name, td.telestudio_doctor_id, '') AS telestudio_doctor_name
                    FROM {$tbl} td
                    INNER JOIN patients p ON p.patient_id = td.patient_id
                    LEFT JOIN ehealth_center_doctors ed ON ed.doctor_id = td.ehealth_doctor_id
                    LEFT JOIN telestudio_doctors tsd ON tsd.doctor_id = td.telestudio_doctor_id
                    WHERE td.ehealth_doctor_id = ?
                      AND DATE(td.diagnosis_date) = CURDATE()
                    ORDER BY td.diagnosis_date DESC
                    LIMIT 300";
            $st = $db->prepare($sql);
            if (!$st) continue;
            $st->bind_param('s', $doctorId);
            $st->execute();
            $tRows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
            $st->close();

            foreach ($tRows as $row) {
                $row['source'] = 'teleconsult';
                $row['source_table'] = $tbl;
                $row = with_patient_photo($row);
                $rows[] = $row;
            }
        }

        usort($rows, static function(array $a, array $b): int {
            return strcmp((string)$b['diagnosis_date'], (string)$a['diagnosis_date']);
        });

        $rows = attach_dispense_state($db, $rows);
        $rows = array_values(array_filter($rows, static function(array $row): bool {
            return empty($row['dispensed']);
        }));
        json_success(['queue' => $rows]);
    }

    if ($action === 'search') {
        $q = trim((string)($_GET['q'] ?? ''));
        if ($q === '') {
            json_success(['results' => []]);
        }

        $term = '%' . $q . '%';
        $digits = preg_replace('/\D+/', '', $q);
        $mobileTerm = $digits !== '' ? '%' . $digits . '%' : $term;

        $rows = [];

        $sql = "SELECT d.id AS diagnosis_id,
                       d.diagnosis_date,
                       p.patient_id,
                       p.full_name,
                       p.mobile_no,
                       p.address_full,
                       p.photo_filename,
                       d.doctor_id AS ehealth_doctor_id,
                       COALESCE(ed.full_name, d.doctor_id, '') AS ehealth_doctor_name
                FROM diagnosis_records d
                INNER JOIN patients p ON p.patient_id = d.patient_id
                LEFT JOIN ehealth_center_doctors ed ON ed.doctor_id = d.doctor_id
                WHERE p.patient_id LIKE ?
                   OR p.full_name LIKE ?
                   OR p.mobile_no LIKE ?
                ORDER BY d.diagnosis_date DESC
                LIMIT 300";
        $stmt = $db->prepare($sql);
        $stmt->bind_param('sss', $term, $term, $mobileTerm);
        $stmt->execute();
        $ehRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($ehRows as $row) {
            $row['source'] = 'ehealth';
            $row['source_table'] = 'diagnosis_records';
            $row = with_patient_photo($row);
            $rows[] = $row;
        }

        $teleTables = existing_tables($db, ['telestudio_diagnosis', 'teleconsult_diagnosis']);
        foreach ($teleTables as $tbl) {
            $sql = "SELECT td.id AS diagnosis_id,
                           td.diagnosis_date,
                           p.patient_id,
                           p.full_name,
                           p.mobile_no,
                           p.address_full,
                           p.photo_filename,
                           td.ehealth_doctor_id,
                           COALESCE(NULLIF(td.ehealth_doctor_name,''), ed.full_name, td.ehealth_doctor_id, '') AS ehealth_doctor_name,
                           td.telestudio_doctor_id,
                           COALESCE(NULLIF(td.telestudio_doctor_name,''), tsd.full_name, td.telestudio_doctor_id, '') AS telestudio_doctor_name
                    FROM {$tbl} td
                    INNER JOIN patients p ON p.patient_id = td.patient_id
                    LEFT JOIN ehealth_center_doctors ed ON ed.doctor_id = td.ehealth_doctor_id
                    LEFT JOIN telestudio_doctors tsd ON tsd.doctor_id = td.telestudio_doctor_id
                    WHERE p.patient_id LIKE ?
                       OR p.full_name LIKE ?
                       OR p.mobile_no LIKE ?
                    ORDER BY td.diagnosis_date DESC
                    LIMIT 300";
            $st = $db->prepare($sql);
            if (!$st) continue;
            $st->bind_param('sss', $term, $term, $mobileTerm);
            $st->execute();
            $tRows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
            $st->close();

            foreach ($tRows as $row) {
                $row['source'] = 'teleconsult';
                $row['source_table'] = $tbl;
                $row = with_patient_photo($row);
                $rows[] = $row;
            }
        }

        usort($rows, static function(array $a, array $b): int {
            return strcmp((string)$b['diagnosis_date'], (string)$a['diagnosis_date']);
        });

        $rows = attach_dispense_state($db, $rows);
        json_success(['results' => $rows]);
    }
}

$pharm_name = htmlspecialchars(get_session_name() ?: 'Pharmacy Staff');
$pharm_id   = htmlspecialchars($_SESSION['pharmacy_id'] ?? '');
$base = app_base_url();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Provide Medicine - Pharmacy Staff</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --green-dark: #1b5e20;
            --green-mid: #2e7d32;
            --green-light: #43a047;
            --green-pale: #e8f5e9;
            --bg: #e6f4ea;
            --text: #1f2937;
            --muted: #6b7280;
            --border: #cfe3d1;
            --card: #ffffff;
        }
        body {
            font-family: 'Segoe UI', sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
        }
        .navbar {
            background: var(--green-mid);
            height: 58px;
            padding: 0 32px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 2px 10px rgba(0,0,0,0.18);
        }
        .navbar-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            color: white;
            text-decoration: none;
            font-weight: 800;
            letter-spacing: 1px;
        }
        .nav-right {
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .pill {
            background: rgba(255,255,255,0.15);
            color: white;
            padding: 6px 12px;
            border-radius: 18px;
            font-size: 12px;
            font-weight: 700;
        }
        .logout-btn {
            border: 2px solid white;
            background: transparent;
            color: white;
            padding: 6px 16px;
            border-radius: 18px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
        }
        .page {
            max-width: 1100px;
            margin: 0 auto;
            padding: 26px 20px 60px;
        }
        .title {
            margin-bottom: 20px;
        }
        .title h1 {
            font-size: 28px;
            color: var(--green-dark);
            margin-bottom: 6px;
        }
        .title p {
            color: var(--muted);
            font-size: 14px;
        }
        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 16px;
            box-shadow: 0 6px 18px rgba(27,94,32,0.08);
            padding: 20px 22px;
            margin-bottom: 18px;
        }
        .card h2 {
            font-size: 18px;
            color: var(--green-dark);
            margin-bottom: 10px;
        }
        .alert {
            display: none;
            padding: 10px 12px;
            border-radius: 8px;
            font-size: 13px;
            margin-bottom: 12px;
        }
        .alert.show { display: block; }
        .alert.error { background: #fdecea; color: #b71c1c; border: 1px solid #f5c6cb; }
        .alert.success { background: #e8f5e9; color: #1b5e20; border: 1px solid #c8e6c9; }
        .grid-2 {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 12px;
            align-items: end;
        }
        .field label {
            display: block;
            font-size: 12px;
            font-weight: 700;
            color: var(--green-dark);
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }
        .field input {
            width: 100%;
            padding: 10px 12px;
            border-radius: 10px;
            border: 1.5px solid var(--border);
            font-size: 14px;
            outline: none;
        }
        .field input:focus { border-color: var(--green-mid); }
        .btn {
            border: none;
            border-radius: 10px;
            padding: 10px 16px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            background: var(--green-mid);
            color: white;
        }
        .btn.secondary {
            background: transparent;
            color: var(--green-mid);
            border: 1.5px solid var(--green-mid);
        }
        .doctor-info {
            display: none;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            padding: 12px 14px;
            border-radius: 12px;
            background: var(--green-pale);
            border: 1px dashed var(--border);
            margin-top: 12px;
        }
        .doctor-info.show { display: flex; }
        .doctor-name {
            font-weight: 800;
            color: var(--green-dark);
        }
        .doctor-meta {
            font-size: 12px;
            color: var(--muted);
            margin-top: 2px;
        }
        .section-title {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 12px;
        }
        .count-pill {
            background: var(--green-pale);
            color: var(--green-dark);
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 700;
        }
        .cards {
            display: grid;
            gap: 14px;
        }
        .patient-card {
            display: grid;
            grid-template-columns: 64px 1fr auto;
            gap: 14px;
            align-items: center;
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 14px;
            background: #fff;
        }
        .patient-photo {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--green-pale);
        }
        .patient-photo.fallback {
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--green-pale);
            color: var(--green-dark);
            font-weight: 800;
            font-size: 18px;
        }
        .patient-name {
            font-size: 16px;
            font-weight: 800;
            color: var(--green-dark);
        }
        .patient-meta {
            font-size: 12px;
            color: var(--muted);
            margin-top: 4px;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        .tag {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.4px;
            text-transform: uppercase;
        }
        .tag.tele { background: #e3f2fd; color: #0d47a1; }
        .tag.eh { background: #e8f5e9; color: #1b5e20; }
        .card-actions {
            text-align: right;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .action-link {
            text-decoration: none;
            padding: 8px 12px;
            border-radius: 8px;
            background: var(--green-mid);
            color: white;
            font-weight: 700;
            font-size: 12px;
        }
        .action-link.provided {
            background: #0f5f3a;
        }
        .action-note {
            font-size: 11px;
            color: var(--green-dark);
            font-weight: 700;
        }
        .time-text {
            font-size: 11px;
            color: var(--muted);
        }
        .empty {
            text-align: center;
            padding: 18px;
            border: 1px dashed var(--border);
            border-radius: 12px;
            color: var(--muted);
            font-size: 14px;
        }
        @media (max-width: 900px) {
            .patient-card { grid-template-columns: 1fr; text-align: left; }
            .card-actions { text-align: left; flex-direction: row; flex-wrap: wrap; }
        }
    </style>
</head>
<body>
<nav class="navbar">
    <a href="<?= e($base) ?>/" class="navbar-brand">
        <img src="<?= e($base) ?>/public/assets/SRMS_TRUST_LOGO.png" alt="Logo" style="height:36px;width:auto;display:block;">
        <span>SRMS EHEALTH</span>
    </a>
    <div class="nav-right">
        <div class="pill">ID: <?= $pharm_id ?></div>
        <a href="pharmacy_dashboard.php" class="pill" style="text-decoration:none;">Dashboard</a>
        <a href="<?= htmlspecialchars(app_base_url()) ?>/about.php" class="pill" style="text-decoration:none;">ABOUT</a>
        <a href="<?= htmlspecialchars(app_base_url()) ?>/contact.php" class="pill" style="text-decoration:none;">CONTACT</a>
        <form method="POST" style="display:inline">
            <button type="submit" name="logout" class="logout-btn">Logout</button>
        </form>
    </div>
</nav>

<div class="page">
    <div class="title">
        <h1>Provide Medicine</h1>
        <p>Search diagnoses and dispense medicines with pricing and availability.</p>
    </div>

    <div class="card">
        <h2>Select eHealth Center Doctor</h2>
        <div id="alertBox" class="alert"></div>
        <div class="grid-2" id="doctorForm">
            <div class="field">
                <label for="doctorIdInput">Doctor ID</label>
                <input id="doctorIdInput" type="text" placeholder="e.g. EHD123" autocomplete="off">
            </div>
            <button class="btn" id="doctorSearchBtn" type="button">Find Doctor</button>
        </div>
        <div class="doctor-info" id="doctorInfo">
            <div>
                <div class="doctor-name" id="doctorName">Doctor Name</div>
                <div class="doctor-meta" id="doctorMeta">ID: -</div>
            </div>
            <button class="btn secondary" id="changeDoctorBtn" type="button">Change Doctor</button>
        </div>
    </div>

    <div class="card">
        <div class="section-title">
            <h2>Search Diagnosis</h2>
        </div>
        <div class="grid-2">
            <div class="field">
                <label for="searchInput">Patient ID / Name / Mobile</label>
                <input id="searchInput" type="text" placeholder="Search any diagnosis" autocomplete="off">
            </div>
            <button class="btn" id="searchBtn" type="button">Search</button>
        </div>
        <div style="margin-top:14px;" id="searchResults">
            <div class="empty">No search results yet.</div>
        </div>
    </div>

    <div class="card">
        <div class="section-title">
            <h2>Today Queue (Live)</h2>
            <div class="count-pill" id="queueCount">0</div>
        </div>
        <div id="queueResults">
            <div class="empty">Select a doctor to load today queue.</div>
        </div>
    </div>
</div>

<script>
const doctorInput = document.getElementById('doctorIdInput');
const doctorSearchBtn = document.getElementById('doctorSearchBtn');
const doctorInfo = document.getElementById('doctorInfo');
const doctorName = document.getElementById('doctorName');
const doctorMeta = document.getElementById('doctorMeta');
const changeDoctorBtn = document.getElementById('changeDoctorBtn');
const alertBox = document.getElementById('alertBox');

const searchInput = document.getElementById('searchInput');
const searchBtn = document.getElementById('searchBtn');
const searchResults = document.getElementById('searchResults');

const queueResults = document.getElementById('queueResults');
const queueCount = document.getElementById('queueCount');

let selectedDoctorId = null;
let queueTimer = null;

function showAlert(msg, type) {
    alertBox.textContent = msg;
    alertBox.className = `alert show ${type}`;
}

function clearAlert() {
    alertBox.textContent = '';
    alertBox.className = 'alert';
}

async function fetchJson(url, options = {}) {
    const res = await fetch(url, options);
    const data = await res.json().catch(() => ({}));
    if (!res.ok || data.success === false) {
        const err = data.error || 'Request failed.';
        throw new Error(err);
    }
    return data;
}

function storeDoctor(doctor) {
    selectedDoctorId = doctor.doctor_id;
    localStorage.setItem('pharmacy_doctor_id', selectedDoctorId);
    localStorage.setItem('pharmacy_doctor_name', doctor.full_name || '');
    localStorage.setItem('pharmacy_doctor_spec', doctor.specialization || '');

    doctorName.textContent = doctor.full_name || 'Doctor';
    doctorMeta.textContent = `ID: ${doctor.doctor_id}${doctor.specialization ? ' | ' + doctor.specialization : ''}`;

    doctorInfo.classList.add('show');
    document.getElementById('doctorForm').style.display = 'none';

    refreshQueue();
}

function clearDoctor() {
    selectedDoctorId = null;
    localStorage.removeItem('pharmacy_doctor_id');
    localStorage.removeItem('pharmacy_doctor_name');
    localStorage.removeItem('pharmacy_doctor_spec');
    doctorInfo.classList.remove('show');
    document.getElementById('doctorForm').style.display = 'grid';
    queueResults.innerHTML = '<div class="empty">Select a doctor to load today queue.</div>';
    queueCount.textContent = '0';
    if (queueTimer) clearInterval(queueTimer);
    queueTimer = null;
}

async function lookupDoctor() {
    const id = doctorInput.value.trim().toUpperCase();
    if (!id) { showAlert('Please enter a doctor ID.', 'error'); return; }
    clearAlert();
    doctorSearchBtn.disabled = true;
    doctorSearchBtn.textContent = 'Loading...';
    try {
        const data = await fetchJson(`provide_medicine.php?action=doctor_lookup&doctor_id=${encodeURIComponent(id)}`);
        storeDoctor(data.doctor);
    } catch (err) {
        showAlert(err.message || 'Doctor lookup failed.', 'error');
    } finally {
        doctorSearchBtn.disabled = false;
        doctorSearchBtn.textContent = 'Find Doctor';
    }
}

function initials(name) {
    if (!name) return 'NA';
    const parts = name.trim().split(/\s+/);
    const first = parts[0] ? parts[0][0].toUpperCase() : '';
    const second = parts[1] ? parts[1][0].toUpperCase() : '';
    return (first + second) || 'NA';
}

function formatDate(ts) {
    if (!ts) return '-';
    const clean = ts.replace(' ', 'T');
    const d = new Date(clean);
    if (isNaN(d.getTime())) return ts;
    return d.toLocaleString('en-IN', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

function esc(value) {
    return String(value ?? '').replace(/[&<>"']/g, (ch) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;'
    }[ch]));
}

function buildCard(row) {
    const isTele = row.source === 'teleconsult';
    const slipUrl = `pharmacy_slip.php?diagnosisId=${encodeURIComponent(row.diagnosis_id)}&type=${isTele ? 'teleconsult' : 'ehealth'}${isTele && row.source_table ? '&srcTable=' + encodeURIComponent(row.source_table) : ''}`;
    const actionLabel = row.action_label || 'Provide Diagnosis';
    const actionClass = row.dispensed ? 'action-link provided' : 'action-link';

    const photo = row.photo_url
        ? `<img class="patient-photo" src="${esc(row.photo_url)}" alt="Patient photo">`
        : `<div class="patient-photo fallback">${esc(initials(row.full_name))}</div>`;

    const teleDoctor = isTele && (row.telestudio_doctor_name || row.telestudio_doctor_id)
        ? `<div class="patient-meta"><span>TeleStudio: ${esc(row.telestudio_doctor_name || '-')}${row.telestudio_doctor_id ? ' (' + esc(row.telestudio_doctor_id) + ')' : ''}</span></div>`
        : '';
    const actionNote = row.dispensed
        ? '<div class="action-note">Open to provide diagnosis again</div>'
        : '';

    return `
        <div class="patient-card">
            ${photo}
            <div>
                <div class="patient-name">${esc(row.full_name || '-')}
                    <span class="tag ${isTele ? 'tele' : 'eh'}">${isTele ? 'Teleconsult' : 'Ehealth'}</span>
                </div>
                <div class="patient-meta">
                    <span>ID: ${esc(row.patient_id || '-')}</span>
                    <span>Phone: ${esc(row.mobile_no || '-')}</span>
                </div>
                <div class="patient-meta">
                    <span>Address: ${esc(row.address_full || '-')}</span>
                </div>
                <div class="patient-meta">
                    <span>eHealth Doctor: ${esc(row.ehealth_doctor_name || '-')}${row.ehealth_doctor_id ? ' (' + esc(row.ehealth_doctor_id) + ')' : ''}</span>
                </div>
                ${teleDoctor}
            </div>
            <div class="card-actions">
                <a class="${actionClass}" target="_blank" href="${slipUrl}">${esc(actionLabel)}</a>
                ${actionNote}
                <div class="time-text">${esc(formatDate(row.diagnosis_date))}</div>
            </div>
        </div>
    `;
}

function renderCards(container, rows) {
    if (!rows || rows.length === 0) {
        container.innerHTML = '<div class="empty">No records found.</div>';
        return;
    }
    container.innerHTML = `<div class="cards">${rows.map(buildCard).join('')}</div>`;
}

async function refreshQueue() {
    if (!selectedDoctorId) return;
    try {
        const data = await fetchJson(`provide_medicine.php?action=queue&doctor_id=${encodeURIComponent(selectedDoctorId)}`);
        queueCount.textContent = data.queue.length;
        renderCards(queueResults, data.queue);
    } catch (err) {
        queueResults.innerHTML = '<div class="empty">Failed to load queue.</div>';
    }

    if (!queueTimer) {
        queueTimer = setInterval(refreshQueue, 15000);
    }
}

async function runSearch() {
    const q = searchInput.value.trim();
    if (!q) {
        searchResults.innerHTML = '<div class="empty">Enter a search value.</div>';
        return;
    }
    searchBtn.disabled = true;
    searchBtn.textContent = 'Searching...';
    try {
        const data = await fetchJson(`provide_medicine.php?action=search&q=${encodeURIComponent(q)}`);
        renderCards(searchResults, data.results);
    } catch (err) {
        searchResults.innerHTML = '<div class="empty">Search failed.</div>';
    } finally {
        searchBtn.disabled = false;
        searchBtn.textContent = 'Search';
    }
}

doctorSearchBtn.addEventListener('click', lookupDoctor);
changeDoctorBtn.addEventListener('click', clearDoctor);
searchBtn.addEventListener('click', runSearch);
searchInput.addEventListener('keydown', (e) => { if (e.key === 'Enter') runSearch(); });
window.addEventListener('storage', (e) => {
    if (e.key !== 'pharmacy_dispense_updated') return;
    if (selectedDoctorId) {
        refreshQueue();
    }
    if (searchInput.value.trim()) {
        runSearch();
    }
});

window.addEventListener('DOMContentLoaded', () => {
    const savedId = localStorage.getItem('pharmacy_doctor_id');
    if (savedId) {
        doctorInput.value = savedId;
        lookupDoctor();
    }
});
</script>
</body>
</html>
