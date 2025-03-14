<?php
/**
 * The core plugin class.
 *
 * @since      1.0.0
 */
class NPC {

    /**
     * The loader that's responsible for maintaining and registering all hooks that power
     * the plugin.
     *
     * @since    1.0.0
     * @access   protected
     * @var      NPC_Loader    $loader    Maintains and registers all hooks for the plugin.
     */
    protected $loader;

    /**
     * Define the core functionality of the plugin.
     *
     * @since    1.0.0
     */
    public function __construct() {
        $this->load_dependencies();
        $this->define_admin_hooks();
    }

    /**
     * Load the required dependencies for this plugin.
     *
     * @since    1.0.0
     * @access   private
     */
    private function load_dependencies() {
        // The class responsible for orchestrating the actions and filters of the core plugin.
        require_once NPC_PLUGIN_DIR . 'includes/class-npc-loader.php';
        
        // The class responsible for defining all actions that occur in the admin area.
        require_once NPC_PLUGIN_DIR . 'admin/class-npc-admin.php';
        
        // The class responsible for CSV processing
        require_once NPC_PLUGIN_DIR . 'includes/class-npc-processor.php';

        $this->loader = new NPC_Loader();
    }

    /**
     * Register all of the hooks related to the admin area functionality
     * of the plugin.
     *
     * @since    1.0.0
     * @access   private
     */
    private function define_admin_hooks() {
        $plugin_admin = new NPC_Admin();
        
        // Add admin menu
        $this->loader->add_action('admin_menu', $plugin_admin, 'add_plugin_admin_menu');
        
        // Add admin assets
        $this->loader->add_action('admin_enqueue_scripts', $plugin_admin, 'enqueue_styles');
        $this->loader->add_action('admin_enqueue_scripts', $plugin_admin, 'enqueue_scripts');
        
        // Handle form submissions
        $this->loader->add_action('admin_post_upload_csv', $plugin_admin, 'handle_csv_upload');
        $this->loader->add_action('admin_post_map_csv_columns', $plugin_admin, 'handle_column_mapping');
        $this->loader->add_action('admin_post_process_csv_data', $plugin_admin, 'process_csv_data');
        $this->loader->add_action('admin_post_import_to_database', $plugin_admin, 'import_to_database');
        $this->loader->add_action('admin_post_run_custom_query', $plugin_admin, 'run_custom_query');
        $this->loader->add_action('admin_post_match_skus', $plugin_admin, 'match_skus');
        $this->loader->add_action('admin_post_export_match_results', $plugin_admin, 'export_match_results');
    }

    /**
     * Run the loader to execute all of the hooks with WordPress.
     *
     * @since    1.0.0
     */
    public function run() {
        $this->loader->run();
    }
}
