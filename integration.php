<?php
/**
 * Plugin Name: SIL Ezidebit-WPDB-HubSpot Integration
 * Description: Make API Requests to the Ezidebit API, Insert into the Wordpress Database, Update Deals in HubSpot.
 * Version: 1.0
 * Author: Allan Concepcion
 */

// Create Custom Table on Plugin Activation
function custom_table_creation() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'deals_info';
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE $table_name (
        id INT NOT NULL AUTO_INCREMENT,
        ezidebit_transaction_id VARCHAR(255),
        hubspot_deal_id VARCHAR(255),
        clickup_task_id VARCHAR(255),
        amount DECIMAL(10, 2),
        payment_frequency VARCHAR(255),
        renewal_frequency VARCHAR(255),
        number_payments_in_contract INT,
        number_payments_left_in_contract INT,
        sale_date DATETIME,
        payment_reference VARCHAR(255),
        renewal_date DATE,
        total_amount_paid DECIMAL(10, 2),
        total_amount_left DECIMAL(10, 2),
        number_failed_payments INT,
        number_successful_payments INT,
        payment_status VARCHAR(255),
        created_at VARCHAR(255),
        updated_at VARCHAR(255),
        PRIMARY KEY (id)
    ) $charset_collate;";
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}
// Hook the custom_table_creation function to run on plugin activation
register_activation_hook(__FILE__, 'custom_table_creation');

function manually_insert_to_wp_deals_info() {
    if ( isset( $_POST['fetch_deals'] ) ) {
        processEzidebitPayments();
        echo '
        <div class="container">
            <div class="alert alert-success alert-dismissible fade show mt-3" role="alert">
                <strong class="mb-0">HubSpot deals fetched and inserted into the database!</strong>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        </div>';
    }
    if ( isset( $_POST['update_deals'] ) ) {
        retrieve_wp_deals_info_for_hubspot_update();
        echo '
        <div class="container">
            <div class="alert alert-success alert-dismissible fade show mt-3" role="alert">
                <strong class="mb-0">HubSpot Deals Updated!</strong>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        </div>';
    }
    if ( isset( $_POST['sync_contracts'] ) ) {
        syncDateTimeStamps();
        echo '
        <div class="container">
            <div class="alert alert-success alert-dismissible fade show mt-3" role="alert">
                <strong class="mb-0">Contracts Updated!</strong>
                <p>Timestamps in wp_deals_info successfully updated with the ones from HubSpot API.</p>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        </div>';
    }
    if ( isset( $_POST['update_hubspot_on_wpdb_change'] ) ) {
        updateHubSpotDealsBasedOnDatabase();
        echo '
        <div class="container">
            <div class="alert alert-success alert-dismissible fade show mt-3" role="alert">
                <strong class="mb-0">HubSpot Deals Updated!</strong>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        </div>';
    }
    // $result = ezidebitPaymentsFromThreeDaysAgo();
    // if ($result !== false) {
    //     echo "API Response:\n";
    //     print_r($result); // or var_dump($result);
    // } else {
    //     echo "API call failed or response format is not as expected.\n";
    // }
    display_deals_widget();
}

// Function to check if a deal with a specific hubspot_deal_id already exists
function deal_exists_by_hubspot_id( $hubspot_deal_id ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'deals_info';
    $result = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table_name WHERE hubspot_deal_id = %s", $hubspot_deal_id ) );
    return $result > 0;
}

// Manually trigger the HubSpot Deals fetching
add_action( 'admin_menu', 'register_hubspot_deals_menu' );
function register_hubspot_deals_menu() {
    add_menu_page(
        'Insert to WP_Deals_Info',
        'Insert to WP_Deals_Info',
        'manage_options',
        'insert-to-wp-deals-info',
        'manually_insert_to_wp_deals_info'
    );
}

function formatDateForHubSpot($dateTimeString) {
    // Convert the input date to UTC format
    $dateTime = new DateTime($dateTimeString);
    $dateTime->setTimezone(new DateTimeZone('UTC'));
    // Set the time to midnight (00:00:00)
    $dateTime->setTime(0, 0, 0, 0);
    // Format the date in ISO 8601 format (YYYY-MM-DDTHH:MM:SSZ)
    return $dateTime->format('Y-m-d\TH:i:s\Z');
}

