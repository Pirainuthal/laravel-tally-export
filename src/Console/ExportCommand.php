<?php

declare(strict_types=1);

namespace Pirainuthal\TallyExport\Console;

use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Pirainuthal\TallyExport\Contracts\VoucherMapper;
use Pirainuthal\TallyExport\Exceptions\TallyException;
use Pirainuthal\TallyExport\TallyManager;

final class ExportCommand extends Command
{
    /** @var string */
    protected $signature = 'tally:export
        {--from= : First date to include (Y-m-d)}
        {--to= : Last date to include (Y-m-d)}
        {--model= : Eloquent model class (defaults to tally.export.model)}
        {--mapper= : VoucherMapper class (defaults to tally.export.mapper)}
        {--output= : File to write, relative to storage/app, or "-" for stdout}
        {--push : Also send the XML to Tally over HTTP}';

    /** @var string */
    protected $description = 'Export invoices / receipts to a Tally import XML file';

    public function handle(TallyManager $tally, Repository $config): int
    {
        try {
            $from = $this->dateOption('from');
            $to = $this->dateOption('to');

            if ($from !== null && $to !== null && $from > $to) {
                $this->components->error('--from must be on or before --to.');

                return self::FAILURE;
            }

            $modelClass = $this->classOption('model', $config->get('tally.export.model'));
            $mapperClass = $this->classOption('mapper', $config->get('tally.export.mapper'));

            if (! is_subclass_of($modelClass, Model::class)) {
                $this->components->error(sprintf('Model "%s" is not an Eloquent model. Set tally.export.model or pass --model.', $modelClass));

                return self::FAILURE;
            }

            $mapper = $this->laravel->make($mapperClass);
            if (! $mapper instanceof VoucherMapper) {
                $this->components->error(sprintf('Mapper "%s" must implement %s.', $mapperClass, VoucherMapper::class));

                return self::FAILURE;
            }

            $column = (string) $config->get('tally.export.date_column', 'invoice_date');
            /** @var Model $model */
            $model = new $modelClass;
            $query = $model->newQuery()
                ->when($from, static fn ($q, DateTimeImmutable $d) => $q->whereDate($column, '>=', $d->format('Y-m-d')))
                ->when($to, static fn ($q, DateTimeImmutable $d) => $q->whereDate($column, '<=', $d->format('Y-m-d')))
                ->orderBy($column)
                ->orderBy($model->getKeyName());

            $chunk = max(1, (int) $config->get('tally.export.chunk', 500));
            $vouchers = $tally->exporter()->vouchers($query->lazy($chunk), $mapper);

            if ($vouchers === []) {
                $this->components->warn('No records found for the given range; nothing exported.');

                return self::SUCCESS;
            }

            $xml = $tally->writer()->write($vouchers, 'Vouchers');
            $this->writeOutput($xml, $config, $from, $to, count($vouchers));

            if ($this->option('push')) {
                return $this->push($tally, $xml);
            }

            return self::SUCCESS;
        } catch (TallyException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function push(TallyManager $tally, string $xml): int
    {
        $result = $tally->client()->push($xml);

        $this->table(
            ['Created', 'Altered', 'Ignored', 'Errors', 'Exceptions'],
            [[$result->created, $result->altered, $result->ignored, $result->errors, $result->exceptions]],
        );

        foreach ($result->lineErrors as $error) {
            $this->components->error($error);
        }

        if (! $result->successful()) {
            $this->components->error($result->exceptions > 0
                ? sprintf('Tally reported %d error(s) and %d exception(s).', $result->errors, $result->exceptions)
                : sprintf('Tally reported %d error(s).', $result->errors));

            return self::FAILURE;
        }

        $this->components->info(sprintf('Pushed to %s.', $tally->client()->url()));

        return self::SUCCESS;
    }

    private function writeOutput(string $xml, Repository $config, ?DateTimeImmutable $from, ?DateTimeImmutable $to, int $count): void
    {
        $output = $this->option('output');

        if ($output === '-') {
            $this->output->write($xml, false, \Symfony\Component\Console\Output\OutputInterface::OUTPUT_RAW);

            return;
        }

        $relative = is_string($output) && $output !== ''
            ? $output
            : str_replace(
                ['{from}', '{to}'],
                [$from?->format('Ymd') ?? 'all', $to?->format('Ymd') ?? 'all'],
                (string) $config->get('tally.export.output', 'tally/vouchers-{from}-{to}.xml'),
            );

        $path = str_starts_with($relative, '/') ? $relative : storage_path('app/'.$relative);
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new TallyException(sprintf('Could not create directory %s.', $directory));
        }

        if (file_put_contents($path, $xml) === false) {
            throw new TallyException(sprintf('Could not write %s.', $path));
        }

        $this->components->info(sprintf('Exported %d voucher(s) to %s', $count, $path));
    }

    private function dateOption(string $name): ?DateTimeImmutable
    {
        $value = $this->option($name);

        if ($value === null || $value === '') {
            return null;
        }

        $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;

        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new TallyException(sprintf('--%s must be a date in Y-m-d format, got "%s".', $name, is_string($value) ? $value : ''));
        }

        return $date;
    }

    private function classOption(string $name, mixed $fallback): string
    {
        $value = $this->option($name);
        $class = is_string($value) && $value !== '' ? $value : $fallback;

        if (! is_string($class) || $class === '' || ! class_exists($class)) {
            throw new TallyException(sprintf(
                'Set tally.export.%s in config/tally.php or pass --%s with an existing class.',
                $name,
                $name,
            ));
        }

        return $class;
    }
}
