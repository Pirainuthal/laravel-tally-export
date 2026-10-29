<?php

declare(strict_types=1);

namespace Pirainuthal\TallyExport\Contracts;

use Illuminate\Database\Eloquent\Model;
use Pirainuthal\TallyExport\Builders\VoucherBuilder;
use Pirainuthal\TallyExport\Data\Voucher;

/**
 * Turns one of your Eloquent models (an invoice, a payment...) into a Tally voucher.
 *
 * Used by the `tally:export` command and by TallyExporter::export() / vouchers().
 * Return null to skip a record (for example a cancelled invoice).
 *
 * @template TModel of Model
 */
interface VoucherMapper
{
    /**
     * @param  TModel  $model
     * @return Voucher|VoucherBuilder|array<string, mixed>|null
     */
    public function map(Model $model): Voucher|VoucherBuilder|array|null;
}
