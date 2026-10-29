<?php
/**
 * The payment and shipping method parts of the settings file.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Payments\Admin;

use EuroB2B\Container;
use EuroB2B\Modules\Admin\TransferPart;
use EuroB2B\Modules\Payments\MethodPolicy;

/**
 * Parts of the settings file of PLT-012 that belong to this module: the payment and shipping
 * method policies. They were part of SettingsTransfer until the edition split (PLT-015); file
 * format and import report are unchanged. The payment terms and the options of the payment and
 * shipping adjustments follow them as parts of EuroB2B Pro (PaymentsProTransferParts).
 */
final class PaymentsTransferParts {

	/**
	 * Filter callback (eurob2b_settings_transfer_parts).
	 *
	 * @param mixed     $parts Parts so far.
	 * @param Container $c     Container.
	 * @return mixed
	 */
	public static function add( $parts, Container $c ) {
		if ( ! is_array( $parts ) ) {
			return $parts;
		}
		$parts[] = new TransferPart(
			'payment_policy',
			__( 'Payment methods per audience', 'eurob2b' ),
			50,
			static fn() => $c->get( MethodPolicy::class )->load( MethodPolicy::KIND_PAYMENT ),
			static function ( array $incoming, array &$report ) use ( $c ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- the part's signature.
				$c->get( MethodPolicy::class )->save( MethodPolicy::KIND_PAYMENT, $incoming );
			}
		);
		$parts[] = new TransferPart(
			'shipping_policy',
			__( 'Shipping methods per audience', 'eurob2b' ),
			60,
			static fn() => $c->get( MethodPolicy::class )->load( MethodPolicy::KIND_SHIPPING ),
			static function ( array $incoming, array &$report ) use ( $c ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- the part's signature.
				$c->get( MethodPolicy::class )->save( MethodPolicy::KIND_SHIPPING, $incoming );
			}
		);
		return $parts;
	}
}
