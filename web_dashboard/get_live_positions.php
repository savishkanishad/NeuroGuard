<?php
// ============================================================
// get_live_positions.php
// ------------------------------------------------------------
// Returns each driver's most recent GPS ping from live_positions,
// for the Live Map (map.php) to plot as a "live dot" alongside
// alert markers from get_alerts_json.php.
//
// A row is flagged is_live = true if it was updated within the
// last LIVE_WINDOW_SECONDS — otherwise the driver is treated as
// offline/stale (browser tab closed, GPS/permission lost, etc.)
// and the frontend fades or hides it rather than showing a dot
// that's actually gone quiet.
// ============================================================

session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['admin_logged_in'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated', 'positions' => []]);
    exit();
}

require_once 'db_config.php';

const LIVE_WINDOW_SECONDS = 20;

$positions = [];
$db_error = null;

if ($conn !== null) {
    try {
        $sql = "SELECT live_positions.driver_id, live_positions.session_id, drivers.full_name,
                       live_positions.latitude, live_positions.longitude, live_positions.accuracy,
                       live_positions.location_source, live_positions.updated_at,
                       TIMESTAMPDIFF(SECOND, live_positions.updated_at, NOW()) AS age_seconds
                FROM live_positions
                JOIN drivers ON live_positions.driver_id = drivers.driver_id
                ORDER BY live_positions.updated_at DESC";
        $result = $conn->query($sql);

        if ($result === false) {
            // Most likely cause: live_positions table doesn't exist yet — run
            // migrate_live_location.sql against your database.
            $db_error = $conn->error;
        } else {
            while ($row = $result->fetch_assoc()) {
                $age = (int)$row['age_seconds'];
                $row['age_seconds'] = $age;
                $row['is_live'] = $age <= LIVE_WINDOW_SECONDS;
                $positions[] = $row;
            }
        }
    } catch (Throwable $e) {
        $db_error = $e->getMessage();
    }
}

$response = ['positions' => $positions];

// Visit get_live_positions.php?debug=1 to see the raw DB error if the
// live dot isn't showing up.
if ($db_error !== null && isset($_GET['debug'])) {
    $response['db_error'] = $db_error;
}

echo json_encode($response);

if ($conn !== null) {
    $conn->close();
}
?>
