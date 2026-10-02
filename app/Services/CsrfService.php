<?php
// app/Services/CsrfService.php

namespace App\Services;
class CsrfService
{
    private const SESSION_KEY = '_csrf_token';
    private const TOKEN_BYTES = 32;

    public function getToken(): string
    {
        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = $this->generate();
        }

        return $_SESSION[self::SESSION_KEY];
    }

    /**
     * @param  string|null $submittedToken
     * @return bool
     */
    public function validate(?string $submittedToken): bool
    {
        if ($submittedToken === null || $submittedToken === '') {
            return false;
        }

        $sessionToken = $_SESSION[self::SESSION_KEY] ?? '';
        return hash_equals($sessionToken, $submittedToken);
    }

    public function rotate(): void
    {
        $_SESSION[self::SESSION_KEY] = $this->generate();
    }

    // ── Helper ───────────────────────────────────────────────────────────────

    private function generate(): string
    {
        return bin2hex(random_bytes(self::TOKEN_BYTES));
    }
}