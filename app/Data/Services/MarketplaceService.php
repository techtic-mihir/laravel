<?php

namespace App\Data\Services;

use App\Data\Entities\ControlActivity;
use App\Data\Entities\SubControl;
use App\Data\Entities\Program;
use App\Data\Entities\Framework;
use App\Data\Entities\Product;
use App\Data\Entities\App;
use App\Data\Entities\UserMarketplaceSolution;
use App\Data\Entities\MarketplaceLeads;
use App\Data\Entities\Marketplace\Category;
use App\Data\Entities\Marketplace\Company;
use App\Data\Entities\Marketplace\SolutionType;
use App\Data\Entities\Marketplace\Solution;
use App\Data\Entities\Marketplace\User as MarketplaceUser;
use App\Data\Entities\SubControlTemplateLevel;
use App\Mail\GetQuoteMarketplaceEmail;
use Mail;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

use Facades \ {
    App\Data\Services\AppService,
    App\Data\Services\ProgramService,
    App\Data\Services\SubControlService,
    App\Data\Services\FrameworkService,
    App\Data\Services\AuthService
};

class MarketplaceService
{
    public function getMarketplaceFilters($filters = [], $programId = null)
    {
        $apps          = [];
        $subcontrols   = [];
        $categoriesIds = [];
        $types         = [];
        $providers     = [];

        $results = $this->getMarketplaceResults($filters, $programId, true);

        foreach ($results['results'] as $result) {
            foreach ($result['subcontrols'] as $subcontrol) {
                if (count($subcontrol['solutions']) > 0) {
                    array_push($categoriesIds, $subcontrol['marketplace_category_id']);
                }
            }

            $solutions     = array_column($result['subcontrols'], 'solutions');
            $solutions     = collect($solutions)->collapse();

            $types[]       = array_column($solutions->toArray(), 'type');
            $providers[]   = array_column($solutions->toArray(), 'company');

            $subcontrols[] = array_values($result['subcontrols']);
            unset($result['subcontrols']);
            $apps[] = $result;
        }

        //appends categroy and provider
        foreach ($results['appends'] as $append) {
            foreach ($append['maped_solutions'] as $solution) {
                array_push($categoriesIds, $solution['category_id']);
                $providers[] = collect([])->push($solution['company'])->toArray();
                $types[] = collect([])->push($solution['type'])->toArray();
            }
        }

        $subcontrols  = collect($subcontrols)->collapse()->values();

        $typesIds     = collect($types)->collapse()->unique('id')->pluck('id');
        $providersIds = collect($providers)->collapse()->unique('id')->pluck('id');

        $categories = Category::withCount(['solutions'])
                        ->whereIn('id', $categoriesIds)
                        ->orWhereIn('slug', ['Cyber Insurance', 'Consulting Firm', 'Audit Firm'])
                        ->orderBy('name', 'ASC')
                        ->get();

        $types = SolutionType::whereIn('id', $typesIds)->where('name', '!=', 'Other')->orderBy('name', 'ASC')->get();
        $providers  = Company::whereIn('id', $providersIds)->orderBy('name', 'ASC')->get();

        return [
            'categories'  => $categories,
            'apps'        => $apps,
            'subcontrols' => $subcontrols,
            'types'       => $types,
            'providers'   => $providers
        ];
    }

    public function getMarketPlaceQuery($programId)
    {
        $apps = DB::select( DB::raw("select
            map.sub_control_template_id 'common_sub_control_template_id',
            map.child_sub_control_template_id 'sub_control_template_id',
            sub.title 'sub_control_name',
            sub.title 'title',
            sub.description 'sub_description',
            sub.id 'sub_control_id',
            sub.id 'id',
            sub.progress 'progress',
            app.name 'app_name',
            app.id 'app_id',
            f.name 'framework_name',
            market.marketplace_category_id 'marketplace_category_id'
            from sub_control_template_map map
            join sub_control sub on sub.sub_control_template_id = map.child_sub_control_template_id
            join sub_control_template template on template.id = map.sub_control_template_id
            join app app on app.id = sub.app_id
            join program p on p.id = app.program_id
            join framework f on f.id = p.framework_id
            left join subcontrol_marketplace_mappings market on market.common_sub_control_template_id = map.sub_control_template_id
            where p.id = :program_id
            and sub.deleted_at is null"
            ), [
            'program_id' => $programId
        ] );

        return $apps;
    }

