<?php
/**
 * Simple_Google_Adsense Ad Inspector
 *
 * Admin-only frontend helper: an "AdFlow" toolbar menu that outlines every
 * ad on the page and reports whether Google filled it - the fastest way to
 * answer "why is my ad not showing?". Visitors never load any of this.
 *
 * @package Simple_Google_Adsense
 * @since   1.4.0
 */

defined('ABSPATH') || exit;

/**
 * Simple_Google_Adsense_Inspector Class.
 *
 * @class Simple_Google_Adsense_Inspector
 */
final class Simple_Google_Adsense_Inspector
{

    /**
     * The single instance of the class.
     *
     * @var Simple_Google_Adsense_Inspector
     * @since 1.4.0
     */
    protected static $_instance = null;

    /**
     * Main Simple_Google_Adsense_Inspector Instance.
     *
     * @return Simple_Google_Adsense_Inspector
     * @since 1.4.0
     * @static
     */
    public static function instance()
    {
        if (is_null(self::$_instance)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    /**
     * Simple_Google_Adsense_Inspector Constructor.
     */
    public function __construct()
    {
        add_action('admin_bar_menu', array($this, 'toolbar'), 90);
        add_action('admin_bar_init', array($this, 'toolbar_style'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue'));
    }

    /**
     * Whether the inspector is available on this request.
     *
     * @return bool
     * @since 1.4.0
     */
    private function is_available()
    {
        return !is_admin() && is_admin_bar_showing() && current_user_can(Simple_Google_Adsense_Caps::ACCESS)
            && apply_filters('adflow_inspector_enabled', true);
    }

    /**
     * Toolbar icon styles (the AdFlow glyph follows the toolbar's colours).
     *
     * @since 1.4.0
     */
    public function toolbar_style()
    {
        wp_add_inline_style('admin-bar', '#wpadminbar #wp-admin-bar-adflow .adflow-ab-icon{display:inline-block;float:left;width:20px;height:20px;margin:6px 6px 0 0;padding:0;color:#a7aaad}#wpadminbar #wp-admin-bar-adflow .adflow-ab-icon:before{content:none}#wpadminbar #wp-admin-bar-adflow .adflow-ab-icon svg{display:block;width:20px;height:20px}#wpadminbar #wp-admin-bar-adflow:hover .adflow-ab-icon,#wpadminbar #wp-admin-bar-adflow .ab-item:focus .adflow-ab-icon{color:#72aee6}@media screen and (max-width:782px){#wpadminbar #wp-admin-bar-adflow .adflow-ab-icon{width:26px;height:26px;margin:10px 7px 0}#wpadminbar #wp-admin-bar-adflow .adflow-ab-icon svg{width:26px;height:26px}}');
    }

    /**
     * Add the toolbar menu.
     *
     * @param WP_Admin_Bar $bar Toolbar.
     * @since 1.4.0
     */
    public function toolbar($bar)
    {
        if (!$this->is_available()) {
            return;
        }

        $bar->add_node(array(
            'id' => 'adflow',
            'title' => '<span class="ab-icon adflow-ab-icon" aria-hidden="true">' . sprintf(Simple_Google_Adsense_Admin::glyph_svg(), Simple_Google_Adsense_Admin::GLYPH_PATH) . '</span><span class="ab-label">' . esc_html__('AdFlow', 'simple-google-adsense') . '</span>',
            'href' => Simple_Google_Adsense_Admin::dashboard_url(),
        ));

        $bar->add_node(array(
            'parent' => 'adflow',
            'id' => 'adflow-inspect',
            'title' => esc_html__('Inspect ads on this page', 'simple-google-adsense'),
            'href' => '#adflow-inspect',
        ));

        $bar->add_node(array(
            'parent' => 'adflow',
            'id' => 'adflow-dashboard',
            'title' => esc_html__('Dashboard', 'simple-google-adsense'),
            'href' => Simple_Google_Adsense_Admin::dashboard_url(),
        ));
    }

    /**
     * Load the inspector for administrators.
     *
     * @since 1.4.0
     */
    public function enqueue()
    {
        if (!$this->is_available()) {
            return;
        }

        wp_enqueue_style('adflow-inspector', SIMPLE_GOOGLE_ADSENSE_PLUGIN_URI . '/assets/css/inspector.css', array(), SIMPLE_GOOGLE_ADSENSE_VERSION);
        wp_enqueue_script('adflow-inspector', SIMPLE_GOOGLE_ADSENSE_PLUGIN_URI . '/assets/js/inspector.js', array(), SIMPLE_GOOGLE_ADSENSE_VERSION, true);
        wp_localize_script('adflow-inspector', 'adflowInspector', array(
            'hideForAdmins' => (bool) Simple_Google_Adsense_Settings::get('hide_for_admins'),
            'i18n' => array(
                'filled' => __('Filled', 'simple-google-adsense'),
                'unfilled' => __('Unfilled - Google had no ad for this slot right now (common on new or low-traffic sites).', 'simple-google-adsense'),
                'pending' => __('Requested - waiting for Google.', 'simple-google-adsense'),
                'notLoaded' => __('Not requested - AdSense script blocked (ad blocker?), lazy loading, or hidden by a targeting rule.', 'simple-google-adsense'),
                'custom' => __('Your own ad', 'simple-google-adsense'),
                'autoAd' => __('Auto ad', 'simple-google-adsense'),
                'unit' => __('Unit', 'simple-google-adsense'),
                'slot' => __('Slot', 'simple-google-adsense'),
                'placement' => __('Placement', 'simple-google-adsense'),
                /* translators: 1: number of ads, 2: unfilled ads, 3: ads not requested */
                'summary' => __('AdFlow: %1$d ad(s) on this page, %2$d unfilled, %3$d not requested.', 'simple-google-adsense'),
                'none' => __('AdFlow: no ads on this page.', 'simple-google-adsense'),
                'hidden' => __('Ads are hidden for administrators (AdFlow → Settings). Log out or use a private window to see them.', 'simple-google-adsense'),
            ),
        ));
    }
}
