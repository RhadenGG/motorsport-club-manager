<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class MSC_Registration {

    public static function init() {
        add_action( 'wp_ajax_msc_submit_registration',        array( __CLASS__, 'ajax_submit' ) );
        add_action( 'wp_ajax_nopriv_msc_submit_registration', array( __CLASS__, 'ajax_submit_nopriv' ) );
        add_action( 'wp_ajax_msc_get_vehicles',               array( __CLASS__, 'ajax_get_vehicles' ) );
        add_action( 'wp_ajax_msc_cancel_registration',        array( __CLASS__, 'ajax_cancel' ) );
    }

    public static function ajax_submit_nopriv() {
        wp_send_json_error( array( 'message' => 'You must be logged in to register for an event.' ) );
    }

    public static function ajax_get_vehicles() {
        check_ajax_referer( 'msc_nonce', 'nonce' );
        $event_id = intval( $_POST['event_id'] );
        $vehicles = MSC_Admin_Garage::get_user_vehicles_for_event( get_current_user_id(), $event_id );
        $out = array();
        foreach ( $vehicles as $v ) {
            $make        = get_post_meta( $v->ID, '_msc_make',        true );
            $model       = get_post_meta( $v->ID, '_msc_model',       true );
            $year        = get_post_meta( $v->ID, '_msc_year',        true );
            $type        = get_post_meta( $v->ID, '_msc_type',        true );
            $reg         = get_post_meta( $v->ID, '_msc_reg_number',  true );
            $engine_size = get_post_meta( $v->ID, '_msc_engine_size', true );

            $out[] = array(
                'id'          => $v->ID,
                'title'       => $v->post_title,
                'label'       => trim( "$year $make $model" ) . ( $reg ? " ($reg)" : '' ) . " — $type",
                'type'        => $type,
                'engine_size' => $engine_size,
            );
        }
        wp_send_json_success( $out );
    }

    public static function ajax_submit() {
        check_ajax_referer( 'msc_nonce', 'nonce' );
        global $wpdb;

        $user_id    = get_current_user_id();
        $event_id   = intval( $_POST['event_id'] );
        $vehicle_id = intval( $_POST['vehicle_id'] );

        // Validate class selection — must have at least one
        $raw_class_ids = isset( $_POST['class_ids'] ) ? (array) $_POST['class_ids'] : array();
        $class_ids     = array_values( array_filter( array_map( 'absint', $raw_class_ids ) ) );
        if ( empty( $class_ids ) ) {
            wp_send_json_error( array( 'message' => 'Please select at least one class to enter.' ) );
        }

        $ind_method = sanitize_key( $_POST['indemnity_method'] ?? '' );
        $ind_sig    = sanitize_textarea_field( $_POST['indemnity_sig'] ?? '' );

        $birthday = get_user_meta( $user_id, 'msc_birthday', true );
        $is_minor = 0;
        if ( $birthday ) {
            $dob_ts = strtotime( $birthday );
            $now_ts = time();
            $age    = date( 'Y', $now_ts ) - date( 'Y', $dob_ts );
            if ( date( 'md', $now_ts ) < date( 'md', $dob_ts ) ) $age--;
            if ( $age < 18 ) $is_minor = 1;
        }

        $parent     = sanitize_text_field( $_POST['parent_name']    ?? '' );
        $parent_sig = sanitize_textarea_field( $_POST['parent_sig'] ?? '' );
        $em_name    = sanitize_text_field( $_POST['emergency_name']  ?? '' );
        $em_phone   = sanitize_text_field( $_POST['emergency_phone'] ?? '' );
        $em_rel     = sanitize_text_field( $_POST['emergency_rel']   ?? '' );
        $pit_crew_1 = sanitize_text_field( $_POST['pit_crew_1']      ?? '' );
        $pit_crew_2 = sanitize_text_field( $_POST['pit_crew_2']      ?? '' );
        $notes      = sanitize_textarea_field( $_POST['notes']       ?? '' );

        $user_obj = get_userdata( $user_id );
        $ind_full = $user_obj ? $user_obj->display_name : 'Unknown';

        // Validations
        if ( ! $event_id || ! $vehicle_id || ! $em_name || ! $em_phone ) {
            wp_send_json_error( array( 'message' => 'Please complete all required emergency contact fields.' ) );
        }
        if ( $ind_method !== 'signed' || ! $ind_sig ) {
            wp_send_json_error( array( 'message' => 'Electronic signature is required to complete the indemnity.' ) );
        }
        if ( $is_minor ) {
            if ( ! $parent ) wp_send_json_error( array( 'message' => 'Please provide the parent/guardian name for a minor.' ) );
            if ( $ind_method === 'signed' && ! $parent_sig ) wp_send_json_error( array( 'message' => 'Please provide the parent/guardian signature.' ) );
        }

        $event = get_post( $event_id );
        if ( ! $event || $event->post_type !== 'msc_event' ) {
            wp_send_json_error( array( 'message' => 'Invalid event.' ) );
        }

        // Registration window
        $reg_open  = get_post_meta( $event_id, '_msc_reg_open',  true );
        $reg_close = get_post_meta( $event_id, '_msc_reg_close', true );
        $now       = current_time( 'timestamp' );
        if ( $reg_open  && strtotime( $reg_open )  > $now ) wp_send_json_error( array( 'message' => 'Registration has not opened yet.' ) );
        if ( $reg_close && strtotime( $reg_close ) < $now ) wp_send_json_error( array( 'message' => 'Registration is closed.' ) );

        // Capacity
        $capacity = intval( get_post_meta( $event_id, '_msc_capacity', true ) );
        if ( $capacity > 0 ) {
            $count = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}msc_registrations WHERE event_id=%d AND status NOT IN ('rejected','cancelled')",
                $event_id
            ) );
            if ( $count >= $capacity ) wp_send_json_error( array( 'message' => 'Sorry, this event is fully booked.' ) );
        }

        // Duplicate check
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}msc_registrations WHERE event_id=%d AND user_id=%d AND status NOT IN ('rejected','cancelled')",
            $event_id, $user_id
        ) );
        if ( $exists ) wp_send_json_error( array( 'message' => 'You are already registered for this event.' ) );

        // Vehicle ownership
        $vehicle = get_post( $vehicle_id );
        if ( ! $vehicle || (int) $vehicle->post_author !== $user_id ) {
            wp_send_json_error( array( 'message' => 'Invalid vehicle selection.' ) );
        }

        // Validate class IDs belong to this event
        $allowed_classes = get_post_meta( $event_id, '_msc_event_classes', true );
        $allowed_classes = $allowed_classes ? array_map( 'intval', (array) $allowed_classes ) : array();
        foreach ( $class_ids as $cid ) {
            if ( ! in_array( $cid, $allowed_classes, true ) ) {
                wp_send_json_error( array( 'message' => 'One or more selected classes are not allowed for this event.' ) );
            }
        }

        // Calculate total fee
        $base_fee   = floatval( get_post_meta( $event_id, '_msc_entry_fee', true ) );
        $class_fees = get_post_meta( $event_id, '_msc_class_fees', true );
        $class_fees = is_array( $class_fees ) ? $class_fees : array();

        $total_fee = $base_fee;
        $fee_per_class = array(); // class_id => fee paid for that class
        foreach ( $class_ids as $cid ) {
            $cf = isset( $class_fees[ $cid ] ) ? floatval( $class_fees[ $cid ] ) : 0.0;
            $fee_per_class[ $cid ] = $cf;
            $total_fee += $cf;
        }

        // Handle Proof of Payment upload
        $pop_file_id = null;
        if ( ! empty( $_FILES['pop_file'] ) && ! empty( $_FILES['pop_file']['name'] ) ) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';

            $check = wp_check_filetype_and_ext( $_FILES['pop_file']['tmp_name'], $_FILES['pop_file']['name'] );
            if ( $check['ext'] !== 'pdf' || $check['type'] !== 'application/pdf' ) {
                wp_send_json_error( array( 'message' => 'Proof of Payment must be a PDF file.' ) );
            }

            $attachment_id = media_handle_upload( 'pop_file', 0 );
            if ( is_wp_error( $attachment_id ) ) {
                wp_send_json_error( array( 'message' => 'Failed to upload Proof of Payment: ' . $attachment_id->get_error_message() ) );
            }
            $pop_file_id = $attachment_id;
        } elseif ( $total_fee > 0 ) {
            wp_send_json_error( array( 'message' => 'Proof of Payment is required for this event.' ) );
        }

        $approval = get_post_meta( $event_id, '_msc_approval', true ) ?: 'instant';
        $status   = ( $approval === 'manual' ) ? 'pending' : 'confirmed';

        // Insert main registration row
        $inserted = $wpdb->insert( "{$wpdb->prefix}msc_registrations", array(
            'event_id'            => $event_id,
            'user_id'             => $user_id,
            'vehicle_id'          => $vehicle_id,
            'status'              => $status,
            'entry_fee'           => $total_fee,
            'fee_paid'            => 0,
            'indemnity_method'    => $ind_method,
            'indemnity_full_name' => $ind_full,
            'is_minor'            => $is_minor,
            'parent_name'         => $parent,
            'parent_sig'          => $parent_sig,
            'emergency_name'      => $em_name,
            'emergency_phone'     => $em_phone,
            'indemnity_sig'       => $ind_sig,
            'indemnity_date'      => ( $ind_method === 'signed' ) ? gmdate( 'Y-m-d H:i:s' ) : null,
            'notes'               => $notes,
            'pop_file_id'         => $pop_file_id,
            'class_id'            => null, // deprecated — classes stored in junction table
            'created_at'          => gmdate( 'Y-m-d H:i:s' ),
        ), array( '%d','%d','%d','%s','%f','%d','%s','%s','%d','%s','%s','%s','%s','%s','%s','%d','%s','%d','%s' ) );

        if ( false === $inserted ) {
            error_log( 'MSC Registration Error: ' . $wpdb->last_error );
            wp_send_json_error( array( 'message' => 'Failed to save registration. Please contact the administrator.' ) );
        }

        $reg_id = $wpdb->insert_id;

        // Insert per-class rows into junction table
        foreach ( $class_ids as $cid ) {
            $wpdb->insert(
                "{$wpdb->prefix}msc_registration_classes",
                array(
                    'registration_id' => $reg_id,
                    'class_id'        => $cid,
                    'class_fee'       => $fee_per_class[ $cid ],
                ),
                array( '%d', '%d', '%f' )
            );
        }

        // Save pit crew and emergency relationship to user profile
        if ( isset( $_POST['pit_crew_1'] ) )    update_user_meta( $user_id, 'msc_pit_crew_1',    $pit_crew_1 );
        if ( isset( $_POST['pit_crew_2'] ) )    update_user_meta( $user_id, 'msc_pit_crew_2',    $pit_crew_2 );
        if ( isset( $_POST['emergency_rel'] ) ) update_user_meta( $user_id, 'msc_emergency_rel', $em_rel );

        MSC_Emails::send_registration_received( $reg_id );
        if ( $status === 'confirmed' ) MSC_Emails::send_confirmation( $reg_id );

        if ( $ind_method === 'signed' ) {
            MSC_Indemnity::email_signed_pdf( $reg_id );
        }

        $message = ( $status === 'confirmed' )
            ? 'You\'re registered! A confirmation email has been sent.'
            : 'Your registration has been submitted and is awaiting approval. We\'ll email you once confirmed.';

        wp_send_json_success( array( 'message' => $message, 'status' => $status, 'reg_id' => $reg_id ) );
    }

    public static function ajax_cancel() {
        check_ajax_referer( 'msc_nonce', 'nonce' );
        global $wpdb;
        $reg_id  = intval( $_POST['reg_id'] );
        $user_id = get_current_user_id();
        $reg = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}msc_registrations WHERE id=%d AND user_id=%d",
            $reg_id, $user_id
        ) );
        if ( ! $reg ) wp_send_json_error( array( 'message' => 'Registration not found.' ) );
        $wpdb->update(
            "{$wpdb->prefix}msc_registrations",
            array( 'status' => 'cancelled' ),
            array( 'id'     => $reg_id ),
            array( '%s' ),
            array( '%d' )
        );
        wp_send_json_success( array( 'message' => 'Registration cancelled.' ) );
    }

    /** Get all registrations for a user, with entered classes */
    public static function get_user_registrations( $user_id ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT r.*, p.post_title as event_name, v.post_title as vehicle_name
             FROM {$wpdb->prefix}msc_registrations r
             LEFT JOIN {$wpdb->posts} p ON p.ID = r.event_id
             LEFT JOIN {$wpdb->posts} v ON v.ID = r.vehicle_id
             WHERE r.user_id = %d ORDER BY r.created_at DESC",
            $user_id
        ) );
    }

    /** Return class names entered for a given registration */
    public static function get_class_names_for_registration( $reg_id ) {
        global $wpdb;
        $rows = $wpdb->get_col( $wpdb->prepare(
            "SELECT class_id FROM {$wpdb->prefix}msc_registration_classes WHERE registration_id = %d",
            $reg_id
        ) );
        $names = array();
        foreach ( $rows as $cid ) {
            $term = get_term( $cid, 'msc_vehicle_class' );
            if ( $term && ! is_wp_error( $term ) ) $names[] = $term->name;
        }
        return $names;
    }

    /** Check if user is already registered for event */
    public static function user_is_registered( $user_id, $event_id ) {
        global $wpdb;
        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}msc_registrations WHERE user_id=%d AND event_id=%d AND status NOT IN ('rejected','cancelled')",
            $user_id, $event_id
        ) );
    }
}
