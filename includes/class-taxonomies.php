<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class MSC_Taxonomies {

    public static function init() {
        add_action( 'init', array( __CLASS__, 'register' ) );

        // Term meta UI for vehicle type
        add_action( 'msc_vehicle_class_add_form_fields',  array( __CLASS__, 'add_term_fields' ) );
        add_action( 'msc_vehicle_class_edit_form_fields', array( __CLASS__, 'edit_term_fields' ), 10 );
        add_action( 'created_msc_vehicle_class',          array( __CLASS__, 'save_term_fields' ) );
        add_action( 'edited_msc_vehicle_class',           array( __CLASS__, 'save_term_fields' ) );

        // Add vehicle type column to term list table
        add_filter( 'manage_edit-msc_vehicle_class_columns',  array( __CLASS__, 'term_columns' ) );
        add_filter( 'manage_msc_vehicle_class_custom_column',  array( __CLASS__, 'term_column_data' ), 10, 3 );
    }

    public static function register() {
        register_taxonomy( 'msc_vehicle_class', array( 'msc_vehicle', 'msc_event' ), array(
            'labels'            => array(
                'name'          => 'Vehicle Classes',
                'singular_name' => 'Vehicle Class',
                'add_new_item'  => 'Add New Class',
                'edit_item'     => 'Edit Class',
            ),
            'hierarchical'      => false,
            'public'            => false,
            'show_ui'           => true,
            'show_in_menu'      => true,
            'show_admin_column' => true,
            'rewrite'           => false,
            'show_in_rest'      => true,
        ) );
    }

    // ── Term meta UI ──────────────────────────────────────────────────────

    public static function add_term_fields() {
        ?>
        <div class="form-field">
            <label for="msc_vehicle_type">Vehicle Type</label>
            <select name="msc_vehicle_type" id="msc_vehicle_type">
                <option value="Car">Car</option>
                <option value="Motorcycle">Motorcycle</option>
            </select>
            <p class="description">Which vehicle type does this class belong to?</p>
        </div>
        <div class="form-field">
            <label>Class Conditions</label>
            <div id="msc-conditions-builder-wrap" data-existing="[]">
                <input type="hidden" name="msc_class_conditions_json" id="msc-class-conditions-json" value="[]">
                <div id="msc-conditions-builder" style="margin-bottom:8px"></div>
                <button type="button" id="msc-add-condition" class="button">+ Add Condition</button>
            </div>
            <p class="description">Define conditions entrants must confirm or select when entering this class (e.g. tyre type, equipment declarations).</p>
        </div>
        <?php self::print_conditions_js(); ?>
        <?php
    }

    public static function edit_term_fields( $term ) {
        $type           = get_term_meta( $term->term_id, 'msc_vehicle_type', true ) ?: 'Car';
        $existing_conds = get_term_meta( $term->term_id, 'msc_class_conditions', true ) ?: '[]';
        ?>
        <tr class="form-field">
            <th scope="row"><label for="msc_vehicle_type">Vehicle Type</label></th>
            <td>
                <select name="msc_vehicle_type" id="msc_vehicle_type">
                    <option value="Car"        <?php selected( $type, 'Car' ); ?>>Car</option>
                    <option value="Motorcycle"  <?php selected( $type, 'Motorcycle' ); ?>>Motorcycle</option>
                </select>
                <p class="description">Which vehicle type does this class belong to?</p>
            </td>
        </tr>
        <tr class="form-field">
            <th scope="row"><label>Class Conditions</label></th>
            <td>
                <div id="msc-conditions-builder-wrap" data-existing="<?php echo esc_attr( $existing_conds ); ?>">
                    <input type="hidden" name="msc_class_conditions_json" id="msc-class-conditions-json" value="">
                    <div id="msc-conditions-builder" style="margin-bottom:8px"></div>
                    <button type="button" id="msc-add-condition" class="button">+ Add Condition</button>
                </div>
                <p class="description">Define conditions entrants must confirm or select when entering this class.</p>
            </td>
        </tr>
        <?php self::print_conditions_js(); ?>
        <?php
    }

    public static function save_term_fields( $term_id ) {
        if ( isset( $_POST['msc_vehicle_type'] ) ) {
            $allowed = array( 'Car', 'Motorcycle' );
            $type    = sanitize_text_field( wp_unslash( $_POST['msc_vehicle_type'] ) );
            if ( in_array( $type, $allowed, true ) ) {
                update_term_meta( $term_id, 'msc_vehicle_type', $type );
            }
        }

        if ( isset( $_POST['msc_class_conditions_json'] ) ) {
            $raw     = wp_unslash( $_POST['msc_class_conditions_json'] );
            $decoded = json_decode( $raw, true );
            if ( is_array( $decoded ) ) {
                $clean        = array();
                $valid_types  = array( 'confirm', 'select_one', 'select_many' );
                foreach ( $decoded as $cond ) {
                    if ( empty( $cond['label'] ) ) continue;
                    $ctype = isset( $cond['type'] ) && in_array( $cond['type'], $valid_types, true ) ? $cond['type'] : 'confirm';
                    $entry = array(
                        'type'  => $ctype,
                        'label' => sanitize_text_field( $cond['label'] ),
                    );
                    if ( in_array( $ctype, array( 'select_one', 'select_many' ), true ) ) {
                        if ( ! empty( $cond['options'] ) && is_array( $cond['options'] ) ) {
                            $opts = array_values( array_filter( array_map( 'sanitize_text_field', $cond['options'] ) ) );
                        } else {
                            $opts = array();
                        }
                        if ( empty( $opts ) ) continue; // require at least one option
                        $entry['options'] = $opts;
                    }
                    $clean[] = $entry;
                }
                if ( ! empty( $clean ) ) {
                    update_term_meta( $term_id, 'msc_class_conditions', wp_json_encode( $clean ) );
                } else {
                    delete_term_meta( $term_id, 'msc_class_conditions' );
                }
            } else {
                delete_term_meta( $term_id, 'msc_class_conditions' );
            }
        }
    }

    // ── Conditions JS repeater ────────────────────────────────────────────

    private static $conditions_js_printed = false;

    private static function print_conditions_js() {
        if ( self::$conditions_js_printed ) return;
        self::$conditions_js_printed = true;
        ?>
        <script>
        (function() {
            function escAttr(str) {
                return String(str).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
            }

            function makeOptItem(val) {
                var d = document.createElement('div');
                d.className = 'msc-opt-item';
                d.style.cssText = 'display:flex;gap:6px;margin-bottom:4px';
                d.innerHTML = '<input type="text" class="msc-opt-val" value="' + escAttr(val) + '" placeholder="Option text" style="flex:1;padding:4px 6px;border:1px solid #ddd;border-radius:3px"><button type="button" class="msc-rm-opt" style="background:none;border:none;color:#d63638;cursor:pointer;font-size:16px;line-height:1;padding:0 4px">✕</button>';
                return d;
            }

            function makeCondRow(cond) {
                var type    = (cond && cond.type)    || 'confirm';
                var label   = (cond && cond.label)   || '';
                var options = (cond && cond.options)  || [];

                var row = document.createElement('div');
                row.className = 'msc-cond-row';
                row.style.cssText = 'border:1px solid #ddd;padding:12px;margin-bottom:10px;border-radius:4px;background:#fafafa';

                var selHtml = '<select class="msc-cond-type" style="padding:3px 6px">'
                    + '<option value="confirm"'    + (type==='confirm'    ?' selected':'') + '>Confirm (single tick)</option>'
                    + '<option value="select_one"' + (type==='select_one' ?' selected':'') + '>Select one (radio)</option>'
                    + '<option value="select_many"'+ (type==='select_many'?' selected':'') + '>Select many (checkboxes)</option>'
                    + '</select>';

                row.innerHTML =
                    '<div style="display:flex;gap:8px;align-items:center;margin-bottom:8px">'
                    + '<strong style="flex:0 0 auto">Type:</strong>' + selHtml
                    + '<button type="button" class="msc-rm-cond" style="margin-left:auto;background:none;border:none;color:#d63638;cursor:pointer;font-weight:600">✕ Remove</button>'
                    + '</div>'
                    + '<div style="margin-bottom:8px">'
                    + '<label style="display:block;margin-bottom:3px;font-weight:600">Label / Question:</label>'
                    + '<input type="text" class="msc-cond-label" value="' + escAttr(label) + '" placeholder="e.g. Tyre specification" style="width:100%;padding:4px 6px;border:1px solid #ddd;border-radius:3px;box-sizing:border-box">'
                    + '</div>'
                    + '<div class="msc-opts-wrap" style="' + (type === 'confirm' ? 'display:none' : '') + '">'
                    + '<label style="display:block;margin-bottom:4px;font-weight:600">Options:</label>'
                    + '<div class="msc-opts-list"></div>'
                    + '<button type="button" class="msc-add-opt button button-small" style="margin-top:4px">+ Add Option</button>'
                    + '</div>';

                var optList = row.querySelector('.msc-opts-list');
                options.forEach(function(o) { optList.appendChild(makeOptItem(o)); });

                return row;
            }

            function serialize(builder, hiddenInput) {
                var data = [];
                builder.querySelectorAll('.msc-cond-row').forEach(function(row) {
                    var type  = row.querySelector('.msc-cond-type').value;
                    var label = row.querySelector('.msc-cond-label').value.trim();
                    if (!label) return;
                    var entry = {type: type, label: label};
                    if (type !== 'confirm') {
                        var opts = [];
                        row.querySelectorAll('.msc-opt-val').forEach(function(inp) {
                            var v = inp.value.trim();
                            if (v) opts.push(v);
                        });
                        if (!opts.length) return;
                        entry.options = opts;
                    }
                    data.push(entry);
                });
                hiddenInput.value = JSON.stringify(data);
            }

            document.addEventListener('DOMContentLoaded', function() {
                var wrap = document.getElementById('msc-conditions-builder-wrap');
                if (!wrap) return;

                var builder     = wrap.querySelector('#msc-conditions-builder');
                var addBtn      = wrap.querySelector('#msc-add-condition');
                var hiddenInput = wrap.querySelector('#msc-class-conditions-json');
                var existing    = [];
                try { existing = JSON.parse(wrap.getAttribute('data-existing') || '[]') || []; } catch(e) {}

                existing.forEach(function(cond) { builder.appendChild(makeCondRow(cond)); });

                addBtn.addEventListener('click', function() {
                    builder.appendChild(makeCondRow(null));
                });

                builder.addEventListener('click', function(e) {
                    var el = e.target;
                    if (el.classList.contains('msc-rm-cond')) {
                        el.closest('.msc-cond-row').remove();
                    } else if (el.classList.contains('msc-add-opt')) {
                        el.closest('.msc-cond-row').querySelector('.msc-opts-list').appendChild(makeOptItem(''));
                    } else if (el.classList.contains('msc-rm-opt')) {
                        el.closest('.msc-opt-item').remove();
                    }
                });

                builder.addEventListener('change', function(e) {
                    if (e.target.classList.contains('msc-cond-type')) {
                        var optsWrap = e.target.closest('.msc-cond-row').querySelector('.msc-opts-wrap');
                        optsWrap.style.display = e.target.value === 'confirm' ? 'none' : '';
                    }
                });

                var form = wrap.closest('form');
                if (form) {
                    form.addEventListener('submit', function() { serialize(builder, hiddenInput); });
                }
            });
        })();
        </script>
        <?php
    }

    // ── Term list columns ─────────────────────────────────────────────────

    public static function term_columns( $columns ) {
        $new = array();
        foreach ( $columns as $k => $v ) {
            $new[ $k ] = $v;
            if ( $k === 'name' ) {
                $new['vehicle_type'] = 'Vehicle Type';
            }
        }
        return $new;
    }

    public static function term_column_data( $content, $column, $term_id ) {
        if ( $column === 'vehicle_type' ) {
            $type = get_term_meta( $term_id, 'msc_vehicle_type', true );
            return $type ? esc_html( $type ) : '—';
        }
        return $content;
    }

    // ── Public API ────────────────────────────────────────────────────────

    /** Return all vehicle classes as id => name array */
    public static function get_all_classes() {
        $terms = get_terms( array( 'taxonomy' => 'msc_vehicle_class', 'hide_empty' => false ) );
        $out   = array();
        if ( ! is_wp_error( $terms ) ) {
            foreach ( $terms as $t ) {
                $out[ $t->term_id ] = $t->name;
            }
        }
        return $out;
    }

    /** Return class IDs assigned to an event */
    public static function get_event_classes( $event_id ) {
        $terms = wp_get_post_terms( $event_id, 'msc_vehicle_class', array( 'fields' => 'ids' ) );
        return is_array( $terms ) ? $terms : array();
    }

    /** Return supported vehicle types (derived from term meta) */
    public static function get_vehicle_types() {
        return array( 'Car', 'Motorcycle' );
    }

    /** Return classes grouped by vehicle type: array( 'Car' => [...], 'Motorcycle' => [...] ) */
    public static function get_classes_by_type() {
        $types  = self::get_vehicle_types();
        $result = array();
        foreach ( $types as $type ) {
            $result[ $type ] = array();
        }

        $terms = get_terms( array( 'taxonomy' => 'msc_vehicle_class', 'hide_empty' => false ) );
        if ( is_wp_error( $terms ) ) return $result;

        foreach ( $terms as $term ) {
            $type = get_term_meta( $term->term_id, 'msc_vehicle_type', true ) ?: 'Car';
            if ( ! isset( $result[ $type ] ) ) {
                $result[ $type ] = array();
            }
            $result[ $type ][ $term->term_id ] = $term->name;
        }

        return $result;
    }
}
