//  inbound.js

'use strict';

function getCsrfToken() {
    return document.querySelector('input[name="_csrf_token"]')?.value || '';
}

function appendCsrf(formData) {
    formData.set('_csrf_token', getCsrfToken());
    return formData;
}

function getInboundAutocompleteOptions() {
    return {
        searchUrl: 'process.php?action=search_products',
        onSelect: (product, row) => {
            const priceEl = row.querySelector('.price');
            if (priceEl && !unformatNumber(priceEl.value)) {
                priceEl.value = formatNumberInput(product.price.toString());
            }
            calculateInboundRow(row);
            if (typeof saveInboundDraft === 'function') saveInboundDraft();
        },
        position: 'above',
        minChars: 2
    };
}

function calculateInboundRow(row) {
    calculateRow(row, {
        qtySelector: '.qty',
        priceSelector: '.price',
        totalSelector: '.row-total',
        onAfter: calculateInboundTotal
    });
}

function calculateInboundTotal() {
    calculateTotal({
        bodySelector: '#itemsBody',
        totalSelector: '.row-total',
        displayId: 'totalAmountDisplay',
        hiddenId: 'totalAmountInput'
    });
    const total = unformatNumber(document.getElementById('totalAmountDisplay')?.textContent || '0');
    const payEl = document.getElementById('payAmount');
    if (payEl) payEl.textContent = formatNumberInput(total.toString());
}

function getEmptyInboundRow() {
    const template = document.getElementById('rowTemplate');
    if (template) {
        const newRow = template.content.cloneNode(true).querySelector('tr');
        newRow.querySelectorAll('input, select').forEach(el => { el.value = ''; });
        newRow.querySelector('.qty').value = '1';
        return newRow;
    }
    const tr = document.createElement('tr');
    tr.className = 'item-row';
    tr.innerHTML = `
        <td class="stt-cell"></td>
        <td style="min-width:200px;">
            <input type="text" class="product-autocomplete" name="product_name[]" placeholder="Nhập tên hoặc SKU" autocomplete="off" style="width:100%">
            <input type="hidden" name="product_id[]" class="product-id">
            <div class="product-info" style="font-size:11px;color:#475569;margin-top:4px"></div>
        </td>
        <td><input type="text" name="batch_no[]" required placeholder="Lô" style="width:100%"></td>
        <td><input type="date" name="mfg_date[]" style="width:100%"></td>
        <td><input type="date" name="exp_date[]" required style="width:100%"></td>
        <td><input type="text" name="quantity[]" class="qty" value="1" min="1" required style="width:80px"></td>
        <td><input type="text" name="unit_price[]" class="price" step="1000" required style="width:120px"></td>
        <td><input type="text" class="row-total" readonly style="width:100px"></td>
        <td><button type="button" class="btn-icon remove-row" onclick="removeInboundRow(this)"><i class="ri-delete-bin-line"></i></button></td>
    `;
    return tr;
}

function addInboundRow() {
    const tbody = document.getElementById('itemsBody');
    if (!tbody) return;
    const newRow = getEmptyInboundRow();
    tbody.appendChild(newRow);
    attachInboundRowEvents(newRow);
    initAutocomplete(newRow.querySelector('.product-autocomplete'), getInboundAutocompleteOptions());
    updateStt();
    saveInboundDraft();
}

function addInboundRowWithData(item) {
    const tbody = document.getElementById('itemsBody');
    if (!tbody) return;
    const newRow = getEmptyInboundRow();
    newRow.querySelector('.product-autocomplete').value    = item.product_name || '';
    newRow.querySelector('.product-id').value              = item.product_id || '';
    newRow.querySelector('input[name="batch_no[]"]').value = item.batch_no || '';
    newRow.querySelector('input[name="mfg_date[]"]').value = item.mfg_date || '';
    newRow.querySelector('input[name="exp_date[]"]').value = item.exp_date || '';
    newRow.querySelector('.qty').value                     = formatNumberInput(item.quantity.toString());
    newRow.querySelector('.price').value                   = formatNumberInput(item.unit_price.toString());
    tbody.appendChild(newRow);
    attachInboundRowEvents(newRow);
    calculateInboundRow(newRow);
    initAutocomplete(newRow.querySelector('.product-autocomplete'), getInboundAutocompleteOptions());
    updateStt();
    saveInboundDraft();
}

function removeInboundRow(btn) {
    const rows = document.querySelectorAll('#itemsBody .item-row');
    if (rows.length > 1) {
        btn.closest('tr').remove();
        updateStt();
        calculateInboundTotal();
        saveInboundDraft();
    } else {
        alert('Phải có ít nhất một dòng sản phẩm');
    }
}

function clearAllInboundRows() {
    const tbody = document.getElementById('itemsBody');
    if (tbody && confirm('Xóa tất cả các dòng sản phẩm?')) {
        tbody.innerHTML = '';
        addInboundRow();
        calculateInboundTotal();
        saveInboundDraft();
    }
}

function resetInboundForm() {
    if (confirm('Làm mới sẽ xóa tất cả dữ liệu đang nhập. Bạn có chắc?')) {
        const form = document.getElementById('inboundForm');
        if (form) form.reset();
        const statusEl = document.getElementById('inboundStatus');
        if (statusEl) statusEl.value = 'completed';
        const itemsBody = document.getElementById('itemsBody');
        if (itemsBody) itemsBody.innerHTML = '';
        addInboundRow();
        calculateInboundTotal();
    }
}

