<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../../../authentication/lib/supabase_client.php';

function json_fail($msg, $code=400){ http_response_code($code); echo json_encode(['ok'=>false,'error'=>$msg]); exit; }
function json_ok($extra=[]){ echo json_encode(array_merge(['ok'=>true], $extra)); exit; }

$bat_id = $_SESSION['user_id'] ?? null;
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'bat' || !$bat_id){ json_fail('Unauthorized', 401); }

$action = $_GET['action'] ?? ($_POST['action'] ?? '');
if (!$action) json_fail('Missing action');

function month_to_num($m){
  if ($m === null) return null; $m = trim(strtolower((string)$m));
  if ($m === '') return null;
  if (ctype_digit($m)) return intval($m);
  $map = [
    'jan'=>1,'january'=>1,'feb'=>2,'february'=>2,'mar'=>3,'march'=>3,'apr'=>4,'april'=>4,
    'may'=>5,'jun'=>6,'june'=>6,'jul'=>7,'july'=>7,'aug'=>8,'august'=>8,'sep'=>9,'sept'=>9,'september'=>9,
    'oct'=>10,'october'=>10,'nov'=>11,'november'=>11,'dec'=>12,'december'=>12
  ];
  return $map[$m] ?? null;
}

function parse_date_time($month, $day, $hour, $minute, $year){
  $mm = month_to_num($month); if (!$mm) return null;
  $dd = intval($day);
  $hh = intval($hour); $mi = intval($minute);
  if ($dd<=0 || $dd>31 || $hh<0 || $hh>23 || $mi<0 || $mi>59) return null;
  return sprintf('%04d-%02d-%02dT%02d:%02d:00', intval($year), $mm, $dd, $hh, $mi);
}

if ($action === 'list'){
  // Fetch all schedules for this BAT; client provides a date range
  [$res,$status,$err] = sb_rest('GET','schedule',[
    'select'=>'schedule_id,bat_id,title,description,month,day,hour,minute',
    'bat_id'=>'eq.'.$bat_id
  ]);
  if (!($status>=200 && $status<300) || !is_array($res)) $res = [];
  $start = isset($_GET['start']) ? $_GET['start'] : null;
  $end = isset($_GET['end']) ? $_GET['end'] : null;
  $startYear = $start ? intval(date('Y', strtotime($start))) : intval(date('Y'));
  $endYear = $end ? intval(date('Y', strtotime($end))) : $startYear;
  if ($endYear < $startYear) $endYear = $startYear;
  $events = [];
  foreach ($res as $row){
    for ($y=$startYear; $y<=$endYear; $y++){
      $iso = parse_date_time($row['month'], $row['day'], $row['hour'], $row['minute'], $y);
      if (!$iso) continue;
      $events[] = [
        'id' => (string)$row['schedule_id'],
        'title' => (string)$row['title'],
        'start' => $iso,
        'end' => $iso,
        'done' => (strpos((string)$row['title'], '[Done]') === 0),
      ];
    }
  }
  // Also include seller-scheduled meet-ups from ongoingtransactions
  [$ot,$ots,$ote] = sb_rest('GET','ongoingtransactions',[
    'select'=>'transaction_id,listing_id,seller_id,buyer_id,transaction_date,transaction_location,bat_id'
  ]);
  if ($ots>=200 && $ots<300 && is_array($ot)){
    foreach ($ot as $row){
      $dt = isset($row['transaction_date']) ? trim((string)$row['transaction_date']) : '';
      $loc = isset($row['transaction_location']) ? trim((string)$row['transaction_location']) : '';
      if ($dt==='') continue; // must have date/time
      if ($loc==='') continue; // must have location
      // Validate location as lat,lng numeric pair
      $okLoc = false; $la=null; $ln=null;
      if (strpos($loc, ',') !== false){
        $parts = explode(',', $loc, 2);
        $la = (float)trim($parts[0]); $ln = (float)trim($parts[1]);
        if (is_finite($la) && is_finite($ln)) $okLoc = true;
      }
      if (!$okLoc) continue;
      $listingId = (int)($row['listing_id'] ?? 0);
      $title = 'Transaction Meet-up for Listing #'.$listingId;
      $desc  = 'Seller '.(string)($row['seller_id'] ?? '').' x Buyer '.(string)($row['buyer_id'] ?? '').' at '.$loc;
      // Only for current BAT; we need bat_id in row to match session bat
      $rowBat = isset($row['bat_id']) ? (int)$row['bat_id'] : null;
      if ($rowBat !== null && (int)$rowBat === (int)$bat_id){
        // Check if schedule already exists for this BAT + title
        [$ex,$xs,$xe] = sb_rest('GET','schedule',[ 'select'=>'schedule_id', 'bat_id'=>'eq.'.$bat_id, 'title'=>'eq.'.$title, 'limit'=>1 ]);
        if (!($xs>=200 && $xs<300) || !is_array($ex) || !isset($ex[0])){
          // Create schedule from dt
          $ts = strtotime($dt);
          $month = ltrim(date('m',$ts),'0');
          $day   = ltrim(date('d',$ts),'0');
          $hour  = (int)date('H',$ts);
          $minute= (int)date('i',$ts);
          $payload = [[ 'bat_id'=>(int)$bat_id, 'title'=>$title, 'description'=>$desc, 'month'=>$month, 'day'=>$day, 'hour'=>$hour, 'minute'=>$minute ]];
          sb_rest('POST','schedule',[], $payload, ['Prefer: return=representation']);
        }
      }
      // Also include in current response to reflect immediately
      $events[] = [ 'id' => 'ongoing-'.(string)($row['transaction_id'] ?? ''), 'title' => $title, 'start' => $dt, 'end' => $dt, 'done' => false ];
    }
  }
  json_ok(['events'=>$events]);
}

