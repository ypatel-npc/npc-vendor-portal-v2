<?php
/**
 * Plugin Name: NPC vendor portal v2
 * Plugin URI: 
 * Description: A plugin for importing CSV files, mapping columns, and processing data in batches.
 * Version: 1.0.0
 * Author: 
 * Author URI: 
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: npc
 * Domain Path: /languages
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Define plugin constants
define('NPC_VERSION', '1.0.0');
define('NPC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('NPC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('NPC_ADMIN_URL', admin_url('admin.php?page=npc'));

// Include required files
require_once NPC_PLUGIN_DIR . 'includes/class-npc.php';

// Initialize the plugin
function run_npc() {
    $plugin = new NPC();
    $plugin->run();
}
run_npc();

// Add after your other code
add_action('npc_delete_import_table', array('NPC_Processor', 'delete_import_table'));
