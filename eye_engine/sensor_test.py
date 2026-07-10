import os
import time
import threading
import urllib.parse
import urllib.request

import cv2
import numpy as np
from scipy.spatial import distance as dist
from pygame import mixer

# ─────────────────────────────────────────────────────────────────────────────
#  1.  SOUND SETUP
# ─────────────────────────────────────────────────────────────────────────────
BASE_DIR   = os.path.dirname(os.path.abspath(__file__))
ALARM_PATH = os.path.join(BASE_DIR, "alarm.wav")

mixer.init()
alarm_sound = None
if os.path.exists(ALARM_PATH):
    alarm_sound = mixer.Sound(ALARM_PATH)
    print("✅  Alarm loaded")
else:
    print(f"❌  alarm.wav not found at {ALARM_PATH}")

# ─────────────────────────────────────────────────────────────────────────────
#  2.  CONFIG & THRESHOLDS  (kept in sync with engine.js)
# ─────────────────────────────────────────────────────────────────────────────
API_URL   = "http://localhost/neuroguard_api/log_alert.php"
DRIVER_ID = 1

# Detection sensitivities
EYE_THRESH   = 0.23
MOUTH_THRESH = 0.60
GAZE_MIN, GAZE_MAX = 0.30, 0.70   # horizontal safe-zone
TURN_MIN, TURN_MAX = 0.35, 0.65   # head-turn safe-zone

# Condition must persist this long before it counts
DROWSY_WAIT_TIME   = 1.0   # seconds
YAWN_WAIT_TIME     = 0.7
DISTRACT_WAIT_TIME = 1.5

# ── Alert state-machine (direct port from engine.js) ──────────────────────
ALERT_LATCH_TIME   = 2.0   # alert stays active this long after condition clears
RETRIGGER_COOLDOWN = 5.0   # silence period before same alert can fire again
SYNC_COOLDOWN      = 10.0  # minimum gap between DB writes for the same alert type

# ─────────────────────────────────────────────────────────────────────────────
#  3.  SESSION & DATABASE SYNC
# ─────────────────────────────────────────────────────────────────────────────
def get_new_session(driver_id: int) -> int:
    url  = "http://localhost/neuroguard_api/start_session.php"
    # Fallback if neuroguard_api is not the right path. We'll use relative path if possible, but python needs absolute.
    # We will assume localhost is right for the python script for now.
    data = urllib.parse.urlencode({"driver_id": driver_id}).encode()
    req = urllib.request.Request(url, data=data, headers={'X-API-Key': 'NgPro2026_xYz98!'})
    try:
        with urllib.request.urlopen(req, timeout=2) as resp:
            new_id = resp.read().decode().strip()
            print(f"🚀  Session started — ID: {new_id}")
            return int(new_id)
    except Exception as e:
        print(f"⚠️   Could not reach session API ({e}) — defaulting to session 1")
        return 1

SESSION_ID = get_new_session(DRIVER_ID)

# ── isSyncing equivalent: threading.Lock (non-blocking acquire) ──────────
_sync_lock       = threading.Lock()
_last_sync_times = {"Drowsy": 0.0, "Yawn": 0.0, "Distracted": 0.0}

def sync_to_db(alert_type: str) -> None:
    """
    Fire-and-forget DB write.
    Skipped immediately if:
      • another write is already in-flight  (_sync_lock busy)
      • this alert type is still in its SYNC_COOLDOWN window
    Mirrors the isSyncing + lastSyncTimes guard in engine.js syncToDB().
    """
    now = time.time()
    if now - _last_sync_times.get(alert_type, 0.0) < SYNC_COOLDOWN:
        return
    if not _sync_lock.acquire(blocking=False):   # strict lock — skip if busy
        return

    def _worker() -> None:
        try:
            _last_sync_times[alert_type] = time.time()
            payload = {
                "driver_id":  DRIVER_ID,
                "session_id": SESSION_ID,
                "alert_type": alert_type,
            }
            data = urllib.parse.urlencode(payload).encode()
            req  = urllib.request.Request(API_URL, data=data, headers={'X-API-Key': 'NgPro2026_xYz98!'})
            with urllib.request.urlopen(req, timeout=1.5) as resp:
                print(f"[DB] {alert_type} → {resp.read().decode(errors='ignore')}")
        except Exception as exc:
            print(f"[DB] Sync error: {exc}")
        finally:
            _sync_lock.release()

    threading.Thread(target=_worker, daemon=True).start()

