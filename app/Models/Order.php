<?php

// app\Models\Order.php

namespace App\Models;
use App\Models\User;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $table = 'orders';
    protected $primaryKey = 'order_id';
    // Define the relationship with OrderItem
    public function orderItems()
    {
        return $this->hasMany(OrderItem::class, 'order_id', 'order_id');
    }
    
    public function addedUser()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    protected $fillable = [
        'order_date', 'client_name', 'client_contact', 'sub_total', 'grand_total', 'note', 'transaction_id', 'user_id',
    ];

         // Remove or set timestamps to false
    public $timestamps = false;

}

