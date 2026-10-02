//  outbound.js

'use strict';

function getCsrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content || '';
}

function getOutboundAutocompleteOptions() {
    return {
        searchUrl: 'process.php?action=search_products',
        onSelect: (product, row) => {
            loadBatchesForOutboundRow(row, product.id);
            if (typeof saveOutboundDraft === 'function') saveOutboundDraft();
        },
        position: 'above',
        minChars: 2
    };
}

function loadBatchesForOutboundRow(row, productId) {
    const batchSelect = row.querySelector('.batch-select-outbound');
    const batchInfo   = row.querySelector('.batch-stock-info');
    if (!productId) {
        if (batchSelect) batchSelect.innerHTML = '<option value="">-- Chọn lô --</option>';
        if (batchInfo)   batchInfo.innerHTML   = '';
        return;
    }
    fetch(`get_batches.php?product_id=${productId}`)
        .then(res => res.json())
        .then(data => {
            if (data.success && data.batches.length) {
                let opts = '<option value="">-- Chọn lô --</option>';
                data.batches.forEach(b => {
                    opts += `<option value="${escapeHtml(b.batch_no)}" data-stock="${b.quantity}" data-exp="${b.exp_date}">
                        ${escapeHtml(b.batch_no)} (HSD: ${b.exp_date}, còn: ${b.quantity})
                    </option>`;
                });
                if (batchSelect) batchSelect.innerHTML = opts;
                if (batchInfo)   batchInfo.innerHTML   = '';
            } else {
                if (batchSelect) batchSelect.innerHTML = '<option value="">-- Không có lô tồn --</option>';
                if (batchInfo)   batchInfo.innerHTML   = '<span style="color:red">Sản phẩm này không còn lô hàng nào</span>';
            }
        })
        .catch(err => console.error(err));
}

function getEmptyOutboundRow() {
    const template = document.getElementById('outboundRowTemplate');
    if (!template) return null;
    const newRow = template.content.cloneNode(true).querySelector('tr');
    newRow.querySelectorAll('input, select').forEach(el => { el.value = ''; });
    newRow.querySelector('.qty-outbound').value = '1';
    return newRow;
}

function addOutboundRow() {
    const tbody = document.getElementById('outboundItemsBody');
    if (!tbody) return;
    const newRow = getEmptyOutboundRow();
    if (!newRow) return;
    tbody.appendChild(newRow);
    attachOutboundRowEvents(newRow);
    const autocompleteInput = newRow.querySelector('.product-autocomplete-outbound');
    if (autocompleteInput) {
        initAutocomplete(autocompleteInput, getOutboundAutocompleteOptions());
    }
    updateOutboundStt();
    saveOutboundDraft();
}

function removeOutboundRow(btn) {
    const rows = document.querySelectorAll('#outboundItemsBody .item-row');
    if (rows.length > 1) {
        btn.closest('tr').remove();
        updateOutboundStt();
        saveOutboundDraft();
    } else {
        alert('Phải có ít nhất một dòng sản phẩm');
    }
}

function clearAllOutboundRows() {
    const tbody = document.getElementById('outboundItemsBody');
    if (tbody && confirm('Xóa tất cả các dòng sản phẩm?')) {
        tbody.innerHTML = '';
        addOutboundRow();
        saveOutboundDraft();
    }
}

function resetOutboundForm() {
    if (confirm('Làm mới sẽ xóa tất cả dữ liệu đang nhập. Bạn có chắc?')) {
        document.getElementById('outboundForm')?.reset();
        const tbody = document.getElementById('outboundItemsBody');
        if (tbody) tbody.innerHTML = '';
        addOutboundRow();
        localStorage.removeItem('outbound_draft');
    }
}

