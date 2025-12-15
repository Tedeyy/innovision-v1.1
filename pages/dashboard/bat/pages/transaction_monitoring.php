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
  
  // Check if transaction already has date/time and location (reschedule case)
  [$existingTx,$txStatus,$txError] = sb_rest('GET','ongoingtransactions',[
    'select'=>'transaction_date,transaction_location,bat_id',
    'transaction_id'=>'eq.'.$txId,
    'limit'=>1
  ]);
  
  $isReschedule = false;
  if ($txStatus>=200 && $txStatus<300 && is_array($existingTx) && !empty($existingTx)){
    $existing = $existingTx[0];
    if (!empty($existing['transaction_date']) && !empty($existing['transaction_location'])){
      $isReschedule = true;
    }
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
  
  // If rescheduling, remove existing schedule entry and confirmation record
  if ($isReschedule) {
    // Remove existing schedule entry
    [$delSched,$delStatus,$delError] = sb_rest('DELETE','schedule',[
      'bat_id'=>'eq.'.$batId,
      'month'=>'eq.'.date('m', strtotime($dt)),
      'day'=>'eq.'.date('d', strtotime($dt))
    ], null, ['Prefer: return=minimal']);
    
    // Remove confirmation record for this transaction
    [$delConf,$delConfStatus,$delConfError] = sb_rest('DELETE','show_confirmation',[
      'transaction_id'=>'eq.'.$txId
    ], null, ['Prefer: return=minimal']);
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
  
  $title = ($isReschedule ? 'Reschedule: ' : 'Meet-up: ') . ($livestockInfo ? $livestockInfo : 'Listing #' . $listingId);
  
  $sellerName = ($seller && $seller['user_fname']) ? trim($seller['user_fname'] . ' ' . ($seller['user_lname']||'')) : 'Seller #' . $sellerId;
  $buyerName = ($buyer && $buyer['user_fname']) ? trim($buyer['user_fname'] . ' ' . ($buyer['user_lname']||'')) : 'Buyer #' . $buyerId;
  $address = ($listing && $listing['address']) ? $listing['address'] : 'Address not specified';
  
  $desc = 'Transaction: ' . $sellerName . ' × ' . $buyerName . 
          ' | Listing #' . $listingId . 
          ' | Meet-up at: ' . $loc . 
          ' | Original Address: ' . $address;
  
  if ($isReschedule) {
    $desc .= ' | RESCHEDULED';
  }
  
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
  // Notify seller about scheduled/rescheduled meet-up
  $title = $isReschedule ? 'Meet-up Rescheduled' : 'Meet-up Scheduled';
  $msg = 'BAT '.($isReschedule ? 'rescheduled' : 'scheduled').': '.date('M j, Y g:i A', strtotime($dt)).' at '.$loc;
  notify_send((int)$sellerId, 'seller', $title, $msg, (int)$listingId, 'meetup');
  echo json_encode(['ok'=>true,'warning'=> (count($warnings)? implode('; ', $warnings) : null)]); exit;
}

if (isset($_GET['action']) && $_GET['action']==='meetup_requests'){
  header('Content-Type: application/json');
  $txId = isset($_GET['transaction_id']) ? (int)$_GET['transaction_id'] : 0;
  if (!$txId){ echo json_encode(['ok'=>false,'error'=>'missing_params']); exit; }
  [$rows,$st,$err] = sb_rest('GET','meetup_request',[
    'select'=>'transaction_id,user_id,user_role,transaction_date,transaction_location,description',
    'transaction_id'=>'eq.'.$txId
  ]);
  if (!($st>=200 && $st<300) || !is_array($rows)){
    echo json_encode(['ok'=>false,'error'=>'load_failed','code'=>$st]);
    exit;
  }
  echo json_encode(['ok'=>true,'data'=>$rows]);
  exit;
}

if (isset($_POST['action']) && $_POST['action']==='apply_meetup_request'){
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
  $upd = [
    'bat_id'=>$batId,
    'transaction_date'=>$dt,
    'transaction_location'=>$loc,
  ];
  [$ur,$us,$ue] = sb_rest('PATCH','ongoingtransactions',[ 'transaction_id'=>'eq.'.$txId ], [$upd]);
  if (!($us>=200 && $us<300)){
    echo json_encode(['ok'=>false,'error'=>'update_failed','code'=>$us]); exit;
  }
  echo json_encode(['ok'=>true]);
  exit;
}

if (isset($_POST['action']) && $_POST['action']==='deny_meetup_request'){
  header('Content-Type: application/json');
  $txId = isset($_POST['transaction_id']) ? (int)$_POST['transaction_id'] : 0;
  $userId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
  $dt = isset($_POST['transaction_date']) ? (string)$_POST['transaction_date'] : '';
  if (!$txId || !$userId || $dt===''){
    echo json_encode(['ok'=>false,'error'=>'missing_params']); exit;
  }
  [$dr,$ds,$de] = sb_rest('DELETE','meetup_request',[
    'transaction_id'=>'eq.'.$txId,
    'user_id'=>'eq.'.$userId,
    'transaction_date'=>'eq.'.$dt
  ], null, ['Prefer: return=minimal']);
  if (!($ds>=200 && $ds<300)){
    echo json_encode(['ok'=>false,'error'=>'delete_failed','code'=>$ds]); exit;
  }
  echo json_encode(['ok'=>true]);
  exit;
}

// Inline action: get confirmation status for a transaction
if (isset($_GET['action']) && $_GET['action']==='get_confirmation'){
  header('Content-Type: application/json');
  $txId = isset($_GET['transaction_id']) ? (int)$_GET['transaction_id'] : 0;
  if (!$txId){ echo json_encode(['ok'=>false,'error'=>'missing_transaction_id']); exit; }
  
  [$rows,$st,$err] = sb_rest('GET','show_confirmation',[
    'select'=>'*',
    'transaction_id'=>'eq.'.$txId
  ]);
  
  if (!($st>=200 && $st<300) || !is_array($rows)){
    echo json_encode(['ok'=>false,'error'=>'load_failed','code'=>$st]); exit;
  }
  
  $confirmation = !empty($rows) ? $rows[0] : null;
  echo json_encode(['ok'=>true,'data'=>$confirmation]);
  exit;
}

// Inline action: confirm attendance as BAT
if (isset($_POST['action']) && $_POST['action']==='confirm_attendance_bat'){
  header('Content-Type: application/json');
  $txId = isset($_POST['transaction_id']) ? (int)$_POST['transaction_id'] : 0;
  if (!$txId){ echo json_encode(['ok'=>false,'error'=>'missing_transaction_id']); exit; }
  
  $now = date('Y-m-d H:i:s');
  
  // Check if confirmation record exists
  [$existing,$exSt,$exErr] = sb_rest('GET','show_confirmation',[
    'select'=>'*',
    'transaction_id'=>'eq.'.$txId,
    'limit'=>1
  ]);
  
  if ($exSt>=200 && $exSt<300 && is_array($existing) && !empty($existing)){
    // Update existing record - only update bat_id, confirm_bat, and confirmed_bat
    [$upd,$upSt,$upErr] = sb_rest('PATCH','show_confirmation',[
      'transaction_id'=>'eq.'.$txId
    ],[[
      'bat_id'=>$batId,
      'confirm_bat'=>'Confirmed',
      'confirmed_bat'=>$now
    ]]);
    
    if (!($upSt>=200 && $upSt<300)){
      echo json_encode(['ok'=>false,'error'=>'update_failed','code'=>$upSt,'detail'=>$upErr]); exit;
    }
  } else {
    // Create new record - only include required fields
    [$ins,$inSt,$inErr] = sb_rest('POST','show_confirmation',[],[[
      'transaction_id'=>$txId,
      'bat_id'=>$batId,
      'confirm_bat'=>'Confirmed',
      'confirmed_bat'=>$now
    ]]);
    
    if (!($inSt>=200 && $inSt<300)){
      echo json_encode(['ok'=>false,'error'=>'insert_failed','code'=>$inSt,'detail'=>$inErr]); exit;
    }
  }
  
  echo json_encode(['ok'=>true]);
  exit;
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
  $serviceRoleKey = sb_env('SUPABASE_SERVICE_ROLE_KEY') ?: '';
  $adminHeaders = $serviceRoleKey ? ['Authorization: Bearer '.$serviceRoleKey] : [];
  if (!$serviceRoleKey){ echo json_encode(['ok'=>false,'error'=>'supabase_service_role_key_missing']); exit; }
  // Fetch the ongoing transaction for details
  [$txRows,$txStatus,$txError] = sb_rest('GET','ongoingtransactions',[
    'select'=>'*', 'transaction_id'=>'eq.'.$txId, 'listing_id'=>'eq.'.$listingId, 'seller_id'=>'eq.'.$sellerId, 'buyer_id'=>'eq.'.$buyerId, 'limit'=>1
  ], null, $adminHeaders);
  if (!($txStatus>=200 && $txStatus<300) || !is_array($txRows) || empty($txRows)){
    echo json_encode(['ok'=>false,'error'=>'transaction_not_found']); exit;
  }
  $tx = $txRows[0];
  $now = date('Y-m-d H:i:s');

  if ($result === 'successful') {
    // Completed transaction
    [$existingComp,$compExistStatus,$compExistError] = sb_rest('GET','completedtransactions',[
      'select'=>'transaction_id',
      'transaction_id'=>'eq.'.$txId,
      'limit'=>1
    ], null, $adminHeaders);

    $compPayload = [[
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
    ]];

    if ($compExistStatus>=200 && $compExistStatus<300 && is_array($existingComp) && !empty($existingComp)){
      [$compRes,$compSt,$compErr] = sb_rest('PATCH','completedtransactions',[
        'transaction_id'=>'eq.'.$txId,
        'select'=>'transaction_id'
      ],$compPayload, array_merge($adminHeaders, ['Prefer: return=representation']));
    } else {
      [$compRes,$compSt,$compErr] = sb_rest('POST','completedtransactions',[
        'select'=>'transaction_id'
      ],$compPayload, array_merge($adminHeaders, ['Prefer: return=representation']));
    }

    if (!($compSt>=200 && $compSt<300)){
      echo json_encode(['ok'=>false,'error'=>'completedtransactions_write_failed','code'=>$compSt,'detail'=>$compErr]); exit;
    }

    // Successful transaction
    $succPayload = [[
      'transaction_id'=>$txId,
      'listing_id'=>$listingId,
      'seller_id'=>$sellerId,
      'buyer_id'=>$buyerId,
      'price'=>$price,
      'payment_method'=>$paymentMethod,
      'status'=>'Successful',
      'transaction_date'=>$now
    ]];

    [$succRes,$succSt,$succErr] = sb_rest('POST','successfultransactions',[
      'select'=>'transaction_id,listing_id'
    ],$succPayload, array_merge($adminHeaders, ['Prefer: return=representation']));
    if (!($succSt>=200 && $succSt<300)){
      echo json_encode(['ok'=>false,'error'=>'successfultransactions_insert_failed','code'=>$succSt,'detail'=>$succErr]); exit;
    }

    // Move listing from activelivestocklisting to soldlivestocklisting
    [$activeList,$listSt,$listErr] = sb_rest('GET','activelivestocklisting',[
      'select'=>'*',
      'listing_id'=>'eq.'.$listingId,
      'limit'=>1
    ], null, $adminHeaders);
    if (!($listSt>=200 && $listSt<300) || !is_array($activeList) || empty($activeList)){
      echo json_encode(['ok'=>false,'error'=>'activelivestocklisting_not_found']); exit;
    }
    $listingData = $activeList[0];

    [$soldRes,$soldSt,$soldErr] = sb_rest('POST','soldlivestocklisting',[
      'select'=>'seller_id'
    ],[[
      'seller_id'=>$listingData['seller_id'],
      'livestock_type'=>$listingData['livestock_type'],
      'breed'=>$listingData['breed'],
      'address'=>$listingData['address'],
      'age'=>$listingData['age'],
      'weight'=>$listingData['weight'],
      'price'=>$listingData['price'],
      'soldprice'=>$price,
      'status'=>'Sold',
      'created'=>$listingData['created']
    ]], array_merge($adminHeaders, ['Prefer: return=representation']));
    if (!($soldSt>=200 && $soldSt<300)){
      echo json_encode(['ok'=>false,'error'=>'soldlivestocklisting_insert_failed','code'=>$soldSt,'detail'=>$soldErr]); exit;
    }

    // Log listing move
    [$livestockLogRes,$livestockLogSt,$livestockLogErr] = sb_rest('POST','livestocklisting_logs',[],[[
      'seller_id'=>$listingData['seller_id'],
      'livestock_type'=>$listingData['livestock_type'],
      'breed'=>$listingData['breed'],
      'address'=>$listingData['address'],
      'age'=>$listingData['age'],
      'weight'=>$listingData['weight'],
      'price'=>$listingData['price'],
      'status'=>'Sold',
      'created'=>$listingData['created']
    ]], null, $adminHeaders);
    if (!($livestockLogSt>=200 && $livestockLogSt<300)){
      echo json_encode(['ok'=>false,'error'=>'livestocklisting_logs_insert_failed','code'=>$livestockLogSt,'detail'=>$livestockLogErr]); exit;
    }

    // Delete active listing
    [$delActiveRes,$delActiveSt,$delActiveErr] = sb_rest('DELETE','activelivestocklisting',[
      'listing_id'=>'eq.'.$listingId
    ], null, array_merge($adminHeaders, ['Prefer: return=minimal']));
    if (!($delActiveSt>=200 && $delActiveSt<300)){
      echo json_encode(['ok'=>false,'error'=>'activelivestocklisting_delete_failed','code'=>$delActiveSt,'detail'=>$delActiveErr]); exit;
    }

    // Transfer pins and log
    [$activePins,$pinsSt,$pinsErr] = sb_rest('GET','activelocation_pins',[
      'select'=>'*',
      'listing_id'=>'eq.'.$listingId
    ], null, $adminHeaders);
    if ($pinsSt>=200 && $pinsSt<300 && is_array($activePins)) {
      foreach ($activePins as $pin) {
        [$pinRes,$pinSt,$pinErr] = sb_rest('POST','soldlocation_pins',[],[[
          'location'=>$pin['location'],
          'listing_id'=>$pin['listing_id'],
          'status'=>'Sold',
          'created_at'=>$pin['created_at']
        ]], null, $adminHeaders);
        if (!($pinSt>=200 && $pinSt<300)){
          echo json_encode(['ok'=>false,'error'=>'soldlocation_pins_insert_failed','code'=>$pinSt,'detail'=>$pinErr]); exit;
        }

        [$logRes,$logSt,$logErr] = sb_rest('POST','location_pin_logs',[],[[
          'location'=>$pin['location'],
          'listing_id'=>$pin['listing_id'],
          'status'=>'Sold',
          'created_at'=>$pin['created_at']
        ]], null, $adminHeaders);
        if (!($logSt>=200 && $logSt<300)){
          echo json_encode(['ok'=>false,'error'=>'location_pin_logs_insert_failed','code'=>$logSt,'detail'=>$logErr]); exit;
        }
      }

      [$delPinsRes,$delPinsSt,$delPinsErr] = sb_rest('DELETE','activelocation_pins',[
        'listing_id'=>'eq.'.$listingId
      ], null, array_merge($adminHeaders, ['Prefer: return=minimal']));
      if (!($delPinsSt>=200 && $delPinsSt<300)){
        echo json_encode(['ok'=>false,'error'=>'activelocation_pins_delete_failed','code'=>$delPinsSt,'detail'=>$delPinsErr]); exit;
      }
    }

    // Transaction log
    [$tlogRes,$tlogSt,$tlogErr] = sb_rest('POST','transactions_logs',[],[[
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
    ]], null, $adminHeaders);
    if (!($tlogSt>=200 && $tlogSt<300)){
      echo json_encode(['ok'=>false,'error'=>'transactions_logs_insert_failed','code'=>$tlogSt,'detail'=>$tlogErr]); exit;
    }

    // Remove from ongoingtransactions
    [$delTxRes,$delTxSt,$delTxErr] = sb_rest('DELETE','ongoingtransactions',[
      'transaction_id'=>'eq.'.$txId
    ], null, array_merge($adminHeaders, ['Prefer: return=minimal']));
    if (!($delTxSt>=200 && $delTxSt<300)){
      echo json_encode(['ok'=>false,'error'=>'ongoingtransactions_delete_failed','code'=>$delTxSt,'detail'=>$delTxErr]); exit;
    }

    echo json_encode(['ok'=>true]); exit;
  }

  // Failed flow
  [$failRes,$failSt,$failErr] = sb_rest('POST','failedtransactions',[
    'select'=>'transaction_id'
  ],[[
    'transaction_id'=>$txId,
    'listing_id'=>$listingId,
    'seller_id'=>$sellerId,
    'buyer_id'=>$buyerId,
    'price'=>$price,
    'payment_method'=>$paymentMethod,
    'status'=>'Failed',
    'transaction_date'=>$now
  ]], array_merge($adminHeaders, ['Prefer: return=representation']));
  if (!($failSt>=200 && $failSt<300)){
    echo json_encode(['ok'=>false,'error'=>'failedtransactions_insert_failed','code'=>$failSt,'detail'=>$failErr]); exit;
  }

  [$tlogRes,$tlogSt,$tlogErr] = sb_rest('POST','transactions_logs',[],[[
    'transaction_id'=>$txId,
    'listing_id'=>$listingId,
    'seller_id'=>$sellerId,
    'buyer_id'=>$buyerId,
    'status'=>'Failed',
    'started_at'=>$tx['started_at'] ?? null,
    'bat_id'=>$batId,
    'transaction_date'=>$now,
    'transaction_location'=>$tx['transaction_location'] ?? null,
    'completed_transaction'=>$now
  ]], null, $adminHeaders);
  if (!($tlogSt>=200 && $tlogSt<300)){
    echo json_encode(['ok'=>false,'error'=>'transactions_logs_insert_failed','code'=>$tlogSt,'detail'=>$tlogErr]); exit;
  }

  [$delTxRes,$delTxSt,$delTxErr] = sb_rest('DELETE','ongoingtransactions',[
    'transaction_id'=>'eq.'.$txId
  ], null, array_merge($adminHeaders, ['Prefer: return=minimal']));
  if (!($delTxSt>=200 && $delTxSt<300)){
    echo json_encode(['ok'=>false,'error'=>'ongoingtransactions_delete_failed','code'=>$delTxSt,'detail'=>$delTxErr]); exit;
  }

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

// Prepare data for service coverage map
$coveragePins = ['activePins' => [], 'soldPins' => []];

// Helper to parse location strings
$parse_loc_pair = function($locStr) {
    $lat = null; $lng = null;
    if (!$locStr) { return [null, null]; }
    $j = json_decode($locStr, true);
    if (is_array($j)) {
        if (isset($j['lat']) && isset($j['lng'])) {
            return [(float)$j['lat'], (float)$j['lng']];
        }
        if (isset($j[0]) && isset($j[1])) {
            return [(float)$j[0], (float)$j[1]];
        }
    }
    if (strpos($locStr, ',') !== false) {
        $parts = explode(',', $locStr, 2);
        $lat = (float)trim($parts[0]);
        $lng = (float)trim($parts[1]);
        return [$lat, $lng];
    }
    return [null, null];
};

// Build index of active listings
[$activeList, $alSt, $alErr] = sb_rest('GET', 'activelivestocklisting', [
    'select' => 'listing_id,livestock_type,breed,price,address,created,seller_id',
    'limit' => 1000
]);
if (!($alSt >= 200 && $alSt < 300) || !is_array($activeList)) { $activeList = []; }
$activeIndex = [];
foreach ($activeList as $row) {
    $lid = (int)($row['listing_id'] ?? 0);
    if ($lid <= 0) { continue; }
    $activeIndex[$lid] = [
        'type'    => $row['livestock_type'] ?? '',
        'breed'   => $row['breed'] ?? '',
        'price'   => $row['price'] ?? '',
        'address' => $row['address'] ?? '',
        'created' => $row['created'] ?? '',
        'seller_id'=> (int)($row['seller_id'] ?? 0)
    ];
}

// Active pins
[$ap, $as, $ae] = sb_rest('GET', 'activelocation_pins', [
    'select' => 'pin_id,location,listing_id,status',
    'limit' => 1000
]);
if (!($as >= 200 && $as < 300) || !is_array($ap)) { $ap = []; }
foreach ($ap as $p) {
    $lid = (int)($p['listing_id'] ?? 0);
    if (!isset($activeIndex[$lid])) { continue; }
    [$la, $ln] = $parse_loc_pair($p['location'] ?? '');
    if ($la === null || $ln === null) { continue; }
    $meta = $activeIndex[$lid];
    $coveragePins['activePins'][] = [
        'pin_id'     => (int)($p['pin_id'] ?? 0),
        'listing_id' => $lid,
        'lat'        => (float)$la,
        'lng'        => (float)$ln,
        'type'       => $meta['type'],
        'breed'      => $meta['breed'],
        'price'      => $meta['price'],
        'address'    => $meta['address'],
        'created'    => $meta['created'],
        'seller_id'  => $meta['seller_id']
    ];
}

// Build index of sold listings
[$soldList, $slSt, $slErr] = sb_rest('GET', 'activelivestocklisting', [
    'select' => 'listing_id,livestock_type,breed,price,address,created,seller_id',
    'status' => 'eq.Sold',
    'limit' => 1000
]);
if (!($slSt >= 200 && $slSt < 300) || !is_array($soldList)) { $soldList = []; }
$soldIndex = [];
foreach ($soldList as $row) {
    $lid = (int)($row['listing_id'] ?? 0);
    if ($lid <= 0) { continue; }
    $soldIndex[$lid] = [
        'type'     => $row['livestock_type'] ?? '',
        'breed'    => $row['breed'] ?? '',
        'price'    => $row['price'] ?? '',
        'address'  => $row['address'] ?? '',
        'created'  => $row['created'] ?? '',
        'seller_id'=> (int)($row['seller_id'] ?? 0)
    ];
}

// Sold pins
[$sp, $ss, $se] = sb_rest('GET', 'soldlocation_pins', [
    'select' => 'pin_id,location,listing_id,status',
    'limit' => 1000
]);
if (!($ss >= 200 && $ss < 300) || !is_array($sp)) { $sp = []; }
foreach ($sp as $p) {
    $lid = (int)($p['listing_id'] ?? 0);
    if (!isset($soldIndex[$lid])) { continue; }
    [$la, $ln] = $parse_loc_pair($p['location'] ?? '');
    if ($la === null || $ln === null) { continue; }
    $meta = $soldIndex[$lid];
    $coveragePins['soldPins'][] = [
        'pin_id'     => (int)($p['pin_id'] ?? 0),
        'listing_id' => $lid,
        'lat'        => (float)$la,
        'lng'        => (float)$ln,
        'type'       => $meta['type'],
        'breed'      => $meta['breed'],
        'price'      => $meta['price'],
        'address'    => $meta['address'],
        'created'    => $meta['created'],
        'seller_id'  => $meta['seller_id']
    ];
}
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
    /* Scrollable table styles */
    .table-container{max-height:400px;overflow-y:auto;overflow-x:hidden;border:1px solid #e2e8f0;border-radius:8px;margin-bottom:16px}
    .table-container .table{margin:0;border:none;width:100%}
    .table-container thead{position:sticky;top:0;background:#ffffff;z-index:10;border-bottom:2px solid #e2e8f0}
    .table-container thead th{background:#ffffff}
    .table-container tbody{overflow-y:scroll}
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
      /* Attendance confirmation mobile styles */
      #attendanceContent{font-size:11px}
      #attendanceContent h3{font-size:12px}
      #attendanceContent .table{font-size:10px}
      #attendanceContent .table th,#attendanceContent .table td{padding:2px 4px}
      #attendanceContent .table th{width:80px !important}
      #attendanceContent .table th:nth-child(2){width:auto !important}
      #attendanceContent .table th:nth-child(3){width:60px !important}
      #attendanceContent .table th:nth-child(4){width:70px !important}
      #attendanceContent span{font-size:8px;padding:1px 3px}
      #btnConfirmAttendance{padding:4px 8px;font-size:10px}
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
      <div class="table-container">
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
              <tr><td colspan="5" style="color:#4a5568;">No started transactions.</td></tr>
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
    </div>

    <div class="card section">
      <h2 style="margin:0 0 8px 0;">Ongoing</h2>
      <?php if (!$ongo['ok']): ?>
        <div style="margin:6px 0;padding:8px;border:1px solid #fecaca;background:#fef2f2;color:#7f1d1d;border-radius:8px;">Failed to load ongoing transactions (code <?php echo (int)$ongo['code']; ?>)</div>
      <?php endif; ?>
      <div class="table-container">
        <table class="table">
          <thead>
            <tr>
              <th>Seller</th>
              <th>Buyer</th>
              <th>Status</th>
              <th>Timestamp</th>
              <th>BAT Name</th>
              <th>Meet-up Date</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php $ongoRows = $ongo['rows'] ?? []; ?>
            <?php if (count($ongoRows)===0): ?>
              <tr><td colspan="7" style="color:#4a5568;">No ongoing transactions.</td></tr>
            <?php else: foreach ($ongoRows as $r): 
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
                <td><button class="btn btn-show" data-row="<?php echo esc(json_encode($r)); ?>">Show</button></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card section">
      <h2 style="margin:0 0 8px 0;">Completed</h2>
      <?php if (!$done['ok']): ?>
        <div style="margin:6px 0;padding:8px;border:1px solid #fecaca;background:#fef2f2;color:#7f1d1d;border-radius:8px;">Failed to load completed transactions (code <?php echo (int)$done['code']; ?>)</div>
      <?php endif; ?>
      <div class="table-container">
        <table class="table">
          <thead>
            <tr>
              <th>Seller</th>
              <th>Buyer</th>
              <th>Status</th>
              <th>BAT Name</th>
              <th>Completed</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php $doneRows = $done['rows'] ?? []; ?>
            <?php if (count($doneRows)===0): ?>
              <tr><td colspan="6" style="color:#4a5568;">No completed transactions.</td></tr>
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
                <td><?php echo esc($batName); ?></td>
                <td><?php echo esc(($r['completed_transaction'] ?? '') ? date('H/i d/m/Y', strtotime($r['completed_transaction'])) : ''); ?></td>
                <td><button class="btn btn-show" data-row="<?php echo esc(json_encode($r)); ?>">Show</button></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
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
      $all('.close-btn').forEach(function(b){ 
        b.addEventListener('click', function(){ 
          var modalId = b.getAttribute('data-close');
          if (modalId === 'batCompleteModal') {
            closeModal(modalId);
          } else if (modalId === 'txModal') {
            // Also close batCompleteModal if it's open
            var batCompleteModal = document.getElementById('batCompleteModal');
            if (batCompleteModal && batCompleteModal.style.display !== 'none') {
              closeModal('batCompleteModal');
            }
            destroyTxMap(); 
            var body=document.getElementById('txBody'); 
            if(body) body.innerHTML=''; 
            closeModal(modalId);
          } else {
            destroyTxMap(); 
            var body=document.getElementById('txBody'); 
            if(body) body.innerHTML=''; 
            closeModal(modalId);
          }
        }); 
      });
      document.addEventListener('click', function(e){
        if (e.target && e.target.classList.contains('btn-show')){
          var data = {};
          try{ data = JSON.parse(e.target.getAttribute('data-row')||'{}'); }catch(_){ data={}; }
          var statusVal = (data && (data.status || data.Status) ? (data.status || data.Status) : '').toString().trim();
          var statusLower = statusVal.toLowerCase();
          var isStartingStarted = (statusLower === 'starting' || statusLower === 'started');
          var isOngoing = !isStartingStarted;
          var isCompleted = !!(data && (data.completed_transaction || data.completed_Transaction));
          var canEditMeetup = (!isCompleted && !isStartingStarted);
          document.getElementById('txTitle').textContent = (isCompleted? 'Completed' : (isStartingStarted? 'Started' : 'Ongoing')) + ' Transaction #'+(data.transaction_id||'');
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
                      '<div class="subtle" style="margin-top:4px;">Listing #'+(listing.listing_id||'')+' • '+(listing.created ? new Date(listing.created).toLocaleString('en-GB', { hour: '2-digit', minute: '2-digit', day: '2-digit', month: '2-digit', year: 'numeric' }).replace(',', '') : '')+'</div>'+
                    '</div>'+
                  '</div>'+
                  '<hr style="margin:12px 0;border:none;border-top:1px solid #e2e8f0" />'+
                  '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">'+
                    '<div><div style="font-weight:600;">Seller</div><div>'+fullname(seller)+'</div><div>Email: '+(seller.email||'')+'</div><div>Contact: '+(seller.contact||'')+'</div></div>'+
                    '<div><div style="font-weight:600;">Buyer</div><div>'+fullname(buyer)+'</div><div>Email: '+(buyer.email||'')+'</div><div>Contact: '+(buyer.contact||'')+'</div></div>'+
                  '</div>'+
                '</div>'+
                (isCompleted || !canEditMeetup ? (
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
                    '<div style="margin-bottom:8px;color:#4a5568;font-size:12px;">💡 Click anywhere on map to set meet-up location</div>'+
                    '<div id="txMap" style="height:260px;border:1px solid #e2e8f0;border-radius:8px;cursor:crosshair;" title="Click anywhere on map to set meet-up location"></div>'+
                    '<div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap;">'+
                      '<button class="btn" id="btnSaveTx">Save Meet-up Details</button>'+
                      '<button class="btn" id="btnCompleteTx" style="background:#10b981;color:#fff;">Complete Transaction</button>'+
                      '<span id="saveStatus" style="color:#4a5568;font-size:12px;"></span>'+
                    '</div>'+
                    '<div id="sellerMeetupWrap" style="margin-top:16px;">'+
                      '<div style="font-weight:600;font-size:14px;margin-bottom:4px;">Seller suggested meet-ups</div>'+
                      '<div id="sellerMeetupEmpty" class="subtle">Loading...</div>'+
                      '<table id="sellerMeetupTable" class="table" style="display:none;font-size:12px;margin-top:4px;">'+
                        '<thead><tr><th>Date & Time</th><th>Location</th><th>Description</th><th>Actions</th></tr></thead>'+
                        '<tbody></tbody>'+ 
                      '</table>'+ 
                    '</div>'+
                    '<div style="margin-top:20px;padding-top:16px;border-top:1px solid #e2e8f0;">'+
                      '<h3 style="margin:0 0 12px 0;font-size:16px;color:#2d3748;">Attendance Confirmation</h3>'+
                      '<div id="attendanceLoading" class="subtle">Loading attendance status...</div>'+
                      '<div id="attendanceContent" style="display:none;">'+
                        '<table class="table" style="margin-top:8px;">'+
                          '<thead>'+
                            '<tr>'+
                              '<th style="width:120px;">Role</th>'+
                              '<th>Full Name</th>'+
                              '<th style="width:100px;">Status</th>'+
                              '<th style="width:120px;">Confirmed At</th>'+
                            '</tr>'+
                          '</thead>'+
                          '<tbody id="attendanceBody">'+
                          '</tbody>'+
                        '</table>'+
                      '</div>'+
                    '</div>'+
                  '</div>'
                ));
              txBody.innerHTML = bodyHtml;
              // Open modal first so map can compute size
              openModal('txModal');
              // Auto-scroll to listing details after modal opens
              setTimeout(function(){
                var modal = document.getElementById('txModal');
                if (modal) {
                  var firstCard = modal.querySelector('.card');
                  if (firstCard) {
                    firstCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
                  }
                }
              }, 100);
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

                if (canEditMeetup){
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
              
              var saveBtn = document.getElementById('btnSaveTx');
              var saveStatus = document.getElementById('saveStatus');
              if (canEditMeetup && saveBtn && saveStatus) {
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
              if (canEditMeetup) {
                var wrap = document.getElementById('sellerMeetupWrap');
                var emptyEl = document.getElementById('sellerMeetupEmpty');
                var table = document.getElementById('sellerMeetupTable');
                if (wrap && emptyEl && table) {
                  fetch('transaction_monitoring.php?action=meetup_requests&transaction_id='+(data.transaction_id||''), { credentials:'same-origin' })
                    .then(function(r){ return r.json(); })
                    .then(function(res){
                      var tbody = table.querySelector('tbody');
                      tbody.innerHTML = '';
                      if (!res || !res.ok || !Array.isArray(res.data) || res.data.length===0) {
                        emptyEl.textContent = 'No seller suggestions yet.';
                        table.style.display = 'none';
                        return;
                      }
                      emptyEl.textContent = '';
                      table.style.display = '';
                      res.data.forEach(function(row){
                        var tr = document.createElement('tr');
                        var dt = row.transaction_date || '';
                        var loc = row.transaction_location || '';
                        var desc = row.description || '';
                        tr.innerHTML = '<td>'+dt+'</td>'+
                          '<td>'+loc+'</td>'+
                          '<td>'+desc+'</td>'+
                          '<td>'+
                            '<button class="btn btn-approve-meetup" data-user="'+(row.user_id||'')+'" data-dt="'+dt+'" data-loc="'+loc+'" style="margin-right:4px;">Approve</button>'+
                            '<button class="btn btn-deny-meetup" data-user="'+(row.user_id||'')+'" data-dt="'+dt+'">Deny</button>'+
                          '</td>';
                        tbody.appendChild(tr);
                      });

                      tbody.addEventListener('click', function(ev){
                        var t = ev.target;
                        if (t && t.classList.contains('btn-approve-meetup')){
                          var u = t.getAttribute('data-user')||'';
                          var dtv = t.getAttribute('data-dt')||'';
                          var locv = t.getAttribute('data-loc')||'';
                          if (!dtv || !locv) return;
                          if (!confirm('Approve this suggested meet-up and set it as the transaction meet-up details?')) return;
                          var fd2 = new FormData();
                          fd2.append('action','apply_meetup_request');
                          fd2.append('transaction_id', data.transaction_id || '');
                          fd2.append('listing_id', data.listing_id || '');
                          fd2.append('seller_id', data.seller_id || '');
                          fd2.append('buyer_id', data.buyer_id || '');
                          fd2.append('transaction_date', dtv);
                          fd2.append('transaction_location', locv);
                          t.disabled = true;
                          fetch('transaction_monitoring.php', { method:'POST', body: fd2, credentials:'same-origin' })
                            .then(function(r){ return r.json(); })
                            .then(function(rj){
                              t.disabled = false;
                              if (rj && rj.ok){
                                var dtInput = document.getElementById('txDateTime');
                                var locInput = document.getElementById('txLocation');
                                if (dtInput) dtInput.value = dtv;
                                if (locInput) locInput.value = locv;
                                saveStatus.textContent = 'Approved seller suggestion and saved meet-up details.';
                                saveStatus.style.color = '#38a169';
                              } else {
                                alert('Failed to apply suggestion');
                              }
                            })
                            .catch(function(){ t.disabled=false; });
                        } else if (t && t.classList.contains('btn-deny-meetup')){
                          var u2 = t.getAttribute('data-user')||'';
                          var dt2 = t.getAttribute('data-dt')||'';
                          if (!u2 || !dt2) return;
                          if (!confirm('Deny this suggested meet-up?')) return;
                          var fd3 = new FormData();
                          fd3.append('action','deny_meetup_request');
                          fd3.append('transaction_id', data.transaction_id || '');
                          fd3.append('user_id', u2);
                          fd3.append('transaction_date', dt2);
                          t.disabled = true;
                          fetch('transaction_monitoring.php', { method:'POST', body: fd3, credentials:'same-origin' })
                            .then(function(r){ return r.json(); })
                            .then(function(rj){
                              t.disabled = false;
                              if (rj && rj.ok){
                                t.closest('tr').remove();
                                if (!table.querySelector('tbody').children.length){
                                  emptyEl.textContent = 'No seller suggestions yet.';
                                  table.style.display = 'none';
                                }
                              } else {
                                alert('Failed to deny suggestion');
                              }
                            })
                            .catch(function(){ t.disabled=false; });
                        }
                      });
                    });
                }
              }
              // Load attendance confirmation for ongoing transactions
              if (isOngoing) {
                var attendanceLoading = document.getElementById('attendanceLoading');
                var attendanceContent = document.getElementById('attendanceContent');
                var attendanceBody = document.getElementById('attendanceBody');
                var confirmBtn = document.getElementById('btnConfirmAttendance');
                var confirmStatus = document.getElementById('confirmStatus');
                
                // Fetch attendance data
                fetch('transaction_monitoring.php?action=get_confirmation&transaction_id=' + (data.transaction_id || ''), { credentials:'same-origin' })
                  .then(function(r){ return r.json(); })
                  .then(function(res){
                    if (attendanceLoading) attendanceLoading.style.display = 'none';
                    if (attendanceContent) attendanceContent.style.display = 'block';
                    
                    if (res && res.ok && res.data) {
                      var conf = res.data;
                      var rows = '';
                      
                      // Seller row
                      rows += '<tr>' +
                        '<td>Seller</td>' +
                        '<td>' + fullname(seller) + '</td>' +
                        '<td><span style="padding:2px 8px;border-radius:4px;font-size:12px;background:' + 
                          (conf.confirm_seller === 'Confirmed' ? '#d1fae5;color:#065f46' : '#fef3c7;color:#92400e') + '">' + 
                          (conf.confirm_seller || 'Waiting') + '</span></td>' +
                        '<td>' + (conf.confirmed_seller ? new Date(conf.confirmed_seller).toLocaleString() : '-') + '</td>' +
                      '</tr>';
                      
                      // Buyer row  
                      rows += '<tr>' +
                        '<td>Buyer</td>' +
                        '<td>' + fullname(buyer) + '</td>' +
                        '<td><span style="padding:2px 8px;border-radius:4px;font-size:12px;background:' + 
                          (conf.confirm_buyer === 'Confirmed' ? '#d1fae5;color:#065f46' : '#fef3c7;color:#92400e') + '">' + 
                          (conf.confirm_buyer || 'Waiting') + '</span></td>' +
                        '<td>' + (conf.confirmed_buyer ? new Date(conf.confirmed_buyer).toLocaleString() : '-') + '</td>' +
                      '</tr>';
                      
                      if (attendanceBody) attendanceBody.innerHTML = rows;
                    } else {
                      // No confirmation record yet
                      if (attendanceBody) {
                        attendanceBody.innerHTML = 
                          '<tr><td>Seller</td><td>' + fullname(seller) + '</td><td><span style="padding:2px 8px;border-radius:4px;font-size:12px;background:#fef3c7;color:#92400e">Waiting</span></td><td>-</td></tr>' +
                          '<tr><td>Buyer</td><td>' + fullname(buyer) + '</td><td><span style="padding:2px 8px;border-radius:4px;font-size:12px;background:#fef3c7;color:#92400e">Waiting</span></td><td>-</td></tr>';
                      }
                    }
                  })
                  .catch(function(){
                    if (attendanceLoading) attendanceLoading.style.display = 'none';
                    if (attendanceContent) attendanceContent.style.display = 'block';
                    if (attendanceBody) attendanceBody.innerHTML = '<tr><td colspan="4" style="color:#ef4444;">Failed to load attendance status</td></tr>';
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
                  // Auto-scroll to form after modal opens
                  setTimeout(function(){
                    var modal = document.getElementById('batCompleteModal');
                    if (modal) {
                      var form = document.getElementById('batCompleteForm');
                      if (form) {
                        form.scrollIntoView({ behavior: 'smooth', block: 'start' });
                      }
                    }
                  }, 100);
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
      <div style="display:flex;justify-content:center;align-items:center;gap:8px;">
        <h2 style="margin:0;">Complete Transaction</h2>
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
          <div id="batCompletePriceWrap">
            <label style="font-weight:600;">Final Price (₱)</label>
            <input type="number" name="price" step="0.01" min="0" style="width:100%;padding:8px;border:1px solid #e2e8f0;border-radius:6px;" />
          </div>
          <div id="batCompletePaymentWrap">
            <label style="font-weight:600;">Payment Method</label>
            <div style="margin-top:6px;display:flex;flex-direction:column;gap:6px;">
              <select id="paymentMethodSelect" style="width:100%;padding:8px;border:1px solid #e2e8f0;border-radius:6px;">
                <option value="Cash">Cash</option>
                <option value="GCash">GCash</option>
                <option value="Bank Transfer">Bank Transfer</option>
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
      var priceWrap = document.getElementById('batCompletePriceWrap');
      var paymentWrap = document.getElementById('batCompletePaymentWrap');
      var priceInput = form ? form.querySelector('input[name="price"]') : null;

      function syncCompleteResultUI(){
        if (!form) return;
        var result = (form.querySelector('input[name="result"]:checked') || {}).value || '';
        if (result === 'failed'){
          if (priceWrap) priceWrap.style.display = 'none';
          if (paymentWrap) paymentWrap.style.display = 'none';
          if (priceInput) priceInput.value = '0';
          if (methodHidden) methodHidden.value = 'None';
        } else {
          if (priceWrap) priceWrap.style.display = '';
          if (paymentWrap) paymentWrap.style.display = '';
        }
      }

      if (form){
        var resultRadios = form.querySelectorAll('input[name="result"]');
        Array.prototype.slice.call(resultRadios).forEach(function(r){
          r.addEventListener('change', syncCompleteResultUI);
        });
        syncCompleteResultUI();
      }

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
          var price = priceInput ? parseFloat(priceInput.value || '0') : 0;
          var methodVal = methodSelect ? methodSelect.value : '';
          var pay = '';
          if (methodVal === 'other'){
            pay = methodOther ? methodOther.value.toString().trim() : '';
          } else {
            pay = methodVal || '';
          }
          if (result === 'failed'){
            price = 0;
            pay = 'None';
            if (priceInput) priceInput.value = '0';
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
                var errorMsg = (res && res.error ? res.error : 'Unknown error');
                if (res && res.detail) {
                  var d = res.detail;
                  try{
                    if (typeof d === 'object') d = JSON.stringify(d);
                  }catch(_){ }
                  errorMsg += ' (' + d + ')';
                }
                if (res && res.code) {
                  errorMsg += ' [Code: ' + res.code + ']';
                }
                msg.textContent = 'Failed: ' + errorMsg; msg.style.color = '#e53e3e';
              }
            })
            .catch(function(err){ submitBtn.disabled=false; submitBtn.textContent='Submit'; msg.textContent = 'Network error: '+err.message; msg.style.color = '#e53e3e'; });
        });
      }
    })();
  </script>
</body>
</html>
