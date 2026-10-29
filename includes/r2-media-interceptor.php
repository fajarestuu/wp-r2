<?php
/**
 * R2 Media Interceptor
 *
 * Hooks into the WordPress featured image pipeline to:
 * 1. Detect when a featured image (_thumbnail_id) is assigned to a post.
 * 2. Upload that attachment (original + all WP-generated sizes) to Cloudflare R2.
 * 3. Delete local files to free disk space.
 * 4. Filter ALL attachment URL references (src, srcset) to serve from CDN.
 *
 * URL strategy:
 *   Files are stored in R2 using the SAME relative path as local uploads.
 *   e.g. local:  /wp-content/uploads/2026/06/photo-300x200.jpg
 *        R2 key: 2026/06/photo-300x200.jpg
 *        CDN:    https://cdn.yourdomain.com/2026/06/photo-300x200.jpg
 *
 *   This means ALL size variants are automatically resolved by a single
 *   str_replace( local_uploads_url, cdn_url ) — no per-size URL mapping needed.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ---------------------------------------------------------------------------
// 1. Detect featured image assignment
// ---------------------------------------------------------------------------

/**
 * Fires when _thumbnail_id post meta is added or updated.
 * This is the trigger for "Set Featured Image" in both Gutenberg and Classic editor.
 */
add_action( 'added_post_meta',   'wp_r2_on_thumbnail_meta_change', 10, 4 );
add_action( 'updated_post_meta', 'wp_r2_on_thumbnail_meta_change', 10, 4 );

function wp_r2_on_thumbnail_meta_change( $meta_id, $post_id, $meta_key, $meta_value ) {
    if ( $meta_key !== '_thumbnail_id' ) {
        return;
    }
    $attachment_id = absint( $meta_value );
    if ( $attachment_id ) {
        wp_r2_offload_attachment( $attachment_id );
    }
}

// ---------------------------------------------------------------------------
// 2. Core offload function
// ---------------------------------------------------------------------------

/**
 * Upload an attachment (original + all WP-generated sizes) to R2, then
 * delete every local file to free disk space.
 *
 * After this runs, the attachment is marked with _r2_offloaded = 1 so it
 * is never processed twice.
 *
 * @param int $attachment_id  WordPress attachment post ID.
 */
function wp_r2_offload_attachment( $attachment_id ) {
    // Skip if already offloaded
    if ( get_post_meta( $attachment_id, '_r2_offloaded', true ) ) {
        return;
    }

    $cdn_url = rtrim( get_option( 'wp_r2_cdn_url' ), '/' );
    if ( ! $cdn_url ) {
        error_log( '[R2 INTERCEPTOR] CDN URL not configured — skipping offload for attachment ' . $attachment_id );
        return;
    }

    $file_path = get_attached_file( $attachment_id );
    if ( ! $file_path || ! file_exists( $file_path ) ) {
        error_log( '[R2 INTERCEPTOR] Local file not found for attachment ' . $attachment_id . ': ' . $file_path );
        return;
    }

    $upload_dir   = wp_upload_dir();
    $base_dir     = untrailingslashit( $upload_dir['basedir'] );
    $upload_dir_path = dirname( $file_path );

    /**
     * Build the R2 key from the file's path relative to /wp-content/uploads/.
     * Example: /var/www/html/wp-content/uploads/2026/06/photo.jpg
     *       -> 2026/06/photo.jpg
     */
    $r2_key_for = function( $abs_path ) use ( $base_dir ) {
        $rel = ltrim( str_replace( $base_dir, '', $abs_path ), '/\\' );
        return str_replace( '\\', '/', $rel );
    };

    // --- Upload original file ---
    $r2_key = $r2_key_for( $file_path );
    $result = wp_r2_upload_file( $file_path, $r2_key );

    if ( empty( $result['success'] ) ) {
        error_log( '[R2 INTERCEPTOR] Upload FAILED for ' . $r2_key . ': ' . ( $result['message'] ?? 'unknown' ) );
        return; // Abort — do not delete local files if upload failed
    }
    error_log( '[R2 INTERCEPTOR] Uploaded: ' . $r2_key );

    // --- Upload every WP-generated size variant ---
    $metadata = wp_get_attachment_metadata( $attachment_id );

    if ( ! empty( $metadata['sizes'] ) ) {
        foreach ( $metadata['sizes'] as $size_name => $size_data ) {
            $size_abs = $upload_dir_path . DIRECTORY_SEPARATOR . $size_data['file'];
            if ( ! file_exists( $size_abs ) ) {
                continue;
            }
            $size_key    = $r2_key_for( $size_abs );
            $size_result = wp_r2_upload_file( $size_abs, $size_key );

            if ( ! empty( $size_result['success'] ) ) {
                error_log( '[R2 INTERCEPTOR] Uploaded size "' . $size_name . '": ' . $size_key );
                @unlink( $size_abs ); // Delete local size file immediately after successful upload
            } else {
                error_log( '[R2 INTERCEPTOR] FAILED size "' . $size_name . '": ' . ( $size_result['message'] ?? 'unknown' ) );
            }
        }
    }

    // Delete original local file
    @unlink( $file_path );

    // Store CDN base so URL filters can do a simple base-URL swap
    update_post_meta( $attachment_id, '_r2_offloaded', '1' );
    update_post_meta( $attachment_id, '_r2_cdn_base',  $cdn_url );

    error_log( '[R2 INTERCEPTOR] Offload complete for attachment ' . $attachment_id );
}

