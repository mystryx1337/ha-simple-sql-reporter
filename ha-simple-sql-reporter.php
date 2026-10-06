<?php
/*
Plugin Name: Simple SQL Reporter (SQL Sync & Download)
Description: Executes SQL queries, generates SQL dumps, sends them to webhooks, allows manual downloads, and manual sync triggers.
Version: 2.5
Author: Alexander Hacker
*/

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Initialize Plugin Update Checker for automatic updates from GitHub repository.
 *
 * @return \YahnisElsts\PluginUpdateChecker\v5\Vcs\PluginUpdateChecker|null
 */
function ha_simple_sql_reporter_get_updater() {
    static $update_checker = null;
    if ( null !== $update_checker ) {
        return $update_checker;
    }

    $puc_file = plugin_dir_path( __FILE__ ) . 'vendor/plugin-update-checker/plugin-update-checker.php';
    if ( ! file_exists( $puc_file ) ) {
        return null;
    }

    require_once $puc_file;

    $update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        'https://github.com/mystryx1337/ha-simple-sql-reporter/',
        __FILE__,
        'ha-simple-sql-reporter'
    );

    // Set default branch to main
    $update_checker->setBranch( 'main' );

    // Set GitHub Access Token (if defined/available)
    $token = defined( 'HA_GITHUB_UPDATE_TOKEN' ) ? HA_GITHUB_UPDATE_TOKEN : '';
    if ( empty( $token ) && defined( 'GITHUB_ACCESS_TOKEN' ) ) {
        $token = GITHUB_ACCESS_TOKEN;
    }
    $token = apply_filters( 'ha_github_update_token', $token, 'ha-simple-sql-reporter' );

    if ( ! empty( $token ) ) {
        $update_checker->setAuthentication( $token );
    }

    return $update_checker;
}
ha_simple_sql_reporter_get_updater();


class SimpleSQLReporter {

    public function __construct() {
        // Register CPT
        add_action( 'init', [ $this, 'register_cpt' ] );
        
        // Add Meta Boxes
        add_action( 'add_meta_boxes', [ $this, 'add_meta_boxes' ] );
        add_action( 'save_post', [ $this, 'save_meta_data' ] );
        
        // Load Code Highlighting
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_scripts' ] );
        
        // Settings Page
        add_action( 'admin_menu', [ $this, 'add_settings_page' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        
        // Custom Columns for List View
        add_filter( 'manage_sql_report_posts_columns', [ $this, 'add_custom_columns' ] );
        add_action( 'manage_sql_report_posts_custom_column', [ $this, 'render_custom_columns' ], 10, 2 );

        // Handlers for Download, Manual Run and Cron Activation
        add_action( 'admin_init', [ $this, 'handle_action_requests' ] );
        add_action( 'admin_notices', [ $this, 'show_admin_notices' ] );

        // Cron logic
        add_action( 'ssr_hourly_event', [ $this, 'execute_reports' ] );
        
        // Activation/Deactivation of Cron
        register_activation_hook( __FILE__, [ $this, 'activate' ] );
        register_deactivation_hook( __FILE__, [ $this, 'deactivate' ] );
		
		// Register AJAX action for testing the database connection
        add_action( 'wp_ajax_ssr_test_db_connection', [ $this, 'ajax_test_db_connection' ] );
    }

    public function register_cpt() {
        register_post_type( 'sql_report', [
            'labels' => [ 'name' => 'SQL Reports', 'singular_name' => 'SQL Report' ],
            'public' => false,
            'show_ui' => true,
            'supports' => [ 'title' ],
            'menu_icon' => 'dashicons-database',
            'capabilities' => [ 'create_posts' => 'manage_options' ],
            'map_meta_cap' => true,
        ]);
    }
	
	/**
     * AJAX handler to test the external database connection
     */
    public function ajax_test_db_connection() {
        // Verify nonce for security
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'ssr_test_db_nonce' ) ) {
            wp_send_json_error( 'Invalid security token.' );
        }

        // Check user permissions
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions.' );
        }

