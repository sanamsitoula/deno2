<?php
/**
 * Calendar Based Production Report System
 * FIXED: Accurate Nepali Date Conversion using Sajjan Maharjan Library
 */

ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

// ─── Permissions ─────────────────────────────────────────────────────────
if (!has_role('viewer') && !has_role('operator') && !has_role('incharge') && !has_role('supervisor') && !has_role('admin')) {
    ob_end_clean();
    header('Location: /deno2/unauthorized.php');
    exit();
}

// ─── Filter Parameters ───────────────────────────────────────────────────
// Defaults to the active fiscal year on first load; isset() so an explicit
// "All Fiscal Years" choice (empty string) sticks.
$active_fy_for_filter = getActiveFiscalYear($conn);
$fiscal_year_filter = isset($_GET['fiscal_year']) ? $_GET['fiscal_year'] : ($active_fy_for_filter['id'] ?? '');
$book_code_filter   = $_GET['book_code'] ?? '';
$status_filter      = $_GET['status'] ?? '';
$view_month         = $_GET['month'] ?? date('Y-m');

// ─── AJAX: Load Calendar Data ────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'load_calendar') {
    header('Content-Type: application/json');
    
    $month = $_GET['month'] ?? date('Y-m');
    $year = substr($month, 0, 4);
    $mon = str_pad(substr($month, 5, 2), 2, '0', STR_PAD_LEFT);
    $first_day = "{$year}-{$mon}-01";
    $last_day = date('Y-m-t', strtotime($first_day));
    
    // Build WHERE clause
    $where = ["fp.status = true", "DATE(fp.date_eng) BETWEEN :start AND :end"];
    $params = [':start' => $first_day, ':end' => $last_day];
    
    if ($fiscal_year_filter) {
        $where[] = "jt.fiscal_year_id = :fy";
        $params[':fy'] = (int)$fiscal_year_filter;
    }
    if ($book_code_filter) {
        $where[] = "b.book_code LIKE :bc";
        $params[':bc'] = "%{$book_code_filter}%";
    }
    if ($status_filter) {
        $where[] = "jt.status = :st";
        $params[':st'] = $status_filter;
    }
    
    $where_sql = implode(' AND ', $where);
    
    // Aggregation query by production date (English date stored in DB)
    $sql = "
        SELECT 
            DATE(fp.date_eng) as prod_date,
            COALESCE(SUM(fp.fp_printqty), 0)::bigint as fp_total,
            COALESCE(SUM(bp.p_qty), 0)::bigint as bp_total,
            COALESCE(SUM(d.total_qty), 0)::bigint as deno_total,
            COALESCE(SUM(d2i.total_qty), 0)::bigint as d2m_total,
            COUNT(DISTINCT jt.id)::integer as jt_count
        FROM forma_printing fp
        LEFT JOIN job_ticket jt ON jt.id = fp.jt_id
        LEFT JOIN books b ON b.book_id = jt.book_id
        LEFT JOIN book_packing bp ON bp.jt_id = jt.id AND bp.status = true
        LEFT JOIN deno d ON d.jt_id = jt.id AND d.deleted_at IS NULL
        LEFT JOIN d2m_items d2i ON d2i.book_code = b.book_code
        LEFT JOIN d2m d2 ON d2.id = d2i.d2m_id AND d2.deleted_at IS NULL
        WHERE {$where_sql}
        GROUP BY DATE(fp.date_eng)
        ORDER BY prod_date
    ";
    
    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Return ONLY English dates - JS will convert to BS accurately
        $data = [];
        foreach ($rows as $r) {
            $ad = $r['prod_date'];
            $data[$ad] = [
                'ad' => $ad,  // English date (YYYY-MM-DD)
                'fp' => (int)$r['fp_total'],
                'bp' => (int)$r['bp_total'],
                'deno' => (int)$r['deno_total'],
                'd2m' => (int)$r['d2m_total'],
                'jt' => (int)$r['jt_count'],
                'is_holiday' => (date('N', strtotime($ad)) >= 6)
            ];
        }
        
        echo json_encode(['success' => true, 'data' => $data, 'month' => $month]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// ─── AJAX: Load Day Details ──────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'load_day_details') {
    header('Content-Type: application/json');
    $date = $_GET['date'] ?? date('Y-m-d');
    
    $sql = "
        SELECT 
            jt.job_ticket_code, b.book_code, b.book_name, b.class_level,
            fp.fp_printqty, bp.p_qty as packing_qty,
            d.total_qty as deno_qty, d2i.total_qty as d2m_qty,
            u_op.username as operator, m.machine_name, s.name as shift,
            fp.created_date as entry_time, jt.status
        FROM forma_printing fp
        JOIN job_ticket jt ON jt.id = fp.jt_id
        JOIN books b ON b.book_id = jt.book_id
        LEFT JOIN users u_op ON u_op.id = fp.operator_id
        LEFT JOIN machines m ON m.id = fp.machine_id
        LEFT JOIN shifts s ON s.id = fp.shift_id
        LEFT JOIN book_packing bp ON bp.jt_id = jt.id AND bp.status = true
        LEFT JOIN deno d ON d.jt_id = jt.id AND d.deleted_at IS NULL
        LEFT JOIN d2m_items d2i ON d2i.book_code = b.book_code
        LEFT JOIN d2m d2 ON d2.id = d2i.d2m_id AND d2.deleted_at IS NULL
        WHERE DATE(fp.date_eng) = :date AND fp.status = true
        ORDER BY fp.created_date DESC
    ";
    
    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute([':date' => $date]);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $totals = [
            'fp' => array_sum(array_column($records, 'fp_printqty')),
            'bp' => array_sum(array_column($records, 'packing_qty')),
            'deno' => array_sum(array_column($records, 'deno_qty')),
            'd2m' => array_sum(array_column($records, 'd2m_qty')),
        ];
        
        // Return AD date only - JS converts to BS
        echo json_encode([
            'success' => true,
            'date_ad' => $date,  // English date
            'records' => $records,
            'totals' => $totals
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// ─── AJAX: Export Handler ────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'export') {
    $format = $_GET['format'] ?? 'excel';
    $date = $_GET['date'] ?? '';
    
    if ($format === 'excel') {
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="production_calendar_' . ($date ?: $view_month) . '.xls"');
        echo "Date(AD)\tDate(BS)\tJobTicket\tBook\tFP_Qty\tBP_Qty\tDeno_Qty\tD2M_Qty\n";
        
        $sql = "SELECT fp.date_eng, jt.job_ticket_code, b.book_code, b.book_name,
                       fp.fp_printqty, bp.p_qty, d.total_qty as deno_qty, d2i.total_qty as d2m_qty
                FROM forma_printing fp
                JOIN job_ticket jt ON jt.id = fp.jt_id
                JOIN books b ON b.book_id = jt.book_id
                LEFT JOIN book_packing bp ON bp.jt_id = jt.id AND bp.status = true
                LEFT JOIN deno d ON d.jt_id = jt.id AND d.deleted_at IS NULL
                LEFT JOIN d2m_items d2i ON d2i.book_code = b.book_code
                WHERE fp.status = true " . ($date ? "AND DATE(fp.date_eng) = :date" : "") . "
                ORDER BY fp.created_date DESC";
        
        $stmt = $conn->prepare($sql);
        if ($date) $stmt->execute([':date' => $date]);
        else $stmt->execute();
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            // BS date will be added client-side or via server library
            echo implode("\t", [
                $row['date_eng'] ?? '',
                '',  // BS date filled by JS or accurate PHP lib
                $row['job_ticket_code'] ?? '',
                $row['book_code'] ?? '',
                $row['fp_printqty'] ?? 0,
                $row['p_qty'] ?? 0,
                $row['deno_qty'] ?? 0,
                $row['d2m_qty'] ?? 0
            ]) . "\n";
        }
        exit();
    }
}

// ─── MAIN PAGE: Dropdown Data ────────────────────────────────────────────
$fiscal_years = $conn->query("SELECT id, fiscal_code, fiscal_name FROM fiscal_years ORDER BY fiscal_code DESC")->fetchAll(PDO::FETCH_ASSOC);
$books = $conn->query("SELECT DISTINCT book_code, book_name FROM books ORDER BY book_code")->fetchAll(PDO::FETCH_ASSOC);

// Calendar navigation
$nav_dt = new DateTime($view_month . '-01');
$prev_month = (clone $nav_dt)->modify('-1 month')->format('Y-m');
$next_month = (clone $nav_dt)->modify('+1 month')->format('Y-m');
$month_label = $nav_dt->format('F Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🗓️ Production Calendar Report</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FullCalendar CSS -->
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.css" rel="stylesheet">
    <!-- Nepali Datepicker CSS & JS (for accurate conversion) -->
    <link href="https://nepalidatepicker.sajanmaharjan.com.np/v5/nepali.datepicker/css/nepali.datepicker.v5.0.6.min.css" rel="stylesheet"/>
    
    <style>
        :root {
            --fp-blue: #3b82f6; --bp-green: #22c55e; --deno-orange: #f97316;
            --d2m-purple: #a855f7; --holiday-red: #ef4444; --today-yellow: #fbbf24;
            --bg-light: #ffffff; --surface: #f8fafc; --border: #e2e8f0; 
            --text: #1e293b; --text-muted: #64748b;
        }
        body { background: var(--bg-light); color: var(--text); font-family: 'Segoe UI', system-ui, sans-serif; }
        
        .calendar-wrapper { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 15px; margin-bottom: 20px; }
        .date-dual { font-size: 11px; line-height: 1.2; margin-bottom: 4px; }
        .date-bs { color: #d97706; font-weight: 700; }  /* Amber for BS */
        .date-ad { color: var(--text-muted); font-weight: 500; }
        
        .fc { background: transparent; }
        .fc-theme-standard td, .fc-theme-standard th { border-color: var(--border) !important; }
        .fc-daygrid-day { min-height: 115px !important; cursor: pointer; }
        .fc-daygrid-day:hover { background: rgba(59,130,246,0.04); }
        .fc-daygrid-day-number { font-size: 12px !important; color: var(--text-muted) !important; }
        .fc-day-today { background: rgba(251,191,36,0.12) !important; border: 2px solid var(--today-yellow) !important; }
        .fc-day-sat, .fc-day-sun { background: rgba(239,68,68,0.04) !important; }
        
        .metric-badge { display: inline-block; padding: 2px 6px; border-radius: 4px; font-size: 10px; font-weight: 700; margin: 1px 0; }
        .m-fp { background: rgba(59,130,246,0.12); color: var(--fp-blue); }
        .m-bp { background: rgba(34,197,94,0.12); color: var(--bp-green); }
        .m-deno { background: rgba(249,115,22,0.12); color: var(--deno-orange); }
        .m-d2m { background: rgba(168,85,247,0.12); color: var(--d2m-purple); }
        .m-jt { background: rgba(100,116,139,0.12); color: var(--text-muted); }
        
        .modal-content { background: var(--bg-light); border: 1px solid var(--border); color: var(--text); border-radius: 12px; }
        .filter-panel { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 16px; margin-bottom: 20px; }
        .form-select, .form-control { background: #fff; border-color: var(--border); color: var(--text); }
        .loading-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(255,255,255,0.92); display: flex; align-items: center; justify-content: center; z-index: 9999; display: none; }
        .spinner { width: 40px; height: 40px; border: 4px solid var(--border); border-top-color: var(--fp-blue); border-radius: 50%; animation: spin 1s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>
<div class="container-fluid py-3">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="mb-0 fw-bold">🏭 Production Calendar Report</h4>
            <small class="text-muted" id="headerMonthLabel"><?= $month_label ?></small>
        </div>
        <div class="btn-group">
            <button class="btn btn-outline-secondary btn-sm" onclick="window.print()">🖨️ Print</button>
            <button class="btn btn-success btn-sm" onclick="exportData('excel', '')">📥 Excel</button>
        </div>
    </div>
    
    <!-- Filters -->
    <div class="filter-panel">
        <form id="filterForm" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small text-muted fw-semibold">Fiscal Year</label>
                <select name="fiscal_year" class="form-select form-select-sm">
                    <option value="">All</option>
                    <?php foreach ($fiscal_years as $fy): ?>
                        <option value="<?= $fy['id'] ?>" <?= $fiscal_year_filter == $fy['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($fy['fiscal_code']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small text-muted fw-semibold">Book Code</label>
                <select name="book_code" class="form-select form-select-sm">
                    <option value="">All Books</option>
                    <?php foreach ($books as $bk): ?>
                        <option value="<?= htmlspecialchars($bk['book_code']) ?>" <?= $book_code_filter == $bk['book_code'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($bk['book_code']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small text-muted fw-semibold">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All</option>
                    <option value="pending" <?= $status_filter == 'pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="processing" <?= $status_filter == 'processing' ? 'selected' : '' ?>>Processing</option>
                    <option value="completed" <?= $status_filter == 'completed' ? 'selected' : '' ?>>Completed</option>
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm flex-grow-1">🔍 Apply</button>
                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="resetFilters()">↺</button>
            </div>
        </form>
    </div>
    
    <!-- Calendar Navigation -->
    <div class="calendar-wrapper d-flex justify-content-between align-items-center">
        <div class="btn-group">
            <a href="?month=<?= $prev_month ?>&<?= http_build_query($_GET) ?>" class="btn btn-outline-secondary btn-sm">◀ Prev</a>
            <a href="?month=<?= date('Y-m') ?>" class="btn btn-outline-secondary btn-sm">Today</a>
            <a href="?month=<?= $next_month ?>&<?= http_build_query($_GET) ?>" class="btn btn-outline-secondary btn-sm">Next ▶</a>
        </div>
        <div class="text-center">
            <strong class="fs-5" id="navMonthLabel"><?= $month_label ?></strong><br>
            <small class="text-warning fw-semibold" id="navBsLabel">Loading BS...</small>
        </div>
        <div>
            <input type="month" class="form-control form-control-sm" style="width:auto" value="<?= $view_month ?>" onchange="location='?month='+this.value+'&<?= http_build_query(array_diff_key($_GET, ['month'=>''])) ?>'">
        </div>
    </div>
    
    <!-- Calendar Container -->
    <div id="calendar" class="calendar-wrapper"></div>
</div>

<!-- Day Detail Modal -->
<div class="modal fade" id="dayModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title fw-bold" id="modalDateTitle">📅 Day Details</h5>
                    <small class="text-muted" id="modalDateSub"></small>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-2 mb-3">
                    <div class="col-3"><div class="p-2 rounded text-center" style="background:rgba(59,130,246,0.1)"><div class="h5 mb-0 fw-bold text-primary" id="t-fp">0</div><small class="text-muted">FP</small></div></div>
                    <div class="col-3"><div class="p-2 rounded text-center" style="background:rgba(34,197,94,0.1)"><div class="h5 mb-0 fw-bold text-success" id="t-bp">0</div><small class="text-muted">BP</small></div></div>
                    <div class="col-3"><div class="p-2 rounded text-center" style="background:rgba(249,115,22,0.1)"><div class="h5 mb-0 fw-bold text-orange" id="t-deno">0</div><small class="text-muted">Deno</small></div></div>
                    <div class="col-3"><div class="p-2 rounded text-center" style="background:rgba(168,85,247,0.1)"><div class="h5 mb-0 fw-bold text-purple" id="t-d2m">0</div><small class="text-muted">D2M</small></div></div>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm" id="detailTable">
                        <thead><tr><th>JT Code</th><th>Book</th><th>Class</th><th>FP</th><th>BP</th><th>Deno</th><th>D2M</th><th>Operator</th><th>Machine</th><th>Shift</th><th>Status</th></tr></thead>
                        <tbody id="detailBody"><tr><td colspan="11" class="text-center text-muted">Loading...</td></tr></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer"><button class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Close</button></div>
        </div>
    </div>
</div>

<div class="loading-overlay" id="loading"><div class="spinner"></div></div>

<!-- Scripts -->
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.js"></script>
<script src="https://nepalidatepicker.sajanmaharjan.com.np/v5/nepali.datepicker/js/nepali.datepicker.v5.0.6.min.js"></script>

<script>
// Global vars
let calendar;
let currentDay = '';
const filters = <?= json_encode(array_filter($_GET), JSON_HEX_TAG|JSON_HEX_AMP) ?>;

// ─── ACCURATE NEPALI DATE CONVERSION USING LIBRARY ───────────────────────
/**
 * Convert English (AD) to Nepali (BS) using Sajjan Maharjan library
 * @param {string} adDate - English date in YYYY-MM-DD format
 * @returns {string} Nepali date in YYYY-MM-DD format
 */
function adToBs(adDate) {
    if (!adDate || typeof NepaliFunctions === 'undefined' || typeof NepaliFunctions.AD2BS !== 'function') {
        // Fallback: return AD date with BS-like format (for display only)
        if (!adDate) return '';
        const d = new Date(adDate + 'T00:00:00');
        if (isNaN(d)) return adDate;
        // This fallback is approximate - library is preferred
        return `${d.getFullYear() + 57}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
    }
    try {
        // Library expects YYYY-MM-DD or YYYY/MM/DD
        const bs = NepaliFunctions.AD2BS(adDate);
        // Library returns format like "2083-02-07" or object - normalize
        if (typeof bs === 'string') return bs;
        if (bs && bs.bs) return bs.bs;
        return adDate; // fallback
    } catch (e) {
        console.warn('AD2BS conversion error:', e);
        return adDate;
    }
}

/**
 * Format date for display: BS on top, AD below
 */
function renderDualDate(adDate) {
    const bs = adToBs(adDate);
    return `<div class="date-bs">${bs}</div><div class="date-ad">${adDate.slice(5)}</div>`;
}

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    // Initialize FullCalendar
    initCalendar();
    
    // Filter form
    document.getElementById('filterForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const params = new URLSearchParams(new FormData(this));
        params.set('month', '<?= $view_month ?>');
        window.location.search = params.toString();
    });
    
    // Update BS month label in header (approximate for nav)
    updateBsMonthLabel('<?= $view_month ?>');
});

function updateBsMonthLabel(ym) {
    if (typeof NepaliFunctions !== 'undefined' && typeof NepaliFunctions.AD2BS === 'function') {
        try {
            const first = ym + '-01';
            const bs = adToBs(first);
            const parts = bs.split('-');
            if (parts.length === 3) {
                const bsMonths = ['Baishakh','Jestha','Ashadh','Shrawan','Bhadra','Ashwin','Kartik','Mangsir','Poush','Magh','Falgun','Chaitra'];
                const bsMonth = bsMonths[(parseInt(parts[1]) - 1) % 12] || '';
                document.getElementById('navBsLabel').textContent = `${parts[0]} ${bsMonth}`;
                document.getElementById('headerMonthLabel').textContent = `${document.getElementById('navMonthLabel').textContent} | BS: ${parts[0]} ${bsMonth}`;
            }
        } catch(e) {}
    }
}

function initCalendar() {
    const el = document.getElementById('calendar');
    if (!el) return;
    
    calendar = new FullCalendar.Calendar(el, {
        initialView: 'dayGridMonth',
        initialDate: '<?= $view_month ?>-01',
        headerToolbar: false,
        height: 'auto',
        fixedWeekCount: true,
        dayMaxEvents: 3,
        
        dayCellDidMount: function(info) {
            const adDate = info.date.toISOString().split('T')[0];
            const frame = info.el.querySelector('.fc-daygrid-day-frame');
            if (!frame) return;
            
            // Clear and rebuild cell content
            const dayNum = frame.querySelector('.fc-daygrid-day-number');
            frame.innerHTML = '';
            if (dayNum) frame.appendChild(dayNum);
            
            // Add dual dates (BS/AD) using accurate conversion
            const dates = document.createElement('div');
            dates.className = 'date-dual mt-1';
            dates.innerHTML = renderDualDate(adDate);
            frame.appendChild(dates);
            
            // Metrics container (populated by AJAX)
            const metrics = document.createElement('div');
            metrics.className = 'metrics mt-1';
            metrics.dataset.adDate = adDate;
            frame.appendChild(metrics);
            
            // Click handler
            frame.style.cursor = 'pointer';
            frame.onclick = () => openDayModal(adDate);
        },
        
        datesSet: function() { 
            loadCalendarData();
            updateBsMonthLabel(calendar.getDate().toISOString().slice(0,7));
        }
    });
    calendar.render();
    loadCalendarData();
}

function loadCalendarData() {
    showLoading(true);
    const params = new URLSearchParams({
        action: 'load_calendar',
        month: calendar.getDate().toISOString().slice(0,7),
        ...Object.entries(filters).reduce((acc,[k,v])=>{if(v)acc[k]=v;return acc},{})
    });
    
    fetch('?' + params.toString())
        .then(r => r.json())
        .then(res => {
            if (!res?.success) { console.error('Calendar error:', res?.error); return; }
            updateCalendarCells(res.data);
        })
        .catch(err => console.error('Fetch error:', err))
        .finally(() => showLoading(false));
}

function updateCalendarCells(data) {
    document.querySelectorAll('.fc-daygrid-day').forEach(dayEl => {
        const ad = dayEl.getAttribute('data-date');
        if (!ad || !data[ad]) return;
        
        const frame = dayEl.querySelector('.fc-daygrid-day-frame');
        const metrics = frame?.querySelector('.metrics');
        if (!metrics) return;
        
        const d = data[ad];
        
        // Update BS date using accurate conversion
        const bsEl = frame.querySelector('.date-bs');
        if (bsEl) bsEl.textContent = adToBs(ad);
        
        // Build metrics HTML
        let html = '';
        if (d.fp) html += `<span class="metric-badge m-fp">FP:${formatNum(d.fp)}</span> `;
        if (d.bp) html += `<span class="metric-badge m-bp">BP:${formatNum(d.bp)}</span> `;
        if (d.deno) html += `<span class="metric-badge m-deno">D:${formatNum(d.deno)}</span> `;
        if (d.d2m) html += `<span class="metric-badge m-d2m">D2M:${formatNum(d.d2m)}</span> `;
        if (d.jt) html += `<span class="metric-badge m-jt">JT:${d.jt}</span>`;
        metrics.innerHTML = html;
        
        // Holiday styling
        if (d.is_holiday) frame.style.borderLeft = `3px solid var(--holiday-red)`;
    });
}

function openDayModal(adDate) {
    currentDay = adDate;
    showLoading(true);
    
    // Show both dates in modal header
    const bsDate = adToBs(adDate);
    document.getElementById('modalDateTitle').textContent = `📅 ${adDate}`;
    document.getElementById('modalDateSub').textContent = `BS: ${bsDate}`;
    
    fetch(`?action=load_day_details&date=${adDate}`)
        .then(r => r.json())
        .then(res => {
            if (!res?.success) {
                document.getElementById('detailBody').innerHTML = '<tr><td colspan="11" class="text-center text-danger">Error loading</td></tr>';
                return;
            }
            
            // Update totals
            ['fp','bp','deno','d2m'].forEach(k => {
                document.getElementById(`t-${k}`).textContent = formatNum(res.totals[k]||0);
            });
            
            // Populate table
            const tbody = document.getElementById('detailBody');
            if (!res.records?.length) {
                tbody.innerHTML = '<tr><td colspan="11" class="text-center text-muted">No records</td></tr>';
            } else {
                tbody.innerHTML = res.records.map(r => `
                    <tr>
                        <td><strong>${escapeHtml(r.job_ticket_code||'')}</strong></td>
                        <td>${escapeHtml(r.book_code||'')}<br><small class="text-muted">${truncate(escapeHtml(r.book_name||''),18)}</small></td>
                        <td>${escapeHtml(r.class_level||'-')}</td>
                        <td class="text-primary fw-semibold">${formatNum(r.fp_printqty||0)}</td>
                        <td class="text-success fw-semibold">${formatNum(r.packing_qty||0)}</td>
                        <td class="text-orange fw-semibold">${formatNum(r.deno_qty||0)}</td>
                        <td class="text-purple fw-semibold">${formatNum(r.d2m_qty||0)}</td>
                        <td>${escapeHtml(r.operator||'-')}</td>
                        <td>${escapeHtml(r.machine_name||'-')}</td>
                        <td>${escapeHtml(r.shift||'-')}</td>
                        <td><span class="badge bg-secondary">${escapeHtml(r.status||'-')}</span></td>
                    </tr>
                `).join('');
            }
            
            new bootstrap.Modal(document.getElementById('dayModal')).show();
        })
        .catch(err => {
            console.error('Detail error:', err);
            document.getElementById('detailBody').innerHTML = '<tr><td colspan="11" class="text-center text-danger">Error</td></tr>';
        })
        .finally(() => showLoading(false));
}

function exportData(format, date) {
    const params = new URLSearchParams({
        action: 'export', format, date,
        ...Object.entries(filters).reduce((acc,[k,v])=>{if(v)acc[k]=v;return acc},{})
    });
    window.location.href = '?' + params.toString();
}

function resetFilters() {
    window.location.href = window.location.pathname;
}

function showLoading(show) {
    document.getElementById('loading').style.display = show ? 'flex' : 'none';
}

function formatNum(n) {
    n = parseInt(n)||0;
    if (n>=1e6) return (n/1e6).toFixed(1)+'M';
    if (n>=1e3) return (n/1e3).toFixed(1)+'K';
    return n.toLocaleString();
}

function truncate(s,len){ return s&&s.length>len?s.substring(0,len)+'...':s||''; }
function escapeHtml(t){ if(!t)return''; const m={'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}; return t.replace(/[&<>"']/g,x=>m[x]); }
</script>
</body>
</html>
<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>