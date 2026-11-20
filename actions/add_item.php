<?php
defined('ROOT') or exit;

// A request bigger than PHP's post_max_size arrives with an empty $_POST (and no CSRF token)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && empty($_POST) && !empty($_SERVER['CONTENT_LENGTH'])) {
    flash('error', 'The upload was too large. Please choose a smaller photo.');
    redirect('/report');
}

require_post_csrf('/report');

$item_name   = post_str('item_name');
$type        = post_str('type');
$category    = post_str('category');
$location    = post_str('location');
$item_date   = post_str('item_date');
$description = post_str('description');
$user_id     = (int) $_SESSION['user_id'];

$errors = [];
if ($item_name === '' || text_length($item_name) > 100) {
    $errors[] = 'Item name is required (max 100 characters).';
}
if (!in_array($type, ['Lost', 'Found'], true)) {
    $errors[] = 'Please choose Lost or Found.';
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
    redirect('/report');
}

// Optional photo (works for both Lost and Found reports); validated, shrunk and re-compressed
[$image, $image_error] = save_item_image($_FILES['image'] ?? null);
if ($image_error !== null) {
    flash('error', $image_error);
    redirect('/report');
}

$conn = db();
mysqli_begin_transaction($conn);
try {
    db_exec(
        'INSERT INTO items (user_id, item_name, type, category, location, item_date, description, image)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        'isssssss',
        [$user_id, $item_name, $type, $category, $location, $item_date, $description, $image]
    );
    $new_id = (int) mysqli_insert_id($conn);
    $code   = make_item_code($category, $new_id);          // FR-04: e.g. ELEC-042
    db_exec('UPDATE items SET item_code = ? WHERE id = ?', 'si', [$code, $new_id]);
    log_item_history($new_id, $user_id, 'created', null, 'Open', "$type report");
    mysqli_commit($conn);
} catch (Throwable $ex) {
    mysqli_rollback($conn);
    delete_item_image($image);   // don't leave an orphan file behind
    throw $ex;
}

flash('success', "Item reported successfully. Its ID is $code — the Security Office uses it for labelling.");
redirect('/items');
