<?php
session_start();
require_once __DIR__ . '/../../../authentication/lib/supabase_client.php';

$firstname = isset($_SESSION['firstname']) && $_SESSION['firstname'] !== '' ? $_SESSION['firstname'] : 'User';
$sellerId = $_SESSION['user_id'] ?? null;
if (!$sellerId) {
  header('Location: ../dashboard.php');
  exit;
}

// Inline AJAX endpoints
if (isset($_GET['action']) && $_GET['action'] === 'interests'){
  header('Content-Type: application/json');
  $listingId = isset($_GET['listing_id']) ? (int)$_GET['listing_id'] : 0;
  if (!$listingId) { echo json_encode(['ok'=>false,'error'=>'missing listing_id']); exit; }
  // Join listinginterest with buyer
  [$rows,$st,$err] = sb_rest('GET','listinginterest',[
    'select' => 'interest_id,listing_id,buyer_id,message,created,buyer:buyer(user_id,user_fname,user_mname,user_lname,bdate,email)',
    'listing_id' => 'eq.'.$listingId,
    'order' => 'created.desc'
  ]);
  if (!($st>=200 && $st<300) || !is_array($rows)) { echo json_encode(['ok'=>false,'error'=>'load_failed']); exit; }
  echo json_encode(['ok'=>true,'data'=>$rows]);
  exit;
}
if (isset($_GET['action']) && $_GET['action'] === 'buyer_profile'){
  header('Content-Type: application/json');
  $buyerId = isset($_GET['buyer_id']) ? (int)$_GET['buyer_id'] : 0;
  if (!$buyerId) { echo json_encode(['ok'=>false,'error'=>'missing buyer_id']); exit; }
  [$rows,$st,$err] = sb_rest('GET','buyer',[
    'select'=>'user_id,user_fname,user_mname,user_lname,bdate,contact,address,barangay,municipality,province,email',
    'user_id'=>'eq.'.$buyerId,
    'limit'=>1
  ]);
  if (!($st>=200 && $st<300) || !is_array($rows) || !isset($rows[0])){ echo json_encode(['ok'=>false,'error'=>'not_found']); exit; }
  
  // Get buyer ratings
  [$ratings,$ratingStatus] = sb_rest('GET','userrating',[
    'select' => 'rating,description,created_at',
    'buyer_id' => 'eq.' . $buyerId
  ]);
  
  $ratingData = ['average' => 0, 'count' => 0];
  if ($ratingStatus >= 200 && $ratingStatus < 300 && is_array($ratings) && !empty($ratings)) {
    $total = 0;
    foreach ($ratings as $rating) {
      $total += (float)($rating['rating'] ?? 0);
    }
    $ratingData = [
      'average' => round($total / count($ratings), 1),
      'count' => count($ratings)
    ];
  }
  
  $buyerData = $rows[0];
  $buyerData['rating'] = $ratingData;
  echo json_encode(['ok'=>true,'data'=>$buyerData]);
  exit;
}
if (isset($_POST['action']) && $_POST['action'] === 'start_transaction'){
  header('Content-Type: application/json');
  $listingId = isset($_POST['listing_id']) ? (int)$_POST['listing_id'] : 0;
  $buyerId = isset($_POST['buyer_id']) ? (int)$_POST['buyer_id'] : 0;
  if (!$listingId || !$buyerId) { echo json_encode(['ok'=>false,'error'=>'missing params']); exit; }
  $payload = [[
    'listing_id'=>$listingId,
    'seller_id'=>(int)$sellerId,
    'buyer_id'=>$buyerId,
    'status'=>'Started'
  ]];
  [$res,$st,$err] = sb_rest('POST','starttransactions',[], $payload, ['Prefer: return=representation']);
  if (!($st>=200 && $st<300)){
    $detail = '';
    if (is_array($res) && isset($res['message'])) { $detail = $res['message']; }
    elseif (is_string($res) && $res!=='') { $detail = $res; }
    echo json_encode(['ok'=>false,'error'=>'start_failed','code'=>$st,'detail'=>$detail]);
    exit;
  }
  // Also write to transactions_logs
  $logPayload = [[
    'listing_id'=>$listingId,
    'seller_id'=>(int)$sellerId,
    'buyer_id'=>$buyerId,
    'status'=>'Started'
  ]];
  [$lr,$ls,$le] = sb_rest('POST','transactions_logs',[], $logPayload, ['Prefer: return=representation']);
  $warning = null;
  if (!($ls>=200 && $ls<300)){
    $ldetail = '';
    if (is_array($lr) && isset($lr['message'])) { $ldetail = $lr['message']; }
    elseif (is_string($lr) && $lr!=='') { $ldetail = $lr; }
    $warning = 'Log insert failed (code '.(string)$ls.'). '.($ldetail?:'');
  }
  echo json_encode(['ok'=>true,'data'=>$res[0] ?? null, 'warning'=>$warning]);
  exit;
}

