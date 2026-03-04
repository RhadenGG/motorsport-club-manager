<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class MSC_Activator {

    public static function activate() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        // Registrations table
        $sql1 = "CREATE TABLE {$wpdb->prefix}msc_registrations (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            vehicle_id bigint(20) unsigned NOT NULL,
            status varchar(30) NOT NULL DEFAULT 'pending',
            entry_fee decimal(10,2) DEFAULT 0,
            fee_paid tinyint(1) DEFAULT 0,
            indemnity_method varchar(20) DEFAULT '',
            indemnity_full_name varchar(255) DEFAULT '',
            is_minor tinyint(1) DEFAULT 0,
            parent_name varchar(255) DEFAULT '',
            parent_sig longtext DEFAULT '',
            emergency_name varchar(255) DEFAULT '',
            emergency_phone varchar(50) DEFAULT '',
            indemnity_sig longtext DEFAULT '',
            indemnity_date datetime DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            notes text DEFAULT '',
            PRIMARY KEY  (id),
            KEY idx_event (event_id),
            KEY idx_user (user_id)
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
