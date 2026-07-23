<?php
// book/title_report.php
// Lifetime production by Title — sums a title's production across every
// yearly edition (book_code changes each year; title_id stays constant),
// with a per-edition breakdown so you can see the individual years too.
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
redirect_if_not_logged_in();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';

$search        = trim($_GET['search'] ?? '');
$class_filter  = trim($_GET['class'] ?? '');
$status_filter = trim($_GET['status'] ?? 'all'); // titles: active/inactive/all
$expand_id     = isset($_GET['title_id']) ? (int)$_GET['title_id'] : 0;

require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

// ── Lifetime totals per title (production = deno.total_qty, i.e. actual
//    produced/dispatched quantity, summed across every edition of the title) ──
$where = ["1=1"];
$params = [];
if ($search !== '') {
    $where[] = "(bt.title_code ILIKE ? OR bt.title_name ILIKE ?)";
    $params[] = "%$search%"; $params[] = "%$search%";
}
if ($class_filter !== '') {
    $where[] = "bt.class_level = ?";
    $params[] = $class_filter;
}
if ($status_filter === 'active') {
    $where[] = "bt.is_active = true";
} elseif ($status_filter === 'inactive') {
    $where[] = "bt.is_active = false";
}
$where_clause = implode(' AND ', $where);

$titles_stmt = $conn->prepare("
    SELECT bt.id AS title_id, bt.title_code, bt.title_name, bt.class_level,
           bt.is_translated, bt.is_active,
           COUNT(DISTINCT b.book_id)              AS edition_count,
           COALESCE(SUM(d.total_qty), 0)          AS lifetime_total
    FROM book_titles bt
    LEFT JOIN books b ON b.title_id = bt.id
    LEFT JOIN deno d  ON d.book_code = b.book_code AND d.deleted_at IS NULL
    WHERE $where_clause
    GROUP BY bt.id, bt.title_code, bt.title_name, bt.class_level, bt.is_translated, bt.is_active
    ORDER BY bt.title_name
");
$titles_stmt->execute($params);
$titles = $titles_stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Per-edition breakdown for whichever title is expanded ──
$editions = [];
if ($expand_id) {
    $ed_stmt = $conn->prepare("
        SELECT b.book_id, b.book_code, b.fiscal_year, b.page_count, b.is_active,
               COALESCE(SUM(d.total_qty), 0) AS edition_total,
               COUNT(d.id) AS entry_count
        FROM books b
        LEFT JOIN deno d ON d.book_code = b.book_code AND d.deleted_at IS NULL
        WHERE b.title_id = ?
        GROUP BY b.book_id, b.book_code, b.fiscal_year, b.page_count, b.is_active
        ORDER BY b.fiscal_year
    ");
    $ed_stmt->execute([$expand_id]);
    $editions = $ed_stmt->fetchAll(PDO::FETCH_ASSOC);
}

$class_levels = $conn->query("SELECT DISTINCT class_level FROM book_titles WHERE class_level IS NOT NULL ORDER BY class_level")->fetchAll(PDO::FETCH_COLUMN);
?>
<style>
.container { max-width: 1200px; margin: 0 auto; padding: 20px; }
h2 { color: #333; margin-bottom: 20px; }
.search-container { background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #e9ecef; }
.search-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 14px; align-items: end; }
.search-group label { font-weight: 600; font-size: 13px; color: #495057; display: block; margin-bottom: 5px; }
.search-control { padding: 8px 12px; border: 2px solid #e9ecef; border-radius: 5px; font-size: 14px; width: 100%; box-sizing: border-box; }
.btn { padding: 9px 18px; border: none; border-radius: 5px; cursor: pointer; font-size: 14px; font-weight: 600; text-decoration: none; display: inline-block; }
.btn-primary { background: #007bff; color: #fff; }
.btn-secondary { background: #6c757d; color: #fff; }
.table-container { background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,.08); margin-bottom: 20px; }
.table { width: 100%; border-collapse: collapse; font-size: 13px; }
.table th, .table td { padding: 10px 8px; text-align: left; border-bottom: 1px solid #dee2e6; vertical-align: middle; }
.table th { background: #f8f9fa; font-weight: 700; color: #495057; font-size: 12px; text-transform: uppercase; }
.table tbody tr:hover { background: #f8f9fa; }
.lifetime-total { font-weight: 700; font-size: 15px; color: #0d6efd; }
.edition-row { background: #f8fbff !important; }
.badge { padding: 3px 9px; border-radius: 10px; font-size: 11px; font-weight: 700; }
.badge-active { background: #d1fae5; color: #065f46; }
.badge-inactive { background: #f3f4f6; color: #6b7280; }
.expand-link { color: #0d6efd; text-decoration: none; font-weight: 600; }
</style>

<div class="container">
    <h2>📚 Lifetime Production by Title</h2>
    <p class="text-muted" style="margin-top:-14px;">
        Sums production (from Deno records) across every yearly edition of a title —
        e.g. Math6-NT-2080 + Math6-NT-2081 + ... — so content/price/page changes
        between editions don't break the total. Click a title to see the per-edition breakdown.
    </p>

    <div class="search-container">
        <form method="GET">
            <div class="search-row">
                <div class="search-group">
                    <label>Search Title</label>
                    <input type="text" name="search" class="search-control" placeholder="Title code or name..." value="<?= htmlspecialchars($search) ?>">
                </div>
                <div class="search-group">
                    <label>Class Level</label>
                    <select name="class" class="search-control">
                        <option value="">All Classes</option>
                        <?php foreach ($class_levels as $cl): ?>
                            <option value="<?= $cl ?>" <?= $class_filter == $cl ? 'selected' : '' ?>>Class <?= $cl ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="search-group">
                    <label>Title Status</label>
                    <select name="status" class="search-control">
                        <option value="all"      <?= $status_filter === 'all'      ? 'selected' : '' ?>>All</option>
                        <option value="active"   <?= $status_filter === 'active'   ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= $status_filter === 'inactive' ? 'selected' : '' ?>>Obsolete</option>
                    </select>
                </div>
                <div class="search-group">
                    <button type="submit" class="btn btn-primary">🔍 Search</button>
                    <a href="?" class="btn btn-secondary">Reset</a>
                </div>
            </div>
        </form>
    </div>

    <div class="table-container">
        <table class="table">
            <thead>
                <tr>
                    <th>Title Code</th>
                    <th>Title Name</th>
                    <th>Class</th>
                    <th>Type</th>
                    <th>Status</th>
                    <th># Editions</th>
                    <th>Lifetime Total Produced</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($titles)): ?>
                    <tr><td colspan="8" style="text-align:center;padding:30px;color:#6c757d;">No titles found. Titles are created from <a href="create.php">Add New Book</a>.</td></tr>
                <?php endif; ?>
                <?php foreach ($titles as $t): ?>
                    <tr>
                        <td><code><?= htmlspecialchars($t['title_code']) ?></code></td>
                        <td><?= htmlspecialchars($t['title_name']) ?></td>
                        <td><?= $t['class_level'] ? 'Class ' . $t['class_level'] : '—' ?></td>
                        <td><?= $t['is_translated'] ? 'Translated' : 'Non-Translated' ?></td>
                        <td><span class="badge <?= $t['is_active'] ? 'badge-active' : 'badge-inactive' ?>"><?= $t['is_active'] ? 'Active' : 'Obsolete' ?></span></td>
                        <td><?= (int)$t['edition_count'] ?></td>
                        <td class="lifetime-total"><?= number_format($t['lifetime_total']) ?></td>
                        <td>
                            <a class="expand-link" href="?<?= http_build_query(array_merge($_GET, ['title_id' => $t['title_id']])) ?>#editions">
                                <?= $expand_id == $t['title_id'] ? 'Hide editions' : 'Show editions ▾' ?>
                            </a>
                        </td>
                    </tr>
                    <?php if ($expand_id == $t['title_id']): ?>
                        <tr class="edition-row" id="editions">
                            <td colspan="8" style="padding:0">
                                <table class="table" style="margin:0">
                                    <thead>
                                        <tr>
                                            <th>Book Code (Edition)</th>
                                            <th>Fiscal Year</th>
                                            <th>Page Count</th>
                                            <th>Status</th>
                                            <th>Deno Entries</th>
                                            <th>Produced (this edition)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($editions)): ?>
                                            <tr><td colspan="6" style="text-align:center;color:#6c757d;">No editions linked to this title yet.</td></tr>
                                        <?php endif; ?>
                                        <?php foreach ($editions as $e): ?>
                                            <tr>
                                                <td><code><?= htmlspecialchars($e['book_code']) ?></code></td>
                                                <td><?= htmlspecialchars($e['fiscal_year'] ?: '—') ?></td>
                                                <td><?= $e['page_count'] !== null ? (int)$e['page_count'] : '—' ?></td>
                                                <td><span class="badge <?= $e['is_active'] ? 'badge-active' : 'badge-inactive' ?>"><?= $e['is_active'] ? 'Active' : 'Obsolete' ?></span></td>
                                                <td><?= (int)$e['entry_count'] ?></td>
                                                <td><strong><?= number_format($e['edition_total']) ?></strong></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </td>
                        </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>
