<?php
require_once __DIR__ . '/_middleware.php';

use Administrator\Deno2\Core\{Auth, Response, Validator};
use Administrator\Deno2\Attendance\{AttendanceRepository, ZKTecoService};

Auth::requireModule('attendance');

$repo = new AttendanceRepository($db);

switch ($_SERVER['REQUEST_METHOD']) {

    case 'GET':
        // ?summary=YYYY.MM.DD → daily summary
        if (isset($_GET['summary'])) {
            Response::success($repo->getDailySummary($_GET['summary']));
        }
        // ?from=YYYY.MM.DD&to=YYYY.MM.DD[&employee_id=N]
        if (isset($_GET['from'], $_GET['to'])) {
            $records = $repo->findByDateRange(
                $_GET['from'],
                $_GET['to'],
                isset($_GET['employee_id']) ? (int) $_GET['employee_id'] : null
            );
            Response::success($records);
        }
        Response::error('Provide ?from=&to= or ?summary= parameters', 400);
        break;

    case 'POST':
        $body = json_decode(file_get_contents('php://input'), true) ?? [];

        // Trigger ZKTeco pull
        if (($body['action'] ?? '') === 'zkteco_pull') {
            Auth::requireModule('admin');
            $zk  = new ZKTecoService($db);
            $cmd = $zk->triggerAsyncPull(
                $body['schedule'] ?? 'manual',
                $body['date']     ?? null,
                isset($body['device_id']) ? (int) $body['device_id'] : null
            );
            Response::success(['command' => $cmd], 'ZKTeco pull triggered');
        }

        // Manual attendance upsert
        $v = (new Validator())
            ->required('employee_id',         $body['employee_id']         ?? '')
            ->required('attendance_date_nep',  $body['attendance_date_nep'] ?? '')
            ->nepaliDate('attendance_date_nep', $body['attendance_date_nep'] ?? '')
            ->required('status_id',            $body['status_id']           ?? '');

        if ($v->fails()) {
            Response::error('Validation failed', 422, $v->errors());
        }

        $body['marked_by'] = $_SESSION['user_id'] ?? 0;
        $ok = $repo->upsert($body);
        $ok
            ? Response::success(null, 'Attendance saved')
            : Response::error('Failed to save attendance');
        break;

    default:
        Response::error('Method not allowed', 405);
}
