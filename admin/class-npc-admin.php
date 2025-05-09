<?php
/**
 * The admin-specific functionality of the plugin.
 *
 * @since      2.0.0
 */
class NPC_Admin {

    /**
     * The ID of this plugin.
     *
     * @since    1.0.0
     * @access   private
     * @var      string    $plugin_name    The ID of this plugin.
     */
    private $plugin_name;

    /**
     * The version of this plugin.
     *
     * @since    1.0.0
     * @access   private
     * @var      string    $version    The current version of this plugin.
     */
    private $version;

    /**
     * Initialize the class and set its properties.
     *
     * @since    1.0.0
     */
    public function __construct() {
        $this->plugin_name = 'npc';
        $this->version = NPC_VERSION;

        // Register admin post actions
        add_action('admin_post_npc_upload', array($this, 'handle_csv_upload'));
        add_action('admin_post_npc_map_columns', array($this, 'handle_column_mapping'));
        add_action('admin_post_npc_process', array($this, 'process_csv_data'));
        add_action('admin_post_npc_import', array($this, 'import_to_database'));
        add_action('admin_post_npc_match', array($this, 'match_skus'));
        add_action('admin_post_npc_export', array($this, 'export_match_results'));
        add_action('admin_post_npc_drop_table', array($this, 'handle_drop_table'));
        add_action('admin_post_npc_create_po', array($this, 'handle_create_po'));
        add_action('admin_post_npc_hold_batch', array($this, 'handle_hold_batch'));
    }

    /**
     * Register the stylesheets for the admin area.
     *
     * @since    1.0.0
     */
    public function enqueue_styles() {
        wp_enqueue_style($this->plugin_name, NPC_PLUGIN_URL . 'admin/css/npc-admin.css', array(), $this->version, 'all');
    }

    /**
     * Register the JavaScript for the admin area.
     *
     * @since    1.0.0
     */
    public function enqueue_scripts() {
        wp_enqueue_script($this->plugin_name, NPC_PLUGIN_URL . 'admin/js/npc-admin.js', array('jquery'), $this->version, false);
    }

    /**
     * Add menu items to the admin menu.
     *
     * @since    1.0.0
     */
    public function add_plugin_admin_menu() {
        add_menu_page(
            'NPC Vendor Portal',
            'NPC Vendor Portal',
            'manage_options',
            'npc',
            array($this, 'display_plugin_admin_page'),
            'dashicons-database-import',
            26
        );
        
        // Add submenu for existing import tables
        add_submenu_page(
            'npc',
            'Import Tables',
            'Import Tables',
            'manage_options',
            'npc-tables',
            array($this, 'display_import_tables_page')
        );
    }

    /**
     * Render the admin page.
     *
     * @since    1.0.0
     */
    public function display_plugin_admin_page() {
        // Check if we have a CSV file in the session
        $csv_file = get_transient('npc_file');
        $step = isset($_GET['step']) ? sanitize_text_field($_GET['step']) : 'upload';
        
        if ($step === 'upload' || empty($csv_file)) {
            $this->display_upload_form();
        } elseif ($step === 'mapping') {
            $this->display_mapping_form($csv_file);
        } elseif ($step === 'preview') {
            $this->display_preview($csv_file);
        } elseif ($step === 'results') {
            $this->display_results();
        } elseif ($step === 'database') {
            $this->display_database_results();
        } elseif ($step === 'match') {
            $this->display_match_step();
        } elseif ($step === 'match_results') {
            $this->display_match_results();
        } elseif ($step === 'create_po') {
            $this->display_create_po();
        }
    }

