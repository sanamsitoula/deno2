<?php
/**
 * forma_ctp_templates.php — Manage imposition layout templates
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
redirect_if_not_logged_in();

$msg = $error = '';
$edit_id = (int)($_GET['edit'] ?? 0);
$action  = $_GET['action'] ?? 'list';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pa = $_POST['post_action'] ?? '';
    if ($pa === 'save_template') {
        $eid = (int)($_POST['edit_id'] ?? 0);
        $data = [
            ':name' => trim($_POST['template_name']??''),
            ':lt'   => $_POST['layout_type']??'8up_booklet',
            ':cols' => (int)($_POST['cols']??4),
            ':rows' => (int)($_POST['rows']??2),
            ':ppp'  => (int)($_POST['pages_per_plate']??8),
            ':pps'  => (int)($_POST['pages_per_side']??4),
            ':im'   => $_POST['imposition_mode']??'sheetwork',
            ':pw'   => (float)($_POST['plate_width']??720),
            ':ph'   => (float)($_POST['plate_height']??508),
            ':bl'   => (float)($_POST['bleed']??3),
            ':gu'   => (float)($_POST['gutter']??5),
            ':to'   => (float)($_POST['trim_outer']??8),
            ':gr'   => (float)($_POST['gripper']??10),
            ':hm'   => (float)($_POST['head_margin']??8),
            ':fm'   => (float)($_POST['foot_margin']??8),
            ':sm'   => (float)($_POST['spine_margin']??5),
            ':cm'   => (float)($_POST['cutting_margin']??3),
            ':is_d' => isset($_POST['is_default']) ? 1 : 0,
            ':notes'=> trim($_POST['notes']??''),
            ':by'   => $_SESSION['username']??'system',
        ];
        if (!$data[':name']) { $error = 'Template name required.'; }
        else {
            try {
                if ($eid) {
                    $sql = "UPDATE fctp_imposition_templates SET template_name=:name,layout_type=:lt,cols=:cols,rows=:rows,pages_per_plate=:ppp,pages_per_side=:pps,imposition_mode=:im,plate_width=:pw,plate_height=:ph,bleed=:bl,gutter=:gu,trim_outer=:to,gripper=:gr,head_margin=:hm,foot_margin=:fm,spine_margin=:sm,cutting_margin=:cm,is_default=:is_d,notes=:notes WHERE id=:id";
                    $data[':id'] = $eid;
                    $conn->prepare($sql)->execute($data);
                    $msg = 'Template updated.';
                } else {
                    $sql = "INSERT INTO fctp_imposition_templates (template_name,layout_type,cols,rows,pages_per_plate,pages_per_side,imposition_mode,plate_width,plate_height,bleed,gutter,trim_outer,gripper,head_margin,foot_margin,spine_margin,cutting_margin,is_default,notes,created_by) VALUES(:name,:lt,:cols,:rows,:ppp,:pps,:im,:pw,:ph,:bl,:gu,:to,:gr,:hm,:fm,:sm,:cm,:is_d,:notes,:by)";
                    $conn->prepare($sql)->execute($data);
                    $msg = 'Template created.';
                }
            } catch (Exception $e) { $error = $e->getMessage(); }
        }
        $action = 'list'; $edit_id = 0;
    }
    if ($pa === 'delete_template') {
        $conn->prepare("DELETE FROM fctp_imposition_templates WHERE id=:id")->execute([':id'=>(int)$_POST['del_id']]);
        $msg = 'Template deleted.';
        $action = 'list';
    }
}

$edit_tpl = null;
if ($action === 'edit' && $edit_id) {
    $edit_tpl = $conn->prepare("SELECT * FROM fctp_imposition_templates WHERE id=:id");
    $edit_tpl->execute([':id'=>$edit_id]);
    $edit_tpl = $edit_tpl->fetch(PDO::FETCH_ASSOC);
}

$templates = $conn->query("SELECT * FROM fctp_imposition_templates ORDER BY is_default DESC, id")->fetchAll(PDO::FETCH_ASSOC);

require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';
?>
<style>
:root{--primary:#1a73e8;--success:#16a34a;--danger:#dc2626;--dark:#1e293b;--muted:#64748b;--border:#e2e8f0;}
*{box-sizing:border-box;}
.fctp-wrap{max-width:1200px;margin:0 auto;padding:0 16px 40px;}
.fctp-nav{display:flex;gap:8px;flex-wrap:wrap;background:#fff;padding:12px 18px;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,.07);align-items:center;margin-bottom:20px;}
.fctp-nav-title{font-weight:800;font-size:15px;color:var(--dark);margin-right:8px;}
.nav-btn{padding:6px 14px;border-radius:6px;font-size:12.5px;font-weight:600;text-decoration:none;color:var(--muted);border:1.5px solid var(--border);transition:.15s;}
.nav-btn:hover,.nav-btn.active{background:var(--primary);color:#fff;border-color:var(--primary);}
.page-title{font-size:22px;font-weight:800;color:var(--dark);margin-bottom:20px;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 18px;border-radius:7px;font-size:13px;font-weight:600;text-decoration:none;border:none;cursor:pointer;transition:.15s;}
.btn-primary{background:var(--primary);color:#fff;}
.btn-sm{padding:5px 12px;font-size:12px;}
.btn-danger{background:var(--danger);color:#fff;}
.btn-outline{background:#fff;color:var(--primary);border:1.5px solid var(--primary);}
.card{background:#fff;border-radius:12px;box-shadow:0 2px 12px rgba(0,0,0,.07);overflow:hidden;margin-bottom:24px;}
.card-header{padding:14px 20px;border-bottom:1px solid var(--border);font-weight:700;font-size:14px;}
.card-body{padding:20px;}
.form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;}
.form-group{display:flex;flex-direction:column;gap:4px;}
.form-group label{font-size:11px;font-weight:700;color:var(--dark);text-transform:uppercase;}
.form-group input,.form-group select,.form-group textarea{padding:8px 11px;border:1.5px solid var(--border);border-radius:6px;font-size:13px;outline:none;}
.form-group input:focus,.form-group select:focus{border-color:var(--primary);}
.form-full{grid-column:1/-1;}
.form-actions{display:flex;gap:10px;margin-top:20px;padding-top:14px;border-top:1px solid var(--border);}
.alert{padding:12px 18px;border-radius:8px;font-size:13px;margin-bottom:16px;font-weight:500;}
.alert-success{background:#dcfce7;color:#16a34a;border:1px solid #bbf7d0;}
.alert-error{background:#fee2e2;color:var(--danger);border:1px solid #fecaca;}
table{width:100%;border-collapse:collapse;font-size:13px;}
th{background:#f1f5f9;padding:10px 14px;text-align:left;font-weight:700;font-size:11px;text-transform:uppercase;color:var(--muted);border-bottom:2px solid var(--border);}
td{padding:10px 14px;border-bottom:1px solid #f1f5f9;}
tr:hover td{background:#fafbff;}
.badge-default{background:#dbeafe;color:#1a73e8;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;}
.action-btns{display:flex;gap:6px;}
</style>

<div class="fctp-wrap">
<div class="fctp-nav">
    <span class="fctp-nav-title">🖨️ Forma CTP</span>
    <a href="forma_ctp.php" class="nav-btn">📋 Job Tickets</a>
    <a href="forma_ctp_book.php" class="nav-btn">📚 Books</a>
    <a href="forma_ctp_job_create.php" class="nav-btn">➕ New Job</a>
    <a href="forma_ctp_templates.php" class="nav-btn active">⚙️ Templates</a>
</div>

<?php if ($msg): ?><div class="alert alert-success">✅ <?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error">❌ <?= htmlspecialchars($error) ?></div><?php endif; ?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
    <div class="page-title" style="margin:0">⚙️ Imposition Templates</div>
    <a href="forma_ctp_templates.php?action=create" class="btn btn-primary btn-sm">➕ New Template</a>
</div>

<?php if ($action === 'create' || $action === 'edit'): ?>
<div class="card">
    <div class="card-header"><?= $action==='edit'?'✏️ Edit Template':'➕ New Template' ?></div>
    <div class="card-body">
    <form method="POST">
    <input type="hidden" name="post_action" value="save_template">
    <input type="hidden" name="edit_id" value="<?= $edit_tpl['id']??0 ?>">
    <div class="form-grid">
        <div class="form-group form-full">
            <label>Template Name *</label>
            <input type="text" name="template_name" value="<?= htmlspecialchars($edit_tpl['template_name']??'') ?>" required>
        </div>
        <div class="form-group">
            <label>Layout Type</label>
            <select name="layout_type">
                <?php foreach(['8up_booklet'=>'8-Up Booklet (4×2)','4up_booklet'=>'4-Up Booklet (2×2)','2up'=>'2-Up (2×1)','cover_4pp'=>'Cover 4pp','custom'=>'Custom'] as $v=>$l): ?>
                <option value="<?=$v?>" <?=($edit_tpl['layout_type']??'8up_booklet')===$v?'selected':''?>><?=$l?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Cols</label>
            <input type="number" name="cols" value="<?= $edit_tpl['cols']??4 ?>" min="1" max="8">
        </div>
        <div class="form-group">
            <label>Rows</label>
            <input type="number" name="rows" value="<?= $edit_tpl['rows']??2 ?>" min="1" max="6">
        </div>
        <div class="form-group">
            <label>Pages/Plate</label>
            <input type="number" name="pages_per_plate" value="<?= $edit_tpl['pages_per_plate']??8 ?>">
        </div>
        <div class="form-group">
            <label>Pages/Side</label>
            <input type="number" name="pages_per_side" value="<?= $edit_tpl['pages_per_side']??4 ?>">
        </div>
        <div class="form-group">
            <label>Imposition Mode</label>
            <select name="imposition_mode">
                <option value="sheetwork" <?=($edit_tpl['imposition_mode']??'')==='sheetwork'?'selected':''?>>Sheetwork</option>
                <option value="work_and_turn" <?=($edit_tpl['imposition_mode']??'')==='work_and_turn'?'selected':''?>>Work & Turn</option>
                <option value="work_and_tumble" <?=($edit_tpl['imposition_mode']??'')==='work_and_tumble'?'selected':''?>>Work & Tumble</option>
            </select>
        </div>
        <div class="form-group">
            <label>Plate Width (mm)</label>
            <input type="number" name="plate_width" value="<?= $edit_tpl['plate_width']??720 ?>" step="0.5">
        </div>
        <div class="form-group">
            <label>Plate Height (mm)</label>
            <input type="number" name="plate_height" value="<?= $edit_tpl['plate_height']??508 ?>" step="0.5">
        </div>
        <div class="form-group"><label>Bleed (mm)</label><input type="number" name="bleed" value="<?= $edit_tpl['bleed']??3 ?>" step="0.5"></div>
        <div class="form-group"><label>Gutter (mm)</label><input type="number" name="gutter" value="<?= $edit_tpl['gutter']??5 ?>" step="0.5"></div>
        <div class="form-group"><label>Trim Outer (mm)</label><input type="number" name="trim_outer" value="<?= $edit_tpl['trim_outer']??8 ?>" step="0.5"></div>
        <div class="form-group"><label>Gripper (mm)</label><input type="number" name="gripper" value="<?= $edit_tpl['gripper']??10 ?>" step="0.5"></div>
        <div class="form-group"><label>Head Margin (mm)</label><input type="number" name="head_margin" value="<?= $edit_tpl['head_margin']??8 ?>" step="0.5"></div>
        <div class="form-group"><label>Foot Margin (mm)</label><input type="number" name="foot_margin" value="<?= $edit_tpl['foot_margin']??8 ?>" step="0.5"></div>
        <div class="form-group"><label>Spine Margin (mm)</label><input type="number" name="spine_margin" value="<?= $edit_tpl['spine_margin']??5 ?>" step="0.5"></div>
        <div class="form-group"><label>Cutting Margin (mm)</label><input type="number" name="cutting_margin" value="<?= $edit_tpl['cutting_margin']??3 ?>" step="0.5"></div>
        <div class="form-group form-full">
            <label>Notes</label>
            <textarea name="notes"><?= htmlspecialchars($edit_tpl['notes']??'') ?></textarea>
        </div>
        <div class="form-group form-full">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                <input type="checkbox" name="is_default" <?= !empty($edit_tpl['is_default'])?'checked':'' ?>>
                Set as default template
            </label>
        </div>
    </div>
    <div class="form-actions">
        <button type="submit" class="btn btn-primary">💾 Save Template</button>
        <a href="forma_ctp_templates.php" class="btn btn-outline">Cancel</a>
    </div>
    </form>
    </div>
</div>
<?php endif; ?>

<div class="card">
<div class="card-header">All Templates (<?= count($templates) ?>)</div>
<table>
<thead>
<tr>
    <th>Name</th><th>Layout</th><th>Cols×Rows</th><th>Mode</th><th>Plate (mm)</th><th>Bleed</th><th>Gutter</th><th>Gripper</th><th>Head</th><th>Spine</th><th>Cut</th><th>Actions</th>
</tr>
</thead>
<tbody>
<?php foreach ($templates as $t): ?>
<tr>
    <td><strong><?= htmlspecialchars($t['template_name']) ?></strong><?= $t['is_default']?' <span class="badge-default">Default</span>':'' ?></td>
    <td><?= $t['layout_type'] ?></td>
    <td><?= $t['cols'] ?>×<?= $t['rows'] ?></td>
    <td><?= $t['imposition_mode'] ?></td>
    <td><?= $t['plate_width'] ?>×<?= $t['plate_height'] ?></td>
    <td><?= $t['bleed'] ?></td>
    <td><?= $t['gutter'] ?></td>
    <td><?= $t['gripper'] ?></td>
    <td><?= $t['head_margin'] ?></td>
    <td><?= $t['spine_margin'] ?></td>
    <td><?= $t['cutting_margin'] ?></td>
    <td>
        <div class="action-btns">
            <a href="forma_ctp_templates.php?action=edit&edit=<?= $t['id'] ?>" class="btn btn-sm btn-outline">✏️</a>
            <form method="POST" style="display:inline" onsubmit="return confirm('Delete template?')">
                <input type="hidden" name="post_action" value="delete_template">
                <input type="hidden" name="del_id" value="<?= $t['id'] ?>">
                <button class="btn btn-sm btn-danger">🗑</button>
            </form>
        </div>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</div>
<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>
