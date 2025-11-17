<?php
session_start();
require_once __DIR__ . '/../../../authentication/lib/supabase_client.php';

// Allow all roles; require login only
$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId === 0){
  $_SESSION['flash_error'] = 'Please sign in to view transactions.';
  header('Location: ../dashboard.php');
  exit;
}

if (isset($_POST['action']) && $_POST['action']==='complete_transaction'){
  header('Content-Type: application/json');
  $txId = isset($_POST['transaction_id']) ? (int)$_POST['transaction_id'] : 0;
  $listingId = isset($_POST['listing_id']) ? (int)$_POST['listing_id'] : 0;
  $sellerIdIn = isset($_POST['seller_id']) ? (int)$_POST['seller_id'] : 0;
  $buyerIdIn = isset($_POST['buyer_id']) ? (int)$_POST['buyer_id'] : 0;
  $result = isset($_POST['result']) ? (string)$_POST['result'] : 'successful';
  $price = isset($_POST['price']) ? (float)$_POST['price'] : 0.0;
  $payment = isset($_POST['payment_method']) ? (string)$_POST['payment_method'] : '';
  if (!$listingId || !$sellerIdIn || !$buyerIdIn || $price<=0 || $payment===''){
    echo json_encode(['ok'=>false,'error'=>'missing_params']);
    exit;
  }
  // fetch ongoing for details (bat_id, dates, location)
  $ongo = null;
  if ($txId){
    [$og,$ogs,$oge] = sb_rest('GET','ongoingtransactions',[ 'select'=>'*', 'transaction_id'=>'eq.'.$txId, 'limit'=>1 ]);
    if ($ogs>=200 && $ogs<300 && is_array($og) && isset($og[0])) $ongo = $og[0];
  }
  if (!$ongo){
    [$og,$ogs,$oge] = sb_rest('GET','ongoingtransactions',[ 'select'=>'*', 'listing_id'=>'eq.'.$listingId, 'seller_id'=>'eq.'.$sellerIdIn, 'buyer_id'=>'eq.'.$buyerIdIn, 'limit'=>1 ]);
    if ($ogs>=200 && $ogs<300 && is_array($og) && isset($og[0])) $ongo = $og[0];
  }
  $started_at = $ongo['started_at'] ?? date('c');
  $bat_id = $ongo['bat_id'] ?? null;
  $when = $ongo['transaction_date'] ?? null;
  $loc  = $ongo['transaction_location'] ?? null;
  // insert completedtransactions
  $compPayload = [[
    'listing_id'=>$listingId,
    'seller_id'=>$sellerIdIn,
    'buyer_id'=>$buyerIdIn,
    'status'=>'Completed',
    'started_at'=>$started_at,
    'bat_id'=>$bat_id,
    'transaction_date'=>$when,
    'transaction_location'=>$loc
  ]];
  [$cres,$cst,$cerr] = sb_rest('POST','completedtransactions',[], $compPayload, ['Prefer: return=representation']);
  if (!($cst>=200 && $cst<300) || !is_array($cres) || !isset($cres[0])){
    $detail = is_array($cres) && isset($cres['message']) ? $cres['message'] : (is_string($cres)?$cres:'');
    echo json_encode(['ok'=>false,'error'=>'completed_insert_failed','code'=>$cst,'detail'=>$detail]);
    exit;
  }
  $completed = $cres[0];
  $completedTxId = (int)($completed['transaction_id'] ?? 0);
  // transactions_logs
  $logPayload = [[
    'listing_id'=>$listingId,
    'seller_id'=>$sellerIdIn,
    'buyer_id'=>$buyerIdIn,
    'status'=>'Completed',
    'started_at'=>$started_at,
    'bat_id'=>$bat_id,
    'transaction_date'=>$when,
    'transaction_location'=>$loc,
    'completed_transaction'=>date('c')
  ]];
  [$lr,$ls,$le] = sb_rest('POST','transactions_logs',[], $logPayload, ['Prefer: return=representation']);
  $warnings = [];
  if (!($ls>=200 && $ls<300)) $warnings[] = 'Failed to write transactions_logs';

  // delete ongoing row (if existed)
  if ($ongo && isset($ongo['transaction_id'])){
    [$dr,$ds,$de] = sb_rest('DELETE','ongoingtransactions',[ 'transaction_id'=>'eq.'.$ongo['transaction_id'] ]);
    if (!($ds>=200 && $ds<300)) $warnings[] = 'Failed to remove ongoing transaction';
  }

  // remove listing interests regardless of result
  [$ir,$is,$ie] = sb_rest('DELETE','listinginterest',[ 'listing_id'=>'eq.'.$listingId ]);
  if (!($is>=200 && $is<300)) $warnings[] = 'Failed to clear listing interests';

  if ($result==='successful'){
    // fetch listing details
    [$lrow,$lst,$ler] = sb_rest('GET','activelivestocklisting',[ 'select'=>'*', 'listing_id'=>'eq.'.$listingId, 'limit'=>1 ]);
    $listing = ($lst>=200 && $lst<300 && is_array($lrow) && isset($lrow[0])) ? $lrow[0] : [];
    // insert soldlivestocklisting
    $soldPayload = [[
      'listing_id'=>$listingId,
      'seller_id'=>$sellerIdIn,
      'livestock_type'=>$listing['livestock_type'] ?? null,
      'breed'=>$listing['breed'] ?? null,
      'address'=>$listing['address'] ?? '',
      'age'=>$listing['age'] ?? 0,
      'weight'=>$listing['weight'] ?? 0,
      'price'=>$listing['price'] ?? 0,
      'soldprice'=>$price,
      'status'=>'Sold'
    ]];
    [$sr,$ss,$se] = sb_rest('POST','soldlivestocklisting',[], $soldPayload, ['Prefer: return=representation']);
    if (!($ss>=200 && $ss<300)) $warnings[] = 'Failed to create sold listing';
    // logs for listing
    $llogPayload = [[
      'listing_id'=>$listingId,
      'seller_id'=>$sellerIdIn,
      'livestock_type'=>$listing['livestock_type'] ?? '',
      'breed'=>$listing['breed'] ?? '',
      'address'=>$listing['address'] ?? '',
      'age'=>$listing['age'] ?? 0,
      'weight'=>$listing['weight'] ?? 0,
      'price'=>$listing['price'] ?? 0,
      'status'=>'Sold',
      'bat_id'=>$bat_id
    ]];
    [$llr,$lls,$lle] = sb_rest('POST','livestocklisting_logs',[], $llogPayload, ['Prefer: return=representation']);
    if (!($lls>=200 && $lls<300)) $warnings[] = 'Failed to write livestock listing logs';
    // move pins from activelocation_pins to soldlocation_pins and logs
    [$pr,$ps,$pe] = sb_rest('GET','activelocation_pins',[ 'select'=>'*', 'listing_id'=>'eq.'.$listingId ]);
    if ($ps>=200 && $ps<300 && is_array($pr)){
      foreach ($pr as $pin){
        $pinPayload = [[ 'location'=>$pin['location'] ?? '', 'listing_id'=>$listingId, 'status'=>'Sold' ]];
        sb_rest('POST','soldlocation_pins',[], $pinPayload, ['Prefer: return=representation']);
        $plogPayload = [[ 'location'=>$pin['location'] ?? '', 'listing_id'=>$listingId, 'status'=>'Sold' ]];
        sb_rest('POST','location_pin_logs',[], $plogPayload, ['Prefer: return=representation']);
      }
      sb_rest('DELETE','activelocation_pins',[ 'listing_id'=>'eq.'.$listingId ]);
    }
    // successfultransactions
    $succPayload = [[
      'transaction_id'=>$completedTxId,
      'listing_id'=>$listingId,
      'seller_id'=>$sellerIdIn,
      'buyer_id'=>$buyerIdIn,
      'price'=>$price,
      'payment_method'=>$payment,
      'status'=>'Successful',
      'transaction_date'=>date('c')
    ]];
    [$sr2,$ss2,$se2] = sb_rest('POST','successfultransactions',[], $succPayload, ['Prefer: return=representation']);
    if (!($ss2>=200 && $ss2<300)) $warnings[] = 'Failed to create successfultransactions record';
    // remove from active listings
    [$delA,$delAs,$delAe] = sb_rest('DELETE','activelivestocklisting',[ 'listing_id'=>'eq.'.$listingId ]);
    if (!($delAs>=200 && $delAs<300)) $warnings[] = 'Failed to remove active listing';
  } else {
    // failedtransactions
    $failPayload = [[
      'transaction_id'=>$completedTxId,
      'listing_id'=>$listingId,
      'seller_id'=>$sellerIdIn,
      'buyer_id'=>$buyerIdIn,
      'price'=>$price,
      'payment_method'=>$payment,
      'status'=>'Failed',
      'transaction_date'=>date('c')
    ]];
    [$fr,$fs,$fe] = sb_rest('POST','failedtransactions',[], $failPayload, ['Prefer: return=representation']);
    if (!($fs>=200 && $fs<300)) $warnings[] = 'Failed to create failedtransactions record';
  }

  // Documentary photo upload and storage image transfer are not implemented in this endpoint.
  // Return warnings to inform the caller.
  echo json_encode(['ok'=>true,'completed_transaction_id'=>$completedTxId,'warnings'=>$warnings]);
  exit;
}
function safe($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

if (isset($_GET['action']) && $_GET['action']==='list'){
  header('Content-Type: application/json');
  // Fetch from all three transaction tables for current user as SELLER only
  [$srows,$st,$err] = sb_rest('GET','starttransactions',[
    'select'=>'transaction_id,listing_id,seller_id,buyer_id,status,started_at,buyer:buyer(user_id,user_fname,user_mname,user_lname,email,contact,location),seller:seller(user_id,user_fname,user_mname,user_lname,email,contact,location),listing:activelivestocklisting(listing_id,livestock_type,breed,price,created,address)',
    'seller_id'=>'eq.'.$userId,
    'order'=>'started_at.desc'
  ]);
  if (!($st>=200 && $st<300) || !is_array($srows)) $srows = [];
  [$orows,$ost,$oerr] = sb_rest('GET','ongoingtransactions',[
    'select'=>'transaction_id,listing_id,seller_id,buyer_id,status,started_at,buyer:buyer(user_id,user_fname,user_mname,user_lname,email,contact,location),seller:seller(user_id,user_fname,user_mname,user_lname,email,contact,location),listing:activelivestocklisting(listing_id,livestock_type,breed,price,created,address)',
    'seller_id'=>'eq.'.$userId,
    'order'=>'started_at.desc'
  ]);
  if (!($ost>=200 && $ost<300) || !is_array($orows)) $orows = [];
  [$crows,$cst,$cerr] = sb_rest('GET','completedtransactions',[
    'select'=>'transaction_id,listing_id,seller_id,buyer_id,status,started_at,buyer:buyer(user_id,user_fname,user_mname,user_lname,email,contact,location),seller:seller(user_id,user_fname,user_mname,user_lname,email,contact,location),listing:activelivestocklisting(listing_id,livestock_type,breed,price,created,address)',
    'seller_id'=>'eq.'.$userId,
    'order'=>'started_at.desc'
  ]);
  if (!($cst>=200 && $cst<300) || !is_array($crows)) $crows = [];
  $rows = array_merge($srows, $orows, $crows);
  $out = [];
  foreach ($rows as $r){
    $sellerRow = $r['seller'] ?? [];
    $listing = $r['listing'] ?? [];
    $created = $listing['created'] ?? '';
    $digits = preg_replace('/\D+/', '', (string)$created);
    $createdKey = substr($digits, 0, 14);
    // Build seller folder from joined seller
    $sellerIdForListing = (int)($r['seller_id'] ?? 0);
    $sf = $sellerRow['user_fname'] ?? ''; $sm = $sellerRow['user_mname'] ?? ''; $sl = $sellerRow['user_lname'] ?? '';
    $full = trim($sf.' '.($sm?:'').' '.$sl);
    $san = strtolower(preg_replace('/[^a-z0-9]+/i','_', $full));
    $san = trim($san, '_'); if ($san==='') $san='user';
    $sellerNewFolder = $sellerIdForListing.'_'.$san;
    $legacyFolder = $sellerIdForListing.'_'.((int)($listing['listing_id'] ?? 0));
    $root = 'listings/verified';
    $thumb = ($createdKey !== '')
      ? ('../../bat/pages/storage_image.php?path='.$root.'/'.$sellerNewFolder.'/'.$createdKey.'_1img.jpg')
      : ('../../bat/pages/storage_image.php?path='.$root.'/'.$legacyFolder.'/image1');
    $thumb_fallback = '../../bat/pages/storage_image.php?path='.$root.'/'.$legacyFolder.'/image1';
    // Seller location for map
    $lat=null; $lng=null;
    if (!empty($sellerRow['location'])){
      $loc = json_decode($sellerRow['location'], true);
      if (is_array($loc)){ $lat = $loc['lat'] ?? null; $lng = $loc['lng'] ?? null; }
    }
    $r['thumb'] = $thumb; $r['thumb_fallback'] = $thumb_fallback; $r['lat']=$lat; $r['lng']=$lng;
    $out[] = $r;
  }
  echo json_encode(['ok'=>true,'count'=>count($out),'data'=>$out]);
  exit;
}
if (isset($_POST['action']) && $_POST['action']==='schedule_meetup'){
  header('Content-Type: application/json');
  $listingId = isset($_POST['listing_id']) ? (int)$_POST['listing_id'] : 0;
  $sellerIdIn = isset($_POST['seller_id']) ? (int)$_POST['seller_id'] : 0;
  $buyerIdIn = isset($_POST['buyer_id']) ? (int)$_POST['buyer_id'] : 0;
  $when = isset($_POST['transaction_date']) ? (string)$_POST['transaction_date'] : '';
  $loc  = isset($_POST['transaction_location']) ? (string)$_POST['transaction_location'] : '';
  if (!$listingId || !$sellerIdIn || !$buyerIdIn || $when===''){
    echo json_encode(['ok'=>false,'error'=>'missing_params']);
    exit;
  }
  $payload = [[
    'listing_id'=>$listingId,
    'seller_id'=>$sellerIdIn,
    'buyer_id'=>$buyerIdIn,
    'status'=>'Ongoing',
    'started_at'=>date('c'),
    'transaction_date'=>$when,
    'transaction_location'=>$loc
  ]];
  [$ores,$ost,$oerr] = sb_rest('POST','ongoingtransactions',[], $payload, ['Prefer: return=representation']);
  if (!($ost>=200 && $ost<300)){
    $detail = '';
    if (is_array($ores) && isset($ores['message'])) { $detail = $ores['message']; }
    elseif (is_string($ores) && $ores!=='') { $detail = $ores; }
    echo json_encode(['ok'=>false,'error'=>'ongoing_insert_failed','code'=>$ost,'detail'=>$detail]);
    exit;
  }
  // Update starttransactions status to 'Ongoing' for the matching row
  $upd = [ 'status'=>'Ongoing' ];
  [$ur,$us,$ue] = sb_rest('PATCH','starttransactions', [
    'listing_id'=>'eq.'.$listingId,
    'seller_id'=>'eq.'.$sellerIdIn,
    'buyer_id'=>'eq.'.$buyerIdIn
  ], [$upd]);
  $warning2 = null;
  if (!($us>=200 && $us<300)){
    $warning2 = 'Failed to update starttransactions to Ongoing';
  }
  // Delete from starttransactions to avoid duplicates in merged list
  [$dr,$ds,$de] = sb_rest('DELETE','starttransactions', [
    'listing_id'=>'eq.'.$listingId,
    'seller_id'=>'eq.'.$sellerIdIn,
    'buyer_id'=>'eq.'.$buyerIdIn
  ]);
  $warning3 = null;
  if (!($ds>=200 && $ds<300)){
    $warning3 = 'Failed to delete original starttransaction';
  }
  $logPayload = [[
    'listing_id'=>$listingId,
    'seller_id'=>$sellerIdIn,
    'buyer_id'=>$buyerIdIn,
    'status'=>'Meet-up scheduled by seller on '.$when.' at '.($loc?:'N/A')
  ]];
  [$lr,$ls,$le] = sb_rest('POST','transactions_logs',[], $logPayload, ['Prefer: return=representation']);
  $warning = null;
  if (!($ls>=200 && $ls<300)){
    $ldetail = '';
    if (is_array($lr) && isset($lr['message'])) { $ldetail = $lr['message']; }
    elseif (is_string($lr) && $lr!=='') { $ldetail = $lr; }
    $warning = 'Log insert failed (code '.(string)$ls.'). '.($ldetail?:'');
  }
  echo json_encode(['ok'=>true,'data'=>$ores[0] ?? null, 'warning'=>$warning, 'warning2'=>$warning2, 'warning3'=>$warning3]);
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
            <th>Buyer</th>
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

  <div id="completeModal" class="modal">
    <div class="panel">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;">
        <h2 style="margin:0;">Complete Transaction</h2>
        <button class="close-btn" data-close="completeModal">Close</button>
      </div>
      <div style="margin-top:8px;">
        <div style="display:grid;gap:10px;">
          <div>
            <label style="display:block;font-weight:600;margin-bottom:4px;">Result</label>
            <label style="margin-right:16px;"><input type="radio" name="txResult" value="successful" checked> Successful</label>
            <label><input type="radio" name="txResult" value="unsuccessful"> Unsuccessful</label>
          </div>
          <div>
            <label style="display:block;font-weight:600;margin-bottom:4px;">Final Price (₱)</label>
            <input type="number" id="txPrice" step="0.01" min="0" style="width:100%;padding:8px;border:1px solid #e2e8f0;border-radius:6px;" />
          </div>
          <div>
            <label style="display:block;font-weight:600;margin-bottom:4px;">Payment Method</label>
            <input type="text" id="txPayment" placeholder="e.g. Cash" style="width:100%;padding:8px;border:1px solid #e2e8f0;border-radius:6px;" />
          </div>
          <div>
            <label style="display:block;font-weight:600;margin-bottom:4px;">Documentary Photo (optional if unsuccessful)</label>
            <input type="file" id="txDoc" accept="image/*" />
          </div>
          <div>
            <button class="btn" id="btnSubmitComplete">Submit</button>
          </div>
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
            if (!res || res.ok===false){
              var tr = document.createElement('tr');
              var td = document.createElement('td'); td.colSpan = 6; td.style.color = '#7f1d1d';
              td.textContent = 'Failed to load transactions'+(res && res.code? (' (code '+res.code+')') : '');
              tr.appendChild(td); tb.appendChild(tr); return;
            }
            if (!res.data || res.data.length===0){
              var tr = document.createElement('tr'); var td = document.createElement('td'); td.colSpan = 6; td.textContent = 'No transactions found.'; tr.appendChild(td); tb.appendChild(tr); return;
            }
            (res.data||[]).forEach(function(row){
              var buyer = row.buyer||{}; var seller = row.seller||{}; var listing = row.listing||{};
              var role = (row.seller_id == <?php echo (int)$userId; ?>) ? 'Seller' : 'Buyer';
              var counterparty = role==='Seller' ? (function(p){var f=p.user_fname||'',m=p.user_mname||'',l=p.user_lname||'';return (f+' '+(m?m+' ':'')+l).trim();})(buyer) : (function(p){var f=p.user_fname||'',m=p.user_mname||'',l=p.user_lname||'';return (f+' '+(m?m+' ':'')+l).trim();})(seller);
              var tr = document.createElement('tr');
              tr.innerHTML = '<td>'+ (row.transaction_id||'') +'</td>'+
                '<td>'+ counterparty +'</td>'+
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
          var buyer = data.buyer||{}; var seller = data.seller||{}; var listing = data.listing||{};
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
            '<div style="margin-top:10px;">'+
              '<div id="txMap" style="height:200px;border:1px solid #e2e8f0;border-radius:8px"></div>'+
            '</div>'+
            '<hr style="margin:12px 0;border:none;border-top:1px solid #e2e8f0" />'+
            '<h3>Seller</h3>'+
            '<div style="display:flex;gap:10px;align-items:flex-start;">'+ avatarHTML(seller) +
              '<div><div><strong>'+fullname(seller)+'</strong></div><div>Email: '+(seller.email||'')+'</div><div>Contact: '+(seller.contact||'')+'</div></div>'+
            '</div>'+
            '<h3 style="margin-top:10px;">Buyer</h3>'+
            '<div style="display:flex;gap:10px;align-items:flex-start;">'+ avatarHTML(buyer) +
              '<div><div><strong>'+fullname(buyer)+'</strong></div><div>Email: '+(buyer.email||'')+'</div><div>Contact: '+(buyer.contact||'')+'</div></div>'+
            '</div>'+
            '<div style="margin-top:14px;color:#4a5568;">Note: Meet-up date and location will be set by BAT.</div>'+ 
            '<div style="margin-top:8px;"><button class="btn" id="btnDone">Done</button></div>';
          var wrap = document.getElementById('imgWrap'); if (wrap) wrap.appendChild(img);
          // Map (read-only)
          setTimeout(function(){
            var mEl = document.getElementById('txMap'); if (!mEl || !window.L) return;
            var map = L.map(mEl).setView([8.314209 , 124.859425], 12);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(map);
            var marker = null;
            if (data.lat!=null && data.lng!=null){
              map.setView([data.lat, data.lng], 12);
              marker = L.marker([data.lat, data.lng]).addTo(map);
            }
          }, 0);
          openModal('txModal');
          var doneBtn = document.getElementById('btnDone');
          if (doneBtn){
            doneBtn.addEventListener('click', function(){
              var cm = document.getElementById('completeModal');
              cm.setAttribute('data-tx', encodeURIComponent(JSON.stringify(data)));
              openModal('completeModal');
            });
          }
        }
      });

      ['txModal','completeModal'].forEach(function(id){ var el=document.getElementById(id); if(el){ el.addEventListener('click', function(ev){ if(ev.target===el) closeModal(id); }); }});

      var btnSubmitComplete = document.getElementById('btnSubmitComplete');
      if (btnSubmitComplete){
        btnSubmitComplete.addEventListener('click', function(){
          var cm = document.getElementById('completeModal');
          var raw = cm.getAttribute('data-tx')||'';
          var tx = {};
          try{ tx = JSON.parse(decodeURIComponent(raw)); }catch(e){}
          var result = (document.querySelector('input[name="txResult"]:checked')||{}).value||'successful';
          var price = (document.getElementById('txPrice')||{}).value||'';
          var paym  = (document.getElementById('txPayment')||{}).value||'';
          var file  = (document.getElementById('txDoc')||{}).files ? (document.getElementById('txDoc').files[0]||null) : null;
          if (!price){ alert('Please enter final price'); return; }
          if (!paym){ alert('Please enter payment method'); return; }
          var fd = new FormData();
          fd.append('action','complete_transaction');
          fd.append('transaction_id', tx.transaction_id||'');
          fd.append('listing_id', tx.listing_id||'');
          fd.append('seller_id', tx.seller_id||'');
          fd.append('buyer_id', tx.buyer_id||'');
          fd.append('result', result);
          fd.append('price', price);
          fd.append('payment_method', paym);
          if (file) fd.append('doc_photo', file);
          btnSubmitComplete.disabled = true; btnSubmitComplete.textContent = 'Submitting...';
          fetch('transactions.php', { method:'POST', body: fd, credentials:'same-origin' })
            .then(function(r){ return r.json(); })
            .then(function(res){
              if (!res || res.ok===false){
                alert('Failed to complete transaction'+(res && res.code? (' (code '+res.code+')') : ''));
                btnSubmitComplete.disabled = false; btnSubmitComplete.textContent = 'Submit';
              } else {
                alert('Transaction completion saved.');
                btnSubmitComplete.disabled = true; btnSubmitComplete.textContent = 'Saved';
                closeModal('completeModal');
                closeModal('txModal');
                location.reload();
              }
            })
            .catch(function(){ btnSubmitComplete.disabled = false; btnSubmitComplete.textContent = 'Submit'; });
        });
      }
    })();
  </script>
</body>
</html>
