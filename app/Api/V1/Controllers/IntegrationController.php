<?php

namespace App\Api\V1\Controllers;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Response;
use Redirect;

use App\Repositories\Integration\IntegrationRepository;


class IntegrationController extends Controller
{
    public function __construct(
    	IntegrationRepository $integrationRepository
    ) {
    	$this->integrationRepository = $integrationRepository;
    }

 	/**
    * Display a listing of the resource.
    *
    * @return \Illuminate\Http\Response
    */
    public function index()
    {
        try {
            $response = $this->integrationRepository->getIntegrationsList(\Auth::id());
			return response()->json([
 				'code'   => 200,
                'response' => $response,
            ]);

        }catch (Exception $e) {
            return response()->json([
 				'code'   => 500,
                'response' => $e->getMessage()
            ]);
        }

    }

	/**
	*
	* store integration for diffrent type
	* @return \Illuminate\Http\Response
	*/
	public function createOrUpdateIntegration(Request $request)
    {
        try {
            $inputs = $request->all();
            $response = $this->integrationRepository->createOrUpdateIntegration($inputs, \Auth::id());
            return response()->json([
 				'code'   => 200,
                'response' => $response,
            ]);
        } catch (Exception $e) {
           	return response()->json([
 				'code'   => 500,
                'response' => $e->getMessage()
            ]);
        }

    }

	/**
	*
	* get integration settings for pre-populate integrations fields
	* @return \Illuminate\Http\Response
	*/
    public function getSettings($name)
    {
        try {
            $response = $this->integrationRepository->getIntegrationSettings($name);
            return response()->json([
 				'code'   => 200,
                'response' => $response,
            ]);
        } catch (Exception $e) {
			return response()->json([
 				'code'   => 500,
                'response' => $e->getMessage()
            ]);
        }

    }

	/**
    * delete integrations
    * @param $id [integration primary id]
    * @return \Illuminate\Http\Response
    */
	public function destroy($id)
    {
        $return = $this->integrationRepository->deleteIntegration($id);
        return response()->json(['response' => $return], 200);
    }

    /**
    * active integrations with google and outlook types
    *
    * @return json
    */
    public function activeCalendarIntegrations ()
    {
        try {
            $response = $this->integrationRepository->activeCalendarIntegration();
            return response()->success($response);

        } catch (Exception $e) {
            return response()->error($e->getMessage());
        }
    }
}
