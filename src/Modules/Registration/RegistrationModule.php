<?php
/**
 * Registration module.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Registration;

use EuroB2B\Container;
use EuroB2B\Module\AbstractModule;
use EuroB2B\Modules\Admin\Settings;
use EuroB2B\Modules\Admin\SettingsSchema;
use EuroB2B\Modules\Customers\AudienceResolver;
use EuroB2B\Modules\Customers\CustomerService;
use EuroB2B\Modules\Customers\CustomersDataProvider;
use EuroB2B\Modules\Customers\GroupRepository;
use EuroB2B\Modules\Privacy\PrivateFiles;
use EuroB2B\Modules\Registration\Admin\ApprovalsPage;
use EuroB2B\Modules\Registration\Admin\RegistrationPage;
use EuroB2B\Modules\Tax\VatIdField;

/**
 * Business registration with approval, custom fields and e-mails (the login guard lives in the customers module).
 */
final class RegistrationModule extends AbstractModule {

	/**
	 * Module id.
	 */
	public function id(): string {
		return 'registration';
	}

	/**
	 * Registers services.
	 *
	 * @param Container $c Service container.
	 */
	public function register( Container $c ): void {
		$c->set( RegistrationTypeRepository::class, static fn(): RegistrationTypeRepository => new RegistrationTypeRepository() );
		$c->set( FieldRepository::class, static fn(): FieldRepository => new FieldRepository() );
		$c->set( PrivateFiles::class, static fn(): PrivateFiles => new PrivateFiles() );
		$c->set( FieldStorage::class, static fn( Container $c ): FieldStorage => new FieldStorage( $c->get( FieldRepository::class ), $c->get( PrivateFiles::class ) ) );
		$c->set( FieldRenderer::class, static fn( Container $c ): FieldRenderer => new FieldRenderer( (int) $c->get( Settings::class )->get( 'registration_file_max_mb' ) ) );
		$c->set(
			AntiAbuse::class,
			static function ( Container $c ): AntiAbuse {
				$settings = $c->get( Settings::class );
				return new AntiAbuse( (int) $settings->get( 'registration_throttle_limit' ), 3600 );
			}
		);
		$c->set( RegistrationService::class, static fn( Container $c ): RegistrationService => new RegistrationService( $c->get( RegistrationTypeRepository::class ), $c->get( FieldRepository::class ), $c->get( FieldStorage::class ), $c->get( CustomerService::class ), $c->get( GroupRepository::class ), $c->get( PrivateFiles::class ), (int) $c->get( Settings::class )->get( 'registration_file_max_mb' ) ) );
		$c->set( RegistrationForm::class, static fn( Container $c ): RegistrationForm => new RegistrationForm( $c->get( RegistrationService::class ), $c->get( RegistrationTypeRepository::class ), $c->get( FieldRepository::class ), $c->get( FieldRenderer::class ), $c->get( AntiAbuse::class ), $c->get( Settings::class ) ) );
		$c->set( CheckoutFields::class, static fn( Container $c ): CheckoutFields => new CheckoutFields( $c->get( FieldRepository::class ), $c->get( FieldStorage::class ), $c->get( RegistrationService::class ) ) );
		$c->set( AccountFields::class, static fn( Container $c ): AccountFields => new AccountFields( $c->get( FieldRepository::class ), $c->get( FieldStorage::class ), $c->get( FieldRenderer::class ), $c->get( RegistrationService::class ) ) );
		$c->set( ApprovalsPage::class, static fn( Container $c ): ApprovalsPage => new ApprovalsPage( $c->get( RegistrationService::class ), $c->get( GroupRepository::class ), $c->get( FieldStorage::class ), $c->get( AudienceResolver::class ) ) );
		$c->set( RegistrationPage::class, static fn( Container $c ): RegistrationPage => new RegistrationPage( $c->get( RegistrationTypeRepository::class ), $c->get( FieldRepository::class ), $c->get( GroupRepository::class ) ) );
		$c->set( CustomersDataProvider::class, static fn( Container $c ): CustomersDataProvider => new CustomersDataProvider( $c->get( FieldRepository::class ), $c->get( FieldStorage::class ), $c->get( GroupRepository::class ), $c->get( RegistrationTypeRepository::class ) ) );
		$c->set( ExternalRegistration::class, static fn( Container $c ): ExternalRegistration => new ExternalRegistration( $c->get( RegistrationService::class ), $c->get( RegistrationTypeRepository::class ), $c->get( FieldRepository::class ), $c->get( Settings::class ), $c->has( VatIdField::class ) ? $c->get( VatIdField::class ) : null ) );
		$c->set( RejectedApplicationsRetention::class, static fn( Container $c ): RejectedApplicationsRetention => new RejectedApplicationsRetention( $c->get( Settings::class ) ) );
		$c->set( RegistrationFilesRetention::class, static fn( Container $c ): RegistrationFilesRetention => new RegistrationFilesRetention( $c->get( Settings::class ), $c->get( PrivateFiles::class ) ) );

		add_action( 'eurob2b_settings_schema', array( self::class, 'settings_schema' ) );
	}

