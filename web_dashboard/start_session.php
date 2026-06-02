<?php
require_once 'db_config.php';

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
