<?php

declare(strict_types=1);

namespace App\Actions\Media;

use App\Models\Media;

/**
 * The outcome of one upload.
 *
 * `wasDuplicate` matters to the caller: a bulk upload that silently reuses six
 * existing files looks like it lost them. The list page counts these and says so.
 */
readonly class StoredMedia
{
    public function __construct(
        public Media $media,
        public bool $wasDuplicate,
    ) {}
}