	/**
	 * Adds hooks.
	 *
	 * @param Container $c Service container.
	 */
	public function boot( Container $c ): void {
		$c->get( RegistrationForm::class )->hooks();
		$c->get( PrivateFiles::class )->hooks();
		$c->get( CheckoutFields::class )->hooks();
		$c->get( AccountFields::class )->hooks();

		// REG-011: form builders hand over the users they created (docs/form-builders.md).
		add_action( 'eurob2b_register_business_customer', static fn( $user_id, $data = array() ) => $c->get( ExternalRegistration::class )->on_action( $user_id, $data ), 10, 2 );

		// E-mails: let WooCommerce load the mailer lazily for our events.
		add_filter( 'woocommerce_email_actions', array( self::class, 'email_actions' ) );
		add_filter( 'woocommerce_email_classes', array( self::class, 'email_classes' ) );

		// Privacy export/erasure.
		add_filter(
			'eurob2b_personal_data_providers',
			static function ( array $providers ) use ( $c ): array {
				$providers[] = $c->get( CustomersDataProvider::class );
				return $providers;
			}
		);
		add_filter(
			'eurob2b_retention_tasks',
			static function ( array $tasks ) use ( $c ): array {
				$tasks[] = $c->get( RejectedApplicationsRetention::class );
				$tasks[] = $c->get( RegistrationFilesRetention::class );
				return $tasks;
			}
		);

		add_action( 'init', array( $this, 'register_block' ) );

		// A deleted group must not leave registration types pointing nowhere: retarget them.
		add_action(
			'eurob2b_group_deleted',
			static function ( \EuroB2B\Modules\Customers\Group $deleted, \EuroB2B\Modules\Customers\Group $target ) use ( $c ): void {
				$types = $c->get( RegistrationTypeRepository::class );
				foreach ( $types->all() as $type ) {
					if ( $type->group_id === $deleted->id ) {
						$types->save( $type->with( array( 'group_id' => $target->customer_type === $type->customer_type ? $target->id : 0 ) ) );
					}
				}
			},
			10,
			2
		);

		if ( ! is_admin() ) {
			return;
		}
		$approvals = $c->get( ApprovalsPage::class );
		add_action( 'eurob2b_admin_menu', array( $approvals, 'register_page' ) );
		add_action( 'admin_post_' . ApprovalsPage::ACTION_APPROVE, array( $approvals, 'handle_approve' ) );
		add_action( 'admin_post_' . ApprovalsPage::ACTION_REJECT, array( $approvals, 'handle_reject' ) );

		$page = $c->get( RegistrationPage::class );
		add_action( 'eurob2b_admin_menu', array( $page, 'register_page' ) );
		add_action( 'admin_post_' . RegistrationPage::ACTION_SAVE_TYPE, array( $page, 'handle_save_type' ) );
		add_action( 'admin_post_' . RegistrationPage::ACTION_DELETE_TYPE, array( $page, 'handle_delete_type' ) );
		add_action( 'admin_post_' . RegistrationPage::ACTION_SAVE_FIELD, array( $page, 'handle_save_field' ) );
		add_action( 'admin_post_' . RegistrationPage::ACTION_DELETE_FIELD, array( $page, 'handle_delete_field' ) );
	}

	/**
	 * Registers the registration form block (server-rendered).
	 */
	public function register_block(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		$form = \EuroB2B\Plugin::instance()->container()->get( RegistrationForm::class );
		register_block_type(
			EUROB2B_PLUGIN_DIR . 'blocks/registration-form',
			array(
				'render_callback' => static function ( array $attributes ) use ( $form ): string {
					return $form->render_locked_form( sanitize_key( (string) ( $attributes['type'] ?? '' ) ) );
				},
			)
		);

		$types = array_map(
			static fn( RegistrationType $t ): array => array(
				'slug'  => $t->slug,
				'label' => $t->label,
			),
			array_values( \EuroB2B\Plugin::instance()->container()->get( RegistrationTypeRepository::class )->all() )
		);
		wp_add_inline_script( 'eurob2b-registration-form-editor-script', 'window.eurob2bRegistrationBlock = ' . (string) wp_json_encode( array( 'types' => $types ) ) . ';', 'before' );
	}

	/**
	 * Our transactional e-mail events.
	 *
	 * @param string[] $actions Actions.
	 * @return string[]
	 */
	public static function email_actions( array $actions ): array {
		$actions[] = 'eurob2b_customer_registered';
		$actions[] = 'eurob2b_registration_approved';
		$actions[] = 'eurob2b_registration_rejected';
		return $actions;
	}

