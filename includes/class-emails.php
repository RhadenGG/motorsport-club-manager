<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class MSC_Emails {

    public static function init() {
        add_filter( 'wp_mail_content_type', function(){ return 'text/html'; } );
    }

    private static function get_reg($reg_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("
            SELECT r.*, p.post_title as event_name, v.post_title as vehicle_name,
                   u.display_name as user_name, u.user_email
            FROM {$wpdb->prefix}msc_registrations r
            LEFT JOIN {$wpdb->posts}  p ON p.ID = r.event_id
            LEFT JOIN {$wpdb->posts}  v ON v.ID = r.vehicle_id
            LEFT JOIN {$wpdb->users}  u ON u.ID = r.user_id
            WHERE r.id = %d
        ", $reg_id));
    }

    private static function wrap( $title, $body ) {
        $site = get_bloginfo('name');
        return "
        <div style='font-family:Arial,sans-serif;max-width:560px;margin:0 auto;border:1px solid #e0e0e0;border-radius:8px;overflow:hidden'>
            <div style='background:#1a1a2e;padding:24px;text-align:center'>
                <h1 style='color:#e94560;margin:0;font-size:20px'>🏁 $site</h1>
            </div>
            <div style='padding:24px'>
                <h2 style='color:#1a1a2e;margin-top:0'>$title</h2>
                $body
            </div>
            <div style='background:#f5f5f5;padding:12px 24px;font-size:12px;color:#888;text-align:center'>
                {$site} &bull; This is an automated message, please do not reply.
            </div>
        </div>";
    }

    public static function send_registration_received( $reg_id ) {
        $reg = self::get_reg($reg_id);
        if ( ! $reg ) return;
        $event    = get_post($reg->event_id);
        $event_dt = get_post_meta($reg->event_id,'_msc_event_date',true);
        $date_str = $event_dt ? date('D d F Y @ H:i', strtotime($event_dt)) : 'TBC';
        $fee_str  = $reg->entry_fee > 0 ? 'R '.number_format($reg->entry_fee,2) : 'Free';
        $status_msg = $reg->status === 'confirmed' ? 'You are confirmed! See you on the track.' : 'Your entry is <strong>pending approval</strong>. We will notify you once confirmed.';

        $body = "
        <p>Hi {$reg->user_name},</p>
        <p>Thanks for registering for <strong>{$reg->event_name}</strong>.</p>
        <table style='width:100%;border-collapse:collapse;margin:16px 0'>
            <tr><td style='padding:8px;border:1px solid #eee;color:#888'>Event</td><td style='padding:8px;border:1px solid #eee'>{$reg->event_name}</td></tr>
            <tr><td style='padding:8px;border:1px solid #eee;color:#888'>Date</td><td style='padding:8px;border:1px solid #eee'>{$date_str}</td></tr>
            <tr><td style='padding:8px;border:1px solid #eee;color:#888'>Vehicle</td><td style='padding:8px;border:1px solid #eee'>{$reg->vehicle_name}</td></tr>
            <tr><td style='padding:8px;border:1px solid #eee;color:#888'>Entry Fee</td><td style='padding:8px;border:1px solid #eee'>{$fee_str}</td></tr>
            <tr><td style='padding:8px;border:1px solid #eee;color:#888'>Indemnity</td><td style='padding:8px;border:1px solid #eee'>" . ($reg->indemnity_method==='signed'?'✓ Electronically signed':'Will bring on the day') . "</td></tr>
        </table>
        <p>$status_msg</p>
        <p><a href='".home_url('/my-account/')."' style='background:#e94560;color:#fff;padding:10px 20px;border-radius:4px;text-decoration:none;display:inline-block'>View My Registrations</a></p>
        <p>See you at the track!<br>The {$_SERVER['SERVER_NAME']} team</p>";

        wp_mail( $reg->user_email, "Registration Received — {$reg->event_name}", self::wrap("Registration Received", $body) );

        // Notify admin
        wp_mail( get_option('admin_email'), "New Registration: {$reg->event_name} — {$reg->user_name}",
            self::wrap("New Registration", "<p>New registration from <strong>{$reg->user_name}</strong> for <strong>{$reg->event_name}</strong>.</p><p><a href='".admin_url('admin.php?page=msc-registrations')."'>View in admin →</a></p>")
        );
    }

    public static function send_confirmation( $reg_id ) {
        $reg = self::get_reg($reg_id);
        if ( ! $reg ) return;
        $pdf_link = add_query_arg('msc_indemnity_pdf', $reg_id, home_url());
        $body = "
        <p>Hi {$reg->user_name},</p>
        <p>Great news — your entry for <strong>{$reg->event_name}</strong> has been <strong style='color:green'>confirmed</strong>! 🎉</p>
        <p>Entry No: <strong>#{$reg->id}</strong></p>
        " . ($reg->indemnity_method==='bring' ? "<p><strong>Reminder:</strong> You selected to bring a signed indemnity form on the day. Please ensure you have it completed before arrival.</p><p><a href='{$pdf_link}' style='background:#e94560;color:#fff;padding:10px 20px;border-radius:4px;text-decoration:none;display:inline-block'>Download Indemnity Form</a></p>" : "<p><a href='{$pdf_link}'>Download your signed indemnity form</a></p>") . "
        <p>We look forward to seeing you at the event!</p>";
        wp_mail( $reg->user_email, "Entry Confirmed — {$reg->event_name}", self::wrap("Entry Confirmed ✓", $body) );
    }
}
