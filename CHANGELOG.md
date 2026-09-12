# Changelog

All notable changes to the Payzum gateway for WHMCS are documented here.
This project follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
