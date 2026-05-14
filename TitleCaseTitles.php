<?php
/*
Plugin Name: Title Case Titles
Version:     1.0
Description: Forces all titles and headings to display in Title Case
Author:      Stuart Robertson
Requires at least: 5.8
Requires PHP: 7.4
License:     GPLv3
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: title-case-titles
*/

// Prevent direct access
if (!defined('ABSPATH')) {
    http_response_code(403);
    exit;
}

// Convert string to Title Case
function tct_title_case($title) {
    // Words to exclude from capitalization unless first/last
    $small_words = [
        'a','an','and','as','at','but','by','for','if','in','nor',
        'of','on','or','per','so','the','to','th','up','yet'
    ];
    
    // Decode HTML entities (e.g., &nbsp;)
    $title = html_entity_decode($title, ENT_QUOTES, 'UTF-8');

    // Split into tags and text
    $parts = preg_split('/(<[^>]+>)/u', $title, -1, PREG_SPLIT_DELIM_CAPTURE);

    $word_index = 0;

    foreach ($parts as &$part) {
        // Handle HTML tags separately
        if (preg_match('/^<[^>]+>$/', $part)) {
            $part = mb_strtolower($part, 'UTF-8');
            continue;
        }

        // Split into words
        $words = preg_split('/\s+/u', $part, -1, PREG_SPLIT_NO_EMPTY);

        foreach ($words as &$word) {

            // Split hyphenated words
            $hyphen_parts = explode('-', $word);

            foreach ($hyphen_parts as &$subword) {

                $word_pattern = '/^([^\p{L}\p{N}]*)([\p{L}\p{N}]+)([^\p{L}\p{N}]*)$/u';
                preg_match($word_pattern, $subword, $matches);

                if (!$matches) {
                    $subword = mb_convert_case(
                        mb_strtolower($subword, 'UTF-8'),
                        MB_CASE_TITLE,
                        'UTF-8'
                    );
                    continue;
                }

                $prefix = $matches[1];
                $clean  = $matches[2];
                $suffix = $matches[3];

                $clean_lower = mb_strtolower($clean, 'UTF-8');

                if (
                    $word_index === 0 || 
                    !in_array($clean_lower, $small_words, true)
                ) {
                    $clean = mb_convert_case($clean_lower, MB_CASE_TITLE, 'UTF-8');
                } else {
                    $clean = $clean_lower;
                }

                $subword = $prefix . $clean . $suffix;
            }

            // Rebuild hyphenated word
            $word = implode('-', $hyphen_parts);

            $word_index++;
        }

        $part = implode(' ', $words);
    }

    return preg_replace(
        '/(<\/[^>]+>)(?=\S)/u',
        '$1 ',
        implode('', $parts)
    );
}

// Check if plugin should apply to this post type
function tct_should_apply($post_id) {
    $apply_to = get_option('tct_apply_to', 'all');

    $post_type = get_post_type($post_id);

    if ($apply_to === 'pages') {
        return $post_type === 'page';
    }

    return in_array($post_type, ['post', 'page'], true);
}

// Apply Title Case to titles
function tct_filter_title($title, $post_id = 0) {
    if (!$post_id || !tct_should_apply($post_id)) {
        return $title;
    }

    return tct_title_case($title);
}

// Apply Title Case to headings (H1–H6)
function tct_filter_headings($content) {
    global $post;

    if (!$post || !tct_should_apply($post->ID)) {
        return $content;
    }

    $content = preg_replace_callback(
        '/<(h[1-6])(.*?)>(.*?)<\/\1>/i',
        function ($matches) {
            $tag = $matches[1];
            $attrs = $matches[2];
            $inner = $matches[3];

            // Convert only visible text
            $converted = tct_title_case($inner);

            return "<{$tag}{$attrs}>{$converted}</{$tag}>";
        },
        $content
    );

    $content = preg_replace_callback(
        '/(<a[^>]*class="[^"]*wp-block-button__link[^"]*"[^>]*>)(.*?)(<\/a>)/is',
        function ($matches) {

            $open  = $matches[1];
            $inner = $matches[2];
            $close = $matches[3];

            // Convert visible text only (handles spans etc. too)
            $inner = preg_replace_callback(
                '/>([^<]+)</u',
                function ($text_match) {
                    return '>' . tct_title_case($text_match[1]) . '<';
                },
                '>' . $inner . '<'
            );

            // remove the wrapping we added
            $inner = substr($inner, 1, -1);

            return $open . $inner . $close;
        },
        $content
    );


    $content = preg_replace_callback(
    '/(<li[^>]*class="[^"]*child-page[^"]*"[^>]*>.*?<a[^>]*>)(.*?)(<\/a>)/is',
        function ($matches) {

            $open  = $matches[1]; // <li ...><a ...>
            $inner = $matches[2]; // link text
            $close = $matches[3]; // </a>

            // Convert visible text only
            $inner = preg_replace_callback(
                '/>([^<]+)</u',
                function ($text_match) {
                    return '>' . tct_title_case($text_match[1]) . '<';
                },
                '>' . $inner . '<'
            );

            $inner = substr($inner, 1, -1); // remove wrapper

            return $open . $inner . $close;
        },
        $content
    );

    return $content;
}

// Register settings
function tct_register_settings() {
    register_setting('tct_settings_group', 'tct_apply_to');

    add_settings_section(
        'tct_main_section',
        'Title Case Settings',
        null,
        'tct-settings'
    );

    add_settings_field(
        'tct_apply_to_field',
        'Apply Title Case To',
        'tct_apply_to_field_render',
        'tct-settings',
        'tct_main_section'
    );
}
add_action('admin_init', 'tct_register_settings');

// Render radio field
function tct_apply_to_field_render() {
    $value = get_option('tct_apply_to', 'all');
    ?>
    <label>
        <input type="radio" name="tct_apply_to" value="all" <?php checked($value, 'all'); ?>>
        Posts and Pages
    </label><br>

    <label>
        <input type="radio" name="tct_apply_to" value="pages" <?php checked($value, 'pages'); ?>>
        Pages only
    </label>
    <?php
}

// Add settings page
function tct_add_settings_page() {
    add_options_page(
        'Title Case Titles',
        'Title Case Titles',
        'manage_options',
        'tct-settings',
        'tct_settings_page_render'
    );
}
add_action('admin_menu', 'tct_add_settings_page');

// Render settings page
function tct_settings_page_render() {
    ?>
    <div class="wrap">
        <h1>Title Case Titles Settings</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('tct_settings_group');
            do_settings_sections('tct-settings');
            submit_button();
            ?>
        </form>
    </div>
    <?php
}

// Apply to page titles
add_filter('the_title', 'tct_filter_title', 10, 2);

// Apply to page headings
add_filter('the_content', 'tct_filter_headings');



