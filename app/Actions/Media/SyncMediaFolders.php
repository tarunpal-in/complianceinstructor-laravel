<?php

declare(strict_types=1);

namespace App\Actions\Media;

use App\Models\Media;
use App\Models\MediaFolder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Applies the folder list from the Manage folders modal.
 *
 * Deleting a folder never deletes what is in it. A client tidying up their
 * filing should not lose the files they were filing — the contents move to
 * Uncategorised and the delete guard still protects anything in use.
 */
class SyncMediaFolders
{
    /**
     * @param  array<int, array{id: int|string|null, name: string, parent_id: int|string|null}>  $rows
     * @return array{renamed: int, created: int, deleted: int, relocated: int}
     */
    public function __invoke(array $rows): array
    {
        $uncategorised = MediaFolder::uncategorised();

        $result = ['renamed' => 0, 'created' => 0, 'deleted' => 0, 'relocated' => 0];

        DB::transaction(function () use ($rows, $uncategorised, &$result): void {
            $kept = [$uncategorised->id];

            foreach (array_values($rows) as $position => $row) {
                $name = trim((string) $row['name']);

                if ($name === '') {
                    continue;
                }

                $folder = filled($row['id'] ?? null)
                    ? MediaFolder::find($row['id'])
                    : null;

                if ($folder === null) {
                    $folder = new MediaFolder;
                    $result['created']++;
                } elseif ($folder->name !== $name) {
                    $result['renamed']++;
                }

                $folder->fill([
                    'name' => $name,
                    'slug' => $this->uniqueSlug($name, $folder->id),
                    // A folder cannot be its own parent, and one level of nesting
                    // is all the UI offers, so a parent that is itself nested is
                    // flattened rather than silently accepted.
                    'parent_id' => $this->resolveParent($row['parent_id'] ?? null, $folder->id),
                    'sort_order' => $position,
                ])->save();

                $kept[] = $folder->id;
            }

            $removed = MediaFolder::query()->whereNotIn('id', $kept)->get();

            foreach ($removed as $folder) {
                // Contents move rather than vanish.
                $result['relocated'] += Media::query()
                    ->where('model_type', MediaFolder::class)
                    ->where('model_id', $folder->id)
                    ->update(['model_id' => $uncategorised->id]);

                $folder->delete();
                $result['deleted']++;
            }
        });

        return $result;
    }

    private function resolveParent(mixed $parentId, ?int $selfId): ?int
    {
        if (blank($parentId)) {
            return null;
        }

        $parentId = (int) $parentId;

        if ($selfId !== null && $parentId === $selfId) {
            return null;
        }

        $parent = MediaFolder::find($parentId);

        return $parent === null || $parent->parent_id !== null ? null : $parent->id;
    }

    private function uniqueSlug(string $name, ?int $ignoreId): string
    {
        $base = Str::slug($name) ?: 'folder';
        $slug = $base;
        $suffix = 2;

        while (MediaFolder::query()
            ->where('slug', $slug)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists()
        ) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
