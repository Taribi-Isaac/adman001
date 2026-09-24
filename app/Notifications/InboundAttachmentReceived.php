<?php

namespace App\Notifications;

use App\Models\MessageAttachment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InboundAttachmentReceived extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly MessageAttachment $attachment,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $attachment = $this->attachment->loadMissing(['conversation', 'contact', 'message']);

        return (new MailMessage)
            ->subject('Inbound file awaiting review')
            ->line('A customer or contact submitted a file through WhatsApp.')
            ->line('Conversation #'.$attachment->conversation_id)
            ->line('Contact: '.($attachment->contact?->display_name ?? 'Unknown / unlinked'))
            ->line('Filename: '.($attachment->original_filename ?: 'attachment'))
            ->line('Received: '.($attachment->created_at?->toDateTimeString() ?? 'n/a'))
            ->action('Open conversation', url('/conversations/'.$attachment->conversation_id))
            ->line('This notification does not confirm payments or validate document contents.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'inbound_attachment_received',
            'message_attachment_id' => $this->attachment->id,
            'conversation_id' => $this->attachment->conversation_id,
            'contact_id' => $this->attachment->contact_id,
            'original_filename' => $this->attachment->original_filename,
            'mime_type' => $this->attachment->mime_type,
            'media_kind' => $this->attachment->media_kind,
        ];
    }
}
