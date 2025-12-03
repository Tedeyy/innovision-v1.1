<?php
session_start();
require_once __DIR__ . '/../../../authentication/lib/supabase_client.php';
require_once __DIR__ . '/../../../authentication/lib/use_case_logger.php';
require_once __DIR__ . '/../../../common/notify.php';

// Allow all roles; require login only
$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId === 0){
  $_SESSION['flash_error'] = 'Please sign in to view transactions.';
  header('Location: ../dashboard.php');
  exit;
}

// Seller reports buyer from completed transaction
if (isset($_POST['action']) && $_POST['action']==='report_user'){
  header('Content-Type: application/json');
  $sellerIdIn = isset($_POST['seller_id']) ? (int)$_POST['seller_id'] : 0;
  $buyerIdIn  = isset($_POST['buyer_id']) ? (int)$_POST['buyer_id'] : 0;
  $title = isset($_POST['title']) ? trim((string)$_POST['title']) : '';
  $description = isset($_POST['description']) ? trim((string)$_POST['description']) : '';
  if ($sellerIdIn !== $userId || $buyerIdIn<=0 || $title==='' || $description===''){
    echo json_encode(['ok'=>false,'error'=>'invalid_params']); exit;
  }
  // Insert into reviewreportuser
  $payload = [[
    'seller_id'=>$sellerIdIn,
    'buyer_id'=>$buyerIdIn,
    'title'=>$title,
    'description'=>$description,
    'Status'=>'Pending'
  ]];
  [$res,$st,$err] = sb_rest('POST','reviewreportuser',[], $payload, ['Prefer: return=representation']);
  if (!($st>=200 && $st<300)){
    $detail = is_array($res) && isset($res['message']) ? $res['message'] : (is_string($res)?$res:'');
    echo json_encode(['ok'=>false,'error'=>'insert_failed','code'=>$st,'detail'=>$detail]); exit;
  }
  // also log to userreport_logs with an available admin
  [$arows,$ast,$aer] = sb_rest('GET','admin',[ 'select'=>'user_id', 'limit'=>1, 'order'=>'user_id.asc' ]);
  $admin_id = ($ast>=200 && $ast<300 && is_array($arows) && isset($arows[0]['user_id'])) ? (int)$arows[0]['user_id'] : null;
  if ($admin_id){
    $log = [[
      'report_id'=> $res[0]['report_id'] ?? null,
      'seller_id'=>$sellerIdIn,
      'buyer_id'=>$buyerIdIn,
      'title'=>$title,
      'description'=>$description,
      'admin_id'=>$admin_id,
      'Status'=>'Pending'
    ]];
    sb_rest('POST','userreport_logs',[], $log, ['Prefer: return=minimal']);
  }
  echo json_encode(['ok'=>true]);
  exit;
}

