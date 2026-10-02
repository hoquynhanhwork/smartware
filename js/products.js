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
        _setVal('prodCostPrice', data.cost_price ? formatNumberInput(String(data.cost_price)) : '');
        _setVal('prodPrice', data.price ? formatNumberInput(String(data.price)) : '');
        _setVal('prodDesc', data.description || '');
        
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

    fetch('process.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                const container = document.getElementById('categoryRadioContainer');
                if (container) {
                    const label = document.createElement('label');
                    label.className = 'radio-circle-item';
                    label.id = `cat-radio-label-${data.id}`;
                    label.innerHTML = `
                        <input type="radio" name="category_id" value="${data.id}" checked onchange="onRadioSelectChange('cat', '${data.name}')">
                        <span class="custom-radio-circle"></span>
                        <span class="radio-text-label">${data.name}</span>
                    `;
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

// ── 4. VALIDATE TIỀN TỆ & SKU ────────────────────────────────────────────────
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
    if (err) err.innerText = 'Giá bán không được âm!';
    return false;
}

function validateCostPrice() {
    const input = document.getElementById('prodCostPrice');
    const err   = document.getElementById('costPriceError');
    if (!input) return true;
    const val = parseFloat(unformatNumber(input.value));
    if (isNaN(val) || val >= 0) { if (err) err.innerText = ''; return true; }
    if (err) err.innerText = 'Giá vốn không được âm!';
    return false;
}

function attachPriceFormatEvents() {
    ['prodCostPrice', 'prodPrice'].forEach(id => {
        const el = document.getElementById(id);
        if (!el) return;
        if (el.type === 'number') el.type = 'text';
        el.addEventListener('blur', function () {
            this.value = formatNumberInput(unformatNumber(this.value).toString());
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
        toggleTxt.textContent = isFilterHidden ? 'Show Filters' : 'Hide Filters';
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
                if (toggleTxt) toggleTxt.textContent = 'Show Filters';
            } else {
                filterSidebar.classList.remove('hidden');
                document.documentElement.classList.remove('product-filter-hidden');
                if (toggleTxt) toggleTxt.textContent = 'Hide Filters';
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
            updateSelectAllTotalState();
        });
    }

    document.addEventListener('change', function (e) {
        if (e.target.classList.contains('row-checkbox')) {
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
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.custom-select-dropdown')) {
            document.querySelectorAll('.custom-select-dropdown').forEach(el => {
                el.classList.remove('is-open');
            });
        }
        if (e.target === document.getElementById('productModal'))  closeProductModal();
        if (e.target === document.getElementById('categoryModal')) closeCategoryModal();
    });

    // 6.6 Submit Form & Phím tắt tìm kiếm
    attachPriceFormatEvents();

    const productForm = document.getElementById('productForm');
    if (productForm) {
        productForm.addEventListener('submit', function (e) {
            const costEl  = document.getElementById('prodCostPrice');
            const priceEl = document.getElementById('prodPrice');
            if (costEl)  costEl.value  = unformatNumber(costEl.value);
            if (priceEl) priceEl.value = unformatNumber(priceEl.value);

            if (!validatePrice() || !validateCostPrice()) {
                e.preventDefault();
                alert('Vui lòng nhập giá hợp lệ (không âm).');
                return;
            }
            const skuError = document.getElementById('skuError');
            if (skuError && skuError.innerText !== '') {
                e.preventDefault();
                alert('SKU không hợp lệ hoặc đã tồn tại.');
            }
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