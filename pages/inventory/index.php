<?php
// pages/inventory/index.php
$page_title = "LÔ HÀNG";

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use App\Repositories\InventoryRepository;
use App\Services\InventoryService;
use App\Services\CsrfService;

requireLogin();
$csrf = new CsrfService();

$service = new InventoryService(new InventoryRepository($pdo), $pdo);

$keyword     = trim($_GET['keyword']       ?? '');
$category_id = (int) ($_GET['category_id'] ?? 0);
$expiry_f    = $_GET['expiry_status']      ?? '';
$page        = max(1, (int) ($_GET['page'] ?? 1));

$filters = [
    'keyword'       => $keyword,
    'category_id'   => $category_id,
    'expiry_status' => $expiry_f,
    'page'          => $page,
];

$result      = $service->getBatchPage($filters);
$batches     = $result['items'];
$kpi         = $result['kpi'];
$total_rows  = $result['total'];
$total_pages = $result['total_pages'];

$categories = $service->getCategories();

// Đếm filter đang active
$activeFilters = 0;
if ($keyword)     $activeFilters++;
if ($category_id) $activeFilters++;
if ($expiry_f)    $activeFilters++;

function getExpiryInfo(?string $expDate, ?int $daysLeft): array
{
    if ($expDate === null || $daysLeft === null) return ['none', '', 'ri-infinity-line', 'Không có HSD'];
    if ($daysLeft < 0)   return ['expired',  'badge-expired',  'ri-spam-2-fill',        'Đã hết hạn'];
    if ($daysLeft <= 7)  return ['critical', 'badge-critical', 'ri-alarm-warning-fill', $daysLeft . ' ngày'];
    if ($daysLeft <= 30) return ['warning',  'badge-warning',  'ri-time-fill',          $daysLeft . ' ngày'];
    return ['ok', 'badge-ok', 'ri-checkbox-circle-fill', $daysLeft . ' ngày'];
}

include __DIR__ . '/../../layout/header.php';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/css/inventory.css">
<meta name="csrf-token" content="<?= htmlspecialchars($csrf->getToken()) ?>">

