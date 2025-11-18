<?php
session_start();
$firstname = isset($_SESSION['firstname']) && $_SESSION['firstname'] !== '' ? $_SESSION['firstname'] : 'User';
// Compute verification status from role and source table
$role = $_SESSION['role'] ?? '';
$src  = $_SESSION['source_table'] ?? '';
$isVerified = false;
if ($role === 'bat') {
    $isVerified = ($src === 'bat');
}
$statusLabel = $isVerified ? 'Verified' : 'Under review';
require_once __DIR__ . '/../../authentication/lib/supabase_client.php';
$batId = $_SESSION['user_id'] ?? null;
$toReview = 0; $approvedCount = 0; $deniedCount = 0;
if ($batId){
    [$r1,$s1,$e1] = sb_rest('GET','reviewlivestocklisting',['select'=>'listing_id']);
    if ($s1>=200 && $s1<300 && is_array($r1)) $toReview = count($r1);
    [$r2,$s2,$e2] = sb_rest('GET','livestocklisting_logs',['select'=>'listing_id','bat_id'=>'eq.'.$batId]);
    if ($s2>=200 && $s2<300 && is_array($r2)) $approvedCount = count($r2);
    [$r3,$s3,$e3] = sb_rest('GET','deniedlivestocklisting',['select'=>'listing_id','bat_id'=>'eq.'.$batId]);
    if ($s3>=200 && $s3<300 && is_array($r3)) $deniedCount = count($r3);
}
// Inline API: pins for map
if (isset($_GET['action']) && $_GET['action']==='pins'){
    header('Content-Type: application/json');
    // Helper identical to buyer market.php
    $parse_loc_pair = function($locStr){
        $lat=null; $lng=null;
        if (!$locStr) return [null,null];
        $j = json_decode($locStr, true);
        if (is_array($j)){
            if (isset($j['lat']) && isset($j['lng'])){ return [(float)$j['lat'], (float)$j['lng']]; }
            if (isset($j[0]) && isset($j[1])){ return [(float)$j[0], (float)$j[1]]; }
        }
        if (strpos($locStr, ',') !== false){
            $parts = explode(',', $locStr, 2);
            $lat = (float)trim($parts[0]);
            $lng = (float)trim($parts[1]);
            return [$lat,$lng];
        }
        return [null,null];
    };
    // Build active index for type/breed + price/created/address and seller info for thumbnails
    [$alist,$alst,$aerr] = sb_rest('GET','activelivestocklisting',[ 'select'=>'listing_id,livestock_type,breed,price,created,address,seller_id', 'order'=>'created.desc', 'limit'=>1000 ]);
    if (!($alst>=200 && $alst<300) || !is_array($alist)) $alist = [];
    $activeIndex = [];
    foreach ($alist as $arow){
        $activeIndex[(int)($arow['listing_id']??0)] = [
            'type'=>$arow['livestock_type']??'',
            'breed'=>$arow['breed']??'',
            'price'=>$arow['price']??'',
            'created'=>$arow['created']??'',
            'address'=>$arow['address']??'',
            'seller_id'=>(int)($arow['seller_id']??0)
        ];
    }
    // Active pins
    [$ap,$as,$ae] = sb_rest('GET','activelocation_pins',[ 'select'=>'pin_id,location,listing_id,status' ]);
    if (!($as>=200 && $as<300) || !is_array($ap)) $ap = [];
    $activePins = [];
    foreach ($ap as $p){
        $lid = (int)($p['listing_id'] ?? 0);
        if (!isset($activeIndex[$lid])) continue;
        list($la,$ln) = $parse_loc_pair($p['location'] ?? '');
        if ($la!==null && $ln!==null){
            $meta = $activeIndex[$lid];
            $sellerId = (int)$meta['seller_id'];
            $legacyFolder = $sellerId.'_'.$lid;
            $root = 'listings/verified';
            $thumb = '../../bat/pages/storage_image.php?path='.$root.'/'.$legacyFolder.'/image1';
            $fallback = $thumb;
            $activePins[] = [
              'listing_id'=>$lid,
              'lat'=>(float)$la,
              'lng'=>(float)$ln,
              'type'=>$meta['type'],
              'breed'=>$meta['breed'],
              'price'=>$meta['price'],
              'created'=>$meta['created'],
              'address'=>$meta['address'],
              'thumb'=>$thumb,
              'thumb_fallback'=>$fallback
            ];
        }
    }
    // Sold index (build for thumbnails too with full details)
    [$slist,$slst,$serr] = sb_rest('GET','activelivestocklisting',[ 'select'=>'listing_id,livestock_type,breed,price,created,address,seller_id', 'status'=>'eq.Sold', 'order'=>'created.desc', 'limit'=>1000 ]);
    if (!($slst>=200 && $slst<300) || !is_array($slist)) $slist = [];
    $soldIndex = [];
    foreach ($slist as $srow){
        $soldIndex[(int)($srow['listing_id']??0)] = [
            'type'=>$srow['livestock_type']??'',
            'breed'=>$srow['breed']??'',
            'price'=>$srow['price']??'',
            'created'=>$srow['created']??'',
            'address'=>$srow['address']??'',
            'seller_id'=>(int)($srow['seller_id']??0)
        ];
    }
    [$sp,$ss,$se] = sb_rest('GET','soldlocation_pins',[ 'select'=>'pin_id,location,listing_id,status' ]);
    if (!($ss>=200 && $ss<300) || !is_array($sp)) $sp = [];
    $soldPins = [];
    foreach ($sp as $p){
        $lid = (int)($p['listing_id'] ?? 0);
        list($la,$ln) = $parse_loc_pair($p['location'] ?? '');
        if ($la!==null && $ln!==null){
            $meta = $soldIndex[$lid] ?? ['type'=>'','breed'=>'','seller_id'=>0,'created'=>''];
            $sellerId = (int)($meta['seller_id']??0);
            $legacyFolder = $sellerId.'_'.$lid;
            $root = 'listings/verified';
            $thumb = '../../bat/pages/storage_image.php?path='.$root.'/'.$legacyFolder.'/image1';
            $fallback = $thumb;
            $soldPins[] = [
              'listing_id'=>$lid,
              'lat'=>(float)$la,
              'lng'=>(float)$ln,
              'type'=>$meta['type'],
              'breed'=>$meta['breed'],
              'price'=>$meta['price']??'',
              'created'=>$meta['created']??'',
              'address'=>$meta['address']??'',
              'thumb'=>$thumb,
              'thumb_fallback'=>$fallback
            ];
        }
    }
    echo json_encode(['ok'=>true,'activePins'=>$activePins,'soldPins'=>$soldPins]);
    exit;
}

