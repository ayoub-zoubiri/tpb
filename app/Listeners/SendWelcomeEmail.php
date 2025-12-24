<?php

namespace App\Listeners;

use App\Events\UserRegistered;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
// use Illuminate\Support\Facades\Mail;
// use App\Mail\WelcomeEmail;

class SendWelcomeEmail implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(UserRegistered $event): void
    {
        // For now we will log it. In a real app we'd use Mail::to($event->user)->send(new WelcomeEmail($event->user));
        Log::info('Welcome email sent to: ' . $event->user->email);
        
        // Simulating email sending delay
        // sleep(2);
    }
}
