<?php
require_once __DIR__ . '/../../config/config.php';
require_role('admin', 'admin_login.php');

$db = db();

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

function pick_column(array $cols, array $candidates): string {
    foreach ($candidates as $c) {
        if (in_array($c, $cols, true)) return $c;
    }
    return '';
}

function table_exists(mysqli $db, string $table): bool {
    $safe = $db->real_escape_string($table);
    $res = $db->query("SHOW TABLES LIKE '{$safe}'");
    if (!$res) return false;
    $exists = $res->num_rows > 0;
    $res->free();
    return $exists;
}

$patientCols = table_columns($db, 'patients');
$patientDateCol = pick_column($patientCols, ['created_at', 'registered_at', 'created_on', 'created_date', 'registration_date', 'reg_date']);
$patientAmountCol = pick_column($patientCols, ['payment_amount', 'amount']);
$patientExecIdCol = pick_column($patientCols, ['registered_by_exec_id']);
$patientExecNameCol = pick_column($patientCols, ['registered_by_exec_name']);

$teleExists = table_exists($db, 'teleconsult_sessions');
$teleCols = $teleExists ? table_columns($db, 'teleconsult_sessions') : [];
$telePayMethodCol = pick_column($teleCols, ['payment_method']);
$telePayAmountCol = pick_column($teleCols, ['payment_amount']);
$teleCollectedAtCol = pick_column($teleCols, ['payment_collected_at', 'ended_at', 'updated_at', 'created_at', 'started_at']);
$teleCollectedRoleCol = pick_column($teleCols, ['payment_collected_by_role']);
$teleCollectedIdCol = pick_column($teleCols, ['payment_collected_by_id']);
$teleCollectedNameCol = pick_column($teleCols, ['payment_collected_by_name']);

$pharmacyExists = table_exists($db, 'pharmacy_dispense');
$pharmacyCols = $pharmacyExists ? table_columns($db, 'pharmacy_dispense') : [];
$pharmacyAmountCol = pick_column($pharmacyCols, ['total_amount']);
$pharmacyStaffIdCol = pick_column($pharmacyCols, ['pharmacy_staff_id']);
$pharmacyStaffNameCol = pick_column($pharmacyCols, ['pharmacy_staff_name']);
$pharmacyDateCol = pick_column($pharmacyCols, ['paid_at', 'created_at', 'updated_at']);
$pharmacyDateExpr = '';
if ($pharmacyDateCol !== '') {
    if (in_array('paid_at', $pharmacyCols, true)) {
        $dateFallbacks = ['paid_at'];
        if (in_array('created_at', $pharmacyCols, true)) $dateFallbacks[] = 'created_at';
        if (in_array('updated_at', $pharmacyCols, true)) $dateFallbacks[] = 'updated_at';
        $pharmacyDateExpr = 'COALESCE(' . implode(', ', $dateFallbacks) . ')';
    } else {
        $pharmacyDateExpr = $pharmacyDateCol;
    }
}

// Totals
$totalPatients = (int)($db->query('SELECT COUNT(*) AS c FROM patients')->fetch_assoc()['c'] ?? 0);
$totalEhealthDoctors = (int)($db->query('SELECT COUNT(*) AS c FROM ehealth_center_doctors')->fetch_assoc()['c'] ?? 0);
$totalTelestudioDoctors = (int)($db->query('SELECT COUNT(*) AS c FROM telestudio_doctors')->fetch_assoc()['c'] ?? 0);
$totalExecutives = (int)($db->query('SELECT COUNT(*) AS c FROM executives')->fetch_assoc()['c'] ?? 0);

// Date filter
$today = date('Y-m-d');
$dateFrom = trim((string)($_GET['from'] ?? $today));
$dateTo = trim((string)($_GET['to'] ?? $today));
if ($dateFrom === '') $dateFrom = $today;
if ($dateTo === '') $dateTo = $today;

