<?php
if (!defined('ABSPATH'))
    exit;

class Johari_MI_Quiz_Module
{
    const VERSION = '0.2.6';
    const SLUG = 'johari-mi-quiz';
    const OPT_BCC = 'jmi_bcc_emails';
    const OPT_ANTITHREAD = 'jmi_antithread';
    const OPT_SHOW_PEER_DEBUG = 'jmi_show_peer_debug';

    // DB table suffixes
    const TABLE_SELF = 'jmi_self';
    const TABLE_LINKS = 'jmi_peer_links';
    const TABLE_FEEDBACK = 'jmi_peer_feedback';
    const TABLE_AGG = 'jmi_aggregates';

    public function __construct()
    {
        if (class_exists('Micro_Coach_Core')) {
            Micro_Coach_Core::register_quiz(self::SLUG, [
                'title' => 'Johari × MI',
                'shortcode' => 'johari_mi_quiz',
                'results_meta_key' => 'johari_mi_profile',
                'order' => 40,
                'description' => 'Peer-feedback on MI adjectives organized into the Johari Window (Open/Blind/Hidden/Unknown).',
                'description_completed' => 'View your Johari Window with MI domain insights and peer agreement.',
            ]);
        }

        add_shortcode('johari_mi_quiz', [$this, 'render_quiz']);
        add_shortcode('johari-mi-quiz', [$this, 'render_quiz']);

        if (is_admin()) {
            add_action('admin_menu', [$this, 'add_settings_pages']);
            add_action('admin_init', [$this, 'register_settings']);
        } else {
            add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        }

        // AJAX endpoints
        add_action('wp_ajax_miq_jmi_save_self', [$this, 'ajax_save_self']);
        add_action('wp_ajax_nopriv_miq_jmi_save_self', [$this, 'ajax_save_self']);

        // Peer submission now requires login (no nopriv)
        add_action('wp_ajax_miq_jmi_peer_submit', [$this, 'ajax_peer_submit']);

        add_action('wp_ajax_miq_jmi_generate_results', [$this, 'ajax_generate_results']);
        add_action('wp_ajax_nopriv_miq_jmi_generate_results', [$this, 'ajax_generate_results']);

        add_action('wp_ajax_jmi_generate_pdf', [$this, 'ajax_generate_pdf']);
        add_action('wp_ajax_nopriv_jmi_generate_pdf', [$this, 'ajax_generate_pdf']);
        add_action('wp_ajax_jmi_get_user_data', [$this, 'ajax_get_user_data']);
        add_action('wp_ajax_nopriv_jmi_get_user_data', [$this, 'ajax_get_user_data']);

        // Login status check (both logged in and logged out users)
        add_action('wp_ajax_miq_jmi_check_login', [$this, 'ajax_check_login']);
        add_action('wp_ajax_nopriv_miq_jmi_check_login', [$this, 'ajax_check_login']);

        // Peer count check for progress display
        add_action('wp_ajax_miq_jmi_get_peer_count', [$this, 'ajax_get_peer_count']);
        add_action('wp_ajax_nopriv_miq_jmi_get_peer_count', [$this, 'ajax_get_peer_count']);

        // Custom registration endpoint for peer assessment auto-login
        add_action('wp_ajax_nopriv_jmi_magic_register', [$this, 'ajax_peer_magic_register']);

        // Get original user's name from JMI UUID (for peer assessment context)
        add_action('wp_ajax_jmi_get_original_user', [$this, 'ajax_get_original_user']);
        add_action('wp_ajax_nopriv_jmi_get_original_user', [$this, 'ajax_get_original_user']);

        // User action: delete their Johari results and reset assessment
        add_action('wp_ajax_miq_jmi_delete_results', [$this, 'ajax_delete_results']);

        // User action: update self-assessment (redo) without deleting peer feedback
        add_action('wp_ajax_miq_jmi_update_self', [$this, 'ajax_update_self']);

        add_action('delete_user', [$this, 'handle_user_deletion']);

        // Admin AJAX endpoints
        add_action('wp_ajax_jmi_export_subs', [$this, 'ajax_export_subs']);
        add_action('wp_ajax_jmi_delete_subs', [$this, 'ajax_delete_subs']);
        add_action('wp_ajax_jmi_view_user_results', [$this, 'ajax_view_user_results']);

        // Admin testing interface AJAX endpoints
        add_action('wp_ajax_jmi_get_assessment_data', [$this, 'ajax_get_assessment_data']);
        add_action('wp_ajax_jmi_admin_simulate_peer', [$this, 'ajax_admin_simulate_peer']);
        add_action('wp_ajax_jmi_admin_clear_test_data', [$this, 'ajax_admin_clear_test_data']);
        add_action('wp_ajax_miq_jmi_admin_clear_cached_results', [$this, 'ajax_admin_clear_cached_results']);
        add_action('wp_ajax_miq_jmi_create_test_peer', [$this, 'ajax_create_test_peer']);
        add_action('wp_ajax_miq_jmi_cleanup_test_users', [$this, 'ajax_cleanup_test_users']);
        add_action('wp_ajax_miq_jmi_return_to_admin', [$this, 'ajax_return_to_admin']);
        add_action('wp_ajax_miq_jmi_reset_assessment', [$this, 'ajax_reset_assessment']);
        add_action('wp_ajax_jmi_admin_clear_all_peer_data', [$this, 'ajax_admin_clear_all_peer_data']);
        add_action('wp_ajax_jmi_admin_add_mi_test_data', [$this, 'ajax_admin_add_mi_test_data']);
        add_action('wp_ajax_jmi_admin_clear_mi_test_data', [$this, 'ajax_admin_clear_mi_test_data']);
        add_action('wp_ajax_jmi_debug_user_data', [$this, 'ajax_debug_user_data']);

        // Security & cleanup hooks
        add_action('jmi_cleanup_expired_links', [$this, 'cleanup_expired_links']);
        if (!wp_next_scheduled('jmi_cleanup_expired_links')) {
            wp_schedule_event(time(), 'daily', 'jmi_cleanup_expired_links');
        }

        // Ensure proper redirects after login/registration for peer links
        add_filter('login_redirect', [$this, 'preserve_jmi_login_redirect'], 10, 3);
        add_filter('registration_redirect', [$this, 'preserve_jmi_registration_redirect']);

        // Store JMI UUID before registration/login for auto-login
        add_action('login_form', [$this, 'store_jmi_uuid_for_login']);
        add_action('register_form', [$this, 'store_jmi_uuid_for_login']);

        // Handle auto-login after successful registration
        add_action('user_register', [$this, 'handle_user_registration'], 10, 1);

        // Handle redirect after successful login for peer assessment  
        add_action('wp_login', [$this, 'handle_user_login'], 10, 2);

        // Initialize sessions for UUID storage
        add_action('init', [$this, 'init_session']);

        // Hook into user deletion to clean up assessment data
        add_action('delete_user', [$this, 'handle_user_deletion']);

        // Special admin URL handler for adding MI test data
        add_action('init', [$this, 'handle_mi_test_data_url']);
        add_action('init', [$this, 'handle_temp_login']);
        add_action('init', [$this, 'handle_admin_return']);

        // Ensure database tables exist
        $this->ensure_tables();
    }

    private function table($suffix)
    {
        global $wpdb;
        return $wpdb->prefix . $suffix;
    }

    public function ensure_tables()
    {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql_self = "CREATE TABLE IF NOT EXISTS `" . $this->table(self::TABLE_SELF) . "` (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NULL,
            uuid CHAR(36) NOT NULL,
            adjectives LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uuid (uuid),
            KEY user_id (user_id)
        ) $charset;";

