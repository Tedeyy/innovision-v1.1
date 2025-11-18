<?php
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>BAT Coordination</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../style/dashboard.css">
</head>
<body>
  <nav class="navbar">
    <div class="nav-left">
      <div class="brand">BAT Coordination</div>
    </div>
    <div class="nav-right">
      <a class="btn" href="../dashboard.php">Back to Dashboard</a>
    </div>
  </nav>
  <div class="wrap">
    <div class="card">
      <div style="display:grid;grid-template-columns:repeat(4,minmax(140px,1fr));gap:8px">
        <input id="q" type="search" placeholder="Search" />
        <input id="date" type="date" />
        <input id="time" type="time" />
        <button id="request" class="btn">Request Assistance</button>
      </div>
    </div>
    <div class="card">
      <div style="overflow:auto">
        <table style="width:100%;border-collapse:collapse" id="appointments">
          <thead>
            <tr style="text-align:left;border-bottom:1px solid #e2e8f0">
              <th style="padding:8px">ID</th>
              <th style="padding:8px">Listing</th>
              <th style="padding:8px">BAT</th>
              <th style="padding:8px">Date</th>
              <th style="padding:8px">Time</th>
              <th style="padding:8px">Status</th>
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
