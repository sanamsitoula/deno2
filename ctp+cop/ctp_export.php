<?php
/**
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║              CTP EXPORT MODULE — Print Ready Copy Generator             ║
 * ║         Professional Imposition Engine for Press-Grade Output           ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * v2.0 — Improvements:
 *   • PDF upload with automatic page count (no Composer/FPDI needed here)
 *   • Searchable book dropdown (Choices.js via CDN)
 *   • Navigation links to all CTP sub-pages
 */

ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

redirect_if_not_logged_in();

// ─── DEFAULT MARGIN PRESETS (all in mm) ──────────────────────────────────────
define('DEFAULT_BLEED',        3.0);
define('DEFAULT_GUTTER',       5.0);
define('DEFAULT_TRIM_OUTER',   8.0);
define('DEFAULT_GRIPPER',     10.0);
define('DEFAULT_HEAD_MARGIN',  8.0);
define('DEFAULT_FOOT_MARGIN',  8.0);

// ─── IMPOSITION LAYOUTS ───────────────────────────────────────────────────────
$IMPOSITION_LAYOUTS = [
    '8up_booklet'  => ['label'=>'8-Up Booklet (4×2)','cols'=>4,'rows'=>2,'pages_per_sheet'=>8,'signature_size'=>16,'description'=>'Standard 32-page signature. 4 columns × 2 rows per side.'],
    '4up_booklet'  => ['label'=>'4-Up Booklet (2×2)','cols'=>2,'rows'=>2,'pages_per_sheet'=>4,'signature_size'=>8,'description'=>'16-page signature. 2 columns × 2 rows per side.'],
    '2up_booklet'  => ['label'=>'2-Up (2×1)','cols'=>2,'rows'=>1,'pages_per_sheet'=>2,'signature_size'=>4,'description'=>'Simple 2-up side-by-side per sheet.'],
    '16up_booklet' => ['label'=>'16-Up (4×4)','cols'=>4,'rows'=>4,'pages_per_sheet'=>16,'signature_size'=>32,'description'=>'64-page signature. 4 columns × 4 rows per side.'],
    'custom'       => ['label'=>'Custom Layout','cols'=>2,'rows'=>2,'pages_per_sheet'=>4,'signature_size'=>8,'description'=>'Define your own column/row configuration.'],
];

// ─── STANDARD SHEET SIZES (mm) ────────────────────────────────────────────────
$SHEET_SIZES = [
    'SRA3'  => ['w'=>450,  'h'=>320,  'label'=>'SRA3 (450×320mm)'],
    'SRA2'  => ['w'=>640,  'h'=>450,  'label'=>'SRA2 (640×450mm)'],
    'SRA1'  => ['w'=>900,  'h'=>640,  'label'=>'SRA1 (900×640mm)'],
    'B1'    => ['w'=>1000, 'h'=>707,  'label'=>'B1 (1000×707mm)'],
    'A1'    => ['w'=>841,  'h'=>594,  'label'=>'A1 (841×594mm)'],
    'A2'    => ['w'=>594,  'h'=>420,  'label'=>'A2 (594×420mm)'],
    'custom'=> ['w'=>720,  'h'=>508,  'label'=>'Custom Size'],
];

// ─── FETCH DATA ───────────────────────────────────────────────────────────────
$books    = [];
$ctp_jobs = [];
$error_msg = $success_msg = null;

