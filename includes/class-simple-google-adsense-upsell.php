<?php
/**
 * Simple_Google_Adsense AdFlow Pro presentation
 *
 * Everything the free plugin says about AdFlow Pro lives here: the feature
 * catalogue, pricing, tracked links, the "AdFlow Pro" screen, the Earnings
 * preview and small contextual cards.
 *
 * WordPress.org rules this class follows: Pro is only mentioned on AdFlow's
 * own screens (plus one plugin-row link), every notice is dismissible, no
 * free feature is locked or degraded, and nothing loads when Pro is active.
 *
 * @package Simple_Google_Adsense
 * @since   1.4.0
 */

defined('ABSPATH') || exit;

/**
 * Simple_Google_Adsense_Upsell Class.
 *
 * @class Simple_Google_Adsense_Upsell
 */
final class Simple_Google_Adsense_Upsell
{

    /**
     * Earnings preview slug (the same slug Pro's real Earnings screen uses,
     * so bookmarks keep working after upgrading).
     */
    const EARNINGS_SLUG = 'adflow-earnings';

    /**
     * The single instance of the class.
     *
     * @var Simple_Google_Adsense_Upsell
     * @since 1.4.0
     */
    protected static $_instance = null;

    /**
     * Main Simple_Google_Adsense_Upsell Instance.
     *
     * @return Simple_Google_Adsense_Upsell
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
     * Simple_Google_Adsense_Upsell Constructor.
     */
    public function __construct()
    {
        // Pro is defined before plugins_loaded; decide once everything has loaded.
        add_action('plugins_loaded', array($this, 'init'), 30);
    }

    /**
     * Register hooks when Pro is not active.
     *
     * @since 1.4.0
     */
    public function init()
    {
        if (Simple_Google_Adsense_Settings::is_pro_active() || !apply_filters('adflow_show_pro_promotions', true)) {
            return;
        }

        add_action('admin_menu', array($this, 'add_earnings_preview'), 20);
        add_action('adflow_ad_unit_fields', array($this, 'ad_unit_teaser'));
        add_action('adflow_placement_settings_fields', array($this, 'placement_teaser'), 20, 3);
        add_filter('adflow_health_checks', array($this, 'health_checks'), 20);
        add_action('admin_notices', array($this, 'milestone_notice'));
        add_filter('adflow_dashboard_cards', array($this, 'dashboard_card'));
        add_action('adflow_reports_after', array($this, 'reports_teaser'));
        add_action('adflow_dashboard_sidebar', array($this, 'dashboard_upgrade_card'));
        add_action('admin_post_adflow_dismiss_pro_notice', array($this, 'dismiss_notice'));
    }

    /**
     * Plans shown in the plugin. Keep in sync with the store (or filter).
     *
     * @return array[] key => name, price, period, sites, highlight, note
     * @since 1.4.0
     */
    public static function plans()
    {
        return apply_filters('adflow_pro_plans', array(
            'personal' => array(
                'name' => __('Personal', 'simple-google-adsense'),
                'price' => '$49',
                'period' => __('/ year', 'simple-google-adsense'),
                'sites' => __('1 website', 'simple-google-adsense'),
                'highlight' => false,
                'note' => __('For bloggers with one site', 'simple-google-adsense'),
            ),
            'plus' => array(
                'name' => __('Plus', 'simple-google-adsense'),
                'price' => '$99',
                'period' => __('/ year', 'simple-google-adsense'),
                'sites' => __('5 websites', 'simple-google-adsense'),
                'highlight' => true,
                'note' => __('Most popular with publishers', 'simple-google-adsense'),
            ),
            'agency' => array(
                'name' => __('Agency', 'simple-google-adsense'),
                'price' => '$149',
                'period' => __('/ year', 'simple-google-adsense'),
                'sites' => __('25 websites', 'simple-google-adsense'),
                'highlight' => false,
                'note' => __('For freelancers and networks of sites', 'simple-google-adsense'),
            ),
        ));
    }

