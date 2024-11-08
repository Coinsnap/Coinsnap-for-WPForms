<?php
namespace WPFormsCoinsnap;

if (!defined( 'ABSPATH' )){
    exit;
}

use WP_Post;
use WPForms_Builder_Panel_Settings;
use WPForms_Payment;
use WPForms\Db\Payments\ValueValidator;


class Plugin extends WPForms_Payment {

    private $allowed_to_process = false;
    private $amount = '';		
    private $payment_settings = [];
    public const WEBHOOK_EVENTS = ['New','Expired','Settled','Processing'];	 
        
    public static function get_instance() {
        static $instance;
        if ( ! $instance ) {
            $instance = new self();
	}
        return $instance;
    }

    public function init() {
        $this->version  = COINSNAP_VERSION;
        $this->name     = 'Coinsnap';
        $this->slug     = 'coinsnap';
        $this->priority = 10;
        $this->icon     = COINSNAP_WPFORMS_URL . 'assets/images/coinsnap_logo.png';
        $this->hooks();
    }

    private function hooks() {

		add_action( 'wpforms_process', [ $this, 'process_entry' ], 10, 3 );
		add_action( 'wpforms_process_complete', [ $this, 'process_payment' ], 20, 4 );
		add_filter( 'wpforms_forms_submission_prepare_payment_data', [ $this, 'prepare_payment_data' ], 10, 3 );
		add_filter( 'wpforms_forms_submission_prepare_payment_meta', [ $this, 'prepare_payment_meta' ], 10, 3 );
		add_action( 'wpforms_process_payment_saved', [ $this, 'process_payment_saved' ], 10, 3 );
                add_action('admin_notices', array($this, 'coinsnap_notice'));
                add_action( 'admin_enqueue_scripts', [ $this, 'enqueueCoinsnapCSS'], 25 );
		add_action( 'init', [ $this, 'process_webhook' ] );	
                
	}
        
        public function enqueueCoinsnapCSS(): void {
            wp_enqueue_style( 'CoinsnapPayment', COINSNAP_WPFORMS_URL . 'assets/css/coinsnap-style.css',array(),COINSNAP_VERSION );
        }

	public function coinsnap_notice(){
        
        $page = (filter_input(INPUT_GET,'page',FILTER_SANITIZE_FULL_SPECIAL_CHARS ) !== null)? filter_input(INPUT_GET,'page',FILTER_SANITIZE_FULL_SPECIAL_CHARS ) : '';
        
        if($page === 'wpforms-builder'){
            
            if(isset($this->form_data['payments'])){
                $payment_settings = $this->form_data['payments'][ $this->slug ];
                $this->payment_settings = $payment_settings;

                $coinsnap_url = $this->getApiUrl();
                $coinsnap_api_key = $this->getApiKey();
                $coinsnap_store_id = $this->getStoreId();
                $coinsnap_webhook_url = $this->get_webhook_url();
            }
            
            else {
                $coinsnap_url = '';
                $coinsnap_store_id = '';
                $coinsnap_api_key = '';
                $coinsnap_webhook_url = '';
            }
            
            echo '<div class="coinsnap-notices">';
            
                
                if(empty($coinsnap_store_id)){
                    echo '<div class="notice notice-error"><p>';
                    esc_html_e('Coinsnap Store ID is not set', 'coinsnap-for-wpforms');
                    echo '</p></div>';
                }

                if(empty($coinsnap_api_key)){
                    echo '<div class="notice notice-error"><p>';
                    esc_html_e('Coinsnap API Key is not set', 'coinsnap-for-wpforms');
                    echo '</p></div>';
                }
                
                if(!empty($coinsnap_api_key) && !empty($coinsnap_store_id)){
                    $client = new \Coinsnap\Client\Store($coinsnap_url, $coinsnap_api_key);
                    $store = $client->getStore($coinsnap_store_id);
                    if ($store['code'] === 200) {
                        echo '<div class="notice notice-success"><p>';
                        esc_html_e('Established connection to Coinsnap Server', 'coinsnap-for-wpforms');
                        echo '</p></div>';
                        
                        if ( ! $this->webhookExists( $coinsnap_store_id, $coinsnap_api_key, $coinsnap_webhook_url ) ) {
                            if ( ! $this->registerWebhook( $coinsnap_store_id, $coinsnap_api_key, $coinsnap_webhook_url ) ) {
                                echo '<div class="notice notice-error"><p>';
                                esc_html_e('Unable to create webhook on Coinsnap Server', 'coinsnap-for-wpforms');
                                echo '</p></div>';
                            }
                            else {
                                echo '<div class="notice notice-success"><p>';
                                esc_html_e('Successfully registered a new webhook on Coinsnap Server', 'coinsnap-for-wpforms');
                                echo '</p></div>';
                            }
                        }
                        else {
                            echo '<div class="notice notice-info"><p>';
                            esc_html_e('Webhook already exists, skipping webhook creation', 'coinsnap-for-wpforms');
                            echo '</p></div>';
                        }
                    }
                    else {
                        echo '<div class="notice notice-error"><p>';
                        esc_html_e('Coinsnap connection error:', 'coinsnap-for-wpforms');
                        echo esc_html($store['result']['message']);
                        echo '</p></div>';
                    }
                }
                echo '</div>';
        }
    }

