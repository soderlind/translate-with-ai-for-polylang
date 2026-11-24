<?php
/**
 * Read-only snapshot of a translation job.
 *
 * @package TranslateWithAiForPolylang
 */

declare(strict_types=1);

namespace Soderlind\TranslateWithAiForPolylang\Translation;

/**
 * What is known about a translation draft's job, and what that means.
 */
final class JobState {

	public const PENDING    = 'pending';
	public const PROCESSING = 'processing';
	public const DONE       = 'done';
	public const FAILED     = 'failed';

	/**
	 * Seconds without a heartbeat after which an unfinished job is considered dead.
	 *
	 * A heartbeat is written before every AI request, so a live job is only this quiet
	 * if a single request hangs far beyond the request timeout.
	 */
	public const STALE_AFTER = 15 * 60;

	/**
	 * Constructor.
	 *
	 * @param string               $status        One of the status constants.
	 * @param array<string,string> $language      Target language ( code, name, locale ).
	 * @param string               $error         Error message of a failed job.
	 * @param int                  $updated       Unix time of the last status change or heartbeat, 0 if unknown.
	 * @param int|null             $batch_updated Current batch heartbeat when this job is waiting its turn.
	 * @param bool                 $batch_paused  Whether its batch is paused.
	 */
	public function __construct(
		public readonly string $status,
		public readonly array $language,
		public readonly string $error,
		public readonly int $updated,
		public readonly ?int $batch_updated = null,
		public readonly bool $batch_paused = false
	) {}

	/**
	 * Name of the target language, e.g. "Norsk bokmål".
	 *
	 * @return string
	 */
	public function language_name(): string {
		return (string) ( $this->language['name'] ?? '' );
	}

	/**
	 * Whether the job is waiting or being translated.
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		return self::PENDING === $this->status || self::PROCESSING === $this->status;
	}

	/**
	 * Whether this pending job is waiting for another translation in its batch.
	 *
	 * @return bool
	 */
	public function is_waiting(): bool {
		return self::PENDING === $this->status && null !== $this->batch_updated;
	}

	/**
	 * Whether an active job has had no heartbeat for so long that it is presumed dead.
	 *
	 * @param int|null $now Unix time to compare with. Defaults to now.
	 * @return bool
	 */
	public function is_stale( ?int $now = null ): bool {
		$updated = $this->batch_updated ?? $this->updated;
		return $this->is_active() && $updated > 0 && ( $now ?? time() ) - $updated > self::STALE_AFTER;
	}

	/**
	 * Whether the job has ended, successfully or not.
	 *
	 * @return bool
	 */
	public function is_finished(): bool {
		return self::DONE === $this->status || self::FAILED === $this->status;
	}

	/**
	 * Whether the job may be queued again: it failed, or it is stale.
	 *
	 * @return bool
	 */
	public function can_retry(): bool {
		return self::FAILED === $this->status || ( $this->is_waiting() && $this->batch_paused ) || $this->is_stale();
	}
}