try {
    $stmt = $conn->query("
        SELECT DISTINCT d.book_code, b.book_name,
               COUNT(d.id) AS deno_count
          FROM deno d
          LEFT JOIN books b ON d.book_code = b.book_code
         WHERE d.deleted_at IS NULL
         GROUP BY d.book_code, b.book_name
         ORDER BY b.book_name
    ");
    $books = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $books = []; }

try {
    $ctp_jobs = $conn->query("
        SELECT * FROM ctp_export_jobs ORDER BY created_at DESC LIMIT 50
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $ctp_jobs = []; }

// ─── HANDLE FORM SUBMISSION ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'init_ctp_tables') {
        try {
            $conn->exec("
                CREATE TABLE IF NOT EXISTS ctp_export_jobs (
                    id              SERIAL PRIMARY KEY,
                    job_name        VARCHAR(200)  NOT NULL,
                    book_code       VARCHAR(100),
                    original_pdf    TEXT,
                    pdf_filename    VARCHAR(300),
                    total_pages     INTEGER,
                    padded_pages    INTEGER,
                    blank_inserted  INTEGER       DEFAULT 0,
                    layout_type     VARCHAR(50)   DEFAULT '8up_booklet',
                    cols            INTEGER       DEFAULT 4,
                    rows            INTEGER       DEFAULT 2,
                    signature_size  INTEGER       DEFAULT 16,
                    sheet_width     FLOAT         DEFAULT 720,
                    sheet_height    FLOAT         DEFAULT 508,
                    bleed           FLOAT         DEFAULT 3,
                    gutter          FLOAT         DEFAULT 5,
                    trim_outer      FLOAT         DEFAULT 8,
                    gripper         FLOAT         DEFAULT 10,
                    head_margin     FLOAT         DEFAULT 8,
                    foot_margin     FLOAT         DEFAULT 8,
                    output_pdf      TEXT,
                    status          VARCHAR(30)   DEFAULT 'pending',
                    page_order_json TEXT,
                    notes           TEXT,
                    created_by      VARCHAR(100),
                    created_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
                    updated_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP
                )
            ");
            $success_msg = 'CTP tables initialized successfully.';
        } catch (Exception $e) { $error_msg = 'Table init failed: ' . $e->getMessage(); }
    }

    elseif ($action === 'create_ctp_job') {
        try {
            $conn->beginTransaction();

            $layout   = $_POST['layout_type'] ?? '8up_booklet';
            $cfg      = $IMPOSITION_LAYOUTS[$layout] ?? $IMPOSITION_LAYOUTS['8up_booklet'];
            $cols     = $layout === 'custom' ? (int)$_POST['custom_cols'] : $cfg['cols'];
            $rows     = $layout === 'custom' ? (int)$_POST['custom_rows'] : $cfg['rows'];
            $sig_size = $cols * $rows * 2;

            $total_pages = (int)($_POST['total_pages'] ?? 0);
            $pad_to      = ($total_pages % $sig_size !== 0)
                         ? $total_pages + ($sig_size - ($total_pages % $sig_size))
                         : $total_pages;
            $blank_count = $pad_to - $total_pages;

            $sheet_preset = $_POST['sheet_size'] ?? 'custom';
            $sw = isset($SHEET_SIZES[$sheet_preset]) ? $SHEET_SIZES[$sheet_preset]['w'] : (float)$_POST['sheet_w'];
            $sh = isset($SHEET_SIZES[$sheet_preset]) ? $SHEET_SIZES[$sheet_preset]['h'] : (float)$_POST['sheet_h'];

            $page_order = generateImpositionOrder($pad_to, $cols, $rows);

            $stmt = $conn->prepare("
                INSERT INTO ctp_export_jobs
                    (job_name, book_code, original_pdf, pdf_filename, total_pages, padded_pages,
                     blank_inserted, layout_type, cols, rows, signature_size,
                     sheet_width, sheet_height, bleed, gutter, trim_outer,
                     gripper, head_margin, foot_margin, page_order_json,
                     notes, created_by, status)
                VALUES
                    (:job_name, :book_code, :original_pdf, :pdf_filename, :total_pages, :padded_pages,
                     :blank_inserted, :layout_type, :cols, :rows, :signature_size,
                     :sheet_width, :sheet_height, :bleed, :gutter, :trim_outer,
                     :gripper, :head_margin, :foot_margin, :page_order_json,
                     :notes, :created_by, 'pending')
            ");
            $stmt->execute([
                ':job_name'        => $_POST['job_name'],
                ':book_code'       => $_POST['book_code'] ?? null,
                ':original_pdf'    => $_POST['uploaded_pdf_path'] ?? null,
                ':pdf_filename'    => $_POST['uploaded_pdf_name'] ?? null,
                ':total_pages'     => $total_pages,
                ':padded_pages'    => $pad_to,
                ':blank_inserted'  => $blank_count,
                ':layout_type'     => $layout,
                ':cols'            => $cols,
                ':rows'            => $rows,
                ':signature_size'  => $sig_size,
                ':sheet_width'     => $sw,
                ':sheet_height'    => $sh,
                ':bleed'           => (float)($_POST['bleed']       ?? DEFAULT_BLEED),
                ':gutter'          => (float)($_POST['gutter']      ?? DEFAULT_GUTTER),
                ':trim_outer'      => (float)($_POST['trim_outer']  ?? DEFAULT_TRIM_OUTER),
                ':gripper'         => (float)($_POST['gripper']     ?? DEFAULT_GRIPPER),
                ':head_margin'     => (float)($_POST['head_margin'] ?? DEFAULT_HEAD_MARGIN),
                ':foot_margin'     => (float)($_POST['foot_margin'] ?? DEFAULT_FOOT_MARGIN),
                ':page_order_json' => json_encode($page_order, JSON_PRETTY_PRINT),
                ':notes'           => $_POST['notes'] ?? null,
                ':created_by'      => $_SESSION['username'] ?? 'system',
            ]);

            $conn->commit();
            header('Location: ' . $_SERVER['PHP_SELF'] . '?success=1');
            exit;
        } catch (Exception $e) {
            $conn->rollBack();
            $error_msg = $e->getMessage();
        }
    }

    elseif ($action === 'delete_job') {
        $conn->prepare("DELETE FROM ctp_export_jobs WHERE id = :id")
             ->execute([':id' => (int)$_POST['job_id']]);
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

// ─── IMPOSITION ORDER GENERATOR ───────────────────────────────────────────────
function generateImpositionOrder(int $total_pages, int $cols, int $rows): array {
    $pages_per_side  = $cols * $rows;
    $pages_per_sheet = $pages_per_side * 2;
    if ($total_pages % $pages_per_sheet !== 0) {
        $total_pages += $pages_per_sheet - ($total_pages % $pages_per_sheet);
    }
    $sheets = [];
    $left   = 1;
    $right  = $total_pages;
    while ($left < $right) {
        $front = [];
        $back  = [];
        for ($i = 0; $i < $pages_per_side / 2; $i++) {
            $front[] = ['right_pg'=>$right,   'left_pg'=>$left,   'col'=>$i*2,   'row'=>0];
            $front[] = ['right_pg'=>$right-2, 'left_pg'=>$left+2, 'col'=>$i*2+1, 'row'=>0];
            $back[]  = ['left_pg'=>$left+1,   'right_pg'=>$right-1,'col'=>$i*2,   'row'=>0];
            $back[]  = ['left_pg'=>$left+3,   'right_pg'=>$right-3,'col'=>$i*2+1, 'row'=>0];
            $left  += 4;
            $right -= 4;
        }
        $sheets[] = ['front'=>$front,'back'=>$back,'sheet_index'=>count($sheets)+1];
    }
    return $sheets;
}

// View mode
$view_job = null;
if (isset($_GET['view_id'])) {
    try {
        $s = $conn->prepare("SELECT * FROM ctp_export_jobs WHERE id = :id");
        $s->execute([':id'=>(int)$_GET['view_id']]);
        $view_job = $s->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

?>
<!-- Choices.js for searchable select -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/choices.js/public/assets/styles/choices.min.css">
<script src="https://cdn.jsdelivr.net/npm/choices.js/public/assets/scripts/choices.min.js"></script>

<style>
:root {
    --primary:#1a73e8; --success:#28a745; --warning:#ffc107;
    --danger:#dc3545; --dark:#1e293b; --light-bg:#f0f4f8;
    --card-bg:#ffffff; --border:#e2e8f0; --text:#334155;
    --muted:#64748b; --radius:10px; --shadow:0 2px 12px rgba(0,0,0,0.08);
}
body { font-family:'Segoe UI',sans-serif; background:var(--light-bg); color:var(--text); }
.ctp-container { max-width:1500px; margin:0 auto; padding:24px; }

/* ── Top nav bar ── */
.ctp-nav {
    display:flex; gap:8px; flex-wrap:wrap; margin-bottom:20px;
    background:#fff; padding:14px 18px; border-radius:var(--radius);
    box-shadow:var(--shadow); align-items:center;
}
.ctp-nav-title { font-weight:800; font-size:15px; color:var(--dark); margin-right:10px; }
.nav-link {
    padding:7px 16px; border-radius:6px; font-size:13px; font-weight:600;
    text-decoration:none; color:var(--muted); border:1.5px solid var(--border);
    transition:all 0.2s; display:inline-flex; align-items:center; gap:6px;
}
.nav-link:hover, .nav-link.active { background:var(--primary); color:#fff; border-color:var(--primary); }

.page-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:24px; flex-wrap:wrap; gap:12px; }
.page-title { font-size:26px; font-weight:700; color:var(--dark); }
.page-title span { color:var(--primary); }

.card { background:var(--card-bg); border-radius:var(--radius); box-shadow:var(--shadow); padding:28px; margin-bottom:24px; }
.card-title { font-size:17px; font-weight:700; color:var(--dark); margin-bottom:20px; display:flex; align-items:center; gap:10px; border-bottom:2px solid var(--border); padding-bottom:12px; }
.form-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:18px; }
.form-group { display:flex; flex-direction:column; gap:6px; }
.form-group label { font-size:13px; font-weight:600; color:var(--muted); text-transform:uppercase; }
.form-control { padding:10px 14px; border:1.5px solid var(--border); border-radius:7px; font-size:14px; transition:border 0.2s; background:#fff; width:100%; box-sizing:border-box; }
.form-control:focus { outline:none; border-color:var(--primary); box-shadow:0 0 0 3px rgba(26,115,232,0.12); }
.section-label { font-size:13px; font-weight:700; text-transform:uppercase; letter-spacing:0.8px; color:var(--primary); margin:22px 0 12px; display:flex; align-items:center; gap:8px; }
.section-label::after { content:''; flex:1; height:1px; background:var(--border); }

/* ── Upload zone ── */
.upload-zone {
    border:2.5px dashed var(--border); border-radius:10px; padding:32px;
    text-align:center; cursor:pointer; transition:all 0.25s; background:#f8fafc;
    position:relative;
}
.upload-zone:hover, .upload-zone.dragover { border-color:var(--primary); background:#e8f0fe; }
.upload-zone input[type=file] { position:absolute; inset:0; opacity:0; cursor:pointer; width:100%; }
.upload-icon { font-size:48px; margin-bottom:10px; }
.upload-text { font-size:15px; font-weight:600; color:var(--dark); margin-bottom:6px; }
.upload-hint { font-size:12px; color:var(--muted); }
.upload-progress { margin-top:12px; display:none; }
.progress-bar-wrap { background:#e2e8f0; border-radius:20px; height:8px; overflow:hidden; margin-top:8px; }
.progress-bar-fill { height:100%; background:var(--primary); border-radius:20px; transition:width 0.3s; width:0%; }
.upload-result { margin-top:14px; padding:14px 18px; border-radius:8px; display:none; }
.upload-result.success { background:#d4edda; color:#155724; border:1px solid #c3e6cb; }
.upload-result.error   { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; }

/* ── Layout selector ── */
.layout-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(160px, 1fr)); gap:12px; margin-bottom:20px; }
.layout-option input[type=radio] { display:none; }
.layout-option label { display:block; padding:14px; border:2px solid var(--border); border-radius:8px; cursor:pointer; text-align:center; transition:all 0.2s; background:#fff; }
.layout-option input:checked + label { border-color:var(--primary); background:#e8f0fe; }
.layout-icon { font-size:26px; display:block; margin-bottom:4px; }
.layout-name { font-weight:700; font-size:13px; display:block; }
.layout-desc { font-size:10px; color:var(--muted); margin-top:3px; display:block; }

/* ── Margin sliders ── */
.margin-row { display:flex; align-items:center; gap:14px; margin-bottom:14px; }
.margin-label { width:130px; font-size:13px; font-weight:600; flex-shrink:0; }
.margin-slider { flex:1; accent-color:var(--primary); }
.margin-val { width:70px; text-align:center; padding:6px 8px; border:1.5px solid var(--border); border-radius:6px; font-size:14px; font-weight:700; }

/* ── Sheet preview ── */
.sheet-preview-wrap { display:flex; justify-content:center; margin:16px 0; }
.sheet-canvas-container { background:#fff; border:2px solid var(--border); border-radius:8px; padding:14px; box-shadow:var(--shadow); }

/* ── Buttons ── */
.btn { padding:10px 22px; border:none; border-radius:7px; font-size:14px; font-weight:700; cursor:pointer; display:inline-flex; align-items:center; gap:8px; transition:all 0.2s; text-decoration:none; }
.btn-primary  { background:var(--primary); color:#fff; }
.btn-success  { background:var(--success); color:#fff; }
.btn-warning  { background:var(--warning); color:#212529; }
.btn-danger   { background:var(--danger);  color:#fff; }
.btn-ghost    { background:#f1f5f9; color:var(--text); border:1px solid var(--border); }
.btn:hover    { filter:brightness(1.08); transform:translateY(-1px); box-shadow:0 4px 10px rgba(0,0,0,0.12); }
.btn-sm       { padding:6px 14px; font-size:12px; }

/* ── Alerts ── */
.alert { padding:14px 18px; border-radius:8px; margin-bottom:18px; font-size:14px; }
.alert-success { background:#d4edda; color:#155724; border:1px solid #c3e6cb; }
.alert-danger  { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; }
.alert-info    { background:#d1ecf1; color:#0c5460; border:1px solid #bee5eb; }
.alert-warning { background:#fff3cd; color:#856404; border:1px solid #ffd96a; }

/* ── Jobs table ── */
.data-table { width:100%; border-collapse:collapse; font-size:14px; }
.data-table th { background:#f8fafc; padding:11px 14px; text-align:left; font-size:12px; font-weight:700; color:var(--muted); text-transform:uppercase; border-bottom:2px solid var(--border); }
.data-table td { padding:11px 14px; border-bottom:1px solid var(--border); vertical-align:middle; }
.data-table tbody tr:hover { background:#f8fafc; }
.badge { display:inline-block; padding:3px 10px; border-radius:20px; font-size:11px; font-weight:700; }
.badge-pending    { background:#fff3cd; color:#856404; }
.badge-processing { background:#cce5ff; color:#004085; }
.badge-complete   { background:#d4edda; color:#155724; }
.badge-failed     { background:#f8d7da; color:#721c24; }

/* ── Summary ── */
.summary-row { display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid var(--border); font-size:14px; }
.summary-row:last-child { border:none; font-weight:700; font-size:15px; color:var(--primary); }

/* ── Tabs ── */
.tabs { display:flex; gap:4px; margin-bottom:20px; border-bottom:2px solid var(--border); }
.tab-btn { padding:10px 20px; border:none; background:none; font-size:14px; font-weight:600; color:var(--muted); cursor:pointer; border-radius:6px 6px 0 0; border-bottom:3px solid transparent; margin-bottom:-2px; transition:all 0.2s; }
.tab-btn.active { color:var(--primary); border-bottom-color:var(--primary); background:#e8f0fe; }
.tab-content { display:none; }
.tab-content.active { display:block; }
.hidden { display:none; }

/* ── Page order grid ── */
.po-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(280px, 1fr)); gap:16px; }
.po-sheet-card { background:#f8fafc; border:1px solid var(--border); border-radius:8px; padding:16px; }
.po-sheet-title { font-weight:700; color:var(--primary); margin-bottom:10px; font-size:13px; }
.po-side-label { font-size:11px; text-transform:uppercase; color:var(--muted); font-weight:700; margin-bottom:6px; }
.po-pairs { display:flex; flex-wrap:wrap; gap:6px; margin-bottom:10px; }
.po-pair { background:#fff; border:1.5px solid var(--border); border-radius:6px; padding:5px 10px; font-size:12px; font-weight:700; color:var(--dark); }
.po-pair.front { border-color:#4caf50; }
.po-pair.back  { border-color:#2196f3; }
.blank-badge   { background:#e0e0e0; color:#666; border-color:#bbb; }

/* ── Margin guide ── */
.margin-guide-card { background:#fff8e1; border:1px solid #ffe082; border-radius:10px; padding:20px; margin-bottom:24px; }
.margin-guide-card h3 { margin:0 0 14px; font-size:15px; color:#795548; }
.margin-table { width:100%; border-collapse:collapse; font-size:13px; }
.margin-table th { background:#fff3e0; padding:8px 10px; text-align:left; font-weight:700; }
.margin-table td { padding:8px 10px; border-bottom:1px solid #ffe0b2; }
.tr-bleed   td:first-child { color:#c62828; font-weight:600; }
.tr-gutter  td:first-child { color:#f57f17; font-weight:600; }
.tr-trim    td:first-child { color:#2e7d32; font-weight:600; }
.tr-gripper td:first-child { color:#1565c0; font-weight:600; }
.margin-note { margin-top:12px; font-size:13px; color:#5d4037; background:#fff3e0; padding:10px 14px; border-radius:6px; }

/* Choices.js override */
.choices { width:100%; }
.choices__inner { border:1.5px solid var(--border); border-radius:7px; font-size:14px; padding:4px 8px; min-height:42px; }
.choices__input { font-size:14px; }
</style>

<div class="ctp-container">

    <!-- ══ TOP NAV ══ -->
    <div class="ctp-nav">
        <span class="ctp-nav-title">🖨️ CTP Module</span>
        <a href="ctp_export.php" class="nav-link active">📋 Export Jobs</a>
        <a href="ctp_export.php?new=1" class="nav-link">➕ New Job</a>
        <a href="ctp_generate_prc.php" class="nav-link">⚙️ Generate PRC PDF</a>
        <a href="ctp_export_download.php" class="nav-link">⬇️ Download / Export</a>
        <a href="ctp_export.php?tab=margins" class="nav-link">📐 Margin Guide</a>
    </div>

    <div class="page-header">
        <h1 class="page-title">🖨️ CTP Export <span>Module</span></h1>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <form method="POST" style="display:inline;">
                <input type="hidden" name="action" value="init_ctp_tables">
                <button type="submit" class="btn btn-ghost btn-sm">⚙️ Initialize DB Tables</button>
            </form>
            <a href="?new=1" class="btn btn-primary">➕ New CTP Job</a>
        </div>
    </div>

    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success">✅ CTP job created successfully!</div>
    <?php endif; ?>
    <?php if ($success_msg): ?>
        <div class="alert alert-success">✅ <?= htmlspecialchars($success_msg) ?></div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert alert-danger">❌ <?= htmlspecialchars($error_msg) ?></div>
    <?php endif; ?>

    <!-- ══ TABS ══ -->
    <?php
    $activeTab = 'tab-jobs';
    if (isset($_GET['new']))    $activeTab = 'tab-new';
    if (isset($_GET['view_id'])) $activeTab = 'tab-pageorder';
    if (isset($_GET['tab']) && $_GET['tab'] === 'margins') $activeTab = 'tab-margins';
    ?>
    <div class="tabs">
        <button class="tab-btn <?= $activeTab==='tab-jobs'    ?'active':'' ?>" onclick="showTab('tab-jobs',this)">📋 Export Jobs</button>
        <button class="tab-btn <?= $activeTab==='tab-new'     ?'active':'' ?>" onclick="showTab('tab-new',this)">➕ New Job</button>
        <button class="tab-btn <?= $activeTab==='tab-margins' ?'active':'' ?>" onclick="showTab('tab-margins',this)">📐 Margin Guide</button>
        <?php if ($view_job): ?>
        <button class="tab-btn <?= $activeTab==='tab-pageorder'?'active':'' ?>" onclick="showTab('tab-pageorder',this)">📄 Page Order</button>
        <?php endif; ?>
    </div>

    <!-- ══════════════════════════════════════════════════════════
         TAB: JOBS LIST
    ══════════════════════════════════════════════════════════════ -->
    <div id="tab-jobs" class="tab-content <?= $activeTab==='tab-jobs'?'active':'' ?>">
        <div class="card">
            <div class="card-title">📋 CTP Export Jobs</div>

            <?php if (empty($ctp_jobs)): ?>
                <div class="alert alert-info">
                    No CTP jobs yet. Click <strong>➕ New CTP Job</strong> to create your first export.<br>
                    If tables are missing, click <strong>⚙️ Initialize DB Tables</strong> first.
                </div>
            <?php else: ?>
            <!-- Search filter -->
            <div style="display:flex;gap:12px;margin-bottom:16px;flex-wrap:wrap;align-items:center;">
                <input type="text" id="jobSearch" class="form-control" placeholder="🔍 Search jobs..." style="max-width:300px;" oninput="filterJobs(this.value)">
                <span id="jobCount" style="font-size:13px;color:var(--muted);"><?= count($ctp_jobs) ?> jobs</span>
            </div>
            <div style="overflow-x:auto;">
            <table class="data-table" id="jobsTable">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Job Name</th>
                        <th>Book</th>
                        <th>PDF File</th>
                        <th>Layout</th>
                        <th>Pages</th>
                        <th>Sheets</th>
                        <th>Sheet Size</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($ctp_jobs as $job): ?>
                    <?php
                    $sheets_needed = ($job['padded_pages'] > 0 && $job['cols'] > 0 && $job['rows'] > 0)
                        ? ceil($job['padded_pages'] / ($job['cols'] * $job['rows'] * 2)) : 0;
                    ?>
                    <tr data-search="<?= strtolower(htmlspecialchars($job['job_name'].' '.$job['book_code'].' '.($job['pdf_filename']??''))) ?>">
                        <td><strong>#<?= $job['id'] ?></strong></td>
                        <td><strong><?= htmlspecialchars($job['job_name']) ?></strong></td>
                        <td><?= htmlspecialchars($job['book_code'] ?? '—') ?></td>
                        <td>
                            <?php if ($job['pdf_filename']): ?>
                                <span title="<?= htmlspecialchars($job['original_pdf']??'') ?>" style="font-size:12px;">
                                    📄 <?= htmlspecialchars(basename($job['pdf_filename'])) ?>
                                </span>
                            <?php else: ?>
                                <span style="color:var(--muted);font-size:12px;">—</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge badge-processing"><?= htmlspecialchars($IMPOSITION_LAYOUTS[$job['layout_type']]['label'] ?? $job['layout_type']) ?></span></td>
                        <td>
                            <?= $job['total_pages'] ?>
                            <?php if ($job['blank_inserted'] > 0): ?>
                                <small style="color:var(--muted);">(+<?= $job['blank_inserted'] ?> blank)</small>
                            <?php endif; ?>
                        </td>
                        <td><?= $sheets_needed ?> sheets</td>
                        <td><?= $job['sheet_width'] ?>×<?= $job['sheet_height'] ?>mm</td>
                        <td><span class="badge badge-<?= $job['status'] ?>"><?= ucfirst($job['status']) ?></span></td>
                        <td><?= date('Y-m-d H:i', strtotime($job['created_at'])) ?></td>
                        <td style="white-space:nowrap;">
                            <a href="?view_id=<?= $job['id'] ?>" class="btn btn-ghost btn-sm">👁 View</a>
                            <a href="ctp_generate_prc.php?job_id=<?= $job['id'] ?>" class="btn btn-success btn-sm">⚙️ Generate</a>
                            <a href="ctp_export_download.php?job_id=<?= $job['id'] ?>&format=csv" class="btn btn-ghost btn-sm">⬇ CSV</a>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this CTP job?')">
                                <input type="hidden" name="action"  value="delete_job">
                                <input type="hidden" name="job_id" value="<?= $job['id'] ?>">
                                <button type="submit" class="btn btn-danger btn-sm">🗑</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════
         TAB: NEW JOB FORM
    ══════════════════════════════════════════════════════════════ -->
    <div id="tab-new" class="tab-content <?= $activeTab==='tab-new'?'active':'' ?>">

        <!-- Live sheet preview -->
        <div class="card">
            <div class="card-title">👁️ Live Sheet Preview</div>
            <div class="sheet-preview-wrap">
                <div class="sheet-canvas-container">
                    <canvas id="sheetPreview" width="700" height="380"></canvas>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-title">➕ Create New CTP Export Job</div>

            <form method="POST" id="ctpForm">
                <input type="hidden" name="action" value="create_ctp_job">
                <!-- Hidden fields populated by JS after upload -->
                <input type="hidden" name="uploaded_pdf_path" id="uploaded_pdf_path">
                <input type="hidden" name="uploaded_pdf_name" id="uploaded_pdf_name">

                <!-- ── BASIC INFO ── -->
                <div class="section-label">📁 Job Information</div>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Job Name <span style="color:red">*</span></label>
                        <input type="text" name="job_name" class="form-control" required
                               placeholder="e.g. Nepali Grade-9 Batch 2082">
                    </div>
                    <div class="form-group">
                        <label>Book (searchable)</label>
                        <select name="book_code" id="bookSelect" class="form-control">
                            <option value="">— Select Book —</option>
                            <?php foreach ($books as $b): ?>
                                <option value="<?= htmlspecialchars($b['book_code']) ?>"
                                        data-label="<?= htmlspecialchars($b['book_name'].' ('.$b['book_code'].')') ?>">
                                    <?= htmlspecialchars($b['book_name']) ?> (<?= $b['book_code'] ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- ── PDF UPLOAD ── -->
                <div class="section-label">📎 Upload Original PDF</div>
                <div class="alert alert-warning" style="font-size:13px;">
                    ⚠️ <strong>Composer not yet installed?</strong> Upload your PDF here — the system will count pages automatically without needing FPDI. Once you run
                    <code>composer require setasign/fpdi tecnickcom/tcpdf</code> in <code>C:\xampp\htdocs\deno2\</code>, the Generate PRC PDF step will also work.
                </div>

                <div class="upload-zone" id="uploadZone">
                    <input type="file" id="pdfFileInput" accept=".pdf" onchange="handleFileSelect(this)">
                    <div class="upload-icon">📄</div>
                    <div class="upload-text">Click to browse or drag & drop your PDF here</div>
                    <div class="upload-hint">Accepts PDF files up to 200MB</div>
                    <div class="upload-progress" id="uploadProgress">
                        <div style="font-size:13px;color:var(--muted);">Uploading & counting pages…</div>
                        <div class="progress-bar-wrap">
                            <div class="progress-bar-fill" id="progressFill"></div>
                        </div>
                    </div>
                </div>

                <div class="upload-result" id="uploadResult"></div>

                <!-- ── PAGE COUNT ── -->
                <div class="section-label">📖 Page Count</div>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Total Pages in PDF <span style="color:red">*</span></label>
                        <div style="display:flex;gap:8px;align-items:center;">
                            <input type="number" name="total_pages" id="total_pages" class="form-control"
                                   min="1" required placeholder="Auto-filled after upload or enter manually"
                                   oninput="updateCalculations()">
                            <span id="pageCountBadge" style="display:none;padding:6px 12px;background:#d4edda;color:#155724;border-radius:20px;font-size:12px;font-weight:700;white-space:nowrap;">
                                ✅ Auto-detected
                            </span>
                        </div>
                        <small style="color:var(--muted);font-size:11px;">Auto-filled from uploaded PDF. You can also type manually.</small>
                    </div>
                </div>

                <!-- ── IMPOSITION LAYOUT ── -->
                <div class="section-label">🗂 Imposition Layout</div>
                <div class="layout-grid">
                    <?php foreach ($IMPOSITION_LAYOUTS as $key => $ly): ?>
                    <div class="layout-option">
                        <input type="radio" name="layout_type" id="lt_<?= $key ?>" value="<?= $key ?>"
                               <?= $key==='8up_booklet'?'checked':'' ?>
                               onchange="onLayoutChange('<?= $key ?>',<?= $ly['cols'] ?>,<?= $ly['rows'] ?>)">
                        <label for="lt_<?= $key ?>">
                            <span class="layout-icon"><?= ['8up_booklet'=>'📄','4up_booklet'=>'📃','2up_booklet'=>'📰','16up_booklet'=>'📑','custom'=>'⚙️'][$key] ?></span>
                            <span class="layout-name"><?= $ly['label'] ?></span>
                            <span class="layout-desc"><?= $ly['description'] ?></span>
                        </label>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div id="custom_dims" class="hidden">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Custom Columns</label>
                            <input type="number" name="custom_cols" id="custom_cols" class="form-control" min="1" max="8" value="2" oninput="updateCalculations()">
                        </div>
                        <div class="form-group">
                            <label>Custom Rows</label>
                            <input type="number" name="custom_rows" id="custom_rows" class="form-control" min="1" max="8" value="2" oninput="updateCalculations()">
                        </div>
                    </div>
                </div>

                <!-- ── SHEET SIZE ── -->
                <div class="section-label">📏 Sheet Size</div>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Sheet Preset</label>
                        <select name="sheet_size" class="form-control" onchange="onSheetPreset(this.value)">
                            <?php foreach ($SHEET_SIZES as $sk => $sv): ?>
                                <option value="<?= $sk ?>" <?= $sk==='custom'?'selected':'' ?>><?= htmlspecialchars($sv['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Width (mm)</label>
                        <input type="number" name="sheet_w" id="sheet_w" class="form-control" value="720" step="0.1" oninput="updateCalculations()">
                    </div>
                    <div class="form-group">
                        <label>Height (mm)</label>
                        <input type="number" name="sheet_h" id="sheet_h" class="form-control" value="508" step="0.1" oninput="updateCalculations()">
                    </div>
                    <div class="form-group">
                        <label>Orientation</label>
                        <select class="form-control" onchange="swapSheetDims(this.value)">
                            <option value="landscape">Landscape (wide)</option>
                            <option value="portrait">Portrait (tall)</option>
                        </select>
                    </div>
                </div>

                <!-- ── MARGINS ── -->
                <div class="section-label">📐 Margins & Marks (mm)</div>
                <?php
                $margin_fields = [
                    ['bleed','Bleed',DEFAULT_BLEED,0,10,0.5,'🔴'],
                    ['gutter','Gutter',DEFAULT_GUTTER,0,20,0.5,'🟡'],
                    ['trim_outer','Trim / Outer',DEFAULT_TRIM_OUTER,0,20,0.5,'🟢'],
                    ['gripper','Gripper',DEFAULT_GRIPPER,5,25,0.5,'🔵'],
                    ['head_margin','Head Margin',DEFAULT_HEAD_MARGIN,0,25,0.5,'⬆️'],
                    ['foot_margin','Foot Margin',DEFAULT_FOOT_MARGIN,0,25,0.5,'⬇️'],
                ];
                foreach ($margin_fields as [$n,$l,$d,$mn,$mx,$step,$ico]): ?>
                <div class="margin-row">
                    <span class="margin-label"><?= $ico ?> <?= $l ?></span>
                    <input type="range" name="<?= $n ?>" id="range_<?= $n ?>"
                           min="<?= $mn ?>" max="<?= $mx ?>" step="<?= $step ?>" value="<?= $d ?>"
                           class="margin-slider"
                           oninput="document.getElementById('val_<?= $n ?>').value=this.value; updateCalculations()">
                    <input type="number" id="val_<?= $n ?>" class="margin-val"
                           min="<?= $mn ?>" max="<?= $mx ?>" step="<?= $step ?>" value="<?= $d ?>"
                           oninput="document.getElementById('range_<?= $n ?>').value=this.value; updateCalculations()">
                    <span style="font-size:12px;color:var(--muted);width:30px;">mm</span>
                </div>
                <?php endforeach; ?>

                <!-- ── LIVE SUMMARY ── -->
                <div class="section-label">📊 Calculation Summary</div>
                <div style="background:#f8fafc;border:1px solid var(--border);border-radius:8px;padding:20px;margin-bottom:20px;">
                    <div class="summary-row"><span>Original Pages</span><strong id="s_total">—</strong></div>
                    <div class="summary-row"><span>Pages per Sheet (both sides)</span><strong id="s_pps">—</strong></div>
                    <div class="summary-row"><span>Signature Size</span><strong id="s_sig">—</strong></div>
                    <div class="summary-row"><span>Blank Pages to Insert</span><strong id="s_blank" style="color:var(--warning);">—</strong></div>
                    <div class="summary-row"><span>Padded Total Pages</span><strong id="s_padded">—</strong></div>
                    <div class="summary-row"><span>CTP Sheets Required</span><strong id="s_sheets">—</strong></div>
                    <div class="summary-row"><span>Usable Page Area (per page on sheet)</span><strong id="s_area">—</strong></div>
                </div>

                <div class="form-group" style="margin-bottom:20px;">
                    <label>Notes / Special Instructions</label>
                    <textarea name="notes" class="form-control" rows="3"
                              placeholder="e.g. CMYK separations, spot colour plates, finishing notes..."></textarea>
                </div>

                <div style="display:flex;gap:12px;justify-content:flex-end;padding-top:16px;border-top:1px solid var(--border);">
                    <a href="<?= $_SERVER['PHP_SELF'] ?>" class="btn btn-ghost">Cancel</a>
                    <button type="submit" class="btn btn-primary" id="submitBtn">🖨️ Create CTP Job</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════
         TAB: MARGIN GUIDE
    ══════════════════════════════════════════════════════════════ -->
    <div id="tab-margins" class="tab-content <?= $activeTab==='tab-margins'?'active':'' ?>">
        <div class="margin-guide-card">
            <h3>📐 Recommended Margin Guide (mm)</h3>
            <table class="margin-table">
                <thead><tr><th>Setting</th><th>Min</th><th>Recommended</th><th>Max</th><th>Purpose</th></tr></thead>
                <tbody>
                    <tr class="tr-bleed"><td>🔴 Bleed</td><td>2mm</td><td><strong>3mm</strong></td><td>5mm</td><td>Extends artwork beyond trim; prevents white edges after cutting</td></tr>
                    <tr class="tr-gutter"><td>🟡 Gutter</td><td>3mm</td><td><strong>5mm</strong></td><td>10mm</td><td>Space between two pages on the same sheet; hidden in spine</td></tr>
                    <tr class="tr-trim"><td>🟢 Trim/Outer</td><td>6mm</td><td><strong>8mm</strong></td><td>12mm</td><td>Space for crop marks outside bleed area</td></tr>
                    <tr class="tr-gripper"><td>🔵 Gripper</td><td>8mm</td><td><strong>10mm</strong></td><td>15mm</td><td>Press gripper area — NEVER print here; paper clamp zone</td></tr>
                    <tr><td>⬆️ Head Margin</td><td>5mm</td><td><strong>8mm</strong></td><td>15mm</td><td>Top edge space; includes registration marks</td></tr>
                    <tr><td>⬇️ Foot Margin</td><td>5mm</td><td><strong>8mm</strong></td><td>15mm</td><td>Bottom edge space; includes colour bars</td></tr>
                </tbody>
            </table>
            <div class="margin-note">⚠️ <strong>Note:</strong> Gripper margin is always at the leading edge of the sheet entering the press.</div>
        </div>
        <div class="card">
            <div class="card-title">📐 Press Sheet Cross-Section Diagram</div>
            <canvas id="marginDiagram" width="680" height="420" style="display:block;margin:0 auto;border:1px solid var(--border);border-radius:8px;"></canvas>
        </div>
        <div class="card">
            <div class="card-title">⚙️ Page Dimension Calculator</div>
            <div class="form-grid">
                <div class="form-group"><label>Sheet Width (mm)</label><input type="number" id="calc_sw" class="form-control" value="720" oninput="calcPageSize()"></div>
                <div class="form-group"><label>Sheet Height (mm)</label><input type="number" id="calc_sh" class="form-control" value="508" oninput="calcPageSize()"></div>
                <div class="form-group"><label>Columns</label><input type="number" id="calc_cols" class="form-control" value="4" oninput="calcPageSize()"></div>
                <div class="form-group"><label>Rows</label><input type="number" id="calc_rows" class="form-control" value="2" oninput="calcPageSize()"></div>
                <div class="form-group"><label>Bleed (mm)</label><input type="number" id="calc_bleed" class="form-control" value="3" oninput="calcPageSize()"></div>
                <div class="form-group"><label>Gutter (mm)</label><input type="number" id="calc_gutter" class="form-control" value="5" oninput="calcPageSize()"></div>
                <div class="form-group"><label>Gripper (mm)</label><input type="number" id="calc_gripper" class="form-control" value="10" oninput="calcPageSize()"></div>
                <div class="form-group"><label>Head / Foot (mm)</label><input type="number" id="calc_head" class="form-control" value="8" oninput="calcPageSize()"></div>
            </div>
            <div id="calc_result" style="margin-top:16px;background:#e8f5e9;border:1px solid #a5d6a7;border-radius:8px;padding:16px;font-size:14px;">
                <strong>Results will appear here…</strong>
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════
         TAB: PAGE ORDER VIEW
    ══════════════════════════════════════════════════════════════ -->
    <?php if ($view_job): ?>
    <div id="tab-pageorder" class="tab-content <?= $activeTab==='tab-pageorder'?'active':'' ?>">
        <div class="card">
            <div class="card-title">
                📄 Page Imposition Order — <?= htmlspecialchars($view_job['job_name']) ?>
                <span style="margin-left:auto;display:flex;gap:8px;">
                    <a href="ctp_generate_prc.php?job_id=<?= $view_job['id'] ?>" class="btn btn-primary btn-sm">⚙️ Generate PRC PDF</a>
                    <a href="ctp_export_download.php?job_id=<?= $view_job['id'] ?>&format=csv"  class="btn btn-success btn-sm">⬇ CSV</a>
                    <a href="ctp_export_download.php?job_id=<?= $view_job['id'] ?>&format=json" class="btn btn-ghost btn-sm">⬇ JSON</a>
                </span>
            </div>

            <?php $order = json_decode($view_job['page_order_json'] ?? '[]', true); ?>
            <div class="alert alert-info">
                <strong>Layout:</strong> <?= htmlspecialchars($IMPOSITION_LAYOUTS[$view_job['layout_type']]['label'] ?? '') ?>
                &nbsp;|&nbsp; <strong>Pages:</strong> <?= $view_job['total_pages'] ?>
                &nbsp;|&nbsp; <strong>Padded:</strong> <?= $view_job['padded_pages'] ?>
                &nbsp;|&nbsp; <strong>Blank Inserted:</strong> <?= $view_job['blank_inserted'] ?>
                &nbsp;|&nbsp; <strong>Sheet Size:</strong> <?= $view_job['sheet_width'] ?>×<?= $view_job['sheet_height'] ?>mm
                <?php if ($view_job['pdf_filename']): ?>
                    &nbsp;|&nbsp; <strong>PDF:</strong> <?= htmlspecialchars(basename($view_job['pdf_filename'])) ?>
                <?php endif; ?>
            </div>

            <div class="po-grid">
            <?php foreach ($order as $sheet): ?>
                <div class="po-sheet-card">
                    <div class="po-sheet-title">📄 Sheet <?= $sheet['sheet_index'] ?></div>
                    <?php foreach (['front','back'] as $side): ?>
                    <div>
                        <div class="po-side-label"><?= strtoupper($side) ?> Side</div>
                        <div class="po-pairs">
                        <?php foreach ($sheet[$side] as $pair):
                            $lp = $pair['left_pg']  ?? $pair['right_pg'] ?? '?';
                            $rp = $pair['right_pg'] ?? $pair['left_pg']  ?? '?';
                            $isBlank = ($lp > $view_job['total_pages']) || ($rp > $view_job['total_pages']);
                        ?>
                            <div class="po-pair <?= $side ?> <?= $isBlank?'blank-badge':'' ?>">
                                <?= $lp <= $view_job['total_pages'] ? $lp : 'B' ?> | <?= $rp <= $view_job['total_pages'] ? $rp : 'B' ?>
                            </div>
                        <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
            </div>

            <div style="margin-top:20px;padding:14px;background:#fff8e1;border-radius:8px;font-size:13px;">
                <strong>Legend:</strong>
                <span class="po-pair front" style="margin:0 6px;">Front Side Pairs</span>
                <span class="po-pair back"  style="margin:0 6px;">Back Side Pairs</span>
                <span class="po-pair blank-badge" style="margin:0 6px;">B = Blank Page</span>
                &nbsp; Each pair: <strong>Left Page | Right Page</strong>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div><!-- /ctp-container -->

<script>
// ─── LAYOUT CONFIG ─────────────────────────────────────────────────────────
const LAYOUTS = <?= json_encode($IMPOSITION_LAYOUTS) ?>;
const SHEETS  = <?= json_encode($SHEET_SIZES) ?>;
let currentCols = 4, currentRows = 2;

// ─── TAB SWITCHING ─────────────────────────────────────────────────────────
function showTab(id, btn) {
    document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById(id).classList.add('active');
    btn.classList.add('active');
    if (id === 'tab-margins') { drawMarginDiagram(); calcPageSize(); }
    if (id === 'tab-new')     { drawSheetPreview(); }
}

// ─── SEARCHABLE BOOK SELECT (Choices.js) ──────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    const bookEl = document.getElementById('bookSelect');
    if (bookEl && typeof Choices !== 'undefined') {
        new Choices(bookEl, {
            searchEnabled:       true,
            searchPlaceholderValue: 'Type to search books…',
            itemSelectText:      '',
            noResultsText:       'No books found',
            shouldSort:          false,
            allowHTML:           false,
        });
    }

    updateCalculations();
    drawSheetPreview();

    // Drag & drop support
    const zone = document.getElementById('uploadZone');
    if (zone) {
        zone.addEventListener('dragover', e => { e.preventDefault(); zone.classList.add('dragover'); });
        zone.addEventListener('dragleave', ()=> zone.classList.remove('dragover'));
        zone.addEventListener('drop', e => {
            e.preventDefault();
            zone.classList.remove('dragover');
            const f = e.dataTransfer.files[0];
            if (f && f.type === 'application/pdf') uploadPDF(f);
            else showUploadResult(false, 'Please drop a PDF file.');
        });
    }
});

// ─── FILE SELECT HANDLER ──────────────────────────────────────────────────
function handleFileSelect(input) {
    const file = input.files[0];
    if (!file) return;
    if (file.type !== 'application/pdf') {
        showUploadResult(false, 'Only PDF files are accepted.');
        return;
    }
    uploadPDF(file);
}

// ─── UPLOAD PDF VIA AJAX ──────────────────────────────────────────────────
function uploadPDF(file) {
    const progressWrap = document.getElementById('uploadProgress');
    const progressFill = document.getElementById('progressFill');
    const resultBox    = document.getElementById('uploadResult');
    const zone         = document.getElementById('uploadZone');

    resultBox.style.display = 'none';
    progressWrap.style.display = 'block';
    progressFill.style.width = '0%';
    zone.style.pointerEvents = 'none';

    const formData = new FormData();
    formData.append('pdf_file', file);

    const xhr = new XMLHttpRequest();
    xhr.open('POST', 'ctp_upload_handler.php', true);

    xhr.upload.onprogress = e => {
        if (e.lengthComputable) {
            progressFill.style.width = Math.round((e.loaded / e.total) * 100) + '%';
        }
    };

    xhr.onload = () => {
        progressWrap.style.display = 'none';
        zone.style.pointerEvents = '';
        try {
            const res = JSON.parse(xhr.responseText);
            if (res.success) {
                // Populate hidden fields
                document.getElementById('uploaded_pdf_path').value = res.saved_path;
                document.getElementById('uploaded_pdf_name').value = res.filename;
                // Set page count
                const pgInput = document.getElementById('total_pages');
                pgInput.value = res.pages;
                pgInput.dispatchEvent(new Event('input'));
                // Show badge
                const badge = document.getElementById('pageCountBadge');
                badge.style.display = 'inline-flex';
                badge.textContent = '✅ ' + res.pages + ' pages detected';

                showUploadResult(true,
                    `✅ <strong>${escHtml(res.filename)}</strong> uploaded successfully!<br>
                     📖 Pages detected: <strong>${res.pages}</strong> &nbsp;|&nbsp;
                     💾 Size: <strong>${res.file_size}</strong>`
                );
                updateCalculations();
            } else {
                showUploadResult(false, '❌ ' + escHtml(res.error || 'Upload failed.'));
            }
        } catch(e) {
            showUploadResult(false, '❌ Server response error. Check ctp_upload_handler.php.');
        }
    };
    xhr.onerror = () => {
        progressWrap.style.display = 'none';
        zone.style.pointerEvents = '';
        showUploadResult(false, '❌ Network error during upload.');
    };
    xhr.send(formData);
}

function showUploadResult(success, html) {
    const box = document.getElementById('uploadResult');
    box.className = 'upload-result ' + (success ? 'success' : 'error');
    box.innerHTML = html;
    box.style.display = 'block';
}

function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// ─── JOB TABLE SEARCH ─────────────────────────────────────────────────────
function filterJobs(q) {
    const rows = document.querySelectorAll('#jobsTable tbody tr');
    let visible = 0;
    q = q.toLowerCase();
    rows.forEach(r => {
        const match = r.dataset.search.includes(q);
        r.style.display = match ? '' : 'none';
        if (match) visible++;
    });
    const el = document.getElementById('jobCount');
    if (el) el.textContent = visible + ' job' + (visible !== 1 ? 's' : '');
}

// ─── LAYOUT CHANGE ────────────────────────────────────────────────────────
function onLayoutChange(key, cols, rows) {
    const isCustom = key === 'custom';
    document.getElementById('custom_dims').classList.toggle('hidden', !isCustom);
    if (!isCustom) { currentCols = cols; currentRows = rows; }
    else {
        currentCols = parseInt(document.getElementById('custom_cols').value) || 2;
        currentRows = parseInt(document.getElementById('custom_rows').value) || 2;
    }
    updateCalculations();
    drawSheetPreview();
}

function onSheetPreset(key) {
    const s = SHEETS[key];
    if (s) { document.getElementById('sheet_w').value = s.w; document.getElementById('sheet_h').value = s.h; updateCalculations(); }
}

function swapSheetDims(orient) {
    const sw = parseFloat(document.getElementById('sheet_w').value);
    const sh = parseFloat(document.getElementById('sheet_h').value);
    if (orient === 'portrait'  && sw > sh) { document.getElementById('sheet_w').value = sh; document.getElementById('sheet_h').value = sw; }
    if (orient === 'landscape' && sh > sw) { document.getElementById('sheet_w').value = sh; document.getElementById('sheet_h').value = sw; }
    updateCalculations();
}

// ─── CALCULATIONS ─────────────────────────────────────────────────────────
function updateCalculations() {
    const tp  = parseInt(document.getElementById('total_pages')?.value) || 0;
    const sw  = parseFloat(document.getElementById('sheet_w')?.value)  || 720;
    const sh  = parseFloat(document.getElementById('sheet_h')?.value)  || 508;
    const bleed   = parseFloat(document.getElementById('val_bleed')?.value)       || 3;
    const gutter  = parseFloat(document.getElementById('val_gutter')?.value)      || 5;
    const gripper = parseFloat(document.getElementById('val_gripper')?.value)     || 10;
    const head    = parseFloat(document.getElementById('val_head_margin')?.value) || 8;
    const foot    = parseFloat(document.getElementById('val_foot_margin')?.value) || 8;

    const layoutKey = document.querySelector('input[name=layout_type]:checked')?.value || '8up_booklet';
    if (layoutKey === 'custom') {
        currentCols = parseInt(document.getElementById('custom_cols').value) || 2;
        currentRows = parseInt(document.getElementById('custom_rows').value) || 2;
    } else {
        currentCols = LAYOUTS[layoutKey].cols;
        currentRows = LAYOUTS[layoutKey].rows;
    }

    const pps     = currentCols * currentRows;
    const sigSize = pps * 2;
    const padded  = tp > 0 ? (tp % sigSize === 0 ? tp : tp + (sigSize - tp % sigSize)) : 0;
    const blanks  = padded - tp;
    const sheets  = padded > 0 ? padded / sigSize : 0;
    const usableW = (sw - gripper - 2*bleed - gutter*(currentCols-1)) / currentCols;
    const usableH = (sh - head - foot - 2*bleed - gutter*(currentRows-1)) / currentRows;

    setText('s_total',  tp     || '—');
    setText('s_pps',    pps    ? `${pps} (${currentCols}×${currentRows} × 2 sides)` : '—');
    setText('s_sig',    sigSize? `${sigSize} pages / sheet` : '—');
    setText('s_blank',  tp     ? blanks : '—');
    setText('s_padded', padded || '—');
    setText('s_sheets', sheets ? `${sheets} sheets` : '—');
    setText('s_area',   tp     ? `${usableW.toFixed(1)} × ${usableH.toFixed(1)} mm` : '—');
    drawSheetPreview();
}

function setText(id, v) { const el = document.getElementById(id); if (el) el.textContent = v; }

// ─── CANVAS SHEET PREVIEW ─────────────────────────────────────────────────
function drawSheetPreview() {
    const canvas = document.getElementById('sheetPreview');
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    const W = canvas.width, H = canvas.height;
    ctx.clearRect(0, 0, W, H);

    const sw     = parseFloat(document.getElementById('sheet_w')?.value)          || 720;
    const sh     = parseFloat(document.getElementById('sheet_h')?.value)          || 508;
    const bleed  = parseFloat(document.getElementById('val_bleed')?.value)        || 3;
    const gutter = parseFloat(document.getElementById('val_gutter')?.value)       || 5;
    const gripper= parseFloat(document.getElementById('val_gripper')?.value)      || 10;
    const head   = parseFloat(document.getElementById('val_head_margin')?.value)  || 8;
    const foot   = parseFloat(document.getElementById('val_foot_margin')?.value)  || 8;

    const PAD = 30;
    const scale = Math.min((W-PAD*2)/sw, (H-PAD*2)/sh);
    const ox = PAD + (W-PAD*2-sw*scale)/2;
    const oy = PAD + (H-PAD*2-sh*scale)/2;
    const s = v => v * scale;

    ctx.fillStyle = '#e3f2fd'; ctx.fillRect(ox, oy, s(sw), s(sh));
    ctx.strokeStyle = '#1565c0'; ctx.lineWidth = 2; ctx.strokeRect(ox, oy, s(sw), s(sh));

    ctx.fillStyle = 'rgba(21,101,192,0.15)'; ctx.fillRect(ox, oy, s(gripper), s(sh));
    ctx.fillStyle = '#1565c0'; ctx.font = `${Math.max(8,s(6))}px sans-serif`;
    ctx.save(); ctx.translate(ox+s(gripper/2), oy+s(sh/2)); ctx.rotate(-Math.PI/2);
    ctx.textAlign='center'; ctx.fillText('GRIPPER', 0, 4); ctx.restore();

    ctx.fillStyle = 'rgba(244,67,54,0.10)';
    ctx.fillRect(ox+s(gripper), oy, s(bleed), s(sh));
    ctx.fillRect(ox+s(sw-bleed), oy, s(bleed), s(sh));
    ctx.fillRect(ox+s(gripper+bleed), oy, s(sw-gripper-2*bleed), s(bleed));
    ctx.fillRect(ox+s(gripper+bleed), oy+s(sh-bleed), s(sw-gripper-2*bleed), s(bleed));

    ctx.fillStyle = 'rgba(76,175,80,0.15)';
    ctx.fillRect(ox+s(gripper+bleed), oy+s(bleed), s(sw-gripper-2*bleed), s(head));
    ctx.fillRect(ox+s(gripper+bleed), oy+s(sh-bleed-foot), s(sw-gripper-2*bleed), s(foot));

    const csx = ox+s(gripper+bleed), csy = oy+s(bleed+head);
    const cellW = (sw-gripper-2*bleed-gutter*(currentCols-1))/currentCols;
    const cellH = (sh-2*bleed-head-foot-gutter*(currentRows-1))/currentRows;
    const COLORS = ['#e8f5e9','#f3e5f5','#fff8e1','#fce4ec','#e3f2fd','#f1f8e9','#fef9c3','#fde8e8','#e8eaf6','#e0f2f1','#fbe9e7','#f1f8e9'];

    for (let r = 0; r < currentRows; r++) {
        for (let c = 0; c < currentCols; c++) {
            const px = csx + s(c*(cellW+gutter));
            const py = csy + s(r*(cellH+gutter));
            const idx = r*currentCols+c;
            ctx.fillStyle = COLORS[idx%COLORS.length]; ctx.fillRect(px, py, s(cellW), s(cellH));
            ctx.strokeStyle = '#666'; ctx.lineWidth = 1; ctx.strokeRect(px, py, s(cellW), s(cellH));
            ctx.fillStyle = '#333'; ctx.font = `bold ${Math.max(10,s(8))}px sans-serif`;
            ctx.textAlign = 'center'; ctx.fillText(`P${idx+1}`, px+s(cellW/2), py+s(cellH/2)+4);
            ctx.font = `${Math.max(7,s(5))}px sans-serif`; ctx.fillStyle = '#666';
            ctx.fillText(`${cellW.toFixed(0)}×${cellH.toFixed(0)}mm`, px+s(cellW/2), py+s(cellH/2)+16);
        }
    }

    ctx.fillStyle = '#333'; ctx.font = `bold ${Math.max(9,s(6))}px sans-serif`;
    ctx.textAlign = 'center';
    ctx.fillText(`Sheet: ${sw}×${sh}mm  |  ${currentCols}×${currentRows} layout`, ox+s(sw/2), oy-10);
}

// ─── MARGIN DIAGRAM ───────────────────────────────────────────────────────
function drawMarginDiagram() {
    const canvas = document.getElementById('marginDiagram');
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    ctx.clearRect(0, 0, 680, 420);
    const ox=60,oy=40,sw=560,sh=320,gr=40,bl=10,head=25,foot=25,gut=15;
    ctx.fillStyle='#e8eaf6'; ctx.fillRect(ox,oy,sw,sh);
    ctx.strokeStyle='#3f51b5'; ctx.lineWidth=2; ctx.strokeRect(ox,oy,sw,sh);
    ctx.fillStyle='rgba(21,101,192,0.22)'; ctx.fillRect(ox,oy,gr,sh);
    ctx.fillStyle='#1565c0'; ctx.font='bold 11px sans-serif'; ctx.textAlign='center';
    ctx.save(); ctx.translate(ox+gr/2,oy+sh/2); ctx.rotate(-Math.PI/2); ctx.fillText('GRIPPER 10mm',0,4); ctx.restore();
    ctx.fillStyle='rgba(244,67,54,0.18)';
    ctx.fillRect(ox+gr,oy,bl,sh); ctx.fillRect(ox+sw-bl,oy,bl,sh);
    ctx.fillRect(ox+gr+bl,oy,sw-gr-2*bl,bl); ctx.fillRect(ox+gr+bl,oy+sh-bl,sw-gr-2*bl,bl);
    ctx.fillStyle='rgba(76,175,80,0.18)';
    ctx.fillRect(ox+gr+bl,oy+bl,sw-gr-2*bl,head); ctx.fillRect(ox+gr+bl,oy+sh-bl-foot,sw-gr-2*bl,foot);
    const laX=ox+gr+bl,laY=oy+bl+head,laW=sw-gr-2*bl,laH=sh-2*bl-head-foot;
    ctx.fillStyle='#fff'; ctx.fillRect(laX,laY,laW,laH);
    ctx.fillStyle='#333'; ctx.font='bold 14px sans-serif'; ctx.textAlign='center';
    ctx.fillText('LIVE AREA (Print Area)',laX+laW/2,laY+laH/2);
    ctx.font='11px sans-serif'; ctx.fillStyle='#555';
    ctx.fillText('Page content goes here',laX+laW/2,laY+laH/2+18);
    const mid=laX+laW/2;
    ctx.fillStyle='rgba(255,193,7,0.4)'; ctx.fillRect(mid-gut/2,laY,gut,laH);
    ctx.fillStyle='#856404'; ctx.font='10px sans-serif'; ctx.textAlign='center';
    ctx.fillText('GUTTER',mid,laY+laH/2-8); ctx.fillText('5mm',mid,laY+laH/2+8);
    ctx.fillStyle='#3f51b5'; ctx.font='bold 12px sans-serif';
    ctx.fillText('Press Sheet: 720 × 508mm (example — 8-up layout)',340,412);
}

// ─── PAGE SIZE CALCULATOR ─────────────────────────────────────────────────
function calcPageSize() {
    const sw=parseFloat(document.getElementById('calc_sw').value)||720;
    const sh=parseFloat(document.getElementById('calc_sh').value)||508;
    const cols=parseInt(document.getElementById('calc_cols').value)||4;
    const rows=parseInt(document.getElementById('calc_rows').value)||2;
    const bleed=parseFloat(document.getElementById('calc_bleed').value)||3;
    const gutter=parseFloat(document.getElementById('calc_gutter').value)||5;
    const gripper=parseFloat(document.getElementById('calc_gripper').value)||10;
    const head=parseFloat(document.getElementById('calc_head').value)||8;
    const usableW=(sw-gripper-2*bleed-gutter*(cols-1))/cols;
    const usableH=(sh-2*bleed-head*2-gutter*(rows-1))/rows;
    document.getElementById('calc_result').innerHTML=`
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <div><strong>Usable Page Width:</strong> ${usableW.toFixed(2)}mm</div>
            <div><strong>Usable Page Height:</strong> ${usableH.toFixed(2)}mm</div>
            <div><strong>Pages Per Side:</strong> ${cols*rows}</div>
            <div><strong>Pages Per Sheet (both sides):</strong> ${cols*rows*2}</div>
            <div><strong>Total Printable Width:</strong> ${(sw-gripper-2*bleed).toFixed(2)}mm</div>
            <div><strong>Total Printable Height:</strong> ${(sh-2*bleed).toFixed(2)}mm</div>
        </div>
        <div style="margin-top:10px;padding:10px;background:#fff3e0;border-radius:6px;font-size:12px;">
            ℹ️ For a B5 book (180×254mm finished), usable area per page should be ≥183×257mm (including bleed).
        </div>`;
}

document.addEventListener('DOMContentLoaded', () => {
    updateCalculations();
    drawSheetPreview();
    if (document.getElementById('marginDiagram')) drawMarginDiagram();
    if (document.getElementById('calc_result'))  calcPageSize();
});
</script>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php';
ob_end_flush();
?>
