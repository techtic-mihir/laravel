<?php

namespace App\Data\Services;

use App\Data\Entities\Document;
use App\Data\Entities\SubControl;
use App\Data\Entities\App;
use App\Data\Entities\Program;
use App\Data\Entities\PlatformDocument;
use App\Data\Entities\DocumentSubcontrol;
use App\Data\Entities\DocumentTask;
use App\Data\Entities\SubcontrolDocumentLink;
use App\Data\Entities\Framework;
use Mail;
use App\Mail\FrameworkDocumentEmail;
use Ramsey\Uuid\Uuid;
use Storage;
use Facades\ {
    App\Data\Services\AuthService,
    App\Data\Services\SubControlService
};
use App\Traits\LogsActivity;
use Carbon\Carbon;

class DocumentService {
    
    use LogsActivity;

    public function saveDocument($id = null, $data)
    {
        $document                  = new Document;
        $document->sub_control_id  = $id;
        $document->title           = $data['file_name'];
        $document->file_path       = isset($data['upload_file']) ? $data['upload_file'] : NULL;
        $document->organization_id = $data['organization_id'];
        $document->type            = $data['type'];
        $document->mime_type       = ($data['mime_type']) ?? '';
        $document->link            = isset($data['link']) ? $data['link'] : NULL;
        $document->is_display      = isset($data['is_display']) ? $data['is_display'] : true;
        $document->hash            = (!empty($document->full_url)) ? hash_file('md5', $document->full_url) : NULL;
        $document->save();

        return $document;
    }

    public function uploadDocument($file, $directory = 'documents')
    {
        $uuid     = Uuid::uuid1()->toString();
        $file     = $file->file('file');
        $fileName = $uuid . "." . $file->getClientOriginalExtension();
        $path     = Storage::disk('s3_storage')->putFileAs($directory, $file, $fileName);
        return array('success' => true, 'upload_file' => $path, 'file_name' => $file->getClientOriginalName(), 'mime_type' => $file->getMimeType());
    }

    public function uploadPDF($subcontrolId, $file, $fileName, $directory = 'documents') {
        $uuid = Uuid::uuid1()->toString();
        $s3FileName = $uuid .'.pdf';
        $path = Storage::disk('s3_storage')->putFileAs($directory, $file, $s3FileName);
        $data = array('success' => true, 'upload_file' => $path, 'file_name' => $fileName);
        self::saveDocument($subcontrolId, $data);
        $mappedSubControls  = SubControlService::getMappedSubControls($subcontrolId);
        if ($mappedSubControls) {
            foreach ($mappedSubControls as $mappedSubControl) {
                if ($mappedSubControl['sub_control_id'] != $id) {
                    self::saveDocument($mappedSubControl['sub_control_id'], $data);
                }
            }
        }
        return true;
    }

    public function getFilePath($documentId) {
        return Document::select('file_path')->where('id', '=', $documentId)->first();
    }

    public function deleteDocument($documentId, $organizationId)
    {
        $file = null;
        $document = Document::where('id', $documentId)->where('organization_id', $organizationId)->first();
        if (!$document){
            return false;
        }
        $subcontrol = SubControl::find($document->sub_control_id);
        $file = $document->file_path;

        $mappedSubControls = SubControlService::getMappedSubControls($document->sub_control_id);
        $subcontrolIds = [];
        if ($mappedSubControls) {
            foreach ($mappedSubControls as $sub) {
                if ($sub['sub_control_id'] != $document->sub_control_id) {
                    array_push($subcontrolIds, $sub['sub_control_id']);
                }
            }
        }

        $otherDocuments =  Document::where('file_path', $file)
                                    ->whereIn('sub_control_id', $subcontrolIds);

        $otherDocumentsList = $otherDocuments->get();

        if(!empty($subcontrol)) {
            $app = App::find($subcontrol->app_id);
            if (!empty($app)) {
                $program = Program::where(array('id' => $app->program_id, 'organization_id' => $organizationId))->first();
                if (!empty($program)) {
                    $document->delete();
                    if ($otherDocumentsList->isEmpty()) {
                        if ($file) {
                            Storage::disk('s3_storage')->delete($file);
                            $exists = Storage::disk('s3_storage')->has($file);
                            if (!$exists) {
                                return true;
                            }
                        }
                    } else {
                        $otherDocuments->delete();
                        return true;
                    }
                }
            }
        }

        return false;
    }