    /**
     * Pro feature catalogue: one place for names, benefits and icons.
     *
     * @return array[] key => title, benefit, icon, pillar
     * @since 1.4.0
     */
    public static function features()
    {
        return apply_filters('adflow_pro_features', array(
            'earnings' => array(
                'pillar' => 'see',
                'icon' => 'chart-bar',
                'title' => __('AdSense earnings in WordPress', 'simple-google-adsense'),
                'benefit' => __('Today, yesterday, this month, page RPM and top-earning pages - without logging in to AdSense.', 'simple-google-adsense'),
            ),
            'ab' => array(
                'pillar' => 'see',
                'icon' => 'randomize',
                'title' => __('A/B tests judged by real earnings', 'simple-google-adsense'),
                'benefit' => __('Rotate ad units and see which one earns more, using AdSense\'s own numbers.', 'simple-google-adsense'),
            ),
            'click_protection' => array(
                'pillar' => 'protect',
                'icon' => 'shield',
                'title' => __('Invalid-click protection', 'simple-google-adsense'),
                'benefit' => __('Visitors who click too often stop seeing ads - the most common cause of AdSense limits and bans.', 'simple-google-adsense'),
            ),
            'limits' => array(
                'pillar' => 'protect',
                'icon' => 'editor-ol',
                'title' => __('Ad limits & per-post control', 'simple-google-adsense'),
                'benefit' => __('Cap ads per page and switch ads off on any post with one tick.', 'simple-google-adsense'),
            ),
            'placements' => array(
                'pillar' => 'earn',
                'icon' => 'layout',
                'title' => __('More places to earn', 'simple-google-adsense'),
                'benefit' => __('Middle of the article, between posts, before comments, sticky anchor and WooCommerce pages.', 'simple-google-adsense'),
            ),
            'targeting' => array(
                'pillar' => 'earn',
                'icon' => 'filter',
                'title' => __('Targeting & scheduling', 'simple-google-adsense'),
                'benefit' => __('Show ads by device, category, search traffic, visitor type, post age or date range. Cache-friendly.', 'simple-google-adsense'),
            ),
            'speed' => array(
                'pillar' => 'earn',
                'icon' => 'performance',
                'title' => __('Faster pages, no layout jumps', 'simple-google-adsense'),
                'benefit' => __('Lazy-load ads and reserve their space for better Core Web Vitals and viewability.', 'simple-google-adsense'),
            ),
            'sponsors' => array(
                'pillar' => 'earn',
                'icon' => 'megaphone',
                'title' => __('Sell ads directly', 'simple-google-adsense'),
                'benefit' => __('An "Advertise with us" page where sponsors order and pay, country targeting, automatic weekly reports and sponsor eCPM compared with AdSense.', 'simple-google-adsense'),
            ),
            'adblock' => array(
                'pillar' => 'earn',
                'icon' => 'visibility',
                'title' => __('Ad-blocker message', 'simple-google-adsense'),
                'benefit' => __('Politely ask ad-block users to support your site.', 'simple-google-adsense'),
            ),
        ));
    }

    /**
     * Tracked link to the Pro page / checkout.
     *
     * @param string $campaign Where the link is shown.
     * @param string $plan Optional plan key to preselect.
     * @return string
     * @since 1.4.0
     */
    public static function url($campaign, $plan = '')
    {
        $url = Simple_Google_Adsense_Admin::pro_url($campaign);

        return '' === $plan ? $url : add_query_arg('plan', sanitize_key($plan), $url) . '#pricing';
    }

