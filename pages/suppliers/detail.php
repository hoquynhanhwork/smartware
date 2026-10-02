<?php
// pages/suppliers/detail.php
$page_title = 'CHI TIẾT ĐỐI TÁC';

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use App\Repositories\SupplierRepository;
use App\Services\SupplierService;
use App\Services\CsrfService;

requireLogin();

$supplierId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($supplierId <= 0) {
    header('Location: index.php');
    exit;
}

$service   = new SupplierService(new SupplierRepository($pdo));
$csrf      = new CsrfService();
$csrfToken = $csrf->getToken();

// 1. Lấy thông tin đối tác
$supplier = $service->getDetail($supplierId);
if (!$supplier) {
    header('Location: index.php');
    exit;
}

// 2. Lấy danh sách sản phẩm & lịch sử nhập kho
$productsList   = $service->getProducts($supplierId);
$inboundHistory = $service->getInboundHistory($supplierId, 'all');

$totalInbound  = (int)($supplier['inbound_count'] ?? count($inboundHistory));
$totalAmount   = (float)($supplier['total_import'] ?? 0);
$totalProducts = count($productsList);

$initials = strtoupper(mb_substr($supplier['name'], 0, 2, 'UTF-8'));
$entityOriginText = ($supplier['entity_origin'] ?? 'domestic') === 'fdi' ? 'FDI (Vốn nước ngoài)' : 'Trong nước';

// Định dạng địa chỉ đầy đủ
$fullAddress = $supplier['address'] ?? '—';
if (!empty($supplier['country_code']) && $supplier['country_code'] !== 'VN') {
    $parts = array_filter([$supplier['address'], $supplier['city'], $supplier['state_province'], $supplier['country_code']]);
    $fullAddress = !empty($parts) ? implode(', ', $parts) : '—';
}

$currentYear  = (int)date('Y');
$currentMonth = (int)date('n');