    public function updateFileName($id, $input) {
        $document = Document::find($id);

        if($document->type == 'document') {
            $name = $document->title;
            $extension = pathinfo($name, PATHINFO_EXTENSION);
            $input['title'] = $input['title'] . '.' . $extension;
        }

        if($document->type == 'link') {
            $resetDoc = false;

            if(isset($input['link']) && !is_null($input['link'])) {
                if($input['link'] !== $document['link']) {
                    $resetDoc = true;
                }
            }

            if($input['title'] !== $document['title']) {
                $resetDoc = true;
            }

            if($resetDoc) {
                $document->delete();
                $document = new Document;
                $document->type            = 'link';
                $document->link            = $input['link'];
                $document->organization_id = $input['org_id'];
            }
        }
        $document->title = $input['title'];
        $document->save();

        $updateDocSubcontrol = DocumentSubcontrol::where('document_id', $id)->update(['document_id' => $document->id]);

        $updateDocTask = DocumentTask::where('document_id', $id)->update(['document_id' => $document->id]);

        return true;
    }

    public function getFiles($id, $filePath) {
        $files = Document::where('file_path', $filePath)->get();
        $documentIds = [];
        if (!$files->isEmpty()) {
            foreach ($files as $f) {
                if ($f->id != $id) {
                    array_push($documentIds, $f->id);
                }
            }
        }
        return $documentIds;
    }

    public function getDocumentData($documentId, $organizationId) {

        $document = Document::where('id', $documentId)->where('organization_id', $organizationId)->first();

        if ($document){
            $document->append('download_url');
        }
      
       
        return $document;
    }

    public function getPlatformDocument($hash)
    {
        $document = PlatformDocument::where('hash', $hash)->first();
        if ($document) {
            $this->captureLog('platform-document', $document);
        }

        return $document;
    }

    public function savePlatformDocumentUpload($file)
    {
        $uuid       = Uuid::uuid1()->toString();
        $uploadPath = 'seeds/platform_documents/';
        $contents   = Storage::disk('local_database')->get($uploadPath.$file);
        $s3         = Storage::disk('s3_platform_document');
        $path       = $s3->put($file, $contents, 'private');
        $url        = $file;

        return [
            'path' => $url,
            'url'  => $s3->url($url),
            'hash' => $uuid
        ];
    }

    public function savePlatformDocument($data)
    {
        $document = PlatformDocument::create([
            'hash' => $data['hash'],
            'name' => $data['name'],
            'url'  => $data['path']
        ]);

        return $document;
    }

