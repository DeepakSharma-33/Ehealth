<?php
require_once __DIR__ . '/../../config/config.php';
require_role('executive', 'executive_login.php');

$execId  = get_session_id();  // executive_id (int)
$db      = db();
$error   = '';
$success = '';

// Fetch executive by executive_id column
$st = $db->prepare('SELECT * FROM executives WHERE executive_id = ?');
$st->bind_param('i', $execId);
$st->execute();
$exec = $st->get_result()->fetch_assoc();
$st->close();

if (!$exec) { die('Executive not found.'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName      = trim($_POST['fullName'] ?? '');
    $gender        = trim($_POST['gender'] ?? '');
    $dob           = trim($_POST['dob'] ?? '');
    $email         = trim($_POST['email'] ?? '');
    $state         = trim($_POST['state'] ?? '');
    $district      = trim($_POST['district'] ?? '');
    $city          = trim($_POST['city'] ?? '') ?: null;
    $village       = trim($_POST['village'] ?? '') ?: null;
    $street        = trim($_POST['street'] ?? '') ?: null;
    $pincode       = trim($_POST['pincode'] ?? '');
    $qualification = trim($_POST['qualification'] ?? '');
    $otherQual     = trim($_POST['otherQualification'] ?? '');

    if ($qualification === 'Other') { $qualification = $otherQual ?: 'Other'; }

    if (!$fullName || !$gender || !$dob || !$email || !$state || !$district || !$pincode || !$qualification) {
        $error = 'Please fill in all required fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email address.';
    } else {
        $up = $db->prepare(
            'UPDATE executives SET full_name=?, gender=?, dob=?, email=?,
             state=?, district=?, city=?, village=?, street=?, pincode=?, qualification=?
             WHERE executive_id=?'
        );
        $up->bind_param('sssssssssssi',
            $fullName, $gender, $dob, $email,
            $state, $district, $city, $village, $street, $pincode, $qualification,
            $execId
        );
        if ($up->execute()) {
            $success = 'Profile updated successfully.';
            $_SESSION['user_name'] = $fullName;
            $exec = array_merge($exec, compact('fullName','gender','dob','email','state','district','city','village','street','pincode','qualification'));
            $exec['full_name'] = $fullName;
        } else {
            $error = 'Failed to update profile.';
        }
        $up->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - Executive</title>
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Segoe UI',sans-serif; background:linear-gradient(135deg,#a8e6cf,#88d8b0); min-height:100vh; }
        header { background:linear-gradient(to right,#4CAF50,#45a049); padding:15px 50px; display:flex; justify-content:space-between; align-items:center; box-shadow:0 2px 5px rgba(0,0,0,.1); }
        .logo { display:flex; align-items:center; color:white; font-size:22px; font-weight:bold; text-decoration:none; }
        .logo-icon { width:38px; height:38px; background:white; border-radius:50%; display:flex; align-items:center; justify-content:center; margin-right:10px; color:#4CAF50; font-size:18px; }
        nav a { color:white; text-decoration:none; font-size:15px; font-weight:500; margin-left:25px; }
        .container { max-width:850px; margin:40px auto; padding:0 20px 60px; }
        .form-card { background:white; border-radius:20px; padding:40px; box-shadow:0 8px 30px rgba(0,0,0,.12); }
        .form-title { font-size:26px; font-weight:700; color:#2c3e50; margin-bottom:8px; text-align:center; }
        .form-subtitle { font-size:14px; color:#7f8c8d; margin-bottom:20px; text-align:center; }
        .exec-id-badge { text-align:center; margin-bottom:24px; }
        .exec-id-badge span { background:#e8f5e9; color:#2e7d32; font-weight:700; padding:8px 20px; border-radius:20px; font-size:15px; }
        .section-head { font-size:15px; font-weight:700; color:#2e7d32; border-left:4px solid #2e7d32; padding-left:12px; margin:28px 0 16px; }
        .form-grid { display:grid; grid-template-columns:1fr 1fr; gap:18px; }
        .form-group { display:flex; flex-direction:column; }
        .form-group.full { grid-column:1/-1; }
        label { font-size:13px; font-weight:600; color:#333; margin-bottom:6px; }
        .required { color:#e74c3c; }
        input, select, textarea { padding:11px 14px; border:2px solid #e0e0e0; border-radius:10px; font-size:14px; font-family:inherit; outline:none; transition:border-color .25s; background:#f9f9f9; }
        input:focus, select:focus, textarea:focus { border-color:#2e7d32; background:white; }
        input[readonly] { background:#f0f7f0!important; color:#555; cursor:not-allowed; }
        textarea { resize:vertical; min-height:70px; }
        .radio-group { display:flex; gap:20px; align-items:center; padding-top:6px; }
        .radio-group label { font-weight:400; margin-bottom:0; display:flex; align-items:center; gap:6px; cursor:pointer; }
        .radio-group input[type="radio"] { width:auto; padding:0; }
        .alert { padding:13px 18px; border-radius:10px; margin-bottom:22px; font-size:14px; }
        .alert-error   { background:#fdecea; color:#b71c1c; border:1px solid #f5c6cb; }
        .alert-success { background:#e8f5e9; color:#1b5e20; border:1px solid #c3e6cb; }
        .btn-submit { width:100%; padding:15px; background:linear-gradient(135deg,#2e7d32,#43a047); color:white; border:none; border-radius:12px; font-size:15px; font-weight:700; cursor:pointer; margin-top:28px; transition:opacity .2s; }
        .btn-submit:hover { opacity:.92; }
        .back-link { text-align:center; margin-top:18px; }
        .back-link a { color:#2e7d32; text-decoration:none; font-size:14px; font-weight:600; }
        @media(max-width:600px){ .form-grid{grid-template-columns:1fr;} header{padding:15px 20px;} }
    </style>
</head>
<body>
<header>
    <a href="<?= htmlspecialchars(app_base_url()) ?>/" class="logo"><span style="display:flex;align-items:center;gap:14px;line-height:1;"><img src="<?= htmlspecialchars(app_base_url()) ?>/public/assets/SRMS_TRUST_LOGO.png" alt="SRMST Trust Logo" style="height:48px;width:auto;display:block;object-fit:contain;filter:drop-shadow(0 1px 3px rgba(0,0,0,.35));"><span style="font-size:22px;font-weight:800;letter-spacing:1px;color:#fff;text-shadow:0 1px 2px rgba(0,0,0,.35);white-space:nowrap;">SRMS EHEALTH</span></span></a>
    <nav>
        <a href="executive_dashboard.php">← Dashboard</a>
        <a href="<?= htmlspecialchars(app_base_url()) ?>/about.php">ABOUT</a>
        <a href="<?= htmlspecialchars(app_base_url()) ?>/contact.php">CONTACT</a>
        <a href="executive_logout.php">Logout</a>
    </nav>
</header>
<div class="container">
    <div class="form-card">
        <div class="form-title">⚙️ My Profile</div>
        <div class="form-subtitle">Executive — Update Your Information</div>
        <div class="exec-id-badge"><span>🪪 Executive ID: <?= htmlspecialchars($exec['executive_id']) ?></span></div>

        <?php if ($error): ?><div class="alert alert-error">❌ <?= htmlspecialchars($error) ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success">✅ <?= htmlspecialchars($success) ?></div><?php endif; ?>

        <form method="POST">
            <div class="section-head">Personal Information</div>
            <div class="form-grid">
                <div class="form-group full">
                    <label>Full Name <span class="required">*</span></label>
                    <input type="text" name="fullName" value="<?= htmlspecialchars($exec['full_name']) ?>" required>
                </div>
                <div class="form-group">
                    <label>Gender <span class="required">*</span></label>
                    <div class="radio-group">
                        <label><input type="radio" name="gender" value="Male"   <?= $exec['gender']==='Male'  ?'checked':'' ?>> Male</label>
                        <label><input type="radio" name="gender" value="Female" <?= $exec['gender']==='Female'?'checked':'' ?>> Female</label>
                        <label><input type="radio" name="gender" value="Other"  <?= $exec['gender']==='Other' ?'checked':'' ?>> Other</label>
                    </div>
                </div>
                <div class="form-group">
                    <label>Date of Birth <span class="required">*</span></label>
                    <input type="date" name="dob" value="<?= htmlspecialchars($exec['dob']) ?>" required>
                </div>
                <div class="form-group">
                    <label>Mobile Number</label>
                    <input type="text" value="<?= htmlspecialchars($exec['mobile_no']) ?>" readonly>
                </div>
                <div class="form-group">
                    <label>Aadhar Number</label>
                    <input type="text" value="<?= htmlspecialchars(mask_aadhar_last4($exec['aadhar_no'] ?? '')) ?>" readonly>
                </div>
                <div class="form-group">
                    <label>Email <span class="required">*</span></label>
                    <input type="email" name="email" value="<?= htmlspecialchars($exec['email']) ?>" required>
                </div>
                <div class="form-group">
                    <label>Qualification <span class="required">*</span></label>
                    <select name="qualification" id="qualification" required onchange="toggleOtherQual()">
                        <option value="">Select</option>
                        <?php foreach(['10th','12th','Diploma','ITI','Graduate','Post Graduate','Other'] as $q): ?>
                            <option value="<?= $q ?>" <?= $exec['qualification']===$q?'selected':'' ?>><?= $q ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" id="otherQualGroup" style="display:none">
                    <label>Specify Qualification <span class="required">*</span></label>
                    <input type="text" name="otherQualification" id="otherQualInput" placeholder="Enter qualification">
                </div>
            </div>

            <div class="section-head">Address</div>
            <div class="form-grid">
                <div class="form-group">
                    <label>Country</label>
                    <input type="text" value="<?= htmlspecialchars($exec['country']) ?>" readonly>
                </div>
                <div class="form-group">
                    <label>Pincode <span class="required">*</span></label>
                    <input type="text" name="pincode" value="<?= htmlspecialchars($exec['pincode']) ?>" maxlength="6" required>
                </div>
                <div class="form-group">
                    <label>State <span class="required">*</span></label>
                    <input type="text" name="state" value="<?= htmlspecialchars($exec['state']) ?>" required>
                </div>
                <div class="form-group">
                    <label>District <span class="required">*</span></label>
                    <input type="text" name="district" value="<?= htmlspecialchars($exec['district']) ?>" required>
                </div>
                <div class="form-group">
                    <label>City</label>
                    <input type="text" name="city" value="<?= htmlspecialchars($exec['city'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Village / Area</label>
                    <input type="text" name="village" value="<?= htmlspecialchars($exec['village'] ?? '') ?>">
                </div>
                <div class="form-group full">
                    <label>Street Address</label>
                    <textarea name="street"><?= htmlspecialchars($exec['street'] ?? '') ?></textarea>
                </div>
            </div>
            <button type="submit" class="btn-submit">💾 SAVE CHANGES</button>
        </form>
        <div class="back-link"><a href="executive_dashboard.php">← Back to Dashboard</a></div>
    </div>
</div>
<script>
function toggleOtherQual() {
    const val = document.getElementById('qualification').value;
    const grp = document.getElementById('otherQualGroup');
    const inp = document.getElementById('otherQualInput');
    if (val === 'Other') { grp.style.display='flex'; inp.required=true; }
    else { grp.style.display='none'; inp.required=false; inp.value=''; }
}
window.addEventListener('DOMContentLoaded', toggleOtherQual);
</script>
</body>
</html>