function syncDateTimeStamps() {
    // Assuming you have the JSON file in the theme folder
    $json_file_path = __DIR__ . './sample-json-sil.json';
    // Read the JSON file content
    $json_data = file_get_contents($json_file_path);
    // Convert JSON data to an array
    $data_array = json_decode($json_data, true);
    foreach ($data_array as $data) {
        // Fetch createdAt and updatedAt properties from HubSpot
        $hubspotDealId = $data['hubspot_deal_id'];
        $timestamps = fetchHubSpotDealTimestamps($hubspotDealId);
        if ($timestamps !== false) {
            $createdAt = $timestamps['created_at'];
            $updatedAt = $timestamps['updated_at'];
            // Update the WordPress database with timestamps
            updateTimestampsInWordPress($hubspotDealId, $createdAt, $updatedAt);
        }
    }
}

function processEzidebitPayments() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'deals_info';
    // Assuming you have the JSON file in the theme folder
    $json_file_path = __DIR__ . './sample-json-sil.json';
    // Read the JSON file content
    $json_data = file_get_contents($json_file_path);
    // Convert JSON data to an array
    $data_array = json_decode($json_data, true);
    // Initialize an array to store payment data
    $payment_data = array();
    // Loop through each payment_reference
    foreach ($data_array as $data) {
        $payment_reference = $data['payment_reference'];
        // Call the Ezidebit API to get payment details
        $ezidebit_response = getPaymentDetail($payment_reference);
        if ($ezidebit_response !== false) {
            // Extract PaymentAmount and PaymentStatus from the API response
            $ezidebit_payer_id = $ezidebit_response['EzidebitCustomerID'];
            $payment_amount = $ezidebit_response['PaymentAmount'];
            $payment_status = $ezidebit_response['PaymentStatus'];
            // Store the payment data in an array
            $payment_data[$payment_reference] = array(
                'EzidebitCustomerID' => $ezidebit_payer_id,
                'PaymentAmount' => $payment_amount,
                'PaymentStatus' => $payment_status,
            );
        }
    }
    // Loop through the JSON data and insert into the WordPress table
    foreach ($data_array as $data) {
        $payment_reference = $data['payment_reference'];
        // Check if payment data exists for this reference
        if (isset($payment_data[$payment_reference])) {
            $ezidebit_transaction_id = $payment_data[$payment_reference]['EzidebitCustomerID'];
            $payment_amount = $payment_data[$payment_reference]['PaymentAmount'];
            $payment_status = $payment_data[$payment_reference]['PaymentStatus'];
            $originalDate = $data['sale_date'];
            // Format the date to yyyy-mm-d
            $formattedDate = date("Y-m-d", strtotime($originalDate));
            // Calculate payments left
            $today = new DateTime();
            $saleDate = new DateTime($formattedDate);
            $renewalFrequency = $data['renewal_frequency'];
            // Calculate the next renewal date based on renewal frequency
            $nextRenewalDate = clone $saleDate;
            switch ($renewalFrequency) {
                case 1: // Yearly
                    $nextRenewalDate->modify('+1 year');
                    break;
                case 2: // 6 monthly
                    $nextRenewalDate->modify('+6 months');
                    break;
                case 4: // Quarterly (every 3 months)
                    $nextRenewalDate->modify('+3 months');
                    break;
                case 0: // One-time only payment
                    $nextRenewalDate = null;
                    break;
                case 6: // Every 2 months
                    $nextRenewalDate->modify('+2 months');
                    break;
                case 12: // Monthly
                    $nextRenewalDate->modify('+1 month');
                    break;
                default:
                    // Handle invalid renewal frequency here
                    break;
            }
            $currentYear = $today->format('Y');
            if ($nextRenewalDate !== null) {
                $nextRenewalDate->setDate($currentYear, $nextRenewalDate->format('m'), $nextRenewalDate->format('d'));
                if ($nextRenewalDate < $today) {
                    $nextRenewalDate->modify('+1 year');
                }
            }
            $paymentFrequency = $data['payment_frequency'];
            $totalPayments = 0;
            if ($nextRenewalDate !== null) {
                $interval = $saleDate->diff($nextRenewalDate);
                $monthsBetweenDates = $interval->format('%y') * 12 + $interval->format('%m');
                switch ($paymentFrequency) {
                    case 'Monthly':
                        $totalPayments = $monthsBetweenDates;
                        break;
                    case '6 Monthly':
                        $totalPayments = floor($monthsBetweenDates / 6);
                        break;
                    case 'Yearly':
                        $yearsToNextPayment = $interval->format('%y');
                        if ($yearsToNextPayment > 0 || $interval->format('%m') > 0 || $interval->format('%d') > 0) {
                            $totalPayments = 1;
                        }
                        $totalPayments = $yearsToNextPayment;
                        break;
                    case 'Quarterly':
                        $quartersToNextPayment = ceil($monthsBetweenDates / 3);
                        if ($quartersToNextPayment > 0) {
                            $totalPayments = $quartersToNextPayment;
                        }
                        break;
                    default:
                        // Handle invalid payment frequency here
                        break;
                }
            }
            $paymentsLeft = 0;
            if ($nextRenewalDate !== null) {
                $interval = $today->diff($nextRenewalDate);
                $monthsToNextRenewal = $interval->format('%y') * 12 + $interval->format('%m');
                $monthsToNextPayment = $interval->format('%m');
                switch ($paymentFrequency) {
                    case 'Monthly':
                        $paymentsLeft = $monthsToNextRenewal;
                        if ($interval->format('%d') > 0) {
                            $paymentsLeft++;
                        }
                        break;
                    case '6 Monthly':
                        $interval = $today->diff($nextRenewalDate);
                        $yearsToNextPayment = $interval->format('%y');
                        $monthsToNextPayment = $yearsToNextPayment * 12 + $interval->format('%m');
                        if ($monthsToNextPayment > 0 || $interval->format('%d') > 0) {
                            $paymentsLeft = 1;
                        }
                        break;
                    case 'Yearly':
                        $yearsToNextPayment = $interval->format('%y');
                        if ($yearsToNextPayment > 0 || $interval->format('%m') > 0 || $interval->format('%d') > 0) {
                            $paymentsLeft = 1;
                        }
                        break;
                    case 'Quarterly':
                        $quartersToNextPayment = ceil($monthsToNextRenewal / 3);
                        if ($quartersToNextPayment > 0 || $interval->format('%d') > 0) {
                            $paymentsLeft = $quartersToNextPayment;
                        }
                        break;
                    default:
                        // Handle invalid payment frequency here
                        break;
                }
            }
            $renewalDate = ($nextRenewalDate !== null) ? $nextRenewalDate->format('Y-m-d') : null;
            // total_amount_paid value
            $totalAmountToPayInContract = $payment_amount * $totalPayments;
            // total_amount_left value
            $totalAmountLeftToPayInContract = $payment_amount * $paymentsLeft;
            // Determine the increment value based on the payment status
            $increment_successful_payments = ($payment_status == 'S') ? 1 : 0;
            $increment_failed_payments = ($payment_status == 'F') ? 1 : 0;
            // Calculate the new cumulative counts
            $cumulative_successful_payments = $wpdb->get_var("SELECT MAX(number_successful_payments) FROM $table_name") + $increment_successful_payments;
            // If it's a failed payment, increment the failed count; otherwise, keep it 0
            $cumulative_failed_payments = ($payment_status == 'F') ? ($wpdb->get_var("SELECT MAX(number_failed_payments) FROM $table_name") + $increment_failed_payments) : 0;
            // Insert data into the WordPress table
            $wpdb->insert(
                $table_name,
                array(
                    'ezidebit_transaction_id' => $ezidebit_transaction_id,
                    'clickup_task_id' => $data['clickup_task_id'],
                    'hubspot_deal_id' => $data['hubspot_deal_id'],
                    'sale_date' => $formattedDate,
                    'payment_reference' => $payment_reference,
                    'amount' => $payment_amount,
                    'payment_frequency' => $data['payment_frequency'],
                    'renewal_frequency' => $data['renewal_frequency'],
                    'number_payments_in_contract' => $totalPayments,
                    'number_payments_left_in_contract' => $paymentsLeft,
                    'renewal_date' => $renewalDate,
                    'total_amount_paid' => $totalAmountToPayInContract,
                    'total_amount_left' => $totalAmountLeftToPayInContract,
                    'number_successful_payments' => $cumulative_successful_payments,
                    'number_failed_payments' => $cumulative_failed_payments,
                    'payment_status' => $payment_status,
                ),
                array(
                    '%s',
                    '%s',
                    '%s',
                    '%s',
                    '%s',
                    '%s',
                    '%s',
                    '%s',
                    '%s',
                    '%s',
                    '%s',
                    '%s',
                )
            );
        }
    }
}

