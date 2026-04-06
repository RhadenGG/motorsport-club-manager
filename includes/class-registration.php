<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class MSC_Registration {

    public static function init() {
        add_action( 'wp_ajax_msc_submit_registration',        array( __CLASS__, 'ajax_submit' ) );
        add_action( 'wp_ajax_nopriv_msc_submit_registration', array( __CLASS__, 'ajax_submit_nopriv' ) );
        add_action( 'wp_ajax_msc_get_vehicles',               array( __CLASS__, 'ajax_get_vehicles' ) );
        add_action( 'wp_ajax_msc_cancel_registration',        array( __CLASS__, 'ajax_cancel' ) );
        add_action( 'wp_ajax_msc_get_entry_edit_data',        array( __CLASS__, 'ajax_get_entry_edit_data' ) );
        add_action( 'wp_ajax_msc_update_entry_classes',       array( __CLASS__, 'ajax_update_entry_classes' ) );
        add_action( 'template_redirect',                      array( __CLASS__, 'maybe_serve_pop_file' ) );
    }

    /** Redirect media uploads to the protected msc-pop subdirectory */
    public static function pop_upload_dir( $dir ) {
        return array_merge( $dir, array(
            'path'   => $dir['basedir'] . '/msc-pop',
            'url'    => $dir['baseurl'] . '/msc-pop',
            'subdir' => '/msc-pop',
        ) );
    }

    /**
     * Upload a PoP file without generating attachment metadata (thumbnail/preview).
     * media_handle_upload() calls wp_generate_attachment_metadata() which invokes
     * GhostScript/ImageMagick on PDFs and takes 5-10 seconds. Since PoP files are
     * only ever served for download, metadata generation is unnecessary.
     *
     * @param string $file_key  The $_FILES key.
     * @return int|WP_Error     Attachment ID on success.
     */
    public static function upload_pop_file( $file_key ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        add_filter( 'upload_dir', array( __CLASS__, 'pop_upload_dir' ) );
        $upload = wp_handle_upload( $_FILES[ $file_key ], array( 'test_form' => false ) );
        remove_filter( 'upload_dir', array( __CLASS__, 'pop_upload_dir' ) );

        if ( isset( $upload['error'] ) ) {
            return new WP_Error( 'upload_error', $upload['error'] );
        }

        $attachment_id = wp_insert_attachment( array(
            'post_mime_type' => $upload['type'],
            'post_title'     => sanitize_file_name( basename( $upload['file'] ) ),
            'post_status'    => 'inherit',
            'guid'           => $upload['url'],
        ), $upload['file'], 0, true );

        if ( is_wp_error( $attachment_id ) ) {
            return $attachment_id;
        }
        if ( ! $attachment_id ) {
            return new WP_Error( 'insert_failed', 'Failed to register uploaded file in the database.' );
        }

        // Save _wp_attached_file meta so get_attached_file() can locate the file.
        // Intentionally skipping wp_generate_attachment_metadata() — PoP files are
        // download-only and do not need thumbnail generation (GhostScript/ImageMagick).
        update_attached_file( $attachment_id, $upload['file'] );

        return $attachment_id;
    }

    /** Serve a PoP file with access control via ?msc_pop_file={reg_id} */
    public static function maybe_serve_pop_file() {
        if ( ! isset( $_GET['msc_pop_file'] ) ) return;

        $reg_id = absint( $_GET['msc_pop_file'] );
        if ( ! $reg_id ) wp_die( 'Invalid entry ID.' );

        if ( ! is_user_logged_in() ) {
            auth_redirect();
            exit;
        }

        $pop_slot = isset( $_GET['pop'] ) && $_GET['pop'] === '2' ? 2 : 1;

        global $wpdb;
        $reg = $wpdb->get_row( $wpdb->prepare(
            "SELECT r.pop_file_id, r.pop_file_id_2, r.user_id, p.post_author as event_author
             FROM {$wpdb->prefix}msc_registrations r
             LEFT JOIN {$wpdb->posts} p ON p.ID = r.event_id
             WHERE r.id = %d",
            $reg_id
        ) );

        if ( ! $reg ) wp_die( 'File not found.' );
        $file_id = $pop_slot === 2 ? (int) $reg->pop_file_id_2 : (int) $reg->pop_file_id;
        if ( ! $file_id ) wp_die( 'File not found.' );

        $current_user_id = get_current_user_id();
        $is_owner     = ( (int) $reg->user_id === $current_user_id );
        $is_admin     = current_user_can( 'manage_options' );
        $is_organizer = current_user_can( 'msc_view_participants' );

        if ( ! $is_owner && ! $is_admin && ! $is_organizer ) {
            wp_die( 'You do not have permission to view this file.' );
        }

        $file_path = get_attached_file( $file_id );
        if ( ! $file_path || ! file_exists( $file_path ) ) {
            wp_die( 'File not found on server.' );
        }

        $mime         = wp_check_filetype( $file_path );
        $content_type = $mime['type'] ?: 'application/octet-stream';

        if ( ob_get_length() ) ob_end_clean();
        header( 'Content-Type: ' . $content_type );
        header( 'Content-Disposition: inline; filename="' . basename( $file_path ) . '"' );
        header( 'Cache-Control: private, no-cache, no-store, must-revalidate' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );
        readfile( $file_path );
        exit;
    }

    /**
     * Calculate the total entry fee for a given class selection.
     * Extracted from ajax_submit() so it can be reused for entry edits.
     *
     * @param int   $event_id
     * @param int   $primary_class_id
     * @param int[] $additional_class_ids
     * @return array { 'total' => float, 'per_class' => array<int,float> }
     */
    public static function calculate_fee( $event_id, $primary_class_id, $additional_class_ids ) {
        $base_fee       = floatval( get_post_meta( $event_id, '_msc_entry_fee', true ) );
        $pricing_set_id = (int) get_post_meta( $event_id, '_msc_pricing_set_id', true );
        $total          = $base_fee;
        $per_class      = array();

        if ( $pricing_set_id ) {
            $primary_data    = MSC_Pricing::get_class_pricing_data( $pricing_set_id, $primary_class_id );
            $primary_fee     = $primary_data ? $primary_data['primary_fee'] : 0.0;
            $global_override = ( $primary_data && $primary_data['override'] !== null ) ? $primary_data['override'] : null;

            $per_class[ $primary_class_id ] = $primary_fee;
            $total += $primary_fee;

            $all_set_fees = MSC_Pricing::get_set_fees( $pricing_set_id );

            foreach ( $additional_class_ids as $cid ) {
                $class_data = isset( $all_set_fees[ $cid ] ) ? $all_set_fees[ $cid ] : null;
                $af = 0.0;
                if ( $class_data ) {
                    if ( $class_data['exempt'] ) {
                        $af = $class_data['additional_fee'];
                    } elseif ( $global_override !== null ) {
                        $af = $global_override;
                    } else {
                        $af = $class_data['additional_fee'];
                    }
                }
                $per_class[ $cid ] = $af;
                $total += $af;
            }
        } else {
            $per_class[ $primary_class_id ] = 0.0;
            foreach ( $additional_class_ids as $cid ) {
                $per_class[ $cid ] = 0.0;
            }
        }

        return array( 'total' => round( $total, 2 ), 'per_class' => $per_class );
    }

    public static function ajax_submit_nopriv() {
        wp_send_json_error( array( 'message' => 'You must be logged in to register for an event.' ) );
    }

    public static function ajax_get_vehicles() {
        check_ajax_referer( 'msc_nonce', 'nonce' );
        $user_id  = get_current_user_id();
        $event_id = intval( $_POST['event_id'] );

        // Count vehicles owned by this user
        $total_user_vehicles = (int) ( new WP_Query( array(
            'post_type'      => 'msc_vehicle',
            'post_status'    => 'publish',
            'author'         => $user_id,
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ) ) )->found_posts;

        $vehicles = MSC_Admin_Garage::get_user_vehicles_for_event( $user_id, $event_id );
        $out = array();
        foreach ( $vehicles as $v ) {
            $make        = get_post_meta( $v->ID, '_msc_make',        true );
            $model       = get_post_meta( $v->ID, '_msc_model',       true );
            $year        = get_post_meta( $v->ID, '_msc_year',        true );
            $type        = get_post_meta( $v->ID, '_msc_type',        true );
            $engine_size = get_post_meta( $v->ID, '_msc_engine_size', true );

            $out[] = array(
                'id'          => $v->ID,
                'title'       => $v->post_title,
                'label'       => trim( "$year $make $model" ) . " — $type",
                'type'        => $type,
                'engine_size' => $engine_size,
                'comp_number' => get_post_meta( $v->ID, '_msc_comp_number', true ) ?: '',
            );
        }
        wp_send_json_success( array(
            'vehicles'           => $out,
            'total_user_vehicles' => $total_user_vehicles,
        ) );
    }

    public static function ajax_submit() {
        check_ajax_referer( 'msc_nonce', 'nonce' );
        global $wpdb;

        $user_id            = get_current_user_id();
        $event_id           = absint( $_POST['event_id'] ?? 0 );
        $primary_class_id   = absint( $_POST['primary_class_id'] ?? 0 );
        $primary_vehicle_id = absint( $_POST['primary_vehicle_id'] ?? 0 );

        MSC_Logger::info( 'Registration', 'Submit attempt', array( 'event_id' => $event_id ) );

        // Additional class IDs and their vehicle IDs
        $raw_add_class_ids   = isset( $_POST['additional_class_ids'] )   ? (array) $_POST['additional_class_ids']   : array();
        $raw_add_vehicle_ids = isset( $_POST['additional_vehicle_ids'] ) ? (array) $_POST['additional_vehicle_ids'] : array();
        $additional_class_ids   = array_values( array_filter( array_map( 'absint', $raw_add_class_ids ) ) );
        $additional_vehicle_ids = array_values( array_filter( array_map( 'absint', $raw_add_vehicle_ids ) ) );

        if ( ! $primary_class_id ) {
            wp_send_json_error( array( 'message' => 'Please select a primary class to enter.' ) );
        }
        if ( ! $primary_vehicle_id ) {
            wp_send_json_error( array( 'message' => 'Please select a vehicle for the primary class.' ) );
        }

        $ind_method  = sanitize_key( $_POST['indemnity_method'] ?? '' );
        $ind_sig_raw = isset( $_POST['indemnity_sig'] ) ? $_POST['indemnity_sig'] : '';
        if ( strpos( $ind_sig_raw, 'data:image/' ) === 0 ) {
            if ( ! preg_match( '/^data:image\/png;base64,[A-Za-z0-9+\/=\r\n]+$/', $ind_sig_raw ) ) {
                wp_send_json_error( array( 'message' => 'Invalid signature data.' ) );
            }
            $ind_sig = $ind_sig_raw;
        } else {
            $ind_sig = sanitize_text_field( wp_unslash( $ind_sig_raw ) );
        }

        $birthday = get_user_meta( $user_id, 'msc_birthday', true );
        $is_minor = 0;
        if ( $birthday ) {
            $dob_ts = strtotime( $birthday );
            $now_ts = time();
            $age    = date( 'Y', $now_ts ) - date( 'Y', $dob_ts );
            if ( date( 'md', $now_ts ) < date( 'md', $dob_ts ) ) $age--;
            if ( $age < 18 ) $is_minor = 1;
        }

        $parent         = sanitize_text_field( wp_unslash( $_POST['parent_name'] ?? '' ) );
        $parent_sig_raw = isset( $_POST['parent_sig'] ) ? $_POST['parent_sig'] : '';
        if ( strpos( $parent_sig_raw, 'data:image/' ) === 0 ) {
            if ( ! preg_match( '/^data:image\/png;base64,[A-Za-z0-9+\/=\r\n]+$/', $parent_sig_raw ) ) {
                wp_send_json_error( array( 'message' => 'Invalid parent signature data.' ) );
            }
            $parent_sig = $parent_sig_raw;
        } else {
            $parent_sig = sanitize_text_field( wp_unslash( $parent_sig_raw ) );
        }
        $msa_licence = sanitize_text_field( wp_unslash( $_POST['msa_licence'] ?? '' ) );
        $em_name    = sanitize_text_field( $_POST['emergency_name']  ?? '' );
        $em_phone   = sanitize_text_field( $_POST['emergency_phone'] ?? '' );
        $em_rel     = sanitize_text_field( $_POST['emergency_rel']   ?? '' );
        $pit_crew_1 = sanitize_text_field( $_POST['pit_crew_1']      ?? '' );
        $pit_crew_2 = sanitize_text_field( $_POST['pit_crew_2']      ?? '' );
        $sponsors   = substr( sanitize_text_field( wp_unslash( $_POST['sponsors'] ?? '' ) ), 0, 33 );
        $notes      = sanitize_textarea_field( $_POST['notes']       ?? '' );

        // Submitted competition numbers keyed by vehicle_id (may include stored or user-corrected values)
        $submitted_comp_numbers = array();
        if ( isset( $_POST['vehicle_comp_numbers'] ) && is_array( $_POST['vehicle_comp_numbers'] ) ) {
            foreach ( $_POST['vehicle_comp_numbers'] as $vid => $comp ) {
                $submitted_comp_numbers[ absint( $vid ) ] = sanitize_text_field( wp_unslash( $comp ) );
            }
        }

        $user_obj = get_userdata( $user_id );
        $ind_full = $user_obj ? $user_obj->display_name : 'Unknown';

        // Validations
        if ( empty( $_POST['indemnity_accept'] ) || $_POST['indemnity_accept'] !== '1' ) {
            wp_send_json_error( array( 'message' => 'You must accept the indemnity declaration to submit your entry.' ) );
        }
        if ( ! $msa_licence ) {
            wp_send_json_error( array( 'message' => 'Please enter your MSA Licence Number.' ) );
        }
        if ( ! $event_id || ! $em_name || ! $em_phone ) {
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
        if ( $reg_open  && strtotime( $reg_open )  > $now ) wp_send_json_error( array( 'message' => 'Entry window has not opened yet.' ) );
        if ( $reg_close && strtotime( $reg_close ) < $now ) wp_send_json_error( array( 'message' => 'Entry window is closed.' ) );

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
        if ( $exists ) wp_send_json_error( array( 'message' => 'You have already entered this event.' ) );

        // Validate all class IDs belong to this event
        $allowed_classes = get_post_meta( $event_id, '_msc_event_classes', true );
        $allowed_classes = $allowed_classes ? array_map( 'intval', (array) $allowed_classes ) : array();
        $all_entered_class_ids = array_merge( array( $primary_class_id ), $additional_class_ids );
        foreach ( $all_entered_class_ids as $cid ) {
            if ( ! in_array( $cid, $allowed_classes, true ) ) {
                wp_send_json_error( array( 'message' => 'One or more selected classes are not allowed for this event.' ) );
            }
        }

        // Validate class-specific conditions
        $submitted_conditions = isset( $_POST['msc_cdecl'] ) && is_array( $_POST['msc_cdecl'] ) ? $_POST['msc_cdecl'] : array();
        foreach ( $all_entered_class_ids as $cid ) {
            $cond_raw = get_term_meta( $cid, 'msc_class_conditions', true );
            if ( ! $cond_raw ) continue;
            $conditions = json_decode( $cond_raw, true );
            if ( ! is_array( $conditions ) ) continue;
            $cterm = get_term( $cid, 'msc_vehicle_class' );
            $cname = ( $cterm && ! is_wp_error( $cterm ) ) ? $cterm->name : 'Class #' . $cid;
            foreach ( $conditions as $idx => $cond ) {
                $ctype   = isset( $cond['type'] ) ? $cond['type'] : 'confirm';
                $options = isset( $cond['options'] ) && is_array( $cond['options'] ) ? $cond['options'] : array();
                $label   = isset( $cond['label'] ) ? $cond['label'] : '';
                $sub     = isset( $submitted_conditions[ $cid ][ $idx ] ) ? $submitted_conditions[ $cid ][ $idx ] : null;
                if ( $ctype === 'confirm' ) {
                    if ( $sub !== '1' ) {
                        wp_send_json_error( array( 'message' => 'Please confirm all requirements for class: ' . $cname ) );
                    }
                } elseif ( $ctype === 'select_one' ) {
                    $val = $sub !== null ? sanitize_text_field( wp_unslash( (string) $sub ) ) : '';
                    if ( ! in_array( $val, $options, true ) ) {
                        wp_send_json_error( array( 'message' => 'Please make a selection for "' . esc_html( $label ) . '" in class: ' . $cname ) );
                    }
                } elseif ( $ctype === 'select_many' ) {
                    if ( ! is_array( $sub ) ) {
                        wp_send_json_error( array( 'message' => 'Please select at least one option for "' . esc_html( $label ) . '" in class: ' . $cname ) );
                    }
                    $checked = array_filter( (array) $sub, function( $v ) use ( $options ) {
                        return in_array( sanitize_text_field( wp_unslash( $v ) ), $options, true );
                    } );
                    if ( empty( $checked ) ) {
                        wp_send_json_error( array( 'message' => 'Please select at least one option for "' . esc_html( $label ) . '" in class: ' . $cname ) );
                    }
                }
            }
        }

        // Validate vehicle ownership — primary
        $primary_vehicle = get_post( $primary_vehicle_id );
        if ( ! $primary_vehicle || (int) $primary_vehicle->post_author !== $user_id ) {
            wp_send_json_error( array( 'message' => 'Invalid primary vehicle selection.' ) );
        }
        // Validate additional vehicle ownership
        foreach ( $additional_vehicle_ids as $vid ) {
            $av = get_post( $vid );
            if ( ! $av || (int) $av->post_author !== $user_id ) {
                wp_send_json_error( array( 'message' => 'Invalid vehicle selection for additional class.' ) );
            }
        }

        // Validate that every selected vehicle has a competition number (stored or submitted)
        foreach ( array_unique( array_merge( array( $primary_vehicle_id ), $additional_vehicle_ids ) ) as $vid ) {
            $stored = get_post_meta( $vid, '_msc_comp_number', true );
            $posted = isset( $submitted_comp_numbers[ $vid ] ) ? $submitted_comp_numbers[ $vid ] : '';
            if ( ! $stored && ! $posted ) {
                $vpost = get_post( $vid );
                $vname = $vpost ? $vpost->post_title : 'vehicle #' . $vid;
                wp_send_json_error( array( 'message' => 'Please enter a Race Number for "' . esc_html( $vname ) . '".' ) );
            }
        }

        // Security check: cannot use primary-only class as additional
        if ( $pricing_set_id = (int) get_post_meta( $event_id, '_msc_pricing_set_id', true ) ) {
            $all_set_fees = MSC_Pricing::get_set_fees( $pricing_set_id );
            foreach ( $additional_class_ids as $cid ) {
                $class_data = isset( $all_set_fees[ $cid ] ) ? $all_set_fees[ $cid ] : null;
                if ( $class_data && ! empty( $class_data['primary_only'] ) ) {
                    wp_send_json_error( array( 'message' => 'The class "' . get_term( $cid )->name . '" can only be a primary class.' ) );
                }
            }
        }

        // Calculate total fee
        $fee_result    = self::calculate_fee( $event_id, $primary_class_id, $additional_class_ids );
        $total_fee     = $fee_result['total'];
        $fee_per_class = $fee_result['per_class'];

        // Handle Proof of Payment upload
        $pop_file_id = null;
        if ( ! empty( $_FILES['pop_file'] ) && ! empty( $_FILES['pop_file']['name'] ) ) {
            MSC_Logger::info( 'Registration', 'PoP upload started', array(
                'event_id' => $event_id,
                'filename' => sanitize_text_field( $_FILES['pop_file']['name'] ),
                'size'     => $_FILES['pop_file']['size'],
            ) );
            $check         = wp_check_filetype_and_ext( $_FILES['pop_file']['tmp_name'], $_FILES['pop_file']['name'] );
            $allowed_exts  = array( 'pdf', 'png', 'jpg', 'jpeg' );
            $allowed_types = array( 'application/pdf', 'image/png', 'image/jpeg' );
            if ( ! in_array( $check['ext'], $allowed_exts, true ) || ! in_array( $check['type'], $allowed_types, true ) ) {
                MSC_Logger::warning( 'Registration', 'PoP rejected: invalid file type', array( 'ext' => $check['ext'], 'type' => $check['type'] ) );
                wp_send_json_error( array( 'message' => 'Proof of Payment must be a PDF, PNG, or JPG file.' ) );
            }

            if ( $_FILES['pop_file']['size'] > 5 * 1024 * 1024 ) {
                MSC_Logger::warning( 'Registration', 'PoP rejected: file too large', array( 'size' => $_FILES['pop_file']['size'] ) );
                wp_send_json_error( array( 'message' => 'Proof of Payment must be smaller than 5MB.' ) );
            }

            $attachment_id = self::upload_pop_file( 'pop_file' );
            if ( is_wp_error( $attachment_id ) ) {
                MSC_Logger::error( 'Registration', 'PoP upload failed: ' . $attachment_id->get_error_message(), array( 'event_id' => $event_id ) );
                wp_send_json_error( array( 'message' => 'Failed to upload Proof of Payment: ' . $attachment_id->get_error_message() ) );
            }
            $pop_file_id = $attachment_id;
            MSC_Logger::info( 'Registration', 'PoP upload successful', array( 'attachment_id' => $pop_file_id ) );
        } elseif ( $total_fee > 0 ) {
            wp_send_json_error( array( 'message' => 'Proof of Payment is required for this event.' ) );
        }

        $approval = get_post_meta( $event_id, '_msc_approval', true ) ?: 'manual';
        $status   = ( $approval === 'manual' ) ? 'pending' : 'confirmed';

        // Insert main registration row (primary vehicle stored for backwards compat)
        $inserted = $wpdb->insert( "{$wpdb->prefix}msc_registrations", array(
            'event_id'            => $event_id,
            'user_id'             => $user_id,
            'vehicle_id'          => $primary_vehicle_id,
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
            MSC_Logger::error( 'Registration', 'DB insert failed', array( 'event_id' => $event_id, 'db_error' => $wpdb->last_error ) );
            error_log( 'MSC Registration Error: ' . $wpdb->last_error );
            wp_send_json_error( array( 'message' => 'Failed to save your entry. Please contact the administrator.' ) );
        }

        $reg_id = $wpdb->insert_id;
        MSC_Logger::info( 'Registration', 'Entry inserted', array( 'reg_id' => $reg_id, 'event_id' => $event_id, 'status' => $status ) );

        // Insert primary class row
        $primary_cond_data = self::build_conditions_data( $primary_class_id, $submitted_conditions );
        $wpdb->insert(
            "{$wpdb->prefix}msc_registration_classes",
            array(
                'registration_id' => $reg_id,
                'class_id'        => $primary_class_id,
                'class_fee'       => $fee_per_class[ $primary_class_id ],
                'vehicle_id'      => $primary_vehicle_id,
                'is_primary'      => 1,
                'conditions_data' => $primary_cond_data !== null ? wp_json_encode( $primary_cond_data ) : null,
            ),
            array( '%d', '%d', '%f', '%d', '%d', '%s' )
        );

        // Insert additional class rows
        foreach ( $additional_class_ids as $i => $cid ) {
            $vid       = isset( $additional_vehicle_ids[ $i ] ) ? $additional_vehicle_ids[ $i ] : $primary_vehicle_id;
            $cond_data = self::build_conditions_data( $cid, $submitted_conditions );
            $wpdb->insert(
                "{$wpdb->prefix}msc_registration_classes",
                array(
                    'registration_id' => $reg_id,
                    'class_id'        => $cid,
                    'class_fee'       => $fee_per_class[ $cid ],
                    'vehicle_id'      => $vid,
                    'is_primary'      => 0,
                    'conditions_data' => $cond_data !== null ? wp_json_encode( $cond_data ) : null,
                ),
                array( '%d', '%d', '%f', '%d', '%d', '%s' )
            );
        }

        // Save any user-supplied comp numbers back to the vehicle post meta
        foreach ( $submitted_comp_numbers as $vid => $comp ) {
            if ( $vid && $comp ) {
                $vpost = get_post( $vid );
                if ( $vpost && (int) $vpost->post_author === $user_id ) {
                    update_post_meta( $vid, '_msc_comp_number', $comp );
                }
            }
        }

        // Save motorsport details, pit crew, sponsors, and emergency relationship to user profile
        update_user_meta( $user_id, 'msc_msa_licence', $msa_licence );
        if ( isset( $_POST['pit_crew_1'] ) )    update_user_meta( $user_id, 'msc_pit_crew_1',    $pit_crew_1 );
        if ( isset( $_POST['pit_crew_2'] ) )    update_user_meta( $user_id, 'msc_pit_crew_2',    $pit_crew_2 );
        if ( isset( $_POST['sponsors'] ) )      update_user_meta( $user_id, 'msc_sponsors',      $sponsors );
        if ( isset( $_POST['emergency_rel'] ) ) update_user_meta( $user_id, 'msc_emergency_rel', $em_rel );

        MSC_Logger::info( 'Registration', 'Sending registration received email', array( 'reg_id' => $reg_id ) );
        MSC_Emails::send_registration_received( $reg_id );
        if ( $status === 'confirmed' ) self::assign_entry_number( $reg_id );
        if ( $status === 'confirmed' ) {
            MSC_Logger::info( 'Registration', 'Sending confirmation email', array( 'reg_id' => $reg_id ) );
            MSC_Emails::send_confirmation( $reg_id );
        }

        if ( $ind_method === 'signed' ) {
            MSC_Logger::info( 'Registration', 'Emailing signed indemnity PDF', array( 'reg_id' => $reg_id ) );
            MSC_Indemnity::email_signed_pdf( $reg_id );
        }

        $message = ( $status === 'confirmed' )
            ? 'You\'re registered! A confirmation email has been sent.'
            : 'Your entry has been submitted and is awaiting approval. We\'ll email you once confirmed.';

        MSC_Logger::info( 'Registration', 'Submit complete', array( 'reg_id' => $reg_id, 'status' => $status ) );
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
        if ( ! $reg ) {
            MSC_Logger::warning( 'Registration', 'Cancel failed: not found', array( 'reg_id' => $reg_id ) );
            wp_send_json_error( array( 'message' => 'Registration not found.' ) );
        }
        if ( MSC_Results::is_closed( $reg->event_id ) ) {
            MSC_Logger::warning( 'Registration', 'Cancel blocked: event closed', array( 'reg_id' => $reg_id, 'event_id' => $reg->event_id ) );
            wp_send_json_error( array( 'message' => 'This event is closed and entries can no longer be cancelled.' ) );
        }
        $wpdb->update(
            "{$wpdb->prefix}msc_registrations",
            array( 'status' => 'cancelled' ),
            array( 'id'     => $reg_id ),
            array( '%s' ),
            array( '%d' )
        );
        MSC_Logger::info( 'Registration', 'Entry cancelled', array( 'reg_id' => $reg_id, 'event_id' => $reg->event_id ) );
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

    /** Build a display label for a vehicle: "Year Make Model Engine" from post meta. Falls back to post title. */
    public static function format_vehicle_label( $vehicle_id ) {
        if ( ! $vehicle_id ) return '—';
        $year   = get_post_meta( $vehicle_id, '_msc_year',        true );
        $make   = get_post_meta( $vehicle_id, '_msc_make',        true );
        $model  = get_post_meta( $vehicle_id, '_msc_model',       true );
        $engine = get_post_meta( $vehicle_id, '_msc_engine_size', true );
        $label  = trim( "$year $make $model $engine" );
        return $label ?: ( get_the_title( $vehicle_id ) ?: '—' );
    }

    /** Return class+vehicle pairs for a registration: [['class_name', 'vehicle_name', 'is_primary'], ...] ordered primary first */
    public static function get_class_vehicle_pairs( $reg_id ) {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT rc.class_id, rc.vehicle_id, rc.is_primary
             FROM {$wpdb->prefix}msc_registration_classes rc
             WHERE rc.registration_id = %d ORDER BY rc.is_primary DESC",
            $reg_id
        ) );
        $pairs = array();
        foreach ( $rows as $row ) {
            $term = get_term( (int) $row->class_id, 'msc_vehicle_class' );
            $vid  = (int) $row->vehicle_id;
            $pairs[] = array(
                'class_id'     => (int) $row->class_id,
                'class_name'   => ( $term && ! is_wp_error( $term ) ) ? $term->name : '—',
                'vehicle_id'   => $vid,
                'vehicle_name' => self::format_vehicle_label( $vid ),
                'is_primary'   => (bool) $row->is_primary,
                'comp_number'  => $vid ? get_post_meta( $vid, '_msc_comp_number', true ) : '',
            );
        }

        // Fallback for legacy registrations that predate the junction table
        if ( empty( $pairs ) ) {
            $reg = $wpdb->get_row( $wpdb->prepare(
                "SELECT r.class_id, r.vehicle_id
                 FROM {$wpdb->prefix}msc_registrations r
                 WHERE r.id = %d",
                $reg_id
            ) );
            if ( $reg ) {
                $term = $reg->class_id ? get_term( (int) $reg->class_id, 'msc_vehicle_class' ) : null;
                $vid  = (int) $reg->vehicle_id;
                $pairs[] = array(
                    'class_id'     => $reg->class_id ? (int) $reg->class_id : 0,
                    'class_name'   => ( $term && ! is_wp_error( $term ) ) ? $term->name : '—',
                    'vehicle_id'   => $vid,
                    'vehicle_name' => self::format_vehicle_label( $vid ),
                    'is_primary'   => true,
                    'comp_number'  => $vid ? get_post_meta( $vid, '_msc_comp_number', true ) : '',
                );
            }
        }

        return $pairs;
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

    /** Return data needed to render the entry edit form */
    public static function ajax_get_entry_edit_data() {
        check_ajax_referer( 'msc_nonce', 'nonce' );
        if ( ! is_user_logged_in() ) wp_send_json_error( array( 'message' => 'Not logged in.' ) );

        $reg_id  = absint( $_POST['reg_id'] ?? 0 );
        $user_id = get_current_user_id();
        global $wpdb;

        $reg = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}msc_registrations WHERE id = %d AND user_id = %d",
            $reg_id, $user_id
        ) );
        if ( ! $reg || ! in_array( $reg->status, array( 'pending', 'confirmed' ), true ) ) {
            wp_send_json_error( array( 'message' => 'Entry not found or cannot be edited.' ) );
        }

        $event = get_post( $reg->event_id );
        if ( ! $event ) wp_send_json_error( array( 'message' => 'Event not found.' ) );

        if ( MSC_Results::is_closed( $reg->event_id ) ) {
            wp_send_json_error( array( 'message' => 'This event is closed and entries can no longer be edited.' ) );
        }

        // Current class rows
        $class_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT class_id, is_primary, vehicle_id FROM {$wpdb->prefix}msc_registration_classes WHERE registration_id = %d ORDER BY is_primary DESC",
            $reg_id
        ) );
        $current_classes = array();
        foreach ( $class_rows as $row ) {
            $current_classes[] = array(
                'class_id'   => (int) $row->class_id,
                'is_primary' => (int) $row->is_primary,
                'vehicle_id' => (int) $row->vehicle_id,
            );
        }

        // User's vehicles for this event
        $vehicles_raw  = MSC_Admin_Garage::get_user_vehicles_for_event( $user_id, $reg->event_id );
        $user_vehicles = array();
        foreach ( $vehicles_raw as $v ) {
            $make   = get_post_meta( $v->ID, '_msc_make',       true );
            $model  = get_post_meta( $v->ID, '_msc_model',      true );
            $year   = get_post_meta( $v->ID, '_msc_year',       true );
            $user_vehicles[] = array(
                'id'    => $v->ID,
                'label' => trim( "$year $make $model" ),
            );
        }

        // Event's allowed classes
        $allowed_ids = get_post_meta( $reg->event_id, '_msc_event_classes', true );
        $allowed_ids = $allowed_ids ? array_map( 'intval', (array) $allowed_ids ) : array();

        $event_classes = array();
        $valid_cond_types = array( 'confirm', 'select_one', 'select_many' );
        foreach ( $allowed_ids as $cid ) {
            $term = get_term( $cid, 'msc_vehicle_class' );
            if ( ! $term || is_wp_error( $term ) ) continue;
            $cond_raw   = get_term_meta( $cid, 'msc_class_conditions', true );
            $conditions = array();
            if ( $cond_raw ) {
                $decoded = json_decode( $cond_raw, true );
                if ( is_array( $decoded ) ) {
                    foreach ( $decoded as $cond ) {
                        if ( empty( $cond['label'] ) ) continue;
                        $ctype = isset( $cond['type'] ) && in_array( $cond['type'], $valid_cond_types, true ) ? $cond['type'] : 'confirm';
                        $entry = array( 'type' => $ctype, 'label' => sanitize_text_field( $cond['label'] ) );
                        if ( in_array( $ctype, array( 'select_one', 'select_many' ), true ) && ! empty( $cond['options'] ) ) {
                            $entry['options'] = array_values( array_filter( array_map( 'sanitize_text_field', (array) $cond['options'] ) ) );
                        }
                        $conditions[] = $entry;
                    }
                }
            }
            $event_classes[] = array(
                'id'         => $cid,
                'name'       => $term->name,
                'vtype'      => get_term_meta( $cid, 'msc_vehicle_type', true ) ?: '',
                'conditions' => $conditions,
            );
        }

        $pricing_set_id = (int) get_post_meta( $reg->event_id, '_msc_pricing_set_id', true );
        $base_fee       = floatval( get_post_meta( $reg->event_id, '_msc_entry_fee', true ) );
        $pricing        = $pricing_set_id ? MSC_Pricing::get_set_fees( $pricing_set_id ) : array();

        wp_send_json_success( array(
            'reg'             => array(
                'id'         => (int) $reg->id,
                'entry_fee'  => (float) $reg->entry_fee,
                'status'     => $reg->status,
                'event_name' => $event->post_title,
            ),
            'current_classes' => $current_classes,
            'event_classes'   => $event_classes,
            'pricing'         => $pricing,
            'base_fee'        => $base_fee,
            'user_vehicles'   => $user_vehicles,
            'pit_crew_1'      => get_user_meta( $user_id, 'msc_pit_crew_1', true ),
            'pit_crew_2'      => get_user_meta( $user_id, 'msc_pit_crew_2', true ),
        ) );
    }

    /** Save updated class selection for an existing entry */
    public static function ajax_update_entry_classes() {
        check_ajax_referer( 'msc_nonce', 'nonce' );
        if ( ! is_user_logged_in() ) wp_send_json_error( array( 'message' => 'Not logged in.' ) );

        $reg_id           = absint( $_POST['reg_id'] ?? 0 );
        $primary_class_id = absint( $_POST['primary_class_id'] ?? 0 );
        $additional_ids   = isset( $_POST['additional_class_ids'] ) && is_array( $_POST['additional_class_ids'] )
            ? array_values( array_filter( array_map( 'absint', $_POST['additional_class_ids'] ) ) )
            : array();

        // Per-class vehicle overrides submitted from the edit form
        $vehicle_ids_raw    = isset( $_POST['vehicle_ids'] ) && is_array( $_POST['vehicle_ids'] ) ? $_POST['vehicle_ids'] : array();
        $posted_vehicle_ids = array();
        foreach ( $vehicle_ids_raw as $cid => $vid ) {
            $posted_vehicle_ids[ absint( $cid ) ] = absint( $vid );
        }

        if ( ! $reg_id || ! $primary_class_id ) {
            wp_send_json_error( array( 'message' => 'Invalid request.' ) );
        }

        $user_id = get_current_user_id();
        global $wpdb;

        $reg = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}msc_registrations WHERE id = %d AND user_id = %d",
            $reg_id, $user_id
        ) );
        if ( ! $reg || ! in_array( $reg->status, array( 'pending', 'confirmed' ), true ) ) {
            wp_send_json_error( array( 'message' => 'Entry not found or cannot be edited.' ) );
        }

        if ( MSC_Results::is_closed( $reg->event_id ) ) {
            wp_send_json_error( array( 'message' => 'This event is closed and entries can no longer be edited.' ) );
        }

        // Validate classes are allowed for this event
        $allowed = get_post_meta( $reg->event_id, '_msc_event_classes', true );
        $allowed = $allowed ? array_map( 'intval', (array) $allowed ) : array();
        foreach ( array_merge( array( $primary_class_id ), $additional_ids ) as $cid ) {
            if ( ! in_array( $cid, $allowed, true ) ) {
                wp_send_json_error( array( 'message' => 'One or more selected classes are not allowed for this event.' ) );
            }
        }

        // Check primary-only constraint for additional classes
        $pricing_set_id = (int) get_post_meta( $reg->event_id, '_msc_pricing_set_id', true );
        if ( $pricing_set_id ) {
            $all_set_fees = MSC_Pricing::get_set_fees( $pricing_set_id );
            foreach ( $additional_ids as $cid ) {
                $cd = isset( $all_set_fees[ $cid ] ) ? $all_set_fees[ $cid ] : null;
                if ( $cd && ! empty( $cd['primary_only'] ) ) {
                    $t = get_term( $cid, 'msc_vehicle_class' );
                    wp_send_json_error( array( 'message' => 'The class "' . ( $t ? $t->name : $cid ) . '" can only be a primary class.' ) );
                }
            }
        }

        // Calculate new fee and compare
        $result       = self::calculate_fee( $reg->event_id, $primary_class_id, $additional_ids );
        $new_total    = $result['total'];
        $original_fee = round( (float) $reg->entry_fee, 2 );
        $difference   = round( $new_total - $original_fee, 2 );

        // Block downgrades
        if ( $difference < -0.005 ) {
            wp_send_json_error( array( 'message' => 'You cannot reduce your entry below the amount already paid.' ) );
        }

        // Require PoP for any additional amount owed
        $new_pop_file_id = null;
        if ( $difference > 0.005 ) {
            if ( empty( $_FILES['pop_file'] ) || empty( $_FILES['pop_file']['name'] ) ) {
                wp_send_json_error( array( 'message' => 'Please upload proof of payment for the additional R ' . number_format( $difference, 2 ) . ' owed.' ) );
            }
            $check         = wp_check_filetype_and_ext( $_FILES['pop_file']['tmp_name'], $_FILES['pop_file']['name'] );
            $allowed_exts  = array( 'pdf', 'png', 'jpg', 'jpeg' );
            $allowed_types = array( 'application/pdf', 'image/png', 'image/jpeg' );
            if ( ! in_array( $check['ext'], $allowed_exts, true ) || ! in_array( $check['type'], $allowed_types, true ) ) {
                wp_send_json_error( array( 'message' => 'Proof of Payment must be a PDF, PNG, or JPG file.' ) );
            }
            if ( $_FILES['pop_file']['size'] > 5 * 1024 * 1024 ) {
                wp_send_json_error( array( 'message' => 'Proof of Payment must be smaller than 5MB.' ) );
            }

            $attachment_id = self::upload_pop_file( 'pop_file' );
            if ( is_wp_error( $attachment_id ) ) {
                wp_send_json_error( array( 'message' => 'Failed to upload proof of payment: ' . $attachment_id->get_error_message() ) );
            }
            $new_pop_file_id = $attachment_id;
        }

        // Persist updated fee, PoP, and reset to pending if previously confirmed
        $update_data    = array( 'entry_fee' => $new_total );
        $update_formats = array( '%f' );
        if ( $reg->status === 'confirmed' ) {
            $update_data['status'] = 'pending';
            $update_formats[]      = '%s';
        }
        if ( $new_pop_file_id !== null ) {
            $update_data['pop_file_id_2'] = $new_pop_file_id;
            $update_formats[]             = '%d';
        }
        $wpdb->update(
            "{$wpdb->prefix}msc_registrations",
            $update_data,
            array( 'id' => $reg_id ),
            $update_formats,
            array( '%d' )
        );

        // Snapshot existing vehicle-per-class and conditions before deleting.
        $existing_vehicles   = array(); // class_id => vehicle_id
        $existing_conditions = array(); // class_id => conditions_data JSON
        $existing_class_ids  = array(); // class_ids currently enrolled
        $existing_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT class_id, vehicle_id, conditions_data FROM {$wpdb->prefix}msc_registration_classes WHERE registration_id = %d",
            $reg_id
        ) );
        foreach ( $existing_rows as $row ) {
            $existing_vehicles[   (int) $row->class_id ] = (int) $row->vehicle_id;
            $existing_conditions[ (int) $row->class_id ] = $row->conditions_data;
            $existing_class_ids[] = (int) $row->class_id;
        }

        // Validate conditions for any newly-added classes that have conditions defined.
        $msc_cdecl = isset( $_POST['msc_cdecl'] ) && is_array( $_POST['msc_cdecl'] ) ? $_POST['msc_cdecl'] : array();
        $all_new_ids = array_merge( array( $primary_class_id ), $additional_ids );
        foreach ( $all_new_ids as $cid ) {
            if ( in_array( $cid, $existing_class_ids, true ) ) continue; // existing — skip
            $cond_raw = get_term_meta( $cid, 'msc_class_conditions', true );
            if ( ! $cond_raw ) continue; // no conditions defined
            $cond_defs = json_decode( $cond_raw, true );
            if ( empty( $cond_defs ) || ! is_array( $cond_defs ) ) continue;
            $t          = get_term( $cid, 'msc_vehicle_class' );
            $class_name = $t ? $t->name : (string) $cid;
            $submitted  = isset( $msc_cdecl[ $cid ] ) && is_array( $msc_cdecl[ $cid ] ) ? $msc_cdecl[ $cid ] : array();
            foreach ( $cond_defs as $idx => $cond ) {
                $ctype   = isset( $cond['type'] ) ? $cond['type'] : 'confirm';
                $options = isset( $cond['options'] ) && is_array( $cond['options'] ) ? $cond['options'] : array();
                $raw     = isset( $submitted[ $idx ] ) ? $submitted[ $idx ] : null;
                if ( $ctype === 'confirm' ) {
                    if ( ! $raw ) {
                        wp_send_json_error( array( 'message' => 'Please complete all required conditions for class "' . $class_name . '".' ) );
                    }
                } elseif ( $ctype === 'select_one' ) {
                    $val = sanitize_text_field( wp_unslash( (string) $raw ) );
                    if ( ! in_array( $val, $options, true ) ) {
                        wp_send_json_error( array( 'message' => 'Please complete all required conditions for class "' . $class_name . '".' ) );
                    }
                } elseif ( $ctype === 'select_many' ) {
                    $has_valid = false;
                    foreach ( (array) $raw as $v ) {
                        if ( in_array( sanitize_text_field( wp_unslash( $v ) ), $options, true ) ) {
                            $has_valid = true;
                            break;
                        }
                    }
                    if ( ! $has_valid ) {
                        wp_send_json_error( array( 'message' => 'Please complete all required conditions for class "' . $class_name . '".' ) );
                    }
                }
            }
        }

        $primary_vehicle_id = (int) $reg->vehicle_id; // fallback for newly added classes

        // Resolve conditions_data for a class: submitted answers override; existing data preserved for unchanged classes.
        $resolve_conditions = function( $cid ) use ( $msc_cdecl, $existing_conditions, $existing_class_ids ) {
            if ( ! empty( $msc_cdecl[ $cid ] ) ) {
                $cd = MSC_Registration::build_conditions_data( $cid, $msc_cdecl );
                return ! empty( $cd ) ? wp_json_encode( $cd ) : null;
            }
            if ( isset( $existing_conditions[ $cid ] ) ) {
                return $existing_conditions[ $cid ];
            }
            return null;
        };

        // Replace junction table rows
        $wpdb->delete( "{$wpdb->prefix}msc_registration_classes", array( 'registration_id' => $reg_id ), array( '%d' ) );

        $wpdb->insert(
            "{$wpdb->prefix}msc_registration_classes",
            array(
                'registration_id' => $reg_id,
                'class_id'        => $primary_class_id,
                'class_fee'       => $result['per_class'][ $primary_class_id ],
                'vehicle_id'      => ( isset( $posted_vehicle_ids[ $primary_class_id ] ) && $posted_vehicle_ids[ $primary_class_id ] )
                                        ? $posted_vehicle_ids[ $primary_class_id ]
                                        : ( isset( $existing_vehicles[ $primary_class_id ] ) ? $existing_vehicles[ $primary_class_id ] : $primary_vehicle_id ),
                'is_primary'      => 1,
                'conditions_data' => $resolve_conditions( $primary_class_id ),
            ),
            array( '%d', '%d', '%f', '%d', '%d', '%s' )
        );
        foreach ( $additional_ids as $cid ) {
            $wpdb->insert(
                "{$wpdb->prefix}msc_registration_classes",
                array(
                    'registration_id' => $reg_id,
                    'class_id'        => $cid,
                    'class_fee'       => $result['per_class'][ $cid ],
                    'vehicle_id'      => ( isset( $posted_vehicle_ids[ $cid ] ) && $posted_vehicle_ids[ $cid ] )
                                            ? $posted_vehicle_ids[ $cid ]
                                            : ( isset( $existing_vehicles[ $cid ] ) ? $existing_vehicles[ $cid ] : $primary_vehicle_id ),
                    'is_primary'      => 0,
                    'conditions_data' => $resolve_conditions( $cid ),
                ),
                array( '%d', '%d', '%f', '%d', '%d', '%s' )
            );
        }

        // Update pit crew if submitted
        if ( isset( $_POST['msc_pit_crew_1'] ) ) {
            update_user_meta( $user_id, 'msc_pit_crew_1', sanitize_text_field( wp_unslash( $_POST['msc_pit_crew_1'] ) ) );
        }
        if ( isset( $_POST['msc_pit_crew_2'] ) ) {
            update_user_meta( $user_id, 'msc_pit_crew_2', sanitize_text_field( wp_unslash( $_POST['msc_pit_crew_2'] ) ) );
        }

        $msg = ( $reg->status === 'confirmed' )
            ? 'Your entry has been updated and resubmitted for approval.'
            : 'Your entry has been updated successfully.';
        wp_send_json_success( array( 'message' => $msg ) );
    }

    /**
     * Assign the next sequential entry number for this registration's event.
     * Only assigns if the entry doesn't already have a number.
     * Called when an entry is confirmed.
     */
    public static function assign_entry_number( $reg_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'msc_registrations';

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT event_id, entry_number FROM $table WHERE id = %d",
            $reg_id
        ) );

        if ( ! $row || $row->entry_number !== null ) return;

        // Use a transaction with FOR UPDATE to lock all rows for this event while
        // computing MAX, preventing two concurrent confirms from reading the same
        // max and writing duplicate numbers. The UNIQUE index on (event_id, entry_number)
        // in the DB schema provides a hard enforcement layer on top of this.
        $wpdb->query( 'START TRANSACTION' );

        $next = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(MAX(entry_number), 0) + 1 FROM $table WHERE event_id = %d FOR UPDATE",
            (int) $row->event_id
        ) );

        // Only write if the number is still unassigned (guard against race on re-entry)
        $wpdb->query( $wpdb->prepare(
            "UPDATE $table SET entry_number = %d WHERE id = %d AND entry_number IS NULL",
            $next, $reg_id
        ) );

        if ( $wpdb->last_error ) {
            $wpdb->query( 'ROLLBACK' );
        } else {
            $wpdb->query( 'COMMIT' );
        }
    }

    /**
     * Build a sanitised conditions_data array for storage from submitted POST data.
     * Returns null when the class has no conditions defined.
     */
    public static function build_conditions_data( $class_id, $submitted_conditions ) {
        $cond_raw = get_term_meta( $class_id, 'msc_class_conditions', true );
        if ( ! $cond_raw ) return null;
        $conditions = json_decode( $cond_raw, true );
        if ( ! is_array( $conditions ) || empty( $conditions ) ) return null;

        $data = array();
        foreach ( $conditions as $idx => $cond ) {
            $ctype   = isset( $cond['type'] ) ? $cond['type'] : 'confirm';
            $options = isset( $cond['options'] ) && is_array( $cond['options'] ) ? $cond['options'] : array();
            $raw     = isset( $submitted_conditions[ $class_id ][ $idx ] ) ? $submitted_conditions[ $class_id ][ $idx ] : null;

            if ( $ctype === 'confirm' ) {
                $data[ $idx ] = '1';
            } elseif ( $ctype === 'select_one' ) {
                $val = sanitize_text_field( wp_unslash( (string) $raw ) );
                $data[ $idx ] = in_array( $val, $options, true ) ? $val : '';
            } elseif ( $ctype === 'select_many' ) {
                $checked = array();
                foreach ( (array) $raw as $v ) {
                    $sv = sanitize_text_field( wp_unslash( $v ) );
                    if ( in_array( $sv, $options, true ) ) $checked[] = $sv;
                }
                $data[ $idx ] = $checked;
            }
        }
        return $data ?: null;
    }

    /**
     * Return structured conditions data for display (merges stored answers with term meta definitions).
     * Returns [] when no conditions were recorded or the class has no definitions.
     *
     * @param int $reg_id
     * @return array  [['class_id','class_name','conditions'=>[['label','type','options','answer'],...]],...]
     */
    public static function get_conditions_for_display( $reg_id ) {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT class_id, conditions_data FROM {$wpdb->prefix}msc_registration_classes WHERE registration_id = %d",
            $reg_id
        ) );

        $result = array();
        foreach ( $rows as $row ) {
            if ( ! $row->conditions_data ) continue;
            $stored = json_decode( $row->conditions_data, true );
            if ( ! is_array( $stored ) ) continue;

            $cid      = (int) $row->class_id;
            $cond_raw = get_term_meta( $cid, 'msc_class_conditions', true );
            if ( ! $cond_raw ) continue;
            $conditions = json_decode( $cond_raw, true );
            if ( ! is_array( $conditions ) || empty( $conditions ) ) continue;

            $term = get_term( $cid, 'msc_vehicle_class' );
            if ( ! $term || is_wp_error( $term ) ) continue;

            $class_conditions = array();
            foreach ( $conditions as $idx => $cond ) {
                if ( empty( $cond['label'] ) ) continue;
                $class_conditions[] = array(
                    'label'   => $cond['label'],
                    'type'    => isset( $cond['type'] ) ? $cond['type'] : 'confirm',
                    'options' => isset( $cond['options'] ) ? (array) $cond['options'] : array(),
                    'answer'  => isset( $stored[ $idx ] ) ? $stored[ $idx ] : null,
                );
            }
            if ( empty( $class_conditions ) ) continue;

            $result[] = array(
                'class_id'   => $cid,
                'class_name' => $term->name,
                'conditions' => $class_conditions,
            );
        }
        return $result;
    }

    /**
     * Format a single condition answer as a plain-text string suitable for display or PDF output.
     */
    public static function format_condition_answer( $cond ) {
        $type   = $cond['type'];
        $answer = $cond['answer'];
        if ( $type === 'confirm' ) {
            return '✓ Confirmed';
        } elseif ( $type === 'select_one' ) {
            return $answer ?: '—';
        } elseif ( $type === 'select_many' ) {
            return ( is_array( $answer ) && ! empty( $answer ) ) ? implode( ', ', $answer ) : '—';
        }
        return '—';
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
