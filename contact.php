<?php
require_once __DIR__ . '/config/config.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contact - SRMS EHEALTH</title>
    <link rel="stylesheet" href="styles.css">
    <script src="script.js" defer></script>
    <style>
        .page-hero {
            text-align: center;
            color: white;
            margin-bottom: 2.5rem;
        }
        .page-hero h1 {
            font-size: 2.6rem;
            margin-bottom: 0.8rem;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.3);
        }
        .page-hero p {
            font-size: 1.1rem;
            color: rgba(255,255,255,0.9);
            max-width: 760px;
            margin: 0 auto;
        }
        .contact-wrap {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.6rem;
            align-items: start;
        }
        .contact-card {
            background: white;
            border-radius: 18px;
            padding: 1.8rem 1.6rem;
            box-shadow: 0 16px 34px rgba(0,0,0,0.14);
        }
        .contact-card h2 {
            color: #134d36;
            font-size: 1.4rem;
            margin-bottom: 0.7rem;
        }
        .contact-card p {
            color: #4b5563;
            line-height: 1.6;
            font-size: 1rem;
        }
        .contact-card .phone {
            margin-top: 1rem;
            font-size: 1.2rem;
            font-weight: 700;
            color: #134d36;
        }
        .contact-card .phone a {
            color: inherit;
            text-decoration: none;
        }
        .info-panel {
            background: rgba(255,255,255,0.12);
            border: 1px solid rgba(255,255,255,0.25);
            border-radius: 18px;
            padding: 1.6rem;
            color: white;
        }
        .info-panel h3 {
            font-size: 1.3rem;
            margin-bottom: 0.6rem;
        }
        .info-panel p {
            color: rgba(255,255,255,0.9);
            line-height: 1.6;
        }
        @media (max-width: 600px) {
            .page-hero h1 { font-size: 2.1rem; }
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
            <h1>Contact Us</h1>
            <p>
                Have questions about the platform, onboarding, or support? Reach out and we will
                help you get the right information quickly. We are here to assist patients, staff,
                and administrators across the SRMS EHEALTH ecosystem.
            </p>
        </section>

        <section class="contact-wrap">
            <div class="contact-card">
                <h2>Contact Developed</h2>
                <p>For project-related queries, feature updates, or technical clarifications, connect directly.</p>
                <div class="phone">
                    <a href="tel:8279805090">8279805090</a>
                </div>
            </div>

            <div class="info-panel">
                <h3>Support Information</h3>
                <p>
                    You can also contact your local eHealth center for patient registration help,
                    appointment guidance, and teleconsultation assistance. Our support team will
                    direct you to the right department.
                </p>
                <p style="margin-top:1rem;">
                    Operating hours: 9:00 AM to 6:00 PM (Mon-Sat)
                </p>
            </div>
        </section>
    </main>

    <footer class="footer">
        <p>&copy; 2025 EHEALTH. All rights reserved. | Connecting Healthcare, Empowering Lives</p>
    </footer>
</body>
</html>