function getPaymentDetail($payment_reference) {
    $client = new SoapClient('https://api.demo.ezidebit.com.au/v3-5/nonpci?singleWsdl');
    $ezidebit_response = $client->GetPayments([
        'DigitalKey' => '143F8E3D-D734-472B-9E9E-DC37B4DA59E0',
        'PaymentType' => 'ALL',
        'PaymentMethod' => 'ALL',
        'PaymentSource' => 'ALL',
        'PaymentReference' => $payment_reference
    ]);
    // Check if the Ezidebit API call was successful
    if ($ezidebit_response && isset($ezidebit_response->GetPaymentsResult->Data->Payment)) {
        $payments = $ezidebit_response->GetPaymentsResult->Data->Payment;
        return array(
            'EzidebitCustomerID' => $payments->EzidebitCustomerID,
            'PaymentAmount' => $payments->PaymentAmount,
            'PaymentStatus' => $payments->PaymentStatus,
        );
    } else {
        return false; // API call failed or response format is not as expected
    }
}

// Function to update a batch of deals in HubSpot
function updateHubSpotDeals($dealUpdates) {
    // Replace with your HubSpot API key or OAuth token
    $apiKey = 'pat-na1-e1f175c0-93c4-42d7-9135-d2d2ecbc743d';
    // HubSpot API endpoint for updating deals
    $endpoint = 'https://api.hubapi.com/crm/v3/objects/deals/batch/update';
    // Create the request body
    $requestBody = json_encode(['inputs' => $dealUpdates]);
    // Create cURL handle
    $ch = curl_init();
    // Set cURL options
    curl_setopt($ch, CURLOPT_URL, $endpoint);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $requestBody);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json',
    ]);
    // Capture the output buffer to suppress response
    ob_start();
    // Execute cURL request
    $response = curl_exec($ch);
    // Check for cURL errors
    if (curl_errno($ch)) {
        echo 'cURL Error: ' . curl_error($ch);
    } else {
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($httpCode === 200) {
            // Deals updated successfully, you can display a success message if needed
            echo 'Deals updated successfully.';
        } else {
            // Handle any errors here, but do not echo the response
            echo 'Error updating deals. HTTP Status Code: ' . $httpCode;
        }
    }
    // Close cURL handle
    curl_close($ch);
    // End the output buffer to suppress the response
    ob_end_clean();
}

