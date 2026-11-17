<?php
// pages/outbound/index.php
$page_title = "XUẤT KHO";

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use App\Services\CsrfService;
use App\Services\OutboundService;
use App\Repositories\OutboundRepository;
use App\Repositories\ProductRepository;
use App\Repositories\CategoryRepository;
use App\Repositories\SupplierRepository;

requireLogin();

$csrf          = new CsrfService();
$csrfToken     = $csrf->getToken();
$flash_success = getFlash('success');
$flash_error   = getFlash('error');

// ── Tham số lọc & phân trang ──────────────────────────────────────────────
$from_date = trim($_GET['from'] ?? '');
$to_date   = trim($_GET['to']   ?? '');
// Ngày sai định dạng làm PostgreSQL báo lỗi → bỏ qua thay vì crash trang
$from_date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $from_date) ? $from_date : '';
$to_date   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $to_date) ? $to_date : '';
$keyword   = trim($_GET['keyword'] ?? '');
$page      = max(1, (int) ($_GET['page']  ?? 1));
$limit     = min(100, max(1, (int) ($_GET['limit'] ?? 15)));

$supplier_filters = $_GET['supplier_id'] ?? [];
if (!is_array($supplier_filters)) $supplier_filters = [$supplier_filters];
$supplier_filters = array_filter(array_map('intval', $supplier_filters));

$status_filters = $_GET['status'] ?? [];
if (!is_array($status_filters)) $status_filters = [$status_filters];
$status_filters = array_filter($status_filters, 'trim');

// ── Dùng Service để lấy dữ liệu ───────────────────────────────────────────
$service = new OutboundService(
    new OutboundRepository($pdo),
    new ProductRepository($pdo),
    new CategoryRepository($pdo),
    $pdo
);

$result = $service->list([
    'page'         => $page,
    'limit'        => $limit,
    'keyword'      => $keyword,
    'supplier_ids' => $supplier_filters,
    'statuses'     => $status_filters,
    'from'         => $from_date,
    'to'           => $to_date,
]);

$outbounds   = $result['items'] ?? [];
$total_rows  = $result['total'] ?? 0;
$total_pages = $result['total_pages'] ?? 1;

// ── Danh sách nhà cung cấp cho Filter ────────────────────────────────────
$stmt = $pdo->prepare("SELECT id, name FROM suppliers WHERE deleted_at IS NULL ORDER BY name");
$stmt->execute();
$suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);

$filterCount = count($supplier_filters) + count($status_filters)
             + ((!empty($from_date) || !empty($to_date)) ? 1 : 0)
             + (!empty($keyword) ? 1 : 0);

