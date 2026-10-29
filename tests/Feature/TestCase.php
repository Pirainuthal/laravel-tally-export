<?php

declare(strict_types=1);

namespace Pirainuthal\TallyExport\Tests\Feature;

use Orchestra\Testbench\TestCase as Orchestra;
use Pirainuthal\TallyExport\TallyExportServiceProvider;
use Pirainuthal\TallyExport\TallyVoucher;

abstract class TestCase extends Orchestra
{
    protected function tearDown(): void
    {
        parent::tearDown();
        TallyVoucher::useDefaults(null);
    }

    protected function getPackageProviders($app): array
    {
        return [TallyExportServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['Tally' => \Pirainuthal\TallyExport\Facades\Tally::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('tally.url', 'http://tally.test:9000');
        $app['config']->set('tally.company', 'Demo Company');
    }
}