function retrieve_wp_deals_info_for_hubspot_update() {
    // Retrieve data from the WordPress database (wp_deals_info)
    global $wpdb;
    $table_name = $wpdb->prefix . 'deals_info';
    $query = "SELECT hubspot_deal_id, clickup_task_id, ezidebit_transaction_id, amount, payment_frequency, 
        number_payments_in_contract, number_payments_left_in_contract, sale_date, renewal_date, total_amount_paid, 
        total_amount_left, number_failed_payments, number_successful_payments, payment_status FROM $table_name";
    $results = $wpdb->get_results($query, ARRAY_A);
    // Set your desired batch size
    $batchSize = 10;
    // Initialize an array to store deal updates
    $dealUpdates = [];
    foreach ($results as $row) {
        // Map database columns to HubSpot property names
        $dealUpdate = [
            'id' => $row['hubspot_deal_id'],
            'properties' => [
                'clickup_task_url' => $row['clickup_task_id'],
                'ezidebit_payer_id' => $row['ezidebit_transaction_id'],
                'regular_debit_amount__inc_gst_' => $row['amount'],
                'payment_frequency' => $row['payment_frequency'],
                'number_payments_in_contract' => $row['number_payments_in_contract'],
                'number_payments_left_in_contract' => $row['number_payments_left_in_contract'],
                'closedate' => formatDateForHubSpot($row['sale_date']),
                'renewal_date' => $row['renewal_date'],
                'total_amount_paid' => $row['total_amount_paid'],
                'total_amount_left' => $row['total_amount_left'],
                'number_of_failed_payments' => $row['number_failed_payments'],
                'number_of_successful_payments' => $row['number_successful_payments'],
                'order_status' => $row['payment_status'],
            ],
        ];
        $dealUpdates[] = $dealUpdate;
        // Check if the batch is ready for processing
        if (count($dealUpdates) === $batchSize) {
            // Update the deals in HubSpot
            updateHubSpotDeals($dealUpdates);
            $dealUpdates = []; // Reset the batch
        }
    }
    // Process any remaining records
    if (!empty($dealUpdates)) {
        updateHubSpotDeals($dealUpdates);
    }
}

