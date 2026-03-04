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

        // Pull Site Logo
        $logo_url   = '';
        $custom_logo_id = get_theme_mod( 'custom_logo' );
        if ( $custom_logo_id ) {
            $logo_data = wp_get_attachment_image_src( $custom_logo_id, 'full' );
            if ( $logo_data ) {
                $logo_url = $logo_data[0];
            }
        }

        $pdf = new MSC_PDF();
        $pdf->add_page();

        $lm  = 50;   // left margin pts
        $rw  = 495;  // content width
        $mid = $lm + $rw / 2;

        // ── Professional Header ───────────────────────────────────────
        $pdf->set_fill_color( 45, 52, 54 ); // #2d3436 - Dark Grey
        $pdf->rect( 0, 0, 595, 80, 'F' );
        
        // Accent Bar
        $pdf->set_fill_color( 99, 110, 114 ); // #636e72 - Muted Grey
        $pdf->rect( 0, 80, 595, 3, 'F' );

        $header_y = 25;
        if ( $logo_url ) {
            // Display logo on the left, shift text
            $pdf->image_from_file( $logo_url, $lm, $header_y, 40, 40 );
            $text_x = $lm + 55;
        } else {
            $text_x = $lm;
        }

        $pdf->set_text_color( 255, 255, 255 );
        $pdf->set_font_size( 18 );
        $pdf->text_at( $text_x, $header_y + 15, $site_name );

        $pdf->set_font_size( 10 );
        $pdf->text_at( $text_x, $header_y + 32, 'Indemnity & Event Entry Confirmation' );

        // ── Event Title & Core Info ──────────────────────────────────
        $pdf->set_y( 105 );
        $pdf->set_text_color( 45, 52, 54 );
        $pdf->set_font_size( 16 );
        $pdf->write( $lm, $event->post_title, $rw, 16, 22, true );
        
        $pdf->set_font_size( 10 );
        $pdf->set_text_color( 99, 110, 114 );
        $pdf->write( $lm, 'Registration Reference: #' . $reg->id . ' | Status: Confirmed', $rw, 10, 16 );

        $pdf->set_y( $pdf->get_y() + 10 );

        // ── Section: Event Details ────────────────────────────────────
        self::section_header( $pdf, $lm, $rw, 'EVENT DETAILS' );
        $rows = array(
            'Date / Time' => $event_date ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $event_date ) ) : '—',
            'Location'    => $location ?: '—',
        );
        self::table_rows( $pdf, $lm, $rw, $rows );
        $pdf->set_y( $pdf->get_y() + 12 );

        // ── Section: Participant Details ──────────────────────────────
        self::section_header( $pdf, $lm, $rw, 'PARTICIPANT DETAILS' );
        $make  = get_post_meta( $vehicle->ID, '_msc_make',       true );
        $model = get_post_meta( $vehicle->ID, '_msc_model',      true );
        $year  = get_post_meta( $vehicle->ID, '_msc_year',       true );
        $regn  = get_post_meta( $vehicle->ID, '_msc_reg_number', true );
        $terms = wp_get_post_terms( $vehicle->ID, 'msc_vehicle_class', array( 'fields' => 'names' ) );
        $class = ! empty( $terms ) ? implode( ', ', $terms ) : '—';
        $rows  = array(
            'Full Name' => $user->display_name,
            'Email'     => $user->user_email,
            'Vehicle'   => trim( "$year $make $model" ) ?: $vehicle->post_title,
            'Registration' => $regn ?: '—',
            'Class'     => $class,
        );
        self::table_rows( $pdf, $lm, $rw, $rows );
        $pdf->set_y( $pdf->get_y() + 12 );

        // ── Section: Indemnity Text ───────────────────────────────────
        self::section_header( $pdf, $lm, $rw, 'INDEMNITY DECLARATION' );

        $pdf->set_text_color( 60, 60, 60 );
        $pdf->set_font_size( 10, 15 );
        $start_y = $pdf->get_y();
        $pdf->write( $lm + 10, $indem, $rw - 20, 10, 15 );
        $end_y   = $pdf->get_y();

        // Left Accent Border for Declaration
        $pdf->set_fill_color( 99, 110, 114 );
        $pdf->rect( $lm, $start_y - 14, 2, $end_y - $start_y + 14, 'F' );

        $pdf->set_y( $end_y + 20 );

        // ── Section: Signature ─────────────────────────────────────────
        if ( $pdf->get_y() > 650 ) { $pdf->add_page(); }

        self::section_header( $pdf, $lm, $rw, 'SIGNATURE & ACKNOWLEDGEMENT' );

        if ( $reg->indemnity_method === 'signed' && $reg->indemnity_sig ) {
            $sig_date = $reg->indemnity_date
                ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $reg->indemnity_date ) )
                : date_i18n( get_option( 'date_format' ) );

            $pdf->set_text_color( 80, 80, 80 );
            $pdf->set_font_size( 9 );
            $pdf->write( $lm, 'Digitally signed and accepted on ' . $sig_date, $rw, 9, 14 );
            $pdf->set_y( $pdf->get_y() + 5 );

            $sig_y = $pdf->get_y();

            // Signature box
            $pdf->set_fill_color( 250, 250, 250 );
            $pdf->rect( $lm, $sig_y, $rw, 70, 'F' );
            $pdf->set_fill_color( 45, 52, 54 );
            $pdf->rect( $lm, $sig_y, $rw, 1, 'F' );
            $pdf->rect( $lm, $sig_y + 69, $rw, 1, 'F' );

            if ( strpos( $reg->indemnity_sig, 'data:image/' ) === 0 ) {
                $pdf->image_from_dataurl( $reg->indemnity_sig, $lm + 10, $sig_y + 10, $rw - 20, 50 );
            } else {
                $pdf->typed_signature( $lm + 10, $sig_y + 15, $reg->indemnity_sig, $rw - 20, 50 );
            }

            $pdf->set_y( $sig_y + 80 );
            $pdf->set_text_color( 80, 80, 80 );
            $pdf->set_font_size( 9 );
            $pdf->write( $lm, 'The participant hereby acknowledges that the electronic signature above is binding and carries the same legal weight as a physical signature.', $rw, 9, 14 );

        } else {
            // Blank lines for physical signature
            $pdf->set_text_color( 60, 60, 60 );
            $pdf->set_font_size( 10 );
            
            $pdf->write( $lm, 'Signature:', $rw );
            $sy = $pdf->get_y() - 15;
            $pdf->set_fill_color( 0, 0, 0 );
            $pdf->rect( $lm + 60, $sy + 12, 250, 0.5, 'F' );
            
            $pdf->set_y( $pdf->get_y() + 10 );
            $pdf->write( $lm, 'Print Name:', $rw );
            $sy = $pdf->get_y() - 15;
            $pdf->rect( $lm + 70, $sy + 12, 240, 0.5, 'F' );
            
            $pdf->set_y( $pdf->get_y() + 10 );
            $pdf->write( $lm, 'Date:', $rw );
            $sy = $pdf->get_y() - 15;
            $pdf->rect( $lm + 40, $sy + 12, 150, 0.5, 'F' );
        }

        // ── Footer ─────────────────────────────────────────────────────
        $pdf->set_fill_color( 45, 52, 54 );
        $pdf->rect( 0, 810, 595, 32, 'F' );
        $pdf->set_text_color( 223, 230, 233 );
        $pdf->set_font_size( 8 );
        $footer_text = $site_name . ' | ' . get_bloginfo( 'wpurl' ) . ' | Generated: ' . date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
        $pdf->text_at( $lm, 822, $footer_text );

        return $pdf->output_string();
    }

    /* ── Helpers ──────────────────────────────────────────────────────── */
    private static function section_header( $pdf, $lm, $rw, $title ) {
        $y = $pdf->get_y();
        $pdf->set_fill_color( 241, 242, 246 ); // Very light grey
        $pdf->rect( $lm, $y, $rw, 20, 'F' );
        $pdf->set_text_color( 45, 52, 54 );
        $pdf->set_font_size( 9, null, true );
        $pdf->write( $lm + 10, $title, $rw - 20, 9, 20, true );
        $pdf->set_y( $y + 24 );
    }

    private static function table_rows( $pdf, $lm, $rw, $rows ) {
        $col1 = 120;
        foreach ( $rows as $label => $value ) {
            $y = $pdf->get_y();
            $pdf->set_text_color( 120, 120, 120 );
            $pdf->set_font_size( 9 );
            $pdf->text_at( $lm + 10, $y + 10, $label );
            $pdf->set_text_color( 45, 52, 54 );
            $pdf->set_font_size( 9, null, false );
            $pdf->write( $lm + $col1, (string)$value, $rw - $col1 - 10, 9, 16 );
            // Bottom border for each row
            $pdf->set_fill_color( 223, 230, 233 );
            $pdf->rect( $lm + 10, $pdf->get_y(), $rw - 20, 0.5, 'F' );
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
