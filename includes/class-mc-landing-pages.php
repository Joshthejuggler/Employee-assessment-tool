<?php
if (!defined('ABSPATH'))
    exit;

/**
 * Handles the rendering of Employer and Employee landing pages.
 */
class MC_Landing_Pages
{

    public static function init()
    {
        add_shortcode('mc_employer_landing', [__CLASS__, 'render_employer_landing']);
        add_shortcode('mc_employee_landing', [__CLASS__, 'render_employee_landing']);
        add_action('template_redirect', [__CLASS__, 'handle_invite_logic']);
    }

    /**
     * Handles invite logic (redirects, cookies) before headers are sent.
     */
    public static function handle_invite_logic()
    {
        // Only run if invite_code is present
        if (!isset($_GET['invite_code'])) {
            return;
        }

        // Check if we are on the employee landing page
        // Since we don't know the ID, we check if the content contains the shortcode
        global $post;
        if (!$post || !has_shortcode($post->post_content, 'mc_employee_landing')) {
            return;
        }

        $code = sanitize_text_field($_GET['invite_code']);

        // Set cookie for registration persistence
        if (!headers_sent()) {
            setcookie('mc_invite_code', $code, time() + 3600, COOKIEPATH, COOKIE_DOMAIN);
        }

        // Process Invite Code
        $args = [
            'meta_key' => 'mc_company_share_code',
            'meta_value' => $code,
            'number' => 1,
            'fields' => 'ID'
        ];
        $employer_query = new WP_User_Query($args);
        $employers = $employer_query->get_results();

        if (!empty($employers)) {
            $employer_id = $employers[0];

            // If user is logged in, link them immediately, assign role, and redirect to dashboard
            if (is_user_logged_in()) {
                $user_id = get_current_user_id();

                // Prevent employer from linking to themselves
                if ($user_id == $employer_id) {
                    // Do nothing, let them view the page
                } else {
                    // Check if already linked to avoid re-running logic unnecessarily
                    $current_linked_employer = get_user_meta($user_id, 'mc_linked_employer_id', true);

                    if ($current_linked_employer != $employer_id) {
                        update_user_meta($user_id, 'mc_linked_employer_id', $employer_id);

                        // Assign Employee Role ONLY if they don't have a higher role
                        $is_employer = get_user_meta($user_id, 'mc_company_name', true);

                        if (!$is_employer && class_exists('MC_Roles')) {
                            $u = new WP_User($user_id);
                            $u->add_role(MC_Roles::ROLE_EMPLOYEE);
                        }
                    }

                    // Redirect to dashboard
                    if (class_exists('MC_Funnel')) {
                        $dashboard_url = MC_Funnel::find_page_by_shortcode('quiz_dashboard');
                        if ($dashboard_url) {
                            wp_redirect($dashboard_url);
                            exit;
                        }
                    }
                }
            }
        }
    }

