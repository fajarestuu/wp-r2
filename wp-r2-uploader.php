<?php
/**
 * Plugin Name: WP R2 Uploader
 * Description: Upload dan kelola gambar di Cloudflare R2 langsung dari WordPress.
 * Version: 1.0
 * Author: ChatGPT & Fajar
 */

if (!defined('ABSPATH')) exit;

// load file helper
require_once plugin_dir_path(__FILE__) . 'includes/r2-client.php';
require_once plugin_dir_path(__FILE__) . 'includes/uploader.php';
require_once plugin_dir_path(__FILE__) . 'includes/r2-media-interceptor.php';

// Tambah menu di wp-admin
add_action('admin_menu', function () {
    add_menu_page(
        'R2 Uploader',
        'R2 Uploader',
        'manage_options',
        'wp-r2-uploader',
        'wp_r2_uploader_admin_page',
        'dashicons-cloud',
        25
    );
});

// Admin Page
function wp_r2_uploader_admin_page() {
    include plugin_dir_path(__FILE__) . 'admin/settings-page.php';
}

// Register option untuk simpan setting
add_action('admin_init', function () {
    register_setting('wp_r2_settings', 'wp_r2_account_id');
    register_setting('wp_r2_settings', 'wp_r2_access_key');
    register_setting('wp_r2_settings', 'wp_r2_secret_key');
    register_setting('wp_r2_settings', 'wp_r2_bucket');
    register_setting('wp_r2_settings', 'wp_r2_endpoint');
    register_setting('wp_r2_settings', 'wp_r2_cdn_url');
});
