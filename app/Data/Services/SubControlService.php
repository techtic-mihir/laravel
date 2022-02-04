<?php

namespace App\Data\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Arr;

use Config;
use Mail;
use Storage;
use Carbon\Carbon;

use App\Data\Entities\Program;
use App\Data\Entities\App;
use App\Data\Entities\AppTemplate;
use App\Data\Entities\SubControl;
use App\Data\Entities\SubControlTemplate;
use App\Data\Entities\SubControlTemplateMap;
use App\Data\Entities\ScoringHistory;
use App\Data\Entities\SubcontrolProfile;
use App\Data\Entities\User;
use App\Data\Entities\Document;
use App\Data\Entities\Task;
use App\Data\Constants\RiskRatingOption;
use App\Data\Entities\TaskNotesMap;
use App\Data\Entities\TaskOccurrence;
use App\Data\Entities\Partner;
use App\Data\Entities\Organization;
use App\Data\Entities\SubcontrolDocumentLink;
use App\Data\Entities\SubcontrolVendor;
use App\Data\Entities\SubcontrolRiskRating;
use App\Data\Entities\FrameworkDocument;
use App\Data\Entities\SubcontrolComment;
use App\Data\Entities\PlatformDocument;
use App\Data\Entities\DocumentSubcontrol;
use App\Data\Entities\SubControlTemplateLevel;

use Facades\{
    App\Data\Services\TaskService,
    App\Data\Services\ProgramService,
    App\Data\Services\EmailService,
    App\Data\Services\AuthService,
    App\Data\Services\UserService,
    App\Data\Services\AppService,
    App\Data\Services\OrganizationService,
    App\Data\Services\DocumentService,
    App\Data\Services\FrameworkService
};

use App\Jobs\UserNotification;
use App\Mail\AssignComment;

class SubControlService
{

    public function getSubControlDetails($appId, $subcontrolId, $source = 0, $is_searchable = false, $search_string = null, $currentUser = null)
    {
        $subcontrol = SubControl::where('id', $subcontrolId)->where('app_id', $appId)->first();

        $mappedTemplates = [];
        $response = AppService::doesAppBelongToCurrentOrganization($appId);
        
        if (!$response) {
            return $this->response->error('Unauthorized', 403);
        } else {
            $programId = App::find($appId)->program_id;
        }

        $currentProgram          = Program::find($programId);
        $currentProgramFramework = $currentProgram->framework;
        $mappedProgramFrameworks = $currentProgram->frameworks()->get();
        $currentOrganizationId   = $currentProgram->organization_id;
        $orgDocumentSku          = OrganizationService::checkOrgSku('Documents', $currentOrganizationId);

        if (stripos( $subcontrol->description, 'Related Document' )) {
            if ($currentProgramFramework){
                $frameworkDocument = FrameworkDocument::where('framework_id', $currentProgramFramework->id)->get();

                if($frameworkDocument->isNotEmpty()) {
                    $frameworkDocument->each(function ($document, $key) use($subcontrol) {
                        if (stripos( $subcontrol['description'], $document['document_title'] )) {
                            $urls = explode('/', $document['document_url']);
                            $hash = end($urls);
                            $replaceTitle = '<a href="javascript:void(0)" class="d-doc-link other-doc" data-hash="'.$hash.'">'.$document['document_title'].'</a>';
                            $subcontrol['description'] = str_replace($document['document_title'], $replaceTitle, $subcontrol['description']);
                        }
                    });
                }
            }
        }

        $mappedProgramFrameworkIds = [];

        if (!$mappedProgramFrameworks->isEmpty()) {
            foreach ($mappedProgramFrameworks as $prgrmfwm) {
                array_push($mappedProgramFrameworkIds, $prgrmfwm->id);
            }
        }

        $mappedFrameworkApptemplateIds = [];
        if ($mappedProgramFrameworkIds) {
            $mappedFrameworkApptemplates = DB::table('framework_app_template_map')->whereIn('framework_id', $mappedProgramFrameworkIds)
                ->get();

            if (!$mappedFrameworkApptemplates->isEmpty()) {
                foreach ($mappedFrameworkApptemplates as $mapFwm) {
                    array_push($mappedFrameworkApptemplateIds, $mapFwm->app_template_id);
                }
            }
        }

        $mappedTemplates = SubControlTemplateMap::where('sub_control_template_id', $subcontrol['sub_control_template_id'])->get();
        $subcontroltempData = [];

        if (!$mappedTemplates->isEmpty()) {
            foreach ($mappedTemplates as $temp) {
                $subcontrolTemplate = SubControlTemplate::where('id', $temp['child_sub_control_template_id'])
                    ->whereIn('app_template_id', $mappedFrameworkApptemplateIds)
                    ->first();
                if ($subcontrolTemplate) {
                    $apptemplate = AppTemplate::find($subcontrolTemplate['app_template_id']);
                    $framework = $apptemplate->framework()->whereIn('id', $mappedProgramFrameworkIds)
                        ->whereNull('organization_id')->first();

                    $data['name'] = $subcontrolTemplate->name;
                    $data['description'] = $subcontrolTemplate->description;
                    $subcontroltempData[$framework->name][] = $data;
                }
            }
        }

        $tasks = $subcontrol->tasks()->with(['notes', 'comments', 'comments.user'])->get();
        $profiles = $subcontrol->profiles()->get();
        $controlActivities = $subcontrol->subControlActivities()->get();
        $risks = $subcontrol->riskRatings()->get();
        
        if (!$risks->isEmpty()) {
            $risks[0]['rating_option'] = $this->colorCodeCalculationForRiskRating($risks[0]);
        }

        $taskIds = [];
        if (!empty($tasks)) {
            foreach ($tasks as $task) {
                $taskIds[] = $task["id"];
                $task['status'] = TaskService::getStatus($task['status']);
                $task['users'] = $task->users()->get();
                
                if (!empty($task['reccur_week'])) {
                    $task['reccur_week_ordinal'] = explode(' ', $task['reccur_week'])[0];
                    $task['reccur_week_day'] = explode(' ', $task['reccur_week'])[1];
                }

                if ($task['reccur_pattern'] == 'sameWeekYear') {
                    $task['reccur_week_month'] = $task['reccur_month'];
                    $task['reccur_month'] = null;
                }

                if (!empty($task->reccur_number_of_months)) {
                    if ($task->reccur_pattern == 'sameDayMonth') {
                        $task->reccur_number_day = $task->reccur_number_of_months;
                    } else if ($task->reccur_pattern == 'sameWeekMonth') {
                        $task->reccur_number_week = $task->reccur_number_of_months;
                    }
                }
            }
        }
        
        $taskOccurences = TaskService::getAllTaskOccurrences($taskIds);
        if (!empty($taskOccurences)) {
            foreach ($tasks as $task) {
                $taskId = $task["id"];
                if (isset($taskOccurences[$taskId])) {
                    $task->occurences = $taskOccurences[$taskId];
                } else {
                    $task->occurences = [];
                }
            }
        }

        if (!$currentUser) {
            $currentUser = AuthService::getCurrentUser();
        }
        //$documents = $subcontrol->subcontrolDoc()->where('user_id', $currentUser->id)->get();

        $documentData = DocumentService::getAssignDocument($subcontrolId, $currentUser->id, $currentOrganizationId);
        $documents    = DocumentService::checkDocumentPermission($documentData);

        $users = $subcontrol->users()->get();
        $subcontrol['documents'] = $documents;
        $subcontrol['tasks'] = $tasks;
        $subcontrol['control_activities'] = $controlActivities;
        $subcontrol['profiles'] = $profiles;
        $subcontrol['risks'] = $risks;
        //$subcontrol['documents'] = $documents;
        $subcontrol['assigned_users'] = $users;
        $subcontrol['mapped_sub_control_template_data'] =  $subcontroltempData;
        $subcontrol['have_access_doc'] =  ($orgDocumentSku) ? true : false;

        //subcontrol comments with users
        $comments = $subcontrol->comments()->with('user')->get();
        $subcontrol['comments'] = $comments->sortBy('id')->values();

        $partnerId = "";
        if ($source == 0) {
            $orgId = $currentUser->organization_id;
            if (!empty($orgId)) {
                $org = Organization::find($orgId);
                $partnerId = $org->partner_id;
            } else {
                $partnerId = $currentUser->partner_id;
            }
        }

        $subcontrol["partner"] = !empty($partnerId) ? Partner::find($partnerId) : "";
        $childSubControls = $this->getAllChildSubControl($subcontrolId);
        
        if (!empty($childSubControls)) {
            $subcontrol["level_1"] = $childSubControls;
        }
        
        $subcontrol['history'] = $subcontrol->scoringHistory()->orderBy('created_at', 'desc')->get();
        //$subcontrol['document_link'] = $this->getSubcontrolDocumentLinks($subcontrolId);
        if ($source == 1) {
            $subcontrol['vendor'] = $this->getSubcontrolVendors($subcontrolId);
        } else {
            $subcontrol['vendor'] = $this->getSubcontrolVendors($subcontrolId, true);
        }

        return $subcontrol;
    }

