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
            registration_id  BIGINT UNSIGNED DEFAULT NULL,
            driver_name      VARCHAR(150)    DEFAULT NULL,
            manual_vehicle   VARCHAR(150)    DEFAULT NULL,
            class_id         BIGINT UNSIGNED DEFAULT NULL,
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

        // Explicit column migrations — dbDelta is unreliable for existing tables.
        $existing_cols = $wpdb->get_col( "DESCRIBE $table", 0 );

        if ( ! in_array( 'driver_name', $existing_cols, true ) ) {
            $wpdb->query( "ALTER TABLE $table ADD COLUMN driver_name VARCHAR(150) DEFAULT NULL AFTER registration_id" );
        }
        if ( ! in_array( 'manual_vehicle', $existing_cols, true ) ) {
            $wpdb->query( "ALTER TABLE $table ADD COLUMN manual_vehicle VARCHAR(150) DEFAULT NULL AFTER driver_name" );
        }
        if ( ! in_array( 'class_id', $existing_cols, true ) ) {
            $wpdb->query( "ALTER TABLE $table ADD COLUMN class_id BIGINT UNSIGNED DEFAULT NULL AFTER manual_vehicle" );
        }

        // Make registration_id nullable for existing installs
        $col = $wpdb->get_row( $wpdb->prepare(
            "SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'registration_id'",
            DB_NAME,
            $table
        ) );
        if ( $col && $col->IS_NULLABLE === 'NO' ) {
            $wpdb->query( "ALTER TABLE $table MODIFY COLUMN registration_id BIGINT UNSIGNED DEFAULT NULL" );
        }
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

        // Split existing results: registered vs manual
        $existing_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $res_table WHERE event_id = %d", $event_id
        ) );
        $results_by_reg = [];
        $manual_results = [];
        foreach ( $existing_rows as $row ) {
            if ( $row->registration_id ) {
                $results_by_reg[ $row->registration_id ] = $row;
            } else {
                $manual_results[] = $row;
            }
        }

        // Vehicle classes for manual entry dropdown
        $all_classes = get_terms( [ 'taxonomy' => 'msc_vehicle_class', 'hide_empty' => false ] );
        if ( is_wp_error( $all_classes ) ) $all_classes = [];

        if ( ! self::is_closed( $event_id ) ) {
            echo '<div style="background:#fff3cd;border-left:4px solid #ffc107;padding:10px 14px;margin-bottom:14px;border-radius:4px;">
                    ⚠️ <strong>Event is still Open.</strong> Set the Event Status to <em>Closed</em> (sidebar) before entering results. Results will not appear publicly until the event is closed.
                  </div>';
        }

        wp_nonce_field( 'msc_save_results_nonce', 'msc_save_results_nonce' );
        ?>

        <style>
        #msc_results_box .msc-re-table td,
        #msc_results_box .msc-re-table th { padding: 4px 6px; vertical-align: middle; }
        #msc_results_box .msc-re-table input[type="number"],
        #msc_results_box .msc-re-table input[type="text"],
        #msc_results_box .msc-re-table select { width: 100%; box-sizing: border-box; }
        #msc_results_box .msc-re-table textarea { width: 100%; height: 44px; resize: vertical; box-sizing: border-box; font-family: inherit; font-size: 13px; }
        #msc_results_box .msc-re-section { margin-top: 20px; }
        #msc_results_box .msc-re-section h4 { margin: 0 0 6px; font-size: 14px; }
        </style>

        <p style="color:#555;margin-top:0;">Enter results for each participant. Leave Position blank for DNF/DNS/DSQ entries.</p>

        <?php if ( ! empty( $registrations ) ) : ?>
        <table class="widefat fixed striped msc-re-table">
            <thead>
                <tr>
                    <th style="width:17%;">Driver</th>
                    <th style="width:16%;">Vehicle</th>
                    <th style="width:11%;">Class</th>
                    <th style="width:5%;">Pos</th>
                    <th style="width:5%;">Laps</th>
                    <th style="width:10%;">Best Lap <small style="font-weight:400;">(m:ss.ms)</small></th>
                    <th style="width:10%;">Total Time <small style="font-weight:400;">(h:mm:ss)</small></th>
                    <th style="width:9%;">Status</th>
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
                    <td style="font-size:12px;"><?php echo esc_html( $reg->vehicle_name ?: '—' ); ?></td>
                    <td style="font-size:12px;"><?php echo esc_html( $class_name ); ?></td>
                    <td>
                        <input type="number"
                               name="msc_results[<?php echo $reg->id; ?>][position]"
                               value="<?php echo esc_attr( $r->position ?? '' ); ?>"
                               min="1">
                    </td>
                    <td>
                        <input type="number"
                               name="msc_results[<?php echo $reg->id; ?>][laps_completed]"
                               value="<?php echo esc_attr( $r->laps_completed ?? '' ); ?>"
                               min="0">
                    </td>
                    <td>
                        <input type="text"
                               name="msc_results[<?php echo $reg->id; ?>][best_lap_time]"
                               value="<?php echo esc_attr( $r->best_lap_time ?? '' ); ?>"
                               placeholder="1:23.456">
                    </td>
                    <td>
                        <input type="text"
                               name="msc_results[<?php echo $reg->id; ?>][total_race_time]"
                               value="<?php echo esc_attr( $r->total_race_time ?? '' ); ?>"
                               placeholder="0:45:12">
                    </td>
                    <td>
                        <select name="msc_results[<?php echo $reg->id; ?>][status]">
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
                        <textarea name="msc_results[<?php echo $reg->id; ?>][notes]"
                                  placeholder="Optional…"><?php echo esc_textarea( $r->notes ?? '' ); ?></textarea>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else : ?>
        <p style="color:#888;font-style:italic;">No confirmed registrations found for this event.</p>
        <?php endif; ?>

        <!-- Manual Driver Entries -->
        <div class="msc-re-section">
            <h4>Manual Driver Entries</h4>
            <p style="color:#555;margin:0 0 8px;font-size:13px;">For drivers who aren't registered on the website.</p>

            <table class="widefat fixed striped msc-re-table">
                <thead>
                    <tr>
                        <th style="width:15%;">Driver Name</th>
                        <th style="width:14%;">Vehicle</th>
                        <th style="width:11%;">Class</th>
                        <th style="width:5%;">Pos</th>
                        <th style="width:5%;">Laps</th>
                        <th style="width:9%;">Best Lap</th>
                        <th style="width:9%;">Total Time</th>
                        <th style="width:8%;">Status</th>
                        <th>Notes</th>
                        <th style="width:30px;"></th>
                    </tr>
                </thead>
                <tbody id="msc-manual-tbody">
                <?php foreach ( $manual_results as $idx => $mr ) :
                    self::render_manual_row( $idx, $mr, $all_classes );
                endforeach; ?>
                </tbody>
            </table>
            <button type="button" id="msc-add-manual-driver" class="button" style="margin-top:8px;">+ Add Manual Driver</button>
        </div>

        <p class="description" style="margin-top:10px;">
            Results are sorted automatically: Finished entries by position, then DNF &rarr; DNS &rarr; DSQ.
        </p>

        <!-- Hidden template row for JS cloning -->
        <table style="display:none;"><tbody id="msc-manual-row-tpl">
            <?php self::render_manual_row( '__IDX__', null, $all_classes ); ?>
        </tbody></table>

        <script>
        (function($){
            var manualIdx = <?php echo (int) count( $manual_results ); ?>;

            $('#msc-add-manual-driver').on('click', function(){
                var tpl = $('#msc-manual-row-tpl').html().replace(/__IDX__/g, 'new_' + manualIdx);
                manualIdx++;
                $('#msc-manual-tbody').append(tpl);
            });

            $(document).on('click', '.msc-remove-manual-row', function(){
                $(this).closest('tr').remove();
            });
        })(jQuery);
        </script>
        <?php
    }

    private static function render_manual_row( $idx, $mr, $all_classes ) {
        $prefix     = "msc_results_manual[{$idx}]";
        $statuses   = [ 'Finished', 'DNF', 'DNS', 'DSQ' ];
        $cur_status = $mr ? ( $mr->status ?? 'Finished' ) : 'Finished';
        ?>
        <tr>
            <td>
                <input type="text"
                       name="<?php echo $prefix; ?>[driver_name]"
                       value="<?php echo esc_attr( $mr ? ( $mr->driver_name ?? '' ) : '' ); ?>"
                       placeholder="Full name">
            </td>
            <td>
                <input type="text"
                       name="<?php echo $prefix; ?>[manual_vehicle]"
                       value="<?php echo esc_attr( $mr ? ( $mr->manual_vehicle ?? '' ) : '' ); ?>"
                       placeholder="Make/Model">
            </td>
            <td>
                <select name="<?php echo $prefix; ?>[class_id]">
                    <option value="">— None —</option>
                    <?php foreach ( $all_classes as $cls ) :
                        $sel = ( $mr && (int) $mr->class_id === (int) $cls->term_id ) ? 'selected' : '';
                    ?>
                    <option value="<?php echo esc_attr( $cls->term_id ); ?>" <?php echo $sel; ?>>
                        <?php echo esc_html( $cls->name ); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td>
                <input type="number"
                       name="<?php echo $prefix; ?>[position]"
                       value="<?php echo esc_attr( $mr ? ( $mr->position ?? '' ) : '' ); ?>"
                       min="1">
            </td>
            <td>
                <input type="number"
                       name="<?php echo $prefix; ?>[laps_completed]"
                       value="<?php echo esc_attr( $mr ? ( $mr->laps_completed ?? '' ) : '' ); ?>"
                       min="0">
            </td>
            <td>
                <input type="text"
                       name="<?php echo $prefix; ?>[best_lap_time]"
                       value="<?php echo esc_attr( $mr ? ( $mr->best_lap_time ?? '' ) : '' ); ?>"
                       placeholder="1:23.456">
            </td>
            <td>
                <input type="text"
                       name="<?php echo $prefix; ?>[total_race_time]"
                       value="<?php echo esc_attr( $mr ? ( $mr->total_race_time ?? '' ) : '' ); ?>"
                       placeholder="0:45:12">
            </td>
            <td>
                <select name="<?php echo $prefix; ?>[status]">
                    <?php foreach ( $statuses as $s ) : ?>
                    <option value="<?php echo $s; ?>" <?php echo ( $cur_status === $s ) ? 'selected' : ''; ?>>
                        <?php echo $s; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td>
                <textarea name="<?php echo $prefix; ?>[notes]"
                          placeholder="Optional…"><?php echo esc_textarea( $mr ? ( $mr->notes ?? '' ) : '' ); ?></textarea>
            </td>
            <td>
                <button type="button" class="button-link msc-remove-manual-row"
                        style="color:#b32d2e;font-size:16px;line-height:1;" title="Remove">&times;</button>
            </td>
        </tr>
        <?php
    }

    public static function save_results( $post_id ) {
        global $wpdb;

        if (
            ! isset( $_POST['msc_save_results_nonce'] ) ||
            ! wp_verify_nonce( $_POST['msc_save_results_nonce'], 'msc_save_results_nonce' ) ||
            ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ||
            ! current_user_can( 'edit_post', $post_id )
        ) return;

        $res_table      = $wpdb->prefix . 'msc_event_results';
        $valid_statuses = [ 'Finished', 'DNF', 'DNS', 'DSQ' ];

        // ── Registered drivers ──────────────────────────────────────
        if ( ! empty( $_POST['msc_results'] ) ) {
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
                    'status'          => in_array( $data['status'] ?? '', $valid_statuses, true )
                                            ? $data['status'] : 'Finished',
                    'notes'           => sanitize_textarea_field( $data['notes'] ?? '' ) ?: null,
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

        // ── Manual drivers (delete-then-reinsert) ───────────────────
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM $res_table WHERE event_id = %d AND registration_id IS NULL",
            $post_id
        ) );

        if ( ! empty( $_POST['msc_results_manual'] ) ) {
            foreach ( $_POST['msc_results_manual'] as $data ) {
                $driver_name = sanitize_text_field( $data['driver_name'] ?? '' );
                if ( $driver_name === '' ) continue; // skip empty rows

                $row = [
                    'event_id'        => $post_id,
                    'registration_id' => null,
                    'driver_name'     => $driver_name,
                    'manual_vehicle'  => sanitize_text_field( $data['manual_vehicle'] ?? '' ) ?: null,
                    'class_id'        => ( isset( $data['class_id'] ) && $data['class_id'] !== '' )
                                            ? absint( $data['class_id'] ) : null,
                    'position'        => ( isset( $data['position'] ) && $data['position'] !== '' )
                                            ? absint( $data['position'] ) : null,
                    'laps_completed'  => ( isset( $data['laps_completed'] ) && $data['laps_completed'] !== '' )
                                            ? absint( $data['laps_completed'] ) : null,
                    'best_lap_time'   => sanitize_text_field( $data['best_lap_time']   ?? '' ) ?: null,
                    'total_race_time' => sanitize_text_field( $data['total_race_time'] ?? '' ) ?: null,
                    'status'          => in_array( $data['status'] ?? '', $valid_statuses, true )
                                            ? $data['status'] : 'Finished',
                    'notes'           => sanitize_textarea_field( $data['notes'] ?? '' ) ?: null,
                ];
                $formats = [ '%d', '%d', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s' ];

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
                    COALESCE(u.display_name, res.driver_name) AS member_name,
                    COALESCE(v.post_title,   res.manual_vehicle) AS vehicle_name,
                    COALESCE(reg.class_id,   res.class_id) AS resolved_class_id
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
                        if ( ! empty( $p->resolved_class_id ) ) {
                            $term = get_term( $p->resolved_class_id, 'msc_vehicle_class' );
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
                                if ( ! empty( $r->resolved_class_id ) ) {
                                    $term = get_term( $r->resolved_class_id, 'msc_vehicle_class' );
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
