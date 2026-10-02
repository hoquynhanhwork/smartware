<?php
// pages/ai/dashboard.php
$page_title = 'AI PHÂN TÍCH';

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/db.php';
requireLogin();

$currentUser = currentUser();

$AI_BASE  = getenv('AI_SERVICE_URL') ?: 'http://localhost:8000/api/v1';
$AI_KEY   = getenv('AI_API_KEY') ?: 'smartware';


function callAI(string $endpoint, array $payload, string $base, string $key): array {
    $ch = curl_init("$base/$endpoint");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json; charset=utf-8',
            "X-API-Key: $key",
        ],
        CURLOPT_TIMEOUT        => 120,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $code >= 500) {
        return ['error' => "HTTP $code: " . ($body ?: 'No response')];
    }
    $data = json_decode($body, true);
    return $data ?? ['error' => 'Invalid JSON'];
}

$batchResult  = callAI('analyze/batch', [
    'limit'      => 500,
], $AI_BASE, $AI_KEY);

$hasError    = isset($batchResult['error']);
$summary     = $batchResult['summary']  ?? [];
$products    = $batchResult['results']  ?? [];
$overviewTxt = $summary['overview_text'] ?? '';

$byRisk = ['high' => [], 'medium' => [], 'low' => []];
foreach ($products as $p) {
    $byRisk[$p['risk_level'] ?? 'low'][] = $p;
}

$riskCounts     = $summary['risk_counts']    ?? ['high' => 0, 'medium' => 0, 'low' => 0];
$priorityCounts = $summary['priority_counts'] ?? [];
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>AI Dashboard — SmartWare</title>
    <link rel="stylesheet" href="../../css/dashboard.css">
    <link rel="stylesheet" href="../../css/ai.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/remixicon@4.2.0/fonts/remixicon.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
</head>
<body>
<?php include __DIR__ . '/../../layout/header.php'; ?>

