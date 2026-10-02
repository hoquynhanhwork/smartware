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

// ── Composition Root ──────────────────────────────────────────────────────────
$service = new SupplierService(new SupplierRepository($pdo));
$csrf    = new CsrfService();

// ── Đọc filter từ GET — whitelist các giá trị có enum ────────────────────────
$fromDate  = trim($_GET['from']     ?? '');
$toDate    = trim($_GET['to']       ?? '');
$province  = trim($_GET['province'] ?? '');
$ward      = trim($_GET['ward']     ?? '');

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

// ── Lấy dữ liệu — không có SQL nào bên dưới đây ──────────────────────────────
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
             + (!empty($ward)      ? 1 : 0);

$csrfToken = $csrf->getToken();

include __DIR__ . '/../../layout/header.php';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/css/suppliers.css">

<div class="suppliers-container">

    <!-- Stats cards -->
    <div class="stats-cards-grid">
        <div class="stat-card">
            <div class="stat-icon icon-neutral"><i class="ri-building-2-line"></i></div>
            <div class="stat-body">
                <div class="stat-title">Tổng Đối tác</div>
                <div class="stat-value"><?= number_format($stats['total']) ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon icon-success"><i class="ri-checkbox-circle-line"></i></div>
            <div class="stat-body">
                <div class="stat-title">Đang hợp tác</div>
                <div class="stat-value"><?= number_format($stats['active']) ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon icon-danger"><i class="ri-forbid-line"></i></div>
            <div class="stat-body">
                <div class="stat-title">Ngừng hợp tác</div>
                <div class="stat-value"><?= number_format($stats['inactive']) ?></div>
            </div>
        </div>
    </div>

    <!-- Thanh Toolbar -->
    <div class="toolbar-modern">
        <div class="toolbar-left">
            <button class="btn-tool" onclick="toggleFilterBar()">
                <i class="ri-filter-3-line"></i> Bộ lọc
                <?php if ($filterCount > 0): ?>
                    <span class="filter-badge"><?= $filterCount ?></span>
                <?php endif; ?>
            </button>
            <div class="search-box-modern">
                <i class="ri-search-line"></i>
                <input type="text" id="searchInput" placeholder="Tìm kiếm nhanh..."
                       onkeyup="searchSupplierTable()">
            </div>
        </div>
        <div class="toolbar-right">
            <button class="btn-dark" onclick="openSupplierModal('add')">
                <i class="ri-add-line"></i> Thêm mới
            </button>
        </div>
    </div>

    <!-- Bộ lọc ngang -->
    <div class="filter-bar-horizontal" id="filterBar"
         style="display: <?= $filterCount > 0 ? 'flex' : 'none' ?>;">
        <form method="GET" action="" id="filterForm" class="filter-form-inline">

            <input type="<?= empty($fromDate) ? 'text' : 'date' ?>"
                   name="from"
                   value="<?= htmlspecialchars($fromDate) ?>"
                   class="filter-input"
                   placeholder="Từ ngày"
                   onfocus="this.type='date'"
                   onblur="if(this.value==='') this.type='text'">

            <input type="<?= empty($toDate) ? 'text' : 'date' ?>"
                   name="to"
                   value="<?= htmlspecialchars($toDate) ?>"
                   class="filter-input"
                   placeholder="Đến ngày"
                   onfocus="this.type='date'"
                   onblur="if(this.value==='') this.type='text'">

            <!-- Trạng thái -->
            <div class="custom-dropdown">
                <button type="button" class="filter-input custom-dropdown-btn"
                        onclick="toggleDropdown('statusDropdownPanel')">
                    Trạng thái <?= !empty($statuses) ? '(' . count($statuses) . ')' : '' ?>
                    <i class="ri-arrow-down-s-line"></i>
                </button>
                <div class="dropdown-panel" id="statusDropdownPanel">
                    <div class="dropdown-panel-inner">
                        <div class="fsb-group">
                            <div class="fsb-row">
                                <label class="fsb-label">
                                    <input type="checkbox" name="status[]" value="active"
                                           <?= in_array('active', $statuses, true) ? 'checked' : '' ?>>
                                    <span class="fsb-name">Đang hoạt động</span>
                                </label>
                            </div>
                            <div class="fsb-row">
                                <label class="fsb-label">
                                    <input type="checkbox" name="status[]" value="inactive"
                                           <?= in_array('inactive', $statuses, true) ? 'checked' : '' ?>>
                                    <span class="fsb-name">Ngừng hoạt động</span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Loại hình đối tác -->
            <div class="custom-dropdown">
                <button type="button" class="filter-input custom-dropdown-btn"
                        onclick="toggleDropdown('entityOriginDropdownPanel')">
                    Loại hình <?= !empty($entityOrigins) ? '(' . count($entityOrigins) . ')' : '' ?>
                    <i class="ri-arrow-down-s-line"></i>
                </button>
                <div class="dropdown-panel" id="entityOriginDropdownPanel">
                    <div class="dropdown-panel-inner">
                        <div class="fsb-group">
                            <div class="fsb-row">
                                <label class="fsb-label">
                                    <input type="checkbox" name="entity_origin[]" value="domestic"
                                           <?= in_array('domestic', $entityOrigins, true) ? 'checked' : '' ?>>
                                    <span class="fsb-name">Trong nước</span>
                                </label>
                            </div>
                            <div class="fsb-row">
                                <label class="fsb-label">
                                    <input type="checkbox" name="entity_origin[]" value="fdi"
                                           <?= in_array('fdi', $entityOrigins, true) ? 'checked' : '' ?>>
                                    <span class="fsb-name">FDI (vốn nước ngoài)</span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Giao dịch -->
            <div class="custom-dropdown">
                <button type="button" class="filter-input custom-dropdown-btn"
                        onclick="toggleDropdown('transactionDropdownPanel')">
                    Giao dịch <?= !empty($hasTransactions) ? '(' . count($hasTransactions) . ')' : '' ?>
                    <i class="ri-arrow-down-s-line"></i>
                </button>
                <div class="dropdown-panel" id="transactionDropdownPanel">
                    <div class="dropdown-panel-inner">
                        <div class="fsb-group">
                            <div class="fsb-row">
                                <label class="fsb-label">
                                    <input type="checkbox" name="has_transaction[]" value="yes"
                                           <?= in_array('yes', $hasTransactions, true) ? 'checked' : '' ?>>
                                    <span class="fsb-name">Có giao dịch</span>
                                </label>
                            </div>
                            <div class="fsb-row">
                                <label class="fsb-label">
                                    <input type="checkbox" name="has_transaction[]" value="no"
                                           <?= in_array('no', $hasTransactions, true) ? 'checked' : '' ?>>
                                    <span class="fsb-name">Chưa có giao dịch</span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn-dark btn-sm">Áp dụng</button>
            <a href="index.php" class="btn-tool btn-sm">Xóa lọc</a>
        </form>
    </div>

    <!-- Bảng nhà cung cấp -->
    <div class="table-card">
        <table class="table-modern" id="supplierTable">
            <thead>
                <tr>
                    <th width="40"><input type="checkbox" id="selectAll"></th>
                    <th width="280">Tên đối tác</th>
                    <th width="260">Điện thoại</th>
                    <th width="260">Email</th>
                    <th width="260">Mã số thuế</th>
                    <th width="200">Trạng thái</th>
                    <th class="text-right">Thao tác</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($suppliers)): ?>
                    <tr>
                        <td colspan="8" class="text-center" style="padding:40px">
                            Không tìm thấy đối tác nào
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($suppliers as $sup):
                        $badge     = $sup['status'] === 'active' ? 'badge-success' : 'badge-danger';
                        $badgeText = $sup['status'] === 'active' ? 'Đang hợp tác'  : 'Ngừng hợp tác';
                        $isFdi     = ($sup['entity_origin'] ?? 'domestic') === 'fdi';
                    ?>
                    <tr>
                        <td><input type="checkbox" class="row-checkbox" data-id="<?= $sup['id'] ?>"></td>
                        <td>
                            <button class="btn-link" onclick="viewSupplierDetail(<?= $sup['id'] ?>, <?= htmlspecialchars(json_encode($sup['name']), ENT_QUOTES) ?>)">
                                <strong><?= htmlspecialchars($sup['name']) ?></strong>
                            </button>
                        </td>
                        <td><?= htmlspecialchars($sup['phone'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($sup['email'] ?? '—') ?></td>
                        <td><code><?= htmlspecialchars($sup['tax_code'] ?? '—') ?></code></td>
                        <td><span class="badge-modern <?= $badge ?>"><?= $badgeText ?></span></td>
                        <td class="text-right actions-cell">
                            <button class="btn-icon-subtle"
                                    onclick="openSupplierModal('edit', <?= htmlspecialchars(json_encode($sup), ENT_QUOTES) ?>)">
                                <i class="ri-edit-line"></i>
                            </button>
                            <button class="btn-icon-subtle text-danger"
                                    onclick="confirmDeleteSupplier(<?= $sup['id'] ?>, '<?= $sup['status'] ?>')">
                                <i class="ri-delete-bin-line"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <div id="bulkActionBar" class="bulk-action-bar">
            <span id="bulkCount" class="bulk-count">0 đối tác đã chọn</span>
            <div class="divider"></div>
            <button onclick="exportSelectedExcel()" class="btn-export">
                <i class="ri-download-cloud-2-line"></i> Xuất Excel
            </button>
            <button onclick="clearSelection()" class="btn-clear">
                Bỏ chọn
            </button>
        </div>

    <!-- Phân trang -->
    <?php if ($totalPages > 1):
        $baseParams = $_GET; unset($baseParams['page']); ?>
    <div class="pagination-modern">
        <div class="page-numbers">
            <?php if ($page > 1): ?>
                <a href="?<?= http_build_query(array_merge($baseParams, ['page' => $page - 1])) ?>">
                    <i class="ri-arrow-left-s-line"></i>
                </a>
            <?php else: ?>
                <span class="disabled"><i class="ri-arrow-left-s-line"></i></span>
            <?php endif; ?>

            <?php $rangeSize = 1; $showDots = false;
            for ($i = 1; $i <= $totalPages; $i++):
                if ($i === 1 || $i === $totalPages || ($i >= $page - $rangeSize && $i <= $page + $rangeSize)):
                    if ($showDots) { echo '<span class="dots">...</span>'; $showDots = false; } ?>
                    <a href="?<?= http_build_query(array_merge($baseParams, ['page' => $i])) ?>"
                       class="<?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
                <?php else: $showDots = true;
                endif;
            endfor; ?>

            <?php if ($page < $totalPages): ?>
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

<div id="supplierModal" class="modal" style="display:none;">
    <div class="modal-content modal-lg" style="max-width: 800px;">
        <div class="modal-header">
            <h3 id="modalTitle">Thêm đối tác</h3>
            <span class="close" onclick="closeSupplierModal()">&times;</span>
        </div>
        <form id="supplierForm" method="POST" action="process.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="id" id="supplierId" value="0">

            <div class="form-grid">
                <div class="form-group">
                    <label>Tên đối tác <span class="required">*</span></label>
                    <input type="text" name="name" id="supName" required onblur="checkUnique('name')">
                    <span id="nameError" class="error-message"></span>
                </div>
                <div class="form-group">
                    <label>Điện thoại</label>
                    <input type="tel" name="phone" id="supPhone" onblur="checkUnique('phone')">
                    <span id="phoneError" class="error-message"></span>
                </div>
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" id="supEmail" onblur="checkUnique('email')">
                    <span id="emailError" class="error-message"></span>
                </div>
                <div class="form-group">
                    <label>Mã số thuế</label>
                    <input type="text" name="tax_code" id="supTaxCode" onblur="checkUnique('tax_code')">
                    <span id="taxCodeError" class="error-message"></span>
                </div>

                <div class="form-group">
                    <label>Trạng thái <span class="required">*</span></label>
                    <select name="status" id="supStatus">
                        <option value="active">Đang hoạt động</option>
                        <option value="inactive">Ngừng hoạt động</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Loại hình đối tác</label>
                    <select name="entity_origin" id="supEntityOrigin">
                        <option value="domestic">Trong nước</option>
                        <option value="fdi">FDI (vốn nước ngoài, hoạt động tại VN)</option>
                    </select>
                </div>

                <div class="form-group full-width">
                    <label>Địa chỉ</label>

                    <div class="address-scope-group">
                        <label class="address-scope-option">
                            <input type="radio" name="address_scope" value="VN" id="scopeVN" checked
                                onchange="toggleAddressScope('VN')">
                            <span>Việt Nam</span>
                        </label>
                        <label class="address-scope-option">
                            <input type="radio" name="address_scope" value="FOREIGN" id="scopeForeign"
                                onchange="toggleAddressScope('FOREIGN')">
                            <span>Nước ngoài</span>
                        </label>
                    </div>

                    <!-- Địa chỉ trong nước (VN) -->
                    <div id="addressVNBlock">
                        <div style="display:flex; gap:12px; margin-bottom:10px;">
                            <select id="province" style="flex:1;" onchange="populateWards(this.value); updateFullAddress();">
                                <option value="">Chọn tỉnh/thành</option>
                            </select>
                            <select id="ward" style="flex:1;" disabled onchange="updateFullAddress();">
                                <option value="">Chọn phường/xã</option>
                            </select>
                        </div>
                    </div>

                    <!-- Địa chỉ nước ngoài -->
                    <div id="addressForeignBlock" style="display:none;">
                        <div style="display:flex; gap:12px; margin-bottom:10px;">
                            <input type="text" id="foreignCountry" placeholder="Quốc gia" style="flex:1;">
                            <input type="text" id="foreignState" placeholder="Tỉnh/Bang" style="flex:1;">
                        </div>
                        <div style="display:flex; gap:12px; margin-bottom:10px;">
                            <input type="text" id="foreignCity" placeholder="Thành phố" style="flex:1;">
                            <input type="text" id="foreignPostal" placeholder="Mã bưu chính" style="flex:1;">
                        </div>
                    </div>

                    <input type="text" name="address" id="supAddress" placeholder="Địa chỉ chi tiết">
                    <div id="address_display" class="address-display-hint"></div>

                    <input type="hidden" name="province_code"  id="provinceCode">
                    <input type="hidden" name="ward_code"      id="wardCode">
                    <input type="hidden" name="country_code"   id="countryCode" value="VN">
                    <input type="hidden" name="city"            id="cityField">
                    <input type="hidden" name="state_province"  id="stateField">
                    <input type="hidden" name="postal_code"     id="postalField">
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Lưu đối tác</button>
                <button type="button" class="btn btn-secondary" onclick="closeSupplierModal()">Hủy</button>
            </div>
        </form>
    </div>
</div>

<div id="detailModal" class="modal" style="display:none;">
    <div class="modal-content modal-lg" style="max-width: 800px;">
        <div class="modal-header">
            <h3 id="detailModalTitle">Chi tiết đối tác</h3>
            <span class="close" onclick="closeDetailModal()">&times;</span>
        </div>

        <div class="detail-tabs">
            <button class="tab-btn active" data-tab="info">📋 Thông tin</button>
            <button class="tab-btn" data-tab="products">📦 Sản phẩm</button>
            <button class="tab-btn" data-tab="history">📜 Lịch sử</button>
        </div>

        <div class="tab-content" id="tab-info">
            <div id="supplierInfo" class="detail-list">
                Đang tải...
            </div>
        </div>

        <div class="tab-content" id="tab-products" style="display:none;">
            <div id="productsList" class="detail-list">
                Đang tải...
            </div>
        </div>

        <div class="tab-content" id="tab-history" style="display:none;">
            <div class="preset-time">
                <button class="btn-time active" data-range="7d">7 ngày</button>
                <button class="btn-time" data-range="30d">30 ngày</button>
                <button class="btn-time" data-range="90d">90 ngày</button>
                <button class="btn-time" data-range="1y">1 năm</button>
            </div>
            <div id="historyList" class="detail-list">
                Đang tải...
            </div>
        </div>
    </div>
</div>

<meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>">

<script src="<?= BASE_URL ?>/js/utils.js"></script>
<script src="<?= BASE_URL ?>/js/suppliers.js"></script>
<?php include __DIR__ . '/../../layout/footer.php'; ?>