<?php

namespace CarlJanzell\FilamentPageBuilder\Support;

use CarlJanzell\FilamentPageBuilder\BlockRegistry;
use Filament\Forms\Components\RichEditor\RichContentRenderer;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

/**
 * Reconciles Filament's in-form state with the shape stored in the database.
 *
 * Two fields differ between editing and rest, and both will crash a renderer that
 * assumes the stored shape:
 *
 *  - RichEditor holds a TipTap document array while editing but dehydrates to an HTML
 *    string on save. Editors can also sit inside repeaters, so the walk is recursive.
 *  - FileUpload always holds an array (Arr::wrap'd path, or uuid => TemporaryUploadedFile)
 *    while the stored column is a plain path string.
 *
 * Uploads are resolved by declared field name rather than by shape, because a map of
 * strings is indistinguishable from a repeater item.
 */
class BlockStateNormaliser
{
    public function __construct(protected BlockRegistry $registry) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function normalise(mixed $blocks): array
    {
        if (! is_array($blocks)) {
            return [];
        }

        return collect($blocks)
            ->values()
            ->filter(fn (mixed $block): bool => is_array($block) && isset($block['type']))
            ->map(fn (array $block): array => [
                'id' => $block['id'] ?? null,
                'type' => $block['type'],
                'data' => $this->normaliseData(
                    $block['data'] ?? [],
                    $this->registry->fileFields($block['type']),
                ),
            ])
            ->all();
    }

    /**
     * @param  array<int, string>  $fileFields
     * @return array<string, mixed>
     */
    public function normaliseData(mixed $data, array $fileFields = []): array
    {
        if (! is_array($data)) {
            return [];
        }

        return collect($data)
            ->map(function (mixed $value, mixed $key) use ($fileFields): mixed {
                if (in_array($key, $fileFields, true)) {
                    return $this->resolveFileState($value);
                }

                return match (true) {
                    $this->isRichTextDocument($value) => RichContentRenderer::make($value)->toHtml(),
                    is_array($value) => $this->normaliseData($value, $fileFields),
                    default => $value,
                };
            })
            ->all();
    }

    /**
     * A file mid-upload has no stored path yet, so it is shown from Livewire's temporary URL.
     */
    public function resolveFileState(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }

        foreach (is_array($value) ? $value : [$value] as $file) {
            if ($file instanceof TemporaryUploadedFile) {
                try {
                    return $file->temporaryUrl();
                } catch (Throwable) {
                    return null;
                }
            }

            if (is_string($file) && filled($file)) {
                return $file;
            }
        }

        return null;
    }

    protected function isRichTextDocument(mixed $value): bool
    {
        return is_array($value) && ($value['type'] ?? null) === 'doc';
    }
}
