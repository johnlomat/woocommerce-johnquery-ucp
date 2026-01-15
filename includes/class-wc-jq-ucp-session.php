<?php
/**
 * WooCommerce JohnQuery UCP Session Management
 *
 * @package WooCommerce_JQ_UCP
 */

defined('ABSPATH') || exit;

/**
 * WC_JQ_UCP_Session Class
 */
class WC_JQ_UCP_Session {
    
    /**
     * Session statuses
     */
    const STATUS_INCOMPLETE = 'incomplete';
    const STATUS_READY_FOR_COMPLETE = 'ready_for_complete';
    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETE = 'complete';
    const STATUS_REQUIRES_ESCALATION = 'requires_escalation';
    const STATUS_EXPIRED = 'expired';
    const STATUS_CANCELLED = 'cancelled';
    
    /**
     * Session ID
     */
    private string $session_id;
    
    /**
     * Database row ID
     */
    private ?int $id = null;
    
    /**
     * Session data
     */
    private array $data = [];
    
    /**
     * Constructor
     */
    public function __construct(?string $session_id = null) {
        if ($session_id) {
            $this->session_id = $session_id;
            $this->load();
        } else {
            $this->session_id = $this->generate_session_id();
            $this->data = $this->get_defaults();
        }
    }
    
    /**
     * Generate a unique session ID
     */
    private function generate_session_id(): string {
        return 'chk_' . wp_generate_uuid4();
    }
    
    /**
     * Get default session data
     */
    private function get_defaults(): array {
        return [
            'status' => self::STATUS_INCOMPLETE,
            'currency' => get_woocommerce_currency(),
            'buyer' => [],
            'line_items' => [],
            'totals' => [],
            'fulfillment' => [],
            'payment' => [],
            'messages' => [],
            'links' => [],
            'platform_profile' => null,
            'wc_order_id' => null,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ];
    }
    
