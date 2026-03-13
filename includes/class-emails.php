<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class MSC_Emails {

    public static function init() {
        // Content-Type is set per-send via explicit headers — no global filter needed.
        add_action( 'phpmailer_init', array( __CLASS__, 'configure_smtp' ) );
    }

    /** Configure PHPMailer to use custom SMTP if enabled in settings */
    public static function configure_smtp( $phpmailer ) {
        if ( ! get_option( 'msc_smtp_enabled' ) ) return;

        $phpmailer->isSMTP();
        $phpmailer->Host       = get_option( 'msc_smtp_host' );
        $phpmailer->SMTPAuth   = true;
        $phpmailer->Port       = get_option( 'msc_smtp_port', 587 );
        $phpmailer->Username   = get_option( 'msc_smtp_user' );
        $phpmailer->Password   = defined( 'MSC_SMTP_PASSWORD' ) ? MSC_SMTP_PASSWORD : get_option( 'msc_smtp_pass' );
        $phpmailer->SMTPSecure = get_option( 'msc_smtp_encryption', 'tls' );
        
        // Ensure some servers don't complain about encryption being set to 'none'
        if ( $phpmailer->SMTPSecure === 'none' ) {
            $phpmailer->SMTPSecure = '';
            $phpmailer->SMTPAutoTLS = false;
        }
    }

    /** Wrapper for wp_mail() that logs failures. */
    public static function send_mail( $to, $subject, $body, $headers = array(), $attachments = array() ) {
        $sent = wp_mail( $to, $subject, $body, $headers, $attachments );
        if ( ! $sent ) {
            error_log( 'MSC Email failed: To=' . $to . ' | Subject=' . $subject );
        }
        return $sent;
    }

    public static function get_headers() {
        $from_name    = get_option('msc_email_from_name') ?: get_bloginfo('name');
        $from_address = get_option('msc_email_from_address') ?: get_option('admin_email');
        return array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $from_name . ' <' . $from_address . '>'
        );
    }

    private static function get_reg($reg_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("
            SELECT r.*, r.class_id, p.post_title as event_name, v.post_title as vehicle_name,
                   u.display_name as user_name, u.user_email
            FROM {$wpdb->prefix}msc_registrations r
            LEFT JOIN {$wpdb->posts}  p ON p.ID = r.event_id
            LEFT JOIN {$wpdb->posts}  v ON v.ID = r.vehicle_id
            LEFT JOIN {$wpdb->users}  u ON u.ID = r.user_id
            WHERE r.id = %d
        ", $reg_id));
    }

    public static function wrap( $title, $body ) {
        $site_name     = esc_html( get_bloginfo( 'name' ) );
        $site_url_esc  = esc_html( home_url() );
        $logo_url      = '';
        $custom_logo_id = get_theme_mod( 'custom_logo' );
        if ( $custom_logo_id ) {
            $logo_data = wp_get_attachment_image_src( $custom_logo_id, 'full' );
            if ( $logo_data ) { $logo_url = $logo_data[0]; }
        }

        $header_content = $logo_url
            ? "<img src='" . esc_url( $logo_url ) . "' alt='" . esc_attr( get_bloginfo( 'name' ) ) . "' style='max-height:60px;width:auto'>"
            : "<h1 style='color:#ffffff;margin:0;font-size:22px'>🏁 {$site_name}</h1>";

        return "
        <div style='font-family:Arial,sans-serif;max-width:560px;margin:20px auto;border:1px solid #e0e0e0;border-radius:8px;overflow:hidden;box-shadow:0 4px 12px rgba(0,0,0,0.05)'>
            <div style='background:#2d3436;padding:32px;text-align:center'>
                $header_content
            </div>
            <div style='padding:32px;line-height:1.6;color:#2d3436'>
                <h2 style='color:#2d3436;margin-top:0;font-size:20px;border-bottom:2px solid #f1f2f6;padding-bottom:12px'>$title</h2>
                $body
            </div>
            <div style='background:#f9f9f9;padding:20px 32px;font-size:12px;color:#999;text-align:center;border-top:1px solid #eee'>
                <strong>{$site_name}</strong><br>
                This is an automated message from {$site_url_esc}. Please do not reply.
            </div>
        </div>";
    }

    public static function send_registration_received( $reg_id ) {
        $reg = self::get_reg($reg_id);
        if ( ! $reg || ! $reg->user_email ) return;

        $user_name    = esc_html( $reg->user_name );
        $event_name   = esc_html( $reg->event_name );
        $vehicle_name = esc_html( $reg->vehicle_name );

        $class_name = '—';
        if ( ! empty( $reg->class_id ) ) {
            $term = get_term( $reg->class_id, 'msc_vehicle_class' );
            if ( $term && ! is_wp_error( $term ) ) {
                $class_name = $term->name;
            }
        }
        if ( $class_name === '—' ) {
            $names = MSC_Registration::get_class_names_for_registration( $reg_id );
            if ( ! empty( $names ) ) {
                $class_name = implode( ', ', $names );
            }
        }
        $class_name = esc_html( $class_name );

        $site_name    = get_bloginfo( 'name' );
        $account_url  = esc_url( msc_get_account_url( 'registrations' ) );

        $event_dt   = get_post_meta($reg->event_id,'_msc_event_date',true);
        $date_str   = $event_dt ? date_i18n(get_option('date_format') . ' @ ' . get_option('time_format'), strtotime($event_dt)) : 'TBC';
        $fee_str    = $reg->entry_fee > 0 ? 'R ' . number_format($reg->entry_fee,2) : 'Free';
        $status_msg = $reg->status === 'confirmed' ? 'You are confirmed! See you on the track.' : 'Your entry is <strong>pending approval</strong>. We will notify you once confirmed.';

        $body = "
        <p>Hi {$user_name},</p>
        <p>Thank you for entering <strong>{$event_name}</strong>.</p>
        <table style='width:100%;border-collapse:collapse;margin:20px 0;background:#fdfdfd'>
            <tr><td style='padding:10px;border:1px solid #f1f1f1;color:#888;width:120px'>Event</td><td style='padding:10px;border:1px solid #f1f1f1;font-weight:bold'>{$event_name}</td></tr>
            <tr><td style='padding:10px;border:1px solid #f1f1f1;color:#888'>Date</td><td style='padding:10px;border:1px solid #f1f1f1'>{$date_str}</td></tr>
            <tr><td style='padding:10px;border:1px solid #f1f1f1;color:#888'>Vehicle</td><td style='padding:10px;border:1px solid #f1f1f1'>{$vehicle_name}</td></tr>
            <tr><td style='padding:10px;border:1px solid #f1f1f1;color:#888'>Class</td><td style='padding:10px;border:1px solid #f1f1f1'>{$class_name}</td></tr>
            <tr><td style='padding:10px;border:1px solid #f1f1f1;color:#888'>Entry Fee</td><td style='padding:10px;border:1px solid #f1f1f1'>{$fee_str}</td></tr>
            <tr><td style='padding:10px;border:1px solid #f1f1f1;color:#888'>Indemnity</td><td style='padding:10px;border:1px solid #f1f1f1'>" . ($reg->indemnity_method==='signed'?'✓ Digitally signed':'Will bring physical copy') . "</td></tr>
        </table>
        <p style='margin:20px 0'>$status_msg</p>
        <p style='text-align:center;margin:30px 0'><a href='{$account_url}' style='background:#2d3436;color:#fff;padding:12px 24px;border-radius:6px;text-decoration:none;display:inline-block;font-weight:bold'>View My Registrations</a></p>
        <p>See you at the track!<br>The " . esc_html($site_name) . " team</p>";

        $headers = self::get_headers();

        // Participant confirmation only — no attachments.
        // Admin/creator notification with signed indemnity + PoP is handled by
        // MSC_Indemnity::email_signed_pdf() so they receive a single combined email.
        self::send_mail( $reg->user_email, "Entry Received - {$reg->event_name}", self::wrap("Entry Received", $body), $headers );
    }

    public static function send_confirmation( $reg_id ) {
        $reg = self::get_reg($reg_id);
        if ( ! $reg || ! $reg->user_email ) return;

        $user_name  = esc_html( $reg->user_name );
        $event_name = esc_html( $reg->event_name );
        $site_name  = get_bloginfo( 'name' );
        $pdf_link   = esc_url( add_query_arg( 'msc_indemnity_pdf', $reg_id, home_url() ) );

        $entry_num_block = '';
        if ( ! empty( $reg->entry_number ) ) {
            $entry_num_block = "<div style='background:#d1e7dd;border-left:4px solid #27ae60;padding:14px 20px;margin:20px 0;border-radius:0 6px 6px 0'>"
                . "<span style='display:block;font-size:12px;color:#0a3622;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px'>Your Entry Number</span>"
                . "<span style='font-size:28px;font-weight:700;color:#0a3622'>#" . (int) $reg->entry_number . "</span>"
                . "<span style='display:block;font-size:12px;color:#0a3622;margin-top:4px'>Please quote this number on the day.</span>"
                . "</div>";
        }

        $body = "
        <p>Hi {$user_name},</p>
        <p>Great news — your entry for <strong>{$event_name}</strong> has been <strong style='color:#27ae60'>confirmed</strong>! 🎉</p>
        {$entry_num_block}
        " . ($reg->indemnity_method==='bring' ? "<p><strong>Note:</strong> You selected to bring a physical indemnity form. Please ensure it is printed and signed before arrival.</p><p style='text-align:center;margin:25px 0'><a href='{$pdf_link}' style='background:#2d3436;color:#fff;padding:12px 24px;border-radius:6px;text-decoration:none;display:inline-block;font-weight:bold'>Download Indemnity Form</a></p>" : "<p><a href='{$pdf_link}' style='color:#2d3436;font-weight:bold;text-decoration:underline'>Download your signed indemnity form (PDF)</a></p>") . "
        <p>We look forward to seeing you at the event!</p>
        <p>Best regards,<br>" . esc_html($site_name) . "</p>";

        $headers = self::get_headers();
        self::send_mail( $reg->user_email, "Entry Confirmed - {$reg->event_name}", self::wrap("Entry Confirmed ✓", $body), $headers );
    }

    public static function send_rejection( $reg_id, $reason = '' ) {
        $reg = self::get_reg( $reg_id );
        if ( ! $reg || ! $reg->user_email ) return;

        $user_name   = esc_html( $reg->user_name );
        $event_name  = esc_html( $reg->event_name );
        $site_name   = get_bloginfo( 'name' );
        $account_url = esc_url( msc_get_account_url( 'registrations' ) );

        $reason_block = '';
        if ( $reason !== '' ) {
            $reason_block = "<div style='background:#f8d7da;border-left:4px solid #842029;padding:12px 16px;margin:16px 0;border-radius:0 4px 4px 0'>"
                . "<strong style='display:block;margin-bottom:4px;color:#842029'>Reason:</strong>"
                . "<span style='color:#2d3436'>" . nl2br( esc_html( $reason ) ) . "</span>"
                . "</div>";
        }

        $body = "
        <p>Hi {$user_name},</p>
        <p>We regret to inform you that your entry for <strong>{$event_name}</strong> has been <strong style='color:#842029'>rejected</strong>.</p>
        {$reason_block}
        <p>If you have any questions, please contact the event organiser.</p>
        <p style='text-align:center;margin:30px 0'><a href='{$account_url}' style='background:#2d3436;color:#fff;padding:12px 24px;border-radius:6px;text-decoration:none;display:inline-block;font-weight:bold'>View My Entries</a></p>
        <p>Regards,<br>" . esc_html( $site_name ) . "</p>";

        $headers = self::get_headers();
        self::send_mail( $reg->user_email, "Entry Not Accepted - {$reg->event_name}", self::wrap( 'Entry Not Accepted', $body ), $headers );
    }

    public static function send_cancellation_by_admin( $reg_id ) {
        $reg = self::get_reg( $reg_id );
        if ( ! $reg || ! $reg->user_email ) return;

        $user_name   = esc_html( $reg->user_name );
        $event_name  = esc_html( $reg->event_name );
        $site_name   = get_bloginfo( 'name' );
        $account_url = esc_url( msc_get_account_url( 'registrations' ) );

        $body = "
        <p>Hi {$user_name},</p>
        <p>Your entry for <strong>{$event_name}</strong> has been <strong style='color:#41464b'>cancelled</strong> by the event organiser.</p>
        <p>If you have any questions, please contact the organiser directly.</p>
        <p style='text-align:center;margin:30px 0'><a href='{$account_url}' style='background:#2d3436;color:#fff;padding:12px 24px;border-radius:6px;text-decoration:none;display:inline-block;font-weight:bold'>View My Registrations</a></p>
        <p>Regards,<br>" . esc_html( $site_name ) . "</p>";

        $headers = self::get_headers();
        self::send_mail( $reg->user_email, "Entry Cancelled - {$reg->event_name}", self::wrap( 'Entry Cancelled', $body ), $headers );
    }
}
