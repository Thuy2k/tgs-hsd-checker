<?php
/**
 * Trang Kiểm kê HSD - Mobile-first responsive UI
 * 
 * Workflow: Quét barcode (hoặc tìm tên) → Thấy SP cha → Tạo/sửa HSD children → Quét tiếp
 */
if (!defined('ABSPATH')) exit;

$nonce = wp_create_nonce(TGS_HSD_Checker_Ajax::NONCE_ACTION);
$product_nonce = wp_create_nonce('tgs_shop_product_nonce');
$scanner_js_url = TGS_SHOP_PLUGIN_URL . 'assets/js/common/tgs-barcode-scanner.js';
$checker_js_url = TGS_HSD_CHECKER_URL . 'assets/js/hsd-checker.js';
$checker_css_url = TGS_HSD_CHECKER_URL . 'assets/css/hsd-checker.css';
?>

<!-- ZXing Library (for barcode scanner) -->
<script src="https://unpkg.com/@zxing/library@0.21.3"></script>
<!-- Scanner class from tgs_shop_management -->
<script src="<?php echo esc_url($scanner_js_url); ?>"></script>

<link rel="stylesheet" href="<?php echo esc_url($checker_css_url); ?>">

<div id="hsdCheckerApp" class="hsd-checker">
    <!-- ══════ HEADER STATS BAR ══════ -->
    <div class="hsd-stats-bar" id="statsBar">
        <div class="hsd-stat">
            <span class="hsd-stat-value" id="statTotal">-</span>
            <span class="hsd-stat-label">Tổng SP</span>
        </div>
        <div class="hsd-stat">
            <span class="hsd-stat-value text-success" id="statDone">-</span>
            <span class="hsd-stat-label">Đã kiểm</span>
        </div>
        <div class="hsd-stat">
            <span class="hsd-stat-value text-warning" id="statBarcode">-</span>
            <span class="hsd-stat-label">Có barcode</span>
        </div>
        <div class="hsd-stat-progress">
            <div class="progress" style="height:6px">
                <div class="progress-bar bg-success" id="statProgressBar" style="width:0%"></div>
            </div>
        </div>
    </div>

    <!-- ══════ MAIN SCANNER AREA ══════ -->
    <div class="hsd-scanner-area" id="scannerArea">
        <!-- Barcode mapping notice (if scanned but not mapped) -->
        <div class="hsd-map-notice alert alert-warning" id="mapNotice" style="display:none;">
            <strong>Barcode chưa được gắn!</strong>
            <p class="mb-1">Mã: <code id="unmappedBarcode"></code></p>
            <p class="mb-0">Tìm tên sản phẩm bên dưới để gắn barcode này.</p>
        </div>

        <!-- Camera preview -->
        <div class="hsd-camera-box" id="cameraBox" style="display:none;">
            <div id="scanCameraPreview" class="hsd-camera-preview"></div>
            <button type="button" class="btn btn-outline-light btn-sm hsd-btn-close-cam" id="btnCloseCam">
                <i class="bx bx-x"></i> Đóng camera
            </button>
        </div>

        <!-- Action buttons -->
        <div class="hsd-scan-actions">
            <button type="button" class="btn btn-primary btn-lg hsd-btn-scan" id="btnOpenCam">
                <i class="bx bx-scan"></i> Quét barcode
            </button>
            <button type="button" class="btn btn-outline-secondary btn-lg hsd-btn-search" id="btnSearchName">
                <i class="bx bx-search"></i> Tìm tên SP
            </button>
        </div>

        <!-- Search by name (hidden by default) -->
        <div class="hsd-search-box" id="searchBox" style="display:none;">
            <div class="input-group">
                <input type="text" class="form-control form-control-lg" id="searchInput"
                       placeholder="Gõ tên sản phẩm..." autocomplete="off">
                <button type="button" class="btn btn-outline-secondary" id="btnCloseSearch">
                    <i class="bx bx-x"></i>
                </button>
            </div>
            <div class="hsd-search-results" id="searchResults"></div>
        </div>
    </div>

    <!-- ══════ PRODUCT DETAIL AREA (shown after scan/search) ══════ -->
    <div class="hsd-product-area" id="productArea" style="display:none;">
        <!-- Product header -->
        <div class="hsd-product-header">
            <div class="hsd-product-info">
                <img id="productThumb" class="hsd-product-thumb" src="" alt="">
                <div>
                    <h5 class="hsd-product-name" id="productName">-</h5>
                    <div class="hsd-product-meta">
                        <span class="badge bg-secondary" id="productSku">-</span>
                        <span class="text-muted" id="productUnit"></span>
                    </div>
                    <div class="hsd-product-ref-qty">
                        SL tham khảo (tổng): <strong id="productRefQty">0</strong>
                    </div>
                </div>
            </div>
            <button type="button" class="btn btn-outline-primary btn-sm" id="btnBackToScan">
                <i class="bx bx-scan"></i> Quét tiếp
            </button>
        </div>

        <!-- HSD Summary -->
        <div class="hsd-summary-row" id="hsdSummary">
            <div class="hsd-summary-item">
                <span>Tổng SL có HSD</span>
                <strong id="totalHsdQty">0</strong>
            </div>
            <div class="hsd-summary-item">
                <span>Tổng SL tất cả</span>
                <strong id="totalAllQty">0</strong>
            </div>
            <div class="hsd-summary-item">
                <span>Số HSD</span>
                <strong id="totalHsdCount">0</strong>
            </div>
        </div>

        <!-- Existing HSD children list -->
        <div class="hsd-children-list" id="childrenList">
            <!-- JS renders here -->
        </div>

        <!-- ── ADD NEW HSD FORM ── -->
        <div class="hsd-add-form card" id="addHsdForm">
            <div class="card-header d-flex justify-content-between align-items-center"
                 data-bs-toggle="collapse" data-bs-target="#addHsdBody" role="button">
                <h6 class="mb-0"><i class="bx bx-plus-circle me-1"></i>Thêm HSD mới</h6>
                <i class="bx bx-chevron-down"></i>
            </div>
            <div class="collapse show" id="addHsdBody">
                <div class="card-body">
                    <!-- HSD Date - 3 ô DD/MM/YYYY -->
                    <div class="mb-3">
                        <label class="form-label">Hạn sử dụng <small class="text-muted">(để trống = không có HSD)</small></label>
                        <div class="hsd-date-inputs">
                            <input type="text" class="form-control form-control-lg hsd-date-field" id="newHsdDay"
                                   placeholder="DD" maxlength="2" inputmode="numeric" autocomplete="off">
                            <span class="hsd-date-sep">/</span>
                            <input type="text" class="form-control form-control-lg hsd-date-field" id="newHsdMonth"
                                   placeholder="MM" maxlength="2" inputmode="numeric" autocomplete="off">
                            <span class="hsd-date-sep">/</span>
                            <input type="text" class="form-control form-control-lg hsd-date-field hsd-date-year" id="newHsdYear"
                                   placeholder="YYYY" maxlength="4" inputmode="numeric" autocomplete="off">
                        </div>
                    </div>

                    <!-- Quantity mode: Lẻ or Thùng -->
                    <div class="mb-3">
                        <label class="form-label">Nhập số lượng</label>
                        <div class="hsd-qty-mode">
                            <div class="btn-group w-100" role="group">
                                <input type="radio" class="btn-check" name="qtyMode" id="qtyModeLe" value="le" checked>
                                <label class="btn btn-outline-primary" for="qtyModeLe">
                                    <i class="bx bx-cube"></i> Lẻ (chai/hộp)
                                </label>
                                <input type="radio" class="btn-check" name="qtyMode" id="qtyModeThung" value="thung">
                                <label class="btn btn-outline-primary" for="qtyModeThung">
                                    <i class="bx bx-package"></i> Thùng
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- Lẻ input -->
                    <div class="mb-3" id="qtyLeBox">
                        <label class="form-label">Số lượng lẻ</label>
                        <input type="number" class="form-control form-control-lg" id="newQtyLe"
                               min="0" step="1" placeholder="0" inputmode="numeric">
                    </div>

                    <!-- Thùng input -->
                    <div class="mb-3" id="qtyThungBox" style="display:none;">
                        <div class="row g-2">
                            <div class="col-6">
                                <label class="form-label">Số thùng</label>
                                <input type="number" class="form-control form-control-lg" id="newQtyThung"
                                       min="0" step="1" placeholder="0" inputmode="numeric">
                            </div>
                            <div class="col-6">
                                <label class="form-label">Chai/thùng</label>
                                <input type="number" class="form-control form-control-lg" id="newQtyPerBox"
                                       min="1" step="1" placeholder="48" inputmode="numeric">
                            </div>
                        </div>
                        <div class="hsd-thung-total mt-2">
                            = <strong id="thungTotalCalc">0</strong> chai
                        </div>
                        <!-- Thêm lẻ (ngoài thùng) -->
                        <div class="mt-2">
                            <label class="form-label">+ Lẻ thêm (ngoài thùng)</label>
                            <input type="number" class="form-control" id="newQtyLeExtra"
                                   min="0" step="1" value="0" inputmode="numeric">
                        </div>
                        <div class="hsd-thung-grand-total mt-2">
                            Tổng cộng: <strong id="thungGrandTotal">0</strong> chai
                        </div>
                    </div>

                    <!-- Submit -->
                    <button type="button" class="btn btn-success btn-lg w-100" id="btnSaveHsd">
                        <i class="bx bx-check"></i> Lưu HSD
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ══════ HISTORY LOG (last 10 actions) ══════ -->
    <div class="hsd-log" id="logArea">
        <h6 class="hsd-log-title">
            <i class="bx bx-history"></i> Lịch sử thao tác
            <button type="button" class="btn btn-sm btn-outline-secondary" id="btnClearLog">Xóa</button>
        </h6>
        <div class="hsd-log-list" id="logList"></div>
    </div>
</div>

<script>
    // Pass config to JS
    window.HSD_CHECKER_CONFIG = {
        ajaxUrl: '<?php echo admin_url('admin-ajax.php'); ?>',
        nonce: '<?php echo $nonce; ?>',
        productNonce: '<?php echo $product_nonce; ?>',
        pluginUrl: '<?php echo TGS_HSD_CHECKER_URL; ?>',
        shopPluginUrl: '<?php echo TGS_SHOP_PLUGIN_URL; ?>',
    };
</script>
<script src="<?php echo esc_url($checker_js_url); ?>?v=<?php echo TGS_HSD_CHECKER_VERSION; ?>"></script>