// Overall revenue (all time)
$regTotal = 0.0;
if ($patientAmountCol !== '') {
    $regTotal = (float)($db->query("SELECT COALESCE(SUM({$patientAmountCol}),0) AS t FROM patients")->fetch_assoc()['t'] ?? 0);
}
$teleTotal = 0.0;
if ($teleExists && $telePayAmountCol !== '' && $telePayMethodCol !== '') {
    $teleTotal = (float)($db->query("SELECT COALESCE(SUM({$telePayAmountCol}),0) AS t FROM teleconsult_sessions WHERE {$telePayMethodCol} IS NOT NULL")->fetch_assoc()['t'] ?? 0);
}
$pharmacyTotal = 0.0;
if ($pharmacyExists && $pharmacyAmountCol !== '') {
    $pharmacyTotal = (float)($db->query("SELECT COALESCE(SUM({$pharmacyAmountCol}),0) AS t FROM pharmacy_dispense")->fetch_assoc()['t'] ?? 0);
}
$overallRevenue = $regTotal + $teleTotal + $pharmacyTotal;

// Daily revenue within filter
$dailyMap = [];
$dailyTotal = 0.0;
$filteredRegTotal = 0.0;
$filteredTeleTotal = 0.0;
$filteredPharmacyTotal = 0.0;

if ($patientDateCol !== '' && $patientAmountCol !== '') {
    $st = $db->prepare(
        "SELECT DATE({$patientDateCol}) AS day, COALESCE(SUM({$patientAmountCol}),0) AS total
         FROM patients
         WHERE DATE({$patientDateCol}) BETWEEN ? AND ?
         GROUP BY DATE({$patientDateCol})"
    );
    if ($st) {
        $st->bind_param('ss', $dateFrom, $dateTo);
        $st->execute();
        $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();
        foreach ($rows as $r) {
            $day = (string)($r['day'] ?? '');
            if ($day === '') continue;
            $amt = (float)($r['total'] ?? 0);
            if (!isset($dailyMap[$day])) {
                $dailyMap[$day] = ['registration' => 0.0, 'telemedicine' => 0.0, 'pharmacy' => 0.0];
            }
            $dailyMap[$day]['registration'] += $amt;
            $filteredRegTotal += $amt;
        }
    }
}

if ($teleExists && $telePayAmountCol !== '' && $telePayMethodCol !== '' && $teleCollectedAtCol !== '') {
    $ts = $db->prepare(
        "SELECT DATE({$teleCollectedAtCol}) AS day, COALESCE(SUM({$telePayAmountCol}),0) AS total
         FROM teleconsult_sessions
         WHERE {$telePayMethodCol} IS NOT NULL
           AND {$teleCollectedAtCol} IS NOT NULL
           AND DATE({$teleCollectedAtCol}) BETWEEN ? AND ?
         GROUP BY DATE({$teleCollectedAtCol})"
    );
    if ($ts) {
        $ts->bind_param('ss', $dateFrom, $dateTo);
        $ts->execute();
        $trows = $ts->get_result()->fetch_all(MYSQLI_ASSOC);
        $ts->close();
        foreach ($trows as $r) {
            $day = (string)($r['day'] ?? '');
            if ($day === '') continue;
            $amt = (float)($r['total'] ?? 0);
            if (!isset($dailyMap[$day])) {
                $dailyMap[$day] = ['registration' => 0.0, 'telemedicine' => 0.0, 'pharmacy' => 0.0];
            }
            $dailyMap[$day]['telemedicine'] += $amt;
            $filteredTeleTotal += $amt;
        }
    }
}

