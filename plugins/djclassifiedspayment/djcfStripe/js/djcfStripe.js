function stripeValidate()
{
    var valid        = true;
    var form         = jQuery('form[name="stripepayment"]');
    var card_type    = form.find('#card_type');
    var card_number  = form.find('#card_number');
    var card_cvc     = form.find('#card_cvc');
    var exp_month    = form.find('#exp_month');
    var exp_year     = form.find('#exp_year');
    var fullusername = form.find('#fullusername');
    
    stripeResetError(form);
    
    if (fullusername.val() === '')
    {
        stripeDisplayError(form, fullusername, 'invalid-holder-name');
        valid = false;
    }
    
    if (card_type.val() === '')
    {
        stripeDisplayError(form, card_type, 'invalid-credit-card-type');
        valid = false;
    }
    
    if (Stripe.card.validateCardNumber(card_number.val()))
    {
        if (Stripe.card.cardType(card_number.val()) !== card_type.val())
        {
            stripeDisplayError(form, card_number, 'incorrect-number');
            valid = false;
        }
    }
    else
    {
        stripeDisplayError(form, card_number, 'incorrect-number');
        valid = false;
    }
    
    if ( ! Stripe.card.validateExpiry(exp_month.val(), exp_year.val()))
    {
        stripeDisplayError(form, exp_month, 'invalid-expiry-month');
        stripeDisplayError(form, exp_year, 'invalid-expiry-year');
        valid = false;
    }
    
    if ( ! Stripe.card.validateCVC(card_cvc.val()))
    {
        stripeDisplayError(form, card_cvc, 'invalid-cvc');
        valid = false;
    }
    
    if (valid === true)
    {
        Stripe.card.createToken({
            number    : card_number.val(),
            cvc       : card_cvc.val(),
            exp_month : exp_month.val(),
            exp_year  : exp_year.val(),
            name      : fullusername.val()
        }, stripeResponseHandler);
    }
}

function stripeDisplayError(form, control, error)
{
    form.find('#stripeerror')
            .show()
            .find('#' + error).show();
    control.addClass('invalid');
}

function stripeResetError(form)
{
    form.find('#stripeerror')
            .hide()
            .find('p').hide();
    form.find(':input').removeClass('invalid');
}

function stripeResponseHandler(status, response)
{
    var form = jQuery('form[name="stripepayment"]');

    if (response.error)
    {
        // Show the errors on the form
        console.log(response.error);
    }
    else 
    {
        // response contains id and card, which contains additional card details
        var token = response.id;
        // Insert the token into the form so it gets submitted to the server
        form.append(jQuery('<input type="hidden" name="stripeToken" />').val(token));
        // and submit
        form.submit();
    }
}