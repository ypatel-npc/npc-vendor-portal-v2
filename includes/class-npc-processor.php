<?php
/**
 * The class responsible for CSV processing.
 *
 * @since      1.0.0
 */
class NPC_Processor {

    /**
     * The path to the uploaded CSV file.
     *
     * @since    1.0.0
     * @access   private
     * @var      string    $csv_file    The path to the uploaded CSV file.
     */
    private $csv_file;

    /**
     * The CSV headers.
     *
     * @since    1.0.0
     * @access   private
     * @var      array    $headers    The CSV headers.
     */
    private $headers;

    /**
     * The column mapping.
     *
     * @since    1.0.0
     * @access   private
     * @var      array    $column_mapping    The column mapping.
     */
    private $column_mapping;

    /**
     * Initialize the class.
     *
     * @since    1.0.0
     * @param    string    $csv_file    The path to the uploaded CSV file.
     */
    public function __construct($csv_file = null) {
        if ($csv_file) {
            $this->csv_file = $csv_file;
            $this->headers = $this->get_csv_headers();
        }
    }

    /**
     * Set the CSV file.
     *
     * @since    1.0.0
     * @param    string    $csv_file    The path to the uploaded CSV file.
     */
    public function set_csv_file($csv_file) {
        $this->csv_file = $csv_file;
        $this->headers = $this->get_csv_headers();
    }

    /**
     * Get the CSV headers.
     *
     * @since    1.0.0
     * @return   array    The CSV headers.
     */
    public function get_csv_headers() {
        if (!file_exists($this->csv_file)) {
            return array();
        }

        $handle = fopen($this->csv_file, 'r');
        $headers = fgetcsv($handle);
        fclose($handle);

        return $headers;
    }

    /**
     * Get a preview of the CSV data.
     *
     * @since    1.0.0
     * @param    int    $rows    The number of rows to preview.
     * @return   array    The CSV preview data.
     */
    public function get_csv_preview($rows = 5) {
        if (!file_exists($this->csv_file)) {
            return array();
        }

        $handle = fopen($this->csv_file, 'r');
        $headers = fgetcsv($handle);
        
        $preview = array();
        $count = 0;
        
        while (($data = fgetcsv($handle)) !== false && $count < $rows) {
            $row = array();
            foreach ($headers as $index => $header) {
                $row[$header] = isset($data[$index]) ? $data[$index] : '';
            }
            $preview[] = $row;
            $count++;
        }
        
        fclose($handle);
        
        return array(
            'headers' => $headers,
            'data' => $preview
        );
    }

    /**
     * Set the column mapping.
     *
     * @since    1.0.0
     * @param    array    $mapping    The column mapping.
     */
    public function set_column_mapping($mapping) {
        $this->column_mapping = $mapping;
    }

    /**
     * Get the column mapping.
     *
     * @since    1.0.0
     * @return   array    The column mapping.
     */
    public function get_column_mapping() {
        return $this->column_mapping;
    }

    /**
     * Get a preview of the mapped data.
     *
     * @since    1.0.0
     * @param    int    $rows    The number of rows to preview.
     * @return   array    The mapped preview data.
     */
    public function get_mapped_preview($rows = 5) {
        if (!file_exists($this->csv_file) || empty($this->column_mapping)) {
            return array();
        }

        $handle = fopen($this->csv_file, 'r');
        $headers = fgetcsv($handle);
        
        $preview = array();
        $count = 0;
        
        while (($data = fgetcsv($handle)) !== false && $count < $rows) {
            $row = array();
            foreach ($this->column_mapping as $target_column => $source_column) {
                $index = array_search($source_column, $headers);
                if ($index !== false) {
                    $row[$target_column] = isset($data[$index]) ? $data[$index] : '';
                } else {
                    $row[$target_column] = '';
                }
            }
            $preview[] = $row;
            $count++;
        }
        
        fclose($handle);
        
        return array(
            'headers' => array_keys($this->column_mapping),
            'data' => $preview
        );
    }