if (isset($_POST['action']) && $_POST['action']==='complete_transaction'){
  header('Content-Type: application/json');
  
  $transactionId = isset($_POST['transaction_id']) ? (int)$_POST['transaction_id'] : 0;
  $listingId = isset($_POST['listing_id']) ? (int)$_POST['listing_id'] : 0;
  $sellerId = isset($_POST['seller_id']) ? (int)$_POST['seller_id'] : 0;
  $buyerId = isset($_POST['buyer_id']) ? (int)$_POST['buyer_id'] : 0;
  $result = isset($_POST['result']) ? $_POST['result'] : 'successful';
  $price = isset($_POST['price']) ? (float)$_POST['price'] : 0;
  $paymentMethod = isset($_POST['payment_method']) ? $_POST['payment_method'] : '';
  
  // Validate inputs
  if (!$transactionId || !$listingId || !$sellerId || !$buyerId || $sellerId !== $userId || $price <= 0 || empty($paymentMethod)) {
    echo json_encode(['ok'=>false,'error'=>'invalid_params']);
    exit;
  }
  
  // Get current transaction data from ongoingtransactions
  [$txRows,$txStatus,$txError] = sb_rest('GET','ongoingtransactions',[
    'select'=>'*',
    'transaction_id'=>'eq.'.$transactionId,
    'listing_id'=>'eq.'.$listingId,
    'seller_id'=>'eq.'.$sellerId,
    'buyer_id'=>'eq.'.$buyerId,
    'limit'=>1
  ]);
  
  if (!($txStatus>=200 && $txStatus<300) || !is_array($txRows) || empty($txRows)) {
    echo json_encode(['ok'=>false,'error'=>'transaction_not_found']);
    exit;
  }
  
  $transaction = $txRows[0];
  
  // Get listing data for transfer
  [$listingRows,$listingStatus,$listingError] = sb_rest('GET','activelivestocklisting',[
    'select'=>'*',
    'listing_id'=>'eq.'.$listingId,
    'seller_id'=>'eq.'.$sellerId,
    'limit'=>1
  ]);
  
  if (!($listingStatus>=200 && $listingStatus<300) || !is_array($listingRows) || empty($listingRows)) {
    echo json_encode(['ok'=>false,'error'=>'listing_not_found']);
    exit;
  }
  
  $listing = $listingRows[0];
  
  // Handle file upload for successful transactions
  $docPhotoPath = null;
  if ($result === 'successful' && isset($_FILES['doc_photo']) && $_FILES['doc_photo']['error'] === UPLOAD_ERR_OK) {
    $file = $_FILES['doc_photo'];
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
    $maxSize = 5 * 1024 * 1024; // 5MB
    
    if (!in_array($file['type'], $allowedTypes) || $file['size'] > $maxSize) {
      echo json_encode(['ok'=>false,'error'=>'invalid_file']);
      exit;
    }
    
    // Create folder structure: listings/sold/<seller_id>_<fullname>/
    $sellerFullname = trim(($transaction['seller']['user_fname'] ?? '') . ' ' . ($transaction['seller']['user_lname'] ?? ''));
    $folderName = $sellerId . '_' . preg_replace('/[^a-zA-Z0-9]/', '_', $sellerFullname);
    $uploadDir = "listings/sold/" . $folderName . "/";
    
    if (!is_dir($uploadDir)) {
      mkdir($uploadDir, 0755, true);
    }
    
    $fileName = 'transaction_' . $transactionId . '_' . time() . '.' . pathinfo($file['name'], PATHINFO_EXTENSION);
    $uploadPath = $uploadDir . $fileName;
    
    if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
      $docPhotoPath = $uploadPath;
    }
  }
  
  // Add to completedtransactions
  [$compRes,$compStatus,$compError] = sb_rest('POST','completedtransactions',[],[
    [
      'transaction_id'=>$transactionId,
      'listing_id'=>$listingId,
      'seller_id'=>$sellerId,
      'buyer_id'=>$buyerId,
      'status'=>'Completed',
      'started_at'=>$transaction['started_at'],
      'transaction_date'=>$transaction['transaction_date'] ?? date('Y-m-d H:i:s'),
      'transaction_location'=>$transaction['transaction_location'] ?? null,
      'completed_transaction'=>date('Y-m-d H:i:s'),
      'bat_id'=>$transaction['bat_id'] ?? null
    ]
  ]);
  
  if (!($compStatus>=200 && $compStatus<300)) {
    echo json_encode(['ok'=>false,'error'=>'failed_to_complete','code'=>$compStatus]);
    exit;
  }
  
  // Log use case: Transaction completed by seller
  $purpose = format_use_case_description('Transaction Completed', [
    'transaction_id' => $transactionId,
    'listing_id' => $listingId,
    'buyer_id' => $buyerId,
    'final_price' => '₱' . number_format($price, 2),
    'payment_method' => $paymentMethod,
    'result' => $result,
    'location' => $transaction['transaction_location'] ?? 'Not specified',
    'has_document' => $docPhotoPath ? 'Yes' : 'No'
  ]);
  log_use_case($purpose);
  
  // Add to transactions_logs
  sb_rest('POST','transactions_logs',[],[
    [
      'listing_id'=>$listingId,
      'seller_id'=>$sellerId,
      'buyer_id'=>$buyerId,
      'status'=>'Completed',
      'started_at'=>$transaction['started_at'],
      'transaction_date'=>date('Y-m-d H:i:s'),
      'completed_transaction'=>date('Y-m-d H:i:s')
    ]
  ]);
  
  if ($result === 'successful') {
    // Update listing status to Sold in activelivestocklisting
    [$updateRes,$updateStatus,$updateError] = sb_rest('PATCH','activelivestocklisting',[],[
      'status'=>'Sold',
      'soldprice'=>$price
    ],[
      'listing_id'=>'eq.'.$listingId,
      'seller_id'=>'eq.'.$sellerId
    ]);
    
    if ($updateStatus>=200 && $updateStatus<300) {
      // Add to livestocklisting_logs
      sb_rest('POST','livestocklisting_logs',[],[
        [
          'seller_id'=>$sellerId,
          'livestock_type'=>$listing['livestock_type'],
          'breed'=>$listing['breed'],
          'address'=>$listing['address'],
          'age'=>$listing['age'],
          'weight'=>$listing['weight'],
          'price'=>$listing['price'],
          'status'=>'Sold',
          'bat_id'=>$userId,
          'created'=>date('Y-m-d H:i:s')
        ]
      ]);
      
      // Transfer specific images from verified to sold folder
      $sourceFolder = "listings/verified/" . $sellerId . "_" . preg_replace('/[^a-zA-Z0-9]/', '_', $sellerFullname) . "/";
      $targetFolder = "listings/sold/" . $sellerId . "_" . preg_replace('/[^a-zA-Z0-9]/', '_', $sellerFullname) . "/";
      
      if (is_dir($sourceFolder)) {
        if (!is_dir($targetFolder)) {
          mkdir($targetFolder, 0755, true);
        }
        
        // Extract the full timestamp from listing created datetime
        $createdTimestamp = date('YmdHis', strtotime($listing['created']));
        
        // Look for specific image files with the pattern <timestamp>_1img.jpg, <timestamp>_2img.jpg, <timestamp>_3img.jpg
        $imagePatterns = [
          $createdTimestamp . "_1img.jpg",
          $createdTimestamp . "_2img.jpg", 
          $createdTimestamp . "_3img.jpg"
        ];
        
        foreach ($imagePatterns as $imageFile) {
          $sourceFile = $sourceFolder . $imageFile;
          if (file_exists($sourceFile)) {
            copy($sourceFile, $targetFolder . $imageFile);
          }
        }
      }
      
      // Transfer location pin
      [$pinRows,$pinStatus,$pinError] = sb_rest('GET','activelocation_pins',[
        'select'=>'*',
        'listing_id'=>'eq.'.$listingId,
        'limit'=>1
      ]);
      
      if ($pinStatus>=200 && $pinStatus<300 && !empty($pinRows)) {
        $pin = $pinRows[0];
        
        // Add to soldlocation_pins
        sb_rest('POST','soldlocation_pins',[],[
          [
            'location'=>$pin['location'],
            'listing_id'=>$listingId,
            'status'=>'Sold',
            'created_at'=>date('Y-m-d H:i:s')
          ]
        ]);
        
        // Add to location_pin_logs
        sb_rest('POST','location_pin_logs',[],[
          [
            'location'=>$pin['location'],
            'listing_id'=>$listingId,
            'status'=>$pin['status'],
            'created_at'=>date('Y-m-d H:i:s')
          ]
        ]);
        
        // Delete from activelocation_pins
        sb_rest('DELETE','activelocation_pins',[],[],[
          'listing_id'=>'eq.'.$listingId
        ]);
      }
      
      // Add to successfultransactions
      sb_rest('POST','successfultransactions',[],[
        [
          'transaction_id'=>$transactionId,
          'listing_id'=>$listingId,
          'seller_id'=>$sellerId,
          'buyer_id'=>$buyerId,
          'price'=>$price,
          'payment_method'=>$paymentMethod,
          'status'=>'Successful',
          'transaction_date'=>date('Y-m-d H:i:s')
        ]
      ]);
      
    } else {
      echo json_encode(['ok'=>false,'error'=>'failed_to_update_listing','code'=>$updateStatus]);
      exit;
    }
  } else {
    // Unsuccessful transaction
    sb_rest('POST','failedtransactions',[],[
      [
        'transaction_id'=>$transactionId,
        'listing_id'=>$listingId,
        'seller_id'=>$sellerId,
        'buyer_id'=>$buyerId,
        'price'=>$price,
        'payment_method'=>$paymentMethod,
        'status'=>'Failed',
        'transaction_date'=>date('Y-m-d H:i:s')
      ]
    ]);
  }
  
  // Remove from ongoingtransactions
  sb_rest('DELETE','ongoingtransactions',[],[],[
    'transaction_id'=>'eq.'.$transactionId
  ]);
  
  // Remove listing_interest entries
  sb_rest('DELETE','listinginterest',[],[],[
    'listing_id'=>'eq.'.$listingId
  ]);
  
  echo json_encode(['ok'=>true,'result'=>$result]);
  exit;
}

