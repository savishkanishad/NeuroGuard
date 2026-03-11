<?php
require_once 'db_config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $driver_id = $_POST['driver_id'];
    
    // Insert a new session and get the ID it just created
    $sql = "INSERT INTO sessions (driver_id, status) VALUES ('$driver_id', 'Active')";
    
    if ($conn->query($sql) === TRUE) {
        echo $conn->insert_id; // Return ONLY the new session_id (e.g., 5, 6, 7)
    } else {
        echo "Error";
    }
}
$conn->close();
?>