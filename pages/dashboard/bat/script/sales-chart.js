// Sales chart functionality
document.addEventListener('DOMContentLoaded', function() {
    const chartEl = document.getElementById('batSalesChart');
    const dataEl = document.getElementById('bat-sales-data');
    const livestockBtn = document.getElementById('btnLivestockType');
    const breedBtn = document.getElementById('btnBreed');
    
    if (!chartEl || !dataEl || !window.Chart) return;
    
    try {
        const labels = JSON.parse(dataEl.getAttribute('data-labels') || '[]');
        const datasets = JSON.parse(dataEl.getAttribute('data-datasets') || '[]');
        const breedLabels = JSON.parse(dataEl.getAttribute('data-breed-labels') || '[]');
        const breedData = JSON.parse(dataEl.getAttribute('data-breed-data') || '[]');
        
        let currentChart = null;
        let currentView = 'livestock'; // 'livestock' or 'breed'
        
        function createLivestockChart() {
            if (currentChart) currentChart.destroy();
            
            currentChart = new Chart(chartEl, {
                type: 'line',
                data: { labels: labels, datasets: datasets },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: false,
                    plugins: { 
                        legend: { position: 'bottom' },
                        title: { display: true, text: 'Sales by Livestock Type (Last 4 Months)' }
                    },
                    scales: { 
                        y: { beginAtZero: true, title: { display: true, text: 'Number of Sales' } },
                        x: { title: { display: true, text: 'Month' } }
                    }
                }
            });
        }
        
        function createBreedChart() {
            if (currentChart) currentChart.destroy();
            
            currentChart = new Chart(chartEl, {
                type: 'bar',
                data: {
                    labels: breedLabels,
                    datasets: [{
                        label: 'Sales by Breed',
                        data: breedData,
                        backgroundColor: 'rgba(75, 192, 192, 0.6)',
                        borderColor: 'rgba(75, 192, 192, 1)',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: false,
                    plugins: { 
                        legend: { display: false },
                        title: { display: true, text: 'Sales by Breed (All Time)' }
                    },
                    scales: { 
                        y: { beginAtZero: true, title: { display: true, text: 'Number of Sales' } },
                        x: { 
                            title: { display: true, text: 'Breed' },
                            ticks: { autoSkip: false, maxRotation: 45, minRotation: 45 }
                        }
                    }
                }
            });
        }
        
        // Initial chart
        createLivestockChart();
        
        // Button event listeners
        if (livestockBtn && breedBtn) {
            livestockBtn.addEventListener('click', function() {
                if (currentView !== 'livestock') {
                    currentView = 'livestock';
                    createLivestockChart();
                    livestockBtn.classList.add('active');
                    breedBtn.classList.remove('active');
                }
            });
            
            breedBtn.addEventListener('click', function() {
                if (currentView !== 'breed') {
                    currentView = 'breed';
                    createBreedChart();
                    breedBtn.classList.add('active');
                    livestockBtn.classList.remove('active');
                }
            });
        }
        
    } catch (error) {
        console.error('Error initializing sales chart:', error);
    }
});
