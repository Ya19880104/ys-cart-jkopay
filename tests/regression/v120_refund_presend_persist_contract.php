<?php
/**
 * Contract regression: 退款 pre-send persist 檢查（CODEX 終審 R6-F3）。
 *
 * 保證 YSJkopayGateway::process_refund：
 *   (1) refund_order_id（對街口的冪等憑證）落盤失敗 → 中止、不呼叫 client
 *   (2) 持久化檢查出現在 client refund 呼叫**之前**（順序契約）——否則 crash 後
 *       retry 會產生新 refund_order_id 再送一次＝重複退款
 *
 * Run: php tests/regression/v120_refund_presend_persist_contract.php
 */

declare( strict_types = 1 );

$base = dirname( __DIR__, 2 );
$src  = file_get_contents( $base . '/src/Gateway/Jkopay/YSJkopayGateway.php' );

if ( false === $src ) {
	echo "FATAL: cannot read YSJkopayGateway.php\n";
	exit( 1 );
}

$src = str_replace( "\r\n", "\n", $src );

$pass = 0;
$fail = 0;
$assert = static function ( bool $ok, string $label ) use ( &$pass, &$fail ): void {
	if ( $ok ) {
		++$pass;
		echo "  PASS  {$label}\n";
		return;
	}
	++$fail;
	echo "  FAIL  {$label}\n";
};

$m_start = strpos( $src, 'public function process_refund(' );
$assert( false !== $m_start, '(0) process_refund 方法存在' );
$method = false !== $m_start ? substr( $src, $m_start ) : '';

// (1) 持久化結果被檢查、失敗訊息明示「未送出」
$assert(
	1 === preg_match( '/if\s*\(\s*!\s*YSOrder::update\(/', $method ),
	'(1a) refund_order_id 持久化結果被檢查（if ( ! YSOrder::update）'
);
$assert(
	str_contains( $method, '未送出街口退款請求' ),
	'(1b) 寫入失敗訊息明示「未送出」（金流未動、可安全重試）'
);

// (2) 順序契約：persist 檢查在 client refund 呼叫之前
$pos_check = strpos( $method, '未送出街口退款請求' );
$pos_call  = strpos( $method, '->refund(' );
$assert(
	false !== $pos_check && false !== $pos_call && $pos_check < $pos_call,
	'(2) 持久化檢查在 client refund 呼叫之前'
);

// ── R7-F1：typed outcome（indeterminate vs rejected_terminal）──
$client_src = str_replace( "\r\n", "\n", (string) file_get_contents( $base . '/src/Gateway/Jkopay/YSJkopayClient.php' ) );
$assert(
	substr_count( $client_src, "'indeterminate' => true" ) >= 2
	&& str_contains( $client_src, "'indeterminate' => false" ),
	'(3) client：連線/非 2xx→indeterminate=true、result≠000→indeterminate=false'
);
$assert(
	str_contains( $method, "! empty( \$result['indeterminate'] ) ? 'indeterminate' : 'rejected_terminal'" )
	&& str_contains( $method, "'outcome' => 'rejected_terminal'" ),
	'(4) gateway 依 indeterminate 組 typed outcome（pre-send terminal／結果不明凍結）'
);

// (5) R8-F1：2xx 但不符成功 envelope（JSON 無效／缺 result）→ indeterminate。
$assert(
	str_contains( $client_src, "! isset( \$data['result'] )" )
	&& str_contains( $client_src, '街口回應缺少 result 業務碼' ),
	'(5) R8-F1：HTTP 2xx 但 JSON 無效／缺 result → indeterminate（非 terminal）'
);

echo "\njkopay refund pre-send persist contract: {$pass} PASS / {$fail} FAIL\n";
exit( $fail > 0 ? 1 : 0 );
