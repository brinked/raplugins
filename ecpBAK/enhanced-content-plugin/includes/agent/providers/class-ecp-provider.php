<?php
/**
 * Base class for AI providers.
 *
 * The agent never talks to a model directly. It hands a prompt plus a JSON
 * Schema to a provider and gets back a validated array. That indirection is
 * what will let RankAudit take over the analysis later without any change to
 * the analyzer, the guardrails or the approval workflow.
 *
 * Providers are responsible for: building the request, retrying transient
 * failures, reporting token usage, and returning either a decoded array or a
 * WP_Error. They must never throw.
 */

if (!defined('ABSPATH')) {
    exit;
}

abstract class ECP_Provider {

    /** @var string */
    protected $api_key;

    /** @var string */
    protected $model;

    /** @var array Usage from the last successful call. */
    protected $last_usage = array(
        'input_tokens'  => 0,
        'output_tokens' => 0,
        'cost_micros'   => 0,
    );

    public function __construct($api_key, $model) {
        $this->api_key = (string) $api_key;
        $this->model = (string) $model;
    }

    /**
     * Machine name, matching the 'provider' setting.
     */
    abstract public function slug();

    /**
     * Human-readable name for the settings screen.
     */
    abstract public function label();

    /**
     * Model IDs this provider offers, as id => label.
     *
     * @return array<string,string>
     */
    abstract public function models();

    /**
     * Ask the model for a structured object.
     *
     * @param string $system  System prompt.
     * @param string $user    User message.
     * @param array  $schema  JSON Schema the response must satisfy.
     * @param array  $options { max_tokens, effort, timeout, retries }
     * @return array|WP_Error Decoded object, or WP_Error on failure.
     */
    abstract public function structured(string $system, string $user, array $schema, array $options = array());

    /**
     * Usage from the last successful call.
     *
     * @return array { input_tokens, output_tokens, cost_micros }
     */
    public function last_usage() {
        return $this->last_usage;
    }

    /**
     * Per-million-token pricing in USD, as [input, output].
     *
     * Used only to display an estimated spend and to enforce the budget cap.
     * Providers that can't price a model should return null so the UI says
     * "unknown" rather than showing a fabricated number.
     *
     * @return array{0:float,1:float}|null
     */
    public function pricing() {
        return null;
    }

    /* --------------------------------------------------------------------
     * Shared helpers
     * ----------------------------------------------------------------- */

    /**
     * Record usage and compute cost in micro-dollars (millionths of a dollar,
     * so an integer column can hold fractions of a cent exactly).
     */
    protected function record_usage($input_tokens, $output_tokens) {
        $pricing = $this->pricing();

        $cost_micros = 0;
        if ($pricing) {
            $cost_micros = (int) round(
                ($input_tokens / 1000000) * $pricing[0] * 1000000
                + ($output_tokens / 1000000) * $pricing[1] * 1000000
            );
        }

        $this->last_usage = array(
            'input_tokens'  => (int) $input_tokens,
            'output_tokens' => (int) $output_tokens,
            'cost_micros'   => $cost_micros,
        );
    }

    /**
     * POST JSON with retries on transient failures.
     *
     * Retries 429, 5xx and connection errors with exponential backoff, and
     * honours a Retry-After header when the API sends one. Everything else
     * comes back immediately — a 400 will not fix itself.
     *
     * @return array|WP_Error { status: int, body: array, headers: array }
     */
    protected function post_json($url, array $headers, array $body, array $options = array()) {
        $timeout = isset($options['timeout']) ? (int) $options['timeout'] : 120;
        $retries = isset($options['retries']) ? (int) $options['retries'] : 2;

        $payload = wp_json_encode($body);
        if (false === $payload) {
            return new WP_Error('ecp_json_encode', __('Could not encode the request payload.', 'enhanced-content-plugin'));
        }

        $attempt = 0;
        $last_error = null;

        while ($attempt <= $retries) {
            if ($attempt > 0) {
                // 2s, 4s, 8s... Cron has time; a user-facing request has the
                // retry count clamped lower by the caller.
                sleep(min(30, (int) pow(2, $attempt)));
            }
            $attempt++;

            $response = wp_remote_post($url, array(
                'headers'     => $headers,
                'body'        => $payload,
                'timeout'     => $timeout,
                'redirection' => 0,
                'sslverify'   => true,
            ));

            if (is_wp_error($response)) {
                $last_error = $response;
                continue;   // Connection-level failure: worth retrying.
            }

            $status = (int) wp_remote_retrieve_response_code($response);
            $raw = wp_remote_retrieve_body($response);
            $decoded = json_decode($raw, true);

            if ($status >= 200 && $status < 300) {
                if (!is_array($decoded)) {
                    $last_error = new WP_Error(
                        'ecp_bad_response',
                        __('The AI provider returned a response that was not valid JSON.', 'enhanced-content-plugin')
                    );
                    continue;
                }

                return array(
                    'status'  => $status,
                    'body'    => $decoded,
                    'headers' => wp_remote_retrieve_headers($response),
                );
            }

            $message = $this->error_message($decoded, $status, $raw);

            if (429 === $status || $status >= 500) {
                $last_error = new WP_Error('ecp_provider_retryable', $message, array('status' => $status));

                // Respect Retry-After when present rather than our own backoff.
                $retry_after = (int) wp_remote_retrieve_header($response, 'retry-after');
                if ($retry_after > 0 && $attempt <= $retries) {
                    sleep(min(60, $retry_after));
                }
                continue;
            }

            // 4xx other than 429: a bad request, bad key, or unknown model.
            // Retrying sends the identical payload and gets the identical
            // answer, so stop here.
            return new WP_Error('ecp_provider_error', $message, array('status' => $status));
        }

        return $last_error ? $last_error : new WP_Error(
            'ecp_provider_unreachable',
            __('Could not reach the AI provider.', 'enhanced-content-plugin')
        );
    }

