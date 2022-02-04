<?php

namespace App\Api\V1\Controllers;

use App\Http\Controllers\Controller;
use App\Repositories\Households\HouseholdMembersRepository;
use App\Repositories\Households\HouseholdsRepository;
use Auth;
use Illuminate\Http\Request;
use Response;

class HouseholdMembersController extends Controller
{
    public function __construct(HouseholdMembersRepository $householdMembersRepository, HouseholdsRepository $householdRepository)
    {
        $this->householdMembersRepository = $householdMembersRepository;
        $this->householdRepository        = $householdRepository;
    }

    public function getMembersInfo($id)
    {
        $response = $this->householdMembersRepository->getMembersInfo($id);
        return response::json([
            'code'   => 200,
            'result' => $response,
        ]);
    }

    public function setHouseholdPrimaryMember($id, $member_id)
    {
        $response = $this->householdMembersRepository->setHouseholdPrimaryMember($id, $member_id);
        return response::json([
            'code'   => 200,
            'result' => $response,
        ]);
    }

    public function advisorSave($id, Request $request)
    {
        $data     = $request->all();
        $response = $this->householdMembersRepository->advisorSave($id, $data);
        return response()->json([
            'code'   => 200,
            'result' => $response,
        ]);
    }

    public function advisorGetAll()
    {
        $response = $this->householdRepository->advisorGetAll();
        return response::json($response);
    }

    public function advisorGet($id)
    {
        $response = $this->householdRepository->advisorGet($id);
        return response::json($response);
    }

    public function familySave($household_id, Request $request)
    {
        try {
            $data           = $request->all();
            $data['org_id'] = Auth::user()->organization_id;
            $returnData     = $this->householdMembersRepository->familySave($household_id, $data);

            $response       = [
                'code'   => 200,
                'result' => $returnData,
            ];
        } catch (Exception $e) {
            $response = [
                'code'    => 500,
                'message' => $e->getMessage(),
                'result'  => false,
            ];
        }
        return response()->json($response);
    }

    public function familyGet($id)
    {
        $response = $this->householdMembersRepository->familyGet($id);
        return response::json($response);
    }

    public function familyDelete($id, $member_id)
    {
        $response = $this->householdMembersRepository->familyDelete($member_id);
        return response::json($response);
    }

    public function memberInfoSave(Request $request)
    {
        $data     = $request->all();
        $response = $this->householdMembersRepository->memberInfoSave($data);
        return response()->json($response);
    }

    public function updateSource($household_id, Request $request)
    {
        $data     = $request->all();
        $response = $this->householdMembersRepository->updateSource($household_id, $data);
        return response()->json($response);
    }

    public function updateMeetingFrequency($household_id, Request $request)
    {
        $data     = $request->all();
        $response = $this->householdMembersRepository->updateMeetingFrequency($household_id, $data);
        return response()->json($response);
    }

    public function getSourceMeetingFrequency($id)
    {
        $response = $this->householdMembersRepository->getSourceMeetingFrequency($id);
        return response::json($response);
    }

    public function getSource($household_id)
    {
        $response = $this->householdMembersRepository->getSource($household_id);
        return response::json($response);
    }

    public function getMeetingFrequency()
    {
        $response = $this->householdMembersRepository->getMeetingFrequency();
        return response::json($response);
    }

    public function addMembers($household_id, Request $request)
    {
        $data           = $request->all();
        $data['org_id'] = Auth::user()->organization_id;
        $response       = $this->householdMembersRepository->addMembers($household_id, $data);
        return response()->json($response);
    }

    public function addBeneficiary($household_id, Request $request)
    {
        try {
            $data           = $request->all();
            $data['org_id'] = Auth::user()->organization_id;
            $response       = $this->householdMembersRepository->addBeneficiary($household_id, $data);
            $response       = [
                'code'     => 200,
                'response' => $response,
            ];
        } catch (Exception $e) {
            $response = [
                'code'     => 500,
                'response' => $e->getMessage(),
            ];
        }

        return response::json($response);
    }

    public function addHouseholdMembers(Request $request)
    {
        try {
            $data           = $request->all();
            $data['org_id'] = Auth::user()->organization_id;
            $member         = $this->householdMembersRepository->addHouseholdMembers($data);

            $response = [
                'code'   => 200,
                'result' => $member,
            ];
            return response()->json($response);

        } catch (Exception $e) {
            return [
                'code'    => 500,
                'message' => $e->getMessage(),
                'result'  => false,
            ];
        }
    }

