<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Icons\Heroicon;

/**
 * What a file is, for filtering and for choosing an icon.
 *
 * §4 rule 5 wants an enum wherever a status would otherwise be a loose string,
 * and §14 #12 bans comparing those strings by hand — the predecessor lost a
 * whole quota check to `'E_transcript'` against `'E_transcripts'`.
 *
 * Video is deliberately absent: recordings are the product, they are gigabytes
 * each, and §9 requires them behind signed entitlement checks rather than in a
 * browsable library. They stay on `webinar_assets`. See docs/media-manager.md §2.
 */
enum MediaKind: string
{
    case Image = 'image';
    case Pdf = 'pdf';
    case Document = 'document';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Image => 'Image',
            self::Pdf => 'PDF',
            self::Document => 'Document',
            self::Other => 'Other',
        };
    }

    public function icon(): Heroicon
    {
        return match ($this) {
            self::Image => Heroicon::OutlinedPhoto,
            self::Pdf => Heroicon::OutlinedDocumentText,
            self::Document => Heroicon::OutlinedDocument,
            self::Other => Heroicon::OutlinedPaperClip,
        };
    }

    /**
     * Whether a thumbnail can ever exist for this kind. A PDF's depends on the
     * server having a rasteriser (docs/media-manager.md §6.1); a document's
     * never can without a conversion service.
     */
    public function isPreviewable(): bool
    {
        return $this === self::Image || $this === self::Pdf;
    }

    /**
     * Whether text can be pulled out of it for search.
     */
    public function isSearchableDocument(): bool
    {
        return $this === self::Pdf;
    }

    /**
     * Mime first, extension only as a fallback.
     *
     * Browsers send `application/octet-stream` often enough that trusting the
     * mime alone would file half the client's uploads as Other.
     */
    public static function forMime(string $mime, string $extension = ''): self
    {
        $mime = strtolower(trim($mime));
        $extension = strtolower(trim($extension));

        if ($mime === 'application/pdf' || $extension === 'pdf') {
            return self::Pdf;
        }

        if (str_starts_with($mime, 'image/')) {
            return self::Image;
        }

        if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg'], true)) {
            return self::Image;
        }

        if (in_array($extension, self::documentExtensions(), true)) {
            return self::Document;
        }

        return self::Other;
    }

    /** @return array<int, string> */
    public static function documentExtensions(): array
    {
        return ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'csv', 'txt', 'rtf', 'odt', 'ods'];
    }

    /**
     * Everything the library accepts, as extensions.
     *
     * @return array<int, string>
     */
    public static function acceptedExtensions(): array
    {
        return [
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg',
            'pdf',
            ...self::documentExtensions(),
        ];
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
