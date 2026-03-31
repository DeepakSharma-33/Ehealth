<?php
require_once __DIR__ . '/config/config.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>About - SRMS EHEALTH</title>
    <link rel="stylesheet" href="styles.css">
    <script src="script.js" defer></script>
    <style>
        .page-hero {
            text-align: center;
            color: white;
            margin-bottom: 3rem;
        }
        .page-hero h1 {
            font-size: 2.8rem;
            margin-bottom: 0.8rem;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.3);
        }
        .page-hero p {
            font-size: 1.15rem;
            color: rgba(255,255,255,0.9);
            max-width: 840px;
            margin: 0 auto;
        }
        .section-title {
            color: white;
            text-align: center;
            font-size: 2.1rem;
            margin: 3rem 0 2rem;
            text-shadow: 1px 1px 3px rgba(0,0,0,0.25);
        }
        .feature-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 1.5rem;
        }
        .feature-card {
            background: rgba(255,255,255,0.95);
            border-radius: 16px;
            padding: 1.5rem 1.4rem;
            box-shadow: 0 12px 30px rgba(0,0,0,0.12);
        }
        .feature-card h3 {
            color: #134d36;
            font-size: 1.15rem;
            margin-bottom: 0.6rem;
        }
        .feature-card p {
            color: #4b5563;
            font-size: 0.98rem;
            line-height: 1.55;
        }
        .role-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1.2rem;
        }
        .role-chip {
            background: rgba(255,255,255,0.14);
            border: 1px solid rgba(255,255,255,0.25);
            color: white;
            padding: 1rem 1.1rem;
            border-radius: 14px;
            backdrop-filter: blur(8px);
            text-align: center;
            font-weight: 600;
        }
        .role-chip span {
            display: block;
            font-size: 0.9rem;
            font-weight: 400;
            color: rgba(255,255,255,0.85);
            margin-top: 0.35rem;
        }
        @media (max-width: 600px) {
            .page-hero h1 { font-size: 2.2rem; }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="nav-container">
            <a href="<?= htmlspecialchars(app_base_url()) ?>/" class="logo">
                <span style="display:flex;align-items:center;gap:14px;line-height:1;">
                    <img src="<?= htmlspecialchars(app_base_url()) ?>/public/assets/SRMS_TRUST_LOGO.png" alt="SRMST Trust Logo" style="height:48px;width:auto;display:block;object-fit:contain;filter:drop-shadow(0 1px 3px rgba(0,0,0,.35));">
                    <span style="font-size:22px;font-weight:800;letter-spacing:1px;color:#fff;text-shadow:0 1px 2px rgba(0,0,0,.35);white-space:nowrap;">SRMS EHEALTH</span>
                </span>
            </a>
            <nav>
                <ul class="nav-menu">
                    <li><a href="<?= htmlspecialchars(app_base_url()) ?>/index.php">HOME</a></li>
                    <li><a href="<?= htmlspecialchars(app_base_url()) ?>/about.php">ABOUT</a></li>
                    <li><a href="<?= htmlspecialchars(app_base_url()) ?>/contact.php">CONTACT</a></li>
                </ul>
                <button class="mobile-menu-btn">☰</button>
            </nav>
        </div>
    </header>

    <main class="main-container">
        <section class="page-hero">
            <h1>About SRMS EHEALTH</h1>
            <p>
                SRMS EHEALTH is a full telemedicine workflow that connects patients, care teams, and
                administrators on one secure platform. It covers registration, consultation, vitals,
                diagnosis, pharmacy, and reporting to make end-to-end care faster and more reliable.
            </p>
        </section>

        <h2 class="section-title">Features Provided In This Project</h2>
        <section class="feature-grid">
            <div class="feature-card">
                <h3>Patient Registration and ID Login</h3>
                <p>Unique patient IDs, first-time login flow, and secure access to personal health data.</p>
            </div>
            <div class="feature-card">
                <h3>Role-Based Access</h3>
                <p>Separate portals and permissions for Admins, Executives, Doctors, Nurses, Technicians, and Pharmacy staff.</p>
            </div>
            <div class="feature-card">
                <h3>Teleconsultation Levels</h3>
                <p>Level 1 eHealth Center doctors and Level 2 Telestudio doctors for specialist escalation.</p>
            </div>
            <div class="feature-card">
                <h3>Queue and Waiting Management</h3>
                <p>Patient queues, teleconsult waiting screens, and live availability tracking.</p>
            </div>
            <div class="feature-card">
                <h3>Vitals Capture and History</h3>
                <p>Nursing staff can record vitals and review patient vitals history.</p>
            </div>
            <div class="feature-card">
                <h3>Medical Records and Diagnosis History</h3>
                <p>Structured diagnosis records, history views, and printable patient reports.</p>
            </div>
            <div class="feature-card">
                <h3>Clinical Documentation</h3>
                <p>Diagnosis forms, prescriptions, and consultation notes captured per visit.</p>
            </div>
            <div class="feature-card">
                <h3>Lab and Sample Collection</h3>
                <p>Technician workflows for sample collection and lab processing support.</p>
            </div>
            <div class="feature-card">
                <h3>Pharmacy Dispensing</h3>
                <p>Pharmacy staff provide medicines and manage prescription fulfillment.</p>
            </div>
            <div class="feature-card">
                <h3>Staff Onboarding and Approval</h3>
                <p>Admin registration flows for doctors, executives, nursing, technicians, and pharmacy staff.</p>
            </div>
            <div class="feature-card">
                <h3>Dashboards and Workflows</h3>
                <p>Dedicated dashboards for each role with task-specific tools and navigation.</p>
            </div>
            <div class="feature-card">
                <h3>Security and Session Management</h3>
                <p>Secure login, password updates, and session handling across all portals.</p>
            </div>
        </section>

        <h2 class="section-title">Role Portals</h2>
        <section class="role-grid">
            <div class="role-chip">Admin<span>Platform management and analytics</span></div>
            <div class="role-chip">Executive<span>Patient registration and coordination</span></div>
            <div class="role-chip">Patient<span>Records and consultations</span></div>
            <div class="role-chip">Doctor (Level 1)<span>Primary teleconsultation</span></div>
            <div class="role-chip">Telestudio Doctor<span>Specialist consultation</span></div>
            <div class="role-chip">Nursing Staff<span>Vitals and prep</span></div>
            <div class="role-chip">Technician<span>Lab and sample collection</span></div>
            <div class="role-chip">Pharmacy Staff<span>Medicines and dispensing</span></div>
        </section>
    </main>

    <footer class="footer">
        <p>&copy; 2025 EHEALTH. All rights reserved. | Connecting Healthcare, Empowering Lives</p>
    </footer>
</body>
</html>
