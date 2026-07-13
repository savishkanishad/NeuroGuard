import {
  FaceLandmarker,
  FilesetResolver,
  DrawingUtils
} from "https://cdn.jsdelivr.net/npm/@mediapipe/tasks-vision/vision_bundle.js";

const video          = document.getElementById("webcam");
const canvasElement  = document.getElementById("output_canvas");
const canvasCtx      = canvasElement.getContext("2d");
const alertBanner    = document.getElementById("alert-banner");
const engineStatus   = document.getElementById("engine-status");
const alertStatus    = document.getElementById("alert-status");
const loadingOverlay = document.getElementById("loading-overlay");
const nvToggle       = document.getElementById("nv-toggle");

if (nvToggle) {
  nvToggle.addEventListener("click", () => {
    const wrapper = document.getElementById("camera-wrapper");
    if (wrapper) wrapper.classList.toggle("night-vision");
    nvToggle.classList.toggle("active");
  });
}

let faceLandmarker;
let runningMode   = "VIDEO";
let lastVideoTime    = -1;
let results          = undefined;
let _lastDetectedTs  = -1;   // strictly-increasing timestamp guard for MediaPipe

// ─────────────────────────────────────────────────────────────────────────────
//  CONFIG & THRESHOLDS  (kept in sync with sensor_test.py)
// ─────────────────────────────────────────────────────────────────────────────
const MOUTH_THRESH        = 0.60;
const GAZE_MIN            = 0.30;
const GAZE_MAX            = 0.70;
const NOD_THRESH          = 0.60;   // pitch ratio — forward nod / microsleep
const TILT_THRESH         = 0.08;   // normalised eye-corner asymmetry — head roll
const DROWSY_WAIT_TIME    = 1000;   // ms
const YAWN_WAIT_TIME      = 700;
const DISTRACT_WAIT_TIME  = 1500;
const NOD_WAIT_TIME       = 1200;   // microsleep nod must be sustained
const SYNC_COOLDOWN       = 10000;
const ALERT_LATCH_TIME    = 2000;
const RETRIGGER_COOLDOWN  = 5000;
const CALIBRATION_DURATION = 10000; // ms

// ── Calibrated eye threshold (set after calibration phase) ───────────────────
let eyeThresh = 0.23;  // static fallback until calibration completes

// ─────────────────────────────────────────────────────────────────────────────
//  ROLLING BUFFER
//  A single bad frame can shift the mean by at most 1/maxLen — no single
//  corrupted frame can spike a metric across its threshold when the rest
//  of the window is healthy.
// ─────────────────────────────────────────────────────────────────────────────
class RollingBuffer {
  constructor(maxLen) {
    this._buf    = [];
    this._maxLen = maxLen;
  }
  push(val) {
    this._buf.push(val);
    if (this._buf.length > this._maxLen) this._buf.shift();
  }
  mean() {
    if (this._buf.length === 0) return 0;
    return this._buf.reduce((a, b) => a + b, 0) / this._buf.length;
  }
}

// One buffer per metric
const bufEar   = new RollingBuffer(5);   // ~167 ms @30 fps
const bufMar   = new RollingBuffer(8);   // ~267 ms
const bufGaze  = new RollingBuffer(6);   // ~200 ms
const bufTurn  = new RollingBuffer(6);   // ~200 ms
const bufPitch = new RollingBuffer(5);   // ~167 ms
const bufRoll  = new RollingBuffer(5);   // ~167 ms

// ─────────────────────────────────────────────────────────────────────────────
//  MUTABLE DETECTION STATE
// ─────────────────────────────────────────────────────────────────────────────
let drowsyStartTime   = 0; let isEyeClosed  = false;
let yawnStartTime     = 0; let isYawnOpen   = false;
let distractStartTime = 0; let isDistracted = false;
let nodStartTime      = 0; let isNodding    = false;

let alertHistory = []; // Track {timestamp, type} for escalation

let activeAlert  = null;
let alertEndTime = 0;
let isSyncing    = false;
let cooldowns    = { Drowsy: 0, Yawn: 0, Distracted: 0, Microsleep: 0 };
let lastSyncTimes = { Drowsy: 0, Yawn: 0, Distracted: 0, Microsleep: 0 };

