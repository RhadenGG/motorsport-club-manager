<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class MSC_Activator {

    public static function activate() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        // Registrations table
        $sql1 = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}msc_registrations (
            id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            event_id      BIGINT UNSIGNED NOT NULL,
            user_id       BIGINT UNSIGNED NOT NULL,
            vehicle_id    BIGINT UNSIGNED NOT NULL,
            status        VARCHAR(30) NOT NULL DEFAULT 'pending',
            entry_fee     DECIMAL(10,2) DEFAULT 0,
            fee_paid      TINYINT(1) DEFAULT 0,
            indemnity_method VARCHAR(20) DEFAULT '',
            indemnity_sig LONGTEXT DEFAULT '',
            indemnity_date DATETIME DEFAULT NULL,
            created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            notes         TEXT DEFAULT '',
            INDEX idx_event  (event_id),
            INDEX idx_user   (user_id)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql1 );

        // Results table
        MSC_Results::create_table();

        // Flush rewrite rules
        MSC_Post_Types::register_all();
        flush_rewrite_rules();
    }

    public static function deactivate() {
        flush_rewrite_rules();
    }
}
