<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Media\StoreLibraryMedia;
use App\Models\MediaFolder;
use App\Models\Tag;
use Database\Seeders\Support\SamplePdf;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * A library with something in it — §5.9 makes seed data a deliverable, because
 * the seeded database is the QA and client-review environment.
 *
 * The folders are the ones a CEU business actually needs, so the client opens a
 * filing system rather than an empty screen and a decision to make.
 *
 * Files are generated rather than committed: a repository should not carry
 * binary fixtures, and these only need to be real enough to have a size, a mime
 * type and a thumbnail.
 */
class MediaLibrarySeeder extends Seeder
{
    public function run(): void
    {
        $folders = $this->folders();
        $this->tags();

        $store = app(StoreLibraryMedia::class);

        foreach ($this->sampleFiles() as $index => [$folderSlug, $fileName, $isPdf]) {
            $store(
                $isPdf
                    ? UploadedFile::fake()->createWithContent(
                        $fileName,
                        SamplePdf::singlePage(Str::before($fileName, '.')),
                    )
                    // Dimensions vary per file on purpose. Identical bytes would
                    // be deduplicated by StoreLibraryMedia — correctly — and the
                    // seeded library would come out three images short.
                    : UploadedFile::fake()->image($fileName, 640 + ($index * 40), 400 + ($index * 20)),
                $folders[$folderSlug],
            );
        }
    }

    /** @return array<string, MediaFolder> */
    private function folders(): array
    {
        $names = [
            'Uncategorised',
            'Brand',
            'Speakers',
            'Webinar banners',
            'Accreditation documents',
            'Course materials',
            'Marketing',
        ];

        $folders = [];

        foreach ($names as $index => $name) {
            $folders[Str::slug($name)] = MediaFolder::firstOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => $name, 'sort_order' => $index],
            );
        }

        return $folders;
    }

    private function tags(): void
    {
        foreach (['Logo', 'Accreditation', 'Handout', 'Social', '2026 campaign'] as $name) {
            Tag::findOrCreateNamed($name);
        }
    }

    /**
     * @return array<int, array{0: string, 1: string, 2: bool}>
     */
    private function sampleFiles(): array
    {
        return [
            ['brand', 'Compliance Instructor Logo.png', false],
            ['brand', 'Compliance Instructor Logo Dark.png', false],
            ['accreditation-documents', 'HRCI Credit Statement.pdf', true],
            ['accreditation-documents', 'SHRM Recertification Policy.pdf', true],
            ['course-materials', 'FMLA Compliance Handout.pdf', true],
            ['course-materials', 'I-9 Verification Checklist.pdf', true],
            ['marketing', 'Membership One Pager.pdf', true],
            ['webinar-banners', 'Wage And Hour Banner.png', false],
        ];
    }
}
