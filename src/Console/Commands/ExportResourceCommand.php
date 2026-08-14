<?php

namespace ParsDesign\Grow4MeDataConnector\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use ParsDesign\Grow4MeDataConnector\Contracts\ExportableResourceAdapter;
use ParsDesign\Grow4MeDataConnector\Resources\ResourceRegistry;
use ParsDesign\Grow4MeDataConnector\Services\EnvelopeFactory;
use ParsDesign\Grow4MeDataConnector\Support\CsvCellSanitizer;
use RuntimeException;
use SplFileObject;
use XMLWriter;

class ExportResourceCommand extends Command
{
    protected $signature = 'grow4me:export
        {resource : Configured resource key}
        {path? : Absolute or project-relative output path}
        {--format=csv : csv or xml}
        {--include-tombstones : Include inactive records returned by the adapter}';

    protected $description = 'Stream one configured Grow4Me resource to CSV or XML';

    public function handle(
        ResourceRegistry $resources,
        EnvelopeFactory $envelopes,
        CsvCellSanitizer $cells,
    ): int {
        $resource = $resources->get((string) $this->argument('resource'));

        if (! $resource instanceof ExportableResourceAdapter || $resource->exportColumns() === []) {
            $this->components->error("Resource [{$resource->key()}] does not define export columns.");

            return self::FAILURE;
        }

        $format = strtolower((string) $this->option('format'));

        if (! in_array($format, ['csv', 'xml'], true)) {
            $this->components->error('The export format must be csv or xml.');

            return self::INVALID;
        }

        $path = $this->outputPath($resource->key(), $format);
        $this->ensureDirectory(dirname($path));
        $count = $format === 'csv'
            ? $this->writeCsv($path, $resource, $envelopes, $cells)
            : $this->writeXml($path, $resource, $envelopes);

        $this->components->info("{$count} records exported to {$path}.");

        return self::SUCCESS;
    }

    private function writeCsv(
        string $path,
        ExportableResourceAdapter $resource,
        EnvelopeFactory $envelopes,
        CsvCellSanitizer $cells,
    ): int {
        $file = new SplFileObject($path, 'wb');
        $file->setCsvControl(',', '"', '');
        $file->fputcsv([
            'external_id',
            'active',
            'source_updated_at',
            ...array_values($resource->exportColumns()),
        ]);
        $count = 0;

        $this->eachPage($resource, function (EloquentCollection $models) use (
            $resource,
            $envelopes,
            $cells,
            $file,
            &$count,
        ): void {
            $pageEnvelopes = $envelopes->forModels($resource, $models);

            foreach ($models as $index => $model) {
                $envelope = $pageEnvelopes[$index];

                if (! $this->option('include-tombstones') && ! $envelope['active']) {
                    continue;
                }

                $row = $this->validatedExportRow($resource, $model);
                $file->fputcsv($cells->sanitizeRow([
                    $envelope['external_id'],
                    $envelope['active'] ? '1' : '0',
                    $envelope['source_updated_at'],
                    ...array_values($row),
                ]));
                $count++;
            }
        });

        return $count;
    }

    private function writeXml(
        string $path,
        ExportableResourceAdapter $resource,
        EnvelopeFactory $envelopes,
    ): int {
        $writer = new XMLWriter;

        if (! $writer->openUri($path)) {
            throw new RuntimeException("Export file [{$path}] could not be opened.");
        }

        $writer->startDocument('1.0', 'UTF-8');
        $writer->setIndent(true);
        $writer->startElement($resource->key());
        $count = 0;

        $this->eachPage($resource, function (EloquentCollection $models) use (
            $resource,
            $envelopes,
            $writer,
            &$count,
        ): void {
            $pageEnvelopes = $envelopes->forModels($resource, $models);

            foreach ($models as $index => $model) {
                $envelope = $pageEnvelopes[$index];

                if (! $this->option('include-tombstones') && ! $envelope['active']) {
                    continue;
                }

                $writer->startElement('record');
                $writer->writeAttribute('external-id', $envelope['external_id']);
                $writer->writeAttribute('active', $envelope['active'] ? 'true' : 'false');
                $writer->writeAttribute('source-updated-at', $envelope['source_updated_at']);

                foreach ($this->validatedExportRow($resource, $model) as $key => $value) {
                    if ($value !== null) {
                        $writer->writeElement(
                            str_replace('_', '-', $key),
                            is_bool($value) ? ($value ? 'true' : 'false') : (string) $value,
                        );
                    }
                }

                $writer->endElement();
                $count++;
            }
        });

        $writer->endElement();
        $writer->endDocument();
        $writer->flush();

        return $count;
    }

    /**
     * @param  callable(EloquentCollection<int, Model>): void  $callback
     */
    private function eachPage(ExportableResourceAdapter $resource, callable $callback): void
    {
        $snapshotAt = CarbonImmutable::now('UTC');
        $after = null;

        do {
            $models = $resource->applyCursor($resource->query($snapshotAt), $after)
                ->limit(200)
                ->get();

            if ($models->isEmpty()) {
                return;
            }

            $callback($models);
            $last = $models->last();
            $after = $last instanceof Model ? $resource->cursorValue($last) : null;
        } while ($models->count() === 200);
    }

    /**
     * @return array<string, bool|float|int|string|null>
     */
    private function validatedExportRow(ExportableResourceAdapter $resource, Model $model): array
    {
        $columns = array_keys($resource->exportColumns());
        $row = $resource->exportRow($model);

        if (array_keys($row) !== $columns) {
            throw new RuntimeException("Resource [{$resource->key()}] export row keys must match its columns.");
        }

        foreach ($row as $key => $value) {
            if (! is_bool($value) && ! is_float($value) && ! is_int($value) && ! is_string($value) && $value !== null) {
                throw new RuntimeException("Export column [{$key}] must be a scalar or null value.");
            }
        }

        return $row;
    }

    private function outputPath(string $resource, string $format): string
    {
        $argument = $this->argument('path');

        if (! is_string($argument) || trim($argument) === '') {
            return storage_path("app/exports/{$resource}-".now()->format('Ymd-His').".{$format}");
        }

        return str_starts_with($argument, DIRECTORY_SEPARATOR)
            ? $argument
            : base_path($argument);
    }

    private function ensureDirectory(string $directory): void
    {
        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException("Export directory [{$directory}] could not be created.");
        }
    }
}
