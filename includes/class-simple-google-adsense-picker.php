<?php
/**
 * Simple_Google_Adsense searchable picker for posts, pages and terms
 *
 * A keyboard-friendly combobox (no jQuery). It either fills an existing text
 * field of comma-separated IDs, which stays editable, or stores hidden inputs.
 *
 * @package Simple_Google_Adsense
 * @since   1.6.0
 */

defined('ABSPATH') || exit;

/**
 * Simple_Google_Adsense_Picker Class.
 *
 * @class Simple_Google_Adsense_Picker
 */
final class Simple_Google_Adsense_Picker
{

    /**
     * Nonce action of the search endpoint.
     */
    const NONCE = 'adflow_picker';

    /**
     * Number of pickers printed, for unique element IDs.
     *
     * @var int
     */
    private static $count = 0;

    /**
     * Register the search endpoint.
     *
     * @since 1.6.0
     */
    public static function init()
    {
        add_action('wp_ajax_adflow_picker', array(__CLASS__, 'ajax_search'));
    }

    /**
     * Sources a picker can search.
     *
     * @param string $source Source.
     * @return string The source, or 'post'.
     * @since 1.6.0
     */
    private static function source($source)
    {
        return in_array($source, array('post', 'category', 'post_tag'), true) ? $source : 'post';
    }

    /**
     * Name of one picked item.
     *
     * @param string $source Source.
     * @param int $id ID.
     * @return string '' when it does not exist.
     * @since 1.6.0
     */
    public static function item_label($source, $id)
    {
        if ('post' === $source) {
            $post = get_post($id);

            if (!$post || 'trash' === $post->post_status || wp_is_post_revision($post)) {
                return '';
            }

            $title = '' !== trim($post->post_title) ? $post->post_title : __('(no title)', 'simple-google-adsense');

            /* translators: 1: post title, 2: post ID */
            return sprintf(__('%1$s (%2$d)', 'simple-google-adsense'), $title, $id);
        }

        $term = get_term($id, $source);

        return $term instanceof WP_Term ? $term->name : '';
    }

    /**
     * Search results or labels of given IDs.
     *
     * @param string $source Source.
     * @param string $search Search text ('' with $ids).
     * @param int[] $ids IDs to look up.
     * @return array[] Each: id, label.
     * @since 1.6.0
     */
    public static function search($source, $search, $ids = array())
    {
        $source = self::source($source);
        $items = array();

        if ($ids) {
            foreach (array_slice($ids, 0, 100) as $id) {
                $items[] = array('id' => (int) $id, 'label' => self::item_label($source, (int) $id));
            }

            return $items;
        }

        if ('post' === $source) {
            $types = get_post_types(array('public' => true));
            unset($types['attachment']);
            $statuses = array('publish', 'future');

            if (current_user_can('read_private_posts')) {
                $statuses[] = 'private';
            }

            $query = array(
                'post_type' => array_values($types),
                'post_status' => $statuses,
                'posts_per_page' => 20,
                'orderby' => 'relevance',
                'no_found_rows' => true,
                'ignore_sticky_posts' => true,
                'suppress_filters' => false,
                's' => $search,
                'search_columns' => array('post_title'),
            );
            $found = get_posts($query);

            // A number also finds the post with that ID.
            if (ctype_digit($search) && ($post = get_post((int) $search)) && in_array($post->post_type, $types, true) && in_array($post->post_status, $statuses, true)) {
                array_unshift($found, $post);
            }

            $seen = array();
            foreach ($found as $post) {
                if (!isset($seen[$post->ID])) {
                    $seen[$post->ID] = true;
                    $type = get_post_type_object($post->post_type);
                    $items[] = array(
                        'id' => (int) $post->ID,
                        'label' => self::item_label('post', (int) $post->ID),
                        'meta' => $type ? $type->labels->singular_name : '',
                    );
                }
            }

            return $items;
        }

        $terms = get_terms(array('taxonomy' => $source, 'hide_empty' => false, 'number' => 20, 'search' => $search));

        if (!is_wp_error($terms)) {
            foreach ($terms as $term) {
                $items[] = array('id' => (int) $term->term_id, 'label' => $term->name);
            }
        }

        return $items;
    }

