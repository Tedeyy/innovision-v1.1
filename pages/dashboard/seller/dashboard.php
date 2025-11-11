<?php
session_start();
$firstname = isset($_SESSION['firstname']) && $_SESSION['firstname'] !== '' ? $_SESSION['firstname'] : 'User';
// Compute verification status from role and source table
$role = $_SESSION['role'] ?? '';
$src  = $_SESSION['source_table'] ?? '';
$isVerified = false;
if ($role === 'seller') {
    $isVerified = ($src === 'seller');
}
$statusLabel = $isVerified ? 'Verified' : 'Under review';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Seller Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style/dashboard.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    </head>
<body>
    <nav class="navbar">
        <div class="nav-left">
            <div class="brand">Dashboard</div>
            <form class="search" method="get" action="#">
                <input type="search" name="q" placeholder="Search" />
            </form>
        </div>
        <div class="nav-right">
            <div class="greeting">hello <?php echo htmlspecialchars($firstname, ENT_QUOTES, 'UTF-8'); ?> • <?php echo htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8'); ?></div>
            <a class="btn" href="../logout.php">Logout</a>
            <a class="profile" href="pages/profile.php" aria-label="Profile">
                <span class="avatar">👤</span>
            </a>
        </div>
    </nav>
    <div class="wrap">
        <div class="top">
            <div>
                <h1>Seller Dashboard</h1>
            </div>
            <div>
                <a class="btn" href="pages/createlisting.php">Create Listing</a>
            </div>
        </div>
        <div class="card">
            <p>Manage your listings and view recent activity here.</p>
        </div>

        <div class="card">
            <h3>Service Coverage Map</h3>
            <div id="map" class="mapbox" aria-label="Map placeholder">
              <script>
              (function(){
                if (!window.L) return;
                var mapEl = document.getElementById('map');
                if (!mapEl) return;
                var map = L.map(mapEl).setView([8.314209 , 124.859425], 14);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                  maxZoom: 19,
                  attribution: '&copy; OpenStreetMap contributors'
                }).addTo(map);
                window.sellerMap = map; // expose for geolocation updates
              })();
              </script>
            </div>
            <div id="geoStatus" style="margin-top:8px;color:#4a5568;font-size:14px"></div>
        </div>

        <div class="card">
            <h3>Livestock Aid (Sales Projection)</h3>
            <div class="chartbox"><canvas id="salesLineChart"></canvas></div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script>
    (function(){
      var ctx = document.getElementById('salesLineChart');
      if (!ctx || !window.Chart) return;
      function monthLabels(){
        const now = new Date();
        const labels = [];
        for (let i = -3; i <= 1; i++) {
          const d = new Date(now.getFullYear(), now.getMonth()+i, 1);
          labels.push(d.toLocaleString('en', { month: 'short' }));
        }
        return labels;
      }
      const labels = monthLabels();
      const empty = labels.map(() => null);
      new Chart(ctx, {
        type: 'line',
        data: {
          labels: labels,
          datasets: [
            { label: 'Cattle', data: empty, borderColor: '#8B4513', backgroundColor: 'transparent', tension: 0.3, spanGaps: true, pointRadius: 0 },
            { label: 'Goat', data: empty, borderColor: '#16a34a', backgroundColor: 'transparent', tension: 0.3, spanGaps: true, pointRadius: 0 },
            { label: 'Pigs', data: empty, borderColor: '#ec4899', backgroundColor: 'transparent', tension: 0.3, spanGaps: true, pointRadius: 0 }
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          animation: false,
          plugins: { legend: { position: 'bottom' } },
          scales: { y: { beginAtZero: true, suggestedMin: 0 } }
        }
      });
    })();
    </script>
    <script>
    (function(){
      // Only prompt for roles that use location: seller/buyer/bat; this is seller page.
      if (!('geolocation' in navigator)) { return; }
      var statusEl = document.getElementById('geoStatus');
      function setStatus(msg){ if (statusEl) statusEl.textContent = msg; }
      navigator.geolocation.getCurrentPosition(function(pos){
        var lat = pos.coords.latitude; var lng = pos.coords.longitude;
        setStatus('Your location: ' + lat.toFixed(6) + ', ' + lng.toFixed(6));
        try {
          if (window.sellerMap && window.L){
            window.sellerMap.setView([lat, lng], 15);
            L.marker([lat, lng]).addTo(window.sellerMap);
          }
        } catch(e){}
        var fd = new FormData(); fd.append('lat', lat); fd.append('lng', lng);
        fetch('../update_location.php', { method:'POST', body: fd, credentials:'same-origin' }).catch(function(){});
      }, function(err){
        setStatus('Location access denied or unavailable.');
      }, { enableHighAccuracy:true, timeout:10000, maximumAge:300000 });
    })();
    </script>
</body>
</html>