<?php
require_once __DIR__ . '/../../config/config.php';
require_role('executive', 'executive_login.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Existing Patient Search - eHealth</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800&display=swap');

        * { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --green-dark: #1b5e20;
            --green-mid: #2e7d32;
            --green-soft: #e8f5e9;
            --blue-mid: #1565c0;
            --blue-soft: #e3f2fd;
            --amber-soft: #fff8e1;
            --text-dark: #1f2a1f;
            --text-mid: #425642;
            --danger: #b71c1c;
            --danger-soft: #fdecea;
            --card: #ffffff;
            --border: #d6e4d7;
        }

        body {
            font-family: 'Nunito', 'Segoe UI', sans-serif;
            background: linear-gradient(155deg, #d9eedb 0%, #c6e7cb 100%);
            color: var(--text-dark);
            min-height: 100vh;
        }

        .navbar {
            background: var(--green-dark);
            height: 58px;
            padding: 0 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.18);
        }

        .brand {
            color: #fff;
            font-size: 18px;
            font-weight: 800;
            letter-spacing: 1px;
            text-decoration: none;
        }

        .back-link {
            text-decoration: none;
            color: #fff;
            font-size: 13px;
            font-weight: 700;
            background: rgba(255, 255, 255, 0.14);
            padding: 7px 14px;
            border-radius: 18px;
        }

        .page {
            max-width: 1100px;
            margin: 0 auto;
            padding: 24px 16px 40px;
        }

        .title {
            margin-bottom: 16px;
        }

        .title h1 {
            font-size: 30px;
            font-weight: 800;
            margin-bottom: 4px;
        }

        .title p {
            color: var(--text-mid);
            font-size: 14px;
            font-weight: 600;
        }

        .search-box {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 16px;
            box-shadow: 0 4px 16px rgba(30, 70, 35, 0.08);
            margin-bottom: 16px;
        }

        .row {
            display: grid;
            grid-template-columns: 1fr 1fr auto;
            gap: 10px;
        }

        .input {
            width: 100%;
            border: 2px solid #dfe8df;
            border-radius: 10px;
            padding: 11px 12px;
            font-size: 14px;
            font-family: inherit;
        }

        .input:focus {
            outline: none;
            border-color: var(--green-mid);
            box-shadow: 0 0 0 3px rgba(46, 125, 50, 0.12);
        }

        .btn-search {
            border: none;
            border-radius: 10px;
            padding: 11px 18px;
            background: linear-gradient(135deg, var(--green-mid), var(--green-dark));
            color: #fff;
            font-size: 14px;
            font-weight: 800;
            cursor: pointer;
        }

        .hint {
            margin-top: 8px;
            color: var(--text-mid);
            font-size: 12px;
            font-weight: 600;
        }

        .msg {
            display: none;
            margin-bottom: 16px;
            padding: 11px 14px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 700;
        }

        .msg.error {
            display: block;
            background: var(--danger-soft);
            color: var(--danger);
            border: 1px solid #f6c9c9;
        }

        .msg.info {
            display: block;
            background: var(--blue-soft);
            color: #0d47a1;
            border: 1px solid #bbdefb;
        }

        .results {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 12px;
        }

        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 14px;
            box-shadow: 0 4px 14px rgba(30, 70, 35, 0.08);
        }

        .card-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 10px;
            margin-bottom: 10px;
        }

        .name {
            font-size: 18px;
            font-weight: 800;
            line-height: 1.2;
        }

        .pid {
            margin-top: 4px;
            display: inline-block;
            background: var(--green-soft);
            color: var(--green-dark);
            border-radius: 12px;
            padding: 2px 9px;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: .4px;
        }

        .badges {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-bottom: 10px;
        }

        .badge {
            font-size: 11px;
            font-weight: 800;
            border-radius: 14px;
            padding: 3px 8px;
        }

        .badge-new { background: var(--green-soft); color: var(--green-dark); }
        .badge-old { background: var(--blue-soft); color: var(--blue-mid); }
        .badge-pending { background: var(--amber-soft); color: #8a6d00; }
        .badge-completed { background: #ede7f6; color: #5e35b1; }
        .badge-vitals-done { background: #e8f5e9; color: #2e7d32; }
        .badge-vitals-needed { background: #fff3e0; color: #e65100; }

        .meta {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 6px 10px;
            font-size: 13px;
            color: var(--text-mid);
            margin-bottom: 12px;
        }

        .meta span { color: var(--text-dark); font-weight: 700; }

        .actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
        }

        .btn {
            border: none;
            border-radius: 9px;
            padding: 10px 10px;
            font-size: 13px;
            font-weight: 800;
            text-align: center;
            cursor: pointer;
            text-decoration: none;
            font-family: inherit;
        }

        .btn-mark {
            background: linear-gradient(135deg, #1976d2, #0d47a1);
            color: #fff;
        }

        .btn-vitals {
            background: linear-gradient(135deg, #2e7d32, #1b5e20);
            color: #fff;
        }

        .btn-vitals.hidden {
            display: none;
        }

        .popup {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(20, 35, 22, 0.48);
            align-items: center;
            justify-content: center;
            padding: 14px;
        }

        .popup.show { display: flex; }

        .popup-card {
            background: #fff;
            border-radius: 14px;
            width: 100%;
            max-width: 360px;
            padding: 18px;
            box-shadow: 0 20px 46px rgba(0, 0, 0, 0.25);
            text-align: center;
        }

        .popup-title {
            font-size: 20px;
            font-weight: 800;
            color: var(--green-dark);
            margin-bottom: 8px;
        }

        .popup-text {
            font-size: 14px;
            color: var(--text-mid);
            margin-bottom: 14px;
        }

        .popup-actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
        }

        .btn-close {
            border: none;
            border-radius: 9px;
            padding: 10px;
            background: #efefef;
            color: #444;
            font-size: 13px;
            font-weight: 800;
            cursor: pointer;
        }

        @media (max-width: 740px) {
            .row { grid-template-columns: 1fr; }
            .actions { grid-template-columns: 1fr; }
            .popup-actions { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<nav class="navbar">
    <a class="brand" href="<?= htmlspecialchars(app_base_url()) ?>/"><span style="display:flex;align-items:center;gap:14px;line-height:1;"><img src="<?= htmlspecialchars(app_base_url()) ?>/public/assets/SRMS_TRUST_LOGO.png" alt="SRMST Trust Logo" style="height:48px;width:auto;display:block;object-fit:contain;filter:drop-shadow(0 1px 3px rgba(0,0,0,.35));"><span style="font-size:22px;font-weight:800;letter-spacing:1px;color:#fff;text-shadow:0 1px 2px rgba(0,0,0,.35);white-space:nowrap;">SRMS EHEALTH</span></span></a>
    <div style="display:flex;gap:10px;align-items:center;">
        <a class="back-link" href="patient_select.php">Back</a>
        <a class="back-link" href="<?= htmlspecialchars(app_base_url()) ?>/about.php">ABOUT</a>
        <a class="back-link" href="<?= htmlspecialchars(app_base_url()) ?>/contact.php">CONTACT</a>
    </div>
</nav>

<main class="page">
    <div class="title">
        <h1>Existing Patient Search</h1>
        <p>Only patients with at least one completed diagnosis are shown here as old/revisited patients.</p>
    </div>

    <section class="search-box">
        <div class="row">
            <input id="patientIdInput" class="input" type="text" placeholder="Patient ID (e.g. P202603001)" maxlength="20">
            <input id="phoneInput" class="input" type="text" placeholder="Mobile Number (10 digits)" maxlength="15">
            <button id="searchBtn" class="btn-search" type="button">Search</button>
        </div>
        <p class="hint">Search by Patient ID or 10-digit mobile. Mobile search can show multiple diagnosed patients.</p>
    </section>

    <div id="message" class="msg"></div>
    <section id="results" class="results"></section>
</main>

<div id="successPopup" class="popup">
    <div class="popup-card">
        <div class="popup-title">Patient Marked as Revisited</div>
        <div class="popup-text">Patient status is updated to old patient. New vitals can be entered now.</div>
        <div class="popup-actions">
            <a id="popupVitalsBtn" class="btn btn-vitals" href="#">Enter Vitals</a>
            <button id="popupCloseBtn" class="btn-close" type="button">Close</button>
        </div>
    </div>
</div>

<script>
const resultsEl = document.getElementById('results');
const msgEl = document.getElementById('message');
const searchBtn = document.getElementById('searchBtn');
const popup = document.getElementById('successPopup');
const popupVitalsBtn = document.getElementById('popupVitalsBtn');

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (m) => (
        {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[m]
    ));
}

function showMessage(text, type = 'info') {
    msgEl.className = `msg ${type}`;
    msgEl.textContent = text;
}

function clearMessage() {
    msgEl.className = 'msg';
    msgEl.textContent = '';
}

function badgeClass(flag) {
    return flag === 'O' ? 'badge-old' : 'badge-new';
}

function badgeText(flag) {
    return flag === 'O' ? 'Old Patient (O)' : 'New Patient (N)';
}

function renderPatients(patients) {
    if (!patients.length) {
        resultsEl.innerHTML = '';
        showMessage('No diagnosed old patient found for this search.', 'error');
        return;
    }

    clearMessage();
    resultsEl.innerHTML = patients.map((p) => {
        const isPending = String(p.diagnosis_status) === 'pending';
        const needsVitals = Number(p.vitals_recorded) === 0;
        const vitalsBtnClass = needsVitals ? 'btn btn-vitals' : 'btn btn-vitals hidden';

        return `
        <article class="card" data-pid="${escapeHtml(p.patient_id)}">
            <div class="card-head">
                <div>
                    <div class="name">${escapeHtml(p.full_name)}</div>
                    <span class="pid">${escapeHtml(p.patient_id)}</span>
                </div>
            </div>

            <div class="badges">
                <span class="badge ${badgeClass(p.patient_flag)} js-flag">${badgeText(p.patient_flag)}</span>
                <span class="badge ${isPending ? 'badge-pending' : 'badge-completed'} js-diagnosis">
                    Diagnosis: ${isPending ? 'Pending' : 'Completed'}
                </span>
                <span class="badge ${needsVitals ? 'badge-vitals-needed' : 'badge-vitals-done'} js-vitals">
                    ${needsVitals ? 'Vitals: Required' : 'Vitals: Recorded'}
                </span>
            </div>

            <div class="meta">
                <div>Age: <span>${escapeHtml(p.age)}</span></div>
                <div>Gender: <span>${escapeHtml(p.gender)}</span></div>
                <div style="grid-column:1/-1;">Mobile: <span>${escapeHtml(p.mobile_no)}</span></div>
            </div>

            <div class="actions">
                <button class="btn btn-mark js-mark-btn" type="button" data-pid="${escapeHtml(p.patient_id)}">
                    Mark as Revisited
                </button>
                <a class="${vitalsBtnClass} js-vitals-btn" href="../patient/patient_vitals.php?pid=${encodeURIComponent(p.patient_id)}">
                    Enter Vitals
                </a>
            </div>
        </article>`;
    }).join('');
}

async function searchPatients() {
    const patientId = document.getElementById('patientIdInput').value.trim().toUpperCase();
    const phoneRaw = document.getElementById('phoneInput').value.trim();
    const phone = phoneRaw.replace(/\D/g, '');

    if (!patientId && !phone) {
        showMessage('Enter Patient ID or 10-digit mobile number.', 'error');
        return;
    }

    searchBtn.disabled = true;
    searchBtn.textContent = 'Searching...';
    resultsEl.innerHTML = '';
    clearMessage();

    const url = new URL('patient_search_ajax.php', window.location.href);
    url.searchParams.set('old_only', '1');
    if (patientId) url.searchParams.set('patient_id', patientId);
    if (phone) url.searchParams.set('phone', phone);

    try {
        const res = await fetch(url.toString());
        const data = await res.json();
        if (!data.success) {
            showMessage(data.error || 'Search failed.', 'error');
            return;
        }
        renderPatients(Array.isArray(data.patients) ? data.patients : []);
    } catch {
        showMessage('Server error while searching patients.', 'error');
    } finally {
        searchBtn.disabled = false;
        searchBtn.textContent = 'Search';
    }
}

async function markAsRevisited(pid, cardEl, btnEl) {
    btnEl.disabled = true;
    btnEl.textContent = 'Updating...';
    clearMessage();

    try {
        const body = new URLSearchParams({
            action: 'mark_revisited',
            patient_id: pid
        });

        const res = await fetch('patient_search_ajax.php', {
            method: 'POST',
            body
        });
        const data = await res.json();

        if (!data.success) {
            showMessage(data.error || 'Could not mark patient as revisited.', 'error');
            btnEl.disabled = false;
            btnEl.textContent = 'Mark as Revisited';
            return;
        }

        const flagEl = cardEl.querySelector('.js-flag');
        const diagnosisEl = cardEl.querySelector('.js-diagnosis');
        const vitalsEl = cardEl.querySelector('.js-vitals');
        const vitalsBtn = cardEl.querySelector('.js-vitals-btn');

        flagEl.className = 'badge badge-old js-flag';
        flagEl.textContent = 'Old Patient (O)';

        diagnosisEl.className = 'badge badge-pending js-diagnosis';
        diagnosisEl.textContent = 'Diagnosis: Pending';

        vitalsEl.className = 'badge badge-vitals-needed js-vitals';
        vitalsEl.textContent = 'Vitals: Required';

        vitalsBtn.classList.remove('hidden');
        vitalsBtn.href = `../patient/patient_vitals.php?pid=${encodeURIComponent(pid)}`;

        btnEl.disabled = false;
        btnEl.textContent = 'Mark as Revisited';

        popupVitalsBtn.href = `../patient/patient_vitals.php?pid=${encodeURIComponent(pid)}`;
        popup.classList.add('show');
    } catch {
        showMessage('Server error while updating revisit status.', 'error');
        btnEl.disabled = false;
        btnEl.textContent = 'Mark as Revisited';
    }
}

searchBtn.addEventListener('click', searchPatients);
document.getElementById('patientIdInput').addEventListener('keydown', (e) => {
    if (e.key === 'Enter') searchPatients();
});
document.getElementById('phoneInput').addEventListener('keydown', (e) => {
    if (e.key === 'Enter') searchPatients();
});

resultsEl.addEventListener('click', (e) => {
    const btn = e.target.closest('.js-mark-btn');
    if (!btn) return;
    const pid = btn.getAttribute('data-pid');
    const card = btn.closest('.card');
    if (!pid || !card) return;
    markAsRevisited(pid, card, btn);
});

document.getElementById('popupCloseBtn').addEventListener('click', () => {
    popup.classList.remove('show');
});

popup.addEventListener('click', (e) => {
    if (e.target === popup) popup.classList.remove('show');
});
</script>
</body>
</html>
