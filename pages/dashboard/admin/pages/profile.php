<?php
session_start();

require_once __DIR__ . '/../../../authentication/lib/supabase_client.php';

$firstname = isset($_SESSION['firstname']) && $_SESSION['firstname'] !== '' ? $_SESSION['firstname'] : 'User';
$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
$userrole = 'Admin';

// Columns for Admin
$columns = [
    'user_fname', 'user_mname', 'user_lname', 'bdate', 'contact', 'address', 'email', 'office', 'role'
];
$editable = ['contact', 'address', 'office', 'role'];

$data = array_fill_keys($columns, '');
$notice = '';
$success = '';
$error = '';

if ($userId) {
    // Determine which table holds this admin
    $tableOrder = ['admin','reviewadmin'];
    $tableFound = null;
    $currentRow = null;
    foreach ($tableOrder as $t) {
        list($rowsTry, $stTry, $errTry) = sb_rest('GET', $t, [
            'select' => implode(',', $columns),
            'user_id' => 'eq.'.$userId,
            'limit' => 1
        ]);
        if (!$errTry && $stTry < 400 && is_array($rowsTry) && isset($rowsTry[0])) { $tableFound = $t; $currentRow = $rowsTry[0]; break; }
    }
    if (!$tableFound) { $notice = 'No profile found for your account.'; }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $patch = [];
        $logValues = [
            'contact' => null,
            'address' => null,
            'barangay' => null,
            'municipality' => null,
            'province' => null,
            'office' => null,
            'role' => null,
            'assigned_barangay' => null,
        ];
        foreach ($editable as $field) {
            if (isset($_POST[$field])) {
                $val = trim((string)$_POST[$field]);
                $patch[$field] = $val;
                if (array_key_exists($field, $logValues)) { $logValues[$field] = $val; }
            }
        }
        if (!empty($patch) && $tableFound) {
            list($updData, $updStatus, $updErr) = sb_rest(
                'PATCH',
                $tableFound,
                ['user_id' => 'eq.'.$userId],
                $patch,
                ['Prefer: return=minimal']
            );
            if ($updErr || ($updStatus !== 204 && $updStatus !== 200)) {
                $error = 'Update failed';
            } else {
                // Log edit
                $logRow = [
                    'user_id' => $userId,
                    'userrole' => $userrole,
                    'contact' => $logValues['contact'],
                    'address' => $logValues['address'],
                    'barangay' => $logValues['barangay'],
                    'municipality' => $logValues['municipality'],
                    'province' => $logValues['province'],
                    'office' => $logValues['office'],
                    'role' => $logValues['role'],
                    'assigned_barangay' => $logValues['assigned_barangay'],
                ];
                list($logRes, $logStatus, $logErr) = sb_rest('POST', 'profileedit_log', [], [$logRow], ['Prefer: return=minimal']);
                if ($logErr || ($logStatus !== 201 && $logStatus !== 200 && $logStatus !== 204)) {
                    // non-fatal
                }
                $success = 'Profile updated successfully.';
            }
        } else {
            $notice = 'No changes submitted.';
        }
    }

    // Populate data from whichever table we found
    if ($currentRow) { foreach ($columns as $c) { $data[$c] = $currentRow[$c] ?? ''; } }
} else {
    $notice = 'You are not logged in.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Admin Profile</title>
  <!-- External CSS -->
  <link rel="stylesheet" href="style/profile.css" />
  <link rel="stylesheet" href="../../style/profile.css" />
  <!-- Add Font Awesome for icons -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <!-- Add Bootstrap CSS for modal -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
  <div class="container">
    <div class="card">
      <div class="notice">hello <?php echo htmlspecialchars($firstname, ENT_QUOTES, 'UTF-8'); ?></div>
      <?php if (!empty($success)): ?><div class="success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
      <?php if (!empty($error)): ?><div class="error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
      <?php if (!empty($notice)): ?><div class="notice"><?php echo htmlspecialchars($notice); ?></div><?php endif; ?>

      <form method="post">
        <div class="field">
          <label>First name</label>
          <input type="text" value="<?php echo htmlspecialchars($data['user_fname']); ?>" readonly />
        </div>
        <div class="field">
          <label>Middle name</label>
          <input type="text" value="<?php echo htmlspecialchars($data['user_mname']); ?>" readonly />
        </div>
        <div class="field">
          <label>Last name</label>
          <input type="text" value="<?php echo htmlspecialchars($data['user_lname']); ?>" readonly />
        </div>
        <div class="field">
          <label>Birth date</label>
          <input type="text" value="<?php echo htmlspecialchars($data['bdate']); ?>" readonly />
        </div>
        <div class="field">
          <label>Contact</label>
          <div style="display: flex; gap: 8px; align-items: center;">
            <input 
              name="contact" 
              type="tel" 
              pattern="[0-9]{11}" 
              maxlength="11" 
              oninput="this.value = this.value.replace(/[^0-9]/g, '')"
              value="<?php echo htmlspecialchars($data['contact']); ?>" 
              class="contact-input"
              id="contactNumber"
              <?php echo !empty($data['contact_verified_at']) ? 'readonly' : ''; ?>
            />
            <?php if (!empty($data['contact_verified_at'])): ?>
              <span class="verified-badge" style="color: green; font-size: 12px; white-space: nowrap;">
                <i class="fas fa-check-circle"></i> Verified
              </span>
            <?php else: ?>
              <button 
                type="button" 
                class="verify-btn" 
                style="padding: 6px 12px; background: #4CAF50; color: white; border: none; border-radius: 4px; cursor: pointer;"
                onclick="initiateVerification('<?php echo htmlspecialchars($data['contact']); ?>', '<?php echo $userId; ?>', 'admin')"
              >
                Verify
              </button>
            <?php endif; ?>
          </div>
          <div class="verification-status" style="font-size: 12px; margin-top: 4px; color: #666;"></div>
        </div>
        <div class="field">
          <label>Address</label>
          <input name="address" type="text" value="<?php echo htmlspecialchars($data['address']); ?>" />
        </div>
        <div class="field">
          <label>Email</label>
          <input type="email" value="<?php echo htmlspecialchars($data['email']); ?>" readonly />
        </div>
        <div class="field">
          <label>Office</label>
          <input name="office" type="text" value="<?php echo htmlspecialchars($data['office']); ?>" />
        </div>
        <div class="field">
          <label>Role</label>
          <input name="role" type="text" value="<?php echo htmlspecialchars($data['role']); ?>" />
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
          <p>We've sent a 6-digit verification code to <span class="phone-number-display"></span></p>
          <div class="mb-3">
            <label for="otp_code" class="form-label">Enter Verification Code</label>
            <input type="text" class="form-control" id="otp_code" maxlength="6" placeholder="000000">
            <div class="form-text">Didn't receive a code? <a href="#" class="resend-otp" style="pointer-events: none;">Resend code in <span class="countdown">5:00</span></a></div>
          </div>
          <div class="alert alert-danger d-none mt-2 mb-0" role="alert"></div>
          <div class="alert alert-success d-none mt-2 mb-0" role="alert"></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
          <button type="button" class="btn btn-primary submit-otp" onclick="submitOtp()">Verify</button>
        </div>
      </div>
    </div>
  </div>

  <!-- External JavaScript -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="script/contact-verification.js"></script>
  <script>
  // Function to handle verification
  function initiateVerification(phoneNumber, userId, userRole) {
    // Validate phone number
    if (!/^[0-9]{11}$/.test(phoneNumber)) {
      alert('Please enter a valid 11-digit phone number');
      return;
    }

    // Disable the button and show loading state
    const verifyBtn = document.querySelector('.verify-btn');
    const originalText = verifyBtn.innerHTML;
    verifyBtn.disabled = true;
    verifyBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
    
    // Show status message
    const statusElement = document.querySelector('.verification-status');
    statusElement.textContent = 'Sending verification code...';
    statusElement.style.color = '#666';
    
    // Make AJAX call to send OTP
    fetch('../../api/contact-verification.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({
        action: 'send-otp',
        phone: phoneNumber,
        user_id: userId,
        user_role: userRole
      })
    })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        // Show verification modal
        const modal = new bootstrap.Modal(document.getElementById('verificationModal'));
        modal.show();
        
        // Update the phone number display in the modal
        document.querySelector('.phone-number-display').textContent = phoneNumber;
        
        // Store user ID and role in the modal for later use
        const modalForm = document.querySelector('#verificationModal form') || document.createElement('form');
        modalForm.dataset.userId = userId;
        modalForm.dataset.userRole = userRole;
        
        // Start countdown
        startCountdown(300); // 5 minutes
        
        // Update status
        statusElement.textContent = 'Verification code sent!';
        statusElement.style.color = 'green';
      } else {
        statusElement.textContent = data.message || 'Failed to send verification code';
        statusElement.style.color = 'red';
        verifyBtn.disabled = false;
        verifyBtn.innerHTML = originalText;
      }
    })
    .catch(error => {
      console.error('Error:', error);
      statusElement.textContent = 'An error occurred. Please try again.';
      statusElement.style.color = 'red';
      verifyBtn.disabled = false;
      verifyBtn.innerHTML = originalText;
    });
  }

  // Function to handle OTP submission
  function submitOtp() {
    const otpInput = document.querySelector('#otp_code');
    const submitBtn = document.querySelector('.submit-otp');
    const modalForm = document.querySelector('#verificationModal form') || document.createElement('form');
    const userId = modalForm.dataset.userId;
    const userRole = modalForm.dataset.userRole;
    
    // Basic validation
    if (!otpInput.value || otpInput.value.length !== 6) {
      showModalError('Please enter a valid 6-digit code');
      return;
    }
    
    // Disable button and show loading state
    const originalText = submitBtn.innerHTML;
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Verifying...';
    
    // Make AJAX call to verify OTP
    fetch('../../api/contact-verification.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({
        action: 'verify-otp',
        otp: otpInput.value,
        user_id: userId,
        user_role: userRole
      })
    })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        showModalSuccess('Verification successful!');
        
        // Update UI after a short delay
        setTimeout(() => {
          // Hide modal
          const modal = bootstrap.Modal.getInstance(document.getElementById('verificationModal'));
          modal.hide();
          
          // Update the contact field to show as verified
          const contactField = document.querySelector('input[name="contact"]');
          if (contactField) {
            contactField.readOnly = true;
            contactField.value = data.contact_number;
          }
          
          // Replace verify button with verified badge
          const verifyBtn = document.querySelector('.verify-btn');
          if (verifyBtn) {
            verifyBtn.outerHTML = `
              <span class="verified-badge" style="color: green; font-size: 12px; white-space: nowrap;">
                <i class="fas fa-check-circle"></i> Verified
              </span>
            `;
          }
          
          // Update status message
          const statusElement = document.querySelector('.verification-status');
          if (statusElement) {
            statusElement.textContent = 'Contact verified successfully!';
            statusElement.style.color = 'green';
          }
          
          // Clear OTP input
          otpInput.value = '';
          
          // Reset submit button
          submitBtn.disabled = false;
          submitBtn.innerHTML = 'Verify';
        }, 1500);
      } else {
        showModalError(data.message || 'Verification failed. Please try again.');
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalText;
      }
    })
    .catch(error => {
      console.error('Error:', error);
      showModalError('An error occurred. Please try again.');
      submitBtn.disabled = false;
      submitBtn.innerHTML = originalText;
    });
  }

  // Helper functions for modal messages
  function showModalError(message) {
    const errorElement = document.querySelector('.modal .alert-danger');
    const successElement = document.querySelector('.modal .alert-success');
    if (errorElement) {
      errorElement.textContent = message;
      errorElement.classList.remove('d-none');
      if (successElement) successElement.classList.add('d-none');
    }
  }

  function showModalSuccess(message) {
    const successElement = document.querySelector('.modal .alert-success');
    const errorElement = document.querySelector('.modal .alert-danger');
    if (successElement) {
      successElement.textContent = message;
      successElement.classList.remove('d-none');
      if (errorElement) errorElement.classList.add('d-none');
    }
  }

  // Countdown timer
  function startCountdown(seconds) {
    const countdownElement = document.querySelector('.countdown');
    const resendLink = document.querySelector('.resend-otp');
    if (!countdownElement || !resendLink) return;
    
    let remaining = seconds;
    resendLink.style.pointerEvents = 'none';
    
    const interval = setInterval(() => {
      const minutes = Math.floor(remaining / 60);
      const secs = remaining % 60;
      countdownElement.textContent = `${minutes}:${secs < 10 ? '0' : ''}${secs}`;
      
      if (remaining <= 0) {
        clearInterval(interval);
        resendLink.style.pointerEvents = 'auto';
        resendLink.innerHTML = 'Resend code';
        resendLink.onclick = (e) => {
          e.preventDefault();
          const contactField = document.querySelector('input[name="contact"]');
          const verifyBtn = document.querySelector('.verify-btn');
          if (contactField && verifyBtn && !verifyBtn.disabled) {
            initiateVerification(contactField.value, verifyBtn.dataset.userId, verifyBtn.dataset.userRole);
          }
        };
      }
      
      remaining--;
    }, 1000);
  }
  
  // Handle contact number input changes
  document.addEventListener('DOMContentLoaded', function() {
    const contactInput = document.querySelector('input[name="contact"]');
    if (contactInput) {
      // Only allow numbers and limit to 11 digits
      contactInput.addEventListener('input', function(e) {
        this.value = this.value.replace(/[^0-9]/g, '').slice(0, 11);
        
        // If the field is not verified and has 11 digits, enable the verify button
        const verifyBtn = document.querySelector('.verify-btn');
        if (verifyBtn && this.value.length === 11) {
          verifyBtn.disabled = false;
          verifyBtn.setAttribute('data-user-id', '<?php echo $userId; ?>');
          verifyBtn.setAttribute('data-user-role', 'admin');
          verifyBtn.onclick = function() {
            initiateVerification(contactInput.value, this.dataset.userId, this.dataset.userRole);
          };
        } else if (verifyBtn) {
          verifyBtn.disabled = true;
        }
      });
    }
  });
  </script>
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
</body>
</html>