    public function saveSubControl($id, $input, $skipEmails = false, $skipHistory = false)
    { 
        $data = array();
        $existingSubcontrol = SubControl::with('app.program')->find($id);
        $currentProgram = $existingSubcontrol->app()->first()->program()->first();
        $orgId = $currentProgram->organization_id;
        $currentProgramId = $currentProgram->id;
        $mappedProgramFrameworks = $currentProgram->frameworks()->get();
        $mappedProgramFrameworkIds = [];
        $currentUser = AuthService::getCurrentUser();
        $permissions = '';
        if (empty($currentUser->role_id)) {
            $partnerOrganizationRole = AuthService::getPartnerOrganizationRole($orgId, $currentUser->id);
            $permissions = $partnerOrganizationRole->permissions;
        } else {
            $permissions = UserService::getUserPermissions($currentUser->id);
        }
        $subControlsForMappings = [];
        if (!isset($input['mapping'])) {
            $subControlsForMappings = SubControlService::getMappedSubControls($id);
        }

        if (!empty($input['document_link_to_delete'])) {
            $documentIDsTobeDeleted = [];
            $mappedSubcontrolIds = [];
            if ($subControlsForMappings) {
                foreach ($subControlsForMappings as $mappedSubControl) {
                    array_push($mappedSubcontrolIds, $mappedSubControl['sub_control_id']);
                }
                foreach ($input['document_link_to_delete'] as $id) {
                    $currentDocumetLink = DocumentSubcontrol::select('title', 'link')->where('document_sub_controls.id', $id)->leftJoin('documents', 'documents.id', '=', 'document_sub_controls.document_id')->first();
                    //$currentDocumetLink = SubcontrolDocumentLink::where('id', $id)->first();
                    $documetLabel = $currentDocumetLink["title"];
                    $documetLink = $currentDocumetLink["link"];


                    //$documentLinkIDs = SubcontrolDocumentLink::select('id')->whereIn('subcontrol_id', $mappedSubcontrolIds)->where('document_label', $documetLabel)->where('document_link', $documetLink)->get()->pluck('id')->toArray();

                    $documentLinkIDs = DocumentSubcontrol::select('document_sub_controls.id')->leftJoin('documents', 'documents.id', '=', 'document_sub_controls.document_id')->whereIn('document_sub_controls.sub_control_id', $mappedSubcontrolIds)->where('documents.title', $documetLabel)->where('documents.link', $documetLink)->get()->pluck('document_sub_controls.id')->toArray();
                    $documentIDsTobeDeleted[] = $documentLinkIDs;
                    unset($documentLinkIDs);
                }
                DocumentSubcontrol::whereIn('id', array_collapse($documentIDsTobeDeleted))->delete();
            } else {
                DocumentSubcontrol::whereIn('id', $input['document_link_to_delete'])->delete();
            }
        }

        $resetAllUsers = 0;
        if ($id) {
            $subcontrol = SubControl::find($id);
            $subcontrol->users()->where('assigned_by', null)->update(['assigned_by' => $input['assigned_by']]);
        }

        if (isset($input['is_subcontrol_disabled']) && $input['is_subcontrol_disabled']) {
            $input['progress'] = 0;
        }
        if (empty($subcontrol)) {
            $subcontrol = new SubControl;
        } else {
            if (isset($input['end_date'])) {
                $endDate = date('Y-m-d', strtotime($input['end_date']));
                if ($subcontrol->end_date != $endDate) {
                    $resetAllUsers = 1;
                }
            }
            if (isset($input['progress']) && !$skipHistory) {
                $scoringhistory = new ScoringHistory;
                // $currentUser = AuthService::getCurrentUser();
                $acronym = "";
                if (!empty($currentUser->first_name)) {
                    $words = explode(" ", trim($currentUser->first_name));
                    foreach ($words as $w) {
                        if (!empty($w)) {
                            $acronym .= $w[0] . '. ';
                        }
                    }
                }
                $scoringhistory->sub_control_id = $subcontrol->id;
                $scoringhistory->scored_by = strtoupper($acronym) . ucfirst($currentUser->last_name);
                $scoringhistory->old_value = $subcontrol->progress;
                $scoringhistory->new_value = $input['progress'];
                $scoringhistory->save();
            }
        }
        $subcontrol->app_id = $input['app_id'];

        if (isset($input['sub_control_template_id'])){
            $subcontrol->sub_control_template_id = $input['sub_control_template_id'];
        }

        if (isset($input['title'])){
            $subcontrol->title = $input['title'];
        }

        if (isset($input['description'])){
            $subcontrol->description = $input['description'];
        }

        if (isset($input['is_subcontrol_disabled'])){
            $subcontrol->is_disable = $input['is_subcontrol_disabled'];
        }

        if (isset($input->budget_amount) || isset($input['budget_amount'])) {
            $subcontrol->budget_amount = $input['budget_amount'];
        }
        if (isset($input->notes) || isset($input['notes'])) {
            $subcontrol->notes = $input['notes'];
        }
        if (isset($input->is_disable_note) || isset($input['is_disable_note'])) {
            $subcontrol->is_disable_note = $input['is_disable_note'];
        }

        if (in_array('START_DATE_AND_END_DATE_UPDATE', $permissions)) {
            if (isset($input['is_recurring_initiative']) && !empty($input['is_recurring_initiative'])) {
                $subcontrol->is_recurring_initiative = $input['is_recurring_initiative'];
            } else {
                $subcontrol->is_recurring_initiative = "";
            }

            if (isset($input['occurance_limit']) && !empty($input['occurance_limit'])) {
                $subcontrol->occurance_limit = $input['occurance_limit'];
            } else {
                $subcontrol->occurance_limit = "";
            }

            if (isset($input['recurring_period']) && !empty($input['recurring_period'])) {
                $subcontrol->recurring_period = $input['recurring_period'];
            } else {
                $subcontrol->recurring_period = "";
            }

            $subcontrol->create_alert = (isset($input['create_alert'])) ? $input['create_alert'] : "";

            if ((isset($input->start_date) || isset($input['start_date'])) && (isset($input->end_date) || isset($input['end_date']))) {

                if ($input['start_date'] && $input['end_date']) {
                    $subcontrol->start_date = date('Y-m-d', strtotime($input['start_date']));
                    $subcontrol->end_date = date('Y-m-d', strtotime($input['end_date']));
                } else {
                    $subcontrol->start_date = null;
                    $subcontrol->end_date = null;
                    $subcontrol->occurance_limit = null;
                    $subcontrol->recurring_period = null;
                    $subcontrol->is_recurring_initiative = null;
                }
        
            }
        }

 

        if (isset($input['progress'])) {
            $subcontrol->progress = $input['progress'];
        }

    
        $subcontrol->save();
        $subcontrolId = $subcontrol->id;

        //save subcontrol comments
        if(isset($input['comment_note']) && !is_null($input['comment_note'])) {
            $commentData['id'] = $input['comment_id'];
            $commentData['user_id'] = $input['assigned_by'];
            $commentData['notes'] = $input['comment_note'];
            $commentData['sub_control_id'] = $subcontrolId;
            $commentData['mentionIds'] = $input['mentionIds'];
            $this->saveSubControlComment($commentData);
        }

        // update over all progress to the parent subcontrol
        if (isset($input["update_parent"]) || $subcontrol->parent_sub_control_id != null) {
            $parentId = $subcontrol->parent_sub_control_id;
            if (!empty($parentId)) {
                $this->updateParentSubcontrol($parentId);
            }
        }

        $users = $subcontrol->users()->select('id')->get()->toArray();
        if (isset($input['document_link'])) {
            $this->saveDocumentLink($subcontrolId, $input['document_link'], $input['edit_document_link']);
        }
        if (isset($input['vendor'])) {
            $this->saveSubcontrolVendors($subcontrolId, $input['vendor']);
        }
        $currentUsers = array_map(function ($user) {
            return $user['id'];
        }, $users);


        if (isset($input['resources']) && !empty($input['resources'])) {
            foreach ($input['resources'] as $user) {
                $data[] = $user['id'];
            }

            if ($resetAllUsers) {
                $subcontrol->users()->detach();
                $detachUsers = null;
            } else {
                $detachUsers = array_diff($currentUsers, $data);
            }

            if (!empty($detachUsers)) {
                foreach ($detachUsers as $value) {
                    $subcontrol->users()->wherePivot('user_id', '=', $value)->detach();
                }
            }

            $newAssignedUsers = $data;
            $newAssignedUsersBeforeReset = $data;

            if (!empty($currentUsers))
                $newAssignedUsersBeforeReset = array_diff($newAssignedUsers, $currentUsers);

            if (!empty($currentUsers) && $resetAllUsers == 0)
                $newAssignedUsers = array_diff($newAssignedUsers, $currentUsers);

            if (!empty($newAssignedUsers)) {
                foreach ($newAssignedUsers as $value) {
                    $user = User::find($value);
                    $notify = 0;
                    if (!$existingSubcontrol || $resetAllUsers == 1) {
                        if ($input['end_date'] == Carbon::now()->addDays(30)) {
                            $notify = 1;
                        }
                        if ($input['end_date'] == Carbon::now()->addDays(7)) {
                            $notify = 2;
                        }
                        if ($skipEmails) {
                            $notify = 2;
                        }
                        if ($user) {
                            SubControl::find($subcontrolId)->users()->save($user, ['assigned_by' => $input['assigned_by'], 'notified' => $notify]);
                        }
                    } else {
                        if ($user) {
                            SubControl::find($subcontrolId)->users()->save($user, ['assigned_by' => $input['assigned_by']]);
                        }
                    }
                }
                if (!$skipEmails) {
                    EmailService::assignSubcontrolUsers($subcontrolId, $newAssignedUsersBeforeReset, 1);
                }
            }



            $detachUsers = array_diff($currentUsers, $data);
            $updatedUsers = array_diff($currentUsers, $detachUsers);
            if (!$skipEmails) {
                EmailService::assignSubcontrolUsers($subcontrolId, $updatedUsers, 0);
            }
        } else if (isset($input['resources']) && empty($input['resources'])) {
            if (!empty($currentUsers)) {
                foreach ($currentUsers as $value) {
                    $subcontrol->users()->wherePivot('user_id', '=', $value)->detach();
                }
            }
        }

        if ($subControlsForMappings) {

            foreach ($subControlsForMappings as $mappedSubControl) {
                if ($mappedSubControl['sub_control_id'] != $subcontrolId) {

                    $mappedSubControl = SubControl::find($mappedSubControl['sub_control_id']);

                    $mappedSubControl->users()->where('assigned_by', null)->update(['assigned_by' => $input['assigned_by']]);
                    if (isset($input->budget_amount) || isset($input['budget_amount'])) {
                        $mappedSubControl->budget_amount = $input['budget_amount'];
                    }

                    if (isset($input->notes) || isset($input['notes'])) {
                        $mappedSubControl->notes = $input['notes'];
                    }

                    $mappedSubControl->is_disable = (isset($input['is_subcontrol_disabled'])) ? $input['is_subcontrol_disabled'] : false;



                    if (isset($input->is_disable_note) || isset($input['is_disable_note'])) {
                        $mappedSubControl->is_disable_note = $input['is_disable_note'];
                    }


                    if (isset($input['is_recurring_initiative']) && !empty($input['is_recurring_initiative'])) {
                        $mappedSubControl->is_recurring_initiative = $input['is_recurring_initiative'];
                    } else {
                        $mappedSubControl->is_recurring_initiative = "";
                    }

                    if (isset($input['occurance_limit']) && !empty($input['occurance_limit'])) {
                        $mappedSubControl->occurance_limit = $input['occurance_limit'];
                    } else {
                        $mappedSubControl->occurance_limit = "";
                    }

                    if (isset($input['recurring_period']) && !empty($input['recurring_period'])) {
                        $mappedSubControl->recurring_period = $input['recurring_period'];
                    } else {
                        $mappedSubControl->recurring_period = "";
                    }
                    if ((isset($input->start_date) || isset($input['start_date'])) && (isset($input->end_date) || isset($input['end_date']))) {

                        if ($input['start_date'] && $input['end_date']) {
                            $mappedSubControl->start_date = date('Y-m-d', strtotime($input['start_date']));
                            $mappedSubControl->end_date = date('Y-m-d', strtotime($input['end_date']));
                        } else {
                            $mappedSubControl->start_date = null;
                            $mappedSubControl->end_date = null;
                            $mappedSubControl->is_recurring_initiative = null;
                            $mappedSubControl->occurance_limit = null;
                            $mappedSubControl->recurring_period = null;
                        }
                    }

                    
                    if (isset($input['progress'])) {
                        $mappedSubControl->progress = $input['progress'];
                    }

                    $mappedSubControl->create_alert = (isset($input['create_alert'])) ? $input['create_alert'] : "";
                    $mappedSubControl->save();

                    $scoringHistory = $subcontrol->scoringHistory()->get();
                    if (!$scoringHistory->isEmpty()) {
                        $mappedSubControl->scoringHistory()->delete();
                        foreach ($scoringHistory as $history) {
                            $scoringhistory = new ScoringHistory();
                            $scoringhistory->sub_control_id = $mappedSubControl->id;
                            $scoringhistory->scored_by = $history->scored_by;
                            $scoringhistory->old_value = $history->old_value;
                            $scoringhistory->new_value = $history->new_value;
                            $scoringhistory->created_at = $history->created_at;
                            $scoringhistory->updated_at = $history->updated_at;
                            $scoringhistory->save();
                          
                        }
                    }

                    if (isset($input['resources']) && !empty($input['resources'])) {
                        $mappedSubControl->users()->detach();
                        $assignedSubcontrolUsers = $subcontrol->users()->get();
                        if (!$assignedSubcontrolUsers->isEmpty()) {
                            foreach ($assignedSubcontrolUsers as $user) {
                                $mappedSubControl->users()->save($user, ['assigned_by' => $input['assigned_by']]);
                            }
                        }
                    }
                    if (isset($input['document_link'])) {
                        $this->saveDocumentLink($mappedSubControl->id, $input['document_link'], $input['edit_document_link']);
                    }
                    if (isset($input['vendor'])) {
                        $this->saveSubcontrolVendors($mappedSubControl->id, $input['vendor']);
                    }
                    if ($mappedSubControl->parent_sub_control_id != null) {
                        $this->updateParentSubcontrol($mappedSubControl->parent_sub_control_id);
                    }

                    //sync comments to mapped controls
                    $comments = $subcontrol->comments()->get();
                    if (!$comments->isEmpty()) {
                        $mappedSubControl->comments()->delete();
                        foreach ($comments as $comment) {
                            $com = new SubcontrolComment();
                            $com->sub_control_id = $mappedSubControl->id;
                            $com->notes = $comment->notes;
                            $com->user_id = $comment->user_id;
                            $com->created_at = $comment->created_at;
                            $com->updated_at = $comment->updated_at;
                            $com->save();

                        }
                    }

                }
            }
        }
        return true;
    }

