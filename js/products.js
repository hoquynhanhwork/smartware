//  products.js

'use strict';

function _getCsrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

const sortState = { costPrice: null, price: null };

function sortTableByColumn(columnClass) {
    const table = document.getElementById('productTable');
    if (!table) return;
    const tbody = table.querySelector('tbody');
    const rows  = Array.from(tbody.querySelectorAll('tr'));
    if (!rows.length) return;

    const isCostPrice = columnClass === 'cost-price-col';
    const stateKey    = isCostPrice ? 'costPrice' : 'price';
    const otherKey    = isCostPrice ? 'price'     : 'costPrice';
    const direction   = sortState[stateKey] === 'asc' ? 'desc' : 'asc';
    sortState[stateKey] = direction;
    sortState[otherKey] = null;

    rows.sort((a, b) => {
        const valA = parseFloat(a.querySelector(`.${columnClass}`)?.getAttribute('data-value')) || 0;
        const valB = parseFloat(b.querySelector(`.${columnClass}`)?.getAttribute('data-value')) || 0;
        return direction === 'asc' ? valA - valB : valB - valA;
    });
    rows.forEach(row => tbody.appendChild(row));
    _updateSortIcon('sortCostPrice', isCostPrice ? direction : null);
    _updateSortIcon('sortPrice',     isCostPrice ? null : direction);
}

function _updateSortIcon(headerId, direction) {
    const header = document.getElementById(headerId);
    if (!header) return;
    const up   = header.querySelector('.sort-up');
    const down = header.querySelector('.sort-down');
    if (up)   up.style.opacity   = direction === 'asc'  ? '1' : '0.4';
    if (down) down.style.opacity = direction === 'desc' ? '1' : '0.4';
}

function openProductModal(action, data = null) {
    const modal = document.getElementById('productModal');
    if (!modal) return;
    _ensureTextInput('prodCostPrice');
    _ensureTextInput('prodPrice');

    const clearErrors = () => {
        ['skuError','priceError','costPriceError'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.innerText = '';
        });
    };

    if (action === 'add') {
        _setVal('formAction','add'); _setVal('modalTitle','Thêm sản phẩm','innerText');
        _setVal('productId','0'); _setVal('prodName',''); _setVal('prodSku','');
        _setVal('prodCategory',''); _setVal('prodSupplier',''); _setVal('prodUnit','');
        _setVal('prodCostPrice',''); _setVal('prodPrice',''); _setVal('prodDesc','');
        _setVal('prodStatus','active'); clearErrors();
    } else if (action === 'edit' && data) {
        _setVal('formAction','edit'); _setVal('modalTitle','Sửa sản phẩm','innerText');
        _setVal('productId', data.id);
        _setVal('prodName',  data.name);
        _setVal('prodSku',   data.sku         || '');
        _setVal('prodCategory', data.category_id  || '');
        _setVal('prodSupplier', data.supplier_id  || '');
        _setVal('prodUnit',  data.unit         || '');
        _setVal('prodCostPrice', data.cost_price ? formatNumberInput(String(data.cost_price)) : '');
        _setVal('prodPrice', data.price        ? formatNumberInput(String(data.price))        : '');
        _setVal('prodDesc',  data.description  || '');
        _setVal('prodStatus',data.status       || 'active');
        clearErrors();
    }
    modal.style.display = 'flex';
}

function _setVal(id, val, prop = 'value') {
    const el = document.getElementById(id);
    if (el) el[prop] = val;
}
function _ensureTextInput(id) {
    const el = document.getElementById(id);
    if (el && el.type === 'number') el.type = 'text';
}

function closeProductModal() {
    const modal = document.getElementById('productModal');
    if (modal) modal.style.display = 'none';
}

function confirmDeleteProduct(id, status) {
    if (status === 'active') {
        if (!confirm('Bạn có chắc muốn ngừng kinh doanh sản phẩm này?\nSản phẩm sẽ chuyển sang trạng thái "Ngừng kinh doanh".')) return;
    } else {
        if (!confirm('⚠️ Sản phẩm đang NGỪNG KINH DOANH.\n\nBấm OK để XÓA VĨNH VIỄN khỏi hệ thống.\nHành động này KHÔNG THỂ hoàn tác!')) return;
        if (!confirm('Xác nhận lần cuối: xóa vĩnh viễn sản phẩm này?')) return;
    }
    fetch('process.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': _getCsrfToken() },
        body: 'action=delete&id=' + encodeURIComponent(id),
    })
    .then(r => { if (!r.ok) throw new Error(`Lỗi server: ${r.status}`); return r.json(); })
    .then(data => {
        if (data.ok) window.location.href = 'index.php';
        else alert('Không thể thực hiện: ' + data.message);
    })
    .catch(err => alert('Có lỗi xảy ra: ' + err.message));
}

let _searchTimer = null;
function searchProductTable() {
    clearTimeout(_searchTimer);
    _searchTimer = setTimeout(() => {
        const keyword = document.getElementById('searchInput')?.value.trim() ?? '';
        const params  = new URLSearchParams(window.location.search);
        params.delete('page');
        keyword !== '' ? params.set('keyword', keyword) : params.delete('keyword');
        window.location.href = 'index.php?' + params.toString();
    }, 350);
}

function exportProductExcel() {
    const params = new URLSearchParams(window.location.search);
    window.location.href = '../../pages/export/Export.php?type=products&' + params.toString();
}