// ─────────────────────────────────────────────────────────────────────────────
//  AUDIO
// ─────────────────────────────────────────────────────────────────────────────
const alarm = new Audio('alarm.wav');
alarm.loop  = true;

// ─────────────────────────────────────────────────────────────────────────────
//  GEOLOCATION — Real GPS tracking
//
//  currentAccuracy is navigator.geolocation's coords.accuracy: a 68%-
//  confidence radius in meters, not a fixed error bound. Typical values:
//  ~5-20m outdoors with GPS/GNSS chips (phones), ~20-100m indoors/Wi-Fi
//  positioning, ~1-5km for IP-based estimates. We always keep and publish
//  the newest fix (a moving vehicle needs current position, not just the
//  single most-accurate one seen), but we surface the accuracy figure
//  everywhere downstream (UI status card, alert rows, live map circle) so
//  low-confidence fixes are visibly flagged rather than presented as exact.
// ─────────────────────────────────────────────────────────────────────────────
let currentLat      = null;
let currentLng      = null;
let currentAccuracy = null;   // meters, or null if unknown (e.g. old browsers)
let lastFixAt        = 0;     // Date.now() of the last accepted fix
let gpsWatchId       = null;
let locationPromise  = null;
let locationSource   = 'pending';
let livePingTimer    = null;
window.gpsStatus  = 'Requesting…';

const LIVE_PING_INTERVAL_MS = 5000;   // cadence for the "heartbeat" position ping
const WATCH_STALE_MS        = 25000;  // if the watch goes this long without a fix, nudge it

function publishLocation(lat, lng, source, accuracy) {
  currentLat = lat;
  currentLng = lng;
  locationSource = source;
  currentAccuracy = (typeof accuracy === 'number' && isFinite(accuracy)) ? accuracy : null;
  lastFixAt = Date.now();

  const accLabel = currentAccuracy !== null ? `, ±${Math.round(currentAccuracy)}m` : '';
  window.gpsStatus = `${currentLat.toFixed(5)}, ${currentLng.toFixed(5)} (${source}${accLabel})`;

  window.dispatchEvent(new CustomEvent('ng:location', {
    detail: { lat: currentLat, lng: currentLng, source, accuracy: currentAccuracy }
  }));
}

function startGeolocation() {
  if (!navigator.geolocation) {
    window.gpsStatus = 'Not Supported';
    window.dispatchEvent(new CustomEvent('ng:location', { detail: { error: 'Geolocation not supported by this browser' } }));
    return;
  }
  window.gpsStatus = 'Acquiring…';

  gpsWatchId = navigator.geolocation.watchPosition(
    (pos) => {
      publishLocation(pos.coords.latitude, pos.coords.longitude, 'gps', pos.coords.accuracy);
    },
    (err) => {
      window.gpsStatus = 'Denied / Error';
      console.warn('[GPS] Error:', err.message);
      window.dispatchEvent(new CustomEvent('ng:location', { detail: { error: err.message } }));
    },
    { enableHighAccuracy: true, maximumAge: 5000, timeout: 10000 }
  );

  // watchPosition should keep firing on its own, but on some mobile browsers
  // it can silently go quiet after the tab is backgrounded/foregrounded. If
  // we haven't heard anything in a while, force a fresh single-shot fix so a
  // long drive doesn't end up stuck showing a stale first position.
  setInterval(() => {
    if (Date.now() - lastFixAt > WATCH_STALE_MS) {
      navigator.geolocation.getCurrentPosition(
        (pos) => publishLocation(pos.coords.latitude, pos.coords.longitude, 'gps', pos.coords.accuracy),
        (err) => console.warn('[GPS] Stale-watch nudge failed:', err.message),
        { enableHighAccuracy: true, maximumAge: 0, timeout: 10000 }
      );
    }
  }, WATCH_STALE_MS);

  // Push the driver's live position to the server on a fixed cadence so the
  // Live Fleet Map shows real-time movement, not just the positions attached
  // to alerts (which can be minutes apart, or never happen at all on a clean
  // drive). Runs independently of the AI detection loop.
  if (livePingTimer) clearInterval(livePingTimer);
  livePingTimer = setInterval(pingLivePosition, LIVE_PING_INTERVAL_MS);

  window.addEventListener('beforeunload', () => {
    if (gpsWatchId !== null) navigator.geolocation.clearWatch(gpsWatchId);
    if (livePingTimer) clearInterval(livePingTimer);
  }, { once: true });
}

