<?php
defined('ROOT') or exit;
require_post_csrf('/notifications');

db_exec('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0', 'i', [(int) $_SESSION['user_id']]);
redirect('/notifications');
