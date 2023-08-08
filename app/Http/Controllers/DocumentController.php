<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Request;
use File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\Response;
use Illuminate\Http\Request as Requests;
use App\Data\Entities\Document;
use App\Data\Entities\DocumentTask;

use Facades\ {
    App\Data\Services\DocumentService,
    App\Data\Services\AuthService,
    App\Data\Services\SubControlService,
    App\Data\Services\OrganizationService,
    App\Data\Services\FrameworkService
};

use App\Traits\LogsActivity;

class DocumentController extends ApiBaseController {

    use LogsActivity;

    public function __construct() {

    }

    public function uploadDocument($id, Requests $request) {
        $orgId = AuthService::getCurrentOrganizationId();
        $data = DocumentService::uploadDocument($request);
        $data['organization_id'] = $orgId;
        $data['type'] = 'document';
        $file_path = Storage::disk('s3_storage')->get($data['file_name']);
        DocumentService::saveDocument($id, $data);
        $mappedSubControls  = SubControlService::getMappedSubControls($id);
        if ($mappedSubControls) {
            foreach ($mappedSubControls as $mappedSubControl) {
                if ($mappedSubControl['sub_control_id'] != $id) {
                    DocumentService::saveDocument($mappedSubControl['sub_control_id'], $data);
                }
            }
        }
        return $this->response->array(['success' => true]);
    }

    public function downloadFile($documentId) {
    
        try {
            $orgId = AuthService::getCurrentOrganizationId();
            
            $logData = [
                'document_id' => $documentId
            ];

            $this->captureLog('document-download', $logData);

            $data = DocumentService::getDocumentData($documentId, $orgId);

            return $this->response->array([
                'success' => true,
                'data'    => $data
            ]);

        } catch (Illuminate\Filesystem\FileNotFoundException $exception) {
            return $this->response->array(['success' => false, 'data' => $exception]);
        }

        //$type = Storage::disk('s3_storage')->mimeType($file->file_path);
        //return (new Response($contents, 200))->header('Content-Type', $type);
    }

    public function deleteDocument($documentId) {
        $orgId = AuthService::getCurrentOrganizationId();
      
        $success = DocumentService::deleteDocument($documentId, $orgId);
        return $this->response->array(['success' => $success]);
    }

    public function updateFileName($id) {
        $input           = Request::json()->all();
        $orgId           = AuthService::getCurrentOrganizationId();
        $input['org_id'] = $orgId;
        $success         = DocumentService::updateFileName($id, $input);
        if ($success) {
            return $this->response->array(['success' => true]);
        } else {
            return $this->response->array(['success' => false]);
        }
    }

    public function getPlatformDocument($hash = null)
    {
        $org_id = AuthService::getCurrentOrganizationId();
        $access = OrganizationService::checkOrgSku('Documents', $org_id);

        if (!$access){
            return $this->response->array(['success' => false]);
        }

        $document = DocumentService::getPlatformDocument($hash);
        if($document) {
            return $this->response->array(['success' => true, 'data' => $document]);
        } else {
            return $this->response->array(['success' => false]);
        }
    }

