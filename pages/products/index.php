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

// ── Lấy dữ liệu qua Service ──────────────────────────────────────────────────
$result   = $service->list($filters);
$stats    = $service->stats();
$formData = $service->getFormData();

$products    = $result['items'];
$totalRows   = $result['total'];
$totalPages  = $result['total_pages'];
$page        = $result['page'];
$limit       = $result['limit'];

$suppliers  = $formData['suppliers'];
$categories = $formData['categories'];

$filterCount = count($categoryIds) + count($statuses) + count($supplierIds)
             + ($stockMin !== '' || $stockMax !== '' ? 1 : 0)
             + ($keyword !== '' ? 1 : 0);

$csrfToken = $csrf->getToken();

include __DIR__ . '/../../layout/header.php';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/css/products.css">

<!-- Script chống giật khi tải trang (không dùng !important) -->
<script>
    (function () {
        if (localStorage.getItem('product_filter_hidden') === 'true') {
            document.documentElement.classList.add('product-filter-hidden');
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

            <!-- 1. TRẠNG THÁI HÀNG HÓA -->
            <div class="filter-section" data-filter-key="status">
                <div class="filter-sec-title">
                    <span><i class="ri-checkbox-circle-line"></i> Trạng thái kho</span>
                    <i class="ri-arrow-down-s-line"></i>
                </div>
                <div class="filter-checkbox-list">
                    <label class="filter-check-item">
                        <input type="checkbox" name="status[]" value="active" <?= in_array('active', $statuses, true) ? 'checked' : '' ?> onchange="this.form.submit()">
                        <span class="dot-indicator dot-success"></span>
                        <span class="label-text">Đang hoạt động</span>
                    </label>
                    <label class="filter-check-item">
                        <input type="checkbox" name="status[]" value="inactive" <?= in_array('inactive', $statuses, true) ? 'checked' : '' ?> onchange="this.form.submit()">
                        <span class="dot-indicator dot-danger"></span>
                        <span class="label-text">Ngừng kinh doanh</span>
                    </label>
                </div>
            </div>

            <!-- 2. DANH MỤC SẢN PHẨM -->
            <div class="filter-section" data-filter-key="category">
                <div class="filter-sec-title">
                    <span><i class="ri-folder-3-line"></i> Danh mục</span>
                    <i class="ri-arrow-down-s-line"></i>
                </div>
                <div class="filter-checkbox-list filter-scrollable">
                    <?php foreach ($categories as $cat): ?>
                    <label class="filter-check-item">
                        <input type="checkbox" name="category_id[]" value="<?= $cat['id'] ?>" <?= in_array($cat['id'], $categoryIds, true) ? 'checked' : '' ?> onchange="this.form.submit()">
                        <span class="label-text"><?= htmlspecialchars($cat['name']) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- 3. SỐ LƯỢNG TỒN -->
            <div class="filter-section" data-filter-key="stock">
                <div class="filter-sec-title">
                    <span><i class="ri-archive-line"></i> Số lượng tồn</span>
                    <i class="ri-arrow-down-s-line"></i>
                </div>
                <div class="filter-range-inputs">
                    <input type="number" name="stock_min" value="<?= htmlspecialchars($stockMin) ?>" placeholder="Tối thiểu" class="range-field">
                    <span>-</span>
                    <input type="number" name="stock_max" value="<?= htmlspecialchars($stockMax) ?>" placeholder="Tối đa" class="range-field">
                </div>
                <button type="submit" class="btn-apply-stock">Lọc tồn</button>
            </div>

            <!-- 4. NHÀ CUNG CẤP -->
            <div class="filter-section" data-filter-key="supplier">
                <div class="filter-sec-title">
                    <span><i class="ri-store-2-line"></i> Nhà cung cấp</span>
                    <i class="ri-arrow-down-s-line"></i>
                </div>
                <div class="filter-checkbox-list filter-scrollable">
                    <?php foreach ($suppliers as $sup): ?>
                    <label class="filter-check-item">
                        <input type="checkbox" name="supplier_id[]" value="<?= $sup['id'] ?>" <?= in_array($sup['id'], $supplierIds, true) ? 'checked' : '' ?> onchange="this.form.submit()">
                        <span class="label-text"><?= htmlspecialchars($sup['name']) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <input type="hidden" name="keyword" value="<?= htmlspecialchars($keyword) ?>">
        </form>
    </aside>

    <!-- KHU VỰC BẢNG DỮ LIỆU CHÍNH -->
    <main class="task-table-main">
        <!-- TOP TOOLBAR -->
        <div class="task-top-toolbar">
            <div class="tb-left">
                <button type="button" class="btn-tb-filter" id="btnToggleSidebar">
                    <i class="ri-equalizer-line"></i>
                    <span id="txtToggleSidebar">Hide Filters</span>
                </button>
                <div class="tb-dropdown-badge">
                    <span>Tất cả sản phẩm (<?= number_format($totalRows) ?>)</span>
                    <i class="ri-arrow-down-s-line"></i>
                </div>
            </div>

            <div class="tb-right">
                <div class="tb-search-box">
                    <i class="ri-search-line"></i>
                    <input type="text" id="searchInput" placeholder="Tìm kiếm sản phẩm..." value="<?= htmlspecialchars($keyword) ?>" onkeyup="searchProductTable()">
                </div>

                <button type="button" class="btn-tb-secondary" onclick="openCategoryModal()">
                    <i class="ri-folder-add-line"></i> Danh mục
                </button>

                <button type="button" class="btn-tb-primary" onclick="openProductModal('add')">
                    <i class="ri-add-line"></i> Thêm sản phẩm
                </button>
            </div>
        </div>

        <!-- BẢNG DỮ LIỆU -->
        <div class="task-table-card">
            <table class="task-data-table" id="productTable">
                <thead>
                    <tr>
                        <th width="42"><input type="checkbox" id="selectAll"></th>
                        <th width="320">Tên sản phẩm</th>
                        <th width="150">Giá bán</th>
                        <th width="140">Trạng thái</th>
                        <th width="180">Danh mục</th>
                        <th width="130">Tồn kho</th>
                        <th width="100" class="text-right">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($products)): ?>
                        <tr>
                            <td colspan="7" class="table-empty-cell">
                                <i class="ri-inbox-line"></i>
                                <p>Không tìm thấy sản phẩm nào</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($products as $p):
                            $stock = (int) $p['total_stock'];
                            if ($p['status'] === 'inactive') {
                                $statusClass = 'st-overdue';
                                $statusText  = 'Ngừng KD';
                            } elseif ($stock <= (int) $p['min_stock']) {
                                $statusClass = 'st-pending';
                                $statusText  = 'Cần nhập';
                            } else {
                                $statusClass = 'st-completed';
                                $statusText  = 'Còn hàng';
                            }
                        ?>
                        <tr>
                            <td><input type="checkbox" class="row-checkbox" data-id="<?= $p['id'] ?>"></td>
                            <td>
                                <div class="task-title-cell">
                                    <div class="task-text-info">
                                        <div class="item-name"><?= htmlspecialchars($p['name']) ?></div>
                                        <span class="item-sku"><?= htmlspecialchars($p['sku'] ?? 'N/A') ?></span>
                                    </div>
                                </div>
                            </td>
                            <td class="font-price"><?= number_format((float) $p['price']) ?>₫</td>
                            <td>
                                <span class="clean-badge <?= $statusClass ?>">
                                    <?= $statusText ?>
                                </span>
                            </td>
                            <td>
                                <div class="related-cell">
                                    <i class="ri-folder-line"></i>
                                    <span><?= htmlspecialchars($p['category_name'] ?? 'Chưa phân loại') ?></span>
                                </div>
                            </td>
                            <td>
                                <span class="stock-pill"><?= number_format($stock) ?> <?= htmlspecialchars($p['unit'] ?? 'cái') ?></span>
                            </td>
                            <td class="text-right actions-cell">
                                <button type="button" class="btn-action-icon" onclick="openProductModal('edit', <?= htmlspecialchars(json_encode($p), ENT_QUOTES) ?>)" title="Sửa">
                                    <i class="ri-pencil-line"></i>
                                </button>
                                <button type="button" class="btn-action-icon btn-action-delete" onclick="confirmDeleteProduct(<?= $p['id'] ?>, '<?= $p['status'] ?>')" title="Xóa">
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
            <span id="bulkCount" class="bulk-count">0 sản phẩm đã chọn</span>
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

<!-- ── MODAL THÊM / SỬA SẢN PHẨM ────────────────────────────────── -->
<div id="productModal" class="modal-modern" style="display:none;">
    <div class="modal-modern-dialog">
        <div class="modal-modern-header">
            <div>
                <h3 id="modalTitle">Thêm sản phẩm mới</h3>
                <p class="modal-subtitle">Điền thông tin chi tiết cho sản phẩm bên dưới.</p>
            </div>
            <button type="button" class="btn-close-modern" onclick="closeProductModal()">&times;</button>
        </div>

        <form id="productForm" method="POST" action="process.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="id" id="productId" value="0">
            <input type="hidden" name="status" id="prodStatus" value="active">

            <!-- Tab Navigation -->
            <div class="modal-tabs-nav">
                <button type="button" class="tab-pill-btn active" data-tab="tab-basic">Thông tin cơ bản</button>
                <button type="button" class="tab-pill-btn" data-tab="tab-pricing">Giá cả & Tồn kho</button>
            </div>

            <div class="modal-tabs-body">
                <!-- TAB 1: THÔNG TIN CƠ BẢN -->
                <div class="tab-pane active" id="tab-basic">
                    <div class="form-row-modern">
                        <label class="form-label-modern">Tên sản phẩm <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="prodName" required placeholder="Nhập tên sản phẩm..." class="form-input-modern">
                    </div>

                    <!-- GRID 2: MÃ SKU & ĐƠN VỊ TÍNH -->
                    <div class="form-grid-2">
                        <div class="form-row-modern">
                            <label class="form-label-modern">Mã SKU</label>
                            <input type="text" name="sku" id="prodSku" placeholder="VD: SP-100-SF..." onblur="checkSku()" class="form-input-modern">
                            <span id="skuError" class="error-message"></span>
                        </div>
                        <div class="form-row-modern">
                            <label class="form-label-modern">Đơn vị tính</label>
                            <input type="text" name="unit" id="prodUnit" placeholder="Cái, hộp, thùng..." class="form-input-modern">
                        </div>
                    </div>

                    <!-- GRID 2: DANH MỤC & NHÀ CUNG CẤP -->
                    <div class="form-grid-2">
                        <!-- 1. DROPDOWN CHỌN DANH MỤC -->
                        <div class="form-row-modern">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                                <label class="form-label-modern" style="margin-bottom: 0;">Danh mục</label>
                                <button type="button" 
                                        onclick="openCategoryModalFromProduct()" 
                                        class="btn-link-add-cat"
                                        title="Thêm danh mục mới">
                                    <i class="ri-add-line"></i> Thêm mới
                                </button>
                            </div>

                            <div class="custom-select-dropdown" id="catCustomDropdown">
                                <button type="button" class="select-trigger-btn" onclick="toggleCustomDropdown('catCustomDropdown')">
                                    <span class="selected-text" id="catSelectedText">-- Không phân loại --</span>
                                    <i class="ri-arrow-down-s-line trigger-arrow"></i>
                                </button>

                                <div class="select-dropdown-menu" id="categoryRadioContainer">
                                    <label class="radio-circle-item">
                                        <input type="radio" name="category_id" value="" checked onchange="onRadioSelectChange('cat', '-- Không phân loại --')">
                                        <span class="custom-radio-circle"></span>
                                        <span class="radio-text-label">-- Không phân loại --</span>
                                    </label>
                                    <?php foreach ($categories as $cat): ?>
                                        <label class="radio-circle-item" id="cat-radio-label-<?= $cat['id'] ?>">
                                            <input type="radio" name="category_id" value="<?= $cat['id'] ?>" onchange="onRadioSelectChange('cat', '<?= htmlspecialchars(addslashes($cat['name'])) ?>')">
                                            <span class="custom-radio-circle"></span>
                                            <span class="radio-text-label"><?= htmlspecialchars($cat['name']) ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <!-- 2. DROPDOWN CHỌN NHÀ CUNG CẤP -->
                        <div class="form-row-modern">
                            <label class="form-label-modern" style="margin-bottom: 6px;">Nhà cung cấp</label>
                            
                            <div class="custom-select-dropdown" id="supCustomDropdown">
                                <button type="button" class="select-trigger-btn" onclick="toggleCustomDropdown('supCustomDropdown')">
                                    <span class="selected-text" id="supSelectedText">-- Không chọn --</span>
                                    <i class="ri-arrow-down-s-line trigger-arrow"></i>
                                </button>

                                <div class="select-dropdown-menu" id="supplierRadioContainer">
                                    <label class="radio-circle-item">
                                        <input type="radio" name="supplier_id" value="" checked onchange="onRadioSelectChange('sup', '-- Không chọn --')">
                                        <span class="custom-radio-circle"></span>
                                        <span class="radio-text-label">-- Không chọn --</span>
                                    </label>
                                    <?php foreach ($suppliers as $sup): ?>
                                        <label class="radio-circle-item">
                                            <input type="radio" name="supplier_id" value="<?= $sup['id'] ?>" onchange="onRadioSelectChange('sup', '<?= htmlspecialchars(addslashes($sup['name'])) ?>')">
                                            <span class="custom-radio-circle"></span>
                                            <span class="radio-text-label"><?= htmlspecialchars($sup['name']) ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- MÔ TẢ SẢN PHẨM: FULL-WIDTH RỘNG RÃI -->
                    <div class="form-row-modern">
                        <label class="form-label-modern">Mô tả sản phẩm</label>
                        <textarea name="description" id="prodDesc" rows="3" placeholder="Nhập ghi chú hoặc mô tả về sản phẩm..." class="form-input-modern"></textarea>
                    </div>
                </div>

                <!-- TAB 2: GIÁ CẢ & TỒN KHO -->
                <div class="tab-pane" id="tab-pricing">
                    <div class="form-grid-2">
                        <div class="form-row-modern">
                            <label class="form-label-modern">Giá vốn (VNĐ)</label>
                            <input type="number" name="cost_price" id="prodCostPrice" step="1000" min="0" placeholder="0" onblur="validateCostPrice()" class="form-input-modern">
                            <span id="costPriceError" class="error-message"></span>
                        </div>
                        <div class="form-row-modern">
                            <label class="form-label-modern">Giá bán (VNĐ)</label>
                            <input type="number" name="price" id="prodPrice" step="1000" min="0" placeholder="0" onblur="validatePrice()" class="form-input-modern">
                            <span id="priceError" class="error-message"></span>
                        </div>
                    </div>

                    <div class="form-grid-2" style="margin-top: 14px;">
                        <div class="form-row-modern">
                            <label class="form-label-modern">Tồn kho tối thiểu</label>
                            <input type="number" name="min_stock" id="prodMinStock" min="0" placeholder="0" class="form-input-modern">
                        </div>
                        <div class="form-row-modern">
                            <label class="form-label-modern">Tồn kho tối đa</label>
                            <input type="number" name="max_stock" id="prodMaxStock" min="0" placeholder="0" class="form-input-modern">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Footer: Toggle Switch & Buttons -->
            <div class="modal-modern-footer">
                <label class="toggle-status-wrapper">
                    <input type="checkbox" id="prodStatusToggle" checked onchange="document.getElementById('prodStatus').value = this.checked ? 'active' : 'inactive'">
                    <span class="toggle-slider"></span>
                    <span class="toggle-label-text">Sản phẩm đang hoạt động và hiển thị</span>
                </label>

                <div class="footer-btns-group">
                    <button type="button" class="btn-modern-outline" onclick="closeProductModal()">Hủy</button>
                    <button type="submit" class="btn-modern-dark">Lưu sản phẩm</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- ── MODAL THÊM DANH MỤC ──────────────────────────────────────── -->
<div id="categoryModal" class="modal-modern" style="display:none;">
    <div class="modal-modern-dialog" style="max-width: 520px;">
        <div class="modal-modern-header">
            <div>
                <h3>Thêm danh mục mới</h3>
                <p class="modal-subtitle">Tạo phân loại sản phẩm để dễ dàng quản lý hàng tồn.</p>
            </div>
            <button type="button" class="btn-close-modern" onclick="closeCategoryModal()">&times;</button>
        </div>
        <form id="categoryForm" onsubmit="submitCategory(event)">
            <input type="hidden" id="csrfTokenMeta" value="<?= htmlspecialchars($csrfToken) ?>">
            
            <div class="modal-tabs-body" style="padding-top: 14px;">
                <div class="form-row-modern">
                    <label class="form-label-modern">Tên danh mục <span class="text-danger">*</span></label>
                    <input type="text" name="name" id="catName" required placeholder="Nhập tên phân loại..." class="form-input-modern">
                </div>

                <div class="form-row-modern" style="margin-top: 14px;">
                    <label class="form-label-modern">Mô tả / Ghi chú</label>
                    <textarea name="description" id="catDesc" rows="3" placeholder="Mô tả chi tiết danh mục..." class="form-input-modern"></textarea>
                </div>
            </div>

            <div class="modal-modern-footer" style="justify-content: flex-end;">
                <div class="footer-btns-group">
                    <button type="button" class="btn-modern-outline" onclick="closeCategoryModal()">Hủy</button>
                    <button type="submit" class="btn-modern-dark">Lưu danh mục</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
    window._productTotalRows = <?= (int) $totalRows ?>;
</script>
<script src="<?= BASE_URL ?>/js/utils.js"></script>
<script src="<?= BASE_URL ?>/js/products.js"></script>

<?php include __DIR__ . '/../../layout/footer.php'; ?>