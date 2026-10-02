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

$flash_error = getFlash('error');
$csrf        = new CsrfService();

// ── Dữ liệu cho modal quick add ─────────────────────────────────────────
$supplierRepo = new SupplierRepository($pdo);
$categoryRepo = new CategoryRepository($pdo);

$suppliers  = $supplierRepo->listActive();
$categories = $categoryRepo->listActive();

$body_page = 'outbound-create';
include __DIR__ . '/../../layout/header.php';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/css/outbound.css">
<script>document.body.dataset.page = '<?= htmlspecialchars($body_page) ?>';</script>

<?php if ($flash_error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($flash_error) ?></div>
<?php endif; ?>

<form id="outboundForm" method="POST" action="process.php">
    <input type="hidden" name="action" value="add_outbound">
    <input type="hidden" name="status" id="outboundStatus" value="completed">
    <!-- FIX: CSRF token -->
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf->getToken()) ?>">

    <div class="outbound-single-layout">
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
            <!-- Header -->
            <div class="inv-header-strip">
                <div class="inv-strip-left">
                    <div class="inv-strip-label">Phiếu xuất kho</div>
                </div>
                <div class="inv-strip-right">
                    <div class="inv-strip-date-label">Ngày tạo</div>
                    <div class="inv-strip-date-val"><?= date('d/m/Y') ?></div>
                </div>
            </div>

            <div class="inv-header-grid">
                <div class="inv-col">
                    <h4 class="inv-section-title">Người xuất</h4>
                    <div class="inv-supplier-box" style="display:block;">
                        <strong><?= htmlspecialchars($_SESSION['user_name'] ?? 'Admin') ?></strong>
                        <span>Người lập phiếu</span>
                    </div>
                </div>

                <div class="inv-col">
                    <h4 class="inv-section-title">Chi tiết phiếu</h4>
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

            <!-- Danh sách sản phẩm -->
            <div class="inv-body">
                <div class="section-header">
                    <h4 class="inv-section-title" style="margin:0;">Danh sách sản phẩm</h4>
                </div>

                <div class="table-responsive">
                    <table class="data-table" id="outboundItemsTable">
                        <thead>
                            <tr>
                                <th style="width:36px; text-align:center;">#</th>
                                <th style="min-width:220px;">Sản phẩm</th>
                                <th style="width:220px;">Số lô (tồn kho)</th>
                                <th style="width:100px; text-align:right;">SL</th>
                                <th style="width:36px;"></th>
                            </tr>
                        </thead>
                        <tbody id="outboundItemsBody"></tbody>
                    </table>
                </div>

                <div class="inv-add-row">
                    <button type="button" class="btn-add-line" onclick="addOutboundRow()">
                        <i class="ri-add-line"></i> Thêm dòng mới
                    </button>
                </div>
            </div>

            <!-- Footer -->
            <div class="inv-footer-grid">
                <div class="inv-note-section">
                    <h4 class="inv-section-title" style="margin-bottom:8px;">Ghi chú</h4>
                    <textarea name="note" class="inv-textarea" placeholder="Nhập ghi chú..."></textarea>
                </div>

                <div class="inv-summary-section">
                    <div id="pendingWarning" style="display:none; color:#f59e0b;">
                        * Lưu tạm: Chưa trừ tồn kho
                    </div>
                </div>
            </div>

            <div class="inv-actions">
                <a href="index.php" class="btn inv-btn-cancel">Hủy bỏ</a>
                <button type="submit" name="submit_action" value="draft" class="btn inv-btn-draft" id="btnDraft">
                    <i class="ri-save-line"></i> Lưu tạm
                </button>
                <button type="submit" name="submit_action" value="complete" class="btn inv-btn-save" id="btnComplete">
                    <i class="ri-check-line"></i> Lưu phiếu xuất
                </button>
            </div>
        </div>
    </div>
</form>
<template id="outboundRowTemplate">
    <tr class="item-row">
        <td class="stt-cell" style="text-align:center;"></td>
        <td style="min-width:200px; position:relative;">
            <input type="text"
                   class="product-autocomplete-outbound"
                   name="product_name[]"
                   placeholder="Nhập tên hoặc SKU"
                   autocomplete="off"
                   style="width:100%;">
            <input type="hidden" name="product_id[]" class="product-id">
            <div class="product-info"></div>
        </td>
        <td>
            <select name="batch_no[]" class="batch-select-outbound" style="width:100%;">
                <option value="">-- Chọn lô --</option>
            </select>
            <div class="batch-stock-info" style="font-size:11px; margin-top:2px;"></div>
        </td>
        <td>
            <input type="text"
                   name="quantity[]"
                   class="qty-outbound"
                   value="1"
                   required
                   style="width:80px; text-align:right;">
        </td>
        <td style="text-align:center;">
            <button type="button" class="remove-row" onclick="removeOutboundRow(this)">
                <i class="ri-delete-bin-line"></i>
            </button>
        </td>
    </tr>
</template>

<script src="<?= BASE_URL ?>/js/utils.js"></script>
<script src="<?= BASE_URL ?>/js/outbound.js"></script>

<script>
(function () {
    const btnComplete = document.getElementById('btnComplete');
    const btnDraft    = document.getElementById('btnDraft');
    const statusInput = document.getElementById('outboundStatus');
    const warning     = document.getElementById('pendingWarning');

    btnComplete?.addEventListener('click', () => { statusInput.value = 'completed'; warning.style.display = 'none'; });
    btnDraft?.addEventListener('click',    () => { statusInput.value = 'pending';   warning.style.display = 'block'; });
})();
</script>

<?php include __DIR__ . '/../../layout/footer.php'; ?>