// Function to fetch the createdAt and updatedAt properties from HubSpot API
function fetchHubSpotDealTimestamps($hubspotDealId) {
    // HubSpot API endpoint for fetching a specific deal
    $endpoint = "https://api.hubapi.com/crm/v3/objects/deals/{$hubspotDealId}";
    // Your HubSpot API Key
    $apiKey = "pat-na1-e1f175c0-93c4-42d7-9135-d2d2ecbc743d";
    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => $endpoint,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => array(
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ),
    ));
    $response = curl_exec($curl);
    curl_close($curl);
    $dealData = json_decode($response, true);
    if (isset($dealData['createdAt']) && isset($dealData['updatedAt'])) {
        return array(
            'created_at' => $dealData['createdAt'],
            'updated_at' => $dealData['updatedAt'],
        );
    } else {
        return false;
    }
}

// Function to update the WordPress database with timestamps from HubSpot
function updateTimestampsInWordPress($hubspotDealId, $createdAt, $updatedAt) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'deals_info';
    // Update the created_at and updated_at columns in the WordPress table
    $wpdb->update(
        $table_name,
        array(
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
        ),
        array('hubspot_deal_id' => $hubspotDealId),
        array('%s', '%s'),
        array('%s')
    );
}

function updateHubSpotDealsBasedOnDatabase() {
    // Retrieve data from the WordPress database (wp_deals_info)
    global $wpdb;
    $table_name = $wpdb->prefix . 'deals_info';
    $query = "SELECT hubspot_deal_id, renewal_date, sale_date, amount, payment_frequency, renewal_frequency,number_payments_in_contract, number_payments_left_in_contract, total_amount_paid, total_amount_left FROM $table_name";
    $results = $wpdb->get_results($query, ARRAY_A);
    // Initialize an array to store deal updates
    $dealUpdates = [];
    foreach ($results as $row) {
        $hubspotDealId = $row['hubspot_deal_id'];
        $renewalDateDatabase = $row['renewal_date'];
        $saleDate = $row['sale_date'];
        $amount = $row['amount'];
        $paymentFrequency = $row['payment_frequency'];
        $renewalFrequency = $row['renewal_frequency'];
        $numPaymentsInContract = $row['number_payments_in_contract'];
        $numPaymentsLeftInContract = $row['number_payments_left_in_contract'];
        $totalAmountPaid = $row['total_amount_paid'];
        $totalAmountLeft = $row['total_amount_left'];
        // Check if the renewal date has been reached
        $today = new DateTime();
        $renewalDate = new DateTime($renewalDateDatabase);
        // $testToday = new DateTime("2025-02-20");
        if ($today >= $renewalDate) {
            // Calculate the next renewal date based on renewal frequency
            $nextRenewalDate = new DateTime($saleDate);
            switch ($renewalFrequency) {
                case 1: // Yearly
                    $nextRenewalDate->modify('+1 year');
                    break;
                case 2: // 6 monthly
                    $nextRenewalDate->modify('+6 months');
                    break;
                case 4: // Quarterly (every 3 months)
                    $nextRenewalDate->modify('+3 months');
                    break;
                case 0: // One-time only payment
                    $nextRenewalDate = null;
                    break;
                case 6: // Every 2 months
                    $nextRenewalDate->modify('+2 months');
                    break;
                case 12: // Monthly
                    $nextRenewalDate->modify('+1 month');
                    break;
                default:
                    // Handle invalid renewal frequency here
                    break;
            }
            $currentYear = $today->format('Y');
            if ($nextRenewalDate !== null) {
                $nextRenewalDate->setDate($currentYear, $nextRenewalDate->format('m'), $nextRenewalDate->format('d'));
                if ($nextRenewalDate < $today) {
                    $nextRenewalDate->modify('+1 year');
                }
            }
            $totalPayments = 0;
            if ($nextRenewalDate !== null) {
                // Create DateTime objects from the date strings
                $saleDateFix = new DateTime($saleDate);
                $interval = $saleDateFix->diff($nextRenewalDate);
                $monthsBetweenDates = $interval->format('%y') * 12 + $interval->format('%m');
                switch ($paymentFrequency) {
                    case 'Monthly':
                        $totalPayments = $monthsBetweenDates;
                        break;
                    case '6 Monthly':
                        $totalPayments = floor($monthsBetweenDates / 6);
                        break;
                    case 'Yearly':
                        $yearsToNextPayment = $interval->format('%y');
                        if ($yearsToNextPayment > 0 || $interval->format('%m') > 0 || $interval->format('%d') > 0) {
                            $totalPayments = 1;
                        }
                        $totalPayments = $yearsToNextPayment;
                        break;
                    case 'Quarterly':
                        $quartersToNextPayment = ceil($monthsBetweenDates / 3);
                        if ($quartersToNextPayment > 0) {
                            $totalPayments = $quartersToNextPayment;
                        }
                        break;
                    default:
                        // Handle invalid payment frequency here
                        break;
                }
            }
            $paymentsLeft = 0;
            if ($nextRenewalDate !== null) {
                $interval = $today->diff($nextRenewalDate);
                $monthsToNextRenewal = $interval->format('%y') * 12 + $interval->format('%m');
                $monthsToNextPayment = $interval->format('%m');
                switch ($paymentFrequency) {
                    case 'Monthly':
                        $paymentsLeft = $monthsToNextRenewal;
                        if ($interval->format('%d') > 0) {
                            $paymentsLeft++;
                        }
                        break;
                    case '6 Monthly':
                        $interval = $today->diff($nextRenewalDate);
                        $yearsToNextPayment = $interval->format('%y');
                        $monthsToNextPayment = $yearsToNextPayment * 12 + $interval->format('%m');
                        if ($monthsToNextPayment > 0 || $interval->format('%d') > 0) {
                            $paymentsLeft = 1;
                        }
                        break;
                    case 'Yearly':
                        $yearsToNextPayment = $interval->format('%y');
                        if ($yearsToNextPayment > 0 || $interval->format('%m') > 0 || $interval->format('%d') > 0) {
                            $paymentsLeft = 1;
                        }
                        break;
                    case 'Quarterly':
                        $quartersToNextPayment = ceil($monthsToNextRenewal / 3);
                        if ($quartersToNextPayment > 0 || $interval->format('%d') > 0) {
                            $paymentsLeft = $quartersToNextPayment;
                        }
                        break;
                    default:
                        // Handle invalid payment frequency here
                        break;
                }
            }
            // Recalculate total_amount_paid and total_amount_left
            $totalAmountPaid = $amount * $totalPayments;
            $totalAmountLeft = $amount * $paymentsLeft;
            // Update the values in the WordPress database
            $wpdb->update(
                $table_name,
                array(
                    'renewal_date' => $nextRenewalDate ? $nextRenewalDate->format('Y-m-d') : null,
                    'number_payments_in_contract' => $totalPayments,
                    'number_payments_left_in_contract' => $paymentsLeft,
                    'total_amount_paid' => $totalAmountPaid,
                    'total_amount_left' => $totalAmountLeft,
                ),
                array('hubspot_deal_id' => $hubspotDealId)
            );
            // Update the deal properties in HubSpot
            $dealUpdate = [
                'id' => $hubspotDealId,
                'properties' => [
                    'number_payments_in_contract' => $totalPayments,
                    'number_payments_left_in_contract' => $paymentsLeft,
                    'total_amount_paid' => $totalAmountPaid,
                    'total_amount_left' => $totalAmountLeft,
                    'renewal_date' => $nextRenewalDate ? $nextRenewalDate->format('Y-m-d') : null,
                    'regular_debit_amount__inc_gst_' => $amount,
                ],
            ];
            $dealUpdates[] = $dealUpdate;
        }
    }
    // Update the deals in HubSpot
    updateHubSpotDeals($dealUpdates);
}