    /**
     * Create a new CSV file with the mapped columns.
     *
     * @since    1.0.0
     * @param    array    $group_by    Optional. Columns to group by.
     * @param    array    $aggregate   Optional. Columns to aggregate with their functions (sum, avg, etc).
     * @param    bool     $deduplicate Optional. Whether to remove duplicate rows.
     * @return   string|bool    The path to the mapped CSV file, or false on failure.
     */
    public function create_mapped_csv($group_by = array(), $aggregate = array(), $deduplicate = false) {
        if (!file_exists($this->csv_file) || empty($this->column_mapping)) {
            return false;
        }

        // Create a unique filename for the mapped CSV
        $upload_dir = wp_upload_dir();
        $npc_dir = $upload_dir['basedir'] . '/npc';
        
        if (!file_exists($npc_dir)) {
            wp_mkdir_p($npc_dir);
        }
        
        $mapped_filename = 'mapped-' . time() . '.csv';
        $mapped_file_path = $npc_dir . '/' . $mapped_filename;
        
        // Open the source file for reading
        $source_handle = fopen($this->csv_file, 'r');
        if (!$source_handle) {
            return false;
        }
        
        // Read the headers from the source file
        $source_headers = fgetcsv($source_handle);
        
        // Target headers are the keys of the column mapping
        $target_headers = array_keys($this->column_mapping);
        
        // If we're grouping or deduplicating, we need to process all data first
        if (!empty($group_by) || $deduplicate) {
            // Read all data into memory
            $all_data = array();
            
            while (($data = fgetcsv($source_handle)) !== false) {
                $row = array();
                
                // Map the columns according to the mapping
                foreach ($this->column_mapping as $target_column => $source_column) {
                    $index = array_search($source_column, $source_headers);
                    if ($index !== false) {
                        $row[$target_column] = isset($data[$index]) ? $data[$index] : '';
                    } else {
                        $row[$target_column] = '';
                    }
                }
                
                $all_data[] = $row;
            }
            
            // Close the source file
            fclose($source_handle);
            
            // Process the data (group or deduplicate)
            if (!empty($group_by)) {
                $all_data = $this->group_data($all_data, $group_by, $aggregate);
            } elseif ($deduplicate) {
                $all_data = $this->deduplicate_data($all_data);
            }
            
            // Write the processed data to the target file
            $target_handle = fopen($mapped_file_path, 'w');
            if (!$target_handle) {
                return false;
            }
            
            // Write the headers
            fputcsv($target_handle, $target_headers);
            
            // Write the data
            foreach ($all_data as $row) {
                $row_data = array();
                foreach ($target_headers as $header) {
                    $row_data[] = isset($row[$header]) ? $row[$header] : '';
                }
                fputcsv($target_handle, $row_data);
            }
            
            // Close the target file
            fclose($target_handle);
        } else {
            // Standard processing without grouping or deduplication
            // Open the target file for writing
            $target_handle = fopen($mapped_file_path, 'w');
            if (!$target_handle) {
                fclose($source_handle);
                return false;
            }
            
            // Write the headers to the target file
            fputcsv($target_handle, $target_headers);
            
            // Process the data row by row
            $processed_rows = 0;
            $batch_size = 1000; // Process 1000 rows at a time
            $batch_count = 0;
            
            while (($data = fgetcsv($source_handle)) !== false) {
                $row = array();
                
                // Map the columns according to the mapping
                foreach ($this->column_mapping as $target_column => $source_column) {
                    $index = array_search($source_column, $source_headers);
                    if ($index !== false) {
                        $row[$target_column] = isset($data[$index]) ? $data[$index] : '';
                    } else {
                        $row[$target_column] = '';
                    }
                }
                
                // Write the row to the target file
                fputcsv($target_handle, array_values($row));
                
                $processed_rows++;
                
                // Free up memory periodically
                if ($processed_rows % $batch_size === 0) {
                    $batch_count++;
                    // You could add progress tracking here if needed
                    
                    // Force garbage collection to free memory
                    if (function_exists('gc_collect_cycles')) {
                        gc_collect_cycles();
                    }
                }
            }
            
            // Close the file handles
            fclose($source_handle);
            fclose($target_handle);
        }
        
        return $mapped_file_path;
    }

