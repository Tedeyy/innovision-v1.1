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

  // Insert into reviewreportuser using reporter/reported schema
  $payload = [[
    'reporter_id'   => $sellerIdIn,
    'reporter_role' => 'Seller',
    'reported_id'   => $buyerIdIn,
    'reported_role' => 'Buyer',
    'title'         => $title,
    'description'   => $description,
    'Status'        => 'Pending'
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
      'report_id'      => $res[0]['report_id'] ?? null,
      'reporter_id'    => $sellerIdIn,
      'reporter_role'  => 'Seller',
      'reported_id'    => $buyerIdIn,
      'reported_role'  => 'Buyer',
      'title'          => $title,
      'description'    => $description,
      'admin_id'       => $admin_id,
      'Status'         => 'Pending',
      'created'        => date('Y-m-d H:i:s')
    ]];
    sb_rest('POST','userreport_logs',[], $log, ['Prefer: return=minimal']);
  }
  echo json_encode(['ok'=>true]);
  exit;
}

// Seller confirms show for an ongoing transaction
if (isset($_POST['action']) && $_POST['action']==='confirm_show'){
  header('Content-Type: application/json');
  $txId       = isset($_POST['transaction_id']) ? (int)$_POST['transaction_id'] : 0;
  $sellerIdIn = isset($_POST['seller_id']) ? (int)$_POST['seller_id'] : 0;
  $buyerIdIn  = isset($_POST['buyer_id']) ? (int)$_POST['buyer_id'] : 0;
  $batIdIn    = isset($_POST['bat_id']) ? (int)$_POST['bat_id'] : null;
  $decision   = isset($_POST['decision']) ? (string)$_POST['decision'] : 'Confirm';
  if (!in_array($decision,['Confirm','Reschedule','Waiting'],true)) $decision = 'Confirm';

  if (!$txId || $sellerIdIn!==$userId){
    echo json_encode(['ok'=>false,'error'=>'invalid_params']);
    exit;
  }

  // Check if a confirmation row already exists for this seller + transaction
  [$rows,$st,$err] = sb_rest('GET','show_confirmation',[
    'select'=>'confirmation_id',
    'transaction_id'=>'eq.'.$txId,
    'seller_id'=>'eq.'.$sellerIdIn,
    'limit'=>1
  ]);
  if (!($st>=200 && $st<300) || !is_array($rows)) $rows = [];

  $now = date('Y-m-d H:i:s');

  if (!empty($rows) && isset($rows[0]['confirmation_id'])){
    // Update existing row
    [$uRes,$uSt,$uErr] = sb_rest('PATCH','show_confirmation',[
      'transaction_id'=>'eq.'.$txId,
      'seller_id'=>'eq.'.$sellerIdIn
    ],[
      'confirm_seller'=>$decision,
      'confirmed_seller'=>$now
    ]);
    if (!($uSt>=200 && $uSt<300)){
      echo json_encode(['ok'=>false,'error'=>'update_failed','code'=>$uSt]);
      exit;
    }
  } else {
    // Insert new row
    $payload = [[
      'transaction_id'=>$txId,
      'seller_id'=>$sellerIdIn,
      'buyer_id'=>$buyerIdIn ?: null,
      'bat_id'=>$batIdIn,
      'confirm_seller'=>$decision,
      'confirmed_seller'=>$now
    ]];
    [$iRes,$iSt,$iErr] = sb_rest('POST','show_confirmation',[], $payload, ['Prefer: return=minimal']);
    if (!($iSt>=200 && $iSt<300)){
      echo json_encode(['ok'=>false,'error'=>'insert_failed','code'=>$iSt]);
      exit;
    }
  }
  echo json_encode(['ok'=>true]);
  exit;
}

