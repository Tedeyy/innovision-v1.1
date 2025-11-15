<?php
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Security</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../style/dashboard.css">
</head>
<body>
  <nav class="navbar">
    <div class="nav-left">
      <div class="brand">Security</div>
    </div>
    <div class="nav-right">
      <a class="btn" href="../dashboard.php">Back to Dashboard</a>
    </div>
  </nav>
  <div class="wrap">
    <div class="card">
      <h3>Status</h3>
      <div id="status" style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px"></div>
    </div>
    <div class="card">
      <h3>Settings</h3>
      <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:8px">
        <label><input type="checkbox" id="2fa" /> Two-Factor for Admins</label>
        <label><input type="checkbox" id="audit" /> Enhanced Audit Logs</label>
      </div>
    </div>
    <div class="card">
      <h3>Recent Security Events</h3>
      <div style="overflow:auto">
        <table style="width:100%;border-collapse:collapse" id="events">
          <thead>
            <tr style="text-align:left;border-bottom:1px solid #e2e8f0">
              <th style="padding:8px">Time</th>
              <th style="padding:8px">User</th>
              <th style="padding:8px">Action</th>
              <th style="padding:8px">Details</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
    </div>
  </div>
</body>
</html>
