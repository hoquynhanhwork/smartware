<?php
// pages/suppliers/index.php
$page_title = 'ĐỐI TÁC';

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use App\Contracts\SupplierRepositoryInterface;
use App\Repositories\SupplierRepository;
use App\Services\SupplierService;
use App\Services\CsrfService;

requireLogin();

// ── Composition Root ──────────────────────────────────────────────────────────
$service = new SupplierService(new SupplierRepository($pdo));
$csrf    = new CsrfService();

// ── Đọc filter từ GET ─────────────────────────────────────────────────────────
$fromDate  = trim($_GET['from']     ?? '');
$toDate    = trim($_GET['to']       ?? '');
$province  = trim($_GET['province'] ?? '');
$ward      = trim($_GET['ward']     ?? '');
$keyword   = trim($_GET['keyword']  ?? '');

$statuses = array_filter(
    (array) ($_GET['status'] ?? []),
    fn($s) => in_array($s, ['active', 'inactive'], true)
);

$entityOrigins = array_filter(
    (array) ($_GET['entity_origin'] ?? []),
    fn($v) => in_array($v, ['domestic', 'fdi'], true)
);

$hasTransactions = array_filter(
    (array) ($_GET['has_transaction'] ?? []),
    fn($v) => in_array($v, ['yes', 'no'], true)
);

$filters = [
    'keyword'          => $keyword,
    'from_date'        => $fromDate,
    'to_date'          => $toDate,
    'statuses'         => array_values($statuses),
    'entity_origins'   => array_values($entityOrigins),
    'has_transactions' => array_values($hasTransactions),
    'province_code'    => $province,
    'ward_code'        => $ward,
    'page'             => max(1, (int) ($_GET['page']  ?? 1)),
    'limit'            => min(100, max(1, (int) ($_GET['limit'] ?? 15))),
];

// ── Lấy dữ liệu ───────────────────────────────────────────────────────────────
$result  = $service->list($filters);
$stats   = $service->stats();

$suppliers   = $result['items'];
$totalRows   = $result['total'];
$totalPages  = $result['total_pages'];
$page        = $result['page'];
$limit       = $result['limit'];

$filterCount = count($statuses) + count($entityOrigins) + count($hasTransactions)
             + (!empty($fromDate)  ? 1 : 0)
             + (!empty($toDate)    ? 1 : 0)
             + (!empty($province)  ? 1 : 0)
             + (!empty($ward)      ? 1 : 0)
             + (!empty($keyword)   ? 1 : 0);

$csrfToken = $csrf->getToken();

include __DIR__ . '/../../layout/header.php';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/css/suppliers.css">

