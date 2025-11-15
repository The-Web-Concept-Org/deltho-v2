<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class RequestConfirmation extends Mailable
{
    use Queueable, SerializesModels;

    public $username;
    public $email;
    public $phone;
    public $userRole;
    public $address;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct($userData)
    {
        $this->username = $userData['username'];
        $this->email = $userData['email'];
        $this->phone = $userData['phone'];
        $this->userRole = $userData['user_role'];
        $this->address = $userData['address'];
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        return $this->view('emails.requestConfirmation')
                    ->subject('Request Confirmation');
    }
}
