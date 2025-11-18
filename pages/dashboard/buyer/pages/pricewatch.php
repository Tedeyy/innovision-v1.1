<?php
session_start();
if (($_SESSION['role'] ?? '') !== 'buyer'){
  http_response_code(302);
  header('Location: ../dashboard.php');
  exit;
}
require_once __DIR__ . '/../../../authentication/lib/supabase_client.php';
function safe($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
// Load filter options
[$typeRows,$typeSt,$typeErr] = sb_rest('GET','livestock_type',['select'=>'type_id,name']);
$types = is_array($typeRows) ? $typeRows : [];
$selType = isset($_GET['type_id']) ? (int)$_GET['type_id'] : 0;
// Breed options depend on selected type (if any). If none selected, keep empty for UI but load all for name mapping.
if ($selType>0){
  [$breedRows,$breedSt,$breedErr] = sb_rest('GET','livestock_breed',['select'=>'breed_id,name,type_id','type_id'=>'eq.'.$selType]);
  $breeds = is_array($breedRows) ? $breedRows : [];
  $breedRowsAll = $breeds; // for mapping
} else {
  $breeds = [];
  [$breedRowsAll,$brAllSt,$brAllErr] = sb_rest('GET','livestock_breed',['select'=>'breed_id,name']);
  if (!is_array($breedRowsAll)) { $breedRowsAll = []; }
}
$selBreed = isset($_GET['breed_id']) ? (int)$_GET['breed_id'] : 0;

// Build name maps
$typeMap = []; foreach ($types as $t){ $typeMap[(int)$t['type_id']] = $t['name'] ?? ('Type #'.(int)$t['type_id']); }
$breedMap = []; foreach ($breedRowsAll as $b){ $breedMap[(int)$b['breed_id']] = $b['name'] ?? ('Breed #'.(int)$b['breed_id']); }

// Query approved logs
$query = [ 'select' => 'pricing_id,livestocktype_id,breed_id,marketprice,admin_id,superadmin_id,status,created', 'status' => 'eq.Approved', 'order' => 'created.desc' ];
if ($selType>0){ $query['livestocktype_id'] = 'eq.'.$selType; }
if ($selBreed>0){ $query['breed_id'] = 'eq.'.$selBreed; }
[$logs,$logSt,$logErr] = sb_rest('GET','marketpricing_logs', $query);
if (!is_array($logs)) $logs = [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Buyer • Price Watch</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../style/dashboard.css">
  <style>
    table{width:100%;border-collapse:collapse}
    th,td{padding:10px;border-bottom:1px solid #e5e7eb;text-align:left}
    th{font-weight:600;color:#374151}
  </style>
</head>
<body>
  <nav class="navbar">
    <div class="nav-left"><div class="brand">Price Watch</div></div>
    <div class="nav-right"><a class="btn" href="../dashboard.php">Back to Dashboard</a></div>
  </nav>
  <div class="wrap">
    <div class="card">
      <h1>Market Prices</h1>
      <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin:8px 0;">
        <label>Type
          <select name="type_id" id="type_id" onchange="this.form.submit()">
            <option value="">All</option>
            <?php foreach ($types as $t): $tid=(int)$t['type_id']; $sel=$selType===$tid?'selected':''; ?>
              <option value="<?php echo $tid; ?>" <?php echo $sel; ?>><?php echo safe($t['name']??('Type #'.$tid)); ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Breed
          <select name="breed_id" id="breed_id" <?php echo $selType>0? '' : 'disabled'; ?>>
            <?php if ($selType===0): ?>
              <option value="">Select type first</option>
            <?php else: ?>
              <option value="">All</option>
              <?php foreach ($breeds as $b): $bid=(int)$b['breed_id']; $sel=$selBreed===$bid?'selected':''; ?>
                <option value="<?php echo $bid; ?>" <?php echo $sel; ?>><?php echo safe($b['name']??('Breed #'.$bid)); ?></option>
              <?php endforeach; ?>
            <?php endif; ?>
          </select>
        </label>
        <button class="btn" type="submit">Filter</button>
        <a class="btn" href="pricewatch.php" style="background:#718096;">Clear</a>
      </form>
      <div class="table-scroll">
        <table class="table-simple">
          <thead>
            <tr>
              <th>Type</th>
              <th>Breed</th>
              <th>Market Price (per kilo)</th>
              <th>Logged</th>
            </tr>
          </thead>
          <tbody id="rows">
            <?php if (count($logs)===0): ?>
              <tr><td colspan="4" style="color:#6b7280">No data yet.</td></tr>
            <?php else: foreach ($logs as $row): ?>
              <tr>
                <td><?php echo safe($typeMap[(int)$row['livestocktype_id']] ?? ('Type #'.(int)$row['livestocktype_id'])); ?></td>
                <td><?php echo safe($breedMap[(int)$row['breed_id']] ?? ('Breed #'.(int)$row['breed_id'])); ?></td>
                <td><strong>₱<?php echo number_format((float)($row['marketprice'] ?? 0), 2); ?></strong></td>
                <td><?php echo safe(substr((string)($row['created'] ?? ''),0,19)); ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</body>
</html>
