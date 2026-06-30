<?php
/**
 * R2 Media Interceptor
 *
 * Hooks into the WordPress featured image pipeline to:
 * 1. Detect when a featured image (_thumbnail_id) is assigned to a post.
 * 2. Upload that attachment (all sizes) to Cloudflare R2.
 * 3. Delete local files to free disk space.
 * 4. Filter attachment URLs to serve from the configured CDN URL.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ---------------------------------------------------------------------------
// 1. Detect featured image assignment
// ---------------------------------------------------------------------------

/**
 * Fires when _thumbnail_id post meta is added or updated.
 * This is the hook that triggers when a user sets a Featured Image.
 */
add_action( 'added_post_meta',   'wp_r2_on_thumbnail_meta_change', 10, 4 );
add_action( 'updated_post_meta', 'wp_r2_on_thumbnail_meta_change', 10, 4 );

function wp_r2_on_thumbnail_meta_change( $meta_id, $post_id, $meta_key, $meta_value ) {
    if ( $meta_key !== '_thumbnail_id' ) {
        return;
    }

    $attachment_id = absint( $meta_value );
    if ( ! $attachment_id ) {
        return;
    }

    wp_r2_offload_attachment( $attachment_id );
}

// ---------------------------------------------------------------------------
// 2. Core offload function
// ---------------------------------------------------------------------------

/**
 * Upload an attachment and all its generated sizes to R2, then delete local files.
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
        error_log( '[R2 INTERCEPTOR] File not found for attachment ' . $attachment_id . ': ' . $file_path );
        return;
    }

    // Build the R2 key using a path relative to wp-content/uploads/
    // so that year/month sub-folders are preserved (avoids filename collisions).
    $upload_dir  = wp_upload_dir();
    $base_dir    = untrailingslashit( $upload_dir['basedir'] );
    $relative    = ltrim( str_replace( $base_dir, '', $file_path ), '/\\' );
    $r2_key      = str_replace( '\\', '/', $relative ); // normalize to forward slashes

    // Upload the main (original) file
    $result = wp_r2_upload_file( $file_path, $r2_key );

    if ( empty( $result['success'] ) ) {
        error_log( '[R2 INTERCEPTOR] Upload failed for ' . $file_path . ': ' . ( $result['message'] ?? 'unknown error' ) );
        return;
    }

    $main_cdn_url = $cdn_url . '/' . $r2_key;
    update_post_meta( $attachment_id, '_r2_url',     $main_cdn_url );
    update_post_meta( $attachment_id, '_r2_key',     $r2_key );
    update_post_meta( $attachment_id, '_r2_cdn_base', $cdn_url );

    error_log( '[R2 INTERCEPTOR] Uploaded main file: ' . $main_cdn_url );

    // Upload all generated image sizes (thumbnail, medium, large, etc.)
    $metadata   = wp_get_attachment_metadata( $attachment_id );
    $upload_dir_path = dirname( $file_path );
    $rel_dir    = ltrim( str_replace( $base_dir, '', $upload_dir_path ), '/\\' );
    $rel_dir    = str_replace( '\\', '/', $rel_dir );

    $size_r2_urls = [];

    if ( ! empty( $metadata['sizes'] ) ) {
        foreach ( $metadata['sizes'] as $size_name => $size_data ) {
            $size_file = $upload_dir_path . DIRECTORY_SEPARATOR . $size_data['file'];
            if ( ! file_exists( $size_file ) ) {
                continue;
            }

            $size_key    = ( $rel_dir ? $rel_dir . '/' : '' ) . $size_data['file'];
            $size_result = wp_r2_upload_file( $size_file, $size_key );

            if ( ! empty( $size_result['success'] ) ) {
                $size_r2_urls[ $size_name ] = $cdn_url . '/' . $size_key;
                error_log( '[R2 INTERCEPTOR] Uploaded size "' . $size_name . '": ' . $size_r2_urls[ $size_name ] );

                // Delete local size file
                @unlink( $size_file );
            } else {
                error_log( '[R2 INTERCEPTOR] Failed to upload size "' . $size_name . '": ' . ( $size_result['message'] ?? 'unknown' ) );
            }
        }
    }

    // Store all size URLs as a single meta entry for easy lookup
    if ( ! empty( $size_r2_urls ) ) {
        update_post_meta( $attachment_id, '_r2_size_urls', $size_r2_urls );
    }

    // Delete the original local file last
    @unlink( $file_path );

    // Mark as fully offloaded
    update_post_meta( $attachment_id, '_r2_offloaded', '1' );

    error_log( '[R2 INTERCEPTOR] Offload complete for attachment ' . $attachment_id );
}

// ---------------------------------------------------------------------------
// 3. URL filtering — redirect attachment URLs to CDN
// ---------------------------------------------------------------------------

/**
 * Replace the local attachment URL with the R2 CDN URL.
 * Only applies to attachments that have been offloaded.
 */
add_filter( 'wp_get_attachment_url', 'wp_r2_filter_attachment_url', 10, 2 );

function wp_r2_filter_attachment_url( $url, $attachment_id ) {
    $r2_url = get_post_meta( $attachment_id, '_r2_url', true );
    return $r2_url ? $r2_url : $url;
}

/**
 * Replace URLs in srcset so all responsive image sizes also point to R2.
 */
add_filter( 'wp_calculate_image_srcset', 'wp_r2_filter_image_srcset', 10, 5 );

function wp_r2_filter_image_srcset( $sources, $size_array, $image_src, $image_meta, $attachment_id ) {
    if ( ! get_post_meta( $attachment_id, '_r2_offloaded', true ) ) {
        return $sources;
    }

    $cdn_base    = get_post_meta( $attachment_id, '_r2_cdn_base', true );
    $upload_dir  = wp_upload_dir();
    $uploads_url = untrailingslashit( $upload_dir['baseurl'] );

    if ( ! $cdn_base || ! $uploads_url ) {
        return $sources;
    }

    foreach ( $sources as &$source ) {
        $source['url'] = str_replace( $uploads_url, $cdn_base, $source['url'] );
    }
    unset( $source );

    return $sources;
}

/**
 * Replace the URL inside image tag attributes (src, srcset) generated by
 * wp_get_attachment_image_src() when a specific size is requested.
 */
add_filter( 'wp_get_attachment_image_src', 'wp_r2_filter_attachment_image_src', 10, 4 );

function wp_r2_filter_attachment_image_src( $image, $attachment_id, $size, $icon ) {
    if ( ! $image || ! get_post_meta( $attachment_id, '_r2_offloaded', true ) ) {
        return $image;
    }

    $size_urls = get_post_meta( $attachment_id, '_r2_size_urls', true );

    // If a named size is requested and we have its R2 URL, use it
    if ( is_string( $size ) && ! empty( $size_urls[ $size ] ) ) {
        $image[0] = $size_urls[ $size ];
        return $image;
    }

    // Otherwise fall back to the main CDN URL
    $r2_url = get_post_meta( $attachment_id, '_r2_url', true );
    if ( $r2_url ) {
        $image[0] = $r2_url;
    }

    return $image;
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
