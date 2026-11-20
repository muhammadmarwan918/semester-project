<?php
defined('ROOT') or exit;
require_post_csrf('/items');

$item_id = (int) ($_POST['item_id'] ?? 0);
$message = post_str('message');
$user_id = (int) $_SESSION['user_id'];

if ($item_id <= 0) {
    flash('error', 'Item not found.');
    redirect('/items');
}
// FR-07: the proof-of-ownership text is required
if ($message === '' || text_length($message) > 1000) {
    flash('error', 'Please describe a hidden detail that proves the item is yours (max 1000 characters).');
    redirect('/claim?item_id=' . $item_id);
}

$conn  = db();
$error = null;
$back  = '/items';
$item  = null;

// One transaction + a row lock on the item, so a double-click or two simultaneous
// requests cannot create duplicate claims.
mysqli_begin_transaction($conn);
try {
    $item = db_row('SELECT id, item_code, item_name, type, status, user_id FROM items WHERE id = ? AND deleted_at IS NULL FOR UPDATE', 'i', [$item_id]);

    if (!$item) {
        $error = 'Item not found.';
    } elseif ($item['type'] !== 'Found') {
        $error = 'Only found items can be claimed. If you found this lost item, report it as Found.';
    } elseif ($item['status'] !== 'Open') {
        $error = 'This item is no longer available to claim.';
    } elseif ($item['user_id'] !== null && (int) $item['user_id'] === $user_id) {
        $error = 'You cannot claim an item that you reported yourself.';
    } elseif (db_row(
        "SELECT id FROM claims WHERE item_id = ? AND user_id = ? AND status = 'Pending'",
        'ii', [$item_id, $user_id]
    )) {
        $error = 'You already have a pending claim for this item.';
        $back  = '/my-claims';
    } else {
        db_exec('INSERT INTO claims (item_id, user_id, message) VALUES (?, ?, ?)', 'iis', [$item_id, $user_id, $message]);
        log_item_history($item_id, $user_id, 'claim', null, null, 'Claim submitted');
    }

    if ($error === null) {
        mysqli_commit($conn);
    } else {
        mysqli_rollback($conn);
    }
} catch (Throwable $ex) {
    mysqli_rollback($conn);
    error_log('[UniTrace] claim_process: ' . $ex);
    $error = 'Something went wrong. Please try again.';
}

if ($error !== null) {
    flash('error', $error);
    redirect($back);
}

// Let the reporter know someone claims their found item
if ($item['user_id'] !== null) {
    notify_user(
        (int) $item['user_id'],
        'Someone submitted a claim for your found item "' . $item['item_name'] . '" (' . item_label($item) . '). The Security Office will verify it.',
        '/my-items',
        'A claim was submitted for your item'
    );
}

flash('success', '🌟 Claim submitted! You can track it on the My Claims page.');
redirect('/my-claims');
