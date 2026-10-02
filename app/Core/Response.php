<?php
// app/Core/Response.php

namespace App\Core;

class Response
{
    public static function json(array $data, int $status = 200): void
    {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($status);
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function success(string $message, array $data = []): void
    {
        self::json(array_merge(['ok' => true, 'message' => $message], $data));
    }

    public static function error(string $message, int $status = 400): void
    {
        self::json(['ok' => false, 'message' => $message], $status);
    }

    public static function redirect(string $url, ?string $flashType = null, ?string $flashMessage = null): void
    {
        if ($flashType && $flashMessage) {
            setFlash($flashType, $flashMessage);
        }
        header("Location: $url");
        exit;
    }
}