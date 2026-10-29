<?php
/**
 * Private file storage.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Privacy;

use EuroB2B\Infrastructure\Capabilities;
use EuroB2B\Infrastructure\Schema;
use RuntimeException;
use WC_Order;

/**
 * PRV-006 (D-09): files customers upload (first use: documents with the registration, REG-016)
 * are kept outside the public media library.
 *
 * - **Where**: the directory `eurob2b-private` in the uploads folder, or the absolute path in the
 *   constant `EUROB2B_PRIVATE_DIR` (recommended: outside the web root). The directory gets an
 *   `.htaccess` that denies every request (Apache) and an empty `index.php`; files get the
 *   permissions 0600. nginx ignores `.htaccess`, so the Site Health test `eurob2b_private_files`
 *   requests a probe file with the same permissions over HTTP and reports when it is delivered.
 * - **Names**: 32 random hex characters plus `.bin` on disk; the original name, the checked type
 *   and the size are kept in `eurob2b_files` (migration 10) with the owner and the purpose.
 * - **Types**: PDF, JPEG and PNG only, checked by content (`wp_check_filetype_and_ext()`, which
 *   reads the file) and by extension; at most the size the caller allows.
 * - **Download**: `admin-post.php?action=eurob2b_file_download&file=<id>` with a nonce per file,
 *   for shop staff with the capability of the file's purpose (registration documents:
 *   `eurob2b_manage_customers`; proof of supply documents, TAX-030: `eurob2b_decide_validation`;
 *   purchase order documents, PAY-015: `edit_shop_orders` or the order's own logged-in customer),
 *   always as an attachment with `X-Content-Type-Options: nosniff`.
 * - **Deletion**: per file, per owner (erasure, user deletion) and the orphan sweep of the
 *   retention job (rows without a file, files without a row older than a day).
 */
final class PrivateFiles {

	/**
	 * Admin-post action of the download.
	 */
	public const ACTION_DOWNLOAD = 'eurob2b_file_download';

	/**
	 * Purpose: documents uploaded with the registration (REG-016).
	 */
	public const CONTEXT_REGISTRATION = 'registration';

	/**
	 * Purpose: evidence documents of the proof of intra-Community supply (TAX-030). The owner is 0 (the
	 * shop) and the field key the order id, so deleting or erasing a customer account keeps them.
	 */
	public const CONTEXT_PROOF = 'proof_of_supply';

	/**
	 * Purpose: documents of the export evidence (TAX-032, D-29). Owner 0 (the shop), field key the
	 * order id, like the proof of supply.
	 */
	public const CONTEXT_EXPORT = 'export_evidence';

	/**
	 * Purpose: the customer's purchase order document (PAY-015). Owner 0 (the shop), field key the
	 * order id: the document stays with the order when the customer account is deleted.
	 */
	public const CONTEXT_PURCHASE_ORDER = 'purchase_order';

	/**
	 * Purpose: a purchase order document uploaded at the classic checkout before the order exists;
	 * the field key is a random token kept in the visitor's session (PAY-015).
	 */
	public const CONTEXT_PURCHASE_ORDER_PENDING = 'purchase_order_pending';

	/**
	 * Accepted types: extension => MIME type.
	 */
	public const TYPES = array(
		'pdf'  => 'application/pdf',
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'png'  => 'image/png',
	);

	/**
	 * Site Health test id.
	 */
	public const TEST = 'eurob2b_private_files';

	/**
	 * How check_upload() asks PHP whether a file came with this request (`is_uploaded_file()`);
	 * integration tests replace it, because they cannot send a real upload.
	 *
	 * @var callable|null
	 */
	public static $is_uploaded = null;

	/**
	 * Hooks.
	 */
	public function hooks(): void {
		add_action( 'admin_post_' . self::ACTION_DOWNLOAD, array( $this, 'handle_download' ) );
		add_filter( 'site_status_tests', array( $this, 'register_test' ) );
		add_action( 'deleted_user', array( $this, 'on_deleted_user' ) );
	}

	/**
	 * Table name.
	 */
	private static function table(): string {
		return Schema::table( 'eurob2b_files' );
	}

