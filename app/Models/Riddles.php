<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Riddles extends Model
{
    use HasFactory;

    protected $table = 'riddles'; // Adjust the table name if needed
    protected $primaryKey = 'rid_id';

    protected $fillable = [
        'rid_title',
        'rid_img',
        'user_id',

    ];

    // Remove or set timestamps to false
    public $timestamps = false;
}
