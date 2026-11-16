/* ============================================================
   AI Dashboard — tải dữ liệu bất đồng bộ, render phía client
   ============================================================ */

const AI_URL    = window.AI_PROCESS_URL || '/smartware/pages/ai/process.php';
const CHAT_KEY  = window.AI_CHAT_KEY    || 'smartware_chat';
const AI_TIMEOUT_MS = 130000;   // PHP chờ FastAPI tối đa 120s

// Một nguồn màu duy nhất cho biểu đồ VÀ chú thích (trước đây legend lệch màu với biểu đồ)
const AI_RISK = [
    { key: 'high',   label: 'Rủi ro cao', color: '#C24B33' },
    { key: 'medium', label: 'Trung bình', color: '#D5B370' },
    { key: 'low',    label: 'Ổn định',    color: '#2F7F6F' },
];
const AI_PRIORITY = [
    { key: 'critical', label: 'Critical', color: '#C24B33' },
    { key: 'high',     label: 'High',     color: '#8E3422' },
    { key: 'medium',   label: 'Medium',   color: '#D5B370' },
    { key: 'low',      label: 'Low',      color: '#5F6B7D' },
];
const AI_STATUS = {
    OUT_OF_STOCK: ['Hết hàng',     'var(--danger)'],
    LOW_STOCK:    ['Sắp hết',      'var(--gold-dark)'],
    NEAR_EXPIRY:  ['Sắp HSD',      'var(--danger-dark)'],
    OVERSTOCK:    ['Dư hàng',      'var(--info)'],
    NORMAL:       ['Bình thường',  'var(--success)'],
};

let aiCharts = [];

function escHtml(s) {
    return String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                          .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}
const $ai = id => document.getElementById(id);
const aiNum = (v, d = 0) => { const n = Number(v); return Number.isFinite(n) ? n : d; };

// ── Trạng thái: loading / lỗi ────────────────────────────────
function aiShowLoading() {
    $ai('aiContent').hidden = true;
    $ai('aiMeta').innerHTML = '';
    $ai('aiState').innerHTML = `
        <div class="ai-loading" role="status">
            <span class="ai-spinner"></span>
            <span>Đang phân tích kho hàng bằng AI… lần đầu có thể mất vài chục giây.</span>
        </div>
        <div class="ai-kpi-grid" aria-hidden="true">${'<div class="ai-skel ai-skel-kpi"></div>'.repeat(5)}</div>
        <div class="ai-chart-row" aria-hidden="true">${'<div class="ai-skel ai-skel-chart"></div>'.repeat(2)}</div>`;
    $ai('aiState').hidden = false;
}

function aiShowError(message) {
    $ai('aiContent').hidden = true;
    $ai('aiMeta').innerHTML = '';
    $ai('aiState').hidden = false;
    $ai('aiState').innerHTML = `
        <div class="ai-error-box" role="alert">
            <strong>⚠ Không lấy được dữ liệu phân tích từ AI Service.</strong><br>
            ${escHtml(message)}<br>
            <small>Kiểm tra AI Service đang chạy và cấu hình API key, rồi thử lại.</small><br>
            <button type="button" class="ai-retry" id="aiRetry"><i class="ri-refresh-line"></i> Thử lại</button>
        </div>`;
    $ai('aiRetry').addEventListener('click', aiLoad);
}

// ── Tải dữ liệu ──────────────────────────────────────────────
async function aiLoad() {
    aiShowLoading();
    const ctrl  = new AbortController();
    const timer = setTimeout(() => ctrl.abort(), AI_TIMEOUT_MS);
    try {
        const res = await fetch(AI_URL, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'batch_analyze' }),
            signal: ctrl.signal,
        });
        const data = await res.json().catch(() => null);
        if (!data || typeof data !== 'object') {
            throw new Error('Phản hồi không hợp lệ (HTTP ' + res.status + ')');
        }
        if (data.error) {
            throw new Error(typeof data.error === 'string' ? data.error : 'Lỗi không xác định');
        }
        if (!res.ok) {
            throw new Error('AI Service lỗi HTTP ' + res.status);
        }
        // Phản hồi 4xx dạng {detail: ...} hoặc sai định dạng KHÔNG được coi là "kho trống"
        if (!data.summary && !Array.isArray(data.results)) {
            throw new Error('AI Service trả dữ liệu không đúng định dạng');
        }
        aiRender(data);
    } catch (e) {
        aiShowError(e && e.name === 'AbortError' ? 'Quá thời gian chờ AI Service.' : (e.message || 'Lỗi không xác định'));
    } finally {
        clearTimeout(timer);
    }
}

// ── Render ───────────────────────────────────────────────────
function aiLevel(p) {
    const lv = String(p.risk_level || '').toLowerCase();
    if (lv === 'high' || lv === 'critical') return 'high';
    if (lv === 'medium') return 'medium';
    return 'low';                      // giá trị lạ không bị mất khỏi trang
}

