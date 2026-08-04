<?php
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

if (!has_role('viewer') && !has_role('operator') && !has_role('incharge') && !has_role('supervisor') && !has_role('admin')) {
    ob_end_clean();
    header('Location: /deno2/unauthorized.php');
    exit();
}

$book_code = $_GET['book_code'] ?? '';
$fiscal_year = $_GET['fiscal_year'] ?? '';

if (empty($book_code)) {
    header('Location: report_production_control.php');
    exit();
}

// Book info
$book = $conn->prepare("SELECT * FROM books WHERE book_code = :bc LIMIT 1");
$book->bindValue(':bc', $book_code);
$book->execute();
$book = $book->fetch(PDO::FETCH_ASSOC);

if (!$book) {
    die("<div style='padding:40px;text-align:center;color:#ef4444'>❌ Book not found: <strong>" . htmlspecialchars($book_code) . "</strong><br><a href='report_production_control.php' style='color:#6366f1'>← Back</a></div>");
}

// Related data queries (same as original, fiscal_year filter applied)
$jt_sql = "SELECT jt.*, fy.fiscal_code FROM job_ticket jt LEFT JOIN fiscal_years fy ON fy.id = jt.fiscal_year_id WHERE jt.book_id = :bid";
if ($fiscal_year) $jt_sql .= " AND jt.fiscal_year_id = :fy";
$jt_sql .= " ORDER BY jt.created_date DESC";
$jt_stmt = $conn->prepare($jt_sql);
$jt_stmt->bindValue(':bid', $book['book_id']);
if ($fiscal_year) $jt_stmt->bindValue(':fy', $fiscal_year);
$jt_stmt->execute();
$job_tickets = $jt_stmt->fetchAll(PDO::FETCH_ASSOC);

$fp_sql = "SELECT fp.*, jt.job_ticket_code FROM forma_printing fp JOIN job_ticket jt ON jt.id = fp.jt_id WHERE fp.jt_id IN (SELECT id FROM job_ticket WHERE book_id = :bid) ".($fiscal_year?"AND jt.fiscal_year_id = :fy":"")." AND fp.status = true ORDER BY fp.created_date DESC";
$fp_stmt = $conn->prepare($fp_sql);
$fp_stmt->bindValue(':bid', $book['book_id']);
if ($fiscal_year) $fp_stmt->bindValue(':fy', $fiscal_year);
$fp_stmt->execute();
$printings = $fp_stmt->fetchAll(PDO::FETCH_ASSOC);

$bp_sql = "SELECT bp.*, jt.job_ticket_code FROM book_packing bp JOIN job_ticket jt ON jt.id = bp.jt_id WHERE bp.jt_id IN (SELECT id FROM job_ticket WHERE book_id = :bid) ".($fiscal_year?"AND jt.fiscal_year_id = :fy":"")." AND bp.status = true ORDER BY bp.created_date DESC";
$bp_stmt = $conn->prepare($bp_sql);
$bp_stmt->bindValue(':bid', $book['book_id']);
if ($fiscal_year) $bp_stmt->bindValue(':fy', $fiscal_year);
$bp_stmt->execute();
$packings = $bp_stmt->fetchAll(PDO::FETCH_ASSOC);

$deno_sql = "SELECT d.*, fy.fiscal_code FROM deno d LEFT JOIN fiscal_years fy ON fy.fiscal_code = d.fiscal_year WHERE d.book_code = :bc AND d.deleted_at IS NULL ".($fiscal_year?"AND d.fiscal_year = (SELECT fiscal_code FROM fiscal_years WHERE id = :fy)":"")." ORDER BY d.deno_date_eng DESC";
$deno_stmt = $conn->prepare($deno_sql);
$deno_stmt->bindValue(':bc', $book_code);
if ($fiscal_year) $deno_stmt->bindValue(':fy', $fiscal_year);
$deno_stmt->execute();
$denos = $deno_stmt->fetchAll(PDO::FETCH_ASSOC);

$d2m_sql = "SELECT dm.*, di.total_qty, di.book_code FROM d2m dm JOIN d2m_items di ON di.d2m_id = dm.id WHERE di.book_code = :bc AND dm.deleted_at IS NULL AND dm.status <> 'CANCELLED' ".($fiscal_year?"AND dm.fiscal_year_id = :fy":"")." ORDER BY dm.eng_date DESC";
$d2m_stmt = $conn->prepare($d2m_sql);
$d2m_stmt->bindValue(':bc', $book_code);
if ($fiscal_year) $d2m_stmt->bindValue(':fy', $fiscal_year);
$d2m_stmt->execute();
$d2ms = $d2m_stmt->fetchAll(PDO::FETCH_ASSOC);