// Seller suggests a meet-up for an ongoing transaction
if (isset($_POST['action']) && $_POST['action']==='request_meetup'){
  header('Content-Type: application/json');
  $txId       = isset($_POST['transaction_id']) ? (int)$_POST['transaction_id'] : 0;
  $sellerIdIn = isset($_POST['seller_id']) ? (int)$_POST['seller_id'] : 0;
  $dateIn     = isset($_POST['date']) ? trim((string)$_POST['date']) : '';
  $timeIn     = isset($_POST['time']) ? trim((string)$_POST['time']) : '';
  $locationIn = isset($_POST['location']) ? trim((string)$_POST['location']) : '';
  $descIn     = isset($_POST['description']) ? trim((string)$_POST['description']) : '';

  if (!$txId || $sellerIdIn!==$userId || $dateIn==='' || $timeIn==='' || $locationIn===''){
    echo json_encode(['ok'=>false,'error'=>'invalid_params']);
    exit;
  }

  $dtStr = $dateIn.' '.$timeIn;
  $ts = strtotime($dtStr);
  if ($ts===false){
    echo json_encode(['ok'=>false,'error'=>'invalid_datetime']);
    exit;
  }
  $dtFormatted = date('Y-m-d H:i:s',$ts);

  $payload = [[
    'transaction_id'=>$txId,
    'user_id'=>$sellerIdIn,
    'user_role'=>'Seller',
    'transaction_date'=>$dtFormatted,
    'transaction_location'=>$locationIn,
    'description'=>$descIn
  ]];
  [$res,$st,$err] = sb_rest('POST','meetup_request',[], $payload, ['Prefer: return=minimal']);
  if (!($st>=200 && $st<300)){
    echo json_encode(['ok'=>false,'error'=>'insert_failed','code'=>$st]);
    exit;
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

if (isset($_GET['action']) && $_GET['action']==='has_report'){
  header('Content-Type: application/json');
  $buyerIdIn = isset($_GET['buyer_id']) ? (int)$_GET['buyer_id'] : 0;
  if ($buyerIdIn<=0){
    echo json_encode(['ok'=>false,'error'=>'missing_params']);
    exit;
  }
  // Has this seller already reported this buyer?
  [$rows,$status,$error] = sb_rest('GET','reviewreportuser',[
    'select'=>'report_id',
    'reporter_id'=>'eq.'.$userId,
    'reported_id'=>'eq.'.$buyerIdIn,
    'limit'=>1
  ]);
  if (!($status>=200 && $status<300) || !is_array($rows)) $rows = [];
  echo json_encode(['ok'=>true,'has_report'=>(count($rows)>0)]);
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
  
  // Add thumbnail data and extras to each transaction
  foreach ($allTransactions as &$tx) {
    if (isset($tx['listing_id']) && isset($tx['seller_id'])) {
      $listingId = (int)$tx['listing_id'];
      $sellerId = (int)$tx['seller_id'];
      $seller = $tx['seller'] ?? [];
      
      // Build Supabase public image URLs for this listing
      // Use created_at if available, otherwise fallback to created
      $createdRaw = $tx['listing']['created_at'] ?? ($tx['listing']['created'] ?? '');
      $createdKey = $createdRaw ? date('YmdHis', strtotime($createdRaw)) : '';
      $fullName = trim(implode(' ', [
        $seller['user_fname'] ?? '',
        $seller['user_mname'] ?? '',
        $seller['user_lname'] ?? ''
      ]));
      $sanitized = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $fullName));
      $sanitized = trim($sanitized, '_');
      $folder = $sellerId . '_' . ($sanitized !== '' ? $sanitized : 'user');
      $base = rtrim(sb_base_url(), '/');

      $thumbs = [];
      if ($base && $createdKey !== ''){
        for ($i=1; $i<=3; $i++){
          $thumbs[] = $base.'/storage/v1/object/public/listings/verified/'.$folder.'/'.$createdKey.'_'.$i.'img.jpg';
        }
      }
      $tx['thumbs'] = $thumbs;
      $tx['thumb'] = $thumbs[0] ?? '';
      $tx['thumb_fallback'] = $thumbs[1] ?? ($thumbs[0] ?? '');
      
      // Add meet-up details from ongoingtransactions
      if (isset($tx['transaction_date'])) {
        $tx['meetup_date'] = date('Y-m-d', strtotime($tx['transaction_date']));
        $tx['meetup_time'] = date('H:i', strtotime($tx['transaction_date']));
      } else {
        $tx['meetup_date'] = null;
        $tx['meetup_time'] = null;
      }
      
      $tx['meetup_location'] = $tx['transaction_location'] ?? null;
      
      // Flag if a meetup_request already exists for this transaction (for this seller)
    }
      // Add BAT fullname
      if (isset($tx['bat'])) {
        $bat = $tx['bat'];
        $tx['bat_fullname'] = trim(($bat['user_fname']??'').' '.($bat['user_mname']??'').' '.($bat['user_lname']??''));
      } else {
        $tx['bat_fullname'] = null;
      }
    }
  }

  echo json_encode(['ok'=>true,'items'=>$allTransactions]);
  exit;
}

