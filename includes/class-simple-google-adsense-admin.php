<?php
/**
 * Simple_Google_Adsense admin setup
 *
 * Menu, the shared admin chrome (top bar, page header, form rows, toggles),
 * the tabbed Settings screen and the General tab.
 *
 * Menu, in order of how often it is needed:
 *   Dashboard · Ad Units · Placements · Earnings · Settings · (Upgrade)
 *
 * @package Simple_Google_Adsense
 * @since   1.0.0
 */

defined('ABSPATH') || exit;

/**
 * Main Simple_Google_Adsense_Admin Class.
 *
 * @class Simple_Google_Adsense
 */
final class Simple_Google_Adsense_Admin
{

    /**
     * SVG path of the AdFlow glyph (20x20).
     */
    const GLYPH_PATH = 'M2.2 12.6h3.4a.9.9 0 0 1 .9.9v4.6a.9.9 0 0 1-.9.9H2.2a.9.9 0 0 1-.9-.9v-4.6a.9.9 0 0 1 .9-.9zm6.1-3.2h3.4a.9.9 0 0 1 .9.9v7.8a.9.9 0 0 1-.9.9H8.3a.9.9 0 0 1-.9-.9v-7.8a.9.9 0 0 1 .9-.9zm6.1-2.6h3.4a.9.9 0 0 1 .9.9v10.4a.9.9 0 0 1-.9.9h-3.4a.9.9 0 0 1-.9-.9V7.7a.9.9 0 0 1 .9-.9zM1.8 8.55C6.1 8.15 9.57 6.36 14.57 2.56l1.27 1.68C10.84 8.04 6.3 10.25 2 10.65a1.05 1.05 0 0 1-.2-2.1zM17.59 1.58l-1.24 3.83-2.78-3.66z';

    /**
     * Top-level menu slug (the Dashboard).
     */
    const MENU_SLUG = 'adflow';

    /**
     * Settings screen slug - the slug the settings page had before 1.4.0, so
     * existing links and bookmarks keep working.
     */
    const SETTINGS_SLUG = 'simple-google-adsense-settings';

    /**
     * Health checks now live on the Dashboard.
     *
     * @deprecated 1.4.0 Use MENU_SLUG.
     */
    const HEALTH_SLUG = 'adflow';

    /**
     * Pro comparison page slug (free only).
     */
    const PRO_SLUG = 'adflow-pro-features';

    /**
     * The single instance of the class.
     *
     * @var Simple_Google_Adsense_Admin
     * @since 1.0.0
     */
    protected static $_instance = null;


    /**
     * Main Simple_Google_Adsense_Admin Instance.
     *
     * Ensures only one instance of Simple_Google_Adsense_Admin is loaded or can be loaded.
     *
     * @return Simple_Google_Adsense_Admin - Main instance.
     * @since 1.0.0
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
        add_filter('plugin_action_links_' . SIMPLE_GOOGLE_ADSENSE_BASENAME, array($this, 'action_links'));
        add_filter('plugin_row_meta', array($this, 'row_meta'), 10, 2);
        add_action('admin_init', array($this, 'settings'));
        add_action('admin_init', array($this, 'maybe_redirect'));
        // Before WordPress checks page access (which would refuse the old URL).
        add_action('admin_menu', array($this, 'legacy_redirect'), 0);
        add_action('admin_menu', array($this, 'option_menu'));
        add_action('admin_menu', array($this, 'late_menu'), 99);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_styles'));
        add_action('in_admin_header', array($this, 'render_topbar'));
        add_filter('admin_body_class', array($this, 'body_class'));
        add_action('admin_notices', array($this, 'review_notice'));
        add_action('admin_post_adflow_dismiss_review', array($this, 'dismiss_review'));
        add_filter('adflow_settings_tabs', array($this, 'register_general_tab'), 5);
    }

    /*
    |--------------------------------------------------------------------------
    | URLs
    |--------------------------------------------------------------------------
    */

    /**
     * Dashboard URL.
     *
     * @return string
     * @since 1.4.0
     */
    public static function dashboard_url()
    {
        return admin_url('admin.php?page=' . self::MENU_SLUG);
    }

    /**
     * Settings URL, optionally for a tab.
     *
     * @param string $tab Tab key.
     * @return string
     * @since 1.4.0
     */
    public static function settings_url($tab = '')
    {
        $url = admin_url('admin.php?page=' . self::SETTINGS_SLUG);

        return '' === $tab ? $url : add_query_arg('tab', $tab, $url);
    }

    /**
     * AdFlow Pro URL with campaign tracking.
     *
     * @param string $campaign Where the link is shown.
     * @return string
     * @since 1.4.0
     */
    public static function pro_url($campaign = 'settings')
    {
        $url = add_query_arg(array(
            'utm_source' => 'adflow-free',
            'utm_medium' => 'plugin',
            'utm_campaign' => sanitize_key($campaign),
        ), 'https://matrixaddons.com/plugins/adflow/');

        /**
         * Filters the AdFlow Pro URL (e.g. for affiliate links).
         *
         * @param string $url
         * @param string $campaign
         * @since 1.4.0
         */
        return apply_filters('adflow_pro_url', $url, $campaign);
    }

