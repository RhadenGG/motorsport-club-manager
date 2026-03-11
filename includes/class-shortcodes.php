<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class MSC_Shortcodes {

    public static function init() {
        add_shortcode( 'msc_events_list',     array( __CLASS__, 'events_list' ) );
        add_shortcode( 'msc_next_event',      array( __CLASS__, 'next_event' ) );
        add_shortcode( 'msc_register_event',  array( __CLASS__, 'register_form' ) );
        add_action(    'wp_enqueue_scripts',  array( __CLASS__, 'enqueue' ) );
        add_filter( 'the_content',       array( __CLASS__, 'append_to_event' ) );
        add_filter( 'post_thumbnail_html', array( __CLASS__, 'suppress_event_thumbnail' ), 10, 2 );
    }

    public static function enqueue() {
        wp_enqueue_style(  'msc-frontend', MSC_URL . 'assets/css/frontend.css', array(), MSC_VERSION );
        wp_enqueue_script( 'msc-signature', 'https://cdn.jsdelivr.net/npm/signature_pad@4.1.7/dist/signature_pad.umd.min.js', array(), null, true );
        wp_enqueue_script( 'msc-frontend',  MSC_URL . 'assets/js/frontend.js', array('jquery','msc-signature'), MSC_VERSION, true );
        wp_localize_script( 'msc-frontend', 'mscData', array(
            'ajaxUrl'  => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('msc_nonce'),
            'loginUrl' => MSC_Auth::login_url(),
            'loggedIn' => is_user_logged_in(),
        ) );
    }

    public static function suppress_event_thumbnail( $html, $post_id ) {
        if ( is_singular('msc_event') ) {
            return '';
        }
        return $html;
    }

    public static function append_to_event( $content ) {
        if ( is_singular('msc_event') && in_the_loop() && is_main_query() ) {
            $event_id = get_the_ID();
            $thumb_id = get_post_thumbnail_id( $event_id );
            if ( $thumb_id ) {
                $full_url = wp_get_attachment_image_url( $thumb_id, 'full' );
                $img      = wp_get_attachment_image( $thumb_id, 'large' );
                $img_wrap = '<a href="' . esc_url( $full_url ) . '" class="msc-lightbox-trigger" aria-label="View full image">' . $img . '</a>';
                $content  = '<div class="msc-event-hero" style="display:flex;gap:28px;align-items:flex-start;margin-bottom:20px;">'
                    . '<div class="msc-event-hero-img" style="flex:0 0 40%;max-width:40%;">' . $img_wrap . '</div>'
                    . '<div class="msc-event-hero-body" style="flex:1;min-width:0;">' . $content . '</div>'
                    . '</div>';
            }
            $content .= self::render_event_meta( $event_id );
            $content .= self::register_form( array( 'event_id' => $event_id ) );
            if ( MSC_Results::is_closed( $event_id ) ) {
                $content .= MSC_Results::get_results_html( $event_id );
            }
        }
        return $content;
    }

    private static function render_event_meta( $event_id ) {
        $date     = get_post_meta($event_id,'_msc_event_date',true);
        $end_date = get_post_meta($event_id,'_msc_event_end_date',true);
        $location = get_post_meta($event_id,'_msc_event_location',true);
        $price    = MSC_Pricing::get_event_starting_price( $event_id );
        $capacity = get_post_meta($event_id,'_msc_capacity',true);
        $classes  = MSC_Taxonomies::get_event_classes($event_id);
        $class_names = array();
        foreach($classes as $cid) {
            $t = get_term($cid,'msc_vehicle_class');
            if ($t && !is_wp_error($t)) $class_names[] = $t->name;
        }

        global $wpdb;
        $reg_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}msc_registrations WHERE event_id=%d AND status NOT IN ('rejected','cancelled')", $event_id
        ));

        $html = '<div class="msc-event-meta">';
        $items = array();
        if ($date)     $items[] = array('📅','Date', esc_html( date('D d F Y @ H:i', strtotime($date)) . ($end_date ? ' – '.date('H:i', strtotime($end_date)) : '') ));
        if ($location) $items[] = array('📍','Location', esc_html($location));
        $items[] = array('💰','Entry Fee', $price > 0 ? 'From R '.number_format($price,2) : 'Free');
        if ($capacity) $items[] = array('👥','Entries', esc_html($reg_count.' / '.$capacity));
        else           $items[] = array('👥','Entries', esc_html($reg_count.' registered'));
        if (!empty($class_names)) $items[] = array('🏷','Classes', esc_html(implode(', ', $class_names)));

        // Show closed badge if event is closed
        if ( MSC_Results::is_closed( $event_id ) ) {
            $items[] = array('🔴','Status','Event Closed — Results Available Below');
        }

        foreach($items as $i) {
            $html .= "<div class='msc-meta-row'><span class='msc-meta-icon'>{$i[0]}</span><span class='msc-meta-label'>{$i[1]}</span><span class='msc-meta-value'>{$i[2]}</span></div>";
        }
        $html .= '</div>';
        return $html;
    }

    public static function next_event( $atts = array() ) {
        $events = get_posts( array(
            'post_type'      => 'msc_event',
            'posts_per_page' => 1,
            'post_status'    => 'publish',
            'orderby'        => 'meta_value',
            'meta_key'       => '_msc_event_date',
            'order'          => 'ASC',
            'meta_query'     => array(
                'relation' => 'AND',
                array(
                    'key'     => '_msc_event_date',
                    'value'   => current_time('Y-m-d\TH:i'),
                    'compare' => '>=',
                    'type'    => 'DATETIME',
                ),
                array(
                    'relation' => 'OR',
                    array( 'key' => '_msc_event_status', 'compare' => 'NOT EXISTS' ),
                    array( 'key' => '_msc_event_status', 'value' => 'closed', 'compare' => '!=' ),
                ),
            ),
        ) );

        if ( empty( $events ) ) {
            return '<p class="msc-no-events">No upcoming events at the moment.</p>';
        }

        $e        = $events[0];
        $date     = get_post_meta( $e->ID, '_msc_event_date', true );
        $location = get_post_meta( $e->ID, '_msc_event_location', true );
        $price    = MSC_Pricing::get_event_starting_price( $e->ID );
        $thumb_id = get_post_thumbnail_id( $e->ID );
        $url      = get_permalink( $e->ID );

        $html  = '<div class="msc-next-event">';
        if ( $thumb_id ) {
            $html .= '<a href="' . esc_url( $url ) . '" class="msc-next-event-img-wrap">'
                   . wp_get_attachment_image( $thumb_id, 'medium', false, array( 'class' => 'msc-next-event-img' ) )
                   . '</a>';
        } else {
            $html .= '<div class="msc-next-event-img-wrap msc-next-event-placeholder">🏁</div>';
        }
        $html .= '<div class="msc-next-event-body">';
        $html .= '<p class="msc-next-event-label">Next Event</p>';
        $html .= '<h4 class="msc-next-event-title"><a href="' . esc_url( $url ) . '">' . esc_html( $e->post_title ) . '</a></h4>';
        if ( $date )     $html .= '<p class="msc-next-event-meta">📅 ' . esc_html( date_i18n( 'D d F Y', strtotime( $date ) ) ) . '</p>';
        if ( $location ) $html .= '<p class="msc-next-event-meta">📍 ' . esc_html( $location ) . '</p>';
        $html .= '<p class="msc-next-event-meta">💰 ' . ( $price > 0 ? 'From R ' . number_format( $price, 2 ) : 'Free' ) . '</p>';
        $html .= '<a href="' . esc_url( $url ) . '" class="msc-btn msc-btn-sm" style="margin-top:6px;display:inline-block;">View &amp; Register</a>';
        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    public static function events_list( $atts = array() ) {
        global $wpdb;
        $atts = shortcode_atts( array( 'count' => 10, 'show_past' => 0 ), $atts );
        $args = array(
            'post_type'      => 'msc_event',
            'posts_per_page' => intval($atts['count']),
            'post_status'    => 'publish',
            'orderby'        => 'meta_value',
            'meta_key'       => '_msc_event_date',
            'order'          => 'DESC',
        );
        if ( ! $atts['show_past'] ) {
            $args['meta_query'] = array(array(
                'key'     => '_msc_event_date',
                'value'   => current_time('Y-m-d\TH:i'),
                'compare' => '>=',
                'type'    => 'DATETIME',
            ));
        }
        $events = get_posts($args);
        if (empty($events)) return '<p class="msc-no-events">No upcoming events at the moment. Check back soon!</p>';

        ob_start();
        echo '<div class="msc-events-grid">';
        foreach($events as $e) {
            $date     = get_post_meta($e->ID,'_msc_event_date',true);
            $location = get_post_meta($e->ID,'_msc_event_location',true);
            $price    = MSC_Pricing::get_event_starting_price( $e->ID );
            $terms    = get_the_terms($e->ID,'msc_vehicle_class');
            $closed   = MSC_Results::is_closed( $e->ID );
            $reg_count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}msc_registrations WHERE event_id=%d AND status NOT IN ('rejected','cancelled')",$e->ID));
            $capacity  = get_post_meta($e->ID,'_msc_capacity',true);
            $full      = $capacity && $reg_count >= $capacity;
            ?>
            <div class="msc-event-card <?php echo $full ? 'msc-event-full' : ''; ?> <?php echo $closed ? 'msc-event-closed' : ''; ?>">
                <?php if(has_post_thumbnail($e->ID)): ?>
                <div class="msc-event-thumb"><?php echo get_the_post_thumbnail($e->ID,'medium') ?></div>
                <?php else: ?>
                <div class="msc-event-thumb msc-event-thumb-placeholder">🏁</div>
                <?php endif ?>
                <div class="msc-event-body">
                    <?php if($closed): ?>
                        <span class="msc-badge msc-badge-closed">RESULTS AVAILABLE</span>
                    <?php elseif($full): ?>
                        <span class="msc-badge msc-badge-full">FULL</span>
                    <?php endif; ?>
                    <h3 class="msc-event-title"><a href="<?php echo esc_url( get_permalink($e->ID) ) ?>"><?php echo esc_html($e->post_title) ?></a></h3>
                    <?php if($date): ?><p class="msc-event-date">📅 <?php echo esc_html(date('D d F Y',strtotime($date))) ?></p><?php endif ?>
                    <?php if($location): ?><p class="msc-event-location">📍 <?php echo esc_html($location) ?></p><?php endif ?>
                    <?php if(!empty($terms) && !is_wp_error($terms)): ?>
                    <p class="msc-event-classes"><?php foreach($terms as $t) echo "<span class='msc-class-pill'>".esc_html($t->name)."</span> " ?></p>
                    <?php endif ?>
                    <div class="msc-event-footer">
                        <span class="msc-event-fee"><?php echo $price>0 ? esc_html('From R '.number_format($price,2)) : 'Free' ?></span>
                        <?php if($closed): ?>
                            <a href="<?php echo esc_url( get_permalink($e->ID) ) ?>" class="msc-btn">View Results</a>
                        <?php else: ?>
                            <a href="<?php echo esc_url( get_permalink($e->ID) ) ?>" class="msc-btn <?php echo $full?'msc-btn-disabled':'' ?>"><?php echo $full?'View Event':'Register Now' ?></a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php
        }
        echo '</div>';
        return ob_get_clean();
    }

    public static function register_form( $atts = array() ) {
        $atts     = shortcode_atts( array('event_id' => get_the_ID()), $atts );
        $event_id = intval($atts['event_id']);
        $event    = get_post($event_id);
        if (!$event || $event->post_type !== 'msc_event') return '';

        // If event is closed, don't show registration form
        if ( MSC_Results::is_closed( $event_id ) ) {
            return '<div class="msc-notice msc-notice-info">🔴 This event has now closed. See the results below.</div>';
        }

        $reg_open  = get_post_meta($event_id,'_msc_reg_open',true);
        $reg_close = get_post_meta($event_id,'_msc_reg_close',true);
        $now       = current_time('timestamp');
        $indemnity = get_post_meta( $event_id, '_msc_indemnity_text', true );
        if ( ! $indemnity ) {
            $indemnity = get_option( 'msc_default_indemnity', msc_get_default_indemnity() );
        }
        $fee            = floatval( get_post_meta( $event_id, '_msc_entry_fee', true ) );
        $pricing_set_id = (int) get_post_meta( $event_id, '_msc_pricing_set_id', true );
        $approval       = get_post_meta( $event_id, '_msc_approval', true ) ?: 'instant';

        // Build class data for the registration form (includes two-tier fees)
        $set_fees        = $pricing_set_id ? MSC_Pricing::get_set_fees( $pricing_set_id ) : array();
        $event_class_ids = get_post_meta( $event_id, '_msc_event_classes', true );
        $event_class_ids = $event_class_ids ? array_map( 'intval', (array) $event_class_ids ) : array();
        $event_classes_for_form = array();
        foreach ( $event_class_ids as $cid ) {
            $term = get_term( $cid, 'msc_vehicle_class' );
            if ( $term && ! is_wp_error( $term ) ) {
                $fees = isset( $set_fees[ $cid ] ) ? $set_fees[ $cid ] : array( 'primary_fee' => 0.0, 'additional_fee' => 0.0, 'override' => null, 'exempt' => 0, 'primary_only' => 0 );
                $event_classes_for_form[] = array(
                    'id'             => $cid,
                    'name'           => $term->name,
                    'vtype'          => get_term_meta( $cid, 'msc_vehicle_type', true ) ?: '',
                    'primary_fee'    => $fees['primary_fee'],
                    'additional_fee' => $fees['additional_fee'],
                    'override'       => isset($fees['override']) ? $fees['override'] : null,
                    'exempt'         => isset($fees['exempt']) ? (int) $fees['exempt'] : 0,
                    'primary_only'   => isset($fees['primary_only']) ? (int) $fees['primary_only'] : 0,
                );
            }
        }

        if ($reg_open  && strtotime($reg_open)  > $now) return '<div class="msc-notice msc-notice-info">Registration opens on '.date('D d F Y @ H:i',strtotime($reg_open)).'.</div>';
        if ($reg_close && strtotime($reg_close) < $now) return '<div class="msc-notice msc-notice-warning">Registration for this event is now closed.</div>';

        if (!is_user_logged_in()) {
            $login_url    = add_query_arg( 'redirect_to', urlencode( get_permalink() ), MSC_Auth::login_url() );
            $register_url = MSC_Auth::register_url();
            return '<div class="msc-notice msc-notice-info"><p>You must be logged in to register for this event.</p><a href="' . esc_url( $login_url ) . '" class="msc-btn">Log In</a> <a href="' . esc_url( $register_url ) . '" class="msc-btn msc-btn-outline">Register Account</a></div>';
        }

        $user_id = get_current_user_id();

        $required_fields = array(
            'msc_birthday'           => 'Date of Birth',
            'msc_comp_number'        => 'Competition Number',
            'msc_msa_licence'        => 'MSA License Number',
            'msc_medical_aid'        => 'Medical Aid Provider',
            'msc_medical_aid_number' => 'Medical Aid Number',
            'msc_gender'             => 'Gender',
        );
        $missing = array();
        foreach ( $required_fields as $key => $label ) {
            if ( ! get_user_meta( $user_id, $key, true ) ) {
                $missing[] = $label;
            }
        }
        if ( $missing ) {
            return '<div class="msc-notice msc-notice-warning">
                <p><strong>Profile Incomplete:</strong> The following fields are required before you can register: <strong>' . esc_html( implode( ', ', $missing ) ) . '</strong>.</p>
                <a href="' . esc_url( msc_get_account_url( 'profile' ) ) . '" class="msc-btn msc-btn-sm">Complete My Profile →</a>
            </div>';
        }

        $birthday = get_user_meta( $user_id, 'msc_birthday', true );

        // Calculate age
        try {
            $dob    = new DateTime( $birthday );
            $now_dt = new DateTime();
            $age    = $now_dt->diff( $dob )->y;
        } catch ( Exception $e ) {
            $age = 99; // Treat invalid/missing birthday as adult
        }
        $is_minor = ($age < 18);

        if (MSC_Registration::user_is_registered($user_id, $event_id)) {
            return '<div class="msc-notice msc-notice-success">✓ You are already registered for this event. <a href="' . esc_url( msc_get_account_url( 'registrations' ) ) . '">View your registration</a></div>';
        }

        global $wpdb;
        $capacity  = intval(get_post_meta($event_id,'_msc_capacity',true));
        $reg_count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}msc_registrations WHERE event_id=%d AND status NOT IN ('rejected','cancelled')",$event_id));
        if ($capacity && $reg_count >= $capacity) {
            return '<div class="msc-notice msc-notice-warning">Sorry, this event is fully booked.</div>';
        }

        ob_start();
        ?>
        <div id="msc-reg-wrap" class="msc-registration-wrap"
             data-event="<?php echo $event_id; ?>"
             data-minor="<?php echo $is_minor ? '1' : '0'; ?>"
             data-base-fee="<?php echo esc_attr( number_format( $fee, 2, '.', '' ) ); ?>"
             data-classes="<?php echo esc_attr( wp_json_encode( $event_classes_for_form ) ); ?>"
             data-garage-url="<?php echo esc_attr( msc_get_account_url( 'garage' ) ); ?>">
            <h3 class="msc-section-title">Register for this Event</h3>

            <?php if($approval==='manual'): ?>
            <div class="msc-notice msc-notice-info" style="margin-bottom:16px">ℹ️ Registrations for this event require admin approval. You will be notified by email once confirmed.</div>
            <?php endif ?>

            <!-- Step 1: Class + Vehicle Selection -->
            <div class="msc-step" id="msc-step-1">
                <div class="msc-step-header"><span class="msc-step-num">1</span> Select Class &amp; Vehicle</div>
                <div class="msc-step-body">
                    <div id="msc-vehicles-loading">Loading your vehicles…</div>

                    <!-- No vehicles at all in garage -->
                    <div id="msc-vehicles-none-at-all" style="display:none">
                        <p>You have no vehicles in your garage yet.</p>
                        <a href="<?php echo esc_url( msc_get_account_url( 'garage' ) ); ?>" class="msc-btn msc-btn-outline">Add a Vehicle to Your Garage</a>
                    </div>

                    <!-- Vehicles exist but none eligible for this event type -->
                    <div id="msc-vehicles-empty" style="display:none">
                        <p>None of your vehicles are eligible for this event's vehicle type.</p>
                        <a href="<?php echo esc_url( msc_get_account_url( 'garage' ) ); ?>" class="msc-btn msc-btn-outline">Manage Garage</a>
                    </div>

                    <!-- Main class+vehicle selection — shown after vehicles load -->
                    <div id="msc-class-vehicle-wrap" style="display:none">
                        <?php if ( empty( $event_classes_for_form ) ) : ?>
                        <p style="color:#888;font-style:italic;">No classes have been configured for this event.</p>
                        <?php else : ?>

                        <!-- Primary class selection -->
                        <div class="msc-field" style="margin-bottom:16px">
                            <label style="font-weight:600">Primary Class <span class="msc-required">*</span></label>
                            <select id="msc-primary-class-select">
                                <option value="">— Select a class —</option>
                            </select>
                        </div>

                        <!-- Primary vehicle selection (shown after primary class is chosen) -->
                        <div id="msc-primary-vehicle-wrap" style="display:none;margin-bottom:16px">
                            <div class="msc-field">
                                <label style="font-weight:600">Vehicle for Primary Class <span class="msc-required">*</span></label>
                                <select id="msc-primary-vehicle-select">
                                    <option value="">— Select a vehicle —</option>
                                </select>
                            </div>
                        </div>

                        <!-- Additional classes (shown after primary class + vehicle are chosen) -->
                        <div id="msc-additional-classes-wrap" style="display:none">
                            <p style="font-weight:600;margin-bottom:8px">Additional Classes <small style="font-weight:400;color:#666">(optional)</small></p>
                            <div id="msc-additional-rows"></div>
                            <button type="button" id="msc-add-class-btn" class="msc-btn msc-btn-sm msc-btn-outline" style="margin-top:8px">+ Add Another Class</button>
                        </div>

                        <!-- Live fee breakdown -->
                        <div id="msc-fee-breakdown" style="display:none;margin-top:16px">
                            <table style="width:100%;border-collapse:collapse">
                                <tbody id="msc-fee-breakdown-rows"></tbody>
                                <tfoot>
                                    <tr style="border-top:2px solid #ccc">
                                        <td style="padding:6px 4px;font-weight:700">Total</td>
                                        <td style="padding:6px 4px;text-align:right;font-weight:700" id="msc-fee-total">R 0.00</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>

                        <?php endif; ?>
                    </div>

                    <div style="margin-top:16px">
                        <label style="font-weight:600;display:block;margin-bottom:4px">Additional Notes (optional)</label>
                        <textarea id="msc-notes" rows="2" placeholder="Any notes for the organiser..." style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px;box-sizing:border-box"></textarea>
                    </div>
                    <button id="msc-step1-next" class="msc-btn" style="margin-top:12px" disabled>Next: Review &amp; Indemnity →</button>
                </div>
            </div>

            <!-- Step 2: Summary + Indemnity -->
            <div class="msc-step" id="msc-step-2" style="display:none">
                <div class="msc-step-header"><span class="msc-step-num">2</span> Review & Indemnity</div>
                <div class="msc-step-body">
                    
                    <div class="msc-indemnity-section" style="margin-top:0; margin-bottom:24px;">
                        <h4 style="margin-bottom:10px;">Indemnity Declaration</h4>
                        <div class="msc-indemnity-text" style="margin-bottom:12px;"><?php echo nl2br(esc_html($indemnity)) ?></div>
                    </div>

                    <div style="margin-bottom:20px;">
                        <p class="msc-reg-section-label">Emergency Contact Information</p>
                        <div class="msc-reg-grid-3">
                            <div class="msc-field">
                                <label>Name <span class="msc-required">*</span></label>
                                <input type="text" id="msc-emergency-name" value="<?php echo esc_attr(get_user_meta($user_id, 'msc_emergency_name', true)); ?>" placeholder="Contact person name">
                            </div>
                            <div class="msc-field">
                                <label>Number <span class="msc-required">*</span></label>
                                <input type="text" id="msc-emergency-phone" value="<?php echo esc_attr(get_user_meta($user_id, 'msc_emergency_phone', true)); ?>" placeholder="Contact phone number">
                            </div>
                            <div class="msc-field">
                                <label>Relationship</label>
                                <input type="text" id="msc-emergency-rel" value="<?php echo esc_attr(get_user_meta($user_id, 'msc_emergency_rel', true)); ?>" placeholder="Spouse, Parent, etc.">
                            </div>
                        </div>
                    </div>

                    <div style="margin-bottom:20px;">
                        <p class="msc-reg-section-label">Pit Crew</p>
                        <div class="msc-reg-grid-2">
                            <div class="msc-field">
                                <label>Name #1</label>
                                <input type="text" id="msc-pit-crew-1" value="<?php echo esc_attr(get_user_meta($user_id, 'msc_pit_crew_1', true)); ?>" placeholder="Pit crew member name">
                            </div>
                            <div class="msc-field">
                                <label>Name #2</label>
                                <input type="text" id="msc-pit-crew-2" value="<?php echo esc_attr(get_user_meta($user_id, 'msc_pit_crew_2', true)); ?>" placeholder="Pit crew member name">
                            </div>
                        </div>
                    </div>

                    <?php if ($is_minor) : ?>
                    <div style="margin-bottom:20px;">
                        <div class="msc-field-group">
                            <label>Parent/Guardian Full Name <span style="color:red">*</span></label>
                            <input type="text" id="msc-parent-name" placeholder="Parent or legal guardian name">
                        </div>
                    </div>
                    <?php endif; ?>

                    <div id="msc-summary" class="msc-summary-box"></div>

                    <!-- Payment section — shown/hidden by JS based on total fee -->
                    <div id="msc-payment-section" class="msc-payment-section" style="display:none;margin:20px 0;padding:15px;background:#f9f9f9;border:1px solid #ddd;border-radius:4px;">
                        <h4 style="margin-top:0">💰 Payment Information</h4>

                        <!-- Fee breakdown injected by JS -->
                        <div id="msc-payment-breakdown" style="margin-bottom:14px;padding:10px;background:#fff;border:1px solid #e0e0e0;border-radius:4px;">
                            <table style="width:100%;border-collapse:collapse;font-size:14px;">
                                <tbody id="msc-payment-breakdown-rows"></tbody>
                                <tr style="border-top:2px solid #ccc;">
                                    <td style="padding:6px 4px;font-weight:700;">Total due</td>
                                    <td style="padding:6px 4px;font-weight:700;text-align:right;" id="msc-payment-total">R 0.00</td>
                                </tr>
                            </table>
                        </div>

                        <?php
                        $banking = get_option( 'msc_banking_details', '' );
                        if ( $banking ) : ?>
                        <div class="msc-banking-details" style="margin-bottom:15px;padding:10px;background:#fff;border-left:4px solid #2271b1;">
                            <strong>Banking Details for EFT:</strong><br>
                            <?php echo nl2br( wp_kses_post( $banking ) ); ?>
                        </div>
                        <?php endif; ?>

                        <div class="msc-field-group">
                            <label style="font-weight:700">Upload Proof of Payment (PDF only) <span style="color:red">*</span></label>
                            <input type="file" id="msc-pop-file" accept="application/pdf" style="width:100%;padding:10px;background:#fff;border:1px solid #ccc;border-radius:4px;">
                            <p class="description" style="font-size:0.85em;margin-top:4px;">Please upload your EFT confirmation PDF to complete your registration.</p>
                        </div>
                    </div>

                    <?php 
                    $custom_decs = get_option('msc_custom_declarations', '');
                    if ( $custom_decs ) :
                        $lines = array_filter( array_map( 'trim', explode( "\n", $custom_decs ) ) );
                        foreach ( $lines as $i => $line ) :
                    ?>
                    <div class="msc-declaration-section" style="margin:10px 0; padding:12px; background:#fffbf0; border:1px solid #ffeeba; border-radius:4px;">
                        <label style="display:flex; align-items:flex-start; cursor:pointer; font-weight:600; line-height:1.4;">
                            <input type="checkbox" name="msc_custom_declaration" class="msc-custom-declaration" style="margin-top:4px; margin-right:10px;">
                            <span>
                                <?php echo wp_kses_post( $line ); ?>
                                <span style="color:red">*</span>
                            </span>
                        </label>
                    </div>
                    <?php endforeach; endif; ?>

                    <div class="msc-signature-controls-wrap">
                        <!-- E-signature panel -->
                        <div id="msc-sig-panel" style="margin-top:16px">
                            <p style="margin-bottom:8px;font-weight:600">Participant Signature:</p>
                            <div style="margin-bottom:12px">
                                <label><input type="radio" name="msc_sig_type" value="draw" checked> ✏️ Draw signature</label>
                                &nbsp;&nbsp;
                                <label><input type="radio" name="msc_sig_type" value="type"> ⌨️ Type signature</label>
                            </div>
                            <div id="msc-sig-draw-wrap">
                                <canvas id="msc-sig-canvas" class="msc-sig-canvas"></canvas>
                                <div style="margin-top:6px"><button type="button" id="msc-sig-clear" class="msc-btn msc-btn-sm msc-btn-outline">Clear</button></div>
                            </div>
                            <div id="msc-sig-type-wrap" style="display:none">
                                <input type="text" id="msc-sig-typed" placeholder="Type your full name as signature" style="width:100%;padding:10px;border:1px solid #ccc;border-radius:4px;font-family:cursive;font-size:18px;box-sizing:border-box">
                            </div>
                        </div>

                        <!-- Parent/Guardian Signature Panel (Minor Only) -->
                        <?php if ($is_minor) : ?>
                        <div id="msc-parent-sig-panel" style="margin-top:24px;padding-top:24px;border-top:1px solid #eee">
                            <p style="margin-bottom:8px;font-weight:600">Parent/Guardian Signature:</p>
                            <div style="margin-bottom:12px">
                                <label><input type="radio" name="msc_parent_sig_type" value="draw" checked> ✏️ Draw signature</label>
                                &nbsp;&nbsp;
                                <label><input type="radio" name="msc_parent_sig_type" value="type"> ⌨️ Type signature</label>
                            </div>
                            <div id="msc-parent-sig-draw-wrap">
                                <canvas id="msc-parent-sig-canvas" class="msc-sig-canvas"></canvas>
                                <div style="margin-top:6px"><button type="button" id="msc-parent-sig-clear" class="msc-btn msc-btn-sm msc-btn-outline">Clear</button></div>
                            </div>
                            <div id="msc-parent-sig-type-wrap" style="display:none">
                                <input type="text" id="msc-parent-sig-typed" placeholder="Parent/Guardian: Type your full name as signature" style="width:100%;padding:10px;border:1px solid #ccc;border-radius:4px;font-family:cursive;font-size:18px;box-sizing:border-box">
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div id="msc-reg-error" class="msc-notice msc-notice-error" style="display:none;margin-top:12px"></div>

                    <div style="margin-top:20px;display:flex;gap:12px">
                        <button id="msc-step2-back" class="msc-btn msc-btn-outline">← Back</button>
                        <button id="msc-submit-reg" class="msc-btn" disabled>Submit Registration</button>
                    </div>
                </div>
            </div>

            <!-- Success -->
            <div id="msc-reg-success" style="display:none" class="msc-notice msc-notice-success msc-success-big"></div>
        </div>
        <?php
        return ob_get_clean();
    }
}
