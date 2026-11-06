<?php
/**
 * Simple_Google_Adsense_Docs
 *
 * AdFlow → Docs: the complete documentation for AdFlow and AdFlow Pro,
 * inside WordPress, searchable, with links straight to the right screen.
 * Pro articles carry a "Pro" badge and, while Pro is not active, a link to
 * get it.
 *
 * @package Simple_Google_Adsense
 * @since   1.4.0
 */

defined('ABSPATH') || exit;

/**
 * Simple_Google_Adsense_Docs Class.
 *
 * @class Simple_Google_Adsense_Docs
 */
final class Simple_Google_Adsense_Docs
{

    const PAGE_SLUG = 'adflow-docs';

    /**
     * The single instance of the class.
     *
     * @var Simple_Google_Adsense_Docs
     * @since 1.4.0
     */
    protected static $_instance = null;

    /**
     * Main Simple_Google_Adsense_Docs Instance.
     *
     * @return Simple_Google_Adsense_Docs
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
     * Simple_Google_Adsense_Docs Constructor.
     */
    public function __construct()
    {
        add_action('admin_menu', array($this, 'add_menu'), 30);
        add_filter('adflow_menu_order', array($this, 'menu_order'));
    }

    /**
     * Add the Docs page.
     *
     * @since 1.4.0
     */
    public function add_menu()
    {
        add_submenu_page(
            Simple_Google_Adsense_Admin::MENU_SLUG,
            __('AdFlow Docs', 'simple-google-adsense'),
            __('Docs', 'simple-google-adsense'),
            Simple_Google_Adsense_Caps::ACCESS,
            self::PAGE_SLUG,
            array($this, 'render')
        );
    }

    /**
     * Put Docs after Settings.
     *
     * @param string[] $order Slugs.
     * @return string[]
     * @since 1.4.0
     */
    public function menu_order($order)
    {
        $at = array_search(Simple_Google_Adsense_Admin::SETTINGS_SLUG, $order, true);
        array_splice($order, false === $at ? count($order) : $at + 1, 0, array(self::PAGE_SLUG));

        return $order;
    }

    /**
     * Link to a Docs article.
     *
     * @param string $article Article ID.
     * @return string
     * @since 1.4.0
     */
    public static function url($article = '')
    {
        return admin_url('admin.php?page=' . self::PAGE_SLUG) . ('' !== $article ? '#' . $article : '');
    }

    /*
    |--------------------------------------------------------------------------
    | Content
    |--------------------------------------------------------------------------
    */