    /**
     * Small contextual card.
     *
     * @param string $feature Feature key.
     * @param string $campaign Tracking campaign.
     * @param string $tag Wrapper tag.
     * @since 1.4.0
     */
    public static function render_card($feature, $campaign, $tag = 'div')
    {
        $features = self::features();

        if (!isset($features[$feature]) || Simple_Google_Adsense_Settings::is_pro_active()) {
            return;
        }

        $f = $features[$feature];
        $tag = in_array($tag, array('div', 'td'), true) ? $tag : 'div';
        ?>
        <<?php echo esc_html($tag); ?> class="adflow-upsell-card">
            <span class="dashicons dashicons-<?php echo esc_attr($f['icon']); ?>" aria-hidden="true"></span>
            <div>
                <strong><?php echo esc_html($f['title']); ?></strong> <span class="adflow-pro-tag"><?php esc_html_e('Pro', 'simple-google-adsense'); ?></span>
                <p><?php echo esc_html($f['benefit']); ?></p>
            </div>
            <a class="button" href="<?php echo esc_url(self::url($campaign)); ?>" target="_blank" rel="noopener"><?php esc_html_e('Learn more', 'simple-google-adsense'); ?></a>
        </<?php echo esc_html($tag); ?>>
        <?php
    }

    /**
     * "Earnings" menu item that previews the Pro dashboard.
     *
     * @since 1.4.0
     */
    public function add_earnings_preview()
    {
        add_submenu_page(
            Simple_Google_Adsense_Admin::MENU_SLUG,
            __('AdSense Earnings', 'simple-google-adsense'),
            __('Earnings', 'simple-google-adsense') . ' <span class="adflow-menu-badge">' . esc_html__('Pro', 'simple-google-adsense') . '</span>',
            Simple_Google_Adsense_Caps::VIEW_REPORTS,
            self::EARNINGS_SLUG,
            array($this, 'render_earnings_preview')
        );
    }

