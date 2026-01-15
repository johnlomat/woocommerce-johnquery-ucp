<?php
/**
 * WooCommerce JohnQuery UCP Formatter Helper
 *
 * @package WooCommerce_JQ_UCP
 */

defined('ABSPATH') || exit;

/**
 * WC_JQ_UCP_Formatter Class
 */
class WC_JQ_UCP_Formatter {
    
    /**
     * Format price to minor units (cents)
     */
    public static function to_minor_units(float $amount): int {
        return (int) round($amount * 100);
    }
    
    /**
     * Format from minor units to decimal
     */
    public static function from_minor_units(int $amount): float {
        return $amount / 100;
    }
    
    /**
     * Format address for UCP
     */
    public static function format_address(array $address): array {
        return array_filter([
            'full_name' => trim(($address['first_name'] ?? '') . ' ' . ($address['last_name'] ?? '')),
            'street_address' => $address['address_1'] ?? $address['street_address'] ?? null,
            'extended_address' => $address['address_2'] ?? $address['extended_address'] ?? null,
            'address_locality' => $address['city'] ?? $address['address_locality'] ?? null,
            'address_region' => $address['state'] ?? $address['address_region'] ?? null,
            'postal_code' => $address['postcode'] ?? $address['postal_code'] ?? null,
            'address_country' => $address['country'] ?? $address['address_country'] ?? null,
        ]);
    }
    
    /**
     * Format address from UCP to WooCommerce
     */
    public static function format_address_for_wc(array $ucp_address): array {
        $name_parts = explode(' ', $ucp_address['full_name'] ?? '', 2);
        
        return [
            'first_name' => $name_parts[0] ?? '',
            'last_name' => $name_parts[1] ?? '',
            'address_1' => $ucp_address['street_address'] ?? '',
            'address_2' => $ucp_address['extended_address'] ?? '',
            'city' => $ucp_address['address_locality'] ?? '',
            'state' => $ucp_address['address_region'] ?? '',
            'postcode' => $ucp_address['postal_code'] ?? '',
            'country' => $ucp_address['address_country'] ?? '',
        ];
    }
    
    /**
     * Format date to ISO 8601
     */
    public static function format_date(?string $date): ?string {
        if (empty($date)) {
            return null;
        }
        
        $timestamp = strtotime($date);
        if ($timestamp === false) {
            return null;
        }
        
        return gmdate('c', $timestamp);
    }
    
    /**
     * Format product for UCP
     */
    public static function format_product(WC_Product $product): array {
        return [
            'id' => (string) $product->get_id(),
            'title' => $product->get_name(),
            'price' => self::to_minor_units($product->get_price()),
            'image_url' => wp_get_attachment_url($product->get_image_id()) ?: null,
            'product_url' => $product->get_permalink(),
            'sku' => $product->get_sku() ?: null,
            'description' => $product->get_short_description() ?: null,
            'in_stock' => $product->is_in_stock(),
            'stock_quantity' => $product->get_stock_quantity(),
        ];
    }
    
    /**
     * Format totals array
     */
    public static function format_totals(array $amounts): array {
        $totals = [];
        
        foreach ($amounts as $type => $amount) {
            $totals[] = [
                'type' => $type,
                'amount' => (int) $amount,
            ];
        }
        
        return $totals;
    }
    
    /**
     * Sanitize session ID
     */
    public static function sanitize_session_id(string $id): string {
        return preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
    }
    
    /**
     * Generate unique ID with prefix
     */
    public static function generate_id(string $prefix = ''): string {
        $id = wp_generate_uuid4();
        return $prefix ? $prefix . '_' . $id : $id;
    }
}
