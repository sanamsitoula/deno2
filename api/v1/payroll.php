<?php
require_once __DIR__ . '/_middleware.php';

use Administrator\Deno2\Core\{Auth, Response, Validator};
use Administrator\Deno2\Payroll\{PayrollRepository, PayrollService, PayslipGenerator};

Auth::requireModule('payroll');

$repo = new PayrollRepository($db);

switch ($_SERVER['REQUEST_METHOD']) {

    // GET /api/v1/payroll.php                 → list all headers
    // GET /api/v1/payroll.php?id=N            → single header with details
    // GET /api/v1/payroll.php?slip=N          → download payslip PDF
    case 'GET':
        if (isset($_GET['slip'])) {
            $gen = new PayslipGenerator($db);
            $gen->outputSingle((int) $_GET['slip']);
            // outputSingle() exits
        }

        if (isset($_GET['id'])) {
            $header  = $repo->findHeaderById((int) $_GET['id']);
            if (!$header) {
                Response::notFound('Payroll run not found');
            }
            $details = $repo->findDetailsByHeader((int) $_GET['id']);
            Response::success(['header' => $header, 'details' => $details]);
        }

        $headers = $repo->findAllHeaders(24);
        Response::success($headers);
        break;

    // POST /api/v1/payroll.php  body: {month, year, fiscal_year_id}
    case 'POST':
        $body = json_decode(file_get_contents('php://input'), true) ?? [];

        $v = (new Validator())
            ->required('month', $body['month'] ?? '')
            ->required('year',  $body['year']  ?? '')
            ->required('fiscal_year_id', $body['fiscal_year_id'] ?? '');

        if ($v->fails()) {
            Response::error('Validation failed', 422, $v->errors());
        }

        $userId = $_SESSION['user_id'] ?? 0;
        try {
            $service = new PayrollService($db);
            $ppId = $service->generatePayroll(
                (int) $body['month'],
                (int) $body['year'],
                (int) $body['fiscal_year_id'],
                $userId
            );
            Response::success(['payroll_processing_id' => $ppId], 'Payroll generated', 201);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), 409);
        }
        break;

    // GET /api/v1/payroll.php?bulk=N → bulk payslip PDF for a run
    default:
        if (isset($_GET['bulk'])) {
            $gen = new PayslipGenerator($db);
            $gen->outputBulk((int) $_GET['bulk']);
        }
        Response::error('Method not allowed', 405);
}
