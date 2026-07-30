<?php
/**
 * Who can see, approve, and configure the agent.
 *
 * Deliberately built on *virtual* capabilities granted through the
 * `user_has_cap` filter rather than by writing capabilities into the roles
 * table. Mutating roles is a one-way door on a live site — if the plugin is
 * removed, or a role is edited by another plugin in between, you are left
 * with orphaned caps and no clean way to work out which were yours. This
 * approach is stateless: change the setting, and the permission changes.
 *
 * Five access levels, lowest to highest:
 *
 *   none        Cannot see the agent at all.
 *   view        Can see the queue and the reasoning, cannot approve anything.
 *               Useful for a client who wants visibility without the button.
 *   review_own  Can approve changes to posts they could edit anyway.
 *   review_all  Can approve any change on the site.
 *   manage      The above, plus settings, autopilot and the API key.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ECP_Capabilities {

    /* Virtual capabilities. Nothing writes these to the database. */
    const VIEW   = 'ecp_view_agent';
    const REVIEW = 'ecp_review_changes';
    const MANAGE = 'ecp_manage_agent';

    const LEVEL_NONE       = 'none';
    const LEVEL_VIEW       = 'view';
    const LEVEL_REVIEW_OWN = 'review_own';
    const LEVEL_REVIEW_ALL = 'review_all';
    const LEVEL_MANAGE     = 'manage';

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        add_filter('user_has_cap', array($this, 'grant'), 10, 4);
    }

    /* --------------------------------------------------------------------
     * Levels
     * ----------------------------------------------------------------- */

    /**
     * @return array<string,string> level => label
     */
    public static function levels() {
        return array(
            self::LEVEL_NONE       => __('No access', 'enhanced-content-plugin'),
            self::LEVEL_VIEW       => __('Can look, cannot approve', 'enhanced-content-plugin'),
            self::LEVEL_REVIEW_OWN => __('Can approve changes to their own posts', 'enhanced-content-plugin'),
            self::LEVEL_REVIEW_ALL => __('Can approve any change', 'enhanced-content-plugin'),
            self::LEVEL_MANAGE     => __('Full control, including settings and the API key', 'enhanced-content-plugin'),
        );
    }

    public static function level_label($level) {
        $levels = self::levels();

        return isset($levels[$level]) ? $levels[$level] : $level;
    }

    /**
     * Ranking, so a user with several roles gets the most permissive.
     */
    private static function rank($level) {
        $order = array(
            self::LEVEL_NONE       => 0,
            self::LEVEL_VIEW       => 1,
            self::LEVEL_REVIEW_OWN => 2,
            self::LEVEL_REVIEW_ALL => 3,
            self::LEVEL_MANAGE     => 4,
        );

        return isset($order[$level]) ? $order[$level] : 0;
    }

    /**
     * Sensible starting point: editors review everything, authors review
     * their own work, everyone else is out.
     *
     * @return array<string,string>
     */
    public static function default_map() {
        return array(
            'administrator' => self::LEVEL_MANAGE,
            'editor'        => self::LEVEL_REVIEW_ALL,
            'author'        => self::LEVEL_REVIEW_OWN,
            'contributor'   => self::LEVEL_NONE,
            'subscriber'    => self::LEVEL_NONE,
        );
    }

    /**
     * The configured role → level map, with defaults filled in for any role
     * the site has that the setting doesn't mention.
     *
     * @return array<string,string>
     */
    public static function role_map() {
        $stored = ECP_Agent_Settings::get('role_access', array());
        $stored = is_array($stored) ? $stored : array();

        $defaults = self::default_map();
        $map = array();

        foreach (self::all_roles() as $slug => $name) {
            if (isset($stored[$slug]) && array_key_exists($stored[$slug], self::levels())) {
                $map[$slug] = $stored[$slug];
            } elseif (isset($defaults[$slug])) {
                $map[$slug] = $defaults[$slug];
            } else {
                // A custom role nobody has configured gets nothing.
                $map[$slug] = self::LEVEL_NONE;
            }
        }

        return $map;
    }

    /**
     * @return array<string,string> slug => display name
     */
    public static function all_roles() {
        $roles = wp_roles();

        return $roles ? $roles->get_names() : array();
    }

    /**
     * The effective level for a user.
     *
     * @param int|WP_User|null $user
     * @return string
     */
    public static function level_for($user = null) {
        $user = $user instanceof WP_User ? $user : ($user ? get_userdata((int) $user) : wp_get_current_user());

        if (!$user || !$user->exists()) {
            return self::LEVEL_NONE;
        }

        // Safety hatch: whoever can install plugins can always reach the
        // settings, or a misconfiguration would lock everyone out of the
        // screen that fixes it.
        if (user_can($user, 'manage_options')) {
            return self::LEVEL_MANAGE;
        }

        $map = self::role_map();
        $level = self::LEVEL_NONE;

        foreach ((array) $user->roles as $role) {
            $candidate = isset($map[$role]) ? $map[$role] : self::LEVEL_NONE;

            if (self::rank($candidate) > self::rank($level)) {
                $level = $candidate;
            }
        }

        /**
         * Filter a user's agent access level.
         *
         * @param string  $level
         * @param WP_User $user
         */
        return apply_filters('ecp_user_access_level', $level, $user);
    }

    /* --------------------------------------------------------------------
     * Granting
     * ----------------------------------------------------------------- */

    /**
     * Translate the level into the three virtual caps.
     *
     * Runs on every capability check, so it stays cheap: one cached level
     * lookup per user per request.
     *
     * @param array   $allcaps
     * @param array   $caps
     * @param array   $args    [ cap, user_id, ...object_id ]
     * @param WP_User $user
     * @return array
     */
    public function grant($allcaps, $caps, $args, $user) {
        $requested = isset($args[0]) ? $args[0] : '';

        if (!in_array($requested, array(self::VIEW, self::REVIEW, self::MANAGE), true)) {
            return $allcaps;
        }

        static $cache = array();

        $user_id = $user instanceof WP_User ? (int) $user->ID : 0;

        if (!isset($cache[$user_id])) {
            $cache[$user_id] = self::level_for($user);
        }

        $level = $cache[$user_id];
        $rank = self::rank($level);

        $allcaps[self::VIEW]   = $rank >= self::rank(self::LEVEL_VIEW);
        $allcaps[self::REVIEW] = $rank >= self::rank(self::LEVEL_REVIEW_OWN);
        $allcaps[self::MANAGE] = $rank >= self::rank(self::LEVEL_MANAGE);

        unset($caps);

        return $allcaps;
    }

    /* --------------------------------------------------------------------
     * Convenience checks
     * ----------------------------------------------------------------- */

    public static function can_view() {
        return current_user_can(self::VIEW);
    }

    public static function can_manage() {
        return current_user_can(self::MANAGE);
    }

    /**
     * Can the current user approve or reject this change?
     *
     * Two gates that both have to pass: the agent-level permission, and
     * WordPress's own answer about whether they may edit that post. The
     * second is what makes review_own mean something — it defers to the
     * site's real editorial permissions rather than reimplementing them.
     *
     * @param int $post_id 0 asks the general question.
     */
    public static function can_review($post_id = 0) {
        if (!current_user_can(self::REVIEW)) {
            return false;
        }

        if (!$post_id) {
            return true;
        }

        if (!current_user_can('edit_post', (int) $post_id)) {
            return false;
        }

        if (self::LEVEL_REVIEW_OWN === self::level_for()) {
            $post = get_post((int) $post_id);

            return $post && (int) $post->post_author === get_current_user_id();
        }

        return true;
    }

    /**
     * The author ID the queue should be limited to, or 0 for no limit.
     *
     * Used to scope database queries so a restricted reviewer never even
     * sees a proposal they could not act on.
     */
    public static function author_scope() {
        if (self::LEVEL_REVIEW_OWN !== self::level_for()) {
            return 0;
        }

        return get_current_user_id();
    }

    /**
     * Whether the current user may kick off an analysis, which costs money.
     *
     * Deliberately stricter than viewing: a view-only account should not be
     * able to spend the site owner's budget.
     */
    public static function can_analyze($post_id = 0) {
        if (!current_user_can(self::REVIEW)) {
            return false;
        }

        return $post_id ? self::can_review($post_id) : true;
    }

    /**
     * A short description of what the current user can do, for the UI.
     */
    public static function current_summary() {
        switch (self::level_for()) {
            case self::LEVEL_MANAGE:
                return __('You can approve any change and configure the agent.', 'enhanced-content-plugin');

            case self::LEVEL_REVIEW_ALL:
                return __('You can approve any change on this site.', 'enhanced-content-plugin');

            case self::LEVEL_REVIEW_OWN:
                return __('You can approve changes to posts you wrote. Other people\'s posts are hidden.', 'enhanced-content-plugin');

            case self::LEVEL_VIEW:
                return __('You can see what the agent proposes, but not apply it. Ask an editor to approve.', 'enhanced-content-plugin');
        }

        return __('You do not have access to the agent.', 'enhanced-content-plugin');
    }
}
