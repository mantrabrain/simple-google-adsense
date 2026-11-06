<?php
/**
 * Simple_Google_Adsense repeatable in-content ads
 *
 * Any number of extra placements on AdFlow → Placements: after paragraph N,
 * every N paragraphs, or before/after an HTML element. Each is stored in the
 * placements option under its own key (content_slot_<n>) in the same shape as
 * every other placement, so ad unit, post types and the
 * adflow_placement_* / adflow_sanitize_placement filters (and everything AdFlow
 * Pro hooks to them) apply unchanged. No slot exists until the owner adds one.
 *
 * @package Simple_Google_Adsense
 * @since   1.6.0
 */

defined('ABSPATH') || exit;

/**
 * Simple_Google_Adsense_Content_Slots Class.
 *
 * @class Simple_Google_Adsense_Content_Slots
 */
final class Simple_Google_Adsense_Content_Slots
{

    /**
     * Key prefix of a slot in the placements option.
     */
    const PREFIX = 'content_slot_';

    /**
     * Most slots a site can have.
     */
    const MAX_SLOTS = 20;

    /**
     * The single instance of the class.
     *
     * @var Simple_Google_Adsense_Content_Slots
     */
    protected static $_instance = null;

    /**
     * Main instance.
     *
     * @return Simple_Google_Adsense_Content_Slots
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
        // Early, so add-ons see the slots in the same filter.
        add_filter('adflow_placement_types', array($this, 'register_types'), 1);
        add_filter('adflow_stats_placement_keys', array($this, 'stats_keys'));
        add_filter('adflow_placement_should_display', array($this, 'allow_on_lists'), 1, 3);
        add_action('admin_enqueue_scripts', array($this, 'enqueue'));

        if (!is_admin() || wp_doing_ajax()) {
            add_action('wp_footer', array($this, 'print_element_slots'), 7);
        }
    }

    /**
     * Default settings of one slot.
     *
     * @return array
     * @since 1.6.0
     */
    public static function defaults()
    {
        return array(
            'enabled' => false,
            'ad_id' => 0,
            'post_types' => array('post'),
            'mode' => 'every',
            'paragraph' => 3,
            'interval' => 4,
            'max_ads' => 3,
            'min_paragraphs' => 6,
            'selector' => '',
            'nth' => 1,
            'position' => 'after',
            'reserve' => 250,
            'on_lists' => false,
        );
    }

    /**
     * Whether a key is a slot key.
     *
     * @param mixed $key Key.
     * @return bool
     * @since 1.6.0
     */
    public static function is_slot_key($key)
    {
        return is_string($key) && (bool) preg_match('/^' . self::PREFIX . '[1-9]\d{0,3}$/', $key);
    }

    /**
     * Stored slots in their numeric order, merged over the defaults.
     *
     * @return array key => settings
     * @since 1.6.0
     */
    public static function get_all()
    {
        $stored = get_option(Simple_Google_Adsense_Placements::OPTION_NAME);
        $slots = array();

        if (!is_array($stored)) {
            return $slots;
        }

        foreach ($stored as $key => $value) {
            if (self::is_slot_key($key) && is_array($value)) {
                $slots[$key] = wp_parse_args($value, self::defaults());
            }
        }

        uksort($slots, 'strnatcmp');

        return $slots;
    }

    /**
     * Register stored slots as placement types.
     *
     * They have no `inserter`: Simple_Google_Adsense_Placements runs them last,
     * through insert(), so they can keep clear of every other ad.
     *
     * @param array $types Types.
     * @return array
     * @since 1.6.0
     */
    public function register_types($types)
    {
        foreach (self::get_all() as $key => $slot) {
            $types[$key] = array(
                'position' => 45,
                'label' => self::label($key),
                'description' => '',
                'group' => 'content',
                'fields' => array(),
                'slot' => true,
            );
        }

        return $types;
    }

    /**
     * Display name of a slot.
     *
     * @param string $key Slot key.
     * @return string
     * @since 1.6.0
     */
    public static function label($key)
    {
        /* translators: %s: number of the extra ad, e.g. 2 */
        return sprintf(__('Extra ad %s', 'simple-google-adsense'), substr($key, strlen(self::PREFIX)));
    }

