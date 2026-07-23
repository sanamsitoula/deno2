<?php
// books/create.php
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
redirect_if_not_logged_in();

require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';

// Check permissions
if (!has_role('editor') && !has_role('admin')) {
    $_SESSION['error'] = "You don't have permission to create books.";
    header("Location: index.php");
    exit();
}

// Resolves which book_titles.id this edition belongs to — either an existing
// title picked from the dropdown, or a brand-new title created inline.
function resolveTitleId($conn, $post) {
    $mode = $post['title_mode'] ?? 'existing';

    if ($mode === 'new') {
        $title_code = strtoupper(trim($post['new_title_code'] ?? ''));
        $title_name = trim($post['new_title_name'] ?? '');
        if ($title_code === '' || $title_name === '') {
            throw new Exception("Title Code and Title Name are required when creating a new title.");
        }
        $chk = $conn->prepare("SELECT id FROM book_titles WHERE title_code = ?");
        $chk->execute([$title_code]);
        $existing = $chk->fetchColumn();
        if ($existing) return (int)$existing; // reuse if it already exists

        $stmt = $conn->prepare("
            INSERT INTO book_titles (title_code, title_name, class_level, is_translated, book_type, business_associated)
            VALUES (?, ?, ?, ?, ?, ?)
            RETURNING id
        ");
        $stmt->execute([
            $title_code,
            $title_name,
            !empty($post['class_level']) ? (int)$post['class_level'] : null,
            isset($post['is_translated']) ? 't' : 'f',
            $post['book_type'] ?? 'TextBook',
            $post['business_associated'] ?? 'CDC',
        ]);
        return (int)$stmt->fetchColumn();
    }

    // mode === 'existing' — may be blank (edition not linked to any title yet)
    return !empty($post['title_id']) ? (int)$post['title_id'] : null;
}

$message   = '';
$edit_book = null;

// ── Edit mode ──────────────────────────────────────────────────────────────────
if (isset($_GET['id'])) {
    $stmt = $conn->prepare("SELECT * FROM books WHERE book_id = ?");
    $stmt->execute([$_GET['id']]);
    $edit_book = $stmt->fetch();
    if (!$edit_book) {
        $_SESSION['error'] = "Book not found.";
        header("Location: index.php");
        exit();
    }
}

// ── Handle delete (from edit page) ────────────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'delete' && has_role('admin')) {
    $del_id = (int)($_POST['id'] ?? 0);
    try {
        $conn->beginTransaction();
        $chk = $conn->prepare("SELECT COUNT(*) as c FROM job_ticket WHERE book_id = ?");
        $chk->execute([$del_id]);
        $used = $chk->fetch()['c'];
        if ($used > 0) throw new Exception("Cannot delete: used in $used job ticket(s).");
        $conn->prepare("DELETE FROM books WHERE book_id = ?")->execute([$del_id]);
        $conn->commit();
        $_SESSION['success'] = 'Book deleted successfully.';
        header("Location: index.php");
        exit();
    } catch (Exception $e) {
        $conn->rollBack();
        $message = "<div class='alert alert-danger'><i class='fas fa-exclamation-triangle me-2'></i>" . htmlspecialchars($e->getMessage()) . "</div>";
    }
}