function attachInboundRowEvents(row) {
    attachRowEvents(row, {
        qtySelector: '.qty',
        priceSelector: '.price',
        totalSelector: '.row-total',
        onAfterCalc: () => {
            calculateInboundTotal();
            saveInboundDraft();
        },
        onQtyBlur: (row, val) => {
            if (val <= 0) return 1;
            return val;
        },
        onPriceBlur: (row, val) => {
            const priceEl = row.querySelector('.price');
            if (val <= 0) {
                priceEl.style.border = '2px solid red';
                priceEl.title = 'Đơn giá phải lớn hơn 0';
            } else {
                priceEl.style.border = '';
                priceEl.title = '';
            }
            return val;
        }
    });

    const expDate = row.querySelector('[name="exp_date[]"]');
    if (expDate) {
        expDate.addEventListener('change', () => {
            checkExpiryWarning(expDate);
            saveInboundDraft();
        });
    }
}

function saveInboundDraft() {
}

function validateInboundFormBeforeSubmit() {
    const supplier = document.querySelector('#inboundForm [name="supplier_id"]');
    if (!supplier?.value) {
        alert('Vui lòng chọn nhà cung cấp');
        supplier?.focus();
        return false;
    }

    const rows = document.querySelectorAll('#itemsBody .item-row');
    if (rows.length === 0) {
        alert('Phải có ít nhất một dòng sản phẩm');
        return false;
    }

    let isValid = true;
    rows.forEach(row => {
        row.style.outline = '';
        const result = validateItemRow(row, {
            requireBatch: true,
            requireExp: true,
            requirePrice: true,
            qtySelector: '.qty',
            priceSelector: '.price',
            batchSelector: '[name="batch_no[]"]',
            expSelector: '[name="exp_date[]"]'
        });
        if (!result.valid) {
            isValid = false;
            row.style.outline = '2px solid #ef4444';
            row.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    });

    if (!isValid) {
        alert('Vui lòng kiểm tra lại các dòng được viền đỏ.');
        return false;
    }

    document.querySelectorAll('#itemsBody .price').forEach(el => { el.value = unformatNumber(el.value); });
    document.querySelectorAll('#itemsBody .qty').forEach(el => { el.value = unformatNumber(el.value); });
    return true;
}

function submitNewProduct(event) {
    event.preventDefault();
    const form    = document.getElementById('productForm');
    const costEl  = document.getElementById('prodCostPrice');
    const priceEl = document.getElementById('prodPrice');
    if (costEl)  costEl.value  = unformatNumber(costEl.value);
    if (priceEl) priceEl.value = unformatNumber(priceEl.value);

    const formData = appendCsrf(new FormData(form));
    formData.append('action', 'add_product');

    fetch('process.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                addInboundRowWithData({
                    product_name: data.name,
                    product_id:   data.id,
                    unit_price:   data.price,
                    batch_no:     '',
                    mfg_date:     '',
                    exp_date:     '',
                    quantity:     1
                });
                if (typeof closeProductModal === 'function') closeProductModal();
                alert('Thêm sản phẩm thành công!');
            } else {
                alert('Lỗi: ' + (data.message || 'Không thể thêm sản phẩm'));
            }
        })
        .catch(err => { console.error(err); alert('Có lỗi xảy ra'); });
}

function openSupplierModal(action) {
    const modal = document.getElementById('supplierModal');
    if (!modal) return;
    if (action === 'add') {
        ['supName', 'supPhone', 'supEmail', 'supTaxCode', 'supAddress'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.value = '';
        });
        const province = document.getElementById('province');
        if (province) province.value = '';
        const ward = document.getElementById('ward');
        if (ward) { ward.innerHTML = '<option value="">-- Chọn phường/xã --</option>'; ward.disabled = true; }
        const statusEl = document.getElementById('supStatus');
        if (statusEl) statusEl.value = 'active';
    }
    modal.style.display = 'flex';
}

function closeSupplierModal() {
    const modal = document.getElementById('supplierModal');
    if (modal) modal.style.display = 'none';
}

function submitNewSupplier(event) {
    event.preventDefault();
    const formData = appendCsrf(new FormData(document.getElementById('supplierForm')));
    formData.append('action', 'add_supplier');

    fetch('process.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                ['supplierSelect', 'prodSupplier', 'editSupplierId'].forEach(selId => {
                    const sel = document.getElementById(selId);
                    if (!sel) return;
                    const opt = document.createElement('option');
                    opt.value = data.id;
                    opt.textContent = data.name;
                    sel.appendChild(opt);
                    if (selId === 'prodSupplier') sel.value = data.id;
                });
                closeSupplierModal();
                alert('Thêm nhà cung cấp thành công!');
            } else {
                alert('Lỗi: ' + (data.message || 'Không thể thêm nhà cung cấp'));
            }
        })
        .catch(err => { console.error(err); alert('Có lỗi xảy ra'); });
}

let currentInboundDetailData = null;

function viewInboundDetail(id) {
    fetch(`process.php?action=get_detail&id=${encodeURIComponent(id)}`)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                currentInboundDetailData = data;
                currentInboundDetailData.id = id;
                renderInboundDetailModal(data);
                document.getElementById('detailModal').style.display = 'flex';
            } else {
                alert('Không thể tải chi tiết: ' + (data.message || 'Lỗi không xác định'));
            }
        })
        .catch(err => { console.error(err); alert('Lỗi kết nối: ' + err.message); });
}

