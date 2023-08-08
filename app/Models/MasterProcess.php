<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Ramsey\Uuid\Uuid;

class MasterProcess extends Model
{
    use HasFactory;
    use SoftDeletes;
    protected $guarded = ['id'];

    
    // for create uuid.
    public static function boot()
    {   
        parent::boot();
        self::creating(function ($model) {
            $model->uuid = (string) Uuid::uuid4();
        });
    }

    public function processManagements()
    {
        return $this->belongsToMany(ProcessManagement::class, 'master_process_maps', 'master_process_id','process_management_id')->withPivot(['order'])
        ->orderBy('order','asc');
    }

    public function settings()
    {
        return $this->hasOne(MasterProcessSetting::class, 'master_process_id');
    }

    public function replicateRow($data)
    {
        $clone = $this->replicate();
        $clone->title = $data['title'] ?? null;
        $clone->description = $data['description'] ?? null;
        $clone->push();
        $processIds = $this->processManagements()->pluck('id')->toArray();
        $ids = [];
        $order = 1;
        foreach($processIds as $id) {
            $ids[$id] = ['order' => $order];
            $order++;
        }
        $clone->processManagements()->sync($ids);
        return $clone;
    }

    public function user() {
        return $this->belongsTo(User::class);
    }

    public function customerProcess() {
        return $this->hasMany(CustomerMasterProcess::class, 'masterprocess_id');
    }

    public function customerPendingProcess() {
        return $this->hasMany(CustomerMasterProcess::class, 'masterprocess_id')->where('is_link_expired', "0")->where('status', "0");
    }

    public function customerInProgressProcess() {
        return $this->hasMany(CustomerMasterProcess::class, 'masterprocess_id')->where('is_link_expired', "0")->where('status', "1");
    }

    public function customerCompletedProcess() {
        return $this->hasMany(CustomerMasterProcess::class, 'masterprocess_id')->where('is_link_expired', "0")->where('status', "2");
    }

    public function customerExpiredProcess() {
        return $this->hasMany(CustomerMasterProcess::class, 'masterprocess_id')->where('is_link_expired', "1");
    }
}
