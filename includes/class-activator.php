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
            pop_file_id bigint(20) unsigned DEFAULT NULL,
            class_id bigint(20) unsigned DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY idx_event (event_id),
            KEY idx_user (user_id)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql1 );

        // Results table
        MSC_Results::create_table();

        // Seed default vehicle classes if none exist
        MSC_Taxonomies::seed_defaults();

        // Migrate existing terms: assign vehicle type meta if missing
        self::migrate_term_vehicle_types();
    }

    /** One-time migration: assign msc_vehicle_type meta to existing terms */
    private static function migrate_term_vehicle_types() {
        $terms = get_terms( array( 'taxonomy' => 'msc_vehicle_class', 'hide_empty' => false ) );
        if ( is_wp_error( $terms ) ) return;

        // Legacy mapping from the old hardcoded lists
        $motorcycle_classes = array(
            'Juniors', 'Motards / Supermotards', 'Powersport',
            'CBR150', '300 Class', '600/1000', 'MiniGP',
        );

        foreach ( $terms as $term ) {
            if ( get_term_meta( $term->term_id, 'msc_vehicle_type', true ) ) continue;
            $type = in_array( $term->name, $motorcycle_classes, true ) ? 'Motorcycle' : 'Car';
            update_term_meta( $term->term_id, 'msc_vehicle_type', $type );
        }
    }

    public static function deactivate() {
        flush_rewrite_rules();
    }
}
