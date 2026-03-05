<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class MSC_Post_Types {

    public static function init() {
        add_action( 'init', array( __CLASS__, 'register_all' ) );
    }

    public static function register_all() {
        // ── Racing Event ─────────────────────────────────────────────
        register_post_type( 'msc_event', array(
            'labels'        => self::labels( 'Racing Event', 'Racing Events' ),
            'public'        => true,
            'has_archive'   => true,
            'rewrite'       => array( 'slug' => 'racing-events' ),
            'supports'      => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
            'show_in_menu'  => 'motorsport-club',
            'show_in_rest'  => true,
        ) );

        // ── Vehicle (Garage) ─────────────────────────────────────────
        register_post_type( 'msc_vehicle', array(
            'labels'        => self::labels( 'Vehicle', 'Vehicles / Garage' ),
            'public'        => false,
            'show_ui'       => true,
            'show_in_menu'  => 'motorsport-club',
            'supports'      => array( 'title', 'thumbnail' ),
            'menu_icon'     => 'dashicons-car',
            'show_in_rest'  => true,
            'capability_type' => 'post',
            'map_meta_cap'  => true,
        ) );
    }

    private static function labels( $singular, $plural ) {
        return array(
            'name'          => $plural,
            'singular_name' => $singular,
            'add_new_item'  => "Add New $singular",
            'edit_item'     => "Edit $singular",
            'view_item'     => "View $singular",
            'search_items'  => "Search $plural",
            'not_found'     => "No $plural found",
        );
    }
}