    /**
     * Group data by specified columns.
     *
     * @since    1.0.0
     * @access   private
     * @param    array    $data       The data to group.
     * @param    array    $group_by   The columns to group by.
     * @param    array    $aggregate  The columns to aggregate with their functions.
     * @return   array    The grouped data.
     */
    private function group_data($data, $group_by, $aggregate = array()) {
        if (empty($data) || empty($group_by)) {
            return $data;
        }
        
        $grouped_data = array();
        
        foreach ($data as $row) {
            // Create a key based on the group_by columns
            $key_parts = array();
            foreach ($group_by as $column) {
                $key_parts[] = isset($row[$column]) ? $row[$column] : '';
            }
            $key = implode('|', $key_parts);
            
            // If this is the first row with this key, initialize it
            if (!isset($grouped_data[$key])) {
                $grouped_data[$key] = $row;
                
                // Initialize aggregate values
                foreach ($aggregate as $column => $function) {
                    $grouped_data[$key]['_count_' . $column] = 1;
                    if ($function === 'sum' || $function === 'avg') {
                        $grouped_data[$key]['_sum_' . $column] = floatval($row[$column]);
                    }
                }
            } else {
                // Update aggregate values
                foreach ($aggregate as $column => $function) {
                    $grouped_data[$key]['_count_' . $column]++;
                    
                    if ($function === 'sum' || $function === 'avg') {
                        $grouped_data[$key]['_sum_' . $column] += floatval($row[$column]);
                    }
                    
                    if ($function === 'sum') {
                        $grouped_data[$key][$column] = $grouped_data[$key]['_sum_' . $column];
                    } elseif ($function === 'avg') {
                        $grouped_data[$key][$column] = $grouped_data[$key]['_sum_' . $column] / $grouped_data[$key]['_count_' . $column];
                    } elseif ($function === 'count') {
                        $grouped_data[$key][$column] = $grouped_data[$key]['_count_' . $column];
                    } elseif ($function === 'min') {
                        $grouped_data[$key][$column] = min($grouped_data[$key][$column], $row[$column]);
                    } elseif ($function === 'max') {
                        $grouped_data[$key][$column] = max($grouped_data[$key][$column], $row[$column]);
                    } elseif ($function === 'concat') {
                        if ($grouped_data[$key][$column] !== $row[$column]) {
                            $grouped_data[$key][$column] .= ', ' . $row[$column];
                        }
                    }
                }
            }
        }
        
        // Clean up temporary aggregate values
        foreach ($grouped_data as $key => $row) {
            foreach ($aggregate as $column => $function) {
                unset($grouped_data[$key]['_count_' . $column]);
                unset($grouped_data[$key]['_sum_' . $column]);
            }
        }
        
        return array_values($grouped_data);
    }

    /**
     * Remove duplicate rows from data.
     *
     * @since    1.0.0
     * @access   private
     * @param    array    $data    The data to deduplicate.
     * @return   array    The deduplicated data.
     */
    private function deduplicate_data($data) {
        if (empty($data)) {
            return $data;
        }
        
        $unique_data = array();
        $seen_keys = array();
        
        foreach ($data as $row) {
            // Create a key based on all values
            $key = md5(serialize($row));
            
            if (!isset($seen_keys[$key])) {
                $unique_data[] = $row;
                $seen_keys[$key] = true;
            }
        }
        
        return $unique_data;
    }

