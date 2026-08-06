<?php
/**
 * Plugin Name:       FSBHOA Website User Sender
 * Plugin URI:        https://github.com/fsbhoa/
 * Description:       Securely synchronizes active resident email addresses from the local HOA access control database to the public website.
 * Version:           1.0.0
 * Author:            FSBHOA
 * Text Domain:       fsbhoa-web-sync-sender
 * License:           Private/Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// I changed this constant slightly to match the new Sender name so it's clean
if ( ! defined( 'FSBHOA_SENDER_DIR' ) ) {
    define( 'FSBHOA_SENDER_DIR', plugin_dir_path( __FILE__ ) );
}

if ( is_admin() ) {
    require_once FSBHOA_SENDER_DIR . 'includes/user_sync_settings.php';
}

// ========================================================================
// 1. CRON SCHEDULING (Activation / Deactivation)
// ========================================================================

register_activation_hook( __FILE__, 'fsbhoa_sender_activate' );
function fsbhoa_sender_activate() {
    if ( ! wp_next_scheduled( 'fsbhoa_daily_web_sync_event' ) ) {
        wp_schedule_event( time(), 'daily', 'fsbhoa_daily_web_sync_event' );
    }
}

register_deactivation_hook( __FILE__, 'fsbhoa_sender_deactivate' );
function fsbhoa_sender_deactivate() {
    wp_clear_scheduled_hook( 'fsbhoa_daily_web_sync_event' );
}

// ========================================================================
// 2. BACKGROUND CRON EXECUTION
// ========================================================================

add_action( 'fsbhoa_daily_web_sync_event', 'fsbhoa_cron_web_sync' );
add_action( 'fsbhoa_instant_web_sync_event', 'fsbhoa_cron_web_sync' );
function fsbhoa_cron_web_sync() {
    $is_enabled = get_option( 'fsbhoa_sync_enabled', '0' );
    if ( $is_enabled !== '1' ) {
        return; 
    }

    $result = fsbhoa_execute_core_web_sync();
    if ( is_wp_error( $result ) ) {
        error_log( 'FSBHOA WEB SYNC CRON ERROR: ' . $result->get_error_message() );
    }
}

// ========================================================================
// 3. CORE SYNC LOGIC (Called by Cron AND Manual AJAX Button)
// ========================================================================

function fsbhoa_execute_core_web_sync() {
    global $wpdb;

    $api_url = get_option( 'fsbhoa_sync_endpoint', '' );
    $api_key = get_option( 'fsbhoa_sync_api_key', '' );

    if ( empty( $api_url ) || empty( $api_key ) ) {
        return new WP_Error( 'missing_config', 'Sync Endpoint URL and API Key must be configured first.' );
    }

    // Includes the GROUP BY email to prevent duplicate updates
    $query = "
        SELECT MIN(id) as ac_id, email, first_name, last_name
        FROM ac_cardholders
        WHERE email IS NOT NULL
        AND email != ''
        AND card_status IN ('active', 'inactive')
        AND resident_type NOT IN ('Staff', 'Contractor')
        AND deleted_at IS NULL
        GROUP BY email
    ";

    $results = $wpdb->get_results( $query, ARRAY_A );

    if ( empty( $results ) ) {
        return new WP_Error( 'no_users', 'No valid users found to sync.' );
    }

    $args = array(
        'body'    => wp_json_encode( $results ),
        'timeout' => 300,
        'headers' => array(
            'Content-Type'     => 'application/json',
            'X-FSBHOA-API-KEY' => $api_key,
        ),
    );

    $response = wp_remote_post( $api_url, $args );

    if ( is_wp_error( $response ) ) {
        return new WP_Error( 'http_error', 'Connection failed: ' . $response->get_error_message() );
    }

    $status_code = wp_remote_retrieve_response_code( $response );
    $body_raw    = wp_remote_retrieve_body( $response );
    $body        = json_decode( $body_raw, true );

    if ( $status_code !== 200 ) {
        $err_msg = isset( $body['message'] ) ? $body['message'] : 'Unexpected HTTP response code ' . $status_code;
        return new WP_Error( 'remote_error', $err_msg );
    }

    $summary     = $body['summary'] ?? [];
    $total       = $summary['total_processed'] ?? 0;
    $created     = $summary['users_created'] ?? 0;
    $updated     = $summary['users_updated'] ?? 0;
    $deleted     = $summary['users_deleted'] ?? 0;
    $safe_caught = $summary['safe_mode_caught'] ?? [];
    $errors      = $summary['errors'] ?? [];

    $result = "Processed $total. Created $created. Updated $updated. Deleted $deleted.";
    if ( ! empty( $errors ) ) {
    $result .= " | ERRORS: " . implode( ', ', $errors );
}

    // If Safe Mode caught anyone, append their emails to the feedback message
    if ( ! empty( $safe_caught ) ) {
        $count = count( $safe_caught );
        // Only display the first 5 emails so we don't break the UI if there are hundreds
        $display = array_slice( $safe_caught, 0, 5 );
        $list_str = implode( ', ', $display );

        if ( $count > 5 ) {
            $list_str .= " (and " . ($count - 5) . " more)";
        }

        $result .= " | SAFE MODE: Prevented deletion of $count users -> $list_str";
    }

    return $result;
}

