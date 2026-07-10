# NeuroGuard Pro - Software Requirements Specification (SRS)

## 1. Introduction
NeuroGuard Pro is an advanced AI-driven driver monitoring system. This document outlines the functional and non-functional requirements for the implemented system.

## 2. Functional Requirements

### 2.1 Driver Monitoring (AI Engines)
- **FR1:** The system shall capture live video feed from a webcam (via Web Browser or Python script).
- **FR2:** The system shall analyze facial landmarks in real-time using MediaPipe.
- **FR3:** The system shall detect "Drowsy" states based on Eye Aspect Ratio (EAR) falling below a threshold (0.23).
- **FR4:** The system shall detect "Yawn" states based on Mouth Aspect Ratio (MAR) exceeding a threshold (0.60).
- **FR5:** The system shall detect "Distracted" states if the driver's gaze or head turn exceeds defined safe boundaries.
- **FR6:** The system shall trigger a local audible alarm upon detecting any critical state.
- **FR7:** The system shall transmit alert data to the central database securely using an API key.

### 2.2 Fleet Management Dashboard
- **FR8:** The dashboard shall require administrator authentication (username and password).
- **FR9:** The dashboard shall display real-time statistics of total alerts and high-severity alerts.
- **FR10:** The dashboard shall list recent alert events detailing the driver's name, alert type, severity, and timestamp.
- **FR11:** The system shall allow selecting a specific driver before initializing a monitoring session.

## 3. Non-Functional Requirements
- **NFR1 (Performance):** The AI engine should run at a stable framerate (minimum 15 FPS) on modern devices.
- **NFR2 (Security):** Machine-to-machine communication shall be secured with an `X-API-Key`.
- **NFR3 (Security):** Admin passwords shall be securely hashed using bcrypt.
- **NFR4 (Usability):** The admin dashboard must be fully responsive on mobile and desktop devices.
- **NFR5 (Reliability):** The backend API shall debounce repeated alerts within a 10-second window to prevent database flooding.
