<?php
/**
 * AdFlow smoke tests. Run with: wp eval-file wp-content/plugins/simple-google-adsense/tests/run.php
 *
 * Each section is a function named adflow_test_<area>() registered at the
 * bottom of this file (append yours above the "Run" marker). Sections clean up
 * after themselves and are safe to run twice. Not shipped: see .distignore.
 *
 * @package Simple_Google_Adsense
 */

defined('ABSPATH') || exit;

if (!function_exists('adflow_check')) {
    $GLOBALS['adflow_test_passed'] = 0;
    $GLOBALS['adflow_test_failed'] = 0;

    /**
     * Record one assertion.
     *
     * @param string $label What is being checked.
     * @param bool $ok Whether it holds.
     * @param mixed $detail Printed when it fails.
     */
    function adflow_check($label, $ok, $detail = null)
    {
        if ($ok) {
            $GLOBALS['adflow_test_passed']++;
            return;
        }

        $GLOBALS['adflow_test_failed']++;
        echo 'FAIL: ' . $label . (null === $detail ? '' : ' => ' . wp_json_encode($detail)) . "\n";
    }
}

if (!function_exists('check')) {
    /**
     * Short name for adflow_check().
     *
     * @param string $label What is being checked.
     * @param bool $ok Whether it holds.
     * @param mixed $detail Printed when it fails.
     */
    function check($label, $ok, $detail = null)
    {
        adflow_check($label, $ok, $detail);
    }
}

$adflow_test_sections = array();

// ---- Sections: define a function and add its name to $adflow_test_sections. ----

/**
 * Security, statistics and data checks (S3-S6, S8, S10, S12-S16, S19, F9-F11).
 */
