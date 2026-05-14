<?php

namespace App\Mail;

use App\Models\Flight\FlightBooking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class FlightBookingTicketMailable extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public FlightBooking $booking)
    {
        $this->booking->loadMissing(['customer', 'passengers']);
    }

    public function envelope(): Envelope
    {
        $num = $this->booking->booking_number ?? (string) $this->booking->id;

        return new Envelope(
            subject: 'تذكرة سفر — '.$num,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.flight.booking-ticket',
            with: ['booking' => $this->booking],
        );
    }
}