    public function updateParentSubcontrol($parentId)
    {
        $allChildSubControls = SubControl::where("parent_sub_control_id", $parentId)->where("is_disable", 0)->select('progress')->get();
        $countChildSubControls = count($allChildSubControls) ? count($allChildSubControls) : 1;
        $totalChildProgress = 0;
        foreach ($allChildSubControls as $child_sub_controls) {
            $totalChildProgress += $child_sub_controls->progress;
        }
        $avgChildProgress = $totalChildProgress / $countChildSubControls;
        $parentSubControl = SubControl::find($parentId);
        if (!count($allChildSubControls)) {
            $parentSubControl->is_disable = true;
        } else {
            $parentSubControl->is_disable = false;
        }
        $parentSubControl->progress = $avgChildProgress;
        $parentSubControl->save();
    }
    public function saveSubControlTemplate($id, $input)
    {
        if ($id) {
            $subcontroltemplate = SubControlTemplate::find($id);

        } else {
            $subcontroltemplate = new SubControlTemplate;
            $order = DB::table('sub_control_template')->where('app_template_id', $input['app_template_id'])->pluck('order');
            $ordercount = $order->max();
            $subcontroltemplate->order = $ordercount +1;
        }
        isset($input['title']) ? $subcontroltemplate->name = $input['title'] : "";

        if (isset($input['description']) && !empty($input['description'])) {
            $subcontroltemplate->description = $input['description'];
        } else {
            $subcontroltemplate->description = "";
        }
        if (isset($input['narrative']) && !empty($input['narrative'])) {
            $subcontroltemplate->narrative = $input['narrative'];
        } else {
            $subcontroltemplate->narrative = "";
        }
        $subcontroltemplate->app_template_id = $input['app_template_id'];

        $subcontroltemplate->icon = isset($input['icon']) ? $input['icon'] : "app-icon.png";
        isset($input['organization_id']) ? $subcontroltemplate->organization_id = $input['organization_id'] : "";
        isset($input['partner_id']) ? $subcontroltemplate->partner_id = $input['partner_id'] : "";
        $subcontroltemplate->save();

        if (!empty($input['app_id'])) {
            $subcontrol = SubControl::where('sub_control_template_id', $subcontroltemplate->id)
                ->where('app_id', $input['app_id'])->first();
            if (!empty($subcontrol)) {
                $subcontrol->title = $subcontroltemplate->name;
                $subcontrol->description = $subcontroltemplate->description;
                $subcontrol->save();
            } else {
                $subcontrol = new SubControl;
                $subcontrol->title = $subcontroltemplate->name;
                $subcontrol->description = $subcontroltemplate->description;
                $subcontrol->sub_control_template_id = $subcontroltemplate->id;
                $subcontrol->app_id = $input['app_id'];
                $subcontrol->save();
            }
        }
        return $subcontroltemplate->id;
    }

    public function deleteSubControl($id, $organizationId)
    {
        $subcontrol = SubControl::find($id);
        $template = SubControlTemplate::where(array('id' => $subcontrol->sub_control_template_id, 'organization_id' => $organizationId))->first();
        if (!empty($template)) {
            $subcontrol->delete();
            return true;
        } else {
            return false;
        }


    }

    public function deleteSubControlTemplate($id, $organizationId)
    {
        $template = SubControlTemplate::where(array('id' => $id, 'organization_id' => $organizationId))->first();
        if (!empty($template)) {
            $template->delete();
            $subcontrols = SubControl::where('sub_control_template_id', $template->id)->get();
            if (!empty($subcontrols)) {
                foreach ($subcontrols as $subcontrol) {
                    SubControl::find($subcontrol->id)->delete();
                }
            }
            return true;
        } else
            return false;
    }

    public function getSubcontrolsbyUser($userId)
    {
        $user = User::find($userId);
        return $user->subcontrols()->get();
    }
    public function getAllSubcontrols($organizationId)
    {
        $programId = ProgramService::getCurrentUserProgramId();
        $program = Program::find($programId);
        $framework = FrameworkService::getFrameworkDetails($program->framework_id);

        $currentLevel = ProgramService::getCurrentUserProgramLevelId();
        $subControlTemplateIds = SubControlTemplateLevel::whereIn('level', [$currentLevel])->pluck('sub_control_template_id');  

        $subcontrol = $subcontrols = $controls = array();
        $appId = [];

        if (!empty($programId)) {
            $app = App::where(array('program_id' => $programId, 'status' => 1))->get();
            if (!empty($app)) {
                foreach ($app as $data) {
                    $appId[] = $data->id;
                }
                
                $subcontrolData = App::with(['subcontrols' => function($query) use($framework, $program, $subControlTemplateIds) { 
                    //framework has multiple levels
                    if (!empty($framework) && $framework->has_multiple_levels && !empty($program->level)) {
                        $query->whereIn('sub_control.sub_control_template_id', $subControlTemplateIds);
                    }
                }])->whereIn('id', $appId)->get();

                foreach ($subcontrolData as $subcontrolInfo) {
                    $subcontrol[] = $subcontrolInfo;
                }
            }
        }

        $colors = array(
            '#2E98CC', '#1DA66E', '#7D4493', '#FF5F01', '#FFB12B', '#D11718', '#3A579B', '#D71D5E', '#38684e', '#00C7FE', '#A58BD5', '#402866', '#9AB9BB', '#CB5F91', '#C26263', '#6B594D', '#AAA786', '#53B09E', '#3C2F1E', '#EA984C', '#D07850', '#AE3F14', '#8F3FB0', '#4FE001', '#6F9684', '#7D8B8C', '#80463B', '#F0C43A', '#4CBA74', '#BF3A32', '#7E8C8D', '#0466EF', '#422C8E', '#F2503B', '#C1DB0A', '#673301', '#A2BFC7', '#FF7719', '#A2285B', '#9498E1', '#536980', '#52B767', '#95A5A5', '#705CBF', '#D55400', '#87810d', '#8A4E1C', '#847e0b', '#9B500D'
        );

        foreach ($subcontrol as $control) {
            $controls[] = $control['subcontrols'];
        }

        foreach ($controls as $data) {
            if (!empty($data)) {
                foreach ($data as $control) {
                    $subcontrols[] = array(
                        'start' => $control->start_date,
                        'end' => ($control->end_date != null) ? date('Y-m-d', strtotime($control->end_date . ' +1 day')) : null,
                        'title' => $control->title,
                        'id' => $control->app_id,
                        'sub_control_id' => $control->id,
                        'is_recurring_initiative' => $control->is_recurring_initiative,
                        'create_alert' => $control->create_alert,
                        'recurring_period' => $control->recurring_period,
                        'occurance_limit' => $control->occurance_limit,
                        'color' => $colors[($control->id) % count($colors)]
                    );
                }
            }
        }
        return $subcontrols;
    }

    public function saveSubControlProfile($subcontrolId, $profileId = null, $input)
    {
        $profile = SubcontrolProfile::find($profileId);
        if (empty($profile)) {
            $profile = new SubcontrolProfile;
        }
        $profile->sub_control_id = $subcontrolId;
        isset($input['profile']) ? $profile->profile = $input['profile'] : "";
        if (isset($input['action_steps']) && !empty($input['action_steps'])) {
            $profile->action_steps = $input['action_steps'];
        } else {
            $profile->action_steps = "";
        }
        isset($input['gaps']) ? $profile->gaps = $input['gaps'] : "";
        isset($input['target_tier']) ? $profile->target_tier = $input['target_tier'] : "";
        isset($input['current_tier']) ? $profile->current_tier = $input['current_tier'] : "";
        $profile->save();
        return true;
    }

    public function saveSubControlRiskRating($subcontrolId, $riskId = null, $input)
    {
        $risk = SubcontrolRiskRating::where('sub_control_id', $subcontrolId)->first();
        if (empty($risk)) {
            $risk = new SubcontrolRiskRating;
        }
        $risk->sub_control_id = $subcontrolId;
        if (isset($input['current_control']) && !empty($input['current_control'])) {
            $risk->current_control = $input['current_control'];
        } else {
            $risk->current_control = "";
        }
        if (isset($input['remediation_plan']) && !empty($input['remediation_plan'])) {
            $risk->remediation_plan = $input['remediation_plan'];
        } else {
            $risk->remediation_plan = "";
        }
        if (isset($input['likelyhood']) && !empty($input['likelyhood'])) {
            $risk->likelyhood = $input['likelyhood'];
        } else {
            $risk->likelyhood = "";
        }
        if (isset($input['impact']) && !empty($input['impact'])) {
            $risk->impact = $input['impact'];
        } else {
            $risk->impact = "";
        }
        $risk->save();
        $subControlsForMappings  = $this->getMappedSubControls($subcontrolId);
        if ($subControlsForMappings) {
            foreach ($subControlsForMappings as $mappedSubControl) {
                if ($mappedSubControl['sub_control_id'] != $subcontrolId) {
                    $mappedSubControlRisk = SubcontrolRiskRating::where('sub_control_id', $mappedSubControl['sub_control_id'])->first();
                    if (empty($mappedSubControlRisk)) {
                        $mappedSubControlRisk = new SubcontrolRiskRating;
                    }
                    $mappedSubControlRisk->sub_control_id = $mappedSubControl['sub_control_id'];
                    if (isset($input['current_control']) && !empty($input['current_control'])) {
                        $mappedSubControlRisk->current_control = $input['current_control'];
                    } else {
                        $mappedSubControlRisk->current_control = "";
                    }
                    if (isset($input['remediation_plan']) && !empty($input['remediation_plan'])) {
                        $mappedSubControlRisk->remediation_plan = $input['remediation_plan'];
                    } else {
                        $mappedSubControlRisk->remediation_plan = "";
                    }
                    if (isset($input['likelyhood']) && !empty($input['likelyhood'])) {
                        $mappedSubControlRisk->likelyhood = $input['likelyhood'];
                    } else {
                        $mappedSubControlRisk->likelyhood = "";
                    }
                    if (isset($input['impact']) && !empty($input['impact'])) {
                        $mappedSubControlRisk->impact = $input['impact'];
                    } else {
                        $mappedSubControlRisk->impact = "";
                    }
                    $mappedSubControlRisk->save();
                }
            }
        }
        $ratingOption = $this->colorCodeCalculationForRiskRating($input);
        return $ratingOption;
    }
    public function getAllChildSubControl($parentId)
    {
        return SubControl::where('parent_sub_control_id', '=', $parentId)->get();
    }
    public function deleteSubControlProfile($profileId)
    {
        $profile = SubcontrolProfile::find($profileId);

        if (!empty($profile)) {
            $profile->delete();
            return true;
        } else {
            return false;
        }
    }

    public function deleteSubControlRiskRating($riskId)
    {
        $risk = SubcontrolRiskRating::find($riskId);
        if (!empty($risk)) {
            $risk->delete();
            return true;
        } else {
            return false;
        }
    }
    public function getMySubcontrols($programId, $userId = "")
    {
        $program = Program::find($programId);
        $framework = FrameworkService::getFrameworkDetails($program->framework_id);
        $frameworkId = $program->framework_id;

        $subControlData = App::leftJoin('program', 'program.id', '=', 'app.program_id')
            ->leftJoin('framework', 'framework.id', '=', 'program.framework_id')
            ->leftJoin('sub_control', 'sub_control.app_id', '=', 'app.id')
            ->leftJoin('sub_control_user_map', 'sub_control_user_map.sub_control_id', '=', 'sub_control.id')
            ->leftJoin('user', 'sub_control_user_map.user_id', '=', 'user.id')
            ->where(function ($query) use ($userId) {
                if (empty($userId)) {
                    $query->whereNotNull('sub_control_user_map.user_id');
                } else {
                    $query->where('sub_control_user_map.user_id', '=', $userId);
                }
            })
            ->where('framework.id', '=', $frameworkId)
            ->where('app.program_id', '=', $programId)
            ->where('app.status', '=', 1);

            //framework has multiple levels
            if (!empty($framework) && $framework->has_multiple_levels && !empty($program->level)) {
                $currentLevel = $program->level;
                $subControlTemplateIds = SubControlTemplateLevel::whereIn('level', [$currentLevel])->pluck('sub_control_template_id');
                $subControlData->whereIn('sub_control.sub_control_template_id', $subControlTemplateIds);
            }

            $subControlData = $subControlData->selectRaw('framework.id as framework_id, framework.name AS framework_name,'
                . 'app.id AS app_id, app.name As app_name,'
                . 'sub_control.id AS sub_control_id, sub_control.title AS sub_control_title,'
                . 'CASE WHEN sub_control.is_recurring_initiative = 0 THEN " " ELSE "Recurring" END AS is_recurring,'
                . 'user.first_name  AS user_first_name,user.last_name  AS user_last_name,'
                . 'sub_control.progress AS sub_control_score,sub_control.recurring_period AS recurring_period,sub_control.notes AS notes,sub_control.start_date AS start_date,sub_control.end_date AS end_date')
            ->groupBy('sub_control.id')
            ->orderBy('sub_control_score')
            ->get();

        return $subControlData;
    }