// 3. Thống kê theo Tháng / Quý / Năm / Tuần ban đầu
$monthStats = array_fill(1, 12, 0);
$stmtM = $pdo->prepare("
    SELECT EXTRACT(MONTH FROM created) AS m, COALESCE(SUM(total_amount), 0) AS total 
    FROM stock_inbounds 
    WHERE supplier_id = ? AND deleted_at IS NULL AND EXTRACT(YEAR FROM created) = ?
    GROUP BY EXTRACT(MONTH FROM created)
");
$stmtM->execute([$supplierId, $currentYear]);
while ($row = $stmtM->fetch(PDO::FETCH_ASSOC)) {
    $monthStats[(int)$row['m']] = (float)$row['total'];
}

$quarterStats = [1 => 0, 2 => 0, 3 => 0, 4 => 0];
$stmtQ = $pdo->prepare("
    SELECT EXTRACT(QUARTER FROM created) AS q, COALESCE(SUM(total_amount), 0) AS total 
    FROM stock_inbounds 
    WHERE supplier_id = ? AND deleted_at IS NULL AND EXTRACT(YEAR FROM created) = ?
    GROUP BY EXTRACT(QUARTER FROM created)
");
$stmtQ->execute([$supplierId, $currentYear]);
while ($row = $stmtQ->fetch(PDO::FETCH_ASSOC)) {
    $quarterStats[(int)$row['q']] = (float)$row['total'];
}

$yearStats = [];
for ($y = $currentYear - 4; $y <= $currentYear; $y++) {
    $yearStats[$y] = 0;
}
$stmtY = $pdo->prepare("
    SELECT EXTRACT(YEAR FROM created) AS y, COALESCE(SUM(total_amount), 0) AS total 
    FROM stock_inbounds 
    WHERE supplier_id = ? AND deleted_at IS NULL AND EXTRACT(YEAR FROM created) >= ?
    GROUP BY EXTRACT(YEAR FROM created)
");
$stmtY->execute([$supplierId, $currentYear - 4]);
while ($row = $stmtY->fetch(PDO::FETCH_ASSOC)) {
    $yKey = (int)$row['y'];
    if (isset($yearStats[$yKey])) $yearStats[$yKey] = (float)$row['total'];
}

$weekStats = [];
for ($d = 6; $d >= 0; $d--) {
    $dayKey = date('Y-m-d', strtotime("-$d days"));
    $weekStats[$dayKey] = 0;
}
$stmtW = $pdo->prepare("
    SELECT created::date AS d, COALESCE(SUM(total_amount), 0) AS total 
    FROM stock_inbounds 
    WHERE supplier_id = ? AND deleted_at IS NULL AND created >= CURRENT_DATE - INTERVAL '6 days'
    GROUP BY created::date
");
$stmtW->execute([$supplierId]);
while ($row = $stmtW->fetch(PDO::FETCH_ASSOC)) {
    if (isset($weekStats[$row['d']])) $weekStats[$row['d']] = (float)$row['total'];
}

include __DIR__ . '/../../layout/header.php';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/css/suppliers.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/supplier-detail.css">

<!-- Nạp sẵn JSON Supplier an toàn vào DOM -->
<script id="currentSupplierJson" type="application/json">
    <?= json_encode($supplier, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>
</script>

<div class="sup-detail-page-container">
    <!-- ── BREADCRUMB & NÚT SỬA ────────────────────────────────────── -->
    <div class="sup-header-breadcrumb-bar">
        <div class="breadcrumb-links">
            <a href="index.php" class="bc-prev">Danh sách đối tác</a>
            <i class="ri-arrow-right-s-line bc-sep"></i>
            <span class="bc-cur"><?= htmlspecialchars($supplier['name']) ?></span>
        </div>
        <div class="header-action-group">
            <button type="button" class="btn-icon-square" onclick="window.print()" title="In hồ sơ">
                <i class="ri-printer-line"></i>
            </button>
            <!-- Gọi modal sửa an toàn thông qua dữ liệu JSON đã nhúng -->
            <button type="button" class="btn-pill-edit" onclick="openSupplierModal('edit', JSON.parse(document.getElementById('currentSupplierJson').textContent))">
                <i class="ri-edit-line"></i> Chỉnh sửa đối tác
            </button>
        </div>
    </div>

    <!-- ── KHỐI TRÊN: PROFILE & DANH SÁCH SẢN PHẨM ─────────────────── -->
    <div class="dashboard-row top-row">
        <!-- Khối 1: Hồ sơ đối tác -->
        <div class="dash-card profile-card">
            <div class="profile-left-col">
                <div class="profile-avatar-circle"><?= $initials ?></div>
                <h2 class="profile-company-name"><?= htmlspecialchars($supplier['name']) ?></h2>
                <span class="profile-email-text"><?= htmlspecialchars($supplier['email'] ?: 'Chưa có email') ?></span>

                <div class="profile-counter-stats">
                    <div class="stat-unit">
                        <span class="num"><?= number_format($totalInbound) ?></span>
                        <span class="lbl">Đơn nhập</span>
                    </div>
                    <div class="sep-line"></div>
                    <div class="stat-unit">
                        <span class="num"><?= number_format($totalProducts) ?></span>
                        <span class="lbl">Sản phẩm</span>
                    </div>
                </div>

                <a href="mailto:<?= htmlspecialchars($supplier['email']) ?>" class="btn-send-message">
                    <i class="ri-mail-send-line"></i> Gửi thư liên hệ
                </a>
            </div>

            <div class="profile-right-grid">
                <div class="data-field">
                    <span class="data-lbl">Loại hình</span>
                    <span class="data-val"><?= $entityOriginText ?></span>
                </div>
                <div class="data-field">
                    <span class="data-lbl">Mã số thuế</span>
                    <span class="data-val"><code><?= htmlspecialchars($supplier['tax_code'] ?: '—') ?></code></span>
                </div>
                <div class="data-field">
                    <span class="data-lbl">Số điện thoại</span>
                    <span class="data-val"><?= htmlspecialchars($supplier['phone'] ?: '—') ?></span>
                </div>
                <div class="data-field">
                    <span class="data-lbl">Trạng thái hợp tác</span>
                    <span class="data-val">
                        <?php if (($supplier['status'] ?? 'active') === 'active'): ?>
                            <span class="status-pill active">Đang hợp tác</span>
                        <?php else: ?>
                            <span class="status-pill inactive">Ngừng hợp tác</span>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="data-field full-row">
                    <span class="data-lbl">Địa chỉ hoạt động</span>
                    <span class="data-val"><?= htmlspecialchars($fullAddress) ?></span>
                </div>
                <div class="data-field">
                    <span class="data-lbl">Ngày tạo</span>
                    <span class="data-val"><?= !empty($supplier['created']) ? date('d M Y', strtotime($supplier['created'])) : '—' ?></span>
                </div>
                <div class="data-field">
                    <span class="data-lbl">Tổng giá trị nhập</span>
                    <span class="data-val highlight-price"><?= number_format($totalAmount) ?> ₫</span>
                </div>
            </div>
        </div>

        <!-- Khối 2: Danh sách sản phẩm -->
        <div class="dash-card products-compact-card">
            <div class="card-title-bar">
                <h3>Danh sách sản phẩm cung ứng</h3>
                <span class="badge-count"><?= count($productsList) ?> sản phẩm</span>
            </div>
            <div class="products-compact-table-wrap">
                <table class="detail-data-table compact-table">
                    <thead>
                        <tr>
                            <th>Tên sản phẩm</th>
                            <th>Mã SKU</th>
                            <th>ĐVT</th>
                            <th class="text-right">Giá vốn</th>
                            <th class="text-right">Tồn kho</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($productsList)): ?>
                            <tr><td colspan="5" class="empty-state">Chưa có sản phẩm nào được liên kết.</td></tr>
                        <?php else: ?>
                            <?php foreach ($productsList as $p): ?>
                            <tr>
                                <td><strong class="prod-name-clamp" title="<?= htmlspecialchars($p['name']) ?>"><?= htmlspecialchars($p['name']) ?></strong></td>
                                <td><code><?= htmlspecialchars($p['sku'] ?: '—') ?></code></td>
                                <td><?= htmlspecialchars($p['unit'] ?: 'Cái') ?></td>
                                <td class="text-right highlight-price"><?= number_format((float)($p['cost_price'] ?? 0)) ?> ₫</td>
                                <td class="text-right"><span class="stock-pill"><?= number_format((int)($p['total_stock'] ?? 0)) ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ── KHỐI DƯỚI: TIMELINE NHẬP KHO & BIỂU ĐỒ SẢN LƯỢNG ──────────── -->
    <div class="dashboard-row bottom-row">
        <!-- Khối 3: Timeline lịch sử nhập kho -->
        <div class="dash-card timeline-card">
            <div class="card-title-bar">
                <h3>Lịch sử nhập kho gần đây</h3>
                <span class="badge-count"><?= count($inboundHistory) ?> đơn nhập</span>
            </div>
            <div class="timeline-container-scroll">
                <?php if (empty($inboundHistory)): ?>
                    <div class="empty-state">Chưa có lịch sử nhập hàng nào từ đối tác này.</div>
                <?php else: ?>
                    <div class="timeline-vertical-line"></div>
                    <?php foreach ($inboundHistory as $idx => $order): 
                        $createdDate = !empty($order['created']) ? strtotime($order['created']) : time();
                        $dateStr = date('d M \'y', $createdDate);
                        $timeStr = date('H:i', $createdDate);
                        $isFirst = $idx === 0;
                    ?>
                    <div class="timeline-item-row">
                        <div class="timeline-node <?= $isFirst ? 'node-green' : 'node-blue' ?>"></div>
                        <div class="timeline-box">
                            <div class="box-date-col">
                                <span class="d-text"><?= $dateStr ?></span>
                                <span class="t-text"><?= $timeStr ?></span>
                            </div>
                            <div class="box-detail-col">
                                <span class="lbl">Mã phiếu</span>
                                <span class="val"><strong><?= htmlspecialchars($order['ref_no'] ?? 'PN-'.str_pad($order['id'], 5, '0', STR_PAD_LEFT)) ?></strong></span>
                            </div>
                            <div class="box-detail-col">
                                <span class="lbl">Tổng tiền</span>
                                <span class="val highlight-price"><?= number_format((float)($order['total_amount'] ?? 0)) ?> ₫</span>
                            </div>
                            <div class="box-action-col">
                                <span class="badge-status-completed"><?= htmlspecialchars($order['status'] ?? 'completed') ?></span>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Khối 4: Biểu đồ nhập hàng (Kèm Popover chọn Tháng / Năm chuẩn lưới 3x4) -->
        <div class="dash-card chart-card">
            <div class="chart-header-row">
                <div class="chart-title-group">
                    <h3 class="chart-title">Lượng nhập hàng</h3>
                    
                    <!-- Nút bấm mở popover chọn Tháng / Năm -->
                    <div class="chart-picker-wrapper" id="chartPickerWrapper">
                        <button type="button" class="btn-chart-date-trigger" onclick="toggleChartDatePopover(event)">
                            <i class="ri-calendar-line"></i>
                            <span id="chartSubTitle">Năm <?= $currentYear ?></span>
                            <i class="ri-arrow-down-s-line trigger-arrow"></i>
                        </button>

                        <!-- Popover lưới 3x4 (Tháng & Dải Năm) -->
                        <div class="chart-date-popover" id="chartDatePopover" onclick="event.stopPropagation()">
                            <div class="cdp-header-bar">
                                <button type="button" class="cdp-btn-nav" onclick="changeCdpStep(-1)">
                                    <i class="ri-arrow-left-s-line"></i>
                                </button>
                                <button type="button" class="cdp-btn-title" id="cdpTitleBtn" onclick="toggleCdpView()">
                                    <?= $currentYear ?>
                                </button>
                                <button type="button" class="cdp-btn-nav" onclick="changeCdpStep(1)">
                                    <i class="ri-arrow-right-s-line"></i>
                                </button>
                            </div>

                            <!-- View 1: Lưới 12 Tháng -->
                            <div class="cdp-grid-3x4" id="cdpMonthsGrid"></div>

                            <!-- View 2: Lưới 12 Năm -->
                            <div class="cdp-grid-3x4" id="cdpYearsGrid" style="display: none;"></div>
                        </div>
                    </div>
                </div>

                <!-- Tabs lọc chu kỳ -->
                <div class="chart-filter-pills">
                    <button type="button" class="btn-chart-tab" onclick="switchChartPeriod('week', this)">Tuần</button>
                    <button type="button" class="btn-chart-tab active" onclick="switchChartPeriod('month', this)">Tháng</button>
                    <button type="button" class="btn-chart-tab" onclick="switchChartPeriod('quarter', this)">Quý</button>
                    <button type="button" class="btn-chart-tab" onclick="switchChartPeriod('year', this)">Năm</button>
                </div>
            </div>

            <!-- Giao diện Biểu đồ dạng Rounded Pills -->
            <div class="chart-render-wrapper">
                <div class="chart-y-axis" id="chartYAxis"></div>
                <div class="chart-bars-container" id="chartBarsContainer"></div>
            </div>
        </div>
    </div>
</div>

<!-- ── MODAL CHỈNH SỬA ĐỐI TÁC ────────────────────────────────────── -->
<div id="supplierModal" class="modal-modern" style="display:none;">
    <div class="modal-modern-dialog" style="max-width: 740px;">
        <div class="modal-modern-header">
            <div>
                <h3 id="modalTitle">Chỉnh sửa đối tác</h3>
                <p class="modal-subtitle">Cập nhật hồ sơ đối tác và thông tin hóa đơn.</p>
            </div>
            <button type="button" class="btn-close-modern" onclick="closeSupplierModal()">&times;</button>
        </div>

        <form id="supplierForm" method="POST" action="process.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="action" id="formAction" value="edit">
            <input type="hidden" name="id" id="supplierId" value="<?= (int)$supplier['id'] ?>">
            <input type="hidden" name="status" id="supStatus" value="<?= htmlspecialchars($supplier['status'] ?? 'active') ?>">

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

<!-- Biến toàn cục truyền ID & Dữ liệu biểu đồ sang file JS -->
<script>
    window._currentSupplierDetailId = <?= (int)$supplierId ?>;
    window._chartData = {
        month: {
            labels: ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"],
            values: <?= json_encode(array_values($monthStats)) ?>
        },
        quarter: {
            labels: ["Quý 1", "Quý 2", "Quý 3", "Quý 4"],
            values: <?= json_encode(array_values($quarterStats)) ?>
        },
        year: {
            labels: <?= json_encode(array_map('strval', array_keys($yearStats))) ?>,
            values: <?= json_encode(array_values($yearStats)) ?>
        },
        week: {
            labels: <?= json_encode(array_map(fn($d) => date('d/m', strtotime($d)), array_keys($weekStats))) ?>,
            values: <?= json_encode(array_values($weekStats)) ?>
        }
    };
</script>

<!-- Nạp script dùng chung và script riêng cho trang detail -->
<script src="<?= BASE_URL ?>/js/utils.js"></script>
<script src="<?= BASE_URL ?>/js/suppliers.js"></script>
<script src="<?= BASE_URL ?>/js/supplier-detail.js"></script>

<?php include __DIR__ . '/../../layout/footer.php'; ?>