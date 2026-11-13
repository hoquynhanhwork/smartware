<?php
// pages/settings/users.php
$page_title = 'CÀI ĐẶT HỆ THỐNG';

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use App\Repositories\SettingRepository;
use App\Services\SettingService;
use App\Services\CsrfService;

requireLogin();
requireRole('admin', 'manager');

$current_uid = (int) $_SESSION['user_id'];
$service     = new SettingService(new SettingRepository($pdo));
$csrf        = new CsrfService();

$users         = $service->listUsers();
$csrfToken     = $csrf->getToken();
$flash_success = getFlash('success');
$flash_error   = getFlash('error');

// Thống kê nhanh cho KPI cards
$totalMembers   = count($users);
$totalAdmins    = count(array_filter($users, fn($u) => $u['role'] === 'admin'));
$activeMembers  = count(array_filter($users, fn($u) => $u['status'] === 'active'));
$inactiveCount  = $totalMembers - $activeMembers;

$roleMap = ['admin' => 'Quản trị viên', 'manager' => 'Quản lý', 'staff' => 'Nhân viên'];

$body_page = 'settings-page';
include __DIR__ . '/../../layout/header.php';
?>
<!-- Font Be Vietnam Pro & Style Chuẩn -->
<link rel="stylesheet" href="<?= BASE_URL ?>/css/suppliers.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/inbound.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/settings.css">
<meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>">

