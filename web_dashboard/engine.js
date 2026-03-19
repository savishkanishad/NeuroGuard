import {
  FaceLandmarker,
  FilesetResolver,
  DrawingUtils
} from "https://cdn.jsdelivr.net/npm/@mediapipe/tasks-vision/vision_bundle.js";

const video = document.getElementById("webcam");
const canvasElement = document.getElementById("output_canvas");
const canvasCtx = canvasElement.getContext("2d");
const alertBanner = document.getElementById("alert-banner");
const engineStatus = document.getElementById("engine-status");
const alertStatus = document.getElementById("alert-status");
const loadingOverlay = document.getElementById("loading-overlay");
const nvToggle = document.getElementById("nv-toggle");

if (nvToggle) {
  nvToggle.addEventListener("click", () => {
    const wrapper = document.getElementById("camera-wrapper");
    if (wrapper) wrapper.classList.toggle("night-vision");
    nvToggle.classList.toggle("active");
  });
}

let faceLandmarker;
let runningMode = "VIDEO";
let lastVideoTime = -1;
let results = undefined;

// --- CONFIG & THRESHOLDS ---
const EYE_THRESH = 0.23;       // Working well as per user
const MOUTH_THRESH = 0.60;     // Increased further to avoid false positives during speech
const GAZE_MIN = 0.30;         // Tightened for better sensitivity
const GAZE_MAX = 0.70;         // Tightened for better sensitivity
const DROWSY_WAIT_TIME = 1000; // 1s
const YAWN_WAIT_TIME = 700;    // 0.7s (newly added)
const DISTRACT_WAIT_TIME = 1500; // 1.5s
const SYNC_COOLDOWN = 10000;
const ALERT_LATCH_TIME = 2000;  // Stay active for 2s
const RETRIGGER_COOLDOWN = 5000; // Rest for 5s after alert ends

let drowsyStartTime = 0;
let isEyeClosed = false;

let yawnStartTime = 0;
let isYawnOpen = false;

let distractStartTime = 0;
let isDistracted = false;

// Alert Management State
let activeAlert = null;
let alertEndTime = 0;
let isSyncing = false;  // Global lock to prevent overlapping network requests
let cooldowns = { Drowsy: 0, Yawn: 0, Distracted: 0 };
let lastSyncTimes = { Drowsy: 0, Yawn: 0, Distracted: 0 };

// Audio setup
const alarm = new Audio('alarm.wav');
alarm.loop = true;

// --- INITIALIZE MEDIAPIPE ---
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
  navigator.mediaDevices.getUserMedia({ 
    video: { facingMode: 'user' } 
  }).then((stream) => {
    video.srcObject = stream;
    video.addEventListener("loadeddata", predictWebcam);
    loadingOverlay.style.display = "none";
    engineStatus.textContent = "Live";
  }).catch(e => {
    engineStatus.textContent = "Camera Error";
    console.error(e);
  });
}

// --- MATH HELPERS ---
function dist(p1, p2) {
  const x = (p1.x - p2.x) * video.videoWidth;
  const y = (p1.y - p2.y) * video.videoHeight;
  return Math.sqrt(x * x + y * y);
}

function calculateEAR(eyeIndices, landmarks) {
  const eye = eyeIndices.map(i => landmarks[i]);
  const A = dist(eye[1], eye[5]);
  const B = dist(eye[2], eye[4]);
  const C = dist(eye[0], eye[3]);
  return (A + B) / (2.0 * C);
}

function getHorizontalRatio(iris, corner1, corner2) {
  if (!iris || !corner1 || !corner2) return 0.5;
  const leftX = Math.min(corner1.x, corner2.x);
  const rightX = Math.max(corner1.x, corner2.x);
  const width = rightX - leftX;
  if (width < 0.005) return 0.5;
  let ratio = (iris.x - leftX) / width;
  return Math.max(0, Math.min(1, ratio));
}

async function syncToDB(alertType) {
  const now = Date.now();
  if (isSyncing) return; // Strict lock
  if (now - lastSyncTimes[alertType] < SYNC_COOLDOWN) return;

  isSyncing = true;
  lastSyncTimes[alertType] = now;
  console.log(`[NeuroGuard] Sending ${alertType} alert...`);

  const formData = new FormData();
  formData.append('driver_id', '1');
  formData.append('session_id', '1');
  formData.append('alert_type', alertType);

  try {
    const response = await fetch('log_alert.php', { method: 'POST', body: formData });
    const text = await response.text();
    console.log("[NeuroGuard] Server response:", text);
  } catch (e) {
    console.error("[NeuroGuard] Sync error:", e);
  } finally {
    isSyncing = false;
  }
}

