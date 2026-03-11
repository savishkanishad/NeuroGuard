async function fetchAlerts() {
    try {
        const response = await fetch('get_alerts_json.php');
        const data = await response.json();

        // Update Stats
        document.getElementById('total-alerts').textContent = data.stats.total_today;
        document.getElementById('high-severity').textContent = data.stats.high_severity;

        // Update Table
        const tbody = document.getElementById('alerts-body');
        const currentIds = Array.from(tbody.querySelectorAll('tr')).map(tr => tr.dataset.id);
        
        // Clear and rebuild or just prepend new ones?
        // For simplicity in this demo, we'll rebuild if changes detected or just always rebuild nicely.
        let html = '';
        data.alerts.forEach(alert => {
            const badgeClass = `badge-${alert.alert_type.toLowerCase()}`;
            const isNew = !currentIds.includes(alert.alert_id);
            
            html += `
                <tr data-id="${alert.alert_id}" class="${isNew ? 'new-row' : ''}">
                    <td>${alert.alert_id}</td>
                    <td>${alert.full_name}</td>
                    <td><span class="badge ${badgeClass}">${alert.alert_type}</span></td>
                    <td>${alert.severity}</td>
                    <td style="color: var(--text-muted)">${alert.timestamp}</td>
                </tr>
            `;
        });
        tbody.innerHTML = html;

    } catch (error) {
        console.error('Error fetching alerts:', error);
    }
}

// Initial fetch
fetchAlerts();

// Set interval for every 5 seconds
setInterval(fetchAlerts, 5000);
