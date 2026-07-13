<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: X-API-Key, Content-Type");
header("Access-Control-Allow-Methods: POST, OPTIONS");
require_once 'db_config.php';

function save_alert_fallback($payload) {
    $store_path = __DIR__ . '/alerts_store.json';
    $store = [];
    if (file_exists($store_path)) {
        $raw = file_get_contents($store_path);
        if ($raw !== false && trim($raw) !== '') {
            $store = json_decode($raw, true);
            if (!is_array($store)) {
                $store = [];
            }
        }
    }
    $store[] = $payload;
    file_put_contents($store_path, json_encode($store, JSON_PRETTY_PRINT));
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($conn === null) {
    $db_available = false;
} else {
    $db_available = true;
}

$provided_key = $_SERVER['HTTP_X_API_KEY'] ?? ($_POST['api_key'] ?? '');
if ($provided_key !== API_KEY) {
    http_response_code(403);
    die("Error: Unauthorized. Invalid or missing API Key.");
}

// Check if data is coming from Python
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // These names must match the 'payload' keys in your Python script
    $driver_id  = $_POST['driver_id'];
    $session_id = $_POST['session_id'];
    $type       = $_POST['alert_type'];
    
    // Accept dynamic severity, fallback to old logic if not provided
    if (isset($_POST['severity'])) {
        $severity = $_POST['severity'];
    } else {
        $severity = ($type == 'Drowsy') ? 'High' : 'Medium';
    }

    $latitude  = isset($_POST['latitude'])  && $_POST['latitude']  !== '' ? (float)$_POST['latitude']  : null;
    $longitude = isset($_POST['longitude']) && $_POST['longitude'] !== '' ? (float)$_POST['longitude'] : null;
    $latitude_value = ($latitude !== null && is_numeric($latitude)) ? sprintf('%.6f', $latitude) : null;
    $longitude_value = ($longitude !== null && is_numeric($longitude)) ? sprintf('%.6f', $longitude) : null;
    $location_source = $_POST['location_source'] ?? null; // 'gps' | 'ip' | 'fallback', set by engine.js

    // Accuracy radius in meters, straight from navigator.geolocation's
    // coords.accuracy (or a nominal estimate engine.js assigns for ip/fallback
    // fixes). Used by the Live Map to draw a "how sure are we" circle.
    $accuracy = (isset($_POST['accuracy']) && $_POST['accuracy'] !== '' && is_numeric($_POST['accuracy']))
        ? (float)$_POST['accuracy']
        : null;
    $accuracy_value = ($accuracy !== null) ? sprintf('%.2f', $accuracy) : null;

    // Guarantee every alert has a location, no matter what the client sent.
    // Without this, any request that omits latitude/longitude (manual API
    // testing, curl, a client with GPS denied) silently stores NULL and the
    // alert disappears from the Live Map with no indication why.
    // DEFAULT_LAT/LNG below is the fleet depot fallback (Colombo); swap for
    // your actual base location if different. A small random jitter keeps
    // markers from stacking exactly on top of each other.
    if ($latitude_value === null || $longitude_value === null) {
        $DEFAULT_LAT = 6.9271;
        $DEFAULT_LNG = 79.8612;
        $latitude_value  = sprintf('%.6f', $DEFAULT_LAT + (mt_rand(-500, 500) / 100000));
        $longitude_value = sprintf('%.6f', $DEFAULT_LNG + (mt_rand(-500, 500) / 100000));
        $location_source = 'server_fallback'; // tagged so it's visibly NOT a real reading
        $accuracy_value  = '25000.00'; // 25km nominal radius — makes clear this is a guess, not a fix
    }

    $storedFallback = false;
    if ($db_available) {
        // --- SERVER-SIDE DEBOUNCING ---
        $debounce_check = $conn->prepare("SELECT alert_id FROM alerts WHERE session_id = ? AND alert_type = ? AND timestamp > (NOW() - INTERVAL 10 SECOND)");
        $debounce_check->bind_param("is", $session_id, $type);
        $debounce_check->execute();
        $debounce_check->store_result();
        
        if ($debounce_check->num_rows > 0) {
            $debounce_check->close();
            echo "Ignored: Duplicate alert within 10s window";
            $conn->close();
            exit();
        }
        $debounce_check->close();

        $stmt = $conn->prepare("INSERT INTO alerts (session_id, driver_id, alert_type, severity, latitude, longitude, location_source, accuracy, timestamp) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");

        if ($stmt === false) {
            // Most likely cause: the alerts table doesn't have latitude/longitude/
            // location_source/accuracy columns yet. Run migrate_live_location.sql
            // against your database, or fall back for now.
            echo "SQL Error: " . $conn->error;
            $storedFallback = true;
        } else {
            $stmt->bind_param("iissssss", $session_id, $driver_id, $type, $severity, $latitude_value, $longitude_value, $location_source, $accuracy_value);

            if ($stmt->execute()) {
                echo "Success";
            } else {
                echo "SQL Error: " . $stmt->error;
                $storedFallback = true;
            }

            $stmt->close();
        }
    } else {
        echo "Stored in fallback tracker";
        $storedFallback = true;
    }

    if ($storedFallback) {
        $fallback_payload = [
            'alert_id' => time(),
            'driver_id' => $driver_id,
            'session_id' => $session_id,
            'alert_type' => $type,
            'severity' => $severity,
            'latitude' => $latitude_value,
            'longitude' => $longitude_value,
            'location_source' => $location_source,
            'accuracy' => $accuracy_value,
            'timestamp' => date('Y-m-d H:i:s'),
            'full_name' => 'Fallback Driver'
        ];
        save_alert_fallback($fallback_payload);
    }
}

if ($conn !== null) {
    $conn->close();
}
?>