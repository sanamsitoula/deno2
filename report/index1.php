<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$current_user    = $_SESSION['username'] ?? 'system';
$current_user_id = $_SESSION['user_id']  ?? null;

/* ═══════════════════════════════════════════════════════════
   DYNAMIC MODULE REGISTRY
   ═══════════════════════════════════════════════════════════ */
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

$recon_ddl = "id SERIAL PRIMARY KEY, book_code VARCHAR(50) NOT NULL,
    fiscal_code VARCHAR(10) NOT NULL, price NUMERIC(12,2) DEFAULT 0,
    qty INTEGER DEFAULT 0, notes TEXT, created_by INTEGER,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(book_code,fiscal_code)";

foreach ($modules as $m) {
    $conn->exec("CREATE TABLE IF NOT EXISTS {$m['tbl']} ($recon_ddl)");
}

$active_fiscal_years = $conn->query("
    SELECT fiscal_name,fiscal_code FROM fiscal_years WHERE is_active=TRUE ORDER BY fiscal_name
")->fetchAll(PDO::FETCH_ASSOC);

$books_raw = $conn->query("
    SELECT book_code,book_name,is_translated,class_level,
           COALESCE(book_type,'TextBook') AS book_type FROM books ORDER BY book_name
")->fetchAll(PDO::FETCH_ASSOC);

$sel_fy      = $_GET['fiscal_year'] ?? ($active_fiscal_years[0]['fiscal_code'] ?? '');
$sel_book    = $_GET['book_code']   ?? '';
$sel_trans   = $_GET['translated']  ?? '';
$sel_class   = $_GET['class_level'] ?? '';
$search_term = $_GET['search']      ?? '';
$sort_col    = $_GET['sort']        ?? 'book_name';
$sort_dir    = strtoupper($_GET['dir'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';

/* Closing balance formula stored per FY in session */
$formula_key = 'cb_formula_'.$sel_fy;
if (isset($_GET['save_formula'])) {
    $_SESSION[$formula_key] = trim($_GET['cb_formula'] ?? '');
    $redir = array_filter($_GET, fn($k)=>!in_array($k,['save_formula','cb_formula']), ARRAY_FILTER_USE_KEY);
    header('Location: '.$_SERVER['PHP_SELF'].'?'.http_build_query($redir)); exit;
}
$cb_formula = $_SESSION[$formula_key] ?? '';

/* ═══════════════════════════════════════════════════════════
   POST HANDLERS
   ═══════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_module') {
        $ns = preg_replace('/[^a-z0-9_]/','',strtolower(trim($_POST['new_slug']??'')));
        $nl = trim($_POST['new_label']??'');
        $nc = trim($_POST['new_color']??'#3b82f6');
        $ni = trim($_POST['new_icon']??'📦');
        if ($ns && $nl) {
            try {
                $nt = 'recon_'.$ns;
                $conn->prepare("INSERT INTO recon_modules(slug,label,tbl,color,icon,sort_order)
                    VALUES(:s,:l,:t,:c,:i,99)")
                    ->execute([':s'=>$ns,':l'=>$nl,':t'=>$nt,':c'=>$nc,':i'=>$ni]);
                $conn->exec("CREATE TABLE IF NOT EXISTS $nt ($recon_ddl)");
                $_SESSION['flash']=['type'=>'success','msg'=>"Module '$nl' created!"];
            } catch(Exception $e) {
                $_SESSION['flash']=['type'=>'danger','msg'=>'Slug already exists or invalid.'];
            }
        }
        header('Location: '.$_SERVER['PHP_SELF'].'?fiscal_year='.urlencode($sel_fy)); exit;
    }

    if ($action === 'delete_module') {
        $ds = trim($_POST['del_slug']??'');
        $bis = array_column($built_in,0);
        if ($ds && !in_array($ds,$bis)) {
            $conn->prepare("UPDATE recon_modules SET is_active=FALSE WHERE slug=:s")->execute([':s'=>$ds]);
            $_SESSION['flash']=['type'=>'success','msg'=>'Module hidden.'];
        } else {
            $_SESSION['flash']=['type'=>'danger','msg'=>'Cannot remove built-in modules.'];
        }
        header('Location: '.$_SERVER['PHP_SELF'].'?fiscal_year='.urlencode($sel_fy)); exit;
    }

    $slug_to_tbl = array_column($modules,'tbl','slug');

    if (str_starts_with($action,'save_') && isset($slug_to_tbl[substr($action,5)])) {
        $table = $slug_to_tbl[substr($action,5)];
        foreach (($_POST['rows']??[]) as $row) {
            $bc=trim($row['book_code']??''); $fyc=trim($row['fiscal_code']??'');
            if(!$bc||!$fyc) continue;
            $conn->prepare("INSERT INTO $table(book_code,fiscal_code,price,qty,notes,created_by)
                VALUES(:bc,:fyc,:pr,:qty,:notes,:uid)
                ON CONFLICT(book_code,fiscal_code) DO UPDATE SET
                    price=EXCLUDED.price,qty=EXCLUDED.qty,
                    notes=EXCLUDED.notes,updated_at=CURRENT_TIMESTAMP")
                ->execute([':bc'=>$bc,':fyc'=>$fyc,
                    ':pr'=>floatval($row['price']??0),':qty'=>intval($row['qty']??0),
                    ':notes'=>trim($row['notes']??''),':uid'=>$current_user_id]);
        }
        $_SESSION['flash']=['type'=>'success','msg'=>'Saved!'];
        header('Location: '.$_SERVER['PHP_SELF']
            .'?fiscal_year='.urlencode($_POST['fiscal_code_filter']??'')
            .'&book_code='.urlencode($_POST['book_filter']??'')
            .'&translated='.urlencode($_POST['trans_filter']??'')
            .'&class_level='.urlencode($_POST['class_filter']??'')
            .'&search='.urlencode($_POST['search_filter']??'')); exit;
    }

    if ($action === 'upload_csv') {
        $slug  = $_POST['upload_module']??'';
        $table = $slug_to_tbl[$slug]??'';
        $fyc   = $_POST['upload_fiscal_code']??'';
        $saved = 0;
        if ($table && $fyc && isset($_FILES['csv_file']) && $_FILES['csv_file']['error']===0) {
            $fh=fopen($_FILES['csv_file']['tmp_name'],'r');
            if($fh){
                fgetcsv($fh); // skip header: book_code,book_name,fiscal_year,price,qty,notes
                while(($row=fgetcsv($fh))!==false){
                    if(count($row)<2) continue;
                    $bc=trim($row[0]); if(!$bc||strtolower($bc)==='book_code') continue;
                    // col 0=book_code, 1=book_name(skip), 2=fiscal_year(skip), 3=price, 4=qty, 5=notes
                    $pr=floatval($row[3]??0); $qty=intval($row[4]??0); $notes=trim($row[5]??'');
                    $conn->prepare("INSERT INTO $table(book_code,fiscal_code,price,qty,notes,created_by)
                        VALUES(:bc,:fyc,:pr,:qty,:notes,:uid)
                        ON CONFLICT(book_code,fiscal_code) DO UPDATE SET
                            price=EXCLUDED.price,qty=EXCLUDED.qty,
                            notes=EXCLUDED.notes,updated_at=CURRENT_TIMESTAMP")
                        ->execute([':bc'=>$bc,':fyc'=>$fyc,':pr'=>$pr,':qty'=>$qty,':notes'=>$notes,':uid'=>$current_user_id]);
                    $saved++;
                }
                fclose($fh);
            }
            $_SESSION['flash']=['type'=>'success','msg'=>"Uploaded $saved rows."];
        } else {
            $_SESSION['flash']=['type'=>'danger','msg'=>'Upload failed.'];
        }
        header('Location: '.$_SERVER['PHP_SELF'].'?fiscal_year='.urlencode($fyc)); exit;
    }
}

/* ═══════════════════════════════════════════════════════════
   BUILD DYNAMIC QUERY
   ═══════════════════════════════════════════════════════════ */
$sel_parts = ["b.book_code","b.book_name","b.fiscal_year AS fy_code","b.is_translated",
              "COALESCE(b.class_level::text,'') AS class_level",
              "COALESCE(b.book_type,'') AS book_type",
              "COALESCE(SUM(d.total_qty),0) AS deno_qty"];
$join_parts = ["LEFT JOIN deno d ON d.book_code=b.book_code AND d.fiscal_year=b.fiscal_year AND d.deleted_at IS NULL"];
$group_extra = [];
foreach ($modules as $m) {
    $a=$m['slug']; $t=$m['tbl'];
    $sel_parts[] = "r_{$a}.price AS {$a}_price, r_{$a}.qty AS {$a}_qty, r_{$a}.notes AS {$a}_notes, r_{$a}.updated_at AS {$a}_updated";
    $join_parts[] = "LEFT JOIN $t r_{$a} ON r_{$a}.book_code=b.book_code AND r_{$a}.fiscal_code=b.fiscal_year";
    $group_extra[] = "r_{$a}.price,r_{$a}.qty,r_{$a}.notes,r_{$a}.updated_at";
}

$where_parts=["b.fiscal_year IS NOT NULL"]; $params=[];
if($sel_fy)       {$where_parts[]="b.fiscal_year=:fy";    $params[':fy']=$sel_fy;}
if($sel_book)     {$where_parts[]="b.book_code=:bc";      $params[':bc']=$sel_book;}
if($sel_trans!==''){$where_parts[]="b.is_translated=:tr"; $params[':tr']=($sel_trans==='1')?'true':'false';}
if($sel_class)    {$where_parts[]="b.class_level=:cl";    $params[':cl']=(int)$sel_class;}
if($search_term)  {$where_parts[]="(LOWER(b.book_name) LIKE :s OR LOWER(b.book_code) LIKE :s)"; $params[':s']='%'.strtolower($search_term).'%';}

$allowed_sort=['book_name','book_code','fy_code','class_level','deno_qty'];
foreach($modules as $m) $allowed_sort[]=$m['slug'].'_qty';
if(!in_array($sort_col,$allowed_sort)) $sort_col='book_name';

$where_sql=implode(' AND ',$where_parts);
$sel_sql=implode(",\n",$sel_parts);
$join_sql=implode("\n",$join_parts);
$grp_sql="b.book_code,b.book_name,b.fiscal_year,b.is_translated,b.class_level,b.book_type,".implode(',',$group_extra);

$stmt=$conn->prepare("SELECT $sel_sql FROM books b $join_sql WHERE $where_sql GROUP BY $grp_sql ORDER BY $sort_col $sort_dir");
$stmt->execute($params);
$recon_data=$stmt->fetchAll(PDO::FETCH_ASSOC);

/* Totals */
$totals=['deno'=>0];
foreach($modules as $m) $totals[$m['slug']]=0;
foreach($recon_data as $r){
    $totals['deno']+=(int)$r['deno_qty'];
    foreach($modules as $m) $totals[$m['slug']]+=(int)($r[$m['slug'].'_qty']??0);
}
$books_count=count($recon_data);

/* Price fallback */
function resolve_price($r,$slug,$modules){
    $p=floatval($r[$slug.'_price']??0); if($p>0) return $p;
    $mp=floatval($r['marketing_price']??0); if($mp>0) return $mp;
    foreach($modules as $m){ if($m['slug']===$slug) continue; $op=floatval($r[$m['slug'].'_price']??0); if($op>0) return $op; }
    return 0;
}

/* Closing balance evaluator */
function eval_cb($formula,$row,$modules){
    if(!$formula) return null;
    $e=strtolower(trim($formula));
    foreach($modules as $m){ $s=strtolower($m['slug']); $q=(int)($row[$m['slug'].'_qty']??0);
        $e=preg_replace('/\b'.preg_quote($s,'/').'_qty\b/',$q,$e);
        $e=preg_replace('/\b'.preg_quote($s,'/').'(?!_)/',$q,$e); }
    $e=preg_replace('/\bdeno_qty\b/',(int)($row['deno_qty']??0),$e);
    $e=preg_replace('/\bdeno\b/',(int)($row['deno_qty']??0),$e);
    if(!preg_match('/^[\d\s\+\-\*\/\(\)\.]+$/',$e)) return null;
    try{ return @eval("return ($e);"); }catch(Throwable $ex){ return null; }
}

/* Export */
if(isset($_GET['export'])){
    $fn='recon_'.($sel_fy?:'all').'_'.date('Y-m-d');
    $cols=['SN','Book Code','Book Name','Class','FY','Trans','Deno Qty'];
    foreach($modules as $m){$cols[]=$m['label'].' Price';$cols[]=$m['label'].' Qty';$cols[]=$m['label'].' Total';}
    foreach($modules as $m) $cols[]='Var '.$m['label'];
    if($cb_formula) $cols[]='Closing Bal';
    if($_GET['export']==='csv'){
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment;filename="'.$fn.'.csv"');
        $out=fopen('php://output','w'); fputcsv($out,$cols);
        $sn=1;
        foreach($recon_data as $r){
            $row=[$sn++,$r['book_code'],$r['book_name'],$r['class_level'],$r['fy_code'],$r['is_translated']?'Y':'N',(int)$r['deno_qty']];
            foreach($modules as $m){$p=resolve_price($r,$m['slug'],$modules);$q=(int)($r[$m['slug'].'_qty']??0);$row[]=$p;$row[]=$q;$row[]=number_format($p*$q,2);}
            foreach($modules as $m) $row[]=((int)($r[$m['slug'].'_qty']??0))-(int)$r['deno_qty'];
            if($cb_formula) $row[]=eval_cb($cb_formula,$r,$modules)??'';
            fputcsv($out,$row);
        }
        fclose($out); exit;
    }
    if($_GET['export']==='excel'){
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment;filename="'.$fn.'.xls"');
        echo "<table border='1'><tr>"; foreach($cols as $c) echo "<th>".htmlspecialchars($c)."</th>"; echo "</tr>";
        $sn=1;
        foreach($recon_data as $r){
            $pr=[$sn++,$r['book_code'],htmlspecialchars($r['book_name']),$r['class_level'],$r['fy_code'],$r['is_translated']?'Y':'N',(int)$r['deno_qty']];
            foreach($modules as $m){$p=resolve_price($r,$m['slug'],$modules);$q=(int)($r[$m['slug'].'_qty']??0);$pr[]=$p;$pr[]=$q;$pr[]=number_format($p*$q,2);}
            foreach($modules as $m) $pr[]=((int)($r[$m['slug'].'_qty']??0))-(int)$r['deno_qty'];
            if($cb_formula) $pr[]=eval_cb($cb_formula,$r,$modules)??'';
            echo "<tr>"; foreach($pr as $v) echo "<td>$v</td>"; echo "</tr>"; $sn++;
        }
        echo "</table>"; exit;
    }
}

$flash=$_SESSION['flash']??null; unset($_SESSION['flash']);
$class_levels=$conn->query("SELECT DISTINCT class_level FROM books WHERE class_level IS NOT NULL ORDER BY class_level")->fetchAll(PDO::FETCH_COLUMN);
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
  --ac:#2563eb;--ac-lt:#eff6ff;--ac-dk:#1d4ed8;
  --ok:#16a34a;--ok-lt:#f0fdf4;--err:#dc2626;--err-lt:#fef2f2;
  --warn:#d97706;--warn-lt:#fffbeb;--pink:#db2777;
  --tx:#0f172a;--tx2:#475569;--mu:#94a3b8;
  --mo:'JetBrains Mono',monospace;--fn:'Inter',sans-serif;--r:8px;--r2:4px;
  --sh:0 1px 3px rgba(0,0,0,.08),0 1px 2px rgba(0,0,0,.06);
  --sh2:0 4px 6px -1px rgba(0,0,0,.08),0 2px 4px -1px rgba(0,0,0,.06);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--tx);font-family:var(--fn);font-size:14px;min-height:100vh}
.pw{max-width:1800px;margin:0 auto;padding:20px}

/* Layout */
.ph{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:18px;flex-wrap:wrap;gap:10px}
.ph h1{font-size:20px;font-weight:700;display:flex;align-items:center;gap:8px}
.ph p{font-size:11px;color:var(--mu);margin-top:2px;font-family:var(--mo)}

/* Flash */
.flash{padding:12px 16px;border-radius:var(--r);font-weight:500;margin-bottom:14px;font-size:13px;display:flex;align-items:center;gap:8px}
.flash-s{background:var(--ok-lt);color:var(--ok);border:1px solid #bbf7d0}
.flash-d{background:var(--err-lt);color:var(--err);border:1px solid #fecaca}

/* Filters */
.filters{background:var(--s);border:1px solid var(--bd);border-radius:var(--r);padding:12px 14px;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:14px;box-shadow:var(--sh)}
.fg{display:flex;flex-direction:column;gap:4px}
.fl{font-size:10px;font-weight:600;color:var(--tx2);text-transform:uppercase;letter-spacing:.6px}
.fc{background:var(--s2);border:1px solid var(--bd2);color:var(--tx);border-radius:var(--r2);padding:7px 10px;font-size:13px;font-family:var(--fn);min-width:140px;transition:border-color .15s}
.fc:focus{outline:none;border-color:var(--ac);background:#fff}
.fc::placeholder{color:var(--mu)}

/* Book search */
.bdd{position:relative}
.bdd-opts{position:absolute;top:calc(100% + 4px);left:0;right:0;background:#fff;border:1px solid var(--bd2);border-radius:var(--r);box-shadow:var(--sh2);max-height:220px;overflow-y:auto;z-index:300;display:none}
.bdd-opt{padding:8px 12px;cursor:pointer;font-size:13px;border-bottom:1px solid var(--bd);transition:background .1s}
.bdd-opt:hover{background:var(--ac-lt)}
.bdd-opt .bc{font-family:var(--mo);font-size:11px;color:var(--mu);margin-left:5px}

/* Buttons */
.btn{display:inline-flex;align-items:center;gap:5px;padding:8px 14px;border:none;border-radius:var(--r2);cursor:pointer;font-size:13px;font-weight:500;font-family:var(--fn);transition:all .15s;text-decoration:none;white-space:nowrap}
.btn-primary{background:var(--ac);color:#fff}.btn-primary:hover{background:var(--ac-dk)}
.btn-success{background:var(--ok);color:#fff}.btn-success:hover{background:#15803d}
.btn-info{background:#0284c7;color:#fff}.btn-info:hover{background:#0369a1}
.btn-warning{background:var(--warn);color:#fff}.btn-warning:hover{background:#b45309}
.btn-outline{background:#fff;border:1px solid var(--bd2);color:var(--tx2)}.btn-outline:hover{border-color:var(--ac);color:var(--ac)}
.btn-sm{padding:5px 10px;font-size:12px}
.btn-xs{padding:3px 8px;font-size:11px}
.btn-danger{background:var(--err);color:#fff}.btn-danger:hover{background:#b91c1c}

/* Summary cards */
.sg{display:grid;grid-template-columns:repeat(auto-fill,minmax(155px,1fr));gap:10px;margin-bottom:16px}
.sc{background:var(--s);border:1px solid var(--bd);border-radius:var(--r);padding:12px 14px;box-shadow:var(--sh)}
.sl{font-size:10px;font-weight:600;color:var(--mu);text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px}
.sv{font-size:21px;font-weight:700;font-family:var(--mo)}
.ss{font-size:10px;color:var(--mu);margin-top:2px}

/* Tabs */
.tabs{display:flex;border-bottom:2px solid var(--bd);margin-bottom:16px;flex-wrap:nowrap;overflow-x:auto;gap:0}
.tab{padding:9px 15px;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-2px;color:var(--tx2);font-size:13px;font-weight:500;transition:all .15s;background:none;border-top:none;border-left:none;border-right:none;font-family:var(--fn);white-space:nowrap;flex-shrink:0}
.tab:hover{color:var(--ac);background:var(--ac-lt);border-radius:4px 4px 0 0}
.tab.active{color:var(--ac);border-bottom-color:var(--ac);font-weight:600}
.tab-panel{display:none}.tab-panel.active{display:block}

/* Card */
.card{background:var(--s);border:1px solid var(--bd);border-radius:var(--r);overflow:hidden;box-shadow:var(--sh);margin-bottom:14px}
.ch{padding:11px 14px;display:flex;align-items:center;gap:10px;background:var(--s2);border-bottom:1px solid var(--bd)}
.ci{width:28px;height:28px;border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0}
.ct{font-weight:600;font-size:13px;color:var(--tx);flex:1}
.cbadge{font-size:11px;padding:2px 7px;border-radius:20px;font-weight:600;font-family:var(--mo);background:var(--s);border:1px solid var(--bd);color:var(--tx2)}
.cbody{padding:14px}

/* Tables */
.tw{overflow-x:auto}
table.rt{width:100%;border-collapse:collapse;font-size:13px}
table.rt th{background:var(--s2);color:var(--tx2);font-size:11px;text-transform:uppercase;letter-spacing:.4px;padding:9px 10px;border-bottom:2px solid var(--bd);white-space:nowrap;text-align:left;font-weight:600;cursor:pointer;user-select:none;position:sticky;top:0;z-index:1}
table.rt th:hover{background:#e2e8f0;color:var(--tx)}
table.rt th.sa::after{content:' ↑';color:var(--ac)}
table.rt th.sd::after{content:' ↓';color:var(--ac)}
table.rt td{padding:7px 10px;border-bottom:1px solid var(--bd);vertical-align:middle}
table.rt tr:hover td{background:var(--ac-lt)}
table.rt tr:nth-child(even) td{background:#fafbfc}
table.rt tr:nth-child(even):hover td{background:var(--ac-lt)}
table.rt input[type="number"],table.rt input[type="text"]{
  background:#fff;border:1px solid var(--bd2);color:var(--tx);
  border-radius:var(--r2);padding:5px 8px;font-size:12px;width:100%;font-family:var(--mo);transition:border-color .15s}
table.rt input:focus{outline:none;border-color:var(--ac);box-shadow:0 0 0 2px rgba(37,99,235,.12)}

/* Pills & badges */
.pill{display:inline-block;padding:2px 7px;border-radius:20px;font-size:11px;font-weight:600}
.p-ok  {background:var(--ok-lt);color:var(--ok);border:1px solid #bbf7d0}
.p-bad {background:var(--err-lt);color:var(--err);border:1px solid #fecaca}
.p-warn{background:var(--warn-lt);color:var(--warn);border:1px solid #fde68a}
.btg{display:inline-block;padding:1px 5px;border-radius:3px;font-size:10px;font-weight:600;font-family:var(--mo)}
.btg-tr{background:#dbeafe;color:#1e40af}
.btg-cl{background:#f3e8ff;color:#6b21a8}
.ph-hint{display:block;font-size:10px;padding:1px 5px;border-radius:3px;font-family:var(--mo);margin-top:2px;background:#fef9c3;color:#713f12;border:1px solid #fde68a}

/* Variance */
.vp{color:var(--ok);font-weight:600;font-family:var(--mo)}
.vn{color:var(--err);font-weight:600;font-family:var(--mo)}
.vz{color:var(--mu);font-family:var(--mo)}

.ab{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:12px}
.mono{font-family:var(--mo)}

/* Comparison selector */
.cmp-sel{background:var(--s);border:1px solid var(--bd);border-radius:var(--r);padding:14px;margin-bottom:12px;box-shadow:var(--sh)}
.cmp-sel h4{font-size:11px;font-weight:600;color:var(--tx2);text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px}
.cmp-checks{display:flex;gap:7px;flex-wrap:wrap}
.cck{display:flex;align-items:center;gap:6px;padding:5px 12px;border-radius:20px;border:1.5px solid var(--bd2);cursor:pointer;font-size:12px;font-weight:600;background:#fff;transition:all .15s;user-select:none}
.cck input{width:14px;height:14px;cursor:pointer}
.cck.on{background:color-mix(in srgb,currentColor 8%,white)}

/* Formula builder */
.fbox{background:var(--s);border:1px solid var(--bd);border-radius:var(--r);padding:14px;margin-bottom:12px;box-shadow:var(--sh)}
.fbox h4{font-size:11px;font-weight:600;color:var(--tx2);text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px}
.ftoks{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px}
.ftok{padding:4px 10px;border-radius:4px;font-family:var(--mo);font-size:12px;cursor:pointer;border:1.5px solid;transition:all .15s;font-weight:500}
.ftok:hover{transform:translateY(-1px);box-shadow:var(--sh)}
.fi{width:100%;padding:8px 12px;border:1px solid var(--bd2);border-radius:var(--r2);font-family:var(--mo);font-size:13px;color:var(--tx);background:#fff;transition:border-color .15s}
.fi:focus{outline:none;border-color:var(--ac);box-shadow:0 0 0 2px rgba(37,99,235,.12)}
.fprev{font-size:12px;color:var(--tx2);margin-top:8px;font-family:var(--mo);padding:6px 10px;background:var(--s2);border-radius:var(--r2)}

/* Closing balance col */
.cbc{background:#fffbeb!important;font-weight:700;font-family:var(--mo);color:#92400e}

/* Module manager */
.mmgr{background:var(--s);border:1px solid var(--bd);border-radius:var(--r);padding:14px;margin-bottom:14px;box-shadow:var(--sh)}
.mmgr h4{font-size:13px;font-weight:600;margin-bottom:12px}

@media(max-width:768px){.sg{grid-template-columns:1fr 1fr}}
@media print{
  body{background:#fff;font-size:11px}
  .filters,.tabs,.ab,.cmp-sel,.fbox,.mmgr,.btn,form,.ch{display:none!important}
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
        <p>FY <?= htmlspecialchars($sel_fy?:'All') ?> · <?= $books_count ?> books · <?= count($modules) ?> modules active</p>
    </div>
    <div style="display:flex;gap:7px;flex-wrap:wrap">
        <a href="?<?= http_build_query(array_merge($_GET,['export'=>'excel'])) ?>" class="btn btn-success btn-sm">📊 Excel</a>
        <a href="?<?= http_build_query(array_merge($_GET,['export'=>'csv'])) ?>"   class="btn btn-info btn-sm">📥 CSV</a>
        <button onclick="window.print()" class="btn btn-warning btn-sm">🖨️ Print</button>
    </div>
</div>

<!-- Filters -->
<div class="filters">
<form method="get" style="display:contents" id="ff">
    <div class="fg">
        <div class="fl">Fiscal Year</div>
        <select name="fiscal_year" class="fc" onchange="document.getElementById('ff').submit()">
            <option value="">All Years</option>
            <?php foreach($active_fiscal_years as $fy): ?>
            <option value="<?= htmlspecialchars($fy['fiscal_code']) ?>" <?= $sel_fy===$fy['fiscal_code']?'selected':'' ?>>
                <?= htmlspecialchars($fy['fiscal_name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="fg">
        <div class="fl">Book (code or name)</div>
        <div class="bdd">
            <input type="text" id="bs" class="fc" autocomplete="off" style="min-width:200px"
                   placeholder="Search book code or name…"
                   value="<?php if($sel_book){ foreach($books_raw as $b){ if($b['book_code']===$sel_book){ echo htmlspecialchars($b['book_code'].' — '.$b['book_name']); break; }}} ?>">
            <input type="hidden" name="book_code" id="bh" value="<?= htmlspecialchars($sel_book) ?>">
            <div class="bdd-opts" id="bo">
                <div class="bdd-opt" data-code="" data-text="All Books"><strong>All Books</strong></div>
                <?php foreach($books_raw as $b): ?>
                <div class="bdd-opt" data-code="<?= htmlspecialchars($b['book_code']) ?>"
                     data-text="<?= htmlspecialchars($b['book_code'].' — '.$b['book_name']) ?>">
                    <?= htmlspecialchars($b['book_name']) ?><span class="bc"><?= htmlspecialchars($b['book_code']) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <div class="fg">
        <div class="fl">Translated</div>
        <select name="translated" class="fc">
            <option value="">All</option>
            <option value="1" <?= $sel_trans==='1'?'selected':'' ?>>Translated</option>
            <option value="0" <?= $sel_trans==='0'?'selected':'' ?>>Not Translated</option>
        </select>
    </div>
    <div class="fg">
        <div class="fl">Class Level</div>
        <select name="class_level" class="fc">
            <option value="">All Classes</option>
            <?php foreach($class_levels as $cl): ?>
            <option value="<?= (int)$cl ?>" <?= $sel_class==(int)$cl?'selected':'' ?>>Class <?= (int)$cl ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="fg">
        <div class="fl">Search</div>
        <input type="text" name="search" class="fc" placeholder="Book name / code…" value="<?= htmlspecialchars($search_term) ?>">
    </div>
    <input type="hidden" name="sort" value="<?= htmlspecialchars($sort_col) ?>">
    <input type="hidden" name="dir"  value="<?= htmlspecialchars($sort_dir) ?>">
    <div class="fg" style="flex-direction:row;gap:6px">
        <button type="submit" class="btn btn-primary">🔍 Filter</button>
        <a href="<?= $_SERVER['PHP_SELF'] ?>" class="btn btn-outline">✕</a>
    </div>
</form>
</div>

<!-- Summary cards -->
<div class="sg">
    <div class="sc"><div class="sl">📚 Books</div><div class="sv"><?= number_format($books_count) ?></div><div class="ss">Filtered</div></div>
    <div class="sc"><div class="sl">📘 Deno Qty</div><div class="sv" style="color:var(--ac)"><?= number_format($totals['deno']) ?></div><div class="ss">From DB</div></div>
    <?php foreach($modules as $m): ?>
    <div class="sc">
        <div class="sl"><?= $m['icon'] ?> <?= htmlspecialchars($m['label']) ?></div>
        <div class="sv" style="color:<?= htmlspecialchars($m['color']) ?>"><?= number_format($totals[$m['slug']]) ?></div>
        <div class="ss"><?= $totals['deno']>0?round($totals[$m['slug']]/$totals['deno']*100,1).'% of Deno':'' ?></div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Tabs -->
<div class="tabs">
    <button class="tab active" onclick="switchTab('overview',this)">📊 Overview</button>
    <button class="tab" onclick="switchTab('deno',this)">📘 Deno</button>
    <?php foreach($modules as $m): ?>
    <button class="tab" onclick="switchTab('<?= htmlspecialchars($m['slug']) ?>',this)">
        <?= $m['icon'] ?> <?= htmlspecialchars($m['label']) ?>
    </button>
    <?php endforeach; ?>
    <button class="tab" onclick="switchTab('comparison',this)">⚖️ Comparison</button>
    <button class="tab" onclick="switchTab('analysis',this)">📈 Analysis</button>
    <button class="tab" onclick="switchTab('manage',this)">⚙️ Modules</button>
</div>

<!-- ══ OVERVIEW ══ -->
<div class="tab-panel active" id="tab-overview">
    <div class="card">
        <div class="ch">
            <div class="ci" style="background:var(--ac-lt)">📋</div>
            <div class="ct">All Modules — <?= htmlspecialchars($sel_fy?:'All FY') ?></div>
            <span class="cbadge"><?= $books_count ?> books</span>
        </div>
        <div class="tw">
        <table class="rt">
            <thead>
            <tr>
                <th onclick="srt('overview','book_code')">Code</th>
                <th onclick="srt('overview','book_name')">Book Name</th>
                <th onclick="srt('overview','class_level')">Class</th>
                <th>Trans</th>
                <th onclick="srt('overview','fy_code')">FY</th>
                <th onclick="srt('overview','deno_qty')" style="color:var(--ac)">Deno Qty</th>
                <?php foreach($modules as $m): ?>
                <th colspan="3" style="text-align:center;color:<?= htmlspecialchars($m['color']) ?>;background:color-mix(in srgb,<?= htmlspecialchars($m['color']) ?> 6%,white)">
                    <?= $m['icon'] ?> <?= htmlspecialchars($m['label']) ?>
                </th>
                <?php endforeach; ?>
                <?php if($cb_formula): ?><th style="background:var(--warn-lt);color:var(--warn)">Closing Bal</th><?php endif; ?>
                <th>Status</th>
            </tr>
            <tr>
                <th></th><th></th><th></th><th></th><th></th><th></th>
                <?php foreach($modules as $m): ?>
                <th style="font-size:10px">Price</th><th style="font-size:10px">Qty</th><th style="font-size:10px">Total</th>
                <?php endforeach; ?>
                <?php if($cb_formula): ?><th></th><?php endif; ?>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach($recon_data as $r):
                $all_ok=true; $any=false;
                foreach($modules as $m){ if((int)($r[$m['slug'].'_qty']??0)!=(int)$r['deno_qty']) $all_ok=false; if((int)($r[$m['slug'].'_qty']??0)>0) $any=true; }
                $cbv=$cb_formula?eval_cb($cb_formula,$r,$modules):null;
            ?>
            <tr>
                <td class="mono" style="color:var(--ac);font-size:12px"><?= htmlspecialchars($r['book_code']) ?></td>
                <td style="font-weight:500;white-space:nowrap"><?= htmlspecialchars($r['book_name']) ?></td>
                <td><?= $r['class_level']?'<span class="btg btg-cl">'.htmlspecialchars($r['class_level']).'</span>':'' ?></td>
                <td><?= $r['is_translated']?'<span class="btg btg-tr">T</span>':'' ?></td>
                <td class="mono" style="font-size:12px"><?= htmlspecialchars($r['fy_code']) ?></td>
                <td class="mono" style="font-weight:700;color:var(--ac)"><?= number_format((int)$r['deno_qty']) ?></td>
                <?php foreach($modules as $m):
                    $p=resolve_price($r,$m['slug'],$modules); $q=(int)($r[$m['slug'].'_qty']??0);
                    $own=floatval($r[$m['slug'].'_price']??0)>0;
                ?>
                <td class="mono" style="font-size:12px;color:<?= $own?'inherit':'var(--warn)' ?>">
                    <?= number_format($p,2) ?>
                    <?php if(!$own&&$p>0): ?><span class="ph-hint">↑ fallback</span><?php endif; ?>
                </td>
                <td class="mono" style="font-weight:700;color:<?= htmlspecialchars($m['color']) ?>"><?= number_format($q) ?></td>
                <td class="mono" style="font-size:12px"><?= number_format($p*$q,2) ?></td>
                <?php endforeach; ?>
                <?php if($cb_formula): ?><td class="cbc"><?= $cbv!==null?number_format((float)$cbv,2):'—' ?></td><?php endif; ?>
                <td>
                    <?php if(!$any): ?><span class="pill p-warn">— None</span>
                    <?php elseif($all_ok): ?><span class="pill p-ok">✓ Match</span>
                    <?php else: ?><span class="pill p-bad">⚠ Diff</span><?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if(empty($recon_data)): ?><tr><td colspan="30" style="text-align:center;padding:28px;color:var(--mu)">No records match filters.</td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<!-- ══ DENO ══ -->
<div class="tab-panel" id="tab-deno">
    <div class="card">
        <div class="ch">
            <div class="ci" style="background:var(--ac-lt)">📘</div>
            <div class="ct">Deno — SUM(total_qty) from deno table grouped by book_code + fiscal_year</div>
            <span class="cbadge" style="color:var(--ac)">Read-only</span>
        </div>
        <div class="tw"><table class="rt">
            <thead><tr>
                <th onclick="srt('deno','book_code')">Code</th>
                <th onclick="srt('deno','book_name')">Book Name</th>
                <th onclick="srt('deno','class_level')">Class</th>
                <th>Trans</th>
                <th onclick="srt('deno','fy_code')">FY</th>
                <th onclick="srt('deno','deno_qty')">Total Qty</th>
            </tr></thead>
            <tbody>
            <?php foreach($recon_data as $r): ?>
            <tr>
                <td class="mono" style="color:var(--ac);font-size:12px"><?= htmlspecialchars($r['book_code']) ?></td>
                <td style="font-weight:500"><?= htmlspecialchars($r['book_name']) ?></td>
                <td><?= $r['class_level']?'<span class="btg btg-cl">'.htmlspecialchars($r['class_level']).'</span>':'' ?></td>
                <td><?= $r['is_translated']?'<span class="btg btg-tr">T</span>':'' ?></td>
                <td class="mono" style="font-size:12px"><?= htmlspecialchars($r['fy_code']) ?></td>
                <td class="mono" style="font-size:16px;font-weight:700;color:var(--ac)"><?= number_format((int)$r['deno_qty']) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
</div>

<?php foreach($modules as $m):
    $slug=$m['slug']; $label=$m['label']; $color=$m['color']; $icon=$m['icon'];
?>
<!-- ══ MODULE: <?= htmlspecialchars($label) ?> ══ -->
<div class="tab-panel" id="tab-<?= htmlspecialchars($slug) ?>">
    <div class="card" style="margin-bottom:10px">
        <div class="ch" style="background:color-mix(in srgb,<?= htmlspecialchars($color) ?> 7%,white)">
            <div class="ci" style="background:color-mix(in srgb,<?= htmlspecialchars($color) ?> 15%,white)"><?= $icon ?></div>
            <div class="ct" style="color:<?= htmlspecialchars($color) ?>">Upload CSV — <?= htmlspecialchars($label) ?></div>
        </div>
        <div class="cbody">
            <div style="background:var(--s2);border:1px solid var(--bd);border-radius:var(--r2);padding:8px 12px;font-size:12px;font-family:var(--mo);color:var(--tx2);margin-bottom:10px">
                CSV columns: <strong>book_code · book_name · fiscal_year · price · qty · notes</strong>
                &nbsp;<button onclick="dlTpl('<?= htmlspecialchars($slug) ?>')" class="btn btn-outline btn-xs" style="margin-left:8px">📥 Download Template</button>
            </div>
            <form method="post" enctype="multipart/form-data" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
                <input type="hidden" name="action" value="upload_csv">
                <input type="hidden" name="upload_module" value="<?= htmlspecialchars($slug) ?>">
                <input type="hidden" name="upload_fiscal_code" value="<?= htmlspecialchars($sel_fy) ?>">
                <div class="fg"><div class="fl">Select CSV File</div>
                    <input type="file" name="csv_file" accept=".csv" class="fc" style="padding:5px"></div>
                <button type="submit" class="btn btn-primary">⬆ Upload</button>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="ch" style="background:color-mix(in srgb,<?= htmlspecialchars($color) ?> 7%,white)">
            <div class="ci" style="background:color-mix(in srgb,<?= htmlspecialchars($color) ?> 15%,white)"><?= $icon ?></div>
            <div class="ct" style="color:<?= htmlspecialchars($color) ?>"><?= htmlspecialchars($label) ?> — Manual Entry</div>
            <span class="cbadge"><?= count($recon_data) ?> rows</span>
        </div>
        <form method="post" id="form-<?= htmlspecialchars($slug) ?>">
            <input type="hidden" name="action" value="save_<?= htmlspecialchars($slug) ?>">
            <input type="hidden" name="fiscal_code_filter" value="<?= htmlspecialchars($sel_fy) ?>">
            <input type="hidden" name="book_filter" value="<?= htmlspecialchars($sel_book) ?>">
            <input type="hidden" name="trans_filter" value="<?= htmlspecialchars($sel_trans) ?>">
            <input type="hidden" name="class_filter" value="<?= htmlspecialchars($sel_class) ?>">
            <input type="hidden" name="search_filter" value="<?= htmlspecialchars($search_term) ?>">
            <div class="ab" style="padding:10px 14px 0">
                <button type="submit" class="btn btn-primary">💾 Save All</button>
                <button type="button" class="btn btn-outline btn-sm" onclick="clearMod('<?= htmlspecialchars($slug) ?>')">✕ Clear</button>
                <button type="button" class="btn btn-outline btn-sm" onclick="autofill('<?= htmlspecialchars($slug) ?>')">🔄 Auto-fill Price</button>
            </div>
            <div class="tw"><table class="rt">
                <thead><tr>
                    <th onclick="sortMod('<?= htmlspecialchars($slug) ?>','code')">Code</th>
                    <th onclick="sortMod('<?= htmlspecialchars($slug) ?>','name')">Book Name</th>
                    <th onclick="sortMod('<?= htmlspecialchars($slug) ?>','cls')">Class</th>
                    <th>Trans</th>
                    <th onclick="sortMod('<?= htmlspecialchars($slug) ?>','fy')">FY</th>
                    <th style="color:var(--ac)" onclick="sortMod('<?= htmlspecialchars($slug) ?>','deno')">Deno Qty</th>
                    <th style="color:<?= htmlspecialchars($color) ?>">Price</th>
                    <th style="color:<?= htmlspecialchars($color) ?>">Qty</th>
                    <th style="color:<?= htmlspecialchars($color) ?>">Total</th>
                    <th>Notes</th>
                    <th>Saved</th>
                </tr></thead>
                <tbody id="tbody-<?= htmlspecialchars($slug) ?>">
                <?php foreach($recon_data as $i=>$r):
                    $sp=floatval($r[$slug.'_price']??0);
                    $dp=$sp>0?$sp:resolve_price($r,$slug,$modules);
                    $dq=(int)($r[$slug.'_qty']??0);
                    $fb=$sp==0&&$dp>0;
                ?>
                <tr data-code="<?= htmlspecialchars($r['book_code']) ?>"
                    data-name="<?= htmlspecialchars($r['book_name']) ?>"
                    data-cls="<?= (int)($r['class_level']??0) ?>"
                    data-fy="<?= htmlspecialchars($r['fy_code']) ?>"
                    data-deno="<?= (int)$r['deno_qty'] ?>">
                    <td class="mono" style="color:var(--ac);font-size:12px"><?= htmlspecialchars($r['book_code']) ?>
                        <input type="hidden" name="rows[<?= $i ?>][book_code]"   value="<?= htmlspecialchars($r['book_code']) ?>">
                        <input type="hidden" name="rows[<?= $i ?>][fiscal_code]" value="<?= htmlspecialchars($r['fy_code']) ?>">
                    </td>
                    <td style="font-weight:500;white-space:nowrap"><?= htmlspecialchars($r['book_name']) ?></td>
                    <td><?= $r['class_level']?'<span class="btg btg-cl">'.htmlspecialchars($r['class_level']).'</span>':'' ?></td>
                    <td><?= $r['is_translated']?'<span class="btg btg-tr">T</span>':'' ?></td>
                    <td class="mono" style="font-size:12px"><?= htmlspecialchars($r['fy_code']) ?></td>
                    <td class="mono" style="color:var(--ac);font-weight:600"><?= number_format((int)$r['deno_qty']) ?></td>
                    <td style="min-width:130px">
                        <input type="number" name="rows[<?= $i ?>][price]"
                               id="pr-<?= htmlspecialchars($slug) ?>-<?= $i ?>"
                               value="<?= htmlspecialchars((string)$dp) ?>" min="0" step="0.01"
                               oninput="calcRow('<?= htmlspecialchars($slug) ?>',<?= $i ?>)">
                        <?php if($fb): ?><span class="ph-hint">↑ fallback price</span><?php endif; ?>
                    </td>
                    <td style="min-width:90px">
                        <input type="number" name="rows[<?= $i ?>][qty]"
                               id="qt-<?= htmlspecialchars($slug) ?>-<?= $i ?>"
                               value="<?= $dq ?>" min="0"
                               oninput="calcRow('<?= htmlspecialchars($slug) ?>',<?= $i ?>)">
                    </td>
                    <td><span id="tot-<?= htmlspecialchars($slug) ?>-<?= $i ?>" class="mono"><?= number_format($dp*$dq,2) ?></span></td>
                    <td><input type="text" name="rows[<?= $i ?>][notes]" value="<?= htmlspecialchars($r[$slug.'_notes']??'') ?>" placeholder="…"></td>
                    <td style="font-size:10px;color:var(--mu);font-family:var(--mo)">
                        <?= !empty($r[$slug.'_updated'])?date('m/d H:i',strtotime($r[$slug.'_updated'])):'' ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        </form>
    </div>
</div>
<?php endforeach; ?>

<!-- ══ COMPARISON ══ -->
<div class="tab-panel" id="tab-comparison">
    <div class="cmp-sel">
        <h4>Select Modules to Compare</h4>
        <div class="cmp-checks" id="cmpChecks">
            <label class="cck on" style="color:var(--ac)">
                <input type="checkbox" value="deno" checked onchange="syncC(this);renderCmp()">📘 Deno
            </label>
            <?php foreach($modules as $m): ?>
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
             — click tokens to build formula · saved per fiscal year
            </small>
        </h4>
        <div class="ftoks">
            <span class="ftok" style="background:var(--ac-lt);color:var(--ac);border-color:var(--ac)" onclick="ins('deno_qty')">📘 deno_qty</span>
            <?php foreach($modules as $m): ?>
            <span class="ftok" style="background:color-mix(in srgb,<?= htmlspecialchars($m['color']) ?> 10%,white);color:<?= htmlspecialchars($m['color']) ?>;border-color:<?= htmlspecialchars($m['color']) ?>"
                  onclick="ins('<?= htmlspecialchars($m['slug']) ?>_qty')"><?= $m['icon'] ?> <?= htmlspecialchars($m['slug']) ?>_qty</span>
            <?php endforeach; ?>
            <span class="ftok" style="background:var(--s2);color:var(--tx2);border-color:var(--bd2)" onclick="ins('+')"> + </span>
            <span class="ftok" style="background:var(--s2);color:var(--tx2);border-color:var(--bd2)" onclick="ins('-')"> − </span>
            <span class="ftok" style="background:var(--s2);color:var(--tx2);border-color:var(--bd2)" onclick="ins('*')"> × </span>
            <span class="ftok" style="background:var(--s2);color:var(--tx2);border-color:var(--bd2)" onclick="ins('/')"> ÷ </span>
            <span class="ftok" style="background:var(--s2);color:var(--tx2);border-color:var(--bd2)" onclick="ins('(')">(</span>
            <span class="ftok" style="background:var(--s2);color:var(--tx2);border-color:var(--bd2)" onclick="ins(')')">)</span>
        </div>
        <input type="text" id="cbf" class="fi"
               placeholder="e.g.  deno_qty + marketing_qty - software_qty"
               value="<?= htmlspecialchars($cb_formula) ?>">
        <div class="fprev" id="fprev">
            <?= $cb_formula ? 'Current: <strong>'.htmlspecialchars($cb_formula).'</strong>' : 'Type or click tokens to define the closing balance formula.' ?>
        </div>
        <div style="display:flex;gap:8px;margin-top:10px">
            <a id="sfBtn" href="#" class="btn btn-primary btn-sm">💾 Save Formula</a>
            <button onclick="document.getElementById('cbf').value='';upPrev()" class="btn btn-outline btn-sm">✕ Clear</button>
        </div>
    </div>

    <div class="ab">
        <button onclick="expCmpCSV()" class="btn btn-info btn-sm">📥 Export CSV</button>
        <button onclick="window.print()" class="btn btn-warning btn-sm">🖨️ Print</button>
    </div>

    <div class="card">
        <div class="ch">
            <div class="ci" style="background:var(--ac-lt)">⚖️</div>
            <div class="ct">Comparison — Qty · Price × Total · % Variance · Closing Balance</div>
        </div>
        <div class="tw" id="cmpWrap"></div>
    </div>
</div>

<!-- ══ ANALYSIS ══ -->
<div class="tab-panel" id="tab-analysis">
    <div class="card">
        <div class="ch"><div class="ci" style="background:var(--ac-lt)">📈</div><div class="ct">Qty per Module per Book</div></div>
        <div style="padding:18px"><canvas id="aC" height="80"></canvas></div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
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
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;padding-bottom:10px;border-bottom:1px solid var(--bd)">
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
<div class="tab-panel" id="tab-manage">
    <div class="mmgr">
        <h4>➕ Add New Module</h4>
        <form method="post" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
            <input type="hidden" name="action" value="add_module">
            <div class="fg"><div class="fl">Slug (a-z_0-9 only)</div>
                <input type="text" name="new_slug" class="fc" placeholder="e.g. physical_stock" pattern="[a-z0-9_]+" required></div>
            <div class="fg"><div class="fl">Display Label</div>
                <input type="text" name="new_label" class="fc" placeholder="e.g. Physical Stock" required></div>
            <div class="fg"><div class="fl">Color</div>
                <input type="color" name="new_color" class="fc" value="#3b82f6" style="min-width:60px;padding:4px"></div>
            <div class="fg"><div class="fl">Icon (emoji)</div>
                <input type="text" name="new_icon" class="fc" placeholder="📦" maxlength="4" style="min-width:70px"></div>
            <button type="submit" class="btn btn-primary">➕ Create Module &amp; Table</button>
        </form>
    </div>
    <div class="card">
        <div class="ch"><div class="ci" style="background:var(--ac-lt)">⚙️</div><div class="ct">Active Modules</div></div>
        <div class="tw"><table class="rt">
            <thead><tr><th>Slug</th><th>Label</th><th>DB Table</th><th>Color</th><th>Icon</th><th>Type</th><th>Action</th></tr></thead>
            <tbody>
            <tr><td class="mono" style="color:var(--ac)">deno</td><td style="font-weight:600">Deno (from deno table)</td>
                <td class="mono" style="font-size:11px">deno</td>
                <td><div style="width:18px;height:18px;border-radius:3px;background:var(--ac)"></div></td>
                <td>📘</td><td><span class="pill p-ok">Built-in</span></td>
                <td><span style="color:var(--mu);font-size:11px">Read-only from DB</span></td></tr>
            <?php foreach($modules as $m): $bi=in_array($m['slug'],array_column($built_in,0)); ?>
            <tr>
                <td class="mono" style="color:<?= htmlspecialchars($m['color']) ?>"><?= htmlspecialchars($m['slug']) ?></td>
                <td style="font-weight:600"><?= htmlspecialchars($m['label']) ?></td>
                <td class="mono" style="font-size:11px"><?= htmlspecialchars($m['tbl']) ?></td>
                <td><div style="width:18px;height:18px;border-radius:3px;background:<?= htmlspecialchars($m['color']) ?>"></div></td>
                <td><?= $m['icon'] ?></td>
                <td><?= $bi?'<span class="pill p-ok">Built-in</span>':'<span class="pill p-warn">Custom</span>' ?></td>
                <td>
                    <?php if(!$bi): ?>
                    <form method="post" style="display:inline" onsubmit="return confirm('Hide this module?')">
                        <input type="hidden" name="action" value="delete_module">
                        <input type="hidden" name="del_slug" value="<?= htmlspecialchars($m['slug']) ?>">
                        <button type="submit" class="btn btn-danger btn-xs">Hide</button>
                    </form>
                    <?php else: ?><span style="color:var(--mu);font-size:11px">Protected</span><?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
</div>

</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
const RD=<?= json_encode(array_map(function($r) use ($modules) {
    $row=['code'=>$r['book_code'],'name'=>$r['book_name'],'fy'=>$r['fy_code'],
          'cls'=>(int)($r['class_level']??0),'tr'=>(bool)$r['is_translated'],
          'deno_qty'=>(int)$r['deno_qty']];
    foreach($modules as $m){
        $row[$m['slug'].'_price']=(float)($r[$m['slug'].'_price']??0);
        $row[$m['slug'].'_qty']=(int)($r[$m['slug'].'_qty']??0);
    }
    return $row;
},$recon_data),JSON_UNESCAPED_UNICODE) ?>;

const MODS={
    deno:{label:'Deno',color:'#2563eb',pkey:null,qkey:'deno_qty'},
    <?php foreach($modules as $m): ?>
    <?= json_encode($m['slug']) ?>:{label:<?= json_encode($m['label']) ?>,color:<?= json_encode($m['color']) ?>,
        pkey:<?= json_encode($m['slug'].'_price') ?>,qkey:<?= json_encode($m['slug'].'_qty') ?>},
    <?php endforeach; ?>
};

function resP(r,slug){
    if(slug==='deno') return 0;
    let p=r[slug+'_price']||0; if(p>0) return p;
    p=r['marketing_price']||0; if(p>0) return p;
    for(const[s,m] of Object.entries(MODS)){if(s===slug||s==='deno') continue; p=r[m.pkey]||0; if(p>0) return p;}
    return 0;
}

function switchTab(id,btn){
    document.querySelectorAll('.tab-panel').forEach(p=>p.classList.remove('active'));
    document.querySelectorAll('.tab').forEach(t=>t.classList.remove('active'));
    document.getElementById('tab-'+id).classList.add('active'); btn.classList.add('active');
    if(id==='analysis') buildChart();
    if(id==='comparison') renderCmp();
}

/* Book search dropdown */
const bs=document.getElementById('bs'),bo=document.getElementById('bo'),bh=document.getElementById('bh');
if(bs){
    bs.addEventListener('focus',()=>{bo.style.display='block';fb()});
    bs.addEventListener('input',fb);
    document.addEventListener('click',e=>{if(!e.target.closest('.bdd')) bo.style.display='none';});
    function fb(){const t=bs.value.toLowerCase();bo.querySelectorAll('.bdd-opt').forEach(o=>{o.style.display=o.textContent.toLowerCase().includes(t)?'block':'none';});bo.style.display='block';}
    bo.querySelectorAll('.bdd-opt').forEach(o=>o.addEventListener('click',()=>{bs.value=o.dataset.text;bh.value=o.dataset.code;bo.style.display='none';}));
}

/* Sort table */
function srt(tabId,col){
    const t=document.querySelector('#tab-'+tabId+' table.rt tbody'); if(!t) return;
    const rows=[...t.querySelectorAll('tr')];
    const th=document.querySelector('#tab-'+tabId+' th[onclick*="\''+col+'\'"]');
    const asc=th?!th.classList.contains('sa'):true;
    document.querySelectorAll('#tab-'+tabId+' th').forEach(h=>h.classList.remove('sa','sd'));
    if(th) th.classList.add(asc?'sa':'sd');
    const colMap={book_code:0,book_name:1,class_level:2,fy_code:4,deno_qty:5};
    const ci=colMap[col]??0;
    rows.sort((a,b)=>{
        const av=a.cells[ci]?.textContent.trim()||'', bv=b.cells[ci]?.textContent.trim()||'';
        const an=parseFloat(av.replace(/,/g,'')), bn=parseFloat(bv.replace(/,/g,''));
        if(!isNaN(an)&&!isNaN(bn)) return asc?an-bn:bn-an;
        return asc?av.localeCompare(bv):bv.localeCompare(av);
    });
    rows.forEach(r=>t.appendChild(r));
}

function calcRow(slug,idx){
    const pr=document.getElementById(`pr-${slug}-${idx}`);
    const qt=document.getElementById(`qt-${slug}-${idx}`);
    const tot=document.getElementById(`tot-${slug}-${idx}`);
    if(!pr||!qt||!tot) return;
    tot.textContent=(parseFloat(pr.value||0)*parseInt(qt.value||0)).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2});
}

function clearMod(slug){
    if(!confirm(`Clear all ${slug}?`)) return;
    document.querySelectorAll(`#form-${slug} input[type="number"]`).forEach(i=>i.value='0');
    document.querySelectorAll(`#form-${slug} input[type="text"]`).forEach(i=>i.value='');
    document.querySelectorAll(`[id^="tot-${slug}-"]`).forEach(el=>el.textContent='0.00');
}

function autofill(slug){
    RD.forEach((r,i)=>{
        const el=document.getElementById(`pr-${slug}-${i}`);
        if(el&&parseFloat(el.value||0)===0){const p=resP(r,slug);if(p>0){el.value=p.toFixed(2);calcRow(slug,i);}}
    });
}

function dlTpl(slug){
    const fy=<?= json_encode($sel_fy) ?>;
    const rows=RD.map(r=>[r.code,'"'+r.name.replace(/"/g,'""')+'"',r.fy,resP(r,slug).toFixed(2),(r[MODS[slug]?.qkey]||0),''].join(','));
    const a=document.createElement('a');
    a.href=URL.createObjectURL(new Blob(['book_code,book_name,fiscal_year,price,qty,notes\n'+rows.join('\n')],{type:'text/csv'}));
    a.download=`template_${slug}_${fy}.csv`; a.click();
}

function sortMod(slug,col){
    const tb=document.getElementById('tbody-'+slug); if(!tb) return;
    const rows=[...tb.querySelectorAll('tr')];
    const asc=tb.dataset.sc!==col||tb.dataset.sd==='desc';
    tb.dataset.sc=col; tb.dataset.sd=asc?'asc':'desc';
    rows.sort((a,b)=>{
        let av=a.dataset[col]||'',bv=b.dataset[col]||'';
        const an=parseFloat(av),bn=parseFloat(bv);
        if(!isNaN(an)&&!isNaN(bn)) return asc?an-bn:bn-an;
        return asc?av.localeCompare(bv):bv.localeCompare(av);
    });
    rows.forEach(r=>tb.appendChild(r));
}

/* Formula */
const cbf=document.getElementById('cbf');
function ins(t){if(!cbf)return;const p=cbf.selectionStart,v=cbf.value;cbf.value=v.slice(0,p)+' '+t+' '+v.slice(p);cbf.focus();upPrev();}
function upPrev(){
    const f=cbf?.value?.trim()||'';
    document.getElementById('fprev').innerHTML=f?'Formula: <strong>'+f+'</strong>':'Type or click tokens to define the formula.';
    const u=new URL(window.location.href);u.searchParams.set('save_formula','1');u.searchParams.set('cb_formula',f);
    const b=document.getElementById('sfBtn');if(b)b.href=u.toString();
}
if(cbf){cbf.addEventListener('input',upPrev);upPrev();}

function evalF(formula,row){
    if(!formula) return null;
    let e=formula.toLowerCase();
    for(const[s,m] of Object.entries(MODS)){
        if(s==='deno') continue;
        const q=row[m.qkey]||0;
        e=e.replace(new RegExp('\\b'+s.replace(/_/g,'_')+'_qty\\b','g'),q);
        e=e.replace(new RegExp('\\b'+s+'(?!_)\\b','g'),q);
    }
    e=e.replace(/\bdeno_qty\b/g,row.deno_qty||0).replace(/\bdeno\b/g,row.deno_qty||0);
    if(!/^[\d\s\+\-\*\/\(\)\.]+$/.test(e)) return null;
    try{return Function('"use strict";return ('+e+')')();}catch(ex){return null;}
}

function syncC(cb){cb.closest('.cck').classList.toggle('on',cb.checked);}

function renderCmp(){
    const sel=[...document.querySelectorAll('#cmpChecks input:checked')].map(c=>c.value);
    const wrap=document.getElementById('cmpWrap');
    if(sel.length<2){wrap.innerHTML='<div style="padding:24px;color:var(--mu);text-align:center;font-size:13px">Select at least 2 modules.</div>';return;}
    const base=sel.includes('deno')?'deno':sel[0];
    const bM=MODS[base];
    const formula=cbf?.value?.trim()||'';

    let h1=`<tr><th rowspan="2" onclick="srtCmp('sn')">SN</th><th rowspan="2" onclick="srtCmp('code')">Code</th>
        <th rowspan="2" onclick="srtCmp('name')">Book Name</th><th rowspan="2" onclick="srtCmp('cls')">Class</th>
        <th rowspan="2">Trans</th><th rowspan="2" onclick="srtCmp('fy')">FY</th>`;
    let h2='<tr>';
    sel.forEach(m=>{
        const md=MODS[m],isDeno=m==='deno';
        h1+=`<th colspan="${isDeno?1:4}" style="text-align:center;color:${md.color};background:color-mix(in srgb,${md.color} 6%,white)">${md.label}</th>`;
        h2+=`<th style="color:${md.color}" onclick="srtCmp('${m}_qty')">Qty</th>`;
        if(!isDeno){h2+=`<th style="color:${md.color}">Price</th><th style="color:${md.color}">Total</th><th onclick="srtCmp('${m}_var')">Var vs ${bM.label}</th>`;}
    });
    if(formula) h1+=`<th rowspan="2" style="background:var(--warn-lt);color:var(--warn)" onclick="srtCmp('cb')">Closing Bal</th>`;
    h1+=`<th rowspan="2">Status</th></tr>`;h2+=`</tr>`;

    let rows='';
    RD.forEach((r,i)=>{
        const bQ=r[bM.qkey]||0;
        const ok=sel.filter(m=>m!==base).every(m=>(r[MODS[m].qkey]||0)===bQ);
        const pill=ok?'<span class="pill p-ok">✓</span>':'<span class="pill p-bad">⚠</span>';
        let cells='';
        const varMap={};
        sel.forEach(m=>{
            const md=MODS[m],isDeno=m==='deno';
            const qty=r[md.qkey]||0,price=md.pkey?resP(r,m):0,total=price*qty;
            const diff=qty-bQ,pct=bQ?(diff/bQ*100).toFixed(1)+'%':'—';
            const cls=diff>0?'vp':diff<0?'vn':'vz',sign=diff>=0?'+':'';
            varMap[m+'_var']=diff;
            cells+=`<td class="mono" style="color:${md.color};font-weight:600">${qty.toLocaleString()}</td>`;
            if(!isDeno){cells+=`<td class="mono" style="font-size:12px">${price.toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})}</td>
                <td class="mono" style="font-size:12px">${total.toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})}</td>
                <td class="${cls}">${sign}${diff.toLocaleString()} (${pct})</td>`;}
        });
        const cb=formula?evalF(formula,r):null;
        const cbCell=formula?`<td class="cbc" data-cb="${cb??''}">${cb!==null?parseFloat(cb.toFixed(2)).toLocaleString():'—'}</td>`:'';
        rows+=`<tr data-sn="${i+1}" data-code="${r.code}" data-name="${r.name}" data-cls="${r.cls}" data-fy="${r.fy}" ${Object.entries(varMap).map(([k,v])=>`data-${k}="${v}"`).join(' ')}>
            <td class="mono" style="font-size:12px">${i+1}</td>
            <td class="mono" style="color:var(--ac);font-size:12px">${r.code}</td>
            <td style="font-weight:500;white-space:nowrap">${r.name}</td>
            <td>${r.cls?`<span class="btg btg-cl">${r.cls}</span>`:''}</td>
            <td>${r.tr?'<span class="btg btg-tr">T</span>':''}</td>
            <td class="mono" style="font-size:12px">${r.fy}</td>
            ${cells}${cbCell}<td>${pill}</td></tr>`;
    });
    wrap.innerHTML=`<table class="rt" id="cmpT"><thead>${h1}${h2}</thead><tbody>${rows||'<tr><td colspan="20" style="padding:28px;text-align:center;color:var(--mu)">No data.</td></tr>'}</tbody></table>`;
}

function srtCmp(col){
    const tb=document.querySelector('#cmpT tbody');if(!tb) return;
    const rows=[...tb.querySelectorAll('tr')];
    const th=document.querySelector(`#cmpT th[onclick="srtCmp('${col}')"]`);
    const asc=th?!th.classList.contains('sa'):true;
    document.querySelectorAll('#cmpT th').forEach(h=>h.classList.remove('sa','sd'));
    if(th) th.classList.add(asc?'sa':'sd');
    rows.sort((a,b)=>{
        let av=a.dataset[col.replace(/_var/,'_var')]||'',bv=b.dataset[col.replace(/_var/,'_var')]||'';
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
        sel.forEach(m=>{const md=MODS[m];const qty=r[md.qkey]||0;const price=md.pkey?resP(r,m):0;row.push(qty);if(m!=='deno') row.push(price.toFixed(2),(price*qty).toFixed(2),(qty-(r[MODS[base].qkey]||0)));});
        if(formula){const cb=evalF(formula,r);row.push(cb!==null?parseFloat(cb.toFixed(2)):'');}
        lines.push(row.join(','));
    });
    const a=document.createElement('a');
    a.href=URL.createObjectURL(new Blob([lines.join('\n')],{type:'text/csv'}));
    a.download='comparison_<?= htmlspecialchars($sel_fy) ?>_'+new Date().toISOString().split('T')[0]+'.csv';a.click();
}

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
        options:{responsive:true,plugins:{legend:{labels:{color:'#475569',font:{family:'Inter'}}},tooltip:{mode:'index',intersect:false}},
            scales:{x:{ticks:{color:'#94a3b8',maxRotation:45,font:{size:10}},grid:{color:'rgba(0,0,0,.04)'}},
                    y:{ticks:{color:'#94a3b8'},grid:{color:'rgba(0,0,0,.04)'}}}}
    });
}

document.addEventListener('DOMContentLoaded',()=>{ renderCmp(); });
</script>
</body></html>
<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>