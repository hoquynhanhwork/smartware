<?php
// pages/outbound/index.php
$page_title = "XUẤT KHO";

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use App\Services\OutboundService;
use App\Services\CsrfService;
use App\Repositories\OutboundRepository;
use App\Repositories\ProductRepository;
use App\Repositories\CategoryRepository;
use App\Repositories\SupplierRepository;

requireLogin();

$flash_success = getFlash('success');
$flash_error   = getFlash('error');
$csrf          = new CsrfService();

// ── Khởi tạo Service ─────────────────────────────────────────────────────
$service = new OutboundService(
    new OutboundRepository($pdo),
    new ProductRepository($pdo),
    new CategoryRepository($pdo),
    $pdo
);

// ── Lấy filter ───────────────────────────────────────────────────────────
$from_date      = $_GET['from']   ?? '';
$to_date        = $_GET['to']     ?? '';
$keyword        = trim($_GET['keyword'] ?? '');
$status_filters = array_filter((array) ($_GET['status'] ?? []));

$filters = [
    'keyword'  => $keyword,
    'status'   => $status_filters,
    'from'     => $from_date,
    'to'       => $to_date,
    'page'     => max(1, (int)($_GET['page'] ?? 1)),
    'limit'    => min(100, max(1, (int)($_GET['limit'] ?? 15))),
];

// ── Lấy dữ liệu qua Service ───────────────────────────────────────────────
$result      = $service->list($filters);
$outbounds   = $result['items'];
$total_rows  = $result['total'];
$total_pages = $result['total_pages'];
$page        = $result['page'];
$limit       = $result['limit'];

// Tính số filter đang active
$filter_count = count($status_filters) + (!empty($from_date) ? 1 : 0) + (!empty($to_date) ? 1 : 0);

include __DIR__ . '/../../layout/header.php';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/css/outbound.css">
<script>document.body.dataset.page = 'outbound-index';</script>

<!-- FIX: CSRF token dùng cho AJAX delete từ JS -->
<meta name="csrf-token" content="<?= htmlspecialchars($csrf->getToken()) ?>">