    public function action_links($links)
    {
        array_unshift($links, '<a href="' . esc_url(self::settings_url()) . '">' . esc_html__('Settings', 'simple-google-adsense') . '</a>');

        if (!Simple_Google_Adsense_Settings::is_pro_active()) {
            $links[] = '<a href="' . esc_url(self::pro_url('plugins')) . '" target="_blank" rel="noopener" style="font-weight:600">'
                . esc_html__('Get AdFlow Pro', 'simple-google-adsense') . '</a>';
        }

        return $links;
    }

    /**
     * Links under the plugin description on the Plugins screen.
     *
     * @param array $links Row meta links.
     * @param string $file Plugin file.
     * @return array
     * @since 1.4.0
     */
    public function row_meta($links, $file)
    {
        if (SIMPLE_GOOGLE_ADSENSE_BASENAME !== $file) {
            return $links;
        }

        $links[] = '<a href="' . esc_url(admin_url('admin.php?page=adflow-docs')) . '">' . esc_html__('Docs', 'simple-google-adsense') . '</a>';
        if (!Simple_Google_Adsense_Settings::is_pro_active()) {
            $links[] = '<a href="' . esc_url(self::pro_url('plugins-meta') . '#compare') . '" target="_blank" rel="noopener">' . esc_html__('Pro features', 'simple-google-adsense') . '</a>';
        }
        $links[] = '<a href="https://wordpress.org/support/plugin/simple-google-adsense/" target="_blank" rel="noopener">' . esc_html__('Support', 'simple-google-adsense') . '</a>';

        return $links;
    }