	/**
	 * Our e-mail classes.
	 *
	 * @param array<string, \WC_Email> $classes Classes.
	 * @return array<string, \WC_Email>
	 */
	public static function email_classes( array $classes ): array {
		$classes['EuroB2B_Admin_New_Registration']  = new Emails\AdminNewRegistrationEmail();
		$classes['EuroB2B_Admin_Approval_Required'] = new Emails\AdminApprovalRequiredEmail();
		$classes['EuroB2B_Customer_Approved']       = new Emails\CustomerApprovedEmail();
		$classes['EuroB2B_Customer_Rejected']       = new Emails\CustomerRejectedEmail();
		return $classes;
	}

	/**
	 * Settings section.
	 *
	 * @param SettingsSchema $schema Schema.
	 */
	public static function settings_schema( SettingsSchema $schema ): void {
		$schema->add_section( 'registration', __( 'Registration', 'eurob2b' ), 10 );
		$schema->add_field(
			array(
				'id'          => 'registration_throttle_limit',
				'section'     => 'registration',
				'label'       => __( 'Registrations per hour and connection', 'eurob2b' ),
				'description' => __( 'Blocks further registrations from the same connection within an hour. 0 disables the limit. The connection is stored as a salted hash for one hour only.', 'eurob2b' ),
				'type'        => SettingsSchema::TYPE_NUMBER,
				'default'     => 5,
				'min'         => 0,
				'max'         => 1000,
			)
		);
		$schema->add_field(
			array(
				'id'          => 'registration_file_max_mb',
				'section'     => 'registration',
				'label'       => __( 'Size limit of uploaded documents (MB)', 'eurob2b' ),
				'description' => __( 'For fields of the type "file" in the registration form: PDF, JPG or PNG up to this size. The server may set a lower limit (upload_max_filesize, post_max_size).', 'eurob2b' ),
				'type'        => SettingsSchema::TYPE_NUMBER,
				'default'     => 5,
				'min'         => 1,
				'max'         => 20,
			)
		);
		$schema->add_field(
			array(
				'id'             => 'registration_terms_required',
				'section'        => 'registration',
				'label'          => __( 'Terms checkbox', 'eurob2b' ),
				'checkbox_label' => __( 'Require customers to tick a terms checkbox when registering (the time of acceptance is stored)', 'eurob2b' ),
				'type'           => SettingsSchema::TYPE_BOOLEAN,
				'default'        => false,
			)
		);
		$schema->add_field(
			array(
				'id'             => 'registration_suppress_pending_welcome',
				'section'        => 'registration',
				'label'          => __( 'Welcome e-mail for pending accounts', 'eurob2b' ),
				'checkbox_label' => __( 'Do not send the WooCommerce new-account e-mail while a registration waits for approval', 'eurob2b' ),
				'description'    => __( 'Recommended when WooCommerce generates passwords: the approval e-mail contains a link to set the password instead.', 'eurob2b' ),
				'type'           => SettingsSchema::TYPE_BOOLEAN,
				'default'        => true,
			)
		);
		$schema->add_field(
			array(
				'id'                => 'registration_terms_text',
				'section'           => 'registration',
				'label'             => __( 'Terms checkbox text', 'eurob2b' ),
				'description'       => __( 'Shown next to the checkbox. Links are allowed.', 'eurob2b' ),
				'type'              => SettingsSchema::TYPE_TEXTAREA,
				'default'           => '',
				'sanitize_callback' => array( self::class, 'sanitize_terms_text' ),
			)
		);
		$schema->add_field(
			array(
				'id'          => 'registration_file_retention_days',
				'section'     => 'privacy',
				'label'       => __( 'Delete documents uploaded with the registration after (days)', 'eurob2b' ),
				'description' => __( 'Counted from the approval or rejection of the application (default 30). 0 keeps them as long as the customer account exists. They are always deleted with the account and on an erasure request.', 'eurob2b' ),
				'type'        => SettingsSchema::TYPE_NUMBER,
				'default'     => 30,
				'min'         => 0,
				'max'         => 3650,
			)
		);
		$schema->add_field(
			array(
				'id'          => 'privacy_rejected_days',
				'section'     => 'privacy',
				'label'       => __( 'Delete rejected registrations after (days)', 'eurob2b' ),
				'description' => __( 'Accounts of applications you rejected are deleted this many days after the rejection by a daily job. Only accounts that were never approved, have no orders and have no role besides customer or subscriber are deleted; deactivated customers are never touched. 0 keeps them until you delete them by hand.', 'eurob2b' ),
				'type'        => SettingsSchema::TYPE_NUMBER,
				'default'     => 180,
				'min'         => 0,
				'max'         => 3650,
			)
		);
	}

	/**
	 * Allowed markup for the terms text (the same policy the form applies when rendering).
	 *
	 * @return array<string, array<string, bool>>
	 */
	public static function terms_allowed_html(): array {
		return array(
			'a' => array(
				'href'   => true,
				'target' => true,
				'rel'    => true,
			),
		);
	}

	/**
	 * Sanitises the terms text: links survive, everything else is stripped.
	 *
	 * @param string $value Raw value.
	 */
	public static function sanitize_terms_text( string $value ): string {
		return trim( wp_kses( $value, self::terms_allowed_html() ) );
	}
}
