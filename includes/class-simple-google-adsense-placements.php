<?php
/**
 * Simple_Google_Adsense automatic placements
 *
 * Inserts saved Ad Units into content automatically (before/after content,
 * after paragraph N). The registry is filterable so AdFlow Pro can add more
 * placement types and display conditions.
 *
 * @package Simple_Google_Adsense
 * @since   1.4.0
 */

defined('ABSPATH') || exit;

/**
 * Simple_Google_Adsense_Placements Class.
 *
 * @class Simple_Google_Adsense_Placements
 */
final class Simple_Google_Adsense_Placements
{

    /**
     * Option holding placement settings.
     */
    const OPTION_NAME = 'simple_google_adsense_placements';

    /**
     * Temporary markers around ads inserted into the content, so paragraph
     * counting ignores paragraphs inside ads. Removed before output.
     */
    const AD_START = '<!--adflow-ad-->';
    const AD_END = '<!--/adflow-ad-->';

    /**
     * Admin page slug.
     */
    const PAGE_SLUG = 'adflow-placements';

    /**
     * The single instance of the class.
     *
     * @var Simple_Google_Adsense_Placements
     * @since 1.4.0
     */
    protected static $_instance = null;

    /**
     * Main Simple_Google_Adsense_Placements Instance.
     *
     * @return Simple_Google_Adsense_Placements
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
     * Simple_Google_Adsense_Placements Constructor.
     */
    public function __construct()
    {
        add_action('admin_init', array($this, 'register_setting'));
        add_action('admin_menu', array($this, 'add_menu'), 12);

        if (!is_admin() || wp_doing_ajax()) {
            // After shortcodes (11) and wpautop (10) so paragraphs exist.
            add_filter('the_content', array($this, 'insert_into_content'), 20);
        }
    }

    /**
     * Registered placement types.
     *
     * Each entry: label, description, group, fields (subset of: paragraph),
     * inserter (callable( $content, $html, $placement ) for content placements),
     * pro (bool - shown as a Pro teaser when Pro is not active).
     *
     * @return array
     * @since 1.4.0
     */
    public static function get_types()
    {
        // Built once per request after init (it runs several times per page).
        static $cache = null;

        if (null !== $cache && did_action('init')) {
            return $cache;
        }

        $types = array(
            'before_content' => array(
                'position' => 10,
                'label' => __('Before content', 'simple-google-adsense'),
                'description' => __('Above the first paragraph of the post.', 'simple-google-adsense'),
                'group' => 'content',
                'fields' => array(),
                'inserter' => array(__CLASS__, 'insert_before'),
            ),
            'after_paragraph' => array(
                'position' => 20,
                'label' => __('After paragraph', 'simple-google-adsense'),
                'description' => __('Inside the post, after the chosen paragraph. Skipped when the post is shorter.', 'simple-google-adsense'),
                'group' => 'content',
                'fields' => array('paragraph'),
                'inserter' => array(__CLASS__, 'insert_after_paragraph'),
            ),
            'after_content' => array(
                'position' => 40,
                'label' => __('After content', 'simple-google-adsense'),
                'description' => __('Below the last paragraph of the post.', 'simple-google-adsense'),
                'group' => 'content',
                'fields' => array(),
                'inserter' => array(__CLASS__, 'insert_after'),
            ),
        );

        if (!Simple_Google_Adsense_Settings::is_pro_active()) {
            $teasers = array(
                'content_percent' => array(__('Middle of the article (after X%)', 'simple-google-adsense'), __('Scales with post length - the highest-earning in-content spot for most blogs.', 'simple-google-adsense')),
                'between_posts' => array(__('Between posts', 'simple-google-adsense'), __('In the post list on your home, category, tag and search pages.', 'simple-google-adsense')),
                'sticky_anchor' => array(__('Sticky anchor', 'simple-google-adsense'), __('A small closable bar at the bottom of the screen that never covers content.', 'simple-google-adsense')),
                'woo_shop' => array(__('WooCommerce shop & product pages', 'simple-google-adsense'), __('Above the product grid, below the summary and after add-to-cart.', 'simple-google-adsense')),
            );

            foreach ($teasers as $key => $teaser) {
                $types[$key] = array(
                    'label' => $teaser[0],
                    'description' => $teaser[1],
                    'group' => 'pro',
                    'fields' => array(),
                    'pro' => true,
                );
            }
        }

        /**
         * Filters the placement types. AdFlow Pro registers its placements here.
         *
         * @param array $types
         * @since 1.4.0
         */
        $types = apply_filters('adflow_placement_types', $types);

        if (did_action('init')) {
            $cache = $types;
        }

        return $types;
    }

