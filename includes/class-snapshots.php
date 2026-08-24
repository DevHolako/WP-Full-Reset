<?php
/**
 * Snapshots & Database Backup Engine
 */

if (!defined('WPINC')) {
    die;
}

class WP_Full_Reset_Snapshots {

    /**
     * Get snapshots directory path and ensure it exists and is protected
     */
    public static function get_snapshots_dir() {
        $upload_dir = wp_upload_dir();
        $snapshots_dir = trailingslashit($upload_dir['basedir']) . 'wp-full-reset-snapshots/';

        if (!file_exists($snapshots_dir)) {
            wp_mkdir_p($snapshots_dir);
        }

        // Security files
        $htaccess = $snapshots_dir . '.htaccess';
        if (!file_exists($htaccess)) {
            @file_put_contents($htaccess, "Order deny,allow\nDeny from all\n");
        }

        $index = $snapshots_dir . 'index.php';
        if (!file_exists($index)) {
            @file_put_contents($index, "<?php // Silence is golden\n");
        }

        return $snapshots_dir;
    }

    /**
     * List all available snapshots
     */
    public static function get_snapshots() {
        $dir = self::get_snapshots_dir();
        $files = glob($dir . '*.sql');
        $snapshots = array();

        if (!$files) {
            return $snapshots;
        }

        foreach ($files as $file) {
            $filename = basename($file);
            $size = filesize($file);
            $modified = filemtime($file);

            // Read metadata header from first 1024 bytes
            $fp = @fopen($file, 'r');
            $header = '';
            if ($fp) {
                $header = fread($fp, 1024);
                fclose($fp);
            }

            $description = 'Snapshot';
            if (preg_match('/-- Description: (.*?)\n/i', $header, $matches)) {
                $description = trim($matches[1]);
            }

            $wp_version = '';
            if (preg_match('/-- WP Version: (.*?)\n/i', $header, $matches)) {
                $wp_version = trim($matches[1]);
            }

            $snapshots[] = array(
                'filename'    => $filename,
                'path'        => $file,
                'size'        => size_format($size, 2),
                'size_bytes'  => $size,
                'created'     => $modified,
                'date'        => date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $modified),
                'description' => esc_html($description),
                'wp_version'  => esc_html($wp_version),
            );
        }

        // Sort descending by creation time
        usort($snapshots, function($a, $b) {
            return $b['created'] - $a['created'];
        });