$raw = file_get_contents('php://input');
$input = $raw ? json_decode($raw, true) : [];
if (!is_array($input)) $input = [];

if ($action === 'create'){
  $title = trim($input['title'] ?? '');
  $description = trim($input['description'] ?? '');
  $date = trim($input['date'] ?? ''); // YYYY-MM-DD
  $time = trim($input['time'] ?? ''); // HH:MM
  if ($title==='' || $description==='' || $date==='' || $time==='') json_fail('Missing fields');
  $parts = explode('-', $date); if (count($parts)!==3) json_fail('Invalid date');
  $month = ltrim($parts[1], '0'); if ($month==='') $month='0';
  $day = ltrim($parts[2], '0'); if ($day==='') $day='0';
  $tparts = explode(':', $time); if (count($tparts)<2) json_fail('Invalid time');
  $hour = intval($tparts[0]); $minute = intval($tparts[1]);
  $payload = [[
    'bat_id' => (int)$bat_id,
    'title' => $title,
    'description' => $description,
    'month' => (string)$month,
    'day' => (string)$day,
    'hour' => (int)$hour,
    'minute' => (int)$minute
  ]];
  [$r,$s,$e] = sb_rest('POST','schedule',[], $payload, ['Prefer: return=representation']);
  if (!($s>=200 && $s<300)) json_fail('Create failed',500);
  json_ok();
}

if ($action === 'get'){
  $id = isset($_GET['id']) ? (int)$_GET['id'] : 0; if (!$id) json_fail('Missing id');
  [$r,$s,$e] = sb_rest('GET','schedule',[
    'select'=>'schedule_id,bat_id,title,description,month,day,hour,minute',
    'schedule_id'=>'eq.'.$id,
    'bat_id'=>'eq.'.$bat_id,
    'limit'=>1
  ]);
  if (!($s>=200 && $s<300) || !is_array($r) || !isset($r[0])) json_fail('Not found',404);
  $row = $r[0];
  // Convert to form-friendly fields
  $mm = str_pad((string)month_to_num($row['month']), 2, '0', STR_PAD_LEFT);
  $dd = str_pad((string)intval($row['day']), 2, '0', STR_PAD_LEFT);
  $date = date('Y')."-$mm-$dd";
  $time = str_pad((string)intval($row['hour']),2,'0',STR_PAD_LEFT).':'.str_pad((string)intval($row['minute']),2,'0',STR_PAD_LEFT);
  json_ok(['item'=>[
    'id'=>$row['schedule_id'],
    'title'=>$row['title'],
    'description'=>$row['description'],
    'date'=>$date,
    'time'=>$time
  ]]);
}

if ($action === 'update'){
  $id = isset($_GET['id']) ? (int)$_GET['id'] : 0; if (!$id) json_fail('Missing id');
  // Load existing to ensure ownership
  [$ex,$xs,$xe] = sb_rest('GET','schedule',[
    'select'=>'schedule_id,bat_id', 'schedule_id'=>'eq.'.$id, 'bat_id'=>'eq.'.$bat_id, 'limit'=>1
  ]);
  if (!($xs>=200 && $xs<300) || !is_array($ex) || !isset($ex[0])) json_fail('Not found',404);
  $title = trim($input['title'] ?? '');
  $description = trim($input['description'] ?? '');
  $date = trim($input['date'] ?? '');
  $time = trim($input['time'] ?? '');
  $patch = [];
  if ($title!=='') $patch['title']=$title;
  if ($description!=='') $patch['description']=$description;
  if ($date!=='') { $p=explode('-',$date); if (count($p)===3){ $patch['month']=ltrim($p[1],'0')?:'0'; $patch['day']=ltrim($p[2],'0')?:'0'; } }
  if ($time!=='') { $tp=explode(':',$time); if (count($tp)>=2){ $patch['hour']=intval($tp[0]); $patch['minute']=intval($tp[1]); } }
  if (!$patch) json_ok();
  [$r,$s,$e] = sb_rest('PATCH','schedule',[ 'schedule_id'=>'eq.'.$id, 'bat_id'=>'eq.'.$bat_id ], [ $patch ], ['Prefer: return=representation']);
  if (!($s>=200 && $s<300)) json_fail('Update failed',500);
  json_ok();
}

if ($action === 'delete'){
  $id = isset($_GET['id']) ? (int)$_GET['id'] : 0; if (!$id) json_fail('Missing id');
  [$r,$s,$e] = sb_rest('DELETE','schedule',[ 'schedule_id'=>'eq.'.$id, 'bat_id'=>'eq.'.$bat_id ], []);
  if (!($s>=200 && $s<300)) json_fail('Delete failed',500);
  json_ok();
}

if ($action === 'done'){
  $id = isset($_GET['id']) ? (int)$_GET['id'] : 0; if (!$id) json_fail('Missing id');
  // Fetch current title then prepend [Done] if not already
  [$r,$s,$e] = sb_rest('GET','schedule',[ 'select'=>'title', 'schedule_id'=>'eq.'.$id, 'bat_id'=>'eq.'.$bat_id, 'limit'=>1 ]);
  if (!($s>=200 && $s<300) || !is_array($r) || !isset($r[0])) json_fail('Not found',404);
  $title = (string)$r[0]['title'];
  if (strpos($title, '[Done]') !== 0){ $title = '[Done] '.$title; }
  [$pr,$ps,$pe] = sb_rest('PATCH','schedule',[ 'schedule_id'=>'eq.'.$id, 'bat_id'=>'eq.'.$bat_id ], [ ['title'=>$title] ], ['Prefer: return=representation']);
  if (!($ps>=200 && $ps<300)) json_fail('Mark done failed',500);
  json_ok();
}

json_fail('Unknown action');
