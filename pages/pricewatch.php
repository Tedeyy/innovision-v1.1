<?php
session_start();
require_once __DIR__ . '/authentication/lib/supabase_client.php';
header('Content-Type: application/json');

// Fetch all types
list($typesRes,$typesCode,$typesErr) = sb_rest('GET','livestock_type',[ 'select'=>'type_id,name', 'order'=>'name.asc' ]);
if (!($typesCode>=200 && $typesCode<300) || !is_array($typesRes)) $typesRes = [];
$types = [];
foreach ($typesRes as $t){ $types[(int)$t['type_id']] = [ 'type_id'=>(int)$t['type_id'], 'name'=>(string)$t['name'] ]; }

// Fetch all breeds
list($breedsRes,$breedsCode,$breedsErr) = sb_rest('GET','livestock_breed',[ 'select'=>'breed_id,type_id,name', 'order'=>'name.asc' ]);
if (!($breedsCode>=200 && $breedsCode<300) || !is_array($breedsRes)) $breedsRes = [];
$breedsById = [];
$breedsByType = [];
foreach ($breedsRes as $b){
  $bid = (int)$b['breed_id']; $tid = (int)$b['type_id'];
  $breedsById[$bid] = [ 'breed_id'=>$bid, 'type_id'=>$tid, 'name'=>(string)$b['name'] ];
  if (!isset($breedsByType[$tid])) $breedsByType[$tid] = [];
  $breedsByType[$tid][] = $breedsById[$bid];
}

// Fetch approved pricing ordered by most recent, then take first per breed
list($priceRes,$priceCode,$priceErr) = sb_rest('GET','approvedmarketpricing',[ 'select'=>'breed_id,livestocktype_id,marketprice,approved_at', 'order'=>'approved_at.desc', 'limit'=>10000 ]);
if (!($priceCode>=200 && $priceCode<300) || !is_array($priceRes)) $priceRes = [];
$latestByBreed = [];
foreach ($priceRes as $row){
  $bid = (int)($row['breed_id'] ?? 0);
  if ($bid && !isset($latestByBreed[$bid])){
    $latestByBreed[$bid] = [
      'breed_id'=>$bid,
      'livestocktype_id'=>(int)($row['livestocktype_id'] ?? 0),
      'marketprice'=>(float)($row['marketprice'] ?? 0),
      'approved_at'=> (string)($row['approved_at'] ?? '')
    ];
  }
}

// Build output grouped by type
$out = [];
foreach ($types as $tid=>$tinfo){
  $typeName = $tinfo['name'];
  $breeds = $breedsByType[$tid] ?? [];
  $breedItems = [];
  $recentPrices = [];
  foreach ($breeds as $b){
    $bid = $b['breed_id'];
    $latest = $latestByBreed[$bid] ?? null;
    if ($latest){
      $breedItems[] = [
        'breed_id'=>$bid,
        'breed_name'=>$b['name'],
        'price'=>$latest['marketprice'],
        'approved_at'=>$latest['approved_at']
      ];
      $recentPrices[] = $latest['marketprice'];
    }
  }
  // Compute a simple recent price for the type (e.g., average of latest breed prices)
  $typeRecent = null;
  if (count($recentPrices)){
    $typeRecent = array_sum($recentPrices)/count($recentPrices);
  }
  $out[] = [
    'type_id'=>$tid,
    'type_name'=>$typeName,
    'recent_price'=>$typeRecent,
    'breeds'=>$breedItems
  ];
}

echo json_encode([ 'ok'=>true, 'types'=>$out ]);