    /**
     * Display the upload form.
     *
     * @since    1.0.0
     */
    private function display_upload_form() {
        // Create a processor instance to check for existing tables
        $processor = new NPC_Processor();
        $existing_tables = $processor->get_import_tables();
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <div class="card">
                <h2>Upload CSV File</h2>
                <p>Upload a CSV file to begin the import process.</p>
                
                <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="npc_upload">
                    <?php wp_nonce_field('npc_upload', 'npc_upload_nonce'); ?>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="csv_file">CSV File</label></th>
                            <td>
                                <input type="file" name="csv_file" id="csv_file" accept=".csv" required>
                                <p class="description">Select a CSV file to upload.</p>
                            </td>
                        </tr>
                    </table>
                    
                    <?php submit_button('Upload and Continue', 'primary', 'submit', true); ?>
                </form>
                
                <?php if (!empty($existing_tables)) : ?>
                <div class="existing-tables-notice" style="margin-top: 20px; padding: 10px; background: #f8f8f8; border-left: 4px solid #0073aa;">
                    <p><strong>Development Notice:</strong> You have <?php echo count($existing_tables); ?> permanent import tables in your database.</p>
                    <p><a href="<?php echo esc_url(admin_url('admin.php?page=npc-tables')); ?>" class="button">Manage Import Tables</a></p>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Display the mapping form.
     *
     * @since    1.0.0
     * @param    string    $csv_file    The path to the uploaded CSV file.
     */
    private function display_mapping_form($csv_file) {
        $processor = new NPC_Processor($csv_file);
        $preview = $processor->get_csv_preview();
        
        if (empty($preview)) {
            echo '<div class="notice notice-error"><p>Error reading CSV file. Please try again.</p></div>';
            $this->display_upload_form();
            return;
        }
        
        $headers = $preview['headers'];
        $sample_data = $preview['data'];
        
        // Define target columns (these would be your database columns or required fields)
        $target_columns = array(
            'sku' => 'SKU',
            'price' => 'Price',
            'location' => 'Location',
            'quantity' => 'Quantity',
            'description' => 'Description'
        );
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <div class="card">
                <h2>Map CSV Columns</h2>
                <p>Map the columns from your CSV file to the required fields.</p>
                
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="npc_map_columns">
                    <?php wp_nonce_field('npc_map_columns', 'npc_map_columns_nonce'); ?>
                    
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th>Target Field</th>
                                <th>CSV Column</th>
                                <th>Sample Data</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($target_columns as $column_key => $column_label) : ?>
                                <tr>
                                    <td>
                                        <strong><?php echo esc_html($column_label); ?></strong>
                                        <?php if ($column_key === 'sku') echo ' (Required)'; ?>
                                    </td>
                                    <td>
                                        <select name="column_mapping[<?php echo esc_attr($column_key); ?>]" <?php if ($column_key === 'sku') echo 'required'; ?>>
                                            <option value="">-- Select CSV Column --</option>
                                            <?php foreach ($headers as $header) : ?>
                                                <option value="<?php echo esc_attr($header); ?>"><?php echo esc_html($header); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td>
                                        <div class="sample-data">
                                            
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <div class="csv-preview">
                        <h3>CSV Preview</h3>
                        <table class="widefat striped">
                            <thead>
                                <tr>
                                    <?php foreach ($headers as $header) : ?>
                                        <th><?php echo esc_html($header); ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($sample_data as $row) : ?>
                                    <tr>
                                        <?php foreach ($headers as $header) : ?>
                                            <td><?php echo esc_html($row[$header]); ?></td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <p>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=npc')); ?>" class="button">Start Over</a>
                        <?php submit_button('Continue to Preview', 'primary', 'submit', false); ?>
                    </p>
                </form>
            </div>
        </div>
        <script>
        jQuery(document).ready(function($) {
            // Store preview data in JavaScript
            var previewData = <?php echo json_encode($sample_data); ?>;
            
            $('select[name^="column_mapping"]').on('change', function() {
                var select = $(this);
                var row = select.closest('tr');
                var sampleCell = row.find('.sample-data');
                var selectedIndex = select.val();
                
                // Clear sample data if no column is selected
                if (selectedIndex === '') {
                    sampleCell.html('');
                    return;
                }
                
                // Get sample data for selected column
                var samples = [];
                previewData.forEach(function(row) {
                    if (row[selectedIndex]) {
                        samples.push(row[selectedIndex]);
                    }
                });
                
                // Update the sample data cell
                sampleCell.html(samples.join(', '));
            });
        });
        </script>
        <?php
    }

    /**
     * Display the preview step.
     *
     * @since    1.0.0
     * @param    string    $csv_file    The path to the uploaded CSV file.
     */
    private function display_preview($csv_file) {
        $processor = new NPC_Processor($csv_file);
        $mapping = get_transient('npc_mapping');
        
        if (empty($mapping)) {
            wp_die('Column mapping not found. Please try again.');
        }
        
        $processor->set_column_mapping($mapping);
        $preview = $processor->get_mapped_preview();
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <div class="card">
                <h2>Preview Mapped Data</h2>
                <p>Review the mapped data before processing.</p>
                
                <div class="csv-preview">
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <?php foreach ($preview['headers'] as $header) : ?>
                                    <th><?php echo esc_html($header); ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($preview['data'] as $row) : ?>
                                <tr>
                                    <?php foreach ($preview['headers'] as $header) : ?>
                                        <td><?php echo esc_html($row[$header]); ?></td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="processing-options">
                    <h3>Processing Options</h3>
                    
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="npc_process">
                        <?php wp_nonce_field('npc_process', 'npc_process_nonce'); ?>
                        
                        <div class="option-section">
                            <h4>Group By</h4>
                            <div class="group-by-options">
                                <?php foreach ($preview['headers'] as $header) : ?>
                                    <label>
                                        <input type="checkbox" name="group_by[]" value="<?php echo esc_attr($header); ?>">
                                        <?php echo esc_html($header); ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            
                            <div class="aggregate-options">
                                <h4>Aggregate Functions</h4>
                                <table>
                                    <tr>
                                        <th>Column</th>
                                        <th>Function</th>
                                    </tr>
                                    <?php foreach ($preview['headers'] as $header) : ?>
                                        <tr>
                                            <td><?php echo esc_html($header); ?></td>
                                            <td>
                                                <select name="aggregate[<?php echo esc_attr($header); ?>]">
                                                    <option value="">None</option>
                                                    <option value="sum">Sum</option>
                                                    <option value="avg">Average</option>
                                                    <option value="count">Count</option>
                                                    <option value="min">Min</option>
                                                    <option value="max">Max</option>
                                                </select>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </table>
                            </div>
                        </div>
                        
                        <div class="option-section">
                            <h4>Additional Options</h4>
                            <label>
                                <input type="checkbox" name="deduplicate" value="1">
                                Remove duplicate rows
                            </label>
                        </div>
                        
                        <p>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=npc&step=mapping')); ?>" class="button">Back to Mapping</a>
                            <?php submit_button('Process Data', 'primary', 'submit', false); ?>
                        </p>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Display the results step.
     *
     * @since    1.0.0
     */
    private function display_results() {
        $mapped_file = get_transient('npc_mapped_file');
        
        if (empty($mapped_file)) {
            wp_die('Processed file not found. Please try again.');
        }
        
        $processor = new NPC_Processor($mapped_file);
        $preview = $processor->get_csv_preview(10);
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <div class="notice notice-success">
                <p><strong>Success!</strong> Your data has been processed.</p>
            </div>
            
            <div class="card">
                <h2>Processed Data Preview</h2>
                
                <div class="csv-preview">
                    <?php if (!empty($preview['data'])) : ?>
                        <table class="widefat striped">
                            <thead>
                                <tr>
                                    <?php foreach ($preview['headers'] as $header) : ?>
                                        <th><?php echo esc_html($header); ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($preview['data'] as $row) : ?>
                                    <tr>
                                        <?php foreach ($preview['headers'] as $header) : ?>
                                            <td><?php echo esc_html($row[$header]); ?></td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else : ?>
                        <p>No preview data available.</p>
                    <?php endif; ?>
                </div>
                
                <div class="next-steps">
                    <h3>Next Steps</h3>
                    
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="npc_import">
                        <?php wp_nonce_field('npc_import', 'npc_import_nonce'); ?>
                        
                        <p>You can now import this data into a database table for further processing.</p>
                        
                        <table class="form-table">
                            <tr>
                                <th scope="row"><label for="vendor_name">Vendor Name</label></th>
                                <td>
                                    <input type="text" name="vendor_name" id="vendor_name" class="regular-text" required>
                                    <p class="description">Enter a name for this vendor (used for table naming).</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="is_permanent">Table Type</label></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="is_permanent" id="is_permanent" value="1" checked>
                                        Create permanent table (for development)
                                    </label>
                                    <p class="description">If checked, the table will remain in the database after import.</p>
                                </td>
                            </tr>
                        </table>
                        
                        <p>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=npc&step=preview')); ?>" class="button">Back to Preview</a>
                            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=download_mapped_csv'), 'npc_download', 'npc_download_nonce')); ?>" class="button">Download Processed CSV</a>
                            <?php submit_button('Import to Database', 'primary', 'submit', false); ?>
                        </p>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Display the database import results.
     *
     * @since    1.0.0
     */
    private function display_database_results() {
        // Get import statistics from transient
        $import_stats = get_transient('npc_import_stats');
        
        if (empty($import_stats)) {
            wp_die('Import statistics not found. Please try again.');
        }
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <div class="notice notice-success">
                <p><strong>Success!</strong> Data imported to database.</p>
            </div>
            
            <div class="card">
                <h2>Database Import Results</h2>
                
                <div class="import-summary">
                    <ul>
                        <li><strong>Table Name:</strong> <?php echo esc_html($import_stats['table_name']); ?></li>
                        <li><strong>Rows Imported:</strong> <?php echo number_format($import_stats['rows_imported']); ?></li>
                        <li><strong>Execution Time:</strong> <?php echo number_format($import_stats['execution_time'], 2); ?> seconds</li>
                        <li><strong>Table Type:</strong> <?php echo $import_stats['is_permanent'] ? 'Permanent' : 'Temporary (24 hours)'; ?></li>
                    </ul>
                </div>
                
                <div class="next-steps">
                    <h3>Next Steps</h3>
                    
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="npc_match">
                        <?php wp_nonce_field('npc_match', 'npc_match_nonce'); ?>
                        <input type="hidden" name="table_name" value="<?php echo esc_attr($import_stats['table_name']); ?>">
                        <input type="hidden" name="sku_column" value="sku">
                        <?php submit_button('Match Records', 'primary', 'submit', true); ?>
                    </form>
                    
                    <p>Or you can:</p>
                    <ul>
                        <li><a href="<?php echo esc_url(admin_url('admin.php?page=npc')); ?>">Import another file</a></li>
                        <li><a href="<?php echo esc_url(admin_url('admin.php?page=npc-tables')); ?>">Manage import tables</a></li>
                    </ul>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Display the match step.
     *
     * @since    1.0.0
     */
    private function display_match_step() {
        // Get import statistics from transient
        $import_stats = get_transient('npc_import_stats');
        
        if (empty($import_stats)) {
            wp_die('Import statistics not found. Please try again.');
        }
        
        $table_name = $import_stats['table_name'];
        
        // Create a processor instance to get table info
        $processor = new NPC_Processor();
        $table_info = $processor->get_table_info($table_name);
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <div class="card">
                <h2>Match Records</h2>
                
                <div class="import-summary">
                    <h3>Import Summary</h3>
                    <ul>
                        <li><strong>Table Name:</strong> <?php echo esc_html($table_name); ?></li>
                        <li><strong>Rows Imported:</strong> <?php echo number_format($import_stats['rows_imported']); ?></li>
                        <li><strong>Available Columns:</strong> <?php echo esc_html(implode(', ', $table_info['columns'])); ?></li>
                    </ul>
                </div>
                
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="npc_match">
                    <?php wp_nonce_field('npc_match', 'npc_match_nonce'); ?>
                    
                    <input type="hidden" name="table_name" value="<?php echo esc_attr($table_name); ?>">
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="sku_column">Select Column to Match</label></th>
                            <td>
                                <select name="sku_column" id="sku_column" class="regular-text" required>
                                    <?php foreach ($table_info['columns'] as $column) : ?>
                                        <option value="<?php echo esc_attr($column); ?>"><?php echo esc_html($column); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">Select the column to match with Hollander numbers.</p>
                            </td>
                        </tr>
                    </table>
                    
                    <?php submit_button('Match Records', 'primary', 'submit', true); ?>
                </form>
            </div>
        </div>
        <?php
    }

    /**
     * Display the match results.
     *
     * @since    1.0.0
     */
    private function display_match_results() {
        global $wpdb;
        
        if (isset($_GET['message']) && $_GET['message'] === 'batch_held') {
            ?>
            <div class="notice notice-success is-dismissible">
                <p><?php _e('Batch has been successfully put on hold.', 'npc-vendor-portal-v2'); ?></p>
            </div>
            <?php
        }

        if (isset($_GET['error']) && $_GET['error'] === 'hold_failed') {
            ?>
            <div class="notice notice-error is-dismissible">
                <p><?php _e('Failed to put batch on hold. Please try again.', 'npc-vendor-portal-v2'); ?></p>
            </div>
            <?php
        }

        // Get match results from transient
        $match_results = get_transient('npc_match_results');
        
        if (empty($match_results)) {
            wp_die('Match results not found. Please try again.');
        }
        
        $table_name = $match_results['table_name'];
		$query = "
			SELECT DISTINCT 
                n.sku as 'Vendor SKU',
				n.price as 'Vendor Price',
				n.location as 'Vendor Location',
				n.quantity as 'Vendor Quantity',
				n.description as 'Vendor Description',
				h.hollander_no as 'NPC Hollander No',
				i.inventory_no as 'NPC Hardware No',	
				s.mfr_software_no as 'NPC Software',
				sds.Need_3mo as '3 Month Demand',  
				sds.Need_6mo as '6 Month Demand'
            FROM {$table_name} n
            INNER JOIN `test_play`.hollander h  
                ON n.sku COLLATE utf8mb4_unicode_ci = h.hollander_no COLLATE utf8mb4_unicode_ci
            INNER JOIN `test_play`.inventory_hollander_map ihm 
                ON h.hollander_id = ihm.hollander_id
            INNER JOIN `test_play`.inventory i 
                ON ihm.inventory_id = i.inventory_id
			INNER JOIN `test_play`.software s 
				on  i.inventory_id = s.inventory_id
			inner join npcwebsite.sales_demand_summary sds     
				ON sds.SKU COLLATE utf8mb4_general_ci = i.inventory_no COLLATE utf8mb4_general_ci 
            WHERE n.sku IS NOT NULL
        ";
		$results = $wpdb->get_results($query, ARRAY_A);
		// error_log(print_r($results, true));
		// die();
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <div class="notice notice-success">
                <p><strong>Success!</strong> SKU matching completed.</p>
            </div>
            
            <div class="card">
                <h2>Match Results</h2>
                
                <div class="match-stats">
                    <div class="match-stat-box">
                        <span class="match-stat-number"><?php echo esc_html(count($results)); ?></span>
                        <span class="match-stat-label">Total Matches</span>
                    </div>
                </div>
                
                <?php if (!empty($results)) : ?>
                    <div class="tablenav top">
                        <div class="tablenav-pages">
                            <span class="displaying-num"><?php echo count($results); ?> items</span>
                        </div>
                    </div>

                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th>Vendor SKU</th>
                                <th>Vendor Price</th>
                                <th>Vendor Location</th>
                                <th>Vendor Quantity</th>
                                <th>Vendor Description</th>
								<th>NPC Hollander No</th>
								<th>NPC Hardware No</th>
                                <th>NPC Software</th>
                                <th>3 Month Demand</th>
                                <th>6 Month Demand</th>
                                <?php 
                                // Get additional columns from first result
                                $first_row = reset($results);
                                foreach ($first_row as $key => $value) {
                                    if (!in_array($key, ['sku', 'hollander_no', 'mapped_sku', 'Vendor SKU', 'Vendor Price', 'Vendor Location', 'Vendor Quantity', 'Vendor Description', 'NPC Software', '3 Month Demand', '6 Month Demand', 'NPC Hollander No', 'NPC Hardware No'])) {
                                        echo '<th>' . esc_html(ucwords(str_replace('_', ' ', $key))) . '</th>';
                                    }
                                }
                                ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($results as $row) : ?>
                                <tr>
                                    <td><?php echo esc_html($row['Vendor SKU']); ?></td>
                                    <td><?php echo esc_html($row['Vendor Price']); ?></td>
                                    <td><?php echo esc_html($row['Vendor Location']); ?></td>
                                    <td><?php echo esc_html($row['Vendor Quantity']); ?></td>
                                    <td><?php echo esc_html($row['Vendor Description']); ?></td>
                                    <td><?php echo esc_html($row['NPC Hollander No']); ?></td>
                                    <td><?php echo esc_html($row['NPC Hardware No']); ?></td>
                                    <td><?php echo esc_html($row['NPC Software']); ?></td>
                                    <td><?php echo esc_html($row['3 Month Demand']); ?></td>
                                    <td><?php echo esc_html($row['6 Month Demand']); ?></td>
                                    <?php 
                                    foreach ($row as $key => $value) {
                                        if (!in_array($key, ['sku', 'hollander_no', 'mapped_sku', 'Vendor SKU', 'Vendor Price', 'Vendor Location', 'Vendor Quantity', 'Vendor Description', 'NPC Software', '3 Month Demand', '6 Month Demand', 'NPC Hollander No', 'NPC Hardware No'])) {
                                            echo '<td>' . esc_html($value) . '</td>';
                                        }
                                    }
                                    ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <div class="tablenav bottom">
                        <div class="tablenav-pages">
                            <span class="displaying-num"><?php echo count($results); ?> items</span>
                        </div>
                    </div>
                <?php else : ?>
                    <p>No matches found.</p>
                <?php endif; ?>
                
                <div class="export-options">
                    <h3>Export Results</h3>
                    
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="npc_export">
                        <?php wp_nonce_field('npc_export', 'npc_export_nonce'); ?>
                        
                        <input type="hidden" name="table_name" value="<?php echo esc_attr($match_results['table_name']); ?>">
                        
                        <?php submit_button('Export to CSV', 'secondary', 'submit', true); ?>
                    </form>

                    <h3>Create Purchase Orders</h3>
                    <p>Create purchase orders based on the matched results and demand data.</p>
                    
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="npc_create_po">
                        <?php wp_nonce_field('npc_create_po', 'npc_create_po_nonce'); ?>
                        
                        <input type="hidden" name="table_name" value="<?php echo esc_attr($match_results['table_name']); ?>">
                        
                        <?php submit_button('Create Purchase Orders', 'primary', 'submit', true); ?>
                    </form>

                    <h3>Hold Batch</h3>
                    <p>Mark this batch as held for later processing.</p>
                    
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="npc_hold_batch">
                        <?php wp_nonce_field('npc_hold_batch', 'npc_hold_batch_nonce'); ?>
                        
                        <input type="hidden" name="table_name" value="<?php echo esc_attr($match_results['table_name']); ?>">
                        
                        <?php submit_button('Hold Batch', 'secondary', 'submit', true); ?>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Display the import tables page.
     *
     * @since    1.0.0
     */
    public function display_import_tables_page() {
        // Create a processor instance
        $processor = new NPC_Processor();
        $tables = $processor->get_import_tables(true);
        
        // Check for messages
        $message = isset($_GET['message']) ? sanitize_text_field($_GET['message']) : '';
        
        // At the beginning of display_import_tables_page()
        if (isset($_GET['action']) && $_GET['action'] === 'view' && 
            isset($_GET['table_name']) && isset($_GET['_wpnonce']) && 
            wp_verify_nonce($_GET['_wpnonce'], 'view_table_data')) {
            
            $table_name = sanitize_text_field($_GET['table_name']);
            global $wpdb;
            
            // Pagination settings
            $per_page = 20; // Number of records per page
            $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
            $offset = ($current_page - 1) * $per_page;
            
            // Get total count of records
            $total_items = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
            $total_pages = ceil($total_items / $per_page);
            
            // Get paginated data
            $results = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table_name} LIMIT %d OFFSET %d", 
                    $per_page, 
                    $offset
                ), 
                ARRAY_A
            );
            
            if (empty($results)) {
                echo '<div class="wrap">';
                echo '<h1>' . esc_html(get_admin_page_title()) . '</h1>';
                echo '<div class="notice notice-error"><p>No data found in table.</p></div>';
                echo '<a href="' . esc_url(admin_url('admin.php?page=npc-tables')) . '" class="button">Back to Tables</a>';
                echo '</div>';
                return;
            }
            
            echo '<div class="wrap">';
            echo '<h1>' . esc_html(get_admin_page_title()) . '</h1>';
            echo '<h2>Table: ' . esc_html($table_name) . '</h2>';
            echo '<a href="' . esc_url(admin_url('admin.php?page=npc-tables')) . '" class="button">Back to Tables</a>';
            
            // Pagination controls - top
            if ($total_pages > 1) {
                echo '<div class="tablenav top">';
                echo '<div class="tablenav-pages">';
                echo '<span class="displaying-num">' . number_format($total_items) . ' items</span>';
                
                // Previous page link
                if ($current_page > 1) {
                    echo '<a class="prev-page button" href="' . esc_url(add_query_arg(array(
                        'paged' => $current_page - 1,
                        'action' => 'view',
                        'table_name' => $table_name,
                        '_wpnonce' => wp_create_nonce('view_table_data')
                    ), admin_url('admin.php?page=npc-tables'))) . '"><span class="screen-reader-text">Previous page</span><span aria-hidden="true">‹</span></a>';
                }
                
                // Page numbers
                echo '<span class="pagination-links">';
                echo '<span class="paging-input">' . $current_page . ' of <span class="total-pages">' . $total_pages . '</span></span>';
                
                // Next page link
                if ($current_page < $total_pages) {
                    echo '<a class="next-page button" href="' . esc_url(add_query_arg(array(
                        'paged' => $current_page + 1,
                        'action' => 'view',
                        'table_name' => $table_name,
                        '_wpnonce' => wp_create_nonce('view_table_data')
                    ), admin_url('admin.php?page=npc-tables'))) . '"><span class="screen-reader-text">Next page</span><span aria-hidden="true">›</span></a>';
                }
                
                echo '</span></div></div>';
            }
            
            echo '<div style="margin-top: 20px; overflow-x: auto;">';
            echo '<table class="widefat striped">';
            
            // Headers
            echo '<thead><tr>';
            foreach (array_keys($results[0]) as $header) {
                echo '<th>' . esc_html($header) . '</th>';
            }
            echo '</tr></thead>';
            
            // Data
            echo '<tbody>';
            foreach ($results as $row) {
                echo '<tr>';
                foreach ($row as $value) {
                    echo '<td>' . esc_html($value) . '</td>';
                }
                echo '</tr>';
            }
            echo '</tbody>';
            echo '</table>';
            echo '</div>';
            
            // Pagination controls - bottom
            if ($total_pages > 1) {
                echo '<div class="tablenav bottom">';
                echo '<div class="tablenav-pages">';
                echo '<span class="displaying-num">' . number_format($total_items) . ' items</span>';
                
                // Previous page link
                if ($current_page > 1) {
                    echo '<a class="prev-page button" href="' . esc_url(add_query_arg(array(
                        'paged' => $current_page - 1,
                        'action' => 'view',
                        'table_name' => $table_name,
                        '_wpnonce' => wp_create_nonce('view_table_data')
                    ), admin_url('admin.php?page=npc-tables'))) . '"><span class="screen-reader-text">Previous page</span><span aria-hidden="true">‹</span></a>';
                }
                
                // Page numbers
                echo '<span class="pagination-links">';
                echo '<span class="paging-input">' . $current_page . ' of <span class="total-pages">' . $total_pages . '</span></span>';
                
                // Next page link
                if ($current_page < $total_pages) {
                    echo '<a class="next-page button" href="' . esc_url(add_query_arg(array(
                        'paged' => $current_page + 1,
                        'action' => 'view',
                        'table_name' => $table_name,
                        '_wpnonce' => wp_create_nonce('view_table_data')
                    ), admin_url('admin.php?page=npc-tables'))) . '"><span class="screen-reader-text">Next page</span><span aria-hidden="true">›</span></a>';
                }
                
                echo '</span></div></div>';
            }
            
            echo '</div>';
            return;
        }
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <?php if ($message === 'table_dropped') : ?>
                <div class="notice notice-success is-dismissible">
                    <p>Table dropped successfully.</p>
                </div>
            <?php elseif ($message === 'all_tables_dropped') : ?>
                <div class="notice notice-success is-dismissible">
                    <p>All tables dropped successfully.</p>
                </div>
            <?php endif; ?>
            
            <div class="card">
                <h2>Imported Data Tables</h2>
                
                <?php if (!empty($tables)) : ?>
                    <div class="tablenav top">
                        <div class="alignleft actions">
                            <a href="<?php echo esc_url(wp_nonce_url(
                                admin_url('admin-post.php?action=npc_drop_all_tables'),
                                'npc_drop_all_tables',
                                'npc_drop_all_nonce'
                            )); ?>" 
                            class="button button-secondary" 
                            onclick="return confirm('Are you sure you want to drop all import tables? This action cannot be undone.');">
                                Drop All Tables
                            </a>
                        </div>
                    </div>
                    
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th>Table Name</th>
                                <th>Vendor</th>
                                <th>Created Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tables as $table) : ?>
                                <tr>
                                    <td><?php echo esc_html($table['table_name']); ?></td>
                                    <td><?php echo esc_html($table['vendor_name']); ?></td>
                                    <td><?php echo esc_html($table['created_date']); ?></td>
                                    <td>
                                        <a href="<?php echo esc_url(add_query_arg(
                                            array(
                                                'page' => 'npc-tables',
                                                'action' => 'view',
                                                'table_name' => $table['table_name'],
                                                '_wpnonce' => wp_create_nonce('view_table_data')
                                            ),
                                            admin_url('admin.php')
                                        )); ?>" class="button button-primary">View Data</a>
                                        
                                        <a href="<?php echo esc_url(wp_nonce_url(
                                            admin_url('admin-post.php?action=npc_drop_table&table=' . $table['table_name']),
                                            'npc_drop_table',
                                            'npc_drop_nonce'
                                        )); ?>" 
                                        class="button button-secondary" 
                                        onclick="return confirm('Are you sure you want to drop this table?');">
                                            Drop Table
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else : ?>
                    <p>No import tables found.</p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Handle CSV file upload.
     *
     * @since    1.0.0
     */
    public function handle_csv_upload() {
        // Verify nonce
        if (!isset($_POST['npc_upload_nonce']) || !wp_verify_nonce($_POST['npc_upload_nonce'], 'npc_upload')) {
            wp_die('Invalid nonce specified', 'Error', array('response' => 403));
        }
        
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_die('You do not have sufficient permissions to access this page.');
        }
        
        // Check if a file was uploaded
        if (!isset($_FILES['csv_file'])) {
            wp_die('No file was uploaded.');
        }
        
        $file = $_FILES['csv_file'];
        
        // Check for upload errors
        if ($file['error'] !== UPLOAD_ERR_OK) {
            wp_die('Upload failed with error code ' . $file['error']);
        }
        
        // Verify file type
        $file_type = wp_check_filetype(basename($file['name']), array('csv' => 'text/csv'));
        if ($file_type['ext'] !== 'csv') {
            wp_die('Please upload a valid CSV file.');
        }
        
        // Create uploads directory if it doesn't exist
        $upload_dir = wp_upload_dir();
        $target_dir = $upload_dir['basedir'] . '/npc/';
        if (!file_exists($target_dir)) {
            wp_mkdir_p($target_dir);
        }
        
        // Generate unique filename
        $filename = wp_unique_filename($target_dir, $file['name']);
        $target_file = $target_dir . $filename;
        
        // Move uploaded file
        if (!move_uploaded_file($file['tmp_name'], $target_file)) {
            wp_die('Failed to move uploaded file.');
        }
        
        // Store the file path in a transient
        set_transient('npc_file', $target_file, HOUR_IN_SECONDS);
        
        // Redirect to mapping step
        wp_redirect(admin_url('admin.php?page=npc&step=mapping'));
        exit;
    }

    /**
     * Handle column mapping form submission.
     *
     * @since    1.0.0
     */
    public function handle_column_mapping() {
        // Verify nonce with matching name
        if (!isset($_POST['npc_map_columns_nonce']) || 
            !wp_verify_nonce($_POST['npc_map_columns_nonce'], 'npc_map_columns')) {
            wp_die('Invalid nonce specified', 'Error', array('response' => 403));
        }
        
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_die('You do not have sufficient permissions to access this page.');
        }
        
        // Get the mapping from POST data
        $mapping = isset($_POST['column_mapping']) ? $_POST['column_mapping'] : array();
        
        // Verify required fields
        if (empty($mapping['sku'])) {
            wp_die('SKU mapping is required.');
        }
        
        // Store the mapping in a transient
        set_transient('npc_mapping', $mapping, HOUR_IN_SECONDS);
        
        // Redirect to preview step
        wp_redirect(admin_url('admin.php?page=npc&step=preview'));
        exit;
    }

    /**
     * Handle data processing form submission.
     *
     * @since    1.0.0
     */
    public function process_csv_data() {
        // Verify nonce with matching name
        if (!isset($_POST['npc_process_nonce']) || 
            !wp_verify_nonce($_POST['npc_process_nonce'], 'npc_process')) {
            wp_die('Invalid nonce specified', 'Error', array('response' => 403));
        }
        
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_die('You do not have sufficient permissions to access this page.');
        }
        
        // Get processing options from POST data
        $group_by = isset($_POST['group_by']) ? $_POST['group_by'] : array();
        $aggregate = isset($_POST['aggregate']) ? $_POST['aggregate'] : array();
        $deduplicate = isset($_POST['deduplicate']) ? (bool)$_POST['deduplicate'] : false;
        
        // Get the CSV file and mapping from transients
        $csv_file = get_transient('npc_file');
        $mapping = get_transient('npc_mapping');
        
        if (empty($csv_file) || empty($mapping)) {
            wp_die('CSV file or mapping not found. Please try again.');
        }
        
        // Create a processor instance
        $processor = new NPC_Processor($csv_file);
        $processor->set_column_mapping($mapping);
        
        // Create the mapped CSV file
        $mapped_file = $processor->create_mapped_csv($group_by, $aggregate, $deduplicate);
        
        if ($mapped_file === false) {
            wp_die('Failed to process CSV file.');
        }
        
        // Store the mapped file path in a transient
        set_transient('npc_mapped_file', $mapped_file, HOUR_IN_SECONDS);
        
        // Redirect to results step
        wp_redirect(admin_url('admin.php?page=npc&step=results'));
        exit;
    }

    /**
     * Handle database import form submission.
     *
     * @since    1.0.0
     */
    public function import_to_database() {
        // Verify nonce with matching name
        if (!isset($_POST['npc_import_nonce']) || 
            !wp_verify_nonce($_POST['npc_import_nonce'], 'npc_import')) {
            wp_die('Invalid nonce specified', 'Error', array('response' => 403));
        }
        
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_die('You do not have sufficient permissions to access this page.');
        }
        
        // Get import options from POST data
        $vendor_name = isset($_POST['vendor_name']) ? sanitize_title($_POST['vendor_name']) : '';
        $is_permanent = isset($_POST['is_permanent']) ? (bool)$_POST['is_permanent'] : false;
        
        if (empty($vendor_name)) {
            wp_die('Vendor name is required.');
        }
        
        // Get the mapped file from transient
        $mapped_file = get_transient('npc_mapped_file');
        
        if (empty($mapped_file)) {
            wp_die('Mapped file not found. Please try again.');
        }
        
        // Create a processor instance
        $processor = new NPC_Processor($mapped_file);
        
        // Import to database
        $start_time = microtime(true);
        $result = $processor->import_to_database($vendor_name, $is_permanent);
        $execution_time = microtime(true) - $start_time;
        
        if ($result === false) {
            wp_die('Failed to import data to database.');
        }
        
        // Store import statistics in a transient
        $import_stats = array(
            'table_name' => $result['table_name'],
            'rows_imported' => $result['rows_imported'],
            'execution_time' => $execution_time,
            'is_permanent' => $is_permanent,
            'vendor_name' => $vendor_name
        );
        set_transient('npc_import_stats', $import_stats, HOUR_IN_SECONDS);
        
        // Redirect to database results step
        wp_redirect(admin_url('admin.php?page=npc&step=database'));
        exit;
    }

    /**
     * Handle SKU matching form submission.
     *
     * @since    1.0.0
     */
    public function match_skus() {
        // Verify nonce
        if (!isset($_POST['npc_match_nonce']) || !wp_verify_nonce($_POST['npc_match_nonce'], 'npc_match')) {
            wp_die('Invalid nonce specified', 'Error', array('response' => 403));
        }
        
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_die('You do not have sufficient permissions to access this page.');
        }
        
        // Get table name from POST data
        $table_name = isset($_POST['table_name']) ? sanitize_text_field($_POST['table_name']) : '';
        
        if (empty($table_name)) {
            wp_die('Table name is required.');
        }
        
        // Create a processor instance
        $processor = new NPC_Processor();
        
        // Perform matching with hardcoded values
        $results = $processor->match_skus(
            $table_name,
            'sku',  // Hardcoded SKU column
            'test_play.hollander',  // Hardcoded Hollander table
            'hollander_no',  // Hardcoded Hollander column
            'test_play.inventory',  // Hardcoded inventory table
            'sku'  // Hardcoded inventory column
        );
        
        if ($results === false) {
            wp_die('Failed to match SKUs.');
        }
        
        // Store match results in a transient
        set_transient('npc_match_results', $results, HOUR_IN_SECONDS);
        
        // Redirect to match results step
        wp_redirect(admin_url('admin.php?page=npc&step=match_results'));
        exit;
    }

    /**
     * Handle match results export.
     *
     * @since    1.0.0
     */
    public function export_match_results() {
        // Verify nonce and permissions
        if (!isset($_POST['npc_export_nonce']) || !wp_verify_nonce($_POST['npc_export_nonce'], 'npc_export')) {
            wp_die('Invalid nonce specified', 'Error', array('response' => 403));
        }
        
        if (!current_user_can('manage_options')) {
            wp_die('You do not have sufficient permissions to access this page.');
        }
        
        $table_name = isset($_POST['table_name']) ? sanitize_text_field($_POST['table_name']) : '';
        
        if (empty($table_name)) {
            wp_die('Table name is required.');
        }
        
        global $wpdb;
        
        // Use the exact match results query
        $query = $wpdb->prepare("
            SELECT DISTINCT 
                n.*,
                h.hollander_no, 
                i.inventory_no AS mapped_sku
            FROM {$table_name} n
            INNER JOIN `test_play`.hollander h
                ON n.sku COLLATE utf8mb4_unicode_ci = h.hollander_no COLLATE utf8mb4_unicode_ci
            INNER JOIN `test_play`.inventory_hollander_map ihm 
                ON h.hollander_id = ihm.hollander_id
            INNER JOIN `test_play`.inventory i 
                ON ihm.inventory_id = i.inventory_id
            WHERE n.sku IS NOT NULL
        ");
        
        $results = $wpdb->get_results($query, ARRAY_A);
        
        if (empty($results)) {
            wp_die('No matched results found to export.');
        }
        
        // Clean output buffer
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        // Set headers for CSV download
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="matched_results_' . date('Y-m-d_H-i-s') . '.csv"');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        try {
            $output = fopen('php://output', 'w');
            
            if ($output === false) {
                throw new Exception('Failed to open output stream');
            }
            
            // Get headers dynamically from the first row
            $headers = array_keys($results[0]);
            
            // Write headers
            fputcsv($output, $headers);
            
            // Write data rows
            foreach ($results as $row) {
                fputcsv($output, $row);
            }
            
            fclose($output);
            
        } catch (Exception $e) {
            wp_die('Export failed: ' . $e->getMessage());
        }
        
        exit;
    }

    /**
     * Handle table drop request.
     *
     * @since    1.0.0
     */
    public function handle_drop_table() {
        // Verify nonce
        if (!isset($_GET['npc_drop_nonce']) || !wp_verify_nonce($_GET['npc_drop_nonce'], 'npc_drop_table')) {
            wp_die('Invalid nonce specified', 'Error', array('response' => 403));
        }
        
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_die('You do not have sufficient permissions to access this page.');
        }
        
        // Get table name from URL
        $table_name = isset($_GET['table']) ? sanitize_text_field($_GET['table']) : '';
        
        if (empty($table_name)) {
            wp_die('No table specified.');
        }
        
        // Create a processor instance
        $processor = new NPC_Processor();
        
        // Drop the table
        $result = $processor->delete_import_table($table_name);
        
        if ($result) {
            // Redirect back to tables page with success message
            wp_redirect(add_query_arg(
                array(
                    'page' => 'npc-tables',
                    'message' => 'table_dropped'
                ),
                admin_url('admin.php')
            ));
        } else {
            wp_die('Failed to drop table.');
        }
        
        exit;
    }

    /**
     * Handle drop all tables request.
     *
     * @since    1.0.0
     */
    public function handle_drop_all_tables() {
        // Verify nonce
        if (!isset($_GET['npc_drop_all_nonce']) || !wp_verify_nonce($_GET['npc_drop_all_nonce'], 'npc_drop_all_tables')) {
            wp_die('Invalid nonce specified', 'Error', array('response' => 403));
        }
        
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_die('You do not have sufficient permissions to access this page.');
        }
        
        // Create a processor instance
        $processor = new NPC_Processor();
        
        // Get all import tables
        $tables = $processor->get_import_tables();
        
        // Drop each table
        $success = true;
        foreach ($tables as $table_name) {
            if (!$processor->delete_import_table($table_name)) {
                $success = false;
                break;
            }
        }
        
        if ($success) {
            // Redirect back to tables page with success message
            wp_redirect(add_query_arg(
                array(
                    'page' => 'npc-tables',
                    'message' => 'all_tables_dropped'
                ),
                admin_url('admin.php')
            ));
        } else {
            wp_die('Failed to drop all tables.');
        }
        
        exit;
    }

    /**
     * Display the create PO form.
     *
     * @since    1.0.0
     */
    private function display_create_po() {
        global $wpdb;
        
        // Get match results from transient
        $match_results = get_transient('npc_match_results');
        
        if (empty($match_results)) {
            wp_die('Match results not found. Please try again.');
        }
        
        $table_name = $match_results['table_name'];
        
        // Get the matched results with demand data
        $query = "
            SELECT DISTINCT 
                n.sku as 'Vendor SKU',
                n.price as 'Vendor Price',
                n.location as 'Vendor Location',
                n.quantity as 'Vendor Quantity',
                n.description as 'Vendor Description',
                s.mfr_software_no as 'NPC Software',
                sds.Need_3mo as '3 Month Demand',  
                sds.Need_6mo as '6 Month Demand',
                CASE 
                    WHEN sds.Need_3mo > 0 THEN LEAST(sds.Need_3mo, n.quantity)
                    ELSE 0 
                END as 'Suggested Order Quantity'
            FROM {$table_name} n
            INNER JOIN `test_play`.hollander h  
                ON n.sku COLLATE utf8mb4_unicode_ci = h.hollander_no COLLATE utf8mb4_unicode_ci
            INNER JOIN `test_play`.inventory_hollander_map ihm 
                ON h.hollander_id = ihm.hollander_id
            INNER JOIN `test_play`.inventory i 
                ON ihm.inventory_id = i.inventory_id
            INNER JOIN `test_play`.software s 
                on i.inventory_id = s.inventory_id
            INNER JOIN npcwebsite.sales_demand_summary sds     
                ON sds.SKU COLLATE utf8mb4_general_ci = i.inventory_no COLLATE utf8mb4_general_ci 
            WHERE n.sku IS NOT NULL
            AND sds.Need_3mo > 0
            ORDER BY sds.Need_3mo DESC
        ";
        
        $results = $wpdb->get_results($query, ARRAY_A);
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <div class="card">
                <h2>Create Purchase Orders</h2>
                
                <?php if (!empty($results)) : ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="npc_create_po">
                        <?php wp_nonce_field('npc_create_po', 'npc_create_po_nonce'); ?>
                        
                        <input type="hidden" name="table_name" value="<?php echo esc_attr($table_name); ?>">
                        
                        <table class="widefat striped">
                            <thead>
                                <tr>
                                    <th><input type="checkbox" id="select-all"></th>
                                    <th>Vendor SKU</th>
                                    <th>NPC Software</th>
                                    <th>Vendor Price</th>
                                    <th>Available Quantity</th>
                                    <th>3 Month Demand</th>
                                    <th>Suggested Order Quantity</th>
                                    <th>Order Quantity</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($results as $row) : ?>
                                    <tr>
                                        <td>
                                            <input type="checkbox" name="items[]" value="<?php echo esc_attr($row['Vendor SKU']); ?>">
                                        </td>
                                        <td><?php echo esc_html($row['Vendor SKU']); ?></td>
                                        <td><?php echo esc_html($row['NPC Software']); ?></td>
                                        <td><?php echo esc_html($row['Vendor Price']); ?></td>
                                        <td><?php echo esc_html($row['Vendor Quantity']); ?></td>
                                        <td><?php echo esc_html($row['3 Month Demand']); ?></td>
                                        <td><?php echo esc_html($row['Suggested Order Quantity']); ?></td>
                                        <td>
                                            <input type="number" 
                                                name="quantity[<?php echo esc_attr($row['Vendor SKU']); ?>]" 
                                                value="<?php echo esc_attr($row['Suggested Order Quantity']); ?>"
                                                min="0"
                                                max="<?php echo esc_attr($row['Vendor Quantity']); ?>"
                                                class="small-text">
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        
                        <div class="po-options">
                            <h3>Purchase Order Options</h3>
                            <table class="form-table">
                                <tr>
                                    <th scope="row"><label for="po_reference">PO Reference</label></th>
                                    <td>
                                        <input type="text" name="po_reference" id="po_reference" class="regular-text" required>
                                        <p class="description">Enter a reference number for this purchase order.</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="po_notes">Notes</label></th>
                                    <td>
                                        <textarea name="po_notes" id="po_notes" class="large-text" rows="3"></textarea>
                                        <p class="description">Add any notes or special instructions for this purchase order.</p>
                                    </td>
                                </tr>
                            </table>
                        </div>
                        
                        <?php submit_button('Generate Purchase Order', 'primary', 'submit', true); ?>
                    </form>
                    
                    <script>
                    jQuery(document).ready(function($) {
                        // Handle select all checkbox
                        $('#select-all').on('change', function() {
                            $('input[name="items[]"]').prop('checked', $(this).prop('checked'));
                        });
                        
                        // Update select all when individual checkboxes change
                        $('input[name="items[]"]').on('change', function() {
                            var allChecked = $('input[name="items[]"]:checked').length === $('input[name="items[]"]').length;
                            $('#select-all').prop('checked', allChecked);
                        });
                    });
                    </script>
                <?php else : ?>
                    <div class="notice notice-warning">
                        <p>No items found with demand in the next 3 months.</p>
                    </div>
                    
                    <p>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=npc&step=match_results')); ?>" class="button">Back to Match Results</a>
                    </p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Handle PO creation form submission.
     *
     * @since    1.0.0
     */
    public function handle_create_po() {
        // Verify nonce
        if (!isset($_POST['npc_create_po_nonce']) || !wp_verify_nonce($_POST['npc_create_po_nonce'], 'npc_create_po')) {
            wp_die('Invalid nonce specified', 'Error', array('response' => 403));
        }
        
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_die('You do not have sufficient permissions to access this page.');
        }
        
        // Get form data
        $table_name = isset($_POST['table_name']) ? sanitize_text_field($_POST['table_name']) : '';
        $po_reference = isset($_POST['po_reference']) ? sanitize_text_field($_POST['po_reference']) : '';
        $po_notes = isset($_POST['po_notes']) ? sanitize_textarea_field($_POST['po_notes']) : '';
        $selected_items = isset($_POST['items']) ? array_map('sanitize_text_field', $_POST['items']) : array();
        $quantities = isset($_POST['quantity']) ? array_map('intval', $_POST['quantity']) : array();
        
        if (empty($table_name) || empty($po_reference) || empty($selected_items)) {
            wp_die('Required fields are missing.');
        }
        
        global $wpdb;
        
        // Start transaction
        $wpdb->query('START TRANSACTION');
        
        try {
            // Insert PO header
            $wpdb->insert(
                'purchase_orders',
                array(
                    'reference_number' => $po_reference,
                    'notes' => $po_notes,
                    'status' => 'pending',
                    'created_at' => current_time('mysql'),
                    'created_by' => get_current_user_id()
                ),
                array('%s', '%s', '%s', '%s', '%d')
            );
            
            $po_id = $wpdb->insert_id;
            
            if (!$po_id) {
                throw new Exception('Failed to create purchase order header.');
            }
            
            // Insert PO items
            foreach ($selected_items as $sku) {
                if (!isset($quantities[$sku]) || $quantities[$sku] <= 0) {
                    continue;
                }
                
                // Get item details
                $item = $wpdb->get_row($wpdb->prepare("
                    SELECT 
                        n.sku,
                        n.price,
                        n.description,
                        s.mfr_software_no as npc_sku
                    FROM {$table_name} n
                    INNER JOIN `test_play`.hollander h  
                        ON n.sku COLLATE utf8mb4_unicode_ci = h.hollander_no COLLATE utf8mb4_unicode_ci
                    INNER JOIN `test_play`.inventory_hollander_map ihm 
                        ON h.hollander_id = ihm.hollander_id
                    INNER JOIN `test_play`.inventory i 
                        ON ihm.inventory_id = i.inventory_id
                    INNER JOIN `test_play`.software s 
                        on i.inventory_id = s.inventory_id
                    WHERE n.sku = %s
                    LIMIT 1
                ", $sku), ARRAY_A);
                
                if (!$item) {
                    continue;
                }
                
                $wpdb->insert(
                    'purchase_order_items',
                    array(
                        'po_id' => $po_id,
                        'vendor_sku' => $item['sku'],
                        'npc_sku' => $item['npc_sku'],
                        'description' => $item['description'],
                        'quantity' => $quantities[$sku],
                        'unit_price' => $item['price'],
                        'total_price' => $quantities[$sku] * $item['price']
                    ),
                    array('%d', '%s', '%s', '%s', '%d', '%f', '%f')
                );
                
                if ($wpdb->last_error) {
                    throw new Exception('Failed to create purchase order item: ' . $wpdb->last_error);
                }
            }
            
            // Commit transaction
            $wpdb->query('COMMIT');
            
            // Store PO ID in transient for display
            set_transient('npc_created_po_id', $po_id, HOUR_IN_SECONDS);
            
            // Redirect to PO view page
            wp_redirect(admin_url('admin.php?page=npc&step=view_po&id=' . $po_id));
            exit;
            
        } catch (Exception $e) {
            // Rollback transaction
            $wpdb->query('ROLLBACK');
            wp_die('Failed to create purchase order: ' . $e->getMessage());
        }
    }

    /**
     * Handle the hold batch action
     */
    public function handle_hold_batch() {
        // Verify nonce
        if (!isset($_POST['npc_hold_batch_nonce']) || !wp_verify_nonce($_POST['npc_hold_batch_nonce'], 'npc_hold_batch')) {
            wp_die('Invalid nonce specified', 'Error', array(
                'response' => 403,
                'back_link' => true,
            ));
        }

        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_die('You do not have sufficient permissions to access this page.');
        }

        global $wpdb;
        error_log('NPC Plugin: Holding batch');
        
        // Get and sanitize the table name
        $table_name = sanitize_text_field($_POST['table_name']);
        
        // First, check if the is_flagged column exists in the table
        $columns = $wpdb->get_results("SHOW COLUMNS FROM `{$table_name}` LIKE 'is_flagged'");
        
        // If the column doesn't exist, add it
        if (empty($columns)) {
            $wpdb->query("ALTER TABLE `{$table_name}` ADD COLUMN `is_flagged` TINYINT(1) DEFAULT 0");
        }
        
        // Update all rows in the table to set is_flagged to 1
        $result = $wpdb->query("UPDATE `{$table_name}` SET `is_flagged` = 1");
        
        error_log('NPC Plugin: Holding batch result: ' . $result);

        if ($result === false) {
            // Handle error
            wp_redirect(add_query_arg(
                array(
                    'page' => 'npc',
                    'step' => 'match_results',
                    'error' => 'hold_failed'
                ),
                admin_url('admin.php')
            ));
            exit;
        }

        // Redirect back to match results with success message
        wp_redirect(add_query_arg(
            array(
                'page' => 'npc',
                'step' => 'match_results',
                'message' => 'batch_held'
            ),
            admin_url('admin.php')
        ));
        exit;
    }
}
				