<?php
/**
 * forma_ctp.php — CTP Forma Module Dashboard
 * Lists all job tickets with their forma status
 * Handles: list, init_db actions
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
redirect_if_not_logged_in();

$action = $_GET['action'] ?? 'list';
$msg    = '';
$error  = '';

// ── Initialize DB Tables ──────────────────────────────────────
if ($action === 'init_db') {
    try {
        $sql = file_get_contents(__DIR__ . '/forma_ctp_schema.sql');
        $conn->exec($sql);
        $msg = 'Database tables initialized successfully.';
    } catch (Exception $e) {
        $error = 'DB init error: ' . $e->getMessage();
    }
    $action = 'list';
}

// ── Fetch job tickets summary ─────────────────────────────────
$search   = trim($_GET['search'] ?? '');
$filter   = $_GET['status'] ?? '';
$page_num = max(1, (int)($_GET['p'] ?? 1));
$per_page = 20;
$offset   = ($page_num - 1) * $per_page;

$where  = [];
$params = [];
if ($search) {
    $where[]  = "(jt.job_ticket_code ILIKE :s OR b.book_code ILIKE :s OR b.book_name ILIKE :s)";
    $params[':s'] = "%{$search}%";
}
if ($filter) {
    $where[]  = "jt.status = :status";
    $params[':status'] = $filter;
}
$whereSQL = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$total_stmt = $conn->prepare("SELECT COUNT(*) FROM fctp_job_tickets jt JOIN fctp_books b ON jt.book_code = b.book_code $whereSQL");
$total_stmt->execute($params);
$total_rows = (int)$total_stmt->fetchColumn();
$total_pages = max(1, ceil($total_rows / $per_page));

$stmt = $conn->prepare("
    SELECT jt.*, b.book_name, b.class, b.total_pages, b.master_pdf_path,
           COUNT(f.id) AS forma_count,
           SUM(CASE WHEN f.output_status='generated' THEN 1 ELSE 0 END) AS formas_done,
           SUM(CASE WHEN f.output_status='pending' THEN 1 ELSE 0 END) AS formas_pending
    FROM fctp_job_tickets jt
    JOIN fctp_books b ON jt.book_code = b.book_code
    LEFT JOIN fctp_formas f ON f.job_ticket_id = jt.id
    $whereSQL
    GROUP BY jt.id, b.book_name, b.class, b.total_pages, b.master_pdf_path
    ORDER BY jt.created_at DESC
    LIMIT :lim OFFSET :off
");
$params[':lim'] = $per_page;
$params[':off'] = $offset;
$stmt->execute($params);
$jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';
?>
<style>
:root{--primary:#1a73e8;--success:#16a34a;--warning:#d97706;--danger:#dc2626;--dark:#1e293b;--muted:#64748b;--border:#e2e8f0;--bg:#f8fafc;}
*{box-sizing:border-box;}
.fctp-wrap{max-width:1400px;margin:0 auto;padding:0 16px 40px;}
.fctp-nav{display:flex;gap:8px;flex-wrap:wrap;background:#fff;padding:12px 18px;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,.07);align-items:center;margin-bottom:20px;}
.fctp-nav-title{font-weight:800;font-size:15px;color:var(--dark);margin-right:8px;display:flex;align-items:center;gap:6px;}
.nav-btn{padding:6px 14px;border-radius:6px;font-size:12.5px;font-weight:600;text-decoration:none;color:var(--muted);border:1.5px solid var(--border);transition:.15s;}
.nav-btn:hover,.nav-btn.active{background:var(--primary);color:#fff;border-color:var(--primary);}
.nav-btn.green{border-color:#16a34a;color:#16a34a;}
.nav-btn.green:hover{background:#16a34a;color:#fff;}
.page-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;flex-wrap:wrap;gap:10px;}
.page-title{font-size:22px;font-weight:800;color:var(--dark);}
.page-subtitle{font-size:13px;color:var(--muted);margin-top:2px;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 18px;border-radius:7px;font-size:13px;font-weight:600;text-decoration:none;border:none;cursor:pointer;transition:.15s;}
.btn-primary{background:var(--primary);color:#fff;}
.btn-primary:hover{background:#1557b0;}
.btn-success{background:#16a34a;color:#fff;}
.btn-success:hover{background:#138038;}
.btn-sm{padding:5px 12px;font-size:12px;}
.btn-outline{background:#fff;color:var(--primary);border:1.5px solid var(--primary);}
.btn-outline:hover{background:var(--primary);color:#fff;}
.search-bar{display:flex;gap:8px;flex-wrap:wrap;background:#fff;padding:14px 18px;border-radius:10px;box-shadow:0 1px 8px rgba(0,0,0,.06);margin-bottom:16px;}
.search-bar input,.search-bar select{padding:8px 12px;border:1.5px solid var(--border);border-radius:6px;font-size:13px;outline:none;}
.search-bar input:focus,.search-bar select:focus{border-color:var(--primary);}
.search-bar input{flex:1;min-width:200px;}
.card{background:#fff;border-radius:12px;box-shadow:0 2px 12px rgba(0,0,0,.07);overflow:hidden;}
table{width:100%;border-collapse:collapse;font-size:13px;}
th{background:#f1f5f9;padding:10px 14px;text-align:left;font-weight:700;color:var(--dark);font-size:12px;text-transform:uppercase;letter-spacing:.5px;border-bottom:2px solid var(--border);}
td{padding:10px 14px;border-bottom:1px solid #f1f5f9;vertical-align:middle;}
tr:hover td{background:#fafbff;}
.badge{display:inline-flex;align-items:center;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;}
.badge-active{background:#dcfce7;color:#16a34a;}
.badge-closed{background:#f1f5f9;color:var(--muted);}
.badge-cancelled{background:#fee2e2;color:var(--danger);}
.progress-bar-wrap{background:#e2e8f0;border-radius:20px;height:8px;min-width:80px;overflow:hidden;}
.progress-bar{height:100%;border-radius:20px;background:var(--primary);transition:width .3s;}
.progress-bar.done{background:var(--success);}
.alert{padding:12px 18px;border-radius:8px;font-size:13px;margin-bottom:16px;font-weight:500;}
.alert-success{background:#dcfce7;color:#16a34a;border:1px solid #bbf7d0;}
.alert-error{background:#fee2e2;color:var(--danger);border:1px solid #fecaca;}
.empty-state{text-align:center;padding:60px 20px;color:var(--muted);}
.empty-state .icon{font-size:48px;margin-bottom:12px;}
.stats-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:20px;}
.stat-card{background:#fff;border-radius:10px;padding:16px 18px;box-shadow:0 2px 10px rgba(0,0,0,.06);display:flex;flex-direction:column;gap:4px;}
.stat-val{font-size:26px;font-weight:800;color:var(--dark);}
.stat-label{font-size:12px;color:var(--muted);font-weight:600;}
.stat-card.blue .stat-val{color:var(--primary);}
.stat-card.green .stat-val{color:var(--success);}
.stat-card.orange .stat-val{color:var(--warning);}
.pagination{display:flex;gap:6px;justify-content:center;margin-top:16px;flex-wrap:wrap;}
.pagination a,.pagination span{padding:6px 12px;border-radius:6px;font-size:13px;font-weight:600;text-decoration:none;border:1.5px solid var(--border);color:var(--muted);}
.pagination a:hover{background:var(--primary);color:#fff;border-color:var(--primary);}
.pagination .current{background:var(--primary);color:#fff;border-color:var(--primary);}
.action-btns{display:flex;gap:6px;flex-wrap:nowrap;}
.forma-pills{display:flex;gap:4px;flex-wrap:wrap;}
.pill{display:inline-flex;align-items:center;gap:3px;padding:2px 8px;border-radius:12px;font-size:11px;font-weight:600;background:#eff6ff;color:#1a73e8;border:1px solid #bfdbfe;}
.pill.done{background:#dcfce7;color:#16a34a;border-color:#bbf7d0;}
.pill.pending{background:#fef9c3;color:#a16207;border-color:#fde68a;}
</style>

<div class="fctp-wrap">
<!-- NAV -->
<div class="fctp-nav">
    <span class="fctp-nav-title">🖨️ Forma CTP</span>
    <a href="forma_ctp.php" class="nav-btn active">📋 Job Tickets</a>
    <a href="forma_ctp_book.php" class="nav-btn">📚 Books</a>
    <a href="forma_ctp_job_create.php" class="nav-btn green">➕ New Job Ticket</a>
    <a href="forma_ctp_templates.php" class="nav-btn">⚙️ Templates</a>
    <a href="forma_ctp.php?action=init_db" class="nav-btn" onclick="return confirm('Initialize/update DB tables?')">🗄️ Init DB</a>
</div>

<?php if ($msg): ?><div class="alert alert-success">✅ <?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error">❌ <?= htmlspecialchars($error) ?></div><?php endif; ?>

<!-- HEADER -->
<div class="page-header">
    <div>
        <div class="page-title">📋 CTP Job Tickets</div>
        <div class="page-subtitle">Manage per-book CTP imposition jobs and plate generation</div>
    </div>
    <a href="forma_ctp_job_create.php" class="btn btn-primary">➕ New Job Ticket</a>
</div>

<!-- STATS -->
<?php
$stats_stmt = $conn->query("
    SELECT 
        COUNT(DISTINCT jt.id) AS total_jobs,
        COUNT(DISTINCT b.book_code) AS total_books,
        COUNT(f.id) AS total_formas,
        SUM(CASE WHEN f.output_status='generated' THEN 1 ELSE 0 END) AS done_formas
    FROM fctp_job_tickets jt
    JOIN fctp_books b ON jt.book_code = b.book_code
    LEFT JOIN fctp_formas f ON f.job_ticket_id = jt.id
");
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC) ?: [];
?>
<div class="stats-row">
    <div class="stat-card blue"><span class="stat-val"><?= $stats['total_jobs'] ?? 0 ?></span><span class="stat-label">Total Jobs</span></div>
    <div class="stat-card"><span class="stat-val"><?= $stats['total_books'] ?? 0 ?></span><span class="stat-label">Books</span></div>
    <div class="stat-card orange"><span class="stat-val"><?= $stats['total_formas'] ?? 0 ?></span><span class="stat-label">Total Formas</span></div>
    <div class="stat-card green"><span class="stat-val"><?= $stats['done_formas'] ?? 0 ?></span><span class="stat-label">Plates Generated</span></div>
</div>

<!-- SEARCH -->
<form method="GET" class="search-bar">
    <input type="text" name="search" placeholder="🔍 Search job code, book code, book name..." value="<?= htmlspecialchars($search) ?>">
    <select name="status">
        <option value="">All Status</option>
        <option value="active" <?= $filter==='active'?'selected':'' ?>>Active</option>
        <option value="closed" <?= $filter==='closed'?'selected':'' ?>>Closed</option>
        <option value="cancelled" <?= $filter==='cancelled'?'selected':'' ?>>Cancelled</option>
    </select>
    <button type="submit" class="btn btn-primary btn-sm">Search</button>
    <a href="forma_ctp.php" class="btn btn-outline btn-sm">Reset</a>
</form>

<!-- TABLE -->
<div class="card">
<?php if (empty($jobs)): ?>
    <div class="empty-state">
        <div class="icon">📋</div>
        <div style="font-size:16px;font-weight:700;color:#1e293b;margin-bottom:8px;">No job tickets found</div>
        <div style="margin-bottom:18px;">Create your first CTP job ticket to get started.</div>
        <a href="forma_ctp_job_create.php" class="btn btn-primary">➕ Create Job Ticket</a>
    </div>
<?php else: ?>
<table>
<thead>
<tr>
    <th>Job Ticket</th>
    <th>Book</th>
    <th>Class</th>
    <th>FY / Lot</th>
    <th>Print Qty</th>
    <th>Pages</th>
    <th>Formas</th>
    <th>Progress</th>
    <th>Status</th>
    <th>Date</th>
    <th>Actions</th>
</tr>
</thead>
<tbody>
<?php foreach ($jobs as $j): 
    $fc = (int)$j['forma_count'];
    $fd = (int)$j['formas_done'];
    $pct = $fc > 0 ? round(($fd/$fc)*100) : 0;
?>
<tr>
    <td><strong><?= htmlspecialchars($j['job_ticket_code']) ?></strong></td>
    <td>
        <div style="font-weight:700"><?= htmlspecialchars($j['book_code']) ?></div>
        <div style="font-size:11px;color:var(--muted)"><?= htmlspecialchars($j['book_name']) ?></div>
    </td>
    <td><?= htmlspecialchars($j['class'] ?? '—') ?></td>
    <td><?= htmlspecialchars($j['fiscal_year'] ?? '—') ?> / <?= $j['lot_no'] ?></td>
    <td><?= number_format($j['print_qty']) ?></td>
    <td><?= number_format($j['page_qty']) ?></td>
    <td>
        <?php if ($fc > 0): ?>
        <div style="font-weight:700"><?= $fd ?>/<?= $fc ?> done</div>
        <?php else: ?>
        <span style="color:var(--muted);font-size:12px">No formas</span>
        <?php endif; ?>
    </td>
    <td>
        <?php if ($fc > 0): ?>
        <div class="progress-bar-wrap">
            <div class="progress-bar <?= $pct>=100?'done':'' ?>" style="width:<?= $pct ?>%"></div>
        </div>
        <div style="font-size:11px;color:var(--muted);margin-top:2px"><?= $pct ?>%</div>
        <?php else: ?>—<?php endif; ?>
    </td>
    <td><span class="badge badge-<?= $j['status'] ?>"><?= ucfirst($j['status']) ?></span></td>
    <td style="white-space:nowrap;font-size:11px;color:var(--muted)">
        <?= $j['date_nep'] ? htmlspecialchars($j['date_nep']) : ($j['date_eng'] ? $j['date_eng'] : '—') ?>
    </td>
    <td>
        <div class="action-btns">
            <a href="forma_ctp_job_view.php?id=<?= $j['id'] ?>" class="btn btn-outline btn-sm">👁 View</a>
            <a href="forma_ctp_job_edit.php?id=<?= $j['id'] ?>" class="btn btn-sm" style="background:#f1f5f9;color:var(--dark);border:1.5px solid var(--border)">✏️ Edit</a>
        </div>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
</div>

<!-- PAGINATION -->
<?php if ($total_pages > 1): ?>
<div class="pagination">
<?php for ($i = 1; $i <= $total_pages; $i++): ?>
    <?php $qs = http_build_query(['search'=>$search,'status'=>$filter,'p'=>$i]); ?>
    <?php if ($i == $page_num): ?>
        <span class="current"><?= $i ?></span>
    <?php else: ?>
        <a href="forma_ctp.php?<?= $qs ?>"><?= $i ?></a>
    <?php endif; ?>
<?php endfor; ?>
</div>
<?php endif; ?>

</div><!-- /wrap -->

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>
