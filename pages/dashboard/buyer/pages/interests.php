<?php
session_start();
require_once __DIR__ . '/../../../authentication/lib/supabase_client.php';

if (($_SESSION['role'] ?? '') !== 'buyer'){
  http_response_code(302);
  header('Location: ../dashboard.php');
  exit;
}
$buyer_id = $_SESSION['user_id'] ?? null;
if (!$buyer_id){
  http_response_code(302);
  header('Location: ../dashboard.php');
  exit;
}

function safe($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// Fetch interests joined to active listings (may not exist if listing moved/sold)
[$ints,$ist,$ier] = sb_rest('GET','listinginterest',[
  'select'=>'interest_id,listing_id,message,created',
  'buyer_id'=>'eq.'.$buyer_id,
  'order'=>'created.desc'
]);
if (!($ist>=200 && $ist<300) || !is_array($ints)) $ints = [];

$listings = [];
foreach ($ints as $it){
  $lid = (int)$it['listing_id'];
  // Try active first
  [$act,$ast,$aer] = sb_rest('GET','activelivestocklisting',[ 'select'=>'listing_id,seller_id,livestock_type,breed,address,age,weight,price,created', 'listing_id'=>'eq.'.$lid, 'limit'=>1 ]);
  if ($ast>=200 && $ast<300 && is_array($act) && isset($act[0])){
    $listings[] = ['row'=>$act[0], 'interest'=>$it, 'status'=>'Active'];
    continue;
  }
  // Try sold
  [$sld,$sst,$ser] = sb_rest('GET','activelivestocklisting',[ 'select'=>'listing_id,seller_id,livestock_type,breed,address,age,weight,price,created', 'listing_id'=>'eq.'.$lid, 'status'=>'eq.Sold', 'limit'=>1 ]);
  if ($sst>=200 && $sst<300 && is_array($sld) && isset($sld[0])){
    $listings[] = ['row'=>$sld[0], 'interest'=>$it, 'status'=>'Sold'];
    continue;
  }
  // As fallback, try pending table
  [$pend,$pst,$per] = sb_rest('GET','livestocklisting',[ 'select'=>'listing_id,seller_id,livestock_type,breed,address,age,weight,price,created', 'listing_id'=>'eq.'.$lid, 'limit'=>1 ]);
  if ($pst>=200 && $pst<300 && is_array($pend) && isset($pend[0])){
    $listings[] = ['row'=>$pend[0], 'interest'=>$it, 'status'=>'Pending'];
    continue;
  }
}
?>
<?php
require_once __DIR__ . '/../../../authentication/lib/supabase_client.php';
function safe($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function checkUserViolations($userId){
  // Check penalty_log for violations in the past month
  $oneMonthAgo = date('Y-m-d H:i:s', strtotime('-1 month'));
  [$penalties,$status,$error] = sb_rest('GET','penalty_log',[
    'select'=>'*',
    'buyer_id' => 'eq.'.$userId,
    'created' => 'gte.'.$oneMonthAgo
  ]);
  
  if ($status>=200 && $status<300 && is_array($penalties) && !empty($penalties)){
    return count($penalties);
  }
  return 0;
}

$userId = $_SESSION['user_id'] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>My Interests</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../style/dashboard.css">
  <style>
    table{width:100%;border-collapse:collapse}
    th,td{padding:10px;border-bottom:1px solid #e5e7eb;text-align:left}
    th{font-weight:600;color:#374151}
    .status{font-size:12px;padding:2px 6px;border-radius:6px;border:1px solid #e5e7eb;display:inline-block}
    .violation-badge{background:#ef4444;color:white;padding:2px 6px;border-radius:4px;font-size:11px;margin-left:8px}
  </style>
</head>
<body>
  <nav class="navbar">
    <div class="nav-left"><div class="brand">My Interests</div></div>
    <div class="nav-right"><a class="btn" href="../dashboard.php">Back to Dashboard</a></div>
  </nav>
  <div class="wrap">
    <div class="card">
      <h1>Listings you are interested in</h1>
      <div class="table-scroll">
        <table class="table-simple">
          <thead>
            <tr>
              <th>Listing</th>
              <th>Address</th>
              <th>Age</th>
              <th>Weight (kg)</th>
              <th>Price</th>
              <th>Status</th>
              <th>Interested On</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!count($listings)): ?>
              <tr><td colspan="8" style="color:#6b7280">No interests yet.</td></tr>
            <?php else: foreach ($listings as $it): $r = $it['row']; $int=$it['interest']; 
              $violations = checkUserViolations($userId);
              ?>
              <tr>
                <td><?php echo safe(($r['livestock_type']??'').' • '.($r['breed']??'')); ?></td>
                <td><?php echo safe($r['address'] ?? ''); ?></td>
                <td><?php echo safe($r['age'] ?? ''); ?></td>
                <td><?php echo safe($r['weight'] ?? ''); ?></td>
                <td>₱<?php echo safe($r['price'] ?? ''); ?></td>
                <td><span class="status"><?php echo safe($it['status']); ?><?php if ($violations > 0): ?><span class="violation-badge"><?php echo $violations; ?> violation(s)</span><?php endif; ?></span></td>
                <td><?php echo safe($int['created'] ?? ''); ?></td>
                <td><a class="btn" href="viewpost.php?listing_id=<?php echo (int)$int['listing_id']; ?>">View</a></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</body>
</html>