// Lightweight heartbeat: sends whatever the freshest known position is to
// update_location.php. Separate table from `alerts` — this is "where is the
// driver right now", not "what happened at the moment of an alert".
async function pingLivePosition() {
  if (currentLat === null || currentLng === null) return;
  if (!window.SESSION_ID) return;

  const formData = new FormData();
  formData.append('driver_id',  window.DRIVER_ID  || '1');
  formData.append('session_id', window.SESSION_ID || '1');
  formData.append('latitude',   currentLat.toFixed(6));
  formData.append('longitude',  currentLng.toFixed(6));
  if (currentAccuracy !== null) formData.append('accuracy', currentAccuracy.toFixed(2));
  formData.append('location_source', (locationSource === 'gps' || locationSource === 'ip') ? locationSource : 'fallback');
  formData.append('api_key', 'NgPro2026_xYz98!');

  try {
    await fetch('update_location.php', { method: 'POST', body: formData });
  } catch (e) {
    console.warn('[NeuroGuard] Live position ping failed:', e);
  }
}

async function resolveLocation() {
  if (currentLat !== null && currentLng !== null) {
    return { lat: currentLat, lng: currentLng, source: locationSource, accuracy: currentAccuracy };
  }

  if (locationPromise) {
    return locationPromise;
  }

  locationPromise = (async () => {
    try {
      if (navigator.geolocation) {
        const pos = await new Promise((resolve, reject) => {
          navigator.geolocation.getCurrentPosition(resolve, reject, {
            enableHighAccuracy: true,
            maximumAge: 10000,
            timeout: 8000
          });
        });
        publishLocation(pos.coords.latitude, pos.coords.longitude, 'gps', pos.coords.accuracy);
        return { lat: pos.coords.latitude, lng: pos.coords.longitude, source: 'gps', accuracy: pos.coords.accuracy };
      }
    } catch (err) {
      console.warn('[GPS] Could not resolve live coordinates, trying IP fallback:', err.message);
    }

    try {
      const response = await fetch('https://ipapi.co/json/');
      const data = await response.json();
      if (data && data.latitude && data.longitude) {
        // ipapi doesn't report a real accuracy figure — city-level IP geolocation
        // is reliably only accurate to a few kilometers, so we tag it with a
        // nominal 5km radius rather than implying GPS-grade precision.
        const IP_ACCURACY_M = 5000;
        publishLocation(parseFloat(data.latitude), parseFloat(data.longitude), 'ip', IP_ACCURACY_M);
        return { lat: parseFloat(data.latitude), lng: parseFloat(data.longitude), source: 'ip', accuracy: IP_ACCURACY_M };
      }
    } catch (ipErr) {
      console.warn('[GPS] IP fallback unavailable:', ipErr);
    }

    const FALLBACK_ACCURACY_M = 20000; // 20km — this is a guess, not a fix
    const fallbackLat = 6.9271 + (Math.random() - 0.5) * 0.05;
    const fallbackLng = 79.8612 + (Math.random() - 0.5) * 0.05;
    publishLocation(fallbackLat, fallbackLng, 'fallback', FALLBACK_ACCURACY_M);
    return { lat: fallbackLat, lng: fallbackLng, source: 'fallback', accuracy: FALLBACK_ACCURACY_M };
  })();

  try {
    return await locationPromise;
  } finally {
    locationPromise = null;
  }
}

