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
$csrfToken = $csrf->getToken();

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
$filterCount = 0;
if (!empty($keyword))     $filterCount++;
if (!empty($category_id)) $filterCount++;
if (!empty($expiry_f))    $filterCount++;

function getExpiryInfo(?string $expDate, ?int $daysLeft): array
{
    if ($expDate === null || $daysLeft === null) return ['none', '', 'ri-infinity-line', 'Không có HSD'];
    if ($daysLeft < 0)   return ['expired',  'st-overdue',   'ri-spam-2-fill',        'Đã hết hạn'];
    if ($daysLeft <= 7)  return ['critical', 'st-overdue',   'ri-alarm-warning-fill', $daysLeft . ' ngày'];
    if ($daysLeft <= 30) return ['warning',  'st-pending',   'ri-time-fill',          $daysLeft . ' ngày'];
    return ['ok', 'st-completed', 'ri-checkbox-circle-fill', $daysLeft . ' ngày'];
}

$body_page = 'inventory-index';
include __DIR__ . '/../../layout/header.php';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/css/suppliers.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/inbound.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/inventory.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/filter-sidebar.css">


<div class="task-app-wrapper">
    <!-- CỘT BỘ LỌC TRÁI (FILTER SIDEBAR) -->
    <aside class="task-filter-sidebar" id="taskFilterSidebar">
        <form method="GET" action="" id="filterForm">
            <div class="sidebar-filter-header">
                <h3>Bộ lọc</h3>
                <?php if ($filterCount > 0): ?>
                    <a href="index.php" class="clear-all-link">Xóa tất cả (<?= $filterCount ?>)</a>
                <?php endif; ?>
            </div>

            <!-- 1. HẠN SỬ DỤNG (EXPIRY STATUS) -->
            <div class="filter-section" data-filter-key="expiry">
                <div class="filter-sec-title">
                    <span><i class="ri-alarm-warning-line"></i> Trạng thái HSD</span>
                    <i class="ri-arrow-down-s-line"></i>
                </div>
                <div class="filter-checkbox-list">
                    <label class="filter-check-item">
                        <input type="radio" name="expiry_status" value="" <?= $expiry_f === '' ? 'checked' : '' ?> onchange="this.form.submit()">
                        <span class="label-text">Tất cả</span>
                    </label>
                    <label class="filter-check-item">
                        <input type="radio" name="expiry_status" value="expired" <?= $expiry_f === 'expired' ? 'checked' : '' ?> onchange="this.form.submit()">
                        <span class="dot-indicator dot-danger"></span>
                        <span class="label-text">Đã hết hạn <?= ($kpi['cnt_expired'] ?? 0) > 0 ? '('.$kpi['cnt_expired'].')' : '' ?></span>
                    </label>
                    <label class="filter-check-item">
                        <input type="radio" name="expiry_status" value="critical" <?= $expiry_f === 'critical' ? 'checked' : '' ?> onchange="this.form.submit()">
                        <span class="dot-indicator" style="background:var(--accent)"></span>
                        <span class="label-text">Nguy cấp ≤7 ngày <?= ($kpi['cnt_critical'] ?? 0) > 0 ? '('.$kpi['cnt_critical'].')' : '' ?></span>
                    </label>
                    <label class="filter-check-item">
                        <input type="radio" name="expiry_status" value="warning" <?= $expiry_f === 'warning' ? 'checked' : '' ?> onchange="this.form.submit()">
                        <span class="dot-indicator" style="background:var(--accent)"></span>
                        <span class="label-text">Sắp hết ≤30 ngày <?= ($kpi['cnt_warning'] ?? 0) > 0 ? '('.$kpi['cnt_warning'].')' : '' ?></span>
                    </label>
                    <label class="filter-check-item">
                        <input type="radio" name="expiry_status" value="ok" <?= $expiry_f === 'ok' ? 'checked' : '' ?> onchange="this.form.submit()">
                        <span class="dot-indicator dot-success"></span>
                        <span class="label-text">Còn an toàn</span>
                    </label>
                </div>
            </div>

            <!-- 2. DANH MỤC HÀNG HÓA -->
            <div class="filter-section" data-filter-key="category">
                <div class="filter-sec-title">
                    <span><i class="ri-folder-line"></i> Danh mục</span>
                    <i class="ri-arrow-down-s-line"></i>
                </div>
                <div class="filter-checkbox-list filter-scroll-box">
                    <label class="filter-check-item">
                        <input type="radio" name="category_id" value="0" <?= $category_id === 0 ? 'checked' : '' ?> onchange="this.form.submit()">
                        <span class="label-text">Tất cả danh mục</span>
                    </label>
                    <?php foreach ($categories as $cat): ?>
                    <label class="filter-check-item">
                        <input type="radio" name="category_id" value="<?= $cat['id'] ?>" <?= $category_id == $cat['id'] ? 'checked' : '' ?> onchange="this.form.submit()">
                        <span class="label-text" title="<?= htmlspecialchars($cat['name']) ?>"><?= htmlspecialchars($cat['name']) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <input type="hidden" name="keyword" value="<?= htmlspecialchars($keyword) ?>">
        </form>
    </aside>

    <!-- CỘT BẢNG DỮ LIỆU CHÍNH -->
    <main class="task-table-main">
        <!-- TOP TOOLBAR ĐỒNG BỘ INBOUND -->
        <div class="task-top-toolbar">
            <div class="tb-left">
                <button type="button" class="btn-tb-filter" id="btnToggleSidebar">
                    <i class="ri-equalizer-line"></i>
                    <span id="txtToggleSidebar">Ẩn bộ lọc</span>
                </button>
                <div class="tb-dropdown-badge">
                    <span>Tất cả lô hàng (<?= number_format($total_rows) ?>)</span>
                    <i class="ri-arrow-down-s-line"></i>
                </div>
            </div>

            <div class="tb-right">
                <div class="tb-search-box">
                    <i class="ri-search-line"></i>
                    <input type="text" id="searchInput" placeholder="Tìm tên SP, SKU, số lô..." value="<?= htmlspecialchars($keyword) ?>" onkeyup="searchInventoryTable()">
                </div>
                <a href="history.php" class="btn-tb-filter" style="text-decoration:none;">
                    <i class="ri-history-line"></i> Lịch sử xuất nhập
                </a>
                <?php if (hasRole('admin', 'manager')): ?>
                    <button type="button" class="btn-tb-primary" onclick="openAdjustModal()">
                        <i class="ri-equalizer-line"></i> Điều chỉnh tồn
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- THẺ KPI TỒN KHO MINI TRÊN ĐẦU BẢNG -->
        <div class="inventory-mini-kpi-bar">
            <div class="kpi-mini-item">
                <span class="kpi-mini-lbl">Tổng lô:</span>
                <strong><?= number_format($kpi['total_batches'] ?? 0) ?></strong>
            </div>
            <div class="kpi-mini-item">
                <span class="kpi-mini-lbl">Tổng tồn:</span>
                <strong><?= number_format($kpi['total_qty'] ?? 0) ?></strong>
            </div>
            <div class="kpi-mini-item text-danger" style="cursor:pointer;" onclick="setExpiryFilter('expired')">
                <span class="kpi-mini-lbl">Đã hết hạn:</span>
                <strong><?= number_format($kpi['cnt_expired'] ?? 0) ?></strong>
            </div>
            <div class="kpi-mini-item text-warning" style="cursor:pointer;" onclick="setExpiryFilter('critical')">
                <span class="kpi-mini-lbl">Nguy cấp (≤7 ngày):</span>
                <strong><?= number_format($kpi['cnt_critical'] ?? 0) ?></strong>
            </div>
        </div>

        <?php if (($kpi['cnt_expired'] ?? 0) > 0): ?>
            <div class="alert alert-danger" style="margin-bottom:0;">
                <i class="ri-error-warning-fill"></i>
                <span>Có <strong><?= number_format($kpi['cnt_expired']) ?></strong> lô hàng đã hết hạn sử dụng — cần kiểm tra và xử lý hủy/xuất ngay.</span>
            </div>
        <?php endif; ?>

        <!-- BẢNG DỮ LIỆU HIỆN ĐẠI -->
        <div class="task-table-card">
            <table class="task-data-table" id="batchTable">
                <thead>
                    <tr>
                        <th width="42"><input type="checkbox" id="selectAll"></th>
                        <th>Sản phẩm</th>
                        <th width="140">Số lô</th>
                        <th width="120" class="text-right">Tồn lô</th>
                        <th width="130">Hạn sử dụng</th>
                        <th width="150">Thời hạn</th>
                        <th width="140">Ngày nhập</th>
                        <th width="100" class="text-right">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($batches)): ?>
                        <tr>
                            <td colspan="8" class="table-empty-cell">
                                <i class="ri-inbox-line"></i>
                                <p>Không tìm thấy lô hàng nào phù hợp</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($batches as $b):
                            [$expLevel, $expCls, $expIcon, $expLabel] = getExpiryInfo(
                                $b['exp_date'],
                                $b['days_to_exp'] !== null ? (int) $b['days_to_exp'] : null
                            );
                        ?>
                        <tr>
                            <td><input type="checkbox" class="row-checkbox" data-id="<?= (int)$b['id'] ?>"></td>
                            <td>
                                <div class="item-name">
                                    <strong><?= htmlspecialchars($b['product_name']) ?></strong>
                                    <div class="product-info-sub"><?= htmlspecialchars($b['sku'] ?? '') ?><?= !empty($b['category_name']) ? ' · ' . htmlspecialchars($b['category_name']) : '' ?></div>
                                </div>
                            </td>
                            <td><code class="batch-code"><?= htmlspecialchars($b['batch_no']) ?></code></td>
                            <td class="text-right font-medium">
                                <span class="<?= ($b['quantity'] ?? 0) <= ($b['min_stock'] ?? 0) ? 'text-danger font-bold' : '' ?>">
                                    <?= number_format($b['quantity'] ?? 0) ?>
                                </span>
                                <small class="text-muted-sm"><?= htmlspecialchars($b['unit'] ?? '') ?></small>
                            </td>
                            <td><?= $b['exp_date'] ? date('d/m/Y', strtotime($b['exp_date'])) : '<span class="text-muted">—</span>' ?></td>
                            <td>
                                <?php if ($expCls): ?>
                                    <span class="clean-badge <?= $expCls ?>"><i class="<?= $expIcon ?>"></i> <?= $expLabel ?></span>
                                <?php else: ?>
                                    <span class="text-muted-sm">Không có HSD</span>
                                <?php endif; ?>
                            </td>
                            <td><?= $b['created_at'] ? date('d/m/Y', strtotime($b['created_at'])) : '—' ?></td>
                            <td class="text-right actions-cell">
                                <?php if (hasRole('admin', 'manager')): ?>
                                    <button type="button" class="btn-action-icon" onclick="openAdjustModal(<?= $b['product_id'] ?>, <?= json_encode($b['product_name'], ENT_QUOTES) ?>, <?= json_encode($b['batch_no'], ENT_QUOTES) ?>)" title="Điều chỉnh tồn">
                                        <i class="ri-equalizer-line"></i>
                                    </button>
                                <?php endif; ?>
                                <button type="button" class="btn-action-icon" onclick="viewBatchHistory(<?= json_encode($b['batch_no'], ENT_QUOTES) ?>)" title="Lịch sử lô">
                                    <i class="ri-history-line"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- BULK ACTION BAR -->
        <div id="bulkActionBar" class="bulk-action-bar">
            <span id="bulkCount" class="bulk-count">0 lô hàng đã chọn</span>
            <div class="divider"></div>
            <button onclick="exportSelectedExcel()" class="btn-export">
                <i class="ri-download-cloud-2-line"></i> Xuất Excel
            </button>
            <button onclick="clearSelection()" class="btn-clear">Bỏ chọn</button>
        </div>

        <!-- PHÂN TRANG -->
        <?php if ($total_pages > 1):
            $baseParams = $_GET;
            unset($baseParams['page']); ?>
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

