<?php
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Sales Analytics</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../style/dashboard.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
</head>
<body>
  <nav class="navbar">
    <div class="nav-left">
      <div class="brand">Sales Analytics</div>
    </div>
    <div class="nav-right">
      <a class="btn" href="../dashboard.php">Back to Dashboard</a>
    </div>
  </nav>
  <div class="wrap">
    <div class="card">
      <h3>Monthly Sales by Livestock Type</h3>
      <div class="chartbox"><canvas id="salesBar"></canvas></div>
    </div>
    <div class="card">
      <h3>Top Performing Types</h3>
      <div style="overflow:auto">
        <table style="width:100%;border-collapse:collapse" id="top-types">
          <thead>
            <tr style="text-align:left;border-bottom:1px solid #e2e8f0">
              <th style="padding:8px">Type</th>
              <th style="padding:8px">Total Sales</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
    </div>
  </div>
  <script>
    (function(){
      var ctx = document.getElementById('salesBar');
      if (!ctx || !window.Chart) return;
      new Chart(ctx, { type:'bar', data:{ labels:[], datasets:[] }, options:{ responsive:true, maintainAspectRatio:false } });
    })();
  </script>
</body>
</html>
