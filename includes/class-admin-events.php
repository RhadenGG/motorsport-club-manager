<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class MSC_Admin_Events {

    public static function init() {
        add_action( 'admin_menu',            array( __CLASS__, 'add_menu' ) );
        add_action( 'admin_menu',            array( __CLASS__, 'reorder_submenu' ), 999 );
        add_action( 'admin_notices',         array( __CLASS__, 'account_page_notice' ) );
        add_action( 'add_meta_boxes',        array( __CLASS__, 'add_meta_boxes' ) );
        add_action( 'save_post_msc_event',   array( __CLASS__, 'save_meta' ) );
        add_action( 'admin_enqueue_scripts',  array( __CLASS__, 'enqueue_media' ) );
        add_filter( 'manage_msc_event_posts_columns',       array( __CLASS__, 'columns' ) );
        add_action( 'manage_msc_event_posts_custom_column', array( __CLASS__, 'column_data' ), 10, 2 );
    }

    public static function add_menu() {
        add_menu_page(
            'Motorsport Club', 'Motorsport Club', 'manage_options',
            'motorsport-club', array( __CLASS__, 'dashboard_page' ),
            'dashicons-flag', 30
        );
        // Rename the auto-generated first submenu from "Motorsport Club" to "Dashboard"
        add_submenu_page( 'motorsport-club', 'Dashboard', 'Dashboard', 'manage_options', 'motorsport-club' );
        add_submenu_page( 'motorsport-club', 'Registrations','Registrations','manage_options','msc-registrations', array( __CLASS__, 'registrations_page' ) );
        add_submenu_page( 'motorsport-club', 'Vehicle Classes','Vehicle Classes','manage_options','edit-tags.php?taxonomy=msc_vehicle_class&post_type=msc_vehicle' );
        add_submenu_page( 'motorsport-club', 'Settings',      'Settings',     'manage_options','msc-settings',      array( __CLASS__, 'settings_page' ) );
    }

    public static function reorder_submenu() {
        global $submenu;
        if ( ! isset( $submenu['motorsport-club'] ) ) return;

        $order = array(
            'motorsport-club',                                                  // Dashboard
            'edit.php?post_type=msc_event',                                     // Racing Events
            'msc-registrations',                                                // Registrations
            'msc-participants',                                                 // Participants
            'edit.php?post_type=msc_vehicle',                                   // Vehicles
            'edit-tags.php?taxonomy=msc_vehicle_class&post_type=msc_vehicle',   // Vehicle Classes
            'msc-pricing',                                                      // Pricing
            'msc-settings',                                                     // Settings
        );

        $sorted = array();
        foreach ( $order as $slug ) {
            foreach ( $submenu['motorsport-club'] as $key => $item ) {
                if ( $item[2] === $slug ) {
                    $sorted[] = $item;
                    break;
                }
            }
        }
        // Append any items not in our order list
        foreach ( $submenu['motorsport-club'] as $item ) {
            if ( ! in_array( $item[2], $order, true ) ) {
                $sorted[] = $item;
            }
        }
        $submenu['motorsport-club'] = $sorted;
    }

    public static function account_page_notice() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        if ( get_option( 'msc_account_page_url', '' ) ) return;

        $settings_url = admin_url( 'admin.php?page=msc-settings' );
        echo '<div class="notice notice-warning is-dismissible"><p>';
        echo '<strong>Motorsport Club Manager:</strong> ';
        echo 'You need to create a page with the <code>[msc_my_account]</code> shortcode, then set its URL in ';
        echo '<a href="' . esc_url( $settings_url ) . '">Motorsport Club → Settings → Account Page URL</a>.';
        echo '</p></div>';
    }

    public static function enqueue_media( $hook ) {
        if ( in_array($hook, array('post.php','post-new.php')) ) {
            wp_enqueue_media();
        }
    }

    public static function dashboard_page() {
        global $wpdb;
        $total_events   = wp_count_posts('msc_event')->publish;
        $total_vehicles = wp_count_posts('msc_vehicle')->publish;
        $total_regs     = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}msc_registrations");
        $pending_regs   = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}msc_registrations WHERE status='pending'");
        ?>
        <div class="wrap">
        <h1>🏁 Motorsport Club — Dashboard</h1>
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-top:20px;">
        <?php
        $cards = array(
            array('label'=>'Upcoming Events',   'value'=>$total_events,   'color'=>'#2271b1'),
                       array('label'=>'Vehicles in Garage','value'=>$total_vehicles, 'color'=>'#00a32a'),
                       array('label'=>'Total Registrations','value'=>$total_regs,    'color'=>'#8c00d4'),
                       array('label'=>'Pending Approval',  'value'=>$pending_regs,   'color'=>'#d63638'),
        );
        foreach ( $cards as $c ) : ?>
            <div style="background:#fff;border-left:4px solid <?php echo $c['color']; ?>;padding:20px;border-radius:4px;box-shadow:0 1px 4px rgba(0,0,0,.1)">
            <div style="font-size:2em;font-weight:700;color:<?php echo $c['color']; ?>"><?php echo esc_html($c['value']); ?></div>
            <div style="color:#555;margin-top:4px"><?php echo esc_html($c['label']); ?></div>
            </div>
            <?php endforeach; ?>
            </div>
            <p style="margin-top:30px">
            <a class="button button-primary" href="<?php echo esc_url( admin_url('post-new.php?post_type=msc_event') ); ?>">+ Add New Event</a>
            <a class="button" href="<?php echo esc_url( admin_url('admin.php?page=msc-registrations') ); ?>" style="margin-left:8px">View All Registrations</a>
            </p>
            </div>
            <?php
    }

    public static function add_meta_boxes() {
        add_meta_box( 'msc_event_details', 'Event Details', array( __CLASS__, 'meta_box_details' ), 'msc_event', 'normal', 'high' );
        add_meta_box( 'msc_event_classes', 'Allowed Vehicle Classes', array( __CLASS__, 'meta_box_classes' ), 'msc_event', 'side', 'default' );
    }

    public static function meta_box_details( $post ) {
        wp_nonce_field( 'msc_event_save', 'msc_event_nonce' );
        $d = array(
            'event_date'     => get_post_meta( $post->ID, '_msc_event_date', true ),
                   'event_end_date' => get_post_meta( $post->ID, '_msc_event_end_date', true ),
                   'event_location' => get_post_meta( $post->ID, '_msc_event_location', true ),
                   'entry_fee'      => get_post_meta( $post->ID, '_msc_entry_fee', true ),
                   'capacity'       => get_post_meta( $post->ID, '_msc_capacity', true ),
                   'approval'       => get_post_meta( $post->ID, '_msc_approval', true ) ?: 'instant',
                   'reg_open'       => get_post_meta( $post->ID, '_msc_reg_open', true ),
                   'reg_close'      => get_post_meta( $post->ID, '_msc_reg_close', true ),
                   'indemnity_text' => get_post_meta( $post->ID, '_msc_indemnity_text', true ),
        );
        ?>
        <table class="form-table" style="width:100%">
        <tr>
        <th><label>Start Date & Time</label></th>
        <td><input type="datetime-local" name="msc_event_date" value="<?php echo esc_attr($d['event_date']); ?>" class="regular-text"></td>
        <th><label>End Date & Time</label></th>
        <td><input type="datetime-local" name="msc_event_end_date" value="<?php echo esc_attr($d['event_end_date']); ?>" class="regular-text"></td>
        </tr>
        <tr>
        <th><label>Location / Venue</label></th>
        <td colspan="3"><input type="text" name="msc_event_location" value="<?php echo esc_attr($d['event_location']); ?>" class="large-text"></td>
        </tr>
        <tr>
        <th><label>Base Admin Fee</label></th>
        <td><input type="number" name="msc_entry_fee" value="<?php echo esc_attr($d['entry_fee']); ?>" min="0" step="0.01" class="small-text" placeholder="0.00"> <span class="description">(Added to primary class fee)</span></td>
        <th><label>Capacity (max entries)</label></th>
        <td><input type="number" name="msc_capacity" value="<?php echo esc_attr($d['capacity']); ?>" min="0" class="small-text" placeholder="Unlimited"></td>
        </tr>
        <tr>
        <th><label>Registration Opens</label></th>
        <td><input type="datetime-local" name="msc_reg_open" value="<?php echo esc_attr($d['reg_open']); ?>" class="regular-text"></td>
        <th><label>Registration Closes</label></th>
        <td><input type="datetime-local" name="msc_reg_close" value="<?php echo esc_attr($d['reg_close']); ?>" class="regular-text"></td>
        </tr>
        <tr>
        <th><label>Registration Approval</label></th>
        <td colspan="3">
        <label><input type="radio" name="msc_approval" value="instant" <?php checked($d['approval'],'instant'); ?>> Instant (auto-confirmed)</label>&nbsp;&nbsp;
        <label><input type="radio" name="msc_approval" value="manual"  <?php checked($d['approval'],'manual'); ?>>  Requires admin approval</label>
        </td>
        </tr>
        <tr>
        <th><label for="msc_indemnity_text">Indemnity Text</label></th>
        <td colspan="3">
        <textarea name="msc_indemnity_text" id="msc_indemnity_text" rows="6" class="large-text" placeholder="Leave blank to use site-wide default. Site-wide default can be set in Motorsport Club &gt; Settings."><?php echo esc_textarea($d['indemnity_text']); ?></textarea>
        <p class="description">Overrides the global default indemnity text for this event only. Leave blank to use the site-wide default.</p>
        </td>
        </tr>
        </table>
        <?php
    }

    public static function meta_box_classes( $post ) {
        $vehicle_types   = MSC_Taxonomies::get_classes_by_type();
        $pricing_sets    = MSC_Pricing::get_all_sets();

        $saved_type       = get_post_meta( $post->ID, '_msc_event_vehicle_type', true ) ?: 'Both';
        $saved_classes    = get_post_meta( $post->ID, '_msc_event_classes', true );
        $saved_classes    = array_map( 'intval', $saved_classes ? (array) $saved_classes : array() );
        $saved_pricing_id = (int) get_post_meta( $post->ID, '_msc_pricing_set_id', true );
        ?>
        <p class="description" style="margin-top:0">Select allowed classes and assign a pricing set to define class fees.</p>

        <p style="margin-bottom:8px">
        <label style="font-weight:600;display:block;margin-bottom:4px">Vehicle Types Allowed</label>
        <select name="msc_event_vehicle_type" id="msc_event_vehicle_type" style="width:100%">
        <option value="Both"       <?php selected($saved_type,'Both'); ?>>🚗🏍 Both Cars &amp; Motorcycles</option>
        <option value="Car"        <?php selected($saved_type,'Car'); ?>>🚗 Cars only</option>
        <option value="Motorcycle" <?php selected($saved_type,'Motorcycle'); ?>>🏍 Motorcycles only</option>
        </select>
        </p>

        <p style="margin-bottom:8px">
        <label style="font-weight:600;display:block;margin-bottom:4px">Pricing Set</label>
        <select name="msc_pricing_set_id" style="width:100%">
        <option value="">— No pricing set (free classes) —</option>
        <?php foreach ( $pricing_sets as $ps ) : ?>
        <option value="<?php echo (int) $ps->id; ?>" <?php selected( $saved_pricing_id, (int) $ps->id ); ?>><?php echo esc_html( $ps->name ); ?></option>
        <?php endforeach; ?>
        </select>
        <span class="description">Fees per class are defined in <a href="<?php echo esc_url( admin_url('admin.php?page=msc-pricing') ); ?>">Motorsport Club → Pricing</a>.</span>
        </p>

        <div id="msc-class-checkboxes" style="margin-top:12px">
        <?php foreach ( $vehicle_types as $type => $classes ) :
        $show = ( $saved_type === 'Both' || $saved_type === $type ) ? '' : 'display:none;';
        ?>
        <div class="msc-class-group" data-type="<?php echo esc_attr( $type ); ?>" style="<?php echo esc_attr( $show ); ?>margin-bottom:10px">
        <strong style="display:block;margin-bottom:6px;color:#1d2327"><?php echo $type === 'Car' ? '🚗 Car Classes' : '🏍 Motorcycle Classes'; ?></strong>
        <div style="columns:2;column-gap:8px">
        <?php foreach ( $classes as $term_id => $class_name ) :
        $checked = in_array( $term_id, $saved_classes ) ? 'checked' : '';
        ?>
        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;break-inside:avoid;padding:2px 0">
            <input type="checkbox" name="msc_event_classes[]" value="<?php echo esc_attr($term_id); ?>" <?php echo $checked; ?>>
            <?php echo esc_html($class_name); ?>
        </label>
        <?php endforeach; ?>
        </div>
        <div style="margin-top:4px">
        <a href="#" class="msc-select-all"   data-type="<?php echo esc_attr( $type ); ?>" style="font-size:11px">Select all</a> &middot;
        <a href="#" class="msc-deselect-all" data-type="<?php echo esc_attr( $type ); ?>" style="font-size:11px">Deselect all</a>
        </div>
        </div>
        <?php endforeach; ?>
        </div>
        <script>
        jQuery(function($){
            function filterClasses(type) {
                if (type === 'Both') { $('.msc-class-group').show(); }
                else { $('.msc-class-group').hide(); $('.msc-class-group[data-type="' + type + '"]').show(); }
            }
            filterClasses($('#msc_event_vehicle_type').val());
            $('#msc_event_vehicle_type').on('change', function(){ filterClasses($(this).val()); });
            $(document).on('click', '.msc-select-all', function(e){
                e.preventDefault();
                $('.msc-class-group[data-type="' + $(this).data('type') + '"] input[type="checkbox"]').prop('checked', true);
            });
            $(document).on('click', '.msc-deselect-all', function(e){
                e.preventDefault();
                $('.msc-class-group[data-type="' + $(this).data('type') + '"] input[type="checkbox"]').prop('checked', false);
            });
        });
        </script>
        <?php
    }

    public static function save_meta( $post_id ) {
        if ( ! isset($_POST['msc_event_nonce']) || ! wp_verify_nonce($_POST['msc_event_nonce'], 'msc_event_save') ) return;
        if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
        if ( ! current_user_can('edit_post', $post_id) ) return;

        $fields = array('msc_event_date','msc_event_end_date','msc_event_location','msc_entry_fee','msc_capacity','msc_reg_open','msc_reg_close','msc_approval');
        foreach ( $fields as $f ) {
            if ( isset($_POST[$f]) ) update_post_meta( $post_id, '_' . $f, sanitize_text_field($_POST[$f]) );
        }
        if ( isset($_POST['msc_indemnity_text']) ) {
            update_post_meta( $post_id, '_msc_indemnity_text', sanitize_textarea_field(wp_unslash($_POST['msc_indemnity_text'])) );
        }
        if ( isset($_POST['msc_indemnity_pdf_id']) ) {
            $pdf_id = intval($_POST['msc_indemnity_pdf_id']);
            if ($pdf_id) update_post_meta( $post_id, '_msc_indemnity_pdf_id', $pdf_id );
            else delete_post_meta( $post_id, '_msc_indemnity_pdf_id' );
        }

        $vehicle_type = isset($_POST['msc_event_vehicle_type']) ? sanitize_text_field($_POST['msc_event_vehicle_type']) : 'Both';
        update_post_meta( $post_id, '_msc_event_vehicle_type', $vehicle_type );

        $event_classes = isset($_POST['msc_event_classes']) ? array_map('intval', $_POST['msc_event_classes']) : array();
        update_post_meta( $post_id, '_msc_event_classes', $event_classes );
        wp_set_post_terms( $post_id, $event_classes, 'msc_vehicle_class' );

        // Pricing set
        $pricing_set_id = absint( $_POST['msc_pricing_set_id'] ?? 0 );
        if ( $pricing_set_id ) {
            update_post_meta( $post_id, '_msc_pricing_set_id', $pricing_set_id );
        } else {
            delete_post_meta( $post_id, '_msc_pricing_set_id' );
        }
    }

    public static function columns( $cols ) {
        $new = array();
        foreach ( $cols as $k => $v ) {
            $new[$k] = $v;
            if ( $k === 'title' ) {
                $new['event_date'] = 'Date';
                $new['event_loc']  = 'Location';
                $new['event_regs'] = 'Registrations';
                $new['entry_fee']  = 'Starting From';
                $new['approval']   = 'Approval';
            }
        }
        return $new;
    }

    public static function column_data( $col, $post_id ) {
        global $wpdb;
        switch ($col) {
            case 'event_date':
                $d = get_post_meta($post_id,'_msc_event_date',true);
                echo $d ? esc_html(date('d M Y H:i', strtotime($d))) : '—';
                break;
            case 'event_loc':
                echo esc_html(get_post_meta($post_id,'_msc_event_location',true) ?: '—');
                break;
            case 'event_regs':
                $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}msc_registrations WHERE event_id=%d",$post_id));
                $cap   = get_post_meta($post_id,'_msc_capacity',true);
                echo esc_html($count . ($cap ? ' / '.$cap : ''));
                break;
            case 'entry_fee':
                $price = MSC_Pricing::get_event_starting_price( $post_id );
                echo $price > 0 ? esc_html('R '.number_format($price,2)) : 'Free';
                break;
            case 'approval':
                $ap = get_post_meta($post_id,'_msc_approval',true) ?: 'instant';
                echo $ap === 'manual' ? '<span style="color:#d63638">Manual</span>' : '<span style="color:#00a32a">Instant</span>';
                break;
        }
    }

    public static function registrations_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized access.' );
        }
        global $wpdb;
        $table = $wpdb->prefix . 'msc_registrations';

        // ── Handle delete ──────────────────────────────────────────────
        if (
            isset( $_POST['msc_delete_reg'] ) &&
            isset( $_POST['_wpnonce'] ) &&
            wp_verify_nonce( $_POST['_wpnonce'], 'msc_reg_action' )
        ) {
            $reg_id = intval( $_POST['reg_id'] );
            $wpdb->delete( $table, array( 'id' => $reg_id ), array( '%d' ) );
            echo '<div class="updated notice is-dismissible"><p>Registration #' . $reg_id . ' deleted.</p></div>';
        }

        // ── Handle status update ───────────────────────────────────────
        if (
            isset( $_POST['msc_update_status'] ) &&
            isset( $_POST['_wpnonce'] ) &&
            wp_verify_nonce( $_POST['_wpnonce'], 'msc_reg_action' )
        ) {
            $reg_id   = intval( $_POST['reg_id'] );
            $status   = sanitize_key( $_POST['new_status'] );
            $fee_paid = isset( $_POST['new_fee_paid'] ) ? 1 : 0;
            $wpdb->update( $table, array( 'status' => $status, 'fee_paid' => $fee_paid ), array( 'id' => $reg_id ), array( '%s', '%d' ), array( '%d' ) );
            if ( $status === 'confirmed' ) MSC_Emails::send_confirmation( $reg_id );
            echo '<div class="updated notice is-dismissible"><p>Registration updated.</p></div>';
        }

        // ── Filters ────────────────────────────────────────────────────
        $event_filter  = isset( $_GET['event_id'] ) ? intval( $_GET['event_id'] ) : 0;
        $valid_statuses = array( 'pending', 'confirmed', 'rejected', 'cancelled' );
        $status_filter  = isset( $_GET['status'] ) && in_array( $_GET['status'], $valid_statuses, true )
            ? $_GET['status']
            : '';

        $conditions = array( '1=1' );
        $values     = array();
        if ( $event_filter ) {
            $conditions[] = 'r.event_id = %d';
            $values[]     = $event_filter;
        }
        if ( $status_filter ) {
            $conditions[] = 'r.status = %s';
            $values[]     = $status_filter;
        }
        $where_sql = implode( ' AND ', $conditions );
        $sql = "SELECT r.*, p.post_title as event_name, v.post_title as vehicle_name,
        u.display_name as user_name, u.user_email
        FROM $table r
        LEFT JOIN {$wpdb->posts} p ON p.ID = r.event_id
        LEFT JOIN {$wpdb->posts} v ON v.ID = r.vehicle_id
        LEFT JOIN {$wpdb->users} u ON u.ID = r.user_id
        WHERE $where_sql ORDER BY r.created_at DESC";
        $regs = $values
            ? $wpdb->get_results( $wpdb->prepare( $sql, ...$values ) )
            : $wpdb->get_results( $sql );

        $events = get_posts(array('post_type'=>'msc_event','numberposts'=>-1,'post_status'=>'publish'));
        ?>
        <div class="wrap">
        <h1>Registrations</h1>

        <!-- Filters -->
        <form method="get" style="margin-bottom:16px">
        <input type="hidden" name="page" value="msc-registrations">
        <select name="event_id">
        <option value="">All Events</option>
        <?php foreach($events as $e): ?>
        <option value="<?php echo $e->ID ?>" <?php selected($event_filter,$e->ID) ?>><?php echo esc_html($e->post_title) ?></option>
        <?php endforeach; ?>
        </select>
        <select name="status">
        <option value="">All Statuses</option>
        <?php foreach(array('pending','confirmed','rejected','cancelled') as $s): ?>
        <option value="<?php echo $s ?>" <?php selected($status_filter,$s) ?>><?php echo ucfirst($s) ?></option>
        <?php endforeach; ?>
        </select>
        <button type="submit" class="button">Filter</button>
        </form>

        <!-- Table -->
        <table class="widefat striped">
        <thead>
        <tr>
        <th>#</th>
        <th>Entrant</th>
        <th>Email</th>
        <th>Event</th>
        <th>Vehicle</th>
        <th>Class</th>
        <th>Entry Fee</th>
        <th>PoP</th>
        <th>Paid</th>
        <th>Indemnity</th>
        <th>Status</th>
        <th>Date</th>
        <th>Actions</th>
        </tr>
        </thead>
        <tbody>
        <?php if ( empty($regs) ) : ?>
        <tr><td colspan="12">No registrations found.</td></tr>
        <?php else : $count = 1; foreach($regs as $r) :
        $status_colors = array('pending'=>'#856404','confirmed'=>'#0a3622','rejected'=>'#842029','cancelled'=>'#41464b');
        $status_bg     = array('pending'=>'#fff3cd','confirmed'=>'#d1e7dd','rejected'=>'#f8d7da','cancelled'=>'#e2e3e5');
        $sc = $status_colors[$r->status] ?? '#333';
        $sb = $status_bg[$r->status]     ?? '#eee';
        ?>
        <tr>
        <td><?php echo $count++ ?></td>
        <td><?php echo esc_html($r->user_name) ?></td>
        <td><?php echo esc_html($r->user_email) ?></td>
        <td><?php echo esc_html($r->event_name) ?></td>
        <td><?php echo esc_html($r->vehicle_name) ?></td>
        <td><?php
            $rc_rows = $wpdb->get_col( $wpdb->prepare(
                "SELECT class_id FROM {$wpdb->prefix}msc_registration_classes WHERE registration_id = %d",
                $r->id
            ) );
            if ( ! empty( $rc_rows ) ) {
                $names = array();
                foreach ( $rc_rows as $cid ) {
                    $t = get_term( $cid, 'msc_vehicle_class' );
                    if ( $t && ! is_wp_error( $t ) ) $names[] = $t->name;
                }
                echo esc_html( implode( ', ', $names ) );
            } else {
                echo '<span style="color:#aaa">—</span>';
            }
        ?></td>
        <td><?php echo $r->entry_fee > 0 ? esc_html('R '.number_format($r->entry_fee,2)) : 'Free' ?></td>
        <td><?php
            if ( $r->pop_file_id ) {
                $url = wp_get_attachment_url( $r->pop_file_id );
                if ( $url ) {
                    echo '<a href="' . esc_url($url) . '" target="_blank" title="View Proof of Payment" style="text-decoration:none">📄 View</a>';
                } else {
                    echo '<span style="color:#27ae60" title="PoP was emailed and removed from server">✓ Emailed</span>';
                }
            } else {
                echo '<span style="color:#aaa">—</span>';
            }
        ?></td>
        <td><?php echo $r->fee_paid ? '<span style="color:green">✓ Paid</span>' : '<span style="color:#aaa">—</span>' ?></td>
        <td><?php
        if ($r->indemnity_method === 'signed') echo '<span style="color:green" title="'.esc_attr($r->indemnity_date).'">✓ Signed</span>';
        elseif ($r->indemnity_method === 'bring') echo '<span style="color:#856404">📄 Will bring</span>';
        else echo '—';
        ?></td>
        <td>
        <span style="background:<?php echo $sb ?>;color:<?php echo $sc ?>;padding:2px 8px;border-radius:12px;font-size:12px;font-weight:600">
        <?php echo esc_html(ucfirst($r->status)) ?>
        </span>
        </td>
        <td><?php echo esc_html(date('d M Y', strtotime($r->created_at))) ?></td>
        <td>
        <form method="post" style="display:flex;align-items:center;gap:4px;flex-wrap:nowrap;"
        onsubmit="if(this.msc_delete_reg && this.msc_delete_reg === document.activeElement) return confirm('Permanently delete registration for <?php echo esc_js($r->user_name) ?>? This cannot be undone.');">
        <?php wp_nonce_field('msc_reg_action') ?>
        <input type="hidden" name="reg_id" value="<?php echo $r->id ?>">
        <select name="new_status" style="height:28px;line-height:28px;">
        <?php foreach(array('pending','confirmed','rejected','cancelled') as $s): ?>
        <option value="<?php echo $s ?>" <?php selected($r->status,$s) ?>><?php echo ucfirst($s) ?></option>
        <?php endforeach; ?>
        </select>
        <label title="Mark as Paid" style="display:flex;align-items:center;background:#eee;padding:2px 6px;border-radius:4px;cursor:pointer">
            <input type="checkbox" name="new_fee_paid" value="1" <?php checked($r->fee_paid,1) ?>> $
        </label>
        <button type="submit" name="msc_update_status" class="button button-small">Update</button>
        <?php if ($r->indemnity_method === 'signed' && $r->indemnity_sig): ?>
        <a href="<?php echo esc_url( add_query_arg(array('msc_indemnity_pdf'=>$r->id), home_url()) ) ?>"
        class="button button-small" target="_blank">PDF</a>
        <?php endif; ?>
        <button type="submit" name="msc_delete_reg"
        class="button button-small"
        style="color:#d63638;border-color:#d63638;">
        🗑 Delete
        </button>
        </form>
        </td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
        </table>
        </div>
        <?php
    }

    public static function settings_page() {
        if ( isset($_POST['msc_save_settings']) ) {
            check_admin_referer('msc_save_settings');
            update_option('msc_banking_details', wp_kses_post(wp_unslash($_POST['msc_banking_details'])));
            update_option('msc_default_indemnity', wp_kses_post(wp_unslash($_POST['msc_default_indemnity'])));
            update_option('msc_account_page_url',      esc_url_raw(sanitize_text_field(wp_unslash($_POST['msc_account_page_url']      ?? ''))));
            update_option('msc_login_page_url',        esc_url_raw(sanitize_text_field(wp_unslash($_POST['msc_login_page_url']        ?? ''))));
            update_option('msc_register_page_url',     esc_url_raw(sanitize_text_field(wp_unslash($_POST['msc_register_page_url']     ?? ''))));
            update_option('msc_set_password_page_url', esc_url_raw(sanitize_text_field(wp_unslash($_POST['msc_set_password_page_url'] ?? ''))));
            update_option('msc_custom_declarations', wp_kses_post(wp_unslash($_POST['msc_custom_declarations'] ?? '')));
            update_option('msc_email_from_name', sanitize_text_field(wp_unslash($_POST['msc_email_from_name'] ?? '')));
            update_option('msc_email_from_address', sanitize_email(wp_unslash($_POST['msc_email_from_address'] ?? '')));
            $access_mode = sanitize_key( wp_unslash( $_POST['msc_dashboard_event_access_mode'] ?? 'strict' ) );
            if ( ! in_array( $access_mode, array( 'strict', 'shared' ), true ) ) {
                $access_mode = 'strict';
            }
            update_option( 'msc_dashboard_event_access_mode', $access_mode );
            
            update_option('msc_smtp_enabled', isset($_POST['msc_smtp_enabled']) ? 1 : 0);
            update_option('msc_smtp_host', sanitize_text_field(wp_unslash($_POST['msc_smtp_host'] ?? '')));
            update_option('msc_smtp_port', intval($_POST['msc_smtp_port'] ?? 587));
            update_option('msc_smtp_encryption', sanitize_text_field(wp_unslash($_POST['msc_smtp_encryption'] ?? 'tls')));
            update_option('msc_smtp_user', sanitize_text_field(wp_unslash($_POST['msc_smtp_user'] ?? '')));
            if ( ! empty($_POST['msc_smtp_pass']) ) {
                update_option('msc_smtp_pass', sanitize_text_field(wp_unslash($_POST['msc_smtp_pass'])));
            }

            echo '<div class="updated"><p>Settings saved.</p></div>';
        }

        $banking      = get_option('msc_banking_details', '');
        $indemnity    = get_option('msc_default_indemnity', msc_get_default_indemnity());
        $account_url      = get_option('msc_account_page_url', '');
        $login_url        = get_option('msc_login_page_url', '');
        $register_url     = get_option('msc_register_page_url', '');
        $set_password_url = get_option('msc_set_password_page_url', '');
        $declarations = get_option('msc_custom_declarations', '');
        $from_name    = get_option('msc_email_from_name', '');
        $from_address = get_option('msc_email_from_address', '');
        $access_mode  = get_option('msc_dashboard_event_access_mode', 'strict');

        $smtp_enabled = get_option('msc_smtp_enabled', 0);
        $smtp_host    = get_option('msc_smtp_host', '');
        $smtp_port    = get_option('msc_smtp_port', 587);
        $smtp_enc     = get_option('msc_smtp_encryption', 'tls');
        $smtp_user    = get_option('msc_smtp_user', '');
        ?>
        <div class="wrap">
            <h1>⚙️ Motorsport Club — Settings</h1>
            <form method="post">
                <?php wp_nonce_field('msc_save_settings'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="msc_account_page_url">Account Page URL</label></th>
                        <td>
                            <input type="url" name="msc_account_page_url" id="msc_account_page_url" value="<?php echo esc_attr($account_url); ?>" class="large-text" placeholder="https://yoursite.com/my-account/">
                            <p class="description">Full URL of the page containing the <code>[msc_my_account]</code> shortcode. Used in registration emails and on-page links.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="msc_login_page_url">Login Page URL</label></th>
                        <td>
                            <input type="url" name="msc_login_page_url" id="msc_login_page_url" value="<?php echo esc_attr($login_url); ?>" class="large-text" placeholder="https://yoursite.com/login/">
                            <p class="description">Full URL of the page containing the <code>[msc_login]</code> shortcode. Leave blank to use the default WordPress login page.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="msc_register_page_url">Registration Page URL</label></th>
                        <td>
                            <input type="url" name="msc_register_page_url" id="msc_register_page_url" value="<?php echo esc_attr($register_url); ?>" class="large-text" placeholder="https://yoursite.com/register/">
                            <p class="description">Full URL of the page containing the <code>[msc_register]</code> shortcode. Leave blank to use the default WordPress registration page.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="msc_set_password_page_url">Set Password Page URL</label></th>
                        <td>
                            <input type="url" name="msc_set_password_page_url" id="msc_set_password_page_url" value="<?php echo esc_attr($set_password_url); ?>" class="large-text" placeholder="https://yoursite.com/set-password/">
                            <p class="description">Full URL of the page containing the <code>[msc_set_password]</code> shortcode. After email verification, users are sent here to choose their password. Leave blank to use the default WordPress password reset page.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Email Settings</th>
                        <td>
                            <div style="margin-bottom:10px">
                                <label for="msc_email_from_name" style="display:inline-block; width:100px;">From Name:</label>
                                <input type="text" name="msc_email_from_name" id="msc_email_from_name" value="<?php echo esc_attr($from_name); ?>" class="regular-text" placeholder="<?php echo esc_attr(get_bloginfo('name')); ?>">
                            </div>
                            <div>
                                <label for="msc_email_from_address" style="display:inline-block; width:100px;">From Email:</label>
                                <input type="email" name="msc_email_from_address" id="msc_email_from_address" value="<?php echo esc_attr($from_address); ?>" class="regular-text" placeholder="<?php echo esc_attr(get_option('admin_email')); ?>">
                            </div>
                            <p class="description">Configure the sender details for all automated emails. Leave empty to use site defaults.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="msc_dashboard_event_access_mode">Dashboard Event Access Mode</label></th>
                        <td>
                            <select name="msc_dashboard_event_access_mode" id="msc_dashboard_event_access_mode">
                                <option value="strict" <?php selected( $access_mode, 'strict' ); ?>>Strict ownership (recommended)</option>
                                <option value="shared" <?php selected( $access_mode, 'shared' ); ?>>Shared ops (all event creators can manage all events)</option>
                            </select>
                            <p class="description">Controls how Event Creators use the frontend Event Dashboard. In <strong>Strict</strong> mode, creators can only manage events they authored. In <strong>Shared ops</strong> mode, any Event Creator can manage all events, registrations, and results.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">SMTP Configuration</th>
                        <td>
                            <div style="margin-bottom:15px">
                                <label><input type="checkbox" name="msc_smtp_enabled" value="1" <?php checked($smtp_enabled, 1); ?>> <strong>Enable Custom SMTP</strong></label>
                                <p class="description">Use an external SMTP server for all emails instead of the default web server mailer.</p>
                            </div>
                            <div class="msc-smtp-fields" style="<?php echo $smtp_enabled ? '' : 'display:none;'; ?> background:#f9f9f9; padding:15px; border:1px solid #ddd; border-radius:4px; max-width:600px;">
                                <div style="margin-bottom:10px">
                                    <label style="display:inline-block; width:120px;">SMTP Host:</label>
                                    <input type="text" name="msc_smtp_host" value="<?php echo esc_attr($smtp_host); ?>" class="regular-text" placeholder="smtp.example.com">
                                </div>
                                <div style="margin-bottom:10px">
                                    <label style="display:inline-block; width:120px;">SMTP Port:</label>
                                    <input type="number" name="msc_smtp_port" value="<?php echo esc_attr($smtp_port); ?>" class="small-text">
                                </div>
                                <div style="margin-bottom:10px">
                                    <label style="display:inline-block; width:120px;">Encryption:</label>
                                    <select name="msc_smtp_encryption">
                                        <option value="none" <?php selected($smtp_enc, 'none'); ?>>None</option>
                                        <option value="ssl"  <?php selected($smtp_enc, 'ssl'); ?>>SSL</option>
                                        <option value="tls"  <?php selected($smtp_enc, 'tls'); ?>>TLS</option>
                                    </select>
                                </div>
                                <div style="margin-bottom:10px">
                                    <label style="display:inline-block; width:120px;">Username:</label>
                                    <input type="text" name="msc_smtp_user" value="<?php echo esc_attr($smtp_user); ?>" class="regular-text">
                                </div>
                                <div>
                                    <label style="display:inline-block; width:120px;">Password:</label>
                                    <?php if ( ! defined( 'MSC_SMTP_PASSWORD' ) ) : ?>
                                    <input type="password" name="msc_smtp_pass" value="" class="regular-text" placeholder="••••••••">
                                    <?php if ( get_option( 'msc_smtp_pass' ) ) : ?>
                                        <span class="description" style="color:green; margin-left:10px;">✓ Password saved</span>
                                    <?php endif; ?>
                                    <?php else : ?>
                                    <input type="password" name="msc_smtp_pass" value="" class="regular-text" placeholder="••••••••" disabled>
                                    <span class="description" style="color:green; margin-left:10px;">✓ Password set via <code>MSC_SMTP_PASSWORD</code> constant</span>
                                    <?php endif; ?>
                                    <p class="description">For security, define <code>define('MSC_SMTP_PASSWORD', '...');</code> in <code>wp-config.php</code> instead of saving here.</p>
                                </div>
                            </div>
                            <script>
                                jQuery(document).ready(function($) {
                                    $('input[name="msc_smtp_enabled"]').on('change', function() {
                                        $('.msc-smtp-fields').toggle($(this).is(':checked'));
                                    });
                                });
                            </script>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="msc_banking_details">Banking Details</label></th>
                        <td>
                            <textarea name="msc_banking_details" id="msc_banking_details" rows="6" class="large-text" placeholder="Enter banking details for EFT payments..."><?php echo esc_textarea($banking); ?></textarea>
                            <p class="description">These details will be shown to users when they register for an event with an entry fee.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="msc_default_indemnity">Default Indemnity Text</label></th>
                        <td>
                            <textarea name="msc_default_indemnity" id="msc_default_indemnity" rows="10" class="large-text" placeholder="Enter default indemnity text..."><?php echo esc_textarea($indemnity); ?></textarea>
                            <p class="description">This text will be used for all events and will appear on the signed PDF.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="msc_custom_declarations">Custom Registration Declarations</label></th>
                        <td>
                            <textarea name="msc_custom_declarations" id="msc_custom_declarations" rows="6" class="large-text" placeholder="I accept the Safe Guarding Policy..."><?php echo esc_textarea($declarations); ?></textarea>
                            <p class="description">Add additional mandatory checkboxes to the registration form. <strong>One per line.</strong> HTML (like links) is allowed. If empty, no extra checkboxes will be shown.</p>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <button type="submit" name="msc_save_settings" class="button button-primary">Save Settings</button>
                </p>
            </form>
        </div>
        <?php
    }
}
