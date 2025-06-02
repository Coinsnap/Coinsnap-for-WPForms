jQuery(document).ready(function ($) {
    
    if($('#wpforms-panel-field-coinsnap-coinsnap_provider').length){
        
        setProvider();
        
        $('#wpforms-panel-field-coinsnap-coinsnap_provider').change(function(){
            setProvider();
        });
    }
    
    function setProvider(){
        if($('#wpforms-panel-field-coinsnap-coinsnap_provider').val() === 'coinsnap'){
            $('.wpforms-panel-field.btcpay').hide();
            $('.wpforms-panel-field.btcpay input').removeAttr('required');
            $('.wpforms-panel-field.coinsnap').show();
            $('.wpforms-panel-field.coinsnap input').attr('required','required');
        }
        else {
            $('.wpforms-panel-field.coinsnap').hide();
            $('.wpforms-panel-field.coinsnap input').removeAttr('required');
            $('.wpforms-panel-field.btcpay').show();
            $('.wpforms-panel-field.btcpay input').attr('required','required');
        }
    }
    
    function isValidUrl(serverUrl) {
        try {
            const url = new URL(serverUrl);
            if (url.protocol !== 'https:' && url.protocol !== 'http:') {
                return false;
            }
	}
        catch (e) {
            console.error(e);
            return false;
	}
        return true;
    }

    $('.btcpay-apikey-link').click(function(e) {
        e.preventDefault();
        const host = $('#wpforms-panel-field-coinsnap-btcpay_server_url').val();
	if (isValidUrl(host)) {
            let data = {
                'action': 'btcpay_server_apiurl_handler',
                'form_id': coinsnap_ajax.form_id,
                'host': host,
                'apiNonce': coinsnap_ajax.nonce
            };
            
            $.post(coinsnap_ajax.ajax_url, data, function(response) {
                if (response.data.url) {
                    window.location = response.data.url;
		}
            }).fail( function() {
		alert('Error processing your request. Please make sure to enter a valid BTCPay Server instance URL.')
            });
	}
        else {
            alert('Please enter a valid url including https:// in the BTCPay Server URL input field.')
        }
    });
});

