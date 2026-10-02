<?php
// pages/inventory/history.php
$page_title = "LỊCH SỬ XUẤT NHẬP";

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use App\Repositories\InventoryRepository;
use App\Services\InventoryService;
use App\Services\CsrfService;
use App\Helpers\AppTime;

requireLogin();
$csrf = new CsrfService();

$service = new InventoryService(new InventoryRepository($pdo), $pdo);

$type_filter    = $_GET['type']        ?? '';
$from_date      = $_GET['from']        ?? AppTime::calc($pdo, 'Y-m-01');
$to_date        = $_GET['to']          ?? AppTime::today($pdo);
$batch_filter   = trim($_GET['batch_no']     ?? '');
$product_filter = (int) ($_GET['product_id'] ?? 0);
$user_filter    = (int) ($_GET['user_id']    ?? 0);
$page           = max(1, (int) ($_GET['page'] ?? 1));

$filters = [
    'type'       => $type_filter,
    'from'       => $from_date,
    'to'         => $to_date,
    'batch_no'   => $batch_filter,
    'product_id' => $product_filter ?: null,
    'user_id'    => $user_filter    ?: null,
    'page'       => $page,
];

$result      = $service->getHistoryPage($filters);
$histories   = $result['items'];
$kpi         = $result['kpi'];
$total_rows  = $result['total'];
$total_pages = $result['total_pages'];
$net         = ($kpi['total_in'] ?? 0) - ($kpi['total_out'] ?? 0);

$users = $service->getActiveUsers();

// Đếm filter đang active (ngoài from/to mặc định)
$activeFilters = 0;
if ($type_filter)    $activeFilters++;
if ($batch_filter)   $activeFilters++;
if ($product_filter) $activeFilters++;
if ($user_filter)    $activeFilters++;
$defaultFrom = AppTime::calc($pdo, 'Y-m-01'); $defaultTo = AppTime::today($pdo);
if ($from_date !== $defaultFrom || $to_date !== $defaultTo) $activeFilters++;

$safeGet = [
    'from'         => $from_date,
    'to'           => $to_date,
    'type'         => $type_filter,
    'product_id'   => $product_filter ?: '',
    'product_name' => htmlspecialchars($_GET['product_name'] ?? '', ENT_QUOTES),
    'batch_no'     => $batch_filter,
    'user_id'      => $user_filter ?: '',
];

include __DIR__ . '/../../layout/header.php';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/css/inventory.css">
<meta name="csrf-token" content="<?= htmlspecialchars($csrf->getToken()) ?>">

