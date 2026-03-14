<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class MSC_Admin_Garage {

    public static function init() {
        add_action( 'add_meta_boxes',         array( __CLASS__, 'add_meta_boxes' ) );
        add_action( 'save_post_msc_vehicle',  array( __CLASS__, 'save_meta' ) );
        add_filter( 'manage_msc_vehicle_posts_columns',       array( __CLASS__, 'columns' ) );
        add_action( 'manage_msc_vehicle_posts_custom_column', array( __CLASS__, 'column_data' ), 10, 2 );
        // Allow users to see their own vehicles
        add_filter( 'parse_query', array( __CLASS__, 'limit_to_own_vehicles' ) );
    }

    public static function add_meta_boxes() {
        add_meta_box( 'msc_vehicle_details', 'Vehicle Details', array( __CLASS__, 'meta_box' ), 'msc_vehicle', 'normal', 'high' );
    }

    public static function meta_box( $post ) {
        wp_nonce_field( 'msc_vehicle_save', 'msc_vehicle_nonce' );
        $d = array(
            'make'          => get_post_meta( $post->ID, '_msc_make', true ),
            'model'         => get_post_meta( $post->ID, '_msc_model', true ),
            'year'          => get_post_meta( $post->ID, '_msc_year', true ),
            'color'         => get_post_meta( $post->ID, '_msc_color', true ),
            'type'          => get_post_meta( $post->ID, '_msc_type', true ),
            'comp_number'   => get_post_meta( $post->ID, '_msc_comp_number', true ),
            'notes'         => get_post_meta( $post->ID, '_msc_notes', true ),
        );
        $types = MSC_Taxonomies::get_vehicle_types();
        ?>
        <table class="form-table">
            <tr>
                <th><label>Vehicle Type</label></th>
                <td>
                    <select name="msc_type">
                        <?php foreach($types as $t): ?>
                        <option value="<?php echo esc_attr($t) ?>" <?php selected($d['type'],$t) ?>><?php echo esc_html($t) ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <th><label>Year</label></th>
                <td><input type="number" name="msc_year" value="<?php echo esc_attr($d['year']); ?>" class="small-text" min="1900" max="2099"></td>
            </tr>
            <tr>
                <th><label>Make</label></th>
                <td><input type="text" name="msc_make" value="<?php echo esc_attr($d['make']); ?>" class="regular-text" placeholder="e.g. Toyota"></td>
                <th><label>Model</label></th>
                <td><input type="text" name="msc_model" value="<?php echo esc_attr($d['model']); ?>" class="regular-text" placeholder="e.g. GR86"></td>
            </tr>
            <tr>
                <th><label>Colour</label></th>
                <td><input type="text" name="msc_color" value="<?php echo esc_attr($d['color']); ?>" class="regular-text"></td>
            </tr>
            <tr>
                <th><label>Race Number</label></th>
                <td><input type="text" name="msc_comp_number" value="<?php echo esc_attr($d['comp_number']); ?>" class="regular-text" placeholder="e.g. 42"></td>
                <th><label>Engine Size</label></th>
                <td>
                    <input type="text" name="msc_engine_size" value="<?php echo esc_attr( get_post_meta( $post->ID, '_msc_engine_size', true ) ); ?>" class="regular-text" placeholder="e.g. 1600cc, 2.0L Turbo">
                    <p class="description">Displacement or engine specification — used to determine eligible classes at registration time.</p>
                </td>
            </tr>
            <tr>
                <th><label>Notes</label></th>
                <td colspan="3"><textarea name="msc_notes" rows="3" class="large-text"><?php echo esc_textarea($d['notes']); ?></textarea></td>
            </tr>
        </table>
        <?php
    }

    public static function save_meta( $post_id ) {
        if ( ! isset($_POST['msc_vehicle_nonce']) || ! wp_verify_nonce($_POST['msc_vehicle_nonce'],'msc_vehicle_save') ) return;
        if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
        if ( ! current_user_can('edit_post',$post_id) ) return;

        foreach( array('msc_make','msc_model','msc_year','msc_color','msc_type','msc_comp_number') as $f ) {
            if ( isset($_POST[$f]) ) update_post_meta($post_id,'_'.$f, sanitize_text_field(wp_unslash($_POST[$f])));
        }
        if ( isset($_POST['msc_notes']) ) {
            update_post_meta($post_id,'_msc_notes', sanitize_textarea_field(wp_unslash($_POST['msc_notes'])));
        }
        if ( isset($_POST['msc_engine_size']) ) {
            update_post_meta($post_id,'_msc_engine_size', sanitize_text_field(wp_unslash($_POST['msc_engine_size'])));
        }
    }

    public static function columns($cols) {
        return array_merge($cols, array(
            'vehicle_type'   => 'Type',
            'vehicle_make'   => 'Make/Model',
            'vehicle_engine' => 'Engine Size',
            'vehicle_owner'  => 'Owner',
        ));
    }

    public static function column_data($col, $post_id) {
        switch($col) {
            case 'vehicle_type':
                echo esc_html(get_post_meta($post_id,'_msc_type',true) ?: '—');
                break;
            case 'vehicle_make':
                $make  = get_post_meta($post_id,'_msc_make',true);
                $model = get_post_meta($post_id,'_msc_model',true);
                $year  = get_post_meta($post_id,'_msc_year',true);
                echo esc_html(trim("$year $make $model") ?: '—');
                break;
            case 'vehicle_engine':
                echo esc_html( get_post_meta( $post_id, '_msc_engine_size', true ) ?: '—' );
                break;
            case 'vehicle_owner':
                $post  = get_post($post_id);
                $user  = get_user_by('id',$post->post_author);
                echo $user ? esc_html($user->display_name) : '—';
                break;
        }
    }

    public static function limit_to_own_vehicles($query) {
        global $pagenow;
        if ( is_admin() && $pagenow === 'edit.php' &&
             isset($_GET['post_type']) && $_GET['post_type'] === 'msc_vehicle' &&
             ! current_user_can('manage_options') ) {
            $query->set('author', get_current_user_id());
        }
    }

    /** Get vehicles for a user that match allowed classes (or all if no restriction) */
    public static function get_user_vehicles_for_event( $user_id, $event_id ) {
        // Get allowed classes (stored as string names) and vehicle type for this event
        $allowed_classes  = get_post_meta( $event_id, '_msc_event_classes', true );
        $allowed_classes  = $allowed_classes ? (array) $allowed_classes : array();
        $allowed_type     = get_post_meta( $event_id, '_msc_event_vehicle_type', true ) ?: 'Both';

        $args = array(
            'post_type'   => 'msc_vehicle',
            'post_status' => 'publish',
            'author'      => $user_id,
            'numberposts' => -1,
        );

        // Filter by vehicle type if not Both
        if ( $allowed_type !== 'Both' ) {
            $args['meta_query'][] = array(
                'key'   => '_msc_type',
                'value' => $allowed_type,
            );
        }

        return get_posts($args);
    }
}
