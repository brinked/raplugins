<?php
/**
 * RankAudit proxy provider.
 *
 * This is the seam for the eventual integration. Instead of the site holding
 * an AI key and paying a model vendor directly, it posts the same prompt and
 * schema to a RankAudit endpoint, which does the analysis and bills the
 * subscription.
 *
 * Nothing else in the agent changes when the switch happens: the analyzer, the
 * guardrails, the approval queue and the applier all sit above this interface.
 *
 * The wire format below is the proposal, not a contract with a running
 * service — RankAudit does not implement this endpoint yet. Requests are
 * signed with the site token exactly as section 5 of the plan describes
 * (site id, timestamp, nonce, body hash, HMAC), so the server side can be
 * built against a fixed shape.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ECP_Provider_RankAudit extends ECP_Provider {

    /** @var string */
    private $endpoint;

    /** @var string */
    private $site_token;

    public function __construct($api_key, $model) {
        parent::__construct($api_key, $model);

        $this->endpoint = (string) ECP_Agent_Settings::get('rankaudit_endpoint', '');
        $this->site_token = (string) ECP_Agent_Settings::get('rankaudit_site_token', '');
    }

    public function slug() {
        return 'rankaudit';
    }

    public function label() {
        return __('RankAudit (managed)', 'enhanced-content-plugin');
    }

    public function models() {
        return array(
            'managed' => __('Managed by your RankAudit plan', 'enhanced-content-plugin'),
        );
    }

    /**
     * Billed by subscription, not per token.
     */
    public function pricing() {
        return array(0.0, 0.0);
    }

    /**
     * @inheritDoc
     */
    public function structured(string $system, string $user, array $schema, array $options = array()) {
        if ('' === $this->endpoint || '' === $this->site_token) {
            return new WP_Error(
                'ecp_rankaudit_unconfigured',
                __('The RankAudit connection is not configured. Add the endpoint and site token, or switch to a direct AI provider.', 'enhanced-content-plugin')
            );
        }

        $options = wp_parse_args($options, array(
            'max_tokens' => 16000,
            'timeout'    => 180,
            'retries'    => 2,
        ));

        $body = array(
            'site_url'   => home_url(),
            'site_id'    => self::site_id(),
            'plugin_ver' => ECP_VERSION,
            'task'       => isset($options['task']) ? $options['task'] : 'content_analysis',
            'system'     => $system,
            'user'       => $user,
            'schema'     => $schema,
            'max_tokens' => (int) $options['max_tokens'],
        );

        $response = $this->post_json(
            trailingslashit($this->endpoint) . 'v1/analyze',
            $this->signed_headers($body),
            $body,
            array('timeout' => (int) $options['timeout'], 'retries' => (int) $options['retries'])
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $payload = $response['body'];

        $usage = isset($payload['usage']) ? $payload['usage'] : array();
        $this->record_usage(
            isset($usage['input_tokens']) ? $usage['input_tokens'] : 0,
            isset($usage['output_tokens']) ? $usage['output_tokens'] : 0
        );

        if (!empty($payload['error'])) {
            return new WP_Error('ecp_rankaudit_error', (string) $payload['error']);
        }

        if (!isset($payload['result']) || !is_array($payload['result'])) {
            return new WP_Error('ecp_rankaudit_bad_shape', __('RankAudit returned an unexpected response shape.', 'enhanced-content-plugin'));
        }

        return $payload['result'];
    }

    /**
     * Sign the request: site id, timestamp, nonce, body hash, HMAC-SHA256.
     *
     * The timestamp and nonce let the server reject replays; the body hash
     * means a signature can't be lifted onto a different payload.
     */
    private function signed_headers(array $body) {
        $timestamp = (string) time();
        $nonce = wp_generate_password(24, false, false);
        $body_hash = hash('sha256', (string) wp_json_encode($body));

        $canonical = implode("\n", array(self::site_id(), $timestamp, $nonce, $body_hash));
        $signature = hash_hmac('sha256', $canonical, $this->site_token);

        return array(
            'content-type'      => 'application/json',
            'x-ecp-site'        => self::site_id(),
            'x-ecp-timestamp'   => $timestamp,
            'x-ecp-nonce'       => $nonce,
            'x-ecp-body-sha256' => $body_hash,
            'x-ecp-signature'   => $signature,
        );
    }

    /**
     * A stable identifier for this site, generated once.
     */
    public static function site_id() {
        $id = get_option('ecp_site_id', '');

        if (!$id) {
            $id = wp_generate_uuid4();
            add_option('ecp_site_id', $id, '', false);
        }

        return $id;
    }

    /**
     * @return true|WP_Error
     */
    public function test_connection() {
        if ('' === $this->endpoint || '' === $this->site_token) {
            return new WP_Error('ecp_rankaudit_unconfigured', __('Enter the endpoint and site token first.', 'enhanced-content-plugin'));
        }

        $response = $this->post_json(
            trailingslashit($this->endpoint) . 'v1/ping',
            $this->signed_headers(array('ping' => true)),
            array('ping' => true),
            array('timeout' => 20, 'retries' => 0)
        );

        return is_wp_error($response) ? $response : true;
    }
}
