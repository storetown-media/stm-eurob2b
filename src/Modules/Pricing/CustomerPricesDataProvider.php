<?php
/**
 * Privacy provider for customer prices.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Pricing;

use EuroB2B\Modules\Privacy\PersonalDataProviderInterface;
use EuroB2B\Support\Currency;
use WC_Product;

/**
 * Exports a customer's own prices and tier sets and deletes them on erasure (PRC-007, D-18).
 */
final class CustomerPricesDataProvider implements PersonalDataProviderInterface {

	/**
	 * Storage.
	 *
	 * @var CustomerPrices
	 */
	private CustomerPrices $prices;

	/**
	 * Constructor.
	 *
	 * @param CustomerPrices $prices Storage (prices and the customer's tier sets).
	 */
	public function __construct( CustomerPrices $prices ) {
		$this->prices = $prices;
	}

	/**
	 * Id.
	 */
	public function id(): string {
		return 'customer_prices';
	}

	/**
	 * Label.
	 */
	public function label(): string {
		return __( 'EuroB2B customer prices', 'eurob2b' );
	}

	/**
	 * Export callback: one item per product or variation and currency with an own price or tier set,
	 * exactly as stored (a tier set without an own price is exported too).
	 *
	 * @param string $email_address E-mail.
	 * @param int    $page          Page.
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public function export( string $email_address, int $page = 1 ): array {
		$user = get_user_by( 'email', $email_address );
		$data = array();
		// Prices in every currency the customer has some in (CUR-001), the shop currency first.
		$rows = array();
		foreach ( $user ? $this->prices->stored_by_suffix( $user->ID ) : array() as $suffix => $stored_rows ) {
			foreach ( $stored_rows as $product_id => $stored ) {
				$rows[] = array( $product_id, (string) $suffix, $stored );
			}
		}
		if ( $user ) {
			foreach ( $rows as list( $product_id, $suffix, $stored ) ) {
				$product = wc_get_product( $product_id );
				$fields  = array(
					array(
						'name'  => __( 'Product', 'eurob2b' ),
						'value' => $product instanceof WC_Product ? $product->get_name() . ( '' !== $product->get_sku() ? ' (' . $product->get_sku() . ')' : '' ) : '#' . $product_id,
					),
					array(
						'name'  => __( 'Currency', 'eurob2b' ),
						'value' => '' === $suffix ? Currency::shop() : strtoupper( substr( $suffix, 1 ) ),
					),
				);
				if ( '' !== $stored['regular'] ) {
					$fields[] = array(
						'name'  => __( 'Price', 'eurob2b' ),
						'value' => $stored['regular'],
					);
				}
				if ( '' !== $stored['sale'] ) {
					$fields[] = array(
						'name'  => __( 'Sale price', 'eurob2b' ),
						'value' => $stored['sale'],
					);
				}
				$raw   = '' !== $stored['tiers'] ? json_decode( $stored['tiers'], true ) : null;
				$tiers = Tier::from_array( $raw );
				// Unreadable JSON is still personal data: then the stored value goes out as it is.
				$tier_value = ! empty( $tiers ) ? (string) wp_json_encode( Tier::to_array( $tiers ) ) : $stored['tiers'];
				if ( '' !== $tier_value ) {
					$fields[] = array(
						'name'  => __( 'Tier prices', 'eurob2b' ),
						'value' => $tier_value,
					);
				}
				if ( count( $fields ) < 3 ) {
					continue;
				}
				$data[] = array(
					'group_id'    => 'eurob2b-customer-prices',
					'group_label' => $this->label(),
					'item_id'     => 'eurob2b-customer-price-' . $product_id . $suffix,
					'data'        => $fields,
				);
			}//end foreach
		}//end if
		return array(
			'data' => $data,
			'done' => true,
		);
	}

	/**
	 * Erase callback: the prices go (no retention duty; orders keep the prices they were placed at).
	 *
	 * @param string $email_address E-mail.
	 * @param int    $page          Page.
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public function erase( string $email_address, int $page = 1 ): array {
		$user    = get_user_by( 'email', $email_address );
		$removed = $user ? $this->prices->delete_for_user( $user->ID ) : 0;
		return array(
			'items_removed'  => $removed > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}
