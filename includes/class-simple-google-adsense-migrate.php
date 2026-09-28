<?php
/**
 * Simple_Google_Adsense_Migrate
 *
 * Switch from Advanced Ads, Ad Inserter, AdRotate or WP QUADS in one click:
 * - ads become AdFlow ad units (AdSense code is recognised and turned into
 *   native AdSense units; banners become image ads; anything else stays as
 *   custom code so it keeps working exactly as before);
 * - groups become rotation groups, placements fill empty AdFlow placements;
 * - old shortcodes ([the_ad], [adinserter], [adrotate], [quads]) keep working,
 *   so no post has to be edited;
 * - AdRotate and WP QUADS statistics are carried over into Reports.
 *
 * The data is read straight from the database, so the other plugin does not
 * have to be active. Importing twice never creates duplicates.
 *
 * @package Simple_Google_Adsense
 * @since   1.4.0
 */

defined('ABSPATH') || exit;

/**
 * Simple_Google_Adsense_Migrate Class.
 *
 * @class Simple_Google_Adsense_Migrate
 */
final class Simple_Google_Adsense_Migrate
{

    /**
     * Source ad/group ID => AdFlow unit ID, per source.
     */
    const MAP_OPTION = 'adflow_import_map';

    /**
     * Result of the last import, shown once.
     */
    const REPORT_OPTION = 'adflow_import_report';

    /**
     * Post meta marking where an imported unit came from ("advads:ad:45").
     */
    const ORIGIN_META = '_adflow_imported_from';

    /**
     * The single instance of the class.
     *
     * @var Simple_Google_Adsense_Migrate
     * @since 1.4.0
     */
    protected static $_instance = null;

    /**
     * Log of the running import.
     *
     * @var array
     */
    private $log = array();

    /**
     * Main Simple_Google_Adsense_Migrate Instance.
     *
     * @return Simple_Google_Adsense_Migrate
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
     * Simple_Google_Adsense_Migrate Constructor.
     */
    public function __construct()
    {
        add_filter('adflow_settings_tabs', array($this, 'register_tab'), 75);
        add_action('admin_post_adflow_migrate', array($this, 'handle'));
        add_action('init', array($this, 'compat_shortcodes'), 99);
        add_filter('adflow_health_checks', array($this, 'double_ads_check'));
    }

    /*
    |--------------------------------------------------------------------------
    | Sources
    |--------------------------------------------------------------------------
    */

    /**
     * Supported plugins.
     *
     * @return array key => label, plugin file, detected counts
     * @since 1.4.0
     */
    public static function sources()
    {
        return array(
            'advads' => array(
                'label' => 'Advanced Ads',
                'plugin' => 'advanced-ads/advanced-ads.php',
                'shortcodes' => '[the_ad id=""], [the_ad_group id=""]',
            ),
            'adinserter' => array(
                'label' => 'Ad Inserter',
                'plugin' => 'ad-inserter/ad-inserter.php',
                'shortcodes' => '[adinserter block=""]',
            ),
            'adrotate' => array(
                'label' => 'AdRotate',
                'plugin' => 'adrotate/adrotate.php',
                'shortcodes' => '[adrotate banner=""], [adrotate group=""]',
            ),
            'quads' => array(
                'label' => 'WP QUADS',
                'plugin' => 'quick-adsense-reloaded/quick-adsense-reloaded.php',
                'shortcodes' => '[quads id=""]',
            ),
        );
    }

    /**
     * What each source has to import (ads, groups, placements, stats rows).
     *
     * @param string $source Source key.
     * @return array ads, groups, placements, stats
     * @since 1.4.0
     */
    public static function detect($source)
    {
        global $wpdb;

        $found = array('ads' => 0, 'groups' => 0, 'placements' => 0, 'stats' => 0);

        switch ($source) {
            case 'advads':
                $found['ads'] = self::count_posts('advanced_ads');
                $found['placements'] = self::count_posts('advanced_ads_plcmnt');
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter
                $found['groups'] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", 'advanced_ads_groups'));
                break;

            case 'adinserter':
                foreach (self::adinserter_blocks() as $block) {
                    $found['ads']++;
                    if (!empty($block['display_type'])) {
                        $found['placements']++;
                    }
                }
                break;

            case 'adrotate':
                if (self::table_exists($wpdb->prefix . 'adrotate')) {
                    // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
                    $found['ads'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}adrotate WHERE type NOT IN ('trash','empty','generator','a_empty')");
                    $found['groups'] = self::table_exists($wpdb->prefix . 'adrotate_groups') ? (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}adrotate_groups WHERE name != ''") : 0;
                    $found['stats'] = self::table_exists($wpdb->prefix . 'adrotate_stats') ? (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}adrotate_stats") : 0;
                    // phpcs:enable
                }
                break;

            case 'quads':
                $found['ads'] = self::count_posts('quads-ads');
                if (self::table_exists($wpdb->prefix . 'quads_impressions_desktop')) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
                    $found['stats'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}quads_impressions_desktop");
                }
                break;
        }

