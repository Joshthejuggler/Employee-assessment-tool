<?php
if (!defined('ABSPATH'))
    exit;

/**
 * Super Admin Dashboard for managing employers and subscriptions.
 */
class MC_Super_Admin
{
    public function __construct()
    {
        if (is_admin()) {
            add_action('admin_menu', [$this, 'add_admin_menu'], 8);
            add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);

            // AJAX handlers
            add_action('wp_ajax_mc_create_employer', [$this, 'ajax_create_employer']);
            add_action('wp_ajax_mc_update_employer_status', [$this, 'ajax_update_employer_status']);
            add_action('wp_ajax_mc_delete_employer', [$this, 'ajax_delete_employer']);
            add_action('wp_ajax_mc_send_employer_invite', [$this, 'ajax_send_employer_invite']);
            add_action('wp_ajax_delete_test_user', [$this, 'ajax_delete_test_user']);
        }
    }

    /**
     * Add admin menu for super admin.
     */
    public function add_admin_menu()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        add_menu_page(
            'Super Admin',
            'Super Admin',
            'manage_options',
            'mc-super-admin',
            [$this, 'render_dashboard'],
            'dashicons-admin-multisite',
            3
        );

        add_submenu_page(
            'mc-super-admin',
            'Employer Management',
            'Employers',
            'manage_options',
            'mc-super-admin',
            [$this, 'render_dashboard']
        );

        add_submenu_page(
            'mc-super-admin',
            'Subscription Management',
            'Subscriptions',
            'manage_options',
            'mc-super-admin-subscriptions',
            [$this, 'render_subscriptions']
        );


    }

    /**
     * Renders the admin testing page.
     */
    public function render_admin_testing_page()
    {
        require_once MC_QUIZ_PLATFORM_PATH . 'admin-testing-page.php';
    }

    /**
     * Enqueue admin assets.
     */
    public function enqueue_assets($hook)
    {
        if (strpos($hook, 'mc-super-admin') === false) {
            return;
        }

        wp_enqueue_style(
            'mc-super-admin-css',
            plugin_dir_url(__DIR__) . 'assets/super-admin.css',
            [],
            '1.0.14'
        );

        wp_enqueue_script(
            'mc-super-admin-js',
            plugin_dir_url(__DIR__) . 'assets/super-admin.js',
            ['jquery'],
            '1.0.0',
            true
        );

        wp_localize_script('mc-super-admin-js', 'mcSuperAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('mc_super_admin_nonce'),
        ]);
    }

    /**
     * Render the main dashboard.
     */
    public function render_dashboard()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        // Get all employers
        $employers = get_users([
            'role' => MC_Roles::ROLE_EMPLOYER,
            'orderby' => 'registered',
            'order' => 'DESC'
        ]);

        // Get stats
        $total_employers = count($employers);
        $active_employers = 0;
        $total_employees = 0;

        foreach ($employers as $employer) {
            $status = get_user_meta($employer->ID, 'mc_employer_status', true);
            if ($status === 'active') {
                $active_employers++;
            }

            // Count employees linked to this employer
            $employees = get_users([
                'meta_key' => 'mc_linked_employer_id',
                'meta_value' => $employer->ID,
                'fields' => 'ID'
            ]);
            $total_employees += count($employees);
        }

        ?>
        <div class="wrap mc-super-admin-wrap">
            <h1 class="mc-super-admin-title">
                <span class="dashicons dashicons-admin-multisite"></span>
                Super Admin Dashboard
            </h1>

            <!-- Stats Cards -->
            <div class="mc-stats-grid">
                <div class="mc-stat-card">
                    <div class="mc-stat-icon mc-stat-primary">
                        <span class="dashicons dashicons-businessman"></span>
                    </div>
                    <div class="mc-stat-content">
                        <div class="mc-stat-value"><?php echo esc_html($total_employers); ?></div>
                        <div class="mc-stat-label">Total Employers</div>
                    </div>
                </div>
                <div class="mc-stat-card">
                    <div class="mc-stat-icon mc-stat-success">
                        <span class="dashicons dashicons-yes-alt"></span>
                    </div>
                    <div class="mc-stat-content">
                        <div class="mc-stat-value"><?php echo esc_html($active_employers); ?></div>
                        <div class="mc-stat-label">Active Employers</div>
                    </div>
                </div>
                <div class="mc-stat-card">
                    <div class="mc-stat-icon mc-stat-info">
                        <span class="dashicons dashicons-groups"></span>
                    </div>
                    <div class="mc-stat-content">
                        <div class="mc-stat-value"><?php echo esc_html($total_employees); ?></div>
                        <div class="mc-stat-label">Total Employees</div>
                    </div>
                </div>
                <div class="mc-stat-card">
                    <div class="mc-stat-icon mc-stat-warning">
                        <span class="dashicons dashicons-money-alt"></span>
                    </div>
                    <div class="mc-stat-content">
                        <div class="mc-stat-value">$0</div>
                        <div class="mc-stat-label">Monthly Revenue</div>
                    </div>
                </div>
            </div>

            <!-- Actions Bar -->
            <div class="mc-actions-bar">
                <button type="button" class="button button-primary button-large mc-btn-create-employer">
                    <span class="dashicons dashicons-plus-alt"></span>
                    Invite New Employer
                </button>
            </div>

            <!-- Employers Table -->
            <div class="mc-card">
                <div class="mc-card-header">
                    <h2>Employer Management</h2>
                    <div class="mc-search-box">
                        <input type="text" id="mc-employer-search" placeholder="Search employers..." class="mc-search-input">
                    </div>
                </div>
                <div class="mc-table-container">
                    <table class="wp-list-table widefat fixed striped mc-employers-table">
                        <thead>
                            <tr>
                                <th>Company</th>
                                <th>Contact</th>
                                <th>Email</th>
                                <th>
                                    Employees
                                    <span class="mc-tooltip-icon" data-tooltip="Active (Logged in) / Invited (Total)">
                                        <span class="dashicons dashicons-info"></span>
                                    </span>
                                </th>
                                <th>Status</th>
                                <th>Subscription</th>
                                <th>Registered</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($employers)): ?>
                                <tr>
                                    <td colspan="8" class="mc-empty-state">
                                        <div class="mc-empty-icon">
                                            <span class="dashicons dashicons-businessman"></span>
                                        </div>
                                        <p>No employers yet. Create your first employer invitation!</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($employers as $employer):
                                    $company_name = get_user_meta($employer->ID, 'mc_company_name', true);
                                    $status = get_user_meta($employer->ID, 'mc_employer_status', true) ?: 'pending';
                                    $subscription = get_user_meta($employer->ID, 'mc_subscription_plan', true) ?: 'free';

                                    $employees = get_users([
                                        'meta_key' => 'mc_linked_employer_id',
                                        'meta_value' => $employer->ID,
                                        'fields' => 'all'
                                    ]);
                                    $total_employees = count($employees);
                                    $active_employees = 0;

                                    foreach ($employees as $employee) {
                                        if ($this->is_user_active($employee->ID)) {
                                            $active_employees++;
                                        }
                                    }
                                    ?>
                                    <tr data-employer-id="<?php echo esc_attr($employer->ID); ?>">
                                        <td>
                                            <strong><?php echo esc_html($company_name ?: 'N/A'); ?></strong>
                                        </td>
                                        <td><?php echo esc_html($employer->display_name); ?></td>
                                        <td>
                                            <a href="mailto:<?php echo esc_attr($employer->user_email); ?>">
                                                <?php echo esc_html($employer->user_email); ?>
                                            </a>
                                        </td>
                                        <td>
                                            <div class="mc-employee-count-wrapper">
                                                <span class="mc-badge mc-badge-count">
                                                    <?php echo esc_html($active_employees . ' / ' . $total_employees); ?>
                                                </span>
                                                <?php if ($total_employees > 0): ?>
                                                    <button type="button" class="mc-accordion-toggle" title="View Employees">
                                                        <span class="dashicons dashicons-arrow-down-alt2"></span>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php
                                            $status_class = 'mc-badge-';
                                            $status_label = ucfirst($status);
                                            switch ($status) {
                                                case 'active':
                                                    $status_class .= 'success';
                                                    break;
                                                case 'pending':
                                                    $status_class .= 'warning';
                                                    break;
                                                case 'suspended':
                                                    $status_class .= 'error';
                                                    break;
                                                default:
                                                    $status_class .= 'default';
                                            }
                                            ?>
                                            <span class="mc-badge <?php echo esc_attr($status_class); ?>">
                                                <?php echo esc_html($status_label); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="mc-badge mc-badge-default">
                                                <?php echo esc_html(ucfirst($subscription)); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php echo esc_html(date('M j, Y', strtotime($employer->user_registered))); ?>
                                        </td>
                                        <td>
                                            <div class="mc-row-actions">
                                                <?php if (function_exists('user_switching_get_switch_url')): ?>
                                                    <a href="<?php echo esc_url(user_switching_get_switch_url($employer)); ?>"
                                                        class="mc-action-btn mc-btn-switch" title="Run As User">
                                                        <span class="dashicons dashicons-migrate"></span>
                                                    </a>
                                                <?php endif; ?>
                                                <button type="button" class="mc-action-btn mc-btn-view"
                                                    data-employer-id="<?php echo esc_attr($employer->ID); ?>" title="View Details">
                                                    <span class="dashicons dashicons-visibility"></span>
                                                </button>
                                                <button type="button" class="mc-action-btn mc-btn-send-invite"
                                                    data-employer-id="<?php echo esc_attr($employer->ID); ?>"
                                                    data-employer-email="<?php echo esc_attr($employer->user_email); ?>"
                                                    title="Send Invite">
                                                    <span class="dashicons dashicons-email"></span>
                                                </button>
                                                <button type="button" class="mc-action-btn mc-btn-edit"
                                                    data-employer-id="<?php echo esc_attr($employer->ID); ?>" title="Edit">
                                                    <span class="dashicons dashicons-edit"></span>
                                                </button>
                                                <?php
                                                $switch_url = false;
                                                if (function_exists('user_switching_get_switch_url')) {
                                                    $switch_url = user_switching_get_switch_url($employer);
                                                } else {
                                                    $switch_url = add_query_arg(array(
                                                        'action' => 'switch_to_user',
                                                        'user_id' => $employer->ID,
                                                        '_wpnonce' => wp_create_nonce('switch_to_user_' . $employer->ID)
                                                    ), admin_url('users.php'));
                                                }

                                                if ($switch_url): ?>
                                                    <a href="<?php echo esc_url($switch_url); ?>" class="mc-action-btn mc-btn-switch"
                                                        title="Run As User">
                                                        <span class="dashicons dashicons-migrate"></span>
                                                    </a>
                                                <?php endif; ?>
                                                <button type="button" class="mc-action-btn mc-btn-delete"
                                                    data-employer-id="<?php echo esc_attr($employer->ID); ?>" title="Delete">
                                                    <span class="dashicons dashicons-trash"></span>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <!-- Employee Details Row -->
                                    <tr class="mc-details-row" style="display: none;">
                                        <td colspan="8">
                                            <div class="mc-details-content">
                                                <h4>Employees</h4>
                                                <?php if (empty($employees)): ?>
                                                    <p>No employees found.</p>
                                                <?php else: ?>
                                                    <div class="mc-nested-grid">
                                                        <div class="mc-grid-head">Name</div>
                                                        <div class="mc-grid-head">Email</div>
                                                        <div class="mc-grid-head">Status</div>
                                                        <div class="mc-grid-head">Last Login</div>
                                                        <div class="mc-grid-head">Actions</div>

                                                        <?php foreach ($employees as $employee):
                                                            $last_login = get_user_meta($employee->ID, 'mc_last_login', true);
                                                            $is_active = $this->is_user_active($employee->ID);
                                                            $emp_status = $is_active ? 'Active' : 'Invited';
                                                            $emp_status_class = $is_active ? 'mc-badge-success' : 'mc-badge-warning';
                                                            ?>
                                                            <div class="mc-grid-cell"><?php echo esc_html($employee->display_name); ?></div>
                                                            <div class="mc-grid-cell"><?php echo esc_html($employee->user_email); ?></div>
                                                            <div class="mc-grid-cell">
                                                                <span class="mc-badge <?php echo esc_attr($emp_status_class); ?>">
                                                                    <?php echo esc_html($emp_status); ?>
                                                                </span>
                                                            </div>
                                                            <div class="mc-grid-cell">
                                                                <?php echo $last_login ? esc_html(date('M j, Y', strtotime($last_login))) : 'Never'; ?>
                                                            </div>
                                                            <div class="mc-grid-cell">
                                                                <?php
                                                                $switch_url = add_query_arg(array(
                                                                    'action' => 'switch_to_user',
                                                                    'user_id' => $employee->ID,
                                                                    '_wpnonce' => wp_create_nonce('switch_to_user_' . $employee->ID)
                                                                ), admin_url('users.php'));
                                                                ?>
                                                                <a href="<?php echo esc_url($switch_url); ?>"
                                                                    class="mc-action-btn mc-btn-switch" title="Run As User">
                                                                    <span class="dashicons dashicons-migrate"></span>
                                                                </a>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Create Employer Modal -->
        <div id="mc-create-employer-modal" class="mc-modal" style="display: none;">
            <div class="mc-modal-overlay"></div>
            <div class="mc-modal-content">
                <div class="mc-modal-header">
                    <h2>Invite New Employer</h2>
                    <button type="button" class="mc-modal-close">
                        <span class="dashicons dashicons-no-alt"></span>
                    </button>
                </div>
                <div class="mc-modal-body">
                    <form id="mc-create-employer-form">
                        <div class="mc-form-row">
                            <div class="mc-form-group">
                                <label for="employer_email">Email Address <span class="required">*</span></label>
                                <input type="email" id="employer_email" name="employer_email" required class="mc-input">
                            </div>
                        </div>
                        <div class="mc-form-row">
                            <div class="mc-form-group">
                                <label for="employer_first_name">First Name</label>
                                <input type="text" id="employer_first_name" name="employer_first_name" class="mc-input">
                            </div>
                            <div class="mc-form-group">
                                <label for="employer_last_name">Last Name</label>
                                <input type="text" id="employer_last_name" name="employer_last_name" class="mc-input">
                            </div>
                        </div>
                        <div class="mc-form-row">
                            <div class="mc-form-group">
                                <label for="company_name">Company Name</label>
                                <input type="text" id="company_name" name="company_name" class="mc-input">
                            </div>
                        </div>
                        <div class="mc-form-row">
                            <div class="mc-form-group">
                                <label class="mc-checkbox-label">
                                    <input type="checkbox" id="send_invite" name="send_invite" checked>
                                    <span>Send invitation email immediately</span>
                                </label>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="mc-modal-footer">
                    <button type="button" class="button mc-modal-close">Cancel</button>
                    <button type="button" class="button button-primary" id="mc-submit-employer">Create Employer</button>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render subscriptions page.
     */
    public function render_subscriptions()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        ?>
        <div class="wrap mc-super-admin-wrap">
            <h1 class="mc-super-admin-title">
                <span class="dashicons dashicons-money-alt"></span>
                Subscription Management
            </h1>

            <div class="mc-card">
                <div class="mc-card-header">
                    <h2>Subscription Plans</h2>
                    <button type="button" class="button button-primary">
                        <span class="dashicons dashicons-plus-alt"></span>
                        Add Plan
                    </button>
                </div>
                <div class="mc-subscription-plans">
                    <div class="mc-plan-card">
                        <div class="mc-plan-header">
                            <h3>Free</h3>
                            <div class="mc-plan-price">$0<span>/month</span></div>
                        </div>
                        <div class="mc-plan-features">
                            <ul>
                                <li><span class="dashicons dashicons-yes"></span> Up to 5 employees</li>
                                <li><span class="dashicons dashicons-yes"></span> Basic assessments</li>
                                <li><span class="dashicons dashicons-yes"></span> Email support</li>
                            </ul>
                        </div>
                        <div class="mc-plan-stats">
                            <span class="mc-badge mc-badge-info">0 Active</span>
                        </div>
                    </div>

                    <div class="mc-plan-card mc-plan-featured">
                        <div class="mc-plan-badge">Popular</div>
                        <div class="mc-plan-header">
                            <h3>Professional</h3>
                            <div class="mc-plan-price">$99<span>/month</span></div>
                        </div>
                        <div class="mc-plan-features">
                            <ul>
                                <li><span class="dashicons dashicons-yes"></span> Up to 50 employees</li>
                                <li><span class="dashicons dashicons-yes"></span> All assessments</li>
                                <li><span class="dashicons dashicons-yes"></span> AI-powered insights</li>
                                <li><span class="dashicons dashicons-yes"></span> Priority support</li>
                            </ul>
                        </div>
                        <div class="mc-plan-stats">
                            <span class="mc-badge mc-badge-info">0 Active</span>
                        </div>
                    </div>

                    <div class="mc-plan-card">
                        <div class="mc-plan-header">
                            <h3>Enterprise</h3>
                            <div class="mc-plan-price">Custom</div>
                        </div>
                        <div class="mc-plan-features">
                            <ul>
                                <li><span class="dashicons dashicons-yes"></span> Unlimited employees</li>
                                <li><span class="dashicons dashicons-yes"></span> Custom integrations</li>
                                <li><span class="dashicons dashicons-yes"></span> Dedicated support</li>
                                <li><span class="dashicons dashicons-yes"></span> White-label options</li>
                            </ul>
                        </div>
                        <div class="mc-plan-stats">
                            <span class="mc-badge mc-badge-info">0 Active</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mc-card" style="margin-top: 32px;">
                <div class="mc-card-header">
                    <h2>Recent Transactions</h2>
                </div>
                <div class="mc-empty-state" style="padding: 60px;">
                    <div class="mc-empty-icon">
                        <span class="dashicons dashicons-money-alt"></span>
                    </div>
                    <p>No transactions yet. Payment integration coming soon.</p>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX: Create new employer.
     */
    public function ajax_create_employer()
    {
        check_ajax_referer('mc_super_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $email = sanitize_email($_POST['employer_email'] ?? '');
        $first_name = sanitize_text_field($_POST['employer_first_name'] ?? '');
        $last_name = sanitize_text_field($_POST['employer_last_name'] ?? '');
        $company_name = sanitize_text_field($_POST['company_name'] ?? '');
        $send_invite = isset($_POST['send_invite']) && $_POST['send_invite'] === 'true';

        if (empty($email) || !is_email($email)) {
            wp_send_json_error(['message' => 'Valid email address is required']);
        }

        // Check if user already exists
        if (email_exists($email)) {
            wp_send_json_error(['message' => 'A user with this email already exists']);
        }

        // Generate random password
        $password = wp_generate_password(12, true, true);

        // Create user
        $user_id = wp_create_user($email, $password, $email);

        if (is_wp_error($user_id)) {
            wp_send_json_error(['message' => $user_id->get_error_message()]);
        }

        // Update user meta
        if ($first_name) {
            update_user_meta($user_id, 'first_name', $first_name);
        }
        if ($last_name) {
            update_user_meta($user_id, 'last_name', $last_name);
        }
        if ($company_name) {
            update_user_meta($user_id, 'mc_company_name', $company_name);
        }

        // Set role to employer
        $user = new WP_User($user_id);
        $user->set_role(MC_Roles::ROLE_EMPLOYER);

        // Set status
        update_user_meta($user_id, 'mc_employer_status', 'pending');
        update_user_meta($user_id, 'mc_subscription_plan', 'free');

        // Generate share code
        $share_code = strtoupper(wp_generate_password(8, false));
        update_user_meta($user_id, 'mc_company_share_code', $share_code);

        // Send invite email if requested
        if ($send_invite) {
            $this->send_employer_welcome_email($user_id, $email, $password);
        }

        wp_send_json_success([
            'message' => 'Employer created successfully',
            'user_id' => $user_id,
            'redirect' => admin_url('admin.php?page=mc-super-admin')
        ]);
    }

    /**
     * AJAX: Update employer status.
     */
    public function ajax_update_employer_status()
    {
        check_ajax_referer('mc_super_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $employer_id = intval($_POST['employer_id'] ?? 0);
        $status = sanitize_text_field($_POST['status'] ?? '');

        if (!$employer_id || !in_array($status, ['active', 'pending', 'suspended'])) {
            wp_send_json_error(['message' => 'Invalid parameters']);
        }

        update_user_meta($employer_id, 'mc_employer_status', $status);

        wp_send_json_success(['message' => 'Status updated successfully']);
    }

    /**
     * AJAX: Delete employer.
     */
    public function ajax_delete_employer()
    {
        check_ajax_referer('mc_super_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $employer_id = intval($_POST['employer_id'] ?? 0);

        if (!$employer_id) {
            wp_send_json_error(['message' => 'Invalid employer ID']);
        }

        // Check if employer has employees
        $employees = get_users([
            'meta_key' => 'mc_linked_employer_id',
            'meta_value' => $employer_id,
            'fields' => 'ID'
        ]);

        if (!empty($employees)) {
            wp_send_json_error([
                'message' => 'Cannot delete employer with linked employees. Please remove employees first.'
            ]);
        }

        require_once(ABSPATH . 'wp-admin/includes/user.php');
        $result = wp_delete_user($employer_id);

        if (!$result) {
            wp_send_json_error(['message' => 'Failed to delete employer']);
        }

        wp_send_json_success(['message' => 'Employer deleted successfully']);
    }

    /**
     * AJAX: Send employer invite.
     */
    public function ajax_send_employer_invite()
    {
        check_ajax_referer('mc_super_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $employer_id = intval($_POST['employer_id'] ?? 0);

        if (!$employer_id) {
            wp_send_json_error(['message' => 'Invalid employer ID']);
        }

        $user = get_userdata($employer_id);
        if (!$user) {
            wp_send_json_error(['message' => 'Employer not found']);
        }

        // Send invite email
        $this->send_employer_welcome_email($employer_id, $user->user_email);

        wp_send_json_success(['message' => 'Invitation sent successfully']);
    }

    /**
     * Check if a user is active (logged in or completed a quiz).
     *
     * @param int $user_id User ID
     * @return bool True if active
     */
    private function is_user_active($user_id)
    {
        // Check if user has logged in
        if (get_user_meta($user_id, 'mc_last_login', true)) {
            return true;
        }

        // Check if user has completed any quiz
        if (class_exists('MC_Funnel')) {
            $completion = MC_Funnel::get_completion_status($user_id);
            foreach ($completion as $is_complete) {
                if ($is_complete) {
                    return true;
                }
            }
        } else {
            // Fallback if MC_Funnel not available
            $quiz_metas = ['miq_quiz_results', 'cdt_quiz_results', 'bartle_quiz_results'];
            foreach ($quiz_metas as $meta_key) {
                if (get_user_meta($user_id, $meta_key, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Send welcome email to employer.
     */
    private function send_employer_welcome_email($user_id, $email, $password = null)
    {
        $user = get_userdata($user_id);
        $company_name = get_user_meta($user_id, 'mc_company_name', true);

        $onboarding_url = home_url('/employer-onboarding/');
        if (class_exists('MC_Funnel')) {
            $page = MC_Funnel::find_page_by_shortcode('employer_onboarding');
            if ($page) {
                $onboarding_url = get_permalink($page);
            }
        }

        $subject = 'Welcome to What You\'re Good At - Employee Assessment Platform';
        $first_name = $user->first_name ?: 'there';

        // Build HTML email
        $message = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <meta name="color-scheme" content="light dark">
            <meta name="supported-color-schemes" content="light dark">
            <title>' . esc_html($subject) . '</title>
            <style>
                @media (prefers-color-scheme: dark) {
                    .email-body { background-color: #0f172a !important; }
                    .email-card { background-color: #1e293b !important; border: 1px solid #334155 !important; }
                    .email-text { color: #e2e8f0 !important; }
                    .email-text-secondary { color: #94a3b8 !important; }
                    .email-box { background-color: #334155 !important; border-color: #475569 !important; }
                    .email-info-box { background-color: #1e3a5f !important; border-left-color: #3b82f6 !important; }
                    .email-footer { background-color: #1e293b !important; border-top-color: #334155 !important; }
                    .email-footer-text { color: #64748b !important; }
                }
            </style>
        </head>
        <body class="email-body" style="margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, \'Helvetica Neue\', Arial, sans-serif; background-color: #f8fafc; line-height: 1.6;">
            <table width="100%" cellpadding="0" cellspacing="0" border="0" class="email-body" style="background-color: #f8fafc; padding: 40px 20px;">
                <tr>
                    <td align="center">
                        <table width="600" cellpadding="0" cellspacing="0" border="0" class="email-card" style="max-width: 600px; background-color: #ffffff; border-radius: 12px; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); overflow: hidden;">
                            <!-- Header -->
                            <tr>
                                <td style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 40px 40px 30px; text-align: center;">
                                    <h1 style="margin: 0; font-size: 28px; font-weight: 700; color: #ffffff; letter-spacing: -0.02em;">Welcome to What You\'re Good At</h1>
                                    <p style="margin: 12px 0 0; font-size: 16px; color: rgba(255, 255, 255, 0.95);">Employee Assessment Platform</p>
                                </td>
                            </tr>
                            
                            <!-- Body -->
                            <tr>
                                <td style="padding: 40px;">
                                    <p class="email-text" style="margin: 0 0 20px; font-size: 16px; color: #0f172a;">Hello ' . esc_html($first_name) . ',</p>
                                    
                                    <p class="email-text-secondary" style="margin: 0 0 24px; font-size: 16px; color: #475569; line-height: 1.6;">Your employer account has been created. You\'re now ready to unlock the full potential of your team through comprehensive psychometric assessments.</p>
                                    ';

        if ($password) {
            $magic_link = '';
            if (class_exists('MC_Magic_Login')) {
                $magic_link = MC_Magic_Login::generate_magic_link($user_id);
            }

            $message .= '
                                    <div class="email-box" style="background-color: #f8fafc; border: 2px solid #e2e8f0; border-radius: 8px; padding: 24px; margin: 24px 0;">
                                        <h2 class="email-text" style="margin: 0 0 16px; font-size: 18px; font-weight: 700; color: #0f172a;">🔑 Login Credentials</h2>
                                        <p class="email-text-secondary" style="margin: 0 0 8px; font-size: 14px; color: #64748b;"><strong class="email-text" style="color: #0f172a;">Email:</strong> ' . esc_html($email) . '</p>
                                        <p class="email-text-secondary" style="margin: 0 0 8px; font-size: 14px; color: #64748b;"><strong class="email-text" style="color: #0f172a;">Password:</strong> <code style="background: rgba(37, 99, 235, 0.1); padding: 2px 8px; border-radius: 4px; font-family: monospace; color: #2563eb;">' . esc_html($password) . '</code></p>
                                        
                                        ' . ($magic_link ? '
                                        <div style="margin-top: 20px; padding-top: 20px; border-top: 1px dashed #cbd5e1;">
                                            <p class="email-text-secondary" style="margin: 0 0 12px; font-size: 14px; color: #64748b;">Or log in instantly without a password:</p>
                                            <a href="' . esc_url($magic_link) . '" style="display: inline-block; background-color: #ffffff; color: #2563eb; border: 1px solid #2563eb; text-decoration: none; padding: 8px 16px; border-radius: 6px; font-weight: 600; font-size: 14px;">✨ Magic Login Link</a>
                                            <p class="email-text-secondary" style="margin: 8px 0 0; font-size: 12px; color: #94a3b8;">(Valid for 7 days)</p>
                                        </div>
                                        ' : '') . '

                                        <p style="margin: 16px 0 0; font-size: 13px; color: #f59e0b; font-weight: 500;">⚠️ Please change your password after your first login.</p>
                                    </div>
                                    ';
        }

        $message .= '
                                    <div style="margin: 32px 0;">
                                        <h2 class="email-text" style="margin: 0 0 16px; font-size: 20px; font-weight: 700; color: #0f172a;">🚀 Get Started</h2>
                                        <p class="email-text-secondary" style="margin: 0 0 20px; font-size: 15px; color: #475569;">Complete your company profile to begin inviting team members:</p>
                                        <a href="' . esc_url($onboarding_url) . '" style="display: inline-block; background: linear-gradient(135deg, #2563eb 0%, #7c3aed 100%); color: #ffffff; text-decoration: none; padding: 14px 32px; border-radius: 8px; font-weight: 600; font-size: 16px; box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);">Complete Your Profile →</a>
                                    </div>
                                    
                                    <div class="email-info-box" style="background-color: #f0f9ff; border-left: 4px solid #2563eb; padding: 20px; margin: 32px 0; border-radius: 4px;">
                                        <h3 class="email-text" style="margin: 0 0 12px; font-size: 16px; font-weight: 700; color: #0f172a;">Once set up, you\'ll be able to:</h3>
                                        <ul class="email-text-secondary" style="margin: 0; padding: 0 0 0 20px; color: #475569; font-size: 15px;">
                                            <li style="margin-bottom: 8px;">✅ Invite employees to take assessments</li>
                                            <li style="margin-bottom: 8px;">✅ View comprehensive team insights</li>
                                            <li style="margin-bottom: 8px;">✅ Generate AI-powered development recommendations</li>
                                        </ul>
                                    </div>
                                    
                                    <p class="email-text-secondary" style="margin: 32px 0 0; font-size: 15px; color: #64748b;">If you have any questions, please don\'t hesitate to reach out.</p>
                                </td>
                            </tr>
                            
                            <!-- Footer -->
                            <tr>
                                <td class="email-footer" style="background-color: #f8fafc; padding: 32px 40px; text-align: center; border-top: 1px solid #e2e8f0;">
                                    <p class="email-text-secondary" style="margin: 0 0 8px; font-size: 14px; color: #64748b; font-weight: 600;">Best regards,</p>
                                    <p class="email-text" style="margin: 0; font-size: 14px; color: #0f172a; font-weight: 700;">The What You\'re Good At Team</p>
                                </td>
                            </tr>
                        </table>
                        
                        <!-- Footer Note -->
                        <table width="600" cellpadding="0" cellspacing="0" border="0" style="max-width: 600px; margin-top: 20px;">
                            <tr>
                                <td class="email-footer-text" style="text-align: center; padding: 20px; font-size: 12px; color: #94a3b8;">
                                    <p style="margin: 0;">© ' . date('Y') . ' What You\'re Good At. All rights reserved.</p>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </body>
        </html>
        ';

        // Set content type to HTML
        add_filter('wp_mail_content_type', function () {
            return 'text/html';
        });

        wp_mail($email, $subject, $message);

        // Reset content type
        remove_filter('wp_mail_content_type', function () {
            return 'text/html';
        });
    }

    /**
     * AJAX: Delete test user.
     */
    public function ajax_delete_test_user()
    {
        check_ajax_referer('mc_admin_testing_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $user_id = intval($_POST['user_id'] ?? 0);

        if (!$user_id) {
            wp_send_json_error(['message' => 'Invalid user ID']);
        }

        require_once(ABSPATH . 'wp-admin/includes/user.php');
        $result = wp_delete_user($user_id);

        if (!$result) {
            wp_send_json_error(['message' => 'Failed to delete user']);
        }

        wp_send_json_success(['message' => 'User deleted successfully']);
    }
}