let skuCheckTimeout;
function checkSku() {
    const sku = document.getElementById('prodSku');
    if (!sku) return;
    const skuVal    = sku.value.trim();
    const id        = document.getElementById('productId')?.value ?? 0;
    const errorSpan = document.getElementById('skuError');
    if (skuVal === '') { if (errorSpan) errorSpan.innerText = ''; return; }
    clearTimeout(skuCheckTimeout);
    skuCheckTimeout = setTimeout(() => {
        fetch(`process.php?action=check_sku&sku=${encodeURIComponent(skuVal)}&id=${id}`)
            .then(r => r.json())
            .then(data => { if (errorSpan) errorSpan.innerText = data.exists ? 'SKU đã tồn tại!' : ''; })
            .catch(err => console.error('Lỗi kiểm tra SKU:', err));
    }, 300);
}

function validatePrice() {
    const input = document.getElementById('prodPrice');
    const err   = document.getElementById('priceError');
    if (!input) return true;
    const val = parseFloat(unformatNumber(input.value));
    if (isNaN(val) || val >= 0) { if (err) err.innerText = ''; return true; }
    if (err) err.innerText = 'Giá bán không được âm!'; return false;
}

function validateCostPrice() {
    const input = document.getElementById('prodCostPrice');
    const err   = document.getElementById('costPriceError');
    if (!input) return true;
    const val = parseFloat(unformatNumber(input.value));
    if (isNaN(val) || val >= 0) { if (err) err.innerText = ''; return true; }
    if (err) err.innerText = 'Giá vốn không được âm!'; return false;
}

function attachPriceFormatEvents() {
    ['prodCostPrice','prodPrice'].forEach(id => {
        const el = document.getElementById(id);
        if (!el) return;
        if (el.type === 'number') el.type = 'text';
        el.addEventListener('blur', function () {
            this.value = formatNumberInput(unformatNumber(this.value).toString());
        });
    });
}

function openCategoryModal() {
    const modal = document.getElementById('categoryModal');
    if (modal) modal.style.display = 'flex';
}
function closeCategoryModal() {
    const modal = document.getElementById('categoryModal');
    if (modal) modal.style.display = 'none';
    document.getElementById('categoryForm')?.reset();
}
function submitCategory(e) {
    e.preventDefault();
    const fd = new FormData();
    fd.append('action',      'add_category');
    fd.append('csrf_token',  _getCsrfToken());
    fd.append('name',        document.getElementById('catName')?.value   ?? '');
    fd.append('description', document.getElementById('catDesc')?.value   ?? '');
    fetch('process.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                const opt = document.createElement('option');
                opt.value = data.id;
                opt.textContent = data.name;
                document.getElementById('prodCategory')?.appendChild(opt);
                alert('Thêm danh mục thành công!');
                closeCategoryModal();
            } else alert('Lỗi: ' + data.message);
        })
        .catch(err => alert('Có lỗi xảy ra: ' + err.message));
}

document.addEventListener('DOMContentLoaded', function () {
    if (!document.getElementById('productTable')) return;

    const sel = createSelection('products');
    sel.setTotalRows(window._productTotalRows || 0); // truyền từ index.php
    sel.init('#productTable', '#selectAll', '#bulkActionBar', '#bulkCount');

    window.exportSelectedExcel = function () {
        const params = sel.buildExportParams();
        if (!params) { alert('Chưa chọn sản phẩm nào.'); return; }
        window.location.href = '../../pages/export/Export.php?type=products&' + params.toString();
    };

    window.clearSelection = function () {
        sel.clearAll();
        document.querySelectorAll('.row-checkbox').forEach(cb => cb.checked = false);
        const sa = document.getElementById('selectAll');
        if (sa) { sa.checked = false; sa.indeterminate = false; }
        document.getElementById('bulkActionBar').style.display = 'none';
        document.getElementById('_selAllBanner')?.remove();
    };

    document.getElementById('filterForm')?.addEventListener('submit', () => sel.clearAll());
    document.querySelector('a[href="index.php"]')?.addEventListener('click', () => sel.clearAll());

    attachPriceFormatEvents();

    document.getElementById('sortCostPrice')?.addEventListener('click', e => {
        e.preventDefault(); sortTableByColumn('cost-price-col');
    });
    document.getElementById('sortPrice')?.addEventListener('click', e => {
        e.preventDefault(); sortTableByColumn('price-col');
    });

    const productForm = document.getElementById('productForm');
    if (productForm) {
        productForm.addEventListener('submit', function (e) {
            const costEl  = document.getElementById('prodCostPrice');
            const priceEl = document.getElementById('prodPrice');
            if (costEl)  costEl.value  = unformatNumber(costEl.value);
            if (priceEl) priceEl.value = unformatNumber(priceEl.value);
            if (!validatePrice() || !validateCostPrice()) {
                e.preventDefault(); alert('Vui lòng nhập giá hợp lệ (không âm).'); return;
            }
            const skuError = document.getElementById('skuError');
            if (skuError && skuError.innerText !== '') {
                e.preventDefault(); alert('SKU không hợp lệ hoặc đã tồn tại.');
            }
        });
    }

    window.addEventListener('click', function (e) {
        if (e.target === document.getElementById('productModal'))  closeProductModal();
        if (e.target === document.getElementById('categoryModal')) closeCategoryModal();
    });
});

window.openProductModal     = openProductModal;
window.closeProductModal    = closeProductModal;
window.confirmDeleteProduct = confirmDeleteProduct;
window.searchProductTable   = searchProductTable;
window.exportProductExcel   = exportProductExcel;
window.openCategoryModal    = openCategoryModal;
window.closeCategoryModal   = closeCategoryModal;
window.submitCategory       = submitCategory;
window.checkSku             = checkSku;
window.validatePrice        = validatePrice;
window.validateCostPrice    = validateCostPrice;
window.sortTableByColumn    = sortTableByColumn;