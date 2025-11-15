<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Lottery extends Model
{
    use HasFactory;

    protected $table = 'lotteries'; // Specify the actual table name if it's different

    protected $primaryKey = 'lot_id'; // Specify the primary key column name

    protected $fillable = [


    ];

}
