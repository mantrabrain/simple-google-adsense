<?php
/**
 * Simple_Google_Adsense settings helper
 *
 * Central place for reading plugin options so that defaults, and the
 * normalisation of the Publisher ID, stay consistent between the admin
 * screen, the frontend Auto Ads snippet and the Manual Ads output.
 *
 * @package Simple_Google_Adsense
 * @since   1.3.0
 */

defined('ABSPATH') || exit;

/**
 * Simple_Google_Adsense_Settings Class.
 *
 * @class Simple_Google_Adsense_Settings
 */
final class Simple_Google_Adsense_Settings
{

    /**
     * Option name used to store the plugin settings.
     */
    const OPTION_NAME = 'simple_google_adsense_settings';

    /**
     * Default values for every stored setting.
     *
     * @return array
     * @since 1.3.0
     */
    public static function defaults()
    {
        return array(
            'publisher_id' => '',
            'enable_auto_ads' => true,
            'enable_manual_ads' => true,
            // 1.4.0 - all off by default so upgraded sites render exactly as before.
            'hide_for_admins' => false,
            'auto_ads_exclude_post_types' => array(),
            'auto_ads_exclude_ids' => '',
            // 1.5.0 - empty, so manual ads show everywhere as before.
            'manual_ads_exclude_post_types' => array(),
            'manual_ads_exclude_ids' => '',
            'ad_label' => '',
            'delete_data' => false,
        );
    }

    /**
     * Get all settings merged over the defaults.
     *
     * @return array
     * @since 1.3.0
     */
    public static function get_all()
    {
        $options = get_option(self::OPTION_NAME);

        if (!is_array($options)) {
            $options = array();
        }

        return wp_parse_args($options, self::defaults());
    }

    /**
     * Get a single setting.
     *
     * @param string $key Setting key.
     * @return mixed
     * @since 1.3.0
     */
    public static function get($key)
    {
        $options = self::get_all();

        return isset($options[$key]) ? $options[$key] : null;
    }

    /**
     * Get the stored Publisher ID, normalised to the `pub-XXXXXXXXXXXXXXXX` form.
     *
     * Users routinely paste the ID as `ca-pub-1234...` (the ad client) rather
     * than `pub-1234...`. Stripping the prefix here keeps the generated ad
     * client from ending up as `ca-ca-pub-1234...`, which never serves an ad.
     *
     * @return string Empty string when no Publisher ID has been configured.
     * @since 1.3.0
     */
    public static function get_publisher_id()
    {
        $publisher_id = trim((string) self::get('publisher_id'));

        if ('' === $publisher_id) {
            return '';
        }

        // Accept `ca-pub-XXXX`, `pub-XXXX` and a bare numeric ID.
        $publisher_id = preg_replace('/^ca-/i', '', $publisher_id);

        if (!preg_match('/^pub-/i', $publisher_id)) {
            $publisher_id = 'pub-' . $publisher_id;
        }

        return $publisher_id;
    }

    /**
     * Get the AdSense ad client (`ca-pub-XXXXXXXXXXXXXXXX`).
     *
     * @return string Empty string when no Publisher ID has been configured.
     * @since 1.3.0
     */
    public static function get_ad_client()
    {
        // No ad code is ever sent with a malformed ID: Google would reject every request.
        return self::is_publisher_id_valid() ? 'ca-' . self::get_publisher_id() : '';
    }

    /**
     * Whether Auto Ads should be printed on the frontend.
     *
     * @return bool
     * @since 1.3.0
     */
    public static function is_auto_ads_enabled()
    {
        return (bool) self::get('enable_auto_ads') && '' !== self::get_publisher_id();
    }

    /**
     * Whether Manual Ads (shortcodes and the block) should render ad markup.
     *
     * @return bool
     * @since 1.3.0
     */
    public static function is_manual_ads_enabled()
    {
        return (bool) self::get('enable_manual_ads');
    }

    /**
     * Whether ads may be shown on the current request at all.
     *
     * Applies to Auto Ads, Manual Ads and automatic placements alike. Pro
     * modules (per-post controls, click protection) hook the filter.
     *
     * @return bool
     * @since 1.4.0
     */
    public static function ads_allowed()
    {
        $allowed = true;

        if (self::get('hide_for_admins') && is_user_logged_in() && current_user_can('manage_options')) {
            $allowed = false;
        }

        /**
         * Filters whether any AdFlow ad may be output on the current request.
         *
         * @param bool $allowed
         * @since 1.4.0
         */
        return (bool) apply_filters('adflow_ads_allowed', $allowed);
    }

