<?php
session_start();
require_once __DIR__ . '/../../../authentication/lib/supabase_client.php';
require_once __DIR__ . '/../../../common/notify.php';

$role = $_SESSION['role'] ?? '';
if ($role !== 'bat'){
  header('Location: ../dashboard.php');
  exit;
}
$batId = (int)($_SESSION['user_id'] ?? 0);

function esc($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// Inline action: details for modal (listing + seller + buyer)
if (isset($_GET['action']) && $_GET['action']==='details'){
  header('Content-Type: application/json');
  $listingId = isset($_GET['listing_id']) ? (int)$_GET['listing_id'] : 0;
  $sellerId  = isset($_GET['seller_id']) ? (int)$_GET['seller_id'] : 0;
  $buyerId   = isset($_GET['buyer_id']) ? (int)$_GET['buyer_id'] : 0;
  if (!$listingId || !$sellerId || !$buyerId){ echo json_encode(['ok'=>false,'error'=>'missing_params']); exit; }
  // Try active first
  [$lr,$ls,$le] = sb_rest('GET','activelivestocklisting',[ 'select'=>'listing_id,livestock_type,breed,price,created,address,seller_id', 'listing_id'=>'eq.'.$listingId ]);
  $listing = (is_array($lr) && count($lr)>0) ? $lr[0] : null;
  if (!$listing){
    // Try sold listings in activelivestocklisting
    [$lr2,$ls2,$le2] = sb_rest('GET','activelivestocklisting',[ 'select'=>'listing_id,livestock_type,breed,price,created,address,seller_id', 'listing_id'=>'eq.'.$listingId, 'status'=>'eq.Sold' ]);
    $listing = (is_array($lr2) && count($lr2)>0) ? $lr2[0] : null;
  }
  // Seller profile
  [$sr,$ss,$se] = sb_rest('GET','seller',[ 'select'=>'user_id,user_fname,user_mname,user_lname,email,contact', 'user_id'=>'eq.'.$sellerId ]);
  $seller = (is_array($sr) && count($sr)>0) ? $sr[0] : [];
  // Buyer profile
  [$br,$bs,$be] = sb_rest('GET','buyer',[ 'select'=>'user_id,user_fname,user_mname,user_lname,email,contact', 'user_id'=>'eq.'.$buyerId ]);
  $buyer = (is_array($br) && count($br)>0) ? $br[0] : [];
  // Thumbnail using market image path logic
  $sellerName = trim(($seller['user_fname']??'').'_'.($seller['user_lname']??''));
  $newFolder = $sellerId.'_'.$sellerName;
  $created = $listing['created'] ?? '';
  $createdKey = $created ? date('YmdHis', strtotime($created)) : '';
  
  // Determine status folder based on listing status
  $status = strtolower($listing['status'] ?? 'active');
  $statusFolder = 'active'; // default
  if ($status === 'verified') $statusFolder = 'verified';
  elseif ($status === 'sold') $statusFolder = 'sold';
  elseif ($status === 'underreview') $statusFolder = 'underreview';
  elseif ($status === 'denied') $statusFolder = 'denied';
  
  $thumb = ($createdKey!=='' ? '../../bat/pages/storage_image.php?path=listings/'.$statusFolder.'/'.$newFolder.'/'.$createdKey.'_1img.jpg' : '../../bat/pages/storage_image.php?path=listings/'.$statusFolder.'/'.$sellerId.'_'.$listingId.'/image1');
  $thumb_fallback = '../../bat/pages/storage_image.php?path=listings/'.$statusFolder.'/'.$sellerId.'_'.$listingId.'/image1';
  echo json_encode(['ok'=>true,'listing'=>$listing, 'seller'=>$seller, 'buyer'=>$buyer, 'thumb'=>$thumb, 'thumb_fallback'=>$thumb_fallback]);
  exit;
}

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
  
  // Fetch listing details for better title/description
  [$listingRows,$listingStatus,$listingError] = sb_rest('GET','activelivestocklisting',[
    'select'=>'livestock_type,breed,price,address',
    'listing_id'=>'eq.'.$listingId,
    'limit'=>1
  ]);
  $listing = (is_array($listingRows) && count($listingRows)>0) ? $listingRows[0] : null;
  
  // Fetch seller and buyer names for better description
  [$sellerRows,$sellerStatus,$sellerError] = sb_rest('GET','seller',[
    'select'=>'user_fname,user_lname',
    'user_id'=>'eq.'.$sellerId,
    'limit'=>1
  ]);
  $seller = (is_array($sellerRows) && count($sellerRows)>0) ? $sellerRows[0] : null;
  
  [$buyerRows,$buyerStatus,$buyerError] = sb_rest('GET','buyer',[
    'select'=>'user_fname,user_lname',
    'user_id'=>'eq.'.$buyerId,
    'limit'=>1
  ]);
  $buyer = (is_array($buyerRows) && count($buyerRows)>0) ? $buyerRows[0] : null;
  
  // Build enhanced title and description
  $livestockInfo = '';
  if ($listing) {
    $livestockInfo = ($listing['livestock_type']||'') . ' • ' . ($listing['breed']||'');
    if ($listing['price']) $livestockInfo .= ' • ₱' . $listing['price'];
  }
  
  $title = 'Meet-up: ' . ($livestockInfo ? $livestockInfo : 'Listing #' . $listingId);
  
  $sellerName = ($seller && $seller['user_fname']) ? trim($seller['user_fname'] . ' ' . ($seller['user_lname']||'')) : 'Seller #' . $sellerId;
  $buyerName = ($buyer && $buyer['user_fname']) ? trim($buyer['user_fname'] . ' ' . ($buyer['user_lname']||'')) : 'Buyer #' . $buyerId;
  $address = ($listing && $listing['address']) ? $listing['address'] : 'Address not specified';
  
  $desc = 'Transaction: ' . $sellerName . ' × ' . $buyerName . 
          ' | Listing #' . $listingId . 
          ' | Meet-up at: ' . $loc . 
          ' | Original Address: ' . $address;
  
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
  $warnings = [];
  if (!($ss>=200 && $ss<300)) $warnings[] = 'Failed to create schedule entry';
  // Notify seller about scheduled meet-up
  $title = 'Meet-up Scheduled';
  $msg = 'BAT scheduled: '.($whenVal ?: '').' at '.($loc ?: '');
  notify_send((int)$sellerId, 'seller', $title, $msg, (int)$listingId, 'meetup');
  echo json_encode(['ok'=>true,'warning'=> (count($warnings)? implode('; ', $warnings) : null)]); exit;
}

