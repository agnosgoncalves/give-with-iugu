<?php
/**
 * Manual Gateway
 *
 * @package     Give
 * @subpackage  Gateways
 * @copyright   Copyright (c) 2016, WordImpress
 * @license     https://opensource.org/licenses/gpl-license GNU Public License
 * @since       1.0
 */


// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

/**
 * Manual Gateway does not need a CC form, so remove it.
 *
 * @since 1.0
 * @return void
 */
add_action( 'give_iugu_cc_form', '__return_false' );

/**
 * Processes the donation data and uses the Manual Payment gateway to record
 * the donation in the Donation History
 *
 * @since 1.0
 *
 * @param array $purchase_data Donation Data
 *
 * @return void
 */


use Carbon\Carbon;

function iugu_add_days($qnt_days){
  $date_now = Carbon::now();
  return $date_now->addDays($qnt_days);
}


function iugu_generate_payer(){
    $phone        = give_get_option('iugu_account_phone', 'Iugu' );
    $phone_prefix = give_get_option('iugu_account_phone_prefix', 'Iugu' );
    $name     = give_get_option('iugu_account_name', 'Iugu' );
    $email    = give_get_option('iugu_account_email', 'Iugu' );
    $cpf_cnpj = give_get_option('iugu_account_ssn', 'Iugu' );
    $zip_code = give_get_option('iugu_account_zip_code', 'Iugu' );
    $state    = give_get_option('iugu_account_state', 'Iugu' );
    $city     = give_get_option('iugu_account_city', 'Iugu' );
    $street   = give_get_option('iugu_account_street', 'Iugu' );
    $street_number = give_get_option('iugu_account_street_number', 'Iugu' );

    $payer  = [
      'name'        => $name ? $name : '',
      'email'       => $email ? $email : '',
      'cpf_cnpj'    => $cpf_cnpj ? $cpf_cnpj : '',
      'phone'       => $phone ? $phone : '',
      'phone_prefix' => $phone_prefix ? $phone_prefix : '',
      'address'     =>[
        'zip_code'  => $zip_code ? $zip_code : '',
        'state'     => $state ? $state : '',
        'street'    => $street ? $street : '',
        'number'    => $street_number ? $street_number : '',
        'country'   =>'BRASIL',
        'city'      => $city ? $city : '',
      ],
    ];
    return $payer;
}

function give_iugu_send_payment($payment_data){

  $user_token   = give_get_option('iugu_user_token', 'Iugu' );
  $live_token   = give_get_option('iugu_live_token', 'Iugu' );
  $test_token   = give_get_option('iugu_test_token', 'Iugu' );
  $test_enabled = give_get_option('iugu_test_enabled', 'Iugu' );
  $email    = give_get_option('iugu_account_email', 'Iugu' );

  if ($test_enabled == 'on') {
    Iugu::setApiKey($test_token); 
  } else {
    Iugu::setApiKey($user_token); 
  }

  $total_amount = $payment_data['price'] * 100;
  if($total_amount < 100){
    $total_amount = 100;
  }

  $due_date = iugu_add_days(6);


  $customer = Iugu_Customer::create([
    'email'=> $payment_data['user_email'],
    'name' => $purchase_data['user_info']['first_name'].' '.$purchase_data['user_info']['last_name'],
  ]);
  

  $invoice  = Iugu_Invoice::create([
    'customer_id'  => $customer->id,
    'email'        => $email,
    'due_date'     => $due_date->format('Y-m-d'),
    'payer'        => iugu_generate_payer(),
    'payable_with' =>'all',
    'ensure_workday_due_date'=>true,
    'items'        => [
      [
        'description' =>'Doação '.$purchase_data['post_data']['give-form-title'],
        'price_cents' =>$total_amount,
        'quantity'    =>1
      ]
    ]
  ]);
  return $invoice;
}

function give_iugu_payment( $purchase_data ) {

  if ( ! wp_verify_nonce( $purchase_data['gateway_nonce'], 'give-gateway' ) ) {
    wp_die( esc_html__( 'Nonce verification failed.', 'give' ), esc_html__( 'Error', 'give' ), array( 'response' => 403 ) );
  }

  //Create payment_data array
  $payment_data = array(
    'price'           => $purchase_data['price'],
    'give_form_title' => $purchase_data['post_data']['give-form-title'],
    'give_period_mode' => $purchase_data['post_data']['period-mode'],
    'give_form_id'    => intval( $purchase_data['post_data']['give-form-id'] ),
    'give_price_id'   => isset($purchase_data['post_data']['give-price-id']) ? $purchase_data['post_data']['give-price-id'] : '',
    'date'            => $purchase_data['date'],
    'user_email'      => $purchase_data['user_email'],
    'purchase_key'    => $purchase_data['purchase_key'],
    'currency'        => give_get_currency( $purchase_data['post_data']['give-form-id'], $purchase_data ),
    'user_info'       => $purchase_data['user_info'],
    'status'          => 'pending'
  );
  // Record the pending payment
  $payment = give_insert_payment( $payment_data );

  if ( $payment ) {
    $invoice = give_iugu_send_payment($payment_data);
    if($invoice['errors']){
      echo '<h1>Iugu Error</h1><br/>';
      echo 'Configuration error has occurred at the payment gateway, please inform the owner of the website.';
      
      if(is_array($invoice['errors'])){
        foreach ($invoice['errors']  as $name => $errors) {
        echo('<br/>');
          echo '<strong>'.$name.':</strong>';
          foreach ($errors  as $key => $error) {
            echo '<span>'.$error.'</span>,';
          }
        }
        echo('<br/>');
      } else {
         print_r('<br/><strong>'.$invoice['errors'].'</strong></br>');
      }
    } else {
      $invoice_data = Iugu_Invoice::fetch($invoice);
      give_update_payment_status( $payment, 'publish' );
      $iugu_invoice_url = $invoice['secure_url'];
      header("Location: ".$iugu_invoice_url);
    }
  } else {
    give_record_gateway_error(
      esc_html__( 'Payment Error', 'give' ),
      sprintf(
        /* translators: %s: payment data */
        esc_html__( 'The payment creation failed while processing a manual (free or test) donation. Payment data: %s', 'give' ),
        json_encode( $payment_data )
      ),
      $payment
    );
    // If errors are present, send the user back to the donation page so they can be corrected
    give_send_back_to_checkout( '?payment-mode=' . $purchase_data['post_data']['give-gateway'] );
  }
}

add_action( 'give_gateway_iugu', 'give_iugu_payment' );
