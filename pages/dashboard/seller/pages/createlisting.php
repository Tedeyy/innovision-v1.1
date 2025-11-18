<?php
session_start();
require_once __DIR__ . '/../../../authentication/lib/supabase_client.php';
require_once __DIR__ . '/../../../authentication/lib/use_case_logger.php';

function safe($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$firstname = isset($_SESSION['firstname']) && $_SESSION['firstname'] !== '' ? $_SESSION['firstname'] : 'User';
$seller_id = $_SESSION['seller_id'] ?? ($_SESSION['user_id'] ?? null);
if ($seller_id !== null) { $seller_id = (int)$seller_id; }
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
  $price = isset($_POST['price']) ? (float)$_POST['price'] : 0.0;

  if ($livestock_type_name === '' || $breed_name === '' || $address === '' || $age <= 0 || $weight <= 0 || $price <= 0) {
    $error = 'Please complete all fields with valid values.';
  } else {
    // Verify seller exists to satisfy FK constraint on livestocklisting_logs(seller_id -> seller.user_id)
    $effectiveSellerId = (int)$seller_id;
    [$sres, $sstatus, $serr] = sb_rest('GET', 'seller', [
      'select' => 'user_id',
      'user_id' => 'eq.'.$effectiveSellerId,
      'limit' => 1
    ]);
    if (!($sstatus>=200 && $sstatus<300) || !is_array($sres) || count($sres)===0){
      // Try fallback to session user_id if different from seller_id
      $sessionUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
      if ($sessionUserId && $sessionUserId !== $effectiveSellerId){
        [$sres2, $sstatus2, $serr2] = sb_rest('GET', 'seller', [
          'select' => 'user_id',
          'user_id' => 'eq.'.$sessionUserId,
          'limit' => 1
        ]);
        if ($sstatus2>=200 && $sstatus2<300 && is_array($sres2) && count($sres2)>0){
          $effectiveSellerId = $sessionUserId;
        } else {
          $error = 'Your seller profile is not yet activated in seller table. Please complete seller setup.';
        }
      } else {
        $error = 'Your seller profile is not yet activated in seller table. Please complete seller setup.';
      }
    }

    if ($error){
      // stop processing early
    } else {
    // 1) Log entry first (let DB default status apply)
    $logPayload = [[
      'seller_id' => (int)$effectiveSellerId,
      'livestock_type' => $livestock_type_name,
      'breed' => $breed_name,
      'address' => $address,
      'age' => (int)$age,
      'weight' => (float)$weight,
      'price' => (float)$price,
      'status' => 'Pending'
    ]];
    [$lres, $lstatus, $lerr] = sb_rest('POST', 'livestocklisting_logs', [], $logPayload, ['Prefer: return=representation']);
    if (!($lstatus >= 200 && $lstatus < 300)) {
      $detail = is_array($lres) ? json_encode($lres) : (string)$lres;
      $error = 'Failed to write log (status '.strval($lstatus).'). Details: '.$detail;
    } else {
      // 2) Create review record and get listing_id + created
      $payload = [[
        'seller_id' => (int)$effectiveSellerId,
        'livestock_type' => $livestock_type_name,
        'breed' => $breed_name,
        'address' => $address,
        'age' => (int)$age,
        'weight' => (float)$weight,
        'price' => (float)$price
      ]];
      [$ires, $istatus, $ierr] = sb_rest('POST', 'reviewlivestocklisting', [], $payload, ['Prefer: return=representation']);
      if ($istatus >= 200 && $istatus < 300) {
        $created = is_array($ires) && isset($ires[0]) ? $ires[0] : null;
        $listingId = $created['listing_id'] ?? null;
        $createdAt = $created['created'] ?? null;

        // Log use case: Seller created new livestock listing
        $purpose = format_use_case_description('Livestock Listing Created', [
          'listing_id' => $listingId,
          'livestock_type' => $livestock_type_name,
          'breed' => $breed_name,
          'age' => $age . ' years',
          'weight' => $weight . ' kg',
          'price' => '₱' . number_format($price, 2),
          'address' => substr($address, 0, 100) . (strlen($address) > 100 ? '...' : ''),
          'photo_count' => isset($_FILES['photos']) ? count($_FILES['photos']['name']) : 0
        ]);
        log_use_case($purpose);

        // 3) Upload photos to storage bucket path listings/underreview/<seller_id>_<fullname>/
        if ($listingId && isset($_FILES['photos']) && is_array($_FILES['photos']['name'])){
          // Build folder name: <seller_id>_<fullname>
          $sfname = '';$smname='';$slname='';
          [$sinf,$sinfst,$sinfe] = sb_rest('GET','seller',[ 'select'=>'user_fname,user_mname,user_lname','user_id'=>'eq.'.$effectiveSellerId, 'limit'=>1 ]);
          if ($sinfst>=200 && $sinfst<300 && is_array($sinf) && isset($sinf[0])){
            $sfname = (string)($sinf[0]['user_fname'] ?? '');
            $smname = (string)($sinf[0]['user_mname'] ?? '');
            $slname = (string)($sinf[0]['user_lname'] ?? '');
          }
          $fullname = trim($sfname.' '.($smname?:'').' '.$slname);
          $sanFull = strtolower(preg_replace('/[^a-z0-9]+/i','_', $fullname));
          $sanFull = trim($sanFull, '_');
          if ($sanFull === '') { $sanFull = 'user'; }
          // Created key as YmdHis
          $createdKey = $createdAt ? date('YmdHis', strtotime($createdAt)) : date('YmdHis');

          $validFiles = [];
          $total = count($_FILES['photos']['name']);
          for ($i=0; $i<$total; $i++){
            if ($_FILES['photos']['error'][$i] === UPLOAD_ERR_OK){
              $validFiles[] = [
                'tmp' => $_FILES['photos']['tmp_name'][$i],
                'name' => $_FILES['photos']['name'][$i]
              ];
            }
          }
          if (count($validFiles) !== 3){
            $error = 'Please upload exactly 3 images.';
          } else {
            $base = function_exists('sb_base_url') ? sb_base_url() : (getenv('SUPABASE_URL') ?: '');
            $service = function_exists('sb_env') ? (sb_env('SUPABASE_SERVICE_ROLE_KEY') ?: '') : (getenv('SUPABASE_SERVICE_ROLE_KEY') ?: '');
            $auth = $_SESSION['supa_access_token'] ?? ($service ?: (getenv('SUPABASE_KEY') ?: ''));
            $folder = ((int)$effectiveSellerId).'_'.$sanFull;
            $bucketPathPrefix = rtrim($base,'/').'/storage/v1/object/listings/underreview/'.$folder.'/';
            $idx = 1;
            foreach ($validFiles as $vf){
              $tmp = $vf['tmp'];
              $fname = $createdKey.'_'.$idx.'img.jpg';
              $pathUrl = $bucketPathPrefix.$fname;
              $mime = mime_content_type($tmp) ?: 'image/jpeg';
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
              $idx++;
            }
          }
        }

        if (!$error){
          $message = 'Listing submitted for review.'.(count($uploadedInfo)?(' Uploaded '.count($uploadedInfo).' image(s).'):'');
          // Reset POST fields after success
          $_POST = [];
        }
      } else {
        $error = 'Failed to submit listing to review (status '.strval($istatus).').';
      }
    }
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
  <div class="wrap" style="max-width:1100px;margin:0 auto;">
    <div class="top">
      <div><h1>Create Listing</h1></div>
      <div><a class="btn" href="../managelistings.php">Back</a></div>
    </div>
    <div class="card" style="max-width:900px;margin:0 auto;">
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
            <label>Price</label>
            <input type="number" min="0" step="0.01" name="price" value="<?php echo htmlspecialchars($_POST['price'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required />
          </div>
          <div style="grid-column:1 / span 2">
            <label>Upload Photos (proof)</label>
            <input id="photos" type="file" name="photos[]" accept="image/*" multiple />
            <div id="photoPreview" style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap"></div>
          </div>
        </div>
        <div style="margin-top:16px;display:flex;gap:8px;">
          <button type="submit" class="btn">Submit for Review</button>
          <a class="btn" href="../dashboard.php" style="background:#4a5568;">Cancel</a>
        </div>
      </form>
    </div>
  </div>
  <div id="breed-data"
       data-breeds='<?php echo json_encode($breeds ?: [], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>'
       data-current="<?php echo htmlspecialchars(isset($_POST['breed']) ? $_POST['breed'] : '', ENT_QUOTES, 'UTF-8'); ?>"
       hidden></div>
  <script src="script/createlisting.js"></script>
</body>
</html>
