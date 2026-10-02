<?php
// pages/inbound/ocr.php
$page_title = 'OCR Hóa đơn';

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/db.php';
requireLogin();

$csrfToken  = (new App\Services\CsrfService())->getToken();
include __DIR__ . '/../../layout/header.php';
?>
<link rel="stylesheet" href="../../css/inbound.css">
<div class="main-content">
<div class="ocr-wrap">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:24px">
        <a href="index.php" style="color:#6b7280;text-decoration:none">
            <i class="ri-arrow-left-line"></i> Quay lại
        </a>
        <h1 style="font-size:20px;font-weight:700;margin:0">
            <i class="ri-file-search-line" style="color:#6366f1"></i>
            OCR Hóa đơn nhập hàng
        </h1>
        <span style="background:#eef2ff;color:#4f46e5;font-size:11px;font-weight:600;padding:2px 10px;border-radius:20px">GPT-4o Vision</span>
    </div>

    <!-- Error banner -->
    <div class="error-banner" id="errorBanner"></div>

    <!-- Upload zone -->
    <div class="upload-zone" id="uploadZone">
        <i class="ri-file-pdf-line"></i>
        <p>Kéo thả file PDF vào đây hoặc <strong>click để chọn</strong></p>
        <p style="font-size:12px;margin-top:8px">Hỗ trợ: PDF hóa đơn nhập hàng (tối đa 20MB)</p>
        <input type="file" id="fileInput" accept=".pdf" style="display:none">
    </div>

    <!-- Loading -->
    <div class="ocr-loading" id="ocrLoading">
        <div class="spinner"></div>
        <p style="color:#6b7280">Đang phân tích hóa đơn bằng AI...<br>
        <small>Thường mất 10-30 giây tùy độ phức tạp</small></p>
    </div>

    <!-- Preview -->
    <div class="ocr-preview" id="ocrPreview">

        <!-- Header info -->
        <div class="preview-header">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
                <strong style="font-size:15px">✅ Đã trích xuất thông tin hóa đơn</strong>
                <span class="conf-badge" id="confBadge"></span>
            </div>
        </div>

        <!-- Warnings -->
        <div class="ocr-warnings" id="ocrWarnings" style="display:none">
            <strong>⚠ Lưu ý:</strong>
            <ul id="warningList"></ul>
        </div>

        <!-- Thông tin NCC & hóa đơn -->
        <div class="info-grid">
            <div class="info-field">
                <label>Nhà cung cấp</label>
                <input type="text" id="f_supplier" placeholder="Tên NCC">
            </div>
            <div class="info-field">
                <label>Số điện thoại</label>
                <input type="text" id="f_phone" placeholder="SĐT">
            </div>
            <div class="info-field">
                <label>Mã số thuế</label>
                <input type="text" id="f_tax" placeholder="MST">
            </div>
            <div class="info-field">
                <label>Số hóa đơn</label>
                <input type="text" id="f_invoice_no" placeholder="Số HĐ">
            </div>
            <div class="info-field">
                <label>Ngày hóa đơn</label>
                <input type="date" id="f_date">
            </div>
            <div class="info-field">
                <label>Tổng tiền</label>
                <input type="text" id="f_total" placeholder="0">
            </div>
        </div>

        <!-- Danh sách sản phẩm -->
        <div class="items-section">
            <h3><i class="ri-list-check"></i> Danh sách sản phẩm 
                <small style="font-weight:400;color:#6b7280">(có thể chỉnh sửa trực tiếp)</small>
            </h3>
            <table class="ocr-items">
                <thead>
                    <tr>
                        <th>Tên sản phẩm</th>
                        <th style="width:90px">Mã SP</th>
                        <th style="width:80px">SL</th>
                        <th style="width:70px">ĐVT</th>
                        <th style="width:120px">Đơn giá</th>
                        <th style="width:120px">Thành tiền</th>
                        <th style="width:30px"></th>
                    </tr>
                </thead>
                <tbody id="itemsBody"></tbody>
            </table>
            <button class="btn-add-row" onclick="addItemRow()">
                <i class="ri-add-line"></i> Thêm dòng
            </button>
        </div>

        <!-- Actions -->
        <div class="action-row">
            <button class="btn-secondary" onclick="resetOCR()">
                <i class="ri-refresh-line"></i> Upload lại
            </button>
            <button class="btn-primary" onclick="createInbound()">
                <i class="ri-file-add-line"></i> Tạo phiếu nhập từ hóa đơn này
            </button>
        </div>
    </div>
</div>
</div>

<?php include __DIR__ . '/../../layout/footer.php'; ?>

<script>
const CSRF_TOKEN = '<?= htmlspecialchars($csrfToken) ?>';
const PROCESS_URL = 'ocr_process.php';

// ── Upload zone ──────────────────────────────────────────────
const zone  = document.getElementById('uploadZone');
const input = document.getElementById('fileInput');

zone.addEventListener('click', () => input.click());
zone.addEventListener('dragover', e => { e.preventDefault(); zone.classList.add('dragover'); });
zone.addEventListener('dragleave', () => zone.classList.remove('dragover'));
zone.addEventListener('drop', e => {
    e.preventDefault();
    zone.classList.remove('dragover');
    const file = e.dataTransfer.files[0];
    if (file) handleFile(file);
});
input.addEventListener('change', () => {
    if (input.files[0]) handleFile(input.files[0]);
});

