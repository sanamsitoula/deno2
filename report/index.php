<?php
// Inline AJAX handler for truncate count
if (isset($_GET['ajax']) && $_GET['ajax'] === 'trunc_count') {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
    $slug = preg_replace('/[^a-z0-9_]/', '', $_GET['slug'] ?? '');
    $fy   = trim($_GET['fy'] ?? '');
    $tbl  = 'recon_' . $slug;
    try {
        $c = $conn->prepare("SELECT COUNT(*) FROM $tbl WHERE fiscal_code=:fy");
        $c->execute([':fy' => $fy]);
        echo json_encode(['count' => (int)$c->fetchColumn()]);
    } catch (Exception $e) {
        echo json_encode(['count' => 0]);
    }
    exit;
}

/**
 * Stock Reconciliation Module v3.1
 * - Book list: ALL books from books table, deno qty joined
 * - Fiscal years from fiscal_years table via deno.deno_year
 * - Deno tab: one row per book, separate columns per FY
 * - Each module tab: FY selector, books from books table
 * - Book dropdown: code + name + class + latest FY
 * - Truncate per module per FY (confirm + live count preview)
 * - Price fallback: own → marketing → other modules
 * - Closing balance formula builder
 * - Dynamic module registry (recon_modules table)
 * - XLSX/CSV upload with comma-stripped number parsing
 * - qty: BIGINT (10+ digits), price: NUMERIC(18,4)
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$current_user    = $_SESSION['username'] ?? 'system';
$current_user_id = $_SESSION['user_id']  ?? null;

/* ─── Module registry ─── */
$conn->exec("CREATE TABLE IF NOT EXISTS recon_modules (
    id SERIAL PRIMARY KEY, slug VARCHAR(50) NOT NULL UNIQUE,
    label VARCHAR(100) NOT NULL, tbl VARCHAR(100) NOT NULL UNIQUE,
    color VARCHAR(20) DEFAULT '#3b82f6', icon VARCHAR(10) DEFAULT '📦',
    sort_order INTEGER DEFAULT 99, is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$built_in = [
    ['marketing',  'Marketing',   'recon_marketing',   '#16a34a','🟢',1],
    ['stockkeeper','Stock Keeper','recon_stockkeeper',  '#7c3aed','🟣',2],
    ['software',   'Software',    'recon_software',     '#d97706','🟠',3],
    ['comparative','Comparative', 'recon_comparative',  '#db2777','🔴',4],
];
foreach ($built_in as [$s,$l,$t,$c,$i,$o]) {
    $conn->prepare("INSERT INTO recon_modules(slug,label,tbl,color,icon,sort_order)
        VALUES(:s,:l,:t,:c,:i,:o) ON CONFLICT(slug) DO NOTHING")
        ->execute([':s'=>$s,':l'=>$l,':t'=>$t,':c'=>$c,':i'=>$i,':o'=>$o]);
}
$modules = $conn->query("SELECT * FROM recon_modules WHERE is_active=TRUE ORDER BY sort_order,id")
    ->fetchAll(PDO::FETCH_ASSOC);

/* DDL for recon tables — BIGINT qty, NUMERIC(18,4) price, unique(book_code,fiscal_code) */
$recon_ddl = "id SERIAL PRIMARY KEY,
    book_code   VARCHAR(50)     NOT NULL,
    fiscal_code VARCHAR(10)     NOT NULL,
    price       NUMERIC(18,4)   DEFAULT 0,
    qty         BIGINT          DEFAULT 0,
    notes       TEXT,
    created_by  INTEGER,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(book_code, fiscal_code)";

foreach ($modules as $m) {
    $conn->exec("CREATE TABLE IF NOT EXISTS {$m['tbl']} ($recon_ddl)");
}

/* ── Upgrade existing tables to wider types (safe to run every request) ── */
foreach ($modules as $m) {
    try { $conn->exec("ALTER TABLE {$m['tbl']} ALTER COLUMN price TYPE NUMERIC(18,4)"); } catch (Exception $e) {}
    try { $conn->exec("ALTER TABLE {$m['tbl']} ALTER COLUMN qty   TYPE BIGINT");        } catch (Exception $e) {}
}

/* ─── Fiscal years (from fiscal_years table) ─── */
$all_fiscal_years = $conn->query("
    SELECT fiscal_name, fiscal_code FROM fiscal_years ORDER BY fiscal_name DESC
")->fetchAll(PDO::FETCH_ASSOC);

$active_fiscal_years = $conn->query("
    SELECT fiscal_name, fiscal_code FROM fiscal_years WHERE is_active=TRUE ORDER BY fiscal_name DESC
")->fetchAll(PDO::FETCH_ASSOC);

/* ─── Fiscal years that actually have deno data ─── */
$deno_fiscal_years = $conn->query("
    SELECT DISTINCT d.deno_year AS fiscal_code,
           COALESCE(f.fiscal_name, d.deno_year) AS fiscal_name
    FROM deno d
    LEFT JOIN fiscal_years f ON f.fiscal_code = d.deno_year
    WHERE d.deleted_at IS NULL AND d.deno_year IS NOT NULL
    ORDER BY fiscal_code DESC
")->fetchAll(PDO::FETCH_ASSOC);

/* ─── Filters ─── */
$sel_fy      = $_GET['fiscal_year'] ?? ($deno_fiscal_years[0]['fiscal_code'] ?? ($active_fiscal_years[0]['fiscal_code'] ?? ''));
$sel_books   = isset($_GET['book_code'])   ? (array)$_GET['book_code']   : [];
$sel_classes = isset($_GET['class_level']) ? (array)$_GET['class_level'] : [];
$sel_book    = count($sel_books)===1 ? $sel_books[0] : '';
$sel_class   = count($sel_classes)===1 ? $sel_classes[0] : '';
$sel_trans   = $_GET['translated']  ?? '';
$search_term = $_GET['search']      ?? '';
$sort_col    = in_array($_GET['sort']??'',['book_name','book_code','class_level','deno_qty']) ? $_GET['sort'] : 'book_name';
$sort_dir    = strtoupper($_GET['dir']??'ASC')==='DESC' ? 'DESC' : 'ASC';
$active_tab  = preg_replace('/[^a-z0-9_]/', '', $_GET['active_tab'] ?? 'overview');
if (!$active_tab) $active_tab = 'overview';

/* ─── Closing balance formula (per FY in session) ─── */
$fkey = 'cbf_'.$sel_fy;
if (isset($_GET['save_formula'])) {
    $_SESSION[$fkey] = trim($_GET['cb_formula']??'');
    $r2 = array_filter($_GET, fn($k)=>!in_array($k,['save_formula','cb_formula']), ARRAY_FILTER_USE_KEY);
    header('Location: '.$_SERVER['PHP_SELF'].'?'.http_build_query($r2)); exit;
}
$cb_formula = $_SESSION[$fkey] ?? '';

/* ─── All books for dropdown ─── */
$all_deno_books = $conn->query("
    SELECT b.book_code, b.book_name,
           COALESCE(b.class_level::text,'') AS class_level,
           COALESCE(b.is_translated,FALSE)  AS is_translated,
           agg.latest_fy,
           COALESCE(f.fiscal_name, agg.latest_fy) AS fiscal_name
    FROM books b
    LEFT JOIN (
        SELECT book_code, MAX(deno_year) AS latest_fy
        FROM deno WHERE deleted_at IS NULL AND deno_year IS NOT NULL
        GROUP BY book_code
    ) agg ON agg.book_code = b.book_code
    LEFT JOIN fiscal_years f ON f.fiscal_code = agg.latest_fy
    ORDER BY b.book_name
")->fetchAll(PDO::FETCH_ASSOC);

/* ─── Class levels ─── */
$class_levels = $conn->query("
    SELECT DISTINCT b.class_level FROM books b
    WHERE b.class_level IS NOT NULL
    ORDER BY b.class_level
")->fetchAll(PDO::FETCH_COLUMN);

/* ═══════════════════════════════════════════════════════════
   HELPER: strip commas from numeric strings like "161,743.00"
   ═══════════════════════════════════════════════════════════ */
function parse_num_str(string $v, bool $is_int = false) {
    $clean = str_replace([',', ' '], '', trim($v));
    return $is_int ? intval(floatval($clean)) : floatval($clean);
}

/* ═══════════════════════════════════════════════════════════
   POST HANDLERS
   ═══════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    /* Add module */
    if ($action === 'add_module') {
        $ns=preg_replace('/[^a-z0-9_]/','',strtolower(trim($_POST['new_slug']??'')));
        $nl=trim($_POST['new_label']??'');
        $nc=trim($_POST['new_color']??'#3b82f6');
        $ni=trim($_POST['new_icon']??'📦');
        if ($ns && $nl) {
            try {
                $nt='recon_'.$ns;
                $conn->prepare("INSERT INTO recon_modules(slug,label,tbl,color,icon,sort_order) VALUES(:s,:l,:t,:c,:i,99)")
                    ->execute([':s'=>$ns,':l'=>$nl,':t'=>$nt,':c'=>$nc,':i'=>$ni]);
                $conn->exec("CREATE TABLE IF NOT EXISTS $nt ($recon_ddl)");
                $_SESSION['flash']=['type'=>'success','msg'=>"Module '$nl' created!"];
            } catch(Exception $e) {
                $_SESSION['flash']=['type'=>'danger','msg'=>'Slug already exists or invalid.'];
            }
        }
        header('Location: '.$_SERVER['PHP_SELF'].'?fiscal_year='.urlencode($sel_fy)); exit;
    }

    /* Hide module */
    if ($action === 'delete_module') {
        $ds=trim($_POST['del_slug']??'');
        $bis=array_column($built_in,0);
        if ($ds && !in_array($ds,$bis)) {
            $conn->prepare("UPDATE recon_modules SET is_active=FALSE WHERE slug=:s")->execute([':s'=>$ds]);
            $_SESSION['flash']=['type'=>'success','msg'=>'Module hidden.'];
        } else {
            $_SESSION['flash']=['type'=>'danger','msg'=>'Cannot remove built-in modules.'];
        }
        header('Location: '.$_SERVER['PHP_SELF'].'?fiscal_year='.urlencode($sel_fy)); exit;
    }

    /* Truncate module for a specific FY */
    if ($action === 'truncate_module') {
        $ts   = trim($_POST['trunc_slug']??'');
        $tfyc = trim($_POST['trunc_fy']??'');
        $s2t  = array_column($modules,'tbl','slug');
        if ($ts && $tfyc && isset($s2t[$ts])) {
            $ttbl = $s2t[$ts];
            $del  = $conn->prepare("DELETE FROM $ttbl WHERE fiscal_code=:fyc");
            $del->execute([':fyc'=>$tfyc]);
            $cnt  = $del->rowCount();
            $_SESSION['flash']=['type'=>'success','msg'=>"Truncated $cnt rows from $ts for FY $tfyc."];
        } else {
            $_SESSION['flash']=['type'=>'danger','msg'=>'Truncate failed: invalid module or FY.'];
        }
        header('Location: '.$_SERVER['PHP_SELF'].'?fiscal_year='.urlencode($sel_fy).'&active_tab='.urlencode($ts)); exit;
    }

    /* Truncate ALL rows in module */
    if ($action === 'truncate_module_all') {
        $ts  = trim($_POST['trunc_slug']??'');
        $s2t_tmp = array_column($modules,'tbl','slug');
        if ($ts && isset($s2t_tmp[$ts])) {
            $ttbl = $s2t_tmp[$ts];
            $del  = $conn->prepare("DELETE FROM $ttbl");
            $del->execute();
            $cnt  = $del->rowCount();
            $_SESSION['flash']=['type'=>'success','msg'=>"Deleted all $cnt rows from $ts."];
        } else {
            $_SESSION['flash']=['type'=>'danger','msg'=>'Delete all failed.'];
        }
        header('Location: '.$_SERVER['PHP_SELF'].'?fiscal_year='.urlencode($sel_fy).'&active_tab='.urlencode($ts)); exit;
    }

    /* Pre-fill module from deno book codes for a FY */
    if ($action === 'prefill_module') {
        $ps   = trim($_POST['prefill_slug']??'');
        $pfyc = trim($_POST['prefill_fy']??'');
        $s2t_tmp = array_column($modules,'tbl','slug');
        if ($ps && $pfyc && isset($s2t_tmp[$ps])) {
            $ptbl = $s2t_tmp[$ps];
            $bk_stmt = $conn->prepare("SELECT DISTINCT book_code FROM deno WHERE deno_year=:fy AND deleted_at IS NULL");
            $bk_stmt->execute([':fy'=>$pfyc]);
            $bk_codes = $bk_stmt->fetchAll(PDO::FETCH_COLUMN);
            $inserted = 0;
            $first_id = null; $last_id = null;
            foreach ($bk_codes as $bc) {
                $ins = $conn->prepare("INSERT INTO $ptbl(book_code,fiscal_code,price,qty,notes,created_by)
                    VALUES(:bc,:fyc,0,0,'',  :uid)
                    ON CONFLICT(book_code,fiscal_code) DO NOTHING
                    RETURNING id");
                $ins->execute([':bc'=>$bc,':fyc'=>$pfyc,':uid'=>$current_user_id]);
                $new_id = $ins->fetchColumn();
                if ($new_id !== false) {
                    if ($first_id === null) $first_id = $new_id;
                    $last_id = $new_id;
                    $inserted++;
                }
            }
            $id_info = $inserted > 0 ? " | First ID: $first_id, Last ID: $last_id" : '';
            $_SESSION['flash']=['type'=>'success','msg'=>"Pre-filled $inserted new rows into table '$ptbl' for FY $pfyc.$id_info"];
        } else {
            $_SESSION['flash']=['type'=>'danger','msg'=>'Pre-fill failed.'];
        }
        header('Location: '.$_SERVER['PHP_SELF'].'?fiscal_year='.urlencode($pfyc??$sel_fy).'&active_tab='.urlencode($ps)); exit;
    }

    $s2t = array_column($modules,'tbl','slug');

    /* Save module rows */
    if (str_starts_with($action,'save_') && isset($s2t[substr($action,5)])) {
        $table = $s2t[substr($action,5)];
        foreach (($_POST['rows']??[]) as $row) {
            $bc=trim($row['book_code']??''); $fyc=trim($row['fiscal_code']??'');
            if (!$bc||!$fyc) continue;
            // Strip commas from manually typed numbers
            $price = parse_num_str($row['price'] ?? '0');
            $qty   = parse_num_str($row['qty']   ?? '0', true);
            $conn->prepare("INSERT INTO $table(book_code,fiscal_code,price,qty,notes,created_by)
                VALUES(:bc,:fyc,:pr,:qty,:notes,:uid)
                ON CONFLICT(book_code,fiscal_code) DO UPDATE SET
                    price=EXCLUDED.price, qty=EXCLUDED.qty,
                    notes=EXCLUDED.notes, updated_at=CURRENT_TIMESTAMP")
                ->execute([':bc'=>$bc,':fyc'=>$fyc,
                    ':pr'=>$price, ':qty'=>$qty,
                    ':notes'=>trim($row['notes']??''),':uid'=>$current_user_id]);
        }
        $_SESSION['flash']=['type'=>'success','msg'=>'Saved successfully!'];
        header('Location: '.$_SERVER['PHP_SELF']
            .'?fiscal_year='.urlencode($_POST['page_fy']??$sel_fy)
            .'&active_tab='.urlencode($_POST['active_tab']??'')
            .'&book_code='.urlencode($_POST['book_filter']??'')
            .'&translated='.urlencode($_POST['trans_filter']??'')
            .'&class_level='.urlencode($_POST['class_filter']??'')
            .'&search='.urlencode($_POST['search_filter']??'')); exit;
    }

    /* ── CSV / XLSX Upload ── */
    if ($action === 'upload_csv') {
        $slug  = $_POST['upload_module'] ?? '';
        $table = $s2t[$slug] ?? '';
        $fyc   = $_POST['upload_fiscal_code'] ?? '';
        $saved = 0;

        if ($table && $fyc && isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === 0) {
            $orig_name = $_FILES['csv_file']['name'] ?? '';
            $ext       = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
            $tmp       = $_FILES['csv_file']['tmp_name'];

            $rows_to_import = [];

            /* ── XLSX via ZipArchive + SimpleXML ── */
            if ($ext === 'xlsx') {
                if (!class_exists('ZipArchive')) {
                    $_SESSION['flash'] = ['type'=>'danger','msg'=>'ZipArchive PHP extension not available. Please upload CSV instead.'];
                    header('Location: '.$_SERVER['PHP_SELF'].'?fiscal_year='.urlencode($fyc)); exit;
                }
                $zip = new ZipArchive();
                if ($zip->open($tmp) === TRUE) {
                    // Read shared strings table
                    $shared_strings = [];
                    $ss_xml = $zip->getFromName('xl/sharedStrings.xml');
                    if ($ss_xml) {
                        $ss = simplexml_load_string($ss_xml);
                        foreach ($ss->si as $si) {
                            // Concatenate all <t> nodes (handles rich-text cells)
                            $txt = '';
                            foreach ($si->xpath('.//t') as $t_node) {
                                $txt .= (string)$t_node;
                            }
                            $shared_strings[] = $txt;
                        }
                    }
                    // Read first worksheet
                    $sheet_xml = $zip->getFromName('xl/worksheets/sheet1.xml');
                    if ($sheet_xml) {
                        $sheet = simplexml_load_string($sheet_xml);
                        $header_skipped = false;
                        foreach ($sheet->sheetData->row as $xrow) {
                            $cells = [];
                            foreach ($xrow->c as $cell) {
                                $cell_type = (string)($cell['t'] ?? '');
                                $cell_val  = (string)($cell->v ?? '');
                                if ($cell_type === 's') {
                                    // Shared string
                                    $cell_val = $shared_strings[(int)$cell_val] ?? '';
                                } elseif ($cell_type === 'inlineStr') {
                                    $cell_val = (string)($cell->is->t ?? '');
                                }
                                $cells[] = $cell_val;
                            }
                            if (!$header_skipped) { $header_skipped = true; continue; }
                            if (empty(trim($cells[0] ?? ''))) continue;
                            $rows_to_import[] = $cells;
                        }
                    }
                    $zip->close();
                } else {
                    $_SESSION['flash'] = ['type'=>'danger','msg'=>'Could not open XLSX file.'];
                    header('Location: '.$_SERVER['PHP_SELF'].'?fiscal_year='.urlencode($fyc)); exit;
                }

            /* ── XLS (old binary) — not supported ── */
            } elseif ($ext === 'xls') {
                $_SESSION['flash'] = ['type'=>'danger','msg'=>'Old .xls format is not supported. Please save the file as .xlsx or .csv and re-upload.'];
                header('Location: '.$_SERVER['PHP_SELF'].'?fiscal_year='.urlencode($fyc)); exit;

            /* ── CSV ── */
            } else {
                $fh = fopen($tmp, 'r');
                if ($fh) {
                    fgetcsv($fh); // skip header row
                    while (($row = fgetcsv($fh)) !== false) {
                        if (count($row) < 2) continue;
                        $rows_to_import[] = $row;
                    }
                    fclose($fh);
                }
            }

            /* ── Insert / update rows ── */
            foreach ($rows_to_import as $row) {
                $bc = trim($row[0] ?? '');
                if (!$bc || strtolower($bc) === 'book_code') continue;

                // Strip commas from numbers: "161,743.00" → 161743, "2,941,000.00" → 2941000
                $pr    = parse_num_str($row[3] ?? '0');
                $qty   = parse_num_str($row[4] ?? '0', true);
                $notes = trim($row[5] ?? '');

                $conn->prepare("INSERT INTO $table(book_code,fiscal_code,price,qty,notes,created_by)
                    VALUES(:bc,:fyc,:pr,:qty,:notes,:uid)
                    ON CONFLICT(book_code,fiscal_code) DO UPDATE SET
                        price=EXCLUDED.price, qty=EXCLUDED.qty,
                        notes=EXCLUDED.notes, updated_at=CURRENT_TIMESTAMP")
                    ->execute([':bc'=>$bc,':fyc'=>$fyc,':pr'=>$pr,':qty'=>$qty,':notes'=>$notes,':uid'=>$current_user_id]);
                $saved++;
            }

            $_SESSION['flash'] = ['type'=>'success','msg'=>"Uploaded $saved rows to $slug from .$ext file."];
        } else {
            $_SESSION['flash'] = ['type'=>'danger','msg'=>'Upload failed. Check file and fiscal year selection.'];
        }
        header('Location: '.$_SERVER['PHP_SELF'].'?fiscal_year='.urlencode($fyc).'&active_tab='.urlencode($slug)); exit;
    }
}

/* ═══════════════════════════════════════════════════════════
   FETCH MAIN RECONCILIATION DATA
   ═══════════════════════════════════════════════════════════ */
$sel_parts = [
    "d_agg.book_code",
    "d_agg.book_name",
    "d_agg.class_level",
    "d_agg.is_translated",
    "d_agg.book_type",
    "d_agg.fiscal_code AS fy_code",
    "d_agg.deno_qty"
];
$join_parts = [];
$group_extra = [];

foreach ($modules as $m) {
    $a = $m['slug']; $tbl = $m['tbl'];
    $sel_parts[]  = "r_{$a}.price AS {$a}_price, r_{$a}.qty AS {$a}_qty, r_{$a}.notes AS {$a}_notes, r_{$a}.updated_at AS {$a}_updated";
    $join_parts[] = "LEFT JOIN $tbl r_{$a} ON r_{$a}.book_code=d_agg.book_code AND r_{$a}.fiscal_code=d_agg.fiscal_code";
    $group_extra[] = "r_{$a}.price,r_{$a}.qty,r_{$a}.notes,r_{$a}.updated_at";
}

$outer_where  = ["1=1"];
$outer_params = [];
if (!empty($sel_books)) {
    $ph = implode(',', array_map(fn($i)=>":bc$i", array_keys($sel_books)));
    $outer_where[] = "d_agg.book_code IN ($ph)";
    foreach ($sel_books as $i=>$bc) $outer_params[":bc$i"] = $bc;
}
if ($sel_trans!==''){$outer_where[] = "d_agg.is_translated=:tr"; $outer_params[':tr']=($sel_trans==='1')?'true':'false';}
if (!empty($sel_classes)) {
    $ph2 = implode(',', array_map(fn($i)=>":cl$i", array_keys($sel_classes)));
    $outer_where[] = "d_agg.class_level IN ($ph2)";
    foreach ($sel_classes as $i=>$cl) $outer_params[":cl$i"] = $cl;
}
if ($search_term) { $outer_where[] = "(LOWER(d_agg.book_name) LIKE :s OR LOWER(d_agg.book_code) LIKE :s)"; $outer_params[':s']='%'.strtolower($search_term).'%'; }

$outer_where_sql = implode(' AND ', $outer_where);
$sel_sql  = implode(",\n        ", $sel_parts);
$join_sql  = implode("\n    ", $join_parts);
$grp_sql   = "d_agg.book_code,d_agg.book_name,d_agg.class_level,d_agg.is_translated,d_agg.book_type,d_agg.fiscal_code,d_agg.deno_qty,"
           . implode(',',$group_extra);

$sort_map  = ['book_name'=>'d_agg.book_name','book_code'=>'d_agg.book_code','class_level'=>'d_agg.class_level','deno_qty'=>'d_agg.deno_qty'];
$sort_expr = $sort_map[$sort_col] ?? 'd_agg.book_name';
$sort_full = $sort_col==='book_name' ? 'NULLIF(d_agg.class_level,\'\')::int NULLS LAST, d_agg.book_name ASC' : "$sort_expr $sort_dir, NULLIF(d_agg.class_level,\'\')::int NULLS LAST, d_agg.book_name ASC";

$main_params = array_merge([':fy'=>$sel_fy, ':fy2'=>$sel_fy, ':fy3'=>$sel_fy], $outer_params);

$stmt = $conn->prepare("
    SELECT $sel_sql
    FROM (
        SELECT
            b.book_code,
            b.book_name,
            COALESCE(b.class_level::text,'')  AS class_level,
            COALESCE(b.is_translated,FALSE)   AS is_translated,
            COALESCE(b.book_type,'TextBook')  AS book_type,
            :fy                               AS fiscal_code,
            COALESCE(SUM(CASE WHEN d.deleted_at IS NULL AND d.deno_year=:fy2 THEN d.total_qty ELSE 0 END),0) AS deno_qty
        FROM books b
        LEFT JOIN deno d ON d.book_code=b.book_code AND d.deno_year=:fy3 AND d.deleted_at IS NULL
        GROUP BY b.book_code,b.book_name,b.class_level,b.is_translated,b.book_type
    ) AS d_agg
    $join_sql
    WHERE $outer_where_sql
    ORDER BY $sort_full
");
$stmt->execute($main_params);
$recon_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ─── Deno tab: unique FY list ─── */
$deno_fy_cols = $conn->query("
    SELECT DISTINCT d.deno_year AS fiscal_code,
           COALESCE(f.fiscal_name, d.deno_year) AS fiscal_name
    FROM deno d
    LEFT JOIN fiscal_years f ON f.fiscal_code = d.deno_year
    WHERE d.deleted_at IS NULL AND d.deno_year IS NOT NULL
    ORDER BY fiscal_code DESC
")->fetchAll(PDO::FETCH_ASSOC);

/* ─── Deno pivot ─── */
$deno_pivot_stmt = $conn->query("
    SELECT
        b.book_code,
        b.book_name,
        COALESCE(b.class_level::text,'') AS class_level,
        COALESCE(b.is_translated,FALSE)  AS is_translated,
        d.deno_year                      AS fy_code,
        SUM(d.total_qty)                 AS deno_qty
    FROM books b
    LEFT JOIN deno d ON d.book_code=b.book_code AND d.deleted_at IS NULL AND d.deno_year IS NOT NULL
    GROUP BY b.book_code,b.book_name,b.class_level,b.is_translated,d.deno_year
    ORDER BY b.book_name, d.deno_year DESC
");
$deno_pivot_raw = $deno_pivot_stmt->fetchAll(PDO::FETCH_ASSOC);

$deno_pivot = [];
foreach ($deno_pivot_raw as $pr) {
    $bc = $pr['book_code'];
    if (!isset($deno_pivot[$bc])) {
        $deno_pivot[$bc] = [
            'book_code'    => $bc,
            'book_name'    => $pr['book_name'],
            'class_level'  => $pr['class_level'],
            'is_translated'=> $pr['is_translated'],
            'total_all_fy' => 0,
        ];
        foreach ($deno_fy_cols as $fc) {
            $deno_pivot[$bc]['fy_'.$fc['fiscal_code']] = 0;
        }
    }
    if ($pr['fy_code']) {
        $deno_pivot[$bc]['fy_'.$pr['fy_code']] = (int)$pr['deno_qty'];
        $deno_pivot[$bc]['total_all_fy'] += (int)$pr['deno_qty'];
    }
}
$deno_pivot = array_values($deno_pivot);

/* ─── Totals ─── */
$totals = ['deno'=>0];
foreach ($modules as $m) $totals[$m['slug']]=0;
foreach ($recon_data as $r) {
    $totals['deno'] += (int)$r['deno_qty'];
    foreach ($modules as $m) $totals[$m['slug']] += (int)($r[$m['slug'].'_qty']??0);
}
$books_count = count($recon_data);

/* ─── Truncate counts ─── */
$trunc_counts = [];
foreach ($modules as $m) {
    $c = $conn->prepare("SELECT COUNT(*) FROM {$m['tbl']} WHERE fiscal_code=:fy");
    $c->execute([':fy'=>$sel_fy]);
    $trunc_counts[$m['slug']] = (int)$c->fetchColumn();
}

/* ─── Price fallback ─── */
function resolve_price($r, $slug, $modules) {
    $p = floatval($r[$slug.'_price']??0); if ($p>0) return $p;
    $mp = floatval($r['marketing_price']??0); if ($mp>0) return $mp;
    foreach ($modules as $m) {
        if ($m['slug']===$slug) continue;
        $op = floatval($r[$m['slug'].'_price']??0); if ($op>0) return $op;
    }
    return 0;
}

/* ─── Closing balance evaluator ─── */
function eval_cb($formula, $row, $modules) {
    if (!$formula) return null;
    $e = strtolower(trim($formula));
    foreach ($modules as $m) {
        $s = strtolower($m['slug']);
        $q = (int)($row[$m['slug'].'_qty']??0);
        $e = preg_replace('/\b'.preg_quote($s,'/').'_qty\b/', $q, $e);
        $e = preg_replace('/\b'.preg_quote($s,'/').'(?!_)\b/', $q, $e);
    }
    $e = preg_replace('/\bdeno_qty\b/', (int)($row['deno_qty']??0), $e);
    $e = preg_replace('/\bdeno\b/',     (int)($row['deno_qty']??0), $e);
    if (!preg_match('/^[\d\s\+\-\*\/\(\)\.]+$/', $e)) return null;
    try { return @eval("return ($e);"); } catch(Throwable $ex) { return null; }
}

/* ─── Export ─── */
if (isset($_GET['export'])) {
    $fn = 'recon_'.($sel_fy?:'all').'_'.date('Y-m-d');
    $cols = ['SN','Book Code','Book Name','Class','FY','Trans','Deno Qty'];
    foreach ($modules as $m) { $cols[]=$m['label'].' Price'; $cols[]=$m['label'].' Qty'; $cols[]=$m['label'].' Total'; }
    foreach ($modules as $m) { $cols[]='Var '.$m['label'].' vs Deno'; }
    if ($cb_formula) $cols[] = 'Closing Bal';
    if ($_GET['export']==='csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment;filename="'.$fn.'.csv"');
        $out=fopen('php://output','w'); fputcsv($out,$cols);
        $sn=1;
        foreach ($recon_data as $r) {
            $row=[$sn++,$r['book_code'],$r['book_name'],$r['class_level'],$r['fy_code'],$r['is_translated']?'Y':'N',(int)$r['deno_qty']];
            foreach ($modules as $m) { $p=resolve_price($r,$m['slug'],$modules); $q=(int)($r[$m['slug'].'_qty']??0); $row[]=$p;$row[]=$q;$row[]=number_format($p*$q,2); }
            foreach ($modules as $m) { $row[]=((int)($r[$m['slug'].'_qty']??0))-(int)$r['deno_qty']; }
            if ($cb_formula) $row[]=eval_cb($cb_formula,$r,$modules)??'';
            fputcsv($out,$row);
        }
        fclose($out); exit;
    }
    if ($_GET['export']==='excel') {
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment;filename="'.$fn.'.xls"');
        echo "<table border='1'><tr>"; foreach($cols as $c) echo "<th>".htmlspecialchars($c)."</th>"; echo "</tr>";
        $sn=1;
        foreach ($recon_data as $r) {
            $pr=[$sn++,$r['book_code'],htmlspecialchars($r['book_name']),$r['class_level'],$r['fy_code'],$r['is_translated']?'Y':'N',(int)$r['deno_qty']];
            foreach($modules as $m){$p=resolve_price($r,$m['slug'],$modules);$q=(int)($r[$m['slug'].'_qty']??0);$pr[]=$p;$pr[]=$q;$pr[]=number_format($p*$q,2);}
            foreach($modules as $m) $pr[]=((int)($r[$m['slug'].'_qty']??0))-(int)$r['deno_qty'];
            if($cb_formula) $pr[]=eval_cb($cb_formula,$r,$modules)??'';
            echo "<tr>"; foreach($pr as $v) echo "<td>".htmlspecialchars((string)$v)."</td>"; echo "</tr>"; $sn++;
        }
        echo "</table>"; exit;
    }
}

$flash=$_SESSION['flash']??null; unset($_SESSION['flash']);

/* ─── FY label map ─── */
$fy_label_map = [];
foreach ($all_fiscal_years as $fy) $fy_label_map[$fy['fiscal_code']] = $fy['fiscal_name'];

require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Stock Reconciliation</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
:root{
  --bg:#f8fafc;--s:#fff;--s2:#f1f5f9;--bd:#e2e8f0;--bd2:#cbd5e1;
  --ac:#2563eb;--acl:#eff6ff;--acd:#1d4ed8;
  --ok:#16a34a;--okl:#f0fdf4;--er:#dc2626;--erl:#fef2f2;
  --wa:#d97706;--wal:#fffbeb;
  --tx:#0f172a;--t2:#475569;--mu:#94a3b8;
  --mo:'JetBrains Mono',monospace;--fn:'Inter',sans-serif;--r:8px;--r2:4px;
  --sh:0 1px 3px rgba(0,0,0,.08),0 1px 2px rgba(0,0,0,.06);
  --sh2:0 4px 12px rgba(0,0,0,.1);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--tx);font-family:var(--fn);font-size:14px;min-height:100vh}
.pw{max-width:1900px;margin:0 auto;padding:18px 16px}

.ph{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px}
.ph h1{font-size:19px;font-weight:700;display:flex;align-items:center;gap:8px}
.ph p{font-size:11px;color:var(--mu);margin-top:2px;font-family:var(--mo)}

.flash{padding:11px 14px;border-radius:var(--r);font-weight:500;margin-bottom:14px;font-size:13px;display:flex;align-items:center;gap:8px}
.flash-s{background:var(--okl);color:var(--ok);border:1px solid #bbf7d0}
.flash-d{background:var(--erl);color:var(--er);border:1px solid #fecaca}

.fb{background:var(--s);border:1px solid var(--bd);border-radius:var(--r);padding:12px 14px;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:14px;box-shadow:var(--sh)}
.fg{display:flex;flex-direction:column;gap:4px}
.fl{font-size:10px;font-weight:600;color:var(--t2);text-transform:uppercase;letter-spacing:.6px}
.fc{background:var(--s2);border:1px solid var(--bd2);color:var(--tx);border-radius:var(--r2);padding:7px 10px;font-size:13px;font-family:var(--fn);min-width:130px;transition:border-color .15s}
.fc:focus{outline:none;border-color:var(--ac);background:#fff}
.fc::placeholder{color:var(--mu)}

.bdd{position:relative}
.bdo{position:absolute;top:calc(100%+4px);left:0;right:0;background:#fff;border:1px solid var(--bd2);border-radius:var(--r);box-shadow:var(--sh2);max-height:240px;overflow-y:auto;z-index:400;display:none;min-width:320px}
.bdi{padding:8px 12px;cursor:pointer;font-size:13px;border-bottom:1px solid var(--bd);transition:background .1s;line-height:1.4}
.bdi:hover{background:var(--acl)}
.bdi .bdc{font-family:var(--mo);font-size:11px;color:var(--ac);font-weight:600}
.bdi .bdm{font-size:11px;color:var(--mu)}

.btn{display:inline-flex;align-items:center;gap:5px;padding:7px 13px;border:none;border-radius:var(--r2);cursor:pointer;font-size:13px;font-weight:500;font-family:var(--fn);transition:all .15s;text-decoration:none;white-space:nowrap}
.bp{background:var(--ac);color:#fff}.bp:hover{background:var(--acd)}
.bs{background:var(--ok);color:#fff}.bs:hover{background:#15803d}
.bi{background:#0284c7;color:#fff}.bi:hover{background:#0369a1}
.bw{background:var(--wa);color:#fff}.bw:hover{background:#b45309}
.bo{background:#fff;border:1px solid var(--bd2);color:var(--t2)}.bo:hover{border-color:var(--ac);color:var(--ac)}
.bd{background:var(--er);color:#fff}.bd:hover{background:#b91c1c}
.bsm{padding:5px 10px;font-size:12px}
.bxs{padding:3px 8px;font-size:11px}

.sg{display:grid;grid-template-columns:repeat(auto-fill,minmax(148px,1fr));gap:10px;margin-bottom:14px}
.sc{background:var(--s);border:1px solid var(--bd);border-radius:var(--r);padding:12px 13px;box-shadow:var(--sh)}
.sl{font-size:10px;font-weight:600;color:var(--mu);text-transform:uppercase;letter-spacing:.4px;margin-bottom:4px}
.sv{font-size:20px;font-weight:700;font-family:var(--mo)}
.ss{font-size:10px;color:var(--mu);margin-top:2px}

.tabs{display:flex;border-bottom:2px solid var(--bd);margin-bottom:14px;flex-wrap:nowrap;overflow-x:auto;gap:0}
.tab{padding:8px 14px;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-2px;color:var(--t2);font-size:13px;font-weight:500;transition:all .15s;background:none;border-top:none;border-left:none;border-right:none;font-family:var(--fn);white-space:nowrap;flex-shrink:0}
.tab:hover{color:var(--ac);background:var(--acl);border-radius:4px 4px 0 0}
.tab.active{color:var(--ac);border-bottom-color:var(--ac);font-weight:600}
.tp{display:none}.tp.active{display:block}

.card{background:var(--s);border:1px solid var(--bd);border-radius:var(--r);overflow:hidden;box-shadow:var(--sh);margin-bottom:12px}
.ch{padding:10px 14px;display:flex;align-items:center;gap:9px;background:var(--s2);border-bottom:1px solid var(--bd)}
.ci{width:27px;height:27px;border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0}
.ct{font-weight:600;font-size:13px;color:var(--tx);flex:1;min-width:0}
.cbg{font-size:11px;padding:2px 7px;border-radius:20px;font-weight:600;font-family:var(--mo);background:var(--s);border:1px solid var(--bd);color:var(--t2);white-space:nowrap}
.cbdy{padding:14px}

.fy-sel-bar{background:var(--acl);border:1px solid #bfdbfe;border-radius:var(--r2);padding:10px 14px;display:flex;align-items:center;gap:12px;margin-bottom:10px;flex-wrap:wrap}
.fy-sel-bar label{font-size:12px;font-weight:600;color:var(--ac)}

.tw{overflow-x:auto}
table.rt{width:100%;border-collapse:collapse;font-size:13px}
table.rt th{background:var(--s2);color:var(--t2);font-size:11px;text-transform:uppercase;letter-spacing:.4px;padding:9px 10px;border-bottom:2px solid var(--bd);white-space:nowrap;text-align:left;font-weight:600;cursor:pointer;user-select:none;position:sticky;top:0;z-index:2}
table.rt th:hover{background:#e2e8f0;color:var(--tx)}
table.rt th.sa::after{content:' ↑';color:var(--ac)}
table.rt th.sd::after{content:' ↓';color:var(--ac)}
table.rt td{padding:7px 10px;border-bottom:1px solid var(--bd);vertical-align:middle}
table.rt tr:hover td{background:var(--acl)}
table.rt tr:nth-child(even) td{background:#fafbfc}
table.rt tr:nth-child(even):hover td{background:var(--acl)}
/* INPUT FIELDS — qty allows 10+ digits, price allows 4 decimals */
table.rt input[type="number"],table.rt input[type="text"]{background:#fff;border:1px solid var(--bd2);color:var(--tx);border-radius:var(--r2);padding:5px 8px;font-size:12px;width:100%;font-family:var(--mo);transition:border-color .15s}
table.rt input:focus{outline:none;border-color:var(--ac);box-shadow:0 0 0 2px rgba(37,99,235,.12)}
/* Wider inputs for large numbers */
table.rt input[type="number"].qty-input{min-width:130px}
table.rt input[type="number"].price-input{min-width:120px}

.pill{display:inline-block;padding:2px 7px;border-radius:20px;font-size:11px;font-weight:600}
.po{background:var(--okl);color:var(--ok);border:1px solid #bbf7d0}
.pb{background:var(--erl);color:var(--er);border:1px solid #fecaca}
.pw2{background:var(--wal);color:var(--wa);border:1px solid #fde68a}
.btg{display:inline-block;padding:1px 5px;border-radius:3px;font-size:10px;font-weight:600;font-family:var(--mo)}
.btg-t{background:#dbeafe;color:#1e40af}
.btg-c{background:#f3e8ff;color:#6b21a8}
.ph2{display:block;font-size:10px;padding:1px 5px;border-radius:3px;font-family:var(--mo);margin-top:2px;background:#fef9c3;color:#713f12;border:1px solid #fde68a}

.vp{color:var(--ok);font-weight:600;font-family:var(--mo)}
.vn{color:var(--er);font-weight:600;font-family:var(--mo)}
.vz{color:var(--mu);font-family:var(--mo)}

.ab{display:flex;gap:7px;flex-wrap:wrap;align-items:center;margin-bottom:12px}
.mono{font-family:var(--mo)}

.csel{background:var(--s);border:1px solid var(--bd);border-radius:var(--r);padding:13px 14px;margin-bottom:12px;box-shadow:var(--sh)}
.csel h4{font-size:11px;font-weight:600;color:var(--t2);text-transform:uppercase;letter-spacing:.4px;margin-bottom:9px}
.cchks{display:flex;gap:7px;flex-wrap:wrap}
.cck{display:flex;align-items:center;gap:6px;padding:5px 12px;border-radius:20px;border:1.5px solid var(--bd2);cursor:pointer;font-size:12px;font-weight:600;background:#fff;transition:all .15s;user-select:none}
.cck input{width:14px;height:14px;cursor:pointer}
.cck.on{background:color-mix(in srgb,currentColor 8%,white)}

.fbox{background:var(--s);border:1px solid var(--bd);border-radius:var(--r);padding:13px 14px;margin-bottom:12px;box-shadow:var(--sh)}
.fbox h4{font-size:11px;font-weight:600;color:var(--t2);text-transform:uppercase;letter-spacing:.4px;margin-bottom:9px}
.ftoks{display:flex;gap:5px;flex-wrap:wrap;margin-bottom:9px}
.ftok{padding:4px 9px;border-radius:4px;font-family:var(--mo);font-size:11px;cursor:pointer;border:1.5px solid;transition:all .15s;font-weight:500}
.ftok:hover{transform:translateY(-1px);box-shadow:var(--sh)}
.fi{width:100%;padding:8px 11px;border:1px solid var(--bd2);border-radius:var(--r2);font-family:var(--mo);font-size:13px;color:var(--tx);background:#fff;transition:border-color .15s}
.fi:focus{outline:none;border-color:var(--ac);box-shadow:0 0 0 2px rgba(37,99,235,.12)}
.fpv{font-size:12px;color:var(--t2);margin-top:8px;font-family:var(--mo);padding:6px 10px;background:var(--s2);border-radius:var(--r2)}

.cbc{background:#fffbeb!important;font-weight:700;font-family:var(--mo);color:#92400e}

.mmgr{background:var(--s);border:1px solid var(--bd);border-radius:var(--r);padding:14px;margin-bottom:12px;box-shadow:var(--sh)}
.mmgr h4{font-size:13px;font-weight:600;margin-bottom:11px}

/* Upload info box */
.upload-info{background:var(--s2);border:1px solid var(--bd);border-radius:var(--r2);padding:7px 11px;font-size:12px;color:var(--t2);margin-bottom:10px;display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.upload-info strong{color:var(--tx)}
.upload-badge{display:inline-flex;align-items:center;gap:4px;padding:2px 7px;border-radius:20px;font-size:11px;font-weight:600}
.ub-csv{background:#dcfce7;color:#166534;border:1px solid #bbf7d0}
.ub-xlsx{background:#dbeafe;color:#1e40af;border:1px solid #bfdbfe}

.ms-wrap{position:relative;min-width:220px}
.ms-input{background:var(--s2);border:1px solid var(--bd2);border-radius:var(--r2);padding:7px 10px;font-size:13px;cursor:pointer;display:flex;align-items:center;gap:6px;user-select:none;transition:border-color .15s;min-height:36px}
.ms-input:hover{border-color:var(--ac)}
.ms-dropdown{position:absolute;top:calc(100%+4px);left:0;z-index:500;background:#fff;border:1px solid var(--bd2);border-radius:var(--r);box-shadow:var(--sh2);min-width:280px;max-width:360px}
.ms-search{width:100%;padding:8px 10px;border:none;border-bottom:1px solid var(--bd);font-size:12px;outline:none;font-family:var(--fn)}
.ms-opts{max-height:220px;overflow-y:auto;padding:4px 0}
.ms-opt{display:flex;align-items:center;gap:7px;padding:6px 12px;cursor:pointer;font-size:12px;transition:background .1s}
.ms-opt:hover{background:var(--acl)}
.ms-opt input{width:14px;height:14px;accent-color:var(--ac);flex-shrink:0}

.filter-strip{display:flex;gap:6px;flex-wrap:wrap;align-items:center;padding:7px 12px;background:var(--acl);border:1px solid #bfdbfe;border-radius:var(--r2);margin-bottom:10px;font-size:12px}
.filter-strip span{color:var(--t2)}
.ftag{display:inline-flex;align-items:center;gap:5px;padding:2px 8px;background:#dbeafe;color:#1e40af;border-radius:20px;font-weight:600;font-size:11px;font-family:var(--mo)}
.ftag a{color:#1e40af;text-decoration:none;font-weight:700;margin-left:2px}
.ftag a:hover{color:var(--er)}

@media(max-width:768px){.sg{grid-template-columns:1fr 1fr}}
@media print{
  body{background:#fff;font-size:11px}
  .fb,.tabs,.ab,.csel,.fbox,.mmgr,.btn,form,.fy-sel-bar{display:none!important}
  .card{border:1px solid #ccc;box-shadow:none}
  table.rt th{background:#f0f0f0!important;font-size:10px}
  table.rt input{border:none;background:transparent}
  table.rt td{font-size:10px;padding:4px 6px}
}
</style>
</head>
<body>
<div class="pw">

<?php if($flash): ?>
<div class="flash flash-<?= $flash['type']==='success'?'s':'d' ?>">
    <?= $flash['type']==='success'?'✓':'✕' ?> <?= htmlspecialchars($flash['msg']) ?>
</div>
<?php endif; ?>

<div class="ph">
    <div>
        <h1>📚 Stock Reconciliation</h1>
        <p>FY <?= htmlspecialchars($fy_label_map[$sel_fy] ?? $sel_fy) ?> (<?= htmlspecialchars($sel_fy) ?>) · <?= $books_count ?> books · <?= count($modules) ?> modules</p>
    </div>
    <div style="display:flex;gap:7px;flex-wrap:wrap">
        <a href="?<?= http_build_query(array_merge($_GET,['export'=>'excel'])) ?>" class="btn bs bsm">📊 Excel</a>
        <a href="?<?= http_build_query(array_merge($_GET,['export'=>'csv'])) ?>"   class="btn bi bsm">📥 CSV</a>
        <button onclick="window.print()" class="btn bw bsm">🖨️ Print</button>
    </div>
</div>

<!-- Filters -->
<div class="fb">
<form method="get" style="display:contents" id="ff">
    <div class="fg">
        <div class="fl">Fiscal Year</div>
        <select name="fiscal_year" class="fc" onchange="document.getElementById('ff').submit()">
            <?php foreach($deno_fiscal_years as $fy): ?>
            <option value="<?= htmlspecialchars($fy['fiscal_code']) ?>" <?= $sel_fy===$fy['fiscal_code']?'selected':'' ?>>
                <?= htmlspecialchars($fy['fiscal_name'] ?: $fy['fiscal_code']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="fg">
        <div class="fl">Book Code (multi-select)</div>
        <div class="ms-wrap" id="ms-book-wrap">
            <div class="ms-input" id="ms-book-display" onclick="toggleMs('book')">
                <span id="ms-book-label"><?php
                    if(!empty($sel_books)){
                        $labels=array_filter(array_map(fn($bc)=>array_column($all_deno_books,'book_name','book_code')[$bc]??$bc, $sel_books));
                        echo htmlspecialchars(count($labels).' selected: '.implode(', ',array_slice($labels,0,2)).(count($labels)>2?'…':''));
                    } else { echo 'All Books'; }
                ?></span>
                <span style="margin-left:auto;font-size:10px;color:var(--mu)">▾</span>
            </div>
            <div class="ms-dropdown" id="ms-book-dd" style="display:none">
                <input type="text" id="ms-book-search" placeholder="Search…" class="ms-search" oninput="filterMs('book',this.value)">
                <div class="ms-opts" id="ms-book-opts">
                    <?php
                    $sorted_books = $all_deno_books;
                    usort($sorted_books, function($a,$b){
                        $ca = $a['class_level']===''?9999:intval($a['class_level']);
                        $cb = $b['class_level']===''?9999:intval($b['class_level']);
                        if($ca !== $cb) return $ca <=> $cb;
                        return strcmp($a['book_name'], $b['book_name']);
                    });
                    $seen_bc = [];
                    foreach($sorted_books as $b):
                        if(isset($seen_bc[$b['book_code']])) continue;
                        $seen_bc[$b['book_code']]=true;
                        $checked = in_array($b['book_code'], $sel_books) ? 'checked' : '';
                        $trans_badge = $b['is_translated'] ? ' <span style="background:#dbeafe;color:#1e40af;font-size:10px;padding:0 4px;border-radius:2px;font-family:var(--mo)">T</span>' : ' <span style="background:#f3f4f6;color:#6b7280;font-size:10px;padding:0 4px;border-radius:2px;font-family:var(--mo)">NT</span>';
                    ?>
                    <label class="ms-opt" data-search="<?= htmlspecialchars(strtolower($b['book_code'].' '.$b['book_name'].' '.($b['class_level']??''))) ?>">
                        <input type="checkbox" name="book_code[]" value="<?= htmlspecialchars($b['book_code']) ?>" <?= $checked ?> onchange="updateMsLabel('book')">
                        <span class="bdc"><?= htmlspecialchars($b['book_code']) ?></span>
                        <?= htmlspecialchars($b['book_name']) ?>
                        <?= $trans_badge ?>
                        <?php if($b['class_level']): ?><span style="color:var(--mu);font-size:11px"> · Cl.<?= htmlspecialchars($b['class_level']) ?></span><?php endif; ?>
                    </label>
                    <?php endforeach; ?>
                </div>
                <div style="padding:6px 10px;display:flex;gap:8px">
                    <button type="button" class="btn bo bxs" onclick="checkAllMs('book',true)">All</button>
                    <button type="button" class="btn bo bxs" onclick="checkAllMs('book',false)">None</button>
                </div>
            </div>
        </div>
    </div>
    <div class="fg">
        <div class="fl">Class Level (multi)</div>
        <div class="ms-wrap" id="ms-cls-wrap">
            <div class="ms-input" id="ms-cls-display" onclick="toggleMs('cls')">
                <span id="ms-cls-label"><?= !empty($sel_classes)?htmlspecialchars(count($sel_classes).' class(es) selected'):'All Classes' ?></span>
                <span style="margin-left:auto;font-size:10px;color:var(--mu)">▾</span>
            </div>
            <div class="ms-dropdown" id="ms-cls-dd" style="display:none">
                <div class="ms-opts" id="ms-cls-opts">
                    <?php foreach($class_levels as $cl):
                        $checked2 = in_array((string)$cl,$sel_classes)?'checked':'';
                    ?>
                    <label class="ms-opt">
                        <input type="checkbox" name="class_level[]" value="<?= htmlspecialchars((string)$cl) ?>" <?= $checked2 ?> onchange="updateMsLabel('cls')">
                        Class <?= htmlspecialchars((string)$cl) ?>
                    </label>
                    <?php endforeach; ?>
                </div>
                <div style="padding:6px 10px;display:flex;gap:8px">
                    <button type="button" class="btn bo bxs" onclick="checkAllMs('cls',true)">All</button>
                    <button type="button" class="btn bo bxs" onclick="checkAllMs('cls',false)">None</button>
                </div>
            </div>
        </div>
    </div>
    <div class="fg">
        <div class="fl">Translated</div>
        <select name="translated" class="fc">
            <option value="">All</option>
            <option value="1" <?= $sel_trans==='1'?'selected':'' ?>>T (Translated)</option>
            <option value="0" <?= $sel_trans==='0'?'selected':'' ?>>NT (Not Translated)</option>
        </select>
    </div>
    <div class="fg">
        <div class="fl">Search</div>
        <input type="text" name="search" class="fc" placeholder="Book name / code…" value="<?= htmlspecialchars($search_term) ?>">
    </div>
    <input type="hidden" name="sort" value="<?= htmlspecialchars($sort_col) ?>">
    <input type="hidden" name="dir"  value="<?= htmlspecialchars($sort_dir) ?>">
    <div class="fg" style="flex-direction:row;gap:6px">
        <button type="submit" class="btn bp">🔍 Filter</button>
        <a href="<?= $_SERVER['PHP_SELF'] ?>" class="btn bo">✕</a>
    </div>
</form>
</div>

<!-- Active filter strip -->
<?php
$has_filter = !empty($sel_books)||!empty($sel_classes)||$sel_trans!==''||$search_term!=='';
if($has_filter):
?>
<div class="filter-strip">
    <span>🔍 Active filters:</span>
    <?php if(!empty($sel_books)):
        foreach($sel_books as $bc):
            $bn = array_column($all_deno_books,'book_name','book_code')[$bc] ?? $bc;
            $rm = array_filter($sel_books,fn($x)=>$x!==$bc);
            $rp = array_merge($_GET,['book_code'=>array_values($rm)]);
    ?>
    <span class="ftag">📖 <?= htmlspecialchars($bc) ?><a href="?<?= htmlspecialchars(http_build_query($rp)) ?>">×</a></span>
    <?php endforeach; endif; ?>
    <?php if(!empty($sel_classes)):
        foreach($sel_classes as $cl):
            $rm2 = array_filter($sel_classes,fn($x)=>$x!=$cl);
            $rp2 = array_merge($_GET,['class_level'=>array_values($rm2)]);
    ?>
    <span class="ftag">📚 Class <?= htmlspecialchars((string)$cl) ?><a href="?<?= htmlspecialchars(http_build_query($rp2)) ?>">×</a></span>
    <?php endforeach; endif; ?>
    <?php if($sel_trans!==''): $rp3=array_merge($_GET,['translated'=>'']); ?>
    <span class="ftag">🔤 <?= $sel_trans==='1'?'Translated':'Not Translated' ?><a href="?<?= htmlspecialchars(http_build_query($rp3)) ?>">×</a></span>
    <?php endif; ?>
    <?php if($search_term!==''): $rp4=array_merge($_GET,['search'=>'']); ?>
    <span class="ftag">🔎 "<?= htmlspecialchars($search_term) ?>"<a href="?<?= htmlspecialchars(http_build_query($rp4)) ?>">×</a></span>
    <?php endif; ?>
    <a href="<?= $_SERVER['PHP_SELF'] ?>" class="ftag" style="background:#fecaca;color:var(--er)">✕ Clear All</a>
</div>
<?php endif; ?>

<!-- Summary cards -->
<div class="sg">
    <div class="sc"><div class="sl">📚 Books</div><div class="sv"><?= number_format($books_count) ?></div><div class="ss">In deno for FY <?= htmlspecialchars($sel_fy) ?></div></div>
    <div class="sc"><div class="sl">📘 Deno Qty</div><div class="sv" style="color:var(--ac)"><?= number_format($totals['deno']) ?></div><div class="ss">SUM(total_qty)</div></div>
    <?php foreach ($modules as $m): ?>
    <div class="sc">
        <div class="sl"><?= $m['icon'] ?> <?= htmlspecialchars($m['label']) ?></div>
        <div class="sv" style="color:<?= htmlspecialchars($m['color']) ?>"><?= number_format($totals[$m['slug']]) ?></div>
        <div class="ss"><?= $totals['deno']>0?round($totals[$m['slug']]/$totals['deno']*100,1).'% of Deno':'' ?></div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Tabs -->
<div class="tabs" id="mainTabs">
    <button class="tab active" onclick="switchTab('overview',this)">📊 Overview</button>
    <button class="tab" onclick="switchTab('deno',this)">📘 Deno</button>
    <?php foreach ($modules as $m): ?>
    <button class="tab" onclick="switchTab('<?= htmlspecialchars($m['slug']) ?>',this)">
        <?= $m['icon'] ?> <?= htmlspecialchars($m['label']) ?>
    </button>
    <?php endforeach; ?>
    <button class="tab" onclick="switchTab('comparison',this)">⚖️ Comparison</button>
    <button class="tab" onclick="switchTab('analysis',this)">📈 Analysis</button>
    <button class="tab" onclick="switchTab('manage',this)">⚙️ Modules</button>
</div>

<!-- ══ OVERVIEW ══ -->
<div class="tp active" id="tab-overview">
<div class="card">
    <div class="ch">
        <div class="ci" style="background:var(--acl)">📋</div>
        <div class="ct">All Modules — FY <?= htmlspecialchars($fy_label_map[$sel_fy]??$sel_fy) ?></div>
        <span class="cbg"><?= $books_count ?> books from deno</span>
    </div>
    <div class="tw">
    <table class="rt">
        <thead>
        <tr>
            <th rowspan="2">#</th>
            <th onclick="srt('overview','book_code')" rowspan="2">Code</th>
            <th onclick="srt('overview','book_name')" rowspan="2">Book Name</th>
            <th onclick="srt('overview','class_level')" rowspan="2">Class</th>
            <th rowspan="2">Trans</th>
            <th rowspan="2">FY</th>
            <th style="color:var(--ac)" onclick="srt('overview','deno_qty')" rowspan="2">Deno Qty</th>
            <?php foreach ($modules as $m): ?>
            <th colspan="3" style="text-align:center;color:<?= htmlspecialchars($m['color']) ?>;background:color-mix(in srgb,<?= htmlspecialchars($m['color']) ?> 6%,white)">
                <?= $m['icon'] ?> <?= htmlspecialchars($m['label']) ?>
            </th>
            <?php endforeach; ?>
            <?php if($cb_formula): ?><th style="background:var(--wal);color:var(--wa)" rowspan="2">Closing Bal</th><?php endif; ?>
            <th rowspan="2">Status</th>
        </tr>
        <tr>
            <?php foreach ($modules as $m): ?>
            <th style="font-size:10px">Price</th><th style="font-size:10px">Qty</th><th style="font-size:10px">Total</th>
            <?php endforeach; ?>
        </tr>
        </thead>
        <tbody>
        <?php $ov_sn=0; foreach ($recon_data as $r): $ov_sn++;
            $all_ok=true; $any=false;
            foreach($modules as $m){if((int)($r[$m['slug'].'_qty']??0)!=(int)$r['deno_qty']) $all_ok=false; if((int)($r[$m['slug'].'_qty']??0)>0) $any=true;}
            $cbv=$cb_formula?eval_cb($cb_formula,$r,$modules):null;
        ?>
        <tr>
            <td class="mono" style="font-size:11px;color:var(--mu)"><?= $ov_sn ?></td>
            <td class="mono" style="color:var(--ac);font-size:12px"><?= htmlspecialchars($r['book_code']) ?></td>
            <td style="font-weight:500;white-space:nowrap"><?= htmlspecialchars($r['book_name']) ?></td>
            <td><?= $r['class_level']?'<span class="btg btg-c">'.htmlspecialchars($r['class_level']).'</span>':'' ?></td>
            <td><?= $r['is_translated']?'<span class="btg btg-t">T</span>':'<span class="btg" style="background:#f3f4f6;color:#6b7280">NT</span>' ?></td>
            <td class="mono" style="font-size:11px"><?= htmlspecialchars($fy_label_map[$r['fy_code']]??$r['fy_code']) ?></td>
            <td class="mono" style="font-weight:700;color:var(--ac)"><?= number_format((int)$r['deno_qty']) ?></td>
            <?php foreach ($modules as $m):
                $p=resolve_price($r,$m['slug'],$modules); $q=(int)($r[$m['slug'].'_qty']??0);
                $own=floatval($r[$m['slug'].'_price']??0)>0;
            ?>
            <td class="mono" style="font-size:12px;color:<?= $own?'inherit':'var(--wa)' ?>">
                <?= number_format($p,4) ?>
                <?php if(!$own&&$p>0): ?><span class="ph2">↑ fallback</span><?php endif; ?>
            </td>
            <td class="mono" style="font-weight:700;color:<?= htmlspecialchars($m['color']) ?>"><?= number_format($q) ?></td>
            <td class="mono" style="font-size:12px"><?= number_format($p*$q,2) ?></td>
            <?php endforeach; ?>
            <?php if($cb_formula): ?><td class="cbc"><?= $cbv!==null?number_format((float)$cbv,2):'—' ?></td><?php endif; ?>
            <td>
                <?php if(!$any): ?><span class="pill pw2">— None</span>
                <?php elseif($all_ok): ?><span class="pill po">✓ Match</span>
                <?php else: ?><span class="pill pb">⚠ Diff</span><?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if(empty($recon_data)): ?><tr><td colspan="30" style="text-align:center;padding:28px;color:var(--mu)">No books found in deno for FY <?= htmlspecialchars($sel_fy) ?>.</td></tr><?php endif; ?>
        </tbody>
        <?php if(!empty($recon_data)):
            $ov_deno_tot = array_sum(array_column($recon_data,'deno_qty'));
            $ov_mod_totals = [];
            foreach($modules as $m){
                $slug_t = $m['slug'];
                $ov_mod_totals[$slug_t] = [
                    'qty'   => array_sum(array_column($recon_data, $slug_t.'_qty')),
                    'total' => array_sum(array_map(fn($r)=>resolve_price($r,$slug_t,$modules)*(int)($r[$slug_t.'_qty']??0), $recon_data)),
                ];
            }
        ?>
        <tfoot>
        <tr style="background:var(--s2);font-weight:700;border-top:2px solid var(--bd2);font-size:12px">
            <td></td><td colspan="5" class="mono" style="color:var(--t2)">TOTAL (<?= $books_count ?> books)</td>
            <td class="mono" style="color:var(--ac)"><?= number_format($ov_deno_tot) ?></td>
            <?php foreach($modules as $m): $sl=$m['slug']; ?>
            <td></td>
            <td class="mono" style="color:<?= htmlspecialchars($m['color']) ?>"><?= number_format($ov_mod_totals[$sl]['qty']) ?></td>
            <td class="mono" style="color:<?= htmlspecialchars($m['color']) ?>"><?= number_format($ov_mod_totals[$sl]['total'],2) ?></td>
            <?php endforeach; ?>
            <?php if($cb_formula): ?><td></td><?php endif; ?>
            <td></td>
        </tr>
        </tfoot>
        <?php endif; ?>
    </table>
    </div>
</div>
</div>

<!-- ══ DENO TAB ══ -->
<div class="tp" id="tab-deno">
<div class="card">
    <div class="ch">
        <div class="ci" style="background:var(--acl)">📘</div>
        <div class="ct">Deno — SUM(total_qty) per Book · One column per Fiscal Year</div>
        <span class="cbg" style="color:var(--ac)">Read-only · <?= count($deno_pivot) ?> books · <?= count($deno_fy_cols) ?> FYs</span>
    </div>
    <div class="tw">
    <table class="rt">
        <thead>
        <tr>
            <th>#</th>
            <th onclick="srtDeno('code')">Code</th>
            <th onclick="srtDeno('name')">Book Name</th>
            <th onclick="srtDeno('cls')">Class</th>
            <th>Trans</th>
            <?php foreach ($deno_fy_cols as $fc): ?>
            <th onclick="srtDeno('fy_<?= htmlspecialchars($fc['fiscal_code']) ?>')"
                style="color:var(--ac);text-align:right;min-width:100px">
                <?= htmlspecialchars($fc['fiscal_name'] ?: $fc['fiscal_code']) ?>
            </th>
            <?php endforeach; ?>
            <th onclick="srtDeno('total_all_fy')" style="color:var(--acd);text-align:right;background:var(--acl)">Total All FY</th>
        </tr>
        </thead>
        <tbody id="denoTbody">
        <?php
        $dsn = 0;
        foreach ($deno_pivot as $r):
            $dsn++;
            $rda  = 'data-code="'  . htmlspecialchars($r['book_code'])   . '"';
            $rda .= ' data-name="' . htmlspecialchars($r['book_name'])   . '"';
            $rda .= ' data-cls="'  . htmlspecialchars($r['class_level']) . '"';
            $rda .= ' data-total_all_fy="' . (int)$r['total_all_fy'] . '"';
            foreach ($deno_fy_cols as $fc) {
                $fcode = $fc['fiscal_code'];
                $rda .= ' data-fy_' . htmlspecialchars($fcode) . '="' . (int)($r['fy_'.$fcode] ?? 0) . '"';
            }
        ?>
        <tr <?= $rda ?>>
            <td class="mono" style="font-size:11px;color:var(--mu)"><?= $dsn ?></td>
            <td class="mono" style="color:var(--ac);font-size:12px"><?= htmlspecialchars($r['book_code']) ?></td>
            <td style="font-weight:500;white-space:nowrap"><?= htmlspecialchars($r['book_name']) ?></td>
            <td><?= $r['class_level'] ? '<span class="btg btg-c">'.htmlspecialchars($r['class_level']).'</span>' : '' ?></td>
            <td><?= $r['is_translated'] ? '<span class="btg btg-t">T</span>' : '' ?></td>
            <?php foreach ($deno_fy_cols as $fc):
                $fcode = $fc['fiscal_code'];
                $qty   = (int)($r['fy_'.$fcode] ?? 0);
            ?>
            <td class="mono" style="text-align:right;<?= $qty > 0 ? 'font-weight:700;color:var(--ac)' : 'color:var(--mu)' ?>">
                <?= $qty > 0 ? number_format($qty) : '&mdash;' ?>
            </td>
            <?php endforeach; ?>
            <td class="mono" style="text-align:right;font-weight:700;background:var(--acl);color:var(--acd)">
                <?= number_format((int)$r['total_all_fy']) ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <?php
        $col_totals = [];
        $grand_total = 0;
        foreach ($deno_fy_cols as $fc) {
            $fcode = $fc['fiscal_code'];
            $ct = array_sum(array_column($deno_pivot, 'fy_'.$fcode));
            $col_totals[$fcode] = $ct;
            $grand_total += $ct;
        }
        if (!empty($deno_pivot)): ?>
        <tfoot>
        <tr style="background:var(--s2);font-weight:700;border-top:2px solid var(--bd2)">
            <td></td>
            <td colspan="4" class="mono" style="color:var(--t2)">TOTAL</td>
            <?php foreach ($deno_fy_cols as $fc): ?>
            <td class="mono" style="text-align:right;color:var(--ac)"><?= number_format($col_totals[$fc['fiscal_code']]) ?></td>
            <?php endforeach; ?>
            <td class="mono" style="text-align:right;background:var(--acl);color:var(--acd)"><?= number_format($grand_total) ?></td>
        </tr>
        </tfoot>
        <?php endif; ?>
        <?php if (empty($deno_pivot)): ?>
        <tr><td colspan="<?= 5 + count($deno_fy_cols) + 1 ?>" style="text-align:center;padding:28px;color:var(--mu)">No books found.</td></tr>
        <?php endif; ?>
    </table>
    </div>
</div>
</div>

<?php foreach ($modules as $m):
    $slug=$m['slug']; $label=$m['label']; $color=$m['color']; $icon=$m['icon'];
    $tc = $trunc_counts[$slug] ?? 0;
?>
<!-- ══ MODULE: <?= htmlspecialchars($label) ?> ══ -->
<div class="tp" id="tab-<?= htmlspecialchars($slug) ?>">

    <!-- Data Management -->
    <div class="card" style="border-color:#fecaca;margin-bottom:10px">
        <div class="ch" style="background:#fef2f2;border-color:#fecaca">
            <div class="ci" style="background:#fee2e2">🗑</div>
            <div class="ct" style="color:var(--er)">Data Management — <?= htmlspecialchars($label) ?></div>
            <span class="cbg" style="color:var(--er);border-color:#fecaca">
                <span id="tc-<?= htmlspecialchars($slug) ?>"><?= $tc ?></span> rows for FY <?= htmlspecialchars($sel_fy) ?>
            </span>
        </div>
        <div class="cbdy" style="display:flex;gap:20px;flex-wrap:wrap;align-items:flex-start">

            <!-- Delete by FY -->
            <form method="post" onsubmit="return confirmTrunc('<?= htmlspecialchars($label) ?>',this,'fy')"
                  style="display:flex;gap:8px;flex-direction:column">
                <div style="font-size:11px;font-weight:700;color:var(--er);text-transform:uppercase;letter-spacing:.4px;margin-bottom:4px">🗑 Delete by Fiscal Year</div>
                <div style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
                    <input type="hidden" name="action" value="truncate_module">
                    <input type="hidden" name="trunc_slug" value="<?= htmlspecialchars($slug) ?>">
                    <input type="hidden" name="active_tab" value="<?= htmlspecialchars($slug) ?>">
                    <div class="fg">
                        <div class="fl">Fiscal Year</div>
                        <select name="trunc_fy" class="fc" id="trunc-fy-<?= htmlspecialchars($slug) ?>"
                                onchange="updateTruncCount('<?= htmlspecialchars($slug) ?>')">
                            <?php foreach ($all_fiscal_years as $fy): ?>
                            <option value="<?= htmlspecialchars($fy['fiscal_code']) ?>" <?= $sel_fy===$fy['fiscal_code']?'selected':'' ?>>
                                <?= htmlspecialchars($fy['fiscal_name']) ?> (<?= htmlspecialchars($fy['fiscal_code']) ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="fg">
                        <div class="fl">Row count</div>
                        <div id="tc2-<?= htmlspecialchars($slug) ?>" style="padding:7px 12px;background:#fff;border:1px solid #fecaca;border-radius:var(--r2);font-family:var(--mo);font-weight:700;color:var(--er);min-width:70px">
                            <span id="tc-<?= htmlspecialchars($slug) ?>-cnt"><?= $tc ?></span> rows
                        </div>
                    </div>
                    <button type="submit" class="btn bd bsm">🗑 Delete FY</button>
                </div>
            </form>

            <!-- Delete ALL -->
            <form method="post" onsubmit="return confirmTrunc('<?= htmlspecialchars($label) ?>',this,'all')"
                  style="display:flex;gap:8px;flex-direction:column">
                <div style="font-size:11px;font-weight:700;color:var(--er);text-transform:uppercase;letter-spacing:.4px;margin-bottom:4px">🗑 Delete ALL Data</div>
                <div style="display:flex;gap:8px;align-items:flex-end">
                    <input type="hidden" name="action" value="truncate_module_all">
                    <input type="hidden" name="trunc_slug" value="<?= htmlspecialchars($slug) ?>">
                    <input type="hidden" name="active_tab" value="<?= htmlspecialchars($slug) ?>">
                    <button type="submit" class="btn bd bsm">🗑 Delete All Rows</button>
                </div>
            </form>

            <!-- Pre-fill from deno -->
            <form method="post" onsubmit="return confirm('Pre-fill <?= htmlspecialchars($label) ?> with book codes from deno for the selected FY?\n\nExisting entries for that FY will NOT be overwritten.')"
                  style="display:flex;gap:8px;flex-direction:column">
                <div style="font-size:11px;font-weight:700;color:var(--ok);text-transform:uppercase;letter-spacing:.4px;margin-bottom:4px">➕ Pre-fill from Deno</div>
                <div style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
                    <input type="hidden" name="action" value="prefill_module">
                    <input type="hidden" name="prefill_slug" value="<?= htmlspecialchars($slug) ?>">
                    <input type="hidden" name="active_tab" value="<?= htmlspecialchars($slug) ?>">
                    <div class="fg">
                        <div class="fl">Fiscal Year</div>
                        <select name="prefill_fy" class="fc">
                            <?php foreach ($deno_fiscal_years as $fy): ?>
                            <option value="<?= htmlspecialchars($fy['fiscal_code']) ?>" <?= $sel_fy===$fy['fiscal_code']?'selected':'' ?>>
                                <?= htmlspecialchars($fy['fiscal_name']?:$fy['fiscal_code']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn bs bsm">➕ Pre-fill Rows</button>
                </div>
                <div style="font-size:11px;color:var(--mu)">Inserts book_code rows with qty=0 for any new books not yet in this module.</div>
            </form>

        </div>
    </div>

    <!-- Upload CSV / XLSX -->
    <div class="card" style="margin-bottom:10px">
        <div class="ch" style="background:color-mix(in srgb,<?= htmlspecialchars($color) ?> 7%,white)">
            <div class="ci" style="background:color-mix(in srgb,<?= htmlspecialchars($color) ?> 15%,white)"><?= $icon ?></div>
            <div class="ct" style="color:<?= htmlspecialchars($color) ?>">Upload CSV / XLSX — <?= htmlspecialchars($label) ?></div>
        </div>
        <div class="cbdy">
            <div class="upload-info">
                <span>Columns: <strong>book_code · book_name · fiscal_year · price · qty · notes</strong></span>
                <span class="upload-badge ub-csv">✓ CSV</span>
                <span class="upload-badge ub-xlsx">✓ XLSX</span>
                <span style="color:var(--mu);font-size:11px">Numbers like <strong>161,743.00</strong> are handled automatically</span>
                <button onclick="dlTpl('<?= htmlspecialchars($slug) ?>')" class="btn bo bxs" type="button">📥 CSV Template</button>
            </div>
            <form method="post" enctype="multipart/form-data" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
                <input type="hidden" name="action" value="upload_csv">
                <input type="hidden" name="upload_module" value="<?= htmlspecialchars($slug) ?>">
                <div class="fg">
                    <div class="fl">Upload Fiscal Year</div>
                    <select name="upload_fiscal_code" class="fc">
                        <?php foreach ($all_fiscal_years as $fy): ?>
                        <option value="<?= htmlspecialchars($fy['fiscal_code']) ?>" <?= $sel_fy===$fy['fiscal_code']?'selected':'' ?>>
                            <?= htmlspecialchars($fy['fiscal_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="fg">
                    <div class="fl">File (.csv or .xlsx)</div>
                    <input type="file" name="csv_file" accept=".csv,.xlsx" class="fc" style="padding:5px">
                </div>
                <button type="submit" class="btn bp">⬆ Upload</button>
            </form>
            <div style="margin-top:8px;font-size:11px;color:var(--mu);padding:6px 10px;background:var(--s2);border-radius:var(--r2)">
                ℹ️ For XLSX: first sheet, first row = header. Quantities like <code>161,743.00</code> or <code>2,941,000.00</code> are parsed correctly.
                Old <strong>.xls</strong> format is not supported — save as .xlsx first.
            </div>
        </div>
    </div>

    <!-- FY selector -->
    <div class="fy-sel-bar">
        <label>📅 Entry Fiscal Year:</label>
        <select id="mod-fy-<?= htmlspecialchars($slug) ?>" class="fc"
                style="min-width:180px"
                onchange="reloadModuleFY('<?= htmlspecialchars($slug) ?>', this.value)">
            <?php foreach ($deno_fiscal_years as $fy): ?>
            <option value="<?= htmlspecialchars($fy['fiscal_code']) ?>" <?= $sel_fy===$fy['fiscal_code']?'selected':'' ?>>
                <?= htmlspecialchars($fy['fiscal_name']?:$fy['fiscal_code']) ?> (<?= htmlspecialchars($fy['fiscal_code']) ?>)
            </option>
            <?php endforeach; ?>
        </select>
        <span style="font-size:12px;color:var(--ac)">Showing books from deno for selected FY</span>
    </div>

    <!-- Manual entry table -->
    <div class="card">
        <div class="ch" style="background:color-mix(in srgb,<?= htmlspecialchars($color) ?> 7%,white)">
            <div class="ci" style="background:color-mix(in srgb,<?= htmlspecialchars($color) ?> 15%,white)"><?= $icon ?></div>
            <div class="ct" style="color:<?= htmlspecialchars($color) ?>"><?= htmlspecialchars($label) ?> — Manual Entry</div>
            <span class="cbg"><?= count($recon_data) ?> books for FY <?= htmlspecialchars($sel_fy) ?></span>
        </div>
        <form method="post" id="form-<?= htmlspecialchars($slug) ?>">
            <input type="hidden" name="action" value="save_<?= htmlspecialchars($slug) ?>">
            <input type="hidden" name="page_fy" value="<?= htmlspecialchars($sel_fy) ?>">
            <input type="hidden" name="active_tab" value="<?= htmlspecialchars($slug) ?>">
            <input type="hidden" name="book_filter" value="<?= htmlspecialchars($sel_book) ?>">
            <input type="hidden" name="trans_filter" value="<?= htmlspecialchars($sel_trans) ?>">
            <input type="hidden" name="class_filter" value="<?= htmlspecialchars($sel_class) ?>">
            <input type="hidden" name="search_filter" value="<?= htmlspecialchars($search_term) ?>">
            <div class="ab" style="padding:10px 14px 0">
                <button type="submit" class="btn bp">💾 Save All</button>
                <button type="button" class="btn bo bsm" onclick="clearMod('<?= htmlspecialchars($slug) ?>')">✕ Clear</button>
                <button type="button" class="btn bo bsm" onclick="autofill('<?= htmlspecialchars($slug) ?>')">🔄 Auto-fill Price</button>
            </div>
            <!-- Column hide toggles -->
            <div style="display:flex;gap:6px;flex-wrap:wrap;padding:8px 14px 0;align-items:center">
                <span style="font-size:11px;color:var(--mu);font-weight:600;text-transform:uppercase;letter-spacing:.4px">Hide cols:</span>
                <button type="button" class="btn bo bxs" onclick="toggleCol('<?= htmlspecialchars($slug) ?>',2)">Class</button>
                <button type="button" class="btn bo bxs" onclick="toggleCol('<?= htmlspecialchars($slug) ?>',3)">Trans</button>
                <button type="button" class="btn bo bxs" onclick="toggleCol('<?= htmlspecialchars($slug) ?>',4)">FY</button>
                <button type="button" class="btn bo bxs" onclick="toggleCol('<?= htmlspecialchars($slug) ?>',5)">Deno Qty</button>
                <button type="button" class="btn bo bxs" onclick="toggleCol('<?= htmlspecialchars($slug) ?>',6)">Price</button>
                <button type="button" class="btn bo bxs" onclick="toggleCol('<?= htmlspecialchars($slug) ?>',8)">Total</button>
                <button type="button" class="btn bo bxs" onclick="toggleCol('<?= htmlspecialchars($slug) ?>',9)">Notes</button>
                <button type="button" class="btn bo bxs" onclick="toggleCol('<?= htmlspecialchars($slug) ?>',10)">Saved</button>
            </div>
            <div class="tw"><table class="rt">
                <thead><tr>
                    <th class="col-sn">#</th>
                    <th onclick="sortMod('<?= htmlspecialchars($slug) ?>','code')">Code</th>
                    <th onclick="sortMod('<?= htmlspecialchars($slug) ?>','name')">Book Name</th>
                    <th class="col-2" onclick="sortMod('<?= htmlspecialchars($slug) ?>','cls')">Class</th>
                    <th class="col-3">Trans</th>
                    <th class="col-4" onclick="sortMod('<?= htmlspecialchars($slug) ?>','fy')">FY</th>
                    <th class="col-5" style="color:var(--ac)">Deno Qty</th>
                    <th class="col-6" style="color:<?= htmlspecialchars($color) ?>">Price (4 dec)</th>
                    <th style="color:<?= htmlspecialchars($color) ?>">Qty (10+ digits)</th>
                    <th class="col-8" style="color:<?= htmlspecialchars($color) ?>">Total</th>
                    <th class="col-9">Notes</th>
                    <th class="col-10">Saved</th>
                </tr>
                <tr style="background:var(--s2);font-weight:700;font-size:12px" id="totrow-<?= htmlspecialchars($slug) ?>">
                    <td></td><td colspan="2" class="mono" style="color:var(--t2)">TOTAL</td>
                    <td class="col-2"></td><td class="col-3"></td><td class="col-4"></td>
                    <td class="col-5 mono" style="color:var(--ac)" id="ttl-deno-<?= htmlspecialchars($slug) ?>"></td>
                    <td class="col-6"></td>
                    <td class="mono" style="color:<?= htmlspecialchars($color) ?>" id="ttl-qty-<?= htmlspecialchars($slug) ?>"></td>
                    <td class="col-8 mono" style="color:<?= htmlspecialchars($color) ?>" id="ttl-tot-<?= htmlspecialchars($slug) ?>"></td>
                    <td class="col-9"></td><td class="col-10"></td>
                </tr>
                </thead>
                <tbody id="tbody-<?= htmlspecialchars($slug) ?>">
                <?php foreach ($recon_data as $i=>$r):
                    $sp = floatval($r[$slug.'_price'] ?? 0);
                    $dp = $sp > 0 ? $sp : resolve_price($r, $slug, $modules);
                    $dq = (int)($r[$slug.'_qty'] ?? 0);
                    $fb = $sp == 0 && $dp > 0;
                ?>
                <tr data-code="<?= htmlspecialchars($r['book_code']) ?>"
                    data-name="<?= htmlspecialchars($r['book_name']) ?>"
                    data-cls="<?= htmlspecialchars($r['class_level']) ?>"
                    data-fy="<?= htmlspecialchars($r['fy_code']) ?>"
                    data-deno="<?= (int)$r['deno_qty'] ?>">
                    <td class="mono col-sn" style="font-size:11px;color:var(--mu)"><?= $i+1 ?></td>
                    <td class="mono" style="color:var(--ac);font-size:12px"><?= htmlspecialchars($r['book_code']) ?>
                        <input type="hidden" name="rows[<?= $i ?>][book_code]"   value="<?= htmlspecialchars($r['book_code']) ?>">
                        <input type="hidden" name="rows[<?= $i ?>][fiscal_code]" value="<?= htmlspecialchars($r['fy_code']) ?>">
                    </td>
                    <td style="font-weight:500;white-space:nowrap"><?= htmlspecialchars($r['book_name']) ?></td>
                    <td class="col-2"><?= $r['class_level']?'<span class="btg btg-c">'.htmlspecialchars($r['class_level']).'</span>':'' ?></td>
                    <td class="col-3"><?= $r['is_translated']?'<span class="btg btg-t">T</span>':'' ?></td>
                    <td class="col-4 mono" style="font-size:11px"><?= htmlspecialchars($fy_label_map[$r['fy_code']]??$r['fy_code']) ?></td>
                    <td class="col-5 mono" style="color:var(--ac);font-weight:600"><?= number_format((int)$r['deno_qty']) ?></td>
                    <td class="col-6" style="min-width:140px">
                        <input type="number"
                               name="rows[<?= $i ?>][price]"
                               id="pr-<?= htmlspecialchars($slug) ?>-<?= $i ?>"
                               class="price-input"
                               value="<?= number_format($dp, 4, '.', '') ?>"
                               min="0"
                               step="0.0001"
                               oninput="calcRow('<?= htmlspecialchars($slug) ?>',<?= $i ?>)">
                        <?php if($fb): ?><span class="ph2">↑ fallback</span><?php endif; ?>
                    </td>
                    <td style="min-width:150px">
                        <input type="number"
                               name="rows[<?= $i ?>][qty]"
                               id="qt-<?= htmlspecialchars($slug) ?>-<?= $i ?>"
                               class="qty-input"
                               value="<?= $dq ?>"
                               min="0"
                               max="9999999999"
                               step="1"
                               oninput="calcRow('<?= htmlspecialchars($slug) ?>',<?= $i ?>)">
                    </td>
                    <td class="col-8"><span id="tot-<?= htmlspecialchars($slug) ?>-<?= $i ?>" class="mono"><?= number_format($dp * $dq, 2) ?></span></td>
                    <td class="col-9"><input type="text" name="rows[<?= $i ?>][notes]" value="<?= htmlspecialchars($r[$slug.'_notes']??'') ?>" placeholder="…"></td>
                    <td class="col-10" style="font-size:10px;color:var(--mu);font-family:var(--mo);white-space:nowrap">
                        <?= !empty($r[$slug.'_updated'])?date('m/d H:i',strtotime($r[$slug.'_updated'])):'' ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if(empty($recon_data)): ?><tr><td colspan="11" style="text-align:center;padding:24px;color:var(--mu)">No books in deno for FY <?= htmlspecialchars($sel_fy) ?>.</td></tr><?php endif; ?>
                </tbody>
            </table></div>
        </form>
    </div>
</div>
<?php endforeach; ?>

<!-- ══ COMPARISON ══ -->
<div class="tp" id="tab-comparison">
    <div class="csel">
        <h4>Select Modules to Compare</h4>
        <div class="cchks" id="cmpChecks">
            <label class="cck on" style="color:var(--ac)">
                <input type="checkbox" value="deno" checked onchange="syncC(this);renderCmp()">📘 Deno
            </label>
            <?php foreach ($modules as $m): ?>
            <label class="cck on" style="color:<?= htmlspecialchars($m['color']) ?>">
                <input type="checkbox" value="<?= htmlspecialchars($m['slug']) ?>" checked onchange="syncC(this);renderCmp()">
                <?= $m['icon'] ?> <?= htmlspecialchars($m['label']) ?>
            </label>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="fbox">
        <h4>📐 Closing Balance Formula
            <small style="font-weight:400;text-transform:none;letter-spacing:0;font-size:10px;color:var(--mu)">
             · click tokens to build · saved per FY
            </small>
        </h4>
        <div class="ftoks">
            <span class="ftok" style="background:var(--acl);color:var(--ac);border-color:var(--ac)" onclick="ins('deno_qty')">📘 deno_qty</span>
            <?php foreach ($modules as $m): ?>
            <span class="ftok" style="background:color-mix(in srgb,<?= htmlspecialchars($m['color']) ?> 10%,white);color:<?= htmlspecialchars($m['color']) ?>;border-color:<?= htmlspecialchars($m['color']) ?>"
                  onclick="ins('<?= htmlspecialchars($m['slug']) ?>_qty')"><?= $m['icon'] ?> <?= htmlspecialchars($m['slug']) ?>_qty</span>
            <?php endforeach; ?>
            <span class="ftok" style="background:var(--s2);color:var(--t2);border-color:var(--bd2)" onclick="ins('+')">+</span>
            <span class="ftok" style="background:var(--s2);color:var(--t2);border-color:var(--bd2)" onclick="ins('-')">−</span>
            <span class="ftok" style="background:var(--s2);color:var(--t2);border-color:var(--bd2)" onclick="ins('*')">×</span>
            <span class="ftok" style="background:var(--s2);color:var(--t2);border-color:var(--bd2)" onclick="ins('/')" >÷</span>
            <span class="ftok" style="background:var(--s2);color:var(--t2);border-color:var(--bd2)" onclick="ins('(')">(</span>
            <span class="ftok" style="background:var(--s2);color:var(--t2);border-color:var(--bd2)" onclick="ins(')')">)</span>
        </div>
        <input type="text" id="cbf" class="fi"
               placeholder="e.g.  deno_qty + marketing_qty - software_qty"
               value="<?= htmlspecialchars($cb_formula) ?>">
        <div class="fpv" id="fpv">
            <?= $cb_formula?'Current: <strong>'.htmlspecialchars($cb_formula).'</strong>':'Click tokens or type your closing balance formula.' ?>
        </div>
        <div style="display:flex;gap:7px;margin-top:9px">
            <a id="sfBtn" href="#" class="btn bp bsm">💾 Save Formula</a>
            <button onclick="document.getElementById('cbf').value='';upPv()" class="btn bo bsm">✕ Clear</button>
        </div>
    </div>

    <div class="ab">
        <button onclick="expCmpCSV()" class="btn bi bsm">📥 Export CSV</button>
        <button onclick="window.print()" class="btn bw bsm">🖨️ Print</button>
    </div>

    <div class="card">
        <div class="ch">
            <div class="ci" style="background:var(--acl)">⚖️</div>
            <div class="ct">Qty · Price×Total · % Variance · Closing Balance</div>
        </div>
        <div class="tw" id="cmpWrap"></div>
    </div>
</div>

<!-- ══ ANALYSIS ══ -->
<div class="tp" id="tab-analysis">
    <div class="card">
        <div class="ch"><div class="ci" style="background:var(--acl)">📈</div><div class="ct">Qty per Module</div></div>
        <div style="padding:18px"><canvas id="aC" height="80"></canvas></div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <div class="card">
            <div class="ch"><div class="ct">Top Discrepancies vs Deno</div></div>
            <div class="tw"><table class="rt">
                <thead><tr><th>Book</th><th>Module</th><th>Deno</th><th>Qty</th><th>Diff</th></tr></thead>
                <tbody>
                <?php
                $disc=[];
                foreach($recon_data as $r) foreach($modules as $m){
                    $d=(int)(($r[$m['slug'].'_qty']??0)-(int)$r['deno_qty']);
                    if($d!==0) $disc[]=['n'=>$r['book_name'],'m'=>$m['label'],'c'=>$m['color'],'dq'=>$r['deno_qty'],'q'=>$r[$m['slug'].'_qty']??0,'d'=>$d];
                }
                usort($disc,fn($a,$b)=>abs($b['d'])<=>abs($a['d']));
                foreach(array_slice($disc,0,12) as $dr): ?>
                <tr>
                    <td style="font-weight:500"><?= htmlspecialchars(mb_strimwidth($dr['n'],0,24,'…')) ?></td>
                    <td><span style="color:<?= htmlspecialchars($dr['c']) ?>;font-weight:600;font-size:12px"><?= htmlspecialchars($dr['m']) ?></span></td>
                    <td class="mono"><?= number_format((int)$dr['dq']) ?></td>
                    <td class="mono"><?= number_format((int)$dr['q']) ?></td>
                    <td class="<?= $dr['d']>0?'vp':'vn' ?>"><?= ($dr['d']>0?'+':'').number_format($dr['d']) ?></td>
                </tr>
                <?php endforeach;
                if(empty($disc)): ?><tr><td colspan="5" style="text-align:center;color:var(--ok);padding:16px;font-weight:600">✓ All modules match!</td></tr><?php endif; ?>
                </tbody>
            </table></div>
        </div>
        <div class="card">
            <div class="ch"><div class="ct">Module Coverage vs Deno</div></div>
            <div style="padding:14px">
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:11px;padding-bottom:10px;border-bottom:1px solid var(--bd)">
                    <div style="width:10px;height:10px;border-radius:50%;background:var(--ac);flex-shrink:0"></div>
                    <div style="flex:1;font-size:13px;font-weight:500">Deno (Baseline)</div>
                    <div class="mono" style="font-weight:700"><?= number_format($totals['deno']) ?></div>
                </div>
                <?php foreach($modules as $m): ?>
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:9px">
                    <div style="width:10px;height:10px;border-radius:50%;background:<?= htmlspecialchars($m['color']) ?>;flex-shrink:0"></div>
                    <div style="flex:1;font-size:13px"><?= htmlspecialchars($m['label']) ?></div>
                    <div class="mono" style="font-weight:700"><?= number_format($totals[$m['slug']]) ?></div>
                    <?php if($totals['deno']>0): ?>
                    <div style="font-size:11px;color:var(--mu);font-family:var(--mo);min-width:38px;text-align:right"><?= round($totals[$m['slug']]/$totals['deno']*100,1) ?>%</div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- ══ MODULE MANAGER ══ -->
<div class="tp" id="tab-manage">
    <div class="mmgr">
        <h4>➕ Add New Module</h4>
        <form method="post" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
            <input type="hidden" name="action" value="add_module">
            <div class="fg"><div class="fl">Slug (a-z_0-9)</div>
                <input type="text" name="new_slug" class="fc" placeholder="e.g. physical_stock" pattern="[a-z0-9_]+" required></div>
            <div class="fg"><div class="fl">Display Label</div>
                <input type="text" name="new_label" class="fc" placeholder="e.g. Physical Stock" required></div>
            <div class="fg"><div class="fl">Color</div>
                <input type="color" name="new_color" class="fc" value="#3b82f6" style="min-width:60px;padding:4px"></div>
            <div class="fg"><div class="fl">Icon (emoji)</div>
                <input type="text" name="new_icon" class="fc" placeholder="📦" maxlength="4" style="min-width:70px"></div>
            <button type="submit" class="btn bp">➕ Create Module + Table</button>
        </form>
    </div>
    <div class="card">
        <div class="ch"><div class="ci" style="background:var(--acl)">⚙️</div><div class="ct">Active Modules</div></div>
        <div class="tw"><table class="rt">
            <thead><tr><th>Slug</th><th>Label</th><th>DB Table</th><th>Color</th><th>Icon</th><th>Type</th><th>Rows (sel FY)</th><th>Action</th></tr></thead>
            <tbody>
            <tr>
                <td class="mono" style="color:var(--ac)">deno</td>
                <td style="font-weight:600">Deno (from deno table)</td>
                <td class="mono" style="font-size:11px">public.deno</td>
                <td><div style="width:18px;height:18px;border-radius:3px;background:var(--ac)"></div></td>
                <td>📘</td><td><span class="pill po">Built-in</span></td>
                <td class="mono"><?= number_format($books_count) ?></td>
                <td><span style="color:var(--mu);font-size:11px">Read-only</span></td>
            </tr>
            <?php foreach($modules as $m): $bi=in_array($m['slug'],array_column($built_in,0)); ?>
            <tr>
                <td class="mono" style="color:<?= htmlspecialchars($m['color']) ?>"><?= htmlspecialchars($m['slug']) ?></td>
                <td style="font-weight:600"><?= htmlspecialchars($m['label']) ?></td>
                <td class="mono" style="font-size:11px"><?= htmlspecialchars($m['tbl']) ?></td>
                <td><div style="width:18px;height:18px;border-radius:3px;background:<?= htmlspecialchars($m['color']) ?>"></div></td>
                <td><?= $m['icon'] ?></td>
                <td><?= $bi?'<span class="pill po">Built-in</span>':'<span class="pill pw2">Custom</span>' ?></td>
                <td class="mono"><?= number_format($trunc_counts[$m['slug']]??0) ?></td>
                <td>
                    <?php if(!$bi): ?>
                    <form method="post" style="display:inline" onsubmit="return confirm('Hide this module?')">
                        <input type="hidden" name="action" value="delete_module">
                        <input type="hidden" name="del_slug" value="<?= htmlspecialchars($m['slug']) ?>">
                        <button type="submit" class="btn bd bxs">Hide</button>
                    </form>
                    <?php else: ?><span style="color:var(--mu);font-size:11px">Protected</span><?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
</div>

</div><!-- /pw -->

<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
/* ── Data from PHP ── */
const RD = <?= json_encode(array_map(function($r) use ($modules) {
    $row=['code'=>$r['book_code'],'name'=>$r['book_name'],'fy'=>$r['fy_code'],
          'cls'=>(int)($r['class_level']??0),'tr'=>(bool)$r['is_translated'],
          'deno_qty'=>(int)$r['deno_qty']];
    foreach($modules as $m){
        $row[$m['slug'].'_price']=(float)($r[$m['slug'].'_price']??0);
        $row[$m['slug'].'_qty']=(int)($r[$m['slug'].'_qty']??0);
    }
    return $row;
},$recon_data),JSON_UNESCAPED_UNICODE) ?>;

const MODS = {
    deno:{label:'Deno',color:'#2563eb',pkey:null,qkey:'deno_qty'},
    <?php foreach($modules as $m): ?>
    <?= json_encode($m['slug']) ?>:{label:<?= json_encode($m['label']) ?>,color:<?= json_encode($m['color']) ?>,
        pkey:<?= json_encode($m['slug'].'_price') ?>,qkey:<?= json_encode($m['slug'].'_qty') ?>},
    <?php endforeach; ?>
};

/* ── Price fallback ── */
function resP(r,slug){
    if(slug==='deno') return 0;
    let p=r[slug+'_price']||0; if(p>0) return p;
    p=r['marketing_price']||0; if(p>0) return p;
    for(const[s,m] of Object.entries(MODS)){if(s===slug||s==='deno') continue; p=r[m.pkey]||0; if(p>0) return p;}
    return 0;
}

/* ── Number formatting with commas ── */
function fmtNum(n, dec=2){
    return parseFloat(n).toLocaleString(undefined,{minimumFractionDigits:dec,maximumFractionDigits:dec});
}
function fmtQty(n){
    return Math.round(n).toLocaleString();
}

/* ── Tab switch ── */
function switchTab(id,btn){
    document.querySelectorAll('.tp').forEach(p=>p.classList.remove('active'));
    document.querySelectorAll('.tab').forEach(t=>t.classList.remove('active'));
    const panel=document.getElementById('tab-'+id);
    if(panel) panel.classList.add('active');
    if(btn) btn.classList.add('active');
    if(id==='analysis') buildChart();
    if(id==='comparison') renderCmp();
}

/* ── Multi-select dropdowns ── */
function toggleMs(id){
    const dd=document.getElementById('ms-'+id+'-dd');
    if(!dd) return;
    const open=dd.style.display==='block';
    document.querySelectorAll('.ms-dropdown').forEach(d=>d.style.display='none');
    dd.style.display=open?'none':'block';
    if(!open){ const s=document.getElementById('ms-'+id+'-search'); if(s){s.value='';filterMs(id,'');s.focus();} }
}
function filterMs(id,q){
    document.querySelectorAll('#ms-'+id+'-opts .ms-opt').forEach(o=>{
        o.style.display=(o.dataset.search||'').includes(q.toLowerCase())?'':'none';
    });
}
function updateMsLabel(id){
    const checked=[...document.querySelectorAll('#ms-'+id+'-opts input:checked')];
    const lbl=document.getElementById('ms-'+id+'-label');
    if(!lbl) return;
    if(id==='book'){
        if(checked.length===0){ lbl.textContent='All Books'; }
        else{
            const names=checked.map(c=>(c.closest('.ms-opt')?.querySelector('.bdc')?.textContent?.trim()||c.value));
            lbl.textContent=checked.length+' selected: '+names.slice(0,3).join(', ')+(names.length>3?'...':'');
        }
    } else {
        lbl.textContent=checked.length?checked.length+' class(es) selected':'All Classes';
    }
}
function checkAllMs(id,state){
    document.querySelectorAll('#ms-'+id+'-opts .ms-opt').forEach(o=>{
        if(o.style.display!=='none') o.querySelector('input').checked=state;
    });
    updateMsLabel(id);
}

/* ── Sort overview table ── */
function srt(tabId,col){
    const tbl=document.querySelector('#tab-'+tabId+' table.rt tbody'); if(!tbl) return;
    const rows=[...tbl.querySelectorAll('tr')];
    const th=document.querySelector('#tab-'+tabId+' th[onclick*="\''+col+'\'"]');
    const asc=th?!th.classList.contains('sa'):true;
    document.querySelectorAll('#tab-'+tabId+' th').forEach(h=>h.classList.remove('sa','sd'));
    if(th) th.classList.add(asc?'sa':'sd');
    const map={book_code:0,book_name:1,class_level:2,fy_code:4,deno_qty:5};
    const ci=map[col]??0;
    rows.sort((a,b)=>{
        const av=a.cells[ci]?.textContent.trim()||'',bv=b.cells[ci]?.textContent.trim()||'';
        const an=parseFloat(av.replace(/,/g,'')),bn=parseFloat(bv.replace(/,/g,''));
        if(!isNaN(an)&&!isNaN(bn)) return asc?an-bn:bn-an;
        return asc?av.localeCompare(bv):bv.localeCompare(av);
    });
    rows.forEach(r=>tbl.appendChild(r));
}

/* ── Module row sort ── */
function sortMod(slug,col){
    const tb=document.getElementById('tbody-'+slug); if(!tb) return;
    const rows=[...tb.querySelectorAll('tr')];
    const asc=tb.dataset.sc!==col||tb.dataset.sd==='desc';
    tb.dataset.sc=col; tb.dataset.sd=asc?'asc':'desc';
    rows.sort((a,b)=>{
        const av=a.dataset[col]||'',bv=b.dataset[col]||'';
        const an=parseFloat(av),bn=parseFloat(bv);
        if(!isNaN(an)&&!isNaN(bn)) return asc?an-bn:bn-an;
        return asc?av.localeCompare(bv):bv.localeCompare(av);
    });
    rows.forEach(r=>tb.appendChild(r));
}

/* ── Calc row total (4 decimal price, large qty, formatted total) ── */
function calcRow(slug,idx){
    const pr=document.getElementById(`pr-${slug}-${idx}`);
    const qt=document.getElementById(`qt-${slug}-${idx}`);
    const tot=document.getElementById(`tot-${slug}-${idx}`);
    if(!pr||!qt||!tot) return;
    const price = parseFloat(pr.value || 0);
    const qty   = parseInt(qt.value || 0, 10);
    const total = price * qty;
    // Display total formatted with commas, 2 decimal places
    tot.textContent = total.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2});
    updateModTotals(slug);
}

/* ── Clear module ── */
function clearMod(slug){
    if(!confirm(`Clear all ${slug} entries?`)) return;
    document.querySelectorAll(`#form-${slug} input[type="number"]`).forEach(i=>i.value='0');
    document.querySelectorAll(`#form-${slug} input[type="text"]`).forEach(i=>i.value='');
    document.querySelectorAll(`[id^="tot-${slug}-"]`).forEach(el=>el.textContent='0.00');
    updateModTotals(slug);
}

/* ── Auto-fill price ── */
function autofill(slug){
    RD.forEach((r,i)=>{
        const el=document.getElementById(`pr-${slug}-${i}`);
        if(el&&parseFloat(el.value||0)===0){
            const p=resP(r,slug);
            if(p>0){ el.value=p.toFixed(4); calcRow(slug,i); }
        }
    });
}

/* ── Reload module when FY changes ── */
function reloadModuleFY(slug, fy){
    const url=new URL(window.location.href);
    url.searchParams.set('fiscal_year',fy);
    url.searchParams.set('active_tab',slug);
    window.location.href=url.toString();
}

/* ── Truncate confirm ── */
function confirmTrunc(label, form, type){
    if(type==='all') return confirm(`⚠️ DELETE ALL DATA from "${label}"?\n\nEvery fiscal year will be erased. This cannot be undone.`);
    const fy=form.querySelector('[name="trunc_fy"]').value;
    const cnt=form.querySelector('[id$="-cnt"]')?.textContent?.trim()||'?';
    return confirm(`⚠️ Delete ALL ${cnt} rows from "${label}" for FY ${fy}?\n\nThis cannot be undone.`);
}

/* ── Update truncate count display (AJAX) ── */
function updateTruncCount(slug){
    const sel=document.getElementById('trunc-fy-'+slug);
    const cnt1=document.getElementById('tc-'+slug+'-cnt');
    const cnt2=document.getElementById('tc-'+slug);
    if(!sel) return;
    const fy=sel.value;
    fetch(`?ajax=trunc_count&slug=${encodeURIComponent(slug)}&fy=${encodeURIComponent(fy)}`)
        .then(r=>r.json()).then(d=>{
            const c=(d.count||0);
            if(cnt1) cnt1.textContent=c;
            if(cnt2) cnt2.textContent=c+' rows for FY '+fy;
        })
        .catch(()=>{ if(cnt1) cnt1.textContent='?'; });
}

/* ── CSV template download ── */
function dlTpl(slug){
    const fy=<?= json_encode($sel_fy) ?>;
    const rows=RD.map(r=>[
        r.code,
        '"'+r.name.replace(/"/g,'""')+'"',
        r.fy,
        resP(r,slug).toFixed(4),
        (r[MODS[slug]?.qkey]||0),
        ''
    ].join(','));
    const a=document.createElement('a');
    a.href=URL.createObjectURL(new Blob(
        ['book_code,book_name,fiscal_year,price,qty,notes\n'+rows.join('\n')],
        {type:'text/csv'}
    ));
    a.download=`template_${slug}_${fy}.csv`;
    a.click();
}

/* ── Formula builder ── */
const cbf=document.getElementById('cbf');
function ins(t){
    if(!cbf)return;
    const p=cbf.selectionStart,v=cbf.value;
    cbf.value=v.slice(0,p)+' '+t+' '+v.slice(p);
    cbf.focus();
    upPv();
}
function upPv(){
    const f=cbf?.value?.trim()||'';
    document.getElementById('fpv').innerHTML=f?'Formula: <strong>'+f+'</strong>':'Click tokens or type your closing balance formula.';
    const u=new URL(window.location.href);
    u.searchParams.set('save_formula','1');
    u.searchParams.set('cb_formula',f);
    const b=document.getElementById('sfBtn');
    if(b) b.href=u.toString();
}
if(cbf){cbf.addEventListener('input',upPv);upPv();}

function evalF(formula,row){
    if(!formula) return null;
    let e=formula.toLowerCase();
    for(const[s,m] of Object.entries(MODS)){
        if(s==='deno') continue;
        const q=row[m.qkey]||0;
        e=e.replace(new RegExp('\\b'+s+'_qty\\b','g'),q);
        e=e.replace(new RegExp('\\b'+s+'(?!_)\\b','g'),q);
    }
    e=e.replace(/\bdeno_qty\b/g,row.deno_qty||0).replace(/\bdeno\b/g,row.deno_qty||0);
    if(!/^[\d\s\+\-\*\/\(\)\.]+$/.test(e)) return null;
    try{return Function('"use strict";return ('+e+')')();}catch(ex){return null;}
}

function syncC(cb){cb.closest('.cck').classList.toggle('on',cb.checked);}

/* ── Comparison table ── */
function renderCmp(){
    const sel=[...document.querySelectorAll('#cmpChecks input:checked')].map(c=>c.value);
    const wrap=document.getElementById('cmpWrap');
    if(sel.length<2){wrap.innerHTML='<div style="padding:24px;color:var(--mu);text-align:center;font-size:13px">Select at least 2 modules.</div>';return;}
    const base=sel.includes('deno')?'deno':sel[0];
    const bM=MODS[base];
    const formula=cbf?.value?.trim()||'';

    let h1=`<tr><th rowspan="2" onclick="srtCmp('sn')">SN</th>
        <th rowspan="2" onclick="srtCmp('code')">Code</th>
        <th rowspan="2" onclick="srtCmp('name')">Book Name</th>
        <th rowspan="2" onclick="srtCmp('cls')">Class</th>
        <th rowspan="2">Trans</th>
        <th rowspan="2" onclick="srtCmp('fy')">FY</th>`;
    let h2='<tr>';
    sel.forEach(m=>{
        const md=MODS[m],isDeno=m==='deno';
        h1+=`<th colspan="${isDeno?1:4}" style="text-align:center;color:${md.color};background:color-mix(in srgb,${md.color} 6%,white)">${md.label}</th>`;
        h2+=`<th style="color:${md.color}" onclick="srtCmp('${m}_qty')">Qty</th>`;
        if(!isDeno){h2+=`<th style="color:${md.color}">Price</th><th style="color:${md.color}">Total</th><th onclick="srtCmp('${m}_var')">Var vs ${bM.label}</th>`;}
    });
    if(formula) h1+=`<th rowspan="2" style="background:var(--wal);color:var(--wa)" onclick="srtCmp('cb')">Closing Bal</th>`;
    h1+=`<th rowspan="2">Status</th></tr>`; h2+=`</tr>`;

    let rows='';
    RD.forEach((r,i)=>{
        const bQ=r[bM.qkey]||0;
        const ok=sel.filter(m=>m!==base).every(m=>(r[MODS[m].qkey]||0)===bQ);
        const pill=ok?'<span class="pill po">✓</span>':'<span class="pill pb">⚠</span>';
        let cells=''; const vm={};
        sel.forEach(m=>{
            const md=MODS[m],isDeno=m==='deno';
            const qty=r[md.qkey]||0;
            const price=md.pkey?resP(r,m):0;
            const total=price*qty;
            const diff=qty-bQ;
            const pct=bQ?(diff/bQ*100).toFixed(1)+'%':'—';
            const cls=diff>0?'vp':diff<0?'vn':'vz';
            const sign=diff>=0?'+':'';
            vm[m+'_var']=diff;
            cells+=`<td class="mono" style="color:${md.color};font-weight:600">${fmtQty(qty)}</td>`;
            if(!isDeno){
                cells+=`<td class="mono" style="font-size:12px">${fmtNum(price,4)}</td>`;
                cells+=`<td class="mono" style="font-size:12px">${fmtNum(total,2)}</td>`;
                cells+=`<td class="${cls}">${sign}${fmtQty(diff)} (${pct})</td>`;
            }
        });
        const cb=formula?evalF(formula,r):null;
        const cbCell=formula?`<td class="cbc">${cb!==null?fmtNum(parseFloat(cb.toFixed(2)),2):'—'}</td>`:'';
        rows+=`<tr data-sn="${i+1}" data-code="${r.code}" data-name="${r.name}" data-cls="${r.cls}" data-fy="${r.fy}" ${Object.entries(vm).map(([k,v])=>`data-${k}="${v}"`).join(' ')}>
            <td class="mono" style="font-size:12px">${i+1}</td>
            <td class="mono" style="color:var(--ac);font-size:12px">${r.code}</td>
            <td style="font-weight:500;white-space:nowrap">${r.name}</td>
            <td>${r.cls?`<span class="btg btg-c">${r.cls}</span>`:''}</td>
            <td>${r.tr?'<span class="btg btg-t">T</span>':''}</td>
            <td class="mono" style="font-size:11px">${r.fy}</td>
            ${cells}${cbCell}<td>${pill}</td></tr>`;
    });
    wrap.innerHTML=`<table class="rt" id="cmpT"><thead>${h1}${h2}</thead><tbody>${rows||'<tr><td colspan="20" style="padding:28px;text-align:center;color:var(--mu)">No data.</td></tr>'}</tbody></table>`;
}

function srtCmp(col){
    const tb=document.querySelector('#cmpT tbody'); if(!tb) return;
    const rows=[...tb.querySelectorAll('tr')];
    const asc=!tb.dataset['a_'+col]; tb.dataset['a_'+col]=asc?'1':'';
    rows.sort((a,b)=>{
        const av=a.dataset[col]||'',bv=b.dataset[col]||'';
        const an=parseFloat(av),bn=parseFloat(bv);
        if(!isNaN(an)&&!isNaN(bn)) return asc?an-bn:bn-an;
        return asc?av.localeCompare(bv):bv.localeCompare(av);
    });
    rows.forEach(r=>tb.appendChild(r));
}

function expCmpCSV(){
    const sel=[...document.querySelectorAll('#cmpChecks input:checked')].map(c=>c.value);
    const base=sel.includes('deno')?'deno':sel[0];
    const formula=cbf?.value?.trim()||'';
    const headers=['SN','Code','Book Name','Class','FY'];
    sel.forEach(m=>{const l=MODS[m].label;headers.push(l+' Qty');if(m!=='deno') headers.push(l+' Price',l+' Total','Var vs '+MODS[base].label);});
    if(formula) headers.push('Closing Balance');
    const lines=[headers.join(',')];
    RD.forEach((r,i)=>{
        const row=[i+1,r.code,'"'+r.name.replace(/"/g,'""')+'"',r.cls,r.fy];
        sel.forEach(m=>{
            const md=MODS[m];
            const qty=r[md.qkey]||0;
            const price=md.pkey?resP(r,m):0;
            row.push(qty);
            if(m!=='deno') row.push(price.toFixed(4),(price*qty).toFixed(2),(qty-(r[MODS[base].qkey]||0)));
        });
        if(formula){const cb=evalF(formula,r);row.push(cb!==null?parseFloat(cb.toFixed(2)):'');}
        lines.push(row.join(','));
    });
    const a=document.createElement('a');
    a.href=URL.createObjectURL(new Blob([lines.join('\n')],{type:'text/csv'}));
    a.download='comparison_<?= htmlspecialchars($sel_fy) ?>_'+new Date().toISOString().split('T')[0]+'.csv';
    a.click();
}

/* ── Chart ── */
let chart=null;
function buildChart(){
    if(chart) chart.destroy();
    chart=new Chart(document.getElementById('aC'),{
        type:'bar',
        data:{
            labels:RD.map(r=>r.name.length>16?r.name.slice(0,16)+'…':r.name),
            datasets:[
                {label:'Deno',data:RD.map(r=>r.deno_qty),backgroundColor:'rgba(37,99,235,.7)'},
                <?php foreach($modules as $m): ?>
                {label:<?= json_encode($m['label']) ?>,data:RD.map(r=>r[<?= json_encode($m['slug'].'_qty') ?>]||0),backgroundColor:<?= json_encode($m['color']) ?>+'b3'},
                <?php endforeach; ?>
            ]
        },
        options:{
            responsive:true,
            plugins:{
                legend:{labels:{color:'#475569',font:{family:'Inter'}}},
                tooltip:{
                    mode:'index',intersect:false,
                    callbacks:{
                        label: ctx => ctx.dataset.label+': '+fmtQty(ctx.raw)
                    }
                }
            },
            scales:{
                x:{ticks:{color:'#94a3b8',maxRotation:45,font:{size:10}},grid:{color:'rgba(0,0,0,.04)'}},
                y:{
                    ticks:{
                        color:'#94a3b8',
                        callback: v => fmtQty(v)
                    },
                    grid:{color:'rgba(0,0,0,.04)'}
                }
            }
        }
    });
}

/* ── Toggle column visibility in module tables ── */
function toggleCol(slug, colIdx){
    const tbl=document.querySelector(`#tab-${slug} table.rt`);
    if(!tbl) return;
    const cls='col-'+colIdx;
    const cells=tbl.querySelectorAll('.'+cls);
    const hidden=cells.length>0&&cells[0].style.display==='none';
    cells.forEach(c=>c.style.display=hidden?'':'none');
    const colNames={2:'Class',3:'Trans',4:'FY',5:'Deno Qty',6:'Price',8:'Total',9:'Notes',10:'Saved'};
    document.querySelectorAll(`#tab-${slug} .btn.bo.bxs`).forEach(b=>{
        if(b.textContent.trim()===colNames[colIdx]){
            b.style.background=hidden?'':'#fee2e2';
            b.style.borderColor=hidden?'':'var(--er)';
            b.style.color=hidden?'':'var(--er)';
        }
    });
}

/* ── Update module tab totals row ── */
function updateModTotals(slug){
    let totalDeno=0, totalQty=0, totalAmt=0;
    // Sum deno from data attributes
    const tb=document.getElementById('tbody-'+slug);
    if(tb) tb.querySelectorAll('tr').forEach(row=>{
        totalDeno += parseInt(row.dataset.deno||0);
    });
    // Sum qty and total from inputs
    const qtyInputs  = document.querySelectorAll(`#form-${slug} input[name*="[qty]"]`);
    const priceInputs = document.querySelectorAll(`#form-${slug} input[name*="[price]"]`);
    qtyInputs.forEach((qi,idx)=>{
        const q=parseInt(qi.value||0,10);
        totalQty += q;
        const pi=priceInputs[idx];
        if(pi) totalAmt += parseFloat(pi.value||0)*q;
    });
    const dEl=document.getElementById('ttl-deno-'+slug);
    const qEl=document.getElementById('ttl-qty-'+slug);
    const tEl=document.getElementById('ttl-tot-'+slug);
    if(dEl) dEl.textContent=fmtQty(totalDeno);
    if(qEl) qEl.textContent=fmtQty(totalQty);
    if(tEl) tEl.textContent=fmtNum(totalAmt,2);
}

/* ── Deno table sort ── */
function srtDeno(col){
    const tb=document.getElementById('denoTbody'); if(!tb) return;
    const rows=[...tb.querySelectorAll('tr')];
    const asc=!tb.dataset['a_'+col]; tb.dataset['a_'+col]=asc?'1':'';
    rows.sort((a,b)=>{
        const av=a.dataset[col]||'',bv=b.dataset[col]||'';
        const an=parseFloat(av),bn=parseFloat(bv);
        if(!isNaN(an)&&!isNaN(bn)) return asc?an-bn:bn-an;
        return asc?av.localeCompare(bv):bv.localeCompare(av);
    });
    rows.forEach((r,i)=>{ r.cells[0].textContent=i+1; tb.appendChild(r); });
}

/* ── Init ── */
document.addEventListener('DOMContentLoaded',()=>{
    // Close multi-select on outside click
    document.addEventListener('click',function(e){
        if(!e.target.closest('.ms-wrap')){
            document.querySelectorAll('.ms-dropdown').forEach(d=>d.style.display='none');
        }
    });
    // Restore active tab
    const activeTab=<?= json_encode($active_tab) ?>;
    if(activeTab && activeTab!=='overview'){
        const btn=document.querySelector(`.tab[onclick*="'${activeTab}'"]`);
        if(btn) switchTab(activeTab,btn);
    }
    // Compute initial totals for all module tabs
    <?php foreach($modules as $m): ?>
    updateModTotals(<?= json_encode($m['slug']) ?>);
    <?php endforeach; ?>
    renderCmp();
});
</script>
</body></html>
<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>
