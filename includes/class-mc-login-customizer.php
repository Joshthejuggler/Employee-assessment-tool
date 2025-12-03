<?php
if (!defined('ABSPATH'))
    exit;

/**
 * Customizes the WordPress login and registration screens.
 */
class MC_Login_Customizer
{
    /**
     * Initialize the login customizer.
     */
    public static function init()
    {
        add_action('login_enqueue_scripts', [__CLASS__, 'enqueue_login_styles']);
        add_filter('login_headerurl', [__CLASS__, 'login_logo_url']);
        add_filter('login_headertext', [__CLASS__, 'login_logo_title']);
        add_action('login_message', [__CLASS__, 'custom_login_message']);

        // Custom login redirect logic
        add_filter('login_redirect', [__CLASS__, 'custom_login_redirect'], 10, 3);

        // Auto-login registration hooks
        add_action('register_form', [__CLASS__, 'add_password_fields']);
        add_filter('registration_errors', [__CLASS__, 'validate_password_fields'], 10, 3);
        add_action('user_register', [__CLASS__, 'save_password_and_login']);

        // Track user login
        add_action('wp_login', [__CLASS__, 'track_user_login'], 10, 2);
    }

    /**
     * Track user login and update status.
     */
    public static function track_user_login($user_login, $user)
    {
        // Update last login timestamp
        update_user_meta($user->ID, 'mc_last_login', current_time('mysql'));

        // If user is an employer and status is pending, set to active
        if (in_array(MC_Roles::ROLE_EMPLOYER, (array) $user->roles)) {
            $status = get_user_meta($user->ID, 'mc_employer_status', true);
            if (!$status || $status === 'pending') {
                update_user_meta($user->ID, 'mc_employer_status', 'active');
            }
        }
    }

    /**
     * Redirect users to their appropriate dashboard after login.
     */
    public static function custom_login_redirect($redirect_to, $request, $user)
    {
        // If there is a specific redirect request (and it's not default admin), respect it.
        if (!empty($request) && strpos($request, 'wp-admin') === false && strpos($request, 'wp-login.php') === false) {
            return $request;
        }

        if (isset($user->roles) && is_array($user->roles)) {
            // Employers -> Employer Dashboard
            if (in_array(MC_Roles::ROLE_EMPLOYER, $user->roles)) {
                $employer_dash = MC_Funnel::find_page_by_shortcode('mc_employer_dashboard');
                if ($employer_dash) {
                    return $employer_dash;
                }
            }
            // Employees -> Assessment Dashboard
            if (in_array(MC_Roles::ROLE_EMPLOYEE, $user->roles)) {
                $employee_dash = MC_Funnel::find_page_by_shortcode('quiz_dashboard');
                if ($employee_dash) {
                    return $employee_dash;
                }
            }
        }

        return home_url();
    }

    /**
     * Enqueue custom styles for the login page.
     */
    public static function enqueue_login_styles()
    {
        wp_enqueue_style('mc-login-custom', plugins_url('../assets/login-custom.css', __FILE__), [], '1.0.0');
    }

    /**
     * Change the logo link URL to the site home URL.
     */
    public static function login_logo_url()
    {
        return home_url();
    }

    /**
     * Change the logo title attribute.
     */
    public static function login_logo_title()
    {
        return get_bloginfo('name');
    }

    /**
     * Add a custom message or modify the login header.
     */
    public static function custom_login_message($message)
    {
        return $message;
    }

    /**
     * Add password fields to the registration form.
     */
    public static function add_password_fields()
    {
        ?>
        <p>
            <label for="password">Password <br />
                <input type="password" name="password" id="password" class="input" value="" size="25" autocomplete="off" />
            </label>
        </p>
        <p>
            <label for="password_confirm">Confirm Password <br />
                <input type="password" name="password_confirm" id="password_confirm" class="input" value="" size="25"
                    autocomplete="off" />
            </label>
        </p>
        <?php
    }

    /**
     * Validate the password fields.
     */
    public static function validate_password_fields($errors, $sanitized_user_login, $user_email)
    {
        if (empty($_POST['password']) || empty($_POST['password_confirm'])) {
            $errors->add('password_error', __('<strong>ERROR</strong>: Please enter a password and confirm it.', 'mc-quiz'));
        } elseif ($_POST['password'] !== $_POST['password_confirm']) {
            $errors->add('password_mismatch', __('<strong>ERROR</strong>: Passwords do not match.', 'mc-quiz'));
        }
        return $errors;
    }

    /**
     * Save the password and auto-login the user.
     */
    public static function save_password_and_login($user_id)
    {
        if (isset($_POST['password'])) {
            wp_set_password($_POST['password'], $user_id);

            // Auto-login
            $user = get_user_by('id', $user_id);
            wp_set_current_user($user_id, $user->user_login);
            wp_set_auth_cookie($user_id);

            // Handle Role Assignment based on invite cookie
            if (class_exists('MC_Roles')) {
                if (isset($_COOKIE['mc_invite_code'])) {
                    $user->set_role(MC_Roles::ROLE_EMPLOYEE);
                } else {
                    // Default new registrations to Employer (if no invite code)
                    $user->set_role(MC_Roles::ROLE_EMPLOYER);
                }
            }

            // Determine redirect based on role
            $redirect_to = home_url();

            // Check role we just assigned
            if ($user->has_cap(MC_Roles::CAP_MANAGE_EMPLOYEES)) {
                $dash = MC_Funnel::find_page_by_shortcode('mc_employer_dashboard');
                if ($dash)
                    $redirect_to = $dash;
            } elseif ($user->has_cap(MC_Roles::CAP_TAKE_ASSESSMENTS)) {
                $dash = MC_Funnel::find_page_by_shortcode('quiz_dashboard');
                if ($dash)
                    $redirect_to = $dash;
            }

            // If invite code cookie exists, logic might override (but we handled role assignment above)
            // But we can also check if there was a specific redirect_to posted
            if (!empty($_POST['redirect_to'])) {
                $redirect_to = $_POST['redirect_to'];
            }

            wp_safe_redirect($redirect_to);
            exit;
        }
    }
}
