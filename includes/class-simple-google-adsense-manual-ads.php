<?php
/**
 * Simple_Google_Adsense Manual Ads setup
 *
 * @package Simple_Google_Adsense
 * @since   1.2.0
 */

defined('ABSPATH') || exit;

/**
 * Main Simple_Google_Adsense_Manual_Ads Class.
 *
 * @class Simple_Google_Adsense_Manual_Ads
 */
final class Simple_Google_Adsense_Manual_Ads
{

    /**
     * The single instance of the class.
     *
     * @var Simple_Google_Adsense_Manual_Ads
     * @since 1.2.0
     */
    protected static $_instance = null;

    /**
     * Main Simple_Google_Adsense_Manual_Ads Instance.
     *
     * Ensures only one instance of Simple_Google_Adsense_Manual_Ads is loaded or can be loaded.
     *
     * @return Simple_Google_Adsense_Manual_Ads - Main instance.
     * @since 1.2.0
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
     * Simple_Google_Adsense_Manual_Ads Constructor.
     */
    public function __construct()
    {
        // Held-back ad code sits in <template>: block themes run wptexturize over
        // the whole page, which must not turn its quotes into typographic ones.
        add_filter('no_texturize_tags', array($this, 'no_texturize_templates'));
        $this->init_hooks();
    }

    /**
     * Hook into actions and filters.
     *
     * @since 1.2.0
     */
    private function init_hooks()
    {
        // Register shortcodes
        add_shortcode('adsense', array($this, 'adsense_shortcode'));
        add_shortcode('adsense_banner', array($this, 'banner_ad_shortcode'));
        add_shortcode('adsense_inarticle', array($this, 'inarticle_ad_shortcode'));
        add_shortcode('adsense_infeed', array($this, 'infeed_ad_shortcode'));
        add_shortcode('adsense_matched_content', array($this, 'matched_content_shortcode'));
        add_shortcode('adsense_multiplex', array($this, 'matched_content_shortcode'));
        add_shortcode('adflow', array($this, 'adflow_shortcode'));

        // Add Gutenberg block
        add_action('init', array($this, 'register_blocks'));
    }

    /**
     * Main AdSense shortcode
     *
     * `id` (1.4.0) renders a saved Ad Unit; every other attribute keeps its
     * pre-1.4.0 meaning so existing content renders unchanged.
     *
     * @param array $atts Shortcode attributes
     * @return string
     */
    public function adsense_shortcode($atts)
    {
        // Set by the type-specific shortcodes ([adsense_inarticle] etc.).
        $typed = is_array($atts) && !empty($atts['_adflow_typed']);

        $atts = shortcode_atts(array(
            'id' => '',
            'type' => 'banner',
            'ad_slot' => '',
            'ad_client' => '',
            'ad_format' => 'auto',
            'layout_key' => '',
            'full_width_responsive' => 'true',
            'style' => '',
            'class' => ''
        ), $atts, 'adsense');

        if (!empty($atts['id'])) {
            return $this->render_saved_unit($atts['id'], array('source' => 'shortcode'), $atts);
        }

        /*
         * Backward compatibility: before 1.4.0 the generic [adsense type="…"]
         * ignored `type` and always printed a display unit with the given
         * ad_format. Keep that exact output; type-specific markup is used by
         * the dedicated shortcodes, saved Ad Units, or when the new
         * `layout_key` attribute is given (in-feed).
         */
        if ($typed) {
            $type = Simple_Google_Adsense_Ad_Units::normalize_type($atts['type']);
        } elseif ('' !== trim((string) $atts['layout_key'])) {
            $type = 'infeed';
        } else {
            $type = 'display';
        }

        $unit = array(
            'id' => 0,
            'slot' => $atts['ad_slot'],
            'type' => $type,
            'format' => $atts['ad_format'],
            'layout_key' => $atts['layout_key'],
            // Printed verbatim, as before 1.4.0.
            'full_width_responsive' => (string) $atts['full_width_responsive'],
            'ad_client' => self::allowed_ad_client_override($atts['ad_client']),
            'style' => $atts['style'],
            'class' => $atts['class'],
        );

        return self::render_unit($unit, array('source' => 'shortcode'));
    }

