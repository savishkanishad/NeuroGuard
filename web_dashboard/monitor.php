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
    <title>NeuroGuard Pro | Driver Monitor</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-color: #020617;
            --overlay-bg: rgba(15, 23, 42, 0.8);
            --accent-red: #ef4444;
            --accent-blue: #38bdf8;
            --text-main: #f8fafc;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg-color);
            color: var(--text-main);
            margin: 0;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            height: 100vh;
        }

        .header {
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: rgba(15, 23, 42, 0.9);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(255,255,255,0.05);
            z-index: 10;
        }

        .logo { font-weight: 800; font-size: 20px; letter-spacing: -0.05em; }
        .logo span { color: var(--accent-blue); }

        .back-btn {
            color: #94a3b8;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
        }

        .video-container {
            position: relative;
            flex: 1;
            display: flex;
            justify-content: center;
            align-items: center;
            background: #000;
        }

        video#webcam {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transform: scaleX(-1); /* Mirror effect */
        }

        canvas#output_canvas {
            position: absolute;
            width: 100%;
            height: 100%;
            object-fit: cover;
            transform: scaleX(-1);
            pointer-events: none;
        }

        .status-overlay {
            position: absolute;
            top: 30px;
            left: 30px;
            z-index: 5;
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .status-card {
            background: var(--overlay-bg);
            backdrop-filter: blur(8px);
            padding: 15px 25px;
            border-radius: 12px;
            border: 1px solid rgba(255,255,255,0.1);
            min-width: 200px;
        }

        .status-label { font-size: 10px; text-transform: uppercase; color: #94a3b8; letter-spacing: 0.1em; font-weight: 700; margin-bottom: 5px; }
        .status-value { font-size: 18px; font-weight: 700; }

        .alert-banner {
            position: absolute;
            bottom: 50px;
            left: 50%;
            transform: translateX(-50%);
            padding: 20px 60px;
            border-radius: 50px;
            font-weight: 800;
            font-size: 24px;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            display: none;
            z-index: 20;
            box-shadow: 0 0 50px rgba(239, 68, 68, 0.4);
            animation: pulse 1s infinite;
        }

        .alert-drowsy { background: var(--accent-red); color: white; }
        .alert-yawn { background: #fbbf24; color: #78350f; }
        .alert-distracted { background: #3b82f6; color: white; }

        @keyframes pulse {
            0% { transform: translateX(-50%) scale(1); }
            50% { transform: translateX(-50%) scale(1.05); }
            100% { transform: translateX(-50%) scale(1); }
        }

        #loading-overlay {
            position: absolute;
            inset: 0;
            background: var(--bg-color);
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            z-index: 100;
        }

        .loader {
            width: 48px;
            height: 48px;
            border: 5px solid #FFF;
            border-bottom-color: var(--accent-blue);
            border-radius: 50%;
            display: inline-block;
            box-sizing: border-box;
            animation: rotation 1s linear infinite;
        }

        @keyframes rotation {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* --- MOBILE OPTIMIZATION --- */
        @media (max-width: 768px) {
            .header {
                padding: 10px 15px;
            }
            .logo { font-size: 16px; }
            .back-btn { font-size: 12px; }

            .status-overlay {
                top: auto;
                bottom: 120px; /* Above the alert banner */
                left: 15px;
                right: 15px;
                flex-direction: row;
                gap: 10px;
            }

            .status-card {
                flex: 1;
                padding: 10px;
                min-width: 0;
            }

            .status-label { font-size: 8px; }
            .status-value { font-size: 14px; }

            .alert-banner {
                bottom: 30px;
                padding: 15px 30px;
                font-size: 18px;
                width: 80%;
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <div id="loading-overlay">
        <span class="loader"></span>
        <p style="margin-top: 20px; font-weight: 600;">Initializing AI Engine...</p>
    </div>

    <div class="header">
        <div class="logo">NeuroGuard <span>PRO</span></div>
        <a href="portal.php" class="back-btn">← Back to Portal</a>
    </div>

    <div class="video-container">
        <video id="webcam" autoplay playsinline></video>
        <canvas id="output_canvas"></canvas>
        
        <div class="status-overlay">
            <div class="status-card">
                <div class="status-label">Engine Status</div>
                <div class="status-value" id="engine-status">Calibration...</div>
            </div>
            <div class="status-card">
                <div class="status-label">Alerting</div>
                <div class="status-value" id="alert-status">All Clear</div>
            </div>
        </div>

        <div id="alert-banner" class="alert-banner">DROWSY!</div>
    </div>

    <!-- MediaPipe Libraries -->
    <script src="https://cdn.jsdelivr.net/npm/@mediapipe/tasks-vision/vision_bundle.js" crossorigin="anonymous"></script>
    <script src="engine.js" type="module"></script>
</body>
</html>
