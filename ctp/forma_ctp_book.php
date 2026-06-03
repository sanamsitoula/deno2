<?php
/**
 * forma_ctp_book.php — Manage Books (upload master PDF, book registry)
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
redirect_if_not_logged_in();

$action  = $_GET['action'] ?? 'list';
$book_id = (int)($_GET['id'] ?? 0);
$msg = $error = '';

// ── Handle POST: Create / Edit book ──────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_action = $_POST['post_action'] ?? '';

    if ($post_action === 'save_book') {
        $book_code  = strtoupper(trim($_POST['book_code'] ?? ''));
        $book_name  = trim($_POST['book_name'] ?? '');
        $class      = trim($_POST['class'] ?? '');
        $subject    = trim($_POST['subject'] ?? '');
        $tot_pages  = (int)($_POST['total_pages'] ?? 0);
        $notes      = trim($_POST['notes'] ?? '');
        $edit_id    = (int)($_POST['edit_id'] ?? 0);

        if (!$book_code || !$book_name) {
            $error = 'Book Code and Book Name are required.';
        } else {
            // Handle master PDF upload
            $pdf_path = $_POST['existing_pdf_path'] ?? '';
            $pdf_name = $_POST['existing_pdf_name'] ?? '';
            $pdf_pages = (int)($_POST['existing_pdf_pages'] ?? 0);

            if (!empty($_FILES['master_pdf']['name'])) {
                $file = $_FILES['master_pdf'];
                if ($file['error'] === UPLOAD_ERR_OK && strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) === 'pdf') {
                    $dir = $_SERVER['DOCUMENT_ROOT'] . "/deno2/uploads/forma_pdfs/{$book_code}/";
                    if (!is_dir($dir)) mkdir($dir, 0755, true);
                    $safe = date('Ymd_His') . '_master_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $file['name']);
                    $dest = $dir . $safe;
                    if (move_uploaded_file($file['tmp_name'], $dest)) {
                        $pdf_path  = "/deno2/uploads/forma_pdfs/{$book_code}/" . $safe;
                        $pdf_name  = $file['name'];
                        // Count pages
                        $content = file_get_contents($dest, false, null, 0, 512*1024);
                        if (preg_match_all('/\/Count\s+(\d+)/', $content, $m)) $pdf_pages = (int)max($m[1]);
                        if (!$pdf_pages) {
                            $full = file_get_contents($dest);
                            $pdf_pages = preg_match_all('/\/Type\s*\/Page[^s]/', $full);
                        }
                    }
                }
            }

            try {
                if ($edit_id) {
                    $stmt = $conn->prepare("UPDATE fctp_books SET book_code=:bc,book_name=:bn,class=:cl,subject=:sub,total_pages=:tp,notes=:notes,master_pdf_path=COALESCE(NULLIF(:pp,''), master_pdf_path),master_pdf_name=COALESCE(NULLIF(:pn,''), master_pdf_name),master_pdf_pages=CASE WHEN :pp2<>'' THEN :ppp ELSE master_pdf_pages END,updated_at=NOW() WHERE id=:id");
                    $stmt->execute([':bc'=>$book_code,':bn'=>$book_name,':cl'=>$class,':sub'=>$subject,':tp'=>$tot_pages,':notes'=>$notes,':pp'=>$pdf_path,':pp2'=>$pdf_path,':pn'=>$pdf_name,':ppp'=>$pdf_pages,':id'=>$edit_id]);
                    $msg = 'Book updated successfully.';
                } else {
                    $stmt = $conn->prepare("INSERT INTO fctp_books (book_code,book_name,class,subject,total_pages,notes,master_pdf_path,master_pdf_name,master_pdf_pages,created_by) VALUES(:bc,:bn,:cl,:sub,:tp,:notes,:pp,:pn,:ppp,:by)");
                    $stmt->execute([':bc'=>$book_code,':bn'=>$book_name,':cl'=>$class,':sub'=>$subject,':tp'=>$tot_pages,':notes'=>$notes,':pp'=>$pdf_path,':pn'=>$pdf_name,':ppp'=>$pdf_pages,':by'=>$_SESSION['username']??'system']);
                    $msg = 'Book created successfully.';
                }
            } catch (Exception $e) {
                $error = 'Error: ' . $e->getMessage();
            }
        }
        $action = 'list';
    }

    if ($post_action === 'delete_book') {
        $del_id = (int)($_POST['del_id'] ?? 0);
        if ($del_id) {
            $conn->prepare("DELETE FROM fctp_books WHERE id=:id")->execute([':id'=>$del_id]);
            $msg = 'Book deleted.';
        }
        $action = 'list';
    }
}

// ── Load book for edit ────────────────────────────────────────
$edit_book = null;
if ($action === 'edit' && $book_id) {
    $edit_book = $conn->prepare("SELECT * FROM fctp_books WHERE id=:id");
    $edit_book->execute([':id'=>$book_id]);
    $edit_book = $edit_book->fetch(PDO::FETCH_ASSOC);
}

// ── Fetch books list ──────────────────────────────────────────
$books = $conn->query("
    SELECT b.*, COUNT(DISTINCT jt.id) AS job_count
    FROM fctp_books b
    LEFT JOIN fctp_job_tickets jt ON jt.book_code = b.book_code
    GROUP BY b.id
    ORDER BY b.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

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
.page-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;flex-wrap:wrap;gap:10px;}
.page-title{font-size:22px;font-weight:800;color:var(--dark);}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 18px;border-radius:7px;font-size:13px;font-weight:600;text-decoration:none;border:none;cursor:pointer;transition:.15s;}
.btn-primary{background:var(--primary);color:#fff;}
.btn-primary:hover{background:#1557b0;}
.btn-sm{padding:5px 12px;font-size:12px;}
.btn-danger{background:var(--danger);color:#fff;}
.btn-outline{background:#fff;color:var(--primary);border:1.5px solid var(--primary);}
.btn-outline:hover{background:var(--primary);color:#fff;}
.card{background:#fff;border-radius:12px;box-shadow:0 2px 12px rgba(0,0,0,.07);overflow:hidden;margin-bottom:24px;}
.card-header{padding:16px 20px;border-bottom:1px solid var(--border);font-weight:700;font-size:15px;display:flex;align-items:center;gap:8px;}
.card-body{padding:20px;}
table{width:100%;border-collapse:collapse;font-size:13px;}
th{background:#f1f5f9;padding:10px 14px;text-align:left;font-weight:700;color:var(--dark);font-size:12px;text-transform:uppercase;letter-spacing:.5px;border-bottom:2px solid var(--border);}
td{padding:10px 14px;border-bottom:1px solid #f1f5f9;vertical-align:middle;}
tr:hover td{background:#fafbff;}
.form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;}
.form-group{display:flex;flex-direction:column;gap:5px;}
.form-group label{font-size:12px;font-weight:700;color:var(--dark);}
.form-group input,.form-group select,.form-group textarea{padding:9px 12px;border:1.5px solid var(--border);border-radius:7px;font-size:13px;outline:none;transition:.15s;}
.form-group input:focus,.form-group select:focus,.form-group textarea:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(26,115,232,.1);}
.form-group textarea{resize:vertical;min-height:80px;}
.form-full{grid-column:1/-1;}
.form-actions{display:flex;gap:10px;margin-top:20px;padding-top:16px;border-top:1px solid var(--border);}
.alert{padding:12px 18px;border-radius:8px;font-size:13px;margin-bottom:16px;font-weight:500;}
.alert-success{background:#dcfce7;color:#16a34a;border:1px solid #bbf7d0;}
.alert-error{background:#fee2e2;color:var(--danger);border:1px solid #fecaca;}
.pdf-badge{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;background:#eff6ff;color:var(--primary);border-radius:12px;font-size:11px;font-weight:600;}
.upload-zone{border:2px dashed var(--border);border-radius:8px;padding:20px;text-align:center;cursor:pointer;transition:.15s;background:#fafbff;}
.upload-zone:hover{border-color:var(--primary);background:#eff6ff;}
.upload-zone input[type=file]{display:none;}
.upload-zone label{cursor:pointer;font-size:13px;color:var(--muted);}
.action-btns{display:flex;gap:6px;}
</style>

<div class="fctp-wrap">
<div class="fctp-nav">
    <span class="fctp-nav-title">🖨️ Forma CTP</span>
    <a href="forma_ctp.php" class="nav-btn">📋 Job Tickets</a>
    <a href="forma_ctp_book.php" class="nav-btn active">📚 Books</a>
    <a href="forma_ctp_job_create.php" class="nav-btn">➕ New Job Ticket</a>
    <a href="forma_ctp_templates.php" class="nav-btn">⚙️ Templates</a>
</div>

<?php if ($msg): ?><div class="alert alert-success">✅ <?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error">❌ <?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="page-header">
    <div><div class="page-title">📚 Books Registry</div></div>
    <a href="forma_ctp_book.php?action=create" class="btn btn-primary">➕ Add Book</a>
</div>

<!-- CREATE / EDIT FORM -->
<?php if ($action === 'create' || $action === 'edit'): ?>
<div class="card">
    <div class="card-header">
        <?= $action==='edit' ? '✏️ Edit Book' : '➕ Add New Book' ?>
    </div>
    <div class="card-body">
    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="post_action" value="save_book">
        <input type="hidden" name="edit_id" value="<?= $edit_book['id'] ?? 0 ?>">
        <?php if ($edit_book): ?>
        <input type="hidden" name="existing_pdf_path" value="<?= htmlspecialchars($edit_book['master_pdf_path']??'') ?>">
        <input type="hidden" name="existing_pdf_name" value="<?= htmlspecialchars($edit_book['master_pdf_name']??'') ?>">
        <input type="hidden" name="existing_pdf_pages" value="<?= $edit_book['master_pdf_pages']??0 ?>">
        <?php endif; ?>
        <div class="form-grid">
            <div class="form-group">
                <label>Book Code *</label>
                <input type="text" name="book_code" value="<?= htmlspecialchars($edit_book['book_code']??'') ?>" placeholder="e.g. MATH5-NT" required style="text-transform:uppercase">
            </div>
            <div class="form-group">
                <label>Book Name *</label>
                <input type="text" name="book_name" value="<?= htmlspecialchars($edit_book['book_name']??'') ?>" placeholder="e.g. Mathematics" required>
            </div>
            <div class="form-group">
                <label>Class</label>
                <select name="class">
                    <option value="">— Select —</option>
                    <?php foreach(range(1,12) as $c): ?>
                    <option value="<?=$c?>" <?=($edit_book['class']??'')==$c?'selected':''?>><?=$c?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Subject</label>
                <input type="text" name="subject" value="<?= htmlspecialchars($edit_book['subject']??'') ?>" placeholder="e.g. Nepali, English, Math">
            </div>
            <div class="form-group">
                <label>Total Book Pages</label>
                <input type="number" name="total_pages" value="<?= $edit_book['total_pages']??0 ?>" min="0">
            </div>
            <div class="form-group form-full">
                <label>Master PDF (Full Book PDF)</label>
                <?php if (!empty($edit_book['master_pdf_path'])): ?>
                <div style="margin-bottom:8px">
                    <span class="pdf-badge">📄 Current: <?= htmlspecialchars($edit_book['master_pdf_name']??'') ?> (<?= $edit_book['master_pdf_pages'] ?> pages)</span>
                    <span style="font-size:11px;color:var(--muted);margin-left:8px">Upload new to replace</span>
                </div>
                <?php endif; ?>
                <div class="upload-zone" id="pdfDrop">
                    <input type="file" name="master_pdf" id="masterPdf" accept=".pdf" onchange="document.getElementById('pdfLabel').textContent=this.files[0]?.name||'Click or drag PDF here'">
                    <label for="masterPdf" id="pdfLabel">📄 Click or drag the full book PDF here (max 500MB)</label>
                </div>
            </div>
            <div class="form-group form-full">
                <label>Notes</label>
                <textarea name="notes"><?= htmlspecialchars($edit_book['notes']??'') ?></textarea>
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">💾 Save Book</button>
            <a href="forma_ctp_book.php" class="btn btn-outline">Cancel</a>
        </div>
    </form>
    </div>
</div>
<?php endif; ?>

<!-- BOOKS TABLE -->
<div class="card">
<div class="card-header">📚 All Books (<?= count($books) ?>)</div>
<table>
<thead>
<tr>
    <th>Book Code</th>
    <th>Book Name</th>
    <th>Class</th>
    <th>Subject</th>
    <th>Total Pages</th>
    <th>Master PDF</th>
    <th>Jobs</th>
    <th>Actions</th>
</tr>
</thead>
<tbody>
<?php if (empty($books)): ?>
<tr><td colspan="8" style="text-align:center;padding:40px;color:var(--muted)">No books yet. Add your first book.</td></tr>
<?php else: foreach ($books as $b): ?>
<tr>
    <td><strong><?= htmlspecialchars($b['book_code']) ?></strong></td>
    <td><?= htmlspecialchars($b['book_name']) ?></td>
    <td><?= $b['class'] ?: '—' ?></td>
    <td><?= htmlspecialchars($b['subject'] ?: '—') ?></td>
    <td><?= number_format($b['total_pages']) ?></td>
    <td>
        <?php if ($b['master_pdf_path']): ?>
        <span class="pdf-badge">📄 <?= $b['master_pdf_pages'] ?> pages</span>
        <div style="font-size:10px;color:var(--muted);margin-top:2px"><?= htmlspecialchars($b['master_pdf_name']??'') ?></div>
        <?php else: ?>
        <span style="color:#dc2626;font-size:12px">❌ No PDF</span>
        <?php endif; ?>
    </td>
    <td><strong><?= $b['job_count'] ?></strong></td>
    <td>
        <div class="action-btns">
            <a href="forma_ctp_book.php?action=edit&id=<?= $b['id'] ?>" class="btn btn-sm btn-outline">✏️ Edit</a>
            <form method="POST" style="display:inline" onsubmit="return confirm('Delete book <?= htmlspecialchars($b['book_code']) ?>? This will remove all associated jobs and formas.')">
                <input type="hidden" name="post_action" value="delete_book">
                <input type="hidden" name="del_id" value="<?= $b['id'] ?>">
                <button type="submit" class="btn btn-sm btn-danger">🗑</button>
            </form>
        </div>
    </td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
</div>

</div>
<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>
