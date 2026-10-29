<?php
/**
 * The journal of an import could not be written.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Migration;

use Exception;

/**
 * Thrown before an import changes a value whose previous state could not be journaled: the run
 * stops instead of writing something its undo could not put back. Not a RuntimeException, so the
 * writer's handlers for refused single values do not swallow it.
 */
final class JournalException extends Exception {
}
