<?php
require_once __DIR__ . '/_middleware.php';

use Administrator\Deno2\Core\{Auth, Response, Validator};
use Administrator\Deno2\HR\{EmployeeRepository, EmployeeService};

Auth::requireModule('employee');

$repo    = new EmployeeRepository($db);
$service = new EmployeeService($repo, $db);

switch ($_SERVER['REQUEST_METHOD']) {

    case 'GET':
        if (isset($_GET['id'])) {
            $profile = $service->getProfile((int) $_GET['id']);
            $profile
                ? Response::success($profile)
                : Response::notFound('Employee not found');
        } else {
            $filters = array_intersect_key($_GET, array_flip([
                'search', 'department_id', 'designation_id', 'level_id',
                'emp_status', 'emp_type', 'is_technical', 'state', 'fiscal_year_id',
            ]));
            $page    = max(1, (int) ($_GET['page'] ?? 1));
            $perPage = min(100, max(1, (int) ($_GET['per_page'] ?? 50)));
            $result  = $service->listPage($filters, $page, $perPage);
            Response::paginated($result['items'], $result['total'], $page, $perPage);
        }
        break;

    case 'DELETE':
        Auth::requireModule('hr');
        $id = (int) ($_GET['id'] ?? 0);
        if (!$id) {
            Response::error('id is required', 400);
        }
        $userId = $_SESSION['user_id'] ?? 0;
        $repo->softDelete($id, $userId)
            ? Response::success(null, 'Employee deleted')
            : Response::error('Employee not found or already deleted', 404);
        break;

    default:
        Response::error('Method not allowed', 405);
}
