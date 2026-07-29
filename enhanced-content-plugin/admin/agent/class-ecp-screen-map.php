<?php
/**
 * Topical Map: the plan for what to cover, shown cluster by cluster —
 * with the Content Restraint Engine's verdicts front and center.
 *
 * The screen leads with restraint on purpose. Every content tool can
 * generate fifty article ideas; the pitch here is the opposite — of the
 * ideas mapped, how many did the engine talk you out of, and why. Every
 * verdict shows its evidence: the query, the position, the page that
 * already owns the ground.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ECP_Screen_Map {

    public static function render() {
        if (!ECP_Capabilities::can_view()) {
            wp_die(esc_html__('You do not have permission to view this page.', 'enhanced-content-plugin'));
        }

        $seeds = ECP_Topical_Map::seeds();
        $stats = ECP_Topical_Map::stats();
        $can_review = ECP_Capabilities::can_review();

        $current = isset($_GET['seed']) ? sanitize_text_field(wp_unslash($_GET['seed'])) : '';  // phpcs:ignore WordPress.Security.NonceVerification

        if ('' === $current && $seeds) {
            $current = $seeds[0]['seed'];
        }

        $rows = $current ? ECP_Topical_Map::map_for($current) : array();
        $clusters = self::group($rows);
        $profile_seeds = (array) ECP_Site_Profile::get('seed_topics');

        ?>
        <div class="wrap ecp-wrap">
            <h1><?php esc_html_e('Topical Map', 'enhanced-content-plugin'); ?></h1>

            <?php ECP_Admin_Menu::header('ecp-map'); ?>

            <p class="ecp-narrative">
                <?php esc_html_e('What this site should cover to own its subject — and what it should not write, because a page already holds the ground or the territory is not yours. Built from your own Search Console data and inventory; nothing is scraped and no demand is invented.', 'enhanced-content-plugin'); ?>
            </p>

            <?php if ($stats['mapped'] > 0) : ?>
                <div class="ecp-panel ecp-map-restraint">
                    <p class="ecp-map-restraint-line">
                        <?php
                        printf(
                            /* translators: 1: total mapped, 2: worth writing, 3: expansions, 4: not worth writing */
                            esc_html__('%1$s topics mapped so far: %2$s worth a new page, %3$s better as improvements to pages you already have, and %4$s the restraint engine talked you out of. Publish only what deserves to exist.', 'enhanced-content-plugin'),
                            esc_html(number_format_i18n($stats['mapped'])),
                            esc_html(number_format_i18n($stats['write'])),
                            esc_html(number_format_i18n($stats['expand'] + $stats['subsection'])),
                            esc_html(number_format_i18n($stats['skipped']))
                        );
                        ?>
                    </p>
                </div>
            <?php endif; ?>

            <?php if ($can_review && ECP_Agent_Settings::is_ready()) : ?>
                <div class="ecp-panel">
                    <h2><?php esc_html_e('Grow a map', 'enhanced-content-plugin'); ?></h2>
                    <div class="ecp-map-form">
                        <label class="screen-reader-text" for="ecp-map-seed"><?php esc_html_e('Seed topic', 'enhanced-content-plugin'); ?></label>
                        <input type="text" id="ecp-map-seed" class="regular-text" list="ecp-map-seed-options"
                               placeholder="<?php esc_attr_e('e.g. outdoor kitchens', 'enhanced-content-plugin'); ?>">
                        <datalist id="ecp-map-seed-options">
                            <?php foreach ($profile_seeds as $suggestion) : ?>
                                <option value="<?php echo esc_attr($suggestion); ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                        <button type="button" class="button button-primary" id="ecp-build-map">
                            <?php esc_html_e('Map this topic', 'enhanced-content-plugin'); ?>
                        </button>
                        <span class="ecp-map-form-status" aria-live="polite"></span>
                    </div>
                    <p class="description">
                        <?php esc_html_e('One AI call per map, from your monthly allowance. Re-running a seed refreshes its map; your approvals and dismissals are kept.', 'enhanced-content-plugin'); ?>
                    </p>
                </div>
            <?php endif; ?>

            <?php if (count($seeds) > 1) : ?>
                <p class="ecp-map-seeds">
                    <?php esc_html_e('Maps:', 'enhanced-content-plugin'); ?>
                    <?php foreach ($seeds as $seed_row) : ?>
                        <a class="ecp-chip<?php echo $seed_row['seed'] === $current ? ' ecp-chip-safe' : ''; ?>"
                           href="<?php echo esc_url(add_query_arg(array('page' => 'ecp-map', 'seed' => rawurlencode($seed_row['seed'])), admin_url('admin.php'))); ?>">
                            <?php echo esc_html($seed_row['seed']); ?>
                        </a>
                    <?php endforeach; ?>
                </p>
            <?php endif; ?>

            <?php if (!$rows) : ?>
                <div class="ecp-panel">
                    <p class="ecp-muted"><?php esc_html_e('No map yet. Seed one above — your site profile\'s core topics are good starting points.', 'enhanced-content-plugin'); ?></p>
                </div>
            <?php else : ?>
                <?php foreach ($clusters as $cluster) : ?>
                    <div class="ecp-panel ecp-map-cluster">
                        <div class="ecp-map-cluster-head">
                            <h2><?php echo esc_html($cluster['label']); ?></h2>
                            <?php if ($can_review && $cluster['open'] > 0) : ?>
                                <button type="button" class="button button-small ecp-cluster-approve"
                                        data-seed="<?php echo esc_attr($current); ?>"
                                        data-parent="<?php echo esc_attr($cluster['label']); ?>">
                                    <?php
                                    printf(
                                        /* translators: %d: number of open topics */
                                        esc_html(_n('Approve this cluster (%d topic)', 'Approve this cluster (%d topics)', (int) $cluster['open'], 'enhanced-content-plugin')),
                                        (int) $cluster['open']
                                    );
                                    ?>
                                </button>
                                <span class="ecp-row-status" aria-live="polite"></span>
                            <?php endif; ?>
                        </div>

                        <?php foreach ($cluster['rows'] as $row) : ?>
                            <?php self::render_topic($row, $can_review); ?>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Group map rows into clusters keyed by pillar, ordered by the best
     * measured evidence in each.
     *
     * @return array[] { label, open, rows }
     */
    private static function group(array $rows) {
        $clusters = array();

        foreach ($rows as $row) {
            $key = '' === $row['parent'] ? $row['topic'] : $row['parent'];

            if (!isset($clusters[$key])) {
                $clusters[$key] = array('label' => $key, 'open' => 0, 'score' => 0.0, 'rows' => array());
            }

            $clusters[$key]['rows'][] = $row;
            $clusters[$key]['score'] = max($clusters[$key]['score'], (float) $row['score']);

            if (ECP_Topical_Map::PROPOSED === $row['status'] && ECP_Topical_Map::SKIP !== $row['verdict']) {
                $clusters[$key]['open']++;
            }
        }

        usort($clusters, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        return $clusters;
    }

    /**
     * One topic node: what, why, the verdict with its evidence, and the
     * owner's controls.
     */
    private static function render_topic(array $row, $can_review) {
        $decided = ECP_Topical_Map::PROPOSED !== $row['status'];
        $skip = ECP_Topical_Map::SKIP === $row['verdict'];
        ?>
        <div class="ecp-map-topic<?php echo $skip ? ' is-restrained' : ''; ?><?php echo ECP_Topical_Map::DISMISSED === $row['status'] ? ' is-dismissed' : ''; ?>" data-id="<?php echo esc_attr($row['id']); ?>">
            <div class="ecp-map-topic-main">
                <p class="ecp-map-topic-title">
                    <strong><?php echo esc_html($row['topic']); ?></strong>
                    <span class="ecp-chip"><?php echo esc_html(ECP_Topical_Map::page_type_label($row['page_type'])); ?></span>
                    <?php if ($row['intent']) : ?>
                        <span class="ecp-chip"><?php echo esc_html($row['intent']); ?></span>
                    <?php endif; ?>
                    <?php if (ECP_Topical_Map::APPROVED === $row['status']) : ?>
                        <span class="ecp-chip ecp-chip-safe"><?php esc_html_e('Approved', 'enhanced-content-plugin'); ?></span>
                    <?php elseif (ECP_Topical_Map::DISMISSED === $row['status']) : ?>
                        <span class="ecp-chip"><?php esc_html_e('Dismissed', 'enhanced-content-plugin'); ?></span>
                    <?php endif; ?>
                </p>

                <p class="ecp-map-verdict<?php echo $skip ? ' is-skip' : ''; ?>">
                    <strong><?php echo esc_html(ECP_Topical_Map::verdict_label($row['verdict'])); ?><?php echo $row['verdict_reason'] ? ':' : ''; ?></strong>
                    <?php echo esc_html($row['verdict_reason']); ?>
                    <?php if ((int) $row['matched_post_id']) : ?>
                        <a href="<?php echo esc_url(get_edit_post_link((int) $row['matched_post_id'])); ?>">
                            <?php esc_html_e('Open that page', 'enhanced-content-plugin'); ?> &rarr;
                        </a>
                    <?php endif; ?>
                </p>

                <?php if ($row['main_query']) : ?>
                    <p class="ecp-muted">
                        <?php
                        $basis = is_array($row['match_basis']) ? $row['match_basis'] : array();
                        $measured = isset($basis['query_owner']['impressions']) ? (int) $basis['query_owner']['impressions'] : 0;
                        $estimated = isset($basis['volume']) ? (int) $basis['volume'] : 0;

                        if ($measured > 0) {
                            printf(
                                /* translators: 1: query, 2: impressions */
                                esc_html__('Main query: "%1$s" — %2$s measured monthly impressions.', 'enhanced-content-plugin'),
                                esc_html($row['main_query']),
                                esc_html(number_format_i18n($measured))
                            );
                        } elseif ($estimated > 0) {
                            printf(
                                /* translators: 1: query, 2: estimated searches */
                                esc_html__('Main query: "%1$s" — roughly %2$s monthly searches (licensed estimate, not your own data).', 'enhanced-content-plugin'),
                                esc_html($row['main_query']),
                                esc_html(number_format_i18n($estimated))
                            );
                        } else {
                            printf(
                                /* translators: %s: query */
                                esc_html__('Main query: "%s" — no measured demand yet; this is a hypothesis.', 'enhanced-content-plugin'),
                                esc_html($row['main_query'])
                            );
                        }
                        ?>
                    </p>
                <?php endif; ?>

                <?php if ($row['business_relevance']) : ?>
                    <p class="ecp-muted"><?php echo esc_html($row['business_relevance']); ?></p>
                <?php endif; ?>

                <?php if ($row['evidence_needs'] && !$skip) : ?>
                    <p class="ecp-muted ecp-map-evidence">
                        <?php
                        printf(
                            /* translators: %s: what the owner must supply */
                            esc_html__('You would need to supply: %s', 'enhanced-content-plugin'),
                            esc_html($row['evidence_needs'])
                        );
                        ?>
                    </p>
                <?php endif; ?>
            </div>

            <?php if ($can_review && !$skip) : ?>
                <div class="ecp-map-topic-actions">
                    <?php if (!$decided) : ?>
                        <button type="button" class="button button-small button-primary ecp-topic-act" data-act="approve" data-id="<?php echo esc_attr($row['id']); ?>">
                            <?php esc_html_e('Approve', 'enhanced-content-plugin'); ?>
                        </button>
                        <button type="button" class="button-link ecp-topic-act" data-act="dismiss" data-id="<?php echo esc_attr($row['id']); ?>">
                            <?php esc_html_e('Dismiss', 'enhanced-content-plugin'); ?>
                        </button>
                    <?php else : ?>
                        <button type="button" class="button button-small ecp-topic-act" data-act="reopen" data-id="<?php echo esc_attr($row['id']); ?>">
                            <?php esc_html_e('Reconsider', 'enhanced-content-plugin'); ?>
                        </button>
                    <?php endif; ?>
                    <span class="ecp-row-status" aria-live="polite"></span>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}