<div class="outbound-container">

    <div class="toolbar-modern">
        <div class="toolbar-left">
            <button class="btn-tool" onclick="toggleFilterBar()">
                <i class="ri-filter-3-line"></i> Bộ lọc
                <?php if ($filter_count > 0): ?>
                    <span class="filter-badge"><?= $filter_count ?></span>
                <?php endif; ?>
            </button>
            <div class="search-box-modern">
                <i class="ri-search-line"></i>
                <input type="text" id="searchInput" placeholder="Tìm kiếm nhanh..."
                       value="<?= htmlspecialchars($keyword) ?>" onkeyup="searchOutboundTable()">
            </div>
        </div>
        <div class="toolbar-right">
            <a href="create.php" class="btn-dark"><i class="ri-add-line"></i> Xuất mới</a>
        </div>
    </div>

    <!-- Filter Bar -->
    <div class="filter-bar-horizontal" id="filterBar" style="display: <?= ($filter_count > 0) ? 'flex' : 'none' ?>;">
        <form method="GET" action="" class="filter-form-inline">
            <input type="<?= empty($from_date) ? 'text' : 'date' ?>" name="from"
                   value="<?= htmlspecialchars($from_date) ?>" class="filter-input"
                   placeholder="Từ ngày" onfocus="this.type='date'"
                   onblur="if(this.value==='') this.type='text'">

            <input type="<?= empty($to_date) ? 'text' : 'date' ?>" name="to"
                   value="<?= htmlspecialchars($to_date) ?>" class="filter-input"
                   placeholder="Đến ngày" onfocus="this.type='date'"
                   onblur="if(this.value==='') this.type='text'">

            <div class="custom-dropdown">
                <button type="button" class="filter-input custom-dropdown-btn" onclick="toggleDropdown('statusDropdownPanel')">
                    Trạng thái <?= !empty($status_filters) ? '('.count($status_filters).')' : '' ?>
                    <i class="ri-arrow-down-s-line"></i>
                </button>
                <div class="dropdown-panel" id="statusDropdownPanel">
                    <div class="dropdown-panel-inner">
                        <div class="fsb-group">
                            <div class="fsb-row">
                                <label class="fsb-label">
                                    <input type="checkbox" name="status[]" value="completed" <?= in_array('completed', $status_filters) ? 'checked' : '' ?>>
                                    <span class="fsb-name">Hoàn thành</span>
                                </label>
                            </div>
                            <div class="fsb-row">
                                <label class="fsb-label">
                                    <input type="checkbox" name="status[]" value="pending" <?= in_array('pending', $status_filters) ? 'checked' : '' ?>>
                                    <span class="fsb-name">Tạm thời</span>
                                </label>
                            </div>
                            <div class="fsb-row">
                                <label class="fsb-label">
                                    <input type="checkbox" name="status[]" value="cancelled" <?= in_array('cancelled', $status_filters) ? 'checked' : '' ?>>
                                    <span class="fsb-name">Đã hủy</span>
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

    <?php if ($flash_success): ?><div class="alert alert-success"><?= htmlspecialchars($flash_success) ?></div><?php endif; ?>
    <?php if ($flash_error):   ?><div class="alert alert-danger"><?= htmlspecialchars($flash_error) ?></div><?php endif; ?>

    <div class="table-card">
        <table class="table-modern" id="outboundTable">
            <thead>
                <tr>
                    <th width="40"><input type="checkbox" id="selectAll"></th>
                    <th width="300">Mã PX</th>
                    <th width="300">Người tạo</th>
                    <th>Ngày tạo</th>
                    <th width="300">Trạng thái</th>
                    <th width="200" class="text-right">Thao tác</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($outbounds)): ?>
                    <tr><td colspan="6" class="text-center" style="padding:40px">Chưa có phiếu xuất nào</td></tr>
                <?php else: ?>
                    <?php foreach ($outbounds as $ob):
                        $statusMap = [
                            'completed' => ['badge-success', 'Hoàn thành'],
                            'pending'   => ['badge-warning', 'Tạm thời'],
                            'cancelled' => ['badge-danger',  'Đã hủy'],
                        ];
                        [$statusClass, $statusText] = $statusMap[$ob['status']] ?? ['badge-modern', $ob['status']];
                        $canEdit = ($ob['status'] === 'pending');
                    ?>
                    <tr>
                        <td><input type="checkbox" class="row-checkbox" data-id="<?= (int)$ob['id'] ?>"></td>
                        <td class="font-medium"><?= htmlspecialchars($ob['ref_no'] ?? '') ?></td>
                        <td><?= htmlspecialchars($ob['user_name'] ?? '') ?></td>
                        <td class="text-muted"><?= htmlspecialchars(date('d/m/Y H:i', strtotime($ob['created']))) ?></td>
                        <td><span class="badge-modern <?= $statusClass ?>"><?= $statusText ?></span></td>
                        <td class="text-right actions-cell">
                            <button class="btn-icon-subtle" onclick="viewOutboundDetail(<?= (int)$ob['id'] ?>)"><i class="ri-eye-line"></i></button>
                            <?php if ($canEdit): ?>
                                <button class="btn-icon-subtle" onclick="editOutbound(<?= (int)$ob['id'] ?>)"><i class="ri-edit-line"></i></button>
                            <?php endif; ?>
                            <?php if (hasRole('admin', 'manager') && $ob['status'] === 'pending'): ?>
                                <button class="btn-icon-subtle text-danger" onclick="confirmDeleteOutbound(<?= (int)$ob['id'] ?>)"><i class="ri-delete-bin-line"></i></button>
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
        <button onclick="exportSelectedExcel()" class="btn-export">
            <i class="ri-download-cloud-2-line"></i> Xuất Excel
        </button>
        <button onclick="clearSelection()" class="btn-clear">Bỏ chọn</button>
    </div>

    <!-- Phân trang -->
    <?php if ($total_pages > 1): ?>
    <div class="pagination-modern">
        <div class="page-numbers">
            <?php
            $baseParams = $_GET;
            unset($baseParams['page']);
            ?>
            <?php if ($page > 1): ?>
                <a href="?<?= http_build_query(array_merge($baseParams, ['page' => $page - 1])) ?>"><i class="ri-arrow-left-s-line"></i></a>
            <?php else: ?>
                <span class="disabled"><i class="ri-arrow-left-s-line"></i></span>
            <?php endif; ?>

            <?php
            $range = 1; $show_dots = false;
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
                <a href="?<?= http_build_query(array_merge($baseParams, ['page' => $page + 1])) ?>"><i class="ri-arrow-right-s-line"></i></a>
            <?php else: ?>
                <span class="disabled"><i class="ri-arrow-right-s-line"></i></span>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<div id="detailOutboundModal" class="modal">
    <div class="modal-content modal-lg">
        <div class="modal-header">
            <h3>Chi tiết phiếu xuất</h3>
            <div class="modal-header-actions">
                <button class="btn btn-sm btn-secondary" onclick="exportOutboundDetailExcel()"><i class="ri-file-excel-line"></i> Excel</button>
                <button class="btn btn-sm btn-secondary" onclick="printOutboundDetail()"><i class="ri-printer-line"></i> In</button>
                <span class="close" onclick="closeDetailOutboundModal()">&times;</span>
            </div>
        </div>
        <div id="detailOutboundContent" style="padding:20px"></div>
    </div>
</div>

<div id="editOutboundModal" class="modal">
    <div class="modal-content edit-modal-doc" style="max-width:1020px;"></div>
</div>

<script src="<?= BASE_URL ?>/js/utils.js"></script>
<script src="<?= BASE_URL ?>/js/outbound.js"></script>

<?php include __DIR__ . '/../../layout/footer.php'; ?>