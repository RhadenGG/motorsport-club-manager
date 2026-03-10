<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * MSC_Auth — Custom frontend login, registration, and set-password shortcodes.
 *
 * Shortcodes:
 *   [msc_login]        — Login form.
 *   [msc_register]     — Registration form (username + email; verification email sent automatically).
 *   [msc_set_password] — Set-password form reached after email verification.
 *
 * Settings (stored in wp_options):
 *   msc_login_page_url        — Full URL of the page with [msc_login].
 *   msc_register_page_url     — Full URL of the page with [msc_register].
 *   msc_set_password_page_url — Full URL of the page with [msc_set_password].
 *
 * If msc_set_password_page_url is set, MSC_Security::handle_email_verification()
 * redirects there (with ?key=&login=) instead of to wp-login.php?action=rp.
 */
class MSC_Auth {

    public static function init() {
        add_shortcode( 'msc_login',        array( __CLASS__, 'shortcode_login' ) );
        add_shortcode( 'msc_register',     array( __CLASS__, 'shortcode_register' ) );
        add_shortcode( 'msc_set_password', array( __CLASS__, 'shortcode_set_password' ) );

        add_action( 'init', array( __CLASS__, 'handle_login_form' ),        5 );
        add_action( 'init', array( __CLASS__, 'handle_register_form' ),     5 );
        add_action( 'init', array( __CLASS__, 'handle_set_password_form' ), 5 );

        // Redirect logged-in users away from login/register pages.
        add_action( 'template_redirect', array( __CLASS__, 'redirect_logged_in' ) );
    }

    // ── Internal helpers ──────────────────────────────────────────────────────

    public static function login_url() {
        $url = get_option( 'msc_login_page_url', '' );
        return $url ?: wp_login_url();
    }

    public static function register_url() {
        $url = get_option( 'msc_register_page_url', '' );
        return $url ?: wp_registration_url();
    }

    /**
     * Build the set-password URL with key and login.
     * Uses custom page if configured, else falls back to wp-login.php?action=rp.
     */
    public static function set_password_url( $key, $login ) {
        $base = get_option( 'msc_set_password_page_url', '' );
        if ( $base ) {
            return add_query_arg( array(
                'key'   => rawurlencode( $key ),
                'login' => rawurlencode( $login ),
            ), $base );
        }
        return add_query_arg( array(
            'action' => 'rp',
            'key'    => rawurlencode( $key ),
            'login'  => rawurlencode( $login ),
        ), wp_login_url() );
    }

    private static function error_message( $code ) {
        $messages = array(
            'empty_username'    => 'Please enter a username.',
            'empty_email'       => 'Please enter an email address.',
            'invalid_email'     => 'Please enter a valid email address.',
            'invalid_username'  => 'That username contains invalid characters.',
            'username_taken'    => 'That username is already taken. Please choose another.',
            'email_taken'       => 'An account with that email address already exists.',
            'creation_failed'   => 'Account creation failed. Please try again.',
            'empty_password'    => 'Please enter a password.',
            'password_mismatch' => 'Passwords do not match.',
            'invalid_key'       => 'This link is invalid or has expired. Please register again.',
            'invalid_nonce'     => 'Security check failed. Please try again.',
            'wrong_credentials' => 'Incorrect username or password.',
            'not_verified'      => 'Your email address has not been verified yet. Please check your inbox for the verification link.',
        );
        return isset( $messages[ $code ] ) ? $messages[ $code ] : 'An error occurred. Please try again.';
    }

    // ── Redirect logged-in users ──────────────────────────────────────────────

    public static function redirect_logged_in() {
        if ( ! is_user_logged_in() ) return;

        $login_url    = get_option( 'msc_login_page_url', '' );
        $register_url = get_option( 'msc_register_page_url', '' );
        $set_pw_url   = get_option( 'msc_set_password_page_url', '' );

        $current = strtok( home_url( add_query_arg( null, null ) ), '?' );

        foreach ( array( $login_url, $register_url, $set_pw_url ) as $page ) {
            if ( $page && rtrim( $page, '/' ) === rtrim( $current, '/' ) ) {
                wp_safe_redirect( msc_get_account_url() ?: home_url( '/' ) );
                exit;
            }
        }
    }

    // ── Form handler: Login ───────────────────────────────────────────────────

