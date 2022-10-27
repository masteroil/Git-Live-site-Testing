
var closte_open_welcome_pointer = new Array();
closte_open_welcome_pointer[1] = {
    'element': 'toplevel_page_closte_main_menu',
    'options': {
        'content': '<h3>Here are some useful features</h3><h4>Development Mode</h4><p>Recommended for development or debugging a possible issue.</p><h4>CDN</h4><p>Clear CDN cache when needed.</p>',
        'position': { 'edge': 'top', 'align': 'center' },
        'close': function () {
            var data = {
                'action': 'closte_welcome_tour',
            };

            jQuery.ajax({
                type: 'POST',
                url: ajaxurl,
                async: true,
                data: data,
            });
        }
    }
};


jQuery(window).load(function ($) {

    if (typeof (jQuery().pointer) != 'undefined') {
        var pointerid = 1;
        if (jQuery('.wp-pointer').is(":visible")) { // if a pointer is already open...
            var openid = jQuery('.wp-pointer:visible').attr("id").replace('wp-pointer-', ''); // ... note its id
            jQuery('#' + closte_open_welcome_pointer[openid].element).pointer('close'); // ... and close it
            pointerid = parseInt(openid) + 1;
        }

        if (closte_open_welcome_pointer[pointerid] != undefined) { // check if next pointer exists
            jQuery('#' + closte_open_welcome_pointer[pointerid].element).pointer(closte_open_welcome_pointer[pointerid].options).pointer('open');	// and open it
            var nextid = pointerid + 1;
            if (closte_open_welcome_pointer[nextid] != undefined) { // check if another next pointer exists
                jQuery('#wp-pointer-' + pointerid + " .wp-pointer-buttons").append('Next'); // and if so attach a "next" link to the current pointer
            }
        }
    }
});