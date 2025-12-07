yes<?php
session_start();

require_once __DIR__ . '/../../../authentication/lib/supabase_client.php';
function safe($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function getUserRatings($userId, $userType = 'buyer') {
  $column = $userType . '_id';
  [$ratings, $status] = sb_rest('GET', 'userrating', [
    'select' => 'rating,description,created_at',
    $column => 'eq.' . $userId
  ]);
  
  if ($status >= 200 && $status < 300 && is_array($ratings)) {
    return $ratings;
  }
  return [];
}

function calculateAverageRating($ratings) {
  if (empty($ratings)) return ['average' => 0, 'count' => 0];
  
  $total = 0;
  foreach ($ratings as $rating) {
    $total += (float)($rating['rating'] ?? 0);
  }
  
  return [
    'average' => round($total / count($ratings), 1),
    'count' => count($ratings)
  ];
}

$firstname = isset($_SESSION['firstname']) && $_SESSION['firstname'] !== '' ? $_SESSION['firstname'] : 'User';
$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
$userrole = 'Buyer';

$columns = [
    'user_id','user_fname','user_mname','user_lname','bdate','contact','address','barangay','municipality','province','email'
];
$editable = ['contact','address','barangay','municipality','province'];

$data = array_fill_keys($columns, '');
$notice = '';$success='';$error = '';

if ($userId) {
    // Determine which table holds this buyer
    $tableOrder = ['buyer','reviewbuyer'];
    $tableFound = null; $currentRow = null;
    foreach ($tableOrder as $t) {
        list($rowsTry,$stTry,$errTry) = sb_rest('GET',$t,['select'=>implode(',',$columns),'user_id'=>'eq.'.$userId,'limit'=>1]);
        if (!$errTry && $stTry < 400 && is_array($rowsTry) && isset($rowsTry[0])) { $tableFound=$t; $currentRow=$rowsTry[0]; break; }
    }
    if (!$tableFound) { $notice='No profile found for your account.'; }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $patch = [];
        $logValues = [
            'contact'=>null,'address'=>null,'barangay'=>null,'municipality'=>null,'province'=>null,
            'office'=>null,'role'=>null,'assigned_barangay'=>null,
        ];
        foreach ($editable as $f) {
            if (isset($_POST[$f])) { $val = trim((string)$_POST[$f]); $patch[$f] = $val; $logValues[$f]=$val; }
        }
        if (!empty($patch) && $tableFound) {
            list($updData,$updStatus,$updErr) = sb_rest('PATCH',$tableFound,['user_id'=>'eq.'.$userId], $patch, ['Prefer: return=minimal']);
            if ($updErr || ($updStatus !== 204 && $updStatus !== 200)) { $error='Update failed'; }
            else {
                $logRow = [
                    'user_id'=>$userId,'userrole'=>$userrole,
                    'contact'=>$logValues['contact'],'address'=>$logValues['address'],'barangay'=>$logValues['barangay'],'municipality'=>$logValues['municipality'],'province'=>$logValues['province'],
                    'office'=>$logValues['office'],'role'=>$logValues['role'],'assigned_barangay'=>$logValues['assigned_barangay']
                ];
                sb_rest('POST','profileedit_log',[],[$logRow],['Prefer: return=minimal']);
                $success='Profile updated successfully.';
            }
        } else { $notice='No changes submitted.'; }
    }
    // Populate from found row
    if ($currentRow) { foreach ($columns as $c) { $data[$c]=$currentRow[$c]??''; } }
    
    // Get user ratings
    $ratings = getUserRatings($userId, 'buyer');
    $ratingData = calculateAverageRating($ratings);
} else { $notice='You are not logged in.'; }
?>
<!DOCTYPE html>
<html lang="en"><head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Buyer Profile</title>
  <!-- External CSS -->
  <link rel="stylesheet" href="style/profile.css" />
  <link rel="stylesheet" href="../../style/profile.css" />
  <!-- Add Font Awesome for icons -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <!-- Add Bootstrap CSS for modal -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head><body>
  <!-- Add jQuery and Bootstrap JS -->
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
  
  <div class="container" data-user-id="<?php echo $userId; ?>">
    <div class="card">
      <div class="notice">hello <?php echo htmlspecialchars($firstname, ENT_QUOTES, 'UTF-8'); ?></div>
      <?php if (!empty($success)): ?><div class="success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
      <?php if (!empty($error)): ?><div class="error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
      <?php if (!empty($notice)): ?><div class="notice"><?php echo htmlspecialchars($notice); ?></div><?php endif; ?>
      <form method="post" id="profileForm">
        <div class="field"><label>User ID</label><input type="text" value="<?php echo htmlspecialchars($data['user_id']); ?>" readonly /></div>
        <div class="field"><label>First name</label><input type="text" value="<?php echo htmlspecialchars($data['user_fname']); ?>" readonly /></div>
        <div class="field"><label>Middle name</label><input type="text" value="<?php echo htmlspecialchars($data['user_mname']); ?>" readonly /></div>
        <div class="field"><label>Last name</label><input type="text" value="<?php echo htmlspecialchars($data['user_lname']); ?>" readonly /></div>
        <div class="field"><label>Birth date</label><input type="text" value="<?php echo htmlspecialchars($data['bdate']); ?>" readonly /></div>
        <div class="field">
          <label>Contact</label>
          <div class="contact-verification">
            <input type="text" name="contact" id="contact" value="<?php echo htmlspecialchars($data['contact']); ?>" maxlength="11" oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 11)" <?php echo !empty($data['contact_verified_at']) ? 'readonly' : ''; ?> />
            <?php if (empty($data['contact_verified_at'])): ?>
              <button type="button" id="verifyBtn" class="btn btn-primary btn-sm">Verify</button>
            <?php else: ?>
              <span class="verification-status verified"><i class="fas fa-check-circle"></i> Verified</span>
            <?php endif; ?>
          </div>
          <div id="contactMessage" class="verification-message"></div>
        </div>
        <div class="field"><label>Address</label><input name="address" type="text" value="<?php echo htmlspecialchars($data['address']); ?>" /></div>
        <div class="field"><label>Barangay</label><input name="barangay" type="text" value="<?php echo htmlspecialchars($data['barangay']); ?>" /></div>
        <div class="field"><label>Municipality</label><input name="municipality" type="text" value="<?php echo htmlspecialchars($data['municipality']); ?>" /></div>
        <div class="field"><label>Province</label><input name="province" type="text" value="<?php echo htmlspecialchars($data['province']); ?>" /></div>
        <div class="field"><label>Email</label><input type="email" value="<?php echo htmlspecialchars($data['email']); ?>" readonly /></div>
        <div class="field">
          <label>User Rating</label>
          <div style="display: flex; align-items: center; gap: 10px;">
            <span style="font-size: 24px; font-weight: bold; color: #f59e0b;"><?php echo $ratingData['average']; ?> </span>
            <span style="color: #6b7280;">(<?php echo $ratingData['count']; ?> rating<?php echo $ratingData['count'] !== 1 ? 's' : ''; ?>)</span>
          </div>
        </div>
        <div class="actions">
          <a class="secondary" href="../dashboard.php">Back</a>
          <button id="btn-reset" class="ghost" type="button">Reset password</button>
          <button class="primary" type="submit">Save changes</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Verification Modal -->
  <div class="modal fade" id="verificationModal" tabindex="-1" aria-labelledby="verificationModalLabel" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="verificationModalLabel">Verify Contact Number</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <p>We've sent a 6-digit verification code to <strong id="contactNumberDisplay"></strong></p>
          <input type="text" id="otp" class="form-control otp-input" maxlength="6" placeholder="000000" oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 6)">
          <div id="countdown" class="countdown">Code expires in: <span id="time">05:00</span></div>
          <div id="otpMessage" class="verification-message"></div>
          <div class="resend-otp">
            <p>Didn't receive the code? <a href="#" id="resendOtp">Resend code</a></p>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
          <button type="button" class="btn btn-primary" id="submitOtpBtn">Verify</button>
        </div>
      </div>
    </div>
  </div>

  <!-- External JavaScript -->
  <script src="../../script/contact-verification.js"></script>
</body>
</html>
  (function(){
    var btn = document.getElementById('btn-reset');
    if (!btn) return;
    btn.addEventListener('click', function(){
      var base = '../../../authentication/reset_password.php?mode=direct&redirect=' + encodeURIComponent(window.location.href.split('#')[0]);
      window.location.href = base;
    });
  })();
 </script>
</body></html>

<script>
  (function(){
    var p = new URLSearchParams(window.location.search);
    if (p.get('msg') === 'password_changed') {
      alert('Password has been changed successfully.');
      p.delete('msg');
      var url = window.location.pathname + (p.toString()?('?'+p.toString()):'');
      window.history.replaceState({}, '', url);
    }
  })();
</script>
