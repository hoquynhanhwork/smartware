// js/outbound.js
'use strict';

// ── CSRF & UTILS ──────────────────────────────────────────────────────────
function getCsrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content 
        || document.querySelector('input[name="csrf_token"]')?.value 
        || '';
}

function appendCsrf(formData) {
    formData.set('csrf_token', getCsrfToken());
    return formData;
}

function getOutboundSupplierId() {
    const v = document.getElementById('outboundSupplier')?.value
           || document.getElementById('editOutboundSupplierId')?.value
           || '';
    return parseInt(v, 10) || 0;
}

// ── GỢI Ý TÌM SẢN PHẨM (AUTOCOMPLETE) ──────────────────────────────────
function getOutboundAutocompleteOptions() {
    return {
        searchUrl: `process.php?action=search_products&supplier_id=${getOutboundSupplierId()}`,
        onSelect: (product, row) => {
            loadBatchesForOutboundRow(row, product.id);
            if (typeof saveOutboundDraft === 'function') saveOutboundDraft();
            if (typeof syncOutboundInvoicePreview === 'function') syncOutboundInvoicePreview();
        },
        position: 'above',
        minChars: 2
    };
}

function applyOutboundSupplierLock(row) {
    const input = row?.querySelector('.product-autocomplete-outbound');
    const select = document.getElementById('outboundSupplier');
    if (!input || !select) return;
    const hasSupplier = !!select.value;
    input.readOnly    = !hasSupplier;
    input.placeholder = hasSupplier ? 'Nhập tên hoặc SKU' : 'Chọn nhà cung cấp trước';
}

function onOutboundSupplierChange(select) {
    const prev = select.dataset.prev || '';
    const hasProducts = [...document.querySelectorAll('#outboundItemsBody .product-id')].some(el => el.value);
    if (hasProducts && prev && prev !== select.value) {
        if (!confirm('Đổi nhà cung cấp sẽ xóa các dòng sản phẩm đã chọn (mỗi phiếu chỉ xuất cho một nhà cung cấp). Tiếp tục?')) {
            select.value = prev;
            return;
        }
    }
    select.dataset.prev = select.value;
    const tbody = document.getElementById('outboundItemsBody');
    if (tbody) {
        tbody.innerHTML = '';
        addOutboundRow();
    }
    if (typeof syncOutboundInvoicePreview === 'function') syncOutboundInvoicePreview();
}

function loadBatchesForOutboundRow(row, productId, selectedBatchNo = '') {
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
            if (data.success && data.batches && data.batches.length) {
                let opts = '<option value="">-- Chọn lô --</option>';
                data.batches.forEach(b => {
                    const isSel = selectedBatchNo && b.batch_no === selectedBatchNo ? 'selected' : '';
                    opts += `<option value="${escapeHtml(b.batch_no)}" data-stock="${b.quantity}" data-exp="${b.exp_date || ''}" ${isSel}>
                        ${escapeHtml(b.batch_no)} (${b.exp_date ? 'HSD: ' + b.exp_date + ', ' : ''}tồn: ${b.quantity})
                    </option>`;
                });
                if (batchSelect) batchSelect.innerHTML = opts;
                if (batchInfo)   batchInfo.innerHTML   = '';
            } else {
                if (batchSelect) batchSelect.innerHTML = '<option value="">-- Không có lô tồn --</option>';
                if (batchInfo)   batchInfo.innerHTML   = '<span style="color:#C24B33;font-size:11px;">Hết lô hàng khả dụng</span>';
            }
            if (typeof syncOutboundInvoicePreview === 'function') syncOutboundInvoicePreview();
        })
        .catch(err => console.error(err));
}

// ── QUẢN LÝ DÒNG HÀNG TRANG TẠO (CREATE) ───────────────────────────────
function getEmptyOutboundRow() {
    const template = document.getElementById('outboundRowTemplate');
    if (!template) return null;
    const newRow = template.content.cloneNode(true).querySelector('tr');
    newRow.querySelectorAll('input, select').forEach(el => { el.value = ''; });
    const qty = newRow.querySelector('.qty-outbound');
    if (qty) qty.value = '1';
    applyOutboundSupplierLock(newRow);
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
    if (autocompleteInput && typeof initAutocomplete === 'function') {
        initAutocomplete(autocompleteInput, getOutboundAutocompleteOptions());
    }
    updateOutboundStt();
    saveOutboundDraft();
    if (typeof syncOutboundInvoicePreview === 'function') syncOutboundInvoicePreview();
}