// ─────────────────────────────────────────────────────────────────────────────
//  INITIALIZE MEDIAPIPE
// ─────────────────────────────────────────────────────────────────────────────
async function initialize() {
  const filesetResolver = await FilesetResolver.forVisionTasks(
    "https://cdn.jsdelivr.net/npm/@mediapipe/tasks-vision/wasm"
  );
  faceLandmarker = await FaceLandmarker.createFromOptions(filesetResolver, {
    baseOptions: {
      modelAssetPath: `https://storage.googleapis.com/mediapipe-models/face_landmarker/face_landmarker/float16/1/face_landmarker.task`,
      delegate: "GPU"
    },
    outputFaceBlendshapes: true,
    runningMode: runningMode,
    numFaces: 1
  });

  startCamera();
}

function startCamera() {
  navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' } })
    .then((stream) => {
      video.srcObject = stream;
      video.addEventListener("loadeddata", () => {
        loadingOverlay.style.display = "none";
        startGeolocation();        // start real GPS alongside AI engine
        runCalibration();          // calibrate before entering main loop
      });
    })
    .catch(e => {
      engineStatus.textContent = "Camera Error";
      console.error(e);
    });
}

// ─────────────────────────────────────────────────────────────────────────────
//  MATH HELPERS
// ─────────────────────────────────────────────────────────────────────────────
function euclidDist(p1, p2) {
  const x = (p1.x - p2.x) * video.videoWidth;
  const y = (p1.y - p2.y) * video.videoHeight;
  return Math.sqrt(x * x + y * y);
}

function calculateEAR(eyeIndices, landmarks) {
  const eye = eyeIndices.map(i => landmarks[i]);
  const A   = euclidDist(eye[1], eye[5]);
  const B   = euclidDist(eye[2], eye[4]);
  const C   = euclidDist(eye[0], eye[3]);
  return (A + B) / (2.0 * C);
}

function getHorizontalRatio(iris, corner1, corner2) {
  if (!iris || !corner1 || !corner2) return 0.5;
  const leftX  = Math.min(corner1.x, corner2.x);
  const rightX = Math.max(corner1.x, corner2.x);
  const width  = rightX - leftX;
  if (width < 0.005) return 0.5;
  return Math.max(0, Math.min(1, (iris.x - leftX) / width));
}

function headPitch(landmarks) {
  /**
   * Vertical ratio of nose between forehead and chin, [0–1].
   * Normal upright: ~0.45–0.55.  Forward nod (microsleep): rises toward 0.65+.
   * Landmarks: 10 = forehead/glabella, 1 = nose tip, 152 = chin.
   */
  const foreheadY = landmarks[10].y;
  const noseY     = landmarks[1].y;
  const chinY     = landmarks[152].y;
  const span      = chinY - foreheadY;
  if (Math.abs(span) < 0.01) return 0.5;
  return Math.max(0, Math.min(1, (noseY - foreheadY) / span));
}

function headRoll(landmarks) {
  /**
   * Signed vertical asymmetry between outer eye corners, normalised by the
   * horizontal eye span.  Near 0 = level.  |roll| > TILT_THRESH = significant tilt.
   * Outer corners: left eye 263, right eye 33.
   */
  const leftOuterY  = landmarks[263].y;
  const rightOuterY = landmarks[33].y;
  const eyeSpanX    = Math.abs(landmarks[263].x - landmarks[33].x);
  if (eyeSpanX < 0.01) return 0;
  return (leftOuterY - rightOuterY) / eyeSpanX;
}

