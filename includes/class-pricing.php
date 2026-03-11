<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class MSC_Pricing {

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'add_submenu' ) );
        add_action( 'wp_ajax_msc_pricing_save_set',   array( __CLASS__, 'ajax_save_set' ) );
        add_action( 'wp_ajax_msc_pricing_delete_set', array( __CLASS__, 'ajax_delete_set' ) );
        add_action( 'wp_ajax_msc_pricing_get_set',    array( __CLASS__, 'ajax_get_set' ) );
    }

    /** ── Access control ─────────────────────────────────────────── */
    private static function can_access() {
        $user = wp_get_current_user();
        return $user && ( current_user_can( 'manage_options' ) || in_array( 'msc_event_creator', (array) $user->roles, true ) );
    }

    /** ── Public API ─────────────────────────────────────────────── */

    public static function get_all_sets() {
        global $wpdb;
        return $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}msc_pricing_sets ORDER BY name ASC" );
    }

    /**
     * Returns fees indexed by class_id: [ class_id => ['primary_fee' => x, 'additional_fee' => y], ... ]
     */
    public static function get_set_fees( $set_id ) {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT class_id, primary_fee, additional_fee FROM {$wpdb->prefix}msc_pricing_set_classes WHERE pricing_set_id = %d",
            (int) $set_id
        ) );
        $out = array();
        foreach ( $rows as $row ) {
            $out[ (int) $row->class_id ] = array(
                'primary_fee'    => (float) $row->primary_fee,
                'additional_fee' => (float) $row->additional_fee,
            );
        }
        return $out;
    }

    /**
     * Returns the fee for a single class in a pricing set.
     * @param int  $set_id     Pricing set ID.
     * @param int  $class_id   Term ID.
     * @param bool $is_primary True for primary class fee, false for additional.
     * @return float
     */
    public static function get_class_fee( $set_id, $class_id, $is_primary ) {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT primary_fee, additional_fee FROM {$wpdb->prefix}msc_pricing_set_classes WHERE pricing_set_id = %d AND class_id = %d",
            (int) $set_id, (int) $class_id
        ) );
        if ( ! $row ) return 0.0;
        return $is_primary ? (float) $row->primary_fee : (float) $row->additional_fee;
    }

    /** ── Admin page ─────────────────────────────────────────────── */

    public static function add_submenu() {
        add_submenu_page(
            'motorsport-club',
            'Pricing Sets',
            'Pricing',
            'manage_options',
            'msc-pricing',
            array( __CLASS__, 'admin_page' )
        );
    }

    public static function admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized.' );
        }
        $sets   = self::get_all_sets();
        $classes_by_type = MSC_Taxonomies::get_classes_by_type();
        $all_classes = array();
        foreach ( $classes_by_type as $type => $classes ) {
            foreach ( $classes as $term_id => $name ) {
                $all_classes[ $term_id ] = array( 'name' => $name, 'type' => $type );
            }
        }
        ?>
        <div class="wrap">
        <h1>Pricing Sets</h1>
        <p class="description">Pricing sets define primary and additional fees per vehicle class. Assign a pricing set to an event to control class fees.</p>

        <h2 style="margin-top:24px">Existing Sets</h2>
        <?php if ( empty( $sets ) ) : ?>
        <p>No pricing sets yet.</p>
        <?php else : ?>
        <table class="widefat striped" style="max-width:600px">
        <thead><tr><th>Name</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ( $sets as $set ) : ?>
        <tr id="set-row-<?php echo (int) $set->id; ?>">
            <td><?php echo esc_html( $set->name ); ?></td>
            <td>
                <button class="button msc-edit-set-btn" data-id="<?php echo (int) $set->id; ?>" data-name="<?php echo esc_attr( $set->name ); ?>">Edit</button>
                <button class="button msc-delete-set-btn" data-id="<?php echo (int) $set->id; ?>" style="color:#d63638;margin-left:4px">Delete</button>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        </table>
        <?php endif; ?>

        <h2 style="margin-top:30px" id="msc-pricing-form-title">Add New Pricing Set</h2>
        <div id="msc-pricing-form" style="background:#fff;border:1px solid #ccd0d4;padding:20px;max-width:900px;border-radius:4px">
        <input type="hidden" id="msc-pricing-set-id" value="">
        <p>
            <label><strong>Set Name</strong><br>
            <input type="text" id="msc-pricing-set-name" class="regular-text" placeholder="e.g. 2026 Season"></label>
        </p>
        <h3>Class Fees</h3>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">
        <?php foreach ( $classes_by_type as $type => $classes ) : ?>
        <div>
        <h4 style="margin-top:0"><?php echo $type === 'Car' ? '🚗 Car Classes' : '🏍 Motorcycle Classes'; ?></h4>
        <table class="widefat" style="font-size:13px">
        <thead><tr>
            <th>Class</th>
            <th>Primary Fee (R)</th>
            <th>Additional Fee (R)</th>
        </tr></thead>
        <tbody>
        <?php foreach ( $classes as $term_id => $class_name ) : ?>
        <tr>
            <td><?php echo esc_html( $class_name ); ?></td>
            <td><input type="number" class="small-text msc-primary-fee" data-class="<?php echo (int) $term_id; ?>" min="0" step="0.01" value="0.00" placeholder="0.00"></td>
            <td><input type="number" class="small-text msc-additional-fee" data-class="<?php echo (int) $term_id; ?>" min="0" step="0.01" value="0.00" placeholder="0.00"></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        </table>
        </div>
        <?php endforeach; ?>
        </div>
        <p style="margin-top:16px">
            <button class="button button-primary" id="msc-pricing-save-btn">Save Pricing Set</button>
            <button class="button" id="msc-pricing-cancel-btn" style="display:none;margin-left:8px">Cancel</button>
            <span id="msc-pricing-msg" style="margin-left:12px;color:#00a32a"></span>
        </p>
        </div>
        </div>

        <script>
        jQuery(function($){
            var nonce = '<?php echo esc_js( wp_create_nonce( 'msc_nonce' ) ); ?>';

            function resetForm() {
                $('#msc-pricing-set-id').val('');
                $('#msc-pricing-set-name').val('');
                $('.msc-primary-fee, .msc-additional-fee').val('0.00');
                $('#msc-pricing-form-title').text('Add New Pricing Set');
                $('#msc-pricing-cancel-btn').hide();
                $('#msc-pricing-msg').text('');
            }

            $('#msc-pricing-cancel-btn').on('click', function(){ resetForm(); });

            $('#msc-pricing-save-btn').on('click', function(){
                var name = $.trim($('#msc-pricing-set-name').val());
                if (!name) { alert('Please enter a set name.'); return; }
                var fees = [];
                $('.msc-primary-fee').each(function(){
                    var classId = $(this).data('class');
                    fees.push({
                        class_id:       classId,
                        primary_fee:    parseFloat($(this).val()) || 0,
                        additional_fee: parseFloat($('.msc-additional-fee[data-class="' + classId + '"]').val()) || 0
                    });
                });
                $.post(ajaxurl, {
                    action: 'msc_pricing_save_set',
                    nonce:  nonce,
                    set_id: $('#msc-pricing-set-id').val(),
                    name:   name,
                    fees:   JSON.stringify(fees)
                }, function(res){
                    if (res.success) {
                        $('#msc-pricing-msg').text(res.data.message).css('color','#00a32a');
                        setTimeout(function(){ location.reload(); }, 800);
                    } else {
                        $('#msc-pricing-msg').text(res.data || 'Error.').css('color','#d63638');
                    }
                });
            });

            $(document).on('click', '.msc-edit-set-btn', function(){
                var id   = $(this).data('id');
                var name = $(this).data('name');
                $('#msc-pricing-set-id').val(id);
                $('#msc-pricing-set-name').val(name);
                $('#msc-pricing-form-title').text('Edit Pricing Set: ' + name);
                $('#msc-pricing-cancel-btn').show();
                // Load fees
                $.post(ajaxurl, { action: 'msc_pricing_get_set', nonce: nonce, set_id: id }, function(res){
                    if (!res.success) return;
                    var fees = res.data.fees;
                    $.each(fees, function(classId, f){
                        $('.msc-primary-fee[data-class="' + classId + '"]').val(parseFloat(f.primary_fee).toFixed(2));
                        $('.msc-additional-fee[data-class="' + classId + '"]').val(parseFloat(f.additional_fee).toFixed(2));
                    });
                    $('html,body').animate({scrollTop: $('#msc-pricing-form').offset().top - 40}, 300);
                });
            });

            $(document).on('click', '.msc-delete-set-btn', function(){
                var id = $(this).data('id');
                if (!confirm('Delete this pricing set? Events using it will have no class fees.')) return;
                $.post(ajaxurl, { action: 'msc_pricing_delete_set', nonce: nonce, set_id: id }, function(res){
                    if (res.success) { $('#set-row-' + id).remove(); }
                    else { alert(res.data || 'Error.'); }
                });
            });
        });
        </script>
        <?php
    }

    /** ── AJAX handlers ──────────────────────────────────────────── */

    public static function ajax_save_set() {
        check_ajax_referer( 'msc_nonce', 'nonce' );
        if ( ! self::can_access() ) wp_send_json_error( 'Unauthorized', 403 );

        global $wpdb;
        $name   = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
        $set_id = absint( $_POST['set_id'] ?? 0 );
        if ( ! $name ) wp_send_json_error( 'Name required.' );

        $fees_raw = stripslashes( $_POST['fees'] ?? '[]' );
        $fees     = json_decode( $fees_raw, true );
        if ( ! is_array( $fees ) ) wp_send_json_error( 'Invalid fees data.' );

        if ( $set_id ) {
            $wpdb->update( "{$wpdb->prefix}msc_pricing_sets", array( 'name' => $name ), array( 'id' => $set_id ), array( '%s' ), array( '%d' ) );
            $wpdb->delete( "{$wpdb->prefix}msc_pricing_set_classes", array( 'pricing_set_id' => $set_id ), array( '%d' ) );
            $msg = 'Pricing set updated.';
        } else {
            $wpdb->insert( "{$wpdb->prefix}msc_pricing_sets", array( 'name' => $name ), array( '%s' ) );
            $set_id = (int) $wpdb->insert_id;
            $msg = 'Pricing set created.';
        }

        foreach ( $fees as $fee ) {
            $class_id       = absint( $fee['class_id'] ?? 0 );
            $primary_fee    = round( floatval( $fee['primary_fee'] ?? 0 ), 2 );
            $additional_fee = round( floatval( $fee['additional_fee'] ?? 0 ), 2 );
            if ( ! $class_id ) continue;
            $wpdb->insert(
                "{$wpdb->prefix}msc_pricing_set_classes",
                array(
                    'pricing_set_id' => $set_id,
                    'class_id'       => $class_id,
                    'primary_fee'    => $primary_fee,
                    'additional_fee' => $additional_fee,
                ),
                array( '%d', '%d', '%f', '%f' )
            );
        }

        wp_send_json_success( array( 'set_id' => $set_id, 'message' => $msg ) );
    }

    public static function ajax_delete_set() {
        check_ajax_referer( 'msc_nonce', 'nonce' );
        if ( ! self::can_access() ) wp_send_json_error( 'Unauthorized', 403 );

        global $wpdb;
        $set_id = absint( $_POST['set_id'] ?? 0 );
        if ( ! $set_id ) wp_send_json_error( 'Invalid ID.' );

        $wpdb->delete( "{$wpdb->prefix}msc_pricing_set_classes", array( 'pricing_set_id' => $set_id ), array( '%d' ) );
        $wpdb->delete( "{$wpdb->prefix}msc_pricing_sets",        array( 'id' => $set_id ),             array( '%d' ) );

        wp_send_json_success();
    }

    public static function ajax_get_set() {
        check_ajax_referer( 'msc_nonce', 'nonce' );
        if ( ! self::can_access() ) wp_send_json_error( 'Unauthorized', 403 );

        $set_id = absint( $_POST['set_id'] ?? 0 );
        if ( ! $set_id ) wp_send_json_error( 'Invalid ID.' );

        wp_send_json_success( array( 'fees' => self::get_set_fees( $set_id ) ) );
    }
}
