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

// =======================================================
// BỘ CHỌN NGÀY PRESET + CALENDAR RANGE (DAYS, MONTHS, YEARS)
// =======================================================
let _calViewMode = 'days'; 
let _calViewDate = new Date();
let _rangeStart = null;
let _rangeEnd = null;

const _shortMonths = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

function _formatDateYMD(d) {
    if (!d) return '';
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${y}-${m}-${day}`;
}

function _formatShortVN(d) {
    if (!d) return '';
    const day = String(d.getDate()).padStart(2, '0');
    const month = String(d.getMonth() + 1).padStart(2, '0');
    const year = d.getFullYear();
    return `${day}/${month}/${year}`;
}

function toggleDatePickerPopover(e) {
    if (e) e.stopPropagation();
    const container = document.getElementById('neoDatePicker');
    if (!container) return;
    const isOpen = container.classList.toggle('is-open');
    if (isOpen) {
        if (_rangeStart && _rangeEnd) {
            openCalendarPicker();
        } else {
            document.getElementById('datepickerPresetList').style.display = 'flex';
            document.getElementById('datepickerCalendarPanel').style.display = 'none';
        }
    }
}

function openCalendarPicker() {
    document.getElementById('datepickerPresetList').style.display = 'none';
    document.getElementById('datepickerCalendarPanel').style.display = 'block';
    _calViewMode = 'days';
    renderCalendar();
}

function backToPresets() {
    document.getElementById('datepickerPresetList').style.display = 'flex';
    document.getElementById('datepickerCalendarPanel').style.display = 'none';
}

function selectQuickDate(preset) {
    const today = new Date();
    let fromD = new Date(today);
    let toD = new Date(today);
    let label = 'Hôm nay';

    if (preset === 'today') {
        label = 'Hôm nay';
    } else if (preset === 'yesterday') {
        fromD.setDate(today.getDate() - 1);
        toD.setDate(today.getDate() - 1);
        label = 'Hôm qua';
    } else if (preset === 'last7') {
        fromD.setDate(today.getDate() - 6);
        label = '7 ngày trước';
    } else if (preset === 'last14') {
        fromD.setDate(today.getDate() - 13);
        label = '14 ngày trước';
    } else if (preset === 'last30') {
        fromD.setDate(today.getDate() - 29);
        label = '30 ngày trước';
    }

    _setVal('neoDateFrom', _formatDateYMD(fromD));
    _setVal('neoDateTo', _formatDateYMD(toD));
    _setVal('neoDatePickerText', label, 'innerText');
    document.getElementById('filterForm')?.submit();
}

function onCalTitleClick() {
    if (_calViewMode === 'days') {
        _calViewMode = 'months';
    } else if (_calViewMode === 'months') {
        _calViewMode = 'years';
    }
    renderCalendar();
}

function changeCalStep(step) {
    if (_calViewMode === 'days') {
        _calViewDate.setMonth(_calViewDate.getMonth() + step);
    } else if (_calViewMode === 'months') {
        _calViewDate.setFullYear(_calViewDate.getFullYear() + step);
    } else if (_calViewMode === 'years') {
        _calViewDate.setFullYear(_calViewDate.getFullYear() + (step * 12));
    }
    renderCalendar();
}

function renderCalendar() {
    const daysView = document.getElementById('calDaysView');
    const monthsView = document.getElementById('calMonthsView');
    const yearsView = document.getElementById('calYearsView');
    const titleBtn = document.getElementById('calMainTitleBtn');

    if (!daysView || !monthsView || !yearsView || !titleBtn) return;

    daysView.style.display = _calViewMode === 'days' ? 'block' : 'none';
    monthsView.style.display = _calViewMode === 'months' ? 'block' : 'none';
    yearsView.style.display = _calViewMode === 'years' ? 'block' : 'none';

    const year = _calViewDate.getFullYear();
    const month = _calViewDate.getMonth();

    if (_calViewMode === 'days') {
        titleBtn.innerText = `Tháng ${month + 1} ${year}`;
        renderDaysGrid();
    } else if (_calViewMode === 'months') {
        titleBtn.innerText = `${year}`;
        renderMonthsGrid();
    } else if (_calViewMode === 'years') {
        const startYear = year - (year % 12);
        const endYear = startYear + 11;
        titleBtn.innerText = `${startYear}-${endYear}`;
        renderYearsGrid(startYear);
    }
}

function renderDaysGrid() {
    const year = _calViewDate.getFullYear();
    const month = _calViewDate.getMonth();
    const grid = document.getElementById('calDaysGrid');
    if (!grid) return;
    grid.innerHTML = '';

    const firstDayIndex = new Date(year, month, 1).getDay();
    const totalDays = new Date(year, month + 1, 0).getDate();

    for (let x = 0; x < firstDayIndex; x++) {
        const emptyCell = document.createElement('div');
        emptyCell.className = 'cal-day-num empty';
        grid.appendChild(emptyCell);
    }

    for (let day = 1; day <= totalDays; day++) {
        const cell = document.createElement('div');
        cell.className = 'cal-day-num';
        cell.innerText = day;
        const cellDate = new Date(year, month, day);

        const ymd = _formatDateYMD(cellDate);
        const startYMD = _formatDateYMD(_rangeStart);
        const endYMD = _formatDateYMD(_rangeEnd);

        if (startYMD && ymd === startYMD) {
            cell.classList.add('selected-start');
        } else if (endYMD && ymd === endYMD) {
            cell.classList.add('selected-end');
        } else if (_rangeStart && _rangeEnd && cellDate > _rangeStart && cellDate < _rangeEnd) {
            cell.classList.add('in-range');
        }

        cell.onclick = (e) => {
            e.stopPropagation();
            onSelectRangeDay(cellDate);
        };
        grid.appendChild(cell);
    }

    const hint = document.getElementById('calRangeHint');
    if (hint) {
        if (_rangeStart && _rangeEnd) {
            hint.innerText = `${_formatShortVN(_rangeStart)} → ${_formatShortVN(_rangeEnd)}`;
        } else if (_rangeStart) {
            hint.innerText = `Từ: ${_formatShortVN(_rangeStart)} (Chọn ngày kết thúc)`;
        } else {
            hint.innerText = 'Chọn ngày bắt đầu';
        }
    }
}

function renderMonthsGrid() {
    const grid = document.getElementById('calMonthsGrid');
    if (!grid) return;
    grid.innerHTML = '';

    const curMonth = _calViewDate.getMonth();

    _shortMonths.forEach((mName, idx) => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'cal-grid-item';
        btn.innerText = mName;

        if (idx === curMonth) {
            btn.classList.add('selected');
        }

        btn.onclick = (e) => {
            e.stopPropagation();
            _calViewDate.setMonth(idx);
            _calViewMode = 'days';
            renderCalendar();
        };

        grid.appendChild(btn);
    });
}

function renderYearsGrid(startYear) {
    const grid = document.getElementById('calYearsGrid');
    if (!grid) return;
    grid.innerHTML = '';

    const curYear = _calViewDate.getFullYear();

    for (let y = startYear; y < startYear + 12; y++) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'cal-grid-item';
        btn.innerText = y;

        if (y === curYear) {
            btn.classList.add('selected');
        }

        btn.onclick = (e) => {
            e.stopPropagation();
            _calViewDate.setFullYear(y);
            _calViewMode = 'months';
            renderCalendar();
        };

        grid.appendChild(btn);
    }
}

function onSelectRangeDay(d) {
    if (!_rangeStart || (_rangeStart && _rangeEnd)) {
        _rangeStart = d;
        _rangeEnd = null;
    } else if (_rangeStart && !_rangeEnd) {
        if (d < _rangeStart) {
            _rangeEnd = _rangeStart;
            _rangeStart = d;
        } else {
            _rangeEnd = d;
        }
    }
    renderDaysGrid();
}

function applyCustomRange() {
    if (!_rangeStart) return;
    const end = _rangeEnd || _rangeStart;
    _setVal('neoDateFrom', _formatDateYMD(_rangeStart));
    _setVal('neoDateTo', _formatDateYMD(end));
    
    const startStr = _formatShortVN(_rangeStart);
    const endStr = _formatShortVN(end);
    
    if (_formatDateYMD(_rangeStart) === _formatDateYMD(end)) {
        _setVal('neoDatePickerText', startStr, 'innerText');
    } else {
        _setVal('neoDatePickerText', `${startStr} - ${endStr}`, 'innerText');
    }
    
    document.getElementById('neoDatePicker')?.classList.remove('is-open');
    document.getElementById('filterForm')?.submit();
}

// ── DOM INITIALIZATION ───────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
    loadAddressData();

    // 1. Toggle Sidebar Filter
    const toggleBtn     = document.getElementById('btnToggleSidebar');
    const filterSidebar = document.getElementById('taskFilterSidebar');
    const toggleTxt     = document.getElementById('txtToggleSidebar');

    const isFilterHidden = localStorage.getItem('supplier_filter_hidden') === 'true';
    if (toggleTxt) toggleTxt.textContent = isFilterHidden ? 'Show Filters' : 'Hide Filters';
    if (isFilterHidden && filterSidebar) filterSidebar.classList.add('hidden');

    if (toggleBtn && filterSidebar) {
        toggleBtn.addEventListener('click', function () {
            const willHide = !filterSidebar.classList.contains('hidden');
            if (willHide) {
                filterSidebar.classList.add('hidden');
                document.documentElement.classList.add('supplier-filter-hidden');
                if (toggleTxt) toggleTxt.textContent = 'Show Filters';
            } else {
                filterSidebar.classList.remove('hidden');
                document.documentElement.classList.remove('supplier-filter-hidden');
                if (toggleTxt) toggleTxt.textContent = 'Hide Filters';
            }
            localStorage.setItem('supplier_filter_hidden', willHide);
        });
    }

    // 2. Accordion Nhóm lọc
    let collapsedSections = [];
    try {
        collapsedSections = JSON.parse(localStorage.getItem('collapsed_supplier_filters') || '[]');
    } catch (e) {}

    document.querySelectorAll('.filter-section[data-filter-key]').forEach(section => {
        const key = section.getAttribute('data-filter-key');
        if (collapsedSections.includes(key)) section.classList.add('is-collapsed');
    });

    document.querySelectorAll('.filter-sec-title').forEach(title => {
        title.addEventListener('click', function () {
            const section = this.closest('.filter-section');
            if (!section) return;
            const isCollapsed = section.classList.toggle('is-collapsed');
            const key = section.getAttribute('data-filter-key');
            if (key) {
                if (isCollapsed) { if (!collapsedSections.includes(key)) collapsedSections.push(key); }
                else { collapsedSections = collapsedSections.filter(k => k !== key); }
                localStorage.setItem('collapsed_supplier_filters', JSON.stringify(collapsedSections));
            }
        });
    });

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

    // 6. Khôi phục nhãn ngày & đóng popover
    const fromVal = document.getElementById('neoDateFrom')?.value;
    const toVal   = document.getElementById('neoDateTo')?.value;
    const textEl  = document.getElementById('neoDatePickerText');

    if (fromVal && toVal) {
        _rangeStart = new Date(fromVal);
        _rangeEnd   = new Date(toVal);
        _calViewDate = new Date(_rangeStart);
        if (fromVal === toVal) {
            const todayStr = _formatDateYMD(new Date());
            if (fromVal === todayStr && textEl) textEl.innerText = 'Hôm nay';
            else if (textEl) textEl.innerText = _formatShortVN(_rangeStart);
        } else if (textEl) {
            textEl.innerText = `${_formatShortVN(_rangeStart)} - ${_formatShortVN(_rangeEnd)}`;
        }
    }

    document.addEventListener('click', function(e) {
        if (!e.target.closest('#neoDatePicker')) {
            document.getElementById('neoDatePicker')?.classList.remove('is-open');
        }
    });

    // 7. Đồng bộ chiều cao Sidebar = Khung bảng
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
window.toggleDatePickerPopover  = toggleDatePickerPopover;
window.selectQuickDate          = selectQuickDate;
window.openCalendarPicker       = openCalendarPicker;
window.backToPresets            = backToPresets;
window.onCalTitleClick         = onCalTitleClick;
window.changeCalStep           = changeCalStep;
window.applyCustomRange         = applyCustomRange;