<?php
if ( ! defined( 'ABSPATH' ) ) exit;

require_once MSC_PATH . 'includes/lib/class-msc-pdf.php';

class MSC_Indemnity {

    public static function init() {
        add_action( 'template_redirect', array( __CLASS__, 'maybe_output_pdf' ) );
    }

    /* ── PDF download endpoint ──────────────────────────────────────── */
    public static function maybe_output_pdf() {
        if ( ! isset( $_GET['msc_indemnity_pdf'] ) ) return;
        
        $reg_id = intval( $_GET['msc_indemnity_pdf'] );
        if ( ! $reg_id ) wp_die( 'Invalid registration ID.' );

        // 1. Must be logged in
        if ( ! is_user_logged_in() ) {
            auth_redirect(); // Redirect to login instead of just dying
            exit;
        }

        global $wpdb;
        $reg = $wpdb->get_row( $wpdb->prepare(
            "SELECT r.*, p.post_author as event_author 
             FROM {$wpdb->prefix}msc_registrations r
             LEFT JOIN {$wpdb->posts} p ON p.ID = r.event_id
             WHERE r.id=%d", $reg_id
        ) );

        if ( ! $reg ) wp_die( 'Registration not found.' );

        // 2. Security Check: participant, event author, admin, or any event creator with view cap.
        $current_user_id = get_current_user_id();
        $is_owner     = ( (int)$reg->user_id === $current_user_id );
        $is_admin     = current_user_can( 'manage_options' );
        $is_author    = ( (int)$reg->event_author === $current_user_id );
        $is_organizer = current_user_can( 'msc_view_participants' );

        if ( ! $is_owner && ! $is_admin && ! $is_author && ! $is_organizer ) {
            wp_die( 'You do not have permission to view this indemnity form.' );
        }

        $pdf = self::build_pdf( $reg );

        // 3. Clear output buffer to prevent corruption
        if (ob_get_length()) ob_end_clean();

        header( 'Content-Type: application/pdf' );
        header( 'Content-Disposition: inline; filename="indemnity-' . $reg_id . '.pdf"' );
        header( 'Cache-Control: private, no-cache, no-store, must-revalidate' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );
        echo $pdf;
        exit;
    }