    /**
     * Earnings preview: a clearly labelled sample of the Pro dashboard.
     *
     * @since 1.4.0
     */
    public function render_earnings_preview()
    {
        $bars = array(42, 55, 48, 61, 58, 72, 66, 80, 74, 69, 85, 91, 78, 88);
        $max = max($bars);
        ?>
        <div class="wrap adflow-page">
            <?php Simple_Google_Adsense_Admin::render_header(__('Earnings', 'simple-google-adsense'), __('Your AdSense earnings, right inside WordPress.', 'simple-google-adsense')); ?>

            <div class="adflow-preview">
                <div class="adflow-preview-sample" aria-hidden="true">
                    <span class="adflow-preview-ribbon"><?php esc_html_e('Sample data', 'simple-google-adsense'); ?></span>
                    <div class="adflow-preview-kpis">
                        <div><small><?php esc_html_e('Today', 'simple-google-adsense'); ?></small><strong>$18.42</strong></div>
                        <div><small><?php esc_html_e('This month', 'simple-google-adsense'); ?></small><strong>$512.90</strong></div>
                        <div><small><?php esc_html_e('Page RPM', 'simple-google-adsense'); ?></small><strong>$6.35</strong></div>
                        <div><small><?php esc_html_e('Clicks', 'simple-google-adsense'); ?></small><strong>1,204</strong></div>
                    </div>
                    <svg class="adflow-preview-chart" viewBox="0 0 280 90" preserveAspectRatio="none" focusable="false">
                        <?php foreach ($bars as $i => $value) :
                            $height = round($value / $max * 80);
                            ?>
                            <rect x="<?php echo esc_attr($i * 20 + 3); ?>" y="<?php echo esc_attr(88 - $height); ?>" width="14" height="<?php echo esc_attr($height); ?>" rx="2"></rect>
                        <?php endforeach; ?>
                    </svg>
                    <table class="widefat striped">
                        <thead><tr><th><?php esc_html_e('Ad unit', 'simple-google-adsense'); ?></th><th><?php esc_html_e('Earnings', 'simple-google-adsense'); ?></th><th><?php esc_html_e('RPM', 'simple-google-adsense'); ?></th></tr></thead>
                        <tbody>
                            <tr><td><?php esc_html_e('In-article (after paragraph 3)', 'simple-google-adsense'); ?> <span class="adflow-pro-tag"><?php esc_html_e('Winner', 'simple-google-adsense'); ?></span></td><td>$301.20</td><td>$7.90</td></tr>
                            <tr><td><?php esc_html_e('Sidebar 300x250', 'simple-google-adsense'); ?></td><td>$148.75</td><td>$4.10</td></tr>
                        </tbody>
                    </table>
                </div>

                <div class="adflow-preview-cta">
                    <h2><?php esc_html_e('See what your ads really earn - right here.', 'simple-google-adsense'); ?></h2>
                    <ul class="adflow-ticks">
                        <li><?php esc_html_e('Earnings today, this month and by day', 'simple-google-adsense'); ?></li>
                        <li><?php esc_html_e('Which ad unit and which page earn the most', 'simple-google-adsense'); ?></li>
                        <li><?php esc_html_e('A/B test winners from AdSense\'s own numbers', 'simple-google-adsense'); ?></li>
                        <li><?php esc_html_e('An earnings widget on your WordPress dashboard', 'simple-google-adsense'); ?></li>
                    </ul>
                    <a class="button button-primary button-hero" href="<?php echo esc_url(self::url('earnings-preview')); ?>" target="_blank" rel="noopener"><?php esc_html_e('Unlock earnings with AdFlow Pro', 'simple-google-adsense'); ?></a>
                    <p class="description"><?php esc_html_e('Read-only access to your AdSense reports. The numbers above are an example, not your data.', 'simple-google-adsense'); ?></p>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Teaser row on the ad unit screen.
     *
     * @since 1.4.0
     */
    public function ad_unit_teaser()
    {
        Simple_Google_Adsense_Admin::field_start(__('Reserved height', 'simple-google-adsense'), __('Keeps space for the ad so the page does not jump while it loads.', 'simple-google-adsense'));
        ?>
        <p class="adflow-upsell-line" style="margin:0">
            <span class="adflow-pro-tag"><?php esc_html_e('Pro', 'simple-google-adsense'); ?></span>
            <?php esc_html_e('Reserve space and lazy-load this ad for better Core Web Vitals.', 'simple-google-adsense'); ?>
            <a href="<?php echo esc_url(self::url('ad-unit')); ?>" target="_blank" rel="noopener"><?php esc_html_e('Learn more', 'simple-google-adsense'); ?></a>
        </p>
        <?php
        Simple_Google_Adsense_Admin::field_end();
    }

    /**
     * One-line teaser inside each placement card.
     *
     * @param string $key Placement key.
     * @param array $placement Settings.
     * @param string $name Input prefix.
     * @since 1.4.0
     */
    public function placement_teaser($key, $placement, $name)
    {
        ?>
        <p class="adflow-upsell-line">
            <span class="adflow-pro-tag"><?php esc_html_e('Pro', 'simple-google-adsense'); ?></span>
            <?php esc_html_e('Target by device, category or search traffic, schedule dates and A/B test ad units.', 'simple-google-adsense'); ?>
            <a href="<?php echo esc_url(self::url('placement-card')); ?>" target="_blank" rel="noopener"><?php esc_html_e('Learn more', 'simple-google-adsense'); ?></a>
        </p>
        <?php
    }

    /**
     * Pro reporting features, below the free report.
     *
     * @since 1.4.0
     */
    public function reports_teaser()
    {
        ?>
        <p class="adflow-upsell-line">
            <span class="adflow-pro-tag"><?php esc_html_e('Pro', 'simple-google-adsense'); ?></span>
            <?php esc_html_e('Custom date ranges, breakdowns by placement, advertiser and device, CSV export, shareable advertiser reports, expiry emails and sponsor eCPM next to your AdSense RPM.', 'simple-google-adsense'); ?>
            <a href="<?php echo esc_url(self::url('reports')); ?>" target="_blank" rel="noopener"><?php esc_html_e('Learn more', 'simple-google-adsense'); ?></a>
        </p>
        <?php
    }

    /**
     * Earnings card on the Dashboard (preview in the free version).
     *
     * @param array $cards Cards.
     * @return array
     * @since 1.4.0
     */
    public function dashboard_card($cards)
    {
        $cards['earnings'] = array(
            'label' => __('Earnings', 'simple-google-adsense'),
            'value' => __('See it here', 'simple-google-adsense'),
            'meta' => __('Your AdSense earnings, per ad unit and page, inside WordPress.', 'simple-google-adsense'),
            'badge' => __('Pro', 'simple-google-adsense'),
            'muted' => true,
            'link' => admin_url('admin.php?page=' . self::EARNINGS_SLUG),
            'link_label' => __('Preview', 'simple-google-adsense'),
        );

        return $cards;
    }

    /**
     * Upgrade card in the Dashboard sidebar.
     *
     * @since 1.4.0
     */
    public function dashboard_upgrade_card()
    {
        ?>
        <div class="adflow-upgrade-card">
            <h2><?php esc_html_e('Get more from AdSense with Pro', 'simple-google-adsense'); ?></h2>
            <p><?php esc_html_e('Know what earns, protect your account, and earn more per visit.', 'simple-google-adsense'); ?></p>
            <ul>
                <li><?php esc_html_e('Earnings dashboard in WordPress', 'simple-google-adsense'); ?></li>
                <li><?php esc_html_e('Invalid-click protection', 'simple-google-adsense'); ?></li>
                <li><?php esc_html_e('Mid-article, sticky & WooCommerce ads', 'simple-google-adsense'); ?></li>
                <li><?php esc_html_e('A/B tests judged by real earnings', 'simple-google-adsense'); ?></li>
            </ul>
            <a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=' . Simple_Google_Adsense_Admin::PRO_SLUG)); ?>"><?php esc_html_e('See what\'s in Pro', 'simple-google-adsense'); ?></a>
        </div>
        <?php
    }

