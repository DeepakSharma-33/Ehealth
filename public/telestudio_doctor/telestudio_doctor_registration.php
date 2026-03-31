<?php
require_once __DIR__ . '/../../config/config.php';
require_role('admin', '../admin/admin_login.php');

$admin_name = get_session_name();
$admin_id   = get_session_id();
$db         = db();

// ── Generate Sequential Doctor ID: TSD{YYYY}{MM}{NNN} ─────
function generate_doctor_id($db) {
    $prefix = 'TSD' . date('Y') . date('m'); // e.g. TSD202603
    $stmt   = $db->prepare(
        "SELECT doctor_id FROM telestudio_doctors
         WHERE doctor_id LIKE ? ORDER BY doctor_id DESC LIMIT 1"
    );
    $like = $prefix . '%';
    $stmt->bind_param('s', $like);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $seq = $row ? str_pad((int)substr($row['doctor_id'], -3) + 1, 3, '0', STR_PAD_LEFT) : '001';
    return $prefix . $seq; // e.g. TSD202603001
}

// ── Sanitise filename ──────────────────────────────────────
function safe_filename($name) {
    $name = preg_replace('/[^a-zA-Z0-9.\-_]/', '_', $name);
    return strtolower(preg_replace('/_{2,}/', '_', $name));
}

// ── Send Twilio SMS ────────────────────────────────────────
function send_doctor_sms($mobile, $doctor_id) {
    $to  = '+91' . $mobile;
    $msg = "Welcome to eHealth Telestudio! Your Doctor ID is {$doctor_id} and this is also your default password. Please change it after your first login. Thank you for joining the eHealth team!";

    $url  = 'https://api.twilio.com/2010-04-01/Accounts/' . TWILIO_SID . '/Messages.json';
    $data = http_build_query([
        'To'                  => $to,
        'MessagingServiceSid' => TWILIO_MESSAGING_SID,
        'Body'                => $msg,
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $data,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => TWILIO_SID . ':' . TWILIO_AUTH_TOKEN,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT        => 10,
    ]);
    $response = curl_exec($ch);
    $err      = curl_error($ch);
    curl_close($ch);

    if ($err) return false;
    $res = json_decode($response, true);
    return isset($res['sid']);
}

$modal_data = null;
$form_error = '';
$doc_warnings = [];

