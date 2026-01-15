<?php
/**
 * WooCommerce JohnQuery UCP Validator Helper
 *
 * @package WooCommerce_JQ_UCP
 */

defined('ABSPATH') || exit;

/**
 * WC_JQ_UCP_Validator Class
 */
class WC_JQ_UCP_Validator {
    
    /**
     * Validation errors
     */
    private array $errors = [];
    
    /**
     * Validate line items
     */
    public function validate_line_items(array $items): bool {
        if (empty($items)) {
            $this->add_error('line_items', 'Line items cannot be empty');
            return false;
        }
        
        foreach ($items as $index => $item) {
            if (!isset($item['item']['id'])) {
                $this->add_error("line_items.{$index}.item.id", 'Product ID is required');
            }
            
            if (isset($item['quantity']) && $item['quantity'] < 1) {
                $this->add_error("line_items.{$index}.quantity", 'Quantity must be at least 1');
            }
        }
        
        return empty($this->errors);
    }
    
    /**
     * Validate buyer data
     */
    public function validate_buyer(array $buyer): bool {
        if (isset($buyer['email']) && !is_email($buyer['email'])) {
            $this->add_error('buyer.email', 'Invalid email address');
        }
        
        if (isset($buyer['phone'])) {
            $phone = preg_replace('/[^0-9+]/', '', $buyer['phone']);
            if (strlen($phone) < 10) {
                $this->add_error('buyer.phone', 'Invalid phone number');
            }
        }
        
        return empty($this->errors);
    }
    
    /**
     * Validate address
     */
    public function validate_address(array $address, string $prefix = 'address'): bool {
        $required = ['address_country'];
        
        foreach ($required as $field) {
            if (empty($address[$field])) {
                $this->add_error("{$prefix}.{$field}", ucfirst(str_replace('_', ' ', $field)) . ' is required');
            }
        }
        
        // Validate country code
        if (!empty($address['address_country'])) {
            $countries = WC()->countries->get_countries();
            if (!isset($countries[$address['address_country']])) {
                $this->add_error("{$prefix}.address_country", 'Invalid country code');
            }
        }
        
        // Validate postal code format (basic)
        if (!empty($address['postal_code'])) {
            if (strlen($address['postal_code']) < 3 || strlen($address['postal_code']) > 12) {
                $this->add_error("{$prefix}.postal_code", 'Invalid postal code format');
            }
        }
        
        return empty($this->errors);
    }
    
    /**
     * Validate currency
     */
    public function validate_currency(string $currency): bool {
        $supported = get_woocommerce_currencies();
        
        if (!isset($supported[strtoupper($currency)])) {
            $this->add_error('currency', 'Unsupported currency');
            return false;
        }
        
        return true;
    }
    
    /**
     * Validate payment data
     */
    public function validate_payment_data(array $payment_data): bool {
        if (empty($payment_data['handler_id'])) {
            $this->add_error('payment_data.handler_id', 'Payment handler ID is required');
        }
        
        return empty($this->errors);
    }
    
    /**
     * Validate UCP Agent header
     */
    public function validate_agent_header(?string $header): bool {
        if (empty($header)) {
            $this->add_error('UCP-Agent', 'UCP-Agent header is required');
            return false;
        }
        
        // Check for profile URL
        if (!preg_match('/profile="([^"]+)"/', $header, $matches)) {
            $this->add_error('UCP-Agent', 'Invalid UCP-Agent header format');
            return false;
        }
        
        // Validate profile URL
        $profile_url = $matches[1];
        if (!wp_http_validate_url($profile_url)) {
            $this->add_error('UCP-Agent', 'Invalid profile URL in UCP-Agent header');
            return false;
        }
        
        return true;
    }
    
    /**
     * Add validation error
     */
    public function add_error(string $field, string $message): void {
        $this->errors[$field] = $message;
    }
    
    /**
     * Get all errors
     */
    public function get_errors(): array {
        return $this->errors;
    }
    
    /**
     * Check if there are errors
     */
    public function has_errors(): bool {
        return !empty($this->errors);
    }
    
    /**
     * Get errors as UCP messages
     */
    public function get_error_messages(): array {
        $messages = [];
        
        foreach ($this->errors as $field => $message) {
            $messages[] = [
                'type' => 'error',
                'code' => 'validation_error',
                'field' => $field,
                'message' => $message,
                'severity' => 'requires_buyer_input',
            ];
        }
        
        return $messages;
    }
    
    /**
     * Clear errors
     */
    public function clear(): void {
        $this->errors = [];
    }
}