    /**
     * Statistics may be stored under the slots that exist.
     *
     * @param string[] $keys Keys.
     * @return string[]
     * @since 1.6.0
     */
    public function stats_keys($keys)
    {
        return array_merge((array) $keys, array_keys(self::get_all()));
    }

    /**
     * Fewest paragraphs between two in-content ads.
     *
     * @return int
     * @since 1.6.0
     */
    public static function min_spacing()
    {
        /**
         * Filters the fewest paragraphs kept between an extra in-content ad and
         * any other ad in the post. Never below 1, so two ads are never adjacent.
         *
         * @param int $spacing Default 2.
         * @since 1.6.0
         */
        return max(1, (int) apply_filters('adflow_content_slot_min_spacing', 2));
    }

    /**
     * Paragraph numbers already followed by an ad (0 = above the first
     * paragraph). Covers placements inserted earlier and ads the author put in
     * the post with a shortcode or block.
     *
     * @param string $content Content.
     * @param int[] $ends Paragraph end offsets.
     * @return int[]
     * @since 1.6.0
     */
    private static function occupied($content, $ends)
    {
        $occupied = array();
        $pattern = '#' . preg_quote(Simple_Google_Adsense_Placements::AD_START, '#') . '|<(?:div|ins)\b[^>]*\bclass=["\'][^"\']*\b(?:adflow-ad|adsense-ad|afx-unit|afx-group|adsbygoogle)\b#i';

        if (!preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
            return $occupied;
        }

        foreach ($matches[0] as $match) {
            $n = 0;

            foreach ($ends as $end) {
                if ($end > $match[1]) {
                    break;
                }
                $n++;
            }

            $occupied[$n] = $n;
        }

        return array_values($occupied);
    }

    /**
     * Paragraph numbers a slot asks for, in order.
     *
     * @param array $slot Slot settings.
     * @param int $total Paragraphs in the post.
     * @return int[]
     * @since 1.6.0
     */
    private static function wanted($slot, $total)
    {
        if ('after' === $slot['mode']) {
            $n = max(1, (int) $slot['paragraph']);

            return $n <= $total ? array($n) : array();
        }

        // "Every N" stays inside the article: never after the last paragraph.
        $interval = max(1, (int) $slot['interval']);
        $wanted = array();

        for ($n = $interval; $n < $total; $n += $interval) {
            $wanted[] = $n;
        }

        return $wanted;
    }

    /**
     * Insert every paragraph-based slot into the content.
     *
     * Each ad is rendered separately, so per-page limits, A/B rotation and
     * statistics treat it as its own ad. Runs after all other placements.
     *
     * @param string $content Content (other placements' ads still marked).
     * @return string
     * @since 1.6.0
     */
    public static function insert($content)
    {
        $slots = self::get_all();

        if (!$slots) {
            return $content;
        }

        $ends = Simple_Google_Adsense_Placements::paragraph_end_offsets($content);
        $total = count($ends);
        $occupied = self::occupied($content, $ends);
        $spacing = self::min_spacing();
        $planned = array();

        foreach ($slots as $key => $slot) {
            if (empty($slot['enabled']) || 'element' === $slot['mode'] || $total < (int) $slot['min_paragraphs']) {
                continue;
            }

            // Filled up front so a hidden slot never takes room from another one.
            if (!Simple_Google_Adsense_Placements::should_display($key, $slot)) {
                continue;
            }

            $placed = 0;
            $max = 'after' === $slot['mode'] ? 1 : max(1, (int) $slot['max_ads']);

            foreach (self::wanted($slot, $total) as $n) {
                if ($placed >= $max) {
                    break;
                }

                foreach ($occupied as $other) {
                    if (abs($other - $n) < $spacing) {
                        continue 2;
                    }
                }

                $html = Simple_Google_Adsense_Placements::render($key);

                // Nothing to show (no unit, or a per-page limit was reached).
                if ('' === $html) {
                    break;
                }

                $planned[$n] = $html;
                $occupied[] = $n;
                $placed++;
            }
        }

        // From the end, so earlier offsets stay valid.
        krsort($planned);

        foreach ($planned as $n => $html) {
            $offset = $ends[$n - 1];
            $content = substr($content, 0, $offset) . Simple_Google_Adsense_Placements::AD_START . $html . Simple_Google_Adsense_Placements::AD_END . substr($content, $offset);
        }

        return $content;
    }