// Inline action: complete an ongoing transaction (BAT)
if (isset($_POST['action']) && $_POST['action']==='complete_transaction'){
  header('Content-Type: application/json');
  $txId = isset($_POST['transaction_id']) ? (int)$_POST['transaction_id'] : 0;
  $listingId = isset($_POST['listing_id']) ? (int)$_POST['listing_id'] : 0;
  $sellerId = isset($_POST['seller_id']) ? (int)$_POST['seller_id'] : 0;
  $buyerId = isset($_POST['buyer_id']) ? (int)$_POST['buyer_id'] : 0;
  $result = isset($_POST['result']) ? (string)$_POST['result'] : 'successful';
  $price = isset($_POST['price']) ? (float)$_POST['price'] : 0;
  $paymentMethod = isset($_POST['payment_method']) ? (string)$_POST['payment_method'] : '';
  if (!$txId || !$listingId || !$sellerId || !$buyerId){ echo json_encode(['ok'=>false,'error'=>'missing_params']); exit; }
  if ($result==='successful' && ($price<=0 || $paymentMethod==='')){ echo json_encode(['ok'=>false,'error'=>'missing_payment']); exit; }
  // Fetch the ongoing transaction for details
  [$txRows,$txStatus,$txError] = sb_rest('GET','ongoingtransactions',[
    'select'=>'*', 'transaction_id'=>'eq.'.$txId, 'listing_id'=>'eq.'.$listingId, 'seller_id'=>'eq.'.$sellerId, 'buyer_id'=>'eq.'.$buyerId, 'limit'=>1
  ]);
  if (!($txStatus>=200 && $txStatus<300) || !is_array($txRows) || empty($txRows)){
    echo json_encode(['ok'=>false,'error'=>'transaction_not_found']); exit;
  }
  $tx = $txRows[0];
  $now = date('Y-m-d H:i:s');
  // Insert into completedtransactions
  [$compRes,$compSt,$compErr] = sb_rest('POST','completedtransactions',[],[[
    'transaction_id'=>$txId,
    'listing_id'=>$listingId,
    'seller_id'=>$sellerId,
    'buyer_id'=>$buyerId,
    'status'=>'Completed',
    'started_at'=>$tx['started_at'] ?? null,
    'bat_id'=>$batId,
    'transaction_date'=>$tx['transaction_date'] ?? $now,
    'transaction_location'=>$tx['transaction_location'] ?? null,
    'completed_transaction'=>$now
  ]]);
  if (!($compSt>=200 && $compSt<300)){
    echo json_encode(['ok'=>false,'error'=>'complete_insert_failed','code'=>$compSt]); exit;
  }
  // Log into transactions_logs
  sb_rest('POST','transactions_logs',[],[[
    'transaction_id'=>$txId,
    'listing_id'=>$listingId,
    'seller_id'=>$sellerId,
    'buyer_id'=>$buyerId,
    'status'=>'Completed',
    'started_at'=>$tx['started_at'] ?? null,
    'bat_id'=>$batId,
    'transaction_date'=>$now,
    'transaction_location'=>$tx['transaction_location'] ?? null,
    'completed_transaction'=>$now
  ]], ['Prefer: return=minimal']);
  // Result-specific insert
  if (strtolower($result)==='successful'){
    sb_rest('POST','successfultransactions',[],[[
      'transaction_id'=>$txId,
      'listing_id'=>$listingId,
      'seller_id'=>$sellerId,
      'buyer_id'=>$buyerId,
      'price'=>$price,
      'payment_method'=>$paymentMethod,
      'status'=>'Successful',
      'transaction_date'=>$now
    ]], ['Prefer: return=minimal']);
  } else {
    sb_rest('POST','failedtransactions',[],[[
      'transaction_id'=>$txId,
      'listing_id'=>$listingId,
      'seller_id'=>$sellerId,
      'buyer_id'=>$buyerId,
      'price'=>($price>0?$price:0),
      'payment_method'=>($paymentMethod!==''?$paymentMethod:null),
      'status'=>'Failed',
      'transaction_date'=>$now
    ]], ['Prefer: return=minimal']);
  }
  // Remove from ongoingtransactions
  sb_rest('DELETE','ongoingtransactions',[ 'transaction_id'=>'eq.'.$txId ], null, ['Prefer: return=minimal']);
  echo json_encode(['ok'=>true]); exit;
}