function renderInboundDetailModal(data) {
    let totalQuantity = 0;
    data.items.forEach(item => { totalQuantity += parseFloat(item.quantity); });
    let stt = 1;
    let html = `
        <div style="margin-bottom:20px">
            <p><strong>Mã phiếu:</strong> ${escapeHtml(data.ref_no)}</p>
            <p><strong>Nhà cung cấp:</strong> ${escapeHtml(data.supplier_name || '')}</p>
            <p><strong>Người tạo:</strong> ${escapeHtml(data.user_name || '')}</p>
            <p><strong>Ghi chú:</strong> ${escapeHtml(data.note || '')}</p>
            <p><strong>Ngày tạo:</strong> ${data.created}</p>
        </div>
        <table class="data-table">
            <thead><tr>
                <th class="text-center">STT</th><th>Sản phẩm</th><th>Danh mục</th>
                <th class="text-right">Lô</th><th class="text-right">HSD</th>
                <th class="text-right">SL</th><th class="text-right">Đơn giá</th><th class="text-right">Thành tiền</th>
            </tr></thead>
            <tbody>`;
    data.items.forEach(item => {
        html += `<tr>
            <td class="text-center">${stt++}</td>
            <td>${escapeHtml(item.product_name)}</td>
            <td>${escapeHtml(item.category_name || '')}</td>
            <td class="text-right">${escapeHtml(item.batch_no)}</td>
            <td class="text-right">${item.exp_date}</td>
            <td class="text-right">${formatNumberInput(item.quantity)}</td>
            <td class="text-right">${formatNumberInput(item.unit_price)} ₫</td>
            <td class="text-right">${formatNumberInput(item.total)} ₫</td>
        </tr>`;
    });
    html += `</tbody></table>
        <div style="margin-top:20px;border-top:1px solid #ddd;padding-top:10px">
            <div style="display:flex;justify-content:space-between;margin-bottom:5px"><strong>Tổng số lượng:</strong> <span>${formatNumberInput(totalQuantity)}</span></div>
            <div style="display:flex;justify-content:space-between;margin-bottom:5px"><strong>Tổng tiền hàng:</strong> <span>${formatNumberInput(data.total_amount)} ₫</span></div>
            <div style="display:flex;justify-content:space-between;margin-bottom:5px"><strong>Cần trả NCC:</strong> <span>${formatNumberInput(data.total_amount)} ₫</span></div>
        </div>`;
    document.getElementById('detailContent').innerHTML = html;
}

function closeInboundDetailModal() {
    document.getElementById('detailModal').style.display = 'none';
}

function printInboundDetail() {
    if (!currentInboundDetailData) return;
    let totalQuantity = 0;
    currentInboundDetailData.items.forEach(item => { totalQuantity += parseFloat(item.quantity); });
    let itemsHtml = '', stt = 1;
    currentInboundDetailData.items.forEach(item => {
        itemsHtml += `<tr>
            <td style="text-align:center">${stt++}</td>
            <td>${escapeHtml(item.product_name)}</td>
            <td>${escapeHtml(item.category_name || '')}</td>
            <td style="text-align:right">${escapeHtml(item.batch_no)}</td>
            <td style="text-align:right">${item.exp_date}</td>
            <td style="text-align:right">${formatNumberInput(item.quantity)}</td>
            <td style="text-align:right">${formatNumberInput(item.unit_price)} ₫</td>
            <td style="text-align:right">${formatNumberInput(item.total)} ₫</td>
        </tr>`;
    });
    const html = `
    <div style="text-align:center;margin-bottom:20px">
        <div style="font-size:20px;font-weight:bold">PHIẾU NHẬP KHO</div>
        <div style="font-size:12px;color:#555;margin-top:5px">Mã phiếu: ${escapeHtml(currentInboundDetailData.ref_no)} &nbsp;|&nbsp; Ngày tạo: ${currentInboundDetailData.created}</div>
    </div>
    <div style="margin-bottom:20px">
        <p><strong>Nhà cung cấp:</strong> ${escapeHtml(currentInboundDetailData.supplier_name || '')}</p>
        <p><strong>Người lập phiếu:</strong> ${escapeHtml(currentInboundDetailData.user_name || '')}</p>
        <p><strong>Ghi chú:</strong> ${escapeHtml(currentInboundDetailData.note || '')}</p>
    </div>
    <table border="1" cellpadding="5" cellspacing="0" style="width:100%;border-collapse:collapse">
        <thead><tr><th>STT</th><th>Sản phẩm</th><th>Danh mục</th><th>Lô</th><th>HSD</th><th>SL</th><th>Đơn giá</th><th>Thành tiền</th></tr></thead>
        <tbody>${itemsHtml}</tbody>
    </table>
    <div style="margin-top:20px;border-top:1px solid #ddd;padding-top:10px">
        <div><strong>Tổng số lượng:</strong> ${formatNumberInput(totalQuantity)}</div>
        <div><strong>Tổng tiền hàng:</strong> ${formatNumberInput(currentInboundDetailData.total_amount)} ₫</div>
    </div>
    <div style="margin-top:100px;display:flex;justify-content:space-between">
        <div style="text-align:center;width:45%"><hr><p>Nhà cung cấp<br>(Ký, họ tên)</p></div>
        <div style="text-align:center;width:45%"><hr><p>Người lập phiếu<br>(Ký, họ tên)</p></div>
    </div>`;
    _printInboundIframe(html);
}

function _printInboundIframe(html) {
    const iframe = document.createElement('iframe');
    iframe.style.cssText = 'position:absolute;width:0;height:0;border:none';
    document.body.appendChild(iframe);
    const doc = iframe.contentWindow.document;
    doc.open();
    doc.write(`<!DOCTYPE html><html><head><meta charset="UTF-8"><style>body{font-family:Arial;margin:20px}table{border-collapse:collapse}th,td{border:1px solid #000;padding:8px}</style></head><body>${html}</body></html>`);
    doc.close();
    iframe.contentWindow.focus();
    iframe.contentWindow.print();
    setTimeout(() => document.body.removeChild(iframe), 1000);
}