function attachOutboundRowEvents(row) {
    const qty         = row.querySelector('.qty-outbound');
    const batchSelect = row.querySelector('.batch-select-outbound');
    const batchInfo   = row.querySelector('.batch-stock-info');

    if (qty && qty.type === 'number') qty.type = 'text';

    qty.addEventListener('input', () => saveOutboundDraft());
    qty.addEventListener('blur', function () {
        let val = this.value.trim() === '' ? 1 : unformatNumber(this.value);
        if (isNaN(val) || val <= 0) val = 1;
        const opt   = batchSelect?.options[batchSelect.selectedIndex];
        const stock = opt ? parseInt(opt.getAttribute('data-stock')) : Infinity;
        if (val > stock) { alert(`Số lượng xuất (${val}) vượt quá tồn kho lô (${stock})`); val = stock; }
        this.value = formatNumberInput(val.toString());
        saveOutboundDraft();
    });

    if (batchSelect) {
        batchSelect.addEventListener('change', function () {
            const opt   = this.options[this.selectedIndex];
            const stock = opt?.getAttribute('data-stock');
            if (stock) {
                const currentQty = unformatNumber(qty.value);
                if (currentQty > parseInt(stock)) {
                    alert(`Số lượng xuất (${currentQty}) vượt quá tồn kho lô (${stock})`);
                    qty.value = formatNumberInput(stock);
                }
            }
            saveOutboundDraft();
        });
    }
}

function updateOutboundStt() {
    document.querySelectorAll('#outboundItemsBody .item-row').forEach((row, idx) => {
        const cell = row.querySelector('.stt-cell');
        if (cell) cell.textContent = idx + 1;
    });
}

function saveOutboundDraft() {
    const draft = {
        ref_no: document.querySelector('#outboundForm input[name="ref_no"]')?.value || '',
        note:   document.querySelector('#outboundForm textarea[name="note"]')?.value || '',
        items:  []
    };
    document.querySelectorAll('#outboundItemsBody .item-row').forEach(row => {
        const item = {
            product_id:   row.querySelector('.product-id')?.value || '',
            product_name: row.querySelector('.product-autocomplete-outbound')?.value || '',
            batch_no:     row.querySelector('.batch-select-outbound')?.value || '',
            quantity:     unformatNumber(row.querySelector('.qty-outbound')?.value || 0)
        };
        if (item.product_name || item.batch_no) draft.items.push(item);
    });
    localStorage.setItem('outbound_draft', JSON.stringify(draft));
}

function validateOutboundFormBeforeSubmit() {
    let hasValidRow = false;
    document.querySelectorAll('#outboundItemsBody .item-row').forEach(row => {
        const pid     = row.querySelector('.product-id').value;
        const batchNo = row.querySelector('.batch-select-outbound').value;
        const qty     = unformatNumber(row.querySelector('.qty-outbound').value);
        row.querySelector('.product-autocomplete-outbound').style.border = (pid && pid != '0') ? '' : '2px solid red';
        row.querySelector('.batch-select-outbound').style.border = batchNo ? '' : '2px solid red';
        row.querySelector('.qty-outbound').style.border          = qty > 0 ? '' : '2px solid red';
        if (pid && pid != '0' && batchNo && qty > 0) hasValidRow = true;
    });
    if (!hasValidRow) {
        alert('Vui lòng chọn ít nhất một sản phẩm hợp lệ (có lô, số lượng >0).');
        return false;
    }
    return true;
}

function viewOutboundDetail(id) {
    fetch(`process.php?action=get_outbound_detail&id=${id}`)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                window.currentOutboundDetail = data;
                renderOutboundDetailModal(data);
                document.getElementById('detailOutboundModal').style.display = 'flex';
            } else {
                alert('Không thể tải chi tiết phiếu xuất');
            }
        })
        .catch(err => alert('Lỗi: ' + err));
}

