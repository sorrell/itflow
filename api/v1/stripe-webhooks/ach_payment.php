<?php

/**
 * Stripe Webhook Handler for ACH Payments
 * 
 * This script handles the charge.succeeded webhook event from Stripe,
 * specifically for ACH payments that were previously in a processing state.
 * 
 * It verifies the webhook signature, checks that it's an ACH payment,
 * and then updates the invoice status and creates payment records.
 */

// Required configuration
require_once '../../../plugins/stripe-php/init.php'; // Stripe PHP library
require_once __DIR__ . '../../../../functions.php';
require_once __DIR__ . "../../../../config.php";

// JSON header
header('Content-Type: application/json');

// POST data
$payload = file_get_contents('php://input');
$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'];

// Define constants
define("WORDING_WEBHOOK_FAILED", "Webhook verification failed");

// Get Stripe configuration from database
$stripe_vars = mysqli_fetch_array(mysqli_query($mysqli, "SELECT config_stripe_enable, config_stripe_secret, config_stripe_webhook_secret, config_stripe_account, config_stripe_expense_vendor, config_stripe_expense_category, config_stripe_percentage_fee, config_stripe_flat_fee FROM settings WHERE company_id = 1"));
$config_stripe_enable = intval($stripe_vars['config_stripe_enable']);
$config_stripe_secret = $stripe_vars['config_stripe_secret'];
$config_stripe_webhook_secret = $stripe_vars['config_stripe_webhook_secret'];
$config_stripe_account = intval($stripe_vars['config_stripe_account']);
$config_stripe_expense_vendor = intval($stripe_vars['config_stripe_expense_vendor']);
$config_stripe_expense_category = intval($stripe_vars['config_stripe_expense_category']);
$config_stripe_percentage_fee = floatval($stripe_vars['config_stripe_percentage_fee']);
$config_stripe_flat_fee = floatval($stripe_vars['config_stripe_flat_fee']);

// Verify Stripe is configured
if ($config_stripe_enable == 0 || $config_stripe_account == 0 || empty($config_stripe_secret) || empty($config_stripe_webhook_secret)) {
    http_response_code(500);
    error_log("Stripe webhook error - Stripe not properly configured");
    exit("Stripe not properly configured");
}

// Verify webhook signature
try {
    \Stripe\Stripe::setApiKey($config_stripe_secret);
    $event = \Stripe\Webhook::constructEvent(
        $payload, $sig_header, $config_stripe_webhook_secret
    );
} catch (\UnexpectedValueException $e) {
    // Invalid payload
    http_response_code(400);
    error_log("Stripe webhook error - Invalid payload: " . $e->getMessage());
    exit(WORDING_WEBHOOK_FAILED);
} catch (\Stripe\Exception\SignatureVerificationException $e) {
    // Invalid signature
    http_response_code(400);
    error_log("Stripe webhook error - Invalid signature: " . $e->getMessage());
    exit(WORDING_WEBHOOK_FAILED);
}

// Check if this is a charge.succeeded event
if ($event->type !== 'charge.succeeded') {
    // Not a charge.succeeded event, ignore
    http_response_code(200);
    exit("Event type not handled");
}

// Get the charge object
$charge = $event->data->object;

// Make sure this is an ACH payment (us_bank_account)
if (!isset($charge->payment_method_details->type) || $charge->payment_method_details->type !== 'us_bank_account') {
    // Not an ACH payment, ignore
    http_response_code(200);
    exit("Not an ACH payment");
}

// Extract metadata from the charge
if (!isset($charge->metadata->itflow_invoice_id) || !isset($charge->metadata->itflow_client_id)) {
    http_response_code(400);
    error_log("Stripe webhook error - Missing required metadata");
    exit("Missing required metadata");
}

// Get required data from the charge
$pi_id = sanitizeInput($charge->payment_intent);
$pi_date = date('Y-m-d', $charge->created);
$pi_invoice_id = intval($charge->metadata->itflow_invoice_id);
$pi_client_id = intval($charge->metadata->itflow_client_id);
$pi_amount_paid = floatval(($charge->amount / 100));
$pi_currency = strtoupper(sanitizeInput($charge->currency));
$pi_livemode = $charge->livemode;

// Get IP address for logging
$ip = $_SERVER['REMOTE_ADDR'];
$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';

// Get browser/OS info for logging
$os = "Unknown";
$browser = "Unknown";
if (isset($_SERVER['HTTP_USER_AGENT'])) {
    $user_agent = $_SERVER['HTTP_USER_AGENT'];
    if (preg_match('/linux/i', $user_agent)) {
        $os = 'Linux';
    } elseif (preg_match('/macintosh|mac os x/i', $user_agent)) {
        $os = 'Mac';
    } elseif (preg_match('/windows|win32/i', $user_agent)) {
        $os = 'Windows';
    }
    
    if (preg_match('/MSIE/i', $user_agent) || preg_match('/Trident/i', $user_agent)) {
        $browser = 'Internet Explorer';
    } elseif (preg_match('/Firefox/i', $user_agent)) {
        $browser = 'Firefox';
    } elseif (preg_match('/Chrome/i', $user_agent)) {
        $browser = 'Chrome';
    } elseif (preg_match('/Safari/i', $user_agent)) {
        $browser = 'Safari';
    } elseif (preg_match('/Opera/i', $user_agent)) {
        $browser = 'Opera';
    }
}