function log_doc_issue(string $message): void {
    $log_dir = __DIR__ . '/../../logs';
    if (!is_dir($log_dir)) mkdir($log_dir, 0755, true);
    $log_file = $log_dir . '/telestudio_doc_upload.log';
    error_log('[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL, 3, $log_file);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ── Collect inputs ─────────────────────────────────────
    $full_name           = trim($_POST['fullName']           ?? '');
    $gender              = trim($_POST['gender']             ?? '');
    $dob                 = trim($_POST['dob']                ?? '');
    $experience          = (int)($_POST['experience']        ?? 0);
    $license_number      = trim($_POST['licenseNumber']      ?? '');
    $registration_number = trim($_POST['registrationNumber'] ?? '');
    $hpr_id              = trim($_POST['hprId']              ?? '') ?: null;
    $qualification       = trim($_POST['qualification']      ?? '');
    $other_qual          = trim($_POST['otherQualification'] ?? '');
    $specialization      = trim($_POST['specialization']     ?? '');
    $other_spec          = trim($_POST['otherSpecialization'] ?? '');
    $other_doc_label     = trim($_POST['otherDocName']       ?? '');
    $mobile_no           = preg_replace('/\D/', '', $_POST['mobileNo']  ?? '');
    $email               = trim($_POST['email']              ?? '');
    $aadhar_no           = preg_replace('/\D/', '', $_POST['aadharNo']  ?? '');
    $pincode             = preg_replace('/\D/', '', $_POST['pincode']   ?? '');
    $state               = trim($_POST['state']              ?? '');
    $district            = trim($_POST['district']           ?? '');
    $city                = trim($_POST['city']               ?? '') ?: null;
    $village             = trim($_POST['village']            ?? '') ?: null;
    $street              = trim($_POST['street']             ?? '') ?: null;

    // Resolve "Other" qualification / specialization
    if ($qualification === 'Other' || $qualification === 'Fellowship') {
        $qualification = $other_qual ?: $qualification;
    }
    if ($specialization === 'Other') {
        $specialization = $other_spec ?: $specialization;
    }

    $errors = [];

    // ── JS-side 1 MB check is mirrored here ───────────────
    $doc_fields = ['fileDegree', 'filePG', 'fileCert', 'fileOther'];
    $max_bytes  = 1 * 1024 * 1024; // 1 MB
    foreach ($doc_fields as $field) {
        if (!empty($_FILES[$field]['tmp_name']) && $_FILES[$field]['error'] === UPLOAD_ERR_OK) {
            if ($_FILES[$field]['size'] > $max_bytes) {
                $labels = ['fileDegree'=>'Degree Certificate','filePG'=>'PG Degree','fileCert'=>'Certificate','fileOther'=>'Other Document'];
                $errors[] = $labels[$field] . ' exceeds 1 MB limit (' . round($_FILES[$field]['size']/1024/1024, 2) . ' MB uploaded).';
            }
            $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['pdf','jpg','jpeg','png'])) {
                $errors[] = 'Invalid file type for document. Only PDF, JPG, PNG allowed.';
            }
        }
        if (!empty($_FILES[$field]['error']) && $_FILES[$field]['error'] === UPLOAD_ERR_INI_SIZE) {
            $errors[] = 'A document file exceeds server upload limit.';
        }
    }

    // ── Field validation ───────────────────────────────────
    if (!$full_name)                                   $errors[] = 'Full name is required.';
    if (!in_array($gender, ['Male','Female','Other'])) $errors[] = 'Gender is required.';
    if (!$dob || !strtotime($dob))                     $errors[] = 'Valid date of birth is required.';
    if ($experience < 0 || $experience > 60)           $errors[] = 'Experience must be 0–60 years.';
    if (!$license_number)                              $errors[] = 'License number is required.';
    if (!$registration_number)                         $errors[] = 'Registration number is required.';
    if (!$qualification)                               $errors[] = 'Qualification is required.';
    if (!$specialization)                              $errors[] = 'Specialization is required.';
    if (strlen($mobile_no) !== 10)                     $errors[] = 'Mobile must be 10 digits.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))    $errors[] = 'Invalid email address.';
    if (strlen($aadhar_no) !== 12)                     $errors[] = 'Aadhar must be 12 digits.';
    if (strlen($pincode)   !== 6)                      $errors[] = 'Pincode must be 6 digits.';
    if (!$state)                                       $errors[] = 'State is required.';
    if (!$district)                                    $errors[] = 'District is required.';

    // ── Duplicate checks ───────────────────────────────────
    if (!$errors) {
        $dup_checks = [
            ['mobile_no',          $mobile_no,           'Mobile number'],
            ['email',              $email,                'Email address'],
            ['aadhar_no',          $aadhar_no,            'Aadhar number'],
            ['license_number',     $license_number,       'License number'],
            ['registration_number',$registration_number,  'Registration number'],
        ];
        foreach ($dup_checks as [$col, $val, $label]) {
            $dup = $db->prepare("SELECT doctor_id FROM telestudio_doctors WHERE `$col` = ? LIMIT 1");
            $dup->bind_param('s', $val);
            $dup->execute();
            $dup->store_result();
            if ($dup->num_rows > 0) $errors[] = "$label is already registered.";
            $dup->close();
        }
    }

    if (!$errors) {
        $doctor_id = generate_doctor_id($db);
        $password  = hash_password($doctor_id);

        // ── INSERT doctor ──────────────────────────────────
        $stmt = $db->prepare(
            'INSERT INTO telestudio_doctors
             (doctor_id, full_name, gender, dob, experience_years,
              license_number, registration_number, hpr_id,
              qualification, specialization,
              mobile_no, email, aadhar_no,
              country, state, district, city, village, street, pincode,
              password, registered_by_admin, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())'
        );
        $country = 'India';
        // 22 placeholders: s s s s i s s s s s s s s s s s s s s s s i
        $stmt->bind_param(
            'ssssissssssssssssssssi',
            $doctor_id, $full_name, $gender, $dob, $experience,
            $license_number, $registration_number, $hpr_id,
            $qualification, $specialization,
            $mobile_no, $email, $aadhar_no,
            $country, $state, $district, $city, $village, $street, $pincode,
            $password, $admin_id
        );

        if (!$stmt->execute()) {
            $errors[] = 'Database error: ' . $db->error;
            $stmt->close();
        } else {
            $stmt->close();

            // ── Save documents to uploads/doctors/{doctor_id}/ ──
            $doc_dir = __DIR__ . '/../../uploads/doctors/' . $doctor_id . '/';
            if (!is_dir($doc_dir)) mkdir($doc_dir, 0755, true);

            $doc_map = [
                'fileDegree' => ['degree',      'Degree Certificate'],
                'filePG'     => ['pg_degree',   'PG Degree'],
                'fileCert'   => ['certificate', 'Certificate'],
                'fileOther'  => ['other',       $other_doc_label ?: 'Other Document'],
            ];

            $docs_uploaded = [];
            $docs_received = [];
            foreach ($doc_map as $field => $_meta) {
                if (!empty($_FILES[$field]['tmp_name']) && $_FILES[$field]['error'] === UPLOAD_ERR_OK) {
                    $docs_received[] = $field;
                } elseif (!empty($_FILES[$field]['error']) && $_FILES[$field]['error'] !== UPLOAD_ERR_NO_FILE) {
                    $err_code = (int)$_FILES[$field]['error'];
                    $err_name = $_FILES[$field]['name'] ?? '';
                    log_doc_issue("upload error for doctor_id={$doctor_id}, field={$field}, code={$err_code}, name={$err_name}");
                }
            }
            if (empty($docs_received)) {
                $doc_warnings[] = 'No document files were received by the server.';
                log_doc_issue("no document files received for doctor_id={$doctor_id}");
            }

            foreach ($doc_map as $field => [$doc_type, $doc_label]) {
                if (empty($_FILES[$field]['tmp_name']) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) continue;

                $orig_name = $_FILES[$field]['name'];
                $ext       = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
                $base      = safe_filename(pathinfo($orig_name, PATHINFO_FILENAME));
                $final_name = $doc_type . '_' . $base . '.' . $ext;
                $final_path = $doc_dir . $final_name;
                $rel_path   = 'uploads/doctors/' . $doctor_id . '/' . $final_name;

                if (!move_uploaded_file($_FILES[$field]['tmp_name'], $final_path)) {
                    $doc_warnings[] = $doc_label . ' failed to save on server.';
                    log_doc_issue("move_uploaded_file failed for doctor_id={$doctor_id}, field={$field}, file={$orig_name}");
                    continue;
                }

                // Insert document record
                $ds = $db->prepare(
                    'INSERT INTO telestudio_doctor_documents
                     (doctor_id, doc_type, doc_label, file_name, file_path, file_size, mime_type, uploaded_at)
                     VALUES (?,?,?,?,?,?,?,NOW())'
                );
                if (!$ds) {
                    $doc_warnings[] = $doc_label . ' failed to save in database.';
                    log_doc_issue("prepare failed for telestudio_doctor_documents: " . $db->error);
                } else {
                    $file_size = $_FILES[$field]['size'];
                    $mime      = $_FILES[$field]['type'];
                    $ds->bind_param('sssssis', $doctor_id, $doc_type, $doc_label, $orig_name, $rel_path, $file_size, $mime);
                    if (!$ds->execute()) {
                        $doc_warnings[] = $doc_label . ' failed to save in database.';
                        log_doc_issue("execute failed for telestudio_doctor_documents (doctor_id={$doctor_id}, field={$field}): " . $ds->error);
                    }
                    $ds->close();
                }

                $docs_uploaded[] = $doc_label;
            }

            // ── Send Twilio SMS ────────────────────────────
            $sms_sent = send_doctor_sms($mobile_no, $doctor_id);

            $modal_data = [
                'doctor_id'     => $doctor_id,
                'full_name'     => $full_name,
                'gender'        => $gender,
                'qualification' => $qualification,
                'specialization'=> $specialization,
                'mobile_no'     => $mobile_no,
                'email'         => $email,
                'aadhar_no'     => substr($aadhar_no,0,4).'XXXX'.substr($aadhar_no,8),
                'license'       => $license_number,
                'state'         => $state,
                'district'      => $district,
                'docs_uploaded' => $docs_uploaded,
                'doc_warnings'  => $doc_warnings,
                'sms_sent'      => $sms_sent,
                'datetime'      => date('d M Y, h:i A'),
            ];
        }
    }

if ($errors) $form_error = implode('<br>', $errors);
}

$has_success_modal = !empty($modal_data);

