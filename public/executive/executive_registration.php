<?php
require_once __DIR__ . '/../../config/config.php';
require_role('admin', '../admin/admin_login.php');

if (isset($_POST['logout'])) {
    logout_user();
    header('Location: ../admin/admin_login.php');
    exit;
}

$error   = '';
$success = '';
$modal_data = null;

// ── Generate next Executive ID (format: E{YYYY}{MM}{NNN}) ──
function generate_executive_id(mysqli $db): string {
    $year   = date('Y');
    $month  = date('m');
    $prefix = 'E' . $year . $month;
    $like   = $prefix . '%';

    $stmt = $db->prepare('SELECT executive_id FROM executives WHERE executive_id LIKE ? ORDER BY executive_id DESC LIMIT 1');
    $stmt->bind_param('s', $like);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $next = $row ? ((int) substr($row['executive_id'], -3)) + 1 : 1;
    return $prefix . str_pad($next, 3, '0', STR_PAD_LEFT);
}

// ── Handle form submission ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['logout'])) {
    $full_name     = trim($_POST['full_name']      ?? '');
    $gender        = trim($_POST['gender']         ?? '');
    $dob           = trim($_POST['dob']            ?? '');
    $qualification = trim($_POST['qualification']  ?? '');
    $qualification_other = trim($_POST['qualification_other'] ?? '');
    $mobile        = trim($_POST['mobile']         ?? '');
    $email         = trim($_POST['email']          ?? '');
    $aadhar        = trim($_POST['aadhar']         ?? '');
    $pincode       = trim($_POST['pincode']        ?? '');
    $state         = trim($_POST['state']          ?? '');
    $district      = trim($_POST['district']       ?? '');
    $city          = trim($_POST['city']           ?? '');
    $village       = trim($_POST['village']        ?? '');
    $street        = trim($_POST['street']         ?? '');

    if ($qualification === 'Other') {
        $qualification = $qualification_other;
    }

    if (!$full_name || !$gender || !$dob || !$qualification || !$mobile || !$email || !$aadhar || !$pincode || !$state || !$district) {
        $error = 'Please fill in all required fields.';
    } elseif (!preg_match('/^\d{10}$/', $mobile)) {
        $error = 'Mobile number must be exactly 10 digits.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (!preg_match('/^\d{12}$/', $aadhar)) {
        $error = 'Aadhar number must be exactly 12 digits.';
    } elseif (!preg_match('/^\d{6}$/', $pincode)) {
        $error = 'Pincode must be exactly 6 digits.';
    } else {
        $db   = db();
        $stmt = $db->prepare('SELECT id FROM executives WHERE mobile_no = ? OR email = ? OR aadhar_no = ? LIMIT 1');
        $stmt->bind_param('sss', $mobile, $email, $aadhar);
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($exists) {
            $error = 'An executive with this mobile, email, or Aadhar already exists.';
        } else {
            $executive_id = generate_executive_id($db);
            $raw_password = $executive_id;
            $hashed_pw    = hash_password($raw_password);

            $admin_id = get_session_id();
            $stmt = $db->prepare('
                INSERT INTO executives
                    (executive_id, full_name, gender, dob, qualification,
                     mobile_no, email, aadhar_no,
                     pincode, state, district, city, village, street,
                     password, registered_by_admin)
                VALUES (?,?,?,?,?, ?,?,?, ?,?,?,?,?,?, ?, ?)
            ');
            $stmt->bind_param(
                'sssssssssssssssi',
                $executive_id, $full_name, $gender, $dob, $qualification,
                $mobile, $email, $aadhar,
                $pincode, $state, $district, $city, $village, $street,
                $hashed_pw, $admin_id
            );

            if ($stmt->execute()) {
                $success = "Executive registered successfully!<br>
                    &nbsp;👤 <strong>Executive ID:</strong> {$executive_id}<br>
                    &nbsp;🔑 <strong>Default Password:</strong> {$executive_id}<br>
                    &nbsp;📱 Share these credentials with the executive. They can change their password after first login.";
                $modal_data = [
                    'executive_id' => $executive_id,
                    'full_name' => $full_name,
                    'gender' => $gender,
                    'qualification' => $qualification,
                    'mobile' => $mobile,
                    'email' => $email,
                    'aadhar' => mask_aadhar_last4($aadhar),
                    'district' => $district,
                    'state' => $state,
                    'datetime' => date('d M Y, h:i A'),
                ];
                $_POST = [];
            } else {
                $error = 'Database error: ' . $db->error;
            }
            $stmt->close();
        }
    }
}

