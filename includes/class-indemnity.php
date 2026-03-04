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
        if ( ! is_user_logged_in() ) wp_die( 'Please log in.' );

        global $wpdb;
        $reg = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}msc_registrations WHERE id=%d", $reg_id
        ) );
        if ( ! $reg ) wp_die( 'Registration not found.' );
        if ( $reg->user_id != get_current_user_id() && ! current_user_can( 'manage_options' ) ) wp_die( 'Access denied.' );

        $pdf = self::build_pdf( $reg );

        header( 'Content-Type: application/pdf' );
        header( 'Content-Disposition: inline; filename="indemnity-' . $reg_id . '.pdf"' );
        header( 'Cache-Control: private, max-age=0, must-revalidate' );
        echo $pdf;
        exit;
    }

    /* ── Build the signed indemnity PDF ─────────────────────────────── */
    public static function build_pdf( $reg ) {
        $event      = get_post( $reg->event_id );
        $vehicle    = get_post( $reg->vehicle_id );
        $user       = get_user_by( 'id', $reg->user_id );
        $indem      = get_post_meta( $reg->event_id, '_msc_indemnity_text', true );
        $event_date = get_post_meta( $reg->event_id, '_msc_event_date', true );
        $location   = get_post_meta( $reg->event_id, '_msc_event_location', true );
        $site_name  = get_bloginfo( 'name' );

        $logo_url   = '';
        $custom_logo_id = get_theme_mod( 'custom_logo' );
        if ( $custom_logo_id ) {
            $logo_data = wp_get_attachment_image_src( $custom_logo_id, 'full' );
            if ( $logo_data ) { $logo_url = $logo_data[0]; }
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
        if ( $logo_url ) {
            $pdf->image_from_file( $logo_url, $lm, $header_y, 35, 35 );
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
        
        $make  = get_post_meta( $vehicle->ID, '_msc_make',       true );
        $model = get_post_meta( $vehicle->ID, '_msc_model',      true );
        $year  = get_post_meta( $vehicle->ID, '_msc_year',       true );
        $regn  = get_post_meta( $vehicle->ID, '_msc_reg_number', true );
        $terms = wp_get_post_terms( $vehicle->ID, 'msc_vehicle_class', array( 'fields' => 'names' ) );
        $class = ! empty( $terms ) ? implode( ', ', $terms ) : '—';

        $rows = array(
            'Event Date'   => $event_date ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $event_date ) ) : '—',
            'Location'     => $location ?: '—',
            'Participant'  => $reg->indemnity_full_name ?: $user->display_name,
            'Email'        => $user->user_email,
            'Emergency'    => $reg->emergency_name . ' (' . $reg->emergency_phone . ')',
            'Vehicle'      => trim( "$year $make $model" ) ?: $vehicle->post_title,
            'Reg / Number' => $regn ?: '—',
            'Class'        => $class,
        );
        if ($reg->is_minor) {
            $rows['Guardian'] = $reg->parent_name;
        }
        self::table_rows( $pdf, $lm, $rw, $rows );
        $pdf->set_y( $pdf->get_y() + 10 );

        // ── Section: Indemnity Text ───────────────────────────────────
        self::section_header( $pdf, $lm, $rw, 'INDEMNITY DECLARATION' );

        $pdf->set_text_color( 45, 52, 54 );
        $pdf->set_font_size( 9, 13 ); // Condensed line height
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
            $pdf->write( $lm + 10, 'Signed by: ' . ($reg->indemnity_full_name ?: $user->display_name), $rw - 20, 8, 12, true );
            if ($reg->is_minor) {
                $pdf->write( $lm + 10, 'Parent/Guardian Acknowledgement: ' . $reg->parent_name, $rw - 20, 8, 12, true );
            }
            $pdf->write( $lm + 10, 'Date Signed: ' . $sig_date, $rw - 20, 8, 12 );

            $pdf->set_y( $sig_y + 95 );
            $pdf->set_text_color( 99, 110, 114 );
            $pdf->set_font_size( 8 );
            $pdf->write( $lm, 'This document was electronically signed. By signing, the participant acknowledges all terms of the indemnity declaration.', $rw, 8, 12 );

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
                $pdf->set_font_size( 9, null, true );
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

        // Write to a temp file for wp_mail attachment
        $tmp = wp_tempnam( $filename );
        file_put_contents( $tmp, $pdf_data );

        $subject  = 'Signed Indemnity Form — ' . $reg->event_name;
        $site     = get_bloginfo( 'name' );
        $message  = "
            <p>Hi {$reg->user_name},</p>
            <p>Please find attached your signed indemnity form for <strong>{$reg->event_name}</strong>.</p>
            <p>Please keep this for your records. Entry #<strong>{$reg->id}</strong>.</p>
            <p>See you at the track!<br>The $site Team</p>";

        $headers  = array( 'Content-Type: text/html; charset=UTF-8' );
        $attachments = array( $tmp );

        // Send to participant
        wp_mail( $reg->user_email, $subject, MSC_Emails::wrap("Signed Indemnity Form", $message), $headers, $attachments );

        // Send to admin
        wp_mail( get_option( 'admin_email' ), 'Indemnity Signed: ' . $reg->event_name . ' — ' . $reg->user_name, MSC_Emails::wrap("Indemnity Signed", $message), $headers, $attachments );

        // Send to event author if different from admin
        $event_author = get_user_by( 'id', $reg->event_author );
        if ( $event_author && $event_author->user_email !== get_option( 'admin_email' ) ) {
            wp_mail( $event_author->user_email, 'Indemnity Signed: ' . $reg->event_name . ' — ' . $reg->user_name, MSC_Emails::wrap("Indemnity Signed", $message), $headers, $attachments );
        }

        @unlink( $tmp );
    }
}
