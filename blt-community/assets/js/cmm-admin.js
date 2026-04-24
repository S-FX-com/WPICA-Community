/**
 * Blt Community — shared frontend JS.
 * Handles AJAX remove-user from the My Home dashboard.
 */
(function ($) {
    'use strict';

    // Remove member (AJAX)
    $(document).on('click', '.cmm-remove-user', function (e) {
        e.preventDefault();
        var btn     = $(this);
        var homeId  = btn.data('home-id');
        var userId  = btn.data('user-id');
        var name    = btn.closest('.cmm-member-item').find('.cmm-member-name').text().trim();

        if (!confirm('Remove ' + name + ' from this home?')) return;

        btn.prop('disabled', true).text('Removing…');

        $.post(cmmData.ajax_url, {
            action:  'cmm_remove_user',
            nonce:   cmmData.nonce,
            home_id: homeId,
            user_id: userId,
        }, function (response) {
            if (response.success) {
                btn.closest('.cmm-member-item').fadeOut(300, function () { $(this).remove(); });
            } else {
                alert(response.data || 'Could not remove user.');
                btn.prop('disabled', false).text('Remove');
            }
        }).fail(function () {
            alert('Request failed. Please try again.');
            btn.prop('disabled', false).text('Remove');
        });
    });

}(jQuery));
