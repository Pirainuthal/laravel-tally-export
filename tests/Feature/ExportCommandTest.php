<?php

declare(strict_types=1);

namespace Pirainuthal\TallyExport\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\PendingCommand;
use Pirainuthal\TallyExport\Tests\Concerns\AssertsTallyXml;
use Pirainuthal\TallyExport\Tests\Fixtures\Invoice;
use Pirainuthal\TallyExport\Tests\Fixtures\InvoiceMapper;

final class ExportCommandTest extends TestCase
{
    use AssertsTallyXml;

    private string $outputDir;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('invoices', static function (Blueprint $table): void {
            $table->id();
            $table->string('number');
            $table->date('invoice_date');
            $table->string('customer');
            $table->decimal('taxable', 12, 2);
            $table->unsignedInteger('gst_rate');
            $table->boolean('inter_state')->default(false);
            $table->boolean('cancelled')->default(false);
        });

        Invoice::query()->insert([
            ['number' => 'INV-001', 'invoice_date' => '2026-03-31', 'customer' => 'Old Customer', 'taxable' => '500.00', 'gst_rate' => 18, 'inter_state' => false, 'cancelled' => false],
            ['number' => 'INV-002', 'invoice_date' => '2026-04-01', 'customer' => 'Kaveri Traders', 'taxable' => '1234.50', 'gst_rate' => 18, 'inter_state' => false, 'cancelled' => false],
            ['number' => 'INV-003', 'invoice_date' => '2026-04-15', 'customer' => 'Mysuru Silks', 'taxable' => '2000.00', 'gst_rate' => 5, 'inter_state' => true, 'cancelled' => false],
            ['number' => 'INV-004', 'invoice_date' => '2026-04-20', 'customer' => 'Cancelled Co', 'taxable' => '10.00', 'gst_rate' => 18, 'inter_state' => false, 'cancelled' => true],
            ['number' => 'INV-005', 'invoice_date' => '2026-05-01', 'customer' => 'Next Month', 'taxable' => '10.00', 'gst_rate' => 18, 'inter_state' => false, 'cancelled' => false],
        ]);

        config()->set('tally.export.model', Invoice::class);
        config()->set('tally.export.mapper', InvoiceMapper::class);

        $this->outputDir = sys_get_temp_dir().'/tally-export-test-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->outputDir.'/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->outputDir)) {
            rmdir($this->outputDir);
        }

        parent::tearDown();
    }

    public function test_exports_the_date_range_to_a_file(): void
    {
        $path = $this->outputDir.'/april.xml';

        $this->export(['--from' => '2026-04-01', '--to' => '2026-04-30', '--output' => $path])
            ->expectsOutputToContain('Exported 2 voucher(s)')
            ->assertSuccessful();

        $xml = (string) file_get_contents($path);
        $this->assertBalancedVouchers($xml);

        $numbers = [];
        foreach ($this->elements($this->loadXml($xml), '//VOUCHERNUMBER') as $node) {
            $numbers[] = $node->textContent;
        }
        self::assertSame(['INV-002', 'INV-003'], $numbers);
        self::assertSame('Demo Company', $this->xpathValue($xml, '//SVCURRENTCOMPANY'));
        // INV-003 is inter-state at 5%: IGST 100.00
        self::assertSame('100.00', $this->xpathValue($xml, "//VOUCHER[VOUCHERNUMBER='INV-003']/ALLLEDGERENTRIES.LIST[LEDGERNAME='IGST']/AMOUNT"));
    }

    public function test_default_output_path_uses_storage_and_range(): void
    {
        $this->export(['--from' => '2026-04-01', '--to' => '2026-04-30'])->assertSuccessful();

        $path = storage_path('app/tally/vouchers-20260401-20260430.xml');
        self::assertFileExists($path);
        unlink($path);
    }

    public function test_stdout_output(): void
    {
        $this->export(['--from' => '2026-05-01', '--output' => '-'])
            ->expectsOutputToContain('<VOUCHERNUMBER>INV-005</VOUCHERNUMBER>')
            ->assertSuccessful();
    }

    public function test_push_option_sends_to_tally(): void
    {
        Http::fake(['*' => Http::response('<RESPONSE><CREATED>2</CREATED><ALTERED>0</ALTERED><ERRORS>0</ERRORS></RESPONSE>')]);

        $this->export(['--from' => '2026-04-01', '--to' => '2026-04-30', '--output' => $this->outputDir.'/p.xml', '--push' => true])
            ->expectsOutputToContain('Pushed to http://tally.test:9000')
            ->assertSuccessful();

        Http::assertSentCount(1);
    }

    public function test_push_failure_returns_non_zero(): void
    {
        Http::fake(['*' => Http::response('<RESPONSE><CREATED>0</CREATED><ERRORS>2</ERRORS><LINEERROR>Voucher totals do not match!</LINEERROR></RESPONSE>')]);

        $this->export(['--output' => $this->outputDir.'/f.xml', '--push' => true])
            ->expectsOutputToContain('Voucher totals do not match!')
            ->assertFailed();
    }

    public function test_push_with_tally_exceptions_but_no_errors_fails_and_says_so(): void
    {
        Http::fake(['*' => Http::response('<RESPONSE><CREATED>0</CREATED><ERRORS>0</ERRORS><EXCEPTIONS>1</EXCEPTIONS></RESPONSE>')]);

        $this->export(['--output' => $this->outputDir.'/g.xml', '--push' => true])
            ->expectsOutputToContain('Tally reported 0 error(s) and 1 exception(s).')
            ->assertFailed();
    }

    public function test_empty_range_warns(): void
    {
        $this->export(['--from' => '2030-01-01', '--output' => $this->outputDir.'/none.xml'])
            ->expectsOutputToContain('No records found')
            ->assertSuccessful();

        self::assertFileDoesNotExist($this->outputDir.'/none.xml');
    }

    public function test_validates_options(): void
    {
        $this->export(['--from' => '01-04-2026'])
            ->expectsOutputToContain('--from must be a date in Y-m-d format')
            ->assertFailed();

        $this->export(['--from' => '2026-05-01', '--to' => '2026-04-01'])
            ->expectsOutputToContain('--from must be on or before --to')
            ->assertFailed();

        $this->export(['--model' => 'App\\Models\\Missing'])
            ->expectsOutputToContain('pass --model with an existing class')
            ->assertFailed();

        $this->export(['--mapper' => Invoice::class])
            ->expectsOutputToContain('must implement')
            ->assertFailed();
    }

    public function test_invalid_record_fails_with_a_clear_message(): void
    {
        Invoice::query()->where('number', 'INV-002')->update(['customer' => '']);

        $this->export(['--from' => '2026-04-01', '--to' => '2026-04-30', '--output' => $this->outputDir.'/bad.xml'])
            ->expectsOutputToContain('needs a party()')
            ->assertFailed();
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function export(array $options): PendingCommand
    {
        $command = $this->artisan('tally:export', $options);
        self::assertInstanceOf(PendingCommand::class, $command);

        return $command;
    }
}
