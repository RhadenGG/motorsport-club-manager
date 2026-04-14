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
            KEY event_id (event_id)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        // Explicit column migrations — dbDelta is unreliable for existing tables.
        $existing_cols = $wpdb->get_col( "DESCRIBE $table", 0 );

        foreach ( array( 'driver_name', 'manual_vehicle', 'class_id' ) as $col ) {
            if ( ! in_array( $col, $existing_cols, true ) ) {
                $wpdb->query( "ALTER TABLE $table ADD COLUMN $col " . (
                    $col === 'class_id' ? 'BIGINT UNSIGNED DEFAULT NULL' : 'VARCHAR(150) DEFAULT NULL'
                ) );
            }
        }

        // Make registration_id nullable for existing installs
        $col = $wpdb->get_row( $wpdb->prepare(
            "SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'registration_id'",
            DB_NAME, $table
        ) );
        if ( $col && $col->IS_NULLABLE === 'NO' ) {
            $wpdb->query( "ALTER TABLE $table MODIFY COLUMN registration_id BIGINT UNSIGNED DEFAULT NULL" );
        }

        // Drop the old single-registration unique key — multi-class allows one reg to have multiple result rows.
        $old_key = $wpdb->get_results( "SHOW INDEX FROM $table WHERE Key_name = 'event_reg'" );
        if ( ! empty( $old_key ) ) {
            $wpdb->query( "ALTER TABLE $table DROP INDEX event_reg" );
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

        if ( get_option( 'msc_results_enabled', 1 ) ) {
            add_meta_box(
                'msc_results_box',
                '🏆 Event Results',
                array( __CLASS__, 'render_results_box' ),
                'msc_event',
                'normal',
                'default'
            );
        }
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
            $allowed = array( 'open', 'closed' );
            $status  = sanitize_text_field( $_POST['msc_event_status'] );
            if ( in_array( $status, $allowed, true ) ) {
                update_post_meta( $post_id, '_msc_event_status', $status );
            }
        }
    }

    public static function is_closed( $event_id ) {
        return get_post_meta( $event_id, '_msc_event_status', true ) === 'closed';
    }

    // ─────────────────────────────────────────────────────────────────
    // 3. RESULTS ENTRY META BOX  (normal area) — tabbed per class
    // ─────────────────────────────────────────────────────────────────

    public static function render_results_box( $post ) {
        global $wpdb;

        $event_id  = $post->ID;
        $reg_table = $wpdb->prefix . 'msc_registrations';
        $rc_table  = $wpdb->prefix . 'msc_registration_classes';
        $res_table = $wpdb->prefix . 'msc_event_results';

        // Event's allowed classes
        $class_ids = get_post_meta( $event_id, '_msc_event_classes', true );
        $class_ids = $class_ids ? array_map( 'intval', (array) $class_ids ) : array();

        if ( empty( $class_ids ) ) {
            echo '<p style="color:#888;font-style:italic;">No vehicle classes are assigned to this event. Edit the event and select at least one class.</p>';
            return;
        }

        // Registered drivers per class (from junction table)
        $registrations_by_class = array();
        if ( ! empty( $class_ids ) ) {
            $ph   = implode( ',', array_fill( 0, count( $class_ids ), '%d' ) );
            $args = array_merge( array( $event_id ), $class_ids );
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT rc.class_id, rc.vehicle_id, r.id AS reg_id,
                        u.display_name AS member_name
                 FROM $rc_table rc
                 JOIN $reg_table r ON r.id = rc.registration_id
                 LEFT JOIN {$wpdb->users} u ON u.ID = r.user_id
                 WHERE r.event_id = %d
                   AND r.status NOT IN ('rejected','cancelled')
                   AND rc.class_id IN ($ph)
                 ORDER BY rc.class_id, u.display_name ASC",
                $args
            ) );
            foreach ( $rows as $row ) {
                $row->vehicle_name = MSC_Registration::format_vehicle_label( (int) $row->vehicle_id );
                $registrations_by_class[ intval( $row->class_id ) ][] = $row;
            }
        }

        // Existing results
        $existing_rows   = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $res_table WHERE event_id = %d", $event_id
        ) );
        $results_by_rc   = array(); // [class_id][reg_id] = row
        $manual_by_class = array(); // [class_id][]       = row
        foreach ( $existing_rows as $row ) {
            if ( $row->registration_id ) {
                $results_by_rc[ intval( $row->class_id ) ][ intval( $row->registration_id ) ] = $row;
            } else {
                $manual_by_class[ intval( $row->class_id ) ][] = $row;
            }
        }

        if ( ! self::is_closed( $event_id ) ) {
            echo '<div style="background:#fff3cd;border-left:4px solid #ffc107;padding:10px 14px;margin-bottom:14px;border-radius:4px;">
                    ⚠️ <strong>Event is still Open.</strong> Set the Event Status to <em>Closed</em> (sidebar) before entering results.
                  </div>';
        }

        wp_nonce_field( 'msc_save_results_nonce', 'msc_save_results_nonce' );
        ?>

        <style>
        .msc-class-tabs { display:flex; gap:0; border-bottom:2px solid #ddd; margin-bottom:16px; flex-wrap:wrap; }
        .msc-class-tab  { padding:8px 16px; cursor:pointer; border:1px solid transparent; border-bottom:none;
                          background:#f0f0f0; font-weight:600; font-size:13px; border-radius:4px 4px 0 0; margin-right:4px; }
        .msc-class-tab.active { background:#fff; border-color:#ddd; border-bottom-color:#fff; position:relative; top:2px; }
        .msc-class-tab-panel { display:none; }
        .msc-class-tab-panel.active { display:block; }
        #msc_results_box .msc-re-table td,
        #msc_results_box .msc-re-table th { padding: 4px 6px; vertical-align: middle; }
        #msc_results_box .msc-re-table input[type="number"],
        #msc_results_box .msc-re-table input[type="text"],
        #msc_results_box .msc-re-table select { width: 100%; box-sizing: border-box; }
        #msc_results_box .msc-re-table textarea { width: 100%; height: 44px; resize: vertical; box-sizing: border-box; font-family: inherit; font-size: 13px; }
        #msc_results_box .msc-re-section { margin-top: 20px; }
        #msc_results_box .msc-re-section h4 { margin: 0 0 6px; font-size: 14px; }
        </style>

        <p style="color:#555;margin-top:0;">Select a class tab to enter results. Leave Position blank for DNF/DNS/DSQ entries.</p>

        <!-- Tab navigation -->
        <div class="msc-class-tabs">
        <?php foreach ( $class_ids as $i => $cid ) :
            $term = get_term( $cid, 'msc_vehicle_class' );
            $label = ( $term && ! is_wp_error( $term ) ) ? $term->name : 'Class #' . $cid;
        ?>
            <div class="msc-class-tab <?php echo $i === 0 ? 'active' : ''; ?>"
                 data-panel="msc-res-panel-<?php echo $cid; ?>">
                <?php echo esc_html( $label ); ?>
            </div>
        <?php endforeach; ?>
        </div>

        <!-- Tab panels -->
        <?php foreach ( $class_ids as $i => $cid ) :
            $term = get_term( $cid, 'msc_vehicle_class' );
            $label      = ( $term && ! is_wp_error( $term ) ) ? $term->name : 'Class #' . $cid;
            $class_regs = $registrations_by_class[ $cid ] ?? array();
            $class_mans = $manual_by_class[ $cid ] ?? array();
        ?>
        <div class="msc-class-tab-panel <?php echo $i === 0 ? 'active' : ''; ?>"
             id="msc-res-panel-<?php echo $cid; ?>">

            <?php if ( ! empty( $class_regs ) ) : ?>
            <table class="widefat fixed striped msc-re-table">
                <thead>
                    <tr>
                        <th style="width:18%;">Driver</th>
                        <th style="width:16%;">Vehicle</th>
                        <th style="width:5%;">Pos</th>
                        <th style="width:5%;">Laps</th>
                        <th style="width:10%;">Best Lap <small style="font-weight:400;">(m:ss.ms)</small></th>
                        <th style="width:10%;">Total Time <small style="font-weight:400;">(h:mm:ss)</small></th>
                        <th style="width:9%;">Status</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $class_regs as $reg ) :
                    $r   = $results_by_rc[ $cid ][ $reg->reg_id ] ?? null;
                    $key = $cid . '_' . $reg->reg_id;
                ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html( $reg->member_name ); ?></strong>
                            <input type="hidden" name="msc_results[<?php echo $key; ?>][registration_id]" value="<?php echo $reg->reg_id; ?>">
                            <input type="hidden" name="msc_results[<?php echo $key; ?>][class_id]" value="<?php echo $cid; ?>">
                        </td>
                        <td style="font-size:12px;"><?php echo esc_html( $reg->vehicle_name ?: '—' ); ?></td>
                        <td>
                            <input type="number" name="msc_results[<?php echo $key; ?>][position]"
                                   value="<?php echo esc_attr( $r->position ?? '' ); ?>" min="1">
                        </td>
                        <td>
                            <input type="number" name="msc_results[<?php echo $key; ?>][laps_completed]"
                                   value="<?php echo esc_attr( $r->laps_completed ?? '' ); ?>" min="0">
                        </td>
                        <td>
                            <input type="text" name="msc_results[<?php echo $key; ?>][best_lap_time]"
                                   value="<?php echo esc_attr( $r->best_lap_time ?? '' ); ?>" placeholder="1:23.456">
                        </td>
                        <td>
                            <input type="text" name="msc_results[<?php echo $key; ?>][total_race_time]"
                                   value="<?php echo esc_attr( $r->total_race_time ?? '' ); ?>" placeholder="0:45:12">
                        </td>
                        <td>
                            <select name="msc_results[<?php echo $key; ?>][status]">
                                <?php
                                $cur = $r->status ?? 'Finished';
                                foreach ( array( 'Finished', 'DNF', 'DNS', 'DSQ' ) as $s ) {
                                    printf( '<option value="%s"%s>%s</option>', $s, selected( $cur, $s, false ), $s );
                                }
                                ?>
                            </select>
                        </td>
                        <td>
                            <textarea name="msc_results[<?php echo $key; ?>][notes]"
                                      placeholder="Optional…"><?php echo esc_textarea( $r->notes ?? '' ); ?></textarea>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php else : ?>
            <p style="color:#888;font-style:italic;margin-top:0;">No confirmed registrations for this class.</p>
            <?php endif; ?>

            <!-- Manual entries for this class -->
            <div class="msc-re-section">
                <h4>Manual Driver Entries — <?php echo esc_html( $label ); ?></h4>
                <p style="color:#555;margin:0 0 8px;font-size:13px;">For drivers who aren't registered on the website.</p>
                <table class="widefat fixed striped msc-re-table">
                    <thead>
                        <tr>
                            <th style="width:17%;">Driver Name</th>
                            <th style="width:14%;">Vehicle</th>
                            <th style="width:5%;">Pos</th>
                            <th style="width:5%;">Laps</th>
                            <th style="width:9%;">Best Lap</th>
                            <th style="width:9%;">Total Time</th>
                            <th style="width:8%;">Status</th>
                            <th>Notes</th>
                            <th style="width:30px;"></th>
                        </tr>
                    </thead>
                    <tbody id="msc-manual-tbody-<?php echo $cid; ?>">
                    <?php foreach ( $class_mans as $idx => $mr ) :
                        self::render_manual_row( $cid, $idx, $mr );
                    endforeach; ?>
                    </tbody>
                </table>
                <button type="button" class="button msc-add-manual-driver" style="margin-top:8px;"
                        data-class="<?php echo $cid; ?>">+ Add Manual Driver</button>
            </div>

        </div><!-- .msc-class-tab-panel -->
        <?php endforeach; ?>

        <!-- Hidden template rows (one per class) for JS cloning -->
        <?php foreach ( $class_ids as $cid ) : ?>
        <table style="display:none;"><tbody id="msc-manual-row-tpl-<?php echo $cid; ?>">
            <?php self::render_manual_row( $cid, '__IDX__', null ); ?>
        </tbody></table>
        <?php endforeach; ?>

        <p class="description" style="margin-top:12px;">
            Results sorted automatically: Finished by position, then DNF &rarr; DNS &rarr; DSQ.
        </p>

        <script>
        (function($){
            // Tab switching
            $('.msc-class-tab').on('click', function(){
                var panelId = $(this).data('panel');
                $('.msc-class-tab').removeClass('active');
                $('.msc-class-tab-panel').removeClass('active');
                $(this).addClass('active');
                $('#' + panelId).addClass('active');
            });

            // Manual driver counts per class
            var manualIdx = {};
            <?php foreach ( $class_ids as $cid ) : ?>
            manualIdx[<?php echo $cid; ?>] = <?php echo count( $manual_by_class[ $cid ] ?? array() ); ?>;
            <?php endforeach; ?>

            $(document).on('click', '.msc-add-manual-driver', function(){
                var cid = $(this).data('class');
                var tpl = $('#msc-manual-row-tpl-' + cid).html()
                              .replace(/__IDX__/g, 'new_' + manualIdx[cid]);
                manualIdx[cid]++;
                $('#msc-manual-tbody-' + cid).append(tpl);
            });

            $(document).on('click', '.msc-remove-manual-row', function(){
                $(this).closest('tr').remove();
            });
        })(jQuery);
        </script>
        <?php
    }

    private static function render_manual_row( $class_id, $idx, $mr ) {
        $prefix   = "msc_results_manual[{$class_id}][{$idx}]";
        $statuses = array( 'Finished', 'DNF', 'DNS', 'DSQ' );
        $cur      = $mr ? ( $mr->status ?? 'Finished' ) : 'Finished';
        ?>
        <tr>
            <td>
                <input type="text" name="<?php echo $prefix; ?>[driver_name]"
                       value="<?php echo esc_attr( $mr ? ( $mr->driver_name ?? '' ) : '' ); ?>"
                       placeholder="Full name">
                <input type="hidden" name="<?php echo $prefix; ?>[class_id]" value="<?php echo $class_id; ?>">
            </td>
            <td>
                <input type="text" name="<?php echo $prefix; ?>[manual_vehicle]"
                       value="<?php echo esc_attr( $mr ? ( $mr->manual_vehicle ?? '' ) : '' ); ?>"
                       placeholder="Make/Model">
            </td>
            <td>
                <input type="number" name="<?php echo $prefix; ?>[position]"
                       value="<?php echo esc_attr( $mr ? ( $mr->position ?? '' ) : '' ); ?>" min="1">
            </td>
            <td>
                <input type="number" name="<?php echo $prefix; ?>[laps_completed]"
                       value="<?php echo esc_attr( $mr ? ( $mr->laps_completed ?? '' ) : '' ); ?>" min="0">
            </td>
            <td>
                <input type="text" name="<?php echo $prefix; ?>[best_lap_time]"
                       value="<?php echo esc_attr( $mr ? ( $mr->best_lap_time ?? '' ) : '' ); ?>"
                       placeholder="1:23.456">
            </td>
            <td>
                <input type="text" name="<?php echo $prefix; ?>[total_race_time]"
                       value="<?php echo esc_attr( $mr ? ( $mr->total_race_time ?? '' ) : '' ); ?>"
                       placeholder="0:45:12">
            </td>
            <td>
                <select name="<?php echo $prefix; ?>[status]">
                    <?php foreach ( $statuses as $s ) : ?>
                    <option value="<?php echo $s; ?>" <?php echo ( $cur === $s ) ? 'selected' : ''; ?>>
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

    // ─────────────────────────────────────────────────────────────────
    // 4. SAVE RESULTS
    // ─────────────────────────────────────────────────────────────────

    public static function save_results( $post_id ) {
        global $wpdb;

        if (
            ! isset( $_POST['msc_save_results_nonce'] ) ||
            ! wp_verify_nonce( $_POST['msc_save_results_nonce'], 'msc_save_results_nonce' ) ||
            ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ||
            ! current_user_can( 'edit_post', $post_id )
        ) return;

        $res_table      = $wpdb->prefix . 'msc_event_results';
        $valid_statuses = array( 'Finished', 'DNF', 'DNS', 'DSQ' );

        // ── Registered drivers (keyed as {class_id}_{reg_id}) ────────
        if ( ! empty( $_POST['msc_results'] ) ) {
            foreach ( $_POST['msc_results'] as $data ) {
                $reg_id   = absint( $data['registration_id'] ?? 0 );
                $class_id = absint( $data['class_id']        ?? 0 );
                if ( ! $reg_id || ! $class_id ) continue;

                $row = array(
                    'event_id'        => $post_id,
                    'registration_id' => $reg_id,
                    'class_id'        => $class_id,
                    'position'        => ( isset( $data['position'] ) && $data['position'] !== '' ) ? absint( $data['position'] ) : null,
                    'laps_completed'  => ( isset( $data['laps_completed'] ) && $data['laps_completed'] !== '' ) ? absint( $data['laps_completed'] ) : null,
                    'best_lap_time'   => sanitize_text_field( $data['best_lap_time']   ?? '' ) ?: null,
                    'total_race_time' => sanitize_text_field( $data['total_race_time'] ?? '' ) ?: null,
                    'status'          => in_array( $data['status'] ?? '', $valid_statuses, true ) ? $data['status'] : 'Finished',
                    'notes'           => sanitize_textarea_field( $data['notes'] ?? '' ) ?: null,
                );
                $formats = array( '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s' );

                $existing = $wpdb->get_var( $wpdb->prepare(
                    "SELECT id FROM $res_table WHERE event_id = %d AND registration_id = %d AND class_id = %d",
                    $post_id, $reg_id, $class_id
                ) );

                if ( $existing ) {
                    $wpdb->update( $res_table, $row, array( 'id' => $existing ), $formats, array( '%d' ) );
                } else {
                    $wpdb->insert( $res_table, $row, $formats );
                }
            }
        }

        // ── Manual drivers — delete all then reinsert ────────────────
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM $res_table WHERE event_id = %d AND registration_id IS NULL",
            $post_id
        ) );

        if ( ! empty( $_POST['msc_results_manual'] ) ) {
            foreach ( $_POST['msc_results_manual'] as $raw_class_id => $entries ) {
                $class_id = absint( $raw_class_id );
                if ( ! $class_id ) continue;
                foreach ( $entries as $data ) {
                    $driver_name = sanitize_text_field( $data['driver_name'] ?? '' );
                    if ( $driver_name === '' ) continue;

                    $row = array(
                        'event_id'        => $post_id,
                        'registration_id' => null,
                        'driver_name'     => $driver_name,
                        'manual_vehicle'  => sanitize_text_field( $data['manual_vehicle'] ?? '' ) ?: null,
                        'class_id'        => $class_id,
                        'position'        => ( isset( $data['position'] ) && $data['position'] !== '' ) ? absint( $data['position'] ) : null,
                        'laps_completed'  => ( isset( $data['laps_completed'] ) && $data['laps_completed'] !== '' ) ? absint( $data['laps_completed'] ) : null,
                        'best_lap_time'   => sanitize_text_field( $data['best_lap_time']   ?? '' ) ?: null,
                        'total_race_time' => sanitize_text_field( $data['total_race_time'] ?? '' ) ?: null,
                        'status'          => in_array( $data['status'] ?? '', $valid_statuses, true ) ? $data['status'] : 'Finished',
                        'notes'           => sanitize_textarea_field( $data['notes'] ?? '' ) ?: null,
                    );
                    $wpdb->insert( $res_table, $row,
                        array( '%d', '%d', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s' )
                    );
                }
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // 5. FRONTEND HTML — grouped by class with per-class podium
    // ─────────────────────────────────────────────────────────────────

    public static function get_results_html( $event_id ) {
        global $wpdb;

        $res_table = $wpdb->prefix . 'msc_event_results';
        $reg_table = $wpdb->prefix . 'msc_registrations';

        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT res.*,
                    reg.vehicle_id,
                    COALESCE(u.display_name, res.driver_name) AS member_name,
                    res.manual_vehicle AS vehicle_name
             FROM $res_table res
             LEFT JOIN $reg_table       reg ON reg.id = res.registration_id
             LEFT JOIN {$wpdb->users}   u   ON u.ID   = reg.user_id
             WHERE res.event_id = %d",
            $event_id
        ) );

        if ( empty( $results ) ) return '';

        // Group by class; format registered vehicle names from meta
        $by_class = array();
        foreach ( $results as $r ) {
            if ( $r->registration_id && $r->vehicle_id ) {
                $r->vehicle_name = MSC_Registration::format_vehicle_label( (int) $r->vehicle_id );
            }
            $by_class[ intval( $r->class_id ) ][] = $r;
        }

        // Sort each class: Finished by position → DNF → DNS → DSQ
        $status_order = array( 'Finished' => 0, 'DNF' => 1, 'DNS' => 2, 'DSQ' => 3 );
        foreach ( $by_class as &$rows ) {
            usort( $rows, function ( $a, $b ) use ( $status_order ) {
                $oa = $status_order[ $a->status ] ?? 4;
                $ob = $status_order[ $b->status ] ?? 4;
                if ( $oa !== $ob ) return $oa <=> $ob;
                if ( $a->status === 'Finished' ) return intval( $a->position ) <=> intval( $b->position );
                return intval( $b->laps_completed ) <=> intval( $a->laps_completed );
            } );
        }
        unset( $rows );

        // Use event class order where available
        $event_class_ids = get_post_meta( $event_id, '_msc_event_classes', true );
        $ordered         = $event_class_ids ? array_map( 'intval', (array) $event_class_ids ) : array_keys( $by_class );

        $badge_color = array(
            'Finished' => '#2ecc71',
            'DNF'      => '#e67e22',
            'DNS'      => '#95a5a6',
            'DSQ'      => '#e74c3c',
        );

        ob_start();
        ?>
        <div class="msc-results-wrap">
            <h2 class="msc-results-title">🏆 Race Results</h2>

            <?php foreach ( $ordered as $cid ) :
                if ( ! isset( $by_class[ $cid ] ) ) continue;
                $class_results = $by_class[ $cid ];
                $term          = get_term( $cid, 'msc_vehicle_class' );
                $class_name    = ( $term && ! is_wp_error( $term ) ) ? $term->name : 'Class #' . $cid;

                $finishers = array_values( array_filter( $class_results, function( $r ) { return $r->status === 'Finished' && $r->position; } ) );
                $podium    = array_slice( $finishers, 0, 3 );
                $medals    = array( '🥇', '🥈', '🥉' );
            ?>
            <div class="msc-class-results-section">
                <h3 class="msc-class-results-heading">
                    <span class="msc-class-badge-heading"><?php echo esc_html( $class_name ); ?></span>
                </h3>

                <?php if ( ! empty( $podium ) ) : ?>
                <div class="msc-podium">
                    <?php foreach ( $podium as $i => $p ) : ?>
                    <div class="msc-podium-card">
                        <div class="msc-podium-medal"><?php echo $medals[ $i ]; ?></div>
                        <div class="msc-podium-name"><?php echo esc_html( $p->member_name ); ?></div>
                        <div class="msc-podium-vehicle"><?php echo esc_html( $p->vehicle_name ?: '—' ); ?></div>
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

                <div class="msc-results-table-wrap">
                    <table class="msc-results-table">
                        <thead>
                            <tr>
                                <th class="msc-col-pos">Pos</th>
                                <th class="msc-col-driver">Driver</th>
                                <th class="msc-col-vehicle">Vehicle</th>
                                <th class="msc-col-status">Status</th>
                                <th class="msc-col-laps">Laps</th>
                                <th class="msc-col-time">Best Lap</th>
                                <th class="msc-col-time">Total Time</th>
                                <th class="msc-col-notes">Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ( $class_results as $i => $r ) :
                            $bc        = $badge_color[ $r->status ] ?? '#aaa';
                            $row_class = $i % 2 === 0 ? 'msc-row-even' : 'msc-row-odd';
                        ?>
                            <tr class="<?php echo $row_class; ?>">
                                <td class="msc-col-pos"><strong><?php echo $r->position ? esc_html( $r->position ) : '—'; ?></strong></td>
                                <td class="msc-col-driver"><?php echo esc_html( $r->member_name ); ?></td>
                                <td class="msc-col-vehicle"><?php echo esc_html( $r->vehicle_name ?: '—' ); ?></td>
                                <td class="msc-col-status">
                                    <span class="msc-result-badge" style="background:<?php echo $bc; ?>;">
                                        <?php echo esc_html( $r->status ); ?>
                                    </span>
                                </td>
                                <td class="msc-col-laps"><?php echo $r->laps_completed !== null ? esc_html( $r->laps_completed ) : '—'; ?></td>
                                <td class="msc-col-time msc-mono"><?php echo $r->best_lap_time ? esc_html( $r->best_lap_time ) : '—'; ?></td>
                                <td class="msc-col-time msc-mono"><?php echo $r->total_race_time ? esc_html( $r->total_race_time ) : '—'; ?></td>
                                <td class="msc-col-notes"><?php echo $r->notes ? esc_html( $r->notes ) : ''; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div><!-- .msc-class-results-section -->
            <?php endforeach; ?>

        </div>
        <?php
        return ob_get_clean();
    }
}
