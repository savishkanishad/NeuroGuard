<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: index.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NeuroGuard Pro | Launchpad</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-color: #0f172a;
            --card-bg: rgba(30, 41, 59, 0.7);
            --text-main: #f8fafc;
            --accent-blue: #38bdf8;
            --accent-purple: #818cf8;
            --glass-border: rgba(255, 255, 255, 0.1);
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg-color);
            background-image: 
                radial-gradient(at 0% 0%, rgba(56, 189, 248, 0.15) 0px, transparent 50%),
                radial-gradient(at 100% 100%, rgba(129, 140, 248, 0.1) 0px, transparent 50%);
            color: var(--text-main);
            margin: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }

        .container {
            text-align: center;
            max-width: 900px;
            padding: 20px;
        }

        h1 {
            font-size: 48px;
            font-weight: 800;
            margin-bottom: 10px;
            letter-spacing: -0.05em;
        }

        h1 span {
            background: linear-gradient(to right, var(--accent-blue), var(--accent-purple));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        p.subtitle {
            color: #94a3b8;
            font-size: 18px;
            margin-bottom: 50px;
        }

        .launchpad {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
        }

        .card {
            background: var(--card-bg);
            backdrop-filter: blur(12px);
            border: 1px solid var(--glass-border);
            border-radius: 24px;
            padding: 40px;
            text-decoration: none;
            color: white;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }

        .card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background: linear-gradient(45deg, transparent, rgba(255, 255, 255, 0.03), transparent);
            transform: translateX(-100%);
            transition: 0.5s;
        }

        .card:hover {
            transform: translateY(-10px) scale(1.02);
            border-color: rgba(56, 189, 248, 0.4);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.4);
        }

        .card:hover::before {
            transform: translateX(100%);
        }

        .icon {
            font-size: 50px;
            margin-bottom: 20px;
            display: block;
        }

        .card h3 {
            font-size: 24px;
            margin-bottom: 15px;
        }

        .card p {
            color: #94a3b8;
            font-size: 14px;
            line-height: 1.6;
        }

        .badge {
            position: absolute;
            top: 20px;
            right: 20px;
            background: var(--accent-blue);
            color: var(--bg-color);
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
        }

        @media (max-width: 600px) {
            .launchpad { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>NeuroGuard <span>PRO</span></h1>
        <p class="subtitle">Select your workstation mode to begin</p>
        
        <div class="launchpad">
            <a href="dashboard.php" class="card">
                <span class="icon">📊</span>
                <h3>Admin Dashboard</h3>
                <p>Monitor real-time alerts, driver statistics, and safety logs across the fleet.</p>
            </a>

            <a href="monitor.php" class="card">
                <span class="badge">New</span>
                <span class="icon">📷</span>
                <h3>Driver Monitor</h3>
                <p>Launch the AI-powered browser engine for real-time drowsiness and distraction detection.</p>
            </a>
        </div>
    </div>
</body>
</html>