function exportInboundDetailExcel() {
    if (!currentInboundDetailData) return;
    fetch('../../pages/export/Export.php?type=inbound_detail', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(currentInboundDetailData)
    })
    .then(r => r.blob())
    .then(blob => {
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `phieu_nhap_${currentInboundDetailData.ref_no || 'detail'}_${new Date().toISOString().slice(0, 10)}.xlsx`;
        document.body.appendChild(a); a.click(); document.body.removeChild(a);
        URL.revokeObjectURL(url);
    })
    .catch(err => alert('Lỗi xuất file: ' + err));
}

function exportInboundExcel() {
    const params = new URLSearchParams(window.location.search);
    window.location.href = '../../pages/export/Export.php?type=inbound_list&' + params.toString();
}

function searchInboundTable() {
    const filter = document.getElementById('searchInput')?.value.toLowerCase() || '';
    document.querySelectorAll('#inboundTable tbody tr').forEach(row => {
        row.style.display = row.innerText.toLowerCase().includes(filter) ? '' : 'none';
    });
}

function confirmDeleteInbound(id) {
    if (!confirm('Xóa phiếu nhập này?')) return;

    const formData = new FormData();
    formData.append('action', 'delete_inbound');
    formData.append('id', id);
    formData.append('_csrf_token', getCsrfToken());

    fetch('process.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: formData
    })
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
    .catch(err => { console.error(err); alert('Lỗi kết nối: ' + err.message); });
}

function getEmptyEditInboundRow() {
    const template = document.getElementById('rowTemplateEdit');
    if (template) {
        const newRow = template.content.cloneNode(true).querySelector('tr');
        newRow.querySelectorAll('input, select').forEach(el => { el.value = ''; });
        newRow.querySelector('.qty').value = '1';
        return newRow;
    }
    const tr = document.createElement('tr');
    tr.className = 'item-row';
    tr.innerHTML = `
        <td class="stt-cell" style="text-align:center;"></td>
        <td style="min-width:200px;">
            <input type="text" class="product-autocomplete" name="product_name[]" placeholder="Nhập tên hoặc SKU" autocomplete="off" style="width:100%">
            <input type="hidden" name="product_id[]" class="product-id">
            <div class="product-info" style="font-size:11px;color:#475569;margin-top:4px"></div>
        </td>
        <td><input type="text" name="batch_no[]" required placeholder="Lô" style="width:100%"></td>
        <td><input type="date" name="mfg_date[]" style="width:100%"></td>
        <td><input type="date" name="exp_date[]" required style="width:100%"></td>
        <td><input type="text" name="quantity[]" class="qty" value="1" min="1" required style="width:80px"></td>
        <td><input type="text" name="unit_price[]" class="price" step="1000" required style="width:120px"></td>
        <td><input type="text" class="row-total" readonly style="width:100px"></td>
        <td><button type="button" class="btn-icon remove-row" onclick="removeEditInboundRow(this)"><i class="ri-delete-bin-line"></i></button></td>
    `;
    return tr;
}

function addEditInboundRow() {
    const tbody = document.getElementById('editItemsBody');
    if (!tbody) return;
    const newRow = getEmptyEditInboundRow();
    tbody.appendChild(newRow);
    attachEditInboundRowEvents(newRow);
    initAutocomplete(newRow.querySelector('.product-autocomplete'), getInboundAutocompleteOptions());
    updateEditStt();
}

function addEditInboundRowWithData(item) {
    const tbody = document.getElementById('editItemsBody');
    if (!tbody) return;
    const newRow = getEmptyEditInboundRow();
    if (!newRow) return;
    newRow.querySelector('.product-autocomplete').value    = item.product_name || '';
    newRow.querySelector('.product-id').value              = item.product_id || '';
    newRow.querySelector('input[name="batch_no[]"]').value = item.batch_no || '';
    newRow.querySelector('input[name="mfg_date[]"]').value = item.mfg_date || '';
    newRow.querySelector('input[name="exp_date[]"]').value = item.exp_date || '';
    newRow.querySelector('.qty').value                     = formatNumberInput(item.quantity.toString());
    newRow.querySelector('.price').value                   = formatNumberInput(item.unit_price.toString());
    tbody.appendChild(newRow);
    attachEditInboundRowEvents(newRow);
    calculateEditInboundRow(newRow);
    initAutocomplete(newRow.querySelector('.product-autocomplete'), getInboundAutocompleteOptions());
    updateEditStt();
}

function updateEditStt() {
    document.querySelectorAll('#editItemsBody .item-row').forEach((row, i) => {
        const cell = row.querySelector('.stt-cell');
        if (cell) cell.textContent = i + 1;
    });
}

function removeEditInboundRow(btn) {
    const rows = document.querySelectorAll('#editItemsBody .item-row');
    if (rows.length > 1) {
        btn.closest('tr').remove();
        updateEditStt();
        calculateEditInboundTotal();
    } else {
        alert('Phải có ít nhất một dòng sản phẩm');
    }
}

function attachEditInboundRowEvents(row) {
    attachRowEvents(row, {
        qtySelector: '.qty',
        priceSelector: '.price',
        totalSelector: '.row-total',
        onAfterCalc: calculateEditInboundTotal,
        onQtyBlur: (row, val) => val <= 0 ? 1 : val,
        onPriceBlur: (row, val) => {
            const priceEl = row.querySelector('.price');
            if (val <= 0) {
                priceEl.style.border = '2px solid red';
                priceEl.title = 'Đơn giá phải lớn hơn 0';
            } else {
                priceEl.style.border = '';
                priceEl.title = '';
            }
            return val;
        }
    });
    const expDate = row.querySelector('[name="exp_date[]"]');
    if (expDate) {
        expDate.addEventListener('change', () => checkExpiryWarning(expDate));
    }
}

