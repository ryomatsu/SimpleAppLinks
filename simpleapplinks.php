<?php /*
*************************************************************************
Plugin Name:  SimpleAppLinks
Plugin URI:   http://loumo.jp/
Version:      0.1
Description:  add link to apps in your blog post.
Author:       ryomatsu
Author URI:   http://loumo.jp/
**************************************************************************/

require_once 'simple_html_dom.php';

class SimpleAppLinks {

    const PLUGIN_NAME = 'SimpleAppLinks';
    const PLUGIN_URI  = 'http://loumo.jp/';
    const VERSION     = '0.1';

    private $db_version = '1';
    private $table_name;

    private $option_group = 'simpleapplinks-settings';
    private $option_name = 'simpleapplinks-options-generic';
    private $option_page = 'simpleapplinks-settings';

    private $text_domain;

    private $options;

    // default options
    private $default_options = array(
        'cache-time'            => 86400,
        'use-ajax'              => false,
        'display-powerdby-link' => false,
        'display-store-badge'   => true,
    );

    function __construct() {
		$this->text_domain      = basename(dirname(__FILE__));
		load_plugin_textdomain( $this->text_domain, false, basename(dirname( __FILE__ )) . '/languages' );
        $this->init();
    }

    function init() {
        global $wpdb;
        $this->get_options();
        $this->table_name = $wpdb->prefix . 'simpleapplinks_cache';
        register_activation_hook (__FILE__, array($this, 'activate'));

        add_action('wp_enqueue_scripts', array($this, 'enqueue_style'));
        if ($this->options['use-ajax']) {
            add_action('wp_enqueue_scripts', array($this, 'enqueue_script'));
            add_action('wp_head', array($this, 'ensure_ajaxurl'), 1);
            add_action('wp_ajax_nopriv_simpleapplinks_get_html', array($this, 'get_applink_ajax'));
            add_action('wp_ajax_simpleapplinks_get_html', array($this, 'get_applink_ajax'));

            add_shortcode('applink', array($this, 'expand_shortcode_applink_ajax'));
        } else {
            add_shortcode('applink', array($this, 'expand_shortcode_applink'));
        }

        if (is_admin()) {
            //add_settings_error('general', 'settings_updated', __('hogehoge'), 'updated');
            add_action('admin_menu', array($this, 'admin_menu'));
            add_action('admin_init', array($this, 'page_init'));
            if (isset($_POST['clear-cache'])) {
                $this->delete_cache();
            }
        }
    }

    function enqueue_style() {
        wp_register_style('simpleapplinks', plugins_url('style.css', __FILE__), array(), NULL);
        wp_enqueue_style('simpleapplinks');
    }

    function enqueue_script() {
        wp_register_script('simpleapplinks', plugins_url('simpleapplinks.js', __FILE__), array('jquery'), NULL);
        wp_enqueue_script('simpleapplinks');
    }

    function ensure_ajaxurl() { ?>
<script type="text/javascript">
    //<![CDATA[
    var ajaxurl = '<?php echo admin_url('admin-ajax.php'); ?>';
    //]]>
</script>
<?php
    }

    function get_options() {
        $this->options = wp_parse_args((array)get_option($this->option_name), $this->default_options);
    }

    function admin_menu() {
        add_options_page(
            self::PLUGIN_NAME,
            self::PLUGIN_NAME,
            8,
            $this->option_page,
            array($this, 'settings_page'));
    }

    function settings_page() {
?>
<div class="wrap">
<?php if (isset($_POST['clear-cache'])) { ?>
<div class="updated">
<p><strong><?php _e('Cache has been cleared.', $this->text_domain); ?></strong></p>
</div>
<?php } ?>

<h2><?php echo self::PLUGIN_NAME . ' ' .  __('Settings'); ?></h2>
<form method="post" action="options.php">
<?php
        // This prints out all hidden setting fields
        settings_fields($this->option_group);   
        do_settings_sections($this->option_page);
        submit_button();
?>
<h2><?php _e('Cache Status', $this->text_domain, $this->text_domain); ?></h2>
<p><?php printf(__('%s rows in cache database.', $this->text_domain), $this->get_cache_count()); ?></p>
<?php submit_button(__('Save settings and clear cache', $this->text_domain), 'button-primary', 'clear-cache'); ?>
</form>
</div>
<?php 
    }