<div class="inventory-container">

    <!-- KPI -->
    <div class="kpi-grid">
        <div class="kpi-card">
            <div class="kpi-icon"><i class="ri-stack-line"></i></div>
            <div class="kpi-info">
                <div class="kpi-value"><?= number_format($kpi['total_batches'] ?? 0) ?></div>
                <div class="kpi-label">Tổng lô hàng</div>
            </div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon" style="background:#e0f2fe;color:#0ea5e9"><i class="ri-scales-fill"></i></div>
            <div class="kpi-info">
                <div class="kpi-value"><?= number_format($kpi['total_qty'] ?? 0) ?></div>
                <div class="kpi-label">Tổng tồn</div>
            </div>
        </div>
        <div class="kpi-card out" onclick="setExpiryFilter('expired')" style="cursor:pointer">
            <div class="kpi-icon"><i class="ri-spam-2-fill"></i></div>
            <div class="kpi-info">
                <div class="kpi-value"><?= number_format($kpi['cnt_expired'] ?? 0) ?></div>
                <div class="kpi-label">Đã hết hạn</div>
            </div>
        </div>
        <div class="kpi-card critical" onclick="setExpiryFilter('critical')" style="cursor:pointer">
            <div class="kpi-icon"><i class="ri-alarm-warning-fill"></i></div>
            <div class="kpi-info">
                <div class="kpi-value"><?= number_format($kpi['cnt_critical'] ?? 0) ?></div>
                <div class="kpi-label">Nguy cấp ≤7 ngày</div>
            </div>
        </div>
        <div class="kpi-card warning" onclick="setExpiryFilter('warning')" style="cursor:pointer">
            <div class="kpi-icon"><i class="ri-time-fill"></i></div>
            <div class="kpi-info">
                <div class="kpi-value"><?= number_format($kpi['cnt_warning'] ?? 0) ?></div>
                <div class="kpi-label">Sắp hết ≤30 ngày</div>
            </div>
        </div>
    </div>

    <?php if (($kpi['cnt_expired'] ?? 0) > 0): ?>
    <div class="alert alert-danger" style="display:flex;align-items:center;gap:10px;">
        <i class="ri-error-warning-fill" style="font-size:18px;flex-shrink:0"></i>
        <span>Có <strong><?= number_format($kpi['cnt_expired']) ?></strong> lô hàng đã hết hạn sử dụng — cần xử lý ngay.</span>
        <a href="index.php?expiry_status=expired" class="btn-tool btn-sm" style="margin-left:auto;flex-shrink:0;color:#991b1b;border-color:#fecaca;">Xem ngay <i class="ri-arrow-right-line"></i></a>
    </div>
    <?php endif; ?>

    <!-- TOOLBAR -->
    <div class="toolbar-modern">
        <div class="toolbar-left">
            <!-- Nút bộ lọc toggle -->
            <button class="btn-tool" id="btnToggleFilter" onclick="toggleFilterBar()">
                <i class="ri-filter-3-line"></i> Bộ lọc
                <?php if ($activeFilters > 0): ?>
                    <span class="filter-badge"><?= $activeFilters ?></span>
                <?php endif; ?>
            </button>
            <!-- Tìm kiếm nhanh -->
            <div class="search-box-modern">
                <i class="ri-search-line"></i>
                <input type="text" id="quickSearch" placeholder="Tên SP, SKU hoặc số lô..."
                       value="<?= htmlspecialchars($keyword) ?>"
                       onkeydown="if(event.key==='Enter') applyQuickSearch()">
            </div>
            <span class="toolbar-count"><strong><?= number_format($total_rows) ?></strong> lô · <?= number_format($kpi['total_products'] ?? 0) ?> sản phẩm</span>
        </div>
        <div class="toolbar-right">
            <a href="history.php" class="btn-tool btn-sm"><i class="ri-history-line"></i> Lịch sử</a>
            <?php if (hasRole('admin', 'manager')): ?>
                <button class="btn-dark btn-sm" onclick="openAdjustModal()"><i class="ri-equalizer-line"></i> Điều chỉnh tồn</button>
            <?php endif; ?>
        </div>
    </div>

    <!-- FILTER BAR (toggle) -->
    <div class="filter-bar-horizontal" id="filterBar" style="<?= $activeFilters > 0 ? '' : 'display:none;' ?>">
        <form method="GET" action="" class="filter-form-inline" id="filterForm">
            <input type="hidden" name="keyword" id="hiddenKeyword" value="<?= htmlspecialchars($keyword) ?>">
            <!-- Danh mục -->
            <div class="custom-dropdown">
                <button type="button" class="filter-input custom-dropdown-btn" onclick="toggleDropdown('categoryDropdownPanel')">
                    Danh mục <?= $category_id > 0 ? '(1)' : '' ?>
                    <i class="ri-arrow-down-s-line"></i>
                </button>
                <div class="dropdown-panel" id="categoryDropdownPanel">
                    <div class="dropdown-panel-inner">
                        <div class="fsb-group">
                            <?php foreach ($categories as $cat): ?>
                            <div class="fsb-row">
                                <label class="fsb-label">
                                    <input type="checkbox" class="single-select-cb" name="category_id"
                                        value="<?= $cat['id'] ?>"
                                        <?= $category_id == $cat['id'] ? 'checked' : '' ?>
                                        onchange="enforceSingleCheck(this)">
                                    <span class="fsb-name"><?= htmlspecialchars($cat['name']) ?></span>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Trạng thái HSD -->
            <div class="custom-dropdown">
                <button type="button" class="filter-input custom-dropdown-btn" onclick="toggleDropdown('expiryStatusDropdownPanel')">
                    Trạng thái <?= $expiry_f !== '' ? '(1)' : '' ?>
                    <i class="ri-arrow-down-s-line"></i>
                </button>
                <div class="dropdown-panel" id="expiryStatusDropdownPanel">
                    <div class="dropdown-panel-inner">
                        <div class="fsb-group">
                            <div class="fsb-row">
                                <label class="fsb-label">
                                    <input type="checkbox" class="single-select-cb" name="expiry_status" value="expired"
                                        <?= $expiry_f === 'expired' ? 'checked' : '' ?>
                                        onchange="enforceSingleCheck(this)">
                                    <span class="fsb-name">🔴 Đã hết hạn<?= ($kpi['cnt_expired'] ?? 0) > 0 ? ' ('.$kpi['cnt_expired'].')' : '' ?></span>
                                </label>
                            </div>
                            <div class="fsb-row">
                                <label class="fsb-label">
                                    <input type="checkbox" class="single-select-cb" name="expiry_status" value="critical"
                                        <?= $expiry_f === 'critical' ? 'checked' : '' ?>
                                        onchange="enforceSingleCheck(this)">
                                    <span class="fsb-name">🟠 Nguy cấp ≤7 ngày<?= ($kpi['cnt_critical'] ?? 0) > 0 ? ' ('.$kpi['cnt_critical'].')' : '' ?></span>
                                </label>
                            </div>
                            <div class="fsb-row">
                                <label class="fsb-label">
                                    <input type="checkbox" class="single-select-cb" name="expiry_status" value="warning"
                                        <?= $expiry_f === 'warning' ? 'checked' : '' ?>
                                        onchange="enforceSingleCheck(this)">
                                    <span class="fsb-name">🟡 Sắp hết ≤30 ngày<?= ($kpi['cnt_warning'] ?? 0) > 0 ? ' ('.$kpi['cnt_warning'].')' : '' ?></span>
                                </label>
                            </div>
                            <div class="fsb-row">
                                <label class="fsb-label">
                                    <input type="checkbox" class="single-select-cb" name="expiry_status" value="ok"
                                        <?= $expiry_f === 'ok' ? 'checked' : '' ?>
                                        onchange="enforceSingleCheck(this)">
                                    <span class="fsb-name">🟢 Còn an toàn</span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="filter-actions-row">
                <button type="submit" class="btn-dark btn-sm"><i class="ri-filter-line"></i> Áp dụng</button>
                <a href="index.php" class="btn-tool btn-sm"><i class="ri-refresh-line"></i> Đặt lại</a>
            </div>
        </form>
    </div>

    <!-- BẢNG -->
    <div class="table-card">
        <table class="data-table" id="batchTable">
            <thead>
                <tr>
                    <th width="40"><input type="checkbox" id="selectAll"></th>
                    <th>Sản phẩm</th>
                    <th style="width:110px">Số lô</th>
                    <th class="text-right" style="width:110px">Tồn lô</th>
                    <th style="width:110px">Hạn SD</th>
                    <th style="width:160px">Còn lại</th>
                    <th style="width:110px">Ngày nhập</th>
                    <th style="width:90px">Thao tác</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($batches)): ?>
                    <tr><td colspan="8" class="text-center" style="padding:48px 0;">
                        <i class="ri-inbox-line" style="font-size:32px;color:#cbd5e1;display:block;margin-bottom:8px;"></i>
                        <span style="color:#94a3b8;font-size:14px;">Không có lô hàng nào phù hợp</span>
                    </td></tr>
                <?php else: foreach ($batches as $b):
                    [$expLevel, $expCls, $expIcon, $expLabel] = getExpiryInfo(
                        $b['exp_date'],
                        $b['days_to_exp'] !== null ? (int) $b['days_to_exp'] : null
                    );
                    $rowCls = match($expLevel) {
                        'expired'  => 'row-expired',
                        'critical' => 'row-critical',
                        'warning'  => 'row-warning',
                        default    => '',
                    };
                ?>
                    <tr class="<?= $rowCls ?>">
                        <td><input type="checkbox" class="row-checkbox" data-id="<?= $b['id'] ?>"></td>
                        <td>
                            <div class="product-cell">
                                <div class="prod-name"><?= htmlspecialchars($b['product_name']) ?></div>
                                <div class="prod-meta"><?= htmlspecialchars($b['sku'] ?? '') ?><?= !empty($b['category_name']) ? ' · ' . htmlspecialchars($b['category_name']) : '' ?></div>
                            </div>
                        </td>
                        <td><code class="batch-code"><?= htmlspecialchars($b['batch_no']) ?></code></td>
                        <td class="text-right">
                            <strong class="<?= ($b['quantity'] ?? 0) <= ($b['min_stock'] ?? 0) ? 'text-danger' : '' ?>"><?= number_format($b['quantity'] ?? 0) ?></strong>
                            <small class="text-muted-sm"> <?= htmlspecialchars($b['unit'] ?? '') ?></small>
                        </td>
                        <td class="text-muted-sm"><?= $b['exp_date'] ? date('d/m/Y', strtotime($b['exp_date'])) : '<span style="color:#cbd5e1">—</span>' ?></td>
                        <td>
                            <?php if ($expCls): ?>
                                <span class="badge <?= $expCls ?>"><i class="<?= $expIcon ?>"></i> <?= $expLabel ?></span>
                            <?php else: ?>
                                <span class="text-muted-sm">Không có HSD</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-muted-sm"><?= $b['created_at'] ? date('d/m/Y', strtotime($b['created_at'])) : '—' ?></td>
                        <td class="actions-cell">
                            <?php if (hasRole('admin', 'manager')): ?>
                                <button class="btn-icon-subtle" onclick="openAdjustModal(<?= $b['product_id'] ?>, <?= json_encode($b['product_name'], ENT_QUOTES) ?>, <?= json_encode($b['batch_no'], ENT_QUOTES) ?>)" title="Điều chỉnh tồn"><i class="ri-equalizer-line"></i></button>
                            <?php endif; ?>
                            <button class="btn-icon-subtle" onclick="viewBatchHistory(<?= json_encode($b['batch_no'], ENT_QUOTES) ?>)" title="Xem lịch sử"><i class="ri-history-line"></i></button>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <!-- PHÂN TRANG -->
    <?php if ($total_pages > 1):
        $baseParams = $_GET; unset($baseParams['page']); ?>
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
<!-- MODAL: ĐIỀU CHỈNH TỒN -->
<?php if (hasRole('admin', 'manager')): ?>
<div id="adjustModal" class="modal" style="display:none">
    <div class="modal-content" style="max-width:500px">
        <div class="modal-header">
            <h3>Điều chỉnh tồn kho</h3>
            <span class="close" onclick="closeAdjustModal()">&times;</span>
        </div>
        <div style="padding:24px 28px 0" id="adjustProductGroup">
            <div class="form-group">
                <label>Sản phẩm <span class="required">*</span></label>
                <input type="text" id="adjustProductSearch" placeholder="Tìm theo tên hoặc SKU..." autocomplete="off">
                <input type="hidden" id="adjustProductId" value="">
            </div>
        </div>
        <div style="padding:0 28px 8px">
            <div class="form-group">
                <label>Số lô <span class="required">*</span></label>
                <select id="adjustBatchNo"><option value="">-- Chọn sản phẩm trước --</option></select>
                <small id="adjustCurrentQty" style="color:#64748b;font-size:12px;display:block;margin-top:6px"></small>
            </div>
            <div class="form-group">
                <label>Số lượng thực tế <span class="required">*</span></label>
                <input type="number" id="adjustActualQty" min="0" step="1" placeholder="Nhập số lượng đếm được...">
                <small id="adjustDiffPreview" style="font-size:12px;margin-top:6px;display:block"></small>
            </div>
            <div class="form-group">
                <label>Lý do điều chỉnh <span class="required">*</span></label>
                <textarea id="adjustReason" rows="2" placeholder="VD: Kiểm kê định kỳ, hàng hỏng..."></textarea>
            </div>
        </div>
        <div class="form-actions">
            <button type="button" class="btn-secondary" onclick="closeAdjustModal()">Hủy</button>
            <button type="button" class="btn-primary" onclick="submitAdjust()"><i class="ri-check-line"></i> Xác nhận</button>
        </div>
    </div>
</div>
<?php endif; ?>
<script src="<?= BASE_URL ?>/js/utils.js"></script>
<script src="<?= BASE_URL ?>/js/inventory.js"></script>
<?php include __DIR__ . '/../../layout/footer.php'; ?>