    /**
     * Validate a per-shortcode `ad_client` override.
     *
     * Kept for sites that use it (multi-publisher setups), but only honoured
     * when it is a well-formed ad client and the content was written by a
     * trusted user - otherwise a Contributor could put someone else's
     * Publisher ID on the site.
     *
     * @param string $ad_client Raw attribute.
     * @return string Empty string when not allowed.
     * @since 1.4.0
     */
    private static function allowed_ad_client_override($ad_client)
    {
        $ad_client = trim((string) $ad_client);

        if ('' === $ad_client || !preg_match('/^ca-pub-\d{10,20}$/', $ad_client)) {
            return '';
        }

        $post = get_post();
        $trusted = !$post || user_can((int) $post->post_author, 'unfiltered_html');

        /**
         * Filters whether a shortcode may override the site's ad client.
         *
         * @param bool $trusted
         * @param string $ad_client
         * @param WP_Post|null $post
         * @since 1.4.0
         */
        return apply_filters('adflow_allow_ad_client_override', $trusted, $ad_client, $post) ? $ad_client : '';
    }

    /**
     * `[adflow id="123"]` - render a saved Ad Unit.
     *
     * @param array $atts Shortcode attributes
     * @return string
     * @since 1.4.0
     */
    public function adflow_shortcode($atts)
    {
        $atts = shortcode_atts(array(
            'id' => '',
            'style' => '',
            'class' => '',
        ), $atts, 'adflow');

        return $this->render_saved_unit($atts['id'], array('source' => 'shortcode'), $atts);
    }

    /**
     * Render a saved Ad Unit by ID, with optional style/class overrides.
     *
     * @param int $id Ad unit ID.
     * @param array $context Render context.
     * @param array $atts Overrides (style, class).
     * @return string
     * @since 1.4.0
     */
    private function render_saved_unit($id, $context, $atts = array())
    {
        $unit = Simple_Google_Adsense_Ad_Units::get($id);

        if (!$unit) {
            return self::config_notice(
                __('Ad unit not found.', 'simple-google-adsense'),
                __('The ad unit in this shortcode or block was deleted or is not published. Check AdFlow → Ad Units.', 'simple-google-adsense')
            );
        }

        $unit['style'] = isset($atts['style']) ? $atts['style'] : '';
        $unit['class'] = isset($atts['class']) ? $atts['class'] : '';

        return self::render_unit($unit, $context);
    }

    /**
     * Render one ad unit.
     *
     * The single place that builds `<ins class="adsbygoogle">` markup - used by
     * shortcodes, the block, the widget and automatic placements.
     *
     * @param array $unit Unit data: slot, type, format, layout_key, full_width_responsive
     *                    and optionally id, ad_client, style, class.
     * @param array $context Where the ad is rendered: source (shortcode|block|widget|placement), placement.
     * @return string
     * @since 1.4.0
     */
    public static function render_unit($unit, $context = array())
    {
        // Failsafe: an ad must never take the page down (bad custom code, a
        // conflicting plugin, a PHP edge case). Log it and render nothing.
        try {
            return self::render_unit_unsafe($unit, $context);
        } catch (\Throwable $e) {
            self::log_failure($e, $unit);

            return '';
        }
    }