    /**
     * AJAX: search.
     *
     * @since 1.6.0
     */
    public static function ajax_search()
    {
        check_ajax_referer(self::NONCE, 'nonce');

        if (!current_user_can(Simple_Google_Adsense_Caps::MANAGE_ADS)) {
            wp_send_json_error(null, 403);
        }

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- checked above.
        $source = isset($_GET['source']) ? sanitize_key(wp_unslash($_GET['source'])) : 'post';
        $search = isset($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : '';
        $ids = isset($_GET['ids']) ? array_filter(wp_parse_id_list(sanitize_text_field(wp_unslash($_GET['ids'])))) : array();
        // phpcs:enable

        if ('' === $search && !$ids) {
            wp_send_json_success(array());
        }

        wp_send_json_success(self::search($source, $search, $ids));
    }

    /**
     * Print one chosen item.
     *
     * @param string $source Source.
     * @param int $id ID.
     * @param string $name Hidden input name ('' when the picker fills a text field).
     * @since 1.6.0
     */
    private static function render_chip($source, $id, $name)
    {
        $label = self::item_label($source, $id);
        /* translators: %d: post or term ID */
        $shown = '' !== $label ? $label : sprintf(__('No item with ID %d', 'simple-google-adsense'), $id);
        ?>
        <li class="adflow-picker__chip<?php echo '' === $label ? ' is-missing' : ''; ?>" data-id="<?php echo esc_attr($id); ?>">
            <span><?php echo esc_html($shown); ?></span>
            <button type="button" class="adflow-picker__remove" aria-label="<?php
                /* translators: %s: name of a selected post or term */
                echo esc_attr(sprintf(__('Remove %s', 'simple-google-adsense'), $shown));
            ?>" hidden>&times;</button>
            <?php if ('' !== $name) : ?>
                <input type="hidden" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($id); ?>">
            <?php endif; ?>
        </li>
        <?php
    }

    /**
     * Print a picker.
     *
     * @param array $args source (post|category|post_tag), label, placeholder, ids (int[]),
     *                    name (hidden input name for array storage) or target (ID of a text field).
     * @since 1.6.0
     */
    public static function render($args)
    {
        $args = wp_parse_args($args, array('source' => 'post', 'label' => '', 'placeholder' => '', 'ids' => array(), 'name' => '', 'target' => '', 'uid' => ''));
        $source = self::source($args['source']);
        // Stable inside the slot template: the browser replaces __N__ when it clones the card.
        $uid = '' !== $args['uid'] ? $args['uid'] : 'adflow-picker-' . (++self::$count);
        ?>
        <div class="adflow-picker" data-source="<?php echo esc_attr($source); ?>"<?php echo '' !== $args['target'] ? ' data-target="' . esc_attr($args['target']) . '"' : ' data-name="' . esc_attr($args['name']) . '"'; ?>>
            <div class="adflow-picker__box" hidden>
                <label class="adflow-sublabel" for="<?php echo esc_attr($uid); ?>"><?php echo esc_html($args['label']); ?></label>
                <input type="text" id="<?php echo esc_attr($uid); ?>" class="adflow-picker__input regular-text" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="<?php echo esc_attr($uid); ?>-list" autocomplete="off" placeholder="<?php echo esc_attr($args['placeholder']); ?>">
                <ul id="<?php echo esc_attr($uid); ?>-list" class="adflow-picker__list" role="listbox" aria-label="<?php echo esc_attr($args['label']); ?>" hidden></ul>
                <span class="screen-reader-text adflow-picker__status" role="status"></span>
            </div>
            <ul class="adflow-picker__chips">
                <?php foreach (array_slice((array) $args['ids'], 0, 100) as $id) {
                    self::render_chip($source, (int) $id, (string) $args['name']);
                } ?>
            </ul>
        </div>
        <?php
    }
}
