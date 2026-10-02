<?php
// app/Repositories/BaseRepository.php

namespace App\Repositories;

use PDO;
abstract class BaseRepository
{
    protected PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    protected function execute(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    protected function fetchAll(string $sql, array $params = []): array
    {
        return $this->execute($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
    }

    protected function fetchOne(string $sql, array $params = []): array|false
    {
        return $this->execute($sql, $params)->fetch(PDO::FETCH_ASSOC);
    }

    protected function fetchColumn(string $sql, array $params = []): mixed
    {
        return $this->execute($sql, $params)->fetchColumn();
    }

    protected function lastInsertId(): string
    {
        return $this->pdo->lastInsertId();
    }
    protected function insertReturningId(string $sql, array $params = []): int
{
    return (int) $this->fetchColumn($sql, $params);
}
}