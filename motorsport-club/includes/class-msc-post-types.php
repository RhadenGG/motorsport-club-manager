<?php
defined( 'ABSPATH' ) || exit;

class MSC_Post_Types {
    public static function init() {
        add_action( 'init', array( __CLASS__, 'register' ) );
    }

    public static function register() {
        register_post_type( 'msc_event', array(
            'labels' => array(
                'name'               => 'Racing Events',
                'singular_name'      => 'Racing Event',
                'add_new_item'       => 'Add New Event',
                'edit_item'          => 'Edit Event',
                'new_item'           => 'New Event',
                'view_item'          => 'View Event',
                'search_items'       => 'Search Events',
                'not_found'          => 'No events found',
                'not_found_in_trash' => 'No events found in Trash',
            ),
            'public'        => true,
            'has_archive'   => false,
            'show_in_menu'  => false,
            'menu_icon'     => 'dashicons-flag',
            'supports'      => array( 'title', 'editor', 'thumbnail' ),
            'rewrite'       => array( 'slug' => 'racing-event' ),
        ) );
    }
}
