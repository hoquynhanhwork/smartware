// js/suppliers.js
'use strict';

function _getCsrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

function _setVal(id, val, prop = 'value') {
    const el = document.getElementById(id);
    if (el) el[prop] = val;
}

let addressData = null;

function loadAddressData() {
    return fetch('../../js/vn-address.json')
        .then(response => {
            if (!response.ok) throw new Error('HTTP ' + response.status);
            return response.json();
        })
        .then(data => {
            addressData = data;
            populateProvinces();
            populateFilterProvinces();
        })
        .catch(err => console.error('Không thể tải dữ liệu địa chỉ', err));
}

function populateProvinces() {
    const sel = document.getElementById('province');
    if (!sel || !addressData) return;
    sel.innerHTML = '<option value="">Chọn tỉnh / thành</option>';
    addressData.forEach(p => {
        const opt       = document.createElement('option');
        opt.value       = p.Code;
        opt.textContent = p.Name;
        sel.appendChild(opt);
    });
}

function populateWards(provinceCode) {
    const sel = document.getElementById('ward');
    if (!sel) return;
    sel.innerHTML = '<option value="">Chọn phường / xã</option>';
    if (!provinceCode) { sel.disabled = true; return; }
    const province = addressData?.find(p => p.Code == provinceCode);
    if (province?.Wards?.length) {
        sel.disabled = false;
        province.Wards.forEach(w => {
            const opt       = document.createElement('option');
            opt.value       = w.Code;
            opt.textContent = w.Name;
            sel.appendChild(opt);
        });
    } else {
        sel.disabled = true;
    }
}

function toggleAddressScope(scope) {
    const vnBlock      = document.getElementById('addressVNBlock');
    const foreignBlock = document.getElementById('addressForeignBlock');
    if (!vnBlock || !foreignBlock) return;

    if (scope === 'VN') {
        vnBlock.style.display      = 'block';
        foreignBlock.style.display = 'none';

        _setVal('foreignCountry', '');
        _setVal('foreignState', '');
        _setVal('foreignCity', '');
        _setVal('foreignPostal', '');
    } else {
        vnBlock.style.display      = 'none';
        foreignBlock.style.display = 'block';

        const provSel = document.getElementById('province');
        const wardSel = document.getElementById('ward');
        if (provSel) provSel.value = '';
        if (wardSel) { wardSel.innerHTML = '<option value="">Chọn phường / xã</option>'; wardSel.disabled = true; }
    }
    updateFullAddress();
}

function updateFullAddress() {
    const scope       = document.querySelector('input[name="address_scope"]:checked')?.value || 'VN';
    const addrDisplay = document.getElementById('address_display');

    if (scope === 'VN') {
        const provSel = document.getElementById('province');
        const wardSel = document.getElementById('ward');
        if (!provSel || !wardSel) return;

        const provinceCode = provSel.value;
        const wardCode     = wardSel.value;
        const provinceName = provSel.options[provSel.selectedIndex]?.text || '';
        const wardName     = wardSel.options[wardSel.selectedIndex]?.text || '';

        let fullAddress = '';
        if (wardName)     fullAddress += wardName;
        if (provinceName) fullAddress += (fullAddress ? ', ' : '') + provinceName;

        if (addrDisplay) addrDisplay.innerText = fullAddress || '';

        _setVal('provinceCode', provinceCode);
        _setVal('wardCode', wardCode);
        _setVal('countryCode', 'VN');
        _setVal('cityField', '');
        _setVal('stateField', '');
        _setVal('postalField', '');
    } else {
        const country = document.getElementById('foreignCountry')?.value.trim() || '';
        const state   = document.getElementById('foreignState')?.value.trim()   || '';
        const city    = document.getElementById('foreignCity')?.value.trim()    || '';
        const postal  = document.getElementById('foreignPostal')?.value.trim()  || '';

        const fullAddress = [city, state, country].filter(Boolean).join(', ');
        if (addrDisplay) addrDisplay.innerText = fullAddress || '';

        _setVal('provinceCode', '');
        _setVal('wardCode', '');
        _setVal('countryCode', country || 'OTHER');
        _setVal('cityField', city);
        _setVal('stateField', state);
        _setVal('postalField', postal);
    }
}

