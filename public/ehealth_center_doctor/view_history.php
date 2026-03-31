<?php
require_once __DIR__ . '/../../config/config.php';
start_session();

if (!is_logged_in()) {
    header('Location: ../index.php');
    exit;
}

$role = (string)get_session_role();
if (!in_array($role, ['doctor', 'telestudio_doctor'], true)) {
    header('Location: ../index.php');
    exit;
}

$prefillPatientId = strtoupper(trim((string)($_GET['patientId'] ?? '')));
if (!preg_match('/^[A-Z0-9]{3,20}$/', $prefillPatientId)) {
    $prefillPatientId = '';
}

$activeTab = (string)($_GET['tab'] ?? 'ehealth');
if (!in_array($activeTab, ['ehealth', 'tele'], true)) {
    $activeTab = 'ehealth';
}

$backUrl = ($role === 'doctor')
    ? 'doctor_dashboard.php'
    : '../telestudio_doctor/telestudio_dashboard.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient History - eHealth</title>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;600;700;800&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root {
            --g-dark: #1b5e20;
            --g-mid: #2e7d32;
            --g-light: #43a047;
            --g-pale: #e8f5e9;
            --g-mist: #f1f8f2;
            --g-border: #c8e0ca;
            --teal: #00796b;
            --teal-pale: #e0f2f1;
            --text-dark: #0d1f12;
            --text-mid: #3a4f3c;
            --text-soft: #6b7f6d;
            --danger: #c62828;
            --danger-bg: #ffebee;
            --white: #fff;
        }

        body {
            font-family: 'Sora', sans-serif;
            background: #a8e6b0;
            min-height: 100vh;
            color: var(--text-dark);
            background-image:
                radial-gradient(ellipse 70% 50% at 5% 0%, rgba(27,94,32,0.14) 0%, transparent 55%),
                radial-gradient(ellipse 50% 40% at 95% 100%, rgba(67,160,71,0.1) 0%, transparent 55%);
        }

        .navbar {
            background: var(--g-dark);
            height: 58px;
            padding: 0 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 2px 12px rgba(0,0,0,.22);
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .brand { color: #fff; font-size: 18px; font-weight: 800; letter-spacing: 1.5px; text-decoration: none; }
        .back {
            color: #fff;
            text-decoration: none;
            font-size: 13px;
            font-weight: 700;
            background: rgba(255,255,255,.15);
            border-radius: 20px;
            padding: 7px 14px;
        }

        .page { max-width: 1180px; margin: 0 auto; padding: 20px 16px 26px; }

        .title-box {
            background: var(--white);
            border-radius: 14px;
            border: 1px solid var(--g-border);
            box-shadow: 0 3px 14px rgba(0,0,0,.08);
            padding: 18px 20px;
            margin-bottom: 14px;
        }
        .title-box h1 { font-size: 28px; font-weight: 800; margin-bottom: 6px; }
        .title-box p { color: var(--text-mid); font-size: 14px; font-weight: 600; }

        .search-row {
            margin-top: 14px;
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 10px;
        }
        .search-input {
            border: 2px solid var(--g-border);
            border-radius: 10px;
            padding: 11px 14px;
            font-size: 14px;
            font-family: inherit;
            font-weight: 700;
            letter-spacing: .5px;
        }
        .search-input:focus {
            outline: none;
            border-color: var(--g-mid);
            box-shadow: 0 0 0 3px rgba(46,125,50,.1);
        }
        .btn-load {
            border: none;
            border-radius: 10px;
            background: linear-gradient(135deg, var(--g-mid), var(--g-light));
            color: #fff;
            font-size: 14px;
            font-weight: 800;
            padding: 11px 18px;
            cursor: pointer;
        }

        .msg {
            display: none;
            margin-bottom: 14px;
            border-radius: 10px;
            padding: 11px 14px;
            font-size: 13px;
            font-weight: 700;
        }
        .msg.show-error {
            display: block;
            background: var(--danger-bg);
            color: var(--danger);
            border: 1px solid #ffcdd2;
        }
        .msg.show-info {
            display: block;
            background: #e3f2fd;
            color: #0d47a1;
            border: 1px solid #bbdefb;
        }

        .patient-summary {
            display: none;
            background: var(--white);
            border-radius: 14px;
            border: 1px solid var(--g-border);
            box-shadow: 0 3px 14px rgba(0,0,0,.08);
            padding: 16px 20px;
            margin-bottom: 14px;
            align-items: center;
            gap: 14px;
        }
        .patient-summary.show { display: flex; }
        .p-photo {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--g-pale);
            flex-shrink: 0;
        }
        .p-photo.ph {
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            background: var(--g-pale);
            color: var(--g-mid);
        }
        .p-name { font-size: 20px; font-weight: 800; margin-bottom: 3px; }
        .p-id {
            display: inline-block;
            font-family: 'JetBrains Mono', monospace;
            font-size: 12px;
            font-weight: 700;
            background: var(--g-pale);
            color: var(--g-mid);
            border-radius: 14px;
            padding: 3px 9px;
            margin-bottom: 6px;
        }
        .p-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            font-size: 13px;
            color: var(--text-soft);
        }
        .p-meta b { color: var(--text-mid); }
        .p-address { margin-top: 4px; font-size: 13px; color: var(--text-mid); }

        .tabs-wrap {
            display: none;
            background: var(--white);
            border-radius: 14px;
            border: 1px solid var(--g-border);
            box-shadow: 0 3px 14px rgba(0,0,0,.08);
            padding: 14px;
        }
        .tabs-wrap.show { display: block; }
        .tabs {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            margin-bottom: 10px;
        }
        .tab-btn {
            border: 2px solid var(--g-border);
            border-radius: 11px;
            background: #fff;
            color: var(--text-mid);
            padding: 10px 12px;
            font-size: 13px;
            font-weight: 800;
            cursor: pointer;
        }
        .tab-btn.active {
            border-color: var(--g-mid);
            background: var(--g-pale);
            color: var(--g-dark);
        }

        .history-scroll {
            border: 1px solid var(--g-border);
            border-radius: 12px;
            background: #f8fdf9;
            padding: 10px;
            max-height: 56vh;
            overflow-y: auto;
            display: grid;
            gap: 10px;
        }

        .history-card {
            background: #fff;
            border: 1px solid var(--g-border);
            border-left: 5px solid var(--g-mid);
            border-radius: 12px;
            padding: 12px 12px;
        }
        .history-card.tele { border-left-color: var(--teal); }
        .h-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 10px;
            margin-bottom: 8px;
            flex-wrap: wrap;
        }
        .h-title { font-size: 14px; font-weight: 800; color: var(--text-dark); }
        .h-date { font-size: 11px; color: var(--text-soft); font-family: 'JetBrains Mono', monospace; }
        .h-rows { display: grid; gap: 7px; }
        .h-row { font-size: 13px; color: var(--text-mid); line-height: 1.45; }
        .h-row b { color: var(--text-dark); }

        .vitals-chip {
            margin-top: 7px;
            display: inline-flex;
            flex-wrap: wrap;
            gap: 8px;
            font-size: 11px;
            font-weight: 700;
            background: #eef7ef;
            border: 1px solid var(--g-border);
            border-radius: 20px;
            padding: 5px 10px;
            color: var(--g-dark);
        }

        .pill {
            display: inline-block;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: .3px;
            padding: 3px 8px;
            background: var(--teal-pale);
            color: var(--teal);
        }

        .empty {
            text-align: center;
            color: var(--text-soft);
            font-size: 13px;
            padding: 16px 12px;
        }

        @media (max-width: 700px) {
            .search-row { grid-template-columns: 1fr; }
            .tabs { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<nav class="navbar">
    <a href="<?= htmlspecialchars(app_base_url()) ?>/" class="brand"><span style="display:flex;align-items:center;gap:14px;line-height:1;"><img src="<?= htmlspecialchars(app_base_url()) ?>/public/assets/SRMS_TRUST_LOGO.png" alt="SRMST Trust Logo" style="height:48px;width:auto;display:block;object-fit:contain;filter:drop-shadow(0 1px 3px rgba(0,0,0,.35));"><span style="font-size:22px;font-weight:800;letter-spacing:1px;color:#fff;text-shadow:0 1px 2px rgba(0,0,0,.35);white-space:nowrap;">SRMS EHEALTH</span></span></a>
    <div style="display:flex;gap:12px;align-items:center;">
        <a href="<?= htmlspecialchars($backUrl) ?>" class="back">Back</a>
        <a href="<?= htmlspecialchars(app_base_url()) ?>/about.php" class="back">ABOUT</a>
        <a href="<?= htmlspecialchars(app_base_url()) ?>/contact.php" class="back">CONTACT</a>
    </div>
</nav>

<main class="page">
    <section class="title-box">
        <h1>Patient Diagnosis History</h1>
        <p>For old patients, choose a history stream and view full diagnosis cards with vitals used during diagnosis.</p>
        <div class="search-row">
            <input id="patientIdInput" class="search-input" type="text" placeholder="Enter Patient ID (e.g. P202603001)" maxlength="20" value="<?= htmlspecialchars($prefillPatientId) ?>">
            <button id="loadBtn" class="btn-load" type="button">Load History</button>
        </div>
    </section>

    <div id="msgBox" class="msg"></div>

    <section id="patientSummary" class="patient-summary">
        <div id="photoBox" class="p-photo ph">P</div>
        <div style="flex:1;">
            <div id="pName" class="p-name">-</div>
            <div id="pId" class="p-id">-</div>
            <div id="pMeta" class="p-meta"></div>
            <div id="pAddress" class="p-address"></div>
        </div>
    </section>

    <section id="tabsWrap" class="tabs-wrap">
        <div class="tabs">
            <button class="tab-btn" data-tab="ehealth">eHealth Center Diagnosis History</button>
            <button class="tab-btn" data-tab="tele">Teleconsultation History</button>
        </div>
        <div id="historyScroll" class="history-scroll"></div>
    </section>
</main>

<script>
const API_URL = '../common/patient_history_api.php';
const DEFAULT_TAB = <?= json_encode($activeTab) ?>;
let historyData = null;
let activeTab = DEFAULT_TAB;

function esc(v) {
    return String(v ?? '').replace(/[&<>"']/g, (m) => (
        {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]
    ));
}

function showMsg(text, type = 'info') {
    const box = document.getElementById('msgBox');
    box.className = `msg show-${type}`;
    box.textContent = text;
}

function clearMsg() {
    const box = document.getElementById('msgBox');
    box.className = 'msg';
    box.textContent = '';
}

function fmtDate(v) {
    if (!v) return '-';
    const d = new Date(String(v).replace(' ', 'T'));
    if (Number.isNaN(d.getTime())) return String(v);
    return d.toLocaleString('en-IN', {
        day: '2-digit', month: 'short', year: 'numeric',
        hour: '2-digit', minute: '2-digit'
    });
}

function vitalsLine(r) {
    const bp = (r.bp_systolic && r.bp_diastolic) ? `${r.bp_systolic}/${r.bp_diastolic}` : '-';
    const hr = r.heart_rate ?? '-';
    const t = r.temperature ?? '-';
    const spo2 = r.spo2 ?? '-';
    const rr = r.resp_rate ?? '-';
    const bmi = r.bmi ?? '-';
    return `BP ${bp} | HR ${hr} | Temp ${t} | SpO2 ${spo2} | RR ${rr} | BMI ${bmi}`;
}

function renderPatient(patient) {
    const summary = document.getElementById('patientSummary');
    summary.classList.add('show');

    const photoBox = document.getElementById('photoBox');
    if (patient.photo_url) {
        photoBox.className = 'p-photo';
        photoBox.innerHTML = `<img src="${esc(patient.photo_url)}" alt="patient" class="p-photo">`;
    } else {
        photoBox.className = 'p-photo ph';
        photoBox.textContent = 'P';
    }

    document.getElementById('pName').textContent = patient.full_name || '-';
    document.getElementById('pId').textContent = patient.patient_id || '-';
    document.getElementById('pMeta').innerHTML = `
        <span><b>Age:</b> ${esc(patient.age ?? '-')}</span>
        <span><b>Gender:</b> ${esc(patient.gender ?? '-')}</span>
        <span><b>Mobile:</b> ${esc(patient.mobile_no ?? '-')}</span>
    `;
    document.getElementById('pAddress').innerHTML = `<b>Address:</b> ${esc(patient.address_full || '-')}`;
}

function formatMeds(meds) {
    if (!Array.isArray(meds) || meds.length === 0) return 'None';
    return meds.map((m) => {
        const timing = Array.isArray(m.timing_list) ? m.timing_list.filter(Boolean).join(', ') : '';
        const parts = [
            m.medicine_name || '-',
            m.dosage ? `(${m.dosage})` : '',
            timing ? `- ${timing}` : '',
            m.meal_instruction ? `- ${m.meal_instruction}` : '',
            m.duration ? `- ${m.duration}` : '',
        ].filter(Boolean);
        return parts.join(' ');
    }).join('<br>');
}

function renderEhealthCards(items) {
    if (!items.length) {
        return `<div class="empty">No eHealth center diagnosis history found.</div>`;
    }
    return items.map((r) => `
        <article class="history-card">
            <div class="h-head">
                <div>
                    <div class="h-title">In-Person Diagnosis #${esc(r.id)}</div>
                    <span class="pill">eHealth Center</span>
                </div>
                <div class="h-date">${esc(fmtDate(r.diagnosis_date))}</div>
            </div>
            <div class="h-rows">
                <div class="h-row"><b>Doctor:</b> ${esc(r.doctor_name || '-')}${r.doctor_id ? ` (${esc(r.doctor_id)})` : ''}</div>
                <div class="h-row"><b>Chief Complaint:</b> ${esc(r.chief_complaint || '-')}</div>
                <div class="h-row"><b>Clinical Notes:</b> ${esc(r.clinical_notes || '-')}</div>
                <div class="h-row"><b>Provisional Diagnosis:</b> ${esc(r.provisional_diagnosis || '-')}</div>
                <div class="h-row"><b>Investigation:</b> ${esc(r.investigation_prescribed || '-')}</div>
                <div class="h-row"><b>Review After:</b> ${esc(r.review_after || '-')}</div>
                <div class="h-row"><b>Remarks:</b> ${esc(r.remarks || '-')}</div>
                <div class="h-row"><b>Medicines:</b><br>${formatMeds(r.medicines || [])}</div>
                <div class="vitals-chip">
                    <span><b>Vitals Used:</b> ${esc(vitalsLine(r))}</span>
                    <span>|</span>
                    <span>Recorded: ${esc(fmtDate(r.vitals_at))}</span>
                </div>
            </div>
        </article>
    `).join('');
}

function parseTeleMeds(mJson) {
    if (!mJson) return [];
    try {
        const arr = JSON.parse(mJson);
        return Array.isArray(arr) ? arr : [];
    } catch {
        return [];
    }
}

function formatTeleMeds(arr) {
    if (!arr.length) return 'None';
    return arr.map((m) => {
        const timing = Array.isArray(m.timing) ? m.timing.filter(Boolean).join(', ') : '';
        const parts = [
            m.name || '-',
            m.dosage ? `(${m.dosage})` : '',
            timing ? `- ${timing}` : '',
            m.mealInstruction ? `- ${m.mealInstruction}` : '',
            m.duration ? `- ${m.duration}` : '',
        ].filter(Boolean);
        return parts.join(' ');
    }).join('<br>');
}

function renderTeleCards(items) {
    if (!items.length) {
        return `<div class="empty">No teleconsultation diagnosis history found.</div>`;
    }
    return items.map((r) => {
        const meds = parseTeleMeds(r.medicines_json);
        const chiefVal = r.chief_complaint || r.consultation_notes || '-';
        const clinicalVal = r.clinical_notes || r.consultation_notes || '-';
        const consultVal = r.consultation_notes || r.clinical_notes || r.chief_complaint || '-';
        return `
        <article class="history-card tele">
            <div class="h-head">
                <div>
                    <div class="h-title">Teleconsultation #${esc(r.id)}</div>
                    <span class="pill">TeleStudio</span>
                </div>
                <div class="h-date">${esc(fmtDate(r.diagnosis_date))}</div>
            </div>
            <div class="h-rows">
                <div class="h-row"><b>eHealth Doctor:</b> ${esc(r.ehealth_doctor_name || '-')} (${esc(r.ehealth_doctor_id || '-')})</div>
                <div class="h-row"><b>TeleStudio Doctor:</b> ${esc(r.telestudio_doctor_name || '-')} (${esc(r.telestudio_doctor_id || '-')})</div>
                <div class="h-row"><b>Teleconsult Reason:</b> ${esc(r.teleconsult_reason || '-')}</div>
                <div class="h-row"><b>Chief Complaint:</b> ${esc(chiefVal)}</div>
                <div class="h-row"><b>Clinical Notes:</b> ${esc(clinicalVal)}</div>
                <div class="h-row"><b>Consultation Notes:</b> ${esc(consultVal)}</div>
                <div class="h-row"><b>Primary Diagnosis:</b> ${esc(r.primary_diagnosis || '-')}</div>
                <div class="h-row"><b>Investigation:</b> ${esc(r.investigation || '-')}</div>
                <div class="h-row"><b>Review After:</b> ${esc(r.review_after || '-')}</div>
                <div class="h-row"><b>Remarks:</b> ${esc(r.remarks || '-')}</div>
                <div class="h-row"><b>Medicines:</b><br>${formatTeleMeds(meds)}</div>
                <div class="vitals-chip">
                    <span><b>Vitals Used:</b> ${esc(vitalsLine(r))}</span>
                    <span>|</span>
                    <span>Recorded: ${esc(fmtDate(r.vitals_at))}</span>
                </div>
            </div>
        </article>`;
    }).join('');
}

function setActiveTab(tab) {
    activeTab = tab;
    document.querySelectorAll('.tab-btn').forEach((btn) => {
        btn.classList.toggle('active', btn.dataset.tab === tab);
    });
    renderActiveTab();
}

function renderActiveTab() {
    if (!historyData) return;
    const scroll = document.getElementById('historyScroll');
    if (activeTab === 'tele') {
        scroll.innerHTML = renderTeleCards(historyData.tele_history || []);
    } else {
        scroll.innerHTML = renderEhealthCards(historyData.ehealth_history || []);
    }
}

async function loadHistory() {
    const pid = document.getElementById('patientIdInput').value.trim().toUpperCase();
    if (!pid) {
        showMsg('Please enter patient ID first.', 'error');
        return;
    }

    const btn = document.getElementById('loadBtn');
    btn.disabled = true;
    btn.textContent = 'Loading...';
    clearMsg();

    try {
        const res = await fetch(`${API_URL}?patientId=${encodeURIComponent(pid)}`);
        const data = await res.json();
        if (!data.success) {
            showMsg(data.error || 'Could not load history.', 'error');
            document.getElementById('tabsWrap').classList.remove('show');
            return;
        }

        historyData = data;
        renderPatient(data.patient);

        const tabsWrap = document.getElementById('tabsWrap');
        if (!data.is_old) {
            tabsWrap.classList.remove('show');
            showMsg('This patient is not old yet (no diagnosis history found).', 'info');
            return;
        }

        tabsWrap.classList.add('show');
        clearMsg();
        setActiveTab(activeTab);
    } catch {
        showMsg('Server error while loading history.', 'error');
    } finally {
        btn.disabled = false;
        btn.textContent = 'Load History';
    }
}

document.getElementById('loadBtn').addEventListener('click', loadHistory);
document.getElementById('patientIdInput').addEventListener('keydown', (e) => {
    if (e.key === 'Enter') loadHistory();
});
document.querySelectorAll('.tab-btn').forEach((btn) => {
    btn.addEventListener('click', () => setActiveTab(btn.dataset.tab));
});

window.addEventListener('DOMContentLoaded', () => {
    setActiveTab(DEFAULT_TAB);
    const prefill = document.getElementById('patientIdInput').value.trim();
    if (prefill) loadHistory();
});
</script>
</body>
</html>