function adflow_test_stats_security()
{
    global $wpdb;

    $server = $_SERVER;
    $user = get_current_user_id();
    wp_set_current_user(0);
    $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/140 Safari/537.36';
    $_SERVER['REMOTE_ADDR'] = '203.0.113.77';
    unset($_SERVER['HTTP_ORIGIN'], $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_X_REAL_IP']);

    $stats = Simple_Google_Adsense_Stats::instance();
    $table = Simple_Google_Adsense_Stats::table();
    $meta = array('type' => 'text', 'url' => 'https://example.com/', 'headline' => 'Test', 'track' => true);
    $ad = wp_insert_post(array('post_type' => 'adflow_ad', 'post_status' => 'publish', 'post_title' => 'adflow test'));
    update_post_meta($ad, Simple_Google_Adsense_Ad_Units::META_KEY, $meta);
    $wpdb->query('DELETE FROM ' . Simple_Google_Adsense_Stats::limits_table());
    $event = function ($type, $placement = '') use ($ad) {
        return array('a' => $ad, 'p' => $placement, 't' => $type, 'd' => 0);
    };
    $today = function () use ($ad) {
        return Simple_Google_Adsense_Stats::totals(array('days' => 1, 'ad_id' => $ad));
    };

    // S5: placement keys.
    adflow_check('S5 empty key is valid', Simple_Google_Adsense_Placements::is_valid_stats_key(''));
    adflow_check('S5 registered key is valid', Simple_Google_Adsense_Placements::is_valid_stats_key('after_content'));
    adflow_check('S5 junk key is invalid', !Simple_Google_Adsense_Placements::is_valid_stats_key('junk_123'));
    adflow_check('S5 non-string is invalid', !Simple_Google_Adsense_Placements::is_valid_stats_key(array('x')));
    $extra = function ($keys) {
        $keys[] = 'content_slot_7';
        return $keys;
    };
    add_filter('adflow_stats_placement_keys', $extra);
    adflow_check('S5 filter adds a key', Simple_Google_Adsense_Placements::is_valid_stats_key('content_slot_7'));
    remove_filter('adflow_stats_placement_keys', $extra);

    $stats->process(wp_json_encode(array($event('i', 'junk_a'), $event('i', 'junk_b'), $event('i', 'after_content'), $event('i', array('x')))));
    $keys = array_keys(Simple_Google_Adsense_Stats::grouped('placement', array('days' => 1, 'ad_id' => $ad)));
    sort($keys);
    adflow_check('S5 junk placements make no rows', array('', 'after_content') === $keys, $keys);
    adflow_check('S5 the events still count', 4 === $today()['impressions'], $today());

    // S3/F9: clicks cannot pass impressions; the rest are set aside and come back.
    $stats->process(wp_json_encode(array_fill(0, 6, $event('c'))));
    adflow_check('S3 clicks stop at impressions', 4 === $today()['clicks'], $today());
    adflow_check('S3 extra clicks are held', 2 === Simple_Google_Adsense_Stats::held_clicks(array('days' => 1, 'ad_id' => $ad)));
    adflow_check('S3 clicks stay put without impressions', 4 === $today()['clicks']);
    $stats->process(wp_json_encode(array($event('i'))));
    adflow_check('S3 held clicks return when covered', 1 === Simple_Google_Adsense_Stats::held_clicks(array('days' => 1, 'ad_id' => $ad)) && 5 === $today()['clicks'], $today());
    $stats->process(wp_json_encode(array($event('c'), $event('i'))));
    adflow_check('S3 impression in the same batch covers its click', 6 === $today()['clicks'] && 6 === $today()['impressions'], $today());

    // S3: token and origin.
    $token = Simple_Google_Adsense_Stats::token();
    adflow_check('S3 current token valid', Simple_Google_Adsense_Stats::token_valid($token));
    adflow_check('S3 ten-month-old token valid (cached page)', Simple_Google_Adsense_Stats::token_valid(Simple_Google_Adsense_Stats::token(time() - 300 * DAY_IN_SECONDS)));
    adflow_check('S3 two-year-old token refused', !Simple_Google_Adsense_Stats::token_valid(Simple_Google_Adsense_Stats::token(time() - 730 * DAY_IN_SECONDS)));
    adflow_check('S3 future token refused', !Simple_Google_Adsense_Stats::token_valid(Simple_Google_Adsense_Stats::token(time() + 90 * DAY_IN_SECONDS)));
    adflow_check('S3 forged token refused', !Simple_Google_Adsense_Stats::token_valid(substr($token, 0, -1) . ('0' === substr($token, -1) ? '1' : '0')));
    adflow_check('S3 array token refused', !Simple_Google_Adsense_Stats::token_valid(array($token)));
    adflow_check('S3 page config carries the token', false !== strpos(Simple_Google_Adsense_Stats::script_config()['url'], 'k=' . rawurlencode($token)) || false !== strpos(Simple_Google_Adsense_Stats::script_config()['url'], 'k=' . $token));
    $home = (string) wp_parse_url(home_url(), PHP_URL_HOST);
    adflow_check('S3 own origin allowed', Simple_Google_Adsense_Stats::origin_allowed('http://' . $home));
    adflow_check('S3 no origin allowed', Simple_Google_Adsense_Stats::origin_allowed(''));
    adflow_check('S3 other origin refused', !Simple_Google_Adsense_Stats::origin_allowed('https://evil.example'));
    adflow_check('S3 lookalike origin refused', !Simple_Google_Adsense_Stats::origin_allowed('https://evil' . $home . '.example'));

    // S4: size limit before decoding, shallow JSON only.
    $big = '[' . str_repeat(wp_json_encode($event('i')) . ',', 2000) . wp_json_encode($event('i')) . ']';
    $before = $today()['impressions'];
    adflow_check('S4 oversized body refused', 0 === $stats->process($big) && $before === $today()['impressions']);
    adflow_check('S4 50 events fit the limit', strlen(wp_json_encode(array_fill(0, 50, array('a' => 9999999999, 'p' => str_repeat('x', 40), 't' => 'i', 'd' => 1)))) < Simple_Google_Adsense_Stats::MAX_BODY);
    adflow_check('S4 deep JSON refused', 0 === $stats->process(str_repeat('[', 20) . str_repeat(']', 20)));
    adflow_check('S4 array ad id ignored', 0 === $stats->process(wp_json_encode(array(array('a' => array(1), 't' => 'i')))));

    $request = new WP_REST_Request('POST', '/afx/v1/e');
    $request->set_body(str_repeat(' ', Simple_Google_Adsense_Stats::MAX_BODY + 1));
    $response = apply_filters('rest_pre_dispatch', null, rest_get_server(), $request);
    adflow_check('S4 REST answers 413 before decoding', $response instanceof WP_REST_Response && 413 === $response->get_status());
    $request = new WP_REST_Request('POST', '/AFX/V1/E');
    $request->set_body(str_repeat(' ', Simple_Google_Adsense_Stats::MAX_BODY + 1));
    $response = apply_filters('rest_pre_dispatch', null, rest_get_server(), $request);
    adflow_check('S4 guard ignores route case', $response instanceof WP_REST_Response && 413 === $response->get_status());

    // Rate limit: fixed size, no options rows, per client.
    $options = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options}");
    $wpdb->query('DELETE FROM ' . Simple_Google_Adsense_Stats::limits_table());
    $limit = function () {
        return 10;
    };
    add_filter('adflow_stats_rate_limit', $limit);
    $accepted = 0;
    for ($i = 0; $i < 15; $i++) {
        $accepted += $stats->process(wp_json_encode(array($event('i'))));
    }
    adflow_check('S3 rate limit stops the client', 10 === $accepted, $accepted);
    $_SERVER['REMOTE_ADDR'] = '203.0.113.78';
    adflow_check('S3 another client still counts', 1 === $stats->process(wp_json_encode(array($event('i')))));
    $_SERVER['REMOTE_ADDR'] = '203.0.113.77';
    remove_filter('adflow_stats_rate_limit', $limit);
    adflow_check('S3 limit adds no options rows', $options === (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options}"));
    adflow_check('S3 limits table holds salted numbers only', 2 >= (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Simple_Google_Adsense_Stats::limits_table()));
    $wpdb->query('DELETE FROM ' . Simple_Google_Adsense_Stats::limits_table());

    // S19: forwarding headers count only from a trusted proxy.
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.9';
    $_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.10';
    adflow_check('S19 direct visitor cannot spoof headers', '203.0.113.77' === Simple_Google_Adsense_Stats::client_ip(), Simple_Google_Adsense_Stats::client_ip());
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.1, 198.51.100.9';
    adflow_check('S19 local proxy: nearest public hop wins', '198.51.100.9' === Simple_Google_Adsense_Stats::client_ip(), Simple_Google_Adsense_Stats::client_ip());
    $_SERVER['REMOTE_ADDR'] = '173.245.48.5';
    adflow_check('S19 Cloudflare header believed from Cloudflare', '198.51.100.10' === Simple_Google_Adsense_Stats::client_ip(), Simple_Google_Adsense_Stats::client_ip());
    $_SERVER['REMOTE_ADDR'] = '2606:4700::1';
    adflow_check('S19 Cloudflare IPv6 range works', '198.51.100.10' === Simple_Google_Adsense_Stats::client_ip(), Simple_Google_Adsense_Stats::client_ip());
    $_SERVER['REMOTE_ADDR'] = '203.0.113.77';
    unset($_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_CF_CONNECTING_IP']);

    // S16/F11: real browsers and apps count; bots do not.
    foreach (array(
        'Mozilla/5.0 (Linux; Android 13) AppleWebKit/537.36 Chrome/120 Mobile Safari/537.36 DuckDuckGo/5',
        'Mozilla/5.0 (Linux; Android 13) AppleWebKit/537.36 Chrome/120 Mobile Safari/537.36 YandexSearch/23.1',
        'Mozilla/5.0 (Linux; Android 13) AppleWebKit/537.36 Chrome/120 Mobile Safari/537.36 Pinterest for Android/11',
        'Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Chrome/120 Safari/537.36 SmartMonitor',
        'Mozilla/5.0 (Linux; Android 9; CUBOT X18) AppleWebKit/537.36 Chrome/120 Mobile Safari/537.36',
        'Mozilla/5.0 (Linux; Android 13; TECNO Phantom X) AppleWebKit/537.36 Chrome/120 Mobile Safari/537.36',
    ) as $ua) {
        $_SERVER['HTTP_USER_AGENT'] = $ua;
        adflow_check('S16 browser counts: ' . $ua, !Simple_Google_Adsense_Stats::is_invalid_traffic());
    }
    foreach (array(
        'Mozilla/5.0 (compatible; YandexBot/3.0; +http://yandex.com/bots)',
        'Mozilla/5.0 (compatible; YandexImages/3.0; +http://yandex.com/bots)',
        'DuckDuckBot/1.1; (+http://duckduckgo.com/duckduckbot.html)',
        'Mozilla/5.0 (compatible; Pinterestbot/1.0; +http://www.pinterest.com/bot.html)',
        'Pinterest/0.2 (+http://www.pinterest.com/)',
        'Mozilla/5.0 (compatible; UptimeRobot/2.0; http://www.uptimerobot.com/)',
        'Mozilla/5.0 (compatible; site-monitor/1.0; example)',
        'Mozilla/5.0 (X11; Linux) HeadlessChrome/120 Safari/537.36',
        'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
    ) as $ua) {
        $_SERVER['HTTP_USER_AGENT'] = $ua;
        adflow_check('S16 bot ignored: ' . $ua, Simple_Google_Adsense_Stats::is_invalid_traffic());
    }
    $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/140 Safari/537.36';

    // S8: the listing is closed however the route is written.
    foreach (array('/wp/v2/adflow-ads', '/wp/v2/ADFLOW-ADS', '/WP/V2/Adflow-Ads/' . $ad, '//wp//v2/adflow-ads', '/wp/v2/adflow%2Dads') as $route) {
        $request = new WP_REST_Request('GET', $route);
        $result = apply_filters('rest_pre_dispatch', null, rest_get_server(), $request);
        adflow_check('S8 anonymous refused: ' . $route, is_wp_error($result));
    }
    $request = new WP_REST_Request('GET', '/wp/v2/ADFLOW-ADS');
    $response = rest_do_request($request);
    adflow_check('S8 full request refused', in_array($response->get_status(), array(401, 403), true), $response->get_status());

    // S12: ranges are bounded and validated.
    $range = Simple_Google_Adsense_Stats::range(array('from' => '1000-01-01', 'to' => '9000-01-01'));
    adflow_check('S12 huge range is cut', (strtotime($range['to']) - strtotime($range['from'])) / DAY_IN_SECONDS < Simple_Google_Adsense_Stats::MAX_RANGE_DAYS, $range);
    $range = Simple_Google_Adsense_Stats::range(array('from' => '2026-99-99', 'to' => '2026-01-01'));
    adflow_check('S12 impossible date falls back', Simple_Google_Adsense_Stats::is_date($range['from']) && '2026-99-99' !== $range['from'], $range);
    $range = Simple_Google_Adsense_Stats::range(array('from' => array('x'), 'to' => array('y'), 'days' => array('z')));
    adflow_check('S12 array dates fall back', Simple_Google_Adsense_Stats::is_date($range['from']), $range);
    $range = Simple_Google_Adsense_Stats::range(array('from' => '2026-02-10', 'to' => '2026-02-01'));
    adflow_check('S12 swapped dates are ordered', '2026-02-01' === $range['from'] && '2026-02-10' === $range['to'], $range);
    adflow_check('S12 days are capped', count(Simple_Google_Adsense_Stats::grouped('day', array('days' => 9000000, 'ad_id' => $ad))) === Simple_Google_Adsense_Stats::MAX_RANGE_DAYS);
    adflow_check('S12 normal range unchanged', 30 === count(Simple_Google_Adsense_Stats::grouped('day', array('days' => 30, 'ad_id' => $ad))));

    // S13: import data of the wrong type must not fatal.
    $bad = array();
    foreach (array('code', 'slot', 'type', 'format', 'layout_key', 'url', 'alt', 'headline', 'body', 'cta', 'disclosure', 'start', 'end', 'fallback_id', 'rotation', 'image_id') as $key) {
        $bad[$key] = array('x' => array('y'));
    }
    $bad['members'] = array(5, 'x', array('id' => array(1)), array('id' => $ad, 'weight' => array(1)));
    try {
        $clean = Simple_Google_Adsense_Ad_Units::sanitize_data($bad, 0);
        adflow_check('S13 arrays are dropped', is_array($clean) && '' === $clean['url'] && 'sponsored' === $clean['disclosure'], $clean);
    } catch (\Throwable $e) {
        adflow_check('S13 arrays are dropped', false, $e->getMessage());
    }

    // S15: past days are cached, today stays live and exact.
    $old = gmdate('Y-m-d', strtotime(current_time('Y-m-d') . ' 00:00:00 UTC -40 days'));
    Simple_Google_Adsense_Stats::write(array(array('ad_id' => $ad, 'placement' => '', 'device' => 0, 'i' => 100, 'v' => 0, 'c' => 1)), $old);
    Simple_Google_Adsense_Stats::flush_report_cache();
    $first = Simple_Google_Adsense_Stats::totals(array('days' => 60, 'ad_id' => $ad));
    $cached = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_afx\\_r\\_%'");
    adflow_check('S15 long range is cached', $cached > 0);
    $stats->process(wp_json_encode(array($event('i'))));
    $second = Simple_Google_Adsense_Stats::totals(array('days' => 60, 'ad_id' => $ad));
    adflow_check('S15 today is live', $second['impressions'] === $first['impressions'] + 1, array($first, $second));
    $days = Simple_Google_Adsense_Stats::grouped('day', array('days' => 60, 'ad_id' => $ad));
    adflow_check('S15 per-day sums match totals', array_sum(wp_list_pluck($days, 'impressions')) === $second['impressions']);
    Simple_Google_Adsense_Stats::write(array(array('ad_id' => $ad, 'placement' => '', 'device' => 0, 'i' => 5, 'v' => 0, 'c' => 0)), $old);
    Simple_Google_Adsense_Stats::flush_report_cache();
    adflow_check('S15 flush picks up changed past days', Simple_Google_Adsense_Stats::totals(array('days' => 60, 'ad_id' => $ad))['impressions'] === $second['impressions'] + 5);

    // S10: privacy tools and truthful text.
    $author = wp_create_user('adflow_privacy_test', wp_generate_password(), 'adflow-privacy@example.test');
    $wpdb->update($wpdb->posts, array('post_author' => $author), array('ID' => $ad));
    clean_post_cache($ad);
    adflow_check('S10 exporter registered', isset(apply_filters('wp_privacy_personal_data_exporters', array())['adflow']));
    adflow_check('S10 eraser registered', isset(apply_filters('wp_privacy_personal_data_erasers', array())['adflow']));
    $export = $stats->export_personal_data('adflow-privacy@example.test', 1);
    adflow_check('S10 export lists the author\'s ads', 1 === count($export['data']) && $export['done'], $export);
    adflow_check('S10 unknown email exports nothing', array() === $stats->export_personal_data('nobody@example.test', 1)['data']);
    $erase = $stats->erase_personal_data('adflow-privacy@example.test', 1);
    adflow_check('S10 eraser detaches the author', $erase['items_removed'] && 0 === (int) get_post_field('post_author', $ad), $erase);
    adflow_check('S10 ad keeps running after erasure', 'publish' === get_post_status($ad));
    adflow_check('S10 nothing left to erase', !$stats->erase_personal_data('adflow-privacy@example.test', 1)['items_removed']);
    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user($author);

    // S14: uninstall knows about every leftover (checked for real in the release pass).
    $uninstall = file_get_contents(SIMPLE_GOOGLE_ADSENSE_ABSPATH . 'uninstall.php');
    foreach (array('adflow_stats_self_test', 'afx_limits', 'afx_held', 'afx\\\\_c\\\\_', 'simple_google_adsense_stats_key', 'simple_google_adsense_stats_cache') as $needle) {
        adflow_check('S14 uninstall covers ' . $needle, false !== strpos($uninstall, $needle));
    }

    // Upgrade: repeat-click rows go away, history stays, tables exist.
    set_transient('afx_c_adflowtest', 1, 100);
    Simple_Google_Adsense_Stats::write(array(array('ad_id' => $ad, 'placement' => 'history', 'device' => 0, 'i' => 3, 'v' => 0, 'c' => 0)), $old);
    $version = get_option(Simple_Google_Adsense_Stats::DB_VERSION_OPTION);
    update_option(Simple_Google_Adsense_Stats::DB_VERSION_OPTION, '2', false);
    Simple_Google_Adsense_Stats::maybe_install();
    wp_cache_delete('alloptions', 'options');
    adflow_check('Upgrade removes old click flags', !$wpdb->get_var("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '%afx\\_c\\_adflowtest'"));
    adflow_check('Upgrade keeps history', 3 === (int) $wpdb->get_var($wpdb->prepare("SELECT impressions FROM {$table} WHERE ad_id = %d AND placement = 'history'", $ad)));
    adflow_check('Upgrade sets the version', Simple_Google_Adsense_Stats::DB_VERSION === get_option(Simple_Google_Adsense_Stats::DB_VERSION_OPTION), $version);

    // Clean up.
    wp_delete_post($ad, true);
    adflow_check('Deleting an ad deletes its stats and held clicks', 0 === (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE ad_id = %d", $ad)) && 0 === (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Simple_Google_Adsense_Stats::held_table() . ' WHERE ad_id = %d', $ad)));
    $wpdb->query('DELETE FROM ' . Simple_Google_Adsense_Stats::limits_table());
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_afx\\_r\\_%' OR option_name LIKE '\\_transient\\_timeout\\_afx\\_r\\_%'");
    $_SERVER = $server;
    wp_set_current_user($user);
}
$adflow_test_sections[] = 'adflow_test_stats_security';

/**
 * Repeatable in-content ads, free conditions, post picker, per-post box, CSS-selector slots.
 */
function adflow_test_content_gaps()
{
    $O = Simple_Google_Adsense_Placements::OPTION_NAME;
    $S = Simple_Google_Adsense_Settings::OPTION_NAME;
    $P = 'Simple_Google_Adsense_Placements';
    $C = 'Simple_Google_Adsense_Content_Slots';
    $saved_placements = get_option($O);
    $saved_settings = get_option($S);
    $created = array();
    $terms = array();
    $user = get_current_user_id();

    wp_set_current_user(0);
    update_option($S, array_merge(is_array($saved_settings) ? $saved_settings : array(), array('publisher_id' => 'pub-1234567890123456', 'enable_manual_ads' => true, 'hide_for_admins' => false, 'manual_ads_exclude_ids' => '', 'manual_ads_exclude_post_types' => array())));

    $make_unit = function ($slot) use (&$created) {
        $id = wp_insert_post(array('post_type' => 'adflow_ad', 'post_status' => 'publish', 'post_title' => 'T ' . $slot));
        update_post_meta($id, Simple_Google_Adsense_Ad_Units::META_KEY, array('slot' => $slot, 'type' => 'display'));
        $created[] = $id;
        return $id;
    };
    $make_post = function ($paragraphs, $extra = '') use (&$created) {
        $content = $extra;
        for ($i = 1; $i <= $paragraphs; $i++) {
            $content .= "<p>Paragraph {$i} of the test post, with enough words.</p>\n";
        }
        $id = wp_insert_post(array('post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Gap test ' . wp_generate_password(6, false), 'post_content' => $content));
        $created[] = $id;
        return $id;
    };
    // Ads between paragraphs: returns the paragraph number each ad follows.
    $positions = function ($html, $slot = '') {
        $found = array();
        if (preg_match_all('/<p>Paragraph (\d+)|data-ad-slot="(\d+)"/', $html, $m, PREG_SET_ORDER)) {
            $last = 0;
            foreach ($m as $hit) {
                if (!empty($hit[2])) {
                    if ('' === $slot || $hit[2] === $slot) {
                        $found[] = $last;
                    }
                } else {
                    $last = (int) $hit[1];
                }
            }
        }
        return $found;
    };
    // Render a post's content as the main query would (inserted once per post per request).
    $view = function ($id) {
        global $wp_query, $wp_the_query, $post, $wp_actions;
        // wp eval-file does not run wp(): per-page rules wait for this action.
        $wp_actions['wp'] = isset($wp_actions['wp']) ? $wp_actions['wp'] : 1;
        $wp_query = new WP_Query(array('p' => $id, 'post_type' => 'any'));
        $wp_the_query = $wp_query;
        $wp_query->the_post();
        $html = apply_filters('the_content', get_post_field('post_content', $id));
        return $html;
    };
    $store = function ($placements) use ($O, $P) {
        remove_all_filters('sanitize_option_' . $O);
        update_option($O, $placements);
        $P::flush_types();
    };
    $u1 = $make_unit('1111111111');
    $u2 = $make_unit('2222222222');

    // Existing sites: no slots, after_paragraph works exactly as before.
    $store(array('after_paragraph' => array('enabled' => true, 'ad_id' => $u1, 'paragraph' => 2, 'post_types' => array('post'))));
    check('no slot types without stored slots', !array_filter(array_keys($P::get_types()), array($C, 'is_slot_key')));
    $html = $view($make_post(8));
    check('after_paragraph alone is unchanged', array(2) === $positions($html), $positions($html));
    check('no slot markup without slots', false === strpos($html, 'content_slot_'));

    // Every N, maximum per post, own ad unit, statistics key.
    $store(array('content_slot_1' => array('enabled' => true, 'ad_id' => $u2, 'mode' => 'every', 'interval' => 3, 'max_ads' => 2, 'min_paragraphs' => 4, 'post_types' => array('post'))));
    check('slot is a registered type', isset($P::get_types()['content_slot_1']));
    check('slot passes the stats key check', $P::is_valid_stats_key('content_slot_1'));
    check('an unknown slot does not pass the stats key check', !$P::is_valid_stats_key('content_slot_77'));
    $html = $view($make_post(12));
    check('every 3, max 2', array(3, 6) === $positions($html), $positions($html));
    check('slot key in markup', false !== strpos($html, 'data-adflow-placement="content_slot_1"'));
    check('markers removed', false === strpos($html, 'adflow-ad-->'));

    // Never after the last paragraph.
    $html = $view($make_post(6));
    check('every 3 of 6 stops before the end', array(3) === $positions($html), $positions($html));

    // Short posts are skipped.
    $html = $view($make_post(3));
    check('short post skipped', array() === $positions($html), $positions($html));

    // Spacing from every other ad, in any order; two ads are never adjacent.
    $store(array(
        'after_paragraph' => array('enabled' => true, 'ad_id' => $u1, 'paragraph' => 3, 'post_types' => array('post')),
        'after_content' => array('enabled' => true, 'ad_id' => $u1, 'post_types' => array('post')),
        'content_slot_1' => array('enabled' => true, 'ad_id' => $u2, 'mode' => 'after', 'paragraph' => 3, 'min_paragraphs' => 0, 'post_types' => array('post')),
        'content_slot_2' => array('enabled' => true, 'ad_id' => $u2, 'mode' => 'after', 'paragraph' => 4, 'min_paragraphs' => 0, 'post_types' => array('post')),
        'content_slot_3' => array('enabled' => true, 'ad_id' => $u2, 'mode' => 'after', 'paragraph' => 5, 'min_paragraphs' => 0, 'post_types' => array('post')),
        'content_slot_4' => array('enabled' => true, 'ad_id' => $u2, 'mode' => 'after', 'paragraph' => 6, 'min_paragraphs' => 0, 'post_types' => array('post')),
    ));
    $html = $view($make_post(6));
    check('fixed spot of the old placement kept', array(3, 6) === $positions($html, '1111111111'), $positions($html, '1111111111'));
    check('no slot next to or one away from another ad', array() === $positions($html, '2222222222'), $positions($html, '2222222222'));
    $html = $view($make_post(9));
    check('the first spot two away from every ad is used', array(5) === $positions($html, '2222222222'), $positions($html, '2222222222'));

    // An ad the author added by hand counts as an ad.
    $store(array('content_slot_1' => array('enabled' => true, 'ad_id' => $u2, 'mode' => 'after', 'paragraph' => 2, 'min_paragraphs' => 0, 'post_types' => array('post'))));
    $id = wp_insert_post(array('post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Hand', 'post_content' => "<p>Paragraph 1 x</p>\n<p>Paragraph 2 x</p>\n[adflow id=\"{$u1}\"]\n<p>Paragraph 3 x</p>\n<p>Paragraph 4 x</p>"));
    $created[] = $id;
    check('hand-placed ad blocks the neighbouring slot', array() === $positions($view($id), '2222222222'));

    // Pro's filters apply to slots: display decision, ad ID, per-page limit.
    $seen = array();
    $spy = function ($display, $key) use (&$seen) {
        $seen[] = $key;
        return $display;
    };
    add_filter('adflow_placement_should_display', $spy, 10, 2);
    $swap = function ($ad_id, $key) use ($u1) {
        return 'content_slot_1' === $key ? $u1 : $ad_id;
    };
    add_filter('adflow_placement_ad_id', $swap, 10, 2);
    $html = $view($make_post(8));
    remove_filter('adflow_placement_should_display', $spy, 10);
    remove_filter('adflow_placement_ad_id', $swap, 10);
    check('adflow_placement_should_display sees the slot', in_array('content_slot_1', $seen, true));
    check('adflow_placement_ad_id changes the slot unit', array(2) === $positions($html, '1111111111'));

    $store(array('content_slot_1' => array('enabled' => true, 'ad_id' => $u2, 'mode' => 'every', 'interval' => 2, 'max_ads' => 9, 'min_paragraphs' => 0, 'post_types' => array('post'))));
    $count = 0;
    $counter = function () use (&$count) {
        $count++;
    };
    add_action('adflow_ad_rendered', $counter);
    $limit = function ($display) use (&$count) {
        return $count >= 2 ? false : $display;
    };
    add_filter('adflow_should_display_ad', $limit);
    $html = $view($make_post(12));
    remove_filter('adflow_should_display_ad', $limit);
    remove_action('adflow_ad_rendered', $counter);
    check('each slot ad counts towards a per-page limit', 2 === count($positions($html)) && 2 === $count, array($positions($html), $count));

    // Conditions, stored in Pro's format.
    $cat = wp_insert_term('Gap cat ' . wp_generate_password(5, false), 'category');
    $tag = wp_insert_term('Gap tag ' . wp_generate_password(5, false), 'post_tag');
    $terms = array(array($cat['term_id'], 'category'), array($tag['term_id'], 'post_tag'));
    $in = $make_post(8);
    $out = $make_post(8);
    wp_set_post_terms($in, array($cat['term_id']), 'category');
    wp_set_post_terms($in, array($tag['term_id']), 'post_tag');
    $base = array('enabled' => true, 'ad_id' => $u1, 'paragraph' => 2, 'post_types' => array('post'));
    $rule_case = function ($rule, $post) use ($store, $base, $view, $positions, $make_post) {
        $store(array('after_paragraph' => array_merge($base, $rule)));
        return (bool) $positions($view($post));
    };
    // $done is per post, so each case needs its own post with the same terms.
    $fresh = function ($with_terms) use ($make_post, $cat, $tag) {
        $id = $make_post(8);
        if ($with_terms) {
            wp_set_post_terms($id, array($cat['term_id']), 'category');
            wp_set_post_terms($id, array($tag['term_id']), 'post_tag');
        }
        return $id;
    };
    check('category rule matches', $rule_case(array('categories' => array($cat['term_id'])), $fresh(true)));
    check('category rule hides', !$rule_case(array('categories' => array($cat['term_id'])), $fresh(false)));
    check('tag rule matches', $rule_case(array('tags' => array($tag['term_id'])), $fresh(true)));
    check('tag rule hides', !$rule_case(array('tags' => array($tag['term_id'])), $fresh(false)));
    $one = $fresh(false);
    check('only-on-posts matches', $rule_case(array('post_ids' => array($one)), $one));
    check('only-on-posts hides', !$rule_case(array('post_ids' => array($one)), $fresh(false)));
    $one = $fresh(false);
    check('never-on-posts hides', !$rule_case(array('exclude_post_ids' => array($one)), $one));
    check('language rule is ignored without a language plugin', $rule_case(array('languages' => array('xx')), $fresh(false)) || (bool) Simple_Google_Adsense_Conditions::languages());

    // Device rule: same stored value; no ad request in the markup.
    $store(array('after_paragraph' => array_merge($base, array('device' => 'mobile'))));
    $html = $view($make_post(8));
    check('device rule carried on the wrapper', false !== strpos($html, 'data-adflow-device="mobile"') && false !== strpos($html, 'data-adflow-defer="1"'));
    check('device rule: hidden class', false !== strpos($html, 'adflow-only-mobile'));
    check('device rule: ad code held in a template, no inline push', false !== strpos($html, '<template class="adflow-held"><ins class="adsbygoogle"') && false === strpos($html, 'adsbygoogle = window.adsbygoogle'), $html);
    $store(array('after_paragraph' => $base));
    $html = $view($make_post(8));
    check('no rule: normal AdSense markup', false !== strpos($html, 'class="adsbygoogle"') && false !== strpos($html, 'adsbygoogle = window.adsbygoogle') && false === strpos($html, 'data-adflow-device'));

    // Sanitizing: slots, selector, removal, other keys kept.
    $instance = $P::instance();
    $store(array('after_paragraph' => array_merge($base, array('some_addon_key' => 7)), 'content_slot_1' => array('enabled' => true, 'ad_id' => $u1, 'selector' => '.keep'), 'content_slot_2' => array('enabled' => true, 'ad_id' => $u2)));
    $clean = $instance->sanitize(array(
        'after_paragraph' => array('enabled' => '1', 'ad_id' => $u1, 'paragraph' => '4', 'post_types' => array('post'), 'device' => 'desktop', 'categories' => array('5', 'x'), 'tags' => array('9'), 'post_ids' => array('12', '12')),
        'content_slot_1' => array('enabled' => '1', 'ad_id' => $u1, 'mode' => 'element', 'selector' => '<script>x</script>', 'nth' => '999', 'position' => 'evil', 'reserve' => '99999', 'post_types' => array('post', 'nope')),
        'content_slot_7' => array('mode' => 'after', 'paragraph' => '0', 'selector' => '.entry-content > h2:nth-of-type(2)'),
        'content_slot_bad' => array('enabled' => '1'),
        'evil' => array('enabled' => '1'),
    ));
    check('old placement sanitized in Pro format', 'desktop' === $clean['after_paragraph']['device'] && array(5) === $clean['after_paragraph']['categories'] && array(9) === $clean['after_paragraph']['tags'] && array(12) === $clean['after_paragraph']['post_ids']);
    check('stored keys survive sanitizing', 7 === $clean['after_paragraph']['some_addon_key']);
    check('removed slot is gone', !isset($clean['content_slot_2']));
    check('bad keys refused', !isset($clean['content_slot_bad']) && !isset($clean['evil']));
    check('bad selector keeps the previous value', '.keep' === $clean['content_slot_1']['selector'], $clean['content_slot_1']['selector']);
    check('numbers clamped', 50 === $clean['content_slot_1']['nth'] && 1200 === $clean['content_slot_1']['reserve'] && 1 === $clean['content_slot_7']['paragraph']);
    check('position whitelisted', 'after' === $clean['content_slot_1']['position']);
    check('post types whitelisted', array('post') === $clean['content_slot_1']['post_types']);
    check('valid selector stored', '.entry-content > h2:nth-of-type(2)' === $clean['content_slot_7']['selector']);
    $many = array();
    for ($i = 1; $i <= 30; $i++) {
        $many['content_slot_' . $i] = array('enabled' => '1');
    }
    check('slot limit', $C::MAX_SLOTS === count(array_filter(array_keys($instance->sanitize($many)), array($C, 'is_slot_key'))));

    foreach (array('.a', '#b > p', 'article h2:nth-of-type(2)', 'div[data-x="y"]', 'h2, h3', 'main *') as $good) {
        check('selector accepted: ' . $good, $C::is_valid_selector($good));
    }
    foreach (array('', '   ', '<img>', 'a{b:c}', 'a;b', '@import', 'a[b', 'a(b', '> a', 'a >', ',', str_repeat('a', 201)) as $bad) {
        check('selector refused: ' . $bad, !$C::is_valid_selector($bad));
    }

    // Selector slot prints an inert template; it is not in the content.
    $store(array('content_slot_1' => array('enabled' => true, 'ad_id' => $u1, 'mode' => 'element', 'selector' => '.entry-content h2', 'nth' => 2, 'position' => 'before', 'reserve' => 300, 'post_types' => array('post'))));
    $id = $make_post(8);
    $html = $view($id);
    check('selector slot is not in the content', array() === $positions($html));
    ob_start();
    $C::instance()->print_element_slots();
    $footer = ob_get_clean();
    check('selector slot prints a template', 0 === strpos($footer, '<template class="afx-sel" data-afx-sel=".entry-content h2" data-afx-nth="2" data-afx-pos="before" data-afx-h="300">') && false !== strpos($footer, 'data-ad-slot="1111111111"') && false !== strpos($footer, '</template>'), $footer);
    $store(array('content_slot_1' => array('enabled' => true, 'ad_id' => $u1, 'mode' => 'element', 'selector' => '', 'post_types' => array('post'))));
    ob_start();
    $C::instance()->print_element_slots();
    check('no selector, no output', '' === ob_get_clean());

    // Per-post box: Pro's meta keys.
    $store(array('after_paragraph' => $base));
    $id = $make_post(8);
    update_post_meta($id, '_adflow_disable_manual_ads', 1);
    check('No manual ads', array() === $positions($view($id)));
    $id = $make_post(8);
    update_post_meta($id, '_adflow_disable_ads', 1);
    check('No ads', array() === $positions($view($id)) && !Simple_Google_Adsense_Settings::ads_allowed());
    $id = $make_post(8);
    update_post_meta($id, '_adflow_disable_auto_ads', 1);
    $html = $view($id);
    check('No Auto Ads leaves manual ads', array(2) === $positions($html) && !Simple_Google_Adsense_Settings::auto_ads_allowed());

    // Picker.
    $id = wp_insert_post(array('post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Findme Picker Page'));
    $created[] = $id;
    $draft = wp_insert_post(array('post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'Findme Draft'));
    $created[] = $draft;
    $found = Simple_Google_Adsense_Picker::search('post', 'Findme');
    check('picker finds pages by title, not drafts', array($id) === wp_list_pluck($found, 'id'), $found);
    check('picker finds by ID', in_array($id, wp_list_pluck(Simple_Google_Adsense_Picker::search('post', (string) $id), 'id'), true));
    $looked = Simple_Google_Adsense_Picker::search('post', '', array($id, 99999999));
    check('picker labels IDs, empty for missing', false !== strpos($looked[0]['label'], 'Findme') && '' === $looked[1]['label']);
    check('picker finds terms', $cat['term_id'] === Simple_Google_Adsense_Picker::search('category', get_term($cat['term_id'])->name)[0]['id']);

    // Import: a second after-paragraph ad becomes an extra ad.
    $store(array('after_paragraph' => $base));
    $key = $C::add(array('enabled' => true, 'ad_id' => $u2, 'mode' => 'after', 'paragraph' => 5, 'min_paragraphs' => 0));
    $stored = get_option($O);
    check('add() stores a slot', 'content_slot_1' === $key && 5 === $stored[$key]['paragraph'] && isset($P::get_types()[$key]));

    // The importer's second after-paragraph ad used to report "Already used".
    $store(array('after_paragraph' => $base));
    $place = new ReflectionMethod('Simple_Google_Adsense_Migrate', 'place');
    $place->setAccessible(true);
    $place->invoke(Simple_Google_Adsense_Migrate::instance(), 'Second', 'after_paragraph', $u2, array('paragraph' => 7), false);
    $stored = get_option($O);
    check('import makes a second after-paragraph ad an extra ad', isset($stored['content_slot_1']) && $u2 === $stored['content_slot_1']['ad_id'] && 7 === $stored['content_slot_1']['paragraph'] && 'after' === $stored['content_slot_1']['mode'], $stored);
    check('import leaves the existing placement alone', $u1 === $stored['after_paragraph']['ad_id'] && 2 === $stored['after_paragraph']['paragraph']);

    // Page builders go through render_unit().
    check('builder element renders a saved unit', false !== strpos(Simple_Google_Adsense_Integrations::render_unit($u1, 'elementor'), 'data-ad-slot="1111111111"'));
    check('builder element without a unit renders nothing', '' === Simple_Google_Adsense_Integrations::render_unit(0, 'elementor'));
    check('editor stand-in has no ad code', false === strpos(Simple_Google_Adsense_Integrations::editor_preview($u1), '<ins'));

    // Shipped config for WPML and Polylang is well formed and covers the keys.
    $xml = simplexml_load_file(SIMPLE_GOOGLE_ADSENSE_ABSPATH . 'wpml-config.xml');
    check('wpml-config.xml parses', false !== $xml && 4 === count($xml->{'custom-fields'}->{'custom-field'}));

    // Clean up.
    foreach ($created as $post_id) {
        wp_delete_post($post_id, true);
    }
    foreach ($terms as $term) {
        wp_delete_term($term[0], $term[1]);
    }
    if (false === $saved_placements) {
        delete_option($O);
    } else {
        remove_all_filters('sanitize_option_' . $O);
        update_option($O, $saved_placements);
    }
    remove_all_filters('sanitize_option_' . $S);
    if (false === $saved_settings) {
        delete_option($S);
    } else {
        update_option($S, $saved_settings);
    }
    $P::flush_types();
    wp_reset_postdata();
    wp_set_current_user($user);
}
$adflow_test_sections[] = 'adflow_test_content_gaps';

/**
 * Free plugin correctness: consent, schedules, paragraph counting, AMP, health
 * and checklist truthfulness, importers, access links.
 */
function adflow_test_free_correctness()
{
    $S = 'Simple_Google_Adsense_Settings';
    $U = 'Simple_Google_Adsense_Ad_Units';
    $M = 'Simple_Google_Adsense_Manual_Ads';
    $P = 'Simple_Google_Adsense_Placements';
    $C = 'Simple_Google_Adsense_Consent';
    $T = 'Simple_Google_Adsense_Ads_Txt';
    $options = array($S::OPTION_NAME, $C::OPTION_NAME, $P::OPTION_NAME, $T::OPTION_NAME, 'ad_inserter', Simple_Google_Adsense_Migrate::MAP_OPTION, Simple_Google_Adsense_Migrate::REPORT_OPTION, 'active_plugins');
    $saved = array();
    foreach ($options as $option) {
        $saved[$option] = get_option($option, '__none__');
    }
    $transient = get_transient($T::CHECK_TRANSIENT);
    $previous_user = get_current_user_id();
    $ids = array();
    $make = function ($title, $data) use (&$ids, $U) {
        $id = wp_insert_post(array('post_type' => $U::POST_TYPE, 'post_status' => 'publish', 'post_title' => $title));
        update_post_meta($id, $U::META_KEY, array_merge($U::defaults(), $data));
        $ids[] = $id;
        return $id;
    };
    $settings = function ($values) use ($S) {
        update_option($S::OPTION_NAME, array_merge(array('publisher_id' => 'pub-1234567890123456', 'enable_auto_ads' => true, 'enable_manual_ads' => true), $values));
    };
    $tag = function () {
        wp_deregister_script(Simple_Google_Adsense_Frontend::LIBRARY_HANDLE);
        Simple_Google_Adsense_Frontend::register_library();
        $registered = wp_scripts()->query(Simple_Google_Adsense_Frontend::LIBRARY_HANDLE, 'registered');
        return $registered ? $registered->src : '';
    };

    wp_set_current_user(1);
    $settings(array());

    // F1: no timer releases ads, and the WP Consent API alone is not consent.
    update_option($C::OPTION_NAME, array('wait' => true, 'consent_mode' => true));
    update_option('active_plugins', array());
    ob_start();
    Simple_Google_Adsense_Consent::instance()->print_head_script();
    $head = ob_get_clean();
    check('F1 consent script has no grant at the end of a timer', false === strpos($head, 'watch(') && !preg_match('/n>0|\)\s*;grant\(\)\}$/m', $head), $head);
    check('F1 WP Consent API needs a consent type', false !== strpos($head, 'wp_consent_type||w.wp_fallback_consent_type'));
    check('F1 reads CookieYes, Complianz and Cookiebot', false !== strpos($head, 'getCkyConsent') && false !== strpos($head, 'cmplz_has_consent') && false !== strpos($head, 'Cookiebot.consent'));
    check('F1 no plugin: AdSense message assumed until saved', $C::uses_google_message());
    update_option('active_plugins', array('cookie-law-info/cookie-law-info.php'));
    check('F1 plugin found: message not assumed', !$C::uses_google_message());
    check('F1 CookieYes is readable', array() === $C::unreadable_plugins());
    update_option('active_plugins', array('cookie-notice/cookie-notice.php'));
    check('F1 unreadable tool is flagged', array('Cookie Notice & Compliance') === $C::unreadable_plugins());
    $health = Simple_Google_Adsense_Consent::instance()->health_check(array());
    check('F1 unreadable tool warns on Site health', 'warning' === $health['consent']['status'] && false !== strpos($health['consent']['message'], 'cannot read'), $health);
    update_option('active_plugins', array('wp-consent-api/wp-consent-api.php'));
    check('F1 WP Consent API alone is not a consent plugin', array() === $C::consent_plugins());
    $stored = Simple_Google_Adsense_Consent::instance()->sanitize(array('wait' => 1));
    check('F1 saving stores the message choice', false === $stored['google_message']);
    update_option('active_plugins', array());
    delete_option($C::OPTION_NAME);

    // F4.
    $settings(array('enable_auto_ads' => false));
    check('F4 Auto Ads off: no ?client= tag', false === strpos($tag(), 'client='), $tag());
    $settings(array());
    check('F4 Auto Ads on: ?client= tag', false !== strpos($tag(), 'client=ca-pub-1234567890123456'), $tag());
    wp_deregister_script(Simple_Google_Adsense_Frontend::LIBRARY_HANDLE);

    // F5: starts later, on cached pages.
    $future = gmdate('Y-m-d\TH:i', time() + 3 * DAY_IN_SECONDS);
    $past = gmdate('Y-m-d\TH:i', time() - 3 * DAY_IN_SECONDS);
    $fallback = $make('FB', array('type' => 'display', 'slot' => '123'));
    $later = $make('Later', array('type' => 'text', 'headline' => 'Later ad', 'start' => $future, 'fallback_id' => $fallback));
    $html = $M::render_unit($U::get($later), array());
    check('F5 future ad waits in a template', false !== strpos($html, '<template class="afx-sch-ad">') && false !== strpos($html, 'Later ad'), $html);
    check('F5 its fallback waits too', false !== strpos($html, '<template class="afx-sch-fb">') && false !== strpos($html, 'data-ad-slot="123"'), $html);
    check('F5 nothing outside the templates', '' === trim(preg_replace('#<template.*</template>#s', '', preg_replace('#^<div[^>]*>|</div>$#', '', $html))));
    $alone = $make('Alone', array('type' => 'text', 'headline' => 'Alone', 'start' => $future));
    check('F5 future ad without fallback is still prepared', false !== strpos($M::render_unit($U::get($alone), array()), 'afx-sch-ad'));
    $ending = $make('Ending', array('type' => 'text', 'headline' => 'Ending', 'end' => $future, 'fallback_id' => $fallback));
    wp_dequeue_script(Simple_Google_Adsense_Frontend::LIBRARY_HANDLE);
    $html = $M::render_unit($U::get($ending), array());
    check('F5 running ad keeps its fallback in a template', false !== strpos($html, '<div class="afx-unit') && false !== strpos($html, '<template class="afx-sch-fb">'), $html);
    check('F5 unused fallback does not enqueue Google\'s library', !wp_script_is(Simple_Google_Adsense_Frontend::LIBRARY_HANDLE, 'enqueued'));
    $ended = $make('Ended', array('type' => 'text', 'headline' => 'Ended', 'end' => $past, 'fallback_id' => $fallback));
    $html = $M::render_unit($U::get($ended), array());
    check('F5 ended ad prints its fallback directly', false === strpos($html, '<template') && false !== strpos($html, 'data-ad-slot="123"'), $html);
    $plain = $make('Plain', array('type' => 'text', 'headline' => 'Plain', 'url' => 'https://example.com/'));
    $html = $M::render_unit($U::get($plain), array());
    check('F5 unscheduled ad unchanged', false === strpos($html, '<template') && 0 === strpos($html, '<div class="afx-unit afx-t-text"'), $html);
    $member = $make('Member', array('type' => 'text', 'headline' => 'Member', 'start' => $future));
    $group = $make('Group', array('type' => 'group', 'members' => array(array('id' => $member, 'weight' => 1))));
    $html = $M::render_unit($U::get($group), array());
    check('F5 future group member stays in the group with its date', false !== strpos($html, 'Member') && false !== strpos($html, 'data-afx-s='), $html);

    // F6.
    $count = function ($html) use ($P) {
        return $P::count_paragraphs($html);
    };
    check('F6 columns not counted', 2 === $count('<p>a</p><div class="wp-block-columns"><div class="wp-block-column"><p>b</p></div><div class="wp-block-column"><p>c</p></div></div><p>d</p>'));
    check('F6 cover not counted', 1 === $count('<div class="wp-block-cover alignfull"><div class="wp-block-cover__inner-container"><p>a</p></div></div><p>b</p>'));
    check('F6 another ad not counted', 1 === $count('<p>a</p><div class="afx-unit afx-t-text"><div class="afx-text"><p class="afx-body">b</p></div></div>'));
    check('F6 template not counted', 1 === $count('<template><p>a</p></template><p>b</p>'));
    check('F6 script not counted', 1 === $count('<script>var s="<p>a</p>";</script><p>b</p>'));
    check('F6 plain div and group still counted', 3 === $count('<div class="wp-block-group"><p>a</p></div><div><p>b</p></div><p>c</p>'));
    check('F6 quote and list still skipped', 1 === $count('<blockquote><p>a</p></blockquote><ul><li><p>b</p></li></ul><p>c</p>'));
    check('F6 unbalanced div falls back to counting', 2 === $count('<div class="wp-block-columns"><p>a</p><p>b</p>'));
    $in = '<p>a</p><div class="wp-block-columns"><p>b</p></div><p>c</p>';
    check('F6 ad goes after the second real paragraph', '<p>a</p><div class="wp-block-columns"><p>b</p></div><p>c</p>AD' === $P::insert_after_nth_paragraph($in, 'AD', 2));

    // F7, F16, F23.
    $settings(array('publisher_id' => 'PUB-1234567890123456'));
    check('F16 uppercase PUB- accepted', $S::is_publisher_id_valid() && 'ca-pub-1234567890123456' === $S::get_ad_client(), $S::get_ad_client());
    $settings(array('publisher_id' => 'CA-PUB-1234567890123456'));
    check('F16 uppercase CA-PUB- accepted', 'ca-pub-1234567890123456' === $S::get_ad_client());
    $settings(array('publisher_id' => '1234567890123456'));
    check('F16 bare number still accepted', 'pub-1234567890123456' === $S::get_publisher_id());
    delete_transient($T::CHECK_TRANSIENT);
    $settings(array('publisher_id' => 'pub-nonsense'));
    check('F7 invalid ID: Auto Ads not enabled', !$S::is_auto_ads_enabled());
    $cards = Simple_Google_Adsense_Dashboard::get_cards();
    check('F7 invalid ID: card does not say On', __('Not running', 'simple-google-adsense') === $cards['auto_ads']['value'], $cards['auto_ads']);
    // A site with active placements legitimately ticks the step: test without any.
    $f7_placements = get_option(Simple_Google_Adsense_Placements::OPTION_NAME);
    update_option(Simple_Google_Adsense_Placements::OPTION_NAME, array());
    $steps = wp_list_pluck(Simple_Google_Adsense_Dashboard::get_setup_steps(), 'done', 'key');
    update_option(Simple_Google_Adsense_Placements::OPTION_NAME, $f7_placements);
    check('F7 invalid ID: ads step not ticked', empty($steps['ads']), $steps);
    check('F12 invalid ID not served in ads.txt', '' === $T::get_adsense_line() && false === strpos($T::get_content(), 'nonsense'));
    check('F12 invalid ID: checklist not ticked', empty($steps['ads_txt']), $steps);
    update_option($S::OPTION_NAME, array());
    $cards = Simple_Google_Adsense_Dashboard::get_cards();
    check('F23 fresh install: card agrees with the toggle', __('Not running', 'simple-google-adsense') === $cards['auto_ads']['value'] && 'warning' === $cards['auto_ads']['status']);
    $settings(array('enable_auto_ads' => false));
    $cards = Simple_Google_Adsense_Dashboard::get_cards();
    check('F23 switched off: card says Off', __('Off', 'simple-google-adsense') === $cards['auto_ads']['value']);

    // F12: a wrong file does not tick the step.
    $settings(array());
    update_option($T::OPTION_NAME, array('enabled' => true, 'custom_lines' => ''));
    $wrong = function () {
        return array('response' => array('code' => 200, 'message' => 'OK'), 'body' => "google.com, pub-9999999999999999, DIRECT, f08c47fec0942fa0\n", 'headers' => array(), 'cookies' => array());
    };
    add_filter('pre_http_request', $wrong);
    delete_transient($T::CHECK_TRANSIENT);
    $steps = wp_list_pluck(Simple_Google_Adsense_Dashboard::get_setup_steps(), 'done', 'key');
    check('F12 live file with another publisher: not ticked', empty($steps['ads_txt']), $steps);
    remove_filter('pre_http_request', $wrong);
    $right = function () {
        return array('response' => array('code' => 200, 'message' => 'OK'), 'body' => "google.com, pub-1234567890123456, DIRECT, f08c47fec0942fa0\n", 'headers' => array(), 'cookies' => array());
    };
    add_filter('pre_http_request', $right);
    delete_transient($T::CHECK_TRANSIENT);
    $steps = wp_list_pluck(Simple_Google_Adsense_Dashboard::get_setup_steps(), 'done', 'key');
    check('F12 correct live file: ticked', !empty($steps['ads_txt']), $steps);
    remove_filter('pre_http_request', $right);

    // F8: Google's AMP code, and nothing on normal pages.
    $amp = Simple_Google_Adsense_Amp::ad_element(array('data-ad-client' => 'ca-pub-1', 'data-ad-slot' => '2', 'data-ad-format' => 'auto'));
    check('F8 responsive amp-ad', false !== strpos($amp, 'width="100vw"') && false !== strpos($amp, 'type="adsense"') && false !== strpos($amp, 'data-auto-format="rspv"') && false !== strpos($amp, '<div overflow=""></div>'), $amp);
    $amp = Simple_Google_Adsense_Amp::ad_element(array('data-ad-client' => 'ca-pub-1', 'data-ad-slot' => '2', 'data-ad-format' => 'autorelaxed'));
    check('F8 multiplex amp-ad', false !== strpos($amp, 'data-auto-format="mcrspv"'), $amp);
    $amp = Simple_Google_Adsense_Amp::ad_element(array('data-ad-client' => 'ca-pub-1', 'data-ad-slot' => '2', 'data-ad-format' => 'rectangle'));
    check('F8 fixed amp-ad', false !== strpos($amp, 'width="300"') && false !== strpos($amp, 'height="250"'), $amp);
    if (!function_exists('amp_is_request')) {
        check('F8 not an AMP request without the plugin', !$S::is_amp());
        ob_start();
        Simple_Google_Adsense_Amp::instance()->print_auto_ads();
        check('F8 no amp-auto-ads on normal pages', '' === ob_get_clean());
    }
    $html = $M::render_unit($U::get($fallback), array());
    check('F8 normal page keeps the ins', false !== strpos($html, '<ins class="adsbygoogle"') && false === strpos($html, 'amp-ad'), $html);

    // F13.
    $old_member = $make('OldMember', array('type' => 'text', 'headline' => 'Old', 'end' => $past));
    $dead = $make('DeadGroup', array('type' => 'group', 'members' => array(array('id' => $old_member, 'weight' => 1))));
    $errors = wp_list_filter($U::diagnose($U::get($dead)), array('status' => 'error'));
    check('F13 group with only an ended member says No', count($errors) > 0, $U::diagnose($U::get($dead)));
    $errors = wp_list_filter($U::diagnose($U::get($later)), array('status' => 'error'));
    check('F13 not started with a fallback is not "No"', 0 === count($errors), $U::diagnose($U::get($later)));
    update_option($C::OPTION_NAME, array('wait' => true));
    $text = implode(' ', wp_list_pluck($U::diagnose($U::get($fallback)), 'text'));
    check('F13 AdSense unit mentions waiting for consent', false !== strpos($text, 'Load ads after consent'), $text);
    $text = implode(' ', wp_list_pluck($U::diagnose($U::get($plain)), 'text'));
    check('F13 own text ad does not', false === strpos($text, 'Load ads after consent'), $text);
    delete_option($C::OPTION_NAME);

    // F14: new blocks get the right code, saved ones stay as they are.
    $manual = Simple_Google_Adsense_Manual_Ads::instance();
    $html = $manual->render_block(array('adSlot' => '77', 'adType' => 'inarticle', 'adFormat' => 'auto', 'typedMarkup' => true));
    check('F14 typed in-article markup', false !== strpos($html, 'data-ad-layout="in-article"'), $html);
    $html = $manual->render_block(array('adSlot' => '77', 'adType' => 'matched_content', 'adFormat' => 'auto', 'typedMarkup' => true));
    check('F14 typed multiplex markup', false !== strpos($html, 'data-ad-format="autorelaxed"'), $html);
    $html = $manual->render_block(array('adSlot' => '77', 'adType' => 'inarticle', 'adFormat' => 'auto'));
    check('F14 saved block keeps its markup', false === strpos($html, 'in-article') && false !== strpos($html, 'data-ad-format="auto"'), $html);

    // F15.
    $role = 'adflow_test_reports';
    add_role($role, 'T', array('read' => true, Simple_Google_Adsense_Caps::ACCESS => true, Simple_Google_Adsense_Caps::VIEW_REPORTS => true));
    $user = wp_insert_user(array('user_login' => 'adflow_t_' . wp_generate_password(6, false), 'user_pass' => wp_generate_password(), 'role' => $role));
    wp_set_current_user($user);
    $A = 'Simple_Google_Adsense_Admin';
    check('F15 no settings link for a reports role', !$A::can_open($A::settings_url('ads-txt')));
    check('F15 no upgrade link for a reports role', !$A::can_open(admin_url('admin.php?page=' . $A::PRO_SLUG)));
    check('F15 no new-ad link for a reports role', !$A::can_open(admin_url('post-new.php?post_type=' . $U::POST_TYPE)));
    check('F15 reports link stays', $A::can_open(admin_url('admin.php?page=' . Simple_Google_Adsense_Reports::PAGE_SLUG)));
    wp_set_current_user(1);
    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user($user);
    remove_role($role);

    // F2.
    check('F2 add-new route registered', has_action('admin_menu', array(Simple_Google_Adsense_Ad_Units::instance(), 'register_add_new_route')) > 0);

    // F3, F18, C3.
    $options_parsed = Simple_Google_Adsense_Migrate::adinserter_rotation_options("<b>A</b>\n[ADINSERTER ROTATE name=\"Two\" share=\"30\"]\n<b>B</b>|rotate|<b>C</b>");
    check('F3 three options split', 3 === count($options_parsed), $options_parsed);
    check('F3 option name and share read', 'Two' === $options_parsed[1]['name'] && 30 === $options_parsed[1]['share'], $options_parsed);
    update_option($S::OPTION_NAME, array());
    delete_option(Simple_Google_Adsense_Migrate::MAP_OPTION);
    delete_option($P::OPTION_NAME);
    update_option('ad_inserter', array(
        1 => array('name' => 'Rot', 'code' => '[ADINSERTER ROTATE name="One" share="25"]<div>opt one</div>[ADINSERTER ROTATE name="Two"]<div>opt two</div>', 'display_type' => 0),
        2 => array('name' => 'Top', 'code' => '<ins class="adsbygoogle" data-ad-client="ca-pub-1234567890123456" data-ad-slot="321" data-ad-format="auto"></ins>', 'display_type' => 1),
        3 => array('name' => 'Bottom', 'code' => '<div>bottom [ADINSERTER COUNT] more</div>', 'display_type' => 2),
    ));
    $report = Simple_Google_Adsense_Migrate::instance()->run('adinserter', array('placements' => true));
    $map = get_option(Simple_Google_Adsense_Migrate::MAP_OPTION);
    foreach ($report['created'] as $row) {
        $ids[] = $row['id'];
    }
    $types = wp_list_pluck($report['created'], 'type');
    check('F3 rotation became a group', in_array('group', $types, true), $types);
    $bodies = '';
    foreach ($report['created'] as $row) {
        $meta = get_post_meta($row['id'], $U::META_KEY, true);
        $bodies .= isset($meta['code']) ? $meta['code'] : '';
        if ('group' === $row['type']) {
            $weights = wp_list_pluck($meta['members'], 'weight');
        }
    }
    check('F3 no Ad Inserter token left on the page', false === stripos($bodies, '[ADINSERTER'), $bodies);
    check('F3 share becomes weight', isset($weights) && array(25, 75) === array_values($weights), isset($weights) ? $weights : null);
    $stored = get_option($P::OPTION_NAME);
    check('F18 before post mapped', !empty($stored['before_content']['enabled']), $stored);
    check('F18 after post mapped', !empty($stored['after_content']['enabled']), $stored);
    check('F18 report explains the move', false !== strpos(implode(' ', $report['notes']), 'outside the post content'), $report['notes']);
    check('C3 import copies the ID', 'pub-1234567890123456' === $S::get_publisher_id());
    check('C3 import does not start Auto Ads', !$S::get('enable_auto_ads') && !$S::is_auto_ads_enabled());
    check('C3 report says so', false !== strpos(implode(' ', $report['notes']), 'Auto Ads stay off'), $report['notes']);

    // F17.
    $_POST = array(
        'adflow_ad_unit_nonce' => wp_create_nonce('adflow_save_ad_unit'),
        'adflow_ad' => array('type' => 'custom', 'code' => '<ins class="adsbygoogle"></ins>', 'disclosure' => 'sponsored'),
    );
    $pasted = wp_insert_post(array('post_type' => $U::POST_TYPE, 'post_status' => 'draft', 'post_title' => 'Pasted'));
    $ids[] = $pasted;
    delete_post_meta($pasted, $U::META_KEY);
    Simple_Google_Adsense_Ad_Units::instance()->save($pasted, get_post($pasted));
    $meta = get_post_meta($pasted, $U::META_KEY, true);
    check('F17 pasted AdSense code gets no extra label', 'none' === $meta['disclosure'], $meta);
    $_POST['adflow_ad']['disclosure'] = 'advertisement';
    Simple_Google_Adsense_Ad_Units::instance()->save($pasted, get_post($pasted));
    $meta = get_post_meta($pasted, $U::META_KEY, true);
    check('F17 later saves respect the choice', 'advertisement' === $meta['disclosure'], $meta);
    $_POST = array();

    // F19, F21, F22, P24.
    check('F22 no Pro row in Site health', !isset(Simple_Google_Adsense_Admin::get_health_checks()['pro_click_protection']));
    check('F22 old method still there', array() === Simple_Google_Adsense_Upsell::instance()->health_checks(array()));
    update_user_meta(1, 'adflow_pro_card_dismissed', 1);
    $card = Simple_Google_Adsense_Upsell::instance()->dashboard_card(array());
    delete_user_meta(1, 'adflow_pro_card_dismissed');
    check('F22 dashboard Pro card can be hidden', array() === $card);
    $mail = array();
    $trap = function ($args) use (&$mail) {
        $mail = $args;
        $args['to'] = array();
        return $args;
    };
    $stop = function () {
        return false;
    };
    add_filter('wp_mail', $trap);
    add_filter('pre_wp_mail', $stop);
    $post = (object) array('ID' => 1, 'post_type' => $U::POST_TYPE, 'post_title' => 'Tom &amp; Jerry', 'post_author' => 1);
    Simple_Google_Adsense_Caps::instance()->notify_review('pending', 'draft', new WP_Post($post));
    remove_filter('wp_mail', $trap);
    remove_filter('pre_wp_mail', $stop);
    check('P24 review subject has no entity', isset($mail['subject']) && false !== strpos($mail['subject'], 'Tom & Jerry') && false === strpos($mail['subject'], '&amp;'), $mail);

    // Clean up.
    foreach (array_unique($ids) as $id) {
        wp_delete_post($id, true);
    }
    foreach ($saved as $option => $value) {
        if ('__none__' === $value) {
            delete_option($option);
        } else {
            update_option($option, $value);
        }
    }
    if ($transient) {
        set_transient($T::CHECK_TRANSIENT, $transient, HOUR_IN_SECONDS);
    } else {
        delete_transient($T::CHECK_TRANSIENT);
    }
    wp_set_current_user($previous_user);
}
$adflow_test_sections[] = 'adflow_test_free_correctness';

/**
 * Regression and integration QA of the 1.6.0 release candidate.
 */
function adflow_test_release_qa()
{
    global $wp_query;

    $S = 'Simple_Google_Adsense_Stats';
    $C = 'Simple_Google_Adsense_Consent';
    $U = 'Simple_Google_Adsense_Ad_Units';
    $saved = array();
    foreach (array($C::OPTION_NAME, Simple_Google_Adsense_Ads_Txt::OPTION_NAME) as $option) {
        $saved[$option] = get_option($option, '__none__');
    }

    // Multisite: core's allowed_redirect_hosts filter replaces the list with the network's domain.
    $network_only = function () {
        return array('network.example:8080');
    };
    add_filter('allowed_redirect_hosts', $network_only);
    $own = home_url();
    check('QA mapped domain counts when core filters hosts to the network', $S::origin_allowed($own));
    check('QA network domain with a port still matches', $S::origin_allowed('https://network.example'));
    check('QA another site is still refused', !$S::origin_allowed('https://evil.example'));
    remove_filter('allowed_redirect_hosts', $network_only);

    // Feeds run no scripts: nothing waits in a <template> there.
    $make = function ($title, $data) use ($U) {
        $id = wp_insert_post(array('post_type' => $U::POST_TYPE, 'post_title' => $title, 'post_status' => 'publish'));
        update_post_meta($id, $U::META_KEY, array_merge($U::defaults(), $data));

        return $id;
    };
    $fallback = $make('QA fallback', array('type' => 'display', 'slot' => '1234567890'));
    $future = $make('QA future', array('type' => 'text', 'headline' => 'QA FUTURE', 'url' => 'https://example.com/', 'start' => wp_date('Y-m-d\TH:i', time() + DAY_IN_SECONDS)));
    $future_fb = $make('QA future fb', array('type' => 'text', 'headline' => 'QA FUTURE', 'url' => 'https://example.com/', 'start' => wp_date('Y-m-d\TH:i', time() + DAY_IN_SECONDS), 'fallback_id' => $fallback));
    $ending = $make('QA ending', array('type' => 'text', 'headline' => 'QA ENDING', 'url' => 'https://example.com/', 'end' => wp_date('Y-m-d\TH:i', time() + DAY_IN_SECONDS), 'fallback_id' => $fallback));
    $M = 'Simple_Google_Adsense_Manual_Ads';
    $was_feed = $wp_query->is_feed;
    check('QA web page: future ad waits in a template', false !== strpos($M::render_unit($U::get($future), array()), '<template'));
    $wp_query->is_feed = true;
    check('QA feed: future ad prints nothing', '' === $M::render_unit($U::get($future), array()));
    $html = $M::render_unit($U::get($future_fb), array());
    check('QA feed: future ad with fallback prints no template and not the ad', false === strpos($html, '<template') && false === strpos($html, 'QA FUTURE'), $html);
    $html = $M::render_unit($U::get($ending), array());
    check('QA feed: running ad carries no template', false === strpos($html, '<template') && false !== strpos($html, 'QA ENDING'), $html);
    $wp_query->is_feed = $was_feed;

    // Consent: an unreadable or missing tool is said out loud on the ad too.
    $tools = function () {
        return array('cookie-notice/cookie-notice.php');
    };
    update_option($C::OPTION_NAME, array('wait' => true, 'google_message' => false));
    check('QA no tool and no Google message holds everyone', $C::holds_everyone());
    $errors = wp_list_filter($U::diagnose($U::get($fallback)), array('status' => 'error'));
    check('QA "Will this ad show?" says No while everyone is held', count($errors) > 0, $U::diagnose($U::get($fallback)));
    update_option($C::OPTION_NAME, array('wait' => true, 'google_message' => true));
    check('QA Google message: not held', !$C::holds_everyone());
    update_option($C::OPTION_NAME, array('wait' => false));
    check('QA wait off: not held', !$C::holds_everyone());
    update_option($C::OPTION_NAME, array('wait' => false, 'consent_mode' => false));
    ob_start();
    $C::instance()->held_notice();
    check('QA wait off: no notice', '' === ob_get_clean());

    // A selector slot stays out of other ads and away from their sides.
    $fx = file_get_contents(SIMPLE_GOOGLE_ADSENSE_ABSPATH . 'assets/js/fx.js');
    check('QA selector slots refuse targets in or beside an ad', false !== strpos($fx, 'AD_BOXES') && false !== strpos($fx, "near.matches( AD_BOXES )"));

    // Subdirectory install: no toggle that cannot work.
    $sub = function ($url) {
        return preg_replace('#^(https?://[^/]+)#', '$1/sub', $url);
    };
    $T = 'Simple_Google_Adsense_Ads_Txt';
    update_option($T::OPTION_NAME, array('enabled' => true, 'custom_lines' => ''));
    add_filter('home_url', $sub);
    delete_transient($T::CHECK_TRANSIENT);
    ob_start();
    $T::instance()->render_tab();
    $tab = ob_get_clean();
    remove_filter('home_url', $sub);
    delete_transient($T::CHECK_TRANSIENT);
    check('QA subdirectory: says upload it by hand', false !== strpos($tab, 'subdirectory'));
    check('QA subdirectory: no "no FTP needed" toggle', false === strpos($tab, 'no FTP needed'));
    check('QA subdirectory: stored choice survives a save', false !== strpos($tab, 'type="hidden" name="' . $T::OPTION_NAME . '[enabled]" value="1"'));

    foreach (array($fallback, $future, $future_fb, $ending) as $id) {
        wp_delete_post($id, true);
    }
    foreach ($saved as $option => $value) {
        if ('__none__' === $value) {
            delete_option($option);
        } else {
            update_option($option, $value);
        }
    }
}
$adflow_test_sections[] = 'adflow_test_release_qa';

/**
 * The upgrade page tells buyers how to install Pro and makes no overstated claim.
 */
function adflow_test_upgrade_page()
{
    $admin = get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ids'));
    wp_set_current_user((int) $admin[0]);
    ob_start();
    Simple_Google_Adsense_Upsell::render_pro_page();
    $html = (string) ob_get_clean();
    adflow_check('upgrade page: already bought panel', false !== strpos($html, 'adflow-pro-bought'));
    adflow_check('upgrade page: links to the store account', false !== strpos($html, 'store.mantrabrain.com/account/'));
    adflow_check('upgrade page: links to the upload screen', false !== strpos($html, 'plugin-install.php?tab=upload'));
    adflow_check('upgrade page: no overstated claims', false === stripos($html, 'before Google') && false === stripos($html, 'winning ad unit'));
    wp_set_current_user(0);
}
$adflow_test_sections[] = 'adflow_test_upgrade_page';

/**
 * The Delivery box itself is never hidden by AdFlow (Screen Options owns it);
 * only its content follows the ad type.
 */
function adflow_test_delivery_box_screen_options()
{
    $units = Simple_Google_Adsense_Ad_Units::instance();
    foreach (array('image' => true, 'display' => false) as $type => $own) {
        $id = wp_insert_post(array('post_type' => Simple_Google_Adsense_Ad_Units::POST_TYPE, 'post_status' => 'draft', 'post_title' => 'Delivery test ' . $type));
        update_post_meta($id, Simple_Google_Adsense_Ad_Units::META_KEY, array('type' => $type));
        ob_start();
        $units->render_delivery_box(get_post($id));
        $html = (string) ob_get_clean();
        wp_delete_post($id, true);
        adflow_check("delivery box ($type): schedule fields are rendered", false !== strpos($html, 'id="adflow-start"'));
        adflow_check("delivery box ($type): content " . ($own ? 'shown' : 'hidden') . ' for this type', (bool) preg_match('/class="adflow-delivery-own"\s*' . ($own ? '>' : 'hidden>') . '/', $html));
    }
    $js = (string) file_get_contents(dirname(__DIR__) . '/assets/js/admin-ad-unit.js');
    adflow_check('admin-ad-unit.js never hides the Delivery postbox itself', false === strpos($js, "show( '#adflow-ad-unit-delivery'"));
    adflow_check('admin-ad-unit.js also reacts to jQuery-triggered type changes', false !== strpos($js, "jQuery( type ).on( 'change'"));
}
$adflow_test_sections[] = 'adflow_test_delivery_box_screen_options';

// ---- Run (keep this block last) ----
foreach ($adflow_test_sections as $adflow_test_section) {
    echo '== ' . $adflow_test_section . "\n";
    call_user_func($adflow_test_section);
}

echo $GLOBALS['adflow_test_passed'] . ' passed, ' . $GLOBALS['adflow_test_failed'] . " failed\n";
