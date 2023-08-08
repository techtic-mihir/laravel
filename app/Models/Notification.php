<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Notification extends Model
{
    use HasFactory;
    use SoftDeletes;
    // protected $fillable = ['title', 'body', 'from_user_id', 'to_user_id'];
    protected $guarded = ['id'];

    public function fromUser()
    {
        return $this->belongsTo(User::class);
    }

    public function toUser()
    {
        return $this->belongsTo(User::class);
    }
}