	public function builder_content() {
		$statuses = ValueValidator::get_allowed_one_time_statuses();
		

		wpforms_panel_field(
			'toggle',
			$this->slug,
			'enable',
			$this->form_data,
			esc_html__( 'Enable Coinsnap payments', 'coinsnap-for-wpforms' ),
			[
				'parent'  => 'payments',
				'default' => '0',
			]
		);

		echo '<div class="wpforms-panel-content-section-coinsnap-body">';

		wpforms_panel_field(
			'text',
			$this->slug,
			'store_id',
			$this->form_data,
			esc_html__( 'Store Id', 'coinsnap-for-wpforms' ),
			[
				'parent'  => 'payments',
				'tooltip' => esc_html__( 'Enter Your Coinsnap Store ID', 'coinsnap-for-wpforms' ),
			]
		);		
		wpforms_panel_field(
			'text',
			$this->slug,
			'api_key',
			$this->form_data,
			esc_html__( 'API Key', 'coinsnap-for-wpforms' ),
			[
				'parent'  => 'payments',
				'tooltip' => esc_html__( 'Enter Your Coinsnap API Key', 'coinsnap-for-wpforms' ),
			]
		);		

		

		wpforms_panel_field(
			'select',
			$this->slug,
			'expired_status',
			$this->form_data,
			esc_html__( 'Expired Status', 'coinsnap-for-wpforms' ),
			[
				'parent'  => 'payments',
				'default' => 'failed',
				'options' => $statuses,
				'tooltip' => esc_html__( 'Select Expired Status', 'coinsnap-for-wpforms' ),
			]
		);

		wpforms_panel_field(
			'select',
			$this->slug,
			'settled_status',
			$this->form_data,
			esc_html__( 'Settled Status', 'coinsnap-for-wpforms' ),
			[
				'parent'  => 'payments',
				'default' => 'completed',
				'options' => $statuses,
				'tooltip' => esc_html__( 'Select Settled Status', 'coinsnap-for-wpforms' ),
			]
		);

		wpforms_panel_field(
			'select',
			$this->slug,
			'processing_status',
			$this->form_data,
			esc_html__( 'Processing Status', 'coinsnap-for-wpforms' ),
			[
				'parent'  => 'payments',
				'default' => 'processed',
				'options' => $statuses,
				'tooltip' => esc_html__( 'Select Processing Status', 'coinsnap-for-wpforms' ),
			]
		);
		

		echo '</div>';
	}

