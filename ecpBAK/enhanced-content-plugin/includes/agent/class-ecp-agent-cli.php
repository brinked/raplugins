<?php
/**
 * WP-CLI commands for the agent.
 *
 * The CLI matters more here than in most plugins: a first full scan of a large
 * site, and the first analysis batch, both want to run outside a web request
 * where there is no execution-time limit and the operator can watch.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

/**
 * Manage the Enhanced Content SEO agent.
 */
class ECP_Agent_CLI {

    /**
     * Score every eligible post. Costs nothing — no AI calls.
     *
     * ## OPTIONS
     *
     * [--batch=<n>]
     * : Posts per batch. Default 50.
     *
     * ## EXAMPLES
     *
     *     wp ecp scan
     *     wp ecp scan --batch=200
     */
    public function scan($args, $assoc_args) {
        $batch = isset($assoc_args['batch']) ? max(1, (int) $assoc_args['batch']) : 50;

        $offset = 0;
        $total = null;
        $progress = null;

        do {
            $result = ECP_Opportunity_Engine::scan_batch($offset, $batch);

            if (null === $total) {
                $total = (int) $result['total'];

                if (0 === $total) {
                    WP_CLI::warning('No eligible posts found. Check the post types and minimum age in Settings.');

                    return;
                }

                $progress = \WP_CLI\Utils\make_progress_bar('Scoring posts', $total);
            }

            $progress->tick($result['processed']);
            $offset += $result['processed'];
        } while ($result['processed'] > 0 && $offset < $total);

        $progress->finish();

        $stats = ECP_Opportunity_Engine::stats();

        WP_CLI::success(sprintf(
            'Scored %d posts. %d have open opportunities, average score %s.',
            $offset,
            $stats['open'],
            $stats['avg_score']
        ));
    }

    /**
     * Analyze posts with AI and create change proposals. This spends money.
     *
     * ## OPTIONS
     *
     * [--post=<id>]
     * : Analyze one specific post.
     *
     * [--limit=<n>]
     * : How many posts to take off the top of the queue. Default 5.
     *
     * [--dry-run]
     * : Show what would be analyzed and stop.
     *
     * ## EXAMPLES
     *
     *     wp ecp analyze --limit=3
     *     wp ecp analyze --post=42
     *     wp ecp analyze --limit=20 --dry-run
     */
    public function analyze($args, $assoc_args) {
        if (!ECP_Agent_Settings::is_ready()) {
            WP_CLI::error('The agent is not ready. Enable it and configure an AI provider in Settings.');
        }

        $dry_run = isset($assoc_args['dry-run']);

        if (isset($assoc_args['post'])) {
            $post_ids = array((int) $assoc_args['post']);
        } else {
            $limit = isset($assoc_args['limit']) ? max(1, (int) $assoc_args['limit']) : 5;
            $post_ids = ECP_Opportunity_Engine::next_for_analysis($limit);
        }

        if (!$post_ids) {
            WP_CLI::warning('Nothing in the queue. Run `wp ecp scan` first.');

            return;
        }

        if ($dry_run) {
            $rows = array();

            foreach ($post_ids as $post_id) {
                $opportunity = ECP_Opportunity_Engine::get($post_id);

                $rows[] = array(
                    'ID'     => $post_id,
                    'Title'  => get_the_title($post_id),
                    'Score'  => $opportunity ? $opportunity['score'] : '—',
                    'Reason' => $opportunity ? ECP_Opportunity_Engine::reason_label($opportunity['primary_reason']) : '—',
                    'Issues' => $opportunity ? count($opportunity['reasons']) : 0,
                );
            }

            \WP_CLI\Utils\format_items('table', $rows, array('ID', 'Title', 'Score', 'Reason', 'Issues'));
            WP_CLI::log('Dry run — nothing was sent to the AI provider.');

            return;
        }

        $created = 0;
        $failed = 0;

        foreach ($post_ids as $post_id) {
            WP_CLI::log(sprintf('Analyzing #%d — %s', $post_id, get_the_title($post_id)));

            $result = ECP_Analyzer::analyze($post_id, array('trigger_source' => 'cli'));

            if (is_wp_error($result)) {
                WP_CLI::warning('  ' . $result->get_error_message());
                $failed++;
                continue;
            }

            $count = count($result['proposals']);
            $created += $count;

            WP_CLI::log(sprintf('  %d change(s) proposed.', $count));

            foreach ((array) $result['skipped'] as $skip) {
                if (is_array($skip) && isset($skip['reason'])) {
                    WP_CLI::debug('  skipped: ' . $skip['reason']);
                }
            }
        }

        $budget = ECP_AI_Client::budget_status();

        WP_CLI::success(sprintf(
            '%d change(s) created across %d post(s), %d failed. Spend this month: $%.2f.',
            $created,
            count($post_ids) - $failed,
            $failed,
            $budget['monthly_spent']
        ));

        if ($created > 0) {
            WP_CLI::log('Review them at: ' . admin_url('admin.php?page=ecp-review'));
        }
    }

