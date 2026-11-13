// js/products.js
'use strict';

function _getCsrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content 
        ?? document.getElementById('csrfTokenMeta')?.value 
        ?? '';
}

function _setVal(id, val, prop = 'value') {
    const el = document.getElementById(id);
    if (el) el[prop] = val;
}

/**
 * Đọc số tiền người dùng nhập. Trả về { empty, valid, value }.
 *  - Rỗng → empty (KHÔNG coi là 0: server mới là nơi báo "Vui lòng nhập giá bán").
 *  - Có dấu phẩy → kiểu VN: "." là phân cách nghìn, "," là thập phân ("1.234,5" = 1234.5).
 *  - Chỉ có dấu chấm: dạng nhóm 3 chữ số ("1.234", "1.234.567") là phân cách nghìn;
 *    còn lại ("1000.5", "0.5") là thập phân.
 *  - Chữ lung tung → valid:false (trước đây bị đổi âm thầm thành 0).
 */
function parseMoneyInput(raw) {
    let t = String(raw ?? '').replace(/[\s₫đĐ]|vn[dđ]/gi, '');
    if (t === '') return { empty: true, valid: true, value: null };

    if (t.includes(',')) {
        t = t.replace(/\./g, '').replace(',', '.');
    } else if (/^-?\d{1,3}(\.\d{3})+$/.test(t)) {
        t = t.replace(/\./g, '');
    }
    if (!/^-?\d+(\.\d+)?$/.test(t)) return { empty: false, valid: false, value: NaN };
    return { empty: false, valid: true, value: parseFloat(t) };
}

// true khi server xác nhận SKU đã tồn tại (lỗi mạng KHÔNG đặt cờ này — xem checkSku)
let _skuExists = false;

function _showPricingTab() {
    document.querySelector('.tab-pill-btn[data-tab="tab-pricing"]')?.click();
}

function _ensureTextInput(id) {
    const el = document.getElementById(id);
    if (el && el.type === 'number') el.type = 'text';
}

// ── 1. MODAL SẢN PHẨM & DANH MỤC ──────────────────────────────────────────────
function openProductModal(action, data = null) {
    const modal = document.getElementById('productModal');
    if (!modal) return;
    _ensureTextInput('prodCostPrice');
    _ensureTextInput('prodPrice');

    const clearErrors = () => {
        ['skuError', 'priceError', 'costPriceError'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.innerText = '';
        });
        _skuExists = false;
    };

    // Reset về tab đầu tiên
    document.querySelectorAll('.tab-pill-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
    const firstTabBtn = document.querySelector('.tab-pill-btn[data-tab="tab-basic"]');
    const firstTabPane = document.getElementById('tab-basic');
    if (firstTabBtn) firstTabBtn.classList.add('active');
    if (firstTabPane) firstTabPane.classList.add('active');

    // Đóng dropdown nếu đang mở
    document.querySelectorAll('.custom-select-dropdown').forEach(el => el.classList.remove('is-open'));

    const toggleStatus = document.getElementById('prodStatusToggle');

    const setDropdownRadio = (name, value, defaultText, textElementId) => {
        const valStr = String(value || '');
        const targetRadio = document.querySelector(`input[name="${name}"][value="${valStr}"]`)
                         || document.querySelector(`input[name="${name}"][value=""]`);
        if (targetRadio) {
            targetRadio.checked = true;
            const labelText = targetRadio.closest('.radio-circle-item')?.querySelector('.radio-text-label')?.textContent || defaultText;
            const textEl = document.getElementById(textElementId);
            if (textEl) textEl.textContent = labelText;
        }
    };

    if (action === 'add') {
        _setVal('formAction', 'add');
        _setVal('modalTitle', 'Thêm sản phẩm mới', 'innerText');
        _setVal('productId', '0');
        _setVal('prodName', '');
        _setVal('prodSku', '');
        _setVal('prodUnit', '');
        _setVal('prodCostPrice', '');
        _setVal('prodPrice', '');
        _setVal('prodDesc', '');
        _setVal('prodMinStock', '');
        _setVal('prodMaxStock', '');
        _setVal('prodStatus', 'active');
        if (toggleStatus) toggleStatus.checked = true;

        setDropdownRadio('category_id', '', '-- Không phân loại --', 'catSelectedText');
        setDropdownRadio('supplier_id', '', '-- Không chọn --', 'supSelectedText');
        clearErrors();
    } else if (action === 'edit' && data) {
        _setVal('formAction', 'edit');
        _setVal('modalTitle', 'Chỉnh sửa sản phẩm', 'innerText');
        _setVal('productId', data.id);
        _setVal('prodName', data.name);
        _setVal('prodSku', data.sku || '');
        _setVal('prodUnit', data.unit || '');
        const hasNum = v => v !== null && v !== undefined && v !== '';
        _setVal('prodCostPrice', hasNum(data.cost_price) ? formatNumberInput(String(data.cost_price)) : '');
        _setVal('prodPrice', hasNum(data.price) ? formatNumberInput(String(data.price)) : '');
        _setVal('prodDesc', data.description || '');
        _setVal('prodMinStock', data.min_stock ?? '');
        _setVal('prodMaxStock', data.max_stock ?? '');

        const isAct = (data.status || 'active') === 'active';
        _setVal('prodStatus', isAct ? 'active' : 'inactive');
        if (toggleStatus) toggleStatus.checked = isAct;

        setDropdownRadio('category_id', data.category_id, '-- Không phân loại --', 'catSelectedText');
        setDropdownRadio('supplier_id', data.supplier_id, '-- Không chọn --', 'supSelectedText');
        clearErrors();
    }
    modal.style.display = 'flex';
}

