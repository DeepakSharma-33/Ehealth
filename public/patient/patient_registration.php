<?php
require_once __DIR__ . '/../../config/config.php';
require_role('executive', '../executive/executive_login.php');

$exec_name       = get_session_name();
$exec_id         = $_SESSION['executive_id'] ?? '';
$numeric_exec_id = get_session_id();

$db = db();
ensure_patient_revisit_schema($db);

// ── Generate Sequential Patient ID: P{YYYY}{MM}{NNN} ──────
function generate_patient_id($db) {
    $prefix = 'P' . date('Y') . date('m'); // e.g. P202603
    $stmt   = $db->prepare(
        "SELECT patient_id FROM patients
         WHERE patient_id LIKE ? ORDER BY patient_id DESC LIMIT 1"
    );
    $like = $prefix . '%';
    $stmt->bind_param('s', $like);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) {
        $last_seq = (int)substr($row['patient_id'], -3);
        $seq      = str_pad($last_seq + 1, 3, '0', STR_PAD_LEFT);
    } else {
        $seq = '001';
    }
    return $prefix . $seq; // e.g. P202603001
}

// ── Calculate age from DOB ─────────────────────────────────
function calc_age($dob_str) {
    return (int)(new DateTime($dob_str))->diff(new DateTime())->y;
}

