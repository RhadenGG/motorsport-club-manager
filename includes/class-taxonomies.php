<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class MSC_Taxonomies {

    public static function init() {
        add_action( 'init', array( __CLASS__, 'register' ) );
    }

    public static function register() {
        // Vehicle Class taxonomy — shared between vehicles and events
        register_taxonomy( 'msc_vehicle_class', array( 'msc_vehicle', 'msc_event' ), array(
            'labels'            => array(
                'name'          => 'Vehicle Classes',
                'singular_name' => 'Vehicle Class',
                'add_new_item'  => 'Add New Class',
                'edit_item'     => 'Edit Class',
            ),
            'hierarchical'      => false,
            'public'            => false,
            'show_ui'           => true,
            'show_in_menu'      => true,
            'show_admin_column' => true,
            'rewrite'           => false,
            'show_in_rest'      => true,
        ) );
    }

    /** Return all vehicle classes as id => name array */
    public static function get_all_classes() {
        $terms = get_terms( array( 'taxonomy' => 'msc_vehicle_class', 'hide_empty' => false ) );
        $out   = array();
        if ( ! is_wp_error( $terms ) ) {
            foreach ( $terms as $t ) {
                $out[ $t->term_id ] = $t->name;
            }
        }
        return $out;
    }

    /** Return class IDs assigned to an event */
    public static function get_event_classes( $event_id ) {
        $terms = wp_get_post_terms( $event_id, 'msc_vehicle_class', array( 'fields' => 'ids' ) );
        return is_array( $terms ) ? $terms : array();
    }

    /** Return supported vehicle types */
    public static function get_vehicle_types() {
        return array( 'Car', 'Motorcycle' );
    }

    /** Return hardcoded classes by type for selection */
    public static function get_hardcoded_classes() {
        return array(
            'Car'        => array(
                'Modifieds / Super Modifieds',
                "Super GT's",
                'Retro Racing',
                'Sports Cars',
                'Porsche Challenge',
                'Time Challenge'
            ),
            'Motorcycle' => array(
                'Juniors',
                'Motards / Supermotards',
                'Powersport',
                'CBR150',
                '300 Class',
                '600/1000',
                'MiniGP'
            ),
        );
    }
}
