<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class RequestUser extends Model
{
    use HasFactory;

    protected $table = 'request_user'; // Adjust the table name if needed

    protected $fillable = [
        'username',
        'email',
        'password',
        'phone',
        'user_role',
        'address',
        'cnic_front',
        'cnic_back',
        'verified_image',
    ];

    // Add any additional methods or relationships here
}
