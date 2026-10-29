<?php

declare(strict_types=1);

namespace Pirainuthal\TallyExport;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;
use Pirainuthal\TallyExport\Builders\BuilderDefaults;
use Pirainuthal\TallyExport\Console\ExportCommand;
use Pirainuthal\TallyExport\Http\TallyClient;
use Pirainuthal\TallyExport\Xml\EnvelopeWriter;

final class TallyExportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/tally.php', 'tally');

        $this->app->singleton(BuilderDefaults::class, static function (Application $app): BuilderDefaults {
            /** @var array<string, mixed> $config */
            $config = $app->make(Repository::class)->get('tally', []);

            return BuilderDefaults::fromConfig($config);
        });

        $this->app->singleton(EnvelopeWriter::class, static function (Application $app): EnvelopeWriter {
            $company = $app->make(Repository::class)->get('tally.company');

            return new EnvelopeWriter(is_string($company) && $company !== '' ? $company : null);
        });

        $this->app->singleton(VoucherFactory::class, static fn (Application $app): VoucherFactory => new VoucherFactory($app->make(BuilderDefaults::class)));

        $this->app->singleton(TallyExporter::class, static fn (Application $app): TallyExporter => new TallyExporter(
            $app->make(EnvelopeWriter::class),
            $app->make(VoucherFactory::class),
        ));

        $this->app->singleton(TallyClient::class, static function (Application $app): TallyClient {
            $config = $app->make(Repository::class);
            $url = $config->get('tally.url');
            $timeout = $config->get('tally.timeout');

            return new TallyClient(
                $app->make(HttpFactory::class),
                is_string($url) && $url !== '' ? $url : 'http://localhost:9000',
                is_numeric($timeout) ? (int) $timeout : 30,
            );
        });

        $this->app->singleton(TallyManager::class);
    }

    public function boot(): void
    {
        TallyVoucher::useDefaults($this->app->make(BuilderDefaults::class));

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/tally.php' => $this->app->configPath('tally.php'),
            ], 'tally-config');

            $this->commands([ExportCommand::class]);
        }
    }
}