    /**
     * Pull a useful message out of an error body.
     */
    protected function error_message($decoded, $status, $raw) {
        if (is_array($decoded)) {
            if (isset($decoded['error']['message'])) {
                return sprintf('[%d] %s', $status, $decoded['error']['message']);
            }
            if (isset($decoded['message'])) {
                return sprintf('[%d] %s', $status, $decoded['message']);
            }
        }

        return sprintf('[%d] %s', $status, mb_substr(wp_strip_all_tags((string) $raw), 0, 300));
    }

    /**
     * Strip JSON Schema keywords that constrained-decoding backends reject.
     *
     * Structured output is not full JSON Schema. Both Anthropic and OpenAI
     * compile the schema into a grammar, and anything they cannot express in
     * one is rejected with a 400 for the *whole request* — so a single stray
     * `maxItems` takes down every analysis on the site, not just one field.
     *
     * The official SDKs strip these client-side and validate them afterwards
     * instead. We talk to the API over raw HTTP, so we have to do the same
     * thing here. Constraints removed here must be enforced in PHP after the
     * response is parsed, or stated in the prompt — usually both.
     *
     * @param array $schema
     * @return array
     */
    public static function sanitize_schema(array $schema) {
        static $unsupported = array(
            // Numeric constraints.
            'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'multipleOf',
            // String constraints.
            'minLength', 'maxLength', 'pattern',
            // Array constraints.
            'minItems', 'maxItems', 'uniqueItems', 'contains',
            // Object constraints.
            'minProperties', 'maxProperties', 'patternProperties', 'dependencies',
        );

        $clean = array();

        foreach ($schema as $key => $value) {
            if (in_array($key, $unsupported, true)) {
                continue;
            }

            // `additionalProperties` must be exactly false on every object;
            // any other value is rejected.
            if ('additionalProperties' === $key) {
                $clean[$key] = false;
                continue;
            }

            if (is_array($value)) {
                // Recurse into nested schemas — properties, items, anyOf,
                // allOf, $defs. A list of sub-schemas and a map of them are
                // both just arrays here, so walking everything is correct and
                // cheaper than special-casing each keyword.
                $clean[$key] = self::sanitize_schema($value);
                continue;
            }

            $clean[$key] = $value;
        }

        // An object without additionalProperties:false is rejected too, so
        // add it rather than waiting for the API to complain.
        if (isset($clean['type']) && 'object' === $clean['type'] && !array_key_exists('additionalProperties', $clean)) {
            $clean['additionalProperties'] = false;
        }

        return $clean;
    }

    /**
     * Decode a JSON string the model produced, tolerating the common failure
     * modes: markdown fences, and leading/trailing prose.
     *
     * @return array|WP_Error
     */
    protected function decode_model_json($text) {
        $text = trim((string) $text);

        if ('' === $text) {
            return new WP_Error('ecp_empty_response', __('The model returned an empty response.', 'enhanced-content-plugin'));
        }

        // Strip ```json ... ``` fences.
        if (preg_match('/```(?:json)?\s*(.+?)\s*```/is', $text, $match)) {
            $text = $match[1];
        }

        $decoded = json_decode($text, true);

        if (!is_array($decoded)) {
            // Last resort: take the outermost {...}.
            $start = strpos($text, '{');
            $end = strrpos($text, '}');
            if (false !== $start && false !== $end && $end > $start) {
                $decoded = json_decode(substr($text, $start, $end - $start + 1), true);
            }
        }

        if (!is_array($decoded)) {
            return new WP_Error(
                'ecp_unparseable_response',
                __('The model returned output that could not be parsed as JSON.', 'enhanced-content-plugin'),
                array('excerpt' => mb_substr($text, 0, 500))
            );
        }

        return $decoded;
    }
}
