<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\CertificateFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * One CEU certificate — CLAUDE.md §5.8 and §8 improvement #4.
 *
 * Everything the document states is on the row, frozen at issue. The relations
 * below are for navigation only: `$certificate->webinar->title` is what the
 * webinar is called *today*, and `$certificate->webinar_title` is what the
 * certificate says. Anything rendering the document, verifying a serial or
 * answering an auditor uses the second.
 *
 * @property CarbonInterface $issued_at
 * @property CarbonInterface|null $revoked_at
 */
class Certificate extends Model
{
    /** @use HasFactory<CertificateFactory> */
    use HasFactory, LogsActivity;

    protected $fillable = [
        'user_id', 'webinar_id', 'accreditation_id', 'serial',
        'learner_name', 'webinar_title', 'accreditation_code', 'credits',
        'issued_at', 'issued_by', 'revoked_at', 'revoked_by', 'revoked_reason', 'pdf_path',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'revoked_at' => 'datetime',
            'credits' => 'decimal:2',
        ];
    }

    /**
     * §9 — an accrediting body may audit this years later.
     *
     * Everything the document asserts is logged, so "was this certificate ever
     * changed after it was issued" has an answer that is not somebody's word.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'serial', 'learner_name', 'webinar_title', 'accreditation_code',
                'credits', 'issued_at', 'revoked_at', 'revoked_reason', 'pdf_path',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('certificate');
    }

    public function getDescriptionForEvent(string $eventName): string
    {
        if ($eventName === 'created') {
            return 'Issued';
        }

        return $this->wasChanged('revoked_at') && $this->revoked_at !== null
            ? 'Revoked'
            : 'Updated';
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

    /** @return BelongsTo<Accreditation, $this> */
    public function accreditation(): BelongsTo
    {
        return $this->belongsTo(Accreditation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    /** @return BelongsTo<User, $this> */
    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    // Scopes ----------------------------------------------------------------------

    /**
     * Certificates that still stand.
     *
     * @param  Builder<Certificate>  $query
     */
    public function scopeValid(Builder $query): void
    {
        $query->whereNull('revoked_at');
    }

    /** @param  Builder<Certificate>  $query */
    public function scopeRevoked(Builder $query): void
    {
        $query->whereNotNull('revoked_at');
    }

    /**
     * Find one by the serial somebody typed.
     *
     * Upper-cased and trimmed, because it arrives off a printed page. §14 #12 —
     * a serial that verifies in capitals and fails in lower case is the same
     * class of bug as the predecessor's 'E_transcript'.
     *
     * @param  Builder<Certificate>  $query
     */
    public function scopeMatchingSerial(Builder $query, string $serial): void
    {
        $query->where('serial', strtoupper(trim($serial)));
    }

    // State -------------------------------------------------------------------------

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isValid(): bool
    {
        return ! $this->isRevoked();
    }

    public function describeState(): string
    {
        return $this->isRevoked() ? 'Revoked' : 'Valid';
    }

    public function stateColor(): string
    {
        return $this->isRevoked() ? 'danger' : 'success';
    }

    /** "2.00 HRCI credits" — the line the document is really about. */
    public function describeCredits(): string
    {
        return $this->credits.' '.$this->accreditation_code.' '.($this->isSingleCredit() ? 'credit' : 'credits');
    }

    private function isSingleCredit(): bool
    {
        return (float) $this->credits === 1.0;
    }

    /** Whether the PDF §8 asks for has been produced yet. */
    public function hasPdf(): bool
    {
        return filled($this->pdf_path);
    }
}
