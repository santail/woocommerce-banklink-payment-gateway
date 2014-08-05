jQuery(document).ready(function($) {
    $('input[name="banklink_sel_bank"]').on('change', function () {
        $('input[name="payment_method"]').attr('checked', false);
        $('#payment_method_banklink').attr('checked', true);
    });
});
