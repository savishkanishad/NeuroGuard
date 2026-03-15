# 🧠 NeuroGuard Pro

**NeuroGuard Pro** is a high-performance, dual-engine driver monitoring system (DMS) designed to enhance road safety through real-time AI computer vision. It detects drowsiness, yawning, and distractions (looking away) to alert the driver and log safety events for remote fleet management.

---

## ⚡ Dual-Engine Architecture

NeuroGuard Pro offers two distinct ways to run the AI detection system:

### 1. 🐍 Python Engine (`eye_engine/`)
Designed for dedicated hardware (like a Raspberry Pi or in-vehicle PC).
- **Tech Stack**: Python 3.x, Mediapipe, OpenCV, Pygame.
- **Advantages**: Low latency, background processing, direct hardware integration.

### 2. 🌐 Web Browser Engine (`web_dashboard/`)
Designed for quick fleet-wide deployment and remote driver monitoring via a browser.
- **Tech Stack**: JavaScript, Mediapipe (Web SDK), PHP 8.x, MySQL.
- **Advantages**: No local Python setup required, works on any modern browser, integrated with the Admin Dashboard.

---

## 📂 Project Structure

- **`eye_engine/`**: Core Python AI brain.
  - `sensor_test.py`: The primary detection script.
  - `face_landmarker.task`: Mediapipe model file (required).
  - `alarm.wav`: High-frequency audio alert.
- **`web_dashboard/`**: Complete fleet management and web-monitoring suite.
  - `index.php`: Admin authentication portal.
  - `portal.php`: Mode selection (Dashboard vs. Monitor).
  - `monitor.php`: **Web-based AI Engine** (Live webcam detection).
  - `dashboard.php`: Real-time safety log & analytics.
  - `engine.js`: Browser AI logic using Mediapipe Web SDK.
- **`database/`**: SQL schema and sample data.

---

## 🚀 Quick Start

### 1) Database Setup
1. Create a MySQL database named `neuroguard_db`.
2. Import `database/schema.sql` to set up the tables and default admin user.
3. Update `web_dashboard/db_config.php` with your database credentials.

### 2) Web Dashboard Deployment
1. Move the `web_dashboard/` folder to your web server (XAMPP `htdocs`, Apache `www`, etc.).
2. Navigate to `http://localhost/NeuroGuard%20Project/web_dashboard/`.
3. Login using the admin credentials (default in `admin_users` table).

### 3) Choose Your Monitor Mode

#### Via Browser (Recommended for quick test):
1. From the Web portal, click **"Driver Monitor"**.
2. Grant camera permissions.
3. The AI will begin detecting safety threats immediately.

#### Via Python (Recommended for production):
1. Install dependencies:
   ```bash
   pip install -r requirements.txt
   ```
2. Run the engine:
   ```bash
   python eye_engine/sensor_test.py
   ```

---

## 🔧 Configuration

### API Integration
By default, the Python engine posts alerts to `http://localhost/neuroguard_api/log_alert.php`. 
**Update `API_URL`** in `eye_engine/sensor_test.py` to match your local setup:
```python
API_URL = "http://localhost/NeuroGuard Project/web_dashboard/log_alert.php"
```

### Sensitivity Calibration
You can adjust the following thresholds in `sensor_test.py` (Python) or `engine.js` (Web):
- `EYE_THRESH`: Sensitivity for drowsiness (lower = more closed).
- `MOUTH_THRESH`: Sensitivity for yawning.
- `GAZE_MIN/MAX`: Safe horizontal "gaze zone".

---

## ✅ Prerequisites
- Webcam access.
- PHP 7.4/8.x with `mysqli` extension.
- MySQL/MariaDB.
- Python 3.10+ (for Python Engine).

---

## 📜 License
This project is for educational and safety demonstration purposes. 
*Stay safe on the road!*