function removeOutboundRow(btn) {
    const rows = document.querySelectorAll('#outboundItemsBody .item-row');
    if (rows.length > 1) {
        btn.closest('tr').remove();
        updateOutboundStt();
        saveOutboundDraft();
        if (typeof syncOutboundInvoicePreview === 'function') syncOutboundInvoicePreview();
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
        if (typeof syncOutboundInvoicePreview === 'function') syncOutboundInvoicePreview();
    }
}

function resetOutboundForm() {
    if (confirm('Làm mới sẽ xóa tất cả dữ liệu đang nhập. Bạn có chắc?')) {
        document.getElementById('outboundForm')?.reset();
        const tbody = document.getElementById('outboundItemsBody');
        if (tbody) tbody.innerHTML = '';
        addOutboundRow();
        localStorage.removeItem('outbound_draft');
        if (typeof syncOutboundInvoicePreview === 'function') syncOutboundInvoicePreview();
    }
}

function attachOutboundRowEvents(row) {
    const qty         = row.querySelector('.qty-outbound');
    const batchSelect = row.querySelector('.batch-select-outbound');

    if (qty && qty.type === 'number') qty.type = 'text';

    if (qty) {
        qty.addEventListener('input', () => {
            saveOutboundDraft();
            if (typeof syncOutboundInvoicePreview === 'function') syncOutboundInvoicePreview();
        });
        qty.addEventListener('blur', function () {
            let val = this.value.trim() === '' ? 1 : unformatNumber(this.value);
            if (isNaN(val) || val <= 0) val = 1;
            const opt   = batchSelect?.options[batchSelect.selectedIndex];
            const stock = opt ? parseInt(opt.getAttribute('data-stock'), 10) : Infinity;
            if (stock && val > stock) {
                alert(`Số lượng xuất (${val}) vượt quá tồn kho của lô (${stock})`);
                val = stock;
            }
            this.value = formatNumberInput(val.toString());
            saveOutboundDraft();
            if (typeof syncOutboundInvoicePreview === 'function') syncOutboundInvoicePreview();
        });
    }

    if (batchSelect) {
        batchSelect.addEventListener('change', function () {
            const opt   = this.options[this.selectedIndex];
            const stock = opt?.getAttribute('data-stock');
            if (stock && qty) {
                const currentQty = unformatNumber(qty.value);
                if (currentQty > parseInt(stock, 10)) {
                    alert(`Số lượng xuất (${currentQty}) vượt quá tồn kho lô (${stock})`);
                    qty.value = formatNumberInput(stock);
                }
            }
            saveOutboundDraft();
            if (typeof syncOutboundInvoicePreview === 'function') syncOutboundInvoicePreview();
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
        supplier_id: document.getElementById('outboundSupplier')?.value || '',
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
    const supplierEl = document.getElementById('outboundSupplier');
    if (supplierEl && !supplierEl.value) {
        alert('Vui lòng chọn nhà cung cấp cho phiếu xuất.');
        supplierEl.focus();
        return false;
    }
    let hasValidRow = false;
    document.querySelectorAll('#outboundItemsBody .item-row').forEach(row => {
        const pid     = row.querySelector('.product-id').value;
        const batchNo = row.querySelector('.batch-select-outbound').value;
        const qty     = unformatNumber(row.querySelector('.qty-outbound').value);
        row.querySelector('.product-autocomplete-outbound').style.border = (pid && pid != '0') ? '' : '2px solid #C24B33';
        row.querySelector('.batch-select-outbound').style.border = batchNo ? '' : '2px solid #C24B33';
        row.querySelector('.qty-outbound').style.border          = qty > 0 ? '' : '2px solid #C24B33';
        if (pid && pid != '0' && batchNo && qty > 0) hasValidRow = true;
    });
    if (!hasValidRow) {
        alert('Vui lòng chọn ít nhất một sản phẩm hợp lệ (có lô và số lượng > 0).');
        return false;
    }
    return true;
}

// ── XEM TRƯỚC HÓA ĐƠN XUẤT (INVOICE PREVIEW CỘT PHẢI) ──────────────────
function syncOutboundInvoicePreview() {
    const supSelect = document.getElementById('outboundSupplier');
    const prevSupName = document.getElementById('prevSupplierName');
    if (supSelect && prevSupName) {
        prevSupName.innerText = supSelect.selectedIndex > 0 ? supSelect.options[supSelect.selectedIndex].text : 'Chưa chọn đối tác';
    }

    const refInput = document.getElementById('refNoInput');
    const prevRefNo = document.getElementById('prevRefNo');
    if (refInput && prevRefNo) {
        prevRefNo.innerText = refInput.value.trim() ? refInput.value.trim() : 'OUT-AUTO';
    }

    const dateInput = document.getElementById('createdDateInput');
    const prevDate = document.getElementById('prevCreatedDate');
    if (dateInput && prevDate && dateInput.value) {
        const parts = dateInput.value.split('-');
        if (parts.length === 3) prevDate.innerText = `${parts[2]}/${parts[1]}/${parts[0]}`;
    }

    const prevList = document.getElementById('prevItemsList');
    const rows = document.querySelectorAll('#outboundItemsBody .item-row');
    if (!prevList) return;

    let itemsHtml = '';
    let totalQty = 0;

    rows.forEach(row => {
        const name = row.querySelector('.product-autocomplete-outbound')?.value.trim();
        const batchSelect = row.querySelector('.batch-select-outbound');
        const batch = batchSelect && batchSelect.value ? batchSelect.value : '—';
        const qty = parseFloat(unformatNumber(row.querySelector('.qty-outbound')?.value || '0')) || 0;
        totalQty += qty;

        if (name) {
            itemsHtml += `
                <div class="inv-doc-table-row">
                    <span class="td-item">${escapeHtml(name)}</span>
                    <span class="td-batch">${escapeHtml(batch)}</span>
                    <span class="td-qty">${qty}</span>
                </div>
            `;
        }
    });

    prevList.innerHTML = itemsHtml || '<div class="prev-empty-hint">Chưa có dòng sản phẩm nào</div>';
    const totalEl = document.getElementById('prevGrandTotalQty');
    if (totalEl) totalEl.innerText = formatNumberInput(String(totalQty));

    const noteVal = document.getElementById('noteInput')?.value.trim();
    const prevNote = document.getElementById('prevNote');
    if (prevNote) {
        prevNote.innerText = noteVal ? noteVal : 'Cảm ơn bạn đã hợp tác cùng hệ thống quản lý chuỗi cung ứng SmartWare.';
    }
}

// ── XEM CHI TIẾT MODAL ──────────────────────────────────────────────────
let currentOutboundDetailData = null;

function viewOutboundDetail(id) {
    fetch(`process.php?action=get_outbound_detail&id=${encodeURIComponent(id)}`)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                currentOutboundDetailData = data;
                currentOutboundDetailData.id = id;
                renderOutboundDetailModal(data);
                document.getElementById('detailOutboundModal').style.display = 'flex';
            } else {
                alert('Không thể tải chi tiết phiếu xuất: ' + (data.message || ''));
            }
        })
        .catch(err => alert('Lỗi kết nối: ' + err.message));
}

