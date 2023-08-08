<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\FileUploadTrait;
use Illuminate\Notifications\Notifiable;
use Ramsey\Uuid\Uuid;

class CustomerDetail extends Model
{
    use HasFactory;
    use SoftDeletes;
    use FileUploadTrait;
    use Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'city',
        'state',
        'status',
        'address',
        'country',
        'zip_code',
        'customer_type',
        'hubspot_contact_id',
        'registration_date'
    ];

    public function setProfilePicAttribute($value)
    {
        if (!empty($value) && is_file($value)) {
            $this->saveFile($value, 'profile_pic', "customer/" . date('Y/m'));
        }
    }

    public function getProfilePicAttribute()
    {
        if (!empty($this->attributes['profile_pic'])) {
            return $this->getFileUrl($this->attributes['profile_pic']);
        }
    }
}
