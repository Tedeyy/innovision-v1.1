<?php
session_start();
$firstname = isset($_SESSION['firstname']) && $_SESSION['firstname'] !== '' ? $_SESSION['firstname'] : 'User';
// Compute verification status from role and source table
$role = $_SESSION['role'] ?? '';
$src  = $_SESSION['source_table'] ?? '';
$isVerified = false;
if ($role === 'admin') {
    $isVerified = ($src === 'admin');
}
$statusLabel = $isVerified ? 'Verified' : 'Under review';
require_once __DIR__ . '/../../authentication/lib/supabase_client.php';

// Prepare data for sales chart (last 3 months and next month)
$labels = [];
$monthKeys = [];
$now = new DateTime('first day of this month 00:00:00');
for ($i=-3; $i<=1; $i++){
  $d = (clone $now)->modify(($i>=0?'+':'').$i.' month');
  $labels[] = $d->format('M');
  $monthKeys[] = $d->format('Y-m');
}

// Get livestock types
[$typesRes,$typesStatus,$typesErr] = sb_rest('GET','livestock_type',['select'=>'name']);
$typeNames = [];
if ($typesStatus>=200 && $typesStatus<300 && is_array($typesRes)){
  foreach ($typesRes as $row){ if (!empty($row['name'])) $typeNames[] = $row['name']; }
}
// Fallback default labels if table empty
if (count($typeNames)===0){ $typeNames = ['Cattle','Goat','Pigs']; }

// Fetch sold listings; filter in PHP to avoid complex PostgREST params here
[$soldRes,$soldStatus,$soldErr] = sb_rest('GET','soldlivestocklisting',['select'=>'livestock_type,price,created']);
$series = [];
foreach ($typeNames as $tn){ $series[$tn] = array_fill(0, count($monthKeys), 0); }
if ($soldStatus>=200 && $soldStatus<300 && is_array($soldRes)){
  foreach ($soldRes as $r){
    $lt = $r['livestock_type'] ?? null;
    $price = (float)($r['price'] ?? 0);
    $created = isset($r['created']) ? substr($r['created'],0,7) : null; // YYYY-MM
    if (!$lt || !$created) continue;
    $idx = array_search($created, $monthKeys, true);
    if ($idx===false) continue;
    if (!isset($series[$lt])) continue;
    $series[$lt][$idx] += $price;
  }
}

// Build datasets with colors
$palette = ['#8B4513','#16a34a','#ec4899','#2563eb','#f59e0b','#10b981','#ef4444','#6b7280'];
$datasets = [];
foreach ($typeNames as $i=>$tn){
  $datasets[] = [
    'label' => $tn,
    'data' => $series[$tn],
    'borderColor' => $palette[$i % count($palette)],
    'backgroundColor' => 'transparent',
    'tension' => 0.3,
    'spanGaps' => true,
    'pointRadius' => 0
  ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style/dashboard.css">
    </head>
<body>
    <nav class="navbar">
        <div class="nav-left">
            <div class="brand">Dashboard</div>
            <form class="search" method="get" action="#">
                <input type="search" name="q" placeholder="Search" />
            </form>
        </div>
        <div class="nav-center" style="display:flex;gap:16px;align-items:center;">
            <a class="btn" href="pages/usermanagement.php" style="background:#4a5568;">Users</a>
            <a class="btn" href="pages/listingmanagement.php" style="background:#4a5568;">Listings</a>
        </div>
        <div class="nav-right">
            <div class="greeting">hello <?php echo htmlspecialchars($firstname, ENT_QUOTES, 'UTF-8'); ?> • <?php echo htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8'); ?></div>
            <a class="btn" href="../logout.php">Logout</a>
            <a class="profile" href="pages/profile.php" aria-label="Profile">
                <span class="avatar">👤</span>
            </a>
        </div>
    </nav>
    <div class="wrap">
        <div class="top">
            <div>
                <h1>Admin Dashboard</h1>
            </div>
        </div>
        <div class="card">
            <p>Use this space to manage users, view system stats, and oversee platform content.</p>
        </div>

        <div class="card">
            <h3>Sales by Livestock Type</h3>
            <div class="chartbox"><canvas id="adminSalesChart"></canvas></div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script>
    (function(){
      var el = document.getElementById('adminSalesChart');
      if (!el || !window.Chart) return;
      var labels = <?php echo json_encode($labels); ?>;
      var datasets = <?php echo json_encode($datasets); ?>;
      new Chart(el, {
        type: 'line',
        data: { labels: labels, datasets: datasets },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          animation: false,
          plugins: { legend: { position: 'bottom' } },
          scales: { y: { beginAtZero: true, suggestedMin: 0 } }
        }
      });
    })();
    </script>
</body>
</html>