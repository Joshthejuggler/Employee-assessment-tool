<?php
if (!defined('ABSPATH'))
    exit;

/**
 * Helper class for managing the quiz funnel configuration and state
 */
class MC_Funnel
{
    const OPTION_KEY = 'mc_quiz_funnel_config';

    /**
     * Get the current funnel configuration with defaults
     * 
     * @return array Configuration array with steps, titles, and placeholder
     */
    public static function get_config()
    {
        $defaults = [
            'steps' => ['mi-quiz', 'cdt-quiz', 'bartle-quiz', 'johari-mi-quiz'],
            'titles' => [
                'mi-quiz' => 'Multiple Intelligences Assessment',
                'cdt-quiz' => 'Cognitive Dissonance Tolerance Quiz',
                'bartle-quiz' => 'Player Type Discovery',
                'johari-mi-quiz' => 'Johari × MI'
            ],
            'placeholder' => [
                'title' => 'Advanced Self-Discovery Module',
                'description' => 'Coming soon - unlock deeper insights into your personal growth journey',
                'target' => '', // URL or page slug when ready
                'enabled' => false
            ]
        ];

        $config = get_option(self::OPTION_KEY, []);
        return wp_parse_args($config, $defaults);
    }

    /**
     * Save funnel configuration
     * 
     * @param array $config Configuration to save
     * @return bool Success/failure
     */
    public static function save_config($config)
    {
        $sanitized = self::sanitize_config($config);
        $result = update_option(self::OPTION_KEY, $sanitized);

        // Clear all user dashboard caches when config changes
        if ($result) {
            self::clear_all_dashboard_caches();
        }

        return $result;
    }

    /**
     * Get completion status for current user
     * 
     * @param int|null $user_id User ID, defaults to current user
     * @return array Completion status keyed by quiz slug
     */
    public static function get_completion_status($user_id = null)
    {
        if (!$user_id) {
            $user_id = get_current_user_id();
        }

        if (!$user_id) {
            return [];
        }

        // Use the existing completion logic from dashboard
        $registered_quizzes = Micro_Coach_Core::get_quizzes();
        $completion_status = [];

        foreach ($registered_quizzes as $quiz_id => $quiz_info) {
            // Special handling for Johari to count 'ready' state as complete
            if ($quiz_id === 'johari-mi-quiz') {
                $johari_status = self::get_johari_status($user_id);
                $completion_status[$quiz_id] = in_array($johari_status['status'], ['completed', 'ready']);
                continue;
            }

            $meta_key = $quiz_info['results_meta_key'] ?? '';
            if ($meta_key) {
                $results = get_user_meta($user_id, $meta_key, true);
                $completion_status[$quiz_id] = !empty($results);
            } else {
                $completion_status[$quiz_id] = false;
            }
        }

        return $completion_status;
    }