function calculateEditInboundRow(row) {
    calculateRow(row, {
        qtySelector: '.qty',
        priceSelector: '.price',
        totalSelector: '.row-total',
        onAfter: calculateEditInboundTotal
    });
}

function calculateEditInboundTotal() {
    let total = 0;
    document.querySelectorAll('#editItemsBody .row-total').forEach(el => {
        total += unformatNumber(el.value || '0');
    });
    const formatted = formatNumberInput(total.toString());
    const displayEl = document.getElementById('editTotalAmountDisplay');
    const payEl     = document.getElementById('editPayAmount');
    const hiddenEl  = document.getElementById('editTotalAmount');
    if (displayEl) displayEl.textContent = formatted + ' đ';
    if (payEl)     payEl.textContent     = formatted + ' đ';
    if (hiddenEl)  hiddenEl.value        = total;
}

function closeEditInboundModal() {
    document.getElementById('editInboundModal').style.display = 'none';
}

function editInbound(id) {
    fetch(`process.php?action=get_detail&id=${encodeURIComponent(id)}`)
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                alert('Không thể tải dữ liệu phiếu nhập để sửa');
                return;
            }

            document.getElementById('editInboundId').value = id;
            document.getElementById('editRefNo').value     = data.ref_no || '';
            document.getElementById('editNote').value      = data.note || '';
            document.getElementById('editStatus').value    = data.status || 'pending';

            const createdEl = document.getElementById('editCreatedDate');
            if (createdEl && data.created) {
                createdEl.textContent = new Date(data.created).toLocaleDateString('vi-VN');
            }

            const supSel = document.getElementById('editSupplierId');
            if (supSel) {
                supSel.value = data.supplier_id || '';
                const boxName = document.getElementById('editBoxSupName');
                const infoBox = document.getElementById('editSupplierInfoBox');
                if (supSel.value && boxName && infoBox) {
                    boxName.textContent   = supSel.options[supSel.selectedIndex]?.text || '';
                    infoBox.style.display = 'block';
                } else if (infoBox) {
                    infoBox.style.display = 'none';
                }
            }

            const tbody = document.getElementById('editItemsBody');
            if (tbody) {
                tbody.innerHTML = '';
                if (data.items?.length) {
                    data.items.forEach(item => addEditInboundRowWithData(item));
                } else {
                    addEditInboundRow();
                }
            }
            updateEditStt();
            calculateEditInboundTotal();
            document.getElementById('editInboundModal').style.display = 'flex';
        })
        .catch(err => { console.error(err); alert('Lỗi tải dữ liệu: ' + err.message); });
}

function validateEditInboundFormBeforeSubmit() {
    const supplierId = document.getElementById('editSupplierId')?.value;
    if (!supplierId) {
        alert('Vui lòng chọn nhà cung cấp');
        return false;
    }
    const rows = document.querySelectorAll('#editItemsBody .item-row');
    if (rows.length === 0) {
        alert('Phải có ít nhất một dòng sản phẩm');
        return false;
    }
    let isValid = true;
    rows.forEach(row => {
        row.style.outline = '';
        const result = validateItemRow(row, {
            requireBatch: true,
            requireExp: true,
            requirePrice: true,
            qtySelector: '.qty',
            priceSelector: '.price',
            batchSelector: '[name="batch_no[]"]',
            expSelector: '[name="exp_date[]"]'
        });
        if (!result.valid) {
            isValid = false;
            row.style.outline = '2px solid #ef4444';
            row.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    });
    if (!isValid) {
        alert('Vui lòng kiểm tra lại các dòng được viền đỏ.');
        return false;
    }
    document.querySelectorAll('#editItemsBody .price').forEach(el => { el.value = unformatNumber(el.value); });
    document.querySelectorAll('#editItemsBody .qty').forEach(el => { el.value = unformatNumber(el.value); });
    return true;
}
function updateInboundBulkBar() {
    const checked = document.querySelectorAll('#inboundTable .row-checkbox:checked');
    const bar     = document.getElementById('bulkActionBar');
    const count   = document.getElementById('bulkCount');
    if (!bar) return;
    bar.style.display = checked.length > 0 ? 'flex' : 'none';
    if (count) count.textContent = `${checked.length} phiếu nhập đã chọn`;
}
 
function clearInboundSelection() {
    document.querySelectorAll('#inboundTable .row-checkbox')
        .forEach(cb => cb.checked = false);
    const selectAll = document.getElementById('selectAll');
    if (selectAll) { selectAll.checked = false; selectAll.indeterminate = false; }
    updateInboundBulkBar();
}
 
function exportSelectedInboundExcel() {
    const ids = [...document.querySelectorAll('#inboundTable .row-checkbox:checked')]
        .map(cb => cb.dataset.id)
        .filter(Boolean);
 
    if (ids.length === 0) {
        alert('Chưa chọn phiếu nhập nào.');
        return;
    }
 
    const params = new URLSearchParams(window.location.search);
    ids.forEach(id => params.append('selected_ids[]', id));
    window.location.href = '../../pages/export/Export.php?type=inbound_list&' + params.toString();
}
 
document.addEventListener('DOMContentLoaded', function () {
    const table = document.getElementById('inboundTable');
    if (!table) return;
 
    const selectAllCb = document.getElementById('selectAll');
 
    if (selectAllCb) {
        selectAllCb.addEventListener('change', function () {
            table.querySelectorAll('.row-checkbox')
                .forEach(cb => cb.checked = this.checked);
            updateInboundBulkBar();
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
            updateInboundBulkBar();
        });
    });
});
document.addEventListener('DOMContentLoaded', function () {
    const pageType     = document.body.dataset.page || '';
    const isCreatePage = pageType === 'inbound-create';
    const isIndexPage  = pageType === 'inbound-index';

    if (isCreatePage) {
        if (typeof loadAddressData === 'function') loadAddressData();

        const supplierForm = document.getElementById('supplierForm');
        if (supplierForm) {
            supplierForm.addEventListener('submit', submitNewSupplier);
        }

        const tbody = document.getElementById('itemsBody');
        if (tbody && tbody.children.length === 0) {
            addInboundRow();
        } else if (tbody) {
            document.querySelectorAll('#itemsBody .item-row').forEach(row => {
                attachInboundRowEvents(row);
                const autoInput = row.querySelector('.product-autocomplete');
                if (autoInput) initAutocomplete(autoInput, getInboundAutocompleteOptions());
            });
            updateStt();
            calculateInboundTotal();
        }

        ['[name="supplier_id"]', '[name="ref_no"]', '[name="note"]'].forEach(sel => {
            const el = document.querySelector(`#inboundForm ${sel}`);
            if (el) el.addEventListener(el.tagName === 'SELECT' ? 'change' : 'input', saveInboundDraft);
        });
    }

    if (isIndexPage) {
        const editSupSel = document.getElementById('editSupplierId');
        if (editSupSel) {
            editSupSel.addEventListener('change', function () {
                const boxName = document.getElementById('editBoxSupName');
                const infoBox = document.getElementById('editSupplierInfoBox');
                if (this.value && boxName && infoBox) {
                    boxName.textContent   = this.options[this.selectedIndex].text;
                    infoBox.style.display = 'block';
                } else if (infoBox) {
                    infoBox.style.display = 'none';
                }
            });
        }
    }

    window.addEventListener('click', function (e) {
        if (e.target === document.getElementById('detailModal'))       closeInboundDetailModal();
        if (e.target === document.getElementById('editInboundModal'))  closeEditInboundModal();
        if (e.target === document.getElementById('supplierModal'))      closeSupplierModal();
        if (e.target === document.getElementById('productModal') && typeof closeProductModal === 'function') closeProductModal();
        if (e.target === document.getElementById('categoryModal') && typeof closeCategoryModal === 'function') closeCategoryModal();
    });
});
// js/inbound.js
'use strict';

function getCsrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content || '';
}

function appendCsrf(formData) {
    formData.set('_csrf_token', getCsrfToken());
    return formData;
}

function getInboundAutocompleteOptions() {
    return {
        searchUrl: 'process.php?action=search_products',
        onSelect: (product, row) => {
            const priceEl = row.querySelector('.price');
            if (priceEl && !unformatNumber(priceEl.value)) {
                priceEl.value = formatNumberInput(product.price.toString());
            }
            calculateInboundRow(row);
        },
        position: 'above',
        minChars: 2
    };
}

function calculateInboundRow(row) {
    calculateRow(row, {
        qtySelector: '.qty',
        priceSelector: '.price',
        totalSelector: '.row-total',
        onAfter: calculateInboundTotal
    });
}

function calculateInboundTotal() {
    calculateTotal({
        bodySelector: '#itemsBody',
        totalSelector: '.row-total',
        displayId: 'totalAmountDisplay',
        hiddenId: 'totalAmountInput'
    });
    const total = unformatNumber(document.getElementById('totalAmountDisplay')?.textContent || '0');
    const payEl = document.getElementById('payAmount');
    if (payEl) payEl.textContent = formatNumberInput(total.toString()) + ' ₫';
}

function getEmptyInboundRow() {
    const template = document.getElementById('rowTemplate');
    if (template) {
        const newRow = template.content.cloneNode(true).querySelector('tr');
        newRow.querySelectorAll('input, select').forEach(el => { el.value = ''; });
        newRow.querySelector('.qty').value = '1';
        return newRow;
    }
    return document.createElement('tr');
}

function addInboundRow() {
    const tbody = document.getElementById('itemsBody');
    if (!tbody) return;
    const newRow = getEmptyInboundRow();
    tbody.appendChild(newRow);
    attachInboundRowEvents(newRow);
    initAutocomplete(newRow.querySelector('.product-autocomplete'), getInboundAutocompleteOptions());
    updateStt();
}

function removeInboundRow(btn) {
    const rows = document.querySelectorAll('#itemsBody .item-row');
    if (rows.length > 1) {
        btn.closest('tr').remove();
        updateStt();
        calculateInboundTotal();
    } else {
        alert('Phải có ít nhất một dòng sản phẩm');
    }
}

function attachInboundRowEvents(row) {
    attachRowEvents(row, {
        qtySelector: '.qty',
        priceSelector: '.price',
        totalSelector: '.row-total',
        onAfterCalc: calculateInboundTotal,
        onQtyBlur: (row, val) => val <= 0 ? 1 : val,
        onPriceBlur: (row, val) => val
    });
}

function updateStt() {
    document.querySelectorAll('#itemsBody .item-row').forEach((row, i) => {
        const cell = row.querySelector('.stt-cell');
        if (cell) cell.textContent = i + 1;
    });
}

function validateInboundFormBeforeSubmit() {
    const supplier = document.querySelector('#inboundForm [name="supplier_id"]');
    if (!supplier?.value) {
        alert('Vui lòng chọn nhà cung cấp');
        supplier?.focus();
        return false;
    }
    const rows = document.querySelectorAll('#itemsBody .item-row');
    if (rows.length === 0) {
        alert('Phải có ít nhất một dòng sản phẩm');
        return false;
    }
    return true;
}

