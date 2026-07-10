<?php
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $driver_id = $_POST['driver_id'] ?? null;

    if (!$driver_id || !is_numeric($driver_id)) {
        http_response_code(400);
        echo "Error: Invalid driver_id";
        exit();
    }

    $driver_id = (int) $driver_id;

    // Use a prepared statement to prevent SQL injection
    $stmt = $conn->prepare("INSERT INTO sessions (driver_id, status) VALUES (?, 'Active')");
    $stmt->bind_param("i", $driver_id);

    if ($stmt->execute()) {
        echo $conn->insert_id; // Return ONLY the new session_id (e.g., 5, 6, 7)
    } else {
        http_response_code(500);
        echo "Error: " . $stmt->error;
    }

    $stmt->close();
}

$conn->close();
?>