# ─────────────────────────────────────────────────────────────────────────────
#  4.  MATH HELPERS
# ─────────────────────────────────────────────────────────────────────────────
def eye_aspect_ratio(eye_coords: list) -> float:
    A = dist.euclidean(eye_coords[1], eye_coords[5])
    B = dist.euclidean(eye_coords[2], eye_coords[4])
    C = dist.euclidean(eye_coords[0], eye_coords[3])
    return (A + B) / (2.0 * C) if C != 0 else 0.0


def get_horizontal_ratio(iris, corner1, corner2) -> float:
    """
    Returns iris position in [0.0 – 1.0] relative to the eye corners.

    FIX: uses min/max to determine left/right, so corner argument order
    no longer matters — eliminates the swap bug in the original code and
    mirrors the engine.js getHorizontalRatio() rewrite exactly.
    """
    left_x  = min(corner1.x, corner2.x)
    right_x = max(corner1.x, corner2.x)
    width   = right_x - left_x
    if width < 0.005:
        return 0.5
    return max(0.0, min(1.0, (iris.x - left_x) / width))

# ─────────────────────────────────────────────────────────────────────────────
#  5.  MEDIAPIPE SETUP  (fixed imports — Tasks Python API)
# ─────────────────────────────────────────────────────────────────────────────
import mediapipe as mp
from mediapipe.tasks.python.core.base_options import BaseOptions
from mediapipe.tasks.python import vision as mp_vision

MODEL_PATH = os.path.join(BASE_DIR, "face_landmarker.task")

_options = mp_vision.FaceLandmarkerOptions(
    base_options=BaseOptions(model_asset_path=MODEL_PATH),
    running_mode=mp_vision.RunningMode.VIDEO,   # VIDEO mode = temporal smoothing
    num_faces=1,
    output_face_blendshapes=False,
)
landmarker = mp_vision.FaceLandmarker.create_from_options(_options)
print("✅  FaceLandmarker loaded")

# Landmark indices — MediaPipe 468-pt mesh + iris extensions
LEFT_EYE  = [362, 385, 387, 263, 373, 380]
RIGHT_EYE = [33,  160, 158, 133, 153, 144]
MOUTH     = [13, 14, 78, 308]
L_IRIS, R_IRIS = 468, 473
L_IN,  L_OUT   = 362, 263    # inner, outer corners of left eye
R_IN,  R_OUT   = 133,  33    # inner, outer corners of right eye
NOSE           = 1
LEFT_F         = 234          # left face silhouette
RIGHT_F        = 454          # right face silhouette

# ─────────────────────────────────────────────────────────────────────────────
#  6.  MUTABLE DETECTION STATE
# ─────────────────────────────────────────────────────────────────────────────
# Temporal tracking (same as before)
drowsy_start_time  = 0.0;  IS_EYE_CLOSED  = False
yawn_start_time    = 0.0;  IS_YAWN_OPEN   = False
distract_start_time = 0.0; IS_DISTRACTED  = False

# ── Alert state-machine (ported from engine.js) ───────────────────────────
active_alert   = None          # currently latched alert type, or None
alert_end_time = 0.0           # wall-clock time when latch expires
cooldowns      = {"Drowsy": 0.0, "Yawn": 0.0, "Distracted": 0.0}

