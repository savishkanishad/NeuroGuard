<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: index.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Live Map - NeuroGuard Pro</title>
    <link rel="stylesheet" href="dashboard.css?v=<?php echo time(); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin=""/>
    <style>
        body { overflow-x: hidden; }
        #map { height: 620px; width: 100%; border-radius: 16px; border: 1px solid var(--glass-border); z-index: 1; }
        .leaflet-container { background: #0f172a; }
        .tracker-shell { display: grid; grid-template-columns: 340px 1fr; gap: 20px; margin-top: 20px; align-items: start; }
        .tracker-card, .map-card {
            background: rgba(15, 23, 42, 0.9);
            border: 1px solid var(--glass-border);
            border-radius: 20px;
            box-shadow: 0 18px 50px rgba(2, 6, 23, 0.35);
            overflow: hidden;
        }
        .tracker-card { padding: 18px; }
        .tracker-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; margin-bottom: 14px; }
        .tracker-title { font-size: 18px; font-weight: 700; }
        .tracker-subtitle { color: var(--text-muted); font-size: 12px; margin-top: 4px; }
        .tracker-status { padding: 6px 10px; border-radius: 999px; background: rgba(56, 189, 248, 0.14); color: var(--accent-blue); font-size: 12px; font-weight: 700; white-space: nowrap; }
        .tracker-status.live { background: rgba(16, 185, 129, 0.16); color: #34d399; }
        .tracker-details { display: grid; gap: 10px; margin-bottom: 14px; }
        .tracker-row { display: flex; justify-content: space-between; gap: 10px; font-size: 13px; color: var(--text-muted); }
        .tracker-row strong { color: var(--text-main); }
        .tracker-list { list-style: none; padding: 0; margin: 0; display: grid; gap: 8px; }
        .tracker-item { padding: 10px 12px; background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.06); border-radius: 12px; }
        .tracker-item strong { display: block; margin-bottom: 4px; }
        .tracker-meta { color: var(--text-muted); font-size: 12px; }
        .tracker-empty { padding: 14px; text-align: center; color: var(--text-muted); font-size: 13px; border: 1px dashed rgba(255,255,255,0.12); border-radius: 12px; }
        .map-card { padding: 14px; }
        .map-toolbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; color: var(--text-muted); font-size: 13px; }
        .map-toolbar strong { color: var(--text-main); }
        @media (max-width: 920px) {
            .tracker-shell { grid-template-columns: 1fr; }
            #map { height: 480px; }
        }
    </style>
