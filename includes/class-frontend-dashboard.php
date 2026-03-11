<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * MSC_Frontend_Dashboard
 * Unified frontend management dashboard for event creators and admins.
 * Shortcode: [msc_event_dashboard]
 * Tabs: Events | Registrations | Results | Participants
 */
class MSC_Frontend_Dashboard {

    public static function init() {
        add_shortcode( 'msc_event_dashboard', array( __CLASS__, 'render' ) );

        // AJAX: Events
        add_action( 'wp_ajax_msc_fe_create_event',      array( __CLASS__, 'ajax_create_event' ) );
        add_action( 'wp_ajax_msc_fe_update_event',      array( __CLASS__, 'ajax_update_event' ) );
        add_action( 'wp_ajax_msc_fe_set_event_status',  array( __CLASS__, 'ajax_set_event_status' ) );

        // AJAX: Vehicle Classes
        add_action( 'wp_ajax_msc_fe_add_class',    array( __CLASS__, 'ajax_add_class' ) );
        add_action( 'wp_ajax_msc_fe_rename_class', array( __CLASS__, 'ajax_rename_class' ) );
        add_action( 'wp_ajax_msc_fe_delete_class', array( __CLASS__, 'ajax_delete_class' ) );

        // AJAX: Registrations
        add_action( 'wp_ajax_msc_fe_update_reg_status', array( __CLASS__, 'ajax_update_reg_status' ) );

        // AJAX: Results
        add_action( 'wp_ajax_msc_fe_save_results',      array( __CLASS__, 'ajax_save_results' ) );
    }

    // ─── Access guard ─────────────────────────────────────────────────────────

    /**
     * Only administrators and users with the msc_event_creator role
     * (or any role that has been granted msc_view_participants) may access.
     */
    private static function can_access() {
        if ( ! is_user_logged_in() ) return false;
        $user = wp_get_current_user();
        $allowed_roles = array( 'administrator', 'msc_event_creator' );
        $has_role = (bool) array_intersect( $allowed_roles, (array) $user->roles );
        return $has_role || current_user_can( MSC_Admin_Participants::required_cap() );
    }

    /** Event creator operational scope: strict ownership (default) or shared ops. */
    private static function is_shared_ops_mode() {
        return get_option( 'msc_dashboard_event_access_mode', 'strict' ) === 'shared';
    }

    /**
     * Restrict event mutations: admins can manage all events, non-admins only their own.
     */
    private static function can_manage_event( $event_id ) {
        $event_id = absint( $event_id );
        if ( ! $event_id ) return false;
        if ( current_user_can( 'manage_options' ) ) return true;
        if ( self::is_shared_ops_mode() ) return true;
        $post = get_post( $event_id );
        return ( $post && $post->post_type === 'msc_event' && (int) $post->post_author === get_current_user_id() );
    }

    // ─── Main renderer ────────────────────────────────────────────────────────

    public static function render() {
        if ( ! self::can_access() ) {
            if ( ! is_user_logged_in() ) {
                return '<div class="msc-login-prompt"><div class="msc-login-prompt-inner">
                    <div class="msc-login-icon">🔒</div>
                    <h3>Access Restricted</h3>
                    <p>Please log in to access the event management dashboard.</p>
                    <a href="' . wp_login_url( get_permalink() ) . '" class="msc-btn">Log In</a>
                </div></div>';
            }
            return '<p class="msc-notice">You do not have permission to access this dashboard.</p>';
        }

        wp_enqueue_media();

        $tab = isset( $_GET['msc_etab'] ) ? sanitize_key( $_GET['msc_etab'] ) : 'events';
        $valid_tabs = array( 'events', 'registrations', 'results', 'participants' );
        if ( ! in_array( $tab, $valid_tabs, true ) ) $tab = 'events';

        $events_args = array(
            'post_type'   => 'msc_event',
            'numberposts' => -1,
            'post_status' => 'publish',
            'orderby'     => 'meta_value',
            'meta_key'    => '_msc_event_date',
            'order'       => 'DESC',
        );
        if ( ! current_user_can( 'manage_options' ) && ! self::is_shared_ops_mode() ) {
            $events_args['author'] = get_current_user_id();
        }
        $all_events   = get_posts( $events_args );
        $event_counts = self::get_reg_counts();

        ob_start();
        ?>
        <div id="msc-event-dashboard">

        <!-- Tab Nav -->
        <div class="msc-tab-nav">
            <?php
            $tabs = array(
                'events'          => 'Events',
                'registrations'   => 'Registrations',
                'results'         => 'Results',
                'participants'    => 'Participants',
                'vehicle-classes' => 'Vehicle Classes',
            );
            foreach ( $tabs as $t => $label ) :
                $url = add_query_arg( 'msc_etab', $t, get_permalink() );
            ?>
            <a href="<?php echo esc_url( $url ); ?>"
               class="msc-tab-link <?php echo $tab === $t ? 'active' : ''; ?>">
                <?php echo esc_html( $label ); ?>
            </a>
            <?php endforeach; ?>
        </div>

        <?php
        switch ( $tab ) {
            case 'registrations':   self::tab_registrations( $all_events ); break;
            case 'results':         self::tab_results( $all_events );       break;
            case 'participants':    self::tab_participants();                break;
            case 'vehicle-classes': self::tab_vehicle_classes();            break;
            default:                self::tab_events( $all_events, $event_counts ); break;
        }
        ?>

        </div><!-- #msc-event-dashboard -->
        <?php
        return ob_get_clean();
    }

    // ─── Tab: Events ──────────────────────────────────────────────────────────

