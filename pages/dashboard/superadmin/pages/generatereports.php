<?php
session_start();
require_once __DIR__ . '/../../../authentication/lib/supabase_client.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Livestock Trading Reports</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../style/dashboard.css">
  <style>
    .report-container {
      max-width: 1200px;
      margin: 0 auto;
      padding: 20px;
    }
    .report-header {
      text-align: center;
      margin-bottom: 30px;
      padding: 20px;
      border-bottom: 2px solid #e5e7eb;
    }
    .report-title {
      font-size: 28px;
      font-weight: 600;
      color: #1f2937;
      margin-bottom: 10px;
    }
    .report-period {
      font-size: 16px;
      color: #6b7280;
    }
    .report-section {
      margin-bottom: 30px;
      padding: 20px;
      background: #f9fafb;
      border-radius: 8px;
      border: 1px solid #e5e7eb;
    }
    .section-title {
      font-size: 18px;
      font-weight: 600;
      color: #374151;
      margin-bottom: 15px;
      border-bottom: 1px solid #d1d5db;
      padding-bottom: 8px;
    }
    .stats-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 20px;
      margin-bottom: 20px;
    }
    .stat-card {
      background: white;
      padding: 20px;
      border-radius: 8px;
      border: 1px solid #e5e7eb;
      text-align: center;
    }
    .stat-value {
      font-size: 24px;
      font-weight: 700;
      color: #059669;
      margin-bottom: 5px;
    }
    .stat-label {
      font-size: 14px;
      color: #6b7280;
    }
    .table-container {
      overflow-x: auto;
      margin-top: 20px;
    }
    .report-table {
      width: 100%;
      border-collapse: collapse;
      background: white;
      border-radius: 8px;
      overflow: hidden;
      border: 1px solid #e5e7eb;
    }
    .report-table th {
      background: #f3f4f6;
      padding: 12px;
      text-align: left;
      font-weight: 600;
      color: #374151;
      border-bottom: 1px solid #e5e7eb;
    }
    .report-table td {
      padding: 12px;
      border-bottom: 1px solid #f3f4f6;
    }
    .report-table tr:hover {
      background: #f9fafb;
    }
    .filter-section {
      background: white;
      padding: 20px;
      border-radius: 8px;
      border: 1px solid #e5e7eb;
      margin-bottom: 20px;
    }
    .filter-group {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 15px;
      margin-bottom: 15px;
    }
    .filter-group label {
      display: block;
      margin-bottom: 5px;
      font-weight: 500;
      color: #374151;
    }
    .filter-group input,
    .filter-group select {
      width: 100%;
      padding: 8px 12px;
      border: 1px solid #d1d5db;
      border-radius: 6px;
      font-size: 14px;
    }
    .btn-generate {
      background: #059669;
      color: white;
      padding: 10px 20px;
      border: none;
      border-radius: 6px;
      font-weight: 500;
      cursor: pointer;
    }
    .btn-generate:hover {
      background: #047857;
    }
    .livestock-type-badge {
      display: inline-block;
      padding: 4px 8px;
      border-radius: 4px;
      font-size: 12px;
      font-weight: 500;
    }
    .type-cattle { background: #fef3c7; color: #92400e; }
    .type-swine { background: #dbeafe; color: #1e40af; }
    .type-goat { background: #d1fae5; color: #065f46; }
    .type-poultry { background: #fce7f3; color: #9f1239; }
    .status-active { background: #d1fae5; color: #065f46; }
    .status-sold { background: #fee2e2; color: #991b1b; }
    .status-pending { background: #fef3c7; color: #92400e; }
    .status-denied { background: #f3f4f6; color: #6b7280; }
  </style>
</head>
<body>
  <div class="report-container">
    <div class="report-header">
      <h1 class="report-title">Livestock Trading Activity Report</h1>
      <p class="report-period" id="reportPeriod">January 2024</p>
    </div>

    <div class="filter-section">
      <h2 class="section-title">Report Filters</h2>
      <div class="filter-group">
        <div>
          <label for="monthFrom">From Month</label>
          <input type="month" id="monthFrom" value="2024-01">
        </div>
        <div>
          <label for="monthTo">To Month</label>
          <input type="month" id="monthTo" value="2024-12">
        </div>
        <div>
          <label for="livestockType">Livestock Type</label>
          <select id="livestockType">
            <option value="">All Types</option>
            <option value="Cattle">Cattle</option>
            <option value="Swine">Swine</option>
            <option value="Goat">Goat</option>
            <option value="Poultry">Poultry</option>
          </select>
        </div>
        <div>
          <label for="status">Status</label>
          <select id="status">
            <option value="">All Status</option>
            <option value="Active">Active</option>
            <option value="Sold">Sold</option>
            <option value="Pending">Pending</option>
            <option value="Denied">Denied</option>
          </select>
        </div>
      </div>
      <button class="btn-generate" onclick="generateReport()">Generate Report</button>
    </div>

    <div class="report-section">
      <h2 class="section-title">Trading Overview</h2>
      <div class="stats-grid">
        <div class="stat-card">
          <div class="stat-value">247</div>
          <div class="stat-label">Total Listings</div>
        </div>
        <div class="stat-card">
          <div class="stat-value">89</div>
          <div class="stat-label">Completed Transactions</div>
        </div>
        <div class="stat-card">
          <div class="stat-value">₱2.4M</div>
          <div class="stat-label">Total Revenue</div>
        </div>
        <div class="stat-card">
          <div class="stat-value">156</div>
          <div class="stat-label">Active Buyers</div>
        </div>
        <div class="stat-card">
          <div class="stat-value">89</div>
          <div class="stat-label">Active Sellers</div>
        </div>
        <div class="stat-card">
          <div class="stat-value">₱27,000</div>
          <div class="stat-label">Avg Transaction Value</div>
        </div>
      </div>
    </div>

    <div class="report-section">
      <h2 class="section-title">Livestock Type Distribution</h2>
      <div class="stats-grid">
        <div class="stat-card">
          <div class="stat-value">98</div>
          <div class="stat-label">Cattle Listings</div>
        </div>
        <div class="stat-card">
          <div class="stat-value">67</div>
          <div class="stat-label">Swine Listings</div>
        </div>
        <div class="stat-card">
          <div class="stat-value">45</div>
          <div class="stat-label">Goat Listings</div>
        </div>
        <div class="stat-card">
          <div class="stat-value">37</div>
          <div class="stat-label">Poultry Listings</div>
        </div>
      </div>
    </div>

    <div class="report-section">
      <h2 class="section-title">Recent Transactions</h2>
      <div class="table-container">
        <table class="report-table">
          <thead>
            <tr>
              <th>Transaction ID</th>
              <th>Date</th>
              <th>Livestock</th>
              <th>Type</th>
              <th>Seller</th>
              <th>Buyer</th>
              <th>Price</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td>#TX001</td>
              <td>2024-01-15</td>
              <td>Brahman Cow</td>
              <td><span class="livestock-type-badge type-cattle">Cattle</span></td>
              <td>Juan Santos</td>
              <td>Maria Reyes</td>
              <td>₱45,000</td>
              <td><span class="livestock-type-badge status-sold">Sold</span></td>
            </tr>
            <tr>
              <td>#TX002</td>
              <td>2024-01-14</td>
              <td>Landrace Pig</td>
              <td><span class="livestock-type-badge type-swine">Swine</span></td>
              <td>Carlos Mendoza</td>
              <td>Ana Torres</td>
              <td>₱12,000</td>
              <td><span class="livestock-type-badge status-sold">Sold</span></td>
            </tr>
            <tr>
              <td>#TX003</td>
              <td>2024-01-13</td>
              <td>Boer Goat</td>
              <td><span class="livestock-type-badge type-goat">Goat</span></td>
              <td>Ricardo Cruz</td>
              <td>Luis Martinez</td>
              <td>₱18,500</td>
              <td><span class="livestock-type-badge status-active">Active</span></td>
            </tr>
            <tr>
              <td>#TX004</td>
              <td>2024-01-12</td>
              <td>Broiler Chicken</td>
              <td><span class="livestock-type-badge type-poultry">Poultry</span></td>
              <td>Elena Rodriguez</td>
              <td>Roberto Sanchez</td>
              <td>₱3,200</td>
              <td><span class="livestock-type-badge status-sold">Sold</span></td>
            </tr>
            <tr>
              <td>#TX005</td>
              <td>2024-01-11</td>
              <td>Hereford Bull</td>
              <td><span class="livestock-type-badge type-cattle">Cattle</span></td>
              <td>Miguel Flores</td>
              <td>Diana Castillo</td>
              <td>₱52,000</td>
              <td><span class="livestock-type-badge status-pending">Pending</span></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <div class="report-section">
      <h2 class="section-title">Top Sellers by Revenue</h2>
      <div class="table-container">
        <table class="report-table">
          <thead>
            <tr>
              <th>Seller Name</th>
              <th>Listings</th>
              <th>Sold</th>
              <th>Revenue</th>
              <th>Avg Price</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td>Juan Santos</td>
              <td>12</td>
              <td>8</td>
              <td>₱324,000</td>
              <td>₱40,500</td>
            </tr>
            <tr>
              <td>Carlos Mendoza</td>
              <td>8</td>
              <td>6</td>
              <td>₱156,000</td>
              <td>₱26,000</td>
            </tr>
            <tr>
              <td>Ricardo Cruz</td>
              <td>10</td>
              <td>5</td>
              <td>₱142,500</td>
              <td>₱28,500</td>
            </tr>
            <tr>
              <td>Elena Rodriguez</td>
              <td>15</td>
              <td>11</td>
              <td>₱98,700</td>
              <td>₱8,970</td>
            </tr>
            <tr>
              <td>Miguel Flores</td>
              <td>6</td>
              <td>4</td>
              <td>₱87,200</td>
              <td>₱21,800</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <div class="report-section">
      <h2 class="section-title">Monthly Trading Trends</h2>
      <div class="table-container">
        <table class="report-table">
          <thead>
            <tr>
              <th>Month</th>
              <th>New Listings</th>
              <th>Transactions</th>
              <th>Revenue</th>
              <th>Avg Price</th>
              <th>Active Users</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td>January 2024</td>
              <td>67</td>
              <td>24</td>
              <td>₱648,000</td>
              <td>₱27,000</td>
              <td>245</td>
            </tr>
            <tr>
              <td>December 2023</td>
              <td>58</td>
              <td>19</td>
              <td>₱523,000</td>
              <td>₱27,526</td>
              <td>238</td>
            </tr>
            <tr>
              <td>November 2023</td>
              <td>72</td>
              <td>28</td>
              <td>₱756,000</td>
              <td>₱27,000</td>
              <td>252</td>
            </tr>
            <tr>
              <td>October 2023</td>
              <td>50</td>
              <td>18</td>
              <td>₱486,000</td>
              <td>₱27,000</td>
              <td>231</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <script>
    function generateReport() {
      const monthFrom = document.getElementById('monthFrom').value;
      const monthTo = document.getElementById('monthTo').value;
      const livestockType = document.getElementById('livestockType').value;
      const status = document.getElementById('status').value;
      
      // Update report period
      if (monthFrom && monthTo) {
        const from = new Date(monthFrom);
        const to = new Date(monthTo);
        const fromMonth = from.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
        const toMonth = to.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
        
        if (monthFrom === monthTo) {
          document.getElementById('reportPeriod').textContent = fromMonth;
        } else {
          document.getElementById('reportPeriod').textContent = `${fromMonth} - ${toMonth}`;
        }
      }
      
      // Here you would typically make an API call to fetch filtered data
      // For now, we'll just show an alert
      alert(`Report generated with filters:\nFrom: ${monthFrom}\nTo: ${monthTo}\nLivestock Type: ${livestockType || 'All'}\nStatus: ${status || 'All'}`);
    }
    
    // Set current month as default
    const now = new Date();
    const currentMonth = now.toISOString().slice(0, 7);
    document.getElementById('monthTo').value = currentMonth;
    
    // Update report period display
    const monthTo = document.getElementById('monthTo').value;
    if (monthTo) {
      const to = new Date(monthTo);
      const toMonth = to.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
      document.getElementById('reportPeriod').textContent = toMonth;
    }
  </script>
</body>
</html>
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 15px;
      margin-bottom: 20px;
    }
    .stat-card {
      background: white;
      padding: 15px;
      border-radius: 6px;
      border: 1px solid #d1d5db;
    }
    .stat-label {
      font-size: 12px;
      color: #6b7280;
      margin-bottom: 5px;
    }
    .stat-value {
      font-size: 20px;
      font-weight: 600;
      color: #1f2937;
    }
    .report-table {
      width: 100%;
      border-collapse: collapse;
      background: white;
      border-radius: 6px;
      overflow: hidden;
    }
    .report-table th,
    .report-table td {
      padding: 12px;
      text-align: left;
      border-bottom: 1px solid #e5e7eb;
    }
    .report-table th {
      background: #f3f4f6;
      font-weight: 600;
      color: #374151;
    }
    .report-table tr:hover {
      background: #f9fafb;
    }
    .form-group {
      margin-bottom: 15px;
    }
    .form-group label {
      display: block;
      margin-bottom: 5px;
      font-weight: 500;
      color: #374151;
    }
    .form-group input,
    .form-group select,
    .form-group textarea {
      width: 100%;
      padding: 8px 12px;
      border: 1px solid #d1d5db;
      border-radius: 4px;
      font-family: inherit;
    }
    .form-group textarea {
      resize: vertical;
      min-height: 80px;
    }
    .form-row {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 15px;
    }
    .btn-group {
      display: flex;
      gap: 10px;
      margin-top: 20px;
    }
    .btn {
      padding: 10px 20px;
      border: none;
      border-radius: 4px;
      cursor: pointer;
      font-weight: 500;
      text-decoration: none;
      display: inline-block;
    }
    .btn-primary {
      background: #3b82f6;
      color: white;
    }
    .btn-secondary {
      background: #6b7280;
      color: white;
    }
    .btn-success {
      background: #10b981;
      color: white;
    }
    .btn:hover {
      opacity: 0.9;
    }
  </style>
</head>
<body>
  <nav class="navbar">
    <div class="nav-left">
      <div class="brand">Generate Reports</div>
    </div>
    <div class="nav-right">
      <a class="btn" href="../dashboard.php">Back to Dashboard</a>
    </div>
  </nav>
  
  <div class="wrap">
    <div class="report-container">
      <!-- Report Header -->
      <div class="report-header">
        <h1 class="report-title">Sales Monthly Report (Sample)</h1>
        <p class="report-period">November 2024</p>
      </div>

      <!-- Report Configuration -->
      <div class="report-section">
        <h2 class="section-title">Report Configuration</h2>
        <form id="reportForm">
          <div class="form-row">
            <div class="form-group">
              <label for="reportMonth">Report Month</label>
              <input type="month" id="reportMonth" name="reportMonth" value="2024-11">
            </div>
            <div class="form-group">
              <label for="reportType">Report Type</label>
              <select id="reportType" name="reportType">
                <option value="sales">Sales Report</option>
                <option value="livestock">Livestock Report</option>
                <option value="revenue">Revenue Report</option>
              </select>
            </div>
          </div>
          <div class="form-group">
            <label for="reportTitle">Report Title</label>
            <input type="text" id="reportTitle" name="reportTitle" value="Monthly Sales Performance Report - November 2024">
          </div>
          <div class="form-group">
            <label for="reportSummary">Executive Summary</label>
            <textarea id="reportSummary" name="reportSummary">November 2024 showed exceptional growth with 156 transactions completed, representing a 23% increase from October. Total revenue reached ₱2,456,780 with cattle and goats being the top-performing categories. The average transaction value increased by 8% to ₱15,754, indicating healthy market conditions and customer confidence.</textarea>
          </div>
          <div class="btn-group">
            <button type="button" class="btn btn-primary" onclick="generateReport()">Generate Report</button>
            <button type="button" class="btn btn-success" onclick="exportPDF()">Export PDF</button>
            <button type="button" class="btn btn-secondary" onclick="exportExcel()">Export Excel</button>
          </div>
        </form>
      </div>

      <!-- Key Metrics -->
      <div class="report-section">
        <h2 class="section-title">Key Performance Indicators</h2>
        <div class="stats-grid">
          <div class="stat-card">
            <div class="stat-label">Total Transactions</div>
            <div class="stat-value">156</div>
          </div>
          <div class="stat-card">
            <div class="stat-label">Total Revenue</div>
            <div class="stat-value">₱2,456,780</div>
          </div>
          <div class="stat-card">
            <div class="stat-label">Average Transaction</div>
            <div class="stat-value">₱15,754</div>
          </div>
          <div class="stat-card">
            <div class="stat-label">Success Rate</div>
            <div class="stat-value">94.2%</div>
          </div>
          <div class="stat-card">
            <div class="stat-label">Active Sellers</div>
            <div class="stat-value">89</div>
          </div>
          <div class="stat-card">
            <div class="stat-label">Active Buyers</div>
            <div class="stat-value">234</div>
          </div>
        </div>
      </div>

      <!-- Sales by Category -->
      <div class="report-section">
        <h2 class="section-title">Sales by Livestock Category</h2>
        <table class="report-table">
          <thead>
            <tr>
              <th>Livestock Type</th>
              <th>Transactions</th>
              <th>Revenue</th>
              <th>Average Price</th>
              <th>% of Total</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td>Cattle</td>
              <td>67</td>
              <td>₱1,234,500</td>
              <td>₱18,425</td>
              <td>50.2%</td>
            </tr>
            <tr>
              <td>Goats</td>
              <td>45</td>
              <td>₱567,890</td>
              <td>₱12,620</td>
              <td>23.1%</td>
            </tr>
            <tr>
              <td>Swine</td>
              <td>28</td>
              <td>₱423,450</td>
              <td>₱15,123</td>
              <td>17.2%</td>
            </tr>
            <tr>
              <td>Chicken</td>
              <td>16</td>
              <td>₱231, -940</td>
              <td>₱14,496</td>
              <td>9.5%</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Top Sellers -->
      <div class="report-section">
        <h2 class="section-title">Top Performing Sellers</h2>
        <table class="report-table">
          <thead>
            <tr>
              <th>Seller Name</th>
              <th>Transactions</th>
              <th>Revenue</th>
              <th>Location</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td>Juan Dela Cruz</td>
              <td>23</td>
              <td>₱456,780</td>
              <td>Laguna</td>
            </tr>
            <tr>
              <td>Maria Santos</td>
              <td>18</td>
              <td>₱345,670</td>
              <td>Batangas</td>
            </tr>
            <tr>
              <td>Jose Reyes</td>
              <td>15</td>
              <td>₱289,450</td>
              <td>Cavite</td>
            </tr>
            <tr>
              <td>Anna Lopez</td>
              <td>12</td>
              <td>₱198,230</td>
              <td>Quezon</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Regional Analysis -->
      <div class="report-section">
        <h2 class="section-title">Regional Sales Analysis</h2>
        <table class="report-table">
          <thead>
            <tr>
              <th>Region</th>
              <th>Transactions</th>
              <th>Revenue</th>
              <th>Average Value</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td>Calabarzon</td>
              <td>45</td>
              <td>₱789,450</td>
              <td>₱17,543</td>
            </tr>
            <tr>
              <td>Central Luzon</td>
              <td>38</td>
              <td>₱654,320</td>
              <td>₱17,219</td>
            </tr>
            <tr>
              <td>NCR</td>
              <td>32</td>
              <td>₱567,890</td>
              <td>₱17,746</td>
            </tr>
            <tr>
              <td>MIMAROPA</td>
              <td>25</td>
              <td>₱345,120</td>
              <td>₱13,805</td>
            </tr>
            <tr>
              <td>Bicol Region</td>
              <td>16</td>
              <td>₱100,000</td>
              <td>₱6,250</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Monthly Trends -->
      <div class="report-section">
        <h2 class="section-title">Monthly Comparison</h2>
        <table class="report-table">
          <thead>
            <tr>
              <th>Month</th>
              <th>Transactions</th>
              <th>Revenue</th>
              <th>Growth %</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td>November 2024</td>
              <td>156</td>
              <td>₱2,456,780</td>
              <td>+23.4%</td>
            </tr>
            <tr>
              <td>October 2024</td>
              <td>127</td>
              <td>₱1,992,450</td>
              <td>+15.2%</td>
            </tr>
            <tr>
              <td>September 2024</td>
              <td>110</td>
              <td>₱1,728,900</td>
              <td>+8.7%</td>
            </tr>
            <tr>
              <td>August 2024</td>
              <td>101</td>
              <td>₱1,589,340</td>
              <td>-2.3%</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <script>
    function generateReport() {
      alert('Report generation feature will be implemented with backend data integration.');
    }
    
    function exportPDF() {
      alert('PDF export will be implemented using a library like jsPDF or DOMPDF.');
    }
    
    function exportExcel() {
      alert('Excel export will be implemented using a library like SheetJS or PHPExcel.');
    }
  </script>
</body>
</html>
