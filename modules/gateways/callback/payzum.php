<?php
/**
 * Payzum IPN callback for WHMCS.
 *
 * The SDK's Verifier does the dangerous parts: it reads the correct, fixed signature header
 * itself (x-nowpayments-sig — case-insensitively, CGI form included), verifies HMAC-SHA-512 over
 * the RAW bytes in constant time, and enforces the 10-minute replay window on the signed
 * event_at. Deduplication is platform-native: checkCbTransID rejects a transaction id (the
 * Payzum payment_id) that was already applied, so delivery retries are a no-op.
 *
 * Verification deliberately uses `new Verifier($secret)` and not the Payzum entry class:
 * verifying an already-paid IPN must not depend on the API key being configured.
 *
 * Only `finished` settles the invoice (it also covers overpayment — the merchant surface reports
 * an overpaid invoice as finished). Everything else, including the three non-invoice event
 * families (late_deposit_received, wrong_token_received, suspicious_token_received) whose
 * payloads carry no known payment_status, lands in the gateway log with its full body.
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/../payzum/vendor/autoload.php';
// The gateway module itself, for payzum_stored_payment(): the mod_payzum_payments row records
// what the Payzum invoice was raised for, which is what the settled amount has to be checked
// against below. Only function definitions — including it has no side effects.
require_once __DIR__ . '/../payzum.php';

use Payzum\Errors\PayzumException;
use Payzum\Errors\SignatureException;
use Payzum\PaymentStatus;
use Payzum\Webhooks\Verifier;

$gatewayModuleName = 'payzum';
$gatewayParams     = getGatewayVariables( $gatewayModuleName );

if ( ! $gatewayParams['type'] ) {
	http_response_code( 500 );
	die( 'Module not activated' );
}

// 1) Read the raw body and verify the signature before trusting anything.
$rawBody = file_get_contents( 'php://input' );
if ( '' === $rawBody || false === $rawBody ) {
	http_response_code( 400 );
	die( 'empty body' );
}

$secret = isset( $gatewayParams['webhookSecret'] ) ? (string) $gatewayParams['webhookSecret'] : '';
if ( '' === $secret ) {
	logTransaction( $gatewayModuleName, array( 'reason' => 'no webhook secret configured' ), 'Unverified' );
	http_response_code( 500 );
	die( 'not configured' );
}

// getallheaders() when the SAPI provides it, otherwise the raw $_SERVER array — the Verifier
// accepts the CGI form (HTTP_X_NOWPAYMENTS_SIG) directly.
$headers = function_exists( 'getallheaders' ) ? getallheaders() : false;
if ( ! is_array( $headers ) ) {
	$headers = array_filter( $_SERVER, 'is_string' );
}

// Only a fingerprint of an unverified body is logged, never the body itself.
//
// This callback is public and answers before any authentication, so whatever is logged here is
// chosen by whoever posts to it. Storing the full payload let anyone fill tblgatewaylog — and
// the disk — by posting megabytes of nothing, repeatedly. The size and hash are enough to tell
// two bad deliveries apart when diagnosing, and cost a fixed number of bytes per attempt.
$unverified = static function ( $rawBody, $reason ) {
	return array(
		'reason'    => $reason,
		'body_size' => strlen( $rawBody ),
		'body_sha'  => substr( hash( 'sha256', $rawBody ), 0, 16 ),
	);
};

try {
	$verifier = new Verifier( $secret );
	$data     = $verifier->verifyPaymentIpn( $rawBody, $headers );
} catch ( SignatureException $e ) {
	logTransaction( $gatewayModuleName, $unverified( $rawBody, $e->reason ), 'Unverified' );
	http_response_code( 401 );
	die( 'bad signature' );
} catch ( PayzumException $e ) {
	logTransaction( $gatewayModuleName, $unverified( $rawBody, $e->getMessage() ), 'Unverified' );
	http_response_code( 400 );
	die( 'bad json' );
}

$invoiceId = isset( $data['order_id'] ) ? (int) $data['order_id'] : 0;
$status    = isset( $data['payment_status'] ) ? (string) $data['payment_status'] : '';
// Payzum returns the payment id as `payment_id` (alias `id` in older docs); accept either.
$paymentId = isset( $data['payment_id'] ) ? (string) $data['payment_id'] : ( isset( $data['id'] ) ? (string) $data['id'] : '' );
$transId   = '' !== $paymentId ? $paymentId : ( $invoiceId . '-' . $status );

// 2) Validate the invoice belongs to this gateway.
$invoiceId = checkCbInvoiceID( $invoiceId, $gatewayModuleName );

// 3) Map the status through the SDK's enum. An unknown value is a contract change: log it with
// the full body and acknowledge — a 500 here would just burn the five delivery retries and
// dead-letter an event no retry can fix.
try {
	$mapped = PaymentStatus::fromMerchant( $status );
} catch ( PayzumException $e ) {
	logTransaction( $gatewayModuleName, $data, 'Unrecognised event (payment_status "' . $status . '")' );
	http_response_code( 200 );
	die( 'ok' );
}

// 4) Apply only the terminal "paid" transition; log the rest.
if ( $mapped->isPaid() ) {
	// `finished` only says the Payzum invoice settled — it says nothing about that invoice
	// having been raised for THIS invoice's total. Crediting on the status alone marks an
	// invoice paid off a settlement for less, or in a cheaper currency. mod_payzum_payments
	// holds what we asked Payzum to charge, so it is the thing to check against.
	$expected = payzum_stored_payment( $invoiceId );
	$mismatch = payzum_settlement_mismatch( $expected, $data );
	if ( null !== $mismatch ) {
		// Not transient: a retry would carry the same numbers and mismatch again. Acknowledge
		// so the delivery stops, and leave the whole payload in the gateway log for the admin.
		logTransaction( $gatewayModuleName, $data, 'Not credited — ' . $mismatch );
		http_response_code( 200 );
		die( 'ok' );
	}

	// The amount has to be usable BEFORE checkCbTransID, not after. checkCbTransID consumes the
	// transaction id permanently: once it has been recorded, a corrected redelivery carrying the
	// same payment_id is rejected as a duplicate. So crediting `(float) null` = 0.00 here would
	// not just be a wrong payment, it would be an unfixable one — the invoice keeps a 0.00
	// payment applied and no retry can ever replace it. price_amount is `required` in the
	// contract, so anything non-numeric or <= 0 is a contract break or a forgery: log it, leave
	// the transaction id unconsumed, and acknowledge so Payzum stops retrying an event that will
	// not change.
	if ( ! isset( $data['price_amount'] ) || ! is_numeric( $data['price_amount'] ) || (float) $data['price_amount'] <= 0 ) {
		logTransaction( $gatewayModuleName, $data, 'Not credited — no usable price_amount' );
		http_response_code( 200 );
		die( 'ok' );
	}

	$amount = (float) $data['price_amount'];

	checkCbTransID( $transId ); // rejects duplicates — delivery retries reuse the payment id

	addInvoicePayment(
		$invoiceId,
		$transId,
		$amount,
		0, // fee — settled to the merchant wallet, not deducted here
		$gatewayModuleName
	);

	logTransaction( $gatewayModuleName, $data, 'Successful' );
} else {
	logTransaction( $gatewayModuleName, $data, 'Status: ' . $mapped->value );
}

http_response_code( 200 );
echo 'ok';