    /**
     * Find pages competing for the same searches. Free — no AI calls.
     *
     * ## EXAMPLES
     *
     *     wp ecp clusters
     */
    public function clusters($args, $assoc_args) {
        $detect = isset($assoc_args['detect']) || !ECP_Clusters::stats()['total'];

        if ($detect) {
            $result = ECP_Clusters::detect();

            WP_CLI::log(sprintf(
                'Detection complete (source: %s). %d group(s) recorded.',
                $result['source'],
                $result['found']
            ));

            if ('titles' === $result['source'] && $result['found'] > 0) {
                WP_CLI::warning('These came from title similarity, not search data. Check them before acting.');
            }
        }

        $list = ECP_Clusters::query(array(
            'status' => isset($assoc_args['status']) ? $assoc_args['status'] : ECP_Clusters::STATUS_OPEN,
            'limit'  => 50,
        ));

        if (!$list['items']) {
            WP_CLI::log('No competing pages found.');

            return;
        }

        $rows = array();

        foreach ($list['items'] as $cluster) {
            $titles = array();
            foreach ((array) $cluster['member_ids'] as $post_id) {
                $titles[] = get_the_title((int) $post_id);
            }

            $rows[] = array(
                'ID'      => $cluster['id'],
                'Score'   => $cluster['score'],
                'Topic'   => $cluster['label'],
                'Pages'   => implode(' / ', $titles),
                'Status'  => ECP_Clusters::status_label($cluster['status']),
            );
        }

        \WP_CLI\Utils\format_items(
            isset($assoc_args['format']) ? $assoc_args['format'] : 'table',
            $rows,
            array('ID', 'Score', 'Topic', 'Pages', 'Status')
        );

        WP_CLI::log('Analyze one with: wp ecp analyze-cluster <id>');
    }

    /**
     * Decide what to do about one group of competing pages. This spends money.
     *
     * ## OPTIONS
     *
     * <id>
     * : The cluster ID from `wp ecp clusters`.
     *
     * ## EXAMPLES
     *
     *     wp ecp analyze-cluster 3
     */
    public function analyze_cluster($args) {
        if (!ECP_Agent_Settings::is_ready()) {
            WP_CLI::error('The agent is not ready. Configure an AI provider first.');
        }

        $result = ECP_Analyzer::analyze_cluster((int) $args[0], array('trigger_source' => 'cli'));

        if (is_wp_error($result)) {
            WP_CLI::error($result->get_error_message());
        }

        $recommendation = $result['recommendation'];

        if (!empty($recommendation['summary'])) {
            WP_CLI::log('');
            WP_CLI::log(WP_CLI::colorize('%9Verdict%n'));
            WP_CLI::log($recommendation['summary']);
        }

        if (!empty($recommendation['primary_post_id'])) {
            WP_CLI::log('');
            WP_CLI::log(sprintf(
                'Should own the topic: %s (#%d)',
                get_the_title((int) $recommendation['primary_post_id']),
                (int) $recommendation['primary_post_id']
            ));

            if (!empty($recommendation['primary_reason'])) {
                WP_CLI::log('  ' . $recommendation['primary_reason']);
            }
        }

        foreach ((array) $recommendation['members'] as $member) {
            WP_CLI::log('');
            WP_CLI::log(sprintf(
                '#%d %s — %s',
                (int) $member['post_id'],
                get_the_title((int) $member['post_id']),
                ECP_Clusters::verdict_label($member['verdict'])
            ));

            if (!empty($member['rationale'])) {
                WP_CLI::log('  ' . $member['rationale']);
            }
        }

        if (!empty($recommendation['merge_checklist'])) {
            WP_CLI::log('');
            WP_CLI::log(WP_CLI::colorize('%3Manual steps for the merge%n'));
            foreach ($recommendation['merge_checklist'] as $index => $step) {
                WP_CLI::log(sprintf('  %d. %s', $index + 1, $step));
            }
        }

        WP_CLI::log('');
        WP_CLI::success(sprintf('%d change(s) proposed. Review with: wp ecp proposals', count($result['proposals'])));
    }

