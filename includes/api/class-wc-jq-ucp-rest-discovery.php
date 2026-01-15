<?php
/**
 * WooCommerce JohnQuery UCP REST API - Discovery
 *
 * @package WooCommerce_JQ_UCP
 */

defined('ABSPATH') || exit;

/**
 * WC_JQ_UCP_REST_Discovery Class
 */
class WC_JQ_UCP_REST_Discovery {
    
    /**
     * Namespace
     */
    protected string $namespace = 'wc-jq-ucp/v1';
    
    /**
     * Register routes
     */
    public function register_routes(): void {
        register_rest_route($this->namespace, '/profile', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_profile'],
            'permission_callback' => '__return_true',
        ]);
    }
    
    /**
     * Get business profile
     */
    public function get_profile(WP_REST_Request $request): WP_REST_Response {
        $profile = $this->get_business_profile();
        
        $response = new WP_REST_Response($profile);
        $response->set_headers([
            'Cache-Control' => 'public, max-age=3600',
        ]);
        
        return $response;
    }
    
    /**
     * Build business profile for discovery
     */
    public function get_business_profile(): array {
        $store_url = WC_JQ_UCP_Settings::get_store_url();
        $endpoint = WC_JQ_UCP_Settings::get_ucp_endpoint();
        
        $profile = [
            'ucp' => [
                'version' => WC_JQ_UCP_PROTOCOL_VERSION,
                'services' => [
                    'dev.ucp.shopping' => [
                        'version' => WC_JQ_UCP_PROTOCOL_VERSION,
                        'spec' => 'https://ucp.dev/specification/overview',
                        'rest' => [
                            'schema' => 'https://ucp.dev/services/shopping/rest.openapi.json',
                            'endpoint' => $endpoint,
                        ],
                    ],
                ],
                'capabilities' => $this->get_capabilities(),
            ],
            'payment' => [
                'handlers' => $this->get_payment_handlers(),
            ],
        ];
        
        // Add signing keys if available
        $jwk = WC_JQ_UCP_Settings::get_public_key_jwk();
        if ($jwk) {
            $profile['signing_keys'] = [$jwk];
        }
        
        // Add business info
        $profile['business'] = [
            'name' => get_bloginfo('name'),
            'url' => $store_url,
            'logo' => $this->get_store_logo(),
            'support_email' => get_option('admin_email'),
        ];
        
        return apply_filters('wc_jq_ucp_business_profile', $profile);
    }
    
    /**
     * Get supported capabilities
     */
    private function get_capabilities(): array {
        $capabilities = [
            // Core checkout capability
            [
                'name' => 'dev.ucp.shopping.checkout',
                'version' => WC_JQ_UCP_PROTOCOL_VERSION,
                'spec' => 'https://ucp.dev/specification/checkout',
                'schema' => 'https://ucp.dev/schemas/shopping/checkout.json',
            ],
        ];
        
        // Add fulfillment extension if shipping is enabled
        if (wc_shipping_enabled()) {
            $capabilities[] = [
                'name' => 'dev.ucp.shopping.fulfillment',
                'version' => WC_JQ_UCP_PROTOCOL_VERSION,
                'spec' => 'https://ucp.dev/specification/fulfillment',
                'schema' => 'https://ucp.dev/schemas/shopping/fulfillment.json',
                'extends' => 'dev.ucp.shopping.checkout',
            ];
        }
        
        // Add discount extension if coupons are enabled
        if (wc_coupons_enabled()) {
            $capabilities[] = [
                'name' => 'dev.ucp.shopping.discount',
                'version' => WC_JQ_UCP_PROTOCOL_VERSION,
                'spec' => 'https://ucp.dev/specification/discount',
                'schema' => 'https://ucp.dev/schemas/shopping/discount.json',
                'extends' => 'dev.ucp.shopping.checkout',
            ];
        }
        
        // Add order capability
        $capabilities[] = [
            'name' => 'dev.ucp.shopping.order',
            'version' => WC_JQ_UCP_PROTOCOL_VERSION,
            'spec' => 'https://ucp.dev/specification/order',
            'schema' => 'https://ucp.dev/schemas/shopping/order.json',
            'config' => [
                'webhook_supported' => true,
            ],
        ];
        
        return apply_filters('wc_jq_ucp_capabilities', $capabilities);
    }
    
    /**
     * Get payment handlers
     */
    private function get_payment_handlers(): array {
        $handlers = [];
        
        // Primary handler: Embedded checkout (uses WooCommerce's native checkout)
        $handlers[] = [
            'id' => 'wc_embedded_checkout',
            'name' => 'com.woocommerce.embedded_checkout',
            'version' => WC_JQ_UCP_PROTOCOL_VERSION,
            'spec' => 'https://ucp.dev/specification/embedded-checkout',
            'config' => [
                'type' => 'EMBEDDED',
                'checkout_url' => wc_get_checkout_url(),
            ],
        ];
        
        // Add available payment gateways as supported methods
        if (function_exists('WC') && WC()->payment_gateways()) {
            $gateways = WC()->payment_gateways()->get_available_payment_gateways();
            
            $supported_methods = [];
            foreach ($gateways as $gateway_id => $gateway) {
                $supported_methods[] = [
                    'id' => $gateway_id,
                    'title' => $gateway->get_title(),
                    'icon' => $gateway->get_icon() ? wp_strip_all_tags($gateway->get_icon()) : null,
                ];
            }
            
            $handlers[0]['config']['supported_methods'] = $supported_methods;
        }
        
        return apply_filters('wc_jq_ucp_payment_handlers', $handlers);
    }
    
    /**
     * Get store logo URL
     */
    private function get_store_logo(): ?string {
        $custom_logo_id = get_theme_mod('custom_logo');
        if ($custom_logo_id) {
            return wp_get_attachment_url($custom_logo_id);
        }
        return null;
    }
}
