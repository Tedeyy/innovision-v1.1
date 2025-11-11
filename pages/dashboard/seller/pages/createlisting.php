<?php
session_start();
require_once __DIR__ . '/../../../authentication/lib/supabase_client.php';

$firstname = isset($_SESSION['firstname']) && $_SESSION['firstname'] !== '' ? $_SESSION['firstname'] : 'User';
$seller_id = $_SESSION['user_id'] ?? null;
if (!$seller_id) {
  http_response_code(302);
  header('Location: ../dashboard.php');
  exit;
}

$message = '';
$error = '';
$uploadedInfo = [];

// Fetch livestock types and breeds
[$types, $tstatus, $terr] = sb_rest('GET', 'livestock_type', ['select'=>'type_id,name','order'=>'name.asc']);
if ($tstatus < 200 || $tstatus >= 300) { $types = []; }
[$breeds, $bstatus, $berr] = sb_rest('GET', 'livestock_breed', ['select'=>'breed_id,type_id,name','order'=>'name.asc']);
if ($bstatus < 200 || $bstatus >= 300) { $breeds = []; }

// Handle form submit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $livestock_type_name = trim($_POST['livestock_type'] ?? '');
  $breed_name = trim($_POST['breed'] ?? '');
  $address = trim($_POST['address'] ?? '');
  // Removed from form, send empty strings to satisfy NOT NULL columns
  $barangay = '';
  $municipality = '';
  $province = '';
  $age = isset($_POST['age']) ? (int)$_POST['age'] : 0;
  $weight = isset($_POST['weight']) ? (float)$_POST['weight'] : 0.0;
  $marketprice = isset($_POST['marketprice']) ? (float)$_POST['marketprice'] : 0.0;
  $price = isset($_POST['price']) ? (float)$_POST['price'] : 0.0;

  if ($livestock_type_name === '' || $breed_name === '' || $address === '' || $age <= 0 || $weight <= 0 || $price <= 0) {
    $error = 'Please complete all fields with valid values.';
  } else {
    $payload = [[
      'seller_id' => (int)$seller_id,
      'livestock_type' => $livestock_type_name,
      'breed' => $breed_name,
      'address' => $address,
      'barangay' => $barangay,
      'municipality' => $municipality,
      'province' => $province,
      'age' => (int)$age,
      'weight' => (float)$weight,
      'price' => (float)$price
    ]];
    [$ires, $istatus, $ierr] = sb_rest('POST', 'reviewlivestocklisting', [], $payload, ['Prefer: return=representation']);
    if ($istatus >= 200 && $istatus < 300) {
      // Expect created row back
      $created = is_array($ires) && isset($ires[0]) ? $ires[0] : null;
      $listingId = $created['listing_id'] ?? null;
      // Attempt to upload photos to storage if provided
      if ($listingId && isset($_FILES['photos']) && is_array($_FILES['photos']['name'])){
        $fullName = isset($_SESSION['name']) && $_SESSION['name'] !== '' ? $_SESSION['name'] : $firstname;
        $folder = $listingId.'_'.preg_replace('/[^A-Za-z0-9_\- ]/','', str_replace(' ','_', $fullName));
        $base = function_exists('sb_base_url') ? sb_base_url() : (getenv('SUPABASE_URL') ?: '');
        $service = function_exists('sb_env') ? (sb_env('SUPABASE_SERVICE_ROLE_KEY') ?: '') : (getenv('SUPABASE_SERVICE_ROLE_KEY') ?: '');
        $auth = $_SESSION['supa_access_token'] ?? ($service ?: (getenv('SUPABASE_KEY') ?: ''));
        $bucketPathPrefix = rtrim($base,'/').'/storage/v1/object/listings/underreview/'.$folder.'/';
        $count = count($_FILES['photos']['name']);
        for ($i=0; $i<$count; $i++){
          if ($_FILES['photos']['error'][$i] !== UPLOAD_ERR_OK) continue;
          $tmp = $_FILES['photos']['tmp_name'][$i];
          $orig = $_FILES['photos']['name'][$i];
          $ext = pathinfo($orig, PATHINFO_EXTENSION);
          $safe = preg_replace('/[^A-Za-z0-9_\-\.]/','_', $orig);
          $fname = uniqid('img_', true).($ext?('.'.$ext):'');
          $pathUrl = $bucketPathPrefix.$fname;
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
          if ($upCode>=200 && $upCode<300){ $uploadedInfo[] = $fname; }
        }
      }
      // Insert entry log
      $logPayload = [[
        'seller_id' => (int)$seller_id,
        'livestock_type' => $livestock_type_name,
        'breed' => $breed_name,
        'address' => $address,
        'age' => (int)$age,
        'weight' => (float)$weight,
        'marketprice' => (float)$marketprice,
        'price' => (float)$price
      ]];
      [$lres, $lstatus, $lerr] = sb_rest('POST', 'livestocklisting_logs', [], $logPayload, ['Prefer: return=minimal']);
      $message = 'Listing submitted for review.'.(count($uploadedInfo)?(' Uploaded '.count($uploadedInfo).' image(s).'):'');
      // Reset POST fields after success
      $_POST = [];
    } else {
      $error = 'Failed to submit listing.';
    }
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Create Listing</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../style/dashboard.css">
</head>
<body>
  <div class="wrap">
    <div class="top">
      <div><h1>Create Listing</h1></div>
      <div><a class="btn" href="../dashboard.php">Back to Dashboard</a></div>
    </div>
    <div class="card">
      <?php if ($message): ?>
        <div style="padding:10px;border:1px solid #c6f6d5;background:#f0fff4;color:#22543d;border-radius:8px;margin-bottom:12px;"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
      <?php endif; ?>
      <?php if ($error): ?>
        <div style="padding:10px;border:1px solid #fed7d7;background:#fff5f5;color:#742a2a;border-radius:8px;margin-bottom:12px;"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
      <?php endif; ?>

      <form method="post" action="" enctype="multipart/form-data">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
          <div>
            <label>Livestock Type</label>
            <select name="livestock_type" id="livestock_type" required>
              <option value="">Select type</option>
              <?php foreach (($types ?: []) as $t): ?>
                <?php $sel = (isset($_POST['livestock_type']) && $_POST['livestock_type']===$t['name']) ? 'selected' : ''; ?>
                <option value="<?php echo htmlspecialchars($t['name'], ENT_QUOTES, 'UTF-8'); ?>" data-typeid="<?php echo (int)$t['type_id']; ?>" <?php echo $sel; ?>><?php echo htmlspecialchars($t['name'], ENT_QUOTES, 'UTF-8'); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label>Breed</label>
            <select name="breed" id="breed" required>
              <option value="">Select breed</option>
            </select>
          </div>
          <div>
            <label>Address</label>
            <input type="text" name="address" value="<?php echo htmlspecialchars($_POST['address'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required />
          </div>
          <div>
            <label>Age</label>
            <input type="number" min="0" step="1" name="age" value="<?php echo htmlspecialchars($_POST['age'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required />
          </div>
          <div>
            <label>Weight (kg)</label>
            <input type="number" min="0" step="0.01" name="weight" value="<?php echo htmlspecialchars($_POST['weight'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required />
          </div>
          <div>
            <label>Market Price</label>
            <input type="number" min="0" step="0.01" name="marketprice" value="<?php echo htmlspecialchars($_POST['marketprice'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required />
          </div>
          <div>
            <label>Price</label>
            <input type="number" min="0" step="0.01" name="price" value="<?php echo htmlspecialchars($_POST['price'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required />
          </div>
          <div style="grid-column:1 / span 2">
            <label>Upload Photos (proof)</label>
            <input type="file" name="photos[]" accept="image/*" multiple />
          </div>
        </div>
        <div style="margin-top:16px;display:flex;gap:8px;">
          <button type="submit" class="btn">Submit for Review</button>
          <a class="btn" href="../dashboard.php" style="background:#4a5568;">Cancel</a>
        </div>
      </form>
    </div>
  </div>
  <script>
    (function(){
      // Prepare breeds by type_id map
      var breeds = <?php echo json_encode($breeds ?: []); ?>;
      var byType = {};
      (breeds || []).forEach(function(b){
        var k = String(b.type_id);
        if (!byType[k]) byType[k] = [];
        byType[k].push(b);
      });
      function populateBreeds(){
        var typeSel = document.getElementById('livestock_type');
        var breedSel = document.getElementById('breed');
        if (!typeSel || !breedSel) return;
        var opt = typeSel.options[typeSel.selectedIndex];
        var tid = opt ? opt.getAttribute('data-typeid') : null;
        var list = tid && byType[tid] ? byType[tid] : [];
        // Preserve selected value if possible
        var current = '<?php echo isset($_POST['breed']) ? addslashes($_POST['breed']) : '';?>';
        breedSel.innerHTML = '<option value="">Select breed</option>';
        list.forEach(function(b){
          var sel = (current && current===b.name) ? ' selected' : '';
          var o = document.createElement('option');
          o.value = b.name; o.textContent = b.name; if (sel) o.selected = true;
          breedSel.appendChild(o);
        });
      }
      document.getElementById('livestock_type')?.addEventListener('change', populateBreeds);
      populateBreeds();
    })();
  </script>
</body>
</html>