	public function getMarketplaceResults($filters = [], $programId = null, $isAppend = true)
    {
        $isFiltered = false;
        if (empty($programId)) {
            $programId = ProgramService::getCurrentUserProgramId();
        }

        $program = ProgramService::getProgramDetailsById($programId);
        $currentFramework = Framework::where('id', $program->framework_id)->first();

        $currentLevel = ProgramService::getCurrentUserProgramLevelId();
        $subControlTemplateIds = SubControlTemplateLevel::whereIn('level', [$currentLevel])->pluck('sub_control_template_id');

        $data = $this->getMarketPlaceQuery($programId);
        $collections = collect($data);

        //framework has multiple levels
        if (!empty($currentFramework) && $currentFramework->has_multiple_levels && !empty($program->level)) {
            $collections = $collections->whereIn('sub_control_template_id', $subControlTemplateIds);
        }

        // apply apps filter here
        if (!empty($filters['control'])) {
            $isFiltered = true;
            $collections = $collections->reject(function ($app, $key) use($filters) {
                if (in_array($app->app_id, $filters['control'])) {
                    return false;
                }
                return true;
            });
        }

        // get all market place ids to get results of solutions
        $marketplaceCategoryIds = array_filter($collections->pluck('marketplace_category_id')->toArray());


        // apply category filter here
        if (!empty($filters['category'])) {
            $isFiltered = true;
            $marketplaceCategoryIds = $filters['category'];
        }

        $parentFilter = [];
        if (in_array(241, $marketplaceCategoryIds) || !empty($filters['search'])) {
            $parentFilter[] = 'Audit Firm';
            $category       = Category::with('children')->where('slug', 'Audit Firm')->first();
            $categoriesIds  = $category->children->pluck('id')->toArray();

            $marketplaceCategoryIds = array_merge($marketplaceCategoryIds, $categoriesIds);
        }

        if (in_array(226, $marketplaceCategoryIds) || !empty($filters['search'])){
            $parentFilter[] = 'Consulting Firm';
            $category       = Category::with('children')->where('slug', 'Consulting Firm')->first();
            $categoriesIds  = $category->children->pluck('id')->toArray();

            $marketplaceCategoryIds = array_merge($marketplaceCategoryIds, $categoriesIds);
        }

        if (in_array(225, $marketplaceCategoryIds) || !empty($filters['search'])) {
            $parentFilter[] = 'Cyber Insurance';
            $category       = Category::with('children')->where('slug', 'Cyber Insurance')->first();
            $categoriesIds  = $category->children->pluck('id')->toArray();

            $marketplaceCategoryIds = array_merge($marketplaceCategoryIds, $categoriesIds);
        }

        /*
        if ($currentFramework) {
            $frameworkIds  = Category::where('name', $currentFramework->name)->pluck('id')->toArray();
            $category      = Category::with('children')->where('name', 'Consulting Firm')->first();
            $categoriesIds = $category->children->pluck('id')->toArray();

            $marketplaceCategoryIds = array_merge($marketplaceCategoryIds, $frameworkIds, $categoriesIds);
        }
        */

        $solutionsQuery = Solution::with(['type', 'company', 'categories', 'subCategories', 'ppt'])
        ->select('solution.*', 'sol_cat.category_id', 'solution.id as solution_id', 'child.parent_id as parent_category_id')
        ->join('solution_categories as sol_cat', function($join) use ($marketplaceCategoryIds) {
            $join->on('solution.id', '=', 'sol_cat.solution_id')
            ->whereIn('sol_cat.category_id', $marketplaceCategoryIds);
        })
        ->leftJoin('categories as child', function($join) use ($marketplaceCategoryIds) {
            $join->on('child.id', '=', 'sol_cat.category_id')
            ->whereIn('sol_cat.category_id', $marketplaceCategoryIds);
        });

        //apply type filter here
        if (!empty($filters['type'])) {
            $isFiltered = true;
            $solutionsQuery->whereIn('solution_type_id', $filters['type']);
        }

        //apply provider filter here
        if (!empty($filters['provider'])) {
            $isFiltered = true;
            $solutionsQuery->whereIn('company_id', $filters['provider']);
        }

        if (!empty($filters['solution'])) {
            $solutionsQuery->whereIn('solution.id', $filters['solution']);
        }

        $solutions = $solutionsQuery->get();

        $appendResults = [];

        // appends if sub category has parent category, which is a framework
        if ($isAppend) {
            $parentsCategories = $solutions->groupBy('parent_category_id');
            $parentsCategoriesSolutions = $parentsCategories->map(function ($solutions, $id) use($filters, $parentFilter) {
                $category = Category::where('id', $id)->first();
                if (!$category) {
                    return [];
                }

                if (!in_array($category['name'],  $parentFilter)) {
                    return [];
                }

                $solutions = $solutions->unique('id')->values()->toArray();
                $category['maped_solutions'] = $solutions;

                //apply search filter here
                if (!empty($filters['search'])) {
                    $isCategorySearch = false;

                    $keywords = array_filter(array_map('trim', explode(' ', $filters['search'])));
                    if (striposa($category['name'], $keywords) > -1) {
                        $isCategorySearch = true;
                    }

                    $category['maped_solutions'] = array_values(array_filter($category['maped_solutions'], function($solution) use($keywords) {
                        if (
                            (striposa($solution['solution_name'], $keywords) > -1) ||
                            (striposa($solution['company']['name'], $keywords) > -1)
                        ) {
                            return true;
                        }

                        return false;
                    }));

                    $isCategorySearch = (!empty($category['maped_solutions'])) ? true : false;
                    if ($isCategorySearch) {
                        return $category->toArray();
                    }

                    return [];
                }

                return $category->toArray();

            })->filter(function ($category) use($filters) {
                if (empty($category['maped_solutions'])) {
                    return false;
                }

                if (!empty($filters['category']) && in_array($category['id'], $filters['category'])) {
                    return true;
                } else if(empty($filters['category'])) {
                    return true;
                } else {
                    return false;
                }
            });

            $appendResults = $parentsCategoriesSolutions->values()->all();
        }

        $solutions = $solutions->groupBy('category_id')->toArray();

        $apps = $collections->groupBy('app_id');
        $apps = $apps->toArray();

        $marketplaces = [];

        foreach ($apps as $appId => $subcontrols) {

            $hasSolutions = false;
            $isAppSearch = false;

            $app = App::where('id', $appId)->first();
            if (!$app) {
                continue;
            }

            //apply search filter here
            if (!empty($filters['search'])) {
                $keywords = array_filter(array_map('trim', explode(' ', $filters['search'])));
                if (striposa($app->name, $keywords) > -1) {
                    $isAppSearch = true;
                }
            }

            $subcontrols = collect(json_decode(json_encode($subcontrols), true));
            $subcontrolsGroups = $subcontrols->groupBy('sub_control_id');

            $app_subcontrols   = collect([]);

            foreach ($subcontrolsGroups as $subcontrol_id => $subcontrols) {

                //subcontrol get multiple marketplace ids
                $categoriesIds = $subcontrols->pluck('marketplace_category_id')->filter()->toArray();

                //apply subcontrol filter here
                $modified = $subcontrols
                ->unique('sub_control_id')
                ->reject(function ($subcontrol) use($filters) {
                    if (!empty($filters['subcontrol']) && !in_array($subcontrol['sub_control_id'], $filters['subcontrol'])) {
                        return true;
                    }

                    return false;
                })->map(function ($subcontrol) use($solutions, $categoriesIds) {
                    $collect = collect([]);
                    $subcontrol['solutions'] = [];
                    foreach ($categoriesIds as $category_id) {
                        if (isset($solutions[$category_id])) {
                            $collect->push($solutions[$category_id]);
                        }
                    }

                    $subcontrol['solutions'] = $collect->collapse()->unique('id')->values();
                    return $subcontrol;
                })->filter(function ($subcontrol) {
                    if ($subcontrol['solutions']->count() > 0) {
                        return true;
                    }

                    return false;
                });

                $app_subcontrols->push($modified->values());
            }

            $subcontrols = $app_subcontrols->collapse()->values()->toArray();

            //partial search for subcontrols if app name not found for partial search
            if (!empty($filters['search']) && !$isAppSearch) {
                $keywords = array_filter(array_map('trim', explode(' ', $filters['search'])));

                $subcontrols = array_map(function($subcontrol) use($filters, $keywords, &$isAppSearch) {
                    $isSubcontrolSearch = false;
                    if (striposa($subcontrol['sub_control_name'], $keywords) > -1) {
                        $isSubcontrolSearch = true;
                    } else {
                        if ($subcontrol['solutions'] instanceof Collection) {
                            $solutions = $subcontrol['solutions']->toArray();
                        }

                        $subcontrol['solutions'] = array_values(array_filter($solutions, function($solution) use($keywords) {
                            if (
                                (striposa($solution['solution_name'], $keywords) > -1) ||
                                (striposa($solution['company']['name'], $keywords) > -1)
                            ) {
                                return true;
                            }

                            return false;
                        }));

                        if (!empty($subcontrol['solutions'])) {
                           $isSubcontrolSearch = true;
                        }
                    }

                    if ($isSubcontrolSearch) {
                        $isAppSearch = true;
                        return $subcontrol;
                    }

                    return [];
                }, $subcontrols);
            }

            // if partial search not found for app and subcontrol then removed app from results
            if (!empty($filters['search']) && !$isAppSearch) {
                continue;
            }

            $app['subcontrols'] = array_values(array_filter($subcontrols));

            if (count($app['subcontrols']) > 0) {
                $hasSolutions = true;
            }

            if ($hasSolutions) {
                $marketplaces[] = $app;
            }
        }

        return [
            'results' => $marketplaces,
            'appends' => $appendResults
        ];
    }

