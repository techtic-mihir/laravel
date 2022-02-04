<?php
namespace App\Http\Controllers;

use Config;
use Request;
use Illuminate\Http\Request as Requests;

use Illuminate\Support\Facades\Input;
use Facades \ {
    App\Data\Services\MarketplaceService,
    App\Data\Services\AuthService
};

class MarketplaceController extends ApiBaseController
{
    public function __construct()
    {

    }

    public function getMarketplaceResults(Requests $request)
    {
        try {
            $filters = Request::json()->all();
            $data = MarketplaceService::getMarketplaceResults($filters);
            return $this->response->array([
                'success' => true,
                'data' => $data
            ]);
        } catch (Exception $e) {
            return $this->response->array([
                'success'=> false,
                'message' => $e->getMessage(),
                'data' => []
            ]);
        }
    }

    public function getMarketplaceFilters(Requests $request)
    {
        try {
            $filters = Request::json()->all();
            $data = MarketplaceService::getMarketplaceFilters($filters);
            return $this->response->array([
                'success' => true,
                'data' => $data
            ]);
        } catch (Exception $e) {
            return $this->response->array([
                'success'=> false,
                'message' => $e->getMessage(),
                'data' => []
            ]);
        }
    }

    public function getCompanyChart()
    {
        try {
            $data = MarketplaceService::getCompanyChart();
            return $this->response->array([
                'success' => true,
                'data' => $data
            ]);
        } catch (Exception $e) {
            return $this->response->array([
                'success'=> false,
                'message' => $e->getMessage(),
                'data' => []
            ]);
        }
    }

    /***** Manage Solutions APIs start *****/
    public function getAllSolutions()
    {
        try {
            $data = MarketplaceService::getAllSolutions();
            return $this->response->array([
                'success' => true,
                'data' => $data
            ]);
        } catch (Exception $e) {
            return $this->response->array([
                'success'=> false,
                'message' => $e->getMessage(),
                'data' => []
            ]);
        }
    }

    public function getManageSolutions()
    {
        try {
            $data = MarketplaceService::getManageSolutions();
            return $this->response->array([
                'data' => $data,
                'success'=> true
            ]);
        } catch (Exception $e) {
            return $this->response->array([
                'success'=> false,
                'message' => $e->getMessage(),
                'data' => []
            ]);
        }
    }

    public function saveSolution()
    {
        try {
            $request = Request::json()->all();
            $userId  = AuthService::getCurrentUser()->id;
            $data    = MarketplaceService::saveSolution($request, $userId);

            if(!$data) {
                return $this->response->array(['success' => false]);
            }
            return $this->response->array([
                'success' => true,
                'data' => $data
            ]);
        } catch (Exception $e) {
            return $this->response->array([
                'success'=> false,
                'message' => $e->getMessage(),
                'data' => []
            ]);
        }
    }

    public function saveMultipleSolutions()
    {
        try {
            $request = Request::json()->all();
            $userId  = AuthService::getCurrentUser()->id;
            $data    = MarketplaceService::saveMultipleSolutions($request, $userId);
            return $this->response->array([
                'success' => true,
                'data' => $data
            ]);
        } catch (Exception $e) {
            return $this->response->array([
                'success' => false,
                'message' => $e->getMessage(),
                'data'    => []
            ]);
        }
    }

    public function removeSolutions()
    {
        try {
            $request = Request::json()->all();
            $userId = AuthService::getCurrentUser()->id;
            $data   = MarketplaceService::removeSolutions($request, $userId);
            return $this->response->array(['success' => true]);
        } catch (Exception $e) {
            return $this->response->array(['success'=> false,'message' => $e->getMessage()]);
        }
    }

    public function getUserSolutionById()
    {
        try {
            $input            = Request::json()->all();
            $input['user_id'] = AuthService::getCurrentUser()->id;
            $data             = MarketplaceService::getUserSolutionById($input);
            return $this->response->array(['success' => true, 'data' => $data]);
        } catch (Exception $e) {
            return $this->response->array(['success'=> false,'message' => $e->getMessage()]);
        }
    }

    /***** Manage Solutions APIs end *****/
    public function saveMarketPlaceVendor()
    {
        $request = Request::json()->all();
        $data = MarketplaceService::saveMarketPlaceVendor($request);
        return $this->response->array(['data' => $data]);
    }

    /***** calculate progress for framework *****/
    public function computeFuturecast()
    {
        try {
            $input            = Request::json()->all();
            $data             = MarketplaceService::computeFuturecast($input);
            return $this->response->array(['success' => true, 'data' => $data]);
        } catch (Exception $e) {
            return $this->response->array(['success'=> false,'message' => $e->getMessage()]);
        }
    }
}