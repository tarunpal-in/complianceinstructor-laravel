<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Media;
use App\Models\MediaFolder;
use Database\Seeders\Support\SamplePdf;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * @extends Factory<Media>
 *
 * Writes a real file as well as the row.
 *
 * A media row with nothing behind it passes every test that only reads the
 * database and fails the moment something asks for a size, a thumbnail or a
 * stream — which is most of what a media manager does.
 */
class MediaFactory extends Factory
{
    protected $model = Media::class;

    /** A 1×1 transparent PNG — the smallest thing that is genuinely an image. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    public function definition(): array
    {
        $name = Str::title($this->faker->unique()->words(2, true));

        return [
            'model_type' => MediaFolder::class,
            'model_id' => MediaFolder::factory(),
            'collection_name' => Media::LIBRARY_COLLECTION,
            'name' => $name,
            'file_name' => Str::slug($name).'.png',
            'mime_type' => 'image/png',
            'disk' => config('media-library.disk_name'),
            'conversions_disk' => config('media-library.disk_name'),
            'size' => 68,
            'hash' => hash('sha256', Str::random(32)),
            'manipulations' => [],
            'custom_properties' => ['alt' => '', 'caption' => ''],
            'generated_conversions' => [],
            'responsive_images' => [],
            'order_column' => 1,
        ];
    }

    public function pdf(): self
    {
        return $this->state(fn (array $attributes): array => [
            'file_name' => Str::slug((string) ($attributes['name'] ?? 'document')).'.pdf',
            'mime_type' => 'application/pdf',
            'size' => strlen(SamplePdf::singlePage((string) ($attributes['name'] ?? 'Document'))),
        ]);
    }

    public function document(string $extension = 'docx'): self
    {
        return $this->state(fn (array $attributes): array => [
            'file_name' => Str::slug((string) ($attributes['name'] ?? 'document')).'.'.$extension,
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'size' => 2048,
        ]);
    }

    /** Attached to a record rather than the library — the read-only kind of row. */
    public function attachedTo(object $model, string $collection): self
    {
        return $this->state(fn (): array => [
            'model_type' => $model::class,
            'model_id' => $model->getKey(),
            'collection_name' => $collection,
        ]);
    }

    public function withAlt(string $alt): self
    {
        return $this->state(fn (array $attributes): array => [
            'custom_properties' => [...(array) ($attributes['custom_properties'] ?? []), 'alt' => $alt],
        ]);
    }

    public function configure(): self
    {
        return $this->afterCreating(function (Media $media): void {
            $body = $media->mime_type === 'application/pdf'
                ? SamplePdf::singlePage((string) $media->name)
                : (string) base64_decode(self::PNG, true);

            Storage::disk($media->disk)->put(
                $media->id.'/'.$media->file_name,
                $body,
            );
        });
    }
}
