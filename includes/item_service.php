<?php
defined('ROOT') or exit;
/**
 * UniTrace — item operations shared by the student and admin actions
 */

/**
 * Soft-delete an item (FR-03c for the owner, admin clean-up for spam). The row is kept so the
 * history stays complete (FR-10); it just disappears from every list. Pending claims on it are
 * rejected and the claimants are told.
 *
 * $owner_only = true  -> only the reporter may do it, and only while the item is Open (FR-03c)
 * $owner_only = false -> admin: any item
 * Returns [ok, message].
 */
function soft_delete_item(int $item_id, int $actor_id, bool $owner_only): array
{
    $conn     = db();
    $notify   = [];
    $item     = null;
    $result   = [false, 'Item not found.'];

    mysqli_begin_transaction($conn);
    try {
        $item = db_row(
            'SELECT id, item_code, item_name, user_id, status FROM items WHERE id = ? AND deleted_at IS NULL FOR UPDATE',
            'i',
            [$item_id]
        );

        if (!$item) {
            mysqli_rollback($conn);
        } elseif ($owner_only && ($item['user_id'] === null || (int) $item['user_id'] !== $actor_id)) {
            mysqli_rollback($conn);
            $result = [false, 'You can only delete your own reports.'];
        } elseif ($owner_only && $item['status'] !== 'Open') {
            mysqli_rollback($conn);
            $result = [false, 'Only reports with status Open can be deleted.'];
        } else {
            $res = db_query("SELECT id, user_id FROM claims WHERE item_id = ? AND status = 'Pending'", 'i', [$item_id]);
            while ($c = mysqli_fetch_assoc($res)) {
                $notify[] = (int) $c['user_id'];
            }
            db_exec("UPDATE claims SET status = 'Rejected' WHERE item_id = ? AND status = 'Pending'", 'i', [$item_id]);
            db_exec('UPDATE items SET deleted_at = ? WHERE id = ?', 'si', [date('Y-m-d H:i:s'), $item_id]);
            log_item_history($item_id, $actor_id, 'deleted', $item['status'], null, $owner_only ? 'Deleted by reporter' : 'Removed by admin');
            mysqli_commit($conn);
            $result = [true, 'Item deleted.'];
        }
    } catch (Throwable $ex) {
        mysqli_rollback($conn);
        error_log('[UniTrace] soft_delete_item: ' . $ex);
        return [false, 'Something went wrong. No changes were saved.'];
    }

    foreach (array_unique($notify) as $uid) {
        notify_user(
            $uid,
            'Your claim on "' . $item['item_name'] . '" (' . item_label($item) . ') was closed because the item was removed.',
            '/my-claims',
            'Your claim was closed'
        );
    }
    return $result;
}