    /**
     * Element slots: "Also on home, archive and search pages".
     *
     * @param bool $display Display.
     * @param string $key Placement key.
     * @param array $placement Settings.
     * @return bool
     * @since 1.6.0
     */
    public function allow_on_lists($display, $key, $placement)
    {
        if (!self::is_slot_key($key) || is_singular()) {
            return $display;
        }

        return isset($placement['mode']) && 'element' === $placement['mode'] && !empty($placement['on_lists']);
    }

    /**
     * Print element slots as inert templates; the browser moves each one to its
     * element, so the page can be cached and no ad loads when the element is
     * missing.
     *
     * @since 1.6.0
     */
    public function print_element_slots()
    {
        foreach (self::get_all() as $key => $slot) {
            if (empty($slot['enabled']) || 'element' !== $slot['mode'] || '' === (string) $slot['selector']) {
                continue;
            }

            $html = Simple_Google_Adsense_Placements::render($key);

            if ('' === $html) {
                continue;
            }

            Simple_Google_Adsense_Frontend::enqueue_own_ad_assets();

            printf(
                '<template class="afx-sel" data-afx-sel="%1$s" data-afx-nth="%2$d" data-afx-pos="%3$s" data-afx-h="%4$d">%5$s</template>',
                esc_attr($slot['selector']),
                (int) $slot['nth'],
                esc_attr('before' === $slot['position'] ? 'before' : 'after'),
                (int) $slot['reserve'],
                $html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render_unit().
            );
        }
    }

    /**
     * Whether a string is an acceptable CSS selector.
     *
     * Only characters selectors need, balanced brackets, no markup. The browser
     * has the final word (an unknown selector simply places nothing).
     *
     * @param string $selector Selector.
     * @return bool
     * @since 1.6.0
     */
    public static function is_valid_selector($selector)
    {
        $selector = (string) $selector;

        if ('' === trim($selector) || strlen($selector) > 200) {
            return false;
        }

        if (!preg_match('/^[\w\s\-#.,:>+~*\[\]()="\'^$|]+$/u', $selector) || !preg_match('/[\w*]/', $selector)) {
            return false;
        }

        // A combinator or comma may not start or end a selector.
        if (preg_match('/^[>+~,]|[>+~,]$/', trim($selector))) {
            return false;
        }

        return substr_count($selector, '[') === substr_count($selector, ']')
            && substr_count($selector, '(') === substr_count($selector, ')')
            && 0 === substr_count($selector, '"') % 2
            && 0 === substr_count($selector, "'") % 2;
    }

