<?php
session_start();
if (($_SESSION['role'] ?? '') !== 'seller'){
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
  <title>Seller • User Report</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../style/dashboard.css">
  <style>
    table{width:100%;border-collapse:collapse}
    th,td{padding:10px;border-bottom:1px solid #e5e7eb;text-align:left}
    th{font-weight:600;color:#374151}
    .btn-report{background:#ef4444;color:white;padding:8px 16px;border:none;border-radius:6px;cursor:pointer;font-weight:500}
    .btn-report:hover{background:#dc2626}
    .btn-report:disabled{background:#9ca3af;cursor:not-allowed}
    .modal{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:1000}
    .modal-content{background:white;padding:24px;border-radius:12px;max-width:500px;margin:50px auto}
    .form-group{margin-bottom:16px}
    .form-group label{display:block;margin-bottom:4px;font-weight:500}
    .form-group input,.form-group textarea{width:100%;padding:8px;border:1px solid #e5e7eb;border-radius:6px}
    .form-group textarea{min-height:100px;resize:vertical}
    .modal-buttons{display:flex;gap:12px;justify-content:flex-end;margin-top:20px}
    .btn-cancel{background:#6b7280;color:white;padding:8px 16px;border:none;border-radius:6px;cursor:pointer}
    .btn-submit{background:#3b82f6;color:white;padding:8px 16px;border:none;border-radius:6px;cursor:pointer}
    .btn-submit:hover{background:#2563eb}
    .btn-cancel:hover{background:#4b5563}
  </style>
</head>
<body>
  <nav class="navbar">
    <div class="nav-left"><div class="brand">User Report</div></div>
    <div class="nav-right"><a class="btn" href="../dashboard.php">Back to Dashboard</a></div>
  </nav>
  <div class="wrap">
    <div class="card">
      <h1>Recent Transactions</h1>
      <div class="table-scroll">
        <table class="table-simple" id="transactionsTable">
          <thead>
            <tr>
              <th>Date</th>
              <th>Buyer</th>
              <th>Listing</th>
              <th>Status</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <tr data-seller-id="1" data-buyer-id="2" data-listing-id="101">
              <td>2024-01-15</td>
              <td>Maria Santos</td>
              <td>Organic Chicken - 5kg</td>
              <td>Completed</td>
              <td><button class="btn-report" onclick="openReportModal(this)">Report User</button></td>
            </tr>
            <tr data-seller-id="1" data-buyer-id="4" data-listing-id="104">
              <td>2024-01-13</td>
              <td>Carlos Rodriguez</td>
              <td>Fresh Vegetables Bundle</td>
              <td>Completed</td>
              <td><button class="btn-report" onclick="openReportModal(this)">Report User</button></td>
            </tr>
            <tr data-seller-id="1" data-buyer-id="6" data-listing-id="105">
              <td>2024-01-10</td>
              <td>Lisa Chen</td>
              <td>Free-range Eggs - 24 pcs</td>
              <td>In Progress</td>
              <td><button class="btn-report" onclick="openReportModal(this)">Report User</button></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Report Modal -->
  <div id="reportModal" class="modal">
    <div class="modal-content">
      <h2>Report User</h2>
      <form id="reportForm">
        <div class="form-group">
          <label for="reportTitle">Title</label>
          <input type="text" id="reportTitle" name="title" required>
        </div>
        <div class="form-group">
          <label for="reportDescription">Description</label>
          <textarea id="reportDescription" name="description" required placeholder="Please provide details about the issue..."></textarea>
        </div>
        <div class="modal-buttons">
          <button type="button" class="btn-cancel" onclick="closeReportModal()">Cancel</button>
          <button type="submit" class="btn-submit">Submit Report</button>
        </div>
      </form>
    </div>
  </div>

  <script>
    let currentRow = null;
    let currentSellerId = null;
    let currentBuyerId = null;
    let currentListingId = null;

    function openReportModal(button) {
      currentRow = button.closest('tr');
      currentSellerId = currentRow.getAttribute('data-seller-id');
      currentBuyerId = currentRow.getAttribute('data-buyer-id');
      currentListingId = currentRow.getAttribute('data-listing-id');
      
      document.getElementById('reportModal').style.display = 'block';
      document.getElementById('reportForm').reset();
    }

    function closeReportModal() {
      document.getElementById('reportModal').style.display = 'none';
      currentRow = null;
      currentSellerId = null;
      currentBuyerId = null;
      currentListingId = null;
    }

    // Handle form submission
    document.getElementById('reportForm').addEventListener('submit', function(e) {
      e.preventDefault();
      
      const title = document.getElementById('reportTitle').value;
      const description = document.getElementById('reportDescription').value;
      
      if (!title || !description) {
        alert('Please fill in all fields');
        return;
      }

      // Submit report
      fetch('user_report_actions.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          action: 'submit_report',
          seller_id: currentSellerId,
          buyer_id: currentBuyerId,
          title: title,
          description: description
        })
      })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          closeReportModal();
          alert('Thank you for reporting to the authorities. We will try what we can to assess the situation.');
          
          // Disable the report button for this transaction
          if (currentRow) {
            const reportButton = currentRow.querySelector('.btn-report');
            reportButton.disabled = true;
            reportButton.textContent = 'Reported';
            reportButton.style.background = '#9ca3af';
          }
        } else {
          alert('Failed to submit report: ' + (data.error || 'Unknown error'));
        }
      })
      .catch(error => {
        console.error('Error:', error);
        alert('An error occurred while submitting the report.');
      });
    });

    // Close modal when clicking outside
    window.onclick = function(event) {
      const modal = document.getElementById('reportModal');
      if (event.target === modal) {
        closeReportModal();
      }
    }
  </script>
</body>
</html>
