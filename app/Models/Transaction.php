<?php

// app/Models/Transaction.php

namespace App\Models;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    protected $table = 'transactions';
    protected $primaryKey = 'transaction_id';
    protected $fillable = ['debit', 'credit', 'balance', 'seller_id', 'transaction_remarks', 'customer_id'];

    public function seller(){
        return $this->belongsTo(User::class, 'seller_id', 'user_id');
    }

      // Remove or set timestamps to false
      public $timestamps = false;
}