function aiRow(p, level) {
    const st    = AI_STATUS[p.stock_status] || (p.stock_status ? [String(p.stock_status), 'var(--text-muted)'] : AI_STATUS.NORMAL);
    const score = Math.max(0, Math.min(100, aiNum(p.risk_score)));
    const bar   = level === 'high' ? 'var(--danger)' : level === 'medium' ? 'var(--gold-dark)' : 'var(--success)';
    const prio  = ['critical', 'high', 'medium', 'low'].includes(String(p.priority)) ? String(p.priority) : 'low';
    const qty   = aiNum(p.suggested_quantity);
    return `
        <tr>
            <td><strong>${escHtml(p.product_name)}</strong></td>
            <td><span class="ai-status" style="color:${st[1]}"><span class="ai-dot" style="background:${st[1]}"></span>${escHtml(st[0])}</span></td>
            <td>
                <span style="font-weight:600">${Math.round(score)}</span>
                <span class="ai-risk-bar-bg"><span class="ai-risk-bar-fill" style="width:${score}%;background:${bar}"></span></span>
            </td>
            <td><span class="ai-badge-priority ${prio}">${prio.toUpperCase()}</span></td>
            <td>${qty > 0 ? `<strong>${qty.toLocaleString('vi-VN')}</strong> đvt` : '—'}</td>
            <td class="col-warning" style="color:var(--text-muted);font-size:12px">${escHtml(p.warning || '')}</td>
            <td class="col-action">
                <button type="button" class="btn-ai-detail" data-id="${aiNum(p.product_id)}" data-name="${escHtml(p.product_name)}">Chi tiết</button>
            </td>
        </tr>`;
}

function aiTable(list, level) {
    return `
        <div class="ai-table-wrap">
            <table class="ai-table">
                <thead><tr>
                    <th style="width:22%">Sản phẩm</th><th style="width:13%">Trạng thái</th>
                    <th style="width:16%">Điểm rủi ro</th><th style="width:10%">Ưu tiên</th>
                    <th style="width:11%">Cần nhập</th><th style="width:20%">Cảnh báo</th><th style="width:8%"></th>
                </tr></thead>
                <tbody>${list.map(p => aiRow(p, level)).join('')}</tbody>
            </table>
        </div>`;
}

function aiLegend(items) {
    return items.map(i => `<span><span class="swatch" style="background:${i.color}"></span>${escHtml(i.label)}</span>`).join('');
}

function aiRender(d) {
    const summary  = d.summary || {};
    const products = Array.isArray(d.results) ? d.results : [];
    const rc = Object.assign({ high: 0, medium: 0, low: 0 }, summary.risk_counts || {});
    const pc = summary.priority_counts || {};

    // Chips
    $ai('aiMeta').innerHTML = `
        <span class="ai-chip">${aiNum(d.analyzed)}/${aiNum(d.total_products)} sản phẩm</span>
        <span class="ai-chip">${aiNum(d.tokens_used).toLocaleString('vi-VN')} tokens</span>
        ${d.cached ? '<span class="ai-chip cached"><i class="ri-flashlight-line"></i> cached</span>' : ''}`;

    // KPI
    const kpi = (cls, icon, label, value) =>
        `<div class="ai-kpi ${cls}"><div class="ai-kpi-label"><i class="${icon}"></i>${label}</div><div class="ai-kpi-value">${aiNum(value)}</div></div>`;
    $ai('aiKpis').innerHTML =
        kpi('red',    'ri-alert-line',            'Rủi ro cao',        rc.high) +
        kpi('amber',  'ri-error-warning-line',    'Rủi ro trung bình', rc.medium) +
        kpi('green',  'ri-checkbox-circle-line',  'Ổn định',           rc.low) +
        kpi('purple', 'ri-truck-line',            'Cần nhập gấp',      pc.critical) +
        kpi('blue',   'ri-box-3-line',            'Tổng sản phẩm',     d.total_products);

    // Nhận xét
    const ov = summary.overview_text || '';
    $ai('aiOverview').hidden = !ov;
    $ai('aiOverviewText').innerHTML = escHtml(ov).replace(/\n/g, '<br>');

    // Biểu đồ + legend (cùng một bảng màu)
    aiCharts.forEach(c => c.destroy());
    aiCharts = [];
    $ai('legendRisk').innerHTML     = aiLegend(AI_RISK);
    $ai('legendPriority').innerHTML = aiLegend(AI_PRIORITY);
    if (typeof Chart !== 'undefined') {
        aiCharts.push(new Chart($ai('chartRisk'), {
            type: 'doughnut',
            data: {
                labels: AI_RISK.map(r => r.label),
                datasets: [{ data: AI_RISK.map(r => aiNum(rc[r.key])), backgroundColor: AI_RISK.map(r => r.color), borderWidth: 2, borderColor: '#fff' }],
            },
            options: {
                responsive: true, maintainAspectRatio: false, cutout: '65%',
                plugins: { legend: { display: false }, tooltip: { callbacks: { label: i => ` ${i.label}: ${i.raw} sản phẩm` } } },
            },
        }));
        aiCharts.push(new Chart($ai('chartPriority'), {
            type: 'bar',
            data: {
                labels: AI_PRIORITY.map(p => p.label),
                datasets: [{ label: 'Số sản phẩm', data: AI_PRIORITY.map(p => aiNum(pc[p.key])), backgroundColor: AI_PRIORITY.map(p => p.color), borderRadius: 6 }],
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { display: false }, ticks: { color: '#8D96A5', font: { size: 11 } } },
                    y: { beginAtZero: true, ticks: { stepSize: 1, color: '#8D96A5', font: { size: 11 } }, grid: { color: 'rgba(0,0,0,0.04)' } },
                },
            },
        }));
    }

    // Bảng theo mức rủi ro (giá trị lạ được gộp vào "low", không bị mất)
    const by = { high: [], medium: [], low: [] };
    products.forEach(p => by[aiLevel(p)].push(p));

    const section = (level, label, color) => by[level].length ? `
        <div class="ai-section-hd">
            <span class="ai-dot" style="background:${color}"></span>
            <h2>${label}</h2><span class="ai-count">(${by[level].length} sản phẩm)</span>
        </div>${aiTable(by[level], level)}` : '';

    const lowBlock = by.low.length ? `
        <details class="ai-low">
            <summary><span class="ai-dot" style="background:var(--success)"></span> Ổn định <span class="ai-count">(${by.low.length} sản phẩm)</span></summary>
            ${aiTable(by.low, 'low')}
        </details>` : '';

    $ai('aiTables').innerHTML =
        section('high', 'Rủi ro cao', 'var(--danger)') +
        section('medium', 'Rủi ro trung bình', 'var(--gold-dark)') +
        lowBlock;

    $ai('aiState').hidden = true;
    $ai('aiState').innerHTML = '';
    $ai('aiContent').hidden = false;
}

