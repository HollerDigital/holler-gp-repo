<?php
namespace HD_Telemetry\Features;

if (!defined('ABSPATH')) exit;

class Editor {
    public function status(): array {
        // Detect if Classic Editor plugin is active
        $classic_active = false;
        if (function_exists('is_plugin_active')) {
            $classic_active = is_plugin_active('classic-editor/classic-editor.php');
        }

        // Determine if block editor (Gutenberg) is enabled for common post types
        $block_for_posts = $this->blockEnabledFor('post');
        $block_for_pages = $this->blockEnabledFor('page');

        // Overall heuristic: enabled if enabled for either posts or pages and classic is not forcing classic
        $block_enabled = ($block_for_posts || $block_for_pages) && !$classic_active;

        return [
            'gutenberg_enabled'     => $block_enabled,
            'block_for_posts'       => $block_for_posts,
            'block_for_pages'       => $block_for_pages,
            'classic_editor_active' => $classic_active,
        ];
    }

    private function blockEnabledFor(string $post_type): bool {
        if (function_exists('use_block_editor_for_post_type')) {
            // WP core function to determine if block editor is used for a post type
            try {
                return (bool) use_block_editor_for_post_type($post_type);
            } catch (\Throwable $e) {
                return false;
            }
        }
        // Older WP fallback: assume true if Gutenberg plugin active
        if (function_exists('is_plugin_active')) {
            return is_plugin_active('gutenberg/gutenberg.php');
        }
        return false;
    }
}