    /**
     * Record a rendering failure for administrators (Dashboard health) and the debug log.
     *
     * @param \Throwable $e Error.
     * @param array $unit Unit.
     * @since 1.4.0
     */
    public static function log_failure($e, $unit = array())
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('AdFlow: ad ' . (isset($unit['id']) ? (int) $unit['id'] : 0) . ' failed to render: ' . $e->getMessage()); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        }

        update_option('adflow_last_render_error', array(
            'time' => time(),
            'ad_id' => isset($unit['id']) ? (int) $unit['id'] : 0,
            'message' => substr($e->getMessage(), 0, 300),
        ), false);
    }

    /**
     * Render one ad unit (may throw; use render_unit()).
     *
     * @param array $unit Unit.
     * @param array $context Context.
     * @return string
     * @since 1.4.0
     */
    private static function render_unit_unsafe($unit, $context = array())
    {
        $unit = wp_parse_args($unit, array_merge(Simple_Google_Adsense_Ad_Units::defaults(), array(
            'id' => 0,
            'ad_client' => '',
            'style' => '',
            'class' => '',
        )));
        $context = wp_parse_args($context, array('source' => 'shortcode', 'placement' => ''));
        $unit['type'] = Simple_Google_Adsense_Ad_Units::normalize_type($unit['type']);

        if (!Simple_Google_Adsense_Settings::is_manual_ads_enabled()) {
            return self::config_notice(
                __('Manual Ads are turned off.', 'simple-google-adsense'),
                __('Turn on "Show manual ads" under AdFlow → Settings → General to render this ad.', 'simple-google-adsense')
            );
        }

        if ('group' === $unit['type']) {
            return self::render_group($unit, $context);
        }

        // Your own ads (image, text, custom code) have their own renderer.
        if (Simple_Google_Adsense_Ad_Units::is_own_ad($unit['type'])) {
            return self::render_own_unit($unit, $context);
        }

        // Types added by an add-on (e.g. Pro's Google Ad Manager units).
        if (!in_array($unit['type'], Simple_Google_Adsense_Ad_Units::adsense_types(), true)) {
            if (!Simple_Google_Adsense_Settings::ads_allowed() || !apply_filters('adflow_should_display_ad', true, $unit, $context)) {
                return '';
            }

            /**
             * Renders an ad unit of a type added by an add-on.
             *
             * @param string $html
             * @param array $unit
             * @param array $context
             * @since 1.4.0
             */
            $output = (string) apply_filters('adflow_render_ad_type', '', $unit, $context);

            if ('' !== $output && empty($context['template'])) {
                do_action('adflow_ad_rendered', $unit, $context);
            }

            return apply_filters('adflow_ad_markup', $output, $unit, $context);
        }

        $publisher_ad_client = Simple_Google_Adsense_Settings::get_ad_client();

        if ('' === $publisher_ad_client && empty($unit['ad_client'])) {
            $stored = Simple_Google_Adsense_Settings::get_publisher_id();
            return self::config_notice(
                '' === $stored ? __('AdSense Publisher ID not configured.', 'simple-google-adsense') : __('AdSense Publisher ID is not valid.', 'simple-google-adsense'),
                '' === $stored ? __('Please go to AdFlow → Settings and enter your Publisher ID.', 'simple-google-adsense') : __('It should be "pub-" followed by 16 digits. Please correct it under AdFlow → Settings.', 'simple-google-adsense')
            );
        }

        if (empty($unit['slot'])) {
            return self::config_notice(
                __('Ad Slot ID is required.', 'simple-google-adsense'),
                __('Please configure the Ad Slot ID in the block settings or shortcode parameters.', 'simple-google-adsense')
            );
        }

        if (!Simple_Google_Adsense_Settings::ads_allowed()) {
            return '';
        }

        /**
         * Filters whether this particular ad is output.
         *
         * @param bool $display
         * @param array $unit
         * @param array $context
         * @since 1.4.0
         */
        if (!apply_filters('adflow_should_display_ad', true, $unit, $context)) {
            return '';
        }

        $ad_client = !empty($unit['ad_client']) ? $unit['ad_client'] : $publisher_ad_client;

        // Load the AdSense library and styles for this page.
        Simple_Google_Adsense_Frontend::enqueue_ad_assets($ad_client);

        $ins = array(
            'class' => 'adsbygoogle',
            'style' => 'display:block',
            'data-ad-client' => $ad_client,
            'data-ad-slot' => $unit['slot'],
        );

        switch ($unit['type']) {
            case 'inarticle':
                // Google's in-article code: fluid + in-article layout, centred.
                $ins['style'] = 'display:block; text-align:center;';
                $ins['data-ad-layout'] = 'in-article';
                $ins['data-ad-format'] = 'fluid';
                break;
            case 'infeed':
                $ins['data-ad-format'] = 'fluid';
                if ('' !== (string) $unit['layout_key']) {
                    $ins['data-ad-layout-key'] = $unit['layout_key'];
                }
                break;
            case 'multiplex':
                $ins['data-ad-format'] = 'autorelaxed';
                break;
            default:
                $format = '' !== (string) $unit['format'] ? $unit['format'] : 'auto';
                $ins['data-ad-format'] = $format;
                if ('auto' === $format) {
                    $full_width = $unit['full_width_responsive'];
                    $ins['data-full-width-responsive'] = is_bool($full_width) ? ($full_width ? 'true' : 'false') : (string) $full_width;
                }
        }

        /**
         * Filters the attributes of the `<ins class="adsbygoogle">` element.
         *
         * @param array $ins
         * @param array $unit
         * @param array $context
         * @since 1.4.0
         */
        $ins = apply_filters('adflow_ad_ins_attributes', $ins, $unit, $context);

        $extra_classes = array_filter(array_map('sanitize_html_class', preg_split('/\s+/', (string) $unit['class'])));

        $wrapper = array(
            'class' => trim('adsense-ad adflow-ad adflow-type-' . $unit['type'] . ' ' . implode(' ', $extra_classes)),
        );

        $style = '' !== (string) $unit['style'] ? safecss_filter_attr($unit['style']) : '';

        if ('' !== $style) {
            $wrapper['style'] = $style;
        }

        if (!empty($unit['id'])) {
            $wrapper['data-adflow-unit'] = (int) $unit['id'];
        }

        if (!empty($context['placement'])) {
            $wrapper['data-adflow-placement'] = $context['placement'];
        }

        /**
         * Filters the attributes of the wrapping `<div>`.
         *
         * @param array $wrapper
         * @param array $unit
         * @param array $context
         * @since 1.4.0
         */
        $wrapper = apply_filters('adflow_ad_wrapper_attributes', $wrapper, $unit, $context);

        $output = '<div' . self::build_attributes($wrapper) . '>';

        $label = Simple_Google_Adsense_Settings::get_ad_label();

        if ('' !== $label) {
            $output .= '<span class="adflow-ad-label">' . esc_html($label) . '</span>';
        }

        $output .= '<ins' . self::build_attributes($ins) . '></ins>';

        /**
         * Filters whether the inline `adsbygoogle.push()` is printed. Pro's lazy
         * loading turns it off and pushes when the ad scrolls into view.
         *
         * @param bool $inline_push
         * @param array $unit
         * @param array $context
         * @since 1.4.0
         */
        if (apply_filters('adflow_ad_inline_push', true, $unit, $context)) {
            $output .= '<script>(adsbygoogle = window.adsbygoogle || []).push({});</script>';
        }

        $output .= '</div>';

        /**
         * Fires after an ad has been rendered.
         *
         * @param array $unit
         * @param array $context
         * @since 1.4.0
         */
        if (empty($context['template'])) {
            do_action('adflow_ad_rendered', $unit, $context);
        }

        /**
         * Filters the final ad markup.
         *
         * @param string $output
         * @param array $unit
         * @param array $context
         * @since 1.4.0
         */
        return apply_filters('adflow_ad_markup', $output, $unit, $context);
    }

    /**
     * Render a rotation group.
     *
     * Every member is rendered into an inert <template>; the browser picks
     * one per page view (by weight, or in order per visitor) and only then
     * inserts it - so rotation works on cached pages and unchosen ads load
     * nothing and count nothing.
     *
     * @param array $unit Group unit.
     * @param array $context Context.
     * @return string
     * @since 1.4.0
     */
    private static function render_group($unit, $context)
    {
        $templates = '';
        $member_context = array_merge($context, array('group' => (int) $unit['id'], 'template' => true));

        foreach ((array) $unit['members'] as $member) {
            $child = Simple_Google_Adsense_Ad_Units::get(isset($member['id']) ? $member['id'] : 0);

            if (!$child || 'group' === $child['type']) {
                continue;
            }

            $html = self::render_unit($child, $member_context);

            if ('' !== $html) {
                $templates .= '<template data-w="' . esc_attr(max(1, (int) $member['weight'])) . '">' . $html . '</template>';
            }
        }

        if ('' === $templates) {
            return '';
        }

        Simple_Google_Adsense_Frontend::enqueue_own_ad_assets();

        $output = '<div class="afx-group" data-afx-g="' . esc_attr((int) $unit['id']) . '" data-afx-mode="' . esc_attr('ordered' === $unit['rotation'] ? 'ordered' : 'weighted') . '">' . $templates . '</div>';

        /** This action is documented in render_unit(). */
        do_action('adflow_ad_rendered', $unit, $context);

        /** This filter is documented in render_unit(). */
        return apply_filters('adflow_ad_markup', $output, $unit, $context);
    }

    /**
     * Render one of your own ads (image banner, text ad, custom code).
     *
     * Markup uses neutral class names (afx-*) so ad blockers' generic rules
     * do not hide the site owner's own promotions.
     *
     * @param array $unit Unit data.
     * @param array $context Render context.
     * @return string
     * @since 1.4.0
     */
    private static function render_own_unit($unit, $context)
    {
        $Units = 'Simple_Google_Adsense_Ad_Units';

        // Outside its schedule: show the fallback AdSense unit, or nothing.
        if ('running' !== $Units::status($unit)) {
            $fallback = !empty($unit['fallback_id']) ? $Units::get($unit['fallback_id']) : null;

            return $fallback && !$Units::is_own_ad($fallback['type']) ? self::render_unit($fallback, $context) : '';
        }

        $missing = '';
        if ('image' === $unit['type'] && !wp_get_attachment_image_url((int) $unit['image_id'], 'full')) {
            $missing = __('This image ad has no image.', 'simple-google-adsense');
        } elseif ('text' === $unit['type'] && '' === trim((string) $unit['headline'])) {
            $missing = __('This text ad has no headline.', 'simple-google-adsense');
        } elseif ('custom' === $unit['type'] && '' === trim((string) $unit['code'])) {
            $missing = __('This custom ad unit has no code.', 'simple-google-adsense');
        }

        if ('' !== $missing) {
            return self::config_notice($missing, __('Edit it under AdFlow → Ad Units.', 'simple-google-adsense'));
        }

        if (!Simple_Google_Adsense_Settings::ads_allowed() || !apply_filters('adflow_should_display_ad', true, $unit, $context)) {
            return '';
        }

        Simple_Google_Adsense_Frontend::enqueue_own_ad_assets();

        // Link attributes: paid links are "sponsored" (Google's guidance).
        $rel = array('sponsored', 'noopener');
        if (!empty($unit['nofollow'])) {
            $rel[] = 'nofollow';
        }
        $link = '';

        if ('' !== (string) $unit['url']) {
            $redirect = !empty($unit['id']) && !empty($unit['track']) && Simple_Google_Adsense_Stats::uses_redirect();
            $href = $redirect
                ? Simple_Google_Adsense_Stats::redirect_url($unit['id'], $context['placement'])
                : Simple_Google_Adsense_Stats::expand_macros($unit['url'], isset($unit['id']) ? $unit['id'] : 0, $context['placement']);

            $link = ' href="' . esc_url($href) . '" rel="' . esc_attr(implode(' ', $rel)) . '"'
                . (!empty($unit['new_tab']) ? ' target="_blank"' : '')
                . ($redirect ? ' data-afx-go="1"' : '');
        }

        switch ($unit['type']) {
            case 'image':
                $img = wp_get_attachment_image((int) $unit['image_id'], 'full', false, array(
                    'alt' => (string) $unit['alt'],
                    'loading' => 'lazy',
                    'decoding' => 'async',
                    'class' => 'afx-img',
                ));
                $inner = '' !== $link ? '<a class="afx-link"' . $link . '>' . $img . '</a>' : $img;
                break;
            case 'text':
                $inner = '<div class="afx-text">'
                    . ('' !== $link ? '<a class="afx-link afx-headline"' . $link . '>' . esc_html($unit['headline']) . '</a>' : '<strong class="afx-headline">' . esc_html($unit['headline']) . '</strong>')
                    . ('' !== trim((string) $unit['body']) ? '<p class="afx-body">' . nl2br(esc_html($unit['body'])) . '</p>' : '')
                    . ('' !== trim((string) $unit['cta']) && '' !== $link ? '<a class="afx-link afx-cta"' . $link . '>' . esc_html($unit['cta']) . '</a>' : '')
                    . '</div>';
                break;
            default:
                // Saved only by users with the unfiltered_html capability (see Ad Units).
                $inner = (string) $unit['code'];
        }

        $extra_classes = array_filter(array_map('sanitize_html_class', preg_split('/\s+/', (string) $unit['class'])));
        $wrapper = array(
            'class' => trim('afx-unit afx-t-' . $unit['type'] . ' ' . implode(' ', $extra_classes)),
        );

        $style = '' !== (string) $unit['style'] ? safecss_filter_attr($unit['style']) : '';
        if ('' !== $style) {
            $wrapper['style'] = $style;
        }

        $schedule = $Units::schedule($unit);
        if (!empty($unit['id'])) {
            $wrapper['data-adflow-unit'] = (int) $unit['id'];

            if (!empty($unit['track']) && Simple_Google_Adsense_Stats::is_enabled()) {
                $wrapper['data-afx'] = (int) $unit['id'];
                $wrapper['data-afx-p'] = (string) $context['placement'];
            }
        }
        // Let the browser hide the ad if a cached page outlives the schedule.
        if ($schedule['start']) {
            $wrapper['data-afx-s'] = $schedule['start'];
        }
        if ($schedule['end']) {
            $wrapper['data-afx-e'] = $schedule['end'];
        }
        if (!empty($context['placement'])) {
            $wrapper['data-adflow-placement'] = $context['placement'];
        }

        /** This filter is documented in render_unit(). */
        $wrapper = apply_filters('adflow_ad_wrapper_attributes', $wrapper, $unit, $context);

        // Other networks' code is held back while anything in the browser may
        // still decide against showing it (consent, schedule, days/hours, cap,
        // country): it must never load and then be hidden.
        if ('custom' === $unit['type'] && '' !== trim($inner)) {
            $consent = Simple_Google_Adsense_Consent::waits();
            $rules = array_intersect_key($wrapper, array_flip(array('data-afx-s', 'data-afx-e', 'data-afx-days', 'data-afx-hours', 'data-afx-cap', 'data-afx-geo')));

            if ($consent || $rules) {
                $inner = '<template class="afx-wait">' . $inner . '</template>';
                $wrapper['data-afx-hold'] = '1';

                if ($consent) {
                    $wrapper['data-afx-consent'] = '1';
                }

                Simple_Google_Adsense_Frontend::enqueue_own_ad_assets();
            }
        }

        $disclosures = $Units::disclosures();
        $note = 'none' !== $unit['disclosure'] && isset($disclosures[$unit['disclosure']]) ? $disclosures[$unit['disclosure']] : '';

        $output = '<div' . self::build_attributes($wrapper) . '>'
            . ('' !== $note ? '<span class="afx-note">' . esc_html($note) . '</span>' : '')
            . $inner
            . '</div>';

        /** This action is documented in render_unit(). Group members fire it through their group. */
        if (empty($context['template'])) {
            do_action('adflow_ad_rendered', $unit, $context);
        }

        /** This filter is documented in render_unit(). */
        return apply_filters('adflow_ad_markup', $output, $unit, $context);
    }

    /**
     * Never texturize inside <template> (held-back ad code).
     *
     * @param string[] $tags Tags.
     * @return string[]
     * @since 1.4.0
     */
    public function no_texturize_templates($tags)
    {
        $tags[] = 'template';

        return $tags;
    }

    /**
     * Build an HTML attribute string.
     *
     * @param array $attributes name => value.
     * @return string
     * @since 1.4.0
     */
    private static function build_attributes($attributes)
    {
        $html = '';

        foreach ($attributes as $name => $value) {
            $html .= ' ' . esc_attr($name) . '="' . esc_attr($value) . '"';
        }

        return $html;
    }

    /**
     * Build a configuration notice.
     *
     * Only users who can actually fix the problem see it; visitors get nothing
     * rather than a broken-looking error block in the middle of the content.
     *
     * @param string $title Short description of the problem.
     * @param string $message How to resolve it.
     * @return string
     * @since 1.3.0
     */
    private static function config_notice($title, $message)
    {
        if (!current_user_can('manage_options')) {
            return '';
        }

        return '<div class="adsense-error">
                <p><strong>' . esc_html($title) . '</strong></p>
                <p>' . esc_html($message) . '</p>
            </div>';
    }

    /**
     * Banner ad shortcode
     *
     * @param array $atts Shortcode attributes
     * @return string
     */
    public function banner_ad_shortcode($atts)
    {
        $atts = is_array($atts) ? $atts : array();
        $atts['type'] = 'banner';
        $atts['_adflow_typed'] = true;
        return $this->adsense_shortcode($atts);
    }

    /**
     * In-article ad shortcode
     *
     * @param array $atts Shortcode attributes
     * @return string
     */
    public function inarticle_ad_shortcode($atts)
    {
        $atts = is_array($atts) ? $atts : array();
        $atts['type'] = 'inarticle';
        $atts['_adflow_typed'] = true;
        $atts['ad_format'] = 'fluid';
        return $this->adsense_shortcode($atts);
    }

    /**
     * In-feed ad shortcode
     *
     * @param array $atts Shortcode attributes
     * @return string
     */
    public function infeed_ad_shortcode($atts)
    {
        $atts = is_array($atts) ? $atts : array();
        $atts['type'] = 'infeed';
        $atts['_adflow_typed'] = true;
        $atts['ad_format'] = 'fluid';
        return $this->adsense_shortcode($atts);
    }

    /**
     * Matched content (Multiplex) shortcode
     *
     * @param array $atts Shortcode attributes
     * @return string
     */
    public function matched_content_shortcode($atts)
    {
        $atts = is_array($atts) ? $atts : array();
        $atts['type'] = 'matched_content';
        $atts['_adflow_typed'] = true;
        $atts['ad_format'] = 'autorelaxed';
        return $this->adsense_shortcode($atts);
    }



    /**
     * Register Gutenberg blocks
     */
    public function register_blocks()
    {
        if (!function_exists('register_block_type')) {
            return;
        }

        register_block_type(
            SIMPLE_GOOGLE_ADSENSE_ABSPATH . 'blocks/adsense-ad',
            array(
                'render_callback' => array($this, 'render_block'),
            )
        );
    }

    /**
     * Render Gutenberg block
     *
     * @param array $attributes Block attributes
     * @return string
     */
    public function render_block($attributes)
    {
        $html = $this->render_block_markup($attributes);
        $align = isset($attributes['align']) ? (string) $attributes['align'] : '';

        // Wide / full / left / right chosen in the editor (only when set, so older output is unchanged).
        if ('' !== $html && in_array($align, array('wide', 'full', 'left', 'right', 'center'), true)) {
            $html = '<div class="align' . esc_attr($align) . '">' . $html . '</div>';
        }

        return $html;
    }

    /**
     * Block markup without alignment.
     *
     * @param array $attributes Block attributes.
     * @return string
     * @since 1.4.0
     */
    private function render_block_markup($attributes)
    {
        $ad_id = isset($attributes['adId']) ? absint($attributes['adId']) : 0;

        if ($ad_id) {
            return $this->render_saved_unit($ad_id, array('source' => 'block'));
        }

        $ad_slot = isset($attributes['adSlot']) ? $attributes['adSlot'] : '';
        $ad_type = isset($attributes['adType']) ? $attributes['adType'] : 'banner';
        $ad_format = isset($attributes['adFormat']) ? $attributes['adFormat'] : 'auto';
        $full_width_responsive = isset($attributes['fullWidthResponsive']) ? $attributes['fullWidthResponsive'] : true;
        $layout_key = isset($attributes['layoutKey']) ? $attributes['layoutKey'] : '';

        // Before 1.4.0 the block's type setting did not change the markup; keep
        // that, unless the new in-feed layout key is filled in.
        return self::render_unit(array(
            'slot' => $ad_slot,
            'type' => ('infeed' === $ad_type && '' !== trim((string) $layout_key)) ? 'infeed' : 'display',
            'format' => $ad_format,
            'layout_key' => $layout_key,
            'full_width_responsive' => (bool) $full_width_responsive,
        ), array('source' => 'block'));
    }

    /**
     * Get available ad types
     *
     * @return array
     */
    public static function get_ad_types()
    {
        return array(
            'banner' => __('Banner Ad', 'simple-google-adsense'),
            'inarticle' => __('In-Article Ad', 'simple-google-adsense'),
            'infeed' => __('In-Feed Ad', 'simple-google-adsense'),
            'matched_content' => __('Multiplex (Matched Content)', 'simple-google-adsense')
        );
    }

    /**
     * Get available ad formats
     *
     * @return array
     */
    public static function get_ad_formats()
    {
        return array(
            'auto' => __('Auto', 'simple-google-adsense'),
            'fluid' => __('Fluid', 'simple-google-adsense'),
            'autorelaxed' => __('Auto Relaxed', 'simple-google-adsense'),
            'rectangle' => __('Rectangle', 'simple-google-adsense'),
            'horizontal' => __('Horizontal', 'simple-google-adsense'),
            'vertical' => __('Vertical', 'simple-google-adsense')
        );
    }
}
