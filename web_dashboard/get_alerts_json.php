<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['admin_logged_in'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated', 'stats' => ['total_today' => 0, 'high_severity' => 0], 'alerts' => []]);
    exit();
}

require_once 'db_config.php';

$alerts = [];
$count_today = ['total' => 0];
$high_severity = ['total' => 0];
$db_error = null; // surfaced only when ?debug=1 is passed, see bottom of file

if ($conn !== null) {
    try {
        $count_result = $conn->query("SELECT COUNT(*) as total FROM alerts WHERE DATE(timestamp) = CURDATE()");
        $count_today = $count_result ? $count_result->fetch_assoc() : ['total' => 0];

        $high_result = $conn->query("SELECT COUNT(*) as total FROM alerts WHERE severity = 'High' AND DATE(timestamp) = CURDATE()");
        $high_severity = $high_result ? $high_result->fetch_assoc() : ['total' => 0];

        $sql = "SELECT alerts.alert_id, drivers.full_name, alerts.alert_type, alerts.severity, alerts.timestamp, alerts.latitude, alerts.longitude, alerts.location_source 
                FROM alerts 
                JOIN drivers ON alerts.driver_id = drivers.driver_id 
                ORDER BY timestamp DESC LIMIT 20";
        $result = $conn->query($sql);

        if ($result === false) {
            // Query failed (e.g. missing latitude/longitude columns) — surface it
            // instead of silently returning an empty list.
            $db_error = $conn->error;
        } else {
            while ($row = $result->fetch_assoc()) {
                $alerts[] = $row;
            }
        }
    } catch (Throwable $e) {
        // Throwable (not just Exception) so a mysqli fatal on older PHP
        // versions can't crash this script into returning broken/partial JSON.
        $db_error = $e->getMessage();
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
            $alertMap = [];
            foreach ($alerts as $row) {
                $alertMap[$row['alert_id']] = $row;
            }
            foreach ($stored as $storedRow) {
                $alertId = $storedRow['alert_id'] ?? null;
                if ($alertId === null) {
                    continue;
                }
                if (isset($alertMap[$alertId])) {
                    $alertMap[$alertId] = array_merge($alertMap[$alertId], array_filter($storedRow, function($value) {
                        return $value !== null && $value !== '';
                    }));
                } else {
                    $alertMap[$alertId] = $storedRow;
                }
            }
            $alerts = array_values($alertMap);
            usort($alerts, function($a, $b) {
                return strtotime($b['timestamp'] ?? '1970-01-01') <=> strtotime($a['timestamp'] ?? '1970-01-01');
            });
            $alerts = array_slice($alerts, 0, 20);
        }
    }
}

$response = [
    'stats' => [
        'total_today' => (int)($count_today['total'] ?? 0),
        'high_severity' => (int)($high_severity['total'] ?? 0)
    ],
    'alerts' => $alerts
];

// Visit get_alerts_json.php?debug=1 in the browser to see the raw DB error
// (e.g. "Unknown column 'latitude' in 'field list'") if alerts aren't showing up.
if ($db_error !== null && isset($_GET['debug'])) {
    $response['db_error'] = $db_error;
}

echo json_encode($response);
?>
