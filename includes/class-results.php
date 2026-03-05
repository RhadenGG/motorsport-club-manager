<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * MSC_Results — Event Results Management
 * Handles DB table creation, admin meta boxes, and frontend display.
 */
class MSC_Results {

    public static function init() {
        add_action( 'add_meta_boxes',      array( __CLASS__, 'add_meta_boxes' ) );
        add_action( 'save_post_msc_event', array( __CLASS__, 'save_status' ) );
        add_action( 'save_post_msc_event', array( __CLASS__, 'save_results' ) );
    }

    // ─────────────────────────────────────────────────────────────────
    // 1. DATABASE
    // ─────────────────────────────────────────────────────────────────

    public static function create_table() {
        global $wpdb;
        $table   = $wpdb->prefix . 'msc_event_results';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table (
            id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id         BIGINT UNSIGNED NOT NULL,
            registration_id  BIGINT UNSIGNED NOT NULL,
            position         INT UNSIGNED    DEFAULT NULL,
            laps_completed   INT UNSIGNED    DEFAULT NULL,
            best_lap_time    VARCHAR(20)     DEFAULT NULL,
            total_race_time  VARCHAR(20)     DEFAULT NULL,
            status           ENUM('Finished','DNF','DNS','DSQ') NOT NULL DEFAULT 'Finished',
            notes            TEXT            DEFAULT NULL,
            created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY event_reg (event_id, registration_id),
            KEY event_id (event_id)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    // ─────────────────────────────────────────────────────────────────
    // 2. EVENT STATUS META BOX  (sidebar)
    // ─────────────────────────────────────────────────────────────────

    public static function add_meta_boxes() {
        add_meta_box(
            'msc_event_status_box',
            '🏁 Event Status',
            array( __CLASS__, 'render_status_box' ),
            'msc_event',
            'side',
            'high'
        );
        add_meta_box(
            'msc_results_box',
            '🏆 Event Results',
            array( __CLASS__, 'render_results_box' ),
            'msc_event',
            'normal',
            'default'
        );
    }

    public static function render_status_box( $post ) {
        $status = get_post_meta( $post->ID, '_msc_event_status', true ) ?: 'open';
        wp_nonce_field( 'msc_event_status_nonce', 'msc_event_status_nonce' );
        ?>
        <label for="msc_event_status"><strong>Event Status:</strong></label>
        <select name="msc_event_status" id="msc_event_status" style="width:100%;margin-top:6px;">
            <option value="open"   <?php selected( $status, 'open' ); ?>>🟢 Open</option>
            <option value="closed" <?php selected( $status, 'closed' ); ?>>🔴 Closed</option>
        </select>
        <p style="font-size:12px;color:#666;margin-top:8px;">
            Set to <strong>Closed</strong> after the event to lock registrations and enable results entry below.
        </p>
        <?php
    }

    public static function save_status( $post_id ) {
        if (
            ! isset( $_POST['msc_event_status_nonce'] ) ||
            ! wp_verify_nonce( $_POST['msc_event_status_nonce'], 'msc_event_status_nonce' ) ||
            ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ||
            ! current_user_can( 'edit_post', $post_id )
        ) return;

        if ( isset( $_POST['msc_event_status'] ) ) {
            $allowed_statuses = array( 'open', 'closed' );
            $status = sanitize_text_field( $_POST['msc_event_status'] );
            if ( in_array( $status, $allowed_statuses, true ) ) {
                update_post_meta( $post_id, '_msc_event_status', $status );
            }
        }
    }

    public static function is_closed( $event_id ) {
        return get_post_meta( $event_id, '_msc_event_status', true ) === 'closed';
    }

    // ─────────────────────────────────────────────────────────────────
    // 3. RESULTS ENTRY META BOX  (normal area)
    // ─────────────────────────────────────────────────────────────────

    public static function render_results_box( $post ) {
        global $wpdb;

        $event_id  = $post->ID;
        $reg_table = $wpdb->prefix . 'msc_registrations';
        $res_table = $wpdb->prefix . 'msc_event_results';

        $registrations = $wpdb->get_results( $wpdb->prepare(
            "SELECT r.id, r.class_id, u.display_name as member_name, v.post_title as vehicle_name
             FROM $reg_table r
             LEFT JOIN {$wpdb->users} u ON u.ID = r.user_id
             LEFT JOIN {$wpdb->posts} v ON v.ID = r.vehicle_id
             WHERE r.event_id = %d
               AND r.status NOT IN ('rejected','cancelled')
             ORDER BY u.display_name ASC",
            $event_id
        ) );

        if ( empty( $registrations ) ) {
            echo '<p style="color:#888;font-style:italic;">No confirmed registrations found for this event.</p>';
            return;
        }

        // Fetch existing results keyed by registration_id
        $existing_rows  = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $res_table WHERE event_id = %d", $event_id
        ) );
        $results_by_reg = [];
        foreach ( $existing_rows as $row ) {
            $results_by_reg[ $row->registration_id ] = $row;
        }

        if ( ! self::is_closed( $event_id ) ) {
            echo '<div style="background:#fff3cd;border-left:4px solid #ffc107;padding:10px 14px;margin-bottom:14px;border-radius:4px;">
                    ⚠️ <strong>Event is still Open.</strong> Set the Event Status to <em>Closed</em> (sidebar) before entering results. Results will not appear publicly until the event is closed.
                  </div>';
        }

        wp_nonce_field( 'msc_save_results_nonce', 'msc_save_results_nonce' );
        ?>
        <p style="color:#555;margin-top:0;">Enter results for each registered participant. Leave Position blank for DNF/DNS/DSQ entries.</p>

        <table class="widefat fixed striped" style="margin-top:8px;">
            <thead>
                <tr>
                    <th style="width:16%;">Driver</th>
                    <th style="width:16%;">Vehicle</th>
                    <th style="width:14%;">Class</th>
                    <th style="width:7%;">Pos</th>
                    <th style="width:8%;">Laps</th>
                    <th style="width:12%;">Best Lap <small style="font-weight:400;">(m:ss.ms)</small></th>
                    <th style="width:12%;">Total Time <small style="font-weight:400;">(h:mm:ss)</small></th>
                    <th style="width:10%;">Status</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $registrations as $reg ) :
                $r = $results_by_reg[ $reg->id ] ?? null;
                $class_name = '—';
                if ( ! empty( $reg->class_id ) ) {
                    $term = get_term( $reg->class_id, 'msc_vehicle_class' );
                    if ( $term && ! is_wp_error( $term ) ) $class_name = $term->name;
                }
            ?>
                <tr>
                    <td>
                        <strong><?php echo esc_html( $reg->member_name ); ?></strong>
                        <input type="hidden"
                               name="msc_results[<?php echo $reg->id; ?>][registration_id]"
                               value="<?php echo $reg->id; ?>">
                    </td>
                    <td style="color:#555;font-size:12px;"><?php echo esc_html( $reg->vehicle_name ?: '—' ); ?></td>
                    <td style="color:#555;font-size:12px;"><?php echo esc_html( $class_name ); ?></td>
                    <td>
                        <input type="number"
                               name="msc_results[<?php echo $reg->id; ?>][position]"
                               value="<?php echo esc_attr( $r->position ?? '' ); ?>"
                               min="1" style="width:100%;">
                    </td>
                    <td>
                        <input type="number"
                               name="msc_results[<?php echo $reg->id; ?>][laps_completed]"
                               value="<?php echo esc_attr( $r->laps_completed ?? '' ); ?>"
                               min="0" style="width:100%;">
                    </td>
                    <td>
                        <input type="text"
                               name="msc_results[<?php echo $reg->id; ?>][best_lap_time]"
                               value="<?php echo esc_attr( $r->best_lap_time ?? '' ); ?>"
                               placeholder="1:23.456" style="width:100%;">
                    </td>
                    <td>
                        <input type="text"
                               name="msc_results[<?php echo $reg->id; ?>][total_race_time]"
                               value="<?php echo esc_attr( $r->total_race_time ?? '' ); ?>"
                               placeholder="0:45:12" style="width:100%;">
                    </td>
                    <td>
                        <select name="msc_results[<?php echo $reg->id; ?>][status]" style="width:100%;">
                            <?php
                            $statuses = [ 'Finished', 'DNF', 'DNS', 'DSQ' ];
                            $cur      = $r->status ?? 'Finished';
                            foreach ( $statuses as $s ) {
                                printf(
                                    '<option value="%s"%s>%s</option>',
                                    $s, selected( $cur, $s, false ), $s
                                );
                            }
                            ?>
                        </select>
                    </td>
                    <td>
                        <input type="text"
                               name="msc_results[<?php echo $reg->id; ?>][notes]"
                               value="<?php echo esc_attr( $r->notes ?? '' ); ?>"
                               placeholder="Optional…" style="width:100%;">
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="description" style="margin-top:8px;">
            Results are sorted automatically: Finished entries by position, then DNF → DNS → DSQ.
        </p>
        <?php
    }

    public static function save_results( $post_id ) {
        global $wpdb;

        if (
            ! isset( $_POST['msc_save_results_nonce'] ) ||
            ! wp_verify_nonce( $_POST['msc_save_results_nonce'], 'msc_save_results_nonce' ) ||
            ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ||
            ! current_user_can( 'edit_post', $post_id ) ||
            empty( $_POST['msc_results'] )
        ) return;

        $res_table      = $wpdb->prefix . 'msc_event_results';
        $valid_statuses = [ 'Finished', 'DNF', 'DNS', 'DSQ' ];

        foreach ( $_POST['msc_results'] as $reg_id => $data ) {
            $reg_id = absint( $reg_id );
            if ( ! $reg_id ) continue;

            $row = [
                'event_id'        => $post_id,
                'registration_id' => $reg_id,
                'position'        => ( isset( $data['position'] ) && $data['position'] !== '' )
                                        ? absint( $data['position'] ) : null,
                'laps_completed'  => ( isset( $data['laps_completed'] ) && $data['laps_completed'] !== '' )
                                        ? absint( $data['laps_completed'] ) : null,
                'best_lap_time'   => sanitize_text_field( $data['best_lap_time']   ?? '' ) ?: null,
                'total_race_time' => sanitize_text_field( $data['total_race_time'] ?? '' ) ?: null,
                'status'          => in_array( $data['status'] ?? '', $valid_statuses )
                                        ? $data['status'] : 'Finished',
                'notes'           => sanitize_text_field( $data['notes'] ?? '' ) ?: null,
            ];

            $formats = [ '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s' ];

            $existing_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM $res_table WHERE event_id = %d AND registration_id = %d",
                $post_id, $reg_id
            ) );

            if ( $existing_id ) {
                $wpdb->update( $res_table, $row, [ 'id' => $existing_id ], $formats, [ '%d' ] );
            } else {
                $wpdb->insert( $res_table, $row, $formats );
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // 4. FRONTEND HTML
    // ─────────────────────────────────────────────────────────────────

    public static function get_results_html( $event_id ) {
        global $wpdb;

        $res_table = $wpdb->prefix . 'msc_event_results';
        $reg_table = $wpdb->prefix . 'msc_registrations';

        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT res.*,
                    reg.class_id,
                    u.display_name AS member_name,
                    v.post_title   AS vehicle_name
             FROM $res_table res
             LEFT JOIN $reg_table       reg ON reg.id = res.registration_id
             LEFT JOIN {$wpdb->users}   u   ON u.ID   = reg.user_id
             LEFT JOIN {$wpdb->posts}   v   ON v.ID   = reg.vehicle_id
             WHERE res.event_id = %d
             ORDER BY
                 CASE res.status
                     WHEN 'Finished' THEN 0
                     WHEN 'DNF'      THEN 1
                     WHEN 'DNS'      THEN 2
                     WHEN 'DSQ'      THEN 3
                     ELSE 4
                 END,
                 res.position ASC,
                 res.laps_completed DESC",
            $event_id
        ) );

        if ( empty( $results ) ) return '';

        $badge_color = [
            'Finished' => '#2ecc71',
            'DNF'      => '#e67e22',
            'DNS'      => '#95a5a6',
            'DSQ'      => '#e74c3c',
        ];

        // Top 3 finishers for podium
        $finishers = array_values( array_filter( $results, fn($r) => $r->status === 'Finished' && $r->position ) );
        usort( $finishers, fn($a,$b) => (int)$a->position <=> (int)$b->position );
        $podium = array_slice( $finishers, 0, 3 );

        ob_start();
        ?>
        <div class="msc-results-wrap">

            <h2 class="msc-results-title">🏆 Race Results</h2>

            <?php if ( ! empty( $podium ) ) : ?>
            <div class="msc-podium">
                <?php
                $medals = [ '🥇', '🥈', '🥉' ];
                foreach ( $podium as $i => $p ) : ?>
                <div class="msc-podium-card">
                    <div class="msc-podium-medal"><?php echo $medals[ $i ]; ?></div>
                    <div class="msc-podium-name"><?php echo esc_html( $p->member_name ); ?></div>
                    <div class="msc-podium-vehicle">
                        <?php 
                        echo esc_html( $p->vehicle_name ?: '—' );
                        if ( ! empty( $p->class_id ) ) {
                            $term = get_term( $p->class_id, 'msc_vehicle_class' );
                            if ( $term && ! is_wp_error( $term ) ) {
                                echo ' <span class="msc-podium-class">(' . esc_html( $term->name ) . ')</span>';
                            }
                        }
                        ?>
                    </div>
                    <?php if ( $p->total_race_time ) : ?>
                    <div class="msc-podium-stat">⏱ <?php echo esc_html( $p->total_race_time ); ?></div>
                    <?php endif; ?>
                    <?php if ( $p->best_lap_time ) : ?>
                    <div class="msc-podium-stat msc-podium-bestlap">Best: <?php echo esc_html( $p->best_lap_time ); ?></div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <h3 class="msc-results-subtitle">Full Classification</h3>
            <div class="msc-results-table-wrap">
                <table class="msc-results-table">
                    <thead>
                        <tr>
                            <th class="msc-col-pos">Pos</th>
                            <th class="msc-col-driver">Driver</th>
                            <th class="msc-col-vehicle">Vehicle</th>
                            <th class="msc-col-class">Class</th>
                            <th class="msc-col-status">Status</th>
                            <th class="msc-col-laps">Laps</th>
                            <th class="msc-col-time">Best Lap</th>
                            <th class="msc-col-time">Total Time</th>
                            <th class="msc-col-notes">Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $results as $i => $r ) :
                        $bc        = $badge_color[ $r->status ] ?? '#aaa';
                        $row_class = $i % 2 === 0 ? 'msc-row-even' : 'msc-row-odd';
                    ?>
                        <tr class="<?php echo $row_class; ?>">
                            <td class="msc-col-pos">
                                <strong><?php echo $r->position ? esc_html( $r->position ) : '—'; ?></strong>
                            </td>
                            <td class="msc-col-driver"><?php echo esc_html( $r->member_name ); ?></td>
                            <td class="msc-col-vehicle"><?php echo esc_html( $r->vehicle_name ?: '—' ); ?></td>
                            <td class="msc-col-class">
                                <?php 
                                if ( ! empty( $r->class_id ) ) {
                                    $term = get_term( $r->class_id, 'msc_vehicle_class' );
                                    echo ( $term && ! is_wp_error( $term ) ) ? esc_html( $term->name ) : '—';
                                } else {
                                    echo '—';
                                }
                                ?>
                            </td>
                            <td class="msc-col-status">
                                <span class="msc-result-badge" style="background:<?php echo $bc; ?>;">
                                    <?php echo esc_html( $r->status ); ?>
                                </span>
                            </td>
                            <td class="msc-col-laps">
                                <?php echo $r->laps_completed !== null ? esc_html( $r->laps_completed ) : '—'; ?>
                            </td>
                            <td class="msc-col-time msc-mono">
                                <?php echo $r->best_lap_time ? esc_html( $r->best_lap_time ) : '—'; ?>
                            </td>
                            <td class="msc-col-time msc-mono">
                                <?php echo $r->total_race_time ? esc_html( $r->total_race_time ) : '—'; ?>
                            </td>
                            <td class="msc-col-notes">
                                <?php echo $r->notes ? esc_html( $r->notes ) : ''; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        </div>
        <?php
        return ob_get_clean();
    }
}
