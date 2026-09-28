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

        if ($allowed && is_singular()) {
            $excluded_types = (array) self::get('auto_ads_exclude_post_types');

            if (in_array(get_post_type(), $excluded_types, true)) {
                $allowed = false;
            }

            if ($allowed && in_array((int) get_queried_object_id(), self::get_excluded_ids(), true)) {
                $allowed = false;
            }
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
