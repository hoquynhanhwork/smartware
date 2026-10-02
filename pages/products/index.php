<?php
// pages/products/index.php
$page_title = 'SẢN PHẨM';

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use App\Repositories\ProductRepository;
use App\Repositories\CategoryRepository;
use App\Repositories\SupplierRepository;
use App\Services\ProductService;
use App\Services\CsrfService;

requireLogin();

// ── Composition Root ──────────────────────────────────────────────────────────
$service = new ProductService(
    repo:         new ProductRepository($pdo),
    categoryRepo: new CategoryRepository($pdo),
    supplierRepo: new SupplierRepository($pdo),
);
$csrf = new CsrfService();

// ── Đọc filter từ GET ─────────────────────────────────────────────────────────
$categoryIds = array_filter(array_map('intval', (array) ($_GET['category_id'] ?? [])));
$statuses    = array_filter((array) ($_GET['status']      ?? []));
$supplierIds = array_filter(array_map('intval', (array) ($_GET['supplier_id'] ?? [])));
$stockMin    = $_GET['stock_min'] ?? '';
$stockMax    = $_GET['stock_max'] ?? '';
$keyword     = trim($_GET['keyword'] ?? '');

$filters = [
    'keyword'      => $keyword,
    'stock_min'    => $stockMin,
    'stock_max'    => $stockMax,
    'page'         => max(1, (int) ($_GET['page']  ?? 1)),
    'limit'        => min(100, max(1, (int) ($_GET['limit'] ?? 15))),
    'category_ids' => $categoryIds,
    'statuses'     => $statuses,
    'supplier_ids' => $supplierIds,
];

// ── Lấy dữ liệu qua Service — không có SQL nào bên dưới đây ──────────────────
$result   = $service->list($filters);
$stats    = $service->stats();
$formData = $service->getFormData();

$products    = $result['items'];
$totalRows   = $result['total'];
$totalPages  = $result['total_pages'];
$page        = $result['page'];
$limit       = $result['limit'];

// Unpack formData cho template
$suppliers  = $formData['suppliers'];
$categories = $formData['categories']; // danh sách phẳng (không phân cấp)

$filterCount = count($categoryIds) + count($statuses) + count($supplierIds)
             + ($stockMin !== '' || $stockMax !== '' ? 1 : 0)
             + ($keyword !== '' ? 1 : 0);

// CSRF token cho form POST và AJAX
$csrfToken = $csrf->getToken();

include __DIR__ . '/../../layout/header.php';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/css/products.css">

