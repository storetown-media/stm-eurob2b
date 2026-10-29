<?php
/**
 * Personal data provider contract.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Privacy;

/**
 * Implemented by every module that stores personal data.
 *
 * The export() and erase() methods follow the WordPress callback contract
 * (see wp_privacy_personal_data_exporters / _erasers). Erasure must respect
 * statutory retention: records that must be kept are anonymised and reported
 * in "messages" instead of being deleted.
 */
interface PersonalDataProviderInterface {

	/**
	 * Stable id (lower snake case).
	 */
	public function id(): string;

	/**
	 * Human readable name shown in the WordPress privacy tools.
	 */
	public function label(): string;

	/**
	 * Exports data for an email address.
	 *
	 * @param string $email_address Email address of the data subject.
	 * @param int    $page          Page number (1-based).
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public function export( string $email_address, int $page = 1 ): array;

	/**
	 * Erases or anonymises data for an email address.
	 *
	 * @param string $email_address Email address of the data subject.
	 * @param int    $page          Page number (1-based).
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public function erase( string $email_address, int $page = 1 ): array;
}