// ---------------------------------------------------------------------------
// 3. URL filtering — single strategy: replace uploads base URL with CDN URL
//
// Because R2 files are stored with the same relative path as local uploads,
// we only need one simple substitution to fix ALL URLs for ALL sizes.
//
//   Local:  https://mysite.com/wp-content/uploads/2026/06/photo-300x200.jpg
//   R2 CDN: https://cdn.mydomain.com/2026/06/photo-300x200.jpg
// ---------------------------------------------------------------------------

/**
 * Helper: swap the WP uploads base URL for the CDN URL.
 */
function wp_r2_swap_url( $url, $cdn_base ) {
    $upload_dir  = wp_upload_dir();
    $uploads_url = untrailingslashit( $upload_dir['baseurl'] );
    return str_replace( $uploads_url, $cdn_base, $url );
}

/**
 * Filter: wp_get_attachment_url
 * Called for direct attachment URL lookups (e.g. the_post_thumbnail_url()).
 */
add_filter( 'wp_get_attachment_url', 'wp_r2_filter_attachment_url', 10, 2 );

function wp_r2_filter_attachment_url( $url, $attachment_id ) {
    $cdn_base = get_post_meta( $attachment_id, '_r2_cdn_base', true );
    return $cdn_base ? wp_r2_swap_url( $url, $cdn_base ) : $url;
}

/**
 * Filter: wp_get_attachment_image_src
 * Called when WordPress picks a specific size — e.g. the_post_thumbnail('large').
 * $image = [ url, width, height, is_intermediate ]
 */
add_filter( 'wp_get_attachment_image_src', 'wp_r2_filter_attachment_image_src', 10, 4 );

function wp_r2_filter_attachment_image_src( $image, $attachment_id, $size, $icon ) {
    if ( ! $image ) {
        return $image;
    }
    $cdn_base = get_post_meta( $attachment_id, '_r2_cdn_base', true );
    if ( $cdn_base ) {
        $image[0] = wp_r2_swap_url( $image[0], $cdn_base );
    }
    return $image;
}

/**
 * Filter: wp_calculate_image_srcset
 * Called when WordPress builds the srcset= attribute for responsive images.
 * Each source in $sources has a 'url' key.
 */
add_filter( 'wp_calculate_image_srcset', 'wp_r2_filter_image_srcset', 10, 5 );

function wp_r2_filter_image_srcset( $sources, $size_array, $image_src, $image_meta, $attachment_id ) {
    $cdn_base = get_post_meta( $attachment_id, '_r2_cdn_base', true );
    if ( ! $cdn_base ) {
        return $sources;
    }
    foreach ( $sources as &$source ) {
        $source['url'] = wp_r2_swap_url( $source['url'], $cdn_base );
    }
    unset( $source );
    return $sources;
}

// ---------------------------------------------------------------------------
// 4. Admin column — show R2 status in Media Library list view
// ---------------------------------------------------------------------------

add_filter( 'manage_media_columns', 'wp_r2_add_media_column' );

function wp_r2_add_media_column( $columns ) {
    $columns['r2_status'] = 'R2';
    return $columns;
}

add_action( 'manage_media_custom_column', 'wp_r2_render_media_column', 10, 2 );

function wp_r2_render_media_column( $column_name, $attachment_id ) {
    if ( $column_name !== 'r2_status' ) {
        return;
    }
    if ( get_post_meta( $attachment_id, '_r2_offloaded', true ) ) {
        echo '<span style="color:#2271b1;" title="Stored on Cloudflare R2">&#9729; R2</span>';
    } else {
        echo '<span style="color:#aaa;" title="Stored locally">&#128194; Local</span>';
    }
}