// function makeHubSpotAPIRequest($endpoint, $method = 'GET', $data = null) {
//     // Replace with your HubSpot API key or OAuth token
//     $apiKey = 'pat-na1-e1f175c0-93c4-42d7-9135-d2d2ecbc743d';
//     // Create cURL handle
//     $ch = curl_init();
//     // Set cURL options
//     $curlOptions = array(
//         CURLOPT_URL => $endpoint,
//         CURLOPT_RETURNTRANSFER => true,
//         CURLOPT_HTTPHEADER => array(
//             'Authorization: Bearer ' . $apiKey,
//             'Content-Type: application/json',
//         ),
//     );
//     if ($method === 'POST' && $data !== null) {
//         $curlOptions[CURLOPT_CUSTOMREQUEST] = 'POST';
//         $curlOptions[CURLOPT_POSTFIELDS] = json_encode($data);
//     }
//     curl_setopt_array($ch, $curlOptions);
//     // Execute cURL request
//     $response = curl_exec($ch);
//     // Check for cURL errors
//     if (curl_errno($ch)) {
//         echo 'cURL Error: ' . curl_error($ch);
//         return false;
//     }
//     // Close cURL handle
//     curl_close($ch);
//     // Parse the JSON response
//     $responseData = json_decode($response, true);
//     if (isset($responseData)) {
//         return $responseData; // Return the response data
//     } else {
//         echo 'Error making HubSpot API request. Response: ' . $response;
//         return false;
//     }
// }

