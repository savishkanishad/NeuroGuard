<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: index.php");
    exit();
}
require_once 'db_config.php';

// Initial data for fast first paint
$count_today = $conn->query("SELECT COUNT(*) as total FROM alerts WHERE DATE(timestamp) = CURDATE()")->fetch_assoc();
$high_severity = $conn->query("SELECT COUNT(*) as total FROM alerts WHERE severity = 'High' AND DATE(timestamp) = CURDATE()")->fetch_assoc();

$sql = "SELECT alerts.*, drivers.full_name 
        FROM alerts 
        JOIN drivers ON alerts.driver_id = drivers.driver_id 
        ORDER BY timestamp DESC LIMIT 20";
$result = $conn->query($sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NeuroGuard Pro Dashboard</title>
    <link rel="stylesheet" href="dashboard.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
</head>
<body>
    <h2>
        <span>🚀 NeuroGuard Pro Dashboard</span>
        <div style="display: flex; gap: 10px;">
            <a href="portal.php" class="logout-btn">Launchpad</a>
            <a href="monitor.php" class="logout-btn" style="background: rgba(56, 189, 248, 0.1); color: var(--accent-blue);">Open Monitor</a>
            <a href="logout.php" class="logout-btn">Logout</a>
        </div>
    </h2>

    <div class="stats-container">
        <div class="stat-box">
            <span class="stat-number" id="total-alerts"><?php echo $count_today['total']; ?></span>
            <span class="stat-label">Total Alerts Today</span>
        </div>
        <div class="stat-box">
            <span class="stat-number urgent-text" id="high-severity"><?php echo $high_severity['total']; ?></span>
            <span class="stat-label">High Severity (Drowsy)</span>
        </div>
    </div>

    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Driver Name</th>
                    <th>Alert Type</th>
                    <th>Severity</th>
                    <th>Time</th>
                </tr>
            </thead>
            <tbody id="alerts-body">
                <?php while($row = $result->fetch_assoc()): 
                    $typeClass = "badge-" . strtolower($row['alert_type']);
                ?>
                <tr data-id="<?php echo $row['alert_id']; ?>">
                    <td><?php echo $row['alert_id']; ?></td>
                    <td><?php echo $row['full_name']; ?></td>
                    <td><span class="badge <?php echo $typeClass; ?>"><?php echo $row['alert_type']; ?></span></td>
                    <td><?php echo $row['severity']; ?></td>
                    <td style="color: var(--text-muted)"><?php echo $row['timestamp']; ?></td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>

    <script src="dashboard.js"></script>
</body>
</html>