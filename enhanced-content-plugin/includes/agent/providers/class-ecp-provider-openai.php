<?php
/**
 * OpenAI provider.
 *
 * Offered as an alternative for sites that already hold an OpenAI key. The
 * agent's prompts and schemas are provider-neutral, so the only thing that
 * changes is the transport.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ECP_Provider_OpenAI extends ECP_Provider {

    const ENDPOINT = 'https://api.openai.com/v1/chat/completions';

    public function slug() {
        return 'openai';
    }

    public function label() {
        return __('OpenAI', 'enhanced-content-plugin');
    }

    public function models() {
        return array(
            'gpt-4o'      => __('GPT-4o', 'enhanced-content-plugin'),
            'gpt-4o-mini' => __('GPT-4o mini — cheaper', 'enhanced-content-plugin'),
            'gpt-4.1'     => __('GPT-4.1', 'enhanced-content-plugin'),
        );
    }

    /**
     * Not priced here. Rather than hard-code figures that drift, the budget
     * meter reports "unknown" for this provider and the cap falls back to the
     * per-day analysis limit.
     */
    public function pricing() {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function structured(string $system, string $user, array $schema, array $options = array()) {
        if ('' === $this->api_key) {
            return new WP_Error('ecp_no_api_key', __('No OpenAI API key is configured.', 'enhanced-content-plugin'));
        }

        $options = wp_parse_args($options, array(
            'max_tokens' => 8000,
            'timeout'    => 120,
            'retries'    => 2,
        ));

        $body = array(
            'model'    => $this->model,
            'messages' => array(
                array('role' => 'system', 'content' => $system),
                array('role' => 'user', 'content' => $user),
            ),
            'max_tokens' => max(512, (int) $options['max_tokens']),
            'response_format' => array(
                'type' => 'json_schema',
                'json_schema' => array(
                    'name'   => 'ecp_analysis',
                    'strict' => true,
                    // Strict mode has the same grammar restrictions as
                    // Anthropic's, and the same all-or-nothing 400.
                    'schema' => self::sanitize_schema($schema),
                ),
            ),
        );

        $headers = array(
            'Authorization' => 'Bearer ' . $this->api_key,
            'content-type'  => 'application/json',
        );

        $response = $this->post_json(self::ENDPOINT, $headers, $body, array(
            'timeout' => (int) $options['timeout'],
            'retries' => (int) $options['retries'],
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $payload = $response['body'];

        $usage = isset($payload['usage']) ? $payload['usage'] : array();
        $this->record_usage(
            isset($usage['prompt_tokens']) ? $usage['prompt_tokens'] : 0,
            isset($usage['completion_tokens']) ? $usage['completion_tokens'] : 0
        );

        $finish = isset($payload['choices'][0]['finish_reason']) ? $payload['choices'][0]['finish_reason'] : '';
        if ('length' === $finish) {
            return new WP_Error('ecp_truncated', __('The response was cut off by the token limit. Raise Max tokens.', 'enhanced-content-plugin'));
        }

        $text = isset($payload['choices'][0]['message']['content']) ? $payload['choices'][0]['message']['content'] : '';

        if ('' === trim((string) $text)) {
            return new WP_Error('ecp_empty_response', __('The model returned no content.', 'enhanced-content-plugin'));
        }

        return $this->decode_model_json($text);
    }

    /**
     * @return true|WP_Error
     */
    public function test_connection() {
        $result = $this->structured(
            'You are a connectivity test.',
            'Return ok = true.',
            array(
                'type'       => 'object',
                'properties' => array('ok' => array('type' => 'boolean')),
                'required'   => array('ok'),
                'additionalProperties' => false,
            ),
            array('max_tokens' => 256, 'retries' => 0, 'timeout' => 30)
        );

        return is_wp_error($result) ? $result : true;
    }
}