    private static function tab_events( $all_events, $event_counts ) {
        global $wpdb;
        $classes_by_type = MSC_Taxonomies::get_classes_by_type();
        ?>
        <div class="msc-tab-content">
            <div class="msc-tab-header">
                <h3 class="msc-tab-title">Events</h3>
                <button type="button" class="msc-btn" id="msc-toggle-create-event">+ Create New Event</button>
            </div>

            <!-- Create Event Form -->
            <div id="msc-create-event-panel" style="display:none;margin-bottom:24px">
            <div class="msc-panel" style="padding:20px">
                <h4 style="margin:0 0 16px">New Event</h4>
                <div id="msc-create-event-msg" class="msc-field-msg" style="margin-bottom:10px"></div>

                <div class="msc-form-grid">
                    <div class="msc-field msc-field-full">
                        <label>Event Title <span class="msc-required">*</span></label>
                        <input type="text" id="ce_title" placeholder="e.g. Round 3 — Kyalami">
                    </div>
                    <div class="msc-field">
                        <label>Start Date &amp; Time <span class="msc-required">*</span></label>
                        <input type="datetime-local" id="ce_event_date">
                    </div>
                    <div class="msc-field">
                        <label>End Date &amp; Time</label>
                        <input type="datetime-local" id="ce_event_end_date">
                    </div>
                    <div class="msc-field msc-field-full">
                        <label>Location / Venue</label>
                        <input type="text" id="ce_location" placeholder="e.g. Kyalami Grand Prix Circuit">
                    </div>
                    <div class="msc-field">
                        <label>Entry Fee (R)</label>
                        <input type="number" id="ce_entry_fee" min="0" step="0.01" placeholder="0 = free">
                    </div>
                    <div class="msc-field">
                        <label>Capacity <small style="font-weight:400">(0 = unlimited)</small></label>
                        <input type="number" id="ce_capacity" min="0" placeholder="0">
                    </div>
                    <div class="msc-field">
                        <label>Registration Opens</label>
                        <input type="datetime-local" id="ce_reg_open">
                    </div>
                    <div class="msc-field">
                        <label>Registration Closes</label>
                        <input type="datetime-local" id="ce_reg_close">
                    </div>
                    <div class="msc-field">
                        <label>Registration Approval</label>
                        <select id="ce_approval">
                            <option value="instant">Instant (auto-confirmed)</option>
                            <option value="manual">Manual (admin approval)</option>
                        </select>
                    </div>
                    <div class="msc-field">
                        <label>Vehicle Types Allowed</label>
                        <select id="ce_vehicle_type">
                            <option value="Both">Both Cars &amp; Motorcycles</option>
                            <option value="Car">Cars only</option>
                            <option value="Motorcycle">Motorcycles only</option>
                        </select>
                    </div>
                    <div class="msc-field msc-field-full">
                        <label>Indemnity Text</label>
                        <textarea id="ce_indemnity" rows="4" placeholder="Leave blank to use site-wide default. Site-wide default can be set in wp-admin at Motorsport Club &gt; Settings."></textarea>
                    </div>
                    <div class="msc-field msc-field-full">
                        <label>Featured Image</label>
                        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
                            <div id="ce-featured-image-preview"></div>
                            <div style="display:flex;gap:8px;flex-direction:column">
                                <button type="button" class="msc-btn msc-btn-outline msc-btn-sm" id="msc-ce-set-image">Set Featured Image</button>
                                <button type="button" class="msc-btn msc-btn-outline msc-btn-sm" id="msc-ce-remove-image" style="display:none">Remove Image</button>
                            </div>
                        </div>
                        <input type="hidden" id="ce_featured_image_id" value="">
                    </div>
                </div>

                <!-- Class checkboxes + per-class fees -->
                <div style="margin-top:16px">
                    <label style="font-weight:600;display:block;margin-bottom:4px">Allowed Vehicle Classes</label>
                    <p style="color:#666;font-size:13px;margin:0 0 10px">Select classes and set any additional fee per class (on top of the base entry fee).</p>
                    <div id="ce-class-boxes">
                    <?php foreach ( $classes_by_type as $type => $classes ) : ?>
                    <div class="msc-ce-class-group" data-type="<?php echo esc_attr($type); ?>" style="margin-bottom:16px">
                        <strong style="display:block;margin-bottom:6px"><?php echo esc_html($type); ?> Classes</strong>
                        <table style="width:100%;border-collapse:collapse;max-width:500px">
                        <thead><tr>
                            <th style="text-align:left;font-weight:600;font-size:12px;padding:2px 8px 4px 0;width:60%">Class</th>
                            <th style="text-align:left;font-weight:600;font-size:12px;padding:2px 0 4px">Additional Fee (R)</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ( $classes as $term_id => $class_name ) : ?>
                        <tr>
                            <td style="padding:3px 8px 3px 0">
                                <label style="font-weight:400;display:flex;align-items:center;gap:6px;cursor:pointer;white-space:nowrap">
                                    <input type="checkbox" class="ce-class-cb" value="<?php echo esc_attr($term_id); ?>">
                                    <?php echo esc_html($class_name); ?>
                                </label>
                            </td>
                            <td style="padding:3px 0">
                                <input type="number" class="ce-class-fee" data-class-id="<?php echo esc_attr($term_id); ?>"
                                       min="0" step="0.01" style="width:80px" placeholder="0.00" value="0.00">
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                        </table>
                    </div>
                    <?php endforeach; ?>
                    </div>
                </div>

                <div style="margin-top:20px;display:flex;gap:10px">
                    <button type="button" class="msc-btn" id="msc-submit-create-event">Create Event</button>
                    <button type="button" class="msc-btn msc-btn-outline" id="msc-cancel-create-event">Cancel</button>
                </div>
            </div>
            </div>

            <!-- Edit Event Panel -->
            <div id="msc-edit-event-panel" style="display:none;margin-bottom:24px">
            <div class="msc-panel" style="padding:20px">
                <h4 style="margin:0 0 16px">Edit Event</h4>
                <div id="msc-edit-event-msg" class="msc-field-msg" style="margin-bottom:10px"></div>
                <input type="hidden" id="ee_event_id" value="">

                <div class="msc-form-grid">
                    <div class="msc-field msc-field-full">
                        <label>Event Title <span class="msc-required">*</span></label>
                        <input type="text" id="ee_title" placeholder="e.g. Round 3 — Kyalami">
                    </div>
                    <div class="msc-field">
                        <label>Start Date &amp; Time <span class="msc-required">*</span></label>
                        <input type="datetime-local" id="ee_event_date">
                    </div>
                    <div class="msc-field">
                        <label>End Date &amp; Time</label>
                        <input type="datetime-local" id="ee_event_end_date">
                    </div>
                    <div class="msc-field msc-field-full">
                        <label>Location / Venue</label>
                        <input type="text" id="ee_location" placeholder="e.g. Kyalami Grand Prix Circuit">
                    </div>
                    <div class="msc-field">
                        <label>Entry Fee (R)</label>
                        <input type="number" id="ee_entry_fee" min="0" step="0.01" placeholder="0 = free">
                    </div>
                    <div class="msc-field">
                        <label>Capacity <small style="font-weight:400">(0 = unlimited)</small></label>
                        <input type="number" id="ee_capacity" min="0" placeholder="0">
                    </div>
                    <div class="msc-field">
                        <label>Registration Opens</label>
                        <input type="datetime-local" id="ee_reg_open">
                    </div>
                    <div class="msc-field">
                        <label>Registration Closes</label>
                        <input type="datetime-local" id="ee_reg_close">
                    </div>
                    <div class="msc-field">
                        <label>Registration Approval</label>
                        <select id="ee_approval">
                            <option value="instant">Instant (auto-confirmed)</option>
                            <option value="manual">Manual (admin approval)</option>
                        </select>
                    </div>
                    <div class="msc-field">
                        <label>Vehicle Types Allowed</label>
                        <select id="ee_vehicle_type">
                            <option value="Both">Both Cars &amp; Motorcycles</option>
                            <option value="Car">Cars only</option>
                            <option value="Motorcycle">Motorcycles only</option>
                        </select>
                    </div>
                    <div class="msc-field msc-field-full">
                        <label>Indemnity Text</label>
                        <textarea id="ee_indemnity" rows="4" placeholder="Leave blank to use site-wide default."></textarea>
                    </div>
                    <div class="msc-field msc-field-full">
                        <label>Featured Image</label>
                        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
                            <div id="ee-featured-image-preview"></div>
                            <div style="display:flex;gap:8px;flex-direction:column">
                                <button type="button" class="msc-btn msc-btn-outline msc-btn-sm" id="msc-ee-set-image">Set Featured Image</button>
                                <button type="button" class="msc-btn msc-btn-outline msc-btn-sm" id="msc-ee-remove-image" style="display:none">Remove Image</button>
                            </div>
                        </div>
                        <input type="hidden" id="ee_featured_image_id" value="">
                    </div>
                </div>

                <!-- Class checkboxes + per-class fees -->
                <div style="margin-top:16px">
                    <label style="font-weight:600;display:block;margin-bottom:4px">Allowed Vehicle Classes</label>
                    <p style="color:#666;font-size:13px;margin:0 0 10px">Select classes and set any additional fee per class (on top of the base entry fee).</p>
                    <div id="ee-class-boxes">
                    <?php foreach ( $classes_by_type as $type => $classes ) : ?>
                    <div class="msc-ee-class-group" data-type="<?php echo esc_attr($type); ?>" style="margin-bottom:16px">
                        <strong style="display:block;margin-bottom:6px"><?php echo esc_html($type); ?> Classes</strong>
                        <table style="width:100%;border-collapse:collapse;max-width:500px">
                        <thead><tr>
                            <th style="text-align:left;font-weight:600;font-size:12px;padding:2px 8px 4px 0;width:60%">Class</th>
                            <th style="text-align:left;font-weight:600;font-size:12px;padding:2px 0 4px">Additional Fee (R)</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ( $classes as $term_id => $class_name ) : ?>
                        <tr>
                            <td style="padding:3px 8px 3px 0">
                                <label style="font-weight:400;display:flex;align-items:center;gap:6px;cursor:pointer;white-space:nowrap">
                                    <input type="checkbox" class="ee-class-cb" value="<?php echo esc_attr($term_id); ?>">
                                    <?php echo esc_html($class_name); ?>
                                </label>
                            </td>
                            <td style="padding:3px 0">
                                <input type="number" class="ee-class-fee" data-class-id="<?php echo esc_attr($term_id); ?>"
                                       min="0" step="0.01" style="width:80px" placeholder="0.00" value="0.00">
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                        </table>
                    </div>
                    <?php endforeach; ?>
                    </div>
                </div>

                <div style="margin-top:20px;display:flex;gap:10px">
                    <button type="button" class="msc-btn" id="msc-submit-edit-event">Save Changes</button>
                    <button type="button" class="msc-btn msc-btn-outline" id="msc-cancel-edit-event">Cancel</button>
                </div>
            </div>
            </div>

            <!-- Events Table -->
            <?php if ( empty( $all_events ) ) : ?>
            <p style="color:#888">No events found. Create your first event above.</p>
            <?php else : ?>
            <div style="overflow-x:auto">
            <table class="msc-dash-table">
            <thead>
                <tr>
                    <th>Event</th>
                    <th>Date</th>
                    <th>Location</th>
                    <th>Fee</th>
                    <th>Status</th>
                    <th>Registrations</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $all_events as $event ) :
                $event_date   = get_post_meta( $event->ID, '_msc_event_date', true );
                $event_end    = get_post_meta( $event->ID, '_msc_event_end_date', true );
                $location     = get_post_meta( $event->ID, '_msc_event_location', true );
                $fee          = floatval( get_post_meta( $event->ID, '_msc_entry_fee', true ) );
                $is_closed    = MSC_Results::is_closed( $event->ID );
                $reg_count    = $event_counts[ $event->ID ] ?? 0;
                $thumb_id     = get_post_thumbnail_id( $event->ID );
                $thumb_url    = $thumb_id ? wp_get_attachment_image_url( $thumb_id, 'thumbnail' ) : '';
                $ev_classes   = get_post_meta( $event->ID, '_msc_event_classes', true ) ?: array();
                $ev_fees      = get_post_meta( $event->ID, '_msc_class_fees', true ) ?: array();
            ?>
            <tr>
                <td><strong><?php echo esc_html( $event->post_title ); ?></strong></td>
                <td style="white-space:nowrap"><?php echo $event_date ? esc_html( date( 'd M Y H:i', strtotime( $event_date ) ) ) : '—'; ?></td>
                <td><?php echo esc_html( $location ?: '—' ); ?></td>
                <td><?php echo $fee > 0 ? 'R ' . number_format( $fee, 2 ) : 'Free'; ?></td>
                <td>
                    <?php if ( $is_closed ) : ?>
                    <span class="msc-status-badge msc-status-closed">Closed</span>
                    <?php else : ?>
                    <span class="msc-status-badge msc-status-open">Open</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php
                    $reg_url = add_query_arg( array( 'msc_etab' => 'registrations', 'msc_filter_event' => $event->ID ), get_permalink() );
                    echo '<a href="' . esc_url($reg_url) . '">' . $reg_count . ' entr' . ($reg_count === 1 ? 'y' : 'ies') . '</a>';
                    ?>
                </td>
                <td>
                    <div style="display:flex;gap:6px;flex-wrap:wrap">
                    <a href="<?php echo esc_url( get_permalink( $event->ID ) ); ?>" class="msc-btn msc-btn-sm msc-btn-outline" target="_blank">View</a>
                    <button type="button" class="msc-btn msc-btn-sm msc-btn-outline msc-fe-edit-event"
                        data-id="<?php echo esc_attr( $event->ID ); ?>"
                        data-title="<?php echo esc_attr( $event->post_title ); ?>"
                        data-date="<?php echo esc_attr( $event_date ); ?>"
                        data-end-date="<?php echo esc_attr( $event_end ); ?>"
                        data-location="<?php echo esc_attr( $location ); ?>"
                        data-fee="<?php echo esc_attr( get_post_meta( $event->ID, '_msc_entry_fee', true ) ); ?>"
                        data-capacity="<?php echo esc_attr( get_post_meta( $event->ID, '_msc_capacity', true ) ); ?>"
                        data-reg-open="<?php echo esc_attr( get_post_meta( $event->ID, '_msc_reg_open', true ) ); ?>"
                        data-reg-close="<?php echo esc_attr( get_post_meta( $event->ID, '_msc_reg_close', true ) ); ?>"
                        data-approval="<?php echo esc_attr( get_post_meta( $event->ID, '_msc_approval', true ) ?: 'instant' ); ?>"
                        data-vehicle-type="<?php echo esc_attr( get_post_meta( $event->ID, '_msc_event_vehicle_type', true ) ?: 'Both' ); ?>"
                        data-indemnity="<?php echo esc_attr( get_post_meta( $event->ID, '_msc_indemnity_text', true ) ); ?>"
                        data-image-id="<?php echo esc_attr( $thumb_id ?: '' ); ?>"
                        data-image-url="<?php echo esc_attr( $thumb_url ); ?>"
                        data-classes="<?php echo esc_attr( wp_json_encode( array_values( $ev_classes ) ) ); ?>"
                        data-class-fees="<?php echo esc_attr( wp_json_encode( $ev_fees ) ); ?>"
                    >Edit</button>
                    <?php if ( $is_closed ) : ?>
                    <button type="button" class="msc-btn msc-btn-sm msc-btn-outline msc-fe-event-status"
                            data-id="<?php echo $event->ID; ?>" data-status="open">Reopen</button>
                    <a href="<?php echo esc_url( add_query_arg( array( 'msc_etab' => 'results', 'msc_result_event' => $event->ID ), get_permalink() ) ); ?>"
                       class="msc-btn msc-btn-sm">Results</a>
                    <?php else : ?>
                    <button type="button" class="msc-btn msc-btn-sm msc-fe-event-status"
                            data-id="<?php echo $event->ID; ?>" data-status="closed"
                            onclick="return confirm('Close this event? Registrations will be locked.')">Close Event</button>
                    <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>