function renderOutboundDetailModal(data) {
    let totalQuantity = 0;
    data.items.forEach(item => { totalQuantity += parseFloat(item.quantity); });
    let stt = 1;
    const createdDisplay = escapeHtml(data.created || '');
    let html = `
        <div style="margin-bottom:20px">
            <p><strong>Mã phiếu:</strong> ${escapeHtml(data.ref_no)}</p>
            <p><strong>Người xuất:</strong> ${escapeHtml(data.user_name || '')}</p>
            <p><strong>Ghi chú:</strong> ${escapeHtml(data.note || '')}</p>
            <p><strong>Ngày tạo:</strong> ${createdDisplay}</p>
        </div>
        <table class="data-table">
            <thead><tr>
                <th class="text-center">STT</th><th>Sản phẩm</th><th>Lô</th>
                <th class="text-right">Số lượng</th>
            </tr></thead>
            <tbody>`;
    data.items.forEach(item => {
        html += `<tr>
            <td class="text-center">${stt++}</td>
            <td>${escapeHtml(item.product_name)}</td>
            <td>${escapeHtml(item.batch_no)}</td>
            <td class="text-right">${item.quantity}</td>
        </tr>`;
    });
    html += `</tbody></table>
        <div style="margin-top:20px;border-top:1px solid #ddd;padding-top:10px">
            <div style="display:flex;justify-content:space-between"><strong>Tổng số lượng:</strong> <span>${formatNumberInput(totalQuantity)}</span></div>
        </div>`;
    document.getElementById('detailOutboundContent').innerHTML = html;
}

function closeDetailOutboundModal() {
    document.getElementById('detailOutboundModal').style.display = 'none';
}

function confirmDeleteOutbound(id) {
    if (!confirm('Bạn có chắc muốn xóa phiếu xuất này?\nPhiếu phải đang ở trạng thái Tạm thời mới xóa được.')) return;
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'process.php';
    [
        ['action',     'delete_outbound'],
        ['id',         id],
        ['csrf_token', getCsrfToken()],
    ].forEach(([name, value]) => {
        const input = document.createElement('input');
        input.type = 'hidden'; input.name = name; input.value = value;
        form.appendChild(input);
    });
    document.body.appendChild(form);
    form.submit();
}

function searchOutboundTable() {
    const filter = document.getElementById('searchInput')?.value.toLowerCase() || '';
    document.querySelectorAll('#outboundTable tbody tr').forEach(row => {
        row.style.display = row.innerText.toLowerCase().includes(filter) ? '' : 'none';
    });
}

function exportOutboundExcel() {
    const params = new URLSearchParams(window.location.search);
    window.location.href = '../../pages/export/Export.php?type=outbound_list&' + params.toString();
}

function exportOutboundDetailExcel() {
    if (!window.currentOutboundDetail) return;
    fetch('../../pages/export/Export.php?type=outbound_detail', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': getCsrfToken(),
        },
        body: JSON.stringify(window.currentOutboundDetail)
    })
    .then(r => r.blob())
    .then(blob => {
        const url = URL.createObjectURL(blob);
        const a   = document.createElement('a');
        a.href    = url;
        a.download = `phieu_xuat_${window.currentOutboundDetail.ref_no || 'detail'}_${new Date().toISOString().slice(0,19)}.xls`;
        document.body.appendChild(a); a.click(); document.body.removeChild(a);
        URL.revokeObjectURL(url);
    })
    .catch(err => alert('Lỗi xuất file: ' + err));
}

function printOutboundDetail() {
    if (!window.currentOutboundDetail) return;
    let itemsHtml = '', stt = 1;
    window.currentOutboundDetail.items.forEach(item => {
        itemsHtml += `<tr>
            <td style="text-align:center">${stt++}</td>
            <td>${escapeHtml(item.product_name)}</td>
            <td>${escapeHtml(item.batch_no)}</td>
            <td style="text-align:right">${item.quantity}</td>
        </tr>`;
    });
    const d = window.currentOutboundDetail;
    const win = window.open('', '_blank');
    win.document.write(`<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Phiếu xuất ${escapeHtml(d.ref_no)}</title>
    <style>body{font-family:sans-serif;font-size:13px}table{width:100%;border-collapse:collapse}th,td{border:1px solid #ddd;padding:6px 10px}th{background:#f5f5f5}</style>
    </head><body>
    <h2 style="text-align:center">PHIẾU XUẤT KHO</h2>
    <p><strong>Mã phiếu:</strong> ${escapeHtml(d.ref_no)} &nbsp; <strong>Ngày:</strong> ${escapeHtml(d.created || '')}</p>
    <p><strong>Người xuất:</strong> ${escapeHtml(d.user_name || '')} &nbsp; <strong>Ghi chú:</strong> ${escapeHtml(d.note || '')}</p>
    <table><thead><tr><th>STT</th><th>Sản phẩm</th><th>Số lô</th><th>SL</th></tr></thead>
    <tbody>${itemsHtml}</tbody></table>
    <p><\/p>
    <script>window.print();<\/script></body></html>`);
    win.document.close();
}

