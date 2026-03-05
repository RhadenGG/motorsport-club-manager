/* Motorsport Club — Frontend JS */
jQuery(function ($) {

    var photoFile = null;
    var editPhotoFiles = {};

    var msc = {
        eventId:   null,
        vehicleId: null,
        sigPad:    null,
        parentSigPad: null,
        sigType:   'draw',
        parentSigType: 'draw',

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
                if (!res.success || !res.data.length) {
                    $('#msc-vehicles-empty').show();
                    return;
                }
                var html = '<div class="msc-vehicle-cards">';
                $.each(res.data, function (i, v) {
                    var icons = {Car:'🚗',Bike:'🚲',Motorcycle:'🏍',Quad:'🛻',Kart:'🏎',Truck:'🚚',Other:'🚙'};
                    var parts = v.label.split(' — ');
                    html += '<div class="msc-vehicle-card" data-id="' + v.id + '">' +
                        '<div class="msc-vehicle-card-icon">' + (icons[parts[1]] || '🚗') + '</div>' +
                        '<div class="msc-vehicle-card-title">' + v.title + '</div>' +
                        '<div class="msc-vehicle-card-sub">' + parts[0] + '</div>' +
                        '<span class="msc-vehicle-card-class">' + v.class + '</span>' +
                        '</div>';
                });
                html += '</div>';
                $('#msc-vehicles-list').html(html).show();
            });
        },

        // ─── Registration flow ────────────────────────────────────────
        bindRegistration: function () {
            $(document).on('click', '.msc-vehicle-card', function () {
                $('.msc-vehicle-card').removeClass('selected');
                $(this).addClass('selected');
                msc.vehicleId = $(this).data('id');
                $('#msc-step1-next').prop('disabled', false);
            });

            $('#msc-step1-next').on('click', function () {
                if (!msc.vehicleId) return;
                var card  = $('.msc-vehicle-card.selected');
                var vname = card.find('.msc-vehicle-card-title').text();
                var vsub  = card.find('.msc-vehicle-card-sub').text();
                var vcls  = card.find('.msc-vehicle-card-class').text();
                $('#msc-summary').html(
                    '<table>' +
                    '<tr><td>Vehicle</td><td><strong>' + vname + '</strong></td></tr>' +
                    '<tr><td>Details</td><td>' + vsub + '</td></tr>' +
                    '<tr><td>Class</td><td>' + vcls + '</td></tr>' +
                    '</table>'
                );
                $('#msc-step-1').hide();
                $('#msc-step-2').show();
                // Initialize button state
                msc.checkRegValidity();
                // Wait for DOM to paint before sizing canvas
                setTimeout(function() { msc.initSignaturePads(); }, 100);
            });

            $('#msc-step2-back').on('click', function () {
                $('#msc-step-2').hide();
                $('#msc-step-1').show();
                $('#msc-reg-error').hide();
            });

            // Input listeners for validation
            $(document).on('input change', '#msc-emergency-name, #msc-emergency-phone, #msc-parent-name, #msc-sig-typed, #msc-parent-sig-typed, #msc-pop-file', function() {
                msc.checkRegValidity();
            });

            $('input[name="msc_ind_method"]').on('change', function () {
                if ($(this).val() === 'sign') {
                    $('#msc-sig-panel').show();
                    if ($('#msc-reg-wrap').data('minor') == 1) {
                        $('#msc-parent-sig-panel').show();
                    }
                    $('#msc-bring-panel').hide();
                    setTimeout(function() { msc.initSignaturePads(); }, 100);
                } else {
                    $('#msc-sig-panel').hide();
                    $('#msc-parent-sig-panel').hide();
                    $('#msc-bring-panel').show();
                }
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
                var method = $('input[name="msc_ind_method"]:checked').val();
                if (!method) { msc.showError('Please select an indemnity option.'); return; }
                
                var sig = '';
                var parentSig = '';
                var isMinor = $('#msc-reg-wrap').data('minor') == 1;

                if (method === 'sign') {
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
                }

                // Proof of Payment
                var popFile = $('#msc-pop-file')[0] ? $('#msc-pop-file')[0].files[0] : null;
                if ($('#msc-pop-file').length && !popFile) {
                    msc.showError('Please upload your Proof of Payment PDF.');
                    return;
                }
                
                var btn = $(this);
                btn.prop('disabled', true).text('Submitting…');

                var fd = new FormData();
                fd.append('action',           'msc_submit_registration');
                fd.append('nonce',            mscData.nonce);
                fd.append('event_id',         msc.eventId);
                fd.append('vehicle_id',       msc.vehicleId);
                fd.append('indemnity_method', method === 'sign' ? 'signed' : 'bring');
                fd.append('indemnity_sig',    sig);
                fd.append('parent_sig',       parentSig);
                fd.append('is_minor',         isMinor ? 1 : 0);
                fd.append('parent_name',      $('#msc-parent-name').val());
                fd.append('emergency_name',   $('#msc-emergency-name').val());
                fd.append('emergency_phone',  $('#msc-emergency-phone').val());
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
                            $('#msc-reg-wrap').html('<div class="msc-notice msc-notice-success msc-success-big">' + icon + ' ' + res.data.message + '</div>');
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
                    msc.sigPad.onEnd = function() { msc.checkRegValidity(); };
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
                    msc.parentSigPad.onEnd = function() { msc.checkRegValidity(); };
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

            // Indemnity Method
            var method = $('input[name="msc_ind_method"]:checked').val();
            if (!method) {
                isValid = false;
            } else if (method === 'sign') {
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
            }

            // Proof of Payment (if field exists)
            if ($('#msc-pop-file').length) {
                if (!$('#msc-pop-file')[0].files[0]) isValid = false;
            }

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
                fd.append('class_id',   $('#v_class').val());
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

        // ── Class filtering by vehicle type ──────────────────────────
        function populateClassDropdown($select, vehicleType, selectedClass) {
            var classes = (mscData.classes && mscData.classes[vehicleType]) ? mscData.classes[vehicleType] : [];
            $select.empty();
            if (!classes.length) {
                $select.append('<option value="">No classes available</option>').prop('disabled', true);
                return;
            }
            $select.append('<option value="">Select class…</option>').prop('disabled', false);
            $.each(classes, function(i, name) {
                var selected = (name === selectedClass) ? ' selected' : '';
                $select.append('<option value="' + name + '"' + selected + '>' + name + '</option>');
            });
        }

        // Add form — type change
        $(document).on('change', '#v_type', function() {
            populateClassDropdown($('#v_class'), $(this).val(), '');
        });

        // Edit form — type change
        $(document).on('change', '.edit-v_type', function() {
            var id = $(this).data('id');
            var $classSelect = $('.edit-v_class[data-id="' + id + '"]');
            var currentClass = $classSelect.data('current') || '';
            populateClassDropdown($classSelect, $(this).val(), currentClass);
        });

        // On page load, populate edit form class dropdowns for existing vehicles
        $('.edit-v_type').each(function() {
            var id = $(this).data('id');
            var $classSelect = $('.edit-v_class[data-id="' + id + '"]');
            var currentClass = $classSelect.data('current') || '';
            if ($(this).val()) {
                populateClassDropdown($classSelect, $(this).val(), currentClass);
            }
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
                fd.append('class_id',   $('.edit-v_class[data-id="' + id + '"]').val());
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

            $('#msc-save-profile').on('click', function (e) {
                e.preventDefault();
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
                fd.append('msc_licence',  $('#pe_licence').val());
                fd.append('msc_address1', $('#pe_address1').val());
                fd.append('msc_city',     $('#pe_city').val());
                fd.append('msc_province', $('#pe_province').val());
                fd.append('msc_postcode', $('#pe_postcode').val());
                fd.append('msc_emergency_name',  $('#pe_emergency_name').val());
                fd.append('msc_emergency_phone', $('#pe_emergency_phone').val());
                fd.append('msc_emergency_rel',   $('#pe_emergency_rel').val());
                
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
});