    public function getAllDocuments($orgId, $filterBy = [], $sort = null, $includeSubcontrols = false)
    {
        $documents = [];

        if(empty($filterBy)) {
            return $documents;
        }

        if ($includeSubcontrols){
            $documents = Document::with('subcontrolDocuments.subcontrol')
            ->where('organization_id', $orgId)
            ->where('is_display', 1)
            ->get();
        }else{
            $documents = Document::where('organization_id', $orgId)
            ->where('is_display', 1)
            ->get();
        }
       


        if($documents->isNotEmpty()  && $includeSubcontrols) {

            $documents = $documents->each(function($value, $key) {
                $value->append('download_url');
                
                if($value->subcontrolDocuments->isNotEmpty()){
                    $subcontrol = $value->subcontrolDocuments;
                    $value->subcontrolDocuments->each(function($v, $k) use($subcontrol) {
                        if(is_null($v->subcontrol)) {
                            unset($subcontrol[$k]);
                        } else {
                            $subcontrol[$k] = $this->mapDocumentSubcontrol($v->subcontrol->id);
                        }
                        return $subcontrol;
                    });
                    $value->subcontrols = $subcontrol->values();
                } else {
                    $value->subcontrols = collect([]);
                }
            });
        }

        if(!empty($filterBy)) {
            if(count($filterBy) != 2){
                if(in_array('link', $filterBy)) {
                    $documents = $documents->where('type', 'link')->values();
                } else if(in_array('document', $filterBy)) {
                    $documents = $documents->where('type', 'document')->values();
                }
            }
        }

        if(!is_null($sort)) {
            if($sort == 'alpha'){
                $documents = $documents->sortBy(function ($document, $key) {
                                return strtolower($document->title);
                            })->values();
            } else if($sort == 'desc') {
                $documents = $documents->sortByDesc('id')->values();
            } else if($sort == 'asc') {
                $documents = $documents->sortBy('id')->values();
            } else {
                $documents = $documents->sortByDesc('id')->values();
            }
        } else {
            $documents = $documents->sortByDesc('id')->values();
        }

        return $documents->makeVisible(['created_at']);
    }

    public function saveDocumentLink($document)
    {
        $docLink = SubcontrolDocumentLink::updateOrCreate(['id' => $document['id']], $document);
        return true;
    }

    public function getDocumentLinkIds($name, $type)
    {
        if($type == 'link') {
            return SubcontrolDocumentLink::where(['document_label' => $name])->pluck('id');
        }

        return Document::where(['title' => $name])->pluck('id');
    }

    public function deleteDocumentLink($id, $orgId)
    {
        $document = Document::where('id', $id)->where('organization_id', $orgId)->first();
        if (!$document){
            return false;
        }
        
        DocumentSubcontrol::where('document_id', $id)->delete();
        DocumentTask::where('document_id', $id)->delete();
        $file = $document->file_path;
        $type = $document->type;
        $document->delete();

        if ($type != 'link' && $file) {
       
            Storage::disk('s3_storage')->delete($file);
            $exists = Storage::disk('s3_storage')->has($file);
       
            if (!$exists) {
                return true;
            }
        }
        return true;
    }

    public function assignDocument($subcontrolId, $input)
    {
        if(!is_array($input['document_id'])) {
            $input['document_id'] = (array)$input['document_id'];
        }

        $documentSubcontrol = [];

        foreach ($input['document_id'] as $key => $value) {
            $documentSubcontrol[$key]['document_id']    = $value;
            $documentSubcontrol[$key]['sub_control_id'] = $subcontrolId;
            $documentSubcontrol[$key]['user_id']        = $input['user_id'];
            $documentSubcontrol[$key]['created_at']     = Carbon::now();
            $documentSubcontrol[$key]['updated_at']     = Carbon::now();
        }

        if (isset($input['old_selected']) && !empty($input['old_selected'])) {

            foreach ($input['old_selected'] as $key => $value) {

                DocumentSubcontrol::where([
                    'user_id' => $input['user_id'],
                    'document_id' => $value,
                    'sub_control_id' => $subcontrolId
                ])->delete();
            }
        }

        // DocumentSubcontrol::where([
        //     'user_id' => $input['user_id'],
        //     'sub_control_id' => $subcontrolId
        // ])->delete();

        DocumentSubcontrol::insert($documentSubcontrol);

        return true;
    }

    public function getAssignDocument($subcontrolId, $userId, $orgId)
    {
        return \DB::table('document_sub_controls as ds')
                ->select('d.*', 'ds.sub_control_id AS sub_control_id')
                ->join('document as d', 'd.id', '=', 'ds.document_id')
                ->join('sub_control as s', 's.id', '=', 'ds.sub_control_id')
                ->where('ds.sub_control_id', $subcontrolId)
                ->where('d.organization_id', $orgId)
                ->where('d.is_display', 1)
                ->get();
    }

