<?php
session_start();
require_once __DIR__ . '/../../../authentication/lib/supabase_client.php';

[$rows,$st,$err] = sb_rest('GET','livestocklisting_logs',['select'=>'*']);
$logs = ($st>=200 && $st<300 && is_array($rows)) ? $rows : [];

// Build a seller map: seller_id => seller row
$sellerMap = [];
$sellerIds = [];
foreach ($logs as $r){ if (isset($r['seller_id']) && $r['seller_id']!=='') { $sellerIds[$r['seller_id']] = true; } }
foreach (array_keys($sellerIds) as $sid){
  [$srow,$sst,$serr] = sb_rest('GET','seller',[ 'select'=>'*','id'=>'eq.'.$sid ]);
  if ($sst>=200 && $sst<300 && is_array($srow) && count($srow)>0){ $sellerMap[$sid] = $srow[0]; }
}

function seller_name($row){
  if (!$row || !is_array($row)) return '';
  $parts = [];
  if (!empty($row['firstname'])) $parts[] = $row['firstname'];
  if (!empty($row['lastname'])) $parts[] = $row['lastname'];
  if (empty($parts) && !empty($row['name'])) $parts[] = $row['name'];
  return trim(implode(' ', $parts));
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Livestock Listing Logs</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <style>
    body{font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Arial;background:#fff;margin:0;padding:10px;color:#2d3748}
    .table-wrap{overflow:auto}
    table{width:100%;border-collapse:collapse;font-size:12px;line-height:1.25}
    th,td{padding:6px 6px;border-bottom:1px solid #e5e7eb;text-align:left;vertical-align:top}
    th{background:#f9fafb}
    th,td{word-break:break-word;hyphens:auto}
    .btn{display:inline-block;padding:6px 8px;border-radius:8px;background:#2563eb;color:#fff;border:0;cursor:pointer;font-size:12px}
    /* Modal */
    .modal{position:fixed;inset:0;background:rgba(0,0,0,.5);display:none;align-items:center;justify-content:center;z-index:1000}
    .panel{width:min(800px,94vw);background:#fff;border:1px solid #e5e7eb;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,.1);padding:12px}
    .panel h3{margin:0 0 8px}
    .panel .grid{display:grid;grid-template-columns:1fr 2fr;gap:8px}
    .img-wrap img{max-width:100%;height:auto;border:1px solid #e5e7eb;border-radius:10px}
    .actions{display:flex;justify-content:flex-end;margin-top:10px}
    @media (max-width:640px){
      body{padding:4px}
      table{font-size:10px;table-layout:fixed}
      th,td{padding:3px 4px}
      th,td{line-height:1.15}
      .btn{padding:4px 6px;border-radius:6px;font-size:11px}
      .panel{width:min(95vw,560px);padding:10px}
      .panel .grid{grid-template-columns:1fr;gap:6px}
    }
  </style>
</head>
<body>
  <div class="table-wrap">
    <table aria-label="Livestock Listing Logs">
      <thead>
        <tr>
          <th>Seller</th>
          <th>Livestock Type</th>
          <th>Breed</th>
          <th>Age</th>
          <th>Weight</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($logs as $row): 
          $sid = isset($row['seller_id']) ? $row['seller_id'] : '';
          $sname = $sid && isset($sellerMap[$sid]) ? seller_name($sellerMap[$sid]) : ($sid ?: '');
          $type  = $row['livestock_type'] ?? ($row['type'] ?? '');
          $breed = $row['breed'] ?? '';
          $age   = $row['age'] ?? '';
          $weight= $row['weight'] ?? ($row['weight_kg'] ?? '');
          $json  = htmlspecialchars(json_encode($row, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');
        ?>
          <tr>
            <td><?php echo htmlspecialchars((string)$sname, ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars((string)$type, ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars((string)$breed, ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars((string)$age, ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars((string)$weight, ENT_QUOTES, 'UTF-8'); ?></td>
            <td><button class="btn" onclick="showDetails(this)" data-row="<?php echo $json; ?>">Show</button></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="modal" id="detailsModal" role="dialog" aria-modal="true" aria-label="Listing Details">
    <div class="panel">
      <h3>Listing Details</h3>
      <div class="grid">
        <div class="img-wrap" id="detailImage"></div>
        <div id="detailFields"></div>
      </div>
      <div class="actions"><button class="btn" onclick="closeModal()">Close</button></div>
    </div>
  </div>

  <script>
    function tryImageFromRow(row){
      const keys = ['image','image_url','photo','photo_url','thumbnail','thumb','images','photos'];
      for (const k of keys){
        if (!row || !(k in row) || row[k]==null) continue;
        let v = row[k];
        if (typeof v=== 'string'){
          try{ const arr = JSON.parse(v); if (Array.isArray(arr) && arr.length>0) return arr[0]; }catch(e){}
          return v;
        }
        if (Array.isArray(v) && v.length>0) return v[0];
      }
      return '';
    }
    function showDetails(btn){
      const modal = document.getElementById('detailsModal');
      const imgBox = document.getElementById('detailImage');
      const fields = document.getElementById('detailFields');
      if (!btn || !btn.dataset.row) return;
      let row = {};
      try{ row = JSON.parse(btn.dataset.row); }catch(e){ row = {}; }
      imgBox.innerHTML = '';
      const imgUrl = tryImageFromRow(row);
      if (imgUrl){
        const img = document.createElement('img');
        img.src = imgUrl;
        img.alt = 'Listing image';
        img.loading = 'lazy';
        imgBox.appendChild(img);
      }
      // Render all key/value pairs
      const frag = document.createDocumentFragment();
      const table = document.createElement('table');
      table.style.width = '100%';
      table.style.borderCollapse = 'collapse';
      table.style.fontSize = '12px';
      for (const k in row){
        const tr = document.createElement('tr');
        const th = document.createElement('th');
        th.textContent = k;
        th.style.textAlign = 'left';
        th.style.padding = '4px 6px';
        th.style.borderBottom = '1px solid #e5e7eb';
        const td = document.createElement('td');
        let v = row[k];
        if (typeof v === 'object'){ try{ v = JSON.stringify(v); }catch(e){ v = String(v); } }
        td.textContent = v == null ? '' : String(v);
        td.style.padding = '4px 6px';
        td.style.borderBottom = '1px solid #f3f4f6';
        tr.appendChild(th); tr.appendChild(td); table.appendChild(tr);
      }
      frag.appendChild(table);
      fields.innerHTML = '';
      fields.appendChild(frag);
      modal.style.display = 'flex';
    }
    function closeModal(){
      const modal = document.getElementById('detailsModal');
      modal.style.display = 'none';
    }
    document.addEventListener('click', function(e){
      const m = document.getElementById('detailsModal');
      if (!m) return;
      if (e.target === m) { m.style.display = 'none'; }
    });
  </script>
</body>
</html>
