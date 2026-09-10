# Changelog

## [1.1.9] - 2026-09-10

### Fixed

退款路徑的一整輪加固，主題只有一個：**不確定的結果不得被當成失敗**。把「結果不明」
誤標成失敗，core 就會解除凍結、允許再退一次——而街口那邊可能已經退了。

- **R6-F3｜落盤失敗不得送出**：`refund_order_id` 是對街口的冪等憑證，pre-send 寫入
  的結果先前被忽略——寫失敗照送，crash 後 retry 會產生新的 `refund_order_id` 再送
  一次。現在檢查 `YSOrder::update` 的結果，失敗即中止（金流未動、訊息明示「未送出」、
  caller 可安全重試）。
- **R7-F1｜typed outcome**：client timeout 與非 2xx 先前回一般 false，core 誤當成
  「明確拒絕」而解凍，同單重試即重複退款。現在連線層失敗與 HTTP 非 2xx 標為
  indeterminate（core 維持凍結），HTTP 2xx 但 `result` ≠ 000 才是街口明確拒絕
  （可重試）。pre-send 業務拒絕歸為 rejected_terminal。
- **R8-F1｜2xx 不等於成功**：舊版把「2xx 但 body 非有效 JSON 物件或缺 `result`
  業務碼」歸成 terminal failure；現在那一類回 indeterminate（維持凍結），只有
  `result` 存在且 ≠ 000 才算明確拒絕。
- **R14｜webhook 寫入改 CAS**：`payment_detail` 原本是整包盲寫（進場即 stale 的
  read-modify-write），會在 core 退款 CAS 成功之後反向把整個 ledger 覆蓋回去。改成
  fresh read → mutator → 真 CAS → bounded retry；同值即冪等 no-op，SQL 失敗不重試，
  `gateway_trade_no` 同語句 SET。寫入失敗時消費回傳中止、不進行狀態轉換。
- 移除 vendored Hub client 的 WooCommerce HPOS 相容宣告。YS CART 不是 WooCommerce，
  這段 `before_woocommerce_init` 在這裡沒有意義（`ys-cart-content-access` v1.0.6
  已先修掉同一段，本版讓兩邊一致）。

### Added

- 依 core v2.56.4 協定宣告閘道退款能力。

## [1.1.8] - 2026-06-12

### Security
- Webhook and reconcile now pass the gateway-reported paid amount into the
  payment detail DTO so YS CART core verifies `paid_amount` against the order
  total before marking the order paid. Previously the amount guard was a no-op
  for JKOPay because no `paid_amount` was supplied, allowing a tampered/low
  amount to settle the order at full price.