function editOutbound(id) {
    fetch(`process.php?action=get_outbound_detail&id=${id}`)
        .then(res => res.json())
        .then(data => {
            if (!data.success) { alert('Không thể tải phiếu xuất'); return; }
            renderEditOutboundModal(data);
            document.getElementById('editOutboundModal').style.display = 'flex';
        })
        .catch(err => alert('Lỗi: ' + err));
}

function renderEditOutboundModal(data) {
    const html = `
    <form id="editOutboundForm" method="POST" action="process.php">
        <input type="hidden" name="action" value="edit_outbound">
        <input type="hidden" name="outbound_id" value="${data.id}">
        <input type="hidden" name="csrf_token" value="${escapeHtml(getCsrfToken())}">

        <div class="inv-header-strip">
            <div class="inv-strip-label">Sửa phiếu xuất</div>
        </div>

        <div class="inv-header-grid" style="padding:16px">
            <div class="inv-col">
                <div class="form-group">
                    <label>Số tham chiếu</label>
                    <input type="text" name="ref_no" class="inv-field-input" value="${escapeHtml(data.ref_no || '')}">
                </div>
            </div>
            <div class="inv-col">
                <div class="form-group">
                    <label>Trạng thái</label>
                    <select name="status" id="editStatus" class="inv-field-input">
                        <option value="pending"   ${data.status === 'pending'   ? 'selected' : ''}>Tạm thời</option>
                        <option value="completed" ${data.status === 'completed' ? 'selected' : ''}>Hoàn thành</option>
                        <option value="cancelled" ${data.status === 'cancelled' ? 'selected' : ''}>Đã hủy</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="inv-body" style="padding:0 16px">
            <table class="data-table" id="editOutboundItemsTable">
                <thead><tr>
                    <th style="width:36px;text-align:center">#</th>
                    <th style="min-width:200px">Sản phẩm</th>
                    <th style="width:220px">Số lô (tồn kho)</th>
                    <th style="width:100px;text-align:right">SL</th>
                    <th style="width:36px"></th>
                </tr></thead>
                <tbody id="editOutboundItemsBody"></tbody>
            </table>
            <div class="inv-add-row" style="margin-top:8px">
                <button type="button" class="btn-add-line" onclick="addEditOutboundRow()">
                    <i class="ri-add-line"></i> Thêm dòng
                </button>
            </div>
        </div>

        <div class="inv-footer-grid" style="padding:16px">
            <div class="inv-note-section">
                <label>Ghi chú</label>
                <textarea name="note" class="inv-textarea">${escapeHtml(data.note || '')}</textarea>
            </div>
        </div>

        <div class="inv-actions" style="padding:0 16px 16px">
            <button type="button" class="btn inv-btn-cancel" onclick="closeEditOutboundModal()">Hủy bỏ</button>
            <button type="submit" class="btn inv-btn-save"><i class="ri-check-line"></i> Lưu thay đổi</button>
        </div>
    </form>`;

    document.querySelector('#editOutboundModal .modal-content').innerHTML = html;

    const editStatusEl = document.getElementById('editStatus');
    if (editStatusEl) {
        editStatusEl.addEventListener('change', function () {
            const existing = document.getElementById('_completedWarning');
            if (this.value === 'completed' && !existing) {
                const div = document.createElement('div');
                div.id = '_completedWarning';
                div.style.cssText = 'background:#fff3cd;border:1px solid #ffc107;border-radius:6px;padding:8px 12px;font-size:12px;margin-top:8px;color:#856404';
                div.innerHTML = '⚠️ <strong>Chuyển sang Hoàn thành</strong> sẽ trừ tồn kho ngay lập tức và không thể hoàn tác.';
                this.parentNode.appendChild(div);
            } else if (this.value !== 'completed' && existing) {
                existing.remove();
            }
        });
    }

    const editForm = document.getElementById('editOutboundForm');
    if (editForm) {
        editForm.addEventListener('submit', function (e) {
            if (!_validateEditOutboundForm()) { e.preventDefault(); return; }
            document.querySelectorAll('#editOutboundItemsBody .qty-outbound').forEach(el => { el.value = unformatNumber(el.value); });
        });
    }

    if (data.items?.length) {
        data.items.forEach(item => addEditOutboundRow(item));
    } else {
        addEditOutboundRow();
    }
}

