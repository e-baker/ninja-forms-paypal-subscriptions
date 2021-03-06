<?php if ( ! defined( 'ABSPATH' ) ) exit;

/*
 * Plugin Name: Ninja Forms - PayPal Subscriptions
 * Plugin URI: https://ninjaforms.com/extensions/paypal-subscriptions/
 * Description: Use PayPal Subscriptions to accept payments using your Ninja Forms.
 * Version: 3.0.
 * Author: Eric Baker
 * Author URI: http://ericbaker.me
 * Text Domain: ninja-forms-paypal-subscriptions
 *
 * Copyright 2018 Traffic Light Media, LLC.
 */

if( version_compare( get_option( 'ninja_forms_version', '0.0.0' ), '3', '<' ) || get_option( 'ninja_forms_load_deprecated', FALSE ) ) {

    define("NINJA_FORMS_PAYPAL_SUBSCRIPTIONS_DIR", WP_PLUGIN_DIR."/".basename( dirname( __FILE__ ) ) . '/deprecated' );
    define("NINJA_FORMS_PAYPAL_SUBSCRIPTIONS_URL", plugins_url()."/".basename( dirname( __FILE__ ) ) . '/deprecated'  );
    define("NINJA_FORMS_PAYPAL_SUBSCRIPTIONS_VERSION", "3.0");
    define("NINJA_FORMS_PAYPAL_SUBSCRIPTIONS_DEBUG", true);

    include 'deprecated/paypal-subscriptions.php';

} else {

    include plugin_dir_path( __FILE__ ) . 'includes/deprecated.php';

    /**
     * Class NF_PayPalSubscriptions
     */
    final class NF_PayPalSubscriptions
    {
        const VERSION = '3.0';
        const SLUG    = 'paypal-subscriptions';
        const NAME    = 'PayPal Subscriptions';
        const AUTHOR  = 'Eric Baker';
        const PREFIX  = 'NF_PayPalSubscriptions';

        /**
         * Plugin Instance
         *
         * @var NF_PayPalSubscriptions
         * @since 3.0
         */
        private static $instance;

        /**
         * Plugin Directory
         *
         * @since 3.0
         * @var string $dir
         */
        public static $dir = '';

        /**
         * Plugin URL
         *
         * @since 3.0
         * @var string $url
         */
        public static $url = '';

        /**
         * API Connection
         *
         * @since 3.0
         * @var NF_PayPalSubscriptions_Checkout
         */
        private $_api;

        /**
         * Main Plugin Instance
         *
         * Insures that only one instance of a plugin class exists in memory at any one
         * time. Also prevents needing to define globals all over the place.
         *
         * @since 3.0
         * @static
         * @static var array $instance
         * @return NF_PayPalSubscriptions Highlander Instance
         */
        public static function instance()
        {
            if (!isset(self::$instance) && !(self::$instance instanceof NF_PayPalSubscriptions)) {
                self::$instance = new NF_PayPalSubscriptions();

                self::$dir = plugin_dir_path(__FILE__);

                self::$url = plugin_dir_url(__FILE__);

                spl_autoload_register(array(self::$instance, 'autoloader'));
            }

            return self::$instance;
        }

        public function __construct()
        {
            add_action( 'admin_init', array( $this, 'setup_license') );

            add_action( 'ninja_forms_loaded', array( $this, 'setup_admin' ) );
            
            add_action( 'admin_init', array( $this, 'tls_check' ) );

            add_filter( 'ninja_forms_register_payment_gateways', array( $this, 'register_payment_gateways' ) );

            // We're gonna add a PayPal Subscriptions action separate from Collect Payment
	        add_filter( 'ninja_forms_register_actions', array( $this, 'register_actions' ) );
            
            add_filter( 'nf_subs_csv_extra_values', array( $this, 'export_transaction_data' ), 10, 3 );
            add_filter( 'ninja_forms_new_form_templates', array( $this, 'register_templates' ) );
        }

        /**
         * Setup Admin
         *
         * Setup admin classes for Ninja Forms and WordPress.
         */
        public function setup_admin()
        {
            Ninja_Forms()->merge_tags[ 'paypal_subscriptions' ] = new NF_PayPalSubscriptions_MergeTags();

            if( ! is_admin() ) return;

            new NF_PayPalSubscriptions_Admin_Settings();
            new NF_PayPalSubscriptions_Admin_Metaboxes_Submission();
        }
        
        /**
         * Function to get a see if an install's TLS version is outdated.
         * 
         * Since 3.0
         * 
         * @return (bool) false on exit
         */
        public function tls_check()
        {
            // If cURL is disabled, bail.
            if( ! function_exists( 'curl_version' ) ) {
                return false;
            }
            $TARGET_VERSION = 1.2;
            $trans = get_transient('nf_ppe_tls_ver');
            // If we have already checked, bail.
            if( $trans && 'yes' == $trans )
                return false;
            $ch = curl_init(); 
            curl_setopt($ch, CURLOPT_URL, "https://tlstest.paypal.com/"); 
            curl_setopt($ch, CURLOPT_CAINFO, plugin_dir_path(__FILE__) . '/includes/cacert.pem');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            // Some environments may be capable of TLS 1.2 but it is not in their list of defaults so need the SSL version option to be set.
            curl_setopt($ch, CURLOPT_SSLVERSION, 6);
            $tls = curl_exec($ch);
            curl_close($ch);
            $val = 'yes';
            if( ! $tls ) {
                add_action( 'admin_notices', array( $this, 'tls_version_notice' ) );
                $val = 'no';
            }
            set_transient('nf_ppe_tls_ver', $val, 86400);
        }

        /**
         * Function to display an admin error to the user, telling them to update their TLS version.
         * 
         * Since 3.0
         */
        public function tls_version_notice()
        {
            ?>
            <div class="nf-admin-notice nf-admin-error error">
                <div class="nf-notice-logo"></div>
                <p class="nf-notice-title"><?php _e( 'Ninja Forms has detected an outdated TLS Version', 'ninja-forms-paypal-subscriptions' ); ?></p>
                <p class="nf-notice-body"><?php _e( 'Please contact your host and have them update your environment to support TLS 1.2 and HTTP/1.1', 'ninja-forms-paypal-subscriptions' ); ?></p>
            </div>
            <?php

            wp_enqueue_style( 'nf-admin-notices', Ninja_Forms::$url .'assets/css/admin-notices.css?nf_ver=' . Ninja_Forms::VERSION );
        }

        /**
         * Register Payment Gateways
         *
         * Register payment gateways with the Collect Payment action.
         *
         * @param array $payment_gateways
         * @return array $payment_gateways
         */
        public function register_payment_gateways($payment_gateways)
        {
            $payment_gateways[ 'paypal-subscriptions' ] = new NF_PayPalSubscriptions_PaymentGateway();

            return $payment_gateways;
        }

	    /**
	     * Register PayPal Subscriptions Action
	     *
	     * @param array $actions
	     * @return array $actions
	     */
	    public function register_actions( $actions )
	    {
	    	// create action with PayPal Subscriptions as label and name
		    $paypal_action = new NF_Actions_CollectPayment( __( 'PayPal Subscriptions', 'ninja-forms' ),
			    'paypal-subscriptions' );

		    // add to the NF actions array
		    $actions[ 'paypal-subscriptions' ] = $paypal_action;

		    return $actions;
	    }

        /**
         * API
         *
         * Setup PayPal Subscriptions API Connection
         *
         * @param bool $sandbox
         * @return NF_PayPalSubscriptions_Checkout
         */
        public function api( $sandbox = FALSE )
        {
            if( ! $this->_api ) {

                if( $sandbox ){
                    $username = Ninja_Forms()->get_setting( 'ppe_test_api_username' );
                    $password = Ninja_Forms()->get_setting( 'ppe_test_api_password' );
                    $signature = Ninja_Forms()->get_setting( 'ppe_test_api_signature' );
                }else {
                    $username = Ninja_Forms()->get_setting( 'ppe_live_api_username' );
                    $password = Ninja_Forms()->get_setting( 'ppe_live_api_password' );
                    $signature = Ninja_Forms()->get_setting( 'ppe_live_api_signature' );
                }

                try {
                    $this->_api = new NF_PayPalSubscriptions_Checkout( $username, $password, $signature, $sandbox );
                } catch (Exception $e) {
                    // TODO: Log Error, $e->getMessage(), for System Status Report
                }
            }
            return $this->_api;
        }

        /**
         * Register Templates
         *
         * Registers our custom form templates.
         *
         * @param $templates
         * @return mixed
         */
        public function register_templates( $templates )
        {
            //Register the login form template.
            $templates[ 'paypal-payment' ] = array(
                'id'            => 'paypal-payment',
                'title'         => __( 'PayPal Payment', 'ninja-forms' ),
                'template-desc' => __( 'Collect a payment using PayPal Subscriptions. You can add and remove fields as needed.', 'ninja-forms' ),
                'form'          => self::form_templates( 'paypal-payment.nff' ),
            );

            return $templates;
        }

        /**
         * Form Templates
         *
         * This method is used to load the form templates
         *
         * @param string $file_name
         * @param array $data
         * @return string
         */
        public static function form_templates( $file_name = '', array $data = array() )
        {
            $path = self::$dir . 'includes/Templates/' . $file_name;

            if( ! file_exists(  $path ) ) return '';

            extract( $data );

            ob_start();

            include $path;

            return ob_get_clean();
        }

        /**
         * Autoloader
         *
         * Loads files using the class name to mimic the folder structure.
         *
         * @param $class_name
         */
        public function autoloader($class_name)
        {
            if (class_exists($class_name)) return;

            if ( false === strpos( $class_name, self::PREFIX ) ) return;

            $class_name = str_replace( self::PREFIX, '', $class_name );
            $classes_dir = realpath(plugin_dir_path(__FILE__)) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR;
            $class_file = str_replace('_', DIRECTORY_SEPARATOR, $class_name) . '.php';

            if (file_exists($classes_dir . $class_file)) {
                require_once $classes_dir . $class_file;
            }
        }

        /**
         * Config
         *
         * @param $file_name
         * @return mixed
         */
        public static function config( $file_name )
        {
            return include self::$dir . 'includes/Config/' . $file_name . '.php';
        }

        /**
         * Template
         *
         * @param string $file_name
         * @param array $data
         */
        public static function template( $file_name = '', array $data = array() )
        {
            if( ! $file_name ) return;
            extract( $data );

            if( file_exists( self::$dir . 'includes/Templates/' . $file_name ) ) {
                include self::$dir . 'includes/Templates/' . $file_name;
            }
        }
        
        /**
         * Hook Into Submission Exports.
         * 
         * @since 3.0
         * 
         * @param array $csv_array
         * @param array $subs
         * @param int $form_id
         * @return array
         */
        public function export_transaction_data( $csv_array, $subs, $form_id )
        {
            $add_transactions = false;
            $actions = Ninja_Forms()->form($form_id)->get_actions();
            // Loop over our actions to see if PayPal exists.
            foreach( $actions as $action ) {
                $settings = $action->get_settings();
                // check for collectpayment or paypal-subscriptions types
                if( in_array( $settings[ 'type' ], array( 'collectpayment', 'paypal-subscriptions') )
                   && 'paypal-subscriptions' == $settings[ 'payment_gateways' ] ) {
                    $add_transactions = true;
                }
            }
            
            // If we didn't find a PayPal action, bail.
            if( ! $add_transactions ) return $csv_array;
            
            // Add our labels.
            $csv_array[ 0 ][ 0 ][ 'paypal_status' ] = __( 'PayPal Status', 'ninja-forms-paypal-subscriptions' );
            $csv_array[ 0 ][ 0 ][ 'paypal_transaction_id' ] = __( 'PayPal Transaction ID', 'ninja-forms-paypal-subscriptions' );
            // Add our values.
            $i = 0;
            foreach( $subs as $sub ) {
                $csv_array[ 1 ][ 0 ][ $i ][ 'paypal_status' ] = $sub->get_extra_value( 'paypal_status' );
                $csv_array[ 1 ][ 0 ][ $i ][ 'paypal_transaction_id' ] = $sub->get_extra_value( 'paypal_transaction_id' );
                $i++;
            }
            return $csv_array;
            
        }

        /**
         * Setup License
         */
        public function setup_license()
        {
            if ( ! class_exists( 'NF_Extension_Updater' ) ) return;

            new NF_Extension_Updater( self::NAME, self::VERSION, self::AUTHOR, __FILE__, self::SLUG );
        }

    }

    /**
     * The main function responsible for returning The Highlander Plugin
     * Instance to functions everywhere.
     *
     * Use this function like you would a global variable, except without needing
     * to declare the global.
     *
     * @since 3.0
     * @return {class} Highlander Instance
     */
    function NF_PayPalSubscriptions()
    {
        return NF_PayPalSubscriptions::instance();
    }

    // Go ninja, go ninja, go!
    NF_PayPalSubscriptions();
}

