<?php
/**
 * Custom capabilities.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Infrastructure;

/**
 * Fine-grained capabilities instead of blanket manage_woocommerce checks.
 *
 * Every admin screen, REST route and AJAX action maps to exactly one of these
 * (see docs/security.md). Administrators and shop managers get all of them.
 */
final class Capabilities {

	public const MANAGE_SETTINGS   = 'eurob2b_manage_settings';
	public const MANAGE_GROUPS     = 'eurob2b_manage_groups';
	public const MANAGE_CUSTOMERS  = 'eurob2b_manage_customers';
	public const MANAGE_RULES      = 'eurob2b_manage_rules';
	public const MANAGE_QUOTES     = 'eurob2b_manage_quotes';
	public const MANAGE_COMPANIES  = 'eurob2b_manage_companies';
	public const VIEW_REPORTS      = 'eurob2b_view_reports';
	public const DECIDE_VALIDATION = 'eurob2b_decide_validation';

	/**
	 * All capabilities.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array(
			self::MANAGE_SETTINGS,
			self::MANAGE_GROUPS,
			self::MANAGE_CUSTOMERS,
			self::MANAGE_RULES,
			self::MANAGE_QUOTES,
			self::MANAGE_COMPANIES,
			self::VIEW_REPORTS,
			self::DECIDE_VALIDATION,
		);
	}

	/**
	 * Grants all capabilities to administrators and shop managers.
	 */
	public static function install(): void {
		foreach ( array( 'administrator', 'shop_manager' ) as $role_name ) {
			$role = get_role( $role_name );
			if ( null === $role ) {
				continue;
			}
			foreach ( self::all() as $cap ) {
				$role->add_cap( $cap );
			}
		}
	}

	/**
	 * Removes all capabilities from every role (uninstall).
	 */
	public static function remove(): void {
		$roles = wp_roles();
		foreach ( array_keys( $roles->roles ) as $role_name ) {
			$role = get_role( (string) $role_name );
			if ( null === $role ) {
				continue;
			}
			foreach ( self::all() as $cap ) {
				$role->remove_cap( $cap );
			}
		}
	}
}
