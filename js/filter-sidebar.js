/* =======================================================
   filter-sidebar.js — ẩn/hiện + thu gọn nhóm bộ lọc (dùng chung)
   Markup yêu cầu:
     <aside id="taskFilterSidebar" class="task-filter-sidebar"> …
        <div class="filter-section" data-filter-key="…">
            <div class="filter-sec-title">…</div> …
     <button id="btnToggleSidebar"><span id="txtToggleSidebar"></span></button>
   Trạng thái ẩn/hiện: 1 khóa chung `sw_filter_hidden` (mọi trang giống nhau).
   Trạng thái thu gọn từng nhóm: lưu riêng theo trang.
======================================================= */
(function () {
    'use strict';

    var HIDE_KEY     = 'sw_filter_hidden';
    var COLLAPSE_KEY = 'sw_filter_collapsed:' + location.pathname;
    var LABEL_HIDE   = 'Ẩn bộ lọc';
    var LABEL_SHOW   = 'Hiện bộ lọc';
    var root         = document.documentElement;

    function read(key, fallback) {
        try { var v = localStorage.getItem(key); return v === null ? fallback : v; }
        catch (e) { return fallback; }
    }
    function write(key, value) {
        try { localStorage.setItem(key, value); } catch (e) { /* chế độ riêng tư: bỏ qua */ }
    }

    function init() {
        var sidebar = document.getElementById('taskFilterSidebar');
        var btn     = document.getElementById('btnToggleSidebar');
        var txt     = document.getElementById('txtToggleSidebar');

        // ── 1. Ẩn / hiện toàn bộ thanh lọc ──
        function render() {
            var hidden = root.classList.contains('filter-hidden');
            if (txt) txt.textContent = hidden ? LABEL_SHOW : LABEL_HIDE;
            if (btn) {
                btn.setAttribute('aria-expanded', hidden ? 'false' : 'true');
                btn.setAttribute('aria-controls', 'taskFilterSidebar');
            }
        }
        // Đồng bộ với script chống giật trong <head> (đã gắn class trước khi vẽ)
        root.classList.toggle('filter-hidden', read(HIDE_KEY, 'false') === 'true');
        render();

        if (btn && sidebar) {
            btn.addEventListener('click', function () {
                var willHide = !root.classList.contains('filter-hidden');
                root.classList.toggle('filter-hidden', willHide);
                write(HIDE_KEY, willHide ? 'true' : 'false');
                render();
                syncHeight();
            });
        } else if (btn && !sidebar) {
            btn.hidden = true;          // trang không có bộ lọc thì không hiện nút
        }

        // ── 1b. Chiều cao thanh lọc = từ thanh công cụ đến hết bảng ──
        var form    = sidebar ? sidebar.querySelector('form') : null;
        var toolbar = document.querySelector('.task-top-toolbar');
        var card    = document.querySelector('.task-table-card');
        var narrow  = window.matchMedia('(max-width: 900px)');

        function syncHeight() {
            if (!sidebar) return;
            sidebar.style.height = '';                       // đo chiều cao tự nhiên của nội dung
            if (narrow.matches || root.classList.contains('filter-hidden') || !toolbar || !card) return;
            var natural = sidebar.offsetHeight;
            var target  = card.getBoundingClientRect().bottom - toolbar.getBoundingClientRect().top;
            sidebar.style.height = Math.ceil(Math.max(natural, target)) + 'px';
        }
        // Vừa đủ cao theo nội dung nếu nhóm lọc dài hơn bảng; luôn >= chiều cao bảng
        if (sidebar) {
            syncHeight();
            window.addEventListener('resize', syncHeight);
            window.addEventListener('load', syncHeight);
            if (narrow.addEventListener) narrow.addEventListener('change', syncHeight);
            if (document.fonts && document.fonts.ready) document.fonts.ready.then(syncHeight);
            if (window.ResizeObserver) {
                var ro = new ResizeObserver(syncHeight);
                [toolbar, card, form].forEach(function (el) { if (el) ro.observe(el); });
            }
        }

        // ── 2. Thu gọn từng nhóm ──
        var collapsed = [];
        try { collapsed = JSON.parse(read(COLLAPSE_KEY, '[]')) || []; } catch (e) { collapsed = []; }

        document.querySelectorAll('.filter-section[data-filter-key]').forEach(function (sec) {
            var key = sec.getAttribute('data-filter-key');
            sec.classList.toggle('is-collapsed', collapsed.indexOf(key) !== -1);
            var title = sec.querySelector('.filter-sec-title');
            if (title) {
                title.setAttribute('role', 'button');
                title.setAttribute('tabindex', '0');
                title.setAttribute('aria-expanded', sec.classList.contains('is-collapsed') ? 'false' : 'true');
            }
        });

        function toggleSection(title) {
            var sec = title.closest('.filter-section');
            if (!sec) return;
            var isCollapsed = sec.classList.toggle('is-collapsed');
            title.setAttribute('aria-expanded', isCollapsed ? 'false' : 'true');
            var key = sec.getAttribute('data-filter-key');
            if (!key) return;
            collapsed = collapsed.filter(function (k) { return k !== key; });
            if (isCollapsed) collapsed.push(key);
            write(COLLAPSE_KEY, JSON.stringify(collapsed));
            syncHeight();
        }

        document.addEventListener('click', function (e) {
            var title = e.target.closest('.filter-sec-title');
            if (title) toggleSection(title);
        });
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter' && e.key !== ' ') return;
            var title = e.target.closest && e.target.closest('.filter-sec-title');
            if (title) { e.preventDefault(); toggleSection(title); }
        });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