# ─────────────────────────────────────────────────────────────────────────────
#  7.  VISUAL HELPERS
# ─────────────────────────────────────────────────────────────────────────────
# Alert banner styles  {type: (label, bgr_colour)}
ALERT_STYLE = {
    "Drowsy":     ("⚠  DROWSY!",        (0,   0,   220)),
    "Yawn":       ("⚠  YAWNING!",       (0,   200, 255)),
    "Distracted": ("⚠  LOOKING AWAY!",  (220, 130,  0)),
}

CLR_GREEN = (80,  220, 80)
CLR_RED   = (60,  60,  240)
CLR_GRAY  = (160, 160, 160)
CLR_WHITE = (255, 255, 255)
CLR_ICE   = (220, 200, 80)   # cooldown indicator (blue-ish)

FONT = cv2.FONT_HERSHEY_SIMPLEX

# ── Night-vision resources (created once, reused every frame) ─────────────
# CLAHE: adaptive contrast enhancement — far better than a flat alpha/beta
# lift for faces in low-light where the histogram is clumped in the darks.
_clahe       = cv2.createCLAHE(clipLimit=2.5, tileGridSize=(8, 8))
_nv_vignette = None   # built lazily on first NV frame; cached afterward


def apply_night_vision(frame: np.ndarray) -> np.ndarray:
    """
    Full NVG-style filter pipeline — mirrors the web dashboard CSS chain:

        brightness(1.5)  contrast(1.2)  sepia(100%)
        hue-rotate(90deg)  saturate(3)

    Processing steps
    ────────────────
    1. Grayscale   — desaturate (sepia collapses to mono base)
    2. CLAHE       — adaptive contrast; recovers shadow detail that a simple
                     alpha-boost would clip or lose
    3. Brightness  — convertScaleAbs alpha=1.5  (brightness(1.5))
       Contrast    — convertScaleAbs beta=15     (contrast(1.2))
    4. Green merge — B=0, G=enhanced, R=0
                     (sepia + hue-rotate(90deg) + saturate(3) → green dominant)
    5. Scanlines   — every other row dimmed 15 %  (classic phosphor CRT look)
    6. Vignette    — radial darkening toward edges (built once, cached)
    """
    global _nv_vignette
    h, w = frame.shape[:2]

    # ── Build / refresh vignette mask (lazy, cached per resolution) ──────
    if _nv_vignette is None or _nv_vignette.shape != (h, w):
        cy, cx       = h / 2.0, w / 2.0
        Y, X         = np.ogrid[:h, :w]
        radius       = np.sqrt(((X - cx) / cx) ** 2 + ((Y - cy) / cy) ** 2)
        _nv_vignette = np.clip(1.0 - radius * 0.55, 0.0, 1.0).astype(np.float32)

    # ── Steps 1 + 2: grayscale → CLAHE ───────────────────────────────────
    gray     = cv2.cvtColor(frame, cv2.COLOR_BGR2GRAY)
    enhanced = _clahe.apply(gray)

    # ── Step 3: brightness(1.5) + contrast(1.2) ──────────────────────────
    enhanced = cv2.convertScaleAbs(enhanced, alpha=1.5, beta=15)

    # ── Step 4: green phosphor merge ─────────────────────────────────────
    zeros = np.zeros_like(enhanced)
    nv    = cv2.merge([zeros, enhanced, zeros])

    # ── Step 5: scanlines — dim every other row by 15 % ──────────────────
    nv[::2] = (nv[::2] * 0.85).astype(np.uint8)

    # ── Step 6: vignette ─────────────────────────────────────────────────
    nv = (nv.astype(np.float32) * _nv_vignette[:, :, np.newaxis]).astype(np.uint8)

    return nv