function populateFilterProvinces() {
    const sel = document.getElementById('filter_province');
    if (!sel || !addressData) return;
    sel.innerHTML = '<option value="">Tỉnh / Thành</option>';
    addressData.forEach(p => {
        const opt       = document.createElement('option');
        opt.value       = p.Code;
        opt.textContent = p.Name;
        sel.appendChild(opt);
    });
    const selected = new URLSearchParams(window.location.search).get('province');
    if (selected) {
        sel.value = selected;
        populateFilterWards(selected);
    }
}

function populateFilterWards(provinceCode) {
    const sel = document.getElementById('filter_ward');
    if (!sel) return;
    sel.innerHTML = '<option value="">Phường / Xã</option>';
    if (!provinceCode) return;
    const province = addressData?.find(p => p.Code == provinceCode);
    if (province?.Wards?.length) {
        province.Wards.forEach(w => {
            const opt       = document.createElement('option');
            opt.value       = w.Code;
            opt.textContent = w.Name;
            sel.appendChild(opt);
        });
        const selected = new URLSearchParams(window.location.search).get('ward');
        if (selected) sel.value = selected;
    }
}

// ── MODAL THÊM / SỬA ĐỐI TÁC ────────────────────────────────────────────────
function openSupplierModal(action, data = null) {
    const modal = document.getElementById('supplierModal');
    if (!modal) return;
    const toggleStatus = document.getElementById('supStatusToggle');

    document.querySelectorAll('.error-message').forEach(el => el.innerText = '');

    if (action === 'add') {
        _setVal('formAction', 'add');
        _setVal('modalTitle', 'Thêm đối tác mới', 'innerText');
        _setVal('supplierId', 0);
        _setVal('supName', '');
        _setVal('supPhone', '');
        _setVal('supEmail', '');
        _setVal('supTaxCode', '');
        _setVal('supStatus', 'active');
        _setVal('supEntityOrigin', 'domestic');
        _setVal('supAddress', '');
        if (toggleStatus) toggleStatus.checked = true;

        document.getElementById('scopeVN').checked = true;
        toggleAddressScope('VN');

        const province = document.getElementById('province');
        if (province) province.value = '';
        const ward = document.getElementById('ward');
        if (ward) { ward.innerHTML = '<option value="">Chọn phường / xã</option>'; ward.disabled = true; }
        _setVal('address_display', '', 'innerText');
    } else if (action === 'edit' && data) {
        _setVal('formAction', 'edit');
        _setVal('modalTitle', 'Chỉnh sửa đối tác', 'innerText');
        _setVal('supplierId', data.id);
        _setVal('supName', data.name);
        _setVal('supPhone', data.phone || '');
        _setVal('supEmail', data.email || '');
        _setVal('supTaxCode', data.tax_code || '');
        _setVal('supEntityOrigin', data.entity_origin || 'domestic');

        const isAct = (data.status || 'active') === 'active';
        _setVal('supStatus', isAct ? 'active' : 'inactive');
        if (toggleStatus) toggleStatus.checked = isAct;

        const isForeign = !!data.country_code && data.country_code !== 'VN';
        document.getElementById(isForeign ? 'scopeForeign' : 'scopeVN').checked = true;
        toggleAddressScope(isForeign ? 'FOREIGN' : 'VN');

        if (!isForeign && data.province_code) {
            const province = document.getElementById('province');
            if (province) {
                province.value = data.province_code;
                populateWards(data.province_code);
                setTimeout(() => {
                    const ward = document.getElementById('ward');
                    if (ward && data.ward_code) ward.value = data.ward_code;
                    updateFullAddress();
                }, 100);
            }
        } else if (isForeign) {
            _setVal('foreignCountry', data.country_code   || '');
            _setVal('foreignState', data.state_province || '');
            _setVal('foreignCity', data.city           || '');
            _setVal('foreignPostal', data.postal_code    || '');
            updateFullAddress();
        }

        _setVal('supAddress', data.address || '');
        _setVal('address_display', data.address || '', 'innerText');
    }

    modal.style.display = 'flex';
}