<div class="products-container">

    <!-- Stats cards -->
    <div class="stats-cards-grid">
        <div class="stat-card">
            <div class="stat-icon icon-neutral"><i class="ri-archive-line"></i></div>
            <div class="stat-body">
                <div class="stat-title">Tổng sản phẩm</div>
                <div class="stat-value"><?= number_format($stats['total']) ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon icon-success"><i class="ri-checkbox-circle-line"></i></div>
            <div class="stat-body">
                <div class="stat-title">Đang hoạt động</div>
                <div class="stat-value"><?= number_format($stats['active']) ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon icon-danger"><i class="ri-forbid-line"></i></div>
            <div class="stat-body">
                <div class="stat-title">Ngừng kinh doanh</div>
                <div class="stat-value"><?= number_format($stats['inactive']) ?></div>
            </div>
        </div>
    </div>

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
                       value="<?= htmlspecialchars($keyword) ?>"
                       onkeyup="searchProductTable()">
            </div>
        </div>
        <div class="toolbar-right">
            <button class="btn-tool" onclick="openCategoryModal()">
                <i class="ri-add-line"></i> Thêm danh mục
            </button>
            <button class="btn-dark" onclick="openProductModal('add')">
                <i class="ri-add-line"></i> Thêm sản phẩm
            </button>
        </div>
    </div>

    <!-- Bộ lọc -->
    <div class="filter-bar-horizontal" id="filterBar"
         style="display: <?= $filterCount > 0 ? 'flex' : 'none' ?>;">
        <form method="GET" action="" id="filterForm" class="filter-form-inline">
            <input type="hidden" name="keyword" value="<?= htmlspecialchars($keyword) ?>">

            <!-- Danh mục -->
            <div class="custom-dropdown">
                <button type="button" class="filter-input custom-dropdown-btn"
                        onclick="toggleDropdown('categoryDropdownPanel')">
                    Danh mục <?= !empty($categoryIds) ? '(' . count($categoryIds) . ')' : '' ?>
                    <i class="ri-arrow-down-s-line"></i>
                </button>
                <div class="dropdown-panel" id="categoryDropdownPanel">
                    <div class="dropdown-panel-inner">
                        <div class="fsb-group">
                            <?php foreach ($categories as $cat): ?>
                            <div class="fsb-row">
                                <label class="fsb-label">
                                    <input type="checkbox" name="category_id[]"
                                           value="<?= $cat['id'] ?>"
                                           <?= in_array($cat['id'], $categoryIds, true) ? 'checked' : '' ?>>
                                    <span class="fsb-name"><?= htmlspecialchars($cat['name']) ?></span>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

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
                                           <?= in_array('active', $statuses) ? 'checked' : '' ?>>
                                    <span class="fsb-name">Đang hoạt động</span>
                                </label>
                            </div>
                            <div class="fsb-row">
                                <label class="fsb-label">
                                    <input type="checkbox" name="status[]" value="inactive"
                                           <?= in_array('inactive', $statuses) ? 'checked' : '' ?>>
                                    <span class="fsb-name">Ngừng hoạt động</span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Nhà cung cấp -->
            <div class="custom-dropdown">
                <button type="button" class="filter-input custom-dropdown-btn"
                        onclick="toggleDropdown('supplierDropdownPanel')">
                    Nhà cung cấp <?= !empty($supplierIds) ? '(' . count($supplierIds) . ')' : '' ?>
                    <i class="ri-arrow-down-s-line"></i>
                </button>
                <div class="dropdown-panel" id="supplierDropdownPanel">
                    <div class="dropdown-panel-inner">
                        <div class="fsb-group">
                            <?php foreach ($suppliers as $sup): ?>
                            <div class="fsb-row">
                                <label class="fsb-label">
                                    <input type="checkbox" name="supplier_id[]"
                                           value="<?= $sup['id'] ?>"
                                           <?= in_array($sup['id'], $supplierIds) ? 'checked' : '' ?>>
                                    <span class="fsb-name"><?= htmlspecialchars($sup['name']) ?></span>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tồn kho -->
            <input type="number" name="stock_min"
                   value="<?= htmlspecialchars($stockMin) ?>"
                   placeholder="Tồn từ..." class="filter-input">
            <input type="number" name="stock_max"
                   value="<?= htmlspecialchars($stockMax) ?>"
                   placeholder="Đến..." class="filter-input">

            <button type="submit" class="btn-dark btn-sm">Áp dụng</button>
            <a href="index.php" class="btn-tool btn-sm">Xóa lọc</a>
        </form>
    </div>

    <!-- Bảng sản phẩm -->
    <div class="table-card">
        <table class="table-modern" id="productTable">
            <thead>
                <tr>
                    <th width="40"><input type="checkbox" id="selectAll"></th>
                    <th width="240">Sản phẩm</th>
                    <th width="240">Danh mục</th>
                    <th width="240">Giá</th>
                    <th width="240">Tồn kho</th>
                    <th width="300">Trạng thái</th>
                    <th class="text-right">Thao tác</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($products)): ?>
                    <tr>
                        <td colspan="8" class="text-center" style="padding:40px">
                            Không tìm thấy sản phẩm nào
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($products as $p):
                        $stock = (int) $p['total_stock'];
                        if ($p['status'] === 'inactive')              { $badge = 'badge-danger';  $badgeText = 'Ngừng KD'; }
                        elseif ($stock <= (int) $p['min_stock'])       { $badge = 'badge-warning'; $badgeText = 'Cần nhập'; }
                        else                                           { $badge = 'badge-success'; $badgeText = 'Còn hàng'; }
                    ?>
                    <tr>
                        <td><input type="checkbox" class="row-checkbox" data-id="<?= $p['id'] ?>"></td>
                        <td>
                            <div class="product-cell">
                                <div class="prod-name"><?= htmlspecialchars($p['name']) ?></div>
                                <div class="prod-sku"><?= htmlspecialchars($p['sku'] ?? 'N/A') ?></div>
                            </div>
                        </td>
                        <td class="text-muted"><?= htmlspecialchars($p['category_name'] ?? '—') ?></td>
                        <td class="font-medium"><?= number_format((float) $p['price']) ?>₫</td>
                        <td><?= number_format($stock) ?></td>
                        <td><span class="badge-modern <?= $badge ?>"><?= $badgeText ?></span></td>
                        <td class="text-right actions-cell">
                            <button class="btn-icon-subtle"
                                    onclick="openProductModal('edit', <?= htmlspecialchars(json_encode($p), ENT_QUOTES) ?>)">
                                <i class="ri-edit-line"></i>
                            </button>
                            <button class="btn-icon-subtle text-danger"
                                    onclick="confirmDeleteProduct(<?= $p['id'] ?>, '<?= $p['status'] ?>')">
                                <i class="ri-delete-bin-line"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Bulk action bar -->
    <div id="bulkActionBar" class="bulk-action-bar">
        <span id="bulkCount" class="bulk-count">0 sản phẩm đã chọn</span>
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
        $baseParams = $_GET;
        unset($baseParams['page']); ?>
    <div class="pagination-modern">
        <div class="page-numbers">
            <?php if ($page > 1): ?>
                <a href="?<?= http_build_query(array_merge($baseParams, ['page' => $page - 1])) ?>">
                    <i class="ri-arrow-left-s-line"></i>
                </a>
            <?php else: ?>
                <span class="disabled"><i class="ri-arrow-left-s-line"></i></span>
            <?php endif; ?>

            <?php $range = 1; $showDots = false;
            for ($i = 1; $i <= $totalPages; $i++):
                if ($i === 1 || $i === $totalPages || ($i >= $page - $range && $i <= $page + $range)):
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

   <div id="productModal" class="modal" style="display:none;">
        <div class="modal-content modal-lg" style="max-width: 800px;"> <div class="modal-header">
                <h3 id="modalTitle">Thêm sản phẩm</h3>
                <span class="close" onclick="closeProductModal()">&times;</span>
            </div>
            <form id="productForm" method="POST" action="process.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="id" id="productId" value="0">
                
                <div class="form-grid">
                    <div class="form-group">
                        <label>Tên sản phẩm <span class="required">*</span></label>
                        <input type="text" name="name" id="prodName" required placeholder="Nhập tên sản phẩm">
                    </div>
                    <div class="form-group">
                        <label>SKU</label>
                        <input type="text" name="sku" id="prodSku" placeholder="Mã SKU (tùy chọn)" onblur="checkSku()">
                        <span id="skuError" class="error-message"></span>
                    </div>
                    <div class="form-group">
                        <label>Danh mục</label>
                        <select name="category_id" id="prodCategory">
                            <option value="">-- Chọn danh mục --</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['id'] ?>">
                                    <?= htmlspecialchars($cat['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Nhà cung cấp</label>
                        <select name="supplier_id" id="prodSupplier">
                            <option value="">-- Chọn nhà cung cấp --</option>
                            <?php foreach ($suppliers as $sup): ?>
                                <option value="<?= $sup['id'] ?>">
                                    <?= htmlspecialchars($sup['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Giá vốn (VNĐ)</label>
                        <input type="number" name="cost_price" id="prodCostPrice" step="1000" min="0" placeholder="0" onblur="validateCostPrice()">
                        <span id="costPriceError" class="error-message"></span>
                    </div>
                    <div class="form-group">
                        <label>Giá bán (VNĐ)</label>
                        <input type="number" name="price" id="prodPrice" step="1000" min="0" placeholder="0" onblur="validatePrice()">
                        <span id="priceError" class="error-message"></span>
                    </div>
                    <div class="form-group">
                        <label>Đơn vị tính</label>
                        <input type="text" name="unit" id="prodUnit" placeholder="Ví dụ: Cái, hộp, thùng">
                    </div>
                    <div class="form-group">
                        <label>Tồn kho tối thiểu</label>
                        <input type="number" name="min_stock" id="prodMinStock" min="0" placeholder="0">
                    </div>
                    <div class="form-group">
                        <label>Tồn kho tối đa</label>
                        <input type="number" name="max_stock" id="prodMaxStock" min="0" placeholder="0">
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
                        <textarea name="description" id="prodDesc" rows="3" placeholder="Mô tả sản phẩm (nếu có)"></textarea>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Lưu sản phẩm</button>
                    <button type="button" class="btn btn-secondary" onclick="closeProductModal()">Hủy</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ── Modal danh mục nhanh ─────────────────────────────────────────────── -->
    <div id="categoryModal" class="modal">
    <div class="modal-content" style="max-width:550px">
        <div class="modal-header">
            <h3>Thêm danh mục mới</h3>
            <span class="close" onclick="closeCategoryModal()">&times;</span>
        </div>
        <form id="categoryForm" onsubmit="submitCategory(event)">
            <input type="hidden" id="csrfTokenMeta" value="<?= htmlspecialchars($csrfToken) ?>">
            <div style="overflow-y: auto; flex: 1; padding-bottom: 16px;">
            <div class="form-group">
                <label>Tên danh mục <span class="required">*</span></label>
                <input type="text" name="name" id="catName" required>
            </div>

            <div class="form-group">
                <label>Mô tả / Ghi chú</label>
                <textarea name="description" id="catDesc" rows="3"></textarea>
            </div>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Lưu</button>
                <button type="button" class="btn btn-secondary" onclick="closeCategoryModal()">Hủy</button>
            </div>
        </form>
    </div>
</div>

</div>

<script src="<?= BASE_URL ?>/js/utils.js"></script>
<script src="<?= BASE_URL ?>/js/products.js"></script>
<script>
    window._productTotalRows = <?= (int) $totalRows ?>;
</script>
<?php include __DIR__ . '/../../layout/footer.php'; ?>