if (isset($_GET['action']) && $_GET['action']==='has_rating'){
  header('Content-Type: application/json');
  $txId = isset($_GET['transaction_id']) ? (int)$_GET['transaction_id'] : 0;
  if (!$txId){
    echo json_encode(['ok'=>false,'error'=>'missing_params']);
    exit;
  }
  // Did this seller already rate this transaction?
  [$rows,$status,$error] = sb_rest('GET','userrating',[ 'select'=>'rating_id', 'transaction_id'=>'eq.'.$txId, 'seller_id'=>'eq.'.$userId, 'limit'=>1 ]);
  if (!($status>=200 && $status<300) || !is_array($rows)) $rows = [];
  echo json_encode(['ok'=>true,'has_rating'=>(count($rows)>0)]);
  exit;
}

if (isset($_POST['action']) && $_POST['action']==='rate_buyer'){
  header('Content-Type: application/json');
  $txId = isset($_POST['transaction_id']) ? (int)$_POST['transaction_id'] : 0;
  $sellerIdIn = isset($_POST['seller_id']) ? (int)$_POST['seller_id'] : 0;
  $buyerIdIn  = isset($_POST['buyer_id']) ? (int)$_POST['buyer_id'] : 0;
  $rating = isset($_POST['rating']) ? (int)$_POST['rating'] : 0;
  $description = isset($_POST['description']) ? (string)$_POST['description'] : '';
  if (!$txId || $sellerIdIn!==$userId || $buyerIdIn<=0 || $rating<1 || $rating>5){
    echo json_encode(['ok'=>false,'error'=>'missing_params']);
    exit;
  }
  $payload = [[
    'transaction_id'=>$txId,
    'seller_id'=>$sellerIdIn,
    'buyer_id'=>$buyerIdIn,
    'rating'=>$rating,
    'description'=>$description
  ]];
  [$res,$status,$error] = sb_rest('POST','userrating',[], $payload, ['Prefer: return=representation']);
  if (!($status>=200 && $status<300)){
    $detail = '';
    if (is_array($res) && isset($res['message'])) { $detail = $res['message']; }
    elseif (is_string($res) && $res!=='') { $detail = $res; }
    echo json_encode(['ok'=>false,'error'=>'insert_failed','code'=>$status,'detail'=>$detail]);
    exit;
  }
  echo json_encode(['ok'=>true]);
  exit;
}