    public function getCompanyChart($programId=null)
    {
        $chartData = [];
        $subcontrolTmp = [];

        if (empty($programId)){
            $programId = ProgramService::getCurrentUserProgramId();
        }

        $results = $this->getMarketplaceResults([], $programId);

        foreach ($results['results'] as $result) {

            foreach ($result->subcontrols as $sub) {

                foreach($sub['solutions'] as $solution) {

                    if (isset($chartData[$solution['company']['id']])) {
                        if (!in_array($sub['id'], $subcontrolTmp)){
                            $chartData[$solution['company']['id']]['no_of_subcontrol'] += 1;
                            $subcontrolTmp[] = $sub['id'];
                        }

                    } else {
                        $chartData[$solution['company']['id']] = [
                            'company_id'       => $solution['company']['id'],
                            'company_name'     => $solution['company']['name'],
                            'initials'         => $solution['company']['initials'],
                            'no_of_subcontrol' => 1
                        ];
                    }

                }

            }

        }

        return array_values($chartData);
    }

    /***** Manage Solutions APIs start *****/
    public function getAllSolutions()
    {
        $currentFrameworkId = FrameworkService::getCurrentFramework()->id;
        $userId = AuthService::getCurrentUser()->id;

        $data   = UserMarketplaceSolution::with(['solution','solution.company','solution.categories','solution.subCategories','subControl','solution.ppt'])
            ->where('user_id', $userId)
            ->where('framework_id', $currentFrameworkId)
            ->get();

        $solutions = $data->groupBy([
            'status',
        ], $preserveKeys = false);

        return $solutions;
    }

