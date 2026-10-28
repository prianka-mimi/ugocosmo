<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NewMessageMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public string $recipientName;

    public string $senderName;

    public string $messagePreview;

    public int $conversationId;

    public function __construct(string $recipientName, string $senderName, string $messagePreview, int $conversationId)
    {
        $this->recipientName = $recipientName;
        $this->senderName = $senderName;
        $this->messagePreview = $messagePreview;
        $this->conversationId = $conversationId;
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: '💬 New Message — UgoCosmo');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.message.new',
            with: [
                'recipientName' => $this->recipientName,
                'senderName' => $this->senderName,
                'messagePreview' => $this->messagePreview,
                'conversationId' => $this->conversationId,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