        $db_host = sanitize_text_field( wp_unslash( $_POST['db_host'] ?? '' ) );
        $db_name = sanitize_text_field( wp_unslash( $_POST['db_name'] ?? '' ) );
        $db_user = sanitize_text_field( wp_unslash( $_POST['db_user'] ?? '' ) );
        $db_pass = wp_unslash( $_POST['db_pass'] ?? '' ); // Do not strictly sanitize passwords

        // Check if all required fields are provided
        if ( empty( $db_host ) || empty( $db_name ) || empty( $db_user ) ) {
            wp_send_json_error( 'Please fill in Host, Database Name, and User.' );
        }

        // Attempt connection using a temporary wpdb instance
        $test_db = new wpdb( $db_user, $db_pass, $db_name, $db_host );

        if ( ! empty( $test_db->error ) ) {
            // Connection failed
            wp_send_json_error( 'Connection failed: ' . $test_db->error->get_error_message() );
        } else {
            // Connection successful
            wp_send_json_success( 'Connection successful!' );
        }
    }

    /**
     * Helper: Get DB Instance (External or Fallback to WPDB)
     */
    private function get_db_instance($post_id) {
        global $wpdb;
        
        $db_host = get_post_meta($post_id, '_ssr_ext_db_host', true);
        $db_name = get_post_meta($post_id, '_ssr_ext_db_name', true);
        $db_user = get_post_meta($post_id, '_ssr_ext_db_user', true);
        $db_pass = get_post_meta($post_id, '_ssr_ext_db_pass', true);

        // Check if required external connection details are present
        if (!empty($db_host) && !empty($db_name) && !empty($db_user)) {
            $custom_db = new wpdb($db_user, $db_pass, $db_name, $db_host);
            
            // If connection is successful, return the custom wpdb instance
            if (empty($custom_db->error)) {
                return $custom_db;
            }
        }
        
        // Fallback to standard WordPress DB
        return $wpdb;
    }

    /**
     * Helper: Generate the SQL Dump (Structure + Data)
     */
    private function generate_sql_dump($post_id) {
        // Retrieve the correct database instance (external or local fallback)
        $db = $this->get_db_instance($post_id);

        $sql = get_post_meta( $post_id, '_ssr_sql_query', true );
        $target_table = get_post_meta( $post_id, '_ssr_target_table', true );

        if ( empty( $sql ) || empty($target_table) ) return false;

        $tmp_name = '_ssr_tmp_' . $post_id;
        $db->query("DROP TEMPORARY TABLE IF EXISTS $tmp_name");
        
        // Create temp table from complex query
        if ( $db->query("CREATE TEMPORARY TABLE $tmp_name AS ($sql)") === false ) return false;

        $structure_row = $db->get_row("SHOW CREATE TABLE $tmp_name", ARRAY_A);
        $create_sql = str_replace("CREATE TEMPORARY TABLE `$tmp_name`", "CREATE TABLE `$target_table`", $structure_row['Create Table']);
        
        // Fix character set compatibility issues between newer and older database versions
        $create_sql = str_replace('utf8mb3', 'utf8', $create_sql);

        $results = $db->get_results("SELECT * FROM $tmp_name", ARRAY_A);

        // Define a safe delimiter that is also a valid SQL comment
        $delimiter = "\n/*--SSR-SPLIT--*/\n";

        $dump = "-- Simple SQL Reporter Export\n";
        $dump .= "DROP TABLE IF EXISTS `$target_table`;" . $delimiter;
        $dump .= $create_sql . ";" . $delimiter;

        if ( ! empty( $results ) ) {
            $columns = array_keys( $results[0] );
            $col_string = implode( "`, `", $columns );

            // Generate individual INSERT statements
            foreach ( $results as $row ) {
                $escaped = array_map( function($val) use ($db) {
                    // Use the specific db instance's escape function to prevent mismatch errors
                    return is_null($val) ? 'NULL' : "'" . $db->_real_escape($val) . "'";
                }, $row );
                
                $dump .= "INSERT INTO `$target_table` (`$col_string`) VALUES (" . implode( ", ", $escaped ) . ");" . $delimiter;
            }
        }

        $db->query("DROP TEMPORARY TABLE $tmp_name");
        return $dump;
    }

    /**
     * List View: Add & Render Columns
     */
    public function add_custom_columns($columns) {
        $new_columns = [];
        foreach($columns as $key => $value) {
            $new_columns[$key] = $value;
            if ($key === 'title') {
                $new_columns['ssr_actions'] = 'Actions';
                $new_columns['ssr_status'] = 'Last Sync Status';
            }
        }
        return $new_columns;
    }

    public function render_custom_columns($column, $post_id) {
        if ($column === 'ssr_actions') {
            $download_url = wp_nonce_url( admin_url('edit.php?post_type=sql_report&ssr_action=download&post_id=' . $post_id), 'ssr_action_nonce' );
            $run_url = wp_nonce_url( admin_url('edit.php?post_type=sql_report&ssr_action=run&post_id=' . $post_id), 'ssr_action_nonce' );

            echo '<div style="display:flex; gap:5px; margin-bottom: 5px;">';
            echo '<a href="' . esc_url($run_url) . '" class="button button-small button-primary"><span class="dashicons dashicons-controls-play" style="vertical-align:middle; font-size:16px;"></span> Run Now</a>';
            echo '<a href="' . esc_url($download_url) . '" class="button button-small"><span class="dashicons dashicons-download" style="vertical-align:middle; font-size:16px;"></span> Export</a>';
            echo '</div>';
        }

        if ($column === 'ssr_status') {
            $time = get_post_meta($post_id, '_ssr_last_run_time', true);
            $status = get_post_meta($post_id, '_ssr_last_run_status', true);
            $message = get_post_meta($post_id, '_ssr_last_run_message', true);

            if (empty($time)) {
                echo '<em>Not run yet</em>';
            } else {
                $color = ($status === 'success') ? '#00a32a' : '#d63638';
                $icon = ($status === 'success') ? 'yes' : 'no';
                
                echo "<span style='color:{$color}; font-weight:bold;'><span class='dashicons dashicons-{$icon}'></span> " . strtoupper($status) . "</span><br>";
                echo "<small>" . esc_html($time) . "</small><br>";
                echo "<small style='color:#666;'>" . esc_html($message) . "</small>";
            }
        }
    }

    /**
     * Process Download, Run Requests and Cron Activation
     */
    public function handle_action_requests() {
        if ( ! isset($_GET['ssr_action']) || ! current_user_can('manage_options') ) {
            return;
        }

        check_admin_referer('ssr_action_nonce');
        $action = sanitize_text_field($_GET['ssr_action']);

        if ( $action === 'activate_cron' ) {
            $current_schedule = wp_get_schedule( 'ssr_hourly_event' );
            if ( $current_schedule !== 'hourly' ) {
                wp_clear_scheduled_hook( 'ssr_hourly_event' );
                wp_schedule_event( time(), 'hourly', 'ssr_hourly_event' );
            }

            $redirect_url = add_query_arg([
                'post_type'  => 'sql_report',
                'ssr_notice' => 'cron_activated'
            ], admin_url('edit.php'));

            wp_redirect( $redirect_url );
            exit;
        }

        if ( isset($_GET['post_id']) ) {
            $post_id = intval($_GET['post_id']);

            if ( $action === 'download' ) {
                $post = get_post($post_id);
                $dump = $this->generate_sql_dump($post_id);

                if ($dump) {
                    header('Content-Type: application/sql');
                    header('Content-Disposition: attachment; filename="' . sanitize_title($post->post_title) . '.sql"');
                    header('Pragma: no-cache');
                    echo $dump;
                    exit;
                }
            } 
            
            if ( $action === 'run' ) {
                $success = $this->execute_single_report( $post_id );
                
                // Redirect back to avoid re-triggering on page refresh
                $redirect_url = add_query_arg([
                    'post_type'  => 'sql_report',
                    'ssr_notice' => $success ? 'success' : 'error'
                ], admin_url('edit.php'));
                
                wp_redirect( $redirect_url );
                exit;
            }
        }
    }

    /**
     * Display Admin Notices after a manual run or if cron is not registered
     */
    public function show_admin_notices() {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        $is_sql_report = ( $screen && $screen->post_type === 'sql_report' )
            || ( isset( $_GET['post_type'] ) && $_GET['post_type'] === 'sql_report' );

        if ( ! $is_sql_report ) {
            return;
        }

        if ( isset($_GET['ssr_notice']) ) {
            $notice = sanitize_text_field($_GET['ssr_notice']);
            if ( $notice === 'success' ) {
                echo '<div class="notice notice-success is-dismissible"><p>Report successfully synced!</p></div>';
            } elseif ( $notice === 'error' ) {
                echo '<div class="notice notice-error is-dismissible"><p>Report sync failed. Check the status column for details.</p></div>';
            } elseif ( $notice === 'cron_activated' ) {
                echo '<div class="notice notice-success is-dismissible"><p>Der stündliche Cronjob wurde erfolgreich aktiviert!</p></div>';
            }
        }

        // Warn if cron is not scheduled
        if ( ! wp_next_scheduled( 'ssr_hourly_event' ) && current_user_can( 'manage_options' ) ) {
            $activate_url = wp_nonce_url( admin_url( 'edit.php?post_type=sql_report&ssr_action=activate_cron' ), 'ssr_action_nonce' );
            ?>
            <div class="notice notice-warning">
                <p style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
                    <span>
                        <strong>Simple SQL Reporter:</strong> Der stündliche Cronjob (<code>ssr_hourly_event</code>) ist derzeit <strong>nicht aktiv</strong>. Automatische Synchronisationen finden nicht statt.
                    </span>
                    <a href="<?php echo esc_url( $activate_url ); ?>" class="button button-primary">
                        Cronjob jetzt aktivieren
                    </a>
                </p>
            </div>
            <?php
        }
    }

    /**
     * Cron Logic (executes all reports)
     */
    public function execute_reports() {
        $reports = get_posts(['post_type' => 'sql_report', 'numberposts' => -1, 'post_status' => 'publish']);
        foreach ( $reports as $report ) {
            $this->execute_single_report($report->ID);
        }
    }

    /**
     * Core Logic: Execute a single report and log the result
     */
    private function execute_single_report( $post_id ) {
        $api_key = get_option('ssr_api_key');
        $url = get_post_meta( $post_id, '_ssr_target_url', true );
        
        if ( empty($url) ) {
            $this->log_result($post_id, 'error', 'No Target URL set.');
            return false;
        }

        $dump = $this->generate_sql_dump($post_id);
        
        if ( $dump ) {
            $response = wp_remote_post( $url, [
                'timeout' => 60,
                'body' => [ 'api_key' => $api_key, 'sql_file' => $dump ]
            ]);

            if ( is_wp_error( $response ) ) {
                $this->log_result($post_id, 'error', $response->get_error_message());
                return false;
            } else {
                $status_code = wp_remote_retrieve_response_code( $response );
                $body = wp_remote_retrieve_body( $response );
                $status = ($status_code == 200) ? 'success' : 'error';
                
                $clean_msg = wp_strip_all_tags($body);
                $this->log_result($post_id, $status, "HTTP $status_code: $clean_msg");
                return ($status === 'success');
            }
        } else {
            $this->log_result($post_id, 'error', 'Failed to generate SQL dump. Check query syntax.');
            return false;
        }
    }

    /**
     * Helper: Save result to post meta
     */
    private function log_result($post_id, $status, $message) {
        update_post_meta( $post_id, '_ssr_last_run_time', current_time('mysql') );
        update_post_meta( $post_id, '_ssr_last_run_status', $status );
        update_post_meta( $post_id, '_ssr_last_run_message', sanitize_text_field($message) );
    }

    // --- Standard CPT & Settings Boilerplate below ---

    public function register_settings() { register_setting( 'ssr_settings_group', 'ssr_api_key' ); }
    public function add_settings_page() {
        add_submenu_page('edit.php?post_type=sql_report', 'SSR Settings', 'Settings', 'manage_options', 'ssr-settings', [ $this, 'render_settings_page' ]);
    }
    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1>Settings</h1>
            <form method="post" action="options.php">
                <?php settings_fields( 'ssr_settings_group' ); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">API Key</th>
                        <td><input type="text" name="ssr_api_key" id="ssr_api_key" value="<?php echo esc_attr( get_option('ssr_api_key') ); ?>" class="regular-text"></td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
    public function add_meta_boxes() { add_meta_box( 'ssr_config_box', 'Configuration', [ $this, 'render_meta_box' ], 'sql_report', 'normal', 'high' ); }