function ezidebitPaymentsFromThreeDaysAgo() {
    // Get the current date
    $currentDate = date('Y-m-d');
    // Calculate the date 3 days before the current date
    $threeDaysAgo = date('Y-m-d', strtotime('-3 days', strtotime($currentDate)));
    $client = new SoapClient('https://api.demo.ezidebit.com.au/v3-5/nonpci?singleWsdl');
    $ezidebit_response = $client->GetPayments([
        'DigitalKey' => '143F8E3D-D734-472B-9E9E-DC37B4DA59E0',
        'PaymentType' => 'ALL',
        'PaymentMethod' => 'ALL',
        'PaymentSource' => 'ALL',
        'DateFrom' => $threeDaysAgo,
        'DateField' => 'PAYMENT'
    ]);
    if ($ezidebit_response && isset($ezidebit_response->GetPaymentsResult->Data->Payment)) {
        $payments = $ezidebit_response->GetPaymentsResult->Data->Payment;
        // Initialize an array to store payment details for all payments
        $paymentDetails = array();
        foreach ($payments as $payment) {
            $paymentDetail = array(
                'EzidebitCustomerID' => $payment->EzidebitCustomerID,
                'PaymentAmount' => $payment->PaymentAmount,
                'PaymentStatus' => $payment->PaymentStatus,
            );
            // Add the payment detail to the array
            $paymentDetails[] = $paymentDetail;
        }
        return $paymentDetails;
    } else {
        return false; // API call failed or response format is not as expected
    }    
}

function testUpdateThreeDaysAgo() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'deals_info'; // Adjust the table name as needed
    $payment_references = $wpdb->get_results("SELECT hubspot_deal_id, payment_reference, payment_status FROM $table_name", ARRAY_A);
    // Call the Ezidebit function to get payments
    $ezidebitPayments = ezidebitPaymentsFromThreeDaysAgo();
    if ($ezidebitPayments !== false) {
        $dealUpdates = array(); // Initialize an array to store deal updates
        foreach ($payment_references as $row) {
            $payment_reference = $row['payment_reference'];
            $payment_status = $row['payment_status'];
            foreach ($ezidebitPayments as $payment) {
                if ($payment_reference === $payment['EzidebitCustomerID']) {
                // Update payment_status in wp_deals_info
                $new_payment_status = $payment['PaymentStatus'];
                $wpdb->update(
                    $table_name,
                    array('payment_status' => $new_payment_status),
                    array('payment_reference' => $payment_reference)
                );
                // Prepare deal update for HubSpot
                $dealUpdates[] = array(
                    'id' => $hubspot_deal_id, // Replace with your HubSpot deal ID
                    'properties' => array(
                        array(
                            'name' => 'payment_status', // Replace with your HubSpot property name
                            'value' => $new_payment_status,
                        ),
                    ),
                );
            }
        }
    }
        // Update deals in HubSpot
        if (!empty($dealUpdates)) {
            updateHubSpotDeals($dealUpdates);
        }
    } else {
        echo 'Ezidebit API call failed or response format is not as expected.';
    }
}

