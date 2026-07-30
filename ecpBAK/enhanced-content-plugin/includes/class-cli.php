<?php
/**
 * WP-CLI Commands
 *
 * Usage:
 *   wp map recalculate   Recalculate word counts and citation counts
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

class ECP_CLI_Command {

    /**
     * Recalculate article health metrics (word counts, citation counts)
     * for all published posts.
     *
     * ## OPTIONS
     *
     * [--batch-size=<number>]
     * : Posts per batch. Default 200.
     *
     * ## EXAMPLES
     *
     *     wp map recalculate
     *     wp map recalculate --batch-size=500
     */
    public function recalculate($args, $assoc_args) {
        $batch_size = isset($assoc_args['batch-size']) ? max(1, intval($assoc_args['batch-size'])) : 200;

        // Establish the total from the first batch
        $first = ECP_Article_Health::recalculate_batch(0, $batch_size);
        $total = $first['total'];
        $processed = $first['processed'];

        if ($total === 0) {
            WP_CLI::success('No published posts to process.');
            return;
        }

        $progress = \WP_CLI\Utils\make_progress_bar('Recalculating article health', $total);
        $progress->tick($processed);

        while ($processed < $total) {
            $result = ECP_Article_Health::recalculate_batch($processed, $batch_size);
            if ($result['processed'] === 0) {
                break;
            }
            $processed += $result['processed'];
            $progress->tick($result['processed']);
        }

        $progress->finish();
        delete_transient('map_health_stats');

        WP_CLI::success(sprintf('Recalculated metrics for %d posts.', $processed));
    }
}

WP_CLI::add_command('map', 'ECP_CLI_Command');
