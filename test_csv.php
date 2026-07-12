<?php
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SERVER_ADDR'] = '127.0.0.1';
require_once 'web_dashboard/db_config.php';
$output = fopen('php://output', 'w');
fputcsv($output, array('Alert ID', 'Driver Name', 'Session ID', 'Alert Type', 'Severity', 'Latitude', 'Longitude', 'Date', 'Time'));

$sql = "SELECT alerts.alert_id, drivers.full_name, alerts.session_id, alerts.alert_type, alerts.severity, alerts.latitude, alerts.longitude, alerts.timestamp 
        FROM alerts 
        JOIN drivers ON alerts.driver_id = drivers.driver_id 
        ORDER BY alerts.timestamp DESC";
$result = $conn->query($sql);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $ts = strtotime($row['timestamp']);
        $date = date('Y-m-d', $ts);
        $time = date('H:i:s', $ts);
        
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
?>
