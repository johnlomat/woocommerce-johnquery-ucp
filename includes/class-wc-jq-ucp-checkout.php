<?php
/**
 * WooCommerce JohnQuery UCP Checkout Handler
 *
 * @package WooCommerce_JQ_UCP
 */

defined('ABSPATH') || exit;

/**
 * WC_JQ_UCP_Checkout Class
 */
class WC_JQ_UCP_Checkout {
    
    /**
     * Session instance
     */
    private WC_JQ_UCP_Session $session;
    
    /**
     * Messages array
     */
    private array $messages = [];
    
    /**
     * Constructor
     */
    public function __construct(WC_JQ_UCP_Session $session) {
        $this->session = $session;
    }
    
    /**
     * Process line items from request
     */
    public function process_line_items(array $items): array {
        $processed = [];
        
        foreach ($items as $index => $item) {
            $product_id = $item['item']['id'] ?? null;
            $quantity = $item['quantity'] ?? 1;
            
            if (!$product_id) {
                $this->add_message('error', 'invalid_item', "Line item {$index} is missing product ID");
                continue;
            }
            
            // Find WooCommerce product
            $product = $this->find_product($product_id);
            
            if (!$product) {
                $this->add_message('error', 'product_not_found', "Product '{$product_id}' not found");
                continue;
            }
            
            if (!$product->is_purchasable()) {
                $this->add_message('error', 'product_not_purchasable', "Product '{$product_id}' is not available for purchase");
                continue;
            }
            
            if (!$product->is_in_stock()) {
                $this->add_message('error', 'out_of_stock', "Product '{$product_id}' is out of stock");
                continue;
            }
            
            // Check stock quantity
            if ($product->managing_stock() && $product->get_stock_quantity() < $quantity) {
                $this->add_message('warning', 'insufficient_stock', 
                    "Only {$product->get_stock_quantity()} units available for '{$product_id}'");
                $quantity = $product->get_stock_quantity();
            }
            
            $line_id = $item['id'] ?? 'li_' . ($index + 1);
            $price = (int) round($product->get_price() * 100); // Convert to minor units
            
            $processed[] = [
                'id' => $line_id,
                'item' => [
                    'id' => (string) $product->get_id(),
                    'title' => $product->get_name(),
                    'price' => $price,
                    'image_url' => wp_get_attachment_url($product->get_image_id()) ?: null,
                    'product_url' => $product->get_permalink(),
                    'sku' => $product->get_sku() ?: null,
                ],
                'quantity' => $quantity,
                'totals' => [
                    ['type' => 'subtotal', 'amount' => $price * $quantity],
                ],
                'wc_product_id' => $product->get_id(),
            ];
        }
        
        return $processed;
    }
    
    /**
     * Find a WooCommerce product by ID or SKU
     */
    private function find_product($identifier): ?WC_Product {
        // Try as product ID first
        if (is_numeric($identifier)) {
            $product = wc_get_product((int) $identifier);
            if ($product) {
                return $product;
            }
        }
        
        // Try as SKU
        $product_id = wc_get_product_id_by_sku($identifier);
        if ($product_id) {
            return wc_get_product($product_id);
        }
        
        // Try as slug
        $product = get_page_by_path($identifier, OBJECT, 'product');
        if ($product) {
            return wc_get_product($product->ID);
        }
        
        return null;
    }
    
    /**
     * Calculate totals for the session
     */
    public function calculate_totals(): array {
        $line_items = $this->session->get_line_items();
        $fulfillment = $this->session->get_fulfillment();
        
        $subtotal = 0;
        $shipping = 0;
        $tax = 0;
        $discount = 0;
        
        // Calculate subtotal
        foreach ($line_items as $item) {
            $item_subtotal = $item['item']['price'] * $item['quantity'];
            $subtotal += $item_subtotal;
        }
        
        // Calculate shipping if address provided
        if (!empty($fulfillment['methods'])) {
            foreach ($fulfillment['methods'] as $method) {
                if (isset($method['groups'])) {
                    foreach ($method['groups'] as $group) {
                        $selected = $group['selected_option_id'] ?? null;
                        if ($selected && isset($group['options'])) {
                            foreach ($group['options'] as $option) {
                                if ($option['id'] === $selected) {
                                    $shipping += $option['totals'][0]['amount'] ?? 0;
                                    break;
                                }
                            }
                        }
                    }
                }
            }
        }
        
        // Calculate tax (simplified - uses WooCommerce tax settings)
        $tax = $this->calculate_tax($subtotal, $fulfillment);
        
        // Total
        $total = $subtotal + $shipping + $tax - $discount;
        
        return [
            ['type' => 'subtotal', 'amount' => $subtotal],
            ['type' => 'shipping', 'amount' => $shipping],
            ['type' => 'tax', 'amount' => $tax],
            ['type' => 'discount', 'amount' => $discount],
            ['type' => 'total', 'amount' => $total],
        ];
    }
    
