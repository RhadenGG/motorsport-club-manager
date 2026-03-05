<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class MSC_Security {
    public static function init() {
        // Admin profile fields
        add_action( 'show_user_profile',        array( __CLASS__, 'add_birthdate_field' ) );
        add_action( 'edit_user_profile',        array( __CLASS__, 'add_birthdate_field' ) );
        add_action( 'personal_options_update',  array( __CLASS__, 'save_birthdate_field' ) );
        add_action( 'edit_user_profile_update', array( __CLASS__, 'save_birthdate_field' ) );

        // Onboarding redirect on first login
        add_filter( 'login_redirect', array( __CLASS__, 'onboarding_redirect' ), 10, 3 );

        // Login page branding
        add_action( 'login_enqueue_scripts', array( __CLASS__, 'login_logo' ) );
        add_filter( 'login_headerurl',       array( __CLASS__, 'login_logo_url' ) );
        add_filter( 'login_headertext',      array( __CLASS__, 'login_logo_text' ) );

        // Email verification
        add_action( 'user_register',                    array( __CLASS__, 'on_user_register' ) );
        add_filter( 'wp_new_user_notification_email',   array( __CLASS__, 'intercept_wp_notification_email' ), 10, 3 );
        add_filter( 'wp_authenticate_user',             array( __CLASS__, 'check_email_verified' ), 10, 2 );
        add_action( 'init',                             array( __CLASS__, 'handle_email_verification' ) );
        add_filter( 'login_message',                    array( __CLASS__, 'login_verification_notice' ) );
    }

    // ── Login page branding ──────────────────────────────────────────────────

    public static function login_logo() {
        $logo_url = '';
        $logo_id  = get_theme_mod( 'custom_logo' );
        if ( $logo_id ) {
            $src = wp_get_attachment_image_src( $logo_id, 'full' );
            if ( $src ) $logo_url = $src[0];
        }

        if ( ! $logo_url ) return;

        $logo_url_esc = esc_url( $logo_url );
        echo "<style>
            #login h1 a, .login h1 a {
                background-image: url('{$logo_url_esc}');
                background-size: contain;
                background-repeat: no-repeat;
                background-position: center top;
                width: 100%;
                height: 80px;
            }
        </style>";
    }

    public static function login_logo_url() {
        return home_url();
    }

    public static function login_logo_text() {
        return get_bloginfo( 'name' );
    }

    // ── Email verification ───────────────────────────────────────────────────

    /**
     * Suppress WordPress's default "set your password" email.
     * We send our own verification email; a fresh reset key is generated
     * at the moment the user verifies, so no need to capture it here.
     */
    public static function intercept_wp_notification_email( $email_data, $user, $_blogname ) {
        // Only suppress the WP email for self-registered users going through our
        // verification flow. Admin-created users (no msc_email_token) still need
        // the standard WP credential email so they can set their password.
        if ( ! get_user_meta( $user->ID, 'msc_email_token', true ) ) {
            return $email_data;
        }
        $email_data['to'] = '';
        return $email_data;
    }

    public static function on_user_register( $user_id ) {
        // Skip if an admin is creating the user from wp-admin
        if ( current_user_can( 'create_users' ) ) return;

        $token = wp_generate_password( 32, false );
        update_user_meta( $user_id, 'msc_email_token',      $token );
        update_user_meta( $user_id, 'msc_email_verified',   '0' );
        update_user_meta( $user_id, 'msc_verify_last_sent', time() );

        self::send_verification_email( $user_id, $token );
    }

    private static function send_verification_email( $user_id, $token ) {
        $user       = get_userdata( $user_id );
        $site_name  = get_bloginfo( 'name' );
        $verify_url = add_query_arg( array( 'msc_verify' => $token, 'uid' => $user_id ), home_url( '/' ) );

        $name_esc   = esc_html( $user->display_name );
        $site_esc   = esc_html( $site_name );
        $url_esc    = esc_url( $verify_url );

        $body = "
            <p>Hi {$name_esc},</p>
            <p>Thank you for creating an account with <strong>{$site_esc}</strong>.</p>
            <p>Click the button below to verify your email address. You'll then be taken to set your password and complete your registration.</p>
            <p style='text-align:center;margin:30px 0'>
                <a href='{$url_esc}' style='background:#2d3436;color:#fff;padding:12px 24px;border-radius:6px;text-decoration:none;display:inline-block;font-weight:bold'>Verify My Email &amp; Set Password →</a>
            </p>
            <p style='font-size:13px;color:#888'>If you didn't create this account you can safely ignore this email.</p>
        ";

        wp_mail(
            $user->user_email,
            "Verify your email — {$site_name}",
            MSC_Emails::wrap( 'Verify Your Email Address', $body ),
            array( 'Content-Type: text/html; charset=UTF-8' )
        );
    }

    public static function check_email_verified( $user, $password ) {
        if ( is_wp_error( $user ) ) return $user;

        // Never block administrators
        if ( user_can( $user, 'manage_options' ) ) return $user;

        if ( get_user_meta( $user->ID, 'msc_email_verified', true ) === '0' ) {
            $resend_url = esc_url( add_query_arg( 'msc_resend', $user->ID, wp_login_url() ) );
            return new WP_Error(
                'email_not_verified',
                'Your email address has not been verified yet. Please check your inbox for the verification link. ' .
                '<a href="' . $resend_url . '">Resend verification email</a>.'
            );
        }

        return $user;
    }

    public static function handle_email_verification() {
        // Verify token from email link
        if ( isset( $_GET['msc_verify'], $_GET['uid'] ) ) {
            $user_id = absint( $_GET['uid'] );
            $token   = sanitize_text_field( wp_unslash( $_GET['msc_verify'] ) );
            $stored  = get_user_meta( $user_id, 'msc_email_token', true );

            if ( ! $stored || ! hash_equals( $stored, $token ) ) {
                wp_die(
                    'This verification link is invalid or has already been used. Please <a href="' . esc_url( wp_login_url() ) . '">return to login</a> and request a new link.',
                    'Verification Failed',
                    array( 'response' => 400 )
                );
            }

            update_user_meta( $user_id, 'msc_email_verified', '1' );
            delete_user_meta( $user_id, 'msc_email_token' );

            // Generate a fresh password reset key and send user to set-password page
            $user      = get_userdata( $user_id );
            $reset_key = $user ? get_password_reset_key( $user ) : new WP_Error();

            if ( $user && ! is_wp_error( $reset_key ) ) {
                $set_pw_url = add_query_arg( array(
                    'action' => 'rp',
                    'key'    => $reset_key,
                    'login'  => rawurlencode( $user->user_login ),
                ), wp_login_url() );
                wp_safe_redirect( $set_pw_url );
            } else {
                wp_safe_redirect( add_query_arg( 'msc_verified', '1', wp_login_url() ) );
            }
            exit;
        }

        // Resend verification email
        if ( isset( $_GET['msc_resend'] ) ) {
            $user_id = absint( $_GET['msc_resend'] );
            if ( $user_id && get_user_meta( $user_id, 'msc_email_verified', true ) === '0' ) {
                $last = intval( get_user_meta( $user_id, 'msc_verify_last_sent', true ) );
                if ( ! $last || ( time() - $last ) > 120 ) {
                    $token = wp_generate_password( 32, false );
                    update_user_meta( $user_id, 'msc_email_token', $token );
                    update_user_meta( $user_id, 'msc_verify_last_sent', time() );
                    self::send_verification_email( $user_id, $token );
                }
            }
            wp_safe_redirect( add_query_arg( 'msc_resent', '1', wp_login_url() ) );
            exit;
        }
    }

    public static function login_verification_notice( $message ) {
        if ( isset( $_GET['msc_verified'] ) ) {
            $message .= '<div class="message" style="border-left-color:#27ae60">✓ Your email has been verified. You can now log in below.</div>';
        }
        if ( isset( $_GET['msc_resent'] ) ) {
            $message .= '<div class="message">Verification email resent. Please check your inbox.</div>';
        }
        return $message;
    }

    public static function onboarding_redirect( $redirect_to, $requested, $user ) {
        if ( is_wp_error( $user ) || ! ( $user instanceof WP_User ) ) {
            return $redirect_to;
        }

        // Only trigger once per user
        if ( get_user_meta( $user->ID, 'msc_onboarding_prompted', true ) ) {
            return $redirect_to;
        }
        update_user_meta( $user->ID, 'msc_onboarding_prompted', 1 );

        // Only redirect if required profile fields are missing
        $required = array( 'msc_birthday', 'msc_comp_number', 'msc_msa_licence', 'msc_medical_aid', 'msc_medical_aid_number', 'msc_gender' );
        foreach ( $required as $key ) {
            if ( ! get_user_meta( $user->ID, $key, true ) ) {
                return add_query_arg( 'msc_onboarding', '1', msc_get_account_url( 'profile' ) );
            }
        }

        return $redirect_to;
    }

    public static function add_birthdate_field( $user ) {
        ?>
        <h3>Motorsport Club Details</h3>
        <table class="form-table">
            <tr>
                <th><label for="msc_birthday">Date of Birth</label></th>
                <td>
                    <input type="date" name="msc_birthday" id="msc_birthday" value="<?php echo esc_attr( get_the_author_meta( 'msc_birthday', $user->ID ) ); ?>" class="regular-text" />
                    <p class="description">Required for age verification and indemnity forms.</p>
                </td>
            </tr>
            <tr>
                <th><label for="msc_comp_number">Competition Number</label></th>
                <td>
                    <input type="text" name="msc_comp_number" id="msc_comp_number" value="<?php echo esc_attr( get_user_meta( $user->ID, 'msc_comp_number', true ) ); ?>" class="regular-text" />
                    <p class="description">Motorcycle / Car competition number.</p>
                </td>
            </tr>
            <tr>
                <th><label for="msc_msa_licence">MSA License Number</label></th>
                <td>
                    <input type="text" name="msc_msa_licence" id="msc_msa_licence" value="<?php echo esc_attr( get_user_meta( $user->ID, 'msc_msa_licence', true ) ); ?>" class="regular-text" />
                </td>
            </tr>
            <tr>
                <th><label for="msc_medical_aid">Medical Aid Provider</label></th>
                <td>
                    <input type="text" name="msc_medical_aid" id="msc_medical_aid" value="<?php echo esc_attr( get_user_meta( $user->ID, 'msc_medical_aid', true ) ); ?>" class="regular-text" />
                </td>
            </tr>
            <tr>
                <th><label for="msc_medical_aid_number">Medical Aid Number</label></th>
                <td>
                    <input type="text" name="msc_medical_aid_number" id="msc_medical_aid_number" value="<?php echo esc_attr( get_user_meta( $user->ID, 'msc_medical_aid_number', true ) ); ?>" class="regular-text" />
                </td>
            </tr>
            <tr>
                <th><label for="msc_gender">Gender</label></th>
                <td>
                    <select name="msc_gender" id="msc_gender">
                        <option value="">— Select —</option>
                        <option value="male"   <?php selected( get_user_meta( $user->ID, 'msc_gender', true ), 'male' ); ?>>Male</option>
                        <option value="female" <?php selected( get_user_meta( $user->ID, 'msc_gender', true ), 'female' ); ?>>Female</option>
                    </select>
                </td>
            </tr>
        </table>
        <?php
    }

    public static function save_birthdate_field( $user_id ) {
        if ( ! current_user_can( 'edit_user', $user_id ) ) return false;
        $text_fields = array( 'msc_birthday', 'msc_comp_number', 'msc_msa_licence', 'msc_medical_aid', 'msc_medical_aid_number' );
        foreach ( $text_fields as $key ) {
            if ( isset( $_POST[ $key ] ) ) {
                update_user_meta( $user_id, $key, sanitize_text_field( $_POST[ $key ] ) );
            }
        }
        if ( isset( $_POST['msc_gender'] ) && in_array( $_POST['msc_gender'], array( 'male', 'female', '' ), true ) ) {
            update_user_meta( $user_id, 'msc_gender', $_POST['msc_gender'] );
        }
    }
}
