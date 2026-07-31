<?php
/**
 * Writes approved changes, and takes them back out again.
 *
 * Two invariants, both non-negotiable:
 *
 *   1. A WordPress revision is created before any post is modified, so the
 *      site's own history contains the pre-change state even if this plugin
 *      is later deleted.
 *   2. Enough is captured in revert_data to undo the change exactly, without
 *      depending on that revision still existing.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ECP_Applier {

    /**
     * True while a change is being written.
     *
     * The applier calls wp_update_post(), which fires save_post, which the
     * scheduler listens to in order to rescore the page. Rescoring mid-write
     * reads a half-applied post and wastes a query per approval, so the
     * scheduler checks this flag and stands down.
     *
     * @var bool
     */
    public static $applying = false;

    /**
     * Apply an approved proposal.
     *
     * @param int  $proposal_id
     * @param bool $auto Whether this came from autopilot rather than a click.
     * @return true|WP_Error
     */
    public static function apply($proposal_id, $auto = false) {
        $proposal = ECP_Proposals::get($proposal_id);

        if (!$proposal) {
            return new WP_Error('ecp_not_found', __('That change no longer exists.', 'enhanced-content-plugin'));
        }

        // FAILED is included deliberately. A failure here is usually about
        // the state of the page at that moment, not the change itself, so
        // once the cause is dealt with the change should be retryable —
        // making it terminal would force a paid re-analysis to recover from
        // a transient problem.
        $retryable = array(ECP_Proposals::APPROVED, ECP_Proposals::PENDING, ECP_Proposals::FAILED);

        if (!in_array($proposal['status'], $retryable, true)) {
            return new WP_Error('ecp_not_applicable', sprintf(
                /* translators: %s: status label */
                __('That change is %s and cannot be applied.', 'enhanced-content-plugin'),
                ECP_Proposals::status_label($proposal['status'])
            ));
        }

        // An unattended run has no current user, so the capability check is
        // skipped there by design — autopilot is itself gated by an explicit
        // opt-in setting. Anything with a user behind it must pass.
        if (!$auto && !ECP_Capabilities::can_review((int) $proposal['post_id'])) {
            return new WP_Error('ecp_forbidden', __('You do not have permission to approve changes to that post.', 'enhanced-content-plugin'));
        }

        $ready = ECP_Guardrails::check_before_apply($proposal);
        if (is_wp_error($ready)) {
            // The change is still perfectly good — what failed is the page
            // underneath it. Leave it in the queue rather than burying it in
            // a Failed tab, so the reviewer can see it, re-analyze, and
            // decide. It expires on the normal TTL if nobody ever does.
            self::mark_failed($proposal, $ready->get_error_message(), true);

            return $ready;
        }

        $post = get_post((int) $proposal['post_id']);
        $info = ECP_Proposals::change_type($proposal['change_type']);

        if (!$info) {
            $error = new WP_Error('ecp_unknown_type', __('Unknown change type.', 'enhanced-content-plugin'));
            self::mark_failed($proposal, $error->get_error_message());

            return $error;
        }

        // The safety net, before anything is touched.
        $revision_id = self::snapshot($post);

        self::$applying = true;

        switch ($info['target']) {
            case 'meta':
                $result = self::apply_meta($post, $proposal);
                break;

            case 'title':
                $result = self::apply_title($post, $proposal);
                break;

            case 'section':
                $result = self::apply_section($post, $proposal);
                break;

            case 'section_insert':
                $result = self::apply_section_insert($post, $proposal);
                break;

            case 'content':
                $result = self::apply_section($post, $proposal);
                break;

            case 'attachment':
                $result = self::apply_image_alt($post, $proposal);
                break;

            case 'faq':
                $result = self::apply_faq($post, $proposal);
                break;

            case 'sources':
                $result = self::apply_sources($post, $proposal);
                break;

            default:
                $result = new WP_Error('ecp_no_writer', __('No writer exists for that change type.', 'enhanced-content-plugin'));
        }

        self::$applying = false;

        if (is_wp_error($result)) {
            self::mark_failed($proposal, $result->get_error_message());

            return $result;
        }

        ECP_Proposals::update($proposal_id, array(
            'status'       => ECP_Proposals::APPLIED,
            'applied_at'   => ECP_DB::now(),
            'revision_id'  => (int) $revision_id,
            'revert_data'  => $result,
            'auto_applied' => $auto ? 1 : 0,
        ));

        // Baseline for measuring whether this helped.
        self::store_baseline($proposal_id, (int) $post->ID);

        if (!$auto) {
            self::count_trust_decision($proposal);
        }

        ECP_Log::record($auto ? ECP_Log::AUTOPILOT_APPLIED : ECP_Log::PROPOSAL_APPLIED, array(
            'message'     => sprintf(
                /* translators: 1: change type, 2: post title */
                __('Applied %1$s to "%2$s"', 'enhanced-content-plugin'),
                ECP_Proposals::type_label($proposal['change_type']),
                $post->post_title
            ),
            'post_id'     => (int) $post->ID,
            'proposal_id' => (int) $proposal_id,
            'user_id'     => $auto ? 0 : null,
            'context'     => array('revision_id' => (int) $revision_id, 'auto' => (bool) $auto),
        ));

        // Metrics and the opportunity score are stale now.
        delete_transient('map_health_stats');
        delete_transient('ecp_inbound_' . (int) $post->ID);

        /**
         * Fires after an agent change is written to a post.
         *
         * @param int   $proposal_id
         * @param array $proposal
         * @param int   $revision_id
         */
        do_action('ecp_change_applied', (int) $proposal_id, $proposal, (int) $revision_id);

        return true;
    }

    /**
     * Approve and apply in one step, for the review screen's main button.
     *
     * @return true|WP_Error
     */
    public static function approve_and_apply($proposal_id, $note = '') {
        $approved = ECP_Proposals::approve($proposal_id, $note);

        if (is_wp_error($approved)) {
            return $approved;
        }

        // "Apply as draft" keeps the live page untouched: the change lands in
        // a draft revision the editor can publish when they're ready.
        if (ECP_Agent_Settings::is_on('apply_as_draft')) {
            return self::apply_to_draft($proposal_id);
        }

        return self::apply($proposal_id);
    }

    /* --------------------------------------------------------------------
     * Writers
     * ----------------------------------------------------------------- */

    /**
     * The visible page title. The slug is pinned explicitly on every
     * write — a title refresh must never move the URL.
     *
     * @return array|WP_Error revert_data on success.
     */
    private static function apply_title($post, array $proposal) {
        $new_title = trim(wp_strip_all_tags((string) $proposal['after_value']));

        if ('' === $new_title) {
            return new WP_Error('ecp_empty_title', __('The proposed page title is empty.', 'enhanced-content-plugin'));
        }

        $previous = $post->post_title;

        $updated = wp_update_post(array(
            'ID'         => $post->ID,
            'post_title' => $new_title,
            'post_name'  => $post->post_name,
        ), true);

        if (is_wp_error($updated)) {
            return $updated;
        }

        return array(
            'kind'  => 'title',
            'value' => $previous,
        );
    }

    /**
     * @return array|WP_Error revert_data on success.
     */
    private static function apply_meta($post, array $proposal) {
        $payload = $proposal['payload'];
        $field = isset($payload['field']) ? $payload['field'] : '';
        $plugin = isset($payload['seo_plugin']) ? $payload['seo_plugin'] : ECP_Signals::detect_seo_plugin();
        $keys = ECP_Signals::seo_meta_keys($plugin);

        if ('description' === $field && !$keys) {
            // No SEO plugin owns the description, so the excerpt is what
            // themes and feeds actually use.
            $before = $post->post_excerpt;

            $updated = wp_update_post(array(
                'ID'           => $post->ID,
                'post_excerpt' => $proposal['after_value'],
            ), true);

            if (is_wp_error($updated)) {
                return $updated;
            }

            return array('kind' => 'excerpt', 'value' => $before);
        }

        if (!$keys) {
            return new WP_Error(
                'ecp_no_seo_field',
                __('No SEO plugin is active, so there is no SEO title field to write to.', 'enhanced-content-plugin')
            );
        }

        $meta_key = 'title' === $field ? $keys['title'] : $keys['description'];
        $before = get_post_meta($post->ID, $meta_key, true);

        update_post_meta($post->ID, $meta_key, $proposal['after_value']);

        return array('kind' => 'meta', 'meta_key' => $meta_key, 'value' => $before);
    }

    /**
     * @return array|WP_Error
     */
    private static function apply_section($post, array $proposal) {
        $section_id = isset($proposal['payload']['section_id'])
            ? $proposal['payload']['section_id']
            : $proposal['target_key'];

        $heading = isset($proposal['payload']['heading']) ? $proposal['payload']['heading'] : '';
        $new_content = ECP_Content_Map::replace_section($post, $section_id, $proposal['after_value'], $heading);

        if (is_wp_error($new_content)) {
            return $new_content;
        }

        $before = $post->post_content;

        $updated = wp_update_post(array(
            'ID'           => $post->ID,
            'post_content' => $new_content,
        ), true);

        if (is_wp_error($updated)) {
            return $updated;
        }

        // The whole previous body, not just the section. Restoring a section
        // by id fails if a later change renamed its heading.
        return array('kind' => 'content', 'value' => $before);
    }

    /**
     * @return array|WP_Error
     */
    private static function apply_section_insert($post, array $proposal) {
        $after_id = isset($proposal['payload']['after_section_id']) ? $proposal['payload']['after_section_id'] : '';

        // The hint must be the ANCHOR's heading, not payload['heading'] —
        // that one is the new section's own heading, and passing it here
        // once made the writer insert after the wrong section whenever the
        // two happened to match.
        $anchor_hint = isset($proposal['payload']['anchor_heading']) ? $proposal['payload']['anchor_heading'] : '';

        // When the anchor cannot be found the writer appends at the end
        // rather than failing. Right call, but a silent right call is how
        // this plugin has burned its user before — so say it happened.
        $anchor_moved = '' !== $after_id && !ECP_Content_Map::find_section($post, $after_id, $anchor_hint);

        $new_content = ECP_Content_Map::insert_after_section($post, $after_id, $proposal['after_value'], $anchor_hint);

        if (is_wp_error($new_content)) {
            return $new_content;
        }

        $before = $post->post_content;

        $updated = wp_update_post(array(
            'ID'           => $post->ID,
            'post_content' => $new_content,
        ), true);

        if (is_wp_error($updated)) {
            return $updated;
        }

        if ($anchor_moved) {
            ECP_Proposals::update((int) $proposal['id'], array(
                'review_note' => __('The section this was meant to follow had moved or been renamed, so the new section was added at the end of the article instead. Drag it into place in the editor if you want it elsewhere.', 'enhanced-content-plugin'),
            ));

            ECP_Log::info('apply.anchor_moved', sprintf(
                /* translators: %s: post title */
                __('Added the new section to the end of "%s" — its intended position no longer existed.', 'enhanced-content-plugin'),
                $post->post_title
            ), array('post_id' => (int) $post->ID, 'proposal_id' => (int) $proposal['id']));
        }

        return array('kind' => 'content', 'value' => $before);
    }

    /**
     * @return array|WP_Error
     */
    private static function apply_image_alt($post, array $proposal) {
        $attachment_id = isset($proposal['payload']['attachment_id']) ? (int) $proposal['payload']['attachment_id'] : 0;
        $src = isset($proposal['payload']['src']) ? $proposal['payload']['src'] : '';

        if ($attachment_id) {
            $before = (string) get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
            update_post_meta($attachment_id, '_wp_attachment_image_alt', $proposal['after_value']);

            // Also fix the inline attribute, which is what actually renders.
            self::patch_inline_alt($post, $src, $proposal['after_value']);

            return array('kind' => 'alt', 'attachment_id' => $attachment_id, 'value' => $before, 'src' => $src);
        }

        // External or unattached image: the inline attribute is all there is.
        $before_content = $post->post_content;
        $patched = self::patch_inline_alt($post, $src, $proposal['after_value']);

        if (!$patched) {
            return new WP_Error('ecp_alt_not_applied', __('Could not find that image in the post content.', 'enhanced-content-plugin'));
        }

        return array('kind' => 'content', 'value' => $before_content);
    }

    /**
     * Rewrite the alt attribute on an <img> with a given src.
     *
     * @return bool Whether the content changed.
     */
    private static function patch_inline_alt($post, $src, $alt) {
        if ('' === $src) {
            return false;
        }

        $content = $post->post_content;
        $quoted = preg_quote($src, '/');

        $updated = preg_replace_callback(
            '/<img\b([^>]*src\s*=\s*["\']' . $quoted . '["\'][^>]*)>/i',
            function ($match) use ($alt) {
                $attrs = $match[1];
                $escaped = esc_attr($alt);

                if (preg_match('/\balt\s*=\s*["\'][^"\']*["\']/i', $attrs)) {
                    $attrs = preg_replace('/\balt\s*=\s*["\'][^"\']*["\']/i', 'alt="' . $escaped . '"', $attrs, 1);
                } else {
                    $attrs .= ' alt="' . $escaped . '"';
                }

                return '<img' . $attrs . '>';
            },
            $content
        );

        if (null === $updated || $updated === $content) {
            return false;
        }

        wp_update_post(array('ID' => $post->ID, 'post_content' => $updated));

        return true;
    }

    /**
     * @return array|WP_Error
     */
    private static function apply_faq($post, array $proposal) {
        $entries = isset($proposal['payload']['entries']) ? (array) $proposal['payload']['entries'] : array();

        if (!$entries) {
            return new WP_Error('ecp_no_faq_entries', __('No FAQ entries to add.', 'enhanced-content-plugin'));
        }

        // The v1 FAQ feature stores its items under _map_faq and gates
        // rendering on _map_faq_enabled. Writing the items without the flag
        // would store questions nobody ever sees.
        $existing = get_post_meta($post->ID, '_map_faq', true);
        $existing = is_array($existing) ? $existing : array();
        $was_enabled = get_post_meta($post->ID, '_map_faq_enabled', true);

        update_post_meta($post->ID, '_map_faq', array_merge($existing, $entries));
        update_post_meta($post->ID, '_map_faq_enabled', '1');

        return array(
            'kind'         => 'meta_array',
            'meta_key'     => '_map_faq',
            'value'        => $existing,
            'also_restore' => array('_map_faq_enabled' => $was_enabled),
        );
    }

    /**
     * @return array|WP_Error
     */
    private static function apply_sources($post, array $proposal) {
        $sources = isset($proposal['payload']['sources']) ? (array) $proposal['payload']['sources'] : array();

        if (!$sources) {
            return new WP_Error('ecp_no_sources', __('No sources to add.', 'enhanced-content-plugin'));
        }

        $existing = get_post_meta($post->ID, '_article_sources', true);
        $existing = is_array($existing) ? $existing : array();

        $merged = array_merge($existing, $sources);
        update_post_meta($post->ID, '_article_sources', $merged);

        // Keep the Article Health citation count honest.
        update_post_meta($post->ID, '_map_citation_count', count($merged));

        return array('kind' => 'meta_array', 'meta_key' => '_article_sources', 'value' => $existing);
    }

    /* --------------------------------------------------------------------
     * Draft mode
     * ----------------------------------------------------------------- */

    /**
     * Write the change into a draft revision rather than the live post.
     *
     * Only content-shaped changes can be staged this way; metadata has no
     * draft form in WordPress, so those are reported back as needing the
     * direct-apply mode.
     *
     * @return true|WP_Error
     */
    public static function apply_to_draft($proposal_id) {
        $proposal = ECP_Proposals::get($proposal_id);

        if (!$proposal) {
            return new WP_Error('ecp_not_found', __('That change no longer exists.', 'enhanced-content-plugin'));
        }

        $info = ECP_Proposals::change_type($proposal['change_type']);
        $post = get_post((int) $proposal['post_id']);

        if (!$post) {
            return new WP_Error('ecp_no_post', __('The post no longer exists.', 'enhanced-content-plugin'));
        }

        if (!$info || !in_array($info['target'], array('section', 'section_insert', 'content'), true)) {
            // Metadata and attachment changes have nowhere to be staged.
            return self::apply($proposal_id);
        }

        // Draft mode gets the same drift check as a live apply. A draft feels
        // harmless, but a reviewer restores it with one click — staging a
        // stale rewrite over a section a human has since edited plants
        // exactly the silent revert the check exists to prevent.
        $ready = ECP_Guardrails::check_before_apply($proposal);
        if (is_wp_error($ready)) {
            self::mark_failed($proposal, $ready->get_error_message(), true);

            return $ready;
        }

        $section_id = isset($proposal['payload']['section_id'])
            ? $proposal['payload']['section_id']
            : $proposal['target_key'];

        $new_content = 'section_insert' === $info['target']
            ? ECP_Content_Map::insert_after_section(
                $post,
                isset($proposal['payload']['after_section_id']) ? $proposal['payload']['after_section_id'] : '',
                $proposal['after_value'],
                isset($proposal['payload']['anchor_heading']) ? $proposal['payload']['anchor_heading'] : ''
            )
            : ECP_Content_Map::replace_section($post, $section_id, $proposal['after_value'], isset($proposal['payload']['heading']) ? $proposal['payload']['heading'] : '');

        if (is_wp_error($new_content)) {
            self::mark_failed($proposal, $new_content->get_error_message());

            return $new_content;
        }

        // _wp_put_post_revision with autosave semantics gives the editor the
        // "restore this revision" affordance without changing the live page.
        $revision_id = _wp_put_post_revision(array(
            'ID'           => $post->ID,
            'post_title'   => $post->post_title,
            'post_content' => $new_content,
            'post_excerpt' => $post->post_excerpt,
        ));

        if (is_wp_error($revision_id) || !$revision_id) {
            $error = is_wp_error($revision_id)
                ? $revision_id
                : new WP_Error('ecp_draft_failed', __('Could not create the draft revision.', 'enhanced-content-plugin'));

            self::mark_failed($proposal, $error->get_error_message());

            return $error;
        }

        ECP_Proposals::update($proposal_id, array(
            'status'      => ECP_Proposals::APPLIED,
            'applied_at'  => ECP_DB::now(),
            'revision_id' => (int) $revision_id,
            'revert_data' => array('kind' => 'draft_only', 'revision_id' => (int) $revision_id),
        ));

        self::count_trust_decision($proposal);

        ECP_Log::record(ECP_Log::PROPOSAL_APPLIED, array(
            'message'     => sprintf(
                /* translators: 1: change type, 2: post title */
                __('Staged %1$s for "%2$s" as a draft revision — the live page is unchanged.', 'enhanced-content-plugin'),
                ECP_Proposals::type_label($proposal['change_type']),
                $post->post_title
            ),
            'post_id'     => (int) $post->ID,
            'proposal_id' => (int) $proposal_id,
            'context'     => array('revision_id' => (int) $revision_id, 'draft' => true),
        ));

        return true;
    }

    /* --------------------------------------------------------------------
     * Rollback
     * ----------------------------------------------------------------- */

    /**
     * Undo an applied change.
     *
     * @return true|WP_Error
     */
    public static function revert($proposal_id) {
        $proposal = ECP_Proposals::get($proposal_id);

        if (!$proposal) {
            return new WP_Error('ecp_not_found', __('That change no longer exists.', 'enhanced-content-plugin'));
        }

        if (ECP_Proposals::APPLIED !== $proposal['status']) {
            return new WP_Error('ecp_not_applied', __('That change has not been applied, so there is nothing to undo.', 'enhanced-content-plugin'));
        }

        if (!ECP_Capabilities::can_review((int) $proposal['post_id'])) {
            return new WP_Error('ecp_forbidden', __('You do not have permission to approve changes to that post.', 'enhanced-content-plugin'));
        }

        $post = get_post((int) $proposal['post_id']);
        if (!$post) {
            return new WP_Error('ecp_no_post', __('The post no longer exists.', 'enhanced-content-plugin'));
        }

        $revert = $proposal['revert_data'];
        if (!is_array($revert) || empty($revert['kind'])) {
            return new WP_Error('ecp_no_revert_data', __('No rollback information was stored for this change.', 'enhanced-content-plugin'));
        }

        // Content rollbacks restore the ENTIRE pre-change body, because a
        // section can't be re-found by id once later edits rename headings.
        // The consequence: undoing an early change would silently wipe every
        // change applied to the same page after it. Refuse, name the newer
        // ones, and have them undone newest-first — each step then restores
        // exactly one change, which is what the button claims to do.
        if (in_array($revert['kind'], array('content', 'excerpt'), true)) {
            $newer = self::newer_applied_content_changes($proposal);

            if ($newer) {
                return new WP_Error('ecp_revert_order', sprintf(
                    /* translators: 1: count of newer changes, 2: list of change titles */
                    _n(
                        'A newer change was applied to this page after this one (%2$s). Undoing this one would silently wipe it. Undo the newer change first, then this one.',
                        '%1$d newer changes were applied to this page after this one (%2$s). Undoing this one would silently wipe them. Undo the newer changes first, newest first.',
                        count($newer),
                        'enhanced-content-plugin'
                    ),
                    count($newer),
                    implode('; ', array_slice(wp_list_pluck($newer, 'title'), 0, 3))
                ));
            }
        }

        // Snapshot the current state too, so an unwanted rollback is itself
        // recoverable from the post's revision history.
        self::snapshot($post);

        switch ($revert['kind']) {
            case 'title':
                $updated = wp_update_post(array(
                    'ID'         => $post->ID,
                    'post_title' => $revert['value'],
                    'post_name'  => $post->post_name,   // The slug stays, both ways.
                ), true);

                if (is_wp_error($updated)) {
                    return $updated;
                }
                break;

            case 'content':
                $updated = wp_update_post(array(
                    'ID'           => $post->ID,
                    'post_content' => $revert['value'],
                ), true);

                if (is_wp_error($updated)) {
                    return $updated;
                }
                break;

            case 'excerpt':
                $updated = wp_update_post(array(
                    'ID'           => $post->ID,
                    'post_excerpt' => $revert['value'],
                ), true);

                if (is_wp_error($updated)) {
                    return $updated;
                }
                break;

            case 'meta':
                if ('' === $revert['value']) {
                    delete_post_meta($post->ID, $revert['meta_key']);
                } else {
                    update_post_meta($post->ID, $revert['meta_key'], $revert['value']);
                }
                break;

            case 'meta_array':
                if (empty($revert['value'])) {
                    delete_post_meta($post->ID, $revert['meta_key']);
                } else {
                    update_post_meta($post->ID, $revert['meta_key'], $revert['value']);
                }

                if ('_article_sources' === $revert['meta_key']) {
                    update_post_meta($post->ID, '_map_citation_count', count((array) $revert['value']));
                }

                // Companion flags the writer had to set (e.g. _map_faq_enabled).
                if (!empty($revert['also_restore']) && is_array($revert['also_restore'])) {
                    foreach ($revert['also_restore'] as $meta_key => $value) {
                        if ('' === $value || null === $value) {
                            delete_post_meta($post->ID, $meta_key);
                        } else {
                            update_post_meta($post->ID, $meta_key, $value);
                        }
                    }
                }
                break;

            case 'alt':
                if ('' === $revert['value']) {
                    delete_post_meta((int) $revert['attachment_id'], '_wp_attachment_image_alt');
                } else {
                    update_post_meta((int) $revert['attachment_id'], '_wp_attachment_image_alt', $revert['value']);
                }

                if (!empty($revert['src'])) {
                    self::patch_inline_alt($post, $revert['src'], (string) $revert['value']);
                }
                break;

            case 'draft_only':
                // Nothing was published; discarding the draft revision is
                // enough, and WordPress prunes it on its own schedule.
                break;

            default:
                return new WP_Error('ecp_unknown_revert', __('The stored rollback information is in an unrecognised format.', 'enhanced-content-plugin'));
        }

        ECP_Proposals::update($proposal_id, array(
            'status'      => ECP_Proposals::REVERTED,
            'reviewed_by' => get_current_user_id(),
            'reviewed_at' => ECP_DB::now(),
        ));

        ECP_Log::record(ECP_Log::PROPOSAL_REVERTED, array(
            'message'     => sprintf(
                /* translators: 1: change type, 2: post title */
                __('Rolled back %1$s on "%2$s"', 'enhanced-content-plugin'),
                ECP_Proposals::type_label($proposal['change_type']),
                $post->post_title
            ),
            'post_id'     => (int) $post->ID,
            'proposal_id' => (int) $proposal_id,
        ));

        // A rollback is the strongest possible signal that this change type
        // is not yet trustworthy on this site.
        ECP_Trust_Ladder::record_revert($proposal['change_type']);

        return true;
    }

    /* --------------------------------------------------------------------
     * Helpers
     * ----------------------------------------------------------------- */

    /**
     * Force a revision of the current state.
     *
     * wp_save_post_revision() no-ops when the content is identical to the
     * latest revision, which is exactly what we want — it means a revision
     * already captures this state.
     *
     * @return int Revision id, or 0.
     */
    private static function snapshot($post) {
        if (!post_type_supports($post->post_type, 'revisions')) {
            return 0;
        }

        $revision_id = wp_save_post_revision($post->ID);

        if ($revision_id) {
            return (int) $revision_id;
        }

        $latest = wp_get_post_revisions($post->ID, array('numberposts' => 1));

        return $latest ? (int) key($latest) : 0;
    }

    /**
     * Applied content-touching changes on the same post that landed after
     * the given one. These are the changes a whole-body restore would wipe.
     *
     * @return array[] { id, title, applied_at }
     */
    private static function newer_applied_content_changes(array $proposal) {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT id, title, change_type, applied_at, revert_data
               FROM ' . ECP_DB::proposals_table() . '
              WHERE post_id = %d AND status = %s AND id != %d AND applied_at > %s',
            (int) $proposal['post_id'],
            ECP_Proposals::APPLIED,
            (int) $proposal['id'],
            (string) $proposal['applied_at']
        ), ARRAY_A);

        $newer = array();

        foreach ((array) $rows as $row) {
            $revert = ECP_DB::decode($row['revert_data']);

            // Only changes whose rollback touches post_content conflict.
            // Meta, alt-text and FAQ changes coexist with a body restore.
            if (isset($revert['kind']) && in_array($revert['kind'], array('content', 'excerpt'), true)) {
                $newer[] = array(
                    'id'         => (int) $row['id'],
                    'title'      => $row['title'] ? $row['title'] : ECP_Proposals::type_label($row['change_type']),
                    'applied_at' => $row['applied_at'],
                );
            }
        }

        return $newer;
    }

    /**
     * Count an approval in the trust ladder, once per proposal.
     *
     * Recorded here rather than at approval time because an approval whose
     * apply then failed never changed the site — counting it would pad the
     * approval rate that decides when a change type earns autopilot. The
     * flag guards a retried FAILED apply from counting the same human
     * decision twice.
     */
    private static function count_trust_decision(array $proposal) {
        $flags = is_array($proposal['flags']) ? $proposal['flags'] : array();

        if (!empty($flags['trust_counted'])) {
            return;
        }

        $flags['trust_counted'] = true;

        ECP_Proposals::update((int) $proposal['id'], array('flags' => $flags));
        ECP_Trust_Ladder::record_decision($proposal['change_type'], true);
    }

    /**
     * Record a failed apply.
     *
     * @param bool $keep_pending True when the change itself is still valid
     *                           and only the page state blocked it, so it
     *                           should stay in the review queue instead of
     *                           disappearing into the Failed tab.
     */
    private static function mark_failed(array $proposal, $message, $keep_pending = false) {
        ECP_Proposals::update((int) $proposal['id'], array(
            'status'      => $keep_pending ? ECP_Proposals::PENDING : ECP_Proposals::FAILED,
            'review_note' => (string) $message,
        ));

        ECP_Log::error(ECP_Log::PROPOSAL_FAILED, $message, array(
            'post_id'     => (int) $proposal['post_id'],
            'proposal_id' => (int) $proposal['id'],
        ));
    }

    /**
     * Record where search performance stood when the change went live.
     */
    private static function store_baseline($proposal_id, $post_id) {
        if (!ECP_Search_Data::is_connected()) {
            return;
        }

        $proposal = ECP_Proposals::get($proposal_id);
        if (!$proposal) {
            return;
        }

        $evidence = is_array($proposal['evidence']) ? $proposal['evidence'] : array();
        $evidence['baseline'] = ECP_Search_Data::baseline($post_id);

        ECP_Proposals::update($proposal_id, array('evidence' => $evidence));
    }

    /**
     * How an applied change has performed since.
     *
     * @return array|null
     */
    public static function performance($proposal_id) {
        $proposal = ECP_Proposals::get($proposal_id);

        if (!$proposal || ECP_Proposals::APPLIED !== $proposal['status']) {
            return null;
        }

        $evidence = is_array($proposal['evidence']) ? $proposal['evidence'] : array();

        if (empty($evidence['baseline'])) {
            return null;
        }

        return ECP_Search_Data::compare_to_baseline($evidence['baseline'], (int) $proposal['post_id']);
    }

    /**
     * Human wording for a performance verdict.
     *
     * Deliberately correlational. A ranking moved after the edit; that is not
     * proof the edit moved it.
     */
    public static function verdict_label($verdict) {
        $labels = array(
            'too_early' => __('Too early to tell', 'enhanced-content-plugin'),
            'improving' => __('Improved since this change', 'enhanced-content-plugin'),
            'stable'    => __('No clear movement', 'enhanced-content-plugin'),
            'declining' => __('Declined since this change', 'enhanced-content-plugin'),
        );

        return isset($labels[$verdict]) ? $labels[$verdict] : $verdict;
    }
}
