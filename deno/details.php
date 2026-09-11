<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    header('Location: index.php?error=No+record+ID+provided');
    exit();
}

$stmt = $conn->prepare("
    SELECT d.*,
           b.book_name, b.class_level, b.is_translated,
           fy.fiscal_name,
           jt.job_ticket_code, jt.lot AS jt_lot, jt.print_qty AS jt_print_qty,
           bp.name AS bp_name, bp.book_code AS bp_book_code, bp.p_qty AS bp_p_qty,
           bp.packing_no, bp.date_nep AS bp_date_nep,
           u1.username AS created_by_username,
           u2.username AS received_by_username,
           u3.username AS verified_by_username,
           u4.username AS sender_by_username,
           u5.username AS updated_by_username,
           (
               SELECT string_agg(DISTINCT dm.d2m_no, ', ' ORDER BY dm.d2m_no)
               FROM d2m_items di
               JOIN d2m dm ON di.d2m_id = dm.id
               WHERE di.associated_deno_ids LIKE '%' || d.id || '%'
                 AND dm.deleted_at IS NULL
           ) AS d2m_numbers
    FROM deno d
    LEFT JOIN books b         ON d.book_code = b.book_code
    LEFT JOIN fiscal_years fy ON d.fiscal_year_id = fy.id
    LEFT JOIN job_ticket jt   ON d.jt_id = jt.id
    LEFT JOIN book_packing bp ON d.bp_id = bp.id
    LEFT JOIN users u1 ON d.created_by  = u1.id
    LEFT JOIN users u2 ON d.received_by = u2.id
    LEFT JOIN users u3 ON d.verify_by   = u3.id
    LEFT JOIN users u4 ON d.sender_by   = u4.id
    LEFT JOIN users u5 ON d.updated_by  = u5.id
    WHERE d.id = :id AND d.deleted_at IS NULL
");
$stmt->execute([':id' => $id]);
$record = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$record) {
    header('Location: index.php?error=Record+not+found');
    exit();
}

$entry_type_val = $record['entry_type'] ?? 'direct';

require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';
?>

<style>
body { font-size:16px; font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif; }

.details-container {
    max-width: 960px;
    margin: 0 auto;
    background: #fff;
    padding: 30px;
    border-radius: 10px;
    box-shadow: 0 4px 15px rgba(0,0,0,.1);
}

