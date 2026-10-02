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

$csrf       = new CsrfService();

$flash_success = getFlash('success');
$flash_error   = getFlash('error');

// ── Tham số lọc & phân trang ──────────────────────────────────────────────
$from_date = $_GET['from']  ?? '';
$to_date   = $_GET['to']    ?? '';
$page      = max(1, (int) ($_GET['page']  ?? 1));
$limit     = min(100, max(1, (int) ($_GET['limit'] ?? 15)));

$supplier_filters = $_GET['supplier_id'] ?? [];
if (!is_array($supplier_filters)) $supplier_filters = [$supplier_filters];
$supplier_filters = array_filter(array_map('intval', $supplier_filters));

$status_filters = $_GET['status'] ?? [];
if (!is_array($status_filters)) $status_filters = [$status_filters];
$status_filters = array_filter($status_filters, 'trim');

// ── Dùng Service để list (không có company_id) ──────────────────────────
$service = new InboundService(
    new InboundRepository($pdo),
    new CategoryRepository($pdo),
    new SupplierRepository($pdo),
    $pdo
);

$result      = $service->list([
    'page'         => $page,
    'limit'        => $limit,
    'supplier_ids' => $supplier_filters,
    'statuses'     => $status_filters,
    'from_date'    => $from_date,
    'to_date'      => $to_date,
]);
$inbounds    = $result['items'];
$total_rows  = $result['total'];
$total_pages = $result['total_pages'];

