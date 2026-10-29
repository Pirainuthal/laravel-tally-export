<?php

declare(strict_types=1);

namespace Pirainuthal\TallyExport\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $number
 * @property string $invoice_date
 * @property string $customer
 * @property string $taxable
 * @property int $gst_rate
 * @property bool $inter_state
 * @property bool $cancelled
 */
final class Invoice extends Model
{
    protected $guarded = [];

    public $timestamps = false;

    protected $casts = [
        'inter_state' => 'boolean',
        'cancelled' => 'boolean',
    ];
}
