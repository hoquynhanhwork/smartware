<?php
// pages/reports/suppliers.php
$page_title = 'BÁO CÁO NHÀ CUNG CẤP';
$page_icon  = 'ri-building-2-line';

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use App\Repositories\ReportRepository;
use App\Services\ReportService;
use App\Helpers\AppTime;

requireLogin();

$service    = new ReportService(new ReportRepository($pdo));

[$from, $to] = $service->parsePeriod($_GET, AppTime::calc($pdo, 'Y-m-01'), AppTime::today($pdo));
$data = $service->getSupplierData($from, $to, $_GET);

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
            <button class="btn-primary btn-sm" onclick="ReportApp.applyFromInputs()">
                <i class="ri-refresh-line"></i> Áp dụng
            </button>
        </div>
    </div>

    <div class="report-tabs">
        <a href="overview.php"  class="tab-link">Tổng quan</a>
        <a href="inventory.php" class="tab-link">Tồn kho</a>
        <a href="products.php"  class="tab-link">Sản phẩm</a>
        <a href="suppliers.php" class="tab-link active">
            Nhà cung cấp
            <div>
                <p class="page-subtitle"><?= date('d/m/Y', strtotime($from)) ?> — <?= date('d/m/Y', strtotime($to)) ?></p>
            </div>
        </a>
    </div>

    <!-- Chart: nhập theo tháng từng NCC (stacked bar) -->
    <div class="chart-row">
        <div class="chart-box">
            <div class="chart-header">
                <h3><i class="ri-bar-chart-grouped-line"></i> Giá trị nhập theo tháng — Top nhà cung cấp</h3>
            </div>
            <div id="supplierChartWrapper">
                <?php if (!empty($data['chart']['labels'])): ?>
                    <canvas id="supplierChart" height="80"></canvas>
                <?php else: ?>
                    <div style="text-align:center;padding:40px;color:#94a3b8">Không có dữ liệu trong kỳ này</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Bảng hiệu suất NCC -->
    <div class="table-box">
        <div class="table-box-header">
            <h3><i class="ri-trophy-line" style="color: #fc8800"></i> Hiệu suất nhà cung cấp trong kỳ</h3>
        </div>
        <div style="overflow-x:auto">
        <table class="data-table">
            <thead>
                <tr>
                    <th>STT</th>
                    <th>Nhà cung cấp</th>
                    <th class="text-right">Số phiếu</th>
                    <th class="text-right">Tổng nhập (₫)</th>
                    <th class="text-right">Trung bình/phiếu (₫)</th>
                    <th class="text-right">Số SP cung cấp</th>
                    <th class="text-center">Trạng thái</th>
                </tr>
            </thead>
            <tbody id="supplierTableBody">
            <?php if (empty($data['items'])): ?>
                <tr><td colspan="7" class="text-center text-muted" style="padding:20px">Không có giao dịch trong kỳ này</td></tr>
            <?php else: ?>
                <?php foreach ($data['items'] as $i => $r):
                    $pct = $data['items'][0]['total_amount'] > 0
                        ? round($r['total_amount'] / $data['items'][0]['total_amount'] * 100)
                        : 0;
                ?>
                <tr>
                    <td class="text-center text-muted"><?= $i + 1 ?></td>
                    <td>
                        <div class="prod-name"><?= htmlspecialchars($r['supplier_name']) ?></div>
                        <div class="prod-sku"><?= htmlspecialchars($r['phone'] ?? '') ?></div>
                        <!-- Progress bar tỷ trọng -->
                        <div style="margin-top:4px;height:3px;background:#f1f5f9;border-radius:2px;width:120px">
                            <div style="height:3px;background:#3b82f6;border-radius:2px;width:<?= $pct ?>%"></div>
                        </div>
                    </td>
                    <td class="text-right"><?= $r['order_count'] ?></td>
                    <td class="text-right font-medium"><?= number_format($r['total_amount'],0,',','.') ?> ₫</td>
                    <td class="text-right text-muted"><?= number_format($r['avg_order_value'],0,',','.') ?> ₫</td>
                    <td class="text-right"><?= $r['product_count'] ?></td>
                    <td class="text-center">
                        <span style="font-size:11px;padding:2px 8px;border-radius:999px;font-weight:500;
                            background:<?= $r['status']==='active' ? '#f0fdf4' : '#fef2f2' ?>;
                            color:<?= $r['status']==='active' ? '#16a34a' : '#dc2626' ?>">
                            <?= $r['status']==='active' ? 'Đang HT' : 'Ngừng HT' ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>

</div>

<script>
const REPORT_TYPE   = 'suppliers';
const INITIAL_DATA   = <?= json_encode($data) ?>;
const INITIAL_PARAMS = { from: <?= json_encode($from) ?>, to: <?= json_encode($to) ?> };
</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script src="<?= BASE_URL ?>/js/report.js"></script>

<?php include __DIR__ . '/../../layout/footer.php'; ?>