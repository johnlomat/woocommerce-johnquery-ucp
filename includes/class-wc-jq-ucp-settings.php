<?php
/**
 * WooCommerce JohnQuery UCP Settings
 *
 * @package WooCommerce_JQ_UCP
 */

defined('ABSPATH') || exit;

/**
 * WC_JQ_UCP_Settings Class
 */
class WC_JQ_UCP_Settings {
    
    /**
     * Get a setting value
     */
    public static function get(string $key, $default = null) {
        return get_option('wc_jq_ucp_' . $key, $default);
    }
    
    /**
     * Update a setting value
     */
    public static function update(string $key, $value): bool {
        return update_option('wc_jq_ucp_' . $key, $value);
    }
    
    /**
     * Check if UCP is enabled
     */
    public static function is_enabled(): bool {
        return self::get('enabled', 'yes') === 'yes';
    }
    
    /**
     * Check if agent whitelist is enabled
     */
    public static function is_whitelist_enabled(): bool {
        return self::get('agent_whitelist_enabled', 'no') === 'yes';
    }
    
    /**
     * Get whitelisted agent domains
     */
    public static function get_whitelist(): array {
        $whitelist = self::get('agent_whitelist', '');
        if (empty($whitelist)) {
            return [];
        }
        return array_filter(array_map('trim', explode("\n", $whitelist)));
    }
    
    /**
     * Check if signature verification is required
     */
    public static function requires_signature(): bool {
        return self::get('require_signature', 'no') === 'yes';
    }
    
    /**
     * Get session timeout in minutes
     */
    public static function get_session_timeout(): int {
        return (int) self::get('session_timeout', 30);
    }
    
    /**
     * Check if debug mode is enabled
     */
    public static function is_debug(): bool {
        return self::get('debug_mode', 'no') === 'yes';
    }
    
    /**
     * Get the signing key ID
     */
    public static function get_key_id(): string {
        return self::get('key_id', 'wc_jq_ucp_default');
    }
    
    /**
     * Get the public key in PEM format
     */
    public static function get_public_key(): string {
        return self::get('public_key', '');
    }
    
    /**
     * Get the private key in PEM format
     */
    public static function get_private_key(): string {
        return self::get('private_key', '');
    }
    
    /**
     * Get the public key as JWK
     */
    public static function get_public_key_jwk(): ?array {
        $public_key = self::get_public_key();
        if (empty($public_key)) {
            return null;
        }
        
        $key = openssl_pkey_get_public($public_key);
        if (!$key) {
            return null;
        }
        
        $details = openssl_pkey_get_details($key);
        if (!$details || $details['type'] !== OPENSSL_KEYTYPE_EC) {
            return null;
        }
        
        // Convert EC coordinates to base64url
        $x = rtrim(strtr(base64_encode($details['ec']['x']), '+/', '-_'), '=');
        $y = rtrim(strtr(base64_encode($details['ec']['y']), '+/', '-_'), '=');
        
        return [
            'kid' => self::get_key_id(),
            'kty' => 'EC',
            'crv' => 'P-256',
            'x' => $x,
            'y' => $y,
            'use' => 'sig',
            'alg' => 'ES256',
        ];
    }
    
    /**
     * Get store base URL
     */
    public static function get_store_url(): string {
        return trailingslashit(home_url());
    }
    
    /**
     * Get UCP endpoint base URL
     */
    public static function get_ucp_endpoint(): string {
        return rest_url('wc-jq-ucp/v1');
    }
    
    /**
     * Get supported payment handlers
     */
    public static function get_payment_handlers(): array {
        $handlers = [];
        
        // Get available WooCommerce payment gateways
        if (function_exists('WC') && WC()->payment_gateways()) {
            $gateways = WC()->payment_gateways()->get_available_payment_gateways();
            
            foreach ($gateways as $gateway_id => $gateway) {
                // For embedded checkout, we expose WooCommerce's native checkout
                $handlers[] = [
                    'id' => 'wc_' . $gateway_id,
                    'name' => 'com.woocommerce.' . $gateway_id,
                    'version' => WC_JQ_UCP_PROTOCOL_VERSION,
                    'spec' => 'https://woocommerce.com/ucp/payment-handlers/' . $gateway_id,
                    'config' => [
                        'type' => 'EMBEDDED',
                        'title' => $gateway->get_title(),
                        'description' => $gateway->get_description(),
                    ],
                ];
            }
        }
        
        return apply_filters('wc_jq_ucp_payment_handlers', $handlers);
    }
}
