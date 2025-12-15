document.addEventListener('DOMContentLoaded', function(){
  var el = document.getElementById('adminSalesChart');
  var dataEl = document.getElementById('admin-sales-data');
  if (!el || !dataEl || !window.Chart) return;
  try {
    var labels = JSON.parse(dataEl.getAttribute('data-labels') || '[]');
    var datasets = JSON.parse(dataEl.getAttribute('data-datasets') || '[]');
    new Chart(el, {
      type: 'line',
      data: { labels: labels, datasets: datasets },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        animation: false,
        plugins: { legend: { position: 'bottom' } },
        scales: { y: { beginAtZero: true, suggestedMin: 0 } }
      }
    });
  } catch(e) {
    // no-op
  }
});
