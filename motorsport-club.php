<?php
/**
 * Plugin Name: Motorsport Club Manager
 * Plugin URI:  https://github.com/RhadenGG/motorsport-club-manager
 * Description: Full motorsport event management — events, vehicle garage, classes, registration, indemnity signing & entry fees.
 * Version:     0.2.9
 * Author:      Trevor Botha
 * Author URI:  https://trevorbotha.net
 * Text Domain: motorsport-club
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'MSC_VERSION',  '0.2.9' );
define( 'MSC_PATH',     plugin_dir_path( __FILE__ ) );
define( 'MSC_URL',      plugin_dir_url( __FILE__ ) );
define( 'MSC_BASENAME', plugin_basename( __FILE__ ) );

require_once MSC_PATH . 'includes/lib/class-msc-pdf.php';
require_once MSC_PATH . 'includes/class-activator.php';
require_once MSC_PATH . 'includes/class-post-types.php';
require_once MSC_PATH . 'includes/class-taxonomies.php';
require_once MSC_PATH . 'includes/class-admin-events.php';
require_once MSC_PATH . 'includes/class-admin-garage.php';
require_once MSC_PATH . 'includes/class-registration.php';
require_once MSC_PATH . 'includes/class-indemnity.php';
require_once MSC_PATH . 'includes/class-emails.php';
require_once MSC_PATH . 'includes/class-shortcodes.php';
require_once MSC_PATH . 'includes/class-account.php';
require_once MSC_PATH . 'includes/class-security.php';
require_once MSC_PATH . 'includes/class-results.php';

register_activation_hook( __FILE__,   array( 'MSC_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'MSC_Activator', 'deactivate' ) );

add_action( 'plugins_loaded', 'msc_init' );
function msc_init() {
    MSC_Post_Types::init();
    MSC_Taxonomies::init();
    MSC_Admin_Events::init();
    MSC_Admin_Garage::init();
    MSC_Registration::init();
    MSC_Indemnity::init();
    MSC_Emails::init();
    MSC_Shortcodes::init();
    MSC_Account::init();
    MSC_Security::init();
    MSC_Results::init();
}

/**
 * Migration check to ensure DB and rewrites are up to date.
 * Runs on 'init' to ensure $wp_rewrite is available.
 */
add_action( 'init', 'msc_run_migration', 20 );
function msc_run_migration() {
    if ( get_option('msc_db_version') !== MSC_VERSION ) {
        MSC_Activator::activate();
        flush_rewrite_rules();
        update_option('msc_db_version', MSC_VERSION);
    }
}
