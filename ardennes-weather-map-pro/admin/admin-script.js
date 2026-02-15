/**
 * Ardennes Weather Map Pro - Admin Script
 *
 * @package Ardennes_Weather_Map_Pro
 */
(function ($) {
    'use strict';

    const form         = $('#awmp-city-form');
    const formTitle    = $('#awmp-form-title');
    const submitBtn    = $('#awmp-submit-btn');
    const cancelBtn    = $('#awmp-cancel-btn');
    const messageBox   = $('#awmp-form-message');
    const cityIdField  = $('#awmp-city-id');

    /**
     * Reset the form to "add" mode.
     */
    function resetForm() {
        form[0].reset();
        cityIdField.val('');
        formTitle.text('Ajouter une ville');
        submitBtn.text('Enregistrer la ville');
        cancelBtn.hide();
        messageBox.empty();
    }

    /**
     * Show a feedback message.
     */
    function showMessage(container, text, isError) {
        const cssClass = isError ? 'awmp-msg-error' : 'awmp-msg-success';
        $(container).html('<div class="' + cssClass + '">' + text + '</div>');
        setTimeout(function () {
            $(container).empty();
        }, 6000);
    }

    /**
     * Handle form submission (create / update).
     */
    form.on('submit', function (e) {
        e.preventDefault();

        const data = {
            action:              'awmp_save_city',
            nonce:               awmpAdmin.nonce,
            city_id:             cityIdField.val(),
            city_name:           $('#awmp-city-name').val(),
            postal_code:         $('#awmp-postal-code').val(),
            latitude:            $('#awmp-latitude').val(),
            longitude:           $('#awmp-longitude').val(),
            morning_temp:        $('#awmp-morning-temp').val(),
            morning_condition:   $('#awmp-morning-condition').val(),
            afternoon_temp:      $('#awmp-afternoon-temp').val(),
            afternoon_condition: $('#awmp-afternoon-condition').val()
        };

        submitBtn.prop('disabled', true).text('Enregistrement...');

        $.post(awmpAdmin.ajaxUrl, data, function (response) {
            if (response.success) {
                showMessage('#awmp-form-message', response.data.message, false);
                setTimeout(function () {
                    location.reload();
                }, 800);
            } else {
                showMessage('#awmp-form-message', response.data.message, true);
                submitBtn.prop('disabled', false).text('Enregistrer la ville');
            }
        }).fail(function () {
            showMessage('#awmp-form-message', 'Erreur de communication avec le serveur.', true);
            submitBtn.prop('disabled', false).text('Enregistrer la ville');
        });
    });

    /**
     * Handle "Edit" button click.
     */
    $(document).on('click', '.awmp-edit-btn', function () {
        const city = JSON.parse($(this).attr('data-city'));

        cityIdField.val(city.id);
        $('#awmp-city-name').val(city.city_name);
        $('#awmp-postal-code').val(city.postal_code || '');
        $('#awmp-latitude').val(city.latitude);
        $('#awmp-longitude').val(city.longitude);
        $('#awmp-morning-temp').val(city.morning_temp);
        $('#awmp-morning-condition').val(city.morning_condition);
        $('#awmp-afternoon-temp').val(city.afternoon_temp);
        $('#awmp-afternoon-condition').val(city.afternoon_condition);

        formTitle.text('Modifier : ' + city.city_name);
        submitBtn.text('Mettre à jour');
        cancelBtn.show();

        $('html, body').animate({ scrollTop: form.offset().top - 50 }, 300);
    });

    /**
     * Handle "Delete" button click.
     */
    $(document).on('click', '.awmp-delete-btn', function () {
        const cityId   = $(this).data('id');
        const cityName = $(this).data('name');

        if (!confirm('Supprimer la ville "' + cityName + '" ?')) {
            return;
        }

        $.post(awmpAdmin.ajaxUrl, {
            action:  'awmp_delete_city',
            nonce:   awmpAdmin.nonce,
            city_id: cityId
        }, function (response) {
            if (response.success) {
                showMessage('#awmp-form-message', response.data.message, false);
                setTimeout(function () {
                    location.reload();
                }, 800);
            } else {
                showMessage('#awmp-form-message', response.data.message, true);
            }
        }).fail(function () {
            showMessage('#awmp-form-message', 'Erreur de communication avec le serveur.', true);
        });
    });

    /**
     * Cancel editing.
     */
    cancelBtn.on('click', function () {
        resetForm();
    });

    /**
     * Handle manual weather update button.
     */
    $('#awmp-manual-update-btn').on('click', function () {
        const btn      = $(this);
        const progress = $('#awmp-update-progress');
        const msgBox   = $('#awmp-update-message');

        btn.prop('disabled', true).find('.dashicons').addClass('awmp-spin');
        progress.show();
        msgBox.empty();

        $.post(awmpAdmin.ajaxUrl, {
            action: 'awmp_manual_weather_update',
            nonce:  awmpAdmin.nonce
        }, function (response) {
            progress.hide();
            btn.prop('disabled', false).find('.dashicons').removeClass('awmp-spin');

            if (response.success) {
                const hasErrors = response.data.errors && response.data.errors.length > 0;
                let html = '<div class="' + (hasErrors ? 'awmp-msg-error' : 'awmp-msg-success') + '">';
                html += '<strong>' + response.data.updated + ' ville(s) mise(s) à jour.</strong>';

                if (hasErrors) {
                    html += '<ul style="margin:6px 0 0;padding-left:18px;">';
                    response.data.errors.forEach(function (err) {
                        html += '<li>' + err + '</li>';
                    });
                    html += '</ul>';
                }

                html += '</div>';
                msgBox.html(html);

                if (response.data.updated > 0) {
                    setTimeout(function () {
                        location.reload();
                    }, 2000);
                }
            } else {
                showMessage('#awmp-update-message', response.data.message, true);
            }
        }).fail(function () {
            progress.hide();
            btn.prop('disabled', false).find('.dashicons').removeClass('awmp-spin');
            showMessage('#awmp-update-message', 'Erreur de communication avec le serveur.', true);
        });
    });

})(jQuery);
