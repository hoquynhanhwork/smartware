/* =======================================================
   date-picker.js — bộ chọn khoảng ngày dùng chung
   (Suppliers · Inbound · Outbound · Inventory history)
   Markup: layout/date_filter.php
   - Luôn dùng NGÀY ĐỊA PHƯƠNG (không dùng toISOString → tránh lệch 1 ngày ở GMT+7)
   - Một bản cài đặt duy nhất (trước đây có 3 bản khác nhau, history không có bản nào)
======================================================= */
'use strict';

function _setVal(id, val, prop = 'value') {
    const el = document.getElementById(id);
    if (el) el[prop] = val;
}

function _parseYMD(s) {
    // 'YYYY-MM-DD' → Date ở giờ địa phương (new Date('YYYY-MM-DD') là UTC, dễ lệch ngày)
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(s || '').trim());
    return m ? new Date(+m[1], +m[2] - 1, +m[3]) : null;
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

    if (preset === 'all') {
        _setVal('neoDateFrom', '');
        _setVal('neoDateTo', '');
        _setVal('neoDatePickerText', 'Tất cả thời gian', 'innerText');
        document.getElementById('filterForm')?.submit();
        return;
    }
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

function _initDatePicker() {
    const container = document.getElementById('neoDatePicker');
    if (!container) return;

    const fromD  = _parseYMD(document.getElementById('neoDateFrom')?.value);
    const toD    = _parseYMD(document.getElementById('neoDateTo')?.value);
    const textEl = document.getElementById('neoDatePickerText');

    if (fromD && toD) {
        _rangeStart  = fromD;
        _rangeEnd    = toD;
        _calViewDate = new Date(fromD);
        const sameDay = _formatDateYMD(fromD) === _formatDateYMD(toD);
        if (textEl) {
            if (sameDay) {
                textEl.innerText = _formatDateYMD(fromD) === _formatDateYMD(new Date())
                    ? 'Hôm nay' : _formatShortVN(fromD);
            } else {
                textEl.innerText = `${_formatShortVN(fromD)} - ${_formatShortVN(toD)}`;
            }
        }
    } else if (textEl) {
        textEl.innerText = 'Tất cả thời gian';   // trước đây luôn ghi "Hôm nay" dù chưa lọc ngày
    }

    document.addEventListener('click', function (e) {
        if (!e.target.closest('#neoDatePicker')) container.classList.remove('is-open');
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') container.classList.remove('is-open');
    });
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', _initDatePicker);
else _initDatePicker();

window.toggleDatePickerPopover = toggleDatePickerPopover;
window.selectQuickDate         = selectQuickDate;
window.openCalendarPicker      = openCalendarPicker;
window.backToPresets           = backToPresets;
window.onCalTitleClick         = onCalTitleClick;
window.changeCalStep           = changeCalStep;
window.applyCustomRange        = applyCustomRange;