    /**
     * Work out what a reader wants from a page, and what it is missing.
     *
     * This spends money — one AI call per page.
     *
     * ## OPTIONS
     *
     * <id>
     * : The post ID.
     *
     * ## EXAMPLES
     *
     *     wp ecp gaps 42
     */
    public function gaps($args) {
        if (!ECP_Agent_Settings::is_ready()) {
            WP_CLI::error('The agent is not ready. Configure an AI provider first.');
        }

        $result = ECP_Content_Gaps::analyze((int) $args[0], array('trigger_source' => 'cli'));

        if (is_wp_error($result)) {
            WP_CLI::error($result->get_error_message());
        }

        $report = $result['report'];

        WP_CLI::log('');
        WP_CLI::log(WP_CLI::colorize('%9The reader%n'));
        WP_CLI::log($report['reader']);
        WP_CLI::log('');
        WP_CLI::log(WP_CLI::colorize('%9Verdict%n') . sprintf(' (%d%% complete)', (int) $report['completeness']));
        WP_CLI::log($report['verdict']);

        if (!$report['had_search']) {
            WP_CLI::warning('No Search Console data for this page, so the questions are inferred from the title alone.');
        }

        $sections = array(
            'covered'   => array('%2Already answered%n', $report['covered']),
            'gaps'      => array('%3Not answered — the agent can write these%n', $report['gaps']),
            'for_you'   => array('%1Needs a fact only you have%n', $report['for_you']),
            'elsewhere' => array('%9Belongs on another page%n', $report['elsewhere']),
        );

        foreach ($sections as $entries) {
            list($heading, $items) = $entries;

            if (!$items) {
                continue;
            }

            WP_CLI::log('');
            WP_CLI::log(WP_CLI::colorize($heading));

            foreach ($items as $item) {
                WP_CLI::log(sprintf(
                    '  %s %s',
                    !empty($item['backed_by_search']) ? '[searched]' : '          ',
                    $item['question']
                ));

                if (!empty($item['needed_from_owner'])) {
                    WP_CLI::log('             → ' . $item['needed_from_owner']);
                }
            }
        }

        WP_CLI::log('');
        WP_CLI::success(sprintf('%d section(s) drafted for review.', count($result['proposals'])));
    }