<!-- MODAL: ĐIỀU CHỈNH TỒN KHO -->
<?php if (hasRole('admin', 'manager')): ?>
<div id="adjustModal" class="modal-modern" style="display:none">
    <div class="modal-modern-dialog" style="max-width:540px">
        <div class="modal-modern-header">
            <div>
                <h3>Điều chỉnh tồn kho</h3>
                <p class="modal-subtitle">Cập nhật kiểm kê hoặc cân bằng số lượng tồn kho theo số lô.</p>
            </div>
            <button type="button" class="btn-close-modern" onclick="closeAdjustModal()">&times;</button>
        </div>
        <div class="modal-tabs-body">
            <div class="form-row-modern" id="adjustProductGroup">
                <label class="form-label-modern">Sản phẩm <span class="text-danger">*</span></label>
                <input type="text" id="adjustProductSearch" class="form-input-modern" placeholder="Tìm theo tên hoặc SKU..." autocomplete="off">
                <input type="hidden" id="adjustProductId" value="">
            </div>
            <div class="form-row-modern">
                <label class="form-label-modern">Số lô <span class="text-danger">*</span></label>
                <select id="adjustBatchNo" class="form-input-modern"><option value="">-- Chọn sản phẩm trước --</option></select>
                <small id="adjustCurrentQty" style="color:var(--text-muted);font-size:12px;margin-top:4px;display:block"></small>
            </div>
            <div class="form-row-modern">
                <label class="form-label-modern">Số lượng thực tế sau kiểm kê <span class="text-danger">*</span></label>
                <input type="number" id="adjustActualQty" class="form-input-modern" min="0" step="1" placeholder="Nhập số lượng đếm được...">
                <small id="adjustDiffPreview" style="font-size:12px;margin-top:4px;display:block;font-weight:600;"></small>
            </div>
            <div class="form-row-modern">
                <label class="form-label-modern">Lý do điều chỉnh <span class="text-danger">*</span></label>
                <textarea id="adjustReason" class="form-input-modern" rows="2" placeholder="VD: Kiểm kê định kỳ, hàng hỏng, thất thoát..."></textarea>
            </div>
        </div>
        <div class="modal-modern-footer">
            <button type="button" class="btn-modern-outline" onclick="closeAdjustModal()">Hủy</button>
            <button type="button" class="btn-modern-dark" onclick="submitAdjust()"><i class="ri-check-line"></i> Xác nhận điều chỉnh</button>
        </div>
    </div>
</div>
<?php endif; ?>

<meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>">
<script src="<?= BASE_URL ?>/js/utils.js"></script>
<script src="<?= BASE_URL ?>/js/filter-sidebar.js"></script>
<script src="<?= BASE_URL ?>/js/inventory.js"></script>

<?php include __DIR__ . '/../../layout/footer.php'; ?>