    public function getMyRiskRatings($programId, $userId = "")
    {
        $program = Program::find($programId);
        $framework = FrameworkService::getFrameworkDetails($program->framework_id);
        $frameworkId = $program->framework_id;

        $subControlData = App::leftJoin('program', 'program.id', '=', 'app.program_id')
            ->leftJoin('framework', 'framework.id', '=', 'program.framework_id')
            ->leftJoin('sub_control', 'sub_control.app_id', '=', 'app.id')
            ->leftJoin('subcontrol_risk_rating', 'subcontrol_risk_rating.sub_control_id', '=', 'sub_control.id')
            ->leftJoin('sub_control_user_map', 'sub_control_user_map.sub_control_id', '=', 'sub_control.id')
            ->leftJoin('user', 'sub_control_user_map.user_id', '=', 'user.id')
            ->where(function ($query) use ($userId) {
                if (!empty($userId) || $userId != "") {
                    $query->where('sub_control_user_map.user_id', '=', $userId);
                }
            })
            ->where('framework.id', '=', $frameworkId)
            ->where('app.program_id', '=', $programId)
            ->where('app.status', '=', 1);

            //framework has multiple levels
            if (!empty($framework) && $framework->has_multiple_levels && !empty($program->level)) {
                $currentLevel = $program->level;
                $subControlTemplateIds = SubControlTemplateLevel::whereIn('level', [$currentLevel])->pluck('sub_control_template_id');

                $subControlData->whereIn('sub_control.sub_control_template_id', $subControlTemplateIds);
            }

        $subControlData = $subControlData
            //don't display disabled sub controls
            ->where('sub_control.is_disable', '=', 0)
            ->whereNotNull('subcontrol_risk_rating.sub_control_id')
            ->selectRaw('framework.id as framework_id, framework.name AS framework_name,'
                . 'app.id AS app_id, app.name As app_name,'
                . 'sub_control.id AS sub_control_id, sub_control.title AS sub_control_title,'
                . 'sub_control.budget_amount AS budget,'
                . 'CASE WHEN user.id = NULL THEN NULL ELSE user.id END AS user_id,'
                . 'CASE WHEN user.first_name = NULL THEN NULL ELSE user.first_name END AS user_first_name,'
                . 'CASE WHEN user.last_name = NULL THEN NULL ELSE user.last_name END AS user_last_name,'
                . 'subcontrol_risk_rating.id AS risk_id, subcontrol_risk_rating.likelyhood AS likelyhood,'
                . 'subcontrol_risk_rating.impact AS impact,'
                . 'subcontrol_risk_rating.remediation_plan')
            ->groupBy('sub_control.id')
            ->get();

        if (!$subControlData->isEmpty()) {
            foreach ($subControlData as $sub) {
                $tempControl = Subcontrol::find($sub->sub_control_id);
                if ($userId) {
                    $sub->users = $tempControl->users()->where('user_id', $userId)->get();
                } else {
                    $sub->users = $tempControl->users()->get();
                }

                $sub->rating_option = $this->colorCodeCalculationForRiskRating($sub);
                $sub->rating_sort_order = $this->riskRatingSortOrder($sub->rating_option);
            }
        }

        $result = array_values(Arr::sort($subControlData, function ($value) {
            return $value->rating_sort_order;
        }));

        return $result;
    }


    public function getAllSubcontrolUsers($programId)
    {
        $frameworkId = Program::find($programId)->framework_id;
        $subControlData = App::leftJoin('program', 'program.id', '=', 'app.program_id')
            ->leftJoin('framework', 'framework.id', '=', 'program.framework_id')
            ->leftJoin('sub_control', 'sub_control.app_id', '=', 'app.id')
            ->leftJoin('sub_control_user_map', 'sub_control_user_map.sub_control_id', '=', 'sub_control.id')
            ->leftJoin('user', 'sub_control_user_map.user_id', '=', 'user.id')
            ->where('framework.id', '=', $frameworkId)
            ->where('app.program_id', '=', $programId)
            ->where('app.status', '=', 1)
            ->whereNotNull('user.id')
            ->selectRaw('CONCAT(user.first_name," ",user.last_name) user_name,user.id')
            ->groupBy('user.id')
            ->orderBy('user_name')
            ->get();

        return $subControlData;
    }

    public function getAllSubcontrolRiskUsers($programId)
    {
        $frameworkId = Program::find($programId)->framework_id;
        $subControlData = App::leftJoin('program', 'program.id', '=', 'app.program_id')
            ->leftJoin('framework', 'framework.id', '=', 'program.framework_id')
            ->leftJoin('sub_control', 'sub_control.app_id', '=', 'app.id')
            ->leftJoin('subcontrol_risk_rating', 'subcontrol_risk_rating.sub_control_id', '=', 'sub_control.id')
            ->leftJoin('sub_control_user_map', 'sub_control_user_map.sub_control_id', '=', 'sub_control.id')
            ->leftJoin('user', 'sub_control_user_map.user_id', '=', 'user.id')
            ->where('framework.id', '=', $frameworkId)
            ->where('app.program_id', '=', $programId)
            ->where('app.status', '=', 1)
            ->whereNotNull('user.id')
            ->whereNotNull('subcontrol_risk_rating.sub_control_id')
            ->selectRaw('CONCAT(user.first_name," ",user.last_name) user_name,user.id')
            ->groupBy('user.id')
            ->orderBy('user_name')
            ->get();

        return $subControlData;
    }

    public function filterRelatedSubcontrols($subControls)
    {
        $subControlTemplateIds = [];
        $subControlIds = [];
        $idsToRemove = [];
        foreach ($subControls as $subControl) {
            if (in_array($subControl->sub_control_template_id, $subControlTemplateIds)) {
                //If same sub control template is already processed
                $idsToRemove[] = $subControl->id;
            }
            $subControlTemplateIds[] = $subControl->sub_control_template_id;
            $subControlIds[] = $subControl->id;
        }

        $mappings = SubControlTemplateMap::whereIn('sub_control_template_id', $subControlTemplateIds)
            ->get(['sub_control_template_id', 'child_sub_control_template_id']);

        $harmonyTemplateIds = [];
        $childTemplateIds = [];
        foreach ($mappings as $map) {
            $harmonyTemplateIds[] = $map->sub_control_template_id;
            $childTemplateIds[] = $map->child_sub_control_template_id;
        }

        $harmonySubControls = SubControl::leftJoin('app', 'app.id', '=', 'sub_control.app_id')
            ->leftJoin('program', 'program.id', '=', 'app.program_id')
            ->whereIn('sub_control.id', $subControlIds)
            ->wherein('sub_control.sub_control_template_id', $harmonyTemplateIds)
            ->get(['sub_control.id as sub_control_id', 'program.id as program_id', 'program.organization_id as organization_id']);

        $childSubControls = SubControl::leftJoin('app', 'app.id', '=', 'sub_control.app_id')
            ->leftJoin('program', 'program.id', '=', 'app.program_id')
            ->leftJoin('program_framework_map', 'program_framework_map.framework_id', '=', 'program.framework_id')
            ->whereIn('sub_control.id', $subControlIds)
            ->wherein('sub_control.sub_control_template_id', $childTemplateIds)
            ->get(['sub_control.id as sub_control_id', 'program_framework_map.program_id as harmony_program_id', 'program.organization_id as organization_id']);


        foreach ($harmonySubControls as $s1) {
            foreach ($childSubControls as $s2) {
                if ($s1->program_id == $s2->harmony_program_id) {
                    $idsToRemove[] = $s2->sub_control_id;
                }
            }
        }

        $idsToRemove = array_unique($idsToRemove);

        $filteredSubControls = [];
        foreach ($subControls as $subControl) {
            if (!in_array($subControl->id, $idsToRemove)) {
                $filteredSubControls[] = $subControl;
            }
        }
        return $filteredSubControls;
    }

    public function getSubcontolsDueFirstAlert($firstInterval, $secondInterval)
    {
        $date = Carbon::now()->addDays($firstInterval);
        $endDate = $date->format("Y-m-d");
        $subControls = SubControl::leftJoin('sub_control_user_map as sm', 'sm.sub_control_id', '=', 'sub_control.id')
            ->where('sm.notified', '=', 0)
            ->where('create_alert', '=', 1)
            ->where('end_date', '=', $endDate)
            ->groupBy('sub_control.id')
            ->get();
        return $this->filterRelatedSubcontrols($subControls);
    }

    public function getSubcontolsDueSecondAlert($secondInterval)
    {
        $date = Carbon::now()->addDays($secondInterval);
        $endDate = $date->format("Y-m-d");
        $subControls = SubControl::leftJoin('sub_control_user_map as sm', 'sm.sub_control_id', '=', 'sub_control.id')
            ->where(function ($query) {
                $query->where('sm.notified', '=', 0);
                $query->orWhere('sm.notified', '=', 1);
            })
            ->where('create_alert', '=', 1)
            ->where('end_date', '=', $endDate)
            ->groupBy('sub_control.id')
            ->get();
        return $this->filterRelatedSubcontrols($subControls);
    }

    public function updateNotfiedUsers($subcontrolId, $userId, $notified = false, $assigned_by)
    {
        $user = User::find($userId);
        if (SubControl::find($subcontrolId)->users()->detach($user))
            return SubControl::find($subcontrolId)->users()->save($user, ['notified' => $notified, 'assigned_by' => $assigned_by]);
    }

    public function saveDocumentLink($id, $links, $docLinkChange)
    {
        $orgId = AuthService::getCurrentOrganizationId();
        $subControlLinks = SubcontrolDocumentLink::where('subcontrol_id', $id)->get();
        if (!empty($subControlLinks) && $docLinkChange == true) {
            SubcontrolDocumentLink::where('subcontrol_id', $id)->delete();
        }
        if (!empty($links)) {
            foreach ($links as $link) {
                if (!isset($link['docLabel']) && isset($link['docLink'])) {
                    $link['docLabel'] = $link['docLink'];
                }
                if (isset($link['docLink']) & isset($link['docLabel'])) {
                    $subcontrolDocLink = new SubcontrolDocumentLink;
                    $subcontrolDocLink->subcontrol_id = $id;
                    $subcontrolDocLink->document_link = $link['docLink'];
                    $subcontrolDocLink->document_label = $link['docLabel'];
                    $subcontrolDocLink->organization_id = $orgId;
                    $subcontrolDocLink->save();
                }
            }
        }
    }

    public function getSubcontrolDocumentLinks($id)
    {
        $subcontrol = Subcontrol::find($id);
        $subControlLinks = $subcontrol->documents()->where('type', 'link')->get();
        $docLinks = array();
        if (!empty($subControlLinks)) {
            foreach ($subControlLinks as $key => $link) {
                if (empty($link->title)) {
                    $docLinks[$key]['label'] = $link->link;
                } else {
                    $docLinks[$key]['label'] = $link->title;
                }
                $docLinks[$key]['id'] = $link->id;
                $docLinks[$key]['link'] = $link->link;
            }
        }
        return $docLinks;
    }