def draw_hud(frame: np.ndarray, h: int,
             ear: float, mar: float, gaze: float, turn: float,
             now: float) -> None:
    """
    Bottom-left HUD panel — mirrors engine.js engineStatus innerHTML block.
    Each metric shown green when safe, red when alarming.
    Cooldown ❄ indicator shown per-alert-type when active.
    """
    rows = [
        ("EYES :", ear,  ear < EYE_THRESH),
        ("MOUTH:", mar,  mar > MOUTH_THRESH),
        ("GAZE :", gaze, gaze < GAZE_MIN or gaze > GAZE_MAX),
        ("TURN :", turn, turn < TURN_MIN  or turn > TURN_MAX),
    ]
    for i, (label, val, warn) in enumerate(rows):
        y   = h - 14 - (len(rows) - 1 - i) * 22
        col = CLR_RED if warn else CLR_GREEN
        cv2.putText(frame, label,        (12,  y), FONT, 0.50, CLR_GRAY,  1)
        cv2.putText(frame, f"{val:.2f}", (100, y), FONT, 0.50, col,       2)

    # ❄ per-type cooldown countdown (right side)
    cd_y = h - 14
    for atype, t in cooldowns.items():
        remaining = t - now
        if remaining > 0:
            txt = f"[{atype} ❄ {remaining:.1f}s]"
            cv2.putText(frame, txt, (frame.shape[1] - 200, cd_y),
                        FONT, 0.40, CLR_ICE, 1)
            cd_y -= 18