include __DIR__ . '/../../layout/header.php';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/css/suppliers.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/inbound.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/outbound.css">
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

            <!-- 1. TRẠNG THÁI PHIẾU -->
            <div class="filter-section" data-filter-key="status">
                <div class="filter-sec-title">
                    <span><i class="ri-checkbox-circle-line"></i> Trạng thái</span>
                    <i class="ri-arrow-down-s-line"></i>
                </div>
                <div class="filter-checkbox-list">
                    <label class="filter-check-item">
                        <input type="checkbox" name="status[]" value="completed" <?= in_array('completed', $status_filters, true) ? 'checked' : '' ?> onchange="this.form.submit()">
                        <span class="dot-indicator dot-success"></span>
                        <span class="label-text">Hoàn thành</span>
                    </label>
                    <label class="filter-check-item">
                        <input type="checkbox" name="status[]" value="pending" <?= in_array('pending', $status_filters, true) ? 'checked' : '' ?> onchange="this.form.submit()">
                        <span class="dot-indicator" style="background:var(--accent)"></span>
                        <span class="label-text">Tạm thời</span>
                    </label>
                    <label class="filter-check-item">
                        <input type="checkbox" name="status[]" value="cancelled" <?= in_array('cancelled', $status_filters, true) ? 'checked' : '' ?> onchange="this.form.submit()">
                        <span class="dot-indicator dot-danger"></span>
                        <span class="label-text">Đã hủy</span>
                    </label>
                </div>
            </div>

            <!-- 2. NHÀ CUNG CẤP / ĐỐI TÁC -->
            <div class="filter-section" data-filter-key="supplier">
                <div class="filter-sec-title">
                    <span><i class="ri-store-2-line"></i> Nhà cung cấp</span>
                    <i class="ri-arrow-down-s-line"></i>
                </div>
                <div class="filter-checkbox-list filter-scroll-box">
                    <?php foreach ($suppliers as $sup): ?>
                    <label class="filter-check-item">
                        <input type="checkbox" name="supplier_id[]" value="<?= $sup['id'] ?>" <?= in_array((int)$sup['id'], $supplier_filters, true) ? 'checked' : '' ?> onchange="this.form.submit()">
                        <span class="label-text" title="<?= htmlspecialchars($sup['name']) ?>"><?= htmlspecialchars($sup['name']) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php
            $dateFilterFrom  = $from_date;
            $dateFilterTo    = $to_date;
            $dateFilterTitle = 'Ngày tạo';
            include __DIR__ . '/../../layout/date_filter.php';
            ?>

            <input type="hidden" name="keyword" value="<?= htmlspecialchars($keyword) ?>">
        </form>
    </aside>

    <!-- CỘT BẢNG DỮ LIỆU CHÍNH -->
    <main class="task-table-main">
        <!-- TOP TOOLBAR -->
        <div class="task-top-toolbar">
            <div class="tb-left">
                <button type="button" class="btn-tb-filter" id="btnToggleSidebar">
                    <i class="ri-equalizer-line"></i>
                    <span id="txtToggleSidebar">Ẩn bộ lọc</span>
                </button>
                <div class="tb-dropdown-badge">
                    <span>Tất cả phiếu xuất (<?= number_format($total_rows) ?>)</span>
                    <i class="ri-arrow-down-s-line"></i>
                </div>
            </div>

            <div class="tb-right">
                <div class="tb-search-box">
                    <i class="ri-search-line"></i>
                    <input type="text" id="searchInput" placeholder="Tìm kiếm phiếu xuất..." value="<?= htmlspecialchars($keyword) ?>" onkeyup="searchOutboundTable()">
                </div>
                <a href="create.php" class="btn-tb-primary" style="text-decoration:none;">
                    <i class="ri-add-line"></i> Tạo phiếu xuất
                </a>
            </div>
        </div>

        <?php if ($flash_success): ?><div class="alert alert-success" style="margin-bottom:0;"><?= htmlspecialchars($flash_success) ?></div><?php endif; ?>
        <?php if ($flash_error):   ?><div class="alert alert-danger" style="margin-bottom:0;"><?= htmlspecialchars($flash_error) ?></div><?php endif; ?>

        <!-- BẢNG DỮ LIỆU HIỆN ĐẠI -->
        <div class="task-table-card">
            <table class="task-data-table" id="outboundTable">
                <thead>
                    <tr>
                        <th width="46"><input type="checkbox" id="selectAll"></th>
                        <th width="210">Mã phiếu</th>
                        <th width="260">Nhà cung cấp / Đối tác</th>
                        <th width="180">Người tạo</th>
                        <th width="160">Ngày tạo</th>
                        <th width="140">Trạng thái</th>
                        <th width="110" class="text-right">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($outbounds)): ?>
                        <tr>
                            <td colspan="7" class="table-empty-cell">
                                <i class="ri-inbox-line"></i>
                                <p>Không tìm thấy phiếu xuất nào</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php
                        $statusMap = [
                            'completed' => ['st-completed', 'Hoàn thành'],
                            'pending'   => ['st-pending',   'Tạm thời'],
                            'cancelled' => ['st-overdue',   'Đã hủy'],
                        ];
                        foreach ($outbounds as $ob):
                            [$badgeClass, $badgeText] = $statusMap[$ob['status']] ?? ['st-pending', $ob['status']];
                            $canEdit   = ($ob['status'] === 'pending');
                            $canDelete = hasRole('admin', 'manager') && ($ob['status'] === 'pending');
                        ?>
                        <tr>
                            <td><input type="checkbox" class="row-checkbox" data-id="<?= (int)$ob['id'] ?>"></td>
                            <td>
                                <a href="javascript:void(0)" onclick="viewOutboundDetail(<?= (int)$ob['id'] ?>)" class="item-code-link" title="<?= htmlspecialchars($ob['ref_no'] ?? '') ?>">
                                    <?= htmlspecialchars($ob['ref_no'] ?? '—') ?>
                                </a>
                            </td>
                            <td><?= htmlspecialchars($ob['supplier_name'] ?? '—') ?></td>
                            <td><?= htmlspecialchars($ob['user_name'] ?? '—') ?></td>
                            <td><?= date('d/m/Y H:i', strtotime($ob['created'])) ?></td>
                            <td>
                                <span class="clean-badge <?= $badgeClass ?>">
                                    <?= $badgeText ?>
                                </span>
                            </td>
                            <td class="text-right actions-cell">
                                <button type="button" class="btn-action-icon" onclick="viewOutboundDetail(<?= (int)$ob['id'] ?>)" title="Xem chi tiết">
                                    <i class="ri-eye-line"></i>
                                </button>
                                <?php if ($canEdit): ?>
                                    <button type="button" class="btn-action-icon" onclick="editOutbound(<?= (int)$ob['id'] ?>)" title="Sửa phiếu tạm">
                                        <i class="ri-pencil-line"></i>
                                    </button>
                                <?php endif; ?>
                                <?php if ($canDelete): ?>
                                    <button type="button" class="btn-action-icon btn-action-delete" onclick="confirmDeleteOutbound(<?= (int)$ob['id'] ?>)" title="Xóa phiếu tạm">
                                        <i class="ri-delete-bin-line"></i>
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- BULK ACTION BAR -->
        <div id="bulkActionBar" class="bulk-action-bar">
            <span id="bulkCount" class="bulk-count">0 phiếu xuất đã chọn</span>
            <div class="divider"></div>
            <button onclick="exportSelectedOutboundExcel()" class="btn-export">
                <i class="ri-download-cloud-2-line"></i> Xuất Excel
            </button>
            <button onclick="clearOutboundSelection()" class="btn-clear">Bỏ chọn</button>
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