    /**
     * Sections and their articles.
     *
     * Each article: title, pro (bool), blocks. A block is one of:
     * array('p', text) · array('steps', text[]) · array('list', text[]) ·
     * array('code', text) · array('note', text) · array('links', array(label => url)).
     * Text may contain <code>, <strong> and <em>.
     *
     * @return array section title => array( article id => article )
     * @since 1.4.0
     */
    public static function sections()
    {
        $A = 'Simple_Google_Adsense_Admin';
        $units = admin_url('edit.php?post_type=' . Simple_Google_Adsense_Ad_Units::POST_TYPE);
        $new_unit = admin_url('post-new.php?post_type=' . Simple_Google_Adsense_Ad_Units::POST_TYPE);
        $placements = admin_url('admin.php?page=' . Simple_Google_Adsense_Placements::PAGE_SLUG);
        $reports = admin_url('admin.php?page=' . Simple_Google_Adsense_Reports::PAGE_SLUG);

        $sections = array(
            __('Getting started', 'simple-google-adsense') => array(
                'quick-start' => array(
                    'title' => __('Quick start (5 minutes)', 'simple-google-adsense'),
                    'blocks' => array(
                        array('steps', array(
                            __('Go to <strong>AdFlow → Settings → General</strong> and paste your AdSense Publisher ID (<code>pub-1234567890123456</code> - the <code>ca-pub-</code> form works too).', 'simple-google-adsense'),
                            __('Turn on <strong>Auto Ads</strong> if you want Google to place ads for you, or leave it off and place ads yourself.', 'simple-google-adsense'),
                            __('Open <strong>Settings → ads.txt</strong> and turn it on, so AdSense stops warning "Earnings at risk".', 'simple-google-adsense'),
                            __('Create an ad unit under <strong>AdFlow → Ad Units → Add</strong> with the slot ID from AdSense, then put it in a placement (AdFlow → Placements) or add the "AdFlow Ad" block to a post.', 'simple-google-adsense'),
                            __('Check the <strong>Dashboard</strong>: it lists anything that could stop ads from showing.', 'simple-google-adsense'),
                        )),
                        array('note', __('New AdSense accounts can take a few days to show ads. Empty spaces during that time are normal.', 'simple-google-adsense')),
                        array('links', array(
                            __('Open Settings', 'simple-google-adsense') => $A::settings_url(),
                            __('Open Dashboard', 'simple-google-adsense') => $A::dashboard_url(),
                        )),
                    ),
                ),
                'publisher-id' => array(
                    'title' => __('Find your Publisher ID and ad slot IDs', 'simple-google-adsense'),
                    'blocks' => array(
                        array('p', __('<strong>Publisher ID:</strong> in AdSense, open Account → Settings → Account information. It looks like <code>pub-1234567890123456</code>.', 'simple-google-adsense')),
                        array('p', __('<strong>Ad slot ID:</strong> in AdSense, open Ads → By ad unit, create a unit (Display, In-feed, In-article or Multiplex) and copy the number after <code>data-ad-slot</code> in the code Google shows you.', 'simple-google-adsense')),
                        array('p', __('In-feed ads also have a <strong>layout key</strong> (<code>data-ad-layout-key</code>); copy it into the ad unit too.', 'simple-google-adsense')),
                        array('links', array(__('Google: create a display ad unit', 'simple-google-adsense') => 'https://support.google.com/adsense/answer/9274025')),
                    ),
                ),
                'auto-ads' => array(
                    'title' => __('Auto Ads', 'simple-google-adsense'),
                    'blocks' => array(
                        array('p', __('Auto Ads let Google choose where ads go. Turn them on under Settings → General. You can exclude post types (e.g. products) and single pages by ID, and hide ads from logged-in administrators so you never click your own ads.', 'simple-google-adsense')),
                        array('p', __('Which formats Auto Ads use (anchor, vignette, in-page) is set in your AdSense account under Ads → By site.', 'simple-google-adsense')),
                        array('note', __('You can combine Auto Ads with your own placements. Google fills the rest of the page around them.', 'simple-google-adsense')),
                        array('p', __('<strong>Manual ads</strong> (placements, the block, widgets and shortcodes) have their own <em>Pages without manual ads</em> setting under Settings → General → Manual ads. Pick content types, or list posts and pages by ID, and no manual ad shows there, including ads in sidebar widgets. The two settings are independent, so a page can keep Auto Ads and drop manual ads, or the other way round.', 'simple-google-adsense')),
                    ),
                ),
                'ads-txt' => array(
                    'title' => __('ads.txt', 'simple-google-adsense'),
                    'blocks' => array(
                        array('p', __('ads.txt tells buyers you are allowed to sell ads on your site. Without it, AdSense shows "Earnings at risk". Turn it on under <strong>Settings → ads.txt</strong>: AdFlow serves the Google line from your Publisher ID and any extra lines you add for other networks.', 'simple-google-adsense')),
                        array('p', __('The <strong>Check live file</strong> button fetches <code>/ads.txt</code> the way Google does. If a physical ads.txt file exists in your site root, it wins - delete it or copy its lines into AdFlow.', 'simple-google-adsense')),
                        array('links', array(__('Open ads.txt settings', 'simple-google-adsense') => $A::settings_url('ads-txt'))),
                    ),
                ),
            ),

            __('Ads & placements', 'simple-google-adsense') => array(
                'ad-units' => array(
                    'title' => __('Ad units', 'simple-google-adsense'),
                    'blocks' => array(
                        array('p', __('An ad unit is an ad you set up once and reuse everywhere: in placements, the block, the widget and shortcodes. Change it once and every place updates.', 'simple-google-adsense')),
                        array('list', array(
                            __('<strong>Google AdSense:</strong> Display, In-article, In-feed and Multiplex, using the same ad code attributes Google gives you. A block with no saved unit gets the code for its type too.', 'simple-google-adsense'),
                            __('<strong>Your own ads:</strong> Image banner, Text ad, Custom code (any network, HTML/JS - needs the "unfiltered HTML" permission).', 'simple-google-adsense'),
                            __('<strong>Rotation group:</strong> several ads take turns.', 'simple-google-adsense'),
                            __('<strong>Google Ad Manager (GPT)</strong> - with AdFlow Pro.', 'simple-google-adsense'),
                        )),
                        array('p', __('The <strong>Will this ad show?</strong> box on each ad lists everything that could stop it: missing Publisher ID or slot, schedule, a group with nothing left to show, waiting for consent, targeting, placement.', 'simple-google-adsense')),
                        array('links', array(__('Add an ad unit', 'simple-google-adsense') => $new_unit)),
                    ),
                ),
                'shortcodes' => array(
                    'title' => __('Block, widget & shortcodes', 'simple-google-adsense'),
                    'blocks' => array(
                        array('p', __('<strong>Block:</strong> add the "AdFlow Ad" block in the editor and pick an ad unit. <strong>Widget:</strong> Appearance → Widgets → "AdFlow Ad".', 'simple-google-adsense')),
                        array('code', '[adflow id="123"]'),
                        array('p', __('Shortcodes from earlier versions keep working:', 'simple-google-adsense')),
                        array('code', "[adsense ad_slot=\"1234567890\"]\n[adsense_inarticle ad_slot=\"1234567890\"]\n[adsense_banner ad_slot=\"1234567890\"]\n[adsense_infeed ad_slot=\"1234567890\" layout_key=\"-fb+5w+4e-db+86\"]\n[adsense_multiplex ad_slot=\"1234567890\"]"),
                        array('p', __('Optional attributes: <code>style</code> and <code>class</code>.', 'simple-google-adsense')),
                    ),
                ),
                'placements' => array(
                    'title' => __('Automatic placements', 'simple-google-adsense'),
                    'blocks' => array(
                        array('p', __('Placements insert an ad unit into every post without editing posts: <strong>before content</strong>, <strong>after paragraph N</strong> and <strong>after content</strong>, for the post types you choose. Posts shorter than N paragraphs are skipped.', 'simple-google-adsense')),
                        array('p', __('Put a rotation group in a placement to rotate several ads in the same spot.', 'simple-google-adsense')),
                        array('links', array(__('Open Placements', 'simple-google-adsense') => $placements)),
                    ),
                ),
                'extra-ads' => array(
                    'title' => __('More ads inside posts', 'simple-google-adsense'),
                    'blocks' => array(
                        array('p', __('Under <strong>AdFlow → Placements → More ads inside posts</strong>, choose <strong>Add an ad</strong> as often as you need. Each one has its own ad unit, post types and conditions, and starts working once you save.', 'simple-google-adsense')),
                        array('list', array(
                            __('<strong>Every few paragraphs</strong>: for example every 4 paragraphs, at most 3 per post. Never after the last paragraph.', 'simple-google-adsense'),
                            __('<strong>After one paragraph</strong>: a second, third or fourth fixed spot.', 'simple-google-adsense'),
                            __('<strong>Before or after an HTML element</strong>: see below.', 'simple-google-adsense'),
                        )),
                        array('p', __('Posts shorter than <em>Skip posts shorter than</em> are left alone. Two ads are never adjacent: an extra ad is skipped when any other ad, including one you added by hand, is closer than two paragraphs. Developers can change that distance with <code>adflow_content_slot_min_spacing</code>.', 'simple-google-adsense')),
                        array('note', __('Statistics for your own ads record each extra ad separately, and each ad counts towards the AdFlow Pro limit on ads per page.', 'simple-google-adsense')),
                        array('links', array(__('Open Placements', 'simple-google-adsense') => $placements)),
                    ),
                ),
                'element-ads' => array(
                    'title' => __('Before or after an HTML element', 'simple-google-adsense'),
                    'blocks' => array(
                        array('p', __('Use this when the spot is not about paragraphs, for example after the second heading. Add an ad, choose <strong>Before or after an HTML element</strong>, then enter a CSS selector such as <code>.entry-content h2</code> and which match to use (2 = the second one).', 'simple-google-adsense')),
                        array('p', __('The page holds the ad in an inert template and the browser moves it into place, so it works on cached pages. If the element is not on a page, nothing shows and no ad is requested. AdSense units use Google\'s normal code. Your own custom code runs when it is inserted.', 'simple-google-adsense')),
                        array('p', __('<strong>Reserve height</strong> keeps room for the ad so text does not jump when it loads (default 250 px; 0 for none). It goes away when AdSense reports no ad to show.', 'simple-google-adsense')),
                        array('note', __('Selectors depend on your theme and may need changing if you switch themes. An ad placed outside your content area must still follow your ad network\'s rules.', 'simple-google-adsense')),
                    ),
                ),
                'conditions' => array(
                    'title' => __('Conditions: device, category, tag, posts, language', 'simple-google-adsense'),
                    'blocks' => array(
                        array('p', __('Every placement has a <strong>Conditions</strong> section. Leave it empty to show everywhere. An ad must match every condition you set.', 'simple-google-adsense')),
                        array('list', array(
                            __('<strong>Device</strong>: mobile only, or tablet and desktop only. Mobile means a screen up to 767 px wide. It is decided in the browser, so it works behind page caches, and no ad is requested on the other device. With AdFlow Pro active, this field is in its Targeting section.', 'simple-google-adsense'),
                            __('<strong>Categories and tags</strong>: only posts with one of the chosen terms. With AdFlow Pro active, categories are in its Targeting section.', 'simple-google-adsense'),
                            __('<strong>Only on / never on these posts or pages</strong>: search by title or ID.', 'simple-google-adsense'),
                            __('<strong>Languages</strong>: shown when Polylang or WPML is active.', 'simple-google-adsense'),
                        )),
                    ),
                ),
                'per-post' => array(
                    'title' => __('Turn ads off on one post or page', 'simple-google-adsense'),
                    'blocks' => array(
                        array('p', __('Users who can manage ads see an <strong>AdFlow Ads</strong> box in the editor sidebar of every public post type: <em>No ads</em>, <em>No Auto Ads</em> and <em>No manual ads</em> on that page.', 'simple-google-adsense')),
                        array('p', __('To do it from one place, use <em>Pages without Auto Ads</em> and <em>Pages without manual ads</em> under Settings → General. Search for a post or page by title, or type its ID as before.', 'simple-google-adsense')),
                    ),
                ),
                'builders' => array(
                    'title' => __('Elementor and Bricks', 'simple-google-adsense'),
                    'blocks' => array(
                        array('p', __('Search for <strong>AdFlow Ad</strong> in the Elementor or Bricks elements panel, add it and choose a saved ad unit. The editor shows a stand-in box, so ads do not load or count while you design. The ad unit follows all your rules on the live page.', 'simple-google-adsense')),
                    ),
                ),
                'languages' => array(
                    'title' => __('Multilingual sites: Polylang and WPML', 'simple-google-adsense'),
                    'blocks' => array(
                        array('p', __('With Polylang or WPML active, each placement can be limited to chosen languages under <strong>Conditions</strong>. The simplest way to run per-language ads is one placement per language.', 'simple-google-adsense')),
                        array('p', __('AdFlow also ships a <code>wpml-config.xml</code>: if you turn on translation for Ad Units in your language plugin, a placement shows the translation of its ad unit in each language. A headline, text, button, image text and link can then differ per language. Ad units are not made translatable automatically, because ads that already run have no language set.', 'simple-google-adsense')),
                    ),
                ),
                'pro-placements' => array(
                    'title' => __('More placements: mid-article, between posts, sticky, popup, WooCommerce', 'simple-google-adsense'),
                    'pro' => true,
                    'blocks' => array(
                        array('list', array(
                            __('<strong>Middle of the article (after X%)</strong> - scales with post length.', 'simple-google-adsense'),
                            __('<strong>Between posts</strong> - every N posts on home, category, tag and search pages (classic and block themes).', 'simple-google-adsense'),
                            __('<strong>Before comments</strong>.', 'simple-google-adsense'),
                            __('<strong>Sticky anchor</strong> - a small bar at the bottom that visitors can close. AdSense display units are requested at a fixed size (320x50 on phones, 728x90 on larger screens); other AdSense unit types do not show in it. The page keeps room for it while it shows, and it collapses when Google has no ad.', 'simple-google-adsense'),
                            __('<strong>Popup</strong> - for sponsor banners and other networks, after a delay or scroll depth, once per visitor per N days. Google does not allow AdSense in popups, so AdSense units are never shown there.', 'simple-google-adsense'),
                            __('<strong>WooCommerce</strong> - above the product grid, below the product summary, after add-to-cart.', 'simple-google-adsense'),
                        )),
                        array('p', __('<strong>Sticky sidebar:</strong> turn on "Keep in view while scrolling" on an ad unit and place it last in your sidebar. On desktop it stays visible while visitors read.', 'simple-google-adsense')),
                    ),
                ),
                'targeting' => array(
                    'title' => __('Targeting, scheduling & A/B tests', 'simple-google-adsense'),
                    'pro' => true,
                    'blocks' => array(
                        array('p', __('Each placement can be limited by <strong>device</strong> (mobile/desktop), <strong>visitor</strong> (logged in/out), <strong>traffic source</strong> (search engines or not), <strong>categories</strong>, <strong>post age</strong> and <strong>start/end dates</strong>. Device and traffic rules run in the browser, so they work with page caching - and an ad that should not show never requests an ad from Google.', 'simple-google-adsense')),
                        array('p', __('<strong>A/B rotation:</strong> add extra ad units to a placement. The browser picks one per page view, so it also works on cached pages. The Earnings screen compares only the units in the same test, shows the sample size and names a winner only when each unit has at least 1,000 impressions and 10 clicks and the gap is larger than chance.', 'simple-google-adsense')),
                    ),
                ),
                'gam' => array(
                    'title' => __('Google Ad Manager (GPT) units', 'simple-google-adsense'),
                    'pro' => true,
                    'blocks' => array(
                        array('p', __('Choose the type "Google Ad Manager (GPT)" and enter the <strong>ad unit path</strong> (<code>/1234567/sidebar</code>, from Ad Manager → Inventory → Ad units → Tags), the <strong>sizes</strong> (<code>300x250, 336x280, fluid</code>) and optional <strong>mobile sizes</strong> for screens under 768px.', 'simple-google-adsense')),
                        array('p', __('<strong>Key-values</strong> (one <code>key=value</code> per line) target line items. Macros: <code>{post_id}</code>, <code>{post_type}</code>, <code>{category}</code>, <code>{placement}</code>.', 'simple-google-adsense')),
                        array('code', "section={category}\npos=sidebar"),
                        array('p', __('GPT loads once, asynchronously, only on pages that show such a unit. Whether a slot fills depends on your line items.', 'simple-google-adsense')),
                    ),
                ),
            ),

            __('Your own ads & selling', 'simple-google-adsense') => array(
                'own-ads' => array(
                    'title' => __('Banners, text ads and custom code', 'simple-google-adsense'),
                    'blocks' => array(
                        array('p', __('Run sponsor, affiliate or house ads next to AdSense. Image banners come from the Media Library; text ads have a headline, text and button. Links get <code>rel="sponsored"</code> (add nofollow if you like) and can open in a new tab.', 'simple-google-adsense')),
                        array('list', array(
                            __('<strong>Disclosure label:</strong> "Sponsored" or "Advertisement" above the ad - laws in many countries require it for paid ads.', 'simple-google-adsense'),
                            __('<strong>Schedule:</strong> start and end date and time (your site\'s time zone). Cached pages follow it too: the ad appears at the start, and at the end it goes away and its AdSense fallback appears. This takes JavaScript and uses the visitor\'s clock.', 'simple-google-adsense'),
                            __('<strong>When not running:</strong> show an AdSense unit instead, so the space keeps earning.', 'simple-google-adsense'),
                            __('Own ads use neutral class names, so ad blockers are less likely to hide them.', 'simple-google-adsense'),
                        )),
                        array('p', __('Destination links may contain tracking macros: <code>{ad_id}</code>, <code>{placement}</code>, <code>{site}</code>, <code>{cachebuster}</code>.', 'simple-google-adsense')),
                    ),
                ),
                'rotation' => array(
                    'title' => __('Rotation groups', 'simple-google-adsense'),
                    'blocks' => array(
                        array('p', __('A rotation group shows one of its ads per page view: <strong>weighted</strong> (an ad with weight 3 shows three times as often as weight 1) or <strong>in order</strong>. The choice is made in the browser, so it works with page caching.', 'simple-google-adsense')),
                        array('p', __('Only ads that may show right now take part: scheduled, capped, out-of-hours or other-country ads are skipped. Mix sponsor ads with an AdSense unit so the spot is never empty.', 'simple-google-adsense')),
                    ),
                ),
                'selling' => array(
                    'title' => __('Sell ads directly (self-serve)', 'simple-google-adsense'),
                    'pro' => true,
                    'blocks' => array(
                        array('steps', array(
                            __('Create a rotation group (Ad Units → Add → Rotation group) and put it in a placement.', 'simple-google-adsense'),
                            __('Under <strong>Settings → Sell ads</strong>, add a package: name, banner or text, size, days, price and your payment link (a Stripe Payment Link, PayPal.me or any checkout).', 'simple-google-adsense'),
                            __('Create an "Advertise with us" page with the shortcode <code>[adflow_advertise]</code>.', 'simple-google-adsense'),
                            __('Advertisers choose a package, upload their banner or write a text ad, and pay with your link (the order number is passed as <code>?order=</code>).', 'simple-google-adsense'),
                            __('Orders wait under <strong>Ad Units → Pending</strong>. Check the creative and payment ("Mark as paid"), then Publish: the ad joins the package\'s group for the paid days and stops by itself. The advertiser receives a live report link and weekly emails.', 'simple-google-adsense'),
                        )),
                        array('note', __('No card data ever reaches your site. The form works on cached pages and is protected against spam.', 'simple-google-adsense')),
                    ),
                ),
                'advertisers' => array(
                    'title' => __('Advertiser reports, emails & pricing', 'simple-google-adsense'),
                    'pro' => true,
                    'blocks' => array(
                        array('list', array(
                            __('<strong>Advertiser details & campaign price</strong> on each own ad; the sponsor eCPM is shown next to your AdSense RPM.', 'simple-google-adsense'),
                            __('<strong>Report link:</strong> a read-only page with the ad\'s statistics - no login. Create or turn it off in the ad\'s sidebar.', 'simple-google-adsense'),
                            __('<strong>Performance emails:</strong> weekly (Mondays) or monthly (the 1st) to the advertiser. "Email me a preview" shows what they get.', 'simple-google-adsense'),
                            __('<strong>Expiry reminders</strong> to you (and optionally the advertiser) 7 days before and when a campaign ends.', 'simple-google-adsense'),
                            __('<strong>Frequency cap</strong> (impressions per visitor per day) and <strong>dayparting</strong> (days and hours).', 'simple-google-adsense'),
                            __('<strong>Country targeting:</strong> "Only in" or "Everywhere except" a list of country codes. The country comes from Cloudflare or your host, or from the optional free country database (Countries field → "Use the free country database").', 'simple-google-adsense'),
                        )),
                    ),
                ),
            ),

            __('Reports & earnings', 'simple-google-adsense') => array(
                'stats' => array(
                    'title' => __('How statistics are counted', 'simple-google-adsense'),
                    'blocks' => array(
                        array('p', __('AdFlow counts impressions, viewable impressions and clicks of your own ads using industry (IAB/MRC) definitions:', 'simple-google-adsense')),
                        array('list', array(
                            __('<strong>Impression</strong> - the ad was actually displayed (for banners: the image loaded). Hidden, prefetched and prerendered pages do not count.', 'simple-google-adsense'),
                            __('<strong>Viewable</strong> - at least half of the ad was on screen for one continuous second.', 'simple-google-adsense'),
                            __('<strong>Click</strong> - a link in the ad was opened; a scroll that starts on the ad is not a click.', 'simple-google-adsense'),
                            __('<strong>Not counted</strong> - bots, headless browsers, link previews and logged-in administrators.', 'simple-google-adsense'),
                        )),
                        array('p', __('Reports store daily totals only - no IP addresses or visitor IDs - it sets no cookies and it works with page caching. To stop abuse of the counter, a one-way number that many visitors share is kept for up to a day; it cannot be turned back into an address.'
                            , 'simple-google-adsense')),
                        array('p', __('These numbers are estimates, not audited figures. The counter has to be public, so someone determined can still add to it. AdFlow makes that harder: requests need a token from your pages and must not come from another site, each visitor address has a limit per minute, unknown placements are ignored, and a day\'s clicks on an ad cannot pass its impressions. Extra clicks are set aside and shown in Reports. If all your visitors reach WordPress through one proxy address, add its address with the adflow_trusted_proxies filter (Cloudflare and private addresses are already known) or raise adflow_stats_rate_limit.'
                            , 'simple-google-adsense')),
                        array('p', __('Under Settings → Tracking you can wait for statistics consent, count clicks through a redirect link instead, and choose how long data is kept.', 'simple-google-adsense')),
                        array('note', __('AdSense statistics come from Google; AdFlow does not count AdSense impressions itself.', 'simple-google-adsense')),
                        array('links', array(__('Open Reports', 'simple-google-adsense') => $reports, __('Tracking settings', 'simple-google-adsense') => $A::settings_url('tracking'))),
                    ),
                ),
                'reports-pro' => array(
                    'title' => __('Full reports, CSV, REST API & WP-CLI', 'simple-google-adsense'),
                    'pro' => true,
                    'blocks' => array(
                        array('p', __('Custom date ranges, breakdowns by placement, device and advertiser, and CSV export on the Reports screen.', 'simple-google-adsense')),
                        array('code', "GET /wp-json/adflow/v1/ads\nGET /wp-json/adflow/v1/stats?from=2026-09-01&to=2026-09-30&by=day\n\nwp adflow ads\nwp adflow stats --days=30 --by=ad --format=csv"),
                        array('p', __('Authenticate the REST API with an Application Password (Users → Profile) of a user who may view reports.', 'simple-google-adsense')),
                    ),
                ),
                'earnings' => array(
                    'title' => __('AdSense earnings in WordPress', 'simple-google-adsense'),
                    'pro' => true,
                    'blocks' => array(
                        array('p', __('Connect your Google account under <strong>AdFlow → Earnings</strong> to see today, yesterday, this month, page RPM, clicks, top pages and per-unit earnings - plus a dashboard widget. AdFlow only asks for read-only access to AdSense reports. Until one-click sign-in is available, you first set up your own free Google Cloud project once (about 10 to 15 minutes; the steps are on that screen). Your site needs HTTPS, and Google shows an "unverified app" warning for your own project: choose Advanced and continue.', 'simple-google-adsense')),
                    ),
                ),
            ),

            __('Privacy, safety & team', 'simple-google-adsense') => array(
                'consent' => array(
                    'title' => __('GDPR: Consent Mode v2 & loading ads after consent', 'simple-google-adsense'),
                    'blocks' => array(
                        array('p', __('Google requires consent before personalised ads in the EEA, UK and Switzerland. Show the consent message with AdSense\'s own "Privacy & messaging" (a Google-certified TCF message) or a consent plugin such as Complianz, CookieYes or Cookiebot. A plugin cannot make a site compliant on its own: AdFlow makes your ads respect the answer.', 'simple-google-adsense')),
                        array('list', array(
                            __('<strong>Load ads after consent</strong> - other networks\' code and Ad Manager slots load only after the visitor agrees to advertising. The AdSense library loads once a TCF message is answered, because Google reads the choice itself; with other tools, only after the visitor agrees. No timer ever releases ads. Your own banners and text ads use no cookies and show straight away.', 'simple-google-adsense'),
                            __('<strong>Where the answer comes from</strong> - an IAB TCF message, the WP Consent API with a plugin that supports it, CookieYes, Complianz or Cookiebot. The Privacy tab warns if your tool is none of these; ads then stay held. Your tool can release them by calling <code>window.afxConsent.grant()</code> from its "accepted" callback.', 'simple-google-adsense'),
                            __('<strong>AdSense\'s own consent message</strong> - it only reaches the page with the AdSense library, so turning this on loads the library at once. If the message is not set up in AdSense, nobody is asked. Other networks\' code still waits. With no consent plugin it starts on; with one it starts off.', 'simple-google-adsense'),
                            __('<strong>Google Consent Mode v2</strong> - tells Google tags consent is "denied" until the visitor agrees and passes on their choice (from the WP Consent API, CookieYes, Complianz or Cookiebot). Use it when your banner is not a Google-certified TCF message.', 'simple-google-adsense'),
                            __('<strong>Other countries</strong> - AdFlow cannot tell where a visitor is, so they wait too unless the consent tool says consent is not needed for them.', 'simple-google-adsense'),
                        )),
                        array('links', array(__('Open Privacy & consent', 'simple-google-adsense') => $A::settings_url('privacy'))),
                    ),
                ),
                'amp' => array(
                    'title' => __('AMP pages', 'simple-google-adsense'),
                    'blocks' => array(
                        array('p', __('With the official AMP plugin, AdSense units print Google\'s <code>amp-ad</code> code and Auto Ads print <code>amp-auto-ads</code>. The AMP plugin adds the scripts they need.', 'simple-google-adsense')),
                        array('list', array(
                            __('Your own banners and text ads show as plain markup, without statistics, because AMP allows no custom scripts.', 'simple-google-adsense'),
                            __('Rotation groups show one ad, chosen on the server. A page cache keeps that choice until it is refreshed. The same goes for schedules.', 'simple-google-adsense'),
                            __('Custom code shows only if it contains an AMP element such as <code>amp-ad</code>. Pro\'s Ad Manager units and popup and sticky formats do not show.', 'simple-google-adsense'),
                            __('With "Load ads after consent" on, AdSense ads and Auto Ads are left out of AMP pages, because AMP pages cannot wait for AdFlow\'s consent check.', 'simple-google-adsense'),
                        )),
                    ),
                ),
                'protection' => array(
                    'title' => __('Invalid-click protection & ad limits', 'simple-google-adsense'),
                    'pro' => true,
                    'blocks' => array(
                        array('p', __('Stops showing ads to a visitor who clicks ads too often (e.g. 3 clicks in 24 hours → no ads for 7 days). Flagged visitors never load the AdSense script, so no ad is ever hidden - hiding ads breaks AdSense policy. It reduces risk; no tool can guarantee Google\'s decisions.', 'simple-google-adsense')),
                        array('p', __('<strong>Check it works:</strong> never click your own live ads, because Google treats that as invalid activity. Open a page, choose AdFlow → Inspect ads on this page in the toolbar, and use <strong>Test with a dummy ad</strong>. The note shows the clicks seen on the dummy frame and the real clicks counted in this browser, and <strong>Reset this browser</strong> clears them. Flagged visitors stop getting Google ads; your sponsors\' banner and text ads still show to them.', 'simple-google-adsense')),
                        array('p', __('Also on Settings → Click protection: <strong>Max manual ads per page</strong> and a polite <strong>ad-blocker message</strong>. Settings → Performance: <strong>lazy loading</strong>; each ad unit: <strong>reserved height</strong> against layout shift.', 'simple-google-adsense')),
                    ),
                ),
                'roles' => array(
                    'title' => __('Roles, permissions & approvals', 'simple-google-adsense'),
                    'blocks' => array(
                        array('p', __('Under <strong>Settings → Access</strong>, give other roles "Create & edit ads", "Publish ads (no review)" and "View reports". Administrators always have full access; settings stay with administrators.', 'simple-google-adsense')),
                        array('p', __('<strong>Approval workflow:</strong> someone who may create but not publish ads gets "Submit for Review". Everyone who may publish is emailed, and the Dashboard shows the ads waiting.', 'simple-google-adsense')),
                        array('links', array(__('Open Access', 'simple-google-adsense') => $A::settings_url('access'))),
                    ),
                ),
                'activity' => array(
                    'title' => __('Activity log', 'simple-google-adsense'),
                    'pro' => true,
                    'blocks' => array(
                        array('p', __('Who created, changed or deleted which ad, placement or setting, and when - under Settings → Activity log.', 'simple-google-adsense')),
                    ),
                ),
            ),

            __('Switching & moving', 'simple-google-adsense') => array(
                'switch' => array(
                    'title' => __('Switch from Advanced Ads, Ad Inserter, AdRotate or WP QUADS', 'simple-google-adsense'),
                    'blocks' => array(
                        array('steps', array(
                            __('Open <strong>Settings → Switch to AdFlow</strong>. AdFlow shows what it found - the other plugin does not have to be active.', 'simple-google-adsense'),
                            __('Click <strong>Import</strong>. Ads, rotation groups, placements and (AdRotate / WP QUADS) statistics are copied; nothing in the other plugin is changed. Importing again never creates duplicates.', 'simple-google-adsense'),
                            __('Review the report: AdSense code for your Publisher ID becomes a native AdSense unit, linked banners become image ads, everything else is kept as custom code so it looks exactly as before.', 'simple-google-adsense'),
                            __('Deactivate the old plugin. Its shortcodes - <code>[the_ad]</code>, <code>[adinserter]</code>, <code>[adrotate]</code>, <code>[quads]</code> - keep working through AdFlow.', 'simple-google-adsense'),
                        )),
                        array('links', array(__('Open Switch to AdFlow', 'simple-google-adsense') => $A::settings_url('migrate'))),
                    ),
                ),
                'export' => array(
                    'title' => __('Export / import between sites', 'simple-google-adsense'),
                    'pro' => true,
                    'blocks' => array(
                        array('p', __('Settings → Import / Export downloads your whole setup (settings, placements, ads.txt, ad units) as one file and restores it on another site.', 'simple-google-adsense')),
                    ),
                ),
            ),

            __('Help', 'simple-google-adsense') => array(
                'not-showing' => array(
                    'title' => __('Ads are not showing', 'simple-google-adsense'),
                    'blocks' => array(
                        array('steps', array(
                            __('Look at the <strong>Dashboard</strong> checks and the ad\'s <strong>Will this ad show?</strong> box.', 'simple-google-adsense'),
                            __('Open a page while logged in and choose <strong>AdFlow → Inspect ads on this page</strong> in the toolbar: every ad is outlined as filled, unfilled, requested (waiting for Google) or not requested.', 'simple-google-adsense'),
                            __('"Hide ads for administrators" (Settings → General) hides ads from you - check in a private window.', 'simple-google-adsense'),
                            __('Purge your cache plugin, and exclude <code>adsbygoogle.js</code> from JavaScript "delay" or "combine" features.', 'simple-google-adsense'),
                            __('With "Load ads after consent" on, ads wait for the consent banner to be answered.', 'simple-google-adsense'),
                            __('Unfilled AdSense spaces are Google\'s decision (new site, low traffic, policy). They are not an AdFlow error.', 'simple-google-adsense'),
                        )),
                    ),
                ),
                'data' => array(
                    'title' => __('Your data, updates & uninstalling', 'simple-google-adsense'),
                    'blocks' => array(
                        array('p', __('Updating never changes how existing ads look; new features start switched off. Deleting the plugin keeps your ads and settings unless you turn on "Also delete all AdFlow ads, settings and statistics" under Settings → General → Your data.', 'simple-google-adsense')),
                    ),
                ),
                'license' => array(
                    'title' => __('Installing Pro & your license', 'simple-google-adsense'),
                    'pro' => true,
                    'blocks' => array(
                        array('steps', array(
                            __('Keep the free AdFlow plugin active - Pro is an add-on.', 'simple-google-adsense'),
                            __('Upload adflow-pro.zip under Plugins → Add New → Upload Plugin and activate it.', 'simple-google-adsense'),
                            __('Enter your key under Settings → License to receive updates and support. Pro features keep working if a license expires.', 'simple-google-adsense'),
                        )),
                    ),
                ),
                'developers' => array(
                    'title' => __('For developers', 'simple-google-adsense'),
                    'blocks' => array(
                        array('p', __('Useful filters and actions:', 'simple-google-adsense')),
                        array('list', array(
                            __('<code>adflow_should_display_ad</code> ($display, $unit, $context) - hide a particular ad.', 'simple-google-adsense'),
                            __('<code>adflow_ads_allowed</code> - turn all ads off on a request.', 'simple-google-adsense'),
                            __('<code>adflow_manual_ads_excluded</code> - turn manual ads off on a request (return true).', 'simple-google-adsense'),
                            __('<code>adflow_ad_markup</code> ($html, $unit, $context) - change the output.', 'simple-google-adsense'),
                            __('<code>adflow_ad_wrapper_attributes</code> - add attributes to the ad wrapper.', 'simple-google-adsense'),
                            __('<code>adflow_placement_types</code> - register a placement.', 'simple-google-adsense'),
                            __('<code>adflow_content_slot_min_spacing</code> - fewest paragraphs between an extra in-content ad and any other ad (default 2).', 'simple-google-adsense'),
                            __('<code>adflow_languages</code> + <code>adflow_current_language</code> - language rules for other multilingual plugins.', 'simple-google-adsense'),
                            __('<code>adflow_ad_types</code> + <code>adflow_render_ad_type</code> - register an ad unit type.', 'simple-google-adsense'),
                            __('<code>adflow_review_recipients</code> - who is emailed about ads waiting for review.', 'simple-google-adsense'),
                            __('<code>adflow_imported</code> - runs after an import from another plugin.', 'simple-google-adsense'),
                            __('<code>adflow_geo_country</code> (Pro) - supply the visitor country from your own lookup.', 'simple-google-adsense'),
                        )),
                    ),
                ),
            ),
        );

        /**
         * Filters the Docs sections (add your own articles).
         *
         * @param array $sections
         * @since 1.4.0
         */
        return apply_filters('adflow_docs_sections', $sections);
    }