// Move Started -> Ongoing and notify buyer
if (isset($_POST['action']) && $_POST['action']==='schedule_meetup'){
  header('Content-Type: application/json');
  $txId     = isset($_POST['transaction_id']) ? (int)$_POST['transaction_id'] : 0;
  $listingId = isset($_POST['listing_id']) ? (int)$_POST['listing_id'] : 0;
  $sellerId = isset($_POST['seller_id']) ? (int)$_POST['seller_id'] : 0;
  $buyerId = isset($_POST['buyer_id']) ? (int)$_POST['buyer_id'] : 0;
  
  if (!$txId || !$listingId || !$sellerId || !$buyerId || $sellerId !== $userId){
    echo json_encode(['ok'=>false,'error'=>'invalid_params']);
    exit;
  }
  
  // If an ongoing row already exists for this transaction+seller, treat as success (idempotent)
  [$ongoRows,$ongoSt,$ongoErr] = sb_rest('GET','ongoingtransactions',[
    'select'=>'transaction_id',
    'transaction_id'=>'eq.'.$txId,
    'seller_id'=>'eq.'.$sellerId,
    'limit'=>1
  ]);
  if ($ongoSt>=200 && $ongoSt<300 && is_array($ongoRows) && !empty($ongoRows)){
    echo json_encode(['ok'=>true]);
    exit;
  }

  // Look up the start transaction by transaction_id and seller
  [$srows,$sst,$sse] = sb_rest('GET','starttransactions',[
    'select'=>'*',
    'transaction_id'=>'eq.'.$txId,
    'seller_id'=>'eq.'.$sellerId,
    'limit'=>1
  ]);
  if (!($sst>=200 && $sst<300) || !is_array($srows) || empty($srows)){
    echo json_encode(['ok'=>false,'error'=>'transaction_not_found']);
    exit;
  }
  $transaction = $srows[0];

  [$ores,$ost,$ose] = sb_rest('POST','ongoingtransactions',[],[
    [
      'transaction_id'=>$transaction['transaction_id'],
      'listing_id'=>$transaction['listing_id'],
      'seller_id'=>$transaction['seller_id'],
      'buyer_id'=>$transaction['buyer_id'],
      'status'=>'Ongoing',
      'started_at'=>$transaction['started_at']
    ]
  ]);
  if (!($ost>=200 && $ost<300)){
    $detail = '';
    if (is_array($ores) && isset($ores['message'])) {
      $detail = $ores['message'];
    } elseif (is_string($ores) && $ores!=='') {
      $detail = $ores;
    }
    echo json_encode(['ok'=>false,'error'=>'insert_ongoing_failed','code'=>$ost,'detail'=>$detail]);
    exit;
  }

  [$dres,$dst,$dse] = sb_rest('DELETE','starttransactions',[],[],[
    'transaction_id'=>'eq.'.$txId,
    'seller_id'=>'eq.'.$sellerId
  ]);
  if (!($dst>=200 && $dst<300)){
    echo json_encode(['ok'=>false,'error'=>'delete_start_failed','code'=>$dst]);
    exit;
  }

  if ($buyerId){
    $title = 'Transaction Ongoing';
    $msg = 'Your transaction (listing #'.$listingId.') is now ongoing. BAT will schedule the meet-up.';
    notify_send((int)$buyerId,'buyer',$title,$msg,(int)$listingId,'transaction');
  }
  echo json_encode(['ok'=>true]);
  exit;
}

?>

