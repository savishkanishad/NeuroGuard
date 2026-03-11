import os
import time
import urllib.parse
import urllib.request
import cv2
from scipy.spatial import distance as dist
from pygame import mixer

# --- 1. INITIALIZATION & SOUND SETUP ---
BASE_DIR = os.path.dirname(os.path.abspath(__file__))
ALARM_PATH = os.path.join(BASE_DIR, "alarm.wav")

mixer.init()
if os.path.exists(ALARM_PATH):
    alarm_sound = mixer.Sound(ALARM_PATH)
    print("✅ Alarm sound loaded successfully!")
else:
    print(f"❌ Error: Could not find alarm.wav at {ALARM_PATH}")

# --- 2. CONFIG & THRESHOLDS ---
API_URL = "http://localhost/neuroguard_api/log_alert.php"
DRIVER_ID = 1
COOLDOWN = 10  # Seconds between database logs for the same alert type
last_alert_times = {"Drowsy": 0, "Yawn": 0, "Distracted": 0}

# Detection Sensitivities
EYE_THRESH = 0.25
MOUTH_THRESH = 0.5
GAZE_MIN = 0.30  # Horizontal "Safe Zone" Start
GAZE_MAX = 0.70  # Horizontal "Safe Zone" End

# Temporal Thresholds (The "Smart" Logic)
DROWSY_WAIT_TIME = 1.5    # Must be closed for 1.5s to trigger
DISTRACT_WAIT_TIME = 2.0  # Must look away for 2s to trigger

# Tracking States
drowsy_start_time = 0
IS_EYE_CLOSED = False

distract_start_time = 0
IS_DISTRACTED = False

# --- 3. SESSION & DATABASE SYNC ---
def get_new_session(driver_id):
    url = "http://localhost/neuroguard_api/start_session.php"
    data = urllib.parse.urlencode({"driver_id": driver_id}).encode("utf-8")
    try:
        req = urllib.request.Request(url, data=data)
        with urllib.request.urlopen(req) as resp:
            new_id = resp.read().decode('utf-8').strip()
            print(f"🚀 Started New Session ID: {new_id}")
            return int(new_id)
    except:
        print("⚠️ Failed to get session, defaulting to 1")
        return 1

SESSION_ID = get_new_session(DRIVER_ID)

def sync_to_db(alert_type: str):
    global last_alert_times
    now = time.time()
    if (now - last_alert_times.get(alert_type, 0)) <= COOLDOWN:
        return

    payload = {"driver_id": DRIVER_ID, "session_id": SESSION_ID, "alert_type": alert_type}
    try:
        data = urllib.parse.urlencode(payload).encode("utf-8")
        req = urllib.request.Request(API_URL, data=data)
        with urllib.request.urlopen(req, timeout=1.0) as resp:
            print(f"DB Sync [{alert_type}]: {resp.read().decode('utf-8', errors='ignore')}")
        last_alert_times[alert_type] = now
    except:
        pass

# --- 4. MATH & CALCULATION ---
def eye_aspect_ratio(eye):
    A = dist.euclidean(eye[1], eye[5])
    B = dist.euclidean(eye[2], eye[4])
    C = dist.euclidean(eye[0], eye[3])
    return (A + B) / (2.0 * C)

def get_horizontal_ratio(iris, corner_left, corner_right):
    """Calculates iris position relative to eye corners (0.0 to 1.0)."""
    if (corner_right.x - corner_left.x) == 0: return 0.5
    return (iris.x - corner_left.x) / (corner_right.x - corner_left.x)

# --- 5. MEDIAPIPE SETUP ---
MODEL_NAME = "face_landmarker.task"
MODEL_PATH = os.path.join(BASE_DIR, MODEL_NAME)

from mediapipe.tasks.python.vision.face_landmarker import FaceLandmarker
from mediapipe.tasks.python.vision.core.image import Image, ImageFormat
landmarker = FaceLandmarker.create_from_model_path(MODEL_PATH)

