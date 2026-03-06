<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class MSC_Admin_Participants {

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'add_submenu' ) );
    }

    public static function add_submenu() {
        add_submenu_page(
            'motorsport-club',
            'Participants',
            'Participants',
            'edit_posts',
            'msc-participants',
            array( __CLASS__, 'page' )
        );
    }

    public static function page() {
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_die( 'Unauthorized access.' );
        }

        $search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

        $query_args = array(
            'number'  => 200,
            'orderby' => 'display_name',
            'order'   => 'ASC',
        );
        if ( $search ) {
            $query_args['search']         = '*' . $search . '*';
            $query_args['search_columns'] = array( 'display_name', 'user_email', 'user_login' );
        }
        $users = get_users( $query_args );
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

    private static function detail_row( $label, $value ) {
        echo '<div style="display:flex;gap:6px;margin-bottom:6px;font-size:13px">';
        echo '<span style="color:#666;min-width:110px;flex-shrink:0">' . esc_html( $label ) . '</span>';
        echo '<span style="color:#23282d;font-weight:500">' . esc_html( $value ) . '</span>';
        echo '</div>';
    }
}
