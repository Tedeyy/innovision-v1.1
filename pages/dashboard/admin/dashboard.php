<?php
session_start();
$firstname = isset($_SESSION['firstname']) && $_SESSION['firstname'] !== '' ? $_SESSION['firstname'] : 'User';
// Compute verification status from role and source table
$role = $_SESSION['role'] ?? '';
$src  = $_SESSION['source_table'] ?? '';
$isVerified = false;
if ($role === 'admin') {
    $isVerified = ($src === 'admin');
}
$statusLabel = $isVerified ? 'Verified' : 'Under review';
require_once __DIR__ . '/../../authentication/lib/supabase_client.php';

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

// Fetch sold listings; filter in PHP to avoid complex PostgREST params here
[$soldRes,$soldStatus,$soldErr] = sb_rest('GET','activelivestocklisting',['select'=>'livestock_type,price,created','status'=>'eq.Sold']);
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
    if (!isset($series[$lt])) continue;
    $series[$lt][$idx] += $price;
  }
}

// Build datasets with colors
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard</title>
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
        <div class="hamburger" aria-label="Toggle menu">
            <span></span>
            <span></span>
            <span></span>
        </div>
        <div class="nav-center" style="display:flex;gap:16px;align-items:center;">
            <a class="btn" href="pages/usermanagement.php" style="background:#4a5568;">Users</a>
            <a class="btn" href="pages/listingmanagement.php" style="background:#4a5568;">Listings</a>
            <a class="btn" href="pages/report_management.php" style="background:#4a5568;">Report Management</a>
            <a class="btn" href="pages/analytics.php" style="background:#4a5568;">Analytics</a>
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
        <a href="pages/usermanagement.php">Users</a>
        <a href="pages/listingmanagement.php">Listings</a>
        <a href="pages/report_management.php">Report Management</a>
        <a href="pages/analytics.php">Analytics</a>
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
                <h1>Admin Dashboard</h1>
            </div>
        </div>
        <div class="card">
            <h3 style="margin-top:0">Price Management</h3>
            <?php
            // Load livestock types and breeds for selects
            [$ltRows,$ltSt,$ltErr] = sb_rest('GET','livestock_type',['select'=>'type_id,name']);
            $types = (is_array($ltRows)? $ltRows : []);
            ?>
            <form id="price-form" onsubmit="return false;" style="display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:8px;align-items:end;">
                <label>Livestock Type
                    <select id="pm-type" required>
                        <option value="">Select type</option>
                        <?php foreach ($types as $t): ?>
                            <option value="<?php echo (int)$t['type_id']; ?>"><?php echo htmlspecialchars($t['name']??('Type #'.(int)$t['type_id'])); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Breed
                    <select id="pm-breed" required disabled>
                        <option value="">Select breed</option>
                    </select>
                </label>
                <label>Market Price Per Kilo
                    <input id="pm-price" type="number" step="0.01" min="0" placeholder="0.00" required />
                </label>
                <button id="pm-submit" class="btn" type="button">Submit</button>
            </form>
            <div id="pm-msg" style="margin-top:8px;font-size:14px;color:#4a5568"></div>
            <script>
                (function(){
                    var btn = document.getElementById('pm-submit');
                    var msg = document.getElementById('pm-msg');
                    var typeSel = document.getElementById('pm-type');
                    var breedSel = document.getElementById('pm-breed');
                    function setMsg(t, ok){ if(!msg) return; msg.style.color = ok? '#166534':'#b91c1c'; msg.textContent = t; }
                    async function loadBreeds(typeId){
                        if (!breedSel) return;
                        breedSel.innerHTML = '<option value="">Loading...</option>';
                        breedSel.disabled = true;
                        try{
                            const res = await fetch('pages/price_api.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ action:'breeds_by_type', livestocktype_id: Number(typeId) }) });
                            const data = await res.json();
                            breedSel.innerHTML = '<option value="">Select breed</option>';
                            if (data.ok && Array.isArray(data.items)){
                                data.items.forEach(function(b){
                                    var opt = document.createElement('option');
                                    opt.value = b.breed_id; opt.textContent = b.name || ('Breed #'+b.breed_id);
                                    breedSel.appendChild(opt);
                                });
                                breedSel.disabled = false;
                            } else {
                                setMsg(data.error || 'Failed to load breeds', false);
                            }
                        }catch(e){ setMsg('Network error loading breeds', false); breedSel.innerHTML='<option value="">Select breed</option>'; }
                    }
                    if (typeSel){ typeSel.addEventListener('change', function(){ if (this.value) loadBreeds(this.value); else { breedSel.innerHTML='<option value="">Select breed</option>'; breedSel.disabled=true; } }); }
                    if (btn){ btn.addEventListener('click', async function(){
                        var typeId = typeSel.value;
                        var breedId = breedSel.value;
                        var price = document.getElementById('pm-price').value;
                        if (!typeId || !breedId || !price){ setMsg('Please complete all fields', false); return; }
                        try{
                            const res = await fetch('pages/price_api.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ action:'create_pending', livestocktype_id: Number(typeId), breed_id: Number(breedId), marketprice: Number(price) }) });
                            const data = await res.json();
                            if (!data.ok){ setMsg(data.error||'Failed to submit', false); return; }
                            setMsg('Submitted for approval.', true);
                        }catch(e){ setMsg('Network error', false); }
                    }); }
                })();
            </script>
        </div>
        <div class="card">
            <p>Use this space to manage users, view system stats, and oversee platform content.</p>
        </div>

        <div class="card">
            <h3>Sales by Livestock Type</h3>
            <div class="chartbox"><canvas id="adminSalesChart"></canvas></div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script src="/pages/script/mobile-menu.js"></script>
    <div id="admin-sales-data"
         data-labels='<?php echo json_encode($labels, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>'
         data-datasets='<?php echo json_encode($datasets, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>'></div>
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
  </body>
</html>