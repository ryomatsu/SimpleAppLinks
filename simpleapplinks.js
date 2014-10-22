jQuery(document).ready(function(){
    var apps = jQuery('div.applink-ajax');
    jQuery.each(apps, function(i, val) {
        var appLinkUrl = jQuery(val).attr('data-url');
        jQuery.get(ajaxurl, {
            action: 'simpleapplinks_get_html',
            url: appLinkUrl
        }, function(data) {
            jQuery(val).html(data);
        });
    });
    return;
});
