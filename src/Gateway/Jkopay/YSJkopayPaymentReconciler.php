<?php

namespace YangSheep\YSCartJkopay\Gateway\Jkopay;

defined( 'ABSPATH' ) || exit;

use YangSheep\Ecommerce\DTOs\YSPaymentDetailDTO;
use YangSheep\Ecommerce\Services\Payment\YSPaymentReconcileResult;
use YangSheep\Ecommerce\Services\Payment\YSPaymentReconcilerInterface;

final class YSJkopayPaymentReconciler implements YSPaymentReconcilerInterface {
	private YSJkopayClient $client;

	public function __construct( ?YSJkopayClient $client = null ) {
		$this->client = $client ?: new YSJkopayClient();
	}

	public function supports( object $order ): bool {
		$detail     = $this->payment_detail( $order );
		$gateway_id = (string) ( $order->gateway_id ?? $order->payment_method ?? '' );

		return YSJkopayGateway::GATEWAY_ID === $gateway_id
			|| YSJkopayGateway::GATEWAY_ID === (string) ( $order->payment_method ?? '' )
			|| '' !== (string) ( $detail['ys_jkopay_platform_order_id'] ?? '' )
			|| '' !== (string) ( $detail[ YSJkopayWebhookHandler::META_TRADE_NO ] ?? '' );
	}

	public function reconcile( object $order ): YSPaymentReconcileResult {
		$detail            = $this->payment_detail( $order );
		$platform_order_id = (string) ( $detail['ys_jkopay_platform_order_id'] ?? $order->order_number ?? '' );

		if ( '' === $platform_order_id ) {
			return YSPaymentReconcileResult::unsupported( 'JKoPay platform order id is missing.' );
		}

		$result = $this->client->inquiry( $platform_order_id );
		$data   = is_array( $result['data'] ?? null ) ? $result['data'] : [];

		if ( empty( $result['success'] ) ) {
			return YSPaymentReconcileResult::error( (string) ( $result['message'] ?? 'JKoPay inquiry failed.' ), null, $data );
		}

		$payload        = $this->flatten_payload( $data );
		$payment_detail = $this->detail_from_query( $payload, $platform_order_id );
		$status         = $this->extract_status( $payload );
		$mapped         = YSJkopayWebhookHandler::map_status( $status );

		return match ( $mapped ) {
			'success'  => YSPaymentReconcileResult::paid( $payment_detail, 'JKoPay inquiry confirmed payment.', $data ),
			'failed'   => YSPaymentReconcileResult::failed( $payment_detail, 'failed', 'JKoPay inquiry reported a failed payment.', $data ),
			'refunded' => YSPaymentReconcileResult::handled( 'JKoPay inquiry reported an already refunded payment.', $data ),
			'pending'  => YSPaymentReconcileResult::hold( 'JKoPay inquiry found the trade but payment is still pending.', $payment_detail, $data ),
			default    => YSPaymentReconcileResult::hold( 'JKoPay inquiry returned an unknown trade state.', $payment_detail, $data ),
		};
	}

	/**
	 * @return array<string,mixed>
	 */
	private function payment_detail( object $order ): array {
		$detail = json_decode( (string) ( $order->payment_detail ?? '{}' ), true );
		return is_array( $detail ) ? $detail : [];
	}

	/**
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	private function flatten_payload( array $data ): array {
		$object = is_array( $data['result_object'] ?? null ) ? $data['result_object'] : [];
		return array_merge( $data, $object );
	}

	/**
	 * @param array<string,mixed> $payload
	 */
	private function extract_status( array $payload ): int {
		$status = $payload['status'] ?? $payload['trade_status'] ?? $payload['TradeStatus'] ?? null;
		return is_numeric( $status ) ? (int) $status : -1;
	}

	/**
	 * @param array<string,mixed> $payload
	 */
	private function detail_from_query( array $payload, string $platform_order_id ): YSPaymentDetailDTO {
		$trade_no = (string) ( $payload['tradeNo'] ?? $payload['trade_no'] ?? '' );
		$status   = $this->extract_status( $payload );

		return YSPaymentDetailDTO::from_legacy_array(
			[
				'payment_type'                  => 'jkopay',
				'trade_status'                  => (string) $status,
				'trade_no'                      => $trade_no,
				'gateway_trade_no'              => $trade_no,
				'mer_trade_no'                  => $platform_order_id,
				'response_code'                 => (string) ( $payload['result'] ?? $status ),
				'response_message'              => (string) ( $payload['code_msg'] ?? $payload['message'] ?? '' ),
				'ys_jkopay_platform_order_id'   => $platform_order_id,
				YSJkopayWebhookHandler::META_TRADE_NO => $trade_no,
				YSJkopayWebhookHandler::META_LAST_STATUS => $status,
			],
			YSJkopayGateway::GATEWAY_ID
		);
	}
}
