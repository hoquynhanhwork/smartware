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
    <!-- Header Filter Bar (Day / Week / Month / Year & Date Range) -->
    <div class="dashboard-toolbar">
        <div class="filter-pills">
            <button type="button" class="pill-btn">Day</button>
            <button type="button" class="pill-btn">Week</button>
            <button type="button" class="pill-btn active">Month</button>
            <button type="button" class="pill-btn">Year</button>
        </div>
        <div class="date-range-badge">
            <i class="ri-calendar-line"></i>
            <span>01 Th10 2026 - 31 Th10 2026</span>
        </div>
    </div>

    <!-- 4 KPI Cards (Thẻ đầu tiên màu đen huyền bí như trong ảnh) -->
    <div class="kpi-grid">
        <!-- Card 1: Active Black Card -->
        <div class="kpi-card card-dark">
            <div class="kpi-title">Tổng giá trị tồn kho</div>
            <div class="kpi-value"><?= isset($total_inventory_value) ? number_format($total_inventory_value) . ' đ' : '239,020,000 đ' ?></div>
            <div class="kpi-trend trend-up">
                <i class="ri-arrow-right-up-line"></i> 4.2% so với tháng trước
            </div>
        </div>

        <!-- Card 2 -->
        <div class="kpi-card">
            <div class="kpi-title">Tổng số mặt hàng</div>
            <div class="kpi-value"><?= isset($total_products) ? number_format($total_products) : '16,815' ?></div>
            <div class="kpi-trend trend-up">
                <i class="ri-arrow-right-up-line"></i> 1.7% so với tháng trước
            </div>
        </div>

        <!-- Card 3 -->
        <div class="kpi-card">
            <div class="kpi-title">Đơn nhập kho tháng này</div>
            <div class="kpi-value"><?= isset($total_inbound) ? number_format($total_inbound) : '1,457' ?></div>
            <div class="kpi-trend trend-down">
                <i class="ri-arrow-right-down-line"></i> 2.9% so với tháng trước
            </div>
        </div>

        <!-- Card 4 -->
        <div class="kpi-card">
            <div class="kpi-title">Đơn xuất kho hoàn tất</div>
            <div class="kpi-value"><?= isset($total_outbound) ? number_format($total_outbound) : '2,023' ?></div>
            <div class="kpi-trend trend-up">
                <i class="ri-arrow-right-up-line"></i> 0.9% so với tháng trước
            </div>
        </div>
    </div>

    <!-- Main Section: Chart (Left) + Mini Calendar & Widget (Right) -->
    <div class="dashboard-middle-grid">
        <!-- Biểu đồ cột bo góc thanh mảnh -->
        <div class="content-card chart-card">
            <div class="card-header-clean">
                <h3>Thống kê biến động kho</h3>
                <button type="button" class="btn-circle-icon"><i class="ri-arrow-right-up-line"></i></button>
            </div>
            
            <div class="mock-bar-chart">
                <!-- Mô phỏng các cột bo tròn theo mẫu (Có thể dùng Chart.js hoặc CSS cột dưới) -->
                <div class="bar-col"><div class="bar-fill" style="height: 60%;"></div><span>Jan</span></div>
                <div class="bar-col"><div class="bar-fill" style="height: 48%;"></div><span>Feb</span></div>
                <div class="bar-col"><div class="bar-fill active-bar" style="height: 85%;"></div><span>Mar</span></div>
                <div class="bar-col"><div class="bar-fill" style="height: 52%;"></div><span>Apr</span></div>
                <div class="bar-col"><div class="bar-fill" style="height: 80%;"></div><span>May</span></div>
                <div class="bar-col"><div class="bar-fill" style="height: 35%;"></div><span>Jun</span></div>
            </div>
        </div>

        <!-- Widget bên phải: Lịch & Tỷ lệ hoàn thành -->
        <div class="side-widgets">
            <!-- Calendar Card -->
            <div class="content-card calendar-card">
                <div class="cal-header">
                    <button type="button" class="cal-nav"><i class="ri-arrow-left-s-line"></i></button>
                    <span>Tháng 10, 2026</span>
                    <button type="button" class="cal-nav"><i class="ri-arrow-right-s-line"></i></button>
                </div>
                <div class="cal-days-strip">
                    <div class="day-item"><span>Tue</span><b>17</b></div>
                    <div class="day-item"><span>Wed</span><b>18</b></div>
                    <div class="day-item active-day"><span>Thu</span><b>19</b></div>
                    <div class="day-item"><span>Fri</span><b>20</b></div>
                    <div class="day-item"><span>Sat</span><b>21</b></div>
                </div>
            </div>

            <!-- Tỉ lệ hoàn thành mục tiêu -->
            <div class="content-card progress-card">
                <div class="progress-left">
                    <div class="progress-title">Hiệu suất vận hành</div>
                    <div class="kpi-trend trend-up">
                        <i class="ri-arrow-right-up-line"></i> 0.9% từ tuần trước
                    </div>
                </div>
                <div class="progress-circle">
                    <span>85%</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Bảng Giao dịch gần nhất (Course Purchases -> Chuyển thành Đơn hàng gần nhất) -->
    <div class="content-card table-card">
        <div class="card-header-clean">
            <h3>Đơn hàng & Giao dịch mới nhất</h3>
            <div class="card-header-actions">
                <button type="button" class="btn-circle-icon"><i class="ri-refresh-line"></i></button>
                <button type="button" class="btn-circle-icon"><i class="ri-arrow-right-up-line"></i></button>
            </div>
        </div>

        <div class="table-responsive">
            <table class="clean-table">
                <thead>
                    <tr>
                        <th>Tên sản phẩm / Đơn hàng</th>
                        <th>Khách hàng / Đối tác</th>
                        <th>Mã đơn</th>
                        <th>Tổng tiền</th>
                        <th>Trạng thái</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- Lặp dữ liệu PHP thực tế của bạn tại đây -->
                    <?php if (!empty($recent_orders)): ?>
                        <?php foreach ($recent_orders as $order): ?>
                            <tr>
                                <td class="item-name-cell">
                                    <div class="item-thumb"><i class="ri-box-3-fill"></i></div>
                                    <span><?= htmlspecialchars($order['product_name'] ?? 'Mặt hàng kho') ?></span>
                                </td>
                                <td><?= htmlspecialchars($order['partner_name'] ?? 'Khách lẻ') ?></td>
                                <td><span class="mono-id">#<?= htmlspecialchars($order['code'] ?? '100293') ?></span></td>
                                <td class="amount-cell"><?= number_format($order['total'] ?? 0) ?> đ</td>
                                <td><span class="status-pill status-paid">Hoàn tất</span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <!-- Hàng mẫu demo trực quan -->
                        <tr>
                            <td class="item-name-cell">
                                <div class="item-thumb"><i class="ri-box-3-fill"></i></div>
                                <span>Thùng carton đóng gói tiêu chuẩn A1</span>
                            </td>
                            <td>Công ty logistics Aria</td>
                            <td><span class="mono-id">#3456791</span></td>
                            <td class="amount-cell">372,000 đ</td>
                            <td><span class="status-pill status-paid">Đã thanh toán</span></td>
                        </tr>
                        <tr>
                            <td class="item-name-cell">
                                <div class="item-thumb"><i class="ri-box-3-fill"></i></div>
                                <span>Pallet gỗ thông chịu tải 1.2 tấn</span>
                            </td>
                            <td>Tập đoàn Vận tải Viễn Đông</td>
                            <td><span class="mono-id">#3456792</span></td>
                            <td class="amount-cell">1,850,000 đ</td>
                            <td><span class="status-pill status-paid">Đã thanh toán</span></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="<?= BASE_URL ?>/js/utils.js"></script>
<?php include __DIR__ . '/../../layout/footer.php'; ?>