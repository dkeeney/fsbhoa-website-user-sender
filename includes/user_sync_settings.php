<?php
if ( ! defined( 'WPINC' ) ) { die; }

class Fsbhoa_User_Sync_Settings {

    private $parent_slug  = 'fsbhoa_ac_main_menu';
    private $page_slug    = 'fsbhoa_ac_user_sync';
    private $option_group = 'fsbhoa_sync_options';

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_sync_submenu_page' ], 20 );
        add_action( 'admin_init', [ $this, 'register_sync_settings' ] );
        // Register the AJAX handler for the manual trigger button
        add_action( 'wp_ajax_fsbhoa_trigger_manual_sync', [ $this, 'ajax_trigger_sync' ] );
        // Add settings link on the plugin page for the Sender
        add_filter( 'plugin_action_links_' . plugin_basename( dirname( __DIR__ ) . '/fsbhoa-website-user-sender.php' ), [ $this, 'add_plugin_action_links' ] );
    }

    public function add_sync_submenu_page() {
        add_submenu_page(
            $this->parent_slug,
            'Website User Send Configuration', 
            'Website User Send',                       
            'manage_options',                  
            $this->page_slug,                  
            [ $this, 'render_sync_page' ]      
        );
    }

    public function register_sync_settings() {
        add_settings_section(
            'fsbhoa_sync_main_section',
            'Sync Configuration',
            [ $this, 'render_section_description' ],
            $this->page_slug
        );

        // 1. The Enable Checkbox
        add_settings_field(
            'fsbhoa_sync_enabled',
            'Enable Automatic Sync',
            [ $this, 'render_checkbox_field' ],
            $this->page_slug,
            'fsbhoa_sync_main_section',
            [
                'id'   => 'fsbhoa_sync_enabled',
                'desc' => 'Check to allow the daily automated push.'
            ]
        );
        register_setting( $this->option_group, 'fsbhoa_sync_enabled', 'sanitize_text_field' );

        // 2. The Endpoint URL
        add_settings_field(
            'fsbhoa_sync_endpoint',
            'Remote Endpoint URL',
            [ $this, 'render_text_field' ],
            $this->page_slug,
            'fsbhoa_sync_main_section',
            [
                'id'   => 'fsbhoa_sync_endpoint',
                'desc' => 'The target URL where user data will be synchronized. Example: https://example.com/wp-json/fsbhoa/v1/ac-web-sync',
                'placeholder' => 'Receivers URL'
            ]
        );
        register_setting( $this->option_group, 'fsbhoa_sync_endpoint', 'sanitize_text_field' );

        // 3. The API Key
        add_settings_field(
            'fsbhoa_sync_api_key',
            'API Key',
            [ $this, 'render_text_field' ],
            $this->page_slug,
            'fsbhoa_sync_main_section',
            [
                'id'   => 'fsbhoa_sync_api_key',
                'desc' => 'The secure key used to authenticate with the remote website.',
                'placeholder' => 'Paste the 32-byte generated key from the Receiver'
            ]
        );
        register_setting( $this->option_group, 'fsbhoa_sync_api_key', 'sanitize_text_field' );
    }

    public function render_section_description() {
        echo '<p>Configure the parameters for syncing HOA access control users with the website database.</p>';
    }

    public function render_checkbox_field( $args ) {
        $id    = $args['id'];
        $desc  = $args['desc'] ?? '';
        $value = get_option( $id, '0' ); // Default to 0 (unchecked)
        
        echo "<input type='checkbox' name='" . esc_attr($id) . "' id='" . esc_attr($id) . "' value='1' " . checked( '1', $value, false ) . " />";
        if ( $desc ) {
            echo "<p class='description'>" . esc_html($desc) . "</p>";
        }
    }

    public function render_text_field( $args ) {
        $id    = $args['id'];
        $desc  = $args['desc'] ?? '';
        $value = get_option( $id, '' );
        $placeholder = $args['placeholder'] ?? '';

        echo "<input type='text' name='" . esc_attr($id) . "' id='" . esc_attr($id) . "' value='" . esc_attr($value) . "' placeholder='" . esc_attr($placeholder) . "' class='regular-text' />";
        if ( $desc ) {
            echo "<p class='description'>" . esc_html($desc) . "</p>";
        }
    }

    public function render_sync_page() {
        ?>
        <div class="wrap">
            <h1>Website User Sync</h1>
            <?php settings_errors(); ?>
            
            <form method="post" action="options.php">
                <?php
                settings_fields( $this->option_group );
                do_settings_sections( $this->page_slug );
                submit_button( 'Save Sync Configuration' );
                ?>
            </form>

            <hr style="margin: 2em 0;">

            <!-- Manual Trigger UI -->
            <div style="background: #fff; padding: 15px; border: 1px solid #ccd0d4; margin-bottom: 20px;">
                <h2>Manual Sync</h2>
                <p>Push the current list of active residents to the website immediately.</p>
                <button type="button" id="btn-trigger-manual-sync" class="button button-secondary">Run Sync Now</button>
                <span id="manual-sync-feedback" style="margin-left: 10px; font-weight: bold;"></span>
            </div>
        </div>

        <!-- Inline script to handle the AJAX push -->
        <script>
        jQuery(document).ready(function($) {
            $('#btn-trigger-manual-sync').on('click', function() {
                var btn = $(this);
                var feedback = $('#manual-sync-feedback');

                btn.prop('disabled', true);
                feedback.text('Gathering users and contacting website...').css('color', '#000');

                $.post(ajaxurl, {
                    action: 'fsbhoa_trigger_manual_sync',
                    nonce: '<?php echo wp_create_nonce("fsbhoa_manual_sync_nonce"); ?>'
                }, function(response) {
                    btn.prop('disabled', false);
                    if (response.success) {
                        feedback.text('Success: ' + response.data).css('color', 'green');
                    } else {
                        feedback.text('Error: ' + response.data).css('color', 'red');
                    }
                    setTimeout(function() { feedback.text(''); }, 8000);
                }).fail(function() {
                    btn.prop('disabled', false);
                    feedback.text('AJAX request failed. Check console.').css('color', 'red');
                    setTimeout(function() { feedback.text(''); }, 5000);
                });
            });
        });
        </script>
        <?php
    }

    public function ajax_trigger_sync() {
        check_ajax_referer( 'fsbhoa_manual_sync_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        // Call the core logic directly, bypassing the "enabled" check 
        // because the user explicitly pressed the manual button.
        $result = fsbhoa_execute_core_web_sync();

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( $result );
    }

    public function add_plugin_action_links( $links ) {
        // Points to the submenu slug we defined for the Sender
        $settings_link = '<a href="admin.php?page=' . $this->page_slug . '">Settings</a>';
        array_unshift( $links, $settings_link );
        return $links;
    }
}

new Fsbhoa_User_Sync_Settings();