function renderOutboundDetailModal(data) {
    let totalQuantity = 0;
    (data.items || []).forEach(item => { totalQuantity += parseFloat(item.quantity); });
    let stt = 1;
    let html = `
        <div class="info-details-box" style="margin-bottom:16px;">
            <div class="info-detail-row"><span class="row-label">Mã phiếu:</span><span class="row-value">${escapeHtml(data.ref_no)}</span></div>
            <div class="info-detail-row"><span class="row-label">Nhà cung cấp / Đối tác:</span><span class="row-value">${escapeHtml(data.supplier_name || '—')}</span></div>
            <div class="info-detail-row"><span class="row-label">Người tạo:</span><span class="row-value">${escapeHtml(data.user_name || '')}</span></div>
            <div class="info-detail-row"><span class="row-label">Ngày tạo:</span><span class="row-value">${data.created}</span></div>
            <div class="info-detail-row" style="grid-column: span 2;"><span class="row-label">Ghi chú:</span><span class="row-value">${escapeHtml(data.note || '—')}</span></div>
        </div>
        <div style="border:1px solid var(--tb-border); border-radius:10px; overflow:hidden;">
            <table class="task-data-table">
                <thead><tr>
                    <th class="text-center" width="40">STT</th>
                    <th>Sản phẩm</th>
                    <th>Lô xuất</th>
                    <th class="text-right" width="100">Số lượng</th>
                </tr></thead>
                <tbody>`;
    (data.items || []).forEach(item => {
        html += `<tr>
            <td class="text-center">${stt++}</td>
            <td><strong>${escapeHtml(item.product_name)}</strong></td>
            <td><code>${escapeHtml(item.batch_no || '—')}</code></td>
            <td class="text-right font-medium">${formatNumberInput(item.quantity)}</td>
        </tr>`;
    });
    html += `</tbody></table></div>
        <div style="display:flex; justify-content:flex-end; font-size:15px; font-weight:700; margin-top:14px;">
            Tổng số lượng xuất: <span style="color:#264B6F; margin-left:8px;">${formatNumberInput(totalQuantity)}</span>
        </div>`;
    document.getElementById('detailOutboundContent').innerHTML = html;
}

