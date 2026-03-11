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

        // Registration classes junction table
        $sql2 = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}msc_registration_classes (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            registration_id bigint(20) unsigned NOT NULL,
            class_id bigint(20) unsigned NOT NULL,
            class_fee decimal(10,2) NOT NULL DEFAULT 0.00,
            vehicle_id bigint(20) unsigned NULL,
            is_primary tinyint(1) NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY idx_reg (registration_id),
            UNIQUE KEY reg_class (registration_id, class_id)
        ) $charset;";
        $wpdb->query( $sql2 );

        // Migrate msc_registration_classes: add vehicle_id and is_primary if missing
        $cols = array_column( $wpdb->get_results( "DESCRIBE {$wpdb->prefix}msc_registration_classes" ), 'Field' );
        if ( ! in_array( 'vehicle_id', $cols, true ) ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}msc_registration_classes ADD COLUMN vehicle_id bigint(20) unsigned NULL" );
        }
        if ( ! in_array( 'is_primary', $cols, true ) ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}msc_registration_classes ADD COLUMN is_primary tinyint(1) NOT NULL DEFAULT 0" );
        }

        // Pricing sets table
        $sql3 = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}msc_pricing_sets (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id)
        ) $charset;";
        $wpdb->query( $sql3 );

        // Pricing set classes table
        $sql4 = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}msc_pricing_set_classes (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            pricing_set_id bigint(20) unsigned NOT NULL,
            class_id bigint(20) unsigned NOT NULL,
            primary_fee decimal(10,2) NOT NULL DEFAULT 0.00,
            additional_fee decimal(10,2) NOT NULL DEFAULT 0.00,
            global_additional_fee_override decimal(10,2) DEFAULT NULL,
            is_exempt_from_override tinyint(1) NOT NULL DEFAULT 0,
            is_primary_only tinyint(1) NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY pricing_class (pricing_set_id, class_id)
        ) $charset;";
        $wpdb->query( $sql4 );

        // Migrate msc_pricing_set_classes: add columns if missing
        $cols_pricing = array_column( $wpdb->get_results( "DESCRIBE {$wpdb->prefix}msc_pricing_set_classes" ), 'Field' );
        if ( ! in_array( 'global_additional_fee_override', $cols_pricing, true ) ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}msc_pricing_set_classes ADD COLUMN global_additional_fee_override decimal(10,2) DEFAULT NULL" );
        }
        if ( ! in_array( 'is_exempt_from_override', $cols_pricing, true ) ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}msc_pricing_set_classes ADD COLUMN is_exempt_from_override tinyint(1) NOT NULL DEFAULT 0" );
        }
        if ( ! in_array( 'is_primary_only', $cols_pricing, true ) ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}msc_pricing_set_classes ADD COLUMN is_primary_only tinyint(1) NOT NULL DEFAULT 0" );
        }

        // Results table
        MSC_Results::create_table();

        // Seed default vehicle classes if none exist
        MSC_Taxonomies::seed_defaults();

        // Migrate existing terms: assign vehicle type meta if missing
        self::migrate_term_vehicle_types();

        // Create the Event Creator role and assign capabilities
        self::setup_roles();
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

    private static function setup_roles() {
        // Create the Event Creator role if it doesn't exist
        if ( ! get_role( 'msc_event_creator' ) ) {
            add_role( 'msc_event_creator', 'Event Creator', array(
                'read'                  => true,
                'edit_posts'            => true,
                'publish_posts'         => true,
                'edit_others_posts'     => true,
                'msc_view_participants' => true,
            ) );
        } else {
            // Ensure existing role has the capability
            get_role( 'msc_event_creator' )->add_cap( 'msc_view_participants' );
        }

        // Ensure administrators have the capability
        $admin = get_role( 'administrator' );
        if ( $admin ) {
            $admin->add_cap( 'msc_view_participants' );
        }
    }

    public static function deactivate() {
        flush_rewrite_rules();
    }
}
