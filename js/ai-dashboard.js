if (document.getElementById('chartRisk')) {
    new Chart(document.getElementById('chartRisk'), {
        type: 'doughnut',
        data: {
            labels: ['Rủi ro cao', 'Trung bình', 'Ổn định'],
            datasets: [{
                data: [riskCounts.high || 0, riskCounts.medium || 0, riskCounts.low || 0],
                backgroundColor: ['#dc2626', '#d97706', '#16a34a'],
                borderWidth: 2,
                borderColor: '#fff',
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '65%',
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: i => ` ${i.label}: ${i.raw} sản phẩm` } }
            }
        }
    });
}

if (document.getElementById('chartPriority')) {
    const labels = ['Critical', 'High', 'Medium', 'Low'];
    const keys   = ['critical', 'high', 'medium', 'low'];
    new Chart(document.getElementById('chartPriority'), {
        type: 'bar',
        data: {
            labels,
            datasets: [{
                label: 'Số sản phẩm',
                data: keys.map(k => priorityCounts[k] || 0),
                backgroundColor: ['#9d174d', '#991b1b', '#92400e', '#374151'],
                borderRadius: 6,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { grid: { display: false }, ticks: { color: '#9ca3af', font: { size: 11 } } },
                y: { beginAtZero: true, ticks: { stepSize: 1, color: '#9ca3af', font: { size: 11 } }, grid: { color: 'rgba(0,0,0,0.04)' } }
            }
        }
    });
}

async function analyzeProduct(productId, productName) {
    alert(`Đang phân tích chi tiết: ${productName}\n(Tích hợp modal /forecast/advanced và /risks ở đây)`);
}

const CHAT_KEY = `smartware_chat`;

function saveChatHistory() {
    const box = document.getElementById('chatMessages');
    localStorage.setItem(CHAT_KEY, box.innerHTML);
}

function loadChatHistory() {
    const saved = localStorage.getItem(CHAT_KEY);
    if (saved) {
        document.getElementById('chatMessages').innerHTML = saved;
        const box = document.getElementById('chatMessages');
        box.scrollTop = box.scrollHeight;
    }
}

function clearChatHistory() {
    localStorage.removeItem(CHAT_KEY);
    document.getElementById('chatMessages').innerHTML =
        '<div class="chat-msg-ai">Xin chào! Tôi có thể giúp bạn phân tích tồn kho, dự báo nhu cầu, hoặc trả lời bất kỳ câu hỏi nào về kho hàng.</div>';
}

document.addEventListener('DOMContentLoaded', loadChatHistory);

//  Chat AI 
async function sendChat() {
    const input  = document.getElementById('chatInput');
    const msg    = input.value.trim();
    if (!msg) return;

    const box = document.getElementById('chatMessages');
    const btn = document.getElementById('chatBtn');

    box.innerHTML += `<div class="chat-msg-user">${escHtml(msg)}</div>`;
    input.value = '';
    btn.disabled = true;
    box.innerHTML += `<div id="chatTyping"><span class="ai-spinner"></span>Đang suy nghĩ...</div>`;
    box.scrollTop = box.scrollHeight;

    try {
        const res = await fetch('/smartware/pages/ai/process.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'chat', message: msg }),
        });
        const data = await res.json();
        document.getElementById('chatTyping')?.remove();
        box.innerHTML += `<div class="chat-msg-ai">${escHtml(data.answer || data.error || 'Lỗi')}</div>`;
    } catch (e) {
        document.getElementById('chatTyping')?.remove();
        box.innerHTML += `<div class="chat-msg-ai" style="color:#dc2626; border-color:#fca5a5; background:#fee2e2;">Không kết nối được AI Service.</div>`;
    } finally {
        btn.disabled = false;
        box.scrollTop = box.scrollHeight;
        saveChatHistory();
    }
}

function escHtml(s) {
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}