<?php
// pages/export/Export.php

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../vendor/autoload.php';

requireLogin();

$type = $_GET['type'] ?? '';

function exportXls(string $title, array $headers, array $rows, string $filename): never
{
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="' . $filename . '_' . date('Ymd_His') . '.xls"');
    header('Cache-Control: no-store');

    echo '<!DOCTYPE html><html><head><meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; font-size: 10pt; }
        .title { font-size: 16pt; font-weight: bold; text-align: center; padding: 10px 0; }
        .meta  { text-align: right; color: #555; font-size: 9pt; padding-bottom: 8px; }
        table  { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #aaa; padding: 6px 8px; }
        th     { background: #e8ecf0; text-align: center; font-weight: bold; }
    </style></head><body>';

    echo '<div class="title">' . htmlspecialchars($title) . '</div>';
    echo '<div class="meta">Ngày xuất: ' . date('d/m/Y H:i:s') . '</div>';
    echo '<table><thead><tr>';
    foreach ($headers as $h) echo '<th>' . htmlspecialchars($h) . '</th>';
    echo '</tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr>';
        foreach ($row as $cell) {
            if (is_array($cell)) {
                $val   = htmlspecialchars((string) ($cell['value'] ?? ''));
                $align = $cell['align'] ?? 'left';
                $style = isset($cell['fmt']) ? ' mso-number-format:"' . $cell['fmt'] . '";' : '';
                echo "<td style=\"text-align:$align;$style\">$val</td>";
            } else {
                echo '<td>' . htmlspecialchars((string) $cell) . '</td>';
            }
        }
        echo '</tr>';
    }
    echo '</tbody></table></body></html>';
    exit;
}

function fmtMoney(mixed $v): string { return number_format((float)$v, 0, ',', '.') . ' ₫'; }
function fmtNum(mixed $v): string   { return number_format((float)$v, 0, ',', '.'); }
function fmtDate(string $v): string { return $v ? date('d/m/Y', strtotime($v)) : ''; }
function fmtDateTime(string $v): string { return $v ? date('d/m/Y H:i', strtotime($v)) : ''; }

function money(mixed $v): array { return ['value' => fmtMoney($v), 'align' => 'right']; }
function num(mixed $v): array   { return ['value' => fmtNum($v),   'align' => 'right']; }
function ctr(mixed $v): array   { return ['value' => (string)$v,   'align' => 'center']; }

function buildIn(array $vals, string $prefix, array &$params): string
{
    $ph = [];
    foreach ($vals as $i => $v) {
        $key = ":{$prefix}_{$i}";
        $ph[] = $key;
        $params[$key] = $v;
    }
    return '(' . implode(',', $ph) . ')';
}

if ($type === 'products') {
    $keyword      = trim($_GET['keyword'] ?? '');
    $categoryIds  = array_filter(array_map('intval', (array)($_GET['category_id']  ?? [])));
    $supplierIds  = array_filter(array_map('intval', (array)($_GET['supplier_id']  ?? [])));
    $statuses     = array_filter((array)($_GET['status'] ?? []));
    $stock_min    = $_GET['stock_min'] ?? '';
    $stock_max    = $_GET['stock_max'] ?? '';
    $selected_ids = array_filter(array_map('intval', (array)($_GET['selected_ids'] ?? [])));

    $where  = "p.deleted_at IS NULL";
    $params = [];

    if ($keyword !== '') {
        $where .= " AND (p.name ILIKE :kw OR p.sku ILIKE :kw)";
        $params[':kw'] = "%$keyword%";
    }
    if (!empty($categoryIds)) $where .= " AND p.category_id IN " . buildIn($categoryIds, 'cat', $params);
    if (!empty($supplierIds)) $where .= " AND p.supplier_id IN " . buildIn($supplierIds, 'sup', $params);
    if (!empty($statuses))    $where .= " AND p.status IN "       . buildIn($statuses,    'st',  $params);
    if (!empty($selected_ids)) $where .= " AND p.id IN "          . buildIn($selected_ids,'sid', $params);

    $stockSub = "(SELECT COALESCE(SUM(ib.quantity),0) FROM inventory_batches ib WHERE ib.product_id = p.id)";

    $havingCond = '';
    if ($stock_min !== '') { $havingCond .= " AND ($stockSub) >= :smin"; $params[':smin'] = (float)$stock_min; }
    if ($stock_max !== '') { $havingCond .= " AND ($stockSub) <= :smax"; $params[':smax'] = (float)$stock_max; }

    $stmt = $pdo->prepare("
        SELECT p.sku, p.name, c.name AS category_name, s.name AS supplier_name,
               p.unit, p.price, p.cost_price, p.status, p.min_stock,
               $stockSub AS total_stock
        FROM products p
        LEFT JOIN categories c ON c.id = p.category_id
        LEFT JOIN suppliers  s ON s.id = p.supplier_id
        WHERE $where $havingCond
        ORDER BY p.name ASC
    ");
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->execute();
    $data = $stmt->fetchAll();

    $headers = ['STT', 'SKU', 'Tên sản phẩm', 'Danh mục', 'Nhà cung cấp', 'Đơn vị',
                'Giá bán (₫)', 'Giá vốn (₫)', 'Tồn kho', 'Tồn tối thiểu', 'Trạng thái'];
    $rows = [];
    foreach ($data as $i => $p) {
        $rows[] = [
            ctr($i + 1),
            $p['sku'] ?? '',
            $p['name'],
            $p['category_name'] ?? '—',
            $p['supplier_name'] ?? '—',
            $p['unit'] ?? '',
            money($p['price']),
            money($p['cost_price']),
            num($p['total_stock']),
            num($p['min_stock']),
            $p['status'] === 'active' ? 'Đang KD' : 'Ngừng KD',
        ];
    }
    exportXls('DANH SÁCH SẢN PHẨM', $headers, $rows, 'san_pham');
}

if ($type === 'suppliers') {
    $statuses        = array_filter((array)($_GET['status']          ?? []));
    $hasTransactions = array_filter((array)($_GET['has_transaction'] ?? []));
    $from            = $_GET['from']     ?? '';
    $to              = $_GET['to']       ?? '';
    $province        = $_GET['province'] ?? '';
    $ward            = $_GET['ward']     ?? '';
    $selected_ids    = array_filter(array_map('intval', (array)($_GET['selected_ids'] ?? [])));

    $where  = "s.deleted_at IS NULL";
    $params = [];

    if (!empty($from))     { $where .= " AND s.created >= :from"; $params[':from'] = $from . ' 00:00:00'; }
    if (!empty($to))       { $where .= " AND s.created <= :to";   $params[':to']   = $to   . ' 23:59:59'; }
    if (!empty($statuses)) $where .= " AND s.status IN " . buildIn($statuses, 'st', $params);
    if (!empty($province)) { $where .= " AND s.province_code = :prov"; $params[':prov'] = $province; }
    if (!empty($ward))     { $where .= " AND s.ward_code = :ward";     $params[':ward'] = $ward; }
    if (!empty($selected_ids)) $where .= " AND s.id IN " . buildIn($selected_ids, 'sid', $params);

    if (in_array('yes', $hasTransactions, true))
        $where .= " AND EXISTS (SELECT 1 FROM stock_inbounds si WHERE si.supplier_id = s.id AND si.deleted_at IS NULL)";
    elseif (in_array('no', $hasTransactions, true))
        $where .= " AND NOT EXISTS (SELECT 1 FROM stock_inbounds si WHERE si.supplier_id = s.id AND si.deleted_at IS NULL)";

    $stmt = $pdo->prepare("
        SELECT s.name, s.phone, s.email, s.tax_code, s.address, s.status, s.created,
               COALESCE((SELECT SUM(total_amount) FROM stock_inbounds WHERE supplier_id = s.id AND deleted_at IS NULL), 0) AS total_import
        FROM suppliers s
        WHERE $where
        ORDER BY s.name ASC
    ");
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->execute();
    $data = $stmt->fetchAll();

    $headers = ['STT', 'Tên nhà cung cấp', 'Điện thoại', 'Email', 'MST', 'Địa chỉ', 'Tổng nhập (₫)', 'Trạng thái', 'Ngày tạo'];
    $rows = [];
    foreach ($data as $i => $s) {
        $rows[] = [
            ctr($i + 1),
            $s['name'],
            $s['phone'] ?? '',
            $s['email'] ?? '',
            $s['tax_code'] ?? '',
            $s['address'] ?? '',
            money($s['total_import']),
            $s['status'] === 'active' ? 'Đang HT' : 'Ngừng HT',
            fmtDate($s['created']),
        ];
    }
    exportXls('DANH SÁCH NHÀ CUNG CẤP', $headers, $rows, 'nha_cung_cap');
}

if ($type === 'inbound_list') {
    $supplier_ids = array_filter(array_map('intval', (array)($_GET['supplier_id'] ?? [])));
    $statuses     = array_filter((array)($_GET['status'] ?? []));
    $from         = $_GET['from'] ?? '';
    $to           = $_GET['to']   ?? '';
    $selected_ids = array_filter(array_map('intval', (array)($_GET['selected_ids'] ?? [])));

    $where  = "si.deleted_at IS NULL";
    $params = [];

    if (!empty($supplier_ids)) $where .= " AND si.supplier_id IN " . buildIn($supplier_ids, 'sup', $params);
    if (!empty($statuses))     $where .= " AND si.status IN "       . buildIn($statuses,     'st',  $params);
    if (!empty($from)) { $where .= " AND DATE(si.created) >= :from"; $params[':from'] = $from; }
    if (!empty($to))   { $where .= " AND DATE(si.created) <= :to";   $params[':to']   = $to;   }
    if (!empty($selected_ids)) $where .= " AND si.id IN " . buildIn($selected_ids, 'sid', $params);

    $stmt = $pdo->prepare("
        SELECT si.ref_no, s.name AS supplier_name, u.full_name AS user_name,
               si.total_amount, si.created, si.status, si.note
        FROM stock_inbounds si
        LEFT JOIN suppliers s ON s.id = si.supplier_id
        LEFT JOIN users u     ON u.id = si.user_id
        WHERE $where
        ORDER BY si.created DESC
    ");
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->execute();
    $data = $stmt->fetchAll();

    $statusMap = ['completed' => 'Hoàn thành', 'pending' => 'Tạm thời', 'cancelled' => 'Đã hủy'];
    $headers   = ['STT', 'Mã phiếu', 'Nhà cung cấp', 'Người tạo', 'Tổng tiền (₫)', 'Ngày tạo', 'Trạng thái', 'Ghi chú'];
    $rows = [];
    foreach ($data as $i => $r) {
        $rows[] = [
            ctr($i + 1),
            $r['ref_no'] ?? '',
            $r['supplier_name'] ?? '',
            $r['user_name'] ?? '',
            money($r['total_amount']),
            fmtDateTime($r['created']),
            $statusMap[$r['status']] ?? $r['status'],
            $r['note'] ?? '',
        ];
    }
    exportXls('DANH SÁCH PHIẾU NHẬP KHO', $headers, $rows, 'phieu_nhap');
}

if ($type === 'inbound_detail' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data || !isset($data['items'])) {
        http_response_code(400);
        die(json_encode(['error' => 'Dữ liệu không hợp lệ']));
    }

    $ref_no        = htmlspecialchars($data['ref_no']        ?? '');
    $supplier_name = htmlspecialchars($data['supplier_name'] ?? '');
    $user_name     = htmlspecialchars($data['user_name']     ?? '');
    $note          = htmlspecialchars($data['note']          ?? '');
    $created       = htmlspecialchars($data['created']       ?? '');
    $total_amount  = (float) ($data['total_amount'] ?? 0);
    $items         = $data['items'];
    $total_qty     = array_sum(array_column($items, 'quantity'));

    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="phieu_nhap_' . $ref_no . '_' . date('Ymd_His') . '.xls"');
    header('Cache-Control: no-store');

    echo '<!DOCTYPE html><html><head><meta charset="UTF-8">
    <style>
        body{font-family:Arial;font-size:10pt}
        .title{font-size:16pt;font-weight:bold;text-align:center;padding:8px 0}
        table{border-collapse:collapse;width:100%}
        th,td{border:1px solid #aaa;padding:6px 8px}
        th{background:#e8ecf0;text-align:center}
        .info td{border:none;padding:3px 0}
        .sign td{border:none;text-align:center;width:33%;padding-top:40px}
    </style></head><body>';

    echo '<div class="title">PHIẾU NHẬP KHO</div>';
    echo '<table class="info" style="margin-bottom:12px">';
    echo "<tr><td><strong>Mã phiếu:</strong> $ref_no</td><td><strong>Ngày tạo:</strong> $created</td></tr>";
    echo "<tr><td><strong>Nhà cung cấp:</strong> $supplier_name</td><td><strong>Người lập:</strong> $user_name</td></tr>";
    if ($note) echo "<tr><td colspan='2'><strong>Ghi chú:</strong> $note</td></tr>";
    echo '</table>';

    echo '<table><thead><tr>';
    foreach (['STT','Sản phẩm','Danh mục','Số lô','HSD','Số lượng','Đơn giá (₫)','Thành tiền (₫)'] as $h)
        echo '<th>' . $h . '</th>';
    echo '</tr></thead><tbody>';

    foreach ($items as $i => $item) {
        echo '<tr>';
        echo '<td style="text-align:center">' . ($i + 1) . '</td>';
        echo '<td>' . htmlspecialchars($item['product_name'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($item['category_name'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($item['batch_no'] ?? '') . '</td>';
        echo '<td style="text-align:center">' . htmlspecialchars($item['exp_date'] ?? '') . '</td>';
        echo '<td style="text-align:right">' . fmtNum($item['quantity'] ?? 0) . '</td>';
        echo '<td style="text-align:right">' . fmtMoney($item['unit_price'] ?? 0) . '</td>';
        echo '<td style="text-align:right">' . fmtMoney($item['total'] ?? 0) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';

    echo '<table style="margin-top:10px;border:none"><tr>';
    echo '<td style="border:none"><strong>Tổng số lượng:</strong> ' . fmtNum($total_qty) . '</td>';
    echo '<td style="border:none;text-align:right"><strong>Tổng tiền: ' . fmtMoney($total_amount) . '</strong></td>';
    echo '</tr></table>';

    echo '<table class="sign"><tr>';
    echo '<td><strong>Nhà cung cấp</strong><br><br><br>(Ký, họ tên)</td>';
    echo '<td><strong>Thủ kho</strong><br><br><br>(Ký, họ tên)</td>';
    echo '<td><strong>Người lập phiếu</strong><br><br><br>(Ký, họ tên)</td>';
    echo '</tr></table>';

    echo '</body></html>';
    exit;
}

if ($type === 'outbound_list') {
    $statuses     = array_filter((array)($_GET['status'] ?? []));
    $from         = $_GET['from']    ?? '';
    $to           = $_GET['to']      ?? '';
    $keyword      = trim($_GET['keyword'] ?? '');
    $selected_ids = array_filter(array_map('intval', (array)($_GET['selected_ids'] ?? [])));

    $where  = "so.deleted_at IS NULL";
    $params = [];

    if (!empty($statuses)) $where .= " AND so.status IN " . buildIn($statuses, 'st', $params);
    if (!empty($from)) { $where .= " AND DATE(so.created) >= :from"; $params[':from'] = $from; }
    if (!empty($to))   { $where .= " AND DATE(so.created) <= :to";   $params[':to']   = $to;   }
    if ($keyword !== '') { $where .= " AND so.ref_no ILIKE :kw"; $params[':kw'] = "%$keyword%"; }
    if (!empty($selected_ids)) $where .= " AND so.id IN " . buildIn($selected_ids, 'sid', $params);

    $stmt = $pdo->prepare("
        SELECT so.ref_no, u.full_name AS user_name,
               so.total_amount, so.created, so.status, so.note
        FROM stock_outbounds so
        LEFT JOIN users u ON u.id = so.user_id
        WHERE $where
        ORDER BY so.created DESC
    ");
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->execute();
    $data = $stmt->fetchAll();

    $statusMap = ['completed' => 'Hoàn thành', 'pending' => 'Tạm thời', 'cancelled' => 'Đã hủy'];
    $headers   = ['STT', 'Mã phiếu', 'Người tạo', 'Tổng tiền (₫)', 'Ngày tạo', 'Trạng thái', 'Ghi chú'];
    $rows = [];
    foreach ($data as $i => $r) {
        $rows[] = [
            ctr($i + 1),
            $r['ref_no'] ?? '',
            $r['user_name'] ?? '',
            money($r['total_amount']),
            fmtDateTime($r['created']),
            $statusMap[$r['status']] ?? $r['status'],
            $r['note'] ?? '',
        ];
    }
    exportXls('DANH SÁCH PHIẾU XUẤT KHO', $headers, $rows, 'phieu_xuat');
}

if ($type === 'outbound_detail' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');

    $csrf = new \App\Services\CsrfService();
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    if (!$csrf->validate($token)) {
        http_response_code(403);
        die(json_encode(['error' => 'Phiên làm việc hết hạn.']));
    }

    $data = json_decode($raw, true);
    if (!$data || !isset($data['items'])) {
        http_response_code(400);
        die(json_encode(['error' => 'Dữ liệu không hợp lệ']));
    }

    $ref_no       = htmlspecialchars($data['ref_no']      ?? '');
    $user_name    = htmlspecialchars($data['user_name']   ?? '');
    $note         = htmlspecialchars($data['note']        ?? '');
    $created      = htmlspecialchars($data['created']     ?? '');
    $total_amount = (float)($data['total_amount']         ?? 0);
    $items        = $data['items'];
    $total_qty    = array_sum(array_column($items, 'quantity'));

    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="phieu_xuat_' . $ref_no . '_' . date('Ymd_His') . '.xls"');
    header('Cache-Control: no-store');

    echo '<!DOCTYPE html><html><head><meta charset="UTF-8">
    <style>
        body{font-family:Arial;font-size:10pt}
        .title{font-size:16pt;font-weight:bold;text-align:center;padding:8px 0}
        table{border-collapse:collapse;width:100%}
        th,td{border:1px solid #aaa;padding:6px 8px}
        th{background:#e8ecf0;text-align:center}
        .info td{border:none;padding:3px 0}
        .sign td{border:none;text-align:center;width:33%;padding-top:40px}
    </style></head><body>';

    echo '<div class="title">PHIẾU XUẤT KHO</div>';
    echo '<table class="info" style="margin-bottom:12px">';
    echo "<tr><td><strong>Mã phiếu:</strong> $ref_no</td><td><strong>Ngày tạo:</strong> $created</td></tr>";
    echo "<tr><td><strong>Người xuất:</strong> $user_name</td></tr>";
    if ($note) echo "<tr><td colspan='2'><strong>Ghi chú:</strong> $note</td></tr>";
    echo '</table>';

    echo '<table><thead><tr>';
    foreach (['STT','Sản phẩm','Số lô','Số lượng','Đơn giá (₫)','Thành tiền (₫)'] as $h)
        echo '<th>' . $h . '</th>';
    echo '</tr></thead><tbody>';
    foreach ($items as $i => $item) {
        echo '<tr>';
        echo '<td style="text-align:center">' . ($i + 1) . '</td>';
        echo '<td>' . htmlspecialchars($item['product_name'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($item['batch_no'] ?? '') . '</td>';
        echo '<td style="text-align:right">' . fmtNum($item['quantity'] ?? 0) . '</td>';
        echo '<td style="text-align:right">' . fmtMoney($item['unit_price'] ?? 0) . '</td>';
        echo '<td style="text-align:right">' . fmtMoney($item['total'] ?? 0) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';

    echo '<table style="margin-top:10px;border:none"><tr>';
    echo '<td style="border:none"><strong>Tổng số lượng:</strong> ' . fmtNum($total_qty) . '</td>';
    echo '<td style="border:none;text-align:right"><strong>Tổng tiền: ' . fmtMoney($total_amount) . '</strong></td>';
    echo '</tr></table>';

    echo '<table class="sign"><tr>';
    echo '<td><strong>Người nhận</strong><br><br><br>(Ký, họ tên)</td>';
    echo '<td><strong>Thủ kho</strong><br><br><br>(Ký, họ tên)</td>';
    echo '<td><strong>Người lập phiếu</strong><br><br><br>(Ký, họ tên)</td>';
    echo '</tr></table>';
    echo '</body></html>';
    exit;
}

if ($type === 'stock_summary') {
    $keyword      = trim($_GET['keyword']      ?? '');
    $category_id  = (int)($_GET['category_id'] ?? 0);
    $status_f     = trim($_GET['stock_status'] ?? '');
    $selected_ids = array_filter(array_map('intval', (array)($_GET['selected_ids'] ?? []))); // thêm dòng này

    $where  = "1=1";
    $params = [];
    if ($keyword !== '') { $where .= " AND (v.product_name ILIKE :kw OR v.sku ILIKE :kw)"; $params[':kw'] = "%$keyword%"; }
    if ($category_id > 0) { $where .= " AND v.category_id = :cat"; $params[':cat'] = $category_id; }
    if ($status_f !== '')  { $where .= " AND v.stock_status = :st";  $params[':st']  = $status_f; }
    if (!empty($selected_ids)) $where .= " AND v.product_id IN " . buildIn($selected_ids, 'sid', $params); // thêm

    $stmt = $pdo->prepare("
        SELECT v.product_name, v.sku, v.category_name, v.unit,
               v.current_stock, v.min_stock, v.max_stock, v.stock_status
        FROM vw_stock_summary_for_ai v
        WHERE $where
        ORDER BY v.product_name
    ");
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->execute();
    $data = $stmt->fetchAll();

    $statusMap = [
        'OUT_OF_STOCK' => 'Hết hàng', 'LOW_STOCK'  => 'Sắp hết',
        'NEAR_EXPIRY'  => 'Sắp HH',   'OVERSTOCK'  => 'Tồn nhiều', 'NORMAL' => 'Bình thường',
    ];
    $headers = ['STT','Sản phẩm','SKU','Danh mục','Đơn vị','Tồn kho','Tồn tối thiểu','Tồn tối đa','Trạng thái'];
    $rows = [];
    foreach ($data as $i => $r) {
        $rows[] = [
            ctr($i + 1), $r['product_name'], $r['sku'] ?? '', $r['category_name'] ?? '',
            $r['unit'] ?? '', num($r['current_stock']), num($r['min_stock']),
            num($r['max_stock']), $statusMap[$r['stock_status']] ?? $r['stock_status'],
        ];
    }
    exportXls('TỔNG QUAN TỒN KHO', $headers, $rows, 'ton_kho');
}

if ($type === 'batch_summary') {
    $keyword     = trim($_GET['keyword']       ?? '');
    $category_id = (int)($_GET['category_id']  ?? 0);
    $expiry_f    = trim($_GET['expiry_status'] ?? '');

    $where  = "ib.quantity > 0 AND p.deleted_at IS NULL";
    $params = [];
    if ($keyword !== '') {
        $where .= " AND (p.name ILIKE :kw OR p.sku ILIKE :kw OR ib.batch_no ILIKE :kw)";
        $params[':kw'] = "%$keyword%";
    }
    if ($category_id > 0) { $where .= " AND p.category_id = :cat"; $params[':cat'] = $category_id; }
    switch ($expiry_f) {
        case 'expired':  $where .= " AND ib.exp_date < public.app_today()"; break;
        case 'critical': $where .= " AND ib.exp_date >= public.app_today() AND (ib.exp_date-public.app_today()) <= 7"; break;
        case 'warning':  $where .= " AND (ib.exp_date-public.app_today()) BETWEEN 8 AND 30"; break;
        case 'ok':       $where .= " AND (ib.exp_date IS NULL OR (ib.exp_date-public.app_today()) > 30)"; break;
    }

    $stmt = $pdo->prepare("
        SELECT p.name AS product_name, p.sku, c.name AS category_name,
               p.unit, ib.batch_no, ib.quantity, ib.exp_date,
               (ib.exp_date - public.app_today()) AS days_left,
               ib.cost_price
        FROM inventory_batches ib
        JOIN products p        ON p.id = ib.product_id
        LEFT JOIN categories c ON c.id = p.category_id
        WHERE $where
        ORDER BY ib.exp_date ASC NULLS LAST, p.name
    ");
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->execute();
    $data = $stmt->fetchAll();

    $headers = ['STT','Sản phẩm','SKU','Danh mục','Đơn vị','Số lô','Tồn lô','HSD','Còn (ngày)','Giá vốn (₫)'];
    $rows = [];
    foreach ($data as $i => $r) {
        $days = $r['days_left'] !== null ? (int)$r['days_left'] : '—';
        $rows[] = [
            ctr($i + 1), $r['product_name'], $r['sku'] ?? '', $r['category_name'] ?? '',
            $r['unit'] ?? '', $r['batch_no'], num($r['quantity']),
            $r['exp_date'] ? fmtDate($r['exp_date']) : '—',
            ctr($days), money($r['cost_price'] ?? 0),
        ];
    }
    exportXls('DANH SÁCH LÔ HÀNG', $headers, $rows, 'lo_hang');
}

if ($type === 'inventory_history') {
    $type_f     = in_array($_GET['type_filter'] ?? '', ['in','out','adjustment']) ? $_GET['type_filter'] : '';
    $from       = $_GET['from']       ?? '';
    $to         = $_GET['to']         ?? '';
    $product_id = (int)($_GET['product_id'] ?? 0);
    $batch_no   = trim($_GET['batch_no']    ?? '');
    $user_id    = (int)($_GET['user_id']    ?? 0);

    if ($type_f === '') $type_f = in_array($_GET['hist_type'] ?? '', ['in','out','adjustment']) ? $_GET['hist_type'] : '';

    $where  = "1=1";
    $params = [];
    if ($type_f !== '') { $where .= " AND ih.type = :type"; $params[':type'] = $type_f; }
    if (!empty($from))  { $where .= " AND ih.created::date >= :from"; $params[':from'] = $from; }
    if (!empty($to))    { $where .= " AND ih.created::date <= :to";   $params[':to']   = $to;   }
    if ($product_id > 0) { $where .= " AND ih.product_id = :pid"; $params[':pid'] = $product_id; }
    if ($batch_no !== '') { $where .= " AND ih.batch_no ILIKE :bn"; $params[':bn'] = "%$batch_no%"; }
    if ($user_id > 0)    { $where .= " AND ih.user_id = :uid"; $params[':uid'] = $user_id; }

    $stmt = $pdo->prepare("
        SELECT ih.created, ih.type, p.name AS product_name, p.sku,
               ih.batch_no, ih.quantity, p.unit, ih.reference_no, u.full_name AS user_name
        FROM inventory_history ih
        JOIN products p        ON p.id = ih.product_id
        LEFT JOIN users u      ON u.id = ih.user_id
        WHERE $where
        ORDER BY ih.created DESC
        LIMIT 5000
    ");
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->execute();
    $data = $stmt->fetchAll();

    $typeMap = ['in' => 'Nhập', 'out' => 'Xuất', 'adjustment' => 'Điều chỉnh'];
    $headers = ['STT','Ngày giờ','Loại','Sản phẩm','SKU','Số lô','Số lượng','Đơn vị','Mã tham chiếu','Người thực hiện'];
    $rows = [];
    foreach ($data as $i => $r) {
        $rows[] = [
            ctr($i + 1),
            fmtDateTime($r['created']),
            $typeMap[$r['type']] ?? $r['type'],
            $r['product_name'],
            $r['sku'] ?? '',
            $r['batch_no'],
            num($r['quantity']),
            $r['unit'] ?? '',
            $r['reference_no'] ?? '',
            $r['user_name'] ?? '',
        ];
    }
    exportXls('LỊCH SỬ XUẤT NHẬP KHO', $headers, $rows, 'lich_su_xuat_nhap');
}

http_response_code(400);
header('Content-Type: text/plain; charset=UTF-8');
echo 'Loại export không hợp lệ: ' . htmlspecialchars($type);
exit;