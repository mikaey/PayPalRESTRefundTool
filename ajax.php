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

if( !array_key_exists( "transactionId", $_POST ) || !strlen( trim( $_POST[ "transactionId" ] ) ) ) {
    $err[ 'errorMessage' ] = "Transaction ID was not provided.";
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
$transactionId = trim( $_POST[ "transactionId" ] );
$fullRefund = ( trim( $_POST[ "fullRefund" ] ) == "1" ) ? true : false;
$environment = trim( $_POST[ "environment" ] );

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

// Ok, let's fetch an access token.
$host = ( $environment == "live" ) ? "api.paypal.com" : "api.sandbox.paypal.com";
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

if( !property_exists( $result, "access_token" ) ) {
    $err[ 'errorMessage' ] = "There was an error int he response from PayPal.  Your refund request was NOT processed.";
    die( json_encode( $err ) );
}

$accessToken = $result->access_token;

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
