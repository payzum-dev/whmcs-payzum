<?php
/**
 * Payzum — Crypto & Stablecoin payment gateway for WHMCS.
 *
 * Third-party merchant gateway module. Creates a Payzum hosted-checkout invoice and sends the
 * client to it; the invoice is settled from the signed IPN callback. Non-custodial — funds settle
 * to the merchant's own wallet.
 *
 * Built on the official payzum/payzum-php SDK (vendored under payzum/vendor/): HTTP client, HMAC
 * verification, decimal-exact amounts and the status vocabulary all live there, written and
 * tested once.
 *
 * Duplicate-invoice guarantee: the API's Idempotency-Key is best-effort (~60 s eventual
 * consistency), so the hard guarantee is client-side — one row per WHMCS invoice in
 * mod_payzum_payments maps to the open Payzum invoice, and payzum_link() reuses it while it is
 * still open for the same amount. The table also preserves invoice_url, which only the create
 * call returns (reads never do).
 *
 * Install: copy modules/gateways/payzum.php, modules/gateways/payzum/ and
 * modules/gateways/callback/payzum.php into your WHMCS install, then activate under
 * Configuration → System Settings → Payment Gateways.
 *
 * Disclosure: contributed by Payzum. Opt-in gateway; does not change default behaviour.
 */

if ( ! defined( 'WHMCS' ) ) {
	die( 'This file cannot be accessed directly' );
}

require_once __DIR__ . '/payzum/vendor/autoload.php';

use Payzum\Errors\ApiException;
use Payzum\Errors\PayzumException;
use Payzum\PaymentStatus;
use Payzum\Payzum;

/**
 * Gateway metadata.
 *
 * @return array
 */
function payzum_MetaData() {
	return array(
		'DisplayName'                => 'Payzum (Crypto & Stablecoins)',
		// Module release version (this gateway), not the WHMCS gateway API level below.
		'Version'                    => '1.0.0',
		'APIVersion'                 => '1.1',
		'gatewayType'                => 'Third Party Gateway',
		'DisableLocalCreditCardInput' => true,
		'TokenisedStorage'           => false,
	);
}

/**
 * Admin configuration fields.
 *
 * There is deliberately no "IPN signature header" setting any more: the SDK's Verifier owns the
 * fixed header (x-nowpayments-sig) and reads it itself. The old setting was an invitation to fill
 * it with the wrong value, which is exactly how earlier releases broke — a stored
 * x-payzum-signature (the mass-payout header) made every IPN 401 while the buyer had paid.
 *
 * @return array
 */
function payzum_config() {
	return array(
		'FriendlyName'  => array(
			'Type'  => 'System',
			'Value' => 'Payzum (Crypto & Stablecoins)',
		),
		'apiKey'        => array(
			'FriendlyName' => 'API Key',
			'Type'         => 'password',
			'Size'         => '64',
			'Description'  => 'Your Payzum API key (64-hex). Dashboard → Developers → API keys.',
		),
		'webhookSecret' => array(
			'FriendlyName' => 'Webhook secret',
			'Type'         => 'password',
			'Size'         => '64',
			'Description'  => 'IPN signing secret from your Payzum webhook settings (verifies HMAC-SHA-512).',
		),
		'environment'   => array(
			'FriendlyName' => 'Environment',
			'Type'         => 'dropdown',
			'Options'      => array(
				'production' => 'Production — merchant.payzum.com',
				'staging'    => 'Staging / sandbox — staging.payzum.com (separate API keys)',
			),
			'Default'      => 'production',
			'Description'  => 'Staging is an isolated environment with its own API keys — a production key will not work there.',
		),
	);
}

/**
 * Render the "pay now" action on the invoice.
 *
 * Reuses the open Payzum invoice recorded for this WHMCS invoice (idempotent across refreshes);
 * creates one only when there is none, the recorded one reached a terminal state, or the amount
 * due changed (credit applied, invoice edited).
 *
 * @param array $params
 * @return string HTML
 */
