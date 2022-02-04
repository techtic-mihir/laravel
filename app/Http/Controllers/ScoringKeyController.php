<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Request;
use Facades\ {
    App\Data\Services\ScoringKeyService,
    App\Data\Services\AuthService
};
use Illuminate\Http\Request as HttpRequest;

class ScoringKeyController extends ApiBaseController {

    public function __construct()
    {
    }

    public function getScoringKeys(HttpRequest $request)
    {
        try {
            $checkOrgPartner = $request->query('org_partner');
            if(isset($checkOrgPartner)) {
                $data = ScoringKeyService::getScoringKeyByOrg();
            } else {
                $data = ScoringKeyService::getScoringKey();
            }
            return $this->response->array([
                'success' => true,
                'data' => $data
            ]);

        } catch (Exception $e) {
            return $this->response->array([
                'success' => false,
                'message' => $e->getMessage(),
                'data' => []
            ]);
        }
    }

    public function deleteScoringKeById($id)
    {
        try {
            $data = ScoringKeyService::deleteScoringKey($id);
            return $this->response->array([
                'success' => true,
                'data' => $data
            ]);

        } catch (Exception $e) {
            return $this->response->array([
                'success' => false,
                'message' => $e->getMessage(),
                'data' => []
            ]);
        }
    }

    public function saveScoringKey()
    {
        try {
            $request = Request::json()->all();
            $data = ScoringKeyService::saveScoringKey($request);

            return $this->response->array([
                'success' => true,
                'data' => $data
            ]);
        } catch (Exception $e) {
            return $this->response->array([
                'success' => false,
                'message' => $e->getMessage(),
                'data' => []
            ]);
        }
    }

    public function updateScoringOrders()
    {
        try {
            $request = Request::json()->all();
            $data = ScoringKeyService::updateScoringOrders($request);

            return $this->response->array([
                'success' => true,
                'data' => $data
            ]);
        } catch (Exception $e) {
            return $this->response->array([
                'success' => false,
                'message' => $e->getMessage(),
                'data' => []
            ]);
        }
    }

    public function getDefaultScoringKeys($title = null)
    {
        try {
            $data = ScoringKeyService::getDefaultScoringKeys($title);
            return $this->response->array([
                'success' => true,
                'data' => $data
            ]);
        } catch (Exception $e) {
            return $this->response->array([
                'success' => false,
                'message' => $e->getMessage(),
                'data' => []
            ]);
        }
    }
}