function closeEditOutboundModal() {
    document.getElementById('editOutboundModal').style.display = 'none';
}

function addEditOutboundRow(item = null) {
    const tbody = document.getElementById('editOutboundItemsBody');
    if (!tbody) return;
    const idx = tbody.querySelectorAll('.item-row').length + 1;

    const tr = document.createElement('tr');
    tr.className = 'item-row';
    tr.innerHTML = `
        <td class="stt-cell" style="text-align:center">${idx}</td>
        <td style="position:relative">
            <input type="text" class="product-autocomplete-outbound" name="product_name[]"
                   value="${escapeHtml(item?.product_name || '')}" placeholder="Nhập tên sản phẩm" autocomplete="off" style="width:100%">
            <input type="hidden" name="product_id[]" class="product-id" value="${escapeHtml(String(item?.product_id || ''))}">
        </td>
        <td>
            <select name="batch_no[]" class="batch-select-outbound" style="width:100%">
                <option value="${escapeHtml(item?.batch_no || '')}" selected>${escapeHtml(item?.batch_no || '-- Chọn lô --')}</option>
            </select>
            <div class="batch-stock-info" style="font-size:11px;margin-top:2px"></div>
        </td>
        <td><input type="text" name="quantity[]" class="qty-outbound" value="${formatNumberInput(String(item?.quantity || 1))}" style="width:80px;text-align:right"></td>
        <td style="text-align:center"><button type="button" class="remove-row" onclick="removeEditOutboundRow(this)"><i class="ri-delete-bin-line"></i></button></td>
    `;
    tbody.appendChild(tr);
    attachEditOutboundRowEvents(tr);

    if (item?.product_id) _loadEditOutboundBatches(tr, item.product_id, item.batch_no);
}

function removeEditOutboundRow(btn) {
    const rows = document.querySelectorAll('#editOutboundItemsBody .item-row');
    if (rows.length > 1) { btn.closest('tr').remove(); }
    else alert('Phải có ít nhất một dòng sản phẩm');
}

function attachEditOutboundRowEvents(row) {
    const qty         = row.querySelector('.qty-outbound');
    const batchSelect = row.querySelector('.batch-select-outbound');

    if (qty && qty.type === 'number') qty.type = 'text';

    qty.addEventListener('blur', function () {
        let val = unformatNumber(this.value);
        if (isNaN(val) || val <= 0) val = 1;
        const opt   = batchSelect?.options[batchSelect.selectedIndex];
        const stock = parseInt(opt?.getAttribute('data-stock') || '999999');
        if (val > stock) { alert(`Số lượng (${val}) vượt quá tồn kho lô này (${stock} đơn vị)`); val = stock; }
        this.value = formatNumberInput(val.toString());
    });
    if (batchSelect) {
        batchSelect.addEventListener('change', function () {
            const opt     = this.options[this.selectedIndex];
            const stock   = opt?.getAttribute('data-stock');
            if (stock) {
                const currentQty = unformatNumber(row.querySelector('.qty-outbound').value);
                if (currentQty > parseInt(stock)) {
                    alert(`Số lượng (${currentQty}) vượt quá tồn kho lô này (${stock}). Đã điều chỉnh lại.`);
                    row.querySelector('.qty-outbound').value = formatNumberInput(stock);
                }
            }
        });
    }
}

