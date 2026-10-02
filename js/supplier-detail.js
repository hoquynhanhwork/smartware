// js/supplier-detail.js
'use strict';

let _cdpSelectedYear = 2026;
let _cdpSelectedMonth = 10;
let _cdpViewMode = 'months'; // 'months' | 'years'
let _currentPeriod = 'month';

const _shortMonths = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

// ── 1. MODAL CHỈNH SỬA ĐỐI TÁC TRÊN TRANG DETAIL ───────────────────────────
function openSupplierModal(action, data = null) {
    const modal = document.getElementById('supplierModal');
    if (!modal) return;
    const toggleStatus = document.getElementById('supStatusToggle');

    document.querySelectorAll('#supplierModal .error-message').forEach(el => el.innerText = '');

    if (action === 'edit' && data) {
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
        const scopeVN = document.getElementById('scopeVN');
        const scopeForeign = document.getElementById('scopeForeign');
        if (isForeign && scopeForeign) scopeForeign.checked = true;
        else if (scopeVN) scopeVN.checked = true;

        if (typeof toggleAddressScope === 'function') {
            toggleAddressScope(isForeign ? 'FOREIGN' : 'VN');
        }

        if (!isForeign && data.province_code) {
            const province = document.getElementById('province');
            if (province) {
                province.value = data.province_code;
                if (typeof populateWards === 'function') populateWards(data.province_code);
                setTimeout(() => {
                    const ward = document.getElementById('ward');
                    if (ward && data.ward_code) ward.value = data.ward_code;
                    if (typeof updateFullAddress === 'function') updateFullAddress();
                }, 100);
            }
        } else if (isForeign) {
            _setVal('foreignCountry', data.country_code || '');
            _setVal('foreignState', data.state_province || '');
            _setVal('foreignCity', data.city || '');
            _setVal('foreignPostal', data.postal_code || '');
            if (typeof updateFullAddress === 'function') updateFullAddress();
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

// ── 2. POPOVER CHỌN THÁNG / NĂM LƯỚI 3x4 ──────────────────────────────────
function toggleChartDatePopover(e) {
    if (e) {
        e.preventDefault();
        e.stopPropagation();
    }
    const pop = document.getElementById('chartDatePopover');
    if (!pop) return;
    const isShow = pop.classList.toggle('show');
    if (isShow) {
        // Mở popover: nếu đang ở tab Năm thì mở thẳng lưới Năm, còn lại mở lưới Tháng
        _cdpViewMode = (_currentPeriod === 'year') ? 'years' : 'months';
        renderCdpView();
    }
}

function toggleCdpView(e) {
    if (e) {
        e.preventDefault();
        e.stopPropagation();
    }
    _cdpViewMode = (_cdpViewMode === 'months') ? 'years' : 'months';
    renderCdpView();
}

function changeCdpStep(step, e) {
    if (e) {
        e.preventDefault();
        e.stopPropagation();
    }
    if (_cdpViewMode === 'months') {
        _cdpSelectedYear += step;
    } else {
        _cdpSelectedYear += (step * 12);
    }
    renderCdpView();
}

function renderCdpView() {
    const monthsGrid = document.getElementById('cdpMonthsGrid');
    const yearsGrid  = document.getElementById('cdpYearsGrid');
    const titleBtn   = document.getElementById('cdpTitleBtn');
    if (!monthsGrid || !yearsGrid || !titleBtn) return;

    if (_cdpViewMode === 'months') {
        monthsGrid.style.display = 'grid';
        yearsGrid.style.display = 'none';
        titleBtn.innerText = _cdpSelectedYear;

        monthsGrid.innerHTML = '';
        _shortMonths.forEach((mName, idx) => {
            const mNum = idx + 1;
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'cdp-item' + (mNum === _cdpSelectedMonth ? ' active' : '');
            btn.innerText = mName;
            btn.onclick = (e) => {
                e.stopPropagation();
                selectCdpMonth(mNum);
            };
            monthsGrid.appendChild(btn);
        });
    } else {
        monthsGrid.style.display = 'none';
        yearsGrid.style.display = 'grid';

        const startY = _cdpSelectedYear - (_cdpSelectedYear % 12);
        const endY = startY + 11;
        titleBtn.innerText = `${startY}-${endY}`;

        yearsGrid.innerHTML = '';
        for (let y = startY; y <= endY; y++) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'cdp-item' + (y === _cdpSelectedYear ? ' active' : '');
            btn.innerText = y;
            btn.onclick = (e) => {
                e.stopPropagation();
                selectCdpYear(y);
            };
            yearsGrid.appendChild(btn);
        }
    }
}

// Bấm chọn Năm
function selectCdpYear(y) {
    _cdpSelectedYear = y;
    // Nếu đang ở chu kỳ xem Năm hoặc Quý -> đóng luôn popover và load biểu đồ
    if (_currentPeriod === 'year' || _currentPeriod === 'quarter') {
        document.getElementById('chartDatePopover')?.classList.remove('show');
        updateChartByDateSelection();
    } else {
        // Nếu đang ở Tháng / Tuần -> quay về lưới Tháng để người dùng chọn tháng trong năm đó
        _cdpViewMode = 'months';
        renderCdpView();
    }
}

// Bấm chọn Tháng
function selectCdpMonth(m) {
    _cdpSelectedMonth = m;
    document.getElementById('chartDatePopover')?.classList.remove('show');
    updateChartByDateSelection();
}

function updateChartByDateSelection() {
    const lbl = document.getElementById('chartSubTitle');
    if (lbl) {
        if (_currentPeriod === 'month') {
            lbl.innerText = `Năm ${_cdpSelectedYear}`;
        } else if (_currentPeriod === 'week') {
            lbl.innerText = `Tháng ${_cdpSelectedMonth} / ${_cdpSelectedYear}`;
        } else if (_currentPeriod === 'quarter') {
            lbl.innerText = `4 quý năm ${_cdpSelectedYear}`;
        } else {
            lbl.innerText = `Năm ${_cdpSelectedYear}`;
        }
    }
    fetchChartDataAsync();
}

function fetchChartDataAsync() {
    const supplierId = window._currentSupplierDetailId || 0;
    fetch(`process.php?action=get_inbound_chart&supplier_id=${supplierId}&period=${_currentPeriod}&year=${_cdpSelectedYear}&month=${_cdpSelectedMonth}`)
        .then(res => res.json())
        .then(res => {
            if (res.labels && res.values) {
                renderBarChart(res.labels, res.values);
            }
        })
        .catch(() => {
            const fallback = window._chartData ? window._chartData[_currentPeriod] : null;
            if (fallback) renderBarChart(fallback.labels, fallback.values);
        });
}

function switchChartPeriod(period, btn) {
    document.querySelectorAll('.chart-filter-pills .btn-chart-tab').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    _currentPeriod = period;
    updateChartByDateSelection();
}

// ── 3. RENDER BIỂU ĐỒ ROUNDED PILLS BAR ────────────────────────────────────
function renderBarChart(labels, values) {
    const barsContainer = document.getElementById('chartBarsContainer');
    const yAxisContainer = document.getElementById('chartYAxis');
    if (!barsContainer || !yAxisContainer) return;

    barsContainer.innerHTML = '';
    yAxisContainer.innerHTML = '';

    const maxVal = Math.max(...values, 1000);
    const step = Math.ceil(maxVal / 4 / 1000) * 1000;
    const chartMax = step * 4;

    for (let i = 4; i >= 0; i--) {
        const span = document.createElement('span');
        const num = step * i;
        span.innerText = num >= 1000000 ? (num / 1000000) + 'M' : (num >= 1000 ? (num / 1000) + 'k' : num);
        yAxisContainer.appendChild(span);
    }

    labels.forEach((lbl, idx) => {
        const val = values[idx] || 0;
        const percent = Math.min(100, Math.round((val / chartMax) * 100));

        const colWrap = document.createElement('div');
        colWrap.className = 'chart-col-unit';
        colWrap.innerHTML = `
            <div class="chart-track-pill" title="${lbl}: ${new Intl.NumberFormat('vi-VN').format(val)} ₫">
                <div class="chart-fill-pill" style="height: ${percent}%;"></div>
            </div>
            <span class="chart-x-lbl">${lbl}</span>
        `;
        barsContainer.appendChild(colWrap);
    });
}

// ── 4. KHỞI TẠO EVENT LISTENER KHI TẢI TRANG ──────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
    if (typeof loadAddressData === 'function') {
        loadAddressData();
    }

    // Khởi tạo biểu đồ mặc định
    updateChartByDateSelection();

    // Đóng Popover khi click ra ngoài (đảm bảo không chặn sự kiện click bên trong popover)
    document.addEventListener('click', function(e) {
        const pop = document.getElementById('chartDatePopover');
        const trigger = document.getElementById('chartPickerWrapper');
        if (pop && trigger && !trigger.contains(e.target)) {
            pop.classList.remove('show');
        }
        if (e.target === document.getElementById('supplierModal')) {
            closeSupplierModal();
        }
    });
});

// Đưa hàm ra phạm vi Window
window.openSupplierModal      = openSupplierModal;
window.closeSupplierModal     = closeSupplierModal;
window.toggleChartDatePopover = toggleChartDatePopover;
window.toggleCdpView          = toggleCdpView;
window.changeCdpStep          = changeCdpStep;
window.switchChartPeriod      = switchChartPeriod;
window.selectCdpYear          = selectCdpYear;
window.selectCdpMonth         = selectCdpMonth;