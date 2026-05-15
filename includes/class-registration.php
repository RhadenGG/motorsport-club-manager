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
        add_action( 'wp_ajax_msc_upload_pop',                 array( __CLASS__, 'ajax_upload_pop' ) );
        add_action( 'template_redirect',                      array( __CLASS__, 'maybe_serve_pop_file' ) );
        add_action( 'msc_send_registration_notifications',    array( __CLASS__, 'send_registration_notifications' ) );
        add_action( 'msc_retry_pending_notifications',        array( __CLASS__, 'retry_pending_notifications' ) );
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
            if ( ! in_array( strtolower( (string) $check['ext'] ), $allowed_exts, true ) || ! in_array( $check['type'], $allowed_types, true ) ) {
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
            'submission_status'   => $status,
            'entry_fee'           => $total_fee,
            'fee_paid'            => 0,
            'indemnity_method'    => $ind_method,
            'indemnity_full_name' => $ind_full,
            'is_minor'            => $is_minor,
            'parent_name'         => $parent,
            'parent_sig'          => $parent_sig,
            'emergency_name'      => $em_name,
            'emergency_phone'     => $em_phone,
            'pit_crew_1'          => $pit_crew_1,
            'pit_crew_2'          => $pit_crew_2,
            'indemnity_sig'       => $ind_sig,
            'indemnity_date'      => ( $ind_method === 'signed' ) ? gmdate( 'Y-m-d H:i:s' ) : null,
            'notes'               => $notes,
            'pop_file_id'         => $pop_file_id,
            'class_id'            => null, // deprecated — classes stored in junction table
            'created_at'          => gmdate( 'Y-m-d H:i:s' ),
        ), array( '%d','%d','%d','%s','%s','%f','%d','%s','%s','%d','%s','%s','%s','%s','%s','%s','%s','%s','%d','%s','%d','%s' ) );

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

        // Save motorsport details, sponsors, and emergency relationship to user profile
        update_user_meta( $user_id, 'msc_msa_licence', $msa_licence );
        if ( isset( $_POST['sponsors'] ) )      update_user_meta( $user_id, 'msc_sponsors',      $sponsors );
        if ( isset( $_POST['emergency_rel'] ) ) update_user_meta( $user_id, 'msc_emergency_rel', $em_rel );

        if ( $status === 'confirmed' ) self::assign_entry_number( $reg_id );

        $message = ( $status === 'confirmed' )
            ? 'You\'re registered! A confirmation email has been sent.'
            : 'Your entry has been submitted and is awaiting approval. We\'ll email you once confirmed.';

        // Deliver notifications asynchronously so slow SMTP/PDF cannot cause HTTP 0.
        // Fall back to synchronous sending when WP-Cron is disabled so installs that
        // rely on a system cron (or have DISABLE_WP_CRON set) still receive emails.
        if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
            self::send_registration_notifications( $reg_id );
        } else {
            wp_schedule_single_event( time(), 'msc_send_registration_notifications', array( $reg_id ) );
            spawn_cron();
        }

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

    /**
     * Send all post-registration notifications for a single registration.
     * Called by the msc_send_registration_notifications cron action.
     * Each notification type is tracked by its own DB flag so that retries only
     * attempt what has not yet succeeded — preventing duplicate participant emails
     * when a single recipient (e.g. one admin address) caused an earlier failure.
     * notifications_sent=1 is set only when every applicable flag is complete.
     */
    public static function send_registration_notifications( $reg_id ) {
        global $wpdb;
        $reg_id = absint( $reg_id );
        $reg    = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}msc_registrations WHERE id = %d",
            $reg_id
        ) );
        if ( ! $reg ) {
            MSC_Logger::error( 'Notifications', 'Registration not found', array( 'reg_id' => $reg_id ) );
            return;
        }
        // Rejected/cancelled entries should not receive submission notifications.
        // Mark all flags done so the retry cron stops picking this row up.
        if ( in_array( $reg->status, array( 'rejected', 'cancelled' ), true ) ) {
            MSC_Logger::info( 'Notifications', 'Entry is ' . $reg->status . ' — suppressing pending notifications', array( 'reg_id' => $reg_id ) );
            $wpdb->update(
                "{$wpdb->prefix}msc_registrations",
                array( 'notifications_sent' => 1, 'notif_received' => 1, 'notif_confirmed' => 1, 'notif_indemnity' => 1, 'notif_admin' => 1, 'notif_class_reps' => 1 ),
                array( 'id' => $reg_id ),
                array( '%d', '%d', '%d', '%d', '%d', '%d' ),
                array( '%d' )
            );
            return;
        }

        // needs_confirmed uses the live status, not submission_status:
        // - auto-approved entries (status=confirmed from submission): confirmation is sent here
        // - manually approved entries: the approval path calls send_confirmation() directly
        //   and writes notif_confirmed=1 on success; if that fails, this cron retries it
        // submission_status is kept only to render the correct message in the "received" email.
        $sub_status          = $reg->submission_status ?: $reg->status;
        $needs_confirmed     = ( $reg->status === 'confirmed' );
        $needs_indemnity     = ( $reg->indemnity_method === 'signed' );
        $class_rep_email_map  = self::get_class_rep_email_map( $reg_id );
        $class_reps_enabled   = get_post_meta( (int) $reg->event_id, '_msc_notify_class_reps', true ) !== '0';
        $needs_class_reps     = ! empty( $class_rep_email_map ) && $class_reps_enabled;

        // Skip if every applicable notification is already done.
        // notif_admin is always required: admins are notified for every entry type.
        // For signed indemnity the email includes the PDF; for physical copy it is a
        // plain entry summary. notif_indemnity (participant PDF) is signed-only.
        $any_pending = ! $reg->notif_received
            || ( $needs_confirmed && ! $reg->notif_confirmed )
            || ( $needs_indemnity && ! $reg->notif_indemnity )
            || ! $reg->notif_admin
            || ( $needs_class_reps && ! $reg->notif_class_reps );

        if ( ! $any_pending ) {
            if ( ! $reg->notifications_sent ) {
                $wpdb->update( "{$wpdb->prefix}msc_registrations", array( 'notifications_sent' => 1 ), array( 'id' => $reg_id ), array( '%d' ), array( '%d' ) );
            }
            return;
        }

        MSC_Logger::info( 'Notifications', 'Processing notifications', array( 'reg_id' => $reg_id, 'submission_status' => $sub_status ) );

        // ── "Entry received" email to participant ──────────────────────
        if ( ! $reg->notif_received ) {
            // If the confirmation email was already sent (manual approval happened before
            // this cron fired), sending a "pending approval" received email would present
            // stale status to the participant. Skip it and mark as done.
            if ( $reg->notif_confirmed ) {
                $wpdb->update( "{$wpdb->prefix}msc_registrations", array( 'notif_received' => 1 ), array( 'id' => $reg_id ), array( '%d' ), array( '%d' ) );
                $reg->notif_received = 1;
                MSC_Logger::info( 'Notifications', 'notif_received skipped — confirmation already sent', array( 'reg_id' => $reg_id ) );
            } else {
                try {
                    $sent = MSC_Emails::send_registration_received( $reg_id );
                } catch ( \Throwable $e ) {
                    $sent = false;
                    MSC_Logger::error( 'Notifications', 'send_registration_received threw: ' . $e->getMessage(), array( 'reg_id' => $reg_id ) );
                }
                if ( $sent ) {
                    $wpdb->update( "{$wpdb->prefix}msc_registrations", array( 'notif_received' => 1 ), array( 'id' => $reg_id ), array( '%d' ), array( '%d' ) );
                    $reg->notif_received = 1;
                    MSC_Logger::info( 'Notifications', 'notif_received sent', array( 'reg_id' => $reg_id ) );
                } else {
                    MSC_Logger::warning( 'Notifications', 'notif_received failed', array( 'reg_id' => $reg_id ) );
                }
            }
        }

        // ── Confirmation email to participant ─────────────────────────────
        if ( $needs_confirmed && ! $reg->notif_confirmed ) {
            try {
                $sent = MSC_Emails::send_confirmation( $reg_id );
            } catch ( \Throwable $e ) {
                $sent = false;
                MSC_Logger::error( 'Notifications', 'send_confirmation threw: ' . $e->getMessage(), array( 'reg_id' => $reg_id ) );
            }
            if ( $sent ) {
                $wpdb->update( "{$wpdb->prefix}msc_registrations", array( 'notif_confirmed' => 1 ), array( 'id' => $reg_id ), array( '%d' ), array( '%d' ) );
                $reg->notif_confirmed = 1;
                MSC_Logger::info( 'Notifications', 'notif_confirmed sent', array( 'reg_id' => $reg_id ) );
            } else {
                MSC_Logger::warning( 'Notifications', 'notif_confirmed failed', array( 'reg_id' => $reg_id ) );
            }
        }

        // ── Indemnity PDF to participant ───────────────────────────────
        if ( $needs_indemnity && ! $reg->notif_indemnity ) {
            try {
                $sent = MSC_Indemnity::send_indemnity_to_participant( $reg_id );
            } catch ( \Throwable $e ) {
                $sent = false;
                MSC_Logger::error( 'Notifications', 'send_indemnity_to_participant threw: ' . $e->getMessage(), array( 'reg_id' => $reg_id ) );
            }
            if ( $sent ) {
                $wpdb->update( "{$wpdb->prefix}msc_registrations", array( 'notif_indemnity' => 1 ), array( 'id' => $reg_id ), array( '%d' ), array( '%d' ) );
                $reg->notif_indemnity = 1;
                MSC_Logger::info( 'Notifications', 'notif_indemnity sent', array( 'reg_id' => $reg_id ) );
            } else {
                MSC_Logger::warning( 'Notifications', 'notif_indemnity failed', array( 'reg_id' => $reg_id ) );
            }
        }

        // ── Admin entry notification ───────────────────────────────────
        // Sent for every entry type. For signed indemnity the email includes the
        // signed PDF + PoP. For physical-copy entries a plain summary is sent.
        // notif_admin_sent (JSON array) tracks per-recipient delivery for retry dedup.
        if ( ! $reg->notif_admin ) {
            try {
                $already_sent = array();
                if ( ! empty( $reg->notif_admin_sent ) ) {
                    $decoded = json_decode( $reg->notif_admin_sent, true );
                    if ( is_array( $decoded ) ) {
                        $already_sent = $decoded;
                    }
                }

                $result = $needs_indemnity
                    ? MSC_Indemnity::send_indemnity_to_admins( $reg_id, $already_sent )
                    : MSC_Indemnity::send_entry_notification_to_admins( $reg_id, $already_sent );

                if ( ! empty( $result['sent'] ) ) {
                    $all_sent_now = array_values( array_unique( array_merge( $already_sent, $result['sent'] ) ) );
                    $wpdb->update(
                        "{$wpdb->prefix}msc_registrations",
                        array( 'notif_admin_sent' => wp_json_encode( $all_sent_now ) ),
                        array( 'id' => $reg_id ),
                        array( '%s' ),
                        array( '%d' )
                    );
                    $reg->notif_admin_sent = wp_json_encode( $all_sent_now );
                }

                if ( empty( $result['failed'] ) ) {
                    $wpdb->update( "{$wpdb->prefix}msc_registrations", array( 'notif_admin' => 1 ), array( 'id' => $reg_id ), array( '%d' ), array( '%d' ) );
                    $reg->notif_admin = 1;
                    MSC_Logger::info( 'Notifications', 'notif_admin sent', array( 'reg_id' => $reg_id, 'method' => $reg->indemnity_method ) );
                } else {
                    MSC_Logger::warning( 'Notifications', 'notif_admin partial failure', array( 'reg_id' => $reg_id, 'failed' => $result['failed'] ) );
                }
            } catch ( \Throwable $e ) {
                MSC_Logger::error( 'Notifications', 'send_indemnity_to_admins threw: ' . $e->getMessage(), array( 'reg_id' => $reg_id ) );
            }
        }

        // ── Class rep entry notification ──────────────────────────────
        // One email per assigned class rep per unique email address; mirrors the
        // notif_admin_sent per-recipient dedup pattern for partial-failure retry.
        if ( $needs_class_reps && ! $reg->notif_class_reps ) {
            $already_notified = array();
            if ( ! empty( $reg->notif_class_reps_sent ) ) {
                $decoded = json_decode( $reg->notif_class_reps_sent, true );
                if ( is_array( $decoded ) ) $already_notified = $decoded;
            }

            $newly_sent = array();
            $cr_failed  = array();
            foreach ( $class_rep_email_map as $email => $info ) {
                if ( in_array( $email, $already_notified, true ) ) continue;
                try {
                    $sent = MSC_Emails::send_class_rep_notification( $reg_id, $email, $info['name'], $info['entries'] );
                } catch ( \Throwable $e ) {
                    $sent = false;
                    MSC_Logger::error( 'Notifications', 'send_class_rep_notification threw: ' . $e->getMessage(), array( 'reg_id' => $reg_id, 'email' => $email ) );
                }
                if ( $sent ) {
                    $newly_sent[] = $email;
                } else {
                    $cr_failed[] = $email;
                    MSC_Logger::warning( 'Notifications', 'notif_class_reps failed for recipient', array( 'reg_id' => $reg_id, 'email' => $email ) );
                }
            }

            if ( ! empty( $newly_sent ) ) {
                $all_sent_now = array_values( array_unique( array_merge( $already_notified, $newly_sent ) ) );
                $wpdb->update(
                    "{$wpdb->prefix}msc_registrations",
                    array( 'notif_class_reps_sent' => wp_json_encode( $all_sent_now ) ),
                    array( 'id' => $reg_id ),
                    array( '%s' ),
                    array( '%d' )
                );
                $reg->notif_class_reps_sent = wp_json_encode( $all_sent_now );
            }

            if ( empty( $cr_failed ) ) {
                $wpdb->update( "{$wpdb->prefix}msc_registrations", array( 'notif_class_reps' => 1 ), array( 'id' => $reg_id ), array( '%d' ), array( '%d' ) );
                $reg->notif_class_reps = 1;
                MSC_Logger::info( 'Notifications', 'notif_class_reps sent', array( 'reg_id' => $reg_id, 'count' => count( $newly_sent ) ) );
            }
        }

        // ── Mark complete when every applicable notification has succeeded ─
        $all_done = $reg->notif_received
            && ( ! $needs_confirmed || $reg->notif_confirmed )
            && ( ! $needs_indemnity || $reg->notif_indemnity )
            && $reg->notif_admin
            && ( ! $needs_class_reps || $reg->notif_class_reps );

        if ( $all_done ) {
            $wpdb->update( "{$wpdb->prefix}msc_registrations", array( 'notifications_sent' => 1 ), array( 'id' => $reg_id ), array( '%d' ), array( '%d' ) );
            MSC_Logger::info( 'Notifications', 'All notifications complete', array( 'reg_id' => $reg_id ) );
        } else {
            MSC_Logger::warning( 'Notifications', 'Notifications incomplete — retry cron will attempt remainder', array( 'reg_id' => $reg_id ) );
        }
    }

    /**
     * Retry cron callback. Picks up two cases:
     * 1. Submission-time notifications not yet complete (notifications_sent=0).
     * 2. Entries confirmed after submission whose confirmation email failed
     *    (status=confirmed, notif_confirmed=0) — notifications_sent may already be 1.
     * Branch 1 is capped at 48 hours. Branch 2 has no age cap — manual approvals
     * can happen long after submission and transient mail failures must stay recoverable.
     */
    public static function retry_pending_notifications() {
        global $wpdb;
        $pending = $wpdb->get_col(
            "SELECT id FROM {$wpdb->prefix}msc_registrations
             WHERE (
                   -- Submission-time notifications not yet complete.
                   -- 48-hour cap avoids endlessly retrying truly stuck rows.
                   ( notifications_sent = 0
                     AND created_at <= DATE_SUB( UTC_TIMESTAMP(), INTERVAL 2 MINUTE )
                     AND created_at >= DATE_SUB( UTC_TIMESTAMP(), INTERVAL 48 HOUR ) )
                   OR
                   -- Notifications still pending for a confirmed entry (after the first
                   -- branch's 48-hour window has closed).  Two sub-cases share the same
                   -- eligibility guards and are OR-ed inside the outer AND:
                   --
                   --  a) notif_confirmed=0 — confirmation email not yet sent.
                   --     Original purpose: manual-approval flow sent confirmation OK but
                   --     retry is needed when it failed transiently.
                   --
                   --  b) notif_admin=0 AND notifications_sent=0 — admin notification still
                   --     pending on an otherwise-incomplete batch (e.g. entry was stuck when
                   --     admin confirmed it; mark_notification_sent set notif_confirmed=1 but
                   --     the admin notification has never fired).
                   --     notifications_sent=0 guard: old pre-feature physical-copy rows
                   --     already have notifications_sent=1, so they are excluded here and
                   --     will not receive a retroactive admin notification.
                   --
                   -- Eligibility filter (applies to both sub-cases):
                   --  • submission_status='pending' — manual-approval entry; retryable at any
                   --    age because an admin may have just approved it long after submission.
                   --  • created_at within 30 days — all other entries (auto-approved, or
                   --    anything else); bounded so stale transient failures are abandoned.
                   --    notif_received=1 is NOT used as an independent pass here: it carries
                   --    no age information and would allow indefinite retries of old confirmed
                   --    entries whose confirmation or admin-notification step failed once.
                   ( status = 'confirmed'
                     AND (
                       notif_confirmed = 0
                       OR ( notif_admin = 0 AND notifications_sent = 0 )
                     )
                     AND (
                       submission_status = 'pending'
                       OR created_at >= DATE_SUB( UTC_TIMESTAMP(), INTERVAL 30 DAY )
                     ) )
               )
             ORDER BY created_at ASC
             LIMIT 10"
        );
        if ( empty( $pending ) ) return;
        MSC_Logger::info( 'Notifications', 'Retrying pending notifications', array( 'count' => count( $pending ) ) );
        foreach ( $pending as $reg_id ) {
            self::send_registration_notifications( (int) $reg_id );
        }
    }

    /**
     * Build a map of email => ['name', 'classes'] for all class reps assigned to the
     * vehicle classes entered by this registration. Used by send_registration_notifications().
     * Keyed by email to naturally deduplicate reps assigned to multiple classes.
     */
    /**
     * Build a map of email => ['name', 'entries'] for all class reps assigned to the
     * vehicle classes entered by this registration. Used by send_registration_notifications().
     * Keyed by email to naturally deduplicate reps assigned to multiple classes.
     *
     * Each entry in 'entries' is ['class' => string, 'vehicle' => string] so the email
     * can show the vehicle that belongs to each specific class rather than the primary
     * vehicle on the registration record.
     */
    private static function get_class_rep_email_map( $reg_id ) {
        global $wpdb;
        $class_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT class_id, vehicle_id FROM {$wpdb->prefix}msc_registration_classes WHERE registration_id = %d",
            $reg_id
        ) );
        if ( empty( $class_rows ) ) return array();

        // Build class_id => [name, vehicle_label] from the junction rows
        $class_meta = array();
        foreach ( $class_rows as $row ) {
            $cid  = (int) $row->class_id;
            $term = get_term( $cid, 'msc_vehicle_class' );
            $class_meta[ $cid ] = array(
                'name'    => ( $term && ! is_wp_error( $term ) ) ? $term->name : '—',
                'vehicle' => self::format_vehicle_label( (int) $row->vehicle_id ),
            );
        }

        // uid => ['email', 'name', 'entries' => [['class', 'vehicle'], ...]]
        $uid_map = array();
        foreach ( $class_rows as $row ) {
            $cid     = (int) $row->class_id;
            $rep_ids = MSC_Taxonomies::get_class_rep_user_ids( $cid );
            foreach ( $rep_ids as $uid ) {
                if ( ! isset( $uid_map[ $uid ] ) ) {
                    $user = get_userdata( $uid );
                    if ( ! $user || ! $user->user_email ) continue;
                    $uid_map[ $uid ] = array(
                        'email'   => $user->user_email,
                        'name'    => $user->display_name,
                        'entries' => array(),
                    );
                }
                $uid_map[ $uid ]['entries'][] = array(
                    'class'   => $class_meta[ $cid ]['name'],
                    'vehicle' => $class_meta[ $cid ]['vehicle'],
                );
            }
        }

        // Key by email; merge entries from duplicate UID→email mappings
        $result = array();
        foreach ( $uid_map as $info ) {
            $email = $info['email'];
            if ( ! isset( $result[ $email ] ) ) {
                $result[ $email ] = array( 'name' => $info['name'], 'entries' => array() );
            }
            $result[ $email ]['entries'] = array_merge( $result[ $email ]['entries'], $info['entries'] );
        }

        return $result;
    }

    /**
     * Mark a single per-notification flag as sent.
     * Called by external paths (e.g. the manual approval handler) that send
     * notifications directly so the retry cron does not re-send them.
     */
    public static function mark_notification_sent( $reg_id, $col ) {
        global $wpdb;
        $allowed = array( 'notif_received', 'notif_confirmed', 'notif_indemnity', 'notif_admin', 'notif_class_reps' );
        if ( ! in_array( $col, $allowed, true ) ) return;
        $wpdb->update(
            "{$wpdb->prefix}msc_registrations",
            array( $col => 1 ),
            array( 'id' => absint( $reg_id ) ),
            array( '%d' ),
            array( '%d' )
        );
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
    private static function current_user_can_manage_entry( $reg ) {
        if ( current_user_can( 'manage_options' ) ) return true;
        $user = wp_get_current_user();
        if ( ! in_array( 'msc_event_creator', (array) $user->roles, true ) ) return false;
        if ( get_option( 'msc_dashboard_event_access_mode', 'strict' ) === 'shared' ) return true;
        $event = get_post( $reg->event_id );
        return $event && $event->post_type === 'msc_event'
            && (int) $event->post_author === get_current_user_id();
    }

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
        $is_admin_edit = false;
        if ( ! $reg ) {
            $reg = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}msc_registrations WHERE id = %d",
                $reg_id
            ) );
            if ( ! $reg || ! self::current_user_can_manage_entry( $reg ) ) {
                wp_send_json_error( array( 'message' => 'Entry not found or cannot be edited.' ) );
            }
            $is_admin_edit = true;
        }
        if ( ! in_array( $reg->status, array( 'pending', 'confirmed' ), true ) ) {
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

        // User's vehicles for this event — use entrant's ID when admin is editing
        $entrant_id    = $is_admin_edit ? (int) $reg->user_id : $user_id;
        $vehicles_raw  = MSC_Admin_Garage::get_user_vehicles_for_event( $entrant_id, $reg->event_id );
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
            'pit_crew_1'      => $reg->pit_crew_1,
            'pit_crew_2'      => $reg->pit_crew_2,
            'is_admin_edit'   => $is_admin_edit,
            'pop_link'        => $is_admin_edit
                ? add_query_arg( 'msc_pop_reg', $reg->id, msc_get_account_url( 'registrations' ) )
                : '',
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
        $is_admin_edit = false;
        if ( ! $reg ) {
            $reg = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}msc_registrations WHERE id = %d",
                $reg_id
            ) );
            if ( ! $reg || ! self::current_user_can_manage_entry( $reg ) ) {
                wp_send_json_error( array( 'message' => 'Entry not found or cannot be edited.' ) );
            }
            $is_admin_edit = true;
        }
        if ( ! in_array( $reg->status, array( 'pending', 'confirmed' ), true ) ) {
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

        // PoP handling — required for entrants, optional for admins
        $new_pop_file_id = null;
        $pop_requested   = false;

        if ( $difference > 0.005 ) {
            $has_pop_file    = ! empty( $_FILES['pop_file'] ) && ! empty( $_FILES['pop_file']['name'] );
            $requesting_pop  = ! empty( $_POST['request_pop'] );

            if ( ! $is_admin_edit && ! $has_pop_file ) {
                wp_send_json_error( array( 'message' => 'Please upload proof of payment for the additional R ' . number_format( $difference, 2 ) . ' owed.' ) );
            }
            if ( $is_admin_edit && ! $has_pop_file && ! $requesting_pop ) {
                wp_send_json_error( array( 'message' => 'The entry fee increased by R ' . number_format( $difference, 2 ) . '. Please upload proof of payment or check "Request PoP from entrant".' ) );
            }

            if ( $has_pop_file ) {
                $check         = wp_check_filetype_and_ext( $_FILES['pop_file']['tmp_name'], $_FILES['pop_file']['name'] );
                $allowed_exts  = array( 'pdf', 'png', 'jpg', 'jpeg' );
                $allowed_types = array( 'application/pdf', 'image/png', 'image/jpeg' );
                if ( ! in_array( strtolower( (string) $check['ext'] ), $allowed_exts, true ) || ! in_array( $check['type'], $allowed_types, true ) ) {
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
        }
        if ( $is_admin_edit && $difference > 0.005 && ! empty( $_POST['request_pop'] ) ) {
            $pop_requested = true;
        }

        // Persist updated fee, PoP, and status
        $update_data    = array( 'entry_fee' => $new_total );
        $update_formats = array( '%f' );
        if ( $is_admin_edit ) {
            if ( $new_pop_file_id !== null ) {
                // A PoP file was uploaded — always clears any pending request
                $update_data['pop_requested'] = 0;
                $update_formats[]             = '%d';
            } elseif ( $pop_requested ) {
                $update_data['pop_requested'] = 1;
                $update_formats[]             = '%d';
            } elseif ( $difference <= 0.005 && $reg->pop_requested ) {
                // Fee is no longer above the original — any outstanding request is now moot
                $update_data['pop_requested'] = 0;
                $update_formats[]             = '%d';
            }
            // Fee increase: revert confirmed entries to pending and clear paid flag if no new PoP
            if ( $difference > 0.005 ) {
                if ( $reg->status === 'confirmed' ) {
                    $update_data['status'] = 'pending';
                    $update_formats[]      = '%s';
                }
                if ( $new_pop_file_id === null && $reg->fee_paid ) {
                    $update_data['fee_paid'] = 0;
                    $update_formats[]        = '%d';
                }
            }
        } else {
            if ( $reg->status === 'confirmed' ) {
                $update_data['status'] = 'pending';
                $update_formats[]      = '%s';
            }
        }
        if ( $new_pop_file_id !== null ) {
            $pop_slot                  = empty( $reg->pop_file_id ) ? 'pop_file_id' : 'pop_file_id_2';
            $update_data[ $pop_slot ]  = $new_pop_file_id;
            $update_formats[]          = '%d';
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

        // Update pit crew on the registration record
        $new_pit_crew_1 = isset( $_POST['msc_pit_crew_1'] ) ? sanitize_text_field( wp_unslash( $_POST['msc_pit_crew_1'] ) ) : null;
        $new_pit_crew_2 = isset( $_POST['msc_pit_crew_2'] ) ? sanitize_text_field( wp_unslash( $_POST['msc_pit_crew_2'] ) ) : null;
        if ( $new_pit_crew_1 !== null || $new_pit_crew_2 !== null ) {
            $pit_update = array();
            $pit_fmts   = array();
            if ( $new_pit_crew_1 !== null ) { $pit_update['pit_crew_1'] = $new_pit_crew_1; $pit_fmts[] = '%s'; }
            if ( $new_pit_crew_2 !== null ) { $pit_update['pit_crew_2'] = $new_pit_crew_2; $pit_fmts[] = '%s'; }
            $wpdb->update( "{$wpdb->prefix}msc_registrations", $pit_update, array( 'id' => $reg_id ), $pit_fmts, array( '%d' ) );
        }

        if ( $is_admin_edit ) {
            // Actual persisted state (accounts for file-upload clearing a simultaneous checkbox).
            $saved_pop_requested = isset( $update_data['pop_requested'] ) ? (int) $update_data['pop_requested'] : (int) $reg->pop_requested;
            // Only signal "new request" when THIS save set pop_requested to 1 — not when a
            // pre-existing request happens to still be outstanding from a previous edit.
            $newly_requested = isset( $update_data['pop_requested'] ) && (int) $update_data['pop_requested'] === 1;
            $pop_link = $newly_requested
                ? add_query_arg( 'msc_pop_reg', $reg_id, msc_get_account_url( 'registrations' ) )
                : '';
            wp_send_json_success( array(
                'message'       => 'Entry updated successfully.',
                'pop_link'      => $pop_link,
                'pop_requested' => $saved_pop_requested,
            ) );
        } else {
            $msg = ( $reg->status === 'confirmed' )
                ? 'Your entry has been updated and resubmitted for approval.'
                : 'Your entry has been updated successfully.';
            wp_send_json_success( array( 'message' => $msg ) );
        }
    }

    /** Upload a PoP file for an existing registration (entrant or admin). */
    public static function ajax_upload_pop() {
        check_ajax_referer( 'msc_nonce', 'nonce' );
        if ( ! is_user_logged_in() ) wp_send_json_error( array( 'message' => 'Not logged in.' ) );

        $reg_id  = absint( $_POST['reg_id'] ?? 0 );
        $user_id = get_current_user_id();
        if ( ! $reg_id ) wp_send_json_error( array( 'message' => 'Invalid request.' ) );

        global $wpdb;

        $reg = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}msc_registrations WHERE id = %d AND user_id = %d",
            $reg_id, $user_id
        ) );
        $is_own_entry = ( $reg !== null );
        if ( ! $reg ) {
            $reg = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}msc_registrations WHERE id = %d",
                $reg_id
            ) );
            if ( ! $reg || ! self::current_user_can_manage_entry( $reg ) ) {
                wp_send_json_error( array( 'message' => 'Entry not found.' ) );
            }
        }

        // Entrants may only upload when an admin has explicitly requested it
        if ( $is_own_entry && ! $reg->pop_requested ) {
            wp_send_json_error( array( 'message' => 'No proof of payment upload is pending for this entry.' ) );
        }

        if ( ! in_array( $reg->status, array( 'pending', 'confirmed' ), true ) ) {
            wp_send_json_error( array( 'message' => 'Entry cannot be modified.' ) );
        }
        if ( MSC_Results::is_closed( $reg->event_id ) ) {
            wp_send_json_error( array( 'message' => 'This event is closed.' ) );
        }

        if ( empty( $_FILES['pop_file'] ) || empty( $_FILES['pop_file']['name'] ) ) {
            wp_send_json_error( array( 'message' => 'No file provided.' ) );
        }
        $check         = wp_check_filetype_and_ext( $_FILES['pop_file']['tmp_name'], $_FILES['pop_file']['name'] );
        $allowed_exts  = array( 'pdf', 'png', 'jpg', 'jpeg' );
        $allowed_types = array( 'application/pdf', 'image/png', 'image/jpeg' );
        if ( ! in_array( strtolower( (string) $check['ext'] ), $allowed_exts, true ) || ! in_array( $check['type'], $allowed_types, true ) ) {
            wp_send_json_error( array( 'message' => 'File must be a PDF, PNG, or JPG.' ) );
        }
        if ( $_FILES['pop_file']['size'] > 5 * 1024 * 1024 ) {
            wp_send_json_error( array( 'message' => 'File must be under 5MB.' ) );
        }

        $attachment_id = self::upload_pop_file( 'pop_file' );
        if ( is_wp_error( $attachment_id ) ) {
            wp_send_json_error( array( 'message' => 'Upload failed: ' . $attachment_id->get_error_message() ) );
        }

        $slot = empty( $reg->pop_file_id ) ? 'pop_file_id' : 'pop_file_id_2';
        $wpdb->update(
            "{$wpdb->prefix}msc_registrations",
            array( $slot => $attachment_id, 'pop_requested' => 0 ),
            array( 'id'  => $reg_id ),
            array( '%d', '%d' ),
            array( '%d' )
        );

        wp_send_json_success( array( 'message' => 'Proof of payment uploaded successfully.' ) );
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