    /**
     * Default settings for one placement.
     *
     * @return array
     * @since 1.4.0
     */
    public static function placement_defaults()
    {
        return array(
            'enabled' => false,
            'ad_id' => 0,
            'paragraph' => 3,
            'post_types' => array('post'),
        );
    }

    /**
     * All stored placements merged over defaults.
     *
     * @return array key => settings
     * @since 1.4.0
     */
    public static function get_all()
    {
        $stored = get_option(self::OPTION_NAME);
        $stored = is_array($stored) ? $stored : array();
        $placements = array();

        foreach (self::get_types() as $key => $type) {
            if (!empty($type['pro'])) {
                continue;
            }

            $placements[$key] = wp_parse_args(
                isset($stored[$key]) && is_array($stored[$key]) ? $stored[$key] : array(),
                self::placement_defaults()
            );
        }

        return $placements;
    }

    /**
     * Get one placement's settings.
     *
     * @param string $key Placement key.
     * @return array|null
     * @since 1.4.0
     */
    public static function get($key)
    {
        $placements = self::get_all();

        return isset($placements[$key]) ? $placements[$key] : null;
    }

    /**
     * Render a placement's ad if it is enabled and allowed here.
     *
     * Public so Pro placements hooked to theme/WooCommerce actions can reuse
     * the same enable/condition/rendering pipeline.
     *
     * @param string $key Placement key.
     * @return string
     * @since 1.4.0
     */
    public static function render($key)
    {
        $placement = self::get($key);

        if (!$placement || empty($placement['enabled'])) {
            return '';
        }

        if (!self::should_display($key, $placement)) {
            return '';
        }

        /**
         * Filters which ad unit a placement renders. Pro uses it for A/B rotation.
         *
         * @param int $ad_id
         * @param string $key
         * @param array $placement
         * @since 1.4.0
         */
        $ad_id = (int) apply_filters('adflow_placement_ad_id', (int) $placement['ad_id'], $key, $placement);
        $unit = Simple_Google_Adsense_Ad_Units::get($ad_id);

        if (!$unit) {
            return '';
        }

        return Simple_Google_Adsense_Manual_Ads::render_unit($unit, array(
            'source' => 'placement',
            'placement' => $key,
        ));
    }

    /**
     * Whether a placement may display on the current request.
     *
     * @param string $key Placement key.
     * @param array $placement Placement settings.
     * @return bool
     * @since 1.4.0
     */
    public static function should_display($key, $placement)
    {
        $display = true;
        $types = self::get_types();
        $group = isset($types[$key]['group']) ? $types[$key]['group'] : '';

        if ('content' === $group) {
            // is_singular(array()) is true for every post type - an empty list means "nowhere".
            $display = !empty($placement['post_types']) && is_singular((array) $placement['post_types']);
        }

        /**
         * Filters whether a placement displays. Pro adds device, taxonomy,
         * visitor and referrer conditions here.
         *
         * @param bool $display
         * @param string $key
         * @param array $placement
         * @since 1.4.0
         */
        return (bool) apply_filters('adflow_placement_should_display', $display, $key, $placement);
    }

    /**
     * Insert content placements into the main post content.
     *
     * @param string $content Post content.
     * @return string
     * @since 1.4.0
     */
    public function insert_into_content($content)
    {
        if (!is_singular() || !in_the_loop() || !is_main_query() || is_feed() || doing_filter('get_the_excerpt')) {
            return $content;
        }

        // Only the queried post - not related-post widgets or blocks that call the_content.
        if ((int) get_the_ID() !== (int) get_queried_object_id() || post_password_required()) {
            return $content;
        }

        // Themes and plugins (TOC, reading time, schema) may run the_content
        // more than once in the loop; insert only once per post. (Block themes
        // render content before wp_head, so that cannot be used as a signal.)
        static $done = array();

        if (isset($done[get_the_ID()])) {
            return $content;
        }

        $done[get_the_ID()] = true;

        foreach (self::get_types() as $key => $type) {
            if (empty($type['inserter']) || !is_callable($type['inserter'])) {
                continue;
            }

            try {
                $html = self::render($key);

                if ('' === $html) {
                    continue;
                }

                // Mark the ad so later placements count only the article's own paragraphs.
                $content = call_user_func($type['inserter'], $content, self::AD_START . $html . self::AD_END, self::get($key));
            } catch (\Throwable $e) {
                // Never lose the post content because of an ad.
                Simple_Google_Adsense_Manual_Ads::log_failure($e, array('id' => 0));
            }
        }

        return str_replace(array(self::AD_START, self::AD_END), '', $content);
    }