if ($pharmacyExists && $pharmacyAmountCol !== '' && $pharmacyDateExpr !== '') {
    $ps = $db->prepare(
        "SELECT DATE({$pharmacyDateExpr}) AS day, COALESCE(SUM({$pharmacyAmountCol}),0) AS total
         FROM pharmacy_dispense
         WHERE {$pharmacyDateExpr} IS NOT NULL
           AND DATE({$pharmacyDateExpr}) BETWEEN ? AND ?
         GROUP BY DATE({$pharmacyDateExpr})"
    );
    if ($ps) {
        $ps->bind_param('ss', $dateFrom, $dateTo);
        $ps->execute();
        $prows = $ps->get_result()->fetch_all(MYSQLI_ASSOC);
        $ps->close();
        foreach ($prows as $r) {
            $day = (string)($r['day'] ?? '');
            if ($day === '') continue;
            $amt = (float)($r['total'] ?? 0);
            if (!isset($dailyMap[$day])) {
                $dailyMap[$day] = ['registration' => 0.0, 'telemedicine' => 0.0, 'pharmacy' => 0.0];
            }
            $dailyMap[$day]['pharmacy'] += $amt;
            $filteredPharmacyTotal += $amt;
        }
    }
}

ksort($dailyMap);
foreach ($dailyMap as $day => $parts) {
    $dailyMap[$day]['total'] = (float)$parts['registration'] + (float)$parts['telemedicine'] + (float)$parts['pharmacy'];
    $dailyTotal += $dailyMap[$day]['total'];
}

// Executive collections within filter
$execTotals = [];
if ($patientDateCol !== '' && $patientAmountCol !== '' && $patientExecIdCol !== '') {
    $nameCol = $patientExecNameCol !== '' ? $patientExecNameCol : $patientExecIdCol;
    $es = $db->prepare(
        "SELECT {$patientExecIdCol} AS exec_id, MAX({$nameCol}) AS exec_name,
                COALESCE(SUM({$patientAmountCol}),0) AS total
         FROM patients
         WHERE DATE({$patientDateCol}) BETWEEN ? AND ?
         GROUP BY {$patientExecIdCol}"
    );
    if ($es) {
        $es->bind_param('ss', $dateFrom, $dateTo);
        $es->execute();
        $execRows = $es->get_result()->fetch_all(MYSQLI_ASSOC);
        $es->close();
        foreach ($execRows as $r) {
            $id = (string)($r['exec_id'] ?? '');
            if ($id === '') continue;
            $execTotals[$id] = [
                'name' => (string)($r['exec_name'] ?? $id),
                'registration' => (float)$r['total'],
                'telemedicine' => 0.0,
                'total' => (float)$r['total'],
            ];
        }
    }
}

if ($teleExists && $telePayAmountCol !== '' && $teleCollectedAtCol !== '' && $teleCollectedRoleCol !== '' && $teleCollectedIdCol !== '') {
    $nameCol = $teleCollectedNameCol !== '' ? $teleCollectedNameCol : $teleCollectedIdCol;
    $ts = $db->prepare(
        "SELECT {$teleCollectedIdCol} AS exec_id, MAX({$nameCol}) AS exec_name,
                COALESCE(SUM({$telePayAmountCol}),0) AS total
         FROM teleconsult_sessions
         WHERE {$teleCollectedRoleCol} = 'executive'
           AND {$teleCollectedAtCol} IS NOT NULL
           AND DATE({$teleCollectedAtCol}) BETWEEN ? AND ?
         GROUP BY {$teleCollectedIdCol}"
    );
    if ($ts) {
        $ts->bind_param('ss', $dateFrom, $dateTo);
        $ts->execute();
        $rows = $ts->get_result()->fetch_all(MYSQLI_ASSOC);
        $ts->close();
        foreach ($rows as $r) {
            $id = (string)($r['exec_id'] ?? '');
            if ($id === '') continue;
            if (!isset($execTotals[$id])) {
                $execTotals[$id] = [
                    'name' => (string)($r['exec_name'] ?? $id),
                    'registration' => 0.0,
                    'telemedicine' => 0.0,
                    'total' => 0.0,
                ];
            }
            $execTotals[$id]['telemedicine'] += (float)$r['total'];
            $execTotals[$id]['total'] = $execTotals[$id]['registration'] + $execTotals[$id]['telemedicine'];
        }
    }
}

