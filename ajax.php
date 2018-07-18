<?php

$err = [
    "result" => "Error",
    "refundTransactionId" => "(n/a)",
    "errorMessage" => ""
];

if( !array_key_exists( "clientId", $_POST ) || !strlen( trim( $_POST[ "clientId" ] ) ) ) {
    $err[ 'errorMessage' ] = "Client ID was not provided.";
    die( json_encode( $err ) );
}

if( !array_key_exists( "secret", $_POST ) || !strlen( trim( $_POST[ "secret" ] ) ) ) {
    $err[ 'errorMessage' ] = "Secret was not provided.";
    die( json_encode( $err ) );
}

if( ( !array_key_exists( "transactionId", $_POST ) || !strlen( trim( $_POST[ "transactionId" ] ) ) ) && ( !array_key_exists( "orderId", $_POST ) || !strlen( trim( $_POST[ "orderId" ] ) ) ) ) {
    $err[ 'errorMessage' ] = "Transaction ID or order ID was not provided.";
    die( json_encode( $err ) );
}

if( !array_key_exists( "fullRefund", $_POST ) || !strlen( trim( $_POST[ "fullRefund" ] ) ) ) {
    $err[ 'errorMessage' ] = "Refund type was not provided.";
    die( json_encode( $err ) );
}

if( !array_key_exists( "environment", $_POST ) || !strlen( trim( $_POST[ "environment" ] ) ) ) {
    $err[ 'errorMessage' ] = "Environment was not provided.";
    die( json_encode( $err ) );
}

$clientId = trim( $_POST[ "clientId" ] );
$secret = trim( $_POST[ "secret" ] );
if( array_key_exists( 'transactionId', $_POST ) && strlen( trim( $_POST[ 'transactionId' ] ) ) ) {
    $transactionId = trim( $_POST[ 'transactionId' ] );
} else {
    $transactionId = false;
}

if( array_key_exists( 'orderId', $_POST ) && strlen( trim( $_POST[ 'orderId' ] ) ) ) {
    $orderId = trim( $_POST[ 'orderId' ] );
} else {
    $orderId = false;
}

$fullRefund = ( trim( $_POST[ "fullRefund" ] ) == "1" ) ? true : false;
$environment = trim( $_POST[ "environment" ] );
$host = ( $environment == "live" ) ? "api.paypal.com" : "api.sandbox.paypal.com";

if( $transactionId && $orderId ) {
    $err[ 'errorMessage' ] = "Transaction ID and order ID cannot both be provided; please only provide one or the other.";
    die( json_encode( $err ) );
}

if( array_key_exists( "merchantId", $_POST ) && strlen( trim( $_POST[ "merchantId" ] ) ) ) {
    $merchantId = trim( $_POST[ "merchantId" ] );
} else {
    $merchantId = false;
}

if( !$fullRefund ) {
    if( !array_key_exists( "amount", $_POST ) || !strlen( trim( $_POST[ "amount" ] ) ) ) {
	$err[ 'errorMessage' ] = "Partial refund was specified, but refund amount was not provided.";
	die( json_encode( $err ) );
    }
    if( !array_key_exists( "currency", $_POST ) || !strlen( trim( $_POST[ "currency" ] ) ) ) {
	$err[ 'errorMessage' ] = "Partial refund was specified, but refund currency was not provided.";
	die( json_encode( $err ) );
    }

    $amount = trim( $_POST[ "amount" ] );
    $currency = trim( $_POST[ "currency" ] );
}