    // manage button modal data
    public function getManageSolutions()
    {
        $currentFrameworkId = FrameworkService::getCurrentFramework()->id;
        $userId = AuthService::getCurrentUser()->id;

        $data = UserMarketplaceSolution::with(['solution.type','solution.company','solution.categories','solution.subCategories','solution.ppt'])
            ->where('user_id', $userId)
            ->where('framework_id', $currentFrameworkId)
            ->get();

        $results = $data->groupBy('status')->map(function ($items, $status) {
            $categories = collect([]);
            $subcontrols = collect([]);

            $items->map(function ($item) use($categories, $subcontrols) {
                $categories->when($item->category_id, function ($categories) use ($item) {
                    return $categories->push($item);
                });

                $subcontrols->when($item->subcontrol_template_id, function ($subcontrols) use ($item) {
                    return $subcontrols->push($item);
                });
            });

            $subcontrols = $subcontrols->groupBy('subcontrol_template_id')->map(function ($items, $key) {
                $subcontrol = SubControl::where('id', $key)->first();
                $subcontrol->solutions = $items->toArray();
                return $subcontrol;
            });

            $categories = $categories->groupBy('category_id')->map(function ($items, $key) {
                $category = Category::where('id', $key)->first();
                $category->solutions = $items->toArray();
                return $category;
            });

            return [
                'subcontrols' => $subcontrols->sortBy('progress')->values(),
                'categories' => $categories->values()
            ];
        });

        return $results->all();
    }