    /**
     * Sanitize the slots of a submitted form. Slots missing from the input were
     * removed by the owner.
     *
     * @param array $clean Sanitized placements (holds the stored slots).
     * @param array $input Raw input.
     * @return array
     * @since 1.6.0
     */
    public static function sanitize($clean, $input)
    {
        $previous = array();

        foreach ($clean as $key => $value) {
            if (self::is_slot_key($key)) {
                $previous[$key] = is_array($value) ? $value : array();
                unset($clean[$key]);
            }
        }

        $public_types = array_keys(get_post_types(array('public' => true)));
        $range = function ($raw, $name, $min, $max, $default) {
            return isset($raw[$name]) ? max($min, min($max, absint($raw[$name]))) : $default;
        };
        $count = 0;
        $keys = array_filter(array_keys($input), array(__CLASS__, 'is_slot_key'));
        usort($keys, 'strnatcmp');

        foreach ($keys as $key) {
            if (++$count > self::MAX_SLOTS || !is_array($input[$key])) {
                continue;
            }

            $raw = $input[$key];
            $d = self::defaults();
            $post_types = isset($raw['post_types']) ? array_map('sanitize_key', (array) $raw['post_types']) : array();
            $mode = isset($raw['mode']) && in_array($raw['mode'], array('after', 'every', 'element'), true) ? $raw['mode'] : $d['mode'];
            // Refused as a whole below when it holds anything but selector characters.
            $selector = isset($raw['selector']) && is_scalar($raw['selector']) ? trim((string) $raw['selector']) : '';

            if ('' !== $selector && !self::is_valid_selector($selector)) {
                // WordPress can run this callback twice on one save: one notice is enough.
                if (!in_array('adflow-bad-selector-' . $key, wp_list_pluck(get_settings_errors('adflow_placements'), 'code'), true)) {
                    add_settings_error(
                        'adflow_placements',
                        'adflow-bad-selector-' . $key,
                        /* translators: 1: extra ad name, 2: what was typed */
                        sprintf(__('%1$s: "%2$s" is not a CSS selector. The previous value was kept.', 'simple-google-adsense'), self::label($key), esc_html($selector))
                    );
                }
                $selector = isset($previous[$key]['selector']) ? (string) $previous[$key]['selector'] : '';
            }

            $slot = array_merge(isset($previous[$key]) ? $previous[$key] : array(), array(
                'enabled' => !empty($raw['enabled']),
                'ad_id' => isset($raw['ad_id']) ? absint($raw['ad_id']) : 0,
                'post_types' => array_values(array_intersect($post_types, $public_types)),
                'mode' => $mode,
                'paragraph' => $range($raw, 'paragraph', 1, 50, $d['paragraph']),
                'interval' => $range($raw, 'interval', 1, 20, $d['interval']),
                'max_ads' => $range($raw, 'max_ads', 1, 20, $d['max_ads']),
                'min_paragraphs' => $range($raw, 'min_paragraphs', 0, 200, $d['min_paragraphs']),
                'selector' => $selector,
                'nth' => $range($raw, 'nth', 1, 50, $d['nth']),
                'position' => isset($raw['position']) && 'before' === $raw['position'] ? 'before' : 'after',
                'reserve' => $range($raw, 'reserve', 0, 1200, $d['reserve']),
                'on_lists' => !empty($raw['on_lists']),
            ));

            /** This filter is documented in Simple_Google_Adsense_Placements::sanitize(). */
            $clean[$key] = apply_filters('adflow_sanitize_placement', $slot, $raw, $key);
        }

        return $clean;
    }

    /**
     * Add a slot from code (used by the importers).
     *
     * @param array $settings Settings over the defaults.
     * @return string The new key, or '' when the site has the maximum.
     * @since 1.6.0
     */
    public static function add($settings)
    {
        $stored = get_option(Simple_Google_Adsense_Placements::OPTION_NAME);
        $stored = is_array($stored) ? $stored : array();
        $slots = self::get_all();

        if (count($slots) >= self::MAX_SLOTS) {
            return '';
        }

        $key = self::PREFIX . self::next_number($slots);
        $stored[$key] = array_merge(self::defaults(), $settings);

        // Stored shape, not form input: skip the settings-form sanitizer.
        $filter = 'sanitize_option_' . Simple_Google_Adsense_Placements::OPTION_NAME;
        remove_all_filters($filter);
        update_option(Simple_Google_Adsense_Placements::OPTION_NAME, $stored);
        Simple_Google_Adsense_Placements::flush_types();

        return $key;
    }

    /**
     * Next free slot number.
     *
     * @param array $slots Slots.
     * @return int
     * @since 1.6.0
     */
    private static function next_number($slots)
    {
        $max = 0;

        foreach (array_keys($slots) as $key) {
            $max = max($max, (int) substr($key, strlen(self::PREFIX)));
        }

        return $max + 1;
    }

