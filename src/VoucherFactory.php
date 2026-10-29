<?php

declare(strict_types=1);

namespace Pirainuthal\TallyExport;

use DateTimeInterface;
use Pirainuthal\TallyExport\Builders\BuilderDefaults;
use Pirainuthal\TallyExport\Builders\JournalVoucherBuilder;
use Pirainuthal\TallyExport\Builders\ReceiptVoucherBuilder;
use Pirainuthal\TallyExport\Builders\SalesVoucherBuilder;
use Pirainuthal\TallyExport\Builders\VoucherBuilder;
use Pirainuthal\TallyExport\Data\Voucher;
use Pirainuthal\TallyExport\Exceptions\InvalidVoucherException;

/**
 * Builds vouchers from plain arrays, e.g. rows coming from an API or a CSV.
 */
final class VoucherFactory
{
    public function __construct(private readonly BuilderDefaults $defaults = new BuilderDefaults) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function fromArray(array $data): Voucher
    {
        $type = $this->string($data, 'type', 'sales');

        $builder = match (strtolower($type)) {
            'sales' => $this->sales($data),
            'receipt' => $this->receipt($data),
            'journal' => $this->journal($data),
            default => throw new InvalidVoucherException(sprintf('Unknown voucher type "%s" (use sales, receipt or journal).', $type)),
        };

        return $builder->build();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function sales(array $data): SalesVoucherBuilder
    {
        $builder = $this->common(new SalesVoucherBuilder($this->defaults), $data);
        $builder->party($this->string($data, 'party'), $this->amountOrNull($data, 'party_total'));

        foreach ($this->rows($data, 'lines') as $line) {
            $builder->addLine($this->string($line, 'ledger'), $this->amount($line, 'amount'), $this->amountOrNull($line, 'gst_rate'));
        }
        foreach ($this->rows($data, 'taxes') as $tax) {
            $builder->addTax($this->string($tax, 'ledger'), $this->amount($tax, 'amount'));
        }
        foreach ($this->rows($data, 'discounts') as $discount) {
            $builder->addDiscount($this->string($discount, 'ledger'), $this->amount($discount, 'amount'), $this->amountOrNull($discount, 'gst_rate'));
        }

        if (isset($data['gst'])) {
            $builder->applyGst(match ($data['gst']) {
                'inter', 'igst', true => true,
                'intra', 'cgst_sgst', false => false,
                default => throw new InvalidVoucherException('"gst" must be "intra" or "inter".'),
            });
        }

        if (isset($data['round_off']) && $data['round_off'] !== false) {
            $builder->roundOff(is_string($data['round_off']) ? $data['round_off'] : null);
        }

        if (isset($data['bill_wise']) && $data['bill_wise'] !== false) {
            $builder->billWise(is_string($data['bill_wise']) ? $data['bill_wise'] : null);
        }

        if (isset($data['party_gstin']) && is_string($data['party_gstin']) && $data['party_gstin'] !== '') {
            $builder->partyGstin($data['party_gstin']);
        }

        if (isset($data['place_of_supply']) && is_string($data['place_of_supply'])) {
            $builder->placeOfSupply($data['place_of_supply']);
        }

        return $builder;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function receipt(array $data): ReceiptVoucherBuilder
    {
        $builder = $this->common(new ReceiptVoucherBuilder($this->defaults), $data);
        $builder->party($this->string($data, 'party'), $this->amount($data, 'amount'));
        $builder->depositTo($this->string($data, 'deposit_to'));

        foreach ($this->rows($data, 'deductions') as $deduction) {
            $builder->deduct($this->string($deduction, 'ledger'), $this->amount($deduction, 'amount'));
        }
        foreach ($this->rows($data, 'against') as $bill) {
            $builder->againstInvoice($this->string($bill, 'ref'), $this->amountOrNull($bill, 'amount'));
        }

        return $builder;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function journal(array $data): JournalVoucherBuilder
    {
        $builder = $this->common(new JournalVoucherBuilder($this->defaults), $data);

        foreach ($this->rows($data, 'debits') as $row) {
            $builder->debit($this->string($row, 'ledger'), $this->amount($row, 'amount'));
        }
        foreach ($this->rows($data, 'credits') as $row) {
            $builder->credit($this->string($row, 'ledger'), $this->amount($row, 'amount'));
        }

        return $builder;
    }

    /**
     * @template T of VoucherBuilder
     *
     * @param  T  $builder
     * @param  array<string, mixed>  $data
     * @return T
     */
    private function common(VoucherBuilder $builder, array $data): VoucherBuilder
    {
        $number = $data['number'] ?? null;
        if (! is_string($number) && ! is_int($number)) {
            throw new InvalidVoucherException('Voucher array needs a "number".');
        }

        $date = $data['date'] ?? null;
        if (! is_string($date) && ! $date instanceof DateTimeInterface) {
            throw new InvalidVoucherException(sprintf('Voucher "%s" needs a "date".', $number));
        }

        $builder->number($number)->date($date);

        foreach (['narration', 'reference'] as $key) {
            if (isset($data[$key]) && is_string($data[$key])) {
                $builder->{$key}($data[$key]);
            }
        }

        if (isset($data['voucher_type']) && is_string($data['voucher_type'])) {
            $builder->voucherType($data['voucher_type']);
        }

        return $builder;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    private function rows(array $data, string $key): array
    {
        $rows = $data[$key] ?? [];

        if (! is_array($rows)) {
            throw new InvalidVoucherException(sprintf('"%s" must be a list of rows.', $key));
        }

        $result = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new InvalidVoucherException(sprintf('Every entry in "%s" must be an array.', $key));
            }
            /** @var array<string, mixed> $row */
            $result[] = $row;
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function string(array $data, string $key, ?string $default = null): string
    {
        $value = $data[$key] ?? $default;

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidVoucherException(sprintf('Missing or empty "%s".', $key));
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function amount(array $data, string $key): int|float|string
    {
        return $this->amountOrNull($data, $key)
            ?? throw new InvalidVoucherException(sprintf('Missing "%s".', $key));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function amountOrNull(array $data, string $key): int|float|string|null
    {
        $value = $data[$key] ?? null;

        if ($value !== null && ! is_int($value) && ! is_float($value) && ! is_string($value)) {
            throw new InvalidVoucherException(sprintf('"%s" must be a number or numeric string.', $key));
        }

        return $value;
    }
}