    /**
     * Redirect the pre-1.4.0 settings URL.
     *
     * @since 1.4.0
     */
    public function legacy_redirect()
    {
        global $pagenow;

        // Bookmarks and docs pointing at Settings → AdFlow (before 1.4.0) keep working.
        if ('options-general.php' === $pagenow && isset($_GET['page']) && self::SETTINGS_SLUG === sanitize_key(wp_unslash($_GET['page']))) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            wp_safe_redirect(self::settings_url());
            exit;
        }
    }

    /**
     * First visit after activation: open the Dashboard.
     *
     * @since 1.4.0
     */
    public function maybe_redirect()
    {
        if (!get_transient('simple_google_adsense_activation_redirect')) {
            return;
        }

        delete_transient('simple_google_adsense_activation_redirect');

        if (wp_doing_ajax() || is_network_admin() || isset($_GET['activate-multi']) || !current_user_can('manage_options')) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return;
        }

        wp_safe_redirect(self::dashboard_url());
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | Menu
    |--------------------------------------------------------------------------
    */

    /**
     * The AdFlow glyph (rising bars and growth arrow) as SVG markup.
     *
     * @param string $fill Fill colour ("black" lets WordPress recolour the menu icon).
     * @return string
     * @since 1.4.0
     */
    public static function glyph_svg($fill = 'currentColor')
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" width="20" height="20" aria-hidden="true" focusable="false"><path fill="' . esc_attr($fill) . '" d="%s"/></svg>';
    }

    /**
     * Admin menu icon: the AdFlow glyph, recoloured by WordPress for every
     * admin colour scheme and for hover / current states.
     *
     * @return string Data URI.
     * @since 1.4.0
     */
    public static function menu_icon()
    {
        return 'data:image/svg+xml;base64,' . base64_encode(sprintf(self::glyph_svg('black'), self::GLYPH_PATH)); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- WordPress expects a base64 data URI for SVG menu icons.
    }

    public function option_menu()
    {
        add_menu_page(
            __('AdFlow', 'simple-google-adsense'),
            __('AdFlow', 'simple-google-adsense'),
            Simple_Google_Adsense_Caps::ACCESS,
            self::MENU_SLUG,
            array(Simple_Google_Adsense_Dashboard::instance(), 'render'),
            self::menu_icon(),
            58
        );

        add_submenu_page(
            self::MENU_SLUG,
            __('AdFlow Dashboard', 'simple-google-adsense'),
            __('Dashboard', 'simple-google-adsense'),
            Simple_Google_Adsense_Caps::ACCESS,
            self::MENU_SLUG,
            array(Simple_Google_Adsense_Dashboard::instance(), 'render')
        );

        add_submenu_page(
            self::MENU_SLUG,
            __('AdFlow Settings', 'simple-google-adsense'),
            __('Settings', 'simple-google-adsense'),
            'manage_options',
            self::SETTINGS_SLUG,
            array($this, 'options_page')
        );
    }

    /**
     * Add the Upgrade item and put the submenu in priority order.
     *
     * @since 1.4.0
     */
    public function late_menu()
    {
        global $submenu;

        if (!Simple_Google_Adsense_Settings::is_pro_active()) {
            add_submenu_page(
                self::MENU_SLUG,
                __('AdFlow Pro', 'simple-google-adsense'),
                __('Upgrade to Pro', 'simple-google-adsense'),
                'manage_options',
                self::PRO_SLUG,
                array($this, 'pro_page')
            );
        }

        if (empty($submenu[self::MENU_SLUG])) {
            return;
        }

        /**
         * Filters the order of the AdFlow submenu (by slug).
         *
         * @param string[] $order
         * @since 1.4.0
         */
        $order = apply_filters('adflow_menu_order', array(
            self::MENU_SLUG,
            'edit.php?post_type=' . Simple_Google_Adsense_Ad_Units::POST_TYPE,
            Simple_Google_Adsense_Placements::PAGE_SLUG,
            Simple_Google_Adsense_Reports::PAGE_SLUG,
            'adflow-earnings',
            self::SETTINGS_SLUG,
            self::PRO_SLUG,
        ));

        $rank = array_flip($order);
        $items = array_values($submenu[self::MENU_SLUG]);
        $positions = array();

        foreach ($items as $index => $item) {
            // Unknown items keep their place after the known ones, in their original order.
            $positions[$index] = isset($rank[$item[2]]) ? $rank[$item[2]] : 100 + $index;
        }

        asort($positions, SORT_NUMERIC);
        $sorted = array();

        foreach (array_keys($positions) as $index) {
            $sorted[] = $items[$index];
        }

        $submenu[self::MENU_SLUG] = $sorted;
    }

    /*
    |--------------------------------------------------------------------------
    | Shared admin chrome
    |--------------------------------------------------------------------------
    */

    /**
     * Whether the current admin screen belongs to AdFlow.
     *
     * @return bool
     * @since 1.4.0
     */
    public static function is_adflow_screen()
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        if ($screen && Simple_Google_Adsense_Ad_Units::POST_TYPE === $screen->post_type) {
            return true;
        }

        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        return in_array($page, array(self::MENU_SLUG, self::SETTINGS_SLUG), true) || 0 === strpos($page, 'adflow-');
    }

    /**
     * Body class for AdFlow screens.
     *
     * @param string $classes Classes.
     * @return string
     * @since 1.4.0
     */
    public function body_class($classes)
    {
        return self::is_adflow_screen() ? $classes . ' adflow-admin' : $classes;
    }

    /**
     * Branded top bar on every AdFlow screen.
     *
     * @since 1.4.0
     */
    public function render_topbar()
    {
        if (!self::is_adflow_screen()) {
            return;
        }

        $pro = Simple_Google_Adsense_Settings::is_pro_active();
        ?>
        <div class="adflow-topbar">
            <a class="adflow-brand" href="<?php echo esc_url(self::dashboard_url()); ?>">
                <?php echo self::logo(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
                <span class="adflow-brand__name"><?php echo esc_html($pro ? 'AdFlow Pro' : 'AdFlow'); ?></span>
                <span class="adflow-brand__version">v<?php echo esc_html($pro && defined('ADFLOW_PRO_VERSION') ? ADFLOW_PRO_VERSION : SIMPLE_GOOGLE_ADSENSE_VERSION); ?></span>
            </a>
            <nav class="adflow-topbar__links" aria-label="<?php esc_attr_e('AdFlow help', 'simple-google-adsense'); ?>">
                <a href="<?php echo esc_url(Simple_Google_Adsense_Docs::url()); ?>"><?php esc_html_e('Docs', 'simple-google-adsense'); ?></a>
                <a href="https://support.google.com/adsense" target="_blank" rel="noopener"><?php esc_html_e('AdSense Help', 'simple-google-adsense'); ?></a>
                <a href="https://wordpress.org/support/plugin/simple-google-adsense/" target="_blank" rel="noopener"><?php esc_html_e('Support', 'simple-google-adsense'); ?></a>
                <?php if (!$pro) : ?>
                    <a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=' . self::PRO_SLUG)); ?>"><?php esc_html_e('Upgrade to Pro', 'simple-google-adsense'); ?></a>
                <?php endif; ?>
            </nav>
        </div>
        <?php
    }

    /**
     * AdFlow logo mark.
     *
     * @return string SVG markup.
     * @since 1.4.0
     */
    public static function logo()
    {
        return '<svg class="adflow-brand__mark" viewBox="0 0 256 256" aria-hidden="true" focusable="false">'
            . '<defs><linearGradient id="adflow-mark-bg" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#3B82F6"/><stop offset="1" stop-color="#1D4ED8"/></linearGradient></defs>'
            . '<rect width="256" height="256" rx="58" fill="url(#adflow-mark-bg)"/>'
            . '<rect x="58" y="146" width="34" height="56" rx="10" fill="#fff" fill-opacity=".62"/>'
            . '<rect x="111" y="120" width="34" height="82" rx="10" fill="#fff" fill-opacity=".82"/>'
            . '<rect x="164" y="94" width="34" height="108" rx="10" fill="#fff"/>'
            . '<path d="M50 122 C 92 116, 126 92, 172 52" fill="none" stroke="#34D399" stroke-width="16" stroke-linecap="round"/>'
            . '<path d="M146 44 L 180 42 L 177 76" fill="none" stroke="#34D399" stroke-width="16" stroke-linecap="round" stroke-linejoin="round"/>'
            . '</svg>';
    }

    /**
     * Page header: title, optional description and action buttons.
     *
     * @param string $title Page title.
     * @param string $description Optional description.
     * @param array $actions Optional buttons: array( array('label' => , 'url' => , 'primary' => bool, 'target' => '') ).
     * @since 1.4.0
     */
    public static function render_header($title, $description = '', $actions = array())
    {
        ?>
        <div class="adflow-page-head">
            <div>
                <h1><?php echo esc_html($title); ?></h1>
                <?php if ('' !== $description) : ?>
                    <p><?php echo esc_html($description); ?></p>
                <?php endif; ?>
            </div>
            <?php if ($actions) : ?>
                <div class="adflow-page-head__actions">
                    <?php foreach ($actions as $action) : ?>
                        <a class="button <?php echo !empty($action['primary']) ? 'button-primary' : ''; ?>" href="<?php echo esc_url($action['url']); ?>"<?php echo !empty($action['target']) ? ' target="_blank" rel="noopener"' : ''; ?>><?php echo esc_html($action['label']); ?></a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <hr class="wp-header-end">
        <?php
        // Pages outside Settings do not print "Settings saved" on their own.
        settings_errors();
    }

    /**
     * Toggle switch.
     *
     * @param string $name Input name.
     * @param bool $checked Checked.
     * @param string $label Visible label.
     * @param string $id Optional ID.
     * @since 1.4.0
     */
    public static function toggle($name, $checked, $label = '', $id = '')
    {
        ?>
        <label class="adflow-toggle"<?php echo '' !== $id ? ' for="' . esc_attr($id) . '"' : ''; ?>>
            <input type="checkbox" name="<?php echo esc_attr($name); ?>" value="1" <?php checked($checked); ?><?php echo '' !== $id ? ' id="' . esc_attr($id) . '"' : ''; ?>>
            <span class="adflow-toggle__track" aria-hidden="true"></span>
            <?php if ('' !== $label) : ?>
                <span class="adflow-toggle__label"><?php echo esc_html($label); ?></span>
            <?php endif; ?>
        </label>
        <?php
    }

    /**
     * Open a form row (label column + control column).
     *
     * @param string $label Label.
     * @param string $help Help text under the label.
     * @param string $for Optional input ID.
     * @since 1.4.0
     */
    public static function field_start($label, $help = '', $for = '')
    {
        ?>
        <div class="adflow-field">
            <div class="adflow-field__label">
                <?php if ('' !== $for) : ?>
                    <label for="<?php echo esc_attr($for); ?>"><?php echo esc_html($label); ?></label>
                <?php else : ?>
                    <?php echo esc_html($label); ?>
                <?php endif; ?>
                <?php if ('' !== $help) : ?>
                    <p class="adflow-field__help"><?php echo esc_html($help); ?></p>
                <?php endif; ?>
            </div>
            <div class="adflow-field__control">
        <?php
    }

    /**
     * Close a form row.
     *
     * @since 1.4.0
     */
    public static function field_end()
    {
        ?>
            </div>
        </div>
        <?php
    }

    /**
     * Open a panel.
     *
     * @param string $title Title.
     * @param string $description Description.
     * @param string $id Optional anchor ID.
     * @since 1.4.0
     */
    public static function panel_start($title, $description = '', $id = '')
    {
        ?>
        <section class="adflow-panel"<?php echo '' !== $id ? ' id="' . esc_attr($id) . '"' : ''; ?>>
            <header class="adflow-panel__head">
                <div>
                    <h2><?php echo esc_html($title); ?></h2>
                    <?php if ('' !== $description) : ?>
                        <p><?php echo esc_html($description); ?></p>
                    <?php endif; ?>
                </div>
            </header>
            <div class="adflow-panel__body">
        <?php
    }

    /**
     * Close a panel.
     *
     * @since 1.4.0
     */
    public static function panel_end()
    {
        ?>
            </div>
        </section>
        <?php
    }

    /*
    |--------------------------------------------------------------------------
    | Settings screen
    |--------------------------------------------------------------------------
    */

    function sanitize($input)
    {
        $input = is_array($input) ? $input : array();

        // Keep keys this form does not own (added by extensions or later versions).
        $stored = get_option('simple_google_adsense_settings');
        $sanitized_input = is_array($stored) ? $stored : array();

        $sanitized_input['publisher_id'] = isset($input['publisher_id'])
            ? sanitize_text_field($input['publisher_id'])
            : '';

        /*
         * An unchecked checkbox is simply absent from the submitted data, so the
         * value has to be written explicitly. Storing only the checked ones left
         * the option without the key, and the readers fall back to their default
         * of "on" - which made it impossible to turn Auto Ads off.
         */
        $sanitized_input['enable_auto_ads'] = !empty($input['enable_auto_ads']);
        $sanitized_input['enable_manual_ads'] = !empty($input['enable_manual_ads']);
        $sanitized_input['hide_for_admins'] = !empty($input['hide_for_admins']);
        $sanitized_input['delete_data'] = !empty($input['delete_data']);
        $sanitized_input['ad_label'] = isset($input['ad_label']) && in_array($input['ad_label'], array('advertisements', 'sponsored'), true) ? $input['ad_label'] : '';

        $post_types = isset($input['auto_ads_exclude_post_types']) ? array_map('sanitize_key', (array) $input['auto_ads_exclude_post_types']) : array();
        $sanitized_input['auto_ads_exclude_post_types'] = array_values(array_intersect($post_types, array_keys(get_post_types(array('public' => true)))));
        $sanitized_input['auto_ads_exclude_ids'] = isset($input['auto_ads_exclude_ids'])
            ? implode(', ', array_filter(wp_parse_id_list($input['auto_ads_exclude_ids'])))
            : '';

        /**
         * Filters the sanitized core settings.
         *
         * @param array $sanitized_input
         * @param array $input
         * @since 1.4.0
         */
        return apply_filters('adflow_sanitize_settings', $sanitized_input, $input);
    }

    public function settings()
    {
        register_setting('simple_google_adsense_page', 'simple_google_adsense_settings', array(
            'sanitize_callback' => array($this, 'sanitize'),
        ));
    }

    /**
     * Settings tabs, in order. Pro adds its tabs through the filter.
     *
     * @return array key => array( label, callback, priority )
     * @since 1.4.0
     */
    public static function get_settings_tabs()
    {
        /**
         * Filters the Settings tabs.
         *
         * @param array $tabs key => array( 'label' => string, 'callback' => callable, 'priority' => int )
         * @since 1.4.0
         */
        $tabs = apply_filters('adflow_settings_tabs', array());

        uasort($tabs, function ($a, $b) {
            return (isset($a['priority']) ? $a['priority'] : 50) - (isset($b['priority']) ? $b['priority'] : 50);
        });

        return $tabs;
    }

    /**
     * Register the General tab.
     *
     * @param array $tabs Tabs.
     * @return array
     * @since 1.4.0
     */
    public function register_general_tab($tabs)
    {
        $tabs['general'] = array(
            'label' => __('General', 'simple-google-adsense'),
            'callback' => array($this, 'render_general_tab'),
            'priority' => 10,
        );

        return $tabs;
    }

    /**
     * Settings screen (tabbed).
     */
    function options_page()
    {
        $tabs = self::get_settings_tabs();
        $current = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if (!isset($tabs[$current])) {
            $current = 'general';
        }
        ?>
        <div class="wrap adflow-page">
            <?php self::render_header(__('Settings', 'simple-google-adsense')); ?>

            <nav class="adflow-tabs" aria-label="<?php esc_attr_e('Settings sections', 'simple-google-adsense'); ?>">
                <?php foreach ($tabs as $key => $tab) : ?>
                    <a href="<?php echo esc_url(self::settings_url('general' === $key ? '' : $key)); ?>" class="<?php echo $key === $current ? 'is-active' : ''; ?>"<?php echo $key === $current ? ' aria-current="page"' : ''; ?>><?php echo esc_html($tab['label']); ?></a>
                <?php endforeach; ?>
            </nav>

            <?php
            if (is_callable($tabs[$current]['callback'])) {
                call_user_func($tabs[$current]['callback']);
            }
            ?>
        </div>
        <?php
    }

    /**
     * General tab.
     *
     * @since 1.4.0
     */
    public function render_general_tab()
    {
        $opt = 'simple_google_adsense_settings';
        $publisher_id = (string) Simple_Google_Adsense_Settings::get('publisher_id');
        $excluded = (array) Simple_Google_Adsense_Settings::get('auto_ads_exclude_post_types');
        $post_types = get_post_types(array('public' => true), 'objects');
        unset($post_types['attachment']);
        $label = (string) Simple_Google_Adsense_Settings::get('ad_label');
        ?>
        <form id="adsense-settings-form" action="options.php" method="post">
            <?php settings_fields('simple_google_adsense_page'); ?>

            <?php self::panel_start(__('AdSense account', 'simple-google-adsense'), __('Connects this site to your Google AdSense account.', 'simple-google-adsense')); ?>
                <?php self::field_start(__('Publisher ID', 'simple-google-adsense'), __('Found in AdSense under Account → Settings → Account information.', 'simple-google-adsense'), 'adflow-publisher-id'); ?>
                    <input type="text" id="adflow-publisher-id" class="regular-text code" name="<?php echo esc_attr($opt); ?>[publisher_id]" value="<?php echo esc_attr($publisher_id); ?>" placeholder="pub-1234567890123456" autocomplete="off" spellcheck="false">
                    <?php if ('' !== trim($publisher_id) && !Simple_Google_Adsense_Settings::is_publisher_id_valid()) : ?>
                        <p class="adflow-field__error"><?php esc_html_e('This does not look like an AdSense Publisher ID. It should be "pub-" followed by 16 digits.', 'simple-google-adsense'); ?></p>
                    <?php endif; ?>
                    <p class="description"><a href="https://support.google.com/adsense/answer/105516" target="_blank" rel="noopener"><?php esc_html_e('Where do I find it?', 'simple-google-adsense'); ?></a></p>
                <?php self::field_end(); ?>
            <?php self::panel_end(); ?>

            <?php self::panel_start(__('Auto Ads', 'simple-google-adsense'), __('Google places ads automatically. Choose ad formats in your AdSense account under Ads → By site.', 'simple-google-adsense')); ?>
                <?php self::field_start(__('Auto Ads', 'simple-google-adsense'), __('Adds Google\'s Auto Ads code to every page.', 'simple-google-adsense')); ?>
                    <?php self::toggle($opt . '[enable_auto_ads]', (bool) Simple_Google_Adsense_Settings::get('enable_auto_ads'), __('Enable Auto Ads', 'simple-google-adsense')); ?>
                <?php self::field_end(); ?>

                <?php self::field_start(__('Turn off on', 'simple-google-adsense'), __('Keep Auto Ads away from pages where ads do not belong, such as contact or checkout pages.', 'simple-google-adsense')); ?>
                    <fieldset class="adflow-checks">
                        <legend class="screen-reader-text"><?php esc_html_e('Content types without Auto Ads', 'simple-google-adsense'); ?></legend>
                        <?php foreach ($post_types as $post_type) : ?>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr($opt); ?>[auto_ads_exclude_post_types][]" value="<?php echo esc_attr($post_type->name); ?>" <?php checked(in_array($post_type->name, $excluded, true)); ?>>
                                <?php echo esc_html($post_type->labels->name); ?>
                            </label>
                        <?php endforeach; ?>
                    </fieldset>
                    <label class="adflow-sublabel" for="adflow-exclude-ids"><?php esc_html_e('Specific posts or pages (IDs, comma separated)', 'simple-google-adsense'); ?></label>
                    <input type="text" id="adflow-exclude-ids" class="regular-text" name="<?php echo esc_attr($opt); ?>[auto_ads_exclude_ids]" value="<?php echo esc_attr(Simple_Google_Adsense_Settings::get('auto_ads_exclude_ids')); ?>" placeholder="12, 34">
                <?php self::field_end(); ?>
            <?php self::panel_end(); ?>

            <?php self::panel_start(__('Manual ads', 'simple-google-adsense'), __('Ad units you place with placements, the block, the widget or shortcodes.', 'simple-google-adsense')); ?>
                <?php self::field_start(__('Manual ads', 'simple-google-adsense'), __('Turn off to hide every manual ad at once without deleting anything.', 'simple-google-adsense')); ?>
                    <?php self::toggle($opt . '[enable_manual_ads]', (bool) Simple_Google_Adsense_Settings::get('enable_manual_ads'), __('Show manual ads', 'simple-google-adsense')); ?>
                <?php self::field_end(); ?>

                <?php self::field_start(__('Ad label', 'simple-google-adsense'), __('Shown above each manual ad. AdSense allows only these two labels.', 'simple-google-adsense'), 'adflow-ad-label'); ?>
                    <select id="adflow-ad-label" name="<?php echo esc_attr($opt); ?>[ad_label]">
                        <option value="" <?php selected($label, ''); ?>><?php esc_html_e('No label', 'simple-google-adsense'); ?></option>
                        <option value="advertisements" <?php selected($label, 'advertisements'); ?>><?php esc_html_e('Advertisements', 'simple-google-adsense'); ?></option>
                        <option value="sponsored" <?php selected($label, 'sponsored'); ?>><?php esc_html_e('Sponsored Links', 'simple-google-adsense'); ?></option>
                    </select>
                <?php self::field_end(); ?>
            <?php self::panel_end(); ?>

            <?php self::panel_start(__('Your account safety', 'simple-google-adsense')); ?>
                <?php self::field_start(__('Hide ads for administrators', 'simple-google-adsense'), __('Avoids accidental clicks on your own ads and keeps your AdSense numbers clean.', 'simple-google-adsense')); ?>
                    <?php self::toggle($opt . '[hide_for_admins]', (bool) Simple_Google_Adsense_Settings::get('hide_for_admins'), __('Do not show ads while I am logged in', 'simple-google-adsense')); ?>
                <?php self::field_end(); ?>
            <?php self::panel_end(); ?>

            <?php self::panel_start(__('Your data', 'simple-google-adsense')); ?>
                <?php self::field_start(__('When the plugin is deleted', 'simple-google-adsense'), __('Off by default, so reinstalling or switching plugins never loses your ads and statistics.', 'simple-google-adsense')); ?>
                    <?php self::toggle($opt . '[delete_data]', (bool) Simple_Google_Adsense_Settings::get('delete_data'), __('Also delete all AdFlow ads, settings and statistics', 'simple-google-adsense')); ?>
                <?php self::field_end(); ?>
            <?php self::panel_end(); ?>

            <div class="adflow-savebar">
                <?php submit_button(__('Save changes', 'simple-google-adsense'), 'primary', 'submit', false); ?>
            </div>
        </form>
        <?php
    }

    /*
    |--------------------------------------------------------------------------
    | Health checks (shown on the Dashboard)
    |--------------------------------------------------------------------------
    */

    /**
     * Setup health checks.
     *
     * @return array[] Each: status (ok|warning|error|info), label, message, action (url), action_label.
     * @since 1.4.0
     */
    public static function get_health_checks()
    {
        $checks = array();
        $publisher_id = Simple_Google_Adsense_Settings::get_publisher_id();
        $settings_url = self::settings_url();

        if ('' === $publisher_id) {
            $checks['publisher_id'] = array('status' => 'error', 'label' => __('Publisher ID', 'simple-google-adsense'), 'message' => __('Not set - no ads can serve.', 'simple-google-adsense'), 'action' => $settings_url, 'action_label' => __('Add it', 'simple-google-adsense'));
        } elseif (!Simple_Google_Adsense_Settings::is_publisher_id_valid()) {
            $checks['publisher_id'] = array('status' => 'error', 'label' => __('Publisher ID', 'simple-google-adsense'), 'message' => sprintf(/* translators: %s: publisher ID */ __('"%s" is not a valid AdSense Publisher ID.', 'simple-google-adsense'), $publisher_id), 'action' => $settings_url, 'action_label' => __('Fix it', 'simple-google-adsense'));
        } else {
            $checks['publisher_id'] = array('status' => 'ok', 'label' => __('Publisher ID', 'simple-google-adsense'), 'message' => $publisher_id);
        }

        $unit_count = count(Simple_Google_Adsense_Ad_Units::get_all());
        $auto = Simple_Google_Adsense_Settings::is_auto_ads_enabled();

        if (!$auto && !$unit_count) {
            $checks['ads'] = array('status' => 'warning', 'label' => __('Ads', 'simple-google-adsense'), 'message' => __('Auto Ads are off and no ad units exist yet.', 'simple-google-adsense'), 'action' => $settings_url, 'action_label' => __('Turn on Auto Ads', 'simple-google-adsense'));
        } else {
            $checks['ads'] = array('status' => 'ok', 'label' => __('Ads', 'simple-google-adsense'), 'message' => sprintf(
                /* translators: 1: on/off, 2: number of ad units */
                __('Auto Ads %1$s · %2$d ad unit(s)', 'simple-google-adsense'),
                $auto ? __('on', 'simple-google-adsense') : __('off', 'simple-google-adsense'),
                $unit_count
            ));
        }

        $ads_txt = Simple_Google_Adsense_Ads_Txt::check();
        $checks['ads_txt'] = array(
            'status' => 'ok' === $ads_txt['status'] ? 'ok' : ('no_publisher' === $ads_txt['status'] ? 'info' : 'error'),
            'label' => __('ads.txt', 'simple-google-adsense'),
            'message' => $ads_txt['message'],
            'action' => self::settings_url('ads-txt'),
            'action_label' => __('Manage', 'simple-google-adsense'),
        );

        global $wpdb;
        $autoload = (int) $wpdb->get_var("SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload IN ('yes','on','auto','auto-on') AND (option_name LIKE 'simple\\_google\\_adsense%' OR option_name LIKE 'adflow%')"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $stats_table = Simple_Google_Adsense_Stats::table();
        $stats = $wpdb->get_row($wpdb->prepare('SELECT (data_length + index_length) AS b FROM information_schema.tables WHERE table_schema = %s AND table_name = %s', DB_NAME, $stats_table), ARRAY_A); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        // Exact count: information_schema only estimates InnoDB rows (and MySQL 8 caches it for a day). One row per day, ad, placement and device keeps this cheap.
        $stats_rows = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $stats_table)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $checks['footprint'] = array(
            'status' => $autoload < 20 * KB_IN_BYTES ? 'ok' : 'warning',
            'label' => __('Database footprint', 'simple-google-adsense'),
            'message' => sprintf(
                /* translators: 1: autoloaded size, 2: statistics rows, 3: statistics size */
                __('%1$s loaded on every page; statistics: %2$s daily rows (%3$s). No per-visit rows are ever stored.', 'simple-google-adsense'),
                size_format($autoload, 1),
                number_format_i18n($stats_rows),
                size_format($stats ? (int) $stats['b'] : 0, 1)
            ),
        );

        $failure = get_option('adflow_last_render_error');
        if (is_array($failure) && !empty($failure['time']) && time() - $failure['time'] < 7 * DAY_IN_SECONDS) {
            $checks['render_error'] = array(
                'status' => 'warning',
                'label' => __('An ad failed to render', 'simple-google-adsense'),
                /* translators: 1: ad ID, 2: date, 3: error */
                'message' => sprintf(__('Ad #%1$d on %2$s: %3$s. The page kept working; the ad was skipped.', 'simple-google-adsense'), (int) $failure['ad_id'], wp_date(get_option('date_format') . ' ' . get_option('time_format'), $failure['time']), $failure['message']),
            );
        }

        $ending = array();
        foreach (Simple_Google_Adsense_Ad_Units::get_all() as $unit) {
            if (!Simple_Google_Adsense_Ad_Units::is_own_ad($unit['type'])) {
                continue;
            }
            $schedule = Simple_Google_Adsense_Ad_Units::schedule($unit);
            if ($schedule['end'] && $schedule['end'] > time() && $schedule['end'] - time() < 7 * DAY_IN_SECONDS) {
                $ending[] = $unit['title'];
            }
        }

        if ($ending) {
            $checks['ending'] = array(
                'status' => 'warning',
                'label' => __('Campaigns ending soon', 'simple-google-adsense'),
                /* translators: %s: ad names */
                'message' => sprintf(__('Ending within 7 days: %s. Renew the deal or set an AdSense fallback.', 'simple-google-adsense'), implode(', ', $ending)),
                'action' => admin_url('edit.php?post_type=' . Simple_Google_Adsense_Ad_Units::POST_TYPE),
                'action_label' => __('Review', 'simple-google-adsense'),
            );
        }

        if (Simple_Google_Adsense_Settings::get('hide_for_admins')) {
            $checks['admins'] = array('status' => 'info', 'label' => __('Admin visibility', 'simple-google-adsense'), 'message' => __('Ads are hidden while you are logged in. Log out or use a private window to see them.', 'simple-google-adsense'));
        }

        $cache = self::detect_plugins(array(
            'wp-rocket/wp-rocket.php' => 'WP Rocket',
            'litespeed-cache/litespeed-cache.php' => 'LiteSpeed Cache',
            'w3-total-cache/w3-total-cache.php' => 'W3 Total Cache',
            'wp-super-cache/wp-cache.php' => 'WP Super Cache',
            'wp-fastest-cache/wpFastestCache.php' => 'WP Fastest Cache',
            'autoptimize/autoptimize.php' => 'Autoptimize',
            'sg-cachepress/sg-cachepress.php' => 'SiteGround Optimizer',
        ));

        if ($cache) {
            $checks['cache'] = array('status' => 'info', 'label' => __('Caching', 'simple-google-adsense'), 'message' => sprintf(/* translators: %s: plugin names */ __('%s detected. Purge the cache after changing ads, and exclude adsbygoogle.js from JavaScript delay/combine features.', 'simple-google-adsense'), implode(', ', $cache)));
        }

        $cmp = self::detect_plugins(array(
            'complianz-gdpr/complianz-gpdr.php' => 'Complianz',
            'complianz-gdpr-premium/complianz-gpdr-premium.php' => 'Complianz',
            'cookie-law-info/cookie-law-info.php' => 'CookieYes',
            'cookiebot/cookiebot.php' => 'Cookiebot',
            'gdpr-cookie-compliance/moove-gdpr.php' => 'GDPR Cookie Compliance',
        ));

        $checks['consent'] = $cmp
            ? array('status' => 'ok', 'label' => __('Consent (EEA/UK)', 'simple-google-adsense'), 'message' => sprintf(/* translators: %s: plugin names */ __('%s detected. Make sure it is configured as a Google-certified CMP (IAB TCF).', 'simple-google-adsense'), implode(', ', array_unique($cmp))))
            : array('status' => 'info', 'label' => __('Consent (EEA/UK)', 'simple-google-adsense'), 'message' => __('Google requires a certified consent message for visitors from the EEA, UK and Switzerland. Use AdSense\'s own "Privacy & messaging" or a certified CMP plugin.', 'simple-google-adsense'), 'action' => 'https://support.google.com/adsense/answer/13554116', 'action_label' => __('Learn more', 'simple-google-adsense'));

        /**
         * Filters the health checks. Pro adds its own.
         *
         * @param array $checks
         * @since 1.4.0
         */
        return apply_filters('adflow_health_checks', $checks);
    }

    /**
     * Names of the given plugins that are active.
     *
     * @param array $plugins basename => name.
     * @return string[]
     * @since 1.4.0
     */
    public static function detect_plugins($plugins)
    {
        $active = (array) get_option('active_plugins', array());
        $found = array();

        foreach ($plugins as $basename => $name) {
            if (in_array($basename, $active, true)) {
                $found[] = $name;
            }
        }

        return $found;
    }

    /**
     * Health checks moved to the Dashboard.
     *
     * @deprecated 1.4.0
     */
    public function health_page()
    {
        Simple_Google_Adsense_Dashboard::instance()->render();
    }

    /**
     * Free vs Pro screen.
     *
     * @since 1.4.0
     */
    public function pro_page()
    {
        Simple_Google_Adsense_Upsell::render_pro_page();
    }

    /*
    |--------------------------------------------------------------------------
    | Review request
    |--------------------------------------------------------------------------
    */

    /**
     * Ask for a review - once, on AdFlow screens only, after the plugin has
     * been in use for a week with ads configured.
     *
     * @since 1.4.0
     */
    public function review_notice()
    {
        if (!self::is_adflow_screen() || !current_user_can('manage_options') || get_option('simple_google_adsense_review_dismissed')) {
            return;
        }

        $installed = (int) get_option('simple_google_adsense_installed', 0);

        if (!$installed) {
            update_option('simple_google_adsense_installed', time(), false);
            return;
        }

        if (time() - $installed < 7 * DAY_IN_SECONDS || !Simple_Google_Adsense_Settings::is_publisher_id_valid()) {
            return;
        }

        $dismiss = wp_nonce_url(admin_url('admin-post.php?action=adflow_dismiss_review'), 'adflow_dismiss_review');
        ?>
        <div class="notice notice-info adflow-review-notice">
            <p><strong><?php esc_html_e('Is AdFlow helping you earn from AdSense?', 'simple-google-adsense'); ?></strong>
                <?php esc_html_e('A quick review on WordPress.org helps other publishers find it - and keeps the free version growing.', 'simple-google-adsense'); ?></p>
            <p>
                <a class="button button-primary" href="https://wordpress.org/support/plugin/simple-google-adsense/reviews/#new-post" target="_blank" rel="noopener"><?php esc_html_e('Leave a review', 'simple-google-adsense'); ?></a>
                <a class="button" href="<?php echo esc_url($dismiss); ?>"><?php esc_html_e('Already did / No thanks', 'simple-google-adsense'); ?></a>
            </p>
        </div>
        <?php
    }

    /**
     * Dismiss the review notice for good.
     *
     * @since 1.4.0
     */
    public function dismiss_review()
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to do this.', 'simple-google-adsense'));
        }

        check_admin_referer('adflow_dismiss_review');
        update_option('simple_google_adsense_review_dismissed', 1, false);

        wp_safe_redirect(wp_get_referer() ? wp_get_referer() : self::dashboard_url());
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | Assets
    |--------------------------------------------------------------------------
    */

    /**
     * Enqueue admin styles and scripts
     */
    public function enqueue_admin_styles()
    {
        if (!self::is_adflow_screen()) {
            // The small "Pro" badge in the AdFlow menu is visible on every screen.
            wp_add_inline_style('admin-menu', '#adminmenu .adflow-menu-badge{display:inline-block;margin-left:4px;padding:0 5px;border-radius:3px;background:rgba(255,255,255,.16);color:#fff;font-size:9px;font-weight:600;line-height:15px;letter-spacing:.04em;text-transform:uppercase;vertical-align:1px}');
            return;
        }

        wp_enqueue_style('adflow-admin', SIMPLE_GOOGLE_ADSENSE_PLUGIN_URI . '/assets/css/admin/admin.css', array(), SIMPLE_GOOGLE_ADSENSE_VERSION);
        wp_enqueue_script('adflow-admin', SIMPLE_GOOGLE_ADSENSE_PLUGIN_URI . '/assets/js/admin.js', array(), SIMPLE_GOOGLE_ADSENSE_VERSION, true);
        wp_localize_script('adflow-admin', 'adflowAdmin', array(
            'copied' => __('Copied', 'simple-google-adsense'),
        ));
    }

    /**
     * Include required core files used in admin.
     */
    public function includes()
    {
        include_once SIMPLE_GOOGLE_ADSENSE_ABSPATH . 'includes/admin/dashboard/class-mantrabrain-admin-dashboard.php';
    }
}
