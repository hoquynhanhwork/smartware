//  utils.js 

'use strict';

function formatNumberInput(value) {
    if (value === null || value === undefined || value === '') return '';
    const num = parseFloat(value.toString().replace(/[^0-9.-]/g, ''));
    if (isNaN(num)) return '';
    return num.toLocaleString('vi-VN');
}

function unformatNumber(formattedValue) {
    if (formattedValue === null || formattedValue === undefined || formattedValue === '') return 0;
    const num = parseFloat(formattedValue.toString().replace(/\./g, '').replace(/,/g, '.'));
    return isNaN(num) ? 0 : num;
}

function formatDate(dateStr) {
    if (!dateStr) return '';
    return new Date(dateStr).toLocaleDateString('vi-VN');
}

function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/[&<>"']/g, function (m) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m];
    });
}

let _acController = null;

function initAutocomplete(input, options = {}) {
    if (!input) return;
    const {
        searchUrl,
        renderItem = (p) => `<strong>${escapeHtml(p.name)}</strong> (${p.unit}) — ${formatNumberInput(p.price)}₫ — Tồn: ${p.current_stock}`,
        onSelect   = () => {},
        position   = 'below',
        minChars   = 2,
    } = options;

    input.addEventListener('input', function () {
        const row = this.closest('.item-row');
        const pidField = row?.querySelector('.product-id');
        if (pidField) pidField.value = '';
        this.style.border = '';
        const val = this.value.trim();
        if (val.length < minChars) { clearSuggestions(this); return; }
        _acController?.abort();
        _acController = new AbortController();
        fetch(`${searchUrl}&term=${encodeURIComponent(val)}`, { signal: _acController.signal })
            .then(r => r.json())
            .then(data => { showSuggestions(this, data, { renderItem, onSelect, position }); _acController = null; })
            .catch(err => { if (err.name !== 'AbortError') console.error(err); });
    });

    input.addEventListener('blur', () => setTimeout(() => clearSuggestions(input), 200));
}

function showSuggestions(input, products, { renderItem, onSelect, position }) {
    clearSuggestions(input);
    if (!products.length) return;
    const container = document.createElement('div');
    container.className = 'autocomplete-suggestions';
    if (position === 'above') {
        container.style.cssText = 'position:absolute;bottom:100%;top:auto;margin-bottom:4px;z-index:999';
    }
    products.forEach(p => {
        const item = document.createElement('div');
        item.innerHTML = renderItem(p);
        item.style.cssText = 'padding:5px 10px;cursor:pointer';
        item.addEventListener('mousedown', e => e.preventDefault());
        item.addEventListener('click', () => {
            const row = input.closest('.item-row');
            input.value = p.name;
            const pidField = row?.querySelector('.product-id');
            if (pidField) pidField.value = p.id;
            clearSuggestions(input);
            input.style.border = '';
            onSelect(p, row);
        });
        container.appendChild(item);
    });
    input.parentNode.style.position = 'relative';
    input.parentNode.appendChild(container);
}

function clearSuggestions(input) {
    input.parentNode?.querySelector('.autocomplete-suggestions')?.remove();
}

function calculateRow(row, config = {}) {
    const {
        qtySelector   = '.qty',
        priceSelector = '.price',
        totalSelector = '.row-total',
        onAfter       = () => {},
    } = config;
    const qty   = unformatNumber(row.querySelector(qtySelector)?.value   || '0');
    const price = unformatNumber(row.querySelector(priceSelector)?.value || '0');
    const totalEl = row.querySelector(totalSelector);
    if (totalEl) totalEl.value = formatNumberInput((qty * price).toString());
    onAfter();
}

function calculateTotal(config = {}) {
    const {
        bodySelector  = '#itemsBody',
        totalSelector = '.row-total',
        displayId,
        hiddenId,
        formId,
    } = config;
    let total = 0;
    document.querySelectorAll(`${bodySelector} ${totalSelector}`).forEach(el => {
        total += unformatNumber(el.value);
    });
    if (displayId) {
        const el = document.getElementById(displayId);
        if (el) {
            if (el.tagName === 'INPUT') el.value = formatNumberInput(total.toString()) + ' ₫';
            else el.textContent = formatNumberInput(total.toString());
        }
    }
    if (hiddenId) {
        const el = document.getElementById(hiddenId);
        if (el) el.value = total;
    }
    if (formId) {
        const form = document.getElementById(formId);
        if (form) {
            let hidden = form.querySelector('input[name="total_amount"]');
            if (!hidden) {
                hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = 'total_amount';
                form.appendChild(hidden);
            }
            hidden.value = total;
        }
    }
    return total;
}