    /**
     * Whether the Auto Ads snippet may be printed on the current request.
     *
     * @return bool
     * @since 1.4.0
     */
    public static function auto_ads_allowed()
    {
        $allowed = self::is_auto_ads_enabled() && self::ads_allowed();

        if ($allowed && self::request_matches((array) self::get('auto_ads_exclude_post_types'), self::get_excluded_ids())) {
            $allowed = false;
        }

        /**
         * Filters whether the Auto Ads snippet is printed on the current request.
         *
         * @param bool $allowed
         * @since 1.4.0
         */
        return (bool) apply_filters('adflow_auto_ads_allowed', $allowed);
    }

    /**
     * Whether manual ads are switched off for the current request.
     *
     * Manual ads are every ad unit AdFlow outputs itself: automatic
     * placements, the block, the widget and the shortcodes. Like the Auto Ads
     * exclusions, this looks at the page being viewed, so an ad in a sidebar
     * widget is hidden on an excluded page too.
     *
     * @return bool
     * @since 1.5.0
     */
    public static function is_manual_ads_excluded()
    {
        $excluded = self::request_matches((array) self::get('manual_ads_exclude_post_types'), self::get_manual_excluded_ids());

        /**
         * Filters whether manual ads are switched off on the current request.
         * AdFlow Pro uses it for the "No manual ads on this page" checkbox.
         *
         * @param bool $excluded
         * @since 1.5.0
         */
        return (bool) apply_filters('adflow_manual_ads_excluded', $excluded);
    }

    /**
     * Whether manual ads may be output on the current request.
     *
     * @return bool
     * @since 1.5.0
     */
    public static function manual_ads_allowed()
    {
        return self::is_manual_ads_enabled() && self::ads_allowed() && !self::is_manual_ads_excluded();
    }

    /**
     * Post IDs on which manual ads are switched off.
     *
     * @return int[]
     * @since 1.5.0
     */
    public static function get_manual_excluded_ids()
    {
        return array_values(array_filter(wp_parse_id_list((string) self::get('manual_ads_exclude_ids'))));
    }

    /**
     * Whether the page being viewed is one of the given post types or post IDs.
     *
     * Post types match single posts of that type. IDs match single posts and
     * pages, and also the page set as the "Posts page" under Settings → Reading.
     * Runs only once the main query is known (after the `wp` action); before
     * that nothing matches.
     *
     * @param string[] $post_types Post type names.
     * @param int[] $ids Post IDs.
     * @return bool
     * @since 1.5.0
     */
    public static function request_matches($post_types, $ids)
    {
        if ((!$post_types && !$ids) || !did_action('wp') || is_admin()) {
            return false;
        }

        $object = get_queried_object();

        if (!$object instanceof WP_Post) {
            return false;
        }

        if (is_singular() && in_array($object->post_type, (array) $post_types, true)) {
            return true;
        }

        return in_array((int) $object->ID, array_map('intval', (array) $ids), true);
    }

    /**
     * Post IDs on which Auto Ads are switched off.
     *
     * @return int[]
     * @since 1.4.0
     */
    public static function get_excluded_ids()
    {
        $ids = wp_parse_id_list((string) self::get('auto_ads_exclude_ids'));

        return array_values(array_filter($ids));
    }

    /**
     * Whether the Publisher ID looks like a valid AdSense ID.
     *
     * @return bool
     * @since 1.4.0
     */
    public static function is_publisher_id_valid()
    {
        return (bool) preg_match('/^pub-\d{10,20}$/', self::get_publisher_id());
    }

    /**
     * Label text shown above manual ads, or '' for none.
     *
     * Google only allows "Advertisements" or "Sponsored Links" as labels.
     *
     * @return string
     * @since 1.4.0
     */
    public static function get_ad_label()
    {
        $labels = array(
            'advertisements' => __('Advertisements', 'simple-google-adsense'),
            'sponsored' => __('Sponsored Links', 'simple-google-adsense'),
        );
        $key = (string) self::get('ad_label');

        return (string) apply_filters('adflow_ad_label', isset($labels[$key]) ? $labels[$key] : '', $key);
    }

    /**
     * Whether AdFlow Pro is active.
     *
     * @return bool
     * @since 1.4.0
     */
    public static function is_pro_active()
    {
        return defined('ADFLOW_PRO_VERSION');
    }
}