// ── Handle file ──────────────────────────────────────────────
async function handleFile(file) {
    if (!file.name.toLowerCase().endsWith('.pdf')) {
        showError('Chỉ hỗ trợ file PDF');
        return;
    }
    if (file.size > 20 * 1024 * 1024) {
        showError('File quá lớn (tối đa 20MB)');
        return;
    }

    hideError();
    document.getElementById('uploadZone').style.display   = 'none';
    document.getElementById('ocrLoading').style.display   = 'block';
    document.getElementById('ocrPreview').style.display   = 'none';

    const formData = new FormData();
    formData.append('file', file);
    formData.append('csrf_token', CSRF_TOKEN);

    try {
        const res  = await fetch(PROCESS_URL, { method: 'POST', body: formData });
        const data = await res.json();

        document.getElementById('ocrLoading').style.display = 'none';

        if (data.error) {
            showError(data.error);
            document.getElementById('uploadZone').style.display = 'block';
            return;
        }

        renderPreview(data);
    } catch (e) {
        document.getElementById('ocrLoading').style.display   = 'none';
        document.getElementById('uploadZone').style.display   = 'block';
        showError('Lỗi kết nối AI Service');
    }
}

// ── Render preview ───────────────────────────────────────────
function renderPreview(data) {
    // Confidence badge
    const conf  = data.confidence || 0;
    const badge = document.getElementById('confBadge');
    badge.textContent = `Độ chính xác: ${Math.round(conf * 100)}%`;
    badge.className = 'conf-badge ' + (conf >= 0.8 ? 'conf-high' : conf >= 0.6 ? 'conf-medium' : 'conf-low');

    // Fields
    document.getElementById('f_supplier').value   = data.supplier_name  || '';
    document.getElementById('f_phone').value       = data.supplier_phone || '';
    document.getElementById('f_tax').value         = data.supplier_tax   || '';
    document.getElementById('f_invoice_no').value  = data.invoice_no     || '';
    document.getElementById('f_date').value        = data.invoice_date   || '';
    document.getElementById('f_total').value       = data.total_amount   || 0;

    // Warnings
    if (data.warnings && data.warnings.length > 0) {
        document.getElementById('ocrWarnings').style.display = 'block';
        const ul = document.getElementById('warningList');
        ul.innerHTML = data.warnings.map(w => `<li>${escHtml(w)}</li>`).join('');
    }

    // Items
    const tbody = document.getElementById('itemsBody');
    tbody.innerHTML = '';
    (data.items || []).forEach(item => addItemRow(item));

    document.getElementById('ocrPreview').style.display = 'block';
}

// ── Item rows ────────────────────────────────────────────────
function addItemRow(item = {}) {
    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td><input type="text" value="${escAttr(item.name || '')}" placeholder="Tên sản phẩm"></td>
        <td><input type="text" value="${escAttr(item.sku  || '')}" placeholder="Mã SP"></td>
        <td><input type="number" value="${item.quantity  || ''}" placeholder="0" min="0" step="0.01" onchange="recalcTotal(this)"></td>
        <td><input type="text"   value="${escAttr(item.unit || '')}" placeholder="Cái"></td>
        <td><input type="number" value="${item.unit_price || ''}" placeholder="0" min="0" onchange="recalcTotal(this)"></td>
        <td><input type="number" value="${item.total      || ''}" placeholder="0" min="0"></td>
        <td><button class="btn-del-row" onclick="this.closest('tr').remove()" title="Xóa dòng">×</button></td>
    `;
    document.getElementById('itemsBody').appendChild(tr);
}

function recalcTotal(input) {
    const row = input.closest('tr');
    const qty   = parseFloat(row.cells[2].querySelector('input').value) || 0;
    const price = parseFloat(row.cells[4].querySelector('input').value) || 0;
    row.cells[5].querySelector('input').value = (qty * price).toFixed(0);
}

// ── Tạo phiếu nhập ───────────────────────────────────────────
function createInbound() {
    // Thu thập dữ liệu đã chỉnh sửa
    const items = [];
    document.querySelectorAll('#itemsBody tr').forEach(tr => {
        const cells = tr.querySelectorAll('input');
        const name = cells[0].value.trim();
        if (!name) return;
        items.push({
            name:       name,
            sku:        cells[1].value.trim(),
            quantity:   parseFloat(cells[2].value) || 0,
            unit:       cells[3].value.trim(),
            unit_price: parseFloat(cells[4].value) || 0,
            total:      parseFloat(cells[5].value) || 0,
        });
    });

    if (items.length === 0) {
        alert('Vui lòng thêm ít nhất 1 sản phẩm');
        return;
    }

    // Lưu vào sessionStorage → create.php đọc và điền form
    const payload = {
        supplier_name:  document.getElementById('f_supplier').value,
        supplier_phone: document.getElementById('f_phone').value,
        supplier_tax:   document.getElementById('f_tax').value,
        invoice_no:     document.getElementById('f_invoice_no').value,
        invoice_date:   document.getElementById('f_date').value,
        total_amount:   document.getElementById('f_total').value,
        items,
        _from_ocr: true,
    };
    sessionStorage.setItem('ocr_inbound', JSON.stringify(payload));
    window.location.href = 'create.php?from=ocr';
}

function resetOCR() {
    document.getElementById('uploadZone').style.display = 'block';
    document.getElementById('ocrPreview').style.display = 'none';
    document.getElementById('fileInput').value = '';
    hideError();
}

function showError(msg) {
    const el = document.getElementById('errorBanner');
    el.textContent = '⚠ ' + msg;
    el.style.display = 'block';
}
function hideError() {
    document.getElementById('errorBanner').style.display = 'none';
}
function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
function escAttr(s) {
    return String(s).replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}
</script>
</body>
</html>