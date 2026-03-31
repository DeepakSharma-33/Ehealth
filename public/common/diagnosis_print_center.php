<?php
require_once __DIR__ . '/../../config/config.php';
start_session();

if (!is_logged_in()) {
    header('Location: ' . app_base_url() . '/index.php');
    exit;
}

$role = (string)get_session_role();
if (!in_array($role, ['doctor', 'executive'], true)) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$db = db();
$base = app_base_url();

$searchPatientId = strtoupper(trim((string)($_GET['patient_id'] ?? '')));
$searchMobileRaw = trim((string)($_GET['mobile_no'] ?? ''));
$searchMobileDigits = preg_replace('/\D+/', '', $searchMobileRaw);
$hasSearch = ($searchPatientId !== '' || $searchMobileDigits !== '');

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

function bind_dynamic(mysqli_stmt $stmt, string $types, array $params): void {
    if ($types === '' || empty($params)) return;
    $refs = [];
    foreach ($params as $k => $v) {
        $refs[$k] = &$params[$k];
    }
    array_unshift($refs, $types);
    call_user_func_array([$stmt, 'bind_param'], $refs);
}

function fetch_ehealth_rows(mysqli $db, bool $hasSearch, string $patientId, string $mobileDigits): array {
    $where = [];
    $types = '';
    $params = [];

    if (!$hasSearch) {
        $where[] = 'DATE(d.diagnosis_date) = CURDATE()';
    }

    if ($patientId !== '') {
        $where[] = 'p.patient_id = ?';
        $types .= 's';
        $params[] = $patientId;
    }

    if ($mobileDigits !== '') {
        $where[] = "REPLACE(REPLACE(REPLACE(COALESCE(p.mobile_no,''), ' ', ''), '-', ''), '+', '') LIKE ?";
        $types .= 's';
        $params[] = '%' . $mobileDigits . '%';
    }

    $sql = "SELECT
                d.id AS diagnosis_id,
                'ehealth' AS source,
                'diagnosis_records' AS source_table,
                d.diagnosis_date,
                p.patient_id,
                p.full_name,
                p.mobile_no,
                COALESCE(ed.full_name, d.doctor_id, '') AS doctor_name
            FROM diagnosis_records d
            INNER JOIN patients p ON p.patient_id = d.patient_id
            LEFT JOIN ehealth_center_doctors ed ON ed.doctor_id = d.doctor_id";

    if (!empty($where)) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    $sql .= ' ORDER BY d.diagnosis_date DESC';
    if (!$hasSearch) {
        $sql .= ' LIMIT 500';
    }

    $stmt = $db->prepare($sql);
    if (!$stmt) return [];
    bind_dynamic($stmt, $types, $params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function fetch_tele_rows(mysqli $db, string $teleTable, bool $hasSearch, string $patientId, string $mobileDigits): array {

    $where = [];
    $types = '';
    $params = [];

    if (!$hasSearch) {
        $where[] = 'DATE(td.diagnosis_date) = CURDATE()';
    }

    if ($patientId !== '') {
        $where[] = 'p.patient_id = ?';
        $types .= 's';
        $params[] = $patientId;
    }

    if ($mobileDigits !== '') {
        $where[] = "REPLACE(REPLACE(REPLACE(COALESCE(p.mobile_no,''), ' ', ''), '-', ''), '+', '') LIKE ?";
        $types .= 's';
        $params[] = '%' . $mobileDigits . '%';
    }

    $sql = "SELECT
                td.id AS diagnosis_id,
                'teleconsult' AS source,
                '{$teleTable}' AS source_table,
                td.diagnosis_date,
                p.patient_id,
                p.full_name,
                p.mobile_no,
                COALESCE(NULLIF(td.telestudio_doctor_name, ''), tsd.full_name, td.telestudio_doctor_id, '') AS doctor_name
            FROM {$teleTable} td
            INNER JOIN patients p ON p.patient_id = td.patient_id
            LEFT JOIN telestudio_doctors tsd ON tsd.doctor_id = td.telestudio_doctor_id";

    if (!empty($where)) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    $sql .= ' ORDER BY td.diagnosis_date DESC';
    if (!$hasSearch) {
        $sql .= ' LIMIT 500';
    }

    $stmt = $db->prepare($sql);
    if (!$stmt) return [];
    bind_dynamic($stmt, $types, $params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

$teleTables = existing_tables($db, ['telestudio_diagnosis', 'teleconsult_diagnosis']);
$rows = fetch_ehealth_rows($db, $hasSearch, $searchPatientId, $searchMobileDigits);
foreach ($teleTables as $tbl) {
    $rows = array_merge($rows, fetch_tele_rows($db, $tbl, $hasSearch, $searchPatientId, $searchMobileDigits));
}

usort($rows, static function(array $a, array $b): int {
    return strcmp((string)$b['diagnosis_date'], (string)$a['diagnosis_date']);
});

$pageTitle = 'Print Diagnosis';
$backUrl = $role === 'doctor'
    ? ($base . '/public/ehealth_center_doctor/doctor_dashboard.php')
    : ($base . '/public/executive/executive_dashboard.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: Arial, sans-serif;
            background: #eef6ef;
            color: #153018;
            min-height: 100vh;
        }
        .page {
            max-width: 1100px;
            margin: 0 auto;
            padding: 18px 16px 28px;
        }
        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 14px;
        }
        .topbar a {
            color: #1f6f32;
            text-decoration: none;
            font-weight: 700;
            font-size: 14px;
        }
        .topbar a:hover { text-decoration: underline; }
        .title {
            font-size: 24px;
            font-weight: 800;
            margin-bottom: 4px;
        }
        .subtitle {
            color: #4f6653;
            font-size: 13px;
        }
        .search-card {
            background: #fff;
            border: 1px solid #d7e6d8;
            border-radius: 10px;
            padding: 12px;
            margin: 14px 0;
        }
        .search-row {
            display: grid;
            grid-template-columns: 1fr 1fr auto auto;
            gap: 10px;
            align-items: end;
        }
        .field label {
            display: block;
            font-size: 11px;
            font-weight: 700;
            color: #2d5635;
            margin-bottom: 4px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }
        .field input {
            width: 100%;
            border: 1px solid #bfd5c1;
            border-radius: 7px;
            padding: 9px 10px;
            font-size: 14px;
            outline: none;
        }
        .field input:focus { border-color: #2f7d36; }
        .btn {
            border: none;
            border-radius: 7px;
            padding: 10px 14px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            white-space: nowrap;
        }
        .btn-search {
            background: #1f6f32;
            color: #fff;
        }
        .btn-today {
            background: #dff0e1;
            color: #1f6f32;
        }
        .table-wrap {
            background: #fff;
            border: 1px solid #d7e6d8;
            border-radius: 10px;
            overflow: hidden;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        th, td {
            border-bottom: 1px solid #edf3ee;
            padding: 9px 10px;
            text-align: left;
            vertical-align: top;
        }
        th {
            background: #f4f9f4;
            color: #1f6f32;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        tr:last-child td { border-bottom: none; }
        .tag {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.4px;
            text-transform: uppercase;
        }
        .tag.eh { background: #e8f5e9; color: #1f6f32; }
        .tag.tele { background: #e3f2fd; color: #0d47a1; }
        .print-link {
            text-decoration: none;
            background: #1f6f32;
            color: #fff;
            padding: 6px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 700;
            display: inline-block;
        }
        .empty {
            padding: 24px;
            text-align: center;
            color: #5d7260;
            font-size: 14px;
        }
        @media (max-width: 900px) {
            .search-row { grid-template-columns: 1fr 1fr; }
            .search-row .btn { width: 100%; }
        }
        @media (max-width: 700px) {
            .search-row { grid-template-columns: 1fr; }
            .table-wrap { overflow-x: auto; }
            table { min-width: 760px; }
        }
    </style>
</head>
<body>
<div class="page">
    <div class="topbar">
        <a href="<?= e($backUrl) ?>">Back to Dashboard</a>
        <div>
            <div class="title">Print Diagnosis</div>
            <div class="subtitle"><?= $hasSearch ? 'Search results' : "Showing today's diagnoses" ?> | Total: <?= count($rows) ?></div>
        </div>
    </div>

    <form method="GET" class="search-card">
        <div class="search-row">
            <div class="field">
                <label for="patient_id">Patient ID</label>
                <input id="patient_id" name="patient_id" value="<?= e($searchPatientId) ?>" placeholder="e.g. P202603001">
            </div>
            <div class="field">
                <label for="mobile_no">Mobile No</label>
                <input id="mobile_no" name="mobile_no" value="<?= e($searchMobileRaw) ?>" placeholder="10-digit mobile">
            </div>
            <button class="btn btn-search" type="submit">Search</button>
            <a class="btn btn-today" href="<?= e($base . '/public/common/diagnosis_print_center.php') ?>" style="text-decoration:none;display:inline-flex;align-items:center;justify-content:center;">Today</a>
        </div>
    </form>

    <div class="table-wrap">
        <?php if (empty($rows)): ?>
            <div class="empty">No diagnosis records found for the selected filters.</div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th style="width: 90px;">Type</th>
                        <th style="width: 140px;">Date/Time</th>
                        <th style="width: 120px;">Patient ID</th>
                        <th>Patient Name</th>
                        <th style="width: 130px;">Mobile</th>
                        <th>Doctor</th>
                        <th style="width: 90px;">Print</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <?php
                            $isTele = ($row['source'] ?? '') === 'teleconsult';
                            $printUrl = $base . '/public/ehealth_center_doctor/print_diagnosis.php?diagnosisId=' . (int)$row['diagnosis_id'] . '&type=' . ($isTele ? 'teleconsult' : 'ehealth');
                            if ($isTele && !empty($row['source_table'])) {
                                $printUrl .= '&srcTable=' . rawurlencode((string)$row['source_table']);
                            }
                            $dt = strtotime((string)$row['diagnosis_date']);
                        ?>
                        <tr>
                            <td>
                                <span class="tag <?= $isTele ? 'tele' : 'eh' ?>">
                                    <?= $isTele ? 'Tele' : 'Ehealth' ?>
                                </span>
                            </td>
                            <td><?= $dt ? e(date('d M Y h:i A', $dt)) : '-' ?></td>
                            <td><?= e($row['patient_id'] ?? '-') ?></td>
                            <td><?= e($row['full_name'] ?? '-') ?></td>
                            <td><?= e($row['mobile_no'] ?? '-') ?></td>
                            <td><?= e($row['doctor_name'] ?? '-') ?></td>
                            <td><a class="print-link" target="_blank" href="<?= e($printUrl) ?>">Print</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
