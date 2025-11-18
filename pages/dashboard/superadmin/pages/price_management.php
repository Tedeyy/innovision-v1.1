<?php
session_start();
require_once __DIR__ . '/../../../authentication/lib/supabase_client.php';
$firstname = isset($_SESSION['firstname']) && $_SESSION['firstname'] !== '' ? $_SESSION['firstname'] : 'User';
// Fetch pending rows and lookup maps
[$pend,$ps,$pe] = sb_rest('GET','pendingmarketpricing',[ 'select'=>'pricing_id,livestocktype_id,breed_id,marketprice,admin_id,created,status' ]);
[$types,$ts,$te] = sb_rest('GET','livestock_type',[ 'select'=>'type_id,name' ]);
[$breeds,$bs,$be] = sb_rest('GET','livestock_breed',[ 'select'=>'breed_id,name' ]);
$typeMap = [];$breedMap=[];
if (is_array($types)) { foreach ($types as $t){ $typeMap[(int)$t['type_id']] = $t['name'] ?? ('Type #'.(int)$t['type_id']); } }
if (is_array($breeds)) { foreach ($breeds as $b){ $breedMap[(int)$b['breed_id']] = $b['name'] ?? ('Breed #'.(int)$b['breed_id']); } }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Price Management</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../style/dashboard.css">
</head>
<body>
  <nav class="navbar">
    <div class="nav-left">
      <div class="brand">Superadmin</div>
    </div>
    <div class="nav-right">
      <div class="greeting">hello <?php echo htmlspecialchars($firstname, ENT_QUOTES, 'UTF-8'); ?></div>
      <a class="btn" href="../../logout.php">Logout</a>
      <a class="profile" href="profile.php" aria-label="Profile"><span class="avatar">👤</span></a>
    </div>
  </nav>
  <div class="wrap">
    <div class="card">
      <h1 style="margin-top:0">Price Management</h1>
      <div id="msg" style="margin:8px 0;color:#4a5568;font-size:14px"></div>
      <div style="overflow:auto;">
        <table style="width:100%;border-collapse:collapse;">
          <thead>
            <tr>
              <th style="text-align:left;border-bottom:1px solid #e5e7eb;padding:8px 6px;">ID</th>
              <th style="text-align:left;border-bottom:1px solid #e5e7eb;padding:8px 6px;">Type</th>
              <th style="text-align:left;border-bottom:1px solid #e5e7eb;padding:8px 6px;">Breed</th>
              <th style="text-align:left;border-bottom:1px solid #e5e7eb;padding:8px 6px;">Market Price</th>
              <th style="text-align:left;border-bottom:1px solid #e5e7eb;padding:8px 6px;">Admin</th>
              <th style="text-align:left;border-bottom:1px solid #e5e7eb;padding:8px 6px;">Created</th>
              <th style="text-align:left;border-bottom:1px solid #e5e7eb;padding:8px 6px;">Action</th>
            </tr>
          </thead>
          <tbody id="pm-body">
            <?php if (is_array($pend) && count($pend)>0): foreach ($pend as $row): ?>
              <tr data-id="<?php echo (int)$row['pricing_id']; ?>">
                <td style="border-bottom:1px solid #f3f4f6;padding:8px 6px;">#<?php echo (int)$row['pricing_id']; ?></td>
                <td style="border-bottom:1px solid #f3f4f6;padding:8px 6px;"><?php echo htmlspecialchars($typeMap[(int)$row['livestocktype_id']] ?? ('Type #'.(int)$row['livestocktype_id'])); ?></td>
                <td style="border-bottom:1px solid #f3f4f6;padding:8px 6px;"><?php echo htmlspecialchars($breedMap[(int)$row['breed_id']] ?? ('Breed #'.(int)$row['breed_id'])); ?></td>
                <td style="border-bottom:1px solid #f3f4f6;padding:8px 6px;">₱<?php echo number_format((float)$row['marketprice'],2); ?></td>
                <td style="border-bottom:1px solid #f3f4f6;padding:8px 6px;">Admin #<?php echo (int)$row['admin_id']; ?></td>
                <td style="border-bottom:1px solid #f3f4f6;padding:8px 6px;"><?php echo htmlspecialchars(substr((string)($row['created']??''),0,19)); ?></td>
                <td style="border-bottom:1px solid #f3f4f6;padding:8px 6px;display:flex;gap:6px;">
                  <button class="btn" data-action="approve" data-id="<?php echo (int)$row['pricing_id']; ?>" style="background:#2f855a;">Approve</button>
                  <button class="btn" data-action="deny" data-id="<?php echo (int)$row['pricing_id']; ?>" style="background:#e53e3e;">Deny</button>
                </td>
              </tr>
            <?php endforeach; else: ?>
              <tr><td colspan="7" style="padding:10px 6px;color:#6b7280;">No pending items</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <script>
    (function(){
      var body = document.getElementById('pm-body');
      var msg = document.getElementById('msg');
      function setMsg(t, ok){ if(!msg) return; msg.style.color = ok? '#166534':'#b91c1c'; msg.textContent = t; }
      function onClick(e){
        var el = e.target;
        if (el && el.dataset && el.dataset.action){
          var id = Number(el.dataset.id||0);
          var action = el.dataset.action;
          if (!id) return;
          fetch('price_actions.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ action: action, pricing_id: id }) })
            .then(r=>r.json())
            .then(d=>{
              if (!d.ok){ setMsg(d.error||'Action failed', false); return; }
              // remove row
              var tr = body.querySelector('tr[data-id="'+id+'"]');
              if (tr) tr.remove();
              setMsg(action==='approve' ? 'Approved.' : 'Denied.', true);
            })
            .catch(()=> setMsg('Network error', false));
        }
      }
      if (body) body.addEventListener('click', onClick);
    })();
  </script>
</body>
</html>
