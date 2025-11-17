<?php
session_start();
require_once __DIR__ . '/pages/authentication/lib/supabase_client.php';

function safe($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// AJAX data endpoint for infinite scroll
if (isset($_GET['ajax']) && $_GET['ajax']=='1'){
  $limit = max(1, min(30, (int)($_GET['limit'] ?? 10)));
  $offset = max(0, (int)($_GET['offset'] ?? 0));
  $filters = [];
  $andConds = [];
  if (!empty($_GET['livestock_type'])){ $filters['livestock_type'] = 'eq.'.$_GET['livestock_type']; }
  if (!empty($_GET['breed'])){ $filters['breed'] = 'eq.'.$_GET['breed']; }
  if (isset($_GET['min_age']) && $_GET['min_age']!==''){ $andConds[] = 'age.gte.'.(int)$_GET['min_age']; }
  if (isset($_GET['max_age']) && $_GET['max_age']!==''){ $andConds[] = 'age.lte.'.(int)$_GET['max_age']; }
  if (isset($_GET['min_weight']) && $_GET['min_weight']!==''){ $andConds[] = 'weight.gte.'.(float)$_GET['min_weight']; }
  if (isset($_GET['max_weight']) && $_GET['max_weight']!==''){ $andConds[] = 'weight.lte.'.(float)$_GET['max_weight']; }
  if (isset($_GET['min_price']) && $_GET['min_price']!==''){ $andConds[] = 'price.gte.'.(float)$_GET['min_price']; }
  if (isset($_GET['max_price']) && $_GET['max_price']!==''){ $andConds[] = 'price.lte.'.(float)$_GET['max_price']; }

  $params = array_merge([
    'select' => 'listing_id,seller_id,livestock_type,breed,address,age,weight,price,created',
    'order' => 'created.desc',
    'limit' => $limit,
    'offset' => $offset,
  ], $filters);
  if (count($andConds)) { $params['and'] = '('.implode(',', $andConds).')'; }

  [$rows,$st,$err] = sb_rest('GET','activelivestocklisting',$params);
  if (!($st>=200 && $st<300) || !is_array($rows)) $rows = [];

  // Add seller location and thumbnail
  $withSeller = [];
  foreach ($rows as $r){
    [$sres,$sstatus,$serr] = sb_rest('GET','seller',[ 'select'=>'user_id,user_fname,user_mname,user_lname,location', 'user_id'=>'eq.'.((int)$r['seller_id']), 'limit'=>1 ]);
    $seller = ($sstatus>=200 && $sstatus<300 && is_array($sres) && isset($sres[0])) ? $sres[0] : [];
    $loc = null; $lat = null; $lng = null;
    if (!empty($seller['location'])){ $loc = json_decode($seller['location'], true); if (is_array($loc)) { $lat = $loc['lat'] ?? null; $lng = $loc['lng'] ?? null; } }
    $sfname = $seller['user_fname'] ?? ''; $smname = $seller['user_mname'] ?? ''; $slname = $seller['user_lname'] ?? '';
    $fullname = trim($sfname.' '.($smname?:'').' '.$slname);
    $sanFull = strtolower(preg_replace('/[^a-z0-9]+/i','_', $fullname)); $sanFull = trim($sanFull, '_'); if ($sanFull===''){ $sanFull='user'; }
    $newFolder = ((int)$r['seller_id']).'_'.$sanFull;
    $legacyFolder = ((int)$r['seller_id']).'_'.((int)$r['listing_id']);
    $createdKey = isset($r['created']) ? date('YmdHis', strtotime($r['created'])) : '';
    $thumb = ($createdKey!==''
      ? 'pages/dashboard/bat/pages/storage_image.php?path=listings/verified/'.$newFolder.'/'.$createdKey.'_1img.jpg'
      : 'pages/dashboard/bat/pages/storage_image.php?path=listings/active/'.$legacyFolder.'/image1');
    $thumb_fallback = 'pages/dashboard/bat/pages/storage_image.php?path=listings/active/'.$legacyFolder.'/image1';
    $withSeller[] = [
      'listing_id' => (int)$r['listing_id'],
      'seller_id' => (int)$r['seller_id'],
      'livestock_type' => $r['livestock_type'],
      'breed' => $r['breed'],
      'address' => $r['address'],
      'age' => (int)$r['age'],
      'weight' => (float)$r['weight'],
      'price' => (float)$r['price'],
      'created' => $r['created'],
      'seller_name' => $fullname,
      'lat' => $lat,
      'lng' => $lng,
      'thumb' => $thumb,
      'thumb_fallback' => $thumb_fallback
    ];
  }
  header('Content-Type: application/json');
  echo json_encode(['items'=>$withSeller], JSON_UNESCAPED_SLASHES);
  exit;
}

// Pins endpoint (no buyer pin here since public)
if (isset($_GET['pins']) && $_GET['pins']=='1'){
  $filters = [];
  $andConds = [];
  if (!empty($_GET['livestock_type'])){ $filters['livestock_type'] = 'eq.'.$_GET['livestock_type']; }
  if (!empty($_GET['breed'])){ $filters['breed'] = 'eq.'.$_GET['breed']; }
  if (isset($_GET['min_age']) && $_GET['min_age']!==''){ $andConds[] = 'age.gte.'.(int)$_GET['min_age']; }
  if (isset($_GET['max_age']) && $_GET['max_age']!==''){ $andConds[] = 'age.lte.'.(int)$_GET['max_age']; }
  if (count($andConds)) { $filters['and'] = '('.implode(',', $andConds).')'; }

  [$alist,$alst,$aerr] = sb_rest('GET','activelivestocklisting', array_merge(['select'=>'listing_id,livestock_type,breed'], $filters));
  if (!($alst>=200 && $alst<300) || !is_array($alist)) $alist = [];
  $activeIndex = [];
  foreach ($alist as $arow){ $activeIndex[(int)$arow['listing_id']] = ['type'=>$arow['livestock_type']??'', 'breed'=>$arow['breed']??'']; }

  [$apins,$apst,$aperr] = sb_rest('GET','activelocation_pins',[ 'select'=>'pin_id,location,listing_id,status' ]);
  if (!($apst>=200 && $apst<300) || !is_array($apins)) $apins = [];
  $activePins = [];
  foreach ($apins as $p){
    $lid = (int)($p['listing_id'] ?? 0);
    if (!isset($activeIndex[$lid])) continue;
    $locStr = (string)($p['location'] ?? '');
    $la = null; $ln = null;
    $j = json_decode($locStr, true);
    if (is_array($j)){
      if (isset($j['lat']) && isset($j['lng'])){ $la = (float)$j['lat']; $ln = (float)$j['lng']; }
      else if (isset($j[0]) && isset($j[1])){ $la = (float)$j[0]; $ln = (float)$j[1]; }
    } else if (strpos($locStr, ',') !== false){
      $parts = explode(',', $locStr, 2); $la = (float)trim($parts[0]); $ln = (float)trim($parts[1]);
    }
    if ($la!==null && $ln!==null){ $meta = $activeIndex[$lid]; $activePins[] = ['listing_id'=>$lid, 'lat'=>$la, 'lng'=>$ln, 'type'=>$meta['type'], 'breed'=>$meta['breed']]; }
  }
  header('Content-Type: application/json');
  echo json_encode(['activePins'=>$activePins], JSON_UNESCAPED_SLASHES);
  exit;
}

// Initial data for filters
[$types, $tstatus, $terr] = sb_rest('GET', 'livestock_type', ['select'=>'type_id,name','order'=>'name.asc']);
if ($tstatus < 200 || $tstatus >= 300) { $types = []; }
[$breeds, $bstatus, $berr] = sb_rest('GET', 'livestock_breed', ['select'=>'breed_id,type_id,name','order'=>'name.asc']);
if ($bstatus < 200 || $bstatus >= 300) { $breeds = []; }

$preType = isset($_GET['type']) ? (string)$_GET['type'] : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Marketplace</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
  <style>
    body{font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;margin:0;color:#111827}
    .wrap{max-width:1100px;margin:0 auto;padding:16px}
    .top{display:flex;align-items:center;justify-content:space-between}
    .btn{background:#2563eb;color:#fff;border:none;border-radius:8px;padding:8px 12px;cursor:pointer}
    .card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:12px}
    .filters{display:grid;grid-template-columns:repeat(8,minmax(120px,1fr));gap:8px}
    .feed{display:flex;flex-direction:column;gap:12px;margin-top:12px}
    .item{display:grid;grid-template-columns:220px 1fr auto;gap:12px;border:1px solid #e2e8f0;border-radius:8px;padding:8px}
    .map-top{height:280px;border:1px solid #e2e8f0;border-radius:8px;margin-bottom:12px}
    .muted{color:#4a5568;font-size:12px}
    .modal{position:fixed;inset:0;background:rgba(0,0,0,0.5);display:none;align-items:center;justify-content:center;z-index:9999}
    .panel{background:#fff;border-radius:10px;max-width:900px;width:95vw;max-height:90vh;overflow:auto;padding:16px}
  </style>
  <script>
    // expose initial type for preselect
    window.PRE_TYPE = <?php echo json_encode($preType); ?>;
  </script>
  </head>
<body>
  <div class="wrap">
    <div class="top" style="margin-bottom:8px;">
      <div><h1>Marketplace</h1></div>
      <div>
        <a class="btn" href="index.html">Home</a>
      </div>
    </div>

    <div id="top-map" class="map-top"></div>

    <div class="card">
      <div class="filters">
        <div>
          <label>Type</label>
          <select id="f-type">
            <option value="">All</option>
            <?php foreach (($types?:[]) as $t): ?>
              <option value="<?php echo safe($t['name']); ?>"><?php echo safe($t['name']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label>Breed</label>
          <select id="f-breed">
            <option value="">All</option>
            <?php foreach (($breeds?:[]) as $b): ?>
              <option data-typeid="<?php echo (int)$b['type_id']; ?>" value="<?php echo safe($b['name']); ?>"><?php echo safe($b['name']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label>Min Age</label>
          <input type="number" id="f-min-age" min="0" step="1" />
        </div>
        <div>
          <label>Max Age</label>
          <input type="number" id="f-max-age" min="0" step="1" />
        </div>
        <div>
          <label>Min Price</label>
          <input type="number" id="f-min-price" min="0" step="0.01" />
        </div>
        <div>
          <label>Max Price</label>
          <input type="number" id="f-max-price" min="0" step="0.01" />
        </div>
        <div>
          <label>Min Weight (kg)</label>
          <input type="number" id="f-min-weight" min="0" step="0.01" />
        </div>
        <div>
          <label>Max Weight (kg)</label>
          <input type="number" id="f-max-weight" min="0" step="0.01" />
        </div>
      </div>
      <div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap">
        <button id="apply" class="btn">Apply Filters</button>
        <button id="clear" class="btn" style="background:#718096;">Clear</button>
      </div>
    </div>

    <div id="feed" class="feed"></div>
    <div id="sentinel" style="height:24px;"></div>
  </div>

  <div id="viewModal" class="modal"><div class="panel"><div style="display:flex;justify-content:space-between;align-items:center;gap:8px;"><h2 style="margin:0;">Listing</h2><button class="btn" id="mClose" style="background:#e53e3e;">Close</button></div><div id="mBody" style="margin-top:8px;"></div></div></div>

  <script>
    (function(){
      function $(s){ return document.querySelector(s); }
      var feed = document.getElementById('feed');
      var sentinel = document.getElementById('sentinel');
      var topMapEl = document.getElementById('top-map');
      var map = L.map(topMapEl).setView([8.314209 , 124.859425], 12);
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(map);
      var markerLayer = L.layerGroup().addTo(map);
      var state = { offset: 0, limit: 10, loading: false, done: false };

      function currentFilters(){
        return {
          livestock_type: document.getElementById('f-type').value || '',
          breed: document.getElementById('f-breed').value || '',
          min_age: document.getElementById('f-min-age').value,
          max_age: document.getElementById('f-max-age').value,
          min_price: document.getElementById('f-min-price').value,
          max_price: document.getElementById('f-max-price').value,
          min_weight: document.getElementById('f-min-weight').value,
          max_weight: document.getElementById('f-max-weight').value
        };
      }
      function buildQuery(params){ var q = new URLSearchParams(); Object.keys(params).forEach(function(k){ if (params[k]!=='' && params[k]!=null) q.append(k, params[k]); }); return q.toString(); }

      function loadPins(){
        var q = new URLSearchParams(currentFilters()); q.append('pins','1');
        fetch('marketplace.php?'+q.toString())
          .then(function(r){ return r.json(); })
          .then(function(data){
            markerLayer.clearLayers();
            (data.activePins||[]).forEach(function(p){
              var m = L.circleMarker([p.lat, p.lng], { radius:7, color:'#16a34a', fillColor:'#22c55e', fillOpacity:0.9 }).addTo(markerLayer);
              m.bindTooltip((p.type||'')+' • '+(p.breed||''));
            });
          });
      }

      function escapeHtml(s){ if (s==null) return ''; return String(s).replace(/[&<>"]+/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }

      function renderItems(items){
        var frag = document.createDocumentFragment();
        items.forEach(function(it){
          var card = document.createElement('div'); card.className = 'item';
          var img = document.createElement('img'); img.src = it.thumb; img.alt='thumb'; img.style.width='120px'; img.style.height='120px'; img.style.objectFit='cover'; img.style.borderRadius='8px'; img.style.border='1px solid #e2e8f0'; img.onerror=function(){ if (img.src!==it.thumb_fallback) img.src = it.thumb_fallback; else img.style.display='none'; };
          var info = document.createElement('div');
          info.innerHTML = '<div><strong>'+escapeHtml(it.livestock_type)+' • '+escapeHtml(it.breed)+'</strong></div>'+
            '<div>'+escapeHtml(it.address)+'</div>'+
            '<div>Age: '+escapeHtml(it.age)+' • Weight: '+escapeHtml(it.weight)+'kg • Price: ₱'+escapeHtml(it.price)+'</div>'+
            '<div class="muted">Listing #'+it.listing_id+' • Seller #'+it.seller_id+' • '+escapeHtml(it.created||'')+'</div>'+
            (it.seller_name?('<div>Seller: '+escapeHtml(it.seller_name)+'</div>'):'');
          var actions = document.createElement('div'); actions.style.display='flex'; actions.style.flexDirection='column'; actions.style.gap='8px';
          var showBtn = document.createElement('button'); showBtn.className='btn'; showBtn.textContent='Show'; showBtn.addEventListener('click', function(){ openModal(it); });
          var loginBtn = document.createElement('a'); loginBtn.className='btn'; loginBtn.textContent='Login to Express Interest'; loginBtn.href='pages/authentication/login.php'; loginBtn.style.background='#4a5568'; loginBtn.style.textAlign='center';
          actions.appendChild(showBtn); actions.appendChild(loginBtn);
          card.appendChild(img); card.appendChild(info); card.appendChild(actions);
          frag.appendChild(card);
        });
        feed.appendChild(frag);
      }

      function loadMore(){ if (state.loading || state.done) return; state.loading = true; var params = Object.assign({ ajax: 1, limit: state.limit, offset: state.offset }, currentFilters()); var q = buildQuery(params);
        fetch('marketplace.php?'+q).then(function(r){ return r.json(); }).then(function(data){ var items=(data&&data.items)?data.items:[]; if(items.length===0){ state.done=true; return; } renderItems(items); state.offset += items.length; }).finally(function(){ state.loading=false; }); }

      var io = new IntersectionObserver(function(entries){ entries.forEach(function(e){ if (e.isIntersecting) loadMore(); }); }); io.observe(sentinel);

      document.getElementById('apply').addEventListener('click', function(){ feed.innerHTML=''; markerLayer.clearLayers(); state.offset=0; state.done=false; loadMore(); loadPins(); });
      document.getElementById('clear').addEventListener('click', function(){ ['f-type','f-breed','f-min-age','f-max-age','f-min-price','f-max-price','f-min-weight','f-max-weight'].forEach(function(id){ var el=document.getElementById(id); if(el.tagName==='SELECT') el.value=''; else el.value=''; }); feed.innerHTML=''; markerLayer.clearLayers(); state.offset=0; state.done=false; loadMore(); loadPins(); });

      // Preselect from query param
      if (window.PRE_TYPE){ var tSel=document.getElementById('f-type'); Array.from(tSel.options).forEach(function(o){ if ((o.value||'').toLowerCase()===String(window.PRE_TYPE).toLowerCase()) o.selected=true; }); }

      function openModal(it){
        var m = document.getElementById('viewModal'); var b = document.getElementById('mBody');
        b.innerHTML = ''+
          '<div style="display:flex;gap:12px;align-items:flex-start;">'+
            '<img src="'+it.thumb+'" onerror="this.onerror=null;this.src=\''+it.thumb_fallback+'\'" style="width:160px;height:160px;object-fit:cover;border:1px solid #e2e8f0;border-radius:8px" />'+
            '<div style="flex:1">'+
              '<div><strong>'+escapeHtml(it.livestock_type)+' • '+escapeHtml(it.breed)+'</strong></div>'+
              '<div>'+escapeHtml(it.address)+'</div>'+
              '<div>Age: '+escapeHtml(it.age)+' • Weight: '+escapeHtml(it.weight)+'kg • Price: ₱'+escapeHtml(it.price)+'</div>'+
              '<div class="muted">Listing #'+it.listing_id+' • Seller #'+it.seller_id+' • '+escapeHtml(it.created||'')+'</div>'+
              (it.seller_name?('<div>Seller: '+escapeHtml(it.seller_name)+'</div>'):'')+
              '<div style="margin-top:10px;"><a class="btn" style="background:#4a5568" href="pages/authentication/login.php">Login to Express Interest</a></div>'+
            '</div>'+
          '</div>'+
          '<div id="mMap" style="height:220px;border:1px solid #e2e8f0;border-radius:8px;margin-top:10px"></div>';
        m.style.display='flex';
        setTimeout(function(){ var el=document.getElementById('mMap'); if (!el || !window.L) return; var mm = L.map(el).setView([8.314209 , 124.859425], 12); L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(mm); if (it.lat!=null && it.lng!=null){ mm.setView([it.lat,it.lng], 12); L.marker([it.lat,it.lng]).addTo(mm); } },0);
      }
      document.getElementById('mClose').addEventListener('click', function(){ document.getElementById('viewModal').style.display='none'; });
      document.getElementById('viewModal').addEventListener('click', function(ev){ if (ev.target.id==='viewModal') document.getElementById('viewModal').style.display='none'; });

      // initial load
      loadPins();
      loadMore();
    })();
  </script>
</body>
</html>