<div class="main-content">

    <!-- ── Top bar ── -->
    <div class="ai-top-bar">
        <div class="ai-top-bar-left">
            <h1>AI phân tích</h1>
            <span class="ai-badge"><i class="ri-robot-line"></i>SmartWare AI</span>
        </div>
        <?php if (!$hasError): ?>
        <div class="ai-meta-chips">
            <span class="ai-chip">
                <?= $batchResult['analyzed'] ?? 0 ?>/<?= $batchResult['total_products'] ?? 0 ?> sản phẩm
            </span>
            <span class="ai-chip"><?= $batchResult['tokens_used'] ?? 0 ?> tokens</span>
            <?php if ($batchResult['cached'] ?? false): ?>
                <span class="ai-chip cached"><i class="ri-flashlight-line"></i> cached</span>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($hasError): ?>

    <!-- ── Error ── -->
    <div class="ai-error-box">
        <strong>⚠ Không kết nối được AI Service.</strong><br>
        <?= htmlspecialchars($batchResult['error']) ?><br>
        <small>Kiểm tra Docker container đang chạy tại <code><?= $AI_BASE ?></code></small>
    </div>

    <?php else: ?>

    <!-- ── KPI Grid ── -->
    <div class="ai-kpi-grid">
        <div class="ai-kpi red">
            <div class="ai-kpi-label"><i class="ri-alert-line"></i>Rủi ro cao</div>
            <div class="ai-kpi-value"><?= $riskCounts['high'] ?? 0 ?></div>
        </div>
        <div class="ai-kpi amber">
            <div class="ai-kpi-label"><i class="ri-error-warning-line"></i>Rủi ro trung bình</div>
            <div class="ai-kpi-value"><?= $riskCounts['medium'] ?? 0 ?></div>
        </div>
        <div class="ai-kpi green">
            <div class="ai-kpi-label"><i class="ri-checkbox-circle-line"></i>Ổn định</div>
            <div class="ai-kpi-value"><?= $riskCounts['low'] ?? 0 ?></div>
        </div>
        <div class="ai-kpi purple">
            <div class="ai-kpi-label"><i class="ri-truck-line"></i>Cần nhập gấp</div>
            <div class="ai-kpi-value"><?= $priorityCounts['critical'] ?? 0 ?></div>
        </div>
        <div class="ai-kpi blue">
            <div class="ai-kpi-label"><i class="ri-box-3-line"></i>Tổng sản phẩm</div>
            <div class="ai-kpi-value"><?= $batchResult['total_products'] ?? 0 ?></div>
        </div>
    </div>

    <!-- ── AI Overview ── -->
    <?php if ($overviewTxt): ?>
    <div class="ai-overview">
        <div class="ai-overview-label"><i class="ri-sparkling-line"></i>Nhận xét của AI</div>
        <p class="ai-overview-text"><?= nl2br(htmlspecialchars($overviewTxt)) ?></p>
    </div>
    <?php endif; ?>

    <!-- ── Charts ── -->
    <div class="ai-chart-row">
        <div class="ai-chart-card">
            <h3>Phân bố mức rủi ro</h3>
            <div class="ai-chart-canvas-wrap">
                <canvas id="chartRisk"></canvas>
            </div>
            <div class="ai-chart-legend">
                <span><span class="swatch" style="background:#dc2626"></span>Rủi ro cao</span>
                <span><span class="swatch" style="background:#d97706"></span>Trung bình</span>
                <span><span class="swatch" style="background:#16a34a"></span>Ổn định</span>
            </div>
        </div>
        <div class="ai-chart-card">
            <h3>Mức độ ưu tiên nhập hàng</h3>
            <div class="ai-chart-canvas-wrap">
                <canvas id="chartPriority"></canvas>
            </div>
            <div class="ai-chart-legend">
                <span><span class="swatch" style="background:#9d174d"></span>Critical</span>
                <span><span class="swatch" style="background:#b91c1c"></span>High</span>
                <span><span class="swatch" style="background:#92400e"></span>Medium</span>
                <span><span class="swatch" style="background:#6b7280"></span>Low</span>
            </div>
        </div>
    </div>

    <!-- ── Risk Tables ── -->
    <?php foreach (['high' => ['Rủi ro cao', '#dc2626'], 'medium' => ['Rủi ro trung bình', '#d97706']] as $level => [$label, $color]): ?>
    <?php if (!empty($byRisk[$level])): ?>

    <div class="ai-section-hd">
        <span class="ai-dot" style="background:<?= $color ?>"></span>
        <h2><?= $label ?></h2>
        <span class="ai-count">(<?= count($byRisk[$level]) ?> sản phẩm)</span>
    </div>

    <div class="ai-table-wrap">
        <table class="ai-table">
            <thead>
                <tr>
                    <th style="width:22%">Sản phẩm</th>
                    <th style="width:13%">Trạng thái</th>
                    <th style="width:16%">Điểm rủi ro</th>
                    <th style="width:10%">Ưu tiên</th>
                    <th style="width:11%">Cần nhập</th>
                    <th style="width:20%">Cảnh báo</th>
                    <th style="width:8%"></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($byRisk[$level] as $p): ?>
            <?php
                $statusMap = [
                    'OUT_OF_STOCK' => ['Hết hàng', '#dc2626'],
                    'LOW_STOCK'    => ['Sắp hết',  '#d97706'],
                    'NEAR_EXPIRY'  => ['Sắp HSD',  '#7c3aed'],
                    'OVERSTOCK'    => ['Dư hàng',   '#0284c7'],
                    'NORMAL'       => ['Bình thường','#16a34a'],
                ];
                $st       = $statusMap[$p['stock_status'] ?? 'NORMAL'] ?? ['?', '#6b7280'];
                $barColor = $level === 'high' ? '#dc2626' : '#d97706';
                $prio     = htmlspecialchars($p['priority'] ?? 'low');
            ?>
            <tr>
                <td><strong><?= htmlspecialchars($p['product_name']) ?></strong></td>
                <td>
                    <span class="ai-status" style="color:<?= $st[1] ?>">
                        <span class="ai-dot" style="background:<?= $st[1] ?>"></span>
                        <?= $st[0] ?>
                    </span>
                </td>
                <td>
                    <span style="font-weight:600"><?= number_format($p['risk_score'], 0) ?></span>
                    <span class="ai-risk-bar-bg">
                        <span class="ai-risk-bar-fill"
                              style="width:<?= min(100, $p['risk_score']) ?>%;background:<?= $barColor ?>">
                        </span>
                    </span>
                </td>
                <td><span class="ai-badge-priority <?= $prio ?>"><?= strtoupper($prio) ?></span></td>
                <td>
                    <?= $p['suggested_quantity'] > 0
                        ? '<strong>' . $p['suggested_quantity'] . '</strong> đvt'
                        : '—' ?>
                </td>
                <td style="color:#6b7280;font-size:12px" class="col-warning"><?= htmlspecialchars($p['warning'] ?? '') ?></td>
                <td class="col-action">
                    <button class="btn-ai-detail"
                        onclick="analyzeProduct(<?= $p['product_id'] ?>, '<?= htmlspecialchars(addslashes($p['product_name'])) ?>')">
                        Chi tiết
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php endif; ?>
    <?php endforeach; ?>

    <!-- ── Chat Panel ── -->
    <div class="ai-chat-panel">
        <div class="ai-chat-header">
            <h2><i class="ri-chat-3-line"></i>Hỏi AI về kho hàng</h2>
            <button class="btn-ai-clear" onclick="clearChatHistory()">Xóa lịch sử</button>
        </div>
        <div class="ai-chat-messages" id="chatMessages">
            <div class="chat-msg-ai">Xin chào! Tôi có thể giúp bạn phân tích tồn kho, dự báo nhu cầu, hoặc trả lời bất kỳ câu hỏi nào về kho hàng.</div>
        </div>
        <div class="ai-chat-footer">
            <input type="text" id="chatInput"
                   placeholder="Ví dụ: Sản phẩm nào cần nhập hàng gấp nhất?"
                   onkeydown="if(event.key==='Enter') sendChat()">
            <button class="btn-ai-send" id="chatBtn" onclick="sendChat()">
                <i class="ri-send-plane-line"></i> Gửi
            </button>
        </div>
    </div>

    <?php endif; ?>
</div><!-- /.main-content -->

<?php include __DIR__ . '/../../layout/footer.php'; ?>

<script>
const riskCounts     = <?= json_encode($riskCounts,     JSON_UNESCAPED_UNICODE) ?>;
const priorityCounts = <?= json_encode($priorityCounts, JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="../../js/ai-dashboard.js"></script>
</body>
</html>