    /**
     * Load session from database
     */
    private function load(): bool {
        global $wpdb;
        
        $table = $wpdb->prefix . 'wc_jq_ucp_sessions';
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM $table WHERE session_id = %s",
                $this->session_id
            ),
            ARRAY_A
        );
        
        if (!$row) {
            return false;
        }
        
        $this->id = (int) $row['id'];
        $this->data = [
            'status' => $row['status'],
            'currency' => $row['currency'],
            'buyer' => json_decode($row['buyer_data'] ?? '{}', true) ?: [],
            'line_items' => json_decode($row['line_items'] ?? '[]', true) ?: [],
            'totals' => json_decode($row['totals'] ?? '[]', true) ?: [],
            'fulfillment' => json_decode($row['fulfillment'] ?? '{}', true) ?: [],
            'payment' => json_decode($row['payment'] ?? '{}', true) ?: [],
            'platform_profile' => $row['platform_profile'],
            'wc_order_id' => $row['wc_order_id'] ? (int) $row['wc_order_id'] : null,
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
            'expires_at' => $row['expires_at'],
        ];
        
        return true;
    }
    
    /**
     * Save session to database
     */
    public function save(): bool {
        global $wpdb;
        
        $table = $wpdb->prefix . 'wc_jq_ucp_sessions';
        $now = current_time('mysql');
        $timeout = WC_JQ_UCP_Settings::get_session_timeout();
        $expires_at = gmdate('Y-m-d H:i:s', strtotime("+{$timeout} minutes"));
        
        $data = [
            'session_id' => $this->session_id,
            'status' => $this->data['status'],
            'currency' => $this->data['currency'],
            'buyer_data' => wp_json_encode($this->data['buyer']),
            'line_items' => wp_json_encode($this->data['line_items']),
            'totals' => wp_json_encode($this->data['totals']),
            'fulfillment' => wp_json_encode($this->data['fulfillment']),
            'payment' => wp_json_encode($this->data['payment']),
            'platform_profile' => $this->data['platform_profile'],
            'wc_order_id' => $this->data['wc_order_id'],
            'updated_at' => $now,
            'expires_at' => $expires_at,
        ];
        
        if ($this->id) {
            // Update
            $result = $wpdb->update($table, $data, ['id' => $this->id]);
        } else {
            // Insert
            $data['created_at'] = $now;
            $result = $wpdb->insert($table, $data);
            if ($result) {
                $this->id = $wpdb->insert_id;
            }
        }
        
        return $result !== false;
    }
    
    /**
     * Get session ID
     */
    public function get_id(): string {
        return $this->session_id;
    }
    
    /**
     * Get session status
     */
    public function get_status(): string {
        return $this->data['status'];
    }
    
    /**
     * Set session status
     */
    public function set_status(string $status): void {
        $this->data['status'] = $status;
    }
    
    /**
     * Get currency
     */
    public function get_currency(): string {
        return $this->data['currency'];
    }
    
    /**
     * Set currency
     */
    public function set_currency(string $currency): void {
        $this->data['currency'] = strtoupper($currency);
    }
    
    /**
     * Get buyer data
     */
    public function get_buyer(): array {
        return $this->data['buyer'];
    }
    
    /**
     * Set buyer data
     */
    public function set_buyer(array $buyer): void {
        $this->data['buyer'] = $buyer;
    }
    
    /**
     * Get line items
     */
    public function get_line_items(): array {
        return $this->data['line_items'];
    }
    
    /**
     * Set line items
     */
    public function set_line_items(array $items): void {
        $this->data['line_items'] = $items;
    }
    
    /**
     * Get totals
     */
    public function get_totals(): array {
        return $this->data['totals'];
    }
    
    /**
     * Set totals
     */
    public function set_totals(array $totals): void {
        $this->data['totals'] = $totals;
    }
    
    /**
     * Get fulfillment data
     */
    public function get_fulfillment(): array {
        return $this->data['fulfillment'];
    }
    
    /**
     * Set fulfillment data
     */
    public function set_fulfillment(array $fulfillment): void {
        $this->data['fulfillment'] = $fulfillment;
    }
    
    /**
     * Get payment data
     */
    public function get_payment(): array {
        return $this->data['payment'];
    }
    
    /**
     * Set payment data
     */
    public function set_payment(array $payment): void {
        $this->data['payment'] = $payment;
    }
    
    /**
     * Set platform profile URL
     */
    public function set_platform_profile(?string $profile): void {
        $this->data['platform_profile'] = $profile;
    }
    
    /**
     * Get platform profile URL
     */
    public function get_platform_profile(): ?string {
        return $this->data['platform_profile'];
    }
    
    /**
     * Set WooCommerce order ID
     */
    public function set_wc_order_id(?int $order_id): void {
        $this->data['wc_order_id'] = $order_id;
    }
    
    /**
     * Get WooCommerce order ID
     */
    public function get_wc_order_id(): ?int {
        return $this->data['wc_order_id'];
    }
    
    /**
     * Check if session exists
     */
    public function exists(): bool {
        return $this->id !== null;
    }
    
    /**
     * Check if session is expired
     */
    public function is_expired(): bool {
        if (!isset($this->data['expires_at'])) {
            return false;
        }
        return strtotime($this->data['expires_at']) < time();
    }
    
    /**
     * Delete session
     */
    public function delete(): bool {
        global $wpdb;
        
        if (!$this->id) {
            return false;
        }
        
        $table = $wpdb->prefix . 'wc_jq_ucp_sessions';
        return $wpdb->delete($table, ['id' => $this->id]) !== false;
    }
    
    /**
     * Clean up expired sessions
     */
    public static function cleanup_expired(): int {
        global $wpdb;
        
        $table = $wpdb->prefix . 'wc_jq_ucp_sessions';
        return $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM $table WHERE expires_at < %s AND status != %s",
                current_time('mysql'),
                self::STATUS_COMPLETE
            )
        );
    }
}
