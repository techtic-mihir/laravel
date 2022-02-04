<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Validator;
use Dingo\Api\Exception\ValidationHttpException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\Response;
use File;
use Facades\ {
    App\Data\Services\SubControlService,
    App\Data\Services\ProgramService,
    App\Data\Services\AuthService,
    App\Data\Services\AppService
};
use Illuminate\Http\Request as InputRequest;

class SubControlController extends ApiBaseController {

    public function __construct() {

    }

    public function getSubControlDetails($appId, $subcontrolId, InputRequest $request) {
        //check whether user has the access to get the app
        $user = AuthService::getCurrentUser();
        $response = AppService::doesAppBelongToCurrentOrganization($appId);
        if (!$response) {
            return $this->response->error('Unauthorized', 403);
        }
        $searchable   = filter_var($request->query('searchable'), FILTER_VALIDATE_BOOLEAN);
        $searchString = $request->query('sString');
        $data = SubControlService::getSubControlDetails($appId, $subcontrolId, 0, $searchable, $searchString);
        if (!empty($data)) {
            return $this->response->array(['data' => $data]);
        } else {
            return $this->response->array(['success' => false]);
        }
    }

    public function saveSubControl($id = null) {
        $input                = Request::json()->all();
        $user                 = AuthService::getCurrentUser();
        $input['assigned_by'] = $user->id;
        $success              = SubControlService::saveSubControl($id, $input);
        if ($success) {
            return $this->response->array(['success' => true]);
        } else {
            return $this->response->array(['success' => false]);
        }
    }

    public function deleteSubControl($id) {
        $response = SubControlService::doesSubcontrolBelongToCurrentOrganization($id);
        if (!$response) {
            return $this->response->error('Unauthorized', 403);
        }

        $orgId = AuthService::getCurrentOrganizationId();
        $success = SubControlService::deleteSubControl($id, $orgId);
        return $this->response->array(['success' => $success]);
    }

    public function deleteSubControlTemplate($id) {
        $orgId = AuthService::getCurrentOrganizationId();
        $success = SubControlService::deleteSubControlTemplate($id, $orgId);
        return $this->response->array(['success' => true]);
    }

    public function getSubControls() {
        $orgId = AuthService::getCurrentOrganizationId();
        $data = SubControlService::getAllSubcontrols($orgId);
        return $this->response->array(['data' => $data]);
    }
    public function getAllSubcontrolUsers() {
        $programId = ProgramService::getCurrentUserProgramId();
        if (!empty($programId)) {
            $data = SubControlService::getAllSubcontrolUsers($programId);
            if (!empty($data)) {
                return $this->response->array(['data' => $data]);
            } else {
                return $this->response->array(['success' => false]);
            }
        } else {
            return $this->response->array(['success' => false]);
        }
    }

    public function getAllSubcontrolRiskUsers() {
        $programId = ProgramService::getCurrentUserProgramId();
        if (!empty($programId)) {
            $data = SubControlService::getAllSubcontrolRiskUsers($programId);
            if (!empty($data)) {
                return $this->response->array(['data' => $data]);
            } else {
                return $this->response->array(['success' => false]);
            }
        } else {
            return $this->response->array(['success' => false]);
        }
    }

    public function saveSubControlProfile($subcontrolId, $profileId = null) {
        $input = Request::json()->all();
        SubControlService::saveSubControlProfile($subcontrolId, $profileId, $input);
        return $this->response->array(['success' => true]);
    }


    public function deleteSubControlProfile($profileId) {
        $input = Request::json()->all();
        $success = SubControlService::deleteSubControlProfile($profileId);
        if ($success) {
            return $this->response->array(['success' => true]);
        } else {
            return $this->response->array(['success' => false]);
        }
    }

    public function saveSubControlRiskRating($subcontrolId, $riskId = null) {
        $input = Request::json()->all();
        $data = SubControlService::saveSubControlriskRating($subcontrolId, $riskId, $input);
        return $this->response->array(['success' => true, 'data' => $data]);
    }

    /**
    * Delete subcontrol risk rating
    */
    public function deleteSubControlRiskRating($riskId)
    {
        $response = SubControlService::deleteSubControlRiskRating($riskId);
        return $this->response->array(['success' => $response]);
    }

    public function getSubcontrolsbyUser($userId) {
        $data = SubControlService::getSubcontrolsbyUser($userId);
        if ($data) {
            return $this->response->array(['data' => $data]);
        } else {
            return $this->response->array(['success' => false]);
        }
    }
    public function getMySubcontrols($id = null) {
        if ($id == null) {
            $userId = null;
            $programId = ProgramService::getCurrentUserProgramId();
        } else {
            $userId = $id;
        }
        $programId = ProgramService::getCurrentUserProgramId();
        if (!empty($programId)) {
            $data = SubControlService::getMySubcontrols($programId, $userId);
            if (!empty($data)) {
                return $this->response->array(['data' => $data]);
            } else {
                return $this->response->array(['success' => false]);
            }
        } else {
            return $this->response->array(['success' => false]);
        }
    }

    public function getMyRiskRatings($id = null) {
        if ($id == null) {
            $userId = null;
            $programId = ProgramService::getCurrentUserProgramId();
        } else {
            $userId = $id;
        }
        $programId = ProgramService::getCurrentUserProgramId();
        if (!empty($programId)) {
            $data = SubControlService::getMyRiskRatings($programId, $userId);
            if (!empty($data)) {
                return $this->response->array(['data' => $data]);
            } else {
                return $this->response->array(['success' => false]);
            }
        } else {
            return $this->response->array(['success' => false]);
        }
    }

    public function downloadTools($filename){
        $path = "app_tools/".$filename;
        try {
            $contents = Storage::disk('s3_storage')->get($path);
        } catch (Illuminate\Filesystem\FileNotFoundException $exception) {
            return $this->response->array(['success' => false, 'data' => $exception]);
        }
        $type = Storage::disk('s3_storage')->mimeType($path);
        $response = new Response($contents, 200);
        $response->header('Content-Type', $type);
        $response->header('Content-Disposition', 'attachment; filename="'.$filename.'"');
        return $response;
        // return (new Response($contents, 200))->header(['Content-Type'=> $type,'Content-Disposition: attachment; ']);

    }

    public function getSubcontrolComments($subcontrolId) {
        $data =  SubControlService::getAllComments($subcontrolId);
        return $this->response->array(['success'=> true, 'data' => $data]);
    }

    public function saveComments() {
        $input            = Request::json()->all();
        $user             = AuthService::getCurrentUser();
        $input['user_id'] = $user->id;

        $success = SubControlService::saveSubControlComment($input);
        if ($success) {
            return $this->response->array(['success' => true]);
        } else {
            return $this->response->array(['success' => false]);
        }
    }

    public function deletComments($id) {
        $user    = AuthService::getCurrentUser();
        $success =  SubControlService::deleteComment($id, $user);
        if ($success) {
            return $this->response->array(['success' => true]);
        } else {
            return $this->response->array(['success' => false]);
        }
    }

    public function getFilterSubControls() {
        $input = Request::json()->all();
        $data  = SubControlService::getFilterSubControls($input);
        return $this->response->array(['success' => true, 'data' => $data]);
    }
}