    /**
     * Get detailed status for the Johari quiz including intermediate states
     * 
     * @param int|null $user_id User ID, defaults to current user
     * @return array Status info with 'status', 'badge_text', 'description'
     */
    public static function get_johari_status($user_id = null)
    {
        if (!$user_id) {
            $user_id = get_current_user_id();
        }

        if (!$user_id) {
            return [
                'status' => 'available',
                'badge_text' => 'Available',
                'description' => 'Start your self-assessment'
            ];
        }

        global $wpdb;
        $prefix = $wpdb->prefix;

        // Check if user has completed the final quiz (has results in user meta)
        $johari_results = get_user_meta($user_id, 'johari_mi_profile', true);
        if (!empty($johari_results)) {
            return [
                'status' => 'completed',
                'badge_text' => 'Completed',
                'description' => 'View your Johari Window with peer insights'
            ];
        }

        // Check if user has done self-assessment
        $self_table = $prefix . 'jmi_self';
        $self_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `$self_table` WHERE user_id = %d",
            $user_id
        ));

        if ($self_exists) {
            // Check how many peer feedback submissions they have
            $feedback_table = $prefix . 'jmi_peer_feedback';
            $peer_count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT f.peer_user_id) 
                 FROM `$feedback_table` f 
                 INNER JOIN `$self_table` s ON f.self_id = s.id 
                 WHERE s.user_id = %d",
                $user_id
            ));

            if ($peer_count >= 2) {
                return [
                    'status' => 'ready',
                    'badge_text' => 'Results Ready',
                    'description' => 'Click to view your Johari Window results'
                ];
            } else {
                return [
                    'status' => 'waiting',
                    'badge_text' => 'Awaiting Feedback',
                    'description' => "Need " . (2 - $peer_count) . " more peer feedback submissions"
                ];
            }
        }

        // User hasn't started the self-assessment yet
        return [
            'status' => 'available',
            'badge_text' => 'Available',
            'description' => 'Start your self-assessment'
        ];
    }

    /**
     * Get unlock status for each step based on completion
     * 
     * @param int|null $user_id User ID, defaults to current user
     * @return array Unlock status keyed by quiz slug
     */
    public static function get_unlock_status($user_id = null)
    {
        $config = self::get_config();
        $unlock_status = [];

        // All quizzes are now available in any order
        foreach ($config['steps'] as $step_slug) {
            $unlock_status[$step_slug] = true;
        }

        return $unlock_status;
    }

    /**
     * Get the URL for a quiz step
     * 
     * @param string $step_slug Quiz slug
     * @return string|null URL or null if not found
     */
    public static function get_step_url($step_slug)
    {
        // Use existing logic to find quiz pages
        $registered_quizzes = Micro_Coach_Core::get_quizzes();
        if (!isset($registered_quizzes[$step_slug])) {
            return null;
        }

        $shortcode = $registered_quizzes[$step_slug]['shortcode'] ?? '';
        if (!$shortcode) {
            return null;
        }

        // Use the same page finding logic from Micro_Coach_Core
        return self::find_page_by_shortcode($shortcode);
    }

    /**
     * Check if all assessments are complete and trigger notifications/AI analysis
     * 
     * @param int $user_id User ID
     * @return void
     */
    public static function check_completion_and_notify($user_id)
    {
        // Clear cache to ensure fresh completion status
        self::clear_all_dashboard_caches();

        $config = self::get_config();
        $completion = self::get_completion_status($user_id);

        // Check if ALL quizzes are complete
        $all_complete = true;
        foreach (($config['steps'] ?? []) as $slug) {
            if (empty($completion[$slug])) {
                $all_complete = false;
                break;
            }
        }

        if ($all_complete) {
            // Check if we've already handled this completion to avoid duplicates
            $already_handled = get_user_meta($user_id, 'mc_all_assessments_completed', true);
            if ($already_handled) {
                return;
            }

            // Mark as handled
            update_user_meta($user_id, 'mc_all_assessments_completed', time());

            // 1. Generate AI Analysis
            if (class_exists('Micro_Coach_AI')) {
                $analysis = Micro_Coach_AI::generate_analysis_on_completion($user_id);
            }

            // 2. Send Admin/Employer Notification
            $admin_email = get_option('admin_email');
            $user_info = get_userdata($user_id);
            $user_name = $user_info ? $user_info->display_name : 'User #' . $user_id;

            // Find linked employer
            $linked_employer_id = get_user_meta($user_id, 'mc_linked_employer_id', true);
            $employer_email = $linked_employer_id ? get_userdata($linked_employer_id)->user_email : $admin_email;

            $subject = 'Assessment Suitability Report: ' . $user_name;

            $dashboard_url = self::find_page_by_shortcode('mc_employer_dashboard');
            if (!$dashboard_url) {
                $dashboard_url = home_url();
            }

            $headers = ['Content-Type: text/html; charset=UTF-8'];

            $message = "<!DOCTYPE html><html><body style='font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, Helvetica, Arial, sans-serif; line-height: 1.6; color: #333; background-color: #f9f9f9; padding: 20px;'>";
            $message .= "<div style='max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 8px; padding: 30px; box-shadow: 0 2px 10px rgba(0,0,0,0.05);'>";

            $message .= "<h2 style='color: #1e293b; margin-top: 0; border-bottom: 2px solid #f1f5f9; padding-bottom: 15px;'>Assessment Suitability Report</h2>";

            $message .= "<p style='font-size: 16px;'><strong>Employee:</strong> " . esc_html($user_name) . " (" . esc_html($user_info->user_email) . ")</p>";
            $message .= "<p style='color: #64748b;'>This employee has completed all assigned assessments. Based on their profile and your workplace context, here is the AI-generated analysis:</p>";

            if (!empty($analysis)) {
                $strengths = $analysis['strengths'] ?? [];
                $red_flags = $analysis['red_flags'] ?? [];

                if (is_string($strengths))
                    $strengths = [$strengths];
                if (is_string($red_flags))
                    $red_flags = [$red_flags];

                $message .= "<div style='background: #f0fdf4; padding: 20px; border-radius: 8px; margin: 25px 0; border: 1px solid #bbf7d0;'>";
                $message .= "<h3 style='color: #15803d; margin-top: 0; display: flex; align-items: center; font-size: 18px;'>✅ Positive Signs</h3>";
                $message .= "<ul style='margin-bottom: 0; padding-left: 20px;'>";
                foreach ($strengths as $point) {
                    $message .= "<li style='margin-bottom: 8px; color: #14532d;'>" . esc_html($point) . "</li>";
                }
                $message .= "</ul></div>";

                $message .= "<div style='background: #fef2f2; padding: 20px; border-radius: 8px; margin: 25px 0; border: 1px solid #fecaca;'>";
                $message .= "<h3 style='color: #b91c1c; margin-top: 0; display: flex; align-items: center; font-size: 18px;'>🚩 Red Flags / Risks</h3>";
                $message .= "<ul style='margin-bottom: 0; padding-left: 20px;'>";
                foreach ($red_flags as $point) {
                    $message .= "<li style='margin-bottom: 8px; color: #7f1d1d;'>" . esc_html($point) . "</li>";
                }
                $message .= "</ul></div>";
            }

            $message .= "<div style='text-align: center; margin-top: 35px; padding-top: 20px; border-top: 1px dashed #e2e8f0;'>";
            $message .= "<a href='" . esc_url($dashboard_url) . "' style='background-color: #2563eb; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: 600; display: inline-block;'>View Dashboard</a>";
            $message .= "</div>";

            $message .= "</div></body></html>";

            wp_mail($employer_email, $subject, $message, $headers);
        }
    }

    /**
     * Find a page containing a specific shortcode
     * 
     * @param string $shortcode Shortcode to search for
     * @return string|null Page URL or null if not found
     */
    public static function find_page_by_shortcode($shortcode)
    {
        $pages = get_pages([
            'meta_key' => '_wp_page_template',
            'hierarchical' => false,
            'number' => 100
        ]);

        // Also search all published pages
        $all_pages = get_posts([
            'post_type' => 'page',
            'post_status' => 'publish',
            'numberposts' => 100,
            'meta_query' => [
                'relation' => 'OR',
                [
                    'key' => '_wp_page_template',
                    'compare' => 'EXISTS'
                ],
                [
                    'key' => '_wp_page_template',
                    'compare' => 'NOT EXISTS'
                ]
            ]
        ]);

        $search_pages = array_merge($pages, $all_pages);

        foreach ($search_pages as $page) {
            if (has_shortcode($page->post_content, $shortcode)) {
                return get_permalink($page->ID);
            }
        }

        return null;
    }

    /**
     * Sanitize configuration input
     * 
     * @param array $config Raw configuration
     * @return array Sanitized configuration
     */
    private static function sanitize_config($config)
    {
        $registered_quizzes = Micro_Coach_Core::get_quizzes();
        $valid_slugs = array_keys($registered_quizzes);
        $valid_slugs[] = 'placeholder'; // Always allow placeholder

        $sanitized = [
            'steps' => [],
            'titles' => [],
            'placeholder' => [
                'title' => '',
                'description' => '',
                'target' => '',
                'enabled' => false
            ]
        ];

        // Sanitize steps - only allow valid quiz slugs and placeholder
        if (!empty($config['steps']) && is_array($config['steps'])) {
            foreach ($config['steps'] as $step) {
                $step = sanitize_key($step);
                if (in_array($step, $valid_slugs) && !in_array($step, $sanitized['steps'])) {
                    $sanitized['steps'][] = $step;
                }
            }
        }

        // Ensure we have at least the default steps if none provided
        if (empty($sanitized['steps'])) {
            $sanitized['steps'] = ['mi-quiz', 'cdt-quiz', 'bartle-quiz', 'johari-mi-quiz'];
        }

        // Sanitize titles
        if (!empty($config['titles']) && is_array($config['titles'])) {
            foreach ($config['titles'] as $slug => $title) {
                $slug = sanitize_key($slug);
                if (in_array($slug, $valid_slugs)) {
                    $sanitized['titles'][$slug] = sanitize_text_field($title);
                }
            }
        }

        // Sanitize placeholder config
        if (!empty($config['placeholder']) && is_array($config['placeholder'])) {
            $sanitized['placeholder']['title'] = sanitize_text_field($config['placeholder']['title'] ?? '');
            $sanitized['placeholder']['description'] = sanitize_textarea_field($config['placeholder']['description'] ?? '');
            $sanitized['placeholder']['target'] = esc_url_raw($config['placeholder']['target'] ?? '');
            $sanitized['placeholder']['enabled'] = !empty($config['placeholder']['enabled']);
        }

        return $sanitized;
    }

    /**
     * Clear dashboard caches for all users
     */
    public static function clear_all_dashboard_caches()
    {
        if (class_exists('MC_Cache')) {
            // Clear all dashboard caches - this is a bit brute force but ensures consistency
            global $wpdb;
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'mc_dashboard_data_%'");
        }
    }

    /**
     * Clear the funnel configuration cache and reset to defaults
     */
    public static function reset_to_defaults()
    {
        delete_option(self::OPTION_KEY);
        self::clear_all_dashboard_caches();
        return true;
    }

    /**
     * Aggregate all assessment results for a user into a single array
     * 
     * @param int $user_id User ID
     * @return array Aggregated results
     */
    public static function get_all_assessment_results($user_id)
    {
        $results = [];

        // MI Quiz
        $mi_results = get_user_meta($user_id, 'miq_quiz_results', true);
        if (!empty($mi_results)) {
            $results['mi'] = $mi_results;
        }

        // CDT Quiz
        $cdt_results = get_user_meta($user_id, 'cdt_quiz_results', true);
        if (!empty($cdt_results)) {
            $results['cdt'] = $cdt_results;
        }

        // Bartle Quiz
        $bartle_results = get_user_meta($user_id, 'bartle_quiz_results', true);
        if (!empty($bartle_results)) {
            $results['bartle'] = $bartle_results;
        }

        // Johari Window
        $johari_results = get_user_meta($user_id, 'johari_mi_profile', true);
        if (!empty($johari_results)) {
            $results['johari'] = $johari_results;
        }

        return $results;
    }
}