function closeProductModal() {
    const modal = document.getElementById('productModal');
    if (modal) modal.style.display = 'none';
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

function openCategoryModalFromProduct() {
    openCategoryModal();
}

function submitCategory(e) {
    e.preventDefault();
    const fd = new FormData();
    fd.append('action', 'add_category');
    fd.append('csrf_token', _getCsrfToken());
    fd.append('name', document.getElementById('catName')?.value ?? '');
    fd.append('description', document.getElementById('catDesc')?.value ?? '');
    fd.append('need_expiry_tracking', document.getElementById('catNeedExpiry')?.checked ? '1' : '0');

    fetch('process.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                const container = document.getElementById('categoryRadioContainer');
                if (container) {
                    const label = document.createElement('label');
                    label.className = 'radio-circle-item';
                    label.id = `cat-radio-label-${data.id}`;

                    const input = document.createElement('input');
                    input.type = 'radio';
                    input.name = 'category_id';
                    input.value = String(data.id);
                    input.checked = true;
                    input.addEventListener('change', () => onRadioSelectChange('cat', data.name));

                    const circle = document.createElement('span');
                    circle.className = 'custom-radio-circle';

                    const text = document.createElement('span');
                    text.className = 'radio-text-label';
                    text.textContent = data.name;

                    label.append(input, circle, text);
                    container.appendChild(label);

                    const textEl = document.getElementById('catSelectedText');
                    if (textEl) textEl.textContent = data.name;
                }
                alert('Thêm danh mục thành công!');
                closeCategoryModal();
            } else {
                alert('Lỗi: ' + data.message);
            }
        })
        .catch(err => alert('Có lỗi xảy ra: ' + err.message));
}

// ── 2. DROPDOWN RADIO SELECT BÊN TRONG MODAL ────────────────────────────────
function toggleCustomDropdown(dropdownId) {
    const dropdown = document.getElementById(dropdownId);
    if (!dropdown) return;

    document.querySelectorAll('.custom-select-dropdown').forEach(el => {
        if (el.id !== dropdownId) el.classList.remove('is-open');
    });

    dropdown.classList.toggle('is-open');
}

function onRadioSelectChange(type, labelText) {
    if (type === 'cat') {
        const textEl = document.getElementById('catSelectedText');
        if (textEl) textEl.textContent = labelText;
        document.getElementById('catCustomDropdown')?.classList.remove('is-open');
    } else if (type === 'sup') {
        const textEl = document.getElementById('supSelectedText');
        if (textEl) textEl.textContent = labelText;
        document.getElementById('supCustomDropdown')?.classList.remove('is-open');
    }
}