function closeDetailOutboundModal() {
    document.getElementById('detailOutboundModal').style.display = 'none';
}

function printOutboundDetail() {
    if (!currentOutboundDetailData) return;
    let totalQuantity = 0;
    currentOutboundDetailData.items.forEach(item => { totalQuantity += parseFloat(item.quantity); });
    let itemsHtml = '', stt = 1;
    currentOutboundDetailData.items.forEach(item => {
        itemsHtml += `<tr>
            <td style="text-align:center">${stt++}</td>
            <td>${escapeHtml(item.product_name)}</td>
            <td style="text-align:center">${escapeHtml(item.batch_no || '—')}</td>
            <td style="text-align:right">${formatNumberInput(item.quantity)}</td>
        </tr>`;
    });
    const html = `
    <div style="text-align:center;margin-bottom:20px">
        <div style="font-size:20px;font-weight:bold">PHIẾU XUẤT KHO</div>
        <div style="font-size:12px;color:#555;margin-top:5px">Mã phiếu: ${escapeHtml(currentOutboundDetailData.ref_no)} &nbsp;|&nbsp; Ngày tạo: ${currentOutboundDetailData.created}</div>
    </div>
    <div style="margin-bottom:20px">
        <p><strong>Nhà cung cấp / Đối tác:</strong> ${escapeHtml(currentOutboundDetailData.supplier_name || '')}</p>
        <p><strong>Người lập phiếu:</strong> ${escapeHtml(currentOutboundDetailData.user_name || '')}</p>
        <p><strong>Ghi chú:</strong> ${escapeHtml(currentOutboundDetailData.note || '')}</p>
    </div>
    <table border="1" cellpadding="6" cellspacing="0" style="width:100%;border-collapse:collapse">
        <thead><tr style="background:#F8F4E7"><th>STT</th><th>Sản phẩm</th><th>Số lô</th><th>SL</th></tr></thead>
        <tbody>${itemsHtml}</tbody>
    </table>
    <div style="margin-top:20px;border-top:1px solid #ddd;padding-top:10px;text-align:right">
        <strong>Tổng số lượng:</strong> ${formatNumberInput(totalQuantity)}
    </div>
    <div style="margin-top:80px;display:flex;justify-content:space-between">
        <div style="text-align:center;width:40%"><hr><p>Người nhận hàng<br>(Ký, họ tên)</p></div>
        <div style="text-align:center;width:40%"><hr><p>Thủ kho xuất<br>(Ký, họ tên)</p></div>
    </div>`;
    
    const iframe = document.createElement('iframe');
    iframe.style.cssText = 'position:absolute;width:0;height:0;border:none';
    document.body.appendChild(iframe);
    const doc = iframe.contentWindow.document;
    doc.open();
    doc.write(`<!DOCTYPE html><html><head><meta charset="UTF-8"><style>body{font-family:Arial,sans-serif;margin:20px}table{border-collapse:collapse}</style></head><body>${html}</body></html>`);
    doc.close();
    iframe.contentWindow.focus();
    iframe.contentWindow.print();
    setTimeout(() => document.body.removeChild(iframe), 1000);
}

function exportOutboundDetailExcel() {
    if (!currentOutboundDetailData) return;
    fetch('../../pages/export/Export.php?type=outbound_detail', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(currentOutboundDetailData)
    })
    .then(r => r.blob())
    .then(blob => {
        const url = URL.createObjectURL(blob);
        const a   = document.createElement('a');
        a.href    = url;
        a.download = `phieu_xuat_${currentOutboundDetailData.ref_no || 'detail'}_${new Date().toISOString().slice(0, 10)}.xlsx`;
        document.body.appendChild(a); a.click(); document.body.removeChild(a);
        URL.revokeObjectURL(url);
    })
    .catch(err => alert('Lỗi xuất file: ' + err));
}

