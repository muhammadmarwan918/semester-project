<?php
defined('ROOT') or exit;
// FR-09: admin sets an item's status to Open, Claimed or Returned
require_post_csrf('/admin/items');

$id     = (int) ($_POST['id'] ?? 0);
$status = post_str('status');
$admin  = (int) $_SESSION['user_id'];

if ($id <= 0 || !in_array($status, ['Open', 'Claimed', 'Returned'], true)) {
    flash('error', 'Invalid request.');
    redirect('/admin/items');
}

$conn   = db();
$notice = null;
$msg    = 'Something went wrong. No changes were saved.';
$type   = 'error';

mysqli_begin_transaction($conn);
try {
    $item = db_row('SELECT id, item_code, item_name, status, user_id FROM items WHERE id = ? AND deleted_at IS NULL FOR UPDATE', 'i', [$id]);

    if (!$item) {
        $msg = 'Item not found.';
        mysqli_rollback($conn);
    } elseif ($item['status'] === $status) {
        $msg = 'The item already has status ' . $status . '.';
        mysqli_rollback($conn);
    } elseif ($status === 'Open' && db_row(
        "SELECT id FROM claims WHERE item_id = ? AND status = 'Approved' LIMIT 1",
        'i', [$id]
    )) {
        // Do not reopen an item while an approved ownership claim is still recorded.
        // This keeps the claim decision and item availability consistent.
        $msg = 'This item has an approved claim and cannot be reopened. Contact the Security Office to resolve the approved claim first.';
        mysqli_rollback($conn);
    } else {
        $old = $item['status'];
        db_exec('UPDATE items SET status = ? WHERE id = ?', 'si', [$status, $id]);
        log_item_history($id, $admin, 'status', $old, $status, 'Changed by admin');
        mysqli_commit($conn);

        $type = 'success';
        $msg  = "Status changed from $old to $status.";
        if ($item['user_id'] !== null) {
            $notice = [
                (int) $item['user_id'],
                'The status of your item "' . $item['item_name'] . '" (' . item_label($item) . ") changed from $old to $status.",
                '/my-items',
                'Your item status changed',
            ];
            $msg .= ' The reporter has been notified.';
        }
    }
} catch (Throwable $ex) {
    mysqli_rollback($conn);
    error_log('[UniTrace] item_status: ' . $ex);
}

if ($notice !== null) {
    notify_user(...$notice);     // FR-09a
}

flash($type, $msg);
redirect('/admin/items');
