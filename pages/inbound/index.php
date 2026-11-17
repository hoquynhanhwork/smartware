<?php
// pages/inbound/index.php
$page_title = "NHẬP KHO";

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use App\Services\CsrfService;
use App\Services\InboundService;
use App\Repositories\InboundRepository;
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
$service = new InboundService(
    new InboundRepository($pdo),
    new CategoryRepository($pdo),
    new SupplierRepository($pdo),
    $pdo
);

$result = $service->list([
    'page'         => $page,
    'limit'        => $limit,
    'keyword'      => $keyword,
    'supplier_ids' => $supplier_filters,
    'statuses'     => $status_filters,
    'from_date'    => $from_date,
    'to_date'      => $to_date,
]);

$inbounds    = $result['items'];
$total_rows  = $result['total'];
$total_pages = $result['total_pages'];

// ── Danh sách nhà cung cấp cho Filter ────────────────────────────────────
$stmt = $pdo->prepare("SELECT id, name FROM suppliers WHERE deleted_at IS NULL ORDER BY name");
$stmt->execute();
$suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);

$filterCount = count($supplier_filters) + count($status_filters)
             + ((!empty($from_date) || !empty($to_date)) ? 1 : 0)
             + (!empty($keyword) ? 1 : 0);

include __DIR__ . '/../../layout/header.php';
?>
<!-- Font Be Vietnam Pro & CSS Đồng bộ -->
<link rel="stylesheet" href="<?= BASE_URL ?>/css/suppliers.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/inbound.css">
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
                        <span class="dot-indicator" style="background:var(--gold-accent)"></span>
                        <span class="label-text">Tạm thời</span>
                    </label>
                    <label class="filter-check-item">
                        <input type="checkbox" name="status[]" value="cancelled" <?= in_array('cancelled', $status_filters, true) ? 'checked' : '' ?> onchange="this.form.submit()">
                        <span class="dot-indicator dot-danger"></span>
                        <span class="label-text">Đã hủy</span>
                    </label>
                </div>
            </div>

            <!-- 2. NHÀ CUNG CẤP -->
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
                    <span>Tất cả phiếu nhập (<?= number_format($total_rows) ?>)</span>
                    <i class="ri-arrow-down-s-line"></i>
                </div>
            </div>

            <div class="tb-right">
                <div class="tb-search-box">
                    <i class="ri-search-line"></i>
                    <input type="text" id="searchInput" placeholder="Tìm kiếm phiếu nhập..." value="<?= htmlspecialchars($keyword) ?>" onkeyup="searchInboundTable()">
                </div>
                <a href="ocr.php" class="btn-tb-secondary" style="text-decoration:none;">
                    <i class="ri-scan-2-line"></i> Nhập từ hóa đơn
                </a>
                <a href="create.php" class="btn-tb-primary" style="text-decoration:none;">
                    <i class="ri-add-line"></i> Tạo phiếu nhập
                </a>
            </div>
        </div>

        <?php if ($flash_success): ?><div class="alert alert-success" style="margin-bottom:0;"><?= htmlspecialchars($flash_success) ?></div><?php endif; ?>
        <?php if ($flash_error):   ?><div class="alert alert-danger" style="margin-bottom:0;"><?= htmlspecialchars($flash_error) ?></div><?php endif; ?>

        <!-- BẢNG DỮ LIỆU HIỆN ĐẠI -->
