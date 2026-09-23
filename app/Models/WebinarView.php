<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\CompletionRules;
use Carbon\CarbonInterface;
use Database\Factories\WebinarViewFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One learner's progress through one recording.
 *
 * @property CarbonInterface|null $completed_at
 * @property CarbonInterface|null $last_watched_at
 */
class WebinarView extends Model
{
    /** @use HasFactory<WebinarViewFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id', 'webinar_id', 'furthest_seconds', 'seconds_watched',
        'completed_at', 'last_watched_at',
    ];

    protected function casts(): array
    {
        return [
            'furthest_seconds' => 'integer',
            'seconds_watched' => 'integer',
            'completed_at' => 'datetime',
            'last_watched_at' => 'datetime',
        ];
    }

    // Relationships -------------------------------------------------------------

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Webinar, $this> */
    public function webinar(): BelongsTo
    {
        return $this->belongsTo(Webinar::class);
    }

    // Scopes ----------------------------------------------------------------------

    /** @param  Builder<WebinarView>  $query */
    public function scopeCompleted(Builder $query): void
    {
        $query->whereNotNull('completed_at');
    }

    // Progress ----------------------------------------------------------------------

    public function isComplete(): bool
    {
        return $this->completed_at !== null;
    }

    /**
     * Whether the furthest point reached satisfies the rule.
     *
     * Asked of the rule rather than stored as a condition here, so the admin,
     * the player and the certificate all agree about what "watched it" means.
     */
    public function qualifies(Webinar $webinar): bool
    {
        return CompletionRules::hasWatchedEnough($webinar, $this->furthest_seconds);
    }

    /**
     * How far through, as a percentage — for a progress bar, not for the rule.
     *
     * Capped at 100: a recording can run slightly longer than the duration the
     * admin entered, and "104% watched" reads as a fault.
     */
    public function percentOf(Webinar $webinar): int
    {
        $total = (int) $webinar->duration_minutes * 60;

        if ($total < 1) {
            return 0;
        }

        return (int) min(100, round($this->furthest_seconds / $total * 100));
    }

    /** "1h 12m of 1h 30m". */
    public function describeProgress(Webinar $webinar): string
    {
        return self::clock($this->furthest_seconds).' of '.self::clock((int) $webinar->duration_minutes * 60);
    }

    private static function clock(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return $hours > 0 ? $hours.'h '.$minutes.'m' : $minutes.'m';
    }
}
