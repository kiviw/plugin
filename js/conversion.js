jQuery(document).ready(function ($) {
    // Real-time conversion of WLD to KSH
    $('#amount_in_wld').on('input', function () {
        const amountInWLD = parseFloat($(this).val());
        const conversionRate = 210; // 1 WLD = 210 KSH
        if (!isNaN(amountInWLD)) {
            const amountInKSH = amountInWLD * conversionRate;
            $('#amount_in_ksh').val(amountInKSH.toFixed(2));
        } else {
            $('#amount_in_ksh').val('');
        }
    });
});
