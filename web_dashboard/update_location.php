<?php
// ============================================================
// update_location.php
// ------------------------------------------------------------
// Receives a "heartbeat" GPS ping from engine.js roughly every
// 5 seconds while a monitoring session is running, independent
// of alerts.php (which only writes a position when an alert
// actually fires — that can be minutes apart, or never, on a
// clean drive). This is what lets the Live Map show a driver
// moving in real time instead of just plotting alert history.
//
// One row per driver — each new ping overwrites the previous
// position (upsert). A driver with no ping in the last ~20s is
// treated by get_live_positions.php as offline/stale, so this
// table doesn't need any cleanup job.
// ============================================================

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: X-API-Key, Content-Type");
header("Access-Control-Allow-Methods: POST, OPTIONS");
require_once 'db_config.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$provided_key = $_SERVER['HTTP_X_API_KEY'] ?? ($_POST['api_key'] ?? '');
if ($provided_key !== API_KEY) {
    http_response_code(403);
    die("Error: Unauthorized. Invalid or missing API Key.");
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die("Error: POST required.");
}

if ($conn === null) {
    // Database is down — this is a best-effort heartbeat, not a critical
    // write, so fail quietly rather than spamming the browser console
    // every 5 seconds.
    http_response_code(503);
    echo "Database unavailable";
    exit();
}

$driver_id  = isset($_POST['driver_id'])  ? (int)$_POST['driver_id']  : 0;
$session_id = isset($_POST['session_id']) ? (int)$_POST['session_id'] : 0;
$latitude   = (isset($_POST['latitude'])  && $_POST['latitude']  !== '' && is_numeric($_POST['latitude']))  ? (float)$_POST['latitude']  : null;
$longitude  = (isset($_POST['longitude']) && $_POST['longitude'] !== '' && is_numeric($_POST['longitude'])) ? (float)$_POST['longitude'] : null;
$accuracy   = (isset($_POST['accuracy'])  && $_POST['accuracy']  !== '' && is_numeric($_POST['accuracy']))  ? (float)$_POST['accuracy']  : null;
$source     = $_POST['location_source'] ?? 'gps';

if (!in_array($source, ['gps', 'ip', 'fallback'], true)) {
    $source = 'gps';
}

if ($driver_id <= 0 || $session_id <= 0 || $latitude === null || $longitude === null) {
    http_response_code(400);
    die("Error: driver_id, session_id, latitude and longitude are required.");
}

$lat_value = sprintf('%.8f', $latitude);
$lng_value = sprintf('%.8f', $longitude);
$acc_value = ($accuracy !== null) ? sprintf('%.2f', $accuracy) : null;

$stmt = $conn->prepare(
    "INSERT INTO live_positions (driver_id, session_id, latitude, longitude, accuracy, location_source, updated_at)
     VALUES (?, ?, ?, ?, ?, ?, NOW())
     ON DUPLICATE KEY UPDATE
        session_id = VALUES(session_id),
        latitude = VALUES(latitude),
        longitude = VALUES(longitude),
        accuracy = VALUES(accuracy),
        location_source = VALUES(location_source),
        updated_at = NOW()"
);

if ($stmt === false) {
    // Most likely cause: live_positions table doesn't exist yet — run
    // migrate_live_location.sql against your database.
    http_response_code(500);
    echo "SQL Error: " . $conn->error;
} else {
    $stmt->bind_param("iissss", $driver_id, $session_id, $lat_value, $lng_value, $acc_value, $source);
    if ($stmt->execute()) {
        echo "Success";
    } else {
        http_response_code(500);
        echo "SQL Error: " . $stmt->error;
    }
    $stmt->close();
}

$conn->close();
?>
