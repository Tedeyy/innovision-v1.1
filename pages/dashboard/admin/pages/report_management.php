<?php
session_start();
if (($_SESSION['role'] ?? '') !== 'admin'){
  http_response_code(302);
  header('Location: ../dashboard.php');
  exit;
}
require_once __DIR__ . '/../../../authentication/lib/supabase_client.php';
function safe($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin • Report Management</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../style/dashboard.css">
  <style>
    .section-card{background:white;border-radius:12px;padding:20px;margin-bottom:20px;border:1px solid #e5e7eb}
    .section-title{font-size:18px;font-weight:600;margin-bottom:16px;color:#111827}
    table{width:100%;border-collapse:collapse}
    th,td{padding:10px;border-bottom:1px solid #e5e7eb;text-align:left}
    th{font-weight:600;color:#374151;background:#f9fafb}
    .btn{padding:6px 12px;border:none;border-radius:6px;cursor:pointer;font-size:12px;font-weight:500}
    .btn-view{background:#3b82f6;color:white}
    .btn-view:hover{background:#2563eb}
    .btn-approve{background:#10b981;color:white}
    .btn-approve:hover{background:#059669}
    .btn-disregard{background:#ef4444;color:white}
    .btn-disregard:hover{background:#dc2626}
    .btn-penalty{background:#f59e0b;color:white}
    .btn-penalty:hover{background:#d97706}
    .modal{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:1000}
    .modal-content{background:white;padding:24px;border-radius:12px;max-width:600px;margin:50px auto;max-height:80vh;overflow-y:auto}
    .modal-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px}
    .modal-title{font-size:20px;font-weight:600}
    .modal-close{background:none;border:none;font-size:24px;cursor:pointer;color:#6b7280}
    .detail-row{display:flex;margin-bottom:12px}
    .detail-label{font-weight:500;width:120px;color:#374151}
    .detail-value{flex:1;color:#111827}
    .modal-buttons{display:flex;gap:12px;justify-content:flex-end;margin-top:20px}
    .status-badge{display:inline-block;padding:4px 8px;border-radius:4px;font-size:11px;font-weight:500}
    .status-pending{background:#fef3c7;color:#92400e}
    .status-verified{background:#d1fae5;color:#065f46}
    .penalty-options{display:flex;flex-direction:column;gap:8px;margin:20px 0}
    .penalty-option{display:flex;align-items:center;gap:8px}
    .penalty-option input{margin:0}
  </style>
</head>
<body>
  <nav class="navbar">
    <div class="nav-left"><div class="brand">Report Management</div></div>
    <div class="nav-right"><a class="btn" href="../dashboard.php">Back to Dashboard</a></div>
  </nav>
  <div class="wrap">
    
    <!-- Pending Reports -->
    <div class="section-card">
      <h2 class="section-title">Pending Reports Review</h2>
      <div class="table-scroll">
        <table id="pendingReports">
          <thead>
            <tr>
              <th>Report ID</th>
              <th>Seller</th>
              <th>Buyer</th>
              <th>Title</th>
              <th>Date</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr data-report-id="1" data-seller-id="1" data-buyer-id="2">
              <td>#001</td>
              <td>John's Farm</td>
              <td>Maria Santos</td>
              <td>Fraudulent Payment</td>
              <td>2024-01-15</td>
              <td><span class="status-badge status-pending">Pending</span></td>
              <td>
                <button class="btn btn-view" onclick="viewReportDetails(this)">View</button>
                <button class="btn btn-approve" onclick="approveReport(this)">Approve</button>
                <button class="btn btn-disregard" onclick="disregardReport(this)">Disregard</button>
              </td>
            </tr>
            <tr data-report-id="2" data-seller-id="3" data-buyer-id="4">
              <td>#002</td>
              <td>Green Meadows</td>
              <td>Carlos Rodriguez</td>
              <td>Misrepresented Product</td>
              <td>2024-01-14</td>
              <td><span class="status-badge status-pending">Pending</span></td>
              <td>
                <button class="btn btn-view" onclick="viewReportDetails(this)">View</button>
                <button class="btn btn-approve" onclick="approveReport(this)">Approve</button>
                <button class="btn btn-disregard" onclick="disregardReport(this)">Disregard</button>
              </td>
            </tr>
            <tr data-report-id="3" data-seller-id="5" data-buyer-id="6">
              <td>#003</td>
              <td>Sunny Valley Ranch</td>
              <td>Lisa Chen</td>
              <td>Non-delivery of Goods</td>
              <td>2024-01-13</td>
              <td><span class="status-badge status-pending">Pending</span></td>
              <td>
                <button class="btn btn-view" onclick="viewReportDetails(this)">View</button>
                <button class="btn btn-approve" onclick="approveReport(this)">Approve</button>
                <button class="btn btn-disregard" onclick="disregardReport(this)">Disregard</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Verified Reports (Violators) -->
    <div class="section-card">
      <h2 class="section-title">Verified Reports - Violators</h2>
      <div class="table-scroll">
        <table id="verifiedReports">
          <thead>
            <tr>
              <th>Report ID</th>
              <th>Seller</th>
              <th>Buyer</th>
              <th>Title</th>
              <th>Verified Date</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr data-report-id="4" data-seller-id="7" data-buyer-id="8">
              <td>#004</td>
              <td>Happy Farms</td>
              <td>David Kim</td>
              <td>Scam Transaction</td>
              <td>2024-01-10</td>
              <td><span class="status-badge status-verified">Verified</span></td>
              <td>
                <button class="btn btn-penalty" onclick="givePenalty(this)">Give Penalty</button>
              </td>
            </tr>
            <tr data-report-id="5" data-seller-id="9" data-buyer-id="10">
              <td>#005</td>
              <td>Organic Valley</td>
              <td>Sarah Johnson</td>
              <td>Fake Product Listing</td>
              <td>2024-01-08</td>
              <td><span class="status-badge status-verified">Verified</span></td>
              <td>
                <button class="btn btn-penalty" onclick="givePenalty(this)">Give Penalty</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Users with Penalties -->
    <div class="section-card">
      <h2 class="section-title">Users with Active Penalties</h2>
      <div class="table-scroll">
        <table id="penaltyUsers">
          <thead>
            <tr>
              <th>User</th>
              <th>Penalty Type</th>
              <th>Penalty Start</th>
              <th>Penalty End</th>
              <th>Reason</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td>Bad User</td>
              <td>1 Week Ban</td>
              <td>2024-01-05</td>
              <td>2024-01-12</td>
              <td>Multiple fraudulent transactions</td>
              <td><span class="status-badge status-verified">Active</span></td>
            </tr>
            <tr>
              <td>Scammer Account</td>
              <td>3 Days Ban</td>
              <td>2024-01-15</td>
              <td>2024-01-18</td>
              <td>False advertising</td>
              <td><span class="status-badge status-verified">Active</span></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Report Details Modal -->
  <div id="reportModal" class="modal">
    <div class="modal-content">
      <div class="modal-header">
        <h3 class="modal-title">Report Details</h3>
        <button class="modal-close" onclick="closeModal('reportModal')">&times;</button>
      </div>
      <div id="reportDetails"></div>
      <div class="modal-buttons">
        <button class="btn btn-approve" onclick="approveFromModal()">Approve</button>
        <button class="btn btn-disregard" onclick="disregardFromModal()">Disregard</button>
        <button class="btn" style="background:#6b7280;color:white" onclick="closeModal('reportModal')">Close</button>
      </div>
    </div>
  </div>

  <!-- Penalty Modal -->
  <div id="penaltyModal" class="modal">
    <div class="modal-content">
      <div class="modal-header">
        <h3 class="modal-title">Give Penalty</h3>
        <button class="modal-close" onclick="closeModal('penaltyModal')">&times;</button>
      </div>
      <div id="penaltyDetails"></div>
      <div class="penalty-options">
        <h4>Select Penalty Duration:</h4>
        <div class="penalty-option">
          <input type="radio" id="3days" name="penalty" value="3 days">
          <label for="3days">3 Days Ban</label>
        </div>
        <div class="penalty-option">
          <input type="radio" id="1week" name="penalty" value="1 week">
          <label for="1week">1 Week Ban</label>
        </div>
        <div class="penalty-option">
          <input type="radio" id="1month" name="penalty" value="1 month">
          <label for="1month">1 Month Ban</label>
        </div>
        <div class="penalty-option">
          <input type="radio" id="6months" name="penalty" value="6 months">
          <label for="6months">6 Months Ban</label>
        </div>
        <div class="penalty-option">
          <input type="radio" id="1year" name="penalty" value="1 year">
          <label for="1year">1 Year Ban</label>
        </div>
      </div>
      <div class="modal-buttons">
        <button class="btn btn-penalty" onclick="applyPenalty()">Apply Penalty</button>
        <button class="btn" style="background:#6b7280;color:white" onclick="closeModal('penaltyModal')">Cancel</button>
      </div>
    </div>
  </div>

  <script>
    let currentReportRow = null;
    let currentReportId = null;
    let currentSellerId = null;
    let currentBuyerId = null;

    function viewReportDetails(button) {
      const row = button.closest('tr');
      currentReportRow = row;
      currentReportId = row.getAttribute('data-report-id');
      currentSellerId = row.getAttribute('data-seller-id');
      currentBuyerId = row.getAttribute('data-buyer-id');
      
      const cells = row.cells;
      const details = `
        <div class="detail-row">
          <div class="detail-label">Report ID:</div>
          <div class="detail-value">#${currentReportId}</div>
        </div>
        <div class="detail-row">
          <div class="detail-label">Seller:</div>
          <div class="detail-value">${cells[1].textContent}</div>
        </div>
        <div class="detail-row">
          <div class="detail-label">Buyer:</div>
          <div class="detail-value">${cells[2].textContent}</div>
        </div>
        <div class="detail-row">
          <div class="detail-label">Title:</div>
          <div class="detail-value">${cells[3].textContent}</div>
        </div>
        <div class="detail-row">
          <div class="detail-label">Date:</div>
          <div class="detail-value">${cells[4].textContent}</div>
        </div>
        <div class="detail-row">
          <div class="detail-label">Description:</div>
          <div class="detail-value">This is a sample description. In a real implementation, this would show the full report details from the database.</div>
        </div>
      `;
      
      document.getElementById('reportDetails').innerHTML = details;
      document.getElementById('reportModal').style.display = 'block';
    }

    function closeModal(modalId) {
      document.getElementById(modalId).style.display = 'none';
    }

    function approveReport(button) {
      const row = button.closest('tr');
      const reportId = row.getAttribute('data-report-id');
      
      if (confirm('Are you sure you want to approve this report?')) {
        fetch('report_management_actions.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            action: 'approve_report',
            report_id: reportId,
            seller_id: row.getAttribute('data-seller-id'),
            buyer_id: row.getAttribute('data-buyer-id')
          })
        })
        .then(response => response.json())
        .then(data => {
          if (data.success) {
            // Move to verified reports table
            moveToVerified(row);
            alert('Report approved and moved to verified reports.');
          } else {
            alert('Failed to approve report: ' + (data.error || 'Unknown error'));
          }
        })
        .catch(error => {
          console.error('Error:', error);
          alert('An error occurred while approving the report.');
        });
      }
    }

    function disregardReport(button) {
      const row = button.closest('tr');
      const reportId = row.getAttribute('data-report-id');
      
      if (confirm('Are you sure you want to disregard this report?')) {
        fetch('report_management_actions.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            action: 'disregard_report',
            report_id: reportId
          })
        })
        .then(response => response.json())
        .then(data => {
          if (data.success) {
            row.remove();
            alert('Report disregarded successfully.');
          } else {
            alert('Failed to disregard report: ' + (data.error || 'Unknown error'));
          }
        })
        .catch(error => {
          console.error('Error:', error);
          alert('An error occurred while disregarding the report.');
        });
      }
    }

    function givePenalty(button) {
      const row = button.closest('tr');
      currentReportRow = row;
      currentReportId = row.getAttribute('data-report-id');
      currentSellerId = row.getAttribute('data-seller-id');
      currentBuyerId = row.getAttribute('data-buyer-id');
      
      const cells = row.cells;
      const details = `
        <div class="detail-row">
          <div class="detail-label">Report ID:</div>
          <div class="detail-value">#${currentReportId}</div>
        </div>
        <div class="detail-row">
          <div class="detail-label">Seller:</div>
          <div class="detail-value">${cells[1].textContent}</div>
        </div>
        <div class="detail-row">
          <div class="detail-label">Buyer:</div>
          <div class="detail-value">${cells[2].textContent}</div>
        </div>
        <div class="detail-row">
          <div class="detail-label">Title:</div>
          <div class="detail-value">${cells[3].textContent}</div>
        </div>
      `;
      
      document.getElementById('penaltyDetails').innerHTML = details;
      document.getElementById('penaltyModal').style.display = 'block';
    }

    function applyPenalty() {
      const selectedPenalty = document.querySelector('input[name="penalty"]:checked');
      if (!selectedPenalty) {
        alert('Please select a penalty duration.');
        return;
      }
      
      const penaltyDuration = selectedPenalty.value;
      
      if (confirm(`Are you sure you want to apply a ${penaltyDuration} penalty?`)) {
        fetch('report_management_actions.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            action: 'apply_penalty',
            report_id: currentReportId,
            seller_id: currentSellerId,
            buyer_id: currentBuyerId,
            penalty_duration: penaltyDuration
          })
        })
        .then(response => response.json())
        .then(data => {
          if (data.success) {
            // Move to penalty users table
            moveToPenaltyUsers(currentReportRow, penaltyDuration);
            closeModal('penaltyModal');
            alert('Penalty applied successfully.');
          } else {
            alert('Failed to apply penalty: ' + (data.error || 'Unknown error'));
          }
        })
        .catch(error => {
          console.error('Error:', error);
          alert('An error occurred while applying the penalty.');
        });
      }
    }

    function moveToVerified(row) {
      const verifiedTable = document.querySelector('#verifiedReports tbody');
      const newRow = verifiedTable.insertRow(0);
      newRow.innerHTML = row.innerHTML;
      newRow.setAttribute('data-report-id', row.getAttribute('data-report-id'));
      newRow.setAttribute('data-seller-id', row.getAttribute('data-seller-id'));
      newRow.setAttribute('data-buyer-id', row.getAttribute('data-buyer-id'));
      
      // Update status badge
      const statusCell = newRow.cells[5];
      statusCell.innerHTML = '<span class="status-badge status-verified">Verified</span>';
      
      // Update actions
      const actionsCell = newRow.cells[6];
      actionsCell.innerHTML = '<button class="btn btn-penalty" onclick="givePenalty(this)">Give Penalty</button>';
      
      // Add verified date
      newRow.cells[4].textContent = new Date().toLocaleDateString();
      
      row.remove();
    }

    function moveToPenaltyUsers(row, penaltyDuration) {
      const penaltyTable = document.querySelector('#penaltyUsers tbody');
      const newRow = penaltyTable.insertRow(0);
      
      const userName = row.cells[1].textContent + ' / ' + row.cells[2].textContent;
      const penaltyStart = new Date().toLocaleDateString();
      const penaltyEnd = calculatePenaltyEndDate(penaltyDuration);
      const reason = row.cells[3].textContent;
      
      newRow.innerHTML = `
        <td>${userName}</td>
        <td>${penaltyDuration} Ban</td>
        <td>${penaltyStart}</td>
        <td>${penaltyEnd}</td>
        <td>${reason}</td>
        <td><span class="status-badge status-verified">Active</span></td>
      `;
      
      row.remove();
    }

    function calculatePenaltyEndDate(duration) {
      const now = new Date();
      let endDate = new Date(now);
      
      switch(duration) {
        case '3 days':
          endDate.setDate(now.getDate() + 3);
          break;
        case '1 week':
          endDate.setDate(now.getDate() + 7);
          break;
        case '1 month':
          endDate.setMonth(now.getMonth() + 1);
          break;
        case '6 months':
          endDate.setMonth(now.getMonth() + 6);
          break;
        case '1 year':
          endDate.setFullYear(now.getFullYear() + 1);
          break;
      }
      
      return endDate.toLocaleDateString();
    }

    function approveFromModal() {
      if (currentReportRow) {
        const button = currentReportRow.querySelector('.btn-approve');
        if (button) approveReport(button);
        closeModal('reportModal');
      }
    }

    function disregardFromModal() {
      if (currentReportRow) {
        const button = currentReportRow.querySelector('.btn-disregard');
        if (button) disregardReport(button);
        closeModal('reportModal');
      }
    }

    // Close modals when clicking outside
    window.onclick = function(event) {
      if (event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
      }
    }
  </script>
</body>
</html>