// Check if the invoice exists and is in "Processing" status
$invoice_sql = mysqli_query(
    $mysqli,
    "SELECT * FROM invoices
    LEFT JOIN clients ON invoice_client_id = client_id
    LEFT JOIN contacts ON clients.client_id = contacts.contact_client_id AND contact_primary = 1
    WHERE invoice_id = $pi_invoice_id
    AND invoice_status = 'Processing'
    LIMIT 1"
);

if (!$invoice_sql || mysqli_num_rows($invoice_sql) !== 1) {
    // Invoice not found or not in processing status
    http_response_code(200); // Still return 200 to acknowledge receipt
    error_log("Stripe webhook error - Invoice ID $pi_invoice_id not found or not in Processing status");
    exit("Invoice not found or not in Processing status");
}

// Invoice exists - get details
$row = mysqli_fetch_array($invoice_sql);
$invoice_id = intval($row['invoice_id']);
$invoice_prefix = sanitizeInput($row['invoice_prefix']);
$invoice_number = intval($row['invoice_number']);
$invoice_amount = floatval($row['invoice_amount']);
$invoice_currency_code = sanitizeInput($row['invoice_currency_code']);
$invoice_url_key = sanitizeInput($row['invoice_url_key']);
$client_id = intval($row['client_id']);
$client_name = sanitizeInput($row['client_name']);
$contact_name = sanitizeInput($row['contact_name']);
$contact_email = sanitizeInput($row['contact_email']);

// Get company details
$sql_company = mysqli_query($mysqli, "SELECT * FROM companies WHERE company_id = 1");
$row = mysqli_fetch_array($sql_company);
$company_name = sanitizeInput($row['company_name']);
$company_phone = sanitizeInput(formatPhoneNumber($row['company_phone']));
$company_locale = sanitizeInput($row['company_locale']);

// Set Currency Formatting
$currency_format = numfmt_create($company_locale, NumberFormatter::CURRENCY);

// Add up all the payments for the invoice and get the total amount paid to the invoice already (if any)
$sql_amount_paid_previously = mysqli_query($mysqli, "SELECT SUM(payment_amount) AS amount_paid FROM payments WHERE payment_invoice_id = $invoice_id");
$row = mysqli_fetch_array($sql_amount_paid_previously);
$amount_paid_previously = floatval($row['amount_paid']);
$balance_to_pay = $invoice_amount - $amount_paid_previously;

// Round balance to pay to 2 decimal places
$balance_to_pay = round($balance_to_pay, 2);

// Sanity check that the amount paid is exactly the invoice outstanding balance
if (abs($balance_to_pay - $pi_amount_paid) > 0.01) { // Using a small epsilon for floating point comparison
    http_response_code(200); // Still return 200 to acknowledge receipt
    error_log("Stripe webhook error - Invoice balance ($balance_to_pay) does not match amount paid ($pi_amount_paid) for $pi_id");
    exit("Payment amount mismatch");
}

// Begin database transaction
mysqli_begin_transaction($mysqli);