// Doctor collections within filter (teleconsult collected by doctor)
$docTotals = [];
if ($teleExists && $telePayAmountCol !== '' && $teleCollectedAtCol !== '' && $teleCollectedRoleCol !== '' && $teleCollectedIdCol !== '') {
    $nameCol = $teleCollectedNameCol !== '' ? $teleCollectedNameCol : $teleCollectedIdCol;
    $ds = $db->prepare(
        "SELECT {$teleCollectedIdCol} AS doctor_id, MAX({$nameCol}) AS doctor_name,
                COALESCE(SUM({$telePayAmountCol}),0) AS total
         FROM teleconsult_sessions
         WHERE {$teleCollectedRoleCol} = 'doctor'
           AND {$teleCollectedAtCol} IS NOT NULL
           AND DATE({$teleCollectedAtCol}) BETWEEN ? AND ?
         GROUP BY {$teleCollectedIdCol}"
    );
    if ($ds) {
        $ds->bind_param('ss', $dateFrom, $dateTo);
        $ds->execute();
        $rows = $ds->get_result()->fetch_all(MYSQLI_ASSOC);
        $ds->close();
        foreach ($rows as $r) {
            $id = (string)($r['doctor_id'] ?? '');
            if ($id === '') continue;
            $docTotals[$id] = [
                'name' => (string)($r['doctor_name'] ?? $id),
                'total' => (float)$r['total'],
            ];
        }
    }
}

$pharmacyStaffTotals = [];
if ($pharmacyExists && $pharmacyAmountCol !== '' && $pharmacyDateExpr !== '' && $pharmacyStaffIdCol !== '') {
    $nameCol = $pharmacyStaffNameCol !== '' ? $pharmacyStaffNameCol : $pharmacyStaffIdCol;
    $ps = $db->prepare(
        "SELECT {$pharmacyStaffIdCol} AS staff_id, MAX({$nameCol}) AS staff_name,
                COALESCE(SUM({$pharmacyAmountCol}),0) AS total
         FROM pharmacy_dispense
         WHERE {$pharmacyStaffIdCol} IS NOT NULL
           AND {$pharmacyDateExpr} IS NOT NULL
           AND DATE({$pharmacyDateExpr}) BETWEEN ? AND ?
         GROUP BY {$pharmacyStaffIdCol}"
    );
    if ($ps) {
        $ps->bind_param('ss', $dateFrom, $dateTo);
        $ps->execute();
        $rows = $ps->get_result()->fetch_all(MYSQLI_ASSOC);
        $ps->close();
        foreach ($rows as $r) {
            $id = (string)($r['staff_id'] ?? '');
            if ($id === '') continue;
            $pharmacyStaffTotals[$id] = [
                'name' => (string)($r['staff_name'] ?? $id),
                'total' => (float)$r['total'],
            ];
        }
    }
}

