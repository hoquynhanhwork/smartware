<?php
// pages/export/Export.php

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use App\Repositories\ProductRepository;
use App\Repositories\SupplierRepository;
use App\Repositories\InboundRepository;
use App\Services\ExportService;
use App\Services\CsrfService;

requireLogin();

$type = $_GET['type'] ?? '';

// ── CSRF: chỉ cần validate khi POST; GET export là read-only ─────────────────
// (Các button exportProductExcel / exportSupplierExcel gọi bằng GET — an toàn vì
//  không thay đổi dữ liệu, chỉ đọc. Token vẫn được kiểm tra nếu client gửi kèm.)
$csrf = new CsrfService();

// ── Composition Root ──────────────────────────────────────────────────────────
$exportService = new ExportService(
    productRepo:  new ProductRepository($pdo),
    supplierRepo: new SupplierRepository($pdo),
    inboundRepo:  new InboundRepository($pdo),
);

// ── Dispatch theo type ────────────────────────────────────────────────────────
match ($type) {
    'products'  => exportProducts($exportService),
    'suppliers' => exportSuppliers($exportService),
    'inbounds'  => exportInbounds($exportService),
    default     => abort('Loại xuất không hợp lệ.'),
};
exit;

// ═══════════════════════════════════════════════════════════════════════════════
// PRODUCTS
// ═══════════════════════════════════════════════════════════════════════════════
function exportProducts(ExportService $service): void
{
    // Lấy selected_ids nếu có (xuất một phần)
    $selectedIds = array_filter(
        array_map('intval', (array) ($_GET['selected_ids'] ?? [])),
        fn($id) => $id > 0
    );

    // Giữ nguyên filter từ URL (đồng bộ với index.php của products)
    $filters = buildProductFilters($_GET);

    $rows = $service->exportProducts($filters);

    // Lọc theo selected_ids nếu có
    if (!empty($selectedIds)) {
        $rows = array_filter($rows, fn($r) => in_array((int) $r['id'], $selectedIds, true));
    }

    $headers = [
        'ID', 'Tên sản phẩm', 'SKU', 'Danh mục', 'Nhà cung cấp',
        'Đơn vị', 'Giá vốn (VNĐ)', 'Giá bán (VNĐ)', 'Tồn kho',
        'Trạng thái', 'Ngày tạo',
    ];

    $mapper = function (array $r): array {
        return [
            $r['id'],
            $r['name']              ?? '',
            $r['sku']               ?? '',
            $r['category_name']     ?? '',
            $r['supplier_name']     ?? '',
            $r['unit']              ?? '',
            formatCsvNumber($r['cost_price'] ?? 0),
            formatCsvNumber($r['price']      ?? 0),
            formatCsvNumber($r['current_stock'] ?? 0),
            translateStatus($r['status'] ?? ''),
            formatCsvDate($r['created_at'] ?? ''),
        ];
    };

    streamCsv('san_pham_' . date('Ymd_His') . '.csv', $headers, $rows, $mapper);
}

// ═══════════════════════════════════════════════════════════════════════════════
// SUPPLIERS
// ═══════════════════════════════════════════════════════════════════════════════
function exportSuppliers(ExportService $service): void
{
    $selectedIds = array_filter(
        array_map('intval', (array) ($_GET['selected_ids'] ?? [])),
        fn($id) => $id > 0
    );

    // Giữ nguyên filter từ URL (đồng bộ với index.php của suppliers)
    $filters = buildSupplierFilters($_GET);

    $rows = $service->exportSuppliers($filters);

    if (!empty($selectedIds)) {
        $rows = array_filter($rows, fn($r) => in_array((int) $r['id'], $selectedIds, true));
    }

    $headers = [
        'ID', 'Tên nhà cung cấp', 'Điện thoại', 'Email', 'Mã số thuế',
        'Địa chỉ', 'Tỉnh/Thành', 'Trạng thái', 'Ngày tạo',
    ];

    $mapper = function (array $r): array {
        return [
            $r['id'],
            $r['name']          ?? '',
            $r['phone']         ?? '',
            $r['email']         ?? '',
            $r['tax_code']      ?? '',
            $r['address']       ?? '',
            $r['province_name'] ?? '',
            translateStatus($r['status'] ?? '', 'supplier'),
            formatCsvDate($r['created_at'] ?? ''),
        ];
    };

    streamCsv('nha_cung_cap_' . date('Ymd_His') . '.csv', $headers, $rows, $mapper);
}

