<?php

namespace App\Notifications;

use App\Models\Conversation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class ConversationNeedsHumanAttention extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Conversation $conversation,
        public readonly ?string $reason = null,
    ) {
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $conversation = $this->conversation->loadMissing(['identity', 'contact']);

        $mail = (new MailMessage)
            ->subject('Conversation needs human attention')
            ->line('The AI assistant handed this conversation to staff. AI replies are paused until someone takes over or returns it to AI.')
            ->line('Conversation #'.$conversation->id.' ('.$conversation->channel->label().')')
            ->line('Customer: '.($conversation->contact?->display_name ?? $conversation->identity->label()))
            ->line('Identifier: '.$conversation->identity->external_id);

        if ($this->shortReason() !== null) {
            $mail->line('Reason: '.$this->shortReason());
        }

        return $mail
            ->action('Open conversation', url('/conversations/'.$conversation->id))
            ->line('Use Take over in ADMAN to claim this conversation.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'conversation_needs_human_attention',
            'conversation_id' => $this->conversation->id,
            'contact_id' => $this->conversation->contact_id,
            'reason' => $this->shortReason(),
        ];
    }

    private function shortReason(): ?string
    {
        $reason = trim((string) $this->reason);

        return $reason === '' ? null : Str::limit($reason, 300);
    }
}
