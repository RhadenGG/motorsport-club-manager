<?php
defined( 'ABSPATH' ) || exit;

class MSC_Activator {

    public static function activate() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        // Vehicle classes table
        $sql_classes = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}msc_vehicle_classes (
            id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name        VARCHAR(100) NOT NULL,
            slug        VARCHAR(100) NOT NULL UNIQUE,
            description TEXT,
            sort_order  INT DEFAULT 0,
            created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
        ) $charset;";

        // Garage (user vehicles)
        $sql_garage = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}msc_garage (
            id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id    BIGINT UNSIGNED NOT NULL,
            class_id   BIGINT UNSIGNED NOT NULL,
            make       VARCHAR(100) NOT NULL,
            model      VARCHAR(100) NOT NULL,
            year       YEAR,
            color      VARCHAR(50),
            reg_number VARCHAR(50),
            notes      TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) $charset;";

        // Registrations
        $sql_registrations = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}msc_registrations (
            id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            event_id        BIGINT UNSIGNED NOT NULL,
            user_id         BIGINT UNSIGNED NOT NULL,
            vehicle_id      BIGINT UNSIGNED NOT NULL,
            status          ENUM('pending','approved','rejected','cancelled') DEFAULT 'pending',
            entry_fee       DECIMAL(10,2) DEFAULT 0.00,
            fee_paid        TINYINT(1) DEFAULT 0,
            indemnity_method ENUM('signed','download','') DEFAULT '',
            indemnity_signed TINYINT(1) DEFAULT 0,
            indemnity_data  LONGTEXT,
            indemnity_ip    VARCHAR(45),
            indemnity_date  DATETIME,
            admin_notes     TEXT,
            created_at      DATETIME DEFAULT CURRENT_TIMESTAMP
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql_classes );
        dbDelta( $sql_garage );
        dbDelta( $sql_registrations );

        add_option( 'msc_db_version', MSC_VERSION );
        add_option( 'msc_indemnity_text', self::default_indemnity_text() );
        add_option( 'msc_club_name', get_bloginfo( 'name' ) );
        add_option( 'msc_admin_email', get_option( 'admin_email' ) );

        // Create pages
        self::create_pages();
    }

    private static function create_pages() {
        $pages = array(
            'msc_events_page'       => array( 'title' => 'Racing Events',   'content' => '[msc_events]' ),
            'msc_garage_page'       => array( 'title' => 'My Garage',       'content' => '[msc_garage]' ),
            'msc_dashboard_page'    => array( 'title' => 'My Registrations','content' => '[msc_dashboard]' ),
        );
        foreach ( $pages as $option => $data ) {
            if ( get_option( $option ) ) continue;
            $page_id = wp_insert_post( array(
                'post_title'   => $data['title'],
                'post_content' => $data['content'],
                'post_status'  => 'publish',
                'post_type'    => 'page',
            ) );
            if ( $page_id && ! is_wp_error( $page_id ) ) {
                update_option( $option, $page_id );
            }
        }
    }

    public static function deactivate() {}

    private static function default_indemnity_text() {
        return "INDEMNITY AND ASSUMPTION OF RISK AGREEMENT\n\n
I, the undersigned, acknowledge that motorsport activities involve significant risks of injury or death. In consideration of being permitted to participate in this event, I hereby:\n\n
1. ASSUME ALL RISKS associated with participation, including but not limited to: collision, mechanical failure, course conditions, and the actions of other participants.\n\n
2. RELEASE AND INDEMNIFY the club, its officers, directors, volunteers, officials, and sponsors from any and all liability for injury, loss, damage, or death arising from my participation.\n\n
3. CONFIRM that my vehicle is roadworthy, insured, and compliant with all applicable regulations.\n\n
4. CONFIRM that I hold a valid driver's/rider's licence appropriate for the vehicle class.\n\n
5. AGREE to abide by all event rules, regulations, and officials' decisions.\n\n
I have read and understood this agreement and sign it of my own free will.";
    }
}
