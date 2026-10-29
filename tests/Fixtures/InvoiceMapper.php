<?php

declare(strict_types=1);

namespace Pirainuthal\TallyExport\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Pirainuthal\TallyExport\Builders\SalesVoucherBuilder;
use Pirainuthal\TallyExport\Contracts\VoucherMapper;
use Pirainuthal\TallyExport\TallyVoucher;

/**
 * @implements VoucherMapper<Invoice>
 */
final class InvoiceMapper implements VoucherMapper
{
    public function map(Model $model): ?SalesVoucherBuilder
    {
        /** @var Invoice $model */
        if ($model->cancelled) {
            return null;
        }

        return TallyVoucher::sales()
            ->number($model->number)
            ->date($model->invoice_date)
            ->party($model->customer)
            ->addLine('Sales', $model->taxable, gstRate: $model->gst_rate)
            ->applyGst(interState: $model->inter_state)
            ->roundOff();
    }
}