function display_deals_widget() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'deals_info';
    // Pagination variables
    $items_per_page = 10; // Number of items per page
    $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $offset = ($current_page - 1) * $items_per_page;
    $data = $wpdb->get_results("SELECT * FROM $table_name LIMIT $offset, $items_per_page");
        if (!empty($data)) {
            ?>
            <div class="container">
                <div class="row">
                    <div class="col-6">
                        <h2 class="mt-4">Update HubSpot Deals</h2>
                        <form method="post" action="">
                            <p>Update HubSpot deals that are already present in the Contracts Information table or update deal properties from this table.</p>
                            <input type="submit" name="update_deals" class="btn btn-primary mb-3" value="Update Deals">
                            <input type="submit" name="update_hubspot_on_wpdb_change" class="btn btn-outline-primary mb-3" value="Sync to HubSpot">
                        </form> 
                    </div>
                    <div class="col-6">
                        <h2 class="mt-4">Sync Contracts From HubSpot</h2>
                        <form method="post" action="">
                            <p>Click the button below to sync the timestamps of your contracts with the ones from HubSpot.</p>
                            <input type="submit" name="sync_contracts" class="btn btn-primary mb-3" value="Update Contracts">
                        </form> 
                    </div>
                </div>
            </div>
            <?php
            echo '<div class="container">';
            echo '<div class="table-responsive">';
            echo '<table class="table table-striped table-bordered">';
            echo '<thead class="thead-dark">';
            echo '<tr>';
            echo '<th scope="col">#</th>';
            echo '<th scope="col">Transaction #</th>';
            echo '<th scope="col">HubSpot Deal #</th>';
            echo '<th scope="col">ClickUp Task #</th>';
            echo '<th scope="col">Amount</th>';
            echo '<th scope="col">Payment Frequency</th>';
            echo '<th scope="col">Payments in Contract</th>';
            echo '<th scope="col">Payments Left in Contract</th>';
            echo '<th scope="col">Sale Date</th>';
            echo '<th scope="col">Renewal Date</th>';
            echo '<th scope="col">Amount To Pay</th>';
            echo '<th scope="col">Amount Left To Pay</th>';
            echo '<th scope="col">Failed Payments</th>';
            echo '<th scope="col">Successful Payments</th>';
            echo '<th scope="col">Status</th>';
            echo '</tr>';
            echo '</thead>';
            echo '<tbody>';
            foreach ($data as $deal) {
                echo '<tr>';
                echo '<td>' . $deal->id . '</td>';
                echo '<td>' . $deal->ezidebit_transaction_id . '</td>';
                echo '<td>' . $deal->hubspot_deal_id . '</td>';
                echo '<td>' . $deal->clickup_task_id . '</td>';
                echo '<td>' . '$'. $deal->amount . '</td>';
                echo '<td>' . $deal->payment_frequency . '</td>';
                echo '<td>' . $deal->number_payments_in_contract . '</td>';
                echo '<td>' . $deal->number_payments_left_in_contract . '</td>';
                if (!empty($deal->sale_date)) {
                    echo '<td>' . date('F d, Y', strtotime($deal->sale_date)) . '</td>';
                } else {
                    echo '<td></td>'; // Display an empty cell for empty dates
                }
                if ($deal->renewal_date == NULL) {
                    echo '<td></td>';
                } else {
                    echo '<td>' . date('F d, Y', strtotime($deal->renewal_date)) . '</td>';
                }             
                echo '<td>' . '$'. $deal->total_amount_paid . '</td>';
                echo '<td>' . '$'. $deal->total_amount_left . '</td>';
                echo '<td>' . $deal->number_failed_payments . '</td>';
                echo '<td>' . $deal->number_successful_payments . '</td>';
                echo '<td>' . $deal->payment_status . '</td>';
            }
            echo '</tbody>';
            echo '</table>';
            // Pagination
            $total_items = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
            $total_pages = ceil($total_items / $items_per_page);
            $max_pagination_buttons = 10; // Maximum number of pagination buttons to display
            echo '<nav aria-label="Page navigation example">';
            echo '<ul class="pagination justify-content-center">';
            $start_page = max(1, $current_page - floor($max_pagination_buttons / 2));
            $end_page = min($total_pages, $start_page + $max_pagination_buttons - 1);
            if ($current_page > 1) {
                echo '<li class="page-item"><a class="page-link" href="?page=fetch-hubspot-deals&paged=' . ($current_page - 1) . '">Previous</a></li>';
            }
            for ($i = $start_page; $i <= $end_page; $i++) {
                echo '<li class="page-item ' . ($current_page === $i ? 'active' : '') . '"><a class="page-link" href="?page=fetch-hubspot-deals&paged=' . $i . '">' . $i . '</a></li>';
            }
            if ($current_page < $total_pages) {
                echo '<li class="page-item"><a class="page-link" href="?page=fetch-hubspot-deals&paged=' . ($current_page + 1) . '">Next</a></li>';
            }
            echo '</ul>';
            echo '</nav>';
        } else {
            echo '
            <div class="container d-flex justify-content-center mt-5 flex-column align-items-center">
                <img src="http://samplepress.test/wp-content/uploads/2023/08/Nothing-here.png" width="250" height="250"/>
                <h3>No Contracts Yet.</h3>
                <p>Contracts with Ezidebit Information will appear here.</p>
                <form method="post" action="">
                    <input type="submit" name="fetch_deals" class="btn btn-primary mb-3" value="Get Contracts">
                </form>
            </div>
            ';
        }
}
?>
