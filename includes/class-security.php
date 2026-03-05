<?php
if ( ! defined( 'ABSPATH' ) ) exit;
// Registration is disabled via Settings → General → uncheck "Anyone can register"
// No additional security logic needed.
class MSC_Security {
    public static function init() {
        add_action( 'show_user_profile', array( __CLASS__, 'add_birthdate_field' ) );
        add_action( 'edit_user_profile', array( __CLASS__, 'add_birthdate_field' ) );
        add_action( 'personal_options_update', array( __CLASS__, 'save_birthdate_field' ) );
        add_action( 'edit_user_profile_update', array( __CLASS__, 'save_birthdate_field' ) );
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
        </table>
        <?php
    }

    public static function save_birthdate_field( $user_id ) {
        if ( ! current_user_can( 'edit_user', $user_id ) ) return false;
        if ( isset( $_POST['msc_birthday'] ) ) {
            update_user_meta( $user_id, 'msc_birthday', sanitize_text_field( $_POST['msc_birthday'] ) );
        }
    }
}
