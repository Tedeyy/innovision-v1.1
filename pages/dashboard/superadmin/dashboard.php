<?php
session_start();
$firstname = isset($_SESSION['firstname']) && $_SESSION['firstname'] !== '' ? $_SESSION['firstname'] : 'User';
require_once __DIR__ . '/../../authentication/lib/supabase_client.php';
// Compute verification status from role and source table
$role = $_SESSION['role'] ?? '';
$src  = $_SESSION['source_table'] ?? '';
$isVerified = false;
if ($role === 'superadmin') {
    $isVerified = ($src === 'superadmin');
}
$statusLabel = $isVerified ? 'Verified' : 'Under review';
// Fetch pending approval counts
$preBatCount = 0; $reviewAdminCount = 0; $logs = [];
[$batr,$bath,$bate] = sb_rest('GET', 'preapprovalbat', ['select'=>'user_id']);
if ($bath>=200 && $bath<300 && is_array($batr)) { $preBatCount = count($batr); }
[$adr,$adh,$ade] = sb_rest('GET', 'reviewadmin', ['select'=>'user_id']);
if ($adh>=200 && $adh<300 && is_array($adr)) { $reviewAdminCount = count($adr); }
// Fetch livestock listing logs
[$logRows,$logSt,$logErr] = sb_rest('GET','livestocklisting_logs',['select'=>'*']);
if ($logSt>=200 && $logSt<300 && is_array($logRows)) { $logs = $logRows; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Superadmin Dashboard</title>
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
            <div class="greeting">hello <?php echo htmlspecialchars($firstname, ENT_QUOTES, 'UTF-8'); ?></div>
            <a class="btn" href="pages/generatereports.php">Generate Report</a>
            <a class="btn" href="pages/logs.php">Logs</a>
            <a class="btn" href="../logout.php">Logout</a>
            <a class="profile" href="pages/profile.php" aria-label="Profile">
                <span class="avatar">👤</span>
            </a>
        </div>
    </nav>
    <div class="wrap">
        <div class="top">
            <div>
                <h1>Superadmin Dashboard</h1>
            </div>
        </div>
        <div class="card">
            <p>Manage platform-wide settings and users here.</p>
        </div>
        <div class="card">
            <h3>Pending Account Approvals</h3>
            <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:12px;margin:12px 0;">
                <div class="card" style="padding:12px;">
                    <div style="color:#4a5568;font-size:12px;">BATs Waiting (preapprovalbat)</div>
                    <div style="font-size:20px;font-weight:600;"><?php echo (int)$preBatCount; ?></div>
                </div>
                <div class="card" style="padding:12px;">
                    <div style="color:#4a5568;font-size:12px;">Admins Waiting (reviewadmin)</div>
                    <div style="font-size:20px;font-weight:600;"><?php echo (int)$reviewAdminCount; ?></div>
                </div>
            </div>
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
                window.superadminMap = map;
              })();
              </script>
            </div>
            <script>
            (function(){
              if (!('geolocation' in navigator)) { return; }
              navigator.geolocation.getCurrentPosition(function(pos){
                var lat = pos.coords.latitude; var lng = pos.coords.longitude;
                try {
                  if (window.superadminMap && window.L){
                    window.superadminMap.setView([lat, lng], 15);
                    L.marker([lat, lng]).addTo(window.superadminMap);
                  }
                } catch(e){}
              }, function(err){}, { enableHighAccuracy:true, timeout:10000, maximumAge:300000 });
            })();
            </script>
        </div>

        <div class="card">
            <h3>Livestock Listing Logs</h3>
            <div style="overflow:auto;">
                <table aria-label="Livestock Listing Logs" style="width:100%;border-collapse:collapse;">
                    <thead>
                        <tr>
                            <?php
                            $logHeaders = [];
                            if (is_array($logs) && count($logs)>0) {
                                $logHeaders = array_keys($logs[0]);
                            }
                            foreach ($logHeaders as $h) {
                                echo '<th style="text-align:left;border-bottom:1px solid #e5e7eb;padding:8px 6px;">'.htmlspecialchars($h, ENT_QUOTES, 'UTF-8').'</th>';
                            }
                            ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (is_array($logs)):
                            foreach ($logs as $row): ?>
                                <tr>
                                    <?php foreach ($logHeaders as $h): $v = isset($row[$h]) ? $row[$h] : ''; ?>
                                        <td style="border-bottom:1px solid #f3f4f6;padding:8px 6px;"><?php echo htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); ?></td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>