uasort($execTotals, static fn(array $a, array $b): int => ($b['total'] <=> $a['total']));
uasort($docTotals, static fn(array $a, array $b): int => ($b['total'] <=> $a['total']));
uasort($pharmacyStaffTotals, static fn(array $a, array $b): int => ($b['total'] <=> $a['total']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Analysis - eHealth</title>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        :root {
            --bg-1:#e9f6ef;
            --bg-2:#d4efe0;
            --ink:#113528;
            --muted:#4f6a5d;
            --card:#ffffff;
            --accent:#1b7a5a;
            --accent-dark:#0f5c45;
            --accent-soft:#dff2e8;
            --border:#cfe6d7;
            --gold:#f0b429;
        }
        body {
            font-family:'Plus Jakarta Sans', sans-serif;
            background:
                radial-gradient(1200px 400px at 10% -10%, rgba(27,122,90,0.20), transparent 60%),
                radial-gradient(900px 360px at 90% 0%, rgba(240,180,41,0.18), transparent 55%),
                linear-gradient(180deg, var(--bg-1), var(--bg-2));
            min-height:100vh;
            color:var(--ink);
        }
        body::before {
            content:'';
            position:fixed;
            inset:0;
            background-image: linear-gradient(135deg, rgba(255,255,255,0.16) 0%, rgba(255,255,255,0) 40%),
                              radial-gradient(rgba(17,53,40,0.08) 1px, transparent 1px);
            background-size: 100% 100%, 18px 18px;
            opacity:0.5;
            pointer-events:none;
        }
        .nav {
            background: linear-gradient(135deg, var(--accent-dark), var(--accent));
            color:#fff;
            height:62px;
            padding:0 34px;
            display:flex;
            align-items:center;
            justify-content:space-between;
            box-shadow:0 10px 24px rgba(0,0,0,.18);
            position:sticky;
            top:0;
            z-index:10;
        }
        .nav .brand {
            font-weight:800;
            letter-spacing:1.2px;
            display:flex;
            flex-direction:column;
            line-height:1.05;
        }
        .nav .brand small { font-size:11px; opacity:.8; font-weight:600; letter-spacing:.6px; }
        .nav a {
            color:#fff;
            text-decoration:none;
            font-weight:700;
            padding:8px 14px;
            border-radius:999px;
            background:rgba(255,255,255,0.14);
            border:1px solid rgba(255,255,255,0.18);
            transition:transform .2s ease, background .2s ease;
        }
        .nav a:hover { transform:translateY(-1px); background:rgba(255,255,255,0.22); }
        .nav-actions {
            display:flex;
            align-items:center;
            gap:10px;
            flex-wrap:wrap;
            justify-content:flex-end;
        }
        .page { max-width:1240px; margin:0 auto; padding:34px 20px 70px; position:relative; }
        .title {
            font-family:'Fraunces', serif;
            font-size:34px;
            font-weight:700;
            color:var(--ink);
            text-align:center;
            margin-bottom:6px;
            letter-spacing:.4px;
        }
        .subtitle {
            text-align:center;
            color:var(--muted);
            font-size:14px;
            margin-bottom:22px;
        }
        .cards {
            display:grid;
            grid-template-columns:repeat(auto-fit,minmax(220px,1fr));
            gap:18px;
            margin-bottom:22px;
        }
        .card {
            background:var(--card);
            border-radius:16px;
            padding:18px 18px 16px;
            box-shadow:0 12px 24px rgba(17,53,40,.08);
            text-decoration:none;
            color:inherit;
            border:1px solid rgba(207,230,215,0.7);
            position:relative;
            overflow:hidden;
            transform:translateY(6px);
            opacity:0;
            animation:rise .55s ease forwards;
            display:flex;
            flex-direction:column;
            gap:8px;
        }
        .cards .card:nth-child(1) { animation-delay:.05s; }
        .cards .card:nth-child(2) { animation-delay:.12s; }
        .cards .card:nth-child(3) { animation-delay:.18s; }
        .cards .card:nth-child(4) { animation-delay:.24s; }
        .cards .card:nth-child(5) { animation-delay:.30s; }
        .cards .card:nth-child(6) { animation-delay:.36s; }
        .cards .card:nth-child(7) { animation-delay:.42s; }
        .cards .card:nth-child(8) { animation-delay:.48s; }
        .card:hover {
            transform:translateY(-4px);
            box-shadow:0 18px 32px rgba(17,53,40,.14);
        }
        .card h3 { font-size:13px; color:var(--muted); margin-bottom:6px; text-transform:uppercase; letter-spacing:.7px; }
        .card .value { font-size:26px; font-weight:800; color:var(--ink); }
        .card-cta {
            align-self:flex-start;
            margin-top:6px;
            padding:6px 12px;
            border-radius:999px;
            background:linear-gradient(135deg, var(--accent), var(--accent-dark));
            color:#fff;
            font-size:11px;
            font-weight:800;
            letter-spacing:.4px;
            text-transform:uppercase;
        }
        .filters {
            background:rgba(255,255,255,0.9);
            border-radius:14px;
            padding:14px 16px;
            display:flex;
            gap:12px;
            align-items:center;
            flex-wrap:wrap;
            box-shadow:0 12px 22px rgba(17,53,40,.08);
            margin-bottom:18px;
            border:1px solid rgba(207,230,215,0.7);
            backdrop-filter: blur(8px);
            animation:rise .6s ease .35s forwards;
            opacity:0;
            transform:translateY(6px);
        }
        .filters label { font-size:12px; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:.6px; }
        .filters input {
            padding:9px 12px;
            border:1px solid var(--border);
            border-radius:10px;
            font-size:13px;
            background:#fff;
        }
        .filters button {
            padding:9px 16px;
            border:none;
            border-radius:10px;
            background:linear-gradient(135deg, var(--accent), var(--accent-dark));
            color:#fff;
            font-weight:800;
            cursor:pointer;
            letter-spacing:.3px;
        }
        .section {
            background:var(--card);
            border-radius:16px;
            padding:16px 18px;
            box-shadow:0 12px 24px rgba(17,53,40,.08);
            margin-bottom:18px;
            border:1px solid rgba(207,230,215,0.7);
            animation:rise .6s ease forwards;
        }
        .section:nth-of-type(1) { animation-delay:.42s; opacity:0; transform:translateY(6px); }
        .section:nth-of-type(2) { animation-delay:.48s; opacity:0; transform:translateY(6px); }
        .section:nth-of-type(3) { animation-delay:.54s; opacity:0; transform:translateY(6px); }
        .section:nth-of-type(4) { animation-delay:.60s; opacity:0; transform:translateY(6px); }
        .section h4 { font-size:16px; color:var(--ink); margin-bottom:10px; letter-spacing:.2px; }
        table { width:100%; border-collapse:collapse; font-size:13px; }
        th, td { padding:9px 8px; border-bottom:1px solid #e2eee6; text-align:left; }
        th {
            font-size:11px;
            text-transform:uppercase;
            color:var(--muted);
            letter-spacing:.6px;
            background:var(--accent-soft);
        }
        tr:nth-child(even) td { background:rgba(240,247,242,0.55); }
        .muted { color:var(--muted); font-size:12px; }
        @keyframes rise { to { opacity:1; transform:translateY(0); } }
        @media (max-width: 720px) {
            .filters { flex-direction:column; align-items:flex-start; }
            .nav { padding:0 18px; }
            .nav-actions { justify-content:flex-start; }
            .title { font-size:28px; }
        }
    </style>
</head>
<body>
    <div class="nav">
        <div class="brand">
            SRMS EHEALTH
            <small>Admin Analysis</small>
        </div>
        <div class="nav-actions">
            <a href="website_flow.php">Website Flow</a>
            <a href="admin_dashboard.php">Back to Dashboard</a>
        </div>
    </div>

    <div class="page">
        <div class="title">Platform Analysis</div>
        <div class="subtitle">A quick, clear view of volumes and collections</div>

        <div class="cards">
            <a class="card" href="analysis_patients.php">
                <h3>Patients</h3>
                <div class="value"><?= $totalPatients ?></div>
                <span class="card-cta">View Details</span>
            </a>
            <a class="card" href="analysis_ehealth_doctors.php">
                <h3>eHealth Doctors</h3>
                <div class="value"><?= $totalEhealthDoctors ?></div>
                <span class="card-cta">View Details</span>
            </a>
            <a class="card" href="analysis_telestudio_doctors.php">
                <h3>Telestudio Doctors</h3>
                <div class="value"><?= $totalTelestudioDoctors ?></div>
                <span class="card-cta">View Details</span>
            </a>
            <a class="card" href="analysis_executives.php">
                <h3>Executives</h3>
                <div class="value"><?= $totalExecutives ?></div>
                <span class="card-cta">View Details</span>
            </a>
            <div class="card">
                <h3>Registration Revenue</h3>
                <div class="value">INR <?= number_format($regTotal, 2) ?></div>
            </div>
            <div class="card">
                <h3>Telemedicine Revenue</h3>
                <div class="value">INR <?= number_format($teleTotal, 2) ?></div>
            </div>
            <div class="card">
                <h3>Pharmacy Revenue</h3>
                <div class="value">INR <?= number_format($pharmacyTotal, 2) ?></div>
            </div>
            <div class="card">
                <h3>Overall Revenue</h3>
                <div class="value">INR <?= number_format($overallRevenue, 2) ?></div>
            </div>
        </div>

        <form class="filters" method="GET">
            <label>From</label>
            <input type="date" name="from" value="<?= htmlspecialchars($dateFrom) ?>">
            <label>To</label>
            <input type="date" name="to" value="<?= htmlspecialchars($dateTo) ?>">
            <button type="submit">Filter</button>
            <span class="muted">Showing daily revenue and collections for the selected range.</span>
        </form>

        <div class="section">
            <h4>Daily Revenue (Selected Range)</h4>
            <table>
                <thead>
                    <tr><th>Date</th><th>Registration</th><th>Telemedicine</th><th>Pharmacy</th><th>Total</th></tr>
                </thead>
                <tbody>
                    <?php if (empty($dailyMap)): ?>
                        <tr><td colspan="5" class="muted">No collections for this range.</td></tr>
                    <?php else: ?>
                        <?php foreach ($dailyMap as $day => $parts): ?>
                            <tr>
                                <td><?= htmlspecialchars($day) ?></td>
                                <td>INR <?= number_format((float)$parts['registration'], 2) ?></td>
                                <td>INR <?= number_format((float)$parts['telemedicine'], 2) ?></td>
                                <td>INR <?= number_format((float)$parts['pharmacy'], 2) ?></td>
                                <td>INR <?= number_format((float)$parts['total'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            <div class="muted" style="margin-top:8px;">
                Registration: INR <?= number_format($filteredRegTotal, 2) ?> |
                Telemedicine: INR <?= number_format($filteredTeleTotal, 2) ?> |
                Pharmacy: INR <?= number_format($filteredPharmacyTotal, 2) ?> |
                Range total: INR <?= number_format($dailyTotal, 2) ?>
            </div>
        </div>

        <div class="section">
            <h4>Executive Collections (Selected Range)</h4>
            <table>
                <thead><tr><th>Executive</th><th>Registration Charges</th><th>Telemedicine Charges</th><th>Total</th></tr></thead>
                <tbody>
                    <?php if (empty($execTotals)): ?>
                        <tr><td colspan="4" class="muted">No executive collections.</td></tr>
                    <?php else: ?>
                        <?php foreach ($execTotals as $id => $ex): ?>
                            <tr>
                                <td><?= htmlspecialchars($ex['name']) ?> (<?= htmlspecialchars($id) ?>)</td>
                                <td>INR <?= number_format((float)$ex['registration'], 2) ?></td>
                                <td>INR <?= number_format((float)$ex['telemedicine'], 2) ?></td>
                                <td>INR <?= number_format((float)$ex['total'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="section">
            <h4>eHealth Doctor Collections (Selected Range)</h4>
            <table>
                <thead><tr><th>Doctor</th><th>Amount</th></tr></thead>
                <tbody>
                    <?php if (empty($docTotals)): ?>
                        <tr><td colspan="2" class="muted">No doctor collections.</td></tr>
                    <?php else: ?>
                        <?php foreach ($docTotals as $id => $doc): ?>
                            <tr><td><?= htmlspecialchars($doc['name']) ?> (<?= htmlspecialchars($id) ?>)</td><td>INR <?= number_format($doc['total'], 2) ?></td></tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="section">
            <h4>Pharmacy Staff Collections (Selected Range)</h4>
            <table>
                <thead><tr><th>Pharmacy Staff</th><th>Amount</th></tr></thead>
                <tbody>
                    <?php if (empty($pharmacyStaffTotals)): ?>
                        <tr><td colspan="2" class="muted">No pharmacy collections.</td></tr>
                    <?php else: ?>
                        <?php foreach ($pharmacyStaffTotals as $id => $staff): ?>
                            <tr><td><?= htmlspecialchars($staff['name']) ?> (<?= htmlspecialchars($id) ?>)</td><td>INR <?= number_format((float)$staff['total'], 2) ?></td></tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
