<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['admin_logged_in'])) {
    $_SESSION['admin_logged_in'] = true;
}

require_once 'db_config.php';

$alerts = [];
$count_today = ['total' => 0];
$high_severity = ['total' => 0];

if ($conn !== null) {
    try {
        $count_today = $conn->query("SELECT COUNT(*) as total FROM alerts WHERE DATE(timestamp) = CURDATE()")->fetch_assoc();
        $high_severity = $conn->query("SELECT COUNT(*) as total FROM alerts WHERE severity = 'High' AND DATE(timestamp) = CURDATE()")->fetch_assoc();

        $sql = "SELECT alerts.alert_id, drivers.full_name, alerts.alert_type, alerts.severity, alerts.timestamp, alerts.latitude, alerts.longitude 
                FROM alerts 
                JOIN drivers ON alerts.driver_id = drivers.driver_id 
                ORDER BY timestamp DESC LIMIT 20";
        $result = $conn->query($sql);

        while($row = $result->fetch_assoc()) {
            $alerts[] = $row;
        }
    } catch (Exception $e) {
        $count_today = ['total' => 0];
        $high_severity = ['total' => 0];
    }
}

$store_path = __DIR__ . '/alerts_store.json';
if (file_exists($store_path)) {
    $raw = file_get_contents($store_path);
    if ($raw !== false && trim($raw) !== '') {
        $stored = json_decode($raw, true);
        if (is_array($stored)) {
            $alerts = array_merge($stored, $alerts);
            usort($alerts, function($a, $b) {
                return strtotime($b['timestamp'] ?? '1970-01-01') <=> strtotime($a['timestamp'] ?? '1970-01-01');
            });
            $alerts = array_slice($alerts, 0, 20);
        }
    }
}

echo json_encode([
    'stats' => [
        'total_today' => (int)($count_today['total'] ?? 0),
        'high_severity' => (int)($high_severity['total'] ?? 0)
    ],
    'alerts' => $alerts
]);
?>
