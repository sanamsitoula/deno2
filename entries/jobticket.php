<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
redirect_if_not_logged_in();

$currentUserId = $_SESSION['user_id'] ?? 0;

require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

// Utility functions
function generateJobTicketCode($conn, $fiscalYearId) {
    $fyCode = $conn->query("SELECT fiscal_code FROM fiscal_years WHERE id = $fiscalYearId")->fetchColumn();
    $count = $conn->query("SELECT COUNT(*) FROM job_ticket WHERE fiscal_year_id = $fiscalYearId")->fetchColumn();
    $seq = str_pad($count + 1, 3, '0', STR_PAD_LEFT);
    return "$fyCode-JT$seq";
}

function getFiscalYearId($conn) {
    $fiscalStmt = $conn->query("SELECT id FROM fiscal_years WHERE is_active = TRUE LIMIT 1");
    return $fiscalStmt->fetchColumn();
}

// Handle actions
$action = $_GET['action'] ?? 'list';
$fiscalYearId = getFiscalYearId($conn);

if (!$fiscalYearId) {
    die("No active fiscal year set.");
}

// CRUD Operations
switch ($action) {
    case 'create':
        handleCreate($conn, $currentUserId, $fiscalYearId);
        break;
    case 'edit':
        handleEdit($conn, $currentUserId);
        break;
    case 'delete':
        handleDelete($conn);
        break;
    case 'view':
        handleView($conn);
        break;
    case 'print':
        handlePrint($conn);
        break;
    default:
        showList($conn);
        break;
}

function handleCreate($conn, $userId, $fiscalYearId) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!has_role('editor') && !has_role('admin')) {
            $_SESSION['error'] = "You don't have permission to perform this action.";
            header("Location: jobticket.php");
            exit();
        }

        try {
            $conn->beginTransaction();

            // Insert job ticket
            $stmt = $conn->prepare("INSERT INTO job_ticket (
                book_id, job_ticket_code, lot, remarks, description, 
                print_qty, page_qty, class, date_nep, date_eng, 
                created_by, fiscal_year_id
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            $jobTicketCode = generateJobTicketCode($conn, $fiscalYearId);
            $stmt->execute([
                $_POST['book_id'],
                $jobTicketCode,
                $_POST['lot'],
                $_POST['remarks'],
                $_POST['description'],
                $_POST['print_qty'],
                $_POST['page_qty'],
                $_POST['class'],
                $_POST['date_nep'],
                $_POST['date_eng'],
                $userId,
                $fiscalYearId
            ]);
            
            $jobTicketId = $conn->lastInsertId();

            // Insert forma details
            foreach ($_POST['forma'] as $i => $formaId) {
                if (empty($formaId)) continue;
                
                $conn->prepare("INSERT INTO job_ticket_details (
                    job_ticket_id, order_no, forma_id, page, 
                    old_forma_qty, print_qty, machine, description
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([
                    $jobTicketId,
                    $i + 1,
                    $formaId,
                    $_POST['forma_page'][$i] ?? 0,
                    $_POST['old_qty'][$i] ?? 0,
                    $_POST['forma_print_qty'][$i] ?? 0,
                    $_POST['machine'][$i] ?? null,
                    $_POST['desc'][$i] ?? null
                ]);
            }

            $conn->commit();
            $_SESSION['success'] = "Job Ticket created successfully!";
            header("Location: jobticket.php?action=view&id=$jobTicketId");
            exit();
        } catch (Exception $e) {
            $conn->rollBack();
            $_SESSION['error'] = "Error creating job ticket: " . $e->getMessage();
            header("Location: jobticket.php?action=create");
            exit();
        }
    }
    
    showForm($conn, null);
}