function attachRowEvents(row, config = {}) {
    const {
        qtySelector    = '.qty',
        priceSelector  = '.price',
        totalSelector  = '.row-total',
        allowEmpty     = true,
        onQtyBlur      = null,
        onPriceBlur    = null,
        onAfterCalc    = () => {},
        calculateRowFn = null,
    } = config;

    const qty   = row.querySelector(qtySelector);
    const price = row.querySelector(priceSelector);
    if (!qty || !price) return;

    if (qty.type   === 'number') qty.type   = 'text';
    if (price.type === 'number') price.type = 'text';

    const _calcRow = calculateRowFn || ((r) => calculateRow(r, { qtySelector, priceSelector, totalSelector, onAfter: onAfterCalc }));

    qty.addEventListener('input', () => _calcRow(row));
    qty.addEventListener('blur', function () {
        const raw = this.value.trim();
        if (allowEmpty && raw === '') return;
        let val = unformatNumber(raw);
        if (isNaN(val) || val <= 0) val = 1;
        if (onQtyBlur) val = onQtyBlur(row, val) ?? val;
        this.value = formatNumberInput(val.toString());
        _calcRow(row);
    });

    price.addEventListener('input', () => _calcRow(row));
    price.addEventListener('blur', function () {
        let val = unformatNumber(this.value);
        if (isNaN(val) || val < 0) val = 0;
        if (val <= 0) { this.style.border = '2px solid red'; this.title = 'Đơn giá phải lớn hơn 0'; }
        else          { this.style.border = ''; this.title = ''; }
        if (onPriceBlur) val = onPriceBlur(row, val) ?? val;
        this.value = formatNumberInput(val.toString());
        _calcRow(row);
    });

    _calcRow(row);
}

function validateItemRow(row, config = {}) {
    const {
        requireBatch  = true,
        requireExp    = true,
        requirePrice  = true,
        qtySelector   = '.qty',
        priceSelector = '.price',
        batchSelector = '[name="batch_no[]"]',
        expSelector   = '[name="exp_date[]"]',
    } = config;

    const pidEl   = row.querySelector('.product-id');
    const nameEl  = row.querySelector('.product-autocomplete, .product-autocomplete-outbound');
    const batchEl = row.querySelector(batchSelector);
    const expEl   = row.querySelector(expSelector);
    const qtyEl   = row.querySelector(qtySelector);
    const priceEl = row.querySelector(priceSelector);

    [nameEl, batchEl, expEl, qtyEl, priceEl].forEach(el => { if (el) el.style.border = ''; });

    const errors = [];
    if (!pidEl?.value || pidEl.value === '0') errors.push('chưa chọn sản phẩm');
    if (requireBatch && !batchEl?.value?.trim()) errors.push('thiếu số lô');
    if (requireExp   && !expEl?.value)           errors.push('thiếu hạn dùng');
    if (unformatNumber(qtyEl?.value) <= 0)       errors.push('số lượng không hợp lệ');
    if (requirePrice && unformatNumber(priceEl?.value) <= 0) errors.push('đơn giá phải lớn hơn 0');

    if (errors.includes('chưa chọn sản phẩm'))    nameEl  && (nameEl.style.border  = '2px solid red');
    if (errors.includes('thiếu số lô'))            batchEl && (batchEl.style.border = '2px solid red');
    if (errors.includes('thiếu hạn dùng'))         expEl   && (expEl.style.border   = '2px solid red');
    if (errors.includes('số lượng không hợp lệ'))  qtyEl   && (qtyEl.style.border   = '2px solid red');
    if (errors.includes('đơn giá phải lớn hơn 0')) priceEl && (priceEl.style.border = '2px solid red');

    return { valid: errors.length === 0, errors };
}

function updateStt(bodySelector = '#itemsBody') {
    document.querySelectorAll(`${bodySelector} .item-row`).forEach((row, idx) => {
        const cell = row.querySelector('.stt-cell');
        if (cell) cell.textContent = idx + 1;
    });
}