        return $snapshots;
    }

    /**
     * Create a new SQL snapshot of all database tables
     */
    public static function create_snapshot($description = '') {
        global $wpdb;

        if (empty($description)) {
            $description = 'Manual snapshot taken on ' . current_time('mysql');
        }

        $dir = self::get_snapshots_dir();
        $filename = 'snapshot_' . date('Y-m-d_H-i-s') . '_' . wp_generate_password(8, false) . '.sql';
        $filepath = $dir . $filename;

        $fp = @fopen($filepath, 'w');
        if (!$fp) {
            return new WP_Error('snapshot_write_failed', __('Unable to create snapshot file. Check folder permissions.', 'wp-full-reset'));
        }

        // Write SQL Header
        $header  = "-- WP Full Reset Snapshot Dump\n";
        $header .= "-- Date: " . current_time('mysql') . "\n";
        $header .= "-- Site URL: " . get_site_url() . "\n";
        $header .= "-- WP Version: " . get_bloginfo('version') . "\n";
        $header .= "-- Description: " . str_replace(array("\r", "\n"), ' ', $description) . "\n\n";
        $header .= "SET FOREIGN_KEY_CHECKS=0;\n";
        $header .= "SET SQL_MODE=\"NO_AUTO_VALUE_ON_ZERO\";\n\n";

        fwrite($fp, $header);

        // Fetch all tables
        $tables = $wpdb->get_col("SHOW TABLES");

        if (empty($tables)) {
            fclose($fp);
            @unlink($filepath);
            return new WP_Error('no_tables', __('No tables found to back up.', 'wp-full-reset'));
        }

        foreach ($tables as $table) {
            // Drop Table
            fwrite($fp, "DROP TABLE IF EXISTS `{$table}`;\n");

            // Create Table Structure
            $create_table = $wpdb->get_row("SHOW CREATE TABLE `{$table}`", ARRAY_N);
            if (!empty($create_table[1])) {
                fwrite($fp, $create_table[1] . ";\n\n");
            }

            // Dump Table Data in small batches of 25 rows to stay well under max_allowed_packet
            $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
            if ($count > 0) {
                $batch_size = 25;
                $offset = 0;

                while ($offset < $count) {
                    $rows = $wpdb->get_results("SELECT * FROM `{$table}` LIMIT {$offset}, {$batch_size}", ARRAY_A);
                    if ($rows) {
                        $fields = array_keys($rows[0]);
                        $field_names = '`' . implode('`, `', $fields) . '`';

                        $values = array();
                        foreach ($rows as $row) {
                            $row_values = array();
                            foreach ($row as $val) {
                                if (is_null($val)) {
                                    $row_values[] = 'NULL';
                                } else {
                                    $escaped = mysqli_real_escape_string($wpdb->dbh, $val);
                                    $row_values[] = "'" . $escaped . "'";
                                }
                            }
                            $values[] = '(' . implode(', ', $row_values) . ')';
                        }

                        $insert_query = "INSERT INTO `{$table}` ({$field_names}) VALUES \n" . implode(",\n", $values) . ";\n\n";
                        fwrite($fp, $insert_query);
                    }
                    $offset += $batch_size;
                }
            }
        }

        fwrite($fp, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($fp);

        return array(
            'filename'    => $filename,
            'size'        => size_format(filesize($filepath), 2),
            'description' => $description,
        );
    }

    /**
     * Restore a snapshot from file using streaming SQL parser
     */
    public static function restore_snapshot($filename) {
        global $wpdb;

        $filename = sanitize_file_name($filename);
        $filepath = self::get_snapshots_dir() . $filename;

        if (!file_exists($filepath) || !is_readable($filepath)) {
            return new WP_Error('snapshot_not_found', __('Snapshot file not found.', 'wp-full-reset'));
        }

        @set_time_limit(300);
        if (function_exists('wp_raise_memory_limit')) {
            wp_raise_memory_limit('admin');
        }

        $fp = @fopen($filepath, 'r');
        if (!$fp) {
            return new WP_Error('file_open_failed', __('Unable to read snapshot file.', 'wp-full-reset'));
        }

        $query = '';
        $in_string = false;
        $escape = false;

        while (!feof($fp)) {
            $line = fgets($fp);
            if ($line === false) continue;

            // Skip comment lines when starting a new query
            $trimmed = trim($line);
            if (empty($query) && (strpos($trimmed, '--') === 0 || strpos($trimmed, '/*') === 0)) {
                continue;
            }

            $len = strlen($line);
            for ($i = 0; $i < $len; $i++) {
                $char = $line[$i];

                if ($escape) {
                    $escape = false;
                } elseif ($char === '\\') {
                    $escape = true;
                } elseif ($char === "'" || $char === '"') {
                    if (!$in_string) {
                        $in_string = $char;
                    } elseif ($in_string === $char) {
                        $in_string = false;
                    }
                } elseif ($char === ';' && !$in_string) {
                    $query .= substr($line, 0, $i);
                    $trimmed_query = trim($query);
                    if (!empty($trimmed_query)) {
                        $wpdb->query($trimmed_query);
                    }
                    $query = '';
                    $line = substr($line, $i + 1);
                    $len = strlen($line);
                    $i = -1;
                    continue;
                }
            }
            $query .= $line;
        }
        fclose($fp);

        // Refresh user auth cookie if current user exists in restored DB
        $current_user = wp_get_current_user();
        if ($current_user && $current_user->ID) {
            wp_clear_auth_cookie();
            wp_set_auth_cookie($current_user->ID);
        }

        return true;
    }

    /**
     * Delete a snapshot
     */
    public static function delete_snapshot($filename) {
        $filename = sanitize_file_name($filename);
        $filepath = self::get_snapshots_dir() . $filename;

        if (file_exists($filepath) && is_file($filepath)) {
            @unlink($filepath);
            return true;
        }

        return new WP_Error('file_not_found', __('Snapshot file not found.', 'wp-full-reset'));
    }
}
