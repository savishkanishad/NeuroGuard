<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) { http_response_code(403); die("Forbidden"); }
require_once 'db_config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $driver_id = $_POST['driver_id'];
    
    // Insert a new session and get the ID it just created
    $stmt = $conn->prepare("INSERT INTO sessions (driver_id, status) VALUES (?, 'Active')");
    $stmt->bind_param("i", $driver_id);

    if ($stmt->execute()) {
        echo $conn->insert_id; // Return ONLY the new session_id (e.g., 5, 6, 7)
    } else {
        echo "Error";
    }
    $stmt->close();
}
$conn->close();
?>