// Prepare data for sales chart (last 3 months and next month)
$labels = [];
$monthKeys = [];
$now = new DateTime('first day of this month 00:00:00');
for ($i=-3; $i<=1; $i++){
  $d = (clone $now)->modify(($i>=0?'+':'').$i.' month');
  $labels[] = $d->format('M');
  $monthKeys[] = $d->format('Y-m');
}

// Get livestock types
[$typesRes,$typesStatus,$typesErr] = sb_rest('GET','livestock_type',['select'=>'name']);
$typeNames = [];
if ($typesStatus>=200 && $typesStatus<300 && is_array($typesRes)){
  foreach ($typesRes as $row){ if (!empty($row['name'])) $typeNames[] = $row['name']; }
}
// Fallback default labels if table empty
if (count($typeNames)===0){ $typeNames = ['Cattle','Goat','Pigs']; }

// Fetch sold listings from activelivestocklisting with status='Sold'
[$soldRes,$soldStatus,$soldErr] = sb_rest('GET','activelivestocklisting',['select'=>'livestock_type,breed,price,created','status'=>'eq.Sold']);
$series = [];
foreach ($typeNames as $tn){ $series[$tn] = array_fill(0, count($monthKeys), 0); }
if ($soldStatus>=200 && $soldStatus<300 && is_array($soldRes)){
  foreach ($soldRes as $r){
    $lt = $r['livestock_type'] ?? null;
    $price = (float)($r['price'] ?? 0);
    $created = isset($r['created']) ? substr($r['created'],0,7) : null; // YYYY-MM
    if (!$lt || !$created) continue;
    $idx = array_search($created, $monthKeys, true);
    if ($idx===false) continue;
    if (isset($series[$lt])) $series[$lt][$idx] += $price;
  }
}

// Build datasets with colors for livestock types
$palette = ['#8B4513','#16a34a','#ec4899','#2563eb','#f59e0b','#10b981','#ef4444','#6b7280'];
$datasets = [];
foreach ($typeNames as $i=>$tn){
  $datasets[] = [
    'label' => $tn,
    'data' => $series[$tn],
    'borderColor' => $palette[$i % count($palette)],
    'backgroundColor' => 'transparent',
    'tension' => 0.3,
    'spanGaps' => true,
    'pointRadius' => 0
  ];
}