// ── Danh sách nhà cung cấp cho filter & edit modal (bỏ company_id) ────────
$stmt = $pdo->prepare("
    SELECT id, name FROM suppliers
    WHERE deleted_at IS NULL
    ORDER BY name
");
$stmt->execute();
$suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/../../layout/header.php';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/css/inbound.css">

<div class="inbound-container">

    <div class="toolbar-modern">
        <div class="toolbar-left">
            <button class="btn-tool" onclick="toggleFilterBar()">
                <i class="ri-filter-3-line"></i> Bộ lọc
                <?php
                $filter_count = count($supplier_filters) + count($status_filters)
                              + (!empty($from_date) ? 1 : 0) + (!empty($to_date) ? 1 : 0);
                if ($filter_count > 0): ?>
                    <span class="filter-badge"><?= $filter_count ?></span>
                <?php endif; ?>
            </button>
            <div class="search-box-modern">
                <i class="ri-search-line"></i>
                <input type="text" id="searchInput" placeholder="Tìm kiếm nhanh..." onkeyup="searchInboundTable()">
            </div>
        </div>
         <div class="toolbar-right"> 
            <a href="ocr.php" class="btn-tool"><i class="ri-scan-2-line"></i> Nhập từ hóa đơn</a>
            <a href="create.php" class="btn-dark"><i class="ri-add-line"></i> Nhập mới</a>
        </div>
    </div>

    <div class="filter-bar-horizontal" id="filterBar" style="display: <?= $filter_count > 0 ? 'flex' : 'none' ?>;">
        <form method="GET" action="" class="filter-form-inline">
            <input type="<?= empty($from_date) ? 'text' : 'date' ?>" name="from" value="<?= htmlspecialchars($from_date) ?>"
                   class="filter-input" placeholder="Từ ngày"
                   onfocus="this.type='date'" onblur="if(this.value==='') this.type='text'">
            <input type="<?= empty($to_date) ? 'text' : 'date' ?>" name="to" value="<?= htmlspecialchars($to_date) ?>"
                   class="filter-input" placeholder="Đến ngày"
                   onfocus="this.type='date'" onblur="if(this.value==='') this.type='text'">

            <div class="custom-dropdown">
                <button type="button" class="filter-input custom-dropdown-btn" onclick="toggleDropdown('supplierDropdownPanel')">
                    Nhà cung cấp <?= !empty($supplier_filters) ? '(' . count($supplier_filters) . ')' : '' ?>
                    <i class="ri-arrow-down-s-line"></i>
                </button>
                <div class="dropdown-panel" id="supplierDropdownPanel">
                    <div class="dropdown-panel-inner">
                        <div class="fsb-group">
                            <?php foreach ($suppliers as $sup): ?>
                            <div class="fsb-row">
                                <label class="fsb-label">
                                    <input type="checkbox" name="supplier_id[]" value="<?= $sup['id'] ?>"
                                           <?= in_array($sup['id'], $supplier_filters) ? 'checked' : '' ?>>
                                    <span class="fsb-name"><?= htmlspecialchars($sup['name']) ?></span>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="custom-dropdown">
                <button type="button" class="filter-input custom-dropdown-btn" onclick="toggleDropdown('statusDropdownPanel')">
                    Trạng thái <?= !empty($status_filters) ? '(' . count($status_filters) . ')' : '' ?>
                    <i class="ri-arrow-down-s-line"></i>
                </button>
                <div class="dropdown-panel" id="statusDropdownPanel">
                    <div class="dropdown-panel-inner">
                        <div class="fsb-group">
                            <div class="fsb-row"><label class="fsb-label">
                                <input type="checkbox" name="status[]" value="completed" <?= in_array('completed', $status_filters) ? 'checked' : '' ?>>
                                <span class="fsb-name">Hoàn thành</span></label></div>
                            <div class="fsb-row"><label class="fsb-label">
                                <input type="checkbox" name="status[]" value="pending" <?= in_array('pending', $status_filters) ? 'checked' : '' ?>>
                                <span class="fsb-name">Tạm thời</span></label></div>
                            <div class="fsb-row"><label class="fsb-label">
                                <input type="checkbox" name="status[]" value="cancelled" <?= in_array('cancelled', $status_filters) ? 'checked' : '' ?>>
                                <span class="fsb-name">Đã hủy</span></label></div>
                        </div>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn-dark btn-sm">Áp dụng</button>
            <a href="index.php" class="btn-tool btn-sm">Xóa lọc</a>
        </form>
    </div>

    <?php if ($flash_success): ?><div class="alert alert-success"><?= htmlspecialchars($flash_success) ?></div><?php endif; ?>
    <?php if ($flash_error):   ?><div class="alert alert-danger"><?= htmlspecialchars($flash_error) ?></div><?php endif; ?>

    <div class="table-card">
        <table class="table-modern" id="inboundTable">
            <thead>
                <tr>
                    <th width="40"><input type="checkbox" id="selectAll"></th>
                    <th width="120">Mã PN</th>
                    <th>Nhà cung cấp</th>
                    <th>Người tạo</th>
                    <th>Ngày tạo</th>
                    <th width="120">Trạng thái</th>
                    <th class="text-right">Thao tác</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($inbounds)): ?>
                    <tr><td colspan="8" class="text-center" style="padding:40px; color:#94a3b8;">Chưa có phiếu nhập nào</td></tr>
                <?php else: ?>
                    <?php
                    $statusMap = [
                        'completed' => ['badge-success', 'Hoàn thành', 'Đã vào kho'],
                        'pending'   => ['badge-warning', 'Tạm thời',   'Chưa vào kho'],
                        'cancelled' => ['badge-danger',  'Đã hủy',     ''],
                    ];
                    foreach ($inbounds as $ib):
                        [$statusClass, $statusText, $statusHint] = $statusMap[$ib['status']] ?? ['badge-modern', $ib['status'], ''];
                        $canEdit   = ($ib['status'] === 'pending');
                        $canDelete = hasRole('admin', 'manager') && ($ib['status'] === 'pending');
                    ?>
                    <tr>
                        <td><input type="checkbox" class="row-checkbox" data-id="<?= $ib['id'] ?>"></td>
                        <td class="font-medium"><?= htmlspecialchars($ib['ref_no'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($ib['supplier_name'] ?? '—') ?></td>
                        <td class="text-muted"><?= htmlspecialchars($ib['user_name'] ?? '') ?></td>
                        <td class="text-muted"><?= date('d/m/Y H:i', strtotime($ib['created'])) ?></td>
                        <td>
                            <span class="badge-modern <?= $statusClass ?>" title="<?= $statusHint ?>">
                                <?= $statusText ?>
                            </span>
                            <?php if ($statusHint): ?>
                                <span style="font-size:11px;color:#94a3b8;display:block;margin-top:3px;"><?= $statusHint ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="text-right actions-cell">
                            <button class="btn-icon-subtle" onclick="viewInboundDetail(<?= $ib['id'] ?>)" title="Xem chi tiết">
                                <i class="ri-eye-line"></i>
                            </button>
                            <?php if ($canEdit): ?>
                                <button class="btn-icon-subtle" onclick="editInbound(<?= $ib['id'] ?>)" title="Sửa phiếu tạm">
                                    <i class="ri-edit-line"></i>
                                </button>
                            <?php else: ?>
                                <button class="btn-icon-subtle" disabled title="Phiếu <?= $statusText ?> không thể sửa" style="opacity:0.35;cursor:not-allowed;">
                                    <i class="ri-edit-line"></i>
                                </button>
                            <?php endif; ?>
                            <?php if ($canDelete): ?>
                                <button class="btn-icon-subtle text-danger"
                                        onclick="confirmDeleteInbound(<?= $ib['id'] ?>)"
                                        title="Xóa phiếu tạm">
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

    <div id="bulkActionBar" class="bulk-action-bar">
        <span id="bulkCount" class="bulk-count">0 phiếu đã chọn</span>
        <div class="divider"></div>
        <button onclick="exportSelectedInboundExcel()" class="btn-export">
            <i class="ri-download-cloud-2-line"></i> Xuất Excel
        </button>
        <button onclick="clearInboundSelection()" class="btn-clear">Bỏ chọn</button>
    </div>

    <?php if ($total_pages > 1): ?>
    <div class="pagination-modern">
        <div class="page-numbers">
            <?php $baseParams = $_GET; unset($baseParams['page']); ?>
            <?php if ($page > 1): ?>
                <a href="?<?= http_build_query(array_merge($baseParams, ['page' => $page - 1])) ?>">
                    <i class="ri-arrow-left-s-line"></i>
                </a>
            <?php else: ?>
                <span class="disabled"><i class="ri-arrow-left-s-line"></i></span>
            <?php endif; ?>
            <?php
            $range     = 1;
            $show_dots = false;
            for ($i = 1; $i <= $total_pages; $i++):
                if ($i == 1 || $i == $total_pages || ($i >= $page - $range && $i <= $page + $range)):
                    if ($show_dots) { echo '<span class="dots">...</span>'; $show_dots = false; }
                    echo '<a href="?' . http_build_query(array_merge($baseParams, ['page' => $i])) . '" class="' . ($i === $page ? 'active' : '') . '">' . $i . '</a>';
                else:
                    $show_dots = true;
                endif;
            endfor;
            ?>
            <?php if ($page < $total_pages): ?>
                <a href="?<?= http_build_query(array_merge($baseParams, ['page' => $page + 1])) ?>">
                    <i class="ri-arrow-right-s-line"></i>
                </a>
            <?php else: ?>
                <span class="disabled"><i class="ri-arrow-right-s-line"></i></span>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- ── Modal: Xem chi tiết phiếu nhập ─────────────────────────────────── -->
<div id="detailModal" class="modal">
    <div class="modal-content modal-lg">
        <div class="modal-header">
            <h3>Chi tiết phiếu nhập</h3>
            <div class="modal-header-actions">
                <button class="btn btn-sm btn-secondary" onclick="exportInboundDetailExcel()">
                    <i class="ri-file-excel-line"></i> Excel
                </button>
                <button class="btn btn-sm btn-secondary" onclick="printInboundDetail()">
                    <i class="ri-printer-line"></i> In
                </button>
                <span class="close" onclick="closeInboundDetailModal()">&times;</span>
            </div>
        </div>
        <div id="detailContent" style="padding:20px"></div>
    </div>
</div>

<!-- ── Modal: Sửa phiếu nhập ──────────────────────────────────────────── -->
<div id="editInboundModal" class="modal">
    <div class="modal-content edit-modal-doc">

        <form id="editInboundForm" method="POST" action="process.php"
              onsubmit="return validateEditInboundFormBeforeSubmit()">
            <input type="hidden" name="action"      value="edit_inbound">
            <input type="hidden" name="id"          id="editInboundId">
            <input type="hidden" name="_csrf_token" id="editCsrfToken" value="<?= htmlspecialchars($csrf->getToken()) ?>">

            <div class="inv-header-strip" style="border-radius:24px 24px 0 0;">
                <div class="inv-strip-left">
                    <div class="inv-strip-label">Chỉnh sửa phiếu nhập</div>
                </div>
                <div style="display:flex; align-items:center; gap:20px;">
                    <div class="inv-strip-right">
                        <div class="inv-strip-date-label">Ngày tạo</div>
                        <div class="inv-strip-date-val" id="editCreatedDate">—</div>
                    </div>
                    <span class="close edit-modal-close" onclick="closeEditInboundModal()">&times;</span>
                </div>
            </div>

            <div class="inv-header-grid">
                <div class="inv-col">
                    <h4 class="inv-section-title">Nhà cung cấp</h4>
                    <select name="supplier_id" id="editSupplierId" required class="inv-input">
                        <option value="">— Chọn nhà cung cấp —</option>
                        <?php foreach ($suppliers as $sup): ?>
                            <option value="<?= $sup['id'] ?>"><?= htmlspecialchars($sup['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div id="editSupplierInfoBox" class="inv-supplier-box" style="display:none;">
                        <strong id="editBoxSupName"></strong>
                        <span>Mã NCC · Đã xác minh</span>
                    </div>
                </div>

                <div class="inv-col">
                    <h4 class="inv-section-title">Chi tiết phiếu</h4>
                    <div class="inv-details-2x2">
                        <div class="form-group">
                            <label>Số tham chiếu</label>
                            <input type="text" name="ref_no" id="editRefNo" class="inv-field-input" placeholder="Mã phiếu">
                        </div>
                        <div class="form-group">
                            <label>Trạng thái</label>
                            <select name="status" id="editStatus" class="inv-input" style="padding-top:8px; padding-bottom:8px;">
                                <option value="pending">Tạm thời</option>
                                <option value="completed">Hoàn thành</option>
                                <option value="cancelled">Đã hủy</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <div class="inv-body">
                <div class="section-header">
                    <h4 class="inv-section-title" style="margin:0;">Danh sách sản phẩm</h4>
                </div>
                <div class="table-responsive">
                    <table class="data-table" id="editInboundItemsTable">
                        <thead>
                            <tr>
                                <th style="width:36px; text-align:center;">#</th>
                                <th style="min-width:220px;">Sản phẩm</th>
                                <th style="width:90px;">Số lô</th>
                                <th style="width:110px;">NSX</th>
                                <th style="width:110px;">HSD</th>
                                <th style="width:80px; text-align:right;">SL</th>
                                <th style="width:120px; text-align:right;">Đơn giá</th>
                                <th style="width:130px; text-align:right;">Thành tiền</th>
                                <th style="width:36px;"></th>
                            </tr>
                        </thead>
                        <tbody id="editItemsBody"></tbody>
                    </table>
                </div>
                <div class="inv-add-row">
                    <button type="button" class="btn-add-line" onclick="addEditInboundRow()">
                        <i class="ri-add-line"></i> Thêm dòng mới
                    </button>
                </div>
            </div>

            <div class="inv-footer-grid">
                <div class="inv-note-section">
                    <h4 class="inv-section-title" style="margin-bottom:8px;">Ghi chú</h4>
                    <textarea name="note" id="editNote" class="inv-textarea" placeholder="Ghi chú cho phiếu này..."></textarea>
                </div>
                <div class="inv-summary-section">
                    <div class="inv-summary-row">
                        <span>Tổng tiền hàng</span>
                        <span id="editTotalAmountDisplay">0 đ</span>
                    </div>
                    <div class="inv-summary-row">
                        <span>Chiết khấu</span>
                        <span>—</span>
                    </div>
                    <div class="inv-summary-row inv-total">
                        <span>Tổng cộng</span>
                        <span id="editPayAmount">0 đ</span>
                    </div>
                    <input type="hidden" name="total_amount" id="editTotalAmount" value="0">
                </div>
            </div>

            <div class="inv-actions" style="border-radius:0 0 24px 24px;">
                <button type="button" class="btn inv-btn-cancel" onclick="closeEditInboundModal()">Hủy bỏ</button>
                <button type="submit" class="btn inv-btn-save">
                    <i class="ri-check-line"></i> Lưu thay đổi
                </button>
            </div>

        </form>
    </div>
</div>

<template id="rowTemplateEdit">
    <tr class="item-row">
        <td class="stt-cell" style="text-align:center;"></td>
        <td style="min-width:200px; position:relative;">
            <input type="text" class="product-autocomplete" name="product_name[]" placeholder="Nhập tên hoặc SKU" autocomplete="off" style="width:100%">
            <input type="hidden" name="product_id[]" class="product-id">
            <div class="product-info"></div>
        </td>
        <td><input type="text"   name="batch_no[]"   required placeholder="Lô"  style="width:100%;"></td>
        <td><input type="date"   name="mfg_date[]"   style="width:100%;"></td>
        <td><input type="date"   name="exp_date[]"   required style="width:100%;"></td>
        <td><input type="number" name="quantity[]"   class="qty"   value="1" min="1" required style="width:72px; text-align:right;"></td>
        <td><input type="text"   name="unit_price[]" class="price" step="1000" required style="width:110px; text-align:right;"></td>
        <td style="text-align:right;">
            <input type="text" class="row-total" readonly style="width:100%; text-align:right; font-weight:600;">
        </td>
        <td style="text-align:center;">
            <button type="button" class="remove-row" onclick="removeEditInboundRow(this)">
                <i class="ri-delete-bin-line"></i>
            </button>
        </td>
    </tr>
</template>

<script>
function toggleFilterBar() {
    const bar = document.getElementById('filterBar');
    bar.style.display = bar.style.display === 'none' ? 'flex' : 'none';
}
function toggleDropdown(panelId) {
    document.querySelectorAll('.dropdown-panel').forEach(p => {
        if (p.id !== panelId) p.classList.remove('show');
    });
    document.getElementById(panelId)?.classList.toggle('show');
}
document.addEventListener('click', function (e) {
    if (!e.target.closest('.custom-dropdown'))
        document.querySelectorAll('.dropdown-panel').forEach(p => p.classList.remove('show'));
});
</script>

<script src="<?= BASE_URL ?>/js/utils.js"></script>
<script src="<?= BASE_URL ?>/js/suppliers.js"></script>
<script src="<?= BASE_URL ?>/js/inbound.js"></script>

<?php include __DIR__ . '/../../layout/footer.php'; ?>