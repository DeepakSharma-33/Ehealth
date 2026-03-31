<?php
require_once __DIR__ . '/../../config/config.php';
require_role('executive', 'executive_login.php');

$exec_name = get_session_name();
$exec_code = (string)($_SESSION['executive_id'] ?? get_session_id());

function h(mixed $value): string {
    return htmlspecialchars((string)$value);
}

function money_inr(mixed $value): string {
    $num = is_numeric($value) ? (float)$value : 20.0;
    $txt = number_format($num, 2, '.', '');
    $txt = rtrim(rtrim($txt, '0'), '.');
    return '₹' . $txt;
}

$db = db();
$stmt = $db->prepare(
    "SELECT patient_id, full_name, age, gender, aadhar_no, mobile_no, address_full,
            payment_method, payment_amount, photo_filename, created_at,
            registered_by_exec_id, registered_by_exec_name
     FROM patients
     WHERE DATE(created_at) = CURDATE()
       AND registered_by_exec_id = ?
     ORDER BY created_at DESC"
);
$stmt->bind_param('s', $exec_code);
$stmt->execute();
$result = $stmt->get_result();

$patients = [];
while ($row = $result->fetch_assoc()) {
    $row['photo_url'] = patient_photo_url($row['photo_filename'] ?? null);
    $row['masked_aadhar'] = mask_aadhar_last4($row['aadhar_no'] ?? '');
    $row['display_datetime'] = $row['created_at'] ? date('d M Y, h:i A', strtotime($row['created_at'])) : '-';
    $row['display_amount'] = money_inr($row['payment_amount']);
    $patients[] = $row;
}
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Print Registration Slip - eHealth</title>
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        :root {
            --primary:#1a6b4a;
            --primary-light:#2d8f63;
            --primary-dark:#134d36;
            --accent:#f0a500;
            --bg:#f0f7f4;
            --white:#ffffff;
            --border:#c8ddd5;
            --text:#1a2e25;
            --muted:#5a7a6a;
        }

        body {
            font-family:'Segoe UI',sans-serif;
            background:var(--bg);
            min-height:100vh;
            color:var(--text);
            background-image:
                radial-gradient(circle at 18% 18%, rgba(26,107,74,.08) 0%, transparent 50%),
                radial-gradient(circle at 82% 82%, rgba(240,165,0,.06) 0%, transparent 50%);
        }

        .navbar {
            background:var(--primary);
            color:white;
            height:58px;
            padding:0 24px;
            display:flex;
            align-items:center;
            justify-content:space-between;
            box-shadow:0 2px 10px rgba(0,0,0,.15);
        }
        .navbar .brand { font-size:20px; font-weight:800; letter-spacing:1px; }
        .navbar a { color:white; text-decoration:none; font-size:14px; font-weight:700; }

        .page { max-width:1120px; margin:22px auto 40px; padding:0 16px; }
        .page-title { margin-bottom:16px; }
        .page-title h1 { font-size:30px; color:var(--primary-dark); margin-bottom:4px; }
        .page-title p { font-size:14px; color:var(--muted); }

        .summary {
            background:white;
            border:1.5px solid var(--border);
            border-radius:12px;
            padding:12px 14px;
            margin-bottom:14px;
            display:flex;
            gap:14px;
            flex-wrap:wrap;
            align-items:center;
            font-size:13px;
        }
        .summary .chip {
            background:#f5faf7;
            border:1px solid var(--border);
            border-radius:999px;
            padding:4px 10px;
            font-weight:700;
            color:var(--primary);
        }

        .search-bar {
            background:white;
            border:1.5px solid var(--border);
            border-radius:12px;
            padding:12px;
            margin-bottom:14px;
            display:flex;
            gap:10px;
            align-items:center;
            flex-wrap:wrap;
        }
        .search-input {
            flex:1;
            min-width:260px;
            border:2px solid var(--border);
            border-radius:10px;
            padding:10px 12px;
            font-size:14px;
            font-family:inherit;
            outline:none;
        }
        .search-input:focus {
            border-color:var(--primary-light);
            box-shadow:0 0 0 3px rgba(45,143,99,.12);
        }
        .search-count {
            font-size:12px;
            font-weight:700;
            color:var(--primary);
            background:#eef8f2;
            border:1px solid #cce7d9;
            border-radius:999px;
            padding:5px 10px;
        }

        .cards-grid {
            display:grid;
            grid-template-columns:repeat(auto-fill, minmax(280px, 1fr));
            gap:14px;
        }
        .patient-card {
            background:white;
            border-radius:14px;
            border:1.5px solid var(--border);
            box-shadow:0 4px 14px rgba(26,107,74,.10);
            padding:14px;
            display:flex;
            flex-direction:column;
            gap:10px;
        }
        .patient-top { display:flex; gap:10px; align-items:center; }
        .card-photo {
            width:54px; height:54px; border-radius:50%;
            object-fit:cover; border:2px solid var(--primary-light); flex-shrink:0;
        }
        .card-photo-placeholder {
            width:54px; height:54px; border-radius:50%;
            background:#e8f0ec; border:2px solid var(--primary-light);
            display:flex; align-items:center; justify-content:center;
            font-size:20px; flex-shrink:0;
        }
        .patient-name { font-size:16px; font-weight:800; color:var(--primary-dark); }
        .patient-id { font-size:12px; font-weight:700; color:var(--primary); background:#eef8f2; border:1px solid #cce7d9; border-radius:999px; padding:2px 9px; display:inline-block; margin-top:2px; }
        .mini-grid {
            display:grid;
            grid-template-columns:1fr;
            gap:4px;
            font-size:12px;
            color:#3f5b4f;
        }
        .mini-grid b { color:#243a30; }
        .addr {
            font-size:12px;
            color:#3f5b4f;
            line-height:1.45;
            display:-webkit-box;
            -webkit-line-clamp:2;
            -webkit-box-orient:vertical;
            overflow:hidden;
            min-height:34px;
        }
        .card-actions {
            display:flex;
            justify-content:flex-end;
            margin-top:2px;
        }
        .btn-print-open {
            border:none;
            background:linear-gradient(135deg,var(--primary),var(--primary-light));
            color:white;
            font-family:inherit;
            font-size:13px;
            font-weight:700;
            padding:9px 14px;
            border-radius:9px;
            cursor:pointer;
        }
        .btn-print-open:hover { opacity:.9; }

        .empty {
            background:white;
            border:1.5px solid var(--border);
            border-radius:14px;
            padding:30px 18px;
            text-align:center;
            color:var(--muted);
            font-size:15px;
        }

        /* --- Same slip modal styling as registration slip --- */
        .modal-overlay {
            display:none;
            position:fixed;
            inset:0;
            background:rgba(0,0,0,.55);
            backdrop-filter:blur(4px);
            z-index:1000;
            align-items:center;
            justify-content:center;
            padding:20px;
        }
        .modal-overlay.show { display:flex; animation:fadeIn .3s ease; }
        @keyframes fadeIn { from{opacity:0} to{opacity:1} }
        .success-card {
            background:white;
            border-radius:20px;
            max-width:540px;
            width:100%;
            overflow:hidden;
            box-shadow:0 24px 60px rgba(0,0,0,.25);
            animation:popUp .4s cubic-bezier(.34,1.56,.64,1);
            max-height:90vh;
            overflow-y:auto;
        }
        @keyframes popUp { from{opacity:0;transform:scale(.85) translateY(20px)} to{opacity:1;transform:scale(1) translateY(0)} }
        .success-header {
            background:linear-gradient(135deg,var(--primary),var(--primary-light));
            color:white;
            padding:26px 28px 20px;
            text-align:center;
        }
        .success-checkmark {
            width:62px; height:62px;
            background:rgba(255,255,255,.2);
            border-radius:50%;
            display:flex; align-items:center; justify-content:center;
            font-size:30px;
            margin:0 auto 12px;
        }
        .success-header h2 { font-size:21px; font-weight:700; }
        .success-body { padding:22px 28px 26px; }
        .patient-photo-round {
            width:76px; height:76px; border-radius:50%; object-fit:cover;
            border:3px solid var(--primary); display:block; margin:0 auto 14px;
        }
        .patient-photo-placeholder {
            width:76px; height:76px; border-radius:50%;
            background:#e8f0ec; border:3px solid var(--primary);
            display:flex; align-items:center; justify-content:center;
            font-size:30px; margin:0 auto 14px;
        }
        .patient-id-badge { text-align:center; margin-bottom:18px; }
        .patient-id-value { font-size:28px; font-weight:700; color:var(--primary); letter-spacing:2px; }
        .patient-id-label { font-size:11px; color:var(--muted); text-transform:uppercase; letter-spacing:1px; margin-bottom:4px; }
        .detail-grid { display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:14px; }
        .detail-item.full { grid-column:1/-1; }
        .detail-label { font-size:11px; font-weight:600; color:var(--muted); text-transform:uppercase; letter-spacing:.5px; margin-bottom:2px; }
        .detail-value { font-size:14px; font-weight:600; color:var(--text); }
        .payment-receipt {
            display:flex; align-items:center; gap:10px;
            background:#fff8e1; border:1px solid #ffd54f;
            border-radius:10px; padding:12px 16px; margin-bottom:14px;
        }
        .payment-receipt-icon { font-size:22px; }
        .payment-receipt-info { flex:1; }
        .payment-receipt-info .label { font-size:11px; color:#856404; font-weight:600; text-transform:uppercase; }
        .payment-receipt-info .value { font-size:15px; color:#5c3d00; font-weight:700; }
        .payment-receipt-amount { font-size:20px; font-weight:800; color:var(--primary); }
        .receipt-note {
            background:#f7fbf8;
            border:1px solid var(--border);
            border-left:4px solid var(--primary);
            border-radius:10px;
            padding:12px 14px;
            margin-bottom:14px;
        }
        .receipt-note p { margin:0; font-size:13px; line-height:1.6; color:#2c3e35; }
        .receipt-note p + p { margin-top:10px; padding-top:10px; border-top:1px dashed #c8ddd5; }
        .card-actions-modal { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-top:14px; }
        .card-btn {
            padding:12px; border-radius:10px; font-size:14px; font-weight:700; cursor:pointer;
            font-family:inherit; transition:all .2s; text-align:center; border:2px solid transparent;
        }
        .card-btn.print { background:var(--accent); color:#1a1a1a; border-color:var(--accent); }
        .card-btn.close { background:var(--primary); color:white; border-color:var(--primary); }
        .card-btn:hover { transform:translateY(-2px); box-shadow:0 4px 12px rgba(0,0,0,.12); }
        .card-close-btn {
            width:100%; margin-top:10px; padding:10px;
            background:none; border:1px solid var(--border); border-radius:8px;
            color:var(--muted); font-size:13px; cursor:pointer; font-family:inherit;
        }
        .card-close-btn:hover { background:#f5f5f5; }

        @media(max-width:640px) {
            .card-actions-modal { grid-template-columns:1fr; }
            .detail-grid { grid-template-columns:1fr; }
            .search-input { min-width:100%; }
        }

        @media print {
            body > * { display:none !important; }
            #slipModal {
                display:block !important;
                position:static !important;
                background:none !important;
                backdrop-filter:none !important;
                padding:0 !important;
            }
            .success-card {
                box-shadow:none !important;
                border:1px solid #ccc !important;
                border-radius:8px !important;
                max-height:none !important;
                overflow:visible !important;
                animation:none !important;
                width:100% !important;
                max-width:480px !important;
                margin:0 auto !important;
            }
            .card-actions-modal, .card-close-btn { display:none !important; }
            html, body { height:auto !important; margin:0 !important; }
        }
    </style>
</head>
<body>
<nav class="navbar">
    <a class="brand" href="<?= htmlspecialchars(app_base_url()) ?>/"><span style="display:flex;align-items:center;gap:14px;line-height:1;"><img src="<?= htmlspecialchars(app_base_url()) ?>/public/assets/SRMS_TRUST_LOGO.png" alt="SRMST Trust Logo" style="height:48px;width:auto;display:block;object-fit:contain;filter:drop-shadow(0 1px 3px rgba(0,0,0,.35));"><span style="font-size:22px;font-weight:800;letter-spacing:1px;color:#fff;text-shadow:0 1px 2px rgba(0,0,0,.35);white-space:nowrap;">SRMS EHEALTH</span></span></a>
    <div style="display:flex;gap:14px;align-items:center;">
        <a href="executive_dashboard.php">Back to Dashboard</a>
        <a href="<?= htmlspecialchars(app_base_url()) ?>/about.php">ABOUT</a>
        <a href="<?= htmlspecialchars(app_base_url()) ?>/contact.php">CONTACT</a>
    </div>
</nav>

<div class="page">
    <div class="page-title">
        <h1>Print Registration Slip</h1>
        <p>Today’s registrations by <?= h($exec_name) ?>.</p>
    </div>

    <div class="summary">
        <span class="chip"><?= count($patients) ?> Patient<?= count($patients) === 1 ? '' : 's' ?></span>
        <span>Date: <?= h(date('d M Y')) ?></span>
        <span>Executive ID: <?= h($exec_code) ?></span>
    </div>

    <?php if (!empty($patients)): ?>
        <div class="search-bar">
            <input id="searchInput" class="search-input" type="text"
                   placeholder="Search by Patient ID, name, mobile, or address">
            <span class="search-count" id="visibleCount"><?= count($patients) ?> shown</span>
        </div>
    <?php endif; ?>

    <?php if (empty($patients)): ?>
        <div class="empty">No patient registrations found for today.</div>
    <?php else: ?>
        <div class="cards-grid" id="cardsGrid">
            <?php foreach ($patients as $idx => $p): ?>
                <?php $search_blob = strtolower(trim(($p['patient_id'] ?? '') . ' ' . ($p['full_name'] ?? '') . ' ' . ($p['mobile_no'] ?? '') . ' ' . ($p['address_full'] ?? ''))); ?>
                <div class="patient-card" data-search="<?= h($search_blob) ?>">
                    <div class="patient-top">
                        <?php if (!empty($p['photo_url'])): ?>
                            <img src="<?= h($p['photo_url']) ?>" alt="<?= h($p['full_name']) ?>" class="card-photo">
                        <?php else: ?>
                            <div class="card-photo-placeholder">👤</div>
                        <?php endif; ?>
                        <div>
                            <div class="patient-name"><?= h($p['full_name']) ?></div>
                            <span class="patient-id"><?= h($p['patient_id']) ?></span>
                        </div>
                    </div>
                    <div class="mini-grid">
                        <div><b>Mobile:</b> <?= h($p['mobile_no']) ?></div>
                        <div><b>Date:</b> <?= h($p['display_datetime']) ?></div>
                    </div>
                    <div class="addr"><b>Address:</b> <?= h($p['address_full']) ?></div>
                    <div class="card-actions">
                        <button type="button" class="btn-print-open" onclick="openSlip(<?= (int)$idx ?>)">Print Slip</button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="empty" id="searchEmpty" style="display:none; margin-top:12px;">No matching patients for this search.</div>
    <?php endif; ?>
</div>

<div class="modal-overlay" id="slipModal">
    <div class="success-card">
        <div class="success-header">
            <div class="success-checkmark">🧾</div>
            <h2>Registration Slip</h2>
        </div>
        <div class="success-body">
            <img id="slipPhoto" class="patient-photo-round" src="" alt="Patient Photo" style="display:none;">
            <div id="slipPhotoPlaceholder" class="patient-photo-placeholder">👤</div>

            <div class="patient-id-badge">
                <div class="patient-id-label">Patient ID &amp; Default Password</div>
                <div class="patient-id-value" id="slipPatientId">-</div>
            </div>

            <div class="detail-grid">
                <div class="detail-item">
                    <div class="detail-label">Full Name</div>
                    <div class="detail-value" id="slipFullName">-</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Age / Gender</div>
                    <div class="detail-value" id="slipAgeGender">-</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Mobile</div>
                    <div class="detail-value" id="slipMobile">-</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Aadhar</div>
                    <div class="detail-value" id="slipAadhar">-</div>
                </div>
                <div class="detail-item full">
                    <div class="detail-label">Address</div>
                    <div class="detail-value" id="slipAddress">-</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Registered By</div>
                    <div class="detail-value" id="slipRegisteredBy">-</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Date &amp; Time</div>
                    <div class="detail-value" id="slipDatetime">-</div>
                </div>
            </div>

            <div class="payment-receipt">
                <div class="payment-receipt-icon">🧾</div>
                <div class="payment-receipt-info">
                    <div class="label">Payment Received via</div>
                    <div class="value" id="slipPaymentMode">-</div>
                </div>
                <div class="payment-receipt-amount" id="slipAmount">₹20</div>
            </div>

            <div class="receipt-note">
                <p>
                    ई-हेल्थ (eHealth) में पंजीकरण करने के लिए आपका धन्यवाद! आपकी उपयोगकर्ता आईडी:
                    <strong id="slipPidHindi">-</strong> है और यही आपका प्रारंभिक पासवर्ड भी है।
                    कृपया सुरक्षा के लिए ई-हेल्थ पोर्टल में लॉगिन करने के बाद अपना पासवर्ड अवश्य बदल लें।
                </p>
                <p>
                    Thank you for registering with eHealth! Your Patient ID is:
                    <strong id="slipPidEnglish">-</strong> and this is also your default password.
                    You can change your password by logging into the eHealth portal.
                </p>
            </div>

            <div class="card-actions-modal">
                <button class="card-btn print" onclick="window.print()">Print Slip</button>
                <button class="card-btn close" onclick="closeSlip()">Close</button>
            </div>
            <button class="card-close-btn" onclick="closeSlip()">Close</button>
        </div>
    </div>
</div>

<script>
const patients = <?= json_encode($patients, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;

function openSlip(index) {
    const p = patients[index];
    if (!p) return;

    const photo = document.getElementById('slipPhoto');
    const placeholder = document.getElementById('slipPhotoPlaceholder');

    if (p.photo_url) {
        photo.src = p.photo_url;
        photo.style.display = 'block';
        placeholder.style.display = 'none';
    } else {
        photo.src = '';
        photo.style.display = 'none';
        placeholder.style.display = 'flex';
    }

    document.getElementById('slipPatientId').textContent = p.patient_id || '-';
    document.getElementById('slipFullName').textContent = p.full_name || '-';
    document.getElementById('slipAgeGender').textContent = `${p.age || '-'} yrs / ${p.gender || '-'}`;
    document.getElementById('slipMobile').textContent = p.mobile_no || '-';
    document.getElementById('slipAadhar').textContent = p.masked_aadhar || '-';
    document.getElementById('slipAddress').textContent = p.address_full || '-';
    document.getElementById('slipRegisteredBy').textContent = `${p.registered_by_exec_name || '-'} (${p.registered_by_exec_id || '-'})`;
    document.getElementById('slipDatetime').textContent = p.display_datetime || '-';
    document.getElementById('slipPaymentMode').textContent = p.payment_method || '-';
    document.getElementById('slipAmount').textContent = p.display_amount || '₹20';
    document.getElementById('slipPidHindi').textContent = p.patient_id || '-';
    document.getElementById('slipPidEnglish').textContent = p.patient_id || '-';

    document.getElementById('slipModal').classList.add('show');
}

function closeSlip() {
    document.getElementById('slipModal').classList.remove('show');
}

document.getElementById('slipModal').addEventListener('click', function (e) {
    if (e.target === this) closeSlip();
});

function filterCards() {
    const input = document.getElementById('searchInput');
    if (!input) return;

    const q = (input.value || '').trim().toLowerCase();
    const cards = document.querySelectorAll('.patient-card');
    let visible = 0;

    cards.forEach(card => {
        const hay = (card.dataset.search || '').toLowerCase();
        const ok = !q || hay.includes(q);
        card.style.display = ok ? '' : 'none';
        if (ok) visible++;
    });

    const countEl = document.getElementById('visibleCount');
    if (countEl) countEl.textContent = `${visible} shown`;

    const emptyEl = document.getElementById('searchEmpty');
    if (emptyEl) emptyEl.style.display = visible === 0 ? 'block' : 'none';
}

document.getElementById('searchInput')?.addEventListener('input', filterCards);
</script>
</body>
</html>
