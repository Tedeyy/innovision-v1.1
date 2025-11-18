<?php
session_start();
require_once __DIR__ . '/../../../authentication/lib/supabase_client.php';

if (($_SESSION['role'] ?? '') !== 'buyer'){
  header('Location: ../dashboard.php');
  exit;
}
$buyerId = (int)($_SESSION['user_id'] ?? 0);
function safe($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// Handle rating submission from buyer
if (isset($_POST['action']) && $_POST['action']==='rate_seller'){
  header('Content-Type: application/json');
  $sellerId = (int)($_POST['seller_id'] ?? 0);
  $buyerIdIn = (int)($_POST['buyer_id'] ?? 0);
  $txId = (int)($_POST['transaction_id'] ?? 0);
  $rating = (float)($_POST['rating'] ?? 0);
  $desc = trim((string)($_POST['description'] ?? ''));
  if ($buyerIdIn !== $buyerId){ echo json_encode(['ok'=>false,'error'=>'unauthorized']); exit; }
  if ($sellerId<=0 || $buyerIdIn<=0 || $txId<=0 || $rating<1 || $rating>5){ echo json_encode(['ok'=>false,'error'=>'invalid_params']); exit; }
  $payload = [[ 'buyer_id'=>$buyerIdIn, 'seller_id'=>$sellerId, 'transaction_id'=>$txId, 'rating'=>$rating, 'description'=>$desc ]];
  [$res,$st,$err] = sb_rest('POST','userrating',[], $payload, ['Prefer: return=representation']);
  if (!($st>=200 && $st<300)){
    $detail = is_array($res) && isset($res['message']) ? $res['message'] : (is_string($res)?$res:'');
    echo json_encode(['ok'=>false,'error'=>'insert_failed','code'=>$st,'detail'=>$detail]);
    exit;
  }
  echo json_encode(['ok'=>true]);
  exit;
}

if (isset($_GET['action']) && $_GET['action']==='list'){
  header('Content-Type: application/json');
  // Fetch from three tables where current user is the buyer
  [$srows,$sst,$sse] = sb_rest('GET','starttransactions',[
    'select'=>'transaction_id,listing_id,seller_id,buyer_id,status,started_at,buyer:buyer(user_id,user_fname,user_mname,user_lname,email,contact,location),seller:seller(user_id,user_fname,user_mname,user_lname,email,contact,location),listing:activelivestocklisting(listing_id,livestock_type,breed,price,created,address)',
    'buyer_id'=>'eq.'.$buyerId,
    'order'=>'started_at.desc'
  ]);
  if (!($sst>=200 && $sst<300) || !is_array($srows)) $srows = [];
  [$orows,$ost,$ose] = sb_rest('GET','ongoingtransactions',[
    'select'=>'transaction_id,listing_id,seller_id,buyer_id,status,started_at,transaction_date,transaction_location,bat_id,bat:bat(user_id,user_fname,user_mname,user_lname),buyer:buyer(user_id,user_fname,user_mname,user_lname,email,contact,location),seller:seller(user_id,user_fname,user_mname,user_lname,email,contact,location),listing:activelivestocklisting(listing_id,livestock_type,breed,price,created,address)',
    'buyer_id'=>'eq.'.$buyerId,
    'order'=>'started_at.desc'
  ]);
  if (!($ost>=200 && $ost<300) || !is_array($orows)) $orows = [];
  [$crows,$cst,$cse] = sb_rest('GET','completedtransactions',[
    'select'=>'transaction_id,listing_id,seller_id,buyer_id,status,started_at,buyer:buyer(user_id,user_fname,user_mname,user_lname,email,contact,location),seller:seller(user_id,user_fname,user_mname,user_lname,email,contact,location),listing:activelivestocklisting(listing_id,livestock_type,breed,price,created,address)',
    'buyer_id'=>'eq.'.$buyerId,
    'order'=>'started_at.desc'
  ]);
  if (!($cst>=200 && $cst<300) || !is_array($crows)) $crows = [];
  $rows = array_merge($srows, $orows, $crows);
  // Map rows to include thumbnails and seller location
  $out = [];
  foreach ($rows as $r){
    $seller = $r['seller'] ?? [];
    $listing = $r['listing'] ?? [];
    $sf = $seller['user_fname'] ?? ''; $sm = $seller['user_mname'] ?? ''; $sl = $seller['user_lname'] ?? '';
    $full = trim($sf.' '.($sm?:'').' '.$sl);
    $san = strtolower(preg_replace('/[^a-z0-9]+/i','_', $full));
    $san = trim($san, '_'); if ($san==='') $san='user';
    $sellerNewFolder = ((int)($r['seller_id'] ?? 0)).'_'.$san;
    $created = $listing['created'] ?? '';
    $digits = preg_replace('/\D+/', '', (string)$created);
    $createdKey = substr($digits, 0, 14);
    $legacyFolder = ((int)($r['seller_id'] ?? 0)).'_'.((int)($listing['listing_id'] ?? 0));
    
    // Determine status folder based on listing status
    $status = strtolower($listing['status'] ?? 'active');
    $statusFolder = 'active'; // default
    if ($status === 'verified') $statusFolder = 'verified';
    elseif ($status === 'sold') $statusFolder = 'sold';
    elseif ($status === 'underreview') $statusFolder = 'underreview';
    elseif ($status === 'denied') $statusFolder = 'denied';
    
    $thumb = ($createdKey !== '')
      ? ('../../bat/pages/storage_image.php?path=listings/'.$statusFolder.'/'.$sellerNewFolder.'/'.$createdKey.'_1img.jpg')
      : ('../../bat/pages/storage_image.php?path=listings/'.$statusFolder.'/'.$legacyFolder.'/image1');
    $thumb_fallback = '../../bat/pages/storage_image.php?path=listings/'.$statusFolder.'/'.$legacyFolder.'/image1';
    // Seller location
    $lat=null; $lng=null;
    if (!empty($seller['location'])){
      $loc = json_decode($seller['location'], true);
      if (is_array($loc)){ $lat = $loc['lat'] ?? null; $lng = $loc['lng'] ?? null; }
    }
    $r['thumb'] = $thumb; $r['thumb_fallback'] = $thumb_fallback; $r['lat']=$lat; $r['lng']=$lng;
    
    // Add meet-up details from ongoingtransactions
    if (isset($r['transaction_date'])) {
      $r['meetup_date'] = date('Y-m-d', strtotime($r['transaction_date']));
      $r['meetup_time'] = date('H:i', strtotime($r['transaction_date']));
    } else {
      $r['meetup_date'] = null;
      $r['meetup_time'] = null;
    }
    
    $r['meetup_location'] = $r['transaction_location'] ?? null;
    
    // Add BAT fullname
    if (isset($r['bat'])) {
      $bat = $r['bat'];
      $r['bat_fullname'] = trim(($bat['user_fname']??'').' '.($bat['user_mname']??'').' '.($bat['user_lname']??''));
    } else {
      $r['bat_fullname'] = null;
    }
    
    $out[] = $r;
  }
  echo json_encode(['ok'=>true,'data'=>$out]);
  exit;
}

?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Transactions</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../style/dashboard.css">
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
  <style>
    .modal{position:fixed;inset:0;background:rgba(0,0,0,0.5);display:none;align-items:center;justify-content:center;z-index:9999}
    .panel{background:#fff;border-radius:10px;max-width:900px;width:95vw;max-height:90vh;overflow:auto;padding:16px}
    .subtle{color:#4a5568;font-size:12px}
    .table{width:100%;border-collapse:collapse}
    .table th,.table td{padding:8px;text-align:left}
    .table thead tr{border-bottom:1px solid #e2e8f0}
    .close-btn{background:#e53e3e;color:#fff;border:none;border-radius:6px;padding:6px 10px;cursor:pointer}
    .badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:12px;font-weight:600}
    .badge-started{background:#ebf5ff;color:#1e40af;border:1px solid #bfdbfe}
    .badge-ongoing{background:#fff7ed;color:#9a3412;border:1px solid #fed7aa}
    .badge-completed{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0}
    .badge-default{background:#f1f5f9;color:#334155;border:1px solid #e2e8f0}
  </style>
</head>
<body>
  <div class="wrap">
    <div class="top" style="margin-bottom:8px;">
      <div><h1>My Transactions</h1></div>
      <div>
        <a class="btn" href="../dashboard.php">Back to Dashboard</a>
      </div>
    </div>

    <div class="card">
      <table class="table" id="txTable">
        <thead>
          <tr>
            <th>Tx ID</th>
            <th>Seller</th>
            <th>Listing</th>
            <th>Status</th>
            <th>Started</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>
    </div>
  </div>

  <div id="txModal" class="modal">
    <div class="panel">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;">
        <h2 style="margin:0;">Transaction Details</h2>
        <button class="close-btn" data-close="txModal">Close</button>
      </div>
      <div id="txBody" style="margin-top:8px;"></div>
    </div>
  </div>

  <div id="rateModal" class="modal">
    <div class="panel">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;">
        <h2 style="margin:0;">Rate Seller</h2>
        <button class="close-btn" data-close="rateModal">Close</button>
      </div>
      <div style="margin-top:8px;">
        <div class="subtle" style="margin-bottom:8px;">Please rate your experience with this seller.</div>
        <div id="rateStars" style="font-size:28px;color:#e2e8f0;cursor:pointer;user-select:none;">
          <span data-v="1">★</span>
          <span data-v="2">★</span>
          <span data-v="3">★</span>
          <span data-v="4">★</span>
          <span data-v="5">★</span>
        </div>
        <textarea id="rateDesc" placeholder="Optional description (max 255 chars)" maxlength="255" style="margin-top:10px;width:100%;min-height:90px;padding:10px;border:1px solid #e2e8f0;border-radius:8px;"></textarea>
        <div style="margin-top:10px;display:flex;gap:8px;align-items:center;">
          <button class="btn" id="btnSubmitRating">Submit Rating</button>
          <span class="subtle" id="rateMsg"></span>
        </div>
      </div>
    </div>
  </div>

  <script>
    (function(){
      function $(s){ return document.querySelector(s); }
      function $all(s){ return Array.prototype.slice.call(document.querySelectorAll(s)); }
      function openModal(id){ var el=document.getElementById(id); if(el) el.style.display='flex'; }
      function closeModal(id){ var el=document.getElementById(id); if(el) el.style.display='none'; }
      $all('.close-btn').forEach(function(b){ b.addEventListener('click', function(){ closeModal(b.getAttribute('data-close')); }); });

      function fullname(p){ if (!p) return ''; var f=p.user_fname||'', m=p.user_mname||'', l=p.user_lname||''; return (f+' '+(m?m+' ':'')+l).trim(); }
      function statusBadge(s){
        var t=(s||'').toLowerCase();
        if (t==='started') return '<span class="badge badge-started">Started</span>';
        if (t==='ongoing') return '<span class="badge badge-ongoing">Ongoing</span>';
        if (t==='completed') return '<span class="badge badge-completed">Completed</span>';
        return '<span class="badge badge-default">'+(s||'')+'</span>';
      }
      function load(){
        fetch('transactions.php?action=list', { credentials:'same-origin' })
          .then(function(r){ return r.json(); })
          .then(function(res){
            var tb = document.querySelector('#txTable tbody');
            tb.innerHTML = '';
            (res.data||[]).forEach(function(row){
              var seller = row.seller||{}; var listing = row.listing||{};
              var tr = document.createElement('tr');
              tr.innerHTML = '<td>'+ (row.transaction_id||'') +'</td>'+
                '<td>'+ fullname(seller) +'</td>'+
                '<td>'+(listing.livestock_type||'')+' • '+(listing.breed||'')+'</td>'+
                '<td>'+ statusBadge(row.status) +'</td>'+
                '<td>'+(row.started_at||'')+'</td>'+
                '<td><button class="btn btn-show" data-row="'+encodeURIComponent(JSON.stringify(row))+'">Show</button></td>';
              tb.appendChild(tr);
            });
          });
    }
      load();

      document.addEventListener('click', function(e){
        if (e.target && e.target.classList.contains('btn-show')){
          var data = JSON.parse(decodeURIComponent(e.target.getAttribute('data-row')||'{}'));
          var seller = data.seller||{}; var buyer = data.buyer||{}; var listing = data.listing||{};
          var body = document.getElementById('txBody');
          var img = document.createElement('img');
          img.src = data.thumb || '';
          img.alt = 'thumb';
          img.style.width = '160px'; img.style.height='160px'; img.style.objectFit='cover'; img.style.border='1px solid #e2e8f0'; img.style.borderRadius='8px';
          img.onerror = function(){ if (data.thumb_fallback && img.src!==data.thumb_fallback) img.src = data.thumb_fallback; else img.style.display='none'; };
          function initials(p){ var f=(p.user_fname||'').trim(), l=(p.user_lname||'').trim(); var s=(f?f[0]:'')+(l?l[0]:''); return s.toUpperCase()||'U'; }
          function avatarHTML(p){ var ini=initials(p); return '<div style="width:40px;height:40px;border-radius:50%;background:#edf2f7;color:#2d3748;display:flex;align-items:center;justify-content:center;font-weight:600;">'+ini+'</div>'; }
          body.innerHTML = ''+
            '<h3>Listing</h3>'+
            '<div style="display:flex;gap:12px;align-items:flex-start;">'+
              '<div><strong>'+(listing.livestock_type||'')+' • '+(listing.breed||'')+'</strong><div>Price: ₱'+(listing.price||'')+'</div><div>Address: '+(listing.address||'')+'</div><div class="subtle">Listing #'+(listing.listing_id||'')+' • Created '+(listing.created||'')+'</div></div>'+
              '<div id="imgWrap"></div>'+
            '</div>'+
            '<div style="margin-top:10px;"><div id="txMap" style="height:200px;border:1px solid #e2e8f0;border-radius:8px"></div></div>'+
            '<hr style="margin:12px 0;border:none;border-top:1px solid #e2e8f0" />'+
            '<h3>Seller</h3>'+
            '<div style="display:flex;gap:10px;align-items:flex-start;">'+ avatarHTML(seller) +
              '<div><div><strong>'+fullname(seller)+'</strong></div><div>Email: '+(seller.email||'')+'</div><div>Contact: '+(seller.contact||'')+'</div></div>'+
            '</div>'+
            '<h3 style="margin-top:10px;">Buyer</h3>'+
            '<div style="display:flex;gap:10px;align-items:flex-start;">'+ avatarHTML(buyer) +
              '<div><div><strong>'+fullname(buyer)+'</strong></div><div>Email: '+(buyer.email||'')+'</div><div>Contact: '+(buyer.contact||'')+'</div></div>'+
            '</div>'+
            // actions if completed
            ((String(data.status||'').toLowerCase()==='completed') ? '<div style="margin-top:10px;"><button class="btn" id="btnRateSeller">Rate Seller</button></div>' : '');
          var wrap = document.getElementById('imgWrap'); if (wrap) wrap.appendChild(img);
          setTimeout(function(){
            var mEl = document.getElementById('txMap'); if (!mEl || !window.L) return;
            var map = L.map(mEl).setView([8.314209 , 124.859425], 12);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(map);
            if (data.lat!=null && data.lng!=null){
              map.setView([data.lat, data.lng], 12);
              L.marker([data.lat, data.lng]).addTo(map);
            }
          }, 0);
          openModal('txModal');
          // Rating button handler
          var rb = document.getElementById('btnRateSeller');
          if (rb){
            rb.addEventListener('click', function(){
              var rm = document.getElementById('rateModal');
              rm.setAttribute('data-seller', String(data.seller_id||''));
              rm.setAttribute('data-transaction', String(data.transaction_id||''));
              openModal('rateModal');
            });
          }
        }
      });

      ['txModal','rateModal'].forEach(function(id){ var el=document.getElementById(id); if(el){ el.addEventListener('click', function(ev){ if(ev.target===el) closeModal(id); }); }});

      // Star rating interactions
      var currentRating = 5;
      function paintStars(n){
        var stars = document.querySelectorAll('#rateStars span');
        Array.prototype.forEach.call(stars, function(s){
          var v = parseInt(s.getAttribute('data-v'),10);
          s.style.color = (v<=n)? '#f59e0b' : '#e2e8f0';
        });
      }
      paintStars(currentRating);
      var starsWrap = document.getElementById('rateStars');
      if (starsWrap){
        starsWrap.addEventListener('click', function(e){
          var v = parseInt(e.target.getAttribute('data-v'),10);
          if (!isNaN(v)) { currentRating = v; paintStars(currentRating); }
        });
      }

      // Submit rating
      var btnSubmitRating = document.getElementById('btnSubmitRating');
      if (btnSubmitRating){
        btnSubmitRating.addEventListener('click', function(){
          var rm = document.getElementById('rateModal');
          var sellerId = parseInt(rm.getAttribute('data-seller')||'0',10) || 0;
          var txId = parseInt(rm.getAttribute('data-transaction')||'0',10) || 0;
          if (!(currentRating>=1 && currentRating<=5)) { alert('Rating must be 1-5'); return; }
          var fd = new FormData();
          fd.append('action','rate_seller');
          fd.append('buyer_id','<?php echo (int)$buyerId; ?>');
          fd.append('seller_id', String(sellerId));
          fd.append('transaction_id', String(txId));
          fd.append('rating', String(currentRating));
          fd.append('description', (document.getElementById('rateDesc')||{}).value||'');
          btnSubmitRating.disabled = true; btnSubmitRating.textContent = 'Submitting...';
          fetch('transactions.php', { method:'POST', body: fd, credentials:'same-origin' })
            .then(function(r){ return r.json(); })
            .then(function(res){
              if (!res || res.ok===false){
                alert('Failed to submit rating'+(res && res.code? (' (code '+res.code+')') : ''));
                btnSubmitRating.disabled = false; btnSubmitRating.textContent = 'Submit Rating';
              } else {
                btnSubmitRating.textContent = 'Thanks!';
                setTimeout(function(){ closeModal('rateModal'); closeModal('txModal'); }, 600);
              }
            })
            .catch(function(){ btnSubmitRating.disabled = false; btnSubmitRating.textContent = 'Submit Rating'; });
        });
      }
    })();
  </script>
</body>
</html>
