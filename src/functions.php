<?php
/**
 * Public helper functions.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

use EuroB2B\Modules\Customers\AudienceContext;
use EuroB2B\Modules\Customers\AudienceResolver;
use EuroB2B\Modules\Customers\CustomerService;
use EuroB2B\Modules\Customers\CustomersModule;
use EuroB2B\Modules\Customers\CustomerStatus;
use EuroB2B\Modules\Customers\GroupRepository;
use EuroB2B\Modules\Registration\ExternalRegistration;
use EuroB2B\Plugin;

if ( ! function_exists( 'eurob2b' ) ) {
	/**
	 * Returns the plugin instance.
	 */
	function eurob2b(): Plugin {
		return Plugin::instance();
	}
}

if ( ! function_exists( 'eurob2b_audience' ) ) {
	/**
	 * Audience context of the current visitor (or of a given user).
	 *
	 * @param int|null $user_id User id; null for the current user.
	 */
	function eurob2b_audience( ?int $user_id = null ): AudienceContext {
		$resolver = Plugin::instance()->container()->get( AudienceResolver::class );
		return null === $user_id ? $resolver->current() : $resolver->for_user( $user_id );
	}
}

if ( ! function_exists( 'eurob2b_set_customer_group' ) ) {
	/**
	 * Moves a customer into a customer group (GRP-006), e.g. from an importer or an ERP connector.
	 *
	 * The customer type follows the group (a business group makes a business customer); the approval
	 * status is kept (missing status = approved). The change runs through the same validation and
	 * events as the user profile (`eurob2b_customer_assigned`). There is no capability check: the
	 * caller is trusted code. Check `current_user_can( 'edit_user', $user_id )` yourself when you act
	 * on a request.
	 *
	 * @param int $user_id  User id.
	 * @param int $group_id Group id.
	 * @param int $actor_id User who made the change (0 = system); recorded with the event.
	 * @return true|WP_Error
	 */
	function eurob2b_set_customer_group( int $user_id, int $group_id, int $actor_id = 0 ) {
		$container = Plugin::instance()->container();
		$group     = $container->get( GroupRepository::class )->find( $group_id );
		if ( null === $group ) {
			return new WP_Error( 'eurob2b_unknown_group', sprintf( 'Customer group %d does not exist.', $group_id ) );
		}
		$status = CustomerStatus::tryFrom( (string) get_user_meta( $user_id, CustomersModule::META_STATUS, true ) ) ?? CustomerStatus::APPROVED;
		try {
			$container->get( CustomerService::class )->assign( $user_id, $group->customer_type, $status, $group->id, $actor_id );
		} catch ( Throwable $e ) {
			return new WP_Error( 'eurob2b_assignment_failed', $e->getMessage() );
		}
		return true;
	}
}//end if

if ( ! function_exists( 'eurob2b_validate_business_registration' ) ) {
	/**
	 * Checks the data of a business registration made by another form (REG-011), with the rules of
	 * the EuroB2B registration form: custom fields of the registration type, terms checkbox, VAT ID.
	 * Call it from the form builder's validation, before it creates the user, to show the messages
	 * to the visitor. See docs/form-builders.md for the data.
	 *
	 * @param array<string, mixed> $data Keys `type`, `fields`, `vat_id`, `terms_accepted`.
	 * @return true|WP_Error Field, terms and VAT ID messages are translated for the visitor.
	 */
	function eurob2b_validate_business_registration( array $data ) {
		$checked = Plugin::instance()->container()->get( ExternalRegistration::class )->validate( $data );
		return $checked instanceof WP_Error ? $checked : true;
	}
}

if ( ! function_exists( 'eurob2b_register_business_customer' ) ) {
	/**
	 * Turns a user another form just created into an EuroB2B business customer (REG-011): pending
	 * or approved as the registration type says, with its field values, terms acceptance and VAT ID,
	 * and the usual e-mails. Only accounts with no role besides customer or subscriber that are not
	 * EuroB2B customers yet are accepted. There is no capability check: the caller is trusted code.
	 * The action `eurob2b_register_business_customer` with the same arguments does the same.
	 *
	 * @param int                  $user_id User created by the form builder.
	 * @param array<string, mixed> $data    Keys `type`, `fields`, `vat_id`, `terms_accepted`.
	 * @return true|WP_Error Nothing is written on an error.
	 */
	function eurob2b_register_business_customer( int $user_id, array $data = array() ) {
		return Plugin::instance()->container()->get( ExternalRegistration::class )->register( $user_id, $data );
	}
}