function handleEdit($conn, $userId) {
    if (!isset($_GET['id'])) {
        header("Location: jobticket.php");
        exit();
    }

    $id = $_GET['id'];
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!has_role('editor') && !has_role('admin')) {
            $_SESSION['error'] = "You don't have permission to perform this action.";
            header("Location: jobticket.php");
            exit();
        }

        try {
            $conn->beginTransaction();

            // Update job ticket
            $stmt = $conn->prepare("UPDATE job_ticket SET
                book_id = ?, lot = ?, remarks = ?, description = ?,
                print_qty = ?, page_qty = ?, class = ?, date_nep = ?, date_eng = ?,
                updated_by = ?, updated_date = CURRENT_TIMESTAMP
                WHERE id = ?");
            
            $stmt->execute([
                $_POST['book_id'],
                $_POST['lot'],
                $_POST['remarks'],
                $_POST['description'],
                $_POST['print_qty'],
                $_POST['page_qty'],
                $_POST['class'],
                $_POST['date_nep'],
                $_POST['date_eng'],
                $userId,
                $id
            ]);

            // Delete existing details
            $conn->prepare("DELETE FROM job_ticket_details WHERE job_ticket_id = ?")->execute([$id]);

            // Insert updated forma details
            foreach ($_POST['forma'] as $i => $formaId) {
                if (empty($formaId)) continue;
                
                $conn->prepare("INSERT INTO job_ticket_details (
                    job_ticket_id, order_no, forma_id, page, 
                    old_forma_qty, print_qty, machine, description
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([
                    $id,
                    $i + 1,
                    $formaId,
                    $_POST['forma_page'][$i] ?? 0,
                    $_POST['old_qty'][$i] ?? 0,
                    $_POST['forma_print_qty'][$i] ?? 0,
                    $_POST['machine'][$i] ?? null,
                    $_POST['desc'][$i] ?? null
                ]);
            }

            $conn->commit();
            $_SESSION['success'] = "Job Ticket updated successfully!";
            header("Location: jobticket.php?action=view&id=$id");
            exit();
        } catch (Exception $e) {
            $conn->rollBack();
            $_SESSION['error'] = "Error updating job ticket: " . $e->getMessage();
            header("Location: jobticket.php?action=edit&id=$id");
            exit();
        }
    }
    
    showForm($conn, $id);
}

function handleDelete($conn) {
    if (!has_role('admin')) {
        $_SESSION['error'] = "You don't have permission to perform this action.";
        header("Location: jobticket.php");
        exit();
    }

    if (!isset($_GET['id'])) {
        header("Location: jobticket.php");
        exit();
    }

    $id = $_GET['id'];
    
    try {
        $conn->beginTransaction();
        
        // Delete details first
        $conn->prepare("DELETE FROM job_ticket_details WHERE job_ticket_id = ?")->execute([$id]);
        
        // Then delete the ticket
        $conn->prepare("DELETE FROM job_ticket WHERE id = ?")->execute([$id]);
        
        $conn->commit();
        $_SESSION['success'] = "Job Ticket deleted successfully!";
    } catch (Exception $e) {
        $conn->rollBack();
        $_SESSION['error'] = "Error deleting job ticket: " . $e->getMessage();
    }
    
    header("Location: jobticket.php");
    exit();
}