function closeSupplierModal() {
    const modal = document.getElementById('supplierModal');
    if (modal) modal.style.display = 'none';
}

function confirmDeleteSupplier(id, status) {
    const isInactive = status === 'inactive';
    const msg = isInactive
        ? 'Đối tác này đang ngừng hợp tác.\nBấm OK để xóa vĩnh viễn.'
        : 'Bạn có chắc muốn ngừng hợp tác với đối tác này?';

    if (!confirm(msg)) return;

    fetch('process.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': _getCsrfToken() },
        body: 'action=delete&id=' + encodeURIComponent(id),
    })
    .then(res => { if (!res.ok) throw new Error(`Lỗi server: ${res.status}`); return res.json(); })
    .then(data => {
        if (data.ok) window.location.href = 'index.php';
        else alert('Không thể thực hiện: ' + data.message);
    })
    .catch(err => alert('Có lỗi xảy ra: ' + err.message));
}

let _searchTimer = null;
function searchSupplierTable() {
    clearTimeout(_searchTimer);
    _searchTimer = setTimeout(() => {
        const keyword = document.getElementById('searchInput')?.value.trim() ?? '';
        const params  = new URLSearchParams(window.location.search);
        params.delete('page');
        keyword !== '' ? params.set('keyword', keyword) : params.delete('keyword');
        window.location.href = 'index.php?' + params.toString();
    }, 350);
}

let checkTimeout;
function checkUnique(field) {
    const input = document.getElementById(`sup${field.charAt(0).toUpperCase() + field.slice(1)}`);
    if (!input) return;
    const value     = input.value.trim();
    const id        = document.getElementById('supplierId')?.value || 0;
    const errorSpan = document.getElementById(`${field}Error`);
    if (value === '') { if (errorSpan) errorSpan.innerText = ''; return; }

    clearTimeout(checkTimeout);
    checkTimeout = setTimeout(() => {
        fetch(`process.php?action=check_unique&field=${encodeURIComponent(field)}&value=${encodeURIComponent(value)}&id=${encodeURIComponent(id)}`)
            .then(res => res.json())
            .then(data => {
                if (errorSpan) {
                    const labels = { name: 'Tên', phone: 'Số điện thoại', email: 'Email', tax_code: 'Mã số thuế' };
                    errorSpan.innerText = data.exists ? `${labels[field] || field} đã tồn tại!` : '';
                }
            })
            .catch(err => console.error('Lỗi kiểm tra trùng lặp:', err));
    }, 300);
}

// ── BULK ACTION ──────────────────────────────────────────────────────────────
window._isAllTotalSelected = false;

function updateSelectAllTotalState() {
    const totalRows    = window._supplierTotalRows || 0;
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
                ? `Tất cả ${totalRows} đối tác đã chọn` 
                : `${checkedBoxes.length} đối tác đã chọn`;
        }
    } else {
        bulkBar.style.display = 'none';
        window._isAllTotalSelected = false;
        if (btnTotal) btnTotal.style.display = 'none';
        return;
    }

    if (!btnTotal) return;

    if (checkboxes.length > 0 && checkedBoxes.length === checkboxes.length && totalRows > checkboxes.length) {
        btnTotal.style.display = 'inline-block';
        if (!window._isAllTotalSelected) {
            btnTotal.textContent = `Chọn tất cả ${totalRows}`;
            btnTotal.classList.remove('active');
        }
    } else if (!window._isAllTotalSelected) {
        btnTotal.style.display = 'none';
    }
}

function toggleSelectAllTotal() {
    const totalRows    = window._supplierTotalRows || 0;
    const btnTotal     = document.getElementById('btnSelectAllTotal');
    const bulkCount    = document.getElementById('bulkCount');
    const checkedCount = document.querySelectorAll('.row-checkbox:checked').length;

    window._isAllTotalSelected = !window._isAllTotalSelected;

    if (window._isAllTotalSelected) {
        if (btnTotal) { btnTotal.classList.add('active'); btnTotal.textContent = 'Bỏ chọn toàn bộ'; }
        if (bulkCount) bulkCount.textContent = `Tất cả ${totalRows} đối tác đã chọn`;
    } else {
        if (btnTotal) { btnTotal.classList.remove('active'); btnTotal.textContent = `Chọn tất cả ${totalRows}`; }
        if (bulkCount) bulkCount.textContent = `${checkedCount} đối tác đã chọn`;
    }
}

