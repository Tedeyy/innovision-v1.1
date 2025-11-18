<?php
session_start();

require_once __DIR__ . '/../../../authentication/lib/supabase_client.php';

$firstname = isset($_SESSION['firstname']) && $_SESSION['firstname'] !== '' ? $_SESSION['firstname'] : 'User';
$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
$userrole = 'Superadmin';

$columns = [
  'user_fname','user_mname','user_lname','bdate','contact','address','email','office','role'
];
$editable = ['contact','address','office','role'];

$data = array_fill_keys($columns, '');
$notice=''; $success=''; $error='';

if ($userId) {
  if ($_SERVER['REQUEST_METHOD']==='POST') {
    $patch=[]; $logValues=[
      'contact'=>null,'address'=>null,'barangay'=>null,'municipality'=>null,'province'=>null,
      'office'=>null,'role'=>null,'assigned_barangay'=>null,
    ];
    foreach ($editable as $f) {
      if (isset($_POST[$f])) { $val=trim((string)$_POST[$f]); $patch[$f]=$val; if (array_key_exists($f,$logValues)) $logValues[$f]=$val; }
    }
    if (!empty($patch)) {
      list($updData,$updStatus,$updErr) = sb_rest('PATCH','superadmins',['user_id'=>'eq.'.$userId], $patch, ['Prefer: return=minimal']);
      if ($updErr || ($updStatus !== 204 && $updStatus !== 200)) { $error='Update failed'; }
      else {
        $logRow=[
          'user_id'=>$userId,'userrole'=>$userrole,
          'contact'=>$logValues['contact'],'address'=>$logValues['address'],'barangay'=>$logValues['barangay'],'municipality'=>$logValues['municipality'],'province'=>$logValues['province'],
          'office'=>$logValues['office'],'role'=>$logValues['role'],'assigned_barangay'=>$logValues['assigned_barangay']
        ];
        sb_rest('POST','profileedit_log',[],[$logRow],['Prefer: return=minimal']);
        $success='Profile updated successfully.';
      }
    } else { $notice='No changes submitted.'; }
  }
  list($rows,$status,$err) = sb_rest('GET','superadmins',['select'=>implode(',',$columns),'user_id'=>'eq.'.$userId,'limit'=>1]);
  if ($err || $status>=400) { $error='Fetch failed'; }
  else if (is_array($rows) && isset($rows[0])) { foreach ($columns as $c) { $data[$c]=$rows[0][$c]??''; } }
  else { $notice='No profile found for your account.'; }
} else { $notice='You are not logged in.'; }
?>
<!DOCTYPE html>
<html lang="en"><head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Superadmin Profile</title>
  <link rel="stylesheet" href="style/profile.css" />
</head><body>
  <div class="container">
    <div class="card">
      <div class="notice">hello <?php echo htmlspecialchars($firstname, ENT_QUOTES, 'UTF-8'); ?></div>
      <?php if (!empty($success)): ?><div class="success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
      <?php if (!empty($error)): ?><div class="error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
      <?php if (!empty($notice)): ?><div class="notice"><?php echo htmlspecialchars($notice); ?></div><?php endif; ?>
      <form method="post">
        <div class="field"><label>First name</label><input type="text" value="<?php echo htmlspecialchars($data['user_fname']); ?>" readonly /></div>
        <div class="field"><label>Middle name</label><input type="text" value="<?php echo htmlspecialchars($data['user_mname']); ?>" readonly /></div>
        <div class="field"><label>Last name</label><input type="text" value="<?php echo htmlspecialchars($data['user_lname']); ?>" readonly /></div>
        <div class="field"><label>Birth date</label><input type="text" value="<?php echo htmlspecialchars($data['bdate']); ?>" readonly /></div>
        <div class="field"><label>Contact</label><input name="contact" type="text" value="<?php echo htmlspecialchars($data['contact']); ?>" /></div>
        <div class="field"><label>Address</label><input name="address" type="text" value="<?php echo htmlspecialchars($data['address']); ?>" /></div>
        <div class="field"><label>Email</label><input type="email" value="<?php echo htmlspecialchars($data['email']); ?>" readonly /></div>
        <div class="field"><label>Office</label><input name="office" type="text" value="<?php echo htmlspecialchars($data['office']); ?>" /></div>
        <div class="field"><label>Role</label><input name="role" type="text" value="<?php echo htmlspecialchars($data['role']); ?>" /></div>
        <div class="actions">
          <a class="secondary" href="../dashboard.php">Back</a>
          <button id="btn-reset" class="ghost" type="button">Reset password</button>
          <button class="primary" type="submit">Save changes</button>
        </div>
      </form>
    </div>
  </div>
<script>
  (function(){
    var btn = document.getElementById('btn-reset');
    if (!btn) return;
    btn.addEventListener('click', async function(){
      try{
        var emailInput = document.querySelector('input[type="email"]');
        var email = emailInput ? emailInput.value : '';
        if (!email){ alert('No email found for this account.'); return; }
        const res = await fetch('../../../authentication/reset_password_request.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ email }) });
        const data = await res.json();
        if (!data.ok) { alert(data.error || 'Failed to send reset email'); return; }
        alert('Password reset email sent. Please check your inbox.');
      }catch(e){ alert('Network error'); }
    });
  })();
 </script>
</body></html>