.breadcrumb { background:none; padding:0; margin-bottom:16px; font-size:14px; }
.breadcrumb a { color:#007bff; text-decoration:none; }
.breadcrumb a:hover { text-decoration:underline; }

.page-header {
    display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;
    margin-bottom: 24px;
    padding-bottom: 18px;
    border-bottom: 3px solid #007bff;
}
.page-header h2 { color:#333; margin:0; font-size:26px; }

.badge { display:inline-block; padding:5px 12px; border-radius:4px; font-size:13px; font-weight:700; }
.badge-direct  { background:#cce5ff; color:#004085; }
.badge-from_jt { background:#d4edda; color:#155724; }
.badge-from_bp { background:#fff3cd; color:#856404; }

.info-section {
    margin-bottom: 28px;
    padding: 22px;
    background: #f8f9fa;
    border-radius: 8px;
    border-left: 4px solid #667eea;
}
.section-title { font-size:16px; font-weight:700; color:#333; margin-bottom:16px; }

.info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 16px;
}
.info-item { display:flex; flex-direction:column; gap:4px; }
.info-label { font-size:12px; font-weight:700; color:#6c757d; text-transform:uppercase; letter-spacing:.5px; }
.info-value { font-size:15px; color:#333; font-weight:500; }
.info-value.large { font-size:20px; font-weight:700; color:#007bff; }

.d2m-badge { display:inline-block; background:#17a2b8; color:#fff; padding:3px 8px; border-radius:3px; font-size:12px; margin:2px; }
.no-d2m { color:#6c757d; font-style:italic; }

.btn { padding:11px 24px; border:none; border-radius:5px; cursor:pointer; font-size:14px; font-weight:600; text-decoration:none; display:inline-block; text-align:center; margin-right:8px; }
.btn-primary   { background:#007bff; color:#fff; }
.btn-secondary { background:#6c757d; color:#fff; }
.btn-warning   { background:#ffc107; color:#212529; }

.button-group { text-align:center; margin-top:24px; padding-top:18px; border-top:1px solid #dee2e6; }

@media (max-width:768px) {
    .details-container { margin:10px; padding:18px; }
}
</style>

<div class="details-container">

    <div class="breadcrumb">
        <a href="index.php">📋 Deno Entries</a> /
        <span>Record #<?= $record['id'] ?></span>
    </div>

    <div class="page-header">
        <h2>📄 Deno Record #<?= $record['id'] ?></h2>
        <span class="badge badge-<?= htmlspecialchars($entry_type_val) ?>">
            <?= ucfirst(str_replace('_', ' ', $entry_type_val)) ?>
        </span>
    </div>

    <div class="info-section">
        <div class="section-title">📋 Basic Information</div>
        <div class="info-grid">
            <div class="info-item">
                <div class="info-label">Deno No</div>
                <div class="info-value large"><?= htmlspecialchars($record['deno_no'] ?? '-') ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Reference No</div>
                <div class="info-value"><?= htmlspecialchars($record['ref_no']) ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Fiscal Year</div>
                <div class="info-value"><?= htmlspecialchars($record['fiscal_name'] ?? '-') ?></div>
            </div>
        </div>
    </div>

    <div class="info-section">
        <div class="section-title">📚 Book Information</div>
        <div class="info-grid">
            <div class="info-item">
                <div class="info-label">Book Code</div>
                <div class="info-value"><?= htmlspecialchars($record['book_code'] ?? '-') ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Book Name</div>
                <div class="info-value"><?= htmlspecialchars($record['book_name'] ?? '-') ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Class Level</div>
                <div class="info-value"><?= htmlspecialchars($record['class_level'] ?? '-') ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Translated</div>
                <div class="info-value"><?= $record['is_translated'] ? 'Yes' : 'No' ?></div>
            </div>
        </div>
    </div>

    <?php if (!empty($record['bp_id'])): ?>
    <div class="info-section">
        <div class="section-title">📦 Book Packing Source</div>
        <div class="info-grid">
            <div class="info-item">
                <div class="info-label">Packing Name</div>
                <div class="info-value"><?= htmlspecialchars($record['bp_name'] ?? '-') ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Packing No</div>
                <div class="info-value"><?= htmlspecialchars($record['packing_no'] ?? '-') ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Book Code (from Packing)</div>
                <div class="info-value"><?= htmlspecialchars($record['bp_book_code'] ?? '-') ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Packed Qty</div>
                <div class="info-value"><?= number_format((int)($record['bp_p_qty'] ?? 0)) ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Packing Date (Nepali)</div>
                <div class="info-value"><?= htmlspecialchars($record['bp_date_nep'] ?? '-') ?></div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($record['jt_id'])): ?>
    <div class="info-section">
        <div class="section-title">🎫 Job Ticket</div>
        <div class="info-grid">
            <div class="info-item">
                <div class="info-label">Job Ticket Code</div>
                <div class="info-value"><?= htmlspecialchars($record['job_ticket_code'] ?? '-') ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Lot</div>
                <div class="info-value"><?= htmlspecialchars($record['jt_lot'] ?? '-') ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Print Qty</div>
                <div class="info-value"><?= number_format((int)($record['jt_print_qty'] ?? 0)) ?></div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="info-section">
        <div class="section-title">📊 Quantities</div>
        <div class="info-grid">
            <div class="info-item">
                <div class="info-label">Qty per Poka</div>
                <div class="info-value"><?= number_format((int)$record['per_poka_qty']) ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">No. of Pokas</div>
                <div class="info-value"><?= number_format((int)$record['poka_qty']) ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Open Pieces</div>
                <div class="info-value"><?= number_format((int)$record['quantity_openpcs']) ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Total Qty</div>
                <div class="info-value large"><?= number_format((int)$record['total_qty']) ?></div>
            </div>
        </div>
    </div>

    <div class="info-section">
        <div class="section-title">📅 Dates</div>
        <div class="info-grid">
            <div class="info-item">
                <div class="info-label">Nepali Date</div>
                <div class="info-value"><?= htmlspecialchars($record['deno_date_nep']) ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">English Date</div>
                <div class="info-value"><?= htmlspecialchars($record['deno_date_eng'] ?? '-') ?></div>
            </div>
        </div>
    </div>

    <div class="info-section">
        <div class="section-title">👥 Personnel</div>
        <div class="info-grid">
            <div class="info-item">
                <div class="info-label">Created By</div>
                <div class="info-value"><?= htmlspecialchars($record['created_by_username'] ?? '-') ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Sender By</div>
                <div class="info-value"><?= htmlspecialchars($record['sender_by_username'] ?? '-') ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Received By</div>
                <div class="info-value"><?= htmlspecialchars($record['received_by_username'] ?? '-') ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Verified By</div>
                <div class="info-value"><?= htmlspecialchars($record['verified_by_username'] ?? '-') ?></div>
            </div>
        </div>
    </div>

    <?php if (!empty($record['notes']) || !empty($record['update_remarks'])): ?>
    <div class="info-section">
        <div class="section-title">📝 Notes &amp; Remarks</div>
        <div class="info-grid">
            <?php if (!empty($record['notes'])): ?>
            <div class="info-item">
                <div class="info-label">Notes</div>
                <div class="info-value" style="white-space:pre-wrap;"><?= htmlspecialchars($record['notes']) ?></div>
            </div>
            <?php endif; ?>
            <?php if (!empty($record['update_remarks'])): ?>
            <div class="info-item">
                <div class="info-label">Update Remarks</div>
                <div class="info-value" style="white-space:pre-wrap;"><?= htmlspecialchars($record['update_remarks']) ?></div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="info-section">
        <div class="section-title">🔗 D2M Linkage</div>
        <?php if (!empty($record['d2m_numbers'])): ?>
            <?php foreach (explode(', ', $record['d2m_numbers']) as $d2m_no): ?>
                <span class="d2m-badge"><?= htmlspecialchars($d2m_no) ?></span>
            <?php endforeach; ?>
        <?php else: ?>
            <span class="no-d2m">Not used in any D2M yet</span>
        <?php endif; ?>
    </div>

    <div class="info-section">
        <div class="section-title">🕒 Record Metadata</div>
        <div class="info-grid">
            <div class="info-item">
                <div class="info-label">Created At</div>
                <div class="info-value"><?= $record['created_at'] ? date('Y-m-d H:i', strtotime($record['created_at'])) : '-' ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Updated By</div>
                <div class="info-value"><?= htmlspecialchars($record['updated_by_username'] ?? '-') ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Updated At</div>
                <div class="info-value"><?= $record['updated_at'] ? date('Y-m-d H:i', strtotime($record['updated_at'])) : 'Never' ?></div>
            </div>
        </div>
    </div>

    <div class="button-group">
        <a href="index.php" class="btn btn-secondary">⬅ Back to List</a>
        <?php if (has_role('editor') || has_role('admin')): ?>
            <a href="edit.php?id=<?= $record['id'] ?>" class="btn btn-warning">✏️ Edit Record</a>
        <?php endif; ?>
    </div>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>
