<?php
/**
 * Plugin Name: WooCommerce JohnQuery UCP - Universal Commerce Protocol
 * Plugin URI: https://www.johnquery.com/
 * Description: Enables AI agents to purchase products from your WooCommerce store using the Universal Commerce Protocol (UCP)
 * Version: 1.5.0
 * Author: JohnQuery
 * Author URI: https://www.johnquery.com/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: woocommerce-johnquery-ucp
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * WC requires at least: 8.0
 * WC tested up to: 9.0
 */

defined('ABSPATH') || exit;

// Define plugin constants
define('WC_JQ_UCP_VERSION', '1.5.0');
define('WC_JQ_UCP_PROTOCOL_VERSION', '2026-01-11');
define('WC_JQ_UCP_PLUGIN_FILE', __FILE__);
define('WC_JQ_UCP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WC_JQ_UCP_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Main WooCommerce JohnQuery UCP Class
 */
final class WC_JQ_UCP {
    
    /**
     * Single instance of the class
     */
    private static ?WC_JQ_UCP $instance = null;
    
    /**
     * Get the single instance
     */
    public static function instance(): WC_JQ_UCP {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->includes();
        $this->init_hooks();
    }
    
    /**
     * Include required files
     */
    private function includes(): void {
        // Core classes
        require_once WC_JQ_UCP_PLUGIN_DIR . 'includes/class-wc-jq-ucp-install.php';
        require_once WC_JQ_UCP_PLUGIN_DIR . 'includes/class-wc-jq-ucp-settings.php';
        require_once WC_JQ_UCP_PLUGIN_DIR . 'includes/class-wc-jq-ucp-crypto.php';
        require_once WC_JQ_UCP_PLUGIN_DIR . 'includes/class-wc-jq-ucp-session.php';
        require_once WC_JQ_UCP_PLUGIN_DIR . 'includes/class-wc-jq-ucp-checkout.php';
        
        // REST API
        require_once WC_JQ_UCP_PLUGIN_DIR . 'includes/api/class-wc-jq-ucp-rest-discovery.php';
        require_once WC_JQ_UCP_PLUGIN_DIR . 'includes/api/class-wc-jq-ucp-rest-checkout.php';
        require_once WC_JQ_UCP_PLUGIN_DIR . 'includes/api/class-wc-jq-ucp-rest-order.php';
        
        // Helpers
        require_once WC_JQ_UCP_PLUGIN_DIR . 'includes/helpers/class-wc-jq-ucp-formatter.php';
        require_once WC_JQ_UCP_PLUGIN_DIR . 'includes/helpers/class-wc-jq-ucp-validator.php';
        
        // Admin
        if (is_admin()) {
            require_once WC_JQ_UCP_PLUGIN_DIR . 'includes/admin/class-wc-jq-ucp-admin.php';
        }
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks(): void {
        // Check dependencies
        add_action('plugins_loaded', [$this, 'check_dependencies']);
        
        // Register activation/deactivation hooks
        register_activation_hook(WC_JQ_UCP_PLUGIN_FILE, ['WC_JQ_UCP_Install', 'activate']);
        register_deactivation_hook(WC_JQ_UCP_PLUGIN_FILE, ['WC_JQ_UCP_Install', 'deactivate']);
        
        // Initialize REST API
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        
        // Add well-known rewrite rules
        add_action('init', [$this, 'add_rewrite_rules']);
        add_action('template_redirect', [$this, 'handle_well_known']);
        
        // Add settings link
        add_filter('plugin_action_links_' . plugin_basename(WC_JQ_UCP_PLUGIN_FILE), [$this, 'add_settings_link']);
        
        // Handle UCP session on checkout page
        add_action('template_redirect', [$this, 'handle_ucp_checkout_session']);
        
        // Link order to UCP session after checkout
        add_action('woocommerce_checkout_order_processed', ['WC_JQ_UCP', 'link_order_to_ucp_session']);
    }
    
    /**
     * Handle UCP session parameter on checkout page
     * Loads UCP session data into WooCommerce cart
     */
    public function handle_ucp_checkout_session(): void {
        // Only on checkout page
        if (!is_checkout()) {
            return;
        }
        
        // Check for UCP session parameter
        $session_id = isset($_GET['ucp_session']) ? sanitize_text_field($_GET['ucp_session']) : null;
        
        if (!$session_id) {
            return;
        }
        
        // Load UCP session
        $session = new WC_JQ_UCP_Session($session_id);
        
        if (!$session->exists()) {
            wc_add_notice(__('Checkout session not found or expired.', 'woocommerce-johnquery-ucp'), 'error');
            return;
        }
        
        if ($session->is_expired()) {
            wc_add_notice(__('Checkout session has expired. Please start again.', 'woocommerce-johnquery-ucp'), 'error');
            return;
        }
        
        // Clear current cart
        WC()->cart->empty_cart();
        
        // Add line items to cart
        $line_items = $session->get_line_items();
        foreach ($line_items as $item) {
            $product_id = $item['wc_product_id'] ?? $item['item']['id'];
            $quantity = $item['quantity'] ?? 1;
            
            WC()->cart->add_to_cart($product_id, $quantity);
        }
        
        // Set customer data
        $buyer = $session->get_buyer();
        if (!empty($buyer)) {
            if (!empty($buyer['email'])) {
                WC()->customer->set_billing_email($buyer['email']);
            }
            if (!empty($buyer['first_name'])) {
                WC()->customer->set_billing_first_name($buyer['first_name']);
                WC()->customer->set_shipping_first_name($buyer['first_name']);
            }
            if (!empty($buyer['last_name'])) {
                WC()->customer->set_billing_last_name($buyer['last_name']);
                WC()->customer->set_shipping_last_name($buyer['last_name']);
            }
            if (!empty($buyer['phone'])) {
                WC()->customer->set_billing_phone($buyer['phone']);
            }
        }
        
        // Set shipping address
        $fulfillment = $session->get_fulfillment();
        if (!empty($fulfillment['methods'])) {
            foreach ($fulfillment['methods'] as $method) {
                if ($method['type'] === 'shipping' && !empty($method['destinations'])) {
                    $dest = $method['destinations'][0];
                    
                    // Shipping address
                    WC()->customer->set_shipping_address_1($dest['street_address'] ?? '');
                    WC()->customer->set_shipping_city($dest['address_locality'] ?? '');
                    WC()->customer->set_shipping_state($dest['address_region'] ?? '');
                    WC()->customer->set_shipping_postcode($dest['postal_code'] ?? '');
                    WC()->customer->set_shipping_country($dest['address_country'] ?? '');
                    
                    // Also set billing address if not set
                    WC()->customer->set_billing_address_1($dest['street_address'] ?? '');
                    WC()->customer->set_billing_city($dest['address_locality'] ?? '');
                    WC()->customer->set_billing_state($dest['address_region'] ?? '');
                    WC()->customer->set_billing_postcode($dest['postal_code'] ?? '');
                    WC()->customer->set_billing_country($dest['address_country'] ?? '');
                    
                    break;
                }
            }
        }
        
        // Save customer data
        WC()->customer->save();
        
        // Store session ID for order creation
        WC()->session->set('ucp_session_id', $session_id);
        
        // Add success notice
        wc_add_notice(__('Your cart has been loaded. Please complete your payment.', 'woocommerce-johnquery-ucp'), 'success');
    }
    
    /**
     * Link WooCommerce order to UCP session after order is placed
     */
    public static function link_order_to_ucp_session($order_id): void {
        if (!WC()->session) {
            return;
        }
        
        $session_id = WC()->session->get('ucp_session_id');
        
        if (!$session_id) {
            return;
        }
        
        // Update order meta
        $order = wc_get_order($order_id);
        if ($order) {
            $order->add_meta_data('_ucp_session_id', $session_id);
            $order->add_meta_data('_ucp_checkout', 'yes');
            $order->add_order_note(__('Order placed via UCP (Universal Commerce Protocol)', 'woocommerce-johnquery-ucp'));
            $order->save();
        }
        
        // Update UCP session
        $session = new WC_JQ_UCP_Session($session_id);
        if ($session->exists()) {
            $session->set_wc_order_id($order_id);
            $session->set_status(WC_JQ_UCP_Session::STATUS_COMPLETE);
            $session->save();
        }
        
        // Clear session
        WC()->session->set('ucp_session_id', null);
    }
    
    /**
     * Check if WooCommerce is active
     */
    public function check_dependencies(): void {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', function() {
                echo '<div class="error"><p>';
                echo esc_html__('WooCommerce JohnQuery UCP requires WooCommerce to be installed and active.', 'woocommerce-johnquery-ucp');
                echo '</p></div>';
            });
            return;
        }
    }
    
    /**
     * Register REST API routes
     */
    public function register_rest_routes(): void {
        $discovery = new WC_JQ_UCP_REST_Discovery();
        $discovery->register_routes();
        
        $checkout = new WC_JQ_UCP_REST_Checkout();
        $checkout->register_routes();
        
        $order = new WC_JQ_UCP_REST_Order();
        $order->register_routes();
    }
    
    /**
     * Add rewrite rules for well-known endpoint
     */
    public function add_rewrite_rules(): void {
        add_rewrite_rule(
            '^\.well-known/ucp/?$',
            'index.php?wc_jq_ucp_well_known=1',
            'top'
        );
        add_rewrite_tag('%wc_jq_ucp_well_known%', '1');
    }
    
    /**
     * Handle well-known endpoint
     */
    public function handle_well_known(): void {
        if (get_query_var('wc_jq_ucp_well_known')) {
            $discovery = new WC_JQ_UCP_REST_Discovery();
            $profile = $discovery->get_business_profile();
            
            header('Content-Type: application/json');
            header('Access-Control-Allow-Origin: *');
            header('Cache-Control: public, max-age=3600');
            
            echo wp_json_encode($profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            exit;
        }
    }
    
    /**
     * Add settings link to plugins page
     */
    public function add_settings_link(array $links): array {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            admin_url('admin.php?page=woocommerce-johnquery-ucp-settings'),
            __('Settings', 'woocommerce-johnquery-ucp')
        );
        array_unshift($links, $settings_link);
        return $links;
    }
}

/**
 * Initialize the plugin
 */
function wc_ucp(): WC_JQ_UCP {
    return WC_JQ_UCP::instance();
}

// Start the plugin
add_action('plugins_loaded', 'wc_ucp', 10);
