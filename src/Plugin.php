<?php
namespace WPFormsCoinsnap;

if (!defined( 'ABSPATH' )){
    exit;
}

use WP_Post;
use WPForms_Builder_Panel_Settings;
use WPForms_Payment;
use WPForms\Db\Payments\ValueValidator;
use Coinsnap\Client\Webhook;

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
        $this->version  = COINSNAP_WPFORMS_VERSION;
        $this->name     = 'Coinsnap';
        $this->slug     = 'coinsnap';
        $this->priority = 10;
        $this->icon     = COINSNAP_WPFORMS_URL . 'assets/images/coinsnap_logo.png';
        $this->hooks();
    }

    private function hooks(){

        add_action( 'wpforms_process', [ $this, 'process_entry' ], 10, 3 );
        add_action( 'wpforms_process_complete', [ $this, 'process_payment' ], 20, 4 );
        add_filter( 'wpforms_forms_submission_prepare_payment_data', [ $this, 'prepare_payment_data' ], 10, 3 );
	add_filter( 'wpforms_forms_submission_prepare_payment_meta', [ $this, 'prepare_payment_meta' ], 10, 3 );
	add_action( 'wpforms_process_payment_saved', [ $this, 'process_payment_saved' ], 10, 3 );
	add_action( 'init', [ $this, 'process_webhook' ] );
        
        if (is_admin()) {
            add_action( 'admin_notices', array($this, 'coinsnap_notice'));
            add_action( 'admin_enqueue_scripts', [$this, 'enqueueAdminScripts'] );
            add_action( 'wp_ajax_coinsnap_connection_handler', [$this, 'coinsnapConnectionHandler'] );
            add_action( 'wp_ajax_btcpay_server_apiurl_handler', [$this, 'btcpayApiUrlHandler']);
        }
        
        // Adding template redirect handling for coinsnap-for-wpforms-btcpay-settings-callback.
        add_action( 'template_redirect', function(){
    
            global $wp_query;
            $notice = new \Coinsnap\Util\Notice();
            
            $form_id = filter_input(INPUT_GET,'form_id',FILTER_SANITIZE_FULL_SPECIAL_CHARS);
            
            $form = wpforms()->form->get( absint( $form_id ) );
            $this->form_data = json_decode($form->post_content, true);

            // Only continue on a coinsnap-for-wpforms-btcpay-settings-callback request.    
            if (!isset( $wp_query->query_vars['coinsnap-for-wpforms-btcpay-settings-callback']) || !isset($this->form_data['payments'][ $this->slug ])) {
                return;
            }
            
            $payment_settings = $this->form_data['payments'][ $this->slug ];
            $this->payment_settings = $payment_settings;

            $CoinsnapBTCPaySettingsUrl = admin_url('admin.php?page=wpforms-builder&view=payments&form_id='.$form_id.'&section=coinsnap&provider=btcpay');

            $rawData = file_get_contents('php://input');

            $btcpay_server_url = $this->payment_settings['btcpay_server_url'];
            $btcpay_api_key  = filter_input(INPUT_POST,'apiKey',FILTER_SANITIZE_FULL_SPECIAL_CHARS);
            
            $client = new \Coinsnap\Client\Store($btcpay_server_url,$btcpay_api_key);
            if (count($client->getStores()) < 1) {
                $messageAbort = __('Error on verifiying redirected API Key with stored BTCPay Server url. Aborting API wizard. Please try again or continue with manual setup.', 'coinsnap-for-wpforms');
                $notice->addNotice('error', $messageAbort);
                wp_redirect($CoinsnapBTCPaySettingsUrl);
            }

            // Data does get submitted with url-encoded payload, so parse $_POST here.
            if (!empty($_POST) || wp_verify_nonce(filter_input(INPUT_POST,'wp_nonce',FILTER_SANITIZE_FULL_SPECIAL_CHARS),'-1')) {
                $data['apiKey'] = filter_input(INPUT_POST,'apiKey',FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? null;
                $permissions = (isset($_POST['permissions']) && is_array($_POST['permissions']))? $_POST['permissions'] : null;
                    if (isset($permissions)) {
                        foreach ($permissions as $key => $value) {
                        $data['permissions'][$key] = sanitize_text_field($permissions[$key] ?? null);
                    }
                }
            }
            
            if (isset($data['apiKey']) && isset($data['permissions'])) {

                $apiData = new \Coinsnap\Client\BTCPayApiAuthorization($data);
                if ($apiData->hasSingleStore() && $apiData->hasRequiredPermissions()) {

                    $this->coinsnap_settings_update($form_id,[
                        'btcpay_api_key' => $apiData->getApiKey(),
                        'btcpay_store_id' => $apiData->getStoreID(),
                        'coinsnap_provider' => 'btcpay'
                        ]);

                    $notice->addNotice('success', __('Successfully received api key and store id from BTCPay Server API. Please finish setup by saving this settings form.', 'coinsnap-for-wpforms'));

                    // Register a webhook.
                    if ($this->registerWebhook( $apiData->getStoreID(), $apiData->getApiKey(), $this->get_webhook_url())) {
                        $messageWebhookSuccess = __( 'Successfully registered a new webhook on BTCPay Server.', 'coinsnap-for-wpforms' );
                        $notice->addNotice('success', $messageWebhookSuccess);
                    }
                    else {
                        $messageWebhookError = __( 'Could not register a new webhook on the store.', 'coinsnap-for-wpforms' );
                        $notice->addNotice('error', $messageWebhookError );
                    }

                    wp_redirect($CoinsnapBTCPaySettingsUrl);
                    exit();
                }
                else {
                    $notice->addNotice('error', __('Please make sure you only select one store on the BTCPay API authorization page.', 'coinsnap-for-wpforms'));
                    wp_redirect($CoinsnapBTCPaySettingsUrl);
                    exit();
                }
            }

            $notice->addNotice('error', __('Error processing the data from Coinsnap. Please try again.', 'coinsnap-for-wpforms'));
            wp_redirect($CoinsnapBTCPaySettingsUrl);
            exit();
        });
    }
        
    public function enqueueAdminScripts(): void {
        // Register the CSS file
	wp_register_style( 'coinsnap-admin-styles', COINSNAP_WPFORMS_URL . 'assets/css/coinsnap-style.css', array(), COINSNAP_WPFORMS_VERSION );
	// Enqueue the CSS file
	wp_enqueue_style( 'coinsnap-admin-styles' );
        
        if('payments' === filter_input(INPUT_GET,'view',FILTER_SANITIZE_FULL_SPECIAL_CHARS) && 'coinsnap' === filter_input(INPUT_GET,'section',FILTER_SANITIZE_FULL_SPECIAL_CHARS)){
            wp_enqueue_script('coinsnap-admin-fields',COINSNAP_WPFORMS_URL . 'assets/js/adminFields.js',[ 'jquery' ],COINSNAP_WPFORMS_VERSION,true);
        }
        
        wp_enqueue_script('coinsnap-connection-check',COINSNAP_WPFORMS_URL . 'assets/js/connectionCheck.js',[ 'jquery' ],COINSNAP_WPFORMS_VERSION,true);
        wp_localize_script('coinsnap-connection-check', 'coinsnap_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'  => wp_create_nonce( 'coinsnap-ajax-nonce' ),
            'form_id' => sanitize_text_field( filter_input(INPUT_GET,'form_id',FILTER_VALIDATE_INT) )
        ));
    }
    
    public function coinsnapConnectionHandler(){
        $form_id = filter_input(INPUT_POST,'form_id',FILTER_SANITIZE_STRING);
        $form = wpforms()->form->get( absint( $form_id ) );
        $form_data = json_decode($form->post_content, true);
        $this->form_data = $form_data;
        
        if(isset($this->form_data['payments'])){
            $payment_settings = $this->form_data['payments'][ $this->slug ];
            $this->payment_settings = $payment_settings;

            $_nonce = filter_input(INPUT_POST,'_wpnonce',FILTER_SANITIZE_STRING);
            
            if(empty($this->getApiUrl()) || empty($this->getApiKey())){
                $response = [
                    'result' => false,
                    'message' => __('WP Forms: Payment gateway is disconnected', 'coinsnap-for-wpforms')
                ];
                $this->sendJsonResponse($response);
            }
            
            
            $_provider = $this->get_payment_provider();

            $client = new \Coinsnap\Client\Invoice($this->getApiUrl(),$this->getApiKey());
            $currency = strtoupper( wpforms_get_currency() );
            $store = new \Coinsnap\Client\Store($this->getApiUrl(),$this->getApiKey());

            if(isset($_provider) && $_provider === 'btcpay'){

                $storePaymentMethods = $store->getStorePaymentMethods($this->getStoreId());

                if ($storePaymentMethods['code'] === 200) {
                    if($storePaymentMethods['result']['onchain'] && !$storePaymentMethods['result']['lightning']){
                        $checkInvoice = $client->checkPaymentData(0,$currency,'bitcoin','calculation');
                    }
                    elseif($storePaymentMethods['result']['lightning']){
                        $checkInvoice = $client->checkPaymentData(0,$currency,'lightning','calculation');
                    }
                }
                else {

                }
            }
            else {
                $checkInvoice = $client->checkPaymentData(0,$currency,'coinsnap','calculation');
            }

            if(isset($checkInvoice) && $checkInvoice['result']){
                $connectionData = __('Min order amount is', 'coinsnap-for-wpforms') .' '. $checkInvoice['min_value'].' '.$currency;
            }
            else {
                $connectionData = __('No payment method is configured', 'coinsnap-for-wpforms');
            }

            $_message_disconnected = ($_provider !== 'btcpay')? 
                __('WP Forms: Coinsnap server is disconnected', 'coinsnap-for-wpforms') :
                __('WP Forms: BTCPay server is disconnected', 'coinsnap-for-wpforms');
            $_message_connected = ($_provider !== 'btcpay')?
                __('WP Forms: Coinsnap server is connected', 'coinsnap-for-wpforms') : 
                __('WP Forms: BTCPay server is connected', 'coinsnap-for-wpforms');

            if( wp_verify_nonce($_nonce,'coinsnap-ajax-nonce') ){
                $response = ['result' => false,'message' => $_message_disconnected];

                try {
                    $this_store = $store->getStore($this->getStoreId());

                    if ($this_store['code'] !== 200) {
                        $this->sendJsonResponse($response);
                    }

                    $webhookExists = $this->webhookExists($this->getApiUrl(), $this->getApiKey(), $this->getStoreId());

                    if($webhookExists) {
                        $response = ['result' => true,'message' => $_message_connected.' ('.$connectionData.')'];
                        $this->sendJsonResponse($response);
                    }

                    $webhook = $this->registerWebhook($this->getApiUrl(), $this->getApiKey(), $this->getStoreId());
                    $response['result'] = (bool)$webhook;
                    $response['message'] = $webhook ? $_message_connected.' ('.$connectionData.')' : $_message_disconnected.' (Webhook)';
                    $response['display'] = get_option('coinsnap_connection_status_display');
                }
                catch (Exception $e) {
                    $response['message'] = $e->getMessage();
                }

                $this->sendJsonResponse($response);
            }
        }     
    }

    private function sendJsonResponse(array $response): void {
        echo wp_json_encode($response);
        exit();
    }
    
    /**
     * Handles the BTCPay server AJAX callback from the settings form.
     */
    public function btcpayApiUrlHandler() {
        $form_id = filter_input(INPUT_POST,'form_id',FILTER_SANITIZE_STRING);
        $_nonce = filter_input(INPUT_POST,'apiNonce',FILTER_SANITIZE_STRING);
        if ( !wp_verify_nonce( $_nonce, 'coinsnap-ajax-nonce' ) ) {
            wp_die('Unauthorized!', '', ['response' => 401]);
        }
        
        if ( current_user_can( 'manage_options' ) ) {
            $host = filter_var(filter_input(INPUT_POST,'host',FILTER_SANITIZE_STRING), FILTER_VALIDATE_URL);

            if ($host === false || (substr( $host, 0, 7 ) !== "http://" && substr( $host, 0, 8 ) !== "https://")) {
                wp_send_json_error("Error validating BTCPayServer URL.");
            }

            $permissions = array_merge([
		'btcpay.store.canviewinvoices',
		'btcpay.store.cancreateinvoice',
		'btcpay.store.canviewstoresettings',
		'btcpay.store.canmodifyinvoices'
            ],
            [
		'btcpay.store.cancreatenonapprovedpullpayments',
		'btcpay.store.webhooks.canmodifywebhooks',
            ]);

            try {
		// Create the redirect url to BTCPay instance.
		$url = \Coinsnap\Client\BTCPayApiKey::getAuthorizeUrl(
                    $host,
                    $permissions,
                    'WPForms',
                    true,
                    true,
                    home_url('?coinsnap-for-wpforms-btcpay-settings-callback&form_id='.$form_id),
                    null
		);

		// Store the host to options before we leave the site.
		$this->coinsnap_settings_update($form_id,['btcpay_server_url' => $host]);

		// Return the redirect url.
		wp_send_json_success(['url' => $url]);
            }
            
            catch (\Throwable $e) {
                Logger::debug('Error fetching redirect url from BTCPay Server.');
            }
	}
        wp_send_json_error("Error processing Ajax request.");
    }
    
    public function coinsnap_settings_update($form_id,$data){
        
        $form = wpforms()->form->get( absint( $form_id ) );
        $form_data = json_decode($form->post_content, true);
        
        foreach($data as $key => $value){
            $form_data['payments'][ $this->slug ][$key] = $value;
        }
        
        wpforms()->obj( 'form' )->update($form_id,$form_data);
    }    
    

    public function coinsnap_notice(){
        
        $page = (filter_input(INPUT_GET,'page',FILTER_SANITIZE_FULL_SPECIAL_CHARS ) !== null)? filter_input(INPUT_GET,'page',FILTER_SANITIZE_FULL_SPECIAL_CHARS ) : '';
        $notices = new \Coinsnap\Util\Notice(); 
        
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
            
                $notices->showNotices();
                
                if(empty($coinsnap_store_id)){
                    echo '<div class="notice notice-error"><p>';
                    esc_html_e('WP Forms: Coinsnap Store ID is not set', 'coinsnap-for-wpforms');
                    echo '</p></div>';
                }

                if(empty($coinsnap_api_key)){
                    echo '<div class="notice notice-error"><p>';
                    esc_html_e('WP Forms: Coinsnap API Key is not set', 'coinsnap-for-wpforms');
                    echo '</p></div>';
                }
                
                if(!empty($coinsnap_api_key) && !empty($coinsnap_store_id)){
                    $client = new \Coinsnap\Client\Store($coinsnap_url, $coinsnap_api_key);
                    try {
                        $store = $client->getStore($coinsnap_store_id);
                    }
                    catch (\Exception $e) {
                        echo '<div class="notice notice-success"><p>';
                        esc_html($e->getMessage());
                        echo '</p></div>';
                    }
                    if ($store['code'] === 200) {
                        echo '<div class="notice notice-success"><p>';
                        esc_html_e('WP Forms: Established connection to Coinsnap Server', 'coinsnap-for-wpforms');
                        echo '</p></div>';
                        
                        if ( ! $this->webhookExists($this->getApiUrl(), $this->getApiKey(), $this->getStoreId()) ) {
                            if ( ! $this->registerWebhook($this->getApiUrl(), $this->getApiKey(), $this->getStoreId()) ) {
                                echo '<div class="notice notice-error"><p>';
                                esc_html_e('WP Forms: Unable to create webhook on Coinsnap Server', 'coinsnap-for-wpforms');
                                echo '</p></div>';
                            }
                            else {
                                echo '<div class="notice notice-success"><p>';
                                esc_html_e('WP Forms: Successfully registered webhook on Coinsnap Server', 'coinsnap-for-wpforms');
                                echo '</p></div>';
                            }
                        }
                        else {
                            echo '<div class="notice notice-info"><p>';
                            esc_html_e('WP Forms: Webhook already exists, skipping webhook creation', 'coinsnap-for-wpforms');
                            echo '</p></div>';
                        }
                    }
                    else {
                        echo '<div class="notice notice-error"><p>';
                        esc_html_e('WP Forms: Coinsnap connection error:', 'coinsnap-for-wpforms');
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

		echo '<div class="wpforms-panel-content-section-coinsnap-body"><div id="coinsnapConnectionStatus"></div>';

		wpforms_panel_field(
			'select',
			$this->slug,
			'coinsnap_provider',
			$this->form_data,
			esc_html__( 'Payment provider', 'coinsnap-for-wpforms' ),
			[
                            'parent'  => 'payments',
                            'default' => '',
                            'options' => [
                                'coinsnap'  => 'Coinsnap',
                                'btcpay'    => 'BTCPay Server'
                            ],
                            'tooltip' => esc_html__( 'Select payment provider', 'coinsnap-for-wpforms' ),
			]
		);

		
                //  Coinsnap fields
                wpforms_panel_field(
			'text',
			$this->slug,
			'store_id',
			$this->form_data,
			esc_html__( 'Store Id*', 'coinsnap-for-wpforms' ),
			[
				'parent'  => 'payments',
				'tooltip' => esc_html__( 'Enter Your Coinsnap Store ID', 'coinsnap-for-wpforms' ),
                            'class' => 'coinsnap'
			]
		);		
		wpforms_panel_field(
			'text',
			$this->slug,
			'api_key',
			$this->form_data,
			esc_html__( 'API Key*', 'coinsnap-for-wpforms' ),
			[
				'parent'  => 'payments',
				'tooltip' => esc_html__( 'Enter Your Coinsnap API Key', 'coinsnap-for-wpforms' ),
                            'class' => 'coinsnap'
			]
		);
                
                //  BTCPay server fields
                wpforms_panel_field(
			'text',
			$this->slug,
			'btcpay_server_url',
			$this->form_data,
			esc_html__( 'BTCPay server URL*', 'coinsnap-for-wpforms' ),
			[
				'parent'  => 'payments',
				'tooltip' => esc_html__( 'Enter Your BTCPay server URL', 'coinsnap-for-wpforms' ),
                            'class' => 'btcpay',
			]
		);
                
                echo '<div class="wpforms-panel-field btcpay"><a href="#" class="btcpay-apikey-link">' . esc_html__( 'Check connection', 'coinsnap-for-wpforms' ).'</a><br/><br/><button class="button btcpay-apikey-link" id="btcpay_wizard_button" target="_blank">'. esc_html__('Generate API key','coinsnap-for-wpforms').'</button></div>';
		
                wpforms_panel_field(
			'text',
			$this->slug,
			'btcpay_store_id',
			$this->form_data,
			esc_html__( 'Store Id*', 'coinsnap-for-wpforms' ),
			[
				'parent'  => 'payments',
				'tooltip' => esc_html__( 'Enter Your BTCPay Store ID', 'coinsnap-for-wpforms' ),
                            'class' => 'btcpay'
			]
		);		
		wpforms_panel_field(
			'text',
			$this->slug,
			'btcpay_api_key',
			$this->form_data,
			esc_html__( 'API Key*', 'coinsnap-for-wpforms' ),
			[
				'parent'  => 'payments',
				'tooltip' => esc_html__( 'Enter Your BTCPay API Key', 'coinsnap-for-wpforms' ),
                            'class' => 'btcpay'
			]
		);

		wpforms_panel_field(
			'toggle',
			$this->slug,
			'autoredirect',
			$this->form_data,
			esc_html__( 'Redirect after payment', 'coinsnap-for-wpforms' ),
			[
				'parent'  => 'payments',
				'default' => '1',
				'tooltip' => esc_html__( 'Redirect after payment on Thank you page automatically', 'coinsnap-for-wpforms' ),
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
    
    function amount_validation( $amount, $currency ) {
        $client =new \Coinsnap\Client\Invoice($this->getApiUrl(), $this->getApiKey());
        $store = new \Coinsnap\Client\Store($this->getApiUrl(), $this->getApiKey());
        
        try {
            $this_store = $store->getStore($this->getStoreId());
            $_provider = $this->get_payment_provider();
            if($_provider === 'btcpay'){
                try {
                    $storePaymentMethods = $store->getStorePaymentMethods($this->getStoreId());

                    if ($storePaymentMethods['code'] === 200) {
                        if(!$storePaymentMethods['result']['onchain'] && !$storePaymentMethods['result']['lightning']){
                            $errorMessage = __( 'No payment method is configured on BTCPay server', 'coinsnap-for-wpforms' );
                            $checkInvoice = array('result' => false,'error' => esc_html($errorMessage));
                        }
                    }
                    else {
                        $errorMessage = __( 'Error store loading. Wrong or empty Store ID', 'coinsnap-for-wpforms' );
                        $checkInvoice = array('result' => false,'error' => esc_html($errorMessage));
                    }

                    if($storePaymentMethods['result']['onchain'] && !$storePaymentMethods['result']['lightning']){
                        $checkInvoice = $client->checkPaymentData((float)$amount,strtoupper( $currency ),'bitcoin');
                    }
                    elseif($storePaymentMethods['result']['lightning']){
                        $checkInvoice = $client->checkPaymentData((float)$amount,strtoupper( $currency ),'lightning');
                    }
                }
                catch (\Throwable $e){
                    $errorMessage = __( 'API connection is not established', 'coinsnap-for-wpforms' );
                    $checkInvoice = array('result' => false,'error' => esc_html($errorMessage));
                }
            }
            else {
                $checkInvoice = $client->checkPaymentData((float)$amount,strtoupper( $currency ));
            }
        }
        catch (\Throwable $e){
            $errorMessage = __( 'API connection is not established', 'coinsnap-for-wpforms' );
            $checkInvoice = array('result' => false,'error' => esc_html($errorMessage));
        }
        return $checkInvoice;
    }

    public function process_entry( $fields, $entry, $form_data ) {

        $this->form_data = $form_data;
        $errors          = [];

        // Check if payment method exists.
        if ( empty( $this->form_data['payments'][ $this->slug ] ) ) { return; }

        // Check required payment settings.
        $payment_settings = $this->form_data['payments'][ $this->slug ];		
        $this->payment_settings = $payment_settings;

        if ( ! empty( wpforms()->get( 'process' )->errors[ $this->form_data['id'] ] ) ) {return;}

		
        $form_has_payments  = wpforms_has_payment( 'form', $this->form_data );
        $entry_has_paymemts = wpforms_has_payment( 'entry', $fields );
        $webhook_url = $this->get_webhook_url();

        //  If form hasn't payment or entry hasn't payment
        if ( ! $form_has_payments || ! $entry_has_paymemts ) {
            $error_title = esc_html__( 'Coinsnap Payment stopped, missing payment fields', 'coinsnap-for-wpforms' );
            $errors[]    = $error_title;
            $this->log_errors( $error_title );
        }
        
        //  Connection, total amount and currency check
        else {
            $this->amount = wpforms_get_total_payment( $fields );
            $amount = round($this->amount, 2);
            $currency = strtoupper( wpforms_get_currency() );
            
            $client =new \Coinsnap\Client\Invoice($this->getApiUrl(), $this->getApiKey());
            $checkInvoice = $this->amount_validation($amount,strtoupper( $currency ));
                
            if($checkInvoice['result'] === false){
                if($checkInvoice['error'] === 'currencyError'){
                    $errorMessage = sprintf( 
                    /* translators: 1: Currency */
                    esc_html__( 'Currency %1$s is not supported by Coinsnap', 'coinsnap-for-wpforms' ), strtoupper( $currency ));
                }      
                elseif($checkInvoice['error'] === 'amountError'){
                    $errorMessage = sprintf( 
                    /* translators: 1: Amount, 2: Currency */
                    esc_html__( 'Invoice amount cannot be less than %1$s %2$s', 'coinsnap-for-wpforms' ), $checkInvoice['min_value'], strtoupper( $currency ));
                }
                else {
                    $errorMessage = $checkInvoice['error'];
                }
                $error_title = $errorMessage;
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
        
    /**
     * Log payment error.
     * @param string       $title    Error title.
     * @param array|string $messages Error messages.
     * @param string       $level    Error level to add to 'payment' error level.
     */
    public function log_errors( $title, $messages = [], $level = 'error' ) {
        wpforms_log(
            $title,
            $messages,
            [
                'type'    => [ 'payment', $level ],
		'form_id' => $this->form_data['id'],
            ]
	);
    }

    /**
     * Display form errors.
     * @param array $errors Errors to display.
     */
    public function display_errors( $errors ) {
        if ( ! $errors || ! is_array( $errors ) ) { return; }
        wpforms()->get( 'process' )->errors[ $this->form_data['id'] ]['footer'] = implode( '<br>', $errors );
    }
	
    public function process_payment( $fields, $entry, $form_data, $entry_id ) {
        
        if ( empty( $entry_id ) || ! $this->allowed_to_process ) { return; }

        // Update the entry type.
        wpforms()->get( 'entry' )->update($entry_id,[ 'type' => 'payment' ],'','',[ 'cap' => false ]);

        $payment_settings = $this->form_data['payments'][ $this->slug ];
	$this->payment_settings = $payment_settings;

        // Build the return URL with hash.
        $query_args = 'form_id=' . $this->form_data['id'] . '&entry_id=' . $entry_id . '&hash=' . wp_hash( $this->form_data['id'] . ',' . $entry_id );
        $return_url = is_ssl() ? 'https://' : 'http://';

        $server_name = isset( $_SERVER['SERVER_NAME'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_NAME'] ) ) : '';
        $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
        $return_url .= $server_name . $request_uri;

        if ( ! empty( $this->form_data['settings']['ajax_submit'] ) && ! empty( $_SERVER['HTTP_REFERER'] ) ) {
            $return_url = esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) );
        }

        $return_url = esc_url_raw(
            add_query_arg([				
                'wpforms_return' => base64_encode( $query_args ),
                ],				
                apply_filters( 'wpforms_coinsnap_return_url', $return_url, $this->form_data ) // phpcs:ignore WPForms.PHP.ValidateHooks.InvalidHookName
            )
	);

	$invoice_no =  $entry_id;
	$customer_data = $this->get_customer($form_data, $entry);

	$amount = round($this->amount, 2);
        $currency = strtoupper( wpforms_get_currency() );
                
        $client =new \Coinsnap\Client\Invoice($this->getApiUrl(), $this->getApiKey());
        
        $buyerEmail = $customer_data['email'];				
        $buyerName = $customer_data['name'];        						    	

        $metadata = [];
        $metadata['orderNumber'] = $invoice_no;
        $metadata['customerName'] = $buyerName;

        $checkoutOptions = new \Coinsnap\Client\InvoiceCheckoutOptions();
        $checkoutOptions->setRedirectURL( $return_url );
        
        $camount = \Coinsnap\Util\PreciseNumber::parseFloat($amount,2);
        
        // Handle Sats-mode because BTCPay does not understand SAT as a currency we need to change to BTC and adjust the amount.
        if ($currency === 'SATS' && $_provider === 'btcpay') {
            $currency = 'BTC';
            $amountBTC = bcdiv($camount->__toString(), '100000000', 8);
            $camount = \Coinsnap\Util\PreciseNumber::parseString($amountBTC);
        }
        
        $redirectAutomatically = ($this->payment_settings['autoredirect'] == 0)? false : true;
        $walletMessage = '';
        
        try {
								
            $csinvoice = $client->createInvoice(
                $this->getStoreId(),  
                $currency,
                $camount,
                $invoice_no,
                $buyerEmail,
                $buyerName, 
                $return_url,
                COINSNAP_WPFORMS_REFERRAL_CODE,     
                $metadata,
                $redirectAutomatically,
                $walletMessage
            );		

            $payurl = $csinvoice->getData()['checkoutLink'];
            wp_redirect( $payurl );
            exit;
        }
        catch (\Throwable $e){
            $errorMessage = __( 'API connection is not established', 'coinsnap-for-wpforms' );
            return false;
        }
    }

    public function prepare_payment_data( $payment_data, $fields, $form_data ) {
        
        if ( ! $this->allowed_to_process ) {return $payment_data;}
        $payment_data['status']  = 'pending';
        $payment_data['gateway'] = sanitize_key( $this->slug );		
        $payment_data['mode'] = 'live';
        return $payment_data;
    }

    public function prepare_payment_meta( $payment_meta, $fields, $form_data ) {

        if ( ! $this->allowed_to_process ) {return $payment_meta;}
        $payment_meta['method_type'] = 'Coinsnap';
        return $payment_meta;
    }

    public function process_payment_saved( $payment_id, $fields, $form_data ) {

        $payment = wpforms()->get( 'payment' )->get( $payment_id );

        // If payment is not found
        if ( ! isset( $payment->id ) || ! $this->allowed_to_process ) {return;}

        $this->add_payment_log(
            $payment_id,
            sprintf(
		'Coinsnap payment created. (Entry ID: %s)',
		$payment->entry_id
            )
	);
    }

    private function add_payment_log( $payment_id, $value ) {

        wpforms()->get( 'payment_meta' )->add([
            'payment_id' => $payment_id,
            'meta_key'   => 'log',
            'meta_value' => wp_json_encode(['value' => $value,'date'  => gmdate( 'Y-m-d H:i:s' ),]),
	]);
    }

	
    public function process_webhook(){
        
        //  wpforms-listener get parameter check
        if ( null === filter_input(INPUT_GET,'wpforms-listener',FILTER_SANITIZE_FULL_SPECIAL_CHARS) || filter_input(INPUT_GET,'wpforms-listener',FILTER_SANITIZE_FULL_SPECIAL_CHARS) !== 'coinsnap' ) {
            return;
        }
        //  form_id get parameter check
        $form_id = filter_input(INPUT_GET,'form-id',FILTER_VALIDATE_INT);
        if ( $form_id < 1 ) {
            return;
        }
        
        try {
            // First check if we have any input
            $rawPostData = file_get_contents("php://input");
            if (!$rawPostData) {
                wp_die('No raw post data received', '', ['response' => 400]);
            }

            // Get headers and check for signature
            $headers = getallheaders();
            $signature = null; $payloadKey = null;
            $_provider = ($this->get_payment_provider() === 'btcpay')? 'btcpay' : 'coinsnap';
                
            foreach ($headers as $key => $value) {
                if ((strtolower($key) === 'x-coinsnap-sig' && $_provider === 'coinsnap') || (strtolower($key) === 'btcpay-sig' && $_provider === 'btcpay')) {
                        $signature = $value;
                        $payloadKey = strtolower($key);
                }
            }

            // Handle missing or invalid signature
            if (!isset($signature)) {
                wp_die('Authentication required', '', ['response' => 401]);
            }

            // Validate the signature
            $webhook = get_option( 'wpforms_settings_coinsnap_webhook_'.$form_id);
            if (!Webhook::isIncomingWebhookRequestValid($rawPostData, $signature, $webhook['secret'])) {
                wp_die('Invalid authentication signature', '', ['response' => 401]);
            }

            // Parse the JSON payload
            $postData = json_decode($rawPostData, false, 512, JSON_THROW_ON_ERROR);

            if (!isset($postData->invoiceId)) {
                wp_die('No Coinsnap invoiceId provided', '', ['response' => 400]);
            }
            
            $invoice_id = $postData->invoiceId;
            
            if(strpos($invoice_id,'test_') !== false){
                wp_die('Successful webhook test', '', ['response' => 200]);
            }
            
            $this->form_data = wpforms()->get( 'form' )->get($form_id,['content_only' => true]);
            $payment_settings = $this->form_data['payments'][ $this->slug ];		
            $this->payment_settings = $payment_settings;
            
            
            $client = new \Coinsnap\Client\Invoice( $this->getApiUrl(), $this->getApiKey() );			
            $csinvoice = $client->getInvoice($this->getStoreId(), $invoice_id);
            $status = $csinvoice->getData()['status'];
            $entry_id = $csinvoice->getData()['orderId'];		
            
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
        catch (JsonException $e) {
            wp_die('Invalid JSON payload', '', ['response' => 400]);
        }
        catch (\Throwable $e) {
            
            $errorMessage = __('Webhook payload error', 'coinsnap-for-wpforms' );
            $this->log_errors( $errorMessage, array($e->getMessage()));
            
            wp_die('Internal server error', '', ['response' => 500]);
        }
    }

    private function update_payment( $payment_id, $data = [] ) {

        if ( !wpforms()->get( 'payment' )->update( $payment_id, $data, '', '', [ 'cap' => false ] ) ) {
            wpforms_log('Coinsnap IPN Error: Payment update failed',['payment_id' => $payment_id, 'data'       => $data]);
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
    
    public function get_payment_provider() {
        return (isset($this->payment_settings['coinsnap_provider']) && $this->payment_settings['coinsnap_provider'] === 'btcpay')? 'btcpay' : 'coinsnap';
    }
    
    public function get_webhook_url() {		
        return esc_url_raw( add_query_arg( array( 'wpforms-listener' => 'coinsnap', 'form-id'=>$this->form_data['id'] ), home_url( 'index.php' ) ) );
    }

    public function getStoreId() {
        return ($this->get_payment_provider() === 'btcpay')? $this->payment_settings['btcpay_store_id'] : $this->payment_settings['store_id'];
    }
    
    public function getApiKey() {
        return ($this->get_payment_provider() === 'btcpay')?  $this->payment_settings['btcpay_api_key'] : $this->payment_settings['api_key'] ;
    }
    
    public function getApiUrl() {
        return ($this->get_payment_provider() === 'btcpay')?  $this->payment_settings['btcpay_server_url'] : COINSNAP_SERVER_URL;
    }	

    public function webhookExists(string $apiUrl, string $apiKey, string $storeId): bool {
        
        $form_id = (isset($this->form_data['id']) && $this->form_data['id'] > 0)? $this->form_data['id'] : 0;
        if($form_id > 0){
        
            $whClient = new Webhook( $apiUrl, $apiKey );
            if ($storedWebhook = get_option( 'wpforms_settings_coinsnap_webhook_'.$form_id)) {

                try {
                    $existingWebhook = $whClient->getWebhook( $storeId, $storedWebhook['id'] );

                    if($existingWebhook->getData()['id'] === $storedWebhook['id'] && strpos( $existingWebhook->getData()['url'], $storedWebhook['url'] ) !== false){
                        return true;
                    }
                }
                catch (\Throwable $e) {
                    $errorMessage = __( 'Error fetching existing Webhook', 'coinsnap-for-wpforms' );
                    $this->log_errors( $errorMessage, array($e->getMessage()));
                }
            }
            try {
                $storeWebhooks = $whClient->getWebhooks( $storeId );
                foreach($storeWebhooks as $webhook){
                    if(strpos( $webhook->getData()['url'], $this->get_webhook_url() ) !== false){
                        $whClient->deleteWebhook( $storeId, $webhook->getData()['id'] );
                    }
                }
            }
            catch (\Throwable $e) {
                $errorMessage = sprintf( 
                    /* translators: 1: StoreId */
                    __( 'Error fetching webhooks for store ID %1$s', 'coinsnap-for-wpforms' ), $storeId);
                $this->log_errors( $errorMessage, array($e->getMessage()));
            }
        }
	return false;
    }
    
    public function registerWebhook(string $apiUrl, $apiKey, $storeId){
        
        $form_id = (isset($this->form_data['id']) && $this->form_data['id'] > 0)? $this->form_data['id'] : 0;
        if($form_id > 0){
        
            try {
                $whClient = new Webhook( $apiUrl, $apiKey );
                $webhook = $whClient->createWebhook(
                    $storeId,   //$storeId
                    $this->get_webhook_url(), //$url
                    self::WEBHOOK_EVENTS,   //$specificEvents
                    null    //$secret
                );

                update_option(
                    'wpforms_settings_coinsnap_webhook_'.$form_id,
                    [
                        'id' => $webhook->getData()['id'],
                        'secret' => $webhook->getData()['secret'],
                        'url' => $webhook->getData()['url']
                    ]
                );

                return $webhook;

            }
            catch (\Throwable $e) {
                $errorMessage = __('Error creating a new webhook on Coinsnap instance', 'coinsnap-for-wpforms' );
                $this->log_errors( $errorMessage, array($e->getMessage()));
            }
        }
	return null;
    }

    public function updateWebhook(string $webhookId,string $webhookUrl,string $secret,bool $enabled,bool $automaticRedelivery,?array $events): ?WebhookResult {
        try {
            $whClient = new Webhook($this->getApiUrl(), $this->getApiKey() );
            $webhook = $whClient->updateWebhook(
                $this->getStoreId(),
                $webhookUrl,
		$webhookId,
		$events ?? self::WEBHOOK_EVENTS,
		$enabled,
		$automaticRedelivery,
		$secret
            );
            return $webhook;
        }
        catch (\Throwable $e) {
            $errorMessage = __('Error updating existing Webhook from Coinsnap', 'coinsnap-for-wpforms' ) . $e->getMessage();
            $data['errors']['form']['coinsnap'] = esc_html($errorMessage);
	}
    }
}
