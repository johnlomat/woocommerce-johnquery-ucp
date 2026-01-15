<?php
/**
 * WooCommerce JohnQuery UCP REST API - Order & Webhooks
 *
 * @package WooCommerce_JQ_UCP
 */

defined('ABSPATH') || exit;

/**
 * WC_JQ_UCP_REST_Order Class
 */
class WC_JQ_UCP_REST_Order {
    
    /**
     * Namespace
     */
    protected string $namespace = 'wc-jq-ucp/v1';
    
    /**
     * Constructor
     */
    public function __construct() {
        // Hook into WooCommerce order status changes
        add_action('woocommerce_order_status_changed', [$this, 'on_order_status_changed'], 10, 4);
        add_action('woocommerce_order_refunded', [$this, 'on_order_refunded'], 10, 2);
        add_action('woocommerce_shipment_tracking_added', [$this, 'on_tracking_added'], 10, 3);
    }
    
    /**
     * Register routes
     */
    public function register_routes(): void {
        // Get order
        register_rest_route($this->namespace, '/orders/(?P<id>[a-zA-Z0-9_-]+)', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_order'],
            'permission_callback' => [$this, 'check_permissions'],
        ]);
        
        // Register webhook callback URL
        register_rest_route($this->namespace, '/webhooks/register', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'register_webhook'],
            'permission_callback' => [$this, 'check_permissions'],
        ]);
    }
    
    /**
     * Check permissions
     */
    public function check_permissions(WP_REST_Request $request): bool|WP_Error {
        if (!WC_JQ_UCP_Settings::is_enabled()) {
            return new WP_Error('ucp_disabled', 'UCP is disabled', ['status' => 503]);
        }
        return true;
    }
    
    /**
     * Get order details
     */
    public function get_order(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $order_id = $request->get_param('id');
        
        // Remove 'order_' prefix if present
        if (str_starts_with($order_id, 'order_')) {
            $order_id = substr($order_id, 6);
        }
        
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return new WP_Error(
                'order_not_found',
                'Order not found',
                ['status' => 404]
            );
        }
        
        // Check if this is a UCP order
        $ucp_session_id = $order->get_meta('_ucp_session_id');
        if (empty($ucp_session_id)) {
            return new WP_Error(
                'not_ucp_order',
                'This order was not created via UCP',
                ['status' => 403]
            );
        }
        
        return new WP_REST_Response($this->build_order_response($order));
    }
    
    /**
     * Register webhook endpoint
     */
    public function register_webhook(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $body = $request->get_json_params();
        
        if (empty($body['webhook_url'])) {
            return new WP_Error(
                'missing_webhook_url',
                'webhook_url is required',
                ['status' => 400]
            );
        }
        
        // Validate URL
        $webhook_url = esc_url_raw($body['webhook_url']);
        if (!wp_http_validate_url($webhook_url)) {
            return new WP_Error(
                'invalid_webhook_url',
                'Invalid webhook URL',
                ['status' => 400]
            );
        }
        
        // Store webhook URL (associated with platform profile if available)
        $agent_header = $request->get_header('UCP-Agent');
        $platform_id = 'default';
        
        if ($agent_header && preg_match('/profile="([^"]+)"/', $agent_header, $matches)) {
            $platform_id = md5($matches[1]);
        }
        
        $webhooks = get_option('wc_jq_ucp_webhooks', []);
        $webhooks[$platform_id] = [
            'url' => $webhook_url,
            'events' => $body['events'] ?? ['order.*'],
            'registered_at' => current_time('mysql'),
        ];
        update_option('wc_jq_ucp_webhooks', $webhooks);
        
        return new WP_REST_Response([
            'success' => true,
            'webhook_id' => $platform_id,
            'events' => $webhooks[$platform_id]['events'],
        ], 201);
    }
    
    /**
     * Handle order status change
     */
    public function on_order_status_changed(int $order_id, string $from, string $to, WC_Order $order): void {
        // Check if UCP order
        $session_id = $order->get_meta('_ucp_session_id');
        if (empty($session_id)) {
            return;
        }
        
        $event_type = match($to) {
            'processing' => 'order.confirmed',
            'on-hold' => 'order.on_hold',
            'completed' => 'order.delivered',
            'cancelled' => 'order.cancelled',
            'refunded' => 'order.refunded',
            'failed' => 'order.failed',
            default => 'order.status_changed',
        };
        
        $this->send_webhook($order, $event_type);
    }
    
    /**
     * Handle order refund
     */
    public function on_order_refunded(int $order_id, int $refund_id): void {
        $order = wc_get_order($order_id);
        if (!$order) return;
        
        $session_id = $order->get_meta('_ucp_session_id');
        if (empty($session_id)) return;
        
        $refund = wc_get_order($refund_id);
        
        $this->send_webhook($order, 'order.refunded', [
            'refund' => [
                'id' => 'refund_' . $refund_id,
                'amount' => (int) round(abs($refund->get_total()) * 100),
                'reason' => $refund->get_reason(),
                'created_at' => $refund->get_date_created()?->format('c'),
            ],
        ]);
    }
    
    /**
     * Handle shipment tracking added (if using shipment tracking plugin)
     */
    public function on_tracking_added(int $order_id, string $tracking_number, string $tracking_url): void {
        $order = wc_get_order($order_id);
        if (!$order) return;
        
        $session_id = $order->get_meta('_ucp_session_id');
        if (empty($session_id)) return;
        
        $this->send_webhook($order, 'order.shipped', [
            'tracking' => [
                'tracking_number' => $tracking_number,
                'tracking_url' => $tracking_url,
            ],
        ]);
    }
    
    /**
     * Send webhook to registered endpoints
     */
    private function send_webhook(WC_Order $order, string $event_type, array $extra_data = []): void {
        $webhooks = get_option('wc_jq_ucp_webhooks', []);
        
        if (empty($webhooks)) {
            return;
        }
        
        // Build payload
        $payload = array_merge([
            'event' => $event_type,
            'occurred_at' => gmdate('c'),
            'order' => $this->build_order_response($order),
        ], $extra_data);
        
        // Sign payload
        $signature = WC_JQ_UCP_Crypto::sign(wp_json_encode($payload));
        
        foreach ($webhooks as $platform_id => $webhook) {
            // Check if event matches
            $events = $webhook['events'] ?? ['order.*'];
            $should_send = false;
            
            foreach ($events as $pattern) {
                if ($pattern === '*' || $pattern === 'order.*' || $pattern === $event_type) {
                    $should_send = true;
                    break;
                }
            }
            
            if (!$should_send) {
                continue;
            }
            
            // Send webhook
            $response = wp_remote_post($webhook['url'], [
                'timeout' => 30,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-UCP-Event' => $event_type,
                    'X-UCP-Signature' => $signature ?? '',
                    'X-UCP-Key-ID' => WC_JQ_UCP_Settings::get_key_id(),
                ],
                'body' => wp_json_encode($payload),
            ]);
            
            // Log if debug enabled
            if (WC_JQ_UCP_Settings::is_debug()) {
                if (is_wp_error($response)) {
                    error_log("WC UCP Webhook Error ({$webhook['url']}): " . $response->get_error_message());
                } else {
                    $code = wp_remote_retrieve_response_code($response);
                    if ($code >= 400) {
                        error_log("WC UCP Webhook Failed ({$webhook['url']}): HTTP {$code}");
                    }
                }
            }
        }
    }
    
    /**
     * Build order response
     */
    private function build_order_response(WC_Order $order): array {
        $session_id = $order->get_meta('_ucp_session_id');
        
        $response = [
            'ucp' => [
                'version' => WC_JQ_UCP_PROTOCOL_VERSION,
                'capabilities' => [
                    ['name' => 'dev.ucp.shopping.order', 'version' => WC_JQ_UCP_PROTOCOL_VERSION],
                ],
            ],
            'id' => 'order_' . $order->get_id(),
            'checkout_id' => $session_id,
            'order_number' => $order->get_order_number(),
            'status' => $this->map_order_status($order->get_status()),
            'permalink_url' => $order->get_view_order_url(),
            'created_at' => $order->get_date_created()?->format('c'),
            'updated_at' => $order->get_date_modified()?->format('c'),
        ];
        
        // Add line items
        $response['line_items'] = [];
        foreach ($order->get_items() as $item_id => $item) {
            $product = $item->get_product();
            $response['line_items'][] = [
                'id' => 'li_' . $item_id,
                'item' => [
                    'id' => (string) ($product ? $product->get_id() : 0),
                    'title' => $item->get_name(),
                    'price' => (int) round(($item->get_total() / $item->get_quantity()) * 100),
                ],
                'quantity' => $item->get_quantity(),
            ];
        }
        
        // Add totals
        $response['totals'] = [
            ['type' => 'subtotal', 'amount' => (int) round($order->get_subtotal() * 100)],
            ['type' => 'shipping', 'amount' => (int) round($order->get_shipping_total() * 100)],
            ['type' => 'tax', 'amount' => (int) round($order->get_total_tax() * 100)],
            ['type' => 'discount', 'amount' => (int) round($order->get_discount_total() * 100)],
            ['type' => 'total', 'amount' => (int) round($order->get_total() * 100)],
        ];
        
        // Add fulfillment info
        $response['fulfillment'] = [
            'expectations' => [],
            'events' => [],
        ];
        
        // Add shipping expectation
        if ($order->has_shipping_address()) {
            $response['fulfillment']['expectations'][] = [
                'id' => 'exp_shipping',
                'method_type' => 'shipping',
                'destination' => [
                    'full_name' => $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name(),
                    'street_address' => $order->get_shipping_address_1(),
                    'address_locality' => $order->get_shipping_city(),
                    'address_region' => $order->get_shipping_state(),
                    'postal_code' => $order->get_shipping_postcode(),
                    'address_country' => $order->get_shipping_country(),
                ],
            ];
        }
        
        // Add refunds as adjustments
        $refunds = $order->get_refunds();
        if (!empty($refunds)) {
            $response['adjustments'] = [];
            foreach ($refunds as $refund) {
                $response['adjustments'][] = [
                    'id' => 'adj_' . $refund->get_id(),
                    'type' => 'refund',
                    'occurred_at' => $refund->get_date_created()?->format('c'),
                    'status' => 'completed',
                    'amount' => (int) round(abs($refund->get_total()) * 100),
                    'description' => $refund->get_reason() ?: 'Refund',
                ];
            }
        }
        
        return $response;
    }
    
    /**
     * Map WooCommerce status to UCP status
     */
    private function map_order_status(string $status): string {
        return match($status) {
            'pending' => 'pending_payment',
            'processing' => 'confirmed',
            'on-hold' => 'on_hold',
            'completed' => 'delivered',
            'cancelled' => 'cancelled',
            'refunded' => 'refunded',
            'failed' => 'failed',
            default => $status,
        };
    }
}
