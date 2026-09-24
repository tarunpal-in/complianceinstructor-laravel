<?php

declare(strict_types=1);

namespace App\Filament\Resources\Inquiries\Pages;

use App\Actions\Inquiries\RecordInquiry;
use App\Enums\InquirySource;
use App\Filament\Resources\Inquiries\InquiryResource;
use App\Models\Inquiry;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateInquiry extends CreateRecord
{
    protected static string $resource = InquiryResource::class;

    protected static ?string $title = 'Add an inquiry';

    /**
     * Through the action, not straight to the model.
     *
     * This is the only intake that exists today; the quiz, the gated download
     * and the inquiry form all arrive later. Routing even this one through
     * RecordInquiry is what stops the fourth intake being written by copying a
     * Filament page and quietly getting the rules slightly different — §14 #9's
     * failure, in a smaller corner.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $source = InquirySource::from((string) ($data['source'] ?? InquirySource::Manual->value));

        $inquiry = app(RecordInquiry::class)(
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
         * adding an inquiry from a call they have just taken can fill them in on
         * the same screen, so they are applied after.
         */
        $inquiry->fill(array_filter([
            'status' => $data['status'] ?? null,
            'assigned_to' => $data['assigned_to'] ?? null,
            'contacted_at' => $data['contacted_at'] ?? null,
            'note' => $data['note'] ?? null,
        ], fn (mixed $value): bool => $value !== null && $value !== ''))->save();

        return $inquiry;
    }

    protected function getRedirectUrl(): string
    {
        /** @var Inquiry $record */
        $record = $this->getRecord();

        return InquiryResource::getUrl('view', ['record' => $record]);
    }

    /** @param  array<string, mixed>  $data */
    private static function optional(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
