import os
import time
import threading
import urllib.parse
import urllib.request
import sqlite3
import json
from collections import deque
from dotenv import load_dotenv

import cv2
import numpy as np
from scipy.spatial import distance as dist
from pygame import mixer

# ─────────────────────────────────────────────────────────────────────────────
#  0.  ENVIRONMENT & CONFIG
# ─────────────────────────────────────────────────────────────────────────────
BASE_DIR = os.path.dirname(os.path.abspath(__file__))
load_dotenv(os.path.join(BASE_DIR, ".env"))

API_URL   = os.getenv("API_URL", "http://localhost/neuroguard_api/log_alert.php")
API_KEY   = os.getenv("API_KEY", "NgPro2026_xYz98!")
DRIVER_ID = int(os.getenv("DRIVER_ID", 1))

# ─────────────────────────────────────────────────────────────────────────────
#  1.  SOUND SETUP (ESCALATION)
# ─────────────────────────────────────────────────────────────────────────────
ALARM_PATH = os.path.join(BASE_DIR, "alarm.wav")
CHIME_PATH = os.path.join(BASE_DIR, "chime.wav")

mixer.init()
alarm_sound = mixer.Sound(ALARM_PATH) if os.path.exists(ALARM_PATH) else None
chime_sound = mixer.Sound(CHIME_PATH) if os.path.exists(CHIME_PATH) else alarm_sound

if alarm_sound: print("✅  Alarm loaded")
if os.path.exists(CHIME_PATH): print("✅  Chime loaded")

# ─────────────────────────────────────────────────────────────────────────────
#  2.  CONFIG & THRESHOLDS
# ─────────────────────────────────────────────────────────────────────────────
EYE_THRESH_DEFAULT = 0.23   
MOUTH_THRESH       = 0.60
GAZE_MIN, GAZE_MAX = 0.30, 0.70   
TURN_MIN, TURN_MAX = 0.35, 0.65   
NOD_THRESH  = 0.60    
TILT_THRESH = 0.08    

CALIBRATION_DURATION = 10.0   

EAR_BUF_LEN   = 5    
MAR_BUF_LEN   = 8    
GAZE_BUF_LEN  = 6    
TURN_BUF_LEN  = 6    
PITCH_BUF_LEN = 5    
ROLL_BUF_LEN  = 5    

DROWSY_WAIT_TIME   = 1.0    
YAWN_WAIT_TIME     = 0.7
DISTRACT_WAIT_TIME = 1.5
NOD_WAIT_TIME      = 1.2    

ALERT_LATCH_TIME   = 2.0    
SYNC_COOLDOWN      = 10.0   

# ─────────────────────────────────────────────────────────────────────────────
#  3.  OFFLINE QUEUE & DATABASE SYNC
# ─────────────────────────────────────────────────────────────────────────────
QUEUE_DB_PATH = os.path.join(BASE_DIR, "offline_queue.db")

def init_offline_db():
    with sqlite3.connect(QUEUE_DB_PATH) as conn:
        conn.execute('''CREATE TABLE IF NOT EXISTS queue
                        (id INTEGER PRIMARY KEY AUTOINCREMENT, 
                         payload TEXT, 
                         timestamp DATETIME DEFAULT CURRENT_TIMESTAMP)''')
init_offline_db()

def offline_sync_worker():
    while True:
        time.sleep(30)
        try:
            with sqlite3.connect(QUEUE_DB_PATH) as conn:
                cursor = conn.execute("SELECT id, payload FROM queue ORDER BY timestamp ASC LIMIT 50")
                rows = cursor.fetchall()
                for row_id, payload_json in rows:
                    payload = json.loads(payload_json)
                    data = urllib.parse.urlencode(payload).encode()
                    req = urllib.request.Request(API_URL, data=data, headers={'X-API-Key': API_KEY})
                    with urllib.request.urlopen(req, timeout=5) as resp:
                        conn.execute("DELETE FROM queue WHERE id=?", (row_id,))
                        conn.commit()
                        print(f"[Queue] Synced offline alert ID {row_id}")
        except Exception as e:
            pass # Keep quiet, retry later

threading.Thread(target=offline_sync_worker, daemon=True).start()

def get_new_session(driver_id: int) -> int:
    # URL path logic just to find correct host based on API_URL
    host = API_URL.rsplit('/', 1)[0]
    session_url = f"{host}/start_session.php"
    data = urllib.parse.urlencode({"driver_id": driver_id, "api_key": API_KEY}).encode()
    req  = urllib.request.Request(session_url, data=data)
    try:
        with urllib.request.urlopen(req, timeout=2) as resp:
            new_id = resp.read().decode().strip()
            print(f"🚀  Session started — ID: {new_id}")
            return int(new_id)
    except Exception as e:
        print(f"⚠️   Could not reach session API ({e}) — defaulting to session 1")
        return 1

