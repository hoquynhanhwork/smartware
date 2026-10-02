<?php
// app/Helpers/AppTime.php
namespace App\Helpers;

use PDO;

/**
 * "Đồng hồ ảo" phía PHP — đọc frozen_date từ bảng public.app_settings
 * (CÙNG bảng đã tạo cho FastAPI ở migration_app_settings.sql), thay vì
 * luôn dùng date()/strtotime() thật. Không có frozen_date (đã xóa/để
 * rỗng) → tự động rơi về ngày giờ thật.
 */
class AppTime
{
    private static ?string $cached = null;
    private static bool $fetched = false;

    private static function frozenDateStr(PDO $pdo): ?string
    {
        if (self::$fetched) {
            return self::$cached;
        }
        self::$fetched = true;
        $stmt = $pdo->query("SELECT value FROM app_settings WHERE key = 'frozen_date'");
        $row  = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
        self::$cached = ($row && !empty($row['value'])) ? $row['value'] : null;
        return self::$cached;
    }

    /** Ngày "hiện tại" của hệ thống, dạng Y-m-d — frozen_date nếu có, ngược lại date('Y-m-d') thật. */
    public static function today(PDO $pdo): string
    {
        return self::frozenDateStr($pdo) ?: date('Y-m-d');
    }

    /**
     * Thay thế date($format, strtotime($modifier)) nhưng lấy mốc gốc là
     * AppTime::today() thay vì giờ thật của server. Dùng modifier mặc
     * định 'now' khi chỉ cần đổi format (không dịch ngày).
     *
     *   AppTime::calc($pdo, 'Y-m-01')                          // đầu tháng của "hôm nay" ảo
     *   AppTime::calc($pdo, 'Y-m-d', 'monday this week')       // thứ 2 tuần này
     *   AppTime::calc($pdo, 'Y-m-t', 'last day of last month') // ngày cuối tháng trước
     */
    public static function calc(PDO $pdo, string $format, string $modifier = 'now'): string
    {
        $base = strtotime(self::today($pdo) . ' 00:00:00');
        $ts   = ($modifier === 'now') ? $base : strtotime($modifier, $base);
        return date($format, $ts);
    }
}