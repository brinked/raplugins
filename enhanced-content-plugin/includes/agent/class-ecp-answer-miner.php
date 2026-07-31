<?php
/**
 * The site answers its own questions.
 *
 * The gap analysis asks the owner things the site already answers — a
 * warranty page exists, but a per-article analysis cannot see it. This
 * class closes that loop: for each open question it searches the whole
 * site (pages included, not just the agent's configured post types),
 * pulls the most relevant passages, and asks the model to extract an
 * answer ONLY where a passage genuinely contains one, quoting the
 * supporting sentence exactly.
 *
 * Honesty is enforced twice. The model must return the verbatim quote
 * and the id of the page it came from; PHP then verifies the quote
 * actually occurs in that page's text before anything is stored — an
 * invented quote dies here. And nothing mined becomes vault truth by
 * itself: extracted answers are stored PENDING, feed no prompts, and
 * wait for the owner's one-click confirmation. The agent may read the
 * site; only a human decides what it is allowed to believe.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ECP_Answer_Miner {

    /** Questions examined per mining run (one AI call). */
    const BATCH = 8;

    /** Candidate pages searched per question. */
    const PAGES_PER_QUESTION = 3;

    /** Longest passage shown to the model, per page. */
    const PASSAGE_CHARS = 1500;

    /* --------------------------------------------------------------------
     * Mining
     * ----------------------------------------------------------------- */

    /**
     * Run one mining pass over the open questions.
     *
     * @param array $args { trigger_source }
     * @return array|WP_Error { examined, found, queued }
     */
    public static function mine(array $args = array()) {
        $args = wp_parse_args($args, array('trigger_source' => 'manual'));

        $questions = self::minable_questions(self::BATCH);

        if (!$questions) {
            return array('examined' => 0, 'found' => 0, 'queued' => 0);
        }

        // Gather passages first; a question the site says nothing about
        // never reaches the model.
        $work = array();

        foreach ($questions as $question) {
            $passages = self::find_passages($question['question']);

            if ($passages) {
                $question['passages'] = $passages;
                $work[] = $question;
            }
        }

        if (!$work) {
            return array('examined' => count($questions), 'found' => 0, 'queued' => 0);
        }

        $response = ECP_AI_Client::request(
            self::system_prompt(),
            self::user_prompt($work),
            self::schema(),
            array(
                'job_type'       => 'mine',
                'trigger_source' => $args['trigger_source'],
                'max_tokens'     => 16000,
                // Extraction, not judgement — the checks are in PHP.
                'effort'         => 'medium',
            )
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $queued = self::store($response['data'], $work);

        ECP_Log::info('vault.mined', sprintf(
            /* translators: 1: questions examined, 2: answers found */
            __('Searched the site for answers: %1$d questions examined, %2$d answers found for your confirmation.', 'enhanced-content-plugin'),
            count($work),
            $queued
        ), array('run_id' => (int) $response['run_id']));

        return array('examined' => count($work), 'found' => $queued, 'queued' => $queued);
    }

    /**
     * Open questions that are not already waiting in the pending queue.
     *
     * @return array[] { post_id, post_title, question, needed }
     */
    private static function minable_questions($limit) {
        $open = ECP_Content_Gaps::open_questions(40);

        if (!$open) {
            return array();
        }

        $pending = ECP_Vault::query(array('status' => ECP_Vault::PENDING, 'limit' => 200));
        $queued = array();

        foreach ($pending['items'] as $fact) {
            $queued[(int) $fact['post_id']][] = strtolower(trim($fact['question']));
        }

        $out = array();

        foreach ($open as $question) {
            $key = strtolower(trim($question['question']));

            if (isset($queued[(int) $question['post_id']]) && in_array($key, $queued[(int) $question['post_id']], true)) {
                continue;
            }

            $out[] = $question;

            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * The site's best passages for one question: a keyword search across
     * every public post type — the warranty page and the FAQ live
     * outside the agent's configured scope, and that is exactly where
     * these answers hide.
     *
     * @return array[] { post_id, title, text }
     */
    private static function find_passages($question) {
        $types = array_diff(get_post_types(array('public' => true)), array('attachment'));

        $found = get_posts(array(
            's'                => $question,
            'post_type'        => array_values($types),
            'post_status'      => 'publish',
            'posts_per_page'   => self::PAGES_PER_QUESTION,
            'suppress_filters' => false,
        ));

        $passages = array();

        foreach ($found as $post) {
            $text = self::best_passage($post, $question);

            if ('' !== $text) {
                $passages[] = array(
                    'post_id' => (int) $post->ID,
                    'title'   => $post->post_title,
                    'text'    => $text,
                );
            }
        }

        return $passages;
    }

    /**
     * The sections of one post that overlap the question most, joined
     * and capped. Falls back to the opening of the page for short,
     * unstructured pages — a warranty page is often one block of text.
     */
    private static function best_passage($post, $question) {
        $sections = ECP_Content_Map::sections($post);
        $scored = array();

        foreach ($sections as $section) {
            $protected = ECP_Content_Map::protect($section['html']);
            $text = trim($protected['text']);

            if ('' === $text) {
                continue;
            }

            $scored[] = array(
                'score' => ECP_Topical_Map::overlap($question, $section['heading'] . ' ' . $text),
                'text'  => $text,
            );
        }

        if (!$scored) {
            $fallback = trim(wp_strip_all_tags((string) $post->post_content));

            return mb_substr($fallback, 0, self::PASSAGE_CHARS);
        }

        usort($scored, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        $out = '';

        foreach (array_slice($scored, 0, 2) as $section) {
            $out .= ('' === $out ? '' : "\n\n") . $section['text'];
        }

        return mb_substr($out, 0, self::PASSAGE_CHARS);
    }

    /* --------------------------------------------------------------------
     * Prompt
     * ----------------------------------------------------------------- */

    private static function system_prompt() {
        $lines = array();

        $lines[] = 'You extract answers to specific questions from a website\'s own pages.';
        $lines[] = '';
        $lines[] = 'The rules:';
        $lines[] = '- Mark a question answered ONLY when a provided passage genuinely contains the answer. Partial, implied or adjacent information is not an answer — mark it unanswered.';
        $lines[] = '- The answer must restate only what the passage says. Never combine passages into a conclusion the site never drew, never generalise, never fill a gap with plausible knowledge.';
        $lines[] = '- quote is the exact sentence or sentences from the passage that contain the answer, copied verbatim. It will be checked mechanically against the page; a reworded quote is discarded.';
        $lines[] = '- source_post_id is the id shown with the passage you quoted.';
        $lines[] = '- An honest "not answered" is a good result. These answers become facts a business relies on; a wrong extraction is worse than none.';

        return implode("\n", $lines);
    }

    private static function user_prompt(array $work) {
        $out = array();

        foreach ($work as $index => $item) {
            $out[] = sprintf('## Question %d: %s', $index + 1, $item['question']);

            if (!empty($item['needed'])) {
                $out[] = 'What is needed: ' . $item['needed'];
            }

            foreach ($item['passages'] as $passage) {
                $out[] = '';
                $out[] = sprintf('### From "%s" (post id %d)', $passage['title'], $passage['post_id']);
                $out[] = $passage['text'];
            }

            $out[] = '';
        }

        $out[] = '## What to return';
        $out[] = 'One entry per question, in order, answered or honestly not.';

        return implode("\n", $out);
    }

    private static function schema() {
        return array(
            'type' => 'object',
            'properties' => array(
                'answers' => array(
                    'type' => 'array',
                    'items' => array(
                        'type' => 'object',
                        'properties' => array(
                            'question'       => array('type' => 'string', 'description' => 'The question, exactly as given.'),
                            'answered'       => array('type' => 'boolean'),
                            'answer'         => array('type' => 'string', 'description' => 'The answer in one or two plain sentences. Empty when not answered.'),
                            'quote'          => array('type' => 'string', 'description' => 'Verbatim supporting sentence(s) from the passage. Empty when not answered.'),
                            'source_post_id' => array('type' => 'integer', 'description' => 'The post id of the quoted passage. 0 when not answered.'),
                        ),
                        'required' => array('question', 'answered', 'answer', 'quote', 'source_post_id'),
                        'additionalProperties' => false,
                    ),
                ),
            ),
            'required' => array('answers'),
            'additionalProperties' => false,
        );
    }

    /* --------------------------------------------------------------------
     * Verification and storage
     * ----------------------------------------------------------------- */

    /**
     * Store the extractions that survive the quote check as PENDING
     * vault facts, scoped to the article whose analysis asked.
     *
     * @return int Facts queued.
     */
    private static function store(array $data, array $work) {
        $answers = isset($data['answers']) && is_array($data['answers']) ? $data['answers'] : array();
        $queued = 0;

        foreach ($answers as $answer) {
            if (empty($answer['answered']) || empty($answer['answer']) || empty($answer['quote'])) {
                continue;
            }

            $source_id = (int) $answer['source_post_id'];
            $question = self::match_question($answer['question'], $work);

            if (!$question || !$source_id) {
                continue;
            }

            // The quote must occur in the passage we actually showed for
            // that page. An extraction that fails this was invented.
            if (!self::quote_occurs($answer['quote'], $source_id, $question['passages'])) {
                continue;
            }

            $id = ECP_Vault::add(array(
                'fact'     => $answer['answer'],
                'question' => $question['question'],
                'post_id'  => (int) $question['post_id'],
                'source'   => 'site_content',
                'status'   => ECP_Vault::PENDING,
                'evidence' => array(
                    'source_post_id' => $source_id,
                    'quote'          => sanitize_textarea_field($answer['quote']),
                ),
            ));

            if (!is_wp_error($id)) {
                $queued++;
            }
        }

        return $queued;
    }

    private static function match_question($text, array $work) {
        $needle = strtolower(trim($text));

        foreach ($work as $item) {
            if (strtolower(trim($item['question'])) === $needle) {
                return $item;
            }
        }

        return null;
    }

    /**
     * Whether the claimed quote genuinely appears in the passage shown
     * for the claimed page. Compared on collapsed whitespace; the first
     * 80 characters are enough to prove provenance.
     */
    private static function quote_occurs($quote, $source_id, array $passages) {
        $normalise = function ($text) {
            return strtolower(preg_replace('/\s+/', ' ', trim((string) $text)));
        };

        $needle = mb_substr($normalise($quote), 0, 80);

        if ('' === $needle) {
            return false;
        }

        foreach ($passages as $passage) {
            if ((int) $passage['post_id'] === (int) $source_id
                && false !== strpos($normalise($passage['text']), $needle)
            ) {
                return true;
            }
        }

        return false;
    }
}
