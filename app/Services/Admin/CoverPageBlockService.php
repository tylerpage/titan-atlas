<?php

namespace App\Services\Admin;

use App\Enums\CoverPageBlockType;
use App\Models\CoverPage;
use App\Models\CoverPageBlock;
use App\Support\RichTextSanitizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class CoverPageBlockService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(CoverPage $coverPage, CoverPageBlockType $type, array $data = []): CoverPageBlock
    {
        $sortOrder = (int) ($coverPage->blocks()->max('sort_order') ?? 0) + 1;
        $configuration = $this->normalizeConfiguration($type, array_merge($type->defaultConfiguration(), $data['configuration'] ?? []));

        return CoverPageBlock::query()->create([
            'cover_page_id' => $coverPage->id,
            'block_type' => $type,
            'sort_order' => $sortOrder,
            'column_span' => (int) ($data['column_span'] ?? 1),
            'configuration' => $configuration,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(CoverPageBlock $block, array $data): CoverPageBlock
    {
        $configuration = array_merge(
            $block->configuration ?? $block->block_type->defaultConfiguration(),
            $data['configuration'] ?? [],
        );

        if (isset($configuration['series']) && is_string($configuration['series'])) {
            $decoded = json_decode($configuration['series'], true);
            $configuration['series'] = is_array($decoded) ? $decoded : [];
        }

        $configuration = $this->normalizeConfiguration($block->block_type, $configuration);

        $block->update([
            'column_span' => (int) ($data['column_span'] ?? $block->column_span),
            'configuration' => $configuration,
        ]);

        return $block->fresh();
    }

    public function delete(CoverPageBlock $block): void
    {
        $block->delete();
    }

    /**
     * @param  list<int>  $blockIds
     */
    public function reorder(CoverPage $coverPage, array $blockIds): void
    {
        DB::transaction(function () use ($coverPage, $blockIds) {
            foreach ($blockIds as $index => $blockId) {
                CoverPageBlock::query()
                    ->where('cover_page_id', $coverPage->id)
                    ->whereKey($blockId)
                    ->update(['sort_order' => $index + 1]);
            }
        });
    }

    public function moveUp(CoverPageBlock $block): void
    {
        $previous = CoverPageBlock::query()
            ->where('cover_page_id', $block->cover_page_id)
            ->where('sort_order', '<', $block->sort_order)
            ->orderByDesc('sort_order')
            ->first();

        if (! $previous) {
            return;
        }

        $this->swapSortOrder($block, $previous);
    }

    public function moveDown(CoverPageBlock $block): void
    {
        $next = CoverPageBlock::query()
            ->where('cover_page_id', $block->cover_page_id)
            ->where('sort_order', '>', $block->sort_order)
            ->orderBy('sort_order')
            ->first();

        if (! $next) {
            return;
        }

        $this->swapSortOrder($block, $next);
    }

    /**
     * @return array<string, mixed>
     */
    public function importCsv(CoverPageBlock $block, UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'r');

        if ($handle === false) {
            throw new \RuntimeException('Unable to read CSV file.');
        }

        $headers = fgetcsv($handle);

        if ($headers === false) {
            fclose($handle);

            throw new \RuntimeException('CSV file is empty.');
        }

        $headers = array_map(fn ($header) => trim((string) $header), $headers);
        $rows = [];

        while (($row = fgetcsv($handle)) !== false) {
            if (count(array_filter($row, fn ($value) => $value !== null && $value !== '')) === 0) {
                continue;
            }

            $entry = [];

            foreach ($headers as $index => $header) {
                $entry[$header] = $row[$index] ?? null;
            }

            $rows[] = $entry;
        }

        fclose($handle);

        $configuration = $block->configuration ?? $block->block_type->defaultConfiguration();

        if ($block->block_type === CoverPageBlockType::LineChart) {
            $dateKey = $headers[0] ?? 'date';
            $valueKey = $headers[1] ?? 'value';
            $configuration['series'] = collect($rows)->map(fn (array $row) => [
                'date' => (string) ($row[$dateKey] ?? ''),
                'value' => (float) ($row[$valueKey] ?? 0),
            ])->filter(fn (array $point) => $point['date'] !== '')->values()->all();
            $configuration['data_source'] = 'manual';
        } elseif ($block->block_type === CoverPageBlockType::Table) {
            $configuration['columns'] = collect($headers)->map(fn (string $header) => [
                'key' => $header,
                'label' => ucfirst(str_replace('_', ' ', $header)),
            ])->values()->all();
            $configuration['rows'] = $rows;
            $configuration['data_source'] = 'manual';
        } else {
            throw new \RuntimeException('CSV import is only supported for line charts and tables.');
        }

        $block->update(['configuration' => $configuration]);

        return $configuration;
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return array<string, mixed>
     */
    protected function normalizeConfiguration(CoverPageBlockType $type, array $configuration): array
    {
        if ($type === CoverPageBlockType::RichText && isset($configuration['body'])) {
            $configuration['body'] = RichTextSanitizer::clean((string) $configuration['body']);
        }

        if ($type === CoverPageBlockType::LineChart && isset($configuration['insights'])) {
            $configuration['insights'] = RichTextSanitizer::clean((string) $configuration['insights']);
        }

        return $configuration;
    }

    protected function swapSortOrder(CoverPageBlock $a, CoverPageBlock $b): void
    {
        DB::transaction(function () use ($a, $b) {
            $aOrder = $a->sort_order;
            $a->update(['sort_order' => $b->sort_order]);
            $b->update(['sort_order' => $aOrder]);
        });
    }
}