if (isset($_GET['action']) && $_GET['action']==='list'){
  header('Content-Type: application/json');
  // Fetch from three tables where current user is the seller
  [$srows,$sst,$sse] = sb_rest('GET','starttransactions',[
    'select'=>'transaction_id,listing_id,seller_id,buyer_id,status,started_at,buyer:buyer(user_id,user_fname,user_mname,user_lname,email,contact,location),seller:seller(user_id,user_fname,user_mname,user_lname,email,contact,location),listing:activelivestocklisting(listing_id,livestock_type,breed,price,created,address)',
    'seller_id'=>'eq.'.$userId,
    'order'=>'started_at.desc'
  ]);
  if (!($sst>=200 && $sst<300) || !is_array($srows)) $srows = [];
  
  [$orows,$ost,$ose] = sb_rest('GET','ongoingtransactions',[
    'select'=>'transaction_id,listing_id,seller_id,buyer_id,status,started_at,transaction_date,transaction_location,bat_id,bat:bat(user_id,user_fname,user_mname,user_lname),buyer:buyer(user_id,user_fname,user_mname,user_lname,email,contact,location),seller:seller(user_id,user_fname,user_mname,user_lname,email,contact,location),listing:activelivestocklisting(listing_id,livestock_type,breed,price,created,address)',
    'seller_id'=>'eq.'.$userId,
    'order'=>'started_at.desc'
  ]);
  if (!($ost>=200 && $ost<300) || !is_array($orows)) $orows = [];
  
  [$crows,$cst,$cse] = sb_rest('GET','completedtransactions',[
    'select'=>'transaction_id,listing_id,seller_id,buyer_id,status,started_at,buyer:buyer(user_id,user_fname,user_mname,user_lname,email,contact,location),seller:seller(user_id,user_fname,user_mname,user_lname,email,contact,location),listing:activelivestocklisting(listing_id,livestock_type,breed,price,created,address)',
    'seller_id'=>'eq.'.$userId,
    'order'=>'started_at.desc'
  ]);
  if (!($cst>=200 && $cst<300) || !is_array($crows)) $crows = [];
  
  // Combine all transactions
  $allTransactions = array_merge($srows, $orows, $crows);
  
  // Add thumbnail data to each transaction
  foreach ($allTransactions as &$tx) {
    if (isset($tx['listing_id']) && isset($tx['seller_id'])) {
      $listingId = (int)$tx['listing_id'];
      $sellerId = (int)$tx['seller_id'];
      $seller = $tx['seller'] ?? [];
      
      // Thumbnail using market image path logic
      $legacyFolder = $sellerId.'_'.$listingId;
      $sellerName = trim(($seller['user_fname']??'').'_'.($seller['user_lname']??''));
      $newFolder = $sellerId.'_'.$sellerName;
      $created = $tx['listing']['created'] ?? '';
      $createdKey = $created ? date('YmdHis', strtotime($created)) : '';
      
      // Determine status folder based on listing status
      $status = strtolower($tx['listing']['status'] ?? 'active');
      $statusFolder = 'active'; // default
      if ($status === 'verified') $statusFolder = 'verified';
      elseif ($status === 'sold') $statusFolder = 'sold';
      elseif ($status === 'underreview') $statusFolder = 'underreview';
      elseif ($status === 'denied') $statusFolder = 'denied';
      
      $tx['thumb'] = ($createdKey!==''
        ? '../../bat/pages/storage_image.php?path=listings/'.$statusFolder.'/'.$newFolder.'/'.$createdKey.'_1img.jpg'
        : '../../bat/pages/storage_image.php?path=listings/'.$statusFolder.'/'.$legacyFolder.'/image1');
      $tx['thumb_fallback'] = '../../bat/pages/storage_image.php?path=listings/'.$statusFolder.'/'.$legacyFolder.'/image1';
      
      // Add meet-up details from ongoingtransactions
      if (isset($tx['transaction_date'])) {
        $tx['meetup_date'] = date('Y-m-d', strtotime($tx['transaction_date']));
        $tx['meetup_time'] = date('H:i', strtotime($tx['transaction_date']));
      } else {
        $tx['meetup_date'] = null;
        $tx['meetup_time'] = null;
      }
      
      $tx['meetup_location'] = $tx['transaction_location'] ?? null;
      
      // Add BAT fullname
      if (isset($tx['bat'])) {
        $bat = $tx['bat'];
        $tx['bat_fullname'] = trim(($bat['user_fname']??'').' '.($bat['user_mname']??'').' '.($bat['user_lname']??''));
      } else {
        $tx['bat_fullname'] = null;
      }
    }
  }
  
  echo json_encode(['ok'=>true,'data'=>$allTransactions]);
  exit;
}