function handleView($conn) {
    if (!isset($_GET['id'])) {
        header("Location: jobticket.php");
        exit();
    }

    $id = $_GET['id'];
    $ticket = $conn->query("
        SELECT j.*, b.book_name, b.book_code, b.class_level, u.username as created_by_name,
               fy.fiscal_code, j.fiscal_year_id
        FROM job_ticket j
        JOIN books b ON j.book_id = b.book_id
        JOIN users u ON j.created_by = u.id
        JOIN fiscal_years fy ON j.fiscal_year_id = fy.id
        WHERE j.id = $id
    ")->fetch();

    if (!$ticket) {
        $_SESSION['error'] = "Job Ticket not found!";
        header("Location: jobticket.php");
        exit();
    }

    $details = $conn->query("
        SELECT jd.*, f.name as forma_name
        FROM job_ticket_details jd
        JOIN forma f ON jd.forma_id = f.id
        WHERE jd.job_ticket_id = $id
        ORDER BY jd.order_no
    ")->fetchAll();

    ?>
    <div class="container">
        <h2>View Job Ticket: <?= htmlspecialchars($ticket['job_ticket_code']) ?></h2>
        
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success"><?= $_SESSION['success'] ?></div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>
        
        <div class="card mb-4">
            <div class="card-header">
                <div class="d-flex justify-content-between">
                    <h4>Basic Information</h4>
                    <div>
                        <a href="jobticket.php?action=print&id=<?= $id ?>" class="btn btn-success btn-sm" target="_blank">Print</a>
                        <?php if (has_role('editor') || has_role('admin')): ?>
                            <a href="jobticket.php?action=edit&id=<?= $id ?>" class="btn btn-primary btn-sm">Edit</a>
                            <a href="jobticket.php?action=delete&id=<?= $id ?>" class="btn btn-danger btn-sm" onclick="return confirm('Are you sure?')">Delete</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <p><strong>Book Code:</strong> <?= htmlspecialchars($ticket['book_code']) ?></p>
                        <p><strong>Book Name:</strong> <?= htmlspecialchars($ticket['book_name']) ?></p>
                        <p><strong>Job Ticket Code:</strong> <?= htmlspecialchars($ticket['job_ticket_code']) ?></p>
                        <p><strong>Fiscal Year:</strong> <?= htmlspecialchars($ticket['fiscal_year']) ?></p>
                        <p><strong>Lot No:</strong> <?= htmlspecialchars($ticket['lot']) ?></p>
                    </div>
                    <div class="col-md-6">
                        <p><strong>Print Qty:</strong> <?= number_format($ticket['print_qty']) ?></p>
                        <p><strong>Page Qty:</strong> <?= number_format($ticket['page_qty']) ?></p>
                        <p><strong>Class:</strong> <?= htmlspecialchars($ticket['class']) ?></p>
                        <p><strong>Date (Nep):</strong> <?= htmlspecialchars($ticket['date_nep']) ?></p>
                        <p><strong>Date (Eng):</strong> <?= htmlspecialchars($ticket['date_eng']) ?></p>
                    </div>
                </div>
                <?php if ($ticket['remarks']): ?>
                    <p><strong>Remarks:</strong> <?= htmlspecialchars($ticket['remarks']) ?></p>
                <?php endif; ?>
                <?php if ($ticket['description']): ?>
                    <p><strong>Description:</strong> <?= htmlspecialchars($ticket['description']) ?></p>
                <?php endif; ?>
            </div>
            <div class="card-footer">
                <small class="text-muted">
                    Created by <?= htmlspecialchars($ticket['created_by_name']) ?> on <?= $ticket['created_date'] ?>
                    <?php if ($ticket['updated_by']): ?>
                        | Last updated by <?= htmlspecialchars($ticket['updated_by']) ?> on <?= $ticket['updated_date'] ?>
                    <?php endif; ?>
                </small>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h4>Forma Details</h4>
            </div>
            <div class="card-body">
                <table class="table table-bordered">
                    <thead>
                        <tr>
                            <th>Order No</th>
                            <th>Forma</th>
                            <th>Page</th>
                            <th>Old Forma Qty</th>
                            <th>Print Qty</th>
                            <th>Machine</th>
                            <th>Description</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($details as $detail): ?>
                            <tr>
                                <td><?= $detail['order_no'] ?></td>
                                <td><?= htmlspecialchars($detail['forma_name']) ?></td>
                                <td><?= $detail['page'] ?></td>
                                <td><?= number_format($detail['old_forma_qty']) ?></td>
                                <td><?= number_format($detail['print_qty']) ?></td>
                                <td><?= htmlspecialchars($detail['machine']) ?></td>
                                <td><?= htmlspecialchars($detail['description']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <a href="jobticket.php" class="btn btn-secondary mt-3">Back to List</a>
    </div>
    <?php
}

function handlePrint($conn) {
    if (!isset($_GET['id'])) {
        header("Location: jobticket.php");
        exit();
    }

    $id = $_GET['id'];
    $ticket = $conn->query("
        SELECT j.*, b.book_name, b.book_code, b.class_level, u.username as created_by_name,
               fy.fiscal_code, j.fiscal_year_id
        FROM job_ticket j
        JOIN books b ON j.book_id = b.book_id
        JOIN users u ON j.created_by = u.id
        JOIN fiscal_years fy ON j.fiscal_year_id = fy.id
        WHERE j.id = $id
    ")->fetch();

    if (!$ticket) {
        $_SESSION['error'] = "Job Ticket not found!";
        header("Location: jobticket.php");
        exit();
    }

    $details = $conn->query("
        SELECT jd.*, f.name as forma_name
        FROM job_ticket_details jd
        JOIN forma f ON jd.forma_id = f.id
        WHERE jd.job_ticket_id = $id
        ORDER BY jd.order_no
    ")->fetchAll();

    // Get current user for report generation info
    $currentUser = $conn->query("SELECT username FROM users WHERE id = " . ($_SESSION['user_id'] ?? 0))->fetchColumn();

    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Job Ticket - <?= htmlspecialchars($ticket['job_ticket_code']) ?></title>
        <style>
            @page { 
                size: A4; 
                margin: 20mm;
            }
            body {
                font-family: 'Noto Sans Devanagari', Arial, sans-serif;
                font-size: 12px;
                line-height: 1.4;
                margin: 0;
                padding: 0;
            }
            .header {
                text-align: center;
                margin-bottom: 20px;
                border-bottom: 2px solid #000;
                padding-bottom: 10px;
            }
            .logo {
                width: 60px;
                height: 60px;
                float: left;
                margin-right: 20px;
            }
            .company-name {
                font-size: 18px;
                font-weight: bold;
                margin-bottom: 5px;
            }
            .department {
                font-size: 14px;
                margin-bottom: 5px;
            }
            .address {
                font-size: 12px;
                margin-bottom: 10px;
            }
            .ticket-info {
                margin: 20px 0;
            }
            .ticket-info table {
                width: 100%;
                border-collapse: collapse;
            }
            .ticket-info td {
                padding: 5px;
                border: 1px solid #000;
            }
            .forma-table {
                width: 100%;
                border-collapse: collapse;
                margin: 20px 0;
            }
            .forma-table th,
            .forma-table td {
                border: 1px solid #000;
                padding: 8px;
                text-align: center;
                font-size: 11px;
            }
            .forma-table th {
                background-color: #f0f0f0;
                font-weight: bold;
            }
            .footer {
                margin-top: 30px;
                font-size: 10px;
            }
            .signature-section {
                margin-top: 40px;
                display: flex;
                justify-content: space-between;
            }
            .signature-box {
                text-align: center;
                width: 200px;
                border-top: 1px solid #000;
                padding-top: 5px;
                margin-top: 50px;
            }
            .notes {
                margin-top: 20px;
                font-size: 10px;
            }
            .report-info {
                margin-top: 30px;
                padding-top: 10px;
                border-top: 1px solid #ccc;
                font-size: 10px;
                color: #666;
            }
            @media print {
                .no-print { 
                    display: none; 
                }
            }
        </style>
        <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Devanagari:wght@400;700&display=swap" rel="stylesheet">
    </head>
    <body>
        <div class="no-print" style="margin-bottom: 20px;">
            <button onclick="window.print()" class="btn btn-primary">Print</button>
            <button onclick="window.close()" class="btn btn-secondary">Close</button>
        </div>

        <div class="header">
            <div style="display: flex; align-items: center; justify-content: center;">
                <div class="logo">
                    <!-- Company Logo SVG -->
                    <svg viewBox="0 0 100 100" style="width: 60px; height: 60px;">
                        <circle cx="50" cy="50" r="45" fill="none" stroke="#000" stroke-width="3"/>
                        <path d="M30 20 L30 80 M30 50 L70 50 M70 20 L70 80" stroke="#000" stroke-width="3" fill="none"/>
                    </svg>
                </div>
                <div>
                    <div class="company-name">जनक शिक्षा सामग्री केन्द्र लि.</div>
                    <div class="department">उत्पादन विभाग</div>
                    <div class="address">सानो टिमी, भक्तपुर</div>
                </div>
            </div>
        </div>

        <div style="text-align: center; margin-bottom: 20px;">
            <h3>पाठ्यपुस्तकहरूको छपाइ जब टिकट</h3>
        </div>

        <div class="ticket-info">
            <table>
                <tr>
                    <td><strong>पाठ्यपुस्तकको नाम:</strong> <?= htmlspecialchars($ticket['book_code']) ?> - <?= htmlspecialchars($ticket['book_name']) ?></td>
                    <td><strong>शैक्षिक सत्र:</strong> <?= htmlspecialchars($ticket['fiscal_year']) ?></td>
                </tr>
                <tr>
                    <td><strong>कक्षा:</strong> <?= htmlspecialchars($ticket['class_level']) ?></td>
                    <td><strong>जब नं.:</strong> <?= htmlspecialchars($ticket['job_ticket_code']) ?></td>
                </tr>
                <tr>
                    <td><strong>पेज संख्या:</strong> <?= number_format($ticket['page_qty']) ?></td>
                    <td><strong>मिति:</strong> <?= htmlspecialchars($ticket['date_nep']) ?></td>
                </tr>
                <tr>
                    <td><strong>छपाइ भईसकेको संख्या:</strong> 0</td>
                    <td><strong>छपाइ संख्या:</strong> <?= number_format($ticket['print_qty']) ?></td>
                </tr>
            </table>
        </div>

        <table class="forma-table">
            <thead>
                <tr>
                    <th>सि.नं.</th>
                    <th>फर्मा</th>
                    <th>पेज</th>
                    <th>पुरानो फर्मा बाँकी</th>
                    <th>छापिने संख्या</th>
                    <th>मेसिन</th>
                    <th>केैफियत</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                // Fill up to 10 rows
                for ($i = 0; $i < 10; $i++): 
                    $detail = $details[$i] ?? null;
                ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td><?= $detail ? htmlspecialchars($detail['forma_name']) : '' ?></td>
                        <td><?= $detail ? $detail['page'] : '' ?></td>
                        <td><?= $detail ? number_format($detail['old_forma_qty']) : '' ?></td>
                        <td><?= $detail ? number_format($detail['print_qty']) : '' ?></td>
                        <td><?= $detail ? htmlspecialchars($detail['machine']) : '' ?></td>
                        <td><?= $detail ? htmlspecialchars($detail['description']) : '' ?></td>
                    </tr>
                <?php endfor; ?>
                <tr>
                    <td colspan="4"><strong>कुल</strong></td>
                    <td><strong><?= number_format(array_sum(array_column($details, 'print_qty'))) ?></strong></td>
                    <td colspan="2"></td>
                </tr>
            </tbody>
        </table>

        <div class="notes">
            <p><strong>कुल सहित जम्मा पेज:</strong> <?= number_format(array_sum(array_column($details, 'print_qty'))) ?></p>
        </div>

        <div class="notes" style="margin-top: 30px;">
            <p>१ श्रीमान प्रबन्ध सचालकज्यू</p>
            <p>२ श्रीमान बिभागीय प्रमुखज्यू</p>
            <p>३ नया तथा पुरानो भवन अफसेट</p>
            <p>४ प्लेट मेकिङ</p>
            <p>५ नया भवन सि.टि.पि</p>
            <p>६ तेक्यूरिटी</p>
            <p>७ रेकर्ड राखा</p>
        </div>

        <div class="signature-section">
            <div class="signature-box">इन्चार्ज</div>
        </div>

        <div style="text-align: center; margin-top: 30px;">
            <p><strong>कभर P.R.C अनुसार सुरुण गर्ने</strong></p>
        </div>

        <div class="report-info">
            <p><strong>Report Generated:</strong> <?= date('Y-m-d H:i:s') ?></p>
            <p><strong>Generated By:</strong> <?= htmlspecialchars($currentUser) ?></p>
        </div>
    </body>
    </html>
    <?php
    exit();
}

function showList($conn) {
    $tickets = $conn->query("
        SELECT j.id, j.job_ticket_code, j.lot, j.print_qty, j.status, 
               j.created_date, b.book_name, b.book_code, u.username as created_by,
               fy.fiscal_code
        FROM job_ticket j
        JOIN books b ON j.book_id = b.book_id
        JOIN users u ON j.created_by = u.id
        JOIN fiscal_years fy ON j.fiscal_year_id = fy.id
        ORDER BY j.created_date DESC
    ")->fetchAll();

    ?>
    <div class="container">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2>Job Tickets</h2>
            <?php if (has_role('editor') || has_role('admin')): ?>
                <a href="jobticket.php?action=create" class="btn btn-primary">Create New</a>
            <?php endif; ?>
        </div>
        
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success"><?= $_SESSION['success'] ?></div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger"><?= $_SESSION['error'] ?></div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>
        
        <div class="card">
            <div class="card-body">
                <table class="table table-striped table-hover" id="ticketsTable">
                    <thead>
                        <tr>
                            <th>Job Ticket Code</th>
                            <th>Book Code</th>
                            <th>Book Name</th>
                            <th>Fiscal Year</th>
                            <th>Lot No</th>
                            <th>Print Qty</th>
                            <th>Status</th>
                            <th>Created By</th>
                            <th>Created Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tickets as $ticket): ?>
                            <tr>
                                <td><?= htmlspecialchars($ticket['job_ticket_code']) ?></td>
                                <td><?= htmlspecialchars($ticket['book_code']) ?></td>
                                <td><?= htmlspecialchars($ticket['book_name']) ?></td>
                                <td><?= htmlspecialchars($ticket['fiscal_year']) ?></td>
                                <td><?= htmlspecialchars($ticket['lot']) ?></td>
                                <td><?= number_format($ticket['print_qty']) ?></td>
                                <td>
                                    <span class="badge bg-<?= getStatusBadge($ticket['status']) ?>">
                                        <?= ucfirst(str_replace('_', ' ', $ticket['status'])) ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($ticket['created_by']) ?></td>
                                <td><?= $ticket['created_date'] ?></td>
                                <td>
                                    <a href="jobticket.php?action=view&id=<?= $ticket['id'] ?>" class="btn btn-sm btn-info">View</a>
                                    <a href="jobticket.php?action=print&id=<?= $ticket['id'] ?>" class="btn btn-sm btn-success" target="_blank">Print</a>
                                    <?php if (has_role('editor') || has_role('admin')): ?>
                                        <a href="jobticket.php?action=edit&id=<?= $ticket['id'] ?>" class="btn btn-sm btn-warning">Edit</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <script>
    $(document).ready(function() {
        $('#ticketsTable').DataTable({
            responsive: true,
            order: [[8, 'desc']],
            columnDefs: [
                {
                    targets: [1, 2], // Book Code and Book Name columns
                    searchable: true
                }
            ]
        });
    });
    </script>
    <?php
}

function getStatusBadge($status) {
    switch ($status) {
        case 'pending': return 'warning';
        case 'in_progress': return 'primary';
        case 'completed': return 'success';
        case 'cancelled': return 'danger';
        default: return 'secondary';
    }
}

function showForm($conn, $id = null) {
    $books = $conn->query("SELECT book_id, book_code, book_name, class_level FROM books ORDER BY book_code")->fetchAll();
    $formas = $conn->query("SELECT id, name FROM forma WHERE status = 'active' ORDER BY name")->fetchAll();
    $machines = $conn->query("SELECT machine_name FROM machines WHERE status = 'active'")->fetchAll();
    
    $ticket = null;
    $details = [];
    
    if ($id) {
        $ticket = $conn->query("SELECT * FROM job_ticket WHERE id = $id")->fetch();
        if (!$ticket) {
            $_SESSION['error'] = "Job Ticket not found!";
            header("Location: jobticket.php");
            exit();
        }
        
        $details = $conn->query("
            SELECT jd.*, f.name as forma_name
            FROM job_ticket_details jd
            JOIN forma f ON jd.forma_id = f.id
            WHERE jd.job_ticket_id = $id
            ORDER BY jd.order_no
        ")->fetchAll();
    }
    
    ?>
    <div class="container">
        <h2><?= $id ? "Edit" : "Create" ?> Job Ticket</h2>
        
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger"><?= $_SESSION['error'] ?></div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>
        
        <form method="POST" id="jobTicketForm">
            <div class="card mb-4">
                <div class="card-header">
                    <h4>Basic Information</h4>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="book_id" class="form-label">Book</label>
                                <select name="book_id" id="book_id" class="form-select book-select" required>
                                    <option value="">Search and select a book</option>
                                    <?php foreach ($books as $book): ?>
                                        <option value="<?= $book['book_id'] ?>" 
                                            data-book-code="<?= htmlspecialchars($book['book_code']) ?>"
                                            data-class-level="<?= $book['class_level'] ?>"
                                            <?= ($ticket && $ticket['book_id'] == $book['book_id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($book['book_code']) ?> - <?= htmlspecialchars($book['book_name']) ?> (Class: <?= $book['class_level'] ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label for="lot" class="form-label">Lot No</label>
                                <input type="text" name="lot" id="lot" class="form-control" 
                                    value="<?= $ticket ? htmlspecialchars($ticket['lot']) : '' ?>" required>
                            </div>
                            
                            <div class="mb-3">
                                <label for="remarks" class="form-label">Remarks</label>
                                <input type="text" name="remarks" id="remarks" class="form-control" 
                                    value="<?= $ticket ? htmlspecialchars($ticket['remarks']) : '' ?>">
                            </div>
                            
                            <div class="mb-3">
                                <label for="description" class="form-label">Description</label>
                                <textarea name="description" id="description" class="form-control" rows="3"><?= $ticket ? htmlspecialchars($ticket['description']) : '' ?></textarea>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="print_qty" class="form-label">Print Qty</label>
                                <input type="number" name="print_qty" id="print_qty" class="form-control" 
                                    value="<?= $ticket ? $ticket['print_qty'] : '' ?>" required min="1">
                            </div>
                            
                            <div class="mb-3">
                                <label for="page_qty" class="form-label">Page Qty</label>
                                <input type="number" name="page_qty" id="page_qty" class="form-control" 
                                    value="<?= $ticket ? $ticket['page_qty'] : '' ?>" required min="1">
                            </div>
                            
                            <div class="mb-3">
                                <label for="class" class="form-label">Class</label>
                                <input type="number" name="class" id="class" class="form-control" 
                                    value="<?= $ticket ? $ticket['class'] : '' ?>" required min="1" max="12">
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="date_nep" class="form-label">Date (Nepali)</label>
                                        <input type="text" name="date_nep" id="date_nep" class="form-control" 
                                            placeholder="२०८१-०२-०५"
                                            value="<?= $ticket ? htmlspecialchars($ticket['date_nep']) : '' ?>" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="date_eng" class="form-label">Date (English)</label>
                                        <input type="date" name="date_eng" id="date_eng" class="form-control" 
                                            value="<?= $ticket ? htmlspecialchars($ticket['date_eng']) : date('Y-m-d') ?>" required>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h4>Forma Details</h4>
                    <div>
                        <button type="button" id="addForma" class="btn btn-sm btn-primary">+ Add Forma</button>
                        <button type="button" id="resetFormas" class="btn btn-sm btn-secondary">Reset to 10</button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-sm" id="formaTable">
                            <thead class="table-light">
                                <tr>
                                    <th width="4%">Order No</th>
                                    <th width="25%">Forma</th>
                                    <th width="8%">Page</th>
                                    <th width="12%">Old Forma Qty</th>
                                    <th width="12%">Print Qty</th>
                                    <th width="15%">Machine</th>
                                    <th width="20%">Description</th>
                                    <th width="4%">×</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                // Pre-load 10 formas by default
                                for ($i = 0; $i < 10; $i++): 
                                    $detail = $details[$i] ?? null;
                                ?>
                                    <tr>
                                        <td class="text-center align-middle"><?= $i + 1 ?></td>
                                        <td>
                                            <select name="forma[]" class="form-select forma-select form-select-sm">
                                                <option value="">Select Forma</option>
                                                <?php foreach ($formas as $f): ?>
                                                    <option value="<?= $f['id'] ?>" 
                                                        <?= ($detail && $detail['forma_id'] == $f['id']) ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($f['name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td>
                                            <input type="number" name="forma_page[]" class="form-control form-control-sm" 
                                                value="<?= $detail ? $detail['page'] : '32' ?>" min="0">
                                        </td>
                                        <td>
                                            <input type="number" name="old_qty[]" class="form-control form-control-sm" 
                                                value="<?= $detail ? $detail['old_forma_qty'] : '0' ?>" min="0">
                                        </td>
                                        <td>
                                            <input type="number" name="forma_print_qty[]" class="form-control form-control-sm print-qty-input" 
                                                value="<?= $detail ? $detail['print_qty'] : '10000' ?>" min="0">
                                        </td>
                                        <td>
                                            <select name="machine[]" class="form-select form-select-sm">
                                                <option value="">Select Machine</option>
                                                <?php foreach ($machines as $m): ?>
                                                    <option value="<?= htmlspecialchars($m['machine_name']) ?>" 
                                                        <?= ($detail && $detail['machine'] == $m['machine_name']) ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($m['machine_name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td>
                                            <input type="text" name="desc[]" class="form-control form-control-sm" 
                                                value="<?= $detail ? htmlspecialchars($detail['description']) : '' ?>" 
                                                placeholder="Description">
                                        </td>
                                        <td>
                                            <button type="button" class="btn btn-sm btn-outline-danger remove-forma">×</button>
                                        </td>
                                    </tr>
                                <?php endfor; ?>
                            </tbody>
                            <tfoot>
                                <tr class="table-secondary">
                                    <td colspan="4" class="text-end fw-bold">Total Print Qty:</td>
                                    <td><input type="text" id="totalPrintQty" class="form-control form-control-sm fw-bold" readonly></td>
                                    <td colspan="3"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>

            <div class="d-flex justify-content-between">
                <a href="jobticket.php" class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary"><?= $id ? "Update" : "Create" ?> Job Ticket</button>
            </div>
        </form>
    </div>

    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
    $(document).ready(function() {
        // Initialize Select2 with search functionality
        $('.book-select').select2({
            placeholder: "Search by book code or name",
            allowClear: true,
            width: '100%',
            matcher: function(params, data) {
                // If there are no search terms, return all data
                if ($.trim(params.term) === '') {
                    return data;
                }

                // Search in both book code and book name
                var text = data.text.toLowerCase();
                var term = params.term.toLowerCase();
                
                if (text.indexOf(term) > -1) {
                    return data;
                }

                // Return `null` if the term should not be displayed
                return null;
            }
        });

        // Auto-fill class when book is selected
        $('.book-select').on('change', function() {
            var selectedOption = $(this).find('option:selected');
            var classLevel = selectedOption.data('class-level');
            if (classLevel) {
                $('#class').val(classLevel);
            }
        });

        $('.forma-select').select2({
            placeholder: "Select forma",
            allowClear: true,
            width: '100%'
        });

        // Calculate total print quantity
        function calculateTotal() {
            var total = 0;
            $('.print-qty-input').each(function() {
                var value = parseInt($(this).val()) || 0;
                total += value;
            });
            $('#totalPrintQty').val(total.toLocaleString());
        }

        // Calculate total on page load
        calculateTotal();

        // Recalculate total when print quantity changes
        $(document).on('input', '.print-qty-input', function() {
            calculateTotal();
        });

        // Add new forma row
        $('#addForma').click(function() {
            var rowCount = $('#formaTable tbody tr').length;
            
            var newRow = `
            <tr>
                <td class="text-center align-middle">${rowCount + 1}</td>
                <td>
                    <select name="forma[]" class="form-select forma-select form-select-sm">
                        <option value="">Select Forma</option>
                        <?php foreach ($formas as $f): ?>
                            <option value="<?= $f['id'] ?>"><?= htmlspecialchars($f['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td><input type="number" name="forma_page[]" class="form-control form-control-sm" value="32" min="0"></td>
                <td><input type="number" name="old_qty[]" class="form-control form-control-sm" value="0" min="0"></td>
                <td><input type="number" name="forma_print_qty[]" class="form-control form-control-sm print-qty-input" value="10000" min="0"></td>
                <td>
                    <select name="machine[]" class="form-select form-select-sm">
                        <option value="">Select Machine</option>
                        <?php foreach ($machines as $m): ?>
                            <option value="<?= htmlspecialchars($m['machine_name']) ?>"><?= htmlspecialchars($m['machine_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td><input type="text" name="desc[]" class="form-control form-control-sm" placeholder="Description"></td>
                <td><button type="button" class="btn btn-sm btn-outline-danger remove-forma">×</button></td>
            </tr>
            `;
            $('#formaTable tbody').append(newRow);
            
            // Reinitialize Select2 for new dropdowns
            $('.forma-select').select2({
                placeholder: "Select forma",
                allowClear: true,
                width: '100%'
            });
            
            calculateTotal();
        });

        // Reset to 10 formas
        $('#resetFormas').click(function() {
            $('#formaTable tbody tr').slice(10).remove(); // Remove rows beyond 10
            
            // Add rows if less than 10
            var currentRows = $('#formaTable tbody tr').length;
            for (var i = currentRows; i < 10; i++) {
                $('#addForma').click();
            }
        });

        // Remove forma row
        $(document).on('click', '.remove-forma', function() {
            $(this).closest('tr').remove();
            // Update row numbers
            $('#formaTable tbody tr').each(function(index) {
                $(this).find('td:first').text(index + 1);
            });
            calculateTotal();
        });

        // Form validation
        $('#jobTicketForm').on('submit', function(e) {
            var hasForma = false;
            $('select[name="forma[]"]').each(function() {
                if ($(this).val()) {
                    hasForma = true;
                    return false; // break loop
                }
            });

            if (!hasForma) {
                e.preventDefault();
                alert("Please select at least one forma.");
                return false;
            }
        });

        // Auto-fill print quantity based on main print quantity
        $('#print_qty').on('input', function() {
            var mainQty = $(this).val();
            if (mainQty) {
                $('.print-qty-input').each(function() {
                    if (!$(this).val() || $(this).val() == '0') {
                        $(this).val(mainQty);
                    }
                });
                calculateTotal();
            }
        });
    });
    </script>
    <?php
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php';
?>