    public function getHouseholdMemberType(){
        try {
            $member         = $this->householdMembersRepository->getHouseholdMemberType();
            $response = [
                'code'   => 200,
                'result' => $member,
            ];
            return response()->json($response);

        } catch (Exception $e) {
            return [
                'code'    => 500,
                'message' => $e->getMessage(),
                'result'  => false,
            ];
        }
    }

    public function getBorrowerInfo($id)
    {
        $response = $this->householdMembersRepository->getBorrowerInfo($id);
        return response::json([
            'code'   => 200,
            'response' => $response,
        ]);
    }

    public function updateMemberInfo(Request $request,$household_id,$member_id)
    {
        $response = $this->householdMembersRepository->updateMemberInfo($request->all(),$member_id);
        return response::json([
            'code'   => 200,
            'response' => $response,
        ]);
    }

    public function addTDBeneficiaryMember($household_id, Request $request){
        try {
            $data           = $request->all();
            $data['org_id'] = Auth::user()->organization_id;
            $member = $this->householdMembersRepository->addTDBeneficiaryMember($household_id, $data);
            $response = [
                'code'   => 200,
                'response' => $member,
            ];
            return response()->json($response);

        } catch (Exception $e) {
            return [
                'code'    => 500,
                'message' => $e->getMessage(),
                'response'  => false,
            ];
        }
    }

    public function salesSave($id, Request $request)
    {
        try {
            $data     = $request->all();
            $response = $this->householdMembersRepository->salesSave($id, $data);

            $response = [
                'code'   => 200,
                'response' => $response,
            ];
            return response()->json($response);

        } catch (Exception $e) {
            return [
                'code'    => 500,
                'message' => $e->getMessage(),
                'response'  => false,
            ];
        }
    }

    public function salesGet($id)
    {
        try {
            $response = $this->householdMembersRepository->salesGet($id);

            $response = [
                'code'   => 200,
                'response' => $response,
            ];
            return response()->json($response);

        } catch (Exception $e) {
            return [
                'code'    => 500,
                'message' => $e->getMessage(),
                'response'  => false,
            ];
        }
    }

    public function getTeamMember($id)
    {
        try {
            $data = $this->householdMembersRepository->getTeamMember($id);
            $response = [
                'code'   => 200,
                'response' => $data,
            ];
        } catch (Exception $e) {
            $response = [
                'code'    => 500,
                'message' => $e->getMessage(),
                'response'  => false,
            ];
        }
        return response::json($response);
    }

    public function getAllTeamMember($id)
    {
        try {
            $data = $this->householdMembersRepository->getTeamMember($id, false);
            $response = [
                'code'   => 200,
                'response' => $data,
            ];
        } catch (Exception $e) {
            $response = [
                'code'    => 500,
                'message' => $e->getMessage(),
                'response'  => false,
            ];
        }
        return response::json($response);
    }

    public function saveTeamMember($id, Request $request)
    {
        try {
            $input = $request->all();
            $data = $this->householdMembersRepository->saveTeamMember($id, $input);
            $response = [
                'code'   => 200,
                'response' => $data,
            ];
        } catch (Exception $e) {
            $response = [
                'code'    => 500,
                'message' => $e->getMessage(),
                'response'  => false,
            ];
        }
        return response::json($response);
    }

    public function deleteTeamMember($id, $memberId)
    {
        try {
            $data = $this->householdMembersRepository->deleteTeamMember($memberId);
            $response = [
                'code'   => 200,
                'response' => $data,
            ];
        } catch (Exception $e) {
            $response = [
                'code'    => 500,
                'message' => $e->getMessage(),
                'response'  => false,
            ];
        }
        return response::json($response);
    }

    public function getAllMembers($household_id)
    {
        try {
            $returnData       = $this->householdMembersRepository->getAllMembers($household_id);
            $response       = [
                'code'   => 200,
                'response' => $returnData,
            ];
        } catch (Exception $e) {
            $response = [
                'code'    => 500,
                'message' => $e->getMessage(),
                'response'  => false,
            ];
        }
        return response()->json($response);
    }

}
