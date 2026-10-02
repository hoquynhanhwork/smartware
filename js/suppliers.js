//  suppliers.js

'use strict';

function _getCsrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
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
    sel.innerHTML = '<option value="">Chọn tỉnh/thành</option>';
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
    sel.innerHTML = '<option value="">Chọn phường/xã</option>';
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

// ── Chuyển đổi giữa địa chỉ VN / nước ngoài ──────────────────────────────────
function toggleAddressScope(scope) {
    const vnBlock      = document.getElementById('addressVNBlock');
    const foreignBlock = document.getElementById('addressForeignBlock');
    if (!vnBlock || !foreignBlock) return;

    if (scope === 'VN') {
        vnBlock.style.display      = 'block';
        foreignBlock.style.display = 'none';

        document.getElementById('foreignCountry').value = '';
        document.getElementById('foreignState').value   = '';
        document.getElementById('foreignCity').value    = '';
        document.getElementById('foreignPostal').value  = '';
    } else {
        vnBlock.style.display      = 'none';
        foreignBlock.style.display = 'block';

        const provSel = document.getElementById('province');
        const wardSel = document.getElementById('ward');
        if (provSel) provSel.value = '';
        if (wardSel) { wardSel.innerHTML = '<option value="">Chọn phường/xã</option>'; wardSel.disabled = true; }
    }
    updateFullAddress();
}

function updateFullAddress() {
    const scope       = document.querySelector('input[name="address_scope"]:checked')?.value || 'VN';
    const addrDisplay = document.getElementById('address_display');

    const provinceHidden = document.getElementById('provinceCode');
    const wardHidden      = document.getElementById('wardCode');
    const countryHidden   = document.getElementById('countryCode');
    const cityHidden       = document.getElementById('cityField');
    const stateHidden      = document.getElementById('stateField');
    const postalHidden     = document.getElementById('postalField');

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

        if (addrDisplay) addrDisplay.innerText = fullAddress || 'Chưa có địa chỉ';

        if (provinceHidden) provinceHidden.value = provinceCode;
        if (wardHidden)     wardHidden.value      = wardCode;
        if (countryHidden)  countryHidden.value   = 'VN';
        if (cityHidden)     cityHidden.value      = '';
        if (stateHidden)    stateHidden.value     = '';
        if (postalHidden)   postalHidden.value    = '';
    } else {
        const country = document.getElementById('foreignCountry')?.value.trim() || '';
        const state   = document.getElementById('foreignState')?.value.trim()   || '';
        const city    = document.getElementById('foreignCity')?.value.trim()    || '';
        const postal  = document.getElementById('foreignPostal')?.value.trim()  || '';

        const fullAddress = [city, state, country].filter(Boolean).join(', ');
        if (addrDisplay) addrDisplay.innerText = fullAddress || 'Chưa có địa chỉ';

        if (provinceHidden) provinceHidden.value = '';
        if (wardHidden)     wardHidden.value      = '';
        if (countryHidden)  countryHidden.value   = country || 'OTHER';
        if (cityHidden)     cityHidden.value      = city;
        if (stateHidden)    stateHidden.value     = state;
        if (postalHidden)   postalHidden.value    = postal;
    }
}

function populateFilterProvinces() {
    const sel = document.getElementById('filter_province');
    if (!sel || !addressData) return;
    sel.innerHTML = '<option value="">Tỉnh/thành</option>';
    addressData.forEach(p => {
        const opt       = document.createElement('option');
        opt.value       = p.Code;
        opt.textContent = p.Name;
        sel.appendChild(opt);
    });
    const selected = new URLSearchParams(window.location.search).get('province');
    if (selected) {
        sel.value = selected;
        sel.dispatchEvent(new Event('change', { bubbles: true }));
    }
}

function populateFilterWards(provinceCode) {
    const sel = document.getElementById('filter_ward');
    if (!sel) return;
    sel.innerHTML = '<option value="">Tất cả</option>';
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
        const selected = new URLSearchParams(window.location.search).get('ward');
        if (selected) sel.value = selected;
    } else {
        sel.disabled = true;
    }
}

