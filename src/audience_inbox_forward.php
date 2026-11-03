<?php
declare(strict_types=1);

/** The web process knows the mail mode. Only the dispatch worker has the relay credentials. */
function audienceInboxForwardSmtpRequirement(): ?string
{
    return strtolower(trim((string)getenv('DNR_MAIL_TRANSPORT'))) === 'smtp'
        ? null : 'Forwarding requires MOED SMTP mail delivery to be enabled for this account.';
}

trait AudienceInboxForward
{
    private const FORWARD_USER = "u.account_status='active' AND u.email_verified_at IS NOT NULL AND u.email<>''";
    private ?bool $forwardingInstalled = null;

    public function inboxForwardingInstalled(): bool
    {
        return $this->forwardingInstalled ??= (bool)$this->one("SELECT 1 FROM information_schema.columns
            WHERE table_schema=DATABASE() AND table_name='marketing_inbox_messages' AND column_name='email_subject'");
    }

    /** Users belong to the current MOED account's database. Only IDs are accepted from the form. */
    public function inboxForwardUsers(?array $ids = null): array
    {
        $where = self::FORWARD_USER;
        if ($ids !== null) {
            if (!$ids) return [];
            $where .= ' AND u.id IN ('.implode(',', array_fill(0, count($ids), '?')).')';
        }
        return $this->all("SELECT u.id,u.username,u.email FROM users u WHERE $where ORDER BY u.username,u.id", $ids ?? []);
    }

    public function queueInboxForward(int $threadId, int $messageId, mixed $selected, string $requestKey): void
    {
        if (!$this->inboxForwardingInstalled()) throw new InvalidArgumentException('Forwarding requires the Inbox database update.');
        $ids = audienceIds($selected);
        if (!$ids || count($ids) > 25) throw new InvalidArgumentException('Choose between 1 and 25 MOED users.');
        if (!preg_match('/\A[a-f0-9]{32}\z/D', $requestKey)) throw new InvalidArgumentException('Reopen the email and try forwarding again.');
        sort($ids, SORT_NUMERIC);
        $batch = hash('sha256', "inbox-forward:$this->actorId:$messageId:$requestKey");

        $this->transaction(function () use ($threadId, $messageId, $ids, $batch) {
            $thread = $this->inboxThread($threadId, true);
            if ($thread['status'] === 'trash') throw new InvalidArgumentException('Restore the conversation before forwarding an email.');
            $original = $this->one("SELECT email_subject,sender_name,sender_email,occurred_at,body_text FROM marketing_inbox_messages
                WHERE audience_id=? AND thread_id=? AND id=? AND direction='inbound' AND email_subject IS NOT NULL", [$this->audienceId, $threadId, $messageId]);
            if (!$original) throw new InvalidArgumentException('Choose a received email from this conversation.');

            // The thread lock serializes repeat submissions of the same dialog.
            $prior = $this->all('SELECT recipient_user_id FROM marketing_inbox_forwards WHERE audience_id=? AND batch_key=? ORDER BY recipient_user_id', [$this->audienceId, $batch]);
            if ($prior) {
                if (array_map('intval', array_column($prior, 'recipient_user_id')) !== $ids) {
                    throw new InvalidArgumentException('This forward was already submitted. Reopen the email to forward it again.');
                }
                return;
            }
            $users = $this->inboxForwardUsers($ids);
            if (count($users) !== count($ids)) throw new InvalidArgumentException('Choose active MOED users with verified email addresses in this account.');
            $requirement = audienceInboxForwardSmtpRequirement();
            if ($requirement !== null) throw new InvalidArgumentException($requirement);

            $sender = $original['sender_name'] === '' ? $original['sender_email'] : $original['sender_name'].' <'.$original['sender_email'].'>';
            $mail = [
                'subject' => 'Fwd: '.$original['email_subject'], 'inbox_thread_id' => $threadId,
                'text' => "Forwarded by $this->actorLabel\n\n---------- Forwarded email ----------\nFrom: $sender\n"
                    .'Date: '.formatUserTimestamp($original['occurred_at'], 'M j, Y, g:i A T')."\nSubject: ".$original['email_subject']."\n\n".$original['body_text'],
                'attachments' => $this->inboxMailAttachments($messageId, fromDraft: false),
            ];
            foreach ($users as $user) {
                $email = audienceInboxEmail($user['email']);
                $mailId = '<inbox-forward-'.bin2hex(random_bytes(24)).'@message-monkey.invalid>';
                $payload = ['message' => $mail + ['email' => $email, 'message_id' => $mailId]];
                $sealed = \Dnr\Security\ApplicationKey::seal(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                $this->write("INSERT INTO marketing_service_mail(audience_id,kind,actor_id,actor_label,operation_key,payload_ciphertext,message_id,next_attempt_at,expires_at)
                    VALUES (?,'inbox-forward',?,?,?,?,?,UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(),INTERVAL 7 DAY))",
                    [$this->audienceId, $this->actorId, $this->actorLabel, hash('sha256', $batch.':'.$user['id']), $sealed, $mailId]);
                $this->write('INSERT INTO marketing_inbox_forwards(service_mail_id,audience_id,message_id,batch_key,recipient_user_id,recipient_email) VALUES (?,?,?,?,?,?)',
                    [$this->db->insert_id, $this->audienceId, $messageId, $batch, $user['id'], $email]);
            }
        });
    }

    public function inboxForwardDeliveryAllowed(int $serviceId, int $threadId, string $email): bool
    {
        return (bool) $this->one('SELECT f.service_mail_id FROM marketing_inbox_forwards f
            JOIN marketing_inbox_messages m ON m.audience_id=f.audience_id AND m.id=f.message_id
            JOIN marketing_inbox_threads t ON t.audience_id=m.audience_id AND t.id=m.thread_id
            JOIN users u ON u.id=f.recipient_user_id AND LOWER(u.email)=f.recipient_email
            WHERE f.audience_id=? AND f.service_mail_id=? AND t.id=? AND f.recipient_email=? AND t.status<>\'trash\' AND '.self::FORWARD_USER,
            [$this->audienceId, $serviceId, $threadId, $email]);
    }

    /** A conversation is marked forwarded once at least one copy has been accepted. */
    public function inboxForwardStates(array $threadIds): array
    {
        if (!$threadIds || !$this->inboxForwardingInstalled()) return [];
        $slots = implode(',', array_fill(0, count($threadIds), '?'));
        $rows = $this->all("SELECT m.thread_id,
            CASE WHEN MAX(s.state='accepted') THEN 'forwarded' WHEN MAX(s.state IN ('queued','processing')) THEN 'pending' ELSE '' END AS forward_state
            FROM marketing_inbox_messages m JOIN marketing_inbox_forwards f ON f.audience_id=m.audience_id AND f.message_id=m.id
            JOIN marketing_service_mail s ON s.audience_id=f.audience_id AND s.id=f.service_mail_id
            WHERE m.audience_id=? AND m.thread_id IN ($slots) GROUP BY m.thread_id", [$this->audienceId, ...$threadIds]);
        return array_column($rows, 'forward_state', 'thread_id');
    }

    /** Trash/purge already hold the same per-thread lock as dispatch. */
    private function cancelInboxForwards(int $threadId, bool $purge): void
    {
        $pending = "(s.state='queued' OR (s.state='processing' AND s.delivery_started_at IS NULL))";
        $this->write("UPDATE marketing_service_mail s JOIN marketing_inbox_forwards f ON f.audience_id=s.audience_id AND f.service_mail_id=s.id
            JOIN marketing_inbox_messages m ON m.audience_id=f.audience_id AND m.id=f.message_id
            SET s.payload_ciphertext='',s.error_message=IF($pending,'The conversation was moved to Trash.',s.error_message),
                s.state=IF($pending,'cancelled',s.state)
            WHERE m.audience_id=? AND m.thread_id=?".($purge ? '' : " AND $pending"), [$this->audienceId, $threadId]);
    }
}
