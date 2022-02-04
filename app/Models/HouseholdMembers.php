<?php

namespace App\Models;

use App\Traits\EzLynxUpload;
use Illuminate\Database\Eloquent\Model;
use App\Events\CreateCRMContact;
use Auth;
use App\Events\LastModifiedDate as LastModifiedDateEvent;

class HouseholdMembers extends Model
{
    use EzLynxUpload;

    protected $table = 'household_members';

    protected $guarded = ['id'];

    protected $appends = ['initials_avatar', 'random_color', 'occupation_code', 'industry_occupation_code', 'full_name'];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'existing_accounts' => 'array',
        'miscellaneous_fields' => 'array',
        'miscellaneous_form_fields' => 'array'
    ];

    public function memberType()
    {
        return $this->hasOne('App\Models\HouseholdMemberTypes', 'id', 'type_id');
    }

    public function memberEmployer()
    {
        return $this->hasOne('App\Models\HouseholdMemberEmployer', 'household_member_id', 'id');
    }

    public function memberBeneficiaries()
    {
        return $this->hasOne('App\Models\HouseholdMemberBeneficiaries', 'household_member_id', 'id');
    }

    public function households()
    {
        return $this->hasOne('App\Models\Households', 'id', 'household_id');
    }

    public function getFirstNameAttribute($value)
    {
        return ucfirst($value);
    }

    public function getLastNameAttribute($value)
    {
        return ucfirst($value);
    }

    public function insurance(){
        return $this->hasMany('App\Models\HouseholdMembersInsurance', 'household_member_id', 'id');
    }

    public function getInitialsAvatarAttribute()
    {
        $name              = ($this->last_name) ? $this->first_name . ' ' . $this->last_name : $this->first_name;
        $words             = explode(" ", $name);
        $initialsCharacter = "";
        foreach ($words as $w) {
            if (!empty($w[0])) {
                $initialsCharacter .= $w[0];
            }
        }
        return $initialsCharacter;
    }

    public function getRandomColorAttribute()
    {
        return '#' . str_pad(dechex(mt_rand(0, 0xFFFFFF)), 6, '0', STR_PAD_LEFT);
    }

    public function getXmlRequestAttribute()
    {
        return $this->serialize();
    }

    /**
     * The fields that belong to the household members insurance questions.
     */
    public function fields()
    {
        return $this->hasOne('App\Models\HouseholdMembersInsurance', 'household_member_id', 'id')->select('*');
    }

    /**
    * getter for household member full name
    * @return string
    */
    public function getFullNameAttribute()
    {
        return ucwords($this->first_name) .' '. ucwords($this->last_name);
    }

    public function getOccupationCodeAttribute()
    {
        $value = $this->occupation;
        if ((string)(int) $value == $value) {
            return TdPaperworkOccupationCode::find($value)->code;
        }

        return $value;
    }

    public function getIndustryOccupationCodeAttribute($value = '')
    {
        $value = $this->industry_occupation;
        if ((string)(int) $value == $value) {
            return TdPaperworkIndustryCode::find($value)->code;
        }

        return $value;
    }

    public static function boot()
    {
        parent::boot();
        static::created(function($model) {
            $user_id = (Auth::id()) ? Auth::id() : null;
            $integrations = ['redtail'];

            foreach ($integrations as $key => $value) {
                event(new CreateCRMContact($value, $model->household_id, $model->id, $user_id));
            }
        });

        static::updated(function($model) {
            $instanceType = config('benjamin.instance_type');
            if($instanceType == 'insurance'){
                event(new LastModifiedDateEvent($model->household_id, $model->updated_at));
            }
        });

    }
}