    /**
     * Render one slot card.
     *
     * @param string $key Slot key (or a template key).
     * @param array $slot Settings.
     * @param array $units Ad unit choices.
     * @param WP_Post_Type[] $post_types Post types.
     * @since 1.6.0
     */
    private static function render_card($key, $slot, $units, $post_types)
    {
        $name = Simple_Google_Adsense_Placements::OPTION_NAME . '[' . $key . ']';
        $id = 'adflow-pl-' . $key;
        $enabled = !empty($slot['enabled']);
        $mode = (string) $slot['mode'];
        $number = substr($key, strlen(self::PREFIX));
        ?>
        <div class="adflow-placement adflow-slot<?php echo $enabled ? ' is-enabled' : ''; ?>" data-adflow-placement="<?php echo esc_attr($key); ?>">
            <div class="adflow-placement__row">
                <div>
                    <div class="adflow-placement__title">
                        <?php Simple_Google_Adsense_Admin::toggle($name . '[enabled]', $enabled, '', $id); ?>
                        <label for="<?php echo esc_attr($id); ?>"><strong><?php
                            /* translators: %s: number of the extra ad, e.g. 2 */
                            echo esc_html(sprintf(__('Extra ad %s', 'simple-google-adsense'), $number));
                        ?></strong></label>
                    </div>
                </div>
                <div class="adflow-placement__unit">
                    <label class="screen-reader-text" for="adflow-pl-unit-<?php echo esc_attr($key); ?>"><?php esc_html_e('Ad unit', 'simple-google-adsense'); ?></label>
                    <select id="adflow-pl-unit-<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($name); ?>[ad_id]">
                        <option value="0"><?php esc_html_e('Choose an ad unit…', 'simple-google-adsense'); ?></option>
                        <?php foreach ($units as $unit_id => $label) : ?>
                            <option value="<?php echo esc_attr($unit_id); ?>" <?php selected((int) $slot['ad_id'], (int) $unit_id); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="adflow-placement__details">
                <div class="adflow-placement__options">
                    <div>
                        <label class="adflow-sublabel" for="adflow-pl-mode-<?php echo esc_attr($key); ?>"><?php esc_html_e('Where', 'simple-google-adsense'); ?></label>
                        <select id="adflow-pl-mode-<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($name); ?>[mode]" data-adflow-slot-mode>
                            <option value="every" <?php selected($mode, 'every'); ?>><?php esc_html_e('Every few paragraphs', 'simple-google-adsense'); ?></option>
                            <option value="after" <?php selected($mode, 'after'); ?>><?php esc_html_e('After one paragraph', 'simple-google-adsense'); ?></option>
                            <option value="element" <?php selected($mode, 'element'); ?>><?php esc_html_e('Before or after an HTML element', 'simple-google-adsense'); ?></option>
                        </select>
                    </div>

                    <div data-adflow-modes="after"<?php echo 'after' === $mode ? '' : ' hidden'; ?>>
                        <label class="adflow-sublabel" for="adflow-pl-par-<?php echo esc_attr($key); ?>"><?php esc_html_e('After paragraph', 'simple-google-adsense'); ?></label>
                        <input type="number" min="1" max="50" class="small-text" id="adflow-pl-par-<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($name); ?>[paragraph]" value="<?php echo esc_attr($slot['paragraph']); ?>">
                    </div>

                    <div data-adflow-modes="every"<?php echo 'every' === $mode ? '' : ' hidden'; ?>>
                        <label class="adflow-sublabel" for="adflow-pl-int-<?php echo esc_attr($key); ?>"><?php esc_html_e('Every (paragraphs)', 'simple-google-adsense'); ?></label>
                        <input type="number" min="1" max="20" class="small-text" id="adflow-pl-int-<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($name); ?>[interval]" value="<?php echo esc_attr($slot['interval']); ?>">
                    </div>

                    <div data-adflow-modes="every"<?php echo 'every' === $mode ? '' : ' hidden'; ?>>
                        <label class="adflow-sublabel" for="adflow-pl-max-<?php echo esc_attr($key); ?>"><?php esc_html_e('Most per post', 'simple-google-adsense'); ?></label>
                        <input type="number" min="1" max="20" class="small-text" id="adflow-pl-max-<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($name); ?>[max_ads]" value="<?php echo esc_attr($slot['max_ads']); ?>">
                    </div>

                    <div data-adflow-modes="after every"<?php echo 'element' !== $mode ? '' : ' hidden'; ?>>
                        <label class="adflow-sublabel" for="adflow-pl-min-<?php echo esc_attr($key); ?>"><?php esc_html_e('Skip posts shorter than (paragraphs)', 'simple-google-adsense'); ?></label>
                        <input type="number" min="0" max="200" class="small-text" id="adflow-pl-min-<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($name); ?>[min_paragraphs]" value="<?php echo esc_attr($slot['min_paragraphs']); ?>">
                    </div>

                    <div class="adflow-slot__selector" data-adflow-modes="element"<?php echo 'element' === $mode ? '' : ' hidden'; ?>>
                        <label class="adflow-sublabel" for="adflow-pl-sel-<?php echo esc_attr($key); ?>"><?php esc_html_e('CSS selector', 'simple-google-adsense'); ?></label>
                        <input type="text" class="regular-text code" id="adflow-pl-sel-<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($name); ?>[selector]" value="<?php echo esc_attr($slot['selector']); ?>" placeholder=".entry-content h2" maxlength="200" spellcheck="false" autocomplete="off" data-adflow-selector aria-describedby="adflow-pl-sel-help-<?php echo esc_attr($key); ?>">
                        <p class="description" id="adflow-pl-sel-help-<?php echo esc_attr($key); ?>" data-adflow-selector-help><?php esc_html_e('Nothing shows on a page where the element is not found.', 'simple-google-adsense'); ?></p>
                    </div>

                    <div data-adflow-modes="element"<?php echo 'element' === $mode ? '' : ' hidden'; ?>>
                        <label class="adflow-sublabel" for="adflow-pl-nth-<?php echo esc_attr($key); ?>"><?php esc_html_e('Which match', 'simple-google-adsense'); ?></label>
                        <input type="number" min="1" max="50" class="small-text" id="adflow-pl-nth-<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($name); ?>[nth]" value="<?php echo esc_attr($slot['nth']); ?>">
                    </div>

                    <div data-adflow-modes="element"<?php echo 'element' === $mode ? '' : ' hidden'; ?>>
                        <label class="adflow-sublabel" for="adflow-pl-pos-<?php echo esc_attr($key); ?>"><?php esc_html_e('Position', 'simple-google-adsense'); ?></label>
                        <select id="adflow-pl-pos-<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($name); ?>[position]">
                            <option value="after" <?php selected($slot['position'], 'after'); ?>><?php esc_html_e('After the element', 'simple-google-adsense'); ?></option>
                            <option value="before" <?php selected($slot['position'], 'before'); ?>><?php esc_html_e('Before the element', 'simple-google-adsense'); ?></option>
                        </select>
                    </div>

                    <div data-adflow-modes="element"<?php echo 'element' === $mode ? '' : ' hidden'; ?>>
                        <label class="adflow-sublabel" for="adflow-pl-res-<?php echo esc_attr($key); ?>"><?php esc_html_e('Reserve height (px)', 'simple-google-adsense'); ?></label>
                        <input type="number" min="0" max="1200" class="small-text" id="adflow-pl-res-<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($name); ?>[reserve]" value="<?php echo esc_attr($slot['reserve']); ?>">
                    </div>

                    <fieldset>
                        <legend class="adflow-sublabel"><?php esc_html_e('Show on', 'simple-google-adsense'); ?></legend>
                        <div class="adflow-checks">
                            <?php foreach ($post_types as $post_type) : ?>
                                <label>
                                    <input type="checkbox" name="<?php echo esc_attr($name); ?>[post_types][]" value="<?php echo esc_attr($post_type->name); ?>" <?php checked(in_array($post_type->name, (array) $slot['post_types'], true)); ?>>
                                    <?php echo esc_html($post_type->labels->name); ?>
                                </label>
                            <?php endforeach; ?>
                            <label data-adflow-modes="element"<?php echo 'element' === $mode ? '' : ' hidden'; ?>>
                                <input type="checkbox" name="<?php echo esc_attr($name); ?>[on_lists]" value="1" <?php checked(!empty($slot['on_lists'])); ?>>
                                <?php esc_html_e('Home, archive and search pages', 'simple-google-adsense'); ?>
                            </label>
                        </div>
                    </fieldset>

                    <?php
                    /** This action is documented in Simple_Google_Adsense_Placements::render_page(). */
                    do_action('adflow_placement_options', $key, $slot, $name);
                    ?>
                </div>

                <?php
                /** This action is documented in Simple_Google_Adsense_Placements::render_page(). */
                do_action('adflow_placement_settings_fields', $key, $slot, $name);
                ?>

                <p class="adflow-slot__remove"><button type="button" class="button-link button-link-delete" data-adflow-slot-remove><?php esc_html_e('Remove this ad', 'simple-google-adsense'); ?></button></p>
            </div>
        </div>
        <?php
    }

