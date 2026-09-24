<?php

declare(strict_types=1);

namespace App\Filament\Resources\Leads\Pages;

use App\Actions\Leads\RecordLead;
use App\Enums\LeadSource;
use App\Filament\Resources\Leads\LeadResource;
use App\Models\Lead;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateLead extends CreateRecord
{
    protected static string $resource = LeadResource::class;

    protected static ?string $title = 'Add a lead';

    /**
     * Through the action, not straight to the model.
     *
     * This is the only intake that exists today; the quiz, the gated download
     * and the enquiry form all arrive later. Routing even this one through
     * RecordLead is what stops the fourth intake being written by copying a
     * Filament page and quietly getting the rules slightly different — §14 #9's
     * failure, in a smaller corner.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $source = LeadSource::from((string) ($data['source'] ?? LeadSource::Manual->value));

        $lead = app(RecordLead::class)(
            name: (string) $data['name'],
            email: (string) $data['email'],
            source: $source,
            company: self::optional($data, 'company'),
            jobTitle: self::optional($data, 'job_title'),
            phone: self::optional($data, 'phone'),
            industryId: isset($data['industry_id']) ? (int) $data['industry_id'] : null,
            sourceDetail: self::optional($data, 'source_detail'),
            message: self::optional($data, 'message'),
            marketingOptIn: (bool) ($data['marketing_opt_in'] ?? false),
        );

        /*
         * The follow-up fields are not the action's business — it records an
         * arrival, and an arrival has no owner or call history yet. Somebody
         * adding a lead from a call they have just taken can fill them in on
         * the same screen, so they are applied after.
         */
        $lead->fill(array_filter([
            'status' => $data['status'] ?? null,
            'assigned_to' => $data['assigned_to'] ?? null,
            'contacted_at' => $data['contacted_at'] ?? null,
            'note' => $data['note'] ?? null,
        ], fn (mixed $value): bool => $value !== null && $value !== ''))->save();

        return $lead;
    }

    protected function getRedirectUrl(): string
    {
        /** @var Lead $record */
        $record = $this->getRecord();

        return LeadResource::getUrl('view', ['record' => $record]);
    }

    /** @param  array<string, mixed>  $data */
    private static function optional(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