    public function saveSubcontrolVendors($id, $vendors)
    {
        $subControlVendors = SubcontrolVendor::where('subcontrol_id', $id)->get();
        if (!empty($subControlVendors)) {
            SubcontrolVendor::where('subcontrol_id', $id)->delete();
        }
        if (!empty($vendors)) {
            foreach ($vendors as $vendor) {
                $subControlVendors = new SubcontrolVendor;
                $subControlVendors->subcontrol_id = $id;
                $subControlVendors->vendor = $vendor;
                $subControlVendors->save();
            }
        }
    }
    public function getSubcontrolVendors($id, $asArray = false)
    {
        $subControlVendors = SubcontrolVendor::where('subcontrol_id', $id)->get();
        $vendors = array();
        if (!empty($subControlVendors)) {
            foreach ($subControlVendors as $vendor) {
                $vendors[] = $vendor->vendor;
            }
        }
        if (!$asArray) {
            return implode(",", $vendors);
        } else {
            return $vendors;
        }
    }
    public function createAppSubcontrols()
    {
        $apps = App::all();
        foreach ($apps as $app) {
            $subcontrols = $app->subcontrols;
            if (count($subcontrols) < 1) {
                $subcontrolTemplate = SubControlTemplate::where('app_template_id', $app->app_template_id)->get();
                if (!empty($subcontrolTemplate)) {
                    $parent_sub_control_id = "";
                    $sub_control_template_id = "";
                    foreach ($subcontrolTemplate as $temp) {
                        $subcontrol = new SubControl;
                        $subcontrol->title = $temp['name'];
                        $subcontrol->description = $temp['description'];
                        $subcontrol->app_id = $app->id;
                        if (!empty($parent_sub_control_id) && $subcontrolTemplate->parent_id == $sub_control_template_id) {
                            $subcontrol->parent_sub_control_id = $parent_sub_control_id;
                        }
                        $subcontrol->sub_control_template_id = $temp['id'];
                        if (isset($temp['additional_guidance'])) {
                            $subcontrol->additional_guidance = $temp['additional_guidance'];
                        }
                        $subcontrol->save();
                        if (empty($subcontrolTemplate->parent_id)) {
                            $parent_sub_control_id = $subcontrol->id;
                            $sub_control_template_id = $subcontrolTemplate->id;
                        }
                    }
                }
            }
        }
    }

    public function doesSubcontrolBelongToCurrentOrganization($subcontrolId)
    {

        $orgId = AuthService::getCurrentOrganizationId();

        $subcontrol = SubControl::find($subcontrolId);
        $app = App::find($subcontrol['app_id']);
        $program = Program::find($app['program_id']);
        if (!empty($program->organization_id) && $program->organization_id == $orgId) {
            return true;
        }
        return false;
    }

