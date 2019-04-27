<?php
/**
 * Simple_Google_Adsense frontend setup
 *
 * @package Simple_Google_Adsense
 * @since   1.0.0
 */

defined('ABSPATH') || exit;

/**
 * Main Simple_Google_Adsense_Frontend Class.
 *
 * @class Simple_Google_Adsense
 */
final class Simple_Google_Adsense_Frontend
{

    /**
     * The single instance of the class.
     *
     * @var Simple_Google_Adsense_Frontend
     * @since 1.0.0
     */
    protected static $_instance = null;


    /**
     * Main Simple_Google_Adsense_Frontend Instance.
     *
     * Ensures only one instance of Simple_Google_Adsense_Frontend is loaded or can be loaded.
     *
     * @since 1.0.0
     * @static
     * @return Simple_Google_Adsense_Frontend - Main instance.
     */
    public static function instance()
    {
        if (is_null(self::$_instance)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    /**
     * Simple_Google_Adsense Constructor.
     */
    public function __construct()
    {
        $this->includes();
        $this->init_hooks();
    }

    /**
     * Hook into actions and filters.
     *
     * @since 1.0.0
     */
    private function init_hooks()
    {
        add_action('wp_head', array($this, 'inject_script'));

    }

    public function inject_script()
    {

        $options = get_option('simple_google_adsense_settings');
        $publisher_id = isset($options['publisher_id']) ? $options['publisher_id']: '';

        if (isset($publisher_id) && !empty($publisher_id)) {
            $plugin_version = SIMPLE_GOOGLE_ADSENSE_VERSION;
            $ouput = <<<EOT
                <!-- auto ad code generated with Simple Google Adsense plugin v{$plugin_version} -->
                <script async src="//pagead2.googlesyndication.com/pagead/js/adsbygoogle.js"></script>
                <script>
                (adsbygoogle = window.adsbygoogle || []).push({
                     google_ad_client: "ca-{$publisher_id}",
                     enable_page_level_ads: true
                });
                </script>      
                <!-- / Simple Google Adsense plugin -->
EOT;

            echo $ouput;
        }

    }

    /**
     * Include required core files used in frontend.
     */
    public function includes()
    {


    }


}
