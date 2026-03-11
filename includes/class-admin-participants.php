<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class MSC_Admin_Participants {

    public static function init() {
    }

    public static function page() {
        if ( ! current_user_can( self::required_cap() ) ) {
            wp_die( 'Unauthorized access.' );
        }

        $search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
        $users  = self::get_participants( $search );
        ?>
        <div class="wrap">
        <h1>Participants</h1>

        <form method="get" style="margin:16px 0 20px">
            <input type="hidden" name="page" value="msc-participants">
            <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>"
                placeholder="Search by name or email…"
                style="width:280px;padding:6px 10px;font-size:14px;border:1px solid #ccc;border-radius:3px">
            <button type="submit" class="button" style="vertical-align:middle">Search</button>
            <?php if ( $search ) : ?>
                <a href="<?php echo esc_url( admin_url('admin.php?page=msc-participants') ); ?>"
                   class="button" style="vertical-align:middle">Clear</a>
            <?php endif; ?>
        </form>

        <?php if ( empty( $users ) ) : ?>
            <p>No participants found.</p>
        <?php else : ?>

        <p style="color:#666;font-size:13px;margin-bottom:12px">
            <?php echo count( $users ); ?> participant(s) found. Click a row to expand details.
        </p>

        <table class="widefat striped" id="msc-participants-table">
        <thead>
        <tr>
            <th style="width:24px"></th>
            <th>Name</th>
            <th>Email</th>
            <th>Phone</th>
            <th>Competition #</th>
            <th>Registrations</th>
        </tr>
        </thead>
        <tbody>
        <?php
        global $wpdb;
        foreach ( $users as $user ) :
            $meta         = get_user_meta( $user->ID );
            $get          = function( $key ) use ( $meta ) {
                return isset( $meta[ $key ][0] ) ? $meta[ $key ][0] : '';
            };
            $phone        = $get('phone');
            $birthday     = $get('msc_birthday');
            $gender       = $get('msc_gender');
            $comp_number  = $get('msc_comp_number');
            $msa_licence  = $get('msc_msa_licence');
            $medical_aid  = $get('msc_medical_aid');
            $medical_no   = $get('msc_medical_aid_number');
            $address1     = $get('msc_address1');
            $city         = $get('msc_city');
            $province     = $get('msc_province');
            $postcode     = $get('msc_postcode');
            $em_name      = $get('msc_emergency_name');
            $em_phone     = $get('msc_emergency_phone');
            $em_rel       = $get('msc_emergency_rel');
            $pit_crew_1   = $get('msc_pit_crew_1');
            $pit_crew_2   = $get('msc_pit_crew_2');

            $reg_count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}msc_registrations WHERE user_id = %d AND status NOT IN ('cancelled','rejected')",
                $user->ID
            ) );

            $uid = 'msc-p-' . $user->ID;
        ?>
        <tr class="msc-p-row" data-target="<?php echo esc_attr( $uid ); ?>"
            style="cursor:pointer" title="Click to expand">
            <td style="text-align:center;color:#aaa;font-size:16px" class="msc-p-chevron">&#9654;</td>
            <td>
                <strong><?php echo esc_html( $user->display_name ); ?></strong><br>
                <span style="color:#666;font-size:12px"><?php echo esc_html( $user->first_name . ' ' . $user->last_name ); ?></span>
            </td>
            <td><?php echo esc_html( $user->user_email ); ?></td>
            <td><?php echo esc_html( $phone ?: '—' ); ?></td>
            <td><?php echo esc_html( $comp_number ?: '—' ); ?></td>
            <td>
                <?php if ( $reg_count ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=msc-registrations&search_user=' . $user->ID ) ); ?>"
                       style="text-decoration:none">
                        <?php echo $reg_count; ?> event<?php echo $reg_count !== 1 ? 's' : ''; ?>
                    </a>
                <?php else : ?>
                    <span style="color:#aaa">0</span>
                <?php endif; ?>
            </td>
        </tr>
        <tr id="<?php echo esc_attr( $uid ); ?>" class="msc-p-detail" style="display:none;background:#f9f9f9">
            <td colspan="6" style="padding:20px 24px">
            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:20px">

                <!-- Personal Details -->
                <div>
                    <p style="font-weight:700;margin:0 0 10px;font-size:13px;text-transform:uppercase;letter-spacing:.5px;color:#23282d;border-bottom:2px solid #2271b1;padding-bottom:5px">Personal Details</p>
                    <?php self::detail_row( 'Date of Birth', $birthday ?: '—' ); ?>
                    <?php self::detail_row( 'Gender',        ucfirst( $gender ) ?: '—' ); ?>
                    <?php self::detail_row( 'Phone',         $phone ?: '—' ); ?>
                    <?php
                    $addr_parts = array_filter( array( $address1, $city, $province, $postcode ) );
                    self::detail_row( 'Address', $addr_parts ? implode( ', ', $addr_parts ) : '—' );
                    ?>
                </div>

                <!-- Motorsport Details -->
                <div>
                    <p style="font-weight:700;margin:0 0 10px;font-size:13px;text-transform:uppercase;letter-spacing:.5px;color:#23282d;border-bottom:2px solid #00a32a;padding-bottom:5px">Motorsport Details</p>
                    <?php self::detail_row( 'Competition #',  $comp_number ?: '—' ); ?>
                    <?php self::detail_row( 'MSA Licence',    $msa_licence ?: '—' ); ?>
                    <?php self::detail_row( 'Medical Aid',    $medical_aid ?: '—' ); ?>
                    <?php self::detail_row( 'Medical Aid #',  $medical_no ?: '—' ); ?>
                    <?php self::detail_row( 'Pit Crew #1',    $pit_crew_1 ?: '—' ); ?>
                    <?php self::detail_row( 'Pit Crew #2',    $pit_crew_2 ?: '—' ); ?>
                </div>

                <!-- Emergency Contact -->
                <div>
                    <p style="font-weight:700;margin:0 0 10px;font-size:13px;text-transform:uppercase;letter-spacing:.5px;color:#23282d;border-bottom:2px solid #d63638;padding-bottom:5px">Emergency Contact</p>
                    <?php self::detail_row( 'Name',         $em_name ?: '—' ); ?>
                    <?php self::detail_row( 'Phone',        $em_phone ?: '—' ); ?>
                    <?php self::detail_row( 'Relationship', $em_rel ?: '—' ); ?>
                </div>

            </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        </table>

        <script>
        (function(){
            document.querySelectorAll('.msc-p-row').forEach(function(row){
                row.addEventListener('click', function(){
                    var target  = document.getElementById(row.dataset.target);
                    var chevron = row.querySelector('.msc-p-chevron');
                    if (!target) return;
                    var open = target.style.display !== 'none';
                    target.style.display  = open ? 'none' : 'table-row';
                    chevron.innerHTML     = open ? '&#9654;' : '&#9660;';
                    chevron.style.color   = open ? '#aaa' : '#2271b1';
                });
            });
        })();
        </script>

        <?php endif; ?>
        </div>
        <?php
    }

    /** Returns the capability required to view the participants dashboard. */
    public static function required_cap() {
        return 'msc_view_participants';
    }

    /**
     * Fetch participants matching an optional search string.
     *
     * @param  string $search
     * @return WP_User[]
     */
    private static function get_participants( $search = '' ) {
        $args = array(
            'number'  => 200,
            'orderby' => 'display_name',
            'order'   => 'ASC',
        );
        if ( $search ) {
            $args['search']         = '*' . $search . '*';
            $args['search_columns'] = array( 'display_name', 'user_email', 'user_login' );
        }
        return get_users( $args );
    }

    // ── Frontend shortcode ────────────────────────────────────────────────

    public static function frontend_dashboard( $atts ) {
        if ( ! is_user_logged_in() || ! current_user_can( self::required_cap() ) ) {
            return '<p class="msc-notice">You do not have permission to view this page.</p>';
        }

        $search = isset( $_GET['msc_ps'] ) ? sanitize_text_field( wp_unslash( $_GET['msc_ps'] ) ) : '';
        $users  = self::get_participants( $search );

        ob_start();
        ?>
        <div class="msc-participants-wrap">

            <div class="msc-tab-header" style="margin-bottom:20px">
                <h3 class="msc-tab-title">Participants</h3>
            </div>

            <form method="get" style="margin-bottom:20px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
                <?php
                // Preserve any other query args (like page slug) but strip msc_ps
                foreach ( $_GET as $k => $v ) {
                    if ( $k === 'msc_ps' ) continue;
                    echo '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr($v) . '">';
                }
                ?>
                <input type="search" name="msc_ps" value="<?php echo esc_attr( $search ); ?>"
                    placeholder="Search by name or email…"
                    style="flex:1;min-width:200px;max-width:340px">
                <button type="submit" class="msc-btn">Search</button>
                <?php if ( $search ) : ?>
                    <a href="<?php echo esc_url( remove_query_arg('msc_ps') ); ?>" class="msc-btn msc-btn-outline">Clear</a>
                <?php endif; ?>
            </form>

            <?php if ( empty( $users ) ) : ?>
                <p>No participants found.</p>
            <?php else : ?>

            <p style="color:#888;font-size:13px;margin-bottom:14px">
                <?php echo count( $users ); ?> participant<?php echo count($users) !== 1 ? 's' : ''; ?> found.
                Click a row to expand details.
            </p>

            <div class="msc-p-list">
            <?php
            global $wpdb;
            foreach ( $users as $user ) :
                $meta       = get_user_meta( $user->ID );
                $get        = function( $key ) use ( $meta ) {
                    return isset( $meta[ $key ][0] ) ? $meta[ $key ][0] : '';
                };
                $phone      = $get('phone');
                $birthday   = $get('msc_birthday');
                $gender     = $get('msc_gender');
                $comp       = $get('msc_comp_number');
                $msa        = $get('msc_msa_licence');
                $med_aid    = $get('msc_medical_aid');
                $med_no     = $get('msc_medical_aid_number');
                $address1   = $get('msc_address1');
                $city       = $get('msc_city');
                $province   = $get('msc_province');
                $postcode   = $get('msc_postcode');
                $em_name    = $get('msc_emergency_name');
                $em_phone   = $get('msc_emergency_phone');
                $em_rel     = $get('msc_emergency_rel');
                $pit1       = $get('msc_pit_crew_1');
                $pit2       = $get('msc_pit_crew_2');
                $reg_count  = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}msc_registrations WHERE user_id=%d AND status NOT IN ('cancelled','rejected')",
                    $user->ID
                ) );
                $uid = 'msc-fp-' . $user->ID;
                $addr_parts = array_filter( array( $address1, $city, $province, $postcode ) );
            ?>
            <div class="msc-p-card">
                <div class="msc-p-card-header" data-target="<?php echo esc_attr($uid); ?>">
                    <div style="display:flex;align-items:center;gap:12px;flex:1;min-width:0">
                        <span class="msc-p-chevron" style="color:#aaa;font-size:13px;flex-shrink:0">&#9654;</span>
                        <div style="min-width:0">
                            <strong style="display:block"><?php echo esc_html( $user->display_name ); ?></strong>
                            <span style="font-size:12px;color:#888"><?php echo esc_html( $user->user_email ); ?></span>
                        </div>
                    </div>
                    <div style="display:flex;gap:24px;flex-shrink:0;font-size:13px;color:#555">
                        <span><?php echo esc_html( $phone ?: '—' ); ?></span>
                        <span><?php echo $comp ? '<strong>Comp #</strong> ' . esc_html($comp) : '<span style="color:#ccc">No comp #</span>'; ?></span>
                        <span style="white-space:nowrap">
                            <?php if ( $reg_count ) : ?>
                                <span style="background:#e8f5e9;color:#2e7d32;padding:2px 8px;border-radius:10px;font-size:12px;font-weight:600">
                                    <?php echo $reg_count; ?> event<?php echo $reg_count !== 1 ? 's' : ''; ?>
                                </span>
                            <?php else : ?>
                                <span style="color:#ccc;font-size:12px">No events</span>
                            <?php endif; ?>
                        </span>
                    </div>
                </div>

                <div id="<?php echo esc_attr($uid); ?>" class="msc-p-card-body" style="display:none">
                    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:20px">

                        <div>
                            <p class="msc-p-section-title" style="border-bottom-color:#2271b1">Personal Details</p>
                            <?php self::fe_row('Date of Birth', $birthday ?: '—'); ?>
                            <?php self::fe_row('Gender',        $gender ? ucfirst($gender) : '—'); ?>
                            <?php self::fe_row('Phone',         $phone ?: '—'); ?>
                            <?php self::fe_row('Address',       $addr_parts ? implode(', ', $addr_parts) : '—'); ?>
                        </div>

                        <div>
                            <p class="msc-p-section-title" style="border-bottom-color:#00a32a">Motorsport Details</p>
                            <?php self::fe_row('Competition #', $comp ?: '—'); ?>
                            <?php self::fe_row('MSA Licence',   $msa ?: '—'); ?>
                            <?php self::fe_row('Medical Aid',   $med_aid ?: '—'); ?>
                            <?php self::fe_row('Medical Aid #', $med_no ?: '—'); ?>
                            <?php self::fe_row('Pit Crew #1',   $pit1 ?: '—'); ?>
                            <?php self::fe_row('Pit Crew #2',   $pit2 ?: '—'); ?>
                        </div>

                        <div>
                            <p class="msc-p-section-title" style="border-bottom-color:#d63638">Emergency Contact</p>
                            <?php self::fe_row('Name',         $em_name ?: '—'); ?>
                            <?php self::fe_row('Phone',        $em_phone ?: '—'); ?>
                            <?php self::fe_row('Relationship', $em_rel ?: '—'); ?>
                        </div>

                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            </div>

            <?php endif; ?>
        </div>

        <style>
        .msc-participants-wrap { max-width:100%; }
        .msc-p-list { display:flex; flex-direction:column; gap:6px; }
        .msc-p-card { border:1px solid var(--msc-border,#e0e0e0); border-radius:6px; overflow:hidden; background:#fff; }
        .msc-p-card-header { display:flex; align-items:center; justify-content:space-between; gap:16px; padding:14px 18px; cursor:pointer; user-select:none; transition:background .15s; flex-wrap:wrap; }
        .msc-p-card-header:hover { background:#f7f7f7; }
        .msc-p-card-body { padding:20px 18px; border-top:1px solid var(--msc-border,#e0e0e0); background:#fafafa; }
        .msc-p-section-title { font-weight:700; margin:0 0 10px; font-size:12px; text-transform:uppercase; letter-spacing:.5px; border-bottom:2px solid #ccc; padding-bottom:5px; color:#444; }
        .msc-p-fe-row { display:flex; gap:6px; margin-bottom:7px; font-size:13px; }
        .msc-p-fe-row .msc-p-label { color:#888; min-width:110px; flex-shrink:0; }
        .msc-p-fe-row .msc-p-value { color:#222; font-weight:500; }
        @media (max-width:640px) {
            .msc-p-card-body > div { grid-template-columns:1fr !important; }
            .msc-p-card-header > div:last-child { display:none; }
        }
        </style>

        <script>
        (function(){
            document.querySelectorAll('.msc-p-card-header').forEach(function(hdr){
                hdr.addEventListener('click', function(){
                    var body    = document.getElementById(hdr.dataset.target);
                    var chevron = hdr.querySelector('.msc-p-chevron');
                    if (!body) return;
                    var open = body.style.display !== 'none';
                    body.style.display  = open ? 'none' : 'block';
                    chevron.innerHTML   = open ? '&#9654;' : '&#9660;';
                    chevron.style.color = open ? '#aaa' : 'var(--msc-primary,#e94560)';
                });
            });
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    private static function fe_row( $label, $value ) {
        echo '<div class="msc-p-fe-row">';
        echo '<span class="msc-p-label">' . esc_html( $label ) . '</span>';
        echo '<span class="msc-p-value">' . esc_html( $value ) . '</span>';
        echo '</div>';
    }

    private static function detail_row( $label, $value ) {
        echo '<div style="display:flex;gap:6px;margin-bottom:6px;font-size:13px">';
        echo '<span style="color:#666;min-width:110px;flex-shrink:0">' . esc_html( $label ) . '</span>';
        echo '<span style="color:#23282d;font-weight:500">' . esc_html( $value ) . '</span>';
        echo '</div>';
    }
}
