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
        $event   = get_post( $reg->event_id );
        $vehicle = get_post( $reg->vehicle_id );
        $user    = get_user_by( 'id', $reg->user_id );
        $indem   = get_post_meta( $reg->event_id, '_msc_indemnity_text', true );
        $event_date = get_post_meta( $reg->event_id, '_msc_event_date', true );
        $location   = get_post_meta( $reg->event_id, '_msc_event_location', true );
        $site       = get_bloginfo( 'name' );
        $logo_path  = get_post_meta( $reg->event_id, '_msc_logo_path', true ); // optional

        $pdf = new MSC_PDF();
        $pdf->add_page();

        $lm  = 50;   // left margin pts
        $rw  = 495;  // content width
        $mid = $lm + $rw / 2;

        // ── Header bar ────────────────────────────────────────────────
        $pdf->set_fill_color( 26, 26, 46 );   // #1a1a2e
        $pdf->rect( 0, 0, 595, 70, 'F' );

        $pdf->set_fill_color( 233, 69, 96 );  // #e94560
        $pdf->rect( 0, 70, 595, 4, 'F' );

        $pdf->set_text_color( 233, 69, 96 );
        $pdf->set_font_size( 20 );
        $pdf->text_at( $lm, 30, $site );

        $pdf->set_text_color( 255, 255, 255 );
        $pdf->set_font_size( 11 );
        $pdf->text_at( $lm, 52, 'Indemnity & Entry Form' );

        // ── Event title ───────────────────────────────────────────────
        $pdf->set_y( 90 );
        $pdf->set_text_color( 26, 26, 46 );
        $pdf->set_font_size( 16 );
        $pdf->write( $lm, $event->post_title, $rw, 16, 22, true );
        $pdf->set_y( $pdf->get_y() + 4 );

        // ── Section: Event Details ────────────────────────────────────
        self::section_header( $pdf, $lm, $rw, 'EVENT DETAILS' );
        $rows = array(
            'Date'     => $event_date ? date( 'd F Y \a\t H:i', strtotime( $event_date ) ) : '—',
            'Location' => $location ?: '—',
            'Entry #'  => '#' . $reg->id,
        );
        self::table_rows( $pdf, $lm, $rw, $rows );
        $pdf->set_y( $pdf->get_y() + 8 );

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
            'Reg / No'  => $regn ?: '—',
            'Class'     => $class,
        );
        self::table_rows( $pdf, $lm, $rw, $rows );
        $pdf->set_y( $pdf->get_y() + 8 );

        // ── Section: Indemnity Text ───────────────────────────────────
        self::section_header( $pdf, $lm, $rw, 'INDEMNITY DECLARATION' );

        // Light grey box
        $y_before = $pdf->get_y();
        $pdf->set_fill_color( 248, 248, 248 );
        // Write text first to measure height
        $pdf->set_text_color( 60, 60, 60 );
        $pdf->set_font_size( 10, 15 );
        $start_y = $pdf->get_y();
        $pdf->write( $lm + 8, $indem, $rw - 16, 10, 15 );
        $end_y   = $pdf->get_y();

        // Draw the box behind (we've already written text, so it overlaps — draw box first on a fresh page pass)
        // Simpler: just draw a left border line
        $pdf->set_fill_color( 233, 69, 96 );
        $pdf->rect( $lm, $start_y - 14, 3, $end_y - $start_y + 14, 'F' );

        $pdf->set_y( $end_y + 10 );

        // ── Section: Signature ─────────────────────────────────────────
        // Check if we need a new page
        if ( $pdf->get_y() > 680 ) { $pdf->add_page(); }

        self::section_header( $pdf, $lm, $rw, 'SIGNATURE' );

        if ( $reg->indemnity_method === 'signed' && $reg->indemnity_sig ) {
            $sig_date = $reg->indemnity_date
                ? date( 'd F Y \a\t H:i', strtotime( $reg->indemnity_date ) )
                : date( 'd F Y' );

            $pdf->set_text_color( 80, 80, 80 );
            $pdf->set_font_size( 9 );
            $pdf->write( $lm, 'Electronically signed on ' . $sig_date, $rw, 9, 14 );
            $pdf->set_y( $pdf->get_y() + 4 );

            $sig_y = $pdf->get_y();

            // Signature box
            $pdf->set_fill_color( 245, 245, 245 );
            $pdf->rect( $lm, $sig_y, $rw, 60, 'F' );
            $pdf->set_fill_color( 26, 26, 46 );
            $pdf->rect( $lm, $sig_y, $rw, 1.5, 'F' );
            $pdf->rect( $lm, $sig_y + 59, $rw, 1.5, 'F' );

            if ( strpos( $reg->indemnity_sig, 'data:image/' ) === 0 ) {
                // Drawn signature — embed image
                $pdf->image_from_dataurl( $reg->indemnity_sig, $lm + 10, $sig_y + 5, $rw - 20, 50 );
            } else {
                // Typed signature
                $pdf->typed_signature( $lm + 10, $sig_y + 10, $reg->indemnity_sig, $rw - 20, 50 );
            }

            $pdf->set_y( $sig_y + 68 );

            // Confirmation text
            $pdf->set_text_color( 80, 80, 80 );
            $pdf->set_font_size( 9 );
            $pdf->write( $lm, 'By signing above, the participant confirms they have read and agreed to the indemnity declaration.', $rw, 9, 14 );

        } else {
            // Blank signature lines for physical signing
            $pdf->set_text_color( 60, 60, 60 );
            $pdf->set_font_size( 10 );
            $sy = $pdf->get_y();
            $pdf->write( $lm, 'Signature:', $rw );
            $pdf->set_fill_color( 0, 0, 0 );
            $pdf->rect( $lm + 55, $sy + 2, 240, 0.5, 'F' );
            $pdf->set_y( $pdf->get_y() + 12 );
            $sy = $pdf->get_y();
            $pdf->write( $lm, 'Print Name:', $rw );
            $pdf->rect( $lm + 62, $sy + 2, 233, 0.5, 'F' );
            $pdf->set_y( $pdf->get_y() + 12 );
            $sy = $pdf->get_y();
            $pdf->write( $lm, 'Date:', $rw );
            $pdf->rect( $lm + 30, $sy + 2, 150, 0.5, 'F' );
            $pdf->set_y( $pdf->get_y() + 16 );
        }

        // ── Footer ─────────────────────────────────────────────────────
        $pdf->set_fill_color( 26, 26, 46 );
        $pdf->rect( 0, 810, 595, 32, 'F' );
        $pdf->set_text_color( 180, 180, 180 );
        $pdf->set_font_size( 8 );
        $pdf->text_at( $lm, 820, $site . '  |  Entry #' . $reg->id . '  |  Generated ' . date( 'd M Y H:i' ) );

        return $pdf->output_string();
    }

    /* ── Helpers ──────────────────────────────────────────────────────── */
    private static function section_header( $pdf, $lm, $rw, $title ) {
        $y = $pdf->get_y();
        $pdf->set_fill_color( 26, 26, 46 );
        $pdf->rect( $lm, $y, $rw, 18, 'F' );
        $pdf->set_text_color( 233, 69, 96 );
        $pdf->set_font_size( 9 );
        $pdf->text_at( $lm + 8, $y + 12, $title );
        $pdf->set_y( $y + 22 );
    }

    private static function table_rows( $pdf, $lm, $rw, $rows ) {
        $col1 = 100;
        $alt  = false;
        foreach ( $rows as $label => $value ) {
            $y = $pdf->get_y();
            // Alternate row shading
            if ( $alt ) {
                $pdf->set_fill_color( 245, 245, 245 );
                $pdf->rect( $lm, $y - 2, $rw, 16, 'F' );
            }
            $pdf->set_text_color( 120, 120, 120 );
            $pdf->set_font_size( 9 );
            $pdf->text_at( $lm + 6, $y + 10, $label );
            $pdf->set_text_color( 30, 30, 30 );
            $pdf->set_font_size( 9 );
            $pdf->text_at( $lm + $col1, $y + 10, $value );
            $pdf->set_y( $y + 16 );
            $alt = ! $alt;
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
        <div style='font-family:Arial,sans-serif;max-width:560px;margin:0 auto'>
            <div style='background:#1a1a2e;padding:20px;text-align:center'>
                <h2 style='color:#e94560;margin:0'>🏁 $site</h2>
            </div>
            <div style='padding:24px'>
                <p>Hi {$reg->user_name},</p>
                <p>Please find attached your signed indemnity form for <strong>{$reg->event_name}</strong>.</p>
                <p>Please keep this for your records. Entry #<strong>{$reg->id}</strong>.</p>
                <p>See you at the track!<br>The $site Team</p>
            </div>
        </div>";

        $headers  = array( 'Content-Type: text/html; charset=UTF-8' );
        $attachments = array( $tmp );

        // Send to participant
        wp_mail( $reg->user_email, $subject, $message, $headers, $attachments );

        // Send to admin
        wp_mail( get_option( 'admin_email' ), 'Indemnity Signed: ' . $reg->event_name . ' — ' . $reg->user_name, $message, $headers, $attachments );

        // Send to event author if different from admin
        $event_author = get_user_by( 'id', $reg->event_author );
        if ( $event_author && $event_author->user_email !== get_option( 'admin_email' ) ) {
            wp_mail( $event_author->user_email, 'Indemnity Signed: ' . $reg->event_name . ' — ' . $reg->user_name, $message, $headers, $attachments );
        }

        @unlink( $tmp );
    }
}