    // to save all solutions
    public function saveSolution($request, $userId)
    {
        $currentFrameworkId = FrameworkService::getCurrentFramework()->id;
        $currentOrg         = AuthService::getCurrentOrganization()->toArray();
        $currentUser        = AuthService::getCurrentUser()->toArray();

        $where = [
            'user_id'                => $userId,
            'solution_id'            => $request['id'],
            'category_id'            => (!empty($request['category_id'])) ? $request['category_id'] : NULL,
            'subcontrol_template_id' => (!empty($request['sub_control_id'])) ? $request['sub_control_id'] : NULL,
            'framework_id'           => $currentFrameworkId
        ];

        $solution = UserMarketplaceSolution::where($where)->first();

        if($solution) {
            if (($solution['status'] == $request['status']) || $solution['status'] == 'contacted') {
                return false;
            }
        }

        $saveSolution = UserMarketplaceSolution::updateOrCreate(
            $where,
            [
                'status' => $request['status']
            ]
        );

        if($solution['status'] == 'contacted') {
            $getSolution              = Solution::where('id', $request['id'])->first()->toArray();
            $request['solution_user'] = $solution_user = MarketplaceUser::where('id', $getSolution['user_id'])->first()->toArray();
            $request['framework_id']  = $currentFrameworkId;
            $request['user_id']       = $userId;
            $request['current_org']   = $currentOrg;
            $request['current_user']  = $currentUser;

            $marketplaceLeads = [
                'framework_id'  => $currentFrameworkId,
                'user_id'       => $userId,
                'solution_id'   => $request['id'],
                'subcontrol_id' => (!empty($request['sub_control_id'])) ? $request['sub_control_id'] : NULL,
                'category_id'   => (!empty($request['category_id'])) ? $request['category_id'] : NULL,
            ];

            $isExist = MarketplaceLeads::where($marketplaceLeads)->first();

            if(!$isExist) {
                MarketplaceLeads::create($marketplaceLeads);

                Mail::to("hesom.parhizkar@apptega.com")
                    ->send(new GetQuoteMarketplaceEmail($request));
            }
        }

        return UserMarketplaceSolution::with(['solution.vendor'])
                ->where('id', $saveSolution->id)
                ->first();

    }

    // save multiple solutions
    public function saveMultipleSolutions($request, $userId)
    {
        if (!empty($request['solutionIds'])) {
            $update = UserMarketplaceSolution::where('user_id', $userId)
                ->whereIn('id', $request['solutionIds'])
                ->update([
                    'status' => $request['status']
                ]);
        }
        return UserMarketplaceSolution::with(['solution.vendor'])
            ->where('user_id', $userId)
            ->whereIn('id', $request['solutionIds'])
            ->get();
    }

    // remove manage solutions
    public function removeSolutions($request, $userId)
    {
        if(!empty($request)) {
            UserMarketplaceSolution::where('user_id', $userId)
                ->whereIn('id', $request)
                ->delete();
        }

        return true;
    }

    // get user solution
    public function getUserSolutionById($input)
    {
        $currentFrameworkId = FrameworkService::getCurrentFramework()->id;

        $query = UserMarketplaceSolution::where('solution_id', $input['solution_id'])
            ->where('framework_id', $currentFrameworkId)
            ->where('user_id', $input['user_id']);

        if (isset($input['subcontrol_id']) && !is_null($input['subcontrol_id'])) {
            $query->where('subcontrol_template_id', $input['subcontrol_id']);
        } else {
            $query->where('category_id', $input['category_id']);
        }

        $result = $query->first();

        return $result;
    }
    /***** Manage Solutions APIs end *****/