    /* ── Build the signed indemnity PDF ─────────────────────────────── */
    public static function build_pdf( $reg ) {
        $event      = get_post( $reg->event_id );
        $vehicle    = get_post( $reg->vehicle_id );
        $user       = get_user_by( 'id', $reg->user_id );
        
        $indem = get_post_meta( $reg->event_id, '_msc_indemnity_text', true );
        if ( ! $indem ) {
            $indem = get_option( 'msc_default_indemnity', msc_get_default_indemnity() );
        }

        $event_date = get_post_meta( $reg->event_id, '_msc_event_date', true );
        $location   = get_post_meta( $reg->event_id, '_msc_event_location', true );
        $site_name  = get_bloginfo( 'name' );

        $logo_path      = '';
        $custom_logo_id = get_theme_mod( 'custom_logo' );
        if ( $custom_logo_id ) {
            $local = get_attached_file( $custom_logo_id );
            if ( $local && file_exists( $local ) ) {
                $logo_path = $local;  // local file preferred — no HTTP request
            } else {
                $src = wp_get_attachment_image_src( $custom_logo_id, 'full' );
                if ( $src ) {
                    $logo_path = $src[0];  // URL fallback for offloaded/CDN media
                }
            }
        }

        $pdf = new MSC_PDF();
        $pdf->add_page();

        $lm  = 50;   // left margin pts
        $rw  = 495;  // content width
        $mid = $lm + $rw / 2;

        // ── Compact Header ──────────────────────────────────────────
        $pdf->set_fill_color( 45, 52, 54 );
        $pdf->rect( 0, 0, 595, 65, 'F' );
        $pdf->set_fill_color( 99, 110, 114 );
        $pdf->rect( 0, 65, 595, 2, 'F' );

        $header_y = 20;
        if ( $logo_path ) {
            $pdf->image_from_file( $logo_path, $lm, $header_y, 35, 35 );
            $text_x = $lm + 50;
        } else {
            $text_x = $lm;
        }

        $pdf->set_text_color( 255, 255, 255 );
        $pdf->set_font_size( 16 );
        $pdf->text_at( $text_x, $header_y + 12, $site_name );
        $pdf->set_font_size( 9 );
        $pdf->text_at( $text_x, $header_y + 26, 'Indemnity & Entry Form' );

        // ── Core Event Info ──────────────────────────────────────────
        $pdf->set_y( 85 );
        $pdf->set_text_color( 45, 52, 54 );
        $pdf->set_font_size( 14 );
        $pdf->write( $lm, $event->post_title, $rw, 14, 18, true );
        
        $pdf->set_font_size( 8 );
        $pdf->set_text_color( 99, 110, 114 );
        $pdf->write( $lm, 'Reference: #' . $reg->id . ' | Status: Confirmed', $rw, 8, 12 );

        $pdf->set_y( $pdf->get_y() + 6 );

        // ── Section: Event & Participant ──────────────────────────────
        // Put core info into tighter tables
        self::section_header( $pdf, $lm, $rw, 'EVENT & PARTICIPANT SUMMARY' );
        
        $make  = $vehicle ? get_post_meta( $vehicle->ID, '_msc_make',       true ) : '';
        $model = $vehicle ? get_post_meta( $vehicle->ID, '_msc_model',      true ) : '';
        $year  = $vehicle ? get_post_meta( $vehicle->ID, '_msc_year',       true ) : '';
        $regn  = $vehicle ? get_post_meta( $vehicle->ID, '_msc_reg_number', true ) : '';

        // Use the class stored at registration time if available
        if ( ! empty( $reg->class_id ) ) {
            $term  = get_term( $reg->class_id, 'msc_vehicle_class' );
            $class = ( $term && ! is_wp_error( $term ) ) ? $term->name : '—';
        } else {
            $names = MSC_Registration::get_class_names_for_registration( $reg->id );
            $class = ! empty( $names ) ? implode( ', ', $names ) : '—';
        }

        $entry_number_display = ! empty( $reg->entry_number ) ? '#' . (int) $reg->entry_number : 'Pending';

        $rows = array(
            'Entry Number' => $entry_number_display,
            'Event Date'   => $event_date ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $event_date ) ) : '—',
            'Location'     => $location ?: '—',
            'Participant'  => $reg->indemnity_full_name ?: $user->display_name,
            'Email'        => $user->user_email,
            'Emergency'    => $reg->emergency_name . ' (' . $reg->emergency_phone . ')',
            'Guardian'     => $reg->is_minor ? $reg->parent_name : 'N/A (Adult)',
            'Vehicle'      => trim( "$year $make $model" ) ?: ( $vehicle ? $vehicle->post_title : '—' ),
            'Reg / Number' => $regn ?: '—',
            'Class'        => $class,
        );
        self::table_rows( $pdf, $lm, $rw, $rows );
        $pdf->set_y( $pdf->get_y() + 10 );

        // ── Section: Indemnity Text ───────────────────────────────────
        self::section_header( $pdf, $lm, $rw, 'INDEMNITY DECLARATION' );

        $pdf->set_text_color( 45, 52, 54 );
        $pdf->set_font_size( 9, 13 ); // 9pt font, 13pt line-height (condensed for indemnity block)
        $start_y = $pdf->get_y();
        $pdf->write( $lm + 10, $indem, $rw - 20, 9, 13 );
        $end_y   = $pdf->get_y();

        $pdf->set_fill_color( 99, 110, 114 );
        $pdf->rect( $lm, $start_y - 12, 1.5, $end_y - $start_y + 12, 'F' );

        $pdf->set_y( $end_y + 15 );

        // ── Section: Signature ─────────────────────────────────────────
        if ( $pdf->get_y() > 640 ) { $pdf->add_page(); }

        self::section_header( $pdf, $lm, $rw, 'SIGNATURE & ACKNOWLEDGEMENT' );

        if ( $reg->indemnity_method === 'signed' && $reg->indemnity_sig ) {
            $sig_date = $reg->indemnity_date
                ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $reg->indemnity_date ) )
                : date_i18n( get_option( 'date_format' ) );

            $sig_y = $pdf->get_y();
            $pdf->set_fill_color( 252, 252, 252 );
            $pdf->rect( $lm, $sig_y, $rw, 85, 'F' );
            $pdf->set_fill_color( 45, 52, 54 );
            $pdf->rect( $lm, $sig_y, $rw, 0.5, 'F' );
            $pdf->rect( $lm, $sig_y + 84.5, $rw, 0.5, 'F' );

            if ( strpos( $reg->indemnity_sig, 'data:image/' ) === 0 ) {
                $pdf->image_from_dataurl( $reg->indemnity_sig, $lm + 10, $sig_y + 5, $rw - 20, 50 );
            } else {
                $pdf->typed_signature( $lm + 10, $sig_y + 15, $reg->indemnity_sig, $rw - 20, 50 );
            }

            // Details below signature
            $pdf->set_y( $sig_y + 60 );
            $pdf->set_text_color( 99, 110, 114 );
            $pdf->set_font_size( 8 );
            $pdf->write( $lm + 10, 'Participant: ' . ($reg->indemnity_full_name ?: $user->display_name), $rw - 20, 8, 12, true );
            $pdf->write( $lm + 10, 'Date Signed: ' . $sig_date, $rw - 20, 8, 12 );

            // Conditional Parent Signature Panel
            if ($reg->is_minor && $reg->parent_sig) {
                $pdf->set_y( $sig_y + 100 );
                if ($pdf->get_y() > 750) $pdf->add_page();
                
                $psig_y = $pdf->get_y();
                $pdf->set_text_color( 45, 52, 54 );
                $pdf->set_font_size( 9 );
                $pdf->write( $lm, 'PARENT / GUARDIAN ACKNOWLEDGEMENT:', $rw, 9, 14, true );
                
                $psig_box_y = $pdf->get_y() + 5;
                $pdf->set_fill_color( 252, 252, 252 );
                $pdf->rect( $lm, $psig_box_y, $rw, 85, 'F' );
                $pdf->set_fill_color( 45, 52, 54 );
                $pdf->rect( $lm, $psig_box_y, $rw, 0.5, 'F' );
                $pdf->rect( $lm, $psig_box_y + 84.5, $rw, 0.5, 'F' );

                if ( strpos( $reg->parent_sig, 'data:image/' ) === 0 ) {
                    $pdf->image_from_dataurl( $reg->parent_sig, $lm + 10, $psig_box_y + 5, $rw - 20, 50 );
                } else {
                    $pdf->typed_signature( $lm + 10, $psig_box_y + 15, $reg->parent_sig, $rw - 20, 50 );
                }
                
                $pdf->set_y( $psig_box_y + 60 );
                $pdf->set_text_color( 99, 110, 114 );
                $pdf->set_font_size( 8 );
                $pdf->write( $lm + 10, 'Parent/Guardian: ' . $reg->parent_name, $rw - 20, 8, 12, true );
                $pdf->write( $lm + 10, 'Date Signed: ' . $sig_date, $rw - 20, 8, 12 );
                
                $pdf->set_y( $psig_box_y + 95 );
            } else {
                $pdf->set_y( $sig_y + 95 );
            }
            
            $pdf->set_text_color( 99, 110, 114 );
            $pdf->set_font_size( 8 );
            
            $acknowledgement = "This document was electronically signed. By signing, the participant (and guardian where applicable) acknowledges all terms of the indemnity declaration";
            $custom_decs = get_option('msc_custom_declarations', '');
            if ( $custom_decs ) {
                $lines = array_filter( array_map( 'trim', explode( "\n", $custom_decs ) ) );
                foreach ( $lines as $line ) {
                    // Strip HTML tags for PDF
                    $clean_line = wp_strip_all_tags( $line );
                    $acknowledgement .= " and " . $clean_line;
                }
            }
            $acknowledgement .= ".";

            $pdf->write( $lm, $acknowledgement, $rw, 8, 12 );

        } else {
            // Blank lines for physical signature
            $pdf->set_text_color( 45, 52, 54 );
            $pdf->set_font_size( 9 );
            
            $sy = $pdf->get_y();
            $pdf->text_at( $lm, $sy + 10, 'Full Names:' );
            $pdf->set_fill_color( 0, 0, 0 );
            $pdf->rect( $lm + 65, $sy + 10, 190, 0.5, 'F' );
            
            $pdf->text_at( $lm + 270, $sy + 10, 'Date:' );
            $pdf->rect( $lm + 300, $sy + 10, 150, 0.5, 'F' );

            $pdf->set_y( $sy + 20 );
            $sy = $pdf->get_y();
            $pdf->text_at( $lm, $sy + 10, 'Emergency Contact Name:' );
            $pdf->rect( $lm + 125, $sy + 10, 130, 0.5, 'F' );
            
            $pdf->text_at( $lm + 270, $sy + 10, 'Contact Number:' );
            $pdf->rect( $lm + 355, $sy + 10, 95, 0.5, 'F' );

            $pdf->set_y( $sy + 20 );
            $sy = $pdf->get_y();
            $pdf->text_at( $lm, $sy + 10, 'Signature:' );
            $pdf->rect( $lm + 55, $sy + 10, 200, 0.5, 'F' );

            // Conditional Minor Section
            if ($reg->is_minor) {
                $pdf->set_y( $sy + 35 );
                $sy = $pdf->get_y();
                $pdf->set_text_color( 45, 52, 54 );
                $pdf->set_font_size( 9 );
                $pdf->write( $lm, 'FOR MINORS (Parent/Guardian to complete):', $rw, 9, 14, true );
                
                $pdf->set_font_size( 9 );
                $pdf->set_y( $pdf->get_y() + 5 );
                $sy = $pdf->get_y();
                $pdf->text_at( $lm, $sy + 10, 'Parent/Guardian Full Names:' );
                $pdf->rect( $lm + 140, $sy + 10, 310, 0.5, 'F' );
                
                $pdf->set_y( $sy + 20 );
                $sy = $pdf->get_y();
                $pdf->text_at( $lm, $sy + 10, 'Parent/Guardian Signature:' );
                $pdf->rect( $lm + 140, $sy + 10, 150, 0.5, 'F' );
                
                $pdf->text_at( $lm + 305, $sy + 10, 'Date:' );
                $pdf->rect( $lm + 335, $sy + 10, 115, 0.5, 'F' );
            }
            
            $pdf->set_y( $sy + 30 );
        }

        // ── Footer ─────────────────────────────────────────────────────
        $pdf->set_fill_color( 45, 52, 54 );
        $pdf->rect( 0, 815, 595, 27, 'F' );
        $pdf->set_text_color( 223, 230, 233 );
        $pdf->set_font_size( 7 );
        $footer_text = $site_name . ' | Generated: ' . date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) . ' | Ref: #' . $reg->id;
        $pdf->text_at( $lm, 824, $footer_text );

        return $pdf->output_string();
    }

    /* ── Helpers ──────────────────────────────────────────────────────── */
    private static function section_header( $pdf, $lm, $rw, $title ) {
        $y = $pdf->get_y();
        $pdf->set_fill_color( 241, 242, 246 );
        $pdf->rect( $lm, $y, $rw, 16, 'F' );
        $pdf->set_text_color( 45, 52, 54 );
        $pdf->set_font_size( 8 );
        $pdf->text_at( $lm + 8, $y + 11, $title );
        $pdf->set_y( $y + 20 );
    }

    private static function table_rows( $pdf, $lm, $rw, $rows ) {
        $col1 = 110;
        foreach ( $rows as $label => $value ) {
            $y = $pdf->get_y();
            $pdf->set_text_color( 120, 120, 120 );
            $pdf->set_font_size( 8 );
            $pdf->text_at( $lm + 8, $y + 10, $label );
            $pdf->set_text_color( 45, 52, 54 );
            $pdf->set_font_size( 8 );
            // Draw value and get new Y
            $pdf->set_y( $y + 10 );
            $pdf->write( $lm + $col1, (string)$value, $rw - $col1 - 10, 8, 12 );
            // Divider
            $pdf->set_fill_color( 245, 245, 245 );
            $pdf->rect( $lm + 8, $pdf->get_y(), $rw - 16, 0.5, 'F' );
            $pdf->set_y( $pdf->get_y() + 4 );
        }
    }

    /* ── Email the signed PDF ─────────────────────────────────────────── */
    public static function email_signed_pdf( $reg_id ) {
        global $wpdb;
        $reg = $wpdb->get_row( $wpdb->prepare(
            "SELECT r.*, u.display_name as user_name, u.user_email, p.post_title as event_name, p.post_author as event_author
             FROM {$wpdb->prefix}msc_registrations r
             LEFT JOIN {$wpdb->users} u ON u.ID = r.user_id
             LEFT JOIN {$wpdb->posts} p ON p.ID = r.event_id
             WHERE r.id = %d", $reg_id
        ) );
        if ( ! $reg ) return;

        $pdf_data = self::build_pdf( $reg );
        $filename = 'indemnity-' . sanitize_title( $reg->event_name ) . '-' . $reg_id . '.pdf';

        // Write to a temp file with the correct .pdf extension.
        // wp_tempnam() always produces a .tmp file, so use get_temp_dir() directly.
        $tmp_dir = get_temp_dir();
        $tmp     = $tmp_dir . wp_unique_filename( $tmp_dir, $filename );
        file_put_contents( $tmp, $pdf_data );

        $user_name  = esc_html( $reg->user_name );
        $event_name = esc_html( $reg->event_name );
        $site_name  = get_bloginfo( 'name' );
        $headers    = MSC_Emails::get_headers();

        // ── Participant: signed indemnity PDF only ──────────────────────
        $participant_message = "
            <p>Hi {$user_name},</p>
            <p>Please find attached your signed indemnity form for <strong>{$event_name}</strong>.</p>
            <p>Please keep this for your records.</p>
            <p>See you at the track!<br>The " . esc_html($site_name) . " Team</p>";

        MSC_Emails::send_mail(
            $reg->user_email,
            'Signed Indemnity Form - ' . $reg->event_name,
            MSC_Emails::wrap( 'Signed Indemnity Form', $participant_message ),
            $headers,
            array( $tmp )
        );

        // ── Admin / Event Creator: indemnity PDF + PoP ──────────────────
        $admin_attachments = array( $tmp );
        $pop_file_id       = ! empty( $reg->pop_file_id ) ? (int) $reg->pop_file_id : 0;
        if ( $pop_file_id ) {
            $pop_path = get_attached_file( $pop_file_id );
            if ( $pop_path && file_exists( $pop_path ) ) {
                $admin_attachments[] = $pop_path;
            }
        }

        $admin_message = "
            <p>A new event entry has been received and the indemnity form has been signed.</p>
            <p><strong>Participant:</strong> {$user_name}<br>
            <strong>Event:</strong> {$event_name}</p>
            <p>The signed indemnity form" . ( $pop_file_id ? ' and proof of payment are' : ' is' ) . " attached. You can also view both documents any time from the entries dashboard.</p>
            <p><a href='" . esc_url( admin_url('admin.php?page=msc-registrations') ) . "'>View in admin dashboard &rarr;</a></p>";

        $admin_subject = 'New Entry: ' . $reg->event_name . ' - ' . $reg->user_name;

        // Collect unique recipients (admin + event creator, deduplicated)
        $admin_email     = get_option( 'admin_email' );
        $recipients      = array( $admin_email );
        $event_author    = get_user_by( 'id', $reg->event_author );
        if ( $event_author && $event_author->user_email && $event_author->user_email !== $admin_email ) {
            $recipients[] = $event_author->user_email;
        }

        foreach ( $recipients as $recipient ) {
            MSC_Emails::send_mail(
                $recipient,
                $admin_subject,
                MSC_Emails::wrap( 'New Entry', $admin_message ),
                $headers,
                $admin_attachments
            );
        }

        // ── Cleanup: delete only the temp indemnity PDF; PoP is kept for dashboard viewing ──
        if ( ! @unlink( $tmp ) ) {
            error_log( 'MSC: Failed to delete temp indemnity PDF: ' . $tmp );
        }
    }
}
