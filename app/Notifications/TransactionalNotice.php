<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TransactionalNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $subject, public string $line, public ?string $url = null)
    {
        $this->onQueue('mail');
        $this->afterCommit();
    }

    /** @return array<array-key, mixed> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->subject)->line($this->line);
        if ($this->url) {
            $mail->action('Continue', $this->url);
        }

        return $mail;
    }
}