function exportOutboundExcel() {
    const params = new URLSearchParams(window.location.search);
    window.location.href = '../../pages/export/Export.php?type=outbound_list&' + params.toString();
}

let _searchTimer = null;
function searchOutboundTable() {
    clearTimeout(_searchTimer);
    _searchTimer = setTimeout(() => {
        const keyword = document.getElementById('searchInput')?.value.trim() ?? '';
        const params  = new URLSearchParams(window.location.search);
        params.delete('page');
        keyword !== '' ? params.set('keyword', keyword) : params.delete('keyword');
        window.location.href = 'index.php?' + params.toString();
    }, 350);
}

function confirmDeleteOutbound(id) {
    if (!confirm('Bạn có chắc muốn xóa phiếu xuất tạm này?')) return;
    const formData = new FormData();
    formData.append('action', 'delete_outbound');
    formData.append('id', id);
    formData.append('csrf_token', getCsrfToken());

    fetch('process.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if (data.ok) {
                const row = document.querySelector(`input.row-checkbox[data-id="${id}"]`)?.closest('tr');
                if (row) row.remove();
                else window.location.reload();
            } else {
                alert('Lỗi: ' + (data.message || 'Không thể xóa phiếu'));
            }
        })
        .catch(err => alert('Lỗi kết nối: ' + err.message));
}

// ── SỬA PHIẾU TẠM MODAL (EDIT) ───────────────────────────────────────────
function editOutbound(id) {
    fetch(`process.php?action=get_outbound_detail&id=${encodeURIComponent(id)}`)
        .then(res => res.json())
        .then(data => {
            if (!data.success) { alert('Không thể tải phiếu xuất để sửa'); return; }

            document.getElementById('editOutboundId').value = id;
            document.getElementById('editRefNo').value     = data.ref_no || '';
            document.getElementById('editNote').value      = data.note || '';
            document.getElementById('editStatus').value    = data.status || 'pending';

            const supSel = document.getElementById('editOutboundSupplierId');
            if (supSel) supSel.value = data.supplier_id || '';

            const tbody = document.getElementById('editOutboundItemsBody');
            if (tbody) {
                tbody.innerHTML = '';
                if (data.items && data.items.length) {
                    data.items.forEach(item => addEditOutboundRowWithData(item));
                } else {
                    addEditOutboundRow();
                }
            }
            updateEditOutboundStt();
            document.getElementById('editOutboundModal').style.display = 'flex';
        })
        .catch(err => alert('Lỗi kết nối: ' + err.message));
}

function closeEditOutboundModal() {
    document.getElementById('editOutboundModal').style.display = 'none';
}

function getEmptyEditOutboundRow() {
    const template = document.getElementById('outboundRowTemplateEdit');
    if (template) {
        const newRow = template.content.cloneNode(true).querySelector('tr');
        newRow.querySelectorAll('input, select').forEach(el => { el.value = ''; });
        const qty = newRow.querySelector('.qty-outbound');
        if (qty) qty.value = '1';
        return newRow;
    }
    return document.createElement('tr');
}

function addEditOutboundRow() {
    const tbody = document.getElementById('editOutboundItemsBody');
    if (!tbody) return;
    const newRow = getEmptyEditOutboundRow();
    tbody.appendChild(newRow);
    attachEditOutboundRowEvents(newRow);
    const acInput = newRow.querySelector('.product-autocomplete-outbound');
    if (acInput && typeof initAutocomplete === 'function') {
        initAutocomplete(acInput, {
            searchUrl: `process.php?action=search_products&supplier_id=${getOutboundSupplierId()}`,
            onSelect: (product, row) => loadBatchesForOutboundRow(row, product.id),
            position: 'above',
            minChars: 2
        });
    }
    updateEditOutboundStt();
}

