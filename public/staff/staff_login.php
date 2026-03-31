<?php
require_once __DIR__ . '/../../config/config.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Portal - EHEALTH</title>
    <link rel="stylesheet" href="/ehealth/styles.css">
    <script src="/ehealth/script.js" defer></script>
</head>
<body>
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

    <main class="main-container">
        <section class="welcome-section">
            <h1 class="main-heading">Staff Portal</h1>
            <p class="sub-heading">Choose your department to login</p>
        </section>

        <section class="user-types">
            <a href="/ehealth/public/nursing_staff/nursing_login.php" class="user-card" id="nursing-card">
                <div class="card-icon">🧑‍⚕️</div>
                <h2 class="card-title">Nursing Staff</h2>
                <p class="card-description">
                    Record patient vitals, manage the nursing queue, and prepare patients for doctor consultation.
                </p>
                <button class="card-button">Nursing Staff Login</button>
            </a>

            <a href="/ehealth/public/technician/technician_login.php" class="user-card" id="technician-card">
                <div class="card-icon">🔧</div>
                <h2 class="card-title">Technician Staff</h2>
                <p class="card-description">
                    Manage sample collection, testing workflow support, and technician operations.
                </p>
                <button class="card-button">Technician Staff Login</button>
            </a>

            <a href="/ehealth/public/pharmacy/pharmacy_login.php" class="user-card" id="pharmacy-card">
                <div class="card-icon">💊</div>
                <h2 class="card-title">Pharmacy Staff</h2>
                <p class="card-description">
                    Handle prescriptions, dispense medicines, and manage pharmacy records.
                </p>
                <button class="card-button">Pharmacy Staff Login</button>
            </a>
        </section>
    </main>

    <footer class="footer">
        <p>&copy; 2025 EHEALTH. All rights reserved. | Connecting Healthcare, Empowering Lives</p>
    </footer>
</body>
</html>
