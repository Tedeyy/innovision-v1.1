<?php
session_start();
require_once __DIR__ . '/../../../authentication/lib/supabase_client.php';

if (($_SESSION['role'] ?? '') !== 'admin'){
  http_response_code(302);
  header('Location: ../dashboard.php');
  exit;
}

function safe($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

[$rows, $status, $err] = sb_rest('GET', 'livestocklisting', [
  'select'=>'listing_id,seller_id,livestock_type,breed,address,age,weight,price,status,bat_id,created',
  'order'=>'created.desc'
]);
if (!($status>=200 && $status<300) || !is_array($rows)) { $rows = []; }

function fetch_seller($seller_id){
  [$sres,$sstatus,$serr] = sb_rest('GET','seller',[
    'select'=>'user_id,user_fname,user_mname,user_lname,location',
    'user_id'=>'eq.'.$seller_id,
    'limit'=>1
  ]);
  if ($sstatus>=200 && $sstatus<300 && is_array($sres) && isset($sres[0])) return $sres[0];
  return null;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Listing Management</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../style/dashboard.css">
  <style>
    .card{margin-bottom:14px}
    .thumbs img{width:120px;height:120px;object-fit:cover;border-radius:8px;border:1px solid #e2e8f0;margin-right:8px}
    .row{display:grid;grid-template-columns:160px 1fr 260px;gap:12px;align-items:start}
    .map{height:160px;border-radius:8px;border:1px solid #e2e8f0}
    .muted{color:#4a5568;font-size:12px}
    .detail{border-top:1px solid #e2e8f0;margin-top:8px;padding-top:8px}
    .detail-images{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:8px}
    .detail-images img{width:140px;height:140px;object-fit:cover;border-radius:8px;border:1px solid #e2e8f0;cursor:zoom-in}
    #img-modal{position:fixed;inset:0;background:rgba(0,0,0,0.7);display:none;align-items:center;justify-content:center;z-index:9999}
    #img-modal img{max-width:90vw;max-height:90vh;border-radius:8px;box-shadow:0 10px 25px rgba(0,0,0,0.5);background:#000}
  </style>
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
</head>
<body>
  <div class="wrap">
    <div class="top" style="margin-bottom:8px;">
      <div><h1>Listing Management</h1></div>
      <div>
        <a class="btn" href="../dashboard.php">Back to Dashboard</a>
      </div>
    </div>

    <?php if (!empty($_SESSION['flash_message'])): ?>
      <div class="card" style="border-left:4px solid #10b981;color:#065f46;background:#ecfdf5;">
        <div style="padding:10px;"><?php echo safe($_SESSION['flash_message']); ?></div>
      </div>
      <?php unset($_SESSION['flash_message']); ?>
    <?php endif; ?>
    <?php if (!empty($_SESSION['flash_error'])): ?>
      <div class="card" style="border-left:4px solid #ef4444;color:#7f1d1d;background:#fef2f2;">
        <div style="padding:10px;"><?php echo safe($_SESSION['flash_error']); ?></div>
      </div>
      <?php unset($_SESSION['flash_error']); ?>
    <?php endif; ?>

    <?php foreach ($rows as $r):
      $seller = fetch_seller((int)$r['seller_id']);
      // New folder: <seller_id>_<fullname_sanitized>
      $sfname = $seller['user_fname'] ?? '';
      $smname = $seller['user_mname'] ?? '';
      $slname = $seller['user_lname'] ?? '';
      $fullname = trim(($sfname).' '.($smname?:'').' '.($slname));
      $sanFull = strtolower(preg_replace('/[^a-z0-9]+/i','_', $fullname));
      $sanFull = trim($sanFull, '_');
      if ($sanFull === '') { $sanFull = 'user'; }
      $newFolder = ((int)$r['seller_id']).'_'.$sanFull;
      $legacyFolder = ((int)$r['seller_id']).'_'.((int)$r['listing_id']);
      $createdKey = isset($r['created']) ? date('YmdHis', strtotime($r['created'])) : '';
      $lat = null; $lng = null;
      if ($seller && !empty($seller['location'])){
        $loc = json_decode($seller['location'], true);
        if (is_array($loc)) { $lat = $loc['lat'] ?? null; $lng = $loc['lng'] ?? null; }
      }
    ?>
      <div class="card" data-listing-id="<?php echo (int)$r['listing_id']; ?>" data-folder="<?php echo safe($newFolder ?: $legacyFolder); ?>" data-lat="<?php echo $lat!==null ? safe($lat) : ''; ?>" data-lng="<?php echo $lng!==null ? safe($lng) : ''; ?>">
        <div class="row">
          <div class="thumbs">
            <?php $thumbNew = "../../bat/pages/storage_image.php?path=listings/underreview/$newFolder/{$createdKey}_1img.jpg"; $thumbLegacy = "../../bat/pages/storage_image.php?path=listings/underreview/$legacyFolder/image1"; ?>
            <img class="thumb-img" src="<?php echo $thumbNew; ?>" alt="thumbnail" onerror="this.onerror=null; this.src='<?php echo $thumbLegacy; ?>';" />
            <div class="img-error" style="display:none;color:#b91c1c;font-size:12px;margin-top:6px;"></div>
          </div>
          <div>
            <div><strong><?php echo safe($r['livestock_type'].' • '.$r['breed']); ?></strong></div>
            <div>Address: <?php echo safe($r['address']); ?></div>
            <div>Age: <?php echo safe($r['age']); ?> • Weight: <?php echo safe($r['weight']); ?>kg • Price: ₱<?php echo safe($r['price']); ?></div>
            <div class="muted">Listing #<?php echo (int)$r['listing_id']; ?> • Seller #<?php echo (int)$r['seller_id']; ?> • Created <?php echo safe($r['created']); ?></div>
            <?php if ($seller): ?>
              <div>Seller: <?php echo safe(($seller['user_fname']??'').' '.($seller['user_lname']??'')); ?></div>
            <?php endif; ?>
          </div>
          <div>
            <button type="button" class="btn" data-show-id="<?php echo (int)$r['listing_id']; ?>">Show</button>
          </div>
        </div>
        <div id="detail-<?php echo (int)$r['listing_id']; ?>" class="detail" style="display:none;">
          <div class="detail-images">
            <?php for ($i=1;$i<=3;$i++):
              $newImg = "../../bat/pages/storage_image.php?path=listings/underreview/$newFolder/{$createdKey}_{$i}img.jpg";
              $legacyImg = "../../bat/pages/storage_image.php?path=listings/underreview/$legacyFolder/image$i";
            ?>
              <img src="<?php echo $newImg; ?>" alt="image<?php echo $i; ?>" class="detail-img" data-full="<?php echo $newImg; ?>" onerror="this.onerror=null; this.src='<?php echo $legacyImg; ?>'; this.setAttribute('data-full','<?php echo $legacyImg; ?>');" />
            <?php endfor; ?>
          </div>
          <div id="map-<?php echo (int)$r['listing_id']; ?>" class="map" style="margin-top:8px;"></div>
          <form method="post" action="listing_actions.php" style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap">
            <input type="hidden" name="listing_id" value="<?php echo (int)$r['listing_id']; ?>" />
            <button class="btn" name="action" value="approve" type="submit">Approve</button>
            <button class="btn" name="action" value="deny" type="submit" style="background:#e53e3e">Deny</button>
          </form>
          <div class="detail-img-error" style="display:none;color:#b91c1c;font-size:12px;margin-top:6px;"></div>
        </div>
      </div>
    <?php endforeach; ?>

    <?php if (!count($rows)): ?>
      <div class="card">No listings to manage.</div>
    <?php endif; ?>
  </div>

  <div id="img-modal"></div>

  <script>
    (function(){
      var cards = document.querySelectorAll('.card');
      cards.forEach(function(card){
        var showButton = card.querySelector('[data-show-id]');
        var detail = card.querySelector('.detail');
        var mapEl = detail ? detail.querySelector('.map') : null;
        var mapInitialized = false;
        if (!showButton || !detail) return;
        showButton.addEventListener('click', function(){
          var isHidden = (detail.style.display === 'none' || detail.style.display === '');
          detail.style.display = isHidden ? 'block' : 'none';
          if (isHidden && mapEl && !mapInitialized){
            var lat = card.getAttribute('data-lat');
            var lng = card.getAttribute('data-lng');
            if (lat && lng){
              var mapInstance = L.map(mapEl).setView([lat,lng], 12);
              L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(mapInstance);
              L.marker([lat,lng]).addTo(mapInstance);
            } else {
              mapEl.style.display='flex';
              mapEl.style.alignItems='center';
              mapEl.style.justifyContent='center';
              mapEl.style.color='#4a5568';
              mapEl.style.fontSize='12px';
              mapEl.innerText='No location available';
            }
            mapInitialized = true;
          }
        });
      });
      var modal = document.getElementById('img-modal');
      var detailImages = document.querySelectorAll('.detail-img');
      detailImages.forEach(function(img){
        img.addEventListener('click', function(){
          if (!modal) return;
          var fullImage = img.getAttribute('data-full');
          modal.innerHTML = '<img src="' + fullImage + '">';
          modal.style.display = 'flex';
        });
      });
      // Image error explanations
      document.querySelectorAll('.card[data-listing-id]').forEach(function(card){
        var folder = card.getAttribute('data-folder') || '';
        var thumb = card.querySelector('.thumb-img');
        var thumbErr = card.querySelector('.img-error');
        if (thumb && thumbErr){
          thumb.addEventListener('error', function(){
            var attempted = thumb.getAttribute('src') || '';
            thumbErr.textContent = 'No thumbnail at ' + attempted + ' (folder '+folder+').';
            thumbErr.style.display = 'block';
          }, { once: true });
        }
        var imgs = card.querySelectorAll('.detail-img');
        var detailErr = card.querySelector('.detail-img-error');
        var failCount = 0; var total = imgs.length;
        imgs.forEach(function(im){
          im.addEventListener('error', function(){
            failCount++;
            if (failCount === total && detailErr){
              detailErr.textContent = 'No images found in listings/underreview/'+folder+' (image1..3).';
              detailErr.style.display = 'block';
            }
          }, { once: true });
        });
      });
      if (modal){
        modal.addEventListener('click', function(e){
          if (e.target === modal){ modal.style.display = 'none'; }
        });
      }
    })();
  </script>
</body>
</html>
