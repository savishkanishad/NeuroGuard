# NeuroGuard Pro - System Architecture Specification (SAS)

## 1. System Overview
NeuroGuard Pro is a fleet management system designed to monitor driver safety in real-time, detecting drowsiness, yawning, and distraction. The system relies on an AI-powered browser engine and a corresponding Python engine to process video feeds, while a centralized web dashboard aggregates the alerts.

## 2. Technology Stack
- **Frontend & Engine:** HTML5, CSS3 (Vanilla), JavaScript (ES6+ Modules)
- **Computer Vision:** MediaPipe Tasks Vision API (FaceLandmarker)
- **Backend API & Dashboard:** PHP 8.x
- **Database:** MySQL / MariaDB (hosted via InfinityFree for production)
- **Offline/Python Engine:** Python 3.x, OpenCV, PyGame (for alarms)

## 3. Architecture Design
The architecture is a classic client-server model with two distinct client types:

### 3.1 AI Browser Engine (Client)
- A web-based client (`monitor.php` + `engine.js`) that runs in the vehicle using any modern web browser.
- Utilizes the device's webcam to perform on-device inference via MediaPipe.
- Detects drowsiness (EAR), yawning (MAR), and distraction (gaze/head turn).
- Issues HTTP POST requests to the Backend API to log alerts.

### 3.2 Python Offline Engine (Alternative Client)
- A standalone script (`sensor_test.py`) functioning similarly to the web engine.
- Uses the MediaPipe Python API and OpenCV for camera processing and visualization.
- Ideal for low-resource environments or specific hardware setups.

### 3.3 Backend API & Database (Server)
- Receives HTTP POST payloads containing alert events.
- Performs server-side debouncing and validation using an `X-API-Key`.
- Logs events to the MySQL database.
- Serves the admin portal (`dashboard.php`) fetching live data for supervisors.

## 4. Data Model
- **admin_users:** Stores administrator credentials (bcrypt hashed).
- **drivers:** Stores driver profiles (e.g. ID, name, license).
- **sessions:** Tracks active monitoring periods per driver.
- **alerts:** Logs individual safety events (type, severity, timestamp) linked to a session and driver.