function payzum_link( $params ) {
	$invoiceId = (string) $params['invoiceid'];
	// WHMCS hands the amount over already formatted; it travels as a string end to end. The SDK
	// writes it into the JSON as an exact number — casting to float would round it on the way out.
	$amount    = (string) $params['amount'];
	$currency  = strtolower( (string) $params['currency'] );
	$systemUrl = rtrim( (string) $params['systemurl'], '/' );
	$returnUrl = $systemUrl . '/viewinvoice.php?id=' . rawurlencode( $invoiceId );
	$label     = ! empty( $params['langpaynow'] ) ? (string) $params['langpaynow'] : 'Pay with Crypto';

	$payzum = payzum_client( $params );

	$priorPaymentId = '';
	$stored         = payzum_stored_payment( (int) $invoiceId );
	if ( null !== $stored ) {
		$priorPaymentId = (string) $stored['payment_id'];
		$reuse          = payzum_reusable_invoice( $payzum, $stored, $amount, $currency );

		if ( 'finished' === $reuse ) {
			// Paid on Payzum's side but the IPN has not landed yet. A fresh invoice here would
			// invite a second payment; tell the client to sit tight instead.
			return '<p>' . htmlspecialchars( 'Crypto payment received — awaiting confirmation. This page will update once the payment is applied.' ) . '</p>';
		}
		if ( 'error' === $reuse ) {
			// The recorded invoice may still be open; creating another one blind risks a
			// double payment, so fail soft and let the client retry.
			return '<p>' . htmlspecialchars( 'Unable to reach the crypto payment service right now. Please try again shortly.' ) . '</p>';
		}
		if ( 'stale' !== $reuse ) {
			return payzum_pay_button( $reuse, $label );
		}
	}

	try {
		$invoice = $payzum->payments->create(
			priceAmount:      $amount,
			priceCurrency:    $currency,
			// 'all' defers the coin choice to the buyer on the Payzum hosted checkout, limited to
			// the merchant's allowlist (dashboard → Merchants → Settings → Accepted tokens).
			payCurrency:      'all',
			orderId:          $invoiceId,
			orderDescription: 'WHMCS invoice ' . $invoiceId,
			ipnCallbackUrl:   $systemUrl . '/modules/gateways/callback/payzum.php',
			successUrl:       $returnUrl,
			cancelUrl:        $returnUrl,
			idempotencyKey:   payzum_idempotency_key( $invoiceId, $amount, $currency, $priorPaymentId ),
		);
	} catch ( ApiException $e ) {
		payzum_log_call( 'payments.create', array( 'invoice' => $invoiceId ), array( 'code' => $e->rawCode, 'message' => $e->getMessage() ) );
		return '<p>' . htmlspecialchars( 'Unable to start the crypto payment right now. Please try again shortly.' ) . '</p>';
	} catch ( PayzumException $e ) {
		payzum_log_call( 'payments.create', array( 'invoice' => $invoiceId ), array( 'message' => $e->getMessage() ) );
		return '<p>' . htmlspecialchars( 'Unable to start the crypto payment right now. Please try again shortly.' ) . '</p>';
	}

	$invoiceUrl = isset( $invoice['invoice_url'] ) ? (string) $invoice['invoice_url'] : '';
	if ( '' === $invoiceUrl ) {
		payzum_log_call( 'payments.create', array( 'invoice' => $invoiceId ), array( 'error' => 'no invoice_url in response' ) );
		return '<p>' . htmlspecialchars( 'Unable to start the crypto payment right now. Please try again shortly.' ) . '</p>';
	}

	payzum_store_payment(
		(int) $invoiceId,
		isset( $invoice['payment_id'] ) ? (string) $invoice['payment_id'] : '',
		$invoiceUrl,
		$amount,
		$currency
	);

	return payzum_pay_button( $invoiceUrl, $label );
}

/**
 * Whether the recorded Payzum invoice can still take this payment.
 *
 * @param Payzum $payzum
 * @param array  $stored   row from mod_payzum_payments
 * @param string $amount   amount currently due
 * @param string $currency lowercase currency code
 * @return string the invoice_url to reuse, or 'finished' | 'stale' | 'error'
 */
function payzum_reusable_invoice( Payzum $payzum, array $stored, $amount, $currency ) {
	try {
		$payment = $payzum->payments->get( (string) $stored['payment_id'] );
	} catch ( ApiException $e ) {
		if ( 404 === $e->statusCode ) {
			return 'stale';
		}
		payzum_log_call( 'payments.get', array( 'payment' => $stored['payment_id'] ), array( 'code' => $e->rawCode, 'message' => $e->getMessage() ) );
		return 'error';
	} catch ( PayzumException $e ) {
		payzum_log_call( 'payments.get', array( 'payment' => $stored['payment_id'] ), array( 'message' => $e->getMessage() ) );
		return 'error';
	}

	try {
		$status = PaymentStatus::fromMerchant( isset( $payment['payment_status'] ) ? (string) $payment['payment_status'] : '' );
	} catch ( PayzumException $e ) {
		// Unknown status = contract change. Guessing "open or closed" either way risks a
		// duplicate or a dead button; surface it and fail soft.
		payzum_log_call( 'payments.get', array( 'payment' => $stored['payment_id'] ), array( 'error' => $e->getMessage() ) );
		return 'error';
	}

	if ( $status->isPaid() ) {
		return 'finished';
	}
	if ( $status->isTerminal() ) {
		// expired | failed — the client wants to pay again, so a fresh invoice is correct.
		return 'stale';
	}

	// Open (waiting | partially_paid): reuse only while it still matches what is due. If the
	// amount changed (credit applied, invoice edited) the old invoice would charge the wrong
	// total. Comparison at display precision is enough to decide reuse.
	$invoicedAmount   = isset( $payment['price_amount'] ) ? (string) $payment['price_amount'] : '';
	$invoicedCurrency = isset( $payment['price_currency'] ) ? strtolower( (string) $payment['price_currency'] ) : '';
	if ( $invoicedCurrency !== $currency || abs( (float) $invoicedAmount - (float) $amount ) > 0.005 ) {
		return 'stale';
	}

	$url = (string) $stored['invoice_url'];
	return '' !== $url ? $url : 'stale';
}

