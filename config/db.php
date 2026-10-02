<?php
// config/db.php

$host     = getenv('DB_HOST')     ?: 'postgres';
$port     = getenv('DB_PORT')     ?: '5432';
$dbname   = getenv('DB_NAME')     ?: 'smartware';
$username = getenv('DB_USER')     ?: 'postgres';
$password = getenv('DB_PASSWORD') ?: '';

try {
    $pdo = new PDO(
        "pgsql:host={$host};port={$port};dbname={$dbname};options='--client_encoding=UTF8'",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
    $pdo->exec("SET client_encoding = 'UTF8'");
} catch (PDOException $e) {
    error_log('Database Error: ' . $e->getMessage());
    http_response_code(500);
    die(json_encode([
        'error' => 'Kết nối database thất bại.'
    ]));
}