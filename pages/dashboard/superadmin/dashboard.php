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
$preBatCount = 0; $reviewAdminCount = 0;
[$batr,$bath,$bate] = sb_rest('GET', 'preapprovalbat', ['select'=>'user_id']);
if ($bath>=200 && $bath<300 && is_array($batr)) { $preBatCount = count($batr); }
[$adr,$adh,$ade] = sb_rest('GET', 'reviewadmin', ['select'=>'user_id']);
if ($adh>=200 && $adh<300 && is_array($adr)) { $reviewAdminCount = count($adr); }

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
[$typesRes,$typesStatus,$typesErr] = sb_rest('GET','livestock_type',['select'=>'type_id,name']);
$types = (is_array($typesRes)? $typesRes : []);
// Get breeds (id, name, livestocktype_id)
[$breedsRes,$breedsStatus,$breedsErr] = sb_rest('GET','livestockbreed',['select'=>'breed_id,name,livestocktype_id']);
$breeds = (is_array($breedsRes)? $breedsRes : []);
// Fetch sold listings
[$soldRes,$soldStatus,$soldErr] = sb_rest('GET','activelivestocklisting',['select'=>'livestock_type,breed,price,created,status']);
$soldRows = ($soldStatus>=200 && $soldStatus<300 && is_array($soldRes)) ? $soldRes : [];

?>
<!DOCTYPE html><html lang="en">
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
            <a class="btn" href="pages/generatereports.php">Generate Reports</a>
            <a class="btn" href="pages/usermanagement.php">User Management</a>
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
    <!-- Mobile Menu -->
    <div class="mobile-menu">
        <a href="pages/generatereports.php">Generate Reports</a>
        <a href="pages/usermanagement.php">User Management</a>
        <a href="pages/security.php">Security</a>
        <a href="pages/price_management.php">Price Management</a>
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
        <div class="card">
            <h3 style="margin-top:0">Total Livestock Sold</h3>
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;margin-bottom:8px">
                <label style="display:flex;flex-direction:column;gap:4px;font-size:12px;color:#4a5568">Livestock Type
                    <select id="sa-type-filter">
                        <option value="">All</option>
                        <?php foreach ($types as $t): ?>
                          <option value="<?php echo htmlspecialchars($t['name']??'', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($t['name']??('Type #'.(int)($t['type_id']??0)), ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label style="display:flex;flex-direction:column;gap:4px;font-size:12px;color:#4a5568">Breed
                    <select id="sa-breed-filter" disabled>
                        <option value="">All</option>
                    </select>
                </label>
            </div>
            <div class="chartbox" style="width:100%;min-height:220px"><canvas id="saSalesChart"></canvas></div>
        </div>
        <div class="card">
            <p>Manage platform-wide settings and users here.</p>
        </div>
        <div class="card">
            <h3>Management Account Approvals</h3>
            <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:12px;margin:12px 0;">
                <div class="card" style="padding:12px;">
                    <div style="color:#4a5568;font-size:12px;">BATs Waiting</div>
                    <div style="font-size:20px;font-weight:600;"><?php echo (int)$preBatCount; ?></div>
                </div>
                <div class="card" style="padding:12px;">
                    <div style="color:#4a5568;font-size:12px;">Admins Waiting</div>
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
            <iframe class="embed-frame" src="pages/livestock_listing_logs.php" title="Livestock Listing Logs"></iframe>
        </div>
    </div>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script src="/pages/script/notifications.js"></script>
<script src="script/dashboard.js"></script>
<script src="/pages/script/mobile-menu.js"></script>
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
    window.updateNotifications = function(list){ render(list||[]); };
    if (btn){ btn.addEventListener('click', function(e){ e.preventDefault(); if (!pane) return; pane.style.display = (pane.style.display==='none'||pane.style.display==='') ? 'block' : 'none'; }); }
    document.addEventListener('click', function(e){ if (!pane || !btn) return; if (!pane.contains(e.target) && !btn.contains(e.target)) { pane.style.display = 'none'; } });
    // Initial state; notifications.js will populate shortly
    render([]);
  })();
  // Sales chart data + filters
  (function(){
    const labels = <?php echo json_encode($labels, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;
    const sold = <?php echo json_encode($soldRows, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;
    const monthKeys = <?php echo json_encode($monthKeys, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;
    const breeds = <?php echo json_encode($breeds, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;
    const types = <?php echo json_encode($types, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;
    const typeSel = document.getElementById('sa-type-filter');
    const breedSel = document.getElementById('sa-breed-filter');
    const palette = ['#8B4513','#16a34a','#ec4899','#2563eb','#f59e0b','#10b981','#ef4444','#6b7280'];
    function populateBreeds(typeName){
      if (!breedSel) return;
      breedSel.innerHTML = '<option value="">All</option>';
      breedSel.disabled = !typeName;
      if (!typeName) return;
      // Find type_id for the selected type name
      let typeId = null;
      if (Array.isArray(types)){
        const t = types.find(x => (x && (x.name||'') === typeName));
        if (t) typeId = t.type_id;
      }
      const names = [];
      if (typeId!=null){
        breeds.filter(b=>b && b.livestocktype_id==typeId).forEach(b=>{ if (b.name) names.push(b.name); });
      }
      names.sort().forEach(n=>{
        const opt=document.createElement('option'); opt.value=n; opt.textContent=n; breedSel.appendChild(opt);
      });
    }
    function aggregate(typeName, breedName){
      const totals = new Array(monthKeys.length).fill(0);
      sold.forEach(r=>{
        if (r.status && r.status!=='Sold') return;
        const lt = r.livestock_type || '';
        const br = r.breed || '';
        if (typeName && lt!==typeName) return;
        if (breedName && br!==breedName) return;
        const ym = (r.created||'').slice(0,7);
        const idx = monthKeys.indexOf(ym);
        if (idx>-1){ totals[idx] += Number(r.price||0); }
      });
      return totals;
    }
    const ctx = document.getElementById('saSalesChart');
    let chart;
    function render(){
      const t = typeSel ? typeSel.value : '';
      const b = breedSel ? breedSel.value : '';
      const data = aggregate(t,b);
      const ds = [{ label: (b||t||'All Types'), data, borderColor: palette[3], backgroundColor:'transparent', tension:.3, spanGaps:true, pointRadius:0 }];
      if (chart){ chart.destroy(); }
      chart = new Chart(ctx, { type:'line', data:{ labels, datasets: ds }, options:{ responsive:true, maintainAspectRatio:false, scales:{ y:{ beginAtZero:true }}}});
    }
    if (typeSel){ typeSel.addEventListener('change', function(){ populateBreeds(this.value); breedSel && (breedSel.value=''); render(); }); }
    if (breedSel){ breedSel.addEventListener('change', render); }
    populateBreeds(typeSel ? typeSel.value : '');
    render();
  })();
</script>
</body>
</html>
