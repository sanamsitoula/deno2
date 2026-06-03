<?php
/**
 * forma_ctp_job_view.php — View Job Ticket with all Formas
 * Links to imposition editor and plate PDF generation
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
redirect_if_not_logged_in();

$job_id = (int)($_GET['id'] ?? 0);
if (!$job_id) { header('Location: forma_ctp.php'); exit; }

$msg = $_GET['msg'] ?? '';

// Load job ticket
$stmt = $conn->prepare("SELECT jt.*, b.book_name, b.class, b.subject, b.total_pages, b.master_pdf_path, b.master_pdf_name, b.master_pdf_pages FROM fctp_job_tickets jt JOIN fctp_books b ON jt.book_code=b.book_code WHERE jt.id=:id");
$stmt->execute([':id'=>$job_id]);
$job = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$job) { header('Location: forma_ctp.php'); exit; }

// Load formas
$fstmt = $conn->prepare("SELECT * FROM fctp_formas WHERE job_ticket_id=:id ORDER BY order_no");
$fstmt->execute([':id'=>$job_id]);
$formas = $fstmt->fetchAll(PDO::FETCH_ASSOC);

// Handle delete forma
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['post_action']??'')==='delete_forma') {
    $fid = (int)($_POST['forma_id']??0);
    if ($fid) $conn->prepare("DELETE FROM fctp_formas WHERE id=:id AND job_ticket_id=:jid")->execute([':id'=>$fid,':jid'=>$job_id]);
    header("Location: forma_ctp_job_view.php?id={$job_id}&msg=Forma+deleted");
    exit;
}

// Handle status change
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['post_action']??'')==='change_status') {
    $ns = $_POST['new_status']??'active';
    $conn->prepare("UPDATE fctp_job_tickets SET status=:s WHERE id=:id")->execute([':s'=>$ns,':id'=>$job_id]);
    header("Location: forma_ctp_job_view.php?id={$job_id}&msg=Status+updated");
    exit;
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

$total_plates = array_sum(array_column($formas, 'plates_required'));
$done_count   = count(array_filter($formas, fn($f)=>$f['output_status']==='generated'));
?>
<style>
:root{--primary:#1a73e8;--success:#16a34a;--warning:#d97706;--danger:#dc2626;--dark:#1e293b;--muted:#64748b;--border:#e2e8f0;}
*{box-sizing:border-box;}
.fctp-wrap{max-width:1400px;margin:0 auto;padding:0 16px 40px;}
.fctp-nav{display:flex;gap:8px;flex-wrap:wrap;background:#fff;padding:12px 18px;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,.07);align-items:center;margin-bottom:20px;}
.fctp-nav-title{font-weight:800;font-size:15px;color:var(--dark);margin-right:8px;}
.nav-btn{padding:6px 14px;border-radius:6px;font-size:12.5px;font-weight:600;text-decoration:none;color:var(--muted);border:1.5px solid var(--border);transition:.15s;}
.nav-btn:hover{background:var(--primary);color:#fff;border-color:var(--primary);}
.page-header{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px;}
.page-title{font-size:22px;font-weight:800;color:var(--dark);}
.page-sub{font-size:13px;color:var(--muted);}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 18px;border-radius:7px;font-size:13px;font-weight:600;text-decoration:none;border:none;cursor:pointer;transition:.15s;}
.btn-primary{background:var(--primary);color:#fff;}
.btn-primary:hover{background:#1557b0;}
.btn-sm{padding:5px 12px;font-size:12px;}
.btn-success{background:var(--success);color:#fff;}
.btn-success:hover{background:#138038;}
.btn-danger{background:var(--danger);color:#fff;}
.btn-outline{background:#fff;color:var(--primary);border:1.5px solid var(--primary);}
.btn-outline:hover{background:var(--primary);color:#fff;}
.btn-orange{background:#ea580c;color:#fff;}
.btn-orange:hover{background:#c2410c;}
.card{background:#fff;border-radius:12px;box-shadow:0 2px 12px rgba(0,0,0,.07);overflow:hidden;margin-bottom:20px;}
.card-header{padding:14px 20px;border-bottom:1px solid var(--border);font-weight:700;font-size:14px;display:flex;align-items:center;justify-content:space-between;}
.card-body{padding:20px;}
.info-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;}
.info-item{display:flex;flex-direction:column;gap:3px;}
.info-label{font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;}
.info-val{font-size:14px;font-weight:700;color:var(--dark);}
.badge{display:inline-flex;align-items:center;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;}
.badge-active{background:#dcfce7;color:#16a34a;}
.badge-closed{background:#f1f5f9;color:var(--muted);}
.badge-cancelled{background:#fee2e2;color:var(--danger);}
.badge-generated{background:#dcfce7;color:#16a34a;}
.badge-pending{background:#fef9c3;color:#a16207;}
.badge-ready{background:#dbeafe;color:#1a73e8;}
.badge-failed{background:#fee2e2;color:var(--danger);}
.alert{padding:12px 18px;border-radius:8px;font-size:13px;margin-bottom:16px;font-weight:500;}
.alert-success{background:#dcfce7;color:#16a34a;border:1px solid #bbf7d0;}
/* Forma cards grid */
.forma-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(380px,1fr));gap:16px;}
.forma-card{background:#fff;border:1.5px solid var(--border);border-radius:12px;overflow:hidden;transition:.15s;}
.forma-card:hover{border-color:var(--primary);box-shadow:0 4px 16px rgba(26,115,232,.12);}
.forma-card.generated{border-color:#16a34a;}
.forma-card-header{padding:12px 16px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--border);}
.forma-card-title{font-weight:800;font-size:15px;color:var(--dark);}
.forma-card-body{padding:14px 16px;}
.forma-meta{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:12px;}
.fm-item{display:flex;flex-direction:column;gap:2px;}
.fm-label{font-size:10.5px;font-weight:700;color:var(--muted);text-transform:uppercase;}
.fm-val{font-size:13px;font-weight:600;color:var(--dark);}
.forma-actions{display:flex;gap:8px;flex-wrap:wrap;padding-top:12px;border-top:1px solid #f1f5f9;}
.margin-preview{display:flex;gap:4px;flex-wrap:wrap;margin-top:8px;}
.margin-tag{background:#f1f5f9;border-radius:4px;padding:2px 7px;font-size:10.5px;font-weight:600;color:var(--muted);}
.plate-count-badge{background:linear-gradient(135deg,#1a73e8,#0d5bbd);color:#fff;border-radius:8px;padding:4px 10px;font-size:12px;font-weight:700;display:inline-flex;align-items:center;gap:4px;}
.pdf-link{color:var(--primary);font-size:11px;text-decoration:none;font-weight:600;}
.pdf-link:hover{text-decoration:underline;}
.summary-bar{background:linear-gradient(135deg,#1e293b,#334155);color:#fff;border-radius:12px;padding:20px 24px;margin-bottom:20px;display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:16px;}
.sb-item{display:flex;flex-direction:column;gap:4px;}
.sb-val{font-size:24px;font-weight:800;}
.sb-label{font-size:11px;opacity:.7;font-weight:600;text-transform:uppercase;}
.progress-bar-wrap{background:rgba(255,255,255,.2);border-radius:20px;height:6px;overflow:hidden;}
.progress-bar{height:100%;border-radius:20px;background:#4ade80;}
</style>

<div class="fctp-wrap">
<div class="fctp-nav">
    <span class="fctp-nav-title">🖨️ Forma CTP</span>
    <a href="forma_ctp.php" class="nav-btn">📋 Job Tickets</a>
    <a href="forma_ctp_book.php" class="nav-btn">📚 Books</a>
    <a href="forma_ctp_job_create.php" class="nav-btn">➕ New Job</a>
    <a href="forma_ctp_templates.php" class="nav-btn">⚙️ Templates</a>
</div>

<?php if ($msg): ?><div class="alert alert-success">✅ <?= htmlspecialchars($msg) ?></div><?php endif; ?>

<!-- SUMMARY BAR -->
<div class="summary-bar">
    <div class="sb-item">
        <span class="sb-val"><?= htmlspecialchars($job['job_ticket_code']) ?></span>
        <span class="sb-label">Job Ticket Code</span>
    </div>
    <div class="sb-item">
        <span class="sb-val"><?= htmlspecialchars($job['book_code']) ?></span>
        <span class="sb-label"><?= htmlspecialchars($job['book_name']) ?></span>
    </div>
    <div class="sb-item">
        <span class="sb-val"><?= count($formas) ?></span>
        <span class="sb-label">Total Formas</span>
    </div>
    <div class="sb-item">
        <span class="sb-val"><?= $total_plates ?></span>
        <span class="sb-label">Total Plates</span>
    </div>
    <div class="sb-item">
        <span class="sb-val"><?= $done_count ?>/<?= count($formas) ?></span>
        <span class="sb-label">Formas Ready</span>
        <?php if(count($formas)>0): $pct=round(($done_count/count($formas))*100); ?>
        <div class="progress-bar-wrap" style="margin-top:4px"><div class="progress-bar" style="width:<?= $pct ?>%"></div></div>
        <?php endif; ?>
    </div>
    <div class="sb-item">
        <span class="sb-val"><?= number_format($job['print_qty']) ?></span>
        <span class="sb-label">Print Qty</span>
    </div>
</div>

<!-- JOB DETAILS -->
<div class="page-header">
    <div>
        <div class="page-title">📋 <?= htmlspecialchars($job['book_name']) ?> — <?= htmlspecialchars($job['job_ticket_code']) ?></div>
        <div class="page-sub">Class <?= $job['class'] ?> · FY <?= $job['fiscal_year'] ?> · Lot <?= $job['lot_no'] ?> · <?= $job['page_qty'] ?> pages</div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a href="forma_ctp_job_create.php?id=<?= $job_id ?>" class="btn btn-outline btn-sm">✏️ Edit Job</a>
        <span class="badge badge-<?= $job['status'] ?>"><?= ucfirst($job['status']) ?></span>
    </div>
</div>

<!-- JOB INFO CARD -->
<div class="card">
    <div class="card-header">📋 Job Ticket Info</div>
    <div class="card-body">
        <div class="info-grid">
            <div class="info-item"><span class="info-label">Book Code</span><span class="info-val"><?= htmlspecialchars($job['book_code']) ?></span></div>
            <div class="info-item"><span class="info-label">Book Name</span><span class="info-val"><?= htmlspecialchars($job['book_name']) ?></span></div>
            <div class="info-item"><span class="info-label">Class</span><span class="info-val"><?= $job['class'] ?: '—' ?></span></div>
            <div class="info-item"><span class="info-label">Subject</span><span class="info-val"><?= htmlspecialchars($job['subject']??'—') ?></span></div>
            <div class="info-item"><span class="info-label">Fiscal Year</span><span class="info-val"><?= $job['fiscal_year']??'—' ?></span></div>
            <div class="info-item"><span class="info-label">Lot No</span><span class="info-val"><?= $job['lot_no'] ?></span></div>
            <div class="info-item"><span class="info-label">Print Qty</span><span class="info-val"><?= number_format($job['print_qty']) ?></span></div>
            <div class="info-item"><span class="info-label">Page Qty</span><span class="info-val"><?= number_format($job['page_qty']) ?></span></div>
            <div class="info-item"><span class="info-label">Date (Nep)</span><span class="info-val"><?= $job['date_nep']??'—' ?></span></div>
            <div class="info-item"><span class="info-label">Date (Eng)</span><span class="info-val"><?= $job['date_eng']??'—' ?></span></div>
            <div class="info-item"><span class="info-label">Created By</span><span class="info-val"><?= htmlspecialchars($job['created_by']??'—') ?></span></div>
            <div class="info-item">
                <span class="info-label">Master PDF</span>
                <span class="info-val">
                    <?php if ($job['master_pdf_path']): ?>
                    <a href="<?= htmlspecialchars($job['master_pdf_path']) ?>" target="_blank" class="pdf-link">📄 <?= htmlspecialchars($job['master_pdf_name']??'') ?> (<?= $job['master_pdf_pages'] ?>pp)</a>
                    <?php else: ?>
                    <span style="color:var(--danger);font-size:12px">❌ No PDF — <a href="forma_ctp_book.php?action=edit&id=<?= $job['id'] ?>" style="color:var(--primary)">Upload</a></span>
                    <?php endif; ?>
                </span>
            </div>
        </div>
    </div>
</div>

<!-- FORMAS -->
<div class="card-header" style="background:#fff;border-radius:12px 12px 0 0;border:1.5px solid var(--border);border-bottom:none;padding:14px 20px;font-weight:700;font-size:15px;display:flex;justify-content:space-between;align-items:center">
    <span>📦 Formas (<?= count($formas) ?>)</span>
    <a href="forma_ctp_job_create.php?id=<?= $job_id ?>" class="btn btn-primary btn-sm">➕ Edit Formas</a>
</div>

<?php if (empty($formas)): ?>
<div style="background:#fff;border:1.5px solid var(--border);border-top:none;border-radius:0 0 12px 12px;padding:40px;text-align:center;color:var(--muted)">
    No formas defined. <a href="forma_ctp_job_create.php?id=<?= $job_id ?>" style="color:var(--primary);font-weight:600">Add formas →</a>
</div>
<?php else: ?>
<div style="background:#fff;border:1.5px solid var(--border);border-top:none;border-radius:0 0 12px 12px;padding:20px">
<div class="forma-grid">
<?php foreach ($formas as $f): ?>
<div class="forma-card <?= $f['output_status']==='generated'?'generated':'' ?>">
    <div class="forma-card-header">
        <div>
            <span class="forma-card-title">
                <?= $f['forma_type']==='cover'?'🎨':'📄' ?>
                <?= htmlspecialchars($f['forma_name']) ?>
            </span>
            <span style="font-size:11px;color:var(--muted);margin-left:8px">#<?= $f['order_no'] ?></span>
        </div>
        <div style="display:flex;align-items:center;gap:8px">
            <span class="plate-count-badge">🖨 <?= $f['plates_required'] ?> plate<?= $f['plates_required']>1?'s':'' ?></span>
            <span class="badge badge-<?= $f['output_status'] ?>"><?= ucfirst($f['output_status']) ?></span>
        </div>
    </div>
    <div class="forma-card-body">
        <div class="forma-meta">
            <div class="fm-item">
                <span class="fm-label">Pages</span>
                <span class="fm-val">pp <?= $f['page_start'] ?>–<?= $f['page_end'] ?> (<?= $f['page_count'] ?>pp)</span>
            </div>
            <div class="fm-item">
                <span class="fm-label">Type</span>
                <span class="fm-val"><?= ucfirst($f['forma_type']) ?></span>
            </div>
            <div class="fm-item">
                <span class="fm-label">Layout</span>
                <span class="fm-val"><?= $f['cols'] ?>×<?= $f['rows'] ?> (<?= $f['pages_per_side'] ?>pp/side)</span>
            </div>
            <div class="fm-item">
                <span class="fm-label">Print Qty</span>
                <span class="fm-val"><?= number_format($f['print_qty']) ?></span>
            </div>
            <div class="fm-item">
                <span class="fm-label">Plate Size</span>
                <span class="fm-val"><?= $f['plate_width'] ?>×<?= $f['plate_height'] ?>mm</span>
            </div>
            <div class="fm-item">
                <span class="fm-label">Machine</span>
                <span class="fm-val"><?= htmlspecialchars($f['machine']??'—') ?></span>
            </div>
        </div>

        <div class="margin-preview">
            <span class="margin-tag">Bleed: <?= $f['bleed'] ?>mm</span>
            <span class="margin-tag">Gutter: <?= $f['gutter'] ?>mm</span>
            <span class="margin-tag">Gripper: <?= $f['gripper'] ?>mm</span>
            <span class="margin-tag">Trim: <?= $f['trim_outer'] ?>mm</span>
            <span class="margin-tag">Head: <?= $f['head_margin'] ?>mm</span>
            <span class="margin-tag">Spine: <?= $f['spine_margin'] ?>mm</span>
            <span class="margin-tag">Cut: <?= $f['cutting_margin'] ?>mm</span>
        </div>

        <?php if ($f['output_pdf_path']): ?>
        <div style="margin-top:8px">
            <a href="<?= htmlspecialchars($f['output_pdf_path']) ?>" target="_blank" class="pdf-link">📄 Download Generated Plate PDF</a>
        </div>
        <?php endif; ?>

        <div class="forma-actions">
            <a href="forma_ctp_imposition.php?id=<?= $f['id'] ?>" class="btn btn-primary btn-sm">🔲 Imposition Editor</a>
            <a href="forma_ctp_generate.php?id=<?= $f['id'] ?>" class="btn btn-success btn-sm">⚡ Generate PDF</a>
            <a href="forma_ctp_imposition.php?id=<?= $f['id'] ?>&preview=1" class="btn btn-sm" style="background:#f1f5f9;color:var(--dark);border:1px solid var(--border)">👁 Preview</a>
            <form method="POST" style="display:inline" onsubmit="return confirm('Delete forma <?= htmlspecialchars($f['forma_name']) ?>?')">
                <input type="hidden" name="post_action" value="delete_forma">
                <input type="hidden" name="forma_id" value="<?= $f['id'] ?>">
                <button type="submit" class="btn btn-sm btn-danger">🗑</button>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>
</div>
<?php endif; ?>

<!-- FORMA ORDER TABLE (compact) -->
<?php if (!empty($formas)): ?>
<div class="card" style="margin-top:20px">
    <div class="card-header">📊 Forma Order Table</div>
    <div style="overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;font-size:13px">
    <thead>
    <tr style="background:#f1f5f9">
        <th style="padding:10px 14px;text-align:left;font-size:11px;text-transform:uppercase;color:var(--muted)">Order</th>
        <th style="padding:10px 14px;text-align:left;font-size:11px;text-transform:uppercase;color:var(--muted)">Forma</th>
        <th style="padding:10px 14px;text-align:left;font-size:11px;text-transform:uppercase;color:var(--muted)">Pages</th>
        <th style="padding:10px 14px;text-align:left;font-size:11px;text-transform:uppercase;color:var(--muted)">Pg Count</th>
        <th style="padding:10px 14px;text-align:left;font-size:11px;text-transform:uppercase;color:var(--muted)">Old Qty</th>
        <th style="padding:10px 14px;text-align:left;font-size:11px;text-transform:uppercase;color:var(--muted)">Print Qty</th>
        <th style="padding:10px 14px;text-align:left;font-size:11px;text-transform:uppercase;color:var(--muted)">Machine</th>
        <th style="padding:10px 14px;text-align:left;font-size:11px;text-transform:uppercase;color:var(--muted)">Plates</th>
        <th style="padding:10px 14px;text-align:left;font-size:11px;text-transform:uppercase;color:var(--muted)">Status</th>
        <th style="padding:10px 14px;text-align:left;font-size:11px;text-transform:uppercase;color:var(--muted)">Actions</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($formas as $f): ?>
    <tr style="border-bottom:1px solid #f1f5f9">
        <td style="padding:10px 14px"><?= $f['order_no'] ?></td>
        <td style="padding:10px 14px;font-weight:700"><?= htmlspecialchars($f['forma_name']) ?></td>
        <td style="padding:10px 14px;font-size:12px">pp <?= $f['page_start'] ?>–<?= $f['page_end'] ?></td>
        <td style="padding:10px 14px"><?= $f['page_count'] ?></td>
        <td style="padding:10px 14px"><?= number_format($f['old_forma_qty']) ?></td>
        <td style="padding:10px 14px"><?= number_format($f['print_qty']) ?></td>
        <td style="padding:10px 14px;font-size:12px"><?= htmlspecialchars($f['machine']??'—') ?></td>
        <td style="padding:10px 14px;font-weight:700;color:#1a73e8"><?= $f['plates_required'] ?></td>
        <td style="padding:10px 14px"><span class="badge badge-<?= $f['output_status'] ?>"><?= ucfirst($f['output_status']) ?></span></td>
        <td style="padding:10px 14px">
            <div style="display:flex;gap:6px">
                <a href="forma_ctp_imposition.php?id=<?= $f['id'] ?>" class="btn btn-primary btn-sm">🔲 Impose</a>
                <a href="forma_ctp_generate.php?id=<?= $f['id'] ?>" class="btn btn-success btn-sm">⚡ PDF</a>
            </div>
        </td>
    </tr>
    <?php endforeach; ?>
    <tr style="background:#f8fafc;font-weight:700">
        <td colspan="3" style="padding:10px 14px">TOTAL</td>
        <td style="padding:10px 14px"><?= array_sum(array_column($formas,'page_count')) ?></td>
        <td style="padding:10px 14px"><?= number_format(array_sum(array_column($formas,'old_forma_qty'))) ?></td>
        <td style="padding:10px 14px"><?= number_format(max(array_column($formas,'print_qty'))) ?></td>
        <td></td>
        <td style="padding:10px 14px;color:#1a73e8"><?= $total_plates ?></td>
        <td></td>
        <td></td>
    </tr>
    </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

</div>
<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>