function addEditOutboundRowWithData(item) {
    const tbody = document.getElementById('editOutboundItemsBody');
    if (!tbody) return;
    const newRow = getEmptyEditOutboundRow();
    newRow.querySelector('.product-autocomplete-outbound').value = item.product_name || '';
    newRow.querySelector('.product-id').value                   = item.product_id || '';
    newRow.querySelector('.qty-outbound').value                 = formatNumberInput(String(item.quantity || 1));
    tbody.appendChild(newRow);
    attachEditOutboundRowEvents(newRow);
    loadBatchesForOutboundRow(newRow, item.product_id, item.batch_no);
    const acInput = newRow.querySelector('.product-autocomplete-outbound');
    if (acInput && typeof initAutocomplete === 'function') {
        initAutocomplete(acInput, {
            searchUrl: `process.php?action=search_products&supplier_id=${getOutboundSupplierId()}`,
            onSelect: (product, row) => loadBatchesForOutboundRow(row, product.id),
            position: 'above',
            minChars: 2
        });
    }
    updateEditOutboundStt();
}

function removeEditOutboundRow(btn) {
    const rows = document.querySelectorAll('#editOutboundItemsBody .item-row');
    if (rows.length > 1) {
        btn.closest('tr').remove();
        updateEditOutboundStt();
    } else {
        alert('Phải có ít nhất một dòng sản phẩm');
    }
}

function updateEditOutboundStt() {
    document.querySelectorAll('#editOutboundItemsBody .item-row').forEach((row, idx) => {
        const cell = row.querySelector('.stt-cell');
        if (cell) cell.textContent = idx + 1;
    });
}

function attachEditOutboundRowEvents(row) {
    const qty         = row.querySelector('.qty-outbound');
    const batchSelect = row.querySelector('.batch-select-outbound');

    if (qty && qty.type === 'number') qty.type = 'text';

    if (qty) {
        qty.addEventListener('blur', function () {
            let val = unformatNumber(this.value);
            if (isNaN(val) || val <= 0) val = 1;
            const opt   = batchSelect?.options[batchSelect.selectedIndex];
            const stock = parseInt(opt?.getAttribute('data-stock'), 10);
            if (stock && val > stock) {
                alert(`Số lượng (${val}) vượt quá tồn kho lô (${stock})`);
                val = stock;
            }
            this.value = formatNumberInput(val.toString());
        });
    }
}

function validateEditOutboundFormBeforeSubmit() {
    const supId = document.getElementById('editOutboundSupplierId')?.value;
    if (!supId) {
        alert('Vui lòng chọn nhà cung cấp');
        return false;
    }
    const rows = document.querySelectorAll('#editOutboundItemsBody .item-row');
    if (rows.length === 0) {
        alert('Phải có ít nhất một dòng sản phẩm');
        return false;
    }
    document.querySelectorAll('#editOutboundItemsBody .qty-outbound').forEach(el => {
        el.value = unformatNumber(el.value);
    });
    return true;
}

// ── BULK ACTION BAR ──────────────────────────────────────────────────────
function updateOutboundBulkBar() {
    const checked = document.querySelectorAll('#outboundTable .row-checkbox:checked');
    const bar     = document.getElementById('bulkActionBar');
    const count   = document.getElementById('bulkCount');
    if (!bar) return;
    bar.style.display = checked.length > 0 ? 'flex' : 'none';
    if (count) count.textContent = `${checked.length} phiếu xuất đã chọn`;
}

function clearOutboundSelection() {
    document.querySelectorAll('#outboundTable .row-checkbox').forEach(cb => cb.checked = false);
    const selectAll = document.getElementById('selectAll');
    if (selectAll) { selectAll.checked = false; selectAll.indeterminate = false; }
    updateOutboundBulkBar();
}