    public function savePlatformDocument(Requests $request)
    {
        try {

            $upload = DocumentService::savePlatformDocumentUpload($request);
            $data = array_replace([], $upload, $request->only(['name']));
            $response = DocumentService::savePlatformDocument($data);

            return $this->response->array([
                'success' => true,
                'data' => $response
            ]);

        } catch (Exception $e) {
            return $this->response->array([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function getAllDocuments()
    {
        $orgId    = AuthService::getCurrentOrganizationId();
        $response = DocumentService::getAllDocuments($orgId);

        return $this->response->array([
            'success' => true,
            'data' => $response
        ]);
    }

    public function saveDocumentLink()
    {
        try {

            $input  = Request::json()->all();
            $orgId  = AuthService::getCurrentOrganizationId();
            $userId = AuthService::getCurrentUser()->id;

            $input['organization_id'] = $orgId;
            $input['file_name']       = $input['title'];
            $input['type']            = 'link';
            $input['is_display']      = filter_var($input['is_display'], FILTER_VALIDATE_BOOLEAN);

            $document = DocumentService::saveDocument(null, $input);

            if(isset($input['subcontrol_id']) && !is_null($input['subcontrol_id']) && $input['subcontrol_id'] != '') {

                $data = [];
                $data['document_id'] = $document->id;
                $data['user_id']     = $userId;

                DocumentService::assignDocument($input['subcontrol_id'], $data);

                $currentFramework = FrameworkService::getCurrentFramework();

                if($currentFramework->is_harmony == 1) {
                    //save documents for maped subcontrols
                    $mappedSubControls  = SubControlService::getMappedSubControls($input['subcontrol_id']);

                    if ($mappedSubControls) {
                        $input['document_id'] = $document->id;
                        foreach ($mappedSubControls as $mappedSubControl) {
                            if ($mappedSubControl['sub_control_id'] != $input['subcontrol_id']) {
                                DocumentService::assignDocument($mappedSubControl['sub_control_id'], $data);
                            }
                        }
                    }
                }
            }

            return $this->response->array(['success' => true, 'data' => $document]);

        } catch (Exception $e) {
            return $this->response->array([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function deleteDocumentLink($documentId) {
        try {
            $orgId  = AuthService::getCurrentOrganizationId();
            DocumentService::deleteDocumentLink($documentId, $orgId);
            return $this->response->array([
                'success' => true,
                'data' => true
            ]);

        } catch (Exception $e) {
            return $this->response->array([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function uploadDocumentLink(Requests $request) {
        $input                   = $request->all();
        $orgId                   = AuthService::getCurrentOrganizationId();
        $userId                  = AuthService::getCurrentUser()->id;
        $data                    = DocumentService::uploadDocument($request);
        $data['organization_id'] = $orgId;
        $data['type']            = 'document';
        $data['is_display']      = filter_var($input['is_display'], FILTER_VALIDATE_BOOLEAN);

        $document = DocumentService::saveDocument(null, $data);
        $document->append('download_url');

        if(isset($input['subcontrol_id']) && !is_null($input['subcontrol_id']) && $input['subcontrol_id'] != '') {

            $data = [];
            $data['document_id'] = $document->id;
            $data['user_id']     = $userId;

            DocumentService::assignDocument($input['subcontrol_id'], $data);

            $currentFramework = FrameworkService::getCurrentFramework();

            if($currentFramework->is_harmony == 1) {
                //save documents for maped subcontrols
                $mappedSubControls  = SubControlService::getMappedSubControls($input['subcontrol_id']);

                if ($mappedSubControls) {

                    foreach ($mappedSubControls as $mappedSubControl) {
                        if ($mappedSubControl['sub_control_id'] != $input['subcontrol_id']) {
                            DocumentService::assignDocument($mappedSubControl['sub_control_id'], $data);
                        }
                    }
                }
            }
        }

        return $this->response->array(['success' => true, 'data' => $document]);
    }

    public function getAssignedDocument($subcontrolId) {
        $user  = AuthService::getCurrentUser();
        $orgId = AuthService::getCurrentOrganizationId();
        $data  = DocumentService::getAssignDocument($subcontrolId, $user->id, $orgId);

        $response = DocumentService::checkDocumentPermission($data);

        return $this->response->array([
            'success' => true,
            'data'    => $response
        ]);
    }

    public function assignDocument($subcontrolId) {
        try {
            $input            = Request::json()->all();
            $user             = AuthService::getCurrentUser();
            $input['user_id'] = $user->id;

            DocumentService::assignDocument($subcontrolId, $input);

            $currentFramework = FrameworkService::getCurrentFramework();

            if($currentFramework->is_harmony == 1) {
                //save documents for maped subcontrols
                $mappedSubControls  = SubControlService::getMappedSubControls($subcontrolId);

                if ($mappedSubControls) {
                    foreach ($mappedSubControls as $mappedSubControl) {
                        if ($mappedSubControl['sub_control_id'] != $subcontrolId) {
                            DocumentService::assignDocument($mappedSubControl['sub_control_id'], $input);
                        }
                    }
                }
            }

            return $this->response->array([
                'success' => true,
                'data'    => true
            ]);

        } catch (Exception $e) {
            return $this->response->array([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function sendFrameworkDocument(Requests $request)
    {
        try {
            $response = DocumentService::sendFrameworkDocument($request->all());

            return $this->response->array([
                'success' => true
            ]);
        } catch (Exception $e) {
            return $this->response->array([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }


    public function deleteSubControlDocument($subcontrolId)
    {
        $input = Request::json()->all();
        $org_id = AuthService::getCurrentOrganizationId();
        DocumentService::deleteSubControlDocument($input, $org_id, $subcontrolId);

        $currentFramework = FrameworkService::getCurrentFramework();
        if($currentFramework->is_harmony == 1) {
            //delete documents for maped subcontrols
            $mappedSubControls  = SubControlService::getMappedSubControls($subcontrolId);

            if ($mappedSubControls) {
                foreach ($mappedSubControls as $mappedSubControl) {
                    if ($mappedSubControl['sub_control_id'] != $subcontrolId) {
                        DocumentService::deleteSubControlDocument($input, $org_id, $mappedSubControl['sub_control_id']);
                    }
                }
            }
        }

        return $this->response->array(['success' => true]);
    }

    public function unlinkDocumentSubcontrol($documentId)
    {
        $input = Request::json()->all();
        DocumentService::unlinkDocumentSubcontrol($documentId, $input);
        return $this->response->array(['success' => true]);
    }

    public function getDocumentByFilters()
    {
        $input  = Request::json()->all();
        $filter = isset($input['filter']) ? $input['filter'] : null;
        $sort   = isset($input['sort']) ? $input['sort'] : null;
        $includeSubcontrols = isset($input['includeSubcontrols']) ? $input['includeSubcontrols'] : false;

        $orgId    = AuthService::getCurrentOrganizationId();
        $response = DocumentService::getAllDocuments($orgId, $filter, $sort, $includeSubcontrols);

        if(!isset($input['is_task_call'])){
            $data = DocumentService::checkDocumentPermission($response);
        } else {
            $data = $response;
        }

        return $this->response->array([
            'success' => true,
            'data'    => $data
        ]);
    }

    public function updateDocument(Requests $request)
    {
        $input  = $request->all();
        $org_id = AuthService::getCurrentOrganizationId();
        $data   = DocumentService::updateDocument($input, $org_id);
        return $this->response->array(['success' => true]);
    }

    public function assignTaskDocument($taskId) {
        try {
            $input = Request::json()->all();
            $user  = AuthService::getCurrentUser();
            $input['user_id'] = $user->id;
            $input['task_id'] = $taskId;

            $assignDocument = DocumentService::assignTaskDocument($input);

            return $this->response->array([
                'success' => true,
                'data'    => true
            ]);
        } catch (Exception $e) {
            return $this->response->array([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }
}
