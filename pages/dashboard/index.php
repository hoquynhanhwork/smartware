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
<!-- Font Be Vietnam Pro -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:ital,wght@0,300;0,400;0,500;0,600;0,700;1,400&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/dashboard.css">

<div class="dashboard-container">
    <!-- Header Filter Bar (Day / Week / Month / Year & Date Range) -->
    <div class="dashboard-toolbar">
        <div class="filter-pills">
            <button type="button" class="pill-btn">Ngày</button>
            <button type="button" class="pill-btn">Tuần</button>
            <button type="button" class="pill-btn active">Tháng</button>
            <button type="button" class="pill-btn">Năm</button>
        </div>
        <div class="date-range-badge">
            <i class="ri-calendar-line"></i>
            <span>01 Th10 2026 - 31 Th10 2026</span>
        </div>
    </div>

    <!-- 4 KPI Cards: Card đầu tiên mang sắc Navy tối sang trọng, viền trên vàng -->
    <div class="kpi-grid">
        <!-- Card 1: Active Navy Card -->
        <div class="kpi-card card-dark">
            <div class="kpi-title">Tổng giá trị tồn kho</div>
            <div class="kpi-value"><?= isset($stats['inventory_value']) ? number_format($stats['inventory_value']) . ' ₫' : '239,020,000 ₫' ?></div>
            <div class="kpi-trend trend-up">
                <i class="ri-arrow-right-up-line"></i> +4.2% so với tháng trước
            </div>
        </div>

        <!-- Card 2 -->
        <div class="kpi-card">
            <div class="kpi-title">Tổng số mặt hàng</div>
            <div class="kpi-value"><?= isset($kpis['total_products']) ? number_format($kpis['total_products']) : '16,815' ?></div>
            <div class="kpi-trend trend-up">
                <i class="ri-arrow-right-up-line"></i> +1.7% so với tháng trước
            </div>
        </div>

        <!-- Card 3 -->
        <div class="kpi-card">
            <div class="kpi-title">Đơn nhập kho tháng này</div>
            <div class="kpi-value"><?= isset($kpis['inbounds_this_month']) ? number_format($kpis['inbounds_this_month']) : '1,457' ?></div>
            <div class="kpi-trend trend-down">
                <i class="ri-arrow-right-down-line"></i> -2.9% so với tháng trước
            </div>
        </div>

        <!-- Card 4 -->
        <div class="kpi-card">
            <div class="kpi-title">Đơn xuất kho hoàn tất</div>
            <div class="kpi-value"><?= isset($kpis['outbounds_this_month']) ? number_format($kpis['outbounds_this_month']) : '2,023' ?></div>
            <div class="kpi-trend trend-up">
                <i class="ri-arrow-right-up-line"></i> +0.9% so với tháng trước
            </div>
        </div>
    </div>

    <!-- Main Section: Biểu đồ (Trái) + Mini Calendar & Widget (Phải) -->
    <div class="dashboard-middle-grid">
        <!-- Biểu đồ cột bo tròn -->
        <div class="content-card chart-card">
            <div class="card-header-clean">
                <h3>Thống kê biến động nhập kho</h3>
                <button type="button" class="btn-circle-icon" title="Xem chi tiết"><i class="ri-arrow-right-up-line"></i></button>
            </div>
            
            <div class="mock-bar-chart">
                <div class="bar-col"><div class="bar-fill" style="height: 60%;"></div><span>Th1</span></div>
                <div class="bar-col"><div class="bar-fill" style="height: 48%;"></div><span>Th2</span></div>
                <div class="bar-col"><div class="bar-fill" style="height: 70%;"></div><span>Th3</span></div>
                <div class="bar-col"><div class="bar-fill" style="height: 52%;"></div><span>Th4</span></div>
                <div class="bar-col"><div class="bar-fill active-bar" style="height: 85%;"></div><span>Th5</span></div>
                <div class="bar-col"><div class="bar-fill" style="height: 42%;"></div><span>Th6</span></div>
            </div>
        </div>

        <!-- Widget bên phải: Lịch & Hiệu suất -->
        <div class="side-widgets">
            <!-- Calendar Card -->
            <div class="content-card calendar-card">
                <div class="cal-header">
                    <button type="button" class="cal-nav"><i class="ri-arrow-left-s-line"></i></button>
                    <span>Tháng 10, 2026</span>
                    <button type="button" class="cal-nav"><i class="ri-arrow-right-s-line"></i></button>
                </div>
                <div class="cal-days-strip">
                    <div class="day-item"><span>T3</span><b>17</b></div>
                    <div class="day-item"><span>T4</span><b>18</b></div>
                    <div class="day-item active-day"><span>T5</span><b>19</b></div>
                    <div class="day-item"><span>T6</span><b>20</b></div>
                    <div class="day-item"><span>T7</span><b>21</b></div>
                </div>
            </div>

            <!-- Tỉ lệ hoàn thành mục tiêu -->
            <div class="content-card progress-card">
                <div class="progress-left">
                    <div class="progress-title">Hiệu suất luân chuyển</div>
                    <div class="kpi-trend trend-up">
                        <i class="ri-arrow-right-up-line"></i> +0.9% từ tuần trước
                    </div>
                </div>
                <div class="progress-circle">
                    <span>85%</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Bảng Giao dịch gần nhất (table-layout fixed, canh lề chuẩn) -->
    <div class="content-card table-card">
        <div class="card-header-clean">
            <h3>Đơn hàng & Giao dịch mới nhất</h3>
            <div class="card-header-actions">
                <button type="button" class="btn-circle-icon" onclick="window.location.reload()" title="Làm mới"><i class="ri-refresh-line"></i></button>
                <a href="../inbound/index.php" class="btn-circle-icon" title="Xem tất cả"><i class="ri-arrow-right-up-line"></i></a>
            </div>
        </div>

        <div class="table-responsive">
            <table class="clean-table">
                <thead>
                    <tr>
                        <th style="width: 320px;">Tên sản phẩm / Đơn hàng</th>
                        <th>Khách hàng / Đối tác</th>
                        <th style="width: 140px;">Mã đơn</th>
                        <th style="width: 160px;">Tổng tiền</th>
                        <th style="width: 130px;">Trạng thái</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($recent_inbounds)): ?>
                        <?php foreach (array_slice($recent_inbounds, 0, 5) as $order): ?>
                            <tr>
                                <td class="item-name-cell">
                                    <div class="item-thumb"><i class="ri-box-3-fill"></i></div>
                                    <span title="<?= htmlspecialchars($order['note'] ?: 'Đơn nhập hàng kho') ?>">
                                        <?= htmlspecialchars($order['note'] ?: 'Đơn nhập hàng kho') ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($order['supplier_name'] ?? 'Nhà cung cấp đối tác') ?></td>
                                <td><span class="mono-id">#<?= htmlspecialchars($order['ref_no'] ?? 'INB-00') ?></span></td>
                                <td class="amount-cell"><?= number_format($order['total_amount'] ?? 0) ?> ₫</td>
                                <td><span class="status-pill status-paid">Hoàn thành</span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td class="item-name-cell">
                                <div class="item-thumb"><i class="ri-box-3-fill"></i></div>
                                <span>Thùng carton đóng gói tiêu chuẩn A1</span>
                            </td>
                            <td>Công ty logistics Aria</td>
                            <td><span class="mono-id">#3456791</span></td>
                            <td class="amount-cell">372,000 ₫</td>
                            <td><span class="status-pill status-paid">Hoàn tất</span></td>
                        </tr>
                        <tr>
                            <td class="item-name-cell">
                                <div class="item-thumb"><i class="ri-box-3-fill"></i></div>
                                <span>Pallet gỗ thông chịu tải 1.2 tấn</span>
                            </td>
                            <td>Tập đoàn Vận tải Viễn Đông</td>
                            <td><span class="mono-id">#3456792</span></td>
                            <td class="amount-cell">1,850,000 ₫</td>
                            <td><span class="status-pill status-paid">Hoàn tất</span></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="<?= BASE_URL ?>/js/utils.js"></script>
<?php include __DIR__ . '/../../layout/footer.php'; ?>