    public function mapDocumentSubcontrol($subcontrolId)
    {
        $subcontrol              = SubControl::find($subcontrolId);
        $app                     = App::find($subcontrol->app_id);
        $program                 = Program::find($app->program_id);


        if ($program){
            $programFramework        = $program->framework;
        }


        if ($app){
            $subcontrol->app         = $app->name;
        }

        if ($program && $programFramework){
            $subcontrol->framework   = $programFramework->name;
        }


        return $subcontrol;
    }

    public function deleteSubControlDocument($docId, $orgId, $subcontrolId)
    {
        $subcontrol = DocumentSubcontrol::where(['document_id' => $docId, 'sub_control_id' => $subcontrolId])->with(['fromUser'  => function($q) use($orgId) {
                $q->where('organization_id', '=', $orgId);
            }])->delete();
        return true;
    }

    public function sendFrameworkDocument($data)
    {
        $currentUser   = AuthService::getCurrentUser();
        $data['username'] = $currentUser->first_name.' '.$currentUser->last_name;
        $data['email'] = $currentUser->email;
        $data['app'] = App::where('id', $data['appId'])->value('name');
        $data['subcontrol'] = SubControl::where('id', $data['subcontrol'])->value('title');

        $this->captureLog('platform-document-request', $data);

        Mail::to('hesom.parhizkar@apptega.com')->queue(new FrameworkDocumentEmail($data));
        return true;
    }

    public function unlinkDocumentSubcontrol($docId, $subcontrolId)
    {
        $subcontrol = DocumentSubcontrol::where(['document_id' => $docId, 'sub_control_id'=> $subcontrolId])->delete();
        return true;
    }

    public function updateDocument($input, $orgId)
    {
        $document = Document::find($input['id']);
        $document->title = $input['title'];

        if($document->type == 'document') {
            $name = $document->title;
            $extension = pathinfo($name, PATHINFO_EXTENSION);
            $document->title = $input['title'] . '.' . $extension;
        }

        if(isset($input['file']) && !empty($input['file'])) {
            $uuid            = Uuid::uuid1()->toString();
            $file            = $input['file'];
            $fileName        = $uuid . "." . $file->getClientOriginalExtension();
            $path            = Storage::disk('s3_storage')->putFileAs('documents', $file, $fileName);
            $document->delete();

            $document = new Document;
            $document->title           = $input['title'] .'.'. $file->getClientOriginalExtension();
            $document->file_path       = $path;
            $document->type            = 'document';
            $document->organization_id = $orgId;
            $document->hash            = (!empty($document->full_url)) ? hash_file('md5', $document->full_url) : NULL;
        }

        $document->save();

        $updateDocSubcontrol = DocumentSubcontrol::where('document_id', $input['id'])->update(['document_id' => $document->id]);

        $updateDocTask = DocumentTask::where('document_id', $input['id'])->update(['document_id' => $document->id]);
    }

    public function checkDocumentPermission($data){
        $user  = AuthService::getCurrentUser();

        if ($user->partner_id != "") {
            $orgId = AuthService::getCurrentOrganizationId();
            $permissions = AuthService::getCurrentUserPermissions($user->id, $orgId);
        } else {
            $permissions = AuthService::getCurrentUserPermissions($user->id, null);
        }

        $results = [];

        if((in_array('DOCUMENT_LINK_VIEW_ONLY', $permissions)) || (in_array('DOCUMENT_LINK_UPDATE', $permissions))){
            foreach($data as $value){
                if($value->type == 'link'){
                    $results[] = $value;
                }
            }
        }

        if((in_array('DOCUMENTS_VIEW_ONLY', $permissions)) || (in_array('DOCUMENTS_UPDATE', $permissions))){
            foreach($data as $value){
                if($value->type == 'document'){
                    $results[] = $value;
                }
            }
        }

        return $results;
    }
}