    /**
     * Propose inbound links to pages nothing links to. Free — no AI calls.
     *
     * ## OPTIONS
     *
     * [--post=<id>]
     * : Only this page. Otherwise works through every orphan.
     *
     * [--limit=<n>]
     * : How many orphaned pages to handle. Default 5.
     *
     * [--dry-run]
     * : List what would be proposed and stop.
     *
     * ## EXAMPLES
     *
     *     wp ecp link-orphans --dry-run
     *     wp ecp link-orphans --limit=10
     */
    public function link_orphans($args, $assoc_args) {
        $dry_run = isset($assoc_args['dry-run']);
        $limit = isset($assoc_args['limit']) ? max(1, (int) $assoc_args['limit']) : 5;

        if (isset($assoc_args['post'])) {
            $targets = array(array('post_id' => (int) $assoc_args['post'], 'post_title' => get_the_title((int) $assoc_args['post'])));
        } else {
            $targets = ECP_Link_Suggestions::orphans($limit);
        }

        if (!$targets) {
            WP_CLI::success('No orphaned pages found — everything has at least one internal link pointing at it.');

            return;
        }

        $total = 0;

        foreach ($targets as $target) {
            $result = ECP_Link_Suggestions::build($target['post_id'], $dry_run);

            if (is_wp_error($result)) {
                WP_CLI::warning(sprintf('#%d: %s', $target['post_id'], $result->get_error_message()));
                continue;
            }

            WP_CLI::log(sprintf('%s (#%d)', $target['post_title'], $target['post_id']));

            if (!$result['candidates']) {
                WP_CLI::log('  no page mentions this topic in a linkable way');
                continue;
            }

            foreach ($result['candidates'] as $candidate) {
                WP_CLI::log(sprintf(
                    '  ← "%s" in %s',
                    $candidate['phrase'],
                    $candidate['post_title']
                ));
            }

            $total += count($result['proposals']);
        }

        WP_CLI::log('');

        if ($dry_run) {
            WP_CLI::log('Dry run — nothing was proposed.');

            return;
        }

        WP_CLI::success(sprintf('%d link(s) proposed. Review with: wp ecp proposals', $total));
    }

    /**
     * List pending proposals.
     *
     * ## OPTIONS
     *
     * [--status=<status>]
     * : pending, approved, applied, rejected. Default pending.
     *
     * [--post=<id>]
     * : Only for this post.
     *
     * [--format=<format>]
     * : table, csv, json, count. Default table.
     */
    public function proposals($args, $assoc_args) {
        $result = ECP_Proposals::query(array(
            'status'   => isset($assoc_args['status']) ? $assoc_args['status'] : ECP_Proposals::PENDING,
            'post_id'  => isset($assoc_args['post']) ? (int) $assoc_args['post'] : 0,
            'per_page' => 100,
        ));

        if (!$result['items']) {
            WP_CLI::log('None found.');

            return;
        }

        $rows = array();
        foreach ($result['items'] as $item) {
            $rows[] = array(
                'ID'         => $item['id'],
                'Post'       => $item['post_title'],
                'Type'       => ECP_Proposals::type_label($item['change_type']),
                'Risk'       => ECP_Proposals::risk_label($item['risk']),
                'Confidence' => $item['confidence'] . '%',
                'Change'     => ECP_Diff::summary($item['before_value'], $item['after_value']),
                'Title'      => $item['title'],
            );
        }

        \WP_CLI\Utils\format_items(
            isset($assoc_args['format']) ? $assoc_args['format'] : 'table',
            $rows,
            array('ID', 'Post', 'Type', 'Risk', 'Confidence', 'Change', 'Title')
        );
    }