	/**
	 * The private directory (no trailing slash).
	 */
	public static function dir(): string {
		if ( defined( 'EUROB2B_PRIVATE_DIR' ) && is_string( EUROB2B_PRIVATE_DIR ) && '' !== EUROB2B_PRIVATE_DIR ) {
			return untrailingslashit( EUROB2B_PRIVATE_DIR );
		}
		$uploads = wp_upload_dir( null, false );
		return untrailingslashit( (string) $uploads['basedir'] ) . '/eurob2b-private';
	}

	/**
	 * Public URL of the directory when it lies inside the uploads folder or inside the WordPress
	 * folder (for the reachability test), else ''.
	 */
	public static function dir_url(): string {
		$uploads = wp_upload_dir( null, false );
		$base    = untrailingslashit( (string) $uploads['basedir'] );
		$dir     = self::dir();
		if ( str_starts_with( $dir, $base . '/' ) ) {
			return untrailingslashit( (string) $uploads['baseurl'] ) . substr( $dir, strlen( $base ) );
		}
		$root = untrailingslashit( ABSPATH );
		if ( str_starts_with( $dir, $root . '/' ) ) {
			return untrailingslashit( site_url() ) . substr( $dir, strlen( $root ) );
		}
		return '';
	}

	/**
	 * Whether the directory lies outside the WordPress folder (resolved paths).
	 */
	private static function outside_wordpress(): bool {
		$dir  = realpath( self::dir() );
		$root = realpath( ABSPATH );
		return false !== $dir && false !== $root && ! str_starts_with( trailingslashit( $dir ), trailingslashit( $root ) );
	}