        $sql_links = "CREATE TABLE IF NOT EXISTS `" . $this->table(self::TABLE_LINKS) . "` (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            self_id BIGINT UNSIGNED NOT NULL,
            uuid CHAR(36) NOT NULL,
            created_at DATETIME NOT NULL,
            expires_at DATETIME NULL,
            max_peers TINYINT UNSIGNED NOT NULL DEFAULT 5,
            visited INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY uuid (uuid),
            KEY self_id (self_id)
        ) $charset;";

        $sql_feedback = "CREATE TABLE IF NOT EXISTS `" . $this->table(self::TABLE_FEEDBACK) . "` (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            self_id BIGINT UNSIGNED NOT NULL,
            link_id BIGINT UNSIGNED NOT NULL,
            peer_user_id BIGINT UNSIGNED NOT NULL,
            adjectives LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL,
            ip VARCHAR(64) NULL,
            PRIMARY KEY (id),
            KEY self_id (self_id),
            KEY link_id (link_id),
            KEY peer_user_id (peer_user_id),
            UNIQUE KEY no_duplicate_peer (self_id, peer_user_id)
        ) $charset;";

        $sql_agg = "CREATE TABLE IF NOT EXISTS `" . $this->table(self::TABLE_AGG) . "` (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            self_id BIGINT UNSIGNED NOT NULL,
            open LONGTEXT NULL,
            blind LONGTEXT NULL,
            hidden LONGTEXT NULL,
            unknown LONGTEXT NULL,
            domain_summary LONGTEXT NULL,
            last_recalc DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY self_id (self_id)
        ) $charset;";

        dbDelta($sql_self);
        dbDelta($sql_links);
        dbDelta($sql_feedback);
        dbDelta($sql_agg);

        // Handle existing tables that might be missing columns
        $this->migrate_existing_tables();
    }

    /**
     * Handle migrations for existing tables that might be missing columns
     */
    private function migrate_existing_tables()
    {
        global $wpdb;

        $feedback_table = $this->table(self::TABLE_FEEDBACK);

        // Check if peer_user_id column exists
        $columns = $wpdb->get_col("DESCRIBE `$feedback_table`", 0);

        if (!in_array('peer_user_id', $columns)) {
            error_log('JMI: Adding missing peer_user_id column to feedback table');

            // Add the missing column
            $wpdb->query("ALTER TABLE `$feedback_table` ADD COLUMN peer_user_id BIGINT UNSIGNED NOT NULL AFTER link_id");

            // Add the index
            $wpdb->query("ALTER TABLE `$feedback_table` ADD INDEX peer_user_id (peer_user_id)");

            // Add the unique constraint (might fail if there are duplicates, which is ok)
            $wpdb->query("ALTER TABLE `$feedback_table` ADD UNIQUE KEY no_duplicate_peer (self_id, peer_user_id)");

            error_log('JMI: Successfully added peer_user_id column and indexes');
        }
    }

    public function add_settings_pages()
    {
        add_submenu_page(
            'quiz-platform-settings',
            'Johari × MI Peers',
            'Johari × MI Subs',
            'manage_options',
            'johari-mi-subs',
            [$this, 'render_admin_page']
        );
    }

    public function register_settings()
    {
        register_setting('mc_quiz_platform_settings', self::OPT_BCC);
        register_setting('mc_quiz_platform_settings', self::OPT_ANTITHREAD);
        register_setting('mc_quiz_platform_settings', self::OPT_SHOW_PEER_DEBUG);
        add_settings_section('jmi_main', 'Johari × MI Settings', function () {
            echo '<p>Peer-feedback settings for the Johari × MI assessment.</p>';
        }, 'quiz-platform-settings');
        add_settings_field(self::OPT_BCC, 'BCC Results Email', function () {
            $v = esc_attr(get_option(self::OPT_BCC, ''));
            echo '<input type="text" style="width:480px" name="' . esc_attr(self::OPT_BCC) . '" value="' . $v . '" placeholder="admin@example.com, another@example.com">';
            echo '<p class="description">Admins to notify with a copy of results. Comma-separated.</p>';
        }, 'quiz-platform-settings', 'jmi_main');
        add_settings_field(self::OPT_SHOW_PEER_DEBUG, 'Show Peer Debug Info to All Users', function () {
            $v = get_option(self::OPT_SHOW_PEER_DEBUG, '0');
            echo '<label><input type="checkbox" name="' . esc_attr(self::OPT_SHOW_PEER_DEBUG) . '" value="1" ' . checked($v, '1', false) . '> Enable peer response debug visibility for all users</label>';
            echo '<p class="description">When enabled, all users can see detailed peer response breakdowns in their results (normally admin-only). Useful for testing.</p>';
        }, 'quiz-platform-settings', 'jmi_main');
    }

    public function enqueue_assets()
    {
        global $post;
        if (!is_a($post, 'WP_Post') || !has_shortcode($post->post_content, 'johari_mi_quiz'))
            return;

        wp_register_style('jmi-css', plugins_url('css/johari-mi-quiz.css', __FILE__), [], self::VERSION);
        wp_enqueue_style('jmi-css');

        // Enqueue shared About card styles
        $base_url = plugin_dir_url(MC_QUIZ_PLATFORM_PATH . 'mi-quiz-platform.php');
        wp_enqueue_style('mc-about-cards', $base_url . 'assets/about-cards.css', [], '1.0.0');

        wp_register_script('jmi-js', plugins_url('johari-mi-quiz.js', __FILE__), [], self::VERSION, true);

        // Load adjective data
        $adjectives_file = __DIR__ . '/jmi-adjectives.php';
        $adjective_data = [];
        if (file_exists($adjectives_file)) {
            $adjective_data = require $adjectives_file;
        }

        $user_data = null;

        // Debug logging
        error_log('JMI Quiz Debug - is_user_logged_in(): ' . (is_user_logged_in() ? 'true' : 'false'));
        if (is_user_logged_in()) {
            error_log('JMI Quiz Debug - Current user ID: ' . get_current_user_id());
        }

        if (is_user_logged_in()) {
            $user = wp_get_current_user();
            $johari_profile = get_user_meta($user->ID, 'johari_mi_profile', true);
            $self_uuid = get_user_meta($user->ID, 'jmi_self_uuid', true);

            // Debug logging for problematic users
            error_log("JMI Debug - User {$user->ID}: self_uuid from meta = " . ($self_uuid ?: 'NULL'));

            // Check if user has a recent assessment in awaiting-peers state
            $existing_state = null;
            $peer_link_uuid = null;
            if ($self_uuid) {
                global $wpdb;
                $self_table = $this->table(self::TABLE_SELF);
                $feedback_table = $this->table(self::TABLE_FEEDBACK);
                $links_table = $this->table(self::TABLE_LINKS);

                $self_row = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM `$self_table` WHERE uuid = %s AND user_id = %d",
                    $self_uuid,
                    $user->ID
                ));

                error_log("JMI Debug - User {$user->ID}: Looking for self_row with uuid={$self_uuid}, found: " . ($self_row ? 'YES (id=' . $self_row->id . ')' : 'NO'));

                if ($self_row) {
                    $peer_count = $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM `$feedback_table` WHERE self_id = %d",
                        $self_row->id
                    ));

                    error_log("JMI Debug - User {$user->ID}: self_id={$self_row->id}, peer_count={$peer_count}");

                    // Get the peer link UUID (active links only)
                    $peer_link_uuid = $wpdb->get_var($wpdb->prepare(
                        "SELECT uuid FROM `$links_table` WHERE self_id = %d AND expires_at > NOW()",
                        $self_row->id
                    ));

                    error_log("JMI Debug - User {$user->ID}: peer_link_uuid query result = " . ($peer_link_uuid ?: 'NULL'));

                    // If no active link but user is in awaiting-peers state, create/extend a link
                    if (!$peer_link_uuid && $peer_count < 2) {
                        // Check if there's an existing link we can extend
                        $existing_link = $wpdb->get_row($wpdb->prepare(
                            "SELECT * FROM `$links_table` WHERE self_id = %d ORDER BY created_at DESC LIMIT 1",
                            $self_row->id
                        ));

                        if ($existing_link) {
                            // Extend the existing link by 30 days
                            $new_expiry = date('Y-m-d H:i:s', strtotime('+30 days'));
                            $wpdb->update(
                                $links_table,
                                ['expires_at' => $new_expiry],
                                ['id' => $existing_link->id],
                                ['%s'],
                                ['%d']
                            );
                            $peer_link_uuid = $existing_link->uuid;
                            error_log("JMI Debug - Extended existing link for user {$user->ID}: {$peer_link_uuid}");
                        } else {
                            // Create a new peer link
                            $peer_link_uuid = wp_generate_uuid4();
                            $expires_at = date('Y-m-d H:i:s', strtotime('+30 days'));

                            $wpdb->insert($links_table, [
                                'uuid' => $peer_link_uuid,
                                'self_id' => $self_row->id,
                                'expires_at' => $expires_at,
                                'created_at' => current_time('mysql')
                            ], ['%s', '%d', '%s', '%s']);

                            error_log("JMI Debug - Created new link for user {$user->ID}: {$peer_link_uuid}");
                        }
                    }

                    if ($peer_count >= 2) {
                        $existing_state = 'results-ready';
                    } else {
                        $existing_state = 'awaiting-peers';
                    }
                }
            } else {
                // User has no self_uuid in meta - check if they have orphaned data in database
                global $wpdb;
                $self_table = $this->table(self::TABLE_SELF);
                $orphaned_assessment = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM `$self_table` WHERE user_id = %d ORDER BY created_at DESC LIMIT 1",
                    $user->ID
                ));

                if ($orphaned_assessment) {
                    error_log("JMI Debug - User {$user->ID}: Found orphaned assessment with UUID: {$orphaned_assessment->uuid}");
                    // Repair the user meta
                    update_user_meta($user->ID, 'jmi_self_uuid', $orphaned_assessment->uuid);
                    $self_uuid = $orphaned_assessment->uuid;

                    // Now process as normal
                    $feedback_table = $this->table(self::TABLE_FEEDBACK);
                    $links_table = $this->table(self::TABLE_LINKS);

                    $peer_count = $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM `$feedback_table` WHERE self_id = %d",
                        $orphaned_assessment->id
                    ));

                    // Get the peer link UUID (active links only)
                    $peer_link_uuid = $wpdb->get_var($wpdb->prepare(
                        "SELECT uuid FROM `$links_table` WHERE self_id = %d AND expires_at > NOW()",
                        $orphaned_assessment->id
                    ));

                    // If no active link but user is in awaiting-peers state, create/extend a link
                    if (!$peer_link_uuid && $peer_count < 2) {
                        // Check if there's an existing link we can extend
                        $existing_link = $wpdb->get_row($wpdb->prepare(
                            "SELECT * FROM `$links_table` WHERE self_id = %d ORDER BY created_at DESC LIMIT 1",
                            $orphaned_assessment->id
                        ));

                        if ($existing_link) {
                            // Extend the existing link by 30 days
                            $new_expiry = date('Y-m-d H:i:s', strtotime('+30 days'));
                            $wpdb->update(
                                $links_table,
                                ['expires_at' => $new_expiry],
                                ['id' => $existing_link->id],
                                ['%s'],
                                ['%d']
                            );
                            $peer_link_uuid = $existing_link->uuid;
                            error_log("JMI Debug - Extended existing link for orphaned assessment user {$user->ID}: {$peer_link_uuid}");
                        }
                    }

                    if ($peer_count >= 2) {
                        $existing_state = 'results-ready';
                    } else {
                        $existing_state = 'awaiting-peers';
                    }

                    error_log("JMI Debug - User {$user->ID}: Repaired orphaned assessment, state: $existing_state, peer_link_uuid: " . ($peer_link_uuid ?: 'NULL'));
                }
            }

            // Check if current user is a test peer
            $is_test_peer = get_user_meta($user->ID, '_jmi_test_peer', true);
            $admin_return_available = false;
            if ($is_test_peer) {
                $admin_return_token = get_user_meta($user->ID, '_jmi_admin_return_token', true);
                $admin_return_expires = get_user_meta($user->ID, '_jmi_admin_return_expires', true);
                $admin_return_available = $admin_return_token && $admin_return_expires && time() < intval($admin_return_expires);
            }

            $user_data = [
                'id' => $user->ID,
                'email' => $user->user_email,
                'firstName' => $user->first_name,
                'lastName' => $user->last_name,
                'role' => $user->roles[0] ?? 'subscriber', // Primary role
                'isAdmin' => user_can($user, 'manage_options'),
                'isTestPeer' => (bool) $is_test_peer,
                'adminReturnAvailable' => $admin_return_available,
                'johari' => is_array($johari_profile) ? $johari_profile : null,
                'selfUuid' => $self_uuid,
                'peerLinkUuid' => $peer_link_uuid,
                'existingState' => $existing_state,
            ];
        }

        // Find dashboard URL (for Lab Mode CTA after results)
        $dashboard_url = $this->_find_page_by_shortcode('quiz_dashboard');

        $localized = [
            'currentUser' => $user_data,
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'ajaxNonce' => wp_create_nonce('jmi_nonce'),
            'miqNonce' => wp_create_nonce('miq_nonce'), // For registration endpoint
            'data' => $adjective_data,
            'dashboardUrl' => $dashboard_url,
            'showPeerDebug' => get_option(self::OPT_SHOW_PEER_DEBUG, '0') === '1',
        ];
        wp_localize_script('jmi-js', 'jmi_quiz_data', $localized);
        wp_enqueue_script('jmi-js');
    }

    public function render_quiz()
    {
        ob_start(); ?>
        <?php $dashboard_url = $this->_find_page_by_shortcode('quiz_dashboard'); ?>
        <div class="quiz-wrapper">
            <?php if ($dashboard_url): ?>
                <div class="back-bar" style="display:flex;justify-content:space-between;align-items:center;gap:12px;">
                    <a href="<?php echo esc_url($dashboard_url); ?>" class="back-link">&larr; Return to Dashboard</a>
                    <button type="button" id="jmi-about-top" class="mi-quiz-button mi-quiz-button-secondary"
                        onclick="return window._jmiAboutToggle ? window._jmiAboutToggle(event) : (function(e){ e&&e.preventDefault&&e.preventDefault(); var m=document.getElementById('jmi-about-modal'), c=document.getElementById('jmi-container'); if(!m) return false; var show=(m.style.display==='none'||!m.style.display); if(show){ if(c){ m.dataset.prevCont=(c.style.display||''); c.style.display='none'; } m.style.display='block'; } else { m.style.display='none'; if(c && ('prevCont' in m.dataset)) c.style.display=m.dataset.prevCont; } return false; })(event)">About</button>
                </div>
            <?php endif; ?>
            <!-- Staging screen -->
            <div id="jmi-stage" class="mi-quiz-card">
                <h2 class="mi-section-title">Bring In Trusted Perspectives</h2>
                <div class="stage-intro">
                    <p>Ask a few peers to select adjectives that describe you. You’ll see your Johari Window mapped to MI
                        strengths — the final piece to unlock Lab Mode.</p>
                    <h3>How It Works</h3>
                    <ul>
                        <li>Invite 2–5 peers with a private link; they choose adjectives in minutes.</li>
                        <li>We map their responses with yours into the Johari Window (Open, Blind, Hidden, Unknown).</li>
                        <li>Finish to unlock Lab Mode — AI‑assisted, high‑leverage experiments informed by peer insight.</li>
                    </ul>
                </div>
                <div style="text-align:center; margin-top: 1em;">
                    <button type="button" id="jmi-stage-start" class="mi-quiz-button mi-quiz-button-primary">Start Johari ×
                        MI</button>
                </div>
            </div>
            <div id="jmi-container" style="display:none;">
                <div class="mi-quiz-card">
                    <h2 class="mi-section-title">Johari × MI</h2>
                    <p>Select adjectives that describe you. Then invite peers to do the same. We’ll map results to the Johari
                        Window and MI domains.</p>
                </div>
                <div id="jmi-self"></div>
                <div id="jmi-share"></div>
                <div id="jmi-results" style="display:none;"></div>
            </div>
        </div>
        <div id="jmi-about-modal" class="mi-quiz-card quiz-about-card"
            style="display:none; text-align:left; max-width:840px; margin:16px auto;">
            <h2 class="mi-section-title">About Johari × MI</h2>
            <p>Johari × MI blends a simple adjective‑based self/peer reflection with your Multiple Intelligences profile. You’ll
                choose adjectives for yourself, then invite peers to choose the ones that fit you. The overlap and gaps populate
                the Johari Window with MI‑aware summaries that are easy to act on.</p>

            <h3>What it reveals</h3>
            <ul>
                <li><strong>Open</strong> — strengths both you and others recognize.</li>
                <li><strong>Blind</strong> — qualities others see that you underrate.</li>
                <li><strong>Hidden</strong> — traits you see that others don’t notice.</li>
                <li><strong>Unknown</strong> — potential areas to explore together.</li>
            </ul>

            <h3>How it works</h3>
            <ul>
                <li>Select 6–10 adjectives that best describe you.</li>
                <li>Share a private link; each peer selects adjectives in about a minute.</li>
                <li>Results unlock when two peers respond; your window generates instantly.</li>
            </ul>

            <h3>What you’ll get</h3>
            <ul>
                <li>A clear Johari window plus MI domain summaries.</li>
                <li>Prompts to discuss differences productively (1:1s, team reviews).</li>
                <li>Links into Lab Mode to turn insights into short experiments.</li>
            </ul>

            <h3>Time & tips</h3>
            <ul>
                <li>Self‑selection: ~1–2 minutes; each peer: ~1–2 minutes.</li>
                <li>Pick peers who’ve seen you in action (not just friends).</li>
                <li>Share one concrete experiment you’ll try based on the results.</li>
            </ul>
        </div>
        <script>
            (function () {
                function ensureStageHandlers() {
                    var btn = document.getElementById('jmi-stage-start');
                    var stage = document.getElementById('jmi-stage');
                    var cont = document.getElementById('jmi-container');

                    // Check if user has existing assessment - if so, skip staging screen
                    var userData = window.jmi_quiz_data && window.jmi_quiz_data.currentUser;
                    if (userData && userData.existingState && stage && cont) {
                        console.log('JMI: User has existing assessment, auto-skipping staging screen');
                        stage.style.display = 'none';
                        cont.style.display = 'block';
                        return; // Don't add click handler since we're skipping
                    }

                    if (btn && stage && cont) {
                        btn.addEventListener('click', function () {
                            stage.style.display = 'none';
                            cont.style.display = 'block';
                            // Re-run initialization to ensure correct interface is shown
                            if (window.jmiInit && typeof window.jmiInit === 'function') {
                                window.jmiInit();
                            }
                        });
                    }
                }
                function ensureAboutToggle() {
                    function getModal() { return document.getElementById('jmi-about-modal'); }
                    function toggle() {
                        var m = getModal(); if (!m) return false;
                        var cont = document.getElementById('jmi-container');
                        var show = (m.style.display === 'none' || !m.style.display);
                        if (show) {
                            if (cont) { m.dataset.prevCont = cont.style.display || ''; cont.style.display = 'none'; }
                            m.style.display = 'block';
                        } else {
                            m.style.display = 'none';
                            if (cont && ('prevCont' in m.dataset)) cont.style.display = m.dataset.prevCont;
                        }
                        return false;
                    }
                    var aboutBtn = document.getElementById('jmi-about-top');
                    if (aboutBtn && !aboutBtn.getAttribute('data-about-bound')) {
                        aboutBtn.addEventListener('click', function (e) { e.preventDefault(); toggle(); });
                        aboutBtn.setAttribute('data-about-bound', '1');
                    }
                    // Delegate as safety for page builders
                    document.addEventListener('click', function (e) {
                        var t = e.target;
                        if (!t) return;
                        if (t.id === 'jmi-about-top' || (t.closest && t.closest('#jmi-about-top'))) { e.preventDefault(); toggle(); }
                    }, true);
                    window._jmiAboutToggle = function (e) { if (e && e.preventDefault) e.preventDefault(); return toggle(); };
                }
                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', function () { ensureStageHandlers(); ensureAboutToggle(); });
                } else {
                    ensureStageHandlers(); ensureAboutToggle();
                }
            })();
        </script>
        <div class="quiz-wrapper quiz-funnel-card" style="margin: 2em auto;">
            <div class="mi-quiz-card">
                <h2 class="mi-section-title">Your Progress So Far</h2>
                <?php echo do_shortcode('[quiz_funnel show_description="false" style="dashboard"]'); ?>
            </div>
        </div>
        <?php return ob_get_clean();
    }


    /**
     * Finds the permalink of the first page that contains a given shortcode.
     */
    private function _find_page_by_shortcode($shortcode_tag)
    {
        if (empty($shortcode_tag))
            return null;
        $transient_key = 'mc_page_url_for_' . $shortcode_tag;
        if (false !== ($cached = get_transient($transient_key)))
            return $cached;
        $query = new WP_Query([
            'post_type' => ['page', 'post'],
            'post_status' => 'publish',
            'posts_per_page' => -1,
            's' => '[' . $shortcode_tag
        ]);
        $url = null;
        if ($query->have_posts()) {
            foreach ($query->posts as $p) {
                if (has_shortcode($p->post_content, $shortcode_tag)) {
                    $url = get_permalink($p->ID);
                    break;
                }
            }
        }
        set_transient($transient_key, $url, DAY_IN_SECONDS);
        return $url;
    }

    private function maybe_antithread($subject)
    {
        if (!get_option(self::OPT_ANTITHREAD, '1'))
            return $subject;
        $zw = "\xE2\x80\x8B";
        return $subject . str_repeat($zw, wp_rand(1, 3));
    }

    private function generate_uuid()
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }

    private function maybe_notify_owner($self_id, $peer_count)
    {
        global $wpdb;
        $self_table = $this->table(self::TABLE_SELF);
        $self_row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM `$self_table` WHERE id = %d",
            $self_id
        ));

        if (!$self_row || !$self_row->user_id) {
            return; // No user to notify
        }

        $user = get_userdata($self_row->user_id);
        if (!$user) {
            return;
        }

        // Only send notification when reaching exactly 2 peers (first threshold)
        if ($peer_count == 2) {
            $subject = $this->maybe_antithread('Your Johari × MI results are ready!');
            $message = "Hi {$user->first_name},\n\nGreat news! You've received feedback from {$peer_count} peers for your Johari × MI assessment. Your results are now ready to view.\n\nView your results: " . get_permalink() . "\n\nThanks!";

            wp_mail($user->user_email, $subject, $message);
        }
    }

    /**
     * AJAX: Delete current user's Johari results and reset assessment
     */
    public function ajax_delete_results()
    {
        check_ajax_referer('jmi_nonce', '_ajax_nonce');

        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error('Not logged in');
        }

        global $wpdb;
        $self_table = $this->table(self::TABLE_SELF);
        $links_table = $this->table(self::TABLE_LINKS);
        $feedback_table = $this->table(self::TABLE_FEEDBACK);
        $agg_table = $this->table(self::TABLE_AGG);

        // Find user's assessment row
        $self_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM `$self_table` WHERE user_id = %d ORDER BY id DESC LIMIT 1", $user_id));
        if ($self_row) {
            // Delete related data
            $wpdb->delete($feedback_table, ['self_id' => $self_row->id]);
            $wpdb->delete($links_table, ['self_id' => $self_row->id]);
            $wpdb->delete($agg_table, ['self_id' => $self_row->id]);
            $wpdb->delete($self_table, ['id' => $self_row->id]);
        }

        // Clear user meta so UI returns to initial state
        delete_user_meta($user_id, 'johari_mi_profile');
        delete_user_meta($user_id, 'jmi_self_uuid');

        wp_send_json_success(['message' => 'Johari results deleted']);
    }

    /**
     * AJAX: Update self-assessment (redo) without deleting peer feedback
     */
    public function ajax_update_self()
    {
        check_ajax_referer('jmi_nonce', '_ajax_nonce');

        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error('Not logged in');
        }

        $adjectives_raw = isset($_POST['adjectives']) ? wp_unslash($_POST['adjectives']) : '';
        $adjectives = json_decode($adjectives_raw, true);

        // Validate adjectives
        if (!is_array($adjectives) || count($adjectives) < 6 || count($adjectives) > 10) {
            wp_send_json_error('Please select 6-10 adjectives.');
        }

        // Load valid adjectives to verify selection
        $adjective_data = require __DIR__ . '/jmi-adjectives.php';
        $all_valid = $adjective_data['all_adjectives'];

        foreach ($adjectives as $adj) {
            if (!in_array($adj, $all_valid)) {
                wp_send_json_error('Invalid adjective selected.');
            }
        }

        global $wpdb;
        $self_table = $this->table(self::TABLE_SELF);
        $agg_table = $this->table(self::TABLE_AGG);

        // Find user's existing assessment
        $self_row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM `$self_table` WHERE user_id = %d ORDER BY id DESC LIMIT 1",
            $user_id
        ));

        if (!$self_row) {
            wp_send_json_error('No existing assessment found to update.');
        }

        $now = current_time('mysql');

        // Update the adjectives in the self-assessment
        $result = $wpdb->update(
            $self_table,
            [
                'adjectives' => json_encode($adjectives),
                'updated_at' => $now
            ],
            ['id' => $self_row->id],
            ['%s', '%s'],
            ['%d']
        );

        if ($result === false) {
            wp_send_json_error('Could not update assessment.');
        }

        // Clear cached results to force recalculation with new self-adjectives
        $wpdb->delete($agg_table, ['self_id' => $self_row->id]);

        // Clear user meta profile to force fresh generation
        delete_user_meta($user_id, 'johari_mi_profile');

        // Get peer count to determine if results are ready
        $feedback_table = $this->table(self::TABLE_FEEDBACK);
        $peer_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `$feedback_table` WHERE self_id = %d",
            $self_row->id
        ));

        wp_send_json_success([
            'message' => 'Self-assessment updated successfully',
            'self_id' => $self_row->id,
            'peer_count' => intval($peer_count),
            'can_view_results' => $peer_count >= 2
        ]);
    }

    private function calculate_johari_window($self_id)
    {
        global $wpdb;

        // Check for cached results
        $agg_table = $this->table(self::TABLE_AGG);
        $cached = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM `$agg_table` WHERE self_id = %d AND last_recalc > DATE_SUB(NOW(), INTERVAL 1 HOUR)",
            $self_id
        ));

        if ($cached) {
            // For cached results, we need to regenerate debug info if we're on localhost
            $base_results = [
                'open' => json_decode($cached->open, true),
                'blind' => json_decode($cached->blind, true),
                'hidden' => json_decode($cached->hidden, true),
                'unknown' => json_decode($cached->unknown, true),
                'domain_summary' => json_decode($cached->domain_summary, true)
            ];

            // Get self-assessment data for MI profile retrieval and debug info
            $self_table = $this->table(self::TABLE_SELF);
            $self_row = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM `$self_table` WHERE id = %d",
                $self_id
            ));

            // Always get MI profile data for cached results
            if ($self_row && $self_row->user_id) {
                error_log("JMI MI PROFILE DEBUG - Getting MI profile for cached results, user ID: " . $self_row->user_id);
                $base_results['mi_profile'] = $this->get_user_mi_profile($self_row->user_id);
                error_log("JMI MI PROFILE DEBUG - Retrieved MI profile for cached results: " . json_encode($base_results['mi_profile']));
            }

            // Add debug info for development environment
            if (isset($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], 'mi-test-site.local') !== false) {
                // Get fresh debug data
                $feedback_table = $this->table(self::TABLE_FEEDBACK);
                $peer_rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT adjectives, peer_user_id, created_at FROM `$feedback_table` WHERE self_id = %d",
                    $self_id
                ));

                if ($self_row) {
                    $self_adjectives = json_decode($self_row->adjectives, true);
                    $peer_responses = [];
                    $all_peer_adjectives = [];
                    $peer_counts = [];

                    foreach ($peer_rows as $row) {
                        $peer_adjectives = json_decode($row->adjectives, true);
                        $peer_responses[] = [
                            'peer_user_id' => $row->peer_user_id,
                            'adjectives' => $peer_adjectives,
                            'created_at' => $row->created_at
                        ];

                        foreach ($peer_adjectives as $adj) {
                            $peer_counts[$adj] = ($peer_counts[$adj] ?? 0) + 1;
                            if (!in_array($adj, $all_peer_adjectives)) {
                                $all_peer_adjectives[] = $adj;
                            }
                        }
                    }

                    $base_results['debug_info'] = [
                        'self_adjectives' => $self_adjectives,
                        'all_peer_adjectives' => $all_peer_adjectives,
                        'peer_responses' => $peer_responses,
                        'peer_counts' => $peer_counts,
                        'total_peers' => count($peer_rows)
                    ];
                }
            }

            return $base_results;
        }

        // Load adjective data
        $adjective_data = require __DIR__ . '/jmi-adjectives.php';
        $all_adjectives = $adjective_data['all_adjectives'];
        $adjective_map = $adjective_data['adjective_map'];

        // Get self-assessment
        $self_table = $this->table(self::TABLE_SELF);
        $self_row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM `$self_table` WHERE id = %d",
            $self_id
        ));

        if (!$self_row) {
            return false;
        }

        $self_adjectives = json_decode($self_row->adjectives, true);

        error_log("JMI ENHANCED DEBUG - Self adjectives for ID $self_id: " . implode(', ', $self_adjectives));

        // Get peer feedback and aggregate
        $feedback_table = $this->table(self::TABLE_FEEDBACK);
        $peer_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT adjectives, peer_user_id, created_at FROM `$feedback_table` WHERE self_id = %d",
            $self_id
        ));

        error_log("JMI ENHANCED DEBUG - Found " . count($peer_rows) . " peer responses for self_id $self_id");

        // Count how many peers selected each adjective and collect debug info
        $peer_counts = [];
        $peer_responses = [];
        $all_peer_adjectives = [];

        foreach ($peer_rows as $row) {
            $peer_adjectives = json_decode($row->adjectives, true);

            // Store individual peer response for debugging
            $peer_responses[] = [
                'peer_user_id' => $row->peer_user_id,
                'adjectives' => $peer_adjectives,
                'created_at' => $row->created_at
            ];

            foreach ($peer_adjectives as $adj) {
                $peer_counts[$adj] = ($peer_counts[$adj] ?? 0) + 1;
                if (!in_array($adj, $all_peer_adjectives)) {
                    $all_peer_adjectives[] = $adj;
                }
            }
        }

        // Log the raw data for verification
        error_log('JMI DEBUG - Self adjectives: ' . implode(', ', $self_adjectives));
        error_log('JMI DEBUG - Peer counts: ' . json_encode($peer_counts));
        error_log('JMI DEBUG - All peer adjectives: ' . implode(', ', $all_peer_adjectives));

        // Apply Johari Window logic
        $open = [];    // In self AND peer selections
        $blind = [];   // In peer selections but NOT in self
        $hidden = [];  // In self but NOT in peer selections
        $unknown = []; // In neither self nor peer selections

        foreach ($all_adjectives as $adjective) {
            $in_self = in_array($adjective, $self_adjectives);
            $in_peers = isset($peer_counts[$adjective]) && $peer_counts[$adjective] > 0;

            if ($in_self && $in_peers) {
                $open[] = $adjective;
            } elseif (!$in_self && $in_peers) {
                $blind[] = $adjective;
            } elseif ($in_self && !$in_peers) {
                $hidden[] = $adjective;
            } else {
                $unknown[] = $adjective;
            }
        }

        // Log the categorization results
        error_log('JMI DEBUG - OPEN (' . count($open) . '): ' . implode(', ', $open));
        error_log('JMI DEBUG - BLIND (' . count($blind) . '): ' . implode(', ', $blind));
        error_log('JMI DEBUG - HIDDEN (' . count($hidden) . '): ' . implode(', ', $hidden));
        error_log('JMI DEBUG - UNKNOWN (first 10): ' . implode(', ', array_slice($unknown, 0, 10)));

        // Calculate domain summary
        $domain_summary = [];
        foreach ($adjective_map as $domain => $domain_adjectives) {
            $domain_summary[$domain] = [
                'open' => count(array_intersect($open, $domain_adjectives)),
                'blind' => count(array_intersect($blind, $domain_adjectives)),
                'hidden' => count(array_intersect($hidden, $domain_adjectives)),
                'unknown' => count(array_intersect($unknown, $domain_adjectives))
            ];
        }

        // Get user's original MI assessment results for comparison
        error_log("JMI MI PROFILE DEBUG - Starting MI profile retrieval process");
        error_log("JMI MI PROFILE DEBUG - Self row user_id: " . ($self_row->user_id ?? 'NULL'));
        error_log("JMI MI PROFILE DEBUG - Self row details: " . json_encode([
            'id' => $self_row->id ?? 'NULL',
            'user_id' => $self_row->user_id ?? 'NULL',
            'uuid' => $self_row->uuid ?? 'NULL'
        ]));

        $mi_profile_data = null;
        if ($self_row->user_id) {
            error_log("JMI MI PROFILE DEBUG - User ID exists, attempting to get MI profile for user ID: " . $self_row->user_id);
            $mi_profile_data = $this->get_user_mi_profile($self_row->user_id);
            error_log("JMI MI PROFILE DEBUG - Retrieved MI profile data: " . json_encode($mi_profile_data));
        } else {
            error_log("JMI MI PROFILE DEBUG - No user_id found in self_row, cannot retrieve MI profile");
        }

        $results = [
            'open' => $open,
            'blind' => $blind,
            'hidden' => $hidden,
            'unknown' => $unknown,
            'domain_summary' => $domain_summary,
            'mi_profile' => $mi_profile_data, // Add MI subcategory data
            'generated_at' => current_time('mysql'),
            // Debug information (only included for development)
            'debug_info' => [
                'self_adjectives' => $self_adjectives,
                'all_peer_adjectives' => $all_peer_adjectives,
                'peer_responses' => $peer_responses,
                'peer_counts' => $peer_counts,
                'total_peers' => count($peer_rows)
            ]
        ];

        // Cache the results
        $wpdb->replace($agg_table, [
            'self_id' => $self_id,
            'open' => json_encode($open),
            'blind' => json_encode($blind),
            'hidden' => json_encode($hidden),
            'unknown' => json_encode($unknown),
            'domain_summary' => json_encode($domain_summary),
            'last_recalc' => current_time('mysql')
        ]);

        return $results;
    }

    public function render_admin_page()
    {
        global $wpdb;

        // Get all self-assessments with user info
        $self_table = $this->table(self::TABLE_SELF);
        $feedback_table = $this->table(self::TABLE_FEEDBACK);

        $query = "
            SELECT s.*, u.user_email, u.display_name,
                   COUNT(f.id) as peer_count
            FROM `$self_table` s
            LEFT JOIN {$wpdb->users} u ON s.user_id = u.ID
            LEFT JOIN `$feedback_table` f ON s.id = f.self_id
            GROUP BY s.id
            ORDER BY s.created_at DESC
            LIMIT 100
        ";

        $rows = $wpdb->get_results($query, ARRAY_A);
        $export_url = wp_nonce_url(admin_url('admin-ajax.php?action=jmi_export_subs'), 'jmi_admin_nonce');

        echo '<div class="wrap"><h1>Johari × MI Assessments</h1>';
        echo '<p><a class="button button-secondary" href="' . esc_url($export_url) . '">Download CSV</a></p>';

        // Admin testing interface
        $this->render_admin_testing_interface();

        if (empty($rows)) {
            echo '<p>No assessments yet.</p></div>';
            return;
        }

        echo '<table class="widefat striped"><thead><tr>
                <th>ID</th><th>Date</th><th>User</th><th>Email</th><th>Peer Count</th><th>Status</th><th>Actions</th>
              </tr></thead><tbody>';

        foreach ($rows as $r) {
            $status = (int) $r['peer_count'] >= 2 ? '<span style="color: green;">✓ Complete</span>' : '<span style="color: orange;">⏳ Awaiting Peers</span>';
            $row_id = intval($r['id']);
            echo '<tr data-assessment-id="' . $row_id . '">' .
                '<td>' . intval($r['id']) . '</td>' .
                '<td>' . esc_html($r['created_at']) . '</td>' .
                '<td>' . esc_html($r['display_name'] ?: 'Guest') . '</td>' .
                '<td>' . esc_html($r['user_email'] ?: 'N/A') . '</td>' .
                '<td>' . intval($r['peer_count']) . '</td>' .
                '<td>' . $status . '</td>' .
                '<td>' .
                '<button type="button" class="button button-secondary button-small jmi-view-results-btn" data-assessment-id="' . $row_id . '">View Results</button> ' .
                '<button type="button" class="button button-primary button-small jmi-delete-btn" data-assessment-id="' . $row_id . '">Delete</button>' .
                '</td>' .
                '</tr>';
            // Add accordion row for results (initially hidden)
            echo '<tr id="jmi-results-row-' . $row_id . '" class="jmi-results-accordion" style="display: none;">' .
                '<td colspan="7">' .
                '<div id="jmi-results-content-' . $row_id . '" style="padding: 20px; background: #f8f9fa; border-radius: 8px;"></div>' .
                '<div style="text-align: center; padding: 10px;">' .
                '<button type="button" class="button button-secondary jmi-close-results" data-assessment-id="' . $row_id . '">Close Results</button>' .
                '</div>' .
                '</td>' .
                '</tr>';
        }
        echo '</tbody></table>';
        echo '</div>';
        ?>
        <script>
            var ajaxurl = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
            console.log('JMI Admin: Script loaded, ajaxurl =', ajaxurl);

            document.addEventListener('DOMContentLoaded', function () {
                console.log('JMI Admin: DOM ready, initializing inline buttons...');
                const $ = s => document.querySelector(s);
                const $$ = s => Array.from(document.querySelectorAll(s));
                const nonce = '<?php echo esc_js(wp_create_nonce('jmi_admin_nonce')); ?>';
                console.log('JMI Admin: nonce =', nonce);

                // Handle View Results button clicks
                $$('.jmi-view-results-btn').forEach(btn => {
                    btn.addEventListener('click', function () {
                        const assessmentId = this.getAttribute('data-assessment-id');
                        console.log('JMI Admin: View Results clicked for assessment:', assessmentId);

                        // Close any other open accordion results first
                        $$('.jmi-results-accordion').forEach(row => {
                            if (row.id !== 'jmi-results-row-' + assessmentId) {
                                row.style.display = 'none';
                            }
                        });

                        const resultRow = $('#jmi-results-row-' + assessmentId);
                        const resultContent = $('#jmi-results-content-' + assessmentId);

                        if (resultRow && resultContent) {
                            // Show the accordion row
                            resultRow.style.display = 'table-row';

                            // Show loading state
                            resultContent.innerHTML = '<div style="text-align: center; padding: 40px;"><p>Loading results...</p></div>';

                            // Fetch and display results
                            fetch(ajaxurl, {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                body: new URLSearchParams({ action: 'jmi_view_user_results', _ajax_nonce: nonce, assessment_id: assessmentId })
                            }).then(r => {
                                console.log('JMI Admin: Raw response status:', r.status);
                                return r.text();
                            }).then(text => {
                                console.log('JMI Admin: Raw response received');
                                try {
                                    const j = JSON.parse(text);
                                    if (j.success) {
                                        resultContent.innerHTML = j.data.html;
                                    } else {
                                        resultContent.innerHTML = '<div style="padding: 20px; color: red; text-align: center;">Error loading results: ' + (j.data || 'Unknown error') + '</div>';
                                    }
                                } catch (parseError) {
                                    console.error('JMI Admin: JSON parse error:', parseError);
                                    resultContent.innerHTML = '<div style="padding: 20px; color: red;">Server returned invalid response. Check console for details.</div>';
                                }
                            }).catch(err => {
                                console.error('JMI Admin: Network error:', err);
                                resultContent.innerHTML = '<div style="padding: 20px; color: red; text-align: center;">Network error: ' + err.message + '</div>';
                            });
                        }
                    });
                });

                // Handle Close Results button clicks
                $$('.jmi-close-results').forEach(btn => {
                    btn.addEventListener('click', function () {
                        const assessmentId = this.getAttribute('data-assessment-id');
                        console.log('JMI Admin: Close Results clicked for assessment:', assessmentId);

                        const resultRow = $('#jmi-results-row-' + assessmentId);
                        if (resultRow) {
                            resultRow.style.display = 'none';
                        }
                    });
                });

                // Handle Delete button clicks
                $$('.jmi-delete-btn').forEach(btn => {
                    btn.addEventListener('click', function () {
                        const assessmentId = this.getAttribute('data-assessment-id');
                        console.log('JMI Admin: Delete clicked for assessment:', assessmentId);

                        if (!confirm('Delete this assessment and all associated peer feedback?')) {
                            return;
                        }

                        // Disable button during request
                        this.disabled = true;
                        this.textContent = 'Deleting...';

                        fetch(ajaxurl, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: new URLSearchParams({ action: 'jmi_delete_subs', _ajax_nonce: nonce, ids: assessmentId })
                        }).then(r => r.json()).then(j => {
                            if (j.success) {
                                // Remove the row from the table
                                const row = this.closest('tr');
                                const nextRow = row.nextElementSibling;
                                if (nextRow && nextRow.classList.contains('jmi-results-accordion')) {
                                    nextRow.remove();
                                }
                                row.remove();
                            } else {
                                alert('Delete failed: ' + (j.data || 'Unknown error'));
                                this.disabled = false;
                                this.textContent = 'Delete';
                            }
                        }).catch(err => {
                            alert('Network error: ' + err.message);
                            this.disabled = false;
                            this.textContent = 'Delete';
                        });
                    });
                });
            });
        </script>
        <style>
            /* Unified About card styling (shared across quizzes) */
            .quiz-about-card {
                background: #fff;
                border: 1px solid #e2e8f0;
                border-radius: 12px;
                padding: 2rem;
            }

            .quiz-about-card h2 {
                margin: 0 0 1rem 0;
                color: #1a202c;
                font-size: 1.75rem;
            }

            .quiz-about-card h3 {
                margin: 1.5rem 0 0.75rem 0;
                color: #2d3748;
                font-size: 1.25rem;
            }

            .quiz-about-card p {
                line-height: 1.6;
                margin-bottom: 1rem;
                color: #4a5568;
            }

            .quiz-about-card ul {
                margin: 0.75rem 0 1.5rem 1.5rem;
            }

            .quiz-about-card li {
                margin-bottom: 0.5rem;
                line-height: 1.5;
            }

            /* Results display styling */
            #jmi-results-display {
                box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
                transition: all 0.3s ease;
            }

            #jmi-results-display h3 {
                color: #1a202c;
                border-bottom: 2px solid #e2e8f0;
                padding-bottom: 10px;
                margin-bottom: 20px;
            }

            .jmi-quadrant-grid {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 15px;
                margin-bottom: 20px;
            }

            @media (max-width: 768px) {
                .jmi-quadrant-grid {
                    grid-template-columns: 1fr;
                }
            }

            .jmi-quadrant {
                padding: 15px;
                border-radius: 8px;
                box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            }

            .jmi-quadrant h5 {
                margin-top: 0;
                font-size: 16px;
                font-weight: 600;
            }

            .jmi-adjective-tag {
                display: inline-block;
                margin: 2px;
                padding: 4px 8px;
                border-radius: 12px;
                font-size: 11px;
                font-weight: 500;
                text-decoration: none;
            }

            .jmi-mi-integration {
                margin-top: 20px;
                padding: 15px;
                background: #e8f5e8;
                border-radius: 8px;
                border-left: 4px solid #28a745;
            }

            /* Disabled button styling */
            button[disabled],
            button:disabled {
                opacity: 0.6;
                cursor: not-allowed;
            }

            button[disabled]:hover,
            button:disabled:hover {
                background-color: initial;
                border-color: initial;
            }

            /* Accordion results styling */
            .jmi-results-accordion {
                animation: slideDown 0.3s ease-out;
            }

            @keyframes slideDown {
                from {
                    opacity: 0;
                    transform: translateY(-10px);
                }

                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }

            .jmi-results-accordion td {
                padding: 0 !important;
                border-top: 1px solid #c3c4c7;
                background: #f6f7f7;
            }

            /* Small button styling for table actions */
            .button-small {
                padding: 3px 8px;
                font-size: 11px;
                line-height: 1.4;
                height: auto;
            }

            /* Action column styling */
            .widefat td:last-child {
                white-space: nowrap;
            }

            /* Results content styling */
            .jmi-results-accordion #jmi-results-content {
                max-height: 80vh;
                overflow-y: auto;
            }
        </style>
        <?php
    }

    private function render_admin_testing_interface()
    {
        // Load adjective data for the interface
        $adjective_data = require __DIR__ . '/jmi-adjectives.php';
        $adjective_map = $adjective_data['adjective_map'];
        $domain_colors = $adjective_data['domain_colors'];

        ?>
        <div style="background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 8px; padding: 20px; margin: 20px 0;">
            <h2 style="color: #495057; margin-top: 0;">🧪 Admin Testing Interface</h2>
            <p style="color: #6c757d;">Quickly simulate peer responses for testing the Johari Window logic. This bypasses normal
                validation for admin convenience.</p>

            <!-- Assessment Selection -->
            <div style="margin-bottom: 20px;">
                <label for="test-assessment-id" style="font-weight: bold;">Select Assessment to Test:</label>
                <select id="test-assessment-id" style="margin-left: 10px; padding: 5px;">
                    <option value="">-- Choose Assessment --</option>
                    <?php
                    global $wpdb;
                    $self_table = $this->table(self::TABLE_SELF);
                    $assessments = $wpdb->get_results("
                        SELECT s.id, s.uuid, s.created_at, u.display_name, u.user_email,
                               (SELECT COUNT(*) FROM {$this->table(self::TABLE_FEEDBACK)} f WHERE f.self_id = s.id) as peer_count
                        FROM `$self_table` s
                        LEFT JOIN {$wpdb->users} u ON s.user_id = u.ID
                        ORDER BY s.created_at DESC
                        LIMIT 20
                    ");

                    foreach ($assessments as $assessment) {
                        $label = ($assessment->display_name ?: 'Guest') . ' - ' . $assessment->created_at . ' (Peers: ' . $assessment->peer_count . ')';
                        echo '<option value="' . $assessment->id . '" data-uuid="' . $assessment->uuid . '">' . esc_html($label) . '</option>';
                    }
                    ?>
                </select>
                <button type="button" id="load-assessment-data" class="button" style="margin-left: 10px;">Load Assessment
                    Data</button>
            </div>

            <!-- Assessment Details -->
            <div id="assessment-details"
                style="display: none; background: white; padding: 15px; border-radius: 5px; margin-bottom: 20px;">
                <h4>Assessment Details</h4>
                <div id="assessment-info"></div>
                <div id="self-adjectives-display"></div>
            </div>

            <!-- Peer Simulation Forms -->
            <div id="peer-testing-forms" style="display: none;">
                <h3>Simulate Peer Responses</h3>
                <p style="color: #6c757d;">Select different adjectives for each simulated peer to test the Johari Window
                    categorization.</p>

                <!-- Peer 1 Form -->
                <div class="peer-test-form"
                    style="background: white; padding: 15px; margin: 10px 0; border-left: 4px solid #007cba; border-radius: 5px;">
                    <h4 style="color: #007cba; margin-top: 0;">👤 Simulated Peer 1</h4>
                    <div id="peer1-adjectives" class="test-adjective-grid"></div>
                    <div style="margin-top: 10px;">
                        <span id="peer1-counter">0 selected</span>
                        <button type="button" class="button button-primary" id="submit-peer1" style="margin-left: 15px;"
                            disabled>Submit Peer 1 Response</button>
                        <button type="button" class="button button-secondary" id="quick-peer1" style="margin-left: 10px;">Quick
                            Random (6 adjectives)</button>
                        <button type="button" class="button button-secondary" id="strategic-peer1"
                            style="margin-left: 10px;">Strategic Mix (test Blind/Hidden)</button>
                    </div>
                </div>

                <!-- Peer 2 Form -->
                <div class="peer-test-form"
                    style="background: white; padding: 15px; margin: 10px 0; border-left: 4px solid #28a745; border-radius: 5px;">
                    <h4 style="color: #28a745; margin-top: 0;">👤 Simulated Peer 2</h4>
                    <div id="peer2-adjectives" class="test-adjective-grid"></div>
                    <div style="margin-top: 10px;">
                        <span id="peer2-counter">0 selected</span>
                        <button type="button" class="button button-primary" id="submit-peer2" style="margin-left: 15px;"
                            disabled>Submit Peer 2 Response</button>
                        <button type="button" class="button button-secondary" id="quick-peer2" style="margin-left: 10px;">Quick
                            Random (6 adjectives)</button>
                        <button type="button" class="button button-secondary" id="strategic-peer2"
                            style="margin-left: 10px;">Strategic Mix (test Blind/Hidden)</button>
                    </div>
                </div>

                <!-- MI Test Data Section -->
                <div
                    style="background: #e8f5e8; padding: 15px; border-radius: 5px; margin-top: 20px; border-left: 4px solid #28a745;">
                    <h4 style="margin-top: 0; color: #155724;">🧠 MI Integration Testing</h4>
                    <p style="color: #155724; margin-bottom: 10px;">Add sample MI assessment data to test the MI × Johari
                        integration.</p>
                    <button type="button" class="button button-secondary" id="add-mi-test-data"
                        style="background: #28a745; color: white; border-color: #28a745;">Add Sample MI Data for Selected
                        User</button>
                    <button type="button" class="button button-secondary" id="clear-mi-test-data"
                        style="margin-left: 10px;">Clear MI Data</button>
                    <div id="mi-test-results" style="margin-top: 10px; font-size: 0.9em;"></div>
                </div>

                <!-- Results Section -->
                <div style="background: #e9ecef; padding: 15px; border-radius: 5px; margin-top: 20px;">
                    <h4 style="margin-top: 0;">🧪 Test Results</h4>
                    <button type="button" class="button button-primary" id="generate-test-results">Generate Johari Window
                        Results</button>
                    <button type="button" class="button button-secondary" id="clear-test-data" style="margin-left: 10px;">Clear
                        Test Data Only</button>
                    <button type="button" class="button button-secondary" id="clear-all-peer-data"
                        style="margin-left: 10px; color: #dc3545;">⚠️ Clear ALL Peer Data</button>
                    <div id="test-results-output" style="margin-top: 15px;"></div>
                </div>
            </div>
        </div>

        <style>
            .test-adjective-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
                gap: 8px;
                margin: 10px 0;
            }

            .test-adjective-label {
                display: inline-block;
                cursor: pointer;
            }

            .test-adjective-pill {
                display: inline-block;
                padding: 6px 12px;
                border: 1px solid #ccc;
                border-radius: 20px;
                background: white;
                font-size: 12px;
                transition: all 0.2s;
                user-select: none;
            }

            .test-adjective-label:hover .test-adjective-pill {
                background: #f8f9fa;
                transform: translateY(-1px);
            }

            .test-adjective-checkbox:checked+.test-adjective-pill {
                background: #007cba;
                color: white;
                border-color: #007cba;
            }

            .test-adjective-checkbox {
                display: none;
            }
        </style>

        <script>
            // Make sure ajaxurl is available for both scripts
            if (typeof ajaxurl === 'undefined') {
                var ajaxurl = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
            }
            jQuery(document).ready(function ($) {
                console.log('JMI Admin Testing: jQuery script initialized');
                const nonce = '<?php echo wp_create_nonce('jmi_admin_nonce'); ?>';
                let currentAssessmentId = null;
                let currentAssessmentData = null;
                let adjectiveData = <?php echo json_encode($adjective_map); ?>;
                let domainColors = <?php echo json_encode($domain_colors); ?>;

                // Load assessment data
                $('#load-assessment-data').click(function () {
                    const assessmentId = $('#test-assessment-id').val();
                    if (!assessmentId) {
                        alert('Please select an assessment first.');
                        return;
                    }

                    currentAssessmentId = assessmentId;
                    loadAssessmentData(assessmentId);
                });

                function loadAssessmentData(assessmentId) {
                    $.post(ajaxurl, {
                        action: 'jmi_get_assessment_data',
                        _ajax_nonce: nonce,
                        assessment_id: assessmentId
                    }, function (response) {
                        if (response.success) {
                            currentAssessmentData = response.data; // Store for strategic selection
                            displayAssessmentData(response.data);
                            setupAdjectiveGrids();
                            $('#assessment-details').show();
                            $('#peer-testing-forms').show();
                        } else {
                            alert('Error: ' + (response.data || 'Could not load assessment'));
                        }
                    });
                }

                function displayAssessmentData(data) {
                    $('#assessment-info').html(
                        '<p><strong>Assessment ID:</strong> ' + data.id + '</p>' +
                        '<p><strong>User:</strong> ' + (data.user_name || 'Guest') + '</p>' +
                        '<p><strong>Created:</strong> ' + data.created_at + '</p>' +
                        '<p><strong>Current Peer Count:</strong> ' + data.peer_count + '</p>'
                    );

                    // Display self-selected adjectives
                    let selfHtml = '<p><strong>Self-Selected Adjectives (' + data.self_adjectives.length + '):</strong></p>' +
                        '<div style="margin: 10px 0;">' +
                        data.self_adjectives.map(adj =>
                            '<span style="display: inline-block; margin: 3px; padding: 4px 8px; background: #e9ecef; border-radius: 12px; font-size: 12px;">' + adj + '</span>'
                        ).join('') +
                        '</div>';

                    // Display existing peer responses if any
                    if (data.peer_responses && data.peer_responses.length > 0) {
                        selfHtml += '<div style="margin-top: 20px; padding: 15px; background: #f8f9fa; border-radius: 5px; border-left: 4px solid #17a2b8;">';
                        selfHtml += '<h5 style="color: #17a2b8; margin-top: 0;">👥 Existing Peer Responses (' + data.peer_responses.length + ')</h5>';

                        data.peer_responses.forEach(function (peer, index) {
                            const borderColor = peer.is_test_peer ? '#fd7e14' : '#28a745';
                            const bgColor = peer.is_test_peer ? '#fff3cd' : '#d4edda';

                            selfHtml += '<div style="margin-bottom: 10px; padding: 10px; background: ' + bgColor + '; border-radius: 4px; border-left: 3px solid ' + borderColor + ';">';
                            selfHtml += '<div style="font-weight: bold; color: ' + borderColor + '; margin-bottom: 5px;">' + peer.label + ' (User ID: ' + peer.user_id + ')</div>';
                            selfHtml += '<div style="font-size: 11px; color: #6c757d; margin-bottom: 5px;">Submitted: ' + peer.created_at + '</div>';
                            selfHtml += '<div style="margin-top: 5px;">';
                            selfHtml += peer.adjectives.map(adj =>
                                '<span style="display: inline-block; margin: 2px; padding: 3px 7px; background: white; border: 1px solid ' + borderColor + '; border-radius: 10px; font-size: 11px;">' + adj + '</span>'
                            ).join('');
                            selfHtml += '</div></div>';
                        });

                        selfHtml += '</div>';
                    }

                    $('#self-adjectives-display').html(selfHtml);
                }

                function setupAdjectiveGrids() {
                    // Create adjective grids for both peers
                    const allAdjectives = [];
                    Object.entries(adjectiveData).forEach(([domain, adjectives]) => {
                        adjectives.forEach(adj => {
                            allAdjectives.push({ adjective: adj, domain: domain });
                        });
                    });

                    // Shuffle for variety
                    allAdjectives.sort(() => Math.random() - 0.5);

                    ['peer1', 'peer2'].forEach(peerId => {
                        const html = allAdjectives.map((item, index) =>
                            '<label class="test-adjective-label">' +
                            '<input type="checkbox" class="test-adjective-checkbox" data-peer="' + peerId + '" value="' + item.adjective + '">' +
                            '<span class="test-adjective-pill">' + item.adjective + '</span>' +
                            '</label>'
                        ).join('');

                        $('#' + peerId + '-adjectives').html(html);
                    });

                    // Setup event handlers
                    setupPeerFormHandlers();
                }

                function setupPeerFormHandlers() {
                    // Checkbox change handlers
                    $('.test-adjective-checkbox').change(function () {
                        const peerId = $(this).data('peer');
                        updatePeerCounter(peerId);
                    });

                    // Submit handlers
                    $('#submit-peer1').click(() => submitPeerResponse('peer1'));
                    $('#submit-peer2').click(() => submitPeerResponse('peer2'));

                    // Quick random handlers
                    $('#quick-peer1').click(() => quickSelectRandom('peer1'));
                    $('#quick-peer2').click(() => quickSelectRandom('peer2'));

                    // Strategic test handlers
                    $('#strategic-peer1').click(() => strategicSelectMix('peer1'));
                    $('#strategic-peer2').click(() => strategicSelectMix('peer2'));

                    // Results handlers
                    $('#generate-test-results').click(generateTestResults);
                    $('#clear-test-data').click(clearTestData);
                    $('#clear-all-peer-data').click(clearAllPeerData);
                }

                function updatePeerCounter(peerId) {
                    const count = $('input[data-peer="' + peerId + '"]:checked').length;
                    $('#' + peerId + '-counter').text(count + ' selected');
                    $('#submit-' + peerId).prop('disabled', count < 6 || count > 10);
                }

                function quickSelectRandom(peerId) {
                    // Clear current selection
                    $('input[data-peer="' + peerId + '"]:checked').prop('checked', false);

                    // Select 6 random adjectives
                    const checkboxes = $('input[data-peer="' + peerId + '"]').toArray();
                    const shuffled = checkboxes.sort(() => Math.random() - 0.5);
                    shuffled.slice(0, 6).forEach(cb => $(cb).prop('checked', true));

                    updatePeerCounter(peerId);
                }

                function strategicSelectMix(peerId) {
                    // Clear current selection
                    $('input[data-peer="' + peerId + '"]:checked').prop('checked', false);

                    const selfAdjectives = currentAssessmentData ? currentAssessmentData.self_adjectives : [];

                    if (!selfAdjectives || selfAdjectives.length === 0) {
                        alert('Please load assessment data first to use strategic selection.');
                        return;
                    }

                    // Strategic selection to create meaningful Johari Window results:
                    // - Include some self-adjectives (creates Open)
                    // - Include some non-self adjectives (creates Blind)
                    // - Leave some self-adjectives unselected (creates Hidden when peer doesn't select them)

                    const allCheckboxes = $('input[data-peer="' + peerId + '"]').toArray();
                    const availableAdjectives = allCheckboxes.map(cb => cb.value);

                    // Get self-adjectives that are in the available list
                    const selfAdjectivesInList = selfAdjectives.filter(adj => availableAdjectives.includes(adj));

                    // For Hidden category to work, we need peers to leave some self-adjectives unselected
                    // This creates a strategic gap where some self-adjectives don't get peer validation
                    let selfToSelect;
                    if (peerId === 'peer1') {
                        // Peer 1: Select only first 30% of self-adjectives (leaves 70% for potential Hidden)
                        selfToSelect = selfAdjectivesInList.slice(0, Math.floor(selfAdjectivesInList.length * 0.3));
                    } else {
                        // Peer 2: Select middle 30% of self-adjectives (different subset, ensuring gaps)
                        const startIndex = Math.floor(selfAdjectivesInList.length * 0.35);
                        selfToSelect = selfAdjectivesInList.slice(startIndex, startIndex + Math.floor(selfAdjectivesInList.length * 0.3));
                    }

                    // Get non-self adjectives for Blind category
                    const nonSelfAdjectives = availableAdjectives.filter(adj => !selfAdjectives.includes(adj));

                    // Select fewer non-self adjectives to focus on creating meaningful Hidden results
                    let nonSelfToSelect;
                    if (peerId === 'peer1') {
                        nonSelfToSelect = nonSelfAdjectives.sort(() => Math.random() - 0.5).slice(0, 2);
                    } else {
                        // Peer 2 selects different non-self adjectives
                        nonSelfToSelect = nonSelfAdjectives.sort(() => Math.random() - 0.5).slice(2, 4);
                    }

                    // Combine selections (aim for 6-7 total)
                    let strategicSelection = [...selfToSelect, ...nonSelfToSelect];

                    // If we don't have enough, add a few more random ones (but keep it under 8)
                    while (strategicSelection.length < 6 && strategicSelection.length < 8) {
                        const remaining = availableAdjectives.filter(adj => !strategicSelection.includes(adj));
                        if (remaining.length === 0) break;
                        const randomAdj = remaining[Math.floor(Math.random() * remaining.length)];
                        strategicSelection.push(randomAdj);
                    }

                    // Trim if too many
                    strategicSelection = strategicSelection.slice(0, 8);

                    // Apply the selection
                    strategicSelection.forEach(adjective => {
                        const checkbox = $('input[data-peer="' + peerId + '"][value="' + adjective + '"]');
                        if (checkbox.length) {
                            checkbox.prop('checked', true);
                        }
                    });

                    updatePeerCounter(peerId);

                    // Show explanation
                    const peerNum = peerId === 'peer1' ? '1' : '2';
                    const explanation = `Strategic selection for Peer ${peerNum}:\n` +
                        `• Selected ${selfToSelect.length}/${selfAdjectivesInList.length} of your self-adjectives (creates Open)\n` +
                        `• Selected ${nonSelfToSelect.length} adjectives you didn't pick (creates Blind)\n` +
                        `• Left ${selfAdjectivesInList.length - selfToSelect.length} of your self-adjectives unselected (creates Hidden when peers disagree)\n` +
                        `• Total selected: ${strategicSelection.length} adjectives`;

                    console.log(explanation);
                }

                function submitPeerResponse(peerId) {
                    const adjectives = $('input[data-peer="' + peerId + '"]:checked').map(function () {
                        return this.value;
                    }).get();

                    if (adjectives.length < 6 || adjectives.length > 10) {
                        alert('Please select 6-10 adjectives.');
                        return;
                    }

                    $('#submit-' + peerId).prop('disabled', true).text('Submitting...');

                    $.post(ajaxurl, {
                        action: 'jmi_admin_simulate_peer',
                        _ajax_nonce: nonce,
                        assessment_id: currentAssessmentId,
                        peer_id: peerId,
                        adjectives: adjectives
                    }, function (response) {
                        if (response.success) {
                            $('#submit-' + peerId).text('✅ Submitted').css('background', '#28a745');
                            alert(peerId.charAt(0).toUpperCase() + peerId.slice(1) + ' response submitted successfully!');
                        } else {
                            alert('Error: ' + (response.data || 'Could not submit response'));
                            $('#submit-' + peerId).prop('disabled', false).text('Submit ' + peerId.charAt(0).toUpperCase() + peerId.slice(1) + ' Response');
                        }
                    });
                }

                function generateTestResults() {
                    if (!currentAssessmentId) {
                        alert('Please select an assessment first.');
                        return;
                    }

                    $('#generate-test-results').prop('disabled', true).text('Generating...');

                    // First, clear any cached results to force fresh calculation
                    $.post(ajaxurl, {
                        action: 'jmi_admin_clear_cached_results',
                        _ajax_nonce: nonce,
                        assessment_id: currentAssessmentId
                    }, function () {
                        // Now generate fresh results
                        $.post(ajaxurl, {
                            action: 'miq_jmi_generate_results',
                            _ajax_nonce: '<?php echo wp_create_nonce('jmi_nonce'); ?>',
                            uuid: $('#test-assessment-id option:selected').data('uuid')
                        }, function (response) {
                            if (response.success) {
                                displayTestResults(response.data);
                            } else {
                                alert('Error generating results: ' + (response.data || 'Unknown error'));
                            }
                            $('#generate-test-results').prop('disabled', false).text('Generate Johari Window Results');
                        });
                    });
                }

                function displayTestResults(data) {
                    // Helper function to get peer count for an adjective
                    const getPeerCount = (adjective) => {
                        return data.debug_info?.peer_counts?.[adjective] || 0;
                    };

                    // Helper function to render adjectives with peer counts
                    const renderAdjectivesWithCounts = (adjectives, bgColor) => {
                        if (!adjectives || adjectives.length === 0) return '<em style="color: #999;">None identified</em>';
                        return adjectives.map(adj => {
                            const count = getPeerCount(adj);
                            const countBadge = count > 0 ? ' <span style="background: rgba(0,0,0,0.2); padding: 2px 6px; border-radius: 10px; font-size: 9px;">×' + count + '</span>' : '';
                            return '<span style="display: inline-block; margin: 3px; padding: 4px 8px; background: ' + bgColor + '; border-radius: 12px; font-size: 11px;">' + adj + countBadge + '</span>';
                        }).join('');
                    };

                    const html =
                        '<h5 style="margin-top: 20px; color: #1a202c;">🎯 Complete Johari Window Results</h5>' +
                        '<div style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin: 15px 0; border-left: 4px solid #0ea5e9;">' +
                        '<p style="margin: 0; color: #64748b; font-size: 13px;"><strong>Results based on:</strong> ' + (data.debug_info?.self_adjectives?.length || 0) + ' self-adjectives + ' + (data.debug_info?.total_peers || 0) + ' peer responses = ' + ((data.open?.length || 0) + (data.blind?.length || 0) + (data.hidden?.length || 0)) + ' total categorized adjectives</p>' +
                        '</div>' +
                        '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-top: 10px;">' +
                        '<div style="background: #d4edda; padding: 15px; border-radius: 8px; border: 2px solid #28a745;">' +
                        '<h6 style="margin: 0 0 8px 0; color: #155724; font-size: 14px;">🌟 Open Area (' + (data.open?.length || 0) + ')</h6>' +
                        '<p style="margin: 0 0 10px 0; font-size: 11px; color: #155724;"><em>Recognized by both you and others</em></p>' +
                        '<div>' + renderAdjectivesWithCounts(data.open, '#28a745') + '</div>' +
                        '</div>' +
                        '<div style="background: #fff3cd; padding: 15px; border-radius: 8px; border: 2px solid #ffc107;">' +
                        '<h6 style="margin: 0 0 8px 0; color: #856404; font-size: 14px;">👁️ Blind Spot (' + (data.blind?.length || 0) + ')</h6>' +
                        '<p style="margin: 0 0 10px 0; font-size: 11px; color: #856404;"><em>Others see in you, you don\'t recognize</em></p>' +
                        '<div>' + renderAdjectivesWithCounts(data.blind, '#ffc107') + '</div>' +
                        '</div>' +
                        '<div style="background: #e3f2fd; padding: 15px; border-radius: 8px; border: 2px solid #2196f3;">' +
                        '<h6 style="margin: 0 0 8px 0; color: #0c5460; font-size: 14px;">🔐 Hidden Area (' + (data.hidden?.length || 0) + ')</h6>' +
                        '<p style="margin: 0 0 10px 0; font-size: 11px; color: #0c5460;"><em>You see in yourself, others don\'t recognize</em></p>' +
                        '<div>' + renderAdjectivesWithCounts(data.hidden, '#2196f3') + '</div>' +
                        '</div>' +
                        '<div style="background: #f8f9fa; padding: 15px; border-radius: 8px; border: 2px solid #6c757d;">' +
                        '<h6 style="margin: 0 0 8px 0; color: #495057; font-size: 14px;">❓ Unknown Area (' + (data.unknown?.length || 0) + ')</h6>' +
                        '<p style="margin: 0 0 10px 0; font-size: 11px; color: #495057;"><em>Neither you nor others identified (showing first 15)</em></p>' +
                        '<div>' + renderAdjectivesWithCounts(data.unknown?.slice(0, 15), '#6c757d') + '</div>' +
                        (data.unknown?.length > 15 ? '<p style="margin: 8px 0 0 0; font-size: 11px; color: #6c757d;">... and ' + (data.unknown.length - 15) + ' more</p>' : '') +
                        '</div>' +
                        '</div>' +
                        (data.debug_info ?
                            '<details open style="margin-top: 20px; padding: 15px; background: #f8f9fa; border-radius: 8px; border: 1px solid #dee2e6;">' +
                            '<summary style="cursor: pointer; font-weight: bold; color: #495057; font-size: 14px;">🔍 Detailed Breakdown</summary>' +
                            '<div style="margin-top: 15px;">' +
                            '<div style="background: white; padding: 12px; border-radius: 6px; margin-bottom: 12px; border-left: 3px solid #17a2b8;">' +
                            '<h6 style="margin: 0 0 8px 0; color: #17a2b8;">📝 Your Self-Assessment (' + (data.debug_info.self_adjectives?.length || 0) + ' adjectives)</h6>' +
                            '<div>' + (data.debug_info.self_adjectives || []).map(adj =>
                                '<span style="display: inline-block; margin: 2px; padding: 4px 8px; background: #17a2b8; color: white; border-radius: 12px; font-size: 11px;">' + adj + '</span>'
                            ).join('') + '</div>' +
                            '</div>' +
                            '<div style="background: white; padding: 12px; border-radius: 6px; margin-bottom: 12px; border-left: 3px solid #6c757d;">' +
                            '<h6 style="margin: 0 0 8px 0; color: #6c757d;">👥 All Peer Selections (' + (data.debug_info.all_peer_adjectives?.length || 0) + ' unique adjectives from ' + (data.debug_info.total_peers || 0) + ' peers)</h6>' +
                            '<div>' + (data.debug_info.all_peer_adjectives || []).map(adj => {
                                const count = data.debug_info.peer_counts?.[adj] || 0;
                                return '<span style="display: inline-block; margin: 2px; padding: 4px 8px; background: #6c757d; color: white; border-radius: 12px; font-size: 11px;">' + adj + ' <span style="background: rgba(255,255,255,0.3); padding: 1px 5px; border-radius: 8px; margin-left: 3px;">×' + count + '</span></span>';
                            }).join('') + '</div>' +
                            '</div>' +
                            (data.debug_info.peer_responses ?
                                '<div style="background: white; padding: 12px; border-radius: 6px; border-left: 3px solid #28a745;">' +
                                '<h6 style="margin: 0 0 12px 0; color: #28a745;">🔬 Individual Peer Responses</h6>' +
                                data.debug_info.peer_responses.map((p, idx) => {
                                    const peerLabel = [999001, 999002].includes(p.peer_user_id) ? '🧪 Test Peer ' + (p.peer_user_id === 999001 ? '1' : '2') : '👤 Peer ' + (idx + 1);
                                    const borderColor = [999001, 999002].includes(p.peer_user_id) ? '#fd7e14' : '#28a745';
                                    return '<div style="margin-bottom: 10px; padding: 8px; background: #f8f9fa; border-radius: 4px; border-left: 2px solid ' + borderColor + ';">' +
                                        '<div style="font-weight: bold; font-size: 11px; color: ' + borderColor + '; margin-bottom: 4px;">' + peerLabel + ' (User ID: ' + p.peer_user_id + ') — ' + p.adjectives.length + ' adjectives</div>' +
                                        '<div>' + p.adjectives.map(adj => {
                                            // Determine category for color coding
                                            let color = '#6c757d';
                                            if ((data.open || []).includes(adj)) color = '#28a745';
                                            else if ((data.blind || []).includes(adj)) color = '#ffc107';
                                            else if ((data.hidden || []).includes(adj)) color = '#2196f3';
                                            return '<span style="display: inline-block; margin: 2px; padding: 3px 6px; background: ' + color + '; color: white; border-radius: 10px; font-size: 10px;">' + adj + '</span>';
                                        }).join('') + '</div>' +
                                        '</div>';
                                }).join('') +
                                '</div>' : '') +
                            '</div>' +
                            '</details>' : '') +
                        '<div style="margin-top: 15px; padding: 12px; background: #e8f5e9; border-radius: 6px; border-left: 3px solid #28a745;">' +
                        '<p style="margin: 0; font-size: 13px;"><strong>✅ Test Results Generated!</strong> ' +
                        '<a href="/johari-x-mi-assessment/" target="_blank" style="color: #0ea5e9;">View on full results page →</a></p>' +
                        '</div>';

                    $('#test-results-output').html(html);
                }

                function clearTestData() {
                    if (!confirm('Clear all test data for this assessment? This will remove simulated peer responses.')) {
                        return;
                    }

                    $.post(ajaxurl, {
                        action: 'jmi_admin_clear_test_data',
                        _ajax_nonce: nonce,
                        assessment_id: currentAssessmentId
                    }, function (response) {
                        if (response.success) {
                            alert('Test data cleared successfully!');
                            location.reload();
                        } else {
                            alert('Error: ' + (response.data || 'Could not clear test data'));
                        }
                    });
                }

                function clearAllPeerData() {
                    if (!confirm('⚠️ WARNING: This will delete ALL peer responses for this assessment (including real ones). Are you sure?')) {
                        return;
                    }

                    if (!confirm('This cannot be undone. Really delete ALL peer data?')) {
                        return;
                    }

                    $.post(ajaxurl, {
                        action: 'jmi_admin_clear_all_peer_data',
                        _ajax_nonce: nonce,
                        assessment_id: currentAssessmentId
                    }, function (response) {
                        if (response.success) {
                            alert('All peer data cleared successfully!');
                            location.reload();
                        } else {
                            alert('Error: ' + (response.data || 'Could not clear peer data'));
                        }
                    });
                }

                // MI Test Data handlers
                $('#add-mi-test-data').click(function () {
                    if (!currentAssessmentData || !currentAssessmentData.user_id) {
                        alert('Please select an assessment with a valid user first.');
                        return;
                    }

                    const btn = $(this);
                    btn.prop('disabled', true).text('Adding MI Test Data...');

                    $.post(ajaxurl, {
                        action: 'jmi_admin_add_mi_test_data',
                        _ajax_nonce: nonce,
                        user_id: currentAssessmentData.user_id
                    }, function (response) {
                        if (response.success) {
                            $('#mi-test-results').html('<span style="color: #155724;">✅ ' + response.data.message + '<br>Top 3 MI strengths: ' + response.data.top3.join(', ') + '</span>');
                        } else {
                            $('#mi-test-results').html('<span style="color: #721c24;">❌ Error: ' + (response.data || 'Unknown error') + '</span>');
                        }
                        btn.prop('disabled', false).text('Add Sample MI Data for Selected User');
                    });
                });

                $('#clear-mi-test-data').click(function () {
                    if (!currentAssessmentData || !currentAssessmentData.user_id) {
                        alert('Please select an assessment with a valid user first.');
                        return;
                    }

                    const btn = $(this);
                    btn.prop('disabled', true).text('Clearing MI Data...');

                    $.post(ajaxurl, {
                        action: 'jmi_admin_clear_mi_test_data',
                        _ajax_nonce: nonce,
                        user_id: currentAssessmentData.user_id
                    }, function (response) {
                        if (response.success) {
                            $('#mi-test-results').html('<span style="color: #155724;">✅ ' + response.data.message + '</span>');
                        } else {
                            $('#mi-test-results').html('<span style="color: #721c24;">❌ Error: ' + (response.data || 'Unknown error') + '</span>');
                        }
                        btn.prop('disabled', false).text('Clear MI Data');
                    });
                });
            });
        </script>
        <?php
    }

    // --- AJAX endpoints ---
    public function ajax_save_self()
    {
        check_ajax_referer('jmi_nonce');

        $adjectives_raw = isset($_POST['adjectives']) ? wp_unslash($_POST['adjectives']) : '';
        $adjectives = json_decode($adjectives_raw, true);

        // Validate adjectives
        if (!is_array($adjectives) || count($adjectives) < 6 || count($adjectives) > 10) {
            wp_send_json_error('Please select 6-10 adjectives.');
        }

        // Load valid adjectives to verify selection
        $adjective_data = require __DIR__ . '/jmi-adjectives.php';
        $all_valid = $adjective_data['all_adjectives'];

        foreach ($adjectives as $adj) {
            if (!in_array($adj, $all_valid)) {
                wp_send_json_error('Invalid adjective selected.');
            }
        }

        global $wpdb;
        $user_id = is_user_logged_in() ? get_current_user_id() : null;
        $uuid = $this->generate_uuid();
        $now = current_time('mysql');

        // Insert or update self-assessment
        $self_table = $this->table(self::TABLE_SELF);
        $result = $wpdb->insert($self_table, [
            'user_id' => $user_id,
            'uuid' => $uuid,
            'adjectives' => json_encode($adjectives),
            'created_at' => $now
        ]);

        if (!$result) {
            wp_send_json_error('Could not save assessment.');
        }

        $self_id = $wpdb->insert_id;

        // Create peer link
        $link_uuid = $this->generate_uuid();
        $expires_at = date('Y-m-d H:i:s', strtotime('+30 days'));

        $links_table = $this->table(self::TABLE_LINKS);
        $wpdb->insert($links_table, [
            'self_id' => $self_id,
            'uuid' => $link_uuid,
            'created_at' => $now,
            'expires_at' => $expires_at,
            'max_peers' => 5,
            'visited' => 0
        ]);

        // Save to user meta if logged in
        if ($user_id) {
            update_user_meta($user_id, 'jmi_self_uuid', $uuid);
        }

        // Build a public share URL that always points to the Johari × MI page
        $assess_url = $this->_find_page_by_shortcode('johari_mi_quiz');
        if (!$assess_url) {
            $assess_url = $this->_find_page_by_shortcode('johari-mi-quiz');
        }
        if (!$assess_url) {
            $assess_url = home_url('/');
        }
        $share_url = add_query_arg('jmi', $link_uuid, $assess_url);

        wp_send_json_success([
            'uuid' => $uuid,
            'share_url' => $share_url,
            'self_id' => $self_id
        ]);
    }
    public function ajax_peer_submit()
    {
        check_ajax_referer('jmi_nonce');

        // Require login for peer feedback
        if (!is_user_logged_in()) {
            wp_send_json_error('You must be logged in to provide peer feedback.');
        }

        $uuid = isset($_POST['uuid']) ? sanitize_text_field($_POST['uuid']) : '';
        $adjectives_raw = isset($_POST['adjectives']) ? wp_unslash($_POST['adjectives']) : '';
        $adjectives = json_decode($adjectives_raw, true);

        // Validate inputs
        if (empty($uuid)) {
            wp_send_json_error('Invalid link.');
        }

        if (!is_array($adjectives) || count($adjectives) < 6 || count($adjectives) > 10) {
            wp_send_json_error('Please select 6-10 adjectives.');
        }

        // Load valid adjectives
        $adjective_data = require __DIR__ . '/jmi-adjectives.php';
        $all_valid = $adjective_data['all_adjectives'];

        foreach ($adjectives as $adj) {
            if (!in_array($adj, $all_valid)) {
                wp_send_json_error('Invalid adjective selected.');
            }
        }

        global $wpdb;

        // Find the peer link
        $links_table = $this->table(self::TABLE_LINKS);
        $link = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM `$links_table` WHERE uuid = %s AND expires_at > NOW()",
            $uuid
        ));

        if (!$link) {
            wp_send_json_error('This link has expired or is invalid.');
        }

        // Check if max peers reached
        if ($link->visited >= $link->max_peers) {
            wp_send_json_error('Maximum number of peer assessments reached.');
        }

        // Check for duplicate submissions from same user
        $peer_user_id = get_current_user_id();
        $feedback_table = $this->table(self::TABLE_FEEDBACK);
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `$feedback_table` WHERE self_id = %d AND peer_user_id = %d",
            $link->self_id,
            $peer_user_id
        ));

        if ($existing > 0) {
            wp_send_json_error('You have already submitted feedback for this assessment.');
        }

        // Save peer feedback
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $result = $wpdb->insert($feedback_table, [
            'self_id' => $link->self_id,
            'link_id' => $link->id,
            'peer_user_id' => $peer_user_id,
            'adjectives' => json_encode($adjectives),
            'created_at' => current_time('mysql'),
            'ip' => $ip
        ]);

        if (!$result) {
            wp_send_json_error('Could not save feedback.');
        }

        // Update visited count
        $wpdb->update(
            $links_table,
            ['visited' => $link->visited + 1],
            ['id' => $link->id],
            ['%d'],
            ['%d']
        );

        // Check if we've reached the minimum threshold (2 peers)
        $new_count = $link->visited + 1;
        if ($new_count >= 2) {
            $this->maybe_notify_owner($link->self_id, $new_count);

            // Auto-generate results when threshold is reached
            $self_table = $this->table(self::TABLE_SELF);
            $self_row = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM `$self_table` WHERE id = %d",
                $link->self_id
            ));

            if ($self_row && $self_row->user_id) {
                // Check if results haven't been generated yet
                $existing_results = get_user_meta($self_row->user_id, 'johari_mi_profile', true);
                if (empty($existing_results)) {
                    // Generate and save results
                    $results = $this->calculate_johari_window($self_row->id);
                    if ($results) {
                        update_user_meta($self_row->user_id, 'johari_mi_profile', $results);

                        // Trigger centralized completion check
                        if (class_exists('MC_Funnel')) {
                            MC_Funnel::check_completion_and_notify($self_row->user_id);
                        }
                    }
                }
            }
        }

        wp_send_json_success([
            'message' => 'Feedback submitted successfully',
            'peer_count' => $new_count
        ]);
    }
    public function ajax_generate_results()
    {
        check_ajax_referer('jmi_nonce');

        $uuid = isset($_POST['uuid']) ? sanitize_text_field($_POST['uuid']) : '';

        if (empty($uuid)) {
            wp_send_json_error('Invalid request.');
        }

        global $wpdb;

        // Find self-assessment by UUID (could be self UUID or link UUID)
        $self_table = $this->table(self::TABLE_SELF);
        $links_table = $this->table(self::TABLE_LINKS);

        $self_row = $wpdb->get_row($wpdb->prepare(
            "SELECT s.* FROM `$self_table` s WHERE s.uuid = %s",
            $uuid
        ));

        // If not found, try finding by link UUID
        if (!$self_row) {
            $link_row = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM `$links_table` WHERE uuid = %s",
                $uuid
            ));
            if ($link_row) {
                $self_row = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM `$self_table` WHERE id = %d",
                    $link_row->self_id
                ));
            }
        }

        if (!$self_row) {
            wp_send_json_error('Assessment not found.');
        }

        // Get peer feedback count
        $feedback_table = $this->table(self::TABLE_FEEDBACK);
        $peer_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `$feedback_table` WHERE self_id = %d",
            $self_row->id
        ));

        if ($peer_count < 2) {
            wp_send_json_error('Not enough peer feedback yet. Need at least 2 peers.');
        }

        // Generate or retrieve cached results
        $results = $this->calculate_johari_window($self_row->id);

        if (!$results) {
            wp_send_json_error('Could not generate results.');
        }

        // Save to user profile if user is logged in
        if ($self_row->user_id) {
            update_user_meta($self_row->user_id, 'johari_mi_profile', $results);

            // Trigger centralized completion check
            if (class_exists('MC_Funnel')) {
                MC_Funnel::check_completion_and_notify($self_row->user_id);
            }
        }

        wp_send_json_success($results);
    }


    public function ajax_get_peer_count()
    {
        check_ajax_referer('jmi_nonce');

        $uuid = isset($_POST['uuid']) ? sanitize_text_field($_POST['uuid']) : '';

        if (empty($uuid)) {
            wp_send_json_error('Invalid request.');
        }

        global $wpdb;

        // Find self-assessment by UUID (could be self UUID or link UUID)
        $self_table = $this->table(self::TABLE_SELF);
        $links_table = $this->table(self::TABLE_LINKS);

        $self_row = $wpdb->get_row($wpdb->prepare(
            "SELECT s.* FROM `$self_table` s WHERE s.uuid = %s",
            $uuid
        ));

        // If not found, try finding by link UUID
        if (!$self_row) {
            $link_row = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM `$links_table` WHERE uuid = %s",
                $uuid
            ));
            if ($link_row) {
                $self_row = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM `$self_table` WHERE id = %d",
                    $link_row->self_id
                ));
            }
        }

        if (!$self_row) {
            wp_send_json_error('Assessment not found.');
        }

        // Get peer feedback count
        $feedback_table = $this->table(self::TABLE_FEEDBACK);
        $peer_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `$feedback_table` WHERE self_id = %d",
            $self_row->id
        ));

        // Check if ready for results (2+ peers)
        $ready_for_results = $peer_count >= 2;

        wp_send_json_success([
            'peer_count' => intval($peer_count),
            'ready_for_results' => $ready_for_results,
            'needed_count' => 2,
            'remaining_count' => max(0, 2 - intval($peer_count))
        ]);
    }

    public function ajax_generate_pdf()
    {
        check_ajax_referer('jmi_nonce');

        if (!class_exists('Dompdf\\Dompdf')) {
            wp_send_json_error('PDF library is not available.');
        }

        $results_html = isset($_POST['results_html']) ? wp_kses_post(wp_unslash($_POST['results_html'])) : '';
        if (empty($results_html)) {
            wp_send_json_error('No results data provided.');
        }

        // Create branding header
        $branding_html = '<div style="text-align:center; padding: 20px 0; border-bottom: 1px solid #ddd;">';
        $branding_html .= '<h1 style="color: #1a202c; margin: 0;">Johari × MI Assessment Results</h1>';
        $branding_html .= '<p style="margin: 0; color: #666;">Generated on ' . date('F j, Y') . '</p>';
        $branding_html .= '</div>';

        $full_html = '<!DOCTYPE html><html><head><meta charset="utf-8">';

        // Embed CSS
        $css_path = __DIR__ . '/css/johari-mi-quiz.css';
        if (file_exists($css_path)) {
            $css = file_get_contents($css_path);
            // Remove imports and problematic rules for PDF
            $css = preg_replace('/@import[^;]+;/', '', $css);
            $css = preg_replace('/@media[^{]*{[^{}]*({[^{}]*}[^{}]*)*}/', '', $css);
            $full_html .= '<style>' . $css . '</style>';
        }

        $full_html .= '</head><body style="padding: 1em; font-family: sans-serif;">';
        $full_html .= $branding_html . $results_html;
        $full_html .= '</body></html>';

        $options = new \Dompdf\Options();
        $options->set('isRemoteEnabled', true);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml($full_html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $dompdf->stream(
            'johari-mi-results-' . date('Y-m-d') . '.pdf',
            ['Attachment' => true]
        );
        exit;
    }

    public function ajax_export_subs()
    {
        if (!current_user_can('manage_options'))
            wp_die('No permission');
        check_admin_referer('jmi_admin_nonce');

        global $wpdb;
        $self_table = $this->table(self::TABLE_SELF);
        $feedback_table = $this->table(self::TABLE_FEEDBACK);

        $query = "
            SELECT s.id, s.created_at, s.user_id, u.user_email, u.display_name,
                   COUNT(f.id) as peer_count, s.adjectives
            FROM `$self_table` s
            LEFT JOIN {$wpdb->users} u ON s.user_id = u.ID
            LEFT JOIN `$feedback_table` f ON s.id = f.self_id
            GROUP BY s.id
            ORDER BY s.created_at DESC
        ";

        $rows = $wpdb->get_results($query, ARRAY_A);

        $filename = 'johari-mi-assessments-' . date('Ymd-His') . '.csv';
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);

        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM
        fputcsv($out, ['ID', 'Created At', 'User ID', 'Email', 'Display Name', 'Peer Count', 'Status', 'Self Adjectives']);

        foreach ($rows as $r) {
            $status = (int) $r['peer_count'] >= 2 ? 'Complete' : 'Awaiting Peers';
            $adjectives = json_decode($r['adjectives'], true);
            $adjectives_str = is_array($adjectives) ? implode(', ', $adjectives) : '';

            fputcsv($out, [
                $r['id'],
                $r['created_at'],
                $r['user_id'],
                $r['user_email'],
                $r['display_name'],
                $r['peer_count'],
                $status,
                $adjectives_str
            ]);
        }

        fclose($out);
        exit;
    }

    public function ajax_delete_subs()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('No permission');
        }
        check_ajax_referer('jmi_admin_nonce');

        $ids_raw = isset($_POST['ids']) ? wp_unslash($_POST['ids']) : '';
        if (empty($ids_raw)) {
            wp_send_json_error('No IDs provided.');
        }

        $ids = array_filter(array_map('intval', explode(',', $ids_raw)));
        if (empty($ids)) {
            wp_send_json_error('No valid IDs provided.');
        }

        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        // Delete from all related tables
        $deleted = 0;
        $deleted += $wpdb->query($wpdb->prepare("DELETE FROM `" . $this->table(self::TABLE_FEEDBACK) . "` WHERE self_id IN ($placeholders)", $ids));
        $deleted += $wpdb->query($wpdb->prepare("DELETE FROM `" . $this->table(self::TABLE_LINKS) . "` WHERE self_id IN ($placeholders)", $ids));
        $deleted += $wpdb->query($wpdb->prepare("DELETE FROM `" . $this->table(self::TABLE_AGG) . "` WHERE self_id IN ($placeholders)", $ids));
        $deleted += $wpdb->query($wpdb->prepare("DELETE FROM `" . $this->table(self::TABLE_SELF) . "` WHERE id IN ($placeholders)", $ids));

        wp_send_json_success(['deleted' => $deleted]);
    }

    public function ajax_view_user_results()
    {
        error_log('JMI Admin: ajax_view_user_results called');
        if (!current_user_can('manage_options')) {
            error_log('JMI Admin: No permission for user');
            wp_send_json_error('No permission');
        }

        try {
            check_ajax_referer('jmi_admin_nonce');
        } catch (Exception $e) {
            error_log('JMI Admin: Nonce check failed: ' . $e->getMessage());
            wp_send_json_error('Security check failed');
        }

        $assessment_id = intval($_POST['assessment_id']);
        error_log('JMI Admin: Received assessment_id: ' . $assessment_id);
        if (!$assessment_id) {
            error_log('JMI Admin: Invalid assessment ID');
            wp_send_json_error('Invalid assessment ID');
        }

        global $wpdb;

        // Get self assessment data
        $self_table = $this->table(self::TABLE_SELF);
        $self_row = $wpdb->get_row($wpdb->prepare(
            "SELECT s.*, u.display_name, u.user_email 
             FROM `$self_table` s 
             LEFT JOIN {$wpdb->users} u ON s.user_id = u.ID 
             WHERE s.id = %d",
            $assessment_id
        ));

        if (!$self_row) {
            wp_send_json_error('Assessment not found');
        }

        // Get peer feedback count
        $feedback_table = $this->table(self::TABLE_FEEDBACK);
        $peer_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `$feedback_table` WHERE self_id = %d",
            $assessment_id
        ));

        // Parse self-selected adjectives
        $self_adjectives = json_decode($self_row->adjectives, true) ?: [];

        // Check if assessment has enough peers for results
        if ($peer_count < 2) {
            $html = '<div style="padding: 20px; background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 5px;">';
            $html .= '<h4 style="color: #856404; margin-top: 0;">⏳ Results Not Ready</h4>';
            $html .= '<p><strong>User:</strong> ' . esc_html($self_row->display_name ?: 'Guest') . '</p>';
            $html .= '<p><strong>Email:</strong> ' . esc_html($self_row->user_email ?: 'N/A') . '</p>';
            $html .= '<p><strong>Assessment Date:</strong> ' . esc_html($self_row->created_at) . '</p>';
            $html .= '<p><strong>Peer Responses:</strong> ' . $peer_count . ' (need 2 minimum)</p>';
            $html .= '<p><strong>Self-Selected Adjectives:</strong></p>';
            $html .= '<div style="margin: 10px 0;">';
            foreach ($self_adjectives as $adj) {
                $html .= '<span style="display: inline-block; margin: 3px; padding: 4px 8px; background: #e9ecef; border-radius: 12px; font-size: 12px;">' . esc_html($adj) . '</span>';
            }
            $html .= '</div>';
            $html .= '<p style="color: #856404;">This assessment needs at least 2 peer responses to generate Johari Window results.</p>';
            $html .= '</div>';

            wp_send_json_success(['html' => $html]);
            return;
        }

        // Generate the Johari results
        try {
            $results = $this->calculate_johari_window($self_row->id);

            // Create HTML display of results
            $html = '<div style="background: white; padding: 20px; border-radius: 8px; border: 1px solid #e2e8f0;">';

            // User info header
            $html .= '<div style="margin-bottom: 20px; padding-bottom: 15px; border-bottom: 1px solid #e2e8f0;">';
            $html .= '<h4 style="color: #1a202c; margin-top: 0;">Assessment Results</h4>';
            $html .= '<p><strong>User:</strong> ' . esc_html($self_row->display_name ?: 'Guest') . '</p>';
            $html .= '<p><strong>Email:</strong> ' . esc_html($self_row->user_email ?: 'N/A') . '</p>';
            $html .= '<p><strong>Assessment Date:</strong> ' . esc_html($self_row->created_at) . '</p>';
            $html .= '<p><strong>Peer Responses:</strong> ' . $peer_count . '</p>';
            $html .= '</div>';

            // Johari Window results
            $html .= '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 20px;">';

            // Open quadrant (green)
            $html .= '<div style="padding: 15px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 8px;">';
            $html .= '<h5 style="color: #155724; margin-top: 0;">🔓 Open (' . count($results['open']) . ')</h5>';
            $html .= '<p style="font-size: 12px; color: #155724; margin-bottom: 10px;">Known to self and others</p>';
            foreach ($results['open'] as $adj) {
                $html .= '<span style="display: inline-block; margin: 2px; padding: 4px 8px; background: #155724; color: white; border-radius: 12px; font-size: 11px;">' . esc_html($adj) . '</span>';
            }
            $html .= '</div>';

            // Blind quadrant (orange)
            $html .= '<div style="padding: 15px; background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 8px;">';
            $html .= '<h5 style="color: #856404; margin-top: 0;">👁️ Blind (' . count($results['blind']) . ')</h5>';
            $html .= '<p style="font-size: 12px; color: #856404; margin-bottom: 10px;">Known to others, unknown to self</p>';
            foreach ($results['blind'] as $adj) {
                $html .= '<span style="display: inline-block; margin: 2px; padding: 4px 8px; background: #856404; color: white; border-radius: 12px; font-size: 11px;">' . esc_html($adj) . '</span>';
            }
            $html .= '</div>';

            // Hidden quadrant (blue)
            $html .= '<div style="padding: 15px; background: #d1ecf1; border: 1px solid #bee5eb; border-radius: 8px;">';
            $html .= '<h5 style="color: #0c5460; margin-top: 0;">🔒 Hidden (' . count($results['hidden']) . ')</h5>';
            $html .= '<p style="font-size: 12px; color: #0c5460; margin-bottom: 10px;">Known to self, unknown to others</p>';
            foreach ($results['hidden'] as $adj) {
                $html .= '<span style="display: inline-block; margin: 2px; padding: 4px 8px; background: #0c5460; color: white; border-radius: 12px; font-size: 11px;">' . esc_html($adj) . '</span>';
            }
            $html .= '</div>';

            // Unknown quadrant (gray)
            $html .= '<div style="padding: 15px; background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 8px;">';
            $html .= '<h5 style="color: #495057; margin-top: 0;">❓ Unknown (' . count($results['unknown']) . ')</h5>';
            $html .= '<p style="font-size: 12px; color: #495057; margin-bottom: 10px;">Unknown to both self and others</p>';
            $unknown_display = array_slice($results['unknown'], 0, 20); // Show first 20 to avoid overwhelming display
            foreach ($unknown_display as $adj) {
                $html .= '<span style="display: inline-block; margin: 2px; padding: 4px 8px; background: #6c757d; color: white; border-radius: 12px; font-size: 11px;">' . esc_html($adj) . '</span>';
            }
            if (count($results['unknown']) > 20) {
                $html .= '<p style="font-size: 12px; color: #6c757d; margin-top: 10px;">... and ' . (count($results['unknown']) - 20) . ' more</p>';
            }
            $html .= '</div>';

            $html .= '</div>'; // Close grid

            // MI Integration (if available)
            if (!empty($results['mi_profile'])) {
                $mi = $results['mi_profile'];
                $html .= '<div style="margin-top: 20px; padding: 15px; background: #e8f5e8; border-radius: 8px; border-left: 4px solid #28a745;">';
                $html .= '<h5 style="color: #155724; margin-top: 0;">🧠 Multiple Intelligences Integration</h5>';
                $html .= '<p><strong>Top 3 MI Strengths:</strong> ' . implode(', ', $mi['top3_names']) . '</p>';
                if (!empty($mi['assessment_date'])) {
                    $html .= '<p><strong>MI Assessment Date:</strong> ' . esc_html($mi['assessment_date']) . '</p>';
                }
                $html .= '</div>';
            }

            // Add detailed peer responses section for admin debugging
            $html .= $this->generate_admin_peer_details($assessment_id, $self_adjectives, $results);

            $html .= '</div>';

            wp_send_json_success(['html' => $html]);

        } catch (Exception $e) {
            wp_send_json_error('Error generating results: ' . $e->getMessage());
        }
    }

    /**
     * Generate detailed peer response breakdown for admin testing
     */
    private function generate_admin_peer_details($assessment_id, $self_adjectives, $johari_results)
    {
        global $wpdb;

        $feedback_table = $this->table(self::TABLE_FEEDBACK);

        // Get all peer responses for this assessment
        $peer_responses = $wpdb->get_results($wpdb->prepare(
            "SELECT f.*, u.display_name, u.user_email 
             FROM `$feedback_table` f 
             LEFT JOIN {$wpdb->users} u ON f.peer_user_id = u.ID 
             WHERE f.self_id = %d 
             ORDER BY f.created_at ASC",
            $assessment_id
        ));

        if (empty($peer_responses)) {
            return '<div style="margin-top: 30px; padding: 15px; background: #fff3cd; border-radius: 8px; border-left: 4px solid #ffc107;">' .
                '<h5 style="color: #856404; margin-top: 0;">⚠️ No Peer Responses</h5>' .
                '<p>This assessment has no peer responses yet.</p></div>';
        }

        $html = '<div style="margin-top: 30px; padding: 20px; background: #f8f9fa; border-radius: 8px; border-left: 4px solid #6c757d;">';
        $html .= '<h4 style="color: #495057; margin-top: 0; margin-bottom: 20px;">🔍 Admin Debug: Peer Response Details</h4>';

        // Self-selected adjectives summary
        $html .= '<div style="margin-bottom: 20px; padding: 15px; background: white; border-radius: 6px; border-left: 3px solid #17a2b8;">';
        $html .= '<h5 style="color: #17a2b8; margin-top: 0;">📝 Self-Selected Adjectives (' . count($self_adjectives) . ')</h5>';
        foreach ($self_adjectives as $adj) {
            $html .= '<span style="display: inline-block; margin: 2px; padding: 4px 8px; background: #17a2b8; color: white; border-radius: 12px; font-size: 11px;">' . esc_html($adj) . '</span>';
        }
        $html .= '</div>';

        // Individual peer responses
        foreach ($peer_responses as $index => $peer) {
            $peer_adjectives = json_decode($peer->adjectives, true) ?: [];
            $peer_name = $peer->display_name ?: 'Guest';
            $peer_email = $peer->user_email ?: 'N/A';

            // Determine if this is a test peer (user IDs 999001, 999002)
            $is_test_peer = in_array($peer->peer_user_id, [999001, 999002]);
            $peer_label = $is_test_peer ? '🧪 Test Peer ' . ($peer->peer_user_id == 999001 ? '1' : '2') : '👤 Peer ' . ($index + 1);
            $border_color = $is_test_peer ? '#fd7e14' : '#28a745';

            $html .= '<div style="margin-bottom: 15px; padding: 15px; background: white; border-radius: 6px; border-left: 3px solid ' . $border_color . ';">';
            $html .= '<h5 style="color: ' . $border_color . '; margin-top: 0; margin-bottom: 10px;">' . $peer_label . '</h5>';
            $html .= '<p style="font-size: 12px; color: #6c757d; margin-bottom: 10px;">';
            $html .= '<strong>Name:</strong> ' . esc_html($peer_name) . ' | ';
            $html .= '<strong>Email:</strong> ' . esc_html($peer_email) . ' | ';
            $html .= '<strong>User ID:</strong> ' . $peer->peer_user_id . ' | ';
            $html .= '<strong>Date:</strong> ' . $peer->created_at;
            $html .= '</p>';

            // Show peer's selected adjectives with categorization
            $html .= '<div style="margin-bottom: 10px;">';
            $html .= '<strong>Selected Adjectives (' . count($peer_adjectives) . '):</strong><br>';

            foreach ($peer_adjectives as $adj) {
                // Determine the Johari category for this adjective
                $category = 'unknown';
                $category_color = '#6c757d';
                $category_label = 'Unknown';

                if (in_array($adj, $johari_results['open'])) {
                    $category = 'open';
                    $category_color = '#28a745';
                    $category_label = 'Open';
                } elseif (in_array($adj, $johari_results['blind'])) {
                    $category = 'blind';
                    $category_color = '#ffc107';
                    $category_label = 'Blind';
                } elseif (in_array($adj, $johari_results['hidden'])) {
                    $category = 'hidden';
                    $category_color = '#17a2b8';
                    $category_label = 'Hidden';
                }

                $html .= '<span style="display: inline-block; margin: 2px; padding: 4px 8px; background: ' . $category_color . '; color: white; border-radius: 12px; font-size: 10px;" title="Category: ' . $category_label . '">' . esc_html($adj) . '</span>';
            }
            $html .= '</div>';

            // Show overlap with self-selected adjectives
            $overlap = array_intersect($self_adjectives, $peer_adjectives);
            $html .= '<div style="font-size: 11px; color: #495057;">';
            $html .= '<strong>Overlap with Self:</strong> ' . count($overlap) . ' adjectives';
            if (!empty($overlap)) {
                $html .= ' (' . implode(', ', $overlap) . ')';
            }
            $html .= '</div>';

            $html .= '</div>';
        }

        // Summary statistics
        $html .= '<div style="margin-top: 20px; padding: 15px; background: white; border-radius: 6px; border-left: 3px solid #6f42c1;">';
        $html .= '<h5 style="color: #6f42c1; margin-top: 0;">📊 Processing Summary</h5>';
        $html .= '<ul style="margin: 0; padding-left: 20px; font-size: 13px;">';
        $html .= '<li><strong>Total Peers:</strong> ' . count($peer_responses) . '</li>';

        // Calculate unique adjectives mentioned by peers
        $all_peer_adjectives = [];
        foreach ($peer_responses as $peer) {
            $peer_adjectives = json_decode($peer->adjectives, true) ?: [];
            $all_peer_adjectives = array_merge($all_peer_adjectives, $peer_adjectives);
        }
        $unique_peer_adjectives = array_unique($all_peer_adjectives);

        $html .= '<li><strong>Unique Adjectives from Peers:</strong> ' . count($unique_peer_adjectives) . '</li>';
        $html .= '<li><strong>Self-Peer Overlaps:</strong> ' . count($johari_results['open']) . ' (Open quadrant)</li>';
        $html .= '<li><strong>Peer-Only Adjectives:</strong> ' . count($johari_results['blind']) . ' (Blind quadrant)</li>';
        $html .= '<li><strong>Self-Only Adjectives:</strong> ' . count($johari_results['hidden']) . ' (Hidden quadrant)</li>';
        $html .= '</ul>';
        $html .= '</div>';

        $html .= '</div>';

        return $html;
    }

    public function cleanup_expired_links()
    {
        global $wpdb;

        $links_table = $this->table(self::TABLE_LINKS);
        $feedback_table = $this->table(self::TABLE_FEEDBACK);
        $agg_table = $this->table(self::TABLE_AGG);
        $self_table = $this->table(self::TABLE_SELF);

        // Find expired links (older than 30 days)
        $expired_links = $wpdb->get_col($wpdb->prepare(
            "SELECT self_id FROM `$links_table` WHERE expires_at < %s",
            date('Y-m-d H:i:s', strtotime('-30 days'))
        ));

        if (!empty($expired_links)) {
            $placeholders = implode(',', array_fill(0, count($expired_links), '%d'));

            // Delete all related data for expired assessments
            $wpdb->query($wpdb->prepare("DELETE FROM `$feedback_table` WHERE self_id IN ($placeholders)", $expired_links));
            $wpdb->query($wpdb->prepare("DELETE FROM `$links_table` WHERE self_id IN ($placeholders)", $expired_links));
            $wpdb->query($wpdb->prepare("DELETE FROM `$agg_table` WHERE self_id IN ($placeholders)", $expired_links));
            $wpdb->query($wpdb->prepare("DELETE FROM `$self_table` WHERE id IN ($placeholders)", $expired_links));

            error_log('Johari MI Quiz: Cleaned up ' . count($expired_links) . ' expired assessments.');
        }

        // Also clean up any orphaned records
        $wpdb->query("
            DELETE f FROM `$feedback_table` f 
            LEFT JOIN `$self_table` s ON f.self_id = s.id 
            WHERE s.id IS NULL
        ");

        $wpdb->query("
            DELETE l FROM `$links_table` l 
            LEFT JOIN `$self_table` s ON l.self_id = s.id 
            WHERE s.id IS NULL
        ");

        $wpdb->query("
            DELETE a FROM `$agg_table` a 
            LEFT JOIN `$self_table` s ON a.self_id = s.id 
            WHERE s.id IS NULL
        ");
    }

    public function handle_user_deletion($user_id)
    {
        global $wpdb;
        $self_table = $this->table(self::TABLE_SELF);
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id FROM `$self_table` WHERE user_id = %d", $user_id), ARRAY_A);
        if ($rows) {
            $self_ids = array_map('intval', wp_list_pluck($rows, 'id'));
            $ids_sql = implode(',', array_fill(0, count($self_ids), '%d'));
            $wpdb->query($wpdb->prepare("DELETE FROM `" . $this->table(self::TABLE_FEEDBACK) . "` WHERE self_id IN ($ids_sql)", $self_ids));
            $wpdb->query($wpdb->prepare("DELETE FROM `" . $this->table(self::TABLE_LINKS) . "` WHERE self_id IN ($ids_sql)", $self_ids));
            $wpdb->query($wpdb->prepare("DELETE FROM `" . $this->table(self::TABLE_AGG) . "` WHERE self_id IN ($ids_sql)", $self_ids));
            $wpdb->query($wpdb->prepare("DELETE FROM `$self_table` WHERE user_id = %d", $user_id));
        }
    }

    public function preserve_jmi_login_redirect($redirect_to, $request, $user)
    {
        error_log('JMI: Login redirect called - checking for stored UUID');

        // Check if we have a JMI UUID stored in session for this user
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        if (isset($_SESSION['jmi_redirect_uuid'])) {
            $jmi_uuid = $_SESSION['jmi_redirect_uuid'];
            unset($_SESSION['jmi_redirect_uuid']); // Clean up

            $peer_assessment_url = home_url('/johari-x-mi-assessment/?jmi=' . $jmi_uuid);
            error_log('JMI: Redirecting logged in user to peer assessment: ' . $peer_assessment_url);
            return $peer_assessment_url;
        }

        // Fallback: check original redirect_to parameter
        if (isset($_REQUEST['redirect_to'])) {
            $redirect_url = sanitize_url($_REQUEST['redirect_to']);
            if (strpos($redirect_url, 'jmi=') !== false) {
                error_log('JMI: Using fallback redirect: ' . $redirect_url);
                return $redirect_url;
            }
        }

        return $redirect_to;
    }

    public function preserve_jmi_registration_redirect($redirect_to)
    {
        error_log('JMI: Registration redirect called');

        // For new registrations, we'll handle redirect via login hook after auto-login
        // So just preserve the original redirect_to if it exists
        if (isset($_REQUEST['redirect_to'])) {
            $redirect_url = sanitize_url($_REQUEST['redirect_to']);
            if (strpos($redirect_url, 'jmi=') !== false) {
                error_log('JMI: Preserving registration redirect: ' . $redirect_url);
                return $redirect_url;
            }
        }

        return $redirect_to;
    }

    /**
     * Store JMI UUID in transient when user visits login/register forms from peer assessment
     * This allows us to redirect them back after successful authentication
     */
    public function store_jmi_uuid_for_login()
    {
        if (isset($_GET['redirect_to'])) {
            $redirect_url = sanitize_url($_GET['redirect_to']);
            $parsed = parse_url($redirect_url);

            if (isset($parsed['query'])) {
                parse_str($parsed['query'], $query_params);
                if (isset($query_params['jmi'])) {
                    $jmi_uuid = sanitize_text_field($query_params['jmi']);

                    // Validate UUID format
                    if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $jmi_uuid)) {
                        // Store UUID in a transient with the user's session/IP as key
                        $session_key = 'jmi_auth_' . md5(wp_get_session_token() ?: $_SERVER['REMOTE_ADDR'] . $_SERVER['HTTP_USER_AGENT']);
                        set_transient($session_key, $jmi_uuid, 600); // 10 minutes

                        error_log('JMI: Stored UUID for authentication session: ' . $session_key . ' -> ' . $jmi_uuid);
                    }
                }
            }
        }
    }

    /**
     * Handle user registration - auto-login and redirect to peer assessment
     */
    public function handle_user_registration($user_id)
    {
        error_log('JMI: User registered - ID: ' . $user_id);

        // Get the stored JMI UUID for this session
        $session_key = 'jmi_auth_' . md5(wp_get_session_token() ?: $_SERVER['REMOTE_ADDR'] . $_SERVER['HTTP_USER_AGENT']);
        $jmi_uuid = get_transient($session_key);

        if ($jmi_uuid) {
            error_log('JMI: Found stored UUID for new user: ' . $jmi_uuid);

            // Store the UUID for post-registration auto-login
            set_transient('jmi_auto_login_' . $user_id, $jmi_uuid, 300); // 5 minutes

            // Clean up the session transient
            delete_transient($session_key);

            error_log('JMI: Set auto-login transient for user ID: ' . $user_id);

            // Trigger auto-login hook
            do_action('jmi_user_registered_for_peer_assessment', $user_id, $jmi_uuid);
        }
    }

    /**
     * Handle user login - redirect to peer assessment if UUID is stored
     */
    public function handle_user_login($user_login, $user)
    {
        error_log('JMI: User logged in - ID: ' . $user->ID . ', Login: ' . $user_login);

        // Check if this user has a pending peer assessment auto-login
        $jmi_uuid = get_transient('jmi_auto_login_' . $user->ID);

        if ($jmi_uuid) {
            error_log('JMI: Found auto-login UUID for user: ' . $jmi_uuid);

            // Clean up the transient
            delete_transient('jmi_auto_login_' . $user->ID);

            // Store redirect info in user session
            $_SESSION['jmi_redirect_uuid'] = $jmi_uuid;

            error_log('JMI: Set session redirect for UUID: ' . $jmi_uuid);
        } else {
            // Check if login came from peer assessment page via session
            $session_key = 'jmi_auth_' . md5(wp_get_session_token() ?: $_SERVER['REMOTE_ADDR'] . $_SERVER['HTTP_USER_AGENT']);
            $jmi_uuid = get_transient($session_key);

            if ($jmi_uuid) {
                error_log('JMI: Found session UUID for existing user login: ' . $jmi_uuid);
                delete_transient($session_key);
                $_SESSION['jmi_redirect_uuid'] = $jmi_uuid;
            }
        }
    }

    /**
     * Initialize session for UUID storage
     */
    public function init_session()
    {
        if (!is_admin() && session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    /**
     * AJAX endpoint to check if user is logged in (for JavaScript polling)
     */
    public function ajax_check_login()
    {
        wp_send_json_success([
            'logged_in' => is_user_logged_in(),
            'user_id' => get_current_user_id(),
            'timestamp' => time()
        ]);
    }

    /**
     * Custom registration endpoint for peer assessment with auto-login
     * Based on the working MI quiz ajax_magic_register pattern
     */
    public function ajax_peer_magic_register()
    {
        check_ajax_referer('jmi_nonce');

        $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
        $first_name = isset($_POST['first_name']) ? sanitize_text_field($_POST['first_name']) : '';
        $jmi_uuid = isset($_POST['jmi_uuid']) ? sanitize_text_field($_POST['jmi_uuid']) : '';

        if (!is_email($email)) {
            wp_send_json_error('Please provide a valid email address.');
        }
        if (empty($first_name)) {
            wp_send_json_error('Please provide your first name.');
        }
        if (empty($jmi_uuid)) {
            wp_send_json_error('Missing peer assessment ID.');
        }

        // Validate JMI UUID format
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $jmi_uuid)) {
            wp_send_json_error('Invalid peer assessment ID.');
        }

        if (email_exists($email)) {
            wp_send_json_error('This email is already registered. Please use the "Login" button instead.');
        }

        // Generate unique username from email
        $username_base = sanitize_user(explode('@', $email)[0], true);
        $username = $username_base;
        $i = 1;
        while (username_exists($username)) {
            $username = $username_base . $i;
            $i++;
        }

        // Create user account
        $password = wp_generate_password(24, true, true);
        $user_id = wp_create_user($username, $password, $email);

        if (is_wp_error($user_id)) {
            error_log('JMI: User creation failed - ' . $user_id->get_error_message());
            wp_send_json_error('Could not create account. Please try again.');
        }

        // Update user profile
        wp_update_user([
            'ID' => $user_id,
            'first_name' => $first_name,
            'display_name' => $first_name
        ]);

        // Log the user in immediately (this is the key part!)
        wp_set_current_user($user_id, $username);
        wp_set_auth_cookie($user_id, true);

        error_log('JMI: Magic registration successful - User ID: ' . $user_id . ', redirecting to peer assessment');

        // Build the peer assessment URL
        $peer_assessment_url = home_url('/johari-x-mi-assessment/?jmi=' . $jmi_uuid);

        wp_send_json_success([
            'message' => 'Account created! You are now logged in.',
            'redirect_url' => $peer_assessment_url,
            'user_id' => $user_id
        ]);
    }

    public function ajax_get_user_data()
    {
        // Security check
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'jmi_nonce')) {
            wp_send_json_error('Invalid nonce.');
        }

        // Debug logging
        error_log('JMI AJAX get_user_data - is_user_logged_in(): ' . (is_user_logged_in() ? 'true' : 'false'));

        if (!is_user_logged_in()) {
            wp_send_json_success([
                'currentUser' => null,
                'isLoggedIn' => false
            ]);
        }

        $user = wp_get_current_user();
        error_log('JMI AJAX get_user_data - Current user ID: ' . $user->ID);

        $johari_profile = get_user_meta($user->ID, 'johari_mi_profile', true);
        $self_uuid = get_user_meta($user->ID, 'jmi_self_uuid', true);

        // Check if user has a recent assessment in awaiting-peers state
        $existing_state = null;
        $peer_link_uuid = null;
        if ($self_uuid) {
            global $wpdb;
            $self_table = $this->table(self::TABLE_SELF);
            $feedback_table = $this->table(self::TABLE_FEEDBACK);
            $links_table = $this->table(self::TABLE_LINKS);

            $self_row = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM `$self_table` WHERE uuid = %s AND user_id = %d",
                $self_uuid,
                $user->ID
            ));

            if ($self_row) {
                $peer_count = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM `$feedback_table` WHERE self_id = %d",
                    $self_row->id
                ));

                // Get the peer link UUID
                $peer_link_uuid = $wpdb->get_var($wpdb->prepare(
                    "SELECT uuid FROM `$links_table` WHERE self_id = %d AND expires_at > NOW()",
                    $self_row->id
                ));

                if ($peer_count >= 2) {
                    $existing_state = 'results-ready';
                } else {
                    $existing_state = 'awaiting-peers';
                }
            }
        }

        $user_data = [
            'id' => $user->ID,
            'email' => $user->user_email,
            'firstName' => $user->first_name,
            'lastName' => $user->last_name,
            'johari' => is_array($johari_profile) ? $johari_profile : null,
            'selfUuid' => $self_uuid,
            'peerLinkUuid' => $peer_link_uuid,
            'existingState' => $existing_state,
        ];

        error_log('JMI AJAX get_user_data - User data: ' . json_encode($user_data));

        wp_send_json_success([
            'currentUser' => $user_data,
            'isLoggedIn' => true
        ]);
    }

    /**
     * Get the original user's name from a JMI UUID for peer assessment context
     */
    public function ajax_get_original_user()
    {
        check_ajax_referer('jmi_nonce');

        $jmi_uuid = isset($_POST['jmi_uuid']) ? sanitize_text_field($_POST['jmi_uuid']) : '';

        if (empty($jmi_uuid)) {
            wp_send_json_error('Missing JMI UUID.');
        }

        // Validate JMI UUID format
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $jmi_uuid)) {
            wp_send_json_error('Invalid JMI UUID format.');
        }

        global $wpdb;

        // Find the peer link and get the associated self_id
        $links_table = $this->table(self::TABLE_LINKS);
        $link = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM `$links_table` WHERE uuid = %s AND expires_at > NOW()",
            $jmi_uuid
        ));

        if (!$link) {
            wp_send_json_error('This assessment link has expired or is invalid.');
        }

        // Get the original user from the self assessment
        $self_table = $this->table(self::TABLE_SELF);
        $self_row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM `$self_table` WHERE id = %d",
            $link->self_id
        ));

        if (!$self_row || !$self_row->user_id) {
            wp_send_json_error('Could not find the original assessment.');
        }

        // Get the user data
        $user = get_userdata($self_row->user_id);
        if (!$user) {
            wp_send_json_error('Original user not found.');
        }

        // Return user's display name or first name
        $display_name = $user->first_name ? $user->first_name : $user->display_name;
        if (empty($display_name)) {
            $display_name = $user->user_nicename;
        }

        wp_send_json_success([
            'name' => $display_name,
            'email' => $user->user_email // Optional: might be useful for debugging
        ]);
    }

    /**
     * AJAX: Get assessment data for admin testing interface
     */
    public function ajax_get_assessment_data()
    {
        // Verify nonce
        if (!wp_verify_nonce($_POST['_ajax_nonce'], 'jmi_admin_nonce')) {
            wp_send_json_error('Security check failed');
        }

        // Check admin permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $assessment_id = intval($_POST['assessment_id']);
        if (!$assessment_id) {
            wp_send_json_error('Invalid assessment ID');
        }

        global $wpdb;

        // Get self assessment data
        $self_table = $this->table(self::TABLE_SELF);
        $self_row = $wpdb->get_row($wpdb->prepare(
            "SELECT s.*, u.display_name, u.user_email 
             FROM `$self_table` s 
             LEFT JOIN {$wpdb->users} u ON s.user_id = u.ID 
             WHERE s.id = %d",
            $assessment_id
        ));

        if (!$self_row) {
            wp_send_json_error('Assessment not found');
        }

        // Get peer feedback count and responses
        $feedback_table = $this->table(self::TABLE_FEEDBACK);
        $peer_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `$feedback_table` WHERE self_id = %d",
            $assessment_id
        ));

        // Get all peer responses with details
        $peer_responses = $wpdb->get_results($wpdb->prepare(
            "SELECT f.peer_user_id, f.adjectives, f.created_at, u.display_name 
             FROM `$feedback_table` f 
             LEFT JOIN {$wpdb->users} u ON f.peer_user_id = u.ID 
             WHERE f.self_id = %d 
             ORDER BY f.created_at ASC",
            $assessment_id
        ));

        // Parse peer responses
        $peer_data = [];
        foreach ($peer_responses as $peer) {
            $peer_adjectives = json_decode($peer->adjectives, true) ?: [];
            $is_test_peer = in_array($peer->peer_user_id, [999001, 999002]);
            $peer_label = $is_test_peer ?
                '🧪 Test Peer ' . ($peer->peer_user_id == 999001 ? '1' : '2') :
                ($peer->display_name ?: 'Peer ' . $peer->peer_user_id);

            $peer_data[] = [
                'user_id' => $peer->peer_user_id,
                'label' => $peer_label,
                'adjectives' => $peer_adjectives,
                'created_at' => $peer->created_at,
                'is_test_peer' => $is_test_peer
            ];
        }

        // Parse self-selected adjectives
        $self_adjectives = json_decode($self_row->adjectives, true) ?: [];

        wp_send_json_success([
            'id' => $self_row->id,
            'uuid' => $self_row->uuid,
            'user_name' => $self_row->display_name ?: 'Guest',
            'user_email' => $self_row->user_email ?: '',
            'created_at' => $self_row->created_at,
            'peer_count' => intval($peer_count),
            'self_adjectives' => $self_adjectives,
            'peer_responses' => $peer_data
        ]);
    }

    /**
     * AJAX: Simulate peer response for admin testing
     */
    public function ajax_admin_simulate_peer()
    {
        // Verify nonce
        if (!wp_verify_nonce($_POST['_ajax_nonce'], 'jmi_admin_nonce')) {
            wp_send_json_error('Security check failed');
        }

        // Check admin permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $assessment_id = intval($_POST['assessment_id']);
        $peer_id = sanitize_text_field($_POST['peer_id']);
        $adjectives = $_POST['adjectives'];

        if (!$assessment_id || !$peer_id || !is_array($adjectives)) {
            wp_send_json_error('Invalid data provided');
        }

        // Validate adjective count
        if (count($adjectives) < 6 || count($adjectives) > 10) {
            wp_send_json_error('Please select 6-10 adjectives');
        }

        // Sanitize adjectives
        $adjectives = array_map('sanitize_text_field', $adjectives);

        global $wpdb;

        // Verify assessment exists
        $self_table = $this->table(self::TABLE_SELF);
        $assessment = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM `$self_table` WHERE id = %d",
            $assessment_id
        ));

        if (!$assessment) {
            wp_send_json_error('Assessment not found');
        }

        // Create a dummy peer user for testing (or use current admin)
        $current_user_id = get_current_user_id();
        $test_peer_user_id = $current_user_id;

        // For uniqueness, we'll create fake user IDs based on peer_id
        // This is for testing only and bypasses normal validation
        $test_user_offset = ($peer_id === 'peer1') ? 999001 : 999002;

        // Get or create dummy peer link (for testing purposes)
        $links_table = $this->table(self::TABLE_LINKS);
        $link = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM `$links_table` WHERE self_id = %d LIMIT 1",
            $assessment_id
        ));

        if (!$link) {
            // Create dummy link for testing
            $link_uuid = wp_generate_uuid4();
            $wpdb->insert($links_table, [
                'self_id' => $assessment_id,
                'uuid' => $link_uuid,
                'created_at' => current_time('mysql'),
                'expires_at' => date('Y-m-d H:i:s', strtotime('+7 days')),
                'max_peers' => 10
            ]);
            $link_id = $wpdb->insert_id;
        } else {
            $link_id = $link->id;
        }

        // Insert or update peer feedback
        $feedback_table = $this->table(self::TABLE_FEEDBACK);

        // First, try to delete any existing entry for this test peer
        $wpdb->delete($feedback_table, [
            'self_id' => $assessment_id,
            'peer_user_id' => $test_user_offset
        ]);

        // Insert new feedback
        $result = $wpdb->insert($feedback_table, [
            'self_id' => $assessment_id,
            'link_id' => $link_id,
            'peer_user_id' => $test_user_offset,
            'adjectives' => json_encode($adjectives),
            'created_at' => current_time('mysql'),
            'ip' => 'admin-test'
        ]);

        if ($result === false) {
            wp_send_json_error('Database error: ' . $wpdb->last_error);
        }

        wp_send_json_success([
            'message' => 'Peer response submitted successfully',
            'peer_id' => $peer_id,
            'adjective_count' => count($adjectives)
        ]);
    }

    /**
     * AJAX: Clear test data for admin testing
     */
    public function ajax_admin_clear_test_data()
    {
        // Verify nonce
        if (!wp_verify_nonce($_POST['_ajax_nonce'], 'jmi_admin_nonce')) {
            wp_send_json_error('Security check failed');
        }

        // Check admin permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $assessment_id = intval($_POST['assessment_id']);
        if (!$assessment_id) {
            wp_send_json_error('Invalid assessment ID');
        }

        global $wpdb;

        // Delete test peer feedback (user IDs 999001, 999002)
        $feedback_table = $this->table(self::TABLE_FEEDBACK);
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM `$feedback_table` WHERE self_id = %d AND peer_user_id IN (999001, 999002)",
            $assessment_id
        ));

        // Clear aggregates to force recalculation
        $agg_table = $this->table(self::TABLE_AGG);
        $wpdb->delete($agg_table, ['self_id' => $assessment_id]);

        wp_send_json_success([
            'message' => 'Test data cleared successfully',
            'deleted_rows' => $deleted
        ]);
    }

    /**
     * AJAX: Clear cached Johari results to force fresh calculation
     */
    public function ajax_admin_clear_cached_results()
    {
        // Verify nonce
        if (!wp_verify_nonce($_POST['_ajax_nonce'], 'jmi_admin_nonce')) {
            wp_send_json_error('Security check failed');
        }

        // Check admin permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $assessment_id = intval($_POST['assessment_id']);
        if (!$assessment_id) {
            wp_send_json_error('Invalid assessment ID');
        }

        global $wpdb;

        // Clear cached results to force recalculation
        $agg_table = $this->table(self::TABLE_AGG);
        $deleted = $wpdb->delete($agg_table, ['self_id' => $assessment_id]);

        wp_send_json_success([
            'message' => 'Cached results cleared',
            'cleared_cache' => $deleted > 0
        ]);
    }

    /**
     * AJAX: Clear ALL peer data for assessment (including real responses) - for testing only
     */
    public function ajax_admin_clear_all_peer_data()
    {
        // Verify nonce
        if (!wp_verify_nonce($_POST['_ajax_nonce'], 'jmi_admin_nonce')) {
            wp_send_json_error('Security check failed');
        }

        // Check admin permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $assessment_id = intval($_POST['assessment_id']);
        if (!$assessment_id) {
            wp_send_json_error('Invalid assessment ID');
        }

        global $wpdb;

        // Delete ALL peer feedback for this assessment
        $feedback_table = $this->table(self::TABLE_FEEDBACK);
        $deleted = $wpdb->delete($feedback_table, ['self_id' => $assessment_id]);

        // Clear aggregates to force recalculation
        $agg_table = $this->table(self::TABLE_AGG);
        $wpdb->delete($agg_table, ['self_id' => $assessment_id]);

        wp_send_json_success([
            'message' => 'All peer data cleared successfully',
            'deleted_rows' => $deleted
        ]);
    }

    /**
     * Get user's MI profile data for comparison with Johari results
     */
    private function get_user_mi_profile($user_id)
    {
        error_log("JMI MI PROFILE DEBUG - get_user_mi_profile() called with user_id: $user_id");

        if (!$user_id) {
            error_log("JMI MI PROFILE DEBUG - No user_id provided, returning null");
            return null;
        }

        // Get the user's MI quiz results
        error_log("JMI MI PROFILE DEBUG - Attempting to get user meta 'miq_quiz_results' for user $user_id");
        $mi_results = get_user_meta($user_id, 'miq_quiz_results', true);
        error_log("JMI MI PROFILE DEBUG - Raw MI results for user $user_id: " . json_encode($mi_results));

        if (empty($mi_results) || !is_array($mi_results)) {
            error_log("JMI DEBUG - No MI results found or invalid format for user $user_id");
            return null;
        }

        // Get MI categories for display names
        $mi_categories = [
            'logical-mathematical' => 'Logical-Mathematical',
            'linguistic' => 'Linguistic',
            'spatial' => 'Spatial-Visual',
            'bodily-kinesthetic' => 'Bodily-Kinesthetic',
            'musical' => 'Musical-Rhythmic',
            'interpersonal' => 'Interpersonal',
            'intrapersonal' => 'Intrapersonal',
            'naturalistic' => 'Naturalistic'
        ];

        // Extract relevant data
        $profile_data = [
            'has_results' => true,
            'part1_scores' => $mi_results['part1Scores'] ?? [],
            'top3' => $mi_results['top3'] ?? [],
            'top3_names' => [],
            'assessment_date' => $mi_results['generated_at'] ?? $mi_results['timestamp'] ?? null
        ];

        // Convert top 3 slugs to display names
        if (!empty($profile_data['top3'])) {
            $profile_data['top3_names'] = array_map(function ($slug) use ($mi_categories) {
                return $mi_categories[$slug] ?? ucfirst(str_replace('-', ' ', $slug));
            }, $profile_data['top3']);
        }

        // Calculate percentile rankings
        if (!empty($profile_data['part1_scores'])) {
            $max_score = 15; // Assuming max score per domain
            $profile_data['percentiles'] = [];
            $profile_data['strength_levels'] = [];

            foreach ($profile_data['part1_scores'] as $domain => $score) {
                $percentage = ($score / $max_score) * 100;
                $profile_data['percentiles'][$domain] = $this->calculate_percentile($percentage);
                $profile_data['strength_levels'][$domain] = $this->get_strength_level($percentage);
            }
        }

        return $profile_data;
    }

    /**
     * Calculate percentile based on score percentage
     */
    private function calculate_percentile($percentage)
    {
        if ($percentage >= 85)
            return 95;
        if ($percentage >= 75)
            return 85;
        if ($percentage >= 65)
            return 75;
        if ($percentage >= 55)
            return 65;
        if ($percentage >= 45)
            return 55;
        if ($percentage >= 35)
            return 45;
        if ($percentage >= 25)
            return 35;
        return 25;
    }

    /**
     * Get strength level description
     */
    private function get_strength_level($percentage)
    {
        if ($percentage >= 85)
            return 'Exceptional';
        if ($percentage >= 75)
            return 'Very Strong';
        if ($percentage >= 65)
            return 'Strong';
        if ($percentage >= 55)
            return 'Above Average';
        if ($percentage >= 45)
            return 'Average';
        if ($percentage >= 35)
            return 'Developing';
        if ($percentage >= 25)
            return 'Basic';
        return 'Emerging';
    }

    /**
     * AJAX: Add sample MI test data for testing integration
     */
    public function ajax_admin_add_mi_test_data()
    {
        // Verify nonce
        if (!wp_verify_nonce($_POST['_ajax_nonce'], 'jmi_admin_nonce')) {
            wp_send_json_error('Security check failed');
        }

        // Check admin permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $user_id = intval($_POST['user_id']);
        if (!$user_id) {
            wp_send_json_error('Invalid user ID');
        }

        // Check if user exists
        $user = get_user_by('id', $user_id);
        if (!$user) {
            wp_send_json_error('User not found');
        }

        // Sample MI quiz results - realistic test data that correlates well with naturalistic adjectives
        $mi_results = [
            'part1Scores' => [
                'logical-mathematical' => 12,
                'linguistic' => 9,
                'spatial' => 8,
                'bodily-kinesthetic' => 7,
                'musical' => 6,
                'interpersonal' => 10,
                'intrapersonal' => 13,
                'naturalistic' => 15  // Strong naturalistic (matches "Attentive to Nature", "Patient", etc.)
            ],
            'top3' => ['naturalistic', 'intrapersonal', 'logical-mathematical'],
            'generated_at' => current_time('mysql'),
            'timestamp' => current_time('mysql')
        ];

        // Add the meta
        $result = update_user_meta($user_id, 'miq_quiz_results', $mi_results);

        if ($result !== false) {
            wp_send_json_success([
                'message' => 'Sample MI data added successfully for user: ' . $user->display_name,
                'top3' => ['Naturalistic', 'Intrapersonal', 'Logical-Mathematical'],
                'user_name' => $user->display_name
            ]);
        } else {
            wp_send_json_error('Failed to add MI quiz results');
        }
    }

    /**
     * AJAX: Clear MI test data
     */
    public function ajax_admin_clear_mi_test_data()
    {
        // Verify nonce
        if (!wp_verify_nonce($_POST['_ajax_nonce'], 'jmi_admin_nonce')) {
            wp_send_json_error('Security check failed');
        }

        // Check admin permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $user_id = intval($_POST['user_id']);
        if (!$user_id) {
            wp_send_json_error('Invalid user ID');
        }

        // Check if user exists
        $user = get_user_by('id', $user_id);
        if (!$user) {
            wp_send_json_error('User not found');
        }

        // Remove the MI quiz results
        $result = delete_user_meta($user_id, 'miq_quiz_results');

        if ($result) {
            wp_send_json_success([
                'message' => 'MI data cleared for user: ' . $user->display_name,
                'user_name' => $user->display_name
            ]);
        } else {
            wp_send_json_error('No MI data found to clear, or deletion failed');
        }
    }

    /**
     * Handle special URL parameter to add MI test data directly
     */
    public function handle_mi_test_data_url()
    {
        // Check if we should add MI test data
        if (isset($_GET['add_mi_test_data']) && current_user_can('manage_options')) {
            $user_id = intval($_GET['user_id'] ?? 6);

            // Check if user exists
            $user = get_user_by('id', $user_id);
            if (!$user) {
                wp_die('User not found.');
            }

            // Sample MI quiz results - realistic test data that correlates well with naturalistic adjectives
            $mi_results = [
                'part1Scores' => [
                    'logical-mathematical' => 12,
                    'linguistic' => 9,
                    'spatial' => 8,
                    'bodily-kinesthetic' => 7,
                    'musical' => 6,
                    'interpersonal' => 10,
                    'intrapersonal' => 13,
                    'naturalistic' => 15  // Strong naturalistic (matches "Attentive to Nature", "Patient", etc.)
                ],
                'top3' => ['naturalistic', 'intrapersonal', 'logical-mathematical'],
                'generated_at' => current_time('mysql'),
                'timestamp' => current_time('mysql')
            ];

            // Add the meta
            $result = update_user_meta($user_id, 'miq_quiz_results', $mi_results);

            if ($result !== false) {
                wp_die(
                    '✅ Successfully added MI quiz results for user: ' . $user->display_name . ' (ID: ' . $user_id . ')<br>' .
                    'Top 3 MI strengths: Naturalistic, Intrapersonal, Logical-Mathematical<br>' .
                    'Naturalistic score: 15/15 (perfect match for nature-related adjectives)<br><br>' .
                    'Now try viewing the <a href="/johari-mi-results/">Johari results</a> to see the MI integration!',
                    'MI Test Data Added Successfully'
                );
            } else {
                wp_die('❌ Failed to add MI quiz results');
            }
        }

        // Check if we should clear MI test data
        if (isset($_GET['clear_mi_test_data']) && current_user_can('manage_options')) {
            $user_id = intval($_GET['user_id'] ?? 6);

            // Check if user exists
            $user = get_user_by('id', $user_id);
            if (!$user) {
                wp_die('User not found.');
            }

            // Remove the MI quiz results
            $result = delete_user_meta($user_id, 'miq_quiz_results');

            if ($result) {
                wp_die('✅ MI data cleared for user: ' . $user->display_name . ' (ID: ' . $user_id . ')', 'MI Test Data Cleared');
            } else {
                wp_die('❌ No MI data found to clear, or deletion failed');
            }
        }

        // Check if we should clear cached results
        if (isset($_GET['clear_cached_results']) && current_user_can('manage_options')) {
            $assessment_id = intval($_GET['assessment_id'] ?? 6);

            global $wpdb;
            $agg_table = $this->table(self::TABLE_AGG);
            $deleted = $wpdb->delete($agg_table, ['self_id' => $assessment_id]);

            wp_die(
                '✅ Cached results cleared for assessment ID ' . $assessment_id . ' (deleted: ' . $deleted . ' records)<br>' .
                '<a href="/johari-mi-results/">View Results Page</a> (should trigger fresh calculation)',
                'Cached Results Cleared'
            );
        }

        // Check if we should manually trigger results calculation for debugging
        if (isset($_GET['debug_calculate_results']) && current_user_can('manage_options')) {
            $assessment_id = intval($_GET['assessment_id'] ?? 6);

            error_log("JMI MANUAL DEBUG - Starting manual results calculation for assessment ID: $assessment_id");

            // Clear any cached results first
            global $wpdb;
            $agg_table = $this->table(self::TABLE_AGG);
            $wpdb->delete($agg_table, ['self_id' => $assessment_id]);

            // Manually trigger calculation
            $results = $this->calculate_johari_window($assessment_id);

            if ($results) {
                wp_die(
                    '✅ Results calculated successfully for assessment ID ' . $assessment_id . '<br><br>' .
                    '<strong>Check the error logs for MI profile debug output!</strong><br><br>' .
                    '<pre>' . print_r($results['mi_profile'] ?? 'No MI profile data', true) . '</pre><br>' .
                    '<a href="/johari-mi-results/">View Results Page</a>',
                    'Debug Results Calculation Complete'
                );
            } else {
                wp_die('❌ Failed to calculate results for assessment ID ' . $assessment_id, 'Debug Results Calculation Failed');
            }
        }
    }

    /**
     * AJAX: Create test peer user for admin testing (frontend)
     */
    public function ajax_create_test_peer()
    {
        // Verify nonce
        check_ajax_referer('jmi_nonce');

        // Check admin permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $share_url = sanitize_url($_POST['share_url'] ?? '');
        $peer_name = sanitize_text_field($_POST['peer_name'] ?? 'Test Peer');

        if (empty($share_url)) {
            wp_send_json_error('Share URL is required');
        }

        // Extract the UUID from the share URL
        $parsed_url = parse_url($share_url);
        parse_str($parsed_url['query'] ?? '', $query_params);
        $peer_uuid = $query_params['jmi'] ?? '';

        if (empty($peer_uuid)) {
            wp_send_json_error('Invalid share URL format');
        }

        global $wpdb;

        // Find the link and get assessment info
        $links_table = $this->table(self::TABLE_LINKS);
        $link = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM `$links_table` WHERE uuid = %s AND expires_at > NOW()",
            $peer_uuid
        ));

        if (!$link) {
            wp_send_json_error('Share link has expired or is invalid');
        }

        // Create a temporary test user
        $username = 'test_peer_' . time() . '_' . wp_rand(1000, 9999);
        $email = $username . '@testpeer.local';
        $password = wp_generate_password(12, false);

        $user_id = wp_create_user($username, $password, $email);

        if (is_wp_error($user_id)) {
            wp_send_json_error('Failed to create test user: ' . $user_id->get_error_message());
        }

        // Update user display name
        wp_update_user([
            'ID' => $user_id,
            'display_name' => $peer_name,
            'first_name' => $peer_name,
            'nickname' => $peer_name
        ]);

        // Add user meta to mark as test user
        update_user_meta($user_id, '_jmi_test_peer', true);
        update_user_meta($user_id, '_jmi_created_by', get_current_user_id());
        update_user_meta($user_id, '_jmi_created_at', current_time('mysql'));

        // Store admin context for returning after test completion
        $admin_user = wp_get_current_user();
        $admin_return_token = wp_generate_password(32, false);
        update_user_meta($user_id, '_jmi_admin_return_token', $admin_return_token);
        update_user_meta($user_id, '_jmi_admin_return_user_id', get_current_user_id());
        update_user_meta($user_id, '_jmi_admin_return_expires', time() + 7200); // 2 hours

        // Auto-login the user by creating a temporary login URL
        $login_token = wp_generate_password(32, false);
        update_user_meta($user_id, '_jmi_temp_login_token', $login_token);
        update_user_meta($user_id, '_jmi_temp_login_expires', time() + 3600); // 1 hour

        // Build login URL that will auto-login and redirect to peer assessment
        $login_url = add_query_arg([
            'jmi_temp_login' => $login_token,
            'redirect_to' => urlencode($share_url)
        ], home_url('/'));

        wp_send_json_success([
            'user_id' => $user_id,
            'username' => $username,
            'display_name' => $peer_name,
            'email' => $email,
            'login_url' => $login_url,
            'share_url' => $share_url,
            'message' => 'Test peer user created successfully'
        ]);
    }

    /**
     * Handle temporary login for test peer users
     */
    public function handle_temp_login()
    {
        if (!isset($_GET['jmi_temp_login'])) {
            return;
        }

        $token = sanitize_text_field($_GET['jmi_temp_login']);
        $redirect_to = isset($_GET['redirect_to']) ? urldecode($_GET['redirect_to']) : home_url('/');

        // Find user with this token
        $users = get_users([
            'meta_key' => '_jmi_temp_login_token',
            'meta_value' => $token,
            'number' => 1
        ]);

        if (empty($users)) {
            wp_die('Invalid or expired login token.');
        }

        $user = $users[0];

        // Check if token has expired
        $expires = get_user_meta($user->ID, '_jmi_temp_login_expires', true);
        if (!$expires || time() > intval($expires)) {
            wp_die('Login token has expired.');
        }

        // Log in the user
        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID, true);

        // Clean up the token
        delete_user_meta($user->ID, '_jmi_temp_login_token');
        delete_user_meta($user->ID, '_jmi_temp_login_expires');

        // Redirect to the assessment
        wp_safe_redirect($redirect_to);
        exit;
    }

    /**
     * AJAX: Cleanup test peer users created by current admin
     */
    public function ajax_cleanup_test_users()
    {
        // Verify nonce
        check_ajax_referer('jmi_nonce');

        // Check admin permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $current_user_id = get_current_user_id();

        // Find all test peer users created by current admin
        $test_users = get_users([
            'meta_key' => '_jmi_created_by',
            'meta_value' => $current_user_id,
            'fields' => 'ID'
        ]);

        $deleted_count = 0;

        foreach ($test_users as $user_id) {
            // Double-check this is a test peer
            $is_test_peer = get_user_meta($user_id, '_jmi_test_peer', true);
            if ($is_test_peer) {
                // Remove user and all their data
                require_once(ABSPATH . 'wp-admin/includes/user.php');
                if (wp_delete_user($user_id)) {
                    $deleted_count++;
                }
            }
        }

        wp_send_json_success([
            'deleted_count' => $deleted_count,
            'message' => "Cleaned up {$deleted_count} test users"
        ]);
    }

    /**
     * AJAX: Return to admin account after test peer completion
     */
    public function ajax_return_to_admin()
    {
        // Verify nonce
        check_ajax_referer('jmi_nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error('Not logged in');
        }

        $current_user_id = get_current_user_id();

        // Check if current user is a test peer
        $is_test_peer = get_user_meta($current_user_id, '_jmi_test_peer', true);
        if (!$is_test_peer) {
            wp_send_json_error('Not a test peer user');
        }

        // Get admin return context
        $admin_return_token = get_user_meta($current_user_id, '_jmi_admin_return_token', true);
        $admin_user_id = get_user_meta($current_user_id, '_jmi_admin_return_user_id', true);
        $admin_return_expires = get_user_meta($current_user_id, '_jmi_admin_return_expires', true);

        if (!$admin_return_token || !$admin_user_id || !$admin_return_expires) {
            wp_send_json_error('Admin return context not found');
        }

        // Check if token has expired
        if (time() > intval($admin_return_expires)) {
            wp_send_json_error('Admin return token has expired');
        }

        // Verify admin user still exists and has proper permissions
        $admin_user = get_user_by('id', $admin_user_id);
        if (!$admin_user || !user_can($admin_user, 'manage_options')) {
            wp_send_json_error('Admin user not found or insufficient permissions');
        }

        // Generate return URL with token
        $return_url = add_query_arg([
            'jmi_admin_return' => $admin_return_token,
            'from_test_peer' => $current_user_id
        ], home_url('/'));

        wp_send_json_success([
            'return_url' => $return_url,
            'admin_name' => $admin_user->display_name ?: $admin_user->user_login,
            'message' => 'Return URL generated successfully'
        ]);
    }

    /**
     * Handle admin return after test peer completion
     */
    public function handle_admin_return()
    {
        if (!isset($_GET['jmi_admin_return'])) {
            return;
        }

        $return_token = sanitize_text_field($_GET['jmi_admin_return']);
        $test_peer_id = intval($_GET['from_test_peer'] ?? 0);

        if (!$return_token || !$test_peer_id) {
            wp_die('Invalid admin return parameters.');
        }

        // Find test peer user and validate token
        $stored_token = get_user_meta($test_peer_id, '_jmi_admin_return_token', true);
        $admin_user_id = get_user_meta($test_peer_id, '_jmi_admin_return_user_id', true);
        $expires = get_user_meta($test_peer_id, '_jmi_admin_return_expires', true);

        if (!$stored_token || $stored_token !== $return_token) {
            wp_die('Invalid or expired admin return token.');
        }

        if (!$expires || time() > intval($expires)) {
            wp_die('Admin return token has expired.');
        }

        // Verify admin user
        $admin_user = get_user_by('id', $admin_user_id);
        if (!$admin_user || !user_can($admin_user, 'manage_options')) {
            wp_die('Admin user not found or insufficient permissions.');
        }

        // Log back in as admin
        wp_set_current_user($admin_user_id);
        wp_set_auth_cookie($admin_user_id, true);

        // Clean up the return tokens
        delete_user_meta($test_peer_id, '_jmi_admin_return_token');
        delete_user_meta($test_peer_id, '_jmi_admin_return_expires');

        // Find the assessment page to redirect back to
        $assess_url = $this->_find_page_by_shortcode('johari_mi_quiz');
        if (!$assess_url) {
            $assess_url = $this->_find_page_by_shortcode('johari-mi-quiz');
        }
        if (!$assess_url) {
            $assess_url = admin_url('admin.php?page=johari-mi-subs');
        }

        // Add success message as query parameter
        $redirect_url = add_query_arg([
            'jmi_admin_returned' => '1',
            'test_peer_name' => urlencode(get_userdata($test_peer_id)->display_name ?? 'Test Peer')
        ], $assess_url);

        wp_safe_redirect($redirect_url);
        exit;
    }

    /**
     * AJAX: Reset user's assessment (admin only)
     */
    public function ajax_reset_assessment()
    {
        // Verify nonce
        check_ajax_referer('jmi_nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error('Not logged in');
        }

        $current_user_id = get_current_user_id();

        global $wpdb;

        // Get all self assessments for this user
        $self_table = $this->table(self::TABLE_SELF);
        $self_assessments = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM `$self_table` WHERE user_id = %d",
            $current_user_id
        ));

        $deleted_count = 0;

        foreach ($self_assessments as $assessment) {
            // Delete related feedback
            $feedback_table = $this->table(self::TABLE_FEEDBACK);
            $wpdb->delete($feedback_table, ['self_id' => $assessment->id]);

            // Delete related links
            $links_table = $this->table(self::TABLE_LINKS);
            $wpdb->delete($links_table, ['self_id' => $assessment->id]);

            // Delete aggregated results
            $agg_table = $this->table(self::TABLE_AGG);
            $wpdb->delete($agg_table, ['self_id' => $assessment->id]);

            // Delete the self assessment
            $result = $wpdb->delete($self_table, ['id' => $assessment->id]);
            if ($result) {
                $deleted_count++;
            }
        }

        // Clear user meta
        delete_user_meta($current_user_id, 'jmi_self_uuid');
        delete_user_meta($current_user_id, 'johari_mi_profile');

        wp_send_json_success([
            'deleted_count' => $deleted_count,
            'message' => "Reset complete. Deleted {$deleted_count} assessments."
        ]);
    }

    /**
     * AJAX: Debug user data (admin only)
     */
    public function ajax_debug_user_data()
    {
        // Verify nonce and admin permissions
        check_ajax_referer('jmi_nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Access denied');
        }

        $user_id = intval($_POST['user_id'] ?? get_current_user_id());
        $self_uuid = get_user_meta($user_id, 'jmi_self_uuid', true);

        global $wpdb;
        $self_table = $this->table(self::TABLE_SELF);
        $links_table = $this->table(self::TABLE_LINKS);
        $feedback_table = $this->table(self::TABLE_FEEDBACK);

        $debug_info = [
            'user_id' => $user_id,
            'self_uuid_from_meta' => $self_uuid ?: 'NULL',
            'self_table_entries' => [],
            'specific_assessment' => null,
            'peer_links' => [],
            'peer_feedback_count' => 0
        ];

        // Get all self table entries for this user
        $self_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM `$self_table` WHERE user_id = %d ORDER BY created_at DESC",
            $user_id
        ));

        foreach ($self_rows as $row) {
            $debug_info['self_table_entries'][] = [
                'id' => $row->id,
                'uuid' => $row->uuid,
                'created_at' => $row->created_at
            ];
        }

        // If we have a self_uuid from meta, get specific info
        if ($self_uuid) {
            $specific_row = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM `$self_table` WHERE uuid = %s",
                $self_uuid
            ));

            if ($specific_row) {
                $debug_info['specific_assessment'] = [
                    'found' => true,
                    'id' => $specific_row->id,
                    'user_id' => $specific_row->user_id,
                    'uuid' => $specific_row->uuid,
                    'created_at' => $specific_row->created_at
                ];

                // Get peer links
                $links = $wpdb->get_results($wpdb->prepare(
                    "SELECT * FROM `$links_table` WHERE self_id = %d",
                    $specific_row->id
                ));

                foreach ($links as $link) {
                    $expired = strtotime($link->expires_at) < time();
                    $debug_info['peer_links'][] = [
                        'uuid' => $link->uuid,
                        'expires_at' => $link->expires_at,
                        'expired' => $expired
                    ];
                }

                // Get feedback count
                $debug_info['peer_feedback_count'] = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM `$feedback_table` WHERE self_id = %d",
                    $specific_row->id
                ));

            } else {
                $debug_info['specific_assessment'] = [
                    'found' => false,
                    'message' => 'UUID from meta not found in database!'
                ];
            }
        }

        wp_send_json_success($debug_info);
    }
}