SESSION_ID = get_new_session(DRIVER_ID)

_sync_lock       = threading.Lock()
_last_sync_times = {"Drowsy": 0.0, "Yawn": 0.0, "Distracted": 0.0, "Microsleep": 0.0}

def sync_to_db(alert_type: str, severity: str) -> None:
    now = time.time()
    if now - _last_sync_times.get(alert_type, 0.0) < SYNC_COOLDOWN:
        return
    if not _sync_lock.acquire(blocking=False):
        return

    def _worker() -> None:
        try:
            _last_sync_times[alert_type] = time.time()
            
            # Mock GPS coordinates (Colombo area, drifting slightly)
            mock_lat = 6.9271 + (np.random.rand() - 0.5) * 0.05
            mock_lng = 79.8612 + (np.random.rand() - 0.5) * 0.05

            payload = {
                "driver_id":  DRIVER_ID,
                "session_id": SESSION_ID,
                "alert_type": alert_type,
                "severity": severity,
                "latitude": round(mock_lat, 6),
                "longitude": round(mock_lng, 6)
            }
            
            data = urllib.parse.urlencode(payload).encode()
            req  = urllib.request.Request(API_URL, data=data, headers={'X-API-Key': API_KEY})
            with urllib.request.urlopen(req, timeout=2.0) as resp:
                print(f"[DB] {alert_type} ({severity}) → {resp.read().decode(errors='ignore').strip()}")
        except Exception as exc:
            print(f"[DB] Sync error, queueing offline: {exc}")
            with sqlite3.connect(QUEUE_DB_PATH) as conn:
                conn.execute("INSERT INTO queue (payload) VALUES (?)", (json.dumps(payload),))
        finally:
            _sync_lock.release()

    threading.Thread(target=_worker, daemon=True).start()

# ─────────────────────────────────────────────────────────────────────────────
#  4.  ROLLING BUFFER & MATH HELPERS
# ─────────────────────────────────────────────────────────────────────────────
class RollingBuffer:
    def __init__(self, maxlen: int):
        self._buf = deque(maxlen=maxlen)

    def push(self, value: float) -> None:
        self._buf.append(value)

    def mean(self) -> float:
        return float(np.mean(self._buf)) if self._buf else 0.0

def eye_aspect_ratio(eye_coords: list) -> float:
    A = dist.euclidean(eye_coords[1], eye_coords[5])
    B = dist.euclidean(eye_coords[2], eye_coords[4])
    C = dist.euclidean(eye_coords[0], eye_coords[3])
    return (A + B) / (2.0 * C) if C != 0 else 0.0

def get_horizontal_ratio(iris, corner1, corner2) -> float:
    left_x  = min(corner1.x, corner2.x)
    right_x = max(corner1.x, corner2.x)
    width   = right_x - left_x
    if width < 0.005: return 0.5
    return max(0.0, min(1.0, (iris.x - left_x) / width))

def head_pitch(lm) -> float:
    forehead_y = lm[10].y
    nose_y     = lm[1].y
    chin_y     = lm[152].y
    span       = chin_y - forehead_y
    if abs(span) < 0.01: return 0.5
    return max(0.0, min(1.0, (nose_y - forehead_y) / span))

def head_roll(lm) -> float:
    left_outer_y  = lm[263].y
    right_outer_y = lm[33].y
    eye_span_x    = abs(lm[263].x - lm[33].x)
    if eye_span_x < 0.01: return 0.0
    return (left_outer_y - right_outer_y) / eye_span_x

# ─────────────────────────────────────────────────────────────────────────────
#  5.  MEDIAPIPE SETUP
# ─────────────────────────────────────────────────────────────────────────────
import mediapipe as mp
from mediapipe.tasks.python.core.base_options import BaseOptions
from mediapipe.tasks.python import vision as mp_vision

MODEL_PATH = os.path.join(BASE_DIR, "face_landmarker.task")
_options = mp_vision.FaceLandmarkerOptions(
    base_options=BaseOptions(model_asset_path=MODEL_PATH),
    running_mode=mp_vision.RunningMode.VIDEO,
    num_faces=1,
    output_face_blendshapes=False,
)
landmarker = mp_vision.FaceLandmarker.create_from_options(_options)
print("✅  FaceLandmarker loaded")

