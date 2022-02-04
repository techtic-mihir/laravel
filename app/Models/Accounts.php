<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Custodians;

class Accounts extends Model
{
    protected $table = 'accounts';

    protected $guarded = ['id'];

    protected $appends  = ['starting_balance'];

    public function households()
    {
        return $this->belongsTo('App\Models\Households');
    }

    public function custodian()
    {
        return $this->belongsTo('App\Models\Custodians', 'custodian_id', 'id');
    }

    public function getStartingBalanceAttribute()
    {
        return 0.00;
    }

    public function accountTypes(){
        return $this->hasOne('App\Models\AccountTypes','id','type_id');

    }

    public function getNameAttribute($value)
    {
        return ucwords($value);
    }
}