$jt_total = array_sum(array_column($job_tickets, 'print_qty'));
$fp_total = array_sum(array_column($printings, 'fp_printqty'));
$bp_total = array_sum(array_column($packings, 'p_qty'));
$deno_total = array_sum(array_column($denos, 'total_qty'));
$d2m_total = array_sum(array_column($d2ms, 'total_qty'));

$print_pct = $jt_total ? min(100, round($fp_total/$jt_total*100,1)) : 0;
$pack_pct  = $jt_total ? min(100, round($bp_total/$jt_total*100,1)) : 0;
$deno_pct  = $jt_total ? min(100, round($deno_total/$jt_total*100,1)) : 0;
$d2m_pct   = $jt_total ? min(100, round($d2m_total/$jt_total*100,1)) : 0;

// Fiscal year dropdown for filter in header
$fiscal_years = $conn->query("SELECT id, fiscal_code, fiscal_name FROM fiscal_years ORDER BY fiscal_code DESC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>📋 <?= htmlspecialchars($book['book_code']) ?> - Production Detail</title>
<link href="https://nepalidatepicker.sajanmaharjan.com.np/v5/nepali.datepicker/css/nepali.datepicker.v5.0.6.min.css" rel="stylesheet"/>
<style>
:root {
  --bg: #ffffff; --surface: #f8fafc; --surface2: #f1f5f9; --border: #e2e8f0;
  --accent: #4f46e5; --text: #1e293b; --text-muted: #64748b;
  --success: #10b981; --warning: #f59e0b; --danger: #ef4444; --radius: 12px;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--text);font-family:'Segoe UI',system-ui,sans-serif;font-size:14px;line-height:1.5}
a{color:var(--accent);text-decoration:none}
.wrap{max-width:1100px;margin:0 auto;padding:20px 16px}

.detail-header{
  display:flex;justify-content:space-between;align-items:flex-start;
  padding-bottom:16px;margin-bottom:20px;border-bottom:1px solid var(--border);
}
.book-title{font-size:22px;font-weight:700;color:var(--text)}
.book-meta{font-size:13px;color:var(--text-muted);margin-top:4px}
.badge{display:inline-block;padding:1px 6px;border-radius:4px;font-size:10px;font-weight:600}
.badge-info{background:#e0f2fe;color:#0369a1}.badge-warning{background:#fef3c7;color:#92400e}

.summary-grid{
  display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px;margin-bottom:20px;
}
.summary-card{
  background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);
  padding:14px;text-align:center;
}
.summary-value{font-size:22px;font-weight:800;color:var(--text)}
.summary-label{font-size:11px;color:var(--text-muted);margin-top:4px;text-transform:uppercase}

.progress-section{
  background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);
  padding:16px;margin-bottom:20px;
}
.progress-title{font-size:13px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:12px}
.progress-item{display:flex;align-items:center;gap:10px;margin-bottom:10px}
.progress-item:last-child{margin-bottom:0}
.progress-label{width:60px;font-size:12px;font-weight:600}
.progress-track{flex:1;height:8px;background:var(--surface2);border-radius:4px;overflow:hidden}
.progress-fill{height:100%;border-radius:4px}
.progress-val{width:40px;text-align:right;font-weight:700;font-size:12px}

.data-card{
  background:#fff;border:1px solid var(--border);border-radius:var(--radius);
  padding:16px;margin-bottom:16px;
}
.data-card-title{
  font-size:14px;font-weight:700;margin-bottom:12px;padding-bottom:8px;
  border-bottom:1px solid var(--border);display:flex;align-items:center;gap:6px;
}
.data-table{width:100%;border-collapse:collapse;font-size:13px}
.data-table th{padding:8px 10px;text-align:left;font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;background:var(--surface2);border-bottom:1px solid var(--border)}
.data-table td{padding:8px 10px;border-bottom:1px solid var(--border)}
.data-table tr:last-child td{border-bottom:none}
.text-muted{color:var(--text-muted);font-style:italic}

.btn{
  display:inline-flex;align-items:center;gap:5px;padding:7px 13px;border:none;
  border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;transition:.2s;text-decoration:none;
}
.btn:hover{transform:translateY(-1px);box-shadow:0 2px 8px rgba(0,0,0,0.08)}
.btn-primary{background:var(--accent);color:#fff}
.btn-secondary{background:var(--surface2);color:var(--text);border:1px solid var(--border)}
.btn-success{background:var(--success);color:#fff}
.btn-print{background:#64748b;color:#fff}
.btn-sm{padding:4px 10px;font-size:11px}
.fy-select{background:#fff;border:1px solid var(--border);border-radius:6px;padding:5px 8px;font-size:13px;color:var(--text)}

@media(max-width:768px){
  .detail-header{flex-direction:column;gap:10px}
  .data-table{font-size:12px}
}
@media print{
  body{background:#fff;color:#000}
  .no-print{display:none!important}
  .wrap{padding:0;max-width:100%}
  .data-card{border:1px solid #ccc;border-radius:0;margin-bottom:10px}
  .data-table th,.data-table td{border-bottom:1px solid #ccc;color:#000}
}
</style>
</head>
<body>
<div class="wrap">
  <div class="detail-header">
    <div>
      <h1 class="book-title">📋 <?= htmlspecialchars($book['book_code']) ?> – <?= htmlspecialchars($book['book_name']) ?></h1>
      <div class="book-meta">
        Class: <?= $book['class_level'] ?? '—' ?> | Type: <?= htmlspecialchars($book['book_type']??'') ?> |
        <?php if (!empty($book['is_translated'])): ?><span class="badge badge-info">Translated</span><?php endif; ?>
        <?php if (!empty($book['is_optional'])): ?><span class="badge badge-warning">Optional</span><?php endif; ?>
      </div>
    </div>
    <div class="no-print" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <form method="GET" style="display:contents">
        <input type="hidden" name="book_code" value="<?= htmlspecialchars($book_code) ?>">
        <select name="fiscal_year" class="fy-select" onchange="this.form.submit()">
          <option value="">All Fiscal Years</option>
          <?php foreach ($fiscal_years as $fy): ?>
            <option value="<?= $fy['id'] ?>" <?= $fiscal_year == $fy['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($fy['fiscal_code'].' - '.$fy['fiscal_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </form>
      <a href="report_production_control.php?<?= http_build_query(array_intersect_key($_GET, array_flip(['fiscal_year']))) ?>" class="btn btn-secondary">← Back</a>
      <button class="btn btn-print" onclick="window.print()">🖨️ Print</button>
      <button class="btn btn-success" onclick="exportDetail('excel')">📥 Excel</button>
      <button class="btn btn-primary" onclick="exportDetail('pdf')">📄 PDF</button>
    </div>
  </div>

  <!-- Summary -->
  <div class="summary-grid">
    <div class="summary-card"><div class="summary-value"><?= count($job_tickets) ?></div><div class="summary-label">Job Tickets</div></div>
    <div class="summary-card"><div class="summary-value"><?= $print_pct ?>%</div><div class="summary-label">Printing</div></div>
    <div class="summary-card"><div class="summary-value"><?= $pack_pct ?>%</div><div class="summary-label">Packing</div></div>
    <div class="summary-card"><div class="summary-value"><?= $deno_pct ?>%</div><div class="summary-label">DENO</div></div>
    <div class="summary-card"><div class="summary-value"><?= $d2m_pct ?>%</div><div class="summary-label">D2M</div></div>
  </div>

  <!-- Progress -->
  <div class="progress-section">
    <div class="progress-title">📊 Pipeline Progress</div>
    <?php foreach (['Print'=>$print_pct,'Pack'=>$pack_pct,'Deno'=>$deno_pct,'D2M'=>$d2m_pct] as $l=>$p):
      $c = ['Print'=>'#3b82f6','Pack'=>'#f59e0b','Deno'=>'#f97316','D2M'=>'#8b5cf6'][$l]; ?>
      <div class="progress-item">
        <span class="progress-label"><?= $l ?></span>
        <div class="progress-track"><div class="progress-fill" style="width:<?= $p ?>%;background:<?= $c ?>"></div></div>
        <span class="progress-val" style="color:<?= $c ?>"><?= $p ?>%</span>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- Job Tickets -->
  <div class="data-card">
    <div class="data-card-title">📋 Job Tickets (<?= count($job_tickets) ?>)</div>
    <?php if (empty($job_tickets)): ?>
      <p class="text-muted">No job tickets found.</p>
    <?php else: ?>
      <table class="data-table">
        <thead><tr><th>Code</th><th>Fiscal Year</th><th>Print Qty</th><th>Status</th><th>Created</th></tr></thead>
        <tbody>
          <?php foreach ($job_tickets as $jt): ?>
          <tr>
            <td><strong><?= htmlspecialchars($jt['job_ticket_code']) ?></strong></td>
            <td><?= htmlspecialchars($jt['fiscal_code']??'—') ?></td>
            <td><?= number_format($jt['print_qty']) ?></td>
            <td><span class="badge badge-info"><?= ucfirst(str_replace('_',' ',$jt['status'])) ?></span></td>
            <td><?= $jt['created_date']?date('Y-m-d',strtotime($jt['created_date'])):'—' ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <!-- Printing -->
  <div class="data-card">
    <div class="data-card-title">🖨️ Forma Printing (<?= count($printings) ?>)</div>
    <?php if (empty($printings)): ?>
      <p class="text-muted">No printing records.</p>
    <?php else: ?>
      <table class="data-table">
        <thead><tr><th>JT Code</th><th>Printed Qty</th><th>Remarks</th><th>Date</th></tr></thead>
        <tbody>
          <?php foreach ($printings as $fp): ?>
          <tr>
            <td><?= htmlspecialchars($fp['job_ticket_code']) ?></td>
            <td><?= number_format($fp['fp_printqty']) ?></td>
            <td><?= htmlspecialchars($fp['remarks']??'—') ?></td>
            <td><?= $fp['created_date']?date('Y-m-d',strtotime($fp['created_date'])):'—' ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <!-- Packing -->
  <div class="data-card">
    <div class="data-card-title">📦 Book Packing (<?= count($packings) ?>)</div>
    <?php if (empty($packings)): ?>
      <p class="text-muted">No packing records.</p>
    <?php else: ?>
      <table class="data-table">
        <thead><tr><th>JT Code</th><th>Packed Qty</th><th>Box No</th><th>Date</th></tr></thead>
        <tbody>
          <?php foreach ($packings as $bp): ?>
          <tr>
            <td><?= htmlspecialchars($bp['job_ticket_code']) ?></td>
            <td><?= number_format($bp['p_qty']) ?></td>
            <td><?= htmlspecialchars($bp['box_no']??'—') ?></td>
            <td><?= $bp['created_date']?date('Y-m-d',strtotime($bp['created_date'])):'—' ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <!-- DENO -->
  <div class="data-card">
    <div class="data-card-title">🚚 DENO Dispatch (<?= count($denos) ?>)</div>
    <?php if (empty($denos)): ?>
      <p class="text-muted">No DENO records.</p>
    <?php else: ?>
      <table class="data-table">
        <thead><tr><th>Ref No</th><th>Fiscal Year</th><th>Qty</th><th>Date (Eng)</th><th>Date (Nep)</th></tr></thead>
        <tbody>
          <?php foreach ($denos as $dn): ?>
          <tr>
            <td><strong><?= htmlspecialchars($dn['ref_no']) ?></strong></td>
            <td><?= htmlspecialchars($dn['fiscal_code']??'—') ?></td>
            <td><?= number_format($dn['total_qty']) ?></td>
            <td><?= $dn['deno_date_eng']??'—' ?></td>
            <td><?= htmlspecialchars($dn['deno_date_nep']??'—') ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <!-- D2M -->
  <div class="data-card">
    <div class="data-card-title">🏭 D2M Delivery (<?= count($d2ms) ?>)</div>
    <?php if (empty($d2ms)): ?>
      <p class="text-muted">No D2M records.</p>
    <?php else: ?>
      <table class="data-table">
        <thead><tr><th>D2M No</th><th>Qty</th><th>Status</th><th>Date</th></tr></thead>
        <tbody>
          <?php foreach ($d2ms as $dm): ?>
          <tr>
            <td><strong><?= htmlspecialchars($dm['d2m_no']) ?></strong></td>
            <td><?= number_format($dm['total_qty']) ?></td>
            <td><span class="badge <?= $dm['status']==='CLOSE'?'badge-success':($dm['status']==='CANCELLED'?'badge-danger':'badge-warning') ?>"><?= $dm['status'] ?></span></td>
            <td><?= $dm['eng_date']??'—' ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

</div>

<script>
function exportDetail(type) {
  const p = new URLSearchParams(window.location.search);
  p.set('export', type);
  window.location = 'report_export_detail.php?' + p.toString();
}
</script>
<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>