</head>
<body>
    <h2>
        <span>🗺️ Live Fleet Map</span>
        <div style="display: flex; gap: 10px;">
            <a href="dashboard.php" class="logout-btn">Back to Dashboard</a>
        </div>
    </h2>

    <div class="tracker-shell">
        <div class="tracker-card">
            <div class="tracker-head">
                <div>
                    <div class="tracker-title">Live Alert Tracker</div>
                    <div class="tracker-subtitle">Latest alert positions refresh automatically.</div>
                </div>
                <div id="tracker-status" class="tracker-status">Waiting</div>
            </div>
            <div class="tracker-details">
                <div class="tracker-row"><span>Driver</span><strong id="tracker-driver">—</strong></div>
                <div class="tracker-row"><span>Type</span><strong id="tracker-type">—</strong></div>
                <div class="tracker-row"><span>Severity</span><strong id="tracker-severity">—</strong></div>
                <div class="tracker-row"><span>Coordinates</span><strong id="tracker-location">—</strong></div>
                <div class="tracker-row"><span>Time</span><strong id="tracker-time">—</strong></div>
            </div>
            <ul id="tracker-list" class="tracker-list"></ul>
        </div>

        <div class="map-card">
            <div class="map-toolbar">
                <strong>Live location heatmap for recent alerts</strong>
                <span>Auto-centers on the newest alert</span>
            </div>
            <div id="map"></div>
        </div>
    </div>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
    <script>
        var map = L.map('map').setView([6.9271, 79.8612], 12);

        L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors &copy; <a href="https://carto.com/attributions">CARTO</a>',
            subdomains: 'abcd',
            maxZoom: 20
        }).addTo(map);

        var markers = {};
        var latestTrackedAlertId = null;

        var criticalIcon = L.icon({
            iconUrl: 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-2x-red.png',
            shadowUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/0.7.7/images/marker-shadow.png',
            iconSize: [25, 41], iconAnchor: [12, 41], popupAnchor: [1, -34], shadowSize: [41, 41]
        });

        var highIcon = L.icon({
            iconUrl: 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-2x-orange.png',
            shadowUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/0.7.7/images/marker-shadow.png',
            iconSize: [25, 41], iconAnchor: [12, 41], popupAnchor: [1, -34], shadowSize: [41, 41]
        });

        function escapeHtml(value) {
            return String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/\"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        function hasCoords(alert) {
            const lat = parseFloat(alert.latitude);
            const lng = parseFloat(alert.longitude);
            return isFinite(lat) && isFinite(lng) && (lat !== 0 || lng !== 0);
        }

        function getMarkerIcon(alert) {
            if (alert.severity === 'Critical') return criticalIcon;
            if (alert.severity === 'High') return highIcon;
            return highIcon;
        }

        function updateTracker(alert) {
            if (!alert) {
                document.getElementById('tracker-status').textContent = 'Waiting';
                document.getElementById('tracker-status').className = 'tracker-status';
                document.getElementById('tracker-driver').textContent = '—';
                document.getElementById('tracker-type').textContent = '—';
                document.getElementById('tracker-severity').textContent = '—';
                document.getElementById('tracker-location').textContent = '—';
                document.getElementById('tracker-time').textContent = '—';
                return;
            }

            const lat = parseFloat(alert.latitude);
            const lng = parseFloat(alert.longitude);
            document.getElementById('tracker-status').textContent = 'Live';
            document.getElementById('tracker-status').className = 'tracker-status live';
            document.getElementById('tracker-driver').textContent = alert.full_name || 'Unknown driver';
            document.getElementById('tracker-type').textContent = alert.alert_type || 'Alert';
            document.getElementById('tracker-severity').textContent = alert.severity || 'Unknown';
            document.getElementById('tracker-location').textContent = `${lat.toFixed(5)}, ${lng.toFixed(5)}`;
            document.getElementById('tracker-time').textContent = alert.timestamp || 'Recently logged';
        }

        function renderList(alerts) {
            const list = document.getElementById('tracker-list');
            const recentAlerts = alerts.slice(0, 6);

            if (!recentAlerts.length) {
                list.innerHTML = '<li class="tracker-empty">No alert locations available yet.</li>';
                return;
            }

            list.innerHTML = recentAlerts.map(alert => `
                <li class="tracker-item">
                    <strong>${escapeHtml(alert.full_name || 'Unknown driver')}</strong>
                    <div class="tracker-meta">${escapeHtml(alert.alert_type || 'Alert')} · ${escapeHtml(alert.severity || 'Unknown')} · ${parseFloat(alert.latitude).toFixed(4)}, ${parseFloat(alert.longitude).toFixed(4)}</div>
                    <div class="tracker-meta">${escapeHtml(alert.timestamp || '')}</div>
                </li>
            `).join('');
        }

        async function updateMap() {
            try {
                const response = await fetch('get_alerts_json.php');
                const data = await response.json();
                const alerts = (data.alerts || []).filter(hasCoords);

                const currentIds = alerts.map(alert => String(alert.alert_id));
                Object.keys(markers).forEach(id => {
                    if (!currentIds.includes(id)) {
                        map.removeLayer(markers[id]);
                        delete markers[id];
                    }
                });

                alerts.forEach(alert => {
                    const lat = parseFloat(alert.latitude);
                    const lng = parseFloat(alert.longitude);
                    const alertId = String(alert.alert_id);

                    if (alert.severity === 'High' || alert.severity === 'Critical' || alert.alert_type === 'Drowsy' || alert.alert_type === 'Microsleep') {
                        if (markers[alertId]) {
                            markers[alertId].setLatLng([lat, lng]);
                            markers[alertId].setIcon(getMarkerIcon(alert));
                            markers[alertId].bindPopup(`
                                <strong>${escapeHtml(alert.full_name || 'Unknown driver')}</strong><br>
                                Type: ${escapeHtml(alert.alert_type)} (${escapeHtml(alert.severity)})<br>
                                Time: ${escapeHtml(alert.timestamp)}<br>
                                Location: ${lat.toFixed(5)}, ${lng.toFixed(5)}
                            `);
                        } else {
                            const marker = L.marker([lat, lng], { icon: getMarkerIcon(alert) }).addTo(map);
                            marker.bindPopup(`
                                <strong>${escapeHtml(alert.full_name || 'Unknown driver')}</strong><br>
                                Type: ${escapeHtml(alert.alert_type)} (${escapeHtml(alert.severity)})<br>
                                Time: ${escapeHtml(alert.timestamp)}<br>
                                Location: ${lat.toFixed(5)}, ${lng.toFixed(5)}
                            `);
                            markers[alertId] = marker;
                        }
                    }
                });

                const latestAlert = alerts[0] || null;
                if (latestAlert) {
                    updateTracker(latestAlert);
                    if (latestTrackedAlertId !== String(latestAlert.alert_id)) {
                        map.setView([parseFloat(latestAlert.latitude), parseFloat(latestAlert.longitude)], 13);
                        latestTrackedAlertId = String(latestAlert.alert_id);
                    }
                } else {
                    updateTracker(null);
                }

                renderList(alerts);
            } catch (error) {
                console.error('Error fetching alerts for map:', error);
            }
        }

        updateMap();
        setInterval(updateMap, 5000);
    </script>
</body>
</html>
