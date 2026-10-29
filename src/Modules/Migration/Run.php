<?php
/**
 * State of an import run.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Migration;

/**
 * One run of the importer (MIG-001): a test run (`check`, writes nothing), an import (`import`,
 * journaled) or the undo of an import (`undo`). Kept in the option `eurob2b_migration_run` between
 * the batches; only one run exists at a time. The report holds counts per part and at most
 * MAX_MESSAGES messages, which name objects by id only.
 */
final class Run {

	public const MODE_CHECK  = 'check';
	public const MODE_IMPORT = 'import';
	public const MODE_UNDO   = 'undo';

	public const STATUS_RUNNING = 'running';
	public const STATUS_DONE    = 'done';
	public const STATUS_FAILED  = 'failed';

	public const LEVEL_INFO    = 'info';
	public const LEVEL_WARNING = 'warning';
	public const LEVEL_ERROR   = 'error';

	/**
	 * Messages kept per run; further ones are only counted.
	 */
	public const MAX_MESSAGES = 200;

	/**
	 * Count keys per part.
	 */
	public const COUNTS = array( 'new', 'changed', 'unchanged', 'skipped' );

	/**
	 * Run id (random).
	 *
	 * @var string
	 */
	public string $id;

	/**
	 * Source id.
	 *
	 * @var string
	 */
	public string $source;

	/**
	 * Mode.
	 *
	 * @var string
	 */
	public string $mode;

	/**
	 * Parts in import order.
	 *
	 * @var string[]
	 */
	public array $parts;

	/**
	 * Index of the current part.
	 *
	 * @var int
	 */
	public int $part = 0;

	/**
	 * Source items of the current part handled so far.
	 *
	 * @var int
	 */
	public int $offset = 0;

	/**
	 * Undo: journal rows below this id are still to be reverted (0 = start at the newest).
	 *
	 * @var int
	 */
	public int $cursor = 0;

	/**
	 * Status.
	 *
	 * @var string
	 */
	public string $status = self::STATUS_RUNNING;

	/**
	 * User who started the run (the actor of every change).
	 *
	 * @var int
	 */
	public int $user_id;

	/**
	 * Start (UTC).
	 *
	 * @var string
	 */
	public string $started_at;

	/**
	 * End (UTC) or ''.
	 *
	 * @var string
	 */
	public string $finished_at = '';

	/**
	 * Run of the import an undo reverts, or the test run an import follows.
	 *
	 * @var string
	 */
	public string $related = '';

	/**
	 * Targets resolved in this run: kind => source key => EuroB2B id or slug (negative ids stand for
	 * groups a test run would create).
	 *
	 * @var array<string, array<string, int|string>>
	 */
	public array $map = array();

	/**
	 * Counts per part.
	 *
	 * @var array<string, array<string, int>>
	 */
	public array $counts = array();

	/**
	 * Messages: part, level, text.
	 *
	 * @var array<int, array{part: string, level: string, text: string}>
	 */
	public array $messages = array();

	/**
	 * Messages beyond MAX_MESSAGES.
	 *
	 * @var int
	 */
	public int $more_messages = 0;

	/**
	 * Constructor.
	 *
	 * @param string   $id      Run id.
	 * @param string   $source  Source id.
	 * @param string   $mode    Mode.
	 * @param string[] $parts   Parts.
	 * @param int      $user_id Actor.
	 */
	public function __construct( string $id, string $source, string $mode, array $parts, int $user_id ) {
		$this->id         = $id;
		$this->source     = $source;
		$this->mode       = $mode;
		$this->parts      = array_values( $parts );
		$this->user_id    = $user_id;
		$this->started_at = gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * The part being worked on ('' when every part is done).
	 */
	public function current_part(): string {
		return $this->parts[ $this->part ] ?? '';
	}

	/**
	 * Whether the run still has work.
	 */
	public function running(): bool {
		return self::STATUS_RUNNING === $this->status;
	}

	/**
	 * Adds to a count of the current part.
	 *
	 * @param string $key   One of COUNTS.
	 * @param int    $value Amount.
	 */
	public function count( string $key, int $value = 1 ): void {
		$part = $this->current_part();
		if ( ! isset( $this->counts[ $part ] ) ) {
			$this->counts[ $part ] = array_fill_keys( self::COUNTS, 0 );
		}
		$this->counts[ $part ][ $key ] = ( $this->counts[ $part ][ $key ] ?? 0 ) + $value;
	}

	/**
	 * Adds a message to the report.
	 *
	 * @param string      $level LEVEL_ constant.
	 * @param string      $text  Text (ids only, no names).
	 * @param string|null $part  Part it belongs to; null for the current part, '' for the whole run.
	 */
	public function message( string $level, string $text, ?string $part = null ): void {
		if ( count( $this->messages ) >= self::MAX_MESSAGES ) {
			++$this->more_messages;
			return;
		}
		$this->messages[] = array(
			'part'  => $part ?? $this->current_part(),
			'level' => $level,
			'text'  => $text,
		);
	}

	/**
	 * Remembers a resolved target.
	 *
	 * @param string     $kind   Kind (group, registration_type, field, rule).
	 * @param string     $key    Source key.
	 * @param int|string $target EuroB2B id or slug.
	 */
	public function remember( string $kind, string $key, $target ): void {
		$this->map[ $kind ][ $key ] = $target;
	}

	/**
	 * A target resolved in this run, or null.
	 *
	 * @param string $kind Kind.
	 * @param string $key  Source key.
	 * @return int|string|null
	 */
	public function target( string $kind, string $key ) {
		return $this->map[ $kind ][ $key ] ?? null;
	}

	/**
	 * Whether the report has an error message.
	 */
	public function has_errors(): bool {
		foreach ( $this->messages as $message ) {
			if ( self::LEVEL_ERROR === $message['level'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Sum of a count over all parts.
	 *
	 * @param string $key One of COUNTS.
	 */
	public function total( string $key ): int {
		$sum = 0;
		foreach ( $this->counts as $counts ) {
			$sum += (int) ( $counts[ $key ] ?? 0 );
		}
		return $sum;
	}

	/**
	 * Stored form.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return get_object_vars( $this );
	}

	/**
	 * From the stored form; null when it is not a run.
	 *
	 * @param mixed $data Stored value.
	 */
	public static function from_array( $data ): ?self {
		if ( ! is_array( $data ) || ! isset( $data['id'], $data['source'], $data['mode'], $data['parts'] ) || ! is_array( $data['parts'] ) ) {
			return null;
		}
		$run = new self( (string) $data['id'], (string) $data['source'], (string) $data['mode'], array_map( 'strval', $data['parts'] ), (int) ( $data['user_id'] ?? 0 ) );
		foreach ( array( 'part', 'offset', 'cursor', 'more_messages' ) as $int ) {
			$run->$int = (int) ( $data[ $int ] ?? 0 );
		}
		foreach ( array( 'status', 'started_at', 'finished_at', 'related' ) as $string ) {
			if ( isset( $data[ $string ] ) ) {
				$run->$string = (string) $data[ $string ];
			}
		}
		foreach ( array( 'map', 'counts', 'messages' ) as $list ) {
			if ( isset( $data[ $list ] ) && is_array( $data[ $list ] ) ) {
				$run->$list = $data[ $list ];
			}
		}
		return $run;
	}
}
