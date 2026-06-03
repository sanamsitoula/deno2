<footer class="bg-light text-center mt-5 p-3">
    <p class="mb-1">&copy; 2025 Janak Production Management System</p>
    <small class="text-muted">Developed & Maintained by IT Section, JEMC</small>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@3.7.1/dist/chart.min.js"></script>

<script>
// Chart.js Global Configuration
Chart.defaults.font.family = "'Segoe UI', Roboto, Arial, sans-serif";
Chart.defaults.font.size = 12;
Chart.defaults.plugins.legend.display = true;
Chart.defaults.plugins.legend.position = 'bottom';

// Subject-wise Production Chart
const subjectChartEl = document.getElementById('subjectChart');
if (subjectChartEl) {
    new Chart(subjectChartEl, {
        type: 'bar',
        data: {
            labels: <?= json_encode(array_column($subject_data, 'subject')) ?>,
            datasets: [{
                label: 'Total Produced',
                data: <?= json_encode(array_column($subject_data, 'total_produced')) ?>,
                backgroundColor: 'rgba(13, 110, 253, 0.7)',
                borderColor: 'rgba(13, 110, 253, 1)',
                borderWidth: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return value.toLocaleString();
                        }
                    }
                }
            },
            plugins: {
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return 'Produced: ' + context.parsed.y.toLocaleString();
                        }
                    }
                }
            }
        }
    });
}

// Class-wise Book Distribution Chart
const classChartEl = document.getElementById('classChart');
if (classChartEl) {
    new Chart(classChartEl, {
        type: 'pie',
        data: {
            labels: <?= json_encode(array_map(fn($c) => 'Class ' . $c['class'], $class_data)) ?>,
            datasets: [{
                label: 'Total Books',
                data: <?= json_encode(array_column($class_data, 'total_books')) ?>,
                backgroundColor: [
                    'rgba(13, 110, 253, 0.7)',
                    'rgba(25, 135, 84, 0.7)',
                    'rgba(255, 193, 7, 0.7)',
                    'rgba(220, 53, 69, 0.7)',
                    'rgba(13, 202, 240, 0.7)',
                    'rgba(108, 117, 125, 0.7)',
                    'rgba(111, 66, 193, 0.7)',
                    'rgba(214, 51, 132, 0.7)'
                ],
                borderColor: [
                    'rgba(13, 110, 253, 1)',
                    'rgba(25, 135, 84, 1)',
                    'rgba(255, 193, 7, 1)',
                    'rgba(220, 53, 69, 1)',
                    'rgba(13, 202, 240, 1)',
                    'rgba(108, 117, 125, 1)',
                    'rgba(111, 66, 193, 1)',
                    'rgba(214, 51, 132, 1)'
                ],
                borderWidth: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return context.label + ': ' + context.parsed.toLocaleString() + ' books';
                        }
                    }
                }
            }
        }
    });
}

// Job Ticket vs Printed Chart
const jobPrintedChartEl = document.getElementById('jobPrintedChart');
if (jobPrintedChartEl) {
    new Chart(jobPrintedChartEl, {
        type: 'bar',
        data: {
            labels: <?= json_encode(array_column($job_vs_printed, 'job_ticket_code')) ?>,
            datasets: [
                {
                    label: 'Job Ticket Qty',
                    data: <?= json_encode(array_column($job_vs_printed, 'job_ticket_qty')) ?>,
                    backgroundColor: 'rgba(13, 202, 240, 0.7)',
                    borderColor: 'rgba(13, 202, 240, 1)',
                    borderWidth: 2
                },
                {
                    label: 'Printed Qty',
                    data: <?= json_encode(array_column($job_vs_printed, 'printed_qty')) ?>,
                    backgroundColor: 'rgba(25, 135, 84, 0.7)',
                    borderColor: 'rgba(25, 135, 84, 1)',
                    borderWidth: 2
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return value.toLocaleString();
                        }
                    }
                }
            },
            plugins: {
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return context.dataset.label + ': ' + context.parsed.y.toLocaleString();
                        }
                    }
                }
            }
        }
    });
}

// Daily Production Trend Chart
const dailyTrendChartEl = document.getElementById('dailyTrendChart');
if (dailyTrendChartEl) {
    new Chart(dailyTrendChartEl, {
        type: 'line',
        data: {
            labels: <?= json_encode(array_column($daily_production, 'date')) ?>,
            datasets: [
                {
                    label: 'Production',
                    data: <?= json_encode(array_column($daily_production, 'total_production')) ?>,
                    backgroundColor: 'rgba(13, 110, 253, 0.2)',
                    borderColor: 'rgba(13, 110, 253, 1)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.4
                },
                {
                    label: 'Open Pcs',
                    data: <?= json_encode(array_column($daily_production, 'total_openpcs')) ?>,
                    backgroundColor: 'rgba(255, 193, 7, 0.2)',
                    borderColor: 'rgba(255, 193, 7, 1)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.4
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return value.toLocaleString();
                        }
                    }
                },
                x: {
                    ticks: {
                        maxRotation: 45,
                        minRotation: 45
                    }
                }
            },
            plugins: {
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return context.dataset.label + ': ' + context.parsed.y.toLocaleString();
                        }
                    }
                }
            }
        }
    });
}
</script>

</body>
</html>