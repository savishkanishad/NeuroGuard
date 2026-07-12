<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header("HTTP/1.1 403 Forbidden");
    exit();
}
require_once 'db_config.php';

$filename = "neuroguard_alerts_" . date('Ymd_His') . ".csv";

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=' . $filename);

$output = fopen('php://output', 'w');
fputcsv($output, array('Alert ID', 'Driver Name', 'Session ID', 'Alert Type', 'Severity', 'Latitude', 'Longitude', 'Date', 'Time'));

$sql = "SELECT alerts.alert_id, drivers.full_name, alerts.session_id, alerts.alert_type, alerts.severity, alerts.latitude, alerts.longitude, alerts.timestamp 
        FROM alerts 
        JOIN drivers ON alerts.driver_id = drivers.driver_id 
        ORDER BY alerts.timestamp DESC";
$result = $conn->query($sql);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $ts = strtotime($row['timestamp'] ?? '');
        if ($ts && $ts > 0) {
            $date = date('Y-m-d', $ts);
            $time = date('H:i:s', $ts);
        } else {
            $date = 'Not Recorded';
            $time = 'Not Recorded';
        }
        
        fputcsv($output, array(
            $row['alert_id'], 
            $row['full_name'], 
            $row['session_id'], 
            $row['alert_type'], 
            $row['severity'], 
            $row['latitude'], 
            $row['longitude'], 
            $date, 
            $time
        ));
    }
}
fclose($output);
exit();
?>
