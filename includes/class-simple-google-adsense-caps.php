<?php
/**
 * Simple_Google_Adsense capabilities
 *
 * Lets site owners give editors, ad managers or a sales team access to
 * AdFlow without making them administrators:
 * - adflow_access       open the AdFlow menu and Dashboard;
 * - adflow_manage_ads   create/edit ad units and placements;
 * - adflow_view_reports see Reports (and Pro's Earnings).
 * Settings, ads.txt and licensing stay with manage_options.
 *
 * @package Simple_Google_Adsense
 * @since   1.4.0
 */

defined('ABSPATH') || exit;

/**
 * Simple_Google_Adsense_Caps Class.
 *
 * @class Simple_Google_Adsense_Caps
 */
final class Simple_Google_Adsense_Caps
{

    const ACCESS = 'adflow_access';
    const MANAGE_ADS = 'adflow_manage_ads';
    const VIEW_REPORTS = 'adflow_view_reports';
    const PUBLISH_ADS = 'adflow_publish_ads';

    /**
     * Version of the default grants (bump to re-apply to administrators).
     */
    const VERSION = '2';

    /**
     * The single instance of the class.
     *
     * @var Simple_Google_Adsense_Caps
     * @since 1.4.0
     */
    protected static $_instance = null;

    /**
     * Main Simple_Google_Adsense_Caps Instance.
     *
     * @return Simple_Google_Adsense_Caps
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
     * Simple_Google_Adsense_Caps Constructor.
     */
    public function __construct()
    {
        add_action('init', array(__CLASS__, 'ensure_admin_caps'), 1);
        add_filter('user_has_cap', array($this, 'administrators_have_all'), 10, 3);
        add_filter('option_page_capability_adflow_placements', array($this, 'manage_ads_cap'));
        add_filter('adflow_settings_tabs', array($this, 'register_tab'), 85);
        add_action('admin_post_adflow_save_access', array($this, 'save'));
        add_action('transition_post_status', array($this, 'notify_review'), 10, 3);
        add_filter('adflow_health_checks', array($this, 'pending_check'));
    }

    /**
     * Email reviewers when an ad is submitted for review.
     *
     * @param string $new New status.
     * @param string $old Old status.
     * @param WP_Post $post Post.
     * @since 1.4.0
     */
    public function notify_review($new, $old, $post)
    {
        if ('pending' !== $new || 'pending' === $old || Simple_Google_Adsense_Ad_Units::POST_TYPE !== $post->post_type) {
            return;
        }

        $author = get_userdata((int) $post->post_author);
        $site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $emails = array();

        foreach (get_users(array('capability' => self::PUBLISH_ADS, 'fields' => array('user_email'), 'number' => 20)) as $user) {
            $emails[] = $user->user_email;
        }

        /**
         * Filters who is told about ads waiting for review.
         *
         * @param string[] $emails
         * @param WP_Post $post
         * @since 1.4.0
         */
        $emails = array_unique(array_filter((array) apply_filters('adflow_review_recipients', $emails, $post)));

        if (!$emails) {
            return;
        }

        wp_mail(
            $emails,
            /* translators: 1: site, 2: ad name */
            sprintf(__('[%1$s] Ad waiting for review: %2$s', 'simple-google-adsense'), $site, $post->post_title),
            sprintf(
                /* translators: 1: author, 2: ad name, 3: link */
                __('%1$s submitted the ad "%2$s" for review. Review and publish it here: %3$s', 'simple-google-adsense'),
                $author ? $author->display_name : __('Someone', 'simple-google-adsense'),
                $post->post_title,
                admin_url('post.php?post=' . $post->ID . '&action=edit')
            )
        );
    }

    /**
     * Dashboard note for ads waiting for review.
     *
     * @param array $checks Checks.
     * @return array
     * @since 1.4.0
     */
    public function pending_check($checks)
    {
        $count = (int) wp_count_posts(Simple_Google_Adsense_Ad_Units::POST_TYPE)->pending;

        if ($count && current_user_can(self::PUBLISH_ADS)) {
            $checks['pending'] = array(
                'status' => 'warning',
                'label' => __('Ads waiting for review', 'simple-google-adsense'),
                /* translators: %d: number */
                'message' => sprintf(_n('%d ad was submitted and is not live yet.', '%d ads were submitted and are not live yet.', $count, 'simple-google-adsense'), $count),
                'action' => admin_url('edit.php?post_status=pending&post_type=' . Simple_Google_Adsense_Ad_Units::POST_TYPE),
                'action_label' => __('Review', 'simple-google-adsense'),
            );
        }

        return $checks;
    }

    /**
     * Capability labels.
     *
     * @return array
     * @since 1.4.0
     */
    public static function labels()
    {
        return array(
            self::MANAGE_ADS => __('Create & edit ads', 'simple-google-adsense'),
            self::PUBLISH_ADS => __('Publish ads (no review)', 'simple-google-adsense'),
            self::VIEW_REPORTS => __('View reports', 'simple-google-adsense'),
        );
    }

