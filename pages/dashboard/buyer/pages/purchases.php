<?php
session_start();
require_once __DIR__ . '/../../../authentication/lib/supabase_client.php';

function safe($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$buyerId = (int)($_SESSION['user_id'] ?? 0);

// Handle AJAX request for purchase data
if (isset($_GET['action']) && $_GET['action'] === 'list') {
    header('Content-Type: application/json');
    
    // Build filters
    $filters = [];
    $andConds = [];
    
    if (!empty($_GET['q'])) {
        $search = trim($_GET['q']);
        $andConds[] = 'or(livestock_type.ilike.*' . $search . '*,breed.ilike.*' . $search . '*,seller_name.ilike.*' . $search . '*)';
    }
    
    if (!empty($_GET['type'])) {
        $filters['livestock_type'] = 'eq.' . $_GET['type'];
    }
    
    if (!empty($_GET['from'])) {
        $andConds[] = 'transaction_date.gte.' . $_GET['from'] . 'T00:00:00Z';
    }
    
    if (!empty($_GET['to'])) {
        $andConds[] = 'transaction_date.lte.' . $_GET['to'] . 'T23:59:59Z';
    }
    
    if (!empty($_GET['status'])) {
        $filters['status'] = 'eq.' . $_GET['status'];
    }
    
    $params = array_merge([
        'select' => 'trans_id,transaction_id,listing_id,seller_id,buyer_id,price,payment_method,status,transaction_date,livestock_type,breed,seller_name',
        'buyer_id' => 'eq.' . $buyerId,
        'order' => 'transaction_date.desc'
    ], $filters);
    
    if (count($andConds)) {
        $params['and'] = '(' . implode(',', $andConds) . ')';
    }
    
    [$rows, $status, $error] = sb_rest('GET', 'successfultransactions', $params);
    
    if (!($status >= 200 && $status < 300) || !is_array($rows)) {
        $rows = [];
    }
    
    echo json_encode(['ok' => true, 'data' => $rows]);
    exit;
}

// Handle detail view request
if (isset($_GET['action']) && $_GET['action'] === 'detail') {
    header('Content-Type: application/json');
    
    $transId = (int)($_GET['trans_id'] ?? 0);
    
    if ($transId <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Invalid transaction ID']);
        exit;
    }
    
    // Get transaction details with seller and listing info
    [$rows, $status, $error] = sb_rest('GET', 'successfultransactions', [
        'select' => 'trans_id,transaction_id,listing_id,seller_id,buyer_id,price,payment_method,status,transaction_date,livestock_type,breed,seller_name,address,age,weight',
        'trans_id' => 'eq.' . $transId,
        'buyer_id' => 'eq.' . $buyerId,
        'limit' => 1
    ]);
    
    if (!($status >= 200 && $status < 300) || !is_array($rows) || empty($rows)) {
        echo json_encode(['ok' => false, 'error' => 'Transaction not found']);
        exit;
    }
    
    echo json_encode(['ok' => true, 'data' => $rows[0]]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Purchases</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../style/dashboard.css">
  <style>
    .modal{position:fixed;inset:0;background:rgba(0,0,0,0.5);display:none;align-items:center;justify-content:center;z-index:9999}
    .panel{background:#fff;border-radius:10px;max-width:600px;width:95vw;max-height:90vh;overflow:auto;padding:16px}
    .close-btn{background:#e53e3e;color:#fff;border:none;border-radius:6px;padding:6px 10px;cursor:pointer}
    .detail-grid{display:grid;grid-template-columns:120px 1fr;gap:12px;margin-top:12px}
    .detail-label{font-weight:600;color:#4a5568}
    
    /* Mobile responsive styles */
    @media (max-width: 768px) {
      .panel{width:98vw;padding:12px}
      .detail-grid{grid-template-columns:1fr;gap:8px}
      .filters{grid-template-columns:repeat(3,1fr) !important}
    }
    
    @media (max-width: 480px) {
      .panel{width:100vw;height:100vh;max-height:100vh;border-radius:0;padding:8px}
      .filters{grid-template-columns:repeat(2,1fr) !important}
    }
  </style>
</head>
<body>
  <nav class="navbar">
    <div class="nav-left">
      <div class="brand">Purchases</div>
    </div>
    <div class="nav-right">
      <a class="btn" href="../dashboard.php">Back to Dashboard</a>
    </div>
  </nav>
  <div class="wrap">
    <div class="card">
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:8px;margin-bottom:12px" class="filters">
        <input id="q" type="search" placeholder="Search" />
        <select id="type">
          <option value="">All Types</option>
        </select>
        <input id="from" type="date" />
        <input id="to" type="date" />
        <select id="status">
          <option value="">All Status</option>
          <option value="Successful">Successful</option>
          <option value="Refunded">Refunded</option>
        </select>
        <button class="btn" id="applyFilters">Apply</button>
        <button class="btn" id="clearFilters" style="background:#718096;">Clear</button>
      </div>
    </div>
    <div class="card">
      <div style="overflow:auto">
        <table style="width:100%;border-collapse:collapse" id="purchases">
          <thead>
            <tr style="text-align:left;border-bottom:1px solid #e2e8f0">
              <th style="padding:8px">Purchase #</th>
              <th style="padding:8px">Listing</th>
              <th style="padding:8px">Type</th>
              <th style="padding:8px">Seller</th>
              <th style="padding:8px">Price</th>
              <th style="padding:8px">Date</th>
              <th style="padding:8px">Status</th>
              <th style="padding:8px">Actions</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
    </div>
  </div>
  
  <!-- Detail Modal -->
  <div id="detailModal" class="modal">
    <div class="panel">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:12px">
        <h2 style="margin:0">Purchase Details</h2>
        <button class="close-btn" data-close="detailModal">Close</button>
      </div>
      <div id="detailContent"></div>
    </div>
  </div>
  
  <script>
    (function(){
      function $(s){ return document.querySelector(s); }
      function $all(s){ return Array.prototype.slice.call(document.querySelectorAll(s)); }
      function openModal(id){ var el=document.getElementById(id); if(el) el.style.display='flex'; }
      function closeModal(id){ var el=document.getElementById(id); if(el) el.style.display='none'; }
      
      $all('.close-btn').forEach(function(b){ 
        b.addEventListener('click', function(){ closeModal(b.getAttribute('data-close')); }); 
      });
      
      function loadPurchases(){
        var params = new URLSearchParams();
        params.append('action', 'list');
        
        var q = $('#q').value.trim();
        var type = $('#type').value;
        var from = $('#from').value;
        var to = $('#to').value;
        var status = $('#status').value;
        
        if (q) params.append('q', q);
        if (type) params.append('type', type);
        if (from) params.append('from', from);
        if (to) params.append('to', to);
        if (status) params.append('status', status);
        
        fetch('purchases.php?' + params.toString(), { credentials: 'same-origin' })
          .then(function(r){ return r.json(); })
          .then(function(res){
            var tbody = $('#purchases tbody');
            tbody.innerHTML = '';
            
            if (res && res.ok && res.data) {
              res.data.forEach(function(row){
                var tr = document.createElement('tr');
                tr.innerHTML = 
                  '<td style="padding:8px">' + (row.trans_id || '') + '</td>' +
                  '<td style="padding:8px">' + (row.livestock_type || '') + ' • ' + (row.breed || '') + '</td>' +
                  '<td style="padding:8px">' + (row.livestock_type || '') + '</td>' +
                  '<td style="padding:8px">' + (row.seller_name || '') + '</td>' +
                  '<td style="padding:8px">₱' + (row.price || '0') + '</td>' +
                  '<td style="padding:8px">' + (row.transaction_date ? new Date(row.transaction_date).toLocaleDateString() : '') + '</td>' +
                  '<td style="padding:8px">' + (row.status || '') + '</td>' +
                  '<td style="padding:8px"><button class="btn btn-show" data-id="' + (row.trans_id || '') + '">Show</button></td>';
                tbody.appendChild(tr);
              });
            }
          })
          .catch(function(err){
            console.error('Error loading purchases:', err);
          });
      }
      
      function showDetail(transId){
        fetch('purchases.php?action=detail&trans_id=' + encodeURIComponent(transId), { credentials: 'same-origin' })
          .then(function(r){ return r.json(); })
          .then(function(res){
            if (res && res.ok && res.data) {
              var data = res.data;
              var content = $('#detailContent');
              content.innerHTML = 
                '<div class="detail-grid">' +
                  '<div class="detail-label">Purchase #:</div><div>' + (data.trans_id || '') + '</div>' +
                  '<div class="detail-label">Listing:</div><div>' + (data.livestock_type || '') + ' • ' + (data.breed || '') + '</div>' +
                  '<div class="detail-label">Type:</div><div>' + (data.livestock_type || '') + '</div>' +
                  '<div class="detail-label">Seller:</div><div>' + (data.seller_name || '') + '</div>' +
                  '<div class="detail-label">Price:</div><div>₱' + (data.price || '0') + '</div>' +
                  '<div class="detail-label">Payment Method:</div><div>' + (data.payment_method || '') + '</div>' +
                  '<div class="detail-label">Status:</div><div>' + (data.status || '') + '</div>' +
                  '<div class="detail-label">Transaction Date:</div><div>' + (data.transaction_date ? new Date(data.transaction_date).toLocaleString() : '') + '</div>' +
                  '<div class="detail-label">Address:</div><div>' + (data.address || '') + '</div>' +
                  '<div class="detail-label">Age:</div><div>' + (data.age || '') + '</div>' +
                  '<div class="detail-label">Weight:</div><div>' + (data.weight || '') + ' kg</div>' +
                '</div>';
              openModal('detailModal');
            } else {
              alert('Failed to load details: ' + (res.error || 'Unknown error'));
            }
          })
          .catch(function(err){
            console.error('Error loading details:', err);
            alert('Error loading details');
          });
      }
      
      // Event listeners
      $('#applyFilters').addEventListener('click', loadPurchases);
      
      $('#clearFilters').addEventListener('click', function(){
        $('#q').value = '';
        $('#type').value = '';
        $('#from').value = '';
        $('#to').value = '';
        $('#status').value = '';
        loadPurchases();
      });
      
      // Delegate show button clicks
      $('#purchases').addEventListener('click', function(e){
        if (e.target && e.target.classList.contains('btn-show')) {
          var transId = e.target.getAttribute('data-id');
          if (transId) {
            showDetail(transId);
          }
        }
      });
      
      // Close modal on background click
      $('#detailModal').addEventListener('click', function(e){
        if (e.target === this) {
          closeModal('detailModal');
        }
      });
      
      // Initial load
      loadPurchases();
    })();
  </script>
</body>
</html>