    /**
     * Calculate tax based on destination
     */
    private function calculate_tax(int $subtotal, array $fulfillment): int {
        // Get destination address
        $destination = $this->get_shipping_destination($fulfillment);
        
        if (empty($destination)) {
            // No destination, use store base for estimates
            $destination = [
                'country' => WC()->countries->get_base_country(),
                'state' => WC()->countries->get_base_state(),
                'postcode' => WC()->countries->get_base_postcode(),
            ];
        }
        
        // Check if tax is enabled
        if (!wc_tax_enabled()) {
            return 0;
        }
        
        // Get tax rates
        $tax_rates = WC_Tax::find_rates([
            'country' => $destination['country'] ?? '',
            'state' => $destination['state'] ?? '',
            'postcode' => $destination['postcode'] ?? '',
            'city' => $destination['city'] ?? '',
        ]);
        
        if (empty($tax_rates)) {
            return 0;
        }
        
        // Calculate tax (subtotal is in minor units, convert for calculation)
        $subtotal_decimal = $subtotal / 100;
        $taxes = WC_Tax::calc_tax($subtotal_decimal, $tax_rates);
        $total_tax = array_sum($taxes);
        
        return (int) round($total_tax * 100);
    }
    
    /**
     * Get shipping destination from fulfillment data
     */
    private function get_shipping_destination(array $fulfillment): array {
        if (empty($fulfillment['methods'])) {
            return [];
        }
        
        foreach ($fulfillment['methods'] as $method) {
            if (!empty($method['destinations'])) {
                $dest = $method['destinations'][0];
                return [
                    'country' => $dest['address_country'] ?? '',
                    'state' => $dest['address_region'] ?? '',
                    'postcode' => $dest['postal_code'] ?? '',
                    'city' => $dest['address_locality'] ?? '',
                ];
            }
        }
        
        return [];
    }
    
    /**
     * Get available shipping methods
     */
    public function get_shipping_options(array $destination, array $line_items): array {
        if (empty($destination) || empty($line_items)) {
            return [];
        }
        
        // Initialize WooCommerce session if not exists
        if (!WC()->session) {
            WC()->initialize_session();
        }
        
        // Initialize customer if not exists
        if (!WC()->customer) {
            WC()->customer = new WC_Customer(0, true);
        }
        
        // Set customer shipping location
        WC()->customer->set_shipping_country($destination['address_country'] ?? '');
        WC()->customer->set_shipping_state($destination['address_region'] ?? '');
        WC()->customer->set_shipping_postcode($destination['postal_code'] ?? '');
        WC()->customer->set_shipping_city($destination['address_locality'] ?? '');
        
        // Create a temporary cart to calculate shipping
        $package = [
            'destination' => [
                'country' => $destination['address_country'] ?? '',
                'state' => $destination['address_region'] ?? '',
                'postcode' => $destination['postal_code'] ?? '',
                'city' => $destination['address_locality'] ?? '',
                'address' => $destination['street_address'] ?? '',
            ],
            'contents' => [],
            'contents_cost' => 0,
            'applied_coupons' => [],
            'user' => [
                'ID' => 0,
            ],
        ];
        
        // Add items to package
        foreach ($line_items as $key => $item) {
            $product = wc_get_product($item['wc_product_id'] ?? $item['item']['id']);
            if (!$product) continue;
            
            $line_total = ($item['item']['price'] * $item['quantity']) / 100;
            
            $package['contents'][$key] = [
                'product_id' => $product->get_id(),
                'variation_id' => 0,
                'quantity' => $item['quantity'],
                'data' => $product,
                'line_total' => $line_total,
                'line_tax' => 0,
                'line_subtotal' => $line_total,
                'line_subtotal_tax' => 0,
            ];
            $package['contents_cost'] += $line_total;
        }
        
        // Calculate shipping for the package
        $shipping = WC()->shipping();
        $shipping->reset_shipping();
        
        // Get available shipping methods for this package
        $package = $shipping->calculate_shipping_for_package($package);
        
        $options = [];
        if (!empty($package['rates'])) {
            foreach ($package['rates'] as $rate_id => $rate) {
                $options[] = [
                    'id' => $rate_id,
                    'title' => $rate->get_label(),
                    'totals' => [
                        ['type' => 'total', 'amount' => (int) round($rate->get_cost() * 100)],
                    ],
                ];
            }
        }
        
        return $options;
    }
    
