<?php
// pages/inbound/create.php
$page_title = "TẠO PHIẾU NHẬP";

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use App\Services\CsrfService;
use App\Repositories\SupplierRepository;
use App\Repositories\CategoryRepository;

requireLogin();

$flash_error = getFlash('error');$csrf        = new CsrfService();

$supplierRepo = new SupplierRepository($pdo);
$categoryRepo = new CategoryRepository($pdo);

$suppliers  =$supplierRepo->listActive();
$categories =$categoryRepo->listActive();

$csrfToken = $csrf->getToken();$body_page = 'inbound-create';
include __DIR__ . '/../../layout/header.php';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/css/suppliers.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/inbound.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/inbound-create.css">
<meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>">

<script>document.body.dataset.page = '<?= htmlspecialchars($body_page) ?>';</script>

<!-- Sử dụng đúng container inbound-create-wrapper có padding cách lề sidebar -->
<div class="inbound-create-wrapper">
    <!-- TOP BAR -->
    <div class="sup-header-breadcrumb-bar">
        <div class="breadcrumb-links">
            <a href="index.php" class="bc-prev">Phiếu nhập kho</a>
            <i class="ri-arrow-right-s-line bc-sep"></i>
            <span class="bc-cur">Tạo phiếu nhập mới</span>
        </div>
        <div class="header-action-group">
            <span class="clean-badge st-completed">
                <i class="ri-user-line"></i> <?= htmlspecialchars($_SESSION['user_name'] ?? 'Admin') ?>
            </span>
            <span class="clean-badge" style="background:#f4f4f5; color:#52525b;">
                <i class="ri-calendar-line"></i> <?= date('d/m/Y') ?>
            </span>
        </div>
    </div>

    <?php if ($flash_error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($flash_error) ?></div>
    <?php endif; ?>

    <form id="inboundForm" method="POST" action="process.php" onsubmit="return validateInboundFormBeforeSubmit()">
        <input type="hidden" name="action"      value="add_inbound">
        <input type="hidden" name="status"      id="inboundStatus" value="completed">
        <input type="hidden" name="_csrf_token" id="inboundCsrfToken" value="<?= htmlspecialchars($csrfToken) ?>">
        <input type="hidden" name="total_amount" id="totalAmountInput" value="0">

        <!-- BỐ CỤC SPLIT SCREEN 2 CỘT -->
        <div class="split-invoice-layout">
            
            <!-- CỘT TRÁI: FORM NHẬP LIỆU -->
            <div class="split-form-panel">
                <!-- 1. Header & Thông tin phiếu -->
                <div class="split-section-header">
                    <div>
                        <h3 class="panel-main-title">Thông tin phiếu nhập</h3>
                        <p class="panel-subtitle">Điền thông tin đối tác và chứng từ nhập kho.</p>
                    </div>
                </div>

                <div class="form-grid-2">
                    <div class="form-row-modern">
                        <label class="form-label-modern">Nhà cung cấp <span class="text-danger">*</span></label>
                        <select name="supplier_id" id="supplierSelect" required class="form-input-modern" onchange="syncInvoicePreview()">
                            <option value="">— Chọn nhà cung cấp —</option>
                            <?php foreach ($suppliers as$sup): ?>
                                <option value="<?= $sup['id'] ?>"><?= htmlspecialchars($sup['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-row-modern">
                        <label class="form-label-modern">Số tham chiếu (Mã phiếu)</label>
                        <input type="text" name="ref_no" id="refNoInput" class="form-input-modern" placeholder="Tự động sinh mã..." oninput="syncInvoicePreview()">
                    </div>
                </div>

                <div class="form-grid-2">
                    <div class="form-row-modern">
                        <label class="form-label-modern">Ngày lập phiếu</label>
                        <input type="date" id="createdDateInput" class="form-input-modern" value="<?= date('Y-m-d') ?>" oninput="syncInvoicePreview()">
                    </div>

                    <div class="form-row-modern">
                        <label class="form-label-modern">Người lập phiếu</label>
                        <input type="text" class="form-input-modern" value="<?= htmlspecialchars($_SESSION['user_name'] ?? 'Admin') ?>" readonly style="background:#f4f4f5; color:#71717a;">
                    </div>
                </div>

                <div class="form-row-modern">
                    <label class="form-label-modern">Ghi chú phiếu nhập</label>
                    <textarea name="note" id="noteInput" rows="2" class="form-input-modern" placeholder="Ghi chú thêm về lô hàng..." oninput="syncInvoicePreview()"></textarea>
                </div>

                <div class="split-divider"></div>

                <!-- 2. Danh sách sản phẩm -->
                <div class="split-section-header">
                    <div>
                        <h3 class="panel-main-title">Chi tiết sản phẩm</h3>
                        <p class="panel-subtitle">Nhập danh sách mã hàng, số lượng và đơn giá.</p>
                    </div>
                    <button type="button" class="btn-modern-outline" onclick="openProductModal('add')" style="padding: 4px 10px; font-size: 12px;">
                        <i class="ri-add-line"></i> Tạo mã mới
                    </button>
                </div>

                <!-- Bảng nhập liệu mini -->
                <div class="split-table-wrap">
                    <table class="detail-data-table" id="itemsTable">
                        <thead>
                            <tr>
                                <th width="32" class="text-center">#</th>
                                <th style="min-width: 170px;">Sản phẩm</th>
                                <th width="90">Lô</th>
                                <th width="115">HSD</th>
                                <th width="65" class="text-right">SL</th>
                                <th width="110" class="text-right">Đơn giá</th>
                                <th width="110" class="text-right">Thành tiền</th>
                                <th width="32"></th>
                            </tr>
                        </thead>
                        <tbody id="itemsBody"></tbody>
                    </table>
                </div>

                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <button type="button" class="btn-modern-outline" onclick="addInboundRow()">
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
                        <i class="ri-check-line"></i> Lưu phiếu nhập
                    </button>
                </div>
            </div>

            <!-- CỘT PHẢI: BẢN XEM TRƯỚC HÓA ĐƠN -->
            <div class="invoice-paper-preview">
                <div class="paper-fold-corner"></div>

                <div class="inv-doc-header">
                    <div class="inv-doc-title">Phiếu Nhập Kho</div>
                    <div class="inv-doc-code" id="prevRefNo">INV-<?= date('ymd') ?></div>
                </div>

                <!-- Đơn vị nhận & Đơn vị cấp -->
                <div class="inv-doc-party-grid">
                    <div class="party-col">
                        <span class="party-role">Đơn vị nhận (Billed to):</span>
                        <strong class="party-name">Kho SmartWare</strong>
                        <span class="party-desc">Kho trung tâm · Hệ thống</span>
                        <span class="party-desc">TP. Hồ Chí Minh</span>
                    </div>

                    <div class="party-col">
                        <span class="party-role">Nhà cung cấp (Billed by):</span>
                        <strong class="party-name" id="prevSupplierName">Chưa chọn đối tác</strong>
                        <span class="party-desc" id="prevSupplierDesc">Mã đối tác · Xác minh</span>
                    </div>
                </div>

                <!-- Ngày tạo & Người phụ trách -->
                <div class="inv-doc-dates-grid">
                    <div class="date-col">
                        <span class="date-lbl">Ngày tạo phiếu:</span>
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
                        <span class="th-qty">SL</span>
                        <span class="th-cost">Đơn giá</span>
                        <span class="th-total">Thành tiền</span>
                    </div>
                    <div class="inv-doc-table-body" id="prevItemsList">
                        <div class="prev-empty-hint">Chưa có dòng sản phẩm nào</div>
                    </div>
                </div>

                <!-- Tóm tắt số tiền -->
                <div class="inv-doc-summary-box">
                    <div class="sum-line">
                        <span>Tổng tiền hàng:</span>
                        <span id="prevSubtotal">0 ₫</span>
                    </div>
                    <div class="sum-line">
                        <span>Thuế & Chiết khấu:</span>
                        <span>0 ₫</span>
                    </div>
                    <div class="sum-line sum-total">
                        <span>TỔNG CỘNG:</span>
                        <span id="prevGrandTotal">0 ₫</span>
                    </div>
                </div>

                <div class="inv-doc-footer-note">
                    <p id="prevNote">Cảm ơn bạn đã hợp tác cùng hệ thống quản lý chuỗi cung ứng SmartWare.</p>
                </div>
            </div>

        </div>
    </form>
</div>

<!-- Modal: Thêm sản phẩm nhanh -->
<div id="productModal" class="modal-modern" style="display:none;">
    <div class="modal-modern-dialog" style="max-width: 680px;">
        <div class="modal-modern-header">
            <div>
                <h3>Thêm sản phẩm mới</h3>
                <p class="modal-subtitle">Tạo nhanh sản phẩm liên kết vào kho hàng.</p>
            </div>
            <button type="button" class="btn-close-modern" onclick="closeProductModal()">&times;</button>
        </div>
        <form id="productForm" onsubmit="submitNewProduct(event)">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <div class="modal-tabs-body">
                <div class="form-grid-2">
                    <div class="form-row-modern">
                        <label class="form-label-modern">Tên sản phẩm <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="prodName" required class="form-input-modern">
                    </div>
                    <div class="form-row-modern">
                        <label class="form-label-modern">Mã SKU</label>
                        <input type="text" name="sku" id="prodSku" class="form-input-modern">
                    </div>
                </div>

                <div class="form-grid-2">
                    <div class="form-row-modern">
                        <label class="form-label-modern">Danh mục</label>
                        <select name="category_id" id="prodCategory" class="form-input-modern">
                            <option value="">— Chọn danh mục —</option>
                            <?php foreach ($categories as$cat): ?>
                                <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-row-modern">
                        <label class="form-label-modern">Đơn vị tính <span class="text-danger">*</span></label>
                        <input type="text" name="unit" id="prodUnit" required class="form-input-modern">
                    </div>
                </div>

                <div class="form-grid-2">
                    <div class="form-row-modern">
                        <label class="form-label-modern">Giá vốn (VNĐ)</label>
                        <input type="number" name="cost_price" id="prodCostPrice" step="1000" min="0" value="0" class="form-input-modern">
                    </div>
                    <div class="form-row-modern">
                        <label class="form-label-modern">Giá bán (VNĐ) <span class="text-danger">*</span></label>
                        <input type="number" name="price" id="prodPrice" step="1000" min="1" required class="form-input-modern">
                    </div>
                </div>
            </div>
            <div class="modal-modern-footer">
                <button type="button" class="btn-modern-outline" onclick="closeProductModal()">Hủy</button>
                <button type="submit" class="btn-modern-dark">Lưu sản phẩm</button>
            </div>
        </form>
    </div>
</div>

<template id="rowTemplate">
    <tr class="item-row">
        <td class="stt-cell text-center" style="color: #a1a1aa; font-weight: 500;"></td>
        <td>
            <input type="text" class="product-autocomplete form-input-modern" name="product_name[]" placeholder="Nhập tên / SKU..." autocomplete="off" style="padding: 6px 10px; font-size: 13px;" oninput="syncInvoicePreview()">
            <input type="hidden" name="product_id[]" class="product-id">
            <div class="product-info"></div>
        </td>
        <td><input type="text" name="batch_no[]" required placeholder="Lô" class="form-input-modern" style="padding: 6px 8px; font-size: 13px;"></td>
        <td><input type="date" name="exp_date[]" required class="form-input-modern" style="padding: 6px 6px; font-size: 12.5px;"></td>
        <td><input type="number" name="quantity[]" class="qty form-input-modern" value="1" min="1" required style="text-align: right; padding: 6px 6px; font-size: 13px;" oninput="syncInvoicePreview()"></td>
        <td><input type="text" name="unit_price[]" class="price form-input-modern" step="1000" required style="text-align: right; padding: 6px 8px; font-size: 13px;" oninput="syncInvoicePreview()"></td>
        <td style="text-align: right;">
            <input type="text" class="row-total form-input-modern" readonly style="text-align: right; font-weight: 600; background: transparent; border: none; padding: 6px 6px; color: var(--text-main); font-size: 13px;">
        </td>
        <td class="text-center">
            <button type="button" class="btn-action-icon btn-action-delete" onclick="removeInboundRow(this)" title="Xóa dòng">
                <i class="ri-delete-bin-line"></i>
            </button>
        </td>
    </tr>
</template>

<script src="<?= BASE_URL ?>/js/utils.js"></script>
<script src="<?= BASE_URL ?>/js/products.js"></script>
<script src="<?= BASE_URL ?>/js/suppliers.js"></script>
<script src="<?= BASE_URL ?>/js/inbound.js"></script>

<script>
function syncInvoicePreview() {
    const supSelect = document.getElementById('supplierSelect');
    const prevSupName = document.getElementById('prevSupplierName');
    if (supSelect && prevSupName) {
        prevSupName.innerText = supSelect.selectedIndex > 0 ? supSelect.options[supSelect.selectedIndex].text : 'Chưa chọn đối tác';
    }

    const refInput = document.getElementById('refNoInput');
    const prevRefNo = document.getElementById('prevRefNo');
    if (refInput && prevRefNo) {
        prevRefNo.innerText = refInput.value.trim() ? refInput.value.trim() : 'INV-<?= date('ymd') ?>';
    }

    const dateInput = document.getElementById('createdDateInput');
    const prevDate = document.getElementById('prevCreatedDate');
    if (dateInput && prevDate && dateInput.value) {
        const parts = dateInput.value.split('-');
        if (parts.length === 3) prevDate.innerText = `${parts[2]}/${parts[1]}/${parts[0]}`;
    }

    const prevList = document.getElementById('prevItemsList');
    const rows = document.querySelectorAll('#itemsBody .item-row');
    if (!prevList) return;

    let itemsHtml = '';
    let grandTotal = 0;

    rows.forEach(row => {
        const name = row.querySelector('.product-autocomplete')?.value.trim();
        const qty = parseFloat(row.querySelector('.qty')?.value) || 0;
        const price = unformatNumber(row.querySelector('.price')?.value || '0');
        const rowTotal = qty * price;
        grandTotal += rowTotal;

        if (name) {
            itemsHtml += `
                <div class="inv-doc-table-row">
                    <span class="td-item">${escapeHtml(name)}</span>
                    <span class="td-qty">${qty}</span>
                    <span class="td-cost">${formatNumberInput(String(price))} ₫</span>
                    <span class="td-total">${formatNumberInput(String(rowTotal))} ₫</span>
                </div>
            `;
        }
    });

    prevList.innerHTML = itemsHtml || '<div class="prev-empty-hint">Chưa có dòng sản phẩm nào</div>';

    const formatted = formatNumberInput(String(grandTotal)) + ' ₫';
    document.getElementById('prevSubtotal').innerText = formatted;
    document.getElementById('prevGrandTotal').innerText = formatted;
    document.getElementById('totalAmountInput').value = grandTotal;

    const noteVal = document.getElementById('noteInput')?.value.trim();
    const prevNote = document.getElementById('prevNote');
    if (prevNote) {
        prevNote.innerText = noteVal ? noteVal : 'Cảm ơn bạn đã hợp tác cùng hệ thống quản lý chuỗi cung ứng SmartWare.';
    }
}

document.addEventListener('DOMContentLoaded', function () {
    const btnComplete = document.getElementById('btnComplete');
    const btnDraft    = document.getElementById('btnDraft');
    const statusInput = document.getElementById('inboundStatus');

    if (btnComplete) btnComplete.addEventListener('click', () => { statusInput.value = 'completed'; });
    if (btnDraft)    btnDraft.addEventListener('click', () => { statusInput.value = 'pending'; });

    setTimeout(syncInvoicePreview, 300);
});
</script>

<?php include __DIR__ . '/../../layout/footer.php'; ?>