function openSupplierModal(action, data = null) {
    const modal = document.getElementById('supplierModal');
    if (!modal) return;
    const formAction = document.getElementById('formAction');
    const modalTitle = document.getElementById('modalTitle');

    if (action === 'add') {
        if (formAction) formAction.value = 'add';
        if (modalTitle) modalTitle.innerText = 'Thêm đối tác';
        document.getElementById('supplierId').value     = 0;
        document.getElementById('supName').value        = '';
        document.getElementById('supPhone').value       = '';
        document.getElementById('supEmail').value       = '';
        document.getElementById('supTaxCode').value     = '';
        document.getElementById('supStatus').value      = 'active';
        document.getElementById('supEntityOrigin').value = 'domestic';

        document.getElementById('scopeVN').checked = true;
        toggleAddressScope('VN');

        const province = document.getElementById('province');
        if (province) province.value = '';
        const ward = document.getElementById('ward');
        if (ward) { ward.innerHTML = '<option value="">Chọn phường/xã</option>'; ward.disabled = true; }

        document.getElementById('foreignCountry').value = '';
        document.getElementById('foreignState').value   = '';
        document.getElementById('foreignCity').value    = '';
        document.getElementById('foreignPostal').value  = '';

        document.getElementById('supAddress').value = '';
        const disp = document.getElementById('address_display');
        if (disp) disp.innerText = '';
        document.querySelectorAll('.error-message').forEach(el => el.innerText = '');

    } else if (action === 'edit' && data) {
        if (formAction) formAction.value = 'edit';
        if (modalTitle) modalTitle.innerText = 'Sửa đối tác';
        document.getElementById('supplierId').value      = data.id;
        document.getElementById('supName').value         = data.name;
        document.getElementById('supPhone').value        = data.phone         || '';
        document.getElementById('supEmail').value        = data.email         || '';
        document.getElementById('supTaxCode').value      = data.tax_code      || '';
        document.getElementById('supStatus').value       = data.status        || 'active';
        document.getElementById('supEntityOrigin').value = data.entity_origin || 'domestic';
        document.querySelectorAll('.error-message').forEach(el => el.innerText = '');

        const isForeign = !!data.country_code && data.country_code !== 'VN';
        document.getElementById(isForeign ? 'scopeForeign' : 'scopeVN').checked = true;
        toggleAddressScope(isForeign ? 'FOREIGN' : 'VN');

        if (!isForeign && data.province_code) {
            const province = document.getElementById('province');
            if (province) {
                province.value = data.province_code;
                province.dispatchEvent(new Event('change', { bubbles: true }));
                setTimeout(() => {
                    const ward = document.getElementById('ward');
                    if (ward && data.ward_code) ward.value = data.ward_code;
                    updateFullAddress();
                }, 100);
            }
        } else if (isForeign) {
            document.getElementById('foreignCountry').value = data.country_code   || '';
            document.getElementById('foreignState').value   = data.state_province || '';
            document.getElementById('foreignCity').value    = data.city           || '';
            document.getElementById('foreignPostal').value  = data.postal_code    || '';
            updateFullAddress();
        } else {
            updateFullAddress();
        }

        document.getElementById('supAddress').value = data.address || '';
        const disp = document.getElementById('address_display');
        if (disp) disp.innerText = data.address || '';
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
        ? 'Đối tác này đang ngừng hợp tác.\nBấm OK để xóa vĩnh viễn (chỉ khả dụng nếu không còn dữ liệu liên quan).'
        : 'Bạn có chắc muốn ngừng hợp tác với đối tác này?\nMọi lịch sử nhập hàng vẫn được giữ nguyên.';

    if (!confirm(msg)) return;

    fetch('process.php', {
        method:  'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-CSRF-Token': _getCsrfToken(),
        },
        body: 'action=delete&id=' + encodeURIComponent(id),
    })
        .then(res => {
            if (!res.ok) throw new Error(`Lỗi server: ${res.status}`);
            return res.json();
        })
        .then(data => {
            if (data.ok) {
                window.location.href = 'index.php';
            } else {
                alert('Không thể thực hiện: ' + data.message);
            }
        })
        .catch(err => alert('Có lỗi xảy ra: ' + err.message));
}

