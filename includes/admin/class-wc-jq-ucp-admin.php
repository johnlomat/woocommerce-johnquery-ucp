<?php
/**
 * WooCommerce JohnQuery UCP Admin
 *
 * @package WooCommerce_JQ_UCP
 */

defined('ABSPATH') || exit;

/**
 * WC_JQ_UCP_Admin Class
 */
class WC_JQ_UCP_Admin {
    
    /**
     * Constructor
     */
    public function __construct() {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
    }
    
    /**
     * Add admin menu
     */
    public function add_menu(): void {
        add_submenu_page(
            'woocommerce',
            __('UCP Settings', 'woocommerce-johnquery-ucp'),
            __('UCP (AI Commerce)', 'woocommerce-johnquery-ucp'),
            'manage_woocommerce',
            'woocommerce-johnquery-ucp-settings',
            [$this, 'render_settings_page']
        );
    }
    
    /**
     * Register settings
     */
    public function register_settings(): void {
        // General section
        add_settings_section(
            'wc_jq_ucp_general',
            __('General Settings', 'woocommerce-johnquery-ucp'),
            [$this, 'render_general_section'],
            'woocommerce-johnquery-ucp-settings'
        );
        
        // Enable/disable
        register_setting('wc_jq_ucp_settings', 'wc_jq_ucp_enabled');
        add_settings_field(
            'wc_jq_ucp_enabled',
            __('Enable UCP', 'woocommerce-johnquery-ucp'),
            [$this, 'render_checkbox_field'],
            'woocommerce-johnquery-ucp-settings',
            'wc_jq_ucp_general',
            [
                'id' => 'wc_jq_ucp_enabled',
                'description' => __('Enable AI agents to make purchases from your store', 'woocommerce-johnquery-ucp'),
            ]
        );
        
        // Session timeout
        register_setting('wc_jq_ucp_settings', 'wc_jq_ucp_session_timeout');
        add_settings_field(
            'wc_jq_ucp_session_timeout',
            __('Session Timeout', 'woocommerce-johnquery-ucp'),
            [$this, 'render_number_field'],
            'woocommerce-johnquery-ucp-settings',
            'wc_jq_ucp_general',
            [
                'id' => 'wc_jq_ucp_session_timeout',
                'description' => __('How long checkout sessions remain valid (minutes)', 'woocommerce-johnquery-ucp'),
                'min' => 5,
                'max' => 120,
                'default' => 30,
            ]
        );
        
        // Security section
        add_settings_section(
            'wc_jq_ucp_security',
            __('Security Settings', 'woocommerce-johnquery-ucp'),
            [$this, 'render_security_section'],
            'woocommerce-johnquery-ucp-settings'
        );
        
        // Agent whitelist
        register_setting('wc_jq_ucp_settings', 'wc_jq_ucp_agent_whitelist_enabled');
        add_settings_field(
            'wc_jq_ucp_agent_whitelist_enabled',
            __('Enable Agent Whitelist', 'woocommerce-johnquery-ucp'),
            [$this, 'render_checkbox_field'],
            'woocommerce-johnquery-ucp-settings',
            'wc_jq_ucp_security',
            [
                'id' => 'wc_jq_ucp_agent_whitelist_enabled',
                'description' => __('Only allow whitelisted AI agents to use UCP', 'woocommerce-johnquery-ucp'),
            ]
        );
        
        // Whitelist domains
        register_setting('wc_jq_ucp_settings', 'wc_jq_ucp_agent_whitelist');
        add_settings_field(
            'wc_jq_ucp_agent_whitelist',
            __('Whitelisted Domains', 'woocommerce-johnquery-ucp'),
            [$this, 'render_textarea_field'],
            'woocommerce-johnquery-ucp-settings',
            'wc_jq_ucp_security',
            [
                'id' => 'wc_jq_ucp_agent_whitelist',
                'description' => __('One domain per line. Supports wildcards (e.g., *.google.com)', 'woocommerce-johnquery-ucp'),
                'placeholder' => "api.openai.com\n*.google.com\nanthropic.com",
            ]
        );
        
        // Require signature
        register_setting('wc_jq_ucp_settings', 'wc_jq_ucp_require_signature');
        add_settings_field(
            'wc_jq_ucp_require_signature',
            __('Require Request Signature', 'woocommerce-johnquery-ucp'),
            [$this, 'render_checkbox_field'],
            'woocommerce-johnquery-ucp-settings',
            'wc_jq_ucp_security',
            [
                'id' => 'wc_jq_ucp_require_signature',
                'description' => __('Require cryptographic signatures on all requests', 'woocommerce-johnquery-ucp'),
            ]
        );
        
        // Debug mode
        register_setting('wc_jq_ucp_settings', 'wc_jq_ucp_debug_mode');
        add_settings_field(
            'wc_jq_ucp_debug_mode',
            __('Debug Mode', 'woocommerce-johnquery-ucp'),
            [$this, 'render_checkbox_field'],
            'woocommerce-johnquery-ucp-settings',
            'wc_jq_ucp_security',
            [
                'id' => 'wc_jq_ucp_debug_mode',
                'description' => __('Enable debug logging', 'woocommerce-johnquery-ucp'),
            ]
        );
    }
    