	/**
	 * Creates the directory with its protection files.
	 *
	 * @throws RuntimeException When it cannot be created.
	 */
	public static function ensure_dir(): string {
		$dir = self::dir();
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			throw new RuntimeException( 'The private directory could not be created.' );
		}
		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a small static file next to the uploads.
			file_put_contents( $htaccess, "# EuroB2B private files: no direct access.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tDeny from all\n</IfModule>\n" );
		}
		$index = $dir . '/index.php';
		if ( ! file_exists( $index ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- see above.
			file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}
		return $dir;
	}

	/**
	 * Checks an entry of `$_FILES` for the upload rules: a real upload without error, at most
	 * $max_bytes, PDF, JPEG or PNG by content and extension. Returns the checked file or an error code
	 * (`missing`, `failed`, `size`, `type`).
	 *
	 * @param mixed $file      Entry of $_FILES.
	 * @param int   $max_bytes Size limit in bytes.
	 * @return array{tmp: string, name: string, mime: string, size: int}|string
	 */
	public static function check_upload( $file, int $max_bytes ) {
		if ( ! is_array( $file ) || ! isset( $file['error'], $file['tmp_name'], $file['name'], $file['size'] ) || is_array( $file['error'] ) ) {
			return 'missing';
		}
		$error = (int) $file['error'];
		if ( UPLOAD_ERR_NO_FILE === $error ) {
			return 'missing';
		}
		if ( UPLOAD_ERR_INI_SIZE === $error || UPLOAD_ERR_FORM_SIZE === $error ) {
			return 'size';
		}
		$is_uploaded = self::$is_uploaded ?? 'is_uploaded_file';
		if ( UPLOAD_ERR_OK !== $error || ! $is_uploaded( (string) $file['tmp_name'] ) ) {
			return 'failed';
		}
		return self::check_file( (string) $file['tmp_name'], (string) $file['name'], $max_bytes );
	}

	/**
	 * The type and size part of check_upload() for a file on disk.
	 *
	 * @param string $path      File.
	 * @param string $name      Original name.
	 * @param int    $max_bytes Size limit in bytes.
	 * @return array{tmp: string, name: string, mime: string, size: int}|string
	 */
	public static function check_file( string $path, string $name, int $max_bytes ) {
		$size = is_file( $path ) ? (int) filesize( $path ) : 0;
		if ( $size <= 0 ) {
			return 'failed';
		}
		if ( $size > $max_bytes ) {
			return 'size';
		}
		$name  = sanitize_file_name( wp_basename( $name ) );
		$check = wp_check_filetype_and_ext( $path, $name, self::TYPES );
		$ext   = strtolower( (string) ( $check['ext'] ?? '' ) );
		$mime  = (string) ( $check['type'] ?? '' );
		if ( ! isset( self::TYPES[ $ext ] ) || self::TYPES[ $ext ] !== $mime || ! self::content_matches( $path, $mime ) ) {
			return 'type';
		}
		if ( '' !== (string) ( $check['proper_filename'] ?? '' ) ) {
			$name = (string) $check['proper_filename'];
		}
		return array(
			'tmp'  => $path,
			'name' => mb_substr( $name, 0, 190 ),
			'mime' => $mime,
			'size' => $size,
		);
	}

	/**
	 * The first bytes of the file match its type (PDF, JPEG or PNG signature), so a renamed file of
	 * another kind is refused even where the fileinfo extension is missing.
	 *
	 * @param string $path File.
	 * @param string $mime Type.
	 */
	private static function content_matches( string $path, string $mime ): bool {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads 8 bytes of a local file.
		$head = (string) file_get_contents( $path, false, null, 0, 8 );
		switch ( $mime ) {
			case 'application/pdf':
				return str_starts_with( $head, '%PDF-' );
			case 'image/png':
				return str_starts_with( $head, "\x89PNG\r\n\x1a\n" );
			case 'image/jpeg':
				return str_starts_with( $head, "\xFF\xD8\xFF" );
		}
		return false;
	}

	/**
	 * Moves a checked file into the private directory and records it.
	 *
	 * @param array{tmp: string, name: string, mime: string, size: int} $file      Result of check_upload() / check_file().
	 * @param int                                                       $owner_id  Owner (user id).
	 * @param string                                                    $context   Purpose.
	 * @param string                                                    $field_key Field it belongs to.
	 * @return int File id.
	 * @throws RuntimeException When the file cannot be stored.
	 */
	public function store( array $file, int $owner_id, string $context, string $field_key ): int {
		global $wpdb;
		$dir    = self::ensure_dir();
		$stored = bin2hex( random_bytes( 16 ) ) . '.bin';
		$target = $dir . '/' . $stored;
		// An upload is moved (PHP checks it is one); a file from elsewhere (tests, imports) is copied.
		$moved = is_uploaded_file( $file['tmp'] ) ? move_uploaded_file( $file['tmp'], $target ) : copy( $file['tmp'], $target );
		if ( ! $moved ) {
			throw new RuntimeException( 'The file could not be stored.' );
		}
		// Owner read/write only; not every host allows it, the directory protection stays either way.
		@chmod( $target, 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_chmod
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$ok = $wpdb->insert(
			self::table(),
			array(
				'owner_id'      => $owner_id,
				'context'       => mb_substr( $context, 0, 40 ),
				'field_key'     => mb_substr( $field_key, 0, 40 ),
				'original_name' => mb_substr( $file['name'], 0, 190 ),
				'mime'          => $file['mime'],
				'size'          => $file['size'],
				'stored_name'   => $stored,
				'created_at'    => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);
		if ( false === $ok ) {
			wp_delete_file( $target );
			throw new RuntimeException( 'The file could not be recorded.' );
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * One file record, or null.
	 *
	 * @param int $id File id.
	 * @return array{id: int, owner_id: int, context: string, field_key: string, original_name: string, mime: string, size: int, stored_name: string, created_at: string}|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return is_array( $row ) ? self::row( $row ) : null;
	}

	/**
	 * A file of an owner for a field and purpose, or null when the id names another file (a value
	 * entered before the field became a file field must never reach somebody else's document).
	 *
	 * @param int    $id        File id.
	 * @param int    $owner_id  Owner.
	 * @param string $context   Purpose.
	 * @param string $field_key Field.
	 * @return array{id: int, owner_id: int, context: string, field_key: string, original_name: string, mime: string, size: int, stored_name: string, created_at: string}|null
	 */
	public function find_owned( int $id, int $owner_id, string $context, string $field_key ): ?array {
		$file = $id > 0 && $owner_id > 0 ? $this->find( $id ) : null;
		if ( null === $file || $file['owner_id'] !== $owner_id || $file['context'] !== $context || $file['field_key'] !== $field_key ) {
			return null;
		}
		return $file;
	}

	/**
	 * Files of an owner, optionally of one purpose (oldest first).
	 *
	 * @param int         $owner_id Owner.
	 * @param string|null $context  Purpose or null for all.
	 * @return array<int, array{id: int, owner_id: int, context: string, field_key: string, original_name: string, mime: string, size: int, stored_name: string, created_at: string}>
	 */
	public function for_owner( int $owner_id, ?string $context = null ): array {
		global $wpdb;
		$table = self::table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = null === $context
			? $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE owner_id = %d ORDER BY id ASC", $owner_id ), ARRAY_A )
			: $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE owner_id = %d AND context = %s ORDER BY id ASC", $owner_id, $context ), ARRAY_A );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( array( self::class, 'row' ), (array) $rows );
	}

	/**
	 * Owners with files of a purpose (retention).
	 *
	 * @param string $context Purpose.
	 * @return int[]
	 */
	public function owners( string $context ): array {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT owner_id FROM {$table} WHERE context = %s ORDER BY owner_id ASC", $context ) ) );
	}

	/**
	 * Files of a purpose and field key (oldest first).
	 *
	 * @param string $context   Purpose.
	 * @param string $field_key Field key.
	 * @return array<int, array{id: int, owner_id: int, context: string, field_key: string, original_name: string, mime: string, size: int, stored_name: string, created_at: string}>
	 */
	public function for_key( string $context, string $field_key ): array {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE context = %s AND field_key = %s ORDER BY id ASC", $context, $field_key ), ARRAY_A );
		return array_map( array( self::class, 'row' ), (array) $rows );
	}

	/**
	 * Field keys with files of a purpose (retention).
	 *
	 * @param string $context Purpose.
	 * @return string[]
	 */
	public function field_keys( string $context ): array {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT field_key FROM {$table} WHERE context = %s ORDER BY field_key ASC", $context ) ) );
	}

	/**
	 * Gives a stored file another owner, purpose and field key (a pending checkout upload becomes the
	 * document of the order it was uploaded for). Returns whether the record was changed.
	 *
	 * @param int    $id        File id.
	 * @param int    $owner_id  New owner.
	 * @param string $context   New purpose.
	 * @param string $field_key New field key.
	 */
	public function reassign( int $id, int $owner_id, string $context, string $field_key ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			self::table(),
			array(
				'owner_id'  => $owner_id,
				'context'   => mb_substr( $context, 0, 40 ),
				'field_key' => mb_substr( $field_key, 0, 40 ),
			),
			array( 'id' => $id ),
			array( '%d', '%s', '%s' ),
			array( '%d' )
		);
		return is_int( $updated ) && $updated > 0;
	}

	/**
	 * Files of a purpose recorded before a time (UTC, `Y-m-d H:i:s`), oldest first, at most $limit.
	 *
	 * @param string $context Purpose.
	 * @param string $before  Time (UTC).
	 * @param int    $limit   At most this many.
	 * @return array<int, array{id: int, owner_id: int, context: string, field_key: string, original_name: string, mime: string, size: int, stored_name: string, created_at: string}>
	 */
	public function created_before( string $context, string $before, int $limit ): array {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE context = %s AND created_at < %s ORDER BY id ASC LIMIT %d", $context, $before, max( 1, $limit ) ), ARRAY_A );
		return array_map( array( self::class, 'row' ), (array) $rows );
	}

	/**
	 * Deletes a file and its record.
	 *
	 * @param int $id File id.
	 */
	public function delete( int $id ): void {
		global $wpdb;
		$file = $this->find( $id );
		if ( null !== $file && '' !== $file['stored_name'] ) {
			$path = self::dir() . '/' . $file['stored_name'];
			if ( is_file( $path ) ) {
				wp_delete_file( $path );
			}
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Deletes every file of an owner (erasure, user deletion). Returns the number of files.
	 *
	 * @param mixed $owner_id Owner.
	 */
	public function delete_owner( $owner_id ): int {
		$count = 0;
		foreach ( $this->for_owner( (int) $owner_id ) as $file ) {
			$this->delete( $file['id'] );
			++$count;
		}
		return $count;
	}

	/**
	 * A deleted account takes its files with it.
	 *
	 * @param mixed $user_id User.
	 */
	public function on_deleted_user( $user_id ): void {
		$this->delete_owner( (int) $user_id );
	}

	/**
	 * Removes records whose file is gone and files without a record that are older than a day (an
	 * upload interrupted between the move and the record). Returns the number removed.
	 *
	 * @param int $limit At most this many.
	 */
	public function sweep_orphans( int $limit ): int {
		global $wpdb;
		$removed = 0;
		$dir     = self::dir();
		$table   = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows  = (array) $wpdb->get_results( "SELECT id, stored_name FROM {$table}", ARRAY_A );
		$known = array();
		foreach ( $rows as $row ) {
			$known[ (string) $row['stored_name'] ] = true;
			if ( $removed < $limit && ! is_file( $dir . '/' . (string) $row['stored_name'] ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->delete( $table, array( 'id' => (int) $row['id'] ), array( '%d' ) );
				++$removed;
			}
		}
		$stored = is_dir( $dir ) ? glob( $dir . '/*.bin' ) : array();
		foreach ( is_array( $stored ) ? $stored : array() as $path ) {
			if ( $removed >= $limit ) {
				break;
			}
			if ( ! isset( $known[ wp_basename( $path ) ] ) && filemtime( $path ) < time() - DAY_IN_SECONDS ) {
				wp_delete_file( $path );
				++$removed;
			}
		}
		return $removed;
	}

	/**
	 * URL that lets shop staff download a file.
	 *
	 * @param int $id File id.
	 */
	public static function download_link( int $id ): string {
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION_DOWNLOAD . '&file=' . $id ), self::ACTION_DOWNLOAD . '_' . $id );
	}

	/**
	 * Capability needed to download a file of a purpose.
	 *
	 * @param string $context Purpose.
	 */
	public static function capability( string $context ): string {
		return match ( $context ) {
			self::CONTEXT_REGISTRATION => Capabilities::MANAGE_CUSTOMERS,
			self::CONTEXT_PROOF        => Capabilities::DECIDE_VALIDATION,
			self::CONTEXT_EXPORT       => Capabilities::DECIDE_VALIDATION,
			// WooCommerce's capability of the order screen: the document is part of the order.
			self::CONTEXT_PURCHASE_ORDER => 'edit_shop_orders',
			default                    => 'manage_options',
		};
	}

	/**
	 * Whether the current user may download a file: shop staff with the capability of its purpose; a
	 * purchase order document also its order's own logged-in customer (ownership check, PAY-015).
	 *
	 * @param array{context: string, field_key?: string} $file File record.
	 */
	public static function may_download( array $file ): bool {
		if ( current_user_can( self::capability( $file['context'] ) ) ) {
			return true;
		}
		if ( self::CONTEXT_PURCHASE_ORDER !== $file['context'] || get_current_user_id() <= 0 || ! function_exists( 'wc_get_order' ) ) {
			return false;
		}
		$order = wc_get_order( (int) ( $file['field_key'] ?? 0 ) );
		return $order instanceof WC_Order && (int) $order->get_customer_id() === get_current_user_id();
	}

	/**
	 * Download handler (admin-post): nonce per file, capability of the purpose, attachment only.
	 */
	public function handle_download(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified right below with the file id.
		$id = isset( $_GET['file'] ) ? absint( $_GET['file'] ) : 0;
		check_admin_referer( self::ACTION_DOWNLOAD . '_' . $id );
		$file = $this->find( $id );
		if ( null === $file || ! self::may_download( $file ) ) {
			wp_die( esc_html__( 'You are not allowed to download this file.', 'eurob2b' ), '', array( 'response' => 403 ) );
		}
		$path = self::dir() . '/' . $file['stored_name'];
		if ( '' === $file['stored_name'] || ! is_file( $path ) || ! in_array( $file['mime'], self::TYPES, true ) ) {
			wp_die( esc_html__( 'The file no longer exists.', 'eurob2b' ), '', array( 'response' => 404 ) );
		}
		if ( ! headers_sent() ) {
			nocache_headers();
			header( 'Content-Type: ' . $file['mime'] );
			header( 'Content-Length: ' . (string) filesize( $path ) );
			header( 'Content-Disposition: attachment; filename="' . str_replace( array( '"', "\r", "\n" ), '', sanitize_file_name( $file['original_name'] ) ) . '"; filename*=UTF-8\'\'' . rawurlencode( $file['original_name'] ) );
			header( 'X-Content-Type-Options: nosniff' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streams the checked file.
		readfile( $path );
		exit;
	}

	/**
	 * Adds the Site Health test.
	 *
	 * @param mixed $tests Tests.
	 * @return mixed
	 */
	public function register_test( $tests ) {
		if ( is_array( $tests ) ) {
			$tests['direct'][ self::TEST ] = array(
				'label' => __( 'Uploaded documents are not reachable from the web', 'eurob2b' ),
				'test'  => array( $this, 'site_health' ),
			);
		}
		return $tests;
	}

	/**
	 * Whether a probe file in the directory can be downloaded over HTTP: `reachable`, `blocked`,
	 * `outside` (the directory lies outside the WordPress folder) or `unknown` (no address to test, or
	 * no answer). The probe is removed again.
	 */
	public function reachability(): string {
		try {
			$dir = self::ensure_dir();
		} catch ( RuntimeException $e ) {
			return 'unknown';
		}
		$url = self::dir_url();
		if ( '' === $url ) {
			// Outside the uploads and WordPress folders: private only when it is outside WordPress.
			return self::outside_wordpress() ? 'outside' : 'unknown';
		}
		$name = 'probe-' . bin2hex( random_bytes( 8 ) ) . '.bin';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a probe of a few bytes.
		file_put_contents( $dir . '/' . $name, 'eurob2b-probe' );
		// With the permissions of a stored document: where the web server runs as another user, 0600
		// alone keeps the files from being delivered (seen with nginx), and the test must see that.
		@chmod( $dir . '/' . $name, 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_chmod
		$response = wp_remote_get(
			$url . '/' . $name,
			array(
				'timeout'     => 5,
				'redirection' => 0,
				'sslverify'   => false,
			)
		);
		wp_delete_file( $dir . '/' . $name );
		if ( is_wp_error( $response ) ) {
			return 'unknown';
		}
		return 200 === (int) wp_remote_retrieve_response_code( $response ) && 'eurob2b-probe' === wp_remote_retrieve_body( $response ) ? 'reachable' : 'blocked';
	}

	/**
	 * Site Health result.
	 *
	 * @return array<string, mixed>
	 */
	public function site_health(): array {
		$state = $this->reachability();
		$badge = array(
			'label' => __( 'Privacy', 'eurob2b' ),
			'color' => 'blue',
		);
		$where = '<p><code>' . esc_html( self::dir() ) . '</code></p>';
		if ( 'reachable' === $state ) {
			return array(
				'label'       => __( 'Uploaded documents can be downloaded from the web', 'eurob2b' ),
				'status'      => 'critical',
				'badge'       => $badge,
				'description' => '<p>' . esc_html__( 'The web server delivers files from the folder of uploaded documents to anyone who knows their address. The file names are random, but the folder must be closed. With nginx, add a rule that denies the folder, or move the folder outside the web root with the constant EUROB2B_PRIVATE_DIR in wp-config.php.', 'eurob2b' ) . '</p>' . $where,
				'test'        => self::TEST,
			);
		}
		if ( 'unknown' === $state ) {
			return array(
				'label'       => __( 'Whether uploaded documents are reachable from the web could not be checked', 'eurob2b' ),
				'status'      => 'recommended',
				'badge'       => $badge,
				'description' => '<p>' . esc_html__( 'The site could not request a test file from its own folder of uploaded documents. Make sure the web server denies the folder, or move it outside the web root with the constant EUROB2B_PRIVATE_DIR in wp-config.php.', 'eurob2b' ) . '</p>' . $where,
				'test'        => self::TEST,
			);
		}
		return array(
			'label'       => __( 'Uploaded documents are not reachable from the web', 'eurob2b' ),
			'status'      => 'good',
			'badge'       => $badge,
			'description' => '<p>' . esc_html( 'outside' === $state ? __( 'The folder of uploaded documents lies outside the WordPress folder.', 'eurob2b' ) : __( 'A test file in the folder of uploaded documents could not be downloaded from the web.', 'eurob2b' ) ) . '</p>' . $where,
			'test'        => self::TEST,
		);
	}

	/**
	 * Row to record.
	 *
	 * @param array<string, mixed> $row Table row.
	 * @return array{id: int, owner_id: int, context: string, field_key: string, original_name: string, mime: string, size: int, stored_name: string, created_at: string}
	 */
	private static function row( array $row ): array {
		return array(
			'id'            => (int) $row['id'],
			'owner_id'      => (int) $row['owner_id'],
			'context'       => (string) $row['context'],
			'field_key'     => (string) $row['field_key'],
			'original_name' => (string) $row['original_name'],
			'mime'          => (string) $row['mime'],
			'size'          => (int) $row['size'],
			'stored_name'   => (string) $row['stored_name'],
			'created_at'    => (string) $row['created_at'],
		);
	}
}