function searchSupplierTable() {
    const input = document.getElementById('searchInput');
    if (!input) return;
    const filter = input.value.toLowerCase();
    const table  = document.getElementById('supplierTable');
    if (!table) return;
    const rows = table.getElementsByTagName('tr');
    for (let i = 1; i < rows.length; i++) {
        const tdName = rows[i].getElementsByTagName('td')[1]; // tên
        const tdTax  = rows[i].getElementsByTagName('td')[5]; // tax_code (đã dịch cột do thêm cột "Loại hình")
        let show     = false;
        if (tdName && tdName.textContent.toLowerCase().includes(filter)) show = true;
        if (!show && tdTax && tdTax.textContent.toLowerCase().includes(filter)) show = true;
        rows[i].style.display = show ? '' : 'none';
    }
}

function exportSupplierExcel() {
    const params = new URLSearchParams(window.location.search);
    window.location.href = '../../pages/export/Export.php?type=suppliers&' + params.toString();
}

let checkTimeout;
function checkUnique(field) {
    const input = document.getElementById(`sup${field.charAt(0).toUpperCase() + field.slice(1)}`);
    if (!input) return;
    const value     = input.value.trim();
    const id        = document.getElementById('supplierId').value;
    const errorSpan = document.getElementById(`${field}Error`);
    if (value === '') { if (errorSpan) errorSpan.innerText = ''; return; }
    clearTimeout(checkTimeout);
    checkTimeout = setTimeout(() => {
        fetch(`process.php?action=check_unique&field=${encodeURIComponent(field)}&value=${encodeURIComponent(value)}&id=${encodeURIComponent(id)}`)
            .then(res => {
                if (!res.ok) throw new Error('Server error');
                return res.json();
            })
            .then(data => {
                if (errorSpan) {
                    const labels = { name: 'Tên', phone: 'Số điện thoại', email: 'Email', tax_code: 'Mã số thuế' };
                    errorSpan.innerText = data.exists ? `${labels[field] || field} đã tồn tại!` : '';
                }
            })
            .catch(err => console.error('Lỗi kiểm tra trùng lặp:', err));
    }, 300);
}

let currentSupplierId = null;

function viewSupplierDetail(supplierId, supplierName) {
    currentSupplierId = supplierId;
    const titleEl = document.getElementById('detailModalTitle');
    if (titleEl) titleEl.innerText = supplierName ? `Chi tiết: ${supplierName}` : 'Chi tiết đối tác';
    const modal = document.getElementById('detailModal');
    if (modal) modal.style.display = 'flex';
    showTab('info');
    loadSupplierInfo();
    loadProductsBySupplier();
    loadHistoryBySupplier('all');
}

function closeDetailModal() {
    const modal = document.getElementById('detailModal');
    if (modal) modal.style.display = 'none';
    currentSupplierId = null;
}

function showTab(tabName) {
    document.querySelectorAll('.tab-content').forEach(tab => tab.style.display = 'none');
    const tabContent = document.getElementById(`tab-${tabName}`);
    if (tabContent) tabContent.style.display = 'block';
    document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
    const activeBtn = document.querySelector(`.tab-btn[data-tab="${tabName}"]`);
    if (activeBtn) activeBtn.classList.add('active');
    if (tabName === 'products') loadProductsBySupplier();
    else if (tabName === 'history') loadHistoryBySupplier('all');
}

function formatFullAddress(data) {
    if (data.country_code && data.country_code !== 'VN') {
        const parts = [data.address, data.city, data.state_province, data.country_code].filter(Boolean);
        return parts.length ? parts.join(', ') : '—';
    }
    return data.address || '—';
}