def draw_alert_banner(frame: np.ndarray, w: int, alert_type: str) -> None:
    label, colour = ALERT_STYLE[alert_type]
    cv2.rectangle(frame, (0, 0), (w, 58), colour, -1)
    cv2.putText(frame, label, (w // 2 - 155, 40),
                cv2.FONT_HERSHEY_DUPLEX, 1.2, CLR_WHITE, 2)

# ─────────────────────────────────────────────────────────────────────────────
#  8.  MONOTONIC TIMESTAMP HELPER  (VIDEO mode requires strictly increasing ms)
# ─────────────────────────────────────────────────────────────────────────────
_last_ts_ms = 0

def next_timestamp_ms() -> int:
    global _last_ts_ms
    ts = int(time.time() * 1000)
    if ts <= _last_ts_ms:
        ts = _last_ts_ms + 1
    _last_ts_ms = ts
    return ts

# ─────────────────────────────────────────────────────────────────────────────
#  9.  MAIN DETECTION LOOP
# ─────────────────────────────────────────────────────────────────────────────
cap     = cv2.VideoCapture(0)
nv_mode = False

print("\n🟢  NeuroGuard Pro running — [Q] Quit   [N] Night-vision\n")

while True:
    ret, frame = cap.read()
    if not ret:
        break

    now       = time.time()
    h, w      = frame.shape[:2]
    rgb       = cv2.cvtColor(frame, cv2.COLOR_BGR2RGB)
    mp_img    = mp.Image(image_format=mp.ImageFormat.SRGB, data=rgb)
    results   = landmarker.detect_for_video(mp_img, next_timestamp_ms())

    # ── FACE DETECTED ─────────────────────────────────────────────────────
    if results.face_landmarks:
        lm     = results.face_landmarks[0]
        coords = [(int(l.x * w), int(l.y * h)) for l in lm]

        # ── A) COMPUTE METRICS ─────────────────────────────────────────────
        ear = (eye_aspect_ratio([coords[i] for i in LEFT_EYE]) +
               eye_aspect_ratio([coords[i] for i in RIGHT_EYE])) / 2.0

        mar = (dist.euclidean(coords[MOUTH[0]], coords[MOUTH[1]]) /
               dist.euclidean(coords[MOUTH[2]], coords[MOUTH[3]]))

        # Gaze — fixed: min/max inside get_horizontal_ratio; order irrelevant
        gaze_l   = get_horizontal_ratio(lm[L_IRIS], lm[L_IN], lm[L_OUT])
        gaze_r   = get_horizontal_ratio(lm[R_IRIS], lm[R_IN], lm[R_OUT])
        avg_gaze = (gaze_l + gaze_r) / 2.0

        nose_x, left_f_x, right_f_x = lm[NOSE].x, lm[LEFT_F].x, lm[RIGHT_F].x
        span = right_f_x - left_f_x
        turn = ((nose_x - left_f_x) / span) if span != 0 else 0.5

        # ── B) TEMPORAL DETECTION with PRIORITY ───────────────────────────
        #
        #  Priority order: Drowsy > Yawn > Distracted
        #  All three conditions are tracked independently.
        #  Only the highest-priority active condition is reported as
        #  detected_type — prevents the overwrite bug from engine.js.
        #
        detected_type = None

        # Priority 1 — Drowsy
        if ear < EYE_THRESH:
            if not IS_EYE_CLOSED:
                drowsy_start_time = now
                IS_EYE_CLOSED = True
            if (now - drowsy_start_time) > DROWSY_WAIT_TIME:
                detected_type = "Drowsy"
        else:
            IS_EYE_CLOSED = False

        # Priority 2 — Yawn  (tracked regardless; only reports if slot free)
        if mar > MOUTH_THRESH:
            if not IS_YAWN_OPEN:
                yawn_start_time = now
                IS_YAWN_OPEN = True
            if detected_type is None and (now - yawn_start_time) > YAWN_WAIT_TIME:
                detected_type = "Yawn"
        else:
            IS_YAWN_OPEN = False

        # Priority 3 — Distracted
        is_away = ((avg_gaze < GAZE_MIN or avg_gaze > GAZE_MAX) or
                   (turn     < TURN_MIN  or turn     > TURN_MAX))
        if is_away:
            if not IS_DISTRACTED:
                distract_start_time = now
                IS_DISTRACTED = True
            if detected_type is None and (now - distract_start_time) > DISTRACT_WAIT_TIME:
                detected_type = "Distracted"
        else:
            IS_DISTRACTED = False

        # ── C) ALERT STATE MACHINE  (direct port of engine.js logic) ──────
        #
        #  Step 1 — new alert: fire only if slot is free AND past cooldown
        if detected_type and active_alert is None and now > cooldowns[detected_type]:
            active_alert   = detected_type
            alert_end_time = now + ALERT_LATCH_TIME
            sync_to_db(active_alert)          # ONE-TIME DB write per activation

        #  Step 2 — latch extension: keep pushing end-time while condition persists
        if detected_type == active_alert:
            alert_end_time = now + ALERT_LATCH_TIME

        #  Step 3 — latch expiry: enter per-type retrigger cooldown
        if active_alert and now > alert_end_time:
            cooldowns[active_alert] = now + RETRIGGER_COOLDOWN
            active_alert = None

        # ── D) ALARM AUDIO ─────────────────────────────────────────────────
        if active_alert:
            if alarm_sound and not mixer.get_busy():
                alarm_sound.play(-1)
        else:
            if alarm_sound:
                alarm_sound.stop()

        # ── E) VISUAL OVERLAY ──────────────────────────────────────────────
        if active_alert:
            draw_alert_banner(frame, w, active_alert)

        draw_hud(frame, h, ear, mar, avg_gaze, turn, now)

    # ── NO FACE DETECTED ──────────────────────────────────────────────────
    else:
        # Mirrors engine.js: stop alarm + clear alert when face leaves frame
        if alarm_sound:
            alarm_sound.stop()
        active_alert = None
        cv2.putText(frame, "No face detected", (12, 40), FONT, 0.7, CLR_GRAY, 2)

    # ── NIGHT VISION FILTER ───────────────────────────────────────────────
    if nv_mode:
        frame = apply_night_vision(frame)

    cv2.imshow("NeuroGuard Pro Engine", frame)
    key = cv2.waitKey(1) & 0xFF
    if key == ord("q"):
        break
    elif key == ord("n"):
        nv_mode = not nv_mode
        print(f"Night vision: {'ON' if nv_mode else 'OFF'}")

# ─────────────────────────────────────────────────────────────────────────────
#  CLEANUP
# ─────────────────────────────────────────────────────────────────────────────
cap.release()
cv2.destroyAllWindows()
landmarker.close()
if alarm_sound:
    alarm_sound.stop()
print(f"\n🏁  Session {SESSION_ID} closed.")
