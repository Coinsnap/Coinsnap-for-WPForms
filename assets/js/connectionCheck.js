jQuery(function ($) {
    
    let connectionCheckElement = '';
    
    if($('.coinsnap-notices').length){
        connectionCheckElement = '.coinsnap-notices';
    }
    
    if(connectionCheckElement !== ''){
    
        let ajaxurl = coinsnap_ajax['ajax_url'];
        let data = {
            action: 'coinsnap_connection_handler',
            form_id: coinsnap_ajax['form_id'],
            _wpnonce: coinsnap_ajax['nonce']
        };

        jQuery.post( ajaxurl, data, function( response ){

            connectionCheckResponse = $.parseJSON(response);
            let resultClass = (connectionCheckResponse.result === true)? 'success' : 'error';
            $connectionCheckMessage = '<div id="coinsnapConnectionTopStatus" class="message '+resultClass+' notice" style="margin-top: 10px;"><p>'+ connectionCheckResponse.message +'</p></div>';

            $(connectionCheckElement).after($connectionCheckMessage);

            if($('#coinsnapConnectionStatus').length){
                $('#coinsnapConnectionStatus').html('<span class="'+resultClass+'">'+ connectionCheckResponse.message +'</span>');
            }
        });
    }
    
    function getCookie(name) {
        const value = `; ${document.cookie}`;
        const parts = value.split(`; ${name}=`);
        if (parts.length === 2) return parts.pop().split(';').shift();
    }

    function setCookie(name, value, days) {
        const expDate = new Date(Date.now() + days * 86400000);
        const expires = "expires=" + expDate.toUTCString();
        document.cookie = name + "=" + value + ";" + expires + ";path=/";
    }
});

