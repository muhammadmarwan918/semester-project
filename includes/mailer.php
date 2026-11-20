<?php
defined('ROOT') or exit;
/**
 * UniTrace — notifications (in-app alerts) and the email queue
 *
 * FR-08a / FR-09a : tell users when a claim is decided or an item's status changes
 * NFR-08          : emails are written to a queue first and retried if sending fails,
 *                   so a mail-server hiccup never silently loses an update.
 */

/** Put an email in the queue. It is sent right after the current request (or by cron/send_emails.php). */
function queue_email(string $to, string $subject, string $body): void
{
    db_exec(
        'INSERT INTO email_queue (to_email, subject, body, next_attempt_at) VALUES (?, ?, ?, ?)',
        'ssss',
        [mb_substr($to, 0, 100), mb_substr(str_replace(["\r", "\n"], ' ', $subject), 0, 200), $body, date('Y-m-d H:i:s')]
    );
}

/**
 * Tell one user about something: an in-app alert (bell icon) AND an email.
 * $link_path is an app route such as '/my-claims'.
 */
function notify_user(int $user_id, string $message, ?string $link_path = null, ?string $email_subject = null): void
{
    try {
        db_exec(
            'INSERT INTO notifications (user_id, message, link) VALUES (?, ?, ?)',
            'iss',
            [$user_id, mb_substr($message, 0, 255), $link_path]
        );

        $user = db_row('SELECT email, name FROM users WHERE id = ?', 'i', [$user_id]);
        if ($user) {
            $body = "Hello {$user['name']},\n\n$message\n";
            if ($link_path !== null) {
                $body .= "\nOpen UniTrace: " . absolute_url($link_path) . "\n";
            }
            $body .= "\n— UniTrace, University Lost & Found Portal\n";
            queue_email($user['email'], $email_subject ?? 'UniTrace update', $body);
        }
    } catch (Throwable $ex) {
        // A notification problem must never break the action that caused it
        error_log('[UniTrace] notify_user: ' . $ex);
    }
}

/** Send one email now. Returns [ok, error message|null]. */
function send_mail_now(string $to, string $subject, string $body): array
{
    $to      = str_replace(["\r", "\n"], '', $to);
    $subject = str_replace(["\r", "\n"], ' ', $subject);

    if (MAIL_TRANSPORT === 'log') {
        $line = '[' . date('Y-m-d H:i:s') . "] To: $to | Subject: $subject\n$body\n" . str_repeat('-', 60) . "\n";
        return file_put_contents(INCLUDES . '/mail.log', $line, FILE_APPEND | LOCK_EX) !== false
            ? [true, null]
            : [false, 'Could not write includes/mail.log'];
    }

    $from    = str_replace(["\r", "\n"], '', MAIL_FROM);
    $headers = [
        'From: ' . mb_encode_mimeheader(MAIL_FROM_NAME, 'UTF-8') . " <$from>",
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];
    $ok = @mail($to, mb_encode_mimeheader($subject, 'UTF-8'), $body, implode("\r\n", $headers));
    if ($ok) {
        return [true, null];
    }
    $err = error_get_last();
    return [false, mb_substr($err['message'] ?? 'mail() returned false', 0, 250)];
}

/**
 * Send queued emails that are due. Failed ones are rescheduled using MAIL_RETRY_MINUTES;
 * after the last retry they are marked failed (and stay in the table for the admin to see).
 * Returns how many were sent.
 */
function process_email_queue(int $limit = 5): int
{
    $now  = date('Y-m-d H:i:s');
    $rows = [];
    $res  = db_query(
        'SELECT id, to_email, subject, body, attempts FROM email_queue
         WHERE sent_at IS NULL AND failed = 0 AND next_attempt_at <= ? ORDER BY id LIMIT ?',
        'si',
        [$now, $limit]
    );
    while ($r = mysqli_fetch_assoc($res)) {
        $rows[] = $r;
    }

    $sent = 0;
    foreach ($rows as $r) {
        // Reserve the row for 10 minutes so two overlapping requests cannot send the same email twice
        $reserve = date('Y-m-d H:i:s', time() + 600);
        if (db_exec(
            'UPDATE email_queue SET next_attempt_at = ? WHERE id = ? AND sent_at IS NULL AND failed = 0 AND next_attempt_at <= ?',
            'sis',
            [$reserve, (int) $r['id'], $now]
        ) !== 1) {
            continue;
        }

        [$ok, $error] = send_mail_now($r['to_email'], $r['subject'], $r['body']);
        $attempts     = (int) $r['attempts'] + 1;

        if ($ok) {
            db_exec('UPDATE email_queue SET sent_at = ?, attempts = ?, last_error = NULL WHERE id = ?', 'sii', [date('Y-m-d H:i:s'), $attempts, (int) $r['id']]);
            $sent++;
        } elseif ($attempts > count(MAIL_RETRY_MINUTES)) {
            db_exec('UPDATE email_queue SET failed = 1, attempts = ?, last_error = ? WHERE id = ?', 'isi', [$attempts, $error, (int) $r['id']]);
        } else {
            $next = date('Y-m-d H:i:s', time() + MAIL_RETRY_MINUTES[$attempts - 1] * 60);
            db_exec('UPDATE email_queue SET attempts = ?, next_attempt_at = ?, last_error = ? WHERE id = ?', 'issi', [$attempts, $next, $error, (int) $r['id']]);
        }
    }
    return $sent;
}