// ── Handle create / update ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['create','update'])) {
    $action = $_POST['action'];
    try {
        $conn->beginTransaction();

        if ($action === 'create') {
            $chk = $conn->prepare("SELECT COUNT(*) FROM books WHERE book_code = ?");
            $chk->execute([$_POST['book_code']]);
            if ($chk->fetchColumn() > 0) throw new Exception("Book code already exists. Please use a unique book code.");

            $usr = $conn->prepare("SELECT username FROM users WHERE id = ?");
            $usr->execute([$_SESSION['user_id']]);
            $username = $usr->fetchColumn();
            // Legacy created_by is varchar(5) — too short for most real usernames
            // (was throwing a hard truncation error and failing the whole insert).
            // created_by_id (integer, FK to users) is now the reliable source of
            // truth; this truncated value is kept only so the NOT NULL legacy
            // column stays satisfied for anything still reading it.
            $username_short = substr($username ?: '', 0, 5);

            $title_id = resolveTitleId($conn, $_POST);

            $stmt = $conn->prepare("
                INSERT INTO books (book_code, book_name, class_level, fiscal_year,
                    is_translated, is_optional, business_associated, book_type, created_by,
                    created_by_id, title_id, page_count, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                strtoupper(trim($_POST['book_code'])),
                trim($_POST['book_name']),
                !empty($_POST['class_level']) ? (int)$_POST['class_level'] : null,
                !empty($_POST['fiscal_year']) ? trim($_POST['fiscal_year']) : null,
                isset($_POST['is_translated']) ? 't' : 'f',
                isset($_POST['is_optional'])   ? 't' : 'f',
                $_POST['business_associated'] ?? 'CDC',
                $_POST['book_type'] ?? 'TextBook',
                $username_short,
                $_SESSION['user_id'],
                $title_id,
                !empty($_POST['page_count']) ? (int)$_POST['page_count'] : null,
                isset($_POST['is_active']) ? 't' : 'f',
            ]);
            $conn->commit();
            $_SESSION['success'] = 'Book created successfully!';
            echo "<script>window.location.href='index.php';</script>"; exit();

        } elseif ($action === 'update') {
            $book_id = (int)$_POST['id'];
            $chk = $conn->prepare("SELECT COUNT(*) FROM books WHERE book_code = ? AND book_id != ?");
            $chk->execute([$_POST['book_code'], $book_id]);
            if ($chk->fetchColumn() > 0) throw new Exception("Book code already exists. Please use a unique book code.");

            $title_id = resolveTitleId($conn, $_POST);

            $stmt = $conn->prepare("
                UPDATE books SET
                    book_code = ?, book_name = ?, class_level = ?, fiscal_year = ?,
                    is_translated = ?, is_optional = ?, business_associated = ?,
                    book_type = ?, title_id = ?, page_count = ?, is_active = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE book_id = ?
            ");
            $stmt->execute([
                strtoupper(trim($_POST['book_code'])),
                trim($_POST['book_name']),
                !empty($_POST['class_level']) ? (int)$_POST['class_level'] : null,
                !empty($_POST['fiscal_year']) ? trim($_POST['fiscal_year']) : null,
                isset($_POST['is_translated']) ? 't' : 'f',
                isset($_POST['is_optional'])   ? 't' : 'f',
                $_POST['business_associated'] ?? 'CDC',
                $_POST['book_type'] ?? 'TextBook',
                $title_id,
                !empty($_POST['page_count']) ? (int)$_POST['page_count'] : null,
                isset($_POST['is_active']) ? 't' : 'f',
                $book_id
            ]);
            $conn->commit();
            $_SESSION['success'] = 'Book updated successfully!';
            echo "<script>window.location.href='index.php';</script>"; exit();
        }
    } catch (Exception $e) {
        $conn->rollBack();
        $message = "<div class='alert alert-danger'><i class='fas fa-exclamation-triangle me-2'></i>Error: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
}

// ── Dropdown data ──────────────────────────────────────────────────────────────
// Active fiscal years from fiscal_years table (falls back to books table if no fiscal_years table exists)
try {
    $fy_stmt = $conn->query("SELECT fiscal_year_name AS fiscal_year FROM fiscal_years WHERE is_active = TRUE ORDER BY fiscal_year_name DESC");
    $fiscal_years = $fy_stmt->fetchAll();
} catch (Exception $e) {
    // Fallback: pull distinct values from books table
    $fiscal_years = $conn->query("SELECT DISTINCT fiscal_year FROM books WHERE fiscal_year IS NOT NULL ORDER BY fiscal_year DESC")->fetchAll();
}

// Active fiscal year for auto-code (first/only active year)
$active_fy = !empty($fiscal_years) ? $fiscal_years[0]['fiscal_year'] : '';
// Strip the slash part for the code (e.g. "2081/82" → "2081")
$active_fy_code = $active_fy ? strtok($active_fy, '/') : '';

// Book Titles — the stable cross-year identity this edition links to.
// Only active titles are offered for new links; the edit page also shows the
// title currently linked even if it happens to be inactive (so nothing looks
// silently unset).
$book_titles = $conn->query("
    SELECT id, title_code, title_name, class_level, is_translated
    FROM book_titles
    WHERE is_active = true
    ORDER BY title_name
")->fetchAll(PDO::FETCH_ASSOC);

// If we're editing a book whose linked title has since gone inactive, still
// include it in the dropdown (as the pre-selected option) so the existing
// link is visible rather than silently disappearing.
if ($edit_book && !empty($edit_book['title_id'])) {
    $already_listed = array_filter($book_titles, function ($t) use ($edit_book) {
        return (int)$t['id'] === (int)$edit_book['title_id'];
    });
    if (empty($already_listed)) {
        $linked_stmt = $conn->prepare("SELECT id, title_code, title_name, class_level, is_translated FROM book_titles WHERE id = ?");
        $linked_stmt->execute([$edit_book['title_id']]);
        $linked_title = $linked_stmt->fetch(PDO::FETCH_ASSOC);
        if ($linked_title) {
            $book_titles[] = $linked_title;
        }
    }
}

// ── Normal page load — HTML output starts here ─────────────────────────────────
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';
?>

<div class="container py-4" style="max-width:920px">

    <!-- Page Header -->
    <div class="page-hero mb-4 p-4 rounded-3 text-white" style="background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
            <div>
                <h2 class="fw-bold mb-1">
                    <i class="fas fa-book me-2"></i><?= $edit_book ? 'Edit Book' : 'Create New Book' ?>
                </h2>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb mb-0 small" style="--bs-breadcrumb-divider-color:rgba(255,255,255,.6)">
                        <li class="breadcrumb-item"><a href="/deno2/" class="text-white-50 text-decoration-none">Home</a></li>
                        <li class="breadcrumb-item"><a href="index.php" class="text-white-50 text-decoration-none">Books</a></li>
                        <li class="breadcrumb-item active text-white"><?= $edit_book ? 'Edit' : 'Create' ?></li>
                    </ol>
                </nav>
            </div>
            <?php if ($edit_book && has_role('admin')): ?>
                <button type="button" class="btn btn-outline-light"
                        onclick="confirmDeleteFromEdit(<?= $edit_book['book_id'] ?>, '<?= htmlspecialchars(addslashes($edit_book['book_code'])) ?>')">
                    <i class="fas fa-trash-alt me-2"></i>Delete This Book
                </button>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($message): echo $message; endif; ?>

    <div class="card shadow-sm border-0">
        <div class="card-body p-4">
            <form method="POST" id="bookForm" novalidate>
                <input type="hidden" name="action" value="<?= $edit_book ? 'update' : 'create' ?>">
                <?php if ($edit_book): ?>
                    <input type="hidden" name="id" value="<?= $edit_book['book_id'] ?>">
                <?php endif; ?>

                <!-- ── Section 1: Basic Information ── -->
                <div class="form-section mb-4">
                    <h6 class="section-title">
                        <i class="fas fa-info-circle me-2 text-primary"></i>Basic Information
                    </h6>
                    <div class="row g-3">
                        <div class="col-md-5">
                            <label class="form-label fw-semibold" for="book_name">
                                Book Name <span class="text-danger">*</span>
                            </label>
                            <input type="text" class="form-control" id="book_name" name="book_name"
                                   value="<?= $edit_book ? htmlspecialchars($edit_book['book_name']) : '' ?>"
                                   placeholder="e.g., Optional Math"
                                   required autofocus>
                            <div class="invalid-feedback">Please enter a book name.</div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold" for="class_level">Class Level</label>
                            <select class="form-select" id="class_level" name="class_level">
                                <option value="">— Select —</option>
                                <?php for ($i = 1; $i <= 12; $i++): ?>
                                    <option value="<?= $i ?>" <?= ($edit_book && $edit_book['class_level'] == $i) ? 'selected' : '' ?>>
                                        Class <?= $i ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" for="fiscal_year">
                                Fiscal Year
                                <?php if ($active_fy): ?>
                                    <span class="badge bg-success ms-1 small">Active: <?= htmlspecialchars($active_fy) ?></span>
                                <?php endif; ?>
                            </label>
                            <select class="form-select" id="fiscal_year" name="fiscal_year">
                                <option value="">— Select —</option>
                                <?php foreach ($fiscal_years as $fy): ?>
                                    <option value="<?= htmlspecialchars($fy['fiscal_year']) ?>"
                                        <?= ($edit_book && $edit_book['fiscal_year'] === $fy['fiscal_year']) ? 'selected' : ($active_fy === $fy['fiscal_year'] && !$edit_book ? 'selected' : '') ?>>
                                        <?= htmlspecialchars($fy['fiscal_year']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Pulled from active fiscal years.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" for="page_count">Page Count</label>
                            <input type="number" min="0" class="form-control" id="page_count" name="page_count"
                                   value="<?= $edit_book && $edit_book['page_count'] !== null ? (int)$edit_book['page_count'] : '' ?>"
                                   placeholder="e.g., 120">
                            <div class="form-text">This edition's total pages — changes year to year with content.</div>
                        </div>
                    </div>
                </div>

                <!-- ── Section: Title (cross-year identity) ── -->
                <div class="form-section mb-4">
                    <h6 class="section-title">
                        <i class="fas fa-layer-group me-2 text-primary"></i>Title (Cross-Year Identity)
                    </h6>
                    <p class="text-muted small">
                        Every yearly edition needs its own unique Book Code above (price/pages/content
                        change each year). The <strong>Title</strong> is the stable identity that stays
                        the same across editions, so lifetime/all-year production reports can sum this
                        book's output across every year it's been printed.
                    </p>
                    <div class="row g-3">
                        <div class="col-12">
                            <div class="btn-group" role="group" aria-label="Title mode">
                                <input type="radio" class="btn-check" name="title_mode" id="titleModeExisting" value="existing"
                                       <?= (!$edit_book || $edit_book['title_id']) ? 'checked' : '' ?>>
                                <label class="btn btn-outline-primary btn-sm" for="titleModeExisting">Link to Existing Title</label>

                                <input type="radio" class="btn-check" name="title_mode" id="titleModeNew" value="new"
                                       <?= ($edit_book && !$edit_book['title_id']) ? 'checked' : '' ?>>
                                <label class="btn btn-outline-primary btn-sm" for="titleModeNew">Create New Title</label>
                            </div>
                        </div>
                        <div class="col-md-8" id="titleExistingBlock">
                            <label class="form-label fw-semibold" for="title_id">Existing Title</label>
                            <select class="form-select" id="title_id" name="title_id">
                                <option value="">— None (not linked to a title) —</option>
                                <?php foreach ($book_titles as $t): ?>
                                    <option value="<?= $t['id'] ?>" data-code="<?= htmlspecialchars($t['title_code']) ?>"
                                        <?= ($edit_book && (int)$edit_book['title_id'] === (int)$t['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($t['title_code']) ?> — <?= htmlspecialchars($t['title_name']) ?>
                                        <?= $t['class_level'] ? ' (Class ' . $t['class_level'] . ')' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Pick the title this edition is a revision of, if any.</div>
                        </div>
                        <div class="col-md-6" id="titleNewCode" style="display:none">
                            <label class="form-label fw-semibold" for="new_title_code">New Title Code</label>
                            <input type="text" class="form-control font-monospace" id="new_title_code" name="new_title_code"
                                   placeholder="e.g., MATH6-NT" style="text-transform:uppercase">
                        </div>
                        <div class="col-md-6" id="titleNewName" style="display:none">
                            <label class="form-label fw-semibold" for="new_title_name">New Title Name</label>
                            <input type="text" class="form-control" id="new_title_name" name="new_title_name"
                                   placeholder="e.g., Math Grade 6 (Non-Translated)">
                        </div>
                    </div>
                </div>

                <!-- ── Section 2: Classification ── -->
                <div class="form-section mb-4">
                    <h6 class="section-title">
                        <i class="fas fa-building me-2 text-primary"></i>Business &amp; Type
                    </h6>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="business_associated">
                                Business Associated <span class="text-danger">*</span>
                            </label>
                            <select class="form-select" id="business_associated" name="business_associated" required>
                                <option value="CDC"  <?= (!$edit_book || $edit_book['business_associated'] == 'CDC')  ? 'selected' : '' ?>>CDC – Curriculum Development Centre</option>
                                <option value="JEMC" <?= ($edit_book && $edit_book['business_associated'] == 'JEMC') ? 'selected' : '' ?>>JEMC – Janak Education Materials Centre</option>
                                <option value="NTC"  <?= ($edit_book && $edit_book['business_associated'] == 'NTC')  ? 'selected' : '' ?>>NTC – Nepal Telecom</option>
                                <option value="NEB"  <?= ($edit_book && $edit_book['business_associated'] == 'NEB')  ? 'selected' : '' ?>>NEB – National Examination Board</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="book_type">
                                Book Type <span class="text-danger">*</span>
                            </label>
                            <select class="form-select" id="book_type" name="book_type" required>
                                <option value="TextBook"      <?= (!$edit_book || $edit_book['book_type'] == 'TextBook')      ? 'selected' : '' ?>>Text Book</option>
                                <option value="Copy"          <?= ($edit_book && $edit_book['book_type'] == 'Copy')          ? 'selected' : '' ?>>Copy</option>
                                <option value="RechargeCard"  <?= ($edit_book && $edit_book['book_type'] == 'RechargeCard')  ? 'selected' : '' ?>>Recharge Card</option>
                                <option value="Lalpurja"      <?= ($edit_book && $edit_book['book_type'] == 'Lalpurja')      ? 'selected' : '' ?>>Lalpurja</option>
                                <option value="QuestionPaper" <?= ($edit_book && $edit_book['book_type'] == 'QuestionPaper') ? 'selected' : '' ?>>Question Paper</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- ── Section 3: Properties ── -->
                <div class="form-section mb-4">
                    <h6 class="section-title">
                        <i class="fas fa-sliders-h me-2 text-primary"></i>Book Properties
                    </h6>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="prop-check p-3 rounded-2 border d-flex align-items-center gap-3 <?= ($edit_book && $edit_book['is_translated']) ? 'active' : '' ?>"
                                 id="translatedCard">
                                <input class="form-check-input mt-0 flex-shrink-0" type="checkbox"
                                       id="is_translated" name="is_translated"
                                       <?= ($edit_book && $edit_book['is_translated']) ? 'checked' : '' ?>>
                                <label for="is_translated" class="mb-0 fw-semibold" style="cursor:pointer">
                                    <i class="fas fa-language me-1 text-primary"></i>
                                    Translated Book
                                    <span class="badge bg-primary ms-1" id="trBadge" style="display:<?= ($edit_book && $edit_book['is_translated']) ? 'inline' : 'none' ?>">T</span>
                                </label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="prop-check p-3 rounded-2 border d-flex align-items-center gap-3 <?= ($edit_book && $edit_book['is_optional']) ? 'active' : '' ?>"
                                 id="optionalCard">
                                <input class="form-check-input mt-0 flex-shrink-0" type="checkbox"
                                       id="is_optional" name="is_optional"
                                       <?= ($edit_book && $edit_book['is_optional']) ? 'checked' : '' ?>>
                                <label for="is_optional" class="mb-0 fw-semibold" style="cursor:pointer">
                                    <i class="fas fa-star me-1 text-warning"></i>
                                    Optional Book
                                </label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="prop-check p-3 rounded-2 border d-flex align-items-center gap-3 <?= (!$edit_book || $edit_book['is_active']) ? 'active' : '' ?>"
                                 id="activeCard">
                                <input class="form-check-input mt-0 flex-shrink-0" type="checkbox"
                                       id="is_active" name="is_active"
                                       <?= (!$edit_book || $edit_book['is_active']) ? 'checked' : '' ?>>
                                <label for="is_active" class="mb-0 fw-semibold" style="cursor:pointer">
                                    <i class="fas fa-toggle-on me-1 text-success"></i>
                                    Active Edition
                                </label>
                            </div>
                            <div class="form-text">
                                Uncheck once a newer edition supersedes this one — it stays in every
                                report, just hidden from day-to-day "pick a book" lists.
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── Section 4: Auto-generated Book Code ── -->
                <div class="form-section mb-4">
                    <h6 class="section-title">
                        <i class="fas fa-barcode me-2 text-primary"></i>Book Code
                    </h6>
                    <div class="row g-3 align-items-end">
                        <div class="col-md-8">
                            <label class="form-label fw-semibold" for="book_code">
                                Book Code <span class="text-danger">*</span>
                                <span class="text-muted fw-normal small ms-1">(auto-generated or enter manually)</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="fas fa-barcode text-muted"></i></span>
                                <input type="text" class="form-control font-monospace fw-bold" id="book_code" name="book_code"
                                       value="<?= $edit_book ? htmlspecialchars($edit_book['book_code']) : '' ?>"
                                       placeholder="e.g., OPTMTH-10-T-TB-2081"
                                       style="letter-spacing:.05em; font-size:1rem"
                                       required>
                                <button type="button" class="btn btn-outline-primary" id="btnGenCode" title="Re-generate code">
                                    <i class="fas fa-magic me-1"></i>Generate
                                </button>
                            </div>
                            <div class="form-text">
                                Format: <code>BOOKNAME-CLASS-T/NT-TYPE-YEAR</code>
                                &nbsp;&bull;&nbsp; e.g., <code>OPTMTH-10-T-TB-2081</code>
                            </div>
                            <div class="invalid-feedback">Please enter a book code.</div>
                        </div>
                        <div class="col-md-4">
                            <div class="p-3 rounded-2 border bg-light" id="codePreviewBox">
                                <div class="text-muted small mb-1">Preview</div>
                                <div class="fw-bold font-monospace text-primary" id="codePreview" style="font-size:1.05rem; letter-spacing:.05em; word-break:break-all;">
                                    <?= $edit_book ? htmlspecialchars($edit_book['book_code']) : '—' ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── Action Buttons ── -->
                <div class="d-flex gap-3 pt-3 border-top">
                    <button type="submit" class="btn btn-primary px-5">
                        <i class="fas fa-save me-2"></i><?= $edit_book ? 'Update Book' : 'Create Book' ?>
                    </button>
                    <a href="index.php" class="btn btn-outline-secondary px-4">
                        <i class="fas fa-times me-2"></i>Cancel
                    </a>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete confirmation modal (from edit page) -->
<div class="modal fade" id="deleteFromEditModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header text-white" style="background:linear-gradient(135deg,#dc3545,#b02a37)">
                <h5 class="modal-title"><i class="fas fa-trash-alt me-2"></i>Delete Book</h5>
                <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center py-4">
                <div style="font-size:3rem">⚠️</div>
                <h5 class="fw-bold mt-2">Delete this book permanently?</h5>
                <p class="fw-bold fs-5 text-danger mt-2" id="editDeleteBookCode"></p>
                <p class="text-muted small mb-0">Books linked to job tickets cannot be deleted.</p>
            </div>
            <div class="modal-footer justify-content-center border-0 pt-0">
                <button class="btn btn-secondary px-4" data-bs-dismiss="modal">Cancel</button>
                <form method="POST">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" id="editDeleteId">
                    <button type="submit" class="btn btn-danger px-4">
                        <i class="fas fa-trash-alt me-1"></i>Yes, Delete
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<style>
.section-title {
    font-size: 1rem;
    font-weight: 700;
    color: #343a40;
    padding-bottom: .6rem;
    border-bottom: 3px solid #667eea;
    margin-bottom: 1.2rem;
}
.prop-check {
    background: #fff;
    transition: background .2s, border-color .2s;
    cursor: pointer;
    border-color: #dee2e6 !important;
}
.prop-check.active {
    background: #eef2ff;
    border-color: #667eea !important;
}
.prop-check:hover { background: #f5f3ff; }
.form-control:focus, .form-select:focus {
    border-color: #667eea;
    box-shadow: 0 0 0 .2rem rgba(102,126,234,.15);
}
.font-monospace { font-family: 'Courier New', monospace; }
</style>

<script>
// ── Book-code abbreviation logic ─────────────────────────────────────────────
// Stop words to ignore in abbreviation
const STOP = ['a','an','the','of','and','in','for','to','is','its','or'];

// Map book-type values → short code
const TYPE_MAP = {
    TextBook:      'TB',
    Copy:          'CP',
    RechargeCard:  'RC',
    Lalpurja:      'LP',
    QuestionPaper: 'QP'
};

// Active fiscal year year-part from PHP
const ACTIVE_FY_CODE = <?= json_encode($active_fy_code) ?>;

/**
 * Build a short, uppercase abbreviation from a multi-word name.
 * Strategy: take first 2 letters of each significant word, cap at ~6 chars total.
 */
function abbreviateName(name) {
    if (!name.trim()) return '';
    const words = name.trim().split(/\s+/)
        .filter(w => !STOP.includes(w.toLowerCase()) && /[a-zA-Z0-9]/.test(w));

    if (words.length === 0) return name.substring(0, 6).toUpperCase().replace(/[^A-Z0-9]/g, '');

    // Take 2 chars per word, up to first 3 significant words → max 6
    let abbr = '';
    const limit = Math.min(words.length, 3);
    for (let i = 0; i < limit; i++) {
        const chunk = words[i].replace(/[^A-Za-z0-9]/g, '');
        abbr += chunk.substring(0, 2).toUpperCase();
    }
    // If single word give up to 6 chars
    if (words.length === 1) {
        abbr = words[0].replace(/[^A-Za-z0-9]/g, '').substring(0, 6).toUpperCase();
    }
    return abbr;
}

// The book_code's "name" segment is derived from the Title whenever one is
// linked (existing or newly created), instead of re-abbreviating book_name
// from scratch each time — so every edition of the same title shares the
// same base code (e.g. MATH6-NT-2080, MATH6-NT-2081, ...) and stays aligned
// with the title/page-count metadata rather than drifting per edition.
function currentTitleBase() {
    const mode = document.querySelector('input[name="title_mode"]:checked').value;
    if (mode === 'new') {
        const code = document.getElementById('new_title_code').value.trim();
        if (code) return code.toUpperCase().replace(/[^A-Z0-9-]/g, '');
    } else {
        const sel = document.getElementById('title_id');
        const opt = sel.options[sel.selectedIndex];
        if (opt && opt.value && opt.dataset.code) return opt.dataset.code;
    }
    return null; // no title linked yet — fall back to abbreviating the book name
}

function generateCode() {
    const name       = document.getElementById('book_name').value.trim();
    const cls        = document.getElementById('class_level').value;
    const isTransl   = document.getElementById('is_translated').checked;
    const bookType   = document.getElementById('book_type').value;
    const fySelect   = document.getElementById('fiscal_year');
    const fy         = fySelect.value || ACTIVE_FY_CODE;

    const titleBase  = currentTitleBase();
    const nameAbbr   = titleBase || abbreviateName(name);  // aligned to Title when linked
    const classPart  = cls ? cls.toString().padStart(2,'0') : '';  // e.g. 10
    const transPart  = isTransl ? 'T' : 'NT';             // T or NT
    const typePart   = TYPE_MAP[bookType] || bookType.substring(0,2).toUpperCase();
    const fyPart     = fy ? fy.split('/')[0] : '';        // e.g. 2081

    // When the base already came from a Title code, don't also re-append
    // class/translation/type — the title already encodes those; just add
    // the fiscal year so editions read as TITLECODE-YEAR.
    const parts = titleBase ? [titleBase, fyPart].filter(Boolean)
                             : [nameAbbr, classPart, transPart, typePart, fyPart].filter(Boolean);
    return parts.join('-');
}

function updatePreview() {
    const code = generateCode();
    document.getElementById('codePreview').textContent = code || '—';
}

function applyGenerated() {
    const code = generateCode();
    if (code) {
        document.getElementById('book_code').value = code;
        document.getElementById('codePreview').textContent = code;
    }
}

// Wire up live preview to all relevant fields
['book_name','class_level','book_type','fiscal_year','title_id','new_title_code'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('change', updatePreview);
    if (el && el.tagName === 'INPUT') el.addEventListener('input', updatePreview);
});
document.getElementById('is_translated').addEventListener('change', updatePreview);
document.querySelectorAll('input[name="title_mode"]').forEach(r => r.addEventListener('change', updatePreview));

// Manual edit syncs preview
document.getElementById('book_code').addEventListener('input', function() {
    document.getElementById('codePreview').textContent = this.value.toUpperCase() || '—';
    this.value = this.value.toUpperCase();
});

// Generate button
document.getElementById('btnGenCode').addEventListener('click', applyGenerated);

// On new book: auto-generate when name is filled (blur)
<?php if (!$edit_book): ?>
document.getElementById('book_name').addEventListener('blur', function() {
    if (this.value.trim() && !document.getElementById('book_code').value.trim()) {
        applyGenerated();
    } else {
        updatePreview();
    }
});
<?php endif; ?>

// Initial preview
updatePreview();

// ── Title mode toggle (existing vs. new title) ────────────────────────────────
function updateTitleModeUI() {
    const isNew = document.getElementById('titleModeNew').checked;
    document.getElementById('titleExistingBlock').style.display = isNew ? 'none' : '';
    document.getElementById('titleNewCode').style.display       = isNew ? '' : 'none';
    document.getElementById('titleNewName').style.display       = isNew ? '' : 'none';

    // Suggest a title code/name from the book name when switching to "new"
    // (only fills blanks — never overwrites something the user already typed).
    if (isNew) {
        const nameEl = document.getElementById('book_name');
        const codeEl = document.getElementById('new_title_code');
        const titleNameEl = document.getElementById('new_title_name');
        if (nameEl.value.trim()) {
            if (!codeEl.value.trim()) codeEl.value = abbreviateName(nameEl.value.trim());
            if (!titleNameEl.value.trim()) titleNameEl.value = nameEl.value.trim();
        }
    }
    updatePreview();
}
document.getElementById('titleModeExisting').addEventListener('change', updateTitleModeUI);
document.getElementById('titleModeNew').addEventListener('change', updateTitleModeUI);
updateTitleModeUI();

document.getElementById('new_title_code').addEventListener('input', function() {
    this.value = this.value.toUpperCase();
});

// ── Prop-check cards toggle styling ──────────────────────────────────────────
['is_translated','is_optional','is_active'].forEach(id => {
    const cb   = document.getElementById(id);
    const card = cb.closest('.prop-check');
    cb.addEventListener('change', function() {
        card.classList.toggle('active', this.checked);
        if (id === 'is_translated') {
            document.getElementById('trBadge').style.display = this.checked ? 'inline' : 'none';
        }
    });
});

// ── Form validation ───────────────────────────────────────────────────────────
document.getElementById('bookForm').addEventListener('submit', function(e) {
    let ok = true;
    ['book_code','book_name'].forEach(id => {
        const el = document.getElementById(id);
        if (!el.value.trim()) { el.classList.add('is-invalid'); ok = false; }
        else el.classList.remove('is-invalid');
    });
    if (!ok) { e.preventDefault(); }
    else this.submitted = true;
});

// Warn on unsaved changes
let dirty = false;
document.querySelectorAll('#bookForm input, #bookForm select').forEach(el => {
    el.addEventListener('change', () => dirty = true);
    if (el.tagName === 'INPUT') el.addEventListener('input', () => dirty = true);
});
window.addEventListener('beforeunload', e => {
    if (dirty && !document.getElementById('bookForm').submitted) { e.preventDefault(); e.returnValue = ''; }
});

// ── Delete from edit page ─────────────────────────────────────────────────────
function confirmDeleteFromEdit(id, code) {
    document.getElementById('editDeleteId').value = id;
    document.getElementById('editDeleteBookCode').textContent = code;
    new bootstrap.Modal(document.getElementById('deleteFromEditModal')).show();
}
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>
