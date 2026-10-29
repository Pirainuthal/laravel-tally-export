# Laravel Tally Export

**Turn Laravel invoices, receipts and customers into Tally Prime / Tally ERP 9 import XML: balanced, GST-aware, and pushable straight to a running Tally.**

[![CI](https://github.com/Pirainuthal/laravel-tally-export/actions/workflows/ci.yml/badge.svg)](https://github.com/Pirainuthal/laravel-tally-export/actions/workflows/ci.yml)
[![PHP](https://img.shields.io/badge/PHP-8.2%20%7C%208.3%20%7C%208.4-777BB4?logo=php&logoColor=white)](composer.json)
[![Laravel](https://img.shields.io/badge/Laravel-12-FF2D20?logo=laravel&logoColor=white)](composer.json)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%208-brightgreen)](phpstan.neon.dist)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

Most Indian small businesses keep their books in Tally. If your Laravel app raises the
invoices, someone usually re-types them into Tally at month end. This package writes
them in Tally's own XML import format instead:

- **Fluent builders** for Sales, Receipt and Journal vouchers, plus ledger masters.
- **Never exports an unbalanced voucher.** Debits must equal credits or you get a clear `UnbalancedVoucherException`.
- **GST helpers:** CGST + SGST or IGST computed per rate, configurable ledger names (`Output CGST {rate}%`), GSTIN checksum validation, round-off to the nearest rupee.
- **Exact money maths:** everything is integer paise internally, so floats never touch your totals.
- **Bill-wise references**, so receipts settle the right invoices in Tally's outstanding reports.
- **Batch export** of vouchers, arrays or Eloquent models (through a mapper) into one envelope.
- **Optional HTTP push** to Tally's built-in server, with `CREATED` / `ERRORS` / `LINEERROR` parsing.
- **`php artisan tally:export --from --to [--push]`** for a monthly export.

> An open-source portfolio project by [Pirainuthal S](https://github.com/Pirainuthal).
> It is not affiliated with or endorsed by Tally Solutions Pvt. Ltd. "Tally", "TallyPrime"
> and "Tally.ERP 9" are their trademarks.

---

## Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Quick start](#quick-start)
- [Ledger masters and GSTIN](#ledger-masters-and-gstin)
- [Balance checks](#balance-checks)
- [Exporting many invoices](#exporting-many-invoices)
- [The `tally:export` command](#the-tallyexport-command)
- [Pushing to Tally over HTTP](#pushing-to-tally-over-http)
- [API reference](#api-reference)
- [How the XML is built (and why)](#how-the-xml-is-built-and-why)
- [Design notes](#design-notes)
- [Testing](#testing)
- [Roadmap](#roadmap)
- [License](#license)

## Requirements

| | Version |
|---|---|
| PHP | 8.2+ (`ext-mbstring`, `ext-simplexml`, `ext-xmlwriter`) |
| Laravel | 11.x or 12.x (`illuminate/*` components) |
| Tally | Tally Prime or Tally.ERP 9 with XML import (file import or the HTTP server) |

The builders are plain PHP and also work outside Laravel. Only the facade, config,
command and HTTP push need the framework.

## Installation

```bash
composer require pirainuthal/laravel-tally-export
php artisan vendor:publish --tag=tally-config
```

The service provider and the `Tally` facade are auto-discovered. The published
`config/tally.php` holds the Tally URL, company name and **ledger names, which must
match ledgers that already exist in your Tally company**:

```php
'ledgers' => [
    'cgst' => env('TALLY_LEDGER_CGST', 'CGST'),           // e.g. 'Output CGST {rate}%'
    'sgst' => env('TALLY_LEDGER_SGST', 'SGST'),
    'igst' => env('TALLY_LEDGER_IGST', 'IGST'),
    'round_off' => env('TALLY_LEDGER_ROUND_OFF', 'Round Off'),
],
```

## Quick start

```php
use Pirainuthal\TallyExport\TallyVoucher;

$xml = TallyVoucher::sales()
    ->number('INV-2026-0042')
    ->date('2026-04-15')
    ->party('Kaveri Traders')
    ->narration('Being goods sold')
    ->addLine('Sales @ 18%', '1234.50', gstRate: 18)
    ->applyGst(interState: false)   // CGST 9% + SGST 9%
    ->roundOff()                    // to the nearest rupee
    ->billWise()                    // "New Ref" INV-2026-0042
    ->toXml();
```

Real output (`php examples/basic.php`):

```xml
<ENVELOPE>
  <HEADER>
    <TALLYREQUEST>Import Data</TALLYREQUEST>
  </HEADER>
  <BODY>
    <IMPORTDATA>
      <REQUESTDESC>
        <REPORTNAME>Vouchers</REPORTNAME>
      </REQUESTDESC>
      <REQUESTDATA>
        <TALLYMESSAGE xmlns:UDF="TallyUDF">
          <VOUCHER VCHTYPE="Sales" ACTION="Create" OBJVIEW="Accounting Voucher View">
            <DATE>20260415</DATE>
            <EFFECTIVEDATE>20260415</EFFECTIVEDATE>
            <VOUCHERTYPENAME>Sales</VOUCHERTYPENAME>
            <VOUCHERNUMBER>INV-2026-0042</VOUCHERNUMBER>
            <PARTYLEDGERNAME>Kaveri Traders</PARTYLEDGERNAME>
            <NARRATION>Being goods sold</NARRATION>
            <PERSISTEDVIEW>Accounting Voucher View</PERSISTEDVIEW>
            <ISINVOICE>No</ISINVOICE>
            <ALLLEDGERENTRIES.LIST>
              <LEDGERNAME>Kaveri Traders</LEDGERNAME>
              <ISDEEMEDPOSITIVE>Yes</ISDEEMEDPOSITIVE>
              <ISPARTYLEDGER>Yes</ISPARTYLEDGER>
              <AMOUNT>-1457.00</AMOUNT>
              <BILLALLOCATIONS.LIST>
                <NAME>INV-2026-0042</NAME>
                <BILLTYPE>New Ref</BILLTYPE>
                <AMOUNT>-1457.00</AMOUNT>
              </BILLALLOCATIONS.LIST>
            </ALLLEDGERENTRIES.LIST>
            <ALLLEDGERENTRIES.LIST>
              <LEDGERNAME>Sales @ 18%</LEDGERNAME>
              <ISDEEMEDPOSITIVE>No</ISDEEMEDPOSITIVE>
              <ISPARTYLEDGER>No</ISPARTYLEDGER>
              <AMOUNT>1234.50</AMOUNT>
            </ALLLEDGERENTRIES.LIST>
            <ALLLEDGERENTRIES.LIST>
              <LEDGERNAME>CGST</LEDGERNAME>
              <ISDEEMEDPOSITIVE>No</ISDEEMEDPOSITIVE>
              <ISPARTYLEDGER>No</ISPARTYLEDGER>
              <AMOUNT>111.11</AMOUNT>
            </ALLLEDGERENTRIES.LIST>
            <ALLLEDGERENTRIES.LIST>
              <LEDGERNAME>SGST</LEDGERNAME>
              <ISDEEMEDPOSITIVE>No</ISDEEMEDPOSITIVE>
              <ISPARTYLEDGER>No</ISPARTYLEDGER>
              <AMOUNT>111.11</AMOUNT>
            </ALLLEDGERENTRIES.LIST>
            <ALLLEDGERENTRIES.LIST>
              <LEDGERNAME>Round Off</LEDGERNAME>
              <ISDEEMEDPOSITIVE>No</ISDEEMEDPOSITIVE>
              <ISPARTYLEDGER>No</ISPARTYLEDGER>
              <AMOUNT>0.28</AMOUNT>
            </ALLLEDGERENTRIES.LIST>
          </VOUCHER>
        </TALLYMESSAGE>
      </REQUESTDATA>
    </IMPORTDATA>
  </BODY>
</ENVELOPE>
```

The arithmetic: 9% of 1234.50 is 111.105, which rounds to **111.11** per tax.
1234.50 + 111.11 + 111.11 = 1456.72, and rounding to the nearest rupee gives **1457.00**,
so +0.28 goes to the round-off ledger. The signed amounts sum to exactly zero.

A receipt that settles that invoice:

```php
TallyVoucher::receipt()
    ->number('RCT-0007')
    ->date('2026-04-30')
    ->party('Kaveri Traders', 1457)        // party is credited
    ->depositTo('State Bank Current A/c')  // bank is debited
    ->againstInvoice('INV-2026-0042')      // "Agst Ref"; any unallocated rest becomes "On Account"
    ->toXml();
```

If the customer deducted TDS, add `->deduct('TDS Receivable', 29.14)`. The deduction is debited and the bank gets the rest.

## Ledger masters and GSTIN

```php
use Pirainuthal\TallyExport\Gst\GstDutyHead;
use Pirainuthal\TallyExport\Gst\Gstin;
use Pirainuthal\TallyExport\TallyExporter;
use Pirainuthal\TallyExport\TallyLedger;

var_dump(Gstin::isValid('33ABCDE1234F1Z7'), Gstin::isValid('33ABCDE1234F1Z8'));
echo Gstin::parse('33ABCDE1234F1Z7')->stateName(), PHP_EOL;

echo (new TallyExporter)->exportLedgers([
    TallyLedger::customer('Kaveri Traders')     // Sundry Debtors, bill-wise on
        ->gstin('33ABCDE1234F1Z7')              // validated: format, state code, check character
        ->stateFromGstin()
        ->address('12, Gandhi Road', 'Erode')
        ->pincode('638001')
        ->openingBalance(5000),                 // Dr 5000 -> -5000.00
    TallyLedger::gstDuty('CGST', GstDutyHead::Central),
]);
```

Real output (`php examples/ledgers.php`, envelope trimmed):

```text
bool(true)
bool(false)
Tamil Nadu
```

```xml
<REPORTNAME>All Masters</REPORTNAME>
...
<LEDGER NAME="Kaveri Traders" ACTION="Create">
  <NAME.LIST TYPE="String">
    <NAME>Kaveri Traders</NAME>
  </NAME.LIST>
  <PARENT>Sundry Debtors</PARENT>
  <ADDRESS.LIST TYPE="String">
    <ADDRESS>12, Gandhi Road</ADDRESS>
    <ADDRESS>Erode</ADDRESS>
  </ADDRESS.LIST>
  <ISBILLWISEON>Yes</ISBILLWISEON>
  <COUNTRYNAME>India</COUNTRYNAME>
  <LEDSTATENAME>Tamil Nadu</LEDSTATENAME>
  <PINCODE>638001</PINCODE>
  <GSTREGISTRATIONTYPE>Regular</GSTREGISTRATIONTYPE>
  <PARTYGSTIN>33ABCDE1234F1Z7</PARTYGSTIN>
  <OPENINGBALANCE>-5000.00</OPENINGBALANCE>
</LEDGER>
...
<LEDGER NAME="CGST" ACTION="Create">
  <NAME.LIST TYPE="String">
    <NAME>CGST</NAME>
  </NAME.LIST>
  <PARENT>Duties &amp; Taxes</PARENT>
  <TAXTYPE>GST</TAXTYPE>
  <GSTDUTYHEAD>Central Tax</GSTDUTYHEAD>
</LEDGER>
```

GSTINs used in this README and in the tests are synthetic: they have valid check characters but do not belong to anyone.

## Balance checks

A `Voucher` cannot be created unbalanced. Builders compute the party amount for you.
If you also pass the invoice total you expect, a mismatch is reported instead of silently "fixed".

```php
TallyVoucher::sales()->number('INV-2026-0043')->date('2026-04-16')
    ->party('Kaveri Traders', total: 1200)   // the invoice says 1200...
    ->addLine('Sales @ 18%', 1000)
    ->addTax('CGST', 90)
    ->addTax('SGST', 90)                     // ...but lines + tax = 1180
    ->build();

TallyVoucher::journal()->number('JV-7')->date('2026-06-30')
    ->debit('Rent', 25000)
    ->credit('State Bank Current A/c', '24999.99')
    ->build();
```

Real output (`php examples/unbalanced.php`):

```text
Pirainuthal\TallyExport\Exceptions\UnbalancedVoucherException: Voucher "INV-2026-0043" is not balanced: debits 1200.00, credits 1180.00 (difference 20.00). Party total 1200.00 does not match lines + taxes - discounts = 1180.00.
Pirainuthal\TallyExport\Exceptions\UnbalancedVoucherException: Voucher "JV-7" is not balanced: debits 25000.00, credits 24999.99 (difference 0.01).
```

The exception exposes `$voucherNumber`, `$debitPaise` and `$creditPaise` for logging.

## Exporting many invoices

`Tally::export()` (or `new TallyExporter`) accepts any iterable of `Voucher` objects,
builders, arrays, or anything a mapper understands. It produces **one** envelope with one
`TALLYMESSAGE` per voucher.

```php
use Pirainuthal\TallyExport\Facades\Tally;

$xml = Tally::export(Invoice::with('lines')->whereMonth('invoice_date', 4)->get(), new InvoiceMapper);
Storage::put('tally/april.xml', $xml);   // Tally: Import > Transactions > select the file
```

Array format, handy for CSV or API input:

```php
Tally::export([[
    'type' => 'sales',                     // sales | receipt | journal
    'number' => 'INV-9', 'date' => '2026-07-01',
    'party' => 'Kaveri Traders', 'party_total' => 2360,   // party_total is optional
    'lines' => [['ledger' => 'Sales', 'amount' => 2000, 'gst_rate' => 18]],
    'gst' => 'inter',                      // intra | inter
    'round_off' => true, 'bill_wise' => true,
]]);
```

A mapper turns your model into a voucher. Return `null` to skip a record:

```php
use Illuminate\Database\Eloquent\Model;
use Pirainuthal\TallyExport\Contracts\VoucherMapper;
use Pirainuthal\TallyExport\TallyVoucher;

/** @implements VoucherMapper<\App\Models\Invoice> */
final class InvoiceMapper implements VoucherMapper
{
    public function map(Model $invoice): ?\Pirainuthal\TallyExport\Builders\SalesVoucherBuilder
    {
        if ($invoice->cancelled) {
            return null;
        }

        $voucher = TallyVoucher::sales()
            ->number($invoice->number)
            ->date($invoice->invoice_date)
            ->party($invoice->customer->tally_ledger, $invoice->grand_total)
            ->applyGst(interState: $invoice->place_of_supply !== config('app.home_state'))
            ->roundOff()
            ->billWise();

        foreach ($invoice->lines as $line) {
            $voucher->addLine($line->tally_sales_ledger, $line->taxable_value, gstRate: $line->gst_rate);
        }

        return $voucher;
    }
}
```

## The `tally:export` command

Point the config at your model and mapper:

```php
// config/tally.php
'export' => [
    'model' => App\Models\Invoice::class,
    'mapper' => App\Tally\InvoiceMapper::class,
    'date_column' => 'invoice_date',
    'chunk' => 500,
    'output' => 'tally/vouchers-{from}-{to}.xml',   // relative to storage/app
],
```

```text
php artisan tally:export --from=2026-04-01 --to=2026-04-30
php artisan tally:export --from=2026-04-01 --to=2026-04-30 --output=-     # print to stdout
php artisan tally:export --from=2026-04-01 --to=2026-04-30 --push         # also send to Tally
php artisan tally:export --model="App\Models\Receipt" --mapper="App\Tally\ReceiptMapper"
```

Real output, captured from a Testbench app with an in-memory SQLite table (file paths
shortened to `...`). In the `--push` runs Tally's response was faked with `Http::fake`:

```text
$ php artisan tally:export --from=2026-04-01 --to=2026-04-30

   INFO  Exported 2 voucher(s) to .../storage/app/tally/vouchers-20260401-20260430.xml.

$ php artisan tally:export --from=2026-04-01 --to=2026-04-30 --push
   INFO  Exported 2 voucher(s) to ...
+---------+---------+---------+--------+------------+
| Created | Altered | Ignored | Errors | Exceptions |
+---------+---------+---------+--------+------------+
| 2       | 0       | 0       | 0      | 0          |
+---------+---------+---------+--------+------------+

   INFO  Pushed to http://localhost:9000.

$ php artisan tally:export --from=2026-04-01 --to=2026-04-30 --push
   INFO  Exported 2 voucher(s) to ...
+---------+---------+---------+--------+------------+
| Created | Altered | Ignored | Errors | Exceptions |
+---------+---------+---------+--------+------------+
| 0       | 0       | 0       | 1      | 0          |
+---------+---------+---------+--------+------------+

   ERROR  Ledger 'Sales' does not exist!

   ERROR  Tally reported 1 error(s).
# exit code 1
```

Records are streamed with `lazy()` in date order. If any record is invalid, nothing is written
and the command exits with code 1, showing the voucher number and the reason.

## Pushing to Tally over HTTP

Tally can act as an HTTP server (TallyPrime: *F1 Help > Settings > Connectivity*,
"TallyPrime acts as Server", port **9000** by default). Then:

```php
$result = Tally::push($xml);                 // string, Voucher, Ledger, or any builder
$result->created;     // int
$result->altered;     // int
$result->errors;      // int (at least the number of LINEERROR messages)
$result->lineErrors;  // ["Ledger 'Sales' does not exist!", ...]
$result->successful() || $result->throwIfFailed();   // throws TallyImportException
```

| Situation | Result |
|---|---|
| Tally unreachable / timeout | `TallyConnectionException` |
| Non-2xx HTTP status | `TallyConnectionException` |
| Body is not XML or has no counters | `TallyResponseException` (raw body in `$e->body`) |
| `ERRORS`/`EXCEPTIONS` > 0 or a `LINEERROR` | `ImportResult::successful() === false` |

Both response shapes are understood: the bare `<RESPONSE><CREATED>…` form and the
`<ENVELOPE>…<BODY><DATA><IMPORTRESULT>…` form (including `LASTVCHID`). UTF-8 / UTF-16 BOMs are handled.

## API reference

### `TallyVoucher` (static entry point; `Tally::` facade has the same methods)

| Method | Returns |
|---|---|
| `sales()`, `receipt()`, `journal()` | a builder (below) |
| `fromArray(array $data)` | validated `Voucher` |

**Common to all voucher builders:** `number(string\|int)`, `date(DateTimeInterface\|string)`
(`Y-m-d`, `Ymd`, `d-m-Y`, `d/m/Y`), `narration(?string)`, `reference(?string)`,
`voucherType(string)` for custom types like "GST Sales", `action('Create'\|'Alter')`,
`build(): Voucher`, `toXml(?string $company = null): string`.

| `SalesVoucherBuilder` | |
|---|---|
| `party(string $ledger, $total = null)` | Debited. With `$total`, a mismatch throws `UnbalancedVoucherException`. |
| `addLine(string $ledger, $amount, $gstRate = null)` | Credits a sales ledger (taxable value). |
| `addTax(string $ledger, $amount)` | Credits a duty ledger with an explicit amount. |
| `addDiscount(string $ledger, $amount, $gstRate = null)` | Debits a discount ledger. With `applyGst()`, `$gstRate` is required and the discount reduces the taxable value at that rate (use `0` for nil-rated lines). |
| `applyGst(bool $interState, ?GstLedgers $ledgers = null)` | CGST+SGST or IGST from line rates. |
| `roundOff(?string $ledger = null)` | Rounds the party total to the nearest rupee. |
| `billWise(?string $reference = null)` | Adds a `New Ref` bill allocation (defaults to the voucher number). |
| `partyGstin(string)`, `placeOfSupply(string)` | Voucher-level GST details (GSTIN validated). |

| `ReceiptVoucherBuilder` | |
|---|---|
| `party(string $ledger, $amount)` | Credited with the full settled amount. |
| `depositTo(string $ledger)` | Bank/cash ledger, debited with amount minus deductions. |
| `deduct(string $ledger, $amount)` | TDS, bank charges… (debited). |
| `againstInvoice(string $ref, $amount = null)` | `Agst Ref` allocation; remainder becomes `On Account`. |
| `advance(string $ref, $amount)` | `Advance` allocation. |

| `JournalVoucherBuilder` | |
|---|---|
| `debit($ledger, $amount)`, `credit($ledger, $amount)` | Any voucher type via `voucherType()` (Journal, Contra, Payment…). |
| `party(string $ledger)` | Optional `PARTYLEDGERNAME`. |

### `TallyLedger`

`make($name, $parent)`, `customer()` (Sundry Debtors, bill-wise), `supplier()` (Sundry Creditors, bill-wise),
`sales()` (Sales Accounts), `bank()` (Bank Accounts), `gstDuty($name, GstDutyHead)` (Duties & Taxes),
`roundOff()` (Indirect Expenses). Builder methods: `under()`, `billWise()`, `address(...$lines)`,
`state()`, `stateFromGstin()`, `country()`, `pincode()`, `email()`, `gstin()`, `registrationType()`,
`dutyHead()`, `openingBalance($amount, Side $side = Side::Debit)`, `action()`, `build()`, `toXml()`.

### Other public classes

| Class | Purpose |
|---|---|
| `TallyExporter` | `export(iterable, ?mapper)`, `vouchers(iterable, ?mapper): list<Voucher>`, `exportLedgers(iterable)` |
| `Xml\EnvelopeWriter` | Writes the envelope; `new EnvelopeWriter(company: 'My Co')` adds `SVCURRENTCOMPANY` |
| `Gst\Gstin` | `isValid()`, `parse()`, `checkCharacter()`, `stateCode()`, `stateName()`, `pan()` |
| `Gst\GstLedgers` | Duty ledger name patterns with `{rate}` |
| `Http\TallyClient` / `Http\ImportResult` | HTTP push and response parsing |
| `Contracts\VoucherMapper` | `map(Model): Voucher\|VoucherBuilder\|array\|null` |

Every exception extends `Exceptions\TallyException`. Validation problems are
`InvalidVoucherException` (subclasses: `UnbalancedVoucherException`, `InvalidGstinException`).

## How the XML is built (and why)

### Envelope

Tally's documented import request is `ENVELOPE > HEADER > TALLYREQUEST` = `Import Data`, then
`BODY > IMPORTDATA > REQUESTDESC > REPORTNAME` and `REQUESTDATA > TALLYMESSAGE > VOUCHER`
[[1]](#sources). Vouchers use `REPORTNAME` **Vouchers**, ledger masters use **All Masters**,
and the writer refuses to mix them in one envelope. `SVCURRENTCOMPANY` (in `STATICVARIABLES`)
is added only when you configure a company. Otherwise Tally imports into the company that is open.

### Sign conventions

| Side | `ISDEEMEDPOSITIVE` | `AMOUNT` |
|---|---|---|
| Debit | `Yes` | negative, e.g. `-1180.00` |
| Credit | `No` | positive, e.g. `1180.00` |

Tally's own receipt example posts the bank (debit) with `ISDEEMEDPOSITIVE` = `Yes` and the amount
multiplied by −1, and the party (credit) with a positive amount [[2]](#sources). The same rule is applied
to `BILLALLOCATIONS.LIST` amounts and to ledger `OPENINGBALANCE` (a debit balance is negative).
In a balanced voucher the signed `AMOUNT`s sum to zero, and the test suite asserts exactly that for every generated voucher.

### Other formatting rules

- **Dates** are written as `YYYYMMDD` [[1]](#sources). Impossible dates such as `2026-02-30` are rejected, not rolled over.
- **Amounts** are written with exactly two decimals and no thousands separators.
- **Escaping** is done by PHP's `XMLWriter` (`&amp;`, `&lt;`, `&gt;`, `&quot;`). On top of that, control characters that XML 1.0 forbids are stripped and invalid UTF-8 is scrubbed, so a stray byte in a customer name cannot break the whole import.
- **Accounting mode:** vouchers are written in *Accounting Voucher View* (`ALLLEDGERENTRIES.LIST`, `ISINVOICE` = `No`). Item-invoice mode with stock items is on the roadmap.

### GST

- Tax is computed **per GST rate on the summed taxable value of the lines** and rounded half-up to the paisa. CGST and SGST are each half the rate and rounded separately, so an 18% line on ₹1234.50 gives 111.11 + 111.11.
- **Discounts shown on the invoice reduce the taxable value** (CGST Act 2017, Section 15(3)(a) [[6]](#sources)). So when `applyGst()` is used, every `addDiscount()` must say which GST rate it belongs to, and tax is computed on the discounted value. `->addLine('Sales', 1000, gstRate: 18)->addDiscount('Discount Allowed', 100, gstRate: 18)` gives CGST 81 + SGST 81, not 90 + 90. A discount without a rate is rejected rather than guessed. Discounts given after the invoice belong in a credit note, not here.
- Rates are handled in basis points. Any rate from 0 to 100 with two decimals is accepted. The notified slabs are deliberately **not hard-coded**, because they change (e.g. the 2025 rate rationalisation).
- `roundOff()` rounds the invoice total to the nearest rupee, with 50 paise and above rounding up. This is a common invoicing convention, not a legal requirement: Section 170 of the CGST Act [[4]](#sources) applies the same rule to tax *payable to the government*, and the package borrows that rule for invoice totals. Individual tax amounts are left at paise precision.
- UTGST: use your UTGST ledger name for the `sgst` setting. Tally files both under the *State Tax* duty head.
- **GSTIN validation** checks the shape (2-digit state code + 13 alphanumerics), the state code against the GST state-code list, and the 15th check character. The check character uses the base-36 Luhn-style checksum applied by GSTN, implemented the same way as python-stdnum's reference `gstin.py` [[3]](#sources) (weights 1, 2, 1, 2…; each product folded as `⌊p/36⌋ + p mod 36`; check = `(36 − sum mod 36) mod 36`). A synthetic example, `27ABCDE1234F1Z0` (placeholder PAN), is in the tests.

### Sources

1. Tally Solutions, *Integration initiated from TPAs*: import request envelope and the `CREATED` / `ALTERED` / `ERRORS` response. <https://help.tallysolutions.com/integration-initiated-from-tpas/>
2. Tally Solutions, *Integration scenario: receipt voucher creation/alteration*: `ALLLEDGERENTRIES.LIST` with `ISDEEMEDPOSITIVE` = `Yes` and a negated amount for the debit leg; response checked for `LINEERROR` and `ENVELOPE/BODY/DATA/IMPORTRESULT/LASTVCHID`. <https://help.tallysolutions.com/scenario-2/>
3. python-stdnum, `stdnum/in_/gstin.py` (GSTIN check digit, "Luhn mod 36"). <https://arthurdejong.org/python-stdnum/doc/stdnum.in_.gstin>
4. CGST Act 2017, Section 170, *Rounding off of tax, etc.* (official text on cbic-gst.gov.in; summary at <https://www.knowyourgst.com/gstlaw/cgst-act/170-rounding-off-of-tax-etc-170/>).
5. Tally Solutions, *TallyPrime as a server* (HTTP on port 9000) and *Significance of header tags* (`STATUS` in responses). <https://help.tallysolutions.com/tallyprime-as-server/>, <https://help.tallysolutions.com/article/DeveloperReference/integration-capabilities/significance_of_tags.htm>
6. CGST Act 2017, Section 15(3), *Value of taxable supply*: discounts given before or at the time of supply and recorded in the invoice are not part of the value (official text on cbic-gst.gov.in; summary at <https://www.knowyourgst.com/gstlaw/cgst-act/15-value-of-taxable-supply-15/>).

### Known limitations and uncertainties

These points are documented honestly rather than guessed:

- **Not yet verified against a live Tally instance.** The XML follows Tally's published samples and widely used export layouts, and the HTTP push is tested with mocked responses only. Please open an issue with the `LINEERROR` text if your Tally version rejects something.
- **Ledger GST tags.** `PARTYGSTIN`, `LEDSTATENAME`, `GSTREGISTRATIONTYPE`, `TAXTYPE` and `GSTDUTYHEAD` are the flat tags seen in Tally.ERP 9 / TallyPrime exports. Newer TallyPrime releases also store GST registration in nested lists (e.g. `LEDGSTREGDETAILS.LIST`). Whether every release still accepts the flat tags on import is not confirmed, so check one ledger in your version first.
- **Voucher-level `PARTYGSTIN` / `PLACEOFSUPPLY`** are emitted only when you set them. They are informational for Tally's GST reports and their handling may differ by release.
- **State names** use official spellings. Your Tally company may spell some differently (e.g. "&" vs "and"), so prefer `state('…')` with the exact Tally name over `stateFromGstin()` when it matters.
- **Encoding:** the XML is UTF-8 and sent with `Content-Type: text/xml; charset=utf-8`. TallyPrime handles this. Very old Tally.ERP 9 releases may need ASCII-only names.
- Tax-on-invoice rounding conventions vary between businesses (per line vs per invoice). This package computes per rate per invoice. If your invoices are computed differently, pass the exact amounts with `addTax()`.

## Design notes

- **Builders → immutable value objects → writer.** Builders collect input. `build()` returns a readonly `Voucher` / `Ledger` whose constructor enforces the invariants (balanced, ≥ 2 entries, positive amounts, bill allocations adding up to the entry). An unbalanced voucher simply cannot exist, so the writer never has to check.
- **Integer paise everywhere.** `Amount::toPaise()` parses strings exactly (`"1,18,000.75"` works) and converts floats through a 15-significant-digit decimal string (independent of PHP's `precision` ini setting), so `1.005` becomes 101 paise, not 100.
- **Unsigned amounts + `Side` enum.** Tally's negative-means-debit convention lives in one place (`Side::signedForTally()`) instead of being scattered through the code.
- **Same-ledger merging.** Two lines on "Sales" become one entry, which keeps vouchers readable in Tally.
- **No hidden global config in the core.** The builders work without Laravel. The service provider only injects ledger-name defaults from `config/tally.php`.
- **Fail loudly, with the voucher number in the message.** Batch exports of hundreds of invoices are only practical if the error tells you which one to fix.

## Testing

```bash
composer install
composer test        # PHPUnit: 122 tests, 650 assertions
composer lint        # Laravel Pint (--test)
composer analyse     # PHPStan + Larastan, level 8
composer check       # all three
```

- **Unit** (104 tests): amounts, dates, GSTIN, GST rates, every builder, ledger XML, envelope snapshots (`tests/Fixtures/*.xml`), the array factory and response parsing.
  Every generated voucher is parsed back with DOM and checked to be well-formed, with signed amounts summing to zero and `ISDEEMEDPOSITIVE` matching the sign.
- **Feature** (18 tests, Orchestra Testbench): service provider and config merging, the facade, HTTP push with `Http::fake` (success, `LINEERROR`, HTTP 500, connection refused, garbage body), and `tally:export` against an in-memory SQLite table (date range, default path, stdout, `--push` success, errors and exceptions, empty range, option validation, invalid record).

CI runs Pint and PHPStan, then the test matrix of PHP 8.2 / 8.3 / 8.4 × Laravel 12, plus one `--prefer-lowest` job.

## Roadmap

- [ ] Item-invoice mode (`ALLINVENTORYENTRIES.LIST`, stock items, HSN/SAC, units)
- [ ] Credit notes and debit notes
- [ ] Purchase vouchers with input-tax ledgers
- [ ] `REMOTEID` / GUID support so re-exports alter instead of duplicating
- [ ] Optional export of nested GST registration tags for newer TallyPrime releases
- [ ] Reading data back from Tally (ledger balances, outstanding bills) via export requests
- [ ] Queued push with retry for large batches

## License

MIT © 2026 [Pirainuthal S](https://github.com/Pirainuthal). See [LICENSE](LICENSE).
