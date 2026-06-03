<?php

namespace Administrator\Deno2\Core;

class Response
{
    public static function success($data = null, string $message = 'Success', int $code = 200): void
    {
        http_response_code($code);
        echo json_encode([
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ]);
        exit();
    }

    public static function error(string $message, int $code = 400, $errors = null): void
    {
        $body = ['success' => false, 'message' => $message];
        if ($errors !== null) {
            $body['errors'] = $errors;
        }
        http_response_code($code);
        echo json_encode($body);
        exit();
    }

    public static function paginated(array $items, int $total, int $page, int $perPage): void
    {
        self::success([
            'items'      => $items,
            'pagination' => [
                'total'    => $total,
                'page'     => $page,
                'per_page' => $perPage,
                'pages'    => (int) ceil($total / max(1, $perPage)),
            ],
        ]);
    }

    public static function notFound(string $message = 'Not found'): void
    {
        self::error($message, 404);
    }

    public static function unauthorized(string $message = 'Unauthorized'): void
    {
        self::error($message, 401);
    }

    public static function forbidden(string $message = 'Forbidden'): void
    {
        self::error($message, 403);
    }
}