// ── 3. XÓA & TÌM KIẾM ────────────────────────────────────────────────────────
function confirmDeleteProduct(id, status, stock = 0) {
    if (status === 'active') {
        let msg = 'Bạn có chắc muốn ngừng kinh doanh sản phẩm này?\nSản phẩm sẽ chuyển sang trạng thái "Ngừng kinh doanh".';
        if (Number(stock) > 0) {
            msg = `⚠️ Sản phẩm vẫn còn ${Number(stock).toLocaleString('vi-VN')} trong kho.\n\n` + msg;
        }
        if (!confirm(msg)) return;
    } else {
        if (!confirm('⚠️ Sản phẩm đang NGỪNG KINH DOANH.\n\nBấm OK để XÓA sản phẩm khỏi danh sách.\nSản phẩm còn tồn kho hoặc đã có lịch sử nhập/xuất sẽ không xóa được.')) return;
        if (!confirm('Xác nhận lần cuối: xóa sản phẩm này?')) return;
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

        // Từ khóa không đổi so với trang hiện tại (vd. chỉ gõ thêm dấu cách) → khỏi tải lại.
        if (keyword === (params.get('keyword') ?? '')) return;

        params.delete('page');
        keyword !== '' ? params.set('keyword', keyword) : params.delete('keyword');
        try { sessionStorage.setItem('product_search_refocus', '1'); } catch (e) { /* bỏ qua */ }
        window.location.href = 'index.php?' + params.toString();
    }, 500);
}

// Sau khi trang tải lại vì tìm kiếm: trả focus về ô tìm, con trỏ ở cuối chữ.
function restoreSearchFocus() {
    let flag = null;
    try {
        flag = sessionStorage.getItem('product_search_refocus');
        sessionStorage.removeItem('product_search_refocus');
    } catch (e) { return; }
    if (flag !== '1') return;
    const input = document.getElementById('searchInput');
    if (!input) return;
    input.focus();
    const end = input.value.length;
    input.setSelectionRange(end, end);
}

// ── 4. VALIDATE TIỀN TỆ & SKU ────────────────────────────────────────────────
let skuCheckTimeout;
function checkSku() {
    const sku = document.getElementById('prodSku');
    if (!sku) return;
    const skuVal    = sku.value.trim();
    const id        = document.getElementById('productId')?.value ?? 0;
    const errorSpan = document.getElementById('skuError');
    clearTimeout(skuCheckTimeout);
    if (skuVal === '') {
        _skuExists = false;
        if (errorSpan) errorSpan.innerText = '';
        return;
    }

    skuCheckTimeout = setTimeout(() => {
        fetch(`process.php?action=check_sku&sku=${encodeURIComponent(skuVal)}&id=${id}`)
            .then(r => { if (!r.ok) throw new Error(`HTTP ${r.status}`); return r.json(); })
            .then(data => {
                if (sku.value.trim() !== skuVal) return; // người dùng đã sửa SKU, bỏ kết quả cũ
                _skuExists = !!data.exists;
                if (errorSpan) errorSpan.innerText = _skuExists ? 'SKU đã tồn tại!' : '';
            })
            .catch(err => {
                console.error('Lỗi kiểm tra SKU:', err);
                if (sku.value.trim() !== skuVal) return;
                // Lỗi mạng ≠ SKU trùng: báo cho người dùng biết nhưng không chặn lưu (server vẫn kiểm tra lại).
                _skuExists = false;
                if (errorSpan) errorSpan.innerText = 'Chưa kiểm tra được SKU (lỗi mạng). Hệ thống sẽ kiểm tra lại khi lưu.';
            });
    }, 300);
}

function _validateMoneyField(inputId, errId, label, { required = false } = {}) {
    const input = document.getElementById(inputId);
    const err   = document.getElementById(errId);
    if (!input) return true;
    const r = parseMoneyInput(input.value);
    let msg = '';
    if (r.empty)            msg = required ? `Vui lòng nhập ${label}!` : '';
    else if (!r.valid)      msg = `${label.charAt(0).toUpperCase() + label.slice(1)} không hợp lệ!`;
    else if (r.value < 0)   msg = `${label.charAt(0).toUpperCase() + label.slice(1)} không được âm!`;
    if (err) err.innerText = msg;
    return msg === '';
}

function validatePrice()     { return _validateMoneyField('prodPrice',     'priceError',     'giá bán', { required: true }); }
function validateCostPrice() { return _validateMoneyField('prodCostPrice', 'costPriceError', 'giá vốn'); }

function attachPriceFormatEvents() {
    ['prodCostPrice', 'prodPrice'].forEach(id => {
        const el = document.getElementById(id);
        if (!el) return;
        if (el.type === 'number') el.type = 'text';
        el.addEventListener('blur', function () {
            const r = parseMoneyInput(this.value);
            // Rỗng hoặc không hợp lệ: giữ nguyên chữ người dùng gõ (validate sẽ báo lỗi), không đổi thành 0.
            if (r.empty || !r.valid) return;
            this.value = formatNumberInput(String(r.value));
        });
    });
}