// Did we get an order ID?  If so, then we need to look it up first and grab the transaction ID from that.
if( $orderId ) {
    // Get an access token for the caller first.
    $accessToken = getAccessToken( $clientId, $secret );

    // Fetch the order details.
    $curl = curl_init( "https://$host/v1/checkout/orders/$orderId" );
    if( !$curl ) {
	$err[ 'errorMessage' ] = "An internal error occurred.  Your refund request was NOT processed.";
	die( json_encode( $err ) );
    }

    if( !curl_setopt( $curl, CURLOPT_HTTPHEADER, [ "Authorization: Bearer $accessToken" ] ) ||
	!curl_setopt( $curl, CURLOPT_RETURNTRANSFER, true ) ) {
	$err[ 'errorMessage' ] = "An internal error occurred.  Your refund request was NOT processed.";
	die( json_encode( $err ) );
    }

    $response = curl_exec( $curl );

    if( !$response ) {
	$err[ 'errorMessage' ] = "Error communicating with PayPal.  Your refund request was NOT processed.";
	die( json_encode( $err ) );
    }

    $result = json_decode( $response );

    if( $result === NULL ) {
	$err[ 'errorMessage' ] = "Error in the response from PayPal.  Your refund request was NOT processed.";
	die( json_encode( $err ) );
    }

    $response_code = curl_getinfo( $curl, CURLINFO_RESPONSE_CODE );

    if( $response_code >= 400 ) {
	$err[ 'errorMessage' ] = "PayPal returned an error.  Your refund request was NOT processed.";
	die( json_encode( $err ) );
    }

    if( !property_exists( $result, 'purchase_units' ) || !is_array( $result->purchase_units ) ) {
	error_die( "Error in the response from PayPal.  Your refund request was NOT processed." );
    }

    $transactions = [];
    foreach( $result->purchase_units as $unit ) {
	if( !property_exists( $unit, 'payment_summary' ) || !is_object( $unit->payment_summary ) ) {
	    continue;
	}

	if( !property_exists( $unit, "payee" ) || !is_object( $unit->payee ) || !property_exists( $unit->payee, "email" ) || !strlen( trim( $unit->payee->email ) ) ) {
	    continue;
	}

	$payeeEmail = $unit->payee->email;

	if( property_exists( $unit->payment_summary, 'captures' ) && is_array( $unit->payment_summary->captures ) ) {
	    foreach( $unit->payment_summary->captures as $capture ) {
		if( property_exists( $capture, 'id' ) && strlen( trim( $capture->id ) ) && property_exists( $capture, 'status' ) && ( $capture->status == 'COMPLETED' || $capture->status == 'PARTIALLY_REFUNDED' ) && property_exists( $capture, 'amount' ) && is_object( $capture->amount ) && property_exists( $capture->amount, 'total' ) && strlen( trim( $capture->amount->total ) ) && property_exists( $capture->amount, 'currency' ) && strlen( trim( $capture->amount->currency ) ) ) {
		    $transactions[ $capture->id ] = [
			'payee' => $payeeEmail,
			'total' => $capture->amount->total,
			'refundableTotal' => $capture->amount->total,
			'currency' => $capture->amount->currency
		    ];
		}
	    }
	}

	if( property_exists( $unit->payment_summary, 'sales' ) && is_array( $unit->payment_summary->sales ) ) {
	    foreach( $unit->payment_summary->sales as $capture ) {
		if( property_exists( $capture, 'id' ) && strlen( trim( $capture->id ) ) && property_exists( $capture, 'status' ) && ( $capture->status == 'COMPLETED' || $capture->status == 'PARTIALLY_REFUNDED' ) && property_exists( $capture, 'amount' ) && is_object( $capture->amount ) && property_exists( $capture->amount, 'total' ) && strlen( trim( $capture->amount->total ) ) && property_exists( $capture->amount, 'currency' ) && strlen( trim( $capture->amount->currency ) ) ) {
		    $transactions[ $capture->id ] = [
			'payee' => $payeeEmail,
			'total' => $capture->amount->total,
			'refundableTotal' => $capture->amount->total,
			'currency' => $capture->amount->currency
		    ];
		}
	    }
	}

	if( property_exists( $unit->payment_summary, 'refunds' ) && is_array( $unit->payment_summary->refunds ) ) {
	    foreach( $unit->payment_summary->refunds as $refund ) {
		$id = false;
		if( property_exists( $refund, 'capture_id' ) && strlen( trim( $refund->capture_id ) ) ) {
		    $id = trim( $refund->capture_id );
		} else if( property_exists( $refund, 'sale_id' ) && strlen( trim( $refund->sale_id ) ) ) {
		    $id = trim( $refund->sale_id );
		}

		if( $id && property_exists( $refund, 'status' ) && $refund->status != "FAILED" && property_exists( $refund, 'amount' ) && is_object( $refund->amount ) && property_exists( $refund->amount, 'total' ) && strlen( trim( $refund->amount->total ) ) && array_key_exists( $id, $transactions ) ) {
		    $transactions[ $id ][ 'refundableTotal' ] -= $refund->amount->total;
		}
	    }
	}
    }

    if( count( $transactions ) > 1 ) {
	$out = [];
	foreach( $transactions as $id => $trx ) {
	    if( $trx[ 'refundableTotal' ] <= 0 ) {
		continue;
	    }
	    
	    $out[] = $id . " (seller: " . $trx[ 'payee' ] . '; amount available to refund: ' . $trx[ 'refundableTotal' ] . ' ' . $trx[ 'currency' ] . ")";
	}

	error_die( "There is more than one refundable transaction on this order; please refund by one of the following transaction IDs:<ul><li>" . implode( "</li><li>", $out ) . "</li></ul>" );
    }

    if( !count( $transactions ) ) {
	error_die( "There are no refundable transactions associated with this order." );
    }

    foreach( $transactions as $id => $trx ) {
	$transactionId = $id;
    }
}    

