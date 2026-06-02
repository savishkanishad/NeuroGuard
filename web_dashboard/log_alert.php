<?php
header("Access-Control-Allow-Origin: *");
require_once 'db_config.php';

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Check if data is coming from Python
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // These names must match the 'payload' keys in your Python script
    $driver_id  = $_POST['driver_id'];
    $session_id = $_POST['session_id'];
    $type       = $_POST['alert_type'];
    
    // Severity logic: Drowsy is 'High', others are 'Medium'
    $severity = ($type == 'Drowsy') ? 'High' : 'Medium';

    // --- SERVER-SIDE DEBOUNCING ---
    // Prevent the exact same alert for the same driver/session from being spammed
    $debounce_check = $conn->prepare("SELECT alert_id FROM alerts WHERE session_id = ? AND alert_type = ? AND timestamp > (NOW() - INTERVAL 10 SECOND)");
    $debounce_check->bind_param("is", $session_id, $type);
    $debounce_check->execute();
    $debounce_check->store_result();
    
    if ($debounce_check->num_rows > 0) {
        $debounce_check->close();
        die("Ignored: Duplicate alert within 10s window");
    }
    $debounce_check->close();

    // SQL query for inserting the alert
    $stmt = $conn->prepare("INSERT INTO alerts (session_id, driver_id, alert_type, severity) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("iiss", $session_id, $driver_id, $type, $severity);

    if ($stmt->execute()) {
        echo "Success";
    } else {
        // This will tell us EXACTLY why it failed (e.g., Data truncated, Foreign key fail)
        echo "SQL Error: " . $stmt->error; 
    }
    
    $stmt->close();
}
$conn->close();
?>