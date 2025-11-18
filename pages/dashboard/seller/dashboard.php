<?php
session_start();
$firstname = isset($_SESSION['firstname']) && $_SESSION['firstname'] !== '' ? $_SESSION['firstname'] : 'User';
// Compute verification status from role and source table
$role = $_SESSION['role'] ?? '';
$src  = $_SESSION['source_table'] ?? '';
$isVerified = false;
if ($role === 'bat') {
    $isVerified = ($src === 'bat');
} elseif ($role === 'buyer') {
    $isVerified = ($src === 'buyer');
} elseif ($role === 'seller') {
    $isVerified = ($src === 'seller');
} elseif ($role === 'admin') {
    $isVerified = ($src === 'admin');
} elseif ($role === 'superadmin') {
    $isVerified = ($src === 'superadmin');
}
$statusLabel = $isVerified ? 'Verified' : 'Under review';

require_once __DIR__ . '/../../authentication/lib/supabase_client.php';
$sellerId = $_SESSION['user_id'] ?? null;
$pendingCount = 0; $activeCount = 0; $soldCount = 0; $deniedCount = 0;
if ($sellerId){
    // helper to count rows across multiple tables
    $countFrom = function($tables, $statusFilter = null) use ($sellerId){
        $sum = 0;
        foreach ($tables as $t){
            $params = ['select'=>'listing_id','seller_id'=>'eq.'.$sellerId];
            if ($statusFilter) {
                $params['status'] = 'eq.'.$statusFilter;
            }
            [$rows,$st,$err] = sb_rest('GET',$t,$params);
            if ($st>=200 && $st<300 && is_array($rows)) $sum += count($rows);
        }
        return $sum;
    };
    // Pending = reviewlivestocklisting + livestocklisting (support plural variants too)
    $pendingCount = $countFrom(['reviewlivestocklisting','reviewlivestocklistings','livestocklisting','livestocklistings']);
    // Active = activelivestocklisting where status is 'Verified'
    $activeCount = $countFrom(['activelivestocklisting','activelivestocklistings'], 'Verified');
    // Sold = activelivestocklisting where status is 'Sold'
    $soldCount   = $countFrom(['activelivestocklisting','activelivestocklistings'], 'Sold');
    // Denied
    $deniedCount = $countFrom(['deniedlivestocklisting','deniedlivestocklistings']);
}
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
            <a class="btn" href="pages/pricewatch.php">Price Watch</a>
            <a class="btn" href="pages/userreport.php">User Report</a>
            <a class="btn" href="pages/transactions.php">Transactions</a>
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
        <?php if (!empty($_SESSION['flash_message'])): ?>
            <div class="card" style="border-left:4px solid #10b981;color:#065f46;background:#ecfdf5;margin-bottom:8px;">
                <div style="padding:10px;"><?php echo htmlspecialchars((string)$_SESSION['flash_message'], ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
            <?php unset($_SESSION['flash_message']); ?>
        <?php endif; ?>
        <?php if (!empty($_SESSION['flash_error'])): ?>
            <div class="card" style="border-left:4px solid #ef4444;color:#7f1d1d;background:#fef2f2;margin-bottom:8px;">
                <div style="padding:10px;"><?php echo htmlspecialchars((string)$_SESSION['flash_error'], ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
            <?php unset($_SESSION['flash_error']); ?>
        <?php endif; ?>
        <div class="card">
            <p>Your Listings</p>
            <div class="listingstatus">
                <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin:12px 0;">
                    <div class="card" style="padding:12px;">
                        <div style="color:#4a5568;font-size:12px;">Pending Review</div>
                        <div style="font-size:20px;font-weight:600;"><?php echo (int)$pendingCount; ?></div>
                    </div>
                    <div class="card" style="padding:12px;">
                        <div style="color:#4a5568;font-size:12px;">Active</div>
                        <div style="font-size:20px;font-weight:600;"><?php echo (int)$activeCount; ?></div>
                    </div>
                    <div class="card" style="padding:12px;">
                        <div style="color:#4a5568;font-size:12px;">Sold</div>
                        <div style="font-size:20px;font-weight:600;"><?php echo (int)$soldCount; ?></div>
                    </div>
                    <div class="card" style="padding:12px;">
                        <div style="color:#4a5568;font-size:12px;">Denied</div>
                        <div style="font-size:20px;font-weight:600;"><?php echo (int)$deniedCount; ?></div>
                    </div>
                </div>
                
            </div>
        </div>
        <div class="card">
            <h3 style="margin-top:0">Manage Listings</h3>
            <iframe src="pages/managelistings.php" style="width:100%;height:80vh;border:1px solid #e2e8f0;border-radius:8px;" loading="lazy"></iframe>
        </div>

        <div class="card">
            <h3>Service Coverage Map</h3>
            <div id="map" class="mapbox" aria-label="Map placeholder"></div>
            <div id="geoStatus" style="margin-top:8px;color:#4a5568;font-size:14px"></div>
        </div>

        <div class="card">
            <h3>Livestock Aid (Sales Projection)</h3>
            <div class="chartbox"><canvas id="salesLineChart"></canvas></div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
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
        // Expose an updater so backend code can push notifications later
        window.updateNotifications = function(list){ render(list||[]); };
        if (btn){
          btn.addEventListener('click', function(e){ e.preventDefault(); if (!pane) return; pane.style.display = (pane.style.display==='none'||pane.style.display==='') ? 'block' : 'none'; });
        }
        document.addEventListener('click', function(e){ if (!pane || !btn) return; if (!pane.contains(e.target) && !btn.contains(e.target)) { pane.style.display = 'none'; } });
        // Initial state
        render(window.NOTIFS || []);
      })();
    </script>
</body>
</html>