try {
    // Check to see if Expense Fields are configured to create Stripe payment expense
    if ($config_stripe_expense_vendor > 0 && $config_stripe_expense_category > 0) {
        // Calculate gateway expense fee
        $gateway_fee = round($balance_to_pay * $config_stripe_percentage_fee + $config_stripe_flat_fee, 2);

        // Add Expense
        mysqli_query($mysqli, "INSERT INTO expenses SET 
            expense_date = '$pi_date', 
            expense_amount = $gateway_fee, 
            expense_currency_code = '$invoice_currency_code', 
            expense_account_id = $config_stripe_account, 
            expense_vendor_id = $config_stripe_expense_vendor, 
            expense_client_id = $client_id, 
            expense_category_id = $config_stripe_expense_category, 
            expense_description = 'Stripe ACH Transaction for Invoice $invoice_prefix$invoice_number In the Amount of $balance_to_pay', 
            expense_reference = 'Stripe - $pi_id'");
    }

    // Update Invoice Status
    mysqli_query($mysqli, "UPDATE invoices SET invoice_status = 'Paid' WHERE invoice_id = $invoice_id");

    // Add Payment to History
    mysqli_query($mysqli, "INSERT INTO payments SET 
        payment_date = '$pi_date', 
        payment_amount = $pi_amount_paid, 
        payment_currency_code = '$pi_currency', 
        payment_account_id = $config_stripe_account, 
        payment_method = 'Stripe ACH', 
        payment_reference = 'Stripe - $pi_id', 
        payment_invoice_id = $invoice_id");
    
    mysqli_query($mysqli, "INSERT INTO history SET 
        history_status = 'Paid', 
        history_description = 'ACH Payment processed - Webhook - $ip - $os - $browser', 
        history_invoice_id = $invoice_id");

    // Notify system
    if (function_exists('appNotify')) {
        appNotify("Invoice Paid", "Invoice $invoice_prefix$invoice_number has been paid via ACH by $client_name - Webhook", "invoice.php?invoice_id=$invoice_id", $pi_client_id);
    }

    // Run any custom actions
    if (function_exists('customAction')) {
        customAction('invoice_pay', $invoice_id);
    }

    // Logging
    $extended_log_desc = '';
    if (!$pi_livemode) {
        $extended_log_desc = '(DEV MODE)';
    }

    mysqli_query($mysqli, "INSERT INTO logs SET 
        log_type = 'Payment', 
        log_action = 'Create', 
        log_description = 'Stripe ACH payment of $pi_currency $pi_amount_paid against invoice $invoice_prefix$invoice_number - $pi_id $extended_log_desc', 
        log_ip = '$ip', 
        log_user_agent = '$user_agent', 
        log_client_id = $pi_client_id");

    // Commit the transaction
    mysqli_commit($mysqli);

    // Send email receipt
    $sql_settings = mysqli_query($mysqli, "SELECT * FROM settings WHERE company_id = 1");
    $row = mysqli_fetch_array($sql_settings);

    $config_smtp_host = $row['config_smtp_host'];
    $config_smtp_port = intval($row['config_smtp_port']);
    $config_smtp_encryption = $row['config_smtp_encryption'];
    $config_smtp_username = $row['config_smtp_username'];
    $config_smtp_password = $row['config_smtp_password'];
    $config_invoice_from_name = sanitizeInput($row['config_invoice_from_name']);
    $config_invoice_from_email = sanitizeInput($row['config_invoice_from_email']);
    $config_invoice_paid_notification_email = sanitizeInput($row['config_invoice_paid_notification_email']);
    $config_base_url = sanitizeInput($config_base_url);

    if (!empty($config_smtp_host) && !empty($contact_email)) {
        $config_base_url = rtrim($config_base_url, '/'); // Remove trailing slashes
        if (!preg_match('~^https?://~i', $config_base_url)) {
            $config_base_url = 'https://' . $config_base_url;
        }

        $invoice_url = mysqli_real_escape_string($mysqli, "$config_base_url/guest/guest_view_invoice.php?invoice_id=$invoice_id&url_key=$invoice_url_key");

        $subject = "Payment Received - Invoice $invoice_prefix$invoice_number";
        $body = "Hello $contact_name,<br><br>We have received your ACH payment for the amount of " . numfmt_format_currency($currency_format, $pi_amount_paid, $invoice_currency_code) . " for invoice <a href='$invoice_url'>$invoice_prefix$invoice_number</a>. Please keep this email as a receipt for your records.<br><br>Amount: " . numfmt_format_currency($currency_format, $pi_amount_paid, $invoice_currency_code) . "<br><br>Thank you for your business!<br><br><br>~<br>$company_name - Billing<br>$config_invoice_from_email<br>$company_phone";

        $data = [
            [
                'from' => $config_invoice_from_email,
                'from_name' => $config_invoice_from_name,
                'recipient' => $contact_email,
                'recipient_name' => $contact_name,
                'subject' => $subject,
                'body' => $body,
            ]
        ];

        // Email the internal notification address too
        if (!empty($config_invoice_paid_notification_email)) {
            $subject = "ACH Payment Received - $client_name - Invoice $invoice_prefix$invoice_number";
            $body = "Hello, <br><br>This is a notification that an invoice has been paid via ACH in ITFlow. Below is a copy of the receipt sent to the client:-<br><br>--------<br><br>Hello $contact_name,<br><br>We have received your ACH payment for the amount of " . numfmt_format_currency($currency_format, $pi_amount_paid, $invoice_currency_code) . " for invoice <a href='$invoice_url'>$invoice_prefix$invoice_number</a>. Please keep this email as a receipt for your records.<br><br>Amount: " . numfmt_format_currency($currency_format, $pi_amount_paid, $invoice_currency_code) . "<br><br>Thank you for your business!<br><br><br>~<br>$company_name - Billing<br>$config_invoice_from_email<br>$company_phone";

            $data[] = [
                'from' => $config_invoice_from_email,
                'from_name' => $config_invoice_from_name,
                'recipient' => $config_invoice_paid_notification_email,
                'recipient_name' => $company_name,
                'subject' => $subject,
                'body' => $body,
            ];
        }

        // Use mail queue function if available
        if (function_exists('addToMailQueue')) {
            $mail = addToMailQueue($data);
            
            // Email logging
            mysqli_query($mysqli, "INSERT INTO history SET 
                history_status = 'Sent', 
                history_description = 'Emailed ACH Payment Receipt!', 
                history_invoice_id = $invoice_id");
        } else {
            // Simple fallback if mail queue function not available
            error_log("Mail queue function not available for ACH payment receipt");
        }
    }

    // Return success
    http_response_code(200);
    echo "ACH payment processed successfully";

} catch (Exception $e) {
    // An error occurred, rollback the transaction
    mysqli_rollback($mysqli);
    
    http_response_code(500);
    error_log("Stripe webhook error - Transaction failed: " . $e->getMessage());
    exit("Database transaction failed");
}