add_filter( 'ninja_forms_upgrade_settings', 'NF_PayPalSubscriptions_Upgrade', 9999 );
function NF_PayPalSubscriptions_Upgrade( $data ){

    // Migrate plugin settings.
    $plugin_settings = get_option( 'ninja_forms_paypal', array(
        'currency' => 'USD',
        'live_api_user' => '',
        'live_api_pwd' => '',
        'live_api_signature' => '',
        'debug' => 0, // Copy over to per action setting.
        'test_api_user' => '',
        'test_api_pwd' => '',
        'test_api_signature' => ''
    ));
    
    $new_settings = array(
        'ppe_currency' => $plugin_settings[ 'currency' ],
        'ppe_live_api_username' => $plugin_settings[ 'live_api_user' ],
        'ppe_live_api_password' => $plugin_settings[ 'live_api_pwd' ],
        'ppe_live_api_signature' => $plugin_settings[ 'live_api_signature' ],
        'ppe_test_api_username' => $plugin_settings[ 'test_api_user' ],
        'ppe_test_api_password' => $plugin_settings[ 'test_api_pwd' ],
        'ppe_test_api_signature' => $plugin_settings[ 'test_api_signature' ],
    );

    // Check for current settings and overwrite.
    $current_settings = Ninja_Forms()->get_settings();
    foreach( $new_settings as $setting => &$value ) {
        if( isset( $current_settings[ $setting ] ) && !empty( $current_settings[ $setting ] ) ) {
            $value = $current_settings[ $setting ];
        }
    }
    
    Ninja_Forms()->update_settings( $new_settings );


    // Convert form settings to action.
    if( isset( $data[ 'settings' ][ 'paypal_subscriptions' ] ) && 1 == $data[ 'settings' ][ 'paypal_subscriptions' ] ){

        $new_action = array(
            'type' => 'paypal-subscriptions',
            'label' => __( 'PayPal Subscriptions', 'ninja-forms-paypal-subscriptions' ),
            'payment_gateways' => 'paypal-subscriptions',
            'ppe_description' => '',
        );

        /*
         * Payment Total
         */
        if( isset( $data[ 'settings' ][ 'paypal_default_total' ] ) && $data[ 'settings' ][ 'paypal_default_total' ] ) {
            $new_action[ 'payment_total' ] = $data[ 'settings' ][ 'paypal_default_total' ];

            $new_action[ 'payment_total_type' ] = 'fixed';
        }

        foreach( $data[ 'fields' ] as $field ){
            if( '_calc' != $field[ 'type' ] ) continue;
            if( ! isset( $field[ 'data' ][ 'calc_name' ] ) || 'total' != $field[ 'data' ][ 'calc_name' ] ) continue;
            $new_action[ 'payment_total' ] = '{calc:calc_' . $field[ 'id' ] . '}';
        }

        /*
         * Note to Buyer
         *
         * Change: Product Name + Product Description => Description (Note to Buyer)
         */

        if( isset( $data[ 'settings' ][ 'paypal_product_name' ] ) && $data[ 'settings' ][ 'paypal_product_name' ] ) {
            $new_action[ 'ppe_description' ][] = $data[ 'settings' ][ 'paypal_product_name' ];
        }

        if( isset( $data[ 'settings' ][ 'paypal_product_desc' ] ) && $data[ 'settings' ][ 'paypal_product_desc' ] ) {
            $new_action[ 'ppe_description' ][] = $data[ 'settings' ][ 'paypal_product_desc' ];
        }

        $new_action[ 'ppe_description' ] = implode( ': ', $new_action[ 'ppe_description' ] );

        /*
         * Sandbox and Debug Mode
         *
         * Change: Modes are now per action settings.
         * Rename: Debug Mode -> Sandbox Mode (Use Sandbox Credentials)
         * Rename: Test Mode  -> Debug Mode (Debug the Response)
         */
        if( isset( $data[ 'settings' ][ 'paypal_test_mode' ] ) && $data[ 'settings' ][ 'paypal_test_mode' ] ) {
            $new_action[ 'ppe_sandbox' ] = 1;

            //set debug if sandbox mode is enabled.
            if( $plugin_settings[ 'debug' ] ){
                $new_action[ 'ppe_debug' ] = 1;
            }
        }

        $data[ 'actions' ][] = $new_action;
    }

    return $data;
}