function fetch_table($table, $select, $order){
  [$rows,$st,$err] = sb_rest('GET', $table, [ 'select'=>$select, 'order'=>$order ]);
  return [
    'ok' => ($st>=200 && $st<300) && is_array($rows),
    'code' => $st,
    'err' => is_string($err)? $err : '',
    'rows' => (is_array($rows)? $rows : [])
  ];
}

$start = fetch_table(
  'starttransactions',
  'transaction_id,listing_id,seller_id,buyer_id,status,started_at,'.
  'seller:seller(user_id,user_fname,user_mname,user_lname),' .
  'buyer:buyer(user_id,user_fname,user_mname,user_lname)',
  'started_at.desc'
);
$ongo  = fetch_table(
  'ongoingtransactions',
  'transaction_id,listing_id,seller_id,buyer_id,status,started_at,transaction_date,transaction_location,bat_id,' .
  'bat:bat(user_id,user_fname,user_mname,user_lname),' .
  'seller:seller(user_id,user_fname,user_mname,user_lname),' .
  'buyer:buyer(user_id,user_fname,user_mname,user_lname)',
  'started_at.desc'
);
// First, get completed transactions
$done = fetch_table(
  'completedtransactions',
  'transaction_id,listing_id,seller_id,buyer_id,status,started_at,transaction_date,transaction_location,completed_transaction,bat_id,' .
  'bat:bat(user_id,user_fname,user_mname,user_lname),' .
  'seller:seller(user_id,user_fname,user_mname,user_lname),' .
  'buyer:buyer(user_id,user_fname,user_mname,user_lname),' .
  'successfultransactions(price,payment_method)',
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
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
  <link rel="stylesheet" href="../style/dashboard.css">
  <style>
    .section{margin-bottom:16px}
    .table{width:100%;border-collapse:collapse}
    .table th,.table td{padding:8px;text-align:left}
    .table thead tr{border-bottom:1px solid #e2e8f0}
    @media (max-width:640px){
      /* Smaller base font for entire page */
      body{font-size:12px}
      .wrap{font-size:12px}
      h1{font-size:18px}
      h2{font-size:14px}
      .section{margin-bottom:8px}
      .table{table-layout:fixed;font-size:11px;word-wrap:break-word}
      .table th,.table td{padding:4px}
      .top .btn{padding:5px 8px;font-size:11px}
      .panel{max-width:95vw}
      /* Modal content compaction */
      #txBody img{width:88px !important;height:88px !important}
      #txBody input[type="text"]{width:160px !important;max-width:55vw}
      #txBody input[type="datetime-local"]{font-size:12px;padding:3px 6px}
      #txMap{height:200px !important}
      .panel h2{font-size:14px}
      .btn{padding:5px 8px;font-size:11px}
    }
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
      <?php if (!$start['ok']): ?>
        <div style="margin:6px 0;padding:8px;border:1px solid #fecaca;background:#fef2f2;color:#7f1d1d;border-radius:8px;">Failed to load started transactions (code <?php echo (int)$start['code']; ?>)</div>
      <?php endif; ?>
      <table class="table">
        <thead>
          <tr>
            <th>Seller</th>
            <th>Buyer</th>
            <th>Status</th>
            <th>Timestamp</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php $startRows = $start['rows'] ?? []; ?>
          <?php if (count($startRows)===0): ?>
            <tr><td colspan="7" style="color:#4a5568;">No started transactions.</td></tr>
          <?php else: foreach ($startRows as $r): 
            $s = $r['seller'] ?? null;
            $b = $r['buyer'] ?? null;
            $sellerName = $s ? trim(($s['user_fname']??'').' '.(($s['user_mname']??'')?($s['user_mname'].' '):'').($s['user_lname']??'')) : ($r['seller_id'] ?? '');
            $buyerName  = $b ? trim(($b['user_fname']??'').' '.(($b['user_mname']??'')?($b['user_mname'].' '):'').($b['user_lname']??'')) : ($r['buyer_id'] ?? '');
          ?>
            <tr>
              <td><?php echo esc($sellerName); ?></td>
              <td><?php echo esc($buyerName); ?></td>
              <td><?php echo esc($r['status'] ?? ''); ?></td>
              <td><?php echo esc(($r['started_at'] ?? '') ? date('H/i d/m/Y', strtotime($r['started_at'])) : ''); ?></td>
              <td><button class="btn btn-show" data-row="<?php echo esc(json_encode($r)); ?>">Show</button></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <div class="card section">
      <h2 style="margin:0 0 8px 0;">Ongoing</h2>
      <?php if (!$ongo['ok']): ?>
        <div style="margin:6px 0;padding:8px;border:1px solid #fecaca;background:#fef2f2;color:#7f1d1d;border-radius:8px;">Failed to load ongoing transactions (code <?php echo (int)$ongo['code']; ?>)</div>
      <?php endif; ?>
      <table class="table">
        <thead>
          <tr>
            <th>Seller</th>
            <th>Buyer</th>
            <th>Status</th>
            <th>Timestamp</th>
            <th>BAT Name</th>
            <th>Meet-up Date</th>
            <th>Location</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php $ongoRows = $ongo['rows'] ?? []; ?>
          <?php if (count($ongoRows)===0): ?>
            <tr><td colspan="10" style="color:#4a5568;">No ongoing transactions.</td></tr>
          <?php else: foreach ($ongoRows as $r): 
            $loc = $r['transaction_location'] ?? ($r['Transaction_location'] ?? '');
            $s = $r['seller'] ?? null;
            $b = $r['buyer'] ?? null;
            $batRow = $r['bat'] ?? null;
            $sellerName = $s ? trim(($s['user_fname']??'').' '.(($s['user_mname']??'')?($s['user_mname'].' '):'').($s['user_lname']??'')) : ($r['seller_id'] ?? '');
            $buyerName  = $b ? trim(($b['user_fname']??'').' '.(($b['user_mname']??'')?($b['user_mname'].' '):'').($b['user_lname']??'')) : ($r['buyer_id'] ?? '');
            $batName    = $batRow ? trim(($batRow['user_fname']??'').' '.(($batRow['user_mname']??'')?($batRow['user_mname'].' '):'').($batRow['user_lname']??'')) : ($r['bat_id'] ?? $r['Bat_id'] ?? '');
          ?>
            <tr>
              <td><?php echo esc($sellerName); ?></td>
              <td><?php echo esc($buyerName); ?></td>
              <td><?php echo esc($r['status'] ?? ''); ?></td>
              <td><?php echo esc(($r['started_at'] ?? '') ? date('H/i d/m/Y', strtotime($r['started_at'])) : ''); ?></td>
              <td><?php echo esc($batName); ?></td>
              <td><?php echo esc(($r['transaction_date'] ?? '') ? date('H/i d/m/Y', strtotime($r['transaction_date'])) : ''); ?></td>
              <td><?php echo esc($loc); ?></td>
              <td><button class="btn btn-show" data-row="<?php echo esc(json_encode($r)); ?>">Show</button></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <div class="card section">
      <h2 style="margin:0 0 8px 0;">Completed</h2>
      <?php if (!$done['ok']): ?>
        <div style="margin:6px 0;padding:8px;border:1px solid #fecaca;background:#fef2f2;color:#7f1d1d;border-radius:8px;">Failed to load completed transactions (code <?php echo (int)$done['code']; ?>)</div>
      <?php endif; ?>
      <table class="table">
        <thead>
          <tr>
            <th>Seller</th>
            <th>Buyer</th>
            <th>Status</th>
            <th>Timestamp</th>
            <th>BAT Name</th>
            <th>Meet-up Date</th>
            <th>Location</th>
            <th>Completed</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php $doneRows = $done['rows'] ?? []; ?>
          <?php if (count($doneRows)===0): ?>
            <tr><td colspan="11" style="color:#4a5568;">No completed transactions.</td></tr>
          <?php else: foreach ($doneRows as $r): 
            $s = $r['seller'] ?? null;
            $b = $r['buyer'] ?? null;
            $batRow = $r['bat'] ?? null;
            $sellerName = $s ? trim(($s['user_fname']??'').' '.(($s['user_mname']??'')?($s['user_mname'].' '):'').($s['user_lname']??'')) : ($r['seller_id'] ?? '');
            $buyerName  = $b ? trim(($b['user_fname']??'').' '.(($b['user_mname']??'')?($b['user_mname'].' '):'').($b['user_lname']??'')) : ($r['buyer_id'] ?? '');
            $batName    = $batRow ? trim(($batRow['user_fname']??'').' '.(($batRow['user_mname']??'')?($batRow['user_mname'].' '):'').($batRow['user_lname']??'')) : ($r['bat_id'] ?? '');
          ?>
            <tr>
              <td><?php echo esc($sellerName); ?></td>
              <td><?php echo esc($buyerName); ?></td>
              <td><?php echo esc($r['status'] ?? ''); ?></td>
              <td><?php echo esc(($r['started_at'] ?? '') ? date('H/i d/m/Y', strtotime($r['started_at'])) : ''); ?></td>
              <td><?php echo esc($batName); ?></td>
              <td><?php echo esc(($r['transaction_date'] ?? '') ? date('H/i d/m/Y', strtotime($r['transaction_date'])) : ''); ?></td>
              <td><?php echo esc($r['transaction_location'] ?? ''); ?></td>
              <td><?php echo esc(($r['completed_transaction'] ?? '') ? date('H/i d/m/Y', strtotime($r['completed_transaction'])) : ''); ?></td>
              <td><button class="btn btn-show" data-row="<?php echo esc(json_encode($r)); ?>">Show</button></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

  </div>

  <!-- Transaction Details Modal -->
  <div id="txModal" class="modal" style="display:none;align-items:center;justify-content:center;">
    <div class="panel" style="max-width:820px;width:100%">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;">
        <h2 id="txTitle" style="margin:0;">Transaction</h2>
        <button class="close-btn" data-close="txModal">Close</button>
      </div>
      <div id="txBody" style="margin-top:8px;"></div>
    </div>
  </div>

  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
  <script>
    (function(){
      function $(s){ return document.querySelector(s); }
      function $all(s){ return Array.prototype.slice.call(document.querySelectorAll(s)); }
      function openModal(id){ var el=document.getElementById(id); if(el) el.style.display='flex'; }
      function closeModal(id){ var el=document.getElementById(id); if(el) el.style.display='none'; }
      var currentTxMap = null;
      var currentTxMarker = null;
      function destroyTxMap(){ try{ if (currentTxMap){ currentTxMap.remove(); } }catch(e){} currentTxMap=null; currentTxMarker=null; }
      $all('.close-btn').forEach(function(b){ b.addEventListener('click', function(){ destroyTxMap(); var body=document.getElementById('txBody'); if(body) body.innerHTML=''; closeModal(b.getAttribute('data-close')); }); });
      document.addEventListener('click', function(e){
        if (e.target && e.target.classList.contains('btn-show')){
          var data = {};
          try{ data = JSON.parse(e.target.getAttribute('data-row')||'{}'); }catch(_){ data={}; }
          var isOngoing = !!(data && (data.bat_id || data.Bat_id || data.transaction_date || data.Transaction_date));
          var isCompleted = !!(data && (data.completed_transaction || data.completed_Transaction));
          document.getElementById('txTitle').textContent = (isCompleted? 'Completed' : (isOngoing? 'Ongoing' : 'Started')) + ' Transaction #'+(data.transaction_id||'');
          var txBody = document.getElementById('txBody');
          var locVal = (data.transaction_location || data.Transaction_location || '').toString().trim();
          var whenVal = data.transaction_date || data.Transaction_date || '';

          // Fetch details for listing + seller/buyer
          var detailUrl = 'transaction_monitoring.php?action=details&listing_id='+(data.listing_id||'')+'&seller_id='+(data.seller_id||'')+'&buyer_id='+(data.buyer_id||'');
          fetch(detailUrl, { credentials:'same-origin' })
            .then(function(r){ return r.json(); })
            .then(function(info){
              var listing = info && info.listing ? info.listing : {};
              var seller = info && info.seller ? info.seller : {};
              var buyer  = info && info.buyer  ? info.buyer  : {};
              var thumb  = info && info.thumb  ? info.thumb  : '';
              var thumb_fallback = info && info.thumb_fallback ? info.thumb_fallback : '';
              function fullname(p){ var f=p.user_fname||'', m=p.user_mname||'', l=p.user_lname||''; return (f+' '+(m?m+' ':'')+l).trim(); }
              // Check if this is a successful transaction
              var successTx = data.successfultransactions && data.successfultransactions[0];
              var priceHtml = 'Price: ₱' + (listing.price || '0.00');
              var finalPriceHtml = '';
              
              if (successTx && successTx.price > 0) {
                priceHtml = 'List Price: ₱' + (listing.price || '0.00');
                finalPriceHtml = '<div style="font-weight:600;color:#10b981;margin-top:4px;">Final Price: ₱' + 
                                parseFloat(successTx.price).toFixed(2) + 
                                (successTx.payment_method ? ' (' + successTx.payment_method.charAt(0).toUpperCase() + 
                                successTx.payment_method.slice(1) + ')' : '') + '</div>';
              }

              var bodyHtml = ''+
                '<div class="card" style="padding:12px;">'+
                  '<div style="display:flex;gap:12px;align-items:flex-start;">'+
                    '<img src="'+thumb+'" onerror="if(this.src!=\''+thumb_fallback+'\')this.src=\''+thumb_fallback+'\'; else this.style.display=\'none\';" style="width:120px;height:120px;object-fit:cover;border:1px solid #e2e8f0;border-radius:8px;" />'+
                    '<div style="flex:1;">'+
                      '<div style="font-weight:600;margin-bottom:6px;">'+(listing.livestock_type||'')+' • '+(listing.breed||'')+'</div>'+
                      '<div>'+priceHtml+'</div>'+
                      finalPriceHtml +
                      '<div style="margin-top:4px;">Address: '+(listing.address||'')+'</div>'+
                      '<div class="subtle" style="margin-top:4px;">Listing #'+(listing.listing_id||'')+' • '+(listing.created||'')+'</div>'+
                    '</div>'+
                  '</div>'+
                  '<hr style="margin:12px 0;border:none;border-top:1px solid #e2e8f0" />'+
                  '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">'+
                    '<div><div style="font-weight:600;">Seller</div><div>'+fullname(seller)+'</div><div>Email: '+(seller.email||'')+'</div><div>Contact: '+(seller.contact||'')+'</div></div>'+
                    '<div><div style="font-weight:600;">Buyer</div><div>'+fullname(buyer)+'</div><div>Email: '+(buyer.email||'')+'</div><div>Contact: '+(buyer.contact||'')+'</div></div>'+
                  '</div>'+
                '</div>'+
                (isCompleted ? (
                  '<div class="card" style="padding:12px;margin-top:10px;">'+
                    '<div style="display:grid;grid-template-columns:1fr;gap:8px;margin-bottom:8px;">'+
                      '<div><strong>Date & Time:</strong> '+(whenVal||'')+'</div>'+
                      '<div><strong>Location:</strong> '+(locVal||'')+'</div>'+
                    '</div>'+
                    '<div id="txMap" style="height:260px;border:1px solid #e2e8f0;border-radius:8px;margin-bottom:12px;"></div>'+
                    (successTx && successTx.price > 0 ? 
                      '<div style="margin-top:16px;padding-top:16px;border-top:1px solid #e2e8f0;">'+
                        '<h3 style="margin:0 0 12px 0;font-size:16px;color:#2d3748;">Completed Transaction Information</h3>'+
                        '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;background:#f7fafc;padding:12px;border-radius:8px;border:1px solid #e2e8f0;">'+
                          '<div>'+
                            '<div style="font-size:13px;color:#718096;margin-bottom:4px;">List Price</div>'+
                            '<div style="font-size:15px;font-weight:500;">₱'+(listing.price ? parseFloat(listing.price).toFixed(2) : '0.00')+'</div>'+
                          '</div>'+
                          '<div>'+
                            '<div style="font-size:13px;color:#718096;margin-bottom:4px;">Final Price</div>'+
                            '<div style="font-size:15px;font-weight:600;color:#10b981;">₱'+parseFloat(successTx.price).toFixed(2)+'</div>'+
                          '</div>'+
                          '<div>'+
                            '<div style="font-size:13px;color:#718096;margin-bottom:4px;">Payment Method</div>'+
                            '<div style="font-size:15px;font-weight:500;">'+(successTx.payment_method ? successTx.payment_method.charAt(0).toUpperCase() + successTx.payment_method.slice(1) : 'N/A')+'</div>'+
                          '</div>'+
                          '<div>'+
                            '<div style="font-size:13px;color:#718096;margin-bottom:4px;">Status</div>'+
                            '<div style="display:inline-block;background:#e6f7ee;color:#10b981;padding:2px 8px;border-radius:12px;font-size:12px;font-weight:500;">Completed Successfully</div>'+
                          '</div>'+
                        '</div>'+
                      '</div>' : '')+
                  '</div>'
                ) : (
                  '<div class="card" style="padding:12px;margin-top:10px;">'+
                    '<div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap;margin-bottom:8px;">'+
                      '<div><strong>Date & Time:</strong> <input type="datetime-local" id="txDateTime" value="'+(whenVal||'')+'" style="margin-left:8px;padding:4px 8px;border:1px solid #e2e8f0;border-radius:4px;" /></div>'+
                      '<div><strong>Location:</strong> <input type="text" id="txLocation" value="'+(locVal||'')+'" placeholder="lat,lng (e.g., 8.314209,124.859425)" style="margin-left:8px;padding:4px 8px;border:1px solid #e2e8f0;border-radius:4px;width:300px;" /></div>'+
                    '</div>'+
                    '<div style="margin-bottom:8px;color:#4a5568;font-size:12px;">💡 Click anywhere on the map to set the meet-up location</div>'+
                    '<div id="txMap" style="height:260px;border:1px solid #e2e8f0;border-radius:8px;cursor:crosshair;" title="Click anywhere on the map to set meet-up location"></div>'+
                    '<div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap;">'+
                      '<button class="btn" id="btnSaveTx">Save Meet-up Details</button>'+
                      '<button class="btn" id="btnCompleteTx" style="background:#10b981;color:#fff;">Complete Transaction</button>'+
                      '<span id="saveStatus" style="color:#4a5568;font-size:12px;"></span>'+
                    '</div>'+
                  '</div>'
                ));
              txBody.innerHTML = bodyHtml;
              // Open modal first so map can compute size
              openModal('txModal');
              // Initialize map after modal is visible
              setTimeout(function(){
                if (!window.L){ return; }
                var mEl = document.getElementById('txMap'); if (!mEl) return;
                destroyTxMap();
                if (isCompleted){
                  currentTxMap = L.map(mEl, {
                    zoomControl: false,
                    attributionControl: false,
                    dragging: false,
                    scrollWheelZoom: false,
                    doubleClickZoom: false,
                    boxZoom: false,
                    keyboard: false,
                    tap: false,
                  }).setView([8.314209 , 124.859425], 12);
                } else {
                  currentTxMap = L.map(mEl).setView([8.314209 , 124.859425], 12);
                }
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(currentTxMap);
                currentTxMap.on('tileload', function(){ try{ currentTxMap.invalidateSize(); }catch(e){} });

                if (!isCompleted){
                  // Add click handler to set location
                  currentTxMap.on('click', function(e) {
                    var lat = e.latlng.lat.toFixed(6);
                    var lng = e.latlng.lng.toFixed(6);
                    var locationInput = document.getElementById('txLocation');
                    if (locationInput) {
                      locationInput.value = lat + ',' + lng;
                      if (currentTxMarker) {
                        currentTxMarker.setLatLng([lat, lng]);
                      } else {
                        currentTxMarker = L.marker([lat, lng]).addTo(currentTxMap);
                      }
                      currentTxMap.setView([lat, lng], 15);
                    }
                  });
                }

                if (locVal && locVal.indexOf(',')>0){
                  var parts = locVal.split(',');
                  var la = parseFloat((parts[0]||'').trim()); var ln = parseFloat((parts[1]||'').trim());
                  if (!isNaN(la) && !isNaN(ln)){
                    var ll=[la,ln]; currentTxMarker = L.marker(ll).addTo(currentTxMap); try{ currentTxMap.setView(ll,14);}catch(_){ }
                  }
                }
                setTimeout(function(){ try{ currentTxMap.invalidateSize(); }catch(e){} }, 50);
              }, 50);
              
              // Add save button handler only for non-completed transactions
              var saveBtn = document.getElementById('btnSaveTx');
              var saveStatus = document.getElementById('saveStatus');
              if (!isCompleted && saveBtn && saveStatus) {
                saveBtn.addEventListener('click', function() {
                  var dateTime = document.getElementById('txDateTime').value;
                  var location = document.getElementById('txLocation').value;
                  if (!dateTime || !location) {
                    saveStatus.textContent = 'Please fill in both date/time and location';
                    saveStatus.style.color = '#e53e3e';
                    return;
                  }
                  var fd = new FormData();
                  fd.append('action', 'set_schedule');
                  fd.append('transaction_id', data.transaction_id || '');
                  fd.append('listing_id', data.listing_id || '');
                  fd.append('seller_id', data.seller_id || '');
                  fd.append('buyer_id', data.buyer_id || '');
                  fd.append('transaction_date', dateTime);
                  fd.append('transaction_location', location);
                  saveBtn.disabled = true;
                  saveBtn.textContent = 'Saving...';
                  saveStatus.textContent = '';
                  fetch('transaction_monitoring.php', { method:'POST', body:fd, credentials:'same-origin' })
                    .then(function(r){ return r.json(); })
                    .then(function(res){
                      saveBtn.disabled = false;
                      saveBtn.textContent = 'Save Meet-up Details';
                      if (res && res.ok) {
                        saveStatus.textContent = 'Saved successfully!';
                        saveStatus.style.color = '#38a169';
                        if (res.warning) { saveStatus.textContent += ' (' + res.warning + ')'; }
                        locVal = location; whenVal = dateTime;
                        if (location && location.indexOf(',')>0){
                          var parts = location.split(',');
                          var la = parseFloat((parts[0]||'').trim()); var ln = parseFloat((parts[1]||'').trim());
                          if (!isNaN(la) && !isNaN(ln)){
                            if (currentTxMarker) currentTxMarker.remove();
                            var ll=[la,ln]; currentTxMarker = L.marker(ll).addTo(currentTxMap); 
                            try{ currentTxMap.setView(ll,14);}catch(_){ }
                          }
                        }
                      } else {
                        saveStatus.textContent = 'Failed to save: ' + (res && res.error ? res.error : 'Unknown error');
                        saveStatus.style.color = '#e53e3e';
                      }
                    })
                    .catch(function(err){
                      saveBtn.disabled = false;
                      saveBtn.textContent = 'Save Meet-up Details';
                      saveStatus.textContent = 'Network error: ' + err.message;
                      saveStatus.style.color = '#e53e3e';
                    });
                });
              }
              // Complete flow
              var completeBtn = document.getElementById('btnCompleteTx');
              if (!isCompleted && completeBtn) {
                completeBtn.addEventListener('click', function(){
                  // Open complete modal and seed hidden fields
                  var fm = document.getElementById('batCompleteForm');
                  if (fm){
                    fm.querySelector('input[name="transaction_id"]').value = data.transaction_id || '';
                    fm.querySelector('input[name="listing_id"]').value = data.listing_id || '';
                    fm.querySelector('input[name="seller_id"]').value = data.seller_id || '';
                    fm.querySelector('input[name="buyer_id"]').value = data.buyer_id || '';
                  }
                  openModal('batCompleteModal');
                });
              }
            });
        }
      });
    })();
  </script>
  <!-- Complete Transaction Modal (BAT) -->
  <div id="batCompleteModal" class="modal" style="display:none;align-items:center;justify-content:center;">
    <div class="panel" style="max-width:560px;width:100%">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;">
        <h2 style="margin:0;">Complete Transaction</h2>
        <button class="close-btn" data-close="batCompleteModal">Close</button>
      </div>
      <form id="batCompleteForm" style="margin-top:8px;">
        <input type="hidden" name="transaction_id" />
        <input type="hidden" name="listing_id" />
        <input type="hidden" name="seller_id" />
        <input type="hidden" name="buyer_id" />
        <input type="hidden" name="payment_method" id="paymentMethodHidden" />
        <div class="card" style="padding:12px;display:grid;gap:10px;">
          <div>
            <label style="font-weight:600;">Result</label>
            <div style="margin-top:6px;">
              <label style="margin-right:12px;"><input type="radio" name="result" value="successful" checked /> Successful</label>
              <label><input type="radio" name="result" value="failed" /> Failed</label>
            </div>
          </div>
          <div>
            <label style="font-weight:600;">Final Price (₱)</label>
            <input type="number" name="price" step="0.01" min="0" style="width:100%;padding:8px;border:1px solid #e2e8f0;border-radius:6px;" />
          </div>
          <div>
            <label style="font-weight:600;">Payment Method</label>
            <div style="margin-top:6px;display:flex;flex-direction:column;gap:6px;">
              <select id="paymentMethodSelect" style="width:100%;padding:8px;border:1px solid #e2e8f0;border-radius:6px;">
                <option value="Cash">Cash</option>
                <option value="GCash">GCash</option>
                <option value="other">Other payment method</option>
              </select>
              <input type="text" id="paymentMethodOther" placeholder="Specify other payment method" style="width:100%;padding:8px;border:1px solid #e2e8f0;border-radius:6px;display:none;" />
            </div>
          </div>
          <div style="display:flex;gap:8px;align-items:center;">
            <button type="button" class="btn" id="batCompleteSubmit">Submit</button>
            <span id="batCompleteMsg" class="subtle"></span>
          </div>
        </div>
      </form>
    </div>
  </div>
  <script>
    (function(){
      var form = document.getElementById('batCompleteForm');
      var submitBtn = document.getElementById('batCompleteSubmit');
      var msg = document.getElementById('batCompleteMsg');
      var methodSelect = document.getElementById('paymentMethodSelect');
      var methodOther = document.getElementById('paymentMethodOther');
      var methodHidden = document.getElementById('paymentMethodHidden');

      if (methodSelect && methodOther){
        methodSelect.addEventListener('change', function(){
          if (methodSelect.value === 'other'){
            methodOther.style.display = '';
          } else {
            methodOther.style.display = 'none';
            methodOther.value = '';
          }
        });
      }

      if (submitBtn && form){
        submitBtn.addEventListener('click', function(){
          msg.textContent = '';
          var result = (form.querySelector('input[name="result"]:checked') || {}).value || '';
          var priceInput = form.querySelector('input[name="price"]');
          var price = priceInput ? parseFloat(priceInput.value || '0') : 0;
          var methodVal = methodSelect ? methodSelect.value : '';
          var pay = '';
          if (methodVal === 'other'){
            pay = methodOther ? methodOther.value.toString().trim() : '';
          } else {
            pay = methodVal || '';
          }
          if (result==='successful' && (isNaN(price) || price<=0 || pay==='')){
            msg.textContent = 'Please provide price and payment method for successful transactions.';
            msg.style.color = '#e53e3e';
            return;
          }
          if (methodHidden){ methodHidden.value = pay; }
          var fd = new FormData(form);
          fd.append('action','complete_transaction');
          submitBtn.disabled = true; submitBtn.textContent = 'Submitting...';
          fetch('transaction_monitoring.php', { method:'POST', body: fd, credentials:'same-origin' })
            .then(function(r){ return r.json(); })
            .then(function(res){
              submitBtn.disabled = false; submitBtn.textContent = 'Submit';
              if (res && res.ok){
                msg.textContent = 'Transaction completed.'; msg.style.color = '#38a169';
                setTimeout(function(){
                  document.querySelector('.modal#batCompleteModal').style.display='none';
                  window.location.reload();
                }, 600);
              } else {
                msg.textContent = 'Failed: ' + (res && res.error ? res.error : 'Unknown error'); msg.style.color = '#e53e3e';
              }
            })
            .catch(function(err){ submitBtn.disabled=false; submitBtn.textContent='Submit'; msg.textContent = 'Network error: '+err.message; msg.style.color = '#e53e3e'; });
        });
      }
    })();
  </script>
</body>
</html>
