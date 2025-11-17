<?php
session_start();
require_once __DIR__ . '/../../../authentication/lib/supabase_client.php';

// Handle rating submission
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action']) && $_POST['action']==='submit_rating'){
  header('Content-Type: application/json');
  $buyerId  = (int)($_POST['buyer_id'] ?? 0);
  $sellerId = (int)($_POST['seller_id'] ?? 0);
  $txId     = (int)($_POST['transaction_id'] ?? 0);
  $rating   = (float)($_POST['rating'] ?? 0);
  $desc     = trim((string)($_POST['description'] ?? ''));
  if ($buyerId<=0 || $sellerId<=0 || $txId<=0 || $rating<1 || $rating>5){
    echo json_encode(['ok'=>false,'error'=>'invalid_params']);
    exit;
  }
  $payload = [[ 'buyer_id'=>$buyerId, 'seller_id'=>$sellerId, 'transaction_id'=>$txId, 'rating'=>$rating, 'description'=>$desc ]];
  [$res,$st,$err] = sb_rest('POST','userrating',[], $payload, ['Prefer: return=representation']);
  if (!($st>=200 && $st<300)){
    $detail = is_array($res) && isset($res['message']) ? $res['message'] : (is_string($res)?$res:'');
    echo json_encode(['ok'=>false,'error'=>'insert_failed','code'=>$st,'detail'=>$detail]);
    exit;
  }
  echo json_encode(['ok'=>true]);
  exit;
}

$buyerId  = (int)($_GET['buyer_id'] ?? 0);
$sellerId = (int)($_GET['seller_id'] ?? 0);
$txId     = (int)($_GET['transaction_id'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Completion</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <style>
    body{font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,Noto Sans,sans-serif;margin:0;background:#f8fafc;color:#0f172a}
    .wrap{max-width:900px;margin:0 auto;padding:24px}
    .center{display:flex;align-items:center;justify-content:center}
    .headline{font-size:52px;font-weight:800;color:#16a34a;opacity:0;transform:scale(0.5) translateY(30px);transition:all .8s ease}
    .headline.show{opacity:1;transform:scale(1) translateY(0)}
    .headline.minimize{transform:scale(0.6) translateY(-40px);opacity:1;transition:transform .6s ease}
    .rating-card{margin-top:24px;background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:20px;box-shadow:0 10px 20px rgba(2,6,23,0.05);opacity:0;transform:translateY(20px);transition:all .7s ease}
    .rating-card.show{opacity:1;transform:translateY(0)}
    .stars{display:flex;gap:8px;font-size:32px;cursor:pointer;color:#e2e8f0}
    .stars .active{color:#f59e0b}
    .btn{background:#16a34a;color:#fff;border:none;border-radius:8px;padding:10px 16px;cursor:pointer;font-weight:600}
    .btn:disabled{opacity:.6;cursor:not-allowed}
    .muted{color:#475569;font-size:14px}
    canvas#confetti{position:fixed;inset:0;pointer-events:none;z-index:9999}
  </style>
</head>
<body>
  <canvas id="confetti"></canvas>
  <div class="wrap">
    <div class="center"><div id="headline" class="headline">Congratulations! 🎉</div></div>
    <div id="card" class="rating-card">
      <h2 style="margin:0 0 8px 0;">Rate Your Buyer</h2>
      <div class="muted" style="margin-bottom:10px;">Please rate your experience with this buyer.</div>
      <div class="stars" id="stars" aria-label="Rating">
        <span data-v="1">★</span>
        <span data-v="2">★</span>
        <span data-v="3">★</span>
        <span data-v="4">★</span>
        <span data-v="5">★</span>
      </div>
      <textarea id="desc" placeholder="Optional feedback (max 255 chars)" maxlength="255" style="margin-top:12px;width:100%;min-height:90px;padding:10px;border:1px solid #e2e8f0;border-radius:8px;"></textarea>
      <div style="margin-top:12px;display:flex;gap:8px;align-items:center;">
        <button id="submitBtn" class="btn">Submit Rating</button>
        <span class="muted" id="msg"></span>
      </div>
    </div>
  </div>
  <script>
    (function(){
      var buyerId = <?php echo (int)$buyerId; ?>;
      var sellerId = <?php echo (int)$sellerId; ?>;
      var transactionId = <?php echo (int)$txId; ?>;
      var rating = 5;
      var headline = document.getElementById('headline');
      var card = document.getElementById('card');
      // Confetti setup
      var canvas = document.getElementById('confetti');
      var ctx = canvas.getContext('2d');
      function resize(){ canvas.width = window.innerWidth; canvas.height = window.innerHeight; }
      window.addEventListener('resize', resize); resize();
      var pieces = [];
      var colors = ['#16a34a','#22c55e','#84cc16','#f59e0b','#10b981'];
      for (var i=0;i<200;i++){
        pieces.push({ x: Math.random()*canvas.width, y: Math.random()*-canvas.height, r: 3+Math.random()*4, c: colors[i%colors.length], s: 2+Math.random()*3, a: Math.random()*Math.PI });
      }
      var start = Date.now();
      function tick(){
        ctx.clearRect(0,0,canvas.width,canvas.height);
        var t = (Date.now()-start)/1000;
        for (var i=0;i<pieces.length;i++){
          var p = pieces[i];
          p.y += p.s;
          p.x += Math.sin((p.y+p.a)/20);
          if (p.y>canvas.height) { p.y = -10; p.x = Math.random()*canvas.width; }
          ctx.fillStyle = p.c; ctx.beginPath(); ctx.arc(p.x,p.y,p.r,0,Math.PI*2); ctx.fill();
        }
        requestAnimationFrame(tick);
      }
      tick();
      // Headline show
      setTimeout(function(){ headline.classList.add('show'); }, 50);
      // After 5 seconds, minimize headline and reveal rating card
      setTimeout(function(){ headline.classList.add('minimize'); card.classList.add('show'); }, 5000);

      // Star rating logic
      function renderStars(n){
        var stars = document.querySelectorAll('#stars span');
        stars.forEach(function(s){ s.classList.toggle('active', parseInt(s.getAttribute('data-v'),10) <= n); });
      }
      renderStars(rating);
      document.getElementById('stars').addEventListener('click', function(e){
        var v = parseInt(e.target.getAttribute('data-v'),10);
        if (!isNaN(v)) { rating = v; renderStars(rating); }
      });

      // Submit handler
      var btn = document.getElementById('submitBtn');
      btn.addEventListener('click', function(){
        if (!buyerId || !sellerId || !transactionId){ alert('Missing buyer/seller/transaction info.'); return; }
        if (!(rating>=1 && rating<=5)){ alert('Rating must be 1-5'); return; }
        var fd = new FormData();
        fd.append('action','submit_rating');
        fd.append('buyer_id', buyerId);
        fd.append('seller_id', sellerId);
        fd.append('transaction_id', transactionId);
        fd.append('rating', rating);
        fd.append('description', (document.getElementById('desc')||{}).value||'');
        btn.disabled = true; btn.textContent = 'Submitting...';
        fetch('completion.php', { method:'POST', body: fd, credentials:'same-origin' })
          .then(function(r){ return r.json(); })
          .then(function(res){
            if (!res || res.ok===false){
              alert('Failed to save rating'+(res && res.code? (' (code '+res.code+')') : ''));
              btn.disabled = false; btn.textContent = 'Submit Rating';
            } else {
              window.location.href = '../dashboard.php';
            }
          })
          .catch(function(){ btn.disabled = false; btn.textContent = 'Submit Rating'; });
      });
    })();
  </script>
</body>
</html>