function clearSelection() {
    window._isAllTotalSelected = false;
    document.querySelectorAll('.row-checkbox').forEach(cb => cb.checked = false);
    const sa = document.getElementById('selectAll');
    if (sa) { sa.checked = false; sa.indeterminate = false; }
    updateSelectAllTotalState();
}

function exportSelectedExcel() {
    const params = new URLSearchParams(window.location.search);
    if (window._isAllTotalSelected) {
        params.set('all', '1');
    } else {
        const ids = [...document.querySelectorAll('.row-checkbox:checked')].map(cb => cb.dataset.id).filter(Boolean);
        if (!ids.length) { alert('Chưa chọn đối tác nào.'); return; }
        params.set('ids', ids.join(','));
    }
    window.location.href = '../../pages/export/Export.php?type=suppliers&' + params.toString();
}

// Bộ chọn ngày: xem js/date-picker.js (dùng chung)

// ── DOM INITIALIZATION ───────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
    loadAddressData();

    // Ẩn/hiện + thu gọn nhóm bộ lọc: xem js/filter-sidebar.js (dùng chung)

    // 3. Checkboxes & Select all
    const selectAllCheckbox = document.getElementById('selectAll');
    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', function () {
            const isChecked = this.checked;
            document.querySelectorAll('.row-checkbox').forEach(cb => cb.checked = isChecked);
            updateSelectAllTotalState();
        });
    }

    document.addEventListener('change', function (e) {
        if (e.target.classList.contains('row-checkbox')) {
            const allBoxes     = document.querySelectorAll('.row-checkbox');
            const checkedBoxes = document.querySelectorAll('.row-checkbox:checked');
            if (selectAllCheckbox) {
                selectAllCheckbox.checked = (allBoxes.length > 0 && allBoxes.length === checkedBoxes.length);
                selectAllCheckbox.indeterminate = (checkedBoxes.length > 0 && checkedBoxes.length < allBoxes.length);
            }
            updateSelectAllTotalState();
        }
    });

    // 4. Form Submit
    const supplierForm = document.getElementById('supplierForm');
    if (supplierForm) {
        supplierForm.addEventListener('submit', function (e) {
            let hasError = false;
            const name = document.getElementById('supName')?.value.trim() ?? '';
            const nameErr = document.getElementById('nameError');
            if (!name) { if (nameErr) nameErr.innerText = 'Tên không được để trống'; hasError = true; }
            ['nameError', 'phoneError', 'emailError', 'taxCodeError'].forEach(id => {
                const el = document.getElementById(id);
                if (el && el.innerText !== '') hasError = true;
            });
            if (hasError) { e.preventDefault(); alert('Vui lòng kiểm tra lại thông tin.'); }
        });
    }

    // 5. Sự kiện lọc khu vực
    const filterProvince = document.getElementById('filter_province');
    if (filterProvince) {
        filterProvince.addEventListener('change', function () {
            populateFilterWards(this.value);
        });
    }

    window.addEventListener('click', function (e) {
        if (e.target === document.getElementById('supplierModal')) closeSupplierModal();
    });
});

// ── EXPORT GLOBAL FUNCTIONS ──────────────────────────────────────────────────
window.openSupplierModal        = openSupplierModal;
window.closeSupplierModal       = closeSupplierModal;
window.confirmDeleteSupplier    = confirmDeleteSupplier;
window.searchSupplierTable      = searchSupplierTable;
window.checkUnique              = checkUnique;
window.loadAddressData          = loadAddressData;
window.populateWards            = populateWards;
window.populateFilterWards      = populateFilterWards;
window.updateFullAddress        = updateFullAddress;
window.toggleAddressScope       = toggleAddressScope;
window.toggleSelectAllTotal     = toggleSelectAllTotal;
window.clearSelection           = clearSelection;
window.exportSelectedExcel      = exportSelectedExcel;
