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

// Prepare data for seller coverage map and sales chart
$coveragePins = ['activePins' => [], 'soldPins' => []];
$salesLabels = [];
$salesMonthKeys = [];
$salesSeries = [];

// Last 3 months + current month for sales chart
$now = new DateTime('first day of this month 00:00:00');
for ($i = -3; $i <= 1; $i++) {
    $d = (clone $now)->modify(($i >= 0 ? '+' : '') . $i . ' month');
    $salesLabels[] = $d->format('M');
    $salesMonthKeys[] = $d->format('Y-m');
}
$salesSeries = array_fill(0, count($salesMonthKeys), 0);

if ($sellerId) {
    // Aggregate completed sales from soldlivestocklisting for this seller
    [$soldRows, $soldSt, $soldErr] = sb_rest('GET', 'soldlivestocklisting', [
        'select'    => 'soldprice,price,created',
        'seller_id' => 'eq.' . $sellerId,
    ]);
    if ($soldSt >= 200 && $soldSt < 300 && is_array($soldRows)) {
        foreach ($soldRows as $row) {
            $created = isset($row['created']) ? substr((string)$row['created'], 0, 7) : null; // YYYY-MM
            if (!$created) { continue; }
            $idx = array_search($created, $salesMonthKeys, true);
            if ($idx === false) { continue; }
            $amount = 0.0;
            if (isset($row['soldprice']) && $row['soldprice'] !== null && $row['soldprice'] !== '') {
                $amount = (float)$row['soldprice'];
            } elseif (isset($row['price'])) {
                $amount = (float)$row['price'];
            }
            $salesSeries[$idx] += $amount;
        }
    }

    // Helper to parse location strings from *_location_pins tables
    $parse_loc_pair = function($locStr) {
        $lat = null; $lng = null;
        if (!$locStr) { return [null, null]; }
        $j = json_decode($locStr, true);
        if (is_array($j)) {
            if (isset($j['lat']) && isset($j['lng'])) {
                return [(float)$j['lat'], (float)$j['lng']];
            }
            if (isset($j[0]) && isset($j[1])) {
                return [(float)$j[0], (float)$j[1]];
            }
        }
        if (strpos($locStr, ',') !== false) {
            $parts = explode(',', $locStr, 2);
            $lat = (float)trim($parts[0]);
            $lng = (float)trim($parts[1]);
            return [$lat, $lng];
        }
        return [null, null];
    };

    // Build index of this seller's active listings
    [$activeList, $alSt, $alErr] = sb_rest('GET', 'activelivestocklisting', [
        'select'    => 'listing_id,livestock_type,breed,price,address,created,status,seller_id',
        'seller_id' => 'eq.' . $sellerId,
    ]);
    if (!($alSt >= 200 && $alSt < 300) || !is_array($activeList)) { $activeList = []; }
    $activeIndex = [];
    foreach ($activeList as $row) {
        $lid = (int)($row['listing_id'] ?? 0);
        if ($lid <= 0) { continue; }
        $activeIndex[$lid] = [
            'type'    => $row['livestock_type'] ?? '',
            'breed'   => $row['breed'] ?? '',
            'price'   => $row['price'] ?? '',
            'address' => $row['address'] ?? '',
            'created' => $row['created'] ?? '',
            'status'  => $row['status'] ?? 'Active',
        ];
    }

    // Active pins for this seller's listings
    [$ap, $as, $ae] = sb_rest('GET', 'activelocation_pins', [
        'select' => 'pin_id,location,listing_id,status,created_at',
    ]);
    if (!($as >= 200 && $as < 300) || !is_array($ap)) { $ap = []; }
    foreach ($ap as $p) {
        $lid = (int)($p['listing_id'] ?? 0);
        if (!isset($activeIndex[$lid])) { continue; }
        [$la, $ln] = $parse_loc_pair($p['location'] ?? '');
        if ($la === null || $ln === null) { continue; }
        $meta = $activeIndex[$lid];
        $coveragePins['activePins'][] = [
            'pin_id'     => (int)($p['pin_id'] ?? 0),
            'listing_id' => $lid,
            'lat'        => (float)$la,
            'lng'        => (float)$ln,
            'type'       => $meta['type'],
            'breed'      => $meta['breed'],
            'price'      => $meta['price'],
            'address'    => $meta['address'],
            'created'    => $meta['created'],
            'status'     => $meta['status'] ?: 'Active',
            'pin_status' => $p['status'] ?? 'Active',
        ];
    }

    // Build index of this seller's sold listings
    [$soldList, $slSt, $slErr] = sb_rest('GET', 'soldlivestocklisting', [
        'select'    => 'listing_id,livestock_type,breed,price,soldprice,address,created,status,seller_id',
        'seller_id' => 'eq.' . $sellerId,
    ]);
    if (!($slSt >= 200 && $slSt < 300) || !is_array($soldList)) { $soldList = []; }
    $soldIndex = [];
    foreach ($soldList as $row) {
        $lid = (int)($row['listing_id'] ?? 0);
        if ($lid <= 0) { continue; }
        $soldIndex[$lid] = [
            'type'     => $row['livestock_type'] ?? '',
            'breed'    => $row['breed'] ?? '',
            'price'    => $row['price'] ?? '',
            'soldprice'=> $row['soldprice'] ?? null,
            'address'  => $row['address'] ?? '',
            'created'  => $row['created'] ?? '',
            'status'   => $row['status'] ?? 'Sold',
        ];
    }

    // Sold pins for this seller's listings
    [$sp, $ss, $se] = sb_rest('GET', 'soldlocation_pins', [
        'select' => 'pin_id,location,listing_id,status,created_at',
    ]);
    if (!($ss >= 200 && $ss < 300) || !is_array($sp)) { $sp = []; }
    foreach ($sp as $p) {
        $lid = (int)($p['listing_id'] ?? 0);
        if (!isset($soldIndex[$lid])) { continue; }
        [$la, $ln] = $parse_loc_pair($p['location'] ?? '');
        if ($la === null || $ln === null) { continue; }
        $meta = $soldIndex[$lid];
        $amount = $meta['soldprice'] !== null && $meta['soldprice'] !== '' ? $meta['soldprice'] : $meta['price'];
        $coveragePins['soldPins'][] = [
            'pin_id'     => (int)($p['pin_id'] ?? 0),
            'listing_id' => $lid,
            'lat'        => (float)$la,
            'lng'        => (float)$ln,
            'type'       => $meta['type'],
            'breed'      => $meta['breed'],
            'price'      => $amount,
            'address'    => $meta['address'],
            'created'    => $meta['created'],
            'status'     => $meta['status'] ?: 'Sold',
            'pin_status' => $p['status'] ?? 'Sold',
        ];
    }
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
        </div>
        <div class="hamburger" aria-label="Toggle menu">
            <span></span>
            <span></span>
            <span></span>
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
    <!-- Mobile Menu -->
    <div class="mobile-menu">
        <a href="pages/pricewatch.php">Price Watch</a>
        <a href="pages/userreport.php">User Report</a>
        <a href="pages/transactions.php">Transactions</a>
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
            <div style="margin-top:12px;overflow-x:auto;">
                <table id="coverageTable" style="width:100%;border-collapse:collapse;font-size:13px;">
                    <thead>
                        <tr style="background:#f3f4f6;color:#4b5563;">
                            <th style="padding:6px 8px;border-bottom:1px solid #e5e7eb;text-align:left;">Listing ID</th>
                            <th style="padding:6px 8px;border-bottom:1px solid #e5e7eb;text-align:left;">Type</th>
                            <th style="padding:6px 8px;border-bottom:1px solid #e5e7eb;text-align:left;">Breed</th>
                            <th style="padding:6px 8px;border-bottom:1px solid #e5e7eb;text-align:left;">Status</th>
                            <th style="padding:6px 8px;border-bottom:1px solid #e5e7eb;text-align:left;">Pin</th>
                            <th style="padding:6px 8px;border-bottom:1px solid #e5e7eb;text-align:left;">Address</th>
                            <th style="padding:6px 8px;border-bottom:1px solid #e5e7eb;text-align:left;">Created</th>
                            <th style="padding:6px 8px;border-bottom:1px solid #e5e7eb;text-align:right;">Price (₱)</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <h3>Livestock Aid (Sales Projection)</h3>
            <div class="chartbox"><canvas id="salesLineChart"></canvas></div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script src="/pages/script/notifications.js"></script>
    <script src="script/dashboard.js"></script>
    <script src="/pages/script/mobile-menu.js"></script>
    <script>
      (function(){
        // Data from PHP for coverage pins and sales
        var coverageData = <?php echo json_encode($coveragePins, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;
        var salesLabels = <?php echo json_encode($salesLabels, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;
        var salesSeries = <?php echo json_encode($salesSeries, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;

        // Initialize service coverage map with Leaflet
        function initCoverageMap(){
          var mapEl = document.getElementById('map');
          if (!mapEl) return;
          if (!window.L){ setTimeout(initCoverageMap, 100); return; }

          var map = L.map(mapEl).setView([8.314209, 124.859425], 12);
          L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(map);

          var active = (coverageData && Array.isArray(coverageData.activePins)) ? coverageData.activePins : [];
          var sold   = (coverageData && Array.isArray(coverageData.soldPins))   ? coverageData.soldPins   : [];

          var bounds = [];
          function addMarker(pin, color, fillColor){
            if (pin.lat == null || pin.lng == null) return;
            var m = L.circleMarker([pin.lat, pin.lng], {
              radius: 7,
              color: color,
              fillColor: fillColor,
              fillOpacity: 0.9
            }).addTo(map);
            var html = '<div style="font-size:12px;">'
              + '<div><strong>' + (pin.type || '') + ' • ' + (pin.breed || '') + '</strong></div>'
              + '<div>' + (pin.address || '') + '</div>'
              + '<div class="subtle">#' + (pin.listing_id || '') + ' • ' + (pin.created || '') + '</div>'
              + '<div>Status: ' + (pin.status || '') + ' (' + (pin.pin_status || '') + ')</div>'
              + '</div>';
            m.bindTooltip(html, { direction:'top', sticky:true, opacity:0.95, className:'seller-pin-tt' });
            bounds.push([pin.lat, pin.lng]);
          }

          active.forEach(function(pin){ addMarker(pin, '#16a34a', '#22c55e'); });
          sold.forEach(function(pin){ addMarker(pin, '#f59e0b', '#fbbf24'); });

          if (bounds.length) {
            try { map.fitBounds(bounds, { padding:[20,20] }); } catch(e){}
          }

          var statusEl = document.getElementById('geoStatus');
          if (statusEl) {
            var a = active.length, s = sold.length;
            if (a || s) {
              statusEl.textContent = 'Pins loaded — Active: ' + a + ', Sold: ' + s + '.';
            } else {
              statusEl.textContent = 'No location pins available yet.';
            }
          }

          // Populate coverage table
          var table = document.getElementById('coverageTable');
          if (!table) return;
          var tbody = table.querySelector('tbody');
          if (!tbody) return;
          tbody.innerHTML = '';

          function addRow(pin, kind){
            var tr = document.createElement('tr');
            tr.innerHTML = ''
              + '<td style="padding:6px 8px;border-bottom:1px solid #e5e7eb;">' + (pin.listing_id || '') + '</td>'
              + '<td style="padding:6px 8px;border-bottom:1px solid #e5e7eb;">' + (pin.type || '') + '</td>'
              + '<td style="padding:6px 8px;border-bottom:1px solid #e5e7eb;">' + (pin.breed || '') + '</td>'
              + '<td style="padding:6px 8px;border-bottom:1px solid #e5e7eb;">' + (pin.status || '') + '</td>'
              + '<td style="padding:6px 8px;border-bottom:1px solid #e5e7eb;">' + kind + '</td>'
              + '<td style="padding:6px 8px;border-bottom:1px solid #e5e7eb;max-width:260px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="' + (pin.address || '') + '">' + (pin.address || '') + '</td>'
              + '<td style="padding:6px 8px;border-bottom:1px solid #e5e7eb;">' + (pin.created || '') + '</td>'
              + '<td style="padding:6px 8px;border-bottom:1px solid #e5e7eb;text-align:right;">' + (pin.price || '') + '</td>';
            tbody.appendChild(tr);
          }

          active.forEach(function(p){ addRow(p, 'Active'); });
          sold.forEach(function(p){ addRow(p, 'Sold'); });

          if (!active.length && !sold.length) {
            var tr = document.createElement('tr');
            var td = document.createElement('td');
            td.colSpan = 8;
            td.style.cssText = 'padding:8px 10px;text-align:center;color:#6b7280;border-bottom:1px solid #e5e7eb;';
            td.textContent = 'No location pins found.';
            tr.appendChild(td);
            tbody.appendChild(tr);
          }
        }

        if (document.readyState === 'loading') {
          document.addEventListener('DOMContentLoaded', initCoverageMap);
        } else {
          initCoverageMap();
        }

        // Initialize Livestock Aid sales chart
        (function(){
          var ctx = document.getElementById('salesLineChart');
          if (!ctx || !window.Chart) return;

          var data = Array.isArray(salesSeries) ? salesSeries : [];
          var labels = Array.isArray(salesLabels) ? salesLabels : [];

          new Chart(ctx, {
            type: 'line',
            data: {
              labels: labels,
              datasets: [{
                label: 'Total Completed Sales (₱)',
                data: data,
                borderColor: '#16a34a',
                backgroundColor: 'transparent',
                tension: 0.3,
                spanGaps: true,
                pointRadius: 3,
                pointBackgroundColor: '#16a34a'
              }]
            },
            options: {
              responsive: true,
              maintainAspectRatio: false,
              animation: false,
              plugins: {
                legend: { position: 'bottom' },
                title: { display: false }
              },
              scales: {
                y: {
                  beginAtZero: true,
                  suggestedMin: 0,
                  title: { display: true, text: 'Sales Amount (₱)' }
                }
              }
            }
          });
        })();
      })();
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

        // Initial state (empty); notifications.js will populate shortly
        render([]);
      })();
    </script>
</body>
</html>