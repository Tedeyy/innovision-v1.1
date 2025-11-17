<?php
session_start();
require_once __DIR__ . '/../../../authentication/lib/supabase_client.php';

$role = $_SESSION['role'] ?? '';
if ($role !== 'bat'){
  header('Location: ../dashboard.php');
  exit;
}
$batId = (int)($_SESSION['user_id'] ?? 0);

function esc($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// Inline action: set schedule (date/time and location) for an ongoing transaction and add to BAT schedule
if (isset($_POST['action']) && $_POST['action']==='set_schedule'){
  header('Content-Type: application/json');
  $txId = isset($_POST['transaction_id']) ? (int)$_POST['transaction_id'] : 0;
  $listingId = isset($_POST['listing_id']) ? (int)$_POST['listing_id'] : 0;
  $sellerId = isset($_POST['seller_id']) ? (int)$_POST['seller_id'] : 0;
  $buyerId = isset($_POST['buyer_id']) ? (int)$_POST['buyer_id'] : 0;
  $dt = isset($_POST['transaction_date']) ? (string)$_POST['transaction_date'] : '';
  $loc = isset($_POST['transaction_location']) ? (string)$_POST['transaction_location'] : '';
  if (!$txId || !$listingId || !$sellerId || !$buyerId || $dt===''){
    echo json_encode(['ok'=>false,'error'=>'missing_params']); exit;
  }
  // Update ongoingtransactions with date/location and bat_id
  $upd = [
    'bat_id'=>$batId,
    'transaction_date'=>$dt,
    'transaction_location'=>$loc,
  ];
  [$ur,$us,$ue] = sb_rest('PATCH','ongoingtransactions',[ 'transaction_id'=>'eq.'.$txId ], [$upd]);
  if (!($us>=200 && $us<300)){
    echo json_encode(['ok'=>false,'error'=>'update_failed','code'=>$us]); exit;
  }
  // Also create a schedule entry
  $ts = strtotime($dt);
  $month = date('m', $ts); $day = date('d', $ts); $hour = (int)date('H', $ts); $minute = (int)date('i', $ts);
  $title = 'Transaction Meet-up for Listing #'.$listingId;
  $desc  = 'Seller '.$sellerId.' x Buyer '.$buyerId.' at '.$loc;
  $schedPayload = [[
    'bat_id'=>$batId,
    'title'=>$title,
    'description'=>$desc,
    'month'=>$month,
    'day'=>$day,
    'hour'=>$hour,
    'minute'=>$minute
  ]];
  [$sr,$ss,$se] = sb_rest('POST','schedule',[], $schedPayload, ['Prefer: return=representation']);
  $warning = null; if (!($ss>=200 && $ss<300)) $warning = 'Failed to create schedule entry';
  echo json_encode(['ok'=>true,'warning'=>$warning]); exit;
}

function fetch_table($table, $select, $order){
  [$rows,$st,$err] = sb_rest('GET', $table, [ 'select'=>$select, 'order'=>$order ]);
  if (!($st>=200 && $st<300) || !is_array($rows)) return [];
  return $rows;
}

$start = fetch_table(
  'starttransactions',
  'transaction_id,listing_id,seller_id,buyer_id,status,started_at,'.
  'buyer:buyer(user_id,user_fname,user_mname,user_lname,email,contact,location),' .
  'seller:seller(user_id,user_fname,user_mname,user_lname,email,contact,location),' .
  'listing:activelivestocklisting(listing_id,livestock_type,breed,price,created,address)',
  'started_at.desc'
);
$ongo  = fetch_table(
  'ongoingtransactions',
  'transaction_id,listing_id,seller_id,buyer_id,status,started_at,bat_id,transaction_date,transaction_location,Transaction_location,'.
  'buyer:buyer(user_id,user_fname,user_mname,user_lname,email,contact,location),' .
  'seller:seller(user_id,user_fname,user_mname,user_lname,email,contact,location),' .
  'listing:activelivestocklisting(listing_id,livestock_type,breed,price,created,address)',
  'started_at.desc'
);
$done  = fetch_table(
  'completedtransactions',
  'transaction_id,listing_id,seller_id,buyer_id,status,started_at,bat_id,transaction_date,transaction_location,completed_transaction,'.
  'buyer:buyer(user_id,user_fname,user_mname,user_lname,email,contact,location),' .
  'seller:seller(user_id,user_fname,user_mname,user_lname,email,contact,location),' .
  'listing:activelivestocklisting(listing_id,livestock_type,breed,price,created,address)',
  'completed_transaction.desc'
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Transaction Monitoring</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../style/dashboard.css">
  <style>
    .section{margin-bottom:16px}
    .table{width:100%;border-collapse:collapse}
    .table th,.table td{padding:8px;text-align:left}
    .table thead tr{border-bottom:1px solid #e2e8f0}
  </style>
</head>
<body>
  <div class="wrap">
    <div class="top" style="margin-bottom:8px;">
      <div><h1>Transaction Monitoring</h1></div>
      <div><a class="btn" href="../dashboard.php">Back to Dashboard</a></div>
    </div>

    <div class="card section">
      <h2 style="margin:0 0 8px 0;">Started</h2>
      <table class="table">
        <thead>
          <tr>
            <th>Tx ID</th>
            <th>Listing ID</th>
            <th>Seller ID</th>
            <th>Buyer ID</th>
            <th>Status</th>
            <th>Started</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$start || count($start)===0): ?>
            <tr><td colspan="6" style="color:#4a5568;">No started transactions.</td></tr>
          <?php else: foreach ($start as $r): ?>
            <tr>
              <td><?php echo esc($r['transaction_id'] ?? ''); ?></td>
              <td><?php echo esc($r['listing_id'] ?? ''); ?></td>
              <td><?php echo esc($r['seller_id'] ?? ''); ?></td>
              <td><?php echo esc($r['buyer_id'] ?? ''); ?></td>
              <td><?php echo esc($r['status'] ?? ''); ?></td>
              <td><?php echo esc($r['started_at'] ?? ''); ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <div class="card section">
      <h2 style="margin:0 0 8px 0;">Ongoing</h2>
      <table class="table">
        <thead>
          <tr>
            <th>Tx ID</th>
            <th>Listing ID</th>
            <th>Seller ID</th>
            <th>Buyer ID</th>
            <th>Status</th>
            <th>Started</th>
            <th>BAT ID</th>
            <th>Meet-up Date</th>
            <th>Location</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$ongo || count($ongo)===0): ?>
            <tr><td colspan="10" style="color:#4a5568;">No ongoing transactions.</td></tr>
          <?php else: foreach ($ongo as $r): $loc = $r['transaction_location'] ?? ($r['Transaction_location'] ?? ''); ?>
            <tr>
              <td><?php echo esc($r['transaction_id'] ?? ''); ?></td>
              <td><?php echo esc($r['listing_id'] ?? ''); ?></td>
              <td><?php echo esc($r['seller_id'] ?? ''); ?></td>
              <td><?php echo esc($r['buyer_id'] ?? ''); ?></td>
              <td><?php echo esc($r['status'] ?? ''); ?></td>
              <td><?php echo esc($r['started_at'] ?? ''); ?></td>
              <td><?php echo esc($r['bat_id'] ?? $r['Bat_id'] ?? ''); ?></td>
              <td><?php echo esc($r['transaction_date'] ?? ''); ?></td>
              <td><?php echo esc($loc); ?></td>
              <td><button class="btn btn-show" data-row="<?php echo esc(json_encode($r)); ?>">Show</button></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <div class="card section">
      <h2 style="margin:0 0 8px 0;">Completed</h2>
      <table class="table">
        <thead>
          <tr>
            <th>Tx ID</th>
            <th>Listing ID</th>
            <th>Seller ID</th>
            <th>Buyer ID</th>
            <th>Status</th>
            <th>Started</th>
            <th>BAT ID</th>
            <th>Meet-up Date</th>
            <th>Location</th>
            <th>Completed</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$done || count($done)===0): ?>
            <tr><td colspan="10" style="color:#4a5568;">No completed transactions.</td></tr>
          <?php else: foreach ($done as $r): ?>
            <tr>
              <td><?php echo esc($r['transaction_id'] ?? ''); ?></td>
              <td><?php echo esc($r['listing_id'] ?? ''); ?></td>
              <td><?php echo esc($r['seller_id'] ?? ''); ?></td>
              <td><?php echo esc($r['buyer_id'] ?? ''); ?></td>
              <td><?php echo esc($r['status'] ?? ''); ?></td>
              <td><?php echo esc($r['started_at'] ?? ''); ?></td>
              <td><?php echo esc($r['bat_id'] ?? ''); ?></td>
              <td><?php echo esc($r['transaction_date'] ?? ''); ?></td>
              <td><?php echo esc($r['transaction_location'] ?? ''); ?></td>
              <td><?php echo esc($r['completed_transaction'] ?? ''); ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

  </div>

  <!-- Ongoing Details Modal -->
  <div id="ogModal" class="modal" style="display:none;align-items:center;justify-content:center;">
    <div class="panel">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;">
        <h2 style="margin:0;">Ongoing Transaction</h2>
        <button class="close-btn" data-close="ogModal">Close</button>
      </div>
      <div id="ogBody" style="margin-top:8px;"></div>
    </div>
  </div>

  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
  <script>
    (function(){
      function $(s){ return document.querySelector(s); }
      function $all(s){ return Array.prototype.slice.call(document.querySelectorAll(s)); }
      function openModal(id){ var el=document.getElementById(id); if(el) el.style.display='flex'; }
      function closeModal(id){ var el=document.getElementById(id); if(el) el.style.display='none'; }
      $all('.close-btn').forEach(function(b){ b.addEventListener('click', function(){ closeModal(b.getAttribute('data-close')); }); });
      function fullname(p){ if (!p) return ''; var f=p.user_fname||'', m=p.user_mname||'', l=p.user_lname||''; return (f+' '+(m?m+' ':'')+l).trim(); }
      function initials(p){ var f=(p.user_fname||'').trim(), l=(p.user_lname||'').trim(); var s=(f?f[0]:'')+(l?l[0]:''); return s.toUpperCase()||'U'; }
      function avatarHTML(p){ var ini=initials(p); return '<div style="width:40px;height:40px;border-radius:50%;background:#edf2f7;color:#2d3748;display:flex;align-items:center;justify-content:center;font-weight:600;">'+ini+'</div>'; }
      document.addEventListener('click', function(e){
        if (e.target && e.target.classList.contains('btn-show')){
          var data = {};
          try{ data = JSON.parse(e.target.getAttribute('data-row')||'{}'); }catch(_){ data={}; }
          var seller = data.seller||{}; var buyer = data.buyer||{}; var listing = data.listing||{};
          // Compute thumbnail from seller name + listing created
          var sf=seller.user_fname||'', sm=seller.user_mname||'', sl=seller.user_lname||''; var full=(sf+' '+(sm?sm+' ':'')+sl).trim();
          var san = (full.toLowerCase().replace(/[^a-z0-9]+/g,'_')||'user').replace(/^_+|_+$/g,'');
          var sellerNewFolder = (data.seller_id||'') + '_' + san;
          var created = listing.created||''; var digits = String(created).replace(/\D+/g,''); var createdKey = digits.substring(0,14);
          var legacyFolder = (data.seller_id||'') + '_' + (listing.listing_id||'');
          var root = 'listings/verified';
          var thumb = createdKey ? ('../../bat/pages/storage_image.php?path='+root+'/'+sellerNewFolder+'/'+createdKey+'_1img.jpg') : ('../../bat/pages/storage_image.php?path='+root+'/'+legacyFolder+'/image1');
          var thumb_fallback = '../../bat/pages/storage_image.php?path='+root+'/'+legacyFolder+'/image1';

          var body = document.getElementById('ogBody');
          var img = document.createElement('img'); img.src=thumb; img.alt='thumb'; img.style.width='160px'; img.style.height='160px'; img.style.objectFit='cover'; img.style.border='1px solid #e2e8f0'; img.style.borderRadius='8px';
          img.onerror = function(){ if (thumb_fallback && img.src!==thumb_fallback) img.src = thumb_fallback; else img.style.display='none'; };
          body.innerHTML = ''+
            '<h3>Listing</h3>'+
            '<div style="display:flex;gap:12px;align-items:flex-start;">'+
              '<div><strong>'+(listing.livestock_type||'')+' • '+(listing.breed||'')+'</strong><div>Price: ₱'+(listing.price||'')+'</div><div>Address: '+(listing.address||'')+'</div><div class="subtle">Listing #'+(listing.listing_id||'')+' • Created '+(listing.created||'')+'</div></div>'+
              '<div id="ogImgWrap"></div>'+
            '</div>'+
            '<div style="margin-top:10px;"><div id="ogMap" style="height:220px;border:1px solid #e2e8f0;border-radius:8px"></div></div>'+
            '<hr style="margin:12px 0;border:none;border-top:1px solid #e2e8f0" />'+
            '<h3>Seller</h3>'+
            '<div style="display:flex;gap:10px;align-items:flex-start;">'+ avatarHTML(seller) +
              '<div><div><strong>'+fullname(seller)+'</strong></div><div>Email: '+(seller.email||'')+'</div><div>Contact: '+(seller.contact||'')+'</div></div>'+
            '</div>'+
            '<h3 style="margin-top:10px;">Buyer</h3>'+
            '<div style="display:flex;gap:10px;align-items:flex-start;">'+ avatarHTML(buyer) +
              '<div><div><strong>'+fullname(buyer)+'</strong></div><div>Email: '+(buyer.email||'')+'</div><div>Contact: '+(buyer.contact||'')+'</div></div>'+
            '</div>'+
            '<hr style="margin:12px 0;border:none;border-top:1px solid #e2e8f0" />'+
            '<h3>Schedule Meet-up</h3>'+
            '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">'+
              '<label>Date & Time: <input type="datetime-local" id="ogWhen" /></label>'+
              '<label>Location: <input type="text" id="ogLoc" placeholder="Click map to set lat,lng or type address" style="min-width:300px;"/></label>'+
              '<button class="btn" id="ogSave">Save</button>'+
            '</div>';
          var wrap = document.getElementById('ogImgWrap'); if (wrap) wrap.appendChild(img);
          setTimeout(function(){
            var mEl = document.getElementById('ogMap'); if (!mEl || !window.L) return;
            var map = L.map(mEl).setView([8.314209 , 124.859425], 12);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(map);
            var marker=null;
            map.on('click', function(ev){
              var lat = ev.latlng.lat.toFixed(6), lng = ev.latlng.lng.toFixed(6);
              $('#ogLoc').value = lat+','+lng;
              if (marker){ marker.setLatLng(ev.latlng); } else { marker = L.marker(ev.latlng).addTo(map); }
            });
          }, 0);
          openModal('ogModal');
          var save = document.getElementById('ogSave');
          save.addEventListener('click', function(){
            var when = document.getElementById('ogWhen').value;
            var loc = document.getElementById('ogLoc').value;
            if (!when){ alert('Please select date and time'); return; }
            save.disabled = true; save.textContent = 'Saving...';
            var fd = new FormData();
            fd.append('action','set_schedule');
            fd.append('transaction_id', data.transaction_id||'');
            fd.append('listing_id', data.listing_id||'');
            fd.append('seller_id', data.seller_id||'');
            fd.append('buyer_id', data.buyer_id||'');
            fd.append('transaction_date', when);
            fd.append('transaction_location', loc||'');
            fetch('transaction_monitoring.php', { method:'POST', body: fd, credentials:'same-origin' })
              .then(function(r){ return r.json(); })
              .then(function(res){
                if (!res || res.ok===false){
                  alert('Failed to save schedule'+(res && res.code? (' (code '+res.code+')') : ''));
                  save.disabled=false; save.textContent='Save';
                } else {
                  var msg='Schedule saved'; if(res.warning) msg+='\n'+res.warning; alert(msg);
                  save.disabled=true; save.textContent='Saved';
                }
              })
              .catch(function(){ save.disabled=false; save.textContent='Save'; });
          });
        }
      });
    })();
  </script>
</body>
</html>