function exportSelectedOutboundExcel() {
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

// ── BỘ CHỌN LỊCH NEO-DATEPICKER ──────────────────────────────────────────
let _calCurrentYear = 2026;
let _calCurrentMonth = 9; // 0-based: Tháng 10
let _calRangeStart = null;
let _calRangeEnd = null;

function toggleDatePickerPopover(e) {
    if (e) e.stopPropagation();
    const pop = document.getElementById('neoDatePickerPopover');
    if (!pop) return;
    pop.style.display = pop.style.display === 'block' ? 'none' : 'block';
}

function selectQuickDate(type) {
    const fromInput = document.getElementById('neoDateFrom');
    const toInput = document.getElementById('neoDateTo');
    const today = new Date();
    let fromDate = new Date();
    let toDate = new Date();

    if (type === 'today') {
        // Hôm nay
    } else if (type === 'yesterday') {
        fromDate.setDate(today.getDate() - 1);
        toDate.setDate(today.getDate() - 1);
    } else if (type === 'last7') {
        fromDate.setDate(today.getDate() - 7);
    } else if (type === 'last14') {
        fromDate.setDate(today.getDate() - 14);
    } else if (type === 'last30') {
        fromDate.setDate(today.getDate() - 30);
    }

    const fmt = d => d.toISOString().split('T')[0];
    if (fromInput) fromInput.value = fmt(fromDate);
    if (toInput) toInput.value = fmt(toDate);

    document.getElementById('filterForm')?.submit();
}

function openCalendarPicker() {
    const presets = document.getElementById('datepickerPresetList');
    const panel = document.getElementById('datepickerCalendarPanel');
    if (presets) presets.style.display = 'none';
    if (panel) panel.style.display = 'block';
    renderCalendarGrid();
}

function backToPresets() {
    const presets = document.getElementById('datepickerPresetList');
    const panel = document.getElementById('datepickerCalendarPanel');
    if (presets) presets.style.display = 'block';
    if (panel) panel.style.display = 'none';
}

function changeMonth(delta) {
    _calCurrentMonth += delta;
    if (_calCurrentMonth > 11) { _calCurrentMonth = 0; _calCurrentYear++; }
    if (_calCurrentMonth < 0) { _calCurrentMonth = 11; _calCurrentYear--; }
    renderCalendarGrid();
}

function renderCalendarGrid() {
    const lbl = document.getElementById('calMonthLabel');
    if (lbl) lbl.textContent = `Tháng ${_calCurrentMonth + 1} ${_calCurrentYear}`;

    const grid = document.getElementById('calDaysGrid');
    if (!grid) return;
    grid.innerHTML = '';

    const firstDay = new Date(_calCurrentYear, _calCurrentMonth, 1).getDay();
    const daysInMonth = new Date(_calCurrentYear, _calCurrentMonth + 1, 0).getDate();

    for (let i = 0; i < firstDay; i++) {
        const emptyCell = document.createElement('span');
        grid.appendChild(emptyCell);
    }

    for (let d = 1; d <= daysInMonth; d++) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.textContent = d;
        btn.className = 'cal-day-btn';
        btn.onclick = () => onSelectCalDay(new Date(_calCurrentYear, _calCurrentMonth, d));
        grid.appendChild(btn);
    }
}

function onSelectCalDay(date) {
    const hint = document.getElementById('calRangeHint');
    if (!_calRangeStart || (_calRangeStart && _calRangeEnd)) {
        _calRangeStart = date;
        _calRangeEnd = null;
        if (hint) hint.textContent = `Bắt đầu: ${date.toLocaleDateString('vi-VN')}`;
    } else {
        if (date < _calRangeStart) {
            _calRangeEnd = _calRangeStart;
            _calRangeStart = date;
        } else {
            _calRangeEnd = date;
        }
        if (hint) hint.textContent = `${_calRangeStart.toLocaleDateString('vi-VN')} - ${_calRangeEnd.toLocaleDateString('vi-VN')}`;
    }
}

function applyCustomRange() {
    if (!_calRangeStart) return;
    const fmt = d => d.toISOString().split('T')[0];
    const fromInput = document.getElementById('neoDateFrom');
    const toInput = document.getElementById('neoDateTo');
    if (fromInput) fromInput.value = fmt(_calRangeStart);
    if (toInput) toInput.value = fmt(_calRangeEnd || _calRangeStart);
    document.getElementById('filterForm')?.submit();
}

