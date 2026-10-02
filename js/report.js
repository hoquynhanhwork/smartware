/**
 * js/report.js
 * Shared logic cho 4 trang báo cáo: overview, inventory, products, suppliers.
 *
 * Mỗi trang PHP chỉ cần khai báo trước khi include file này:
 *   const REPORT_TYPE   = 'overview' | 'inventory' | 'products' | 'suppliers';
 *   const INITIAL_DATA   = {...}; // dữ liệu render lần đầu (server-side)
 *   const INITIAL_PARAMS = {...}; // tham số hiện tại trên URL (from/to/days/sort/page)
 *
 * Áp dụng ngày / shortcut (Tháng này, Tháng trước, Năm nay, ngày tuỳ chọn)
 * luôn load lại dữ liệu bằng AJAX (process.php) — KHÔNG reload toàn trang,
 * nên không bị "nhảy trang" và mất lựa chọn ngày của người dùng.
 */
const ReportApp = (() => {

    // ── Utils dùng chung ─────────────────────────────────────────────────
    const Utils = {
        fmt0: v => new Intl.NumberFormat('vi-VN').format(Math.round(v || 0)),
        fmtMoney: v => new Intl.NumberFormat('vi-VN').format(Math.round(v || 0)) + ' ₫',
        escStr: s => String(s ?? '').replace(/[&<>"']/g, c => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        }[c])),
        formatDate: s => {
            if (!s) return '—';
            const [y, m, d] = s.split('-');
            return `${d}/${m}/${y}`;
        },
        pctBadge: pct => {
            if (pct === null || pct === undefined) return '';
            const cls = pct >= 0 ? 'up' : 'down';
            return `<span class="pct-badge ${cls}"><i class="ri-arrow-${cls === 'up' ? 'up' : 'down'}-line"></i>${Math.abs(pct)}% so kỳ trước</span> `;
        },
        updateUrl(params) {
            const url = new URL(window.location.href);
            Object.entries(params).forEach(([k, v]) => url.searchParams.set(k, v));
            history.pushState({}, '', url);
        },
        async fetchReport(report, params) {
            const url = new URL('process.php', window.location.href);
            url.searchParams.set('report', report);
            Object.entries(params).forEach(([k, v]) => url.searchParams.set(k, v));
            const resp = await fetch(url.toString());
            const json = await resp.json();
            if (!json.success) throw new Error(json.message || 'Lỗi tải báo cáo');
            return json.data;
        },
        // Đồng bộ trạng thái active của các nút shortcut dựa trên data-* attrs
        syncShortcutActive(selector, matchFn) {
            document.querySelectorAll(selector).forEach(btn => {
                btn.classList.toggle('active', matchFn(btn));
            });
        },
        destroyChart(ref) {
            if (ref && typeof ref.destroy === 'function') ref.destroy();
            return null;
        },

        // ── Lưu / đọc khoảng ngày đang chọn, dùng chung cho cả 4 trang ──────
        STORAGE_KEY: 'smartware_report_period',
        savePeriod(from, to) {
            try { localStorage.setItem(Utils.STORAGE_KEY, JSON.stringify({ from, to })); } catch (e) {}
        },
        loadPeriod() {
            try {
                const raw = localStorage.getItem(Utils.STORAGE_KEY);
                if (!raw) return null;
                const p = JSON.parse(raw);
                return (p && p.from && p.to) ? p : null;
            } catch (e) { return null; }
        },
        // Ưu tiên: from/to trên URL hiện tại > khoảng ngày đã lưu (từ trang khác) > mặc định server render
        resolvePeriod(defaultFrom, defaultTo) {
            const params = new URLSearchParams(window.location.search);
            if (params.has('from') && params.has('to')) {
                return { from: params.get('from'), to: params.get('to') };
            }
            const stored = Utils.loadPeriod();
            if (stored) return stored;
            return { from: defaultFrom, to: defaultTo };
        },
        // Cập nhật href của các tab điều hướng để mang theo from/to đã chọn
        // (trang Tồn kho dùng bộ lọc "days" riêng nên không cần from/to)
        updateTabLinks(from, to) {
            if (!from || !to) return;
            document.querySelectorAll('.tab-link[href]').forEach(a => {
                const href = a.getAttribute('href');
                if (!href || /^https?:\/\//i.test(href) || /inventory\.php/i.test(href)) return;
                const url = new URL(href, window.location.href);
                url.searchParams.set('from', from);
                url.searchParams.set('to', to);
                a.setAttribute('href', url.pathname + url.search);
            });
        },
    };

    // ── State chung cho period picker (overview/products/suppliers) ───────
    function bindPeriodInputs(state) {
        const fromEl = document.getElementById('fromDate');
        const toEl   = document.getElementById('toDate');
        if (fromEl) fromEl.value = state.from;
        if (toEl)   toEl.value   = state.to;
    }

    function syncPeriodShortcuts(from, to) {
        Utils.syncShortcutActive('.shortcut-btn[data-from]', btn =>
            btn.dataset.from === from && btn.dataset.to === to
        );
    }

    // =========================================================================
    // OVERVIEW
    // =========================================================================
    const Overview = (() => {
        let flowChart = null;
        let state = { from: null, to: null };

        function renderFlowChart(chart) {
            flowChart = Utils.destroyChart(flowChart);
            const ctx = document.getElementById('flowChart').getContext('2d');
            flowChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: chart.labels,
                    datasets: [
                        {
                            label: 'Nhập kho (₫)',
                            data: chart.inbounds,
                            borderColor: '#3b82f6',
                            backgroundColor: 'rgba(59,130,246,0.08)',
                            fill: true, tension: 0.4, pointRadius: 4,
                        },
                        {
                            label: 'Xuất kho (₫)',
                            data: chart.outbounds,
                            borderColor: '#22c55e',
                            backgroundColor: 'rgba(34,197,94,0.08)',
                            fill: true, tension: 0.4, pointRadius: 4,
                        },
                    ]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            position: 'top',
                            labels: { usePointStyle: true, pointStyle: 'circle', boxWidth: 10, boxHeight: 10, padding: 20 }
                        }
                    },
                    scales: { y: { ticks: { callback: v => (v / 1e6).toFixed(0) + 'M ₫' } } }
                }
            });
        }

        function renderKpi(kpi) {
            const cards = document.querySelectorAll('#kpiGrid .kpi-card');
            if (cards[0]) {
                cards[0].querySelector('.kpi-value').textContent = Utils.fmtMoney(kpi.inbound_amount);
                cards[0].querySelector('.kpi-sub').innerHTML = Utils.pctBadge(kpi.inbound_pct) + kpi.inbound_orders + ' phiếu · ' + kpi.inbound_suppliers + ' NCC';
            }
            if (cards[1]) {
                cards[1].querySelector('.kpi-value').textContent = Utils.fmtMoney(kpi.outbound_amount);
                cards[1].querySelector('.kpi-sub').innerHTML = Utils.pctBadge(kpi.outbound_pct) + kpi.outbound_orders + ' phiếu';
            }
            if (cards[2]) cards[2].querySelector('.kpi-value').textContent = Utils.fmtMoney(kpi.inventory_value);
            if (cards[3]) cards[3].querySelector('.kpi-value').textContent = Utils.fmtMoney(kpi.outbound_amount - kpi.inbound_amount);
        }

        function renderTopProducts(rows) {
            const tbody = document.querySelector('#topProductsTable tbody');
            if (!rows.length) { tbody.innerHTML = '<tr><td colspan="3" class="text-center text-muted" style="padding:20px">Không có dữ liệu</td></tr>'; return; }
            tbody.innerHTML = rows.map(p => `
                <tr>
                    <td><div class="prod-name">${Utils.escStr(p.product_name)}</div><div class="prod-sku">${Utils.escStr(p.sku || '')}</div></td>
                    <td class="text-right">${Utils.fmt0(p.total_qty)}</td>
                    <td class="text-right font-medium">${Utils.fmt0(p.total_revenue)} ₫</td>
                </tr>`).join('');
        }

        function renderTopSuppliers(rows) {
            const tbody = document.querySelector('#topSuppliersTable tbody');
            if (!rows.length) { tbody.innerHTML = '<tr><td colspan="3" class="text-center text-muted" style="padding:20px">Không có dữ liệu</td></tr>'; return; }
            tbody.innerHTML = rows.map(s => `
                <tr>
                    <td>${Utils.escStr(s.supplier_name)}</td>
                    <td class="text-right">${s.order_count}</td>
                    <td class="text-right font-medium">${Utils.fmt0(s.total_amount)} ₫</td>
                </tr>`).join('');
        }

        function renderAll(data) {
            renderKpi(data.kpi);
            renderFlowChart(data.chart);
            renderTopProducts(data.top_products);
            renderTopSuppliers(data.top_suppliers);
        }

        async function load() {
            const data = await Utils.fetchReport('overview', { from: state.from, to: state.to });
            renderAll(data);
            const sub = document.querySelector('.tab-link.active .page-subtitle');
            if (sub) sub.textContent = `${Utils.formatDate(state.from)} — ${Utils.formatDate(state.to)}`;
        }

        function setPeriod(from, to) {
            state.from = from;
            state.to = to;
            bindPeriodInputs(state);
            syncPeriodShortcuts(from, to);
            Utils.updateUrl({ from, to });
            Utils.savePeriod(from, to);
            Utils.updateTabLinks(from, to);
            load().catch(e => console.error('Overview load error:', e));
        }

        function applyFromInputs() {
            const from = document.getElementById('fromDate').value;
            const to   = document.getElementById('toDate').value;
            if (!from || !to) return;
            setPeriod(from, to);
        }

        function init(initialData, initialParams) {
            const resolved = Utils.resolvePeriod(initialParams.from, initialParams.to);
            state.from = resolved.from;
            state.to   = resolved.to;
            bindPeriodInputs(state);
            syncPeriodShortcuts(state.from, state.to);
            Utils.savePeriod(state.from, state.to);
            Utils.updateTabLinks(state.from, state.to);

            if (resolved.from !== initialParams.from || resolved.to !== initialParams.to) {
                // Khoảng ngày khác với lúc server render (đến từ trang khác) → load lại qua AJAX
                load().catch(e => console.error('Overview load error:', e));
            } else {
                renderFlowChart(initialData.chart);
            }
        }

        return { init, setPeriod, applyFromInputs, load };
    })();

    // =========================================================================
    // INVENTORY
    // =========================================================================
    const Inventory = (() => {
        let distChart = null;
        let catChart  = null;
        let state = { days: 30 };

        const statusColors = ['#ef4444', '#f59e0b', '#f97316', '#3b82f6', '#22c55e'];

        function renderDistChart(distData) {
            distChart = Utils.destroyChart(distChart);
            distChart = new Chart(document.getElementById('distChart'), {
                type: 'doughnut',
                data: { labels: distData.labels, datasets: [{ data: distData.counts, backgroundColor: distData.colors || statusColors, borderWidth: 2 }] },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '65%',
                    plugins: {
                        legend: { position: 'bottom', labels: { usePointStyle: true, pointStyle: 'circle', padding: 20, boxWidth: 10, boxHeight: 10, font: { size: 13, weight: '500' } } },
                        tooltip: { backgroundColor: '#1e293b', padding: 12, callbacks: { label: ctx => `${ctx.label}: ${ctx.raw} SKU` } }
                    }
                }
            });
        }

        function renderCatChart(catData) {
            catChart = Utils.destroyChart(catChart);
            catChart = new Chart(document.getElementById('catChart'), {
                type: 'bar',
                data: { labels: catData.labels, datasets: [{ data: catData.values, backgroundColor: '#3b82f6', borderRadius: 4 }] },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    plugins: { legend: { display: false } },
                    scales: { x: { ticks: { callback: v => (v / 1e6).toFixed(0) + 'M ₫' } } }
                }
            });
        }

        function renderKpi(kpi, days) {
            const cards = document.querySelectorAll('#kpiGrid .kpi-card');
            if (cards[0]) cards[0].querySelector('.kpi-value').textContent = Utils.fmtMoney(kpi.total_value);
            if (cards[1]) {
                cards[1].querySelector('.kpi-value').textContent = Utils.fmtMoney(kpi.at_risk_value);
                cards[1].querySelector('.kpi-sub').textContent = `Trong ${days} ngày tới`;
            }
            if (cards[2]) cards[2].querySelector('.kpi-value').textContent = kpi.low_stock_cnt + ' SKU';
            if (cards[3]) {
                cards[3].querySelector('.kpi-value').textContent = kpi.near_expiry_cnt + ' lô';
                cards[3].querySelector('.kpi-sub').textContent = `Trong ${days} ngày tới`;
            }
        }

        function renderLowStock(rows) {
            const tbody = document.querySelector('#lowStockTable tbody');
            if (!rows.length) { tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted" style="padding:20px">Tồn kho ổn định</td></tr>'; return; }
            tbody.innerHTML = rows.map(r => {
                const stockColor = r.stock_status === 'OUT_OF_STOCK' ? '#ef4444' : '#f59e0b';
                let daysHtml = '<span class="text-muted">—</span>';
                if (r.estimated_days_left !== null) {
                    const c = r.estimated_days_left <= 7 ? '#ef4444' : '#f59e0b';
                    daysHtml = `<span style="color:${c};font-weight:500">${Number(r.estimated_days_left).toFixed(1)} ngày</span>`;
                }
                return `
                <tr>
                    <td><div class="prod-name">${Utils.escStr(r.product_name)}</div><div class="prod-sku">${Utils.escStr(r.sku || '')} · ${Utils.escStr(r.unit || '')}</div></td>
                    <td class="text-right" style="color:${stockColor};font-weight:600">${Utils.fmt0(r.current_stock)}</td>
                    <td class="text-right text-muted">${Utils.fmt0(r.min_stock)}</td>
                    <td class="text-right">${daysHtml}</td>
                </tr>`;
            }).join('');
        }

        function renderNearExpiry(rows) {
            const tbody = document.querySelector('#nearExpiryTable tbody');
            if (!rows.length) { tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted" style="padding:20px">Không có lô sắp hết hạn</td></tr>'; return; }
            tbody.innerHTML = rows.map(r => {
                const cls = r.days_left <= 7 ? 'expiry-critical' : 'expiry-warning';
                const exp = r.exp_date ? Utils.formatDate(r.exp_date.slice(0, 10)) : '—';
                return `
                <tr>
                    <td><div class="prod-name">${Utils.escStr(r.product_name)}</div><div class="prod-sku">${Utils.escStr(r.batch_no)}</div></td>
                    <td class="text-right">${Utils.fmt0(r.quantity)} ${Utils.escStr(r.unit || '')}</td>
                    <td class="text-right text-muted">${exp}</td>
                    <td class="text-right"><span class="${cls}">${r.days_left} ngày</span></td>
                </tr>`;
            }).join('');
        }

        function renderAll(data, days) {
            renderKpi(data.kpi, days);
            renderDistChart(data.dist_chart);
            renderCatChart(data.cat_chart);
            renderLowStock(data.low_stock);
            renderNearExpiry(data.near_expiry);
        }

        async function load() {
            const data = await Utils.fetchReport('inventory', { days: state.days });
            renderAll(data, state.days);
        }

        function setDays(d) {
            state.days = d;
            Utils.syncShortcutActive('.shortcut-btn[data-days]', btn => Number(btn.dataset.days) === d);
            Utils.updateUrl({ days: d });
            load().catch(e => console.error('Inventory load error:', e));
        }

        function init(initialData, initialParams) {
            state.days = Number(initialParams.days) || 30;
            renderDistChart(initialData.dist_chart);
            renderCatChart(initialData.cat_chart);

            // Tồn kho không dùng from/to, nhưng vẫn mang khoảng ngày đã chọn
            // sang các tab Tổng quan / Sản phẩm / Nhà cung cấp.
            const stored = Utils.loadPeriod();
            if (stored) Utils.updateTabLinks(stored.from, stored.to);
        }

        return { init, setDays, load };
    })();

    // =========================================================================
    // PRODUCTS
    // =========================================================================
    const Products = (() => {
        let catChart = null;
        let state = { from: null, to: null, sort: 'revenue', page: 1 };
        const COLORS = ['#3b82f6', '#22c55e', '#f59e0b', '#ef4444', '#8b5cf6', '#06b6d4', '#ec4899', '#84cc16', '#f97316', '#6366f1'];

        function renderCatChart(catData) {
            catChart = Utils.destroyChart(catChart);
            catChart = new Chart(document.getElementById('catChart'), {
                type: 'bar',
                data: {
                    labels: catData.labels,
                    datasets: [{
                        label: 'Doanh thu (₫)',
                        data: catData.values,
                        backgroundColor: catData.labels.map((_, i) => COLORS[i % COLORS.length]),
                        borderRadius: 6,
                    }]
                },
                options: {
                    responsive: true,
                    plugins: { legend: { display: false } },
                    scales: { y: { ticks: { callback: v => (v / 1e6).toFixed(0) + 'M ₫' } } }
                }
            });
        }

        function renderTable(items, total, page) {
            const tbody = document.getElementById('productTableBody');
            if (!items.length) {
                tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted" style="padding:20px">Không có dữ liệu trong kỳ này</td></tr>';
                return;
            }
            const offset = (page - 1) * 20;
            tbody.innerHTML = items.map((r, i) => {
                const daysLeft = r.estimated_days_left;
                const daysColor = daysLeft === null ? '#94a3b8' : (daysLeft <= 7 ? '#ef4444' : (daysLeft <= 30 ? '#f59e0b' : '#22c55e'));
                const daysText = daysLeft !== null ? Number(daysLeft).toFixed(1) + ' ngày' : '—';
                return `
                <tr>
                    <td class="text-center text-muted">${offset + i + 1}</td>
                    <td><div class="prod-name">${Utils.escStr(r.product_name)}</div><div class="prod-sku">${Utils.escStr(r.sku || '')} · ${Utils.escStr(r.unit || '')}</div></td>
                    <td class="text-muted">${Utils.escStr(r.category_name || '—')}</td>
                    <td class="text-right font-medium">${Utils.fmt0(r.total_qty)}</td>
                    <td class="text-right font-medium">${Utils.fmt0(r.total_revenue)} ₫</td>
                    <td class="text-right">${r.order_count}</td>
                    <td class="text-right">${Utils.fmt0(r.current_stock || 0)}</td>
                    <td class="text-right text-muted">${r.avg_daily_out ? Number(r.avg_daily_out).toFixed(1) : '—'}</td>
                    <td class="text-right" style="color:${daysColor};font-weight:500">${daysText}</td>
                </tr>`;
            }).join('');
        }

        function renderPagination(totalPages, page) {
            const el = document.getElementById('pagination');
            if (!el) return;
            if (totalPages <= 1) { el.innerHTML = ''; return; }
            let html = '';
            for (let p = 1; p <= totalPages; p++) {
                html += `<button class="${p === page ? 'active' : ''}" onclick="ReportApp.setPage(${p})">${p}</button>`;
            }
            el.innerHTML = html;
        }

        function renderSortTabs() {
            document.querySelectorAll('.sort-btn').forEach(btn => {
                btn.classList.toggle('active', btn.dataset.sort === state.sort);
            });
        }

        async function load() {
            const data = await Utils.fetchReport('products', { from: state.from, to: state.to, sort: state.sort, page: state.page });
            renderCatChart(data.cat_chart);
            renderTable(data.items, data.total, data.page);
            renderPagination(data.total_pages, data.page);
            const header = document.querySelector('.table-box-header h3');
            if (header) header.innerHTML = `<i class="ri-trophy-line"></i> Hiệu suất sản phẩm — ${data.total} sản phẩm có giao dịch`;
            const sub = document.querySelector('.tab-link.active .page-subtitle');
            if (sub) sub.textContent = `${Utils.formatDate(state.from)} — ${Utils.formatDate(state.to)}`;
        }

        function setPeriod(from, to) {
            state.from = from; state.to = to; state.page = 1;
            bindPeriodInputs(state);
            syncPeriodShortcuts(from, to);
            Utils.updateUrl({ from, to, sort: state.sort, page: state.page });
            Utils.savePeriod(from, to);
            Utils.updateTabLinks(from, to);
            load().catch(e => console.error('Products load error:', e));
        }

        function applyFromInputs() {
            const from = document.getElementById('fromDate').value;
            const to   = document.getElementById('toDate').value;
            if (!from || !to) return;
            setPeriod(from, to);
        }

        function setSort(sort) {
            state.sort = sort; state.page = 1;
            renderSortTabs();
            Utils.updateUrl({ sort, page: state.page });
            load().catch(e => console.error('Products load error:', e));
        }

        function setPage(page) {
            state.page = page;
            Utils.updateUrl({ page });
            load().catch(e => console.error('Products load error:', e));
        }

        function init(initialData, initialParams) {
            const resolved = Utils.resolvePeriod(initialParams.from, initialParams.to);
            state.from = resolved.from;
            state.to   = resolved.to;
            state.sort = initialParams.sort || 'revenue';
            state.page = Number(initialParams.page) || 1;
            bindPeriodInputs(state);
            syncPeriodShortcuts(state.from, state.to);
            renderSortTabs();
            Utils.savePeriod(state.from, state.to);
            Utils.updateTabLinks(state.from, state.to);

            if (resolved.from !== initialParams.from || resolved.to !== initialParams.to) {
                state.page = 1;
                load().catch(e => console.error('Products load error:', e));
            } else {
                renderCatChart(initialData.cat_chart);
            }
        }

        return { init, setPeriod, applyFromInputs, setSort, setPage };
    })();

    // =========================================================================
    // SUPPLIERS
    // =========================================================================
    const Suppliers = (() => {
        let supplierChart = null;
        let state = { from: null, to: null };

        function renderChart(chartData) {
            const canvas = document.getElementById('supplierChart');
            const wrapper = document.getElementById('supplierChartWrapper');
            supplierChart = Utils.destroyChart(supplierChart);

            if (!chartData.labels || !chartData.labels.length) {
                if (wrapper) wrapper.innerHTML = '<div style="text-align:center;padding:40px;color:#94a3b8">Không có dữ liệu trong kỳ này</div>';
                return;
            }
            if (wrapper && !document.getElementById('supplierChart')) {
                wrapper.innerHTML = '<canvas id="supplierChart" height="80"></canvas>';
            }
            const ctx = document.getElementById('supplierChart');
            if (!ctx) return;

            supplierChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: chartData.labels,
                    datasets: chartData.datasets.map(ds => ({ ...ds, borderRadius: 4, borderSkipped: false }))
                },
                options: {
                    responsive: true,
                    plugins: { legend: { position: 'top' } },
                    scales: {
                        x: { stacked: true },
                        y: { stacked: true, ticks: { callback: v => (v / 1e6).toFixed(0) + 'M ₫' } }
                    }
                }
            });
        }

        function renderTable(items) {
            const tbody = document.getElementById('supplierTableBody');
            if (!tbody) return;
            if (!items.length) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted" style="padding:20px">Không có giao dịch trong kỳ này</td></tr>';
                return;
            }
            const maxAmount = items[0].total_amount || 0;
            tbody.innerHTML = items.map((r, i) => {
                const pct = maxAmount > 0 ? Math.round((r.total_amount / maxAmount) * 100) : 0;
                const statusBg = r.status === 'active' ? '#f0fdf4' : '#fef2f2';
                const statusColor = r.status === 'active' ? '#16a34a' : '#dc2626';
                const statusText = r.status === 'active' ? 'Đang hoạt động' : 'Ngừng hoạt động';
                return `
                <tr>
                    <td class="text-center text-muted">${i + 1}</td>
                    <td>
                        <div class="prod-name">${Utils.escStr(r.supplier_name)}</div>
                        <div class="prod-sku">${Utils.escStr(r.phone || '')}</div>
                        <div style="margin-top:4px;height:3px;background:#f1f5f9;border-radius:2px;width:120px">
                            <div style="height:3px;background:#3b82f6;border-radius:2px;width:${pct}%"></div>
                        </div>
                    </td>
                    <td class="text-right">${r.order_count}</td>
                    <td class="text-right font-medium">${Utils.fmt0(r.total_amount)} ₫</td>
                    <td class="text-right text-muted">${Utils.fmt0(r.avg_order_value)} ₫</td>
                    <td class="text-right">${r.product_count}</td>
                    <td class="text-center">
                        <span style="font-size:11px;padding:2px 8px;border-radius:999px;font-weight:500;background:${statusBg};color:${statusColor}">${statusText}</span>
                    </td>
                </tr>`;
            }).join('');
        }

        async function load() {
            const data = await Utils.fetchReport('suppliers', { from: state.from, to: state.to });
            renderChart(data.chart);
            renderTable(data.items);
            const sub = document.querySelector('.tab-link.active .page-subtitle');
            if (sub) sub.textContent = `${Utils.formatDate(state.from)} — ${Utils.formatDate(state.to)}`;
        }

        function setPeriod(from, to) {
            state.from = from; state.to = to;
            bindPeriodInputs(state);
            syncPeriodShortcuts(from, to);
            Utils.updateUrl({ from, to });
            Utils.savePeriod(from, to);
            Utils.updateTabLinks(from, to);
            load().catch(e => console.error('Suppliers load error:', e));
        }

        function applyFromInputs() {
            const from = document.getElementById('fromDate').value;
            const to   = document.getElementById('toDate').value;
            if (!from || !to) return;
            setPeriod(from, to);
        }

        function init(initialData, initialParams) {
            const resolved = Utils.resolvePeriod(initialParams.from, initialParams.to);
            state.from = resolved.from;
            state.to   = resolved.to;
            bindPeriodInputs(state);
            syncPeriodShortcuts(state.from, state.to);
            Utils.savePeriod(state.from, state.to);
            Utils.updateTabLinks(state.from, state.to);

            if (resolved.from !== initialParams.from || resolved.to !== initialParams.to) {
                load().catch(e => console.error('Suppliers load error:', e));
            } else {
                renderChart(initialData.chart);
            }
        }

        return { init, setPeriod, applyFromInputs };
    })();

    // ── Dispatcher công khai ────────────────────────────────────────────
    const modules = { overview: Overview, inventory: Inventory, products: Products, suppliers: Suppliers };

    function init() {
        const mod = modules[REPORT_TYPE];
        if (!mod) { console.error('Unknown REPORT_TYPE:', REPORT_TYPE); return; }
        mod.init(INITIAL_DATA, INITIAL_PARAMS);
    }

    // Các hàm gọi từ onclick trong HTML — proxy tới module hiện tại
    function setPeriod(from, to) { modules[REPORT_TYPE].setPeriod(from, to); }
    function setDays(d)          { modules[REPORT_TYPE].setDays(d); }
    function setSort(sort)       { modules[REPORT_TYPE].setSort(sort); }
    function setPage(page)       { modules[REPORT_TYPE].setPage(page); }
    function applyFromInputs()   { modules[REPORT_TYPE].applyFromInputs(); }

    document.addEventListener('DOMContentLoaded', init);

    return { setPeriod, setDays, setSort, setPage, applyFromInputs, Utils };
})();