// Ok, let's fetch an access token.

$accessToken = getAccessToken( $clientId, $secret, $merchantId );

// Ok, start putting together our refund request.
$curl = curl_init( "https://$host/v1/payments/sale/$transactionId/refund" );

if( !$curl ) {
    $err[ 'errorMessage' ] = "An internal error occurred.  Your refund request was NOT processed.";
    die( json_encode( $err ) );
}

if( $fullRefund ) {
    $req = "{}";
} else {
    $req = [
	'amount' => [
	    'total' => $amount,
	    'currency' => $currency
	]
    ];

    $req = json_encode( $req );
}

if( !curl_setopt( $curl, CURLOPT_POST, true ) ||
    !curl_setopt( $curl, CURLOPT_POSTFIELDS, $req ) ||
    !curl_setopt( $curl, CURLOPT_USERPWD, "$clientId:$secret" ) ||
    !curl_setopt( $curl, CURLOPT_HTTPHEADER, [ "Content-Type: application/json", "PayPal-Request-Id: " . bin2hex( openssl_random_pseudo_bytes( 16 ) ), "Authorization: Bearer $accessToken" ] ) ||
    !curl_setopt( $curl, CURLOPT_RETURNTRANSFER, true ) ) {
    $err[ 'errorMessage' ] = "An internal error occurred.  Your refund request was NOT processed.";
    die( json_encode( $err ) );
}

$max_retries = 5;
$retries = 0;
while( $retries < $max_retries ) {
    $retries++;
    $response = curl_exec( $curl );

    if( !$response ) {
	$err[ 'errorMessage' ] = "Error communicating with PayPal.  Unable to determine whether the refund was processed.";
	continue;
    }

    $result = json_decode( $response );
    if( $result === NULL ) {
	$err[ 'errorMessage' ] = "Error in the response from PayPal.  Unable to determine whether the refund was processed.";
	continue;
    }

    $response_code = curl_getinfo( $curl, CURLINFO_RESPONSE_CODE );
    if( $response_code >= 500 ) {
	if( property_exists( $result, "message" ) ) {
	    $err[ 'errorMessage' ] = "PayPal returned an error.  Unable to determine whether the refund was processed.  Error message from PayPal: " . $result->message;
	    continue;
	}

	$err[ 'errorMessage' ] = "PayPal returned an error.  Unable to determine whether your request has been processed.";
	continue;
    } else if( $response_code >= 400 ) {
	if( property_exists( $result, "message" ) ) {
	    $err[ 'errorMessage' ] = "PayPal returned an error.  The refund was likely not processed.  Error message from PayPal: " . $result->message;
	} else {
	    $err[ 'errorMessage' ] = "PayPal returned an error.  The refund was likely not processed.";
	}

	die(json_encode( $err ) );
    }

    if( !property_exists( $result, "id" ) || !strlen( trim( $result->id ) ) ) {
	$err[ 'errorMessage' ] = "PayPal indicated that the refund was successful, but the response did not contain a transaction ID.";
	continue;
    }

    if( property_exists( $result, "state" ) ) {
	if( $result->state == "completed" ) {
	    $state = "Success (refund completed successfully)";
	} else if( $result->state == "pending" ) {
	    $state = "Success (refund is pending)";
	} else {
	    $state = "Success";
	}
    } else {
	$state = "Success";
    }

    $response = [
	'result' => $state,
	'refundTransactionId' => trim( $result->id ),
	'errorMessage' => '(n/a)'
    ];

    die( json_encode( $response ) );
}

