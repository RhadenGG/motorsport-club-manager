<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class MSC_Logger {

    const OPTION = 'msc_debug_logging';

    public static function is_enabled() {
        return (bool) get_option( self::OPTION, 0 );
    }

    public static function get_log_dir() {
        $upload_dir = wp_upload_dir();
        return trailingslashit( $upload_dir['basedir'] ) . 'msc-logs';
    }

    public static function get_log_path() {
        return self::get_log_dir() . '/msc-debug-' . gmdate( 'Y-m-d' ) . '.log';
    }

    public static function maybe_setup_dir() {
        $dir = self::get_log_dir();
        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
        }
        $htaccess = $dir . '/.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            file_put_contents( $htaccess, "Deny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
        }
        $index = $dir . '/index.php';
        if ( ! file_exists( $index ) ) {
            file_put_contents( $index, "<?php // Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
        }
    }

    public static function log( $level, $context, $message, $data = array() ) {
        if ( ! self::is_enabled() ) return;

        self::maybe_setup_dir();

        $timestamp = gmdate( 'Y-m-d H:i:s' ) . ' UTC';
        $user_id   = get_current_user_id();
        $entry     = sprintf(
            "[%s] [%-7s] [%s] [user:%d] %s",
            $timestamp,
            strtoupper( $level ),
            $context,
            $user_id,
            $message
        );
        if ( ! empty( $data ) ) {
            $entry .= ' | ' . wp_json_encode( $data );
        }
        $entry .= "\n";

        error_log( $entry, 3, self::get_log_path() ); // phpcs:ignore WordPress.WP.AlternativeFunctions
    }

    public static function info( $context, $message, $data = array() ) {
        self::log( 'INFO', $context, $message, $data );
    }

    public static function warning( $context, $message, $data = array() ) {
        self::log( 'WARNING', $context, $message, $data );
    }

    public static function error( $context, $message, $data = array() ) {
        self::log( 'ERROR', $context, $message, $data );
    }

    /**
     * Delete log files older than $days days.
     * Called from the settings page "Purge" action.
     */
    public static function purge_old_logs( $days = 7 ) {
        $dir    = self::get_log_dir();
        if ( ! is_dir( $dir ) ) return 0;
        $files   = glob( $dir . '/msc-debug-*.log' ) ?: array();
        $cutoff  = time() - ( $days * DAY_IN_SECONDS );
        $removed = 0;
        foreach ( $files as $file ) {
            if ( filemtime( $file ) < $cutoff ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions
                if ( @unlink( $file ) ) {
                    $removed++;
                }
            }
        }
        return $removed;
    }

    /**
     * AJAX: receive client-side error reports from the browser so they land
     * in the server log alongside the corresponding server-side context.
     */
    public static function ajax_log_client_error() {
        check_ajax_referer( 'msc_nonce', 'nonce' );

        $http_status = absint( $_POST['http_status'] ?? 0 );
        $action_name = sanitize_key( $_POST['msc_action'] ?? 'unknown' );
        $event_id    = absint( $_POST['event_id'] ?? 0 );
        $snippet     = isset( $_POST['response_snippet'] )
            ? substr( sanitize_text_field( wp_unslash( $_POST['response_snippet'] ) ), 0, 500 )
            : '';

        self::error(
            'Client',
            'Browser received HTTP ' . $http_status . ' on action: ' . $action_name,
            array_filter( array(
                'event_id' => $event_id ?: null,
                'response' => $snippet ?: null,
            ) )
        );

        wp_send_json_success();
    }

    public static function init() {
        add_action( 'wp_ajax_msc_log_client_error', array( __CLASS__, 'ajax_log_client_error' ) );
    }
}
