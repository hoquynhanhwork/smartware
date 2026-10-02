<?php
// pages/reports/inventory.php
$page_title = 'BÁO CÁO TỒN KHO';
$page_icon  = 'ri-stack-fill';

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use App\Repositories\ReportRepository;
use App\Services\ReportService;

requireLogin();

$service    = new ReportService(new ReportRepository($pdo));

$days = min(90, max(7, (int)($_GET['days'] ?? 30)));
$data = $service->getInventoryData($days);
$kpi  = $data['kpi'];

include __DIR__ . '/../../layout/header.php';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/css/reports.css">

<div class="reports-container">

    <div class="report-header">
        <div class="period-picker">
            <span style="font-size:13px;color:#64748b">Lô hàng hết hạn trong:</span>
            <?php foreach ([7,14,30,60,90] as $d): ?>
            <button class="shortcut-btn <?= $days == $d ? 'active' : '' ?>"
                    data-days="<?= $d ?>"
                    onclick="ReportApp.setDays(<?= $d ?>)"><?= $d ?> ngày</button>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="report-tabs">
        <a href="overview.php"  class="tab-link">Tổng quan</a>
        <a href="inventory.php" class="tab-link active">
            Tồn kho
            <div>
                <p class="page-subtitle">Cập nhật theo thời gian thực</p>
            </div>
        </a>
        <a href="products.php"  class="tab-link">Sản phẩm</a>
        <a href="suppliers.php" class="tab-link">Nhà cung cấp</a>
    </div>

    <!-- KPI -->
    <div class="kpi-grid-4" id="kpiGrid">
        <?php
        $cards = [
            ['icon'=>'ri-stack-fill',          'color'=>'blue',  'label'=>'Tổng giá trị tồn kho', 'value'=>number_format($kpi['total_value'],0,',','.') . ' ₫', 'sub'=>'Tính theo giá vốn'],
            ['icon'=>'ri-alarm-warning-fill',  'color'=>'orange','label'=>'Giá trị lô sắp hết hạn','value'=>number_format($kpi['at_risk_value'],0,',','.') . ' ₫', 'sub'=> "Trong $days ngày tới"],
            ['icon'=>'ri-error-warning-fill',  'color'=>'red',   'label'=>'SP cần nhập hàng',      'value'=>$kpi['low_stock_cnt'] . ' SKU',  'sub'=>'Hết hàng + sắp hết'],
            ['icon'=>'ri-time-fill',           'color'=>'orange','label'=>'Lô sắp hết hạn',        'value'=>$kpi['near_expiry_cnt'] . ' lô', 'sub'=>"Trong $days ngày tới"],
        ];
        foreach ($cards as $c): ?>
        <div class="kpi-card">
            <div class="kpi-icon icon-<?= $c['color'] ?>"><i class="<?= $c['icon'] ?>"></i></div>
            <div class="kpi-body">
                <div class="kpi-label"><?= $c['label'] ?></div>
                <div class="kpi-value" style="font-size:16px"><?= $c['value'] ?></div>
                <div class="kpi-sub"><?= $c['sub'] ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Charts -->
    <div class="chart-row-2">
        <div class="chart-box">
            <div class="chart-header"><h3><i class="ri-pie-chart-2-line"></i> Phân bố tồn kho theo trạng thái (SKU)</h3></div>
            <canvas id="distChart" height="220"></canvas>
        </div>
        <div class="chart-box">
            <div class="chart-header"><h3><i class="ri-bar-chart-fill"></i> Giá trị tồn kho theo danh mục (₫)</h3></div>
            <canvas id="catChart" height="320"></canvas>
        </div>
    </div>

    <!-- Tables -->
    <div class="tables-row">
        <div class="table-box">
            <div class="table-box-header">
                <h3><i class="ri-error-warning-fill" style="color:#ef4444"></i> Sản phẩm cần nhập hàng</h3>
            </div>
            <table class="data-table" id="lowStockTable">
                <thead><tr><th>Sản phẩm</th><th class="text-right">Tồn</th><th class="text-right">Ngưỡng</th><th class="text-right">Còn dùng (ngày)</th></tr></thead>
                <tbody>
                <?php foreach ($data['low_stock'] as $r): ?>
                <tr>
                    <td>
                        <div class="prod-name"><?= htmlspecialchars($r['product_name']) ?></div>
                        <div class="prod-sku"><?= htmlspecialchars($r['sku'] ?? '') ?> · <?= htmlspecialchars($r['unit'] ?? '') ?></div>
                    </td>
                    <td class="text-right" style="color:<?= $r['stock_status']==='OUT_OF_STOCK' ? '#ef4444' : '#f59e0b' ?>;font-weight:600">
                        <?= number_format($r['current_stock']) ?>
                    </td>
                    <td class="text-right text-muted"><?= number_format($r['min_stock']) ?></td>
                    <td class="text-right">
                        <?php if ($r['estimated_days_left'] !== null): ?>
                            <span style="color:<?= $r['estimated_days_left'] <= 7 ? '#ef4444' : '#f59e0b' ?>;font-weight:500">
                                <?= number_format($r['estimated_days_left'], 1) ?> ngày
                            </span>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($data['low_stock'])): ?>
                    <tr><td colspan="4" class="text-center text-muted" style="padding:20px">Tồn kho ổn định</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="table-box">
            <div class="table-box-header">
                <h3><i class="ri-time-fill" style="color:#f59e0b"></i> Lô hàng sắp hết hạn</h3>
            </div>
            <table class="data-table" id="nearExpiryTable">
                <thead><tr><th>Sản phẩm · Lô</th><th class="text-right">Tồn</th><th class="text-right">HSD</th><th class="text-right">Còn (ngày)</th></tr></thead>
                <tbody>
                <?php foreach ($data['near_expiry'] as $r): ?>
                <tr>
                    <td>
                        <div class="prod-name"><?= htmlspecialchars($r['product_name']) ?></div>
                        <div class="prod-sku"><?= htmlspecialchars($r['batch_no']) ?></div>
                    </td>
                    <td class="text-right"><?= number_format($r['quantity']) ?> <?= htmlspecialchars($r['unit'] ?? '') ?></td>
                    <td class="text-right text-muted"><?= $r['exp_date'] ? date('d/m/Y', strtotime($r['exp_date'])) : '—' ?></td>
                    <td class="text-right">
                        <span class="<?= $r['days_left'] <= 7 ? 'expiry-critical' : 'expiry-warning' ?>">
                            <?= $r['days_left'] ?> ngày
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($data['near_expiry'])): ?>
                    <tr><td colspan="4" class="text-center text-muted" style="padding:20px">Không có lô sắp hết hạn</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
const REPORT_TYPE   = 'inventory';
const INITIAL_DATA   = <?= json_encode($data) ?>;
const INITIAL_PARAMS = { days: <?= json_encode($days) ?> };
</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script src="<?= BASE_URL ?>/js/report.js"></script>

<?php include __DIR__ . '/../../layout/footer.php'; ?>