die( json_encode( $err ) );

function getAccessToken( $clientId, $secret, $merchantId = false ) {
    global $err, $host;
    
    $request = [
	'grant_type' => "client_credentials"
    ];

    if( $merchantId ) {
	$request[ 'target_subject' ] = $merchantId;
    }

    $curl = curl_init( "https://$host/v1/oauth2/token");

    if( !$curl ) {
	$err[ 'errorMessage' ] = "An internal error occurred.  Your refund request was NOT processed.";
	die( json_encode( $err ) );
    }

    if( !curl_setopt( $curl, CURLOPT_POST, true ) ||
	!curl_setopt( $curl, CURLOPT_POSTFIELDS, http_build_query( $request ) ) ||
	!curl_setopt( $curl, CURLOPT_USERPWD, "$clientId:$secret" ) ||
	!curl_setopt( $curl, CURLOPT_RETURNTRANSFER, true ) ) {
	$err[ 'errorMessage' ] = "An internal error occurred.  Your refund request was NOT processed.";
	die( json_encode( $err ) );
    }

    $response = curl_exec( $curl );

    if( !$response ) {
	$err[ 'errorMessage' ] = "Error occurred while communicating with PayPal.  Your refund request was NOT processed.";
	die( json_encode( $err ) );
    }

    $result = json_decode( $response );

    if( $result === NULL ) {
	$err[ 'errorMessage' ] = "Error in the response received from PayPal.  Your refund request was NOT processed.";
	die( json_encode( $err ) );
    }

    $response_code = curl_getinfo( $curl, CURLINFO_RESPONSE_CODE );

    curl_close( $curl );

    if( $response_code >= 400 ) {
	if( property_exists( $result, "error" ) && strlen( trim( $result->error ) ) ) {
	    if( $result->error == "invalid_client" ) {
		$err[ 'errorMessage' ] = "PayPal returned an error indicating that your credentials are incorrect.  Double-check your client ID and secret.  Additionally, make sure that you are using the correct credentials for the environment you have selected -- your credentials will be different for the Sandbox and Live environments.";
		die( json_encode( $err ) );
	    }

	    if( $result->error == "invalid_request" ) {
		if( property_exists( $result, "error_description" ) && $result->error_description == "No permissions to set target_client_id" ) {
		    $err[ 'errorMessage' ] = "PayPal returned an error indicating that you do not have any API permissions in place with the merchant.  Double-check that the merchant ID you provided is correct and that the merchant has granted API permissions to you.";
		    die( json_encode( $err ) );
		}
	    }

	    if( $result->error == "invalid_subject" ) {
		$err[ 'errorMessage' ] = "PayPal returned an error indicating that the merchant ID you provided is invalid.  Double-check that the merchant ID you provided is correct.";
		die( json_encode( $err ) );
	    }
	}
    
	// Was there an error_description in the response?
	if( property_exists( $result, "error_description" ) && strlen( trim( $result->error_description ) ) ) {
	    $err[ 'errorMessage' ] = "PayPal returned an error.  Your refund request was NOT processed.  Error message from PayPal: " . $result->error_description;
	    die( json_encode( $err ) );
	}
	
	$err[ 'errorMessage' ] = "PayPal returned an error.  Your refund request was NOT processed.";
	die( json_encode( $err ) );
    }

    if( !property_exists( $result, "access_token" ) || !strlen( $result->access_token ) ) {
	$err[ 'errorMessage' ] = "There was an error in the response from PayPal.  Your refund request was NOT processed.";
	die( json_encode( $err ) );
    }

    return $result->access_token;
}

function error_die( $msg ) {
    $err = [
	"result" => "Error",
	"refundTransactionId" => "(n/a)",
	"errorMessage" => $msg
    ];

    die( json_encode( $err ) );
}

