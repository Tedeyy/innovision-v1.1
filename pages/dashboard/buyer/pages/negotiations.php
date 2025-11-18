<?php
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Negotiations</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../style/dashboard.css">
</head>
<body>
  <nav class="navbar">
    <div class="nav-left">
      <div class="brand">Negotiations</div>
    </div>
    <div class="nav-right">
      <a class="btn" href="../dashboard.php">Back to Dashboard</a>
    </div>
  </nav>
  <div class="wrap">
    <div class="card">
      <div style="display:grid;grid-template-columns:repeat(4,minmax(140px,1fr));gap:8px">
        <input id="q" type="search" placeholder="Search" />
        <select id="status">
          <option value="">All</option>
          <option value="open">Open</option>
          <option value="accepted">Accepted</option>
          <option value="declined">Declined</option>
        </select>
        <input id="from" type="date" />
        <input id="to" type="date" />
      </div>
    </div>
    <div class="card">
      <div style="overflow:auto">
        <table style="width:100%;border-collapse:collapse" id="threads">
          <thead>
            <tr style="text-align:left;border-bottom:1px solid #e2e8f0">
              <th style="padding:8px">Thread</th>
              <th style="padding:8px">Seller</th>
              <th style="padding:8px">Listing</th>
              <th style="padding:8px">Status</th>
              <th style="padding:8px">Updated</th>
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