// ── Helper to re-fill POST values ─────────────────────────
function old($key, $default = '') {
    return htmlspecialchars($_POST[$key] ?? $default);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Telestudio Doctor Registration - eHealth</title>
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif; background:linear-gradient(135deg,#667eea 0%,#764ba2 100%); min-height:100vh; padding:20px; }
        .container { max-width:960px; margin:0 auto; background:white; border-radius:16px; box-shadow:0 12px 40px rgba(0,0,0,.25); overflow:hidden; }

        /* Top bar */
        .topbar { display:flex; align-items:center; justify-content:space-between; background:linear-gradient(135deg,#2e7d32 0%,#43a047 100%); padding:12px 22px; border-radius:14px; box-shadow:0 8px 20px rgba(0,0,0,.18); margin:0 auto 18px; max-width:960px; color:#fff; }
        .topbar .brand { display:flex; align-items:center; gap:10px; text-decoration:none; color:inherit; font-weight:800; letter-spacing:1px; }
        .topbar .brand img { height:34px; width:auto; display:block; object-fit:contain; filter:drop-shadow(0 1px 3px rgba(0,0,0,.35)); }
        .topbar .nav { display:flex; align-items:center; gap:12px; }
        .topbar .nav a { color:#fff; text-decoration:none; font-size:13px; font-weight:700; padding:6px 12px; border-radius:999px; background:rgba(255,255,255,.14); transition:opacity .2s, transform .2s; }
        .topbar .nav a:hover { opacity:.9; transform:translateY(-1px); }

        /* Header */
        .header { background:linear-gradient(135deg,#2e7d32 0%,#43a047 100%); color:white; padding:30px; text-align:center; }
        .header-logo { display:flex; align-items:center; justify-content:center; gap:14px; margin-bottom:8px; text-decoration:none; color:inherit; }
        .header-brand-logo {
            width: auto;
            display: block;
            object-fit: contain;
            filter: drop-shadow(0 1px 3px rgba(0,0,0,.35));
        }
        .header-brand-logo.srmst-logo { height: 48px; }
        .header-brand-text {
            font-size: 22px;
            font-weight: 800;
            letter-spacing: 1px;
            color: #fff;
            text-shadow: 0 1px 2px rgba(0,0,0,.35);
            white-space: nowrap;
        }
        .header h1   { font-size:26px; margin-bottom:6px; font-weight:700; }
        .header p    { font-size:14px; opacity:.88; }

        /* Admin banner */
        .admin-banner { background:linear-gradient(135deg,#e8f5e9,#f1f8e9); border:1.5px solid #a5d6a7; border-radius:10px; padding:12px 18px; display:flex; align-items:center; gap:14px; margin-bottom:26px; }
        .admin-avatar { width:38px; height:38px; background:linear-gradient(135deg,#2e7d32,#43a047); border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:18px; flex-shrink:0; }
        .admin-info { flex:1; }
        .admin-label { font-size:11px; font-weight:600; color:#5a7a6a; text-transform:uppercase; letter-spacing:.5px; }
        .admin-name  { font-size:15px; font-weight:700; color:#1b3a1e; }
        .admin-badge { background:#2e7d32; color:white; font-size:11px; font-weight:600; padding:4px 10px; border-radius:20px; }

        /* Form */
        .form-container { padding:36px 40px; }
        .section-title { font-size:15px; color:#2e7d32; margin:28px 0 18px; padding-bottom:8px; border-bottom:2px solid #2e7d32; display:flex; align-items:center; gap:8px; font-weight:700; }
        .section-title:first-of-type { margin-top:0; }

        .form-row { display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:18px; margin-bottom:18px; }
        .form-group { display:flex; flex-direction:column; }
        .form-group.full-width { grid-column:1/-1; }
        .form-group label { font-size:13px; font-weight:600; color:#333; margin-bottom:6px; }
        .required { color:#e74c3c; }

        .form-group input,.form-group select,.form-group textarea { padding:11px 14px; border:2px solid #e0e0e0; border-radius:8px; font-size:14px; transition:border-color .25s,box-shadow .25s; font-family:inherit; background:white; }
        .form-group input:focus,.form-group select:focus,.form-group textarea:focus { outline:none; border-color:#2e7d32; box-shadow:0 0 0 3px rgba(46,125,50,.12); }
        .form-group input[readonly] { background:#f5f5f5; cursor:default; }
        .form-group textarea { resize:vertical; min-height:80px; }

        /* Pincode */
        .pincode-row { display:flex; gap:10px; }
        .pincode-row input { flex:1; padding:11px 14px; border:2px solid #e0e0e0; border-radius:8px; font-size:14px; font-family:inherit; transition:border-color .25s; }
        .pincode-row input:focus { outline:none; border-color:#2e7d32; box-shadow:0 0 0 3px rgba(46,125,50,.12); }
        .lookup-btn { padding:11px 18px; background:linear-gradient(135deg,#2e7d32,#43a047); color:white; border:none; border-radius:8px; font-size:13px; font-weight:600; cursor:pointer; white-space:nowrap; transition:opacity .2s; font-family:inherit; }
        .lookup-btn:hover { opacity:.88; }
        .pincode-status { display:none; font-size:13px; margin-top:6px; padding:7px 12px; border-radius:6px; }
        .pincode-status.success { background:#d4edda; color:#155724; display:block; }
        .pincode-status.error   { background:#f8d7da; color:#721c24; display:block; }
        .pincode-status.loading { background:#d1ecf1; color:#0c5460; display:block; }
        .manual-note { font-size:12px; color:#856404; background:#fff3cd; padding:7px 12px; border-radius:6px; margin-top:6px; }

        /* Radio */
        .radio-group { display:flex; gap:20px; margin-top:8px; flex-wrap:wrap; }
        .radio-option { display:flex; align-items:center; gap:7px; }
        .radio-option input[type="radio"] { width:17px; height:17px; accent-color:#2e7d32; cursor:pointer; }
        .radio-option label { margin:0; font-weight:normal; cursor:pointer; }

        /* Alert */
        .alert { padding:14px 18px; border-radius:8px; margin-bottom:20px; line-height:1.6; font-size:14px; }
        .alert.error   { background:#f8d7da; color:#721c24; border-left:4px solid #dc3545; }
        .alert.success { background:#d4edda; color:#155724; border-left:4px solid #28a745; }

        /* Document upload cards */
        .doc-upload-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:16px; margin-top:6px; }
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
        .doc-size-badge { font-size:10px; color:#888; margin-left:4px; }

        /* Buttons */
        .btn-container { display:flex; gap:14px; margin-top:36px; justify-content:center; }
        .btn { padding:13px 38px; border:none; border-radius:8px; font-size:15px; font-weight:600; cursor:pointer; transition:transform .2s,box-shadow .2s,opacity .2s; text-transform:uppercase; letter-spacing:.4px; font-family:inherit; }
        .btn-primary   { background:linear-gradient(135deg,#2e7d32 0%,#43a047 100%); color:white; }
        .btn-primary:hover:not(:disabled) { transform:translateY(-2px); box-shadow:0 5px 18px rgba(46,125,50,.4); }
        .btn-secondary { background:#6c757d; color:white; }
        .btn-secondary:hover:not(:disabled) { background:#5a6268; transform:translateY(-2px); }
        .btn:disabled  { opacity:.6; cursor:not-allowed; }
        .spinner-inline { display:inline-block; width:15px; height:15px; border:2px solid rgba(255,255,255,.4); border-top-color:white; border-radius:50%; animation:spin .7s linear infinite; vertical-align:middle; margin-right:8px; }
        @keyframes spin { to { transform:rotate(360deg); } }

        .info-text { font-size:12px; color:#6c757d; margin-top:5px; }
        .hidden { display:none !important; }

        /* Success Modal */
        body.modal-open .container { filter: blur(6px); pointer-events: none; user-select: none; }
        .modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.55); backdrop-filter:blur(4px); z-index:1000; align-items:center; justify-content:center; padding:20px; }
        .modal-overlay.show { display:flex; animation:fadeIn .3s ease; }
        @keyframes fadeIn { from{opacity:0} to{opacity:1} }
        .modal-card { background:white; border-radius:18px; max-width:500px; width:100%; box-shadow:0 24px 60px rgba(0,0,0,.25); overflow:hidden; animation:popUp .4s cubic-bezier(.34,1.56,.64,1); max-height:90vh; overflow-y:auto; }
        @keyframes popUp { from{opacity:0;transform:scale(.85) translateY(20px)} to{opacity:1;transform:scale(1) translateY(0)} }
        .modal-header { background:linear-gradient(135deg,#2e7d32,#43a047); color:white; padding:24px 28px 18px; text-align:center; }
        .modal-check { width:58px; height:58px; background:rgba(255,255,255,.2); border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:28px; margin:0 auto 12px; }
        .modal-header h2 { font-size:20px; font-weight:700; }
        .modal-body { padding:22px 28px 26px; }
        .modal-id-badge { text-align:center; margin-bottom:18px; background:#e8f5e9; border:2px solid #a5d6a7; border-radius:12px; padding:14px; }
        .modal-id-label { font-size:11px; color:#5a7a6a; text-transform:uppercase; letter-spacing:1px; margin-bottom:4px; }
        .modal-id-value { font-size:26px; font-weight:800; color:#2e7d32; letter-spacing:2px; }
        .modal-grid { display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:14px; }
        .modal-item.full { grid-column:1/-1; }
        .modal-item-label { font-size:11px; font-weight:600; color:#888; text-transform:uppercase; letter-spacing:.4px; margin-bottom:2px; }
        .modal-item-value { font-size:13px; font-weight:600; color:#1b3a1e; }
        .modal-docs { background:#f0f7f0; border:1px solid #c8e6c9; border-radius:8px; padding:10px 14px; margin-bottom:14px; font-size:13px; }
        .modal-docs-title { font-weight:700; color:#2e7d32; margin-bottom:6px; font-size:12px; text-transform:uppercase; }
        .modal-doc-item { color:#1b5e20; font-size:12px; padding:2px 0; }
        .modal-sms { border-radius:8px; padding:10px 14px; margin-bottom:16px; display:flex; align-items:center; gap:10px; font-size:13px; }
        .modal-sms.sent   { background:#e8f5e9; border:1px solid #a5d6a7; }
        .modal-sms.failed { background:#fff8e1; border:1px solid #ffd54f; }
        .modal-actions { display:flex; gap:12px; }
        .modal-btn { flex:1; padding:11px; border-radius:8px; font-size:14px; font-weight:700; cursor:pointer; font-family:inherit; border:none; transition:opacity .2s; }
        .modal-btn.print  { background:#f0a500; color:#1a1a1a; }
        .modal-btn.dashboard { background:#1976d2; color:#fff; text-decoration:none; display:inline-flex; align-items:center; justify-content:center; }
        .modal-btn.close  { background:#2e7d32; color:white; }
        .modal-btn:hover  { opacity:.88; }

        @media(max-width:680px) { .form-container{padding:20px} .form-row{grid-template-columns:1fr} .btn-container{flex-direction:column} .btn{width:100%} .modal-grid{grid-template-columns:1fr} }
        @media print {
            body > * { display:none !important; }
            #successModal { display:block !important; position:static !important; background:none !important; backdrop-filter:none !important; padding:0 !important; }
            .modal-card { box-shadow:none !important; border:1px solid #ccc !important; max-height:none !important; overflow:visible !important; animation:none !important; }
            .modal-actions { display:none !important; }
        }
    </style>
</head>
<body class="<?= $has_success_modal ? 'modal-open' : '' ?>">
<div class="topbar">
    <a class="brand" href="<?= htmlspecialchars(app_base_url()) ?>/index.php">
        <img src="<?= htmlspecialchars(app_base_url()) ?>/public/assets/SRMS_TRUST_LOGO.png" alt="SRMST Trust Logo">
        <span>SRMS EHEALTH</span>
    </a>
    <div class="nav">
        <a href="<?= htmlspecialchars(app_base_url()) ?>/index.php">HOME</a>
        <a href="<?= htmlspecialchars(app_base_url()) ?>/public/admin/admin_dashboard.php">DASHBOARD</a>
    </div>
</div>
<div class="container">

    <div class="header">
        <a href="<?= htmlspecialchars(app_base_url()) ?>/" class="header-logo">
            <img src="<?= htmlspecialchars(app_base_url()) ?>/public/assets/SRMS_TRUST_LOGO.png" alt="SRMST Trust Logo" class="header-brand-logo srmst-logo">
            <span class="header-brand-text">SRMS EHEALTH</span>
        </a>
        <h1>Telestudio Doctor Registration</h1>
        <p>Register a new Telestudio Doctor — Admin access required</p>
    </div>

    <div class="form-container">

        <!-- Admin Banner -->
        <div class="admin-banner">
            <div class="admin-avatar">👨‍💼</div>
            <div class="admin-info">
                <div class="admin-label">Registering as Admin</div>
                <div class="admin-name"><?= htmlspecialchars($admin_name) ?></div>
            </div>
            <div class="admin-badge">✓ Admin</div>
        </div>

        <?php if ($form_error): ?>
            <div class="alert error">❌ <?= $form_error ?></div>
        <?php endif; ?>

        <form id="doctorForm" method="POST" enctype="multipart/form-data" novalidate>

            <!-- 1. Personal Info -->
            <div class="section-title">👤 Personal Information</div>

            <div class="form-row">
                <div class="form-group">
                    <label for="fullName">Full Name <span class="required">*</span></label>
                    <div class="info-text" style="margin-top:-4px;margin-bottom:6px;">Please start the name with "Dr." (e.g., Dr. Anil Kumar).</div>
                    <input type="text" id="fullName" name="fullName" required
                           value="<?= old('fullName') ?>" placeholder="Dr. John Doe">
                </div>
                <div class="form-group">
                    <label>Gender <span class="required">*</span></label>
                    <div class="radio-group">
                        <?php foreach (['Male','Female','Other'] as $g): ?>
                        <div class="radio-option">
                            <input type="radio" id="gender<?= $g ?>" name="gender" value="<?= $g ?>"
                                   <?= (old('gender') === $g) ? 'checked' : '' ?>>
                            <label for="gender<?= $g ?>"><?= $g ?></label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="dob">Date of Birth <span class="required">*</span></label>
                    <input type="date" id="dob" name="dob" required
                           value="<?= old('dob') ?>" max="<?= date('Y-m-d') ?>">
                </div>
                <div class="form-group">
                    <label for="experience">Experience (Years) <span class="required">*</span></label>
                    <input type="number" id="experience" name="experience" required min="0" max="60"
                           value="<?= old('experience') ?>" placeholder="e.g. 5">
                </div>
            </div>

            <!-- 2. Medical Credentials -->
            <div class="section-title">🎓 Medical Credentials</div>

            <div class="form-row">
                <div class="form-group">
                    <label for="licenseNumber">License Number <span class="required">*</span></label>
                    <input type="text" id="licenseNumber" name="licenseNumber" required
                           value="<?= old('licenseNumber') ?>" placeholder="MED123456">
                </div>
                <div class="form-group">
                    <label for="registrationNumber">Registration Number <span class="required">*</span></label>
                    <input type="text" id="registrationNumber" name="registrationNumber" required
                           value="<?= old('registrationNumber') ?>" placeholder="REG/2025/001234">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="hprId">HPR ID <span style="font-weight:400;color:#888">(Optional)</span></label>
                    <input type="text" id="hprId" name="hprId"
                           value="<?= old('hprId') ?>" placeholder="HPR12345678">
                </div>
                <div class="form-group">
                    <label for="qualification">Qualification <span class="required">*</span></label>
                    <select id="qualification" name="qualification" required>
                        <option value="">Select Qualification</option>
                        <optgroup label="── Undergraduate – Modern Medicine ──">
                            <option value="MBBS" <?= old('qualification')==='MBBS'?'selected':'' ?>>MBBS</option>
                            <option value="BDS"  <?= old('qualification')==='BDS'?'selected':'' ?>>BDS – Bachelor of Dental Surgery</option>
                            <option value="BVSC" <?= old('qualification')==='BVSC'?'selected':'' ?>>BVSc – Veterinary Science</option>
                        </optgroup>
                        <optgroup label="── Postgraduate – Modern Medicine ──">
                            <option value="MBBS+MD"  <?= old('qualification')==='MBBS+MD'?'selected':'' ?>>MBBS + MD</option>
                            <option value="MBBS+MS"  <?= old('qualification')==='MBBS+MS'?'selected':'' ?>>MBBS + MS</option>
                            <option value="MBBS+DNB" <?= old('qualification')==='MBBS+DNB'?'selected':'' ?>>MBBS + DNB</option>
                            <option value="MD"  <?= old('qualification')==='MD'?'selected':'' ?>>MD – Doctor of Medicine</option>
                            <option value="MS"  <?= old('qualification')==='MS'?'selected':'' ?>>MS – Master of Surgery</option>
                            <option value="DNB" <?= old('qualification')==='DNB'?'selected':'' ?>>DNB – Diplomate of National Board</option>
                            <option value="MDS" <?= old('qualification')==='MDS'?'selected':'' ?>>MDS – Master of Dental Surgery</option>
                            <option value="MPH" <?= old('qualification')==='MPH'?'selected':'' ?>>MPH – Master of Public Health</option>
                        </optgroup>
                        <optgroup label="── Super Speciality ──">
                            <option value="DM"  <?= old('qualification')==='DM'?'selected':'' ?>>DM – Doctorate of Medicine</option>
                            <option value="MCh" <?= old('qualification')==='MCh'?'selected':'' ?>>MCh – Master of Chirurgiae</option>
                            <option value="MBBS+MD+DM"   <?= old('qualification')==='MBBS+MD+DM'?'selected':'' ?>>MBBS + MD + DM</option>
                            <option value="MBBS+MS+MCh"  <?= old('qualification')==='MBBS+MS+MCh'?'selected':'' ?>>MBBS + MS + MCh</option>
                            <option value="MBBS+DNB+DrNB"<?= old('qualification')==='MBBS+DNB+DrNB'?'selected':'' ?>>MBBS + DNB + DrNB</option>
                        </optgroup>
                        <optgroup label="── Ayurveda (AYUSH) ──">
                            <option value="BAMS" <?= old('qualification')==='BAMS'?'selected':'' ?>>BAMS – Ayurvedic Medicine &amp; Surgery</option>
                            <option value="BAMS+MD(Ayu)" <?= old('qualification')==='BAMS+MD(Ayu)'?'selected':'' ?>>BAMS + MD (Ayurveda)</option>
                            <option value="BAMS+MS(Ayu)" <?= old('qualification')==='BAMS+MS(Ayu)'?'selected':'' ?>>BAMS + MS (Ayurveda)</option>
                        </optgroup>
                        <optgroup label="── Homeopathy (AYUSH) ──">
                            <option value="BHMS" <?= old('qualification')==='BHMS'?'selected':'' ?>>BHMS – Homeopathic Medicine &amp; Surgery</option>
                            <option value="BHMS+MD(Hom)" <?= old('qualification')==='BHMS+MD(Hom)'?'selected':'' ?>>BHMS + MD (Homeopathy)</option>
                        </optgroup>
                        <optgroup label="── Unani (AYUSH) ──">
                            <option value="BUMS" <?= old('qualification')==='BUMS'?'selected':'' ?>>BUMS – Unani Medicine &amp; Surgery</option>
                            <option value="BUMS+MD(Unani)" <?= old('qualification')==='BUMS+MD(Unani)'?'selected':'' ?>>BUMS + MD (Unani)</option>
                        </optgroup>
                        <optgroup label="── Other AYUSH ──">
                            <option value="BNYS" <?= old('qualification')==='BNYS'?'selected':'' ?>>BNYS – Naturopathy &amp; Yogic Sciences</option>
                            <option value="BSMS" <?= old('qualification')==='BSMS'?'selected':'' ?>>BSMS – Siddha Medicine &amp; Surgery</option>
                        </optgroup>
                        <optgroup label="── Diploma ──">
                            <option value="DMLT" <?= old('qualification')==='DMLT'?'selected':'' ?>>DMLT – Medical Lab Technology</option>
                            <option value="DPM"  <?= old('qualification')==='DPM'?'selected':'' ?>>DPM – Psychological Medicine</option>
                            <option value="DCH"  <?= old('qualification')==='DCH'?'selected':'' ?>>DCH – Child Health</option>
                            <option value="DGO"  <?= old('qualification')==='DGO'?'selected':'' ?>>DGO – Obstetrics &amp; Gynaecology</option>
                            <option value="D.Ortho" <?= old('qualification')==='D.Ortho'?'selected':'' ?>>D.Ortho – Orthopaedics</option>
                            <option value="DTCD" <?= old('qualification')==='DTCD'?'selected':'' ?>>DTCD – Tuberculosis &amp; Chest Diseases</option>
                            <option value="DA"   <?= old('qualification')==='DA'?'selected':'' ?>>DA – Anaesthesia</option>
                            <option value="DLO"  <?= old('qualification')==='DLO'?'selected':'' ?>>DLO – Laryngology &amp; Otology</option>
                            <option value="DO"   <?= old('qualification')==='DO'?'selected':'' ?>>DO – Ophthalmology</option>
                            <option value="DPH"  <?= old('qualification')==='DPH'?'selected':'' ?>>DPH – Public Health</option>
                        </optgroup>
                        <optgroup label="── Fellowship / Certificate ──">
                            <option value="FRCGP"      <?= old('qualification')==='FRCGP'?'selected':'' ?>>FRCGP – Royal College of GPs</option>
                            <option value="Fellowship"  <?= old('qualification')==='Fellowship'?'selected':'' ?>>Fellowship (Specify)</option>
                        </optgroup>
                        <optgroup label="── Other ──">
                            <option value="Other" <?= old('qualification')==='Other'?'selected':'' ?>>Other (Please Specify)</option>
                        </optgroup>
                    </select>
                </div>
            </div>

            <div class="form-row hidden" id="otherQualificationGroup">
                <div class="form-group full-width">
                    <label for="otherQualification">Specify Qualification <span class="required">*</span></label>
                    <input type="text" id="otherQualification" name="otherQualification"
                           value="<?= old('otherQualification') ?>" placeholder="Enter your qualification">
                </div>
            </div>

            <!-- 3. Specialization -->
            <div class="section-title">🩺 Specialization</div>

            <div class="form-row">
                <div class="form-group">
                    <label for="specialization">Specialization <span class="required">*</span></label>
                    <select id="specialization" name="specialization" required>
                        <option value="">Select Specialization</option>
                        <optgroup label="── Primary Care ──">
                            <option value="General Physician">General Physician</option>
                            <option value="Family Medicine">Family Medicine</option>
                        </optgroup>
                        <optgroup label="── Medical Specialities ──">
                            <option value="Cardiology">Cardiology</option>
                            <option value="Neurology">Neurology</option>
                            <option value="Pulmonology">Pulmonology</option>
                            <option value="Gastroenterology">Gastroenterology</option>
                            <option value="Nephrology">Nephrology</option>
                            <option value="Endocrinology">Endocrinology</option>
                            <option value="Rheumatology">Rheumatology</option>
                            <option value="Oncology">Oncology</option>
                            <option value="Hematology">Hematology</option>
                            <option value="Infectious Disease">Infectious Disease</option>
                            <option value="Geriatrics">Geriatrics</option>
                        </optgroup>
                        <optgroup label="── Surgical Specialities ──">
                            <option value="General Surgery">General Surgery</option>
                            <option value="Orthopedics">Orthopedics</option>
                            <option value="Neurosurgery">Neurosurgery</option>
                            <option value="Cardiothoracic Surgery">Cardiothoracic Surgery</option>
                            <option value="Urology">Urology</option>
                            <option value="Vascular Surgery">Vascular Surgery</option>
                            <option value="Plastic Surgery">Plastic Surgery</option>
                        </optgroup>
                        <optgroup label="── Women &amp; Children ──">
                            <option value="Gynecology">Gynecology &amp; Obstetrics</option>
                            <option value="Pediatrics">Pediatrics</option>
                            <option value="Neonatology">Neonatology</option>
                        </optgroup>
                        <optgroup label="── Sensory / Head &amp; Neck ──">
                            <option value="Ophthalmology">Ophthalmology</option>
                            <option value="ENT">ENT (Ear, Nose &amp; Throat)</option>
                            <option value="Dermatology">Dermatology</option>
                            <option value="Dentistry">Dentistry</option>
                        </optgroup>
                        <optgroup label="── Mental Health ──">
                            <option value="Psychiatry">Psychiatry</option>
                            <option value="Clinical Psychology">Clinical Psychology</option>
                        </optgroup>
                        <optgroup label="── Diagnostics &amp; Support ──">
                            <option value="Radiology">Radiology</option>
                            <option value="Pathology">Pathology</option>
                            <option value="Anesthesiology">Anesthesiology</option>
                            <option value="Emergency Medicine">Emergency Medicine</option>
                            <option value="Physical Medicine">Physical Medicine &amp; Rehabilitation</option>
                        </optgroup>
                        <optgroup label="── Alternative Medicine ──">
                            <option value="Ayurveda">Ayurveda</option>
                            <option value="Homeopathy">Homeopathy</option>
                            <option value="Unani">Unani</option>
                            <option value="Naturopathy">Naturopathy</option>
                            <option value="Siddha">Siddha</option>
                        </optgroup>
                        <optgroup label="── Other ──">
                            <option value="Other">Other (Please Specify)</option>
                        </optgroup>
                    </select>
                </div>
            </div>

            <div class="form-row hidden" id="otherSpecGroup">
                <div class="form-group full-width">
                    <label for="otherSpecialization">Specify Specialization <span class="required">*</span></label>
                    <input type="text" id="otherSpecialization" name="otherSpecialization"
                           value="<?= old('otherSpecialization') ?>" placeholder="Enter your specialization">
                </div>
            </div>

            <!-- 4. Documents -->
            <div class="section-title">📄 Documents <span style="font-size:13px;font-weight:400;color:#888">(Optional — PDF, JPG, PNG · Max <strong>1 MB</strong> each)</span></div>

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
                        <button type="button" class="doc-upload-btn" onclick="document.getElementById('<?= $field ?>').click()">
                            📎 Choose File
                        </button>
                        <button type="button" class="doc-remove-btn" onclick="clearDoc('<?= $field ?>')">
                            🗑 Remove
                        </button>
                    </div>
                    <input type="file" id="<?= $field ?>" name="<?= $field ?>"
                           class="doc-file-input" accept=".pdf,.jpg,.jpeg,.png"
                           data-card="card_<?= $field ?>">
                    <div class="doc-file-preview" id="preview_<?= $field ?>">
                        <div class="doc-file-name"  id="fname_<?= $field ?>"></div>
                        <div class="doc-file-meta"  id="fmeta_<?= $field ?>"></div>
                    </div>
                    <div class="doc-error-msg" id="err_<?= $field ?>"></div>
                    <?php if ($field === 'fileOther'): ?>
                    <input type="text" name="otherDocName" id="otherDocName"
                           value="<?= old('otherDocName') ?>"
                           placeholder="Specify document name…"
                           style="margin-top:10px;padding:7px 10px;border:2px solid #e0e0e0;border-radius:6px;font-size:12px;width:100%;font-family:inherit;"
                           onfocus="this.style.borderColor='#2e7d32'" onblur="this.style.borderColor='#e0e0e0'">
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- 5. Contact -->
            <div class="section-title">📞 Contact Information</div>

            <div class="form-row">
                <div class="form-group">
                    <label for="mobileNo">Mobile Number <span class="required">*</span></label>
                    <input type="tel" id="mobileNo" name="mobileNo" required maxlength="10"
                           value="<?= old('mobileNo') ?>" placeholder="10-digit mobile number">
                    <div class="info-text">📱 Login credentials will be sent to this number</div>
                </div>
                <div class="form-group">
                    <label for="email">Email Address <span class="required">*</span></label>
                    <input type="email" id="email" name="email" required
                           value="<?= old('email') ?>" placeholder="doctor@email.com">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="aadharNo">Aadhar Number <span class="required">*</span></label>
                    <input type="text" id="aadharNo" name="aadharNo" required maxlength="12"
                           value="<?= old('aadharNo') ?>" placeholder="12-digit Aadhar number">
                </div>
            </div>

            <!-- 6. Address -->
            <div class="section-title">📍 Address Information</div>

            <div class="form-row">
                <div class="form-group full-width">
                    <label for="pincode">Pincode <span class="required">*</span>&nbsp;<span style="font-weight:400;font-size:12px;color:#2e7d32">— Enter pincode to auto-fill address</span></label>
                    <div class="pincode-row">
                        <input type="text" id="pincode" name="pincode" required maxlength="6"
                               value="<?= old('pincode') ?>" placeholder="Enter 6-digit pincode">
                        <button type="button" class="lookup-btn" id="pincodeLookupBtn">🔍 Lookup</button>
                    </div>
                    <div class="pincode-status" id="pincodeStatus"></div>
                    <div class="manual-note hidden" id="manualAddressNote">
                        ℹ️ Pincode not found — please fill State and District manually.
                    </div>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="country">Country</label>
                    <input type="text" id="country" name="country" value="India" readonly>
                </div>
                <div class="form-group">
                    <label for="state">State <span class="required">*</span></label>
                    <input type="text" id="state" name="state" required
                           value="<?= old('state') ?>" placeholder="Auto-filled from pincode" readonly>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="district">District <span class="required">*</span></label>
                    <input type="text" id="district" name="district" required
                           value="<?= old('district') ?>" placeholder="Auto-filled from pincode" readonly>
                </div>
                <div class="form-group">
                    <label for="city">City / Town / Village</label>
                    <select id="city" name="city" disabled>
                        <option value="">Enter pincode first</option>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="village">Village / Area / Locality</label>
                    <input type="text" id="village" name="village"
                           value="<?= old('village') ?>" placeholder="Enter village or area name">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group full-width">
                    <label for="street">Street / House No. / Landmark</label>
                    <textarea id="street" name="street" placeholder="e.g. House No. 12, Near Railway Station"><?= old('street') ?></textarea>
                </div>
            </div>

            <div class="btn-container">
                <button type="button" class="btn btn-secondary" onclick="resetForm()">Reset Form</button>
                <button type="submit" class="btn btn-primary" id="submitBtn">Register Telestudio Doctor</button>
            </div>

        </form>
    </div>
</div>

<!-- SUCCESS MODAL -->
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
                    <div class="modal-item-label">Location</div>
                    <div class="modal-item-value"><?= htmlspecialchars($modal_data['district']) ?>, <?= htmlspecialchars($modal_data['state']) ?></div>
                </div>
                <div class="modal-item">
                    <div class="modal-item-label">Registered On</div>
                    <div class="modal-item-value"><?= $modal_data['datetime'] ?></div>
                </div>
                <div class="modal-item">
                    <div class="modal-item-label">Aadhar</div>
                    <div class="modal-item-value"><?= htmlspecialchars($modal_data['aadhar_no']) ?></div>
                </div>
            </div>

            <?php if (!empty($modal_data['docs_uploaded'])): ?>
            <div class="modal-docs">
                <div class="modal-docs-title">📄 Documents Uploaded</div>
                <?php foreach ($modal_data['docs_uploaded'] as $doc): ?>
                    <div class="modal-doc-item">✅ <?= htmlspecialchars($doc) ?></div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($modal_data['doc_warnings'])): ?>
            <div class="modal-docs" style="border-color:#f4b8b8;background:#fff5f5">
                <div class="modal-docs-title">⚠️ Document Issues</div>
                <?php foreach ($modal_data['doc_warnings'] as $warn): ?>
                    <div class="modal-doc-item">❗ <?= htmlspecialchars($warn) ?></div>
                <?php endforeach; ?>
                <div class="modal-doc-item" style="font-size:12px;color:#b91c1c">Check logs/telestudio_doc_upload.log for details.</div>
            </div>
            <?php endif; ?>

            <div class="modal-sms <?= $modal_data['sms_sent'] ? 'sent' : 'failed' ?>">
                <span style="font-size:20px"><?= $modal_data['sms_sent'] ? '📱' : '⚠️' ?></span>
                <div>
                    <strong><?= $modal_data['sms_sent'] ? 'SMS Sent' : 'SMS Not Sent' ?></strong><br>
                    <span style="font-size:12px">
                        <?= $modal_data['sms_sent']
                            ? 'Welcome message sent to +91' . htmlspecialchars($modal_data['mobile_no'])
                            : 'Please inform the doctor verbally. ID: ' . htmlspecialchars($modal_data['doctor_id']) ?>
                    </span>
                </div>
            </div>

            <div class="modal-actions">
                <button class="modal-btn print" onclick="window.print()">🖨️ Print Slip</button>
                <a class="modal-btn dashboard" href="../admin/admin_dashboard.php">↩ Dashboard</a>
                <button class="modal-btn close" onclick="document.getElementById('successModal').style.display='none';document.body.classList.remove('modal-open');">
                    ✖ Close &amp; Register Another
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
const MAX_FILE_SIZE = 1 * 1024 * 1024; // 1 MB

// ── Document file input handler ───────────────────────────
document.querySelectorAll('.doc-file-input').forEach(input => {
    input.addEventListener('change', function() {
        const field   = this.id;
        const cardEl  = document.getElementById('card_'    + field);
        const errEl   = document.getElementById('err_'     + field);
        const fnameEl = document.getElementById('fname_'   + field);
        const fmetaEl = document.getElementById('fmeta_'   + field);

        // Reset state
        cardEl.classList.remove('has-file', 'has-error');
        errEl.textContent = '';

        if (!this.files || !this.files[0]) return;

        const file   = this.files[0];
        const sizeMB = (file.size / 1024 / 1024).toFixed(2);
        const ext    = file.name.split('.').pop().toLowerCase();
        const allowed = ['pdf','jpg','jpeg','png'];

        // File type check
        if (!allowed.includes(ext)) {
            cardEl.classList.add('has-error');
            fnameEl.textContent = file.name;
            fmetaEl.textContent = sizeMB + ' MB · Invalid type';
            errEl.textContent   = '⚠️ Only PDF, JPG, PNG files are allowed.';
            return;
        }

        // Size check
        if (file.size > MAX_FILE_SIZE) {
            cardEl.classList.add('has-error');
            fnameEl.textContent = file.name;
            fmetaEl.textContent = sizeMB + ' MB · Exceeds 1 MB limit';
            errEl.textContent   = `⚠️ File too large (${sizeMB} MB). Maximum is 1 MB. Please remove and choose a smaller file.`;
            return;
        }

        // Valid file
        cardEl.classList.add('has-file');
        fnameEl.textContent = file.name;
        fmetaEl.textContent = sizeMB + ' MB · ' + ext.toUpperCase();
    });
});

// ── Remove / clear a document ─────────────────────────────
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

// ── Qualification "Other" reveal ──────────────────────────
document.getElementById('qualification').addEventListener('change', function() {
    const show = this.value === 'Other' || this.value === 'Fellowship';
    document.getElementById('otherQualificationGroup').classList.toggle('hidden', !show);
});

// ── Specialization "Other" reveal ────────────────────────
document.getElementById('specialization').addEventListener('change', function() {
    document.getElementById('otherSpecGroup').classList.toggle('hidden', this.value !== 'Other');
});

// ── Digits only ───────────────────────────────────────────
['mobileNo','aadharNo'].forEach(id => {
    document.getElementById(id).addEventListener('input', function() {
        this.value = this.value.replace(/\D/g,'');
    });
});

// ── Pincode lookup ────────────────────────────────────────
function lookupPincode() {
    const pin    = document.getElementById('pincode').value.trim();
    const status = document.getElementById('pincodeStatus');
    const note   = document.getElementById('manualAddressNote');
    if (!/^\d{6}$/.test(pin)) {
        status.className = 'pincode-status error';
        status.textContent = '⚠️ Enter a valid 6-digit pincode.';
        return;
    }
    status.className = 'pincode-status loading';
    status.textContent = '⏳ Looking up pincode…';
    note.classList.add('hidden');

    fetch(`https://api.postalpincode.in/pincode/${pin}`)
        .then(r => r.json())
        .then(data => {
            if (data[0].Status === 'Success') {
                const post = data[0].PostOffice[0];
                const stEl = document.getElementById('state');
                const dtEl = document.getElementById('district');
                stEl.value = post.State;   stEl.readOnly = false;
                dtEl.value = post.District; dtEl.readOnly = false;

                const cities = [...new Set(data[0].PostOffice.map(p => p.Name))];
                const cityEl = document.getElementById('city');
                cityEl.innerHTML = '<option value="">Select locality</option>' +
                    cities.map(c => `<option value="${c}">${c}</option>`).join('');
                cityEl.disabled = false;

                status.className = 'pincode-status success';
                status.textContent = `✅ ${post.District}, ${post.State}`;
            } else {
                document.getElementById('state').readOnly    = false;
                document.getElementById('district').readOnly = false;
                status.className = 'pincode-status error';
                status.textContent = '❌ Pincode not found. Fill address manually.';
                note.classList.remove('hidden');
            }
        })
        .catch(() => {
            status.className = 'pincode-status error';
            status.textContent = '❌ Network error. Fill address manually.';
            document.getElementById('state').readOnly    = false;
            document.getElementById('district').readOnly = false;
        });
}
document.getElementById('pincodeLookupBtn').addEventListener('click', lookupPincode);
document.getElementById('pincode').addEventListener('keydown', e => {
    if (e.key === 'Enter') { e.preventDefault(); lookupPincode(); }
});

// ── Submit — block if any doc has error ──────────────────
document.getElementById('doctorForm').addEventListener('submit', function(e) {
    // Block if any doc card has error
    const errorCards = document.querySelectorAll('.doc-card.has-error');
    if (errorCards.length > 0) {
        e.preventDefault();
        alert('⚠️ Please remove documents that exceed 1 MB before submitting.');
        errorCards[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
        return;
    }
    const btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-inline"></span>Registering…';
});

// ── Reset form ────────────────────────────────────────────
function resetForm() {
    if (!confirm('Reset all form fields?')) return;
    document.getElementById('doctorForm').reset();
    ['fileDegree','filePG','fileCert','fileOther'].forEach(clearDoc);
    document.getElementById('pincodeStatus').className = 'pincode-status';
    document.getElementById('pincodeStatus').textContent = '';
    document.getElementById('manualAddressNote').classList.add('hidden');
    document.getElementById('state').readOnly    = true;
    document.getElementById('district').readOnly = true;
    document.getElementById('city').innerHTML = '<option value="">Enter pincode first</option>';
    document.getElementById('city').disabled = true;
    document.getElementById('otherQualificationGroup').classList.add('hidden');
    document.getElementById('otherSpecGroup').classList.add('hidden');
}
</script>

</body>
</html>