    /**
     * Show one proposal in full, including the diff.
     *
     * ## OPTIONS
     *
     * <id>
     * : The proposal ID.
     */
    public function show($args) {
        $proposal = ECP_Proposals::get((int) $args[0]);

        if (!$proposal) {
            WP_CLI::error('No such proposal.');
        }

        WP_CLI::log('');
        WP_CLI::log(WP_CLI::colorize('%9' . $proposal['title'] . '%n'));
        WP_CLI::log(str_repeat('─', 60));
        WP_CLI::log('Post:       ' . get_the_title((int) $proposal['post_id']) . ' (#' . $proposal['post_id'] . ')');
        WP_CLI::log('Type:       ' . ECP_Proposals::type_label($proposal['change_type']));
        WP_CLI::log('Risk:       ' . ECP_Proposals::risk_label($proposal['risk']));
        WP_CLI::log('Confidence: ' . $proposal['confidence'] . '%');
        WP_CLI::log('Status:     ' . ECP_Proposals::status_label($proposal['status']));
        WP_CLI::log('');
        WP_CLI::log(WP_CLI::colorize('%9Why%n'));
        WP_CLI::log($proposal['rationale']);

        $flags = is_array($proposal['flags']) ? $proposal['flags'] : array();

        if (!empty($flags['unverified_claims'])) {
            WP_CLI::log('');
            WP_CLI::log(WP_CLI::colorize('%3Check these claims%n'));
            foreach ($flags['unverified_claims'] as $claim) {
                WP_CLI::log('  • ' . $claim);
            }
        }

        if (!empty($flags['new_figures'])) {
            WP_CLI::log('');
            WP_CLI::log(WP_CLI::colorize('%3New figures not in the original%n'));
            WP_CLI::log('  ' . implode(', ', $flags['new_figures']));
        }

        WP_CLI::log('');
        WP_CLI::log(WP_CLI::colorize('%9Currently%n'));
        WP_CLI::log($proposal['before_value'] ? ECP_Content_Map::to_text($proposal['before_value']) : '(nothing)');
        WP_CLI::log('');
        WP_CLI::log(WP_CLI::colorize('%9Proposed%n'));
        WP_CLI::log(ECP_Content_Map::to_text($proposal['after_value']));
        WP_CLI::log('');
    }

    /**
     * Approve and apply a proposal.
     *
     * ## OPTIONS
     *
     * <id>...
     * : One or more proposal IDs.
     *
     * [--yes]
     * : Skip the confirmation prompt.
     */
    public function approve($args, $assoc_args) {
        WP_CLI::confirm(
            sprintf('Apply %d change(s) to live content?', count($args)),
            $assoc_args
        );

        $applied = 0;

        foreach ($args as $id) {
            $result = ECP_Applier::approve_and_apply((int) $id);

            if (is_wp_error($result)) {
                WP_CLI::warning(sprintf('#%d: %s', (int) $id, $result->get_error_message()));
                continue;
            }

            $applied++;
            WP_CLI::log(sprintf('#%d applied.', (int) $id));
        }

        WP_CLI::success(sprintf('%d of %d applied.', $applied, count($args)));
    }

    /**
     * Reject a proposal.
     *
     * ## OPTIONS
     *
     * <id>...
     * : One or more proposal IDs.
     *
     * [--note=<note>]
     * : Why. Recorded in the audit log.
     */
    public function reject($args, $assoc_args) {
        $note = isset($assoc_args['note']) ? $assoc_args['note'] : '';

        foreach ($args as $id) {
            $result = ECP_Proposals::reject((int) $id, $note);

            if (is_wp_error($result)) {
                WP_CLI::warning(sprintf('#%d: %s', (int) $id, $result->get_error_message()));
            }
        }

        WP_CLI::success('Done.');
    }

    /**
     * Roll back an applied change.
     *
     * ## OPTIONS
     *
     * <id>
     * : The proposal ID.
     */
    public function revert($args) {
        $result = ECP_Applier::revert((int) $args[0]);

        if (is_wp_error($result)) {
            WP_CLI::error($result->get_error_message());
        }

        WP_CLI::success('Rolled back.');
    }

    /**
     * Show agent status.
     */
    public function status() {
        $budget = ECP_AI_Client::budget_status();
        $opportunities = ECP_Opportunity_Engine::stats();
        $counts = ECP_Proposals::counts();
        $search = ECP_Search_Data::status();

        $rows = array(
            array('Setting', 'Value'),
            array('Agent enabled', ECP_Agent_Settings::is_on('agent_enabled') ? 'yes' : 'no'),
            array('Configured', ECP_Agent_Settings::is_ready() ? 'yes' : 'no'),
            array('Provider', ECP_Agent_Settings::get('provider') . ' / ' . ECP_Agent_Settings::get('model')),
            array('API key from', ECP_Agent_Settings::api_key_source()),
            array('Approval mode', ECP_Agent_Settings::get('approval_mode')),
            array('Search data', $search['label']),
            array('Posts scored', (string) $opportunities['total']),
            array('Open opportunities', (string) $opportunities['open']),
            array('Pending review', (string) (isset($counts['pending']) ? $counts['pending'] : 0)),
            array('Applied', (string) (isset($counts['applied']) ? $counts['applied'] : 0)),
            array('Spend this month', $budget['priced'] ? sprintf('$%.2f of $%.2f', $budget['monthly_spent'], $budget['monthly_cap']) : 'not priced'),
            array('Analyses today', sprintf('%d of %d', $budget['daily_used'], $budget['daily_cap'])),
        );

        $header = array_shift($rows);
        $items = array();

        foreach ($rows as $row) {
            $items[] = array($header[0] => $row[0], $header[1] => $row[1]);
        }

        \WP_CLI\Utils\format_items('table', $items, $header);
    }