    /**
     * Give administrators every AdFlow capability (once per version).
     *
     * @since 1.4.0
     */
    public static function ensure_admin_caps()
    {
        if (self::VERSION === get_option('adflow_caps_version')) {
            return;
        }

        $role = get_role('administrator');

        if ($role) {
            foreach (array(self::ACCESS, self::MANAGE_ADS, self::PUBLISH_ADS, self::VIEW_REPORTS) as $cap) {
                $role->add_cap($cap);
            }
        }

        update_option('adflow_caps_version', self::VERSION, true);
    }

    /**
     * Anyone who can manage options can do everything in AdFlow (covers
     * multisite super admins and custom admin roles).
     *
     * @param array $allcaps Caps.
     * @param array $caps Required caps.
     * @param array $args Args.
     * @return array
     * @since 1.4.0
     */
    public function administrators_have_all($allcaps, $caps, $args)
    {
        if (!empty($allcaps['manage_options'])) {
            $allcaps[self::ACCESS] = true;
            $allcaps[self::MANAGE_ADS] = true;
            $allcaps[self::VIEW_REPORTS] = true;
            $allcaps[self::PUBLISH_ADS] = true;
        }

        return $allcaps;
    }

    /**
     * Placements are saved through options.php, which needs a capability filter.
     *
     * @return string
     * @since 1.4.0
     */
    public function manage_ads_cap()
    {
        return self::MANAGE_ADS;
    }

    /**
     * Add the Access tab to Settings.
     *
     * @param array $tabs Tabs.
     * @return array
     * @since 1.4.0
     */
    public function register_tab($tabs)
    {
        $tabs['access'] = array(
            'label' => __('Access', 'simple-google-adsense'),
            'callback' => array($this, 'render_tab'),
            'priority' => 85,
        );

        return $tabs;
    }

    /**
     * Render the Access tab.
     *
     * @since 1.4.0
     */
    public function render_tab()
    {
        $roles = wp_roles()->roles;
        unset($roles['administrator']);
        ?>
        <?php if (isset($_GET['access-saved'])) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Access saved.', 'simple-google-adsense'); ?></p></div>
        <?php endif; ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="adflow_save_access">
            <?php wp_nonce_field('adflow_save_access'); ?>
            <section class="adflow-panel">
                <header class="adflow-panel__head">
                    <div>
                        <h2><?php esc_html_e('Who can use AdFlow', 'simple-google-adsense'); ?></h2>
                        <p><?php esc_html_e('Give editors, an ad manager or your sales team access without making them administrators. Administrators always have full access; settings stay with administrators.', 'simple-google-adsense'); ?></p>
                    </div>
                </header>
                <table class="widefat striped adflow-table">
                    <thead>
                        <tr>
                            <th scope="col"><?php esc_html_e('Role', 'simple-google-adsense'); ?></th>
                            <?php foreach (self::labels() as $label) : ?>
                                <th scope="col"><?php echo esc_html($label); ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong><?php esc_html_e('Administrator', 'simple-google-adsense'); ?></strong></td>
                            <?php foreach (self::labels() as $label) : ?>
                                <td><span class="dashicons dashicons-yes" aria-label="<?php esc_attr_e('Always', 'simple-google-adsense'); ?>"></span></td>
                            <?php endforeach; ?>
                        </tr>
                        <?php foreach ($roles as $slug => $role) : ?>
                            <tr>
                                <td><?php echo esc_html(translate_user_role($role['name'])); ?></td>
                                <?php foreach (self::labels() as $cap => $label) : ?>
                                    <td>
                                        <label class="screen-reader-text" for="adflow-cap-<?php echo esc_attr($slug . '-' . $cap); ?>"><?php echo esc_html(translate_user_role($role['name']) . ': ' . $label); ?></label>
                                        <input type="checkbox" id="adflow-cap-<?php echo esc_attr($slug . '-' . $cap); ?>" name="caps[<?php echo esc_attr($slug); ?>][<?php echo esc_attr($cap); ?>]" value="1" <?php checked(!empty($role['capabilities'][$cap])); ?>>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <footer class="adflow-panel__foot">
                    <?php submit_button(__('Save access', 'simple-google-adsense'), 'primary', 'submit', false); ?>
                </footer>
            </section>
        </form>
        <?php
    }

    /**
     * Save role grants.
     *
     * @since 1.4.0
     */
    public function save()
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to do this.', 'simple-google-adsense'));
        }

        check_admin_referer('adflow_save_access');

        $input = isset($_POST['caps']) && is_array($_POST['caps']) ? wp_unslash($_POST['caps']) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- keys validated below.

        foreach (wp_roles()->roles as $slug => $data) {
            if ('administrator' === $slug) {
                continue;
            }

            $role = get_role($slug);
            $any = false;

            foreach (array_keys(self::labels()) as $cap) {
                if (!empty($input[$slug][$cap])) {
                    $role->add_cap($cap);
                    $any = true;
                } else {
                    $role->remove_cap($cap);
                }
            }

            // Menu access follows the other grants.
            if ($any) {
                $role->add_cap(self::ACCESS);
            } else {
                $role->remove_cap(self::ACCESS);
            }
        }

        /**
         * Fires after AdFlow access was changed (Pro: audit log).
         *
         * @since 1.4.0
         */
        do_action('adflow_access_saved');

        wp_safe_redirect(add_query_arg('access-saved', '1', Simple_Google_Adsense_Admin::settings_url('access')));
        exit;
    }
}
