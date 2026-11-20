<?php
/**
 * Optional: send / retry queued emails from the command line or Task Scheduler / cron.
 *   php cron/send_emails.php
 * (The web app also does this by itself after each request, so this is only a safety net
 * for quiet periods when nobody is using the site.)
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require dirname(__DIR__) . '/config.php';
require INCLUDES . '/functions.php';
require INCLUDES . '/db.php';
require INCLUDES . '/mailer.php';

$sent = process_email_queue(50);
echo "Sent $sent queued email(s).\n";
