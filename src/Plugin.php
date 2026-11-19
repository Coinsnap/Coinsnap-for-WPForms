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
    private $rate = 0.0;
    private $payment_id = 0;
    private $payment_settings = [];
    public const COINSNAP_WEBHOOK_EVENTS = Security::COINSNAP_WEBHOOK_EVENTS;
    public const BTCPAY_WEBHOOK_EVENTS = Security::BTCPAY_WEBHOOK_EVENTS;
        
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
            add_action( 'wp_ajax_coinsnap_connection_notices', [$this, 'coinsnapConnectionNotices'] );
            add_action( 'wp_ajax_btcpay_server_apiurl_handler', [$this, 'btcpayApiUrlHandler']);
        }
        
        // Builder save: API keys are never shown, an empty field keeps the stored key (5, 6.14).
        add_filter( 'wpforms_save_form_args', [ $this, 'protect_saved_keys' ], 5, 3 );
        add_action( 'admin_post_coinsnap_wpforms_reconnect_webhook', [ $this, 'reconnectWebhook' ] );

        // BTCPay posts the new API key here after the admin authorized it on BTCPay.
        add_action( 'template_redirect', [ $this, 'btcpayCallback' ] );
    }

    /**
     * BTCPay connect callback. Public on purpose (BTCPay posts it cross-site, usually without the
     * admin's cookie), so the proof is the one-time state; the key's permissions and store come
     * from BTCPay itself, and the form, admin and server URL from the state, never from the request.
     */
    public function btcpayCallback() {
        global $wp_query;

        if (!isset($wp_query->query_vars['coinsnap-for-wpforms-btcpay-settings-callback'])) {
            return;
        }

        $notice = new \Coinsnap\Util\Notice();

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the one-time state is the proof.
        $state = isset($_GET['state']) ? sanitize_text_field(wp_unslash($_GET['state'])) : '';
        $pending = Security::btcpay_consume_state($state);
        if ($pending === null || $pending['form_id'] <= 0 || $pending['user_id'] <= 0) {
            $notice->addNotice('error', __('The BTCPay authorization link has expired or is invalid. Please click "Generate API key" again.', 'coinsnap-for-wpforms'));
            wp_safe_redirect(admin_url('admin.php?page=wpforms-overview'));
            exit();
        }
        $form_id = $pending['form_id'];
        $settingsUrl = admin_url('admin.php?page=wpforms-builder&view=payments&form_id=' . $form_id . '&section=coinsnap&provider=btcpay');

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the one-time state is the proof.
        $apiKey = isset($_POST['apiKey']) ? sanitize_text_field(wp_unslash($_POST['apiKey'])) : '';
        $permissions = Security::btcpay_key_permissions($pending['url'], $apiKey);
        $storeId = is_array($permissions) ? Security::btcpay_key_store($permissions) : '';
        if ($permissions === null || !Security::btcpay_key_is_acceptable($permissions, $storeId)) {
            $notice->addNotice('error', __('The API key was refused. Please select exactly one store and keep the requested permissions on the BTCPay API authorization page.', 'coinsnap-for-wpforms'));
            wp_safe_redirect($settingsUrl);
            exit();
        }

        // The admin who started the authorization saves the form, with WPForms' own capability check.
        wp_set_current_user($pending['user_id']);

        // The server URL is saved only together with a key that works on it (6.14).
        if (!$this->coinsnap_settings_update($form_id, [
            'btcpay_server_url' => $pending['url'],
            'btcpay_api_key'    => $apiKey,
            'btcpay_store_id'   => $storeId,
            'coinsnap_provider' => 'btcpay',
        ])) {
            $notice->addNotice('error', __('Could not save the BTCPay settings in this form.', 'coinsnap-for-wpforms'));
            wp_safe_redirect($settingsUrl);
            exit();
        }

        $notice->addNotice('success', __('Successfully received api key and store id from BTCPay Server API. Please finish setup by saving this settings form.', 'coinsnap-for-wpforms'));

        $cfg = ['form_id' => $form_id, 'provider' => 'btcpay', 'url' => rtrim($pending['url'], '/'), 'store_id' => $storeId, 'api_key' => $apiKey];
        if (Security::register_webhook($cfg)) {
            $notice->addNotice('success', __( 'Successfully registered a new webhook on BTCPay Server.', 'coinsnap-for-wpforms' ));
        }
        else {
            $notice->addNotice('error', __( 'Could not register a new webhook on the store.', 'coinsnap-for-wpforms' ));
        }
        wp_safe_redirect($settingsUrl);
        exit();
    }

    /**
     * Builder save (WPForms checked its nonce and capability): API key fields are rendered empty,
     * so an empty value keeps the stored key. See Security::merge_saved_keys().
     */
    public function protect_saved_keys( $form, $data, $args ) {
        if ( ( $args['context'] ?? '' ) !== 'save_form' ) {
            return $form;
        }
        // Work on what earlier filters left in post_content, not on the original $data.
        $data = json_decode( wp_unslash( (string) ( $form['post_content'] ?? '' ) ), true );
        if ( ! is_array( $data ) || ! is_array( $data['payments'][ $this->slug ] ?? null ) ) {
            return $form;
        }
        $form_id = absint( $form['ID'] ?? 0 );
        $stored = Security::form_settings( $form_id ) ?? [];
        [ $settings, $error ] = Security::merge_saved_keys( $data['payments'][ $this->slug ], $stored );
        if ( $error !== '' ) {
            ( new \Coinsnap\Util\Notice() )->addNotice( 'error', $error );
        }
        $data['payments'][ $this->slug ] = $settings;
        $form['post_content'] = wpforms_encode( $data );
        return $form;
    }

    /** "Create new secret": re-registers this form's webhook with a new secret. */
    public function reconnectWebhook() {
        $form_id = isset( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked below
        if ( ! $form_id || ! wpforms_current_user_can( 'edit_form_single', $form_id ) ) {
            wp_die( esc_html__( 'Forbidden', 'coinsnap-for-wpforms' ), '', [ 'response' => 403 ] );
        }
        check_admin_referer( 'coinsnap_wpforms_reconnect_webhook_' . $form_id );
        $notice = new \Coinsnap\Util\Notice();
        $cfg = Security::config( $form_id, Security::form_settings( $form_id ) ?? [] );
        if ( Security::register_webhook( $cfg ) ) {
            $notice->addNotice( 'success', __( 'Webhook connected with a new secret.', 'coinsnap-for-wpforms' ) );
        } else {
            $notice->addNotice( 'error', __( 'Could not connect the webhook. Please check the store settings.', 'coinsnap-for-wpforms' ) );
        }
        wp_safe_redirect( admin_url( 'admin.php?page=wpforms-builder&view=payments&form_id=' . $form_id . '&section=coinsnap' ) );
        exit();
    }

    private function reconnectUrl( int $form_id ): string {
        return wp_nonce_url( admin_url( 'admin-post.php?action=coinsnap_wpforms_reconnect_webhook&form_id=' . $form_id ), 'coinsnap_wpforms_reconnect_webhook_' . $form_id );
    }
        
    public function enqueueAdminScripts(): void {
        // Register the CSS file
	wp_register_style( 'coinsnap-admin-styles', COINSNAP_WPFORMS_URL . 'assets/css/coinsnap-style.css', array(), COINSNAP_WPFORMS_VERSION );
	// Enqueue the CSS file
	wp_enqueue_style( 'coinsnap-admin-styles' );

        // The AJAX nonce goes only to the form builder, for users who may edit this form.
        $form_id = absint( filter_input(INPUT_GET,'form_id',FILTER_VALIDATE_INT) );
        if ( 'wpforms-builder' !== filter_input(INPUT_GET,'page',FILTER_SANITIZE_FULL_SPECIAL_CHARS) || ! $form_id || ! wpforms_current_user_can( 'edit_form_single', $form_id ) ) {
            return;
        }
        
        if('payments' === filter_input(INPUT_GET,'view',FILTER_SANITIZE_FULL_SPECIAL_CHARS) && 'coinsnap' === filter_input(INPUT_GET,'section',FILTER_SANITIZE_FULL_SPECIAL_CHARS)){
            wp_enqueue_script('coinsnap-admin-fields',COINSNAP_WPFORMS_URL . 'assets/js/adminFields.js',[ 'jquery' ],COINSNAP_WPFORMS_VERSION,true);
        }
        
        wp_enqueue_script('coinsnap-connection-check',COINSNAP_WPFORMS_URL . 'assets/js/connectionCheck.js',[ 'jquery' ],COINSNAP_WPFORMS_VERSION,true);
        wp_localize_script('coinsnap-connection-check', 'coinsnap_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'  => wp_create_nonce( 'coinsnap-ajax-nonce' ),
            'form_id' => $form_id
        ));
    }

    /**
     * Nonce and capability for the admin AJAX handlers, before any API call or webhook change (6.5).
     * Returns the form ID; the Coinsnap settings of that form are loaded into this object.
     */
    private function verifyAjaxRequest( string $nonceField ): int {
        if ( ! check_ajax_referer( 'coinsnap-ajax-nonce', $nonceField, false ) ) {
            wp_send_json_error( null, 403 );
        }
        $form_id = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;
        if ( ! $form_id || ! wpforms_current_user_can( 'edit_form_single', $form_id ) ) {
            wp_send_json_error( null, 403 );
        }
        $form = wpforms()->obj( 'form' )->get( $form_id, [ 'content_only' => true ] );
        $this->form_data = is_array( $form ) ? $form : [];
        $this->payment_settings = is_array( $this->form_data['payments'][ $this->slug ] ?? null ) ? $this->form_data['payments'][ $this->slug ] : [];
        return $form_id;
    }

    private function securityConfig(): array {
        return Security::config( (int) ( $this->form_data['id'] ?? 0 ), $this->payment_settings );
    }
    
    public function coinsnapConnectionHandler(){
        $this->verifyAjaxRequest( '_wpnonce' );
        
        if(isset($this->form_data['payments'])){
            
            if(empty($this->getApiUrl()) || empty($this->getApiKey())){
                $response = [
                    'result' => false,
                    'message' => __('WP Forms: Payment gateway is disconnected', 'coinsnap-for-wpforms')
                ];
                $this->sendJsonResponse($response);
            }
            
            
            $_provider = $this->getPaymentProvider();

            $client = new \Coinsnap\Client\Invoice($this->getApiUrl(),$this->getApiKey());
            $currency = strtoupper( wpforms_get_currency() );
            $store = new \Coinsnap\Client\Store($this->getApiUrl(),$this->getApiKey());
            $_message_disconnected = ($_provider !== 'btcpay')? 
                __('WP Forms: Coinsnap server is disconnected', 'coinsnap-for-wpforms') :
                __('WP Forms: BTCPay server is disconnected', 'coinsnap-for-wpforms');
            $_message_connected = ($_provider !== 'btcpay')?
                __('WP Forms: Coinsnap server is connected', 'coinsnap-for-wpforms') : 
                __('WP Forms: BTCPay server is connected', 'coinsnap-for-wpforms');
            $response = ['result' => false,'message' => $_message_disconnected];

            try {
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

                $this_store = $store->getStore($this->getStoreId());

                if ($this_store->getData()['code'] !== 200) {
                    $this->sendJsonResponse($response);
                }

                $cfg = $this->securityConfig();
                $webhookStatus = Security::webhook_status($cfg);

                if($webhookStatus === 'ok') {
                    $response = ['result' => true,'message' => $_message_connected.' ('.$connectionData.')'];
                    $this->sendJsonResponse($response);
                }

                // Only a webhook that is definitely missing is created again; an API error changes nothing.
                $webhook = ($webhookStatus === 'missing') && Security::register_webhook($cfg);
                $response['result'] = $webhook;
                $response['message'] = $webhook ? $_message_connected.' ('.$connectionData.')' : $_message_disconnected.' (Webhook)';
            }
            catch (\Throwable $e) {
                // Never the exception text: it can name the store or echo the API's answer.
                $response['message'] = __('WP Forms: API connection is not established', 'coinsnap-for-wpforms');
            }

            $this->sendJsonResponse($response);
        }
        $this->sendJsonResponse(['result' => false, 'message' => __('WP Forms: Payment gateway is disconnected', 'coinsnap-for-wpforms')]);
    }

    private function sendJsonResponse(array $response): void {
        echo wp_json_encode($response);
        exit();
    }
    
    /**
     * Handles the BTCPay server AJAX callback from the settings form.
     */
    public function btcpayApiUrlHandler() {
        $_nonce = isset($_POST['apiNonce']) ? sanitize_text_field(wp_unslash($_POST['apiNonce'])) : '';
        if ( !wp_verify_nonce( $_nonce, 'coinsnap-ajax-nonce' ) ) {
            wp_die('Unauthorized!', '', ['response' => 401]);
        }
        $form_id = isset($_POST['form_id']) ? absint($_POST['form_id']) : 0;
        
        if ( $form_id > 0 && current_user_can( 'manage_options' ) && wpforms_current_user_can( 'edit_form_single', $form_id ) ) {
            $host = filter_var(isset($_POST['host']) ? rtrim(sanitize_text_field(wp_unslash($_POST['host'])), '/') : '', FILTER_VALIDATE_URL);

            if ($host === false || (substr( $host, 0, 7 ) !== "http://" && substr( $host, 0, 8 ) !== "https://")) {
                wp_send_json_error("Error validating BTCPayServer URL.");
            }

            // Only what the plugin uses, for one store (strict, selectiveStores).
            $permissions = Security::BTCPAY_PERMISSIONS;

            try {
		// Create the redirect url to BTCPay instance.
		$url = \Coinsnap\Client\BTCPayApiKey::getAuthorizeUrl(
                    $host,
                    $permissions,
                    'WPForms',
                    true,
                    true,
                    add_query_arg('state', Security::btcpay_start_state($form_id, $host, get_current_user_id()), home_url('?coinsnap-for-wpforms-btcpay-settings-callback')),
                    null
		);

		// The host is kept with the one-time state and saved only with a key that works on it (6.14).

		// Return the redirect url.
		wp_send_json_success(['url' => $url]);
            }
            
            catch (\Throwable $e) {
                Security::log('info', 'btcpay authorize url failed');
            }
	}
        wp_send_json_error("Error processing Ajax request.");
    }
    
    public function coinsnap_settings_update($form_id,$data): bool {
        
        $form = wpforms()->form->get( absint( $form_id ) );
        if ( empty( $form ) ) {
            return false;
        }
        $form_data = json_decode($form->post_content, true);
        if ( ! is_array( $form_data ) ) {
            return false;
        }
        
        foreach($data as $key => $value){
            $form_data['payments'][ $this->slug ][$key] = $value;
        }
        
        return (bool) wpforms()->obj( 'form' )->update($form_id,$form_data);
    }
    
    public function coinsnapConnectionNotices(){
        
        $this->verifyAjaxRequest( '_wpnonce' );
        
        if(isset($this->form_data['payments'])){
            
            $response_array = [];
            
            $coinsnap_url = $this->getApiUrl();
            $coinsnap_api_key = $this->getApiKey();
            $coinsnap_store_id = $this->getStoreId();

            if(empty($coinsnap_url)){
                $response_array[] = ['error',__('Server URL is not set','coinsnap-for-wpforms')];
            }
            
            if(empty($coinsnap_store_id)){
                $response_array[] = ['error',__('Coinsnap Store ID is not set','coinsnap-for-wpforms')];
            }
            
            if(empty($coinsnap_api_key)){
                $response_array[] = ['error',__('Coinsnap API Key is not set','coinsnap-for-wpforms')];
            }
            
            if(!empty($coinsnap_url) && !empty($coinsnap_store_id) && !empty($coinsnap_api_key)){
                
                $client = new \Coinsnap\Client\Store($coinsnap_url, $coinsnap_api_key);
                    try {
                        $store = $client->getStore($coinsnap_store_id);
                        
                        if ($store->getData()['code'] === 200) {
                        
                            $response_array[] = ['success',__('Established connection to Coinsnap Server','coinsnap-for-wpforms')];

                            // Only a webhook that is definitely missing is created; an API error changes nothing.
                            $cfg = $this->securityConfig();
                            $webhookStatus = Security::webhook_status($cfg);
                            if ( $webhookStatus === 'missing' ) {
                                if ( ! Security::register_webhook($cfg) ) {
                                    $response_array[] = ['error',__('Unable to create webhook on Server','coinsnap-for-wpforms')];
                                }
                                else {
                                    $response_array[] = ['success',__('Successfully registered webhook on Server','coinsnap-for-wpforms')];
                                }
                            }
                            elseif ( $webhookStatus === 'error' ) {
                                $response_array[] = ['error',__('Could not check the webhook on Server','coinsnap-for-wpforms')];
                            }
                            else {
                                $response_array[] = ['info',__('Webhook already exists, skipping webhook creation','coinsnap-for-wpforms')];
                            }
                        }
                        else {
                            $response_array[] = ['error',__('Store connection error','coinsnap-for-wpforms')];
                        }
                    }
                    catch (\Throwable $e){
                        $response_array[] = ['error',__('Store connection error','coinsnap-for-wpforms')];
                        $this->sendJsonResponse($response_array);
                    }
                    
                    
                
            }
            
            $this->sendJsonResponse($response_array);
        }
        else {
            $response_array = [
                ['error', __('Form initiation error', 'coinsnap-for-wpforms')]
            ];
            $this->sendJsonResponse($response_array);            
        }
    }
    

    public function coinsnap_notice(){
        
        $page = (filter_input(INPUT_GET,'page',FILTER_SANITIZE_FULL_SPECIAL_CHARS ) !== null)? filter_input(INPUT_GET,'page',FILTER_SANITIZE_FULL_SPECIAL_CHARS ) : '';
        $notices = new \Coinsnap\Util\Notice(); 
        
        if($page === 'wpforms-builder'){
            
            if(isset($this->form_data['payments'])){
                $payment_settings = $this->form_data['payments'][ $this->slug ] ?? [];
                $this->payment_settings = is_array($payment_settings) ? $payment_settings : [];

                $coinsnap_url = $this->getApiUrl();
                $coinsnap_api_key = $this->getApiKey();
                $coinsnap_store_id = $this->getStoreId();
            }
            
            else {
                $coinsnap_url = '';
                $coinsnap_store_id = '';
                $coinsnap_api_key = '';
            }
            
            echo '<div class="coinsnap-notices">';
            
                $notices->showNotices();
                
                if(empty($coinsnap_url)){
                    echo '<div class="notice notice-error"><p>';
                    esc_html_e('Server URL is not set', 'coinsnap-for-wpforms');
                    echo '</p></div>';
                }

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
                
                // A page view only shows the status. The webhook is checked and created by the
                // AJAX connection check (nonce and capability first), never by a plain GET (6.5).
                if(!empty($coinsnap_api_key) && !empty($coinsnap_store_id)){
                    $cfg = $this->securityConfig();
                    if ( Security::has_webhook($cfg) ) {
                        echo '<div class="notice notice-info"><p>';
                        esc_html_e('Webhook: connected', 'coinsnap-for-wpforms');
                        echo '</p></div>';
                    }
                    else {
                        echo '<div class="notice notice-warning"><p>';
                        esc_html_e('Webhook: not connected. It must be connected (with a new secret) before payments can be confirmed.', 'coinsnap-for-wpforms');
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
			$this->secretFieldArgs( 'api_key', esc_html__( 'Enter Your Coinsnap API Key', 'coinsnap-for-wpforms' ), 'coinsnap' )
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
                
                echo '<div class="wpforms-panel-field btcpay"><a class="btcpay-apikey-link">' . esc_html__( 'Check connection', 'coinsnap-for-wpforms' ).'</a><br/><br/><button type="button" class="button btcpay-apikey-link" id="btcpay_wizard_button">'. esc_html__('Generate API key','coinsnap-for-wpforms').'</button></div>';
		
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
			$this->secretFieldArgs( 'btcpay_api_key', esc_html__( 'Enter Your BTCPay API Key', 'coinsnap-for-wpforms' ), 'btcpay' )
		);

		echo $this->webhookStatusHtml(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in webhookStatusHtml()

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
			'text',
			$this->slug,
			'returnurl',
			$this->form_data,
			esc_html__( 'Return URL after payment', 'coinsnap-for-wpforms' ),
			[
				'parent'  => 'payments',
				'tooltip' => esc_html__( 'Custom return URL after successful payment (default URL if blank)', 'coinsnap-for-wpforms' ),
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
    
    /** API key field: an empty password field with a hint, never the stored key (5). */
    private function secretFieldArgs( string $key, string $tooltip, string $class ): array {
        $hint = Security::secret_hint( (string) ( $this->form_data['payments'][ $this->slug ][ $key ] ?? '' ) );
        return [
            'parent'      => 'payments',
            'tooltip'     => $hint !== '' ? $tooltip . ' ' . esc_html__( '(leave empty to keep the saved key)', 'coinsnap-for-wpforms' ) : $tooltip,
            'class'       => $class,
            'type'        => 'password',
            'value'       => '',
            'placeholder' => $hint !== '' ? $hint : esc_html__( 'Paste your API key', 'coinsnap-for-wpforms' ),
            'data'        => $hint !== '' ? [ 'coinsnap-has-value' => '1', 'lpignore' => 'true' ] : [ 'lpignore' => 'true' ],
        ];
    }

    /** "Webhook: connected" and a "Create new secret" button; the secret itself is never shown. */
    private function webhookStatusHtml(): string {
        $form_id = (int) ( $this->form_data['id'] ?? 0 );
        if ( $form_id <= 0 ) {
            return '';
        }
        $settings = is_array( $this->form_data['payments'][ $this->slug ] ?? null ) ? $this->form_data['payments'][ $this->slug ] : [];
        $connected = Security::has_webhook( Security::config( $form_id, $settings ) );
        return '<div class="wpforms-panel-field"><p>' . esc_html( $connected ? __( 'Webhook: connected', 'coinsnap-for-wpforms' ) : __( 'Webhook: not connected', 'coinsnap-for-wpforms' ) )
            . ' <a class="button" href="' . esc_url( $this->reconnectUrl( $form_id ) ) . '">'
            . esc_html( $connected ? __( 'Create new secret', 'coinsnap-for-wpforms' ) : __( 'Connect webhook', 'coinsnap-for-wpforms' ) ) . '</a></p></div>';
    }
    
    function amount_validation( $amount, $currency ) {
        $client =new \Coinsnap\Client\Invoice($this->getApiUrl(), $this->getApiKey());
        $store = new \Coinsnap\Client\Store($this->getApiUrl(), $this->getApiKey());
        
        try {
            $_provider = $this->getPaymentProvider();
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
            else {
                $this->rate = (float) ( $checkInvoice['rate'] ?? 0 );

                // A visitor's submission only creates a webhook when this form has none; it never replaces a secret.
                $cfg = $this->securityConfig();
                if ( ! Security::has_webhook( $cfg ) && ! Security::register_webhook( $cfg ) ) {
                    $error_title = esc_html__( 'Bitcoin payments are not available at the moment. Please try again later.', 'coinsnap-for-wpforms' );
                    $errors[]    = $error_title;
                    $this->log_errors( $error_title );
                }
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

        // The payment row WPForms saved for this entry; every invoice is recorded against it (3, 6.7).
        if ( $this->payment_id <= 0 ) {
            $this->log_errors( esc_html__( 'Coinsnap payment stopped, payment record missing', 'coinsnap-for-wpforms' ) );
            return false;
        }

        // Update the entry type.
        $entry_obj = wpforms()->obj( 'entry' );
        if ( $entry_obj ) {
            $entry_obj->update($entry_id,[ 'type' => 'payment' ],'','',[ 'cap' => false ]);
        }

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

        $return_url = (!empty($this->payment_settings['coinsnap_returnurl']))? $this->payment_settings['coinsnap_returnurl'] : esc_url_raw(
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

        // orderId / orderNumber stay the entry ID: the webhook matches the invoice to the entry by it.
        $metadata = Metadata::for_entry([
            'entry_id'   => $invoice_no,
            'payment_id' => $this->payment_id,
            'form_id'    => $this->form_data['id'] ?? '',
            'form_title' => (string) ( $this->form_data['settings']['form_title'] ?? '' ),
            'order_url'  => admin_url( 'admin.php?page=wpforms-payments&view=payment&payment_id=' . (int) $this->payment_id ),
            'page_url'   => (string) wp_get_referer(),
            'post_id'    => (int) ( $entry['post_id'] ?? 0 ),
            'version'    => COINSNAP_WPFORMS_VERSION,
            'language'   => function_exists( 'determine_locale' ) ? determine_locale() : get_locale(),
            'fields'     => is_array( $fields ) ? $fields : [],
        ]);
        // The buyer as found by get_customer(), which the invoice has always used.
        foreach ( [ 'customerName' => $buyerName, 'buyerName' => $buyerName, 'buyerEmail' => $buyerEmail, 'buyerPhone' => $customer_data['phone'] ] as $key => $value ) {
            if ( ! isset( $metadata[ $key ] ) ) {
                Metadata::put( $metadata, $key, $value );
            }
        }

        $checkoutOptions = new \Coinsnap\Client\InvoiceCheckoutOptions();
        $checkoutOptions->setRedirectURL( $return_url );
        
        try {
        if($this->getPaymentProvider() === 'btcpay' && $currency !== 'BTC'){
                $store = new \Coinsnap\Client\Store($this->getApiUrl(), $this->getApiKey());
                $btcpayCurrencies = $store -> getStoreCurrenciesRates($this->getStoreId(),array($currency));
                $isCurrency = true;
                if(!isset($btcpayCurrencies['result']['error']) && count($btcpayCurrencies['result']['currencies'])>0){
                        if(!isset($btcpayCurrencies['result']['currencies']['BTC_'.$currency])){
                            $isCurrency = false;
                        }
                }
                else {
                    $isCurrency = false;
                }
                    
                // Handle currencies non-supported by BTCPay Server, we need to change them BTC and adjust the amount.
                if( !$isCurrency ){
                        if ( $this->rate <= 0 ) {
                            throw new \RuntimeException( 'no exchange rate' );
                        }
                        $currency = 'BTC';
                        $rate = 1/$this->rate;
                        $amountBTC = bcdiv(strval($amount), strval($rate), 8);
                        $amount = (float)$amountBTC;
                }
        }
        
        $camount = ($currency === 'BTC')? \Coinsnap\Util\PreciseNumber::parseFloat($amount,8) : \Coinsnap\Util\PreciseNumber::parseFloat($amount,2);
        
        $redirectAutomatically = ($this->payment_settings['autoredirect'] == 0)? false : true;
        $walletMessage = '';
        
								
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

            $invoiceData = $csinvoice->getData();
            $payurl = (string) ( $invoiceData['checkoutLink'] ?? '' );

            // Keep every invoice with the amount and currency it asked for (3, 6.7).
            if ( ! is_string( $invoiceData['id'] ?? null ) || ! Security::record_invoice( $invoiceData['id'], $this->payment_id, (int) $entry_id, (int) $this->form_data['id'], $this->getPaymentProvider(), $camount->__toString(), $currency ) ) {
                throw new \RuntimeException( 'invoice not recorded' );
            }

            wp_redirect( $payurl );
            exit();
        }
        catch (\Throwable $e){
            $this->log_errors( esc_html__( 'Coinsnap invoice could not be created', 'coinsnap-for-wpforms' ) );
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
        $this->payment_id = (int) $payment->id;

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
        $form_id = (int) filter_input(INPUT_GET,'form-id',FILTER_VALIDATE_INT);
        if ( $form_id < 1 ) {
            return;
        }

        $rawPostData = (string) file_get_contents('php://input');
        $code = $this->handleWebhook($form_id, $rawPostData);

        status_header($code);
        echo ($code === 200) ? 'OK' : (($code === 401) ? 'Unauthorized' : 'Retry later');
        exit;
    }

    /**
     * Returns the HTTP status to send. Only invoiceId is taken from the body; the
     * decision comes from the invoice fetched from the API.
     */
    public function handleWebhook(int $form_id, string $rawPostData): int {
        $settings = Security::form_settings($form_id) ?? [];
        $cfg = Security::config($form_id, $settings);
        $isBtcpay = $cfg['provider'] === 'btcpay';
        $header = $isBtcpay
            ? (isset($_SERVER['HTTP_BTCPAY_SIG']) ? (string) wp_unslash($_SERVER['HTTP_BTCPAY_SIG']) : '') // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            : (isset($_SERVER['HTTP_X_COINSNAP_SIGNATURE']) ? (string) wp_unslash($_SERVER['HTTP_X_COINSNAP_SIGNATURE']) : ''); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        if (!Security::signature_ok($cfg['provider'], $header, $rawPostData, Security::get_secret($cfg))) {
            return 401;
        }

        $data = json_decode($rawPostData, true);
        $invoiceId = (is_array($data) && is_string($data['invoiceId'] ?? null)) ? $data['invoiceId'] : '';
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $invoiceId)) {
            Security::log('info', 'no invoice id');
            return 200;
        }
        $claimsPaid = in_array($data['type'] ?? '', ['Settled', 'InvoiceSettled'], true);

        try {
            $invoice = Security::fetch_invoice($cfg, $invoiceId);
        } catch (\RuntimeException $e) {
            Security::log('info', 'retry: API unavailable', $invoiceId);
            return 503;
        }
        if ($invoice === null) {
            Security::log('info', 'unknown invoice', $invoiceId);
            return 200;
        }

        // The entry the invoice was created for, as the API reports it.
        $apiEntryId = $isBtcpay
            ? ($invoice['metadata']['orderId'] ?? ($invoice['metadata']['orderNumber'] ?? null))
            : ($invoice['orderId'] ?? ($invoice['metadata']['orderNumber'] ?? null));
        $apiEntryId = is_numeric($apiEntryId) ? (int) $apiEntryId : 0;

        $record = Security::get_record($invoiceId);
        if ($record === null) {
            $record = $this->legacyRecord($invoiceId, $invoice, $apiEntryId, $form_id, $cfg['provider']);
        }
        if ($record === null || (int) $record['entry_id'] !== $apiEntryId || (int) $record['form_id'] !== $form_id || $record['provider'] !== $cfg['provider']) {
            Security::log('info', 'not ours', $invoiceId);
            return 200;
        }

        $payment = wpforms()->obj('payment')->get((int) $record['payment_id']);
        if (empty($payment) || (int) $payment->form_id !== $form_id || $payment->gateway !== $this->slug) {
            Security::log('info', 'not ours', $invoiceId);
            return 200;
        }

        $status = (string) ($invoice['status'] ?? '');
        if ($claimsPaid && $status !== 'Settled') {
            // Signed, says paid, but the API disagrees: the secret may have leaked. Change nothing.
            Security::log('security', 'forged status', $invoiceId);
            return 200;
        }

        $settled_status    = (string) ($settings['settled_status'] ?? 'completed');
        $processing_status = (string) ($settings['processing_status'] ?? 'processed');
        $expired_status    = (string) ($settings['expired_status'] ?? 'failed');
        // Statuses a webhook never moves a payment out of or into, except a real payment into "settled".
        $final = array_unique(['completed', 'refunded', 'partrefund', $settled_status]);
        $current = (string) $payment->status;
        // Only states this plugin may move a payment out of (6.8). A status the merchant set by
        // hand (anything else) never changes from a webhook.
        $unpaid = $current === 'pending' || ($current === $processing_status && !in_array($current, $final, true));

        if ($status === 'Settled') {
            if (!Security::invoice_pays($invoice, (string) $record['amount'], (string) $record['currency'], $isBtcpay)) {
                $forged = $claimsPaid && ($invoice['additionalStatus'] ?? '') !== 'Marked';
                Security::log($forged ? 'security' : 'info', $forged ? 'forged status' : 'amount mismatch', $invoiceId);
                return 200;
            }
            // A late payment may still complete a payment an earlier invoice expired.
            $latePayable = $current === $expired_status && !in_array($current, $final, true) && !Security::payment_has_paid_invoice((int) $payment->id);
            if (!$unpaid && !$latePayable) {
                Security::log('info', 'already processed', $invoiceId);
                return 200;
            }
            return $this->completeOnce($payment, $settled_status, $invoiceId);
        }

        if ($status === 'Processing') {
            if ($current === 'pending' && !in_array($processing_status, $final, true)) {
                $this->setPaymentStatus($payment, $processing_status, $invoiceId);
            }
            Security::log('info', 'status Processing', $invoiceId);
            return 200;
        }

        if ($status === 'Expired') {
            // Another invoice of the same payment may have paid it.
            if ($unpaid && !in_array($expired_status, $final, true) && !Security::payment_has_paid_invoice((int) $payment->id)) {
                $this->setPaymentStatus($payment, $expired_status, $invoiceId);
            }
            Security::log('info', 'status Expired', $invoiceId);
            return 200;
        }

        // New, Invalid or a status this plugin doesn't know: change nothing.
        Security::log('info', 'status ' . preg_replace('/[^A-Za-z]/', '', $status), $invoiceId);
        return 200;
    }

    /**
     * Payments started before this version have no invoice record. They are accepted only
     * during a short window after the upgrade, when the invoice asked for exactly the payment's
     * amount and currency (6.7).
     */
    private function legacyRecord(string $invoiceId, array $invoice, int $entryId, int $form_id, string $provider): ?array {
        if ($entryId <= 0 || !Security::in_legacy_window()) {
            return null;
        }
        $payment = wpforms()->obj('payment')->get_by('entry_id', $entryId);
        if (empty($payment) || (int) $payment->form_id !== $form_id || $payment->gateway !== $this->slug) {
            return null;
        }
        $created = strtotime((string) $payment->date_created_gmt . ' UTC');
        if ($created === false || $created > (int) get_option(Security::UPGRADED_AT)) {
            return null;
        }
        $currency = strtoupper((string) $payment->currency);
        if (strtoupper((string) ($invoice['currency'] ?? '')) !== $currency) {
            return null;
        }
        Security::record_invoice($invoiceId, (int) $payment->id, $entryId, $form_id, $provider, (string) $payment->total_amount, $currency);
        return Security::get_record($invoiceId);
    }

    /** Claims the invoice (only one request can), then marks the payment paid. */
    private function completeOnce($payment, string $settled_status, string $invoiceId): int {
        if (!Security::mark_paid_once($invoiceId)) {
            Security::log('info', 'already processed', $invoiceId);
            return 200;
        }
        if (!$this->setPaymentStatus($payment, $settled_status, $invoiceId)) {
            Security::unmark_paid($invoiceId);
            Security::log('info', 'retry: payment update failed', $invoiceId);
            return 503;
        }
        Security::log('info', 'paid', $invoiceId);
        return 200;
    }

    private function setPaymentStatus($payment, string $status, string $invoiceId): bool {
        if (!array_key_exists($status, ValueValidator::get_allowed_one_time_statuses())) {
            return false;
        }
        $updated = wpforms()->obj('payment')->update((int) $payment->id, ['status' => $status, 'transaction_id' => $invoiceId], '', '', ['cap' => false]);
        if ($updated) {
            $this->add_payment_log((int) $payment->id, sprintf('Coinsnap payment status: %s (Invoice ID: %s)', $status, $invoiceId));
        }
        return (bool) $updated;
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
    
    public function getPaymentProvider() {
        return (isset($this->payment_settings['coinsnap_provider']) && $this->payment_settings['coinsnap_provider'] === 'btcpay')? 'btcpay' : 'coinsnap';
    }
    
    public function get_webhook_url() {
        return Security::webhook_url( (int) ( $this->form_data['id'] ?? 0 ) );
    }

    public function getStoreId() {
        return (string) (($this->getPaymentProvider() === 'btcpay')? ($this->payment_settings['btcpay_store_id'] ?? '') : ($this->payment_settings['store_id'] ?? ''));
    }
    
    public function getApiKey() {
        return (string) (($this->getPaymentProvider() === 'btcpay')? ($this->payment_settings['btcpay_api_key'] ?? '') : ($this->payment_settings['api_key'] ?? ''));
    }
    
    public function getApiUrl() {
        return (string) (($this->getPaymentProvider() === 'btcpay')? ($this->payment_settings['btcpay_server_url'] ?? '') : COINSNAP_SERVER_URL);
    }
}
