<?php
// pages/reports/products.php
$page_title = 'BÁO CÁO SẢN PHẨM';
$page_icon  = 'ri-archive-line';

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use App\Repositories\ReportRepository;
use App\Services\ReportService;
use App\Helpers\AppTime;

requireLogin();

$service    = new ReportService(new ReportRepository($pdo));

[$from, $to] = $service->parsePeriod($_GET, AppTime::calc($pdo, 'Y-m-01'), AppTime::today($pdo));
$data = $service->getProductData($from, $to, $_GET);

include __DIR__ . '/../../layout/header.php';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/css/reports.css">

<div class="reports-container">

    <div class="report-header">
        <div class="period-picker">
            <button class="shortcut-btn <?= $from === AppTime::calc($pdo, 'Y-m-01') && $to === AppTime::today($pdo) ? 'active' : '' ?>"
                    data-from="<?= AppTime::calc($pdo, 'Y-m-01') ?>" data-to="<?= AppTime::today($pdo) ?>"
                    onclick="ReportApp.setPeriod(this.dataset.from, this.dataset.to)">Tháng này</button>
            <button class="shortcut-btn"
                    data-from="<?= AppTime::calc($pdo, 'Y-m-01', 'first day of last month') ?>" data-to="<?= AppTime::calc($pdo, 'Y-m-t', 'last day of last month') ?>"
                    onclick="ReportApp.setPeriod(this.dataset.from, this.dataset.to)">Tháng trước</button>
            <button class="shortcut-btn"
                    data-from="<?= AppTime::calc($pdo, 'Y-01-01') ?>" data-to="<?= AppTime::today($pdo) ?>"
                    onclick="ReportApp.setPeriod(this.dataset.from, this.dataset.to)">Năm nay</button>
            <span class="period-sep">|</span>
            <input type="date" id="fromDate" value="<?= $from ?>" class="period-input">
            <span>—</span>
            <input type="date" id="toDate"   value="<?= $to ?>"   class="period-input">
            <button class="btn-primary btn-sm" onclick="ReportApp.applyFromInputs()"><i class="ri-refresh-line"></i> Áp dụng</button>
        </div>
    </div>

    <div class="report-tabs">
        <a href="overview.php"  class="tab-link">Tổng quan</a>
        <a href="inventory.php" class="tab-link">Tồn kho</a>
        <a href="products.php"  class="tab-link active">
            Sản phẩm
            <div>
                <p class="page-subtitle"><?= date('d/m/Y', strtotime($from)) ?> — <?= date('d/m/Y', strtotime($to)) ?></p>
            </div>
        </a>
        <a href="suppliers.php" class="tab-link">Nhà cung cấp</a>
    </div>

    <!-- Chart doanh thu theo danh mục -->
    <div class="chart-row">
        <div class="chart-box">
            <div class="chart-header">
                <h3><i class="ri-pie-chart-2-line"></i> Doanh thu theo danh mục</h3>
            </div>
            <canvas id="catChart" height="70"></canvas>
        </div>
    </div>

    <!-- Bảng hiệu suất sản phẩm -->
    <div class="table-box" style="margin-bottom:24px">
        <div class="table-box-header" style="display:flex;justify-content:space-between;align-items:center">
            <h3><i class="ri-trophy-line"></i> Hiệu suất sản phẩm — <?= $data['total'] ?> sản phẩm có giao dịch</h3>
            <div class="sort-tabs">
                <button class="sort-btn <?= $data['sort']==='revenue' ? 'active' : '' ?>" data-sort="revenue" onclick="ReportApp.setSort('revenue')">Doanh thu</button>
                <button class="sort-btn <?= $data['sort']==='qty'     ? 'active' : '' ?>" data-sort="qty"     onclick="ReportApp.setSort('qty')">Số lượng</button>
                <button class="sort-btn <?= $data['sort']==='orders'  ? 'active' : '' ?>" data-sort="orders"  onclick="ReportApp.setSort('orders')">Số phiếu</button>
            </div>
        </div>
        <div style="overflow-x:auto">
        <table class="data-table" id="productTable">
            <thead>
                <tr>
                    <th>STT</th>
                    <th>Sản phẩm</th>
                    <th>Danh mục</th>
                    <th class="text-right">SL xuất</th>
                    <th class="text-right">Doanh thu (₫)</th>
                    <th class="text-right">Số phiếu</th>
                    <th class="text-right">Tồn hiện tại</th>
                    <th class="text-right">Tốc độ bán/ngày</th>
                    <th class="text-right">Còn dùng (ngày)</th>
                </tr>
            </thead>
            <tbody id="productTableBody">
            <?php foreach ($data['items'] as $i => $r):
                $offset = ($data['page'] - 1) * 20;
                $daysLeft = $r['estimated_days_left'];
                $daysColor = $daysLeft === null ? '#94a3b8' : ($daysLeft <= 7 ? '#ef4444' : ($daysLeft <= 30 ? '#f59e0b' : '#22c55e'));
            ?>
            <tr>
                <td class="text-center text-muted"><?= $offset + $i + 1 ?></td>
                <td>
                    <div class="prod-name"><?= htmlspecialchars($r['product_name']) ?></div>
                    <div class="prod-sku"><?= htmlspecialchars($r['sku'] ?? '') ?> · <?= htmlspecialchars($r['unit'] ?? '') ?></div>
                </td>
                <td class="text-muted"><?= htmlspecialchars($r['category_name'] ?? '—') ?></td>
                <td class="text-right font-medium"><?= number_format($r['total_qty']) ?></td>
                <td class="text-right font-medium"><?= number_format($r['total_revenue'],0,',','.') ?> ₫</td>
                <td class="text-right"><?= $r['order_count'] ?></td>
                <td class="text-right"><?= number_format($r['current_stock'] ?? 0) ?></td>
                <td class="text-right text-muted"><?= $r['avg_daily_out'] ? number_format($r['avg_daily_out'],1) : '—' ?></td>
                <td class="text-right" style="color:<?= $daysColor ?>;font-weight:500">
                    <?= $daysLeft !== null ? number_format($daysLeft,1) . ' ngày' : '—' ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($data['items'])): ?>
                <tr><td colspan="9" class="text-center text-muted" style="padding:20px">Không có dữ liệu trong kỳ này</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>

        <!-- Phân trang (luôn render container để JS có thể cập nhật sau AJAX) -->
        <div class="report-pagination" id="pagination">
            <?php if ($data['total_pages'] > 1): ?>
                <?php for ($p = 1; $p <= $data['total_pages']; $p++): ?>
                    <button class="<?= $p === $data['page'] ? 'active' : '' ?>" onclick="ReportApp.setPage(<?= $p ?>)"><?= $p ?></button>
                <?php endfor; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
const REPORT_TYPE   = 'products';
const INITIAL_DATA   = <?= json_encode($data) ?>;
const INITIAL_PARAMS = {
    from: <?= json_encode($from) ?>,
    to: <?= json_encode($to) ?>,
    sort: <?= json_encode($data['sort']) ?>,
    page: <?= json_encode($data['page']) ?>
};
</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script src="<?= BASE_URL ?>/js/report.js"></script>

<?php include __DIR__ . '/../../layout/footer.php'; ?>