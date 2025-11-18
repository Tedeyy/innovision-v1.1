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
    <title>Dashboard</title>
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
        <div class="nav-center" style="display:flex;gap:16px;align-items:center;">
            <a class="btn" href="pages/generatereports.php">Generate Reports</a>
            <a class="btn" href="pages/usermanagement.php">User Management</a>
            <a class="btn" href="pages/price_actions.php">Price Actions</a>
            <a class="btn" href="pages/listing_actions.php">Listing Actions</a>
            <a class="btn" href="pages/security.php">Security</a>
            <a class="btn" href="pages/price_management.php" style="background:#4a5568;">Price Management</a>
        </div>
        <div class="nav-right">
            <div class="greeting">hello <?php echo htmlspecialchars($firstname, ENT_QUOTES, 'UTF-8'); ?></div>
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
            <a class="btn" href="pages/usermanagement.php" style="margin-top:8px;display:inline-block;">Manage</a>
        </div>

        <div class="card">
            <h3>Service Coverage Map</h3>
            <div id="map" class="mapbox" aria-label="Map placeholder"></div>
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
<script src="script/dashboard.js"></script>
<script>
  (function(){
    var btn = document.querySelector('.notify');
    var pane = document.getElementById('notifPane');
    var badge = document.getElementById('notifBadge');
    var listEl = document.getElementById('notifList');
    var countEl = document.getElementById('notifCount');
    function render(list){
      var n = Array.isArray(list) ? list.length : 0;
      if (badge){ badge.textContent = String(n); badge.style.display = n>0 ? 'inline-block' : 'none'; }
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
        row.textContent = item && item.text ? item.text : String(item);
        listEl.appendChild(row);
      });
    }
    window.updateNotifications = function(list){ render(list||[]); };
    if (btn){ btn.addEventListener('click', function(e){ e.preventDefault(); if (!pane) return; pane.style.display = (pane.style.display==='none'||pane.style.display==='') ? 'block' : 'none'; }); }
    document.addEventListener('click', function(e){ if (!pane || !btn) return; if (!pane.contains(e.target) && !btn.contains(e.target)) { pane.style.display = 'none'; } });
    render(window.NOTIFS || []);
  })();
</script>
</html>