    /**
     * Create the agent database tables. Use if activation was interrupted.
     */
    public function install() {
        ECP_DB::install();
        update_option('ecp_db_version', ECP_DB::SCHEMA_VERSION, false);

        WP_CLI::success('Tables created or updated.');
    }

    /**
     * Pull Search Console data through Google Site Kit.
     *
     * Best run from the CLI on a large site — a web request can time out
     * partway through, because each ranking page costs one extra API call
     * for its query breakdown.
     *
     * ## OPTIONS
     *
     * [--days=<n>]
     * : How many days to pull. Default 28.
     *
     * ## EXAMPLES
     *
     *     wp ecp sync-search
     *     wp ecp sync-search --days=90
     */
    public function sync_search($args, $assoc_args) {
        $status = ECP_Search_Data::status();

        if ('sitekit' !== $status['source']) {
            WP_CLI::error(
                'No live Site Kit connection. Install and connect Google Site Kit, '
                . 'or import a CSV with: wp ecp import-metrics <file>'
            );
        }

        $days = isset($assoc_args['days']) ? ECP_Search_Data::valid_window($assoc_args['days']) : 0;

        WP_CLI::log($days ? sprintf('Fetching the last %d days from Search Console…', $days) : 'Fetching every reporting period from Search Console…');

        $result = $days ? ECP_Search_Data::sync($days) : ECP_Search_Data::sync_all();

        if (is_wp_error($result)) {
            WP_CLI::error($result->get_error_message());
        }

        if (0 === (int) $result['posts']) {
            WP_CLI::warning(
                'Google returned data, but none of the URLs matched a page on this site. '
                . 'The Search Console property is probably a different domain to this install '
                . '(www vs non-www, or http vs https).'
            );

            return;
        }

        WP_CLI::success(sprintf(
            '%d page(s) matched, %d row(s) stored. %d post(s) now have search data.',
            (int) $result['posts'],
            (int) $result['rows'],
            ECP_Search_Data::covered_post_count()
        ));

        WP_CLI::log('Re-score with the new data: wp ecp scan');
    }

    /**
     * Import a Search Console CSV export.
     *
     * ## OPTIONS
     *
     * <file>
     * : Path to the CSV.
     *
     * [--date=<date>]
     * : Date to stamp the rows with (YYYY-MM-DD). Defaults to today.
     */
    public function import_metrics($args, $assoc_args) {
        $result = ECP_Search_Data::import_csv($args[0], isset($assoc_args['date']) ? $assoc_args['date'] : '');

        if (is_wp_error($result)) {
            WP_CLI::error($result->get_error_message());
        }

        WP_CLI::success(sprintf('%d rows imported, %d matched to posts.', $result['rows'], $result['matched']));

        if (!empty($result['unmatched'])) {
            WP_CLI::warning('Some URLs did not match a post, for example:');
            foreach (array_slice($result['unmatched'], 0, 5) as $url) {
                WP_CLI::log('  ' . $url);
            }
        }
    }

    /**
     * Send the digest email now.
     */
    public function digest() {
        WP_CLI::success(ECP_Digest::send_test() ? 'Digest sent.' : 'wp_mail() returned false — check the site\'s email configuration.');
    }
}

WP_CLI::add_command('ecp', 'ECP_Agent_CLI');
