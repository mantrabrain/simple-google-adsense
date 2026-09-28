<?php
/**
 * Simple_Google_Adsense Dashboard
 *
 * The AdFlow landing screen: key numbers, a setup checklist until the site
 * is fully set up, site health, and shortcuts.
 *
 * @package Simple_Google_Adsense
 * @since   1.4.0
 */

defined('ABSPATH') || exit;

/**
 * Simple_Google_Adsense_Dashboard Class.
 *
 * @class Simple_Google_Adsense_Dashboard
 */
final class Simple_Google_Adsense_Dashboard
{

    /**
     * The single instance of the class.
     *
     * @var Simple_Google_Adsense_Dashboard
     * @since 1.4.0
     */
    protected static $_instance = null;

    /**
     * Main Simple_Google_Adsense_Dashboard Instance.
     *
     * @return Simple_Google_Adsense_Dashboard
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
     * Setup steps. Pro appends its own through the filter.
     *
     * @return array[] Each: key, done, title, description, url, action.
     * @since 1.4.0
     */
    public static function get_setup_steps()
    {
        $ads_txt = Simple_Google_Adsense_Ads_Txt::get_settings();
        $placements = Simple_Google_Adsense_Placements::get_all();
        $active_placements = count(array_filter(wp_list_pluck($placements, 'enabled')));

        $steps = array(
            array(
                'key' => 'publisher_id',
                'done' => Simple_Google_Adsense_Settings::is_publisher_id_valid(),
                'title' => __('Connect your AdSense account', 'simple-google-adsense'),
                'description' => __('Add your Publisher ID so ads can serve.', 'simple-google-adsense'),
                'url' => Simple_Google_Adsense_Admin::settings_url(),
                'action' => __('Add Publisher ID', 'simple-google-adsense'),
            ),
            array(
                'key' => 'ads_txt',
                'done' => !empty($ads_txt['enabled']) || Simple_Google_Adsense_Ads_Txt::physical_file_exists(),
                'title' => __('Authorize your site with ads.txt', 'simple-google-adsense'),
                'description' => __('Prevents AdSense\'s "Earnings at risk" warning.', 'simple-google-adsense'),
                'url' => Simple_Google_Adsense_Admin::settings_url('ads-txt'),
                'action' => __('Set up ads.txt', 'simple-google-adsense'),
            ),
            array(
                'key' => 'ads',
                'done' => Simple_Google_Adsense_Settings::is_auto_ads_enabled() || $active_placements > 0,
                'title' => __('Start showing ads', 'simple-google-adsense'),
                'description' => __('Turn on Auto Ads, or place your own ad units automatically.', 'simple-google-adsense'),
                'url' => admin_url('admin.php?page=' . Simple_Google_Adsense_Placements::PAGE_SLUG),
                'action' => __('Set up placements', 'simple-google-adsense'),
            ),
        );

        /**
         * Filters the Dashboard setup steps.
         *
         * @param array $steps
         * @since 1.4.0
         */
        return apply_filters('adflow_setup_steps', $steps);
    }

    /**
     * Stat cards.
     *
     * @return array[] key => label, value, meta, status, link, link_label, muted
     * @since 1.4.0
     */
    public static function get_cards()
    {
        $auto = Simple_Google_Adsense_Settings::is_auto_ads_enabled();
        $units = Simple_Google_Adsense_Ad_Units::get_all();
        $placements = Simple_Google_Adsense_Placements::get_all();
        $active = count(array_filter(wp_list_pluck($placements, 'enabled')));
        $ads_txt = Simple_Google_Adsense_Ads_Txt::check();

        $cards = array(
            'auto_ads' => array(
                'label' => __('Auto Ads', 'simple-google-adsense'),
                'value' => $auto ? __('On', 'simple-google-adsense') : __('Off', 'simple-google-adsense'),
                'status' => $auto ? 'ok' : '',
                'meta' => $auto ? __('Google places ads across your site.', 'simple-google-adsense') : __('Google\'s automatic ads are not running.', 'simple-google-adsense'),
                'link' => Simple_Google_Adsense_Admin::settings_url(),
                'link_label' => __('Settings', 'simple-google-adsense'),
            ),
            'units' => array(
                'label' => __('Ad units', 'simple-google-adsense'),
                'value' => number_format_i18n(count($units)),
                'meta' => sprintf(
                    /* translators: %s: number of active placements */
                    _n('%s placement active', '%s placements active', $active, 'simple-google-adsense'),
                    number_format_i18n($active)
                ),
                'link' => admin_url('admin.php?page=' . Simple_Google_Adsense_Placements::PAGE_SLUG),
                'link_label' => __('Manage placements', 'simple-google-adsense'),
            ),
            'ads_txt' => array(
                'label' => __('ads.txt', 'simple-google-adsense'),
                'value' => 'ok' === $ads_txt['status'] ? __('Authorized', 'simple-google-adsense') : __('Needs attention', 'simple-google-adsense'),
                'status' => 'ok' === $ads_txt['status'] ? 'ok' : 'warning',
                'meta' => $ads_txt['message'],
                'link' => Simple_Google_Adsense_Admin::settings_url('ads-txt'),
                'link_label' => __('Manage ads.txt', 'simple-google-adsense'),
            ),
        );

        /**
         * Filters the Dashboard cards. Pro adds live earnings.
         *
         * @param array $cards
         * @since 1.4.0
         */
        return apply_filters('adflow_dashboard_cards', $cards);
    }

