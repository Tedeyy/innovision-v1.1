<?php
session_start();
require_once __DIR__ . '/../../../authentication/lib/supabase_client.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Security</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../style/dashboard.css">
  <style>
    .security-table {
      width: 100%;
      border-collapse: collapse;
      background: white;
      border-radius: 8px;
      overflow: hidden;
      border: 1px solid #e5e7eb;
    }
    .security-table th {
      background: #f3f4f6;
      padding: 12px;
      text-align: left;
      font-weight: 600;
      color: #374151;
      border-bottom: 1px solid #e5e7eb;
    }
    .security-table td {
      padding: 12px;
      border-bottom: 1px solid #f3f4f6;
    }
    .security-table tr:hover {
      background: #f9fafb;
    }
    .security-table tr.selected {
      background: #eff6ff;
      border-left: 3px solid #3b82f6;
    }
    .btn-block {
      background: #dc2626;
      color: white;
      padding: 8px 16px;
      border: none;
      border-radius: 6px;
      font-weight: 500;
      cursor: pointer;
      margin-top: 10px;
    }
    .btn-block:hover {
      background: #b91c1c;
    }
    .btn-block:disabled {
      background: #9ca3af;
      cursor: not-allowed;
    }
    .table-container {
      overflow-x: auto;
      margin-top: 15px;
    }
    .status-badge {
      display: inline-block;
      padding: 4px 8px;
      border-radius: 4px;
      font-size: 12px;
      font-weight: 500;
    }
    .status-suspicious { background: #fef3c7; color: #92400e; }
    .status-blocked { background: #fee2e2; color: #991b1b; }
    .status-active { background: #d1fae5; color: #065f46; }
  </style>
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
      <h3>Suspicious Login Attempts</h3>
      <div class="table-container">
        <table class="security-table" id="suspiciousTable">
          <thead>
            <tr>
              <th>Time</th>
              <th>Username</th>
              <th>IP Address</th>
              <th>Incident Report</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <tr data-id="1" data-ip="192.168.1.100">
              <td>2024-01-15 14:32:18</td>
              <td>admin</td>
              <td>192.168.1.100</td>
              <td>Multiple failed login attempts - 5 tries in 2 minutes</td>
              <td><span class="status-badge status-suspicious">Suspicious</span></td>
            </tr>
            <tr data-id="2" data-ip="10.0.0.50">
              <td>2024-01-15 13:15:42</td>
              <td>root</td>
              <td>10.0.0.50</td>
              <td>Brute force attack detected - unusual login pattern</td>
              <td><span class="status-badge status-suspicious">Suspicious</span></td>
            </tr>
            <tr data-id="3" data-ip="172.16.0.25">
              <td>2024-01-15 12:08:55</td>
              <td>testuser</td>
              <td>172.16.0.25</td>
              <td>Suspicious activity - login from unauthorized location</td>
              <td><span class="status-badge status-suspicious">Suspicious</span></td>
            </tr>
          </tbody>
        </table>
      </div>
      <button class="btn-block" id="blockBtn" disabled onclick="blockSelectedIP()">Block Selected IP Address</button>
    </div>
    
    <div class="card">
      <h3>Blacklisted IP Addresses</h3>
      <div class="table-container">
        <table class="security-table" id="blacklistTable">
          <thead>
            <tr>
              <th>IP Address</th>
              <th>Reason</th>
              <th>Blocked By</th>
              <th>Blocked At</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td>203.0.113.1</td>
              <td>Repeated brute force attacks on admin account</td>
              <td>Admin User</td>
              <td>2024-01-10 09:15:30</td>
              <td><span class="status-badge status-blocked">Blocked</span></td>
            </tr>
            <tr>
              <td>198.51.100.45</td>
              <td>Suspicious login attempts from multiple accounts</td>
              <td>Security Admin</td>
              <td>2024-01-08 16:42:18</td>
              <td><span class="status-badge status-blocked">Blocked</span></td>
            </tr>
          </tbody>
        </table>
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

  <script>
    let selectedRow = null;
    let selectedIP = null;
    
    // Handle row selection in suspicious attempts table
    document.querySelectorAll('#suspiciousTable tbody tr').forEach(row => {
      row.addEventListener('click', function() {
        // Remove previous selection
        if (selectedRow) {
          selectedRow.classList.remove('selected');
        }
        
        // Select new row
        this.classList.add('selected');
        selectedRow = this;
        selectedIP = this.getAttribute('data-ip');
        
        // Enable block button
        document.getElementById('blockBtn').disabled = false;
      });
    });
    
    function blockSelectedIP() {
      if (!selectedIP) {
        alert('Please select a suspicious login attempt first.');
        return;
      }
      
      if (confirm(`Are you sure you want to block IP address ${selectedIP}? This will prevent all access from this IP address.`)) {
        // Get admin ID from session (in real implementation, this would come from server)
        const adminId = <?php echo isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 'null'; ?>;
        
        // Add to blacklist
        fetch('security_actions.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
          },
          body: JSON.stringify({
            action: 'block_ip',
            ip_address: selectedIP,
            reason: 'Blocked due to suspicious login activity',
            admin_id: adminId
          })
        })
        .then(response => response.json())
        .then(data => {
          if (data.success) {
            alert(`IP address ${selectedIP} has been blocked successfully.`);
            
            // Remove the row from suspicious table
            if (selectedRow) {
              selectedRow.remove();
              selectedRow = null;
              selectedIP = null;
              document.getElementById('blockBtn').disabled = true;
            }
            
            // Add to blacklist table
            addBlacklistEntry(selectedIP, 'Blocked due to suspicious login activity', 'Current Admin', new Date().toLocaleString());
            
            // Refresh data
            loadSuspiciousAttempts();
            loadBlacklist();
          } else {
            alert('Failed to block IP address: ' + (data.error || 'Unknown error'));
          }
        })
        .catch(error => {
          console.error('Error:', error);
          alert('An error occurred while blocking the IP address.');
        });
      }
    }
    
    function addBlacklistEntry(ip, reason, admin, blockedAt) {
      const tbody = document.querySelector('#blacklistTable tbody');
      const newRow = tbody.insertRow(0);
      newRow.innerHTML = `
        <td>${ip}</td>
        <td>${reason}</td>
        <td>${admin}</td>
        <td>${blockedAt}</td>
        <td><span class="status-badge status-blocked">Blocked</span></td>
      `;
    }
    
    function loadSuspiciousAttempts() {
      // In real implementation, fetch from database
      // For now, using static data
    }
    
    function loadBlacklist() {
      // In real implementation, fetch from database
      // For now, using static data
    }
    
    // Load data on page load
    document.addEventListener('DOMContentLoaded', function() {
      loadSuspiciousAttempts();
      loadBlacklist();
    });
  </script>
</body>
</html>