    /**
     * Count the total number of rows in the CSV file.
     *
     * @since    1.0.0
     * @return   int    The total number of rows.
     */
    public function count_rows() {
        if (!file_exists($this->csv_file)) {
            return 0;
        }

        $handle = fopen($this->csv_file, 'r');
        $count = 0;
        
        // Skip header row
        fgetcsv($handle);
        
        while (fgetcsv($handle) !== false) {
            $count++;
        }
        
        fclose($handle);
        
        return $count;
    }

    /**
     * Import the mapped CSV file into a temporary database table.
     *
     * @since    1.0.0
     * @param    string    $table_name    The name of the temporary table.
     * @param    array     $columns       The columns to create in the table.
     * @param    int       $batch_size    The number of rows to import in each batch.
     * @param    bool      $is_temp       Whether to create a temporary table (false for development).
     * @return   array|bool               Import statistics or false on failure.
     */
    public function import_to_temp_table($table_name, $columns = array(), $batch_size = 500, $is_temp = false) {
        global $wpdb;
        
        // Check if the mapped file exists
        $mapped_file = get_transient('npc_mapped_file');
        if (empty($mapped_file) || !file_exists($mapped_file)) {
            return false;
        }
        
        // If no columns are specified, use the column mapping keys
        if (empty($columns)) {
            $columns = array_keys($this->column_mapping);
        }
        
        // Create a table name if not provided
        if (empty($table_name)) {
            $table_name = $wpdb->prefix . 'csv_import_' . time();
        } else {
            // Ensure the table name has the WordPress prefix
            if (strpos($table_name, $wpdb->prefix) !== 0) {
                $table_name = $wpdb->prefix . $table_name;
            }
        }
        
        // Create the table
        $result = $this->create_temp_table($table_name, $columns);
        if (!$result) {
            return false;
        }
        
        // Import the data in batches
        $stats = $this->batch_import_data($mapped_file, $table_name, $columns, $batch_size);
        if (!$stats) {
            // Drop the table if import failed
            $this->drop_temp_table($table_name);
            return false;
        }
        
        // Return the import statistics and table name
        return array(
            'table_name' => $table_name,
            'rows_imported' => $stats['imported'],
            'rows_skipped' => $stats['skipped'],
            'execution_time' => $stats['execution_time'],
            'is_permanent' => !$is_temp
        );
    }

    /**
     * Create a temporary database table.
     *
     * @since    1.0.0
     * @access   private
     * @param    string    $table_name    The name of the temporary table.
     * @param    array     $columns       The columns to create in the table.
     * @return   bool                     Whether the table was created successfully.
     */
    private function create_temp_table($table_name, $columns) {
        global $wpdb;
        
        // Drop the table if it exists to avoid conflicts
        $this->drop_temp_table($table_name);
        
        // Start with the basic SQL - removed TEMPORARY keyword for development
        $sql = "CREATE TABLE `$table_name` (
            `id` bigint(20) NOT NULL AUTO_INCREMENT,
            `is_flagged` TINYINT(1) NOT NULL DEFAULT 0,";
        
        // Add columns
        foreach ($columns as $column) {
            // Default to TEXT type for all columns for simplicity
            // In a real-world scenario, you might want to determine the appropriate type based on data
            $sql .= "`" . esc_sql($column) . "` TEXT,";
        }
        
        // Add primary key and close the statement
        $sql .= "PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        
        // Execute the query
        $result = $wpdb->query($sql);
        
        // Check for errors
        if ($result === false) {
            error_log('Error creating table: ' . $wpdb->last_error);
            return false;
        }
        
        return true;
    }

