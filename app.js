$(document).ready(function() {
    $("#full_refund_no").click(function() {
	$("#amount,#currency").removeAttr("disabled");
    });

    $("#full_refund_yes").click(function() {
	$("#amount,#currency").attr("disabled", "disabled");
    });

    $("#process").click(function() {
	var clientId = $("#client_id").val();
	var secret = $("#secret").val().trim();
	var merchantId = $("#merchant_id").val().trim();
	var transactionId = $("#transaction_id").val().trim();
	var amount = $("#amount").val().trim();
	var currency = $("#currency").val().trim();
	var fullRefund = $("#full_refund_yes").filter(":checked").length;
	var environment = $("#environment_live").filter(":checked").length ? "live" : "sandbox";
	var orderId = $("#order_id").val().trim();

	var errors = [];
	if(!clientId.length) {
	    errors.push("Client ID is required.");
	}

	if(!secret.length) {
	    errors.push("Secret is required.");
	}

	if(transactionId.length && orderId.length) {
	    errors.push("Please only enter a transaction ID or an order ID -- not both.");
	} else if(!transactionId.length && !orderId.length) {
	    errors.push("Please enter either a transaction ID or an order ID.");
	}

	if(!fullRefund) {
	    if(!amount.length) {
		errors.push("When doing a partial refund, you must enter the refund amount.");
	    }
	    if(!currency.length) {
		errors.push("When doing a partial refund, you must enter the refund currency.");
	    }
	}

	if(errors.length) {
	    window.alert("Please fix the following errors before continuing:\n\n" + errors.join("\n"));
	    return;
	}

	if(!merchantId.length) {
	    if(!window.confirm("Merchant ID has not been entered.  The refund will be processed against your account.  Are you sure you want to continue?\n\n(If the original transaction didn't happen on your account, then the refund will likely fail.)")) {
		return;
	    }
	}
	
	$("#process").text("Processing...please wait...");
	$("#process").attr("disabled", "disabled");

	var req = {
	    clientId: clientId,
	    secret: secret,
	    fullRefund: fullRefund,
	    environment: environment
	};

	if(!fullRefund) {
	    req.amount = amount;
	    req.currency = currency;
	}

	if(merchantId.length) {
	    req.merchantId = merchantId;
	}

	if(transactionId.length) {
	    req.transactionId = transactionId;
	} else if(orderId.length) {
	    req.orderId = orderId;
	}

	$("#result,#refund_txn_id,#errmsg").text("(pending)");

	$.ajax({
	    url: "ajax.php",
	    data: req,
	    method: "POST",
	    dataType: "json"
	}).done(function(data, status) {
	    $("#result").text(data.result);
	    $("#refund_txn_id").text(data.refundTransactionId);
	    $("#errmsg").text(data.errorMessage);
	    $("#process").text("Proess Refund").removeAttr("disabled");
	}).fail(function(jqXHR, textStatus, errorThrown) {
	    $("#result").text("Error");
	    $("#refund_txn_id").text("(n/a)");
	    switch(textStatus) {
	    case "timeout":
		$("#errmsg").text("HTTP request timed out"); break;
	    case "error":
		if(typeof errorThrown == "string" && errorThrown.length) {
		    $("#errmsg").text("HTTP error: " + errorThrown);
		} else {
		    $("#errmsg").text("HTTP error");
		}
		break;
	    case "abort":
		$("#errmsg").text("HTTP request aborted"); break;
	    case "parseerror":
		$("#errmsg").text("Parse error"); break;
	    }
	    $("#process").text("Process Refund").removeAttr("disabled");
	});
	    
    }).removeAttr("disabled");
});
