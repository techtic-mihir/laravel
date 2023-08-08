<?php

namespace App\Api\V1\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Repositories\CRM\HubspotRepository;
use Response;
use Auth;

class HubspotController extends Controller
{
    /**
    * Display a listing of the resource.
    *
    * @return \Illuminate\Http\Response
    */
    public function __construct(
        HubspotRepository $hubspotRepository
    ){
        $this->hubspotRepository = $hubspotRepository;
    }

    public function salesWebhook(Request $request)
    {
        $inputs   = $request->all();
        $response = $this->hubspotRepository->salesHubspot($inputs);
        return Response::json([
            'code' => 200,
            'response' => $response
        ]);
    }

    /**
    * Hubspot webhook for create or update contact details.
    * @param  Request $request [array]
    * @return [object]
    */
    public function hubspotProspect(Request $request)
    {
        $inputs   = $request->all();
        $response = $this->hubspotRepository->createHubspotProspect($inputs);
        return response("<Response/>")->header('Content-Type', 'text/xml');
    }


    /**
    * create or update call task for hubspot prospect.
    * @param  Request $request [array]
    * @return [object]
    */
    public function callTask(Request $request)
    {
        $inputs   = $request->all();
        $response = $this->hubspotRepository->createCallTask($inputs, Auth::id());

        if(!$response){
            return response("<Response>Household not found!</Response>")
                    ->header('Content-Type', 'text/xml');
        }

        return response("<Response/>")->header('Content-Type', 'text/xml');
    }

    public function makeIntegration(Request $request)
    {
        $inputs   = $request->all();
        $response = $this->hubspotRepository->makeIntegration($inputs, Auth::id());

        return Response::json([
            'code'     => 200,
            'response' => $response
        ]);
    }
}
