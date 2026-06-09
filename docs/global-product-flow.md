# Luồng sản phẩm global cho TGS HSD Checker

Plugin này kiểm kê/tạo sản phẩm con theo hạn sử dụng. Từ bản chuyển global, mọi dữ liệu catalog sản phẩm đều đi qua `wp_global_product_name` hoặc `TGS_Global_Product_Source`.

## Nguyên tắc

- Không đọc/ghi bảng sản phẩm local.
- Các key `local_product_*` trong AJAX chỉ là alias để giữ giao diện cũ chạy ổn.
- Số lượng/tồn không được cập nhật vào cột catalog sản phẩm.
- Khi cần hiển thị tồn, gọi `TGS_Global_Product_Source::get_stock_for_skus()` hoặc `query_products(... with_stock=true ...)`.
- Khi tạo/cộng/sửa số lượng sản phẩm con HSD, ghi phiếu ledger qua `TGS_Adjustment_Ledger_Helper`; ledger/API sẽ là nguồn tồn.

## File chính

- `includes/class-ajax-hsd-checker.php`
  - `lookup_barcode`: tìm sản phẩm cha bằng `global_product_barcode_main`.
  - `search_product`: gọi `TGS_Global_Product_Source::query_products()`.
  - `map_barcode`: cập nhật barcode vào `global_product_name`.
  - `get_children`: lấy con HSD bằng `global_product_parent_sku`, tồn lấy từ stock API/ledger.
  - `create_child`: tạo sản phẩm con trong `global_product_name`, ghi barcode HSD vào `global_product_hsd_identifiers`, nếu có số lượng thì ghi ledger.
  - `update_quantity`: không update cột tồn sản phẩm; chỉ ghi ledger bằng `record_qty_change()`.

## Lưu ý cho team

Nếu phát triển thêm tính năng mới, hãy giữ UI nhận được `local_product_*` nếu cần tương thích JS, nhưng nguồn dữ liệu phải là global. Không join về `local_product_name`, không update `local_product_quantity_no_tracking`.