if (isset($_POST['action']) && $_POST['action']==='schedule_meetup'){
  header('Content-Type: application/json');
  $listingId = isset($_POST['listing_id']) ? (int)$_POST['listing_id'] : 0;
  $sellerId = isset($_POST['seller_id']) ? (int)$_POST['seller_id'] : 0;
  $buyerId = isset($_POST['buyer_id']) ? (int)$_POST['buyer_id'] : 0;
  
  if (!$listingId || !$sellerId || !$buyerId || $sellerId !== $userId){
    echo json_encode(['ok'=>false,'error'=>'invalid_params']);
    exit;
  }
  
  // First, get the transaction from starttransactions
  [$srows,$sst,$sse] = sb_rest('GET','starttransactions',[
    'select'=>'*',
    'listing_id'=>'eq.'.$listingId,
    'seller_id'=>'eq.'.$sellerId,
    'buyer_id'=>'eq.'.$buyerId,
    'limit'=>1
  ]);
  
  if (!($sst>=200 && $sst<300) || !is_array($srows) || empty($srows)){
    echo json_encode(['ok'=>false,'error'=>'transaction_not_found']);
    exit;
  }
  
  $transaction = $srows[0];
  
  // Insert into ongoingtransactions
  [$ores,$ost,$ose] = sb_rest('POST','ongoingtransactions',[],[
    [
      'listing_id'=>$transaction['listing_id'],
      'seller_id'=>$transaction['seller_id'],
      'buyer_id'=>$transaction['buyer_id'],
      'status'=>'ongoing',
      'started_at'=>$transaction['started_at']
    ]
  ]);
  
  if (!($ost>=200 && $ost<300)){
    echo json_encode(['ok'=>false,'error'=>'insert_ongoing_failed','code'=>$ost]);
    exit;
  }
  
  // Delete from starttransactions
  [$dres,$dst,$dse] = sb_rest('DELETE','starttransactions',[],[],[
    'listing_id'=>'eq.'.$listingId,
    'seller_id'=>'eq.'.$sellerId,
    'buyer_id'=>'eq.'.$buyerId
  ]);
  
  if (!($dst>=200 && $dst<300)){
    echo json_encode(['ok'=>false,'error'=>'delete_start_failed','code'=>$dst]);
    exit;
  }
  // Notify buyer that transaction is now ongoing and will be scheduled by BAT
  if ($buyerId){
    $title = 'Transaction Ongoing';
    $msg = 'Your transaction (listing #'.$listingId.') is now ongoing. BAT will schedule the meet-up.';
    notify_send((int)$buyerId,'buyer',$title,$msg,(int)$listingId,'transaction');
  }
  echo json_encode(['ok'=>true]);
  exit;
}

