<?php
defined('ROOT') or exit;
require_post_csrf('/admin');

$id     = (int) ($_POST['id'] ?? 0);
$action = post_str('action');
$admin  = (int) $_SESSION['user_id'];

if ($id <= 0 || !in_array($action, ['approve', 'reject'], true)) {
    redirect('/admin');
}

$conn       = db();
$flash_type = 'error';
$flash_msg  = 'Something went wrong. No changes were saved.';
$notices    = [];     // [user_id, message, link, email subject] — sent after the transaction is committed

mysqli_begin_transaction($conn);
try {
    // Lock the claim so two admins clicking at once cannot both act on it
    $claim = db_row('SELECT id, item_id, user_id, status FROM claims WHERE id = ? FOR UPDATE', 'i', [$id]);

    if (!$claim) {
        $flash_msg = 'Claim not found.';
        mysqli_rollback($conn);

    } elseif ($claim['status'] !== 'Pending') {
        // Already reviewed: approving/rejecting again would corrupt the item's status
        $flash_msg = 'This claim has already been reviewed.';
        mysqli_rollback($conn);

    } else {
        $item = db_row(
            'SELECT id, item_code, item_name, status, user_id FROM items WHERE id = ? AND deleted_at IS NULL FOR UPDATE',
            'i', [(int) $claim['item_id']]
        );
        $label = $item ? '"' . $item['item_name'] . '" (' . item_label($item) . ')' : 'your item';

        if ($action === 'reject') {
            db_exec("UPDATE claims SET status = 'Rejected' WHERE id = ?", 'i', [$id]);
            if ($item) {
                log_item_history((int) $item['id'], $admin, 'claim', null, null, "Claim #$id rejected");
            }
            mysqli_commit($conn);
            $notices[]  = [(int) $claim['user_id'], "Your claim for $label was rejected. If you think this is a mistake, contact " . PICKUP_POINT . '.', '/my-claims', 'Your claim was rejected'];
            $flash_type = 'success';
            $flash_msg  = 'Claim rejected. The student has been notified.';

        } elseif (!$item || $item['status'] !== 'Open') {
            $flash_msg = 'This item is no longer open, so the claim cannot be approved.';
            mysqli_rollback($conn);

        } else {
            $item_id = (int) $claim['item_id'];

            // Other pending claimants will be rejected: remember who to tell
            $others = [];
            $res = db_query("SELECT user_id FROM claims WHERE item_id = ? AND id <> ? AND status = 'Pending'", 'ii', [$item_id, $id]);
            while ($o = mysqli_fetch_assoc($res)) {
                $others[] = (int) $o['user_id'];
            }

            db_exec("UPDATE claims SET status = 'Approved' WHERE id = ?", 'i', [$id]);
            db_exec("UPDATE items  SET status = 'Claimed'  WHERE id = ?", 'i', [$item_id]);
            db_exec("UPDATE claims SET status = 'Rejected' WHERE item_id = ? AND id <> ? AND status = 'Pending'", 'ii', [$item_id, $id]);
            log_item_history($item_id, $admin, 'status', 'Open', 'Claimed', "Claim #$id approved");
            mysqli_commit($conn);

            $notices[] = [(int) $claim['user_id'], "Good news! Your claim for $label was approved. Please collect it from " . PICKUP_POINT . ' with your university ID.', '/my-claims', 'Your claim was approved'];
            foreach (array_unique($others) as $uid) {
                $notices[] = [$uid, "Your claim for $label was not approved because the item was given to another claimant.", '/my-claims', 'Your claim was rejected'];
            }
            if ($item['user_id'] !== null && (int) $item['user_id'] !== (int) $claim['user_id']) {
                $notices[] = [(int) $item['user_id'], "The status of your item $label changed from Open to Claimed.", '/my-items', 'Your item status changed'];
            }

            $flash_type = 'success';
            $flash_msg  = 'Claim approved. Item marked as Claimed.'
                        . (count($others) > 0 ? ' ' . count($others) . ' other pending claim(s) on this item were rejected.' : '')
                        . ' Students have been notified.';
        }
    }
} catch (Throwable $ex) {
    mysqli_rollback($conn);
    $notices = [];
    error_log('[UniTrace] claim_action: ' . $ex);
}

foreach ($notices as [$uid, $msg, $link, $subject]) {
    notify_user($uid, $msg, $link, $subject);     // FR-08a / FR-09a: in-app alert + queued email
}

flash($flash_type, $flash_msg);
redirect('/admin');