    /**
     * Pro-related checks on the Health Check screen.
     *
     * @param array $checks Checks.
     * @return array
     * @since 1.4.0
     */
    public function health_checks($checks)
    {
        $checks['pro_click_protection'] = array(
            'status' => 'info',
            'label' => __('Invalid-click protection', 'simple-google-adsense'),
            'message' => __('Not active. Repeated clicks from one visitor are the most common reason AdSense limits accounts.', 'simple-google-adsense'),
            'action' => self::url('health-click-protection'),
            'action_label' => __('Protect with Pro', 'simple-google-adsense'),
        );

        return $checks;
    }

    /**
     * One-time, dismissible note after three weeks of real use.
     *
     * Shown only on AdFlow screens, never together with the review request.
     *
     * @since 1.4.0
     */
    public function milestone_notice()
    {
        if (!Simple_Google_Adsense_Admin::is_adflow_screen() || !current_user_can('manage_options') || get_option('adflow_pro_notice_dismissed')) {
            return;
        }

        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        // Not on the screens that are already about Pro.
        if (in_array($page, array(Simple_Google_Adsense_Admin::PRO_SLUG, self::EARNINGS_SLUG), true)) {
            return;
        }

        $installed = (int) get_option('simple_google_adsense_installed', 0);

        if (!$installed || time() - $installed < 21 * DAY_IN_SECONDS || !Simple_Google_Adsense_Settings::is_publisher_id_valid()) {
            return;
        }

        // The review request comes first; this waits until it is answered.
        if (!get_option('simple_google_adsense_review_dismissed')) {
            return;
        }

        $dismiss = wp_nonce_url(admin_url('admin-post.php?action=adflow_dismiss_pro_notice'), 'adflow_dismiss_pro_notice');
        ?>
        <div class="notice notice-info adflow-pro-notice">
            <p><strong><?php esc_html_e('You have been running AdSense with AdFlow for a few weeks.', 'simple-google-adsense'); ?></strong>
                <?php esc_html_e('AdFlow Pro shows which ads and pages actually earn, protects your account from click abuse and adds higher-earning placements.', 'simple-google-adsense'); ?></p>
            <p>
                <a class="button button-primary" href="<?php echo esc_url(self::url('milestone-notice')); ?>" target="_blank" rel="noopener"><?php esc_html_e('See AdFlow Pro', 'simple-google-adsense'); ?></a>
                <a class="button" href="<?php echo esc_url($dismiss); ?>"><?php esc_html_e('No thanks', 'simple-google-adsense'); ?></a>
            </p>
        </div>
        <?php
    }

