<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class MSC_Taxonomies {

    public static function init() {
        add_action( 'init', array( __CLASS__, 'register' ) );

        // Term meta UI for vehicle type
        add_action( 'msc_vehicle_class_add_form_fields',  array( __CLASS__, 'add_term_fields' ) );
        add_action( 'msc_vehicle_class_edit_form_fields', array( __CLASS__, 'edit_term_fields' ), 10 );
        add_action( 'created_msc_vehicle_class',          array( __CLASS__, 'save_term_fields' ) );
        add_action( 'edited_msc_vehicle_class',           array( __CLASS__, 'save_term_fields' ) );

        // Add vehicle type column to term list table
        add_filter( 'manage_edit-msc_vehicle_class_columns',  array( __CLASS__, 'term_columns' ) );
        add_filter( 'manage_msc_vehicle_class_custom_column',  array( __CLASS__, 'term_column_data' ), 10, 3 );
    }

    public static function register() {
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

    // ── Term meta UI ──────────────────────────────────────────────────────

    public static function add_term_fields() {
        ?>
        <div class="form-field">
            <label for="msc_vehicle_type">Vehicle Type</label>
            <select name="msc_vehicle_type" id="msc_vehicle_type">
                <option value="Car">Car</option>
                <option value="Motorcycle">Motorcycle</option>
            </select>
            <p class="description">Which vehicle type does this class belong to?</p>
        </div>
        <?php
    }

    public static function edit_term_fields( $term ) {
        $type = get_term_meta( $term->term_id, 'msc_vehicle_type', true ) ?: 'Car';
        ?>
        <tr class="form-field">
            <th scope="row"><label for="msc_vehicle_type">Vehicle Type</label></th>
            <td>
                <select name="msc_vehicle_type" id="msc_vehicle_type">
                    <option value="Car"        <?php selected( $type, 'Car' ); ?>>Car</option>
                    <option value="Motorcycle"  <?php selected( $type, 'Motorcycle' ); ?>>Motorcycle</option>
                </select>
                <p class="description">Which vehicle type does this class belong to?</p>
            </td>
        </tr>
        <?php
    }

    public static function save_term_fields( $term_id ) {
        if ( isset( $_POST['msc_vehicle_type'] ) ) {
            $allowed = array( 'Car', 'Motorcycle' );
            $type    = sanitize_text_field( $_POST['msc_vehicle_type'] );
            if ( in_array( $type, $allowed, true ) ) {
                update_term_meta( $term_id, 'msc_vehicle_type', $type );
            }
        }
    }

    // ── Term list columns ─────────────────────────────────────────────────

    public static function term_columns( $columns ) {
        $new = array();
        foreach ( $columns as $k => $v ) {
            $new[ $k ] = $v;
            if ( $k === 'name' ) {
                $new['vehicle_type'] = 'Vehicle Type';
            }
        }
        return $new;
    }

    public static function term_column_data( $content, $column, $term_id ) {
        if ( $column === 'vehicle_type' ) {
            $type = get_term_meta( $term_id, 'msc_vehicle_type', true );
            return $type ? esc_html( $type ) : '—';
        }
        return $content;
    }

    // ── Public API ────────────────────────────────────────────────────────

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

    /** Return supported vehicle types (derived from term meta) */
    public static function get_vehicle_types() {
        return array( 'Car', 'Motorcycle' );
    }

    /** Return classes grouped by vehicle type: array( 'Car' => [...], 'Motorcycle' => [...] ) */
    public static function get_classes_by_type() {
        $types  = self::get_vehicle_types();
        $result = array();
        foreach ( $types as $type ) {
            $result[ $type ] = array();
        }

        $terms = get_terms( array( 'taxonomy' => 'msc_vehicle_class', 'hide_empty' => false ) );
        if ( is_wp_error( $terms ) ) return $result;

        foreach ( $terms as $term ) {
            $type = get_term_meta( $term->term_id, 'msc_vehicle_type', true ) ?: 'Car';
            if ( ! isset( $result[ $type ] ) ) {
                $result[ $type ] = array();
            }
            $result[ $type ][ $term->term_id ] = $term->name;
        }

        return $result;
    }
}