    /**
     * Render the Dashboard.
     *
     * @since 1.4.0
     */
    public function render()
    {
        $steps = self::get_setup_steps();
        $done = count(array_filter(wp_list_pluck($steps, 'done')));
        $total = count($steps);
        $checks = Simple_Google_Adsense_Admin::get_health_checks();
        $issues = count(array_filter($checks, function ($check) {
            return in_array($check['status'], array('error', 'warning'), true);
        }));
        ?>
        <div class="wrap adflow-page">
            <?php
            Simple_Google_Adsense_Admin::render_header(
                __('Dashboard', 'simple-google-adsense'),
                __('Your AdSense setup at a glance.', 'simple-google-adsense'),
                array(
                    array('label' => __('Add ad unit', 'simple-google-adsense'), 'url' => admin_url('post-new.php?post_type=' . Simple_Google_Adsense_Ad_Units::POST_TYPE), 'primary' => true),
                )
            );
            ?>

            <div class="adflow-stats">
                <?php foreach (self::get_cards() as $card) :
                    $card = wp_parse_args($card, array('label' => '', 'value' => '', 'meta' => '', 'status' => '', 'link' => '', 'link_label' => '', 'muted' => false, 'badge' => ''));
                    ?>
                    <div class="adflow-stat<?php echo $card['muted'] ? ' adflow-stat--muted' : ''; ?>">
                        <div class="adflow-stat__label">
                            <span><?php echo esc_html($card['label']); ?></span>
                            <?php if ('' !== $card['badge']) : ?>
                                <span class="adflow-pro-tag"><?php echo esc_html($card['badge']); ?></span>
                            <?php elseif ('' !== $card['status']) : ?>
                                <span class="adflow-dot adflow-dot--<?php echo esc_attr($card['status']); ?>" aria-hidden="true"></span>
                            <?php endif; ?>
                        </div>
                        <p class="adflow-stat__value"><?php echo esc_html($card['value']); ?></p>
                        <?php if ('' !== $card['meta']) : ?>
                            <p class="adflow-stat__meta"><?php echo esc_html($card['meta']); ?></p>
                        <?php endif; ?>
                        <?php if ('' !== $card['link']) : ?>
                            <a class="adflow-stat__link" href="<?php echo esc_url($card['link']); ?>"><?php echo esc_html($card['link_label']); ?> &rarr;</a>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="adflow-grid-2">
                <div>
                    <?php if ($done < $total) : ?>
                        <section class="adflow-panel">
                            <header class="adflow-panel__head">
                                <div>
                                    <h2><?php echo 0 === $done ? esc_html__('Welcome! Let\'s get your ads running', 'simple-google-adsense') : esc_html__('Finish setting up', 'simple-google-adsense'); ?></h2>
                                    <p><?php esc_html_e('A few quick steps and your site is ready to earn.', 'simple-google-adsense'); ?></p>
                                </div>
                                <div class="adflow-setup__progress">
                                    <?php
                                    /* translators: 1: completed steps, 2: total steps */
                                    printf(esc_html__('%1$d of %2$d', 'simple-google-adsense'), (int) $done, (int) $total);
                                    ?>
                                    <span class="adflow-setup__bar"><span style="width:<?php echo esc_attr(round($done / max(1, $total) * 100)); ?>%"></span></span>
                                </div>
                            </header>
                            <div class="adflow-panel__body">
                                <ol class="adflow-steps">
                                    <?php foreach ($steps as $step) : ?>
                                        <li class="adflow-step<?php echo $step['done'] ? ' is-done' : ''; ?>">
                                            <span class="adflow-step__icon" aria-hidden="true"><?php echo $step['done'] ? '<span class="dashicons dashicons-yes"></span>' : ''; ?></span>
                                            <span class="adflow-step__text">
                                                <strong><?php echo esc_html($step['title']); ?></strong>
                                                <?php if (!$step['done']) : ?>
                                                    <span><?php echo esc_html($step['description']); ?></span>
                                                <?php endif; ?>
                                            </span>
                                            <?php if ($step['done']) : ?>
                                                <span class="screen-reader-text"><?php esc_html_e('Done', 'simple-google-adsense'); ?></span>
                                            <?php else : ?>
                                                <a class="button" href="<?php echo esc_url($step['url']); ?>"><?php echo esc_html($step['action']); ?></a>
                                            <?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ol>
                            </div>
                        </section>
                    <?php endif; ?>

                    <section class="adflow-panel">
                        <header class="adflow-panel__head">
                            <div>
                                <h2><?php esc_html_e('Site health', 'simple-google-adsense'); ?></h2>
                                <p><?php esc_html_e('The most common reasons ads do not show, checked for you.', 'simple-google-adsense'); ?></p>
                            </div>
                            <span class="adflow-pill adflow-pill--<?php echo $issues ? 'warning' : 'ok'; ?>">
                                <?php
                                echo $issues
                                    /* translators: %d: number of issues */
                                    ? esc_html(sprintf(_n('%d issue', '%d issues', $issues, 'simple-google-adsense'), $issues))
                                    : esc_html__('All good', 'simple-google-adsense');
                                ?>
                            </span>
                        </header>
                        <div class="adflow-panel__body">
                            <ul class="adflow-checklist-rows">
                                <?php foreach ($checks as $check) : ?>
                                    <li class="adflow-row">
                                        <span class="adflow-dot adflow-dot--<?php echo esc_attr($check['status']); ?>" aria-hidden="true"></span>
                                        <span class="adflow-row__main">
                                            <strong><?php echo esc_html($check['label']); ?></strong>
                                            <span><?php echo esc_html($check['message']); ?></span>
                                        </span>
                                        <?php if (!empty($check['action'])) : ?>
                                            <a class="button button-small" href="<?php echo esc_url($check['action']); ?>"<?php echo 0 !== strpos($check['action'], admin_url()) ? ' target="_blank" rel="noopener"' : ''; ?>><?php echo esc_html($check['action_label']); ?></a>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </section>
                </div>

                <div>
                    <?php
                    /**
                     * Fires at the top of the Dashboard sidebar (upgrade card in the free version).
                     *
                     * @since 1.4.0
                     */
                    do_action('adflow_dashboard_sidebar');
                    ?>

                    <section class="adflow-panel">
                        <header class="adflow-panel__head"><div><h2><?php esc_html_e('Shortcuts', 'simple-google-adsense'); ?></h2></div></header>
                        <div class="adflow-panel__body">
                            <ul class="adflow-links">
                                <li><a href="<?php echo esc_url(admin_url('post-new.php?post_type=' . Simple_Google_Adsense_Ad_Units::POST_TYPE)); ?>"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span><?php esc_html_e('Create an ad unit', 'simple-google-adsense'); ?></a></li>
                                <li><a href="<?php echo esc_url(admin_url('admin.php?page=' . Simple_Google_Adsense_Placements::PAGE_SLUG)); ?>"><span class="dashicons dashicons-layout" aria-hidden="true"></span><?php esc_html_e('Place ads automatically', 'simple-google-adsense'); ?></a></li>
                                <li><a href="<?php echo esc_url(add_query_arg('adflow-inspect', '1', home_url('/'))); ?>" target="_blank" rel="noopener"><span class="dashicons dashicons-search" aria-hidden="true"></span><?php esc_html_e('Inspect ads on your site', 'simple-google-adsense'); ?></a></li>
                                <li><a href="<?php echo esc_url(Simple_Google_Adsense_Admin::settings_url('ads-txt')); ?>"><span class="dashicons dashicons-media-text" aria-hidden="true"></span><?php esc_html_e('Manage ads.txt', 'simple-google-adsense'); ?></a></li>
                            </ul>
                        </div>
                    </section>

                    <section class="adflow-panel">
                        <header class="adflow-panel__head"><div><h2><?php esc_html_e('Help', 'simple-google-adsense'); ?></h2></div></header>
                        <div class="adflow-panel__body">
                            <ul class="adflow-links">
                                <li><a href="https://support.google.com/adsense/answer/105516" target="_blank" rel="noopener"><span class="dashicons dashicons-external" aria-hidden="true"></span><?php esc_html_e('Find your Publisher ID', 'simple-google-adsense'); ?></a></li>
                                <li><a href="https://support.google.com/adsense/answer/9183566" target="_blank" rel="noopener"><span class="dashicons dashicons-external" aria-hidden="true"></span><?php esc_html_e('Create an ad unit in AdSense', 'simple-google-adsense'); ?></a></li>
                                <li><a href="https://wordpress.org/support/plugin/simple-google-adsense/" target="_blank" rel="noopener"><span class="dashicons dashicons-sos" aria-hidden="true"></span><?php esc_html_e('Get support', 'simple-google-adsense'); ?></a></li>
                            </ul>
                        </div>
                    </section>
                </div>
            </div>
        </div>
        <?php
    }
}
