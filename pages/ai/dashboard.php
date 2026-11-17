<?php
// pages/ai/dashboard.php
// Bố cục: cột trái = phân tích AI (tải bất đồng bộ), cột phải = khung chat dính theo màn hình.
// Chat luôn dùng được ngay, không phải kéo xuống cuối trang, kể cả khi phân tích đang tải / lỗi.
$page_title = 'AI PHÂN TÍCH';

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/db.php';
requireLogin();

$aiProcessUrl = BASE_URL . '/pages/ai/process.php';
$aiChatKey    = 'smartware_chat_v2_' . (int) ($_SESSION['user_id'] ?? 0);   // lịch sử chat tách theo người dùng

$aiSuggestions = [
    'Sản phẩm nào cần nhập hàng gấp nhất?',
    'Những lô nào sắp hết hạn?',
    'Sản phẩm nào đang tồn kho dư?',
    'Tóm tắt tình hình kho hôm nay',
];

include __DIR__ . '/../../layout/header.php';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/css/dashboard.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/ai.css">

<!-- Chống giật: gắn sẵn trạng thái ẩn/hiện khung chat trước khi vẽ -->
<script>
    (function () {
        try {
            if (localStorage.getItem('sw_ai_chat_hidden') === 'true') {
                document.documentElement.classList.add('ai-chat-hidden');
            }
        } catch (e) { /* localStorage bị chặn: dùng mặc định */ }
    })();
</script>

<div class="ai-page">

    <!-- ── Thanh công cụ (cùng kiểu với các trang danh sách) ── -->
    <div class="ai-toolbar">
        <div class="ai-toolbar-left">
            <span class="ai-badge"><i class="ri-robot-line"></i>SmartWare AI</span>
            <div class="ai-meta-chips" id="aiMeta"></div>
        </div>
        <div class="ai-toolbar-right">
            <button type="button" class="btn-ai-refresh" id="btnAiRefresh" title="Phân tích lại">
                <i class="ri-refresh-line"></i><span>Phân tích lại</span>
            </button>
            <button type="button" class="btn-tb-filter" id="btnToggleChat" aria-controls="aiChatPanel" aria-expanded="true">
                <i class="ri-chat-3-line"></i>
                <span id="txtToggleChat">Ẩn trợ lý</span>
            </button>
        </div>
    </div>

    <div class="ai-layout">

        <!-- ══ CỘT TRÁI: PHÂN TÍCH ══ -->
        <section class="ai-analysis" aria-label="Phân tích kho hàng">

            <!-- Trạng thái: đang tải / lỗi -->
            <div id="aiState" aria-live="polite">
                <div class="ai-loading" role="status">
                    <span class="ai-spinner"></span>
                    <span>Đang phân tích kho hàng bằng AI… lần đầu có thể mất vài chục giây. Bạn có thể hỏi AI ở khung bên cạnh trong lúc chờ.</span>
                </div>
                <div class="ai-kpi-grid" aria-hidden="true">
                    <div class="ai-skel ai-skel-kpi"></div><div class="ai-skel ai-skel-kpi"></div>
                    <div class="ai-skel ai-skel-kpi"></div><div class="ai-skel ai-skel-kpi"></div>
                    <div class="ai-skel ai-skel-kpi"></div>
                </div>
                <div class="ai-chart-row" aria-hidden="true">
                    <div class="ai-skel ai-skel-chart"></div><div class="ai-skel ai-skel-chart"></div>
                </div>
            </div>

            <!-- Nội dung (JS đổ dữ liệu vào) -->
            <div id="aiContent" hidden>
                <div class="ai-kpi-grid" id="aiKpis"></div>

                <div class="ai-overview" id="aiOverview" hidden>
                    <div class="ai-overview-label"><i class="ri-sparkling-line"></i>Nhận xét của AI</div>
                    <p class="ai-overview-text" id="aiOverviewText"></p>
                </div>

                <div class="ai-chart-row">
                    <div class="ai-chart-card">
                        <h3>Phân bố mức rủi ro</h3>
                        <div class="ai-chart-canvas-wrap"><canvas id="chartRisk"></canvas></div>
                        <div class="ai-chart-legend" id="legendRisk"></div>
                    </div>
                    <div class="ai-chart-card">
                        <h3>Mức độ ưu tiên nhập hàng</h3>
                        <div class="ai-chart-canvas-wrap"><canvas id="chartPriority"></canvas></div>
                        <div class="ai-chart-legend" id="legendPriority"></div>
                    </div>
                </div>

                <div id="aiTables"></div>
            </div>
        </section>

        <!-- ══ CỘT PHẢI: CHAT (dính theo màn hình, luôn hiển thị kể cả khi dữ liệu AI lỗi) ══ -->
        <aside class="ai-chat-panel" id="aiChatPanel" aria-label="Hỏi AI về kho hàng">
            <div class="ai-chat-header">
                <h2><i class="ri-chat-3-line"></i>Hỏi AI về kho hàng</h2>
                <div class="ai-chat-header-actions">
                    <button type="button" class="btn-ai-clear" id="btnChatClear">Xóa lịch sử</button>
                    <button type="button" class="btn-ai-close" id="btnChatClose" aria-label="Đóng khung chat">
                        <i class="ri-close-line"></i>
                    </button>
                </div>
            </div>

            <div class="ai-chat-messages" id="chatMessages" role="log" aria-live="polite"></div>

            <div class="ai-chat-suggest" id="chatSuggest">
                <?php foreach ($aiSuggestions as $q): ?>
                    <button type="button" class="ai-suggest-chip"><?= htmlspecialchars($q) ?></button>
                <?php endforeach; ?>
            </div>

            <div class="ai-chat-footer">
                <textarea id="chatInput" rows="1" maxlength="1000"
                          placeholder="Hỏi về tồn kho, dự báo, hạn dùng…  (Enter: gửi · Shift+Enter: xuống dòng)"
                          aria-label="Câu hỏi cho AI"></textarea>
                <button type="button" class="btn-ai-send" id="chatBtn" aria-label="Gửi">
                    <i class="ri-send-plane-fill"></i>
                </button>
            </div>
        </aside>
    </div>

    <!-- Nút nổi mở chat trên màn hình nhỏ -->
    <button type="button" class="ai-chat-fab" id="aiChatFab" aria-label="Mở chat AI">
        <i class="ri-chat-3-line"></i><span>Hỏi AI</span>
    </button>

</div><!-- /.ai-page -->

<script>
window.AI_PROCESS_URL = <?= json_encode($aiProcessUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
window.AI_CHAT_KEY    = <?= json_encode($aiChatKey,    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script src="<?= BASE_URL ?>/js/ai-dashboard.js"></script>

<?php include __DIR__ . '/../../layout/footer.php'; ?>