// ═══════════════════════════════════════════════════════════════════════════════
// INBOUNDS
// ═══════════════════════════════════════════════════════════════════════════════
function exportInbounds(ExportService $service): void
{
    $filters = buildInboundFilters($_GET);
    $rows    = $service->exportInboundList($filters);

    $headers = [
        'ID', 'Mã phiếu', 'Nhà cung cấp', 'Tổng tiền (VNĐ)',
        'Người tạo', 'Trạng thái', 'Ngày nhập',
    ];

    $mapper = function (array $r): array {
        return [
            $r['id'],
            $r['ref_no']        ?? '',
            $r['supplier_name'] ?? '',
            formatCsvNumber($r['total_amount'] ?? 0),
            $r['user_name']     ?? '',
            translateStatus($r['status'] ?? '', 'inbound'),
            formatCsvDate($r['created_at'] ?? ''),
        ];
    };

    streamCsv('nhap_hang_' . date('Ymd_His') . '.csv', $headers, $rows, $mapper);
}

// ═══════════════════════════════════════════════════════════════════════════════
// HELPERS — filter builders (đồng bộ với index.php tương ứng)
// ═══════════════════════════════════════════════════════════════════════════════
function buildProductFilters(array $get): array
{
    return [
        'keyword'     => trim($get['keyword']     ?? ''),
        'category_id' => (array) ($get['category_id'] ?? []),
        'status'      => array_filter(
            (array) ($get['status'] ?? []),
            fn($s) => in_array($s, ['active', 'inactive'], true)
        ),
        'supplier_id' => (array) ($get['supplier_id'] ?? []),
        'stock_min'   => $get['stock_min'] ?? '',
        'stock_max'   => $get['stock_max'] ?? '',
    ];
}

function buildSupplierFilters(array $get): array
{
    return [
        'from_date'        => trim($get['from']     ?? ''),
        'to_date'          => trim($get['to']       ?? ''),
        'statuses'         => array_values(array_filter(
            (array) ($get['status'] ?? []),
            fn($s) => in_array($s, ['active', 'inactive'], true)
        )),
        'has_transactions' => array_values(array_filter(
            (array) ($get['has_transaction'] ?? []),
            fn($v) => in_array($v, ['yes', 'no'], true)
        )),
        'province_code'    => trim($get['province'] ?? ''),
        'ward_code'        => trim($get['ward']     ?? ''),
    ];
}

function buildInboundFilters(array $get): array
{
    return [
        'from_date'   => trim($get['from']        ?? ''),
        'to_date'     => trim($get['to']          ?? ''),
        'supplier_id' => (int) ($get['supplier_id'] ?? 0),
        'status'      => trim($get['status']      ?? ''),
    ];
}

// ═══════════════════════════════════════════════════════════════════════════════
// HELPERS — CSV streaming
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Stream CSV xuống trình duyệt.
 *
 * @param string   $filename Tên file gợi ý cho browser.
 * @param string[] $headers  Hàng tiêu đề.
 * @param array    $rows     Mảng dữ liệu thô.
 * @param callable $mapper   fn(array $row): array — chuyển 1 row thô → 1 mảng cột.
 */
function streamCsv(string $filename, array $headers, array $rows, callable $mapper): void
{
    // BOM UTF-8 để Excel mở đúng tiếng Việt
    $bom = "\xEF\xBB\xBF";

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache');
    header('Pragma: no-cache');

    $out = fopen('php://output', 'wb');
    fwrite($out, $bom);
    fputcsv($out, $headers);

    foreach ($rows as $row) {
        fputcsv($out, $mapper($row));
    }

    fclose($out);
}

/**
 * Format số cho CSV: loại bỏ dấu thừa, giữ số nguyên / thập phân gọn.
 */
function formatCsvNumber(mixed $value): string
{
    $num = (float) $value;
    return $num === floor($num)
        ? number_format($num, 0, '.', '')
        : number_format($num, 2, '.', '');
}

/**
 * Format ngày sang dd/mm/yyyy cho CSV.
 */
function formatCsvDate(string $dateStr): string
{
    if (!$dateStr) return '';
    $ts = strtotime($dateStr);
    return $ts ? date('d/m/Y', $ts) : $dateStr;
}

/**
 * Dịch status code sang tiếng Việt.
 *
 * @param string $status  Giá trị DB ('active', 'inactive', 'completed', …)
 * @param string $context 'product' | 'supplier' | 'inbound'
 */
function translateStatus(string $status, string $context = 'product'): string
{
    $maps = [
        'product'  => ['active' => 'Đang kinh doanh', 'inactive' => 'Ngừng kinh doanh'],
        'supplier' => ['active' => 'Đang hợp tác',    'inactive' => 'Ngừng hợp tác'],
        'inbound'  => ['completed' => 'Hoàn thành', 'draft' => 'Nháp', 'cancelled' => 'Đã hủy'],
    ];

    return $maps[$context][$status] ?? $status;
}

/**
 * Trả về lỗi 400 và dừng.
 */
function abort(string $message): never
{
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $message;
    exit;
}