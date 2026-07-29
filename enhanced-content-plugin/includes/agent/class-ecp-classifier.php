<?php
/**
 * Assigns every page a topic, search intent and funnel stage.
 *
 * This is Phase 1's only AI spend, and it is engineered to be cheap:
 * pages are classified from their title, snippet, heading outline, top
 * queries and taxonomy — never the full content, which the outline
 * represents at roughly a fiftieth of the tokens. Twenty pages travel per
 * request, results are cached against the content hash, so a site
 * classifies once and afterwards only edited pages come back through.
 *
 * SaaS seam: this class touches no UI and reads WordPress only through
 * ECP_Inventory and ECP_Site_Profile. When the backend exists, run_batch()
 * becomes an HTTP call and nothing upstream notices.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ECP_Classifier {

    /** Pages per request. Enough to amortize the prompt, small enough to stay reliable. */
    const BATCH = 20;

    const INTENTS = array('informational', 'commercial', 'transactional', 'navigational');
    const STAGES = array('awareness', 'consideration', 'decision');

    /**
     * Classify the next batch of unclassified pages.
     *
     * @param string $trigger_source cron|manual|cli
     * @return array|WP_Error { classified: int, remaining: int }
     */
    public static function run_batch($trigger_source = 'cron') {
        $stats = ECP_Inventory::stats();

        // How many pages may we still classify today?
        $limit = ECP_Limits::limit('classify');
        $room = $limit > 0 ? max(0, $limit - ECP_Limits::used('classify')) : self::BATCH;
        $size = min(self::BATCH, $room);

        if ($size < 1) {
            return ECP_Limits::can('classify');   // The WP_Error with the human message.
        }

        $rows = ECP_Inventory::unclassified($size);

        if (!$rows) {
            return array('classified' => 0, 'remaining' => 0);
        }

        $response = ECP_AI_Client::request(
            self::system_prompt(),
            self::user_prompt($rows),
            self::schema($rows),
            array(
                'job_type'       => 'classify',
                'trigger_source' => $trigger_source,
                'max_tokens'     => 8000,
                'meter'          => 'classify',
                'model'          => (string) ECP_Agent_Settings::get('classify_model', ''),
            )
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $by_id = array();

        foreach ($rows as $row) {
            $by_id[(int) $row['post_id']] = $row;
        }

        $classified = 0;
        $entries = isset($response['data']['pages']) && is_array($response['data']['pages'])
            ? $response['data']['pages']
            : array();

        foreach ($entries as $entry) {
            if (!is_array($entry) || empty($entry['post_id'])) {
                continue;
            }

            $post_id = (int) $entry['post_id'];

            if (!isset($by_id[$post_id])) {
                continue;   // Not a page we asked about.
            }

            ECP_Inventory::store_classification(
                $post_id,
                self::normalize($entry),
                $by_id[$post_id]['content_hash']
            );

            $classified++;
        }

        ECP_Limits::spend('classify', $classified);

        $after = ECP_Inventory::stats();
        $remaining = max(0, ($after['published'] - $after['classified']));

        ECP_Log::info('classify.batch', sprintf(
            /* translators: 1: pages classified, 2: pages remaining */
            __('Classified %1$d pages by topic and intent; %2$d remaining.', 'enhanced-content-plugin'),
            $classified,
            $remaining
        ), array('run_id' => (int) $response['run_id']));

        return array('classified' => $classified, 'remaining' => $remaining);
    }

    /**
     * Enforce the enums and lengths whatever the model returned.
     *
     * @return array { topic, subtopic, intent, funnel_stage, confidence }
     */
    public static function normalize(array $entry) {
        $intent = isset($entry['intent']) ? strtolower(trim((string) $entry['intent'])) : '';
        $stage = isset($entry['funnel_stage']) ? strtolower(trim((string) $entry['funnel_stage'])) : '';

        return array(
            'topic'        => isset($entry['topic']) ? trim((string) $entry['topic']) : '',
            'subtopic'     => isset($entry['subtopic']) ? trim((string) $entry['subtopic']) : '',
            'intent'       => in_array($intent, self::INTENTS, true) ? $intent : 'informational',
            'funnel_stage' => in_array($stage, self::STAGES, true) ? $stage : 'awareness',
            'confidence'   => isset($entry['confidence']) ? max(0, min(100, (int) $entry['confidence'])) : 50,
        );
    }

    private static function system_prompt() {
        $lines = array();

        $lines[] = 'You are cataloguing a website\'s content. For each page you are given, assign:';
        $lines[] = '- topic: the subject area, as a short noun phrase (2-4 words). Pages about the same subject MUST get the identical topic label — reuse a label from the existing-topics list whenever one fits, and only coin a new label when nothing listed applies. Consistency matters more than precision: "outdoor kitchen cabinets" for twelve related pages is right; twelve bespoke variations of it are wrong.';
        $lines[] = '- subtopic: the page\'s specific angle within that topic, a short phrase. Empty if the page IS the topic\'s overview.';
        $lines[] = '- intent: what the searcher who lands here wants. informational = understand something; commercial = comparing or evaluating before a purchase decision; transactional = ready to buy, sign up or hire; navigational = looking for this specific site or brand.';
        $lines[] = '- funnel_stage: awareness (learning they have a problem or interest), consideration (weighing options), decision (choosing who to buy from or act with).';
        $lines[] = '- confidence: 0-100. Be honest; a thin title with no queries deserves a low number.';
        $lines[] = '';
        $lines[] = 'You judge from the title, description, heading outline, measured search queries and categories. The queries are real user behaviour and outrank your assumptions when they disagree with the title.';

        $context = ECP_Site_Profile::prompt_context();

        if ($context) {
            $lines[] = '';
            $lines[] = 'Business context, which should ground your topic labels in how this site thinks about its subject matter:';
            $lines[] = $context;
        }

        return implode("\n", $lines);
    }

    /**
     * @param array[] $rows Inventory rows to classify.
     */
    private static function user_prompt(array $rows) {
        $out = array();

        // Feeding the existing labels back is what keeps the topic space
        // converging instead of fragmenting into near-duplicates.
        $existing = wp_list_pluck(ECP_Inventory::topics(50), 'topic');

        if ($existing) {
            $out[] = '## Existing topic labels on this site — reuse these wherever they fit';
            $out[] = implode(' | ', $existing);
            $out[] = '';
        }

        $out[] = '## Pages to classify';

        foreach ($rows as $row) {
            $out[] = '';
            $out[] = sprintf('### post_id: %d', (int) $row['post_id']);
            $out[] = 'Title: ' . $row['title'];

            if (!empty($row['meta_description'])) {
                $out[] = 'Description: ' . $row['meta_description'];
            }

            $headings = ECP_DB::decode($row['heading_json']);

            if ($headings) {
                $out[] = 'Headings: ' . implode(' | ', array_slice(wp_list_pluck($headings, 'text'), 0, 12));
            }

            $taxonomy = ECP_DB::decode($row['taxonomy_json']);

            if (!empty($taxonomy['category'])) {
                $out[] = 'Categories: ' . implode(', ', $taxonomy['category']);
            }

            $queries = ECP_Search_Data::top_queries((int) $row['post_id'], 5);

            if ($queries) {
                $out[] = 'Top searches that reach it: ' . implode('; ', wp_list_pluck($queries, 'query'));
            }
        }

        $out[] = '';
        $out[] = 'Classify every page listed, using its post_id verbatim.';

        return implode("\n", $out);
    }

    private static function schema(array $rows) {
        $ids = array_map('intval', wp_list_pluck($rows, 'post_id'));

        return array(
            'type' => 'object',
            'properties' => array(
                'pages' => array(
                    'type' => 'array',
                    'items' => array(
                        'type' => 'object',
                        'properties' => array(
                            'post_id'      => array('type' => 'integer', 'enum' => $ids),
                            'topic'        => array('type' => 'string'),
                            'subtopic'     => array('type' => 'string'),
                            'intent'       => array('type' => 'string', 'enum' => self::INTENTS),
                            'funnel_stage' => array('type' => 'string', 'enum' => self::STAGES),
                            'confidence'   => array('type' => 'integer'),
                        ),
                        'required' => array('post_id', 'topic', 'subtopic', 'intent', 'funnel_stage', 'confidence'),
                        'additionalProperties' => false,
                    ),
                ),
            ),
            'required' => array('pages'),
            'additionalProperties' => false,
        );
    }
}