/** The pay-now button: a GET form to the hosted checkout. */
function payzum_pay_button( $invoiceUrl, $label ) {
	return '<form action="' . htmlspecialchars( $invoiceUrl ) . '" method="get">'
		. '<input type="submit" value="' . htmlspecialchars( $label ) . '" />'
		. '</form>';
}

/**
 * The SDK entry point, pointed at the configured environment.
 *
 * @param array $params gateway params (apiKey, environment)
 * @return Payzum
 */
function payzum_client( array $params ) {
	$apiKey = trim( (string) ( isset( $params['apiKey'] ) ? $params['apiKey'] : '' ) );

	return ( isset( $params['environment'] ) && 'staging' === $params['environment'] )
		? Payzum::sandbox( $apiKey )
		: new Payzum( $apiKey );
}

/**
 * Idempotency key for invoice creation: stable across refreshes of the same state (so a rapid
 * double-render replays the same 201 instead of minting twins), but different as soon as the
 * amount changes or the prior invoice is being replaced — the API replays a keyed 201 for 24 h,
 * and replaying an expired invoice would leave the client with a dead checkout.
 *
 * Only used when the mod_payzum_payments table is available: without it the prior payment is
 * unknowable, and a key that ignores it would pin the client to the first invoice for 24 h.
 *
 * @return string|null
 */
function payzum_idempotency_key( $invoiceId, $amount, $currency, $priorPaymentId ) {
	if ( ! payzum_storage_ready() ) {
		return null;
	}
	// The date bucket caps the blast radius of a lost mod_payzum_payments row: without it, a
	// keyed replay could pin the client to an already-expired invoice for the full 24 h window.
	return 'whmcs-' . $invoiceId . '-' . substr( hash( 'sha256', $amount . '|' . $currency . '|' . $priorPaymentId . '|' . date( 'Y-m-d' ) ), 0, 32 );
}

/**
 * Whether the mod_payzum_payments table is usable, creating it on first touch.
 *
 * Gateway modules have no activation hook, so the schema is ensured lazily. Any storage failure
 * degrades to "no reuse" (a fresh invoice per render) instead of taking the invoice page down.
 *
 * @return bool
 */
function payzum_storage_ready() {
	static $ready = null;
	if ( null !== $ready ) {
		return $ready;
	}
	if ( ! class_exists( '\\WHMCS\\Database\\Capsule' ) ) {
		return $ready = false;
	}
	try {
		$schema = \WHMCS\Database\Capsule::schema();
		if ( ! $schema->hasTable( 'mod_payzum_payments' ) ) {
			$schema->create( 'mod_payzum_payments', function ( $table ) {
				$table->unsignedInteger( 'invoice_id' )->primary();
				$table->string( 'payment_id', 64 );
				$table->string( 'invoice_url', 500 );
				$table->string( 'amount', 32 );
				$table->string( 'currency', 8 );
				$table->dateTime( 'created_at' )->nullable();
			} );
		}
		return $ready = true;
	} catch ( \Throwable $e ) {
		payzum_log_call( 'storage.ensure', array(), array( 'error' => $e->getMessage() ) );
		return $ready = false;
	}
}

/**
 * The recorded Payzum invoice for a WHMCS invoice, or null.
 *
 * @param int $invoiceId
 * @return array|null
 */
function payzum_stored_payment( $invoiceId ) {
	if ( ! payzum_storage_ready() ) {
		return null;
	}
	try {
		$row = \WHMCS\Database\Capsule::table( 'mod_payzum_payments' )->where( 'invoice_id', $invoiceId )->first();
	} catch ( \Throwable $e ) {
		payzum_log_call( 'storage.lookup', array( 'invoice' => $invoiceId ), array( 'error' => $e->getMessage() ) );
		return null;
	}
	return $row ? (array) $row : null;
}