    /**
     * Inserter: before content.
     *
     * @param string $content Content.
     * @param string $html Ad markup.
     * @return string
     * @since 1.4.0
     */
    public static function insert_before($content, $html)
    {
        return $html . $content;
    }

    /**
     * Inserter: after content.
     *
     * @param string $content Content.
     * @param string $html Ad markup.
     * @return string
     * @since 1.4.0
     */
    public static function insert_after($content, $html)
    {
        return $content . $html;
    }

    /**
     * Inserter: after paragraph N.
     *
     * @param string $content Content.
     * @param string $html Ad markup.
     * @param array $placement Placement settings.
     * @return string
     * @since 1.4.0
     */
    public static function insert_after_paragraph($content, $html, $placement)
    {
        return self::insert_after_nth_paragraph($content, $html, max(1, (int) $placement['paragraph']), false);
    }

    /**
     * Insert markup after the Nth top-level closing `</p>`.
     *
     * @param string $content Content.
     * @param string $html Markup to insert.
     * @param int $n Paragraph number (1-based).
     * @param bool $fallback_append Append at the end when there are fewer paragraphs.
     * @return string
     * @since 1.4.0
     */
    public static function insert_after_nth_paragraph($content, $html, $n, $fallback_append = false)
    {
        $ends = self::paragraph_end_offsets($content);

        if (count($ends) < $n) {
            return $fallback_append ? $content . $html : $content;
        }

        $offset = $ends[$n - 1];

        return substr($content, 0, $offset) . $html . substr($content, $offset);
    }

