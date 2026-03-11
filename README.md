# NeuroGuard

NeuroGuard is a simple driver monitoring system that uses face landmark detection to detect drowsiness, yawning, and distraction. It includes a Python-based vision engine and a PHP web dashboard for real-time alert monitoring.

---

## 🧠 Project Structure

- `eye_engine/`
  - `sensor_test.py` — Main Python AI brain (camera + dlib face landmarks + alert logic)
  - `shape_predictor_68_face_landmarks.dat` — Dlib facial landmark model (required)
  - `alarm.wav` — Sound played when an alert triggers

- `web_dashboard/`
  - `index.php` — Admin login page
  - `dashboard.php` — Live alert dashboard (auto-refresh)
  - `log_alert.php` — API endpoint to record alerts into the database
  - `start_session.php` — API endpoint to begin a driver session
  - `logout.php` — Ends the admin session

- `database/`
  - `neuroguard_db.sql` — Full DB dump (includes sample data)
  - `schema.sql` — Schema dump (same as `neuroguard_db.sql`)

- `requirements.txt` — Python dependencies required to run the AI engine

---

## 🚀 Setup

### 1) Python dependencies

From the project root:

```bash
pip install -r requirements.txt
```

### 2) Web dashboard (PHP + MySQL/MariaDB)

1. Import `database/neuroguard_db.sql` into your MySQL/MariaDB server.
2. Place `web_dashboard/` in a web server document root (e.g., `htdocs`, `www`).
3. Ensure PHP has MySQLi enabled.

> **Note:** The dashboard currently connects using default credentials (`root` with no password). Update the connection strings in `web_dashboard/*.php` for production.

### 3) Run the Python engine

From the project root:

```bash
python eye_engine/sensor_test.py
```

It will open the webcam, detect drowsiness/yawning/distraction, play `alarm.wav`, and post alert events to the PHP API.

---

## 🔧 Configuration

- Modify `eye_engine/sensor_test.py` to point at your API URL if you host the dashboard in a different folder or port.
- Update database credentials in `web_dashboard/*.php`.

---

## ✅ Notes

- `shape_predictor_68_face_landmarks.dat` is required and must be in `eye_engine/`.
- If you want to add session end tracking, add an `end_session.php` endpoint and call it from `sensor_test.py` when exiting.
