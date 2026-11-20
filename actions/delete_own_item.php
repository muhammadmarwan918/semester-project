<?php
defined('ROOT') or exit;
// FR-03c: a student deletes their own report, only while it is still Open
require_post_csrf('/my-items');

$id = (int) ($_POST['id'] ?? 0);
[$ok, $msg] = $id > 0 ? soft_delete_item($id, (int) $_SESSION['user_id'], true) : [false, 'Report not found.'];

flash($ok ? 'success' : 'error', $ok ? 'Report deleted.' : $msg);
redirect('/my-items');
