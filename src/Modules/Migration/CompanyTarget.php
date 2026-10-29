<?php
/**
 * Where the import puts company accounts.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Migration;

use RuntimeException;

/**
 * Company accounts are a feature of EuroB2B Pro. The import (free) links the sub-accounts of
 * another plugin to companies only through this target, which the company module of EuroB2B Pro
 * registers in the container; without it the import reports the sub-accounts as not imported
 * (PLT-015, docs/architecture.md section "Editions").
 */
interface CompanyTarget {

	/**
	 * The company the user belongs to (as owner or member).
	 *
	 * @param int $user_id User.
	 * @return array{id: int, owner: int}|null Company id and its owner, null when the user belongs to none.
	 */
	public function company_of( int $user_id ): ?array;

	/**
	 * Id of the company the user is a member (or the owner) of, 0 when none; unlike company_of() also
	 * when the company record itself is gone.
	 *
	 * @param int $user_id User.
	 */
	public function membership_company( int $user_id ): int;

	/**
	 * Creates a company owned by the user.
	 *
	 * @param string   $name     Company name.
	 * @param int      $owner    Owner.
	 * @param int|null $group_id Business group of the company.
	 * @param int      $actor    Staff member who runs the import.
	 * @return int Id of the new company.
	 * @throws RuntimeException When the company cannot be created (translated message).
	 */
	public function create( string $name, int $owner, ?int $group_id, int $actor ): int;

	/**
	 * Deletes a company and ends its memberships; nothing happens when it does not exist.
	 *
	 * @param int $company_id Company.
	 * @throws RuntimeException When it cannot be deleted (translated message).
	 */
	public function delete( int $company_id ): void;

	/**
	 * Links the user to the company with the permissions of a buyer, without order approval.
	 *
	 * @param int $company_id Company.
	 * @param int $user_id    User.
	 * @param int $actor      Staff member who runs the import.
	 * @throws RuntimeException When the user cannot be linked (translated message).
	 */
	public function add_buyer( int $company_id, int $user_id, int $actor ): void;

	/**
	 * Unlinks the user from the company when it is a member of it and not its owner.
	 *
	 * @param int $company_id Company.
	 * @param int $user_id    User.
	 * @throws RuntimeException When the user cannot be unlinked (translated message).
	 */
	public function remove_member( int $company_id, int $user_id ): void;
}
