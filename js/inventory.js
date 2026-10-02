// inventory.js

function _getPageType() {
    if (document.getElementById('batchTable'))  return 'batch';
    if (document.getElementById('historyTable')) return 'history';
    return 'stock';
}

function viewBatches(productId, productName) {
    const modal   = document.getElementById('batchDetailModal');
    const title   = document.getElementById('batchModalTitle');
    const content = document.getElementById('batchDetailContent');
    if (!modal) return;

    if (title) title.textContent = `Lô hàng: ${productName}`;
    content.innerHTML = '<p style="color:#94a3b8;text-align:center;padding:20px">Đang tải...</p>';
    modal.style.display = 'flex';

    fetch(`process.php?action=get_batches_detail&product_id=${productId}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success || !data.batches.length) {
                content.innerHTML = '<p style="color:#94a3b8;text-align:center;padding:20px">Không có lô hàng nào</p>';
                return;
            }
            let html = `<div class="table-responsive">
                <table class="data-table">
                    <thead><tr>
                        <th>Số lô</th>
                        <th>HSD</th>
                        <th class="text-right">Tồn</th>
                        <th class="text-right">Còn (ngày)</th>
                        <th>Trạng thái</th>
                    </tr></thead><tbody>`;
            data.batches.forEach(b => {
                const days = parseInt(b.days_to_exp);
                let expClass = '', expLabel = '';
                if (isNaN(days))       { expClass = '';               expLabel = 'Không có HSD'; }
                else if (days < 0)     { expClass = 'status-inactive'; expLabel = 'Hết hạn'; }
                else if (days <= 30)   { expClass = 'status-warning';  expLabel = `Còn ${days} ngày`; }
                else                   { expClass = 'status-active';   expLabel = 'Còn hạn'; }
                html += `<tr>
                    <td><code class="batch-code">${escapeHtml(b.batch_no)}</code></td>
                    <td>${b.exp_date ? formatDate(b.exp_date) : '—'}</td>
                    <td class="text-right"><strong>${formatNumberInput(b.quantity)}</strong></td>
                    <td class="text-right">${b.exp_date ? days : '—'}</td>
                    <td><span class="badge ${expClass}">${expLabel}</span></td>
                </tr>`;
            });
            html += '</tbody></table></div>';
            content.innerHTML = html;
        })
        .catch(() => content.innerHTML = '<p style="color:red;text-align:center">Lỗi tải dữ liệu</p>');
}

function closeBatchModal() {
    const m = document.getElementById('batchDetailModal');
    if (m) m.style.display = 'none';
}

// Xem lịch sử của 1 lô cụ thể — mở history.php với filter batch_no
function viewBatchHistory(batchNo) {
    const params = new URLSearchParams({ batch_no: batchNo });
    window.open(`history.php?${params.toString()}`, '_blank');
}

let _adjustBatches = [];

function openAdjustModal(productIdOrNull, productNameOrNull, batchNoOrNull) {
    productIdOrNull   = productIdOrNull   ?? null;
    productNameOrNull = productNameOrNull ?? null;
    batchNoOrNull     = batchNoOrNull     ?? null;

    const productSearch   = document.getElementById('adjustProductSearch');
    const productIdHidden = document.getElementById('adjustProductId');
    const batchSelect     = document.getElementById('adjustBatchNo');
    const actualQty       = document.getElementById('adjustActualQty');
    const reason          = document.getElementById('adjustReason');
    const diffPreview     = document.getElementById('adjustDiffPreview');
    const currentQtyInfo  = document.getElementById('adjustCurrentQty');
    const modal           = document.getElementById('adjustModal');
    if (!modal) return;

    if (productSearch)   productSearch.value       = productNameOrNull || '';
    if (productIdHidden) productIdHidden.value      = productIdOrNull  || '';
    if (batchSelect)     batchSelect.innerHTML      = '<option value="">-- Chọn lô --</option>';
    if (actualQty)       actualQty.value            = '';
    if (reason)          reason.value               = '';
    if (diffPreview)     diffPreview.textContent    = '';
    if (currentQtyInfo)  currentQtyInfo.textContent = '';

    modal.style.display = 'flex';

    const grp = document.getElementById('adjustProductGroup');
    if (productIdOrNull) {
        if (grp)           grp.style.opacity      = '0.6';
        if (productSearch) productSearch.readOnly = true;
        _loadAdjustBatches(productIdOrNull, batchNoOrNull);
    } else {
        if (grp)           grp.style.opacity      = '1';
        if (productSearch) productSearch.readOnly = false;
    }
}

function closeAdjustModal() {
    const m = document.getElementById('adjustModal');
    if (m) m.style.display = 'none';
}

function _loadAdjustBatches(productId, batchNoToSelect) {
    batchNoToSelect = batchNoToSelect ?? null;
    fetch(`process.php?action=get_batches_detail&product_id=${productId}`)
        .then(r => r.json())
        .then(data => {
            _adjustBatches = data.batches || [];
            const sel = document.getElementById('adjustBatchNo');
            if (!sel) return;
            sel.innerHTML = '<option value="">-- Chọn lô --</option>' +
                _adjustBatches.map(b =>
                    `<option value="${escapeHtml(b.batch_no)}" data-qty="${b.quantity}">
                        ${escapeHtml(b.batch_no)} — tồn: ${b.quantity}${b.exp_date ? ' (HSD: ' + formatDate(b.exp_date) + ')' : ''}
                    </option>`
                ).join('');

            if (batchNoToSelect) {
                sel.value = batchNoToSelect;
                sel.dispatchEvent(new Event('change'));
            }
        });
}

function previewAdjustDiff() {
    const sel    = document.getElementById('adjustBatchNo');
    const opt    = sel?.options[sel.selectedIndex];
    const curQty = parseInt(opt?.getAttribute('data-qty') ?? '-1');
    const newQty = parseInt(document.getElementById('adjustActualQty')?.value ?? '');
    const el     = document.getElementById('adjustDiffPreview');
    if (!el) return;
    if (isNaN(curQty) || curQty < 0 || isNaN(newQty)) { el.textContent = ''; return; }
    const diff = newQty - curQty;
    if (diff === 0) {
        el.style.color = '#64748b';
        el.textContent = 'Không có thay đổi';
    } else {
        el.style.color = diff > 0 ? '#16a34a' : '#dc2626';
        el.textContent = `Chênh lệch: ${diff > 0 ? '+' : ''}${diff} đơn vị`;
    }
}

async function submitAdjust() {
    const productId = document.getElementById('adjustProductId')?.value;
    const batchNo   = document.getElementById('adjustBatchNo')?.value;
    const actualQty = document.getElementById('adjustActualQty')?.value;
    const reason    = document.getElementById('adjustReason')?.value.trim();

    if (!productId)                                      { alert('Vui lòng chọn sản phẩm'); return; }
    if (!batchNo)                                        { alert('Vui lòng chọn lô hàng'); return; }
    if (actualQty === '' || actualQty === undefined)     { alert('Vui lòng nhập số lượng thực tế'); return; }
    if (parseInt(actualQty) < 0)                        { alert('Số lượng không được âm'); return; }
    if (!reason)                                         { alert('Vui lòng nhập lý do điều chỉnh'); return; }

    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    const fd = new FormData();
    fd.append('action',     'adjust_stock');
    fd.append('product_id', productId);
    fd.append('batch_no',   batchNo);
    fd.append('actual_qty', actualQty);
    fd.append('reason',     reason);
    fd.append('csrf_token', csrfToken);

    const btn = document.querySelector('#adjustModal .btn-primary, #adjustModal button[onclick="submitAdjust()"]');
    if (btn) btn.disabled = true;

    try {
        const resp = await fetch('process.php', { method: 'POST', body: fd });
        const data = await resp.json();
        if (data.success) {
            alert(data.message || 'Điều chỉnh thành công!');
            closeAdjustModal();
            window.location.reload();
        } else {
            alert('Lỗi: ' + data.message);
        }
    } catch (err) {
        alert('Có lỗi xảy ra: ' + err.message);
    } finally {
        if (btn) btn.disabled = false;
    }
}

function initAdjustProductAutocomplete() {
    const input = document.getElementById('adjustProductSearch');
    if (!input) return;
    let timeout;
    input.addEventListener('input', function () {
        const val      = this.value.trim();
        const hiddenId = document.getElementById('adjustProductId');
        if (hiddenId) hiddenId.value = '';
        clearTimeout(timeout);
        document.getElementById('_adjSuggestions')?.remove();
        if (val.length < 2) return;
        timeout = setTimeout(() => {
            fetch(`process.php?action=search_products&term=${encodeURIComponent(val)}`)
                .then(r => r.json())
                .then(products => {
                    document.getElementById('_adjSuggestions')?.remove();
                    if (!products.length) return;
                    const box = document.createElement('div');
                    box.id        = '_adjSuggestions';
                    box.className = 'autocomplete-suggestions';
                    products.forEach(p => {
                        const item = document.createElement('div');
                        item.innerHTML = `<strong>${escapeHtml(p.name)}</strong> <span style="color:#94a3b8">${p.sku || ''}</span>`;
                        item.addEventListener('mousedown', e => e.preventDefault());
                        item.addEventListener('click', () => {
                            input.value = p.name;
                            document.getElementById('adjustProductId').value = p.id;
                            box.remove();
                            _loadAdjustBatches(p.id, null);
                        });
                        box.appendChild(item);
                    });
                    input.parentNode.appendChild(box);
                });
        }, 300);
    });
    input.addEventListener('blur', () => setTimeout(() => {
        document.getElementById('_adjSuggestions')?.remove();
    }, 200));
}

// Chỉ khởi tạo ở history.php (có #historyTable)
function initProductFilterAutocomplete() {
    if (!document.getElementById('historyTable')) return;
    const input      = document.getElementById('productSearchFilter');
    const hiddenId   = document.getElementById('productIdFilter');
    const hiddenName = document.getElementById('productNameFilter');
    if (!input) return;
    let timeout;
    input.addEventListener('input', function () {
        const val = this.value.trim();
        if (hiddenId)   hiddenId.value   = '';
        if (hiddenName) hiddenName.value = '';
        clearTimeout(timeout);
        document.getElementById('_prodSuggestions')?.remove();
        if (val.length < 2) return;
        timeout = setTimeout(() => {
            fetch(`process.php?action=search_products&term=${encodeURIComponent(val)}`)
                .then(r => r.json())
                .then(products => {
                    document.getElementById('_prodSuggestions')?.remove();
                    if (!products.length) return;
                    const box = document.createElement('div');
                    box.id        = '_prodSuggestions';
                    box.className = 'autocomplete-suggestions';
                    products.forEach(p => {
                        const item = document.createElement('div');
                        item.innerHTML = `<strong>${escapeHtml(p.name)}</strong> <span style="color:#94a3b8">${p.sku || ''}</span>`;
                        item.addEventListener('mousedown', e => e.preventDefault());
                        item.addEventListener('click', () => {
                            input.value      = p.name;
                            hiddenId.value   = p.id;
                            hiddenName.value = p.name;
                            box.remove();
                        });
                        box.appendChild(item);
                    });
                    input.parentNode.appendChild(box);
                });
        }, 300);
    });
    input.addEventListener('blur', () => setTimeout(() => {
        document.getElementById('_prodSuggestions')?.remove();
    }, 200));
}

function updateBulkBar() {
    const checked = document.querySelectorAll('.row-checkbox:checked');
    const bar     = document.getElementById('bulkActionBar');
    const count   = document.getElementById('bulkCount');
    if (!bar) return;
    bar.style.display = checked.length > 0 ? 'flex' : 'none';
    if (count) {
        const type  = _getPageType();
        const label = type === 'batch' ? 'lô' : type === 'history' ? 'giao dịch' : 'sản phẩm';
        count.textContent = `${checked.length} ${label} đã chọn`;
    }
}

function clearSelection() {
    document.querySelectorAll('.row-checkbox').forEach(cb => cb.checked = false);
    const selectAll = document.getElementById('selectAll');
    if (selectAll) { selectAll.checked = false; selectAll.indeterminate = false; }
    updateBulkBar();
}

function exportSelectedExcel() {
    const ids = [...document.querySelectorAll('.row-checkbox:checked')]
        .map(cb => cb.dataset.id)
        .filter(Boolean);
    if (ids.length === 0) { alert('Chưa chọn dòng nào.'); return; }

    const type   = _getPageType(); // 'batch' | 'history' | 'stock'
    const params = new URLSearchParams(window.location.search);
    ids.forEach(id => params.append('selected_ids[]', id));
    window.location.href = `../../pages/export/Export.php?type=${type}&${params.toString()}`;
}

function exportBatchExcel() {
    const params = new URLSearchParams(window.location.search);
    window.location.href = `../../pages/export/Export.php?type=batch&${params.toString()}`;
}

function initFilterBadge() {
    const btn = document.getElementById('btnToggleFilter');
    if (!btn) return;
    if (btn.dataset.active === '1') {
        btn.classList.add('active');
        const bar = document.getElementById('filterBar');
        if (bar && bar.style.display === 'none') bar.style.display = 'flex';
    }
}

document.addEventListener('DOMContentLoaded', function () {
    if (!document.getElementById('stockTable') &&
        !document.getElementById('historyTable') &&
        !document.getElementById('batchTable')) return;

    const selectAllCb = document.getElementById('selectAll');
    if (selectAllCb) {
        selectAllCb.addEventListener('change', function () {
            document.querySelectorAll('.row-checkbox').forEach(cb => cb.checked = this.checked);
            updateBulkBar();
        });
    }
    document.querySelectorAll('.row-checkbox').forEach(cb => {
        cb.addEventListener('change', function () {
            const all     = document.querySelectorAll('.row-checkbox');
            const checked = document.querySelectorAll('.row-checkbox:checked');
            if (selectAllCb) {
                selectAllCb.checked       = checked.length === all.length;
                selectAllCb.indeterminate = checked.length > 0 && checked.length < all.length;
            }
            updateBulkBar();
        });
    });

    document.getElementById('batchDetailModal')?.addEventListener('click', e => {
        if (e.target === e.currentTarget) closeBatchModal();
    });
    document.getElementById('adjustModal')?.addEventListener('click', e => {
        if (e.target === e.currentTarget) closeAdjustModal();
    });

    document.getElementById('adjustActualQty')?.addEventListener('input', previewAdjustDiff);
    document.getElementById('adjustBatchNo')?.addEventListener('change', function () {
        const opt    = this.options[this.selectedIndex];
        const curQty = opt?.getAttribute('data-qty');
        const info   = document.getElementById('adjustCurrentQty');
        if (info) info.textContent = curQty ? `Tồn hiện tại: ${curQty} đơn vị` : '';
        previewAdjustDiff();
    });

    initAdjustProductAutocomplete();
    initProductFilterAutocomplete();
    initFilterBadge();
});

window.viewBatches          = viewBatches;
window.closeBatchModal      = closeBatchModal;
window.viewBatchHistory     = viewBatchHistory;
window.openAdjustModal      = openAdjustModal;
window.closeAdjustModal     = closeAdjustModal;
window.submitAdjust         = submitAdjust;
window.previewAdjustDiff    = previewAdjustDiff;
window.updateBulkBar        = updateBulkBar;
window.clearSelection       = clearSelection;
window.exportSelectedExcel  = exportSelectedExcel;
window.exportBatchExcel     = exportBatchExcel;
window.initFilterBadge      = initFilterBadge;

// ── UI helpers ────────────────────────────────────────────────────────────────

function toggleFilterBar() {
    const bar = document.getElementById('filterBar');
    if (!bar) return;
    bar.style.display = bar.style.display === 'none' ? 'flex' : 'none';
}

function toggleDropdown(panelId) {
    document.querySelectorAll('.dropdown-panel').forEach(p => {
        if (p.id !== panelId) p.style.display = 'none';
    });
    const panel = document.getElementById(panelId);
    if (!panel) return;
    panel.style.display = panel.style.display === 'block' ? 'none' : 'block';
}

// Đóng dropdown khi click ra ngoài
document.addEventListener('click', function (e) {
    if (!e.target.closest('.custom-dropdown')) {
        document.querySelectorAll('.dropdown-panel').forEach(p => p.style.display = 'none');
    }
});

function toggleGroup(btn) {
    btn.classList.toggle('open');
    const children = btn.closest('.fsb-group')?.querySelector('.fsb-children');
    if (children) children.classList.toggle('open');
}

function applyQuickSearch() {
    const val    = document.getElementById('quickSearch')?.value ?? '';
    const hidden = document.getElementById('hiddenKeyword');
    if (hidden) hidden.value = val;
    document.getElementById('filterForm')?.submit();
}

// batch/index: filter KPI card click theo expiry_status
function setExpiryFilter(status) {
    const params = new URLSearchParams(window.location.search);
    params.set('expiry_status', status);
    params.delete('page');
    window.location.href = '?' + params.toString();
}

// inventory/index cũ (giữ tương thích nếu còn dùng)
function filterByStatus(status) {
    const params = new URLSearchParams(window.location.search);
    params.set('stock_status', status);
    params.delete('page');
    window.location.href = '?' + params.toString();
}

function enforceSingleCheck(cb) {
    const group = cb.closest('.fsb-group');
    group.querySelectorAll('.single-select-cb').forEach(other => {
        if (other !== cb) other.checked = false;
    });
}
window.toggleFilterBar  = toggleFilterBar;
window.toggleDropdown   = toggleDropdown;
window.toggleGroup      = toggleGroup;
window.applyQuickSearch = applyQuickSearch;
window.setExpiryFilter  = setExpiryFilter;
window.filterByStatus   = filterByStatus;
window.enforceSingleCheck = enforceSingleCheck;