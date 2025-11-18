<?php
session_start();
require_once __DIR__ . '/../../../authentication/lib/supabase_client.php';
header('Content-Type: application/json');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') { echo json_encode(['ok'=>false,'error'=>'Unauthorized']); exit; }
$admin_id = $_SESSION['user_id'] ?? null; if (!$admin_id) { echo json_encode(['ok'=>false,'error'=>'No admin']); exit; }

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $input['action'] ?? '';

function jerr($m){ echo json_encode(['ok'=>false,'error'=>$m]); exit; }

if ($action === 'breeds_by_type'){
  $livestocktype_id = isset($input['livestocktype_id']) ? (int)$input['livestocktype_id'] : 0;
  if ($livestocktype_id<=0){ jerr('Missing livestocktype_id'); }
  // Assuming livestock_breed has column type_id referencing livestock_type(type_id)
  list($rows,$st,$err) = sb_rest('GET','livestock_breed',[ 'select'=>'breed_id,name,type_id', 'type_id'=>'eq.'.$livestocktype_id ]);
  if ($err || $st>=400 || !is_array($rows)) { jerr('Failed to load breeds'); }
  $items = array_map(function($r){ return [ 'breed_id'=>(int)($r['breed_id']??0), 'name'=>($r['name']??('Breed #'.((int)($r['breed_id']??0)))) ]; }, $rows);
  echo json_encode(['ok'=>true,'items'=>$items]);
  exit;
}

if ($action === 'create_pending'){
  $livestocktype_id = isset($input['livestocktype_id']) ? (int)$input['livestocktype_id'] : 0;
  $breed_id = isset($input['breed_id']) ? (int)$input['breed_id'] : 0;
  $marketprice = isset($input['marketprice']) ? (float)$input['marketprice'] : 0;
  if ($livestocktype_id<=0 || $breed_id<=0 || $marketprice<=0){ jerr('Invalid fields'); }
  $created = gmdate('c');
  // Insert into pendingmarketpricing
  [$insRes,$insSt,$insErr] = sb_rest('POST','pendingmarketpricing',[],[[
    'livestocktype_id'=>$livestocktype_id,
    'breed_id'=>$breed_id,
    'marketprice'=>$marketprice,
    'admin_id'=>$admin_id,
    'status'=>'Pending',
    'created'=>$created
  ]]);
  if ($insErr || ($insSt<200 || $insSt>=300)) { jerr('Failed to insert'); }
  // Log action
  [$logRes,$logSt,$logErr] = sb_rest('POST','marketpricing_logs',[],[[
    'livestocktype_id'=>$livestocktype_id,
    'breed_id'=>$breed_id,
    'marketprice'=>$marketprice,
    'admin_id'=>$admin_id,
    'status'=>'Pending',
    'created'=>$created
  ]], ['Prefer: return=minimal']);
  echo json_encode(['ok'=>true]);
  exit;
}

jerr('Unknown action');
