<?php
/**
 * Employee Profile — View + Edit + All Sections
 * Tabs: Profile | Family | Education | Designation History | Documents
 */
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';
redirect_if_not_logged_in();

$id  = (int)($_GET['id'] ?? 0);
$tab = $_GET['tab'] ?? 'profile';
$msg = ''; $err = '';

if (!$id) { header('Location: /jemc/hr/employee/index.php'); exit(); }

// ── Helpers ───────────────────────────────────────────────────
function bs($d) { return $d ?: 'N/A'; }
function fdate($d) { return $d ? date('d M Y', strtotime($d)) : 'N/A'; }
$bsMonths = [1=>'बैशाख',2=>'जेठ',3=>'असार',4=>'साउन',5=>'भाद्र',6=>'असोज',7=>'कार्तिक',8=>'मंसिर',9=>'पुष',10=>'माघ',11=>'फाल्गुण',12=>'चैत'];

// ── POST: Edit Profile ────────────────────────────────────────
if ($_POST['action'] === 'edit_profile') {
    try {
        $fields = [
            'name'             => trim($_POST['name']),
            'name_eng'         => trim($_POST['name_eng'] ?? ''),
            'name_nep'         => trim($_POST['name_nep'] ?? ''),
            'mobile_number'    => trim($_POST['mobile_number'] ?? ''),
            'email'            => trim($_POST['email'] ?? ''),
            'dob'              => $_POST['dob'] ?: null,
            'dob_nep'          => trim($_POST['dob_nep'] ?? ''),
            'gender'           => $_POST['gender'] ?? '',
            'citizenship_no'   => trim($_POST['citizenship_no'] ?? ''),
            'national_id_card_no' => trim($_POST['national_id_card_no'] ?? ''),
            'pan_no'           => trim($_POST['pan_no'] ?? ''),
            'bank_name'        => trim($_POST['bank_name'] ?? ''),
            'bank_branch'      => trim($_POST['bank_branch'] ?? ''),
            'bank_account_number' => trim($_POST['bank_account_number'] ?? ''),
            'full_address'     => trim($_POST['full_address'] ?? ''),
            'state'            => trim($_POST['state'] ?? ''),
            'local_body'       => trim($_POST['local_body'] ?? ''),
            'ward_no'          => trim($_POST['ward_no'] ?? ''),
            'emp_status'       => $_POST['emp_status'] ?? 'ACTIVE',
            'emp_type'         => $_POST['emp_type'] ?? 'CONTRACT',
            'designation_id'   => (int)($_POST['designation_id'] ?? 0) ?: null,
            'level_id'         => (int)($_POST['level_id'] ?? 0) ?: null,
            'department_id'    => (int)($_POST['department_id'] ?? 0) ?: null,
            'join_date'        => $_POST['join_date'] ?: null,
            'join_date_nep'    => trim($_POST['join_date_nep'] ?? ''),
            'initial_appointment_date' => $_POST['initial_appointment_date'] ?: null,
            'initial_appointment_date_nep' => trim($_POST['initial_appointment_date_nep'] ?? ''),
            'retirement_date'  => $_POST['retirement_date'] ?: null,
            'retirement_date_nep' => trim($_POST['retirement_date_nep'] ?? ''),
            'card_id'          => trim($_POST['card_id'] ?? ''),
            'attendance_id'    => trim($_POST['attendance_id'] ?? ''),
            'is_technical'     => isset($_POST['is_technical']) ? 'true' : 'false',
            'is_ssf_enrolled'  => isset($_POST['is_ssf_enrolled']) ? 'true' : 'false',
            'taxpayer_type'    => $_POST['taxpayer_type'] ?? 'SINGLE',
            'fiscal_year_id'   => (int)($_POST['fiscal_year_id'] ?? 0) ?: null,
            'updated_by'       => $_SESSION['user_id'] ?? 0,
        ];

        // Handle picture upload
        if (!empty($_FILES['picture']['name'])) {
            $ext  = pathinfo($_FILES['picture']['name'], PATHINFO_EXTENSION);
            $dest = $_SERVER['DOCUMENT_ROOT'] . '/deno2/docs/employees/' . $id . '_photo.' . $ext;
            if (move_uploaded_file($_FILES['picture']['tmp_name'], $dest)) {
                $fields['picture'] = 'docs/employees/' . $id . '_photo.' . $ext;
            }
        }

        $sets = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($fields)));
        $conn->prepare("UPDATE employee SET $sets, updated_date=NOW() WHERE id=:id")
             ->execute(array_merge($fields, [':id' => $id]));

        $msg = "Employee profile updated.";
        $tab = 'profile';
    } catch(Exception $e) { $err = $e->getMessage(); }
}

// ── POST: Add Family ──────────────────────────────────────────
if ($_POST['action'] === 'add_family') {
    try {
        $fid = (int)($_POST['family_id'] ?? 0);
        if ($fid) {
            $conn->prepare("UPDATE employee_family SET name=:n, relation=:r, contact=:c, remarks=:rm WHERE id=:id AND emp_id=:e")
                 ->execute([':n'=>$_POST['fname'],':r'=>$_POST['relation'],':c'=>$_POST['contact']??'',':rm'=>$_POST['remarks']??'',':id'=>$fid,':e'=>$id]);
            $msg = "Family member updated.";
        } else {
            $conn->prepare("INSERT INTO employee_family (emp_id,name,relation,contact,remarks,created_date) VALUES (:e,:n,:r,:c,:rm,NOW())")
                 ->execute([':e'=>$id,':n'=>$_POST['fname'],':r'=>$_POST['relation'],':c'=>$_POST['contact']??'',':rm'=>$_POST['remarks']??'']);
            $msg = "Family member added.";
        }
        $tab = 'family';
    } catch(Exception $e) { $err = $e->getMessage(); }
}

if (($_POST['action'] ?? '') === 'delete_family') {
    $conn->prepare("DELETE FROM employee_family WHERE id=:id AND emp_id=:e")->execute([':id'=>(int)$_POST['family_id'],':e'=>$id]);
    $msg="Family member removed."; $tab='family';
}

// ── POST: Add Education ───────────────────────────────────────
if ($_POST['action'] === 'add_education') {
    try {
        $eid = (int)($_POST['edu_id'] ?? 0);
        $d = [':e'=>$id,':inst'=>$_POST['institution_name'],':deg'=>$_POST['degree_name'],
              ':uni'=>$_POST['university']??'',':yr'=>(int)($_POST['completion_year']??0)??null,
              ':mrk'=>$_POST['marks']??null,':rem'=>$_POST['remarks']??'',':ord'=>(int)($_POST['display_order']??0)];
        if ($eid) {
            $conn->prepare("UPDATE education_details SET institution_name=:inst,degree_name=:deg,university=:uni,completion_year=:yr,marks=:mrk,remarks=:rem,display_order=:ord WHERE id=:eid AND emp_id=:e")
                 ->execute(array_merge($d,[':eid'=>$eid])); $msg="Education updated.";
        } else {
            $conn->prepare("INSERT INTO education_details (emp_id,institution_name,degree_name,university,completion_year,marks,remarks,display_order,status,created_date) VALUES (:e,:inst,:deg,:uni,:yr,:mrk,:rem,:ord,true,NOW())")
                 ->execute($d); $msg="Education added.";
        }
        $tab='education';
    } catch(Exception $e) { $err=$e->getMessage(); }
}

if (($_POST['action'] ?? '') === 'delete_education') {
    $conn->prepare("DELETE FROM education_details WHERE id=:id AND emp_id=:e")->execute([':id'=>(int)$_POST['edu_id'],':e'=>$id]);
    $msg="Education removed."; $tab='education';
}

