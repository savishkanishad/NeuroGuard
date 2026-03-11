<?php
header('Content-Type: application/json');
require_once 'db_config.php';

// Count total alerts for today
$count_today = $conn->query("SELECT COUNT(*) as total FROM alerts WHERE DATE(timestamp) = CURDATE()")->fetch_assoc();

// Count specifically 'High' severity alerts (Drowsy)
$high_severity = $conn->query("SELECT COUNT(*) as total FROM alerts WHERE severity = 'High' AND DATE(timestamp) = CURDATE()")->fetch_assoc();

// Fetch the most recent 20 alerts
$sql = "SELECT alerts.*, drivers.full_name 
        FROM alerts 
        JOIN drivers ON alerts.driver_id = drivers.driver_id 
        ORDER BY timestamp DESC LIMIT 20";
$result = $conn->query($sql);

$alerts = [];
while($row = $result->fetch_assoc()) {
    $alerts[] = $row;
}

echo json_encode([
    'stats' => [
        'total_today' => $count_today['total'],
        'high_severity' => $high_severity['total']
    ],
    'alerts' => $alerts
]);
?>