    /*
    |--------------------------------------------------------------------------
    | Screen
    |--------------------------------------------------------------------------
    */

    /**
     * Allowed inline markup in docs text.
     *
     * @return array
     * @since 1.4.0
     */
    private static function inline_tags()
    {
        return array('code' => array(), 'strong' => array(), 'em' => array());
    }

    /**
     * Render one block.
     *
     * @param array $block Block.
     * @since 1.4.0
     */
    private static function render_block($block)
    {
        $tags = self::inline_tags();

        switch ($block[0]) {
            case 'p':
                echo '<p>' . wp_kses($block[1], $tags) . '</p>';
                break;
            case 'note':
                echo '<p class="adflow-docs__note">' . wp_kses($block[1], $tags) . '</p>';
                break;
            case 'code':
                echo '<pre class="adflow-docs__code" tabindex="0"><code>' . esc_html($block[1]) . '</code></pre>';
                break;
            case 'steps':
            case 'list':
                $tag = 'steps' === $block[0] ? 'ol' : 'ul';
                echo '<' . $tag . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed tag.
                foreach ((array) $block[1] as $item) {
                    echo '<li>' . wp_kses($item, $tags) . '</li>';
                }
                echo '</' . $tag . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed tag.
                break;
            case 'links':
                echo '<p class="adflow-docs__links">';
                foreach ((array) $block[1] as $label => $url) {
                    if (!Simple_Google_Adsense_Admin::can_open($url)) {
                        continue;
                    }

                    $external = 0 !== strpos($url, admin_url());
                    echo '<a class="button" href="' . esc_url($url) . '"' . ($external ? ' target="_blank" rel="noopener"' : '') . '>' . esc_html($label) . ($external ? ' <span aria-hidden="true">↗</span>' : '') . '</a> ';
                }
                echo '</p>';
                break;
        }
    }