    public static function handle_login_form() {
        if ( empty( $_POST['msc_login_submit'] ) ) return;
        if ( ! isset( $_POST['msc_login_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['msc_login_nonce'] ), 'msc_login_action' ) ) {
            wp_safe_redirect( add_query_arg( 'msc_auth_err', 'invalid_nonce', wp_get_referer() ?: self::login_url() ) );
            exit;
        }

        $username = sanitize_text_field( wp_unslash( $_POST['msc_username'] ?? '' ) );
        $password = wp_unslash( $_POST['msc_password'] ?? '' );
        $remember = ! empty( $_POST['msc_remember'] );
        $redirect = esc_url_raw( wp_unslash( $_POST['msc_redirect_to'] ?? '' ) );

        $user = wp_signon( array(
            'user_login'    => $username,
            'user_password' => $password,
            'remember'      => $remember,
        ), is_ssl() );

        if ( is_wp_error( $user ) ) {
            $code = $user->get_error_code();
            if ( $code === 'email_not_verified' ) {
                $err = 'not_verified';
            } elseif ( in_array( $code, array( 'incorrect_password', 'invalid_username', 'invalid_email' ), true ) ) {
                $err = 'wrong_credentials';
            } else {
                $err = sanitize_key( $code );
            }
            wp_safe_redirect( add_query_arg( 'msc_auth_err', $err, wp_get_referer() ?: self::login_url() ) );
            exit;
        }

        $dest = ( $redirect && wp_validate_redirect( $redirect ) ) ? $redirect : ( msc_get_account_url() ?: home_url( '/' ) );
        wp_safe_redirect( $dest );
        exit;
    }

    // ── Form handler: Register ────────────────────────────────────────────────

    public static function handle_register_form() {
        if ( empty( $_POST['msc_register_submit'] ) ) return;
        if ( ! isset( $_POST['msc_register_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['msc_register_nonce'] ), 'msc_register_action' ) ) {
            wp_safe_redirect( add_query_arg( 'msc_auth_err', 'invalid_nonce', wp_get_referer() ?: self::register_url() ) );
            exit;
        }

        $username = sanitize_user( wp_unslash( $_POST['msc_reg_username'] ?? '' ) );
        $email    = sanitize_email( wp_unslash( $_POST['msc_reg_email']    ?? '' ) );
        $referer  = wp_get_referer() ?: self::register_url();

        if ( empty( $username ) ) {
            wp_safe_redirect( add_query_arg( 'msc_auth_err', 'empty_username', $referer ) ); exit;
        }
        if ( ! validate_username( $username ) ) {
            wp_safe_redirect( add_query_arg( 'msc_auth_err', 'invalid_username', $referer ) ); exit;
        }
        if ( ! is_email( $email ) ) {
            wp_safe_redirect( add_query_arg( 'msc_auth_err', 'invalid_email', $referer ) ); exit;
        }
        if ( username_exists( $username ) ) {
            wp_safe_redirect( add_query_arg( 'msc_auth_err', 'username_taken', $referer ) ); exit;
        }
        if ( email_exists( $email ) ) {
            wp_safe_redirect( add_query_arg( 'msc_auth_err', 'email_taken', $referer ) ); exit;
        }
        if ( ! get_option( 'users_can_register' ) ) {
            // Honour WP's registration setting.
            wp_safe_redirect( add_query_arg( 'msc_auth_err', 'creation_failed', $referer ) ); exit;
        }

        $temp_pw = wp_generate_password( 24, true, true );
        $user_id = wp_create_user( $username, $temp_pw, $email );

        if ( is_wp_error( $user_id ) ) {
            wp_safe_redirect( add_query_arg( 'msc_auth_err', 'creation_failed', $referer ) ); exit;
        }

        // MSC_Security::on_user_register fires automatically via the user_register hook
        // and sends the verification email. No extra action needed here.

        wp_safe_redirect( add_query_arg( 'msc_reg_success', '1', $referer ) );
        exit;
    }

    // ── Form handler: Set password ────────────────────────────────────────────

    public static function handle_set_password_form() {
        if ( empty( $_POST['msc_setpw_submit'] ) ) return;
        if ( ! isset( $_POST['msc_setpw_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['msc_setpw_nonce'] ), 'msc_setpw_action' ) ) {
            wp_safe_redirect( add_query_arg( 'msc_auth_err', 'invalid_nonce', wp_get_referer() ?: home_url() ) );
            exit;
        }

        $password  = wp_unslash( $_POST['msc_new_password']  ?? '' );
        $password2 = wp_unslash( $_POST['msc_new_password2'] ?? '' );
        $key       = sanitize_text_field( wp_unslash( $_POST['msc_reset_key']   ?? '' ) );
        $login     = sanitize_text_field( wp_unslash( $_POST['msc_reset_login'] ?? '' ) );
        $referer   = wp_get_referer() ?: self::set_password_url( $key, $login );

        if ( empty( $password ) ) {
            wp_safe_redirect( add_query_arg( 'msc_auth_err', 'empty_password', $referer ) ); exit;
        }
        if ( $password !== $password2 ) {
            wp_safe_redirect( add_query_arg( 'msc_auth_err', 'password_mismatch', $referer ) ); exit;
        }

        $user = check_password_reset_key( $key, $login );
        if ( is_wp_error( $user ) ) {
            wp_safe_redirect( add_query_arg( 'msc_auth_err', 'invalid_key', self::register_url() ) ); exit;
        }

        reset_password( $user, $password );
        wp_safe_redirect( add_query_arg( 'msc_pw_set', '1', self::login_url() ) );
        exit;
    }

    // ── Shortcode: Login ──────────────────────────────────────────────────────

    public static function shortcode_login( $atts ) {
        if ( is_user_logged_in() ) {
            return '<div class="msc-notice msc-notice-info">You are already logged in. <a href="' . esc_url( msc_get_account_url() ?: home_url( '/' ) ) . '">Go to your account →</a></div>';
        }

        $error    = isset( $_GET['msc_auth_err'] ) ? sanitize_key( wp_unslash( $_GET['msc_auth_err'] ) ) : '';
        $pw_set   = isset( $_GET['msc_pw_set'] );
        $verified = isset( $_GET['msc_verified'] );
        $resent   = isset( $_GET['msc_resent'] );
        $redirect = esc_url_raw( wp_unslash( $_GET['redirect_to'] ?? '' ) );

        ob_start();
        ?>
        <div class="msc-auth-wrap">
            <div class="msc-auth-card">
                <div class="msc-auth-header">
                    <div class="msc-auth-icon">🔑</div>
                    <h2 class="msc-auth-title">Member Login</h2>
                    <p class="msc-auth-subtitle">Sign in to your account</p>
                </div>
                <div class="msc-auth-body">

                    <?php if ( $pw_set ) : ?>
                        <div class="msc-notice msc-notice-success">✓ Password set successfully. You can now log in below.</div>
                    <?php elseif ( $verified ) : ?>
                        <div class="msc-notice msc-notice-success">✓ Email verified! You can now log in below.</div>
                    <?php elseif ( $resent ) : ?>
                        <div class="msc-notice msc-notice-info">Verification email resent. Please check your inbox.</div>
                    <?php endif; ?>

                    <?php if ( $error ) : ?>
                        <div class="msc-notice msc-notice-error"><?php echo esc_html( self::error_message( $error ) ); ?></div>
                    <?php endif; ?>

                    <form method="post" class="msc-auth-form" novalidate>
                        <?php wp_nonce_field( 'msc_login_action', 'msc_login_nonce' ); ?>
                        <input type="hidden" name="msc_login_submit" value="1" />
                        <?php if ( $redirect ) : ?>
                            <input type="hidden" name="msc_redirect_to" value="<?php echo esc_attr( $redirect ); ?>" />
                        <?php endif; ?>

                        <div class="msc-field">
                            <label for="msc-login-user">Username or Email</label>
                            <input type="text" name="msc_username" id="msc-login-user" required autocomplete="username" />
                        </div>

                        <div class="msc-field">
                            <label for="msc-login-pw">Password</label>
                            <div class="msc-pw-wrap">
                                <input type="password" name="msc_password" id="msc-login-pw" required autocomplete="current-password" />
                                <button type="button" class="msc-pw-toggle" aria-label="Toggle password visibility">👁</button>
                            </div>
                        </div>

                        <div class="msc-auth-row">
                            <label class="msc-checkbox-label">
                                <input type="checkbox" name="msc_remember" value="1" /> Keep me logged in
                            </label>
                            <a href="<?php echo esc_url( wp_lostpassword_url() ); ?>" class="msc-auth-link msc-auth-link-muted">Forgot password?</a>
                        </div>

                        <button type="submit" class="msc-btn msc-auth-submit">Log In →</button>
                    </form>

                    <div class="msc-auth-divider"><span>New here?</span></div>
                    <p class="msc-auth-alt">
                        Don't have an account? <a href="<?php echo esc_url( self::register_url() ); ?>" class="msc-auth-link">Create one for free</a>
                    </p>
                </div>
            </div>
        </div>
        <script>
        (function(){
            document.querySelectorAll('.msc-auth-wrap .msc-pw-toggle').forEach(function(btn){
                btn.addEventListener('click', function(){
                    var input = this.closest('.msc-pw-wrap').querySelector('input');
                    if (!input) return;
                    input.type = input.type === 'password' ? 'text' : 'password';
                    this.textContent = input.type === 'password' ? '👁' : '🙈';
                });
            });
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    // ── Shortcode: Register ───────────────────────────────────────────────────

    public static function shortcode_register( $atts ) {
        if ( is_user_logged_in() ) {
            return '<div class="msc-notice msc-notice-info">You are already logged in. <a href="' . esc_url( msc_get_account_url() ?: home_url( '/' ) ) . '">Go to your account →</a></div>';
        }

        $error   = isset( $_GET['msc_auth_err'] ) ? sanitize_key( wp_unslash( $_GET['msc_auth_err'] ) ) : '';
        $success = isset( $_GET['msc_reg_success'] );

        ob_start();
        ?>
        <div class="msc-auth-wrap">
            <div class="msc-auth-card">
                <div class="msc-auth-header">
                    <div class="msc-auth-icon">🏁</div>
                    <h2 class="msc-auth-title">Create Account</h2>
                    <p class="msc-auth-subtitle">Join the club and start racing</p>
                </div>
                <div class="msc-auth-body">

                    <?php if ( $success ) : ?>
                        <div class="msc-notice msc-notice-success" style="text-align:center; padding:24px;">
                            <div style="font-size:36px; margin-bottom:12px;">✉️</div>
                            <strong style="font-size:17px; display:block; margin-bottom:8px;">Account created!</strong>
                            We've sent a verification email to your address.<br>
                            Click the link in the email to verify your account and set your password.
                        </div>
                        <p class="msc-auth-alt" style="text-align:center; margin-top:20px;">
                            Already verified? <a href="<?php echo esc_url( self::login_url() ); ?>" class="msc-auth-link">Log in here →</a>
                        </p>
                    <?php else : ?>

                        <?php if ( $error ) : ?>
                            <div class="msc-notice msc-notice-error"><?php echo esc_html( self::error_message( $error ) ); ?></div>
                        <?php endif; ?>

                        <form method="post" class="msc-auth-form" novalidate>
                            <?php wp_nonce_field( 'msc_register_action', 'msc_register_nonce' ); ?>
                            <input type="hidden" name="msc_register_submit" value="1" />

                            <div class="msc-field">
                                <label for="msc-reg-username">Username <span class="msc-required">*</span></label>
                                <input type="text" name="msc_reg_username" id="msc-reg-username" required autocomplete="username" />
                                <span class="msc-field-hint">Letters, numbers, spaces, and the characters: _ - . @ are allowed.</span>
                            </div>

                            <div class="msc-field">
                                <label for="msc-reg-email">Email Address <span class="msc-required">*</span></label>
                                <input type="email" name="msc_reg_email" id="msc-reg-email" required autocomplete="email" />
                                <span class="msc-field-hint">A verification link will be sent to this address.</span>
                            </div>

                            <button type="submit" class="msc-btn msc-auth-submit">
                                Create Account &amp; Send Verification →
                            </button>
                        </form>

                        <div class="msc-auth-divider"><span>Have an account?</span></div>
                        <p class="msc-auth-alt">
                            Already registered? <a href="<?php echo esc_url( self::login_url() ); ?>" class="msc-auth-link">Log in here →</a>
                        </p>

                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    // ── Shortcode: Set Password ───────────────────────────────────────────────

    public static function shortcode_set_password( $atts ) {
        if ( is_user_logged_in() ) {
            return '<div class="msc-notice msc-notice-info">You are already logged in.</div>';
        }

        $key   = sanitize_text_field( wp_unslash( $_GET['key']   ?? '' ) );
        $login = sanitize_text_field( wp_unslash( $_GET['login'] ?? '' ) );
        $error = isset( $_GET['msc_auth_err'] ) ? sanitize_key( wp_unslash( $_GET['msc_auth_err'] ) ) : '';

        // Validate the key up front so we can show a clear error if it's expired.
        $valid = false;
        if ( $key && $login ) {
            $check = check_password_reset_key( $key, $login );
            $valid = ! is_wp_error( $check );
        }

        ob_start();
        ?>
        <div class="msc-auth-wrap">
            <div class="msc-auth-card">
                <div class="msc-auth-header">
                    <div class="msc-auth-icon">🔐</div>
                    <h2 class="msc-auth-title">Set Your Password</h2>
                    <p class="msc-auth-subtitle">Choose a secure password for your account</p>
                </div>
                <div class="msc-auth-body">

                    <?php if ( $error ) : ?>
                        <div class="msc-notice msc-notice-error"><?php echo esc_html( self::error_message( $error ) ); ?></div>
                    <?php endif; ?>

                    <?php if ( ! $valid ) : ?>
                        <div class="msc-notice msc-notice-error">
                            This link is invalid or has expired.
                            Please <a href="<?php echo esc_url( self::register_url() ); ?>">register again</a> to receive a new verification email.
                        </div>
                    <?php else : ?>

                        <p class="msc-auth-welcome">
                            Welcome, <strong><?php echo esc_html( $login ); ?></strong>!
                            Your email is verified. Choose a password to complete your registration.
                        </p>

                        <form method="post" class="msc-auth-form" novalidate>
                            <?php wp_nonce_field( 'msc_setpw_action', 'msc_setpw_nonce' ); ?>
                            <input type="hidden" name="msc_setpw_submit"  value="1" />
                            <input type="hidden" name="msc_reset_key"     value="<?php echo esc_attr( $key ); ?>" />
                            <input type="hidden" name="msc_reset_login"   value="<?php echo esc_attr( $login ); ?>" />

                            <div class="msc-field">
                                <label for="msc-pw-new">New Password <span class="msc-required">*</span></label>
                                <div class="msc-pw-wrap">
                                    <input type="password" name="msc_new_password" id="msc-pw-new" required autocomplete="new-password" />
                                    <button type="button" class="msc-pw-toggle" aria-label="Show / hide password">👁</button>
                                </div>
                                <div class="msc-pw-strength-bar" id="msc-pw-bar"><span></span></div>
                                <div class="msc-field-hint" id="msc-pw-strength-label"></div>
                            </div>

                            <div class="msc-field">
                                <label for="msc-pw-confirm">Confirm Password <span class="msc-required">*</span></label>
                                <div class="msc-pw-wrap">
                                    <input type="password" name="msc_new_password2" id="msc-pw-confirm" required autocomplete="new-password" />
                                    <button type="button" class="msc-pw-toggle" aria-label="Show / hide password">👁</button>
                                </div>
                                <div class="msc-field-hint" id="msc-pw-match-label"></div>
                            </div>

                            <button type="submit" class="msc-btn msc-auth-submit" id="msc-setpw-btn" disabled>
                                Set Password &amp; Log In →
                            </button>
                        </form>

                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php if ( $valid ) : ?>
        <script>
        (function(){
            var pw1     = document.getElementById('msc-pw-new');
            var pw2     = document.getElementById('msc-pw-confirm');
            var btn     = document.getElementById('msc-setpw-btn');
            var bar     = document.querySelector('#msc-pw-bar span');
            var sLabel  = document.getElementById('msc-pw-strength-label');
            var mLabel  = document.getElementById('msc-pw-match-label');
            if (!pw1 || !pw2) return;

            function strength(pw) {
                var score = 0;
                if (pw.length >= 8)            score++;
                if (pw.length >= 12)           score++;
                if (/[A-Z]/.test(pw))          score++;
                if (/[0-9]/.test(pw))          score++;
                if (/[^A-Za-z0-9]/.test(pw))   score++;
                return score;
            }

            var labels = ['', 'Very weak', 'Weak', 'Fair', 'Strong', 'Very strong'];
            var colors = ['', '#e94560', '#e67e22', '#f1c40f', '#27ae60', '#16a085'];

            function update() {
                var s      = strength(pw1.value);
                var match  = pw1.value && pw2.value && pw1.value === pw2.value;

                if (pw1.value) {
                    bar.style.width     = (s * 20) + '%';
                    bar.style.background = colors[s] || '#e94560';
                    sLabel.textContent  = 'Strength: ' + (labels[s] || '');
                    sLabel.style.color  = colors[s] || '#555';
                } else {
                    bar.style.width    = '0';
                    sLabel.textContent = '';
                }

                if (pw2.value) {
                    mLabel.textContent = match ? '✓ Passwords match' : '✗ Passwords do not match';
                    mLabel.style.color = match ? '#27ae60' : '#e94560';
                } else {
                    mLabel.textContent = '';
                }

                btn.disabled = !(match && s >= 2);
            }

            pw1.addEventListener('input', update);
            pw2.addEventListener('input', update);

            document.querySelectorAll('.msc-auth-wrap .msc-pw-toggle').forEach(function(t){
                t.addEventListener('click', function(){
                    var input = this.closest('.msc-pw-wrap').querySelector('input');
                    if (!input) return;
                    input.type = input.type === 'password' ? 'text' : 'password';
                    this.textContent = input.type === 'password' ? '👁' : '🙈';
                });
            });
        })();
        </script>
        <?php endif; ?>
        <?php
        return ob_get_clean();
    }
}
