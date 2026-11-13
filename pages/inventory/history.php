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
$csrfToken = $csrf->getToken();

$service = new InventoryService(new InventoryRepository($pdo), $pdo);

$type_filter    = $_GET['type']        ?? '';
$from_date      = $_GET['from']        ?? AppTime::calc($pdo, 'Y-m-01');
$to_date        = $_GET['to']          ?? AppTime::today($pdo);
$batch_filter   = trim($_GET['batch_no']     ?? '');
$product_filter = (int) ($_GET['product_id'] ?? 0);
$user_filter    = (int) ($_GET['user_id']    ?? 0);
$page           = max(1, (int) ($_GET['page'] ?? 1));
$limit          = min(100, max(1, (int) ($_GET['limit'] ?? 15)));

$filters = [
    'type'       => $type_filter,
    'from'       => $from_date,
    'to'         => $to_date,
    'batch_no'   => $batch_filter,
    'product_id' => $product_filter ?: null,
    'user_id'    => $user_filter    ?: null,
    'page'       => $page,
    'limit'      => $limit,
];

$result      = $service->getHistoryPage($filters);
$histories   = $result['items'] ?? [];
$kpi         = $result['kpi'] ?? [];
$total_rows  = $result['total'] ?? 0;
$total_pages = $result['total_pages'] ?? 1;
$net         = ($kpi['total_in'] ?? 0) - ($kpi['total_out'] ?? 0);

$users = $service->getActiveUsers();

// Đếm filter đang active (ngoài from/to mặc định)
$filterCount = 0;
if ($type_filter)    $filterCount++;
if ($batch_filter)   $filterCount++;
if ($product_filter) $filterCount++;
if ($user_filter)    $filterCount++;
$defaultFrom = AppTime::calc($pdo, 'Y-m-01'); 
$defaultTo   = AppTime::today($pdo);
if ($from_date !== $defaultFrom || $to_date !== $defaultTo) $filterCount++;

$safeGet = [
    'from'         => $from_date,
    'to'           => $to_date,
    'type'         => $type_filter,
    'product_id'   => $product_filter ?: '',
    'product_name' => htmlspecialchars($_GET['product_name'] ?? '', ENT_QUOTES),
    'batch_no'     => $batch_filter,
    'user_id'      => $user_filter ?: '',
];

$body_page = 'inventory-history';
include __DIR__ . '/../../layout/header.php';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/css/suppliers.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/inbound.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/inventory.css">

<!-- Chống giật sidebar filter đồng bộ Inbound -->
<script>
    (function () {
        if (localStorage.getItem('inventory_history_filter_hidden') === 'true') {
            document.documentElement.classList.add('inbound-filter-hidden');
        }
    })();
</script>

