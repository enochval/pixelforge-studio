<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The lifecycle of a generation, and the rules governing movement through it.
 *
 * Each status carries a rank. Webhook delivery is unordered, so an event
 * describing an earlier stage can arrive after one describing a later stage —
 * a `processing` notification landing after `succeeded`, for example. Comparing
 * ranks turns that into a lookup: a transition is applied only when it moves
 * the generation forward, and anything else is recorded and ignored.
 *
 * Succeeded and failed share a rank because they are alternative endings, not
 * successive stages. Neither can follow the other.
 */
enum GenerationStatus: string
{
    /** Persisted locally, not yet accepted by the provider. */
    case Creating = 'creating';

    case Queued = 'queued';

    case Processing = 'processing';

    case Succeeded = 'succeeded';

    case Failed = 'failed';

    public function rank(): int
    {
        return match ($this) {
            self::Creating => 0,
            self::Queued => 1,
            self::Processing => 2,
            self::Succeeded, self::Failed => 3,
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Succeeded || $this === self::Failed;
    }

    /**
     * Whether an event describing $next should be applied to a generation
     * currently at this status.
     *
     * Stages may be skipped: a generation can move straight from queued to
     * succeeded if the intermediate notification was lost or arrives later.
     * A terminal status is final, so an outcome is never revised.
     */
    public function canTransitionTo(self $next): bool
    {
        if ($this->isTerminal()) {
            return false;
        }

        return $next->rank() > $this->rank();
    }

    /**
     * Statuses still awaiting an outcome. Used by the reconciler, and by the
     * partial index supporting it.
     *
     * @return array<int, self>
     */
    public static function nonTerminal(): array
    {
        return [self::Creating, self::Queued, self::Processing];
    }
}