    /**
     * Create WooCommerce order from session
     */
    public function create_order(array $payment_data): ?WC_Order {
        $line_items = $this->session->get_line_items();
        $buyer = $this->session->get_buyer();
        $fulfillment = $this->session->get_fulfillment();
        $totals = $this->session->get_totals();
        
        // Create order
        $order = wc_create_order([
            'status' => 'pending',
            'customer_id' => 0, // Guest order
        ]);
        
        if (is_wp_error($order)) {
            $this->add_message('error', 'order_creation_failed', $order->get_error_message());
            return null;
        }
        
        // Add line items
        foreach ($line_items as $item) {
            $product = wc_get_product($item['wc_product_id'] ?? $item['item']['id']);
            if ($product) {
                $order->add_product($product, $item['quantity']);
            }
        }
        
        // Set billing address
        $order->set_billing_first_name($buyer['first_name'] ?? '');
        $order->set_billing_last_name($buyer['last_name'] ?? '');
        $order->set_billing_email($buyer['email'] ?? '');
        $order->set_billing_phone($buyer['phone'] ?? '');
        
        // Set billing address from payment data if available
        if (!empty($payment_data['billing_address'])) {
            $billing = $payment_data['billing_address'];
            $order->set_billing_address_1($billing['street_address'] ?? '');
            $order->set_billing_city($billing['address_locality'] ?? '');
            $order->set_billing_state($billing['address_region'] ?? '');
            $order->set_billing_postcode($billing['postal_code'] ?? '');
            $order->set_billing_country($billing['address_country'] ?? '');
        }
        
        // Set shipping address
        $destination = $this->get_shipping_destination($fulfillment);
        if (!empty($destination)) {
            $shipping_dest = $fulfillment['methods'][0]['destinations'][0] ?? [];
            $order->set_shipping_first_name($shipping_dest['full_name'] ?? $buyer['first_name'] ?? '');
            $order->set_shipping_address_1($shipping_dest['street_address'] ?? '');
            $order->set_shipping_city($destination['city'] ?? '');
            $order->set_shipping_state($destination['state'] ?? '');
            $order->set_shipping_postcode($destination['postcode'] ?? '');
            $order->set_shipping_country($destination['country'] ?? '');
        }
        
        // Add shipping
        $shipping_total = 0;
        foreach ($totals as $total) {
            if ($total['type'] === 'shipping') {
                $shipping_total = $total['amount'] / 100;
                break;
            }
        }
        
        if ($shipping_total > 0) {
            $shipping_item = new WC_Order_Item_Shipping();
            $shipping_item->set_method_title('Shipping');
            $shipping_item->set_total($shipping_total);
            $order->add_item($shipping_item);
        }
        
        // Calculate totals
        $order->calculate_totals();
        
        // Add UCP metadata
        $order->add_meta_data('_ucp_session_id', $this->session->get_id());
        $order->add_meta_data('_ucp_platform_profile', $this->session->get_platform_profile());
        $order->add_meta_data('_ucp_payment_handler', $payment_data['handler_id'] ?? '');
        
        // Save order
        $order->save();
        
        return $order;
    }
    
    /**
     * Add a message
     */
    public function add_message(string $type, string $code, string $message, string $severity = 'info'): void {
        $this->messages[] = [
            'type' => $type,
            'code' => $code,
            'message' => $message,
            'severity' => $severity,
        ];
    }
    
    /**
     * Get all messages
     */
    public function get_messages(): array {
        return $this->messages;
    }
    
    /**
     * Check if there are errors
     */
    public function has_errors(): bool {
        foreach ($this->messages as $message) {
            if ($message['type'] === 'error') {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Get required links (terms, privacy, etc.)
     */
    public function get_links(): array {
        $links = [];
        
        // Terms and conditions
        $terms_page_id = wc_terms_and_conditions_page_id();
        if ($terms_page_id) {
            $links[] = [
                'rel' => 'terms_of_service',
                'href' => get_permalink($terms_page_id),
            ];
        }
        
        // Privacy policy
        $privacy_page_id = get_option('wp_page_for_privacy_policy');
        if ($privacy_page_id) {
            $links[] = [
                'rel' => 'privacy_policy',
                'href' => get_permalink($privacy_page_id),
            ];
        }
        
        return $links;
    }
    
    /**
     * Determine session status based on current state
     */
    public function determine_status(): string {
        $line_items = $this->session->get_line_items();
        $fulfillment = $this->session->get_fulfillment();
        $buyer = $this->session->get_buyer();
        
        // No items
        if (empty($line_items)) {
            return WC_JQ_UCP_Session::STATUS_INCOMPLETE;
        }
        
        // Check if shipping is required
        $needs_shipping = false;
        foreach ($line_items as $item) {
            $product = wc_get_product($item['wc_product_id'] ?? $item['item']['id']);
            if ($product && $product->needs_shipping()) {
                $needs_shipping = true;
                break;
            }
        }
        
        // If shipping needed, check for address and selection
        if ($needs_shipping) {
            $destination = $this->get_shipping_destination($fulfillment);
            if (empty($destination['country'])) {
                return WC_JQ_UCP_Session::STATUS_INCOMPLETE;
            }
            
            // Check for shipping method selection
            $has_shipping_selection = false;
            foreach ($fulfillment['methods'] ?? [] as $method) {
                foreach ($method['groups'] ?? [] as $group) {
                    if (!empty($group['selected_option_id'])) {
                        $has_shipping_selection = true;
                        break 2;
                    }
                }
            }
            
            if (!$has_shipping_selection) {
                return WC_JQ_UCP_Session::STATUS_INCOMPLETE;
            }
        }
        
        // All requirements met
        return WC_JQ_UCP_Session::STATUS_READY_FOR_COMPLETE;
    }
}
