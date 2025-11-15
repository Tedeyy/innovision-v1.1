<?php
session_start();
$firstname = isset($_SESSION['firstname']) && $_SESSION['firstname'] !== '' ? $_SESSION['firstname'] : 'User';
// Compute verification status from role and source table
$role = $_SESSION['role'] ?? '';
$src  = $_SESSION['source_table'] ?? '';
$isVerified = false;
if ($role === 'buyer') {
    $isVerified = ($src === 'buyer');
}
$statusLabel = $isVerified ? 'Verified' : 'Under review';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Buyer Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style/dashboard.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
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
                <h1>Buyer Dashboard</h1>
            </div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px;">
            <a class="btn" href="pages/market.php">Go to Marketplace</a>
        </div>
        <div class="card">
            <div style="font-weight:600;margin-bottom:6px;">Your Area</div>
            <div id="map" style="height:260px;border:1px solid #e2e8f0;border-radius:8px"></div>
        </div>
        <div class="card">
            <div style="font-weight:600;margin-bottom:6px;">Market Activity</div>
            <div style="height:260px"><canvas id="salesLineChart"></canvas></div>
        </div>
        <div id="geoStatus" style="margin-top:8px;color:#4a5568;font-size:14px"></div>
    </div>
    <script src="script/dashboard.js"></script>
    <script>
      document.addEventListener('DOMContentLoaded', function(){
        // Map init
        (function(){
          if (!window.L) return;
          var mapEl = document.getElementById('map');
          if (!mapEl) return;
          var map = L.map(mapEl).setView([8.314209 , 124.859425], 14);
          L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap contributors'
          }).addTo(map);
          window.buyerMap = map;
        })();

        // Line chart init (mirror seller style)
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
      });
    </script>
</body>
</html>