// ── DOM INITIALIZATION ──────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
    const pageType     = document.body.dataset.page || '';
    const isCreatePage = pageType === 'outbound-create';
    const isIndexPage  = pageType === 'outbound-index';

    // 1. Sidebar Lọc ẩn/hiện
    const toggleBtn     = document.getElementById('btnToggleSidebar');
    const filterSidebar = document.getElementById('taskFilterSidebar');
    const toggleTxt     = document.getElementById('txtToggleSidebar');

    const isFilterHidden = localStorage.getItem('outbound_filter_hidden') === 'true';
    if (toggleTxt) toggleTxt.textContent = isFilterHidden ? 'Show Filters' : 'Hide Filters';
    if (isFilterHidden && filterSidebar) filterSidebar.classList.add('hidden');

    if (toggleBtn && filterSidebar) {
        toggleBtn.addEventListener('click', function () {
            const willHide = !filterSidebar.classList.contains('hidden');
            if (willHide) {
                filterSidebar.classList.add('hidden');
                document.documentElement.classList.add('inbound-filter-hidden');
                if (toggleTxt) toggleTxt.textContent = 'Show Filters';
            } else {
                filterSidebar.classList.remove('hidden');
                document.documentElement.classList.remove('inbound-filter-hidden');
                if (toggleTxt) toggleTxt.textContent = 'Hide Filters';
            }
            localStorage.setItem('outbound_filter_hidden', willHide);
        });
    }

    // 2. Xử lý checkbox bulk action
    const table = document.getElementById('outboundTable');
    if (table) {
        const selectAllCb = document.getElementById('selectAll');
        if (selectAllCb) {
            selectAllCb.addEventListener('change', function () {
                table.querySelectorAll('.row-checkbox').forEach(cb => cb.checked = this.checked);
                updateOutboundBulkBar();
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
                updateOutboundBulkBar();
            });
        });
    }

    // 3. Xử lý trang Create
    if (isCreatePage) {
        const supplierSelect = document.getElementById('outboundSupplier');
        if (supplierSelect) {
            supplierSelect.dataset.prev = supplierSelect.value;
            supplierSelect.addEventListener('change', () => onOutboundSupplierChange(supplierSelect));
        }

        const tbody = document.getElementById('outboundItemsBody');
        if (tbody && tbody.children.length === 0) {
            addOutboundRow();
        }

        const outboundForm = document.getElementById('outboundForm');
        if (outboundForm) {
            outboundForm.addEventListener('submit', function (e) {
                if (!validateOutboundFormBeforeSubmit()) { e.preventDefault(); return; }
                document.querySelectorAll('#outboundItemsBody .qty-outbound').forEach(el => {
                    el.value = unformatNumber(el.value);
                });
                localStorage.removeItem('outbound_draft');
            });
        }
    }

    // 4. Đóng popover / modal khi click ra ngoài
    window.addEventListener('click', function (e) {
        const pop = document.getElementById('neoDatePickerPopover');
        const trigger = document.querySelector('.neo-datepicker-trigger');
        if (pop && pop.style.display === 'block' && !pop.contains(e.target) && !trigger?.contains(e.target)) {
            pop.style.display = 'none';
        }

        if (e.target === document.getElementById('detailOutboundModal')) closeDetailOutboundModal();
        if (e.target === document.getElementById('editOutboundModal'))   closeEditOutboundModal();
    });
});

// Gán toàn bộ hàm vào window object
window.addOutboundRow                   = addOutboundRow;
window.removeOutboundRow                = removeOutboundRow;
window.clearAllOutboundRows             = clearAllOutboundRows;
window.resetOutboundForm                = resetOutboundForm;
window.loadBatchesForOutboundRow        = loadBatchesForOutboundRow;
window.saveOutboundDraft                = saveOutboundDraft;
window.validateOutboundFormBeforeSubmit = validateOutboundFormBeforeSubmit;
window.syncOutboundInvoicePreview       = syncOutboundInvoicePreview;
window.viewOutboundDetail               = viewOutboundDetail;
window.closeDetailOutboundModal         = closeDetailOutboundModal;
window.printOutboundDetail              = printOutboundDetail;
window.exportOutboundDetailExcel        = exportOutboundDetailExcel;
window.exportOutboundExcel              = exportOutboundExcel;
window.searchOutboundTable              = searchOutboundTable;
window.confirmDeleteOutbound            = confirmDeleteOutbound;
window.editOutbound                     = editOutbound;
window.closeEditOutboundModal           = closeEditOutboundModal;
window.addEditOutboundRow               = addEditOutboundRow;
window.removeEditOutboundRow            = removeEditOutboundRow;
window.validateEditOutboundFormBeforeSubmit = validateEditOutboundFormBeforeSubmit;
window.updateOutboundBulkBar            = updateOutboundBulkBar;
window.clearOutboundSelection           = clearOutboundSelection;
window.exportSelectedOutboundExcel       = exportSelectedOutboundExcel;
window.toggleDatePickerPopover          = toggleDatePickerPopover;
window.selectQuickDate                  = selectQuickDate;
window.openCalendarPicker               = openCalendarPicker;
window.backToPresets                    = backToPresets;
window.changeMonth                      = changeMonth;
window.applyCustomRange                 = applyCustomRange;