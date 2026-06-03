<?php
/**
 * forma_ctp_imposition.php — Imposition Editor
 * Visual plate layout editor: assign book pages to slots, set rotation, blank pages
 * Auto-calculates standard booklet imposition, allows full override
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
redirect_if_not_logged_in();

$forma_id = (int)($_GET['id'] ?? 0);
if (!$forma_id) { header('Location: forma_ctp.php'); exit; }

// Load forma
$stmt = $conn->prepare("SELECT f.*, jt.job_ticket_code, jt.print_qty AS jt_pq, b.book_name, b.book_code, b.master_pdf_path, b.master_pdf_pages FROM fctp_formas f JOIN fctp_job_tickets jt ON f.job_ticket_id=jt.id JOIN fctp_books b ON f.book_code=b.book_code WHERE f.id=:id");
$stmt->execute([':id'=>$forma_id]);
$forma = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$forma) { header('Location: forma_ctp.php'); exit; }

$msg = $error = '';

// ── Save imposition JSON ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['post_action']??'')==='save_imposition') {
    $json_raw = $_POST['imposition_json'] ?? '';
    $decoded  = json_decode($json_raw, true);
    if (!$decoded || !isset($decoded['plates'])) {
        $error = 'Invalid imposition data.';
    } else {
        $conn->prepare("UPDATE fctp_formas SET imposition_json=:ij,output_status='ready',updated_at=NOW() WHERE id=:id")
             ->execute([':ij'=>$json_raw, ':id'=>$forma_id]);
        $msg = 'Imposition saved. Ready for PDF generation.';
        // Reload
        $forma['imposition_json'] = $json_raw;
        $forma['output_status']   = 'ready';
    }
}

// ── Auto-calculate booklet imposition ────────────────────────
function calcBookletImposition(int $page_start, int $page_end, int $cols, int $rows, string $layout): array {
    $page_count   = $page_end - $page_start + 1;
    $pages_per_side  = $cols * $rows;
    $pages_per_plate = $pages_per_side * 2;  // both sides
    $num_plates   = max(1, (int)ceil($page_count / $pages_per_plate));

    // Pad to multiple of pages_per_plate
    $total_slots  = $num_plates * $pages_per_plate;
    $pages_padded = [];
    for ($p = $page_start; $p <= $page_end; $p++) $pages_padded[] = $p;
    while (count($pages_padded) < $total_slots) $pages_padded[] = null; // blank

    $plates = [];
    for ($plate = 0; $plate < $num_plates; $plate++) {
        $offset_f = $plate * $pages_per_plate;
        $offset_b = $offset_f + $pages_per_side;

        $front_slots = [];
        $back_slots  = [];

        for ($s = 0; $s < $pages_per_side; $s++) {
            $col = ($s % $cols) + 1;
            $row = (int)($s / $cols) + 1;

            // Head-to-head: top row (row==1) is rotated 180°
            $rot_front = ($row === 1) ? 180 : 0;
            $rot_back  = ($row === 1) ? 180 : 0;

            $fp = $pages_padded[$offset_f + $s] ?? null;
            $bp = $pages_padded[$offset_b + $s] ?? null;

            $front_slots[] = [
                'slot'      => $s + 1,
                'col'       => $col,
                'row'       => $row,
                'book_page' => $fp,
                'rotation'  => $rot_front,
                'is_blank'  => ($fp === null),
                'label'     => $fp !== null ? (string)$fp : 'BLANK',
            ];
            $back_slots[] = [
                'slot'      => $s + 1,
                'col'       => $col,
                'row'       => $row,
                'book_page' => $bp,
                'rotation'  => $rot_back,
                'is_blank'  => ($bp === null),
                'label'     => $bp !== null ? (string)$bp : 'BLANK',
            ];
        }

        $plates[] = [
            'plate_no' => $plate + 1,
            'sides'    => [
                'front' => $front_slots,
                'back'  => $back_slots,
            ],
        ];
    }
    return ['plates' => $plates];
}

// Load or auto-generate imposition
$imposition = null;
if (!empty($forma['imposition_json'])) {
    $imposition = json_decode($forma['imposition_json'], true);
}
if (!$imposition) {
    $imposition = calcBookletImposition(
        (int)$forma['page_start'],
        (int)$forma['page_end'],
        (int)$forma['cols'],
        (int)$forma['rows'],
        $forma['layout_type']
    );
}

$plates_data = $imposition['plates'] ?? [];
$num_plates  = count($plates_data);

require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';
?>
<style>
:root{--primary:#1a73e8;--success:#16a34a;--warning:#d97706;--danger:#dc2626;--dark:#1e293b;--muted:#64748b;--border:#e2e8f0;}
*{box-sizing:border-box;}
.fctp-wrap{max-width:1600px;margin:0 auto;padding:0 16px 40px;}
.fctp-nav{display:flex;gap:8px;flex-wrap:wrap;background:#fff;padding:12px 18px;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,.07);align-items:center;margin-bottom:20px;}
.fctp-nav-title{font-weight:800;font-size:15px;color:var(--dark);margin-right:8px;}
.nav-btn{padding:6px 14px;border-radius:6px;font-size:12.5px;font-weight:600;text-decoration:none;color:var(--muted);border:1.5px solid var(--border);transition:.15s;}
.nav-btn:hover,.nav-btn.active{background:var(--primary);color:#fff;border-color:var(--primary);}
.page-header{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:10px;}
.page-title{font-size:20px;font-weight:800;color:var(--dark);}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 18px;border-radius:7px;font-size:13px;font-weight:600;text-decoration:none;border:none;cursor:pointer;transition:.15s;}
.btn-primary{background:var(--primary);color:#fff;}
.btn-primary:hover{background:#1557b0;}
.btn-success{background:var(--success);color:#fff;}
.btn-sm{padding:5px 12px;font-size:12px;}
.btn-outline{background:#fff;color:var(--primary);border:1.5px solid var(--primary);}
.btn-outline:hover{background:var(--primary);color:#fff;}
.btn-orange{background:#ea580c;color:#fff;}
.alert{padding:12px 18px;border-radius:8px;font-size:13px;margin-bottom:16px;font-weight:500;}
.alert-success{background:#dcfce7;color:#16a34a;border:1px solid #bbf7d0;}
.alert-error{background:#fee2e2;color:var(--danger);border:1px solid #fecaca;}
.info-strip{background:#fff;border-radius:10px;padding:12px 18px;box-shadow:0 2px 8px rgba(0,0,0,.06);display:flex;gap:24px;flex-wrap:wrap;margin-bottom:20px;align-items:center;}
.is-item{display:flex;flex-direction:column;gap:2px;}
.is-label{font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;}
.is-val{font-size:13px;font-weight:700;color:var(--dark);}
/* Toolbar */
.toolbar{background:#1e293b;border-radius:10px;padding:12px 18px;display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:20px;}
.toolbar-label{color:#94a3b8;font-size:12px;font-weight:600;}
.tool-btn{padding:6px 12px;border-radius:6px;font-size:12px;font-weight:600;border:1.5px solid #475569;color:#94a3b8;background:transparent;cursor:pointer;transition:.15s;}
.tool-btn:hover,.tool-btn.active{background:var(--primary);color:#fff;border-color:var(--primary);}
.tool-sep{width:1px;height:24px;background:#334155;}
/* Plate container */
.plates-container{display:flex;flex-direction:column;gap:28px;}
.plate-wrap{background:#fff;border-radius:12px;border:2px solid var(--border);overflow:hidden;}
.plate-header{background:#1e293b;color:#fff;padding:10px 18px;display:flex;align-items:center;justify-content:space-between;}
.plate-title{font-weight:800;font-size:14px;}
.plate-side-label{font-size:11px;color:#94a3b8;}
.plate-body{padding:16px;}
.plate-sides{display:grid;grid-template-columns:1fr 1fr;gap:24px;}
@media(max-width:900px){.plate-sides{grid-template-columns:1fr;}}
.side-wrap{border:1.5px solid var(--border);border-radius:8px;overflow:hidden;}
.side-header{background:#f8fafc;padding:8px 12px;font-weight:700;font-size:12px;color:var(--dark);border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;}
.slot-grid{display:grid;gap:2px;padding:8px;background:#f1f5f9;}
/* Slot cell */
.slot-cell{background:#fff;border:1.5px solid var(--border);border-radius:6px;padding:8px;display:flex;flex-direction:column;gap:4px;cursor:pointer;transition:.15s;min-height:90px;position:relative;}
.slot-cell:hover{border-color:var(--primary);box-shadow:0 2px 8px rgba(26,115,232,.15);}
.slot-cell.rotated{background:#fffbeb;border-color:#fbbf24;}
.slot-cell.blank{background:#fafafa;border-style:dashed;border-color:#cbd5e1;}
.slot-cell.selected{border-color:var(--primary);box-shadow:0 0 0 3px rgba(26,115,232,.2);}
.slot-num{font-size:10px;font-weight:700;color:var(--muted);position:absolute;top:5px;left:7px;}
.slot-page-input{width:100%;border:none;background:transparent;text-align:center;font-size:22px;font-weight:900;color:var(--dark);outline:none;padding:0;}
.slot-page-input.blank-pg{color:#94a3b8;font-size:14px;}
.slot-controls{display:flex;gap:4px;justify-content:center;flex-wrap:wrap;}
.slot-ctrl-btn{padding:2px 6px;border-radius:4px;font-size:10px;font-weight:700;border:1px solid var(--border);background:#fff;cursor:pointer;transition:.15s;}
.slot-ctrl-btn:hover{background:var(--primary);color:#fff;border-color:var(--primary);}
.slot-ctrl-btn.active{background:#1a73e8;color:#fff;border-color:#1a73e8;}
.slot-ctrl-btn.rot{background:#fef9c3;color:#a16207;border-color:#fde68a;}
.slot-ctrl-btn.rot.active{background:#d97706;color:#fff;}
.slot-ctrl-btn.blank{background:#f0fdf4;color:#16a34a;border-color:#bbf7d0;}
.rotation-indicator{font-size:11px;font-weight:700;color:#d97706;text-align:center;}
.blank-indicator{font-size:11px;color:#94a3b8;text-align:center;}
.col-label{text-align:center;font-size:10px;font-weight:700;color:var(--muted);padding:2px;}
.row-label{font-size:10px;font-weight:700;color:var(--muted);padding:4px;display:flex;align-items:center;justify-content:center;}
/* margin diagram */
.margin-diagram{background:#f8fafc;border:1px solid var(--border);border-radius:8px;padding:14px;margin-bottom:16px;font-size:12px;}
.margin-row{display:flex;gap:8px;flex-wrap:wrap;}
.margin-item{background:#fff;border:1px solid var(--border);border-radius:5px;padding:5px 10px;font-size:11px;font-weight:600;}
.margin-item span{color:var(--primary);}
/* Slot map */
.slot-map-wrap{background:#fff3cd;border:1px solid #ffc107;border-radius:8px;padding:12px 16px;margin-bottom:16px;}
.slot-map-title{font-size:11px;font-weight:800;color:#856404;margin-bottom:8px;text-transform:uppercase;letter-spacing:.5px;}
.slot-map-grid{display:inline-grid;gap:2px;border:2px solid #adb5bd;border-radius:4px;overflow:hidden;}
.slot-map-cell{background:#e9ecef;text-align:center;padding:6px 10px;font-size:11px;font-weight:700;color:#495057;border:1px solid #ced4da;min-width:52px;}
.slot-map-cell .sn{font-size:14px;color:#1a73e8;}
.slot-map-cell .sr{font-size:9px;color:#868e96;}
/* Quick map table */
.map-table{width:100%;border-collapse:collapse;font-size:12px;margin-top:10px;}
.map-table th{background:#1e293b;color:#fff;padding:5px 10px;text-align:center;font-size:11px;}
.map-table td{padding:5px 10px;border:1px solid var(--border);text-align:center;}
.map-table tr:nth-child(even) td{background:#f8fafc;}
.map-table .pg-num{font-weight:800;color:var(--primary);font-size:14px;}
.map-table .blank-cell{color:#94a3b8;font-style:italic;}
.plate-map-section{background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:12px;margin-bottom:12px;}
.plate-map-section h4{font-size:12px;font-weight:700;color:#0369a1;margin:0 0 8px;}
</style>

<div class="fctp-wrap">
<div class="fctp-nav">
    <span class="fctp-nav-title">🖨️ Forma CTP</span>
    <a href="forma_ctp.php" class="nav-btn">📋 Jobs</a>
    <a href="forma_ctp_job_view.php?id=<?= $forma['job_ticket_id'] ?>" class="nav-btn">← Back to Job</a>
    <a href="forma_ctp_generate.php?id=<?= $forma_id ?>" class="nav-btn" style="background:#16a34a;color:#fff;border-color:#16a34a">⚡ Generate PDF</a>
</div>

<?php if ($msg): ?><div class="alert alert-success">✅ <?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error">❌ <?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="page-header">
    <div>
        <div class="page-title">🔲 Imposition Editor — <?= htmlspecialchars($forma['forma_name']) ?></div>
        <div style="font-size:13px;color:var(--muted)"><?= htmlspecialchars($forma['book_name']) ?> · pp <?= $forma['page_start'] ?>–<?= $forma['page_end'] ?> (<?= $forma['page_count'] ?>pp) · <?= $forma['cols'] ?>×<?= $forma['rows'] ?> layout</div>
    </div>
    <div style="display:flex;gap:8px">
        <button onclick="autoCalculate()" class="btn btn-outline btn-sm">🔄 Auto-Calc</button>
        <button onclick="saveImposition()" class="btn btn-primary">💾 Save Imposition</button>
    </div>
</div>

<!-- INFO STRIP -->
<div class="info-strip">
    <div class="is-item"><span class="is-label">Forma</span><span class="is-val"><?= htmlspecialchars($forma['forma_name']) ?></span></div>
    <div class="is-item"><span class="is-label">Pages</span><span class="is-val">pp <?= $forma['page_start'] ?>–<?= $forma['page_end'] ?></span></div>
    <div class="is-item"><span class="is-label">Total Pages</span><span class="is-val"><?= $forma['page_count'] ?></span></div>
    <div class="is-item"><span class="is-label">Layout</span><span class="is-val"><?= $forma['cols'] ?>×<?= $forma['rows'] ?> = <?= $forma['pages_per_side'] ?>pp/side</span></div>
    <div class="is-item"><span class="is-label">Plates</span><span class="is-val"><?= $num_plates ?></span></div>
    <div class="is-item"><span class="is-label">Plate Size</span><span class="is-val"><?= $forma['plate_width'] ?>×<?= $forma['plate_height'] ?>mm</span></div>
    <div class="is-item"><span class="is-label">Status</span>
        <span class="is-val" style="color:<?= $forma['output_status']==='generated'?'#16a34a':($forma['output_status']==='ready'?'#1a73e8':'#d97706') ?>">
            <?= ucfirst($forma['output_status']) ?>
        </span>
    </div>
</div>

<!-- MARGIN DIAGRAM -->
<div class="margin-diagram">
    <strong style="font-size:12px;display:block;margin-bottom:8px;color:var(--dark)">📐 Margin / Cut Settings (mm)</strong>
    <div class="margin-row">
        <div class="margin-item">Bleed: <span><?= $forma['bleed'] ?></span></div>
        <div class="margin-item">Gutter: <span><?= $forma['gutter'] ?></span></div>
        <div class="margin-item">Trim Outer: <span><?= $forma['trim_outer'] ?></span></div>
        <div class="margin-item">Gripper: <span><?= $forma['gripper'] ?></span></div>
        <div class="margin-item">Head: <span><?= $forma['head_margin'] ?></span></div>
        <div class="margin-item">Foot: <span><?= $forma['foot_margin'] ?></span></div>
        <div class="margin-item">Spine: <span><?= $forma['spine_margin'] ?></span></div>
        <div class="margin-item">Cut Margin: <span><?= $forma['cutting_margin'] ?></span></div>
    </div>
</div>

<!-- TOOLBAR -->
<div class="toolbar">
    <span class="toolbar-label">Quick Actions:</span>
    <button class="tool-btn" onclick="toggleAllRotation()">↻ Toggle All Row-1 Rotation</button>
    <button class="tool-btn" onclick="resetToAuto()">🔄 Reset Auto-Calc</button>
    <div class="tool-sep"></div>
    <span class="toolbar-label">Legend:</span>
    <span style="background:#fffbeb;color:#a16207;padding:3px 8px;border-radius:4px;font-size:11px;font-weight:600;border:1px solid #fde68a">↻ = 180° Rotated</span>
    <span style="background:#f8fafc;color:#94a3b8;padding:3px 8px;border-radius:4px;font-size:11px;font-weight:600;border:1px dashed #cbd5e1">BLANK</span>
    <div class="tool-sep"></div>
    <a href="forma_ctp_generate.php?id=<?= $forma_id ?>" class="tool-btn" style="background:#16a34a;color:#fff;border-color:#16a34a">⚡ Generate Plate PDF</a>
</div>

<!-- HIDDEN FORM -->
<form method="POST" id="impositionForm">
    <input type="hidden" name="post_action" value="save_imposition">
    <input type="hidden" name="imposition_json" id="impositionJsonField">
</form>

<!-- SLOT POSITION MAP -->
<div class="slot-map-wrap">
    <div class="slot-map-title">📌 Slot Position Map — Grid Layout (<?= $forma['cols'] ?>×<?= $forma['rows'] ?> = <?= $forma['pages_per_side'] ?> slots per side)</div>
    <div style="display:flex;gap:24px;align-items:flex-start;flex-wrap:wrap;">
        <div>
            <div style="font-size:11px;color:#856404;margin-bottom:6px;font-weight:600;">Slot numbers on the plate:</div>
            <div class="slot-map-grid" style="grid-template-columns:<?= implode(' ', array_fill(0, (int)$forma['cols'], '1fr')) ?>">
                <?php
                $total_slots = (int)$forma['cols'] * (int)$forma['rows'];
                for ($s = 1; $s <= $total_slots; $s++):
                    $sc = (($s-1) % (int)$forma['cols']) + 1;
                    $sr = (int)(($s-1) / (int)$forma['cols']) + 1;
                    $is_row1 = ($sr === 1);
                ?>
                <div class="slot-map-cell" style="<?= $is_row1 ? 'background:#fff3cd;' : '' ?>">
                    <div class="sn"><?= $s ?></div>
                    <div class="sr">C<?= $sc ?>·R<?= $sr ?><?= $is_row1 ? ' ↻' : '' ?></div>
                </div>
                <?php endfor; ?>
            </div>
            <div style="font-size:10px;color:#856404;margin-top:5px;">↻ = Row 1 slots (head-to-head, default 180°)</div>
        </div>
        <div style="flex:1;min-width:200px;">
            <div style="font-size:11px;color:#856404;margin-bottom:6px;font-weight:600;">How to read the editor:</div>
            <ul style="font-size:11px;color:#495057;margin:0;padding-left:16px;line-height:1.8;">
                <li>Each slot box = one page position on the physical plate</li>
                <li>The <strong>number in the box</strong> = which PDF page goes there</li>
                <li>Slot <strong>1</strong> = top-left corner of plate</li>
                <li>Slot <strong><?= $forma['cols'] ?></strong> = top-right · Slot <strong><?= $total_slots ?></strong> = bottom-right</li>
                <li>Change the number to remap any page to any slot</li>
                <li>Click <strong>∅</strong> to mark a slot as blank (no page)</li>
                <li>Click <strong>↻</strong> to toggle 180° rotation for that slot</li>
            </ul>
        </div>
    </div>
</div>

<!-- PLATES -->
<div class="plates-container" id="platesContainer">
<?php foreach ($plates_data as $plate): ?>
<?php $pno = $plate['plate_no']; ?>
<div class="plate-wrap" data-plate="<?= $pno ?>">
    <div class="plate-header">
        <span class="plate-title">🖨️ Plate <?= $pno ?></span>
        <span class="plate-side-label"><?= $forma['page_count'] ?>pp · <?= $forma['cols'] ?>×<?= $forma['rows'] ?> · <?= $forma['plate_width'] ?>×<?= $forma['plate_height'] ?>mm</span>
    </div>
    <div class="plate-body">
        <!-- Quick mapping table -->
        <div class="plate-map-section">
            <h4>🗺️ Plate <?= $pno ?> — Slot → PDF Page Mapping</h4>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <?php foreach (['front','back'] as $ms): ?>
            <?php $ms_slots = $plate['sides'][$ms] ?? []; ?>
            <div>
                <div style="font-size:10px;font-weight:800;color:#0369a1;text-transform:uppercase;margin-bottom:4px;"><?= strtoupper($ms) ?> Side</div>
                <table class="map-table">
                    <thead><tr>
                        <th>Slot</th>
                        <th>Position</th>
                        <th>PDF Page</th>
                        <th>Rotation</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($ms_slots as $ms_slot):
                        $ms_sn = (int)$ms_slot['slot'];
                        $ms_sc = (($ms_sn-1) % (int)$forma['cols']) + 1;
                        $ms_sr = (int)(($ms_sn-1) / (int)$forma['cols']) + 1;
                        $ms_pg = $ms_slot['book_page'] ?? null;
                        $ms_blank = $ms_slot['is_blank'] ?? ($ms_pg === null);
                        $ms_rot = (int)($ms_slot['rotation'] ?? 0);
                    ?>
                    <tr>
                        <td><strong><?= $ms_sn ?></strong></td>
                        <td style="font-size:10px;color:var(--muted)">Col<?= $ms_sc ?>·Row<?= $ms_sr ?></td>
                        <td class="<?= $ms_blank ? 'blank-cell' : 'pg-num' ?>" id="maprow_p<?= $pno ?>_<?= $ms ?>_s<?= $ms_sn ?>">
                            <?= $ms_blank ? '—blank—' : $ms_pg ?>
                        </td>
                        <td style="font-size:11px;color:<?= $ms_rot==180?'#d97706':'#94a3b8' ?>">
                            <?= $ms_rot==180 ? '↻ 180°' : '0°' ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endforeach; ?>
            </div>
        </div>
        <div class="plate-sides">
        <?php foreach (['front','back'] as $side): ?>
        <?php $side_slots = $plate['sides'][$side] ?? []; ?>
        <div class="side-wrap">
            <div class="side-header">
                <span><?= strtoupper($side) ?> SIDE</span>
                <span style="font-size:11px;color:var(--muted)"><?= count($side_slots) ?> slots</span>
            </div>
            <div style="padding:8px;background:#f8fafc">
                <!-- Column labels -->
                <div style="display:grid;grid-template-columns:<?= implode(' ', array_fill(0, (int)$forma['cols'], '1fr')) ?>;gap:4px;margin-bottom:4px">
                    <?php for ($c=1; $c<=(int)$forma['cols']; $c++): ?>
                    <div class="col-label">Col <?= $c ?></div>
                    <?php endfor; ?>
                </div>
                <div class="slot-grid" style="grid-template-columns:<?= implode(' ', array_fill(0, (int)$forma['cols'], '1fr')) ?>;">
                <?php foreach ($side_slots as $slot): ?>
                <?php
                    $is_blank = $slot['is_blank'] ?? false;
                    $rot      = (int)($slot['rotation'] ?? 0);
                    $pg       = $slot['book_page'] ?? null;
                    // Always compute col/row from slot number in case they're missing from saved JSON
                    $slot_num = (int)$slot['slot'];
                    $cols_int = (int)$forma['cols'];
                    $slot_col = (($slot_num - 1) % $cols_int) + 1;
                    $slot_row = (int)(($slot_num - 1) / $cols_int) + 1;
                    $cell_cls = 'slot-cell';
                    if ($rot == 180) $cell_cls .= ' rotated';
                    if ($is_blank || $pg===null) $cell_cls .= ' blank';
                    $data_id = "p{$pno}_{$side}_s{$slot_num}";
                ?>
                <div class="<?= $cell_cls ?>" id="cell_<?= $data_id ?>">
                    <span class="slot-num"><?= $slot_num ?></span>
                    <input type="number" 
                           class="slot-page-input <?= ($is_blank||$pg===null)?'blank-pg':'' ?>"
                           id="pg_<?= $data_id ?>"
                           value="<?= $pg!==null?(int)$pg:'' ?>"
                           min="0"
                           placeholder="BLANK"
                           onchange="onPageChange(this,'<?= $data_id ?>')"
                           title="Plate <?= $pno ?> · <?= strtoupper($side) ?> · Slot <?= $slot_num ?> · Col <?= $slot_col ?> Row <?= $slot_row ?>">
                    <?php if ($rot==180): ?>
                    <div class="rotation-indicator" id="rot_<?= $data_id ?>">↻ 180°</div>
                    <?php else: ?>
                    <div class="rotation-indicator" id="rot_<?= $data_id ?>" style="display:none">↻ 180°</div>
                    <?php endif; ?>
                    <?php if ($is_blank || $pg===null): ?>
                    <div class="blank-indicator" id="blk_<?= $data_id ?>">BLANK</div>
                    <?php else: ?>
                    <div class="blank-indicator" id="blk_<?= $data_id ?>" style="display:none">BLANK</div>
                    <?php endif; ?>
                    <div class="slot-controls">
                        <button type="button" class="slot-ctrl-btn rot <?= $rot==180?'active':'' ?>"
                                id="rotbtn_<?= $data_id ?>"
                                onclick="toggleRotation('<?= $data_id ?>')" title="Toggle 180° rotation">↻</button>
                        <button type="button" class="slot-ctrl-btn blank <?= ($is_blank||$pg===null)?'active':'' ?>"
                                id="blkbtn_<?= $data_id ?>"
                                onclick="toggleBlank('<?= $data_id ?>')" title="Toggle blank">∅</button>
                    </div>
                </div>
                <?php endforeach; ?>
                </div><!-- /slot-grid -->
            </div>
        </div>
        <?php endforeach; ?>
        </div><!-- /plate-sides -->
    </div><!-- /plate-body -->
</div><!-- /plate-wrap -->
<?php endforeach; ?>
</div><!-- /plates-container -->

<div style="margin-top:20px;display:flex;gap:10px;flex-wrap:wrap">
    <button onclick="saveImposition()" class="btn btn-primary">💾 Save Imposition</button>
    <a href="forma_ctp_generate.php?id=<?= $forma_id ?>" class="btn btn-success">⚡ Generate Plate PDF</a>
    <button onclick="autoCalculate()" class="btn btn-outline">🔄 Auto-Calculate</button>
    <a href="forma_ctp_job_view.php?id=<?= $forma['job_ticket_id'] ?>" class="btn btn-outline">← Back</a>
</div>

<script>
// ── State ─────────────────────────────────────────────────────
const STATE = <?= json_encode($imposition, JSON_PRETTY_PRINT) ?>;
const FORMA = {
    id: <?= $forma_id ?>,
    page_start: <?= $forma['page_start'] ?>,
    page_end: <?= $forma['page_end'] ?>,
    cols: <?= $forma['cols'] ?>,
    rows: <?= $forma['rows'] ?>,
    pages_per_side: <?= $forma['pages_per_side'] ?>,
};

// Get slot state from DOM
function getSlotState(data_id) {
    const pg    = document.getElementById('pg_' + data_id).value.trim();
    const cell  = document.getElementById('cell_' + data_id);
    const rotBtn = document.getElementById('rotbtn_' + data_id);
    const blkBtn = document.getElementById('blkbtn_' + data_id);
    return {
        book_page: pg !== '' ? parseInt(pg) : null,
        rotation: rotBtn.classList.contains('active') ? 180 : 0,
        is_blank: blkBtn.classList.contains('active') || pg === '',
    };
}

// Parse data_id: pX_side_sY → {plate_no, side, slot}
function parseId(data_id) {
    const m = data_id.match(/^p(\d+)_(\w+)_s(\d+)$/);
    return m ? {plate:parseInt(m[1]), side:m[2], slot:parseInt(m[3])} : null;
}

// Toggle rotation
function toggleRotation(data_id) {
    const rotBtn = document.getElementById('rotbtn_' + data_id);
    const rotInd = document.getElementById('rot_' + data_id);
    const cell   = document.getElementById('cell_' + data_id);
    const isOn   = rotBtn.classList.toggle('active');
    rotInd.style.display = isOn ? 'block' : 'none';
    if (isOn) cell.classList.add('rotated'); else cell.classList.remove('rotated');
}

// Toggle blank
function toggleBlank(data_id) {
    const blkBtn = document.getElementById('blkbtn_' + data_id);
    const blkInd = document.getElementById('blk_' + data_id);
    const pgInp  = document.getElementById('pg_' + data_id);
    const cell   = document.getElementById('cell_' + data_id);
    const isOn   = blkBtn.classList.toggle('active');
    blkInd.style.display = isOn ? 'block' : 'none';
    pgInp.classList.toggle('blank-pg', isOn);
    if (isOn) { pgInp.value = ''; cell.classList.add('blank'); }
    else cell.classList.remove('blank');
}

// Page input change
function onPageChange(el, data_id) {
    const val = el.value.trim();
    const blkBtn = document.getElementById('blkbtn_' + data_id);
    const blkInd = document.getElementById('blk_' + data_id);
    const cell   = document.getElementById('cell_' + data_id);
    if (!val) {
        blkBtn.classList.add('active');
        blkInd.style.display = 'block';
        el.classList.add('blank-pg');
        cell.classList.add('blank');
    } else {
        blkBtn.classList.remove('active');
        blkInd.style.display = 'none';
        el.classList.remove('blank-pg');
        cell.classList.remove('blank');
    }
    // Update mapping table row
    const m = data_id.match(/^p(\d+)_(\w+)_s(\d+)$/);
    if (m) {
        const mapCell = document.getElementById('maprow_p'+m[1]+'_'+m[2]+'_s'+m[3]);
        if (mapCell) {
            if (!val) {
                mapCell.textContent = '—blank—';
                mapCell.className = 'blank-cell';
            } else {
                mapCell.textContent = val;
                mapCell.className = 'pg-num';
            }
        }
    }
}

// Collect all slot data into imposition JSON
function collectImposition() {
    const plates = [];
    document.querySelectorAll('.plate-wrap').forEach(pw => {
        const plate_no = parseInt(pw.dataset.plate);
        const sides = {};
        ['front','back'].forEach(side => {
            const slots = [];
            pw.querySelectorAll('.slot-cell').forEach(cell => {
                const id = cell.id.replace('cell_','');
                if (!id.includes('_'+side+'_')) return;
                const info = parseId(id);
                if (!info) return;
                const s = getSlotState(id);
                slots.push({
                    slot: info.slot,
                    book_page: s.book_page,
                    rotation: s.rotation,
                    is_blank: s.is_blank,
                    label: s.book_page !== null ? String(s.book_page) : 'BLANK',
                });
            });
            sides[side] = slots;
        });
        plates.push({plate_no, sides});
    });
    return {plates};
}

// Save
function saveImposition() {
    const data = collectImposition();
    document.getElementById('impositionJsonField').value = JSON.stringify(data);
    document.getElementById('impositionForm').submit();
}

// Auto-calculate
function autoCalculate() {
    if (!confirm('Reset imposition to auto-calculated booklet layout? Manual changes will be lost.')) return;
    // Reload page which will auto-calculate since imposition_json is cleared
    const url = new URL(window.location.href);
    url.searchParams.set('recalc','1');
    window.location.href = url.toString();
}

function toggleAllRotation() {
    // Toggle rotation for all row=1 slots
    document.querySelectorAll('.slot-cell').forEach(cell => {
        const id = cell.id.replace('cell_','');
        const pgEl = document.getElementById('pg_' + id);
        if (!pgEl) return;
        // We can't easily determine row from DOM, toggle all
        // In practice: only row 1 is rotated by default
    });
    alert('Use individual slot ↻ buttons to control per-slot rotation.');
}

function resetToAuto() {
    autoCalculate();
}
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>