<html lang="en">
<head>
  <!-- ... (rest of the code remains the same) -->
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Seller Transactions</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../style/dashboard.css">
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />
  <style>
    .modal{position:fixed;inset:0;background:rgba(0,0,0,0.5);display:none;align-items:center;justify-content:center;z-index:9999}
    /* Ensure Report Buyer modal appears above Transaction Details modal */
    #reportBuyerModal{z-index:10001}
    /* Ensure Rate Buyer modal appears above most */
    #rateModalSeller{z-index:10002}
    /* Ensure Confirm Attendance modal appears above all */
    #confirmShowModal{z-index:10003}
    .panel{background:#fff;border-radius:10px;max-width:900px;width:95vw;max-height:90vh;overflow:auto;padding:16px}
    .subtle{color:#4a5568;font-size:12px}
    .table{width:100%;border-collapse:collapse}
    .table th,.table td{padding:8px;text-align:left}
    .table thead tr{border-bottom:1px solid #e2e8f0}
    .close-btn{background:#e53e3e;color:#fff;border:none;border-radius:6px;padding:6px 10px;cursor:pointer}

    /* Mobile adjustments */
    @media (max-width: 640px) {
      body { font-size: 12px; }

      .wrap {
        padding: 8px;
      }

      .top h1 {
        font-size: 18px;
      }

      .btn {
        font-size: 12px;
        padding: 6px 10px;
      }

      .table th,
      .table td {
        padding: 4px 6px;
        font-size: 11px;
        white-space: nowrap;
      }

      .table {
        display: block;
        overflow-x: auto;
      }

      .panel {
        max-width: 100vw;
        width: 96vw;
        padding: 10px;
      }

      #txBody h3 {
        font-size: 14px;
      }

      #txBody div {
        font-size: 12px;
      }

      #rateStarsSeller {
        font-size: 22px;
      }

      textarea,
      input[type="text"] {
        font-size: 12px;
      }
    }
  </style>
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
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

  <!-- Rate Buyer Modal -->
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
    </div>
  </div>

  <!-- Confirm Attendance Modal -->
  <div id="confirmShowModal" class="modal">
    <div class="panel">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;">
        <h2 style="margin:0;">Confirm Attendance</h2>
        <button class="close-btn" data-close="confirmShowModal">Close</button>
      </div>
      <div style="margin-top:8px;">
        <div class="subtle" style="margin-bottom:8px;">Confirm that you will attend the scheduled meet-up, or request a new schedule.</div>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
          <button class="btn" id="btnConfirmShowSubmit">Confirm</button>
          <button class="btn" id="btnRequestResched">Request Reschedule</button>
          <span class="subtle" id="confirmShowMsg"></span>
        </div>
      </div>
    </div>
  </div>

  <!-- Suggest Meet-Up Modal -->
  <div id="meetupRequestModal" class="modal">
    <div class="panel">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;">
        <h2 style="margin:0;">Suggest Meet-Up</h2>
        <button class="close-btn" data-close="meetupRequestModal">Close</button>
      </div>
      <div style="margin-top:8px;display:grid;gap:8px;">
        <div style="display:flex;gap:8px;">
          <div style="flex:1;">
            <label style="display:block;font-weight:600;margin-bottom:4px;">Date</label>
            <input type="date" id="mrDate" style="width:100%;padding:8px;border:1px solid #e2e8f0;border-radius:6px;" />
          </div>
          <div style="flex:1;">
            <label style="display:block;font-weight:600;margin-bottom:4px;">Time</label>
            <input type="time" id="mrTime" style="width:100%;padding:8px;border:1px solid #e2e8f0;border-radius:6px;" />
          </div>
        </div>
        <div>
          <label style="display:block;font-weight:600;margin-bottom:4px;">Location</label>
          <input type="text" id="mrLocation" placeholder="Enter meet-up location" style="width:100%;padding:8px;border:1px solid #e2e8f0;border-radius:6px;" />
        </div>
        <div id="meetupRequestMap" style="margin-top:4px;width:100%;height:220px;border-radius:8px;overflow:hidden;border:1px solid #e2e8f0;"></div>
        <div>
          <label style="display:block;font-weight:600;margin-bottom:4px;">Description</label>
          <textarea id="mrDesc" style="width:100%;min-height:80px;padding:8px;border:1px solid #e2e8f0;border-radius:6px;" placeholder="Optional instructions or notes"></textarea>
        </div>
        <div style="display:flex;gap:8px;align-items:center;">
          <button class="btn" id="btnSubmitMeetupRequest">Submit Request</button>
          <span class="subtle" id="meetupRequestMsg"></span>
        </div>
      </div>
    </div>
  </div>

  <!-- Transaction Details Modal -->
  <div id="txModal" class="modal">
    <div class="panel">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;">
        <h2 style="margin:0;">Transaction Details</h2>
        <button class="close-btn" data-close="txModal">Close</button>
      </div>
      <div id="txBody" style="margin-top:8px;"></div>
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

      function formatStarted(ts){
        if (!ts) return '';
        var d = new Date(ts);
        if (isNaN(d.getTime())) return ts; // fallback if parse fails
        var hh = String(d.getHours()).padStart(2,'0');
        var mm = String(d.getMinutes()).padStart(2,'0');
        var month = String(d.getMonth()+1).padStart(2,'0');
        var day = String(d.getDate()).padStart(2,'0');
        var year = d.getFullYear();
        return hh+':'+mm+' '+month+'/'+day+'/'+year;
      }
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
            var items = Array.isArray(res && res.items) ? res.items : [];
            items.forEach(function(row){
              var buyer = row.buyer||{}; var listing = row.listing||{};
              var tr = document.createElement('tr');
              tr.innerHTML =
                '<td>'+ fullname(buyer) +'</td>'+
                '<td>'+(listing.livestock_type||'')+' • '+(listing.breed||'')+'</td>'+
                '<td>'+ statusBadge(row.status) +'</td>'+
                '<td>'+ (row.started_at ? new Date(row.started_at).toLocaleString('en-GB', {hour: '2-digit', minute: '2-digit', day: '2-digit', month: '2-digit', year: 'numeric'}).replace(',', '') : '') +'</td>'+
                '<td><button class="btn btn-show" data-row="'+encodeURIComponent(JSON.stringify(row))+'">Show</button></td>';
              tb.appendChild(tr);
            });
          });
      }

      // Confirm Attendance submit
      var btnConfirmShowSubmit = document.getElementById('btnConfirmShowSubmit');
      if (btnConfirmShowSubmit){
        btnConfirmShowSubmit.addEventListener('click', function(){
          var modal = document.getElementById('confirmShowModal');
          var msgEl = document.getElementById('confirmShowMsg');
          if (msgEl) msgEl.textContent = '';
          if (!modal) return;
          var txId = modal.getAttribute('data-transaction')||'';
          var sellerId = modal.getAttribute('data-seller')||'';
          var buyerId = modal.getAttribute('data-buyer')||'';
          var batId = modal.getAttribute('data-bat')||'';
          if (!txId || !sellerId){
            if (msgEl) msgEl.textContent = 'Missing transaction data.';
            return;
          }
          var fd = new FormData();
          fd.append('action','confirm_show');
          fd.append('transaction_id', txId);
          fd.append('seller_id', sellerId);
          if (buyerId) fd.append('buyer_id', buyerId);
          if (batId) fd.append('bat_id', batId);
          btnConfirmShowSubmit.disabled = true;
          fetch('transactions.php', { method:'POST', body: fd, credentials:'same-origin' })
            .then(function(r){ return r.json(); })
            .then(function(res){
              btnConfirmShowSubmit.disabled = false;
              if (!res || res.ok===false){
                if (msgEl) msgEl.textContent = 'Failed to confirm.';
              } else {
                if (msgEl) msgEl.textContent = 'Confirmed.';
                closeModal('confirmShowModal');
                // Disable button in parent modal if present
                var btn = document.getElementById('btnConfirmShow');
                if (btn){ btn.disabled = true; btn.textContent = 'Confirmed'; }
              }
            })
            .catch(function(err){
              btnConfirmShowSubmit.disabled = false;
              if (msgEl) msgEl.textContent = 'Network error.';
            });
        });
      }

      // Request Reschedule from Confirm Attendance
      var btnRequestResched = document.getElementById('btnRequestResched');
      if (btnRequestResched){
        btnRequestResched.addEventListener('click', function(){
          var confirmModal = document.getElementById('confirmShowModal');
          var msgEl = document.getElementById('confirmShowMsg');
          if (msgEl) msgEl.textContent = '';
          if (!confirmModal) return;
          var txId = confirmModal.getAttribute('data-transaction')||'';
          var sellerId = confirmModal.getAttribute('data-seller')||'';
          var buyerId = confirmModal.getAttribute('data-buyer')||'';
          var batId = confirmModal.getAttribute('data-bat')||'';
          if (!txId || !sellerId) return;

          // First, mark seller confirmation as Reschedule
          var fd = new FormData();
          fd.append('action','confirm_show');
          fd.append('transaction_id', txId);
          fd.append('seller_id', sellerId);
          if (buyerId) fd.append('buyer_id', buyerId);
          if (batId) fd.append('bat_id', batId);
          fd.append('decision','Reschedule');
          btnRequestResched.disabled = true;
          fetch('transactions.php', { method:'POST', body: fd, credentials:'same-origin' })
            .then(function(r){ return r.json(); })
            .then(function(res){
              btnRequestResched.disabled = false;
              if (!res || res.ok===false){
                if (msgEl) msgEl.textContent = 'Failed to mark as reschedule.';
              } else {
                // Then open meetup request modal to suggest new schedule
                var mrModal = document.getElementById('meetupRequestModal');
                if (mrModal){
                  mrModal.setAttribute('data-transaction', txId);
                  mrModal.setAttribute('data-seller', sellerId);
                  var msg = document.getElementById('meetupRequestMsg'); if (msg) msg.textContent='';
                  closeModal('confirmShowModal');
                  openModal('meetupRequestModal');
                }
              }
            })
            .catch(function(err){
              btnRequestResched.disabled = false;
              if (msgEl) msgEl.textContent = 'Network error.';
            });
        });
      }

      // Suggest Meet-Up interactive map + submit
      var btnSubmitMeetupRequest = document.getElementById('btnSubmitMeetupRequest');
      if (btnSubmitMeetupRequest){
        var mrLocation = document.getElementById('mrLocation');
        var mrMap = document.getElementById('meetupRequestMap');
        var meetupMapInstance = null;
        var meetupMarker = null;

        function ensureMeetupMap(){
          if (!mrMap) return;
          if (!window.L){
            // Leaflet not yet loaded; try again shortly
            setTimeout(ensureMeetupMap, 100);
            return;
          }
          if (!meetupMapInstance){
            meetupMapInstance = L.map(mrMap).setView([8.314209 , 124.859425], 13);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
              maxZoom: 19,
              attribution: '&copy; OpenStreetMap contributors'
            }).addTo(meetupMapInstance);
            meetupMapInstance.on('click', function(ev){
              var lat = ev.latlng.lat;
              var lng = ev.latlng.lng;
              if (mrLocation){
                mrLocation.value = lat.toFixed(6)+','+lng.toFixed(6);
              }
              if (!meetupMarker){
                meetupMarker = L.marker(ev.latlng).addTo(meetupMapInstance);
              } else {
                meetupMarker.setLatLng(ev.latlng);
              }
            });
          } else {
            setTimeout(function(){ try{ meetupMapInstance.invalidateSize(); }catch(e){} }, 0);
          }

          // If location already has lat,lng, reflect on map
          if (mrLocation && mrLocation.value && meetupMapInstance){
            var parts = mrLocation.value.split(',');
            if (parts.length === 2){
              var lat = parseFloat(parts[0]);
              var lng = parseFloat(parts[1]);
              if (!isNaN(lat) && !isNaN(lng)){
                var ll = [lat,lng];
                meetupMapInstance.setView(ll, 14);
                if (!meetupMarker){
                  meetupMarker = L.marker(ll).addTo(meetupMapInstance);
                } else {
                  meetupMarker.setLatLng(ll);
                }
              }
            }
          }
        }

        // Expose for external callers (e.g., when opening the modal)
        window.ensureMeetupMap = ensureMeetupMap;

        // If user manually edits lat,lng string, sync marker
        if (mrLocation && mrMap){
          mrLocation.addEventListener('change', function(){
            if (!mrLocation.value || !meetupMapInstance) return;
            var parts = mrLocation.value.split(',');
            if (parts.length === 2){
              var lat = parseFloat(parts[0]);
              var lng = parseFloat(parts[1]);
              if (!isNaN(lat) && !isNaN(lng)){
                var ll = [lat,lng];
                meetupMapInstance.setView(ll, 14);
                if (!meetupMarker){
                  meetupMarker = L.marker(ll).addTo(meetupMapInstance);
                } else {
                  meetupMarker.setLatLng(ll);
                }
              }
            }
          });
        }

        btnSubmitMeetupRequest.addEventListener('click', function(){
          var modal = document.getElementById('meetupRequestModal');
          var msgEl = document.getElementById('meetupRequestMsg');
          if (msgEl) msgEl.textContent = '';
          if (!modal) return;
          var txId = modal.getAttribute('data-transaction')||'';
          var sellerId = modal.getAttribute('data-seller')||'';
          var dEl = document.getElementById('mrDate');
          var tEl = document.getElementById('mrTime');
          var lEl = document.getElementById('mrLocation');
          var descEl = document.getElementById('mrDesc');
          var dateVal = dEl ? dEl.value : '';
          var timeVal = tEl ? tEl.value : '';
          var locVal = lEl ? lEl.value.trim() : '';
          var descVal = descEl ? descEl.value.trim() : '';
          if (!txId || !sellerId || !dateVal || !timeVal || !locVal){
            if (msgEl) msgEl.textContent = 'Please fill date, time and location.';
            return;
          }
          var fd = new FormData();
          fd.append('action','request_meetup');
          fd.append('transaction_id', txId);
          fd.append('seller_id', sellerId);
          fd.append('date', dateVal);
          fd.append('time', timeVal);
          fd.append('location', locVal);
          fd.append('description', descVal);
          btnSubmitMeetupRequest.disabled = true;
          fetch('transactions.php', { method:'POST', body: fd, credentials:'same-origin' })
            .then(function(r){ return r.json(); })
            .then(function(res){
              btnSubmitMeetupRequest.disabled = false;
              if (!res || res.ok===false){
                if (msgEl) msgEl.textContent = 'Failed to submit request.';
              } else {
                if (msgEl) msgEl.textContent = 'Request sent.';
                closeModal('meetupRequestModal');
              }
            })
            .catch(function(err){
              btnSubmitMeetupRequest.disabled = false;
              if (msgEl) msgEl.textContent = 'Network error.';
            });
        });
      }
      load();

      document.addEventListener('click', function(e){
        if (e.target && e.target.classList.contains('btn-show')){
          var data = JSON.parse(decodeURIComponent(e.target.getAttribute('data-row')||'{}'));
          var seller = data.seller||{}; var buyer = data.buyer||{}; var listing = data.listing||{};
          var body = document.getElementById('txBody');
          function initials(p){ var f=(p.user_fname||'').trim(), l=(p.user_lname||'').trim(); var s=(f?f[0]:'')+(l?l[0]:''); return s.toUpperCase()||'U'; }
          function avatarHTML(p){ var ini=initials(p); return '<div style="width:40px;height:40px;border-radius:50%;background:#edf2f7;color:#2d3748;display:flex;align-items:center;justify-content:center;font-weight:600;">'+ini+'</div>'; }

          var meetupInfo = '';
          if (data.meetup_date || data.meetup_time || data.meetup_location || data.bat_fullname){
            meetupInfo = '<h3 style="margin-top:10px;">Meet-up Details</h3>'+
              '<div>Date: '+(data.meetup_date||'N/A')+'</div>'+
              '<div>Time: '+(data.meetup_time||'N/A')+'</div>'+
              '<div>Location: '+(data.meetup_location||'N/A')+'</div>'+
              '<div>BAT: '+(data.bat_fullname||'N/A')+'</div>'+
              '<div id="meetupMap" style="margin-top:8px;width:100%;height:220px;border-radius:8px;overflow:hidden;border:1px solid #e2e8f0;"></div>';
          }

          body.innerHTML = ''+
            '<h3>Listing</h3>'+
            '<div style="display:flex;gap:12px;align-items:flex-start;">'+
              '<div><strong>'+(listing.livestock_type||'')+' • '+(listing.breed||'')+'</strong><div>Price: ₱'+(listing.price||'')+'</div><div>Address: '+(listing.address||'')+'</div><div class="subtle">Listing #'+(listing.listing_id||'')+' • Created '+(listing.created||'')+'</div></div>'+
              '<div id="imgWrap"></div>'+
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
            meetupInfo+
            '<div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap;">'+
              '<button class="btn" id="btnSuggestMeetup"'+'>'+'</button>'+ 
              '<button class="btn" id="btnSetMeetup">Set Meet-Up</button>'+ 
              '<button class="btn" id="btnConfirmShow">Confirm Attendance</button>'+ 
              '<button class="btn" id="btnRateBuyer">Rate Buyer</button>'+ 
              '<button class="btn" id="btnReportBuyer" style="background:#ef4444;color:#fff;">Report Buyer</button>'+ 
            '</div>';

          var wrap = document.getElementById('imgWrap');
          if (wrap){
            var imgs = (data.thumbs && data.thumbs.length) ? data.thumbs : (data.thumb ? [data.thumb] : []);
            imgs.forEach(function(url){
              if (!url) return;
              var im = document.createElement('img');
              im.src = url;
              im.alt = 'listing';
              im.style.width = '160px';
              im.style.height = '160px';
              im.style.objectFit = 'cover'
              im.style.border = '1px solid #e2e8f0';
              im.style.borderRadius = '8px';
              im.style.marginRight = '6px';
              im.onerror = function(){ im.style.display='none'; };
              wrap.appendChild(im);
            });
          }
          var meetupMap = document.getElementById('meetupMap');
          if (meetupMap && data.meetup_location){
            var iframe = document.createElement('iframe');
            iframe.src = 'https://www.google.com/maps?q='+encodeURIComponent(data.meetup_location)+'&output=embed';
            iframe.style.border = '0';
            iframe.style.width = '100%';
            iframe.style.height = '100%';
            iframe.setAttribute('loading','lazy');
            meetupMap.appendChild(iframe);
          }
          openModal('txModal');

          var statusLower = String(data.status||'').toLowerCase();
          var isCompleted = statusLower==='completed';
          var isStarted = statusLower==='started';
          var isOngoing = statusLower==='ongoing';
          var rateBtn = document.getElementById('btnRateBuyer');
          var reportBtn = document.getElementById('btnReportBuyer');
          var meetBtn = document.getElementById('btnSetMeetup');
          var btnConfirmShow = document.getElementById('btnConfirmShow');
          var btnSuggestMeetup = document.getElementById('btnSuggestMeetup');
          if (rateBtn){
            if (!isCompleted){
              // Only show Rate Buyer for completed transactions
              rateBtn.style.display = 'none';
            } else {
              rateBtn.style.display = '';
              rateBtn.disabled = false;
              rateBtn.textContent = 'Rate Buyer';
            }
          }
          // Report Buyer is allowed for all statuses by default
          if (reportBtn){
            reportBtn.disabled = false;
            reportBtn.textContent = 'Report Buyer';
          }
          if (meetBtn){ meetBtn.disabled = !isStarted; }

          var hasMeetupDetails = !!(data.meetup_date || data.meetup_time || data.meetup_location);
          if (btnConfirmShow){
            btnConfirmShow.style.display = (isOngoing && hasMeetupDetails) ? '' : 'none';
          }
          if (btnSuggestMeetup){
            btnSuggestMeetup.style.display = (isOngoing && !hasMeetupDetails) ? '' : 'none';
          }

          // Check if already rated / reported and update buttons
          var txId = data.transaction_id || 0;
          var buyerId = data.buyer_id || 0;

          if (rateBtn && isCompleted && txId){
            fetch('transactions.php?action=has_rating&transaction_id='+encodeURIComponent(txId), { credentials:'same-origin' })
              .then(function(r){ return r.json(); })
              .then(function(resp){
                if (resp && resp.ok && resp.has_rating){
                  rateBtn.disabled = true;
                  rateBtn.textContent = 'Rated';
                }
              })
              .catch(function(){});
          }

          if (reportBtn && buyerId){
            fetch('transactions.php?action=has_report&buyer_id='+encodeURIComponent(buyerId), { credentials:'same-origin' })
              .then(function(r){ return r.json(); })
              .then(function(resp){
                if (resp && resp.ok && resp.has_report){
                  reportBtn.disabled = true;
                  reportBtn.textContent = 'Reported';
                }
              })
              .catch(function(){});
          }

          if (rateBtn){
            rateBtn.addEventListener('click', function(){
              if (!isCompleted) return;
              if (rateBtn.disabled) return;
              var rm = document.getElementById('rateModalSeller');
              rm.setAttribute('data-buyer', String(data.buyer_id||''));
              rm.setAttribute('data-transaction', String(data.transaction_id||''));
              openModal('rateModalSeller');
            });
          }

          if (reportBtn){
            reportBtn.addEventListener('click', function(){
              if (reportBtn.disabled) return;
              var hid = document.getElementById('repBuyerId');
              if (hid) hid.value = String(data.buyer_id||'');
              openModal('reportBuyerModal');
            });
          }

          if (btnConfirmShow){
            btnConfirmShow.addEventListener('click', function(){
              if (btnConfirmShow.disabled) return;
              var modal = document.getElementById('confirmShowModal');
              if (modal){
                modal.setAttribute('data-transaction', String(data.transaction_id||''));
                modal.setAttribute('data-seller', String(data.seller_id||''));
                modal.setAttribute('data-buyer', String(data.buyer_id||''));
                modal.setAttribute('data-bat', String((data.bat_id||'') || ''));
              }
              openModal('confirmShowModal');
            });
          }

          if (btnSuggestMeetup){
            btnSuggestMeetup.addEventListener('click', function(){
              var modal = document.getElementById('meetupRequestModal');
              if (modal){
                modal.setAttribute('data-transaction', String(data.transaction_id||''));
                modal.setAttribute('data-seller', String(data.seller_id||''));
              }
              var msg = document.getElementById('meetupRequestMsg'); if (msg) msg.textContent='';
              openModal('meetupRequestModal');
              if (typeof ensureMeetupMap === 'function') { ensureMeetupMap(); }
            });
          }

          if (meetBtn){
            meetBtn.addEventListener('click', function(){
              if (!isStarted) return;
              if (!confirm('Set this transaction as ongoing and request BAT to schedule the meet-up?')) return;
              var fd = new FormData();
              fd.append('action','schedule_meetup');
              fd.append('transaction_id', String(data.transaction_id||''));
              fd.append('listing_id', String(data.listing_id||''));
              fd.append('seller_id', String(data.seller_id||''));
              fd.append('buyer_id', String(data.buyer_id||''));
              meetBtn.disabled = true; meetBtn.textContent = 'Setting...';
              fetch('transactions.php', { method:'POST', body: fd, credentials:'same-origin' })
                .then(function(r){ return r.json(); })
                .then(function(res){
                  meetBtn.disabled = false; meetBtn.textContent = 'Set Meet-Up';
                  if (!res || res.ok===false){
                    var msg = 'Failed to set meet-up';
                    if (res && res.code) msg += ' (code '+res.code+')';
                    if (res && res.detail) msg += '\n'+res.detail;
                    alert(msg);
                  } else {
                    alert('Transaction moved to Ongoing. BAT will schedule the meet-up.');
                    closeModal('txModal');
                    load();
                  }
                })
                .catch(function(err){
                  meetBtn.disabled = false; meetBtn.textContent = 'Set Meet-Up';
                  alert('Network error: '+err.message);
                });
            });
          }
        }
      });

      ['txModal','rateModalSeller','reportBuyerModal'].forEach(function(id){ var el=document.getElementById(id); if(el){ el.addEventListener('click', function(ev){ if(ev.target===el) closeModal(id); }); }});
      ['confirmShowModal','meetupRequestModal'].forEach(function(id){ var el=document.getElementById(id); if(el){ el.addEventListener('click', function(ev){ if(ev.target===el) closeModal(id); }); }});

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
                setTimeout(function(){ closeModal('rateModalSeller'); closeModal('txModal'); }, 600);
              }
            })
            .catch(function(){ btnSubmitRatingSeller.disabled = false; btnSubmitRatingSeller.textContent = 'Submit Rating'; });
        });
      }

      // Submit report
      var btnSubmitReportBuyer = document.getElementById('btnSubmitReportBuyer');
      if (btnSubmitReportBuyer){
        btnSubmitReportBuyer.addEventListener('click', function(){
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
          btnSubmitReportBuyer.disabled = true; var old = btnSubmitReportBuyer.textContent; btnSubmitReportBuyer.textContent='Submitting...';
          fetch('transactions.php', { method:'POST', body: fd, credentials:'same-origin' })
            .then(function(r){ return r.json(); })
            .then(function(res){
              btnSubmitReportBuyer.disabled = false; btnSubmitReportBuyer.textContent = old;
              if (res && res.ok){ if (msg){ msg.textContent='Thank you for helping make our community better.'; msg.style.color='#38a169'; }
                setTimeout(function(){ closeModal('reportBuyerModal'); closeModal('txModal'); }, 800);
              } else { if (msg){ msg.textContent='Failed: '+(res && res.error? res.error : 'Unknown error'); msg.style.color='#e53e3e'; } }
            })
            .catch(function(err){ btnSubmitReportBuyer.disabled=false; btnSubmitReportBuyer.textContent=old; if (msg){ msg.textContent='Network error: '+err.message; msg.style.color='#e53e3e'; } });
        });
      }
    })();
  </script>
</body>
</html>