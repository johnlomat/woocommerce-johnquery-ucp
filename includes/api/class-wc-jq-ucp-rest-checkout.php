<?php
/**
 * WooCommerce JohnQuery UCP REST API - Checkout
 *
 * @package WooCommerce_JQ_UCP
 */

defined('ABSPATH') || exit;

/**
 * WC_JQ_UCP_REST_Checkout Class
 */
class WC_JQ_UCP_REST_Checkout {
    
    /**
     * Namespace
     */
    protected string $namespace = 'wc-jq-ucp/v1';
    
    /**
     * Register routes
     */
    public function register_routes(): void {
        // Create checkout session
        register_rest_route($this->namespace, '/checkout-sessions', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'create_session'],
            'permission_callback' => [$this, 'check_permissions'],
        ]);
        
        // Get checkout session
        register_rest_route($this->namespace, '/checkout-sessions/(?P<id>[a-zA-Z0-9_-]+)', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_session'],
            'permission_callback' => [$this, 'check_permissions'],
        ]);
        
        // Update checkout session
        register_rest_route($this->namespace, '/checkout-sessions/(?P<id>[a-zA-Z0-9_-]+)', [
            'methods' => 'PUT',
            'callback' => [$this, 'update_session'],
            'permission_callback' => [$this, 'check_permissions'],
        ]);
        
        // Complete checkout session
        register_rest_route($this->namespace, '/checkout-sessions/(?P<id>[a-zA-Z0-9_-]+)/complete', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'complete_session'],
            'permission_callback' => [$this, 'check_permissions'],
        ]);
        
        // Cancel checkout session
        register_rest_route($this->namespace, '/checkout-sessions/(?P<id>[a-zA-Z0-9_-]+)/cancel', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'cancel_session'],
            'permission_callback' => [$this, 'check_permissions'],
        ]);
    }
    
    /**
     * Check permissions
     */
    public function check_permissions(WP_REST_Request $request): bool|WP_Error {
        // Check if UCP is enabled
        if (!WC_JQ_UCP_Settings::is_enabled()) {
            return new WP_Error(
                'ucp_disabled',
                'UCP is currently disabled',
                ['status' => 503]
            );
        }
        
        // Check agent whitelist
        if (WC_JQ_UCP_Settings::is_whitelist_enabled()) {
            $agent_header = $request->get_header('UCP-Agent');
            if (!$this->is_agent_whitelisted($agent_header)) {
                return new WP_Error(
                    'agent_not_whitelisted',
                    'Agent is not authorized',
                    ['status' => 403]
                );
            }
        }
        
        // Check signature if required
        if (WC_JQ_UCP_Settings::requires_signature()) {
            if (!WC_JQ_UCP_Crypto::validate_agent_signature($request)) {
                return new WP_Error(
                    'invalid_signature',
                    'Request signature is invalid or missing',
                    ['status' => 401]
                );
            }
        }
        
        return true;
    }
    
    /**
     * Check if agent is whitelisted
     */
    private function is_agent_whitelisted(?string $agent_header): bool {
        if (empty($agent_header)) {
            return false;
        }
        
        $whitelist = WC_JQ_UCP_Settings::get_whitelist();
        if (empty($whitelist)) {
            // Default allowed agents if whitelist is empty
            $whitelist = [
                'api.openai.com',
                'google.com',
                '*.google.com',
                'anthropic.com',
            ];
        }
        
        // Extract profile URL from header
        if (preg_match('/profile="([^"]+)"/', $agent_header, $matches)) {
            $profile_url = $matches[1];
            $host = wp_parse_url($profile_url, PHP_URL_HOST);
            
            foreach ($whitelist as $pattern) {
                $pattern = trim($pattern);
                if (empty($pattern)) continue;
                
                // Handle wildcards
                if (str_starts_with($pattern, '*.')) {
                    $domain = substr($pattern, 2);
                    if (str_ends_with($host, $domain) || $host === $domain) {
                        return true;
                    }
                } elseif ($host === $pattern) {
                    return true;
                }
            }
        }
        
        return false;
    }
    
    /**
     * Create checkout session
     */
    public function create_session(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $body = $request->get_json_params();
        
        // Validate required fields
        if (empty($body['line_items'])) {
            return new WP_Error(
                'missing_line_items',
                'line_items is required',
                ['status' => 400]
            );
        }
        
        // Create session
        $session = new WC_JQ_UCP_Session();
        
        // Set platform profile
        $agent_header = $request->get_header('UCP-Agent');
        if ($agent_header && preg_match('/profile="([^"]+)"/', $agent_header, $matches)) {
            $session->set_platform_profile($matches[1]);
        }
        
        // Set currency
        if (!empty($body['currency'])) {
            $session->set_currency($body['currency']);
        }
        
        // Process line items
        $checkout = new WC_JQ_UCP_Checkout($session);
        $line_items = $checkout->process_line_items($body['line_items']);
        
        if (empty($line_items) && $checkout->has_errors()) {
            return $this->error_response($checkout->get_messages());
        }
        
        $session->set_line_items($line_items);
        
        // Set buyer if provided
        if (!empty($body['buyer'])) {
            $session->set_buyer($body['buyer']);
        }
        
        // Set fulfillment if provided
        if (!empty($body['fulfillment'])) {
            $session->set_fulfillment($body['fulfillment']);
        }
        
        // Calculate totals
        $totals = $checkout->calculate_totals();
        $session->set_totals($totals);
        
        // Determine status
        $status = $checkout->determine_status();
        $session->set_status($status);
        
        // Save session
        if (!$session->save()) {
            return new WP_Error(
                'session_save_failed',
                'Failed to create checkout session',
                ['status' => 500]
            );
        }
        
        // Build response
        $response_data = $this->build_session_response($session, $checkout);
        
        $response = new WP_REST_Response($response_data, 201);
        $response->set_headers([
            'Location' => rest_url($this->namespace . '/checkout-sessions/' . $session->get_id()),
        ]);
        
        return $response;
    }
    
    /**
     * Get checkout session
     */
    public function get_session(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $session_id = $request->get_param('id');
        $session = new WC_JQ_UCP_Session($session_id);
        
        if (!$session->exists()) {
            return new WP_Error(
                'session_not_found',
                'Checkout session not found',
                ['status' => 404]
            );
        }
        
        if ($session->is_expired()) {
            $session->set_status(WC_JQ_UCP_Session::STATUS_EXPIRED);
            $session->save();
            
            return new WP_Error(
                'session_expired',
                'Checkout session has expired',
                ['status' => 410]
            );
        }
        
        $checkout = new WC_JQ_UCP_Checkout($session);
        $response_data = $this->build_session_response($session, $checkout);
        
        return new WP_REST_Response($response_data);
    }
    
    /**
     * Update checkout session
     */
    public function update_session(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $session_id = $request->get_param('id');
        $session = new WC_JQ_UCP_Session($session_id);
        
        if (!$session->exists()) {
            return new WP_Error(
                'session_not_found',
                'Checkout session not found',
                ['status' => 404]
            );
        }
        
        if ($session->is_expired()) {
            return new WP_Error(
                'session_expired',
                'Checkout session has expired',
                ['status' => 410]
            );
        }
        
        $current_status = $session->get_status();
        if (in_array($current_status, [WC_JQ_UCP_Session::STATUS_COMPLETE, WC_JQ_UCP_Session::STATUS_CANCELLED])) {
            return new WP_Error(
                'session_not_modifiable',
                'Checkout session cannot be modified',
                ['status' => 409]
            );
        }
        
        $body = $request->get_json_params();
        $checkout = new WC_JQ_UCP_Checkout($session);
        
        // Update line items if provided
        if (isset($body['line_items'])) {
            $line_items = $checkout->process_line_items($body['line_items']);
            $session->set_line_items($line_items);
        }
        
        // Update buyer if provided
        if (isset($body['buyer'])) {
            $session->set_buyer($body['buyer']);
        }
        
        // Update fulfillment if provided
        if (isset($body['fulfillment'])) {
            $fulfillment = $body['fulfillment'];
            
            // If shipping address changed, recalculate shipping options
            if (!empty($fulfillment['methods'])) {
                foreach ($fulfillment['methods'] as &$method) {
                    if ($method['type'] === 'shipping' && !empty($method['destinations'])) {
                        $destination = $method['destinations'][0];
                        
                        // Get new shipping options
                        $options = $checkout->get_shipping_options($destination, $session->get_line_items());
                        
                        if (!empty($options)) {
                            // Create shipping group
                            $line_item_ids = array_column($session->get_line_items(), 'id');
                            $method['groups'] = [
                                [
                                    'id' => 'shipping_group_1',
                                    'line_item_ids' => $line_item_ids,
                                    'options' => $options,
                                    'selected_option_id' => $method['groups'][0]['selected_option_id'] ?? null,
                                ],
                            ];
                        }
                    }
                }
            }
            
            $session->set_fulfillment($fulfillment);
        }
        
        // Recalculate totals
        $totals = $checkout->calculate_totals();
        $session->set_totals($totals);
        
        // Update status
        $status = $checkout->determine_status();
        $session->set_status($status);
        
        // Save
        if (!$session->save()) {
            return new WP_Error(
                'session_save_failed',
                'Failed to update checkout session',
                ['status' => 500]
            );
        }
        
        $response_data = $this->build_session_response($session, $checkout);
        
        return new WP_REST_Response($response_data);
    }
    
    /**
     * Complete checkout session
     */
    public function complete_session(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $session_id = $request->get_param('id');
        $session = new WC_JQ_UCP_Session($session_id);
        
        if (!$session->exists()) {
            return new WP_Error(
                'session_not_found',
                'Checkout session not found',
                ['status' => 404]
            );
        }
        
        if ($session->is_expired()) {
            return new WP_Error(
                'session_expired',
                'Checkout session has expired',
                ['status' => 410]
            );
        }
        
        if ($session->get_status() === WC_JQ_UCP_Session::STATUS_COMPLETE) {
            return new WP_Error(
                'session_already_complete',
                'Checkout session is already complete',
                ['status' => 409]
            );
        }
        
        $body = $request->get_json_params();
        $checkout = new WC_JQ_UCP_Checkout($session);
        
        // Validate payment data
        if (empty($body['payment_data'])) {
            return new WP_Error(
                'missing_payment_data',
                'payment_data is required',
                ['status' => 400]
            );
        }
        
        $payment_data = $body['payment_data'];
        
        // For embedded checkout, we redirect to WooCommerce checkout
        if (($payment_data['handler_id'] ?? '') === 'wc_embedded_checkout') {
            // Return escalation to embedded checkout
            $checkout_url = add_query_arg([
                'ucp_session' => $session->get_id(),
            ], wc_get_checkout_url());
            
            $session->set_status(WC_JQ_UCP_Session::STATUS_REQUIRES_ESCALATION);
            $session->save();
            
            return new WP_REST_Response([
                'ucp' => $this->get_ucp_header($session),
                'id' => $session->get_id(),
                'status' => WC_JQ_UCP_Session::STATUS_REQUIRES_ESCALATION,
                'messages' => [
                    [
                        'type' => 'info',
                        'code' => 'embedded_checkout_required',
                        'message' => 'Please complete checkout using the embedded checkout flow',
                        'severity' => 'requires_buyer_input',
                    ],
                ],
                'continue_url' => $checkout_url,
            ]);
        }
        
        // Process payment and create order
        $session->set_status(WC_JQ_UCP_Session::STATUS_PROCESSING);
        $session->save();
        
        // Create WooCommerce order
        $order = $checkout->create_order($payment_data);
        
        if (!$order) {
            $session->set_status(WC_JQ_UCP_Session::STATUS_REQUIRES_ESCALATION);
            $session->save();
            
            return $this->error_response($checkout->get_messages());
        }
        
        // Set payment method note
        $order->add_order_note(sprintf(
            'Order placed via UCP. Handler: %s',
            $payment_data['handler_id'] ?? 'unknown'
        ));
        
        // Mark order as processing/paid (simplified - in production would integrate with payment gateway)
        $order->payment_complete();
        
        // Update session
        $session->set_wc_order_id($order->get_id());
        $session->set_status(WC_JQ_UCP_Session::STATUS_COMPLETE);
        $session->save();
        
        // Build response
        $response_data = $this->build_session_response($session, $checkout);
        $response_data['order'] = $this->build_order_data($order);
        
        // Trigger webhook
        do_action('wc_jq_ucp_order_created', $order, $session);
        
        return new WP_REST_Response($response_data);
    }
    
    /**
     * Cancel checkout session
     */
    public function cancel_session(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $session_id = $request->get_param('id');
        $session = new WC_JQ_UCP_Session($session_id);
        
        if (!$session->exists()) {
            return new WP_Error(
                'session_not_found',
                'Checkout session not found',
                ['status' => 404]
            );
        }
        
        if ($session->get_status() === WC_JQ_UCP_Session::STATUS_COMPLETE) {
            return new WP_Error(
                'session_not_cancellable',
                'Completed sessions cannot be cancelled',
                ['status' => 409]
            );
        }
        
        $session->set_status(WC_JQ_UCP_Session::STATUS_CANCELLED);
        $session->save();
        
        $checkout = new WC_JQ_UCP_Checkout($session);
        $response_data = $this->build_session_response($session, $checkout);
        
        return new WP_REST_Response($response_data);
    }
    
    /**
     * Build session response
     */
    private function build_session_response(WC_JQ_UCP_Session $session, WC_JQ_UCP_Checkout $checkout): array {
        $response = [
            'ucp' => $this->get_ucp_header($session),
            'id' => $session->get_id(),
            'status' => $session->get_status(),
            'currency' => $session->get_currency(),
            'line_items' => $session->get_line_items(),
            'totals' => $session->get_totals(),
            'links' => $checkout->get_links(),
        ];
        
        // Add buyer if set
        $buyer = $session->get_buyer();
        if (!empty($buyer)) {
            $response['buyer'] = $buyer;
        }
        
        // Add fulfillment if set
        $fulfillment = $session->get_fulfillment();
        if (!empty($fulfillment)) {
            $response['fulfillment'] = $fulfillment;
        }
        
        // Add payment handlers
        $response['payment'] = [
            'handlers' => WC_JQ_UCP_Settings::get_payment_handlers(),
        ];
        
        // Add messages
        $messages = $checkout->get_messages();
        if (!empty($messages)) {
            $response['messages'] = $messages;
        }
        
        // Add order if complete
        $order_id = $session->get_wc_order_id();
        if ($order_id) {
            $order = wc_get_order($order_id);
            if ($order) {
                $response['order'] = $this->build_order_data($order);
            }
        }
        
        return $response;
    }
    
    /**
     * Build order data for response
     */
    private function build_order_data(WC_Order $order): array {
        return [
            'id' => 'order_' . $order->get_id(),
            'order_number' => $order->get_order_number(),
            'status' => $order->get_status(),
            'permalink_url' => $order->get_view_order_url(),
            'created_at' => $order->get_date_created()?->format('c'),
        ];
    }
    
    /**
     * Get UCP header for responses
     */
    private function get_ucp_header(WC_JQ_UCP_Session $session): array {
        return [
            'version' => WC_JQ_UCP_PROTOCOL_VERSION,
            'capabilities' => [
                ['name' => 'dev.ucp.shopping.checkout', 'version' => WC_JQ_UCP_PROTOCOL_VERSION],
                ['name' => 'dev.ucp.shopping.fulfillment', 'version' => WC_JQ_UCP_PROTOCOL_VERSION],
            ],
        ];
    }
    
    /**
     * Build error response
     */
    private function error_response(array $messages): WP_REST_Response {
        return new WP_REST_Response([
            'status' => 'requires_escalation',
            'messages' => $messages,
        ], 400);
    }
}