// ── XỬ LÝ PHIẾU TẠM & MODAL EDIT ──────────────────────────────────────────
function editInbound(id) {
    fetch(`process.php?action=get_detail&id=${encodeURIComponent(id)}`)
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                alert('Không thể tải dữ liệu phiếu');
                return;
            }
            document.getElementById('editInboundId').value = id;
            document.getElementById('editRefNo').value     = data.ref_no || '';
            document.getElementById('editNote').value      = data.note || '';
            document.getElementById('editStatus').value    = data.status || 'pending';

            const supSel = document.getElementById('editSupplierId');
            if (supSel) supSel.value = data.supplier_id || '';

            const tbody = document.getElementById('editItemsBody');
            if (tbody) {
                tbody.innerHTML = '';
                if (data.items?.length) {
                    data.items.forEach(item => addEditInboundRowWithData(item));
                } else {
                    addEditInboundRow();
                }
            }
            calculateEditInboundTotal();
            document.getElementById('editInboundModal').style.display = 'flex';
        })
        .catch(err => alert('Lỗi kết nối: ' + err.message));
}

function addEditInboundRow() {
    const tbody = document.getElementById('editItemsBody');
    const tpl   = document.getElementById('rowTemplateEdit');
    if (!tbody || !tpl) return;
    const newRow = tpl.content.cloneNode(true).querySelector('tr');
    tbody.appendChild(newRow);
    attachEditInboundRowEvents(newRow);
    initAutocomplete(newRow.querySelector('.product-autocomplete'), getInboundAutocompleteOptions());
    updateEditStt();
}

function addEditInboundRowWithData(item) {
    const tbody = document.getElementById('editItemsBody');
    const tpl   = document.getElementById('rowTemplateEdit');
    if (!tbody || !tpl) return;
    const newRow = tpl.content.cloneNode(true).querySelector('tr');
    newRow.querySelector('.product-autocomplete').value    = item.product_name || '';
    newRow.querySelector('.product-id').value              = item.product_id || '';
    newRow.querySelector('input[name="batch_no[]"]').value = item.batch_no || '';
    newRow.querySelector('input[name="mfg_date[]"]').value = item.mfg_date || '';
    newRow.querySelector('input[name="exp_date[]"]').value = item.exp_date || '';
    newRow.querySelector('.qty').value                     = item.quantity;
    newRow.querySelector('.price').value                   = formatNumberInput(item.unit_price.toString());
    tbody.appendChild(newRow);
    attachEditInboundRowEvents(newRow);
    calculateRow(newRow, { qtySelector: '.qty', priceSelector: '.price', totalSelector: '.row-total', onAfter: calculateEditInboundTotal });
    initAutocomplete(newRow.querySelector('.product-autocomplete'), getInboundAutocompleteOptions());
    updateEditStt();
}

function removeEditInboundRow(btn) {
    btn.closest('tr').remove();
    updateEditStt();
    calculateEditInboundTotal();
}

function updateEditStt() {
    document.querySelectorAll('#editItemsBody .item-row').forEach((row, i) => {
        const cell = row.querySelector('.stt-cell');
        if (cell) cell.textContent = i + 1;
    });
}

function attachEditInboundRowEvents(row) {
    attachRowEvents(row, {
        qtySelector: '.qty',
        priceSelector: '.price',
        totalSelector: '.row-total',
        onAfterCalc: calculateEditInboundTotal
    });
}

function calculateEditInboundTotal() {
    let total = 0;
    document.querySelectorAll('#editItemsBody .row-total').forEach(el => {
        total += unformatNumber(el.value || '0');
    });
    const formatted = formatNumberInput(total.toString());
    const displayEl = document.getElementById('editTotalAmountDisplay');
    const hiddenEl  = document.getElementById('editTotalAmount');
    if (displayEl) displayEl.textContent = formatted + ' ₫';
    if (hiddenEl)  hiddenEl.value        = total;
}

function closeEditInboundModal() {
    document.getElementById('editInboundModal').style.display = 'none';
}

function validateEditInboundFormBeforeSubmit() {
    return true;
}

// ── BULK ACTION & SEARCH TABLE ──────────────────────────────────────────
let _searchTimer = null;
function searchInboundTable() {
    clearTimeout(_searchTimer);
    _searchTimer = setTimeout(() => {
        const keyword = document.getElementById('searchInput')?.value.trim() ?? '';
        const params  = new URLSearchParams(window.location.search);
        params.delete('page');
        keyword !== '' ? params.set('keyword', keyword) : params.delete('keyword');
        window.location.href = 'index.php?' + params.toString();
    }, 350);
}

function confirmDeleteInbound(id) {
    if (!confirm('Bạn có chắc muốn xóa phiếu nhập tạm này?')) return;
    const formData = new FormData();
    formData.append('action', 'delete_inbound');
    formData.append('id', id);
    formData.append('_csrf_token', getCsrfToken());

    fetch('process.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if (data.ok) window.location.reload();
            else alert('Lỗi: ' + data.message);
        });
}

function closeInboundDetailModal() {
    document.getElementById('detailModal').style.display = 'none';
}