<!-- Script chống giật layout filter -->
<script>
    (function () {
        if (localStorage.getItem('supplier_filter_hidden') === 'true') {
            document.documentElement.classList.add('supplier-filter-hidden');
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
                    <a href="index.php" class="clear-all-link">Xóa tất cả (<?= $filterCount ?>)</a>
                <?php endif; ?>
            </div>

            <!-- 1. TRẠNG THÁI HỢP TÁC -->
            <div class="filter-section" data-filter-key="status">
                <div class="filter-sec-title">
                    <span><i class="ri-checkbox-circle-line"></i> Trạng thái</span>
                    <i class="ri-arrow-down-s-line"></i>
                </div>
                <div class="filter-checkbox-list">
                    <label class="filter-check-item">
                        <input type="checkbox" name="status[]" value="active" <?= in_array('active', $statuses, true) ? 'checked' : '' ?> onchange="this.form.submit()">
                        <span class="dot-indicator dot-success"></span>
                        <span class="label-text">Đang hợp tác</span>
                    </label>
                    <label class="filter-check-item">
                        <input type="checkbox" name="status[]" value="inactive" <?= in_array('inactive', $statuses, true) ? 'checked' : '' ?> onchange="this.form.submit()">
                        <span class="dot-indicator dot-danger"></span>
                        <span class="label-text">Ngừng hợp tác</span>
                    </label>
                </div>
            </div>

            <!-- 2. LOẠI HÌNH ĐỐI TÁC -->
            <div class="filter-section" data-filter-key="origin">
                <div class="filter-sec-title">
                    <span><i class="ri-building-line"></i> Loại hình</span>
                    <i class="ri-arrow-down-s-line"></i>
                </div>
                <div class="filter-checkbox-list">
                    <label class="filter-check-item">
                        <input type="checkbox" name="entity_origin[]" value="domestic" <?= in_array('domestic', $entityOrigins, true) ? 'checked' : '' ?> onchange="this.form.submit()">
                        <span class="label-text">Trong nước</span>
                    </label>
                    <label class="filter-check-item">
                        <input type="checkbox" name="entity_origin[]" value="fdi" <?= in_array('fdi', $entityOrigins, true) ? 'checked' : '' ?> onchange="this.form.submit()">
                        <span class="label-text">FDI (vốn nước ngoài)</span>
                    </label>
                </div>
            </div>

            <!-- 3. TÌNH TRẠNG GIAO DỊCH -->
            <div class="filter-section" data-filter-key="trans">
                <div class="filter-sec-title">
                    <span><i class="ri-exchange-line"></i> Giao dịch</span>
                    <i class="ri-arrow-down-s-line"></i>
                </div>
                <div class="filter-checkbox-list">
                    <label class="filter-check-item">
                        <input type="checkbox" name="has_transaction[]" value="yes" <?= in_array('yes', $hasTransactions, true) ? 'checked' : '' ?> onchange="this.form.submit()">
                        <span class="label-text">Có giao dịch</span>
                    </label>
                    <label class="filter-check-item">
                        <input type="checkbox" name="has_transaction[]" value="no" <?= in_array('no', $hasTransactions, true) ? 'checked' : '' ?> onchange="this.form.submit()">
                        <span class="label-text">Chưa giao dịch</span>
                    </label>
                </div>
            </div>

            <!-- 4. KHU VỰC ĐỊA LÝ -->
            <div class="filter-section" data-filter-key="area">
                <div class="filter-sec-title">
                    <span><i class="ri-map-pin-line"></i> Khu vực</span>
                    <i class="ri-arrow-down-s-line"></i>
                </div>
                <div class="filter-range-inputs" style="flex-direction: column; gap: 6px;">
                    <select name="province" id="filter_province" class="range-field" onchange="this.form.submit()">
                        <option value="">Tỉnh / Thành</option>
                    </select>
                    <select name="ward" id="filter_ward" class="range-field" onchange="this.form.submit()">
                        <option value="">Phường / Xã</option>
                    </select>
                </div>
            </div>

            <!-- 5. BỘ CHỌN NGÀY TẠO (HIERARCHY VIEW: NGÀY -> THÁNG -> NĂM) -->
            <div class="filter-section" data-filter-key="date">
                <div class="filter-sec-title">
                    <span><i class="ri-calendar-line"></i> Ngày tạo</span>
                    <i class="ri-arrow-down-s-line"></i>
                </div>

                <div class="neo-datepicker-container" id="neoDatePicker">
                    <input type="hidden" name="from" id="neoDateFrom" value="<?= htmlspecialchars($fromDate) ?>">
                    <input type="hidden" name="to" id="neoDateTo" value="<?= htmlspecialchars($toDate) ?>">

                    <button type="button" class="neo-datepicker-trigger" onclick="toggleDatePickerPopover(event)">
                        <span class="trigger-left">
                            <i class="ri-calendar-line"></i>
                            <span id="neoDatePickerText">Hôm nay</span>
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
                                <button type="button" class="btn-cal-nav" onclick="changeCalStep(-1)">
                                    <i class="ri-arrow-left-s-line"></i>
                                </button>
                                
                                <button type="button" class="cal-title-btn" id="calMainTitleBtn" onclick="onCalTitleClick()">
                                    Tháng 10 2026
                                </button>

                                <button type="button" class="btn-cal-nav" onclick="changeCalStep(1)">
                                    <i class="ri-arrow-right-s-line"></i>
                                </button>
                            </div>

                            <div class="cal-view-section" id="calDaysView">
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

                            <div class="cal-view-section" id="calMonthsView" style="display: none;">
                                <div class="cal-grid-3x4" id="calMonthsGrid"></div>
                            </div>

                            <div class="cal-view-section" id="calYearsView" style="display: none;">
                                <div class="cal-grid-3x4" id="calYearsGrid"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
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
                    <span id="txtToggleSidebar">Hide Filters</span>
                </button>
                <div class="tb-dropdown-badge">
                    <span>Tất cả đối tác (<?= number_format($totalRows) ?>)</span>
                    <i class="ri-arrow-down-s-line"></i>
                </div>
            </div>

            <div class="tb-right">
                <div class="tb-search-box">
                    <i class="ri-search-line"></i>
                    <input type="text" id="searchInput" placeholder="Tìm kiếm đối tác..." value="<?= htmlspecialchars($keyword) ?>" onkeyup="searchSupplierTable()">
                </div>

                <button type="button" class="btn-tb-primary" onclick="openSupplierModal('add')">
                    <i class="ri-add-line"></i> Thêm đối tác
                </button>
            </div>
        </div>

        <!-- BẢNG DỮ LIỆU ĐỐI TÁC -->
        <div class="task-table-card">
            <table class="task-data-table" id="supplierTable">
                <thead>
                    <tr>
                        <th width="42"><input type="checkbox" id="selectAll"></th>
                        <th width="280">Tên đối tác</th>
                        <th width="150">Điện thoại</th>
                        <th width="200">Email</th>
                        <th width="140">Mã số thuế</th>
                        <th width="150">Trạng thái</th>
                        <th width="100" class="text-right">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($suppliers)): ?>
                        <tr>
                            <td colspan="7" class="table-empty-cell">
                                <i class="ri-inbox-line"></i>
                                <p>Không tìm thấy đối tác nào</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($suppliers as $sup):
                            $badgeClass = $sup['status'] === 'active' ? 'st-completed' : 'st-overdue';
                            $badgeText  = $sup['status'] === 'active' ? 'Đang hợp tác' : 'Ngừng hợp tác';
                        ?>
                        <tr>
                            <td><input type="checkbox" class="row-checkbox" data-id="<?= $sup['id'] ?>"></td>
                            <td>
                                <div class="task-title-cell">
                                    <div class="task-text-info">
                                        <!-- LIÊN KẾT TRỰC TIẾP SANG TRANG CHI TIẾT ĐỐI TÁC DETAIL.PHP -->
                                        <a href="detail.php?id=<?= $sup['id'] ?>" class="item-name" style="text-decoration:none;">
                                            <?= htmlspecialchars($sup['name']) ?>
                                        </a>
                                        <span class="item-sku">
                                            <?= ($sup['entity_origin'] ?? 'domestic') === 'fdi' ? 'FDI (Vốn nước ngoài)' : 'Trong nước' ?>
                                        </span>
                                    </div>
                                </div>
                            </td>
                            <td><?= htmlspecialchars($sup['phone'] ?? '—') ?></td>
                            <td><?= htmlspecialchars($sup['email'] ?? '—') ?></td>
                            <td><code><?= htmlspecialchars($sup['tax_code'] ?? '—') ?></code></td>
                            <td>
                                <span class="clean-badge <?= $badgeClass ?>">
                                    <?= $badgeText ?>
                                </span>
                            </td>
                            <td class="text-right actions-cell">
                                <button type="button" class="btn-action-icon" onclick="openSupplierModal('edit', <?= htmlspecialchars(json_encode($sup), ENT_QUOTES) ?>)" title="Sửa">
                                    <i class="ri-pencil-line"></i>
                                </button>
                                <button type="button" class="btn-action-icon btn-action-delete" onclick="confirmDeleteSupplier(<?= $sup['id'] ?>, '<?= $sup['status'] ?>')" title="Xóa">
                                    <i class="ri-delete-bin-line"></i>
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
            <span id="bulkCount" class="bulk-count">0 đối tác đã chọn</span>
            <button type="button" id="btnSelectAllTotal" class="btn-select-all-total" style="display: none;" onclick="toggleSelectAllTotal()">
                Chọn tất cả <?= (int) $totalRows ?>
            </button>
            <div class="divider"></div>
            <button onclick="exportSelectedExcel()" class="btn-export">
                <i class="ri-download-cloud-2-line"></i> Xuất Excel
            </button>
            <button onclick="clearSelection()" class="btn-clear">
                Bỏ chọn
            </button>
        </div>

        <!-- PHÂN TRANG -->
        <?php if ($totalPages > 1):
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
                for ($i = 1; $i <= $totalPages; $i++):
                    if ($i === 1 || $i === $totalPages || ($i >= $page - $range && $i <= $page + $range)):
                        if ($showDots) { echo '<span class="dots">...</span>'; $showDots = false; } ?>
                        <a href="?<?= http_build_query(array_merge($baseParams, ['page' => $i])) ?>" class="btn-page-num <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
                    <?php else: $showDots = true;
                    endif;
                endfor; ?>

                <?php if ($page < $totalPages): ?>
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

<!-- ── MODAL THÊM / SỬA ĐỐI TÁC ────────────────────────────────── -->
<div id="supplierModal" class="modal-modern" style="display:none;">
    <div class="modal-modern-dialog" style="max-width: 740px;">
        <div class="modal-modern-header">
            <div>
                <h3 id="modalTitle">Thêm đối tác mới</h3>
                <p class="modal-subtitle">Quản lý hồ sơ đối tác và thông tin xuất hóa đơn.</p>
            </div>
            <button type="button" class="btn-close-modern" onclick="closeSupplierModal()">&times;</button>
        </div>

        <form id="supplierForm" method="POST" action="process.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="id" id="supplierId" value="0">
            <input type="hidden" name="status" id="supStatus" value="active">

            <div class="modal-tabs-body">
                <div class="form-grid-2">
                    <div class="form-row-modern">
                        <label class="form-label-modern">Tên đối tác <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="supName" required placeholder="Tên công ty hoặc đối tác..." onblur="checkUnique('name')" class="form-input-modern">
                        <span id="nameError" class="error-message"></span>
                    </div>

                    <div class="form-row-modern">
                        <label class="form-label-modern">Mã số thuế</label>
                        <input type="text" name="tax_code" id="supTaxCode" placeholder="Mã số thuế..." onblur="checkUnique('tax_code')" class="form-input-modern">
                        <span id="taxCodeError" class="error-message"></span>
                    </div>
                </div>

                <div class="form-grid-2">
                    <div class="form-row-modern">
                        <label class="form-label-modern">Số điện thoại</label>
                        <input type="tel" name="phone" id="supPhone" placeholder="09xxxx..." onblur="checkUnique('phone')" class="form-input-modern">
                        <span id="phoneError" class="error-message"></span>
                    </div>

                    <div class="form-row-modern">
                        <label class="form-label-modern">Email</label>
                        <input type="email" name="email" id="supEmail" placeholder="contact@..." onblur="checkUnique('email')" class="form-input-modern">
                        <span id="emailError" class="error-message"></span>
                    </div>
                </div>

                <div class="form-row-modern">
                    <label class="form-label-modern">Loại hình đối tác</label>
                    <select name="entity_origin" id="supEntityOrigin" class="form-input-modern">
                        <option value="domestic">Doanh nghiệp trong nước</option>
                        <option value="fdi">FDI (vốn đầu tư nước ngoài)</option>
                    </select>
                </div>

                <!-- ĐỊA CHỈ & RADIO CHỌN KHU VỰC -->
                <div class="form-row-modern" style="margin-top: 6px;">
                    <label class="form-label-modern">Địa chỉ hoạt động</label>
                    
                    <div style="display: flex; gap: 20px; margin-bottom: 8px;">
                        <label class="radio-circle-item">
                            <input type="radio" name="address_scope" value="VN" id="scopeVN" checked onchange="toggleAddressScope('VN')">
                            <span class="custom-radio-circle"></span>
                            <span class="radio-text-label">Việt Nam</span>
                        </label>
                        <label class="radio-circle-item">
                            <input type="radio" name="address_scope" value="FOREIGN" id="scopeForeign" onchange="toggleAddressScope('FOREIGN')">
                            <span class="custom-radio-circle"></span>
                            <span class="radio-text-label">Nước ngoài</span>
                        </label>
                    </div>

                    <!-- Khối địa chỉ Việt Nam -->
                    <div id="addressVNBlock">
                        <div class="form-grid-2" style="margin-bottom: 8px;">
                            <select id="province" class="form-input-modern" onchange="populateWards(this.value); updateFullAddress();">
                                <option value="">Chọn tỉnh / thành</option>
                            </select>
                            <select id="ward" class="form-input-modern" disabled onchange="updateFullAddress();">
                                <option value="">Chọn phường / xã</option>
                            </select>
                        </div>
                    </div>

                    <!-- Khối địa chỉ nước ngoài -->
                    <div id="addressForeignBlock" style="display:none;">
                        <div class="form-grid-2" style="margin-bottom: 8px;">
                            <input type="text" id="foreignCountry" placeholder="Quốc gia" class="form-input-modern">
                            <input type="text" id="foreignState" placeholder="Tỉnh / Bang" class="form-input-modern">
                        </div>
                        <div class="form-grid-2" style="margin-bottom: 8px;">
                            <input type="text" id="foreignCity" placeholder="Thành phố" class="form-input-modern">
                            <input type="text" id="foreignPostal" placeholder="Mã bưu chính (Postal code)" class="form-input-modern">
                        </div>
                    </div>

                    <input type="text" name="address" id="supAddress" placeholder="Số nhà, tên đường chi tiết..." class="form-input-modern">
                    <div id="address_display" style="font-size: 12px; color: #71717a; margin-top: 4px;"></div>

                    <input type="hidden" name="province_code"  id="provinceCode">
                    <input type="hidden" name="ward_code"      id="wardCode">
                    <input type="hidden" name="country_code"   id="countryCode" value="VN">
                    <input type="hidden" name="city"            id="cityField">
                    <input type="hidden" name="state_province"  id="stateField">
                    <input type="hidden" name="postal_code"     id="postalField">
                </div>
            </div>

            <!-- Footer Modal -->
            <div class="modal-modern-footer">
                <label class="toggle-status-wrapper">
                    <input type="checkbox" id="supStatusToggle" checked onchange="document.getElementById('supStatus').value = this.checked ? 'active' : 'inactive'">
                    <span class="toggle-slider"></span>
                    <span class="toggle-label-text">Đang duy trì hợp tác</span>
                </label>

                <div class="footer-btns-group">
                    <button type="button" class="btn-modern-outline" onclick="closeSupplierModal()">Hủy</button>
                    <button type="submit" class="btn-modern-dark">Lưu đối tác</button>
                </div>
            </div>
        </form>
    </div>
</div>

<meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>">
<script>
    window._supplierTotalRows = <?= (int) $totalRows ?>;
</script>
<script src="<?= BASE_URL ?>/js/utils.js"></script>
<script src="<?= BASE_URL ?>/js/suppliers.js"></script>

<?php include __DIR__ . '/../../layout/footer.php'; ?>