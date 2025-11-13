<?php
session_start();
require_once __DIR__ . '/../../lib/supabase_client.php';
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first = trim($_POST['firstname'] ?? '');
    $mid   = trim($_POST['middlename'] ?? '');
    $last  = trim($_POST['lastname'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $office = trim($_POST['office'] ?? '');
    $doctype = trim($_POST['doctype'] ?? '');
    $docnum = trim($_POST['docnum'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $confirm  = (string)($_POST['confirm_password'] ?? '');
    $bdate = trim($_POST['bdate'] ?? '');
    $contact = trim($_POST['contact'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $parts = array_filter([$first, $mid, $last], function($v){ return $v !== ''; });
    $fullname = implode(' ', $parts);
    $uploadedFileName = '';

    // Server-side password validation
    $strong = (bool)preg_match('/^(?=.*[A-Za-z])(?=.*\d)(?=.*[^A-Za-z0-9]).{8,}$/', $password);
    if (!$strong) {
        $error = 'Password must be at least 8 characters and include letters, numbers, and symbols.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    }

    if (!$error && isset($_FILES['idphoto']) && $_FILES['idphoto']['error'] === UPLOAD_ERR_OK) {
        $tmp = $_FILES['idphoto']['tmp_name'];
        $orig = $_FILES['idphoto']['name'];
        $ext = pathinfo($orig, PATHINFO_EXTENSION);
        $safeFull = preg_replace('/[^A-Za-z0-9_\- ]/','', str_replace(' ','_', $fullname));
        $safeEmail = preg_replace('/[^A-Za-z0-9_\-]/','_', strtolower($email));
        $fname = ($safeFull !== '' ? $safeFull : 'superadmin').'_'. ($safeEmail ?: 'email') . ($ext?('.'.$ext):'');
        $uploadedFileName = $fname;

        $base = function_exists('sb_base_url') ? sb_base_url() : (getenv('SUPABASE_URL') ?: '');
        $service = function_exists('sb_env') ? (sb_env('SUPABASE_SERVICE_ROLE_KEY') ?: '') : (getenv('SUPABASE_SERVICE_ROLE_KEY') ?: '');
        $auth = $_SESSION['supa_access_token'] ?? ($service ?: (getenv('SUPABASE_KEY') ?: ''));
        $pathUrl = rtrim($base,'/').'/storage/v1/object/users/superadmin/'.$fname;
        $mime = mime_content_type($tmp) ?: 'application/octet-stream';
        $ch = curl_init();
        curl_setopt_array($ch, [
          CURLOPT_URL => $pathUrl,
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_CUSTOMREQUEST => 'POST',
          CURLOPT_HTTPHEADER => [
            'apikey: '.(function_exists('sb_anon_key')? sb_anon_key() : (getenv('SUPABASE_KEY') ?: '')),
            'Authorization: Bearer '.$auth,
            'Content-Type: '.$mime,
            'x-upsert: true'
          ],
          CURLOPT_POSTFIELDS => file_get_contents($tmp)
        ]);
        $upRes = curl_exec($ch);
        $upCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $upErr = curl_error($ch);
        curl_close($ch);
        if (!($upCode>=200 && $upCode<300)){
            $error = 'Failed to upload ID (status '.strval($upCode).').';
        } else {
            $message = 'ID uploaded successfully.';
        }
    }

    // Supabase email authentication signup first
    if (!$error) {
        if ($email === '') { $error = 'Email is required.'; }
    }
    if (!$error) {
        $base = function_exists('sb_base_url') ? sb_base_url() : (getenv('SUPABASE_URL') ?: '');
        $authUrl = rtrim($base,'/').'/auth/v1/signup';
        $apikey = function_exists('sb_anon_key') ? sb_anon_key() : (getenv('SUPABASE_KEY') ?: '');
        $signupBody = json_encode([
          'email' => $email,
          'password' => $password,
          'data' => [ 'role' => 'superadmin' ]
        ]);
        $ch = curl_init();
        curl_setopt_array($ch, [
          CURLOPT_URL => $authUrl,
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_POST => true,
          CURLOPT_HTTPHEADER => [
            'apikey: '.$apikey,
            'Content-Type: application/json'
          ],
          CURLOPT_POSTFIELDS => $signupBody
        ]);
        $signupResRaw = curl_exec($ch);
        $signupCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $signupErr = curl_error($ch);
        curl_close($ch);
        $signupJson = json_decode($signupResRaw, true);
        if (!($signupCode>=200 && $signupCode<300)){
            $detail = is_array($signupJson) ? json_encode($signupJson) : (string)$signupResRaw;
            $error = 'Failed to sign up (status '.strval($signupCode).'). Details: '.$detail;
        }
    }

    // Insert row to superadmin table with schema columns
    if (!$error) {
        $payload = [[
          'user_fname' => $first,
          'user_mname' => $mid,
          'user_lname' => $last,
          'bdate' => $bdate,
          'contact' => $contact,
          'address' => $address,
          'email' => $email,
          'office' => $office,
          'role' => 'superadmin',
          'doctype' => $doctype,
          'docnum' => $docnum,
          'username' => $username,
          'password' => password_hash($password, PASSWORD_DEFAULT)
        ]];
        [$ires,$istatus,$ierr] = sb_rest('POST', 'superadmin', [], $payload, ['Prefer: return=representation']);
        if ($istatus>=200 && $istatus<300 && is_array($ires) && count($ires)>0) {
            header('Location: ../../../../index.html');
            exit;
        } else {
            $detail = is_array($ires) ? json_encode($ires) : (string)$ires;
            $error = 'Failed to save profile (status '.strval($istatus).'). Details: '.$detail;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin Registration</title>
    <link rel="stylesheet" href="style/registrationpage.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
    <div class="wrap">
        <div class="card">
            <h1>Super Admin Registration</h1>
            <?php if ($message): ?>
              <div style="padding:10px;border:1px solid #c6f6d5;background:#f0fff4;color:#22543d;border-radius:8px;margin-bottom:12px;">&nbsp;<?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
              <div style="padding:10px;border:1px solid #fed7d7;background:#fff5f5;color:#742a2a;border-radius:8px;margin-bottom:12px;">&nbsp;<?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
            <form action="" method="post" enctype="multipart/form-data">
                <div class="fname">
                    <label for="firstname">First Name:</label>
                    <input type="text" name="firstname" id="firstname" required>
                </div>
                <div class="mname">
                    <label for="middlename">Middle Name:</label>
                    <input type="text" name="middlename" id="middlename" required>
                </div>
                <div class="lname">
                    <label for="lastname">Last Name:</label>
                    <input type="text" name="lastname" id="lastname" required>
                </div>
                <div class="bdate">
                    <label for="bdate">Birthdate:</label>
                    <input type="date" name="bdate" id="bdate" required>
                </div>
                <div class="contact">
                    <label for="contact">Contact:</label>
                    <input type="text" name="contact" id="contact" required>
                </div>
                <div class="address">
                    <label for="address">Address:</label>
                    <textarea name="address" id="address" required></textarea>
                </div>
                <div class="email">
                    <label for="email">Email:</label>
                    <input type="email" name="email" id="email" required>
                </div>
                <div class="office">
                    <label for="office">Office:</label>
                    <input type="text" name="office" id="office" required>
                </div>
                <div class="doctype">
                    <label for="doctype">Valid ID Type:</label>
                    <select id="doctype" name="doctype" required>
                    <option value="">Select Document Type</option>
                    <option value="Driver's License">Driver's License</option>
                    <option value="Passport">Passport</option>
                    <option value="National ID">National ID</option>
                    <option value="Student ID">Student ID</option>
                    <option value="Other">Other</option>
                </select>
                </div>
                <div class="idupload">
                    <label for="idphoto">Valid ID:</label>
                    <input type="file" name="idphoto" id="idphoto" accept="image/*" required>
                    <div id="idPreview" style="margin-top:10px;"></div>
                </div>
                <div class="docnum">
                    <label for="docnum">ID Number:</label>
                    <input type="text" name="docnum" id="docnum" required>
                </div>
                <div class="username">
                    <label for="username">Username:</label>
                    <input type="text" name="username" id="username" required>
                </div>
                <div class="password">
                    <label for="password">Password:</label>
                    <input type="password" name="password" id="password" required>
                </div>
                <div class="password">
                    <label for="confirm_password">Confirm Password:</label>
                    <input type="password" name="confirm_password" id="confirm_password" required>
                </div>
                <div class="password" style="grid-column:1 / span 2">
                    <label style="display:flex;align-items:center;gap:8px;user-select:none;">
                        <input type="checkbox" id="showPw"> Show password
                    </label>
                </div>
                <div class="submit">
                    <button type="submit">Register</button>
                </div>
            </form>
            <div class="back"><a href="../../../../index.html">Back</a></div>
        </div>
    </div>
    <script>
    (function(){
      var input = document.getElementById('idphoto');
      var prev = document.getElementById('idPreview');
      if (!input || !prev) return;
      input.addEventListener('change', function(){
        while (prev.firstChild) prev.removeChild(prev.firstChild);
        var f = input.files && input.files[0];
        if (!f) return;
        if (f.type && f.type.startsWith('image/')){
          var reader = new FileReader();
          reader.onload = function(e){
            var img = document.createElement('img');
            img.src = e.target.result;
            img.alt = f.name;
            img.style.width = '160px';
            img.style.height = '120px';
            img.style.objectFit = 'cover';
            img.style.border = '1px solid #e2e8f0';
            img.style.borderRadius = '8px';
            img.style.background = '#f8fafc';
            prev.appendChild(img);
          };
          reader.readAsDataURL(f);
        } else {
          var note = document.createElement('div');
          note.textContent = 'Selected: '+f.name;
          note.style.color = '#4a5568';
          prev.appendChild(note);
        }
      });
    })();
    (function(){
      var form = document.querySelector('form');
      var pw = document.getElementById('password');
      var cpw = document.getElementById('confirm_password');
      var show = document.getElementById('showPw');
      function strong(s){ return /^(?=.*[A-Za-z])(?=.*\d)(?=.*[^A-Za-z0-9]).{8,}$/.test(s||''); }
      if (show){
        show.addEventListener('change', function(){
          var t = show.checked ? 'text' : 'password';
          if (pw) pw.type = t; if (cpw) cpw.type = t;
        });
      }
      if (form){
        form.addEventListener('submit', function(e){
          if (!pw || !cpw) return;
          if (!strong(pw.value)){
            e.preventDefault();
            alert('Password must be at least 8 characters and include letters, numbers, and symbols.');
            return;
          }
          if (pw.value !== cpw.value){
            e.preventDefault();
            alert('Passwords do not match.');
          }
        });
      }
    })();
    </script>
</body>
</html>