    /**
     * The "More ads inside posts" section of the Placements screen.
     *
     * @param array $units Ad unit choices.
     * @param WP_Post_Type[] $post_types Post types.
     * @since 1.6.0
     */
    public static function render_section($units, $post_types)
    {
        $slots = self::get_all();
        $template = array_merge(self::defaults(), array('enabled' => true));
        ?>
        <div class="adflow-slots" data-adflow-slots data-next="<?php echo esc_attr(self::next_number($slots)); ?>" data-max="<?php echo esc_attr(self::MAX_SLOTS); ?>">
            <div class="adflow-slots__intro">
                <div>
                    <strong><?php esc_html_e('More ads inside posts', 'simple-google-adsense'); ?></strong>
                    <p class="description"><?php esc_html_e('Add as many as you need. Two ads never sit next to each other: an ad is skipped when another one is closer than two paragraphs.', 'simple-google-adsense'); ?></p>
                </div>
                <button type="button" class="button" data-adflow-slot-add<?php echo count($slots) >= self::MAX_SLOTS ? ' disabled' : ''; ?>><?php esc_html_e('Add an ad', 'simple-google-adsense'); ?></button>
            </div>
            <div class="adflow-slots__list" data-adflow-slots-list>
                <?php foreach ($slots as $key => $slot) {
                    self::render_card($key, $slot, $units, $post_types);
                } ?>
            </div>
            <template data-adflow-slot-template>
                <?php self::render_card(self::PREFIX . '__N__', $template, $units, $post_types); ?>
            </template>
        </div>
        <?php
    }

