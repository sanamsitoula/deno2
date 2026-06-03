<?php

// Include config for base URL
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';

require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

// Function to fetch data from database using PDO
function fetchData($conn, $query) {
    try {
        $stmt = $conn->query($query);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        die("Error in SQL query: " . $e->getMessage());
    }
}

// Fetch all required data using PDO
$fiscalYears = fetchData($conn, "SELECT * FROM fiscal_years ORDER BY start_date DESC");
$books = fetchData($conn, "SELECT * FROM books ORDER BY fiscal_year, class_level");
$denoMonthly = fetchData($conn, "SELECT deno_month, deno_year, SUM(total_qty) as total FROM deno GROUP BY deno_month, deno_year ORDER BY deno_year, deno_month");
$jobTickets = fetchData($conn, "SELECT j.*, b.book_name FROM job_ticket j LEFT JOIN books b ON j.book_id = b.book_id ORDER BY j.created_date DESC LIMIT 10");

// Production Statistics
$totalProduced = fetchData($conn, "SELECT SUM(total_qty) as total FROM deno")[0]['total'];
$totalOpenpcs = fetchData($conn, "SELECT SUM(quantity_openpcs) as total FROM deno")[0]['total'];
$netProduction = $totalProduced - $totalOpenpcs;

// Job Ticket Quantity Subject Wise
$jobTicketSubjectWise = fetchData($conn, "
    SELECT b.book_name as subject, SUM(j.print_qty) as total 
    FROM job_ticket j 
    JOIN books b ON j.book_id = b.book_id 
    GROUP BY b.book_name 
    ORDER BY total DESC
");
// Job Ticket Print Quantity
$jobTicketPrintQty = fetchData($conn, "
    SELECT job_ticket_code, print_qty, print_done_qty, 
           (print_qty - print_done_qty) as remaining 
    FROM job_ticket 
    ORDER BY created_date DESC 
    LIMIT 10
");

// Book-wise Forma Count and Pages
$bookFormaPages = fetchData($conn, "
    SELECT b.book_name, COUNT(f.id) as forma_count, SUM(f.page) as total_pages
    FROM books b
    LEFT JOIN forma f ON b.book_id = f.book_id
    GROUP BY b.book_name
    ORDER BY b.book_name
");

// Class-wise Book Report based on Fiscal Year
$classFiscalReport = fetchData($conn, "
    SELECT 
        b.class_level, 
        b.fiscal_year, 
        COUNT(b.book_id) as book_count,
        SUM(CASE WHEN b.is_translated = true THEN 1 ELSE 0 END) as translated_count,
        SUM(CASE WHEN b.is_translated = false OR b.is_translated IS NULL THEN 1 ELSE 0 END) as non_translated_count
    FROM books b
    GROUP BY b.class_level, b.fiscal_year
    ORDER BY b.fiscal_year DESC, b.class_level
");

// Translated vs Non-translated Books
$translationStats = fetchData($conn, "
    SELECT 
        class_level,
        SUM(CASE WHEN is_translated = true THEN 1 ELSE 0 END) as translated,
        SUM(CASE WHEN is_translated = false OR is_translated IS NULL THEN 1 ELSE 0 END) as non_translated
    FROM books
    GROUP BY class_level
    ORDER BY class_level
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enhanced Book Management Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/chart.js@3.7.1/dist/chart.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        .card {
            margin-bottom: 20px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            transition: transform 0.3s;
        }
        .card:hover {
            transform: translateY(-5px);
        }
        .stat-card {
            text-align: center;
            padding: 20px;
            border-radius: 8px;
        }
        .stat-value {
            font-size: 2.5rem;
            font-weight: bold;
        }
        .stat-label {
            color: #6c757d;
            font-size: 1rem;
        }
        .sidebar {
            background-color: #343a40;
            color: white;
            min-height: 100vh;
        }
        .sidebar .nav-link {
            color: rgba(255,255,255,.75);
        }
        .sidebar .nav-link:hover {
            color: white;
        }
        .sidebar .nav-link.active {
            color: white;
            background-color: rgba(255,255,255,.1);
        }
        .dashboard-header {
            background-color: #f8f9fa;
            padding: 20px;
            margin-bottom: 30px;
            border-radius: 5px;
        }
        .progress {
            height: 25px;
        }
        .chart-container {
            position: relative;
            height: 300px;
            margin-bottom: 20px;
        }
        .table-responsive {
            overflow-x: auto;
        }
        .badge-lg {
            font-size: 0.9rem;
            padding: 0.5em 0.75em;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-2 sidebar p-0">
                <div class="p-3">
                    <h4 class="text-center">Book Management</h4>
                    <hr class="bg-light">
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link active" href="#production-stats"><i class="bi bi-speedometer2"></i> Production Stats</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="#job-tickets"><i class="bi bi-ticket-detailed"></i> Job Tickets</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="#book-reports"><i class="bi bi-book"></i> Book Reports</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="#fiscal-years"><i class="bi bi-calendar-range"></i> Fiscal Years</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="#translation-stats"><i class="bi bi-translate"></i> Translation Stats</a>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Main Content -->
            <div class="col-md-10 p-4">
                <div class="dashboard-header">
                    <h1><i class="bi bi-journal-bookmark"></i> Enhanced Book Management Dashboard</h1>
                    <p class="lead">Comprehensive production tracking and reporting system</p>
                </div>

                <!-- Production Statistics -->
                <div class="row" id="production-stats">
                    <div class="col-md-4">
                        <div class="card stat-card bg-primary text-white">
                            <div class="stat-value"><?php echo number_format($totalProduced); ?></div>
                            <div class="stat-label">Total Produced Quantity</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card stat-card bg-danger text-white">
                            <div class="stat-value"><?php echo number_format($totalOpenpcs); ?></div>
                            <div class="stat-label">Total Open Pcs</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card stat-card bg-success text-white">
                            <div class="stat-value"><?php echo number_format($netProduction); ?></div>
                            <div class="stat-label">Net Production</div>
                        </div>
                    </div>
                </div>

                <!-- Job Tickets Section -->
                <div class="row">
                    <div class="col-md-6">
                        <div class="card" id="job-tickets">
                            <div class="card-header bg-dark text-white">
                                <h3><i class="bi bi-ticket-detailed"></i> Job Ticket Quantity (Subject Wise)</h3>
                            </div>
                            <div class="card-body">
                                <div class="chart-container">
                                    <canvas id="jobTicketSubjectChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header bg-dark text-white">
                                <h3><i class="bi bi-printer"></i> Recent Job Ticket Print Status</h3>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-striped">
                                        <thead>
                                            <tr>
                                                <th>Ticket Code</th>
                                                <th>Total Qty</th>
                                                <th>Done</th>
                                                <th>Remaining</th>
                                                <th>Progress</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($jobTicketPrintQty as $ticket): 
                                                $progress = ($ticket['print_done_qty'] / $ticket['print_qty']) * 100;
                                            ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($ticket['job_ticket_code']); ?></td>
                                                <td><?php echo number_format($ticket['print_qty']); ?></td>
                                                <td><?php echo number_format($ticket['print_done_qty']); ?></td>
                                                <td><?php echo number_format($ticket['remaining']); ?></td>
                                                <td>
                                                    <div class="progress">
                                                        <div class="progress-bar <?php echo $progress == 100 ? 'bg-success' : 'bg-info'; ?>" 
                                                             role="progressbar" 
                                                             style="width: <?php echo $progress; ?>%" 
                                                             aria-valuenow="<?php echo $progress; ?>" 
                                                             aria-valuemin="0" 
                                                             aria-valuemax="100">
                                                            <?php echo round($progress); ?>%
                                                        </div>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Book Reports Section -->
                <div class="row" id="book-reports">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header bg-dark text-white">
                                <h3><i class="bi bi-file-earmark-text"></i> Book-wise Forma & Pages</h3>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-striped">
                                        <thead>
                                            <tr>
                                                <th>Book Name</th>
                                                <th>Forma Count</th>
                                                <th>Total Pages</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($bookFormaPages as $book): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($book['book_name']); ?></td>
                                                <td><?php echo $book['forma_count']; ?></td>
                                                <td><?php echo $book['total_pages']; ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header bg-dark text-white">
                                <h3><i class="bi bi-journals"></i> Class-wise Book Report</h3>
                            </div>
                            <div class="card-body">
                                <div class="chart-container">
                                    <canvas id="classFiscalChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Fiscal Year Section -->
                <div class="card" id="fiscal-years">
                    <div class="card-header bg-dark text-white">
                        <h3><i class="bi bi-calendar-range"></i> Fiscal Year Overview</h3>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Fiscal Code</th>
                                        <th>Period</th>
                                        <th>Status</th>
                                        <th>Books Count</th>
                                        <th>Translated</th>
                                        <th>Non-Translated</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($classFiscalReport as $report): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($report['fiscal_year']); ?></td>
                                        <td>Class <?php echo htmlspecialchars($report['class_level']); ?></td>
                                        <td>
                                            <span class="badge <?php echo $report['book_count'] > 0 ? 'bg-success' : 'bg-secondary'; ?>">
                                                <?php echo $report['book_count'] > 0 ? 'Active' : 'Inactive'; ?>
                                            </span>
                                        </td>
                                        <td><?php echo $report['book_count']; ?></td>
                                        <td><?php echo $report['translated_count']; ?></td>
                                        <td><?php echo $report['non_translated_count']; ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Translation Statistics -->
                <div class="card" id="translation-stats">
                    <div class="card-header bg-dark text-white">
                        <h3><i class="bi bi-translate"></i> Translation Statistics (Class-wise)</h3>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="translationChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.7.1/dist/chart.min.js"></script>
    <script>
        // Job Ticket Subject Wise Chart
        const jobTicketSubjects = <?php echo json_encode(array_column($jobTicketSubjectWise, 'subject')); ?>;
        const jobTicketQuantities = <?php echo json_encode(array_column($jobTicketSubjectWise, 'total')); ?>;
        
        const jobTicketSubjectCtx = document.getElementById('jobTicketSubjectChart').getContext('2d');
        new Chart(jobTicketSubjectCtx, {
            type: 'bar',
            data: {
                labels: jobTicketSubjects,
                datasets: [{
                    label: 'Print Quantity',
                    data: jobTicketQuantities,
                    backgroundColor: 'rgba(54, 162, 235, 0.7)',
                    borderColor: 'rgba(54, 162, 235, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true
                    }
                },
                plugins: {
                    legend: {
                        display: false
                    }
                }
            }
        });

        // Class-wise Fiscal Year Chart
        const classFiscalData = <?php echo json_encode($classFiscalReport); ?>;
        const classLabels = [...new Set(classFiscalData.map(item => 'Class ' + item.class_level))];
        
        // Group by fiscal year
        const fiscalYears = [...new Set(classFiscalData.map(item => item.fiscal_year))];
        const datasets = fiscalYears.map(year => {
            return {
                label: year,
                data: classLabels.map(cls => {
                    const item = classFiscalData.find(d => 
                        d.fiscal_year === year && 
                        'Class ' + d.class_level === cls
                    );
                    return item ? item.book_count : 0;
                }),
                backgroundColor: `rgba(${Math.floor(Math.random() * 255)}, ${Math.floor(Math.random() * 255)}, ${Math.floor(Math.random() * 255)}, 0.7)`
            };
        });

        const classFiscalCtx = document.getElementById('classFiscalChart').getContext('2d');
        new Chart(classFiscalCtx, {
            type: 'bar',
            data: {
                labels: classLabels,
                datasets: datasets
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        stacked: true,
                    },
                    y: {
                        stacked: true,
                        beginAtZero: true
                    }
                }
            }
        });

        // Translation Statistics Chart
        const translationData = <?php echo json_encode($translationStats); ?>;
        const translationLabels = translationData.map(item => 'Class ' + item.class_level);
        const translatedCounts = translationData.map(item => item.translated);
        const nonTranslatedCounts = translationData.map(item => item.non_translated);

        const translationCtx = document.getElementById('translationChart').getContext('2d');
        new Chart(translationCtx, {
            type: 'bar',
            data: {
                labels: translationLabels,
                datasets: [
                    {
                        label: 'Translated',
                        data: translatedCounts,
                        backgroundColor: 'rgba(75, 192, 192, 0.7)',
                        borderColor: 'rgba(75, 192, 192, 1)',
                        borderWidth: 1
                    },
                    {
                        label: 'Non-Translated',
                        data: nonTranslatedCounts,
                        backgroundColor: 'rgba(255, 99, 132, 0.7)',
                        borderColor: 'rgba(255, 99, 132, 1)',
                        borderWidth: 1
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });
    </script>
</body>
</html>