<?php
session_start();
$firstname = isset($_SESSION['firstname']) && $_SESSION['firstname'] !== '' ? $_SESSION['firstname'] : 'User';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Analytics</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../style/dashboard.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
</head>
<body>
  <nav class="navbar">
    <div class="nav-left">
      <div class="brand">Admin</div>
    </div>
    <div class="nav-right">
      <div class="greeting">hello <?php echo htmlspecialchars($firstname, ENT_QUOTES, 'UTF-8'); ?></div>
      <a class="btn" href="../../logout.php">Logout</a>
      <a class="profile" href="profile.php" aria-label="Profile"><span class="avatar">👤</span></a>
    </div>
  </nav>
  <div class="wrap">
    <div class="card">
      <h1 style="margin-top:0">Analytics</h1>
      <div class="chartbox"><canvas id="chart"></canvas></div>
    </div>
  </div>
  <script>
    (function(){
      var ctx = document.getElementById('chart'); if(!ctx) return;
      new Chart(ctx,{type:'line',data:{labels:['Jan','Feb','Mar','Apr'],datasets:[{label:'Volume',data:[3,2,4,5],borderColor:'#2563eb',backgroundColor:'transparent',tension:0.3,pointRadius:0}]} ,options:{responsive:true,maintainAspectRatio:false}});
    })();
  </script>
</body>
</html>
