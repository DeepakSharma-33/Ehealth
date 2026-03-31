<?php
require_once __DIR__ . '/../../config/config.php';
require_role('admin', '../admin/admin_login.php');

$error   = '';
$success = '';
$doctor_id_generated = '';
$modal_data = null;

// ── Helper: generate sequential Doctor ID ─────────────────
function generateDoctorId($db) {
    $prefix = 'DOC' . date('Ym');   // e.g. DOC202602
    $like   = $prefix . '%';

    $st = $db->prepare("SELECT doctor_id FROM ehealth_center_doctors WHERE doctor_id LIKE ? ORDER BY doctor_id DESC LIMIT 1");
    $st->bind_param('s', $like);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    $nextNum = 1;
    if ($row) {
        $extracted = substr($row['doctor_id'], strlen($prefix)); // gets '001', '002', etc.
        if (is_numeric($extracted)) {
            $nextNum = (int)$extracted + 1;
        }
    }

    return $prefix . str_pad($nextNum, 3, '0', STR_PAD_LEFT); // e.g. DOC202602001
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ── Collect fields ──────────────────────────────────────────
    $fullName           = trim($_POST['fullName'] ?? '');
    $gender             = trim($_POST['gender'] ?? '');
    $dob                = trim($_POST['dob'] ?? '');
    $experience         = trim($_POST['experience'] ?? '0');
    $licenseNumber      = trim($_POST['licenseNumber'] ?? '');
    $registrationNumber = trim($_POST['registrationNumber'] ?? '');
    $hprId              = trim($_POST['hprId'] ?? '');
    $qualification      = trim($_POST['qualification'] ?? '');
    $otherQual          = trim($_POST['otherQualification'] ?? '');
    $specialization     = trim($_POST['specialization'] ?? '');
    $otherSpec          = trim($_POST['otherSpecialization'] ?? '');
    $mobileNo           = trim($_POST['mobileNo'] ?? '');
    $email              = trim($_POST['email'] ?? '');
    $aadharNo           = trim($_POST['aadharNo'] ?? '');
    $country            = 'India';
    $state              = trim($_POST['state'] ?? '');
    $district           = trim($_POST['district'] ?? '');
    $city               = trim($_POST['cityManual'] ?? '') ?: trim($_POST['city'] ?? '');
    $village            = trim($_POST['village'] ?? '');
    $street             = trim($_POST['street'] ?? '');
    $pincode            = trim($_POST['pincode'] ?? '');

    // Resolve "Other"/"Fellowship" qualification & specialization
    if ($qualification === 'Other' || $qualification === 'Fellowship') {
        $qualification = $otherQual ?: $qualification;
    }
    if ($specialization === 'Other') {
        $specialization = $otherSpec ?: 'Other';
    }

    // ── Validation ──────────────────────────────────────────────
    if (!$fullName || !$gender || !$dob || !$licenseNumber || !$registrationNumber
        || !$qualification || !$specialization || !$mobileNo || !$email || !$aadharNo
        || !$state || !$district || !$pincode) {
        $error = 'Please fill in all required fields.';
    } elseif (!preg_match('/^\d{10}$/', $mobileNo)) {
        $error = 'Mobile number must be exactly 10 digits.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email address.';
    } elseif (!preg_match('/^\d{12}$/', $aadharNo)) {
        $error = 'Aadhar number must be exactly 12 digits.';
    } elseif (!preg_match('/^\d{6}$/', $pincode)) {
        $error = 'Pincode must be exactly 6 digits.';
    } else {
        $db = db();

        // ── Duplicate checks ────────────────────────────────────
        $checks = [
            ['mobile_no',           $mobileNo,           'Mobile number'],
            ['email',               $email,               'Email'],
            ['aadhar_no',           $aadharNo,            'Aadhar number'],
            ['license_number',      $licenseNumber,       'License number'],
            ['registration_number', $registrationNumber,  'Registration number'],
        ];
        foreach ($checks as [$col, $val, $label]) {
            $st = $db->prepare("SELECT doctor_id FROM ehealth_center_doctors WHERE $col = ? LIMIT 1");
            $st->bind_param('s', $val);
            $st->execute();
            if ($st->get_result()->num_rows > 0) {
                $error = "$label is already registered.";
                break;
            }
            $st->close();
        }

        if (!$error) {
            // ── Generate Doctor ID ───────────────────────────────
            $doctorId = generateDoctorId($db);

            // Safety net: verify uniqueness
            $stChk = $db->prepare('SELECT doctor_id FROM ehealth_center_doctors WHERE doctor_id = ? LIMIT 1');
            $stChk->bind_param('s', $doctorId);
            $stChk->execute();
            $alreadyExists = ($stChk->get_result()->num_rows > 0);
            $stChk->close();

            if ($alreadyExists) {
                $error = 'Could not generate a unique Doctor ID. Please try again.';
            } else {
                // Default password = doctorId
                $hashedPassword = hash_password($doctorId);

                // Nullable fields
                $hprId   = $hprId ?: null;
                $city    = $city ?: null;
                $village = $village ?: null;
                $street  = $street ?: null;

                $exp     = (int)$experience;
                $adminId = (int)get_session_id();

                // ── bind_param format string: 22 params ──────────
                // s  doctor_id
                // s  full_name
                // s  gender
                // s  dob
                // i  experience_years
                // s  license_number
                // s  registration_number
                // s  hpr_id
                // s  qualification
                // s  specialization
                // s  mobile_no
                // s  email
                // s  aadhar_no
                // s  country
                // s  state
                // s  district
                // s  city
                // s  village
                // s  street
                // s  pincode
                // s  password
                // i  registered_by_admin
                // = 'ssssissssssssssssssssi' (20 s, 2 i = 22 total)

                $stmt = $db->prepare(
                    'INSERT INTO ehealth_center_doctors
                    (doctor_id, full_name, gender, dob, experience_years,
                     license_number, registration_number, hpr_id,
                     qualification, specialization,
                     mobile_no, email, aadhar_no,
                     country, state, district, city, village, street, pincode,
                     password, registered_by_admin, created_at)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())'
                );

                $stmt->bind_param(
                    'ssssissssssssssssssssi',
                    $doctorId, $fullName, $gender, $dob, $exp,
                    $licenseNumber, $registrationNumber, $hprId,
                    $qualification, $specialization,
                    $mobileNo, $email, $aadharNo,
                    $country, $state, $district, $city, $village, $street, $pincode,
                    $hashedPassword, $adminId
                );

                if ($stmt->execute()) {
                    $smsSent = false;
                    // ── Send SMS via Twilio ──────────────────────
                    try {
                        $smsBody = "Welcome to eHealth! Your Doctor ID: $doctorId | Registration No: $registrationNumber | Default Password: $doctorId. Please change your password after first login.";
                        $twilioUrl = 'https://api.twilio.com/2010-04-01/Accounts/' . TWILIO_SID . '/Messages.json';
                        $toNumber  = '+91' . $mobileNo;
                        $postData  = http_build_query([
                            'To'                 => $toNumber,
                            'MessagingServiceSid' => TWILIO_MESSAGING_SID,
                            'Body'               => $smsBody,
                        ]);
                        $ch = curl_init($twilioUrl);
                        curl_setopt_array($ch, [
                            CURLOPT_POST           => true,
                            CURLOPT_POSTFIELDS     => $postData,
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_USERPWD        => TWILIO_SID . ':' . TWILIO_AUTH_TOKEN,
                            CURLOPT_TIMEOUT        => 10,
                        ]);
                        $smsResp = curl_exec($ch);
                        $smsSent = ($smsResp !== false);
                        curl_close($ch);
                    } catch (\Throwable $e) {
                        // SMS failure is non-fatal — registration still succeeds
                    }

                    // ── Handle file uploads ──────────────────────
                    $uploadRoot = dirname(__DIR__, 2) . '/uploads/doctors/';
                    $doctorDir  = $uploadRoot . $doctorId . '/';
                    if (!is_dir($doctorDir)) mkdir($doctorDir, 0755, true);
                    $docsUploaded = [];

                    $fileFields = [
                        'fileDegree' => 'degree',
                        'filePG'     => 'pg_degree',
                        'fileCert'   => 'certificate',
                        'fileOther'  => 'other',
                    ];
                    $docLabels = [
                        'degree'      => 'Degree Certificate',
                        'pg_degree'   => 'PG Degree',
                        'certificate' => 'Certificate',
                        'other'       => trim($_POST['otherDocLabel'] ?? 'Other Document'),
                    ];

                    foreach ($fileFields as $field => $type) {
                        if (!empty($_FILES[$field]['tmp_name']) && $_FILES[$field]['error'] === UPLOAD_ERR_OK) {
                            $origName = $_FILES[$field]['name'];
                            $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                            if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'])) continue;
                            if ($_FILES[$field]['size'] > 1 * 1024 * 1024) continue;

                            $safeName = $type . '_' . preg_replace('/[^a-z0-9._-]/', '_', strtolower($origName));
                            $destPath = $doctorDir . $safeName;
                            $relPath  = 'uploads/doctors/' . $doctorId . '/' . $safeName;

                            if (move_uploaded_file($_FILES[$field]['tmp_name'], $destPath)) {
                                $docLabel = $docLabels[$type];
                                $fileSize = $_FILES[$field]['size'];
                                $mimeType = $_FILES[$field]['type'];
                                $ins = $db->prepare(
                                    'INSERT INTO doctor_documents
                                    (doctor_id, doc_type, doc_label, file_name, file_path, file_size, mime_type, uploaded_at)
                                     VALUES (?,?,?,?,?,?,?,NOW())'
                                );
                                $ins->bind_param('sssssss', $doctorId, $type, $docLabel, $origName, $relPath, $fileSize, $mimeType);
                                $ins->execute();
                                $ins->close();
                                $docsUploaded[] = $docLabel;
                            }
                        }
                    }

                    $doctor_id_generated = $doctorId;
                    $success = "Doctor registered successfully! Doctor ID: <strong>$doctorId</strong> | Registration No: <strong>$registrationNumber</strong>. Default password is the Doctor ID. SMS sent to registered mobile.";
                    $modal_data = [
                        'doctor_id'       => $doctorId,
                        'full_name'       => $fullName,
                        'gender'          => $gender,
                        'qualification'   => $qualification,
                        'specialization'  => $specialization,
                        'mobile_no'       => $mobileNo,
                        'email'           => $email,
                        'aadhar_no'       => mask_aadhar_last4($aadharNo),
                        'registration_no' => $registrationNumber,
                        'license_no'      => $licenseNumber,
                        'district'        => $district,
                        'state'           => $state,
                        'docs_uploaded'   => $docsUploaded,
                        'sms_sent'        => $smsSent,
                        'datetime'        => date('d M Y, h:i A'),
                    ];
                    $_POST = []; // clear form data for next registration
                } else {
                    $error = 'Database error: ' . $stmt->error;
                }
                $stmt->close();
            }
        }
    }
}