?>

<!DOCTYPE html>
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
    .panel{background:#fff;border-radius:10px;max-width:1200px;width:98vw;max-height:95vh;overflow:auto;padding:20px}
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

  <!-- Report Buyer Modal -->
  <div id="reportBuyerModal" class="modal">
    <div class="panel">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;">
        <h2 style="margin:0;">Report Buyer</h2>
        <button class="close-btn" data-close="reportBuyerModal">Close</button>
      </div>
      <div style="margin-top:8px;display:grid;gap:10px;">
        <input type="hidden" id="repBuyerId" />
        <div>
          <label style="display:block;font-weight:600;margin-bottom:4px;">Title</label>
          <input type="text" id="repTitle" style="width:100%;padding:8px;border:1px solid #e2e8f0;border-radius:6px;" />
        </div>
        <div>
          <label style="display:block;font-weight:600;margin-bottom:4px;">Description</label>
          <textarea id="repDesc" style="width:100%;min-height:120px;padding:8px;border:1px solid #e2e8f0;border-radius:6px;"></textarea>
        </div>
        <div style="display:flex;gap:8px;align-items:center;">
          <button class="btn" id="btnSubmitReportBuyer">Submit Report</button>
          <span id="repMsg" class="subtle"></span>
        </div>
      </div>
    </div>
  </div>

  <div id="rateModalSeller" class="modal">
    <div class="panel">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;">
        <h2 style="margin:0;">Rate Buyer</h2>
        <button class="close-btn" data-close="rateModalSeller">Close</button>
      </div>
      <div style="margin-top:8px;">
        <div class="subtle" style="margin-bottom:8px;">Please rate your experience with this buyer.</div>
        <div id="rateStarsSeller" style="font-size:28px;color:#e2e8f0;cursor:pointer;user-select:none;">
          <span data-v="1">★</span>
          <span data-v="2">★</span>
          <span data-v="3">★</span>
          <span data-v="4">★</span>
          <span data-v="5">★</span>
        </div>
        <textarea id="rateDescSeller" placeholder="Optional description (max 255 chars)" maxlength="255" style="margin-top:10px;width:100%;min-height:90px;padding:10px;border:1px solid #e2e8f0;border-radius:8px;"></textarea>
        <div style="margin-top:10px;display:flex;gap:8px;align-items:center;">
          <button class="btn" id="btnSubmitRatingSeller">Submit Rating</button>
          <span class="subtle" id="rateMsgSeller"></span>
        </div>
      </div>
      <div style="display:flex;gap:8px;align-items:center;">
        <button class="btn" id="btnSubmitReportBuyer">Submit Report</button>
        <span id="repMsg" class="subtle"></span>
      </div>
    </div>
  </div>
</div>

<!-- ... (rest of the code remains the same) -->

<script>
  // ... (rest of the code remains the same)

  // Report Buyer
  var repBtn = document.getElementById('btnReportBuyer');
  if (repBtn){
    repBtn.addEventListener('click', function(){
      var buyerId = data.buyer_id || '';
      var hid = document.getElementById('repBuyerId'); if (hid) hid.value = String(buyerId||'');
      openModal('reportBuyerModal');
    });
  }

  // ... (rest of the code remains the same)

  (function(){
    var btn = document.getElementById('btnSubmitReportBuyer');
    if (btn){
      btn.addEventListener('click', function(){
        var buyerId = parseInt((document.getElementById('repBuyerId')||{}).value||'0',10) || 0;
        var title = (document.getElementById('repTitle')||{}).value||'';
        var desc = (document.getElementById('repDesc')||{}).value||'';
        var msg = document.getElementById('repMsg'); if (msg){ msg.textContent=''; }
        if (title.trim()==='' || desc.trim()===''){ if (msg){ msg.textContent='Please fill in title and description.'; msg.style.color='#e53e3e'; } return; }
        var fd = new FormData();
        fd.append('action','report_user');
        fd.append('seller_id','<?php echo (int)$userId; ?>');
        fd.append('buyer_id', String(buyerId));
        fd.append('title', title);
        fd.append('description', desc);
        btn.disabled = true; var old = btn.textContent; btn.textContent='Submitting...';
        fetch('transactions.php', { method:'POST', body: fd, credentials:'same-origin' })
          .then(function(r){ return r.json(); })
          .then(function(res){
            btn.disabled = false; btn.textContent = old;
            if (res && res.ok){ if (msg){ msg.textContent='Thank you for helping make our community better.'; msg.style.color='#38a169'; }
              setTimeout(function(){ closeModal('reportBuyerModal'); closeModal('txModal'); }, 800);
            } else { if (msg){ msg.textContent='Failed: '+(res && res.error? res.error : 'Unknown error'); msg.style.color='#e53e3e'; } }
          })
          .catch(function(err){ btn.disabled=false; btn.textContent=old; if (msg){ msg.textContent='Network error: '+err.message; msg.style.color='#e53e3e'; } });
      });
    }
  })();
</script>
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
                alert('Transaction completed successfully as ' + (res.result || 'successful') + '.');
                btnSubmitComplete.disabled = true; btnSubmitComplete.textContent = 'Saved';
                closeModal('completeModal');
                closeModal('txModal');
                window.location.href = 'completion.php';
              }
            })
            .catch(function(err){
              console.error('Error completing transaction:', err);
              alert('Error: Failed to complete transaction');
              btnSubmitComplete.disabled = false; btnSubmitComplete.textContent = 'Submit';
            });
        });
      }

      // Seller rating stars
      var currentRatingSeller = 5;
      function paintStarsSeller(n){
        var stars = document.querySelectorAll('#rateStarsSeller span');
        Array.prototype.forEach.call(stars, function(s){ var v=parseInt(s.getAttribute('data-v'),10); s.style.color = (v<=n)? '#f59e0b' : '#e2e8f0'; });
      }
      paintStarsSeller(currentRatingSeller);
      var starsWrapSeller = document.getElementById('rateStarsSeller');
      if (starsWrapSeller){
        starsWrapSeller.addEventListener('click', function(e){ var v=parseInt(e.target.getAttribute('data-v'),10); if(!isNaN(v)){ currentRatingSeller = v; paintStarsSeller(currentRatingSeller); } });
      }
      var btnSubmitRatingSeller = document.getElementById('btnSubmitRatingSeller');
      if (btnSubmitRatingSeller){
        btnSubmitRatingSeller.addEventListener('click', function(){
          var rm = document.getElementById('rateModalSeller');
          var buyerId = parseInt(rm.getAttribute('data-buyer')||'0',10) || 0;
          var txid = parseInt(rm.getAttribute('data-transaction')||'0',10) || 0;
          if (!(currentRatingSeller>=1 && currentRatingSeller<=5)) { alert('Rating must be 1-5'); return; }
          var fd = new FormData();
          fd.append('action','rate_buyer');
          fd.append('seller_id','<?php echo (int)($_SESSION['user_id'] ?? 0); ?>');
          fd.append('buyer_id', String(buyerId));
          fd.append('transaction_id', String(txid));
          fd.append('rating', String(currentRatingSeller));
          fd.append('description', (document.getElementById('rateDescSeller')||{}).value||'');
          btnSubmitRatingSeller.disabled = true; btnSubmitRatingSeller.textContent = 'Submitting...';
          fetch('transactions.php', { method:'POST', body: fd, credentials:'same-origin' })
            .then(function(r){ return r.json(); })
            .then(function(res){
              if (!res || res.ok===false){
                alert('Failed to submit rating'+(res && res.code? (' (code '+res.code+')') : ''));
                btnSubmitRatingSeller.disabled = false; btnSubmitRatingSeller.textContent = 'Submit Rating';
              } else {
                btnSubmitRatingSeller.textContent = 'Thanks!';
                setTimeout(function(){ closeModal('rateModalSeller'); }, 600);
              }
            })
            .catch(function(){ btnSubmitRatingSeller.disabled = false; btnSubmitRatingSeller.textContent = 'Submit Rating'; });
        });
      }
    })();
  </script>
  <script>
    (function(){
      var btn = document.getElementById('btnSubmitReportBuyer');
      if (btn){
        btn.addEventListener('click', function(){
          var buyerId = parseInt((document.getElementById('repBuyerId')||{}).value||'0',10) || 0;
          var title = (document.getElementById('repTitle')||{}).value||'';
          var desc = (document.getElementById('repDesc')||{}).value||'';
          var msg = document.getElementById('repMsg'); if (msg){ msg.textContent=''; }
          if (title.trim()==='' || desc.trim()===''){ if (msg){ msg.textContent='Please fill in title and description.'; msg.style.color='#e53e3e'; } return; }
          var fd = new FormData();
          fd.append('action','report_user');
          fd.append('seller_id','<?php echo (int)$userId; ?>');
          fd.append('buyer_id', String(buyerId));
          fd.append('title', title);
          fd.append('description', desc);
          btn.disabled = true; var old = btn.textContent; btn.textContent='Submitting...';
          fetch('transactions.php', { method:'POST', body: fd, credentials:'same-origin' })
            .then(function(r){ return r.json(); })
            .then(function(res){
              btn.disabled = false; btn.textContent = old;
              if (res && res.ok){ if (msg){ msg.textContent='Thank you for helping make our community better.'; msg.style.color='#38a169'; }
                setTimeout(function(){ closeModal('reportBuyerModal'); closeModal('txModal'); }, 800);
              } else { if (msg){ msg.textContent='Failed: '+(res && res.error? res.error : 'Unknown error'); msg.style.color='#e53e3e'; } }
            })
            .catch(function(err){ btn.disabled=false; btn.textContent=old; if (msg){ msg.textContent='Network error: '+err.message; msg.style.color='#e53e3e'; } });
        });
      }
    })();
  </script>
</body>
</html>
