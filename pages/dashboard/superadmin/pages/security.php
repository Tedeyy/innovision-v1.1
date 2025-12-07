<?php
session_start();
require_once __DIR__ . '/../../../authentication/lib/supabase_client.php';
// Fetch data from Supabase
function sa_get($table, $params){
  [$rows,$st,$err] = sb_rest('GET',$table,$params);
  return ($st>=200 && $st<300 && is_array($rows)) ? $rows : [];
}
$suspicious = sa_get('suspicious_login_attempts', ['select'=>'slogin_id,username,ip_address,incident_report,log_time','order'=>'log_time.desc']);
$blacklist  = sa_get('blacklist', ['select'=>'block_id,ip_address,reason,admin_id,blocked_at','order'=>'blocked_at.desc']);
$loginlogs  = sa_get('login_logs', ['select'=>'login_id,user_id,ip_address,log_time','order'=>'log_time.desc','limit'=>'50']);
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
    @media (max-width:640px){
      .security-table{font-size:12px}
      .security-table th{padding:8px 10px}
      .security-table td{padding:8px 10px}
      .table-container{margin-top:10px}
      .btn-block{padding:6px 10px;font-size:12px;border-radius:6px}
      .status-badge{padding:2px 6px;font-size:11px}
      #status{grid-template-columns:1fr !important;gap:8px}
      .navbar{padding:8px 10px}
      .wrap .card{padding:12px}
      /* Make wide tables fit */
      #events, .security-table{table-layout:fixed;word-wrap:break-word}
    }
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
            <?php if (is_array($suspicious)):
              foreach ($suspicious as $row): ?>
              <tr data-id="<?php echo (int)($row['slogin_id']??0); ?>" data-ip="<?php echo htmlspecialchars($row['ip_address']??'', ENT_QUOTES,'UTF-8'); ?>">
                <td><?php echo htmlspecialchars($row['log_time']??'', ENT_QUOTES,'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars($row['username']??'', ENT_QUOTES,'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars($row['ip_address']??'', ENT_QUOTES,'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars($row['incident_report']??'', ENT_QUOTES,'UTF-8'); ?></td>
                <td><span class="status-badge status-suspicious">Suspicious</span></td>
              </tr>
            <?php endforeach; endif; ?>
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
            <?php if (is_array($blacklist)):
              foreach ($blacklist as $row): ?>
              <tr>
                <td><?php echo htmlspecialchars($row['ip_address']??'', ENT_QUOTES,'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars($row['reason']??'', ENT_QUOTES,'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars((string)($row['admin_id']??''), ENT_QUOTES,'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars($row['blocked_at']??'', ENT_QUOTES,'UTF-8'); ?></td>
                <td><span class="status-badge status-blocked">Blocked</span></td>
              </tr>
            <?php endforeach; endif; ?>
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
          <tbody>
            <?php if (is_array($loginlogs)):
              foreach ($loginlogs as $row): ?>
              <tr>
                <td style="padding:8px;border-bottom:1px solid #f3f4f6;">&nbsp;<?php echo htmlspecialchars($row['log_time']??'', ENT_QUOTES,'UTF-8'); ?></td>
                <td style="padding:8px;border-bottom:1px solid #f3f4f6;">User #<?php echo (int)($row['user_id']??0); ?></td>
                <td style="padding:8px;border-bottom:1px solid #f3f4f6;">Login</td>
                <td style="padding:8px;border-bottom:1px solid #f3f4f6;">IP: <?php echo htmlspecialchars($row['ip_address']??'', ENT_QUOTES,'UTF-8'); ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
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
            // Reload to reflect changes
            location.reload();
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
    
    // Initial rows are server-rendered; no client reload needed on load
  </script>
</body>
</html>