    /**
     * Render the Docs page.
     *
     * @since 1.4.0
     */
    public function render()
    {
        $pro = Simple_Google_Adsense_Settings::is_pro_active();
        $sections = self::sections();
        ?>
        <div class="wrap adflow-page adflow-docs">
            <?php
            Simple_Google_Adsense_Admin::render_header(
                __('Docs', 'simple-google-adsense'),
                __('Everything AdFlow and AdFlow Pro can do, and how to set it up.', 'simple-google-adsense'),
                array(
                    array(
                        'label' => __('Full documentation online', 'simple-google-adsense'),
                        'url' => 'https://matrixaddons.com/plugins/adflow/docs/',
                        'target' => true,
                    ),
                )
            );
            ?>
            <div class="adflow-docs__layout">
                <nav class="adflow-docs__nav" aria-label="<?php esc_attr_e('Documentation', 'simple-google-adsense'); ?>">
                    <label class="screen-reader-text" for="adflow-docs-search"><?php esc_html_e('Search the docs', 'simple-google-adsense'); ?></label>
                    <input type="search" id="adflow-docs-search" class="adflow-docs__search" placeholder="<?php esc_attr_e('Search the docs…', 'simple-google-adsense'); ?>" autocomplete="off">
                    <?php foreach ($sections as $section => $articles) : ?>
                        <div class="adflow-docs__group">
                            <h2><?php echo esc_html($section); ?></h2>
                            <ul>
                                <?php foreach ($articles as $id => $article) : ?>
                                    <li><a href="#<?php echo esc_attr($id); ?>" data-doc="<?php echo esc_attr($id); ?>"><?php echo esc_html($article['title']); ?><?php if (!empty($article['pro'])) : ?> <span class="adflow-pro-tag"><?php esc_html_e('Pro', 'simple-google-adsense'); ?></span><?php endif; ?></a></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endforeach; ?>
                </nav>

                <div class="adflow-docs__content">
                    <p class="adflow-docs__empty" hidden><?php esc_html_e('Nothing found. Try another word.', 'simple-google-adsense'); ?></p>
                    <?php foreach ($sections as $section => $articles) : ?>
                        <?php foreach ($articles as $id => $article) : ?>
                            <article class="adflow-panel adflow-docs__article" id="<?php echo esc_attr($id); ?>">
                                <header class="adflow-panel__head">
                                    <div>
                                        <p class="adflow-docs__section"><?php echo esc_html($section); ?></p>
                                        <h2>
                                            <?php echo esc_html($article['title']); ?>
                                            <?php if (!empty($article['pro'])) : ?>
                                                <span class="adflow-pro-tag"><?php esc_html_e('Pro', 'simple-google-adsense'); ?></span>
                                            <?php endif; ?>
                                        </h2>
                                    </div>
                                </header>
                                <div class="adflow-panel__body adflow-docs__body">
                                    <?php if (!empty($article['pro']) && !$pro) : ?>
                                        <div class="adflow-docs__pro">
                                            <p><strong><?php esc_html_e('Available in AdFlow Pro', 'simple-google-adsense'); ?></strong> &middot; <?php esc_html_e('Everything in the free plugin stays free.', 'simple-google-adsense'); ?></p>
                                            <a class="button button-primary" href="<?php echo esc_url(Simple_Google_Adsense_Upsell::url('docs-' . $id)); ?>" target="_blank" rel="noopener"><?php esc_html_e('Get AdFlow Pro', 'simple-google-adsense'); ?></a>
                                        </div>
                                    <?php endif; ?>
                                    <?php
                                    foreach ($article['blocks'] as $block) {
                                        self::render_block($block);
                                    }
                                    ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <script>
        (function () {
            var search = document.getElementById('adflow-docs-search');
            var articles = document.querySelectorAll('.adflow-docs__article');
            var links = document.querySelectorAll('.adflow-docs__nav a[data-doc]');
            var empty = document.querySelector('.adflow-docs__empty');

            search.addEventListener('input', function () {
                var q = search.value.trim().toLowerCase();
                var shown = 0;
                articles.forEach(function (a) {
                    var hit = !q || a.textContent.toLowerCase().indexOf(q) !== -1;
                    a.hidden = !hit;
                    shown += hit ? 1 : 0;
                });
                links.forEach(function (l) {
                    var target = document.getElementById(l.getAttribute('data-doc'));
                    l.parentNode.hidden = !!(target && target.hidden);
                });
                empty.hidden = shown > 0;
            });

            function mark() {
                var id = window.location.hash.slice(1);
                links.forEach(function (l) {
                    l.classList.toggle('is-active', l.getAttribute('data-doc') === id);
                });
            }
            window.addEventListener('hashchange', mark);
            mark();
        })();
        </script>
        <?php
    }
}