// ── POST: Add Designation History ─────────────────────────────
if ($_POST['action'] === 'add_designation') {
    try {
        $did = (int)($_POST['desig_hist_id'] ?? 0);
        $d = [':e'=>$id,':doj'=>$_POST['date_of_join']??null,':des'=>(int)($_POST['designation_id']??0)??null,
              ':lvl'=>(int)($_POST['level_id']??0)??null,':dep'=>(int)($_POST['department_id']??0)??null,
              ':sts'=>$_POST['status']??'CURRENT',':rem'=>$_POST['remarks']??''];
        if ($did) {
            $conn->prepare("UPDATE employee_designation SET date_of_join=:doj,designation_id=:des,level_id=:lvl,department_id=:dep,status=:sts,remarks=:rem,updated_date=NOW() WHERE id=:did AND emp_id=:e")
                 ->execute(array_merge($d,[':did'=>$did])); $msg="Designation updated.";
        } else {
            $conn->prepare("INSERT INTO employee_designation (emp_id,date_of_join,designation_id,level_id,department_id,status,remarks,created_date) VALUES (:e,:doj,:des,:lvl,:dep,:sts,:rem,NOW())")
                 ->execute($d); $msg="Designation history added.";
        }
        $tab='designation';
    } catch(Exception $e) { $err=$e->getMessage(); }
}

if (($_POST['action'] ?? '') === 'delete_designation') {
    $conn->prepare("DELETE FROM employee_designation WHERE id=:id AND emp_id=:e")->execute([':id'=>(int)$_POST['desig_hist_id'],':e'=>$id]);
    $msg="Designation record removed."; $tab='designation';
}

// ── POST: Upload Document ─────────────────────────────────────
if ($_POST['action'] === 'upload_document') {
    try {
        $uploadDir = $_SERVER['DOCUMENT_ROOT'] . '/deno2/docs/employees/docs/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

        $files = $_FILES['documents'];
        $names = $_POST['doc_names'] ?? [];
        $types = $_POST['doc_types'] ?? [];
        $descs = $_POST['doc_descs'] ?? [];
        $uploaded = 0;

        for ($i = 0; $i < count($files['name']); $i++) {
            if (empty($files['name'][$i]) || $files['error'][$i] !== 0) continue;

            $origName = $files['name'][$i];
            $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            $safe     = time() . '_' . $i . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $origName);
            $dest     = $uploadDir . $safe;

            if (move_uploaded_file($files['tmp_name'][$i], $dest)) {
                $conn->prepare("INSERT INTO employee_documents (employee_id,document_name,document_type,file_path,file_size,mime_type,description,status,created_by,created_date)
                    VALUES (:e,:dn,:dt,:fp,:fs,:mt,:desc,'ACTIVE',:u,NOW())")
                    ->execute([
                        ':e'   => $id,
                        ':dn'  => $names[$i] ?: $origName,
                        ':dt'  => $types[$i] ?: 'OTHER',
                        ':fp'  => 'docs/employees/docs/' . $safe,
                        ':fs'  => $files['size'][$i],
                        ':mt'  => $files['type'][$i],
                        ':desc'=> $descs[$i] ?? '',
                        ':u'   => $_SESSION['user_id'] ?? 0,
                    ]);
                $uploaded++;
            }
        }
        $msg = "$uploaded document(s) uploaded.";
        $tab = 'documents';
    } catch(Exception $e) { $err = $e->getMessage(); }
}

if (($_POST['action'] ?? '') === 'delete_document') {
    $doc = $conn->prepare("SELECT file_path FROM employee_documents WHERE id=:id AND employee_id=:e");
    $doc->execute([':id'=>(int)$_POST['doc_id'],':e'=>$id]);
    $docRow = $doc->fetch(PDO::FETCH_ASSOC);
    if ($docRow) {
        @unlink($_SERVER['DOCUMENT_ROOT'] . '/deno2/' . $docRow['file_path']);
        $conn->prepare("DELETE FROM employee_documents WHERE id=:id AND employee_id=:e")->execute([':id'=>(int)$_POST['doc_id'],':e'=>$id]);
    }
    $msg="Document deleted."; $tab='documents';
}