public function render_meta_box( $post ) {
        // Core configuration
        $sql = get_post_meta( $post->ID, '_ssr_sql_query', true );
        $url = get_post_meta( $post->ID, '_ssr_target_url', true );
        $target_table = get_post_meta( $post->ID, '_ssr_target_table', true );
        
        // External Database Configuration
        $db_host = get_post_meta( $post->ID, '_ssr_ext_db_host', true );
        $db_name = get_post_meta( $post->ID, '_ssr_ext_db_name', true );
        $db_user = get_post_meta( $post->ID, '_ssr_ext_db_user', true );
        $db_pass = get_post_meta( $post->ID, '_ssr_ext_db_pass', true );

        wp_nonce_field( 'ssr_save_data', 'ssr_nonce' );
        
        // Output nonce field specifically for the AJAX connection test
        wp_nonce_field( 'ssr_test_db_nonce', 'ssr_test_db_nonce_field' );
        

        echo '<h4>Report Configuration</h4>';
        echo '<p><label>Target URL:</label><br><input type="url" name="ssr_target_url" value="'.esc_attr($url).'" style="width:100%;"></p>';
        echo '<p><label>Target Table:</label><br><input type="text" name="ssr_target_table" value="'.esc_attr($target_table).'" style="width:100%;"></p>';
        echo '<p><label>SQL Query:</label><br><textarea id="ssr_sql_query" name="ssr_sql_query" style="width:100%;height:200px;">'.esc_textarea($sql).'</textarea></p>';

        echo '<hr>';
		
        echo '<h4>Database Connection (Optional)</h4>';
        echo '<p><em>Leave the fields below empty to use the standard WordPress database.</em></p>';
        echo '<p><label>External DB Host:</label><br><input type="text" name="ssr_ext_db_host" value="'.esc_attr($db_host).'" style="width:100%;"></p>';
        echo '<p><label>External DB Name:</label><br><input type="text" name="ssr_ext_db_name" value="'.esc_attr($db_name).'" style="width:100%;"></p>';
        echo '<p><label>External DB User:</label><br><input type="text" name="ssr_ext_db_user" value="'.esc_attr($db_user).'" style="width:100%;"></p>';
        echo '<p><label>External DB Password:</label><br><input type="password" name="ssr_ext_db_pass" value="'.esc_attr($db_pass).'" style="width:100%;"></p>';
        
        // Add the test button and a span to show the result
        echo '<p><button type="button" id="ssr_test_connection" class="button button-secondary">Test Connection</button> <span id="ssr_test_result" style="margin-left: 10px;"></span></p>';
        
        // Output inline script to handle the AJAX request when the button is clicked
        ?>
        <script>
        jQuery(document).ready(function($) {
            $('#ssr_test_connection').on('click', function(e) {
                e.preventDefault();
                
                var $btn = $(this);
                var $result = $('#ssr_test_result');
                
                // Disable button and show loading indicator
                $btn.prop('disabled', true);
                $result.html('<span class="spinner is-active" style="float:none; margin-top:0;"></span> Testing...');

                // Prepare data for the AJAX call
                var data = {
                    action: 'ssr_test_db_connection',
                    nonce: $('#ssr_test_db_nonce_field').val(),
                    db_host: $('input[name="ssr_ext_db_host"]').val(),
                    db_name: $('input[name="ssr_ext_db_name"]').val(),
                    db_user: $('input[name="ssr_ext_db_user"]').val(),
                    db_pass: $('input[name="ssr_ext_db_pass"]').val()
                };

                // Send the POST request
                $.post(ajaxurl, data, function(response) {
                    $btn.prop('disabled', false);
                    
                    if (response.success) {
                        $result.html('<span style="color: #00a32a; font-weight: bold;"><span class="dashicons dashicons-yes" style="vertical-align: middle;"></span> ' + response.data + '</span>');
                    } else {
                        $result.html('<span style="color: #d63638; font-weight: bold;"><span class="dashicons dashicons-no" style="vertical-align: middle;"></span> ' + response.data + '</span>');
                    }
                }).fail(function() {
                    $btn.prop('disabled', false);
                    $result.html('<span style="color: #d63638; font-weight: bold;">AJAX Request failed. Check console.</span>');
                });
            });
        });
        </script>
        <?php
    }
    public function save_meta_data( $post_id ) {
        if ( !isset($_POST['ssr_nonce']) || !wp_verify_nonce($_POST['ssr_nonce'], 'ssr_save_data') ) return;
        
        // Save Core configuration
        update_post_meta( $post_id, '_ssr_target_url', esc_url_raw( $_POST['ssr_target_url'] ) );
        update_post_meta( $post_id, '_ssr_target_table', sanitize_text_field( $_POST['ssr_target_table'] ) );
        update_post_meta( $post_id, '_ssr_sql_query', stripslashes( $_POST['ssr_sql_query'] ) );

        // Save External DB fields
        update_post_meta( $post_id, '_ssr_ext_db_host', sanitize_text_field( $_POST['ssr_ext_db_host'] ?? '' ) );
        update_post_meta( $post_id, '_ssr_ext_db_name', sanitize_text_field( $_POST['ssr_ext_db_name'] ?? '' ) );
        update_post_meta( $post_id, '_ssr_ext_db_user', sanitize_text_field( $_POST['ssr_ext_db_user'] ?? '' ) );
        
        // Avoid sanitizing valid characters in passwords out by using simple wp_unslash instead of sanitize_text_field
        $db_pass = isset($_POST['ssr_ext_db_pass']) ? wp_unslash($_POST['ssr_ext_db_pass']) : '';
        update_post_meta( $post_id, '_ssr_ext_db_pass', $db_pass );
    }
	public function enqueue_admin_scripts( $hook ) {
        // Only load on our custom post type edit pages
        if ( ('post.php' === $hook || 'post-new.php' === $hook) && 'sql_report' === get_post_type() ) {
            
            // Enqueue the built-in WordPress code editor for SQL
            $settings = wp_enqueue_code_editor(['type' => 'text/x-sql']);
            
            // Bail out if the code editor is disabled by the user
            if ( false === $settings ) {
                return;
            }

            // Initialize CodeMirror with advanced settings via inline script
            wp_add_inline_script(
                'code-editor',
                'jQuery(document).ready(function($) {
                    var editorSettings = wp.codeEditor.defaultSettings ? _.clone( wp.codeEditor.defaultSettings ) : {};
                    
                    // Extend default settings with specific SQL editor preferences
                    editorSettings.codemirror = _.extend(
                        {},
                        editorSettings.codemirror,
                        {
                            lineNumbers: true,
                            indentUnit: 4,
                            tabSize: 4,
                            matchBrackets: true,
                            autoCloseBrackets: true,
                            mode: "text/x-sql"
                        }
                    );
                    
                    // Apply CodeMirror to the textarea
                    wp.codeEditor.initialize( $("#ssr_sql_query"), editorSettings );
                });'
            );
        }
    }
    public function activate() {
        $current_schedule = wp_get_schedule( 'ssr_hourly_event' );
        if ( $current_schedule !== 'hourly' ) {
            wp_clear_scheduled_hook( 'ssr_hourly_event' );
            wp_schedule_event( time(), 'hourly', 'ssr_hourly_event' );
        }
    }

    public function deactivate() {
        wp_clear_scheduled_hook( 'ssr_hourly_event' );
    }
}

new SimpleSQLReporter();