// Prepare breed data for bar chart
[$breedSoldRes,$breedSoldStatus,$breedSoldErr] = sb_rest('GET','activelivestocklisting',['select'=>'breed','status'=>'eq.Sold']);
$breedCounts = [];
if ($breedSoldStatus>=200 && $breedSoldStatus<300 && is_array($breedSoldRes)){
  foreach ($breedSoldRes as $r){
    $breed = $r['breed'] ?? 'Unknown';
    if (!isset($breedCounts[$breed])) $breedCounts[$breed] = 0;
    $breedCounts[$breed]++;
  }
}
arsort($breedCounts); // Sort by count descending
$breedLabels = array_keys($breedCounts);
$breedData = array_values($breedCounts);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style/dashboard.css">
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
            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin:10px 0;">
                <div class="card" style="padding:12px;">
                    <div style="color:#4a5568;font-size:12px;">To Review</div>
                    <div style="font-size:20px;font-weight:600;"><?php echo (int)$toReview; ?></div>
                </div>
                <div class="card" style="padding:12px;">
                    <div style="color:#4a5568;font-size:12px;">Approved</div>
                    <div style="font-size:20px;font-weight:600;"><?php echo (int)$approvedCount; ?></div>
                </div>
                <div class="card" style="padding:12px;">
                    <div style="color:#4a5568;font-size:12px;">Denied</div>
                    <div style="font-size:20px;font-weight:600;"><?php echo (int)$deniedCount; ?></div>
                </div>
            </div>
            <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;">
                <a class="btn" href="pages/review_listings.php">Review Listings</a>
                <a class="btn" href="pages/transaction_monitoring.php" style="background:#4a5568;">Transaction Monitoring</a>
            </div>
        </div>
        <div class="card">
            <h2 style="margin-top:0">Market Map</h2>
            <div id="batMap" style="height:400px;border:1px solid #e5e7eb;border-radius:10px;"></div>
        </div>
        <div class="card">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
                <h2 style="margin-top:0">Sales Analytics</h2>
                <div style="display:flex;gap:8px;">
                    <button class="btn" id="btnLivestockType" style="background:#2563eb;">Livestock Type</button>
                    <button class="btn" id="btnBreed" style="background:#4a5568;">Breed</button>
                </div>
            </div>
            <div class="chartbox" style="height:300px;position:relative;">
                <canvas id="batSalesChart"></canvas>
            </div>
        </div>
        <div class="card">
            <h2 style="margin-top:0">Scheduling Calendar</h2>
            <div id="calendar"></div>
            <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;">
              <button class="btn" id="btn-add">Add</button>
              <button class="btn" id="btn-edit" style="background:#4a5568;">Edit</button>
              <button class="btn" id="btn-delete" style="background:#e53e3e;">Delete</button>
              <button class="btn" id="btn-done" style="background:#2f855a;">Mark as Done</button>
              <button class="btn" id="btn-view" style="background:#805ad5;">View</button>
            </div>
        </div>
        <div id="geoStatus" style="margin-top:8px;color:#4a5568;font-size:14px"></div>
    </div>
    <link href='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.css' rel='stylesheet' />
    <style>
      .done-event .fc-event-title { text-decoration: line-through; opacity: 0.7; }
    </style>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js'></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <div id="bat-sales-data"
         data-labels='<?php echo json_encode($labels, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>'
         data-datasets='<?php echo json_encode($datasets, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>'
         data-breed-labels='<?php echo json_encode($breedLabels, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>'
         data-breed-data='<?php echo json_encode($breedData, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>'></div>
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
      
      // Sales Chart Implementation
      (function(){
        var chartEl = document.getElementById('batSalesChart');
        var dataEl = document.getElementById('bat-sales-data');
        var livestockBtn = document.getElementById('btnLivestockType');
        var breedBtn = document.getElementById('btnBreed');
        
        if (!chartEl || !dataEl || !window.Chart) return;
        
        try {
          var labels = JSON.parse(dataEl.getAttribute('data-labels') || '[]');
          var datasets = JSON.parse(dataEl.getAttribute('data-datasets') || '[]');
          var breedLabels = JSON.parse(dataEl.getAttribute('data-breed-labels') || '[]');
          var breedData = JSON.parse(dataEl.getAttribute('data-breed-data') || '[]');
          
          var currentChart = null;
          var currentView = 'livestock'; // 'livestock' or 'breed'
          
          function createLivestockChart() {
            if (currentChart) currentChart.destroy();
            currentChart = new Chart(chartEl, {
              type: 'line',
              data: { labels: labels, datasets: datasets },
              options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: false,
                plugins: { 
                  legend: { position: 'bottom' },
                  title: { display: true, text: 'Sales by Livestock Type (Last 4 Months)' }
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
            currentView = 'livestock';
            livestockBtn.style.background = '#2563eb';
            breedBtn.style.background = '#4a5568';
          }
          
          function createBreedChart() {
            if (currentChart) currentChart.destroy();
            
            // Generate colors for breeds
            var colors = breedLabels.map(function(_, i) {
              var palette = ['#8B4513','#16a34a','#ec4899','#2563eb','#f59e0b','#10b981','#ef4444','#6b7280'];
              return palette[i % palette.length];
            });
            
            currentChart = new Chart(chartEl, {
              type: 'bar',
              data: { 
                labels: breedLabels, 
                datasets: [{
                  label: 'Number Sold',
                  data: breedData,
                  backgroundColor: colors,
                  borderColor: colors.map(function(c) { return c; }),
                  borderWidth: 1
                }]
              },
              options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: false,
                plugins: { 
                  legend: { display: false },
                  title: { display: true, text: 'Total Sold by Breed' }
                },
                scales: { 
                  y: { 
                    beginAtZero: true,
                    title: { display: true, text: 'Number of Livestock Sold' }
                  },
                  x: {
                    title: { display: true, text: 'Breed' }
                  }
                }
              }
            });
            currentView = 'breed';
            breedBtn.style.background = '#2563eb';
            livestockBtn.style.background = '#4a5568';
          }
          
          // Initialize with livestock type view
          createLivestockChart();
          
          // Add toggle event listeners
          if (livestockBtn) {
            livestockBtn.addEventListener('click', function() {
              if (currentView !== 'livestock') createLivestockChart();
            });
          }
          
          if (breedBtn) {
            breedBtn.addEventListener('click', function() {
              if (currentView !== 'breed') createBreedChart();
            });
          }
          
        } catch(e) {
          console.error('Chart initialization error:', e);
        }
      })();
    </script>
    <script>
      (function(){
        function initMap(){
          var mapEl = document.getElementById('batMap');
          if (!mapEl) return;
          if (!window.L){ setTimeout(initMap, 100); return; }
          var map = L.map(mapEl).setView([8.314209 , 124.859425], 12);
          L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(map);
          setTimeout(function(){ try{ map.invalidateSize(); }catch(e){} }, 0);
          fetch('dashboard.php?action=pins', { credentials:'same-origin' })
            .then(function(r){ return r.json(); })
            .then(function(res){
              var added = 0; var b = [];
              var active = (res && Array.isArray(res.activePins)) ? res.activePins : [];
              var sold   = (res && Array.isArray(res.soldPins)) ? res.soldPins   : [];
              active.forEach(function(p){
                if (p.lat==null || p.lng==null) return;
                var m = L.circleMarker([p.lat,p.lng], { radius:7, color:'#16a34a', fillColor:'#22c55e', fillOpacity:0.9 }).addTo(map);
                var html = '<div style="display:flex;gap:8px;align-items:flex-start;pointer-events:none;">'+
                  '<img src="'+(p.thumb||'')+'" onerror="this.onerror=null;this.src=\''+(p.thumb_fallback||p.thumb||'')+'\'" style="width:64px;height:64px;object-fit:cover;border-radius:6px;border:1px solid #e5e7eb;" />'+
                  '<div>'+
                    '<div><strong>'+(p.type||'')+' • '+(p.breed||'')+'</strong></div>'+
                    '<div>₱'+(p.price||'')+'</div>'+
                    '<div class="subtle">#'+(p.listing_id||'')+' • '+(p.created||'')+'</div>'+
                    (p.address? '<div class="subtle">'+p.address+'</div>' : '')+
                  '</div>'+
                '</div>';
                m.bindTooltip(html, { direction:'top', sticky:true, opacity:0.95, className:'bat-pin-tt' });
                added++; b.push([p.lat,p.lng]);
              });
              sold.forEach(function(p){
                if (p.lat==null || p.lng==null) return;
                var m2 = L.circleMarker([p.lat,p.lng], { radius:7, color:'#f59e0b', fillColor:'#fbbf24', fillOpacity:0.9 }).addTo(map);
                var html2 = '<div style="display:flex;gap:8px;align-items:flex-start;pointer-events:none;">'+
                  '<img src="'+(p.thumb||'')+'" onerror="this.onerror=null;this.src=\''+(p.thumb_fallback||p.thumb||'')+'\'" style="width:64px;height:64px;object-fit:cover;border-radius:6px;border:1px solid #e5e7eb;" />'+
                  '<div>'+
                    '<div><strong>'+(p.type||'')+' • '+(p.breed||'')+'</strong></div>'+
                    '<div>₱'+(p.price||'')+'</div>'+
                    '<div class="subtle">#'+(p.listing_id||'')+' • '+(p.created||'')+'</div>'+
                    (p.address? '<div class="subtle">'+p.address+'</div>' : '')+
                  '</div>'+
                '</div>';
                m2.bindTooltip(html2, { direction:'top', sticky:true, opacity:0.95, className:'bat-pin-tt' });
                added++; b.push([p.lat,p.lng]);
              });
              var statusEl = document.getElementById('geoStatus');
              if (added>0){
                try { map.fitBounds(b, { padding:[20,20] }); } catch(e){}
                if (statusEl) statusEl.textContent = 'Pins loaded: '+added;
              } else {
                if (statusEl) statusEl.textContent = 'No pins available yet.';
              }
            })
            .catch(function(){ /* ignore, map still renders */ });
        }
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initMap); else initMap();
      })();
    </script>
    <div id="modal" style="position:fixed;inset:0;background:rgba(0,0,0,.4);display:none;align-items:center;justify-content:center;z-index:10000;">
      <div style="background:#fff;border-radius:10px;min-width:300px;max-width:90vw;padding:16px;">
        <h3 id="modal-title" style="margin-top:0">Add Schedule</h3>
        <form id="modal-form">
          <div style="display:grid;grid-template-columns:1fr;gap:8px;">
            <label>Title<input type="text" name="title" required /></label>
            <label>Description<input type="text" name="description" required /></label>
            <label>Date<input type="date" name="date" required /></label>
            <label>Time<input type="time" name="time" required /></label>
          </div>
          <div style="margin-top:12px;display:flex;gap:8px;justify-content:flex-end;">
            <button type="button" class="btn" id="modal-cancel" style="background:#718096;">Cancel</button>
            <button type="submit" class="btn" id="modal-save">Save</button>
          </div>
        </form>
      </div>
    </div>
    <script>
      (function(){
        var calEl = document.getElementById('calendar');
        if (!calEl) return;
        var calendar = new FullCalendar.Calendar(calEl, {
          initialView: 'dayGridMonth',
          selectable: true,
          editable: true,
          headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,timeGridWeek,timeGridDay' },
          events: async function(fetchInfo, success, failure){
            try {
              const res = await fetch('pages/schedule_api.php?action=list&start='+encodeURIComponent(fetchInfo.startStr)+'&end='+encodeURIComponent(fetchInfo.endStr));
              const data = await res.json();
              if (!data.ok) throw new Error(data.error||'Failed');
              success((data.events||[]).map(function(e){
                const cls = (e.done? ['done-event'] : []);
                return { id: e.id, title: e.title, start: e.start, end: e.end, allDay: false, classNames: cls };
              }));
            } catch(err){ failure(err); }
          },
          eventDrop: function(){ /* not supported for schedule table without date fields per move; keep disabled */ },
          eventResize: function(){ /* not supported */ },
          eventClick: function(clickInfo){
            window.selectedEvent = clickInfo.event;
          }
        });
        calendar.render();
        window.calendar = calendar;
        // Modal helpers
        function openModal(title, values){
          var modal = document.getElementById('modal');
          if (!modal) return;
          document.getElementById('modal-title').textContent = title || 'Schedule';
          var form = document.getElementById('modal-form');
          form.reset();
          document.getElementById('modal-save').style.display = '';
          document.getElementById('modal-cancel').textContent = 'Cancel';
          Array.from(form.elements).forEach(function(el){ if (el.tagName==='INPUT') el.readOnly = false; });
          if (values){
            if (form.title) form.title.value = values.title || '';
            if (form.description) form.description.value = values.description || '';
            if (form.date) form.date.value = values.date || '';
            if (form.time) form.time.value = values.time || '';
          }
          modal.style.display = 'flex';
        }
        function closeModal(){ var m=document.getElementById('modal'); if (m) m.style.display='none'; }
        var modalCancel = document.getElementById('modal-cancel');
        if (modalCancel){ modalCancel.addEventListener('click', closeModal); }
        var modalOverlay = document.getElementById('modal');
        if (modalOverlay){ modalOverlay.addEventListener('click', function(e){ if (e.target.id==='modal') closeModal(); }); }
        // Add
        var btnAdd = document.getElementById('btn-add');
        if (btnAdd){
          btnAdd.addEventListener('click', function(){
            const today = new Date();
            const yyyy = today.getFullYear(); const mm = String(today.getMonth()+1).padStart(2,'0'); const dd = String(today.getDate()).padStart(2,'0');
            openModal('Add Schedule', { date: `${yyyy}-${mm}-${dd}`, time: '09:00' });
            const form = document.getElementById('modal-form');
            form.onsubmit = async function(e){ e.preventDefault();
              const payload = { title: form.title.value, description: form.description.value, date: form.date.value, time: form.time.value };
              const res = await fetch('pages/schedule_api.php?action=create', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload)});
              const data = await res.json(); if (data.ok){ closeModal(); calendar.refetchEvents(); }
            };
          });
        }
        // Edit
        var btnEdit = document.getElementById('btn-edit');
        if (btnEdit){
          btnEdit.addEventListener('click', async function(){
            if (!window.selectedEvent){ alert('Select a schedule first'); return; }
            const id = window.selectedEvent.id;
            const res = await fetch('pages/schedule_api.php?action=get&id='+encodeURIComponent(id));
            const data = await res.json(); if (!data.ok){ alert('Failed to load schedule'); return; }
            openModal('Edit Schedule', { title: data.item.title, description: data.item.description, date: data.item.date, time: data.item.time });
            const form = document.getElementById('modal-form');
            form.onsubmit = async function(e){ e.preventDefault();
              const payload = { title: form.title.value, description: form.description.value, date: form.date.value, time: form.time.value };
              const res2 = await fetch('pages/schedule_api.php?action=update&id='+encodeURIComponent(id), { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload)});
              const data2 = await res2.json(); if (data2.ok){ closeModal(); calendar.refetchEvents(); }
            };
          });
        }
        // Delete
        var btnDelete = document.getElementById('btn-delete');
        if (btnDelete){
          btnDelete.addEventListener('click', async function(){
            if (!window.selectedEvent){ alert('Select a schedule first'); return; }
            if (!confirm('Delete this schedule?')) return;
            const id = window.selectedEvent.id;
            const res = await fetch('pages/schedule_api.php?action=delete&id='+encodeURIComponent(id), { method:'POST' });
            const data = await res.json(); if (data.ok){ calendar.refetchEvents(); window.selectedEvent = null; }
          });
        }
        // Done
        var btnDone = document.getElementById('btn-done');
        if (btnDone){
          btnDone.addEventListener('click', async function(){
            if (!window.selectedEvent){ alert('Select a schedule first'); return; }
            const id = window.selectedEvent.id;
            const res = await fetch('pages/schedule_api.php?action=done&id='+encodeURIComponent(id), { method:'POST' });
            const data = await res.json(); if (data.ok){ calendar.refetchEvents(); }
          });
        }
        // View
        var btnView = document.getElementById('btn-view');
        if (btnView){
          btnView.addEventListener('click', async function(){
            if (!window.selectedEvent){ alert('Select a schedule first'); return; }
            const id = window.selectedEvent.id;
            const res = await fetch('pages/schedule_api.php?action=get&id='+encodeURIComponent(id));
            const data = await res.json(); if (!data.ok){ alert('Failed to load schedule'); return; }
            openModal('View Schedule', { title: data.item.title, description: data.item.description, date: data.item.date, time: data.item.time });
            var form = document.getElementById('modal-form');
            Array.from(form.elements).forEach(function(el){ if (el.tagName==='INPUT') el.readOnly = true; });
            document.getElementById('modal-save').style.display = 'none';
            document.getElementById('modal-cancel').textContent = 'Close';
          });
        }
      })();
    </script>
</body>
</html>