function checkExpiryWarning(expDateInput) {
    const expDate  = new Date(expDateInput.value);
    const today    = new Date(); today.setHours(0, 0, 0, 0);
    const daysLeft = Math.ceil((expDate - today) / 86400000);
    let span = expDateInput.parentNode.querySelector('.expiry-warning');
    if (!span) {
        span = document.createElement('span');
        span.className = 'expiry-warning';
        span.style.cssText = 'font-size:11px;margin-left:5px';
        expDateInput.parentNode.appendChild(span);
    }
    if (daysLeft < 0)        { span.innerHTML = '⚠️ Đã hết hạn!';                    span.style.color = 'red'; }
    else if (daysLeft <= 30) { span.innerHTML = `⚠️ Sắp hết hạn (${daysLeft} ngày)`; span.style.color = 'orange'; }
    else                     { span.innerHTML = ''; }
}

function filterTable(tableId, searchValue) {
    const filter = (searchValue || '').toLowerCase();
    document.querySelectorAll(`#${tableId} tbody tr`).forEach(row => {
        row.style.display = row.innerText.toLowerCase().includes(filter) ? '' : 'none';
    });
}

function toggleFilterBar() {
    const bar = document.getElementById('filterBar');
    if (!bar) return;
    bar.style.display = bar.style.display === 'none' ? 'flex' : 'none';
}
function toggleDropdown(panelId) {
    document.querySelectorAll('.dropdown-panel').forEach(panel => {
        if (panel.id !== panelId) panel.classList.remove('show');
    });
    const target = document.getElementById(panelId);
    if (target) target.classList.toggle('show');
}

