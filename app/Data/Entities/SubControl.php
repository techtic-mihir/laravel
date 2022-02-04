<?php

namespace App\Data\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SubControl extends Model {

    use SoftDeletes;

    protected $table = 'sub_control';
    protected $fillable = [
        'app_id', 'title', 'description', 'budget_amount', 'notes', 'progress', 'start_date', 'end_date', 'vendor',
         'document_link','document_label', 'is_recurring_initiative', 'recurring_period', 'occurance_limit', 'is_disable_note', 'level'
    ];
    protected $hidden = [
        'created_at', 'updated_at'
    ];
    protected $dates = ['deleted_at'];

    public function app() {
        return $this->belongsTo('App\Data\Entities\App');
    }
    public function tasks() {
        return $this->hasMany('App\Data\Entities\Task');
    }
    public function profiles() {
        return $this->hasMany('App\Data\Entities\SubcontrolProfile');
    }

    public function riskRatings() {
        return $this->hasMany('App\Data\Entities\SubcontrolRiskRating');
    }

    public function documents() {
        return $this->belongsToMany(Document::class, 'document_sub_controls', 'sub_control_id', 'document_id');
    }

    public function documentLinks() {
        return $this->belongsToMany(Document::class, 'document_sub_controls', 'sub_control_id', 'document_id');
    }

    public function childSubControls() {
        return $this->hasMany('App\Data\Entities\SubControl', 'parent_sub_control_id');
    }
    public function users() {
        return $this->belongsToMany('App\Data\Entities\User', 'sub_control_user_map')->withPivot('notified', 'assigned_by');
    }

    public function subControlActivities() {
        return $this->belongsToMany('App\Data\Entities\ControlActivity', 'sub_control_activity_map');
    }

    public function scoringHistory() {
        return $this->hasMany('App\Data\Entities\ScoringHistory');
    }

    public function getFirstAlert() {
        return $this->users()->wherePivot('notified', 0);
    }

    public function getSecondAlert() {
        return $this->users()->wherePivot('notified', 0)->orWherePivot('notified', 1);
    }

    public function subcontrolDoc() {
        return $this->hasMany('App\Data\Entities\DocumentSubcontrol', 'sub_control_id');
    }

    public function comments() {
        return $this->hasMany('App\Data\Entities\SubcontrolComment', 'sub_control_id', 'id');
    }
}
