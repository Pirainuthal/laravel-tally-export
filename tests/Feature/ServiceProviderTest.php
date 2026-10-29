<?php

declare(strict_types=1);

namespace Pirainuthal\TallyExport\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Pirainuthal\TallyExport\Facades\Tally;
use Pirainuthal\TallyExport\Http\TallyClient;
use Pirainuthal\TallyExport\TallyManager;
use Pirainuthal\TallyExport\TallyVoucher;
use Pirainuthal\TallyExport\Tests\Concerns\AssertsTallyXml;

final class ServiceProviderTest extends TestCase
{
    use AssertsTallyXml;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('tally.ledgers.cgst', 'Output CGST {rate}%');
        $app['config']->set('tally.ledgers.sgst', 'Output SGST {rate}%');
        $app['config']->set('tally.ledgers.round_off', 'Rounding Off');
        $app['config']->set('tally.voucher_types.sales', 'GST Sales');
    }

    public function test_config_is_merged_and_services_are_singletons(): void
    {
        self::assertSame('Output CGST {rate}%', config('tally.ledgers.cgst'));
        self::assertSame('IGST', config('tally.ledgers.igst'));
        self::assertSame(30, config('tally.timeout'));
        self::assertSame(app(TallyManager::class), app(TallyManager::class));
        self::assertSame('http://tally.test:9000', app(TallyClient::class)->url());
    }

    public function test_builders_use_configured_ledger_names(): void
    {
        $xml = TallyVoucher::sales()
            ->number('INV-1')->date('2026-04-01')->party('Kaveri Traders')
            ->addLine('Sales', '99.50', gstRate: 12)
            ->applyGst(false)
            ->roundOff()
            ->toXml();

        self::assertSame(
            ['Kaveri Traders', 'Sales', 'Output CGST 6%', 'Output SGST 6%', 'Rounding Off'],
            array_keys($this->entryAmounts($xml)),
        );
        self::assertSame('GST Sales', $this->xpathValue($xml, '//VOUCHER/@VCHTYPE'));
    }

    public function test_facade_export_uses_configured_company(): void
    {
        $xml = Tally::export([
            Tally::journal()->number('J1')->date('2026-04-01')->debit('A', 1)->credit('B', 1),
        ]);

        self::assertSame('Demo Company', $this->xpathValue($xml, '//SVCURRENTCOMPANY'));
        self::assertSame('All Masters', $this->xpathValue(Tally::exportLedgers([Tally::ledger('Cash', 'Cash-in-Hand')]), '//REPORTNAME'));
    }

    public function test_command_is_registered(): void
    {
        self::assertArrayHasKey('tally:export', Artisan::all());
    }
}