// ── 5. CHỌN TẤT CẢ & BULK BAR ────────────────────────────────────────────────
window._isAllTotalSelected = false;

function updateSelectAllTotalState() {
    const totalRows    = window._productTotalRows || 0;
    const checkboxes   = document.querySelectorAll('.row-checkbox');
    const checkedBoxes = document.querySelectorAll('.row-checkbox:checked');
    const btnTotal     = document.getElementById('btnSelectAllTotal');
    const bulkCount    = document.getElementById('bulkCount');
    const bulkBar      = document.getElementById('bulkActionBar');

    if (!bulkBar) return;

    if (checkedBoxes.length > 0) {
        bulkBar.style.display = 'flex';
        if (bulkCount) {
            bulkCount.textContent = window._isAllTotalSelected 
                ? `Tất cả ${totalRows} mục đã chọn` 
                : `${checkedBoxes.length} mục đã chọn`;
        }
    } else {
        bulkBar.style.display = 'none';
        window._isAllTotalSelected = false;
    }

    if (!btnTotal) return;

    if (checkboxes.length > 0 && checkedBoxes.length === checkboxes.length && totalRows > checkboxes.length) {
        btnTotal.style.display = 'inline-block';
    } else if (!window._isAllTotalSelected) {
        btnTotal.style.display = 'none';
        btnTotal.classList.remove('active');
        btnTotal.textContent = `Chọn tất cả ${totalRows}`;
    }
}

function toggleSelectAllTotal() {
    const totalRows    = window._productTotalRows || 0;
    const btnTotal     = document.getElementById('btnSelectAllTotal');
    const bulkCount    = document.getElementById('bulkCount');
    const checkedCount = document.querySelectorAll('.row-checkbox:checked').length;

    window._isAllTotalSelected = !window._isAllTotalSelected;

    if (window._isAllTotalSelected) {
        btnTotal.classList.add('active');
        btnTotal.textContent = 'Bỏ chọn toàn bộ';
        if (bulkCount) bulkCount.textContent = `Tất cả ${totalRows} mục đã chọn`;
    } else {
        btnTotal.classList.remove('active');
        btnTotal.textContent = `Chọn tất cả ${totalRows}`;
        if (bulkCount) bulkCount.textContent = `${checkedCount} mục đã chọn`;
    }
}

function clearSelection() {
    window._isAllTotalSelected = false;
    document.querySelectorAll('.row-checkbox').forEach(cb => cb.checked = false);
    const sa = document.getElementById('selectAll');
    if (sa) { sa.checked = false; sa.indeterminate = false; }

    const bulkBar  = document.getElementById('bulkActionBar');
    const btnTotal = document.getElementById('btnSelectAllTotal');
    if (bulkBar)  bulkBar.style.display = 'none';
    if (btnTotal) {
        btnTotal.style.display = 'none';
        btnTotal.classList.remove('active');
    }
}

function exportSelectedExcel() {
    const params = new URLSearchParams(window.location.search);
    if (window._isAllTotalSelected) {
        params.set('all', '1');
    } else {
        const checkedBoxes = Array.from(document.querySelectorAll('.row-checkbox:checked'));
        if (!checkedBoxes.length) {
            alert('Chưa chọn sản phẩm nào.');
            return;
        }
        const ids = checkedBoxes.map(cb => cb.getAttribute('data-id')).filter(Boolean);
        params.set('ids', ids.join(','));
    }
    window.location.href = '../../pages/export/Export.php?type=products&' + params.toString();
}

