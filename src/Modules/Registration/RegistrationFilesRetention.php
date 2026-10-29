<?php
/**
 * Retention job for documents uploaded with the registration.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Registration;

use DateTimeImmutable;
use DateTimeZone;
use EuroB2B\Modules\Admin\Settings;
use EuroB2B\Modules\Customers\CustomersModule;
use EuroB2B\Modules\Privacy\PrivateFiles;
use EuroB2B\Modules\Privacy\RetentionTaskInterface;

/**
 * Documents uploaded with the registration (REG-016) are needed to decide on the application.
 * Setting `registration_file_retention_days` (default 30, product owner decision of 2026-10-25):
 * they are deleted that many days after the approval or rejection, together with the field value
 * that points to them; 0 keeps them as long as the account exists. Files of deleted accounts and the
 * orphans of the private storage (PRV-006) go in every run.
 */
final class RegistrationFilesRetention implements RetentionTaskInterface {

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Private files.
	 *
	 * @var PrivateFiles
	 */
	private PrivateFiles $files;

	/**
	 * Constructor.
	 *
	 * @param Settings     $settings Settings.
	 * @param PrivateFiles $files    Private files.
	 */
	public function __construct( Settings $settings, PrivateFiles $files ) {
		$this->settings = $settings;
		$this->files    = $files;
	}

	/**
	 * Id.
	 */
	public function id(): string {
		return 'registration_files';
	}

	/**
	 * Deletes up to $limit files.
	 *
	 * @param DateTimeImmutable $now   Current time (UTC).
	 * @param int               $limit Maximum number of files to delete.
	 * @return array{removed: int, skipped: int, more: bool}
	 */
	public function run( DateTimeImmutable $now, int $limit ): array {
		$days    = max( 0, (int) $this->settings->get( 'registration_file_retention_days' ) );
		$cutoff  = $now->modify( '-' . $days . ' days' );
		$removed = 0;
		$more    = false;
		foreach ( $this->files->owners( PrivateFiles::CONTEXT_REGISTRATION ) as $owner ) {
			$gone    = false === get_userdata( $owner );
			$decided = $gone ? null : self::decided_at( $owner );
			if ( ! $gone && ( 0 === $days || null === $decided || $decided > $cutoff ) ) {
				continue;
			}
			foreach ( $this->files->for_owner( $owner, PrivateFiles::CONTEXT_REGISTRATION ) as $file ) {
				if ( $removed >= $limit ) {
					$more = true;
					break 2;
				}
				$this->files->delete( $file['id'] );
				if ( ! $gone ) {
					self::clear_value( $owner, $file['field_key'], $file['id'] );
				}
				++$removed;
			}
		}
		if ( ! $more && $removed < $limit ) {
			$removed += $this->files->sweep_orphans( $limit - $removed );
		}
		return array(
			'removed' => $removed,
			'skipped' => 0,
			'more'    => $more,
		);
	}

	/**
	 * When the application was approved or rejected (the later of both), or null while it is open.
	 *
	 * @param int $user_id User.
	 */
	private static function decided_at( int $user_id ): ?DateTimeImmutable {
		$latest = null;
		foreach ( array( CustomersModule::META_APPROVED_AT, CustomersModule::META_REJECTED_AT ) as $key ) {
			$value = (string) get_user_meta( $user_id, $key, true );
			$time  = '' !== $value ? DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $value, new DateTimeZone( 'UTC' ) ) : false;
			if ( false !== $time && ( null === $latest || $time > $latest ) ) {
				$latest = $time;
			}
		}
		return $latest;
	}

	/**
	 * Removes the field value that pointed to a deleted file.
	 *
	 * @param int    $user_id   User.
	 * @param string $field_key Field key.
	 * @param int    $file_id   File id.
	 */
	private static function clear_value( int $user_id, string $field_key, int $file_id ): void {
		$meta_key = FieldDefinition::META_PREFIX . $field_key;
		if ( (string) get_user_meta( $user_id, $meta_key, true ) === (string) $file_id ) {
			delete_user_meta( $user_id, $meta_key );
		}
	}
}
