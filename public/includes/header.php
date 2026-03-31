<?php
require_once __DIR__ . '/../../config/config.php';
?>
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