        <script>
        (function($){
            var nonce = '<?php echo wp_create_nonce('msc_nonce'); ?>';
            var ajaxUrl = '<?php echo esc_js( admin_url('admin-ajax.php') ); ?>';

            // Toggle create form
            $('#msc-toggle-create-event').on('click', function(){
                $('#msc-create-event-panel').slideToggle(200);
            });
            $('#msc-cancel-create-event').on('click', function(){
                $('#msc-create-event-panel').slideUp(200);
            });

            // Featured image picker
            var ceImageFrame;
            $('#msc-ce-set-image').on('click', function(e){
                e.preventDefault();
                if ( ceImageFrame ) { ceImageFrame.open(); return; }
                ceImageFrame = wp.media({
                    title:    'Select Featured Image',
                    button:   { text: 'Use this image' },
                    multiple: false,
                    library:  { type: 'image' }
                });
                ceImageFrame.on('select', function(){
                    var att = ceImageFrame.state().get('selection').first().toJSON();
                    $('#ce_featured_image_id').val(att.id);
                    var url = (att.sizes && att.sizes.thumbnail) ? att.sizes.thumbnail.url : att.url;
                    $('#ce-featured-image-preview').html('<img src="'+url+'" style="max-width:150px;max-height:100px;object-fit:cover;border-radius:4px;">');
                    $('#msc-ce-remove-image').show();
                });
                ceImageFrame.open();
            });
            $('#msc-ce-remove-image').on('click', function(){
                $('#ce_featured_image_id').val('');
                $('#ce-featured-image-preview').empty();
                $(this).hide();
            });

            // Filter class checkboxes by vehicle type
            function filterClasses(type) {
                if (type === 'Both') { $('.msc-ce-class-group').show(); }
                else { $('.msc-ce-class-group').hide(); $('.msc-ce-class-group[data-type="'+type+'"]').show(); }
            }
            filterClasses($('#ce_vehicle_type').val());
            $('#ce_vehicle_type').on('change', function(){ filterClasses($(this).val()); });

            // Create event submit
            $('#msc-submit-create-event').on('click', function(){
                var btn = $(this);
                var msg = $('#msc-create-event-msg');
                var classes = [];
                var classFees = {};
                $('.ce-class-cb:checked').each(function(){
                    var id = $(this).val();
                    classes.push(id);
                    var fee = parseFloat($('.ce-class-fee[data-class-id="'+id+'"]').val()) || 0;
                    classFees[id] = fee;
                });

                if (!$('#ce_title').val().trim()) {
                    msg.text('Event title is required.').css('color','red').show(); return;
                }
                if (!$('#ce_event_date').val()) {
                    msg.text('Start date is required.').css('color','red').show(); return;
                }

                btn.prop('disabled', true).text('Creating…');
                $.post(ajaxUrl, {
                    action:          'msc_fe_create_event',
                    nonce:           nonce,
                    title:           $('#ce_title').val(),
                    event_date:      $('#ce_event_date').val(),
                    event_end_date:  $('#ce_event_end_date').val(),
                    location:        $('#ce_location').val(),
                    entry_fee:       $('#ce_entry_fee').val() || 0,
                    capacity:        $('#ce_capacity').val() || 0,
                    reg_open:        $('#ce_reg_open').val(),
                    reg_close:       $('#ce_reg_close').val(),
                    approval:           $('#ce_approval').val(),
                    vehicle_type:       $('#ce_vehicle_type').val(),
                    indemnity:          $('#ce_indemnity').val(),
                    featured_image_id:  $('#ce_featured_image_id').val() || 0,
                    class_ids:          classes,
                    class_fees:         classFees,
                }, function(res){
                    btn.prop('disabled', false).text('Create Event');
                    if (res.success) {
                        msg.text('Event created! Reloading…').css('color','green').show();
                        setTimeout(function(){ location.reload(); }, 1000);
                    } else {
                        msg.text(res.data.message || 'Error.').css('color','red').show();
                    }
                });
            });

            // Edit event
            $('#msc-cancel-edit-event').on('click', function(){
                $('#msc-edit-event-panel').slideUp(200);
            });

            function eeFilterClasses(type) {
                if (type === 'Both') { $('.msc-ee-class-group').show(); }
                else { $('.msc-ee-class-group').hide(); $('.msc-ee-class-group[data-type="'+type+'"]').show(); }
            }
            $('#ee_vehicle_type').on('change', function(){ eeFilterClasses($(this).val()); });

            var eeImageFrame;
            $('#msc-ee-set-image').on('click', function(e){
                e.preventDefault();
                if ( eeImageFrame ) { eeImageFrame.open(); return; }
                eeImageFrame = wp.media({
                    title:    'Select Featured Image',
                    button:   { text: 'Use this image' },
                    multiple: false,
                    library:  { type: 'image' }
                });
                eeImageFrame.on('select', function(){
                    var att = eeImageFrame.state().get('selection').first().toJSON();
                    $('#ee_featured_image_id').val(att.id);
                    var url = (att.sizes && att.sizes.thumbnail) ? att.sizes.thumbnail.url : att.url;
                    $('#ee-featured-image-preview').html('<img src="'+url+'" style="max-width:150px;max-height:100px;object-fit:cover;border-radius:4px;">');
                    $('#msc-ee-remove-image').show();
                });
                eeImageFrame.open();
            });
            $('#msc-ee-remove-image').on('click', function(){
                $('#ee_featured_image_id').val('');
                $('#ee-featured-image-preview').empty();
                $(this).hide();
            });

            $(document).on('click', '.msc-fe-edit-event', function(){
                var btn = $(this);
                var d   = btn.data();
                $('#msc-edit-event-msg').hide().text('');
                $('#ee_event_id').val(d.id);
                $('#ee_title').val(d.title);
                $('#ee_event_date').val(d.date);
                $('#ee_event_end_date').val(d.endDate || '');
                $('#ee_location').val(d.location || '');
                $('#ee_entry_fee').val(d.fee || 0);
                $('#ee_capacity').val(d.capacity || 0);
                $('#ee_reg_open').val(d.regOpen || '');
                $('#ee_reg_close').val(d.regClose || '');
                $('#ee_approval').val(d.approval || 'instant');
                $('#ee_vehicle_type').val(d.vehicleType || 'Both');
                $('#ee_indemnity').val(d.indemnity || '');

                // Featured image
                if (d.imageId) {
                    $('#ee_featured_image_id').val(d.imageId);
                    $('#ee-featured-image-preview').html('<img src="'+d.imageUrl+'" style="max-width:150px;max-height:100px;object-fit:cover;border-radius:4px;">');
                    $('#msc-ee-remove-image').show();
                } else {
                    $('#ee_featured_image_id').val('');
                    $('#ee-featured-image-preview').empty();
                    $('#msc-ee-remove-image').hide();
                }

                // Classes
                var savedClasses = d.classes || [];
                var savedFees    = d.classFees || {};
                $('.ee-class-cb').prop('checked', false);
                $('.ee-class-fee').val('0.00');
                $.each(savedClasses, function(i, classId){
                    $('.ee-class-cb[value="'+classId+'"]').prop('checked', true);
                    var fee = savedFees[classId] || 0;
                    $('.ee-class-fee[data-class-id="'+classId+'"]').val(parseFloat(fee).toFixed(2));
                });
                eeFilterClasses(d.vehicleType || 'Both');

                // Scroll to panel and show
                $('html,body').animate({scrollTop: $('#msc-edit-event-panel').offset().top - 80}, 300);
                $('#msc-edit-event-panel').slideDown(200);
            });

            $('#msc-submit-edit-event').on('click', function(){
                var btn = $(this);
                var msg = $('#msc-edit-event-msg');
                var classes = [];
                var classFees = {};
                $('.ee-class-cb:checked').each(function(){
                    var id = $(this).val();
                    classes.push(id);
                    var fee = parseFloat($('.ee-class-fee[data-class-id="'+id+'"]').val()) || 0;
                    classFees[id] = fee;
                });
                if (!$('#ee_title').val().trim()) {
                    msg.text('Event title is required.').css('color','red').show(); return;
                }
                if (!$('#ee_event_date').val()) {
                    msg.text('Start date is required.').css('color','red').show(); return;
                }
                btn.prop('disabled', true).text('Saving…');
                $.post(ajaxUrl, {
                    action:           'msc_fe_update_event',
                    nonce:            nonce,
                    event_id:         $('#ee_event_id').val(),
                    title:            $('#ee_title').val(),
                    event_date:       $('#ee_event_date').val(),
                    event_end_date:   $('#ee_event_end_date').val(),
                    location:         $('#ee_location').val(),
                    entry_fee:        $('#ee_entry_fee').val() || 0,
                    capacity:         $('#ee_capacity').val() || 0,
                    reg_open:         $('#ee_reg_open').val(),
                    reg_close:        $('#ee_reg_close').val(),
                    approval:         $('#ee_approval').val(),
                    vehicle_type:     $('#ee_vehicle_type').val(),
                    indemnity:        $('#ee_indemnity').val(),
                    featured_image_id: $('#ee_featured_image_id').val() || 0,
                    class_ids:        classes,
                    class_fees:       classFees,
                }, function(res){
                    btn.prop('disabled', false).text('Save Changes');
                    if (res.success) {
                        msg.text('Event updated! Reloading…').css('color','green').show();
                        setTimeout(function(){ location.reload(); }, 1000);
                    } else {
                        msg.text(res.data.message || 'Error.').css('color','red').show();
                    }
                });
            });

            // Close / Reopen event
            $(document).on('click', '.msc-fe-event-status', function(){
                var btn      = $(this);
                var event_id = btn.data('id');
                var status   = btn.data('status');
                btn.prop('disabled', true);
                $.post(ajaxUrl, {
                    action:   'msc_fe_set_event_status',
                    nonce:    nonce,
                    event_id: event_id,
                    status:   status,
                }, function(res){
                    if (res.success) { location.reload(); }
                    else { alert(res.data.message || 'Error.'); btn.prop('disabled', false); }
                });
            });
        })(jQuery);
        </script>
        <?php
    }

    // ─── Tab: Registrations ───────────────────────────────────────────────────

    private static function tab_registrations( $all_events ) {
        global $wpdb;
        $table = $wpdb->prefix . 'msc_registrations';

        $event_filter   = isset( $_GET['msc_filter_event'] ) ? intval( $_GET['msc_filter_event'] ) : 0;
        $valid_statuses = array( 'pending', 'confirmed', 'rejected', 'cancelled' );
        $status_filter  = isset( $_GET['msc_filter_status'] ) && in_array( $_GET['msc_filter_status'], $valid_statuses, true )
            ? $_GET['msc_filter_status'] : '';

        $conditions = array( '1=1' );
        $values     = array();
        if ( ! current_user_can( 'manage_options' ) && ! self::is_shared_ops_mode() ) {
            $conditions[] = 'p.post_author = %d';
            $values[]     = get_current_user_id();
        }
        if ( $event_filter ) { $conditions[] = 'r.event_id = %d'; $values[] = $event_filter; }
        if ( $status_filter ) { $conditions[] = 'r.status = %s'; $values[] = $status_filter; }

        $where = implode( ' AND ', $conditions );
        $sql = "SELECT r.id, r.event_id, r.status, r.entry_fee, r.fee_paid, r.created_at, r.class_id,
                       p.post_title AS event_name, v.post_title AS vehicle_name, u.display_name AS user_name
                FROM $table r
                LEFT JOIN {$wpdb->posts}  p ON p.ID = r.event_id
                LEFT JOIN {$wpdb->posts}  v ON v.ID = r.vehicle_id
                LEFT JOIN {$wpdb->users}  u ON u.ID = r.user_id
                WHERE $where ORDER BY r.created_at DESC";
        $regs = $values ? $wpdb->get_results( $wpdb->prepare( $sql, ...$values ) ) : $wpdb->get_results( $sql );

        $status_colors = array( 'pending' => '#856404', 'confirmed' => '#0a3622', 'rejected' => '#842029', 'cancelled' => '#41464b' );
        $status_bg     = array( 'pending' => '#fff3cd', 'confirmed' => '#d1e7dd', 'rejected' => '#f8d7da', 'cancelled' => '#e2e3e5' );
        ?>
        <div class="msc-tab-content">
            <div class="msc-tab-header">
                <h3 class="msc-tab-title">Registrations</h3>
            </div>

            <!-- Filters -->
            <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px;align-items:center">
                <?php foreach ( $_GET as $k => $v ) : if ( in_array($k, array('msc_filter_event','msc_filter_status'), true) ) continue; ?>
                <input type="hidden" name="<?php echo esc_attr($k); ?>" value="<?php echo esc_attr($v); ?>">
                <?php endforeach; ?>
                <select name="msc_filter_event" style="padding:7px 10px;border:1px solid #ddd;border-radius:4px">
                    <option value="">All Events</option>
                    <?php foreach ( $all_events as $e ) : ?>
                    <option value="<?php echo $e->ID; ?>" <?php selected( $event_filter, $e->ID ); ?>><?php echo esc_html( $e->post_title ); ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="msc_filter_status" style="padding:7px 10px;border:1px solid #ddd;border-radius:4px">
                    <option value="">All Statuses</option>
                    <?php foreach ( $valid_statuses as $s ) : ?>
                    <option value="<?php echo $s; ?>" <?php selected( $status_filter, $s ); ?>><?php echo ucfirst($s); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="msc-btn msc-btn-sm">Filter</button>
                <?php if ( $event_filter || $status_filter ) : ?>
                <a href="<?php echo esc_url( add_query_arg( 'msc_etab', 'registrations', get_permalink() ) ); ?>" class="msc-btn msc-btn-sm msc-btn-outline">Clear</a>
                <?php endif; ?>
            </form>

            <div id="msc-reg-msg" class="msc-field-msg" style="margin-bottom:10px"></div>

            <?php if ( empty( $regs ) ) : ?>
            <p style="color:#888">No registrations found.</p>
            <?php else : ?>
            <div style="overflow-x:auto">
            <table class="msc-dash-table">
                <thead><tr>
                    <th>Entrant</th>
                    <th>Event</th>
                    <th>Vehicle</th>
                    <th>Class</th>
                    <th>Fee</th>
                    <th>Date</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr></thead>
                <tbody>
                <?php foreach ( $regs as $r ) :
                    $sc = $status_colors[ $r->status ] ?? '#333';
                    $sb = $status_bg[ $r->status ]     ?? '#eee';
                    $class_name = '—';
                    if ( ! empty( $r->class_id ) ) {
                        $term = get_term( (int) $r->class_id, 'msc_vehicle_class' );
                        if ( $term && ! is_wp_error($term) ) $class_name = $term->name;
                    }
                ?>
                <tr id="msc-reg-row-<?php echo $r->id; ?>">
                    <td><?php echo esc_html( $r->user_name ); ?></td>
                    <td><?php echo esc_html( $r->event_name ); ?></td>
                    <td><?php echo esc_html( $r->vehicle_name ?: '—' ); ?></td>
                    <td><?php echo esc_html( $class_name ); ?></td>
                    <td><?php echo $r->entry_fee > 0 ? 'R '.number_format($r->entry_fee,2) : 'Free'; ?></td>
                    <td style="white-space:nowrap"><?php echo esc_html( date('d M Y', strtotime($r->created_at)) ); ?></td>
                    <td>
                        <span class="msc-status-badge" style="background:<?php echo $sb;?>;color:<?php echo $sc;?>">
                            <?php echo esc_html( ucfirst($r->status) ); ?>
                        </span>
                    </td>
                    <td>
                        <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
                        <a href="<?php echo esc_url( add_query_arg('msc_indemnity_pdf', $r->id, home_url()) ); ?>"
                           target="_blank" class="msc-btn msc-btn-sm msc-btn-outline">PDF</a>
                        <?php if ( in_array($r->status, array('pending','confirmed'), true) ) : ?>
                        <select class="msc-reg-status-select" data-id="<?php echo $r->id; ?>"
                                style="padding:4px 6px;border:1px solid #ddd;border-radius:4px;font-size:12px">
                            <?php foreach ( $valid_statuses as $s ) : ?>
                            <option value="<?php echo $s; ?>" <?php selected($r->status,$s); ?>><?php echo ucfirst($s); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="msc-btn msc-btn-sm msc-reg-status-save" data-id="<?php echo $r->id; ?>">Save</button>
                        <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>

        <script>
        (function($){
            var nonce   = '<?php echo wp_create_nonce('msc_nonce'); ?>';
            var ajaxUrl = '<?php echo esc_js( admin_url('admin-ajax.php') ); ?>';

            $(document).on('click', '.msc-reg-status-save', function(){
                var btn    = $(this);
                var reg_id = btn.data('id');
                var status = $('.msc-reg-status-select[data-id="'+reg_id+'"]').val();
                btn.prop('disabled', true).text('…');
                $.post(ajaxUrl, {
                    action:  'msc_fe_update_reg_status',
                    nonce:   nonce,
                    reg_id:  reg_id,
                    status:  status,
                }, function(res){
                    btn.prop('disabled', false).text('Save');
                    if (res.success) {
                        $('#msc-reg-msg').text('Registration updated.').css('color','green').show();
                        setTimeout(function(){ location.reload(); }, 800);
                    } else {
                        $('#msc-reg-msg').text(res.data.message || 'Error.').css('color','red').show();
                    }
                });
            });
        })(jQuery);
        </script>
        <?php
    }

    // ─── Tab: Results ─────────────────────────────────────────────────────────

    private static function tab_results( $all_events ) {
        global $wpdb;

        // Filter to closed events only
        $closed_events = array_filter( $all_events, function( $e ) {
            return MSC_Results::is_closed( $e->ID );
        } );

        $selected_event_id = isset( $_GET['msc_result_event'] ) ? intval( $_GET['msc_result_event'] ) : 0;

        // Validate the selected event is actually closed
        if ( $selected_event_id ) {
            $sel_post = get_post( $selected_event_id );
            if ( ! $sel_post || $sel_post->post_type !== 'msc_event' || ! MSC_Results::is_closed( $selected_event_id ) ) {
                $selected_event_id = 0;
            }
        }
        ?>
        <div class="msc-tab-content">
            <div class="msc-tab-header">
                <h3 class="msc-tab-title">Results</h3>
            </div>

            <?php if ( empty( $closed_events ) ) : ?>
            <p style="color:#888">No closed events yet. Close an event from the <a href="<?php echo esc_url( add_query_arg('msc_etab','events',get_permalink()) ); ?>">Events</a> tab to enter results.</p>
            <?php else : ?>

            <!-- Event selector -->
            <form method="get" style="margin-bottom:20px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
                <?php foreach ( $_GET as $k => $v ) : if ( in_array($k, array('msc_result_event'), true) ) continue; ?>
                <input type="hidden" name="<?php echo esc_attr($k); ?>" value="<?php echo esc_attr($v); ?>">
                <?php endforeach; ?>
                <select name="msc_result_event" style="padding:7px 10px;border:1px solid #ddd;border-radius:4px;min-width:240px">
                    <option value="">— Select a closed event —</option>
                    <?php foreach ( $closed_events as $e ) : ?>
                    <option value="<?php echo $e->ID; ?>" <?php selected($selected_event_id, $e->ID); ?>>
                        <?php echo esc_html( $e->post_title ); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="msc-btn msc-btn-sm">Load Results</button>
            </form>

            <?php if ( $selected_event_id ) :
                self::render_results_form( $selected_event_id );
            endif; ?>

            <?php endif; ?>
        </div>
        <?php
    }

    private static function render_results_form( $event_id ) {
        global $wpdb;
        $reg_table  = $wpdb->prefix . 'msc_registrations';
        $res_table  = $wpdb->prefix . 'msc_event_results';
        $event      = get_post( $event_id );

        $registrations = $wpdb->get_results( $wpdb->prepare(
            "SELECT r.id, r.class_id, u.display_name AS member_name, v.post_title AS vehicle_name
             FROM $reg_table r
             LEFT JOIN {$wpdb->users} u ON u.ID = r.user_id
             LEFT JOIN {$wpdb->posts} v ON v.ID = r.vehicle_id
             WHERE r.event_id = %d AND r.status NOT IN ('rejected','cancelled')
             ORDER BY u.display_name ASC",
            $event_id
        ) );

        $existing_rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $res_table WHERE event_id = %d", $event_id ) );
        $results_by_reg = array();
        $manual_results = array();
        foreach ( $existing_rows as $row ) {
            if ( $row->registration_id ) {
                $results_by_reg[ $row->registration_id ] = $row;
            } else {
                $manual_results[] = $row;
            }
        }

        $all_classes = get_terms( array( 'taxonomy' => 'msc_vehicle_class', 'hide_empty' => false ) );
        if ( is_wp_error( $all_classes ) ) $all_classes = array();

        $statuses = array( 'Finished', 'DNF', 'DNS', 'DSQ' );
        ?>
        <div id="msc-results-form-wrap">
        <h4 style="margin:0 0 12px">Results: <?php echo esc_html( $event->post_title ); ?></h4>
        <div id="msc-results-msg" class="msc-field-msg" style="margin-bottom:10px"></div>

        <!-- Registered Drivers -->
        <?php if ( ! empty( $registrations ) ) : ?>
        <p style="font-weight:600;margin-bottom:8px">Registered Drivers</p>
        <div style="overflow-x:auto;margin-bottom:20px">
        <table class="msc-dash-table msc-results-entry-table" id="msc-results-reg-table">
            <thead><tr>
                <th>Driver</th><th>Vehicle</th><th>Class</th>
                <th style="width:60px">Pos</th><th style="width:60px">Laps</th>
                <th style="width:100px">Best Lap <small>(m:ss.ms)</small></th>
                <th style="width:100px">Total Time</th>
                <th style="width:90px">Status</th>
                <th>Notes</th>
            </tr></thead>
            <tbody>
            <?php foreach ( $registrations as $reg ) :
                $r          = $results_by_reg[ $reg->id ] ?? null;
                $class_name = '—';
                if ( ! empty( $reg->class_id ) ) {
                    $term = get_term( $reg->class_id, 'msc_vehicle_class' );
                    if ( $term && ! is_wp_error($term) ) $class_name = $term->name;
                }
            ?>
            <tr data-reg-id="<?php echo $reg->id; ?>">
                <td><strong><?php echo esc_html( $reg->member_name ); ?></strong></td>
                <td style="font-size:12px"><?php echo esc_html( $reg->vehicle_name ?: '—' ); ?></td>
                <td style="font-size:12px"><?php echo esc_html( $class_name ); ?></td>
                <td><input type="number" class="msc-res-pos"   min="1"  value="<?php echo esc_attr($r->position        ?? ''); ?>"></td>
                <td><input type="number" class="msc-res-laps"  min="0"  value="<?php echo esc_attr($r->laps_completed  ?? ''); ?>"></td>
                <td><input type="text"   class="msc-res-best"  placeholder="1:23.456" value="<?php echo esc_attr($r->best_lap_time   ?? ''); ?>"></td>
                <td><input type="text"   class="msc-res-total" placeholder="0:45:12"  value="<?php echo esc_attr($r->total_race_time ?? ''); ?>"></td>
                <td>
                    <select class="msc-res-status">
                        <?php $cur = $r->status ?? 'Finished'; foreach ( $statuses as $s ) : ?>
                        <option value="<?php echo $s; ?>" <?php selected($cur,$s); ?>><?php echo $s; ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td><input type="text" class="msc-res-notes" value="<?php echo esc_attr($r->notes ?? ''); ?>" placeholder="Optional"></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>

        <!-- Manual Drivers -->
        <p style="font-weight:600;margin-bottom:6px">Manual Driver Entries <small style="font-weight:400;color:#888">(drivers without a website account)</small></p>
        <div style="overflow-x:auto;margin-bottom:12px">
        <table class="msc-dash-table msc-results-entry-table" id="msc-manual-results-table">
            <thead><tr>
                <th>Driver Name</th><th>Vehicle</th><th>Class</th>
                <th style="width:60px">Pos</th><th style="width:60px">Laps</th>
                <th style="width:100px">Best Lap</th><th style="width:100px">Total Time</th>
                <th style="width:90px">Status</th><th>Notes</th><th style="width:30px"></th>
            </tr></thead>
            <tbody id="msc-manual-tbody">
            <?php foreach ( $manual_results as $mr ) : ?>
            <tr class="msc-manual-row">
                <td><input type="text"   class="msc-man-name"   value="<?php echo esc_attr($mr->driver_name     ?? ''); ?>" placeholder="Full name"></td>
                <td><input type="text"   class="msc-man-veh"    value="<?php echo esc_attr($mr->manual_vehicle  ?? ''); ?>" placeholder="Make/Model"></td>
                <td>
                    <select class="msc-man-class">
                        <option value="">— None —</option>
                        <?php foreach ( $all_classes as $cls ) : ?>
                        <option value="<?php echo esc_attr($cls->term_id); ?>" <?php selected((int)($mr->class_id??0),(int)$cls->term_id); ?>><?php echo esc_html($cls->name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td><input type="number" class="msc-man-pos"   min="1" value="<?php echo esc_attr($mr->position        ?? ''); ?>"></td>
                <td><input type="number" class="msc-man-laps"  min="0" value="<?php echo esc_attr($mr->laps_completed  ?? ''); ?>"></td>
                <td><input type="text"   class="msc-man-best"  value="<?php echo esc_attr($mr->best_lap_time   ?? ''); ?>" placeholder="1:23.456"></td>
                <td><input type="text"   class="msc-man-total" value="<?php echo esc_attr($mr->total_race_time ?? ''); ?>" placeholder="0:45:12"></td>
                <td>
                    <select class="msc-man-status">
                        <?php $cur = $mr->status ?? 'Finished'; foreach ($statuses as $s) : ?>
                        <option value="<?php echo $s; ?>" <?php selected($cur,$s); ?>><?php echo $s; ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td><input type="text" class="msc-man-notes" value="<?php echo esc_attr($mr->notes ?? ''); ?>" placeholder="Optional"></td>
                <td><button type="button" class="msc-remove-manual" style="background:none;border:none;color:#c00;font-size:18px;cursor:pointer;padding:0">&times;</button></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <button type="button" class="msc-btn msc-btn-sm msc-btn-outline" id="msc-add-manual-row">+ Add Driver</button>

        <div style="margin-top:20px">
            <button type="button" class="msc-btn" id="msc-save-results" data-event-id="<?php echo $event_id; ?>">Save Results</button>
        </div>
        </div>

        <style>
        .msc-results-entry-table input[type="text"],
        .msc-results-entry-table input[type="number"] { width:100%; box-sizing:border-box; padding:5px 7px; border:1px solid #ddd; border-radius:3px; font-size:13px; }
        .msc-results-entry-table select { width:100%; padding:5px 7px; border:1px solid #ddd; border-radius:3px; font-size:13px; }
        </style>

        <script>
        (function($){
            var nonce   = '<?php echo wp_create_nonce('msc_nonce'); ?>';
            var ajaxUrl = '<?php echo esc_js( admin_url('admin-ajax.php') ); ?>';

            // Add manual row
            var classOptions = <?php
                $opts = '<option value="">— None —</option>';
                foreach ($all_classes as $cls) {
                    $opts .= '<option value="' . esc_attr($cls->term_id) . '">' . esc_html($cls->name) . '</option>';
                }
                echo json_encode($opts);
            ?>;
            var statuses = ['Finished','DNF','DNS','DSQ'];
            var statusOptions = statuses.map(function(s){ return '<option value="'+s+'">'+s+'</option>'; }).join('');

            $('#msc-add-manual-row').on('click', function(){
                var tr = $('<tr class="msc-manual-row">'+
                    '<td><input type="text"   class="msc-man-name"  placeholder="Full name"></td>'+
                    '<td><input type="text"   class="msc-man-veh"   placeholder="Make/Model"></td>'+
                    '<td><select class="msc-man-class">'+classOptions+'</select></td>'+
                    '<td><input type="number" class="msc-man-pos"  min="1"></td>'+
                    '<td><input type="number" class="msc-man-laps" min="0"></td>'+
                    '<td><input type="text"   class="msc-man-best"  placeholder="1:23.456"></td>'+
                    '<td><input type="text"   class="msc-man-total" placeholder="0:45:12"></td>'+
                    '<td><select class="msc-man-status">'+statusOptions+'</select></td>'+
                    '<td><input type="text"   class="msc-man-notes" placeholder="Optional"></td>'+
                    '<td><button type="button" class="msc-remove-manual" style="background:none;border:none;color:#c00;font-size:18px;cursor:pointer;padding:0">&times;</button></td>'+
                '</tr>');
                $('#msc-manual-tbody').append(tr);
            });

            $(document).on('click', '.msc-remove-manual', function(){
                $(this).closest('tr').remove();
            });

            // Save results
            $('#msc-save-results').on('click', function(){
                var btn      = $(this);
                var event_id = btn.data('event-id');
                var msg      = $('#msc-results-msg');
                var payload  = { action: 'msc_fe_save_results', nonce: nonce, event_id: event_id, results: [], manual: [] };

                // Registered drivers
                $('#msc-results-reg-table tbody tr').each(function(){
                    var row = $(this);
                    payload.results.push({
                        registration_id: row.data('reg-id'),
                        position:        row.find('.msc-res-pos').val(),
                        laps_completed:  row.find('.msc-res-laps').val(),
                        best_lap_time:   row.find('.msc-res-best').val(),
                        total_race_time: row.find('.msc-res-total').val(),
                        status:          row.find('.msc-res-status').val(),
                        notes:           row.find('.msc-res-notes').val(),
                    });
                });

                // Manual drivers
                $('#msc-manual-tbody tr.msc-manual-row').each(function(){
                    var row = $(this);
                    var name = row.find('.msc-man-name').val().trim();
                    if (!name) return;
                    payload.manual.push({
                        driver_name:     name,
                        manual_vehicle:  row.find('.msc-man-veh').val(),
                        class_id:        row.find('.msc-man-class').val(),
                        position:        row.find('.msc-man-pos').val(),
                        laps_completed:  row.find('.msc-man-laps').val(),
                        best_lap_time:   row.find('.msc-man-best').val(),
                        total_race_time: row.find('.msc-man-total').val(),
                        status:          row.find('.msc-man-status').val(),
                        notes:           row.find('.msc-man-notes').val(),
                    });
                });

                btn.prop('disabled', true).text('Saving…');
                $.post(ajaxUrl, payload, function(res){
                    btn.prop('disabled', false).text('Save Results');
                    if (res.success) {
                        msg.text('Results saved successfully.').css('color','green').show();
                        setTimeout(function(){ msg.fadeOut(); }, 3000);
                    } else {
                        msg.text(res.data.message || 'Error saving results.').css('color','red').show();
                    }
                });
            });
        })(jQuery);
        </script>
        <?php
    }

    // ─── Tab: Participants ────────────────────────────────────────────────────

    private static function tab_participants() {
        // Delegate to the existing participants class
        echo MSC_Admin_Participants::frontend_dashboard( array() );
    }

    // ─── AJAX: Create Event ───────────────────────────────────────────────────

    public static function ajax_create_event() {
        check_ajax_referer( 'msc_nonce', 'nonce' );
        if ( ! self::can_access() ) wp_send_json_error( array( 'message' => 'Unauthorized.' ) );

        $title = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );
        if ( ! $title ) wp_send_json_error( array( 'message' => 'Event title is required.' ) );

        $post_id = wp_insert_post( array(
            'post_title'  => $title,
            'post_type'   => 'msc_event',
            'post_status' => 'publish',
            'post_author' => get_current_user_id(),
        ), true );

        if ( is_wp_error( $post_id ) ) {
            wp_send_json_error( array( 'message' => $post_id->get_error_message() ) );
        }

        $text_meta = array(
            'msc_event_date', 'msc_event_end_date', 'msc_event_location',
            'msc_reg_open', 'msc_reg_close',
        );
        foreach ( $text_meta as $key ) {
            $val = sanitize_text_field( wp_unslash( $_POST[ str_replace( 'msc_', '', $key ) ] ?? '' ) );
            if ( $val ) update_post_meta( $post_id, '_' . $key, $val );
        }

        update_post_meta( $post_id, '_msc_entry_fee', floatval( $_POST['entry_fee'] ?? 0 ) );
        update_post_meta( $post_id, '_msc_capacity',  absint(  $_POST['capacity']   ?? 0 ) );

        $approval = in_array( $_POST['approval'] ?? '', array( 'instant', 'manual' ), true ) ? $_POST['approval'] : 'instant';
        update_post_meta( $post_id, '_msc_approval', $approval );

        $vehicle_type = in_array( $_POST['vehicle_type'] ?? '', array( 'Both', 'Car', 'Motorcycle' ), true ) ? $_POST['vehicle_type'] : 'Both';
        update_post_meta( $post_id, '_msc_event_vehicle_type', $vehicle_type );

        $indemnity = sanitize_textarea_field( wp_unslash( $_POST['indemnity'] ?? '' ) );
        update_post_meta( $post_id, '_msc_indemnity_text', $indemnity );

        $img_id = absint( $_POST['featured_image_id'] ?? 0 );
        if ( $img_id ) set_post_thumbnail( $post_id, $img_id );

        $class_ids  = array_filter( array_map( 'absint', (array) ( $_POST['class_ids'] ?? array() ) ) );
        $valid_ids  = array();
        if ( $class_ids ) {
            $terms = get_terms( array(
                'taxonomy'   => 'msc_vehicle_class',
                'hide_empty' => false,
                'include'    => $class_ids,
                'fields'     => 'ids',
            ) );
            $valid_ids = is_wp_error( $terms ) ? array() : array_map( 'intval', $terms );
        }
        update_post_meta( $post_id, '_msc_event_classes', $valid_ids );
        wp_set_post_terms( $post_id, $valid_ids, 'msc_vehicle_class' );

        // Per-class additional fees
        $class_fees = array();
        if ( ! empty( $_POST['class_fees'] ) && is_array( $_POST['class_fees'] ) ) {
            foreach ( $_POST['class_fees'] as $class_id => $fee ) {
                $class_fees[ intval( $class_id ) ] = round( floatval( $fee ), 2 );
            }
        }
        update_post_meta( $post_id, '_msc_class_fees', $class_fees );

        wp_send_json_success( array( 'message' => 'Event created.', 'post_id' => $post_id ) );
    }

    // ─── AJAX: Update Event ───────────────────────────────────────────────────

    public static function ajax_update_event() {
        check_ajax_referer( 'msc_nonce', 'nonce' );
        if ( ! self::can_access() ) wp_send_json_error( array( 'message' => 'Unauthorized.' ) );

        $event_id = absint( $_POST['event_id'] ?? 0 );
        if ( ! $event_id ) wp_send_json_error( array( 'message' => 'Invalid event.' ) );

        $post = get_post( $event_id );
        if ( ! $post || $post->post_type !== 'msc_event' ) {
            wp_send_json_error( array( 'message' => 'Event not found.' ) );
        }
        if ( ! self::can_manage_event( $event_id ) ) {
            wp_send_json_error( array( 'message' => 'You cannot modify this event.' ) );
        }

        $title = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );
        if ( ! $title ) wp_send_json_error( array( 'message' => 'Event title is required.' ) );

        wp_update_post( array(
            'ID'         => $event_id,
            'post_title' => $title,
        ) );

        $text_meta = array(
            'msc_event_date', 'msc_event_end_date', 'msc_event_location',
            'msc_reg_open', 'msc_reg_close',
        );
        foreach ( $text_meta as $key ) {
            $val = sanitize_text_field( wp_unslash( $_POST[ str_replace( 'msc_', '', $key ) ] ?? '' ) );
            update_post_meta( $event_id, '_' . $key, $val );
        }

        update_post_meta( $event_id, '_msc_entry_fee', floatval( $_POST['entry_fee'] ?? 0 ) );
        update_post_meta( $event_id, '_msc_capacity',  absint(  $_POST['capacity']   ?? 0 ) );

        $approval = in_array( $_POST['approval'] ?? '', array( 'instant', 'manual' ), true ) ? $_POST['approval'] : 'instant';
        update_post_meta( $event_id, '_msc_approval', $approval );

        $vehicle_type = in_array( $_POST['vehicle_type'] ?? '', array( 'Both', 'Car', 'Motorcycle' ), true ) ? $_POST['vehicle_type'] : 'Both';
        update_post_meta( $event_id, '_msc_event_vehicle_type', $vehicle_type );

        $indemnity = sanitize_textarea_field( wp_unslash( $_POST['indemnity'] ?? '' ) );
        update_post_meta( $event_id, '_msc_indemnity_text', $indemnity );

        $img_id = absint( $_POST['featured_image_id'] ?? 0 );
        if ( $img_id ) {
            set_post_thumbnail( $event_id, $img_id );
        } else {
            delete_post_thumbnail( $event_id );
        }

        $class_ids = array_filter( array_map( 'absint', (array) ( $_POST['class_ids'] ?? array() ) ) );
        $valid_ids = array();
        if ( $class_ids ) {
            $terms = get_terms( array(
                'taxonomy'   => 'msc_vehicle_class',
                'hide_empty' => false,
                'include'    => $class_ids,
                'fields'     => 'ids',
            ) );
            $valid_ids = is_wp_error( $terms ) ? array() : array_map( 'intval', $terms );
        }
        update_post_meta( $event_id, '_msc_event_classes', $valid_ids );
        wp_set_post_terms( $event_id, $valid_ids, 'msc_vehicle_class' );

        $class_fees = array();
        if ( ! empty( $_POST['class_fees'] ) && is_array( $_POST['class_fees'] ) ) {
            foreach ( $_POST['class_fees'] as $class_id => $fee ) {
                $class_fees[ intval( $class_id ) ] = round( floatval( $fee ), 2 );
            }
        }
        update_post_meta( $event_id, '_msc_class_fees', $class_fees );

        wp_send_json_success( array( 'message' => 'Event updated.' ) );
    }

    // ─── AJAX: Set Event Status ───────────────────────────────────────────────

    public static function ajax_set_event_status() {
        check_ajax_referer( 'msc_nonce', 'nonce' );
        if ( ! self::can_access() ) wp_send_json_error( array( 'message' => 'Unauthorized.' ) );

        $event_id = absint( $_POST['event_id'] ?? 0 );
        $status   = in_array( $_POST['status'] ?? '', array( 'open', 'closed' ), true ) ? $_POST['status'] : '';

        if ( ! $event_id || ! $status ) wp_send_json_error( array( 'message' => 'Invalid request.' ) );

        $post = get_post( $event_id );
        if ( ! $post || $post->post_type !== 'msc_event' ) {
            wp_send_json_error( array( 'message' => 'Event not found.' ) );
        }
        if ( ! self::can_manage_event( $event_id ) ) {
            wp_send_json_error( array( 'message' => 'You cannot modify this event.' ) );
        }

        update_post_meta( $event_id, '_msc_event_status', $status );
        wp_send_json_success( array( 'message' => 'Event status updated.' ) );
    }

    // ─── AJAX: Update Registration Status ────────────────────────────────────

    public static function ajax_update_reg_status() {
        check_ajax_referer( 'msc_nonce', 'nonce' );
        if ( ! self::can_access() ) wp_send_json_error( array( 'message' => 'Unauthorized.' ) );

        global $wpdb;
        $reg_id = absint( $_POST['reg_id'] ?? 0 );
        $valid  = array( 'pending', 'confirmed', 'rejected', 'cancelled' );
        $status = in_array( $_POST['status'] ?? '', $valid, true ) ? $_POST['status'] : '';

        if ( ! $reg_id || ! $status ) wp_send_json_error( array( 'message' => 'Invalid request.' ) );

        $event_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT event_id FROM {$wpdb->prefix}msc_registrations WHERE id = %d",
            $reg_id
        ) );
        if ( ! $event_id ) {
            wp_send_json_error( array( 'message' => 'Registration not found.' ) );
        }
        if ( ! self::can_manage_event( $event_id ) ) {
            wp_send_json_error( array( 'message' => 'You cannot modify this registration.' ) );
        }

        $wpdb->update(
            $wpdb->prefix . 'msc_registrations',
            array( 'status' => $status ),
            array( 'id'     => $reg_id ),
            array( '%s' ),
            array( '%d' )
        );

        if ( $status === 'confirmed' ) {
            MSC_Emails::send_confirmation( $reg_id );
        }

        wp_send_json_success( array( 'message' => 'Registration updated.' ) );
    }

    // ─── AJAX: Save Results ───────────────────────────────────────────────────

    public static function ajax_save_results() {
        check_ajax_referer( 'msc_nonce', 'nonce' );
        if ( ! self::can_access() ) wp_send_json_error( array( 'message' => 'Unauthorized.' ) );

        global $wpdb;
        $event_id = absint( $_POST['event_id'] ?? 0 );
        if ( ! $event_id ) wp_send_json_error( array( 'message' => 'Invalid event.' ) );
        if ( ! self::can_manage_event( $event_id ) ) {
            wp_send_json_error( array( 'message' => 'You cannot modify results for this event.' ) );
        }

        $res_table      = $wpdb->prefix . 'msc_event_results';
        $valid_statuses = array( 'Finished', 'DNF', 'DNS', 'DSQ' );

        // ── Registered drivers ──────────────────────────────────────────────
        $results = $_POST['results'] ?? array();
        if ( is_array( $results ) ) {
            foreach ( $results as $data ) {
                $reg_id = absint( $data['registration_id'] ?? 0 );
                if ( ! $reg_id ) continue;

                $row = array(
                    'event_id'        => $event_id,
                    'registration_id' => $reg_id,
                    'position'        => ( isset($data['position']) && $data['position'] !== '' ) ? absint($data['position']) : null,
                    'laps_completed'  => ( isset($data['laps_completed']) && $data['laps_completed'] !== '' ) ? absint($data['laps_completed']) : null,
                    'best_lap_time'   => sanitize_text_field($data['best_lap_time']   ?? '') ?: null,
                    'total_race_time' => sanitize_text_field($data['total_race_time'] ?? '') ?: null,
                    'status'          => in_array($data['status'] ?? '', $valid_statuses, true) ? $data['status'] : 'Finished',
                    'notes'           => sanitize_textarea_field($data['notes'] ?? '') ?: null,
                );

                $existing = $wpdb->get_var( $wpdb->prepare(
                    "SELECT id FROM $res_table WHERE event_id = %d AND registration_id = %d",
                    $event_id, $reg_id
                ) );
                if ( $existing ) {
                    $wpdb->update( $res_table, $row, array('id'=>$existing), array('%d','%d','%d','%d','%s','%s','%s','%s'), array('%d') );
                } else {
                    $wpdb->insert( $res_table, $row, array('%d','%d','%d','%d','%s','%s','%s','%s') );
                }
            }
        }

        // ── Manual drivers (delete-then-reinsert) ───────────────────────────
        $wpdb->query( $wpdb->prepare( "DELETE FROM $res_table WHERE event_id = %d AND registration_id IS NULL", $event_id ) );

        $manual = $_POST['manual'] ?? array();
        if ( is_array( $manual ) ) {
            foreach ( $manual as $data ) {
                $driver_name = sanitize_text_field( $data['driver_name'] ?? '' );
                if ( ! $driver_name ) continue;
                $row = array(
                    'event_id'        => $event_id,
                    'registration_id' => null,
                    'driver_name'     => $driver_name,
                    'manual_vehicle'  => sanitize_text_field($data['manual_vehicle'] ?? '') ?: null,
                    'class_id'        => ( isset($data['class_id']) && $data['class_id'] !== '' ) ? absint($data['class_id']) : null,
                    'position'        => ( isset($data['position']) && $data['position'] !== '' ) ? absint($data['position']) : null,
                    'laps_completed'  => ( isset($data['laps_completed']) && $data['laps_completed'] !== '' ) ? absint($data['laps_completed']) : null,
                    'best_lap_time'   => sanitize_text_field($data['best_lap_time']   ?? '') ?: null,
                    'total_race_time' => sanitize_text_field($data['total_race_time'] ?? '') ?: null,
                    'status'          => in_array($data['status'] ?? '', $valid_statuses, true) ? $data['status'] : 'Finished',
                    'notes'           => sanitize_textarea_field($data['notes'] ?? '') ?: null,
                );
                $wpdb->insert( $res_table, $row, array('%d','%d','%s','%s','%d','%d','%d','%s','%s','%s','%s') );
            }
        }

        wp_send_json_success( array( 'message' => 'Results saved.' ) );
    }

    // ─── Tab: Vehicle Classes ─────────────────────────────────────────────────

    private static function tab_vehicle_classes() {
        $classes_by_type = MSC_Taxonomies::get_classes_by_type();
        $nonce           = wp_create_nonce( 'msc_nonce' );
        $ajax_url        = admin_url( 'admin-ajax.php' );
        ?>
        <div class="msc-tab-content">
            <div class="msc-tab-header">
                <h3 class="msc-tab-title">Vehicle Classes</h3>
            </div>

            <div id="msc-vc-msg" class="msc-field-msg" style="margin-bottom:12px;display:none"></div>

            <!-- Add Class Form -->
            <div class="msc-panel" style="padding:16px;margin-bottom:24px">
                <h4 style="margin:0 0 12px">Add New Class</h4>
                <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
                    <div class="msc-field" style="margin:0;flex:1;min-width:160px">
                        <label>Class Name <span class="msc-required">*</span></label>
                        <input type="text" id="vc-new-name" placeholder="e.g. Superbikes">
                    </div>
                    <div class="msc-field" style="margin:0">
                        <label>Vehicle Type</label>
                        <select id="vc-new-type">
                            <option value="Car">Car</option>
                            <option value="Motorcycle">Motorcycle</option>
                        </select>
                    </div>
                    <button type="button" class="msc-btn" id="msc-vc-add-btn">Add Class</button>
                </div>
            </div>

            <!-- Classes Table -->
            <?php foreach ( $classes_by_type as $type => $classes ) : ?>
            <div class="msc-panel" style="padding:16px;margin-bottom:20px" data-vc-type="<?php echo esc_attr($type); ?>">
                <h4 style="margin:0 0 12px"><?php echo esc_html($type); ?> Classes</h4>
                <?php if ( empty($classes) ) : ?>
                <p style="color:#888;margin:0">No <?php echo esc_html($type); ?> classes yet.</p>
                <?php else : ?>
                <table class="msc-dash-table" style="max-width:600px">
                    <thead><tr><th>Class Name</th><th style="width:120px">Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ( $classes as $term_id => $name ) : ?>
                    <tr data-term-id="<?php echo esc_attr($term_id); ?>">
                        <td>
                            <span class="vc-name-display"><?php echo esc_html($name); ?></span>
                            <input type="text" class="vc-name-input" value="<?php echo esc_attr($name); ?>"
                                   style="display:none;width:100%;box-sizing:border-box">
                        </td>
                        <td>
                            <div style="display:flex;gap:6px">
                                <button type="button" class="msc-btn msc-btn-sm msc-btn-outline vc-rename-btn">Rename</button>
                                <button type="button" class="msc-btn msc-btn-sm vc-save-btn" style="display:none">Save</button>
                                <button type="button" class="msc-btn msc-btn-sm msc-btn-outline vc-cancel-btn" style="display:none">Cancel</button>
                                <button type="button" class="msc-btn msc-btn-sm msc-btn-danger vc-delete-btn">Delete</button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>

        <script>
        (function($){
            var nonce   = '<?php echo esc_js( $nonce ); ?>';
            var ajaxUrl = '<?php echo esc_js( $ajax_url ); ?>';

            function vcMsg(text, ok) {
                $('#msc-vc-msg').text(text).css('color', ok ? 'green' : 'red').show();
            }

            // Add class
            $('#msc-vc-add-btn').on('click', function(){
                var btn  = $(this);
                var name = $('#vc-new-name').val().trim();
                var type = $('#vc-new-type').val();
                if (!name) { vcMsg('Class name is required.', false); return; }
                btn.prop('disabled', true).text('Adding…');
                $.post(ajaxUrl, { action: 'msc_fe_add_class', nonce: nonce, name: name, vehicle_type: type }, function(res){
                    btn.prop('disabled', false).text('Add Class');
                    if (res.success) {
                        vcMsg('Class added! Reloading…', true);
                        setTimeout(function(){ location.reload(); }, 800);
                    } else {
                        vcMsg(res.data.message || 'Error.', false);
                    }
                });
            });

            // Rename: show inline input
            $(document).on('click', '.vc-rename-btn', function(){
                var row = $(this).closest('tr');
                row.find('.vc-name-display').hide();
                row.find('.vc-name-input').show().focus();
                row.find('.vc-rename-btn, .vc-delete-btn').hide();
                row.find('.vc-save-btn, .vc-cancel-btn').show();
            });

            // Cancel rename
            $(document).on('click', '.vc-cancel-btn', function(){
                var row = $(this).closest('tr');
                var original = row.find('.vc-name-display').text();
                row.find('.vc-name-input').val(original).hide();
                row.find('.vc-name-display').show();
                row.find('.vc-rename-btn, .vc-delete-btn').show();
                row.find('.vc-save-btn, .vc-cancel-btn').hide();
            });

            // Save rename
            $(document).on('click', '.vc-save-btn', function(){
                var btn     = $(this);
                var row     = btn.closest('tr');
                var termId  = row.data('term-id');
                var newName = row.find('.vc-name-input').val().trim();
                if (!newName) { vcMsg('Class name cannot be empty.', false); return; }
                btn.prop('disabled', true).text('Saving…');
                $.post(ajaxUrl, { action: 'msc_fe_rename_class', nonce: nonce, term_id: termId, name: newName }, function(res){
                    btn.prop('disabled', false).text('Save');
                    if (res.success) {
                        row.find('.vc-name-display').text(newName).show();
                        row.find('.vc-name-input').hide();
                        row.find('.vc-rename-btn, .vc-delete-btn').show();
                        row.find('.vc-save-btn, .vc-cancel-btn').hide();
                        vcMsg('Class renamed.', true);
                    } else {
                        vcMsg(res.data.message || 'Error.', false);
                    }
                });
            });

            // Delete
            $(document).on('click', '.vc-delete-btn', function(){
                var btn    = $(this);
                var row    = btn.closest('tr');
                var termId = row.data('term-id');
                var name   = row.find('.vc-name-display').text();
                if (!confirm('Delete class "' + name + '"? It will be removed from any events that use it.')) return;
                btn.prop('disabled', true).text('Deleting…');
                $.post(ajaxUrl, { action: 'msc_fe_delete_class', nonce: nonce, term_id: termId }, function(res){
                    if (res.success) {
                        row.fadeOut(300, function(){ $(this).remove(); });
                        vcMsg('Class deleted.', true);
                    } else {
                        btn.prop('disabled', false).text('Delete');
                        vcMsg(res.data.message || 'Error.', false);
                    }
                });
            });
        })(jQuery);
        </script>
        <?php
    }

    // ─── AJAX: Add Vehicle Class ──────────────────────────────────────────────

    public static function ajax_add_class() {
        check_ajax_referer( 'msc_nonce', 'nonce' );
        if ( ! self::can_access() ) wp_send_json_error( array( 'message' => 'Unauthorized.' ) );

        $name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
        if ( ! $name ) wp_send_json_error( array( 'message' => 'Class name is required.' ) );

        $type = in_array( $_POST['vehicle_type'] ?? '', array( 'Car', 'Motorcycle' ), true )
            ? $_POST['vehicle_type'] : 'Car';

        $result = wp_insert_term( $name, 'msc_vehicle_class' );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        update_term_meta( $result['term_id'], 'msc_vehicle_type', $type );
        wp_send_json_success( array( 'message' => 'Class added.', 'term_id' => $result['term_id'] ) );
    }

    // ─── AJAX: Rename Vehicle Class ───────────────────────────────────────────

    public static function ajax_rename_class() {
        check_ajax_referer( 'msc_nonce', 'nonce' );
        if ( ! self::can_access() ) wp_send_json_error( array( 'message' => 'Unauthorized.' ) );

        $term_id = absint( $_POST['term_id'] ?? 0 );
        $name    = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );

        if ( ! $term_id ) wp_send_json_error( array( 'message' => 'Invalid class.' ) );
        if ( ! $name )    wp_send_json_error( array( 'message' => 'Class name is required.' ) );

        $term = get_term( $term_id, 'msc_vehicle_class' );
        if ( ! $term || is_wp_error( $term ) ) {
            wp_send_json_error( array( 'message' => 'Class not found.' ) );
        }

        $result = wp_update_term( $term_id, 'msc_vehicle_class', array( 'name' => $name ) );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( array( 'message' => 'Class renamed.' ) );
    }

    // ─── AJAX: Delete Vehicle Class ───────────────────────────────────────────

    public static function ajax_delete_class() {
        check_ajax_referer( 'msc_nonce', 'nonce' );
        if ( ! self::can_access() ) wp_send_json_error( array( 'message' => 'Unauthorized.' ) );

        $term_id = absint( $_POST['term_id'] ?? 0 );
        if ( ! $term_id ) wp_send_json_error( array( 'message' => 'Invalid class.' ) );

        $term = get_term( $term_id, 'msc_vehicle_class' );
        if ( ! $term || is_wp_error( $term ) ) {
            wp_send_json_error( array( 'message' => 'Class not found.' ) );
        }

        $result = wp_delete_term( $term_id, 'msc_vehicle_class' );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( array( 'message' => 'Class deleted.' ) );
    }

    // ─── Helper ───────────────────────────────────────────────────────────────

    private static function get_reg_counts() {
        global $wpdb;
        $sql = "SELECT r.event_id, COUNT(*) AS cnt
                FROM {$wpdb->prefix}msc_registrations r";
        $where = " WHERE r.status NOT IN ('cancelled','rejected')";

        if ( ! current_user_can( 'manage_options' ) && ! self::is_shared_ops_mode() ) {
            $sql .= " INNER JOIN {$wpdb->posts} p ON p.ID = r.event_id";
            $where .= $wpdb->prepare( ' AND p.post_author = %d', get_current_user_id() );
        }

        $rows = $wpdb->get_results( $sql . $where . ' GROUP BY r.event_id' );
        $counts = array();
        foreach ( $rows as $r ) {
            $counts[ $r->event_id ] = (int) $r->cnt;
        }
        return $counts;
    }
}
