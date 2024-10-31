function securepaygfm_bank_select( $, path_icon, path_time, version ) {
    $( document )
        .ready(
            function() {
                function fixadminbar() {
                    if ( $( "body" )
                        .hasClass( "admin-bar" ) ) {
                        $( ".select2-dropdown--above" )
                            .css( "margin-top", "30px" );
                    }
                };

                function formatBank( bank ) {
                    if ( !bank.id ) {
                        return bank.text;
                    }
                    if ( '' === bank.element.value ) {
                        return '';
                    }

                    fixadminbar();

                    var img = path_icon + bank.element.value.toLowerCase() + '.png';
                    var img_error = path_icon + 'blank.png';
                    return $( '<div class="securepaygfm-bnklogo"><div><img onerror="this.src=\'' + img_error + '\'" src="' + img + '?v=' + version + '"></div><div>' + bank.text + '</div></div>' );
                };

                function doselect() {
                    var $target = $( "#buyer_bank_code" );
                    if ( $target.hasClass( "select2-hidden-accessible" ) ) {
                        $target.select2( 'destroy' );
                    }
                    $target.select2( {
                        templateResult: formatBank,
                        width: "100%"
                    } );
                };

                doselect();
            }
        );
};