# Landmarks (468-pt mesh)
LEFT_EYE = [362, 385, 387, 263, 373, 380]
RIGHT_EYE = [33, 160, 158, 133, 153, 144]
MOUTH = [13, 14, 78, 308]
L_IRIS, R_IRIS = 468, 473
L_IN, L_OUT = 362, 263
R_IN, R_OUT = 133, 33

# --- 6. MAIN DETECTION LOOP ---
cap = cv2.VideoCapture(0)

while True:
    ret, frame = cap.read()
    if not ret: break

    h, w, _ = frame.shape
    rgb = cv2.cvtColor(frame, cv2.COLOR_BGR2RGB)
    mp_image = Image(ImageFormat.SRGB, rgb)
    results = landmarker.detect(mp_image)

    if results.face_landmarks:
        landmarks = results.face_landmarks[0]
        coords = [(int(lm.x * w), int(lm.y * h)) for lm in landmarks]

        # A) Calculate Metrics
        ear = (eye_aspect_ratio([coords[i] for i in LEFT_EYE]) + 
               eye_aspect_ratio([coords[i] for i in RIGHT_EYE])) / 2.0
        mar = dist.euclidean(coords[MOUTH[0]], coords[MOUTH[1]]) / \
              dist.euclidean(coords[MOUTH[2]], coords[MOUTH[3]])
        avg_gaze = (get_horizontal_ratio(landmarks[L_IRIS], landmarks[L_IN], landmarks[L_OUT]) + 
                    get_horizontal_ratio(landmarks[R_IRIS], landmarks[R_OUT], landmarks[R_IN])) / 2.0

        # Debug Console
        print(f"Gaze: {avg_gaze:.2f} | EAR: {ear:.2f} | MAR: {mar:.2f}")

        # --- B) ALERT LOGIC ---
        
        # 1. SMART DROWSY CHECK
        if ear < EYE_THRESH:
            if not IS_EYE_CLOSED:
                drowsy_start_time = time.time()
                IS_EYE_CLOSED = True
            
            if (time.time() - drowsy_start_time) > DROWSY_WAIT_TIME:
                cv2.putText(frame, "DROWSY!", (10, 40), cv2.FONT_HERSHEY_SIMPLEX, 1, (0,0,255), 2)
                sync_to_db("Drowsy")
                if not mixer.get_busy(): alarm_sound.play(-1)
        else:
            IS_EYE_CLOSED = False

        # 2. YAWN (Immediate Sound)
        active_yawn = False
        if mar > MOUTH_THRESH:
            active_yawn = True
            cv2.putText(frame, "YAWNING!", (10, 80), cv2.FONT_HERSHEY_SIMPLEX, 1, (0,255,255), 2)
            sync_to_db("Yawn")
            if not mixer.get_busy(): alarm_sound.play(-1)

        # 3. SMART DISTRACTION CHECK
        if avg_gaze < GAZE_MIN or avg_gaze > GAZE_MAX:
            if not IS_DISTRACTED:
                distract_start_time = time.time()
                IS_DISTRACTED = True
            
            if (time.time() - distract_start_time) > DISTRACT_WAIT_TIME:
                cv2.putText(frame, "LOOKING AWAY!", (10, 120), cv2.FONT_HERSHEY_SIMPLEX, 1, (255,165,0), 2)
                sync_to_db("Distracted")
                if not mixer.get_busy(): alarm_sound.play(-1)
        else:
            IS_DISTRACTED = False

        # --- C) ALARM CONTROL ---
        # Stop alarm if eyes are open (or closed < wait time) AND gaze is centered (or looking away < wait time)
        active_drowsy = IS_EYE_CLOSED and (time.time() - drowsy_start_time) > DROWSY_WAIT_TIME
        active_distract = IS_DISTRACTED and (time.time() - distract_start_time) > DISTRACT_WAIT_TIME
        
       # --- ALARM CONTROL ---
        # Stop only if NO active safety threats are detected
        if not active_drowsy and not active_yawn and not active_distract:
            alarm_sound.stop()
            
    cv2.imshow("NeuroGuard Pro Engine", frame)
    if cv2.waitKey(1) & 0xFF == ord("q"): break

cap.release()
cv2.destroyAllWindows()
print(f"🏁 Session {SESSION_ID} closed.")