    /**
     * Dismiss the milestone note for good.
     *
     * @since 1.4.0
     */
    public function dismiss_notice()
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to do this.', 'simple-google-adsense'));
        }

        check_admin_referer('adflow_dismiss_pro_notice');
        update_option('adflow_pro_notice_dismissed', 1, false);

        wp_safe_redirect(wp_get_referer() ? wp_get_referer() : admin_url('admin.php?page=' . Simple_Google_Adsense_Admin::MENU_SLUG));
        exit;
    }

    /**
     * The "AdFlow Pro" screen.
     *
     * @since 1.4.0
     */
    public static function render_pro_page()
    {
        $features = self::features();
        $pillars = array(
            'see' => array('icon' => 'chart-area', 'title' => __('See your money', 'simple-google-adsense'), 'text' => __('Know which ads, units and pages earn - inside WordPress.', 'simple-google-adsense')),
            'protect' => array('icon' => 'shield', 'title' => __('Protect your account', 'simple-google-adsense'), 'text' => __('Reduce the risk of invalid-click limits and policy trouble.', 'simple-google-adsense')),
            'earn' => array('icon' => 'money-alt', 'title' => __('Earn more per visit', 'simple-google-adsense'), 'text' => __('Better placements, smarter targeting, faster pages.', 'simple-google-adsense')),
        );
        $faq = array(
            __('Will my free setup keep working?', 'simple-google-adsense') => __('Yes. Pro is an add-on: your ad units, placements and settings stay exactly as they are, and everything in the free plugin stays free.', 'simple-google-adsense'),
            __('Is it safe for my AdSense account?', 'simple-google-adsense') => __('Pro only uses Google\'s official ad code and read-only reporting access. Click protection stops ads from loading for abusive visitors instead of hiding ads, which AdSense does not allow. No tool can guarantee Google\'s decisions, but Pro removes the most common risks.', 'simple-google-adsense'),
            __('Does it work with page caching and Auto Ads?', 'simple-google-adsense') => __('Yes. Device and traffic-source targeting runs in the browser, so it works behind page caches, and Auto Ads keep running alongside your placements.', 'simple-google-adsense'),
            __('What if my license expires?', 'simple-google-adsense') => __('Pro keeps working. A license gives you updates and priority support.', 'simple-google-adsense'),
            __('Can I get a refund?', 'simple-google-adsense') => __('Yes - 14 days, no questions asked.', 'simple-google-adsense'),
        );
        ?>
        <div class="wrap adflow-page adflow-pro-page">
            <?php Simple_Google_Adsense_Admin::render_header(__('Upgrade to AdFlow Pro', 'simple-google-adsense')); ?>

            <section class="adflow-pro-hero adflow-card">
                <p class="adflow-eyebrow"><?php esc_html_e('Built only for Google AdSense', 'simple-google-adsense'); ?></p>
                <h2><?php esc_html_e('Earn more from AdSense - and keep your account safe.', 'simple-google-adsense'); ?></h2>
                <p><?php esc_html_e('AdFlow Pro shows you what every ad earns, protects you from click abuse and puts ads where they pay the most. Set up in minutes, no code.', 'simple-google-adsense'); ?></p>
                <a class="button button-primary button-hero" href="<?php echo esc_url(self::url('pro-page-hero', 'plus')); ?>" target="_blank" rel="noopener"><?php esc_html_e('Get AdFlow Pro', 'simple-google-adsense'); ?></a>
                <p class="description"><?php esc_html_e('14-day money-back guarantee · Works with your current setup', 'simple-google-adsense'); ?></p>
            </section>

            <section class="adflow-pillars">
                <?php foreach ($pillars as $pillar_key => $pillar) : ?>
                    <div class="adflow-card adflow-pillar">
                        <span class="dashicons dashicons-<?php echo esc_attr($pillar['icon']); ?>" aria-hidden="true"></span>
                        <h3><?php echo esc_html($pillar['title']); ?></h3>
                        <p><?php echo esc_html($pillar['text']); ?></p>
                        <ul>
                            <?php foreach ($features as $feature) :
                                if ($pillar_key !== $feature['pillar']) {
                                    continue;
                                } ?>
                                <li><strong><?php echo esc_html($feature['title']); ?></strong><br><?php echo esc_html($feature['benefit']); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endforeach; ?>
            </section>

            <section class="adflow-pricing" aria-label="<?php esc_attr_e('Pricing', 'simple-google-adsense'); ?>">
                <?php foreach (self::plans() as $plan_key => $plan) : ?>
                    <div class="adflow-card adflow-plan <?php echo !empty($plan['highlight']) ? 'is-highlight' : ''; ?>">
                        <?php if (!empty($plan['highlight'])) : ?>
                            <span class="adflow-plan-flag"><?php esc_html_e('Most popular', 'simple-google-adsense'); ?></span>
                        <?php endif; ?>
                        <h3><?php echo esc_html($plan['name']); ?></h3>
                        <p class="adflow-plan-price"><strong><?php echo esc_html($plan['price']); ?></strong> <span><?php echo esc_html($plan['period']); ?></span></p>
                        <p class="adflow-plan-sites"><?php echo esc_html($plan['sites']); ?></p>
                        <p class="description"><?php echo esc_html($plan['note']); ?></p>
                        <a class="button <?php echo !empty($plan['highlight']) ? 'button-primary' : ''; ?>" href="<?php echo esc_url(self::url('pro-page-pricing', $plan_key)); ?>" target="_blank" rel="noopener">
                            <?php
                            /* translators: %s: plan name */
                            printf(esc_html__('Choose %s', 'simple-google-adsense'), esc_html($plan['name']));
                            ?>
                        </a>
                    </div>
                <?php endforeach; ?>
            </section>
            <p class="adflow-pricing-note description"><?php esc_html_e('All plans include every Pro feature, one year of updates and priority support.', 'simple-google-adsense'); ?></p>

            <?php self::render_comparison(); ?>

            <section class="adflow-card adflow-faq">
                <h3><?php esc_html_e('Questions', 'simple-google-adsense'); ?></h3>
                <?php foreach ($faq as $question => $answer) : ?>
                    <details>
                        <summary><?php echo esc_html($question); ?></summary>
                        <p><?php echo esc_html($answer); ?></p>
                    </details>
                <?php endforeach; ?>
            </section>

            <section class="adflow-pro-hero adflow-card adflow-pro-final">
                <h2><?php esc_html_e('Ready to see what your ads really earn?', 'simple-google-adsense'); ?></h2>
                <a class="button button-primary button-hero" href="<?php echo esc_url(self::url('pro-page-footer', 'plus')); ?>" target="_blank" rel="noopener"><?php esc_html_e('Get AdFlow Pro', 'simple-google-adsense'); ?></a>
            </section>
        </div>
        <?php
    }

    /**
     * Free vs Pro table.
     *
     * @since 1.4.0
     */
    private static function render_comparison()
    {
        $rows = array(
            array(__('Auto Ads with page & post type exclusions', 'simple-google-adsense'), true),
            array(__('Shortcodes, block, widget & reusable ad units', 'simple-google-adsense'), true),
            array(__('Automatic placements: before/after content, after paragraph', 'simple-google-adsense'), true),
            array(__('ads.txt manager & live checker', 'simple-google-adsense'), true),
            array(__('Health Check & on-page Ad Inspector', 'simple-google-adsense'), true),
            array(__('Ad label & custom code units for other networks', 'simple-google-adsense'), true),
            array(__('Your own ads: image banners & text ads with sponsored links, schedule & AdSense fallback', 'simple-google-adsense'), true),
            array(__('Cookieless impression, viewable (IAB/MRC) & click stats for your own ads, cache-proof', 'simple-google-adsense'), true),
            array(__('Rotation groups (weighted or in order), cache-proof', 'simple-google-adsense'), true),
            array(__('Roles & permissions, approval workflow, "Will this ad show?" checks, failsafe rendering', 'simple-google-adsense'), true),
            array(__('One-click import from Advanced Ads, Ad Inserter, AdRotate & WP QUADS (old shortcodes keep working)', 'simple-google-adsense'), true),
            array(__('Google Consent Mode v2 & load ads after consent (TCF v2.2, WP Consent API)', 'simple-google-adsense'), true),
            array(__('AdSense earnings dashboard & dashboard widget', 'simple-google-adsense'), false),
            array(__('Per-unit and per-page earnings, A/B winner', 'simple-google-adsense'), false),
            array(__('Invalid-click protection', 'simple-google-adsense'), false),
            array(__('Mid-article (X%), between posts, before comments, sticky anchor, WooCommerce', 'simple-google-adsense'), false),
            array(__('Targeting: device, category, search traffic, visitor type, post age', 'simple-google-adsense'), false),
            array(__('Scheduling (start / end dates)', 'simple-google-adsense'), false),
            array(__('Country targeting for sponsors, cache-proof, on any host', 'simple-google-adsense'), false),
            array(__('Self-serve ad sales: packages, advertiser upload, your payment link, approval', 'simple-google-adsense'), false),
            array(__('Popup (sponsor ads) & sticky sidebar formats', 'simple-google-adsense'), false),
            array(__('Google Ad Manager (GPT) units with sizes & key-values', 'simple-google-adsense'), false),
            array(__('Lazy loading & reserved height (Core Web Vitals)', 'simple-google-adsense'), false),
            array(__('Max ads per page & per-post ad controls', 'simple-google-adsense'), false),
            array(__('Ad-blocker message', 'simple-google-adsense'), false),
            array(__('Export / import between sites', 'simple-google-adsense'), false),
            array(__('Sponsor reports: custom dates, placements, devices, advertisers, CSV', 'simple-google-adsense'), false),
            array(__('Shareable advertiser report links, weekly/monthly advertiser emails & expiry reminders', 'simple-google-adsense'), false),
            array(__('Sponsor eCPM next to your AdSense RPM', 'simple-google-adsense'), false),
            array(__('Frequency capping & dayparting for your own ads', 'simple-google-adsense'), false),
            array(__('Activity log (who changed what, when)', 'simple-google-adsense'), false),
            array(__('REST API & WP-CLI for reports and automation', 'simple-google-adsense'), false),
            array(__('Priority support', 'simple-google-adsense'), false),
        );
        ?>
        <table class="widefat striped adflow-compare">
            <thead>
                <tr>
                    <th><?php esc_html_e('Feature', 'simple-google-adsense'); ?></th>
                    <th><?php esc_html_e('Free', 'simple-google-adsense'); ?></th>
                    <th><?php esc_html_e('Pro', 'simple-google-adsense'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row) : ?>
                    <tr>
                        <td><?php echo esc_html($row[0]); ?></td>
                        <td><?php echo $row[1] ? '<span class="dashicons dashicons-yes" aria-label="' . esc_attr__('Yes', 'simple-google-adsense') . '"></span>' : '<span aria-label="' . esc_attr__('No', 'simple-google-adsense') . '">-</span>'; ?></td>
                        <td><span class="dashicons dashicons-yes" aria-label="<?php esc_attr_e('Yes', 'simple-google-adsense'); ?>"></span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }
}
