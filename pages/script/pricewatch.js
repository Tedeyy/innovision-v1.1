(function(){
  function $(id){ return document.getElementById(id); }
  function formatPrice(n){ if(n==null || isNaN(n)) return '—'; try{ return '₱'+Number(n).toLocaleString(undefined,{maximumFractionDigits:2}); }catch(_){ return '₱'+n; } }
  function renderType(data, typeName, priceElId, listElId){
    if (!data || !Array.isArray(data.types)) return;
    var t = data.types.find(function(x){ return (x.type_name||'').toLowerCase()===typeName.toLowerCase(); });
    if (!t) return;
    var priceEl = $(priceElId); if (priceEl) priceEl.textContent = formatPrice(t.recent_price);
    var ul = $(listElId); if (!ul) return; ul.innerHTML='';
    (t.breeds||[]).slice(0,6).forEach(function(b){
      var li = document.createElement('li');
      li.style.display='flex'; li.style.justifyContent='space-between'; li.style.gap='8px';
      var name = document.createElement('span'); name.textContent = b.breed_name;
      var price = document.createElement('span'); price.textContent = formatPrice(b.price); price.style.fontWeight='600';
      li.appendChild(name); li.appendChild(price); ul.appendChild(li);
    });
  }
  fetch('pages/pricewatch.php')
    .then(function(r){ return r.json(); })
    .then(function(data){
      if (!data || data.ok===false) return;
      renderType(data, 'Cattle', 'cattle_price', 'cattle_breeds');
      renderType(data, 'Swine', 'swine_price', 'swine_breeds');
      renderType(data, 'Goat', 'goat_price', 'goat_breeds');
    })
    .catch(function(){});
})();
