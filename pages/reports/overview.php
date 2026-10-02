<?php
// pages/reports/overview.php
$page_title = 'BÁO CÁO TỔNG QUAN';
$page_icon  = 'ri-bar-chart-2-line';

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use App\Repositories\ReportRepository;
use App\Services\ReportService;
use App\Helpers\AppTime;

requireLogin();

$service    = new ReportService(new ReportRepository($pdo));

// Parse period — mặc định tháng hiện tại (theo "hôm nay ảo" nếu có frozen_date)
[$from, $to] = $service->parsePeriod($_GET, AppTime::calc($pdo, 'Y-m-01'), AppTime::today($pdo));

// Load dữ liệu initial (server-side render lần đầu)
$data = $service->getOverviewData($from, $to);
$kpi  = $data['kpi'];

include __DIR__ . '/../../layout/header.php';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/css/reports.css">

<div class="reports-container">

    <!-- ── Header + Period picker ────────────────────────────────────────── -->
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

    <!-- ── Nav tabs sang các báo cáo khác ───────────────────────────────── -->
    <div class="report-tabs">
        <a href="overview.php"  class="tab-link active">
            Tổng quan
            <div>
                <p class="page-subtitle">
                    <?= date('d/m/Y', strtotime($from)) ?> — <?= date('d/m/Y', strtotime($to)) ?>
                </p>
            </div>
        </a>
        <a href="inventory.php" class="tab-link">Tồn kho</a>
        <a href="products.php"  class="tab-link">Sản phẩm</a>
        <a href="suppliers.php" class="tab-link">Nhà cung cấp</a>
    </div>

    <!-- ── KPI Cards ─────────────────────────────────────────────────────── -->
    <div class="kpi-grid-4" id="kpiGrid">
        <?php
        $kpiCards = [
            ['icon'=>'ri-arrow-down-circle-fill','color'=>'blue',  'label'=>'Tổng nhập kỳ',    'value'=>number_format($kpi['inbound_amount'],0,',','.') . ' ₫', 'pct'=>$kpi['inbound_pct'],  'sub'=>$kpi['inbound_orders'] . ' phiếu · ' . $kpi['inbound_suppliers'] . ' NCC'],
            ['icon'=>'ri-arrow-up-circle-fill',  'color'=>'green', 'label'=>'Tổng xuất kỳ',    'value'=>number_format($kpi['outbound_amount'],0,',','.') . ' ₫', 'pct'=>$kpi['outbound_pct'], 'sub'=>$kpi['outbound_orders'] . ' phiếu'],
            ['icon'=>'ri-stack-fill',            'color'=>'purple','label'=>'Giá trị tồn kho', 'value'=>number_format($kpi['inventory_value'],0,',','.') . ' ₫', 'pct'=>null, 'sub'=>'Tính theo giá vốn'],
            ['icon'=>'ri-scales-fill',           'color'=>'orange','label'=>'Lợi nhuận gộp ước tính', 'value'=>number_format($kpi['outbound_amount'] - $kpi['inbound_amount'],0,',','.') . ' ₫', 'pct'=>null, 'sub'=>'Xuất − Nhập kỳ'],
        ];
        foreach ($kpiCards as $card): ?>
        <div class="kpi-card">
            <div class="kpi-icon icon-<?= $card['color'] ?>"><i class="<?= $card['icon'] ?>"></i></div>
            <div class="kpi-body">
                <div class="kpi-label"><?= $card['label'] ?></div>
                <div class="kpi-value"><?= $card['value'] ?></div>
                <div class="kpi-sub">
                    <?php if ($card['pct'] !== null): ?>
                        <span class="pct-badge <?= $card['pct'] >= 0 ? 'up' : 'down' ?>">
                            <i class="ri-arrow-<?= $card['pct'] >= 0 ? 'up' : 'down' ?>-line"></i>
                            <?= abs($card['pct']) ?>% so kỳ trước
                        </span>
                    <?php endif; ?>
                    <?= $card['sub'] ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- ── Chart: Xu hướng nhập xuất ────────────────────────────────────── -->
    <div class="chart-row">
        <div class="chart-box chart-lg">
            <div class="chart-header">
                <h3><i class="ri-line-chart-line"></i> Xu hướng nhập / xuất theo tháng</h3>
            </div>
            <canvas id="flowChart" height="80"></canvas>
        </div>
    </div>

    <!-- ── Bảng Top sản phẩm + Top NCC ──────────────────────────────────── -->
    <div class="tables-row">

        <div class="table-box">
            <div class="table-box-header">
                <h3><i class="ri-fire-line" style="color: red"></i> Top sản phẩm xuất nhiều nhất</h3>
            </div>
            <table class="data-table" id="topProductsTable">
                <thead><tr><th>Sản phẩm</th><th class="text-right">SL</th><th class="text-right">Doanh thu (₫)</th></tr></thead>
                <tbody>
                    <?php foreach ($data['top_products'] as $p): ?>
                    <tr>
                        <td><div class="prod-name"><?= htmlspecialchars($p['product_name']) ?></div><div class="prod-sku"><?= htmlspecialchars($p['sku'] ?? '') ?></div></td>
                        <td class="text-right"><?= number_format($p['total_qty']) ?></td>
                        <td class="text-right font-medium"><?= number_format($p['total_revenue'],0,',','.') ?> ₫</td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($data['top_products'])): ?>
                        <tr><td colspan="3" class="text-center text-muted" style="padding:20px">Không có dữ liệu</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="table-box">
            <div class="table-box-header">
                <h3><i class="ri-building-2-line"></i> Top nhà cung cấp theo giá trị nhập</h3>
            </div>
            <table class="data-table" id="topSuppliersTable">
                <thead><tr><th>Nhà cung cấp</th><th class="text-right">Phiếu</th><th class="text-right">Tổng nhập (₫)</th></tr></thead>
                <tbody>
                    <?php foreach ($data['top_suppliers'] as $s): ?>
                    <tr>
                        <td><?= htmlspecialchars($s['supplier_name']) ?></td>
                        <td class="text-right"><?= $s['order_count'] ?></td>
                        <td class="text-right font-medium"><?= number_format($s['total_amount'],0,',','.') ?> ₫</td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($data['top_suppliers'])): ?>
                        <tr><td colspan="3" class="text-center text-muted" style="padding:20px">Không có dữ liệu</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </div>
</div>

<script>
const REPORT_TYPE   = 'overview';
const INITIAL_DATA   = <?= json_encode($data) ?>;
const INITIAL_PARAMS = { from: <?= json_encode($from) ?>, to: <?= json_encode($to) ?> };
</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script src="<?= BASE_URL ?>/js/report.js"></script>

<?php include __DIR__ . '/../../layout/footer.php'; ?>