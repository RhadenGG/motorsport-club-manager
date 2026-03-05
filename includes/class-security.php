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
