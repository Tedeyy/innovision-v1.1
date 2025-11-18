<?php
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Reports and Violations</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../style/dashboard.css">
</head>
<body>
  <nav class="navbar">
    <div class="nav-left">
      <div class="brand">Reports and Violations</div>
    </div>
    <div class="nav-right">
      <a class="btn" href="../dashboard.php">Back to Dashboard</a>
    </div>
  </nav>
  <div class="wrap">
    <div class="card">
      <h3>Filters</h3>
      <div style="display:grid;grid-template-columns:repeat(5,minmax(140px,1fr));gap:8px">
        <select id="role">
          <option value="">All Roles</option>
          <option value="buyer">Buyer</option>
          <option value="seller">Seller</option>
        </select>
        <select id="status">
          <option value="">All Status</option>
          <option value="open">Open</option>
          <option value="under_review">Under Review</option>
          <option value="resolved">Resolved</option>
        </select>
        <input id="from" type="date" />
        <input id="to" type="date" />
        <button id="apply" class="btn">Apply</button>
      </div>
    </div>
    <div class="card">
      <h3>Reports</h3>
      <div style="overflow:auto">
        <table style="width:100%;border-collapse:collapse" id="reports">
          <thead>
            <tr style="text-align:left;border-bottom:1px solid #e2e8f0">
              <th style="padding:8px">ID</th>
              <th style="padding:8px">Filed By</th>
              <th style="padding:8px">Against</th>
              <th style="padding:8px">Reason</th>
              <th style="padding:8px">Status</th>
              <th style="padding:8px">Created</th>
              <th style="padding:8px">Action</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
    </div>
    <div class="card">
      <h3>Penalties</h3>
      <div style="overflow:auto">
        <table style="width:100%;border-collapse:collapse" id="penalties">
          <thead>
            <tr style="text-align:left;border-bottom:1px solid #e2e8f0">
              <th style="padding:8px">User</th>
              <th style="padding:8px">Penalty</th>
              <th style="padding:8px">Effective</th>
              <th style="padding:8px">Until</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
    </div>
  </div>
</body>
</html>