    function page_init() {
        register_setting($this->option_group, $this->option_name);

        $section = 'general';
        add_settings_section(
            $section, // ID
            __('General', $this->text_domain), // Title
            array($this, 'print_section_info'), // Callback
            $this->option_page // Page
        );

        add_settings_field(
            'cache-time', // ID
            __('Cache Time(Second)', $this->text_domain), // Title 
            array($this, 'cache_time_callback'), // Callback
            $this->option_page,
            $section // Section
        );

        add_settings_field(
            'use-ajax', 
            __('Use Ajax', $this->text_domain), 
            array($this, 'use_ajax_callback'), 
            $this->option_page,
            $section
        );

        add_settings_field(
            'display-powerdby-link', 
            __('Display PowerdBy Link', $this->text_domain), 
            array($this, 'display_powerdby_link_callback'), 
            $this->option_page,
            $section
        );

        //add_settings_field(
        //    'display-store-badge', 
        //    'Display Store Badge', 
        //    array($this, 'display_store_badge_callback'), 
        //    $this->option_page,
        //    $section
        //);
    }

    public function print_section_info () {
    }

    public function cache_time_callback() {
        printf(
            '<input type="text" id="cache-time" name="'.$this->option_name.'[cache-time]" value="%d">',
            isset($this->options['cache-time']) ? esc_attr($this->options['cache-time']) : ''
        );
    }

    public function use_ajax_callback() {
        printf(
            '<input type="checkbox" id="use-ajax" name="'.$this->option_name.'[use-ajax]"%s>',
            isset($this->options['use-ajax']) && $this->options['use-ajax'] ? ' checked' : ''
        );
    }

    public function display_powerdby_link_callback() {
        printf(
            '<input type="checkbox" id="display-powerdby-link" name="'.$this->option_name.'[display-powerdby-link]"%s>',
            isset($this->options['display-powerdby-link']) && $this->options['display-powerdby-link'] ? ' checked' : ''
        );
    }

    public function display_store_badge_callback() {
        printf(
            '<input type="checkbox" id="display-store-badge" name="'.$this->option_name.'[display-store-badge]"%s>',
            isset($this->options['display-store-badge']) && $this->options['display-store-badge'] ? ' checked' : ''
        );
    }

