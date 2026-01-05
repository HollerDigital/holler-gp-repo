<?php
namespace HD_Telemetry\Features;

if (!defined('ABSPATH')) exit;

class Comments {
    public function status(): array {
        // Basic site-wide defaults
        $default_open = get_option('default_comment_status', 'open') === 'open';
        $require_reg  = (bool) get_option('comment_registration', 0);
        $auto_close   = (bool) get_option('close_comments_for_old_posts', 0);
        $auto_close_days = (int) get_option('close_comments_days_old', 14);
        $threaded     = (bool) get_option('thread_comments', 0);
        $thread_depth = (int) get_option('thread_comments_depth', 5);

        return [
            'default_open'    => $default_open,
            'require_registration' => $require_reg,
            'auto_close'      => $auto_close,
            'auto_close_days' => $auto_close ? $auto_close_days : null,
            'threaded'        => $threaded,
            'thread_depth'    => $threaded ? $thread_depth : null,
        ];
    }
}