<!-- MODAL: XEM CHI TIẾT PHIẾU XUẤT -->
<div id="detailOutboundModal" class="modal-modern" style="display:none;">
    <div class="modal-modern-dialog" style="max-width: 840px;">
        <div class="modal-modern-header">
            <div>
                <h3 id="detailModalTitle">Chi tiết phiếu xuất</h3>
                <p class="modal-subtitle">Thông tin chứng từ và danh mục sản phẩm xuất kho.</p>
            </div>
            <button type="button" class="btn-close-modern" onclick="closeDetailOutboundModal()">&times;</button>
        </div>
        <div class="modal-tabs-body" id="detailOutboundContent"></div>
        <div class="modal-modern-footer">
            <div style="display:flex; gap:8px;">
                <button type="button" class="btn-modern-outline" onclick="exportOutboundDetailExcel()">
                    <i class="ri-file-excel-line"></i> Xuất Excel
                </button>
                <button type="button" class="btn-modern-outline" onclick="printOutboundDetail()">
                    <i class="ri-printer-line"></i> In phiếu
                </button>
            </div>
            <button type="button" class="btn-modern-dark" onclick="closeDetailOutboundModal()">Đóng</button>
        </div>
    </div>
</div>

<!-- MODAL: SỬA PHIẾU XUẤT TẠM -->
<div id="editOutboundModal" class="modal-modern" style="display:none;">
    <div class="modal-modern-dialog" style="max-width: 960px;">
        <div class="modal-modern-header">
            <div>
                <h3>Chỉnh sửa phiếu xuất tạm</h3>
                <p class="modal-subtitle">Cập nhật danh sách hàng và số lô xuất kho.</p>
            </div>
            <button type="button" class="btn-close-modern" onclick="closeEditOutboundModal()">&times;</button>
        </div>

        <form id="editOutboundForm" method="POST" action="process.php" onsubmit="return validateEditOutboundFormBeforeSubmit()">
            <input type="hidden" name="action" value="edit_outbound">
            <input type="hidden" name="outbound_id" id="editOutboundId">
            <input type="hidden" name="csrf_token" id="editCsrfToken" value="<?= htmlspecialchars($csrfToken) ?>">

            <div class="modal-tabs-body">
                <div class="form-grid-2" style="margin-bottom:14px;">
                    <div class="form-row-modern">
                        <label class="form-label-modern">Nhà cung cấp / Đối tác <span class="text-danger">*</span></label>
                        <select name="supplier_id" id="editOutboundSupplierId" required class="form-input-modern">
                            <option value="">— Chọn nhà cung cấp —</option>
                            <?php foreach ($suppliers as $sup): ?>
                                <option value="<?= (int) $sup['id'] ?>"><?= htmlspecialchars($sup['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-grid-2">
                        <div class="form-row-modern">
                            <label class="form-label-modern">Số tham chiếu</label>
                            <input type="text" name="ref_no" id="editRefNo" class="form-input-modern" placeholder="Mã phiếu">
                        </div>
                        <div class="form-row-modern">
                            <label class="form-label-modern">Trạng thái</label>
                            <select name="status" id="editStatus" class="form-input-modern">
                                <option value="pending">Tạm thời</option>
                                <option value="completed">Hoàn thành</option>
                                <option value="cancelled">Đã hủy</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="table-responsive" style="border:1px solid var(--tb-border); border-radius:10px; margin-bottom:12px;">
                    <table class="task-data-table" id="editOutboundItemsTable">
                        <thead>
                            <tr>
                                <th width="36" class="text-center">#</th>
                                <th style="min-width:200px;">Sản phẩm</th>
                                <th width="200">Số lô (Tồn kho)</th>
                                <th width="90" class="text-right">Số lượng</th>
                                <th width="36"></th>
                            </tr>
                        </thead>
                        <tbody id="editOutboundItemsBody"></tbody>
                    </table>
                </div>

                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
                    <button type="button" class="btn-modern-outline" onclick="addEditOutboundRow()">
                        <i class="ri-add-line"></i> Thêm dòng mới
                    </button>
                </div>

                <div class="form-row-modern">
                    <label class="form-label-modern">Ghi chú</label>
                    <textarea name="note" id="editNote" rows="2" class="form-input-modern" placeholder="Nhập ghi chú..."></textarea>
                </div>
            </div>

            <div class="modal-modern-footer">
                <button type="button" class="btn-modern-outline" onclick="closeEditOutboundModal()">Hủy bỏ</button>
                <button type="submit" class="btn-modern-dark">Lưu thay đổi</button>
            </div>
        </form>
    </div>
</div>

<template id="outboundRowTemplateEdit">
    <tr class="item-row">
        <td class="stt-cell text-center"></td>
        <td style="position:relative;">
            <input type="text" class="product-autocomplete-outbound form-input-modern" name="product_name[]" placeholder="Nhập tên hoặc SKU" autocomplete="off" style="padding:6px 10px;">
            <input type="hidden" name="product_id[]" class="product-id">
            <div class="product-info"></div>
        </td>
        <td>
            <select name="batch_no[]" class="batch-select-outbound form-input-modern" style="padding:6px 10px;">
                <option value="">-- Chọn lô --</option>
            </select>
            <div class="batch-stock-info"></div>
        </td>
        <td>
            <input type="text" name="quantity[]" class="qty-outbound form-input-modern" value="1" required style="text-align:right; padding:6px 10px;">
        </td>
        <td class="text-center">
            <button type="button" class="btn-action-icon btn-action-delete" onclick="removeEditOutboundRow(this)">
                <i class="ri-delete-bin-line"></i>
            </button>
        </td>
    </tr>
</template>

<meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>">
<script>document.body.dataset.page = 'outbound-index';</script>
<script src="<?= BASE_URL ?>/js/utils.js"></script>
<script src="<?= BASE_URL ?>/js/filter-sidebar.js"></script>
<script src="<?= BASE_URL ?>/js/date-picker.js"></script>
<script src="<?= BASE_URL ?>/js/suppliers.js"></script>
<script src="<?= BASE_URL ?>/js/outbound.js"></script>

<?php include __DIR__ . '/../../layout/footer.php'; ?>