	public function process_entry( $fields, $entry, $form_data ) { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh

		$this->form_data = $form_data;
		$errors          = [];

		// Check if payment method exists.
		if ( empty( $this->form_data['payments'][ $this->slug ] ) ) {			
			return;
		}

		// Check required payment settings.
		$payment_settings = $this->form_data['payments'][ $this->slug ];		
		$this->payment_settings = $payment_settings;

		if ( ! empty( wpforms()->get( 'process' )->errors[ $this->form_data['id'] ] ) ) {			
			return;
		}

		
		$form_has_payments  = wpforms_has_payment( 'form', $this->form_data );
		$entry_has_paymemts = wpforms_has_payment( 'entry', $fields );

		if ( ! $form_has_payments || ! $entry_has_paymemts ) {
			$error_title = esc_html__( 'Coinsnap Payment stopped, missing payment fields', 'coinsnap-for-wpforms' );
			$errors[]    = $error_title;

			$this->log_errors( $error_title );
		} else {
			// Check total charge amount.
			$this->amount = wpforms_get_total_payment( $fields );

			if ( empty( $this->amount ) || $this->amount === wpforms_sanitize_amount( 0 ) ) {
				$error_title = esc_html__( 'Coinsnap Payment stopped, invalid/empty amount', 'coinsnap-for-wpforms' );
				$errors[]    = $error_title;

				$this->log_errors( $error_title );
			}
		}

		if ( $errors ) {
			$this->display_errors( $errors );

			return;
		}

		$this->allowed_to_process = true;
	}

	
	public function process_payment( $fields, $entry, $form_data, $entry_id ) { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.MaxExceeded


		if ( empty( $entry_id ) || ! $this->allowed_to_process ) {
			return;
		}

		// Update the entry type.
		wpforms()->get( 'entry' )->update(
			$entry_id,
			[ 'type' => 'payment' ],
			'',
			'',
			[ 'cap' => false ]
		);

		$payment_settings = $this->form_data['payments'][ $this->slug ];
		$this->payment_settings = $payment_settings;


		$webhook_url = $this->get_webhook_url();		
        
				
        if (! $this->webhookExists($this->getStoreId(), $this->getApiKey(), $webhook_url)){
            if (! $this->registerWebhook($this->getStoreId(), $this->getApiKey(),$webhook_url)) {                
                throw new PaymentGatewayException(esc_html('Unable to set Webhook url.', 'coinsnap-for-wpforms'));
                exit;
            }
         }      
				
		

		// Build the return URL with hash.
		$query_args = 'form_id=' . $this->form_data['id'] . '&entry_id=' . $entry_id . '&hash=' . wp_hash( $this->form_data['id'] . ',' . $entry_id );
		$return_url = is_ssl() ? 'https://' : 'http://';

		$server_name = isset( $_SERVER['SERVER_NAME'] ) ?
			sanitize_text_field( wp_unslash( $_SERVER['SERVER_NAME'] ) ) :
			'';
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ?
			esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) :
			'';

		$return_url .= $server_name . $request_uri;