function viewInboundDetail(id) {
    fetch(`process.php?action=get_detail&id=${encodeURIComponent(id)}`)
        .then(res => res.json())
        .then(data => {
            if (!data.success) { alert('Không tìm thấy phiếu'); return; }
            let itemsHtml = '';
            (data.items || []).forEach((it, idx) => {
                itemsHtml += `
                    <tr>
                        <td class="text-center">${idx + 1}</td>
                        <td><strong>${escapeHtml(it.product_name)}</strong></td>
                        <td><code>${escapeHtml(it.batch_no || '—')}</code></td>
                        <td>${it.exp_date || '—'}</td>
                        <td class="text-right">${formatNumberInput(it.quantity)}</td>
                        <td class="text-right">${formatNumberInput(it.unit_price)} ₫</td>
                        <td class="text-right font-price">${formatNumberInput(it.total)} ₫</td>
                    </tr>
                `;
            });
            document.getElementById('detailContent').innerHTML = `
                <div class="info-details-box" style="margin-bottom:16px;">
                    <div class="info-detail-row"><span class="row-label">Mã phiếu:</span><span class="row-value">${escapeHtml(data.ref_no)}</span></div>
                    <div class="info-detail-row"><span class="row-label">Nhà cung cấp:</span><span class="row-value">${escapeHtml(data.supplier_name || '—')}</span></div>
                    <div class="info-detail-row"><span class="row-label">Người tạo:</span><span class="row-value">${escapeHtml(data.user_name || '—')}</span></div>
                    <div class="info-detail-row"><span class="row-label">Ngày tạo:</span><span class="row-value">${data.created}</span></div>
                    <div class="info-detail-row" style="grid-column:span 2;"><span class="row-label">Ghi chú:</span><span class="row-value">${escapeHtml(data.note || '—')}</span></div>
                </div>
                <div style="border:1px solid var(--tb-border); border-radius:10px; overflow:hidden;">
                    <table class="task-data-table">
                        <thead><tr><th width="40" class="text-center">#</th><th>Sản phẩm</th><th>Lô</th><th>HSD</th><th class="text-right">SL</th><th class="text-right">Đơn giá</th><th class="text-right">Thành tiền</th></tr></thead>
                        <tbody>${itemsHtml}</tbody>
                    </table>
                </div>
                <div style="display:flex; justify-content:flex-end; font-size:16px; font-weight:700; margin-top:14px;">
                    Tổng tiền: <span style="color:#2563eb; margin-left:8px;">${formatNumberInput(data.total_amount)} ₫</span>
                </div>
            `;
            document.getElementById('detailModal').style.display = 'flex';
        });
}

// ── DOM INITIALIZATION ──────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
    // 1. Toggle Sidebar Filter
    const toggleBtn     = document.getElementById('btnToggleSidebar');
    const filterSidebar = document.getElementById('taskFilterSidebar');
    const toggleTxt     = document.getElementById('txtToggleSidebar');

    const isFilterHidden = localStorage.getItem('inbound_filter_hidden') === 'true';
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
            localStorage.setItem('inbound_filter_hidden', willHide);
        });
    }

    // 2. Tự khởi tạo dòng đầu tiên ở trang tạo
    const tbody = document.getElementById('itemsBody');
    if (tbody && tbody.children.length === 0) {
        addInboundRow();
    }

    // 3. Đóng modal khi click ra ngoài
    window.addEventListener('click', function (e) {
        if (e.target === document.getElementById('detailModal')) closeInboundDetailModal();
        if (e.target === document.getElementById('editInboundModal')) closeEditInboundModal();
        if (e.target === document.getElementById('productModal')) closeProductModal();
    });
});

window.addInboundRow                      = addInboundRow;
window.removeInboundRow                   = removeInboundRow;
window.validateInboundFormBeforeSubmit    = validateInboundFormBeforeSubmit;
window.viewInboundDetail                  = viewInboundDetail;
window.closeInboundDetailModal            = closeInboundDetailModal;
window.editInbound                        = editInbound;
window.closeEditInboundModal              = closeEditInboundModal;
window.addEditInboundRow                  = addEditInboundRow;
window.removeEditInboundRow               = removeEditInboundRow;
window.validateEditInboundFormBeforeSubmit= validateEditInboundFormBeforeSubmit;
window.confirmDeleteInbound               = confirmDeleteInbound;
window.searchInboundTable                 = searchInboundTable;
window.addInboundRow                    = addInboundRow;
window.addInboundRowWithData            = addInboundRowWithData;
window.removeInboundRow                 = removeInboundRow;
window.clearAllInboundRows              = clearAllInboundRows;
window.resetInboundForm                 = resetInboundForm;
window.calculateInboundRow              = calculateInboundRow;
window.calculateInboundTotal            = calculateInboundTotal;
window.saveInboundDraft                 = saveInboundDraft;
window.validateInboundFormBeforeSubmit  = validateInboundFormBeforeSubmit;
window.submitNewProduct                 = submitNewProduct;
window.submitNewSupplier                = submitNewSupplier;
window.openSupplierModal                = openSupplierModal;
window.closeSupplierModal               = closeSupplierModal;
window.viewInboundDetail                = viewInboundDetail;
window.closeInboundDetailModal          = closeInboundDetailModal;
window.printInboundDetail               = printInboundDetail;
window.exportInboundDetailExcel         = exportInboundDetailExcel;
window.exportInboundExcel               = exportInboundExcel;
window.searchInboundTable               = searchInboundTable;
window.confirmDeleteInbound             = confirmDeleteInbound;
window.addEditInboundRow                = addEditInboundRow;
window.removeEditInboundRow             = removeEditInboundRow;
window.updateEditStt                    = updateEditStt;
window.closeEditInboundModal            = closeEditInboundModal;
window.editInbound                      = editInbound;
window.validateEditInboundFormBeforeSubmit = validateEditInboundFormBeforeSubmit;
window.updateInboundBulkBar        = updateInboundBulkBar;
window.clearInboundSelection       = clearInboundSelection;
window.exportSelectedInboundExcel  = exportSelectedInboundExcel;