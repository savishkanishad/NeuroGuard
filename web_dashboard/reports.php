<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: index.php");
    exit();
}
require_once 'db_config.php';

// Driver Risk Scoring
$risk_sql = "
    SELECT 
        d.driver_id, 
        d.full_name, 
        COUNT(DISTINCT s.session_id) as total_trips,
        COUNT(a.alert_id) as total_alerts,
        SUM(IF(a.severity='Critical', 1, 0)) as critical_alerts,
        SUM(IF(a.severity='High', 1, 0)) as high_alerts
    FROM drivers d
    LEFT JOIN sessions s ON d.driver_id = s.driver_id
    LEFT JOIN alerts a ON d.driver_id = a.driver_id
    GROUP BY d.driver_id
";
$risk_result = $conn->query($risk_sql);

// Trip-Level Reports
$trip_sql = "
    SELECT 
        s.session_id, 
        d.full_name, 
        s.start_time, 
        COUNT(a.alert_id) as alert_count, 
        SUM(IF(a.severity='Critical', 1, 0)) as critical_count,
        SUM(IF(a.severity='High', 1, 0)) as high_count
    FROM sessions s 
    JOIN drivers d ON s.driver_id = d.driver_id 
    LEFT JOIN alerts a ON s.session_id = a.session_id 
    GROUP BY s.session_id 
    ORDER BY s.start_time DESC
    LIMIT 50
";
$trip_result = $conn->query($trip_sql);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - NeuroGuard Pro</title>
    <link rel="stylesheet" href="dashboard.css?v=<?php echo time(); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        .risk-high { color: var(--accent-red); font-weight: bold; }
        .risk-medium { color: var(--accent-yellow); font-weight: bold; }
        .risk-low { color: var(--accent-blue); font-weight: bold; }
        .section-title { margin-top: 40px; margin-bottom: 20px; font-size: 24px; font-weight: 700; }
    </style>
</head>
<body>
    <h2>
        <span>📊 Fleet Reports</span>
        <div style="display: flex; gap: 10px;">
            <a href="dashboard.php" class="logout-btn">Back to Dashboard</a>
            <a href="export_csv.php" class="logout-btn" style="background: rgba(80, 220, 80, 0.1); color: #50dc50;">📥 Download CSV</a>
        </div>
    </h2>

    <div class="section-title">Driver Risk Scoring</div>
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Driver Name</th>
                    <th>Total Trips</th>
                    <th>Total Alerts</th>
                    <th>Critical / High Alerts</th>
                    <th>Risk Level</th>
                </tr>
            </thead>
            <tbody>
                <?php while($row = $risk_result->fetch_assoc()): 
                    $avg = $row['total_trips'] > 0 ? $row['total_alerts'] / $row['total_trips'] : 0;
                    $risk_class = "risk-low";
                    $risk_label = "Low";
                    if ($avg > 10 || $row['critical_alerts'] > 5) { $risk_class = "risk-high"; $risk_label = "High"; }
                    elseif ($avg > 4 || $row['critical_alerts'] > 1) { $risk_class = "risk-medium"; $risk_label = "Medium"; }
                ?>
                <tr>
                    <td><?php echo $row['full_name']; ?></td>
                    <td><?php echo $row['total_trips']; ?></td>
                    <td><?php echo $row['total_alerts']; ?></td>
                    <td><span class="urgent-text"><?php echo (int)$row['critical_alerts']; ?></span> / <span style="color:var(--accent-yellow)"><?php echo (int)$row['high_alerts']; ?></span></td>
                    <td class="<?php echo $risk_class; ?>"><?php echo $risk_label; ?></td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>

    <div class="section-title">Trip-Level Reports</div>
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Session ID</th>
                    <th>Driver Name</th>
                    <th>Start Time</th>
                    <th>Total Alerts</th>
                    <th>Critical Events</th>
                </tr>
            </thead>
            <tbody>
                <?php while($row = $trip_result->fetch_assoc()): ?>
                <tr>
                    <td>#<?php echo $row['session_id']; ?></td>
                    <td><?php echo $row['full_name']; ?></td>
                    <td style="color: var(--text-muted)"><?php echo $row['start_time']; ?></td>
                    <td><?php echo $row['alert_count']; ?></td>
                    <td>
                        <?php if($row['critical_count'] > 0): ?>
                            <span class="badge badge-drowsy" style="background: rgba(239, 68, 68, 0.4);"><?php echo $row['critical_count']; ?> Critical</span>
                        <?php else: ?>
                            <span style="color: var(--text-muted)">0</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</body>
</html>
