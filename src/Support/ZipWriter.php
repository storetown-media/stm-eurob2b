<?php
/**
 * Minimal ZIP archive writer.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Support;

use DateTimeInterface;
use RuntimeException;

/**
 * Writes a ZIP archive (PKWARE APPNOTE 6.3, "stored" entries without compression, UTF-8 names) to a
 * file, entry by entry, so only one entry is in memory at a time. Plain PHP without the zip
 * extension, which not every host has. PDFs are compressed already, so storing them costs little.
 *
 * Limits of the classic format, refused with an exception instead of writing a broken archive:
 * at most 65,535 entries and 4 GB in total (no ZIP64).
 */
final class ZipWriter {

	private const MAX_ENTRIES = 65535;
	private const MAX_BYTES   = 4294967295;

	/**
	 * Open file.
	 *
	 * @var resource|null
	 */
	private $handle;

	/**
	 * Central directory records written by close().
	 *
	 * @var string[]
	 */
	private array $central = array();

	/**
	 * Names used so far.
	 *
	 * @var array<string, true>
	 */
	private array $names = array();

	/**
	 * Bytes written so far.
	 *
	 * @var int
	 */
	private int $offset = 0;

	/**
	 * DOS time and date of the entries.
	 *
	 * @var array{0: int, 1: int}
	 */
	private array $dos_time;

	/**
	 * Opens the archive file (created or truncated).
	 *
	 * @param string            $path File.
	 * @param DateTimeInterface $time Modification time of the entries (as given, no zone conversion).
	 * @throws RuntimeException When the file cannot be opened.
	 */
	public function __construct( string $path, DateTimeInterface $time ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streams the archive to a private temporary file.
		$handle = fopen( $path, 'wb' );
		if ( false === $handle ) {
			throw new RuntimeException( 'The archive file could not be opened.' );
		}
		$this->handle   = $handle;
		$this->dos_time = self::dos_time( $time );
	}

	/**
	 * Adds a file.
	 *
	 * @param string $name Name inside the archive (forward slashes, no leading slash, no `..`).
	 * @param string $data Content.
	 * @throws RuntimeException When the name is not usable, it is used twice, a limit is reached or writing fails.
	 */
	public function add( string $name, string $data ): void {
		if ( null === $this->handle ) {
			throw new RuntimeException( 'The archive is closed.' );
		}
		if ( '' === $name || str_starts_with( $name, '/' ) || str_contains( $name, '\\' ) || in_array( '..', explode( '/', $name ), true ) || strlen( $name ) > 0xFFFF ) {
			throw new RuntimeException( 'The entry name is not usable.' );
		}
		if ( isset( $this->names[ $name ] ) ) {
			throw new RuntimeException( 'The entry name is used twice.' );
		}
		$size = strlen( $data );
		if ( count( $this->central ) >= self::MAX_ENTRIES || $this->offset + 30 + strlen( $name ) + $size > self::MAX_BYTES ) {
			throw new RuntimeException( 'The archive is too large.' );
		}
		$crc   = (int) hexdec( hash( 'crc32b', $data ) );
		$flags = 0x0800;
		// Bit 11: the name is UTF-8.
		[ $time, $date ] = $this->dos_time;
		$local           = pack( 'VvvvvvVVVvv', 0x04034b50, 20, $flags, 0, $time, $date, $crc, $size, $size, strlen( $name ), 0 ) . $name;
		$this->write( $local );
		$this->write( $data );
		$this->central[]      = pack( 'VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, $flags, 0, $time, $date, $crc, $size, $size, strlen( $name ), 0, 0, 0, 0, 0, $this->offset ) . $name;
		$this->names[ $name ] = true;
		$this->offset        += strlen( $local ) + $size;
	}

	/**
	 * Number of entries so far.
	 */
	public function count(): int {
		return count( $this->central );
	}

	/**
	 * Writes the central directory and closes the file.
	 *
	 * @throws RuntimeException When writing fails.
	 */
	public function close(): void {
		if ( null === $this->handle ) {
			return;
		}
		$directory = implode( '', $this->central );
		$this->write( $directory );
		$this->write( pack( 'VvvvvVVv', 0x06054b50, 0, 0, count( $this->central ), count( $this->central ), strlen( $directory ), $this->offset, 0 ) );
		fclose( $this->handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see the constructor.
		$this->handle = null;
	}

	/**
	 * Closes the file without finishing it (after a failure; the caller deletes the file).
	 */
	public function abort(): void {
		if ( null !== $this->handle ) {
			fclose( $this->handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see the constructor.
			$this->handle = null;
		}
	}

	/**
	 * Writes bytes.
	 *
	 * @param string $bytes Bytes.
	 * @throws RuntimeException When writing fails.
	 */
	private function write( string $bytes ): void {
		if ( '' === $bytes || null === $this->handle ) {
			return;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- see the constructor.
		if ( fwrite( $this->handle, $bytes ) !== strlen( $bytes ) ) {
			throw new RuntimeException( 'The archive could not be written.' );
		}
	}

	/**
	 * MS-DOS time and date fields (2-second resolution, years 1980 to 2107).
	 *
	 * @param DateTimeInterface $time Time.
	 * @return array{0: int, 1: int}
	 */
	private static function dos_time( DateTimeInterface $time ): array {
		$year = min( 2107, max( 1980, (int) $time->format( 'Y' ) ) );
		return array(
			( (int) $time->format( 'G' ) << 11 ) | ( (int) $time->format( 'i' ) << 5 ) | intdiv( (int) $time->format( 's' ), 2 ),
			( ( $year - 1980 ) << 9 ) | ( (int) $time->format( 'n' ) << 5 ) | (int) $time->format( 'j' ),
		);
	}
}