    /**
     * Import data from CSV file to the temporary table in batches.
     *
     * @since    1.0.0
     * @access   private
     * @param    string    $file_path     The path to the CSV file.
     * @param    string    $table_name    The name of the temporary table.
     * @param    array     $columns       The columns to import.
     * @param    int       $batch_size    The number of rows to import in each batch.
     * @return   array|bool               Import statistics or false on failure.
     */
    private function batch_import_data($file_path, $table_name, $columns, $batch_size) {
        global $wpdb;
        
        // Open the CSV file
        $handle = fopen($file_path, 'r');
        if (!$handle) {
            return false;
        }
        
        // Skip the header row
        $headers = fgetcsv($handle);
        
        // Prepare statistics
        $stats = array(
            'imported' => 0,
            'skipped' => 0,
            'execution_time' => 0
        );
        
        // Start timing
        $start_time = microtime(true);
        
        // Process the file in batches
        $batch = array();
        $batch_count = 0;
        
        while (($data = fgetcsv($handle)) !== false) {
            // Prepare row data
            $row = array();
            foreach ($columns as $index => $column) {
                $row[$column] = isset($data[$index]) ? $data[$index] : '';
            }
            
            $batch[] = $row;
            $batch_count++;
            
            // Process batch if we've reached the batch size
            if ($batch_count >= $batch_size) {
                $result = $this->insert_batch($table_name, $columns, $batch);
                if ($result) {
                    $stats['imported'] += $result;
                } else {
                    $stats['skipped'] += $batch_count;
                }
                
                // Reset batch
                $batch = array();
                $batch_count = 0;
                
                // Free up memory
                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
            }
        }
        
        // Process any remaining rows
        if (!empty($batch)) {
            $result = $this->insert_batch($table_name, $columns, $batch);
            if ($result) {
                $stats['imported'] += $result;
            } else {
                $stats['skipped'] += count($batch);
            }
        }
        
        // Close the file
        fclose($handle);
        
        // Calculate execution time
        $stats['execution_time'] = microtime(true) - $start_time;
        
        return $stats;
    }