    function activate() {
        global $wpdb;

        $sal_db_version = get_option('sal_db_version');

        // create if version is not match
        if($installed_ver != $this->db_version) {
            $sql = 'CREATE TABLE ' . $this->table_name . ' (
                sal_id bigint(20) unsigned not null auto_increment primary key ,
                url_hash varchar(128) not null,
                data text not null,
                created_at datetime not null,
                updated_at datetime not null,
                unique key(url_hash) 
            ) CHARACTER SET "utf8"; ';

            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
            update_option('sal_db_version', $this->db_version);
        }
    }

    function expand_shortcode_applink_ajax($atts) {
        extract(shortcode_atts(array('url' => '',), $atts));
        if (!$url) return '';
        $loading = '<img src="' . plugins_url('images/loading001.gif', __FILE__) . '">';
        return '<div class="applink-ajax" data-role="applink" data-url="'.htmlspecialchars($url).'">' . $loading . '</div>';
    }

    function expand_shortcode_applink($atts) {
        extract(shortcode_atts(array('url' => '',), $atts));
        return $this->get_applink($url);
    }

    function get_applink_ajax() {
        if (basename($_SERVER['SCRIPT_NAME']) == 'admin-ajax.php' && isset($_GET['url'])) {
            echo $this->get_applink($_GET['url']);
            exit();
        }
    }

    function get_applink($url) {
        if (!$url) return '';

        $opts = array(
            'http'=>array(
                'method'=>"GET",
                'header'=>"Accept-language: en\r\n",
            )
        );

        // get cache from url
        $cache = $this->load_cache($url);
        $data = array();

        // get from website when cache is not exists or too old
        if ($cache === null || ($cache !== null && $cache->updated_at < date('Y-m-d H:i:s', time() - $this->options['cache-time']))) {
            $context = stream_context_create($opts);
            $parsed_url = parse_url($url);
            $html = file_get_contents(strip_tags($url), false, $context);
            if (!$html) { return ''; }

            // if check statucode, use $http_response_header
            $dom = str_get_html($html);
            switch ($parsed_url['host']) {
            case 'play.google.com':
                $data = $this->parse_android_app($dom);
                break;
            case 'itunes.apple.com':
                $html_title = $dom->find('title', 0);
                if (strpos($html_title->plaintext, 'Mac App Store') !== false) {
                    $data = $this->parse_mac_app($dom);
                } else {
                    $data = $this->parse_ios_app($dom);
                }
                break;
            case 'wordpress.org':
                $data = $this->parse_wordpress_plugin($dom);
                break;
            case 'chrome.google.com':
                $data = $this->parse_chrome_extension($dom);
                break;
            case 'addons.mozilla.org':
                $data = $this->parse_firefox_addon($dom);
                break;
            case 'apps.microsoft.com':
                $data = $this->parse_windows_apps($dom);
                break;
            }
            $data['class'] = str_replace('_', '-', str_replace('parse', 'applink', $data['function']));
            $data['url'] = $url;
            $update = ($cache === null) ? false : true;
            $this->save_cache($data, $update);
        } else {
            $data = unserialize($cache->data);
        }

        return $this->make_html($data);
    }

    function load_cache($url) {
        global $wpdb;
        $url_hash = $this->url_hash($url);
        $cache = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . $this->table_name . ' WHERE url_hash = %s'
            , $url_hash));

        if (!$cache) { return null; }
        return $cache;
    }

    function save_cache($data, $update=false) {
        global $wpdb;
        $set_arr = array(
            'url_hash' => $this->url_hash($data['url']),
            'data' => serialize($data),
            'updated_at' => date('c'),
        );

        // update or insert
        if ($update) {
            $wpdb->update($this->table_name, $set_arr, array('url_hash' => $set_arr['url_hash']));
        } else {
            $set_arr['created_at'] = date('c');
            $wpdb->insert($this->table_name, $set_arr);
        }
    }

    function delete_cache() {
        global $wpdb;
        $wpdb->query('delete from ' . $this->table_name);
    }

    function get_cache_count() {
        global $wpdb;
        return $wpdb->get_var('select count(url_hash) from ' . $this->table_name);
    }

    function parse_android_app($dom) {
        $title = $dom->find('div.document-title div', 0);
        $supplier = $dom->find('.details-info .document-subtitle span', 0);
        $icon  = $dom->find('.cover-image', 0);
        $iconUrl = str_replace('=w300', '=w200', $icon->src);
        $price = $dom->find('meta[itemprop=price]', 0);
        $priceText = $price->attr['content'];
        $priceText = $priceText == 0 ? __('Free', $this->text_domain) : $priceText;

        $reviewNum = $dom->find('.reviews-stats .reviews-num', 0);
        $score = $dom->find('.score-container .score', 0);
        $published = $dom->find('div.content[itemprop=datePublished]', 0);
        $download = $dom->find('div.content[itemprop=numDownloads]', 0);

        $ret = array(
            'function'    => __FUNCTION__,
            'platform'    => 'Android',
            'appname'     => $title->plaintext,
            'supplier'    => $supplier->plaintext,
            'icon'        => $iconUrl,
            'price'       => $priceText,
            'reviewCount' => trim($reviewNum->plaintext),
            'score'       => $this->rating_star(trim($score->plaintext)),
            'published'   => $published->plaintext,
            'download'    => $download->plaintext,
        );

        return $ret;
    }

    function parse_ios_app($dom) {
        $title = $dom->find('#title h1', 0);
        $supplier = $dom->find('#title h2', 0);
        $_supplier = explode(' ', $supplier->plaintext);
        unset($_supplier[0]);
        $icon  = $dom->find('#left-stack div.artwork img.artwork', 0);
        $iconUrl = $icon->getAttribute('src-swap');
        $price = $dom->find('div.price', 0);
        // 0:current version, 1:all versions
        $rating_count = $dom->find('span.rating-count', 1);
        preg_match('/[0-9]+/', $rating_count->plaintext, $match);
        $review_count = is_numeric($match[0]) ? $match[0] : 0;
        $rating = $dom->find('div.rating div', 1);
        // rating is not in html when review is a few
        $score = ($rating != null) ?  $this->rating_star(substr_count($rating->innertext(), '"rating-star"')): '';
        $published = $dom->find('li.release-date', 0);
        $download = 0; // not in html

        $ret = array(
            'function'    => __FUNCTION__,
            'platform'    => 'iOS',
            'appname'     => $title->plaintext,
            'supplier'    => implode(' ', $_supplier),
            'icon'        => $iconUrl,
            'price'       => $price->plaintext,
            'reviewCount' => $review_count,
            'score'       => trim($score),
            'published'   => preg_replace('/<span .*?<\/span>/', '', $published->innertext),
            'download'    => $download,
        );
        return $ret;
    }

    function parse_mac_app($dom) {
        $ret = $this->parse_ios_app($dom);
        $ret['function'] = __FUNCTION__;
        $ret['platform'] = 'Mac';
        return $ret;
    }

    function parse_wordpress_plugin($dom) {
        $title = $dom->find('#plugin-title h2', 0);
        $supplier = $dom->find('.plugin-contributor-info a', 0);

        // get icon
        $iconUrl = plugins_url('images/wordpress-logo.png', __FILE__);
        $url = $dom->find('.section-description a', 0);
        $_url = explode('/', $url->getAttribute('href'));
        $_iconUrl = 'http://ps.w.org/' . $_url[4] . '/assets/icon-128x128.png';
        $context = stream_context_create(array('http' => array('ignore_errors' => true)));
        $response = file_get_contents($_iconUrl, false, $context);
        preg_match('/HTTP\/1\.[0|1|x] ([0-9]{3})/', $http_response_header[0], $matches);
        if (in_array($matches[1], array('200', '304'))) {
            $iconUrl = 'data:image/png;base64,' . base64_encode($response);
        }
        $price = __('Free', $this->text_domain);
        $reviewNum = $dom->find('meta[itemprop=ratingCount]', 0);
        $score = $dom->find('meta[itemprop=ratingValue]', 0);
        $_score = $score->getAttribute('content') ? $score->getAttribute('content') : 0;
        $published = $dom->find('meta[itemprop=dateModified]', 0);
        $download = $dom->find('meta[itemprop=interactionCount]', 0);
        $_download = explode(':', $download->getAttribute('content'));

        $ret = array(
            'function'    => __FUNCTION__,
            'platform'    => 'Wordpress',
            'appname'     => $title->plaintext,
            'supplier'    => $supplier->plaintext,
            'icon'        => $iconUrl,
            'price'       => $price,
            'reviewCount' => $reviewNum->getAttribute('content'),
            'score'       => $this->rating_star($_score),
            'published'   => $published->plaintext,
            'download'    => $_download[1],
        );
        return $ret;
    }

    function parse_chrome_extension($dom) {
        $title = $dom->find('meta[itemprop=name]', 0);
        #$supplier = $dom->find('.webstore-O-P-Mb', 0);
        #$_supplier = explode(' ', $supplier->plaintext);
        $supplier = '';
        $detail = $dom->find('.webstore-test-detail-dialog', 0);
        foreach ($detail->find('div') as $div) {
            if (preg_match('/^URL: /', $div->innertext)) {
                $supplier = $div->innertext;
            }
        }

        $icon  = $dom->find('meta[itemprop=image]', 0);
        $iconUrl = $icon->getAttribute('content');
        $price = $dom->find('meta[itemprop=price]', 0);
        $priceText = $price->getAttribute('content');
        preg_match('/[0-9]+/', $priceText, $match);
        $priceText = $match[0] == 0 ? __('Free', $this->text_domain) : $priceText;

        $reviewNum = $dom->find('meta[itemprop=ratingCount]', 0);
        $rating = $dom->find('meta[itemprop=ratingValue]', 0);
        $published = '';//$dom->find('.webstore-qb-Vb-nd-bc-C-mh-nh', 0);
        $download = $dom->find('meta[itemprop=interactionCount]', 0);
        $_download = explode(':', $download->getAttribute('content'));

        $ret = array(
            'function'    => __FUNCTION__,
            'platform'    => 'Google Chrome',
            'appname'     => $title->getAttribute('content'),
            'supplier'    => $supplier,//$_supplier[count($_supplier) - 1],
            'icon'        => $iconUrl,
            'price'       => $priceText,
            'reviewCount' => $reviewNum->getAttribute('content'),
            'score'       => $this->rating_star($rating->getAttribute('content')),
            'published'   => '',//$published->plaintext,
            'download'    => $_download[1],
        );
        return $ret;
    }

    function parse_firefox_addon($dom) {
        $title = $dom->find('h1.addon span[itemprop=name]', 0);
        $supplier = $dom->find('h4.author', 0);
        $_supplier = explode(' ', strip_tags($supplier->plaintext));
        unset($_supplier[0]);
        $icon  = $dom->find('img#addon-icon', 0);
        $iconUrl = $icon->getAttribute('src');
        $price = $dom->find('meta[itemprop=price]', 0);
        $priceText = $price->getAttribute('content');
        preg_match('/[0-9]+/', $priceText, $match);
        $priceText = $match[0] == 0 ? __('Free', $this->text_domain) : $priceText;

        $reviewNum = $dom->find('span[itemprop=ratingCount]', 0);
        $rating = $dom->find('meta[itemprop=ratingValue]', 0);
        $published = $dom->find('span.meta time', 0);
        $download = $dom->find('meta[itemprop=interactionCount]', 0);
        $_download = explode(':', $download->getAttribute('content'));

        $ret = array(
            'function'    => __FUNCTION__,
            'platform'    => 'Firefox',
            'appname'     => $title->plaintext,
            'supplier'    => implode(' ', $_supplier),
            'icon'        => $iconUrl,
            'price'       => $priceText,
            'reviewCount' => trim($reviewNum->plaintext),
            'score'       => $this->rating_star($rating->getAttribute('content')),
            'published'   => $published->getAttribute('datetime'),
            'download'    => $_download[1],
        );
        return $ret;
    }

    function parse_windows_apps($dom) {
        $title = $dom->find('h1#ProductTitleText', 0);
        $supplier = $dom->find('div#AppDeveloper', 0);
        $icon  = $dom->find('img[itemprop=image]', 0);
        $iconUrl = $icon->getAttribute('src');
        $price = $dom->find('span[itemprop=price]', 0);
        $priceText = $price->getAttribute('content');
        $priceText = $priceText == 0 ? __('Free', $this->text_domain) : $priceText;

        $reviewNum = $dom->find('meta[itemprop=ratingCount]', 0);
        $rating = $dom->find('meta[itemprop=ratingValue]', 0);
        $published = 0;
        $download = 0;

        $ret = array(
            'function'    => __FUNCTION__,
            'platform'    => 'Windows',
            'appname'     => $title->getAttribute('title'),
            'supplier'    => $supplier->getAttribute('title'),
            'icon'        => $iconUrl,
            'price'       => $priceText,
            'reviewCount' => $reviewNum->getAttribute('content'),
            'score'       => $this->rating_star($rating->getAttribute('content')),
            'published'   => $published,
            'download'    => $download,
        );
        return $ret;
    }

    function rating_star($count) {
        return str_repeat('★', round($count));
    }

    function make_html($data) {
        $html = '
            <div class="applink {class}">
            <div class="applink-container">
            <div class="applink-icon-container">
            <a href="{url}" target="_blank"><img src="{icon}" class="applink-icon"></a>
            </div>
            <div class="applink-info">
            <p class="applink-appname"><a href="{url}" target="_blank">{appname}</a></p>
            <ul class="applink-meta">
            <li class="applink-supplier">{supplier}</li>
            <li class="applink-price"><span class="applink-meta-title">' . __('Price', $this->text_domain) . '</span>{price}</li>
            <li class="applink-score"><span class="applink-meta-title">' . __('Score', $this->text_domain) . '</span>{score} ({reviewCount})</li>
            <li class="applink-platform"><span class="applink-meta-title">' . __('Platform', $this->text_domain) . '</span>{platform}</li>
    {downloadhtml}
            </ul>
            </div>
    {storebadgehtml}
    {powerdbylinkhtml}
            </div>
            </div>
            ';
        foreach ($data as $key => $val) {
            $html = str_replace('{' . $key . '}', htmlspecialchars($val), $html);
        }

        // platform
        $platforms = array(
            'parse_android_app' => 'Android',
            'parse_ios_app' => 'iOS',
            'parse_mac_app' => 'Mac',
            'parse_wordpress_plugin' => 'Wordpress',
            'parse_chrome_extension' => 'Google Chrome',
            'parse_firefox_addon' => 'Firefox',
            'parse_windows_apps' => 'Windows',
        );
        $html = str_replace('{platform}', $platforms[$data['function']], $html);

        // dont display if download count is 0
        $download_html = '';
        //if ((int)$data['download'] > 0) {
        //    $download_html = '<li class="applink-score"><span class="applink-meta-title">' . __('Download', $this->text_domain) . '</span>' . $data['download'] . '</li>';
        //}
        $html = str_replace('{downloadhtml}', $download_html, $html);

        // powerdby link
        $powerdbylink_html = '';
        if ($this->options['display-powerdby-link']) {
            $powerdbylink_html = '<div class="applink-powerdby">powerd by <a href="'.self::PLUGIN_URI.'">'.self::PLUGIN_NAME.'</a></div>';
        }
        $html = str_replace('{powerdbylinkhtml}', $powerdbylink_html, $html);

        // storebadge
        // todo: must check guidelines
        $storebadge_html = '';
        if ($this->options['display-store-badge']) {
            $storebadge_html = '';
        }
        $html = str_replace('{storebadgehtml}', $storebadge_html, $html);

        return $html;
    }

    function url_hash($url) {
        return md5($url);
    }
}

new SimpleAppLinks;