    public function getSubcontrolDataForReport($programId)
    {
        $program     = Program::find($programId);
        $framework   = FrameworkService::getFrameworkDetails($program->framework_id);

        $frameworkId = $program->framework_id;
        
        $subControlData = App::leftJoin('program', 'program.id', '=', 'app.program_id')
            ->leftJoin('framework', 'framework.id', '=', 'program.framework_id')
            ->leftJoin('sub_control', 'sub_control.app_id', '=', 'app.id')
            ->leftJoin('sub_control_user_map', 'sub_control_user_map.sub_control_id', '=', 'sub_control.id')
            ->leftJoin('user', 'sub_control_user_map.user_id', '=', 'user.id')
            ->where('framework.id', '=', $frameworkId)
            ->where('app.program_id', '=', $programId)
            ->where('app.status', '=', 1);

        //framework has multiple levels
        if (!empty($framework) && $framework->has_multiple_levels && !empty($program->level)) {
            $currentLevel = $program->level;
            $subControlTemplateIds = SubControlTemplateLevel::whereIn('level', [$currentLevel])->pluck('sub_control_template_id');

            $subControlData->whereIn('sub_control.sub_control_template_id', $subControlTemplateIds);
        }

        $subControlData = $subControlData->selectRaw('framework.id as framework_id, framework.name AS framework_name,'
                . 'app.id AS app_id, app.name As app_name,'
                . 'sub_control.id AS sub_control_id, sub_control.title AS sub_control_title,'
                . 'CASE WHEN sub_control.is_recurring_initiative = 0 THEN " " ELSE "Recurring" END AS is_recurring,'
                . 'user.first_name  AS user_first_name,user.last_name  AS user_last_name,'
                . 'CASE WHEN sub_control.is_disable = 1 then "N/A" else sub_control.progress END AS sub_control_score,
                sub_control.recurring_period AS recurring_period,sub_control.notes AS notes,sub_control.start_date AS start_date,
                sub_control.end_date AS end_date')
            ->groupBy('sub_control.id')
            ->get();
       
        $subcontrolid = [];
        foreach ($subControlData as $data) {
            $subcontrolid[] = $data['sub_control_id'];
        }

        $assigned_users = DB::table('sub_control_user_map')
            ->leftJoin('user', 'user.id', '=', 'sub_control_user_map.user_id')
            ->whereIn('sub_control_user_map.sub_control_id', $subcontrolid)
            ->get(['sub_control_user_map.sub_control_id as sub_control_id', 'user.first_name as first_name', 'user.last_name as last_name']);
        
        foreach ($subControlData as &$data) {
            $user = '';
            foreach ($assigned_users as $users) {
                if ($users->sub_control_id == $data['sub_control_id']) {
                    if (empty($user)) {
                        $user = $users->first_name . " " . $users->last_name;
                    } else {
                        $user = $user . ', ' . $users->first_name . " " . $users->last_name;
                    }
                }
            }
            $data['assignedto'] = $user;
        }

        return $subControlData;
    }

    public function saveMappedSubControls($subcontrol, $orgId = null, $currentUser = null)
    {
        $subcontrolId = $subcontrol->id;
        // Log::debug('Going to find domimant control and update for sub control: ' . $subcontrolId);

        if (!$orgId) {
            $orgId = AuthService::getCurrentOrganizationId();
        }

        if (!$currentUser) {
            $currentUser  = AuthService::getCurrentUser();
        }

        $existingSubcontrol = $subcontrol;

        $subControlsForMappings = SubControlService::getMappedSubControls($subcontrolId);

        if ($subControlsForMappings) {
            $mappingSubControlId = null;
            $mappingAppId = null;
            $progress = 0;

            $subcontrolsToBeUpdated = [];
            
            if (!$subControlsForMappings->isEmpty()) {
                array_push($subcontrolsToBeUpdated, $subcontrolId);
                foreach ($subControlsForMappings as $subControlMapping) {
                    if ($progress < $subControlMapping->progress || ($progress > 0 && $progress == $subControlMapping->progress)) {
                        $progress = $subControlMapping->progress;
                        $mappingSubControlId = $subControlMapping->sub_control_id;
                        $mappingAppId = $subControlMapping->app_id;
                    }
                    array_push($subcontrolsToBeUpdated, $subControlMapping->sub_control_id);
                }
            }

            $subcontrolsToBeUpdated = array_unique($subcontrolsToBeUpdated);

            $allUsers = DB::table('sub_control_user_map')->whereIn('sub_control_id', $subcontrolsToBeUpdated)->pluck('user_id')->toArray();
            $allUsers = array_unique($allUsers);

            $assginedUsers = [];
            foreach ($allUsers as $user) {
                $assginedUsers[] = ['id' => $user];
            }

            $user = $currentUser;
            /**
            * Subcontrols Documents Mapped Here 
            */
            $documentsMapped = Document::join('document_sub_controls', function ($join) use($subcontrolsToBeUpdated) {
                $join->on('document.id', '=', 'document_sub_controls.document_id');
                $join->whereIn('document_sub_controls.sub_control_id', $subcontrolsToBeUpdated);
            })->get();

            // $oldDocumentsMapped = DocumentSubcontrol::whereIn('sub_control_id', $subcontrolsToBeUpdated)->get();

            $uniqueMappedDocs = [];
            foreach ($documentsMapped as $doc) {
                $uniqueMappedDocs[$doc->file_path] = $doc;
            }

            $allTasks = Task::with(['document'])->whereIn('sub_control_id', $subcontrolsToBeUpdated)->get();

            $allTaskIds = [];
            foreach ($allTasks as $t) {
                $allTaskIds[] = $t->id;
            }

            $allRelations = DB::table('task_related_map')->whereIn('task_id', $allTaskIds)->get();

            $taskRelations = [];
            foreach ($allTasks as $task) {
                $relatedIds = [];
                foreach ($allRelations as $r) {
                    if ($r->task_id == $task->id) {
                        $relatedIds[] = $r->related_task_id;
                    }
                }

                $taskRelations[$task->id] = $relatedIds;
            }

            $existingTaskRelations = $taskRelations;

            if ($mappingAppId && $mappingSubControlId) {
                $dominantSubcontrolData = $this->getSubControlDetails($mappingAppId, $mappingSubControlId, $source = 0, false, null, $user);
                $dominantSubControlActivities = $dominantSubcontrolData->subControlActivities()->pluck('control_activity_id')->toArray();
            }

            $subControls = SubControl::whereIn('id', $subcontrolsToBeUpdated)->get();

            foreach ($subControls as $subControl) {
                
                if ($mappingAppId && $mappingSubControlId) {
                
                    $sub = $subControl->id;

                    if ($sub != $mappingSubControlId) {

                        $dominantSubcontrolData->title                   = $subControl->title;
                        $dominantSubcontrolData->sub_control_template_id = $subControl->sub_control_template_id;
                        $dominantSubcontrolData->assigned_by             = $user->id;
                        $dominantSubcontrolData->is_subcontrol_disabled  = $dominantSubcontrolData->is_disable;
                        $dominantSubcontrolData->is_disable_note         = $dominantSubcontrolData->is_disable_note;
                        $dominantSubcontrolData->app_id                  = $subControl->app_id;
                        $dominantSubcontrolData->resources               = $assginedUsers;
                        $dominantSubcontrolData->mapping                 = true;
                        
                        SubControlService::saveSubControl($subControl->id, $dominantSubcontrolData, true, true, $user);
                        
                        if ($dominantSubcontrolData->history->isNotEmpty()) {
                            $subControl->scoringHistory()->delete();

                            $scoringHistories = [];
                            foreach ($dominantSubcontrolData->history as $key => $history) {
                                $scoringhistory = [
                                    'sub_control_id' => $subControl->id,
                                    'scored_by' => $history->scored_by,
                                    'old_value' => $history->old_value,
                                    'new_value' => $history->new_value,
                                    'created_at' => $history->created_at,
                                    'updated_at' => $history->updated_at
                                ];

                                array_push($scoringHistories, $scoringhistory);
                            }

                            if (!empty($scoringHistories)) {
                                ScoringHistory::insert($scoringHistories);
                            }
                        }

                        //add control activities
                        $subControl->subControlActivities()->detach();
                        $subControl->subControlActivities()->attach($dominantSubControlActivities);

                        if (!$dominantSubcontrolData->risks->isEmpty()) {
                            $existingRisk = $dominantSubcontrolData->risks[0];
                            $oldRating = SubcontrolRiskRating::where('sub_control_id', $sub)->first();
                           
                            if (empty($oldRating)) {
                                $riskRating = new SubcontrolRiskRating;
                            } else {
                                $riskRating = $oldRating;
                            }

                            $riskRating->sub_control_id = $sub;
                            $riskRating->current_control = $existingRisk['current_control'];
                            $riskRating->remediation_plan = $existingRisk['remediation_plan'];
                            $riskRating->likelyhood = $existingRisk['likelyhood'];
                            $riskRating->impact = $existingRisk['impact'];
                            $riskRating->save();
                        } else {
                            $subControl->riskRatings()->delete();
                        }
                    }
                }

                if (!$allTasks->isEmpty()) {
                    //Log::debug('Going to sync subcontrol task for subcontrol ID: ' . $subControl->id);
                    $existingTaskIds = [];
                    foreach ($allTasks as $task) {
                        if ($task->sub_control_id == $subControl->id) {
                            $existingTaskIds[] = $task->id;
                        }
                    }

                    //Log::debug('Existings task IDs: ' . implode(', ', $existingTaskIds));
                    foreach ($allTasks as $mappedTask) {
                        $found = false;
                        $taskIdsForLookUp = array_merge($taskRelations[$mappedTask->id], [$mappedTask->id]);

                        foreach ($taskIdsForLookUp as $id) {
                            if (in_array($id, $existingTaskIds)) {
                                $found = true;
                                break;
                            }
                        }

                        if (!$found) {
                            $mappedNotes = $mappedTask->notes()->get();
                            $users = $task->users()->get()->toArray();
                            $mappedTask = $mappedTask->toArray();
                            $mappedTask = TaskService::fixTaskData($mappedTask);
                            
                            $mappedTask['users'] = $users;
                            $mappedTask['assigned_by'] = $user->id;

                            $mappedTask['assigned_by'] = $user->id;
                            $mappedTask['linked_notes'] = [];
                            foreach ($mappedNotes as $mappedNote) {
                                $note = [];
                                $note['note'] = $mappedNote->note;
                                $mappedTask['linked_notes'][] = $note;
                            }

                            /**
                            * Task documents mapped here 
                            */
                            if (isset($mappedTask['document'])) {
                                $mappedTask['documentIds'] = array_column($mappedTask['document'], 'id');
                            }

                            Log::debug('Going to insert task: ' . $mappedTask['id']);
                            $returnData = TaskService::saveTask($subControl->id, null, $mappedTask, true);

                            $newTaskId = $returnData['task_id'];
                            $existingTaskIds[] = $newTaskId;
                            
                            foreach ($taskIdsForLookUp as $t) {
                                $taskRelations[$t][] = $newTaskId;
                            }

                            $taskRelations[$newTaskId] = $taskIdsForLookUp;
                        }
                    }
                }

                if (!$documentsMapped->isEmpty()) {
                    $documents = [];

                    foreach ($uniqueMappedDocs as $existingDocument) {
                        $found = false;
                        foreach ($documentsMapped as $doc) {
                            if (
                                $existingDocument->file_path == $doc->file_path
                                && $subControl->id == $doc->sub_control_id
                            ) {
                                $found = true;
                                break;
                            }
                        }

                        if (!$found) {
                            $document = new Document();
                            $document->title = $existingDocument['title'];
                            $document->file_path = $existingDocument['file_path'];
                            $document->organization_id = $orgId;
                            $document->save();

                            $document->subcontrolDocuments()->create([
                                'sub_control_id' => $subControl->id,
                                'user_id' => $user->id
                            ]);
                        }
                    }
                }
            }

            $insertData = [];
            foreach ($taskRelations as $taskId => $relatedIds) {
                $diff = array_diff($relatedIds, isset($existingTaskRelations[$taskId]) ? $existingTaskRelations[$taskId] : []);
                foreach ($diff as $v) {
                    if ($v) {
                        $insertData[] = ['task_id' => $taskId, 'related_task_id' => $v];
                    }
                }
            }

            if (!empty($insertData)) {
                DB::table('task_related_map')->insert($insertData);
            }
        }
    }

    public function updateWithMappedData($subControlId, $mappedSubControlId)
    {
        $user = AuthService::getCurrentUser();

        $mappedSubControl      = SubControl::find($mappedSubControlId);
        $mappedSubControlData  = $this->getSubControlDetails($mappedSubControl['app_id'], $mappedSubControlId);
        $subControlToBeUpdated = SubControl::find($subControlId);
        
        $mappedSubControlData->title = $subControlToBeUpdated->title;
        $mappedSubControlData->sub_control_template_id = $subControlToBeUpdated->sub_control_template_id;
        $mappedSubControlData->assigned_by = $user->id;
        $mappedSubControlData->is_subcontrol_disabled = $mappedSubControlData->is_disable;
        $mappedSubControlData->is_disable_note = $mappedSubControlData->is_disable_note;
        $mappedSubControlData->app_id = $subControlToBeUpdated->app_id;
        $mappedSubControlData->resources = $mappedSubControlData->assigned_users;
        $mappedSubControlData->mapping = true;

        SubControlService::saveSubControl($subControlId, $mappedSubControlData, true, true, $user);
        
        if (!$mappedSubControlData->history->isEmpty()) {
            foreach ($mappedSubControlData->history as $key => $history) {
                $scoringhistory = new ScoringHistory();
                $scoringhistory->sub_control_id = $subControlId;
                $scoringhistory->scored_by = $history->scored_by;
                $scoringhistory->old_value = $history->old_value;
                $scoringhistory->new_value = $history->new_value;
                $scoringhistory->created_at = $history->created_at;
                $scoringhistory->updated_at = $history->updated_at;
                $scoringhistory->save();
            }
        }

    
        //copy document/links associations
        if (!empty($mappedSubControl->subcontrolDoc)) {
            foreach ($mappedSubControl->subcontrolDoc as $key => $doc) {
                $link = DocumentSubcontrol::where('sub_control_id', $subControlId)->where('document_id', $doc->document_id)->first();
                if (empty($link)){
                    $link = new DocumentSubcontrol();
                    $link->document_id = $doc->document_id;
                    $link->sub_control_id = $subControlId;
                    $link->user_id = $doc->user_id;
                    $link->created_at = Carbon::now();
                    $link->updated_at = Carbon::now();

                    $link->save();
                }
            }
        }


        if (!$mappedSubControlData->risks->isEmpty()) {
            $existingRisk = $mappedSubControlData->risks[0];
            $oldRating = SubcontrolRiskRating::where('sub_control_id', $subControlId)->first();
            if (empty($oldRating)) {
                $riskRating = new SubcontrolRiskRating;
            } else {
                $riskRating =  $oldRating;
            }
            $riskRating->sub_control_id = $subControlId;
            $riskRating->current_control = $existingRisk['current_control'];
            $riskRating->remediation_plan = $existingRisk['remediation_plan'];
            $riskRating->likelyhood = $existingRisk['likelyhood'];
            $riskRating->impact = $existingRisk['impact'];
            $riskRating->save();
        }

        if (!$mappedSubControlData->tasks->isEmpty()) {
            $taskIds = [];
            foreach ($mappedSubControlData->tasks as $task) {
                $taskIds[] = $task->id;
            }
            $allRelations = DB::table('task_related_map')->whereIn('task_id', $taskIds)->get();
            foreach ($mappedSubControlData->tasks as $task) {
                $mappedTask =  new Task();
                $mappedTask->title = $task->title;
                $mappedTask->sub_control_id = $subControlId;
                $mappedTask->subject = $task->subject;
                $mappedTask->create_alert = $task->create_alert;
                $mappedTask->status =  TaskService::setStatus($task->status);
                $mappedTask->due_date = $task->due_date;
                if ($task->priority) {
                    $mappedTask->priority = $task->priority;
                }

                if ($task->is_recurring_initiative) {
                    $mappedTask->is_recurring_initiative = $task->is_recurring_initiative;
                    $mappedTask->recurring_period = $task->recurring_period;
                    $mappedTask->occurance_limit = $task->occurance_limit;
                    $mappedTask->reccur_pattern = $task->reccur_pattern;
                    $mappedTask->reccur_day = $task->reccur_day;
                    $mappedTask->reccur_week = $task->reccur_week;
                    $mappedTask->reccur_month = $task->reccur_month;
                    $mappedTask->reccur_number_of_months = $task->reccur_number_of_months;
                }

                //if task has no subject, dont save
                if (empty($mappedTask->subject)){
                    continue;
                }
                $mappedTask->save();
                $mappedTaskId = $mappedTask->id;
                $relatedIds = [];
                foreach ($allRelations as $r) {
                    if ($r->task_id == $task->id) {
                        $relatedIds[] = $r->related_task_id;
                    }
                }
                $newRelations = [
                    ['task_id' => $mappedTaskId, 'related_task_id' => $task->id],
                    ['task_id' => $task->id, 'related_task_id' => $mappedTaskId]
                ];
                foreach ($relatedIds as $rid) {
                    $newRelations[] = ['task_id' => $mappedTaskId, 'related_task_id' => $rid];
                    $newRelations[] = ['task_id' => $rid, 'related_task_id' => $mappedTaskId];
                }
                $insertData = [];
                foreach ($newRelations as $rel) {
                    $insertData[] = ['task_id' => $rel['task_id'], 'related_task_id' => $rel['related_task_id']];
                }
                if (!empty($insertData)) {
                    DB::table('task_related_map')
                        ->insert($insertData);
                }

                if (!$task->users->isEmpty()) {
                    foreach ($task->users as $user) {
                        $notify = 0;
                        if ($mappedTask->due_date == Carbon::now()->addDays(30)) {
                            $notify = 1;
                        }
                        if ($mappedTask->due_date == Carbon::now()->addDays(7)) {
                            $notify = 2;
                        }
                        $mappedTask->users()->save($user, ['assigned_by' => $user->id, 'notified' => $notify]);
                    }
                }

                if ($task->occurences) {
                    foreach ($task->occurences as $occurence) {
                        $newTaskOccurence = new TaskOccurrence();
                        $newTaskOccurence->task_id = $mappedTaskId;
                        $newTaskOccurence->occurrence = $occurence->occurrence;
                        $newTaskOccurence->due_date = $occurence->due_date;
                        $newTaskOccurence->status = $occurence->status;
                        $newTaskOccurence->notified = $occurence->notified;
                        $newTaskOccurence->save();
                    }
                }

                if (!$task->notes->isEmpty()) {
                    foreach ($task->notes as $note) {
                        $noteData['task_id'] = $mappedTaskId;
                        $noteData['note'] = $note['note'];
                        TaskService::saveTaskNotes(null, $noteData);
                    }
                }
            }
        }
    }

    public function getMappedSubControls($subControlId)
    {
        $existingSubcontrol = SubControl::with('app.program')->find($subControlId);
        $currentProgram = $existingSubcontrol->app()->first()->program()->first();
        $orgId = $currentProgram->organization_id;
        $currentProgramId = $currentProgram->id;
      
        $mappedPrograms = DB::table('program_framework_map as pfm')
            ->leftJoin('program as p1', 'p1.id', '=', 'pfm.program_id')
            ->leftJoin('program as p2', function ($join) use ($orgId) {
                $join->on('p2.framework_id', '=', 'pfm.framework_id');
                $join->on('p2.organization_id', '=', DB::raw($orgId));
            })
            ->whereIn('pfm.program_id', function ($query) use ($orgId) {
                $query->select('id')
                    ->from(with(new Program)->getTable())
                    ->where('organization_id', $orgId);
            })
            ->select(['p1.id as p1_id', 'p2.id as p2_id'])
            ->get();

        $mappedProgramIds = [];
        foreach ($mappedPrograms as $m) {
            if (!empty($m->p1_id)) {
                $mappedProgramIds[] = $m->p1_id;
            }
            if (!empty($m->p2_id)) {
                $mappedProgramIds[] = $m->p2_id;
            }
        }

        //Log::debug('Mapped program IDs: ' . implode(', ', $mappedProgramIds));
        if (count($mappedProgramIds) == 0) {
            return false;
        }

        if (!in_array($currentProgramId, $mappedProgramIds)) {
            //Log::debug('Current program is not a mapped one');
            return false;
        }

        $subControlTemplateMapData = SubControlTemplateMap::whereIn('sub_control_template_id', function ($query) use ($existingSubcontrol) {
            $query->select('sub_control_template_id')
            ->from(with(new SubControlTemplateMap)->getTable())
            ->whereIn('child_sub_control_template_id', function ($query1) use ($existingSubcontrol) {
                $query1->select('child_sub_control_template_id')
                    ->from(with(new SubControlTemplateMap)->getTable())
                    ->whereIn('sub_control_template_id', function ($query2) use ($existingSubcontrol) {
                        $query2->select('sub_control_template_id')
                            ->from(with(new SubControlTemplateMap)->getTable())
                            ->where('child_sub_control_template_id', $existingSubcontrol->sub_control_template_id);
                    })
                    ->orWhere('sub_control_template_id', $existingSubcontrol->sub_control_template_id);
            });
        })->get();

        $mappedSubControlTemplateIds = [];
        foreach ($subControlTemplateMapData as $templateMap) {
            $mappedSubControlTemplateIds[] = $templateMap['sub_control_template_id'];
            $mappedSubControlTemplateIds[] = $templateMap['child_sub_control_template_id'];
        }

        $mappedSubControlTemplateIds = array_unique($mappedSubControlTemplateIds);
        $mappedSubControls = SubControl::join('app', 'app.id', '=', 'sub_control.app_id')
            ->join('program', 'program.id', '=', 'app.program_id')
            ->whereIn('sub_control.sub_control_template_id', $mappedSubControlTemplateIds)
            ->where('program.organization_id', $orgId)
            //->whereIn('program.id', $mappedProgramIds)
            ->get(['sub_control.id as sub_control_id', 'sub_control.progress as progress', 'sub_control.app_id as app_id']);

        $tmp = '';
        foreach ($mappedSubControls as $m) {
            $tmp .= $m->sub_control_id . '|';
        }
       
        if (!$mappedSubControls->isEmpty()) {
            return $mappedSubControls;
        }

        return false;
    }

    public function colorCodeCalculationForRiskRating($data)
    {
        $ratingOption = '';
        if (isset($data['likelyhood']) && !empty($data['likelyhood']) && isset($data['impact']) && !empty($data['impact'])) {
            if ($data['likelyhood'] == 'Negligible' || $data['likelyhood'] == 'Low') {
                if (($data['impact'] == 'Minor' && $data['likelyhood'] == 'Low') || ($data['impact'] == 'Moderate') || ($data['impact'] == 'Major' && $data['likelyhood'] == 'Negligible')) {
                    $ratingOption = 'Low Risk';
                } else if (($data['impact'] == 'Minimal') || ($data['impact'] == 'Minor' && $data['likelyhood'] == 'Negligible')) {
                    $ratingOption = 'Negligible Risk';
                } else if (($data['impact'] == 'Major' && $data['likelyhood'] == 'Low') || ($data['impact'] == 'Catastrophic' && $data['likelyhood'] == 'Negligible')) {
                    $ratingOption = 'Moderate Risk';
                } else if ($data['impact'] == 'Catastrophic' && $data['likelyhood'] == 'Low') {
                    $ratingOption = 'High Risk';
                }
            } else if ($data['likelyhood'] == 'Moderate' || $data['likelyhood'] == 'High') {
                if ($data['likelyhood'] == 'Moderate') {
                    if ($data['impact'] == 'Catastrophic' || $data['impact'] == 'Major') {
                        $ratingOption = 'High Risk';
                    } else if ($data['impact'] == 'Moderate') {
                        $ratingOption = 'Moderate Risk';
                    } else {
                        $ratingOption = 'Low Risk';
                    }
                }
                if ($data['likelyhood'] == 'High') {
                    if ($data['impact'] == 'Catastrophic') {
                        $ratingOption = 'Excessive Risk';
                    } else if ($data['impact'] == 'Moderate' || $data['impact'] == 'Major') {
                        $ratingOption = 'High Risk';
                    } else if ($data['impact'] == 'Minor') {
                        $ratingOption = 'Moderate Risk';
                    } else {
                        $ratingOption = 'Low Risk';
                    }
                }
            } else {
                if ($data['impact'] == 'Catastrophic' || $data['impact'] == 'Major') {
                    $ratingOption = 'Excessive Risk';
                } else if ($data['impact'] == 'Minimal') {
                    $ratingOption = 'Moderate Risk';
                } else {
                    $ratingOption = 'High Risk';
                }
            }
        }

        return $ratingOption;
    }

    public function riskRatingSortOrder($ratingOption)
    {
        if ($ratingOption == 'Excessive Risk') {
            return RiskRatingOption::EXCESSIVE;
        } else if ($ratingOption == 'High Risk') {
            return RiskRatingOption::HIGH;
        } else if ($ratingOption == 'Moderate Risk') {
            return RiskRatingOption::MODERATE;
        } else if ($ratingOption == 'Low Risk') {
            return RiskRatingOption::LOW;
        } else if ($ratingOption == 'Negligible Risk') {
            return RiskRatingOption::NEGLIGIBLE;
        } else {
            return RiskRatingOption::NONE;
        }
    }

    public function getSubControlWithVendors($programId)
    {

        $data = DB::select("
        select f.name 'framework', a.name 'app_name', sub.title 'subcontrol', v.vendor  from program p
            join framework f on f.id = p.framework_id
            join app a on a.program_id = p.id
            join sub_control sub on a.id = sub.app_id
            join subcontrol_vendor v on v.subcontrol_id = sub.id
        where a.program_id=:program_id
        order by a.name, sub.title, v.vendor", ['program_id'=> $programId]);
        return $data;
    }


    public function saveSubControlComment($input)
    {
        $assigned_by = AuthService::getCurrentUser();

        if (!empty($input['mentionIds'])) {

            $subcontrol = Subcontrol::find($input['sub_control_id']);
            $orgId      = AuthService::getCurrentOrganizationId();
            $org        = OrganizationService::getOrganizationDetails($orgId);

            foreach ($input['mentionIds'] as $key => $value) {
                if(!$user->email_comments || !$user->email_enabled){
                    continue;
                }
                $user   =  UserService::getUserDetails($value);
                $notify = [];
                $notify['user_id'] = $value;
                $notify['subject'] = 'Subcontrol Comment';
                $notify['message'] = '<a href="{url}">'.$input['notes'].'</a>';
                $notify['from']    = $assigned_by->name;
                $notify['url']     = 'app-view/'.$subcontrol->app_id.'?subcontrol='.$subcontrol->id;
                $notify['read']    = 0;
                $notify['category']= 'subcontrols';

                // create notification
                dispatch(new UserNotification($notify));

                $currentApp = $subcontrol->app()->first();
                $currentProgram = $currentApp->program()->first();
                $currentFramework = $currentProgram->framework()->first();


                $notify['assigned_by']  = $assigned_by->name;
                $notify['to']           = $user->name;
                $notify['deep_link']    = $user->platform_url.'/app-view/'.$subcontrol->app_id.'?subcontrol='.$subcontrol->id;
                $notify['organization'] = (!empty($user->partner_id) && $user->partner_id != null) ? $org->name : "";
                $notify['subcontrol']   = $subcontrol->title;
                $notify['framework_name'] = (isset($currentFramework->name)) ? $currentFramework->name : "";
                $notify['app_name'] = (isset($currentApp->name)) ? $currentApp->name : "";;
                $notify['message']      = $input['notes'];


                //send mail who have assigned in comment
                Mail::to($user->email)->queue(new AssignComment($notify));
            }
        }

        unset($input['mentionIds']);
        $comment = SubcontrolComment::updateOrCreate(['id' => $input['id']], $input);
        return true;
    }

    public function getAllComments($subControlId)
    {
        $subcontrol = Subcontrol::find($subControlId);
        $comments =  $subcontrol->comments()->with('user')->get();
        return $comments->sortByDesc('id')->values();
    }

    public function deleteComment($id, $user) {
        $removeComment =  SubcontrolComment::where('id', $id)->where('user_id', $user->id)->first();
        $subcontrolId =  $removeComment->sub_control_id;

        $removeComment->delete();

        $subControlsForMappings = SubControlService::getMappedSubControls($subcontrolId);


        if ($subControlsForMappings) {
            $subcontrol = Subcontrol::find($subcontrolId);

            foreach ($subControlsForMappings as $mappedSubControl) {
                if ($mappedSubControl['sub_control_id'] != $subcontrolId) {

                    $mappedSubControl = SubControl::find($mappedSubControl['sub_control_id']);

                    //delete all comments, then sync up
                    $mappedSubControl->comments()->delete();
                     //sync comments to mapped controls
                     $comments = $subcontrol->comments()->get();
                     if (!$comments->isEmpty()) {
                         foreach ($comments as $comment) {
                             $com = new SubcontrolComment();
                             $com->sub_control_id = $mappedSubControl->id;
                             $com->notes = $comment->notes;
                             $com->user_id = $comment->user_id;
                             $com->created_at = $comment->created_at;
                             $com->updated_at = $comment->updated_at;
                             $com->save();

                         }
                     }

                }
            }
        }

        if($removeComment) {
            return true;
        }

        return false;
    }

    public function getFilterSubControls($input) {

        $programId = ProgramService::getCurrentUserProgramId();
        $apps      = AppService::getAppsList($programId)->all();

        $orgId = AuthService::getCurrentOrganizationId();
        $data  = SubControl::whereIn('app_id', array_column($apps, 'id'))->get();

        if(isset($input['app_ids']) && !empty($input['app_ids'])) {
            if (is_array($input['app_ids'])) {
                $data = SubControl::whereIn('app_id', $input['app_ids'])->get();
            }else{
                $data = SubControl::where('app_id', $input['app_ids'])->get();
            }
        }

        return $data;
    }

    public function saveSubControl($id, $input, $skipEmails = false, $skipHistory = false, $currentUser = null)
    { 
        $data = array();
        $existingSubcontrol = SubControl::with('app.program')->find($id);
        $currentProgram = $existingSubcontrol->app()->first()->program()->first();
        $orgId = $currentProgram->organization_id;
        $currentProgramId = $currentProgram->id;
        $mappedProgramFrameworks = $currentProgram->frameworks()->get();
        $mappedProgramFrameworkIds = [];

        if (!$currentUser) {
            $currentUser  = AuthService::getCurrentUser();
        }

        $permissions = '';
        if (empty($currentUser->role_id)) {
            $partnerOrganizationRole = AuthService::getPartnerOrganizationRole($orgId, $currentUser->id);
            $permissions = $partnerOrganizationRole->permissions;
        } else {
            $permissions = UserService::getUserPermissions($currentUser->id);
        }

        $subControlsForMappings = [];
        if (!isset($input['mapping'])) {
            $subControlsForMappings = SubControlService::getMappedSubControls($id);
        }

        if (!empty($input['document_link_to_delete'])) {
            $documentIDsTobeDeleted = [];
            $mappedSubcontrolIds = [];
            if ($subControlsForMappings) {
                foreach ($subControlsForMappings as $mappedSubControl) {
                    array_push($mappedSubcontrolIds, $mappedSubControl['sub_control_id']);
                }
                foreach ($input['document_link_to_delete'] as $id) {
                    $currentDocumetLink = DocumentSubcontrol::select('title', 'link')->where('document_sub_controls.id', $id)->leftJoin('documents', 'documents.id', '=', 'document_sub_controls.document_id')->first();
                    //$currentDocumetLink = SubcontrolDocumentLink::where('id', $id)->first();
                    $documetLabel = $currentDocumetLink["title"];
                    $documetLink = $currentDocumetLink["link"];


                    //$documentLinkIDs = SubcontrolDocumentLink::select('id')->whereIn('subcontrol_id', $mappedSubcontrolIds)->where('document_label', $documetLabel)->where('document_link', $documetLink)->get()->pluck('id')->toArray();

                    $documentLinkIDs = DocumentSubcontrol::select('document_sub_controls.id')->leftJoin('documents', 'documents.id', '=', 'document_sub_controls.document_id')->whereIn('document_sub_controls.sub_control_id', $mappedSubcontrolIds)->where('documents.title', $documetLabel)->where('documents.link', $documetLink)->get()->pluck('document_sub_controls.id')->toArray();
                    $documentIDsTobeDeleted[] = $documentLinkIDs;
                    unset($documentLinkIDs);
                }
                DocumentSubcontrol::whereIn('id', array_collapse($documentIDsTobeDeleted))->delete();
            } else {
                DocumentSubcontrol::whereIn('id', $input['document_link_to_delete'])->delete();
            }
        }

        $resetAllUsers = 0;
        if ($id) {
            $subcontrol = SubControl::find($id);
            $subcontrol->users()->where('assigned_by', null)->update(['assigned_by' => $input['assigned_by']]);
        }

        if (isset($input['is_subcontrol_disabled']) && $input['is_subcontrol_disabled']) {
            $input['progress'] = 0;
        }

        if (empty($subcontrol)) {
            $subcontrol = new SubControl;
        } else {
            if (isset($input['end_date'])) {
                $endDate = date('Y-m-d', strtotime($input['end_date']));
                if ($subcontrol->end_date != $endDate) {
                    $resetAllUsers = 1;
                }
            }

            if (isset($input['progress']) && !$skipHistory) {
                $scoringhistory = new ScoringHistory;
                // $currentUser = AuthService::getCurrentUser();
                $acronym = "";
                if (!empty($currentUser->first_name)) {
                    $words = explode(" ", trim($currentUser->first_name));
                    foreach ($words as $w) {
                        if (!empty($w)) {
                            $acronym .= $w[0] . '. ';
                        }
                    }
                }
                $scoringhistory->sub_control_id = $subcontrol->id;
                $scoringhistory->scored_by = strtoupper($acronym) . ucfirst($currentUser->last_name);
                $scoringhistory->old_value = $subcontrol->progress;
                $scoringhistory->new_value = $input['progress'];
                $scoringhistory->save();
            }
        }

        $subcontrol->app_id = $input['app_id'];

        if (isset($input['sub_control_template_id'])){
            $subcontrol->sub_control_template_id = $input['sub_control_template_id'];
        }

        if (isset($input['title'])){
            $subcontrol->title = $input['title'];
        }

        if (isset($input['description'])){
            $subcontrol->description = $input['description'];
        }

        if (isset($input['is_subcontrol_disabled'])){
            $subcontrol->is_disable = $input['is_subcontrol_disabled'];
        }

        if ((is_object($input) && property_exists($input, 'budget_amount')) || isset($input['budget_amount'])) {
            $subcontrol->budget_amount = $input['budget_amount'];
        }
        if ((is_object($input) && property_exists($input, 'notes')) || isset($input['notes'])) {
            $subcontrol->notes = $input['notes'];
        }
        if ((is_object($input) && property_exists($input, 'is_disable_note')) || isset($input['is_disable_note'])) {
            $subcontrol->is_disable_note = $input['is_disable_note'];
        }

        if (in_array('START_DATE_AND_END_DATE_UPDATE', $permissions)) {
            if (isset($input['is_recurring_initiative']) && !empty($input['is_recurring_initiative'])) {
                $subcontrol->is_recurring_initiative = $input['is_recurring_initiative'];
            } else {
                $subcontrol->is_recurring_initiative = "";
            }

            if (isset($input['occurance_limit']) && !empty($input['occurance_limit'])) {
                $subcontrol->occurance_limit = $input['occurance_limit'];
            } else {
                $subcontrol->occurance_limit = "";
            }

            if (isset($input['recurring_period']) && !empty($input['recurring_period'])) {
                $subcontrol->recurring_period = $input['recurring_period'];
            } else {
                $subcontrol->recurring_period = "";
            }

            $subcontrol->create_alert = ((is_array($input) && array_key_exists('create_alert', $input))) ? $input['create_alert'] : "";

            if (((is_object($input) && property_exists($input, 'start_date')) || isset($input['start_date'])) && ((is_object($input) && property_exists($input, 'end_date')) || isset($input['end_date']))) {

                if ($input['start_date'] && $input['end_date']) {
                    $subcontrol->start_date = date('Y-m-d', strtotime($input['start_date']));
                    $subcontrol->end_date = date('Y-m-d', strtotime($input['end_date']));
                } else {
                    $subcontrol->start_date = null;
                    $subcontrol->end_date = null;
                    $subcontrol->occurance_limit = null;
                    $subcontrol->recurring_period = null;
                    $subcontrol->is_recurring_initiative = null;
                }
        
            }
        }

        if (isset($input['progress'])) {
            $subcontrol->progress = $input['progress'];
        }

    
        $subcontrol->save();
        $subcontrolId = $subcontrol->id;

        //save subcontrol comments
        if(isset($input['comment_note']) && !is_null($input['comment_note'])) {
            $commentData['id'] = $input['comment_id'];
            $commentData['user_id'] = $input['assigned_by'];
            $commentData['notes'] = $input['comment_note'];
            $commentData['sub_control_id'] = $subcontrolId;
            $commentData['mentionIds'] = $input['mentionIds'];
            $this->saveSubControlComment($commentData);
        }

        // update over all progress to the parent subcontrol
        if (isset($input["update_parent"]) || $subcontrol->parent_sub_control_id != null) {
            $parentId = $subcontrol->parent_sub_control_id;
            if (!empty($parentId)) {
                $this->updateParentSubcontrol($parentId);
            }
        }

        $users = $subcontrol->users()->select('id')->get()->toArray();
       
        if (isset($input['document_link'])) {
            $this->saveDocumentLink($subcontrolId, $input['document_link'], $input['edit_document_link']);
        }

        if (isset($input['vendor'])) {
            $this->saveSubcontrolVendors($subcontrolId, $input['vendor']);
        }

        $currentUsers = array_map(function ($user) {
            return $user['id'];
        }, $users);


        if (isset($input['resources']) && !empty($input['resources'])) {
            foreach ($input['resources'] as $user) {
                $data[] = $user['id'];
            }

            if ($resetAllUsers) {
                $subcontrol->users()->detach();
                $detachUsers = null;
            } else {
                $detachUsers = array_diff($currentUsers, $data);
            }

            if (!empty($detachUsers)) {
                foreach ($detachUsers as $value) {
                    $subcontrol->users()->wherePivot('user_id', '=', $value)->detach();
                }
            }

            $newAssignedUsers = $data;
            $newAssignedUsersBeforeReset = $data;

            if (!empty($currentUsers))
                $newAssignedUsersBeforeReset = array_diff($newAssignedUsers, $currentUsers);

            if (!empty($currentUsers) && $resetAllUsers == 0)
                $newAssignedUsers = array_diff($newAssignedUsers, $currentUsers);

            if (!empty($newAssignedUsers)) {
                foreach ($newAssignedUsers as $value) {
                    $user = User::find($value);
                    $notify = 0;
                    if (!$existingSubcontrol || $resetAllUsers == 1) {
                        if ($input['end_date'] == Carbon::now()->addDays(30)) {
                            $notify = 1;
                        }
                        if ($input['end_date'] == Carbon::now()->addDays(7)) {
                            $notify = 2;
                        }
                        if ($skipEmails) {
                            $notify = 2;
                        }
                        if ($user) {
                            SubControl::find($subcontrolId)->users()->save($user, ['assigned_by' => $input['assigned_by'], 'notified' => $notify]);
                        }
                    } else {
                        if ($user) {
                            SubControl::find($subcontrolId)->users()->save($user, ['assigned_by' => $input['assigned_by']]);
                        }
                    }
                }
                if (!$skipEmails) {
                    EmailService::assignSubcontrolUsers($subcontrolId, $newAssignedUsersBeforeReset, 1);
                }
            }

            $detachUsers = array_diff($currentUsers, $data);
            $updatedUsers = array_diff($currentUsers, $detachUsers);
            
            if (!$skipEmails) {
                EmailService::assignSubcontrolUsers($subcontrolId, $updatedUsers, 0);
            }
        } else if (isset($input['resources']) && empty($input['resources'])) {
            if (!empty($currentUsers)) {
                foreach ($currentUsers as $value) {
                    $subcontrol->users()->wherePivot('user_id', '=', $value)->detach();
                }
            }
        }

        if ($subControlsForMappings) {

            foreach ($subControlsForMappings as $mappedSubControl) {

                if ($mappedSubControl['sub_control_id'] != $subcontrolId) {

                    $mappedSubControl = SubControl::find($mappedSubControl['sub_control_id']);

                    $mappedSubControl->users()->where('assigned_by', null)->update(['assigned_by' => $input['assigned_by']]);
                    if ((is_object($input) && property_exists($input, 'budget_amount')) || isset($input['budget_amount'])) {
                        $mappedSubControl->budget_amount = $input['budget_amount'];
                    }

                    if ((is_object($input) && property_exists($input, 'notes')) || isset($input['notes'])) {
                        $mappedSubControl->notes = $input['notes'];
                    }

                    $mappedSubControl->is_disable = (is_array($input) && array_key_exists('is_subcontrol_disabled', $input)) ? $input['is_subcontrol_disabled'] : false;



                    if ((is_object($input) && property_exists($input, 'is_disable_note')) || isset($input['is_disable_note'])) {
                        $mappedSubControl->is_disable_note = $input['is_disable_note'];
                    }


                    if (isset($input['is_recurring_initiative']) && !empty($input['is_recurring_initiative'])) {
                        $mappedSubControl->is_recurring_initiative = $input['is_recurring_initiative'];
                    } else {
                        $mappedSubControl->is_recurring_initiative = "";
                    }

                    if (isset($input['occurance_limit']) && !empty($input['occurance_limit'])) {
                        $mappedSubControl->occurance_limit = $input['occurance_limit'];
                    } else {
                        $mappedSubControl->occurance_limit = "";
                    }

                    if (isset($input['recurring_period']) && !empty($input['recurring_period'])) {
                        $mappedSubControl->recurring_period = $input['recurring_period'];
                    } else {
                        $mappedSubControl->recurring_period = "";
                    }
                    if (((is_object($input) && property_exists($input, 'start_date')) || isset($input['start_date'])) && ((is_object($input) && property_exists($input, 'end_date')) || isset($input['end_date']))) {

                        if ($input['start_date'] && $input['end_date']) {
                            $mappedSubControl->start_date = date('Y-m-d', strtotime($input['start_date']));
                            $mappedSubControl->end_date = date('Y-m-d', strtotime($input['end_date']));
                        } else {
                            $mappedSubControl->start_date = null;
                            $mappedSubControl->end_date = null;
                            $mappedSubControl->is_recurring_initiative = null;
                            $mappedSubControl->occurance_limit = null;
                            $mappedSubControl->recurring_period = null;
                        }
                    }

                    
                    if (isset($input['progress'])) {
                        $mappedSubControl->progress = $input['progress'];
                    }

                    $mappedSubControl->create_alert = (is_array($input) && array_key_exists('create_alert', $input)) ? $input['create_alert'] : "";
                    $mappedSubControl->save();

                    $scoringHistory = $subcontrol->scoringHistory()->get();
                    if ($scoringHistory->isNotEmpty()) {
                        
                        $mappedSubControl->scoringHistory()->delete();
                        
                        $scoringHistories = [];
                        foreach ($scoringHistory as $history) {
                            $scoringhistory = [
                                'sub_control_id' => $mappedSubControl->id,
                                'scored_by'      => $history->scored_by,
                                'old_value'      => $history->old_value,
                                'new_value'      => $history->new_value,
                                'created_at'     => $history->created_at,
                                'updated_at'     => $history->updated_at
                            ];

                            array_push($scoringHistories, $scoringhistory);
                        }

                        if (!empty($scoringHistories)) {
                            ScoringHistory::insert($scoringHistories);
                        }
                    }

                    if (isset($input['resources']) && !empty($input['resources'])) {
                        $mappedSubControl->users()->detach();
                        $assignedSubcontrolUsers = $subcontrol->users()->get();
                        
                        if (!$assignedSubcontrolUsers->isEmpty()) {
                            foreach ($assignedSubcontrolUsers as $user) {
                                $mappedSubControl->users()->save($user, ['assigned_by' => $input['assigned_by']]);
                            }
                        }
                    }

                    if (isset($input['document_link'])) {
                        $this->saveDocumentLink($mappedSubControl->id, $input['document_link'], $input['edit_document_link']);
                    }

                    if (isset($input['vendor'])) {
                        $this->saveSubcontrolVendors($mappedSubControl->id, $input['vendor']);
                    }

                    if ($mappedSubControl->parent_sub_control_id != null) {
                        $this->updateParentSubcontrol($mappedSubControl->parent_sub_control_id);
                    }

                    //sync comments to mapped controls
                    $comments = $subcontrol->comments()->get();
                    if (!$comments->isEmpty()) {
                        $mappedSubControl->comments()->delete();
                        $saveComments = [];
                        foreach ($comments as $comment) {
                            $singleComment                   = [];
                            $singleComment['sub_control_id'] = $mappedSubControl->id;
                            $singleComment['notes']          = $comment->notes;
                            $singleComment['user_id']        = $comment->user_id;
                            $singleComment['created_at']     = $comment->created_at;
                            $singleComment['updated_at']     = $comment->updated_at;

                            array_push($saveComments, $singleComment);
                        }

                        if (!empty($saveComments)) {
                            SubcontrolComment::insert($saveComments);
                        }
                    }

                }
            }
        }
        return true;
    }
}