    /**
     * Insert a batch of rows into the database.
     *
     * @since    1.0.0
     * @access   private
     * @param    string    $table_name    The name of the table.
     * @param    array     $columns       The columns to insert.
     * @param    array     $batch         The batch of rows to insert.
     * @return   int|bool                 Number of rows inserted or false on failure.
     */
    private function insert_batch($table_name, $columns, $batch) {
        global $wpdb;
        
        if (empty($batch)) {
            return 0;
        }
        
        // Start a transaction
        $wpdb->query('START TRANSACTION');
        
        try {
            $inserted = 0;
            
            // Prepare the base SQL
            $column_list = '`' . implode('`, `', array_map('esc_sql', $columns)) . '`';
            $placeholder_list = implode(', ', array_fill(0, count($columns), '%s'));
            $sql = "INSERT INTO `$table_name` ($column_list) VALUES ";
            
            // Process each row
            foreach ($batch as $row) {
                $values = array();
                foreach ($columns as $column) {
                    $values[] = isset($row[$column]) ? $row[$column] : '';
                }
                
                $row_sql = $wpdb->prepare("($placeholder_list)", $values);
                $full_sql = $sql . $row_sql;
                
                $result = $wpdb->query($full_sql);
                if ($result !== false) {
                    $inserted++;
                }
            }
            
            // Commit the transaction
            $wpdb->query('COMMIT');
            
            return $inserted;
        } catch (Exception $e) {
            // Rollback on error
            $wpdb->query('ROLLBACK');
            error_log('Error inserting batch: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Drop a temporary table.
     *
     * @since    1.0.0
     * @param    string    $table_name    The name of the temporary table.
     * @return   bool                     Whether the table was dropped successfully.
     */
    public function drop_temp_table($table_name) {
        global $wpdb;
        
        // For development, we can add a parameter to control whether to drop the table
        // For now, we'll keep the drop functionality but log it
        error_log("Dropping table: $table_name");
        
        $sql = "DROP TABLE IF EXISTS `$table_name`";
        $result = $wpdb->query($sql);
        
        if ($result === false) {
            error_log("Error dropping table: " . $wpdb->last_error);
            return false;
        }
        
        return true;
    }

    /**
     * Match SKUs with Hollander numbers and inventory.
     *
     * @since    1.0.0
     * @param    string    $table_name         The name of the import table.
     * @param    string    $sku_column         The column containing SKUs in the import table.
     * @param    string    $hollander_table    The table containing Hollander numbers.
     * @param    string    $hollander_column   The column containing Hollander numbers.
     * @param    string    $inventory_table    The table containing inventory SKUs.
     * @param    string    $inventory_column   The column containing inventory SKUs.
     * @return   array|bool                    Array with match results or false on failure.
     */
    public function match_skus($table_name, $sku_column, $hollander_table, $hollander_column, $inventory_table, $inventory_column) {
        global $wpdb;

        // Verify the import table exists
        if (!$wpdb->get_var("SHOW TABLES LIKE '$table_name'")) {
            error_log("NPC Plugin: Table '$table_name' does not exist");
            return false;
        }

        // Execute the custom matching query
        $query = "
            SELECT DISTINCT 
                n.*,  
                h.hollander_no, 
                i.inventory_no AS mapped_sku
            FROM {$table_name} n
            INNER JOIN `test_play`.hollander h  
                ON n.{$sku_column} COLLATE utf8mb4_unicode_ci = h.hollander_no COLLATE utf8mb4_unicode_ci
            INNER JOIN `test_play`.inventory_hollander_map ihm 
                ON h.hollander_id = ihm.hollander_id
            INNER JOIN `test_play`.inventory i 
                ON ihm.inventory_id = i.inventory_id
            WHERE n.{$sku_column} IS NOT NULL
        ";

        // Log the query for debugging
        error_log("NPC Plugin: Executing query: " . $query);

        $results = $wpdb->get_results($query, ARRAY_A);

        if ($results === false) {
            error_log("NPC Plugin: Query failed. MySQL Error: " . $wpdb->last_error);
            return false;
        }

        error_log("NPC Plugin: Query successful. Found " . count($results) . " matches");

        return array(
            'table_name' => $table_name,
            'results' => $results,
            'total' => count($results)
        );
    }

    /**
     * Perform a join query on the import table.
     *
     * @since    1.0.0
     * @param    string    $table_name     The name of the import table.
     * @param    string    $join_table     The table to join with.
     * @param    string    $join_column    The column to join on.
     * @param    string    $select         The columns to select.
     * @param    string    $where          The WHERE clause.
     * @param    string    $order_by       The ORDER BY clause.
     * @param    int       $limit          The LIMIT value.
     * @return   array                     The query results.
     */
    public function perform_join_query($table_name, $join_table = '', $join_column = '', $select = '*', $where = '', $order_by = '', $limit = 1000) {
        global $wpdb;

        $sql = "SELECT $select FROM $table_name";

        if ($join_table && $join_column) {
            $sql .= " LEFT JOIN $join_table ON $table_name.$join_column = $join_table.$join_column";
        }

        if ($where) {
            $sql .= " WHERE $where";
        }

        if ($order_by) {
            $sql .= " ORDER BY $order_by";
        }

        if ($limit) {
            $sql .= " LIMIT $limit";
        }

        return $wpdb->get_results($sql, ARRAY_A);
    }

    /**
     * Get a list of existing import tables.
     *
     * @since    1.0.0
     * @param    bool     $include_vendor_info    Whether to include vendor information.
     * @return   array    List of table names or table info.
     */
    public function get_import_tables($include_vendor_info = false) {
        global $wpdb;
        
        // Get all tables with the csv_import prefix
        $prefix = $wpdb->prefix . 'csv_import_';
        $tables = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT TABLE_NAME FROM information_schema.TABLES 
                WHERE TABLE_SCHEMA = %s 
                AND TABLE_NAME LIKE %s",
                DB_NAME,
                $prefix . '%'
            ),
            ARRAY_A
        );
        
        if (!$include_vendor_info) {
            // Just return table names
            $table_names = array();
            if (!empty($tables)) {
                foreach ($tables as $table) {
                    $table_names[] = $table['TABLE_NAME'];
                }
            }
            return $table_names;
        } else {
            // Return table info with vendor details
            $table_info = array();
            if (!empty($tables)) {
                foreach ($tables as $table) {
                    $table_name = $table['TABLE_NAME'];
                    
                    // Extract vendor name and timestamp from table name
                    $table_parts = explode('_', str_replace($prefix, '', $table_name));
                    $timestamp = end($table_parts);
                    
                    // Remove the timestamp from the array to get the vendor slug
                    array_pop($table_parts);
                    $vendor_slug = implode('_', $table_parts);
                    $vendor_name = ucwords(str_replace('_', ' ', $vendor_slug));
                    
                    $created_date = is_numeric($timestamp) ? date('Y-m-d H:i:s', $timestamp) : 'Unknown';
                    
                    $table_info[] = array(
                        'table_name' => $table_name,
                        'vendor_slug' => $vendor_slug,
                        'vendor_name' => $vendor_name,
                        'timestamp' => $timestamp,
                        'created_date' => $created_date
                    );
                }
            }
            return $table_info;
        }
    }

    /**
     * Import data to database.
     *
     * @since    1.0.0
     * @param    string    $vendor_name     The vendor name (used for table naming).
     * @param    bool      $is_permanent    Whether to create a permanent table.
     * @return   array|bool                 Array with import results or false on failure.
     */
    public function import_to_database($vendor_name, $is_permanent = false) {
        global $wpdb;

        if (!file_exists($this->csv_file)) {
            return false;
        }

        // Generate table name
        $timestamp = time();
        $table_name = $wpdb->prefix . 'csv_import_' . sanitize_title($vendor_name) . '_' . $timestamp;

        // Read CSV headers
        $handle = fopen($this->csv_file, 'r');
        $headers = fgetcsv($handle);
        if (!$headers) {
            fclose($handle);
            return false;
        }

        // Create table
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            is_flagged TINYINT(1) NOT NULL DEFAULT 0,";

        foreach ($headers as $header) {
            $column_name = sanitize_key($header);
            $sql .= "\n$column_name varchar(255) DEFAULT NULL,";
        }

        $sql .= "\nPRIMARY KEY (id)
        ) " . $wpdb->get_charset_collate() . ";";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        // Check if table was created
        if (!$wpdb->get_var("SHOW TABLES LIKE '$table_name'")) {
            fclose($handle);
            return false;
        }

        // Import data
        $rows_imported = 0;
        while (($data = fgetcsv($handle)) !== false) {
            $row_data = array();
            foreach ($headers as $index => $header) {
                $column_name = sanitize_key($header);
                $row_data[$column_name] = isset($data[$index]) ? $data[$index] : '';
            }

            $wpdb->insert($table_name, $row_data);
            $rows_imported++;
        }

        fclose($handle);

        // If not permanent, schedule deletion
        if (!$is_permanent) {
            wp_schedule_single_event(time() + DAY_IN_SECONDS, 'npc_delete_import_table', array($table_name));
        }

        return array(
            'table_name' => $table_name,
            'rows_imported' => $rows_imported
        );
    }

    /**
     * Delete an import table.
     *
     * @since    1.0.0
     * @param    string    $table_name    The name of the table to delete.
     * @return   bool                     True on success, false on failure.
     */
    public function delete_import_table($table_name) {
        global $wpdb;

        // Verify table name starts with our prefix
        if (strpos($table_name, $wpdb->prefix . 'csv_import_') !== 0) {
            return false;
        }

        // Drop the table
        $sql = "DROP TABLE IF EXISTS $table_name";
        return $wpdb->query($sql) !== false;
    }

    /**
     * Get table information.
     *
     * @since    1.0.0
     * @param    string    $table_name    The name of the table.
     * @return   array                    Table information including columns.
     */
    public function get_table_info($table_name) {
        global $wpdb;

        // Get table columns
        $columns = $wpdb->get_col("DESCRIBE $table_name");

        return array(
            'columns' => $columns
        );
    }
}
