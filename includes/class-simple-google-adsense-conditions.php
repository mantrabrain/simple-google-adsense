<?php
/**
 * Simple_Google_Adsense basic placement conditions
 *
 * Device, category, tag, specific posts and language rules for any placement.
 * They live in the placement's settings under the same keys AdFlow Pro has
 * always used (device: all|mobile|desktop, categories: term IDs), so a site
 * keeps its rules with or without Pro.
 *
 * Device is decided in the browser, so it works behind full-page caches: the
 * ad request is only made when the rule passes. When Pro is running it does
 * this itself and renders those two fields in its own Targeting panel.
 *
 * @package Simple_Google_Adsense
 * @since   1.6.0
 */

defined('ABSPATH') || exit;

/**
 * Simple_Google_Adsense_Conditions Class.
 *
 * @class Simple_Google_Adsense_Conditions
 */
final class Simple_Google_Adsense_Conditions
{

    /**
     * The single instance of the class.
     *
     * @var Simple_Google_Adsense_Conditions
     */
    protected static $_instance = null;

    /**
     * Main instance.
     *
     * @return Simple_Google_Adsense_Conditions
     * @since 1.6.0
     */
    public static function instance()
    {
        if (is_null(self::$_instance)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    /**
     * Constructor.
     */
    public function __construct()
    {
        // Before Pro's filters (10, 20): Pro has the last word on the keys it owns.
        add_filter('adflow_sanitize_placement', array($this, 'sanitize'), 5, 3);
        add_action('adflow_placement_settings_fields', array($this, 'render_fields'), 5, 3);
        add_filter('adflow_placement_should_display', array($this, 'should_display'), 9, 3);
        add_filter('adflow_placement_ad_id', array($this, 'translated_unit'), 20, 3);
        add_filter('adflow_ad_markup', array($this, 'hold_ad_code'), 10, 3);
        add_filter('pll_get_post_types', array($this, 'offer_units_to_polylang'), 10, 2);
        add_filter('adflow_ad_wrapper_attributes', array($this, 'defer_wrapper'), 20, 3);
        add_filter('adflow_ad_inline_push', array($this, 'defer_push'), 10, 3);
    }

    /**
     * Whether AdFlow Pro's own device and category code is running.
     *
     * @return bool
     * @since 1.6.0
     */
    public static function pro_handles_targeting()
    {
        return class_exists('AdFlow_Pro_Conditions') && class_exists('AdFlow_Pro_Frontend');
    }

    /**
     * Rules of a placement, merged over the defaults.
     *
     * @param array $placement Settings.
     * @return array
     * @since 1.6.0
     */
    public static function get_rules($placement)
    {
        $placement = is_array($placement) ? $placement : array();

        return array(
            'device' => isset($placement['device']) && in_array($placement['device'], array('mobile', 'desktop'), true) ? $placement['device'] : 'all',
            'categories' => array_map('intval', isset($placement['categories']) ? (array) $placement['categories'] : array()),
            'tags' => array_map('intval', isset($placement['tags']) ? (array) $placement['tags'] : array()),
            'post_ids' => array_map('intval', isset($placement['post_ids']) ? (array) $placement['post_ids'] : array()),
            'exclude_post_ids' => array_map('intval', isset($placement['exclude_post_ids']) ? (array) $placement['exclude_post_ids'] : array()),
            'languages' => isset($placement['languages']) ? array_map('strval', (array) $placement['languages']) : array(),
        );
    }

    /**
     * Sanitize the rules.
     *
     * @param array $clean Sanitized placement.
     * @param array $raw Raw input.
     * @param string $key Placement key.
     * @return array
     * @since 1.6.0
     */
    public function sanitize($clean, $raw, $key)
    {
        $ids = function ($name) use ($raw) {
            return isset($raw[$name]) ? array_values(array_unique(array_filter(array_map('absint', (array) $raw[$name])))) : array();
        };

        $clean['device'] = isset($raw['device']) && in_array($raw['device'], array('mobile', 'desktop'), true) ? $raw['device'] : 'all';
        $clean['categories'] = $ids('categories');
        $clean['tags'] = $ids('tags');
        $clean['post_ids'] = $ids('post_ids');
        $clean['exclude_post_ids'] = $ids('exclude_post_ids');

        // Without a multilingual plugin the field is not shown: keep what is stored.
        $languages = self::languages();

        if ($languages) {
            $picked = isset($raw['languages']) ? array_map('sanitize_key', (array) $raw['languages']) : array();
            $clean['languages'] = array_values(array_intersect($picked, array_map('sanitize_key', array_keys($languages))));
        }

        return $clean;
    }

    /**
     * Whether rules for posts and terms apply to a placement's group.
     *
     * @param string $key Placement key.
     * @return bool
     * @since 1.6.0
     */
    private static function is_woocommerce($key)
    {
        $types = Simple_Google_Adsense_Placements::get_types();

        return isset($types[$key]['group']) && 'woocommerce' === $types[$key]['group'];
    }

    /**
     * Apply the server-side rules.
     *
     * @param bool $display Current decision.
     * @param string $key Placement key.
     * @param array $placement Settings.
     * @return bool
     * @since 1.6.0
     */
    public function should_display($display, $key, $placement)
    {
        if (!$display) {
            return false;
        }

        $rules = self::get_rules($placement);

        if ($rules['languages'] && self::languages() && !in_array(self::current_language(), $rules['languages'], true)) {
            return false;
        }

        // WooCommerce pages have no post categories or tags: the rules do not apply there.
        if (self::is_woocommerce($key)) {
            return true;
        }

        $singular = is_singular();
        $post_id = $singular ? (int) get_queried_object_id() : 0;

        if ($rules['post_ids'] && !in_array($post_id, $rules['post_ids'], true)) {
            return false;
        }

        if ($rules['exclude_post_ids'] && in_array($post_id, $rules['exclude_post_ids'], true)) {
            return false;
        }

        // Pro checks categories the same way when it runs.
        if ($rules['categories'] && !self::pro_handles_targeting()) {
            if ($singular ? !has_term($rules['categories'], 'category', $post_id) : !is_category($rules['categories'])) {
                return false;
            }
        }

        if ($rules['tags'] && ($singular ? !has_term($rules['tags'], 'post_tag', $post_id) : !is_tag($rules['tags']))) {
            return false;
        }

        return true;
    }

    /**
     * Languages of the active multilingual plugin.
     *
     * @return array code => name. Empty when no such plugin is active.
     * @since 1.6.0
     */
    public static function languages()
    {
        $languages = array();

        if (function_exists('pll_languages_list')) {
            $codes = pll_languages_list(array('fields' => 'slug'));
            $names = pll_languages_list(array('fields' => 'name'));

            foreach ((array) $codes as $i => $code) {
                $languages[$code] = isset($names[$i]) ? $names[$i] : $code;
            }
        } elseif (defined('ICL_SITEPRESS_VERSION')) {
            $active = apply_filters('wpml_active_languages', null, array('skip_missing' => 0));

            foreach ((array) $active as $code => $language) {
                $languages[$code] = isset($language['translated_name']) ? $language['translated_name'] : (isset($language['native_name']) ? $language['native_name'] : $code);
            }
        }

        /**
         * Filters the languages a placement can be limited to.
         *
         * @param array $languages code => name.
         * @since 1.6.0
         */
        return (array) apply_filters('adflow_languages', $languages);
    }

    /**
     * Language of the current request.
     *
     * @return string
     * @since 1.6.0
     */
    public static function current_language()
    {
        $language = '';

        if (function_exists('pll_current_language')) {
            $language = (string) pll_current_language('slug');
        } elseif (defined('ICL_SITEPRESS_VERSION')) {
            $language = (string) apply_filters('wpml_current_language', null);
        }

        /**
         * Filters the current language for placement rules.
         *
         * @param string $language
         * @since 1.6.0
         */
        return (string) apply_filters('adflow_current_language', $language);
    }

    /**
     * Show the translation of an ad unit, when the owner translates ad units
     * and this language has one.
     *
     * @param int $ad_id Unit.
     * @param string $key Placement key.
     * @param array $placement Settings.
     * @return int
     * @since 1.6.0
     */
    public function translated_unit($ad_id, $key, $placement)
    {
        if ($ad_id && function_exists('pll_get_post') && function_exists('pll_is_translated_post_type') && pll_is_translated_post_type(Simple_Google_Adsense_Ad_Units::POST_TYPE)) {
            $translated = (int) pll_get_post($ad_id);

            return $translated ? $translated : $ad_id;
        }

        if ($ad_id && defined('ICL_SITEPRESS_VERSION')) {
            return (int) apply_filters('wpml_object_id', $ad_id, Simple_Google_Adsense_Ad_Units::POST_TYPE, true);
        }

        return $ad_id;
    }

    /**
     * List Ad Units under Polylang's post type settings (they are not public, so
     * it would not offer them). The owner decides: nothing is translated by default.
     *
     * @param string[] $types Post types.
     * @param bool $is_settings Whether this is the list for the settings screen.
     * @return string[]
     * @since 1.6.0
     */
    public function offer_units_to_polylang($types, $is_settings = false)
    {
        if ($is_settings) {
            $types[Simple_Google_Adsense_Ad_Units::POST_TYPE] = Simple_Google_Adsense_Ad_Units::POST_TYPE;
        }

        return $types;
    }

    /**
     * Number of rules set.
     *
     * @param array $rules Rules.
     * @param bool $pro Pro renders device and categories.
     * @return int
     * @since 1.6.0
     */
    private static function count_rules($rules, $pro)
    {
        $count = 0;

        foreach (array('tags', 'post_ids', 'exclude_post_ids', 'languages') as $key) {
            $count += $rules[$key] ? 1 : 0;
        }

        if (!$pro) {
            $count += ('all' !== $rules['device'] ? 1 : 0) + ($rules['categories'] ? 1 : 0);
        }

        return $count;
    }

    /**
     * Render the rules inside a placement card.
     *
     * @param string $key Placement key.
     * @param array $placement Settings.
     * @param string $name Input name prefix.
     * @since 1.6.0
     */
    public function render_fields($key, $placement, $name)
    {
        $pro = self::pro_handles_targeting();
        $rules = self::get_rules($placement);
        $languages = self::languages();
        $woo = self::is_woocommerce($key);

        // WooCommerce placements have only the language rule, and only with such a plugin.
        if ($woo && !$languages) {
            return;
        }

        $count = self::count_rules($rules, $pro);
        ?>
        <details class="adflow-targeting adflow-conditions" <?php echo $count ? 'open' : ''; ?>>
            <summary>
                <?php esc_html_e('Conditions', 'simple-google-adsense'); ?>
                <?php if ($count) : ?>
                    <span class="adflow-pill adflow-pill--info">
                        <?php
                        /* translators: %d: number of active conditions */
                        echo esc_html(sprintf(_n('%d condition', '%d conditions', $count, 'simple-google-adsense'), $count));
                        ?>
                    </span>
                <?php endif; ?>
            </summary>
            <div class="adflow-targeting__body">
                <p class="description"><?php esc_html_e('Leave empty to show everywhere. An ad must match every condition you set.', 'simple-google-adsense'); ?></p>
                <div class="adflow-conditions__grid">
                    <?php if (!$pro) : ?>
                        <div class="adflow-targeting__grid">
                            <label><?php esc_html_e('Device', 'simple-google-adsense'); ?>
                                <select name="<?php echo esc_attr($name); ?>[device]">
                                    <option value="all" <?php selected($rules['device'], 'all'); ?>><?php esc_html_e('All devices', 'simple-google-adsense'); ?></option>
                                    <option value="mobile" <?php selected($rules['device'], 'mobile'); ?>><?php esc_html_e('Mobile only', 'simple-google-adsense'); ?></option>
                                    <option value="desktop" <?php selected($rules['device'], 'desktop'); ?>><?php esc_html_e('Tablet & desktop only', 'simple-google-adsense'); ?></option>
                                </select>
                            </label>
                        </div>
                    <?php endif; ?>

                    <?php if (!$woo) : ?>
                        <?php if (!$pro) : ?>
                            <?php self::picker($key, $name, 'categories', 'category', __('Only in these categories', 'simple-google-adsense'), $rules); ?>
                        <?php endif; ?>
                        <?php self::picker($key, $name, 'tags', 'post_tag', __('Only with these tags', 'simple-google-adsense'), $rules); ?>
                        <?php self::picker($key, $name, 'post_ids', 'post', __('Only on these posts or pages', 'simple-google-adsense'), $rules); ?>
                        <?php self::picker($key, $name, 'exclude_post_ids', 'post', __('Never on these posts or pages', 'simple-google-adsense'), $rules); ?>
                    <?php endif; ?>
                </div>

                <?php if ($languages) : ?>
                    <fieldset class="adflow-conditions__languages">
                        <legend class="adflow-sublabel"><?php esc_html_e('Only in these languages', 'simple-google-adsense'); ?></legend>
                        <div class="adflow-checks">
                            <?php foreach ($languages as $code => $language_name) : ?>
                                <label>
                                    <input type="checkbox" name="<?php echo esc_attr($name); ?>[languages][]" value="<?php echo esc_attr($code); ?>" <?php checked(in_array((string) $code, $rules['languages'], true)); ?>>
                                    <?php echo esc_html($language_name); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>
                <?php endif; ?>
            </div>
        </details>
        <?php
    }

    /**
     * One condition picker.
     *
     * @param string $key Placement key.
     * @param string $name Input prefix.
     * @param string $field Setting.
     * @param string $source Picker source.
     * @param string $label Label.
     * @param array $rules Rules.
     * @since 1.6.0
     */
    private static function picker($key, $name, $field, $source, $label, $rules)
    {
        Simple_Google_Adsense_Picker::render(array(
            'source' => $source,
            'label' => $label,
            'placeholder' => __('Type to search…', 'simple-google-adsense'),
            'ids' => $rules[$field],
            'name' => $name . '[' . $field . '][]',
            'uid' => 'adflow-pick-' . $key . '-' . $field,
        ));
    }

    /**
     * Whether free must gate this ad in the browser.
     *
     * @param array $context Render context.
     * @return string The device rule, or ''.
     * @since 1.6.0
     */
    private static function device_rule($context)
    {
        if (empty($context['placement']) || self::pro_handles_targeting()) {
            return '';
        }

        $rules = self::get_rules(Simple_Google_Adsense_Placements::get($context['placement']));

        return 'all' !== $rules['device'] ? $rules['device'] : '';
    }

    /**
     * Hold a gated AdSense unit's code in a `<template>` (the same markup Pro
     * prints): Google's library cannot see it until the browser inserts it.
     *
     * @param string $output Markup.
     * @param array $unit Unit.
     * @param array $context Context.
     * @return string
     * @since 1.6.0
     */
    public function hold_ad_code($output, $unit, $context)
    {
        if ('' === self::device_rule($context) || !in_array($unit['type'], Simple_Google_Adsense_Ad_Units::adsense_types(), true)) {
            return $output;
        }

        return (string) preg_replace('#<ins\b[^>]*></ins>#', '<template class="adflow-held">$0</template>', $output, 1);
    }

    /**
     * Carry the device rule on the wrapper (the same markup Pro prints).
     *
     * @param array $wrapper Attributes.
     * @param array $unit Unit.
     * @param array $context Context.
     * @return array
     * @since 1.6.0
     */
    public function defer_wrapper($wrapper, $unit, $context)
    {
        $device = self::device_rule($context);

        if ('' === $device) {
            return $wrapper;
        }

        $wrapper['data-adflow-defer'] = '1';
        $wrapper['data-adflow-device'] = $device;
        // Hide immediately with CSS so reserved space never flashes on the wrong device.
        $wrapper['class'] = (isset($wrapper['class']) ? $wrapper['class'] : '') . ' adflow-only-' . $device;

        Simple_Google_Adsense_Frontend::enqueue_own_ad_assets();

        return $wrapper;
    }

    /**
     * No inline push for gated ads.
     *
     * @param bool $push Push.
     * @param array $unit Unit.
     * @param array $context Context.
     * @return bool
     * @since 1.6.0
     */
    public function defer_push($push, $unit, $context)
    {
        return '' !== self::device_rule($context) ? false : $push;
    }
}
