<?php
/**
 * A part of the settings file that a module contributes.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Admin;

use Closure;

/**
 * One part of the configuration file of PLT-012 that belongs to a module, e.g. the quote request
 * fields (EuroB2B Pro) or the payment method policies. Modules add their parts through the filter
 * `eurob2b_settings_transfer_parts`; the settings, groups and registration types and fields are the
 * core's own parts (PLT-015).
 *
 * The export callback returns the value written under the part's key; the apply callback writes an
 * imported value through the owning module (which validates it), adds row errors to the report's
 * `errors` and throws for an error that stops the part.
 */
final class TransferPart {

	/**
	 * Returns the value to export.
	 *
	 * @var Closure(): mixed
	 */
	private Closure $export;

	/**
	 * Writes an imported value.
	 *
	 * @var Closure(array<mixed>, array<string, mixed>): void
	 */
	private Closure $apply;

	/**
	 * Constructor.
	 *
	 * @param string  $key    Key in the file (lower snake case, stable).
	 * @param string  $label  Name shown in the import report.
	 * @param int     $order  Position in the file and in the report; the core's parts use 10 to 30.
	 * @param Closure $export Returns the value to export.
	 * @param Closure $apply  Writes an imported value: function ( array $incoming, array &$report ): void.
	 */
	public function __construct(
		public readonly string $key,
		public readonly string $label,
		public readonly int $order,
		Closure $export,
		Closure $apply
	) {
		$this->export = $export;
		$this->apply  = $apply;
	}

	/**
	 * The value written to the file.
	 *
	 * @return mixed
	 */
	public function export() {
		return ( $this->export )();
	}

	/**
	 * Writes an imported value.
	 *
	 * @param array<mixed>         $incoming Value from the file, group references already rewritten.
	 * @param array<string, mixed> $report   Report; row errors are added to `errors`.
	 */
	public function apply( array $incoming, array &$report ): void {
		$apply = $this->apply;
		$apply( $incoming, $report );
	}
}
