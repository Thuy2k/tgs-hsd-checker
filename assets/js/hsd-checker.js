/**
 * TGS HSD Checker - Main JavaScript
 *
 * Mobile-first kiểm kê HSD qua barcode scanner.
 * 4 người dùng cùng lúc, quét barcode NSX → tìm SP cha → tạo/sửa HSD children.
 */
(function () {
    'use strict';

    const CFG = window.HSD_CHECKER_CONFIG;
    let scanner = null;
    let currentProduct = null; // SP cha đang xử lý
    let currentChildren = []; // HSD children đang hiển thị
    let pendingBarcode = '';   // barcode chưa được map
    let logEntries = [];

    // ─── DOM ELEMENTS ────────────────────────────────────
    const $ = (sel) => document.querySelector(sel);
    const $$ = (sel) => document.querySelectorAll(sel);

    const dom = {
        // Stats
        statTotal: $('#statTotal'),
        statDone: $('#statDone'),
        statBarcode: $('#statBarcode'),
        statProgressBar: $('#statProgressBar'),
        // Scanner
        cameraBox: $('#cameraBox'),
        btnOpenCam: $('#btnOpenCam'),
        btnCloseCam: $('#btnCloseCam'),
        // Search
        btnSearchName: $('#btnSearchName'),
        searchBox: $('#searchBox'),
        searchInput: $('#searchInput'),
        searchResults: $('#searchResults'),
        btnCloseSearch: $('#btnCloseSearch'),
        // Product
        productArea: $('#productArea'),
        scannerArea: $('#scannerArea'),
        productName: $('#productName'),
        productSku: $('#productSku'),
        productUnit: $('#productUnit'),
        productRefQty: $('#productRefQty'),
        productThumb: $('#productThumb'),
        btnBackToScan: $('#btnBackToScan'),
        // Map notice
        mapNotice: $('#mapNotice'),
        unmappedBarcode: $('#unmappedBarcode'),
        // Summary
        totalHsdQty: $('#totalHsdQty'),
        totalAllQty: $('#totalAllQty'),
        totalHsdCount: $('#totalHsdCount'),
        // Children
        childrenList: $('#childrenList'),
        // Add form - date fields
        newHsdDay: $('#newHsdDay'),
        newHsdMonth: $('#newHsdMonth'),
        newHsdYear: $('#newHsdYear'),
        qtyModeLe: $('#qtyModeLe'),
        qtyModeThung: $('#qtyModeThung'),
        qtyLeBox: $('#qtyLeBox'),
        qtyThungBox: $('#qtyThungBox'),
        newQtyLe: $('#newQtyLe'),
        newQtyThung: $('#newQtyThung'),
        newQtyPerBox: $('#newQtyPerBox'),
        newQtyLeExtra: $('#newQtyLeExtra'),
        thungTotalCalc: $('#thungTotalCalc'),
        thungGrandTotal: $('#thungGrandTotal'),
        btnSaveHsd: $('#btnSaveHsd'),
        // Log
        logList: $('#logList'),
        btnClearLog: $('#btnClearLog'),
    };

    // ─── INIT ────────────────────────────────────────────
    function init() {
        bindEvents();
        loadStats();
        addLoadingOverlay();
        addToastContainer();
    }

    // ─── EVENT BINDING ───────────────────────────────────
    function bindEvents() {
        // Camera
        dom.btnOpenCam.addEventListener('click', openCamera);
        dom.btnCloseCam.addEventListener('click', closeCamera);

        // Search
        dom.btnSearchName.addEventListener('click', toggleSearch);
        dom.btnCloseSearch.addEventListener('click', toggleSearch);
        dom.searchInput.addEventListener('input', debounce(handleSearch, 350));

        // Back to scan
        dom.btnBackToScan.addEventListener('click', backToScan);

        // Qty mode toggle
        dom.qtyModeLe.addEventListener('change', toggleQtyMode);
        dom.qtyModeThung.addEventListener('change', toggleQtyMode);

        // Thùng calculation
        dom.newQtyThung.addEventListener('input', calcThung);
        dom.newQtyPerBox.addEventListener('input', calcThung);
        dom.newQtyLeExtra.addEventListener('input', calcThung);

        // Date field auto-advance: DD→MM→YYYY
        dom.newHsdDay.addEventListener('input', function () {
            this.value = this.value.replace(/\D/g, '');
            if (this.value.length === 2) dom.newHsdMonth.focus();
        });
        dom.newHsdMonth.addEventListener('input', function () {
            this.value = this.value.replace(/\D/g, '');
            if (this.value.length === 2) dom.newHsdYear.focus();
        });
        dom.newHsdYear.addEventListener('input', function () {
            this.value = this.value.replace(/\D/g, '');
        });

        // Save HSD
        dom.btnSaveHsd.addEventListener('click', saveHsd);

        // Clear log
        dom.btnClearLog.addEventListener('click', () => {
            logEntries = [];
            dom.logList.innerHTML = '';
        });
    }

    // ─── AJAX HELPER ─────────────────────────────────────
    function ajax(action, data = {}) {
        const fd = new FormData();
        fd.append('action', action);
        fd.append('nonce', CFG.nonce);
        for (const [k, v] of Object.entries(data)) {
            fd.append(k, v);
        }
        return fetch(CFG.ajaxUrl, { method: 'POST', body: fd })
            .then(r => r.json())
            .then(resp => {
                if (!resp.success) throw new Error(resp.data?.message || 'Lỗi không xác định');
                return resp.data;
            });
    }

    // ─── STATS ───────────────────────────────────────────
    function loadStats() {
        ajax('hsd_checker_get_stats').then(d => {
            dom.statTotal.textContent = d.total_parents;
            dom.statDone.textContent = d.parents_with_hsd;
            dom.statBarcode.textContent = d.parents_with_barcode;
            dom.statProgressBar.style.width = d.percent_hsd + '%';
        }).catch(() => { /* silent */ });
    }

    // ─── CAMERA SCANNER ──────────────────────────────────
    function openCamera() {
        if (typeof TGSBarcodeScanner === 'undefined') {
            toast('Scanner chưa sẵn sàng, vui lòng thử lại sau 2 giây', 'warning');
            return;
        }
        dom.cameraBox.style.display = 'block';
        if (scanner) { scanner.stop(); scanner = null; }

        scanner = new TGSBarcodeScanner({
            containerId: 'scanCameraPreview',
            onSuccess: handleScanResult,
            onError: (msg) => toast(msg, 'error'),
            onStatusChange: () => {},
        });
        scanner.start().catch(err => {
            toast('Không mở được camera: ' + err.message, 'error');
            closeCamera();
        });
    }

    function closeCamera() {
        if (scanner) { scanner.stop(); scanner = null; }
        dom.cameraBox.style.display = 'none';
    }

    function handleScanResult(result) {
        const barcode = (result.text || result || '').trim();
        if (!barcode) return;

        // Vibrate if supported
        if (navigator.vibrate) navigator.vibrate(100);

        toast('Đã quét: ' + barcode, 'success');
        closeCamera();
        lookupBarcode(barcode);
    }

    // ─── BARCODE LOOKUP ──────────────────────────────────
    function lookupBarcode(barcode) {
        showLoading(true);
        ajax('hsd_checker_lookup_barcode', { barcode })
            .then(data => {
                showLoading(false);
                if (data.found) {
                    // Barcode đã map → hiện SP
                    pendingBarcode = '';
                    showProduct(data.product);
                    loadChildren(data.product.local_product_sku);
                    addLog('Quét barcode: ' + barcode + ' → ' + data.product.local_product_name);
                } else {
                    // Barcode chưa map → hiện search để user chọn SP
                    pendingBarcode = barcode;
                    showMapMode(barcode);
                    addLog('Barcode chưa gắn: ' + barcode, 'warn');
                }
            })
            .catch(err => {
                showLoading(false);
                toast(err.message, 'error');
            });
    }

    // ─── SEARCH BY NAME ──────────────────────────────────
    function toggleSearch() {
        const visible = dom.searchBox.style.display !== 'none';
        dom.searchBox.style.display = visible ? 'none' : 'block';
        if (!visible) {
            dom.searchInput.value = '';
            dom.searchResults.innerHTML = '';
            dom.searchInput.focus();
        }
    }

    function handleSearch() {
        const kw = dom.searchInput.value.trim();
        if (kw.length < 2) {
            dom.searchResults.innerHTML = '';
            return;
        }

        ajax('hsd_checker_search_product', { keyword: kw })
            .then(data => {
                renderSearchResults(data.products);
            })
            .catch(err => {
                dom.searchResults.innerHTML = '<div class="p-3 text-muted">' + err.message + '</div>';
            });
    }

    function renderSearchResults(products) {
        if (!products.length) {
            dom.searchResults.innerHTML = '<div class="p-3 text-muted text-center">Không tìm thấy</div>';
            return;
        }

        dom.searchResults.innerHTML = products.map(p => `
            <div class="hsd-search-item" data-id="${p.local_product_name_id}" data-sku="${esc(p.local_product_sku)}">
                <img class="hsd-search-item-thumb" src="${p.local_product_thumbnail || ''}" 
                     onerror="this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 40 40%22><rect width=%2240%22 height=%2240%22 fill=%22%23f0f0f0%22/></svg>'" alt="">
                <div class="hsd-search-item-info">
                    <div class="hsd-search-item-name">${esc(p.local_product_name)}</div>
                    <div class="hsd-search-item-meta">
                        SKU: ${esc(p.local_product_sku || '-')} · ĐVT: ${esc(p.local_product_unit || '-')}
                    </div>
                    <div class="hsd-search-item-meta">
                        Giá: ${Number(p.local_product_price_after_tax || 0).toLocaleString('vi-VN')}đ · SL: ${parseFloat(p.local_product_quantity_no_tracking || 0)}
                        ${p.local_product_barcode_main ? ' · BC: ' + esc(p.local_product_barcode_main) : ''}
                    </div>
                </div>
            </div>
        `).join('');

        // Bind click
        dom.searchResults.querySelectorAll('.hsd-search-item').forEach((el, index) => {
            el._hsdProduct = products[index] || null;
            el.addEventListener('click', () => selectSearchProduct(el));
        });
    }

    function selectSearchProduct(el) {
        const payload = el._hsdProduct || {};
        const id = payload.local_product_name_id || el.dataset.id;
        const sku = payload.local_product_sku || el.dataset.sku;
        const productName = payload.local_product_name || el.querySelector('.hsd-search-item-name').textContent;

        // Nếu đang map barcode → hỏi xác nhận trước
        if (pendingBarcode) {
            const msg = 'Gắn barcode:\n' + pendingBarcode + '\n\nvào sản phẩm:\n' + productName + '\nSKU: ' + sku + '\n\nBạn chắc chắn chứ?';
            if (!confirm(msg)) {
                return; // Hủy → để user tìm SP khác
            }
        }

        const product = {
            local_product_name_id: id,
            local_product_name: productName,
            local_product_sku: sku,
            local_product_thumbnail: payload.local_product_thumbnail || el.querySelector('img').src,
            local_product_quantity_no_tracking: parseFloat(payload.local_product_quantity_no_tracking || 0),
            local_product_unit: payload.local_product_unit || '',
        };

        // Close search UI
        dom.searchBox.style.display = 'none';
        dom.searchInput.value = '';
        dom.searchResults.innerHTML = '';

        // Nếu đang ở map mode → map barcode
        if (pendingBarcode) {
            mapBarcode(id, pendingBarcode);
        }

        showProduct(product);
        loadChildren(sku);
    }

    // ─── MAP BARCODE ─────────────────────────────────────
    function showMapMode(barcode) {
        pendingBarcode = barcode;
        // Show map notice + search inside scanner area (not product area)
        dom.mapNotice.style.display = 'block';
        dom.unmappedBarcode.textContent = barcode;
        dom.scannerArea.style.display = 'block';
        dom.productArea.style.display = 'none';
        // Open search for user to find & select the product
        dom.searchBox.style.display = 'block';
        dom.searchInput.value = '';
        dom.searchResults.innerHTML = '';
        dom.searchInput.focus();
    }

    function mapBarcode(productId, barcode) {
        ajax('hsd_checker_map_barcode', { product_id: productId, barcode })
            .then(data => {
                pendingBarcode = '';
                dom.mapNotice.style.display = 'none';
                toast(data.message, 'success');
                addLog('Gắn barcode ' + barcode + ' → ' + data.product_name);
                loadStats();
            })
            .catch(err => {
                toast(err.message, 'error');
            });
    }

    // ─── SHOW PRODUCT ────────────────────────────────────
    function showProduct(product) {
        currentProduct = product;
        dom.scannerArea.style.display = 'none';
        dom.productArea.style.display = 'block';
        dom.mapNotice.style.display = 'none';
        dom.productArea.querySelector('.hsd-product-header').style.display = 'flex';

        dom.productName.textContent = product.local_product_name || '-';
        dom.productSku.textContent = product.local_product_sku || '-';
        dom.productUnit.textContent = product.local_product_unit || '';
        dom.productRefQty.textContent = parseFloat(product.local_product_quantity_no_tracking || 0);
        dom.productThumb.src = product.local_product_thumbnail || '';
        dom.productThumb.onerror = function () {
            this.src = 'data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 56 56%22><rect width=%2256%22 height=%2256%22 fill=%22%23f0f0f0%22/></svg>';
        };

        // Reset form
        dom.newHsdDay.value = '';
        dom.newHsdMonth.value = '';
        dom.newHsdYear.value = '';
        dom.newQtyLe.value = '';
        dom.newQtyThung.value = '';
        dom.newQtyPerBox.value = '';
        dom.newQtyLeExtra.value = '0';
        dom.qtyModeLe.checked = true;
        toggleQtyMode();
    }

    // ─── LOAD HSD CHILDREN ───────────────────────────────
    function loadChildren(parentSku) {
        ajax('hsd_checker_get_children', { parent_sku: parentSku })
            .then(data => {
                currentChildren = data.children || [];
                renderChildren(currentChildren);
                dom.totalHsdQty.textContent = data.total_hsd_qty;
                dom.totalAllQty.textContent = data.total_all_qty;
                dom.totalHsdCount.textContent = currentChildren.filter(c => c.local_product_hsd).length;
            })
            .catch(err => toast(err.message, 'error'));
    }

    function renderChildren(children) {
        if (!children.length) {
            dom.childrenList.innerHTML = '<div class="text-center text-muted py-3">Chưa có HSD nào</div>';
            return;
        }

        const today = new Date().toISOString().slice(0, 10);
        const soon = new Date(Date.now() + 30 * 86400000).toISOString().slice(0, 10); // 30 ngày

        dom.childrenList.innerHTML = children.map(c => {
            const hsd = c.local_product_hsd;
            const qty = parseFloat(c.local_product_quantity_no_tracking || 0);
            const isNoHsd = !hsd;
            const isExpired = hsd && hsd < today;
            const isNearExpiry = hsd && !isExpired && hsd <= soon;

            let cardClass = 'hsd-child-card';
            if (isNoHsd) cardClass += ' is-no-hsd';
            else if (isExpired) cardClass += ' is-hsd is-expired';
            else if (isNearExpiry) cardClass += ' is-hsd is-near-expiry';
            else cardClass += ' is-hsd';

            const hsdDisplay = isNoHsd
                ? '<span class="text-muted">Không có HSD</span>'
                : formatDate(hsd);

            const expiryBadge = isExpired
                ? ' <span class="badge bg-danger">Hết hạn</span>'
                : (isNearExpiry ? ' <span class="badge bg-warning">Sắp hết hạn</span>' : '');

            return `
                <div class="${cardClass}" data-child-id="${c.local_product_name_id}">
                    <div class="hsd-child-left">
                        <div class="hsd-child-hsd">${hsdDisplay}${expiryBadge}</div>
                        <div class="hsd-child-sku">${esc(c.local_product_sku)}</div>
                    </div>
                    <div class="hsd-child-right">
                        <div class="hsd-child-qty-display">${qty}</div>
                        <div class="hsd-child-edit-group" data-id="${c.local_product_name_id}">
                            <input type="number" class="form-control form-control-sm hsd-edit-qty" 
                                   value="${qty}" min="0" step="1" inputmode="numeric">
                            <button class="btn btn-sm btn-success hsd-child-btn-edit hsd-btn-save-qty"
                                    title="Lưu"><i class="bx bx-check"></i></button>
                            <button class="btn btn-sm btn-outline-secondary hsd-child-btn-edit hsd-btn-cancel-qty"
                                    title="Hủy"><i class="bx bx-x"></i></button>
                        </div>
                        <button class="btn btn-sm btn-outline-primary hsd-child-btn-edit hsd-btn-edit-qty"
                                title="Sửa SL"><i class="bx bx-edit-alt"></i></button>
                    </div>
                </div>`;
        }).join('');

        // Bind edit events
        dom.childrenList.querySelectorAll('.hsd-btn-edit-qty').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const card = e.target.closest('.hsd-child-card');
                card.querySelector('.hsd-child-qty-display').style.display = 'none';
                card.querySelector('.hsd-btn-edit-qty').style.display = 'none';
                card.querySelector('.hsd-child-edit-group').classList.add('active');
                card.querySelector('.hsd-edit-qty').focus();
                card.querySelector('.hsd-edit-qty').select();
            });
        });

        dom.childrenList.querySelectorAll('.hsd-btn-cancel-qty').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const card = e.target.closest('.hsd-child-card');
                card.querySelector('.hsd-child-qty-display').style.display = '';
                card.querySelector('.hsd-btn-edit-qty').style.display = '';
                card.querySelector('.hsd-child-edit-group').classList.remove('active');
            });
        });

        dom.childrenList.querySelectorAll('.hsd-btn-save-qty').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const card = e.target.closest('.hsd-child-card');
                const childId = card.dataset.childId;
                const newQty = parseFloat(card.querySelector('.hsd-edit-qty').value || 0);
                updateChildQty(childId, newQty);
            });
        });
    }

    // ─── QTY MODE TOGGLE ─────────────────────────────────
    function toggleQtyMode() {
        const isThung = dom.qtyModeThung.checked;
        dom.qtyLeBox.style.display = isThung ? 'none' : 'block';
        dom.qtyThungBox.style.display = isThung ? 'block' : 'none';
    }

    function calcThung() {
        const thung = parseInt(dom.newQtyThung.value) || 0;
        const perBox = parseInt(dom.newQtyPerBox.value) || 0;
        const extra = parseInt(dom.newQtyLeExtra.value) || 0;
        const thungTotal = thung * perBox;
        dom.thungTotalCalc.textContent = thungTotal;
        dom.thungGrandTotal.textContent = thungTotal + extra;
    }

    function getNewQuantity() {
        if (dom.qtyModeThung.checked) {
            const thung = parseInt(dom.newQtyThung.value) || 0;
            const perBox = parseInt(dom.newQtyPerBox.value) || 0;
            const extra = parseInt(dom.newQtyLeExtra.value) || 0;
            return thung * perBox + extra;
        } else {
            return parseFloat(dom.newQtyLe.value) || 0;
        }
    }

    // ─── SAVE HSD ────────────────────────────────────────
    function getHsdValue() {
        const dd = (dom.newHsdDay.value || '').trim();
        const mm = (dom.newHsdMonth.value || '').trim();
        const yyyy = (dom.newHsdYear.value || '').trim();
        // All empty = no HSD
        if (!dd && !mm && !yyyy) return '';
        // Validate
        const day = parseInt(dd, 10);
        const month = parseInt(mm, 10);
        const year = parseInt(yyyy, 10);
        if (!day || !month || !year || day < 1 || day > 31 || month < 1 || month > 12 || year < 2020 || year > 2099) {
            return null; // invalid
        }
        // Return YYYY-MM-DD
        return yyyy.padStart(4, '0') + '-' + String(month).padStart(2, '0') + '-' + String(day).padStart(2, '0');
    }

    function saveHsd() {
        if (!currentProduct) {
            toast('Chưa chọn sản phẩm!', 'error');
            return;
        }

        const hsd = getHsdValue();
        if (hsd === null) {
            toast('Ngày không hợp lệ! DD/MM/YYYY', 'error');
            return;
        }
        const quantity = getNewQuantity();

        if (quantity <= 0) {
            toast('Số lượng phải > 0', 'warning');
            return;
        }

        // Cảnh báo nếu bất hợp lý (vẫn cho lưu)
        if (quantity > 500) {
            if (!confirm('Số lượng rất lớn: ' + quantity + '. Bạn chắc chắn?')) return;
        }

        showLoading(true);
        ajax('hsd_checker_create_child', {
            parent_product_id: currentProduct.local_product_name_id,
            hsd: hsd,
            quantity: quantity,
        })
            .then(data => {
                showLoading(false);

                if (data.already_exists) {
                    // HSD đã tồn tại → hỏi cộng thêm
                    const existChild = data.child;
                    const oldQty = parseFloat(existChild.local_product_quantity_no_tracking || 0);
                    const msg = `HSD này đã có (SL hiện tại: ${oldQty}).\nCộng thêm ${quantity}? (Tổng: ${oldQty + quantity})`;
                    if (confirm(msg)) {
                        updateChildQty(existChild.local_product_name_id, quantity, 'add');
                    }
                    return;
                }

                toast(data.message, 'success');
                addLog(`Tạo HSD${hsd ? ' ' + formatDate(hsd) : ' (no-hsd)'} SL=${quantity} → ${currentProduct.local_product_name}`);

                // Reload children
                loadChildren(currentProduct.local_product_sku);
                loadStats();

                // Reset form
                dom.newHsdDay.value = '';
                dom.newHsdMonth.value = '';
                dom.newHsdYear.value = '';
                dom.newQtyLe.value = '';
                dom.newQtyThung.value = '';
                dom.newQtyLeExtra.value = '0';
                calcThung();
            })
            .catch(err => {
                showLoading(false);
                toast(err.message, 'error');
                addLog(err.message, 'error');
            });
    }

    // ─── UPDATE CHILD QTY ────────────────────────────────
    function updateChildQty(childId, quantity, mode = 'set') {
        showLoading(true);
        ajax('hsd_checker_update_quantity', {
            child_product_id: childId,
            quantity: quantity,
            mode: mode,
        })
            .then(data => {
                showLoading(false);

                if (data.warning) {
                    toast(data.warning, 'warning');
                    addLog(data.warning, 'warn');
                } else {
                    toast(data.message, 'success');
                }

                const modeText = mode === 'add' ? '+' + quantity : '=' + data.new_qty;
                addLog(`SL ${modeText} (cũ: ${data.old_qty}) → child #${childId}`);

                // Reload
                loadChildren(currentProduct.local_product_sku);
            })
            .catch(err => {
                showLoading(false);
                toast(err.message, 'error');
            });
    }

    // ─── BACK TO SCAN ────────────────────────────────────
    function backToScan() {
        currentProduct = null;
        currentChildren = [];
        pendingBarcode = '';
        dom.productArea.style.display = 'none';
        dom.scannerArea.style.display = 'block';
        dom.mapNotice.style.display = 'none';
        dom.childrenList.innerHTML = '';
        // Auto open camera for quick workflow
        openCamera();
    }

    // ─── LOG ─────────────────────────────────────────────
    function addLog(text, type = 'ok') {
        const time = new Date().toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        logEntries.unshift({ text, type, time });
        if (logEntries.length > 20) logEntries.length = 20;
        renderLog();
    }

    function renderLog() {
        dom.logList.innerHTML = logEntries.map(l =>
            `<div class="hsd-log-item ${l.type === 'warn' ? 'warn' : (l.type === 'error' ? 'error' : '')}">
                <span class="hsd-log-time">${l.time}</span> ${esc(l.text)}
            </div>`
        ).join('');
    }

    // ─── UI HELPERS ──────────────────────────────────────
    function addLoadingOverlay() {
        const div = document.createElement('div');
        div.id = 'hsdLoading';
        div.className = 'hsd-loading';
        div.innerHTML = '<div class="hsd-loading-spinner"></div>';
        document.body.appendChild(div);
    }

    function showLoading(show) {
        const el = document.getElementById('hsdLoading');
        if (el) el.classList.toggle('active', show);
    }

    function addToastContainer() {
        const div = document.createElement('div');
        div.id = 'hsdToast';
        div.className = 'hsd-toast';
        document.body.appendChild(div);
    }

    function toast(msg, type = 'success') {
        const el = document.getElementById('hsdToast');
        if (!el) return;
        el.textContent = msg;
        el.className = 'hsd-toast ' + type + ' show';
        clearTimeout(el._timer);
        el._timer = setTimeout(() => el.classList.remove('show'), 2500);
    }

    function formatDate(dateStr) {
        if (!dateStr) return '';
        const parts = dateStr.split('-');
        if (parts.length === 3) return parts[2] + '/' + parts[1] + '/' + parts[0];
        return dateStr;
    }

    function esc(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function debounce(fn, ms) {
        let t;
        return function (...args) {
            clearTimeout(t);
            t = setTimeout(() => fn.apply(this, args), ms);
        };
    }

    // ─── GO ──────────────────────────────────────────────
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
