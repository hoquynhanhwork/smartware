<?php
// pages/dashboard/index.php
$page_title = 'TỔNG QUAN';

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use App\Repositories\DashboardRepository;
use App\Services\DashboardService;

requireLogin();


// ── Composition Root ──────────────────────────────────────────────────────────
$service   = new DashboardService(new DashboardRepository($pdo));
$data      = $service->getDashboardData();

$kpis             = $data['kpis'];
$stats            = $data['stats'];
$low_stock        = $data['low_stock'];
$near_expiry      = $data['near_expiry'];
$recent_inbounds  = $data['recent_inbounds'];
$recent_outbounds = $data['recent_outbounds'];

include __DIR__ . '/../../layout/header.php';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/css/dashboard.css">

<div class="dashboard-container">

    <div class="dashboard-header">
        <a href="<?= BASE_URL ?>/pages/ai/dashboard.php" class="btn-ai">
            <i class="ri-sparkling-line"></i> Phân tích AI
        </a>
    </div>

    <!-- ── 4 KPI Cards ───────────────────────────────────────────────────── -->
    <div class="kpi-grid">
        <div class="kpi-card">
            <div class="kpi-icon blue"><i class="ri-archive-line"></i></div>
            <div class="kpi-info">
                <div class="kpi-value"><?= $kpis['total_products'] ?></div>
                <div class="kpi-label">Sản phẩm đang bán</div>
            </div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon green"><i class="ri-arrow-down-circle-line"></i></div>
            <div class="kpi-info">
                <div class="kpi-value"><?= $kpis['inbound_this_month'] ?></div>
                <div class="kpi-label">Phiếu nhập tháng này</div>
            </div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon orange"><i class="ri-arrow-up-circle-line"></i></div>
            <div class="kpi-info">
                <div class="kpi-value"><?= $kpis['outbound_this_month'] ?></div>
                <div class="kpi-label">Phiếu xuất tháng này</div>
            </div>
        </div>
        <div class="kpi-card <?= $kpis['alert_has_danger'] ? 'kpi-card-danger' : '' ?>">
            <div class="kpi-icon red"><i class="ri-alarm-warning-line"></i></div>
            <div class="kpi-info">
                <div class="kpi-value"><?= $kpis['alert_count'] ?></div>
                <div class="kpi-label">Cảnh báo chưa xử lý</div>
            </div>
        </div>
    </div>

    <!-- ── Charts + Stats ────────────────────────────────────────────────── -->
    <div class="charts-row">
        <div class="chart-box">
            <h3><i class="ri-bar-chart-grouped-line"></i> Xu hướng nhập / xuất</h3>
            <div class="chart-placeholder" id="trendChartPlaceholder">
                <i class="ri-line-chart-line"></i> Biểu đồ sẽ được hiển thị tại đây
            </div>
        </div>
        <div class="stats-box">
            <h3><i class="ri-pie-chart-line"></i> Thống kê nhanh</h3>
            <ul class="stats-list">
                <li>
                    <span class="stat-label">💵 Tổng giá trị tồn kho</span>
                    <span class="stat-value"><?= $stats['inventory_value_formatted'] ?> ₫</span>
                </li>
                <li>
                    <span class="stat-label">📦 Nhập tháng này</span>
                    <span class="stat-value"><?= $stats['inbound_this_month'] ?></span>
                </li>
                <li>
                    <span class="stat-label">📤 Xuất tháng này</span>
                    <span class="stat-value"><?= $stats['outbound_this_month'] ?></span>
                </li>
                <li>
                    <span class="stat-label">⛔ Sản phẩm ngừng bán</span>
                    <span class="stat-value"><?= $stats['inactive_products'] ?></span>
                </li>
            </ul>
        </div>
    </div>

    <!-- ── Cảnh báo tồn kho ───────────────────────────────────────────────── -->
    <div class="alerts-row">

        <!-- Sắp hết hàng -->
        <div class="alerts-card">
            <div class="alerts-card-header">
                <span><i class="ri-error-warning-line"></i> Sắp hết hàng</span>
                <a href="<?= BASE_URL ?>/pages/inventory/index.php">Xem tất cả</a>
            </div>
            <div class="alerts-card-body">
                <?php if (empty($low_stock)): ?>
                    <div class="empty-state">
                        <i class="ri-checkbox-circle-line"></i> Tồn kho ổn định
                    </div>
                <?php else: ?>
                    <table class="dash-table">
                        <thead>
                            <tr><th>Sản phẩm</th><th>SKU</th><th>Tồn</th><th>Ngưỡng</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($low_stock as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['name']) ?></td>
                                <td><code><?= htmlspecialchars($row['sku']) ?></code></td>
                                <td class="text-danger fw-bold"><?= $row['current_stock'] ?></td>
                                <td><?= $row['min_stock'] ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- Hết hạn trong 30 ngày -->
        <div class="alerts-card">
            <div class="alerts-card-header">
                <span><i class="ri-calendar-close-line"></i> Hết hạn trong 30 ngày</span>
                <a href="<?= BASE_URL ?>/pages/inventory/batch.php">Xem tất cả</a>
            </div>
            <div class="alerts-card-body">
                <?php if (empty($near_expiry)): ?>
                    <div class="empty-state">
                        <i class="ri-checkbox-circle-line"></i> Không có lô hàng sắp hết hạn
                    </div>
                <?php else: ?>
                    <table class="dash-table">
                        <thead>
                            <tr><th>Sản phẩm</th><th>Lô</th><th>SL</th><th>HSD</th><th>Còn</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($near_expiry as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['name']) ?></td>
                                <td><code><?= htmlspecialchars($row['batch_no']) ?></code></td>
                                <td><?= $row['quantity'] ?></td>
                                <td><?= $row['exp_date_display'] ?></td>
                                <td class="text-<?= $row['severity'] ?> fw-bold">
                                    <?= $row['days_left'] ?> ngày
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

    </div>

    <!-- ── Giao dịch gần nhất ─────────────────────────────────────────────── -->
    <div class="recent-transactions-row">

        <!-- Nhập kho -->
        <div class="transactions-card alerts-card">
            <div class="alerts-card-header">
                <span><i class="ri-file-list-3-line"></i> Nhập kho gần nhất</span>
                <a href="<?= BASE_URL ?>/pages/inbound/index.php">Xem tất cả</a>
            </div>
            <div class="alerts-card-body">
                <?php if (empty($recent_inbounds)): ?>
                    <div class="empty-state">Chưa có phiếu nhập nào</div>
                <?php else: ?>
                    <table class="dash-table">
                        <thead>
                            <tr><th>Mã PN</th><th>Nhà cung cấp</th><th>Tổng tiền</th><th>Ngày</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_inbounds as $row): ?>
                            <tr>
                                <td><code><?= htmlspecialchars($row['ref_no']) ?></code></td>
                                <td><?= htmlspecialchars($row['supplier_name']) ?></td>
                                <td><?= number_format($row['total_amount'] ?? 0, 0, ',', '.') ?>đ</td>
                                <td><?= date('d/m/Y H:i', strtotime($row['created'])) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- Xuất kho -->
        <div class="transactions-card alerts-card">
            <div class="alerts-card-header">
                <span><i class="ri-logout-box-line"></i> Xuất kho gần nhất</span>
                <a href="<?= BASE_URL ?>/pages/outbound/index.php">Xem tất cả</a>
            </div>
            <div class="alerts-card-body">
                <?php if (empty($recent_outbounds)): ?>
                    <div class="empty-state">Chưa có phiếu xuất nào</div>
                <?php else: ?>
                    <table class="dash-table">
                        <thead>
                            <tr><th>Mã PX</th><th>Tổng tiền</th><th>Ngày</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_outbounds as $row): ?>
                            <tr>
                                <td><code><?= htmlspecialchars($row['ref_no']) ?></code></td>
                                <td><?= number_format($row['total_amount'] ?? 0, 0, ',', '.') ?>đ</td>
                                <td><?= date('d/m/Y H:i', strtotime($row['created'])) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

    </div>
</div>

<script src="<?= BASE_URL ?>/js/utils.js"></script>
<?php include __DIR__ . '/../../layout/footer.php'; ?>