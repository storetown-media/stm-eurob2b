<?php
/**
 * Result of the VAT ID check in the Checkout block.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Tax;

use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;
use EuroB2B\Support\Assets;

/**
 * TAX-015 in the Checkout block: loads `assets/js/block-vat-feedback.js` with the block. The script
 * reads the result of the check from the cart extension data that `VatIdField` registers and shows
 * it as a notice at the address with the VAT ID field, as the classic checkout shows it next to the
 * order button. The texts come from the server, already translated; the script has none of its own.
 */
final class BlockVatFeedback implements IntegrationInterface {

	public const NAME   = 'eurob2b-vat-feedback';
	public const SCRIPT = 'eurob2b-block-vat-feedback';

	/**
	 * Integration name.
	 */
	public function get_name(): string {
		return self::NAME;
	}

	/**
	 * Registers the script.
	 */
	public function initialize(): void {
		if ( ! wp_script_is( self::SCRIPT, 'registered' ) ) {
			wp_register_script( self::SCRIPT, EUROB2B_PLUGIN_URL . 'assets/js/block-vat-feedback.js', array( 'wp-data', 'wc-blocks-data-store' ), Assets::version( 'assets/js/block-vat-feedback.js' ), true );
		}
	}

	/**
	 * Frontend scripts.
	 *
	 * @return string[]
	 */
	public function get_script_handles(): array {
		return array( self::SCRIPT );
	}

	/**
	 * Editor scripts: none, a result only exists for a real checkout.
	 *
	 * @return string[]
	 */
	public function get_editor_script_handles(): array {
		return array();
	}

	/**
	 * Script data.
	 *
	 * @return array{namespace: string}
	 */
	public function get_script_data(): array {
		return array( 'namespace' => VatIdField::STORE_API_NAMESPACE );
	}
}