document.addEventListener('click', function (event) {
    if (!event.target.closest('.custom-dropdown')) {
        document.querySelectorAll('.dropdown-panel').forEach(panel => panel.classList.remove('show'));
    }
});
function createSelection(namespace) {
    const KEY = `_sel_${namespace}`;
 
    function load() {
        try { return new Set(JSON.parse(sessionStorage.getItem(KEY) || '[]')); }
        catch { return new Set(); }
    }
    function save(set) {
        sessionStorage.setItem(KEY, JSON.stringify([...set]));
    }
    function clear() {
        sessionStorage.removeItem(KEY);
    }
 
    let selected = load();  
 
    function getIds() { return [...selected]; }
    function getCount() { return selected.size; }
    function clearAll() { selected.clear(); save(selected); }
 
    function getCheckboxes(table) {
        return [...table.querySelectorAll('.row-checkbox')];
    }
 
    function updateSelectAll(table, selectAllCb) {
        if (!selectAllCb) return;
        const cbs      = getCheckboxes(table);
        const checkedOnPage = cbs.filter(cb => selected.has(cb.dataset.id));
        selectAllCb.checked       = cbs.length > 0 && checkedOnPage.length === cbs.length;
        selectAllCb.indeterminate = checkedOnPage.length > 0 && checkedOnPage.length < cbs.length;
    }
 
    function updateBulkBar(bulkBar, bulkCount) {
        if (!bulkBar) return;
        const n = selected.size;
        bulkBar.style.display = n > 0 ? 'flex' : 'none';
        if (bulkCount) bulkCount.textContent = `${n} mục đã chọn`;
    }
 
    function restoreCheckboxes(table) {
        getCheckboxes(table).forEach(cb => {
            cb.checked = selected.has(cb.dataset.id);
        });
    }
 
    function init(tableSelector, selectAllSelector, bulkBarSelector, bulkCountSelector) {
        const table     = document.querySelector(tableSelector);
        const selectAll = document.querySelector(selectAllSelector);
        const bulkBar   = document.querySelector(bulkBarSelector);
        const bulkCount = document.querySelector(bulkCountSelector);
 
        if (!table) return;
 
        restoreCheckboxes(table);
        updateSelectAll(table, selectAll);
        updateBulkBar(bulkBar, bulkCount);
        if (selectAll) {
            selectAll.addEventListener('change', function () {
                getCheckboxes(table).forEach(cb => {
                    cb.checked = this.checked;
                    if (this.checked) selected.add(cb.dataset.id);
                    else              selected.delete(cb.dataset.id);
                });
                save(selected);
                updateBulkBar(bulkBar, bulkCount);
 
                if (this.checked) showSelectAllBanner(table, bulkBar);
                else              hideSelectAllBanner();
            });
        }
 
        table.querySelectorAll('.row-checkbox').forEach(cb => {
            cb.addEventListener('change', function () {
                if (this.checked) selected.add(this.dataset.id);
                else {
                    selected.delete(this.dataset.id);
                    hideSelectAllBanner();
                }
                save(selected);
                updateSelectAll(table, selectAll);
                updateBulkBar(bulkBar, bulkCount);
            });
        });
    }
 
    let _totalRows = 0;
 
    function setTotalRows(n) { _totalRows = n; }
 
    function showSelectAllBanner(table, bulkBar) {
        if (!bulkBar || !_totalRows) return;
        const onPage = getCheckboxes(table).length;
        if (_totalRows <= onPage) return;
 
        let banner = document.getElementById('_selAllBanner');
        if (!banner) {
            banner = document.createElement('div');
            banner.id = '_selAllBanner';
            banner.style.cssText = `
                background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;
                padding:8px 16px;font-size:13px;color:#1d4ed8;
                display:flex;align-items:center;gap:12px;margin-top:8px;
            `;
            bulkBar.after(banner);
        }
        banner.innerHTML = `
            <span>Đã chọn <strong>${onPage}</strong> mục trên trang này.</span>
            <button onclick="window._sel_selectAllRecords()"
                    style="background:none;border:none;color:#1d4ed8;font-weight:600;cursor:pointer;font-size:13px;text-decoration:underline">
                Chọn tất cả ${_totalRows.toLocaleString('vi-VN')} bản ghi
            </button>
            <button onclick="window._sel_clearBanner()"
                    style="background:none;border:none;color:#64748b;cursor:pointer;font-size:13px;margin-left:auto">
                ✕
            </button>
        `;
        banner.style.display = 'flex';
    }
 
    function hideSelectAllBanner() {
        const banner = document.getElementById('_selAllBanner');
        if (banner) banner.style.display = 'none';
    }
 
    window._sel_clearBanner = hideSelectAllBanner;
    window._sel_selectAllRecords = function () {
        sessionStorage.setItem(`${KEY}_all`, '1');
        const banner = document.getElementById('_selAllBanner');
        if (banner) {
            banner.innerHTML = `<span>✓ Đã chọn tất cả <strong>${_totalRows.toLocaleString('vi-VN')}</strong> bản ghi. <a href="#" onclick="window._sel_clearAll();return false" style="color:#dc2626">Bỏ chọn</a></span>`;
        }
        const bulkCount = document.querySelector('[id*="bulkCount"], .bulk-count');
        if (bulkCount) bulkCount.textContent = `${_totalRows.toLocaleString('vi-VN')} mục đã chọn`;
    };
    window._sel_clearAll = function () {
        sessionStorage.removeItem(`${KEY}_all`);
        selected.clear();
        save(selected);
        document.querySelectorAll('.row-checkbox').forEach(cb => cb.checked = false);
        const selectAll = document.querySelector('[id*="selectAll"], #selectAll');
        if (selectAll) { selectAll.checked = false; selectAll.indeterminate = false; }
        const bulkBar = document.querySelector('[id*="bulkActionBar"], .bulk-action-bar');
        if (bulkBar) bulkBar.style.display = 'none';
        hideSelectAllBanner();
    };
 
    function isSelectAllMode() {
        return sessionStorage.getItem(`${KEY}_all`) === '1';
    }
 
    function buildExportParams(baseParams) {
        const params = new URLSearchParams(baseParams || window.location.search);
 
        if (isSelectAllMode()) {
            params.set('select_all', '1');
        } else {
            const ids = getIds();
            if (ids.length === 0) return null;
            ids.forEach(id => params.append('selected_ids[]', id));
        }
        return params;
    }
 
    return {
        init,
        getIds,
        getCount,
        clearAll,
        setTotalRows,
        buildExportParams,
        isSelectAllMode,
    };
}
window.formatNumberInput  = formatNumberInput;
window.unformatNumber     = unformatNumber;
window.formatDate         = formatDate;
window.escapeHtml         = escapeHtml;
window.initAutocomplete   = initAutocomplete;
window.showSuggestions    = showSuggestions;
window.clearSuggestions   = clearSuggestions;
window.calculateRow       = calculateRow;
window.calculateTotal     = calculateTotal;
window.attachRowEvents    = attachRowEvents;
window.validateItemRow    = validateItemRow;
window.updateStt          = updateStt;
window.checkExpiryWarning = checkExpiryWarning;
window.filterTable        = filterTable;
window.toggleFilterBar    = toggleFilterBar;
window.toggleDropdown     = toggleDropdown;
window.createSelection    = createSelection;