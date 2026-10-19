# Changelog

All notable changes to the Payzum gateway for WHMCS are documented here.
This project follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] — 2026-10-19

### Fixed
- Settled payments were recorded with a fee of `0`. Payzum deducts its fee (0.5% per sale) from
  what reaches the merchant's wallet, so every payment left WHMCS's books above the wallet by the
  fee. The callback now passes the fee to `addInvoicePayment`: the invoice is still paid in full,
  and the fee appears as the gateway fee on the transaction. The settled amount and fee are also
  written to the gateway log (`recorded_fee`).

### Added
- **Payzum fee (%)** gateway setting, `0.5` by default. Change it only if your Payzum agreement
  uses a different rate. Installs upgraded from 1.0.0 that never save the new setting use the
  default; an unreadable value (not a number, below 0 or above 100) also falls back to the default
  and is logged, because understating the fee is the drift this release removes.

## [1.0.0] — 2026-09-03

First release with a declared version. Everything below shipped today.

### Fixed
- The callback now verifies the amount and currency paid against the invoice before crediting it,
  and the check runs **before** WHMCS's transaction-id guard. That id is consumed permanently, so a
  payment credited for the wrong amount would not merely be a bad entry — it could not be retried at
  all.
- An amount that cannot be read (missing, empty, or not a number) now counts as a mismatch. The
  first version of the check skipped the comparison in that case and credited the invoice
  unverified.
- The callback is a public URL, and it was writing the **raw body of every unsigned POST** into
  `tblgatewaylog` — megabytes per request, repeatable by anyone who found the URL. It now logs only
  the size and a truncated hash, which is what is actually useful for diagnosis.
