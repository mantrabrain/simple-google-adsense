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
     * Script handle used for the AdSense library.
     */
    const LIBRARY_HANDLE = 'simple-google-adsense-library';

    /**
     * Style handle used for the plugin's ad styles.
     */
    const STYLE_HANDLE = 'simple-google-adsense-styles';

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
        add_action('wp_enqueue_scripts', array($this, 'register_assets'));
        add_filter('script_loader_tag', array($this, 'add_library_script_attributes'), 10, 2);
    }

    /**
     * Register the AdSense library and plugin styles.
     *
     * Both are only registered here; they are enqueued on demand so that pages
     * without any ad on them stay free of the extra requests. Auto Ads is the
     * one exception: it has to load on every page to work at all.
     *
     * @since 1.3.0
     */
    public function register_assets()
    {
        self::register_assets_once();

        if (Simple_Google_Adsense_Settings::is_auto_ads_enabled()) {
            wp_enqueue_script(self::LIBRARY_HANDLE);
        }
    }

    /**
     * Register the plugin style and the Google AdSense library.
     *
     * Safe to call more than once, and safe to call late: a shortcode rendering
     * inside a REST request never sees `wp_enqueue_scripts`, so it registers the
     * handles itself rather than silently enqueueing nothing.
     *
     * @since 1.3.0
     */
    public static function register_assets_once()
    {
        if (!wp_style_is(self::STYLE_HANDLE, 'registered')) {
            wp_register_style(
                self::STYLE_HANDLE,
                SIMPLE_GOOGLE_ADSENSE_PLUGIN_URI . '/assets/css/adsense.css',
                array(),
                SIMPLE_GOOGLE_ADSENSE_VERSION
            );
        }

        self::register_library();
    }

    /**
     * Register the Google AdSense library script.
     *
     * @since 1.3.0
     */
    public static function register_library()
    {
        if (wp_script_is(self::LIBRARY_HANDLE, 'registered')) {
            return;
        }

        $ad_client = Simple_Google_Adsense_Settings::get_ad_client();

        if ('' === $ad_client) {
            return;
        }

        wp_register_script(
            self::LIBRARY_HANDLE,
            'https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=' . rawurlencode($ad_client),
            array(),
            null, // Google serves its own versioned library; a `ver` query arg would break caching.
            array(
                'strategy' => 'async',
                'in_footer' => false,
            )
        );
    }

    /**
     * Enqueue the assets a rendered ad unit needs.
     *
     * Called while a shortcode or block renders, which happens after `wp_head`.
     * WordPress still prints the handles in `wp_footer`, and the AdSense library
     * processes the `window.adsbygoogle` queue whenever it finishes loading, so
     * the ordering is safe.
     *
     * @since 1.3.0
     */
    public static function enqueue_ad_assets()
    {
        self::register_assets_once();

        wp_enqueue_style(self::STYLE_HANDLE);

        if (wp_script_is(self::LIBRARY_HANDLE, 'registered')) {
            wp_enqueue_script(self::LIBRARY_HANDLE);
        }
    }

    /**
     * Add the `crossorigin` attribute Google requires on the library tag.
     *
     * @param string $tag The script tag markup.
     * @param string $handle The script handle.
     * @return string
     * @since 1.3.0
     */
    public function add_library_script_attributes($tag, $handle)
    {
        if (self::LIBRARY_HANDLE !== $handle) {
            return $tag;
        }

        if (false !== strpos($tag, 'crossorigin=')) {
            return $tag;
        }

        return str_replace(' src=', ' crossorigin="anonymous" src=', $tag);
    }

    /**
     * Include required core files used in frontend.
     */
    public function includes()
    {


    }


}