        return $found;
    }

    /**
     * Count posts of a type that are not trashed.
     *
     * @param string $type Post type.
     * @return int
     * @since 1.4.0
     */
    private static function count_posts($type)
    {
        global $wpdb;

        // Direct query: these post types are not registered while the other plugin is inactive.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ('trash','auto-draft','inherit')", $type));
    }

    /**
     * Whether a database table exists.
     *
     * @param string $table Table.
     * @return bool
     * @since 1.4.0
     */
    private static function table_exists($table)
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter
        return $table === $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
    }

    /**
     * Ad Inserter blocks that have code.
     *
     * @return array number => block settings
     * @since 1.4.0
     */
    private static function adinserter_blocks()
    {
        $raw = get_option('ad_inserter');

        if (is_string($raw) && 0 === strpos($raw, ':AI:')) {
            $decoded = base64_decode(substr($raw, 4), true); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Ad Inserter's storage format.
            $raw = false !== $decoded ? @unserialize($decoded, array('allowed_classes' => false)) : array(); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors
        }

        $blocks = array();

        for ($i = 1; $i <= 96; $i++) {
            if (!is_array($raw) || empty($raw[$i]) || !is_array($raw[$i])) {
                continue;
            }

            $block = wp_unslash($raw[$i]);

            if ('' !== trim(isset($block['code']) ? (string) $block['code'] : '')) {
                $blocks[$i] = $block;
            }
        }

        return $blocks;
    }

    /*
    |--------------------------------------------------------------------------
    | Import
    |--------------------------------------------------------------------------
    */

    /**
     * Handle the import form.
     *
     * @since 1.4.0
     */
    public function handle()
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to do this.', 'simple-google-adsense'));
        }

        check_admin_referer('adflow_migrate');

        $source = isset($_POST['source']) ? sanitize_key(wp_unslash($_POST['source'])) : '';
        $sources = self::sources();

        if (!isset($sources[$source])) {
            wp_safe_redirect(Simple_Google_Adsense_Admin::settings_url('migrate'));
            exit;
        }

        $options = array(
            'placements' => !empty($_POST['placements']),
            'stats' => !empty($_POST['stats']),
        );

        $report = $this->run($source, $options);

        update_option(self::REPORT_OPTION, $report, false);
        Simple_Google_Adsense_Ad_Units::purge_page_cache();

        wp_safe_redirect(add_query_arg('imported', '1', Simple_Google_Adsense_Admin::settings_url('migrate')));
        exit;
    }

    /**
     * Import one source.
     *
     * @param string $source Source key.
     * @param array $options placements (bool), stats (bool).
     * @return array Report: source, created, skipped, placements, notes, stats.
     * @since 1.4.0
     */
    public function run($source, $options = array())
    {
        $options = wp_parse_args($options, array('placements' => true, 'stats' => true));
        $this->log = array(
            'source' => $source,
            'time' => time(),
            'created' => array(),
            'existing' => 0,
            'skipped' => array(),
            'placements' => array(),
            'notes' => array(),
            'stats' => 0,
        );

        // Imports can be large; never time out half way (the map makes a rerun safe anyway).
        if (function_exists('set_time_limit')) {
            @set_time_limit(300); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        }

        $method = 'import_' . $source;
        $this->$method($options);

        foreach ($this->log['created'] as $row) {
            if (Simple_Google_Adsense_Ad_Units::is_own_ad($row['type'])) {
                $this->note(__('Imported banners and code show without a "Sponsored" label, as before. You can add one per ad under Delivery.', 'simple-google-adsense'));
                break;
            }
        }

        /**
         * Fires after ads were imported from another plugin.
         *
         * @param array $report
         * @since 1.4.0
         */
        do_action('adflow_imported', $this->log);

        return $this->log;
    }

    /**
     * Advanced Ads: ads, groups, placements.
     *
     * @param array $options Options.
     * @since 1.4.0
     */
    private function import_advads($options)
    {
        $adsense = get_option('advanced-ads-adsense');
        $pub = is_array($adsense) && !empty($adsense['adsense-id']) ? (string) $adsense['adsense-id'] : '';
        $general = get_option('advanced-ads');
        $new_tab = is_array($general) && !empty($general['target-blank']);

        $posts = get_posts(array(
            'post_type' => 'advanced_ads',
            'post_status' => array('publish', 'future', 'draft', 'pending', 'private', 'advanced_ads_expired'),
            'numberposts' => -1,
            'orderby' => 'ID',
            'order' => 'ASC',
        ));

        $group_ads = array();

        foreach ($posts as $post) {
            $meta = get_post_meta($post->ID, 'advanced_ads_ad_options', true);
            $meta = is_array($meta) ? $meta : array();
            $type = isset($meta['type']) ? $meta['type'] : 'plain';
            $key = 'ad:' . $post->ID;

            if ('group' === $type) {
                $group_ads[$post->ID] = isset($meta['group_id']) ? (int) $meta['group_id'] : 0;
                continue;
            }

            $data = array();

            switch ($type) {
                case 'adsense':
                    $json = json_decode((string) $post->post_content, true);
                    $json = is_array($json) ? $json : array();
                    $types = array('in-article' => 'inarticle', 'in-feed' => 'infeed', 'matched-content' => 'multiplex');
                    $unit = isset($json['unitType']) ? $json['unitType'] : 'responsive';

                    if (in_array($unit, array('link', 'link-responsive'), true)) {
                        $this->skip('advads', $post->post_title, __('AdSense link units were retired by Google.', 'simple-google-adsense'));
                        continue 2;
                    }

                    $data = $this->adsense_unit(
                        isset($json['slotId']) ? $json['slotId'] : '',
                        !empty($json['pubId']) ? $json['pubId'] : $pub,
                        isset($types[$unit]) ? $types[$unit] : 'display',
                        isset($json['layout_key']) ? $json['layout_key'] : '',
                        'normal' === $unit ? 'rectangle' : 'auto'
                    );
                    break;

                case 'image':
                    $data = array(
                        'type' => 'image',
                        'image_id' => isset($meta['image_id']) ? absint($meta['image_id']) : 0,
                        'url' => isset($meta['url']) ? esc_url_raw($meta['url']) : '',
                        'new_tab' => $new_tab,
                    );
                    break;

                case 'plain':
                case 'content':
                    $code = 'content' === $type ? wpautop((string) $post->post_content) : (string) $post->post_content;
                    $data = $this->from_code($code, $post->post_title, 'advads');

                    if (!empty($meta['allow_php']) && false !== strpos($code, '<?')) {
                        $this->note(sprintf(
                            /* translators: %s: ad name */
                            __('"%s" contained PHP, which AdFlow never runs. Review the imported code.', 'simple-google-adsense'),
                            $post->post_title
                        ));
                    }
                    break;

                default:
                    /* translators: %s: ad type */
                    $this->skip('advads', $post->post_title, sprintf(__('The ad type "%s" has no AdFlow equivalent.', 'simple-google-adsense'), $type));
                    continue 2;
            }

            if (!$data) {
                continue;
            }

            if (Simple_Google_Adsense_Ad_Units::is_own_ad($data['type'])) {
                if ('future' === $post->post_status) {
                    $data['start'] = get_post_datetime($post) ? get_post_datetime($post)->format('Y-m-d\TH:i') : '';
                }
                if (!empty($meta['expiry_date'])) {
                    $data['end'] = wp_date('Y-m-d\TH:i', (int) $meta['expiry_date']);
                }
            }

            $status = in_array($post->post_status, array('draft', 'pending'), true) ? 'draft' : 'publish';

            $this->create('advads', $key, $post->post_title, $data, $status);
        }

        // Groups (taxonomy) become rotation groups.
        $terms = get_terms(array('taxonomy' => 'advanced_ads_groups', 'hide_empty' => false));

        if (is_wp_error($terms) || !$terms) {
            // Taxonomy is not registered while Advanced Ads is inactive: read it directly.
            $terms = $this->raw_terms('advanced_ads_groups');
        }

        foreach ($terms as $term) {
            $opts = get_term_meta($term->term_id, 'advanced_ads_group_options', true);
            $weights = is_array($opts) && !empty($opts['ad_weights']) && is_array($opts['ad_weights']) ? $opts['ad_weights'] : array();
            $group_type = get_term_meta($term->term_id, '_advads_group_type', true);

            $members = array();
            foreach ($weights as $ad_id => $weight) {
                $members[] = array('src' => 'ad:' . (int) $ad_id, 'weight' => (int) $weight);
            }

            if (in_array($group_type, array('slider', 'grid'), true)) {
                /* translators: %s: group name */
                $this->note(sprintf(__('"%s" was a slider/grid group in Advanced Ads; AdFlow shows one of its ads at a time.', 'simple-google-adsense'), $term->name));
            }

            $this->create_group('advads', 'group:' . $term->term_id, $term->name, $members, 'ordered' === $group_type ? 'ordered' : 'weighted');
        }

        // "Group" ads point at a group: reuse the group's unit.
        foreach ($group_ads as $ad_id => $group_id) {
            $target = self::mapped('advads', 'group:' . $group_id);
            if ($target) {
                self::remember('advads', 'ad:' . $ad_id, $target);
            }
        }

        if (empty($options['placements'])) {
            return;
        }

        $placements = get_posts(array(
            'post_type' => 'advanced_ads_plcmnt',
            'post_status' => 'publish',
            'numberposts' => -1,
        ));

        foreach ($placements as $placement) {
            $type = get_post_meta($placement->ID, 'type', true);
            $item = (string) get_post_meta($placement->ID, 'item', true);
            $opts = get_post_meta($placement->ID, 'options', true);
            $opts = is_array($opts) ? $opts : array();
            $target = $item ? self::mapped('advads', str_replace('_', ':', $item)) : 0;
            $key = '';
            $extra = array();

            switch ($type) {
                case 'post_top':
                    $key = 'before_content';
                    break;
                case 'post_bottom':
                    $key = 'after_content';
                    break;
                case 'post_content':
                    $index = isset($opts['index']) ? max(1, (int) $opts['index']) : 1;
                    $position = isset($opts['position']) ? $opts['position'] : 'after';
                    if ('before' === $position && 1 === $index) {
                        $key = 'before_content';
                    } else {
                        $key = 'after_paragraph';
                        $extra['paragraph'] = 'before' === $position ? $index - 1 : $index;
                    }
                    if (!empty($opts['tag']) && 'p' !== $opts['tag']) {
                        /* translators: %s: placement name */
                        $this->note(sprintf(__('"%s" counted headings or other elements; AdFlow counts paragraphs.', 'simple-google-adsense'), $placement->post_title));
                    }
                    break;
            }

            $this->place($placement->post_title, $key, $target, $extra, !empty($opts['display']) || !empty($opts['visitors']));
        }
    }

    /**
     * Ad Inserter: blocks and their automatic insertion.
     *
     * @param array $options Options.
     * @since 1.4.0
     */
    private function import_adinserter($options)
    {
        // Insertion types => AdFlow placement.
        $insertion = array(
            3 => 'before_content',
            4 => 'after_content',
            5 => 'after_paragraph',
            6 => 'after_paragraph',
            9 => 'between_posts',
            10 => 'before_comments',
        );

        foreach (self::adinserter_blocks() as $number => $block) {
            /* translators: %d: block number */
            $name = !empty($block['name']) ? $block['name'] : sprintf(__('Ad Inserter block %d', 'simple-google-adsense'), $number);
            $code = (string) $block['code'];

            if (false !== strpos($code, '|rotate|')) {
                $this->import_adinserter_rotation($number, $name, $code, $block);
            } else {
                $data = $this->from_code($code, $name, 'adinserter');

                if (!$data) {
                    continue;
                }

                if (Simple_Google_Adsense_Ad_Units::is_own_ad($data['type']) && isset($block['scheduling']) && 2 === (int) $block['scheduling']) {
                    $data['start'] = self::date_only(isset($block['start_date']) ? $block['start_date'] : '');
                    $data['end'] = self::date_only(isset($block['end_date']) ? $block['end_date'] : '');
                }

                $this->create('adinserter', 'block:' . $number, $name, $data, empty($block['disable_insertion']) ? 'publish' : 'draft');
            }

            $display = isset($block['display_type']) ? (int) $block['display_type'] : 0;

            if (empty($options['placements']) || !$display || !empty($block['disable_insertion'])) {
                continue;
            }

            $key = isset($insertion[$display]) ? $insertion[$display] : '';
            $extra = array();
            $post_types = array();

            if (!isset($block['display_on_posts']) || !empty($block['display_on_posts'])) {
                $post_types[] = 'post';
            }
            if (!empty($block['display_on_pages'])) {
                $post_types[] = 'page';
            }
            if ($post_types) {
                $extra['post_types'] = $post_types;
            }

            if ('after_paragraph' === $key) {
                $paragraph = isset($block['paragraph_number']) ? trim((string) $block['paragraph_number']) : '1';

                if (!ctype_digit($paragraph) || '0' === $paragraph) {
                    /* translators: %s: block name */
                    $this->note(sprintf(__('"%s" used a paragraph list, percentage or random position; AdFlow placed it after one paragraph - adjust it in Placements.', 'simple-google-adsense'), $name));
                    $paragraph = '2';
                }

                $paragraph = (int) $paragraph;

                if (5 === $display) {
                    // "Before paragraph N" is "after paragraph N-1".
                    if ($paragraph <= 1) {
                        $key = 'before_content';
                    }
                    $paragraph--;
                }

                $extra['paragraph'] = max(1, $paragraph);
            }

            $this->place($name, $key, self::mapped('adinserter', 'block:' . $number), $extra, self::adinserter_has_filters($block));
        }
    }

    /**
     * An Ad Inserter block with |rotate| becomes one ad per option plus a group.
     *
     * @param int $number Block number.
     * @param string $name Name.
     * @param string $code Code.
     * @param array $block Block settings.
     * @since 1.4.0
     */
    private function import_adinserter_rotation($number, $name, $code, $block)
    {
        $members = array();

        foreach (preg_split('/\|rotate\|/', $code) as $i => $part) {
            $part = trim(preg_replace('/^\{[^}]*\}/', '', trim($part)));

            if ('' === $part) {
                continue;
            }

            $data = $this->from_code($part, $name, 'adinserter');

            if ($data) {
                /* translators: 1: block name, 2: option number */
                $this->create('adinserter', 'block:' . $number . ':' . $i, sprintf(__('%1$s - option %2$d', 'simple-google-adsense'), $name, $i + 1), $data, 'publish');
                $members[] = array('src' => 'block:' . $number . ':' . $i, 'weight' => 1);
            }
        }

        $this->create_group('adinserter', 'block:' . $number, $name, $members, 'weighted', empty($block['disable_insertion']) ? 'publish' : 'draft');
    }

    /**
     * Whether an Ad Inserter block used filters AdFlow does not import.
     *
     * @param array $block Block.
     * @return bool
     * @since 1.4.0
     */
    private static function adinserter_has_filters($block)
    {
        foreach (array('category_list', 'tag_list', 'taxonomy_list', 'id_list', 'url_list', 'url_parameter_list', 'cookie_list', 'country_list', 'ip_address_list') as $list) {
            if (!empty($block[$list])) {
                return true;
            }
        }

        return !empty($block['display_on_homepage']) || !empty($block['display_on_category_pages']) || !empty($block['display_on_archive_pages']);
    }

    /**
     * AdRotate: adverts, schedules, groups, statistics.
     *
     * @param array $options Options.
     * @since 1.4.0
     */
    private function import_adrotate($options)
    {
        global $wpdb;

        $p = $wpdb->prefix;

        if (!self::table_exists($p . 'adrotate')) {
            return;
        }

        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $ads = $wpdb->get_results("SELECT * FROM {$p}adrotate WHERE type NOT IN ('trash','empty','generator','a_empty') ORDER BY id ASC", ARRAY_A);
        $has_schedules = self::table_exists($p . 'adrotate_schedule') && self::table_exists($p . 'adrotate_linkmeta');
        $created = array();

        foreach ((array) $ads as $ad) {
            $name = '' !== trim($ad['title']) ? $ad['title'] : 'AdRotate #' . $ad['id'];
            $code = html_entity_decode((string) $ad['bannercode'], ENT_QUOTES);
            $image = (string) $ad['image'];

            if ('' !== $image) {
                $code = str_replace(array('%asset%', '%image%'), $image, $code);
            }

            $data = $this->from_code($code, $name, 'adrotate');

            if (!$data) {
                continue;
            }

            if (Simple_Google_Adsense_Ad_Units::is_own_ad($data['type'])) {
                $data['track'] = 'Y' === $ad['tracker'] || 'image' === $data['type'];

                if ($has_schedules) {
                    $window = $wpdb->get_row($wpdb->prepare(
                        "SELECT MIN(s.starttime) AS start, MAX(s.stoptime) AS stop FROM {$p}adrotate_schedule s INNER JOIN {$p}adrotate_linkmeta l ON l.schedule = s.id WHERE l.ad = %d AND l.group = 0 AND l.user = 0",
                        $ad['id']
                    ), ARRAY_A);

                    // AdRotate stores local time as a Unix-like number: format it without converting.
                    if ($window && $window['start'] > current_time('timestamp')) { // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
                        $data['start'] = gmdate('Y-m-d\TH:i', (int) $window['start']);
                    }
                    if ($window && $window['stop'] && $window['stop'] < 2000000000) {
                        $data['end'] = gmdate('Y-m-d\TH:i', (int) $window['stop']);
                    }
                }
            }

            $status = in_array($ad['type'], array('active', 'expired', '2days', '7days', 'unpaid'), true) ? 'publish' : 'draft';

            if ($this->create('adrotate', 'ad:' . $ad['id'], $name, $data, $status)) {
                $created[(int) $ad['id']] = true;
            }
        }

        // Groups.
        $groups = self::table_exists($p . 'adrotate_groups') ? $wpdb->get_results("SELECT * FROM {$p}adrotate_groups WHERE name != '' ORDER BY id ASC", ARRAY_A) : array();

        foreach ((array) $groups as $group) {
            $member_ids = $wpdb->get_col($wpdb->prepare("SELECT ad FROM {$p}adrotate_linkmeta WHERE `group` = %d AND user = 0", $group['id']));
            $members = array();

            foreach ($member_ids as $member_id) {
                $members[] = array('src' => 'ad:' . (int) $member_id, 'weight' => 1);
            }

            $this->create_group('adrotate', 'group:' . $group['id'], $group['name'], $members, 'weighted');

            if (empty($options['placements'])) {
                continue;
            }

            // Automatic insertion into posts (cat_*) or pages (page_*): 1 before, 2 after, 4 inside.
            foreach (array('cat' => 'post', 'page' => 'page') as $prefix => $post_type) {
                $where = (int) $group[$prefix . '_loc'];

                if (!$where || '' === trim((string) $group[$prefix])) {
                    continue;
                }

                $key = array(1 => 'before_content', 2 => 'after_content', 3 => 'before_content', 4 => 'after_paragraph');
                $extra = array('post_types' => array($post_type));

                if (4 === $where) {
                    $extra['paragraph'] = max(1, (int) $group[$prefix . '_par']);
                }

                if (3 === $where) {
                    /* translators: %s: group name */
                    $this->note(sprintf(__('"%s" was shown before and after the content; AdFlow placed it before the content - add it to "After content" too if you want both.', 'simple-google-adsense'), $group['name']));
                }

                $this->place($group['name'], isset($key[$where]) ? $key[$where] : '', self::mapped('adrotate', 'group:' . $group['id']), $extra, true);
            }
        }

        // Statistics of the ads created now (a rerun never doubles them).
        if (!empty($options['stats']) && $created && self::table_exists($p . 'adrotate_stats')) {
            $ids = array_map('intval', array_keys($created));
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT ad, thetime, SUM(impressions) AS i, SUM(clicks) AS c FROM {$p}adrotate_stats WHERE ad IN (" . implode(',', array_fill(0, count($ids), '%d')) . ') GROUP BY ad, thetime',
                    $ids
                ),
                ARRAY_A
            );

            foreach ((array) $rows as $row) {
                $this->add_stats(self::mapped('adrotate', 'ad:' . $row['ad']), gmdate('Y-m-d', (int) $row['thetime']), 0, (int) $row['i'], (int) $row['c']);
            }
        }
        // phpcs:enable
    }

    /**
     * WP QUADS (new mode): ads with their position, statistics.
     *
     * @param array $options Options.
     * @since 1.4.0
     */
    private function import_quads($options)
    {
        global $wpdb;

        $positions = array(
            'beginning_of_post' => 'before_content',
            'end_of_post' => 'after_content',
            'after_paragraph' => 'after_paragraph',
            'middle_of_post' => 'content_percent',
            'after_the_percentage' => 'content_percent',
        );
        $adsense_types = array('in_article_ads' => 'inarticle', 'in_feed_ads' => 'infeed', 'matched_content' => 'multiplex');

        $posts = get_posts(array(
            'post_type' => 'quads-ads',
            'post_status' => array('publish', 'draft'),
            'numberposts' => -1,
            'orderby' => 'ID',
            'order' => 'ASC',
        ));

        $created = array();

        // Post ID => "adN" from the settings mirror (older ads may lack the meta).
        $legacy = array();
        $settings = get_option('quads_settings');
        foreach (is_array($settings) && !empty($settings['ads']) && is_array($settings['ads']) ? $settings['ads'] : array() as $old_key => $old_ad) {
            if (is_array($old_ad) && !empty($old_ad['ad_id'])) {
                $legacy[(int) $old_ad['ad_id']] = (string) $old_key;
            }
        }

        foreach ($posts as $post) {
            $m = function ($key, $default = '') use ($post) {
                $value = get_post_meta($post->ID, $key, true);
                return '' === $value || null === $value ? $default : $value;
            };

            $type = $m('ad_type');
            $name = '' !== $post->post_title ? $post->post_title : $m('label', 'WP QUADS #' . $post->ID);
            $data = array();

            switch ($type) {
                case 'adsense':
                    $adsense_type = $m('adsense_ad_type', 'display_ads');

                    if (in_array($adsense_type, array('adsense_auto_ads', 'adsense_sticky_ads'), true)) {
                        $this->skip('quads', $name, __('Turn on Auto ads in AdFlow → Settings instead.', 'simple-google-adsense'));
                        continue 2;
                    }

                    $data = $this->adsense_unit(
                        $m('g_data_ad_slot'),
                        $m('g_data_ad_client'),
                        isset($adsense_types[$adsense_type]) ? $adsense_types[$adsense_type] : 'display',
                        $m('data_layout_key'),
                        'normal' === $m('adsense_type') ? 'rectangle' : 'auto'
                    );
                    break;

                case 'ad_image':
                    $src = (string) $m('image_src');
                    $image_id = absint($m('image_src_id', 0));
                    $image_id = $image_id ? $image_id : attachment_url_to_postid($src);

                    if ($image_id) {
                        $data = array(
                            'type' => 'image',
                            'image_id' => $image_id,
                            'url' => esc_url_raw((string) $m('image_redirect_url')),
                            'new_tab' => true,
                            'nofollow' => (bool) $m('add_url_nofollow', false),
                        );
                    } else {
                        $data = $this->from_code('<a href="' . esc_url($m('image_redirect_url')) . '" target="_blank"><img src="' . esc_url($src) . '" alt=""></a>', $name, 'quads');
                    }
                    break;

                case 'plain_text':
                case 'double_click':
                case 'yandex':
                case 'mgid':
                case 'media_net':
                case 'propeller':
                    $data = $this->from_code((string) $m('code'), $name, 'quads');
                    break;

                default:
                    /* translators: %s: ad type */
                    $this->skip('quads', $name, sprintf(__('The WP QUADS type "%s" has no AdFlow equivalent yet.', 'simple-google-adsense'), $type));
                    continue 2;
            }

            if (!$data) {
                continue;
            }

            if ($this->create('quads', 'ad:' . $post->ID, $name, $data, 'publish' === $post->post_status ? 'publish' : 'draft')) {
                $created[$post->ID] = true;
            }

            // [quads id=N] uses the number of the legacy "adN" key.
            $old = (string) $m('quads_ad_old_id', isset($legacy[$post->ID]) ? $legacy[$post->ID] : '');
            if (preg_match('/^ad(\d+)$/', $old, $match)) {
                self::remember('quads', 'short:' . $match[1], self::mapped('quads', 'ad:' . $post->ID));
            }

            if (empty($options['placements']) || 'publish' !== $post->post_status) {
                continue;
            }

            $position = $m('position', 'beginning_of_post');

            if ('ad_shortcode' === $position) {
                continue;
            }

            $extra = array();
            if ('after_paragraph' === $position) {
                $extra['paragraph'] = max(1, (int) $m('paragraph_number', 1));
            } elseif ('middle_of_post' === $position) {
                $extra['percent'] = 50;
            } elseif ('after_the_percentage' === $position) {
                $extra['percent'] = max(10, min(90, (int) $m('after_the_percentage_value', 50)));
            }

            $this->place($name, isset($positions[$position]) ? $positions[$position] : '', self::mapped('quads', 'ad:' . $post->ID), $extra, false);
        }

        // Statistics (new tables, one row per ad, day and device).
        if (!empty($options['stats']) && $created) {
            $ids = implode(',', array_map('intval', array_keys($created)));

            foreach (array(0 => 'desktop', 1 => 'mobile') as $device => $suffix) {
                $days = array();

                foreach (array('impressions' => 'stats_impressions', 'clicks' => 'stats_clicks') as $metric => $column) {
                    $table = $wpdb->prefix . 'quads_' . $metric . '_' . $suffix;

                    if (!self::table_exists($table)) {
                        continue;
                    }

                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
                    foreach ((array) $wpdb->get_results("SELECT ad_id, stats_date, SUM({$column}) AS n FROM {$table} WHERE ad_id IN ({$ids}) GROUP BY ad_id, stats_date", ARRAY_A) as $row) {
                        $k = $row['ad_id'] . '|' . gmdate('Y-m-d', (int) $row['stats_date']);
                        $days[$k][$metric] = (int) $row['n'];
                    }
                }

                foreach ($days as $k => $counts) {
                    list($ad_id, $day) = explode('|', $k);
                    $this->add_stats(self::mapped('quads', 'ad:' . $ad_id), $day, $device, isset($counts['impressions']) ? $counts['impressions'] : 0, isset($counts['clicks']) ? $counts['clicks'] : 0);
                }
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Mapping helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Turn ad code into the best-fitting unit.
     *
     * AdSense code for this site's publisher ID becomes a native AdSense unit,
     * a plain linked banner from the Media Library becomes an image ad, and
     * everything else is kept as custom code.
     *
     * @param string $code Code.
     * @param string $name Ad name (for the report).
     * @param string $source Source key.
     * @return array|null Unit data, or null when skipped.
     * @since 1.4.0
     */
    private function from_code($code, $name, $source)
    {
        $code = trim((string) $code);

        if ('' === $code) {
            $this->skip($source, $name, __('The ad has no code.', 'simple-google-adsense'));
            return null;
        }

        // AdSense (one <ins> only).
        if (1 === preg_match_all('/<ins[^>]+class=["\'][^"\']*adsbygoogle[^>]*>/i', $code, $all) && preg_match('/<ins[^>]+class=["\'][^"\']*adsbygoogle[^>]*>/i', $code, $ins)) {
            $attr = function ($name) use ($ins) {
                return preg_match('/data-' . $name . '=["\']([^"\']*)["\']/i', $ins[0], $m) ? $m[1] : '';
            };
            $format = $attr('ad-format');
            $layout = $attr('ad-layout');
            $type = 'display';

            if ('in-article' === $layout) {
                $type = 'inarticle';
            } elseif ('autorelaxed' === $format) {
                $type = 'multiplex';
            } elseif ('fluid' === $format && '' !== $attr('ad-layout-key')) {
                $type = 'infeed';
            }

            if ('' !== $attr('ad-slot')) {
                $unit = $this->adsense_unit($attr('ad-slot'), $attr('ad-client'), $type, $attr('ad-layout-key'), in_array($format, array('rectangle', 'horizontal', 'vertical'), true) ? $format : 'auto', true);

                if ($unit) {
                    return $unit;
                }
            }
        }

        // A single linked image from the Media Library.
        $stripped = trim(preg_replace('/\s+/', ' ', $code));
        if (preg_match('#^<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>\s*<img\s[^>]*src=["\']([^"\']+)["\'][^>]*/?>\s*</a>$#i', $stripped, $m)) {
            $image_id = attachment_url_to_postid(preg_replace('/-\d+x\d+(?=\.\w+$)/', '', $m[2]));

            if ($image_id) {
                return array(
                    'type' => 'image',
                    'image_id' => $image_id,
                    'url' => esc_url_raw(html_entity_decode($m[1])),
                    'new_tab' => (bool) preg_match('/target=["\']_blank/i', $stripped),
                    'nofollow' => (bool) preg_match('/rel=["\'][^"\']*nofollow/i', $stripped),
                    'alt' => preg_match('/alt=["\']([^"\']*)["\']/i', $stripped, $alt) ? sanitize_text_field($alt[1]) : '',
                );
            }
        }

        if (!current_user_can('unfiltered_html')) {
            $this->skip($source, $name, __('Custom code can only be imported by a user allowed to post unfiltered HTML.', 'simple-google-adsense'));
            return null;
        }

        return array('type' => 'custom', 'code' => $code, 'track' => false);
    }

    /**
     * Native AdSense unit data.
     *
     * AdFlow uses one publisher ID per site. The first imported one becomes
     * it; code for a different publisher is kept as custom code.
     *
     * @param string $slot Slot ID.
     * @param string $client ca-pub-… / pub-….
     * @param string $type AdFlow type.
     * @param string $layout_key In-feed layout key.
     * @param string $format Format.
     * @param bool $from_code Whether the caller can fall back to custom code.
     * @return array|null
     * @since 1.4.0
     */
    private function adsense_unit($slot, $client, $type, $layout_key = '', $format = 'auto', $from_code = false)
    {
        $slot = preg_replace('/[^0-9]/', '', (string) $slot);
        $pub = preg_replace('/^ca-/i', '', trim((string) $client));
        $current = Simple_Google_Adsense_Settings::get_publisher_id();

        if ('' !== $pub && '' === $current && preg_match('/^pub-\d{10,20}$/', $pub)) {
            // Start from the effective settings (defaults included) and skip the
            // settings-form sanitizer, which would read missing checkboxes as "off".
            $settings = Simple_Google_Adsense_Settings::get_all();
            $settings['publisher_id'] = $pub;
            remove_all_filters('sanitize_option_' . Simple_Google_Adsense_Settings::OPTION_NAME);
            update_option(Simple_Google_Adsense_Settings::OPTION_NAME, $settings);
            /* translators: %s: publisher ID */
            $this->note(sprintf(__('Your AdSense publisher ID %s was imported into Settings.', 'simple-google-adsense'), $pub));
            $current = $pub;
        }

        if ('' !== $pub && strtolower($pub) !== strtolower($current)) {
            if ($from_code) {
                return null; // The caller keeps the original code.
            }

            return array(
                'type' => 'custom',
                'code' => sprintf(
                    '<ins class="adsbygoogle" style="display:block" data-ad-client="ca-%1$s" data-ad-slot="%2$s" data-ad-format="auto" data-full-width-responsive="true"></ins><script>(adsbygoogle = window.adsbygoogle || []).push({});</script>',
                    esc_attr($pub),
                    esc_attr($slot)
                ),
                'track' => false,
            );
        }

        return array(
            'type' => $type,
            'slot' => $slot,
            'format' => $format,
            'layout_key' => sanitize_text_field((string) $layout_key),
            'full_width_responsive' => true,
        );
    }

    /**
     * Create an AdFlow unit, unless this source item was imported before.
     *
     * @param string $source Source key.
     * @param string $key Source item key.
     * @param string $title Title.
     * @param array $data Unit data.
     * @param string $status publish|draft.
     * @return int New unit ID, 0 when it already existed or failed.
     * @since 1.4.0
     */
    private function create($source, $key, $title, $data, $status = 'publish')
    {
        if (self::mapped($source, $key)) {
            $this->log['existing']++;
            return 0;
        }

        $id = wp_insert_post(array(
            'post_type' => Simple_Google_Adsense_Ad_Units::POST_TYPE,
            'post_status' => 'publish' === $status ? 'publish' : 'draft',
            'post_title' => wp_strip_all_tags((string) $title),
            'post_author' => get_current_user_id(),
        ), true);

        if (is_wp_error($id)) {
            $this->skip($source, $title, $id->get_error_message());
            return 0;
        }

        // Keep the ad looking exactly as before: no label unless the source had one.
        $data = array_merge(Simple_Google_Adsense_Ad_Units::defaults(), array('disclosure' => 'none'), $data);

        // Imported banners should report from day one.
        if (Simple_Google_Adsense_Ad_Units::is_own_ad($data['type']) && !isset($data['track'])) {
            $data['track'] = true;
        }

        update_post_meta($id, Simple_Google_Adsense_Ad_Units::META_KEY, $data);
        update_post_meta($id, self::ORIGIN_META, $source . ':' . $key);

        self::remember($source, $key, $id);

        $this->log['created'][] = array('id' => (int) $id, 'title' => $title, 'type' => $data['type'], 'status' => $status);

        return (int) $id;
    }

    /**
     * Create a rotation group from source member keys.
     *
     * @param string $source Source.
     * @param string $key Group key.
     * @param string $title Title.
     * @param array $members Rows of src, weight.
     * @param string $rotation weighted|ordered.
     * @param string $status Status.
     * @since 1.4.0
     */
    private function create_group($source, $key, $title, $members, $rotation, $status = 'publish')
    {
        $clean = array();

        foreach ($members as $member) {
            $id = self::mapped($source, $member['src']);

            if ($id && !isset($clean[$id])) {
                $clean[$id] = array('id' => $id, 'weight' => max(1, min(100, (int) $member['weight'])));
            }
        }

        if (!$clean) {
            /* translators: %s: group name */
            $this->note(sprintf(__('The group "%s" has no importable ads and was skipped.', 'simple-google-adsense'), $title));
            return;
        }

        if (1 === count($clean)) {
            // A group of one is just that ad.
            self::remember($source, $key, key($clean));
            return;
        }

        $this->create($source, $key, $title, array('type' => 'group', 'members' => array_values($clean), 'rotation' => $rotation), $status);
    }

    /**
     * Put a unit into an AdFlow placement when that placement is still free.
     *
     * @param string $name Source placement name (for the report).
     * @param string $key AdFlow placement key ('' = unsupported).
     * @param int $unit_id Unit ID.
     * @param array $extra paragraph, percent, post_types.
     * @param bool $had_conditions Whether the source used display conditions.
     * @since 1.4.0
     */
    private function place($name, $key, $unit_id, $extra, $had_conditions)
    {
        $types = Simple_Google_Adsense_Placements::get_types();

        if (!$unit_id) {
            return;
        }

        $row = array('name' => $name, 'unit' => (int) $unit_id, 'placement' => $key, 'result' => '');

        if ('' === $key) {
            $row['result'] = 'manual';
        } elseif (!isset($types[$key]) || !empty($types[$key]['pro'])) {
            $row['result'] = 'pro';
        } else {
            $stored = get_option(Simple_Google_Adsense_Placements::OPTION_NAME);
            $stored = is_array($stored) ? $stored : array();
            $current = isset($stored[$key]) && is_array($stored[$key]) ? $stored[$key] : array();

            if (!empty($current['enabled']) && !empty($current['ad_id'])) {
                $row['result'] = (int) $current['ad_id'] === (int) $unit_id ? 'done' : 'taken';
            } else {
                $stored[$key] = array_merge(Simple_Google_Adsense_Placements::placement_defaults(), $current, array('enabled' => true, 'ad_id' => (int) $unit_id), $extra);
                $stored[$key]['post_types'] = isset($stored[$key]['post_types']) ? array_values((array) $stored[$key]['post_types']) : array('post');

                // Save without the settings-form sanitizer (it reads $_POST-shaped input).
                remove_all_filters('sanitize_option_' . Simple_Google_Adsense_Placements::OPTION_NAME);
                update_option(Simple_Google_Adsense_Placements::OPTION_NAME, $stored);
                $row['result'] = 'done';
            }
        }

        if ('done' === $row['result'] && $had_conditions) {
            $row['result'] = 'conditions';
        }

        $this->log['placements'][] = $row;
    }

    /**
     * Add imported statistics.
     *
     * @param int $ad_id AdFlow unit ID.
     * @param string $day Y-m-d.
     * @param int $device 0 desktop, 1 mobile.
     * @param int $impressions Impressions.
     * @param int $clicks Clicks.
     * @since 1.4.0
     */
    private function add_stats($ad_id, $day, $device, $impressions, $clicks)
    {
        global $wpdb;

        if (!$ad_id || (!$impressions && !$clicks) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            return;
        }

        $table = Simple_Google_Adsense_Stats::table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table} (day, ad_id, placement, device, impressions, viewable, clicks) VALUES (%s, %d, 'import', %d, %d, 0, %d) ON DUPLICATE KEY UPDATE impressions = impressions + VALUES(impressions), clicks = clicks + VALUES(clicks)",
            $day,
            $ad_id,
            $device ? 1 : 0,
            max(0, $impressions),
            max(0, $clicks)
        ));

        $this->log['stats']++;
    }

    /**
     * Terms of a taxonomy that is not registered right now.
     *
     * @param string $taxonomy Taxonomy.
     * @return object[] term_id, name
     * @since 1.4.0
     */
    private function raw_terms($taxonomy)
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter
        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT t.term_id, t.name FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE tt.taxonomy = %s",
            $taxonomy
        ));
    }

    /**
     * Ad Inserter date (any strtotime format) to a date-only value.
     *
     * @param string $value Value.
     * @return string
     * @since 1.4.0
     */
    private static function date_only($value)
    {
        $value = trim((string) $value);

        if ('' === $value) {
            return '';
        }

        $date = date_create_immutable($value, wp_timezone());

        return $date ? $date->format('Y-m-d\TH:i') : '';
    }

    /**
     * Record a skipped item.
     *
     * @param string $source Source.
     * @param string $name Name.
     * @param string $reason Reason.
     * @since 1.4.0
     */
    private function skip($source, $name, $reason)
    {
        $this->log['skipped'][] = array('name' => $name, 'reason' => $reason);
    }

    /**
     * Record a note.
     *
     * @param string $note Note.
     * @since 1.4.0
     */
    private function note($note)
    {
        $this->log['notes'][] = $note;
    }

    /**
     * AdFlow unit ID of an imported item.
     *
     * @param string $source Source.
     * @param string $key Item key.
     * @return int
     * @since 1.4.0
     */
    public static function mapped($source, $key)
    {
        $map = get_option(self::MAP_OPTION);
        $id = isset($map[$source][$key]) ? (int) $map[$source][$key] : 0;

        // Forget units that were deleted since.
        return $id && Simple_Google_Adsense_Ad_Units::POST_TYPE === get_post_type($id) ? $id : 0;
    }

    /**
     * Remember an imported item.
     *
     * @param string $source Source.
     * @param string $key Item key.
     * @param int $id Unit ID.
     * @since 1.4.0
     */
    private static function remember($source, $key, $id)
    {
        if (!$id) {
            return;
        }

        $map = get_option(self::MAP_OPTION);
        $map = is_array($map) ? $map : array();
        $map[$source][$key] = (int) $id;

        update_option(self::MAP_OPTION, $map, true);
    }

    /*
    |--------------------------------------------------------------------------
    | Old shortcodes keep working
    |--------------------------------------------------------------------------
    */

    /**
     * Answer the other plugins' shortcodes once they are deactivated.
     *
     * @since 1.4.0
     */
    public function compat_shortcodes()
    {
        $map = get_option(self::MAP_OPTION);

        if (!is_array($map) || !$map) {
            return;
        }

        $codes = array(
            'advads' => array('the_ad', 'the_ad_group'),
            'adinserter' => array('adinserter'),
            'adrotate' => array('adrotate'),
            'quads' => array('quads'),
        );

        foreach ($codes as $source => $tags) {
            if (empty($map[$source])) {
                continue;
            }

            foreach ($tags as $tag) {
                if (!shortcode_exists($tag)) {
                    add_shortcode($tag, array($this, 'compat_shortcode'));
                }
            }
        }
    }

    /**
     * Render an old shortcode with the imported unit.
     *
     * @param array $atts Attributes.
     * @param string $content Content.
     * @param string $tag Shortcode tag.
     * @return string
     * @since 1.4.0
     */
    public function compat_shortcode($atts, $content = '', $tag = '')
    {
        $atts = is_array($atts) ? $atts : array();
        $id = 0;

        switch ($tag) {
            case 'the_ad':
                $id = isset($atts['id']) ? self::mapped('advads', 'ad:' . absint($atts['id'])) : 0;
                break;
            case 'the_ad_group':
                $id = isset($atts['id']) ? self::mapped('advads', 'group:' . absint($atts['id'])) : 0;
                break;
            case 'adinserter':
                $block = isset($atts['block']) ? absint($atts['block']) : 0;
                if (!$block && isset($atts['name'])) {
                    foreach (self::adinserter_blocks() as $number => $data) {
                        if (isset($data['name']) && $data['name'] === $atts['name']) {
                            $block = $number;
                            break;
                        }
                    }
                }
                $id = $block ? self::mapped('adinserter', 'block:' . $block) : 0;
                break;
            case 'adrotate':
                if (!empty($atts['group'])) {
                    $groups = array_map('absint', explode(',', (string) $atts['group']));
                    $id = self::mapped('adrotate', 'group:' . $groups[0]);
                } elseif (!empty($atts['banner'])) {
                    $id = self::mapped('adrotate', 'ad:' . absint($atts['banner']));
                }
                break;
            case 'quads':
                $id = isset($atts['id']) ? self::mapped('quads', 'short:' . absint($atts['id'])) : 0;
                break;
        }

        $unit = $id ? Simple_Google_Adsense_Ad_Units::get($id) : null;

        return $unit ? Simple_Google_Adsense_Manual_Ads::render_unit($unit, array('source' => 'shortcode')) : '';
    }

    /**
     * Warn when an imported plugin is still active (ads would show twice).
     *
     * @param array $checks Checks.
     * @return array
     * @since 1.4.0
     */
    public function double_ads_check($checks)
    {
        $map = get_option(self::MAP_OPTION);

        if (!is_array($map) || !$map || !function_exists('is_plugin_active')) {
            return $checks;
        }

        foreach (self::sources() as $source => $info) {
            if (!empty($map[$source]) && is_plugin_active($info['plugin'])) {
                $checks['migrate_' . $source] = array(
                    'status' => 'warning',
                    /* translators: %s: plugin name */
                    'label' => sprintf(__('%s is still active', 'simple-google-adsense'), $info['label']),
                    'message' => __('Its ads were imported into AdFlow. Deactivate it so ads are not shown twice; its shortcodes keep working through AdFlow.', 'simple-google-adsense'),
                    'action' => admin_url('plugins.php?plugin_status=active'),
                    'action_label' => __('Plugins', 'simple-google-adsense'),
                );
            }
        }

        return $checks;
    }

    /*
    |--------------------------------------------------------------------------
    | Screen
    |--------------------------------------------------------------------------
    */

    /**
     * Register the tab.
     *
     * @param array $tabs Tabs.
     * @return array
     * @since 1.4.0
     */
    public function register_tab($tabs)
    {
        $tabs['migrate'] = array(
            'label' => __('Switch to AdFlow', 'simple-google-adsense'),
            'callback' => array($this, 'render_tab'),
            'priority' => 75,
        );

        return $tabs;
    }

    /**
     * Render the tab.
     *
     * @since 1.4.0
     */
    public function render_tab()
    {
        $report = isset($_GET['imported']) ? get_option(self::REPORT_OPTION) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $map = get_option(self::MAP_OPTION);
        $any = false;

        if (is_array($report)) {
            $this->render_report($report);
        }

        Simple_Google_Adsense_Admin::panel_start(
            __('Switch from another ad plugin', 'simple-google-adsense'),
            __('Bring your ads, rotation groups, placements and statistics over in one click. The other plugin does not need to be active, nothing in it is changed, and importing again never creates duplicates.', 'simple-google-adsense')
        );
        ?>
        <table class="widefat striped adflow-table">
            <thead>
                <tr>
                    <th scope="col"><?php esc_html_e('Plugin', 'simple-google-adsense'); ?></th>
                    <th scope="col"><?php esc_html_e('Found', 'simple-google-adsense'); ?></th>
                    <th scope="col"><?php esc_html_e('Old shortcodes', 'simple-google-adsense'); ?></th>
                    <th scope="col"><span class="screen-reader-text"><?php esc_html_e('Action', 'simple-google-adsense'); ?></span></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (self::sources() as $key => $source) : ?>
                    <?php
                    $found = self::detect($key);
                    $has = $found['ads'] > 0;
                    $any = $any || $has;
                    $parts = array();
                    /* translators: %d: number of ads */
                    $parts[] = sprintf(_n('%d ad', '%d ads', $found['ads'], 'simple-google-adsense'), $found['ads']);
                    if ($found['groups']) {
                        /* translators: %d: number */
                        $parts[] = sprintf(_n('%d group', '%d groups', $found['groups'], 'simple-google-adsense'), $found['groups']);
                    }
                    if ($found['placements']) {
                        /* translators: %d: number */
                        $parts[] = sprintf(_n('%d placement', '%d placements', $found['placements'], 'simple-google-adsense'), $found['placements']);
                    }
                    if ($found['stats']) {
                        $parts[] = __('statistics', 'simple-google-adsense');
                    }
                    ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html($source['label']); ?></strong>
                            <?php if (!empty($map[$key])) : ?>
                                <span class="adflow-pill adflow-pill--ok"><?php esc_html_e('Imported', 'simple-google-adsense'); ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $has ? esc_html(implode(', ', $parts)) : '<span class="description">' . esc_html__('Nothing found', 'simple-google-adsense') . '</span>'; ?></td>
                        <td><code><?php echo esc_html($source['shortcodes']); ?></code></td>
                        <td>
                            <?php if ($has) : ?>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                    <input type="hidden" name="action" value="adflow_migrate">
                                    <input type="hidden" name="source" value="<?php echo esc_attr($key); ?>">
                                    <input type="hidden" name="placements" value="1">
                                    <?php if ($found['stats']) : ?>
                                        <input type="hidden" name="stats" value="1">
                                    <?php endif; ?>
                                    <?php wp_nonce_field('adflow_migrate'); ?>
                                    <button type="submit" class="button <?php echo empty($map[$key]) ? 'button-primary' : ''; ?>">
                                        <?php empty($map[$key]) ? esc_html_e('Import', 'simple-google-adsense') : esc_html_e('Import new items', 'simple-google-adsense'); ?>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php if (!$any) : ?>
            <p class="description"><?php esc_html_e('No ads from Advanced Ads, Ad Inserter, AdRotate or WP QUADS were found on this site.', 'simple-google-adsense'); ?></p>
        <?php endif; ?>
        <p class="description">
            <?php esc_html_e('How it maps: AdSense code for your publisher ID becomes a native AdSense unit, linked banners from your Media Library become image ads with tracking, and anything else is kept as custom code so it shows exactly as before. Placements only fill AdFlow placements that are still empty.', 'simple-google-adsense'); ?>
        </p>
        <?php
        Simple_Google_Adsense_Admin::panel_end();
    }

    /**
     * Render the result of the last import.
     *
     * @param array $report Report.
     * @since 1.4.0
     */
    private function render_report($report)
    {
        $sources = self::sources();
        $label = isset($sources[$report['source']]) ? $sources[$report['source']]['label'] : $report['source'];
        $types = Simple_Google_Adsense_Ad_Units::get_types();
        $placement_types = Simple_Google_Adsense_Placements::get_types();
        $results = array(
            'done' => array('ok', __('Set up', 'simple-google-adsense')),
            'conditions' => array('warning', __('Set up - check conditions', 'simple-google-adsense')),
            'taken' => array('warning', __('Already used - not changed', 'simple-google-adsense')),
            'pro' => array('info', __('Needs AdFlow Pro', 'simple-google-adsense')),
            'manual' => array('info', __('Use the shortcode, block or widget', 'simple-google-adsense')),
        );

        Simple_Google_Adsense_Admin::panel_start(
            /* translators: %s: plugin name */
            sprintf(__('Imported from %s', 'simple-google-adsense'), $label),
            sprintf(
                /* translators: 1: created, 2: already imported, 3: skipped, 4: statistics records */
                __('%1$d created, %2$d already imported, %3$d skipped, %4$d statistics records carried over.', 'simple-google-adsense'),
                count($report['created']),
                $report['existing'],
                count($report['skipped']),
                $report['stats']
            )
        );
        ?>
        <?php if ($report['created']) : ?>
            <table class="widefat striped adflow-table">
                <thead><tr><th scope="col"><?php esc_html_e('New ad unit', 'simple-google-adsense'); ?></th><th scope="col"><?php esc_html_e('Type', 'simple-google-adsense'); ?></th><th scope="col"><?php esc_html_e('Status', 'simple-google-adsense'); ?></th></tr></thead>
                <tbody>
                    <?php foreach ($report['created'] as $row) : ?>
                        <tr>
                            <td><a href="<?php echo esc_url((string) get_edit_post_link($row['id'])); ?>"><?php echo esc_html($row['title']); ?></a></td>
                            <td><?php echo esc_html(isset($types[$row['type']]) ? $types[$row['type']] : $row['type']); ?></td>
                            <td><?php echo 'publish' === $row['status'] ? esc_html__('Published', 'simple-google-adsense') : esc_html__('Draft (was paused)', 'simple-google-adsense'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php if ($report['placements']) : ?>
            <h3><?php esc_html_e('Placements', 'simple-google-adsense'); ?></h3>
            <table class="widefat striped adflow-table">
                <tbody>
                    <?php foreach ($report['placements'] as $row) : ?>
                        <?php $result = isset($results[$row['result']]) ? $results[$row['result']] : array('info', $row['result']); ?>
                        <tr>
                            <td><?php echo esc_html($row['name']); ?></td>
                            <td><?php echo esc_html(isset($placement_types[$row['placement']]) ? $placement_types[$row['placement']]['label'] : '—'); ?></td>
                            <td><span class="adflow-pill adflow-pill--<?php echo esc_attr($result[0]); ?>"><?php echo esc_html($result[1]); ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php if ($report['skipped'] || $report['notes']) : ?>
            <h3><?php esc_html_e('Worth a look', 'simple-google-adsense'); ?></h3>
            <ul class="ul-disc">
                <?php foreach ($report['skipped'] as $row) : ?>
                    <li><strong><?php echo esc_html($row['name']); ?></strong> — <?php echo esc_html($row['reason']); ?></li>
                <?php endforeach; ?>
                <?php foreach ($report['notes'] as $note) : ?>
                    <li><?php echo esc_html($note); ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <p>
            <a class="button button-primary" href="<?php echo esc_url(admin_url('edit.php?post_type=' . Simple_Google_Adsense_Ad_Units::POST_TYPE)); ?>"><?php esc_html_e('Review ad units', 'simple-google-adsense'); ?></a>
            <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=' . Simple_Google_Adsense_Placements::PAGE_SLUG)); ?>"><?php esc_html_e('Review placements', 'simple-google-adsense'); ?></a>
            <?php if (isset($sources[$report['source']]) && function_exists('is_plugin_active') && is_plugin_active($sources[$report['source']]['plugin'])) : ?>
                <span class="description">
                    <?php
                    /* translators: %s: plugin name */
                    echo esc_html(sprintf(__('Next: deactivate %s so ads are not shown twice. Its shortcodes will keep working through AdFlow.', 'simple-google-adsense'), $label));
                    ?>
                </span>
            <?php endif; ?>
        </p>
        <?php
        Simple_Google_Adsense_Admin::panel_end();
    }
}
