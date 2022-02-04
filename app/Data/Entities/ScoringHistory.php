<?php

namespace App\Data\Entities;

use Illuminate\Database\Eloquent\Model;

class ScoringHistory extends Model 
{

    protected $table = 'scoring_history';
    
    protected $fillable = [
        'subcontrol_id', 'scored_by', 'old_value', 'new_value'
    ];

    protected $hidden = ['updated_at'];  
}