<div class="task-app-wrapper">
    <!-- CỘT BỘ LỌC TRÁI (FILTER SIDEBAR) -->
    <aside class="task-filter-sidebar" id="taskFilterSidebar">
        <form method="GET" action="" id="filterForm">
            <div class="sidebar-filter-header">
                <h3>Bộ lọc</h3>
                <?php if ($filterCount > 0): ?>
                    <a href="history.php" class="clear-all-link">Xóa tất cả (<?= $filterCount ?>)</a>
                <?php endif; ?>
            </div>

            <!-- 1. LOẠI GIAO DỊCH -->
            <div class="filter-section" data-filter-key="type">
                <div class="filter-sec-title">
                    <span><i class="ri-arrow-left-right-line"></i> Loại giao dịch</span>
                    <i class="ri-arrow-down-s-line"></i>
                </div>
                <div class="filter-checkbox-list">
                    <label class="filter-check-item">
                        <input type="radio" name="type" value="" <?= $type_filter === '' ? 'checked' : '' ?> onchange="this.form.submit()">
                        <span class="label-text">Tất cả giao dịch</span>
                    </label>
                    <label class="filter-check-item">
                        <input type="radio" name="type" value="in" <?= $type_filter === 'in' ? 'checked' : '' ?> onchange="this.form.submit()">
                        <span class="dot-indicator dot-success"></span>
                        <span class="label-text">Nhập kho</span>
                    </label>
                    <label class="filter-check-item">
                        <input type="radio" name="type" value="out" <?= $type_filter === 'out' ? 'checked' : '' ?> onchange="this.form.submit()">
                        <span class="dot-indicator dot-danger"></span>
                        <span class="label-text">Xuất kho</span>
                    </label>
                    <label class="filter-check-item">
                        <input type="radio" name="type" value="adjustment" <?= $type_filter === 'adjustment' ? 'checked' : '' ?> onchange="this.form.submit()">
                        <span class="dot-indicator" style="background:var(--info);"></span>
                        <span class="label-text">Điều chỉnh tồn</span>
                    </label>
                </div>
            </div>

            <!-- 2. SẢN PHẨM & SỐ LÔ -->
            <div class="filter-section" data-filter-key="product_batch">
                <div class="filter-sec-title">
                    <span><i class="ri-archive-line"></i> Mặt hàng & Lô</span>
                    <i class="ri-arrow-down-s-line"></i>
                </div>
                <div style="display:flex; flex-direction:column; gap:8px; padding:0 4px;">
                    <div style="position:relative;">
                        <input type="text" id="productSearchFilter" class="form-input-modern" placeholder="Tìm theo tên hoặc SKU..." autocomplete="off"
                               value="<?= $product_filter > 0 ? htmlspecialchars($_GET['product_name'] ?? '') : '' ?>" style="font-size:12.5px; padding:7px 10px;">
                        <input type="hidden" name="product_id" id="productIdFilter" value="<?= $product_filter ?>">
                        <input type="hidden" name="product_name" id="productNameFilter" value="<?= htmlspecialchars($_GET['product_name'] ?? '') ?>">
                    </div>
                    <div>
                        <input type="text" name="batch_no" value="<?= htmlspecialchars($batch_filter) ?>" class="form-input-modern" placeholder="Số lô..." style="font-size:12.5px; padding:7px 10px;">
                    </div>
                </div>
            </div>

            <!-- 3. NGƯỜI THỰC HIỆN -->
            <div class="filter-section" data-filter-key="user">
                <div class="filter-sec-title">
                    <span><i class="ri-user-line"></i> Người thực hiện</span>
                    <i class="ri-arrow-down-s-line"></i>
                </div>
                <div class="filter-checkbox-list filter-scroll-box">
                    <label class="filter-check-item">
                        <input type="radio" name="user_id" value="0" <?= $user_filter === 0 ? 'checked' : '' ?> onchange="this.form.submit()">
                        <span class="label-text">Tất cả nhân sự</span>
                    </label>
                    <?php foreach ($users as $u): ?>
                    <label class="filter-check-item">
                        <input type="radio" name="user_id" value="<?= $u['id'] ?>" <?= $user_filter == $u['id'] ? 'checked' : '' ?> onchange="this.form.submit()">
                        <span class="label-text" title="<?= htmlspecialchars($u['full_name']) ?>"><?= htmlspecialchars($u['full_name']) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- 4. THỜI GIAN TẠO (NEO DATEPICKER) -->
            <div class="filter-section" data-filter-key="date">
                <div class="filter-sec-title">
                    <span><i class="ri-calendar-line"></i> Thời gian</span>
                    <i class="ri-arrow-down-s-line"></i>
                </div>

                <div class="neo-datepicker-container" id="neoDatePicker">
                    <input type="hidden" name="from" id="neoDateFrom" value="<?= htmlspecialchars($from_date) ?>">
                    <input type="hidden" name="to" id="neoDateTo" value="<?= htmlspecialchars($to_date) ?>">

                    <button type="button" class="neo-datepicker-trigger" onclick="toggleDatePickerPopover(event)">
                        <span class="trigger-left">
                            <i class="ri-calendar-line"></i>
                            <span id="neoDatePickerText">
                                <?= !empty($from_date) && !empty($to_date) ? date('d/m', strtotime($from_date)) . ' - ' . date('d/m/Y', strtotime($to_date)) : 'Chọn khoảng ngày' ?>
                            </span>
                        </span>
                        <i class="ri-arrow-down-s-line arrow-icon"></i>
                    </button>

                    <div class="neo-datepicker-popover" id="neoDatePickerPopover" onclick="event.stopPropagation()">
                        <div class="datepicker-preset-list" id="datepickerPresetList">
                            <button type="button" class="preset-pill-item" onclick="selectQuickDate('today')">Hôm nay</button>
                            <button type="button" class="preset-pill-item" onclick="selectQuickDate('yesterday')">Hôm qua</button>
                            <button type="button" class="preset-pill-item" onclick="selectQuickDate('last7')">7 ngày trước</button>
                            <button type="button" class="preset-pill-item" onclick="selectQuickDate('last14')">14 ngày trước</button>
                            <button type="button" class="preset-pill-item" onclick="selectQuickDate('last30')">30 ngày trước</button>
                            <button type="button" class="preset-pill-item custom-btn" onclick="openCalendarPicker()">
                                <span>Tùy chỉnh (Start - End)</span>
                                <i class="ri-arrow-right-s-line"></i>
                            </button>
                        </div>

                        <div class="datepicker-calendar-panel" id="datepickerCalendarPanel" style="display: none;">
                            <div class="cal-header-bar">
                                <button type="button" class="btn-cal-nav" onclick="changeMonth(-1)">
                                    <i class="ri-arrow-left-s-line"></i>
                                </button>
                                <span class="cal-month-label" id="calMonthLabel">Tháng 10 2026</span>
                                <button type="button" class="btn-cal-nav" onclick="changeMonth(1)">
                                    <i class="ri-arrow-right-s-line"></i>
                                </button>
                            </div>
                            <div class="cal-week-labels">
                                <span>Su</span><span>Mo</span><span>Tu</span><span>We</span><span>Th</span><span>Fr</span><span>Sa</span>
                            </div>
                            <div class="cal-days-grid" id="calDaysGrid"></div>
                            <div class="cal-footer-range">
                                <span class="range-hint" id="calRangeHint">Chọn ngày bắt đầu</span>
                                <div class="cal-btns">
                                    <button type="button" class="btn-cal-back" onclick="backToPresets()">Quay lại</button>
                                    <button type="button" class="btn-cal-apply" onclick="applyCustomRange()">Áp dụng</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div style="padding: 10px 4px 0 4px;">
                <button type="submit" class="btn-modern-dark" style="width:100%; justify-content:center; padding:7px 12px; font-size:13px;">
                    <i class="ri-filter-line"></i> Áp dụng bộ lọc
                </button>
            </div>
        </form>
    </aside>

    <!-- CỘT BẢNG DỮ LIỆU CHÍNH -->
    <main class="task-table-main">
        <!-- TOP TOOLBAR ĐỒNG BỘ INBOUND -->
        <div class="task-top-toolbar">
            <div class="tb-left">
                <button type="button" class="btn-tb-filter" id="btnToggleSidebar">
                    <i class="ri-equalizer-line"></i>
                    <span id="txtToggleSidebar">Hide Filters</span>
                </button>
                <div class="tb-dropdown-badge">
                    <span>Tổng giao dịch: <strong><?= number_format($total_rows) ?></strong></span>
                    <i class="ri-arrow-down-s-line"></i>
                </div>
                <!-- Phím tắt loại giao dịch nhanh trên toolbar -->
                <div class="type-toggle" style="margin-left: 8px;">
                    <a href="?<?= http_build_query(array_merge($safeGet, ['type' => '', 'page' => 1])) ?>" class="type-btn <?= $type_filter === '' ? 'active' : '' ?>">Tất cả</a>
                    <a href="?<?= http_build_query(array_merge($safeGet, ['type' => 'in', 'page' => 1])) ?>" class="type-btn <?= $type_filter === 'in' ? 'active' : '' ?>"><i class="ri-arrow-down-circle-line"></i> Nhập</a>
                    <a href="?<?= http_build_query(array_merge($safeGet, ['type' => 'out', 'page' => 1])) ?>" class="type-btn <?= $type_filter === 'out' ? 'active' : '' ?>"><i class="ri-arrow-up-circle-line"></i> Xuất</a>
                    <a href="?<?= http_build_query(array_merge($safeGet, ['type' => 'adjustment', 'page' => 1])) ?>" class="type-btn <?= $type_filter === 'adjustment' ? 'active' : '' ?>"><i class="ri-equalizer-line"></i> Điều chỉnh</a>
                </div>
            </div>

            <div class="tb-right">
                <a href="index.php" class="btn-tb-filter" style="text-decoration:none;">
                    <i class="ri-archive-drawer-line"></i> Danh sách lô tồn
                </a>
            </div>
        </div>

        <!-- THẺ KPI TÓM TẮT ĐỒNG BỘ -->
        <div class="inventory-mini-kpi-bar">
            <div class="kpi-mini-item">
                <span class="kpi-mini-lbl"><i class="ri-arrow-down-circle-line text-success"></i> Nhập kỳ:</span>
                <strong class="text-success">+<?= number_format($kpi['total_in'] ?? 0) ?></strong>
            </div>
            <div class="kpi-mini-item">
                <span class="kpi-mini-lbl"><i class="ri-arrow-up-circle-line text-danger"></i> Xuất kỳ:</span>
                <strong class="text-danger">-<?= number_format($kpi['total_out'] ?? 0) ?></strong>
            </div>
            <div class="kpi-mini-item">
                <span class="kpi-mini-lbl"><i class="ri-scales-line"></i> Tồn thuần:</span>
                <strong style="color: <?= $net >= 0 ? 'var(--info)' : 'var(--gold-dark)' ?>;"><?= ($net >= 0 ? '+' : '') . number_format($net) ?></strong>
            </div>
            <div class="kpi-mini-item">
                <span class="kpi-mini-lbl">Mặt hàng liên quan:</span>
                <strong><?= number_format($kpi['total_products'] ?? 0) ?></strong>
            </div>
        </div>

        <!-- BẢNG DỮ LIỆU LỊCH SỬ XUẤT NHẬP -->
        <div class="task-table-card">
            <table class="task-data-table" id="historyTable">
                <thead>
                    <tr>
                        <th width="42"><input type="checkbox" id="selectAll"></th>
                        <th width="140">Ngày giờ</th>
                        <th width="120">Loại giao dịch</th>
                        <th>Sản phẩm</th>
                        <th width="120">Số lô</th>
                        <th width="120" class="text-right">Biến động SL</th>
                        <th width="150">Chứng từ tham chiếu</th>
                        <th width="140">Người thực hiện</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($histories)): ?>
                        <tr>
                            <td colspan="8" class="table-empty-cell">
                                <i class="ri-inbox-line"></i>
                                <p>Không có giao dịch xuất nhập nào trong khoảng thời gian này</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($histories as $h): ?>
                        <tr>
                            <td><input type="checkbox" class="row-checkbox" data-id="<?= (int)$h['id'] ?>"></td>
                            <td><?= date('d/m/Y H:i', strtotime($h['created'])) ?></td>
                            <td>
                                <?php
                                    $isAdj = $h['type'] === 'adjustment' || str_starts_with($h['reference_no'] ?? '', 'ADJ-');
                                    if ($isAdj): ?>
                                    <span class="clean-badge" style="background:var(--info-bg); color:var(--info-dark);"><i class="ri-equalizer-line"></i> Điều chỉnh</span>
                                <?php elseif ($h['type'] === 'in'): ?>
                                    <span class="clean-badge st-completed"><i class="ri-arrow-down-circle-line"></i> Nhập kho</span>
                                <?php else: ?>
                                    <span class="clean-badge st-overdue"><i class="ri-arrow-up-circle-line"></i> Xuất kho</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="item-name">
                                    <strong><?= htmlspecialchars($h['product_name']) ?></strong>
                                    <div class="product-info-sub"><?= htmlspecialchars($h['sku'] ?? '') ?><?= (!empty($h['sku']) && !empty($h['category_name'])) ? ' · ' : '' ?><?= htmlspecialchars($h['category_name'] ?? '') ?></div>
                                </div>
                            </td>
                            <td><code class="batch-code"><?= htmlspecialchars($h['batch_no']) ?></code></td>
                            <td class="text-right font-medium">
                                <?php
                                    $qty = (int) $h['quantity'];
                                    if ($isAdj && $h['type'] !== 'adjustment') {
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
                                    <a href="<?= $link ?>" class="item-name text-primary font-medium" target="_blank" style="text-decoration:none;">
                                        <i class="ri-external-link-line"></i> <?= htmlspecialchars($ref) ?>
                                    </a>
                                <?php elseif ($ref): ?>
                                    <code class="batch-code"><?= htmlspecialchars($ref) ?></code>
                                <?php else: ?>
                                    <span class="text-muted-sm">—</span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($h['user_name'] ?? '—') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- BULK ACTION BAR -->
        <div id="bulkActionBar" class="bulk-action-bar">
            <span id="bulkCount" class="bulk-count">0 giao dịch đã chọn</span>
            <div class="divider"></div>
            <button onclick="exportSelectedExcel()" class="btn-export">
                <i class="ri-download-cloud-2-line"></i> Xuất Excel
            </button>
            <button onclick="clearSelection()" class="btn-clear">Bỏ chọn</button>
        </div>

        <!-- PHÂN TRANG -->
        <?php if ($total_pages > 1):
            $baseParams = $safeGet; unset($baseParams['page']); ?>
        <div class="pagination-footer">
            <div class="pagination-page-list">
                <?php if ($page > 1): ?>
                    <a href="?<?= http_build_query(array_merge($baseParams, ['page' => $page - 1])) ?>" class="btn-page-nav">
                        <i class="ri-arrow-left-s-line"></i>
                    </a>
                <?php else: ?>
                    <span class="btn-page-nav disabled"><i class="ri-arrow-left-s-line"></i></span>
                <?php endif; ?>

                <?php $range = 1; $showDots = false;
                for ($i = 1; $i <= $total_pages; $i++):
                    if ($i === 1 || $i === $total_pages || ($i >= $page - $range && $i <= $page + $range)):
                        if ($showDots) { echo '<span class="dots">...</span>'; $showDots = false; } ?>
                        <a href="?<?= http_build_query(array_merge($baseParams, ['page' => $i])) ?>" class="btn-page-num <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
                    <?php else: $showDots = true;
                    endif;
                endfor; ?>

                <?php if ($page < $total_pages): ?>
                    <a href="?<?= http_build_query(array_merge($baseParams, ['page' => $page + 1])) ?>" class="btn-page-nav">
                        <i class="ri-arrow-right-s-line"></i>
                    </a>
                <?php else: ?>
                    <span class="btn-page-nav disabled"><i class="ri-arrow-right-s-line"></i></span>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </main>
</div>

<meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>">
<script src="<?= BASE_URL ?>/js/utils.js"></script>
<script src="<?= BASE_URL ?>/js/inventory.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const toggleBtn     = document.getElementById('btnToggleSidebar');
    const filterSidebar = document.getElementById('taskFilterSidebar');
    const toggleTxt     = document.getElementById('txtToggleSidebar');

    const isFilterHidden = localStorage.getItem('inventory_history_filter_hidden') === 'true';
    if (toggleTxt) toggleTxt.textContent = isFilterHidden ? 'Show Filters' : 'Hide Filters';
    if (isFilterHidden && filterSidebar) filterSidebar.classList.add('hidden');

    if (toggleBtn && filterSidebar) {
        toggleBtn.addEventListener('click', function () {
            const willHide = !filterSidebar.classList.contains('hidden');
            if (willHide) {
                filterSidebar.classList.add('hidden');
                document.documentElement.classList.add('inbound-filter-hidden');
                if (toggleTxt) toggleTxt.textContent = 'Show Filters';
            } else {
                filterSidebar.classList.remove('hidden');
                document.documentElement.classList.remove('inbound-filter-hidden');
                if (toggleTxt) toggleTxt.textContent = 'Hide Filters';
            }
            localStorage.setItem('inventory_history_filter_hidden', willHide);
        });
    }
});
</script>

<?php include __DIR__ . '/../../layout/footer.php'; ?>