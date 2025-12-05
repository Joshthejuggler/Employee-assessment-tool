<?php
/**
 * Admin Testing Page
 * 
 * Quick testing interface for creating employers/employees and testing the assessment workflow.
 * Admin-only access with auto-fill and bulk delete features.
 */

if (!defined('ABSPATH')) {
    exit;
}

// Handle User Switching Fallback
add_action('admin_init', 'mc_handle_user_switch_fallback');

function mc_handle_user_switch_fallback() {
    if (is_admin() && isset($_GET['action']) && $_GET['action'] === 'mc_switch_user' && isset($_GET['user_id']) && isset($_GET['_wpnonce'])) {
        $user_id = intval($_GET['user_id']);
        if (wp_verify_nonce($_GET['_wpnonce'], 'mc_switch_user_' . $user_id) && current_user_can('manage_options')) {
            wp_set_auth_cookie($user_id);
            
            // Determine redirect URL based on user role
            $user = get_userdata($user_id);
            $redirect_url = home_url();
            
            if ($user && !is_wp_error($user)) {
                $roles = (array) $user->roles;
                if (in_array('administrator', $roles)) {
                    $redirect_url = admin_url('admin.php?page=mc-super-admin');
                } elseif (class_exists('MC_Roles') && in_array(MC_Roles::ROLE_EMPLOYER, $roles)) {
                    $redirect_url = home_url('/employer-dashboard/');
                } elseif (class_exists('MC_Roles') && in_array(MC_Roles::ROLE_EMPLOYEE, $roles)) {
                    $redirect_url = home_url('/quiz-dashboard/');
                }
            }
            
            wp_redirect($redirect_url);
            exit;
        }
    }
}

