<?php
require_once __DIR__ . '/../../config/config.php';
require_role('executive', 'executive_login.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Select Patient - eHealth</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800&display=swap');
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root {
            --green-dark: #2e7d32;
            --green-mid: #388e3c;
            --green-light: #a5d6a7;
            --green-pale: #e8f5e9;
            --text-dark: #1b3a1e;
            --text-mid: #3d6b41;
            --text-muted: #6b9a6f;
        }

        body {
            font-family: 'Nunito', 'Segoe UI', sans-serif;
            background: linear-gradient(160deg, #b9dfbb 0%, #d4edd6 50%, #c5e8c7 100%);
            min-height: 100vh;
            color: var(--text-dark);
        }

        .navbar {
            background: var(--green-dark);
            height: 58px;
            padding: 0 40px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 2px 12px rgba(0, 0, 0, .18);
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .navbar-brand {
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
        }

        .brand-icon {
            width: 38px;
            height: 38px;
            background: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .brand-name {
            font-size: 20px;
            font-weight: 800;
            letter-spacing: 1.5px;
            color: white;
        }

        .back-btn {
            display: flex;
            align-items: center;
            gap: 8px;
            color: white;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            opacity: .9;
            background: rgba(255, 255, 255, .15);
            padding: 8px 16px;
            border-radius: 20px;
            transition: background .2s;
        }

        .back-btn:hover {
            background: rgba(255, 255, 255, .25);
            opacity: 1;
        }

        .page {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: calc(100vh - 58px);
            padding: 40px 20px;
        }

        .hero {
            text-align: center;
            margin-bottom: 48px;
        }

        .hero h1 {
            font-size: clamp(24px, 4vw, 36px);
            font-weight: 800;
            color: var(--text-dark);
            margin-bottom: 10px;
        }

        .hero p {
            font-size: 15px;
            color: var(--text-mid);
            font-weight: 500;
        }

        .cards {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 28px;
            max-width: 760px;
            width: 100%;
        }

        .choice-card {
            background: white;
            border-radius: 24px;
            padding: 44px 36px;
            box-shadow: 0 4px 24px rgba(46, 125, 50, .13);
            text-align: center;
            text-decoration: none;
            color: inherit;
            display: flex;
            flex-direction: column;
            align-items: center;
            border: 3px solid transparent;
            transition: transform .25s, box-shadow .25s, border-color .25s;
            cursor: pointer;
        }

        .choice-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 16px 40px rgba(46, 125, 50, .22);
            border-color: var(--green-light);
        }

        .choice-card.new-patient { background: linear-gradient(160deg, #ffffff 0%, #f0faf1 100%); }
        .choice-card.old-patient { background: linear-gradient(160deg, #ffffff 0%, #f0f6ff 100%); }
        .choice-card.old-patient:hover {
            border-color: #90caf9;
            box-shadow: 0 16px 40px rgba(33, 150, 243, .15);
        }

        .icon-wrap {
            width: 90px;
            height: 90px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 42px;
            margin-bottom: 22px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, .12);
            transition: transform .25s;
        }

        .choice-card:hover .icon-wrap { transform: scale(1.1) rotate(-5deg); }
        .new-patient .icon-wrap { background: linear-gradient(135deg, var(--green-mid), var(--green-dark)); }
        .old-patient .icon-wrap { background: linear-gradient(135deg, #42a5f5, #1565c0); }

        .choice-card h2 {
            font-size: 22px;
            font-weight: 800;
            color: var(--text-dark);
            margin-bottom: 12px;
        }

        .choice-card p {
            font-size: 14px;
            color: var(--text-muted);
            line-height: 1.65;
        }

        .choice-badge {
            display: inline-block;
            margin-top: 20px;
            padding: 6px 18px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 700;
        }

        .new-patient .choice-badge { background: var(--green-pale); color: var(--green-dark); }
        .old-patient .choice-badge { background: #e3f2fd; color: #1565c0; }

        @media (max-width: 580px) {
            .cards { grid-template-columns: 1fr; }
            .navbar { padding: 0 16px; }
        }
    </style>
</head>
<body>
<nav class="navbar">
    <a href="<?= htmlspecialchars(app_base_url()) ?>/" class="navbar-brand">
        <span style="display:flex;align-items:center;gap:14px;line-height:1;"><img src="<?= htmlspecialchars(app_base_url()) ?>/public/assets/SRMS_TRUST_LOGO.png" alt="SRMST Trust Logo" style="height:48px;width:auto;display:block;object-fit:contain;filter:drop-shadow(0 1px 3px rgba(0,0,0,.35));"><span style="font-size:22px;font-weight:800;letter-spacing:1px;color:#fff;text-shadow:0 1px 2px rgba(0,0,0,.35);white-space:nowrap;">SRMS EHEALTH</span></span>
    </a>
    <div style="display:flex;gap:12px;align-items:center;">
        <a href="executive_dashboard.php" class="back-btn">Back to Dashboard</a>
        <a href="<?= htmlspecialchars(app_base_url()) ?>/about.php" class="back-btn">ABOUT</a>
        <a href="<?= htmlspecialchars(app_base_url()) ?>/contact.php" class="back-btn">CONTACT</a>
    </div>
</nav>

<div class="page">
    <div class="hero">
        <h1>Register Patient</h1>
        <p>Is this a new patient or an existing patient visiting again?</p>
    </div>

    <div class="cards">
        <a href="../patient/patient_registration.php" class="choice-card new-patient">
            <div class="icon-wrap">N</div>
            <h2>New Patient</h2>
            <p>Register a first-time visitor into the eHealth system with full demographic and contact details.</p>
            <span class="choice-badge">Register Now</span>
        </a>

        <a href="existing_patient_search.php" class="choice-card old-patient">
            <div class="icon-wrap">O</div>
            <h2>Existing Patient</h2>
            <p>Search by patient ID or mobile number, mark as revisited, and continue to fresh vitals entry.</p>
            <span class="choice-badge">Search Patient</span>
        </a>
    </div>
</div>
</body>
</html>