function loadSupplierInfo() {
    if (!currentSupplierId) return;
    const container = document.getElementById('supplierInfo');
    if (container) container.innerHTML = 'Đang tải...';

    fetch(`process.php?action=get_supplier_info&id=${encodeURIComponent(currentSupplierId)}`)
        .then(res => {
            if (!res.ok) throw new Error(`Lỗi server: ${res.status}`);
            return res.json();
        })
        .then(data => {
            if (!container) return;
            if (data.error) { container.innerHTML = `<div class="alert-error">${escapeHtml(data.error)}</div>`; return; }
            const titleEl = document.getElementById('detailModalTitle');
            if (titleEl && data.name) titleEl.innerText = `Chi tiết: ${data.name}`;

            const entityLabel = data.entity_origin === 'fdi' ? 'FDI (vốn nước ngoài)' : 'Trong nước';

            container.innerHTML = `
                <div class="info-card">
                    <div class="info-row"><strong>Tên:</strong> ${escapeHtml(data.name)}</div>
                    <div class="info-row"><strong>Loại hình:</strong> ${escapeHtml(entityLabel)}</div>
                    <div class="info-row"><strong>Điện thoại:</strong> ${escapeHtml(data.phone || '—')}</div>
                    <div class="info-row"><strong>Email:</strong> ${escapeHtml(data.email || '—')}</div>
                    <div class="info-row"><strong>Mã số thuế:</strong> ${escapeHtml(data.tax_code || '—')}</div>
                    <div class="info-row"><strong>Địa chỉ:</strong> ${escapeHtml(formatFullAddress(data))}</div>
                    <div class="info-row"><strong>Trạng thái:</strong>
                        <span class="status-badge ${data.status === 'active' ? 'status-active' : 'status-inactive'}">
                            ${data.status === 'active' ? 'Đang hoạt động' : 'Ngừng hoạt động'}
                        </span>
                    </div>
                    <div class="info-row"><strong>Ngày tạo:</strong> ${escapeHtml(data.created ? formatDate(data.created) : '—')}</div>
                    <div class="info-row"><strong>Tổng nhập hàng:</strong> ${formatNumberInput(data.total_import ?? 0)} ₫</div>
                </div>`;
        })
        .catch(err => {
            if (container) container.innerHTML = '<div class="alert-error">Lỗi tải thông tin</div>';
            console.error(err);
        });
}

function loadProductsBySupplier() {
    if (!currentSupplierId) return;
    const container = document.getElementById('productsList');
    if (container) container.innerHTML = 'Đang tải...';

    fetch(`process.php?action=get_products&supplier_id=${encodeURIComponent(currentSupplierId)}`)
        .then(res => {
            if (!res.ok) throw new Error(`Lỗi server: ${res.status}`);
            return res.json();
        })
        .then(data => {
            if (!container) return;
            if (data.error)   { container.innerHTML = `<div class="alert-error">${escapeHtml(data.error)}</div>`; return; }
            if (!data.length) { container.innerHTML = '<div class="text-center">Chưa có sản phẩm nào.</div>'; return; }

            let html = `<table class="data-table">
                <thead><tr>
                    <th>Tên sản phẩm</th>
                    <th>SKU</th>
                    <th>Đơn vị</th>
                    <th>Giá vốn</th>
                    <th>Tồn kho</th>
                    <th>Trạng thái</th>
                </tr></thead><tbody>`;

            data.forEach(p => {
                const statusText  = p.status === 'active' ? 'Đang bán' : 'Ngừng bán';
                const statusClass = p.status === 'active' ? 'status-active' : 'status-inactive';
                html += `<tr>
                    <td>${escapeHtml(p.name)}</td>
                    <td>${escapeHtml(p.sku || '')}</td>
                    <td>${escapeHtml(p.unit || '')}</td>
                    <td>${formatNumberInput(p.cost_price ?? 0)} ₫</td>
                    <td>${formatNumberInput(p.total_stock ?? 0)}</td>
                    <td><span class="status-badge ${statusClass}">${statusText}</span></td>
                </tr>`;
            });

            html += '</tbody></table>';
            container.innerHTML = html;
        })
        .catch(err => {
            if (container) container.innerHTML = '<div class="alert-error">Lỗi tải dữ liệu</div>';
            console.error(err);
        });
}

function loadHistoryBySupplier(range) {
    if (!currentSupplierId) return;
    const container = document.getElementById('historyList');
    if (container) container.innerHTML = 'Đang tải...';

    const validRanges = ['7d', '30d', '90d', '1y'];
    const safeRange   = validRanges.includes(range) ? range : 'all';

    fetch(`process.php?action=get_inbound_history&supplier_id=${encodeURIComponent(currentSupplierId)}&range=${safeRange}`)
        .then(res => {
            if (!res.ok) throw new Error(`Lỗi server: ${res.status}`);
            return res.json();
        })
        .then(data => {
            if (!container) return;
            if (data.error)   { container.innerHTML = `<div class="alert-error">${escapeHtml(data.error)}</div>`; return; }
            if (!data.length) { container.innerHTML = '<div class="text-center">Không có giao dịch nào.</div>'; return; }

            let html = `<table class="data-table">
                <thead><tr>
                    <th>Mã phiếu</th>
                    <th>Ngày nhập</th>
                    <th>Tổng tiền (VNĐ)</th>
                    <th>Trạng thái</th>
                </tr></thead><tbody>`;

            data.forEach(h => {
                html += `<tr>
                    <td>${escapeHtml(h.ref_no)}</td>
                    <td>${escapeHtml(formatDate(h.created))}</td>
                    <td class="price-col">${formatNumberInput(h.total_amount ?? 0)} ₫</td>
                    <td>${escapeHtml(h.status || '')}</td>
                </tr>`;
            });

            html += '</tbody></table>';
            container.innerHTML = html;
        })
        .catch(err => {
            if (container) container.innerHTML = '<div class="alert-error">Lỗi tải lịch sử</div>';
            console.error(err);
        });
}