// ── Load employee ─────────────────────────────────────────────
$emp = $conn->prepare("
    SELECT e.*, d.name AS designation_name, l.name AS level_name, l.remarks AS level_title,
           dep.name AS department_name, dep.sub_department_name,
           fy.fiscal_code,
           creator.name AS created_by_name, updater.name AS updated_by_name
    FROM employee e
    LEFT JOIN designation d   ON e.designation_id=d.id
    LEFT JOIN level l         ON e.level_id=l.id
    LEFT JOIN department dep  ON e.department_id=dep.id
    LEFT JOIN fiscal_years fy ON e.fiscal_year_id=fy.id
    LEFT JOIN employee creator ON e.created_by=creator.id
    LEFT JOIN employee updater ON e.updated_by=updater.id
    WHERE e.id=:id AND e.deleted_date IS NULL
");
$emp->execute([':id'=>$id]);
$e = $emp->fetch(PDO::FETCH_ASSOC);
if (!$e) { header('Location: /jemc/hr/employee/index.php'); exit(); }

// Related data
$family = $conn->prepare("SELECT * FROM employee_family WHERE emp_id=:id ORDER BY id"); $family->execute([':id'=>$id]); $family=$family->fetchAll(PDO::FETCH_ASSOC);
$education = $conn->prepare("SELECT * FROM education_details WHERE emp_id=:id ORDER BY display_order,completion_year DESC"); $education->execute([':id'=>$id]); $education=$education->fetchAll(PDO::FETCH_ASSOC);
$desigHist = $conn->prepare("SELECT ed.*,d.name AS designation_name,l.name AS level_name,dep.name AS dept_name FROM employee_designation ed LEFT JOIN designation d ON ed.designation_id=d.id LEFT JOIN level l ON ed.level_id=l.id LEFT JOIN department dep ON ed.department_id=dep.id WHERE ed.emp_id=:id ORDER BY ed.date_of_join DESC"); $desigHist->execute([':id'=>$id]); $desigHist=$desigHist->fetchAll(PDO::FETCH_ASSOC);
$documents = $conn->prepare("SELECT * FROM employee_documents WHERE employee_id=:id ORDER BY created_date DESC"); $documents->execute([':id'=>$id]); $documents=$documents->fetchAll(PDO::FETCH_ASSOC);

// Dropdowns
$designations = $conn->query("SELECT id,name FROM designation WHERE status=true ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$levels       = $conn->query("SELECT id,name,remarks FROM level WHERE status=true ORDER BY display_order DESC")->fetchAll(PDO::FETCH_ASSOC);
$departments  = $conn->query("SELECT id,name FROM department WHERE status=true ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$fiscalYears  = $conn->query("SELECT id,fiscal_code,is_active FROM fiscal_years ORDER BY start_date DESC")->fetchAll(PDO::FETCH_ASSOC);

$docTypes = ['CITIZENSHIP'=>'Citizenship','PASSPORT'=>'Passport','PAN'=>'PAN Card','DEGREE'=>'Degree/Certificate','APPOINTMENT'=>'Appointment Letter','CONTRACT'=>'Contract Letter','PHOTO'=>'Photo','NID'=>'National ID','BANK'=>'Bank Document','OTHER'=>'Other'];

$statusColors = ['ACTIVE'=>'bg-success','INACTIVE'=>'bg-secondary','DRAFT'=>'bg-warning','RETIRED'=>'bg-info','TERMINATED'=>'bg-danger'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($e['name']) ?> — JEMC</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<style>
body{background:#f0f2f8}
.profile-hero{background:linear-gradient(135deg,#2c3e8c 0%,#3a52b0 100%);color:#fff;border-radius:12px;padding:1.5rem;margin-bottom:1rem}
.profile-avatar{width:100px;height:100px;border-radius:50%;border:3px solid rgba(255,255,255,.5);object-fit:cover;background:#eee}
.profile-avatar-placeholder{width:100px;height:100px;border-radius:50%;border:3px solid rgba(255,255,255,.3);display:flex;align-items:center;justify-content:center;font-size:2.5rem;background:rgba(255,255,255,.1);color:#fff}
.info-label{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#8492a6;margin-bottom:1px}
.info-value{font-size:.88rem;color:#2d3436;font-weight:500}
.section-card{background:#fff;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,.06);overflow:hidden;margin-bottom:1rem}
.section-hdr{padding:.7rem 1rem;border-bottom:1px solid #f0f2f8;display:flex;align-items:center;justify-content:space-between}
.section-hdr h6{margin:0;font-weight:800;font-size:.85rem}
.tab-nav .nav-link{font-size:.82rem;padding:.45rem 1rem;border-radius:8px!important;color:#495057;margin-right:4px}
.tab-nav .nav-link.active{background:#2c3e8c;color:#fff}
.doc-card{border:1px solid #f0f2f8;border-radius:8px;padding:.75rem;display:flex;gap:.75rem;align-items:start;margin-bottom:.5rem;background:#fafbff}
.doc-icon{width:40px;height:40px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0}
.upload-zone{border:2px dashed #c8d0d8;border-radius:10px;padding:2rem;text-align:center;cursor:pointer;transition:all .2s;background:#fafbff}
.upload-zone:hover,.upload-zone.drag-over{border-color:#2c3e8c;background:#eef2ff}
.doc-row-input{display:flex;gap:.5rem;align-items:center;padding:.5rem;background:#f8f9fa;border-radius:6px;margin-bottom:.4rem}
</style>
</head>
<body>
<div class="container-fluid px-4 py-3" style="max-width:1400px">

<!-- Profile Hero -->
<div class="profile-hero">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
        <div class="d-flex gap-3 align-items-center">
            <?php if(!empty($e['picture'])): ?>
            <img src="/jemc/<?= htmlspecialchars($e['picture']) ?>" class="profile-avatar" alt="Photo">
            <?php else: ?>
            <div class="profile-avatar-placeholder">
                <?= strtoupper(substr($e['name'],0,1)) ?>
            </div>
            <?php endif; ?>
            <div>
                <h3 class="mb-1 fw-bold"><?= htmlspecialchars($e['name']) ?></h3>
                <?php if($e['name_nep']): ?><div style="font-size:.88rem;opacity:.85"><?= htmlspecialchars($e['name_nep']) ?></div><?php endif; ?>
                <div style="font-size:.82rem;opacity:.75;margin-top:4px">
                    <?= htmlspecialchars($e['designation_name'] ?? '') ?>
                    <?php if($e['department_name']): ?> · <?= htmlspecialchars($e['department_name']) ?><?php endif; ?>
                    <?php if($e['level_name']): ?> · Level <?= htmlspecialchars($e['level_name']) ?> <?= htmlspecialchars($e['level_title'] ?? '') ?><?php endif; ?>
                </div>
                <div class="mt-2 d-flex gap-2 flex-wrap">
                    <span class="badge <?= $statusColors[$e['emp_status']] ?? 'bg-secondary' ?>"><?= $e['emp_status'] ?></span>
                    <span class="badge bg-light text-dark"><?= $e['emp_type'] ?></span>
                    <code style="font-size:.75rem;background:rgba(255,255,255,.15);padding:2px 8px;border-radius:4px"><?= $e['code'] ?></code>
                </div>
            </div>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="index.php" class="btn btn-sm btn-outline-light">
                <i class="fas fa-arrow-left me-1"></i>Back
            </a>
            <a href="/jemc/hr/employee/print_view.php?id=<?= $id ?>" target="_blank" class="btn btn-sm btn-outline-light">
                <i class="fas fa-print me-1"></i>Print
            </a>
            <button class="btn btn-sm btn-warning fw-semibold" onclick="document.getElementById('editModal').classList.remove('d-none'); document.getElementById('editModal').style.display='flex'">
                <i class="fas fa-edit me-1"></i>Edit Profile
            </button>
        </div>
    </div>
</div>

<!-- Alerts -->
<?php if($msg): ?><div class="alert alert-success alert-dismissible fade show py-2"><i class="fas fa-check-circle me-1"></i><?= htmlspecialchars($msg) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
<?php if($err): ?><div class="alert alert-danger alert-dismissible fade show py-2"><i class="fas fa-exclamation-triangle me-1"></i><?= htmlspecialchars($err) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

<!-- Tabs -->
<ul class="nav tab-nav mb-3">
    <?php foreach(['profile'=>'👤 Profile','family'=>'👨‍👩‍👧 Family ('.count($family).')','education'=>'🎓 Education ('.count($education).')','designation'=>'📋 Designation ('.count($desigHist).')','documents'=>'📄 Documents ('.count($documents).')'] as $k=>$label): ?>
    <li class="nav-item">
        <a class="nav-link <?= $tab===$k?'active':'' ?>" href="?id=<?= $id ?>&tab=<?= $k ?>">
            <?= $label ?>
        </a>
    </li>
    <?php endforeach; ?>
</ul>

<?php if ($tab === 'profile'): ?>
<!-- ══ PROFILE TAB ══════════════════════════════════════════ -->
<div class="row g-3">
    <div class="col-md-6">
        <div class="section-card">
            <div class="section-hdr"><h6><i class="fas fa-id-card me-1 text-primary"></i>Personal Information</h6></div>
            <div class="p-3">
            <?php $pFields=[
                ['Employee Code',$e['code']],['Name (English)',$e['name_eng']??''],
                ['Name (Nepali)',$e['name_nep']??''],['Gender',$e['gender']??''],
                ['Date of Birth (AD)',$e['dob'] ? fdate($e['dob']) : ''],
                ['Date of Birth (BS)',$e['dob_nep']??''],
                ['Mobile',$e['mobile_number']??''],['Email',$e['email']??''],
                ['Address',$e['full_address']??''],['State / Province',$e['state']??''],
                ['Local Body',$e['local_body']??''],['Ward No.',$e['ward_no']??''],
                ['Citizenship No.',$e['citizenship_no']??''],['National ID',$e['national_id_card_no']??''],
                ['PAN No.',$e['pan_no']??''],['Card ID (Attendance)',$e['card_id']??''],
                ['ZKTeco ID',$e['attendance_id']??''],
            ];
            foreach($pFields as [$label,$val]): ?>
            <div class="row mb-2">
                <div class="col-5"><div class="info-label"><?= $label ?></div></div>
                <div class="col-7"><div class="info-value"><?= htmlspecialchars($val ?: 'N/A') ?></div></div>
            </div>
            <?php endforeach; ?>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="section-card">
            <div class="section-hdr"><h6><i class="fas fa-briefcase me-1 text-success"></i>Employment Details</h6></div>
            <div class="p-3">
            <?php $eFields=[
                ['Status',$e['emp_status']??''],['Type',$e['emp_type']??''],
                ['Designation',$e['designation_name']??''],['Level',($e['level_name']?"Level {$e['level_name']} — ".($e['level_title']??''):'')],
                ['Department',$e['department_name']??''],['Sub-Dept',$e['sub_department_name']??''],
                ['Join Date (AD)',$e['join_date']?fdate($e['join_date']):''],
                ['Join Date (BS)',$e['join_date_nep']??''],
                ['Initial Appointment',$e['initial_appointment_date']?fdate($e['initial_appointment_date']):''],
                ['Initial Appt. (BS)',$e['initial_appointment_date_nep']??''],
                ['Retirement Date',$e['retirement_date']?fdate($e['retirement_date']):''],
                ['Retirement Date (BS)',$e['retirement_date_nep']??''],
                ['Fiscal Year',$e['fiscal_code']??''],['Is Technical',$e['is_technical']?'Yes':'No'],
                ['SSF Enrolled',$e['is_ssf_enrolled']?'Yes':'No'],
                ['Taxpayer Type',$e['taxpayer_type']??''],
            ];
            foreach($eFields as [$label,$val]): ?>
            <div class="row mb-2">
                <div class="col-5"><div class="info-label"><?= $label ?></div></div>
                <div class="col-7"><div class="info-value"><?= htmlspecialchars($val ?: 'N/A') ?></div></div>
            </div>
            <?php endforeach; ?>
            </div>
        </div>
        <div class="section-card">
            <div class="section-hdr"><h6><i class="fas fa-university me-1 text-info"></i>Bank Details</h6></div>
            <div class="p-3">
            <?php foreach([['Bank Name',$e['bank_name']??''],['Branch',$e['bank_branch']??''],['Account No.',$e['bank_account_number']??'']] as [$l,$v]): ?>
            <div class="row mb-2">
                <div class="col-5"><div class="info-label"><?= $l ?></div></div>
                <div class="col-7"><div class="info-value"><?= htmlspecialchars($v ?: 'N/A') ?></div></div>
            </div>
            <?php endforeach; ?>
            </div>
        </div>
        <div class="section-card">
            <div class="section-hdr"><h6><i class="fas fa-clock me-1 text-secondary"></i>System Info</h6></div>
            <div class="p-3">
            <?php foreach([['Created On',fdate($e['created_date']??'')],['Created By',$e['created_by_name']??'System'],['Updated On',fdate($e['updated_date']??'')],['Updated By',$e['updated_by_name']??'']] as [$l,$v]): ?>
            <div class="row mb-2">
                <div class="col-5"><div class="info-label"><?= $l ?></div></div>
                <div class="col-7"><div class="info-value"><?= htmlspecialchars($v ?: '—') ?></div></div>
            </div>
            <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<?php elseif ($tab === 'family'): ?>
<!-- ══ FAMILY TAB ═══════════════════════════════════════════ -->
<div class="row g-3">
    <div class="col-md-8">
        <div class="section-card">
            <div class="section-hdr">
                <h6><i class="fas fa-users me-1"></i>Family Members (<?= count($family) ?>)</h6>
            </div>
            <?php if(empty($family)): ?>
            <div class="text-center py-5 text-muted"><i class="fas fa-users fa-2x mb-2 d-block opacity-25"></i>No family members added yet.</div>
            <?php else: ?>
            <div class="table-responsive">
            <table class="table table-sm table-hover mb-0" style="font-size:.83rem">
                <thead class="table-light"><tr><th>#</th><th>Name</th><th>Relation</th><th>Contact</th><th>Remarks</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach($family as $i=>$f): ?>
                <tr>
                    <td><?= $i+1 ?></td>
                    <td><strong><?= htmlspecialchars($f['name']) ?></strong></td>
                    <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($f['relation']) ?></span></td>
                    <td><?= htmlspecialchars($f['contact'] ?? '') ?></td>
                    <td><small class="text-muted"><?= htmlspecialchars($f['remarks'] ?? '') ?></small></td>
                    <td>
                        <button class="btn btn-xs btn-outline-primary me-1" style="font-size:.68rem"
                                onclick='fillFamilyForm(<?= json_encode($f) ?>)'>Edit</button>
                        <form method="POST" style="display:inline" onsubmit="return confirm('Delete?')">
                            <input type="hidden" name="action" value="delete_family">
                            <input type="hidden" name="family_id" value="<?= $f['id'] ?>">
                            <button type="submit" class="btn btn-xs btn-outline-danger" style="font-size:.68rem">Del</button>
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
    <div class="col-md-4">
        <div class="section-card">
            <div class="section-hdr"><h6 id="familyFormTitle"><i class="fas fa-plus me-1"></i>Add Family Member</h6></div>
            <div class="p-3">
            <form method="POST">
                <input type="hidden" name="action" value="add_family">
                <input type="hidden" name="family_id" id="familyId" value="0">
                <div class="mb-2"><label class="form-label fw-semibold small">Full Name <span class="text-danger">*</span></label>
                    <input type="text" name="fname" id="fname" class="form-control form-control-sm" required placeholder="e.g. Sita Sharma"></div>
                <div class="mb-2"><label class="form-label fw-semibold small">Relation <span class="text-danger">*</span></label>
                    <select name="relation" id="frelation" class="form-select form-select-sm">
                        <?php foreach(['Father','Mother','Spouse','Son','Daughter','Brother','Sister','Guardian','Other'] as $r): ?>
                        <option value="<?= $r ?>"><?= $r ?></option>
                        <?php endforeach; ?>
                    </select></div>
                <div class="mb-2"><label class="form-label fw-semibold small">Contact</label>
                    <input type="text" name="contact" id="fcontact" class="form-control form-control-sm" placeholder="Phone number"></div>
                <div class="mb-3"><label class="form-label fw-semibold small">Remarks</label>
                    <textarea name="remarks" id="fremarks" class="form-control form-control-sm" rows="2"></textarea></div>
                <button type="submit" class="btn btn-success btn-sm w-100" id="familySubmitBtn">
                    <i class="fas fa-plus me-1"></i>Add Family Member
                </button>
                <button type="button" class="btn btn-outline-secondary btn-sm w-100 mt-1 d-none" id="familyCancelBtn" onclick="resetFamilyForm()">Cancel</button>
            </form>
            </div>
        </div>
    </div>
</div>

<?php elseif ($tab === 'education'): ?>
<!-- ══ EDUCATION TAB ═════════════════════════════════════════ -->
<div class="row g-3">
    <div class="col-md-8">
        <div class="section-card">
            <div class="section-hdr"><h6><i class="fas fa-graduation-cap me-1"></i>Education (<?= count($education) ?>)</h6></div>
            <?php if(empty($education)): ?>
            <div class="text-center py-5 text-muted"><i class="fas fa-graduation-cap fa-2x mb-2 d-block opacity-25"></i>No education records added yet.</div>
            <?php else: ?>
            <div class="table-responsive">
            <table class="table table-sm table-hover mb-0" style="font-size:.83rem">
                <thead class="table-light"><tr><th>#</th><th>Degree</th><th>Institution</th><th>University/Board</th><th>Year</th><th>Marks</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach($education as $i=>$edu): ?>
                <tr>
                    <td><?= $i+1 ?></td>
                    <td><strong><?= htmlspecialchars($edu['degree_name']) ?></strong></td>
                    <td><?= htmlspecialchars($edu['institution_name']) ?></td>
                    <td><?= htmlspecialchars($edu['university'] ?? '') ?></td>
                    <td><?= $edu['completion_year'] ?></td>
                    <td><?= $edu['marks'] ? number_format($edu['marks'],1) : '' ?></td>
                    <td>
                        <button class="btn btn-xs btn-outline-primary me-1" style="font-size:.68rem"
                                onclick='fillEduForm(<?= json_encode($edu) ?>)'>Edit</button>
                        <form method="POST" style="display:inline" onsubmit="return confirm('Delete?')">
                            <input type="hidden" name="action" value="delete_education">
                            <input type="hidden" name="edu_id" value="<?= $edu['id'] ?>">
                            <button type="submit" class="btn btn-xs btn-outline-danger" style="font-size:.68rem">Del</button>
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
    <div class="col-md-4">
        <div class="section-card">
            <div class="section-hdr"><h6 id="eduFormTitle"><i class="fas fa-plus me-1"></i>Add Education</h6></div>
            <div class="p-3">
            <form method="POST">
                <input type="hidden" name="action" value="add_education">
                <input type="hidden" name="edu_id" id="eduId" value="0">
                <div class="mb-2"><label class="form-label fw-semibold small">Degree/Certificate <span class="text-danger">*</span></label>
                    <input type="text" name="degree_name" id="eDeg" class="form-control form-control-sm" required placeholder="e.g. B.A., SLC, +2"></div>
                <div class="mb-2"><label class="form-label fw-semibold small">Institution <span class="text-danger">*</span></label>
                    <input type="text" name="institution_name" id="eInst" class="form-control form-control-sm" required></div>
                <div class="mb-2"><label class="form-label fw-semibold small">University / Board</label>
                    <input type="text" name="university" id="eUni" class="form-control form-control-sm"></div>
                <div class="row g-2 mb-2">
                    <div class="col-6"><label class="form-label fw-semibold small">Year</label>
                        <input type="number" name="completion_year" id="eYr" class="form-control form-control-sm" min="1950" max="2100" placeholder="2075"></div>
                    <div class="col-6"><label class="form-label fw-semibold small">Marks/%</label>
                        <input type="number" name="marks" id="eMrk" class="form-control form-control-sm" step="0.01" placeholder="3.6 or 75"></div>
                </div>
                <div class="mb-2"><label class="form-label fw-semibold small">Order</label>
                    <input type="number" name="display_order" id="eOrd" class="form-control form-control-sm" value="0"></div>
                <div class="mb-3"><label class="form-label fw-semibold small">Remarks</label>
                    <textarea name="remarks" id="eRem" class="form-control form-control-sm" rows="2"></textarea></div>
                <button type="submit" class="btn btn-success btn-sm w-100" id="eduSubmitBtn"><i class="fas fa-plus me-1"></i>Add Education</button>
                <button type="button" class="btn btn-outline-secondary btn-sm w-100 mt-1 d-none" id="eduCancelBtn" onclick="resetEduForm()">Cancel</button>
            </form>
            </div>
        </div>
    </div>
</div>

<?php elseif ($tab === 'designation'): ?>
<!-- ══ DESIGNATION HISTORY TAB ══════════════════════════════ -->
<div class="row g-3">
    <div class="col-md-8">
        <div class="section-card">
            <div class="section-hdr"><h6><i class="fas fa-sitemap me-1"></i>Designation History (<?= count($desigHist) ?>)</h6></div>
            <?php if(empty($desigHist)): ?>
            <div class="text-center py-5 text-muted"><i class="fas fa-sitemap fa-2x mb-2 d-block opacity-25"></i>No designation history added yet.</div>
            <?php else: ?>
            <div class="table-responsive">
            <table class="table table-sm table-hover mb-0" style="font-size:.83rem">
                <thead class="table-light"><tr><th>#</th><th>Date of Joining</th><th>Designation</th><th>Level</th><th>Department</th><th>Status</th><th>Remarks</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach($desigHist as $i=>$dh): ?>
                <tr>
                    <td><?= $i+1 ?></td>
                    <td><?= $dh['date_of_join'] ? fdate($dh['date_of_join']) : '—' ?></td>
                    <td><strong><?= htmlspecialchars($dh['designation_name'] ?? '') ?></strong></td>
                    <td><?= htmlspecialchars($dh['level_name'] ?? '') ?></td>
                    <td><small><?= htmlspecialchars($dh['dept_name'] ?? '') ?></small></td>
                    <td>
                        <span class="badge <?= $dh['status']==='CURRENT'?'bg-success':'bg-secondary' ?>" style="font-size:.62rem">
                            <?= $dh['status'] ?>
                        </span>
                    </td>
                    <td><small class="text-muted"><?= htmlspecialchars($dh['remarks'] ?? '') ?></small></td>
                    <td>
                        <button class="btn btn-xs btn-outline-primary me-1" style="font-size:.68rem"
                                onclick='fillDesigForm(<?= json_encode($dh) ?>)'>Edit</button>
                        <form method="POST" style="display:inline" onsubmit="return confirm('Delete?')">
                            <input type="hidden" name="action" value="delete_designation">
                            <input type="hidden" name="desig_hist_id" value="<?= $dh['id'] ?>">
                            <button type="submit" class="btn btn-xs btn-outline-danger" style="font-size:.68rem">Del</button>
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
    <div class="col-md-4">
        <div class="section-card">
            <div class="section-hdr"><h6 id="desigFormTitle"><i class="fas fa-plus me-1"></i>Add Designation Record</h6></div>
            <div class="p-3">
            <form method="POST">
                <input type="hidden" name="action" value="add_designation">
                <input type="hidden" name="desig_hist_id" id="desigHistId" value="0">
                <div class="mb-2"><label class="form-label fw-semibold small">Date of Joining</label>
                    <input type="date" name="date_of_join" id="dhDoj" class="form-control form-control-sm"></div>
                <div class="mb-2"><label class="form-label fw-semibold small">Designation</label>
                    <select name="designation_id" id="dhDesig" class="form-select form-select-sm">
                        <option value="">— Select —</option>
                        <?php foreach($designations as $d): ?>
                        <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option>
                        <?php endforeach; ?>
                    </select></div>
                <div class="mb-2"><label class="form-label fw-semibold small">Level</label>
                    <select name="level_id" id="dhLevel" class="form-select form-select-sm">
                        <option value="">— Select —</option>
                        <?php foreach($levels as $l): ?>
                        <option value="<?= $l['id'] ?>">L<?= $l['name'] ?> — <?= htmlspecialchars($l['remarks'] ?? '') ?></option>
                        <?php endforeach; ?>
                    </select></div>
                <div class="mb-2"><label class="form-label fw-semibold small">Department</label>
                    <select name="department_id" id="dhDept" class="form-select form-select-sm">
                        <option value="">— Select —</option>
                        <?php foreach($departments as $dep): ?>
                        <option value="<?= $dep['id'] ?>"><?= htmlspecialchars($dep['name']) ?></option>
                        <?php endforeach; ?>
                    </select></div>
                <div class="mb-2"><label class="form-label fw-semibold small">Status</label>
                    <select name="status" id="dhStatus" class="form-select form-select-sm">
                        <option value="CURRENT">Current</option>
                        <option value="PAST">Past</option>
                        <option value="TRANSFERRED">Transferred</option>
                    </select></div>
                <div class="mb-3"><label class="form-label fw-semibold small">Remarks</label>
                    <textarea name="remarks" id="dhRem" class="form-control form-control-sm" rows="2"></textarea></div>
                <button type="submit" class="btn btn-success btn-sm w-100" id="desigSubmitBtn"><i class="fas fa-plus me-1"></i>Add Record</button>
                <button type="button" class="btn btn-outline-secondary btn-sm w-100 mt-1 d-none" id="desigCancelBtn" onclick="resetDesigForm()">Cancel</button>
            </form>
            </div>
        </div>
    </div>
</div>

<?php elseif ($tab === 'documents'): ?>
<!-- ══ DOCUMENTS TAB ════════════════════════════════════════ -->
<div class="row g-3">
    <div class="col-md-7">
        <div class="section-card">
            <div class="section-hdr"><h6><i class="fas fa-folder-open me-1"></i>Documents (<?= count($documents) ?>)</h6></div>
            <div class="p-3">
            <?php if(empty($documents)): ?>
            <div class="text-center py-5 text-muted"><i class="fas fa-folder-open fa-2x mb-2 d-block opacity-25"></i>No documents uploaded yet.</div>
            <?php else: foreach($documents as $doc):
                $ext  = strtolower(pathinfo($doc['file_path'], PATHINFO_EXTENSION));
                $iconMap = ['pdf'=>['fas fa-file-pdf','#e74c3c'],'jpg'=>['fas fa-file-image','#3498db'],'jpeg'=>['fas fa-file-image','#3498db'],'png'=>['fas fa-file-image','#3498db'],'gif'=>['fas fa-file-image','#3498db'],'doc'=>['fas fa-file-word','#2980b9'],'docx'=>['fas fa-file-word','#2980b9'],'xls'=>['fas fa-file-excel','#27ae60'],'xlsx'=>['fas fa-file-excel','#27ae60']];
                $icon = $iconMap[$ext] ?? ['fas fa-file','#7f8c8d'];
            ?>
            <div class="doc-card">
                <div class="doc-icon" style="background:<?= $icon[1] ?>22;color:<?= $icon[1] ?>">
                    <i class="<?= $icon[0] ?>"></i>
                </div>
                <div class="flex-grow-1">
                    <div class="fw-semibold" style="font-size:.85rem"><?= htmlspecialchars($doc['document_name']) ?></div>
                    <div class="d-flex gap-2 flex-wrap mt-1">
                        <span class="badge bg-light text-dark border" style="font-size:.65rem"><?= $doc['document_type'] ?></span>
                        <span class="text-muted" style="font-size:.7rem"><?= $doc['file_size'] ? round($doc['file_size']/1024,1).' KB' : '' ?></span>
                        <span class="text-muted" style="font-size:.7rem"><?= $doc['created_date'] ? date('d M Y', strtotime($doc['created_date'])) : '' ?></span>
                    </div>
                    <?php if($doc['description']): ?>
                    <div class="text-muted mt-1" style="font-size:.75rem"><?= htmlspecialchars($doc['description']) ?></div>
                    <?php endif; ?>
                </div>
                <div class="d-flex gap-1 flex-column">
                    <a href="/jemc/<?= htmlspecialchars($doc['file_path']) ?>" target="_blank"
                       class="btn btn-xs btn-outline-primary" style="font-size:.68rem;padding:2px 8px">
                        <i class="fas fa-eye"></i> View
                    </a>
                    <a href="/jemc/<?= htmlspecialchars($doc['file_path']) ?>" download
                       class="btn btn-xs btn-outline-success" style="font-size:.68rem;padding:2px 8px">
                        <i class="fas fa-download"></i>
                    </a>
                    <form method="POST" onsubmit="return confirm('Delete this document?')">
                        <input type="hidden" name="action" value="delete_document">
                        <input type="hidden" name="doc_id" value="<?= $doc['id'] ?>">
                        <button type="submit" class="btn btn-xs btn-outline-danger w-100" style="font-size:.68rem;padding:2px 8px">
                            <i class="fas fa-trash"></i>
                        </button>
                    </form>
                </div>
            </div>
            <?php endforeach; endif; ?>
            </div>
        </div>
    </div>
    <div class="col-md-5">
        <div class="section-card">
            <div class="section-hdr"><h6><i class="fas fa-upload me-1"></i>Upload Documents</h6></div>
            <div class="p-3">
            <form method="POST" enctype="multipart/form-data" id="uploadForm">
                <input type="hidden" name="action" value="upload_document">
                <!-- Drop zone -->
                <div class="upload-zone mb-3" id="dropZone" onclick="document.getElementById('fileInput').click()">
                    <i class="fas fa-cloud-upload-alt fa-2x mb-2" style="color:#8492a6"></i>
                    <div class="fw-semibold" style="font-size:.85rem">Click or drag files here</div>
                    <small class="text-muted">PDF, Word, Excel, Images — max 10MB each</small>
                    <input type="file" id="fileInput" name="documents[]" multiple
                           accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.gif"
                           style="display:none" onchange="showFileRows(this.files)">
                </div>

                <!-- Dynamic file rows -->
                <div id="fileRows"></div>

                <button type="submit" class="btn btn-success w-100 mt-2" id="uploadBtn" style="display:none">
                    <i class="fas fa-upload me-1"></i>Upload Documents
                </button>
            </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

</div><!-- /container -->

<!-- ══ EDIT PROFILE MODAL (full-screen overlay) ══════════════════ -->
<div id="editModal" class="d-none" style="position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9999;display:flex;align-items:flex-start;justify-content:center;overflow-y:auto;padding:20px">
    <div style="background:#fff;border-radius:12px;width:100%;max-width:1000px;padding:0;overflow:hidden">
        <div style="background:#2c3e8c;color:#fff;padding:1rem 1.5rem;display:flex;justify-content:space-between;align-items:center">
            <h5 class="mb-0"><i class="fas fa-edit me-2"></i>Edit Employee Profile — <?= htmlspecialchars($e['name']) ?></h5>
            <button type="button" onclick="document.getElementById('editModal').style.display='none'" style="background:none;border:none;color:#fff;font-size:1.2rem">&times;</button>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="edit_profile">
            <div style="padding:1.5rem;max-height:80vh;overflow-y:auto">

                <!-- Photo upload -->
                <div class="text-center mb-4">
                    <div class="position-relative d-inline-block">
                        <?php if(!empty($e['picture'])): ?>
                        <img src="/jemc/<?= htmlspecialchars($e['picture']) ?>" style="width:80px;height:80px;border-radius:50%;object-fit:cover;border:3px solid #2c3e8c" alt="">
                        <?php else: ?>
                        <div style="width:80px;height:80px;border-radius:50%;background:#e8eaf6;border:3px solid #2c3e8c;display:flex;align-items:center;justify-content:center;font-size:2rem;color:#2c3e8c">
                            <?= strtoupper(substr($e['name'],0,1)) ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="mt-2">
                        <label class="btn btn-sm btn-outline-primary"><i class="fas fa-camera me-1"></i>Change Photo <input type="file" name="picture" accept="image/*" style="display:none"></label>
                    </div>
                </div>

                <div class="row g-3">
                    <!-- Personal -->
                    <div class="col-12"><h6 class="fw-bold" style="color:#2c3e8c;border-bottom:2px solid #e8eaf6;padding-bottom:.3rem"><i class="fas fa-user me-1"></i>Personal Information</h6></div>
                    <div class="col-md-4"><label class="form-label fw-semibold small">Full Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control form-control-sm" value="<?= htmlspecialchars($e['name']) ?>" required></div>
                    <div class="col-md-4"><label class="form-label fw-semibold small">Name (English)</label>
                        <input type="text" name="name_eng" class="form-control form-control-sm" value="<?= htmlspecialchars($e['name_eng']??'') ?>"></div>
                    <div class="col-md-4"><label class="form-label fw-semibold small">Name (Nepali)</label>
                        <input type="text" name="name_nep" class="form-control form-control-sm" value="<?= htmlspecialchars($e['name_nep']??'') ?>"></div>
                    <div class="col-md-3"><label class="form-label fw-semibold small">Gender</label>
                        <select name="gender" class="form-select form-select-sm">
                            <option value="">—</option>
                            <?php foreach(['Male','Female','Other'] as $g): ?>
                            <option value="<?= $g ?>" <?= $e['gender']===$g?'selected':'' ?>><?= $g ?></option>
                            <?php endforeach; ?>
                        </select></div>
                    <div class="col-md-3"><label class="form-label fw-semibold small">DOB (BS) <small class="text-muted">— BS calendar</small></label>
                        <input type="text" name="dob_nep" id="dob_nep" class="form-control form-control-sm bs-date" data-ad-pair="dob_ad" value="<?= htmlspecialchars($e['dob_nep']??'') ?>" placeholder="YYYY.MM.DD">
                        <span class="bs-date-label"></span></div>
                    <div class="col-md-3"><label class="form-label fw-semibold small">DOB (AD) <small class="text-muted">— auto-fills</small></label>
                        <input type="date" name="dob" id="dob_ad" class="form-control form-control-sm" value="<?= $e['dob']??'' ?>"></div>
                    <div class="col-md-3"><label class="form-label fw-semibold small">Mobile</label>
                        <input type="text" name="mobile_number" class="form-control form-control-sm" value="<?= htmlspecialchars($e['mobile_number']??'') ?>"></div>
                    <div class="col-md-4"><label class="form-label fw-semibold small">Email</label>
                        <input type="email" name="email" class="form-control form-control-sm" value="<?= htmlspecialchars($e['email']??'') ?>"></div>
                    <div class="col-md-4"><label class="form-label fw-semibold small">Citizenship No.</label>
                        <input type="text" name="citizenship_no" class="form-control form-control-sm" value="<?= htmlspecialchars($e['citizenship_no']??'') ?>"></div>
                    <div class="col-md-4"><label class="form-label fw-semibold small">National ID</label>
                        <input type="text" name="national_id_card_no" class="form-control form-control-sm" value="<?= htmlspecialchars($e['national_id_card_no']??'') ?>"></div>
                    <div class="col-md-4"><label class="form-label fw-semibold small">PAN No.</label>
                        <input type="text" name="pan_no" class="form-control form-control-sm" value="<?= htmlspecialchars($e['pan_no']??'') ?>"></div>
                    <div class="col-md-4"><label class="form-label fw-semibold small">Card ID</label>
                        <input type="text" name="card_id" class="form-control form-control-sm" value="<?= htmlspecialchars($e['card_id']??'') ?>"></div>
                    <div class="col-md-4"><label class="form-label fw-semibold small">ZKTeco Attendance ID</label>
                        <input type="text" name="attendance_id" class="form-control form-control-sm" value="<?= htmlspecialchars($e['attendance_id']??'') ?>"></div>
                    <div class="col-md-12"><label class="form-label fw-semibold small">Full Address</label>
                        <input type="text" name="full_address" class="form-control form-control-sm" value="<?= htmlspecialchars($e['full_address']??'') ?>"></div>
                    <div class="col-md-4"><label class="form-label fw-semibold small">State / Province</label>
                        <input type="text" name="state" class="form-control form-control-sm" value="<?= htmlspecialchars($e['state']??'') ?>"></div>
                    <div class="col-md-4"><label class="form-label fw-semibold small">Local Body</label>
                        <input type="text" name="local_body" class="form-control form-control-sm" value="<?= htmlspecialchars($e['local_body']??'') ?>"></div>
                    <div class="col-md-4"><label class="form-label fw-semibold small">Ward No.</label>
                        <input type="text" name="ward_no" class="form-control form-control-sm" value="<?= htmlspecialchars($e['ward_no']??'') ?>"></div>

                    <!-- Employment -->
                    <div class="col-12 mt-2"><h6 class="fw-bold" style="color:#1a9e5f;border-bottom:2px solid #e8eaf6;padding-bottom:.3rem"><i class="fas fa-briefcase me-1"></i>Employment Details</h6></div>
                    <div class="col-md-3"><label class="form-label fw-semibold small">Status <span class="text-danger">*</span></label>
                        <select name="emp_status" class="form-select form-select-sm">
                            <?php foreach(['ACTIVE','INACTIVE','DRAFT','RETIRED','TERMINATED'] as $s): ?>
                            <option value="<?= $s ?>" <?= $e['emp_status']===$s?'selected':'' ?>><?= $s ?></option>
                            <?php endforeach; ?>
                        </select></div>
                    <div class="col-md-3"><label class="form-label fw-semibold small">Type <span class="text-danger">*</span></label>
                        <select name="emp_type" class="form-select form-select-sm">
                            <?php foreach(['PERMANENT','CONTRACT','DAILY_WAGES'] as $t): ?>
                            <option value="<?= $t ?>" <?= $e['emp_type']===$t?'selected':'' ?>><?= $t ?></option>
                            <?php endforeach; ?>
                        </select></div>
                    <div class="col-md-3"><label class="form-label fw-semibold small">Designation</label>
                        <select name="designation_id" class="form-select form-select-sm">
                            <option value="">— Select —</option>
                            <?php foreach($designations as $d): ?>
                            <option value="<?= $d['id'] ?>" <?= $e['designation_id']==$d['id']?'selected':'' ?>><?= htmlspecialchars($d['name']) ?></option>
                            <?php endforeach; ?>
                        </select></div>
                    <div class="col-md-3"><label class="form-label fw-semibold small">Level</label>
                        <select name="level_id" class="form-select form-select-sm">
                            <option value="">— Select —</option>
                            <?php foreach($levels as $l): ?>
                            <option value="<?= $l['id'] ?>" <?= $e['level_id']==$l['id']?'selected':'' ?>>L<?= $l['name'] ?> — <?= htmlspecialchars($l['remarks']??'') ?></option>
                            <?php endforeach; ?>
                        </select></div>
                    <div class="col-md-6"><label class="form-label fw-semibold small">Department</label>
                        <select name="department_id" class="form-select form-select-sm">
                            <option value="">— Select —</option>
                            <?php foreach($departments as $dep): ?>
                            <option value="<?= $dep['id'] ?>" <?= $e['department_id']==$dep['id']?'selected':'' ?>><?= htmlspecialchars($dep['name']) ?></option>
                            <?php endforeach; ?>
                        </select></div>
                    <div class="col-md-3"><label class="form-label fw-semibold small">Fiscal Year</label>
                        <select name="fiscal_year_id" class="form-select form-select-sm">
                            <option value="">—</option>
                            <?php foreach($fiscalYears as $fy): ?>
                            <option value="<?= $fy['id'] ?>" <?= $e['fiscal_year_id']==$fy['id']?'selected':'' ?>><?= $fy['fiscal_code'] ?></option>
                            <?php endforeach; ?>
                        </select></div>
                    <div class="col-md-3">
                        <div class="form-check mt-4">
                            <input type="checkbox" name="is_technical" class="form-check-input" id="isTech" <?= $e['is_technical']?'checked':'' ?>>
                            <label class="form-check-label fw-semibold" for="isTech" style="font-size:.8rem">Technical Staff</label>
                        </div>
                        <div class="form-check">
                            <input type="checkbox" name="is_ssf_enrolled" class="form-check-input" id="isSSF" <?= $e['is_ssf_enrolled']?'checked':'' ?>>
                            <label class="form-check-label fw-semibold" for="isSSF" style="font-size:.8rem">SSF Enrolled</label>
                        </div>
                    </div>
                    <div class="col-md-3"><label class="form-label fw-semibold small">Join Date (BS)</label>
                        <input type="text" name="join_date_nep" class="form-control form-control-sm bs-date" data-ad-pair="join_date_ad" value="<?= htmlspecialchars($e['join_date_nep']??'') ?>" placeholder="YYYY.MM.DD">
                        <span class="bs-date-label"></span></div>
                    <div class="col-md-3"><label class="form-label fw-semibold small">Join Date (AD) <small class="text-muted">auto</small></label>
                        <input type="date" name="join_date" id="join_date_ad" class="form-control form-control-sm" value="<?= $e['join_date']??'' ?>"></div>
                    <div class="col-md-3"><label class="form-label fw-semibold small">Initial Appt. (BS)</label>
                        <input type="text" name="initial_appointment_date_nep" class="form-control form-control-sm bs-date" data-ad-pair="initial_appt_ad" value="<?= htmlspecialchars($e['initial_appointment_date_nep']??'') ?>" placeholder="YYYY.MM.DD">
                        <span class="bs-date-label"></span></div>
                    <div class="col-md-3"><label class="form-label fw-semibold small">Initial Appt. (AD) <small class="text-muted">auto</small></label>
                        <input type="date" name="initial_appointment_date" id="initial_appt_ad" class="form-control form-control-sm" value="<?= $e['initial_appointment_date']??'' ?>"></div>
                    <div class="col-md-3"><label class="form-label fw-semibold small">Retirement Date (BS)</label>
                        <input type="text" name="retirement_date_nep" class="form-control form-control-sm bs-date" data-ad-pair="retirement_ad" value="<?= htmlspecialchars($e['retirement_date_nep']??'') ?>" placeholder="YYYY.MM.DD">
                        <span class="bs-date-label"></span></div>
                    <div class="col-md-3"><label class="form-label fw-semibold small">Retirement Date (AD) <small class="text-muted">auto</small></label>
                        <input type="date" name="retirement_date" id="retirement_ad" class="form-control form-control-sm" value="<?= $e['retirement_date']??'' ?>"></div>
                    <div class="col-md-3"><label class="form-label fw-semibold small">Taxpayer Type</label>
                        <select name="taxpayer_type" class="form-select form-select-sm">
                            <option value="SINGLE" <?= ($e['taxpayer_type']??'')!=='COUPLE'?'selected':'' ?>>Single</option>
                            <option value="COUPLE" <?= ($e['taxpayer_type']??'')==='COUPLE'?'selected':'' ?>>Couple (married)</option>
                        </select></div>

                    <!-- Bank -->
                    <div class="col-12 mt-2"><h6 class="fw-bold" style="color:#6c5ce7;border-bottom:2px solid #e8eaf6;padding-bottom:.3rem"><i class="fas fa-university me-1"></i>Bank Details</h6></div>
                    <div class="col-md-4"><label class="form-label fw-semibold small">Bank Name</label>
                        <input type="text" name="bank_name" class="form-control form-control-sm" value="<?= htmlspecialchars($e['bank_name']??'') ?>"></div>
                    <div class="col-md-4"><label class="form-label fw-semibold small">Branch</label>
                        <input type="text" name="bank_branch" class="form-control form-control-sm" value="<?= htmlspecialchars($e['bank_branch']??'') ?>"></div>
                    <div class="col-md-4"><label class="form-label fw-semibold small">Account Number</label>
                        <input type="text" name="bank_account_number" class="form-control form-control-sm" value="<?= htmlspecialchars($e['bank_account_number']??'') ?>"></div>
                </div>
            </div>
            <div style="padding:1rem 1.5rem;border-top:1px solid #f0f2f8;display:flex;gap:.5rem;justify-content:flex-end">
                <button type="button" onclick="document.getElementById('editModal').style.display='none'" class="btn btn-secondary btn-sm">Cancel</button>
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-save me-1"></i>Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ── Family form fill ─────────────────────────────────
function fillFamilyForm(f) {
    document.getElementById('familyId').value = f.id;
    document.getElementById('fname').value    = f.name;
    document.getElementById('frelation').value= f.relation;
    document.getElementById('fcontact').value = f.contact || '';
    document.getElementById('fremarks').value = f.remarks || '';
    document.getElementById('familyFormTitle').innerHTML = '<i class="fas fa-edit me-1"></i>Edit Family Member';
    document.getElementById('familySubmitBtn').innerHTML = '<i class="fas fa-save me-1"></i>Update';
    document.getElementById('familyCancelBtn').classList.remove('d-none');
    window.scrollTo({top: document.getElementById('fname').offsetTop - 100, behavior:'smooth'});
}
function resetFamilyForm() {
    document.getElementById('familyId').value = '0';
    document.getElementById('fname').value = document.getElementById('frelation').value = document.getElementById('fcontact').value = document.getElementById('fremarks').value = '';
    document.getElementById('familyFormTitle').innerHTML = '<i class="fas fa-plus me-1"></i>Add Family Member';
    document.getElementById('familySubmitBtn').innerHTML = '<i class="fas fa-plus me-1"></i>Add Family Member';
    document.getElementById('familyCancelBtn').classList.add('d-none');
}

// ── Education form fill ──────────────────────────────
function fillEduForm(e) {
    document.getElementById('eduId').value = e.id;
    document.getElementById('eDeg').value  = e.degree_name;
    document.getElementById('eInst').value = e.institution_name;
    document.getElementById('eUni').value  = e.university || '';
    document.getElementById('eYr').value   = e.completion_year || '';
    document.getElementById('eMrk').value  = e.marks || '';
    document.getElementById('eOrd').value  = e.display_order || 0;
    document.getElementById('eRem').value  = e.remarks || '';
    document.getElementById('eduFormTitle').innerHTML = '<i class="fas fa-edit me-1"></i>Edit Education';
    document.getElementById('eduSubmitBtn').innerHTML = '<i class="fas fa-save me-1"></i>Update';
    document.getElementById('eduCancelBtn').classList.remove('d-none');
}
function resetEduForm() {
    ['eduId','eDeg','eInst','eUni','eYr','eMrk','eRem'].forEach(id => document.getElementById(id).value = id==='eduId'?'0':'');
    document.getElementById('eOrd').value = '0';
    document.getElementById('eduFormTitle').innerHTML = '<i class="fas fa-plus me-1"></i>Add Education';
    document.getElementById('eduSubmitBtn').innerHTML = '<i class="fas fa-plus me-1"></i>Add Education';
    document.getElementById('eduCancelBtn').classList.add('d-none');
}

// ── Designation form fill ────────────────────────────
function fillDesigForm(d) {
    document.getElementById('desigHistId').value = d.id;
    document.getElementById('dhDoj').value    = d.date_of_join || '';
    document.getElementById('dhDesig').value  = d.designation_id || '';
    document.getElementById('dhLevel').value  = d.level_id || '';
    document.getElementById('dhDept').value   = d.department_id || '';
    document.getElementById('dhStatus').value = d.status || 'CURRENT';
    document.getElementById('dhRem').value    = d.remarks || '';
    document.getElementById('desigFormTitle').innerHTML = '<i class="fas fa-edit me-1"></i>Edit Designation';
    document.getElementById('desigSubmitBtn').innerHTML = '<i class="fas fa-save me-1"></i>Update';
    document.getElementById('desigCancelBtn').classList.remove('d-none');
}
function resetDesigForm() {
    document.getElementById('desigHistId').value = '0';
    ['dhDoj','dhRem'].forEach(id => document.getElementById(id).value = '');
    ['dhDesig','dhLevel','dhDept'].forEach(id => document.getElementById(id).value = '');
    document.getElementById('dhStatus').value = 'CURRENT';
    document.getElementById('desigFormTitle').innerHTML = '<i class="fas fa-plus me-1"></i>Add Designation Record';
    document.getElementById('desigSubmitBtn').innerHTML = '<i class="fas fa-plus me-1"></i>Add Record';
    document.getElementById('desigCancelBtn').classList.add('d-none');
}

// ── Document upload ──────────────────────────────────
const docTypes = <?= json_encode($docTypes) ?>;

function showFileRows(files) {
    const container = document.getElementById('fileRows');
    const btn       = document.getElementById('uploadBtn');
    container.innerHTML = '';
    if (!files.length) { btn.style.display='none'; return; }
    btn.style.display='block';

    Array.from(files).forEach((file, i) => {
        const div = document.createElement('div');
        div.className = 'doc-row-input';
        div.innerHTML = `
            <i class="fas fa-file" style="color:#8492a6;width:16px"></i>
            <div style="flex:1;min-width:0">
                <input type="text" name="doc_names[]" class="form-control form-control-sm mb-1"
                       placeholder="Document name" value="${file.name.replace(/\.[^.]+$/, '')}">
                <div class="d-flex gap-1">
                    <select name="doc_types[]" class="form-select form-select-sm">
                        ${Object.entries(docTypes).map(([k,v])=>`<option value="${k}">${v}</option>`).join('')}
                    </select>
                    <input type="text" name="doc_descs[]" class="form-control form-control-sm"
                           placeholder="Description (optional)">
                </div>
            </div>
            <small class="text-muted" style="white-space:nowrap">${(file.size/1024).toFixed(1)} KB</small>
        `;
        container.appendChild(div);
    });
}

// Drag & drop
const dz = document.getElementById('dropZone');
if (dz) {
    dz.addEventListener('dragover',  e => { e.preventDefault(); dz.classList.add('drag-over'); });
    dz.addEventListener('dragleave', () => dz.classList.remove('drag-over'));
    dz.addEventListener('drop', e => {
        e.preventDefault(); dz.classList.remove('drag-over');
        const fi = document.getElementById('fileInput');
        fi.files = e.dataTransfer.files;
        showFileRows(fi.files);
    });
}

// ── Edit modal close on backdrop ─────────────────────
document.getElementById('editModal').addEventListener('click', function(e) {
    if (e.target === this) this.style.display = 'none';
});
</script>
</body></html>
<?php ob_end_flush(); ?>