// Nút "Chi tiết" (giao việc qua event delegation, không dùng onclick inline + addslashes)
document.addEventListener('click', e => {
    const b = e.target.closest('.btn-ai-detail');
    if (b) analyzeProduct(aiNum(b.dataset.id), b.dataset.name || '');
});

// TODO (bàn sau): modal chi tiết sản phẩm — hiện vẫn là placeholder
async function analyzeProduct(productId, productName) {
    alert(`Đang phân tích chi tiết: ${productName}\n(Tích hợp modal /forecast/advanced và /risks ở đây)`);
}

// ── Lịch sử chat (tách theo người dùng, key do PHP cấp) ──────
function saveChatHistory() {
    const box = $ai('chatMessages');
    if (box) localStorage.setItem(CHAT_KEY, box.innerHTML);
}

function loadChatHistory() {
    const saved = localStorage.getItem(CHAT_KEY);
    if (saved) {
        const box = $ai('chatMessages');
        box.innerHTML = saved;
        box.scrollTop = box.scrollHeight;
    }
}

function clearChatHistory() {
    localStorage.removeItem(CHAT_KEY);
    $ai('chatMessages').innerHTML =
        '<div class="chat-msg-ai">Xin chào! Tôi có thể giúp bạn phân tích tồn kho, dự báo nhu cầu, hoặc trả lời bất kỳ câu hỏi nào về kho hàng.</div>';
}

document.addEventListener('DOMContentLoaded', () => {
    loadChatHistory();
    aiLoad();
});

// ── Chat AI ──────────────────────────────────────────────────
async function sendChat() {
    const input = $ai('chatInput');
    const msg   = input.value.trim();
    if (!msg) return;

    const box = $ai('chatMessages');
    const btn = $ai('chatBtn');

    box.innerHTML += `<div class="chat-msg-user">${escHtml(msg)}</div>`;
    input.value = '';
    btn.disabled = true;
    box.innerHTML += `<div id="chatTyping"><span class="ai-spinner"></span>Đang suy nghĩ...</div>`;
    box.scrollTop = box.scrollHeight;

    try {
        const res  = await fetch(AI_URL, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'chat', message: msg }),
        });
        const data = await res.json().catch(() => null);
        $ai('chatTyping')?.remove();
        const text = data && (data.answer || (typeof data.error === 'string' ? data.error : '')) || 'Lỗi';
        box.innerHTML += `<div class="chat-msg-ai">${escHtml(text)}</div>`;
    } catch (e) {
        $ai('chatTyping')?.remove();
        box.innerHTML += `<div class="chat-msg-ai" style="color:#C24B33; border-color:#F0C4B8; background:#FBEAE5;">Không kết nối được AI Service.</div>`;
    } finally {
        btn.disabled = false;
        box.scrollTop = box.scrollHeight;
        saveChatHistory();
    }
}
