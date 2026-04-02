/* Motorsport Club — Frontend JS */
jQuery(function ($) {

    var photoFile = null;
    var editPhotoFiles = {};

    var msc = {
        eventId:        null,
        sigPad:         null,
        parentSigPad:   null,
        sigType:        'draw',
        parentSigType:  'draw',
        totalFee:            0,
        allVehicles:         [],  // all eligible vehicles from server
        primaryClassId:      null,
        primaryVehicleId:    null,
        additionalRows:      [],  // array of {classId, vehicleId, rowId}
        rowCounter:          0,
        vehicleCompNumbers:  {},  // vehicle_id => comp_number (stored or user-entered)

        init: function () {
            msc.eventId = $('#msc-reg-wrap').data('event');
            if (msc.eventId) {
                msc.loadVehicles();
                msc.bindRegistration();
            }
            msc.bindGarage();
            msc.bindAccount();
        },

        // ─── Vehicle loading ──────────────────────────────────────────
        loadVehicles: function () {
            $.post(mscData.ajaxUrl, {
                action:   'msc_get_vehicles',
                nonce:    mscData.nonce,
                event_id: msc.eventId
            }, function (res) {
                $('#msc-vehicles-loading').hide();
                if (!res.success) {
                    $('#msc-vehicles-empty').show();
                    return;
                }
                var total    = res.data.total_user_vehicles || 0;
                var vehicles = res.data.vehicles || [];

                if (total === 0) {
                    $('#msc-vehicles-none-at-all').show();
                    return;
                }
                if (!vehicles.length) {
                    $('#msc-vehicles-empty').show();
                    return;
                }
                msc.allVehicles = vehicles;
                $('#msc-class-vehicle-wrap').show();
                msc.renderPrimaryClassOptions();
            });
        },

        // ─── Registration flow ────────────────────────────────────────
        bindRegistration: function () {

            var $wrap   = $('#msc-reg-wrap');
            var baseFee = parseFloat($wrap.data('base-fee')) || 0;
            var classes = $wrap.data('classes') || [];  // [{id, name, vtype, primary_fee, additional_fee}]

            // Populate the primary class dropdown, filtered to classes whose vtype
            // matches at least one of the user's eligible vehicles
            msc.renderPrimaryClassOptions = function() {
                var availableVtypes = {};
                msc.allVehicles.forEach(function(v) { availableVtypes[v.type] = true; });

                var $sel = $('#msc-primary-class-select');
                $sel.find('option:not(:first), optgroup').remove();

                // Group compatible classes by vtype
                var groups = {}, groupOrder = [];
                classes.forEach(function(cls) {
                    if (cls.vtype && !availableVtypes[cls.vtype]) return;
                    var g = cls.vtype || '';
                    if (!groups[g]) { groups[g] = []; groupOrder.push(g); }
                    groups[g].push(cls);
                });

                if (groupOrder.length > 1) {
                    groupOrder.forEach(function(g) {
                        var $grp = $('<optgroup>').attr('label', g || 'Other');
                        groups[g].forEach(function(cls) {
                            $grp.append($('<option>').val(cls.id).text(cls.name));
                        });
                        $sel.append($grp);
                    });
                } else {
                    (groups[groupOrder[0]] || []).forEach(function(cls) {
                        $sel.append($('<option>').val(cls.id).text(cls.name));
                    });
                }
            };

            // Helper: get vehicles compatible with a given class vtype
            function getVehiclesForClass(classId) {
                var cls = classes.find(function(c) { return c.id == classId; });
                var vtype = cls ? cls.vtype : '';
                return msc.allVehicles.filter(function(v) {
                    return !vtype || v.type === vtype;
                });
            }

            // Populate a vehicle select with vehicles compatible with a class
            function populateVehicleSelect($sel, classId, selectedVid) {
                var compatible = getVehiclesForClass(classId);
                $sel.find('option:not(:first)').remove();
                compatible.forEach(function(v) {
                    var $opt = $('<option>').val(v.id).text(v.label);
                    if (selectedVid && v.id == selectedVid) $opt.prop('selected', true);
                    $sel.append($opt);
                });
                if (compatible.length === 1) {
                    $sel.val(compatible[0].id).trigger('change');
                }
            }

            // Primary class change
            $('#msc-primary-class-select').on('change', function() {
                var classId = $(this).val();
                msc.primaryClassId = classId ? parseInt(classId) : null;
                msc.primaryVehicleId = null;
                $('#msc-primary-vehicle-select').val('');
                $('#msc-primary-comp-wrap').hide();
                if (!classId) {
                    $('#msc-primary-vehicle-wrap').hide();
                    $('#msc-additional-classes-wrap').hide();
                    msc.updateFees();
                    msc.renderClassConditions();
                    msc.checkStep1();
                    return;
                }
                $('#msc-additional-classes-wrap').hide();
                populateVehicleSelect($('#msc-primary-vehicle-select'), classId, null);
                $('#msc-primary-vehicle-wrap').show();
                msc.updateFees();
                msc.renderClassConditions();
                msc.checkStep1();
            });

            // Primary vehicle change
            $('#msc-primary-vehicle-select').on('change', function() {
                var vid = $(this).val();
                msc.primaryVehicleId = vid ? parseInt(vid) : null;
                // Always show comp number field, pre-filled with stored value if any
                if (msc.primaryVehicleId) {
                    var veh = msc.allVehicles.find(function(v) { return v.id == msc.primaryVehicleId; });
                    var stored = veh ? (veh.comp_number || '') : '';
                    msc.vehicleCompNumbers[msc.primaryVehicleId] = stored;
                    $('#msc-primary-comp-input').val(stored).attr('data-vehicle-id', msc.primaryVehicleId);
                    $('#msc-primary-comp-wrap').show();
                    // Show additional classes wrap only if there are other classes that ARE NOT primary_only
                    var canAddMore = classes.some(function(cls) {
                        return !cls.primary_only && cls.id != msc.primaryClassId;
                    });
                    if (canAddMore) {
                        $('#msc-additional-classes-wrap').show();
                    } else {
                        $('#msc-additional-classes-wrap').hide();
                    }
                } else {
                    $('#msc-primary-comp-wrap').hide();
                    $('#msc-additional-classes-wrap').hide();
                }
                msc.updateFees();
                msc.renderClassConditions();
                msc.checkStep1();
            });

            // Add additional class row
            $('#msc-add-class-btn').on('click', function() {
                var rowId = ++msc.rowCounter;
                var $row  = $('<div>').addClass('msc-additional-row').attr('id', 'msc-add-row-' + rowId);
                $row.css({display:'flex', gap:'8px', alignItems:'flex-end', marginBottom:'8px', flexWrap:'wrap'});

                var $classSel = $('<select>').addClass('msc-add-class-sel').css('flex', '1').attr('data-row', rowId);
                $classSel.append($('<option>').val('').text('— Select class —'));

                // Get selected class IDs (primary + existing additional rows)
                var usedIds = msc.getSelectedClassIds();

                // Group non-primary-only classes by vtype
                var addGroups = {}, addGroupOrder = [];
                classes.forEach(function(cls) {
                    if (cls.primary_only) return;
                    var g = cls.vtype || '';
                    if (!addGroups[g]) { addGroups[g] = []; addGroupOrder.push(g); }
                    addGroups[g].push(cls);
                });

                if (addGroupOrder.length > 1) {
                    addGroupOrder.forEach(function(g) {
                        var $grp = $('<optgroup>').attr('label', g || 'Other');
                        addGroups[g].forEach(function(cls) {
                            $grp.append($('<option>').val(cls.id).text(cls.name).prop('disabled', usedIds.indexOf(cls.id) !== -1));
                        });
                        $classSel.append($grp);
                    });
                } else {
                    (addGroups[addGroupOrder[0]] || []).forEach(function(cls) {
                        $classSel.append($('<option>').val(cls.id).text(cls.name).prop('disabled', usedIds.indexOf(cls.id) !== -1));
                    });
                }

                var $vehicleSel = $('<select>').addClass('msc-add-vehicle-sel').css('flex', '1').attr('data-row', rowId);
                $vehicleSel.append($('<option>').val('').text('— Select vehicle —'));
                $vehicleSel.hide();

                var $removeBtn = $('<button>').attr('type', 'button').addClass('msc-btn msc-btn-sm msc-btn-danger').text('Remove')
                    .attr('data-row', rowId);

                $row.append($classSel, $vehicleSel, $removeBtn);
                $('#msc-additional-rows').append($row);
                msc.additionalRows.push({rowId: rowId, classId: null, vehicleId: null});
            });

            // Additional class select change
            $(document).on('change', '.msc-add-class-sel', function() {
                var rowId   = parseInt($(this).data('row'));
                var classId = $(this).val();
                var $vehicleSel = $('.msc-add-vehicle-sel[data-row="' + rowId + '"]');
                var row = msc.additionalRows.find(function(r) { return r.rowId === rowId; });
                if (row) { row.classId = classId ? parseInt(classId) : null; row.vehicleId = null; }

                if (!classId) { $vehicleSel.hide().val(''); msc.updateFees(); msc.renderClassConditions(); msc.checkStep1(); return; }
                populateVehicleSelect($vehicleSel, classId, null);
                $vehicleSel.show();

                // Disable the selected class in all OTHER additional rows' dropdowns
                msc.refreshAdditionalClassOptions();
                msc.updateFees();
                msc.renderClassConditions();
                msc.checkStep1();
            });

            // Additional vehicle select change
            $(document).on('change', '.msc-add-vehicle-sel', function() {
                var rowId = parseInt($(this).data('row'));
                var vid   = $(this).val();
                var row   = msc.additionalRows.find(function(r) { return r.rowId === rowId; });
                if (row) row.vehicleId = vid ? parseInt(vid) : null;
                // Always show comp number field for this row, pre-filled with stored value if any
                var $row = $('#msc-add-row-' + rowId);
                var $cn  = $row.find('.msc-add-comp-notice');
                if (row && row.vehicleId) {
                    var veh    = msc.allVehicles.find(function(v) { return v.id == row.vehicleId; });
                    var stored = veh ? (veh.comp_number || '') : '';
                    msc.vehicleCompNumbers[row.vehicleId] = stored;
                    if (!$cn.length) {
                        $cn = $('<div>').addClass('msc-add-comp-notice msc-field').css({marginTop:'10px', width:'100%'});
                        var $lbl = $('<label>').css('font-weight','600').html('Race Number <span class="msc-required">*</span>');
                        var $inp = $('<input>').attr('type','text').attr('placeholder','e.g. 42')
                            .addClass('msc-comp-input');
                        $cn.append($lbl, $inp);
                        $row.append($cn);
                    }
                    $cn.find('.msc-comp-input').attr('data-vehicle-id', row.vehicleId).val(stored);
                    $cn.show();
                } else {
                    $cn.hide();
                }
                msc.updateFees();
                msc.renderClassConditions();
                msc.checkStep1();
            });

            // Remove additional row
            $(document).on('click', '.msc-add-row-remove', function() {
                var rowId = parseInt($(this).data('row'));
                $('#msc-add-row-' + rowId).remove();
                msc.additionalRows = msc.additionalRows.filter(function(r) { return r.rowId !== rowId; });
                msc.refreshAdditionalClassOptions();
                msc.updateFees();
                msc.renderClassConditions();
                msc.checkStep1();
            });
            // Also handle via delegated click with msc-btn-danger class on remove buttons
            $(document).on('click', '#msc-additional-rows .msc-btn-danger', function() {
                var rowId = parseInt($(this).data('row'));
                $('#msc-add-row-' + rowId).remove();
                msc.additionalRows = msc.additionalRows.filter(function(r) { return r.rowId !== rowId; });
                msc.refreshAdditionalClassOptions();
                msc.updateFees();
                msc.renderClassConditions();
                msc.checkStep1();
            });

            // Get all currently selected class IDs (primary + additional)
            msc.getSelectedClassIds = function() {
                var ids = [];
                if (msc.primaryClassId) ids.push(msc.primaryClassId);
                msc.additionalRows.forEach(function(r) { if (r.classId) ids.push(r.classId); });
                return ids;
            };

            // Refresh disabled state on all additional class dropdowns
            msc.refreshAdditionalClassOptions = function() {
                var usedIds = msc.getSelectedClassIds();
                $('.msc-add-class-sel').each(function() {
                    var rowId = parseInt($(this).data('row'));
                    var rowClassId = null;
                    var row = msc.additionalRows.find(function(r) { return r.rowId === rowId; });
                    if (row) rowClassId = row.classId;
                    $(this).find('option').each(function() {
                        var optId = parseInt($(this).val());
                        if (!optId) return;
                        var isThisRow = (optId === rowClassId);
                        $(this).prop('disabled', !isThisRow && usedIds.indexOf(optId) !== -1);
                    });
                });
            };

            // Update fee breakdown
            msc.updateFees = function() {
                var primaryCls = classes.find(function(c) { return c.id == msc.primaryClassId; });
                var primaryFee = primaryCls ? (primaryCls.primary_fee || 0) : 0;
                var globalOverride = (primaryCls && primaryCls.override !== undefined && primaryCls.override !== null) ? primaryCls.override : null;
                var total = baseFee + primaryFee;

                var rows = [];
                if (baseFee > 0) rows.push({label: 'Base Admin Fee', fee: baseFee});
                if (primaryCls) {
                    rows.push({label: primaryCls.name + ' (primary)', fee: primaryFee});
                }

                msc.additionalRows.forEach(function(r) {
                    if (!r.classId) return;
                    var cls = classes.find(function(c) { return c.id == r.classId; });
                    if (!cls) return;
                    
                    var af = 0;
                    if (cls.exempt) {
                        af = cls.additional_fee || 0;
                    } else if (globalOverride !== null) {
                        af = globalOverride;
                    } else {
                        af = cls.additional_fee || 0;
                    }
                    
                    total += af;
                    rows.push({label: cls.name + ' (additional)', fee: af});
                });

                msc.totalFee = total;

                // Step-1 fee breakdown
                var $rows1 = $('#msc-fee-breakdown-rows');
                $rows1.empty();
                rows.forEach(function(r) {
                    $rows1.append($('<tr>').append(
                        $('<td>').css('padding', '4px').text(r.label),
                        $('<td>').css({padding:'4px','text-align':'right'}).text(r.fee > 0 ? 'R ' + r.fee.toFixed(2) : 'Included')
                    ));
                });
                $('#msc-fee-total').text('R ' + total.toFixed(2));
                if (primaryCls) {
                    $('#msc-fee-breakdown').show();
                } else {
                    $('#msc-fee-breakdown').hide();
                }

                // Step-2 payment breakdown
                var $rows2 = $('#msc-payment-breakdown-rows');
                $rows2.empty();
                rows.forEach(function(r) {
                    $rows2.append($('<tr>').append(
                        $('<td>').css('padding', '4px').text(r.label),
                        $('<td>').css({padding:'4px','text-align':'right'}).text(r.fee > 0 ? 'R ' + r.fee.toFixed(2) : 'Included')
                    ));
                });
                $('#msc-payment-total').text('R ' + total.toFixed(2));

                if (total > 0) {
                    $('#msc-payment-section').show();
                } else {
                    $('#msc-payment-section').hide();
                    $('#msc-pop-file').val('');
                }

                msc.checkRegValidity();
            };

            // Enable Next only when primary class + primary vehicle are selected, and all vehicles have comp numbers
            // Render per-class condition groups into Step 1 whenever selection changes
            msc.renderClassConditions = function() {
                var $condWrap = $('#msc-class-declarations-wrap').empty();
                if (!msc.primaryClassId) return;

                var selectedIds = [msc.primaryClassId].concat(
                    msc.additionalRows.filter(function(r){ return r.classId && r.vehicleId; }).map(function(r){ return r.classId; })
                );
                selectedIds.forEach(function(classId) {
                    var cls = classes.find(function(c){ return c.id == classId; });
                    if (!cls || !cls.conditions || !cls.conditions.length) return;
                    var $section = $('<div>').addClass('msc-class-cond-section');
                    $section.append($('<p>').addClass('msc-class-cond-section-title').text(cls.name + ' — Required Declarations'));
                    cls.conditions.forEach(function(cond, idx) {
                        var $group = $('<div>').addClass('msc-cond-group').attr({'data-class-id': classId, 'data-idx': idx, 'data-type': cond.type});
                        if (cond.type === 'confirm') {
                            var cbId = 'msc-cdecl-' + classId + '-' + idx;
                            var $lbl = $('<label>').addClass('msc-cond-label').attr('for', cbId);
                            $lbl.append($('<input>').attr({type:'checkbox',id:cbId,name:'msc_cdecl['+classId+']['+idx+']',value:'1'}).addClass('msc-class-declaration'));
                            $lbl.append($('<span>').text(cond.label));
                            $group.append($lbl);
                        } else if (cond.type === 'select_one') {
                            $group.append($('<p>').addClass('msc-cond-group-label').text(cond.label));
                            (cond.options || []).forEach(function(opt, oi) {
                                var rbId = 'msc-cdecl-' + classId + '-' + idx + '-' + oi;
                                var $lbl = $('<label>').addClass('msc-cond-label').attr('for', rbId);
                                $lbl.append($('<input>').attr({type:'radio',id:rbId,name:'msc_cdecl['+classId+']['+idx+']',value:opt}).addClass('msc-class-declaration'));
                                $lbl.append($('<span>').text(opt));
                                $group.append($lbl);
                            });
                        } else if (cond.type === 'select_many') {
                            $group.append($('<p>').addClass('msc-cond-group-label').text(cond.label));
                            (cond.options || []).forEach(function(opt, oi) {
                                var cbId = 'msc-cdecl-' + classId + '-' + idx + '-' + oi;
                                var $lbl = $('<label>').addClass('msc-cond-label').attr('for', cbId);
                                $lbl.append($('<input>').attr({type:'checkbox',id:cbId,name:'msc_cdecl['+classId+']['+idx+'][]',value:opt}).addClass('msc-class-declaration'));
                                $lbl.append($('<span>').text(opt));
                                $group.append($lbl);
                            });
                        }
                        $section.append($group);
                    });
                    $condWrap.append($section);
                });
            };

            msc.checkStep1 = function() {
                var ready = !!msc.primaryClassId && !!msc.primaryVehicleId;
                if (ready && msc.primaryVehicleId && !msc.vehicleCompNumbers[msc.primaryVehicleId]) ready = false;
                if (ready) {
                    msc.additionalRows.forEach(function(r) {
                        if (r.classId && !r.vehicleId) ready = false;
                        if (r.vehicleId && !msc.vehicleCompNumbers[r.vehicleId]) ready = false;
                    });
                }
                // All class conditions must be answered before Next is enabled
                if (ready) {
                    $('.msc-cond-group').each(function() {
                        var type    = $(this).data('type');
                        var $inputs = $(this).find('.msc-class-declaration');
                        if (type === 'confirm') {
                            if (!$inputs.is(':checked')) ready = false;
                        } else if (type === 'select_one' || type === 'select_many') {
                            if (!$inputs.filter(':checked').length) ready = false;
                        }
                    });
                }
                $('#msc-step1-next').prop('disabled', !ready);
            };

            $('#msc-step1-next').on('click', function () {
                if (!msc.primaryClassId || !msc.primaryVehicleId) return;

                // Build summary
                var primaryCls = classes.find(function(c) { return c.id == msc.primaryClassId; });
                var primaryVeh = msc.allVehicles.find(function(v) { return v.id == msc.primaryVehicleId; });
                var $table = $('<table style="width:100%;border-collapse:collapse">');
                var pFee = primaryCls ? (primaryCls.primary_fee || 0) : 0;
                $table.append($('<tr>').append(
                    $('<td>').css('padding','4px 8px 4px 0').text(primaryCls ? primaryCls.name : ''),
                    $('<td>').css('padding','4px').text(primaryVeh ? primaryVeh.label : ''),
                    $('<td>').css({padding:'4px','text-align':'right'}).text(pFee > 0 ? 'R ' + pFee.toFixed(2) : 'Included')
                ));

                msc.additionalRows.forEach(function(r) {
                    if (!r.classId || !r.vehicleId) return;
                    var cls = classes.find(function(c) { return c.id == r.classId; });
                    var veh = msc.allVehicles.find(function(v) { return v.id == r.vehicleId; });
                    var af  = cls ? (cls.additional_fee || 0) : 0;
                    $table.append($('<tr>').append(
                        $('<td>').css('padding','4px 8px 4px 0').text((cls ? cls.name : '') + ' (additional)'),
                        $('<td>').css('padding','4px').text(veh ? veh.label : ''),
                        $('<td>').css({padding:'4px','text-align':'right'}).text(af > 0 ? 'R ' + af.toFixed(2) : 'Included')
                    ));
                });

                if (baseFee > 0) {
                    $table.append($('<tr>').append(
                        $('<td>').css('padding','4px 8px 4px 0').text('Base entry fee'),
                        $('<td>'), $('<td>').css({padding:'4px','text-align':'right'}).text('R ' + baseFee.toFixed(2))
                    ));
                }
                $table.append($('<tr style="border-top:2px solid #ccc">').append(
                    $('<td>').css('padding','6px 8px 4px 0').append($('<strong>').text('Total')),
                    $('<td>'),
                    $('<td>').css({padding:'6px 4px','text-align':'right'}).append($('<strong>').text('R ' + msc.totalFee.toFixed(2)))
                ));

                $('#msc-summary').empty().append($table);

                $('#msc-step-1').hide();
                $('#msc-step-2').show();
                msc.checkRegValidity();
                setTimeout(function() { msc.initSignaturePads(); }, 100);
            });

            $('#msc-step2-back').on('click', function () {
                $('#msc-step-2').hide();
                $('#msc-step-1').show();
                $('#msc-reg-error').hide();
            });

            // Comp number input handler — update store and re-check step 1
            $(document).on('input', '.msc-comp-input', function() {
                var vid = parseInt($(this).attr('data-vehicle-id'));
                var val = $(this).val().trim();
                if (vid) msc.vehicleCompNumbers[vid] = val;
                msc.checkStep1();
            });

            // Input listeners for validation
            $(document).on('input change', '#msc-msa-licence, #msc-emergency-name, #msc-emergency-phone, #msc-parent-name, #msc-sig-typed, #msc-parent-sig-typed, #msc-pop-file, .msc-custom-declaration, #msc-indemnity-accept', function() {
                msc.checkRegValidity();
            });

            // Condition inputs are in Step 1 — re-check Step 1 validity on change
            $(document).on('change', '.msc-class-declaration', function() {
                msc.checkStep1();
            });

            $('input[name="msc_sig_type"]').on('change', function () {
                msc.sigType = $(this).val();
                if (msc.sigType === 'draw') {
                    $('#msc-sig-draw-wrap').show();
                    $('#msc-sig-type-wrap').hide();
                } else {
                    $('#msc-sig-draw-wrap').hide();
                    $('#msc-sig-type-wrap').show();
                }
                msc.checkRegValidity();
            });

            $('input[name="msc_parent_sig_type"]').on('change', function () {
                msc.parentSigType = $(this).val();
                if (msc.parentSigType === 'draw') {
                    $('#msc-parent-sig-draw-wrap').show();
                    $('#msc-parent-sig-type-wrap').hide();
                } else {
                    $('#msc-parent-sig-draw-wrap').hide();
                    $('#msc-parent-sig-type-wrap').show();
                }
                msc.checkRegValidity();
            });

            $('#msc-sig-clear').on('click', function () {
                if (msc.sigPad) {
                    msc.sigPad.clear();
                    msc.checkRegValidity();
                }
            });

            $('#msc-parent-sig-clear').on('click', function (e) {
                e.preventDefault();
                if (msc.parentSigPad) {
                    msc.parentSigPad.clear();
                    msc.checkRegValidity();
                }
            });

            $('#msc-submit-reg').on('click', function () {
                var sig = '';
                var parentSig = '';
                var isMinor = $('#msc-reg-wrap').data('minor') == 1;

                // Participant Sig
                if (msc.sigType === 'draw') {
                    if (!msc.sigPad || msc.sigPad.isEmpty()) { msc.showError('Please draw your signature.'); return; }
                    sig = msc.sigPad.toDataURL();
                } else {
                    sig = $('#msc-sig-typed').val().trim();
                    if (!sig) { msc.showError('Please type your name as a signature.'); return; }
                }

                // Parent Sig
                if (isMinor) {
                    if (msc.parentSigType === 'draw') {
                        if (!msc.parentSigPad || msc.parentSigPad.isEmpty()) { msc.showError('Please draw the Parent/Guardian signature.'); return; }
                        parentSig = msc.parentSigPad.toDataURL();
                    } else {
                        parentSig = $('#msc-parent-sig-typed').val().trim();
                        if (!parentSig) { msc.showError('Please type the Parent/Guardian name as a signature.'); return; }
                    }
                }

                // Proof of Payment
                var popFile = $('#msc-pop-file')[0] ? $('#msc-pop-file')[0].files[0] : null;
                if (msc.totalFee > 0 && !popFile) {
                    msc.showError('Please upload your Proof of Payment PDF.');
                    return;
                }

                var btn = $(this);
                btn.prop('disabled', true).text('Submitting…');

                // Prominent "please wait" banner — sticky so it stays visible while scrolled
                var $banner = $('<div>').addClass('msc-submitting-banner').append(
                    $('<div>').addClass('msc-submitting-spinner'),
                    $('<div>').append(
                        $('<strong>').text('Submitting your entry\u2026'),
                        $('<span>').text('Please do not close or refresh this page. This may take up to 10 seconds.')
                    )
                );
                $('#msc-reg-wrap').prepend($banner);
                $banner[0].scrollIntoView({ behavior: 'smooth', block: 'nearest' });

                // Warn if user tries to navigate away mid-submit
                var beforeUnloadHandler = function (e) {
                    e.preventDefault();
                    e.returnValue = '';
                };
                window.addEventListener('beforeunload', beforeUnloadHandler);

                var fd = new FormData();
                fd.append('action',              'msc_submit_registration');
                fd.append('nonce',               mscData.nonce);
                fd.append('event_id',            msc.eventId);
                fd.append('primary_class_id',    msc.primaryClassId);
                fd.append('primary_vehicle_id',  msc.primaryVehicleId);
                msc.additionalRows.forEach(function(r) {
                    if (r.classId && r.vehicleId) {
                        fd.append('additional_class_ids[]',   r.classId);
                        fd.append('additional_vehicle_ids[]', r.vehicleId);
                    }
                });
                // Send any user-supplied comp numbers to be saved to the vehicle
                Object.keys(msc.vehicleCompNumbers).forEach(function(vid) {
                    var comp = msc.vehicleCompNumbers[vid];
                    if (comp) fd.append('vehicle_comp_numbers[' + vid + ']', comp);
                });
                fd.append('indemnity_accept',  $('#msc-indemnity-accept').is(':checked') ? '1' : '0');
                fd.append('indemnity_method', 'signed');
                fd.append('indemnity_sig',    sig);
                fd.append('parent_sig',       parentSig);
                fd.append('is_minor',         isMinor ? 1 : 0);
                fd.append('parent_name',      $('#msc-parent-name').val());
                fd.append('msa_licence',      $('#msc-msa-licence').val());
                fd.append('emergency_name',   $('#msc-emergency-name').val());
                fd.append('emergency_phone',  $('#msc-emergency-phone').val());
                fd.append('emergency_rel',    $('#msc-emergency-rel').val());
                fd.append('pit_crew_1',       $('#msc-pit-crew-1').val());
                fd.append('pit_crew_2',       $('#msc-pit-crew-2').val());
                fd.append('sponsors',         $('#msc-sponsors').val().substring(0, 33));
                fd.append('notes',            $('#msc-notes').val());
                if (popFile) fd.append('pop_file', popFile);

                // Append per-class condition answers
                $('.msc-cond-group').each(function() {
                    var classId = $(this).data('class-id');
                    var idx     = $(this).data('idx');
                    var type    = $(this).data('type');
                    var $inputs = $(this).find('.msc-class-declaration');
                    if (type === 'confirm') {
                        if ($inputs.is(':checked')) fd.append('msc_cdecl[' + classId + '][' + idx + ']', '1');
                    } else if (type === 'select_one') {
                        var val = $inputs.filter(':checked').val();
                        if (val !== undefined) fd.append('msc_cdecl[' + classId + '][' + idx + ']', val);
                    } else if (type === 'select_many') {
                        $inputs.filter(':checked').each(function() {
                            fd.append('msc_cdecl[' + classId + '][' + idx + '][]', $(this).val());
                        });
                    }
                });

                $.ajax({
                    url:         mscData.ajaxUrl,
                    type:        'POST',
                    data:        fd,
                    processData: false,
                    contentType: false,
                    success: function (res) {
                        window.removeEventListener('beforeunload', beforeUnloadHandler);
                        $banner.remove();
                        if (res.success) {
                            var icon = res.data.status === 'confirmed' ? '🎉' : '⏳';
                            $('#msc-reg-wrap').empty().append(
                                $('<div>').addClass('msc-notice msc-notice-success msc-success-big').text(icon + ' ' + res.data.message)
                            );
                        } else {
                            msc.showError(res.data.message || 'An error occurred. Please try again.');
                            btn.prop('disabled', false).text('Submit Registration');
                        }
                    },
                    error: function (jqXHR) {
                        window.removeEventListener('beforeunload', beforeUnloadHandler);
                        $banner.remove();
                        var status = jqXHR.status || 0;
                        var hint   = status === 413 ? ' (file too large)' : status === 500 ? ' (server error)' : status === 403 ? ' (access denied)' : '';
                        msc.showError('Network error (HTTP ' + status + ')' + hint + '. Please try again.');
                        btn.prop('disabled', false).text('Submit Registration');
                        // Report to server log
                        $.post(mscData.ajaxUrl, {
                            action:           'msc_log_client_error',
                            nonce:            mscData.nonce,
                            msc_action:       'msc_submit_registration',
                            event_id:         $wrap.data('event-id') || 0,
                            http_status:      status,
                            response_snippet: (jqXHR.responseText || '').substring(0, 500)
                        });
                    }
                });
            });
        },

        initSignaturePads: function () {
            // Main Pad
            var canvas = document.getElementById('msc-sig-canvas');
            if (canvas && window.SignaturePad) {
                // Only init if visible and width > 0
                if (canvas.offsetWidth > 0) {
                    if (msc.sigPad) { msc.sigPad.off(); msc.sigPad = null; }
                    canvas.width  = canvas.offsetWidth;
                    canvas.height = canvas.offsetHeight;
                    msc.sigPad = new SignaturePad(canvas, { backgroundColor: 'rgb(250,250,250)', penColor: 'rgb(0,0,0)', minWidth: 1.5, maxWidth: 3 });
                    msc.sigPad.addEventListener("endStroke", function() { msc.checkRegValidity(); });
                }
            }

            // Parent Pad
            var pCanvas = document.getElementById('msc-parent-sig-canvas');
            if (pCanvas && window.SignaturePad) {
                // Only init if visible and width > 0
                if (pCanvas.offsetWidth > 0) {
                    if (msc.parentSigPad) { msc.parentSigPad.off(); msc.parentSigPad = null; }
                    pCanvas.width  = pCanvas.offsetWidth;
                    pCanvas.height = pCanvas.offsetHeight;
                    msc.parentSigPad = new SignaturePad(pCanvas, { backgroundColor: 'rgb(250,250,250)', penColor: 'rgb(0,0,0)', minWidth: 1.5, maxWidth: 3 });
                    msc.parentSigPad.addEventListener("endStroke", function() { msc.checkRegValidity(); });
                }
            }
        },

        checkRegValidity: function() {
            var isValid = true;
            var isMinor = $('#msc-reg-wrap').data('minor') == 1;

            // Motorsport Details
            if (!$('#msc-msa-licence').val().trim()) isValid = false;

            // Emergency Contacts
            if (!$('#msc-emergency-name').val().trim()) isValid = false;
            if (!$('#msc-emergency-phone').val().trim()) isValid = false;

            // Minor Check
            if (isMinor && !$('#msc-parent-name').val().trim()) isValid = false;

            // Participant Sig
            if (msc.sigType === 'draw') {
                if (!msc.sigPad || msc.sigPad.isEmpty()) isValid = false;
            } else {
                if (!$('#msc-sig-typed').val().trim()) isValid = false;
            }

            // Parent Sig
            if (isMinor) {
                if (msc.parentSigType === 'draw') {
                    if (!msc.parentSigPad || msc.parentSigPad.isEmpty()) isValid = false;
                } else {
                    if (!$('#msc-parent-sig-typed').val().trim()) isValid = false;
                }
            }

            // Proof of Payment — required only when total fee > 0
            if (msc.totalFee > 0) {
                if (!$('#msc-pop-file')[0] || !$('#msc-pop-file')[0].files[0]) isValid = false;
            }

            // Indemnity accept checkbox
            if (!$('#msc-indemnity-accept').is(':checked')) isValid = false;

            // Custom Declarations (Mandatory Checkboxes)
            $('.msc-custom-declaration').each(function() {
                if (!$(this).is(':checked')) isValid = false;
            });

            $('#msc-submit-reg').prop('disabled', !isValid);
        },

        showError: function (msg) {
            $('#msc-reg-error').text(msg).show();
            $('html, body').animate({ scrollTop: $('#msc-reg-error').offset().top - 80 }, 300);
        },

        // ─── Garage ───────────────────────────────────────────────────
        bindGarage: function () {

            // Open/close add vehicle form
            $('#msc-add-vehicle-btn').on('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                var $form = $('#msc-add-vehicle-form');
                if ($form.is(':visible')) {
                    $form.slideUp(200);
                } else {
                    $form.slideDown(200);
                }
            });

            $('#msc-cancel-vehicle, #msc-cancel-vehicle-2').on('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                $('#msc-add-vehicle-form').slideUp(200);
            });

            // Photo preview
            $(document).on('change', '#v_photo', function () {
                if (this.files && this.files[0]) setPhotoPreview(this.files[0]);
            });

            $(document).on('dragover', '#msc-photo-drop', function (e) {
                e.preventDefault();
                $(this).addClass('drag-over');
            });

            $(document).on('dragleave drop', '#msc-photo-drop', function (e) {
                e.preventDefault();
                $(this).removeClass('drag-over');
                if (e.type === 'drop' && e.originalEvent.dataTransfer.files.length) {
                    setPhotoPreview(e.originalEvent.dataTransfer.files[0]);
                }
            });

            $(document).on('click', '#msc-photo-remove', function (e) {
                e.preventDefault();
                photoFile = null;
                $('#v_photo').val('');
                $('#msc-photo-preview').hide();
                $('#msc-photo-placeholder').show();
            });

            // Save vehicle
            $('#msc-save-vehicle').on('click', function (e) {
                e.preventDefault();
                var title = $('#v_title').val().trim();
                if (!title) {
                    $('#msc-vehicle-msg').text('Please enter a vehicle name.');
                    return;
                }
                var btn = $(this);
                btn.prop('disabled', true).text('Saving…');

                var fd = new FormData();
                fd.append('action',     'msc_add_vehicle');
                fd.append('nonce',      mscData.nonce);
                fd.append('title',      title);
                fd.append('type',       $('#v_type').val());
                fd.append('make',       $('#v_make').val());
                fd.append('model',      $('#v_model').val());
                fd.append('year',       $('#v_year').val());
                fd.append('color',      $('#v_color').val());
                fd.append('comp_number', $('#v_comp_number').val());
                fd.append('engine_size', $('#v_engine_size').val());
                fd.append('notes',      $('#v_notes').val());
                if (photoFile) fd.append('photo', photoFile, photoFile.name);

                $.ajax({
                    url:         mscData.ajaxUrl,
                    type:        'POST',
                    data:        fd,
                    processData: false,
                    contentType: false,
                    success: function (res) {
                        if (res.success) {
                            location.reload();
                        } else {
                            $('#msc-vehicle-msg').text(res.data.message || 'Error saving vehicle.');
                            btn.prop('disabled', false).text('Save Vehicle');
                        }
                    },
                    error: function () {
                        $('#msc-vehicle-msg').text('Network error. Please try again.');
                        btn.prop('disabled', false).text('Save Vehicle');
                    }
                });
            });

            // Delete vehicle
            $(document).on('click', '.msc-delete-vehicle', function (e) {
                e.preventDefault();
                if (!confirm('Remove this vehicle from your garage?')) return;
                var id = $(this).data('id');
                $.post(mscData.ajaxUrl, {
                    action:     'msc_delete_vehicle',
                    nonce:      mscData.nonce,
                    vehicle_id: id
                }, function (res) {
                    if (res.success) location.reload();
                    else alert(res.data.message || 'Error removing vehicle.');
                });
            });

        // Open inline edit form
            $(document).on('click', '.msc-edit-vehicle', function (e) {
                e.preventDefault();
                e.stopPropagation();
                var id = $(this).data('id');
                // Close any other open edit forms first
                $('.msc-inline-edit-form').not('#msc-edit-' + id).slideUp(150);
                $('#msc-edit-' + id).slideToggle(200);
            });

            // Cancel inline edit
            $(document).on('click', '.msc-edit-cancel', function (e) {
                e.preventDefault();
                var id = $(this).data('id');
                $('#msc-edit-' + id).slideUp(200);
            });

            // Edit photo input
            $(document).on('change', '.msc-edit-photo-input', function () {
                var id = $(this).data('id');
                if (this.files && this.files[0]) setEditPhotoPreview(id, this.files[0]);
            });

            // Edit photo drag-drop
            $(document).on('dragover', '.msc-edit-photo-drop', function (e) {
                e.preventDefault();
                $(this).addClass('drag-over');
            });
            $(document).on('dragleave drop', '.msc-edit-photo-drop', function (e) {
                e.preventDefault();
                $(this).removeClass('drag-over');
                if (e.type === 'drop' && e.originalEvent.dataTransfer.files.length) {
                    var id = $(this).attr('id').replace('msc-edit-photo-drop-', '');
                    setEditPhotoPreview(id, e.originalEvent.dataTransfer.files[0]);
                }
            });

            // Remove edit photo
            $(document).on('click', '.msc-edit-photo-remove', function (e) {
                e.preventDefault();
                var id = $(this).data('id');
                editPhotoFiles[id] = null;
                $('#edit_photo_' + id).val('');
                $('#msc-edit-photo-preview-' + id).hide();
                $('#msc-edit-photo-placeholder-' + id).show();
            });

            // Save edits
            $(document).on('click', '.msc-save-vehicle-edit', function (e) {
                e.preventDefault();
                var id  = $(this).data('id');
                var btn = $(this);
                var title = $('.edit-v_title[data-id="' + id + '"]').val().trim();
                if (!title) {
                    $('#msc-edit-msg-' + id).text('Vehicle name is required.');
                    return;
                }
                btn.prop('disabled', true).text('Saving…');

                var fd = new FormData();
                fd.append('action',     'msc_update_vehicle');
                fd.append('nonce',      mscData.nonce);
                fd.append('vehicle_id', id);
                fd.append('title',      title);
                fd.append('type',       $('.edit-v_type[data-id="'  + id + '"]').val());
                fd.append('make',       $('.edit-v_make[data-id="'  + id + '"]').val());
                fd.append('model',      $('.edit-v_model[data-id="' + id + '"]').val());
                fd.append('year',       $('.edit-v_year[data-id="'  + id + '"]').val());
                fd.append('color',      $('.edit-v_color[data-id="' + id + '"]').val());
                fd.append('comp_number', $('.edit-v_comp_number[data-id="' + id + '"]').val());
                fd.append('engine_size', $('.edit-v_engine_size[data-id="' + id + '"]').val());
                fd.append('notes',      $('.edit-v_notes[data-id="' + id + '"]').val());
                if (editPhotoFiles[id]) fd.append('photo', editPhotoFiles[id], editPhotoFiles[id].name);

                $.ajax({
                    url:         mscData.ajaxUrl,
                    type:        'POST',
                    data:        fd,
                    processData: false,
                    contentType: false,
                    success: function (res) {
                        if (res.success) {
                            location.reload();
                        } else {
                            $('#msc-edit-msg-' + id).text(res.data.message || 'Error saving.');
                            btn.prop('disabled', false).text('Save Changes');
                        }
                    },
                    error: function () {
                        $('#msc-edit-msg-' + id).text('Network error. Please try again.');
                        btn.prop('disabled', false).text('Save Changes');
                    }
                });
            });
        },

        // ─── Registrations & Profile ──────────────────────────────────
        bindAccount: function () {
            // ── Entry edit ────────────────────────────────────────────
            $(document).on('click', '.msc-edit-entry', function(e) {
                e.preventDefault();
                var btn   = $(this);
                var regId = btn.data('id');
                var card  = btn.closest('.msc-reg-card');

                $('.msc-entry-edit-panel').remove();
                btn.prop('disabled', true).text('Loading…');

                $.post(mscData.ajaxUrl, {
                    action: 'msc_get_entry_edit_data',
                    nonce:  mscData.nonce,
                    reg_id: regId
                }, function(res) {
                    btn.prop('disabled', false).text('Edit Entry');
                    if (!res.success) {
                        alert(res.data.message || 'Could not load entry data.');
                        return;
                    }
                    renderEntryEditPanel(card, res.data);
                });
            });

            $(document).on('click', '#msc-edit-cancel-btn', function() {
                $('.msc-entry-edit-panel').remove();
            });

            $(document).on('click', '#msc-edit-save-btn', function() {
                var panel       = $('.msc-entry-edit-panel');
                var regId       = $(this).data('reg');
                var pricing     = panel.data('pricing');
                var baseFee     = parseFloat(panel.data('base_fee'));
                var originalFee = parseFloat(panel.data('original_fee'));
                var primaryId   = parseInt($('#msc-edit-primary-class').val()) || 0;

                if (!primaryId) {
                    $('#msc-edit-msg').text('Please select a primary class.').css('color','red').show();
                    return;
                }

                var additionalIds = [];
                $('.msc-edit-additional:checked:not(:disabled)').each(function() {
                    additionalIds.push(parseInt($(this).val()));
                });

                var newFee = calcEntryFee(baseFee, pricing, primaryId, additionalIds);
                var diff   = Math.round((newFee - originalFee) * 100) / 100;

                if (diff < -0.005) {
                    $('#msc-edit-msg').text('You cannot reduce your entry below the amount already paid.').css('color','red').show();
                    return;
                }
                if (diff > 0.005 && !($('#msc-edit-pop-file')[0].files && $('#msc-edit-pop-file')[0].files.length)) {
                    $('#msc-edit-msg').text('Please upload proof of payment for the additional R ' + diff.toFixed(2) + ' owed.').css('color','red').show();
                    return;
                }

                // Validate conditions for newly-added classes
                var condsFilled = true;
                $('#msc-edit-conditions-wrap .msc-edit-cond-group').each(function() {
                    var type    = $(this).data('type');
                    var $inputs = $(this).find('.msc-edit-class-decl');
                    if (type === 'confirm') {
                        if (!$inputs.is(':checked')) condsFilled = false;
                    } else if (type === 'select_one' || type === 'select_many') {
                        if (!$inputs.filter(':checked').length) condsFilled = false;
                    }
                });
                if (!condsFilled) {
                    $('#msc-edit-msg').text('Please complete all required class conditions.').css('color','red').show();
                    return;
                }

                var btn = $(this);
                btn.prop('disabled', true).text('Saving…');
                $('#msc-edit-msg').hide();

                var fd = new FormData();
                fd.append('action',           'msc_update_entry_classes');
                fd.append('nonce',            mscData.nonce);
                fd.append('reg_id',           regId);
                fd.append('primary_class_id', primaryId);
                for (var i = 0; i < additionalIds.length; i++) {
                    fd.append('additional_class_ids[]', additionalIds[i]);
                }
                // Per-class vehicle IDs
                var primaryVehicleId = parseInt($('#msc-edit-primary-vehicle').val()) || 0;
                fd.append('vehicle_ids[' + primaryId + ']', primaryVehicleId);
                $('.msc-edit-additional:checked:not(:disabled)').each(function() {
                    var cid = parseInt($(this).val());
                    var vid = parseInt($(this).closest('.msc-edit-add-row').find('.msc-edit-add-vehicle').val()) || 0;
                    fd.append('vehicle_ids[' + cid + ']', vid);
                });
                if ($('#msc-edit-pop-file')[0].files && $('#msc-edit-pop-file')[0].files[0]) {
                    fd.append('pop_file', $('#msc-edit-pop-file')[0].files[0]);
                }
                fd.append('msc_pit_crew_1', $('#msc-edit-pit-crew-1').val());
                fd.append('msc_pit_crew_2', $('#msc-edit-pit-crew-2').val());
                // Condition answers for newly-added classes
                $('#msc-edit-conditions-wrap .msc-edit-cond-group').each(function() {
                    var classId = $(this).data('class-id');
                    var idx     = $(this).data('idx');
                    var type    = $(this).data('type');
                    var $inputs = $(this).find('.msc-edit-class-decl');
                    if (type === 'confirm') {
                        if ($inputs.is(':checked')) fd.append('msc_cdecl[' + classId + '][' + idx + ']', '1');
                    } else if (type === 'select_one') {
                        var val = $inputs.filter(':checked').val();
                        if (val !== undefined) fd.append('msc_cdecl[' + classId + '][' + idx + ']', val);
                    } else if (type === 'select_many') {
                        $inputs.filter(':checked').each(function() {
                            fd.append('msc_cdecl[' + classId + '][' + idx + '][]', $(this).val());
                        });
                    }
                });

                $.ajax({
                    url:         mscData.ajaxUrl,
                    type:        'POST',
                    data:        fd,
                    processData: false,
                    contentType: false,
                    success: function(res) {
                        if (res.success) {
                            $('#msc-edit-msg').text(res.data.message).css('color','green').show();
                            setTimeout(function() { location.reload(); }, 1200);
                        } else {
                            btn.prop('disabled', false).text('Save Changes');
                            $('#msc-edit-msg').text(res.data.message || 'Error saving.').css('color','red').show();
                        }
                    },
                    error: function() {
                        btn.prop('disabled', false).text('Save Changes');
                        $('#msc-edit-msg').text('Network error.').css('color','red').show();
                    }
                });
            });

            $(document).on('click', '.msc-cancel-reg', function (e) {
                e.preventDefault();
                if (!confirm('Are you sure you want to cancel this registration?')) return;
                var id = $(this).data('id');
                $.post(mscData.ajaxUrl, {
                    action: 'msc_cancel_registration',
                    nonce:  mscData.nonce,
                    reg_id: id
                }, function (res) {
                    if (res.success) location.reload();
                    else alert(res.data.message || 'Error cancelling.');
                });
            });

            $('#msc-profile-photo-input').on('change', function () {
                var file = this.files[0];
                if (!file) return;
                var msg = $('#msc-profile-photo-msg');
                if (!file.type.match(/^image\/(jpeg|png|webp)/)) {
                    msg.text('Only JPG, PNG or WebP allowed.').css('color', 'red');
                    return;
                }
                if (file.size > 5 * 1024 * 1024) {
                    msg.text('Photo must be under 5MB.').css('color', 'red');
                    return;
                }
                var reader = new FileReader();
                reader.onload = function (e) { $('#msc-header-avatar').attr('src', e.target.result); };
                reader.readAsDataURL(file);

                msg.text('Uploading…').css('color', '#888');
                var fd = new FormData();
                fd.append('action', 'msc_upload_profile_photo');
                fd.append('nonce',  mscData.nonce);
                fd.append('photo',  file);
                $.ajax({
                    url: mscData.ajaxUrl, type: 'POST', data: fd,
                    processData: false, contentType: false,
                    success: function (res) {
                        if (res.success) {
                            msg.text('✓ Photo updated').css('color', 'green');
                            $('#msc-header-avatar').attr('src', res.data.url);
                            $('#msc-remove-profile-photo').show();
                        } else {
                            msg.text(res.data.message || 'Upload failed.').css('color', 'red');
                        }
                    },
                    error: function () { msg.text('Network error.').css('color', 'red'); }
                });
            });

            $('#msc-remove-profile-photo').on('click', function () {
                var btn = $(this);
                btn.prop('disabled', true).text('Removing…');
                $.post(mscData.ajaxUrl, { action: 'msc_remove_profile_photo', nonce: mscData.nonce },
                    function (res) {
                        if (res.success) {
                            location.reload();
                        } else {
                            btn.prop('disabled', false).text('Remove photo');
                            $('#msc-profile-photo-msg').text('Failed to remove photo.').css('color', 'red');
                        }
                    }
                );
            });

            $('#msc-save-profile').on('click', function (e) {
                e.preventDefault();

                var profileRequired = [
                    { id: '#pe_first_name', label: 'First Name' },
                    { id: '#pe_last_name',  label: 'Last Name' },
                    { id: '#pe_phone',      label: 'Phone Number' },
                    { id: '#pe_address1',   label: 'Street Address' },
                    { id: '#pe_city',       label: 'City / Town' },
                    { id: '#pe_province',   label: 'Province' },
                    { id: '#pe_postcode',   label: 'Postal Code' },
                ];
                for (var i = 0; i < profileRequired.length; i++) {
                    if (!$(profileRequired[i].id).val().trim()) {
                        $('#msc-profile-msg').text(profileRequired[i].label + ' is required.').css('color', 'red').show();
                        $(profileRequired[i].id).focus();
                        return;
                    }
                }

                var btn = $(this);
                btn.prop('disabled', true).text('Saving…');

                var fd = new FormData();
                fd.append('action',       'msc_update_profile');
                fd.append('nonce',        mscData.nonce);
                fd.append('first_name',   $('#pe_first_name').val());
                fd.append('last_name',    $('#pe_last_name').val());
                fd.append('display_name', $('#pe_display_name').val());
                fd.append('email',        $('#pe_email').val());
                fd.append('msc_birthday', $('#pe_birthday').val());
                fd.append('phone',        $('#pe_phone').val());
                fd.append('msc_msa_licence',        $('#pe_msa_licence').val());
                fd.append('msc_medical_aid',        $('#pe_medical_aid').val());
                fd.append('msc_medical_aid_number', $('#pe_medical_aid_number').val());
                fd.append('msc_gender',             $('#pe_gender').val());
                fd.append('msc_address1', $('#pe_address1').val());
                fd.append('msc_city',     $('#pe_city').val());
                fd.append('msc_province', $('#pe_province').val());
                fd.append('msc_postcode', $('#pe_postcode').val());
                fd.append('msc_emergency_name',  $('#pe_emergency_name').val());
                fd.append('msc_emergency_phone', $('#pe_emergency_phone').val());
                fd.append('msc_emergency_rel',   $('#pe_emergency_rel').val());
                fd.append('msc_pit_crew_1', $('#pe_pit_crew_1').val());
                fd.append('msc_pit_crew_2', $('#pe_pit_crew_2').val());
                fd.append('msc_sponsors',   $('#pe_sponsors').val().substring(0, 33));
                
                if ($('#pe_password').val()) {
                    fd.append('password',  $('#pe_password').val());
                    fd.append('password2', $('#pe_password2').val());
                }

                $.ajax({
                    url:         mscData.ajaxUrl,
                    type:        'POST',
                    data:        fd,
                    processData: false,
                    contentType: false,
                    success: function (res) {
                        $('#msc-profile-msg').text(res.data.message).css('color', res.success ? 'green' : 'red').show();
                        btn.prop('disabled', false).text('Save Changes');
                        if (res.success) {
                            setTimeout(function(){ location.reload(); }, 1500);
                        }
                    },
                    error: function () {
                        $('#msc-profile-msg').text('Network error.').css('color', 'red').show();
                        btn.prop('disabled', false).text('Save Changes');
                    }
                });
            });
        }
    };

    // ─── Entry edit helpers ───────────────────────────────────────────

    function calcEntryFee(baseFee, pricing, primaryId, additionalIds) {
        var total       = baseFee;
        var primaryData = pricing[primaryId];
        var globalOvr   = (primaryData && primaryData.override !== null && primaryData.override !== undefined)
                          ? parseFloat(primaryData.override) : null;
        if (primaryData) total += parseFloat(primaryData.primary_fee || 0);
        for (var i = 0; i < additionalIds.length; i++) {
            var cid = additionalIds[i];
            var cd  = pricing[cid];
            if (!cd) continue;
            var af = 0;
            if (cd.exempt == 1)           af = parseFloat(cd.additional_fee || 0);
            else if (globalOvr !== null)   af = globalOvr;
            else                           af = parseFloat(cd.additional_fee || 0);
            total += af;
        }
        return Math.round(total * 100) / 100;
    }

    function updateEditFeeSummary() {
        var panel = $('.msc-entry-edit-panel');
        if (!panel.length) return;
        var pricing     = panel.data('pricing');
        var baseFee     = parseFloat(panel.data('base_fee'));
        var originalFee = parseFloat(panel.data('original_fee'));

        var primaryId = parseInt($('#msc-edit-primary-class').val()) || 0;
        if (!primaryId) {
            $('#msc-edit-fee-summary').html('<span style="color:#888">Select a primary class to see the fee.</span>');
            $('#msc-edit-pop-wrap').hide();
            $('#msc-edit-save-btn').prop('disabled', false);
            return;
        }

        var additionalIds = [];
        $('.msc-edit-additional:checked:not(:disabled)').each(function() {
            additionalIds.push(parseInt($(this).val()));
        });

        var newFee = calcEntryFee(baseFee, pricing, primaryId, additionalIds);
        var diff   = Math.round((newFee - originalFee) * 100) / 100;

        var diffHtml = diff > 0.005
            ? '<span style="color:#d63638;font-weight:600">+ R ' + diff.toFixed(2) + ' owed — upload proof of payment below</span>'
            : '<span style="color:#27ae60;font-weight:600">No additional payment required</span>';

        var html = '<table style="width:100%;border-collapse:collapse;font-size:13px">'
            + '<tr><td style="padding:4px 8px;color:#888">Previously paid</td><td style="padding:4px 8px;text-align:right">R ' + originalFee.toFixed(2) + '</td></tr>'
            + '<tr><td style="padding:4px 8px;color:#888">New total</td><td style="padding:4px 8px;text-align:right">R ' + newFee.toFixed(2) + '</td></tr>'
            + '<tr style="border-top:1px solid #eee"><td style="padding:6px 8px">Difference</td><td style="padding:6px 8px;text-align:right">' + diffHtml + '</td></tr>'
            + '</table>';
        $('#msc-edit-fee-summary').html(html);

        if (diff > 0.005) {
            $('#msc-edit-pop-wrap').show();
        } else {
            $('#msc-edit-pop-wrap').hide();
            $('#msc-edit-pop-file').val('');
        }

        if (diff < -0.005) {
            $('#msc-edit-msg').text('You cannot reduce your entry below the amount already paid.').css('color', '#d63638').show();
            $('#msc-edit-save-btn').prop('disabled', true);
        } else {
            $('#msc-edit-msg').hide();
            $('#msc-edit-save-btn').prop('disabled', false);
        }
    }

    function renderEntryEditPanel(card, data) {
        var reg         = data.reg;
        var classes     = data.event_classes;
        var pricing     = data.pricing;
        var baseFee     = parseFloat(data.base_fee);
        var originalFee = parseFloat(reg.entry_fee);
        var vehicles    = data.user_vehicles || [];

        // Build maps from current selection
        var currentPrimary    = 0;
        var currentAdditional = [];
        var currentVehicleMap = {}; // class_id → vehicle_id
        for (var i = 0; i < data.current_classes.length; i++) {
            var cc = data.current_classes[i];
            if (cc.is_primary) currentPrimary = cc.class_id;
            else currentAdditional.push(cc.class_id);
            currentVehicleMap[cc.class_id] = cc.vehicle_id;
        }

        // Helper: build vehicle <option> list
        function vehicleOptions(selectedId) {
            if (!vehicles.length) return '<option value="0">No vehicles in garage</option>';
            var html = '';
            for (var v = 0; v < vehicles.length; v++) {
                var veh = vehicles[v];
                html += '<option value="' + veh.id + '"' + (veh.id === selectedId ? ' selected' : '') + '>'
                    + $('<span>').text(veh.label).html() + '</option>';
            }
            return html;
        }

        // Group classes by vtype for organised dropdowns
        var editGroups = {}, editGroupOrder = [];
        classes.forEach(function(cls) {
            var g = cls.vtype || '';
            if (!editGroups[g]) { editGroups[g] = []; editGroupOrder.push(g); }
            editGroups[g].push(cls);
        });

        // Primary class options (grouped by vtype when multiple types present)
        var primaryOptions = '';
        if (editGroupOrder.length > 1) {
            editGroupOrder.forEach(function(g) {
                primaryOptions += '<optgroup label="' + $('<span>').text(g || 'Other').html() + '">';
                editGroups[g].forEach(function(cls) {
                    primaryOptions += '<option value="' + cls.id + '"' + (cls.id === currentPrimary ? ' selected' : '') + '>'
                        + $('<span>').text(cls.name).html() + '</option>';
                });
                primaryOptions += '</optgroup>';
            });
        } else {
            classes.forEach(function(cls) {
                primaryOptions += '<option value="' + cls.id + '"' + (cls.id === currentPrimary ? ' selected' : '') + '>'
                    + $('<span>').text(cls.name).html() + '</option>';
            });
        }

        // Helper: build one checkbox row for an additional class
        function buildAddRow(cls) {
            var p   = pricing[cls.id];
            if (p && p.primary_only == 1) return '';
            var isCurrentPrimary = (cls.id === currentPrimary);
            var chk     = (currentAdditional.indexOf(cls.id) !== -1) ? ' checked' : '';
            var dis     = isCurrentPrimary ? ' disabled' : '';
            var selVeh  = currentVehicleMap[cls.id] || (vehicles.length ? vehicles[0].id : 0);
            var vehShow = (!isCurrentPrimary && currentAdditional.indexOf(cls.id) !== -1) ? '' : 'display:none;';
            return '<div class="msc-edit-add-row" data-class="' + cls.id + '" style="margin-bottom:8px">'
                + '  <label style="display:flex;gap:8px;align-items:center;cursor:pointer;margin:0">'
                + '    <input type="checkbox" class="msc-edit-additional" value="' + cls.id + '"' + chk + dis + '>'
                + '    <span>' + $('<span>').text(cls.name).html() + '</span>'
                + '  </label>'
                + '  <div class="msc-edit-add-vehicle-wrap" style="' + vehShow + 'margin-top:6px;padding-left:26px">'
                + '    <div class="msc-field" style="max-width:360px">'
                + '      <label>Vehicle</label>'
                + '      <select class="msc-edit-add-vehicle" data-class="' + cls.id + '">'
                + vehicleOptions(selVeh)
                + '      </select>'
                + '    </div>'
                + '  </div>'
                + '</div>';
        }

        // Additional classes — grouped by vtype when multiple types present
        var addRows = '';
        if (editGroupOrder.length > 1) {
            editGroupOrder.forEach(function(g) {
                var groupRows = '';
                editGroups[g].forEach(function(cls) { groupRows += buildAddRow(cls); });
                if (groupRows) {
                    addRows += '<div style="font-size:11px;font-weight:700;color:#888;text-transform:uppercase;letter-spacing:0.5px;margin:10px 0 4px">'
                        + $('<span>').text(g || 'Other').html() + '</div>' + groupRows;
                }
            });
        } else {
            classes.forEach(function(cls) { addRows += buildAddRow(cls); });
        }
        if (!addRows) {
            addRows = '<p style="color:#888;font-size:13px;margin:0">No additional classes available.</p>';
        }

        var primaryVehicle = currentVehicleMap[currentPrimary] || (vehicles.length ? vehicles[0].id : 0);

        var panel = $('<div class="msc-entry-edit-panel" style="background:#f8f9fa;border:1px solid #dde0e5;border-radius:8px;padding:20px;margin-top:10px"></div>');
        var pitCrew1 = data.pit_crew_1 || '';
        var pitCrew2 = data.pit_crew_2 || '';

        panel.html(
            '<h4 style="margin:0 0 16px">Edit Entry: ' + $('<span>').text(reg.event_name).html() + '</h4>'
            + '<div style="margin-bottom:16px">'
            + '  <div class="msc-field">'
            + '    <label>Primary Class</label>'
            + '    <select id="msc-edit-primary-class">' + primaryOptions + '</select>'
            + '  </div>'
            + '  <div class="msc-field" style="margin-top:8px;max-width:360px">'
            + '    <label>Vehicle for Primary Class</label>'
            + '    <select id="msc-edit-primary-vehicle">' + vehicleOptions(primaryVehicle) + '</select>'
            + '  </div>'
            + '</div>'
            + '<div class="msc-field" style="margin-bottom:16px">'
            + '  <label>Additional Classes <span style="font-weight:normal;color:#888">(optional)</span></label>'
            + '  <div id="msc-edit-additional-classes" style="margin-top:8px">' + addRows + '</div>'
            + '</div>'
            + '<div id="msc-edit-conditions-wrap"></div>'
            + '<div id="msc-edit-fee-summary" style="background:#fff;border:1px solid #e0e0e0;border-radius:6px;padding:12px;margin-bottom:16px"></div>'
            + '<div id="msc-edit-pop-wrap" style="display:none;margin-bottom:16px">'
            + '  <label style="font-weight:600">Proof of Payment for amount owed <span style="color:#d63638">*</span></label>'
            + '  <input type="file" id="msc-edit-pop-file" accept="application/pdf,image/png,image/jpeg" style="display:block;margin-top:6px">'
            + '  <p style="font-size:12px;color:#888;margin:4px 0 0">PDF, PNG or JPG, max 5MB.</p>'
            + '</div>'
            + '<div style="margin-bottom:16px">'
            + '  <p class="msc-reg-section-label" style="margin-bottom:8px">Pit Crew</p>'
            + '  <div class="msc-reg-grid-2">'
            + '    <div class="msc-field"><label>Name #1</label>'
            + '      <input type="text" id="msc-edit-pit-crew-1" value="' + $('<span>').text(pitCrew1).html() + '" placeholder="Pit crew member name"></div>'
            + '    <div class="msc-field"><label>Name #2</label>'
            + '      <input type="text" id="msc-edit-pit-crew-2" value="' + $('<span>').text(pitCrew2).html() + '" placeholder="Pit crew member name"></div>'
            + '  </div>'
            + '</div>'
            + '<div id="msc-edit-msg" style="display:none;font-size:13px;margin-bottom:10px"></div>'
            + '<div style="display:flex;gap:10px;flex-wrap:wrap">'
            + '  <button type="button" id="msc-edit-save-btn" class="msc-btn" data-reg="' + reg.id + '">Save Changes</button>'
            + '  <button type="button" id="msc-edit-cancel-btn" class="msc-btn msc-btn-outline">Cancel</button>'
            + '</div>'
        );

        panel.data('pricing',      pricing);
        panel.data('base_fee',     baseFee);
        panel.data('original_fee', originalFee);

        var currentClassIds = [];
        for (var ci = 0; ci < data.current_classes.length; ci++) {
            currentClassIds.push(data.current_classes[ci].class_id);
        }

        // Render conditions only for classes NOT already enrolled (newly added classes)
        function renderEditConditions() {
            var $wrap = $('#msc-edit-conditions-wrap').empty();
            var primaryId = parseInt($('#msc-edit-primary-class').val()) || 0;
            var selectedIds = primaryId ? [primaryId] : [];
            $('.msc-edit-additional:checked:not(:disabled)').each(function() {
                selectedIds.push(parseInt($(this).val()));
            });
            selectedIds.forEach(function(classId) {
                if (currentClassIds.indexOf(classId) !== -1) return; // already enrolled
                var cls = classes.find(function(c) { return c.id == classId; });
                if (!cls || !cls.conditions || !cls.conditions.length) return;
                var $section = $('<div>').addClass('msc-class-cond-section');
                $section.append($('<p>').addClass('msc-class-cond-section-title').text(cls.name + ' — Required Declarations'));
                cls.conditions.forEach(function(cond, idx) {
                    var $group = $('<div>').addClass('msc-edit-cond-group').attr({'data-class-id': classId, 'data-idx': idx, 'data-type': cond.type});
                    if (cond.type === 'confirm') {
                        var cbId = 'msc-ecdecl-' + classId + '-' + idx;
                        var $lbl = $('<label>').addClass('msc-cond-label').attr('for', cbId);
                        $lbl.append($('<input>').attr({type:'checkbox',id:cbId,name:'msc_cdecl['+classId+']['+idx+']',value:'1'}).addClass('msc-edit-class-decl'));
                        $lbl.append($('<span>').text(cond.label));
                        $group.append($lbl);
                    } else if (cond.type === 'select_one') {
                        $group.append($('<p>').addClass('msc-cond-group-label').text(cond.label));
                        (cond.options || []).forEach(function(opt, oi) {
                            var rbId = 'msc-ecdecl-' + classId + '-' + idx + '-' + oi;
                            var $lbl = $('<label>').addClass('msc-cond-label').attr('for', rbId);
                            $lbl.append($('<input>').attr({type:'radio',id:rbId,name:'msc_cdecl['+classId+']['+idx+']',value:opt}).addClass('msc-edit-class-decl'));
                            $lbl.append($('<span>').text(opt));
                            $group.append($lbl);
                        });
                    } else if (cond.type === 'select_many') {
                        $group.append($('<p>').addClass('msc-cond-group-label').text(cond.label));
                        (cond.options || []).forEach(function(opt, oi) {
                            var cbId = 'msc-ecdecl-' + classId + '-' + idx + '-' + oi;
                            var $lbl = $('<label>').addClass('msc-cond-label').attr('for', cbId);
                            $lbl.append($('<input>').attr({type:'checkbox',id:cbId,name:'msc_cdecl['+classId+']['+idx+'][]',value:opt}).addClass('msc-edit-class-decl'));
                            $lbl.append($('<span>').text(opt));
                            $group.append($lbl);
                        });
                    }
                    $section.append($group);
                });
                $wrap.append($section);
            });
        }

        card.after(panel);
        updateEditFeeSummary();
        renderEditConditions();

        // On primary class change: disable that class in additional list, hide its vehicle wrap
        panel.on('change', '#msc-edit-primary-class', function() {
            var pv = parseInt($(this).val());
            $('.msc-edit-additional').each(function() {
                var isP = (parseInt($(this).val()) === pv);
                if (isP) {
                    $(this).prop('checked', false).prop('disabled', true);
                    $(this).closest('.msc-edit-add-row').find('.msc-edit-add-vehicle-wrap').hide();
                } else {
                    $(this).prop('disabled', false);
                }
            });
            updateEditFeeSummary();
            renderEditConditions();
        });

        // On additional checkbox change: show/hide per-class vehicle select
        panel.on('change', '.msc-edit-additional', function() {
            var wrap = $(this).closest('.msc-edit-add-row').find('.msc-edit-add-vehicle-wrap');
            if ($(this).is(':checked')) wrap.show();
            else wrap.hide();
            updateEditFeeSummary();
            renderEditConditions();
        });
    }

    // ─── Photo preview helper ─────────────────────────────────────────
    function setPhotoPreview(file) {
        if (!file || !file.type.match(/^image\//)) return;
        if (file.size > 5 * 1024 * 1024) { alert('Photo must be under 5MB.'); return; }
        photoFile = file;
        var reader = new FileReader();
        reader.onload = function (e) {
            $('#msc-photo-img').attr('src', e.target.result);
            $('#msc-photo-placeholder').hide();
            $('#msc-photo-preview').show();
        };
        reader.readAsDataURL(file);
    }

    function setEditPhotoPreview(id, file) {
        if (!file || !file.type.match(/^image\//)) return;
        if (file.size > 5 * 1024 * 1024) { alert('Photo must be under 5MB.'); return; }
        editPhotoFiles[id] = file;
        var reader = new FileReader();
        reader.onload = function (e) {
            $('#msc-edit-photo-img-' + id).attr('src', e.target.result);
            $('#msc-edit-photo-placeholder-' + id).hide();
            $('#msc-edit-photo-preview-' + id).show();
        };
        reader.readAsDataURL(file);
    }

    msc.init();

    // Lightbox for event featured image
    $(document).on('click', '.msc-lightbox-trigger', function (e) {
        e.preventDefault();
        var src = $(this).attr('href');
        var $overlay = $('<div class="msc-lightbox-overlay"><img src="' + src + '"></div>');
        $('body').append($overlay);
        $overlay.on('click', function () { $(this).remove(); });
    });
    $(document).on('keydown', function (e) {
        if (e.key === 'Escape') { $('.msc-lightbox-overlay').remove(); }
    });
});
