<?php

namespace App\Data\Services;

use App\Data\Entities\OrganizationScoringKey;
use App\Data\Entities\PartnerScoringKey;
use App\Data\Entities\Organization;
use App\Data\Entities\DefaultScoringKey;
use Facades\ {
     App\Data\Services\AuthService
};

class ScoringKeyService
{

    /**
    * Get all scoring key of current login user
    */
    public function getScoringKey()
    {
        $user = AuthService::getCurrentUser();
        if ($user->organization_id && !$user->partner_id) {
            return OrganizationScoringKey::where('user_id', $user->id)->orderBy('order', 'asc')->get();
        }

        return PartnerScoringKey::where('user_id', $user->id)->orderBy('order', 'asc')->get();
    }

    /**
    * Create or Update Scoring key for organization and partner
    */
    public function saveScoringKey($request)
    {
        if (isset($request['id']) && !empty($request['id'])) {
            return $this->updateScoringKey($request);
        }

        $user = AuthService::getCurrentUser();
        $data = [
            'description' => $request['scoringKey'],
            'user_id' => $user->id
        ];

        if ($user->organization_id && !$user->partner_id) {
            $model = OrganizationScoringKey::class;
            $data = array_merge($data, ['organization_id' => $user->organization_id]);
        } else {
            $model = PartnerScoringKey::class;
            $data = array_merge($data, ['partner_id' => $user->partner_id]);
        }

        $maxOrder = $model::max('order');
        $scoringKey = $model::create($data);

        if (!$maxOrder) {
            $scoringKey->order = 1;
        } else {
            $scoringKey->order = $maxOrder + 1;
        }

        $scoringKey->save();
        return $scoringKey;
    }

    /**
    * Update description of scoring key for organization and partner
    */
    public function updateScoringKey($request)
    {
        $user = AuthService::getCurrentUser();
        if ($user->organization_id && !$user->partner_id) {
            $model = OrganizationScoringKey::class;
        } else {
            $model = PartnerScoringKey::class;
        }

        $scoringKey = $model::firstOrNew(['id' => $request['id']]);
        $scoringKey->description = $request['scoringKey'];
        $scoringKey->save();

        return $scoringKey;
    }

    /**
    * Delete scoring key for organization and partner
    */
    public function deleteScoringKey($id)
    {
        $user = AuthService::getCurrentUser();
        if ($user->organization_id && !$user->partner_id) {
            $model = OrganizationScoringKey::class;
        } else {
            $model = PartnerScoringKey::class;
        }

        if ($model) {
            $exists = $model::where('id', $id)->first();
            if ($exists) {
                return $exists->delete();
            }
        }

        return false;
    }

    /**
    * Update scoring key order as per given orders
    */
    public function updateScoringOrders($request)
    {
        if (empty($request['order'])) {
            return false;
        }

        if($request['key_type'] == 'custom') {
            $user = AuthService::getCurrentUser();
            if ($user->organization_id && !$user->partner_id) {
                $model = OrganizationScoringKey::class;
            } else {
                $model = PartnerScoringKey::class;
            }
        } else {
            $model = DefaultScoringKey::class;
        }

        try {
            \DB::beginTransaction();

            if (class_exists($model)) {
                foreach ($request['order'] as $key => $value) {
                    $item = $model::find($value['id']);
                    if ($item) {
                        $item->order = $key + 1;
                        $item->save();
                    }
                }
            }

            \DB::commit();
            return true;
        } catch (Exception $e) {
            \DB::rollback();
        }
    }

    public function getScoringKeyByOrg()
    {
        $user = AuthService::getCurrentUser();
        $org  = Organization::find($user->organization_id);

        if ($org->partner_id <> 1) {
            return PartnerScoringKey::where('partner_id', $org->partner_id)->orderBy('order', 'asc')->get();
        }

        return OrganizationScoringKey::where('organization_id', $org->id)->orderBy('order', 'asc')->get();
    }

    public function getDefaultScoringKeys($title)
    {
        if(isset($title) &&  !is_null($title)) {
            return DefaultScoringKey::where('name', $title)->orderBy('order', 'asc')->get();
        }
        return DefaultScoringKey::distinct()->get(['name']);
    }
}