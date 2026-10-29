# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added
- Fluent builders for Sales, Receipt and Journal vouchers (`TallyVoucher`).
- Ledger master builder with GSTIN validation, state, parent group, address and opening balance (`TallyLedger`).
- Automatic CGST + SGST / IGST calculation per GST rate, round-off to the nearest rupee and bill-wise references.
- Debit/credit balance check with `UnbalancedVoucherException`.
- `TallyExporter` for batches of vouchers, builders, arrays or Eloquent models via a `VoucherMapper`.
- HTTP push to Tally with `CREATED` / `ALTERED` / `ERRORS` / `LINEERROR` parsing.
- `tally:export` artisan command with `--from`, `--to`, `--output` and `--push`.
- Publishable `config/tally.php`, service provider and `Tally` facade.

### Fixed (pre-release review)
- With `applyGst()`, invoice discounts now reduce the taxable value at their GST rate (CGST Act s.15(3)(a)); `addDiscount()` takes a `gstRate`, and a discount without one is rejected instead of being taxed on the gross value. The array format accepts `gst_rate` on discounts.
- `Amount::toPaise()` converts floats via a 15-significant-digit string, so results no longer depend on PHP's `precision` ini setting.
- `tally:export --push` reports Tally `EXCEPTIONS` in its failure message instead of "0 error(s)".