    /**
     * Enqueue admin scripts
     */
    public function enqueue_scripts(string $hook): void {
        if ($hook !== 'woocommerce_page_woocommerce-johnquery-ucp-settings') {
            return;
        }
        
        wp_enqueue_style(
            'wc-jq-ucp-admin',
            WC_JQ_UCP_PLUGIN_URL . 'assets/css/admin.css',
            [],
            WC_JQ_UCP_VERSION
        );
    }
    
    /**
     * Render settings page
     */
    public function render_settings_page(): void {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <div class="wc-jq-ucp-info-box">
                <h3><?php esc_html_e('Your UCP Discovery Endpoint', 'woocommerce-johnquery-ucp'); ?></h3>
                <p><?php esc_html_e('AI agents will discover your store capabilities at:', 'woocommerce-johnquery-ucp'); ?></p>
                <code><?php echo esc_url(home_url('/.well-known/ucp')); ?></code>
                
                <h4><?php esc_html_e('REST API Endpoint', 'woocommerce-johnquery-ucp'); ?></h4>
                <code><?php echo esc_url(rest_url('wc-jq-ucp/v1')); ?></code>
            </div>
            
            <form action="options.php" method="post">
                <?php
                settings_fields('wc_jq_ucp_settings');
                do_settings_sections('woocommerce-johnquery-ucp-settings');
                submit_button(__('Save Settings', 'woocommerce-johnquery-ucp'));
                ?>
            </form>
            
            <div class="wc-jq-ucp-keys-section">
                <h2><?php esc_html_e('Signing Keys', 'woocommerce-johnquery-ucp'); ?></h2>
                <p><?php esc_html_e('These keys are used to sign webhooks and verify request signatures.', 'woocommerce-johnquery-ucp'); ?></p>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Key ID', 'woocommerce-johnquery-ucp'); ?></th>
                        <td><code><?php echo esc_html(WC_JQ_UCP_Settings::get_key_id()); ?></code></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Public Key (JWK)', 'woocommerce-johnquery-ucp'); ?></th>
                        <td>
                            <textarea readonly rows="8" class="large-text code"><?php 
                                echo esc_textarea(wp_json_encode(WC_JQ_UCP_Settings::get_public_key_jwk(), JSON_PRETTY_PRINT)); 
                            ?></textarea>
                        </td>
                    </tr>
                </table>
                
                <p>
                    <button type="button" class="button" id="wc-jq-ucp-regenerate-keys">
                        <?php esc_html_e('Regenerate Keys', 'woocommerce-johnquery-ucp'); ?>
                    </button>
                    <span class="description">
                        <?php esc_html_e('Warning: This will invalidate existing webhook signatures.', 'woocommerce-johnquery-ucp'); ?>
                    </span>
                </p>
            </div>
            
            <div class="wc-jq-ucp-test-section">
                <h2><?php esc_html_e('Test Your Integration', 'woocommerce-johnquery-ucp'); ?></h2>
                
                <?php 
                $well_known_file = ABSPATH . '.well-known/ucp/index.php';
                $well_known_exists = file_exists($well_known_file);
                ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Discovery Endpoint Status', 'woocommerce-johnquery-ucp'); ?></th>
                        <td>
                            <?php if ($well_known_exists): ?>
                                <span class="wc-jq-ucp-status wc-jq-ucp-status-enabled">✓ <?php esc_html_e('File exists', 'woocommerce-johnquery-ucp'); ?></span>
                            <?php else: ?>
                                <span class="wc-jq-ucp-status wc-jq-ucp-status-disabled">✗ <?php esc_html_e('File missing', 'woocommerce-johnquery-ucp'); ?></span>
                                <button type="button" class="button" id="wc-jq-ucp-create-wellknown" style="margin-left: 10px;">
                                    <?php esc_html_e('Create Endpoint File', 'woocommerce-johnquery-ucp'); ?>
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
                
                <p>
                    <a href="<?php echo esc_url(home_url('/.well-known/ucp')); ?>" target="_blank" class="button">
                        <?php esc_html_e('View Discovery Profile', 'woocommerce-johnquery-ucp'); ?>
                    </a>
                    <a href="<?php echo esc_url(rest_url('wc-jq-ucp/v1/profile')); ?>" target="_blank" class="button">
                        <?php esc_html_e('View REST API Profile', 'woocommerce-johnquery-ucp'); ?>
                    </a>
                </p>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('#wc-jq-ucp-regenerate-keys').on('click', function() {
                if (confirm('<?php esc_attr_e('Are you sure? This will invalidate existing webhook signatures.', 'woocommerce-johnquery-ucp'); ?>')) {
                    $.post(ajaxurl, {
                        action: 'wc_jq_ucp_regenerate_keys',
                        _wpnonce: '<?php echo wp_create_nonce('wc_jq_ucp_regenerate_keys'); ?>'
                    }, function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert('Failed to regenerate keys');
                        }
                    });
                }
            });
            
            $('#wc-jq-ucp-create-wellknown').on('click', function() {
                var $btn = $(this);
                $btn.prop('disabled', true).text('<?php esc_attr_e('Creating...', 'woocommerce-johnquery-ucp'); ?>');
                
                $.post(ajaxurl, {
                    action: 'wc_jq_ucp_create_wellknown',
                    _wpnonce: '<?php echo wp_create_nonce('wc_jq_ucp_create_wellknown'); ?>'
                }, function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert('<?php esc_attr_e('Failed to create endpoint file. Please check folder permissions or create it manually.', 'woocommerce-johnquery-ucp'); ?>');
                        $btn.prop('disabled', false).text('<?php esc_attr_e('Create Endpoint File', 'woocommerce-johnquery-ucp'); ?>');
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * Render general section description
     */
    public function render_general_section(): void {
        echo '<p>' . esc_html__('Configure how AI agents interact with your store.', 'woocommerce-johnquery-ucp') . '</p>';
    }
    
    /**
     * Render security section description
     */
    public function render_security_section(): void {
        echo '<p>' . esc_html__('Control which AI agents can access your store and how requests are authenticated.', 'woocommerce-johnquery-ucp') . '</p>';
    }
    
    /**
     * Render checkbox field
     */
    public function render_checkbox_field(array $args): void {
        $id = $args['id'];
        $value = get_option($id, 'no');
        ?>
        <label>
            <input type="checkbox" id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($id); ?>" value="yes" <?php checked($value, 'yes'); ?>>
            <?php echo esc_html($args['description'] ?? ''); ?>
        </label>
        <?php
    }
    
    /**
     * Render number field
     */
    public function render_number_field(array $args): void {
        $id = $args['id'];
        $value = get_option($id, $args['default'] ?? '');
        ?>
        <input type="number" id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($id); ?>" 
               value="<?php echo esc_attr($value); ?>"
               min="<?php echo esc_attr($args['min'] ?? ''); ?>"
               max="<?php echo esc_attr($args['max'] ?? ''); ?>"
               class="small-text">
        <p class="description"><?php echo esc_html($args['description'] ?? ''); ?></p>
        <?php
    }
    
    /**
     * Render textarea field
     */
    public function render_textarea_field(array $args): void {
        $id = $args['id'];
        $value = get_option($id, '');
        ?>
        <textarea id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($id); ?>" 
                  rows="5" class="large-text code"
                  placeholder="<?php echo esc_attr($args['placeholder'] ?? ''); ?>"><?php echo esc_textarea($value); ?></textarea>
        <p class="description"><?php echo esc_html($args['description'] ?? ''); ?></p>
        <?php
    }
}

// Initialize admin
new WC_JQ_UCP_Admin();

// AJAX handler for regenerating keys
add_action('wp_ajax_wc_jq_ucp_regenerate_keys', function() {
    check_ajax_referer('wc_jq_ucp_regenerate_keys');
    
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error();
    }
    
    // Delete existing keys
    delete_option('wc_jq_ucp_private_key');
    delete_option('wc_jq_ucp_public_key');
    delete_option('wc_jq_ucp_key_id');
    
    // Regenerate
    WC_JQ_UCP_Install::activate();
    
    wp_send_json_success();
});

// AJAX handler for creating well-known endpoint
add_action('wp_ajax_wc_jq_ucp_create_wellknown', function() {
    check_ajax_referer('wc_jq_ucp_create_wellknown');
    
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => 'Permission denied']);
    }
    
    try {
        WC_JQ_UCP_Install::create_well_known_endpoint();
        
        // Check if file was created
        $file = ABSPATH . '.well-known/ucp/index.php';
        if (file_exists($file)) {
            wp_send_json_success();
        } else {
            wp_send_json_error(['message' => 'File creation failed']);
        }
    } catch (Exception $e) {
        wp_send_json_error(['message' => $e->getMessage()]);
    }
});
