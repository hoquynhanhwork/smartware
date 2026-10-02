<?php
// app/Repositories/AuthRepository.php

namespace App\Repositories;
use App\Contracts\AuthRepositoryInterface;

class AuthRepository extends BaseRepository implements AuthRepositoryInterface
{
    // ── AuthRepositoryInterface ───────────────────────────────────────────────

    public function findByUsername(string $username): array|false
    {
        return $this->fetchOne("
            SELECT u.id, u.username, u.full_name,
                   u.email, u.role, u.password, u.status
            FROM   users u
            WHERE  u.username  = :username
              AND  u.deleted_at IS NULL
            LIMIT  1
        ", [':username' => $username]);
    }

    public function recordFailedLogin(string $identifier): void
    {
        $this->execute("
            INSERT INTO login_attempts (identifier, attempted_at)
            VALUES (:id, NOW())
        ", [':id' => $identifier]);
    }

    public function countRecentFailures(string $identifier, int $windowSecs = 900): int
    {
        $secs  = (int) $windowSecs;
        $count = $this->fetchColumn("
            SELECT COUNT(*)
            FROM   login_attempts
            WHERE  identifier    = :id
              AND  attempted_at >= NOW() - INTERVAL '{$secs} seconds'
        ", [':id' => $identifier]);

        return (int) $count;
    }

    public function clearFailures(string $identifier): void
    {
        $this->execute("
            DELETE FROM login_attempts
            WHERE identifier = :id
        ", [':id' => $identifier]);
    }

    public function updateLastLogin(int $userId): void
    {
        $this->execute("
            UPDATE users
            SET last_login_at = NOW()
            WHERE id = :id
        ", [':id' => $userId]);
    }
}