    /**
     * Renders the Employer Landing Page.
     */
    public static function render_employer_landing()
    {
        ob_start();
        ?>
        <div class="mc-landing-page mc-employer-landing">
            <header class="mc-site-header">
                <div class="mc-logo">What You're Good At</div>
                <div class="mc-nav">
                    <?php if (is_user_logged_in()): ?>
                        <a href="<?php echo wp_logout_url(get_permalink()); ?>">Logout</a>
                    <?php else: ?>
                        <a href="<?php echo wp_login_url(get_permalink()); ?>">Login</a>
                    <?php endif; ?>
                </div>
            </header>

            <header class="mc-landing-hero">
                <div class="mc-hero-content">
                    <h1>Unlock Your Team's True Potential</h1>
                    <p class="mc-landing-subtitle">AI-powered insights that turn employees into high-performing teams.</p>
                    <?php
                    $onboarding_url = '#';
                    if (class_exists('MC_Funnel')) {
                        $onboarding_url = MC_Funnel::find_page_by_shortcode('mc_employer_onboarding') ?: '#';
                    }
                    ?>
                    <div class="mc-hero-cta">
                        <a href="<?php echo esc_url($onboarding_url); ?>" class="mc-button mc-button-primary">Get Started</a>
                        <a href="#assessments" class="mc-button mc-button-secondary">Learn More</a>
                    </div>
                </div>
            </header>

            <section class="mc-landing-section mc-stats-section">
                <div class="mc-container">
                    <div class="mc-stats-grid">
                        <div class="mc-stat-item">
                            <div class="mc-stat-number">4</div>
                            <div class="mc-stat-label">Psychometric Assessments</div>
                        </div>
                        <div class="mc-stat-item">
                            <div class="mc-stat-number">AI</div>
                            <div class="mc-stat-label">Generated Growth Plans</div>
                        </div>
                        <div class="mc-stat-item">
                            <div class="mc-stat-number">360°</div>
                            <div class="mc-stat-label">Team Feedback</div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="mc-landing-section mc-value-prop">
                <div class="mc-container mc-container-narrow">
                    <h2>Why This Matters</h2>
                    <p class="mc-lead-text">Understanding your employees' unique skill sets and core motivators is the key to building a high-performing team.</p>
                    <div class="mc-value-boxes">
                        <div class="mc-value-box">
                            <svg class="mc-value-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                                <circle cx="8.5" cy="7" r="4"></circle>
                                <polyline points="17 11 19 13 23 9"></polyline>
                            </svg>
                            <h3>Assign With Precision</h3>
                            <p>Match tasks to natural strengths</p>
                        </div>
                        <div class="mc-value-box">
                            <svg class="mc-value-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M12 2v20M2 12h20"></path>
                                <circle cx="12" cy="12" r="10"></circle>
                            </svg>
                            <h3>Identify Leaders</h3>
                            <p>Spot high-growth potential early</p>
                        </div>
                        <div class="mc-value-box">
                            <svg class="mc-value-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                                <polyline points="22 4 12 14.01 9 11.01"></polyline>
                            </svg>
                            <h3>Boost Retention</h3>
                            <p>Create growth paths that matter</p>
                        </div>
                    </div>
                </div>
            </section>

            <section id="assessments" class="mc-landing-section mc-assessments-section">
                <div class="mc-container">
                    <div class="mc-section-header">
                        <h2>The Assessments</h2>
                        <p class="mc-section-subtitle">Four powerful tools to understand your team's unique profile</p>
                    </div>
                    <div class="mc-assessments-grid">
                        <div class="mc-assessment-card">
                            <div class="mc-card-icon mc-icon-intelligence">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M12 2L2 7l10 5 10-5-10-5z"></path>
                                    <path d="M2 17l10 5 10-5M2 12l10 5 10-5"></path>
                                </svg>
                            </div>
                            <h3>Multiple Intelligences (MI)</h3>
                            <p class="mc-card-description">Assign tasks employees naturally excel at</p>
                            <ul class="mc-card-benefits">
                                <li>Identify 8 types of intelligence</li>
                                <li>Match cognitive styles to roles</li>
                                <li>Reduce friction, increase output</li>
                            </ul>
                        </div>
                        <div class="mc-assessment-card">
                            <div class="mc-card-icon mc-icon-cognitive">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M22 12h-4l-3 9L9 3l-3 9H2"></path>
                                </svg>
                            </div>
                            <h3>Cognitive Dissonance Tolerance</h3>
                            <p class="mc-card-description">Identify future leaders and growth capacity</p>
                            <ul class="mc-card-benefits">
                                <li>Measure complexity handling</li>
                                <li>Predict leadership readiness</li>
                                <li>Assess feedback receptivity</li>
                            </ul>
                        </div>
                        <div class="mc-assessment-card">
                            <div class="mc-card-icon mc-icon-bartle">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="12" cy="12" r="10"></circle>
                                    <path d="M12 6v6l4 2"></path>
                                </svg>
                            </div>
                            <h3>Bartle Player Types</h3>
                            <p class="mc-card-description">Match motivation types to your reward system</p>
                            <ul class="mc-card-benefits">
                                <li>Discover core motivators</li>
                                <li>Tailor management approaches</li>
                                <li>Design effective incentives</li>
                            </ul>
                        </div>
                        <div class="mc-assessment-card">
                            <div class="mc-card-icon mc-icon-johari">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                                    <line x1="3" y1="12" x2="21" y2="12"></line>
                                    <line x1="12" y1="3" x2="12" y2="21"></line>
                                </svg>
                            </div>
                            <h3>Johari Window</h3>
                            <p class="mc-card-description">Boost trust through 360-degree perception</p>
                            <ul class="mc-card-benefits">
                                <li>Reveal blind spots</li>
                                <li>Improve self-awareness</li>
                                <li>Strengthen team dynamics</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </section>

            <section class="mc-landing-section mc-action-section">
                <div class="mc-container">
                    <div class="mc-action-content">
                        <div class="mc-action-text">
                            <span class="mc-badge">AI-Powered</span>
                            <h2>From Insight to Action</h2>
                            <p class="mc-lead-text">Once the Psychometric Profile is complete, our AI generates personalized Experiments—specific growth challenges and management strategies designed for each employee's unique blend of motivators and cognitive style.</p>
                            <div class="mc-action-features">
                                <div class="mc-feature-item">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <polyline points="20 6 9 17 4 12"></polyline>
                                    </svg>
                                    <span>Tailored to individual profiles</span>
                                </div>
                                <div class="mc-feature-item">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <polyline points="20 6 9 17 4 12"></polyline>
                                    </svg>
                                    <span>Actionable from day one</span>
                                </div>
                                <div class="mc-feature-item">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <polyline points="20 6 9 17 4 12"></polyline>
                                    </svg>
                                    <span>Backed by psychometric data</span>
                                </div>
                            </div>
                        </div>
                        <div class="mc-action-visual">
                            <div class="mc-visual-placeholder">
                                <svg viewBox="0 0 200 200" fill="none">
                                    <circle cx="100" cy="100" r="80" stroke="rgba(255, 255, 255, 0.3)" stroke-width="2"/>
                                    <circle cx="100" cy="100" r="60" stroke="rgba(255, 255, 255, 0.4)" stroke-width="2"/>
                                    <circle cx="100" cy="100" r="40" stroke="rgba(255, 255, 255, 0.5)" stroke-width="2"/>
                                    <circle cx="100" cy="100" r="8" fill="rgba(255, 255, 255, 0.95)"/>
                                    <line x1="100" y1="100" x2="140" y2="60" stroke="rgba(255, 255, 255, 0.9)" stroke-width="3"/>
                                    <circle cx="140" cy="60" r="6" fill="rgba(255, 255, 255, 0.95)"/>
                                    <line x1="100" y1="100" x2="60" y2="60" stroke="rgba(255, 255, 255, 0.9)" stroke-width="3"/>
                                    <circle cx="60" cy="60" r="6" fill="rgba(255, 255, 255, 0.95)"/>
                                    <line x1="100" y1="100" x2="140" y2="140" stroke="rgba(255, 255, 255, 0.9)" stroke-width="3"/>
                                    <circle cx="140" cy="140" r="6" fill="rgba(255, 255, 255, 0.95)"/>
                                    <line x1="100" y1="100" x2="60" y2="140" stroke="rgba(255, 255, 255, 0.9)" stroke-width="3"/>
                                    <circle cx="60" cy="140" r="6" fill="rgba(255, 255, 255, 0.95)"/>
                                </svg>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="mc-landing-section mc-final-cta">
                <div class="mc-container mc-container-narrow">
                    <div class="mc-cta-box">
                        <h2>Ready to Build Stronger Teams?</h2>
                        <p class="mc-cta-subtitle">Increase retention, reduce friction, and unlock hidden strengths in your workforce.</p>
                        <div class="mc-cta-buttons">
                            <a href="<?php echo esc_url($onboarding_url); ?>" class="mc-button mc-button-primary mc-button-large">Get Started</a>
                            <button onclick="openSampleReportModal()" class="mc-button mc-button-outline mc-button-large" type="button">View Sample Report</button>
                        </div>
                        <p class="mc-cta-note">No credit card required • 5-minute setup</p>
                    </div>
                </div>
            </section>

            <footer class="mc-landing-footer">
                <div class="mc-container">
                    <p>© <?php echo date('Y'); ?> What You're Good At. All rights reserved.</p>
                </div>
            </footer>

            <!-- Sample Report Modal -->
            <div id="mc-sample-report-modal" class="mc-sample-modal" style="display: none;">
                <div class="mc-sample-modal-content">
                    <span class="mc-sample-close" onclick="closeSampleReportModal()">&times;</span>
                    <div class="mc-sample-report-header">
                        <h2>Assessment Suitability Report</h2>
                        <div class="mc-sample-meta">
                            <p>For: <strong>Sample Employee</strong></p>
                            <span class="mc-sample-score-badge">Score: 85%</span>
                        </div>
                    </div>

                    <div class="mc-sample-summary">
                        <p>The candidate demonstrates a strong fit for the role with exceptional interpersonal and spatial skills, though there are areas of concern regarding decision-making autonomy and values clarity. Overall, they are likely to thrive in a collaborative environment that values creativity and personal growth.</p>
                    </div>

                    <div class="mc-sample-note">
                        <strong>Note:</strong> This report is based on 4 completed quizzes and 0 peer reviews.
                    </div>

                    <div class="mc-sample-section mc-sample-positive">
                        <h3><span>✅</span> Positive Signs</h3>
                        <ul>
                            <li>High interpersonal skills, particularly in persuasion and influence.</li>
                            <li>Strong goal orientation and self-reflection, indicating a proactive approach to personal and professional growth.</li>
                            <li>Exceptional spatial intelligence, particularly in design and aesthetics, which is valuable in creative roles.</li>
                        </ul>
                    </div>

                    <div class="mc-sample-section mc-sample-negative">
                        <h3><span>🚩</span> Red Flags / Risks</h3>
                        <ul>
                            <li>Lower scores in decision-making autonomy may indicate challenges in independent decision-making.</li>
                            <li>Values clarity is also low, which could lead to misalignment with company values.</li>
                            <li>Artistic representation is a weaker area, potentially limiting creativity in certain contexts.</li>
                        </ul>
                    </div>

                    <div class="mc-sample-section mc-sample-neutral">
                        <h3><span>🚀</span> What Motivates Them</h3>
                        <ul>
                            <li><strong>Achiever</strong> (34%) — Motivated by goals, progress, and measurable success</li>
                            <li><strong>Socializer</strong> (28%) — Values collaboration and team relationships</li>
                            <li><strong>Explorer</strong> (22%) — Driven by discovery and learning new things</li>
                        </ul>
                    </div>

                    <div class="mc-sample-footer">
                        <p>This is a sample report to demonstrate the platform's capabilities.</p>
                        <a href="<?php echo esc_url($onboarding_url); ?>" class="mc-button mc-button-primary">Get Started</a>
                    </div>
                </div>
            </div>

            <script>
                function openSampleReportModal() {
                    document.getElementById('mc-sample-report-modal').style.display = 'flex';
                    document.body.style.overflow = 'hidden';
                }

                function closeSampleReportModal() {
                    document.getElementById('mc-sample-report-modal').style.display = 'none';
                    document.body.style.overflow = 'auto';
                }

                // Close modal when clicking outside
                document.addEventListener('click', function(event) {
                    const modal = document.getElementById('mc-sample-report-modal');
                    if (event.target === modal) {
                        closeSampleReportModal();
                    }
                });

                // Close modal on Escape key
                document.addEventListener('keydown', function(event) {
                    if (event.key === 'Escape') {
                        closeSampleReportModal();
                    }
                });
            </script>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Renders the Employee Landing Page.
     */
    public static function render_employee_landing()
    {
        // Try to find linked employer to get logo
        $employer_id = 0;

        // 1. Check Invite Code (Logic moved to handle_invite_logic, just need employer ID for logo if applicable)
        if (isset($_GET['invite_code'])) {
            $code = sanitize_text_field($_GET['invite_code']);
            $args = [
                'meta_key' => 'mc_company_share_code',
                'meta_value' => $code,
                'number' => 1,
                'fields' => 'ID'
            ];
            $employer_query = new WP_User_Query($args);
            $employers = $employer_query->get_results();
            if (!empty($employers)) {
                $employer_id = $employers[0];
            }
        }

        // 2. Check Logged In User Link
        if (!$employer_id && is_user_logged_in()) {
            $user_id = get_current_user_id();
            $employer_id = get_user_meta($user_id, 'mc_linked_employer_id', true);
        }

        $logo_url = '';
        $company_name = '';
        if ($employer_id) {
            $logo_id = get_user_meta($employer_id, 'mc_company_logo_id', true);
            if ($logo_id) {
                $logo_url = wp_get_attachment_url($logo_id);
            }
            $company_name = get_user_meta($employer_id, 'mc_company_name', true);
        }

        $has_invite = isset($_GET['invite_code']) && $employer_id;

        ob_start();
        ?>
        <div class="mc-landing-page mc-employee-landing">
            <header class="mc-site-header">
                <div class="mc-logo">
                    <?php if ($logo_url): ?>
                        <img src="<?php echo esc_url($logo_url); ?>" alt="Company Logo" style="max-height: 40px;">
                    <?php else: ?>
                        What You're Good At
                    <?php endif; ?>
                </div>
                <div class="mc-nav">
                    <?php if (is_user_logged_in()): ?>
                        <a href="<?php echo wp_logout_url(get_permalink()); ?>">Logout</a>
                    <?php else: ?>
                        <a href="<?php echo wp_login_url(get_permalink()); ?>">Login</a>
                    <?php endif; ?>
                </div>
            </header>

            <section class="mc-landing-hero mc-employee-hero">
                <div class="mc-container">
                    <div class="mc-hero-content">
                        <?php if ($has_invite): ?>
                            <span class="mc-badge mc-badge-gradient">You're Invited</span>
                            <h1><?php echo $company_name ? esc_html($company_name) . ' Wants to' : 'Your employer wants to'; ?> Help You Grow</h1>
                            <p class="mc-hero-subtitle">You've been invited to discover your unique strengths and accelerate your professional development with science-backed assessments.</p>
                        <?php else: ?>
                            <span class="mc-badge mc-badge-gradient">Personal Growth Platform</span>
                            <h1>Discover What Makes You Exceptional</h1>
                            <p class="mc-hero-subtitle">Unlock your unique strengths, understand your motivation, and accelerate your career growth with science-backed assessments.</p>
                        <?php endif; ?>
                        <?php
                        $dashboard_url = '#';
                        if (class_exists('MC_Funnel')) {
                            $dashboard_url = MC_Funnel::find_page_by_shortcode('quiz_dashboard') ?: '#';
                        }

                        $button_url = $dashboard_url;
                        $button_text = $has_invite ? 'Accept Invitation & Start' : 'Begin Your Journey';

                        // If invite code is present, ensure it's in the dashboard URL
                        if (isset($_GET['invite_code'])) {
                            $code = sanitize_text_field($_GET['invite_code']);
                            $dashboard_url = add_query_arg('invite_code', $code, $dashboard_url);
                            $button_url = $dashboard_url;
                        }

                        if (!is_user_logged_in()) {
                            $button_text = $has_invite ? 'Create Account & Accept' : 'Create Free Account';
                            $button_url = wp_registration_url();
                            // Add invite code to registration URL if present
                            if ($has_invite && isset($_GET['invite_code'])) {
                                $button_url = add_query_arg('invite_code', sanitize_text_field($_GET['invite_code']), $button_url);
                            }
                        }
                        ?>
                        <div class="mc-hero-actions">
                            <a href="<?php echo esc_url($button_url); ?>" class="mc-button mc-button-primary mc-button-large"><?php echo esc_html($button_text); ?></a>
                        </div>
                        <div class="mc-hero-stats">
                            <div class="mc-stat-item">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M12 2L2 7l10 5 10-5-10-5z"></path>
                                    <path d="M2 17l10 5 10-5M2 12l10 5 10-5"></path>
                                </svg>
                                <span>15 min to complete</span>
                            </div>
                            <div class="mc-stat-item">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <polyline points="20 6 9 17 4 12"></polyline>
                                </svg>
                                <span>Science-backed results</span>
                            </div>
                            <div class="mc-stat-item">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
                                </svg>
                                <span>100% confidential</span>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="mc-landing-section mc-benefits-section">
                <div class="mc-container">
                    <div class="mc-section-header">
                        <?php if ($has_invite): ?>
                            <h2>What You'll Discover</h2>
                            <p class="mc-section-subtitle">A complete picture of your strengths, motivations, and growth potential</p>
                        <?php else: ?>
                            <h2>Your Personal Growth Roadmap</h2>
                            <p class="mc-section-subtitle">Understand yourself better to work smarter and grow faster</p>
                        <?php endif; ?>
                    </div>
                    <div class="mc-benefits-grid">
                        <div class="mc-benefit-card">
                            <div class="mc-benefit-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                                    <polyline points="22 4 12 14.01 9 11.01"></polyline>
                                </svg>
                            </div>
                            <h3>Discover Your Strengths</h3>
                            <p>Identify your natural talents and learn how to leverage them in your daily work. Stop fighting your weaknesses and start amplifying what you do best.</p>
                        </div>
                        <div class="mc-benefit-card">
                            <div class="mc-benefit-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <line x1="12" y1="1" x2="12" y2="23"></line>
                                    <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>
                                </svg>
                            </div>
                            <h3>Understand Your Motivation</h3>
                            <p>Learn what truly drives you—whether it's achievement, connection, or exploration—and design a work life that energizes rather than drains you.</p>
                        </div>
                        <div class="mc-benefit-card">
                            <div class="mc-benefit-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline>
                                </svg>
                            </div>
                            <h3>Accelerate Your Growth</h3>
                            <p>Get personalized development recommendations based on your cognitive style. Focus your energy on growth areas that will have the biggest impact on your career.</p>
                        </div>
                    </div>
                </div>
            </section>

            <section id="assessments" class="mc-landing-section mc-assessments-section mc-employee-assessments">
                <div class="mc-container">
                    <div class="mc-section-header">
                        <h2>Four Assessments, One Complete Picture</h2>
                        <p class="mc-section-subtitle">Science-backed tools to help you understand your unique professional profile</p>
                    </div>
                    <div class="mc-assessments-grid">
                        <div class="mc-assessment-card">
                            <div class="mc-card-icon mc-icon-intelligence">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M12 2L2 7l10 5 10-5-10-5z"></path>
                                    <path d="M2 17l10 5 10-5M2 12l10 5 10-5"></path>
                                </svg>
                            </div>
                            <h3>Multiple Intelligences</h3>
                            <p class="mc-card-description">Discover your unique cognitive strengths</p>
                            <ul class="mc-card-benefits">
                                <li>Find out if you're Word Smart, Logic Smart, People Smart, or more</li>
                                <li>Choose projects where you'll naturally excel</li>
                                <li>Communicate your strengths to managers and colleagues</li>
                            </ul>
                        </div>
                        <div class="mc-assessment-card">
                            <div class="mc-card-icon mc-icon-cognitive">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M22 12h-4l-3 9L9 3l-3 9H2"></path>
                                </svg>
                            </div>
                            <h3>Cognitive Dissonance Tolerance</h3>
                            <p class="mc-card-description">Measure your capacity for growth and complexity</p>
                            <ul class="mc-card-benefits">
                                <li>Understand how you handle challenges and uncertainty</li>
                                <li>Identify your leadership potential and readiness</li>
                                <li>Learn how receptive you are to feedback</li>
                            </ul>
                        </div>
                        <div class="mc-assessment-card">
                            <div class="mc-card-icon mc-icon-bartle">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="12" cy="12" r="10"></circle>
                                    <path d="M12 6v6l4 2"></path>
                                </svg>
                            </div>
                            <h3>Bartle Player Types</h3>
                            <p class="mc-card-description">Reveal what makes work fulfilling for you</p>
                            <ul class="mc-card-benefits">
                                <li>Discover if you're an Achiever, Explorer, Socializer, or Killer</li>
                                <li>Design a workday that aligns with your motivation</li>
                                <li>Find roles and projects that energize you</li>
                            </ul>
                        </div>
                        <div class="mc-assessment-card">
                            <div class="mc-card-icon mc-icon-johari">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                                    <line x1="3" y1="12" x2="21" y2="12"></line>
                                    <line x1="12" y1="3" x2="12" y2="21"></line>
                                </svg>
                            </div>
                            <h3>Johari Window</h3>
                            <p class="mc-card-description">See yourself through others' eyes</p>
                            <ul class="mc-card-benefits">
                                <li>Uncover blind spots in how you're perceived</li>
                                <li>Improve collaboration and relationships</li>
                                <li>Build stronger trust with your team</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </section>

            <section class="mc-landing-section mc-action-section mc-employee-action">
                <div class="mc-container">
                    <div class="mc-action-content">
                        <div class="mc-action-text">
                            <span class="mc-badge">AI-Powered</span>
                            <h2>From Insight to Action</h2>
                            <p class="mc-lead-text">After completing your assessments, our AI coach generates personalized "Minimum Viable Experiments"—small, practical growth challenges tailored to your unique strengths, motivations, and development areas.</p>
                            <div class="mc-action-features">
                                <div class="mc-feature-item">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <polyline points="20 6 9 17 4 12"></polyline>
                                    </svg>
                                    <span>Customized to your profile</span>
                                </div>
                                <div class="mc-feature-item">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <polyline points="20 6 9 17 4 12"></polyline>
                                    </svg>
                                    <span>Actionable in your daily work</span>
                                </div>
                                <div class="mc-feature-item">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <polyline points="20 6 9 17 4 12"></polyline>
                                    </svg>
                                    <span>Science-backed recommendations</span>
                                </div>
                            </div>
                        </div>
                        <div class="mc-action-visual">
                            <div class="mc-visual-placeholder">
                                <svg viewBox="0 0 200 200" fill="none">
                                    <circle cx="100" cy="100" r="80" stroke="rgba(255, 255, 255, 0.15)" stroke-width="2"/>
                                    <circle cx="100" cy="100" r="60" stroke="rgba(255, 255, 255, 0.15)" stroke-width="2"/>
                                    <circle cx="100" cy="100" r="40" stroke="rgba(255, 255, 255, 0.15)" stroke-width="2"/>
                                    <circle cx="100" cy="100" r="8" fill="rgba(255, 255, 255, 0.9)"/>
                                    <line x1="100" y1="100" x2="140" y2="60" stroke="rgba(255, 255, 255, 0.9)" stroke-width="2"/>
                                    <circle cx="140" cy="60" r="6" fill="rgba(255, 255, 255, 0.9)"/>
                                    <line x1="100" y1="100" x2="60" y2="60" stroke="rgba(255, 255, 255, 0.9)" stroke-width="2"/>
                                    <circle cx="60" cy="60" r="6" fill="rgba(255, 255, 255, 0.9)"/>
                                    <line x1="100" y1="100" x2="140" y2="140" stroke="rgba(255, 255, 255, 0.9)" stroke-width="2"/>
                                    <circle cx="140" cy="140" r="6" fill="rgba(255, 255, 255, 0.9)"/>
                                    <line x1="100" y1="100" x2="60" y2="140" stroke="rgba(255, 255, 255, 0.9)" stroke-width="2"/>
                                    <circle cx="60" cy="140" r="6" fill="rgba(255, 255, 255, 0.9)"/>
                                </svg>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="mc-landing-section mc-final-cta mc-employee-cta">
                <div class="mc-container mc-container-narrow">
                    <div class="mc-cta-box">
                        <?php if ($has_invite): ?>
                            <h2>Ready to Get Started?</h2>
                            <p class="mc-cta-subtitle"><?php echo $company_name ? esc_html($company_name) . ' is' : 'Your employer is'; ?> investing in your growth. Take the first step today.</p>
                            <div class="mc-cta-buttons">
                                <a href="<?php echo esc_url($button_url); ?>" class="mc-button mc-button-primary mc-button-large"><?php echo esc_html($button_text); ?></a>
                            </div>
                            <p class="mc-cta-note">Completely confidential • 15 minutes • Results shared with you first</p>
                        <?php else: ?>
                            <h2>Ready to Unlock Your Potential?</h2>
                            <p class="mc-cta-subtitle">Join thousands discovering their unique strengths and accelerating their career growth.</p>
                            <div class="mc-cta-buttons">
                                <a href="<?php echo esc_url($button_url); ?>" class="mc-button mc-button-primary mc-button-large"><?php echo esc_html($button_text); ?></a>
                            </div>
                            <p class="mc-cta-note">Free to use • 15 minutes • Completely confidential</p>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <footer class="mc-landing-footer">
                <div class="mc-container">
                    <p>© <?php echo date('Y'); ?> What You're Good At. All rights reserved.</p>
                </div>
            </footer>
        </div>
        <?php
        return ob_get_clean();
    }
}
