<?php
/*
Plugin Name: FWP+: Featured Images from WordPress API
Plugin URI: https://github.com/twwoodward/fwp-featured-images
Description: Fetches and sets featured images from WordPress REST API for syndicated posts via FeedWordPress.
Version: 1.0.0
Author: Tom Woodward
Author URI: https://bionicteaching.com
License: GPL
*/

add_action('post_syndicated_item', 'fwp_set_featured_image_from_api', 10, 1);

/**
 * Fetches featured image from source WordPress site via REST API
 * and sets it as the featured image for the syndicated post
 *
 * @param int $post_id The WordPress post ID of the newly syndicated post
 */
function fwp_set_featured_image_from_api($post_id) {
    // Skip if post already has a featured image
    if (has_post_thumbnail($post_id)) {
        return;
    }

    // Get the source URL from post meta
    $source_url = get_post_meta($post_id, 'syndication_source_uri', true);
    
    if (empty($source_url)) {
        return;
    }

    // Parse the source URL to get the base domain
    $parsed_url = parse_url($source_url);
    if (!$parsed_url || empty($parsed_url['host'])) {
        return;
    }

    $base_url = $parsed_url['scheme'] . '://' . $parsed_url['host'];
    
    // Extract post slug or ID from the source URL
    $path = trim($parsed_url['path'], '/');
    $path_parts = explode('/', $path);
    $post_slug = end($path_parts);

    // Try to fetch post data from WordPress REST API
    $api_url = $base_url . '/wp-json/wp/v2/posts?slug=' . urlencode($post_slug) . '&_embed';
    
    $response = wp_remote_get($api_url, [
        'timeout' => 10,
        'headers' => [
            'Accept' => 'application/json'
        ]
    ]);

    if (is_wp_error($response)) {
        error_log('FWP Featured Images: API request failed - ' . $response->get_error_message());
        return;
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (empty($data) || !is_array($data) || count($data) === 0) {
        return;
    }

    $post_data = $data[0];

    // Check for featured media in the embedded data
    if (!empty($post_data['_embedded']['wp:featuredmedia'][0]['source_url'])) {
        $image_url = $post_data['_embedded']['wp:featuredmedia'][0]['source_url'];
        fwp_sideload_featured_image($post_id, $image_url);
    }
    // Alternative: try featured_media ID directly
    elseif (!empty($post_data['featured_media']) && $post_data['featured_media'] > 0) {
        $media_id = $post_data['featured_media'];
        $media_api_url = $base_url . '/wp-json/wp/v2/media/' . $media_id;
        
        $media_response = wp_remote_get($media_api_url, ['timeout' => 10]);
        
        if (!is_wp_error($media_response)) {
            $media_body = wp_remote_retrieve_body($media_response);
            $media_data = json_decode($media_body, true);
            
            if (!empty($media_data['source_url'])) {
                fwp_sideload_featured_image($post_id, $media_data['source_url']);
            }
        }
    }
}

/**
 * Downloads image from URL and sets it as featured image
 *
 * @param int $post_id The post ID to attach the image to
 * @param string $image_url The URL of the image to download
 */
function fwp_sideload_featured_image($post_id, $image_url) {
    require_once(ABSPATH . 'wp-admin/includes/media.php');
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/image.php');

    // Download the image
    $tmp = download_url($image_url);

    if (is_wp_error($tmp)) {
        error_log('FWP Featured Images: Download failed - ' . $tmp->get_error_message());
        return;
    }

    // Get file extension from URL
    $file_array = [
        'name' => basename($image_url),
        'tmp_name' => $tmp
    ];

    // Sideload the image (uploads it to media library)
    $attachment_id = media_handle_sideload($file_array, $post_id);

    // Clean up temp file
    @unlink($tmp);

    if (is_wp_error($attachment_id)) {
        error_log('FWP Featured Images: Sideload failed - ' . $attachment_id->get_error_message());
        return;
    }

    // Set as featured image
    set_post_thumbnail($post_id, $attachment_id);
}