    public function saveMarketPlaceVendor($data)
    {
        return $data;
    }

    public function computeFuturecast($input)
    {
        $subcontrolIds = $input;

        if(empty($subcontrolIds)) {
            return false;
        }

        $programId = ProgramService::getCurrentUserProgramId();

        $average   = $this->getFuturecastProgress($programId, $subcontrolIds);

        return $average;
    }

    public function getFuturecastProgress($programId, $subcontrolIds) {
        $program = Program::find($programId);
        $framework = [];
        $budget = [];
        $budgetRequired = 0;
        $progress = 0;
        $subcontrolCount = 0;
        $appCount = 0;
        $mappedAppProgressPercentage = 0;
        $appProgressPercentage = 0;
        $appMappingPercentage = 0;
        $mappedApp = 0;
        if ($program) {
            $framework = FrameworkService::getFrameworkDetails($program->framework_id);
            $apps = App::where('program_id', $program->id)->get();
            $mappedApp = (!empty($framework)) ? count($framework['app_template_ids']) : 0;
            foreach ($apps as $app) {
                $progress = 0;
                $mappedSubcontrolCount = 0;
                $mappedProgress = 0;
                if ($app->status) {
                    $allSubcontrols = $app->subcontrols()->where('parent_sub_control_id', null)->get();
                    $subcontrols = $app->subcontrols()->where("is_disable", 0)->where('parent_sub_control_id', null)->get();
                    $subcontrolCount = $subcontrols->count();
                    if ($subcontrolCount == 0) {
                        $subcontrolCount = 0;
                        $progress = 0;
                    } else {
                        $appCount ++;
                        foreach ($subcontrols as $subcontrol) {
                            $subProgress    = in_array($subcontrol->id, $subcontrolIds) ? 100 : $subcontrol->progress;
                            $budgetRequired = $budgetRequired + $subcontrol->budget_amount;
                            $progress       += $subProgress;
                        }
                    }
                    $appProgressPercentage += ($subcontrolCount != 0) ? $progress / $subcontrolCount : 0;
                    if (($mappedApp != 0) && in_array($app->app_template_id, $framework['app_template_ids'])) {
                        $allSubcontrols = $app->subcontrols()->where('parent_sub_control_id', null)->get();
                        $subcontrols = $app->subcontrols()->where("is_disable", 0)->where('parent_sub_control_id', null)->get();
                        $mappedSubcontrolCount = $subcontrols->count();
                        if ($mappedSubcontrolCount == 0) {
                            $mappedApp--;
                            $mappedSubcontrolCount = 0;
                            $mappedProgress = 0;
                        } else {
                            foreach ($subcontrols as $subcontrol) {
                                $mapedSubProgress = in_array($subcontrol->id, $subcontrolIds) ? 100 : $subcontrol->progress;
                                $mappedProgress = $mappedProgress + $mapedSubProgress;
                            }
                        }
                        $mappedAppProgressPercentage += ($mappedSubcontrolCount != 0) ? $mappedProgress / $mappedSubcontrolCount : 0;
                    }
                } else {
                    if ($framework['app_template_ids'] && in_array($app->app_template_id, $framework['app_template_ids'])) {
                        $mappedApp --;
                    }
                }
            }
            $program['framework_templates'] = $framework['app_template_ids'];
        } else {
            $program = array();
            $apps = [];
        }
        $mappedProgressPercentage = ($mappedApp != 0) ? ($mappedAppProgressPercentage) / $mappedApp : 0;
        //$avgProgressPercent = ($appCount != 0) ? ($appProgressPercentage) / $appCount : 0;
        // $budget['budget_required'] = $budgetRequired;

        // $budget['total_budget'] = ($program != null) ? $program->total_budget : 0;
        // $program['framework'] = $framework;
        $programProgress = ($appCount != 0) ? ($appProgressPercentage) / $appCount : 0;
        // $program['apps'] = $apps;
        // $program['budget'] = $budget;
        $mappedAppProgress = $mappedProgressPercentage;
        $futurecast_avg = ($mappedAppProgress == 0) ? $programProgress : $mappedAppProgress;
        return $futurecast_avg;
    }
}