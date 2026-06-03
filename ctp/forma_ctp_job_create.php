<?php
/**
 * forma_ctp_job_create.php — Create or Edit a Job Ticket with Formas
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
redirect_if_not_logged_in();

$job_id  = (int)($_GET['id'] ?? 0);
$msg = $error = '';
$job = null;
$formas_existing = [];

// ── Load for editing ──────────────────────────────────────────
if ($job_id) {
    $s = $conn->prepare("SELECT jt.*, b.book_name, b.class, b.total_pages FROM fctp_job_tickets jt JOIN fctp_books b ON jt.book_code=b.book_code WHERE jt.id=:id");
    $s->execute([':id'=>$job_id]);
    $job = $s->fetch(PDO::FETCH_ASSOC);

    $fs = $conn->prepare("SELECT * FROM fctp_formas WHERE job_ticket_id=:id ORDER BY order_no");
    $fs->execute([':id'=>$job_id]);
    $formas_existing = $fs->fetchAll(PDO::FETCH_ASSOC);
}

// ── Get books list ─────────────────────────────────────────────
$books = $conn->query("SELECT book_code, book_name, class, total_pages FROM fctp_books ORDER BY book_code")->fetchAll(PDO::FETCH_ASSOC);

// ── Handle POST ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $book_code  = strtoupper(trim($_POST['book_code'] ?? ''));
    $jt_code    = strtoupper(trim($_POST['job_ticket_code'] ?? ''));
    $fy         = trim($_POST['fiscal_year'] ?? '');
    $lot        = (int)($_POST['lot_no'] ?? 1);
    $print_qty  = (int)($_POST['print_qty'] ?? 0);
    $page_qty   = (int)($_POST['page_qty'] ?? 0);
    $date_nep   = trim($_POST['date_nep'] ?? '');
    $date_eng   = trim($_POST['date_eng'] ?? '') ?: null;
    $notes      = trim($_POST['notes'] ?? '');
    $edit_id    = (int)($_POST['edit_id'] ?? 0);

    // Formas from dynamic rows
    $forma_names   = $_POST['forma_name']   ?? [];
    $forma_types   = $_POST['forma_type']   ?? [];
    $forma_starts  = $_POST['page_start']   ?? [];
    $forma_ends    = $_POST['page_end']      ?? [];
    $forma_counts  = $_POST['page_count']   ?? [];
    $forma_pqtys   = $_POST['forma_print_qty'] ?? [];
    $forma_old_qty = $_POST['old_forma_qty'] ?? [];
    $forma_machine = $_POST['machine']      ?? [];
    $forma_desc    = $_POST['forma_desc']   ?? [];
    $forma_layout  = $_POST['layout_type']  ?? [];
    $forma_cols    = $_POST['cols']         ?? [];
    $forma_rows    = $_POST['rows']         ?? [];
    $forma_pwidth  = $_POST['plate_width']  ?? [];
    $forma_pheight = $_POST['plate_height'] ?? [];
    $forma_bleed   = $_POST['bleed']        ?? [];
    $forma_gutter  = $_POST['gutter']       ?? [];
    $forma_trim    = $_POST['trim_outer']   ?? [];
    $forma_gripper = $_POST['gripper']      ?? [];
    $forma_head    = $_POST['head_margin']  ?? [];
    $forma_foot    = $_POST['foot_margin']  ?? [];
    $forma_spine   = $_POST['spine_margin'] ?? [];
    $forma_cut     = $_POST['cutting_margin'] ?? [];
    $forma_ids     = $_POST['forma_id']     ?? [];

    if (!$book_code || !$jt_code) {
        $error = 'Book Code and Job Ticket Code are required.';
    } else {
        try {
            $conn->beginTransaction();

            if ($edit_id) {
                $stmt = $conn->prepare("UPDATE fctp_job_tickets SET book_code=:bc,job_ticket_code=:jtc,fiscal_year=:fy,lot_no=:lot,print_qty=:pq,page_qty=:pg,date_nep=:dn,date_eng=:de,notes=:no,updated_at=NOW() WHERE id=:id");
                $stmt->execute([':bc'=>$book_code,':jtc'=>$jt_code,':fy'=>$fy,':lot'=>$lot,':pq'=>$print_qty,':pg'=>$page_qty,':dn'=>$date_nep,':de'=>$date_eng,':no'=>$notes,':id'=>$edit_id]);
                $jt_id = $edit_id;
            } else {
                $stmt = $conn->prepare("INSERT INTO fctp_job_tickets (book_code,job_ticket_code,fiscal_year,lot_no,print_qty,page_qty,date_nep,date_eng,notes,created_by) VALUES(:bc,:jtc,:fy,:lot,:pq,:pg,:dn,:de,:no,:by) RETURNING id");
                $stmt->execute([':bc'=>$book_code,':jtc'=>$jt_code,':fy'=>$fy,':lot'=>$lot,':pq'=>$print_qty,':pg'=>$page_qty,':dn'=>$date_nep,':de'=>$date_eng,':no'=>$notes,':by'=>$_SESSION['username']??'system']);
                $jt_id = $stmt->fetchColumn();
            }

            // Delete removed formas if editing
            if ($edit_id) {
                $keep_ids = array_filter(array_map('intval', $forma_ids));
                if ($keep_ids) {
                    $in = implode(',', $keep_ids);
                    $conn->exec("DELETE FROM fctp_formas WHERE job_ticket_id={$edit_id} AND id NOT IN ({$in})");
                } else {
                    $conn->exec("DELETE FROM fctp_formas WHERE job_ticket_id={$edit_id}");
                }
            }

            // Upsert formas
            foreach ($forma_names as $i => $fname) {
                $fname = trim($fname);
                if (!$fname) continue;

                $ftype   = $forma_types[$i]  ?? 'body';
                $fstart  = (int)($forma_starts[$i] ?? 1);
                $fend    = (int)($forma_ends[$i]   ?? $fstart);
                $fcount  = max(1, $fend - $fstart + 1);
                $fpq     = (int)($forma_pqtys[$i]  ?? $print_qty);
                $foldq   = (int)($forma_old_qty[$i] ?? 0);
                $fmach   = trim($forma_machine[$i]  ?? '');
                $fdesc   = trim($forma_desc[$i]     ?? '');
                $flayout = $forma_layout[$i]  ?? '8up_booklet';
                $fcols   = (int)($forma_cols[$i]    ?? 4);
                $frows   = (int)($forma_rows[$i]    ?? 2);
                $fpw     = (float)($forma_pwidth[$i]  ?? 720);
                $fph     = (float)($forma_pheight[$i] ?? 508);
                $fbleed  = (float)($forma_bleed[$i]   ?? 3);
                $fgut    = (float)($forma_gutter[$i]  ?? 5);
                $ftrim   = (float)($forma_trim[$i]    ?? 8);
                $fgrip   = (float)($forma_gripper[$i] ?? 10);
                $fhead   = (float)($forma_head[$i]    ?? 8);
                $ffoot   = (float)($forma_foot[$i]    ?? 8);
                $fspine  = (float)($forma_spine[$i]   ?? 5);
                $fcut    = (float)($forma_cut[$i]     ?? 3);

                // Calculate pages_per_plate and plates_required
                $ppp = $fcols * $frows; // pages per side
                $ppp_total = $ppp * 2;  // pages per plate (both sides)
                $plates_req = max(1, ceil($fcount / $ppp_total));

                $exist_id = (int)($forma_ids[$i] ?? 0);

                if ($exist_id) {
                    $us = $conn->prepare("UPDATE fctp_formas SET forma_name=:fn,forma_type=:ft,page_start=:ps,page_end=:pe,page_count=:pc,print_qty=:pq,old_forma_qty=:oq,machine=:mc,description=:ds,layout_type=:lt,cols=:co,rows=:rw,pages_per_plate=:ppp,pages_per_side=:pps,plate_width=:pw,plate_height=:ph,bleed=:bl,gutter=:gu,trim_outer=:to,gripper=:gr,head_margin=:hm,foot_margin=:fm,spine_margin=:sm,cutting_margin=:cm,plates_required=:pr,updated_at=NOW() WHERE id=:id");
                    $us->execute([':fn'=>$fname,':ft'=>$ftype,':ps'=>$fstart,':pe'=>$fend,':pc'=>$fcount,':pq'=>$fpq,':oq'=>$foldq,':mc'=>$fmach,':ds'=>$fdesc,':lt'=>$flayout,':co'=>$fcols,':rw'=>$frows,':ppp'=>$ppp_total,':pps'=>$ppp,':pw'=>$fpw,':ph'=>$fph,':bl'=>$fbleed,':gu'=>$fgut,':to'=>$ftrim,':gr'=>$fgrip,':hm'=>$fhead,':fm'=>$ffoot,':sm'=>$fspine,':cm'=>$fcut,':pr'=>$plates_req,':id'=>$exist_id]);
                } else {
                    $ins = $conn->prepare("INSERT INTO fctp_formas (job_ticket_id,book_code,order_no,forma_name,forma_type,page_start,page_end,page_count,print_qty,old_forma_qty,machine,description,layout_type,cols,rows,pages_per_plate,pages_per_side,plate_width,plate_height,bleed,gutter,trim_outer,gripper,head_margin,foot_margin,spine_margin,cutting_margin,plates_required,created_by) VALUES(:jtid,:bc,:on,:fn,:ft,:ps,:pe,:pc,:pq,:oq,:mc,:ds,:lt,:co,:rw,:ppp,:pps,:pw,:ph,:bl,:gu,:to,:gr,:hm,:fm,:sm,:cm,:pr,:by)");
                    $ins->execute([':jtid'=>$jt_id,':bc'=>$book_code,':on'=>$i+1,':fn'=>$fname,':ft'=>$ftype,':ps'=>$fstart,':pe'=>$fend,':pc'=>$fcount,':pq'=>$fpq,':oq'=>$foldq,':mc'=>$fmach,':ds'=>$fdesc,':lt'=>$flayout,':co'=>$fcols,':rw'=>$frows,':ppp'=>$ppp_total,':pps'=>$ppp,':pw'=>$fpw,':ph'=>$fph,':bl'=>$fbleed,':gu'=>$fgut,':to'=>$ftrim,':gr'=>$fgrip,':hm'=>$fhead,':fm'=>$ffoot,':sm'=>$fspine,':cm'=>$fcut,':pr'=>$plates_req,':by'=>$_SESSION['username']??'system']);
                }
            }

            $conn->commit();
            $msg = $edit_id ? 'Job ticket updated.' : 'Job ticket created.';
            header("Location: forma_ctp_job_view.php?id={$jt_id}&msg=" . urlencode($msg));
            exit;
        } catch (Exception $e) {
            $conn->rollBack();
            $error = 'Error: ' . $e->getMessage();
        }
    }
}

// ── Get templates ──────────────────────────────────────────────
$templates = $conn->query("SELECT * FROM fctp_imposition_templates ORDER BY is_default DESC, id")->fetchAll(PDO::FETCH_ASSOC);

require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';
?>
<style>
:root{--primary:#1a73e8;--success:#16a34a;--warning:#d97706;--danger:#dc2626;--dark:#1e293b;--muted:#64748b;--border:#e2e8f0;}
*{box-sizing:border-box;}
.fctp-wrap{max-width:1400px;margin:0 auto;padding:0 16px 40px;}
.fctp-nav{display:flex;gap:8px;flex-wrap:wrap;background:#fff;padding:12px 18px;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,.07);align-items:center;margin-bottom:20px;}
.fctp-nav-title{font-weight:800;font-size:15px;color:var(--dark);margin-right:8px;}
.nav-btn{padding:6px 14px;border-radius:6px;font-size:12.5px;font-weight:600;text-decoration:none;color:var(--muted);border:1.5px solid var(--border);transition:.15s;}
.nav-btn:hover,.nav-btn.active{background:var(--primary);color:#fff;border-color:var(--primary);}
.page-title{font-size:22px;font-weight:800;color:var(--dark);margin-bottom:20px;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 18px;border-radius:7px;font-size:13px;font-weight:600;text-decoration:none;border:none;cursor:pointer;transition:.15s;}
.btn-primary{background:var(--primary);color:#fff;}
.btn-primary:hover{background:#1557b0;}
.btn-success{background:#16a34a;color:#fff;}
.btn-danger{background:var(--danger);color:#fff;}
.btn-sm{padding:5px 12px;font-size:12px;}
.btn-outline{background:#fff;color:var(--primary);border:1.5px solid var(--primary);}
.btn-outline:hover{background:var(--primary);color:#fff;}
.card{background:#fff;border-radius:12px;box-shadow:0 2px 12px rgba(0,0,0,.07);overflow:hidden;margin-bottom:24px;}
.card-header{padding:14px 20px;border-bottom:1px solid var(--border);font-weight:700;font-size:14px;display:flex;align-items:center;justify-content:space-between;}
.card-body{padding:20px;}
.form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;}
.form-group{display:flex;flex-direction:column;gap:4px;}
.form-group label{font-size:11.5px;font-weight:700;color:var(--dark);text-transform:uppercase;letter-spacing:.3px;}
.form-group input,.form-group select,.form-group textarea{padding:8px 11px;border:1.5px solid var(--border);border-radius:6px;font-size:13px;outline:none;transition:.15s;}
.form-group input:focus,.form-group select:focus{border-color:var(--primary);}
.form-full{grid-column:1/-1;}
.alert{padding:12px 18px;border-radius:8px;font-size:13px;margin-bottom:16px;font-weight:500;}
.alert-success{background:#dcfce7;color:#16a34a;border:1px solid #bbf7d0;}
.alert-error{background:#fee2e2;color:var(--danger);border:1px solid #fecaca;}
/* Forma table */
.forma-table-wrap{overflow-x:auto;margin-top:10px;}
.forma-table{width:100%;border-collapse:collapse;font-size:12.5px;min-width:1100px;}
.forma-table th{background:#f1f5f9;padding:8px 10px;text-align:left;font-weight:700;color:var(--dark);font-size:11px;text-transform:uppercase;letter-spacing:.4px;white-space:nowrap;border-bottom:2px solid var(--border);}
.forma-table td{padding:6px 8px;border-bottom:1px solid #f1f5f9;vertical-align:top;}
.forma-table input,.forma-table select{width:100%;padding:6px 8px;border:1.5px solid var(--border);border-radius:5px;font-size:12px;outline:none;}
.forma-table input:focus,.forma-table select:focus{border-color:var(--primary);}
.forma-table tr.forma-row:hover td{background:#f8faff;}
.section-title{font-size:14px;font-weight:700;color:var(--dark);margin-bottom:12px;display:flex;align-items:center;gap:6px;}
.add-forma-btn{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;background:#eff6ff;color:var(--primary);border:1.5px dashed var(--primary);border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;transition:.15s;}
.add-forma-btn:hover{background:var(--primary);color:#fff;border-style:solid;}
.del-forma-btn{background:#fee2e2;color:var(--danger);border:none;border-radius:4px;padding:4px 8px;cursor:pointer;font-size:12px;}
.del-forma-btn:hover{background:var(--danger);color:#fff;}
.expand-btn{background:#f1f5f9;border:1px solid var(--border);border-radius:4px;padding:3px 7px;cursor:pointer;font-size:11px;color:var(--muted);}
.advanced-settings{display:none;background:#fafbff;border:1px solid var(--border);border-radius:6px;padding:10px;margin-top:6px;}
.advanced-settings.open{display:grid;grid-template-columns:repeat(auto-fit,minmax(90px,1fr));gap:8px;}
.adv-group{display:flex;flex-direction:column;gap:3px;}
.adv-group label{font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;}
.adv-group input{padding:5px 7px;border:1px solid var(--border);border-radius:4px;font-size:12px;outline:none;}
.adv-group input:focus{border-color:var(--primary);}
.computed-info{font-size:11px;color:var(--muted);margin-top:3px;}
</style>

<div class="fctp-wrap">
<div class="fctp-nav">
    <span class="fctp-nav-title">🖨️ Forma CTP</span>
    <a href="forma_ctp.php" class="nav-btn">📋 Job Tickets</a>
    <a href="forma_ctp_book.php" class="nav-btn">📚 Books</a>
    <a href="forma_ctp_job_create.php" class="nav-btn active">➕ New Job Ticket</a>
    <a href="forma_ctp_templates.php" class="nav-btn">⚙️ Templates</a>
</div>

<?php if ($error): ?><div class="alert alert-error">❌ <?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="page-title"><?= $job ? '✏️ Edit Job Ticket' : '➕ New Job Ticket' ?></div>

<form method="POST" id="mainForm">
<input type="hidden" name="edit_id" value="<?= $job['id'] ?? 0 ?>">

<!-- ── Job Ticket Info ── -->
<div class="card">
    <div class="card-header">📋 Job Ticket Details</div>
    <div class="card-body">
        <div class="form-grid">
            <div class="form-group">
                <label>Book *</label>
                <select name="book_code" id="bookSelect" onchange="loadBookInfo(this.value)" required>
                    <option value="">— Select Book —</option>
                    <?php foreach ($books as $b): ?>
                    <option value="<?= htmlspecialchars($b['book_code']) ?>" 
                            data-pages="<?= $b['total_pages'] ?>"
                            data-name="<?= htmlspecialchars($b['book_name']) ?>"
                            <?= ($job['book_code']??'')===$b['book_code']?'selected':'' ?>>
                        <?= htmlspecialchars($b['book_code']) ?> — <?= htmlspecialchars($b['book_name']) ?> (Cl.<?= $b['class'] ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Job Ticket Code *</label>
                <input type="text" name="job_ticket_code" value="<?= htmlspecialchars($job['job_ticket_code']??'') ?>" placeholder="e.g. 2082-JT094" required style="text-transform:uppercase">
            </div>
            <div class="form-group">
                <label>Fiscal Year</label>
                <input type="text" name="fiscal_year" value="<?= htmlspecialchars($job['fiscal_year']??'') ?>" placeholder="e.g. 2082">
            </div>
            <div class="form-group">
                <label>Lot No</label>
                <input type="number" name="lot_no" value="<?= $job['lot_no']??1 ?>" min="1">
            </div>
            <div class="form-group">
                <label>Print Qty</label>
                <input type="number" name="print_qty" id="printQty" value="<?= $job['print_qty']??0 ?>" min="0">
            </div>
            <div class="form-group">
                <label>Page Qty</label>
                <input type="number" name="page_qty" id="pageQty" value="<?= $job['page_qty']??0 ?>" min="0">
            </div>
            <div class="form-group">
                <label>Date (Nepali)</label>
                <input type="text" name="date_nep" value="<?= htmlspecialchars($job['date_nep']??'') ?>" placeholder="e.g. 2080-11-17">
            </div>
            <div class="form-group">
                <label>Date (English)</label>
                <input type="date" name="date_eng" value="<?= $job['date_eng']??'' ?>">
            </div>
            <div class="form-group form-full">
                <label>Notes</label>
                <input type="text" name="notes" value="<?= htmlspecialchars($job['notes']??'') ?>" placeholder="Optional notes">
            </div>
        </div>
    </div>
</div>

<!-- ── Forma Details ── -->
<div class="card">
    <div class="card-header">
        <span>📦 Forma Details</span>
        <div style="display:flex;gap:8px;align-items:center">
            <select id="templateSelect" style="padding:5px 10px;border:1.5px solid var(--border);border-radius:6px;font-size:12px;outline:none">
                <option value="">Apply template to new rows...</option>
                <?php foreach ($templates as $t): ?>
                <option value='<?= json_encode($t) ?>'>
                    <?= htmlspecialchars($t['template_name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div class="card-body">
        <div class="forma-table-wrap">
        <table class="forma-table" id="formaTable">
        <thead>
        <tr>
            <th style="width:35px">#</th>
            <th style="width:100px">Forma Name *</th>
            <th style="width:70px">Type</th>
            <th style="width:70px">Pg Start</th>
            <th style="width:70px">Pg End</th>
            <th style="width:60px">Pg Count</th>
            <th style="width:90px">Print Qty</th>
            <th style="width:70px">Old Qty</th>
            <th style="width:100px">Machine</th>
            <th style="width:100px">Layout</th>
            <th style="width:55px">Cols</th>
            <th style="width:55px">Rows</th>
            <th style="width:80px">Plates Req</th>
            <th style="width:80px">Settings</th>
            <th style="width:40px"></th>
        </tr>
        </thead>
        <tbody id="formaTbody">
        <?php if (!empty($formas_existing)): foreach ($formas_existing as $fi => $f): ?>
        <tr class="forma-row" data-index="<?= $fi ?>">
            <td><span class="row-num"><?= $fi+1 ?></span>
                <input type="hidden" name="forma_id[]" value="<?= $f['id'] ?>">
            </td>
            <td><input type="text" name="forma_name[]" value="<?= htmlspecialchars($f['forma_name']) ?>" placeholder="e.g. T-28" required></td>
            <td>
                <select name="forma_type[]">
                    <option value="body" <?= $f['forma_type']==='body'?'selected':'' ?>>Body</option>
                    <option value="cover" <?= $f['forma_type']==='cover'?'selected':'' ?>>Cover</option>
                    <option value="insert" <?= $f['forma_type']==='insert'?'selected':'' ?>>Insert</option>
                </select>
            </td>
            <td><input type="number" name="page_start[]" value="<?= $f['page_start'] ?>" min="1" onchange="calcCount(this)" class="pg-start"></td>
            <td><input type="number" name="page_end[]" value="<?= $f['page_end'] ?>" min="1" onchange="calcCount(this)" class="pg-end"></td>
            <td><input type="number" name="page_count[]" value="<?= $f['page_count'] ?>" min="1" class="pg-count" readonly style="background:#f1f5f9"></td>
            <td><input type="number" name="forma_print_qty[]" value="<?= $f['print_qty'] ?>"></td>
            <td><input type="number" name="old_forma_qty[]" value="<?= $f['old_forma_qty'] ?>"></td>
            <td><input type="text" name="machine[]" value="<?= htmlspecialchars($f['machine']??'') ?>"></td>
            <td>
                <select name="layout_type[]" onchange="updateLayoutInfo(this)">
                    <option value="8up_booklet" <?= $f['layout_type']==='8up_booklet'?'selected':'' ?>>8-Up (4×2)</option>
                    <option value="4up_booklet" <?= $f['layout_type']==='4up_booklet'?'selected':'' ?>>4-Up (2×2)</option>
                    <option value="2up" <?= $f['layout_type']==='2up'?'selected':'' ?>>2-Up (2×1)</option>
                    <option value="cover_4pp" <?= $f['layout_type']==='cover_4pp'?'selected':'' ?>>Cover 4pp</option>
                    <option value="custom" <?= $f['layout_type']==='custom'?'selected':'' ?>>Custom</option>
                </select>
            </td>
            <td><input type="number" name="cols[]" value="<?= $f['cols'] ?>" min="1" max="8" class="cols-inp" onchange="calcPlates(this)"></td>
            <td><input type="number" name="rows[]" value="<?= $f['rows'] ?>" min="1" max="6" class="rows-inp" onchange="calcPlates(this)"></td>
            <td>
                <input type="text" name="plates_display[]" class="plates-disp" value="<?= $f['plates_required'] ?>" readonly style="background:#f0fdf4;color:#16a34a;font-weight:700;text-align:center">
            </td>
            <td>
                <button type="button" class="expand-btn" onclick="toggleAdvanced(this)">⚙️ More</button>
                <div class="advanced-settings">
                    <div class="adv-group"><label>Plate W (mm)</label><input type="number" name="plate_width[]" value="<?= $f['plate_width'] ?>" step="0.1"></div>
                    <div class="adv-group"><label>Plate H (mm)</label><input type="number" name="plate_height[]" value="<?= $f['plate_height'] ?>" step="0.1"></div>
                    <div class="adv-group"><label>Bleed</label><input type="number" name="bleed[]" value="<?= $f['bleed'] ?>" step="0.5"></div>
                    <div class="adv-group"><label>Gutter</label><input type="number" name="gutter[]" value="<?= $f['gutter'] ?>" step="0.5"></div>
                    <div class="adv-group"><label>Trim Outer</label><input type="number" name="trim_outer[]" value="<?= $f['trim_outer'] ?>" step="0.5"></div>
                    <div class="adv-group"><label>Gripper</label><input type="number" name="gripper[]" value="<?= $f['gripper'] ?>" step="0.5"></div>
                    <div class="adv-group"><label>Head</label><input type="number" name="head_margin[]" value="<?= $f['head_margin'] ?>" step="0.5"></div>
                    <div class="adv-group"><label>Foot</label><input type="number" name="foot_margin[]" value="<?= $f['foot_margin'] ?>" step="0.5"></div>
                    <div class="adv-group"><label>Spine</label><input type="number" name="spine_margin[]" value="<?= $f['spine_margin'] ?>" step="0.5"></div>
                    <div class="adv-group"><label>Cut Margin</label><input type="number" name="cutting_margin[]" value="<?= $f['cutting_margin'] ?>" step="0.5"></div>
                    <div class="adv-group" style="grid-column:1/-1"><label>Description</label><input type="text" name="forma_desc[]" value="<?= htmlspecialchars($f['description']??'') ?>"></div>
                </div>
            </td>
            <td><button type="button" class="del-forma-btn" onclick="deleteRow(this)">✕</button></td>
        </tr>
        <?php endforeach; else: ?>
        <!-- Default empty row -->
        <?php
        $defaults = [
            ['T-28','body',1,32,32,100000,0,'','8up_booklet',4,2],
            ['29-44','body',33,48,16,100000,0,'','4up_booklet',2,2],
            ['COVER','cover',1,4,4,25000,0,'','cover_4pp',2,1],
        ];
        foreach ($defaults as $di => $d):
        ?>
        <tr class="forma-row" data-index="<?= $di ?>">
            <td><span class="row-num"><?= $di+1 ?></span>
                <input type="hidden" name="forma_id[]" value="0">
            </td>
            <td><input type="text" name="forma_name[]" value="<?= $d[0] ?>" placeholder="e.g. T-28"></td>
            <td>
                <select name="forma_type[]">
                    <option value="body" <?= $d[1]==='body'?'selected':'' ?>>Body</option>
                    <option value="cover" <?= $d[1]==='cover'?'selected':'' ?>>Cover</option>
                    <option value="insert" <?= $d[1]==='insert'?'selected':'' ?>>Insert</option>
                </select>
            </td>
            <td><input type="number" name="page_start[]" value="<?= $d[2] ?>" min="1" onchange="calcCount(this)" class="pg-start"></td>
            <td><input type="number" name="page_end[]" value="<?= $d[3] ?>" min="1" onchange="calcCount(this)" class="pg-end"></td>
            <td><input type="number" name="page_count[]" value="<?= $d[4] ?>" min="1" class="pg-count" readonly style="background:#f1f5f9"></td>
            <td><input type="number" name="forma_print_qty[]" value="<?= $d[5] ?>"></td>
            <td><input type="number" name="old_forma_qty[]" value="<?= $d[6] ?>"></td>
            <td><input type="text" name="machine[]" value="<?= $d[7] ?>"></td>
            <td>
                <select name="layout_type[]">
                    <option value="8up_booklet" <?= $d[8]==='8up_booklet'?'selected':'' ?>>8-Up (4×2)</option>
                    <option value="4up_booklet" <?= $d[8]==='4up_booklet'?'selected':'' ?>>4-Up (2×2)</option>
                    <option value="2up">2-Up (2×1)</option>
                    <option value="cover_4pp" <?= $d[8]==='cover_4pp'?'selected':'' ?>>Cover 4pp</option>
                    <option value="custom">Custom</option>
                </select>
            </td>
            <td><input type="number" name="cols[]" value="<?= $d[9] ?>" min="1" max="8" class="cols-inp" onchange="calcPlates(this)"></td>
            <td><input type="number" name="rows[]" value="<?= $d[10] ?>" min="1" max="6" class="rows-inp" onchange="calcPlates(this)"></td>
            <td>
                <input type="text" name="plates_display[]" class="plates-disp" value="?" readonly style="background:#f0fdf4;color:#16a34a;font-weight:700;text-align:center">
            </td>
            <td>
                <button type="button" class="expand-btn" onclick="toggleAdvanced(this)">⚙️ More</button>
                <div class="advanced-settings">
                    <div class="adv-group"><label>Plate W</label><input type="number" name="plate_width[]" value="720" step="0.1"></div>
                    <div class="adv-group"><label>Plate H</label><input type="number" name="plate_height[]" value="508" step="0.1"></div>
                    <div class="adv-group"><label>Bleed</label><input type="number" name="bleed[]" value="3" step="0.5"></div>
                    <div class="adv-group"><label>Gutter</label><input type="number" name="gutter[]" value="5" step="0.5"></div>
                    <div class="adv-group"><label>Trim Outer</label><input type="number" name="trim_outer[]" value="8" step="0.5"></div>
                    <div class="adv-group"><label>Gripper</label><input type="number" name="gripper[]" value="10" step="0.5"></div>
                    <div class="adv-group"><label>Head</label><input type="number" name="head_margin[]" value="8" step="0.5"></div>
                    <div class="adv-group"><label>Foot</label><input type="number" name="foot_margin[]" value="8" step="0.5"></div>
                    <div class="adv-group"><label>Spine</label><input type="number" name="spine_margin[]" value="5" step="0.5"></div>
                    <div class="adv-group"><label>Cut</label><input type="number" name="cutting_margin[]" value="3" step="0.5"></div>
                    <div class="adv-group" style="grid-column:1/-1"><label>Description</label><input type="text" name="forma_desc[]" value=""></div>
                </div>
            </td>
            <td><button type="button" class="del-forma-btn" onclick="deleteRow(this)">✕</button></td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
        </table>
        </div>

        <div style="margin-top:14px;display:flex;gap:10px;align-items:center">
            <button type="button" class="add-forma-btn" onclick="addFormaRow()">➕ Add Forma Row</button>
            <span style="font-size:12px;color:var(--muted)">Rows: <strong id="rowCount">0</strong></span>
        </div>
    </div>
</div>

<!-- FORM ACTIONS -->
<div style="display:flex;gap:12px;flex-wrap:wrap">
    <button type="submit" class="btn btn-primary" style="min-width:160px">💾 Save Job Ticket</button>
    <a href="forma_ctp.php" class="btn btn-outline">Cancel</a>
</div>
</form>
</div>

<script>
const LAYOUT_PRESETS = {
    '8up_booklet': {cols:4, rows:2, plate_width:720, plate_height:508},
    '4up_booklet': {cols:2, rows:2, plate_width:720, plate_height:508},
    '2up':         {cols:2, rows:1, plate_width:720, plate_height:508},
    'cover_4pp':   {cols:2, rows:1, plate_width:720, plate_height:508},
    'custom':      {cols:4, rows:2, plate_width:720, plate_height:508},
};

function calcCount(el) {
    const row = el.closest('tr');
    const start = parseInt(row.querySelector('.pg-start').value) || 1;
    const end   = parseInt(row.querySelector('.pg-end').value)   || start;
    row.querySelector('.pg-count').value = Math.max(1, end - start + 1);
    calcPlates(el);
}

function calcPlates(el) {
    const row = el.closest('tr');
    const count = parseInt(row.querySelector('.pg-count').value) || 1;
    const cols  = parseInt(row.querySelector('.cols-inp').value) || 4;
    const rows  = parseInt(row.querySelector('.rows-inp').value) || 2;
    const ppp   = cols * rows * 2; // both sides
    const plates = Math.max(1, Math.ceil(count / ppp));
    const disp  = row.querySelector('.plates-disp');
    if (disp) disp.value = plates + ' plate' + (plates>1?'s':'');
}

function toggleAdvanced(btn) {
    const adv = btn.nextElementSibling;
    adv.classList.toggle('open');
    btn.textContent = adv.classList.contains('open') ? '▲ Less' : '⚙️ More';
}

function addFormaRow(defaults) {
    const tbody = document.getElementById('formaTbody');
    const idx = tbody.querySelectorAll('tr.forma-row').length;
    const d = defaults || {};
    const tr = document.createElement('tr');
    tr.className = 'forma-row';
    tr.dataset.index = idx;
    tr.innerHTML = `
    <td><span class="row-num">${idx+1}</span><input type="hidden" name="forma_id[]" value="0"></td>
    <td><input type="text" name="forma_name[]" value="${d.fn||''}" placeholder="e.g. T-28"></td>
    <td><select name="forma_type[]"><option value="body">Body</option><option value="cover">Cover</option><option value="insert">Insert</option></select></td>
    <td><input type="number" name="page_start[]" value="${d.ps||1}" min="1" onchange="calcCount(this)" class="pg-start"></td>
    <td><input type="number" name="page_end[]" value="${d.pe||1}" min="1" onchange="calcCount(this)" class="pg-end"></td>
    <td><input type="number" name="page_count[]" value="${d.pc||1}" min="1" class="pg-count" readonly style="background:#f1f5f9"></td>
    <td><input type="number" name="forma_print_qty[]" value="${d.pq||document.getElementById('printQty').value||0}"></td>
    <td><input type="number" name="old_forma_qty[]" value="0"></td>
    <td><input type="text" name="machine[]" value=""></td>
    <td><select name="layout_type[]" onchange="applyLayoutPreset(this)">
        <option value="8up_booklet" ${(!d.lt||d.lt==='8up_booklet')?'selected':''}>8-Up (4×2)</option>
        <option value="4up_booklet" ${d.lt==='4up_booklet'?'selected':''}>4-Up (2×2)</option>
        <option value="2up" ${d.lt==='2up'?'selected':''}>2-Up (2×1)</option>
        <option value="cover_4pp" ${d.lt==='cover_4pp'?'selected':''}>Cover 4pp</option>
        <option value="custom">Custom</option>
    </select></td>
    <td><input type="number" name="cols[]" value="${d.cols||4}" min="1" max="8" class="cols-inp" onchange="calcPlates(this)"></td>
    <td><input type="number" name="rows[]" value="${d.rows||2}" min="1" max="6" class="rows-inp" onchange="calcPlates(this)"></td>
    <td><input type="text" name="plates_display[]" class="plates-disp" value="?" readonly style="background:#f0fdf4;color:#16a34a;font-weight:700;text-align:center"></td>
    <td>
        <button type="button" class="expand-btn" onclick="toggleAdvanced(this)">⚙️ More</button>
        <div class="advanced-settings">
            <div class="adv-group"><label>Plate W</label><input type="number" name="plate_width[]" value="${d.pw||720}" step="0.1"></div>
            <div class="adv-group"><label>Plate H</label><input type="number" name="plate_height[]" value="${d.ph||508}" step="0.1"></div>
            <div class="adv-group"><label>Bleed</label><input type="number" name="bleed[]" value="3" step="0.5"></div>
            <div class="adv-group"><label>Gutter</label><input type="number" name="gutter[]" value="5" step="0.5"></div>
            <div class="adv-group"><label>Trim Outer</label><input type="number" name="trim_outer[]" value="8" step="0.5"></div>
            <div class="adv-group"><label>Gripper</label><input type="number" name="gripper[]" value="10" step="0.5"></div>
            <div class="adv-group"><label>Head</label><input type="number" name="head_margin[]" value="8" step="0.5"></div>
            <div class="adv-group"><label>Foot</label><input type="number" name="foot_margin[]" value="8" step="0.5"></div>
            <div class="adv-group"><label>Spine</label><input type="number" name="spine_margin[]" value="5" step="0.5"></div>
            <div class="adv-group"><label>Cut</label><input type="number" name="cutting_margin[]" value="3" step="0.5"></div>
            <div class="adv-group" style="grid-column:1/-1"><label>Description</label><input type="text" name="forma_desc[]" value=""></div>
        </div>
    </td>
    <td><button type="button" class="del-forma-btn" onclick="deleteRow(this)">✕</button></td>`;
    tbody.appendChild(tr);
    updateRowNums();
    calcPlates(tr.querySelector('.pg-start'));
}

function applyLayoutPreset(sel) {
    const row = sel.closest('tr');
    const preset = LAYOUT_PRESETS[sel.value] || LAYOUT_PRESETS['8up_booklet'];
    row.querySelector('.cols-inp').value = preset.cols;
    row.querySelector('.rows-inp').value = preset.rows;
    const adv = row.querySelector('.advanced-settings');
    if (adv) {
        const inputs = adv.querySelectorAll('input[name="plate_width[]"]');
        if (inputs[0]) inputs[0].value = preset.plate_width;
        const hinputs = adv.querySelectorAll('input[name="plate_height[]"]');
        if (hinputs[0]) hinputs[0].value = preset.plate_height;
    }
    calcPlates(sel);
}

function deleteRow(btn) {
    if (document.querySelectorAll('.forma-row').length <= 1) { alert('At least one forma row required.'); return; }
    btn.closest('tr').remove();
    updateRowNums();
}

function updateRowNums() {
    document.querySelectorAll('.forma-row').forEach((tr, i) => {
        const num = tr.querySelector('.row-num');
        if (num) num.textContent = i+1;
        tr.dataset.index = i;
    });
    document.getElementById('rowCount').textContent = document.querySelectorAll('.forma-row').length;
}

function loadBookInfo(code) {
    const sel = document.getElementById('bookSelect');
    const opt = sel.options[sel.selectedIndex];
    if (opt && opt.dataset.pages) {
        document.getElementById('pageQty').value = opt.dataset.pages;
    }
}

// Init
document.querySelectorAll('.forma-row').forEach(tr => {
    calcPlates(tr.querySelector('.pg-start') || tr.querySelector('.pg-count'));
});
updateRowNums();
</script>
<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>
