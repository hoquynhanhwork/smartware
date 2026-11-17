<?php
// layout/date_filter.php — nhóm lọc theo ngày dùng chung (Suppliers · Inbound · Outbound · History)
// Biến đầu vào:
//   $dateFilterFrom  (string 'Y-m-d' | '')   $dateFilterTo  (string 'Y-m-d' | '')
//   $dateFilterTitle (tuỳ chọn, mặc định 'Ngày tạo')
// JS đi kèm: js/date-picker.js
$dateFilterFrom  = (string) ($dateFilterFrom  ?? '');
$dateFilterTo    = (string) ($dateFilterTo    ?? '');
$dateFilterTitle = (string) ($dateFilterTitle ?? 'Ngày tạo');
?>
<div class="filter-section" data-filter-key="date">
    <div class="filter-sec-title">
        <span><i class="ri-calendar-line"></i> <?= htmlspecialchars($dateFilterTitle) ?></span>
        <i class="ri-arrow-down-s-line"></i>
    </div>

    <div class="neo-datepicker-container" id="neoDatePicker">
        <input type="hidden" name="from" id="neoDateFrom" value="<?= htmlspecialchars($dateFilterFrom) ?>">
        <input type="hidden" name="to"   id="neoDateTo"   value="<?= htmlspecialchars($dateFilterTo) ?>">

        <button type="button" class="neo-datepicker-trigger" onclick="toggleDatePickerPopover(event)">
            <span class="trigger-left">
                <i class="ri-calendar-line"></i>
                <span id="neoDatePickerText">Tất cả thời gian</span>
            </span>
            <i class="ri-arrow-down-s-line arrow-icon"></i>
        </button>

        <div class="neo-datepicker-popover" id="neoDatePickerPopover" onclick="event.stopPropagation()">
            <div class="datepicker-preset-list" id="datepickerPresetList">
                <button type="button" class="preset-pill-item" onclick="selectQuickDate('all')">Tất cả thời gian</button>
                <button type="button" class="preset-pill-item" onclick="selectQuickDate('today')">Hôm nay</button>
                <button type="button" class="preset-pill-item" onclick="selectQuickDate('yesterday')">Hôm qua</button>
                <button type="button" class="preset-pill-item" onclick="selectQuickDate('last7')">7 ngày gần đây</button>
                <button type="button" class="preset-pill-item" onclick="selectQuickDate('last14')">14 ngày gần đây</button>
                <button type="button" class="preset-pill-item" onclick="selectQuickDate('last30')">30 ngày gần đây</button>
                <button type="button" class="preset-pill-item custom-btn" onclick="openCalendarPicker()">
                    <span>Tùy chỉnh (Start - End)</span>
                    <i class="ri-arrow-right-s-line"></i>
                </button>
            </div>

            <div class="datepicker-calendar-panel" id="datepickerCalendarPanel" style="display: none;">
                <div class="cal-header-bar">
                    <button type="button" class="btn-cal-nav" onclick="changeCalStep(-1)" aria-label="Trước">
                        <i class="ri-arrow-left-s-line"></i>
                    </button>
                    <button type="button" class="cal-title-btn" id="calMainTitleBtn" onclick="onCalTitleClick()"></button>
                    <button type="button" class="btn-cal-nav" onclick="changeCalStep(1)" aria-label="Sau">
                        <i class="ri-arrow-right-s-line"></i>
                    </button>
                </div>

                <div class="cal-view-section" id="calDaysView">
                    <div class="cal-week-labels">
                        <span>CN</span><span>T2</span><span>T3</span><span>T4</span><span>T5</span><span>T6</span><span>T7</span>
                    </div>
                    <div class="cal-days-grid" id="calDaysGrid"></div>
                    <div class="cal-footer-range">
                        <span class="range-hint" id="calRangeHint">Chọn ngày bắt đầu</span>
                        <div class="cal-btns">
                            <button type="button" class="btn-cal-back" onclick="backToPresets()">Quay lại</button>
                            <button type="button" class="btn-cal-apply" onclick="applyCustomRange()">Áp dụng</button>
                        </div>
                    </div>
                </div>

                <div class="cal-view-section" id="calMonthsView" style="display: none;">
                    <div class="cal-grid-3x4" id="calMonthsGrid"></div>
                </div>
                <div class="cal-view-section" id="calYearsView" style="display: none;">
                    <div class="cal-grid-3x4" id="calYearsGrid"></div>
                </div>
            </div>
        </div>
    </div>
</div>
