<?php
/**
 * WooCommerce JohnQuery UCP Cryptographic Utilities
 *
 * @package WooCommerce_JQ_UCP
 */

defined('ABSPATH') || exit;

/**
 * WC_JQ_UCP_Crypto Class
 */
class WC_JQ_UCP_Crypto {
    
    /**
     * Sign data with ES256 (ECDSA using P-256 and SHA-256)
     */
    public static function sign(string $data): ?string {
        $private_key = WC_JQ_UCP_Settings::get_private_key();
        
        if (empty($private_key)) {
            return null;
        }
        
        $key = openssl_pkey_get_private($private_key);
        if (!$key) {
            return null;
        }
        
        $signature = '';
        $result = openssl_sign($data, $signature, $key, OPENSSL_ALGO_SHA256);
        
        if (!$result) {
            return null;
        }
        
        // Convert to JWS format (base64url)
        return self::base64url_encode($signature);
    }
    
    /**
     * Verify signature with ES256
     */
    public static function verify(string $data, string $signature, string $public_key_pem): bool {
        $key = openssl_pkey_get_public($public_key_pem);
        if (!$key) {
            return false;
        }
        
        $decoded_signature = self::base64url_decode($signature);
        if ($decoded_signature === false) {
            return false;
        }
        
        return openssl_verify($data, $decoded_signature, $key, OPENSSL_ALGO_SHA256) === 1;
    }
    
    /**
     * Create a signed JWT (JWS)
     */
    public static function create_jws(array $payload): ?string {
        $header = [
            'alg' => 'ES256',
            'typ' => 'JWT',
            'kid' => WC_JQ_UCP_Settings::get_key_id(),
        ];
        
        $header_b64 = self::base64url_encode(wp_json_encode($header));
        $payload_b64 = self::base64url_encode(wp_json_encode($payload));
        
        $signing_input = $header_b64 . '.' . $payload_b64;
        $signature = self::sign($signing_input);
        
        if (!$signature) {
            return null;
        }
        
        return $signing_input . '.' . $signature;
    }
    
    /**
     * Verify and decode a JWS
     */
    public static function verify_jws(string $jws, string $public_key_pem): ?array {
        $parts = explode('.', $jws);
        if (count($parts) !== 3) {
            return null;
        }
        
        [$header_b64, $payload_b64, $signature] = $parts;
        
        $signing_input = $header_b64 . '.' . $payload_b64;
        
        if (!self::verify($signing_input, $signature, $public_key_pem)) {
            return null;
        }
        
        $payload = json_decode(self::base64url_decode($payload_b64), true);
        return $payload ?: null;
    }
    
    /**
     * Base64URL encode
     */
    public static function base64url_encode(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
    
    /**
     * Base64URL decode
     */
    public static function base64url_decode(string $data): string|false {
        $padded = str_pad($data, strlen($data) + (4 - strlen($data) % 4) % 4, '=');
        return base64_decode(strtr($padded, '-_', '+/'));
    }
    
    /**
     * Generate a nonce
     */
    public static function generate_nonce(): string {
        return bin2hex(random_bytes(16));
    }
    
    /**
     * Validate agent signature
     */
    public static function validate_agent_signature(WP_REST_Request $request): bool {
        if (!WC_JQ_UCP_Settings::requires_signature()) {
            return true;
        }
        
        $signature_header = $request->get_header('Request-Signature');
        if (empty($signature_header)) {
            return false;
        }
        
        // Get agent profile to retrieve public key
        $agent_header = $request->get_header('UCP-Agent');
        if (empty($agent_header)) {
            return false;
        }
        
        // Parse profile URL from header
        if (preg_match('/profile="([^"]+)"/', $agent_header, $matches)) {
            $profile_url = $matches[1];
            
            // Fetch agent profile (with caching)
            $profile = self::fetch_agent_profile($profile_url);
            if (!$profile || empty($profile['signing_keys'])) {
                return false;
            }
            
            // Get signing key
            $signing_key_jwk = $profile['signing_keys'][0] ?? null;
            if (!$signing_key_jwk) {
                return false;
            }
            
            // Convert JWK to PEM
            $public_key_pem = self::jwk_to_pem($signing_key_jwk);
            if (!$public_key_pem) {
                return false;
            }
            
            // Verify the signature
            $body = $request->get_body();
            return self::verify($body, $signature_header, $public_key_pem);
        }
        
        return false;
    }
    
    /**
     * Fetch agent profile with caching
     */
    private static function fetch_agent_profile(string $url): ?array {
        $cache_key = 'wc_jq_ucp_agent_profile_' . md5($url);
        $cached = get_transient($cache_key);
        
        if ($cached !== false) {
            return $cached;
        }
        
        $response = wp_remote_get($url, [
            'timeout' => 10,
            'headers' => [
                'Accept' => 'application/json',
            ],
        ]);
        
        if (is_wp_error($response)) {
            return null;
        }
        
        $body = wp_remote_retrieve_body($response);
        $profile = json_decode($body, true);
        
        if (!$profile) {
            return null;
        }
        
        // Cache for 1 hour
        set_transient($cache_key, $profile, HOUR_IN_SECONDS);
        
        return $profile;
    }
    
    /**
     * Convert EC JWK to PEM format
     */
    private static function jwk_to_pem(array $jwk): ?string {
        if (($jwk['kty'] ?? '') !== 'EC' || ($jwk['crv'] ?? '') !== 'P-256') {
            return null;
        }
        
        $x = self::base64url_decode($jwk['x'] ?? '');
        $y = self::base64url_decode($jwk['y'] ?? '');
        
        if ($x === false || $y === false) {
            return null;
        }
        
        // Build ASN.1 structure for EC public key
        // OID for P-256 curve
        $oid = pack('H*', '06082a8648ce3d030107');
        // OID for ecPublicKey
        $ec_oid = pack('H*', '06072a8648ce3d0201');
        
        // Build algorithm identifier
        $algo_id = chr(0x30) . chr(strlen($ec_oid . $oid)) . $ec_oid . $oid;
        
        // Build public key bit string
        $point = chr(0x04) . $x . $y; // Uncompressed point format
        $bit_string = chr(0x03) . chr(strlen($point) + 1) . chr(0x00) . $point;
        
        // Build sequence
        $sequence = chr(0x30) . chr(strlen($algo_id . $bit_string)) . $algo_id . $bit_string;
        
        // Encode as PEM
        $pem = "-----BEGIN PUBLIC KEY-----\n";
        $pem .= chunk_split(base64_encode($sequence), 64, "\n");
        $pem .= "-----END PUBLIC KEY-----\n";
        
        return $pem;
    }
}
