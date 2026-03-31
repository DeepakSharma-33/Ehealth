<?php
require_once __DIR__ . '/../../config/config.php';
require_role('admin', 'admin_login.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Website Flowchart - SRMS eHealth</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root {
            --bg-a: #edf8f0;
            --bg-b: #d8ede0;
            --ink: #163528;
            --muted: #5f776a;
            --panel: rgba(255,255,255,0.92);
            --border: #d5e5da;
            --green: #1d7a58;
            --green-dark: #13523c;
        }
        body {
            min-height: 100vh;
            font-family: 'Segoe UI', Arial, sans-serif;
            color: var(--ink);
            background:
                radial-gradient(1200px 420px at 10% -10%, rgba(29,122,88,0.18), transparent 60%),
                radial-gradient(900px 320px at 90% 0%, rgba(210,180,95,0.15), transparent 55%),
                linear-gradient(180deg, var(--bg-a), var(--bg-b));
        }
        .nav {
            position: sticky;
            top: 0;
            z-index: 10;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            padding: 14px 22px;
            background: linear-gradient(135deg, var(--green-dark), var(--green));
            box-shadow: 0 10px 24px rgba(0,0,0,0.14);
        }
        .brand {
            color: #fff;
            font-weight: 800;
            letter-spacing: 0.06em;
            line-height: 1.05;
        }
        .brand small {
            display: block;
            font-size: 11px;
            font-weight: 600;
            opacity: 0.82;
            letter-spacing: 0.08em;
        }
        .nav-links,
        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        .nav-links a,
        .actions a {
            text-decoration: none;
            border-radius: 999px;
            padding: 10px 15px;
            font-size: 13px;
            font-weight: 700;
            transition: transform 0.2s ease, background 0.2s ease;
        }
        .nav-links a {
            color: #fff;
            background: rgba(255,255,255,0.14);
            border: 1px solid rgba(255,255,255,0.18);
        }
        .actions a {
            color: #fff;
            background: linear-gradient(135deg, var(--green), var(--green-dark));
        }
        .nav-links a:hover,
        .actions a:hover {
            transform: translateY(-1px);
        }
        .wrap {
            max-width: 1600px;
            margin: 0 auto;
            padding: 28px 18px 40px;
        }
        .hero {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 24px;
            padding: 24px;
            box-shadow: 0 16px 32px rgba(21,53,40,0.08);
            margin-bottom: 18px;
        }
        .eyebrow {
            display: inline-block;
            padding: 6px 11px;
            border-radius: 999px;
            background: #dff1e8;
            color: var(--green-dark);
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            margin-bottom: 12px;
        }
        h1 {
            font-size: clamp(28px, 4vw, 42px);
            line-height: 1.08;
            margin-bottom: 10px;
        }
        .hero p {
            color: var(--muted);
            font-size: 15px;
            line-height: 1.65;
            max-width: 920px;
            margin-bottom: 16px;
        }
        .viewer {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 24px;
            padding: 16px;
            box-shadow: 0 16px 32px rgba(21,53,40,0.08);
        }
        .viewer img {
            display: block;
            width: 100%;
            height: auto;
            border-radius: 18px;
            background: #fff;
        }
        .hint {
            margin-top: 12px;
            color: var(--muted);
            font-size: 13px;
        }
        @media (max-width: 760px) {
            .nav {
                align-items: flex-start;
                flex-direction: column;
            }
            .wrap {
                padding: 18px 12px 28px;
            }
            .hero,
            .viewer {
                padding: 14px;
            }
        }
    </style>
</head>
<body>
    <div class="nav">
        <div class="brand">
            SRMS EHEALTH
            <small>Website Flowchart</small>
        </div>
        <div class="nav-links">
            <a href="admin_dashboard.php">Admin Dashboard</a>
            <a href="view_analysis.php">View Analysis</a>
        </div>
    </div>

    <main class="wrap">
        <section class="hero">
            <div class="eyebrow">Single Image View</div>
            <h1>Website flowchart</h1>
            <p>This is the image version: one SVG flowchart showing the portal entry points, patient journey, telemedicine branch, pharmacy handoff, and admin analysis path.</p>
            <div class="actions">
                <a href="website_flow.svg" target="_blank" rel="noopener">Open SVG</a>
                <a href="website_flow.svg" download>Download SVG</a>
            </div>
        </section>

        <section class="viewer">
            <img src="website_flow.svg" alt="SRMS eHealth website flowchart">
            <div class="hint">If you want, I can also export this same flowchart as PNG for presentations.</div>
        </section>
    </main>
</body>
</html>
