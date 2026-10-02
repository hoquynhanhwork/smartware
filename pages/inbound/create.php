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

$flash_error = getFlash('error');
$csrf        = new CsrfService();

$supplierRepo = new SupplierRepository($pdo);
$categoryRepo = new CategoryRepository($pdo);

// ❌ Đã xóa `company_id` khỏi câu truy vấn
$suppliers  = $supplierRepo->listActive();
$categories = $categoryRepo->listActive();

$csrfToken = $csrf->getToken();
$body_page = 'inbound-create';
include __DIR__ . '/../../layout/header.php';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/css/products.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/suppliers.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/inbound.css">
<meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>">

<script>document.body.dataset.page = '<?= htmlspecialchars($body_page) ?>';</script>

<?php if ($flash_error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($flash_error) ?></div>
<?php endif; ?>

<form id="inboundForm" method="POST" action="process.php"
      onsubmit="return validateInboundFormBeforeSubmit()">
    <input type="hidden" name="action"      value="add_inbound">
    <input type="hidden" name="status"      id="inboundStatus" value="completed">
    <input type="hidden" name="_csrf_token" id="inboundCsrfToken" value="<?= htmlspecialchars($csrfToken) ?>">

<div class="inbound-single-layout">
    <div class="create-crumb-bar">
        <a href="index.php" class="btn-back">
            <i class="ri-arrow-left-line"></i> Trở lại
        </a>
        <div style="display:flex; gap:8px;">
            <div class="info-pill"><i class="ri-user-line"></i> <?= htmlspecialchars($_SESSION['user_name'] ?? 'Admin') ?></div>
            <div class="info-pill"><i class="ri-calendar-line"></i> <?= date('d/m/Y') ?></div>
        </div>
    </div>

    <div class="invoice-document">

        <div class="inv-header-strip">
            <div class="inv-strip-left">
                <div class="inv-strip-label">Phiếu nhập kho</div>
            </div>
            <div class="inv-strip-right">
                <div class="inv-strip-date-label">Ngày tạo</div>
                <div class="inv-strip-date-val"><?= date('d/m/Y') ?></div>
            </div>
        </div>

        <div class="inv-header-grid">
            <div class="inv-col">
                <h4 class="inv-section-title">Nhà cung cấp</h4>
                <select name="supplier_id" id="supplierSelect" required class="inv-input">
                    <option value="">— Chọn nhà cung cấp —</option>
                    <?php foreach ($suppliers as $sup): ?>
                        <option value="<?= $sup['id'] ?>"><?= htmlspecialchars($sup['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <div id="supplierInfoBox" class="inv-supplier-box" style="display:none;">
                    <strong id="boxSupName"></strong>
                    <span>Mã NCC · Đã xác minh</span>
                </div>
            </div>

            <div class="inv-col">
                <div class="inv-details-2x2">
                    <div class="form-group">
                        <label>Ngày tạo</label>
                        <div class="readonly-div inv-input">
                            <i class="ri-calendar-event-line"></i> <?= date('d/m/Y') ?>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Số tham chiếu</label>
                        <input type="text" name="ref_no" class="inv-field-input" placeholder="Tự động sinh mã...">
                    </div>
                </div>
            </div>
        </div>

        <div class="inv-body">
            <div class="section-header">
                <h4 class="inv-section-title" style="margin:0;">Danh sách sản phẩm</h4>
                <button type="button" class="btn-text-action" onclick="openProductModal('add')">
                    <i class="ri-apps-line"></i> Quản lý danh mục
                </button>
            </div>

            <div class="table-responsive">
                <table class="data-table" id="itemsTable">
                    <thead>
                        <tr>
                            <th style="width:36px; text-align:center;">#</th>
                            <th style="min-width:200px;">Sản phẩm</th>
                            <th style="width:150px;">Số lô</th>
                            <th style="width:110px;">NSX</th>
                            <th style="width:110px;">HSD</th>
                            <th style="width:80px; text-align:right;">SL</th>
                            <th style="width:120px; text-align:right;">Đơn giá</th>
                            <th style="width:130px; text-align:right;">Thành tiền</th>
                            <th style="width:36px;"></th>
                        </tr>
                    </thead>
                    <tbody id="itemsBody"></tbody>
                </table>
            </div>

            <div class="inv-add-row">
                <button type="button" class="btn-add-line" onclick="addInboundRow()">
                    <i class="ri-add-line"></i> Thêm dòng mới
                </button>
            </div>
        </div>

        <div class="inv-footer-grid">
            <div class="inv-note-section">
                <h4 class="inv-section-title" style="margin-bottom:8px;">Ghi chú</h4>
                <textarea name="note" class="inv-textarea" placeholder="Nhập ghi chú..."></textarea>
            </div>

            <div class="inv-summary-section">
                <input type="hidden" name="total_amount" id="totalAmountInput" value="0">
                <div class="inv-summary-row">
                    <span>Tổng tiền hàng</span>
                    <span id="totalAmountDisplay">0 đ</span>
                </div>
                <div class="inv-summary-row">
                    <span>Chiết khấu</span>
                    <span>—</span>
                </div>
                <div id="pendingWarning" style="display:none; color:#d97706; font-size:12px; margin:6px 0;">
                    * Lưu tạm: Chưa cập nhật tồn kho
                </div>
                <div class="inv-summary-row inv-total">
                    <span>Tổng cộng</span>
                    <span id="payAmount">0 đ</span>
                </div>
            </div>
        </div>

        <div class="inv-actions">
            <a href="index.php" class="btn inv-btn-cancel">Hủy bỏ</a>
            <button type="submit" name="submit_action" value="draft" class="btn inv-btn-draft" id="btnDraft">
                <i class="ri-save-line"></i> Lưu tạm
            </button>
            <button type="submit" name="submit_action" value="complete" class="btn inv-btn-save" id="btnComplete">
                <i class="ri-check-line"></i> Lưu phiếu nhập
            </button>
        </div>

    </div>
</div>
</form>

<!-- Modal: Thêm sản phẩm nhanh -->
<div id="productModal" class="modal">
    <div class="modal-content modal-lg">
        <div class="modal-header">
            <h3>Thêm sản phẩm mới</h3>
            <span class="close" onclick="closeProductModal()">&times;</span>
        </div>
        <form id="productForm" onsubmit="submitNewProduct(event)">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <div class="form-grid">
                <div class="form-group">
                    <label>Tên sản phẩm <span class="required">*</span></label>
                    <input type="text" name="name" id="prodName" required>
                </div>
                <div class="form-group">
                    <label>SKU</label>
                    <input type="text" name="sku" id="prodSku">
                </div>
                <div class="form-group category-row">
                    <label>Danh mục</label>
                    <select name="category_id" id="prodCategory">
                        <option value="">-- Chọn --</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="btn-add" onclick="openCategoryModal()">Thêm</button>
                </div>
                <div class="form-group supplier-row">
                    <label>Nhà cung cấp</label>
                    <select name="supplier_id" id="prodSupplier">
                        <option value="">-- Chọn --</option>
                        <?php foreach ($suppliers as $sup): ?>
                            <option value="<?= $sup['id'] ?>"><?= htmlspecialchars($sup['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="btn-add" onclick="openSupplierModal('add')">Thêm</button>
                </div>
                <div class="form-group">
                    <label>Giá vốn (VNĐ)</label>
                    <input type="number" name="cost_price" id="prodCostPrice" step="1000" min="0" value="0">
                </div>
                <div class="form-group">
                    <label>Giá bán (VNĐ) <span class="required">*</span></label>
                    <input type="number" name="price" id="prodPrice" step="1000" min="1" required>
                </div>
                <div class="form-group">
                    <label>Đơn vị tính <span class="required">*</span></label>
                    <input type="text" name="unit" id="prodUnit" required>
                </div>
                <div class="form-group">
                    <label>Trạng thái</label>
                    <select name="status" id="prodStatus">
                        <option value="active">Đang hoạt động</option>
                        <option value="inactive">Ngừng hoạt động</option>
                    </select>
                </div>
                <div class="form-group full-width">
                    <label>Mô tả</label>
                    <textarea name="description" id="prodDesc" rows="2"></textarea>
                </div>
            </div>
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeProductModal()">Hủy</button>
                <button type="submit" class="btn btn-primary">Lưu sản phẩm</button>
            </div>
        </form>
    </div>
</div>

<template id="rowTemplate">
    <tr class="item-row">
        <td class="stt-cell" style="text-align:center;"></td>
        <td style="min-width:200px; position:relative;">
            <input type="text" class="product-autocomplete" name="product_name[]" placeholder="Nhập tên hoặc SKU" autocomplete="off" style="width:100%;">
            <input type="hidden" name="product_id[]" class="product-id">
            <div class="product-info"></div>
        </td>
        <td><input type="text"   name="batch_no[]"  required placeholder="Lô" style="width:100%;"></td>
        <td><input type="date"   name="mfg_date[]"  style="width:100%;"></td>
        <td><input type="date"   name="exp_date[]"  required style="width:100%;"></td>
        <td><input type="number" name="quantity[]"  class="qty"   value="1" min="1" required style="width:72px; text-align:right;"></td>
        <td><input type="text"   name="unit_price[]" class="price" step="1000" required style="width:110px; text-align:right;"></td>
        <td style="text-align:right;">
            <input type="text" class="row-total" readonly style="width:100%; text-align:right; font-weight:600;">
        </td>
        <td style="text-align:center;">
            <button type="button" class="remove-row" onclick="removeInboundRow(this)">
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
(function () {
    const btnComplete     = document.getElementById('btnComplete');
    const btnDraft        = document.getElementById('btnDraft');
    const statusInput     = document.getElementById('inboundStatus');
    const warning         = document.getElementById('pendingWarning');
    const supplierSelect  = document.getElementById('supplierSelect');
    const supplierInfoBox = document.getElementById('supplierInfoBox');
    const boxSupName      = document.getElementById('boxSupName');

    btnComplete.addEventListener('click', function () {
        statusInput.value     = 'completed';
        warning.style.display = 'none';
    });

    btnDraft.addEventListener('click', function () {
        statusInput.value     = 'pending';
        warning.style.display = 'block';
    });

    supplierSelect.addEventListener('change', function () {
        if (this.value) {
            boxSupName.textContent        = this.options[this.selectedIndex].text;
            supplierInfoBox.style.display = 'block';
        } else {
            supplierInfoBox.style.display = 'none';
        }
    });
})();
</script>

<?php include __DIR__ . '/../../layout/footer.php'; ?>