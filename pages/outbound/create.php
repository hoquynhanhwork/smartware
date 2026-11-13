<?php
// pages/outbound/create.php
$page_title = "TẠO PHIẾU XUẤT";

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use App\Repositories\SupplierRepository;
use App\Repositories\CategoryRepository;
use App\Services\CsrfService;

requireLogin();

$flash_error = getFlash('error');$csrf        = new CsrfService();

$supplierRepo = new SupplierRepository($pdo);
$categoryRepo = new CategoryRepository($pdo);

$suppliers  =$supplierRepo->listActive();
$categories =$categoryRepo->listActive();

$csrfToken = $csrf->getToken();$body_page = 'outbound-create';
include __DIR__ . '/../../layout/header.php';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/css/suppliers.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/inbound.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/outbound.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/outbound-create.css">
<meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>">

<script>document.body.dataset.page = '<?= htmlspecialchars($body_page) ?>';</script>

<div class="outbound-create-wrapper">
    <!-- TOP BREADCRUMB BAR -->
    <div class="sup-header-breadcrumb-bar">
        <div class="breadcrumb-links">
            <a href="index.php" class="bc-prev">Phiếu xuất kho</a>
            <i class="ri-arrow-right-s-line bc-sep"></i>
            <span class="bc-cur">Tạo phiếu xuất mới</span>
        </div>
        <div class="header-action-group">
            <span class="clean-badge st-completed">
                <i class="ri-user-line"></i> <?= htmlspecialchars($_SESSION['user_name'] ?? 'Admin') ?>
            </span>
            <span class="clean-badge" style="background:var(--surface-subtle); color:var(--text-2);">
                <i class="ri-calendar-line"></i> <?= date('d/m/Y') ?>
            </span>
        </div>
    </div>

    <?php if ($flash_error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($flash_error) ?></div>
    <?php endif; ?>

    <form id="outboundForm" method="POST" action="process.php" onsubmit="return validateOutboundFormBeforeSubmit()">
        <input type="hidden" name="action"      value="add_outbound">
        <input type="hidden" name="status"      id="outboundStatus" value="completed">
        <input type="hidden" name="csrf_token"  value="<?= htmlspecialchars($csrfToken) ?>">

        <!-- BỐ CỤC SPLIT SCREEN 2 CỘT -->
        <div class="split-invoice-layout">

            <!-- CỘT TRÁI: FORM NHẬP LIỆU -->
            <div class="split-form-panel">
                <!-- 1. Header & Thông tin phiếu -->
                <div class="split-section-header">
                    <div>
                        <h3 class="panel-main-title">Thông tin phiếu xuất</h3>
                        <p class="panel-subtitle">Điền thông tin đối tác xuất hàng và chứng từ xuất kho.</p>
                    </div>
                </div>

                <div class="form-grid-2">
                    <div class="form-row-modern">
                        <label class="form-label-modern">Nhà cung cấp / Đối tác <span class="text-danger">*</span></label>
                        <select name="supplier_id" id="outboundSupplier" required class="form-input-modern" onchange="syncOutboundInvoicePreview()">
                            <option value="">— Chọn nhà cung cấp —</option>
                            <?php foreach ($suppliers as$sup): ?>
                                <option value="<?= (int) $sup['id'] ?>"><?= htmlspecialchars($sup['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-row-modern">
                        <label class="form-label-modern">Số tham chiếu (Mã phiếu)</label>
                        <input type="text" name="ref_no" id="refNoInput" class="form-input-modern" placeholder="Tự động sinh mã..." oninput="syncOutboundInvoicePreview()">
                    </div>
                </div>

                <div class="form-grid-2">
                    <div class="form-row-modern">
                        <label class="form-label-modern">Ngày lập phiếu</label>
                        <input type="date" id="createdDateInput" class="form-input-modern" value="<?= date('Y-m-d') ?>" oninput="syncOutboundInvoicePreview()">
                    </div>

                    <div class="form-row-modern">
                        <label class="form-label-modern">Người lập phiếu</label>
                        <input type="text" class="form-input-modern" value="<?= htmlspecialchars($_SESSION['user_name'] ?? 'Admin') ?>" readonly style="background:var(--surface-subtle); color:var(--text-muted);">
                    </div>
                </div>

                <div class="form-row-modern">
                    <label class="form-label-modern">Ghi chú phiếu xuất</label>
                    <textarea name="note" id="noteInput" rows="2" class="form-input-modern" placeholder="Lý do xuất kho, ghi chú vận chuyển..." oninput="syncOutboundInvoicePreview()"></textarea>
                </div>

                <div class="split-divider"></div>

                <!-- 2. Danh sách sản phẩm xuất kho -->
                <div class="split-section-header">
                    <div>
                        <h3 class="panel-main-title">Chi tiết sản phẩm</h3>
                        <p class="panel-subtitle">Chọn mã hàng, chọn số lô còn tồn và nhập số lượng xuất.</p>
                    </div>
                </div>

                <!-- Bảng nhập liệu mini -->
                <div class="split-table-wrap">
                    <table class="detail-data-table" id="outboundItemsTable">
                        <thead>
                            <tr>
                                <th width="32" class="text-center">#</th>
                                <th style="min-width: 200px;">Sản phẩm</th>
                                <th width="190">Số lô (Tồn kho)</th>
                                <th width="85" class="text-right">SL</th>
                                <th width="32"></th>
                            </tr>
                        </thead>
                        <tbody id="outboundItemsBody"></tbody>
                    </table>
                </div>

                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <button type="button" class="btn-modern-outline" onclick="addOutboundRow()">
                        <i class="ri-add-line"></i> Thêm dòng hàng
                    </button>
                </div>

                <!-- Các nút hành động chính -->
                <div class="split-form-footer-actions">
                    <a href="index.php" class="btn-modern-outline" style="text-decoration:none;">Hủy bỏ</a>
                    <button type="submit" name="submit_action" value="draft" class="btn-modern-outline" id="btnDraft">
                        <i class="ri-save-line"></i> Lưu tạm
                    </button>
                    <button type="submit" name="submit_action" value="complete" class="btn-modern-dark" id="btnComplete">
                        <i class="ri-check-line"></i> Lưu phiếu xuất
                    </button>
                </div>
            </div>

            <!-- CỘT PHẢI: BẢN XEM TRƯỚC HÓA ĐƠN XUẤT KHO -->
            <div class="invoice-paper-preview">
                <div class="paper-fold-corner"></div>

                <div class="inv-doc-header">
                    <div class="inv-doc-title">Phiếu Xuất Kho</div>
                    <div class="inv-doc-code" id="prevRefNo">OUT-<?= date('ymd') ?></div>
                </div>

                <!-- Đơn vị xuất & Đơn vị nhận -->
                <div class="inv-doc-party-grid">
                    <div class="party-col">
                        <span class="party-role">Đơn vị xuất (Billed by):</span>
                        <strong class="party-name">Kho SmartWare</strong>
                        <span class="party-desc">Kho trung tâm · Hệ thống</span>
                        <span class="party-desc">TP. Hồ Chí Minh</span>
                    </div>

                    <div class="party-col">
                        <span class="party-role">Đối tác nhận (Billed to):</span>
                        <strong class="party-name" id="prevSupplierName">Chưa chọn đối tác</strong>
                        <span class="party-desc" id="prevSupplierDesc">Mã đối tác · Xác minh</span>
                    </div>
                </div>

                <!-- Ngày tạo & Người phụ trách -->
                <div class="inv-doc-dates-grid">
                    <div class="date-col">
                        <span class="date-lbl">Ngày lập phiếu:</span>
                        <strong class="date-val" id="prevCreatedDate"><?= date('d/m/Y') ?></strong>
                    </div>
                    <div class="date-col">
                        <span class="date-lbl">Người phụ trách:</span>
                        <strong class="date-val"><?= htmlspecialchars($_SESSION['user_name'] ?? 'Admin') ?></strong>
                    </div>
                </div>

                <!-- Danh sách sản phẩm của tờ hóa đơn xem trước -->
                <div class="inv-doc-items-box">
                    <div class="inv-doc-table-head">
                        <span class="th-item">Tên sản phẩm</span>
                        <span class="th-batch">Lô xuất</span>
                        <span class="th-qty">SL</span>
                    </div>
                    <div class="inv-doc-table-body" id="prevItemsList">
                        <div class="prev-empty-hint">Chưa có dòng sản phẩm nào</div>
                    </div>
                </div>

                <!-- Tóm tắt số lượng -->
                <div class="inv-doc-summary-box">
                    <div class="sum-line sum-total">
                        <span>TỔNG SỐ LƯỢNG XUẤT:</span>
                        <span id="prevGrandTotalQty">0</span>
                    </div>
                </div>

                <div class="inv-doc-footer-note">
                    <p id="prevNote">Cảm ơn bạn đã hợp tác cùng hệ thống quản lý chuỗi cung ứng SmartWare.</p>
                </div>
            </div>

        </div>
    </form>
</div>

<template id="outboundRowTemplate">
    <tr class="item-row">
        <td class="stt-cell text-center" style="color: var(--text-faint); font-weight: 500;"></td>
        <td style="position:relative;">
            <input type="text"
                   class="product-autocomplete-outbound form-input-modern"
                   name="product_name[]"
                   placeholder="Nhập tên hoặc SKU"
                   autocomplete="off"
                   style="padding: 6px 10px; font-size: 13px;"
                   oninput="syncOutboundInvoicePreview()">
            <input type="hidden" name="product_id[]" class="product-id">
            <div class="product-info"></div>
        </td>
        <td>
            <select name="batch_no[]" class="batch-select-outbound form-input-modern" style="padding: 6px 6px; font-size: 12.5px;" onchange="syncOutboundInvoicePreview()">
                <option value="">-- Chọn lô --</option>
            </select>
            <div class="batch-stock-info"></div>
        </td>
        <td>
            <input type="text"
                   name="quantity[]"
                   class="qty-outbound form-input-modern"
                   value="1"
                   required
                   style="text-align: right; padding: 6px 6px; font-size: 13px;"
                   oninput="syncOutboundInvoicePreview()">
        </td>
        <td class="text-center">
            <button type="button" class="btn-action-icon btn-action-delete" onclick="removeOutboundRow(this)" title="Xóa dòng">
                <i class="ri-delete-bin-line"></i>
            </button>
        </td>
    </tr>
</template>

<script src="<?= BASE_URL ?>/js/utils.js"></script>
<script src="<?= BASE_URL ?>/js/suppliers.js"></script>
<script src="<?= BASE_URL ?>/js/outbound.js"></script>

<script>
// Hàm đồng bộ tức thì sang tờ hóa đơn xem trước ở cột bên phải
function syncOutboundInvoicePreview() {
    const supSelect = document.getElementById('outboundSupplier');
    const prevSupName = document.getElementById('prevSupplierName');
    if (supSelect && prevSupName) {
        prevSupName.innerText = supSelect.selectedIndex > 0 ? supSelect.options[supSelect.selectedIndex].text : 'Chưa chọn đối tác';
    }

    const refInput = document.getElementById('refNoInput');
    const prevRefNo = document.getElementById('prevRefNo');
    if (refInput && prevRefNo) {
        prevRefNo.innerText = refInput.value.trim() ? refInput.value.trim() : 'OUT-<?= date('ymd') ?>';
    }

    const dateInput = document.getElementById('createdDateInput');
    const prevDate = document.getElementById('prevCreatedDate');
    if (dateInput && prevDate && dateInput.value) {
        const parts = dateInput.value.split('-');
        if (parts.length === 3) prevDate.innerText = `${parts[2]}/${parts[1]}/${parts[0]}`;
    }

    const prevList = document.getElementById('prevItemsList');
    const rows = document.querySelectorAll('#outboundItemsBody .item-row');
    if (!prevList) return;

    let itemsHtml = '';
    let totalQty = 0;

    rows.forEach(row => {
        const name = row.querySelector('.product-autocomplete-outbound')?.value.trim();
        const batchSelect = row.querySelector('.batch-select-outbound');
        const batch = batchSelect && batchSelect.value ? batchSelect.value : '—';
        const qty = parseFloat(unformatNumber(row.querySelector('.qty-outbound')?.value || '0')) || 0;
        totalQty += qty;

        if (name) {
            itemsHtml += `
                <div class="inv-doc-table-row">
                    <span class="td-item">${escapeHtml(name)}</span>
                    <span class="td-batch">${escapeHtml(batch)}</span>
                    <span class="td-qty">${qty}</span>
                </div>
            `;
        }
    });

    prevList.innerHTML = itemsHtml || '<div class="prev-empty-hint">Chưa có dòng sản phẩm nào</div>';
    document.getElementById('prevGrandTotalQty').innerText = formatNumberInput(String(totalQty));

    const noteVal = document.getElementById('noteInput')?.value.trim();
    const prevNote = document.getElementById('prevNote');
    if (prevNote) {
        prevNote.innerText = noteVal ? noteVal : 'Cảm ơn bạn đã hợp tác cùng hệ thống quản lý chuỗi cung ứng SmartWare.';
    }
}

document.addEventListener('DOMContentLoaded', function () {
    const btnComplete = document.getElementById('btnComplete');
    const btnDraft    = document.getElementById('btnDraft');
    const statusInput = document.getElementById('outboundStatus');

    if (btnComplete) btnComplete.addEventListener('click', () => { statusInput.value = 'completed'; });
    if (btnDraft)    btnDraft.addEventListener('click', () => { statusInput.value = 'pending'; });

    setTimeout(syncOutboundInvoicePreview, 300);
});
</script>

<?php include __DIR__ . '/../../layout/footer.php'; ?>