function updateBulkBar() {
    const checked = document.querySelectorAll('.row-checkbox:checked');
    const bar     = document.getElementById('bulkActionBar');
    const count   = document.getElementById('bulkCount');
    if (!bar) return;
    bar.style.display = checked.length > 0 ? 'flex' : 'none';
    if (count) count.textContent = `${checked.length} đối tác đã chọn`;
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
    if (ids.length === 0) { alert('Chưa chọn đối tác nào.'); return; }
    const params = new URLSearchParams(window.location.search);
    ids.forEach(id => params.append('selected_ids[]', id));
    window.location.href = '../../pages/export/Export.php?type=suppliers&' + params.toString();
}

document.addEventListener('DOMContentLoaded', function () {
    if (!document.getElementById('supplierTable')) return;

    loadAddressData();

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

    const supplierForm = document.getElementById('supplierForm');
    if (supplierForm) {
        supplierForm.addEventListener('submit', function (e) {
            let hasError = false;
            const name    = document.getElementById('supName')?.value.trim() ?? '';
            const nameErr = document.getElementById('nameError');
            if (!name) { if (nameErr) nameErr.innerText = 'Tên không được để trống'; hasError = true; }
            ['nameError', 'phoneError', 'emailError', 'taxCodeError'].forEach(id => {
                const el = document.getElementById(id);
                if (el && el.innerText !== '') hasError = true;
            });
            if (hasError) { e.preventDefault(); alert('Vui lòng kiểm tra lại thông tin.'); }
        });
    }

    const provinceEl = document.getElementById('province');
    if (provinceEl) provinceEl.addEventListener('change', function () {
        populateWards(this.value);
        updateFullAddress();
    });
    const wardEl = document.getElementById('ward');
    if (wardEl) wardEl.addEventListener('change', updateFullAddress);

    ['foreignCountry', 'foreignState', 'foreignCity', 'foreignPostal'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('input', updateFullAddress);
    });

    const filterProvince = document.getElementById('filter_province');
    if (filterProvince) filterProvince.addEventListener('change', function () {
        populateFilterWards(this.value);
    });

    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.addEventListener('click', function () { showTab(this.getAttribute('data-tab')); });
    });

    document.querySelectorAll('.btn-time').forEach(btn => {
        btn.addEventListener('click', function () {
            loadHistoryBySupplier(this.getAttribute('data-range'));
        });
    });

    window.addEventListener('click', function (e) {
        if (e.target === document.getElementById('supplierModal')) closeSupplierModal();
        if (e.target === document.getElementById('detailModal'))   closeDetailModal();
    });
});

window.openSupplierModal     = openSupplierModal;
window.closeSupplierModal    = closeSupplierModal;
window.confirmDeleteSupplier = confirmDeleteSupplier;
window.searchSupplierTable   = searchSupplierTable;
window.exportSupplierExcel   = exportSupplierExcel;
window.checkUnique           = checkUnique;
window.viewSupplierDetail    = viewSupplierDetail;
window.closeDetailModal      = closeDetailModal;
window.showTab               = showTab;
window.loadAddressData       = loadAddressData;
window.populateWards         = populateWards;
window.populateFilterWards   = populateFilterWards;
window.updateFullAddress     = updateFullAddress;
window.toggleAddressScope    = toggleAddressScope;
window.updateBulkBar         = updateBulkBar;
window.clearSelection        = clearSelection;
window.exportSelectedExcel   = exportSelectedExcel;