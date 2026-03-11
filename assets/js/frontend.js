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
        totalFee:       0,
        allVehicles:    [],     // all eligible vehicles from server
        primaryClassId: null,
        primaryVehicleId: null,
        additionalRows: [],     // array of {classId, vehicleId, rowId}
        rowCounter:     0,

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
                $sel.find('option:not(:first)').remove();
                classes.forEach(function(cls) {
                    var compatible = !cls.vtype || availableVtypes[cls.vtype];
                    if (compatible) {
                        $sel.append($('<option>').val(cls.id).text(cls.name));
                    }
                });
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
                if (!classId) {
                    $('#msc-primary-vehicle-wrap').hide();
                    $('#msc-additional-classes-wrap').hide();
                    msc.updateFees();
                    msc.checkStep1();
                    return;
                }
                populateVehicleSelect($('#msc-primary-vehicle-select'), classId, null);
                $('#msc-primary-vehicle-wrap').show();
                $('#msc-additional-classes-wrap').hide();
                msc.updateFees();
                msc.checkStep1();
            });

            // Primary vehicle change
            $('#msc-primary-vehicle-select').on('change', function() {
                var vid = $(this).val();
                msc.primaryVehicleId = vid ? parseInt(vid) : null;
                if (msc.primaryVehicleId) {
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
                    $('#msc-additional-classes-wrap').hide();
                }
                msc.updateFees();
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

                classes.forEach(function(cls) {
                    if (cls.primary_only) return; // Skip primary-only classes
                    var isUsed = usedIds.indexOf(cls.id) !== -1;
                    $classSel.append(
                        $('<option>').val(cls.id).text(cls.name).prop('disabled', isUsed)
                    );
                });

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

                if (!classId) { $vehicleSel.hide().val(''); msc.updateFees(); msc.checkStep1(); return; }
                populateVehicleSelect($vehicleSel, classId, null);
                $vehicleSel.show();

                // Disable the selected class in all OTHER additional rows' dropdowns
                msc.refreshAdditionalClassOptions();
                msc.updateFees();
                msc.checkStep1();
            });

            // Additional vehicle select change
            $(document).on('change', '.msc-add-vehicle-sel', function() {
                var rowId = parseInt($(this).data('row'));
                var vid   = $(this).val();
                var row   = msc.additionalRows.find(function(r) { return r.rowId === rowId; });
                if (row) row.vehicleId = vid ? parseInt(vid) : null;
                msc.updateFees();
                msc.checkStep1();
            });

            // Remove additional row
            $(document).on('click', '.msc-add-row-remove', function() {
                var rowId = parseInt($(this).data('row'));
                $('#msc-add-row-' + rowId).remove();
                msc.additionalRows = msc.additionalRows.filter(function(r) { return r.rowId !== rowId; });
                msc.refreshAdditionalClassOptions();
                msc.updateFees();
                msc.checkStep1();
            });
            // Also handle via delegated click with msc-btn-danger class on remove buttons
            $(document).on('click', '#msc-additional-rows .msc-btn-danger', function() {
                var rowId = parseInt($(this).data('row'));
                $('#msc-add-row-' + rowId).remove();
                msc.additionalRows = msc.additionalRows.filter(function(r) { return r.rowId !== rowId; });
                msc.refreshAdditionalClassOptions();
                msc.updateFees();
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

            // Enable Next only when primary class + primary vehicle are selected
            msc.checkStep1 = function() {
                var ready = !!msc.primaryClassId && !!msc.primaryVehicleId;
                // Also check all additional rows have both class and vehicle selected
                if (ready) {
                    msc.additionalRows.forEach(function(r) {
                        if (r.classId && !r.vehicleId) ready = false;
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

            // Input listeners for validation
            $(document).on('input change', '#msc-emergency-name, #msc-emergency-phone, #msc-parent-name, #msc-sig-typed, #msc-parent-sig-typed, #msc-pop-file, .msc-custom-declaration', function() {
                msc.checkRegValidity();
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
                fd.append('indemnity_method', 'signed');
                fd.append('indemnity_sig',    sig);
                fd.append('parent_sig',       parentSig);
                fd.append('is_minor',         isMinor ? 1 : 0);
                fd.append('parent_name',      $('#msc-parent-name').val());
                fd.append('emergency_name',   $('#msc-emergency-name').val());
                fd.append('emergency_phone',  $('#msc-emergency-phone').val());
                fd.append('emergency_rel',    $('#msc-emergency-rel').val());
                fd.append('pit_crew_1',       $('#msc-pit-crew-1').val());
                fd.append('pit_crew_2',       $('#msc-pit-crew-2').val());
                fd.append('notes',            $('#msc-notes').val());
                if (popFile) fd.append('pop_file', popFile);

                $.ajax({
                    url:         mscData.ajaxUrl,
                    type:        'POST',
                    data:        fd,
                    processData: false,
                    contentType: false,
                    success: function (res) {
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
                    error: function () {
                        msc.showError('Network error. Please try again.');
                        btn.prop('disabled', false).text('Submit Registration');
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
                fd.append('reg_number', $('#v_reg').val());
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
                fd.append('reg_number', $('.edit-v_reg[data-id="'   + id + '"]').val());
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
                fd.append('msc_comp_number',        $('#pe_comp_number').val());
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