/**
 * Record (or replace) the open Payzum invoice for a WHMCS invoice.
 *
 * @param int    $invoiceId
 * @param string $paymentId
 * @param string $invoiceUrl
 * @param string $amount
 * @param string $currency
 */
function payzum_store_payment( $invoiceId, $paymentId, $invoiceUrl, $amount, $currency ) {
	if ( '' === $paymentId || ! payzum_storage_ready() ) {
		return;
	}
	try {
		\WHMCS\Database\Capsule::table( 'mod_payzum_payments' )->updateOrInsert(
			array( 'invoice_id' => $invoiceId ),
			array(
				'payment_id'  => $paymentId,
				'invoice_url' => $invoiceUrl,
				'amount'      => $amount,
				'currency'    => $currency,
				'created_at'  => date( 'Y-m-d H:i:s' ),
			)
		);
	} catch ( \Throwable $e ) {
		// Reuse is an optimisation; losing it must not lose the checkout.
		payzum_log_call( 'storage.store', array( 'invoice' => $invoiceId ), array( 'error' => $e->getMessage() ) );
	}
}

/**
 * How a settled payload differs from the invoice it claims to pay, as a human phrase, or null
 * when it matches (or cannot be checked).
 *
 * The expectation is the mod_payzum_payments row — the amount and currency the Payzum invoice was
 * actually raised for. Without a row (storage unavailable, or an invoice created before this
 * table existed) there is nothing to compare against: that is unverifiable, not a mismatch, and
 * refusing the payment would lose money that really did arrive. It is logged instead.
 *
 * Amounts are compared with half a cent of tolerance and never with `==`: the stored amount is a
 * decimal string and price_amount arrives as a JSON number, so the two round differently. Only a
 * SHORTFALL counts — an overpayment still pays the invoice.
 *
 * An amount that cannot be read as a number is NOT a pass. price_amount is `required` in the
 * contract, so null, "", an array or an object means either a contract break or a forged payload
 * — and in both cases the one number that proves the invoice was raised for this total is
 * missing. Skipping the comparison there would mark the invoice paid unverified, which is exactly
 * how an attacker turns a 1-cent invoice into a settled one. Unverifiable is treated as
 * mismatched.
 *
 * @param array|null $stored mod_payzum_payments row
 * @param array      $data   verified IPN payload
 * @return string|null
 */
function payzum_settlement_mismatch( $stored, array $data ) {
	$hasAmount    = isset( $data['price_amount'] ) && is_numeric( $data['price_amount'] );
	$paidAmount   = $hasAmount ? (float) $data['price_amount'] : 0.0;
	$paidCurrency = isset( $data['price_currency'] ) ? strtolower( trim( (string) $data['price_currency'] ) ) : '';

	if ( ! is_array( $stored ) || ! isset( $stored['amount'] ) ) {
		payzum_log_call( 'ipn.verify_amount', $data, array( 'note' => 'no mod_payzum_payments row — settlement could not be verified' ) );
		return null;
	}

	$expectedAmount   = (float) $stored['amount'];
	$expectedCurrency = isset( $stored['currency'] ) ? strtolower( trim( (string) $stored['currency'] ) ) : '';

	if ( ! $hasAmount ) {
		payzum_log_call( 'ipn.verify_amount', $data, array( 'note' => 'no usable price_amount — settlement could not be verified, not crediting' ) );
		return sprintf(
			'it reported no readable settled amount, so it cannot be shown to cover the %s %s the invoice was raised for',
			number_format( $expectedAmount, 2, '.', '' ),
			strtoupper( $expectedCurrency )
		);
	}

	if ( ( $expectedAmount - $paidAmount ) > 0.005 ) {
		return sprintf(
			'it settled %s %s while the invoice was raised for %s %s',
			number_format( $paidAmount, 2, '.', '' ),
			strtoupper( '' !== $paidCurrency ? $paidCurrency : $expectedCurrency ),
			number_format( $expectedAmount, 2, '.', '' ),
			strtoupper( $expectedCurrency )
		);
	}

	// Case-insensitive: the API echoes the currency in whatever case it stored it.
	if ( '' !== $paidCurrency && '' !== $expectedCurrency && $paidCurrency !== $expectedCurrency ) {
		return sprintf(
			'it settled in %s while the invoice was raised in %s',
			strtoupper( $paidCurrency ),
			strtoupper( $expectedCurrency )
		);
	}

	return null;
}

/** Module-call log entry, when the platform provides the logger (it does not in the harness). */
function payzum_log_call( $action, $request, $response ) {
	if ( function_exists( 'logModuleCall' ) ) {
		logModuleCall( 'payzum', $action, $request, $response );
	}
}