$tab = isset($_GET['tab']) ? strtolower($_GET['tab']) : 'pending';
if (!in_array($tab, ['pending','active','sold','denied'], true)) { $tab = 'pending'; }

function fetch_list($tables, $sellerId, $statusFilter = null){
  $select = 'listing_id,livestock_type,breed,address,age,weight,price,status,created';
  $all = [];
  foreach ($tables as $t){
    $params = [
      'select' => $select,
      'seller_id' => 'eq.'.$sellerId,
      'order' => 'created.desc'
    ];
    if ($statusFilter) {
      $params['status'] = 'eq.'.$statusFilter;
    }
    [$res,$st,$err] = sb_rest('GET', $t, $params);
    if ($st>=200 && $st<300 && is_array($res)){
      foreach ($res as $row){ $all[] = $row; }
    }
  }
  // sort combined by created desc if present
  usort($all, function($a,$b){ $ca=strtotime($a['created']??''); $cb=strtotime($b['created']??''); return $cb<=>$ca; });
  return $all;
}

function checkUserViolations($userId){
  // Check penalty_log for violations in the past month
  $oneMonthAgo = date('Y-m-d H:i:s', strtotime('-1 month'));
  [$penalties,$status,$error] = sb_rest('GET','penalty_log',[
    'select'=>'*',
    'seller_id' => 'eq.'.$userId,
    'created' => 'gte.'.$oneMonthAgo
  ]);
  
  if ($status>=200 && $status<300 && is_array($penalties) && !empty($penalties)){
    return count($penalties);
  }
  return 0;
}

