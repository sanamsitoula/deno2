<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php'; 

require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';

// Get current active fiscal year from fiscal_years table
$stmt = $conn->prepare("SELECT fiscal_code FROM fiscal_years WHERE is_active = TRUE LIMIT 1");
$stmt->execute();
$active_fiscal_year = $stmt->fetchColumn();

if (!$active_fiscal_year) {
    die("No active fiscal year found in the system");
}

// Date calculations
$today = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$month = date('m');

// Nepali months mapping
$nepali_months = [
    '01' => 'Baisakh', '02' => 'Jestha', '03' => 'Ashad', 
    '04' => 'Shrawan', '05' => 'Bhadra', '06' => 'Ashoj',
    '07' => 'Kartik', '08' => 'Mangsir', '09' => 'Poush',
    '10' => 'Magh', '11' => 'Falgun', '12' => 'Chaitra'
];
$month_name = $nepali_months[$month] ?? 'Invalid';

// Helper function to fetch data
function fetchData($conn, $query) {
    try {
        $stmt = $conn->query($query);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        die("Error in SQL query: " . $e->getMessage());
    }
}

// Today's production
$stmt = $conn->prepare("
    SELECT 
        COALESCE(SUM(total_qty), 0) as total_production,
        COALESCE(SUM(quantity_openpcs), 0) as total_openpcs,
        deno_month
    FROM Deno 
    WHERE deno_date_eng = :today
    GROUP BY deno_month
");
$stmt->execute([':today' => $today]);
$today_data = $stmt->fetch(PDO::FETCH_ASSOC);

// Tomorrow's production
$stmt = $conn->prepare("
    SELECT 
        COALESCE(SUM(total_qty), 0) as total_production,
        COALESCE(SUM(quantity_openpcs), 0) as total_openpcs,
        deno_month
    FROM Deno 
    WHERE deno_date_eng = :tomorrow
    GROUP BY deno_month
");
$stmt->execute([':tomorrow' => $tomorrow]);
$tomorrow_data = $stmt->fetch(PDO::FETCH_ASSOC);

// This month's production
$stmt = $conn->prepare("
    SELECT 
        COALESCE(SUM(total_qty), 0) as total_production,
        COALESCE(SUM(quantity_openpcs), 0) as total_openpcs
    FROM Deno 
    WHERE deno_month = :month AND deno_year = :year
");
$stmt->execute([
    ':month' => $month_name, 
    ':year' => $active_fiscal_year
]);
$month_data = $stmt->fetch(PDO::FETCH_ASSOC);

// Books summary
$stmt = $conn->query("SELECT COUNT(*) as total FROM Books");
$total_books = $stmt->fetchColumn();
$stmt = $conn->query("SELECT COUNT(*) as translated FROM Books WHERE is_translated = TRUE");
$translated = $stmt->fetchColumn();

// Production Statistics
$totalProduced = fetchData($conn, "SELECT SUM(total_qty) as total FROM deno")[0]['total'] ?? 0;
$totalOpenpcs = fetchData($conn, "SELECT SUM(quantity_openpcs) as total FROM deno")[0]['total'] ?? 0;
$netProduction = $totalProduced + $totalOpenpcs;

// Subject-wise production data
$subject_data = fetchData($conn, "
    SELECT b.book_name as subject, SUM(d.total_qty) as total_produced
    FROM deno d
    JOIN books b ON d.book_code = b.book_code
    WHERE b.fiscal_year = '$active_fiscal_year'
    GROUP BY b.book_name
    ORDER BY total_produced DESC
");

// Class-wise book data
$class_data = fetchData($conn, "
    SELECT 
        class_level as class,
        COUNT(*) as total_books,
        SUM(CASE WHEN is_translated = TRUE THEN 1 ELSE 0 END) as translated,
        SUM(CASE WHEN is_translated = FALSE OR is_translated IS NULL THEN 1 ELSE 0 END) as non_translated
    FROM books
    WHERE fiscal_year = '$active_fiscal_year'
    GROUP BY class_level
    ORDER BY class_level
");

// Job ticket vs printed comparison
$job_vs_printed = fetchData($conn, "
    SELECT 
        j.job_ticket_code,
        SUM(j.print_qty) as job_ticket_qty,
        SUM(j.print_done_qty) as printed_qty
    FROM job_ticket j
    JOIN books b ON j.book_id = b.book_id
    WHERE b.fiscal_year = '$active_fiscal_year'
    GROUP BY j.job_ticket_code
    ORDER BY j.job_ticket_code
");

// Daily production data (last 30 days)
$daily_production = fetchData($conn, "
    SELECT 
        deno_date_eng as date,
        SUM(total_qty) as total_production,
        SUM(quantity_openpcs) as total_openpcs
    FROM deno
    WHERE CAST(deno_date_eng AS DATE) >= CURRENT_DATE - INTERVAL '30 days'
    GROUP BY deno_date_eng
    ORDER BY deno_date_eng DESC
");

// Stats for packing section
$stats = [
    'total_records' => 0,
    'total_packed_qty' => 0,
    'active_records' => 0,
    'completed_records' => 0,
    'pending_records' => 0
];
?>


<div class="container my-4">
    <h2 class="mb-4">Production Dashboard</h2>
    
    <div class="status">
        <span>✅ Database Connected</span>
        <span class="badge bg-primary fiscal-year-badge">
            Active Fiscal Year: <?= htmlspecialchars($active_fiscal_year) ?>
        </span>
    </div>

    <!-- Production Stats -->
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card stat-card bg-primary text-white">
                <div class="stat-value"><?= number_format($totalProduced) ?></div>
                <div class="stat-label text-white">Total Produced Quantity</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card stat-card bg-danger text-white">
                <div class="stat-value"><?= number_format($totalOpenpcs) ?></div>
                <div class="stat-label text-white">Total Open Pcs</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card stat-card bg-success text-white">
                <div class="stat-value"><?= number_format($netProduction) ?></div>
                <div class="stat-label text-white">Grand Total Production</div>
            </div>
        </div>
    </div>

    <h1 class="mb-4">
        📦 Book Packing Management
        <small class="text-muted d-block" style="font-size: 16px; font-weight: normal;">Production Management System</small>
    </h1>

    <!-- Success/Error Messages -->
    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="bi bi-check-circle"></i> <?= htmlspecialchars($_SESSION['success_message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['success_message']); ?>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['error_message'])): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($_SESSION['error_message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['error_message']); ?>
    <?php endif; ?>

    <!-- Statistics Cards -->
    <div class="stats-cards">
        <div class="stat-card total">
            <span class="stat-number"><?= number_format($stats['total_records']) ?></span>
            <span class="stat-label">Total Packing Records</span>
        </div>
        <div class="stat-card quantity">
            <span class="stat-number"><?= number_format($stats['total_packed_qty']) ?></span>
            <span class="stat-label">Total Packed Quantity</span>
        </div>
        <div class="stat-card active">
            <span class="stat-number"><?= number_format($stats['active_records']) ?></span>
            <span class="stat-label">Active Records</span>
        </div>
        <div class="stat-card completed">
            <span class="stat-number"><?= number_format($stats['completed_records']) ?></span>
            <span class="stat-label">Completed</span>
        </div>
        <div class="stat-card pending">
            <span class="stat-number"><?= number_format($stats['pending_records']) ?></span>
            <span class="stat-label">Pending</span>
        </div>
    </div>

    <!-- Daily Production Cards -->
    <div class="dashboard-cards">
        <!-- Today's Production -->
        <div class="card">
            <h3><i class="bi bi-calendar-day"></i> Today's Production</h3>
            <div class="production-stats">
                <div class="production-stat total-production">
                    <div class="stat-label">Total Production</div>
                    <div class="stat-value"><?= number_format($today_data['total_production'] ?? 0) ?></div>
                </div>
                <div class="production-stat openpcs">
                    <div class="stat-label">Total Open Pcs</div>
                    <div class="stat-value"><?= number_format($today_data['total_openpcs'] ?? 0) ?></div>
                </div>
            </div>
            <p class="mt-3 mb-2">Month: <?= $today_data['deno_month'] ?? 'N/A' ?></p>
            <a href="reports/daily.php" class="btn btn-sm btn-primary">View Daily Report</a>
        </div>

        <!-- Tomorrow's Production -->
        <div class="card">
            <h3><i class="bi bi-calendar-plus"></i> Tomorrow's Production</h3>
            <div class="production-stats">
                <div class="production-stat total-production">
                    <div class="stat-label">Total Production</div>
                    <div class="stat-value"><?= number_format($tomorrow_data['total_production'] ?? 0) ?></div>
                </div>
                <div class="production-stat openpcs">
                    <div class="stat-label">Total Open Pcs</div>
                    <div class="stat-value"><?= number_format($tomorrow_data['total_openpcs'] ?? 0) ?></div>
                </div>
            </div>
            <p class="mt-3 mb-2">Month: <?= $tomorrow_data['deno_month'] ?? 'N/A' ?></p>
            <a href="reports/daily.php" class="btn btn-sm btn-primary">View Daily Report</a>
        </div>

        <!-- Month's Production -->
        <div class="card">
            <h3><i class="bi bi-calendar-month"></i> This Month's Production</h3>
            <div class="production-stats">
                <div class="production-stat total-production">
                    <div class="stat-label">Total Production</div>
                    <div class="stat-value"><?= number_format($month_data['total_production'] ?? 0) ?></div>
                </div>
                <div class="production-stat openpcs">
                    <div class="stat-label">Total Open Pcs</div>
                    <div class="stat-value"><?= number_format($month_data['total_openpcs'] ?? 0) ?></div>
                </div>
            </div>
            <p class="mt-3 mb-2">Month: <?= $month_name ?></p>
            <a href="reports/monthly.php" class="btn btn-sm btn-primary">View Monthly Report</a>
        </div>

        <!-- Books Summary -->
        <div class="card">
            <h3><i class="bi bi-book"></i> Books Summary</h3>
            <div class="production-stats">
                <div class="production-stat total-production">
                    <div class="stat-label">Total Books</div>
                    <div class="stat-value"><?= number_format($total_books) ?></div>
                </div>
                <div class="production-stat openpcs">
                    <div class="stat-label">Translated Books</div>
                    <div class="stat-value"><?= number_format($translated) ?></div>
                </div>
            </div>
            <p class="mt-3 mb-2">Non-Translated: <?= number_format($total_books - $translated) ?></p>
            <a href="reports/books.php" class="btn btn-sm btn-primary">View Books Report</a>
        </div>
    </div>

    <!-- Charts Section -->
    <div class="row mt-4">
        <!-- Subject-wise Production Chart -->
        <div class="col-md-6 mb-4">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h4 class="mb-0"><i class="bi bi-bar-chart"></i> Subject-wise Production (FY: <?= htmlspecialchars($active_fiscal_year) ?>)</h4>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="subjectChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Class-wise Book Distribution -->
        <div class="col-md-6 mb-4">
            <div class="card">
                <div class="card-header bg-success text-white">
                    <h4 class="mb-0"><i class="bi bi-pie-chart"></i> Class-wise Book Distribution (FY: <?= htmlspecialchars($active_fiscal_year) ?>)</h4>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="classChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Second Row of Charts -->
    <div class="row">
        <!-- Job Ticket vs Printed -->
        <div class="col-md-6 mb-4">
            <div class="card">
                <div class="card-header bg-info text-white">
                    <h4 class="mb-0"><i class="bi bi-clipboard2-pulse"></i> Job Ticket vs Printed (FY: <?= htmlspecialchars($active_fiscal_year) ?>)</h4>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="jobPrintedChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Daily Production Trend -->
        <div class="col-md-6 mb-4">
            <div class="card">
                <div class="card-header bg-warning text-dark">
                    <h4 class="mb-0"><i class="bi bi-graph-up"></i> Daily Production Trend (Last 30 Days)</h4>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="dailyTrendChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>