<div class="settings-container-modern">
    <!-- Nhúng thanh tab ngang -->
    <?php include '_tabs.php'; ?>

    <div class="settings-tab-content">
        <?php if ($flash_success): ?><div class="alert alert-success"><?= htmlspecialchars($flash_success) ?></div><?php endif; ?>
        <?php if ($flash_error):   ?><div class="alert alert-danger"><?= htmlspecialchars($flash_error) ?></div><?php endif; ?>

        <!-- SECTION: THỐNG KÊ TỔNG QUAN THÀNH VIÊN (KPI CARDS) -->
        <div class="settings-sub-section">
            <div class="section-title-wrap">
                <i class="ri-team-line"></i>
                <h3 class="section-heading">Tổng quan nhân sự</h3>
            </div>

            <div class="team-kpi-grid">
                <!-- Card 1 -->
                <div class="team-kpi-card">
                    <div class="kpi-top">
                        <span class="kpi-lbl">Tổng thành viên</span>
                        <button type="button" class="btn-more-dots"><i class="ri-more-2-fill"></i></button>
                    </div>
                    <div class="kpi-main-val"><?= sprintf("%02d", $totalMembers) ?> tài khoản</div>
                    <div class="kpi-sub-text">Người dùng trong hệ thống</div>
                    <div class="kpi-trend trend-up">
                        <i class="ri-flashlight-line"></i>
                        <span>12% so với tháng trước</span>
                    </div>
                </div>

                <!-- Card 2 -->
                <div class="team-kpi-card">
                    <div class="kpi-top">
                        <span class="kpi-lbl">Quản trị viên</span>
                        <button type="button" class="btn-more-dots"><i class="ri-more-2-fill"></i></button>
                    </div>
                    <div class="kpi-main-val"><?= sprintf("%02d", $totalAdmins) ?> quản trị</div>
                    <div class="kpi-sub-text">Toàn quyền vận hành</div>
                    <div class="kpi-trend trend-up">
                        <i class="ri-checkbox-circle-line"></i>
                        <span>Ổn định tuần này</span>
                    </div>
                </div>

                <!-- Card 3 -->
                <div class="team-kpi-card">
                    <div class="kpi-top">
                        <span class="kpi-lbl">Đang hoạt động</span>
                        <button type="button" class="btn-more-dots"><i class="ri-more-2-fill"></i></button>
                    </div>
                    <div class="kpi-main-val"><?= sprintf("%02d", $activeMembers) ?> thành viên</div>
                    <div class="kpi-sub-text">Sẵn sàng nhận tác vụ</div>
                    <div class="kpi-trend trend-up">
                        <i class="ri-time-line"></i>
                        <span>Đã xác thực</span>
                    </div>
                </div>

                <!-- Card 4 -->
                <div class="team-kpi-card">
                    <div class="kpi-top">
                        <span class="kpi-lbl">Bị khóa / Tạm dừng</span>
                        <button type="button" class="btn-more-dots"><i class="ri-more-2-fill"></i></button>
                    </div>
                    <div class="kpi-main-val" style="color: var(--status-danger);"><?= sprintf("%02d", $inactiveCount) ?> tài khoản</div>
                    <div class="kpi-sub-text">Chưa thể đăng nhập</div>
                    <div class="kpi-trend trend-down">
                        <i class="ri-indeterminate-circle-line"></i>
                        <span>Cần kiểm duyệt</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- SECTION: DANH SÁCH THÀNH VIÊN & THANH CÔNG CỤ -->
        <div class="settings-sub-section mt-30">
            <div class="members-toolbar-header">
                <div class="section-title-wrap">
                    <i class="ri-user-shared-line"></i>
                    <h3 class="section-heading">Danh sách thành viên <span class="counter-badge">(<?= $totalMembers ?>)</span></h3>
                </div>

                <div class="members-action-toolbar">
                    <div class="st-search-animated-box">
                        <i class="ri-search-line"></i>
                        <input type="text" id="settingsSearchInput" placeholder="Tìm theo tên, email..." autocomplete="off">
                        <span class="st-shortcut-badge">⌘ F</span>
                    </div>

                    <div class="st-dropdown-tool">
                        <button type="button" class="btn-tool-outline" id="btnStatusFilter">
                            <span>Trạng thái: <strong id="currentStatusText">Tất cả</strong></span>
                            <i class="ri-arrow-down-s-line"></i>
                        </button>
                        <div class="st-dropdown-menu" id="statusFilterMenu">
                            <a href="javascript:void(0)" onclick="filterMemberStatus('all')" class="active">Tất cả</a>
                            <a href="javascript:void(0)" onclick="filterMemberStatus('active')">Đang hoạt động</a>
                            <a href="javascript:void(0)" onclick="filterMemberStatus('inactive')">Bị khóa</a>
                        </div>
                    </div>

                    <button type="button" class="btn-tool-outline" onclick="toggleSortMembers()" title="Sắp xếp danh sách">
                        <i class="ri-sort-desc"></i>
                        <span>Sắp xếp</span>
                    </button>

                    <?php if (hasRole('admin')): ?>
                    <button type="button" class="btn-modern-dark btn-sm" onclick="openUserModal('add')">
                        <i class="ri-user-add-line"></i>
                        <span>Thêm nhân viên</span>
                    </button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- BẢNG THÀNH VIÊN LIỀN KHỐI (THEAD NAVY CHỮ KEM) -->
            <div class="clean-member-card-table">
                <table class="member-table-grid" id="memberTable">
                    <thead>
                        <tr>
                            <th style="width: 32%;">TÊN & THÔNG TIN</th>
                            <th style="width: 25%;">CÔNG VIỆC & VAI TRÒ</th>
                            <th style="width: 18%;">NGÀY THAM GIA</th>
                            <th style="width: 15%;">HOẠT ĐỘNG</th>
                            <th style="width: 10%; text-align: right;">THAO TÁC</th>
                        </tr>
                    </thead>
                    <tbody id="memberListStack">
                        <?php foreach ($users as $u):
                            $isSelf = ($u['id'] === $current_uid);
                            $nameInitial = mb_substr($u['full_name'] ?? $u['username'], 0, 1, 'UTF-8');
                            $createdDate = date('d/m/Y', strtotime($u['created_at'] ?? '2026-01-10'));
                            $loginText = $u['last_login_at'] ? date('H:i, d/m/Y', strtotime($u['last_login_at'])) : 'Đang hoạt động';
                        ?>
                        <tr class="member-table-row" data-name="<?= strtolower(htmlspecialchars($u['full_name'] ?? '')) ?>" data-status="<?= $u['status'] ?>">
                            <!-- Cột 1: Profile -->
                            <td>
                                <div class="cell-member-profile">
                                    <div class="member-avatar">
                                        <span class="avatar-initial"><?= strtoupper($nameInitial) ?></span>
                                    </div>
                                    <div class="member-info">
                                        <div class="member-name">
                                            <strong><?= htmlspecialchars($u['full_name'] ?? $u['username']) ?></strong>
                                            <?php if ($isSelf): ?>
                                                <span class="self-tag">(Bạn)</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="member-email"><?= htmlspecialchars($u['email'] ?? $u['username'] . '@smartware.vn') ?></div>
                                    </div>
                                </div>
                            </td>

                            <!-- Cột 2: Tasks & Role -->
                            <td>
                                <div class="cell-member-tasks">
                                    <div class="task-line">
                                        <i class="ri-checkbox-line"></i>
                                        <span>Phiếu hoàn tất: <strong><?= rand(10, 85) ?></strong></span>
                                    </div>
                                    <div class="role-line">
                                        Vai trò: <strong><?= $roleMap[$u['role']] ?? ucfirst(htmlspecialchars($u['role'])) ?></strong>
                                    </div>
                                </div>
                            </td>

                            <!-- Cột 3: Created Date -->
                            <td>
                                <div class="cell-member-date">
                                    <i class="ri-calendar-line"></i>
                                    <span><?= $createdDate ?></span>
                                </div>
                            </td>

                            <!-- Cột 4: Status / Activity -->
                            <td>
                                <div class="cell-member-activity">
                                    <i class="ri-time-line"></i>
                                    <span class="activity-text <?= $u['status'] === 'active' ? 'text-success' : 'text-danger' ?>">
                                        <?= $u['status'] === 'active' ? $loginText : 'Đã khóa' ?>
                                    </span>
                                </div>
                            </td>

                            <!-- Cột 5: Actions -->
                            <td style="text-align: right;">
                                <div class="cell-member-actions">
                                    <button type="button" class="btn-icon-subtle" title="Sửa thông tin"
                                            onclick="openUserModal('edit', <?= htmlspecialchars(json_encode($u), ENT_QUOTES) ?>)">
                                        <i class="ri-pencil-line"></i>
                                    </button>
                                    <button type="button" class="btn-icon-subtle" title="Đổi mật khẩu"
                                            onclick="openResetModal(<?= $u['id'] ?>, '<?= htmlspecialchars($u['username'], ENT_QUOTES) ?>')">
                                        <i class="ri-key-line"></i>
                                    </button>
                                    <?php if (!$isSelf && hasRole('admin')): ?>
                                    <button type="button" class="btn-icon-subtle <?= $u['status']==='active' ? 'text-danger' : 'text-success' ?>"
                                            title="<?= $u['status']==='active' ? 'Khóa' : 'Mở khóa' ?>"
                                            onclick="toggleStatus(<?= $u['id'] ?>, '<?= $u['username'] ?>', '<?= $u['status'] ?>')">
                                        <i class="ri-<?= $u['status']==='active' ? 'lock' : 'lock-unlock' ?>-line"></i>
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Modal thêm/sửa user -->
<div id="userModal" class="modal-modern" style="display:none">
    <div class="modal-modern-dialog" style="max-width:560px">
        <div class="modal-modern-header">
            <div>
                <h3 id="userModalTitle">Thêm nhân viên</h3>
                <p class="modal-subtitle">Tạo tài khoản quản lý và vận hành kho SmartWare.</p>
            </div>
            <button type="button" class="btn-close-modern" onclick="closeUserModal()">&times;</button>
        </div>
        <form id="userForm" onsubmit="submitUser(event)">
            <input type="hidden" name="action" id="userAction" value="add_user">
            <input type="hidden" name="id"     id="userId"     value="0">
            <div class="modal-tabs-body">
                <div class="form-grid-2">
                    <div class="form-row-modern" id="usernameGroup">
                        <label class="form-label-modern">Tên đăng nhập <span class="text-danger">*</span></label>
                        <input type="text" name="username" id="uUsername" class="form-input-modern" autocomplete="off" placeholder="VD: nguyenvan_a">
                    </div>
                    <div class="form-row-modern" id="passwordGroup">
                        <label class="form-label-modern">Mật khẩu <span class="text-danger">*</span></label>
                        <input type="password" name="password" id="uPassword" class="form-input-modern" autocomplete="new-password">
                    </div>
                    <div class="form-row-modern">
                        <label class="form-label-modern">Họ và tên <span class="text-danger">*</span></label>
                        <input type="text" name="full_name" id="uFullName" class="form-input-modern" placeholder="VD: Nguyễn Văn A">
                    </div>
                    <div class="form-row-modern">
                        <label class="form-label-modern">Email</label>
                        <input type="email" name="email" id="uEmail" class="form-input-modern" placeholder="a.nguyen@smartware.vn">
                    </div>

                    <!-- Vai trò dạng Radio button Card -->
                    <div class="form-row-modern" style="grid-column: 1 / -1;">
                        <label class="form-label-modern">Vai trò đảm nhiệm <span class="text-danger">*</span></label>
                        <div class="role-radio-group">
                            <label class="role-radio-card">
                                <input type="radio" name="role" value="staff" checked>
                                <span class="custom-radio-circle"></span>
                                <div class="role-radio-text">
                                    <strong>Nhân viên kho</strong>
                                    <small>Thao tác nhập, xuất & xem tồn</small>
                                </div>
                            </label>

                            <label class="role-radio-card">
                                <input type="radio" name="role" value="manager">
                                <span class="custom-radio-circle"></span>
                                <div class="role-radio-text">
                                    <strong>Quản lý kho</strong>
                                    <small>Duyệt phiếu, kiểm kê & điều chỉnh</small>
                                </div>
                            </label>

                            <label class="role-radio-card">
                                <input type="radio" name="role" value="admin">
                                <span class="custom-radio-circle"></span>
                                <div class="role-radio-text">
                                    <strong>Quản trị viên</strong>
                                    <small>Toàn quyền hệ thống & cấu hình</small>
                                </div>
                            </label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-modern-footer">
                <button type="button" class="btn-modern-outline" onclick="closeUserModal()">Hủy</button>
                <button type="submit" class="btn-modern-dark">Lưu nhân viên</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Reset Password -->
<div id="resetModal" class="modal-modern" style="display:none">
    <div class="modal-modern-dialog" style="max-width:420px">
        <div class="modal-modern-header">
            <div>
                <h3>Đặt lại mật khẩu</h3>
                <p class="modal-subtitle" id="resetUserLabel"></p>
            </div>
            <button type="button" class="btn-close-modern" onclick="closeResetModal()">&times;</button>
        </div>
        <form id="resetForm" onsubmit="submitReset(event)">
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="id"     id="resetUserId">
            <div class="modal-tabs-body">
                <div class="form-row-modern">
                    <label class="form-label-modern">Mật khẩu mới <span class="text-danger">*</span></label>
                    <input type="password" name="new_password" id="newPassword" class="form-input-modern" minlength="6" autocomplete="new-password">
                </div>
            </div>
            <div class="modal-modern-footer">
                <button type="button" class="btn-modern-outline" onclick="closeResetModal()">Hủy</button>
                <button type="submit" class="btn-modern-dark">Xác nhận</button>
            </div>
        </form>
    </div>
</div>

<script src="<?= BASE_URL ?>/js/utils.js"></script>
<script src="<?= BASE_URL ?>/js/settings.js"></script>

<?php include __DIR__ . '/../../layout/footer.php'; ?>