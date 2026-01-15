<?php
/**
 * WooCommerce JohnQuery UCP Uninstall
 *
 * Runs when the plugin is deleted (not just deactivated).
 * Removes all plugin data including database tables, options, and files.
 *
 * @package WooCommerce_JQ_UCP
 */

// Exit if not called by WordPress
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Clean up all plugin data
 */
function wc_jq_ucp_uninstall_cleanup() {
    global $wpdb;
    
    // 1. Delete database table
    $table_name = $wpdb->prefix . 'wc_jq_ucp_sessions';
    $wpdb->query("DROP TABLE IF EXISTS $table_name");
    
    // 2. Delete all plugin options
    $options_to_delete = [
        'wc_jq_ucp_enabled',
        'wc_jq_ucp_session_timeout',
        'wc_jq_ucp_agent_whitelist_enabled',
        'wc_jq_ucp_agent_whitelist',
        'wc_jq_ucp_require_signature',
        'wc_jq_ucp_debug_mode',
        'wc_jq_ucp_private_key',
        'wc_jq_ucp_public_key',
        'wc_jq_ucp_key_id',
        'wc_jq_ucp_webhooks',
    ];
    
    foreach ($options_to_delete as $option) {
        delete_option($option);
    }
    
    // Also delete any options that might have been added dynamically
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wc_jq_ucp_%'");
    
    // 3. Delete .well-known/ucp directory (NOT the .well-known folder itself!)
    $ucp_dir = ABSPATH . '.well-known/ucp';
    
    if (is_dir($ucp_dir)) {
        // Delete all files in the directory
        $files = glob($ucp_dir . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        
        // Also delete hidden files like .htaccess
        $hidden_files = glob($ucp_dir . '/.*');
        foreach ($hidden_files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        
        // Remove the ucp directory (only succeeds if empty)
        @rmdir($ucp_dir);
    }
    
    // 4. Delete any transients
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wc_jq_ucp_%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_wc_jq_ucp_%'");
    
    // 5. Clear any scheduled hooks
    wp_clear_scheduled_hook('wc_jq_ucp_cleanup_sessions');
    
    // 6. Remove any order meta related to UCP (optional - keep for order history)
    // Uncomment the following if you want to remove UCP metadata from orders:
    // $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_ucp_%'");
}

// Run cleanup
wc_jq_ucp_uninstall_cleanup();