    /**
     * Assets of the Placements and Settings screens.
     *
     * @since 1.6.0
     */
    public function enqueue()
    {
        if (!Simple_Google_Adsense_Admin::is_adflow_screen()) {
            return;
        }

        wp_enqueue_script('adflow-admin-picker', SIMPLE_GOOGLE_ADSENSE_PLUGIN_URI . '/assets/js/admin-picker.js', array(), SIMPLE_GOOGLE_ADSENSE_VERSION, true);
        wp_localize_script('adflow-admin-picker', 'adflowPicker', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(Simple_Google_Adsense_Picker::NONCE),
            'i18n' => array(
                'searching' => __('Searching…', 'simple-google-adsense'),
                'none' => __('Nothing found.', 'simple-google-adsense'),
                /* translators: %d: number of search results */
                'found' => __('Results: %d. Use the up and down arrows to choose.', 'simple-google-adsense'),
                /* translators: %s: name of a selected post or term */
                'remove' => __('Remove %s', 'simple-google-adsense'),
                /* translators: %s: name of a selected post or term */
                'added' => __('Added %s', 'simple-google-adsense'),
                /* translators: %s: post ID */
                'missing' => __('No post with ID %s', 'simple-google-adsense'),
                'badSelector' => __('This is not a valid CSS selector.', 'simple-google-adsense'),
                'removed' => __('Removed. Save to apply.', 'simple-google-adsense'),
            ),
        ));
    }
}
