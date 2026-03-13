<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Handles front-end "My Account" dashboard for racers.
 * Shortcode: [msc_my_account]
 */
class MSC_Account {

    public static function init() {
        add_shortcode( 'msc_my_account', array( __CLASS__, 'render' ) );
        add_action( 'wp_ajax_msc_add_vehicle',     array( __CLASS__, 'ajax_add_vehicle' ) );
        add_action( 'wp_ajax_msc_update_vehicle',  array( __CLASS__, 'ajax_update_vehicle' ) );
        add_action( 'wp_ajax_msc_delete_vehicle',  array( __CLASS__, 'ajax_delete_vehicle' ) );
        add_action( 'wp_ajax_msc_update_profile',       array( __CLASS__, 'ajax_update_profile' ) );
        add_action( 'wp_ajax_msc_upload_profile_photo',  array( __CLASS__, 'ajax_upload_profile_photo' ) );
        add_action( 'wp_ajax_msc_remove_profile_photo',  array( __CLASS__, 'ajax_remove_profile_photo' ) );
    }

    public static function render() {
        if ( ! is_user_logged_in() ) {
            return '
            <div class="msc-login-prompt">
                <div class="msc-login-prompt-inner">
                    <div class="msc-login-icon">🏁</div>
                    <h3>Members Area</h3>
                    <p>Please log in to access your account, manage your vehicles and view your event entries.</p>
                    <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap;">
                        <a href="' . esc_url( add_query_arg( 'redirect_to', rawurlencode( get_permalink() ), MSC_Auth::login_url() ) ) . '" class="msc-btn">Log In to Your Account</a>
                        <a href="' . esc_url( MSC_Auth::register_url() ) . '" class="msc-btn">Create an Account</a>
                    </div>
                </div>
            </div>';
        }

        ob_start();
        $user              = wp_get_current_user();
        $profile_photo_id  = get_user_meta( $user->ID, 'msc_profile_photo', true );
        $profile_photo_url = $profile_photo_id ? wp_get_attachment_image_url( $profile_photo_id, 'thumbnail' ) : '';
        $tab      = isset( $_GET['msc_tab'] ) ? sanitize_key( $_GET['msc_tab'] ) : 'garage';
        $regs     = MSC_Registration::get_user_registrations( $user->ID );
        $vehicles = get_posts( array(
            'post_type'   => 'msc_vehicle',
            'author'      => $user->ID,
            'numberposts' => -1,
            'post_status' => 'publish',
        ) );
        $classes  = MSC_Taxonomies::get_all_classes();
        ?>
        <div id="msc-account">

            <!-- Profile Header -->
            <div class="msc-profile-header">
                <div class="msc-profile-avatar">
                    <div class="msc-avatar-upload-wrap">
                        <label for="msc-profile-photo-input" class="msc-avatar-upload-label" title="Change photo">
                            <?php if ( $profile_photo_url ) : ?>
                                <img src="<?php echo esc_url( $profile_photo_url ); ?>" alt="<?php echo esc_attr( $user->display_name ); ?>" class="msc-avatar-img" id="msc-header-avatar">
                            <?php else : ?>
                                <?php echo get_avatar( $user->ID, 72, '', '', array( 'class' => 'msc-avatar-img', 'extra_attr' => 'id="msc-header-avatar"' ) ); ?>
                            <?php endif; ?>
                            <span class="msc-avatar-overlay">📷</span>
                        </label>
                        <input type="file" id="msc-profile-photo-input" accept="image/jpeg,image/png,image/webp" style="display:none">
                    </div>
                    <?php if ( $profile_photo_id ) : ?>
                    <button type="button" id="msc-remove-profile-photo" class="msc-avatar-remove">Remove photo</button>
                    <?php endif; ?>
                    <div id="msc-profile-photo-msg"></div>
                </div>
                <div class="msc-profile-info">
                    <h2 class="msc-profile-name"><?php echo esc_html( $user->display_name ); ?></h2>
                    <p class="msc-profile-meta">
                        <?php
                        $role_map = array(
                            'administrator'     => 'Administrator',
                            'editor'            => 'Editor',
                            'msc_event_creator' => 'Event Creator',
                            'subscriber'        => 'Guest',
                        );
                        $user_role = ! empty( $user->roles ) ? $user->roles[0] : 'subscriber';
                        $role_label = isset( $role_map[ $user_role ] )
                            ? $role_map[ $user_role ]
                            : ucwords( str_replace( '_', ' ', $user_role ) );
                        ?>
                        <span class="msc-profile-badge">🏎 <?php echo esc_html( $role_label ); ?></span>
                        <span class="msc-profile-stat"><?php echo count( $vehicles ); ?> Vehicle<?php echo count( $vehicles ) !== 1 ? 's' : ''; ?></span>
                        <span class="msc-profile-stat"><?php echo count( $regs ); ?> <?php echo count( $regs ) !== 1 ? 'Entries' : 'Entry'; ?></span>
                    </p>
                </div>
            </div>

            <!-- Tabs -->
            <div class="msc-tab-nav">
                <?php
                $tabs = array(
                    'garage'        => '🚗 My Garage',
                    'registrations' => '🏁 My Entries',
                    'profile'       => '👤 My Profile',
                );
                foreach ( $tabs as $t => $label ) :
                    $tab_url = add_query_arg( 'msc_tab', $t, get_permalink() );
                ?>
                <a href="<?php echo esc_url( $tab_url ); ?>"
                   class="msc-tab-link <?php echo $tab === $t ? 'active' : ''; ?>">
                    <?php echo $label; ?>
                    <?php if ( $t === 'garage' && count( $vehicles ) ) : ?>
                        <span class="msc-tab-count"><?php echo count( $vehicles ); ?></span>
                    <?php elseif ( $t === 'registrations' && count( $regs ) ) : ?>
                        <span class="msc-tab-count"><?php echo count( $regs ); ?></span>
                    <?php endif; ?>
                </a>
                <?php endforeach; ?>
            </div>

            <!-- ── ONBOARDING BANNER ── -->
            <?php if ( isset( $_GET['msc_onboarding'] ) && $_GET['msc_onboarding'] === '1' ) : ?>
            <div class="msc-onboarding-banner">
                <div class="msc-onboarding-icon">👋</div>
                <div class="msc-onboarding-body">
                    <h3>Welcome to <?php echo esc_html( get_bloginfo( 'name' ) ); ?>!</h3>
                    <p>To register for events you need to do two things first:</p>
                    <div class="msc-onboarding-steps">
                        <div class="msc-onboarding-step">
                            <span class="msc-onboarding-num">1</span>
                            <div>
                                <strong>Complete your profile</strong>
                                <p>Add your date of birth, competition number, MSA licence, medical aid details and gender.</p>
                                <a href="<?php echo esc_url( msc_get_account_url( 'profile' ) ); ?>" class="msc-btn msc-btn-sm">Go to My Profile →</a>
                            </div>
                        </div>
                        <div class="msc-onboarding-step">
                            <span class="msc-onboarding-num">2</span>
                            <div>
                                <strong>Add your vehicle</strong>
                                <p>Add your motorcycle or car to your garage so it can be selected when registering.</p>
                                <a href="<?php echo esc_url( msc_get_account_url( 'garage' ) ); ?>" class="msc-btn msc-btn-sm msc-btn-outline">Go to My Garage →</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- ── GARAGE TAB ── -->
            <?php if ( $tab === 'garage' ) : ?>
            <div class="msc-tab-content">

                <div class="msc-tab-header">
                    <h3 class="msc-tab-title">My Garage</h3>
                    <button id="msc-add-vehicle-btn" class="msc-btn">+ Add Vehicle</button>
                </div>

                <!-- Add Vehicle Form -->
                <div id="msc-add-vehicle-form" class="msc-add-vehicle-panel" style="display:none">
                    <div class="msc-panel-header">
                        <h4>🔧 Add New Vehicle</h4>
                        <button type="button" id="msc-cancel-vehicle" class="msc-panel-close">✕</button>
                    </div>

                    <!-- Photo Upload -->
                    <div class="msc-photo-upload-area" id="msc-photo-drop">
                        <div class="msc-photo-placeholder" id="msc-photo-placeholder">
                            <div class="msc-photo-icon">📷</div>
                            <p>Drag &amp; drop a photo, or <label for="v_photo" class="msc-photo-label">browse</label></p>
                            <p class="msc-photo-hint">JPG or PNG · max 5MB</p>
                        </div>
                        <div class="msc-photo-preview" id="msc-photo-preview" style="display:none">
                            <img id="msc-photo-img" src="" alt="Vehicle preview">
                            <button type="button" id="msc-photo-remove" class="msc-photo-remove-btn">✕ Remove photo</button>
                        </div>
                        <input type="file" id="v_photo" name="v_photo" accept="image/jpeg,image/png,image/webp" style="display:none">
                    </div>

                    <div class="msc-form-grid">
                        <div class="msc-field msc-field-full">
                            <label for="v_title">Nickname / Title <span class="msc-required">*</span></label>
                            <input type="text" id="v_title" placeholder="e.g. My Track Day GR86">
                        </div>
                        <div class="msc-field">
                            <label for="v_type">Vehicle Type <span class="msc-required">*</span></label>
                            <select id="v_type">
                                <option value="">Select type…</option>
                                <?php foreach ( MSC_Taxonomies::get_vehicle_types() as $vt ) : ?>
                                <option value="<?php echo esc_attr( $vt ); ?>"><?php echo esc_html( $vt ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="msc-field">
                            <label for="v_engine_size">Engine Size</label>
                            <input type="text" id="v_engine_size" placeholder="e.g. 1600cc, 2.0L Turbo">
                        </div>
                        <div class="msc-field">
                            <label for="v_make">Make</label>
                            <input type="text" id="v_make" placeholder="Toyota">
                        </div>
                        <div class="msc-field">
                            <label for="v_model">Model</label>
                            <input type="text" id="v_model" placeholder="GR86">
                        </div>
                        <div class="msc-field">
                            <label for="v_year">Year</label>
                            <input type="number" id="v_year" placeholder="2023" min="1900" max="2099">
                        </div>
                        <div class="msc-field">
                            <label for="v_color">Colour</label>
                            <input type="text" id="v_color" placeholder="Red">
                        </div>
                        <div class="msc-field">
                            <label for="v_reg">Reg / Race Number</label>
                            <input type="text" id="v_reg" placeholder="e.g. CA 123-456 or #42">
                        </div>
                        <div class="msc-field msc-field-full">
                            <label for="v_notes">Notes / Modifications</label>
                            <textarea id="v_notes" rows="2" placeholder="Any modifications, special notes…"></textarea>
                        </div>
                    </div>

                    <div class="msc-panel-footer">
                        <button id="msc-save-vehicle" class="msc-btn">Save Vehicle</button>
                        <button type="button" id="msc-cancel-vehicle-2" class="msc-btn msc-btn-outline">Cancel</button>
                        <span id="msc-vehicle-msg" class="msc-field-msg"></span>
                    </div>
                </div>

                <!-- Vehicle Grid -->
                <?php if ( empty( $vehicles ) ) : ?>
                <div class="msc-empty-state">
                    <div class="msc-empty-icon">🏎</div>
                    <h4>Your garage is empty</h4>
                    <p>Add your first vehicle to start registering for events.</p>
                </div>
                <?php else : ?>
                <div class="msc-garage-grid">
                    <?php foreach ( $vehicles as $v ) :
                        $make  = get_post_meta( $v->ID, '_msc_make',       true );
                        $model = get_post_meta( $v->ID, '_msc_model',      true );
                        $year  = get_post_meta( $v->ID, '_msc_year',       true );
                        $type  = get_post_meta( $v->ID, '_msc_type',       true );
                        $color = get_post_meta( $v->ID, '_msc_color',      true );
                        $reg   = get_post_meta( $v->ID, '_msc_reg_number', true );
                        $notes = get_post_meta( $v->ID, '_msc_notes',      true );
                        $engine_size = get_post_meta( $v->ID, '_msc_engine_size', true );
                        $thumb_id  = get_post_thumbnail_id( $v->ID );
                        $thumb_url = $thumb_id ? wp_get_attachment_image_url( $thumb_id, 'medium' ) : '';
                        $icons     = array( 'Car' => '🚗', 'Motorcycle' => '🏍' );
                        $icon      = isset( $icons[ $type ] ) ? $icons[ $type ] : '🚙';
                    ?>
                    <div class="msc-garage-card">
                        <div class="msc-garage-card-photo">
                            <?php if ( $thumb_url ) : ?>
                                <img src="<?php echo esc_url( $thumb_url ); ?>" alt="<?php echo esc_attr( $v->post_title ); ?>">
                            <?php else : ?>
                                <div class="msc-garage-card-icon"><?php echo $icon; ?></div>
                            <?php endif; ?>
                            <?php if ( $engine_size ) : ?>
                            <span class="msc-garage-class-badge"><?php echo esc_html( $engine_size ); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="msc-garage-card-body">
                            <h4 class="msc-garage-card-title"><?php echo esc_html( $v->post_title ); ?></h4>
                            <?php $spec = trim( "$year $make $model" ); ?>
                            <?php if ( $spec ) : ?><p class="msc-garage-card-spec"><?php echo esc_html( $spec ); ?></p><?php endif; ?>
                            <div class="msc-garage-card-pills">
                                <?php if ( $type  ) : ?><span class="msc-pill">🚦 <?php echo esc_html( $type ); ?></span><?php endif; ?>
                                <?php if ( $color ) : ?><span class="msc-pill">🎨 <?php echo esc_html( $color ); ?></span><?php endif; ?>
                                <?php if ( $reg   ) : ?><span class="msc-pill">🔖 <?php echo esc_html( $reg ); ?></span><?php endif; ?>
                            </div>
                            <?php if ( $notes ) : ?>
                            <p class="msc-garage-card-notes"><?php echo esc_html( $notes ); ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="msc-garage-card-footer">
                            <a href="#" class="msc-edit-vehicle msc-edit-link" data-id="<?php echo $v->ID; ?>">✏️ Edit</a>
                            <a href="#" class="msc-delete-vehicle msc-danger-link" data-id="<?php echo $v->ID; ?>">🗑 Remove</a>
                        </div>

                        <!-- Inline edit form (hidden by default) -->
                        <div class="msc-inline-edit-form" id="msc-edit-<?php echo $v->ID; ?>" style="display:none">
                            <div class="msc-panel-header" style="border-radius:0">
                                <h4>✏️ Edit Vehicle</h4>
                                <button type="button" class="msc-panel-close msc-edit-cancel" data-id="<?php echo $v->ID; ?>">✕</button>
                            </div>

                            <!-- Photo upload -->
                            <div style="padding:16px 20px 0">
                                <div class="msc-photo-upload-area msc-edit-photo-drop" id="msc-edit-photo-drop-<?php echo $v->ID; ?>">
                                    <div class="msc-photo-placeholder" id="msc-edit-photo-placeholder-<?php echo $v->ID; ?>">
                                        <?php if ( $thumb_url ) : ?>
                                            <img src="<?php echo esc_url( $thumb_url ); ?>" style="width:100%;max-height:180px;object-fit:cover;display:block;border-radius:4px">
                                            <p style="margin:8px 0 0;font-size:12px;color:#888">Click <label for="edit_photo_<?php echo $v->ID; ?>" class="msc-photo-label">browse</label> to replace photo</p>
                                        <?php else : ?>
                                            <div class="msc-photo-icon">📷</div>
                                            <p>Drag &amp; drop or <label for="edit_photo_<?php echo $v->ID; ?>" class="msc-photo-label">browse</label> to add a photo</p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="msc-photo-preview" id="msc-edit-photo-preview-<?php echo $v->ID; ?>" style="display:none">
                                        <img id="msc-edit-photo-img-<?php echo $v->ID; ?>" src="" alt="Preview">
                                        <button type="button" class="msc-photo-remove-btn msc-edit-photo-remove" data-id="<?php echo $v->ID; ?>">✕ Remove</button>
                                    </div>
                                    <input type="file" id="edit_photo_<?php echo $v->ID; ?>" class="msc-edit-photo-input" data-id="<?php echo $v->ID; ?>" accept="image/jpeg,image/png,image/webp" style="display:none">
                                </div>
                            </div>

                            <div class="msc-form-grid" style="padding-top:12px">
                                <div class="msc-field msc-field-full">
                                    <label>Nickname / Title <span class="msc-required">*</span></label>
                                    <input type="text" class="edit-v_title" data-id="<?php echo $v->ID; ?>" value="<?php echo esc_attr( $v->post_title ); ?>">
                                </div>
                                <div class="msc-field">
                                    <label>Vehicle Type</label>
                                    <select class="edit-v_type" data-id="<?php echo $v->ID; ?>">
                                        <option value="">Select type…</option>
                                        <?php foreach ( MSC_Taxonomies::get_vehicle_types() as $vt ) : ?>
                                        <option value="<?php echo esc_attr( $vt ); ?>" <?php selected( $type, $vt ); ?>><?php echo esc_html( $vt ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="msc-field">
                                    <label>Engine Size</label>
                                    <input type="text" class="edit-v_engine_size" data-id="<?php echo $v->ID; ?>"
                                           value="<?php echo esc_attr( get_post_meta( $v->ID, '_msc_engine_size', true ) ); ?>"
                                           placeholder="e.g. 1600cc, 2.0L Turbo">
                                </div>
                                <div class="msc-field">
                                    <label>Make</label>
                                    <input type="text" class="edit-v_make" data-id="<?php echo $v->ID; ?>" value="<?php echo esc_attr( $make ); ?>" placeholder="Toyota">
                                </div>
                                <div class="msc-field">
                                    <label>Model</label>
                                    <input type="text" class="edit-v_model" data-id="<?php echo $v->ID; ?>" value="<?php echo esc_attr( $model ); ?>" placeholder="GR86">
                                </div>
                                <div class="msc-field">
                                    <label>Year</label>
                                    <input type="number" class="edit-v_year" data-id="<?php echo $v->ID; ?>" value="<?php echo esc_attr( $year ); ?>" placeholder="2023" min="1900" max="2099">
                                </div>
                                <div class="msc-field">
                                    <label>Colour</label>
                                    <input type="text" class="edit-v_color" data-id="<?php echo $v->ID; ?>" value="<?php echo esc_attr( $color ); ?>" placeholder="Red">
                                </div>
                                <div class="msc-field">
                                    <label>Reg / Race Number</label>
                                    <input type="text" class="edit-v_reg" data-id="<?php echo $v->ID; ?>" value="<?php echo esc_attr( $reg ); ?>">
                                </div>
                                <div class="msc-field msc-field-full">
                                    <label>Notes / Modifications</label>
                                    <textarea class="edit-v_notes" data-id="<?php echo $v->ID; ?>" rows="2" placeholder="Any modifications, special notes…"><?php echo esc_textarea( $notes ); ?></textarea>
                                </div>
                            </div>
                            <div class="msc-panel-footer">
                                <button type="button" class="msc-btn msc-save-vehicle-edit" data-id="<?php echo $v->ID; ?>">Save Changes</button>
                                <button type="button" class="msc-btn msc-btn-outline msc-edit-cancel" data-id="<?php echo $v->ID; ?>">Cancel</button>
                                <span class="msc-edit-msg msc-field-msg" id="msc-edit-msg-<?php echo $v->ID; ?>"></span>
                            </div>
                        </div>

                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- ── REGISTRATIONS TAB ── -->
            <?php elseif ( $tab === 'registrations' ) : ?>
            <div class="msc-tab-content">
                <div class="msc-tab-header">
                    <h3 class="msc-tab-title">My Entries</h3>
                </div>

                <?php if ( empty( $regs ) ) : ?>
                <div class="msc-empty-state">
                    <div class="msc-empty-icon">🏁</div>
                    <h4>No entries yet</h4>
                    <p>You haven't entered any events yet.</p>
                    <a href="<?php echo esc_url( get_post_type_archive_link( 'msc_event' ) ); ?>" class="msc-btn">Browse Events →</a>
                </div>
                <?php else : ?>
                <div class="msc-regs-list">
                    <?php foreach ( $regs as $r ) :
                        $sm = array(
                            'pending'   => array( 'label' => 'Pending',   'cls' => 'status-pending' ),
                            'confirmed' => array( 'label' => 'Confirmed', 'cls' => 'status-confirmed' ),
                            'rejected'  => array( 'label' => 'Rejected',  'cls' => 'status-rejected' ),
                            'cancelled' => array( 'label' => 'Cancelled', 'cls' => 'status-cancelled' ),
                        );
                        $s           = isset( $sm[ $r->status ] ) ? $sm[ $r->status ] : array( 'label' => ucfirst( $r->status ), 'cls' => '' );
                        $class_names = MSC_Registration::get_class_names_for_registration( $r->id );
                    ?>
                    <div class="msc-reg-card">
                        <div class="msc-reg-card-icon">🏁</div>
                        <div class="msc-reg-card-body">
                            <div class="msc-reg-card-top">
                                <h4><a href="<?php echo esc_url( get_permalink( $r->event_id ) ); ?>"><?php echo esc_html( $r->event_name ); ?></a></h4>
                                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                                    <?php if ( ! empty( $r->entry_number ) ) : ?>
                                    <span style="font-size:13px;font-weight:700;color:#0a3622;background:#d1e7dd;padding:2px 10px;border-radius:12px">#<?php echo (int) $r->entry_number; ?></span>
                                    <?php endif; ?>
                                    <span class="msc-status-badge <?php echo $s['cls']; ?>"><?php echo $s['label']; ?></span>
                                </div>
                            </div>
                            <div class="msc-reg-meta">
                                <span>🚗 <?php echo esc_html( $r->vehicle_name ); ?></span>
                                <span>💰 <?php echo $r->entry_fee > 0 ? 'R ' . number_format( $r->entry_fee, 2 ) : 'Free'; ?></span>
                                <?php if ( ! empty( $class_names ) ) : ?>
                                <span>🏷️ <?php echo esc_html( implode( ', ', $class_names ) ); ?></span>
                                <?php endif; ?>
                                <span><?php echo $r->indemnity_method === 'signed' ? '✅ Indemnity signed' : '📄 Bring on day'; ?></span>
                            </div>
                        </div>
                        <div class="msc-reg-card-actions">
                            <a href="<?php echo esc_url( add_query_arg( 'msc_indemnity_pdf', $r->id, home_url() ) ); ?>" target="_blank" class="msc-btn msc-btn-sm msc-btn-outline">📄 PDF</a>
                            <?php if ( in_array( $r->status, array( 'pending', 'confirmed' ) ) ) : ?>
                            <button type="button" class="msc-btn msc-btn-sm msc-btn-outline msc-edit-entry" data-id="<?php echo $r->id; ?>">Edit Entry</button>
                            <a href="#" class="msc-cancel-reg msc-danger-link" data-id="<?php echo $r->id; ?>">Cancel</a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- ── PROFILE TAB ── -->
            <?php if ( $tab === 'profile' ) : ?>
            <div class="msc-tab-content">
                <div class="msc-tab-header">
                    <h3 class="msc-tab-title">My Profile</h3>
                </div>

                <div class="msc-profile-edit-form">
                    <div id="msc-profile-msg" class="msc-field-msg" style="margin-bottom:12px"></div>

                    <div class="msc-form-section-title">Personal Details</div>
                    <div class="msc-form-grid">
                        <div class="msc-field">
                            <label>First Name <span class="msc-required">*</span></label>
                            <input type="text" id="pe_first_name" value="<?php echo esc_attr($user->first_name); ?>">
                        </div>
                        <div class="msc-field">
                            <label>Last Name <span class="msc-required">*</span></label>
                            <input type="text" id="pe_last_name" value="<?php echo esc_attr($user->last_name); ?>">
                        </div>
                        <div class="msc-field">
                            <label>Display Name</label>
                            <input type="text" id="pe_display_name" value="<?php echo esc_attr($user->display_name); ?>">
                        </div>
                        <div class="msc-field">
                            <label>Email Address</label>
                            <input type="email" id="pe_email" value="<?php echo esc_attr($user->user_email); ?>">
                        </div>
                        <div class="msc-field">
                            <label>Date of Birth <span class="msc-required">*</span></label>
                            <input type="date" id="pe_birthday" value="<?php echo esc_attr(get_user_meta($user->ID, 'msc_birthday', true)); ?>">
                        </div>
                        <div class="msc-field">
                            <label>Phone Number <span class="msc-required">*</span></label>
                            <input type="tel" id="pe_phone" value="<?php echo esc_attr(get_user_meta($user->ID, 'phone', true)); ?>" placeholder="+27 82 000 0000">
                        </div>
                        <div class="msc-field">
                            <label>Gender <span class="msc-required">*</span></label>
                            <select id="pe_gender">
                                <option value="">— Select —</option>
                                <option value="male"   <?php selected(get_user_meta($user->ID, 'msc_gender', true), 'male'); ?>>Male</option>
                                <option value="female" <?php selected(get_user_meta($user->ID, 'msc_gender', true), 'female'); ?>>Female</option>
                            </select>
                        </div>
                    </div>

                    <div class="msc-form-section-title" style="margin-top:20px">Motorsport Details</div>
                    <div class="msc-form-grid">
                        <div class="msc-field">
                            <label>Competition Number <span class="msc-required">*</span></label>
                            <input type="text" id="pe_comp_number" value="<?php echo esc_attr(get_user_meta($user->ID, 'msc_comp_number', true)); ?>" placeholder="Motorcycle / Car competition number">
                        </div>
                        <div class="msc-field">
                            <label>MSA License Number <span class="msc-required">*</span></label>
                            <input type="text" id="pe_msa_licence" value="<?php echo esc_attr(get_user_meta($user->ID, 'msc_msa_licence', true)); ?>" placeholder="MSA Licence No.">
                        </div>
                        <div class="msc-field">
                            <label>Medical Aid Provider <span class="msc-required">*</span></label>
                            <input type="text" id="pe_medical_aid" value="<?php echo esc_attr(get_user_meta($user->ID, 'msc_medical_aid', true)); ?>" placeholder="e.g. Discovery, Momentum">
                        </div>
                        <div class="msc-field">
                            <label>Medical Aid Number <span class="msc-required">*</span></label>
                            <input type="text" id="pe_medical_aid_number" value="<?php echo esc_attr(get_user_meta($user->ID, 'msc_medical_aid_number', true)); ?>" placeholder="Member number">
                        </div>
                        <div class="msc-field">
                            <label>Pit Crew Name #1</label>
                            <input type="text" id="pe_pit_crew_1" value="<?php echo esc_attr(get_user_meta($user->ID, 'msc_pit_crew_1', true)); ?>" placeholder="Pit crew member name">
                        </div>
                        <div class="msc-field">
                            <label>Pit Crew Name #2</label>
                            <input type="text" id="pe_pit_crew_2" value="<?php echo esc_attr(get_user_meta($user->ID, 'msc_pit_crew_2', true)); ?>" placeholder="Pit crew member name">
                        </div>
                        <div class="msc-field">
                            <label>Sponsor(s) <span style="color:#999;font-size:12px;">(optional, max 33 characters)</span></label>
                            <input type="text" id="pe_sponsors" maxlength="33" value="<?php echo esc_attr(get_user_meta($user->ID, 'msc_sponsors', true)); ?>" placeholder="e.g. Castrol, Bridgestone">
                        </div>
                    </div>

                    <div class="msc-form-section-title" style="margin-top:20px">Address</div>
                    <div class="msc-form-grid">
                        <div class="msc-field msc-field-full">
                            <label>Street Address <span class="msc-required">*</span></label>
                            <input type="text" id="pe_address1" value="<?php echo esc_attr(get_user_meta($user->ID, 'msc_address1', true)); ?>" placeholder="123 Main Road">
                        </div>
                        <div class="msc-field">
                            <label>City / Town <span class="msc-required">*</span></label>
                            <input type="text" id="pe_city" value="<?php echo esc_attr(get_user_meta($user->ID, 'msc_city', true)); ?>">
                        </div>
                        <div class="msc-field">
                            <label>Province <span class="msc-required">*</span></label>
                            <input type="text" id="pe_province" value="<?php echo esc_attr(get_user_meta($user->ID, 'msc_province', true)); ?>">
                        </div>
                        <div class="msc-field">
                            <label>Postal Code <span class="msc-required">*</span></label>
                            <input type="text" id="pe_postcode" value="<?php echo esc_attr(get_user_meta($user->ID, 'msc_postcode', true)); ?>">
                        </div>
                    </div>

                    <div class="msc-form-section-title" style="margin-top:20px">Emergency Contact</div>
                    <div class="msc-form-grid">
                        <div class="msc-field">
                            <label>Contact Name</label>
                            <input type="text" id="pe_emergency_name" value="<?php echo esc_attr(get_user_meta($user->ID, 'msc_emergency_name', true)); ?>" placeholder="Full name">
                        </div>
                        <div class="msc-field">
                            <label>Contact Phone</label>
                            <input type="tel" id="pe_emergency_phone" value="<?php echo esc_attr(get_user_meta($user->ID, 'msc_emergency_phone', true)); ?>" placeholder="+27 82 000 0000">
                        </div>
                        <div class="msc-field">
                            <label>Relationship</label>
                            <input type="text" id="pe_emergency_rel" value="<?php echo esc_attr(get_user_meta($user->ID, 'msc_emergency_rel', true)); ?>" placeholder="Spouse, Parent, etc.">
                        </div>
                    </div>

                    <div class="msc-form-section-title" style="margin-top:20px">Change Password <span style="font-weight:400;font-size:12px;color:#999">(leave blank to keep current)</span></div>
                    <div class="msc-form-grid">
                        <div class="msc-field">
                            <label>New Password</label>
                            <input type="password" id="pe_password" placeholder="Min. 8 characters" autocomplete="new-password">
                        </div>
                        <div class="msc-field">
                            <label>Confirm Password</label>
                            <input type="password" id="pe_password2" placeholder="Repeat new password" autocomplete="new-password">
                        </div>
                    </div>

                    <div class="msc-panel-footer" style="margin-top:24px;padding-top:16px;border-top:1px solid var(--msc-border)">
                        <button type="button" id="msc-save-profile" class="msc-btn">Save Changes</button>
                    </div>
                </div>
            </div>
            <?php endif; ?>

        </div>
        <?php
        return ob_get_clean();
    }

    public static function ajax_update_profile() {
        check_ajax_referer( 'msc_nonce', 'nonce' );
        $user_id = get_current_user_id();
        if ( ! $user_id ) wp_send_json_error( array( 'message' => 'Not logged in.' ) );

        $data = array( 'ID' => $user_id );

        if ( isset($_POST['first_name']) )    $data['first_name']    = sanitize_text_field($_POST['first_name']);
        if ( isset($_POST['last_name']) )     $data['last_name']     = sanitize_text_field($_POST['last_name']);
        if ( isset($_POST['display_name']) )  $data['display_name']  = sanitize_text_field($_POST['display_name']);

        // Required field validation
        $required = array(
            'first_name'   => 'First Name',
            'last_name'    => 'Last Name',
            'phone'        => 'Phone Number',
            'msc_address1' => 'Street Address',
            'msc_city'     => 'City / Town',
            'msc_province' => 'Province',
            'msc_postcode' => 'Postal Code',
        );
        foreach ( $required as $key => $label ) {
            $val = isset( $_POST[ $key ] ) ? sanitize_text_field( $_POST[ $key ] ) : ( $data[ $key ] ?? '' );
            if ( empty( $val ) ) {
                wp_send_json_error( array( 'message' => $label . ' is required.' ) );
            }
        }

        // Email change
        if ( ! empty($_POST['email']) ) {
            $new_email = sanitize_email($_POST['email']);
            if ( ! is_email($new_email) ) wp_send_json_error( array('message' => 'Invalid email address.') );
            $existing = email_exists($new_email);
            if ( $existing && $existing !== $user_id ) wp_send_json_error( array('message' => 'That email is already in use.') );
            $data['user_email'] = $new_email;
        }

        // Password change
        if ( ! empty($_POST['password']) ) {
            $pw  = $_POST['password'];
            $pw2 = $_POST['password2'] ?? '';
            if ( strlen($pw) < 8 )  wp_send_json_error( array('message' => 'Password must be at least 8 characters.') );
            if ( $pw !== $pw2 )     wp_send_json_error( array('message' => 'Passwords do not match.') );
            $data['user_pass'] = $pw;
        }

        wp_update_user($data);

        // Meta fields
        $meta_fields = array(
            'phone', 'msc_birthday',
            'msc_comp_number', 'msc_msa_licence', 'msc_medical_aid', 'msc_medical_aid_number',
            'msc_pit_crew_1', 'msc_pit_crew_2',
            'msc_address1', 'msc_city', 'msc_province', 'msc_postcode',
            'msc_emergency_name', 'msc_emergency_phone', 'msc_emergency_rel',
        );
        foreach ( $meta_fields as $key ) {
            if ( isset($_POST[$key]) ) {
                update_user_meta( $user_id, $key, sanitize_text_field($_POST[$key]) );
            }
        }
        if ( isset( $_POST['msc_sponsors'] ) ) {
            update_user_meta( $user_id, 'msc_sponsors', substr( sanitize_text_field( wp_unslash( $_POST['msc_sponsors'] ) ), 0, 33 ) );
        }
        if ( isset($_POST['msc_gender']) && in_array( $_POST['msc_gender'], array( 'male', 'female', '' ), true ) ) {
            update_user_meta( $user_id, 'msc_gender', $_POST['msc_gender'] );
        }

        wp_send_json_success( array('message' => 'Profile updated successfully!') );
    }

    public static function ajax_upload_profile_photo() {
        check_ajax_referer( 'msc_nonce', 'nonce' );
        $user_id = get_current_user_id();
        if ( ! $user_id ) wp_send_json_error( array( 'message' => 'Not logged in.' ) );

        if ( empty( $_FILES['photo'] ) || empty( $_FILES['photo']['name'] ) ) {
            wp_send_json_error( array( 'message' => 'No file received.' ) );
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        if ( $_FILES['photo']['size'] > 5 * 1024 * 1024 ) {
            wp_send_json_error( array( 'message' => 'Photo must be under 5MB.' ) );
        }

        $check = wp_check_filetype_and_ext( $_FILES['photo']['tmp_name'], $_FILES['photo']['name'] );
        if ( ! in_array( $check['ext'], array( 'jpg', 'jpeg', 'png', 'webp' ), true ) ) {
            wp_send_json_error( array( 'message' => 'Only JPG, PNG or WebP images are allowed.' ) );
        }

        // Delete existing profile photo
        $old_id = get_user_meta( $user_id, 'msc_profile_photo', true );
        if ( $old_id ) wp_delete_attachment( $old_id, true );

        $_FILES['photo']['name'] = 'profile-' . $user_id . '-' . time() . '.' . $check['ext'];
        $attachment_id = media_handle_upload( 'photo', 0 );
        if ( is_wp_error( $attachment_id ) ) {
            wp_send_json_error( array( 'message' => $attachment_id->get_error_message() ) );
        }

        update_user_meta( $user_id, 'msc_profile_photo', $attachment_id );
        $url = wp_get_attachment_image_url( $attachment_id, 'thumbnail' );

        wp_send_json_success( array( 'url' => $url ) );
    }

    public static function ajax_remove_profile_photo() {
        check_ajax_referer( 'msc_nonce', 'nonce' );
        $user_id = get_current_user_id();
        if ( ! $user_id ) wp_send_json_error( array( 'message' => 'Not logged in.' ) );

        $photo_id = get_user_meta( $user_id, 'msc_profile_photo', true );
        if ( $photo_id ) {
            wp_delete_attachment( $photo_id, true );
            delete_user_meta( $user_id, 'msc_profile_photo' );
        }

        wp_send_json_success();
    }

    public static function ajax_add_vehicle() {
        check_ajax_referer( 'msc_nonce', 'nonce' );
        $user_id = get_current_user_id();
        if ( ! $user_id ) wp_send_json_error( array( 'message' => 'Not logged in.' ) );

        $title = sanitize_text_field( $_POST['title'] ?? '' );
        if ( ! $title ) wp_send_json_error( array( 'message' => 'Vehicle name is required.' ) );

        $post_id = wp_insert_post( array(
            'post_title'  => $title,
            'post_type'   => 'msc_vehicle',
            'post_status' => 'publish',
            'post_author' => $user_id,
        ) );
        if ( is_wp_error( $post_id ) ) wp_send_json_error( array( 'message' => $post_id->get_error_message() ) );
        if ( ! $post_id ) wp_send_json_error( array( 'message' => 'Could not create vehicle record.' ) );

        foreach ( array( 'type', 'make', 'model', 'year', 'color', 'reg_number' ) as $f ) {
            if ( isset( $_POST[ $f ] ) ) {
                update_post_meta( $post_id, '_msc_' . $f, sanitize_text_field( $_POST[ $f ] ) );
            }
        }
        if ( ! empty( $_POST['notes'] ) ) {
            update_post_meta( $post_id, '_msc_notes', sanitize_textarea_field( $_POST['notes'] ) );
        }
        if ( isset( $_POST['engine_size'] ) ) {
            update_post_meta( $post_id, '_msc_engine_size', sanitize_text_field( $_POST['engine_size'] ) );
        }

        // Handle photo upload
        if ( ! empty( $_FILES['photo'] ) && ! empty( $_FILES['photo']['name'] ) ) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';

            if ( $_FILES['photo']['size'] > 5 * 1024 * 1024 ) {
                wp_delete_post( $post_id, true );
                wp_send_json_error( array( 'message' => 'Photo must be under 5MB.' ) );
            }
            // Validate using server-side type detection, not client-supplied MIME
            $check         = wp_check_filetype_and_ext( $_FILES['photo']['tmp_name'], $_FILES['photo']['name'] );
            $allowed_exts  = array( 'jpg', 'jpeg', 'png', 'webp' );
            if ( ! $check['ext'] || ! in_array( $check['ext'], $allowed_exts, true ) ) {
                wp_delete_post( $post_id, true );
                wp_send_json_error( array( 'message' => 'Invalid file type. JPG, PNG or WebP only.' ) );
            }
            $_FILES['photo']['name'] = sanitize_file_name( $_FILES['photo']['name'] );
            $attachment_id = media_handle_upload( 'photo', $post_id );
            if ( ! is_wp_error( $attachment_id ) ) {
                set_post_thumbnail( $post_id, $attachment_id );
            }
        }

        wp_send_json_success( array( 'message' => 'Vehicle added to your garage!', 'id' => $post_id ) );
    }

    public static function ajax_update_vehicle() {
        check_ajax_referer( 'msc_nonce', 'nonce' );
        $user_id = get_current_user_id();
        $post_id = intval( $_POST['vehicle_id'] );
        $post    = get_post( $post_id );
        if ( ! $post || (int) $post->post_author !== $user_id ) {
            wp_send_json_error( array( 'message' => 'Vehicle not found.' ) );
        }

        $title = sanitize_text_field( $_POST['title'] ?? '' );
        if ( ! $title ) wp_send_json_error( array( 'message' => 'Vehicle name is required.' ) );

        wp_update_post( array( 'ID' => $post_id, 'post_title' => $title ) );

        foreach ( array( 'type', 'make', 'model', 'year', 'color', 'reg_number' ) as $f ) {
            if ( isset( $_POST[ $f ] ) ) {
                update_post_meta( $post_id, '_msc_' . $f, sanitize_text_field( $_POST[ $f ] ) );
            }
        }
        update_post_meta( $post_id, '_msc_notes', sanitize_textarea_field( $_POST['notes'] ?? '' ) );
        update_post_meta( $post_id, '_msc_engine_size', sanitize_text_field( $_POST['engine_size'] ?? '' ) );

        // Handle new photo upload
        if ( ! empty( $_FILES['photo'] ) && ! empty( $_FILES['photo']['name'] ) ) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';

            if ( $_FILES['photo']['size'] > 5 * 1024 * 1024 ) {
                wp_send_json_error( array( 'message' => 'Photo must be under 5MB.' ) );
            }
            // Validate using server-side type detection, not client-supplied MIME
            $check        = wp_check_filetype_and_ext( $_FILES['photo']['tmp_name'], $_FILES['photo']['name'] );
            $allowed_exts = array( 'jpg', 'jpeg', 'png', 'webp' );
            if ( ! $check['ext'] || ! in_array( $check['ext'], $allowed_exts, true ) ) {
                wp_send_json_error( array( 'message' => 'Invalid file type. JPG, PNG or WebP only.' ) );
            }
            // Remove old thumbnail
            $old_thumb = get_post_thumbnail_id( $post_id );
            if ( $old_thumb ) wp_delete_attachment( $old_thumb, true );

            $_FILES['photo']['name'] = sanitize_file_name( $_FILES['photo']['name'] );
            $attachment_id = media_handle_upload( 'photo', $post_id );
            if ( ! is_wp_error( $attachment_id ) ) {
                set_post_thumbnail( $post_id, $attachment_id );
            }
        }

        wp_send_json_success( array( 'message' => 'Vehicle updated!' ) );
    }

    public static function ajax_delete_vehicle() {
        check_ajax_referer( 'msc_nonce', 'nonce' );
        $post_id = intval( $_POST['vehicle_id'] );
        $post    = get_post( $post_id );
        if ( ! $post || (int) $post->post_author !== get_current_user_id() ) {
            wp_send_json_error( array( 'message' => 'Not found.' ) );
        }
        $thumb_id = get_post_thumbnail_id( $post_id );
        if ( $thumb_id ) wp_delete_attachment( $thumb_id, true );
        wp_delete_post( $post_id, true );
        wp_send_json_success( array( 'message' => 'Vehicle removed.' ) );
    }
}