function mc_render_admin_testing_page()
{
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized access');
    }

    // Handle AJAX delete request
    if (isset($_POST['action']) && $_POST['action'] === 'delete_test_user' && isset($_POST['user_id'])) {
        check_admin_referer('mc_admin_testing_nonce', 'nonce');
        $user_id = intval($_POST['user_id']);
        require_once(ABSPATH . 'wp-admin/includes/user.php');
        if (wp_delete_user($user_id)) {
            wp_send_json_success(['message' => 'User deleted successfully']);
        } else {
            wp_send_json_error(['message' => 'Failed to delete user']);
        }
        wp_die();
    }

    $message = '';
    $message_type = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_admin_referer('mc_admin_testing_action', 'mc_admin_testing_nonce');

        // Handle bulk delete
        if (isset($_POST['bulk_delete_test_users'])) {
            $base_email = sanitize_email($_POST['base_email_for_delete']);
            if (!empty($base_email) && is_email($base_email)) {
                list($local, $domain) = explode('@', $base_email);
                $all_users = get_users(['fields' => ['ID', 'user_email']]);
                $deleted_count = 0;
                foreach ($all_users as $user) {
                    if (preg_match('/^' . preg_quote($local, '/') . '\+.+@' . preg_quote($domain, '/') . '$/', $user->user_email)) {
                        require_once(ABSPATH . 'wp-admin/includes/user.php');
                        if (wp_delete_user($user->ID)) {
                            $deleted_count++;
                        }
                    }
                }
                $message = "Deleted $deleted_count test user(s) matching pattern: {$local}+*@{$domain}";
                $message_type = 'success';
            } else {
                $message = 'Please enter a valid base email address';
                $message_type = 'error';
            }
        }

        if (isset($_POST['create_employer'])) {
            $email = sanitize_email($_POST['employer_email']);
            $first_name = sanitize_text_field($_POST['employer_first_name']);
            $last_name = sanitize_text_field($_POST['employer_last_name']);
            $company_name = sanitize_text_field($_POST['employer_company_name']);

            if (empty($email) || !is_email($email)) {
                $message = 'Valid email address is required';
                $message_type = 'error';
            } elseif (email_exists($email)) {
                $message = 'A user with this email already exists';
                $message_type = 'error';
            } else {
                $password = wp_generate_password(12, true, true);
                $user_id = wp_create_user($email, $password, $email);
                if (is_wp_error($user_id)) {
                    $message = 'Error creating employer: ' . $user_id->get_error_message();
                    $message_type = 'error';
                } else {
                    if ($first_name) update_user_meta($user_id, 'first_name', $first_name);
                    if ($last_name) update_user_meta($user_id, 'last_name', $last_name);
                    if ($company_name) update_user_meta($user_id, 'mc_company_name', $company_name);
                    update_user_meta($user_id, 'mc_age_group', 'adult');
                    delete_user_meta($user_id, 'mc_needs_age_group');
                    $user = new WP_User($user_id);
                    $user->set_role(MC_Roles::ROLE_EMPLOYER);
                    update_user_meta($user_id, 'mc_employer_status', 'active');
                    update_user_meta($user_id, 'mc_subscription_plan', 'free');
                    $share_code = strtoupper(wp_generate_password(8, false));
                    update_user_meta($user_id, 'mc_company_share_code', $share_code);
                    $message = "Employer created! Email: $email | Password: $password | Share Code: $share_code";
                    $message_type = 'success';
                }
            }
        }

        if (isset($_POST['create_employee'])) {
            $employer_id = intval($_POST['employer_id']);
            $email = sanitize_email($_POST['employee_email']);
            $first_name = sanitize_text_field($_POST['employee_first_name']);
            $last_name = sanitize_text_field($_POST['employee_last_name']);

            if (empty($employer_id)) {
                $message = 'Please select an employer';
                $message_type = 'error';
            } elseif (empty($email) || !is_email($email)) {
                $message = 'Valid email address is required';
                $message_type = 'error';
            } elseif (email_exists($email)) {
                $message = 'A user with this email already exists';
                $message_type = 'error';
            } else {
                $password = 'password123';
                $user_id = wp_create_user($email, $password, $email);
                if (is_wp_error($user_id)) {
                    $message = 'Error creating employee: ' . $user_id->get_error_message();
                    $message_type = 'error';
                } else {
                    if ($first_name) update_user_meta($user_id, 'first_name', $first_name);
                    if ($last_name) update_user_meta($user_id, 'last_name', $last_name);
                    update_user_meta($user_id, 'mc_age_group', 'adult');
                    delete_user_meta($user_id, 'mc_needs_age_group');
                    $user = new WP_User($user_id);
                    $user->set_role(MC_Roles::ROLE_EMPLOYEE);
                    update_user_meta($user_id, 'mc_linked_employer_id', $employer_id);
                    $invited_employees = get_user_meta($employer_id, 'mc_invited_employees', true);
                    if (!is_array($invited_employees)) $invited_employees = [];
                    $invited_employees[] = ['email' => $email, 'name' => trim($first_name . ' ' . $last_name)];
                    update_user_meta($employer_id, 'mc_invited_employees', $invited_employees);
                    $message = "Employee created! Email: $email | Password: $password";
                    $message_type = 'success';
                }
            }
        }
    }

    $employers = get_users(['role' => MC_Roles::ROLE_EMPLOYER, 'orderby' => 'registered', 'order' => 'DESC', 'number' => 50]);
    $per_page = 10;
    $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $offset = ($current_page - 1) * $per_page;
    $user_query = new WP_User_Query(['orderby' => 'registered', 'order' => 'DESC', 'number' => $per_page, 'offset' => $offset, 'count_total' => true]);
    $recent_users = $user_query->get_results();
    $total_users = $user_query->get_total();
    $total_pages = ceil($total_users / $per_page);
    ?>
    <div class="wrap">
        <h1>🧪 Admin Testing Page</h1>
        <p class="description">Quick testing interface for the employee assessment workflow.</p>

        <?php if ($message): ?>
            <div class="notice notice-<?php echo esc_attr($message_type); ?> is-dismissible">
                <p><?php echo esc_html($message); ?></p>
            </div>
        <?php endif; ?>

        <div class="card" style="margin-top: 20px; background: #e7f3ff; border-left: 4px solid #2563eb;">
            <h2>📧 Base Email Configuration</h2>
            <table class="form-table" style="margin: 0;">
                <tr>
                    <th style="width: 200px;"><label for="base_email">Base Email Address</label></th>
                    <td>
                        <input type="email" id="base_email" class="regular-text" placeholder="your.email@gmail.com">
                        <p class="description">Used for auto-fill. Example: josh@gmail.com → josh+employer1@gmail.com</p>
                    </td>
                </tr>
            </table>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; margin-top: 20px;">
            <div class="card">
                <h2>➕ Employer</h2>
                <form method="post" id="employer-form">
                    <?php wp_nonce_field('mc_admin_testing_action', 'mc_admin_testing_nonce'); ?>
                    <table class="form-table compact-form">
                        <tr><th><label for="employer_email">Email *</label></th><td><input type="email" name="employer_email" id="employer_email" required></td></tr>
                        <tr><th><label for="employer_first_name">First</label></th><td><input type="text" name="employer_first_name" id="employer_first_name"></td></tr>
                        <tr><th><label for="employer_last_name">Last</label></th><td><input type="text" name="employer_last_name" id="employer_last_name"></td></tr>
                        <tr><th><label for="employer_company_name">Company</label></th><td><input type="text" name="employer_company_name" id="employer_company_name"></td></tr>
                    </table>
                    <p class="submit">
                        <button type="button" class="button" onclick="autoFillEmployer()">🎯 Auto-Fill</button>
                        <button type="submit" name="create_employer" class="button button-primary">Create</button>
                    </p>
                </form>
            </div>

            <div class="card">
                <h2>➕ Employee</h2>
                <form method="post" id="employee-form">
                    <?php wp_nonce_field('mc_admin_testing_action', 'mc_admin_testing_nonce'); ?>
                    <table class="form-table compact-form">
                        <tr><th><label for="employer_id">Employer *</label></th>
                            <td><select name="employer_id" id="employer_id" required>
                                <option value="">-- Select --</option>
                                <?php foreach ($employers as $employer): $company = get_user_meta($employer->ID, 'mc_company_name', true) ?: 'No Company'; ?>
                                    <option value="<?php echo esc_attr($employer->ID); ?>"><?php echo esc_html($company); ?></option>
                                <?php endforeach; ?>
                            </select></td>
                        </tr>
                        <tr><th><label for="employee_email">Email *</label></th><td><input type="email" name="employee_email" id="employee_email" required></td></tr>
                        <tr><th><label for="employee_first_name">First</label></th><td><input type="text" name="employee_first_name" id="employee_first_name"></td></tr>
                        <tr><th><label for="employee_last_name">Last</label></th><td><input type="text" name="employee_last_name" id="employee_last_name"></td></tr>
                    </table>
                    <p class="submit">
                        <button type="button" class="button" onclick="autoFillEmployee()">🎯 Auto-Fill</button>
                        <button type="submit" name="create_employee" class="button button-primary">Create</button>
                    </p>
                </form>
            </div>

            <div class="card" style="background: #ffe7e7; border-left: 4px solid #dc2626;">
                <h2>🗑️ Delete Tests</h2>
                <form method="post" onsubmit="return confirm('Delete all test users? This cannot be undone!');">
                    <?php wp_nonce_field('mc_admin_testing_action', 'mc_admin_testing_nonce'); ?>
                    <table class="form-table compact-form">
                        <tr><th><label for="base_email_for_delete">Base Email</label></th>
                            <td>
                                <input type="email" name="base_email_for_delete" id="base_email_for_delete" required>
                                <p class="description" style="margin-top: 5px;">Deletes all matching yourname+*@domain.com</p>
                            </td>
                        </tr>
                    </table>
                    <p class="submit"><button type="submit" name="bulk_delete_test_users" class="button button-secondary">Delete All</button></p>
                </form>
            </div>
        </div>

        <div class="card" style="margin-top: 20px;">
            <h2>👥 Recent Users</h2>
            <table class="wp-list-table widefat fixed striped" id="users-table">
                <thead>
                    <tr>
                        <th>Email</th><th>Name</th><th>Role</th><th>Company/Employer</th><th>Registered</th><th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recent_users)): ?>
                        <tr><td colspan="6" style="text-align: center; padding: 20px;">No users yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($recent_users as $user):
                            $roles = $user->roles;
                            $role = !empty($roles) ? $roles[0] : 'none';
                            $company = '';
                            $employer_id = '';
                            if ($role === MC_Roles::ROLE_EMPLOYER) {
                                $company = get_user_meta($user->ID, 'mc_company_name', true) ?: '-';
                                $employer_id = $user->ID;
                            } elseif ($role === MC_Roles::ROLE_EMPLOYEE) {
                                $employer_id = get_user_meta($user->ID, 'mc_linked_employer_id', true);
                                if ($employer_id) {
                                    $employer = get_userdata($employer_id);
                                    $company = $employer ? $employer->user_email : 'Employer #' . $employer_id;
                                } else {
                                    $company = '-';
                                }
                            }
                            // Use MC_User_Switcher for switch URL
                            if (class_exists('MC_User_Switcher')) {
                                $switch_url = MC_User_Switcher::get_switch_url($user->ID);
                            } else {
                                $switch_url = add_query_arg(['action' => 'mc_switch_user', 'user_id' => $user->ID, '_wpnonce' => wp_create_nonce('mc_switch_user_' . $user->ID)], admin_url('admin.php?page=admin-testing-page'));
                            }
                        ?>
                            <tr>
                                <td><?php echo esc_html($user->user_email); ?></td>
                                <td><?php echo esc_html($user->display_name); ?></td>
                                <td><?php echo esc_html($role); ?></td>
                                <td><?php echo esc_html($company); ?></td>
                                <td><?php echo esc_html(human_time_diff(strtotime($user->user_registered), current_time('timestamp')) . ' ago'); ?></td>
                                <td>
                                    <div class="mc-row-actions">
                                        <a href="<?php echo esc_url($switch_url); ?>" class="mc-action-btn mc-btn-switch" title="Switch To User">
                                            <span class="dashicons dashicons-migrate"></span>
                                        </a>
                                        <a href="#" class="mc-action-btn mc-btn-delete" onclick="deleteTestUser(<?php echo esc_js($user->ID); ?>, '<?php echo esc_js($user->user_email); ?>'); return false;" title="Delete User">
                                            <span class="dashicons dashicons-trash"></span>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            <?php if ($total_pages > 1): ?>
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <span class="displaying-num"><?php echo esc_html($total_users); ?> items</span>
                        <?php if ($current_page > 1): ?>
                            <a class="prev-page button" href="<?php echo esc_url(add_query_arg('paged', $current_page - 1)); ?>">«</a>
                        <?php endif; ?>
                        <span class="paging-input"><?php echo esc_html($current_page); ?> of <?php echo esc_html($total_pages); ?></span>
                        <?php if ($current_page < $total_pages): ?>
                            <a class="next-page button" href="<?php echo esc_url(add_query_arg('paged', $current_page + 1)); ?>">»</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <style>
        .card { background: #fff; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04); padding: 15px; }
        .card h2 { margin: 0 0 12px; padding-bottom: 8px; border-bottom: 1px solid #eee; font-size: 14px; }
        .compact-form th { padding: 8px 0; font-size: 12px; width: 70px; }
        .compact-form td { padding: 8px 0; }
        .compact-form input, .compact-form select { width: 100%; font-size: 13px; }
        .card .submit { margin: 12px 0 0; padding: 0; }
        .card .button { font-size: 12px; height: 28px; line-height: 26px; padding: 0 10px; margin-right: 5px; }
        
        /* Action buttons - matching Super Admin dashboard style */
        .mc-row-actions {
            display: flex;
            gap: 6px;
            align-items: center;
        }
        .mc-action-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            border: 1px solid #c3c4c7;
            border-radius: 4px;
            background: #f6f7f7;
            color: #50575e;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .mc-action-btn:hover {
            background: #f0f0f1;
            border-color: #8c8f94;
            color: #1d2327;
        }
        .mc-action-btn .dashicons {
            font-size: 16px;
            width: 16px;
            height: 16px;
        }
        .mc-btn-switch {
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            border-color: #1d4ed8;
            color: #fff;
        }
        .mc-btn-switch:hover {
            background: linear-gradient(135deg, #1d4ed8, #1e40af);
            border-color: #1e40af;
            color: #fff;
        }
        .mc-btn-delete:hover {
            background: #dc2626;
            border-color: #dc2626;
            color: #fff;
        }
    </style>

    <script>
        let employerCounter = 1;
        let employeeCounter = 1;

        document.getElementById('base_email').addEventListener('input', function() {
            document.getElementById('base_email_for_delete').value = this.value;
        });

        function autoFillEmployer() {
            const baseEmail = document.getElementById('base_email').value;
            if (!baseEmail) { alert('Please enter a base email address first'); return; }
            const [local, domain] = baseEmail.split('@');
            if (!local || !domain) { alert('Please enter a valid email address'); return; }
            const timestamp = Date.now().toString().slice(-4);
            document.getElementById('employer_email').value = `${local}+employer${employerCounter}_${timestamp}@${domain}`;
            document.getElementById('employer_first_name').value = 'Test';
            document.getElementById('employer_last_name').value = `Employer ${employerCounter}`;
            document.getElementById('employer_company_name').value = `Test Company ${employerCounter}`;
            employerCounter++;
        }

        function autoFillEmployee() {
            const baseEmail = document.getElementById('base_email').value;
            if (!baseEmail) { alert('Please enter a base email address first'); return; }
            const [local, domain] = baseEmail.split('@');
            if (!local || !domain) { alert('Please enter a valid email address'); return; }
            const employerSelect = document.getElementById('employer_id');
            if (!employerSelect.value) { alert('Please select an employer first'); return; }
            const timestamp = Date.now().toString().slice(-4);
            document.getElementById('employee_email').value = `${local}+employee${employeeCounter}_${timestamp}@${domain}`;
            document.getElementById('employee_first_name').value = 'Test';
            document.getElementById('employee_last_name').value = `Employee ${employeeCounter}`;
            employeeCounter++;
        }

        function deleteTestUser(userId, userEmail) {
            if (!confirm(`Are you sure you want to delete ${userEmail}?`)) return;
            fetch(ajaxurl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'delete_test_user',
                    user_id: userId,
                    nonce: '<?php echo wp_create_nonce('mc_admin_testing_nonce'); ?>'
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) { location.reload(); }
                else { alert('Error: ' + (data.data.message || 'Failed to delete user')); }
            })
            .catch(error => { alert('Error deleting user'); console.error('Error:', error); });
        }
    </script>
<?php }