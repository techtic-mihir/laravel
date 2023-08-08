<?php

namespace App\Data\Entities;

use Illuminate\Database\Eloquent\Model;
use Storage;
use Illuminate\Database\Eloquent\SoftDeletes;

class Document extends Model {

    use SoftDeletes;

    protected $table = 'document';
    
    protected $fillable = [
        'sub_control_id', 'file_path', 'title', 'organization_id', 'is_display', 'type'
    ];

    protected $hidden = [
        'created_at', 'updated_at'
    ];
    /**
    * The accessors to append to the model's array form.
    *
    * @var array
    */
    protected $appends = ['full_url'];

    protected $dates = ['deleted_at'];

    public function subcontrol() 
    {
        return $this->belongsTo('App\Data\Entities\SubControl', 'sub_control_id');
    }

    public function subcontrolDocuments() 
    {
        return $this->hasMany('App\Data\Entities\DocumentSubcontrol', 'document_id');
    }

    public function getDownloadUrlAttribute()
    {
        if (!empty($this->attributes['type']) && $this->attributes['type'] == 'document' && !empty($this->attributes['file_path'])) {
            $s3 = Storage::disk('s3_storage');

            $command = $s3->getDriver()->getAdapter()->getClient()->getCommand('GetObject', [
                'Bucket' => config('filesystems.disks.s3_storage.bucket'),
                'Key' => $this->attributes['file_path'],
                'ResponseContentType' => "{$this->mime_type}",
                'ResponseContentDisposition' => "attachment;filename=\"$this->title\"",
            ]);

            $request = $s3->getDriver()->getAdapter()->getClient()->createPresignedRequest($command, '+10 minutes');
            return (string) $request->getUri();
        } else {
            return '';
        }
    }

    public function getFullUrlAttribute()
    {
        if (!empty($this->attributes['type']) && $this->attributes['type'] == 'document' && !empty($this->attributes['file_path'])) {
            $s3 = Storage::disk('s3_storage');

            $command = $s3->getDriver()->getAdapter()->getClient()->getCommand('GetObject', [
                'Bucket' => config('filesystems.disks.s3_storage.bucket'),
                'Key'    => $this->attributes['file_path']
            ]);

            $request = $s3->getDriver()->getAdapter()->getClient()->createPresignedRequest($command, '+10 minutes');
            return (string) $request->getUri();
        } else {
            return '';
        }
    }

}
