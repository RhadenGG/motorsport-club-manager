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
                var $cards = $('<div>').addClass('msc-vehicle-cards');
                $.each(res.data, function (i, v) {
                    var icons = {Car:'🚗',Bike:'🚲',Motorcycle:'🏍',Quad:'🛻',Kart:'🏎',Truck:'🚚',Other:'🚙'};
                    var parts = v.label.split(' — ');
                    var $card = $('<div>').addClass('msc-vehicle-card').attr('data-id', v.id).attr('data-class_id', v.class_id);
                    $card.append($('<div>').addClass('msc-vehicle-card-icon').text(icons[parts[1]] || '🚗'));
                    $card.append($('<div>').addClass('msc-vehicle-card-title').text(v.title));
                    $card.append($('<div>').addClass('msc-vehicle-card-sub').text(parts[0]));
                    $card.append($('<span>').addClass('msc-vehicle-card-class').text(v.class));
                    $cards.append($card);
                });
                $('#msc-vehicles-list').empty().append($cards).show();
            });
        },

        // ─── Registration flow ────────────────────────────────────────
        bindRegistration: function () {
            $(document).on('click', '.msc-vehicle-card', function () {
                $('.msc-vehicle-card').removeClass('selected');
                $(this).addClass('selected');
                msc.vehicleId = $(this).data('id');
                msc.classId   = $(this).data('class_id');
                $('#msc-step1-next').prop('disabled', false);
            });

            $('#msc-step1-next').on('click', function () {
                if (!msc.vehicleId) return;
                var card  = $('.msc-vehicle-card.selected');
                var vname = card.find('.msc-vehicle-card-title').text();
                var vsub  = card.find('.msc-vehicle-card-sub').text();
                var vcls  = card.find('.msc-vehicle-card-class').text();
                var $table = $('<table>');
                $table.append($('<tr>').append($('<td>').text('Vehicle'), $('<td>').append($('<strong>').text(vname))));
                $table.append($('<tr>').append($('<td>').text('Details'), $('<td>').text(vsub)));
                $table.append($('<tr>').append($('<td>').text('Class'), $('<td>').text(vcls)));
                $('#msc-summary').empty().append($table);
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
                fd.append('class_id',         msc.classId || 0);
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

            // Proof of Payment (if field exists)
            if ($('#msc-pop-file').length) {
                if (!$('#msc-pop-file')[0].files[0]) isValid = false;
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
        function populateClassDropdown($select, vehicleType, selectedId) {
            var classes = (mscData.classes && mscData.classes[vehicleType]) ? mscData.classes[vehicleType] : [];
            $select.empty();
            if (!classes.length) {
                $select.append($('<option>').val('').text('No classes available')).prop('disabled', true);
                return;
            }
            $select.append($('<option>').val('').text('Select class…')).prop('disabled', false);
            $.each(classes, function(i, cls) {
                var $opt = $('<option>').val(cls.id).text(cls.name);
                if (selectedId && cls.id == selectedId) $opt.prop('selected', true);
                $select.append($opt);
            });
        }

        // Add form — type change
        $(document).on('change', '#v_type', function() {
            populateClassDropdown($('#v_class'), $(this).val(), 0);
        });

        // Edit form — type change
        $(document).on('change', '.edit-v_type', function() {
            var id = $(this).data('id');
            var $classSelect = $('.edit-v_class[data-id="' + id + '"]');
            var currentId = parseInt($classSelect.data('current'), 10) || 0;
            populateClassDropdown($classSelect, $(this).val(), currentId);
        });

        // On page load, populate edit form class dropdowns for existing vehicles
        $('.edit-v_type').each(function() {
            var id = $(this).data('id');
            var $classSelect = $('.edit-v_class[data-id="' + id + '"]');
            var currentId = parseInt($classSelect.data('current'), 10) || 0;
            if ($(this).val()) {
                populateClassDropdown($classSelect, $(this).val(), currentId);
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
