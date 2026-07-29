<?php
/**
 * See the change before you approve it.
 *
 * A diff tells you what words moved. It does not tell you whether the result
 * looks right in your theme, whether the shortcode still renders, or whether
 * the new section sits properly between the two around it. This closes that
 * gap in three ways, matched to the kind of change:
 *
 *   Body content  A real front-end render of the page with the change applied
 *                 in memory. Nothing is written; the post is untouched.
 *   Metadata      A Google-style result snippet, because a meta description
 *                 has no visible form on the page itself.
 *   Any section   An inline rendered preview inside the review card, run
 *                 through the_content so shortcodes and blocks execute.
 *
 * The front-end preview is the security-sensitive one: it renders unsaved
 * content on a public URL. Nonce, capability and per-post checks all have to
 * pass, and the response is marked no-cache so a page cache can never store
 * and serve it to a visitor.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ECP_Preview {

    const QUERY_VAR = 'ecp_preview';

    private static $instance = null;

    /** @var array|null The proposal being previewed on this request. */
    private $proposal = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        add_action('template_redirect', array($this, 'maybe_start'), 0);
    }

    /* --------------------------------------------------------------------
     * Building the link
     * ----------------------------------------------------------------- */

    /**
     * A signed preview URL for a proposal, or '' when preview does not apply.
     *
     * @param array $proposal
     * @return string
     */
    public static function url(array $proposal) {
        if (!self::supports_page_preview($proposal)) {
            return '';
        }

        $permalink = get_permalink((int) $proposal['post_id']);

        if (!$permalink) {
            return '';
        }

        return add_query_arg(
            array(
                self::QUERY_VAR => (int) $proposal['id'],
                '_wpnonce'      => wp_create_nonce(self::nonce_action((int) $proposal['id'])),
            ),
            $permalink
        );
    }

    private static function nonce_action($proposal_id) {
        return 'ecp_preview_' . (int) $proposal_id;
    }

    /**
     * Whether a full-page render makes sense for this change type.
     *
     * Metadata changes have no on-page appearance, so a page preview would
     * show an identical page and quietly mislead the reviewer.
     */
    public static function supports_page_preview(array $proposal) {
        $info = ECP_Proposals::change_type($proposal['change_type']);

        if (!$info) {
            return false;
        }

        return in_array($info['target'], array('section', 'section_insert', 'content', 'attachment'), true);
    }

    /* --------------------------------------------------------------------
     * Front-end render
     * ----------------------------------------------------------------- */

    /**
     * Decide whether this request is a preview, and if so take it over.
     */
    public function maybe_start() {
        $requested = isset($_GET[self::QUERY_VAR]) ? absint($_GET[self::QUERY_VAR]) : 0;  // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified immediately below.

        if (!$requested || !is_singular()) {
            return;
        }

        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';

        if (!wp_verify_nonce($nonce, self::nonce_action($requested))) {
            wp_die(
                esc_html__('This preview link has expired. Open it again from the review queue.', 'enhanced-content-plugin'),
                esc_html__('Preview expired', 'enhanced-content-plugin'),
                array('response' => 403)
            );
        }

        $proposal = ECP_Proposals::get($requested);

        if (!$proposal) {
            wp_die(
                esc_html__('That change no longer exists.', 'enhanced-content-plugin'),
                esc_html__('Not found', 'enhanced-content-plugin'),
                array('response' => 404)
            );
        }

        if (!ECP_Capabilities::can_review((int) $proposal['post_id'])) {
            wp_die(
                esc_html__('You do not have permission to preview changes to this page.', 'enhanced-content-plugin'),
                esc_html__('Not allowed', 'enhanced-content-plugin'),
                array('response' => 403)
            );
        }

        // The preview must be for the page actually being viewed, or a valid
        // nonce for one post could be used to inject content into another.
        if (get_queried_object_id() !== (int) $proposal['post_id']) {
            return;
        }

        $this->proposal = $proposal;

        self::prevent_caching();

        // Priority 1: the_content's chain begins with raw post_content, so
        // replacing it here still lets do_blocks (9), wpautop (10) and
        // do_shortcode (11) run over the result exactly as they normally do.
        add_filter('the_content', array($this, 'filter_content'), 1);
        add_filter('the_title', array($this, 'filter_title'), 10, 2);

        add_action('wp_footer', array($this, 'render_bar'), 999);
        add_action('wp_head', array($this, 'render_bar_styles'), 999);

        // Keep the preview out of search results and any crawler that
        // somehow reaches the URL.
        add_action('wp_head', function () {
            echo '<meta name="robots" content="noindex,nofollow,noarchive">' . "\n";
        }, 0);
    }

    /**
     * Tell every layer of caching to leave this alone.
     */
    private static function prevent_caching() {
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        if (!defined('DONOTCACHEOBJECT')) {
            define('DONOTCACHEOBJECT', true);
        }
        if (!defined('DONOTCACHEDB')) {
            define('DONOTCACHEDB', true);
        }

        nocache_headers();
    }

    /**
     * Swap in the modified body.
     */
    public function filter_content($content) {
        if (!$this->proposal || !in_the_loop() || !is_main_query()) {
            return $content;
        }

        $modified = self::apply_in_memory($this->proposal);

        if (is_wp_error($modified)) {
            return '<div style="border:2px solid #d63638;padding:14px;margin-bottom:20px;">'
                . '<strong>' . esc_html__('This change can no longer be previewed:', 'enhanced-content-plugin') . '</strong> '
                . esc_html($modified->get_error_message())
                . '</div>' . $content;
        }

        return $modified;
    }

    /**
     * Alt-text changes are invisible in rendered output, so annotate the
     * title rather than letting the reviewer stare at an identical page.
     */
    public function filter_title($title, $post_id = 0) {
        if (!$this->proposal || (int) $post_id !== (int) $this->proposal['post_id'] || !is_main_query()) {
            return $title;
        }

        return $title;
    }

    /**
     * Apply a proposal to the post content without saving anything.
     *
     * @param array $proposal
     * @return string|WP_Error
     */
    public static function apply_in_memory(array $proposal) {
        $post = get_post((int) $proposal['post_id']);

        if (!$post) {
            return new WP_Error('ecp_no_post', __('Post not found.', 'enhanced-content-plugin'));
        }

        $info = ECP_Proposals::change_type($proposal['change_type']);

        if (!$info) {
            return new WP_Error('ecp_unknown_type', __('Unknown change type.', 'enhanced-content-plugin'));
        }

        switch ($info['target']) {
            case 'section':
            case 'content':
                $section_id = isset($proposal['payload']['section_id'])
                    ? $proposal['payload']['section_id']
                    : $proposal['target_key'];

                return ECP_Content_Map::replace_section($post, $section_id, $proposal['after_value'], isset($proposal['payload']['heading']) ? $proposal['payload']['heading'] : '');

            case 'section_insert':
                $after = isset($proposal['payload']['after_section_id']) ? $proposal['payload']['after_section_id'] : '';

                return ECP_Content_Map::insert_after_section($post, $after, $proposal['after_value'], isset($proposal['payload']['heading']) ? $proposal['payload']['heading'] : '');

            case 'attachment':
                $src = isset($proposal['payload']['src']) ? $proposal['payload']['src'] : '';

                if ('' === $src) {
                    return $post->post_content;
                }

                $updated = preg_replace_callback(
                    '/<img\b([^>]*src\s*=\s*["\']' . preg_quote($src, '/') . '["\'][^>]*)>/i',
                    function ($match) use ($proposal) {
                        $attrs = $match[1];
                        $escaped = esc_attr($proposal['after_value']);

                        if (preg_match('/\balt\s*=\s*["\'][^"\']*["\']/i', $attrs)) {
                            $attrs = preg_replace('/\balt\s*=\s*["\'][^"\']*["\']/i', 'alt="' . $escaped . '"', $attrs, 1);
                        } else {
                            $attrs .= ' alt="' . $escaped . '"';
                        }

                        return '<img' . $attrs . '>';
                    },
                    $post->post_content
                );

                return null === $updated ? $post->post_content : $updated;
        }

        return $post->post_content;
    }

    /* --------------------------------------------------------------------
     * The preview bar
     * ----------------------------------------------------------------- */

    public function render_bar_styles() {
        ?>
        <style id="ecp-preview-bar-styles">
            html { margin-top: 46px !important; }
            #ecp-preview-bar {
                position: fixed; top: 0; left: 0; right: 0; z-index: 999999;
                display: flex; align-items: center; justify-content: space-between;
                gap: 16px; flex-wrap: wrap;
                padding: 8px 16px; box-sizing: border-box;
                background: #1d2327; color: #f0f0f1;
                font: 14px/1.4 -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
                box-shadow: 0 1px 6px rgba(0,0,0,.3);
            }
            #ecp-preview-bar .ecp-pv-label {
                display: inline-block; padding: 2px 9px; border-radius: 11px;
                background: #dba617; color: #1d2327; font-weight: 700;
                font-size: 11px; text-transform: uppercase; letter-spacing: .04em;
            }
            #ecp-preview-bar .ecp-pv-what { color: #c3c4c7; }
            #ecp-preview-bar a {
                color: #f0f0f1; text-decoration: none; padding: 5px 12px;
                border: 1px solid #50575e; border-radius: 3px;
            }
            #ecp-preview-bar a:hover { border-color: #f0f0f1; }
            #ecp-preview-bar a.ecp-pv-primary {
                background: #2271b1; border-color: #2271b1; font-weight: 600;
            }
            #ecp-preview-bar a.ecp-pv-primary:hover { background: #135e96; }
            @media (max-width: 600px) {
                html { margin-top: 74px !important; }
            }
            @media print { #ecp-preview-bar { display: none; } html { margin-top: 0 !important; } }
        </style>
        <?php
    }

    public function render_bar() {
        if (!$this->proposal) {
            return;
        }

        $review_url = add_query_arg(
            array('page' => 'ecp-review', 'post' => (int) $this->proposal['post_id']),
            admin_url('admin.php')
        ) . '#ecp-proposal-' . (int) $this->proposal['id'];

        ?>
        <div id="ecp-preview-bar">
            <div>
                <span class="ecp-pv-label"><?php esc_html_e('Preview', 'enhanced-content-plugin'); ?></span>
                <strong><?php esc_html_e('Not published.', 'enhanced-content-plugin'); ?></strong>
                <span class="ecp-pv-what">
                    <?php
                    printf(
                        /* translators: %s: change type, e.g. "Rewrite a section" */
                        esc_html__('Showing this page with one proposed change applied: %s', 'enhanced-content-plugin'),
                        esc_html(ECP_Proposals::type_label($this->proposal['change_type']))
                    );
                    ?>
                </span>
            </div>
            <div>
                <a href="<?php echo esc_url(get_permalink((int) $this->proposal['post_id'])); ?>">
                    <?php esc_html_e('Live version', 'enhanced-content-plugin'); ?>
                </a>
                <a class="ecp-pv-primary" href="<?php echo esc_url($review_url); ?>">
                    <?php esc_html_e('Back to review', 'enhanced-content-plugin'); ?>
                </a>
            </div>
        </div>
        <?php
    }

    /* --------------------------------------------------------------------
     * SERP snippet, for metadata changes
     * ----------------------------------------------------------------- */

    /**
     * A before/after search-result snippet.
     *
     * Google truncates by pixel width, not characters, so this is an
     * approximation — but it is a far better answer to "how will this look"
     * than a character count.
     *
     * @param array $proposal
     * @return string HTML, already escaped.
     */
    public static function serp_snippet(array $proposal) {
        $post_id = (int) $proposal['post_id'];
        $post = get_post($post_id);

        if (!$post) {
            return '';
        }

        $current_title = $post->post_title;
        $current_desc = (string) $post->post_excerpt;

        $keys = ECP_Signals::seo_meta_keys();
        if ($keys) {
            $stored_title = get_post_meta($post_id, $keys['title'], true);
            $stored_desc = get_post_meta($post_id, $keys['description'], true);

            if ($stored_title) {
                $current_title = $stored_title;
            }
            if ($stored_desc) {
                $current_desc = $stored_desc;
            }
        }

        // The stored value may be a template ("%%title%% %%page%%"), which
        // is not what a searcher sees. Render what they see.
        $current_title = ECP_Signals::resolve_seo_template($current_title, $post);
        $current_desc = ECP_Signals::resolve_seo_template($current_desc, $post);

        $is_title = 'meta_title' === $proposal['change_type'];

        $after_title = $is_title ? $proposal['after_value'] : $current_title;
        $after_desc = $is_title ? $current_desc : $proposal['after_value'];

        ob_start();
        ?>
        <div class="ecp-serp-pair">
            <?php
            self::render_serp_card(
                __('How it looks now', 'enhanced-content-plugin'),
                $current_title,
                $current_desc,
                get_permalink($post_id),
                false
            );

            self::render_serp_card(
                __('How it would look', 'enhanced-content-plugin'),
                $after_title,
                $after_desc,
                get_permalink($post_id),
                true
            );
            ?>
        </div>
        <?php

        return (string) ob_get_clean();
    }

    private static function render_serp_card($label, $title, $description, $url, $is_after) {
        $title = trim(wp_strip_all_tags((string) $title));
        $description = trim(wp_strip_all_tags((string) $description));

        // Rough truncation points that match what Google usually shows.
        $title_limit = 60;
        $desc_limit = 158;

        $title_cut = mb_strlen($title) > $title_limit;
        $desc_cut = mb_strlen($description) > $desc_limit;

        $breadcrumb = str_replace(array('https://', 'http://'), '', untrailingslashit((string) $url));
        $breadcrumb = str_replace('/', ' › ', $breadcrumb);

        ?>
        <div class="ecp-serp<?php echo $is_after ? ' is-after' : ''; ?>">
            <h4><?php echo esc_html($label); ?></h4>
            <div class="ecp-serp-card">
                <div class="ecp-serp-url"><?php echo esc_html($breadcrumb); ?></div>
                <div class="ecp-serp-title">
                    <?php echo esc_html($title_cut ? mb_substr($title, 0, $title_limit) . '…' : $title); ?>
                </div>
                <div class="ecp-serp-desc">
                    <?php
                    if ('' === $description) {
                        echo '<em>' . esc_html__('Google will write its own snippet from the page.', 'enhanced-content-plugin') . '</em>';
                    } else {
                        echo esc_html($desc_cut ? mb_substr($description, 0, $desc_limit) . '…' : $description);
                    }
                    ?>
                </div>
            </div>
            <p class="ecp-serp-meta">
                <?php
                printf(
                    /* translators: 1: title length, 2: description length */
                    esc_html__('Title %1$d chars · Description %2$d chars', 'enhanced-content-plugin'),
                    (int) mb_strlen($title),
                    (int) mb_strlen($description)
                );

                if ($title_cut || $desc_cut) {
                    echo ' · <span class="ecp-serp-warn">' . esc_html__('will be cut off', 'enhanced-content-plugin') . '</span>';
                }
                ?>
            </p>
        </div>
        <?php
    }

    /* --------------------------------------------------------------------
     * Inline rendered preview
     * ----------------------------------------------------------------- */

    /**
     * Render just the changed section through the_content, so shortcodes,
     * blocks and embeds resolve. Used by the "Show rendered" toggle on a
     * card — lazily, because running the_content for every card on the page
     * would be slow and would fire other plugins' filters dozens of times.
     *
     * @param array $proposal
     * @return string HTML
     */
    public static function render_fragment(array $proposal) {
        $post = get_post((int) $proposal['post_id']);

        if (!$post) {
            return '';
        }

        $html = (string) $proposal['after_value'];

        if ('' === trim($html)) {
            return '';
        }

        // Set up the post so shortcodes that read the global behave.
        $previous = isset($GLOBALS['post']) ? $GLOBALS['post'] : null;
        $GLOBALS['post'] = $post;   // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
        setup_postdata($post);

        // Deliberately not apply_filters('the_content'): that would run
        // every other plugin's content filters, including ones that inject
        // ads, related posts and social buttons into what is supposed to be
        // a preview of one section. The three core transforms are what
        // actually matter for judging the markup.
        $rendered = do_blocks($html);
        $rendered = wptexturize($rendered);
        $rendered = wpautop($rendered);
        $rendered = do_shortcode($rendered);

        wp_reset_postdata();

        if ($previous) {
            $GLOBALS['post'] = $previous;   // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
        }

        return wp_kses_post($rendered);
    }
}