LEFT_EYE  = [362, 385, 387, 263, 373, 380]
RIGHT_EYE = [33,  160, 158, 133, 153, 144]
MOUTH     = [13, 14, 78, 308]
L_IRIS, R_IRIS = 468, 473
L_IN,  L_OUT   = 362, 263
R_IN,  R_OUT   = 133,  33
NOSE           = 1
LEFT_F         = 234
RIGHT_F        = 454
FOREHEAD       = 10
CHIN           = 152

_last_ts_ms = 0
def next_timestamp_ms():
    global _last_ts_ms
    ts = int(time.time() * 1000)
    if ts <= _last_ts_ms: ts = _last_ts_ms + 1
    _last_ts_ms = ts
    return ts

# ─────────────────────────────────────────────────────────────────────────────
#  6.  CALIBRATION
# ─────────────────────────────────────────────────────────────────────────────
def calibrate(cap, duration: float = CALIBRATION_DURATION) -> float:
    print(f"\n👁  CALIBRATION — look straight at the camera for {int(duration)} seconds…")
    print("Camera warming up...")
    for _ in range(30):
        cap.read()
    time.sleep(0.5)
    
    samples = []
    start = time.time()

    while time.time() - start < duration:
        ret, frame = cap.read()
        if not ret: continue

        elapsed = time.time() - start
        h, w = frame.shape[:2]
        rgb = cv2.cvtColor(frame, cv2.COLOR_BGR2RGB)
        mp_img = mp.Image(image_format=mp.ImageFormat.SRGB, data=rgb)

        res = landmarker.detect_for_video(mp_img, next_timestamp_ms())

        overlay = frame.copy()
        cv2.rectangle(overlay, (0, 0), (w, 88), (15, 15, 15), -1)
        cv2.addWeighted(overlay, 0.78, frame, 0.22, 0, frame)

        bar_w = int((elapsed / duration) * max(1, w - 40))
        cv2.rectangle(frame, (20, 60), (w - 20, 76), (50, 50, 50), -1)
        cv2.rectangle(frame, (20, 60), (20 + bar_w, 76), (80, 220, 80), -1)
        cv2.putText(frame, f"CALIBRATING — keep eyes open  {duration - elapsed:.1f}s", (20, 42), cv2.FONT_HERSHEY_SIMPLEX, 0.65, (255, 255, 255), 2)

        if res.face_landmarks:
            lm = res.face_landmarks[0]
            coords = [(int(l.x * w), int(l.y * h)) for l in lm]
            ear = (eye_aspect_ratio([coords[i] for i in LEFT_EYE]) + eye_aspect_ratio([coords[i] for i in RIGHT_EYE])) / 2.0
            if ear > 0.15: samples.append(ear)
            cv2.putText(frame, f"EAR={ear:.3f}  samples={len(samples)}", (20, h - 14), cv2.FONT_HERSHEY_SIMPLEX, 0.48, (140, 140, 140), 1)

        cv2.imshow("NeuroGuard Pro Engine", frame)
        if cv2.waitKey(1) & 0xFF == ord("q"): break

    if len(samples) < 30:
        return EYE_THRESH_DEFAULT

    mean_ear = float(np.mean(samples))
    calibrated = round(mean_ear * 0.75, 4)
    print(f"✅  Calibration done — mean EAR={mean_ear:.3f}  → eye threshold={calibrated:.3f}")
    return calibrated

# ─────────────────────────────────────────────────────────────────────────────
#  7.  MUTABLE DETECTION STATE & HELPERS
# ─────────────────────────────────────────────────────────────────────────────
drowsy_start_time   = 0.0; IS_EYE_CLOSED = False
yawn_start_time     = 0.0; IS_YAWN_OPEN  = False
distract_start_time = 0.0; IS_DISTRACTED = False
nod_start_time      = 0.0; IS_NODDING    = False

active_alert   = None
alert_end_time = 0.0
cooldowns      = {"Drowsy": 0.0, "Yawn": 0.0, "Distracted": 0.0, "Microsleep": 0.0}

alert_history = deque(maxlen=20) # Track (timestamp, alert_type) for escalation

_buf_ear   = RollingBuffer(EAR_BUF_LEN)
_buf_mar   = RollingBuffer(MAR_BUF_LEN)
_buf_gaze  = RollingBuffer(GAZE_BUF_LEN)
_buf_turn  = RollingBuffer(TURN_BUF_LEN)
_buf_pitch = RollingBuffer(PITCH_BUF_LEN)
_buf_roll  = RollingBuffer(ROLL_BUF_LEN)

