# Payzum for WHMCS — Accept Crypto & Stablecoin Payments (USDC, USDT)

Accept **cryptocurrency and stablecoin payments** (USDC, USDT and more, multi-chain) in
[WHMCS](https://www.whmcs.com) through [Payzum](https://payzum.com) —
**non-custodial**: funds settle directly to your own wallet, Payzum never takes
custody. **Zero chargebacks** — a major win for hosting billing, where
chargeback fraud on VPS and domain orders is routine.

- **Module:** `payzum` (Third Party Gateway) · **Version:** 1.0.0 · **License:** MIT
- **Requires:** WHMCS (gateway API 1.1), PHP ≥ 8.1 with `ext-curl`
- The official [`payzum/payzum-php`](https://packagist.org/packages/payzum/payzum-php)
  SDK is vendored — no composer step on the server.

## How it works

1. The client opens their invoice and clicks **Pay with Crypto**. The module
   creates a Payzum hosted-checkout invoice and sends them to it (QR code +
   deposit address, live status), where they pick the coin and chain. No wallet
   data touches your server.
2. Crypto confirmation is **asynchronous**, so the WHMCS invoice is marked paid
   from Payzum's signed server-to-server IPN callback, never from the client's
   browser — a closed tab never loses a paid invoice.
3. Every callback is verified with **HMAC-SHA-512 over the raw request bytes**
   (constant-time compare, 10-minute replay window) before a single field of it
   is read. The paid amount and currency are re-checked against the invoice
   before it is credited, and WHMCS's own transaction-id guard
   (`checkCbTransID`) makes delivery retries a no-op.

## Features

- **Stablecoin-first**: USDC and USDT across multiple chains (Polygon,
  Ethereum, Arbitrum, Base, Optimism, Tron, Solana and more), plus major
  cryptocurrencies — the client picks the coin on the hosted checkout.
- **Non-custodial** — payments settle to your own wallet.
- **No chargebacks** — crypto payments are final.
- **Duplicate-invoice protection** — one row per WHMCS invoice (in
  `mod_payzum_payments`) maps to the open Payzum invoice; refreshing the pay
  page reuses it while it is open for the same amount, and a changed amount
  (credit applied, invoice edited) mints a fresh one.
- **Amount verification before crediting** — a mismatched or unreadable amount
  is logged, never credited.
- **Production / staging selector** built into the gateway settings.

## Installation

**From the release zip (recommended).** Download
[`payzum-whmcs-1.0.0.zip`](https://github.com/payzum-dev/whmcs-payzum/releases/latest) and unzip it
at your WHMCS root — the archive already mirrors the tree below.

**From a clone.** Copy the `modules/` tree of this repository into your WHMCS root:

```
modules/gateways/payzum.php              → <whmcs>/modules/gateways/payzum.php
modules/gateways/payzum/                 → <whmcs>/modules/gateways/payzum/
modules/gateways/callback/payzum.php     → <whmcs>/modules/gateways/callback/payzum.php
```

Then activate **Payzum (Crypto & Stablecoins)** under
**Configuration → System Settings → Payment Gateways**.

## Configuration

| Setting | Meaning |
|---|---|
| API Key | 64-hex key from your [Payzum merchant dashboard](https://merchant.payzum.com) (Dashboard → Developers → API keys) |
| Webhook secret | IPN signing secret from your Payzum webhook settings — verifies the HMAC-SHA-512 signature |
| Environment | Production (`merchant.payzum.com`) or staging (`staging.payzum.com`; staging needs its own API key) |

The IPN callback URL is
`https://<your-whmcs>/modules/gateways/callback/payzum.php`. The signature
header is fixed and read by the SDK itself — nothing to configure.

## Invoice status mapping

| Payzum payment status | WHMCS effect |
|---|---|
| `finished` | Invoice credited via `addInvoicePayment` (covers overpayment, which the merchant surface reports as `finished`) |
| anything else | Logged to the gateway log with its full body; invoice untouched |

Only a verified `finished` settles the invoice. Underpaid (`partially_paid`),
expired and failed payments never credit anything, and the three non-invoice
event families (`late_deposit_received`, `wrong_token_received`,
`suspicious_token_received`) are logged for the operator instead of being
silently dropped.

## FAQ

**Can WHMCS accept USDT or USDC payments?**
Yes — with this module a client pays any WHMCS invoice in USDT, USDC or other
supported assets from any wallet, and the invoice is credited automatically.

**Is Payzum custodial?**
No. Funds settle directly to your own wallet — Payzum never holds your money.

**Does this eliminate chargebacks on hosting orders?**
Yes. Crypto payments are final, so there is no chargeback path — no more
losing a VPS plus the money plus a dispute fee.

**What happens if the client pays and closes the browser?**
Nothing is lost. The invoice is settled from Payzum's signed IPN callback,
which is server-to-server and retried on failure.

**Can the client accidentally pay twice?**
The module reuses the open Payzum invoice for a WHMCS invoice across page
refreshes, shows an "awaiting confirmation" notice once the payment is
received, and WHMCS's transaction-id guard rejects duplicate credits.

**Does it need composer on the server?**
No. The official Payzum PHP SDK is vendored inside the module.

## Related Payzum integrations

Payzum ships official plugins for most major e-commerce, billing and donation
platforms — WooCommerce, Magento 2, PrestaShop, Shopware 6, OpenCart,
Zen Cart, nopCommerce, Ecwid, BigCommerce, Shopify, Wix, Medusa, Vendure,
Saleor, Sylius, Easy Digital Downloads, GiveWP, Paid Memberships Pro, Blesta,
HostBill, ClientExec, pretix, Frappe/ERPNext, Akaunting and django-payments —
plus official SDKs for PHP, Node.js/TypeScript, Python and Rust. Browse them
all at [github.com/payzum-dev](https://github.com/payzum-dev).

## About Payzum

[Payzum](https://payzum.com) is a non-custodial crypto payment gateway for
merchants: accept USDC, USDT and other digital assets with settlement straight
to your own wallet, optional auto-conversion to stablecoins, and a single REST
API. API docs: [merchant.payzum.com/api/docs](https://merchant.payzum.com/api/docs).

## License

[MIT](LICENSE). Contributed and maintained by Payzum.