// ─────────────────────────────────────────────────────────────────────────────
//  DATABASE SYNC
// ─────────────────────────────────────────────────────────────────────────────
async function syncToDB(alertType, severity = "Medium") {
  const now = Date.now();
  if (isSyncing) return;
  if (now - lastSyncTimes[alertType] < SYNC_COOLDOWN) return;

  isSyncing = true;
  lastSyncTimes[alertType] = now;
  console.log(`[NeuroGuard] Sending ${alertType} (${severity}) alert…`);

  const { lat, lng, accuracy } = await resolveLocation();

  const formData = new FormData();
  formData.append('driver_id',  window.DRIVER_ID  || '1');
  formData.append('session_id', window.SESSION_ID || '1');
  formData.append('alert_type', alertType);
  formData.append('severity',   severity);
  formData.append('latitude',   lat.toFixed(6));
  formData.append('longitude',  lng.toFixed(6));
  if (typeof accuracy === 'number' && isFinite(accuracy)) {
    formData.append('accuracy', accuracy.toFixed(2));
  }
  formData.append('location_source', locationSource);
  formData.append('api_key',    'NgPro2026_xYz98!');

  // Broadcast alert for live mini-map in monitor page
  window.dispatchEvent(new CustomEvent('ng:alert', {
    detail: { lat, lng, type: alertType, severity }
  }));

  try {
    const response = await fetch('log_alert.php', { method: 'POST', body: formData });
    const text     = await response.text();
    console.log("[NeuroGuard] Server response:", text);
  } catch (e) {
    console.error("[NeuroGuard] Sync error:", e);
  } finally {
    isSyncing = false;
  }
}

// ─────────────────────────────────────────────────────────────────────────────
//  CALIBRATION PHASE
//  Collects open-eye EAR samples for CALIBRATION_DURATION ms using rAF.
//  Sets eyeThresh = meanEAR × 0.75 before starting the detection loop.
// ─────────────────────────────────────────────────────────────────────────────
function _nextTs() {
  // Returns a strictly-increasing timestamp (ms) safe for MediaPipe VIDEO mode.
  const now = performance.now();
  if (now <= _lastDetectedTs) {
    _lastDetectedTs += 1;
  } else {
    _lastDetectedTs = now;
  }
  return _lastDetectedTs;
}

function runCalibration() {
  const samples   = [];
  const startTime = performance.now();
  engineStatus.textContent = "Calibrating…";

  function calibrationFrame() {
    const elapsed   = performance.now() - startTime;
    const remaining = Math.max(0, CALIBRATION_DURATION - elapsed);

    // Run detection on every rAF tick — do NOT gate on video.currentTime
    // because currentTime stays 0 for the first several frames after loadeddata.
    try {
      const r = faceLandmarker.detectForVideo(video, _nextTs());
      if (r.faceLandmarks && r.faceLandmarks.length > 0) {
        const lm   = r.faceLandmarks[0];
        const earL = calculateEAR([362, 385, 387, 263, 373, 380], lm);
        const earR = calculateEAR([33, 160, 158, 133, 153, 144], lm);
        const ear  = (earL + earR) / 2.0;
        if (ear > 0.15) samples.push(ear);   // discard blinks / eyes-closed frames
      }
    } catch (e) {
      // MediaPipe occasionally throws on the very first frame — ignore silently.
    }

    const sRemaining = (remaining / 1000).toFixed(1);
    engineStatus.innerHTML = `
      <div style="font-family: monospace; font-size: 13px; text-align: left; line-height: 1.9;">
        <span style="color: #10b981; font-weight: 800; letter-spacing: 0.05em;">● CALIBRATING</span><br>
        Look straight ahead<br>
        ⏱ ${sRemaining}s remaining<br>
        Samples: ${samples.length}
      </div>
    `;

    if (elapsed < CALIBRATION_DURATION) {
      window.requestAnimationFrame(calibrationFrame);
    } else {
      // ── Compute personalised threshold ───────────────────────────────
      if (samples.length >= 10) {
        const meanEAR = samples.reduce((a, b) => a + b, 0) / samples.length;
        eyeThresh = parseFloat((meanEAR * 0.75).toFixed(4));
        console.log(`[NeuroGuard] Calibration done — meanEAR=${meanEAR.toFixed(3)}, eyeThresh=${eyeThresh}, samples=${samples.length}`);
      } else {
        eyeThresh = 0.23;   // static fallback
        console.warn(`[NeuroGuard] Calibration: only ${samples.length} samples — using default threshold`);
      }
      engineStatus.textContent = "Live";
      lastVideoTime = -1;   // reset so predictWebcam starts fresh
      window.requestAnimationFrame(predictWebcam);   // hand off to main loop
    }
  }

  window.requestAnimationFrame(calibrationFrame);
}