ALERT_STYLE = {
    "Drowsy":     ("⚠  DROWSY!",        (0,   0,   220)),
    "Yawn":       ("⚠  YAWNING!",       (0,   200, 255)),
    "Distracted": ("⚠  LOOKING AWAY!",  (220, 130,   0)),
    "Microsleep": ("⚠  MICROSLEEP!",    (30,   0,  200)),
}
CLR_GREEN, CLR_RED, CLR_GRAY, CLR_WHITE, CLR_ICE = (80, 220, 80), (60, 60, 240), (160, 160, 160), (255, 255, 255), (220, 200, 80)
FONT = cv2.FONT_HERSHEY_SIMPLEX

# ─────────────────────────────────────────────────────────────────────────────
#  8.  MAIN
# ─────────────────────────────────────────────────────────────────────────────
cap = cv2.VideoCapture(0)
print("\n🟢  NeuroGuard Pro — starting calibration…\n")
eye_thresh = calibrate(cap, CALIBRATION_DURATION)
print("\n🎯  Detection active — [Q] Quit | [N] Night Vision\n")

# To track active audio escalation state
current_audio_level = 0 
is_night_vision = False

while True:
    ret, frame = cap.read()
    if not ret: break

    now  = time.time()
    h, w = frame.shape[:2]
    rgb  = cv2.cvtColor(frame, cv2.COLOR_BGR2RGB)
    mp_img = mp.Image(image_format=mp.ImageFormat.SRGB, data=rgb)
    results = landmarker.detect_for_video(mp_img, next_timestamp_ms())

    if results.face_landmarks:
        lm = results.face_landmarks[0]
        coords = [(int(l.x * w), int(l.y * h)) for l in lm]

        # Raw Metrics
        raw_ear = (eye_aspect_ratio([coords[i] for i in LEFT_EYE]) + eye_aspect_ratio([coords[i] for i in RIGHT_EYE])) / 2.0
        raw_mar = dist.euclidean(coords[MOUTH[0]], coords[MOUTH[1]]) / dist.euclidean(coords[MOUTH[2]], coords[MOUTH[3]])
        raw_gaze = (get_horizontal_ratio(lm[L_IRIS], lm[L_IN], lm[L_OUT]) + get_horizontal_ratio(lm[R_IRIS], lm[R_IN], lm[R_OUT])) / 2.0
        
        nose_x, left_f_x, right_f_x = lm[NOSE].x, lm[LEFT_F].x, lm[RIGHT_F].x
        span = right_f_x - left_f_x
        raw_turn = ((nose_x - left_f_x) / span) if span != 0 else 0.5
        raw_pitch = head_pitch(lm)
        raw_roll  = head_roll(lm)

        # Buffers
        _buf_ear.push(raw_ear); ear = _buf_ear.mean()
        _buf_mar.push(raw_mar); mar = _buf_mar.mean()
        _buf_gaze.push(raw_gaze); avg_gaze = _buf_gaze.mean()
        _buf_turn.push(raw_turn); turn = _buf_turn.mean()
        _buf_pitch.push(raw_pitch); pitch = _buf_pitch.mean()
        _buf_roll.push(raw_roll); roll = _buf_roll.mean()

        # Detection Logic
        detected_type = None

        if ear < eye_thresh:
            if not IS_EYE_CLOSED: drowsy_start_time = now; IS_EYE_CLOSED = True
            if (now - drowsy_start_time) > DROWSY_WAIT_TIME: detected_type = "Drowsy"
        else: IS_EYE_CLOSED = False

        if (pitch > NOD_THRESH) or (abs(roll) > TILT_THRESH):
            if not IS_NODDING: nod_start_time = now; IS_NODDING = True
            if not detected_type and (now - nod_start_time) > NOD_WAIT_TIME: detected_type = "Microsleep"
        else: IS_NODDING = False

        if mar > MOUTH_THRESH:
            if not IS_YAWN_OPEN: yawn_start_time = now; IS_YAWN_OPEN = True
            if not detected_type and (now - yawn_start_time) > YAWN_WAIT_TIME: detected_type = "Yawn"
        else: IS_YAWN_OPEN = False

        is_away = ((avg_gaze < GAZE_MIN or avg_gaze > GAZE_MAX) or (turn < TURN_MIN or turn > TURN_MAX))
        if is_away:
            if not IS_DISTRACTED: distract_start_time = now; IS_DISTRACTED = True
            if not detected_type and (now - distract_start_time) > DISTRACT_WAIT_TIME: detected_type = "Distracted"
        else: IS_DISTRACTED = False

        # Alert State Machine (Escalation)
        if detected_type and active_alert is None and now > cooldowns[detected_type]:
            active_alert = detected_type
            alert_end_time = now + ALERT_LATCH_TIME
            
            # Escalation: Count same alerts in the last 60 seconds
            alert_history.append((now, active_alert))
            recent_count = sum(1 for t, typ in alert_history if (now - t) <= 60 and typ == active_alert)
            
            if recent_count >= 3:
                severity = "Critical"
                current_audio_level = 2
            elif recent_count == 2:
                severity = "High"
                current_audio_level = 2
            else:
                severity = "High" if active_alert == "Drowsy" else "Medium"
                current_audio_level = 1

            sync_to_db(active_alert, severity)

        if detected_type == active_alert:
            alert_end_time = now + ALERT_LATCH_TIME

        if active_alert and now > alert_end_time:
            # Dynamic Cooldown based on severity/frequency
            recent_count = sum(1 for t, typ in alert_history if (now - t) <= 60 and typ == active_alert)
            cooldowns[active_alert] = now + (15.0 if recent_count >= 2 else 10.0)
            active_alert = None
            current_audio_level = 0

        # Audio
        if active_alert:
            if current_audio_level == 2:
                if chime_sound: chime_sound.stop()
                if alarm_sound and not mixer.get_busy(): alarm_sound.play(-1)
            else:
                if alarm_sound: alarm_sound.stop()
                if chime_sound and not mixer.get_busy(): chime_sound.play(-1)
        else:
            if alarm_sound: alarm_sound.stop()
            if chime_sound: chime_sound.stop()

        # Visuals
        if active_alert:
            label, colour = ALERT_STYLE[active_alert]
            cv2.rectangle(frame, (0, 0), (w, 58), colour, -1)
            cv2.putText(frame, label, (w // 2 - 155, 40), cv2.FONT_HERSHEY_DUPLEX, 1.2, CLR_WHITE, 2)
            # Show Escalation Level
            if current_audio_level == 2:
                cv2.putText(frame, "ESCALATED", (w - 150, 35), cv2.FONT_HERSHEY_SIMPLEX, 0.7, (0, 0, 0), 2)

        # HUD
        rows = [
            ("EYES :", ear, ear < eye_thresh), ("MOUTH:", mar, mar > MOUTH_THRESH),
            ("GAZE :", avg_gaze, avg_gaze < GAZE_MIN or avg_gaze > GAZE_MAX),
            ("TURN :", turn, turn < TURN_MIN or turn > TURN_MAX),
            ("PITCH:", pitch, pitch > NOD_THRESH), ("ROLL :", abs(roll), abs(roll) > TILT_THRESH),
        ]
        for i, (label, val, warn) in enumerate(rows):
            y = h - 14 - (len(rows) - 1 - i) * 22
            cv2.putText(frame, label, (12, y), FONT, 0.50, CLR_GRAY, 1)
            cv2.putText(frame, f"{val:.2f}", (100, y), FONT, 0.50, CLR_RED if warn else CLR_GREEN, 2)

        cd_y = h - 14
        for atype, t in cooldowns.items():
            if t - now > 0:
                cv2.putText(frame, f"[{atype} ❄ {t - now:.1f}s]", (w - 230, cd_y), FONT, 0.40, CLR_ICE, 1)
                cd_y -= 18
    else:
        if alarm_sound: alarm_sound.stop()
        if chime_sound: chime_sound.stop()
        active_alert = None
        current_audio_level = 0
        cv2.putText(frame, "No face detected", (12, 40), FONT, 0.7, CLR_GRAY, 2)

    # Night Vision Effect (apply before showing, but after MediaPipe to not break inference)
    if is_night_vision:
        # Increase brightness/contrast
        enhanced = cv2.convertScaleAbs(frame, alpha=1.2, beta=20)
        gray = cv2.cvtColor(enhanced, cv2.COLOR_BGR2GRAY)
        # Create green tint (B=0, G=gray, R=0)
        zeros = np.zeros_like(gray)
        frame = cv2.merge([zeros, gray, zeros])
        cv2.putText(frame, "NIGHT VISION ACTIVE", (w - 200, 70), FONT, 0.5, (0, 255, 0), 1)

    cv2.imshow("NeuroGuard Pro Engine", frame)
    key = cv2.waitKey(1) & 0xFF
    if key == ord("q"): break
    elif key == ord("n"): is_night_vision = not is_night_vision

cap.release()
cv2.destroyAllWindows()
landmarker.close()
if alarm_sound: alarm_sound.stop()
if chime_sound: chime_sound.stop()
print(f"\n🏁  Session {SESSION_ID} closed.")