$admin_name = htmlspecialchars(get_session_name() ?: 'System Administrator');
$has_success_modal = !empty($modal_data);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Executive Registration - eHealth</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', 'Segoe UI', sans-serif;
            background: #a8e6cf;
            min-height: 100vh;
        }

        /* ── Header ── */
        header {
            background: #2e7d32;
            padding: 0 40px;
            height: 56px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .logo {
            display: flex; align-items: center; gap: 10px;
            text-decoration: none; color: white;
            font-size: 20px; font-weight: 700; letter-spacing: 1px;
        }
        .logo-icon {
            width: 36px; height: 36px; background: white;
            border-radius: 50%; display: flex; align-items: center;
            justify-content: center; font-size: 18px;
        }
        nav { display: flex; gap: 32px; align-items: center; }
        nav a { color: white; text-decoration: none; font-size: 14px; font-weight: 500; opacity: 0.9; }
        nav a:hover { opacity: 1; text-decoration: underline; }

        /* ── Page wrapper ── */
        .page-wrap {
            display: flex;
            justify-content: center;
            padding: 36px 20px 56px;
        }

        /* ── Card ── */
        .card {
            background: white;
            border-radius: 16px;
            width: 100%;
            max-width: 860px;
            padding: 44px 52px 48px;
            box-shadow: 0 4px 24px rgba(0,0,0,0.10);
        }

        /* ── Card title ── */
        .card-title {
            text-align: center;
            margin-bottom: 36px;
        }
        .card-title h1 {
            font-size: 28px; font-weight: 700; color: #1a1a2e;
            display: flex; align-items: center; justify-content: center; gap: 10px;
        }
        .card-title p { margin-top: 6px; font-size: 14px; color: #888; }

        /* ── Section heading ── */
        .section-heading {
            font-size: 16px; font-weight: 700; color: #2e7d32;
            border-left: 4px solid #2e7d32;
            padding-left: 12px;
            margin: 32px 0 20px;
        }

        /* ── Form grid ── */
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }
        .form-row.full { grid-template-columns: 1fr; }

        .form-group { display: flex; flex-direction: column; }
        .form-group label { font-size: 13px; font-weight: 500; color: #333; margin-bottom: 7px; }
        .required { color: #e53935; }

        .form-group input,
        .form-group select,
        .form-group textarea {
            padding: 11px 14px;
            border: 1.5px solid #d8d8d8;
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
            background: #f5faf5;
            color: #1a1a2e;
            transition: border-color 0.2s, box-shadow 0.2s;
            outline: none;
        }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            border-color: #2e7d32;
            background: white;
            box-shadow: 0 0 0 3px rgba(46,125,50,0.10);
        }
        .form-group input[readonly] { background: #eef5ee; color: #555; cursor: default; }
        .form-group textarea { resize: vertical; min-height: 84px; }
        .form-group input::placeholder,
        .form-group textarea::placeholder { color: #bbb; }

        /* Radio */
        .radio-group { display: flex; gap: 24px; align-items: center; padding: 11px 0; }
        .radio-group label {
            display: flex; align-items: center; gap: 7px;
            font-weight: 400; font-size: 14px; cursor: pointer; color: #333; margin-bottom: 0;
        }
        .radio-group input[type="radio"] { width: 15px; height: 15px; accent-color: #2e7d32; border: none; padding: 0; background: none; }

        /* Pincode row */
        .pincode-wrap { display: flex; gap: 10px; align-items: stretch; }
        .pincode-wrap input { flex: 1; }
        .lookup-btn {
            padding: 0 22px;
            background: #2e7d32; color: white;
            border: none; border-radius: 8px;
            font-size: 14px; font-weight: 600;
            cursor: pointer;
            display: flex; align-items: center; gap: 7px;
            white-space: nowrap;
            transition: background 0.2s;
        }
        .lookup-btn:hover { background: #256427; }
        .lookup-dot { width: 8px; height: 8px; background: #69f0ae; border-radius: 50%; display: inline-block; }

        /* Hint */
        .hint { font-size: 12px; color: #888; margin-top: 5px; }

        /* ── Alerts ── */
        .alert { padding: 13px 18px; border-radius: 8px; margin-bottom: 24px; font-size: 14px; line-height: 1.6; }
        .alert-error   { background: #fdecea; color: #c62828; border-left: 4px solid #e53935; }
        .alert-success { background: #e8f5e9; color: #1b5e20; border-left: 4px solid #43a047; }

        /* ── Submit button ── */
        .btn-submit {
            display: block; width: 100%; padding: 15px;
            margin-top: 32px;
            background: #2e7d32; color: white;
            border: none; border-radius: 8px;
            font-size: 15px; font-weight: 700;
            letter-spacing: 0.8px; text-transform: uppercase;
            cursor: pointer;
            transition: background 0.2s, transform 0.15s;
        }
        .btn-submit:hover { background: #256427; transform: translateY(-1px); }
        .btn-submit:active { transform: translateY(0); }

        /* ── Back link ── */
        .back-link { text-align: center; margin-top: 18px; }
        .back-link a { color: #2e7d32; font-size: 14px; font-weight: 500; text-decoration: none; }
        .back-link a:hover { text-decoration: underline; }

        body.modal-open header,
        body.modal-open .page-wrap { filter: blur(6px); pointer-events: none; user-select: none; }
        .modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.55); backdrop-filter:blur(4px); z-index:1000; align-items:center; justify-content:center; padding:20px; }
        .modal-overlay.show { display:flex; animation:fadeIn .3s ease; }
        @keyframes fadeIn { from{opacity:0} to{opacity:1} }
        .modal-card { background:#fff; border-radius:18px; max-width:500px; width:100%; box-shadow:0 24px 60px rgba(0,0,0,.25); overflow:hidden; max-height:90vh; overflow-y:auto; }
        .modal-header { background:linear-gradient(135deg,#2e7d32,#43a047); color:#fff; padding:22px 28px 16px; text-align:center; }
        .modal-check { width:56px; height:56px; margin:0 auto 10px; border-radius:50%; background:rgba(255,255,255,.2); display:flex; align-items:center; justify-content:center; font-size:28px; }
        .modal-header h2 { font-size:20px; font-weight:700; }
        .modal-body { padding:22px 28px 24px; }
        .modal-id-badge { text-align:center; margin-bottom:16px; background:#e8f5e9; border:2px solid #a5d6a7; border-radius:12px; padding:12px; }
        .modal-id-label { font-size:11px; color:#5a7a6a; text-transform:uppercase; letter-spacing:1px; margin-bottom:4px; }
        .modal-id-value { font-size:24px; font-weight:800; color:#2e7d32; letter-spacing:2px; }
        .modal-grid { display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:14px; }
        .modal-item.full { grid-column:1/-1; }
        .modal-item-label { font-size:11px; font-weight:600; color:#888; text-transform:uppercase; letter-spacing:.4px; margin-bottom:2px; }
        .modal-item-value { font-size:13px; font-weight:600; color:#1b3a1e; }
        .modal-actions { display:flex; gap:12px; }
        .modal-btn { flex:1; padding:11px; border-radius:8px; font-size:14px; font-weight:700; cursor:pointer; font-family:inherit; border:none; transition:opacity .2s; text-decoration:none; display:inline-flex; align-items:center; justify-content:center; }
        .modal-btn.print { background:#f0a500; color:#1a1a1a; }
        .modal-btn.dashboard { background:#1976d2; color:#fff; }
        .modal-btn.close { background:#2e7d32; color:#fff; }
        .modal-btn:hover { opacity:.88; }

        @media (max-width: 640px) {
            .card { padding: 28px 18px; }
            .form-row { grid-template-columns: 1fr; }
            header { padding: 0 20px; }
            .modal-grid { grid-template-columns: 1fr; }
            .modal-actions { flex-direction: column; }
        }
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
        <a href="/ehealth/index.php">HOME</a>
        <a href="<?= htmlspecialchars(app_base_url()) ?>/about.php">ABOUT</a>
        <a href="<?= htmlspecialchars(app_base_url()) ?>/contact.php">CONTACT</a>
    </nav>
</header>

<div class="page-wrap">
<div class="card">

    <div class="card-title">
        <h1>🧑‍💼 Executive Registration</h1>
        <p>Ehealth Center — Admin Registration</p>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error">❌ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success && !$modal_data): ?>
        <div class="alert alert-success">✅ <?= $success ?></div>
    <?php endif; ?>

    <form method="POST" novalidate>

        <!-- Personal Information -->
        <div class="section-heading">Personal Information</div>

        <div class="form-row full">
            <div class="form-group">
                <label>Full Name <span class="required">*</span></label>
                <input type="text" name="full_name" placeholder="Enter full name"
                       value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>" required>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label>Gender <span class="required">*</span></label>
                <div class="radio-group">
                    <?php foreach (['Male','Female','Other'] as $g): ?>
                        <label>
                            <input type="radio" name="gender" value="<?= $g ?>"
                                <?= (($_POST['gender'] ?? '') === $g) ? 'checked' : '' ?> required>
                            <?= $g ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="form-group">
                <label>Date of Birth <span class="required">*</span></label>
                <input type="date" name="dob"
                       value="<?= htmlspecialchars($_POST['dob'] ?? '') ?>" required>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label>Qualification <span class="required">*</span></label>
                <select name="qualification" id="qualificationSelect" required>
                    <option value="">Select qualification</option>
                    <?php foreach (['10th Pass','12th Pass','Diploma','Bachelor\'s Degree','Master\'s Degree','PhD','Other'] as $q): ?>
                        <option value="<?= htmlspecialchars($q) ?>"
                            <?= (($_POST['qualification'] ?? '') === $q) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($q) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="form-row full" id="qualOtherWrap" style="display:none;">
            <div class="form-group">
                <label>Other Qualification <span class="required">*</span></label>
                <input type="text" name="qualification_other" id="qualificationOther" placeholder="Specify qualification"
                       value="<?= htmlspecialchars($_POST['qualification_other'] ?? '') ?>">
                <span class="hint">Provide the exact qualification if it is not listed above.</span>
            </div>
        </div>

        <!-- Contact Information -->
        <div class="section-heading">Contact Information</div>

        <div class="form-row">
            <div class="form-group">
                <label>Mobile Number <span class="required">*</span></label>
                <input type="tel" name="mobile" placeholder="10-digit mobile"
                       maxlength="10" value="<?= htmlspecialchars($_POST['mobile'] ?? '') ?>" required>
                <span class="hint">📱 Login credentials will be sent to this number</span>
            </div>
            <div class="form-group">
                <label>Email <span class="required">*</span></label>
                <input type="email" name="email" placeholder="executive@email.com"
                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label>Aadhar Number <span class="required">*</span></label>
                <input type="text" name="aadhar" placeholder="12-digit Aadhar"
                       maxlength="12" value="<?= htmlspecialchars($_POST['aadhar'] ?? '') ?>" required>
            </div>
        </div>

        <!-- Address -->
        <div class="section-heading">Address</div>

        <div class="form-row">
            <div class="form-group">
                <label>Pincode <span class="required">*</span></label>
                <div class="pincode-wrap">
                    <input type="text" name="pincode" id="pincode"
                           placeholder="6-digit pincode" maxlength="6"
                           value="<?= htmlspecialchars($_POST['pincode'] ?? '') ?>" required>
                    <button type="button" class="lookup-btn" onclick="lookupPincode()">
                        <span class="lookup-dot"></span> Lookup
                    </button>
                </div>
            </div>
            <div class="form-group">
                <label>Country</label>
                <input type="text" value="India" readonly>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label>State <span class="required">*</span></label>
                <input type="text" name="state" id="state"
                       placeholder="Auto-filled from pincode"
                       value="<?= htmlspecialchars($_POST['state'] ?? '') ?>"
                       readonly required>
            </div>
            <div class="form-group">
                <label>District <span class="required">*</span></label>
                <input type="text" name="district" id="district"
                       placeholder="Auto-filled from pincode"
                       value="<?= htmlspecialchars($_POST['district'] ?? '') ?>"
                       readonly required>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label>City / Locality</label>
                <select name="city" id="city">
                    <?php $city_val = $_POST['city'] ?? ''; ?>
                    <option value="<?= htmlspecialchars($city_val) ?>">
                        <?= $city_val ?: 'Enter pincode first' ?>
                    </option>
                </select>
            </div>
            <div class="form-group">
                <label>Village / Area</label>
                <input type="text" name="village" placeholder="Village or area name"
                       value="<?= htmlspecialchars($_POST['village'] ?? '') ?>">
            </div>
        </div>

        <div class="form-row full">
            <div class="form-group">
                <label>Street Address</label>
                <textarea name="street" placeholder="House No., Street, Landmark..."><?= htmlspecialchars($_POST['street'] ?? '') ?></textarea>
            </div>
        </div>

        <button type="submit" class="btn-submit">REGISTER EXECUTIVE</button>

    </form>

    <div class="back-link">
        <a href="../admin/admin_dashboard.php">← Back to Admin Dashboard</a>
    </div>

</div>
</div>

<?php if ($modal_data): ?>
<div class="modal-overlay show" id="successModal">
    <div class="modal-card">
        <div class="modal-header">
            <div class="modal-check">✅</div>
            <h2>Executive Registered Successfully</h2>
        </div>
        <div class="modal-body">
            <div class="modal-id-badge">
                <div class="modal-id-label">Executive ID &amp; Default Password</div>
                <div class="modal-id-value"><?= htmlspecialchars($modal_data['executive_id']) ?></div>
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
                    <div class="modal-item-label">Mobile</div>
                    <div class="modal-item-value"><?= htmlspecialchars($modal_data['mobile']) ?></div>
                </div>
                <div class="modal-item full">
                    <div class="modal-item-label">Email</div>
                    <div class="modal-item-value"><?= htmlspecialchars($modal_data['email']) ?></div>
                </div>
                <div class="modal-item">
                    <div class="modal-item-label">Aadhar</div>
                    <div class="modal-item-value"><?= htmlspecialchars($modal_data['aadhar']) ?></div>
                </div>
                <div class="modal-item">
                    <div class="modal-item-label">Registered On</div>
                    <div class="modal-item-value"><?= htmlspecialchars($modal_data['datetime']) ?></div>
                </div>
                <div class="modal-item full">
                    <div class="modal-item-label">Location</div>
                    <div class="modal-item-value"><?= htmlspecialchars($modal_data['district']) ?>, <?= htmlspecialchars($modal_data['state']) ?></div>
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
    async function lookupPincode() {
        const pincode = document.getElementById('pincode').value.trim();
        if (!/^\d{6}$/.test(pincode)) {
            alert('Please enter a valid 6-digit pincode.');
            return;
        }
        const btn = document.querySelector('.lookup-btn');
        btn.innerHTML = '⏳ Looking up...';
        btn.disabled = true;

        try {
            const res  = await fetch(`https://api.postalpincode.in/pincode/${pincode}`);
            const data = await res.json();

            if (data[0].Status === 'Success') {
                const info = data[0].PostOffice[0];
                document.getElementById('state').value    = info.State    || '';
                document.getElementById('district').value = info.District || '';

                const citySelect = document.getElementById('city');
                citySelect.innerHTML = '';
                const seen = new Set();
                data[0].PostOffice.forEach(po => {
                    if (!seen.has(po.Name)) {
                        seen.add(po.Name);
                        const opt = document.createElement('option');
                        opt.value = opt.textContent = po.Name;
                        citySelect.appendChild(opt);
                    }
                });
            } else {
                alert('Pincode not found. Please fill address manually.');
                ['state','district'].forEach(id => {
                    const el = document.getElementById(id);
                    el.removeAttribute('readonly');
                    el.value = '';
                });
            }
        } catch {
            alert('Unable to fetch pincode data. Please fill address manually.');
        } finally {
            btn.innerHTML = '<span class="lookup-dot"></span> Lookup';
            btn.disabled = false;
        }
    }

    function toggleQualificationOther() {
        const select = document.getElementById('qualificationSelect');
        const wrap   = document.getElementById('qualOtherWrap');
        const input  = document.getElementById('qualificationOther');
        if (!select || !wrap || !input) return;
        const isOther = select.value === 'Other';
        wrap.style.display = isOther ? 'block' : 'none';
        input.required = isOther;
        if (!isOther) input.value = '';
    }

    document.addEventListener('DOMContentLoaded', toggleQualificationOther);
    document.getElementById('qualificationSelect')?.addEventListener('change', toggleQualificationOther);

    // Digits only
    ['mobile','aadhar','pincode'].forEach(name => {
        document.querySelector(`[name="${name}"]`)?.addEventListener('input', function () {
            this.value = this.value.replace(/\D/g, '');
        });
    });
</script>

</body>
</html>
