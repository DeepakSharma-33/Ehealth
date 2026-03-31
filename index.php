<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/public/includes/session.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EHEALTH - Telemedicine Platform</title>
    <link rel="stylesheet" href="styles.css">
    <script src="script.js" defer></script>
</head>
<body>
    <!-- Page Loader -->
    <div class="page-loader" aria-hidden="true">
        <div class="loader-brand" role="status" aria-live="polite">
            <img class="loader-logo" src="<?= htmlspecialchars(app_base_url()) ?>/public/assets/SRMS_TRUST_LOGO.png" alt="SRMST Trust Logo">
            <div class="loader-title" id="loaderTitle" data-text="SRMS EHEALTH">SRMS EHEALTH</div>
        </div>
    </div>

    <!-- Header -->
   
    <!-- <?php include '/public/includes/header.php'; ?> -->
<header class="header">
        <div class="nav-container">
            <a href="<?= htmlspecialchars(app_base_url()) ?>/" class="logo">
                <span style="display:flex;align-items:center;gap:14px;line-height:1;"><img src="<?= htmlspecialchars(app_base_url()) ?>/public/assets/SRMS_TRUST_LOGO.png" alt="SRMST Trust Logo" style="height:48px;width:auto;display:block;object-fit:contain;filter:drop-shadow(0 1px 3px rgba(0,0,0,.35));"><span style="font-size:22px;font-weight:800;letter-spacing:1px;color:#fff;text-shadow:0 1px 2px rgba(0,0,0,.35);white-space:nowrap;">SRMS EHEALTH</span></span>
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

    <!-- Main Content -->
    <main class="main-container">
        <section class="welcome-section">
            <h1 class="main-heading">Welcome to SRMS EHEALTH</h1>
            <p class="sub-heading">Your Comprehensive Telemedicine Platform - Connecting Patients, Doctors, and Agents</p>
        </section>

        <section class="user-types">
            <a href="/ehealth/public/patient/patient_login.php" class="user-card" id="patient-card">
                <div class="card-icon">👤</div>
                <h2 class="card-title">Patient</h2>
                <p class="card-description">
                    Access your medical records, schedule appointments, and consult with healthcare professionals from the comfort of your home.
                </p>
                <button class="card-button">Patient Login</button>
            </a>

            <a href="public/executive/executive_login.php" class="user-card" id="agent-card">
                <div class="card-icon">👔</div>
                <h2 class="card-title">EXECUTIVE</h2>
                <p class="card-description">
                    Assist patients with registration, appointment scheduling, and provide support for seamless healthcare access.
                </p>
                <button class="card-button">Agent Login</button>
            </a>

            <a href="public/ehealth_center_doctor/doctor_login.php" class="user-card" id="doctor-card">
                <div class="card-icon">👨‍⚕️</div>
                <h2 class="card-title">Doctor <span class="card-subtitle">Consultation level 1</span></h2>
                <p class="card-description">
                    Provide primary medical consultation, prescribe medicines and diagnostic tests, and escalate complex cases to Telestudio specialists when needed.
                </p>
                <button class="card-button">Doctor Login</button>
            </a>

            <a href="public\telestudio_doctor\telestudio_doctor_login.php" class="user-card" id="telestudio-card">
                <div class="card-icon">👨‍⚕️</div>
                <h2 class="card-title">Telestudio <span class="card-subtitle">Consultation level 2</span></h2>
                <p class="card-description">
                    Conduct video consultations with patients escalated from Level 1. Specialist doctors provide expert diagnosis, prescribe advanced treatments, and manage complex medical cases requiring specialized care.
                </p>
                <button class="card-button">Doctor Login</button>
            </a>

            <a href="/ehealth/public/staff/staff_login.php" class="user-card" id="nursing-card">
                <div class="card-icon">🧑‍⚕️</div>
                <h2 class="card-title">Nursing, Technician &amp; Pharmacy Staff</h2>
                <p class="card-description">
                    Access the staff portal to continue with your department login.
                </p>
                <button class="card-button">Staff Login</button>
            </a>

            <a href="public/admin/admin_login.php" class="user-card" id="admin-card">
                <div class="card-icon">🛡️</div>
                <h2 class="card-title">ADMIN</h2>
                <p class="card-description">
                    Register and manage doctors and executives, monitor system analytics, access detailed patient information, and oversee the overall telemedicine platform operations.
                </p>
                <button class="card-button">Admin Login</button>
            </a>
        </section>

        <section class="features">
            <h2>Why Choose EHEALTH?</h2>
            <div class="features-grid">
                <div class="feature-item">
                    <div class="feature-icon">🏥</div>
                    <h3 class="feature-title">24/7 Healthcare Access</h3>
                    <p class="feature-description">Connect with healthcare professionals anytime, anywhere</p>
                </div>
                <div class="feature-item">
                    <div class="feature-icon">🔒</div>
                    <h3 class="feature-title">Secure & Private</h3>
                    <p class="feature-description">Your medical data is protected with advanced encryption</p>
                </div>
                <div class="feature-item">
                    <div class="feature-icon">📱</div>
                    <h3 class="feature-title">Easy to Use</h3>
                    <p class="feature-description">Intuitive interface designed for all age groups</p>
                </div>
                <div class="feature-item">
                    <div class="feature-icon">💊</div>
                    <h3 class="feature-title">Comprehensive Care</h3>
                    <p class="feature-description">From consultation to prescription management</p>
                </div>
            </div>
        </section>
    </main>

    <!-- Footer -->
    <footer class="footer">
        <p>&copy; 2025 EHEALTH. All rights reserved. | Connecting Healthcare, Empowering Lives</p>
    </footer>

</body>
</html>
