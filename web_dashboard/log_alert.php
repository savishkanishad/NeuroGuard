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

        $stmt = $conn->prepare("INSERT INTO alerts (session_id, driver_id, alert_type, severity, latitude, longitude, timestamp) VALUES (?, ?, ?, ?, ?, ?, NOW())");
        $stmt->bind_param("iissss", $session_id, $driver_id, $type, $severity, $latitude_value, $longitude_value);

        if ($stmt->execute()) {
            echo "Success";
        } else {
            echo "SQL Error: " . $stmt->error; 
        }
        
        $stmt->close();
    } else {
        echo "Stored in fallback tracker";
    }

    $fallback_payload = [
        'alert_id' => time(),
        'driver_id' => $driver_id,
        'session_id' => $session_id,
        'alert_type' => $type,
        'severity' => $severity,
        'latitude' => $latitude_value,
        'longitude' => $longitude_value,
        'timestamp' => date('Y-m-d H:i:s'),
        'full_name' => 'Fallback Driver'
    ];
    save_alert_fallback($fallback_payload);
}
if ($conn !== null) {
    $conn->close();
}
?>