<div class="inventory-container">

    <!-- KPI -->
    <div class="kpi-grid">
        <div class="kpi-card in">
            <div class="kpi-icon"><i class="ri-arrow-down-circle-fill"></i></div>
            <div class="kpi-info">
                <div class="kpi-value"><?= number_format($kpi['total_in'] ?? 0) ?></div>
                <div class="kpi-label">Tổng nhập kỳ</div>
            </div>
        </div>
        <div class="kpi-card out">
            <div class="kpi-icon"><i class="ri-arrow-up-circle-fill"></i></div>
            <div class="kpi-info">
                <div class="kpi-value"><?= number_format($kpi['total_out'] ?? 0) ?></div>
                <div class="kpi-label">Tổng xuất kỳ</div>
            </div>
        </div>
        <div class="kpi-card <?= $net >= 0 ? 'warning' : 'critical' ?>">
            <div class="kpi-icon"><i class="ri-scales-line"></i></div>
            <div class="kpi-info">
                <div class="kpi-value" style="color:<?= $net >= 0 ? '#0ea5e9' : '#f59e0b' ?>"><?= ($net >= 0 ? '+' : '') . number_format($net) ?></div>
                <div class="kpi-label">Thuần (nhập − xuất)</div>
            </div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon"><i class="ri-archive-line"></i></div>
            <div class="kpi-info">
                <div class="kpi-value"><?= number_format($kpi['total_products'] ?? 0) ?></div>
                <div class="kpi-label">Sản phẩm liên quan</div>
            </div>
        </div>
    </div>

    <!-- TOOLBAR -->
    <div class="toolbar-modern">
        <div class="toolbar-left">
            <button class="btn-tool" id="btnToggleFilter" onclick="toggleFilterBar()">
                <i class="ri-filter-3-line"></i> Bộ lọc
                <?php if ($activeFilters > 0): ?>
                    <span class="filter-badge"><?= $activeFilters ?></span>
                <?php endif; ?>
            </button>
            <!-- Type toggle nhanh ngay toolbar -->
            <div class="type-toggle">
                <a href="?<?= http_build_query(array_merge($safeGet, ['type' => '',           'page' => 1])) ?>" class="type-btn <?= $type_filter === ''           ? 'active' : '' ?>">Tất cả</a>
                <a href="?<?= http_build_query(array_merge($safeGet, ['type' => 'in',         'page' => 1])) ?>" class="type-btn type-in  <?= $type_filter === 'in'         ? 'active' : '' ?>"><i class="ri-arrow-down-circle-line"></i> Nhập</a>
                <a href="?<?= http_build_query(array_merge($safeGet, ['type' => 'out',        'page' => 1])) ?>" class="type-btn type-out <?= $type_filter === 'out'        ? 'active' : '' ?>"><i class="ri-arrow-up-circle-line"></i> Xuất</a>
                <a href="?<?= http_build_query(array_merge($safeGet, ['type' => 'adjustment', 'page' => 1])) ?>" class="type-btn type-adj <?= $type_filter === 'adjustment' ? 'active' : '' ?>"><i class="ri-equalizer-line"></i> Điều chỉnh</a>
            </div>
            <span class="toolbar-count">
                <strong><?= number_format($total_rows) ?></strong> giao dịch
                · <?= date('d/m/Y', strtotime($from_date)) ?> — <?= date('d/m/Y', strtotime($to_date)) ?>
            </span>
        </div>
        <div class="toolbar-right">
            <a href="index.php"   class="btn-tool btn-sm"><i class="ri-archive-drawer-line"></i> Tồn kho</a>
        </div>
    </div>

    <!-- FILTER BAR (toggle) -->
    <div class="filter-bar-horizontal" id="filterBar" style="<?= $activeFilters > 0 ? '' : 'display:none;' ?>">
        <form method="GET" action="" class="filter-form-inline" id="filterForm">

            <!-- Khoảng thời gian -->
            <div class="form-group-inline" style="min-width:300px">
                <label><i class="ri-calendar-line"></i> Thời gian</label>
                <div style="display:flex;align-items:center;gap:6px">
                    <input type="date" name="from" value="<?= htmlspecialchars($from_date) ?>" class="filter-input" style="flex:1">
                    <span style="color:#94a3b8;font-size:12px;white-space:nowrap">—</span>
                    <input type="date" name="to"   value="<?= htmlspecialchars($to_date) ?>"   class="filter-input" style="flex:1">
                </div>
                <!-- Phím tắt thời gian -->
                <div class="date-shortcuts">
                    <?php
                    $shortcuts = [
                        ['label' => 'Hôm nay',     'from' => AppTime::today($pdo),                                        'to' => AppTime::today($pdo)],
                        ['label' => 'Tuần này',    'from' => AppTime::calc($pdo, 'Y-m-d', 'monday this week'),           'to' => AppTime::today($pdo)],
                        ['label' => 'Tháng này',   'from' => AppTime::calc($pdo, 'Y-m-01'),                               'to' => AppTime::today($pdo)],
                        ['label' => 'Tháng trước', 'from' => AppTime::calc($pdo, 'Y-m-01', 'first day of last month'),    'to' => AppTime::calc($pdo, 'Y-m-t', 'last day of last month')],
                    ];
                    foreach ($shortcuts as $sc):
                        $isActive = ($from_date === $sc['from'] && $to_date === $sc['to']);
                    ?>
                    <a href="?<?= http_build_query(array_merge($safeGet, ['from' => $sc['from'], 'to' => $sc['to'], 'page' => 1])) ?>"
                       class="shortcut-btn <?= $isActive ? 'active' : '' ?>"><?= $sc['label'] ?></a>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Sản phẩm -->
            <div class="form-group-inline" style="flex:1;min-width:160px;position:relative">
                <label><i class="ri-archive-line"></i> Sản phẩm</label>
                <input type="text"   id="productSearchFilter"  class="filter-input" placeholder="Tìm theo tên hoặc SKU..." autocomplete="off"
                       value="<?= $product_filter > 0 ? htmlspecialchars($_GET['product_name'] ?? '') : '' ?>">
                <input type="hidden" name="product_id"   id="productIdFilter"   value="<?= $product_filter ?>">
                <input type="hidden" name="product_name" id="productNameFilter" value="<?= htmlspecialchars($_GET['product_name'] ?? '') ?>">
                <?php if ($product_filter > 0): ?>
                    <a href="?<?= http_build_query(array_merge($safeGet, ['product_id' => '', 'product_name' => '', 'page' => 1])) ?>" class="input-clear-link"><i class="ri-close-line"></i></a>
                <?php endif; ?>
            </div>

            <!-- Số lô -->
            <div class="form-group-inline" style="min-width:130px">
                <label><i class="ri-barcode-line"></i> Số lô</label>
                <input type="text" name="batch_no" value="<?= htmlspecialchars($batch_filter) ?>" class="filter-input" placeholder="Nhập số lô...">
            </div>

            <!-- Người thực hiện -->
            <div class="form-group-inline" style="min-width:140px">
                <label><i class="ri-user-line"></i> Người thực hiện</label>
                <select name="user_id" class="filter-input">
                    <option value="">-- Tất cả --</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?= $u['id'] ?>" <?= $user_filter == $u['id'] ? 'selected' : '' ?>><?= htmlspecialchars($u['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- type ẩn để giữ khi submit -->
            <input type="hidden" name="type" value="<?= htmlspecialchars($type_filter) ?>">

            <div class="filter-actions-row">
                <button type="submit" class="btn-dark btn-sm"><i class="ri-filter-line"></i> Áp dụng</button>
                <a href="history.php" class="btn-tool btn-sm"><i class="ri-refresh-line"></i> Đặt lại</a>
            </div>
        </form>
    </div>

    <!-- BẢNG -->
    <div class="table-card">
        <table class="data-table" id="historyTable">
            <thead>
                <tr>
                    <th width="40"><input type="checkbox" id="selectAll"></th>                   
                    <th style="width:130px">Ngày giờ</th>
                    <th style="width:90px">Loại</th>
                    <th>Sản phẩm</th>
                    <th style="width:110px">Số lô</th>
                    <th class="text-right" style="width:110px">Số lượng</th>
                    <th style="width:150px">Mã tham chiếu</th>
                    <th style="width:130px">Người thực hiện</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($histories)): ?>
                    <tr><td colspan="8" class="text-center" style="padding:48px 0;">
                        <i class="ri-inbox-line" style="font-size:32px;color:#cbd5e1;display:block;margin-bottom:8px;"></i>
                        <span style="color:#94a3b8;font-size:14px;">Không có giao dịch nào trong kỳ này</span>
                    </td></tr>
                <?php else: foreach ($histories as $h): ?>
                    <tr>
                        <td><input type="checkbox" class="row-checkbox" data-id="<?= $h['id'] ?>"></td>
                        <td class="text-muted-sm"><?= date('d/m/Y H:i', strtotime($h['created'])) ?></td>
                        <td>
                            <?php
                                $isAdj = $h['type'] === 'adjustment'
                                      || str_starts_with($h['reference_no'] ?? '', 'ADJ-');
                                if ($isAdj): ?>
                                <span class="badge badge-adj"><i class="ri-equalizer-line"></i> Điều chỉnh</span>
                            <?php elseif ($h['type'] === 'in'): ?>
                                <span class="badge badge-in"><i class="ri-arrow-down-circle-fill"></i> Nhập</span>
                            <?php else: ?>
                                <span class="badge badge-out"><i class="ri-arrow-up-circle-fill"></i> Xuất</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="product-cell">
                                <div class="product-name"><?= htmlspecialchars($h['product_name']) ?></div>
                                <div class="product-meta"><?= htmlspecialchars($h['sku'] ?? '') ?><?= (!empty($h['sku']) && !empty($h['category_name'])) ? ' · ' : '' ?><?= htmlspecialchars($h['category_name'] ?? '') ?></div>
                            </div>
                        </td>
                        <td><code class="batch-code"><?= htmlspecialchars($h['batch_no']) ?></code></td>
                        <td class="text-right">
                            <?php
                                $isAdj   = $h['type'] === 'adjustment'
                                        || str_starts_with($h['reference_no'] ?? '', 'ADJ-');
                                $qty     = (int) $h['quantity'];
                                // data cũ ADJ type='out' lưu quantity dương → cần đọc dấu từ type
                                // data mới adjustment lưu quantity có dấu
                                if ($isAdj && $h['type'] !== 'adjustment') {
                                    // data cũ: type='out'+ADJ → thực chất giảm tồn
                                    $qty = $h['type'] === 'out' ? -abs($qty) : abs($qty);
                                }
                                $chipClass = $isAdj
                                    ? ($qty >= 0 ? 'qty-chip--in' : 'qty-chip--out')
                                    : ($h['type'] === 'in' ? 'qty-chip--in' : 'qty-chip--out');
                                $sign = $qty >= 0 ? '+' : '−';
                            ?>
                            <span class="qty-chip <?= $chipClass ?>">
                                <?= $sign ?><?= number_format(abs($qty)) ?>
                                <small class="text-muted-sm"><?= htmlspecialchars($h['unit'] ?? '') ?></small>
                            </span>
                        </td>
                        <td>
                            <?php
                            $ref    = $h['reference_no'] ?? '';
                            $ref_id = $h['reference_id'] ?? 0;
                            $link   = '';
                            if (str_starts_with($ref, 'INB-') && $ref_id) $link = BASE_URL . '/pages/inbound/index.php?highlight=' . $ref_id;
                            elseif (str_starts_with($ref, 'OUT-') && $ref_id) $link = BASE_URL . '/pages/outbound/index.php?highlight=' . $ref_id;
                            ?>
                            <?php if ($link): ?>
                                <a href="<?= $link ?>" class="ref-link" target="_blank"><i class="ri-external-link-line"></i> <?= htmlspecialchars($ref) ?></a>
                            <?php elseif ($ref): ?>
                                <code class="batch-code"><?= htmlspecialchars($ref) ?></code>
                            <?php else: ?>
                                <span class="text-muted-sm">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-muted-sm"><?= htmlspecialchars($h['user_name'] ?? '—') ?></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <!-- PHÂN TRANG -->
    <?php if ($total_pages > 1):
        $baseParams = $safeGet; unset($baseParams['page']); ?>
    <div class="pagination-modern">
        <div class="page-numbers">
            <?php if ($page > 1): ?>
                <a href="?<?= http_build_query(array_merge($baseParams, ['page' => $page - 1])) ?>"><i class="ri-arrow-left-s-line"></i></a>
            <?php else: ?><span class="disabled"><i class="ri-arrow-left-s-line"></i></span><?php endif; ?>
            <?php $range = 1; $showDots = false;
            for ($i = 1; $i <= $total_pages; $i++):
                if ($i === 1 || $i === $total_pages || ($i >= $page - $range && $i <= $page + $range)):
                    if ($showDots) { echo '<span class="dots">...</span>'; $showDots = false; } ?>
                    <a href="?<?= http_build_query(array_merge($baseParams, ['page' => $i])) ?>" class="<?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
                <?php else: $showDots = true; endif;
            endfor; ?>
            <?php if ($page < $total_pages): ?>
                <a href="?<?= http_build_query(array_merge($baseParams, ['page' => $page + 1])) ?>"><i class="ri-arrow-right-s-line"></i></a>
            <?php else: ?><span class="disabled"><i class="ri-arrow-right-s-line"></i></span><?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>
    <!-- Bulk action bar -->
    <div id="bulkActionBar" class="bulk-action-bar">
        <span id="bulkCount" class="bulk-count">0 sản phẩm đã chọn</span>
        <div class="divider"></div>
        <button onclick="exportSelectedExcel()" class="btn-export">
            <i class="ri-download-cloud-2-line"></i> Xuất Excel
        </button>
        <button onclick="clearSelection()" class="btn-clear">
            Bỏ chọn
        </button>
    </div>
<script src="<?= BASE_URL ?>/js/utils.js"></script>
<script src="<?= BASE_URL ?>/js/inventory.js"></script>
<?php include __DIR__ . '/../../layout/footer.php'; ?>