<div class="task-table-card">
    <table class="task-data-table" id="inboundTable">
        <thead>
            <tr>
                <th style="width: 46px;" class="text-center"><input type="checkbox" id="selectAll"></th>
                <th style="width: 220px;">Mã phiếu</th>
                <th>Nhà cung cấp</th>
                <th style="width: 180px;">Người tạo</th>
                <th style="width: 180px;">Ngày tạo</th>
                <th style="width: 140px;">Trạng thái</th>
                <th style="width: 110px;" class="text-right">Thao tác</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($inbounds)): ?>
                <tr>
                    <td colspan="7" class="table-empty-cell">
                        <i class="ri-inbox-line"></i>
                        <p>Không tìm thấy phiếu nhập nào</p>
                    </td>
                </tr>
            <?php else: ?>
                <?php
                $statusMap = [
                    'completed' => ['st-success', 'Hoàn thành'],
                    'pending'   => ['st-warning', 'Tạm thời'],
                    'cancelled' => ['st-danger',  'Đã hủy'],
                ];
                foreach ($inbounds as $ib):
                    [$badgeClass, $badgeText] = $statusMap[$ib['status']] ?? ['st-warning', $ib['status']];
                    $canEdit   = ($ib['status'] === 'pending');
                    $canDelete = hasRole('admin', 'manager') && ($ib['status'] === 'pending');
                ?>
                <tr>
                    <td class="text-center"><input type="checkbox" class="row-checkbox" data-id="<?= $ib['id'] ?>"></td>
                    <td>
                        <a href="javascript:void(0)" onclick="viewInboundDetail(<?= $ib['id'] ?>)" class="item-code-link" title="<?= htmlspecialchars($ib['ref_no'] ?? '') ?>">
                            <?= htmlspecialchars($ib['ref_no'] ?? '—') ?>
                        </a>
                    </td>
                    <td title="<?= htmlspecialchars($ib['supplier_name'] ?? '') ?>"><?= htmlspecialchars($ib['supplier_name'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($ib['user_name'] ?? '—') ?></td>
                    <td><?= date('d/m/Y H:i', strtotime($ib['created'])) ?></td>
                    <td>
                        <span class="clean-badge <?= $badgeClass ?>">
                            <?= $badgeText ?>
                        </span>
                    </td>
                    <td class="text-right actions-cell">
                        <button type="button" class="btn-action-icon" onclick="viewInboundDetail(<?= $ib['id'] ?>)" title="Xem chi tiết">
                            <i class="ri-eye-line"></i>
                        </button>
                        <?php if ($canEdit): ?>
                            <button type="button" class="btn-action-icon" onclick="editInbound(<?= $ib['id'] ?>)" title="Sửa phiếu tạm">
                                <i class="ri-pencil-line"></i>
                            </button>
                        <?php endif; ?>
                        <?php if ($canDelete): ?>
                            <button type="button" class="btn-action-icon btn-action-delete" onclick="confirmDeleteInbound(<?= $ib['id'] ?>)" title="Xóa phiếu tạm">
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
            <span id="bulkCount" class="bulk-count">0 phiếu nhập đã chọn</span>
            <div class="divider"></div>
            <button onclick="exportSelectedInboundExcel()" class="btn-export">
                <i class="ri-download-cloud-2-line"></i> Xuất Excel
            </button>
            <button onclick="clearInboundSelection()" class="btn-clear">Bỏ chọn</button>
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

<!-- MODAL: XEM CHI TIẾT PHIẾU NHẬP -->
<div id="detailModal" class="modal-modern" style="display:none;">
    <div class="modal-modern-dialog" style="max-width: 840px;">
        <div class="modal-modern-header">
            <div>
                <h3 id="detailModalTitle">Chi tiết phiếu nhập</h3>
                <p class="modal-subtitle">Thông tin chứng từ và danh mục sản phẩm nhập kho.</p>
            </div>
            <button type="button" class="btn-close-modern" onclick="closeInboundDetailModal()">&times;</button>
        </div>
        <div class="modal-tabs-body" id="detailContent"></div>
        <div class="modal-modern-footer">
            <div style="display:flex; gap:8px;">
                <button type="button" class="btn-modern-outline" onclick="exportInboundDetailExcel()">
                    <i class="ri-file-excel-line"></i> Xuất Excel
                </button>
                <button type="button" class="btn-modern-outline" onclick="printInboundDetail()">
                    <i class="ri-printer-line"></i> In phiếu
                </button>
            </div>
            <button type="button" class="btn-modern-dark" onclick="closeInboundDetailModal()">Đóng</button>
        </div>
    </div>
</div>

<!-- MODAL: SỬA PHIẾU NHẬP TẠM -->
<div id="editInboundModal" class="modal-modern" style="display:none;">
    <div class="modal-modern-dialog" style="max-width: 960px;">
        <div class="modal-modern-header">
            <div>
                <h3>Chỉnh sửa phiếu nhập tạm</h3>
                <p class="modal-subtitle">Cập nhật danh sách hàng và thông tin nhà cung cấp.</p>
            </div>
            <button type="button" class="btn-close-modern" onclick="closeEditInboundModal()">&times;</button>
        </div>

        <form id="editInboundForm" method="POST" action="process.php" onsubmit="return validateEditInboundFormBeforeSubmit()">
            <input type="hidden" name="action" value="edit_inbound">
            <input type="hidden" name="id" id="editInboundId">
            <input type="hidden" name="_csrf_token" id="editCsrfToken" value="<?= htmlspecialchars($csrfToken) ?>">

            <div class="modal-tabs-body">
                <div class="form-grid-2" style="margin-bottom:14px;">
                    <div class="form-row-modern">
                        <label class="form-label-modern">Nhà cung cấp <span class="text-danger">*</span></label>
                        <select name="supplier_id" id="editSupplierId" required class="form-input-modern">
                            <option value="">— Chọn nhà cung cấp —</option>
                            <?php foreach ($suppliers as $sup): ?>
                                <option value="<?= $sup['id'] ?>"><?= htmlspecialchars($sup['name']) ?></option>
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

                <div class="table-responsive" style="border:1px solid var(--border-color); border-radius:10px; margin-bottom:12px; overflow:hidden;">
                    <table class="task-data-table" id="editInboundItemsTable">
                        <thead>
                            <tr>
                                <th width="36" class="text-center">#</th>
                                <th style="min-width:200px;">Sản phẩm</th>
                                <th width="100">Số lô</th>
                                <th width="120">NSX</th>
                                <th width="120">HSD</th>
                                <th width="80" class="text-right">SL</th>
                                <th width="120" class="text-right">Đơn giá</th>
                                <th width="130" class="text-right">Thành tiền</th>
                                <th width="36"></th>
                            </tr>
                        </thead>
                        <tbody id="editItemsBody"></tbody>
                    </table>
                </div>

                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
                    <button type="button" class="btn-modern-outline" onclick="addEditInboundRow()">
                        <i class="ri-add-line"></i> Thêm dòng mới
                    </button>
                    <div style="font-size:14px; font-weight:700; color:var(--navy-darkest);">
                        Tổng cộng: <span id="editTotalAmountDisplay" style="color:var(--navy-primary);">0 đ</span>
                        <input type="hidden" name="total_amount" id="editTotalAmount" value="0">
                    </div>
                </div>

                <div class="form-row-modern">
                    <label class="form-label-modern">Ghi chú</label>
                    <textarea name="note" id="editNote" rows="2" class="form-input-modern" placeholder="Nhập ghi chú..."></textarea>
                </div>
            </div>

            <div class="modal-modern-footer">
                <button type="button" class="btn-modern-outline" onclick="closeEditInboundModal()">Hủy bỏ</button>
                <button type="submit" class="btn-modern-dark">Lưu thay đổi</button>
            </div>
        </form>
    </div>
</div>

<template id="rowTemplateEdit">
    <tr class="item-row">
        <td class="stt-cell text-center"></td>
        <td>
            <input type="text" class="product-autocomplete form-input-modern" name="product_name[]" placeholder="Nhập tên hoặc SKU" autocomplete="off" style="padding:6px 10px;">
            <input type="hidden" name="product_id[]" class="product-id">
            <div class="product-info"></div>
        </td>
        <td><input type="text" name="batch_no[]" required placeholder="Lô" class="form-input-modern" style="padding:6px 10px;"></td>
        <td><input type="date" name="mfg_date[]" class="form-input-modern" style="padding:6px 10px;"></td>
        <td><input type="date" name="exp_date[]" required class="form-input-modern" style="padding:6px 10px;"></td>
        <td><input type="number" name="quantity[]" class="qty form-input-modern" value="1" min="1" required style="text-align:right; padding:6px 10px;"></td>
        <td><input type="text" name="unit_price[]" class="price form-input-modern" step="1000" required style="text-align:right; padding:6px 10px;"></td>
        <td style="text-align:right;">
            <input type="text" class="row-total form-input-modern" readonly style="text-align:right; font-weight:700; background:transparent; border:none; padding:6px 10px; color:var(--navy-darkest);">
        </td>
        <td class="text-center">
            <button type="button" class="btn-action-icon btn-action-delete" onclick="removeEditInboundRow(this)">
                <i class="ri-delete-bin-line"></i>
            </button>
        </td>
    </tr>
</template>

<meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>">
<script src="<?= BASE_URL ?>/js/utils.js"></script>
<script src="<?= BASE_URL ?>/js/filter-sidebar.js"></script>
<script src="<?= BASE_URL ?>/js/date-picker.js"></script>
<script src="<?= BASE_URL ?>/js/suppliers.js"></script>
<script src="<?= BASE_URL ?>/js/inbound.js"></script>

<?php include __DIR__ . '/../../layout/footer.php'; ?>