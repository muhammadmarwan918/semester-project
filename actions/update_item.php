<?php
defined('ROOT') or exit;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && empty($_POST) && !empty($_SERVER['CONTENT_LENGTH'])) {
    flash('error', 'The upload was too large. Please choose a smaller photo.');
    redirect('/my-items');
}
require_post_csrf('/my-items');

$id          = (int) ($_POST['id'] ?? 0);
$item_name   = post_str('item_name');
$category    = post_str('category');
$location    = post_str('location');
$item_date   = post_str('item_date');
$description = post_str('description');
$remove_img  = post_str('remove_image') === '1';
$user_id     = (int) $_SESSION['user_id'];
$back        = '/edit-item?id=' . $id;

$errors = [];
if ($item_name === '' || text_length($item_name) > 100) {
    $errors[] = 'Item name is required (max 100 characters).';
}
if (!in_array($category, ITEM_CATEGORIES, true)) {
    $errors[] = 'Please choose a valid category.';
}
if ($location === '' || text_length($location) > 100) {
    $errors[] = 'Location is required (max 100 characters).';
}
if (!is_valid_date($item_date) || $item_date > date('Y-m-d')) {
    $errors[] = 'Please enter a real date that is not in the future.';
}
if ($description === '' || text_length($description) > 2000) {
    $errors[] = 'Description is required (max 2000 characters).';
}
if ($errors) {
    flash('error', implode(' ', $errors));
    redirect($back);
}

[$new_image, $image_error] = save_item_image($_FILES['image'] ?? null);
if ($image_error !== null) {
    flash('error', $image_error);
    redirect($back);
}

$conn      = db();
$error     = null;
$old_image = null;

mysqli_begin_transaction($conn);
try {
    // Lock the row so a status change by an admin cannot slip in between the check and the update
    $item = db_row(
        'SELECT id, status, image FROM items WHERE id = ? AND user_id = ? AND deleted_at IS NULL FOR UPDATE',
        'ii',
        [$id, $user_id]
    );

    if (!$item) {
        $error = 'Report not found.';
        $back  = '/my-items';
    } elseif ($item['status'] !== 'Open') {
        $error = 'Only reports with status Open can be edited.';
        $back  = '/my-items';
    } else {
        $image = $item['image'];
        if ($new_image !== null) {
            $image = $new_image;
        } elseif ($remove_img) {
            $image = null;
        }
        $old_image = ($image !== $item['image']) ? $item['image'] : null;

        db_exec(
            'UPDATE items SET item_name = ?, category = ?, item_code = ?, location = ?, item_date = ?, description = ?, image = ? WHERE id = ?',
            'sssssssi',
            [$item_name, $category, make_item_code($category, $id), $location, $item_date, $description, $image, $id]
        );
        log_item_history($id, $user_id, 'edited', 'Open', 'Open', 'Report edited by reporter');
    }

    if ($error === null) {
        mysqli_commit($conn);
    } else {
        mysqli_rollback($conn);
    }
} catch (Throwable $ex) {
    mysqli_rollback($conn);
    error_log('[UniTrace] update_item: ' . $ex);
    $error = 'Something went wrong. No changes were saved.';
}

if ($error !== null) {
    delete_item_image($new_image);     // the new photo was never attached
    flash('error', $error);
    redirect($back);
}

delete_item_image($old_image);         // replaced / removed photo
flash('success', 'Report updated.');
redirect('/my-items');
