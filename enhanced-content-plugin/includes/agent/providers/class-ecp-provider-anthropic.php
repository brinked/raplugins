<?php
/**
 * Anthropic Messages API provider.
 *
 * Talks to POST https://api.anthropic.com/v1/messages over wp_remote_post
 * rather than the official PHP SDK. That is deliberate: a WordPress plugin
 * cannot reliably ship a Composer vendor tree — two plugins bundling different
 * versions of the same package fight over the autoloader — and this plugin has
 * no autoloader of its own. The request surface used here is small and stable.
 *
 * Structured output uses `output_config.format` with a JSON Schema, so the
 * response is guaranteed to match the shape the analyzer expects instead of
 * being coaxed out of prose.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ECP_Provider_Anthropic extends ECP_Provider {

    const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    /** The API version header. Pinned; not the model version. */
    const API_VERSION = '2023-06-01';

    public function slug() {
        return 'anthropic';
    }

    public function label() {
        return __('Anthropic (Claude)', 'enhanced-content-plugin');
    }

    /**
     * Models worth offering for this workload, cheapest last.
     */
    public function models() {
        return array(
            'claude-opus-5'   => __('Claude Opus 5 — best quality ($5 / $25 per million tokens)', 'enhanced-content-plugin'),
            'claude-sonnet-5' => __('Claude Sonnet 5 — near-Opus quality, lower cost ($3 / $15)', 'enhanced-content-plugin'),
            'claude-haiku-4-5' => __('Claude Haiku 4.5 — fastest and cheapest ($1 / $5)', 'enhanced-content-plugin'),
        );
    }

    /**
     * USD per million tokens, [input, output].
     */
    public function pricing() {
        $table = array(
            'claude-opus-5'    => array(5.00, 25.00),
            'claude-sonnet-5'  => array(3.00, 15.00),
            'claude-haiku-4-5' => array(1.00, 5.00),
            'claude-opus-4-8'  => array(5.00, 25.00),
        );

        return isset($table[$this->model]) ? $table[$this->model] : null;
    }

    /**
     * Effort levels, which are the real cost/latency lever on Claude 5 models.
     */
    public static function effort_levels() {
        return array(
            'low'    => __('Low — fastest and cheapest; fine for metadata and alt text', 'enhanced-content-plugin'),
            'medium' => __('Medium — good balance for routine content analysis', 'enhanced-content-plugin'),
            'high'   => __('High — the default; best for full article analysis', 'enhanced-content-plugin'),
            'xhigh'  => __('Extra high — deepest analysis, noticeably slower', 'enhanced-content-plugin'),
            'max'    => __('Max — no ceiling; use only when quality outweighs cost', 'enhanced-content-plugin'),
        );
    }

    /**
     * @inheritDoc
     */
    public function structured(string $system, string $user, array $schema, array $options = array()) {
        if ('' === $this->api_key) {
            return new WP_Error('ecp_no_api_key', __('No Anthropic API key is configured.', 'enhanced-content-plugin'));
        }

        $options = wp_parse_args($options, array(
            'max_tokens' => 16000,
            'effort'     => 'high',
            'timeout'    => 120,
            'retries'    => 2,
        ));

        $body = array(
            'model'      => $this->model,
            // max_tokens caps thinking *and* response text together. Thinking
            // is on by default on Opus 5, so this needs headroom above the
            // size of the JSON we actually want back.
            'max_tokens' => max(1024, (int) $options['max_tokens']),
            'system'     => $system,
            'messages'   => array(
                array('role' => 'user', 'content' => $user),
            ),
            'output_config' => array(
                'effort' => $options['effort'],
                'format' => array(
                    'type'   => 'json_schema',
                    // Structured output compiles the schema into a grammar
                    // and 400s the entire request on any keyword it cannot
                    // express. Strip those before they cost us a run.
                    'schema' => self::sanitize_schema($schema),
                ),
            ),
        );

        /**
         * Filter the Anthropic request body before it is sent.
         *
         * @param array  $body
         * @param string $model
         */
        $body = apply_filters('ecp_anthropic_request_body', $body, $this->model);

        $headers = array(
            'x-api-key'         => $this->api_key,
            'anthropic-version' => self::API_VERSION,
            'content-type'      => 'application/json',
        );

        $response = $this->post_json(self::ENDPOINT, $headers, $body, array(
            'timeout' => (int) $options['timeout'],
            'retries' => (int) $options['retries'],
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $message = $response['body'];

        // Usage first, so a refusal still gets accounted for.
        $usage = isset($message['usage']) ? $message['usage'] : array();
        $this->record_usage(
            isset($usage['input_tokens']) ? $usage['input_tokens'] : 0,
            isset($usage['output_tokens']) ? $usage['output_tokens'] : 0
        );

        // A declined request comes back as HTTP 200 with stop_reason
        // "refusal" and no usable content. Check before reading content, or
        // the array index below fatals.
        if (isset($message['stop_reason']) && 'refusal' === $message['stop_reason']) {
            $category = isset($message['stop_details']['category']) ? $message['stop_details']['category'] : '';

            return new WP_Error(
                'ecp_refusal',
                $category
                    ? sprintf(
                        /* translators: %s: refusal category reported by the API */
                        __('The AI provider declined this request (%s). The content may touch a restricted topic.', 'enhanced-content-plugin'),
                        $category
                    )
                    : __('The AI provider declined this request.', 'enhanced-content-plugin')
            );
        }

        if (isset($message['stop_reason']) && 'max_tokens' === $message['stop_reason']) {
            return new WP_Error(
                'ecp_truncated',
                __('The response was cut off by the token limit. Raise Max tokens, or lower Effort so less of the budget goes to reasoning.', 'enhanced-content-plugin')
            );
        }

        // content is a list of blocks; thinking blocks can precede the text.
        $text = '';
        foreach ((array) $message['content'] as $block) {
            if (isset($block['type']) && 'text' === $block['type'] && isset($block['text'])) {
                $text .= $block['text'];
            }
        }

        if ('' === trim($text)) {
            return new WP_Error('ecp_empty_response', __('The model returned no text content.', 'enhanced-content-plugin'));
        }

        return $this->decode_model_json($text);
    }

    /**
     * Cheap round trip to confirm a key works, used by the "Test connection"
     * button. Deliberately tiny so it costs a fraction of a cent.
     *
     * @return true|WP_Error
     */
    public function test_connection() {
        if ('' === $this->api_key) {
            return new WP_Error('ecp_no_api_key', __('Enter an API key first.', 'enhanced-content-plugin'));
        }

        $result = $this->structured(
            'You are a connectivity test. Reply using the schema and nothing else.',
            'Return ok = true.',
            array(
                'type'       => 'object',
                'properties' => array('ok' => array('type' => 'boolean')),
                'required'   => array('ok'),
                'additionalProperties' => false,
            ),
            array('max_tokens' => 1024, 'effort' => 'low', 'retries' => 0, 'timeout' => 30)
        );

        if (is_wp_error($result)) {
            return $result;
        }

        return true;
    }
}
