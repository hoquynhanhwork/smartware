<?php
// pages/ai/dashboard.php
// Khung trang hiển thị ngay; dữ liệu AI được js/ai-dashboard.js tải bất đồng bộ (có loading / lỗi / thử lại).
$page_title = 'AI PHÂN TÍCH';

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/db.php';
requireLogin();

$aiProcessUrl = BASE_URL . '/pages/ai/process.php';
$aiChatKey    = 'smartware_chat_' . (int) ($_SESSION['user_id'] ?? 0);   // lịch sử chat tách theo người dùng

include __DIR__ . '/../../layout/header.php';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/css/dashboard.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/ai.css">

<div class="ai-page">

    <!-- ── Top bar ── -->
    <div class="ai-top-bar">
        <div class="ai-top-bar-left">
            <span class="ai-badge"><i class="ri-robot-line"></i>SmartWare AI</span>
        </div>
        <div class="ai-meta-chips" id="aiMeta"></div>
    </div>

    <!-- ── Trạng thái: đang tải / lỗi ── -->
    <div id="aiState" aria-live="polite">
        <div class="ai-loading" role="status">
            <span class="ai-spinner"></span>
            <span>Đang phân tích kho hàng bằng AI… lần đầu có thể mất vài chục giây.</span>
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

    <!-- ── Nội dung (JS đổ dữ liệu vào) ── -->
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

    <!-- ── Chat Panel (luôn hiển thị, kể cả khi dữ liệu AI lỗi) ── -->
    <div class="ai-chat-panel">
        <div class="ai-chat-header">
            <h2><i class="ri-chat-3-line"></i>Hỏi AI về kho hàng</h2>
            <button type="button" class="btn-ai-clear" onclick="clearChatHistory()">Xóa lịch sử</button>
        </div>
        <div class="ai-chat-messages" id="chatMessages">
            <div class="chat-msg-ai">Xin chào! Tôi có thể giúp bạn phân tích tồn kho, dự báo nhu cầu, hoặc trả lời bất kỳ câu hỏi nào về kho hàng.</div>
        </div>
        <div class="ai-chat-footer">
            <input type="text" id="chatInput"
                   placeholder="Ví dụ: Sản phẩm nào cần nhập hàng gấp nhất?"
                   onkeydown="if(event.key==='Enter') sendChat()">
            <button type="button" class="btn-ai-send" id="chatBtn" onclick="sendChat()">
                <i class="ri-send-plane-line"></i> Gửi
            </button>
        </div>
    </div>

</div><!-- /.ai-page -->

<script>
window.AI_PROCESS_URL = <?= json_encode($aiProcessUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
window.AI_CHAT_KEY    = <?= json_encode($aiChatKey,    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script src="<?= BASE_URL ?>/js/ai-dashboard.js"></script>

<?php include __DIR__ . '/../../layout/footer.php'; ?>