		if ( ! empty( $this->form_data['settings']['ajax_submit'] ) && ! empty( $_SERVER['HTTP_REFERER'] ) ) {
			$return_url = esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) );
		}

		$return_url = esc_url_raw(
			add_query_arg(
				[				
					'wpforms_return' => base64_encode( $query_args ),
				],				
				apply_filters( 'wpforms_coinsnap_return_url', $return_url, $this->form_data ) // phpcs:ignore WPForms.PHP.ValidateHooks.InvalidHookName
			)
		);

		
		$invoice_no =  $entry_id;
		$customer_data = $this->get_customer($form_data, $entry);

		$amount = round($this->amount, 2);
        $buyerEmail = $customer_data['email'];				
        $buyerName = $customer_data['name'];        						    	

        $metadata = [];
        $metadata['orderNumber'] = $invoice_no;
        $metadata['customerName'] = $buyerName;
				

        $checkoutOptions = new \Coinsnap\Client\InvoiceCheckoutOptions();
        $checkoutOptions->setRedirectURL( $return_url );
        $client =new \Coinsnap\Client\Invoice($this->getApiUrl(), $this->getApiKey());
        $camount = \Coinsnap\Util\PreciseNumber::parseFloat($amount,2);
								
        $csinvoice = $client->createInvoice(
				$this->getStoreId(),  
			    	strtoupper( wpforms_get_currency() ),
			    	$camount,
			    	$invoice_no,
			    	$buyerEmail,
			    	$buyerName, 
			    	$return_url,
			    	COINSNAP_REFERRAL_CODE,     
			    	$metadata,
			    	$checkoutOptions
		    	);
				
		
        $payurl = $csinvoice->getData()['checkoutLink'] ;

		wp_redirect( $payurl );

		exit;
	}

	
	public function prepare_payment_data( $payment_data, $fields, $form_data ) {
		
		
		if ( ! $this->allowed_to_process ) {
			return $payment_data;
		}

		$payment_data['status']  = 'pending';
		$payment_data['gateway'] = sanitize_key( $this->slug );		
		$payment_data['mode'] = 'live';

		
		return $payment_data;
	}

	
	public function prepare_payment_meta( $payment_meta, $fields, $form_data ) {

		
		if ( ! $this->allowed_to_process ) {
			return $payment_meta;
		}

		$payment_meta['method_type'] = 'Coinsnap';

		return $payment_meta;
	}

	public function process_payment_saved( $payment_id, $fields, $form_data ) {

		$payment = wpforms()->get( 'payment' )->get( $payment_id );

		// If payment is not found, bail.
		if ( ! isset( $payment->id ) || ! $this->allowed_to_process ) {
			return;
		}

		$this->add_payment_log(
			$payment_id,
			sprintf(
				'Coinsnap payment created. (Entry ID: %s)',
				 $payment->entry_id
			)
		);
	}

	
	private function add_payment_log( $payment_id, $value ) {

		wpforms()->get( 'payment_meta' )->add(
			[
				'payment_id' => $payment_id,
				'meta_key'   => 'log',
				'meta_value' => wp_json_encode( 
					[
						'value' => $value,
						'date'  => gmdate( 'Y-m-d H:i:s' ),
					]
				),
			]
		);
	}

	
    public function process_webhook(){
        if ( null === filter_input(INPUT_GET,'wpforms-listener',FILTER_SANITIZE_FULL_SPECIAL_CHARS) || filter_input(INPUT_GET,'wpforms-listener',FILTER_SANITIZE_FULL_SPECIAL_CHARS) !== 'coinsnap' ) {
            return;
        }

        $notify_json = file_get_contents('php://input');        
        $form_id = filter_input(INPUT_GET,'form-id',FILTER_VALIDATE_INT);		

        $this->form_data = wpforms()->get( 'form' )->get($form_id,['content_only' => true,]);
        $payment_settings = $this->form_data['payments'][ $this->slug ];		
        $this->payment_settings = $payment_settings;

	$notify_ar = json_decode($notify_json, true);
        $invoice_id = $notify_ar['invoiceId'];        
			
        try {
            $client = new \Coinsnap\Client\Invoice( $this->getApiUrl(), $this->getApiKey() );			
            $csinvoice = $client->getInvoice($this->getStoreId(), $invoice_id);
            $status = $csinvoice->getData()['status'] ;
            $entry_id = $csinvoice->getData()['orderId'] ;				
        }
        catch (\Throwable $e) {													
            echo "Error";
            exit;
        }
	
	$payment = wpforms()->get( 'payment' )->get_by( 'entry_id', $entry_id );
        $this->form_data = wpforms()->get( 'form' )->get($payment->form_id,['content_only' => true,]);

        // If payment or form doesn't exist, bail.
        if ( empty( $payment ) || empty( $this->form_data ) ) {
            return;
        }


        $order_status = 'pending';
        
        if ($status == 'Expired'){
            $order_status = $this->payment_settings['expired_status'];
        }
        else if($status == 'Processing'){
            $order_status = $this->payment_settings['processing_status'];
        }
        else if($status == 'Settled'){
            $order_status = $this->payment_settings['settled_status'];
        }	

        $this->update_payment(
			$payment->id,
			[
				'status'         => $order_status,
				'transaction_id' => sanitize_text_field( $invoice_id ),
			]
		);

        $this->add_payment_log(
			$payment->id,
			sprintf(
				'Coinsnap payment status :'.$status.'. (Invoice ID: '.$invoice_id.')',				
			)
		);
		
        echo "OK";
        exit;
    }


	private function update_payment( $payment_id, $data = [] ) {

		if ( ! wpforms()->get( 'payment' )->update( $payment_id, $data, '', '', [ 'cap' => false ] ) ) {

			wpforms_log(
				'Coinsnap IPN Error: Payment update failed',
				[
					'payment_id' => $payment_id,
					'data'       => $data,
				]
			);

			exit;
		}		
	}


	private function get_customer($form_data, $entry){
        $name = '';
        $email = '';
        $phone = '';
        if (!empty($form_data) && !empty($entry)) {
            foreach ($form_data['fields'] as $num => $arr){
                switch ($arr['type']) {
                    case 'name':
                        if ('simple' === $arr['format']){
                            $name = $entry['fields'][$arr['id']];
                        }
                        elseif ('first-last' === $arr['format']){
                            $name = '';
                            if (isset($entry['fields'][$arr['id']]['first'])){
                                $name = $entry['fields'][$arr['id']]['first'];
                            }

                            if (isset($entry['fields'][$arr['id']]['last'])) {
                                $name .= ' '.$entry['fields'][$arr['id']]['last'];
                            }
                        }
                        elseif ('first-middle-last' === $arr['format']) {
                            $name = '';
                            if (isset($entry['fields'][$arr['id']]['first'])) {
                                $name = $entry['fields'][$arr['id']]['first'];
                            }

                            if (isset($entry['fields'][$arr['id']]['middle'])) {
                                $name .= ' '.$entry['fields'][$arr['id']]['middle'];
                            }

                            if (isset($entry['fields'][$arr['id']]['last'])) {
                                $name .= ' '.$entry['fields'][$arr['id']]['last'];
                            }
                        }
                        break;
                    case 'email':
                        $email = $entry['fields'][$arr['id']];
                        break;
                    case 'phone':
                        $phone = $entry['fields'][$arr['id']];
                        break;
                }
            }
        }

        return [
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
        ];
    }
	public function get_webhook_url() {		
        return esc_url_raw( add_query_arg( array( 'wpforms-listener' => 'coinsnap', 'form-id'=>$this->form_data['id'] ), home_url( 'index.php' ) ) );
    }
	public function getStoreId() {
        return $this->payment_settings['store_id'];
    }
    public function getApiKey() {
        return $this->payment_settings['api_key'] ;
    }
    
    public function getApiUrl() {
        return COINSNAP_SERVER_URL;
    }	

    public function webhookExists(string $storeId, string $apiKey, string $webhook): bool {	
        try {		
            $whClient = new \Coinsnap\Client\Webhook( $this->getApiUrl(), $apiKey );		
            $Webhooks = $whClient->getWebhooks( $storeId );
            
            foreach ($Webhooks as $Webhook){					
                if ($Webhook->getData()['url'] == $webhook) return true;	
            }
        }catch (\Throwable $e) {			
            return false;
        }
    
        return false;
    }
    public  function registerWebhook(string $storeId, string $apiKey, string $webhook): bool {	
        try {			
            $whClient = new \Coinsnap\Client\Webhook($this->getApiUrl(), $apiKey);
            
            $webhook = $whClient->createWebhook(
                $storeId,   //$storeId
                $webhook, //$url
                self::WEBHOOK_EVENTS,   
                null    //$secret
            );		
            
            return true;
        } catch (\Throwable $e) {
            return false;	
        }

        return false;
    }

    public function deleteWebhook(string $storeId, string $apiKey, string $webhookid): bool {	    
        
        try {			
            $whClient = new \Coinsnap\Client\Webhook($this->getApiUrl(), $apiKey);
            
            $webhook = $whClient->deleteWebhook(
                $storeId,   //$storeId
                $webhookid, //$url			
            );					
            return true;
        } catch (\Throwable $e) {
            
            return false;	
        }
    }


}