function _loadEditOutboundBatches(row, productId, selectedBatchNo) {
    const batchEl = row.querySelector('.batch-select-outbound');
    const infoEl  = row.querySelector('.batch-stock-info');
    if (!batchEl) return;
    batchEl.innerHTML = '<option value="">Đang tải lô...</option>';
    fetch(`get_batches.php?product_id=${productId}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success || !data.batches.length) {
                batchEl.innerHTML = '<option value="">-- Không có lô tồn --</option>';
                if (infoEl) infoEl.innerHTML = '<span style="color:red">Sản phẩm không còn lô hàng nào</span>';
                return;
            }
            batchEl.innerHTML = '<option value="">-- Chọn lô --</option>' +
                data.batches.map(b => {
                    const expLabel = b.exp_date ? ` | HSD: ${b.exp_date}` : '';
                    const dayLabel = b.days_to_exp != null
                        ? (b.days_to_exp < 0 ? ' ⚠️ Hết hạn' : b.days_to_exp <= 30 ? ` ⚠️ Còn ${b.days_to_exp}ngày` : '')
                        : '';
                    const selected = selectedBatchNo && b.batch_no === selectedBatchNo ? 'selected' : '';
                    return `<option value="${escapeHtml(b.batch_no)}" data-stock="${b.quantity}" data-exp="${b.exp_date || ''}" ${selected}>
                        ${escapeHtml(b.batch_no)} (Tồn: ${b.quantity}${expLabel}${dayLabel})
                    </option>`;
                }).join('');
            batchEl.dispatchEvent(new Event('change'));
        })
        .catch(err => {
            console.error('Lỗi load batches:', err);
            batchEl.innerHTML = '<option value="">-- Lỗi tải lô --</option>';
        });
}

function _validateEditOutboundForm() {
    let valid = true;
    let messages = [];

    document.querySelectorAll('#editOutboundItemsBody .item-row').forEach((row, i) => {
        const rowNum  = i + 1;
        const pid     = row.querySelector('.product-id')?.value;
        const nameEl  = row.querySelector('.product-autocomplete-outbound');
        const batchEl = row.querySelector('.batch-select-outbound');
        const batchNo = batchEl?.value;
        const qty     = unformatNumber(row.querySelector('.qty-outbound')?.value || '0');

        if (nameEl)  nameEl.style.border  = '';
        if (batchEl) batchEl.style.border = '';

        const isLoading = batchEl?.options[0]?.text === 'Đang tải lô...';
        if (isLoading) {
            messages.push(`Dòng ${rowNum}: Vui lòng chờ lô hàng tải xong.`);
            if (batchEl) batchEl.style.border = '2px solid orange';
            valid = false;
            return;
        }

        const errors = [];
        if (!pid || pid === '0' || pid === 'undefined') errors.push(`Dòng ${rowNum}: sản phẩm chưa hợp lệ`);
        if (!batchNo)  errors.push(`Dòng ${rowNum}: chưa chọn lô hàng`);
        if (qty <= 0)  errors.push(`Dòng ${rowNum}: số lượng phải > 0`);

        if (errors.length) {
            valid = false;
            messages.push(...errors);
            if (!pid || pid === '0' || pid === 'undefined') nameEl  && (nameEl.style.border  = '2px solid red');
            if (!batchNo)                                    batchEl && (batchEl.style.border = '2px solid red');
        }
    });

    if (!valid) alert(messages.join('\n'));
    return valid;
}

function openProductModal(action) {
    const modal = document.getElementById('productModal');
    if (!modal) return;
    if (action === 'add') {
        document.getElementById('prodName').value = '';
        document.getElementById('prodSku').value = '';
        document.getElementById('prodCategory').value = '';
        document.getElementById('prodSupplier').value = '';
        document.getElementById('prodCostPrice').value = '0';
        document.getElementById('prodPrice').value = '';
        document.getElementById('prodUnit').value = '';
        document.getElementById('prodDesc').value = '';
    }
    modal.style.display = 'flex';
}

function closeProductModal() {
    const modal = document.getElementById('productModal');
    if (modal) modal.style.display = 'none';
}

// Ghi chú: modal này thêm sản phẩm mới vào danh mục hàng hóa (products.price
// là giá bán của sản phẩm) — không liên quan đến giá trên phiếu xuất, vốn
// không còn được lưu theo schema hiện tại.
function submitNewProduct(event) {
    event.preventDefault();
    const form = document.getElementById('productForm');
    const costEl = document.getElementById('prodCostPrice');
    const priceEl = document.getElementById('prodPrice');
    if (costEl) costEl.value = unformatNumber(costEl.value);
    if (priceEl) priceEl.value = unformatNumber(priceEl.value);
    const formData = new FormData(form);
    formData.append('action', 'add_product');
    formData.append('csrf_token', getCsrfToken());
    fetch('process.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                addOutboundRowWithData({
                    product_name: data.name,
                    product_id: data.id,
                    batch_no: '',
                    quantity: 1
                });
                closeProductModal();
                alert('Thêm sản phẩm thành công!');
            } else {
                alert('Lỗi: ' + (data.message || 'Không thể thêm sản phẩm'));
            }
        })
        .catch(err => { console.error(err); alert('Có lỗi xảy ra'); });
}

function addOutboundRowWithData(item) {
    const tbody = document.getElementById('outboundItemsBody');
    if (!tbody) return;
    const newRow = getEmptyOutboundRow();
    if (!newRow) return;
    newRow.querySelector('.product-autocomplete-outbound').value = item.product_name || '';
    newRow.querySelector('.product-id').value = item.product_id || '';
    newRow.querySelector('.batch-select-outbound').innerHTML = `<option value="${escapeHtml(item.batch_no)}" selected>${escapeHtml(item.batch_no)}</option>`;
    newRow.querySelector('.qty-outbound').value = formatNumberInput((item.quantity || 1).toString());
    tbody.appendChild(newRow);
    attachOutboundRowEvents(newRow);
    const autocompleteInput = newRow.querySelector('.product-autocomplete-outbound');
    if (autocompleteInput) initAutocomplete(autocompleteInput, getOutboundAutocompleteOptions());
    updateOutboundStt();
    saveOutboundDraft();
}
function updateBulkBar() {
    const checked = document.querySelectorAll('#outboundTable .row-checkbox:checked');
    const bar     = document.getElementById('bulkActionBar');
    const count   = document.getElementById('bulkCount');
    if (!bar) return;
    bar.style.display = checked.length > 0 ? 'flex' : 'none';
    if (count) count.textContent = `${checked.length} phiếu đã chọn`;
}
 
function clearSelection() {
    document.querySelectorAll('#outboundTable .row-checkbox')
        .forEach(cb => cb.checked = false);
    const selectAll = document.getElementById('selectAll');
    if (selectAll) { selectAll.checked = false; selectAll.indeterminate = false; }
    updateBulkBar();
}
 
function exportSelectedExcel() {
    const ids = [...document.querySelectorAll('#outboundTable .row-checkbox:checked')]
        .map(cb => cb.dataset.id)
        .filter(Boolean);
 
    if (ids.length === 0) {
        alert('Chưa chọn phiếu xuất nào.');
        return;
    }
 
    const params = new URLSearchParams(window.location.search);
    ids.forEach(id => params.append('selected_ids[]', id));
    window.location.href = '../../pages/export/Export.php?type=outbound_list&' + params.toString();
}
 
document.addEventListener('DOMContentLoaded', function () {
    const table = document.getElementById('outboundTable');
    if (!table) return; 
    const selectAllCb = document.getElementById('selectAll');
 
    if (selectAllCb) {
        selectAllCb.addEventListener('change', function () {
            table.querySelectorAll('.row-checkbox')
                .forEach(cb => cb.checked = this.checked);
            updateBulkBar();
        });
    }
 
    table.querySelectorAll('.row-checkbox').forEach(cb => {
        cb.addEventListener('change', function () {
            const all     = table.querySelectorAll('.row-checkbox');
            const checked = table.querySelectorAll('.row-checkbox:checked');
            if (selectAllCb) {
                selectAllCb.checked       = checked.length === all.length && all.length > 0;
                selectAllCb.indeterminate = checked.length > 0 && checked.length < all.length;
            }
            updateBulkBar();
        });
    });
});
document.addEventListener('DOMContentLoaded', function () {
    const pageType    = document.body.dataset.page || '';
    const isCreatePage = pageType === 'outbound-create';
    const isIndexPage  = pageType === 'outbound-index';

    const outboundBody = document.getElementById('outboundItemsBody');
    if (outboundBody) {
        if (outboundBody.children.length === 0) addOutboundRow();

        const outboundForm = document.getElementById('outboundForm');
        if (outboundForm?.getAttribute('action')?.includes('process.php')) {
            outboundForm.addEventListener('submit', function (e) {
                if (!validateOutboundFormBeforeSubmit()) { e.preventDefault(); return; }
                document.querySelectorAll('#outboundItemsBody .qty-outbound').forEach(el => {
                    el.value = unformatNumber(el.value);
                });
                localStorage.removeItem('outbound_draft');
            });

            const draft = localStorage.getItem('outbound_draft');
            if (draft) {
                try {
                    const data = JSON.parse(draft);
                    if (data.items?.length) {
                        outboundBody.innerHTML = '';
                        data.items.forEach(item => {
                            addOutboundRow();
                            const lastRow = outboundBody.querySelector('.item-row:last-child');
                            if (lastRow) {
                                lastRow.querySelector('.product-autocomplete-outbound').value = item.product_name;
                                lastRow.querySelector('.product-id').value = item.product_id;
                                lastRow.querySelector('.batch-select-outbound').innerHTML = `<option value="${item.batch_no}" selected>${item.batch_no}</option>`;
                                lastRow.querySelector('.qty-outbound').value = formatNumberInput(item.quantity.toString());
                                if (item.product_id) loadBatchesForOutboundRow(lastRow, item.product_id);
                            }
                        });
                        const refEl  = document.querySelector('#outboundForm input[name="ref_no"]');
                        const noteEl = document.querySelector('#outboundForm textarea[name="note"]');
                        if (refEl)  refEl.value  = data.ref_no || '';
                        if (noteEl) noteEl.value = data.note   || '';
                    }
                } catch (e) { /* draft hỏng → bỏ qua */ }
            }
        }
    }

    window.addEventListener('click', function (e) {
        if (e.target === document.getElementById('editOutboundModal'))   closeEditOutboundModal();
        if (e.target === document.getElementById('detailOutboundModal')) closeDetailOutboundModal();
        if (e.target === document.getElementById('productModal')) closeProductModal();
        if (e.target === document.getElementById('supplierModal') && typeof closeSupplierModal === 'function') closeSupplierModal();
        if (e.target === document.getElementById('categoryModal') && typeof closeCategoryModal === 'function') closeCategoryModal();
    });
});

window.addOutboundRow              = addOutboundRow;
window.removeOutboundRow           = removeOutboundRow;
window.clearAllOutboundRows        = clearAllOutboundRows;
window.resetOutboundForm           = resetOutboundForm;
window.loadBatchesForOutboundRow   = loadBatchesForOutboundRow;
window.saveOutboundDraft           = saveOutboundDraft;
window.validateOutboundFormBeforeSubmit = validateOutboundFormBeforeSubmit;
window.viewOutboundDetail          = viewOutboundDetail;
window.closeDetailOutboundModal    = closeDetailOutboundModal;
window.confirmDeleteOutbound       = confirmDeleteOutbound;
window.searchOutboundTable         = searchOutboundTable;
window.exportOutboundExcel         = exportOutboundExcel;
window.exportOutboundDetailExcel   = exportOutboundDetailExcel;
window.printOutboundDetail         = printOutboundDetail;
window.editOutbound                = editOutbound;
window.closeEditOutboundModal      = closeEditOutboundModal;
window.addEditOutboundRow          = addEditOutboundRow;
window.removeEditOutboundRow       = removeEditOutboundRow;
window.attachEditOutboundRowEvents = attachEditOutboundRowEvents;
window.openProductModal            = openProductModal;
window.closeProductModal           = closeProductModal;
window.submitNewProduct            = submitNewProduct;
window.addOutboundRowWithData      = addOutboundRowWithData;
window.updateBulkBar       = updateBulkBar;
window.clearSelection      = clearSelection;
window.exportSelectedExcel = exportSelectedExcel;