    /**
     * Byte offsets just after each paragraph that is safe to follow with an ad.
     *
     * Paragraphs inside quotes, tables, lists, figures, code, details or
     * asides are skipped, so an ad never lands inside them.
     *
     * @param string $content Content.
     * @return int[]
     * @since 1.4.0
     */
    public static function paragraph_end_offsets($content)
    {
        $containers = apply_filters('adflow_paragraph_container_tags', array('blockquote', 'table', 'ul', 'ol', 'figure', 'pre', 'aside', 'details', 'nav', 'form'));
        $pattern = '#<(/?)(p|' . implode('|', array_map('preg_quote', $containers)) . ')(?=[\s>/])[^>]*>#i';
        $depth = 0;
        $ends = array();
        $content = (string) $content;

        // Paragraphs inside ads AdFlow already inserted are not article paragraphs.
        if (false !== strpos($content, self::AD_START)) {
            $content = preg_replace_callback('#' . preg_quote(self::AD_START, '#') . '.*?' . preg_quote(self::AD_END, '#') . '#s', function ($m) {
                return str_repeat(' ', strlen($m[0]));
            }, $content);
        }

        if (!preg_match_all($pattern, $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return $ends;
        }

        foreach ($matches as $match) {
            $closing = '/' === $match[1][0];
            $tag = strtolower($match[2][0]);

            if ('p' === $tag) {
                if ($closing && 0 === $depth) {
                    $ends[] = $match[0][1] + strlen($match[0][0]);
                }
                continue;
            }

            $depth = $closing ? max(0, $depth - 1) : $depth + 1;
        }

        return $ends;
    }

    /**
     * Count paragraphs in content.
     *
     * @param string $content Content.
     * @return int
     * @since 1.4.0
     */
    public static function count_paragraphs($content)
    {
        return count(self::paragraph_end_offsets($content));
    }

    /**
     * Register the settings option.
     *
     * @since 1.4.0
     */
    public function register_setting()
    {
        register_setting('adflow_placements', self::OPTION_NAME, array(
            'sanitize_callback' => array($this, 'sanitize'),
        ));
    }

    /**
     * Sanitize placement settings.
     *
     * @param array $input Raw input.
     * @return array
     * @since 1.4.0
     */
    public function sanitize($input)
    {
        $input = is_array($input) ? $input : array();
        $public_types = array_keys(get_post_types(array('public' => true)));

        // Start from what is stored so placements registered by an inactive
        // add-on (e.g. Pro while its license is renewed) are never lost.
        $stored = get_option(self::OPTION_NAME);
        $clean = is_array($stored) ? $stored : array();

        foreach (self::get_types() as $key => $type) {
            if (!empty($type['pro'])) {
                continue;
            }

            $raw = isset($input[$key]) && is_array($input[$key]) ? $input[$key] : array();
            $post_types = isset($raw['post_types']) ? array_map('sanitize_key', (array) $raw['post_types']) : array();

            $previous = isset($clean[$key]) && is_array($clean[$key]) ? $clean[$key] : array();

            $clean[$key] = array_merge($previous, array(
                'enabled' => !empty($raw['enabled']),
                'ad_id' => isset($raw['ad_id']) ? absint($raw['ad_id']) : 0,
                'paragraph' => isset($raw['paragraph']) ? max(1, min(50, absint($raw['paragraph']))) : 3,
                'post_types' => array_values(array_intersect($post_types, $public_types)),
            ));

            /**
             * Filters one sanitized placement. Pro stores its conditions here.
             *
             * @param array $clean
             * @param array $raw
             * @param string $key
             * @since 1.4.0
             */
            $clean[$key] = apply_filters('adflow_sanitize_placement', $clean[$key], $raw, $key);
        }

        return $clean;
    }

    /**
     * Add the submenu page.
     *
     * @since 1.4.0
     */
    public function add_menu()
    {
        add_submenu_page(
            Simple_Google_Adsense_Admin::MENU_SLUG,
            __('Placements', 'simple-google-adsense'),
            __('Placements', 'simple-google-adsense'),
            Simple_Google_Adsense_Caps::MANAGE_ADS,
            self::PAGE_SLUG,
            array($this, 'render_page')
        );
    }

    /**
     * Placement groups, in display order.
     *
     * @return array key => label
     * @since 1.4.0
     */
    public static function get_groups()
    {
        return apply_filters('adflow_placement_groups', array(
            'content' => __('Inside posts & pages', 'simple-google-adsense'),
            'archive' => __('Post lists & archives', 'simple-google-adsense'),
            'site' => __('Site-wide', 'simple-google-adsense'),
            'woocommerce' => __('WooCommerce', 'simple-google-adsense'),
            'pro' => __('More placements with AdFlow Pro', 'simple-google-adsense'),
        ));
    }

    /**
     * Render the placements page.
     *
     * @since 1.4.0
     */
    public function render_page()
    {
        $units = Simple_Google_Adsense_Ad_Units::get_choices();
        $placements = self::get_all();
        $types = self::get_types();
        $post_types = get_post_types(array('public' => true), 'objects');
        unset($post_types['attachment']);

        // Reading order within each group (Pro types slot in between).
        uasort($types, function ($a, $b) {
            return (isset($a['position']) ? $a['position'] : 50) - (isset($b['position']) ? $b['position'] : 50);
        });

        $grouped = array();
        foreach ($types as $key => $type) {
            $group = isset($type['group']) ? $type['group'] : 'content';
            $grouped[$group][$key] = $type;
        }
        ?>
        <div class="wrap adflow-page">
            <?php
            Simple_Google_Adsense_Admin::render_header(
                __('Placements', 'simple-google-adsense'),
                __('Insert your ad units automatically - no shortcodes needed. Works alongside Auto Ads, blocks and shortcodes.', 'simple-google-adsense'),
                array(array('label' => __('Add ad unit', 'simple-google-adsense'), 'url' => admin_url('post-new.php?post_type=' . Simple_Google_Adsense_Ad_Units::POST_TYPE)))
            );
            ?>

            <?php if (empty($units)) : ?>
                <section class="adflow-panel">
                    <div class="adflow-empty">
                        <span class="dashicons dashicons-layout" aria-hidden="true"></span>
                        <h3><?php esc_html_e('Create an ad unit first', 'simple-google-adsense'); ?></h3>
                        <p><?php esc_html_e('Placements show one of your ad units. Add the slot ID from your AdSense account, then come back here.', 'simple-google-adsense'); ?></p>
                        <a class="button button-primary" href="<?php echo esc_url(admin_url('post-new.php?post_type=' . Simple_Google_Adsense_Ad_Units::POST_TYPE)); ?>"><?php esc_html_e('Add ad unit', 'simple-google-adsense'); ?></a>
                    </div>
                </section>
            <?php endif; ?>

            <form action="options.php" method="post">
                <?php settings_fields('adflow_placements'); ?>

                <?php foreach (self::get_groups() as $group_key => $group_label) :
                    if (empty($grouped[$group_key])) {
                        continue;
                    }
                    ?>
                    <section class="adflow-panel">
                        <h2 class="adflow-group-label"><?php echo esc_html($group_label); ?></h2>
                        <?php foreach ($grouped[$group_key] as $key => $type) :
                            $locked = !empty($type['pro']);
                            $placement = isset($placements[$key]) ? $placements[$key] : self::placement_defaults();
                            $name = self::OPTION_NAME . '[' . $key . ']';
                            $enabled = !$locked && !empty($placement['enabled']);
                            $fields = isset($type['fields']) ? (array) $type['fields'] : array();
                            ?>
                            <div class="adflow-placement<?php echo $enabled ? ' is-enabled' : ''; ?><?php echo $locked ? ' adflow-placement--locked' : ''; ?>" data-adflow-placement="<?php echo esc_attr($key); ?>">
                                <div class="adflow-placement__row">
                                    <div>
                                        <div class="adflow-placement__title">
                                            <?php if ($locked) : ?>
                                                <label class="adflow-toggle"><input type="checkbox" disabled><span class="adflow-toggle__track" aria-hidden="true"></span></label>
                                                <strong><?php echo esc_html($type['label']); ?></strong>
                                                <span class="adflow-pro-tag"><?php esc_html_e('Pro', 'simple-google-adsense'); ?></span>
                                            <?php else : ?>
                                                <?php Simple_Google_Adsense_Admin::toggle($name . '[enabled]', $enabled, '', 'adflow-pl-' . $key); ?>
                                                <label for="adflow-pl-<?php echo esc_attr($key); ?>"><strong><?php echo esc_html($type['label']); ?></strong></label>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (!empty($type['description'])) : ?>
                                            <p class="adflow-placement__desc"><?php echo esc_html($type['description']); ?></p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="adflow-placement__unit">
                                        <?php if ($locked) : ?>
                                            <a href="<?php echo esc_url(Simple_Google_Adsense_Admin::pro_url('placements-' . $key)); ?>" target="_blank" rel="noopener"><?php esc_html_e('Available in AdFlow Pro', 'simple-google-adsense'); ?></a>
                                        <?php else : ?>
                                            <label class="screen-reader-text" for="adflow-pl-unit-<?php echo esc_attr($key); ?>"><?php esc_html_e('Ad unit', 'simple-google-adsense'); ?></label>
                                            <select id="adflow-pl-unit-<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($name); ?>[ad_id]">
                                                <option value="0"><?php esc_html_e('Choose an ad unit…', 'simple-google-adsense'); ?></option>
                                                <?php foreach ($units as $id => $label) : ?>
                                                    <option value="<?php echo esc_attr($id); ?>" <?php selected((int) $placement['ad_id'], (int) $id); ?>><?php echo esc_html($label); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <?php if (!$locked) : ?>
                                    <div class="adflow-placement__details">
                                        <div class="adflow-placement__options">
                                            <?php if (in_array('paragraph', $fields, true)) : ?>
                                                <div>
                                                    <label class="adflow-sublabel" for="adflow-pl-par-<?php echo esc_attr($key); ?>"><?php esc_html_e('After paragraph', 'simple-google-adsense'); ?></label>
                                                    <input type="number" min="1" max="50" class="small-text" id="adflow-pl-par-<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($name); ?>[paragraph]" value="<?php echo esc_attr($placement['paragraph']); ?>">
                                                </div>
                                            <?php endif; ?>

                                            <?php if ('content' === $type['group']) : ?>
                                                <fieldset>
                                                    <legend class="adflow-sublabel"><?php esc_html_e('Show on', 'simple-google-adsense'); ?></legend>
                                                    <div class="adflow-checks">
                                                        <?php foreach ($post_types as $post_type) : ?>
                                                            <label>
                                                                <input type="checkbox" name="<?php echo esc_attr($name); ?>[post_types][]" value="<?php echo esc_attr($post_type->name); ?>" <?php checked(in_array($post_type->name, (array) $placement['post_types'], true)); ?>>
                                                                <?php echo esc_html($post_type->labels->name); ?>
                                                            </label>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </fieldset>
                                            <?php endif; ?>

                                            <?php
                                            /**
                                             * Fires inside a placement's options. Pro renders its fields here.
                                             *
                                             * @param string $key
                                             * @param array $placement
                                             * @param string $name Input name prefix.
                                             * @since 1.4.0
                                             */
                                            do_action('adflow_placement_options', $key, $placement, $name);
                                            ?>
                                        </div>

                                        <?php
                                        /**
                                         * Fires below a placement's options. Pro renders targeting here.
                                         *
                                         * @param string $key
                                         * @param array $placement
                                         * @param string $name Input name prefix.
                                         * @since 1.4.0
                                         */
                                        do_action('adflow_placement_settings_fields', $key, $placement, $name);
                                        ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </section>
                <?php endforeach; ?>

                <div class="adflow-savebar">
                    <?php submit_button(__('Save placements', 'simple-google-adsense'), 'primary', 'submit', false); ?>
                </div>
            </form>
        </div>
        <?php
    }
}
