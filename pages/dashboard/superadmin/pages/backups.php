<?php
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Backups</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../style/dashboard.css">
</head>
<body>
  <nav class="navbar">
    <div class="nav-left">
      <div class="brand">Backups</div>
    </div>
    <div class="nav-right">
      <a class="btn" href="../dashboard.php">Back to Dashboard</a>
    </div>
  </nav>
  <div class="wrap">
    <div class="card">
      <h3>Create Backup</h3>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <button id="backup-now" class="btn">Backup Now</button>
      </div>
    </div>
    <div class="card">
      <h3>Backup History</h3>
      <div style="overflow:auto">
        <table style="width:100%;border-collapse:collapse" id="history">
          <thead>
            <tr style="text-align:left;border-bottom:1px solid #e2e8f0">
              <th style="padding:8px">ID</th>
              <th style="padding:8px">Type</th>
              <th style="padding:8px">Created</th>
              <th style="padding:8px">Size</th>
              <th style="padding:8px">Action</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
    </div>
  </div>
</body>
</html>