// --- MAIN LOOP ---
async function predictWebcam() {
  if (lastVideoTime !== video.currentTime) {
    lastVideoTime = video.currentTime;
    results = faceLandmarker.detectForVideo(video, Date.now());
  }

  canvasCtx.clearRect(0, 0, canvasElement.width, canvasElement.height);

  if (results.faceLandmarks && results.faceLandmarks.length > 0) {
    const landmarks = results.faceLandmarks[0];
    const now = Date.now();

    // 1. EAR (Eyes)
    const earL = calculateEAR([362, 385, 387, 263, 373, 380], landmarks);
    const earR = calculateEAR([33, 160, 158, 133, 153, 144], landmarks);
    const ear = (earL + earR) / 2.0;

    // 2. MAR (Mouth)
    const mar = dist(landmarks[13], landmarks[14]) / dist(landmarks[78], landmarks[308]);

    // 3. GAZE & TURN
    const gazeL = getHorizontalRatio(landmarks[468], landmarks[362], landmarks[263]);
    const gazeR = getHorizontalRatio(landmarks[473], landmarks[133], landmarks[33]);
    const gaze = (gazeL + gazeR) / 2.0;
    const nose = landmarks[1], leftF = landmarks[234], rightF = landmarks[454];
    const turn = (nose.x - leftF.x) / (rightF.x - leftF.x);

    // Visual Feedback HUD
    engineStatus.innerHTML = `
      <div style="font-family: monospace; font-size: 14px; text-align: left; line-height: 1.6;">
        EYES : ${ear.toFixed(2)} ${ear < EYE_THRESH ? '⚠️' : '✅'} ${now < cooldowns.Drowsy ? '❄️' : ''}<br>
        MOUTH: ${mar.toFixed(2)} ${mar > MOUTH_THRESH ? '⚠️' : '✅'} ${now < cooldowns.Yawn ? '❄️' : ''}<br>
        GAZE : ${gaze.toFixed(2)} ${(gaze < 0.25 || gaze > 0.75) ? '⚠️' : '✅'}<br>
        TURN : ${turn.toFixed(2)} ${(turn < 0.35 || turn > 0.65) ? '⚠️' : '✅'} ${now < cooldowns.Distracted ? '❄️' : ''}
      </div>
    `;

    // --- DETECTION LOGIC ---
    let detectedType = null;

    // Check for Drowsy
    if (ear < EYE_THRESH) {
      if (!isEyeClosed) { drowsyStartTime = now; isEyeClosed = true; }
      if (now - drowsyStartTime > DROWSY_WAIT_TIME) detectedType = "Drowsy";
    } else { isEyeClosed = false; }

    // Check for Yawn
    if (mar > MOUTH_THRESH) {
      if (!isYawnOpen) { yawnStartTime = now; isYawnOpen = true; }
      if (now - yawnStartTime > YAWN_WAIT_TIME) detectedType = "Yawn";
    } else { isYawnOpen = false; }

    // Check for Distracted
    const isAway = (gaze < 0.25 || gaze > 0.75) || (turn < 0.35 || turn > 0.65);
    if (isAway) {
      if (!isDistracted) { distractStartTime = now; isDistracted = true; }
      if (now - distractStartTime > DISTRACT_WAIT_TIME) detectedType = "Distracted";
    } else { isDistracted = false; }

    // --- ALERT STATE MACHINE ---
    // If we have a detected event and aren't already in an alert/cooldown
    if (detectedType && !activeAlert && now > cooldowns[detectedType]) {
      activeAlert = detectedType;
      alertEndTime = now + ALERT_LATCH_TIME;
      syncToDB(activeAlert); // ONE-TIME SYNC TRIGGER
    }

    // If the alert condition persists, keep pushing the end time (latching)
    if (detectedType === activeAlert) {
      alertEndTime = now + ALERT_LATCH_TIME;
    }

    // When the latch expires
    if (activeAlert && now > alertEndTime) {
      cooldowns[activeAlert] = now + RETRIGGER_COOLDOWN;
      activeAlert = null;
    }

    // --- UI RENDERING ---
    if (activeAlert) {
      alertBanner.textContent = activeAlert.toUpperCase() + "!";
      alertBanner.className = `alert-banner alert-${activeAlert.toLowerCase()}`;
      alertBanner.style.display = "block";
      alertStatus.textContent = activeAlert;
      if (alarm.paused) alarm.play().catch(() => { });
    } else {
      alertBanner.style.display = "none";
      alertStatus.textContent = "All Clear";
      if (!alarm.paused) alarm.pause();
    }
  }

  window.requestAnimationFrame(predictWebcam);
}

initialize();
