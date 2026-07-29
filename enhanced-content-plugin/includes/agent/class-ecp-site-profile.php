<?php
/**
 * What this website is, in the owner's own words.
 *
 * Everything strategic downstream — classification grounding, topical maps,
 * information-gain judgements, "should we even cover this topic" — needs to
 * know what the business does, who it serves, and what is out of bounds.
 * Guessing those from the content is how an agent ends up recommending
 * articles about a competitor's product line.
 *
 * Stored as one option: standalone is single-site, and in the SaaS this
 * whole record lives server-side. Content-only field set — the products
 * plugin keeps its own profile.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ECP_Site_Profile {

    const OPTION = 'ecp_site_profile';

    /**
     * Field registry: key => { label, type, help }.
     *
     * `list` fields are stored as arrays, entered one per line. This
     * registry drives the settings tab, sanitization and completeness —
     * one source, no drift.
     *
     * @return array<string,array>
     */
    public static function fields() {
        return array(
            'business_name' => array(
                'label' => __('Business name', 'enhanced-content-plugin'),
                'type'  => 'text',
                'help'  => '',
            ),
            'purpose' => array(
                'label' => __('What this website is for', 'enhanced-content-plugin'),
                'type'  => 'textarea',
                'help'  => __('One or two sentences. "We sell HDPE outdoor cabinets to homeowners building outdoor kitchens" beats a mission statement.', 'enhanced-content-plugin'),
            ),
            'offerings' => array(
                'label' => __('Products or services', 'enhanced-content-plugin'),
                'type'  => 'textarea',
                'help'  => __('What you actually sell or provide. The agent uses this to judge which topics are worth your time.', 'enhanced-content-plugin'),
            ),
            'audience' => array(
                'label' => __('Who you serve', 'enhanced-content-plugin'),
                'type'  => 'textarea',
                'help'  => __('Who reads this site and what situation they are in.', 'enhanced-content-plugin'),
            ),
            'geo_markets' => array(
                'label' => __('Geographic markets', 'enhanced-content-plugin'),
                'type'  => 'text',
                'help'  => __('e.g. "United States", "Tampa Bay area", "worldwide".', 'enhanced-content-plugin'),
            ),
            'conversions' => array(
                'label' => __('What a visitor should do', 'enhanced-content-plugin'),
                'type'  => 'text',
                'help'  => __('The conversions that matter: request a quote, buy, subscribe, call.', 'enhanced-content-plugin'),
            ),
            'seed_topics' => array(
                'label' => __('Core topics', 'enhanced-content-plugin'),
                'type'  => 'list',
                'help'  => __('One per line. The subjects this site should be the authority on.', 'enhanced-content-plugin'),
            ),
            'excluded_topics' => array(
                'label' => __('Topics to never cover', 'enhanced-content-plugin'),
                'type'  => 'list',
                'help'  => __('One per line. The agent will not recommend content in these areas.', 'enhanced-content-plugin'),
            ),
            'competitors' => array(
                'label' => __('Competitors', 'enhanced-content-plugin'),
                'type'  => 'list',
                'help'  => __('Names or domains, one per line. Not used yet; recorded for the competitor features on the roadmap.', 'enhanced-content-plugin'),
            ),
            'compliance_notes' => array(
                'label' => __('Legal or compliance restrictions', 'enhanced-content-plugin'),
                'type'  => 'textarea',
                'help'  => __('Anything the content must never claim or imply — regulated industries, certification rules, jurisdictions.', 'enhanced-content-plugin'),
            ),
            'publishing_capacity' => array(
                'label' => __('Publishing capacity (posts per month)', 'enhanced-content-plugin'),
                'type'  => 'number',
                'help'  => __('How much new content your team can realistically review and publish. Future campaign pacing respects this.', 'enhanced-content-plugin'),
            ),
        );
    }

    /**
     * The whole profile, defaults filled.
     *
     * @return array
     */
    public static function all() {
        $stored = get_option(self::OPTION, array());
        $stored = is_array($stored) ? $stored : array();
        $out = array();

        foreach (self::fields() as $key => $field) {
            if ('list' === $field['type']) {
                $out[$key] = isset($stored[$key]) && is_array($stored[$key]) ? $stored[$key] : array();
            } elseif ('number' === $field['type']) {
                $out[$key] = isset($stored[$key]) ? (int) $stored[$key] : 0;
            } else {
                $out[$key] = isset($stored[$key]) ? (string) $stored[$key] : '';
            }
        }

        return $out;
    }

    public static function get($key) {
        $all = self::all();

        return isset($all[$key]) ? $all[$key] : '';
    }

    /**
     * Sanitize and store submitted fields. Unknown keys are dropped.
     *
     * @param array $input Raw field values keyed by profile key.
     */
    public static function update(array $input) {
        $current = self::all();

        foreach (self::fields() as $key => $field) {
            if (!array_key_exists($key, $input)) {
                continue;
            }

            $value = $input[$key];

            switch ($field['type']) {
                case 'list':
                    $lines = is_array($value) ? $value : preg_split('/\r\n|\r|\n/', (string) $value);
                    $current[$key] = array_values(array_filter(array_map('sanitize_text_field', (array) $lines)));
                    break;

                case 'number':
                    $current[$key] = max(0, min(1000, (int) $value));
                    break;

                case 'textarea':
                    $current[$key] = sanitize_textarea_field((string) $value);
                    break;

                default:
                    $current[$key] = sanitize_text_field((string) $value);
            }
        }

        update_option(self::OPTION, $current, false);

        return $current;
    }

    /**
     * How filled-in the profile is, 0–100. Competitors and compliance are
     * genuinely optional and excluded — an empty optional field is not an
     * incomplete profile.
     */
    public static function completeness() {
        $profile = self::all();
        $core = array('business_name', 'purpose', 'offerings', 'audience', 'conversions', 'seed_topics');
        $done = 0;

        foreach ($core as $key) {
            if (!empty($profile[$key])) {
                $done++;
            }
        }

        return (int) round(($done / count($core)) * 100);
    }

    /**
     * The profile as prompt context, for any AI stage that needs to know
     * the business. Empty fields are omitted rather than sent as blanks.
     *
     * @return string
     */
    public static function prompt_context() {
        $profile = self::all();
        $lines = array();

        if ($profile['business_name'] || $profile['purpose']) {
            $lines[] = 'About this website: ' . trim($profile['business_name'] . '. ' . $profile['purpose']);
        }

        if ($profile['offerings']) {
            $lines[] = 'What the business offers: ' . $profile['offerings'];
        }

        if ($profile['audience']) {
            $lines[] = 'Audience: ' . $profile['audience'];
        }

        if ($profile['geo_markets']) {
            $lines[] = 'Markets: ' . $profile['geo_markets'];
        }

        if ($profile['seed_topics']) {
            $lines[] = 'Core topics this site wants authority on: ' . implode('; ', $profile['seed_topics']) . '.';
        }

        if ($profile['excluded_topics']) {
            $lines[] = 'Topics this site deliberately does not cover: ' . implode('; ', $profile['excluded_topics']) . '.';
        }

        if ($profile['compliance_notes']) {
            $lines[] = 'Compliance restrictions: ' . $profile['compliance_notes'];
        }

        return implode("\n", $lines);
    }
}
