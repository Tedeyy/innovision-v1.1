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
    <title>Dashboard</title>
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
        </div>
        <div class="hamburger" aria-label="Toggle menu">
            <span></span>
            <span></span>
            <span></span>
        </div>
        <div class="nav-center" style="display:flex;gap:16px;align-items:center;">
            <a class="btn" href="pages/interests.php">Interests</a>
            <a class="btn" href="pages/pricewatch.php">Price Watch</a>
            <a class="btn" href="pages/purchases.php">Purchases</a>
            <a class="btn" href="pages/transactions.php">Transactions</a>
            <a class="btn" href="pages/userreport.php">User Report</a>
        </div>
        <div class="nav-right">
            <div class="greeting">hello <?php echo htmlspecialchars($firstname, ENT_QUOTES, 'UTF-8'); ?> • <?php echo htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8'); ?></div>
            <a class="btn" href="../logout.php">Logout</a>
            <a class="notify" href="#" aria-label="Notifications" title="Notifications" style="position:relative;">
                <span class="avatar">🔔</span>
                <span id="notifBadge" style="display:none;position:absolute;top:-4px;right:-4px;background:#ef4444;color:#fff;border-radius:999px;padding:0 6px;font-size:10px;line-height:16px;min-width:16px;text-align:center;">0</span>
            </a>
            <a class="profile" href="pages/profile.php" aria-label="Profile">
                <span class="avatar">👤</span>
            </a>
        </div>
    </nav>
    <!-- Mobile Menu -->
    <div class="mobile-menu">
        <a href="pages/interests.php">Interests</a>
        <a href="pages/pricewatch.php">Price Watch</a>
        <a href="pages/purchases.php">Purchases</a>
        <a href="pages/transactions.php">Transactions</a>
        <a href="pages/userreport.php">User Report</a>
        <a href="../logout.php">Logout</a>
        <a href="pages/profile.php">Profile</a>
    </div>
    <div class="menu-overlay"></div>
    <div id="notifPane" style="display:none;position:fixed;top:56px;right:16px;width:300px;max-height:50vh;overflow:auto;background:#ffffff;border:1px solid #e5e7eb;border-radius:10px;box-shadow:0 10px 20px rgba(0,0,0,.08);z-index:10000;">
        <div style="padding:10px 12px;border-bottom:1px solid #f3f4f6;font-weight:600;">Notifications (<span id=\"notifCount\">0</span>)</div>
        <div id="notifList" style="padding:8px 0;">
            <div style="padding:10px 12px;color:#6b7280;">No notifications</div>
        </div>
    </div>
    <div class="wrap">
        <div class="top">
            <div>
                <h1>Dashboard</h1>
            </div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px;"></div>
        <div class="card">
            <div style="font-weight:600;margin-bottom:6px;">Your Area</div>
            <div id="map" style="height:260px;border:1px solid #e2e8f0;border-radius:8px"></div>
        </div>
        <div class="card">
          <div style="font-weight:600;margin-bottom:6px;">Market Activity</div>
          <div style="height:260px"><canvas id="salesLineChart"></canvas></div>
        </div>
        <div class="card">
            <div style="font-weight:600;margin-bottom:6px;">Marketplace</div>
            <iframe src="pages/market.php" style="width:100%;height:100vh;border:1px solid #e2e8f0;border-radius:8px;" loading="lazy"></iframe>
        </div>
        <div id="geoStatus" style="margin-top:8px;color:#4a5568;font-size:14px"></div>
    </div>
    <script src="/pages/script/notifications.js"></script>
    <script src="script/dashboard.js"></script>
    <script src="/pages/script/mobile-menu.js"></script>
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
    <script>
      (function(){
        var btn = document.querySelector('.notify');
        var pane = document.getElementById('notifPane');
        var badge = document.getElementById('notifBadge');
        var listEl = document.getElementById('notifList');
        var countEl = document.getElementById('notifCount');

        function render(list){
          var n = Array.isArray(list) ? list.length : 0;
          // Count label inside pane (total items)
          if (countEl){ countEl.textContent = String(n); }
          if (!listEl) return;
          listEl.innerHTML = '';
          if (n === 0){
            var empty = document.createElement('div');
            empty.style.cssText = 'padding:10px 12px;color:#6b7280;';
            empty.textContent = 'No notifications';
            listEl.appendChild(empty);
            return;
          }
          list.forEach(function(item){
            var row = document.createElement('div');
            row.style.cssText = 'padding:10px 12px;border-bottom:1px solid #f3f4f6;';
            var title = item && item.title ? item.title : '';
            var msg = item && item.message ? item.message : '';
            row.textContent = (title ? title+': ' : '') + msg;
            listEl.appendChild(row);
          });
        }

        // notifications.js will call this with items from notifications_api.php
        window.updateNotifications = function(list){ render(list||[]); };

        if (btn){
          btn.addEventListener('click', function(e){
            e.preventDefault();
            if (!pane) return;
            pane.style.display = (pane.style.display==='none'||pane.style.display==='') ? 'block' : 'none';
          });
        }
        document.addEventListener('click', function(e){
          if (!pane || !btn) return;
          if (!pane.contains(e.target) && !btn.contains(e.target)) { pane.style.display = 'none'; }
        });

        // Initial state; notifications.js will populate shortly
        render([]);
      })();
    </script>
  </body>
</html>