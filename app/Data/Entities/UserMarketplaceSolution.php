<?php

namespace App\Data\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Data\Entities\Marketplace\Solution;

class UserMarketplaceSolution extends Model {

    use SoftDeletes;

    protected $table = 'user_marketplace_solutions';

    protected $guarded = ['id'];

    /**
	* Get saved solutions
	*/
    public function scopeSaved($query)
    {
    	return $query->where("status", 'saved');
    }

    /**
    * Get contacted solutions
    */
    public function scopeContacted($query)
    {
        return $query->where("status", 'contacted');
    }

    /**
    * Get implemented solutions
    */
    public function scopeImplemented($query)
    {
        return $query->where("status", 'implemented');
    }

    public function solution()
    {
        return $this->belongsTo(Solution::class, 'solution_id', 'id');
    }

    public function subControl()
    {
        return $this->belongsTo(SubControl::class, 'subcontrol_template_id', 'id');
    }

    public function subControlTemplate()
    {
        return $this->belongsTo(SubControlTemplate::class, 'subcontrol_template_id', 'id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function framework()
    {
        return $this->belongsTo(Framework::class, 'framework_id', 'id');
    }
}