$has_success_modal = !empty($modal_data);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Doctor Registration - eHealth</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', sans-serif; background: linear-gradient(135deg, #a8e6cf, #88d8b0); min-height: 100vh; }

        header { background: linear-gradient(to right, #4CAF50, #45a049); padding: 15px 50px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .logo { display: flex; align-items: center; color: white; font-size: 22px; font-weight: bold; text-decoration: none; }
        .logo-icon { width: 38px; height: 38px; background: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin-right: 10px; color: #4CAF50; font-size: 18px; }
        nav a { color: white; text-decoration: none; font-size: 15px; font-weight: 500; margin-left: 25px; }
        nav a:hover { opacity: 0.8; }

        .container { max-width: 900px; margin: 40px auto; padding: 0 20px 60px; }
        .form-card { background: white; border-radius: 20px; padding: 40px; box-shadow: 0 8px 30px rgba(0,0,0,0.12); }
        .form-title { font-size: 28px; font-weight: 700; color: #2c3e50; margin-bottom: 8px; text-align: center; }
        .form-subtitle { font-size: 15px; color: #7f8c8d; margin-bottom: 32px; text-align: center; }

        .section-head { font-size: 16px; font-weight: 700; color: #2e7d32; border-left: 4px solid #2e7d32; padding-left: 12px; margin: 30px 0 18px; }

        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .form-group { display: flex; flex-direction: column; }
        .form-group.full { grid-column: 1 / -1; }
        label { font-size: 13px; font-weight: 600; color: #333; margin-bottom: 7px; }
        .required { color: #e74c3c; }
        input, select, textarea {
            padding: 12px 14px; border: 2px solid #e0e0e0; border-radius: 10px;
            font-size: 14px; font-family: inherit; outline: none;
            transition: border-color 0.25s; background: #f9f9f9;
        }
        input:focus, select:focus, textarea:focus { border-color: #2e7d32; background: white; }
        textarea { resize: vertical; min-height: 80px; }

        .radio-group { display: flex; gap: 20px; align-items: center; padding-top: 6px; }
        .radio-group label { font-weight: 400; margin-bottom: 0; display: flex; align-items: center; gap: 6px; cursor: pointer; }
        .radio-group input[type="radio"] { width: auto; padding: 0; }

        .file-upload { border: 2px dashed #c0e0c0; border-radius: 10px; padding: 16px; text-align: center; cursor: pointer; transition: border-color 0.2s; background: #f7fff7; }
        .file-upload:hover { border-color: #2e7d32; }
        .file-upload input { display: none; }
        .file-upload-label { font-size: 13px; color: #555; cursor: pointer; }
        .file-name { font-size: 12px; color: #2e7d32; margin-top: 6px; font-weight: 600; }

        /* Document upload cards */
        .doc-upload-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:16px; margin-top:8px; }
        .doc-card { border:2px dashed #c8e6c9; border-radius:10px; padding:16px 14px 12px; background:#f9fffe; transition:border-color .2s,background .2s; position:relative; }
        .doc-card:hover { border-color:#2e7d32; background:#f1fbf2; }
        .doc-card.has-file  { border-style:solid; border-color:#2e7d32; background:#f1fbf2; }
        .doc-card.has-error { border-style:solid; border-color:#dc3545 !important; background:#fff5f5 !important; }
        .doc-card-title { font-size:13px; font-weight:700; color:#2e7d32; margin-bottom:4px; display:flex; align-items:center; gap:6px; }
        .doc-card-hint  { font-size:11px; color:#888; margin-bottom:10px; }
        .doc-action-row { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
        .doc-upload-btn { display:inline-flex; align-items:center; gap:6px; padding:7px 14px; background:linear-gradient(135deg,#2e7d32,#43a047); color:white; border-radius:6px; cursor:pointer; font-size:12px; font-weight:600; transition:opacity .2s; border:none; font-family:inherit; }
        .doc-upload-btn:hover { opacity:.88; }
        .doc-remove-btn { display:none; align-items:center; gap:4px; padding:7px 12px; background:#fdecea; color:#c62828; border:1.5px solid #f5c6cb; border-radius:6px; cursor:pointer; font-size:12px; font-weight:700; font-family:inherit; transition:background .2s; }
        .doc-remove-btn:hover { background:#f8d7da; }
        .doc-card.has-file  .doc-remove-btn,
        .doc-card.has-error .doc-remove-btn { display:inline-flex; }
        .doc-file-input { display:none; }
        .doc-file-preview { display:none; margin-top:10px; background:#e8f5e9; border:1px solid #a5d6a7; border-radius:7px; padding:8px 10px; }
        .doc-card.has-file  .doc-file-preview { display:block; }
        .doc-card.has-error .doc-file-preview { display:block; background:#fdecea; border-color:#f5c6cb; }
        .doc-file-name { font-size:11px; font-weight:700; color:#1b5e20; word-break:break-all; }
        .doc-card.has-error .doc-file-name { color:#c62828; }
        .doc-file-meta { font-size:10px; color:#5a7a6a; margin-top:2px; }
        .doc-card.has-error .doc-file-meta { color:#e57373; }
        .doc-error-msg { font-size:11px; color:#dc3545; font-weight:600; margin-top:6px; line-height:1.4; }
        .doc-size-badge { font-size:12px; color:#6c757d; font-weight:400; margin-left:6px; }

        .alert { padding: 14px 18px; border-radius: 10px; margin-bottom: 24px; font-size: 14px; }
        .alert-error { background: #fdecea; color: #b71c1c; border: 1px solid #f5c6cb; }
        .alert-success { background: #e8f5e9; color: #1b5e20; border: 1px solid #c3e6cb; }

        .pincode-status { font-size: 13px; margin-top: 6px; padding: 7px 12px; border-radius: 7px; }
        .pincode-status.success { background: #e8f5e9; color: #1b5e20; }
        .pincode-status.error   { background: #fdecea; color: #b71c1c; }
        .pincode-status.loading { background: #e3f2fd; color: #0d47a1; }
        .pincode-status.warn    { background: #fff8e1; color: #e65100; }
        .manual-note { font-size: 12px; color: #888; font-style: italic; margin-top: 5px; }
        input[readonly] { background: #f0f7f0 !important; color: #555; cursor: not-allowed; }

        .btn-submit { width: 100%; padding: 16px; background: linear-gradient(135deg, #2e7d32, #43a047); color: white; border: none; border-radius: 12px; font-size: 16px; font-weight: 700; cursor: pointer; margin-top: 30px; transition: opacity 0.2s, transform 0.2s; letter-spacing: 0.5px; }
        .btn-submit:hover { opacity: 0.92; transform: translateY(-1px); }

        .back-link { text-align: center; margin-top: 20px; }
        .back-link a { color: #2e7d32; text-decoration: none; font-size: 14px; font-weight: 600; }

        body.modal-open header,
        body.modal-open .container { filter: blur(6px); pointer-events: none; user-select: none; }
        .modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.55); backdrop-filter:blur(4px); z-index:1000; align-items:center; justify-content:center; padding:20px; }
        .modal-overlay.show { display:flex; animation:fadeIn .3s ease; }
        @keyframes fadeIn { from{opacity:0} to{opacity:1} }
        .modal-card { background:#fff; border-radius:18px; max-width:520px; width:100%; box-shadow:0 24px 60px rgba(0,0,0,.25); overflow:hidden; max-height:90vh; overflow-y:auto; }
        .modal-header { background:linear-gradient(135deg,#2e7d32,#43a047); color:#fff; padding:22px 28px 16px; text-align:center; }
        .modal-check { width:56px; height:56px; margin:0 auto 10px; border-radius:50%; background:rgba(255,255,255,.2); display:flex; align-items:center; justify-content:center; font-size:28px; }
        .modal-header h2 { font-size:20px; font-weight:700; }
        .modal-body { padding:22px 28px 24px; }
        .modal-id-badge { text-align:center; margin-bottom:16px; background:#e8f5e9; border:2px solid #a5d6a7; border-radius:12px; padding:12px; }
        .modal-id-label { font-size:11px; color:#5a7a6a; text-transform:uppercase; letter-spacing:1px; margin-bottom:4px; }
        .modal-id-value { font-size:24px; font-weight:800; color:#2e7d32; letter-spacing:2px; }
        .modal-grid { display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:12px; }
        .modal-item.full { grid-column:1/-1; }
        .modal-item-label { font-size:11px; font-weight:600; color:#888; text-transform:uppercase; letter-spacing:.4px; margin-bottom:2px; }
        .modal-item-value { font-size:13px; font-weight:600; color:#1b3a1e; }
        .modal-docs { background:#f0f7f0; border:1px solid #c8e6c9; border-radius:8px; padding:10px 14px; margin-bottom:14px; }
        .modal-docs-title { font-weight:700; color:#2e7d32; margin-bottom:6px; font-size:12px; text-transform:uppercase; }
        .modal-doc-item { color:#1b5e20; font-size:12px; padding:2px 0; }
        .modal-sms { border-radius:8px; padding:10px 14px; margin-bottom:16px; display:flex; align-items:center; gap:10px; font-size:13px; }
        .modal-sms.sent { background:#e8f5e9; border:1px solid #a5d6a7; }
        .modal-sms.failed { background:#fff8e1; border:1px solid #ffd54f; }
        .modal-actions { display:flex; gap:12px; }
        .modal-btn { flex:1; padding:11px; border-radius:8px; font-size:14px; font-weight:700; cursor:pointer; font-family:inherit; border:none; transition:opacity .2s; text-decoration:none; display:inline-flex; align-items:center; justify-content:center; }
        .modal-btn.print { background:#f0a500; color:#1a1a1a; }
        .modal-btn.dashboard { background:#1976d2; color:#fff; }
        .modal-btn.close { background:#2e7d32; color:#fff; }
        .modal-btn:hover { opacity:.88; }

        .pincode-row { display: flex; gap: 10px; }
        .pincode-row input { flex: 1; }
        .btn-lookup { padding: 12px 20px; background: #2e7d32; color: white; border: none; border-radius: 10px; font-size: 14px; cursor: pointer; white-space: nowrap; font-weight: 600; }
        .btn-lookup:hover { opacity: 0.9; }

        @media (max-width: 600px) { .form-grid { grid-template-columns: 1fr; } header { padding: 15px 20px; } .modal-grid { grid-template-columns:1fr; } .modal-actions { flex-direction:column; } }
        @media print {
            body > * { display:none !important; }
            #successModal { display:block !important; position:static !important; background:none !important; backdrop-filter:none !important; padding:0 !important; }
            .modal-card { box-shadow:none !important; border:1px solid #ccc !important; max-height:none !important; overflow:visible !important; }
            .modal-actions { display:none !important; }
        }
    </style>
</head>
<body class="<?= $has_success_modal ? 'modal-open' : '' ?>">

<header>
    <a href="<?= htmlspecialchars(app_base_url()) ?>/" class="logo">
        <span style="display:flex;align-items:center;gap:14px;line-height:1;"><img src="<?= htmlspecialchars(app_base_url()) ?>/public/assets/SRMS_TRUST_LOGO.png" alt="SRMST Trust Logo" style="height:48px;width:auto;display:block;object-fit:contain;filter:drop-shadow(0 1px 3px rgba(0,0,0,.35));"><span style="font-size:22px;font-weight:800;letter-spacing:1px;color:#fff;text-shadow:0 1px 2px rgba(0,0,0,.35);white-space:nowrap;">SRMS EHEALTH</span></span>
    </a>
    <nav>
        <a href="../admin/admin_dashboard.php">← Dashboard</a>
        <a href="../index.php">HOME</a>
        <a href="<?= htmlspecialchars(app_base_url()) ?>/about.php">ABOUT</a>
        <a href="<?= htmlspecialchars(app_base_url()) ?>/contact.php">CONTACT</a>
    </nav>
</header>

<div class="container">
    <div class="form-card">
        <div class="form-title">👨‍⚕️ Register Doctor</div>
        <div class="form-subtitle">Ehealth Center — Admin Registration</div>

        <?php if ($error): ?>
            <div class="alert alert-error">❌ <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($success && !$modal_data): ?>
            <div class="alert alert-success">✅ <?= $success ?></div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">

            <div class="section-head">Personal Information</div>
            <div class="form-grid">
                <div class="form-group full">
                    <label>Full Name <span class="required">*</span></label>
                    <div class="manual-note" style="margin-top:-4px;margin-bottom:6px;">Please start the name with "Dr." (e.g., Dr. Anil Kumar).</div>
                    <input type="text" name="fullName" value="<?= htmlspecialchars($_POST['fullName'] ?? '') ?>" placeholder="John Doe" required>
                </div>
                <div class="form-group">
                    <label>Gender <span class="required">*</span></label>
                    <div class="radio-group">
                        <label><input type="radio" name="gender" value="Male"   <?= (($_POST['gender'] ?? '') === 'Male')   ? 'checked' : '' ?>> Male</label>
                        <label><input type="radio" name="gender" value="Female" <?= (($_POST['gender'] ?? '') === 'Female') ? 'checked' : '' ?>> Female</label>
                        <label><input type="radio" name="gender" value="Other"  <?= (($_POST['gender'] ?? '') === 'Other')  ? 'checked' : '' ?>> Other</label>
                    </div>
                </div>
                <div class="form-group">
                    <label>Date of Birth <span class="required">*</span></label>
                    <input type="date" id="dob" name="dob" value="<?= htmlspecialchars($_POST['dob'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label>Experience (years) <span class="required">*</span></label>
                    <input type="number" name="experience" value="<?= htmlspecialchars($_POST['experience'] ?? '0') ?>" min="0" max="60" required>
                </div>
            </div>

            <div class="section-head">Professional Details</div>
            <div class="form-grid">
                <div class="form-group">
                    <label>License Number <span class="required">*</span></label>
                    <input type="text" name="licenseNumber" value="<?= htmlspecialchars($_POST['licenseNumber'] ?? '') ?>" placeholder="e.g. MED123456" required>
                </div>
                <div class="form-group">
                    <label>Registration Number <span class="required">*</span></label>
                    <input type="text" name="registrationNumber" value="<?= htmlspecialchars($_POST['registrationNumber'] ?? '') ?>" placeholder="e.g. REG/UP/2025/001234" required>
                </div>

                <div class="form-group">
                    <label>HPR ID <small>(optional)</small></label>
                    <input type="text" name="hprId" value="<?= htmlspecialchars($_POST['hprId'] ?? '') ?>" placeholder="HPR12345678">
                </div>
                <div class="form-group">
                    <label>Qualification <span class="required">*</span></label>
                    <select name="qualification" id="qualification" required onchange="toggleOtherQualification()">
                        <option value="">Select qualification</option>
                        <?php foreach (['MBBS','MD','MS','BDS','MDS','BAMS','BHMS','BUMS','DNB','DM','MCh','Fellowship','Other'] as $q): ?>
                            <option value="<?= $q ?>" <?= (($_POST['qualification'] ?? '') === $q) ? 'selected' : '' ?>><?= $q ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" id="otherQualificationGroup" style="display:none">
                    <label>Specify Qualification <span class="required">*</span></label>
                    <input type="text" name="otherQualification" id="otherQualification" value="<?= htmlspecialchars($_POST['otherQualification'] ?? '') ?>" placeholder="Enter your qualification">
                </div>
                <div class="form-group">
                    <label>Specialization <span class="required">*</span></label>
                    <select name="specialization" id="specialization" required onchange="toggleOtherSpec()">
                        <option value="">Select specialization</option>
                        <?php foreach (['General Medicine','Cardiology','Dermatology','Orthopedics','Pediatrics','Gynecology','Neurology','Psychiatry','Ophthalmology','ENT','Radiology','Oncology','Urology','Nephrology','Gastroenterology','Pulmonology','Endocrinology','Rheumatology','Other'] as $s): ?>
                            <option value="<?= $s ?>" <?= (($_POST['specialization'] ?? '') === $s) ? 'selected' : '' ?>><?= $s ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" id="otherSpecGroup" style="display:none">
                    <label>Specify Specialization <span class="required">*</span></label>
                    <input type="text" name="otherSpecialization" id="otherSpecialization" value="<?= htmlspecialchars($_POST['otherSpecialization'] ?? '') ?>" placeholder="Enter your specialization">
                </div>
            </div>

            <div class="section-head">Documents <span class="doc-size-badge">(Optional — PDF, JPG, PNG · Max <strong>1 MB</strong> each)</span></div>
            <div class="doc-upload-grid">
                <?php
                $doc_cards = [
                    ['fileDegree', '🎓 Degree Certificate', 'MBBS / BAMS / BDS etc.'],
                    ['filePG',     '🏅 PG Degree',          'MD / MS / DNB / DM etc.'],
                    ['fileCert',   '📜 Certificate',         'Fellowship / Training / CME'],
                    ['fileOther',  '📁 Any Other Document',  'Registration / ID / Other'],
                ];
                foreach ($doc_cards as [$field, $title, $hint]):
                ?>
                <div class="doc-card" id="card_<?= $field ?>">
                    <div class="doc-card-title"><?= $title ?></div>
                    <div class="doc-card-hint"><?= $hint ?></div>
                    <div class="doc-action-row">
                        <button type="button" class="doc-upload-btn" onclick="document.getElementById('<?= $field ?>').click()">📎 Choose File</button>
                        <button type="button" class="doc-remove-btn" onclick="clearDoc('<?= $field ?>')">🗑 Remove</button>
                    </div>
                    <input type="file" id="<?= $field ?>" name="<?= $field ?>"
                           class="doc-file-input" accept=".pdf,.jpg,.jpeg,.png">
                    <div class="doc-file-preview" id="preview_<?= $field ?>">
                        <div class="doc-file-name" id="fname_<?= $field ?>"></div>
                        <div class="doc-file-meta" id="fmeta_<?= $field ?>"></div>
                    </div>
                    <div class="doc-error-msg" id="err_<?= $field ?>"></div>
                    <?php if ($field === 'fileOther'): ?>
                        <input type="text" name="otherDocLabel"
                               value="<?= htmlspecialchars($_POST['otherDocLabel'] ?? '') ?>"
                               placeholder="Specify document name…"
                               style="margin-top:10px;padding:7px 10px;border:2px solid #e0e0e0;border-radius:6px;font-size:12px;width:100%;font-family:inherit;"
                               onfocus="this.style.borderColor='#2e7d32'" onblur="this.style.borderColor='#e0e0e0'">
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="section-head">Contact Information</div>
            <div class="form-grid">
                <div class="form-group">
                    <label>Mobile Number <span class="required">*</span></label>
                    <input type="tel" id="mobileNo" name="mobileNo" value="<?= htmlspecialchars($_POST['mobileNo'] ?? '') ?>" maxlength="10" placeholder="10-digit mobile" required>
                </div>
                <div class="form-group">
                    <label>Email <span class="required">*</span></label>
                    <input type="email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" placeholder="doctor@email.com" required>
                </div>
                <div class="form-group">
                    <label>Aadhar Number <span class="required">*</span></label>
                    <input type="text" id="aadharNo" name="aadharNo" value="<?= htmlspecialchars($_POST['aadharNo'] ?? '') ?>" maxlength="12" placeholder="12-digit Aadhar" required>
                </div>
            </div>

            <div class="section-head">Address</div>
            <div class="form-grid">
                <div class="form-group">
                    <label>Pincode <span class="required">*</span></label>
                    <div class="pincode-row">
                        <input type="text" name="pincode" id="pincode" value="<?= htmlspecialchars($_POST['pincode'] ?? '') ?>" maxlength="6" placeholder="6-digit pincode" required>
                        <button type="button" class="btn-lookup" onclick="lookupPincode()">🔍 Lookup</button>
                    </div>
                    <div class="pincode-status" id="pincodeStatus" style="display:none"></div>
                    <div class="manual-note" id="manualAddressNote" style="display:none">
                        ℹ️ Pincode not found — please fill State and District manually below.
                    </div>
                </div>
                <div class="form-group">
                    <label>Country</label>
                    <input type="text" name="country" value="India" readonly>
                </div>
                <div class="form-group">
                    <label>State <span class="required">*</span></label>
                    <input type="text" name="state" id="state" value="<?= htmlspecialchars($_POST['state'] ?? '') ?>" placeholder="Auto-filled from pincode" readonly required>
                </div>
                <div class="form-group">
                    <label>District <span class="required">*</span></label>
                    <input type="text" name="district" id="district" value="<?= htmlspecialchars($_POST['district'] ?? '') ?>" placeholder="Auto-filled from pincode" readonly required>
                </div>
                <div class="form-group" id="cityWrapper">
                    <label>City / Locality</label>
                    <select name="city" id="citySelect" disabled>
                        <option value="">Enter pincode first</option>
                        <?php if (!empty($_POST['city'])): ?>
                            <option value="<?= htmlspecialchars($_POST['city']) ?>" selected><?= htmlspecialchars($_POST['city']) ?></option>
                        <?php endif; ?>
                    </select>
                    <input type="text" name="cityManual" id="cityManualInput"
                           value="<?= htmlspecialchars($_POST['cityManual'] ?? '') ?>"
                           placeholder="Type your city / town name"
                           style="display:none; margin-top:6px;">
                </div>
                <div class="form-group">
                    <label>Village / Area</label>
                    <input type="text" name="village" value="<?= htmlspecialchars($_POST['village'] ?? '') ?>" placeholder="Village or area name">
                </div>
                <div class="form-group full">
                    <label>Street Address</label>
                    <textarea name="street" placeholder="House No., Street, Landmark…"><?= htmlspecialchars($_POST['street'] ?? '') ?></textarea>
                </div>
            </div>

            <button type="submit" class="btn-submit">REGISTER DOCTOR</button>
        </form>

        <div class="back-link">
            <a href="/public/admin/admin_dashboard.php">← Back to Admin Dashboard</a>
        </div>
</div>
</div>

<?php if ($modal_data): ?>
<div class="modal-overlay show" id="successModal">
    <div class="modal-card">
        <div class="modal-header">
            <div class="modal-check">✅</div>
            <h2>Doctor Registered Successfully</h2>
        </div>
        <div class="modal-body">
            <div class="modal-id-badge">
                <div class="modal-id-label">Doctor ID &amp; Default Password</div>
                <div class="modal-id-value"><?= htmlspecialchars($modal_data['doctor_id']) ?></div>
            </div>

            <div class="modal-grid">
                <div class="modal-item">
                    <div class="modal-item-label">Full Name</div>
                    <div class="modal-item-value"><?= htmlspecialchars($modal_data['full_name']) ?></div>
                </div>
                <div class="modal-item">
                    <div class="modal-item-label">Gender</div>
                    <div class="modal-item-value"><?= htmlspecialchars($modal_data['gender']) ?></div>
                </div>
                <div class="modal-item">
                    <div class="modal-item-label">Qualification</div>
                    <div class="modal-item-value"><?= htmlspecialchars($modal_data['qualification']) ?></div>
                </div>
                <div class="modal-item">
                    <div class="modal-item-label">Specialization</div>
                    <div class="modal-item-value"><?= htmlspecialchars($modal_data['specialization']) ?></div>
                </div>
                <div class="modal-item">
                    <div class="modal-item-label">Mobile</div>
                    <div class="modal-item-value"><?= htmlspecialchars($modal_data['mobile_no']) ?></div>
                </div>
                <div class="modal-item">
                    <div class="modal-item-label">Email</div>
                    <div class="modal-item-value"><?= htmlspecialchars($modal_data['email']) ?></div>
                </div>
                <div class="modal-item full">
                    <div class="modal-item-label">Registration / License</div>
                    <div class="modal-item-value"><?= htmlspecialchars($modal_data['registration_no']) ?> / <?= htmlspecialchars($modal_data['license_no']) ?></div>
                </div>
                <div class="modal-item">
                    <div class="modal-item-label">Registered On</div>
                    <div class="modal-item-value"><?= htmlspecialchars($modal_data['datetime']) ?></div>
                </div>
                <div class="modal-item">
                    <div class="modal-item-label">Aadhar</div>
                    <div class="modal-item-value"><?= htmlspecialchars($modal_data['aadhar_no']) ?></div>
                </div>
                <div class="modal-item full">
                    <div class="modal-item-label">Location</div>
                    <div class="modal-item-value"><?= htmlspecialchars($modal_data['district']) ?>, <?= htmlspecialchars($modal_data['state']) ?></div>
                </div>
            </div>

            <?php if (!empty($modal_data['docs_uploaded'])): ?>
            <div class="modal-docs">
                <div class="modal-docs-title">Documents Uploaded</div>
                <?php foreach ($modal_data['docs_uploaded'] as $doc): ?>
                    <div class="modal-doc-item">✅ <?= htmlspecialchars($doc) ?></div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="modal-sms <?= !empty($modal_data['sms_sent']) ? 'sent' : 'failed' ?>">
                <span style="font-size:20px"><?= !empty($modal_data['sms_sent']) ? '📱' : '⚠️' ?></span>
                <div>
                    <strong><?= !empty($modal_data['sms_sent']) ? 'SMS Sent' : 'SMS Not Sent' ?></strong><br>
                    <span style="font-size:12px">
                        <?= !empty($modal_data['sms_sent'])
                            ? 'Welcome message sent to +91' . htmlspecialchars($modal_data['mobile_no'])
                            : 'Please share doctor ID/password manually.' ?>
                    </span>
                </div>
            </div>

            <div class="modal-actions">
                <button class="modal-btn print" onclick="window.print()">🖨️ Print Slip</button>
                <a class="modal-btn dashboard" href="../admin/admin_dashboard.php">↩ Dashboard</a>
                <button class="modal-btn close" onclick="document.getElementById('successModal').style.display='none';document.body.classList.remove('modal-open');">✖ Close</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
const MAX_FILE_SIZE = 1 * 1024 * 1024; // 1 MB
// ── On page load ───────────────────────────────────────────
window.addEventListener('DOMContentLoaded', () => {
    // DOB max = 18 years ago
    const maxDob = new Date();
    maxDob.setFullYear(maxDob.getFullYear() - 18);
    const dobEl = document.getElementById('dob');
    if (dobEl) dobEl.max = maxDob.toISOString().split('T')[0];

    // Digits-only on mobile, aadhar, pincode
    ['mobileNo','aadharNo','pincode'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('input', e => { e.target.value = e.target.value.replace(/\D/g,''); });
    });

    // Auto-lookup when pincode hits 6 digits
    const pinEl = document.getElementById('pincode');
    if (pinEl) pinEl.addEventListener('input', () => {
        if (pinEl.value.length === 6) lookupPincode();
        else if (pinEl.value.length < 6) resetAddressFields();
    });

    // Restore "Other" qualification/spec fields if form re-displayed after error
    toggleOtherQualification();
    toggleOtherSpec();
});

// ── Qualification "Other"/"Fellowship" toggle ──────────────
function toggleOtherQualification() {
    const val   = document.getElementById('qualification')?.value;
    const group = document.getElementById('otherQualificationGroup');
    const input = document.getElementById('otherQualification');
    if (!group) return;
    if (val === 'Other' || val === 'Fellowship') {
        group.style.display = 'flex';
        input.required      = true;
        input.placeholder   = val === 'Fellowship' ? 'e.g. Fellowship in Cardiology' : 'Enter your qualification';
    } else {
        group.style.display = 'none';
        input.required      = false;
        input.value         = '';
    }
}

// ── Specialization "Other" toggle ─────────────────────────
function toggleOtherSpec() {
    const val   = document.getElementById('specialization')?.value;
    const group = document.getElementById('otherSpecGroup');
    const input = document.getElementById('otherSpecialization');
    if (!group) return;
    if (val === 'Other') {
        group.style.display = 'flex';
        input.required      = true;
    } else {
        group.style.display = 'none';
        input.required      = false;
        input.value         = '';
    }
}

// ── Document file display ──────────────────────────────────
document.querySelectorAll('.doc-file-input').forEach(input => {
    input.addEventListener('change', function() {
        const field   = this.id;
        const cardEl  = document.getElementById('card_'  + field);
        const errEl   = document.getElementById('err_'   + field);
        const fnameEl = document.getElementById('fname_' + field);
        const fmetaEl = document.getElementById('fmeta_' + field);

        cardEl.classList.remove('has-file', 'has-error');
        errEl.textContent = '';

        if (!this.files || !this.files[0]) return;

        const file    = this.files[0];
        const sizeMB  = (file.size / 1024 / 1024).toFixed(2);
        const ext     = file.name.split('.').pop().toLowerCase();
        const allowed = ['pdf', 'jpg', 'jpeg', 'png'];

        if (!allowed.includes(ext)) {
            cardEl.classList.add('has-error');
            fnameEl.textContent = file.name;
            fmetaEl.textContent = sizeMB + ' MB | Invalid type';
            errEl.textContent   = 'Only PDF, JPG, PNG files are allowed.';
            return;
        }

        if (file.size > MAX_FILE_SIZE) {
            cardEl.classList.add('has-error');
            fnameEl.textContent = file.name;
            fmetaEl.textContent = sizeMB + ' MB | Exceeds 1 MB limit';
            errEl.textContent   = 'File too large. Maximum is 1 MB. Please remove and choose a smaller file.';
            return;
        }

        cardEl.classList.add('has-file');
        fnameEl.textContent = file.name;
        fmetaEl.textContent = sizeMB + ' MB | ' + ext.toUpperCase();
    });
});

function clearDoc(field) {
    const input   = document.getElementById(field);
    const cardEl  = document.getElementById('card_'  + field);
    const errEl   = document.getElementById('err_'   + field);
    const fnameEl = document.getElementById('fname_' + field);
    const fmetaEl = document.getElementById('fmeta_' + field);

    input.value         = '';
    errEl.textContent   = '';
    fnameEl.textContent = '';
    fmetaEl.textContent = '';
    cardEl.classList.remove('has-file', 'has-error');
}

// ── Pincode status helper ──────────────────────────────────
function showPincodeStatus(msg, type) {
    const el = document.getElementById('pincodeStatus');
    el.textContent   = msg;
    el.className     = 'pincode-status ' + type;
    el.style.display = 'block';
}

// ── Reset address fields ───────────────────────────────────
function resetAddressFields() {
    const stateEl    = document.getElementById('state');
    const districtEl = document.getElementById('district');
    const citySelect = document.getElementById('citySelect');
    const cityManual = document.getElementById('cityManualInput');

    stateEl.value       = '';
    districtEl.value    = '';
    stateEl.readOnly    = true;
    districtEl.readOnly = true;
    stateEl.style.background    = '';
    districtEl.style.background = '';

    citySelect.innerHTML = '<option value="">Enter pincode first</option>';
    citySelect.disabled  = true;
    citySelect.style.display = '';
    cityManual.style.display = 'none';
    cityManual.value = '';

    document.getElementById('pincodeStatus').style.display    = 'none';
    document.getElementById('manualAddressNote').style.display = 'none';
}

// ── Unlock manual entry when pincode not found ─────────────
function unlockManualEntry() {
    const stateEl    = document.getElementById('state');
    const districtEl = document.getElementById('district');
    stateEl.readOnly    = false;
    districtEl.readOnly = false;
    stateEl.style.background    = '#fff';
    districtEl.style.background = '#fff';
    stateEl.placeholder    = 'Enter state manually';
    districtEl.placeholder = 'Enter district manually';

    const citySelect = document.getElementById('citySelect');
    citySelect.innerHTML = '<option value="">Not required — use Village/Area below</option>';
    citySelect.disabled  = true;

    document.getElementById('manualAddressNote').style.display = 'block';
}

// ── Pincode lookup ─────────────────────────────────────────
async function lookupPincode() {
    const pin = document.getElementById('pincode').value.trim();
    if (!/^\d{6}$/.test(pin)) {
        showPincodeStatus('Please enter a valid 6-digit pincode.', 'error');
        return;
    }

    showPincodeStatus('🔍 Looking up pincode…', 'loading');
    resetAddressFields();

    try {
        const res  = await fetch('https://api.postalpincode.in/pincode/' + pin);
        const data = await res.json();

        if (data[0].Status === 'Success') {
            const offices    = data[0].PostOffice;
            const state      = offices[0].State;
            const district   = offices[0].District;

            const stateEl    = document.getElementById('state');
            const districtEl = document.getElementById('district');
            stateEl.value    = state;
            districtEl.value = district;
            stateEl.style.background    = '#e8f5e9';
            districtEl.style.background = '#e8f5e9';

            // Build city dropdown
            const citySelect = document.getElementById('citySelect');
            const seen       = new Set();
            citySelect.innerHTML = '<option value="">Select City / Town / Village</option>';

            offices.forEach(o => {
                if (!seen.has(o.Name)) {
                    seen.add(o.Name);
                    const opt       = document.createElement('option');
                    opt.value       = o.Name;
                    opt.textContent = o.Name;
                    citySelect.appendChild(opt);
                }
            });

            // "Type manually" option
            const manualOpt       = document.createElement('option');
            manualOpt.value       = '__manual__';
            manualOpt.textContent = '✏️  Type manually…';
            citySelect.appendChild(manualOpt);

            citySelect.disabled = false;
            if (offices.length > 0) citySelect.value = offices[0].Name;

            citySelect.addEventListener('change', function onCityChange() {
                if (citySelect.value === '__manual__') {
                    citySelect.style.display = 'none';
                    const manualInput        = document.getElementById('cityManualInput');
                    manualInput.style.display = 'block';
                    manualInput.focus();
                    citySelect.removeEventListener('change', onCityChange);
                }
            });

            showPincodeStatus('✅ ' + district + ', ' + state + ' — select your locality below', 'success');
            document.getElementById('manualAddressNote').style.display = 'none';

        } else {
            showPincodeStatus('⚠️ Pincode not found — please fill address manually.', 'warn');
            unlockManualEntry();
        }
    } catch (e) {
        showPincodeStatus('⚠️ Lookup failed — please fill State and District manually.', 'warn');
        unlockManualEntry();
    }
}

// ── Form submit validation ─────────────────────────────────
document.querySelector('form').addEventListener('submit', function(e) {

    const qual = document.getElementById('qualification').value;
    if ((qual === 'Other' || qual === 'Fellowship') && !document.getElementById('otherQualification').value.trim()) {
        e.preventDefault();
        alert('Please specify the qualification.');
        document.getElementById('otherQualification').focus();
        return;
    }

    const spec = document.getElementById('specialization').value;
    if (spec === 'Other' && !document.getElementById('otherSpecialization').value.trim()) {
        e.preventDefault();
        alert('Please specify the specialization.');
        document.getElementById('otherSpecialization').focus();
        return;
    }

    // If manual city visible, disable dropdown so only cityManual is submitted
    const manualInput = document.getElementById('cityManualInput');
    if (manualInput.style.display !== 'none') {
        document.getElementById('citySelect').disabled = true;
    }

    const errorCards = document.querySelectorAll('.doc-card.has-error');
    if (errorCards.length) {
        e.preventDefault();
        alert('Please remove documents that exceed 1 MB or have invalid file types before submitting.');
        return;
    }
});
</script>
</body>
</html>
