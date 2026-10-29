<?php
/**
 * A plugin the importer can read from.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Migration;

/**
 * Reads another B2B plugin's stored data (MIG-001) and hands it to the ImportWriter in EuroB2B's
 * model. A source only reads: it never changes or deletes the other plugin's data, and it works
 * from the database, so the other plugin may already be deactivated. Pages are deterministic
 * (ordered by id), so a run can stop after any page and continue.
 */
interface SourceInterface {

	/**
	 * Stable id (lower case), e.g. `b2bking`.
	 */
	public function id(): string;

	/**
	 * Name of the plugin.
	 */
	public function label(): string;

	/**
	 * Whether the database holds data of this plugin.
	 */
	public function detected(): bool;

	/**
	 * Whether the plugin is still active (both plugins would then apply their prices and rules).
	 */
	public function active(): bool;

	/**
	 * What was found, for the overview: label => number.
	 *
	 * @return array<string, int>
	 */
	public function summary(): array;

	/**
	 * Parts in import order: part id => label. Later parts may rely on targets of earlier ones.
	 *
	 * @return array<string, string>
	 */
	public function parts(): array;

	/**
	 * Notes for the report before the first part: settings to compare by hand, data that is not
	 * imported (tax, offers, …).
	 *
	 * @return array<int, array{level: string, text: string}>
	 */
	public function notes(): array;

	/**
	 * Handles one page of a part.
	 *
	 * @param string       $part   Part id.
	 * @param int          $offset Source items of this part handled before.
	 * @param int          $limit  Items per page.
	 * @param ImportWriter $writer Writer (test run or import).
	 * @return int Source items handled; 0 when the part is done.
	 */
	public function import_page( string $part, int $offset, int $limit, ImportWriter $writer ): int;
}