// ─────────────────────────────────────────────────────────────────────────────
//  MAIN DETECTION LOOP
// ─────────────────────────────────────────────────────────────────────────────
async function predictWebcam() {
  if (lastVideoTime !== video.currentTime) {
    lastVideoTime = video.currentTime;
    results = faceLandmarker.detectForVideo(video, _nextTs());
  }

  canvasCtx.clearRect(0, 0, canvasElement.width, canvasElement.height);

  if (results.faceLandmarks && results.faceLandmarks.length > 0) {
    const landmarks = results.faceLandmarks[0];
    const now       = Date.now();

    // ── 1. COMPUTE RAW METRICS ─────────────────────────────────────────────
    const rawEar = (calculateEAR([362, 385, 387, 263, 373, 380], landmarks) +
                    calculateEAR([33, 160, 158, 133, 153, 144], landmarks)) / 2.0;

    const rawMar = euclidDist(landmarks[13], landmarks[14]) /
                   euclidDist(landmarks[78], landmarks[308]);

    const rawGaze = (getHorizontalRatio(landmarks[468], landmarks[362], landmarks[263]) +
                     getHorizontalRatio(landmarks[473], landmarks[133], landmarks[33])) / 2.0;

    const nose = landmarks[1], leftF = landmarks[234], rightF = landmarks[454];
    const rawTurn  = (nose.x - leftF.x) / (rightF.x - leftF.x);

    const rawPitch = headPitch(landmarks);
    const rawRoll  = headRoll(landmarks);

    // ── 2. PUSH INTO ROLLING BUFFERS → SMOOTHED VALUES ─────────────────────
    bufEar.push(rawEar);     const ear   = bufEar.mean();
    bufMar.push(rawMar);     const mar   = bufMar.mean();
    bufGaze.push(rawGaze);   const gaze  = bufGaze.mean();
    bufTurn.push(rawTurn);   const turn  = bufTurn.mean();
    bufPitch.push(rawPitch); const pitch = bufPitch.mean();
    bufRoll.push(rawRoll);   const roll  = bufRoll.mean();

    // ── 3. HUD ─────────────────────────────────────────────────────────────
    engineStatus.innerHTML = `
      <div style="font-family: monospace; font-size: 14px; text-align: left; line-height: 1.6;">
        EYES : ${ear.toFixed(2)} ${ear < eyeThresh ? '⚠️' : '✅'} ${now < cooldowns.Drowsy ? '❄️' : ''}<br>
        MOUTH: ${mar.toFixed(2)} ${mar > MOUTH_THRESH ? '⚠️' : '✅'} ${now < cooldowns.Yawn ? '❄️' : ''}<br>
        GAZE : ${gaze.toFixed(2)} ${(gaze < GAZE_MIN || gaze > GAZE_MAX) ? '⚠️' : '✅'}<br>
        TURN : ${turn.toFixed(2)} ${(turn < 0.35 || turn > 0.65) ? '⚠️' : '✅'} ${now < cooldowns.Distracted ? '❄️' : ''}<br>
        PITCH: ${pitch.toFixed(2)} ${pitch > NOD_THRESH ? '⚠️' : '✅'} ${now < cooldowns.Microsleep ? '❄️' : ''}<br>
        ROLL : ${Math.abs(roll).toFixed(2)} ${Math.abs(roll) > TILT_THRESH ? '⚠️' : '✅'}
      </div>
    `;

    // ── 4. DETECTION LOGIC ─────────────────────────────────────────────────
    //   Priority order: Drowsy > Microsleep > Yawn > Distracted
    let detectedType = null;

    // Priority 1 — Drowsy (eyes closing, personalised threshold)
    if (ear < eyeThresh) {
      if (!isEyeClosed) { drowsyStartTime = now; isEyeClosed = true; }
      if (now - drowsyStartTime > DROWSY_WAIT_TIME) detectedType = "Drowsy";
    } else { isEyeClosed = false; }

    // Priority 2 — Microsleep (head nod or tilt, eyes may still be open)
    const isNoddingOrTilting = (pitch > NOD_THRESH) || (Math.abs(roll) > TILT_THRESH);
    if (isNoddingOrTilting) {
      if (!isNodding) { nodStartTime = now; isNodding = true; }
      if (detectedType === null && now - nodStartTime > NOD_WAIT_TIME) detectedType = "Microsleep";
    } else { isNodding = false; }

    // Priority 3 — Yawn
    if (mar > MOUTH_THRESH) {
      if (!isYawnOpen) { yawnStartTime = now; isYawnOpen = true; }
      if (detectedType === null && now - yawnStartTime > YAWN_WAIT_TIME) detectedType = "Yawn";
    } else { isYawnOpen = false; }

    // Priority 4 — Distracted (gaze or turn out of safe zone)
    const isAway = (gaze < GAZE_MIN || gaze > GAZE_MAX) || (turn < 0.35 || turn > 0.65);
    if (isAway) {
      if (!isDistracted) { distractStartTime = now; isDistracted = true; }
      if (detectedType === null && now - distractStartTime > DISTRACT_WAIT_TIME) detectedType = "Distracted";
    } else { isDistracted = false; }

    // ── 5. ALERT STATE MACHINE ─────────────────────────────────────────────
    if (detectedType && !activeAlert && now > cooldowns[detectedType]) {
      activeAlert  = detectedType;
      alertEndTime = now + ALERT_LATCH_TIME;
      
      // Escalation logic
      alertHistory.push({ time: now, type: activeAlert });
      // Clean old history
      alertHistory = alertHistory.filter(h => (now - h.time) <= 60000);
      
      const recentCount = alertHistory.filter(h => h.type === activeAlert).length;
      let severity = "Medium";
      if (recentCount >= 3) {
          severity = "Critical";
      } else if (recentCount === 2) {
          severity = "High";
      } else {
          severity = activeAlert === "Drowsy" ? "High" : "Medium";
      }

      syncToDB(activeAlert, severity);   // ONE-TIME DB write per activation
    }
    if (detectedType === activeAlert) {
      alertEndTime = now + ALERT_LATCH_TIME;   // latch extension
    }
    if (activeAlert && now > alertEndTime) {
      const recentCount = alertHistory.filter(h => h.type === activeAlert && (now - h.time) <= 60000).length;
      cooldowns[activeAlert] = now + (recentCount >= 2 ? 15000 : 10000); // Dynamic Cooldown
      activeAlert = null;
    }

    // ── 6. UI RENDERING ────────────────────────────────────────────────────
    if (activeAlert) {
      const label = activeAlert === "Microsleep" ? "⚠ MICROSLEEP!" : activeAlert.toUpperCase() + "!";
      alertBanner.textContent = label;
      alertBanner.className   = `alert-banner alert-${activeAlert.toLowerCase()}`;
      alertBanner.style.display = "block";
      alertStatus.textContent   = activeAlert;
      if (alarm.paused) alarm.play().catch(() => { });
    } else {
      alertBanner.style.display = "none";
      alertStatus.textContent   = "All Clear";
      if (!alarm.paused) alarm.pause();
    }
  }

  window.requestAnimationFrame(predictWebcam);
}

// ─────────────────────────────────────────────────────────────────────────────
//  ENTRY POINT
// ─────────────────────────────────────────────────────────────────────────────
export async function startEngine(driverId) {
  window.DRIVER_ID = driverId;
  engineStatus.textContent = "Creating Session…";

  try {
    const formData = new FormData();
    formData.append('driver_id', driverId);
    formData.append('api_key',   'NgPro2026_xYz98!');
    const res      = await fetch('start_session.php', { method: 'POST', body: formData });
    const text     = await res.text();
    const sessionId = parseInt(text.trim(), 10);
    if (!isNaN(sessionId)) {
      window.SESSION_ID = String(sessionId);
      console.log("[NeuroGuard] Started session:", window.SESSION_ID);
    } else {
      console.error("[NeuroGuard] Bad session response:", text);
      window.SESSION_ID = '1';
    }
  } catch (e) {
    console.error("[NeuroGuard] Failed to start session:", e);
    window.SESSION_ID = '1';
  }

  engineStatus.textContent = "Loading AI Model…";
  initialize();
}