// ── Send Twilio SMS ────────────────────────────────────────
function send_welcome_sms($mobile, $patient_id, $patient_name) {
    $to      = '+91' . $mobile;
    $message = "Welcome to eHealth! Dear {$patient_name}, your Patient ID is {$patient_id} and this is also your default password. You can change it after logging in to the eHealth portal.";

    $url  = 'https://api.twilio.com/2010-04-01/Accounts/' . TWILIO_SID . '/Messages.json';
    $data = http_build_query([
        'To'                  => $to,
        'MessagingServiceSid' => TWILIO_MESSAGING_SID,
        'Body'                => $message,
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

    if ($err) return ['success' => false, 'error' => $err];
    $res = json_decode($response, true);
    return ['success' => isset($res['sid']), 'sid' => $res['sid'] ?? null, 'error' => $res['message'] ?? null];
}

$modal_data = null;
$form_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ── Collect & sanitise inputs ──────────────────────────
    $full_name = trim($_POST['fullName']      ?? '');
    $dob       = trim($_POST['dob']           ?? '');
    $age_input = trim($_POST['age']           ?? '');
    $gender    = trim($_POST['gender']        ?? '');
    $aadhar_no = preg_replace('/\D/', '', $_POST['aadharNo']      ?? '');
    $abha_id   = trim($_POST['abhaId']        ?? '') ?: null;
    $mobile_no = preg_replace('/\D/', '', $_POST['mobileNo']      ?? '');
    $pincode   = preg_replace('/\D/', '', $_POST['pincode']       ?? '');
    $state     = trim($_POST['state']         ?? '');
    $district  = trim($_POST['district']      ?? '');
    $city      = trim($_POST['city']          ?? '') ?: null;
    $village   = trim($_POST['village']       ?? '') ?: null;
    $street    = trim($_POST['street']        ?? '') ?: null;
    $payment   = trim($_POST['paymentMethod'] ?? '');

    $errors = [];

    // ── Validation ─────────────────────────────────────────
    if (!$full_name)                                         $errors[] = 'Full name is required.';
    if (!$dob || !strtotime($dob))                           $errors[] = 'Valid date of birth is required.';
    if ($age_input === '' || filter_var($age_input, FILTER_VALIDATE_INT) === false) {
        $errors[] = 'Valid age is required.';
    } elseif ((int)$age_input < 0 || (int)$age_input > 150) {
        $errors[] = 'Age must be between 0 and 150.';
    }
    if (!in_array($gender, ['Male','Female','Other']))       $errors[] = 'Gender is required.';
    if (strlen($aadhar_no) !== 12)                          $errors[] = 'Aadhar must be 12 digits.';
    if (strlen($mobile_no) !== 10)                          $errors[] = 'Mobile must be 10 digits.';
    if (strlen($pincode)   !== 6)                           $errors[] = 'Pincode must be 6 digits.';
    if (!$state)                                             $errors[] = 'State is required.';
    if (!$district)                                          $errors[] = 'District is required.';
    if (!in_array($payment, ['Cash','UPI']))                 $errors[] = 'Payment method is required.';

    // ── Duplicate check ────────────────────────────────────
    if (!$errors) {
        $dup = $db->prepare('SELECT patient_id FROM patients WHERE aadhar_no = ? LIMIT 1');
        $dup->bind_param('s', $aadhar_no);
        $dup->execute();
        $dup->store_result();
        if ($dup->num_rows > 0) $errors[] = 'A patient with this Aadhar number is already registered.';
        $dup->close();
    }

    if (!$errors) {
        $patient_id = generate_patient_id($db);
        $age        = (int)$age_input;

        // Prevent silent 0-age saves when DOB year is wrong.
        $age_from_dob = calc_age($dob);
        if (abs($age - $age_from_dob) > 1) {
            $errors[] = "Age and DOB do not match (expected around {$age_from_dob} years). Please correct DOB or age.";
        }

        $password   = hash_password($patient_id);

        // ── Address full ───────────────────────────────────
        $parts        = array_filter([$street, $village, $city, $district, $state, 'India', $pincode]);
        $address_full = implode(', ', $parts);

        // ── Handle photo — save to uploads/patients/ ───────
        $photo_filename = null;
        $upload_dir     = rtrim(UPLOAD_PATH, '/\\') . DIRECTORY_SEPARATOR . 'patients' . DIRECTORY_SEPARATOR;

        if (!is_dir($upload_dir) && !mkdir($upload_dir, 0755, true)) {
            $errors[] = 'Could not create patient photo directory.';
            error_log('patient_registration: failed to create upload dir ' . $upload_dir);
        } elseif (!is_writable($upload_dir)) {
            $errors[] = 'Patient photo directory is not writable.';
            error_log('patient_registration: upload dir not writable ' . $upload_dir);
        }

        if (!$errors) {
            $allowed_ext = ['jpg', 'jpeg', 'png', 'webp'];
            $photo_error = $_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE;
            $photo_b64   = trim((string)($_POST['photo_base64'] ?? ''));

            $save_base64_photo = function (string $b64, string $invalid_msg) use (&$photo_filename, $patient_id, $upload_dir, &$errors): bool {
                if (!preg_match('/^data:image\/(\w+);base64,/', $b64)) {
                    $errors[] = $invalid_msg;
                    return false;
                }

                $img_data = base64_decode(substr($b64, strpos($b64, ',') + 1), true);
                if ($img_data === false) {
                    $errors[] = $invalid_msg;
                    return false;
                }

                $photo_filename = $patient_id . '.jpg';
                $dest_path      = $upload_dir . $photo_filename;
                if (file_put_contents($dest_path, $img_data) === false) {
                    $photo_filename = null;
                    $errors[] = 'Could not save patient photo.';
                    error_log('patient_registration: file_put_contents failed to ' . $dest_path);
                    return false;
                }

                return true;
            };

            // 1. Primary: multipart upload
            if ($photo_error !== UPLOAD_ERR_NO_FILE) {
                if ($photo_error === UPLOAD_ERR_OK && !empty($_FILES['photo']['tmp_name'])) {
                    $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
                    if (!in_array($ext, $allowed_ext, true)) {
                        $errors[] = 'Photo format must be JPG, PNG, or WEBP.';
                    } else {
                        $photo_filename = $patient_id . '.jpg';
                        $dest_path      = $upload_dir . $photo_filename;

                        if (!move_uploaded_file($_FILES['photo']['tmp_name'], $dest_path)) {
                            $photo_filename = null;
                            if ($photo_b64 !== '') {
                                error_log('patient_registration: move_uploaded_file failed, using base64 fallback');
                                $save_base64_photo($photo_b64, 'Photo data is invalid. Please choose the photo again.');
                            } else {
                                $errors[] = 'Could not save the uploaded photo.';
                                error_log('patient_registration: move_uploaded_file failed to ' . $dest_path);
                            }
                        }
                    }
                } else {
                    // If multipart upload failed but base64 exists, fallback and continue.
                    if ($photo_b64 !== '') {
                        error_log('patient_registration: upload error code ' . $photo_error . ', using base64 fallback');
                        $save_base64_photo($photo_b64, 'Photo data is invalid. Please choose the photo again.');
                    } else {
                        $errors[] = 'Photo upload failed. Please choose the photo again.';
                        error_log('patient_registration: photo upload error code ' . $photo_error);
                    }
                }
            }
            // 2. Direct base64 (webcam or JS fallback)
            elseif ($photo_b64 !== '') {
                $save_base64_photo($photo_b64, 'Captured photo is invalid. Please capture again.');
            }
        }

        // ── INSERT ─────────────────────────────────────────
        if (!$errors) {
            $country = 'India';
        $stmt    = $db->prepare(
            'INSERT INTO patients
             (patient_id, patient_flag, full_name, dob, age, gender, aadhar_no, abha_id, mobile_no,
              country, state, district, city, village, street, pincode, address_full,
              registered_by_exec_id, registered_by_exec_name,
              payment_method, payment_amount, photo_filename, password)
             VALUES (?,"N",?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,20.00,?,?)'
        );
        $stmt->bind_param(
            'sssisssssssssssssssss',
            $patient_id, $full_name, $dob, $age, $gender, $aadhar_no, $abha_id, $mobile_no,
            $country, $state, $district, $city, $village, $street, $pincode, $address_full,
            $exec_id, $exec_name,
            $payment, $photo_filename, $password
        );

        if ($stmt->execute()) {
            $stmt->close();

            // ── Send SMS ───────────────────────────────────
            $sms_result = send_welcome_sms($mobile_no, $patient_id, $full_name);

            $modal_data = [
                'patient_id'     => $patient_id,
                'full_name'      => $full_name,
                'age'            => $age,
                'gender'         => $gender,
                'mobile_no'      => $mobile_no,
                'aadhar_no'      => mask_aadhar_last4($aadhar_no),
                'address'        => $address_full,
                'exec_name'      => $exec_name,
                'exec_id'        => $exec_id,
                'payment'        => $payment,
                'photo_filename' => $photo_filename,
                'datetime'       => date('d M Y, h:i A'),
                'sms_sent'       => $sms_result['success'],
            ];
        } else {
            $stmt->close();
            $errors[] = 'Database error: ' . $db->error;
        }
    }
    }

    if ($errors) {
        $form_error = implode('<br>', $errors);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Registration - eHealth System</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans:wght@400;500;600;700&family=Noto+Sans+Devanagari:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        :root {
            --primary:       #1a6b4a;
            --primary-light: #2d8f63;
            --primary-dark:  #134d36;
            --accent:        #f0a500;
            --bg:            #f0f7f4;
            --white:         #ffffff;
            --border:        #c8ddd5;
            --text:          #1a2e25;
            --muted:         #5a7a6a;
            --error:         #c0392b;
            --info:          #1565c0;
        }
        body { font-family:'Noto Sans',sans-serif; background:var(--bg); min-height:100vh; padding:20px;
            background-image: radial-gradient(circle at 18% 18%,rgba(26,107,74,.08) 0%,transparent 50%), radial-gradient(circle at 82% 82%,rgba(240,165,0,.06) 0%,transparent 50%);
        }
        .container { max-width:960px; margin:0 auto; background:var(--white); border-radius:20px; box-shadow:0 8px 40px rgba(26,107,74,.15),0 2px 8px rgba(0,0,0,.06); overflow:hidden; }

        /* Header */
        .header { background:linear-gradient(135deg,var(--primary) 0%,var(--primary-light) 60%,#3aab78 100%); color:white; padding:28px 36px; text-align:center; position:relative; overflow:hidden; }
        .header::before { content:''; position:absolute; top:-40px; right:-40px; width:160px; height:160px; background:rgba(255,255,255,.07); border-radius:50%; }
        .header::after  { content:''; position:absolute; bottom:-30px; left:30px; width:100px; height:100px; background:rgba(240,165,0,.12); border-radius:50%; }
        .header-logo { display:flex; align-items:center; justify-content:center; gap:14px; margin-bottom:10px; text-decoration:none; color:inherit; }
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
        .header-subtitle { font-size:14px; opacity:.88; margin-top:4px; }
        .header-badge { display:inline-block; background:rgba(240,165,0,.3); border:1px solid rgba(240,165,0,.5); color:#ffe066; font-size:12px; padding:3px 12px; border-radius:20px; margin-top:8px; font-weight:500; }

        /* Form container */
        .form-container { padding:32px 40px 40px; }

        /* Executive Banner */
        .executive-banner { background:linear-gradient(135deg,#e8f5e9,#f0f7f4); border:1.5px solid #a5d6a7; border-radius:12px; padding:14px 20px; display:flex; align-items:center; gap:14px; margin-bottom:28px; }
        .exec-avatar { width:42px; height:42px; background:linear-gradient(135deg,var(--primary),var(--primary-light)); border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:20px; flex-shrink:0; }
        .exec-info { flex:1; }
        .exec-label { font-size:11px; font-weight:600; color:var(--muted); text-transform:uppercase; letter-spacing:.6px; }
        .exec-name  { font-size:16px; font-weight:700; color:var(--primary-dark); }
        .exec-id    { font-size:13px; color:var(--muted); margin-top:1px; }
        .exec-badge { background:var(--primary); color:white; font-size:11px; font-weight:600; padding:4px 10px; border-radius:20px; }

        /* Alert */
        .alert { padding:14px 18px; border-radius:10px; margin-bottom:24px; font-size:14px; line-height:1.6; border-left:4px solid transparent; animation:slideDown .3s ease; }
        @keyframes slideDown { from{opacity:0;transform:translateY(-8px)} to{opacity:1;transform:translateY(0)} }
        .alert.success { background:#e8f5e9; color:#1b5e20; border-color:var(--primary); }
        .alert.error   { background:#fdecea; color:#7f1d1d; border-color:var(--error); }

        /* Section title */
        .section-title { font-size:13px; font-weight:700; color:var(--primary); margin:28px 0 16px; padding-bottom:10px; border-bottom:2px solid var(--border); display:flex; align-items:center; gap:8px; text-transform:uppercase; letter-spacing:.6px; }
        .section-title:first-of-type { margin-top:0; }
        .section-num { background:var(--primary); color:white; width:24px; height:24px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:12px; flex-shrink:0; }

        /* Form */
        .form-row { display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:18px; margin-bottom:18px; }
        .form-group { display:flex; flex-direction:column; }
        .form-group.full-width { grid-column:1/-1; }
        .form-group label { font-size:13px; font-weight:600; color:var(--text); margin-bottom:6px; }
        .required { color:var(--error); }
        .optional-tag { font-weight:400; font-size:11px; color:var(--muted); background:#f0f0f0; padding:1px 7px; border-radius:10px; margin-left:4px; }
        .hint { font-size:12px; color:var(--muted); margin-top:5px; }
        .form-group input,.form-group select,.form-group textarea { padding:11px 14px; border:2px solid var(--border); border-radius:10px; font-size:14px; font-family:inherit; background:white; color:var(--text); transition:border-color .22s,box-shadow .22s; }
        .form-group input:focus,.form-group select:focus,.form-group textarea:focus { outline:none; border-color:var(--primary); box-shadow:0 0 0 3px rgba(26,107,74,.1); }
        .form-group input[readonly] { background:#f0f7f0; cursor:default; color:var(--primary-dark); font-weight:600; }
        .form-group textarea { resize:vertical; min-height:80px; }

        /* Pincode */
        .pincode-row { display:flex; gap:10px; }
        .pincode-row input { flex:1; padding:11px 14px; border:2px solid var(--border); border-radius:10px; font-size:14px; font-family:inherit; transition:border-color .22s; }
        .pincode-row input:focus { outline:none; border-color:var(--primary); box-shadow:0 0 0 3px rgba(26,107,74,.1); }
        .lookup-btn { padding:11px 18px; background:linear-gradient(135deg,var(--primary),var(--primary-light)); color:white; border:none; border-radius:10px; font-size:13px; font-weight:600; cursor:pointer; white-space:nowrap; font-family:inherit; transition:opacity .2s; }
        .lookup-btn:hover { opacity:.88; }
        .pincode-status { display:none; font-size:13px; margin-top:6px; padding:7px 12px; border-radius:8px; }
        .pincode-status.success { background:#e8f5e9; color:#1b5e20; display:block; }
        .pincode-status.error   { background:#fdecea; color:#7f1d1d; display:block; }
        .pincode-status.loading { background:#e3f2fd; color:#0d47a1; display:block; }
        .manual-note { font-size:12px; color:#5c3d00; background:#fff8e1; padding:7px 12px; border-radius:8px; margin-top:6px; }

        /* Radio */
        .radio-group { display:flex; gap:16px; margin-top:8px; flex-wrap:wrap; }
        .radio-option { display:flex; align-items:center; gap:7px; cursor:pointer; }
        .radio-option input[type="radio"] { width:17px; height:17px; accent-color:var(--primary); cursor:pointer; }
        .radio-option label { margin:0; font-weight:400; font-size:14px; cursor:pointer; }

        /* Payment */
        .payment-section { background:linear-gradient(135deg,#fffbf0,#fff8e1); border:2px solid #ffd54f; border-radius:14px; padding:22px 24px; margin-bottom:8px; }
        .payment-header { display:flex; align-items:center; gap:10px; margin-bottom:18px; }
        .payment-icon { width:38px; height:38px; background:var(--accent); border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:18px; }
        .payment-header-text h3 { font-size:16px; font-weight:700; color:#5c3d00; }
        .payment-header-text p  { font-size:13px; color:#856404; margin-top:1px; }
        .payment-amount-display { display:flex; align-items:center; gap:8px; background:white; border:2px solid #ffd54f; border-radius:10px; padding:14px 18px; margin-bottom:18px; }
        .amount-label { font-size:13px; color:var(--muted); font-weight:500; }
        .amount-value { font-size:26px; font-weight:800; color:var(--primary); letter-spacing:-1px; }
        .amount-badge { margin-left:auto; background:#e8f5e9; color:var(--primary); font-size:12px; font-weight:700; padding:4px 12px; border-radius:20px; }
        .payment-methods { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
        .payment-method-card { position:relative; cursor:pointer; }
        .payment-method-card input[type="radio"] { position:absolute; opacity:0; width:0; height:0; }
        .payment-method-label { display:flex; flex-direction:column; align-items:center; gap:8px; padding:18px 14px; border:2px solid #e0e0e0; border-radius:12px; background:white; cursor:pointer; transition:all .2s; text-align:center; }
        .payment-method-card input[type="radio"]:checked + .payment-method-label { border-color:var(--primary); background:#f0f7f4; box-shadow:0 0 0 3px rgba(26,107,74,.1); }
        .payment-method-label:hover { border-color:var(--primary-light); }
        .method-icon { font-size:28px; line-height:1; }
        .method-name { font-size:14px; font-weight:700; color:var(--text); }
        .method-desc { font-size:11px; color:var(--muted); }
        .method-check { display:none; width:20px; height:20px; background:var(--primary); border-radius:50%; color:white; font-size:11px; align-items:center; justify-content:center; }
        .payment-method-card input[type="radio"]:checked + .payment-method-label .method-check { display:flex; }

        /* Photo */
        .photo-upload-area { border:2px dashed var(--border); border-radius:12px; padding:22px; text-align:center; background:#f9fcfa; transition:border-color .2s; }
        .photo-upload-area:hover { border-color:var(--primary); }
        .photo-preview { width:110px; height:110px; border-radius:12px; object-fit:cover; margin:0 auto 12px; display:none; border:3px solid var(--primary); }
        .photo-placeholder { width:90px; height:90px; background:#e8f0ec; border-radius:12px; margin:0 auto 12px; display:flex; align-items:center; justify-content:center; font-size:34px; }
        .photo-actions { display:flex; gap:10px; justify-content:center; flex-wrap:wrap; }
        .photo-btn { padding:9px 18px; border-radius:8px; font-size:13px; font-weight:600; cursor:pointer; font-family:inherit; transition:all .2s; border:2px solid transparent; }
        .photo-btn.upload { background:var(--primary); color:white; border-color:var(--primary); }
        .photo-btn.webcam { background:white; color:var(--primary); border-color:var(--primary); }
        .photo-btn:hover { opacity:.85; transform:translateY(-1px); }
        #photoFile { display:none; }
        .webcam-container { display:none; margin-top:14px; }
        #webcamVideo { width:100%; max-width:300px; border-radius:10px; border:2px solid var(--primary); }
        .webcam-snap-btn { margin-top:10px; padding:9px 22px; background:var(--accent); color:#1a1a1a; border:none; border-radius:8px; font-size:13px; font-weight:700; cursor:pointer; font-family:inherit; }

        /* Buttons */
        .btn-container { display:flex; gap:14px; justify-content:flex-end; margin-top:32px; padding-top:24px; border-top:2px solid var(--border); }
        .btn { padding:13px 28px; border-radius:10px; font-size:15px; font-weight:700; cursor:pointer; font-family:inherit; transition:all .2s; border:2px solid transparent; }
        .btn-primary   { background:linear-gradient(135deg,var(--primary),var(--primary-light)); color:white; border-color:var(--primary); }
        .btn-secondary { background:white; color:var(--muted); border-color:var(--border); }
        .btn:hover    { transform:translateY(-2px); box-shadow:0 4px 12px rgba(0,0,0,.12); }
        .btn:disabled { opacity:.6; cursor:not-allowed; transform:none; }
        .spinner-inline { display:inline-block; width:15px; height:15px; border:2px solid rgba(255,255,255,0.4); border-top-color:white; border-radius:50%; animation:spin .7s linear infinite; vertical-align:middle; margin-right:8px; }
        @keyframes spin { to { transform:rotate(360deg); } }

        /* Modal */
        .modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.55); backdrop-filter:blur(4px); z-index:1000; align-items:center; justify-content:center; padding:20px; }
        .modal-overlay.show { display:flex; animation:fadeIn .3s ease; }
        @keyframes fadeIn { from{opacity:0} to{opacity:1} }
        .success-card { background:white; border-radius:20px; max-width:540px; width:100%; overflow:hidden; box-shadow:0 24px 60px rgba(0,0,0,.25); animation:popUp .4s cubic-bezier(.34,1.56,.64,1); max-height:90vh; overflow-y:auto; }
        @keyframes popUp { from{opacity:0;transform:scale(.85) translateY(20px)} to{opacity:1;transform:scale(1) translateY(0)} }
        .success-header { background:linear-gradient(135deg,var(--primary),var(--primary-light)); color:white; padding:26px 28px 20px; text-align:center; }
        .success-checkmark { width:62px; height:62px; background:rgba(255,255,255,.2); border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:30px; margin:0 auto 12px; }
        .success-header h2 { font-size:21px; font-weight:700; }
        .success-header p  { font-size:13px; opacity:.88; margin-top:4px; }
        .success-body { padding:22px 28px 26px; }
        .slip-brand { text-align:center; margin-bottom:12px; text-decoration:none; color:inherit; display:block; }
        .slip-brand-logo { height:52px; width:auto; display:block; margin:0 auto 6px; }
        .slip-brand-text { font-size:14px; font-weight:800; color:var(--primary); letter-spacing:1px; }
        .patient-photo-round { width:76px; height:76px; border-radius:50%; object-fit:cover; border:3px solid var(--primary); display:block; margin:0 auto 14px; }
        .patient-photo-placeholder { width:76px; height:76px; border-radius:50%; background:#e8f0ec; border:3px solid var(--primary); display:flex; align-items:center; justify-content:center; font-size:30px; margin:0 auto 14px; }
        .patient-id-badge { text-align:center; margin-bottom:18px; }
        .patient-id-value { font-size:28px; font-weight:700; color:var(--primary); letter-spacing:2px; }
        .patient-id-label { font-size:11px; color:var(--muted); text-transform:uppercase; letter-spacing:1px; margin-bottom:4px; }
        .detail-grid { display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:14px; }
        .detail-item.full { grid-column:1/-1; }
        .detail-label { font-size:11px; font-weight:600; color:var(--muted); text-transform:uppercase; letter-spacing:.5px; margin-bottom:2px; }
        .detail-value { font-size:14px; font-weight:600; color:var(--text); }
        .payment-receipt { display:flex; align-items:center; gap:10px; background:#fff8e1; border:1px solid #ffd54f; border-radius:10px; padding:12px 16px; margin-bottom:14px; }
        .payment-receipt-icon { font-size:22px; }
        .payment-receipt-info { flex:1; }
        .payment-receipt-info .label { font-size:11px; color:#856404; font-weight:600; text-transform:uppercase; }
        .payment-receipt-info .value { font-size:15px; color:#5c3d00; font-weight:700; }
        .payment-receipt-amount { font-size:20px; font-weight:800; color:var(--primary); }
        .receipt-note { background:#f7fbf8; border:1px solid var(--border); border-left:4px solid var(--primary); border-radius:10px; padding:12px 14px; margin-bottom:14px; }
        .receipt-note p { margin:0; font-size:13px; line-height:1.6; color:#2c3e35; }
        .receipt-note p + p { margin-top:10px; padding-top:10px; border-top:1px dashed #c8ddd5; }
        .card-actions { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-top:14px; }
        .card-btn { padding:12px; border-radius:10px; font-size:14px; font-weight:700; cursor:pointer; font-family:inherit; transition:all .2s; text-align:center; border:2px solid transparent; }
        .card-btn.print  { background:var(--accent); color:#1a1a1a; border-color:var(--accent); }
        .card-btn.vitals { background:var(--primary); color:white; border-color:var(--primary); }
        .card-btn:hover  { transform:translateY(-2px); box-shadow:0 4px 12px rgba(0,0,0,.12); }
        .card-close-btn { width:100%; margin-top:10px; padding:10px; background:none; border:1px solid var(--border); border-radius:8px; color:var(--muted); font-size:13px; cursor:pointer; font-family:inherit; transition:background .2s; }
        .card-close-btn:hover { background:#f5f5f5; }

        /* State/district unlock */
        .state-unlocked { background:white !important; cursor:text !important; }

        @media(max-width:640px) { .form-container{padding:20px} .detail-grid{grid-template-columns:1fr} .card-actions{grid-template-columns:1fr} .payment-methods{grid-template-columns:1fr} }
        @media print {
            body > * { display:none !important; }
            #successModal { display:block !important; position:static !important; background:none !important; backdrop-filter:none !important; padding:0 !important; }
            .success-card { box-shadow:none !important; border:1px solid #ccc !important; border-radius:8px !important; max-height:none !important; overflow:visible !important; animation:none !important; width:100% !important; max-width:480px !important; margin:0 auto !important; }
            .card-actions, .card-close-btn { display:none !important; }
            html, body { height:auto !important; margin:0 !important; }
        }
    </style>
</head>
<body>
<div class="container">

    <!-- Header -->
    <div class="header">
        <a href="<?= htmlspecialchars(app_base_url()) ?>/" class="header-logo">
            <img src="<?= htmlspecialchars(app_base_url()) ?>/public/assets/SRMS_TRUST_LOGO.png" alt="SRMST Trust Logo" class="header-brand-logo srmst-logo">
            <span class="header-brand-text">SRMS EHEALTH</span>
        </a>
        <div class="header-subtitle">Patient Registration Portal</div>
        <div class="header-badge">🌾 Serving Rural India</div>
    </div>

    <div class="form-container">

        <!-- Executive Banner -->
        <div class="executive-banner">
            <div class="exec-avatar">👨‍💼</div>
            <div class="exec-info">
                <div class="exec-label">Registering as</div>
                <div class="exec-name"><?= htmlspecialchars($exec_name) ?></div>
                <div class="exec-id"><?= htmlspecialchars($exec_id) ?></div>
            </div>
            <div class="exec-badge">✓ Executive</div>
        </div>

        <?php if (!empty($form_error)): ?>
            <div class="alert error">❌ <?= $form_error ?></div>
        <?php endif; ?>

        <form id="patientForm" method="POST" enctype="multipart/form-data" novalidate>

            <!-- 1. Personal Info -->
            <div class="section-title"><span class="section-num">1</span>Personal Information</div>

            <div class="form-row">
                <div class="form-group full-width">
                    <label for="fullName">Full Name <span class="required">*</span></label>
                    <input type="text" id="fullName" name="fullName" required
                           value="<?= htmlspecialchars($_POST['fullName'] ?? '') ?>"
                           placeholder="Enter patient's full name">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="dob">Date of Birth <span class="required">*</span></label>
                    <input type="date" id="dob" name="dob" required
                           value="<?= htmlspecialchars($_POST['dob'] ?? '') ?>"
                           max="<?= date('Y-m-d') ?>">
                </div>
                <div class="form-group">
                    <label for="age">Age <span class="required">*</span></label>
                    <input type="number" id="age" name="age" required min="0" max="150"
                           value="<?= htmlspecialchars($_POST['age'] ?? '') ?>"
                           placeholder="Age in years">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Gender <span class="required">*</span></label>
                    <div class="radio-group">
                        <?php foreach (['Male','Female','Other'] as $g): ?>
                        <div class="radio-option">
                            <input type="radio" id="gender<?= $g ?>" name="gender" value="<?= $g ?>"
                                <?= (($_POST['gender'] ?? '') === $g) ? 'checked' : '' ?>>
                            <label for="gender<?= $g ?>"><?= $g ?></label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="form-group">
                    <label for="aadharNo">Aadhar Number <span class="required">*</span></label>
                    <input type="text" id="aadharNo" name="aadharNo" required maxlength="12"
                           value="<?= htmlspecialchars($_POST['aadharNo'] ?? '') ?>"
                           placeholder="12-digit Aadhar number">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="abhaId">ABHA ID <span class="optional-tag">Optional</span></label>
                    <input type="text" id="abhaId" name="abhaId" maxlength="20"
                           value="<?= htmlspecialchars($_POST['abhaId'] ?? '') ?>"
                           placeholder="ABHA ID if available">
                </div>
                <div class="form-group">
                    <label for="mobileNo">Mobile Number <span class="required">*</span></label>
                    <input type="tel" id="mobileNo" name="mobileNo" required maxlength="10"
                           value="<?= htmlspecialchars($_POST['mobileNo'] ?? '') ?>"
                           placeholder="10-digit mobile number">
                    <div class="hint">📱 Patient will use this to log in</div>
                </div>
            </div>

            <!-- 2. Registered By -->
            <div class="section-title"><span class="section-num">2</span>Registered By (Executive)</div>

            <div class="form-row">
                <div class="form-group">
                    <label>Executive Name</label>
                    <input type="text" value="<?= htmlspecialchars($exec_name) ?>" readonly>
                </div>
                <div class="form-group">
                    <label>Executive ID</label>
                    <input type="text" value="<?= htmlspecialchars($exec_id) ?>" readonly>
                </div>
            </div>

            <!-- 3. Address -->
            <div class="section-title"><span class="section-num">3</span>Address Information</div>

            <div class="form-row">
                <div class="form-group full-width">
                    <label for="pincode">Pincode <span class="required">*</span>&nbsp;<span style="font-weight:400;font-size:12px;color:var(--primary)">— Enter to auto-fill address</span></label>
                    <div class="pincode-row">
                        <input type="text" id="pincode" name="pincode" required maxlength="6"
                               value="<?= htmlspecialchars($_POST['pincode'] ?? '') ?>"
                               placeholder="6-digit pincode">
                        <button type="button" class="lookup-btn" id="pincodeLookupBtn">🔍 Lookup</button>
                    </div>
                    <div class="pincode-status" id="pincodeStatus"></div>
                    <div class="manual-note" id="manualAddressNote" style="display:none">ℹ️ Pincode not found — please fill State and District manually.</div>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="country">Country</label>
                    <input type="text" id="country" value="India" readonly>
                </div>
                <div class="form-group">
                    <label for="state">State <span class="required">*</span></label>
                    <input type="text" id="state" name="state" required
                           value="<?= htmlspecialchars($_POST['state'] ?? '') ?>"
                           placeholder="Auto-filled from pincode" readonly>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="district">District <span class="required">*</span></label>
                    <input type="text" id="district" name="district" required
                           value="<?= htmlspecialchars($_POST['district'] ?? '') ?>"
                           placeholder="Auto-filled from pincode" readonly>
                </div>
                <div class="form-group" id="cityWrapper">
                    <label for="city">City / Town</label>
                    <select id="city" name="city" disabled>
                        <option value="">Enter pincode first</option>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="village">Village / Area / Locality</label>
                    <input type="text" id="village" name="village"
                           value="<?= htmlspecialchars($_POST['village'] ?? '') ?>"
                           placeholder="Village or area name">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group full-width">
                    <label for="street">Street / House No. / Landmark</label>
                    <textarea id="street" name="street" placeholder="e.g. House No. 12, Near Panchayat Office"><?= htmlspecialchars($_POST['street'] ?? '') ?></textarea>
                </div>
            </div>

            <!-- 4. Photo -->
            <div class="section-title"><span class="section-num">4</span>Patient Photo <span class="optional-tag" style="text-transform:none;font-size:12px;">Optional</span></div>

            <div class="form-row">
                <div class="form-group full-width">
                    <div class="photo-upload-area">
                        <img id="photoPreview" class="photo-preview" src="" alt="Patient Photo">
                        <div class="photo-placeholder" id="photoPlaceholder">👤</div>
                        <p style="font-size:13px;color:var(--muted);margin-bottom:12px;">Upload or capture patient's photo</p>
                        <div class="photo-actions">
                            <button type="button" class="photo-btn upload" onclick="document.getElementById('photoFile').click()">📁 Upload Photo</button>
                            <button type="button" class="photo-btn webcam" id="webcamBtn">📷 Use Webcam</button>
                        </div>
                        <input type="file" id="photoFile" name="photo" accept="image/*">
                        <div class="webcam-container" id="webcamContainer">
                            <video id="webcamVideo" autoplay playsinline></video><br>
                            <button type="button" class="webcam-snap-btn" onclick="captureWebcam()">📸 Capture</button>
                            <button type="button" class="webcam-snap-btn" style="background:#e0e0e0;color:#333;margin-left:8px;" onclick="stopWebcam()">✖ Cancel</button>
                        </div>
                    </div>
                    <!-- Hidden canvas for webcam capture -->
                    <canvas id="snapCanvas" style="display:none"></canvas>
                    <!-- Hidden input to send base64 webcam photo -->
                    <input type="hidden" name="photo_base64" id="photo_base64">
                </div>
            </div>

            <!-- 5. Payment -->
            <div class="section-title"><span class="section-num">5</span>Payment for Registration</div>

            <div class="payment-section">
                <div class="payment-header">
                    <div class="payment-icon">💳</div>
                    <div class="payment-header-text">
                        <h3>Registration Fee</h3>
                        <p>Select payment method to complete registration</p>
                    </div>
                </div>
                <div class="payment-amount-display">
                    <div>
                        <div class="amount-label">Amount to be collected</div>
                        <div class="amount-value">₹20</div>
                    </div>
                    <div class="amount-badge">Registration Fee</div>
                </div>
                <div class="payment-methods">
                    <div class="payment-method-card">
                        <input type="radio" id="payCash" name="paymentMethod" value="Cash" required
                               <?= (($_POST['paymentMethod'] ?? '') === 'Cash') ? 'checked' : '' ?>>
                        <label class="payment-method-label" for="payCash">
                            <div class="method-icon">💵</div>
                            <div class="method-name">Cash</div>
                            <div class="method-desc">Collect ₹20 in hand</div>
                            <div class="method-check">✓</div>
                        </label>
                    </div>
                    <div class="payment-method-card">
                        <input type="radio" id="payUpi" name="paymentMethod" value="UPI" required
                               <?= (($_POST['paymentMethod'] ?? '') === 'UPI') ? 'checked' : '' ?>>
                        <label class="payment-method-label" for="payUpi">
                            <div class="method-icon">📱</div>
                            <div class="method-name">UPI</div>
                            <div class="method-desc">PhonePe / GPay / BHIM</div>
                            <div class="method-check">✓</div>
                        </label>
                    </div>
                </div>
            </div>

            <div class="btn-container">
                <button type="button" class="btn btn-secondary" onclick="resetForm()">🔄 Reset Form</button>
                <button type="submit" class="btn btn-primary" id="submitBtn">Register Patient</button>
            </div>
        </form>
    </div>
</div>

<!-- SUCCESS MODAL (PHP-rendered after POST) -->
<?php if ($modal_data): ?>
<div class="modal-overlay show" id="successModal">
    <div class="success-card">
        <div class="success-header">
            <div class="success-checkmark">🧾</div>
            <h2>Registration Slip</h2>
        </div>
        <div class="success-body">
            <a href="<?= htmlspecialchars(app_base_url()) ?>/" class="slip-brand">
                <img class="slip-brand-logo" src="<?= htmlspecialchars(app_base_url()) ?>/public/assets/SRMS_TRUST_LOGO.png" alt="SRMS Trust Logo">
                <div class="slip-brand-text">SRMS EHEALTH</div>
            </a>
            <!-- Photo -->
            <?php $modal_photo_url = patient_photo_url($modal_data['photo_filename'] ?? null); ?>
            <?php if ($modal_photo_url): ?>
                <img class="patient-photo-round" src="<?= htmlspecialchars($modal_photo_url) ?>" alt="Patient Photo">
            <?php else: ?>
                <div class="patient-photo-placeholder">👤</div>
            <?php endif; ?>

            <!-- Patient ID -->
            <div class="patient-id-badge">
                <div class="patient-id-label">Patient ID &amp; Default Password</div>
                <div class="patient-id-value"><?= htmlspecialchars($modal_data['patient_id']) ?></div>
            </div>

            <!-- Details -->
            <div class="detail-grid">
                <div class="detail-item">
                    <div class="detail-label">Full Name</div>
                    <div class="detail-value"><?= htmlspecialchars($modal_data['full_name']) ?></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Age / Gender</div>
                    <div class="detail-value"><?= $modal_data['age'] ?> yrs / <?= htmlspecialchars($modal_data['gender']) ?></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Mobile</div>
                    <div class="detail-value"><?= htmlspecialchars($modal_data['mobile_no']) ?></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Aadhar</div>
                    <div class="detail-value"><?= htmlspecialchars($modal_data['aadhar_no']) ?></div>
                </div>
                <div class="detail-item full">
                    <div class="detail-label">Address</div>
                    <div class="detail-value"><?= htmlspecialchars($modal_data['address']) ?></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Registered By</div>
                    <div class="detail-value"><?= htmlspecialchars($modal_data['exec_name']) ?> (<?= htmlspecialchars($modal_data['exec_id']) ?>)</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Date &amp; Time</div>
                    <div class="detail-value"><?= $modal_data['datetime'] ?></div>
                </div>
            </div>

            <!-- Payment receipt -->
            <div class="payment-receipt">
                <div class="payment-receipt-icon">🧾</div>
                <div class="payment-receipt-info">
                    <div class="label">Payment Received via</div>
                    <div class="value"><?= htmlspecialchars($modal_data['payment']) ?></div>
                </div>
                <div class="payment-receipt-amount">₹20</div>
            </div>

            <div class="receipt-note">
                <p>
                    ई-हेल्थ (eHealth) में पंजीकरण करने के लिए आपका धन्यवाद! आपकी उपयोगकर्ता आईडी:
                    <strong><?= htmlspecialchars($modal_data['patient_id']) ?></strong> है और यही आपका प्रारंभिक पासवर्ड भी है।
                    कृपया सुरक्षा के लिए ई-हेल्थ पोर्टल में लॉगिन करने के बाद अपना पासवर्ड अवश्य बदल लें।
                </p>
                <p>
                    Thank you for registering with eHealth! Your Patient ID is:
                    <strong><?= htmlspecialchars($modal_data['patient_id']) ?></strong> and this is also your default password.
                    You can change your password by logging into the eHealth portal.
                </p>
            </div>

            <!-- Actions -->
            <div class="card-actions">
                <button class="card-btn print"  onclick="window.print()">🖨️ Print Slip</button>
                <button class="card-btn vitals" onclick="window.location.href='../patient/patient_vitals.php?pid=<?= urlencode($modal_data['patient_id']) ?>'">💉 Enter Vitals</button>
            </div>
            <button class="card-close-btn" onclick="document.getElementById('successModal').classList.remove('show');document.getElementById('successModal').style.display='none';">
                ✖ Close &amp; Register Another Patient
            </button>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
// ── Auto-calc age from DOB ────────────────────────────────
document.getElementById('dob').addEventListener('change', function() {
    const dob = new Date(this.value);
    if (!isNaN(dob)) {
        const today = new Date();
        let age = today.getFullYear() - dob.getFullYear();
        const m = today.getMonth() - dob.getMonth();
        if (m < 0 || (m === 0 && today.getDate() < dob.getDate())) age--;
        document.getElementById('age').value = age >= 0 ? age : '';
    }
});

// ── Digits-only for Aadhar & Mobile ──────────────────────
['aadharNo','mobileNo'].forEach(id => {
    document.getElementById(id).addEventListener('input', function() {
        this.value = this.value.replace(/\D/g,'');
    });
});

// ── Pincode lookup ────────────────────────────────────────
function lookupPincode() {
    const pin = document.getElementById('pincode').value.trim();
    const status = document.getElementById('pincodeStatus');
    const manualNote = document.getElementById('manualAddressNote');
    if (pin.length !== 6 || !/^\d+$/.test(pin)) {
        status.className = 'pincode-status error';
        status.textContent = '⚠️ Enter a valid 6-digit pincode first.';
        return;
    }
    status.className = 'pincode-status loading';
    status.textContent = '⏳ Looking up pincode…';
    manualNote.style.display = 'none';

    fetch(`https://api.postalpincode.in/pincode/${pin}`)
        .then(r => r.json())
        .then(data => {
            if (data[0].Status === 'Success') {
                const post = data[0].PostOffice[0];
                const stateEl    = document.getElementById('state');
                const districtEl = document.getElementById('district');
                stateEl.value    = post.State;
                districtEl.value = post.District;
                stateEl.readOnly    = false; stateEl.classList.add('state-unlocked');
                districtEl.readOnly = false; districtEl.classList.add('state-unlocked');

                // Populate city dropdown
                const cities = [...new Set(data[0].PostOffice.map(p => p.Name))];
                const cityEl = document.getElementById('city');
                cityEl.innerHTML = '<option value="">Select locality</option>' +
                    cities.map(c => `<option value="${c}">${c}</option>`).join('');
                cityEl.disabled = false;

                status.className = 'pincode-status success';
                status.textContent = `✅ ${post.District}, ${post.State}`;
            } else {
                document.getElementById('state').readOnly = false;
                document.getElementById('district').readOnly = false;
                document.getElementById('state').classList.add('state-unlocked');
                document.getElementById('district').classList.add('state-unlocked');
                status.className = 'pincode-status error';
                status.textContent = '❌ Pincode not found. Please fill State and District manually.';
                manualNote.style.display = 'block';
            }
        })
        .catch(() => {
            status.className = 'pincode-status error';
            status.textContent = '❌ Network error. Fill address manually.';
            document.getElementById('state').readOnly = false;
            document.getElementById('district').readOnly = false;
        });
}

document.getElementById('pincodeLookupBtn').addEventListener('click', lookupPincode);
document.getElementById('pincode').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') { e.preventDefault(); lookupPincode(); }
});

// ── Photo upload preview ──────────────────────────────────
function handlePhotoUpload(e) {
    const file = e.target.files[0];
    if (!file) return;
    const base64Input = document.getElementById('photo_base64');
    base64Input.value = '';
    stopWebcam();
    const reader = new FileReader();
    reader.onload = function(ev) {
        document.getElementById('photoPreview').src = ev.target.result;
        document.getElementById('photoPreview').style.display = 'block';
        document.getElementById('photoPlaceholder').style.display = 'none';
        // Fallback payload for clients where multipart photo upload is unreliable.
        base64Input.value = ev.target.result;
    };
    reader.readAsDataURL(file);
}
document.getElementById('photoFile').addEventListener('change', handlePhotoUpload);

// ── Webcam ────────────────────────────────────────────────
let stream = null;
document.getElementById('webcamBtn').addEventListener('click', async function() {
    try {
        stream = await navigator.mediaDevices.getUserMedia({ video: true });
        document.getElementById('webcamVideo').srcObject = stream;
        document.getElementById('webcamContainer').style.display = 'block';
    } catch(e) {
        alert('Could not access webcam: ' + e.message);
    }
});

function captureWebcam() {
    const video  = document.getElementById('webcamVideo');
    const canvas = document.getElementById('snapCanvas');
    canvas.width  = video.videoWidth;
    canvas.height = video.videoHeight;
    canvas.getContext('2d').drawImage(video, 0, 0);
    const dataUrl = canvas.toDataURL('image/jpeg', 0.85);
    document.getElementById('photoPreview').src = dataUrl;
    document.getElementById('photoPreview').style.display = 'block';
    document.getElementById('photoPlaceholder').style.display = 'none';
    document.getElementById('photo_base64').value = dataUrl;
    document.getElementById('photoFile').value = '';
    stopWebcam();
}

function stopWebcam() {
    if (stream) { stream.getTracks().forEach(t => t.stop()); stream = null; }
    document.getElementById('webcamContainer').style.display = 'none';
}

// ── Submit with spinner ───────────────────────────────────
document.getElementById('patientForm').addEventListener('submit', function(e) {
    const btn = document.getElementById('submitBtn');
    const preview = document.getElementById('photoPreview');
    const photoB64 = document.getElementById('photo_base64');
    // Basic JS validation
    const required = ['fullName','dob','aadharNo','mobileNo','pincode','state','district'];
    for (const id of required) {
        if (!document.getElementById(id).value.trim()) {
            document.getElementById(id).focus();
            return; // Let HTML5 required handle messaging
        }
    }
    if (!document.querySelector('input[name="gender"]:checked')) return;
    if (!document.querySelector('input[name="paymentMethod"]:checked')) {
        alert('Please select a payment method.');
        e.preventDefault();
        return;
    }
    if (!photoB64.value && preview.src && preview.src.startsWith('data:image/')) {
        photoB64.value = preview.src;
    }
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-inline"></span>Registering…';
});

// ── Reset form ────────────────────────────────────────────
function resetForm() {
    if (!confirm('Reset all form fields?')) return;
    document.getElementById('patientForm').reset();
    document.getElementById('photoPreview').style.display = 'none';
    document.getElementById('photoPlaceholder').style.display = 'flex';
    document.getElementById('photo_base64').value = '';
    document.getElementById('photoFile').value = '';
    document.getElementById('pincodeStatus').className = 'pincode-status';
    document.getElementById('pincodeStatus').textContent = '';
    document.getElementById('manualAddressNote').style.display = 'none';
    document.getElementById('state').readOnly = true;
    document.getElementById('district').readOnly = true;
    document.getElementById('city').innerHTML = '<option value="">Enter pincode first</option>';
    document.getElementById('city').disabled = true;
    stopWebcam();
}
</script>

</body>
</html>
