<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Actions\Media\StoreLibraryMedia;
use App\Filament\Forms\Components\MediaPicker;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * The "Upload new" control that lives inside a MediaPicker modal.
 *
 * Its own small Livewire component rather than a method bolted onto every
 * Filament page that hosts a picker: the file is stored straight into the
 * library through StoreLibraryMedia (§4 rule 2 — Actions do), so hashing,
 * de-duplication and text extraction cannot be skipped.
 *
 * On success it announces the stored file on a browser event named for its
 * picker's state path, so with two pickers on one screen only the picker that
 * opened the uploader reacts — the others are left untouched.
 */
class LibraryQuickUpload extends Component
{
    use WithFileUploads;

    /** The browser-event name this picker listens on; set by the picker view. */
    public string $channel = '';

    /** @var TemporaryUploadedFile|null */
    public $file = null;

    /**
     * Fires the moment Livewire finishes receiving the chosen file, so there is
     * no second "save" step — pick a file and it lands in the library.
     */
    public function updatedFile(): void
    {
        // The picker shows images (and SVG logos), so the uploader accepts the
        // same set — a mimetypes check rather than the `image` rule, whose SVG
        // handling has shifted between Laravel releases and would block logos.
        $this->validate([
            'file' => [
                'required',
                'mimetypes:image/jpeg,image/png,image/gif,image/webp,image/avif,image/svg+xml',
                'max:'.(int) config('media.max_upload_kb'),
            ],
        ], [
            'file.mimetypes' => 'Choose an image (JPG, PNG, GIF, WebP, AVIF or SVG).',
        ]);

        if (! $this->file instanceof TemporaryUploadedFile) {
            return;
        }

        $stored = app(StoreLibraryMedia::class)($this->file);

        $this->reset('file');

        // A duplicate returns the file already in the library, which is exactly
        // what the picker should then select — so no special-casing here.
        $this->dispatch($this->channel, item: MediaPicker::row($stored->media));
    }

    public function render(): View
    {
        return view('livewire.library-quick-upload');
    }
}
