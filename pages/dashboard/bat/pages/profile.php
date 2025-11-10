<?php
session_start();

$firstname = isset($_SESSION['firstname']) && $_SESSION['firstname'] !== '' ? $_SESSION['firstname'] : 'User';
$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
$userrole = 'BAT';

$dsn = getenv('DB_DSN') ?: '';
$dbUser = getenv('DB_USER') ?: '';
$dbPass = getenv('DB_PASS') ?: '';

$pdo = null;
if ($dsn) {
    try { $pdo = new PDO($dsn, $dbUser, $dbPass, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]); }
    catch (Throwable $e) { $pdo = null; $error = 'Database connection failed: '.$e->getMessage(); }
}

$columns = [
  'user_fname','user_mname','user_lname','bdate','contact','address','email','assigned_barangay'
];
$editable = ['contact','address','assigned_barangay'];

$data = array_fill_keys($columns, '');
$notice=''; $success=''; $error = isset($error)?$error:'';

if ($pdo && $userId) {
  if ($_SERVER['REQUEST_METHOD']==='POST') {
    $updates=[]; $params=[':user_id'=>$userId];
    $logValues=[
      'contact'=>null,'address'=>null,'barangay'=>null,'municipality'=>null,'province'=>null,
      'office'=>null,'role'=>null,'assigned_barangay'=>null,
    ];
    foreach ($editable as $f) {
      if (isset($_POST[$f])) { $val=trim((string)$_POST[$f]); $updates[]="$f = :$f"; $params[":$f"]=$val; if (array_key_exists($f,$logValues)) $logValues[$f]=$val; }
    }
    if ($updates) {
      try {
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE bats SET '.implode(',', $updates).' WHERE user_id = :user_id')->execute($params);
        $pdo->prepare('INSERT INTO profileedit_log (user_id,userrole,contact,address,barangay,municipality,province,office,role,assigned_barangay) VALUES (:user_id,:userrole,:contact,:address,:barangay,:municipality,:province,:office,:role,:assigned_barangay)')
            ->execute([
              ':user_id'=>$userId, ':userrole'=>$userrole,
              ':contact'=>$logValues['contact'], ':address'=>$logValues['address'], ':barangay'=>$logValues['barangay'], ':municipality'=>$logValues['municipality'], ':province'=>$logValues['province'], ':office'=>$logValues['office'], ':role'=>$logValues['role'], ':assigned_barangay'=>$logValues['assigned_barangay']
            ]);
        $pdo->commit(); $success='Profile updated successfully.';
      } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); $error='Update failed: '.$e->getMessage(); }
    } else { $notice='No changes submitted.'; }
  }
  try {
    $stmt=$pdo->prepare('SELECT '.implode(',', $columns).' FROM bats WHERE user_id = :user_id');
    $stmt->execute([':user_id'=>$userId]); $row=$stmt->fetch();
    if ($row) { foreach ($columns as $c) { $data[$c]=$row[$c]??''; } } else { $notice='No profile found for your account.'; }
  } catch (Throwable $e) { $error='Fetch failed: '.$e->getMessage(); }
} else { if (!$userId) { $notice='You are not logged in.'; } if (!$pdo && !$error) { $notice='Database not configured. Set DB_DSN, DB_USER, DB_PASS.'; } }
?>
<!DOCTYPE html>
<html lang="en"><head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>BAT Profile</title>
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
        <div class="field"><label>Assigned Barangay</label><input name="assigned_barangay" type="text" value="<?php echo htmlspecialchars($data['assigned_barangay']); ?>" /></div>
        <div class="actions"><button class="primary" type="submit">Save changes</button></div>
      </form>
    </div>
  </div>
</body></html>