// ── 6. KHỞI TẠO SỰ KIỆN DOM ──────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
    // 6.1 Bật/tắt Sidebar bộ lọc
    const toggleBtn     = document.getElementById('btnToggleSidebar');
    const filterSidebar = document.getElementById('taskFilterSidebar');
    const toggleTxt     = document.getElementById('txtToggleSidebar');

    const isFilterHidden = localStorage.getItem('product_filter_hidden') === 'true';
    if (toggleTxt) {
        toggleTxt.textContent = isFilterHidden ? 'Hiện bộ lọc' : 'Ẩn bộ lọc';
    }

    if (isFilterHidden) {
        if (filterSidebar) filterSidebar.classList.add('hidden');
        document.documentElement.classList.add('product-filter-hidden');
    } else {
        if (filterSidebar) filterSidebar.classList.remove('hidden');
        document.documentElement.classList.remove('product-filter-hidden');
    }

    if (toggleBtn && filterSidebar) {
        toggleBtn.addEventListener('click', function () {
            const currentlyHidden = document.documentElement.classList.contains('product-filter-hidden') 
                                 || filterSidebar.classList.contains('hidden');
            const willHide = !currentlyHidden;

            if (willHide) {
                filterSidebar.classList.add('hidden');
                document.documentElement.classList.add('product-filter-hidden');
                if (toggleTxt) toggleTxt.textContent = 'Hiện bộ lọc';
            } else {
                filterSidebar.classList.remove('hidden');
                document.documentElement.classList.remove('product-filter-hidden');
                if (toggleTxt) toggleTxt.textContent = 'Ẩn bộ lọc';
            }
            localStorage.setItem('product_filter_hidden', willHide);
        });
    }

    // 6.2 Accordion bộ lọc (Đọc trạng thái đã lưu để gán class ban đầu)
    let collapsedSections = [];
    try {
        collapsedSections = JSON.parse(localStorage.getItem('collapsed_filter_sections') || '[]');
    } catch (e) {
        collapsedSections = [];
    }

    document.querySelectorAll('.filter-section[data-filter-key]').forEach(section => {
        const key = section.getAttribute('data-filter-key');
        if (collapsedSections.includes(key)) {
            section.classList.add('is-collapsed');
        } else {
            section.classList.remove('is-collapsed');
        }
    });

    document.querySelectorAll('.filter-sec-title').forEach(title => {
        title.addEventListener('click', function () {
            const section = this.closest('.filter-section');
            if (!section) return;

            const isCollapsed = section.classList.toggle('is-collapsed');
            const key = section.getAttribute('data-filter-key');

            if (key) {
                if (isCollapsed) {
                    if (!collapsedSections.includes(key)) collapsedSections.push(key);
                } else {
                    collapsedSections = collapsedSections.filter(k => k !== key);
                }
                localStorage.setItem('collapsed_filter_sections', JSON.stringify(collapsedSections));
            }
        });
    });

    // 6.3 Checkbox & Select All
    const selectAllCheckbox = document.getElementById('selectAll');
    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', function () {
            const isChecked = this.checked;
            document.querySelectorAll('.row-checkbox').forEach(cb => {
                cb.checked = isChecked;
            });
            // Bỏ tick "chọn cả trang" thì cũng hết ý "tất cả N mục".
            if (!isChecked) window._isAllTotalSelected = false;
            updateSelectAllTotalState();
        });
    }

    document.addEventListener('change', function (e) {
        if (e.target.classList.contains('row-checkbox')) {
            // Bỏ tick 1 dòng → không còn là "tất cả N mục": nếu giữ cờ, export all=1 vẫn xuất cả dòng vừa bỏ.
            if (!e.target.checked) window._isAllTotalSelected = false;
            const allCheckboxes     = document.querySelectorAll('.row-checkbox');
            const checkedCheckboxes = document.querySelectorAll('.row-checkbox:checked');
            if (selectAllCheckbox) {
                selectAllCheckbox.checked = (allCheckboxes.length > 0 && allCheckboxes.length === checkedCheckboxes.length);
                selectAllCheckbox.indeterminate = (checkedCheckboxes.length > 0 && checkedCheckboxes.length < allCheckboxes.length);
            }
            updateSelectAllTotalState();
        }
    });

    // 6.4 Chuyển Tab trong Modal
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('tab-pill-btn')) {
            const tabId = e.target.getAttribute('data-tab');
            document.querySelectorAll('.tab-pill-btn').forEach(btn => btn.classList.remove('active'));
            document.querySelectorAll('.tab-pane').forEach(pane => pane.classList.remove('active'));
            e.target.classList.add('active');
            const targetPane = document.getElementById(tabId);
            if (targetPane) targetPane.classList.add('active');
        }
    });

    // 6.5 Click ngoài đóng Dropdown & Modal
    // Nhớ nơi nhấn chuột xuống: bôi đen chữ trong ô rồi thả chuột ngoài vùng modal
    // sinh ra click trên nền modal — không được coi là "click ra ngoài".
    let mouseDownTarget = null;
    document.addEventListener('mousedown', function(e) { mouseDownTarget = e.target; });

    document.addEventListener('click', function(e) {
        if (!e.target.closest('.custom-select-dropdown')) {
            document.querySelectorAll('.custom-select-dropdown').forEach(el => {
                el.classList.remove('is-open');
            });
        }
        const productModal  = document.getElementById('productModal');
        const categoryModal = document.getElementById('categoryModal');
        if (e.target === productModal  && mouseDownTarget === productModal)  closeProductModal();
        if (e.target === categoryModal && mouseDownTarget === categoryModal) closeCategoryModal();

        // 14. Nút Sửa: đọc dữ liệu từ data-product thay vì nhúng JSON vào onclick
        const editBtn = e.target.closest?.('.btn-edit-product');
        if (editBtn) {
            let data = null;
            try { data = JSON.parse(editBtn.dataset.product || '{}'); } catch (err) { data = null; }
            if (!data || !data.id) {
                alert('Không đọc được dữ liệu sản phẩm. Vui lòng tải lại trang.');
                return;
            }
            openProductModal('edit', data);
        }
    });

    // 6.6 Submit Form & Phím tắt tìm kiếm
    attachPriceFormatEvents();

    const productForm = document.getElementById('productForm');
    if (productForm) {
        productForm.addEventListener('submit', function (e) {
            const costEl  = document.getElementById('prodCostPrice');
            const priceEl = document.getElementById('prodPrice');

            // Kiểm tra TRƯỚC, chưa đụng vào giá trị ô: lỗi thì ô vẫn giữ định dạng người dùng đang xem.
            const priceOk = validatePrice();
            const costOk  = validateCostPrice();
            if (!priceOk || !costOk) {
                e.preventDefault();
                _showPricingTab();
                alert('Vui lòng nhập giá hợp lệ (bắt buộc giá bán, không âm).');
                return;
            }
            if (_skuExists) {
                e.preventDefault();
                alert('SKU không hợp lệ hoặc đã tồn tại.');
                return;
            }

            // Hợp lệ: chuẩn hóa về số thuần ("1234.5") cho server. Ô rỗng giữ rỗng
            // (giá vốn rỗng = 0 ở server; giá bán đã được chặn ở trên).
            const cost  = parseMoneyInput(costEl?.value);
            const price = parseMoneyInput(priceEl?.value);
            if (costEl)  costEl.value  = cost.empty  ? '' : String(cost.value);
            if (priceEl) priceEl.value = price.empty ? '' : String(price.value);
        });
    }

    document.addEventListener('keydown', function (e) {
        if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
            e.preventDefault();
            const searchInput = document.getElementById('searchInput');
            if (searchInput) searchInput.focus();
        }
    });

    // ── ĐỒNG BỘ CHIỀU CAO SIDEBAR FILTER BẰNG KHUNG BẢNG ─────────────────────
    function syncSidebarHeight() {
        const tableCard = document.querySelector('.task-table-card');
        const toolbar   = document.querySelector('.task-top-toolbar');
        const sidebar   = document.getElementById('taskFilterSidebar');

        if (tableCard && toolbar && sidebar) {
            const targetHeight = toolbar.offsetHeight + 16 + tableCard.offsetHeight;
            sidebar.style.height = `${targetHeight}px`;
        }
    }
    syncSidebarHeight();
    window.addEventListener('resize', syncSidebarHeight);
    // Font web / ảnh tải xong hoặc bảng đổi kích thước (đổi số dòng, ẩn/hiện sidebar) → đồng bộ lại.
    window.addEventListener('load', syncSidebarHeight);
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(syncSidebarHeight);
    if (window.ResizeObserver) {
        const ro = new ResizeObserver(syncSidebarHeight);
        [document.querySelector('.task-table-card'), document.querySelector('.task-top-toolbar')]
            .forEach(el => { if (el) ro.observe(el); });
    }

    restoreSearchFocus();
});

// ── EXPORT GLOBAL FUNCTIONS ──────────────────────────────────────────────────
window.openProductModal             = openProductModal;
window.closeProductModal            = closeProductModal;
window.confirmDeleteProduct         = confirmDeleteProduct;
window.searchProductTable           = searchProductTable;
window.openCategoryModal            = openCategoryModal;
window.closeCategoryModal           = closeCategoryModal;
window.openCategoryModalFromProduct = openCategoryModalFromProduct;
window.submitCategory               = submitCategory;
window.checkSku                     = checkSku;
window.validatePrice                = validatePrice;
window.validateCostPrice            = validateCostPrice;
window.toggleSelectAllTotal         = toggleSelectAllTotal;
window.clearSelection               = clearSelection;
window.exportSelectedExcel          = exportSelectedExcel;
window.toggleCustomDropdown         = toggleCustomDropdown;
window.onRadioSelectChange          = onRadioSelectChange;