$pendingRows = ($tab==='pending') ? fetch_list(['reviewlivestocklisting','livestocklisting'], $sellerId) : [];
$activeRows = ($tab==='active') ? fetch_list(['activelivestocklisting'], $sellerId, 'Verified') : [];
$soldRows   = ($tab==='sold')   ? fetch_list(['activelivestocklisting'], $sellerId, 'Sold') : [];
$deniedRows = ($tab==='denied') ? fetch_list(['deniedlivestocklisting'], $sellerId) : [];

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Manage Listings</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../style/dashboard.css">
  <style>
    .modal{position:fixed;inset:0;background:rgba(0,0,0,0.5);display:none;align-items:center;justify-content:center;z-index:9999}
    .modal .panel{background:#fff;border-radius:10px;max-width:900px;width:95vw;max-height:90vh;overflow:auto;padding:16px}
    .imgs{display:flex;gap:8px;flex-wrap:wrap;margin:8px 0}
    .imgs img{width:150px;height:150px;object-fit:cover;border-radius:8px;border:1px solid #e2e8f0}
    .subtle{color:#4a5568;font-size:12px}
    .table-clean{width:100%;border-collapse:collapse}
    .table-clean th,.table-clean td{padding:8px;text-align:left}
    .table-clean thead tr{border-bottom:1px solid #e2e8f0}
    .pill{display:inline-block;background:#edf2f7;color:#2d3748;font-size:12px;padding:2px 8px;border-radius:12px}
    .grid2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    .counter{border:1px dashed #cbd5e0;border-radius:8px;height:100px;display:flex;align-items:center;justify-content:center;color:#4a5568}
    .close-btn{background:#e53e3e;color:#fff;border:none;border-radius:6px;padding:6px 10px;cursor:pointer}
    .sold-listing{text-decoration:line-through;color:#718096}
    .violation-badge{background:#ef4444;color:white;padding:2px 6px;border-radius:4px;font-size:11px;margin-left:8px}
  </style>
</head>
<body>
  <div class="wrap">
    <div class="top" style="margin-bottom:8px;">
      <div><h1>Manage Listings</h1></div>
    </div>

    <div class="card">
      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:12px;">
        <a class="btn" href="?tab=pending" style="background:<?php echo $tab==='pending'?'#d69e2e':'#718096'; ?>">Pending</a>
        <a class="btn" href="?tab=active" style="background:<?php echo $tab==='active'?'#d69e2e':'#718096'; ?>">Active</a>
        <a class="btn" href="?tab=sold" style="background:<?php echo $tab==='sold'?'#d69e2e':'#718096'; ?>">Sold</a>
        <a class="btn" href="?tab=denied" style="background:<?php echo $tab==='denied'?'#d69e2e':'#718096'; ?>">Denied</a>
        <span style="flex:1 1 auto"></span>
        <a class="btn" href="createlisting.php">Create Listing</a>
      </div>

      <?php
        $rows = $pendingRows ?: ($activeRows ?: ($soldRows ?: $deniedRows));
      ?>
      <div style="overflow:auto;">
        <table style="width:100%;border-collapse:collapse;">
          <thead>
            <tr style="text-align:left;border-bottom:1px solid #e2e8f0;">
              <th style="padding:8px;">ID</th>
              <th style="padding:8px;">Type</th>
              <th style="padding:8px;">Breed</th>
              <th style="padding:8px;">Age</th>
              <th style="padding:8px;">Weight</th>
              <th style="padding:8px;">Price</th>
              <th style="padding:8px;">Status</th>
              <th style="padding:8px;">Created</th>
              <th style="padding:8px;">Address</th>
              <th style="padding:8px;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$rows || count($rows)===0): ?>
              <tr><td colspan="9" style="padding:12px;color:#4a5568;">No listings found.</td></tr>
            <?php else: ?>
              <?php foreach ($rows as $r): ?>
                <?php 
                $violations = checkUserViolations($sellerId);
                ?>
                <tr style="border-bottom:1px solid #edf2f7;">
                  <td style="padding:8px;">&nbsp;<?php echo htmlspecialchars((string)($r['listing_id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                  <td style="padding:8px;">&nbsp;<span class="<?php echo (strtolower($r['status'] ?? '') === 'sold') ? 'sold-listing' : ''; ?>"><?php echo htmlspecialchars((string)($r['livestock_type'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span></td>
                  <td style="padding:8px;">&nbsp;<span class="<?php echo (strtolower($r['status'] ?? '') === 'sold') ? 'sold-listing' : ''; ?>"><?php echo htmlspecialchars((string)($r['breed'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span></td>
                  <td style="padding:8px;">&nbsp;<?php echo htmlspecialchars((string)($r['age'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                  <td style="padding:8px;">&nbsp;<?php echo htmlspecialchars((string)($r['weight'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                  <td style="padding:8px;">&nbsp;<?php echo htmlspecialchars((string)($r['price'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                  <td style="padding:8px;">&nbsp;<?php echo htmlspecialchars((string)($r['status'] ?? ''), ENT_QUOTES, 'UTF-8'); ?><?php if ($violations > 0): ?><span class="violation-badge"><?php echo $violations; ?> violation(s)</span><?php endif; ?></td>
                  <td style="padding:8px;">&nbsp;<?php echo htmlspecialchars((string)($r['created'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                  <td style="padding:8px;">&nbsp;<?php echo htmlspecialchars((string)($r['address'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                  <td style="padding:8px;">
                    <button class="btn show-listing" data-id="<?php echo (int)($r['listing_id']??0); ?>" data-type="<?php echo htmlspecialchars((string)($r['livestock_type']??''),ENT_QUOTES,'UTF-8'); ?>" data-breed="<?php echo htmlspecialchars((string)($r['breed']??''),ENT_QUOTES,'UTF-8'); ?>" data-age="<?php echo htmlspecialchars((string)($r['age']??''),ENT_QUOTES,'UTF-8'); ?>" data-weight="<?php echo htmlspecialchars((string)($r['weight']??''),ENT_QUOTES,'UTF-8'); ?>" data-price="<?php echo htmlspecialchars((string)($r['price']??''),ENT_QUOTES,'UTF-8'); ?>" data-status="<?php echo htmlspecialchars((string)($r['status']??''),ENT_QUOTES,'UTF-8'); ?>" data-created="<?php echo htmlspecialchars((string)($r['created']??''),ENT_QUOTES,'UTF-8'); ?>" data-address="<?php echo htmlspecialchars((string)($r['address']??''),ENT_QUOTES,'UTF-8'); ?>">Show</button>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Listing Details Modal -->
  <div id="listingModal" class="modal">
    <div class="panel">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;">
        <h2 style="margin:0;">Listing Details</h2>
        <button class="close-btn" data-close="listingModal">Close</button>
      </div>
      <div id="listingBasics" style="margin-top:6px;"></div>
      <div class="imgs" id="listingImages"></div>
      <div class="subtle" id="imgNotice"></div>
      <div style="margin-top:12px;">
        <h3 style="margin:8px 0;">Interested Buyers</h3>
        <table class="table-clean" id="interestTable">
          <thead>
            <tr>
              <th>Fullname</th>
              <th>Email</th>
              <th>Birthdate</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Buyer Profile Modal -->
  <div id="buyerModal" class="modal">
    <div class="panel">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;">
        <h2 style="margin:0;">Buyer Profile</h2>
        <button class="close-btn" data-close="buyerModal">Back</button>
      </div>
      <div id="buyerDetails" style="margin-top:8px;"></div>
      <div class="grid2" style="margin-top:12px;">
        <div class="counter">Recent Violations (placeholder)</div>
        <div class="counter" id="buyerRating">Rating (placeholder)</div>
      </div>
    </div>
  </div>

  <script>
    (function(){
      function $(sel){ return document.querySelector(sel); }
      function $all(sel){ return Array.prototype.slice.call(document.querySelectorAll(sel)); }
      function openModal(id){ var el = document.getElementById(id); if(el){ el.style.display='flex'; } }
      function closeModal(id){ var el = document.getElementById(id); if(el){ el.style.display='none'; } }
      $all('.close-btn').forEach(function(btn){ btn.addEventListener('click', function(){ var id = btn.getAttribute('data-close'); closeModal(id); }); });

      function rootForStatus(status){
        var s = (status||'').toLowerCase();
        if (s==='pending' || s==='under review' || s==='review') return 'listings/underreview';
        if (s==='active' || s==='verified') return 'listings/verified';
        if (s==='denied') return 'listings/denied';
        if (s==='sold') return 'listings/sold';
        return 'listings/underreview';
      }

      function renderImages(listingId, status, created){
        var imgs = $('#listingImages'); var note = $('#imgNotice');
        imgs.innerHTML=''; note.textContent='';
        var root = rootForStatus(status);
        // Use legacy scheme for seller-side to avoid needing fullname folder
        var legacyFolder = <?php echo (int)$sellerId; ?> + '_' + listingId;
        var fails=0; var total=3;
        for (var i=1;i<=3;i++){
          var src = '../../bat/pages/storage_image.php?path=' + root + '/' + legacyFolder + '/image' + i;
          var im = new Image(); im.width=150; im.height=150; im.alt='image'+i; im.src = src;
          im.onerror = function(){ fails++; if (fails===total){ note.textContent='No images found in '+root+'/'+legacyFolder; } };
          imgs.appendChild(im);
        }
      }

      function fetchInterests(listingId){
        return fetch('managelistings.php?action=interests&listing_id='+encodeURIComponent(listingId), { credentials:'same-origin' })
          .then(function(r){ return r.json(); });
      }
      function fetchBuyer(buyerId){
        return fetch('managelistings.php?action=buyer_profile&buyer_id='+encodeURIComponent(buyerId), { credentials:'same-origin' })
          .then(function(r){ return r.json(); });
      }
      function startTransaction(listingId, buyerId){
        var fd = new FormData(); fd.append('action','start_transaction'); fd.append('listing_id', listingId); fd.append('buyer_id', buyerId);
        return fetch('managelistings.php', { method:'POST', body: fd, credentials:'same-origin' }).then(function(r){ return r.json(); });
      }

      function fullname(b){
        var f=(b.user_fname||''), m=(b.user_mname||''), l=(b.user_lname||'');
        return (f+' '+(m?m+' ':'')+l).trim();
      }

      function openListingModal(data){
        var basics = $('#listingBasics'); var tbody = $('#interestTable tbody');
        basics.innerHTML = '<div><strong>'+data.type+' • '+data.breed+'</strong> <span class="pill">'+data.status+'</span></div>'+
          '<div>Age: '+data.age+' • Weight: '+data.weight+'kg • Price: ₱'+data.price+'</div>'+
          '<div class="subtle">Listing #'+data.id+' • '+data.created+'</div>'+
          '<div>Address: '+data.address+'</div>';
        renderImages(data.id, data.status, data.created);
        tbody.innerHTML = '<tr><td colspan="4" class="subtle">Loading...</td></tr>';
        fetchInterests(data.id).then(function(res){
          if (!res.ok){ tbody.innerHTML = '<tr><td colspan="4" class="subtle">Failed to load interests</td></tr>'; return; }
          var rows = res.data || [];
          if (!rows.length){ tbody.innerHTML = '<tr><td colspan="4" class="subtle">No interested buyers yet.</td></tr>'; return; }
          tbody.innerHTML = '';
          rows.forEach(function(row){
            var b = row.buyer || {}; var name = fullname(b);
            var tr = document.createElement('tr');
            tr.innerHTML = '<td>'+name+'</td>'+
              '<td>'+(b.email||'')+'</td>'+
              '<td>'+(b.bdate||'')+'</td>'+
              '<td>'+
                '<button class="btn btn-start" data-buyer="'+(b.user_id||'')+'" data-listing="'+data.id+'">Initiate Transaction</button>\n'+
                '<button class="btn btn-profile" data-buyer="'+(b.user_id||'')+'">View Profile</button>'+
              '</td>';
            tbody.appendChild(tr);
          });
        });
        openModal('listingModal');
      }

      document.addEventListener('click', function(e){
        if (e.target && e.target.classList.contains('show-listing')){
          var btn = e.target;
          var data = {
            id: btn.getAttribute('data-id'),
            type: btn.getAttribute('data-type')||'',
            breed: btn.getAttribute('data-breed')||'',
            age: btn.getAttribute('data-age')||'',
            weight: btn.getAttribute('data-weight')||'',
            price: btn.getAttribute('data-price')||'',
            status: btn.getAttribute('data-status')||'',
            created: btn.getAttribute('data-created')||'',
            address: btn.getAttribute('data-address')||''
          };
          openListingModal(data);
        }
        if (e.target && e.target.classList.contains('btn-start')){
          var btn = e.target;
          if (btn.disabled) return;
          var buyerId = btn.getAttribute('data-buyer');
          var listingId = btn.getAttribute('data-listing');
          btn.disabled = true; btn.textContent = 'Starting...';
          startTransaction(listingId, buyerId).then(function(res){
            if (!res.ok){
              alert('Failed to start transaction (code '+(res.code||'?')+'): '+(res.detail||''));
              btn.disabled = false; btn.textContent = 'Initiate Transaction';
            } else {
              alert('Transaction started');
              btn.disabled = true; btn.textContent = 'Started';
            }
          }).catch(function(){
            btn.disabled = false; btn.textContent = 'Initiate Transaction';
          });
        }
        if (e.target && e.target.classList.contains('btn-profile')){
          var buyerId = e.target.getAttribute('data-buyer');
          var details = $('#buyerDetails'); details.innerHTML='Loading...';
          fetchBuyer(buyerId).then(function(res){
            if (!res.ok){ details.innerHTML='Failed to load buyer profile'; }
            else {
              var b = res.data || {};
              var name = fullname(b);
              var rating = b.rating || {average: 0, count: 0};
              details.innerHTML = '<div><strong>'+name+'</strong></div>'+
                '<div>Email: '+(b.email||'')+'</div>'+
                '<div>Birthdate: '+(b.bdate||'')+'</div>'+
                '<div>Contact: '+(b.contact||'')+'</div>'+
                '<div>Address: '+(b.address||'')+'</div>'+
                '<div>'+[b.barangay,b.municipality,b.province].filter(Boolean).join(', ')+'</div>';
              
              var ratingEl = $('#buyerRating');
              if (ratingEl) {
                ratingEl.innerHTML = rating.average + ' ⭐ (' + rating.count + ' rating' + (rating.count !== 1 ? 's' : '') + ')';
              }
            }
          });
          openModal('buyerModal');
        }
      });
      ['listingModal','buyerModal'].forEach(function(id){
        var modal = document.getElementById(id);
        if (modal){ modal.addEventListener('click', function(ev){ if (ev.target===modal) closeModal(id); }); }
      });
    })();
  </script>
</body>
</html>
