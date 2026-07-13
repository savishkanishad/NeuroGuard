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

        /* Live driver GPS position — pulsing dot + accuracy circle on the map */
        .live-dot-wrapper { background: transparent !important; border: none !important; }
        .live-dot {
            display: block;
            width: 16px; height: 16px;
            border-radius: 50%;
            background: var(--dot-color, #38bdf8);
            border: 2px solid rgba(255,255,255,0.9);
            box-shadow: 0 0 0 rgba(56,189,248,0.6);
            animation: live-pulse 1.8s infinite;
        }
        @keyframes live-pulse {
            0%   { box-shadow: 0 0 0 0 rgba(56,189,248,0.55); }
            70%  { box-shadow: 0 0 0 14px rgba(56,189,248,0); }
            100% { box-shadow: 0 0 0 0 rgba(56,189,248,0); }
        }

        .tracker-subhead {
            font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.06em;
            margin: 18px 0 10px; color: var(--text-muted);
            display: flex; align-items: center; justify-content: space-between;
        }
        .live-badge {
            display: inline-block; margin-left: 8px; font-size: 10px; font-weight: 800;
            letter-spacing: 0.05em; padding: 2px 8px; border-radius: 999px; vertical-align: middle;
        }
        .live-badge.on { background: rgba(16, 185, 129, 0.18); color: #34d399; }
        .live-badge.off { background: rgba(148, 163, 184, 0.18); color: #94a3b8; }

        .map-legend { display: flex; gap: 14px; flex-wrap: wrap; margin-top: 10px; font-size: 11px; color: var(--text-muted); }
        .map-legend span { display: inline-flex; align-items: center; gap: 6px; }
        .legend-dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; flex-shrink: 0; }

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
                    <div class="tracker-subtitle">Alert history + live browser GPS, refreshed every 5s.</div>
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

            <div class="tracker-subhead">
                <span>Live Driver Positions (Browser GPS)</span>
            </div>
            <ul id="live-position-list" class="tracker-list"></ul>
        </div>

        <div class="map-card">
            <div class="map-toolbar">
                <strong>Live positions + recent alerts</strong>
                <span>Auto-refreshes every 5s</span>
            </div>
            <div id="map"></div>
            <div class="map-legend">
                <span><span class="legend-dot" style="background:#38bdf8;"></span>Live GPS position</span>
                <span><span class="legend-dot" style="background:#ef4444;"></span>Critical / Drowsy alert</span>
                <span><span class="legend-dot" style="background:#f97316;"></span>High / Medium alert</span>
                <span><span class="legend-dot" style="background:#94a3b8;"></span>Estimated (no precise GPS)</span>
            </div>
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

        var greyIcon = L.icon({
            iconUrl: 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-2x-grey.png',
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

        function pulsingIcon(color) {
            return L.divIcon({
                className: 'live-dot-wrapper',
                html: `<span class="live-dot" style="--dot-color:${color}"></span>`,
                iconSize: [18, 18],
                iconAnchor: [9, 9]
            });
        }

        function ageLabel(ageSeconds) {
            const age = Math.max(0, Math.round(ageSeconds || 0));
            if (age < 5) return 'just now';
            if (age < 60) return `${age}s ago`;
            const m = Math.floor(age / 60);
            return `${m}m ago`;
        }

        function isEstimated(alert) {
            return alert.location_source === 'fallback' || alert.location_source === 'server_fallback';
        }

        function sourceLabel(alert) {
            switch (alert.location_source) {
                case 'gps': return 'GPS';
                case 'ip': return 'IP-based estimate';
                case 'fallback': return 'Estimated (no GPS/IP)';
                case 'server_fallback': return 'Estimated (no location sent)';
                default: return 'Unknown source';
            }
        }

        function hasCoords(alert) {
            const lat = parseFloat(alert.latitude);
            const lng = parseFloat(alert.longitude);
            return isFinite(lat) && isFinite(lng) && (lat !== 0 || lng !== 0);
        }

        function getMarkerIcon(alert) {
            if (isEstimated(alert)) return greyIcon;
            if (alert.severity === 'Critical') return criticalIcon;
            if (alert.severity === 'High') return highIcon;
            return highIcon;
        }

        function updateTracker(alert, statusOverride) {
            if (!alert) {
                document.getElementById('tracker-status').textContent = statusOverride || 'Waiting';
                document.getElementById('tracker-status').className = 'tracker-status';
                document.getElementById('tracker-driver').textContent = '—';
                document.getElementById('tracker-type').textContent = '—';
                document.getElementById('tracker-severity').textContent = '—';
                document.getElementById('tracker-location').textContent = '—';
                document.getElementById('tracker-time').textContent = '—';
                return;
            }

            document.getElementById('tracker-status').textContent = isEstimated(alert) ? 'Live (estimated location)' : 'Live';
            document.getElementById('tracker-status').className = 'tracker-status live';
            document.getElementById('tracker-driver').textContent = alert.full_name || 'Unknown driver';
            document.getElementById('tracker-type').textContent = alert.alert_type || 'Alert';
            document.getElementById('tracker-severity').textContent = alert.severity || 'Unknown';
            document.getElementById('tracker-location').textContent = formatLocation(alert) + (hasCoords(alert) ? ` (${sourceLabel(alert)})` : '');
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
                    <div class="tracker-meta">${escapeHtml(alert.alert_type || 'Alert')} · ${escapeHtml(alert.severity || 'Unknown')} · ${escapeHtml(formatLocation(alert))}</div>
                    <div class="tracker-meta">${escapeHtml(alert.timestamp || '')}${hasCoords(alert) ? ' · ' + escapeHtml(sourceLabel(alert)) : ''}</div>
                </li>
            `).join('');
        }

        function formatLocation(alert) {
            if (!hasCoords(alert)) {
                return 'Unavailable';
            }
            const lat = parseFloat(alert.latitude);
            const lng = parseFloat(alert.longitude);
            return `${lat.toFixed(5)}, ${lng.toFixed(5)}`;
        }

        // ── LIVE DRIVER POSITIONS (browser GPS, independent of alerts) ─────────
        var livePositionMarkers = {}; // driver_id -> { marker, circle }
        var hasCenteredOnLive = false;

        function renderLivePanel(positions) {
            const list = document.getElementById('live-position-list');
            if (!positions.length) {
                list.innerHTML = '<li class="tracker-empty">No driver has shared a live location yet.</li>';
                return;
            }

            list.innerHTML = positions.map(p => `
                <li class="tracker-item">
                    <strong>${escapeHtml(p.full_name || 'Unknown driver')}</strong>
                    <span class="live-badge ${p.is_live ? 'on' : 'off'}">${p.is_live ? 'LIVE' : 'STALE'}</span>
                    <div class="tracker-meta">
                        ${parseFloat(p.latitude).toFixed(5)}, ${parseFloat(p.longitude).toFixed(5)}
                        ${p.accuracy ? ` · ±${Math.round(p.accuracy)}m` : ''}
                    </div>
                    <div class="tracker-meta">${ageLabel(p.age_seconds)} · ${escapeHtml(sourceLabel({ location_source: p.location_source }))}</div>
                </li>
            `).join('');
        }

        async function updateLivePositions() {
            try {
                const response = await fetch('get_live_positions.php');
                const data = await response.json();
                const positions = Array.isArray(data.positions) ? data.positions : [];
                const liveOnly = positions.filter(p => p.is_live);

                // Remove dots/circles for drivers who've gone stale or dropped off.
                const currentIds = liveOnly.map(p => String(p.driver_id));
                Object.keys(livePositionMarkers).forEach(id => {
                    if (!currentIds.includes(id)) {
                        map.removeLayer(livePositionMarkers[id].marker);
                        map.removeLayer(livePositionMarkers[id].circle);
                        delete livePositionMarkers[id];
                    }
                });

                liveOnly.forEach(p => {
                    const lat = parseFloat(p.latitude);
                    const lng = parseFloat(p.longitude);
                    // Floor the circle radius so a very precise fix (a few meters)
                    // still renders as a visible ring rather than disappearing.
                    const accuracyM = p.accuracy ? Math.max(parseFloat(p.accuracy), 15) : 30;
                    const id = String(p.driver_id);

                    const popupHtml = `
                        <strong>${escapeHtml(p.full_name || 'Unknown driver')}</strong><br>
                        Live position · ${escapeHtml(sourceLabel({ location_source: p.location_source }))}<br>
                        Accuracy: ±${Math.round(accuracyM)}m<br>
                        Updated: ${ageLabel(p.age_seconds)}
                    `;

                    if (livePositionMarkers[id]) {
                        livePositionMarkers[id].marker.setLatLng([lat, lng]);
                        livePositionMarkers[id].marker.setPopupContent(popupHtml);
                        livePositionMarkers[id].circle.setLatLng([lat, lng]);
                        livePositionMarkers[id].circle.setRadius(accuracyM);
                    } else {
                        const marker = L.marker([lat, lng], { icon: pulsingIcon('#38bdf8'), zIndexOffset: 1000 }).addTo(map);
                        marker.bindPopup(popupHtml);
                        const circle = L.circle([lat, lng], {
                            radius: accuracyM, color: '#38bdf8', fillColor: '#38bdf8',
                            fillOpacity: 0.12, weight: 1
                        }).addTo(map);
                        livePositionMarkers[id] = { marker, circle };
                    }
                });

                renderLivePanel(positions);

                // Center on the first live driver once, on initial load, so the
                // map doesn't open on the wrong side of the world. After that,
                // the dot moves freely without yanking the user's view around —
                // only a *new alert* (see updateMap) recenters the map afterward.
                if (!hasCenteredOnLive && liveOnly.length) {
                    const first = liveOnly[0];
                    map.setView([parseFloat(first.latitude), parseFloat(first.longitude)], 14);
                    hasCenteredOnLive = true;
                }
            } catch (error) {
                console.error('Error fetching live positions:', error);
            }
        }

        async function updateMap() {
            try {
                const response = await fetch('get_alerts_json.php');
                const data = await response.json();
                const alerts = Array.isArray(data.alerts) ? data.alerts : [];
                const alertsWithCoords = alerts.filter(hasCoords);

                const currentIds = alertsWithCoords.map(alert => String(alert.alert_id));
                Object.keys(markers).forEach(id => {
                    if (!currentIds.includes(id)) {
                        map.removeLayer(markers[id]);
                        delete markers[id];
                    }
                });

                alertsWithCoords.forEach(alert => {
                    const lat = parseFloat(alert.latitude);
                    const lng = parseFloat(alert.longitude);
                    const alertId = String(alert.alert_id);

                    if (alert.severity === 'High' || alert.severity === 'Critical' || alert.alert_type === 'Drowsy' || alert.alert_type === 'Microsleep') {
                        const popupHtml = `
                            <strong>${escapeHtml(alert.full_name || 'Unknown driver')}</strong><br>
                            Type: ${escapeHtml(alert.alert_type)} (${escapeHtml(alert.severity)})<br>
                            Time: ${escapeHtml(alert.timestamp)}<br>
                            Location: ${escapeHtml(formatLocation(alert))}<br>
                            Source: ${escapeHtml(sourceLabel(alert))}
                        `;

                        if (markers[alertId]) {
                            markers[alertId].setLatLng([lat, lng]);
                            markers[alertId].setIcon(getMarkerIcon(alert));
                            markers[alertId].bindPopup(popupHtml);
                        } else {
                            const marker = L.marker([lat, lng], { icon: getMarkerIcon(alert) }).addTo(map);
                            marker.bindPopup(popupHtml);
                            markers[alertId] = marker;
                        }
                    }
                });

                const latestAlert = alertsWithCoords[0] || alerts[0] || null;
                if (latestAlert && hasCoords(latestAlert)) {
                    updateTracker(latestAlert);
                    if (latestTrackedAlertId !== String(latestAlert.alert_id)) {
                        map.setView([parseFloat(latestAlert.latitude), parseFloat(latestAlert.longitude)], 13);
                        latestTrackedAlertId = String(latestAlert.alert_id);
                    }
                } else if (latestAlert) {
                    // Alerts exist but none carry latitude/longitude — most likely
                    // cause: they were logged without location data (e.g. sent via
                    // Postman/API without lat/lng, or before GPS capture was added).
                    console.warn('[NeuroGuard] Alerts found, but none have coordinates:', alerts);
                    updateTracker(null, 'No location data');
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

        updateLivePositions();
        setInterval(updateLivePositions, 5000);
    </script>
</body>
</html>
