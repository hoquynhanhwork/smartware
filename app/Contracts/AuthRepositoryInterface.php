<?php
// app/Contracts/AuthRepositoryInterface.php

namespace App\Contracts;
interface AuthRepositoryInterface
{
    /**
     * Tìm người dùng theo username, bao gồm thông tin công ty.
     *
     * @param  string       $username  Tên đăng nhập cần tra cứu
     * @return array|false  Mảng dữ liệu user+company, hoặc false nếu không tìm thấy
     */
    public function findByUsername(string $username): array|false;

    /**
     * Ghi nhận một lần đăng nhập thất bại cho username/IP.
     *
     * @param  string $identifier  username hoặc IP address
     * @return void
     */
    public function recordFailedLogin(string $identifier): void;

    /**
     * Lấy số lần đăng nhập thất bại trong khoảng thời gian gần đây.
     *
     * @param  string $identifier   username hoặc IP address
     * @param  int    $windowSecs   Khoảng thời gian tính (giây), mặc định 15 phút
     * @return int
     */
    public function countRecentFailures(string $identifier, int $windowSecs = 900): int;

    /**
     * Xóa bộ đếm thất bại sau khi đăng nhập thành công.
     *
     * @param  string $identifier
     * @return void
     */
    public function clearFailures(string $identifier): void;

    public function updateLastLogin(int $userId): void;
}