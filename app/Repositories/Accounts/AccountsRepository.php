<?php
namespace App\Repositories\Accounts;

use App\Models\Accounts;
use App\Models\AccountsFunding;
use App\Models\Advisors;
use App\Models\ContributionOptions;
use App\Models\ConversationLibrary;
use App\Models\Employee;
use App\Models\HouseholdAnalysis;
use App\Models\HouseholdAnalysisDebts;
use App\Models\HouseholdIntegration;
use App\Models\HouseholdMemberBeneficiaries;
use App\Models\HouseholdNotes;
use App\Models\HouseholdNotesMentions;
use App\Models\HouseholdNotesTag;
use App\Models\Positions;
use App\Models\Transactions;
use App\Models\TransactionTags;
use App\Models\User;
use App\Repositories\Households\HouseholdsRepository;
use App\Services\Chat;
use App\Services\Junxure;
use Auth;
use Carbon\Carbon;
use DB;
use App\Models\AccountTypes;

class AccountsRepository
{
    public function __construct(
        Chat $chat,
        HouseholdsRepository $householdsRepository,
        Junxure $junxure
    ) {
        $this->chat                 = $chat;
        $this->householdsRepository = $householdsRepository;
        $this->org                  = config('benjamin.org_id');
        $this->junxure              = $junxure;
        $this->junxureUserId        = 'b547284b-dac7-45be-8a9a-f678ae8af42a';
    }

    public function copyAccountsFromDemo($org_id)
    {
        $data = DB::select('select * from `benjamin-portal`.accounts_demos;');
        $data = json_decode(json_encode($data), true);

        foreach ($data as &$d) {
            $d['org_id'] = $org_id;
        }
        Accounts::insert($data);

        return $data;
    }

    public function deleteAccounts($org_id)
    {
        Accounts::truncate();
    }

    public function get($id)
    {
        $accounts = Accounts::where('household_id', $id)->get()->toArray();
        return $accounts;
    }

    public function save($data)
    {
        $add = Accounts::create($data);
        return $add;
    }

    public function update($data)
    {
        $id            = $data['id'];
        $accounts_data = Accounts::find($id);

        if ($accounts_data) {
            $accounts_data = $accounts_data->update($data);
        }

        return $accounts_data;
    }

    /**
     * Get a chart resource for all accounts of perticular household.
     *
     * @param  $id [household id]
     */
    public function getChart($id, $request)
    {
        $monthArray        = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'Novemember', 'December'];
        $lineChartLabels   = [];
        $lineChartAccounts = [];

        $lastMonth = Carbon::now()->subMonth(1)->format('Y-m-d');
        //echo "<pre>";print_r($request);exit();
        if (!empty($request['from']) && !empty($request['to'])) {
            $from  = date('Y-m-d 00:00:00', strtotime($request['from']));
            $to    = date('Y-m-d 23:59:59', strtotime($request['to']));
            $where = "h.as_of_date between '" . $from . "' and '" . $to . "'";
        } else {
            $where = "h.as_of_date >= '" . $lastMonth . "'";
        }

        if(isset($request['timeline'])){
             if($request['timeline'] == 'daily'){
                $from = Carbon::now()->subDays(30)->format('Y-m-d');
                $to =  Carbon::now()->format('Y-m-d');
                $where = "h.as_of_date between '" . $from . "' and '" . $to . "'";
                $groupBy = 'day';
            } else if($request['timeline'] == 'monthly') {
                $from = Carbon::now()->subMonth(12)->format('Y-m-d');
                $to =  Carbon::now()->format('Y-m-d');
                $where = "h.as_of_date between '" . $from . "' and '" . $to . "'";
                $groupBy = 'month';
            } else {
                $groupBy = "DATE_FORMAT(h.as_of_date, '%Y-%m-%d')";
            }
        } else {
            $groupBy = "DATE_FORMAT(h.as_of_date, '%Y-%m-%d')";
        }


        $data = DB::select("select DATE_FORMAT(h.as_of_date, '%Y-%m-%d') 'as_of_date', sum(h.balance) 'balance' , MONTH(h.as_of_date) month, DAY(h.as_of_date) day from historical_balances h
            join accounts a on a.id = h.account_id
            where a.household_id = " . $id . " AND " . $where . "
            group by ".$groupBy."
            order by as_of_date ASC");

        foreach ($data as $key => $value) {
            $lineChartLabels[]   = date('m/d/Y', strtotime($value->as_of_date));
            $lineChartAccounts[] = $value->balance;
        }

        $chartData                = [];
        $chartData['data']        = $lineChartAccounts;
        $chartData['label']       = "Accounts";
        $chartData['borderWidth'] = 0;

        return [
            'lineChartLabels' => $lineChartLabels,
            'chartData'       => [$chartData],
        ];
    }

    public function getAccount($id)
    {

        $data = Accounts::with('accountTypes')->where('household_id', $id)->where('status_id', '<>', 16)->get();

        $data = $data->map(function ($item, $key) {
            return [
                "id"               => $item['id'],
                "account_number"   => $item['account_number'],
                "name_account"     => $item['name'],
                "account_type"     => $item['accountTypes']['name'],
                "starting_balance" => 0.00,
                "current_balance"  => $item['balance'],
            ];
        });
        return [
            'code'     => 200,
            'response' => $data,
        ];
    }

    public function accountFilter($id, $request)
    {
        $monthArray      = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'Novemember', 'December'];
        $chartData       = [];
        $lineChartLabels = [];
        $accountNumbers  = array_unique($request['account_number']);
        $chartDataset    = [];
        $lineChartMonth  = [];
        $lastMonth = Carbon::now()->subMonth(1)->format('Y-m-d');
        foreach ($accountNumbers as $k => $val) {
            if (!empty($request['from']) && !empty($request['to'])) {
                $from  = date('Y-m-d 00:00:00', strtotime($request['from']));
                $to    = date('Y-m-d 23:59:59', strtotime($request['to']));
                $where = "h.as_of_date between '" . $from . "' and '" . $to . "'";
            } else {
                $where = "h.as_of_date >= '" . $lastMonth . "'";
            }

            $account = Accounts::where('account_number', $val)->first();
            $data    = DB::select("select DATE_FORMAT(h.as_of_date, '%Y-%m-%d') 'as_of_date', sum(h.balance) 'balance', MONTH(h.as_of_date) month, DAY(h.as_of_date) day  from historical_balances h join accounts a on a.id = h.account_id where a.household_id = " . $id . " AND h.account_id = " . (($account->id) ?? '') . " AND " . $where . " group by DATE_FORMAT(h.as_of_date, '%Y-%m-%d') ORDER BY as_of_date ASC");

            $data = json_decode(json_encode($data), true);
            $data = array_map(function ($tap) {
                return [
                    'balance'    => $tap['balance'],
                    'as_of_date' => $tap['as_of_date'],
                    'combine'    => strtotime($tap['as_of_date']),
                ];
            }, $data);

            $chartDataset[$k]['data']    = $data;
            $chartDataset[$k]['account'] = $val;
            foreach ($data as $key => $value) {
                $lineChartMonth[] = $value['combine'];
                if (!empty($request['from']) && !empty($request['to'])) {
                    $lineChartLabels[] = date('m/d/Y', strtotime($value['as_of_date']));
                }
            }
        }

        $chartData = [];
        foreach ($chartDataset as $csk => $csv) {
            $lineChartAccounts = [];
            foreach (array_unique($lineChartMonth) as $lk => $lv) {
                $months = array_column($csv['data'], 'combine');
                $s      = array_search($lv, $months);
                if ($s === false) {
                    $lineChartAccounts[$lk] = 0;
                } else {
                    $lineChartAccounts[$lk] = (float) $csv['data'][$s]['balance'];
                }
            }
            $chartData[$csk]['data']        = array_values($lineChartAccounts);
            $chartData[$csk]['label']       = 'Accounts-' . $csv['account'];
            $chartData[$csk]['borderWidth'] = 0;
        }
        return [
            'lineChartLabels' => array_values(array_unique($lineChartLabels)),
            'chartData'       => $chartData,
        ];
    }

    public function getAccountDetails($household_id, $account_id)
    {

        $data = Accounts::with('accountTypes')->where('household_id', $household_id)->where('id', $account_id)->first();

        return $data;
    }

    public function getConversationList()
    {
        $data = ConversationLibrary::where('active', 1)->get()->toArray();
        return $data;
    }

    public function getPortfolioPerformanceChart($id, $account_id)
    {
        $monthArray      = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'Novemember', 'December'];
        $lineChartLabels = [];
        $lineChartData   = [];

        $data = DB::select("select DATE_FORMAT(h.as_of_date, '%Y-%m-%d') 'as_of_date', sum(h.balance) 'balance' from historical_balances h join accounts a on a.id = h.account_id where a.household_id = " . $id . " AND h.account_id = " . $account_id . " group by DATE_FORMAT(h.as_of_date, '%Y-%m-%d') ORDER BY as_of_date ASC");

        foreach ($data as $key => $value) {
            $lineChartLabels[] = date('d M, Y', strtotime($value->as_of_date));
            $lineChartData[]   = $value->balance;
        }

        $chartData                = [];
        $chartData['data']        = $lineChartData;
        $chartData['label']       = "Performance";
        $chartData['borderWidth'] = 0;
        return [
            'lineChartLabels' => $lineChartLabels,
            'chartData'       => [$chartData],
        ];
    }

    public function getPortfolioPerformanceChartFilter($id, $account_id, $request)
    {
        $monthArray      = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'Novemember', 'December'];
        $lineChartLabels = [];
        $lineChartData   = [];

        if (!empty($request['from']) && !empty($request['to'])) {
            $from  = date('Y-m-d 00:00:00', strtotime($request['from']));
            $to    = date('Y-m-d 23:59:59', strtotime($request['to']));
            $where = "h.as_of_date between '" . $from . "' and '" . $to . "'";
        }

        $data = DB::select("select DATE_FORMAT(h.as_of_date, '%Y-%m-%d') 'as_of_date', sum(h.balance) 'balance' from historical_balances h join accounts a on a.id = h.account_id where a.household_id = " . $id . " AND h.account_id = " . $account_id . " AND " . $where . " group by DATE_FORMAT(h.as_of_date, '%Y-%m-%d') ORDER BY as_of_date ASC");

        foreach ($data as $key => $value) {
            $lineChartLabels[] = date('d M, Y', strtotime($value->as_of_date));
            $lineChartData[]   = $value->balance;
        }

        $chartData                = [];
        $chartData['data']        = $lineChartData;
        $chartData['label']       = "Performance";
        $chartData['borderWidth'] = 0;

        return [
            'lineChartLabels' => $lineChartLabels,
            'chartData'       => [$chartData],
        ];
    }

    public function getPortfolioChartGrowth($id, $account_id)
    {
        /*$data = [100, 200, 500, 300];
        $label = ['Large cap', 'Mid cap', 'Small cap', 'International'];*/

        $data  = [];
        $label = [];

        $queryData = DB::select("select a_s.sector 'sector', (p.price * p.quantity)/(t.total) 'percentage' from positions p
        join accounts a on a.id = p.account_id
        left join asset_classifications a_s on p.symbol = a_s.ticker
        CROSS
        JOIN (SELECT sum(p1.price * p1.quantity) 'total' FROM positions p1 join accounts a1 on a1.id = p1.account_id left join asset_classifications a_s1 on p1.symbol = a_s1.ticker where IFNULL(a_s1.asset_class, 'Unclassified') = 'Growth' and a_s1.sector in ('Large Cap', 'Mid Cap', 'Small Cap', 'International') and a1.id in ($account_id)) t
        where a.id in ($account_id)
        and IFNULL(a_s.asset_class, 'Unclassified') = 'Growth'
        and a_s.sector in ('Large Cap', 'Mid Cap', 'Small Cap', 'International')
        group by p.symbol");

        foreach ($queryData as $key => $value) {
            $label[] = $value->sector;
            $data[]  = $value->percentage;
        }

        return [
            'data'  => $data,
            'label' => $label,
        ];
    }

    public function getPortfolioChartIncome($id, $account_id)
    {
        /*$data = [100, 200, 500];
        $label = ['Fixed income', 'Closed end funds', 'Alternative investments'];*/

        $data  = [];
        $label = [];

        $queryData = DB::select("select a_s.sector 'sector', (p.price * p.quantity)/(t.total) 'percentage' from positions p
        join accounts a on a.id = p.account_id
        left join asset_classifications a_s on p.symbol = a_s.ticker
        CROSS
        JOIN (SELECT sum(p1.price * p1.quantity) 'total' FROM positions p1 join accounts a1 on a1.id = p1.account_id left join asset_classifications a_s1 on p1.symbol = a_s1.ticker where IFNULL(a_s1.asset_class, 'Unclassified') = 'Income' and a1.id in ($account_id)) t
        where a.id in ($account_id)
        and IFNULL(a_s.asset_class, 'Unclassified') = 'Income'
        group by a_s.sector");

        foreach ($queryData as $key => $value) {
            $label[] = $value->sector;
            $data[]  = $value->percentage;
        }

        return [
            'data'  => $data,
            'label' => $label,
        ];
    }

    public function getCashFlowChart($id)
    {
        $portfolioChartData = array(
            [
                "balance"        => "11101.05",
                "month"          => 1,
                "account_number" => 15256,
                "month_name"     => "January",
            ],
            [
                "balance"        => "24000.05",
                "month"          => 2,
                "account_number" => 52256,
                "month_name"     => "February",
            ],
            [
                "balance"        => "62000.25",
                "month"          => 4,
                "account_number" => 586,
                "month_name"     => "April",
            ],
            [
                "balance"        => "11000.55",
                "month"          => 6,
                "account_number" => 78542,
                "month_name"     => "June",
            ],
            [
                "balance"        => "30000.75",
                "month"          => 7,
                "account_number" => 9658,
                "month_name"     => "July",
            ],
            [
                "balance"        => "30000.00",
                "month"          => 11,
                "account_number" => 11525,
                "month_name"     => "November",
            ],
            [
                "balance"        => "75021.00",
                "month"          => 12,
                "account_number" => 1525,
                "month_name"     => "December",
            ]);

        return $portfolioChartData;
    }

    public function getCashFlowChartFilter($id, $account_id, $request)
    {
        $min = strtotime($request['from']);
        $max = strtotime($request['to']);
        // Generate random number using above bounds
        for ($i = 0; $i < 7; $i++) {
            $date                       = mt_rand($min, $max);
            $historical_balances[$date] = array(
                "balance"    => rand(10000, 99999),
                "month"      => date('F', $date),
                "account_id" => $account_id,
                "month_name" => date('F d', $date),
            );
        }
        ksort($historical_balances);
        $dataarray = array();
        foreach ($historical_balances as $key => $value) {
            $dataarray[] = $value;
        }

        return $dataarray;
    }

    public function getHoldingsList($id, $account_id)
    {
        //and a_s1.sector in ('Large Cap', 'Unclassified', 'Closed End Funds')
        $data = DB::select("select p.id, p.symbol, s_n.name 'security_name',
              p.cost_basis,
              sum((p.price * p.quantity)) 'current_value',
              null 'total_income_generated',
              null 'percentage',
              concat(IFNULL(a_s.asset_class, 'Unclassified'), ' / ', a_s.sector) 'allocation',
              a_s.asset_class 'allocation_name',
              a_s.sector 'sector_name'
              from positions p
                  join accounts a on a.id = p.account_id
                  join (select account_id, max(date) 'max' from positions p2 where p2.account_id = $account_id) d on d.account_id = p.account_id and d.max = p.date
                  left join asset_classifications a_s on p.symbol = a_s.ticker
                  left join security_name s_n on (s_n.cusip = p.cusip and p.cusip <> '' and p.cusip is not null) or (s_n.symbol = p.symbol and p.symbol <> '' and p.symbol is not null)
              where a.id = $account_id
              group by p.symbol, p.cost_basis,  concat(IFNULL(a_s.asset_class, 'Unclassified'), ' / ', a_s.sector) , a_s.asset_class, a_s.sector, p.id, p.symbol, s_n.name");

        return $data;
    }

    public function getTransactionsList($id, $account_id)
    {
        $data = Transactions::with('transactionTags')->where('account_id', $account_id)->get();

        $data = $data->map(function ($item, $key) {

            return [
                "id"          => $item['id'],
                "tags"        => $item['transactionTags'],
                "tagText"     => $this->getTagText($item['transactionTags']->toArray()),
                "date"        => date('Y-m-d', strtotime($item['run_date'])),
                "type"        => $item['transaction_type'],
                "description" => $item['desc_1'],
                "amount"      => $item['amount'],
            ];
        })->toArray();

        $tagFilterValue = TransactionTags::get()->toArray();

        $response_data                   = [];
        $response_data['data']           = $data;
        $response_data['tagFilterValue'] = $tagFilterValue;

        return $response_data;

    }

    public function getTagText($data)
    {
        $textArray = array_map(function ($tag) {
            return $tag['tag'];
        }, $data);

        return implode($textArray, ', ');
    }

    public function getTransactionsFilterList($input, $id, $account_id)
    {
        $transactionIds = TransactionTags::whereIn('tag', $input['tagTypeData'])->pluck('transaction_id')->toArray();

        $tagTypeData = $input['tagTypeData'];

        $listData = $this->getTransactionsList($id, $account_id);

        $filterdData = array_filter(array_map(function ($tag) use ($transactionIds) {
            if (in_array($tag['id'], $transactionIds)) {
                return $tag;
            } else {
                return '';
            }
        }, $listData['data']));

        return array_values($filterdData);
    }

    public function getHoldingsListByAllocation($id, $account_id, $input)
    {
        $allocation = $input['allocation'];

        $data = DB::select("select p.id, p.symbol, p.name 'security_name',
        p.price 'cost_basis',
        sum((p.price * p.quantity)) 'current_value',
        t.total 'total_income_generated',
        (p.price * p.quantity)/(t.total) 'percentage',
        concat(IFNULL(a_s.asset_class, 'Unclassified'), ' / ', a_s.sector) 'allocation',
        a_s.asset_class 'allocation_name',
        a_s.sector 'sector_name'
        from positions p
        join accounts a on a.id = p.account_id
        left join asset_classifications a_s on p.symbol = a_s.ticker
        CROSS
        JOIN (SELECT sum(p1.price * p1.quantity) 'total' FROM positions p1 join accounts a1 on a1.id = p1.account_id left join asset_classifications a_s1 on p1.symbol = a_s1.ticker where a_s1.asset_class in ('Growth', 'Income') and a_s1.sector in ('" . $allocation . "') and a1.id in ($account_id)) t
        where a.id in ($account_id)
        and a_s.asset_class in ('Growth', 'Income') and a_s.sector in ('" . $allocation . "')
        group by p.symbol");

        return $data;
    }

    public function getHoldingsListByFilter($id, $account_id, $input)
    {
        $data = $this->getHoldingsList($id, $account_id);

        $symbolTypeData     = $input['symbolTypeData'];
        $allocationTypeData = $input['allocationTypeData'];

        $filterdData = array_filter(array_map(function ($tag) use ($symbolTypeData, $allocationTypeData) {
            if (in_array($tag->symbol, $symbolTypeData) && in_array($tag->allocation, $allocationTypeData)) {
                return $tag;
            } else {
                return '';
            }
        }, $data));

        return array_values($filterdData);
    }

    public function createAnalysis($household_id, $input)
    {
        try {
            $householdAnalysisdata = [
                'household_id'                 => $household_id,
                'client_1_name'                => $this->checkIsNull($input['household'], 'client_1_name'),
                'client_1_age'                 => $this->checkIsNull($input['household'], 'client_1_age'),
                'client_1_working'             => $this->checkIsNull($input['household'], 'client_1_working'),
                'client_1_type'                => $this->checkIsNull($input['household'], 'client_1_type'),
                'client_1_planned_retirement'  => $this->checkIsNull($input['household'], 'client_1_planned_retirement'),
                'client_2_name'                => $this->checkIsNull($input['household'], 'client_2_name'),
                'client_2_age'                 => $this->checkIsNull($input['household'], 'client_2_age'),
                'client_2_working'             => $this->checkIsNull($input['household'], 'client_2_working'),
                'client_2_type'                => $this->checkIsNull($input['household'], 'client_2_type'),
                'client_2_planned_retirement'  => $this->checkIsNull($input['household'], 'client_2_planned_retirement'),
                'outside_asset_cash'           => $this->checkIsNull($input['household'], 'outside_asset_cash'),
                'outside_asset_retirement'     => $this->checkIsNull($input['household'], 'outside_asset_retirement'),
                'outside_asset_non_retirement' => $this->checkIsNull($input['household'], 'outside_asset_non_retirement'),
                'outside_asset_other'          => $this->checkIsNull($input['household'], 'outside_asset_other'),
                'expense_rent_mortgage'        => $this->checkIsNull($input['household'], 'expense_rent_mortgage'),
                'expense_groceries_going_out'  => $this->checkIsNull($input['household'], 'expense_groceries_going_out'),
                'expense_auto_expenses'        => $this->checkIsNull($input['household'], 'expense_auto_expenses'),
                'expense_utilities_bills'      => $this->checkIsNull($input['household'], 'expense_utilities_bills'),
                'expense_insurance_medical'    => $this->checkIsNull($input['household'], 'expense_insurance_medical'),
                'expense_other_expenses'       => $this->checkIsNull($input['household'], 'expense_other_expenses'),
                'account_id'                   => $this->checkIsNull($input, 'account_id'),
            ];

            $analysis_id = $this->checkIsNull($input, 'analysis_id');

            if ($analysis_id != '') {
                $householdAnalysis = HouseholdAnalysis::find($analysis_id);
                $householdAnalysis->update($householdAnalysisdata);
                $gethouseholdAnalysisDebts = HouseholdAnalysisDebts::where('household_analysis_id', $analysis_id)->get();
                foreach ($gethouseholdAnalysisDebts as $value) {
                    HouseholdAnalysisDebts::where('id', $value->id)->delete();
                }
                $last_analysis_id = $analysis_id;
            } else {
                $householdAnalysis = HouseholdAnalysis::create($householdAnalysisdata);
                $last_analysis_id  = $householdAnalysis->id;
            }

            foreach ($input['household']['debt'] as $key => $value) {
                if (!empty($value['year_remaining']) || !empty($value['interest_rate']) ||
                    !empty($value['monthly_pmt']) || !empty($value['balance'])) {
                    $householdAnalysisDebtsData = [
                        'household_id'          => $household_id,
                        'household_analysis_id' => $last_analysis_id,
                        'balance'               => $value['balance'],
                        'interest_rate'         => $value['interest_rate'],
                        'year_remaining'        => $value['year_remaining'],
                        'monthly_pmt'           => $value['monthly_pmt'],
                    ];
                    HouseholdAnalysisDebts::create($householdAnalysisDebtsData);
                }
            }

            return $last_analysis_id;

        } catch (Exception $e) {
            return [
                'code'    => 500,
                'message' => $e->getMessage(),
                'result'  => false,
            ];
        }

    }
    public function checkIsNull($variable, $field)
    {
        return isset($variable[$field]) ? $variable[$field] : null;
    }

    public function getHouseHoldAnalysysNotes($householdId, $accountId)
    {
        $accounts = HouseholdNotes::where('household_id', $householdId)->where('account_id', $accountId)->with('user')->get();

        return $accounts;

    }

    public function getHouseHoldNotes($householdId)
    {
        $accounts = HouseholdNotes::where('household_id', $householdId)->with('user')->get();
        $accounts = $accounts->map(function ($item, $key) {
            return $item;
        });

        return $accounts;

    }

    public function getHouseHoldRecentNotes($householdId, $propertyId)
    {
        $accounts = HouseholdNotes::where('household_id', $householdId)->orderBy('updated_at', 'DESC')->with('user', 'tags');

        /*if($propertyId){
            $accounts = $accounts->where('property_id', $propertyId);
        }*/

        $accounts = $accounts->orderBy('id', 'asc')->limit(5)->get();

        $accounts = $accounts->map(function ($item, $key) {
            $item->human_date = Carbon::parse($item->created_at)->diffForHumans();
            return $item;
        });

        return $accounts;

    }

    public function createShortNotes($data, $householdId)
    {
        try {
            $data['user_id']      = Auth::id();
            $mentions = [];
            if (isset($data['mentions'])) {
                $mentions = $data['mentions'];
            }
            $data['household_id'] = $householdId;
            if (isset($data['tags'])) {
                $tags = $data['tags'];
                unset($data['tags']);
            }
            unset($data['mentions']);
            $accounts = HouseholdNotes::create($data);

            if(!is_array($mentions)){
                $mentions = [$mentions];
            }
            $mentions = array_map(function($keyword){
                return str_replace('@', '', $keyword);
            }, $mentions);

            $employees   = [];
            if (!empty($mentions)) {
                $employees = Employee::select('id')
                    ->where(function ($query) use ($mentions) {
                        foreach ($mentions as $key => $value) {
                            $query->orwhere('name', 'like', "%{$value}%");
                        }
                    })->where('selectable',true)->get();
            }

            $accounts->mentions()->sync($employees);

            $returntag = [];
            if (isset($tags)) {
                foreach ($tags as $key => $tag) {
                    $notetag['household_notes_id'] = $accounts['id'];
                    $notetag['tag']                = $tag;
                    $returntag[] = HouseholdNotesTag::create($notetag);
                }
            }

            $accounts['tags'] = $returntag;
            $accounts['user']['name'] = Auth::user()->name;
            $accounts['human_date']   = Carbon::parse($accounts['created_at'])->diffForHumans();

            $householdIntegration = HouseholdIntegration::where('household_id', $householdId)
                ->where('intergration', 'junxure')
                ->first();

            if ($householdIntegration) {
                $saveAction = $this->junxureSaveAction($householdIntegration, $data['note']);
            }

            $notes =  HouseholdNotes::with(['mentions', 'user'])
                                    ->where('id', $accounts->id)
                                    ->first();

            $notes->tags = $returntag;
            return $notes;

        } catch (Exception $e) {
            return [
                'code'    => 500,
                'message' => $e->getMessage(),
                'result'  => false,
            ];
        }

    }

    public function junxureSaveAction($recordData, $note)
    {
        $RecordId = $recordData->intergration_id;

        $params = [];

        $params['ActionRequired'] = true;
        $params['RecordId']       = $RecordId;

        //ACCOUNT MAINTANCE
        $params['CategoryId'] = 'C8BC412C-B9D7-4EB5-B382-8C499274A2BC';
        $params['TypeID'] = 'C08D9A6D-3198-492F-B58B-F2357F567202';

        //NOTE
        $params['Notes'] = $note;
        //$params['AssignToID'] = $RecordId;

        //MEDIUM
        $params['PriorityID'] = '4B769CB2-999A-45FE-8A9A-4C7CC514E9B4';

        $recordId = $this->junxure->saveAction($this->junxureUserId, $RecordId, $params);

        \Log::info(print_r($recordId, true));
    }
    public function getAllocations($household_id, $accounts)
    {
        /*return  array(
        'Unclassified' => array(
        "total"=> 234111.86,
        "percent"=> 17.277885669013727
        ),
        'CEF' => array(
        "total"=> 88967.85,
        "percent"=> 17.277885669013727
        ),
        'Fixed Income' => array(
        "total"=> 16108.9918,
        "percent"=> 17.277885669013727
        ),
        'Equity' => array(
        "total"=> 1015791.0909,
        "percent"=> 17.277885669013727
        )
        ); */
        //array('Unclassified','Cef', 'Fixed Income', 'Equity');
        return ['234111.86', '88967.85', '16108.9918', '1015791.0909'];
    }

    public function getCurrentValue($household_id, $accounts)
    {
        return ['balance' => 1273031.53];
    }

    public function getInOutFlows($household_id, $accounts, $contribution, $startDate, $endDate)
    {
        return [
            'data'  => [
                "id"             => 2529,
                "account_id"     => "3830",
                "transaction_id" => "0",
                "type"           => "contribution",
                "amount"         => "23045.790",
                "description"    => "Deposit",
                "date"           => "2018-03-07 00:00:00",
                "created_at"     => '',
                "updated_at"     => '',
                "transfer"       => '',
            ],
            'total' => 1323226.31,
        ];
    }

    public function getInOutFlowsW($household_id, $accounts, $contribution, $startDate, $endDate)
    {
        return [
            'data'  => [
                "id"             => 2529,
                "account_id"     => "3830",
                "transaction_id" => "0",
                "type"           => "contribution",
                "amount"         => "23045.790",
                "description"    => "Deposit",
                "date"           => "2018-03-07 00:00:00",
                "created_at"     => '',
                "updated_at"     => '',
                "transfer"       => '',
            ],
            'total' => 127837.57,
        ];
    }

    public function getTotalIncome()
    {
        return [
            "totalIncome" => 6231,
            "MonthlyAvg"  => 519,
            "IncomeData"  => [
                [
                    'data'  => [65, 59, 80, 81, 56, 55, 40],
                    'lable' => 'Series A',
                ], [
                    'data'  => [28, 48, 40, 19, 86, 27, 90],
                    'lable' => 'Series B',
                ], [
                    'data'  => [18, 48, 77, 9, 100, 27, 40],
                    'lable' => 'Series C',
                ],
            ],
            "IncomeLable" => ['January', 'February', 'March', 'April', 'May', 'June', 'July'],
        ];
    }

    public function getHouseHoldList()
    {
        $data = [
            [
                'value' => 'household-1',
                'name'  => 'Household One',
            ], [
                'value' => 'household-2',
                'name'  => 'Household Two',
            ], [
                'value' => 'household-3',
                'name'  => 'Household Three',
            ],
        ];

        return $data;
    }

    public function copyAllPositionsFromDemo($org_id)
    {

        $data = DB::select('select * from `benjamin-portal`.positions_demo;');
        $data = json_decode(json_encode($data), true);

        foreach ($data as &$d) {
            $d['org_id'] = $org_id;
        }

        Positions::insert($data);

        return true;
    }

    public function deletePositions($org_id)
    {
        Positions::truncate();
    }

    public function allAccounts($user)
    {
        /*return DB::select("select a.*, ad1.name as advisor_1_name, ad2.name as advisor_2_name FROM accounts a LEFT JOIN advisors ad1 ON a.advisor_1_id = ad1.id LEFT JOIN advisors ad2 ON a.advisor_2_id = ad2.id");*/
        // sleep(1000);
        return Accounts::from('accounts as a')->where('org_id', $user->organization_id)
            ->select('a.*', 'ad1.name as advisor_1_name', 'ad2.name as advisor_2_name')
            ->join('advisors as ad1', function ($q) {
                $q->on('a.advisor_1_id', '=', 'ad1.id');
            })->join('advisors as ad2', function ($q) {
            $q->on('a.advisor_2_id', '=', 'ad2.id');
        })->get()->toArray();
    }

    public function updateAccount($householdId, $account_id, $request)
    {
        $accountData = [
            'first_trade_date' => $request['first_trade_date'],
            'name'             => $request['name'],
            'estimate'         => $request['estimate'],
            'source_id'        => $request['source_id'],
            'status_id'        => $request['status_id'],
            'closed_reason_id' => $request['closed_reason_id'],
            'special'          => $request['special'],
            'direct_bill'      => $request['direct_bill'],
            'advisor_overide'  => $request['advisor_override'],
            'progressive'      => null,
            'no_bill'          => null,
            'fee'              => null,
        ];

        switch ($request['fee']) {
            case 'other':
                $accountData['fee'] = $request['other_fee'];
                break;
            case 'progressive':
                $accountData['progressive'] = 1;
                break;
            case 'no_bill':
                $accountData['no_bill'] = 1;
                break;
            default:
                $accountData['fee'] = $request['fee'];
                break;
        }

        Accounts::updateOrCreate(['id' => $request['account_id']], $accountData);

        //update primary beneficiary accounts
        $this->updateAccountBeneficiary($request['account_id'], 'primary', $request['primary_beneficiary']);

        //update contingent beneficiary accounts
        $this->updateAccountBeneficiary($request['account_id'], 'contingent', $request['contingent_beneficiary']);

        return true;
    }

    public function updateAccountBeneficiary($accountId, $type, $data)
    {
        $householdMemberIds = [];
        if ($data) {
            foreach ($data as $key => $value) {
                array_push($householdMemberIds, $value['household_member_id']);
            }
        }

        //if user deleted beneficiary
        HouseholdMemberBeneficiaries::where('account_id', $accountId)
            ->where('type', $type)
            ->whereNotIn('household_member_id', $householdMemberIds)
            ->delete();

        //if existing member select then we need to create entry
        foreach ($householdMemberIds as $key => $value) {
            HouseholdMemberBeneficiaries::updateOrCreate([
                'household_member_id' => $value,
                'account_id'          => $accountId,
                'type'                => $type,
            ], []);
        }

        return true;
    }

    public function getFundingAccount($householdId, $accountId)
    {
        $fundings = AccountsFunding::where('account_id', $accountId)->with('user')->with('options')->orderBy('created_at', 'desc')->get();
        return $fundings;
    }

    public function getContributionOptions()
    {
        $options = ContributionOptions::orderBy('id', 'asc')->get();
        return $options;
    }

    public function deleteFundingAccount($householdId, $accountId, $accountFundId)
    {
        $data = AccountsFunding::find($accountFundId);
        if($data->image){
            \Storage::disk('s3')->delete('checks/'. $data->image);
        }
        return $data->delete();
    }

    public function saveFundingAccount($userId, $request)
    {
        $data = [
            'account_id' => $request['account_id'],
            'amount'     => $request['amount'],
            'notes'      => (!empty($request['notes'])) ? $request['notes'] : null,
            'user_id'    => $userId,
            'comment'    => (!empty($request['comment'])) ? $request['comment'] : null,
        ];

        if (!empty($request['file'])) {
            $imageName = $request['file']->getClientOriginalName() . '_' . time() . '.' . $request['file']->getClientOriginalExtension();
            $path      = $request['file']->storeAs(
                'checks', $imageName, 's3'
            );

            $data['image'] = $imageName;
        }

        $data = AccountsFunding::create($data);

        return $data;
    }

    public function updateShortNotes($note_id, $request)
    {
        $note = HouseholdNotes::find($note_id);
        if(!$note){
            return false;
        }

        $fields = [
            'title',
            'note',
            'household_id',
        ];
        foreach ($fields as $key => $field) {
            if (isset($request[$field])) {
                $note->$field = $request[$field];
            }
        }
        $note->save();


        //note = HouseholdNotes::where('id',$noteId)->update($request);
        if (isset($request['mentions'])) {
            $mentions = $request['mentions'];
            if(!is_array($mentions)){
                $mentions = [$mentions];
            }
            $mentions = array_map(function($keyword){
                return str_replace('@', '', $keyword);
            }, $mentions);

            $employees   = [];
            if (!empty($mentions)) {
                $employees = Employee::select('id')
                    ->where(function ($query) use ($mentions) {
                        foreach ($mentions as $key => $value) {
                            $query->orwhere('name', 'like', "%{$value}%");
                        }
                    })->where('selectable',true)->get();
            }

            $note->mentions()->sync($employees);
        }
        if (isset($request['tags'])) {
            $note->tags()->delete();
            foreach ($request['tags'] as $key => $tag) {
                $note->tags()->create(['tag' => $tag]);
            }
        }
        $notes =  HouseholdNotes::with(['mentions', 'user','tags'])
                ->where('id', $note->id)
                ->first();
        return $note;
    }

    public function getTagsNotes($householdid)
    {
        $notes = HouseholdNotes::where('household_id', $householdid);
        $notesId = $notes->pluck('id')->toArray();
        $userId = $notes->pluck('user_id')->toArray();

        $return['tags'] = HouseholdNotesTag::whereIn('household_notes_id', $notesId)->groupBy('tag')->get();
        $return['user'] = User::whereIn('id', $userId)->get();

        $employee_id = HouseholdNotesMentions::whereIn('household_notes_id', $notesId)->pluck('employee_id')->toArray();
        $return['employee']   = Employee::where('selectable',true)->whereIn('id', $employee_id)->get();

        return $return;
    }


    public function paginateNotes($request , $householdid)
    {
        $filter = $request['filter'] ;

        $notes = HouseholdNotes::where('household_id', $householdid)
                ->with('user', 'tags')
                ->orderBy('id', 'desc');

        if ($filter) {
            if (!empty($filter['date'])) {
                $date = $filter['date'];
                if ($date[0]) {
                    $from = Carbon::parse(date('c', strtotime($date[0])))
                            ->addDays(1)
                            ->format('Y-m-d');
                    $notes = $notes->where('created_at', '>=', $from.' 00:00:00');
                }
                if ($date[1]) {
                    $to   = Carbon::parse(date('c', strtotime($date[1])))
                            ->addDays(1)
                            ->format('Y-m-d');
                    $notes->where('created_at', '<=', $to.' 23:59:59');
                }
            }

            if (!empty($filter['mentions'])) {
                $mentionsIds = HouseholdNotesMentions::whereIn('employee_id', $filter['mentions'])
                                ->pluck('household_notes_id');
                $notes       = $notes->whereIn('id', $mentionsIds);
            }

            if (!empty($filter['tag'])) {
                $tagIds = HouseholdNotesTag::whereIn('tag', $filter['tag'])
                        ->pluck('household_notes_id');
                $notes  = $notes->whereIn('id', $tagIds);
            }

            if (!empty($filter['user'])) {
                $notes = $notes->whereIn('user_id', $filter['user']);
            }
        }

        return $notes->paginate($request['paginationPerPage'], ['*'], 'page', $request['pageNo']);
    }

    public function getHouseHoldNoteBtId($noteId)
    {
        $notes = HouseholdNotes::where('id',$noteId)->with('tags','user')->first();
        return $notes;
    }



    public function getSimpleAccountTypes()
    {
        return AccountTypes::get()->toArray();
    }

    public function getGraphPoints($household,$requestData)
    {
        if($requestData['from'] != '' && $requestData['to'] != ''){
           $from  = date('Y-m-d 00:00:00', strtotime("+1 day", strtotime($requestData['from'])));
            $to    = date('Y-m-d 23:59:59', strtotime("+1 day", strtotime($requestData['to'])));

            $where = " and created_at between '" . $from . "' and '" . $to . "'";
             if($requestData['timeline'] == 'daily'){
                $groupBy = 'day';
             } else {
                 $groupBy = 'month';
             }
        } else {
            if($requestData['timeline'] == 'daily'){
                $fromCarbon = new Carbon('first day of this month');
                $from  = date('Y-m-d 00:00:00', strtotime($fromCarbon));

                $toCarbon = new Carbon('last day of this month');
                $to  = date('Y-m-d 23:59:59', strtotime($toCarbon));
                $where = " and created_at between '" . $from . "' and '" . $to . "'";

                $groupBy = 'day';

            } else {
                $fromCarbon = new Carbon('first day of january');
                $from  = date('Y-m-d 00:00:00', strtotime($fromCarbon));

                $toCarbon = new Carbon('last day of december');
                $to  = date('Y-m-d 23:59:59', strtotime($toCarbon));
                $where = " and created_at between '" . $from . "' and '" . $to . "'";

                $groupBy = 'month';
            }
        }

        /*$test = 'select IFNULL(balance,0) as balance,created_at,MONTH(created_at) month, DAY(created_at) day from accounts where household_id = '.$household.' '.$where.'  group by '.$groupBy.'';
        echo "<pre>";print_r($test);exit();*/

        $chartResult = \DB::select('select IFNULL(balance,0) as balance,created_at,MONTH(created_at) month, DAY(created_at) day from accounts where household_id = '.$household.' '.$where.'  group by '.$groupBy.'');

        $yAxis = [];
        $xAxis = [];
        for ($i=0; $i < count($chartResult) ; $i++) {

             $seriesData['name']  = $chartResult[$i]->balance;
             $seriesData['value']  = $chartResult[$i]->balance;
             $yAxis[]  = $seriesData;
             $xAxis[] = date('Y-m-d', strtotime($chartResult[$i]->created_at));

        }

        $response = [];
        $response['xAxis'] = $xAxis;
        $response['yAxis'] = $yAxis;
        return $response;
    }

    public function updateAccountFields($account_id, $data)
    {

        $accounts_data = Accounts::find($account_id);

        if ($accounts_data) {
            // $accounts_data['name'] = $data['household_name'];
            $accounts_data = $accounts_data->update($data);
        }

        return $accounts_data;
    }

    public function getClosedAccounts()
    {
        $date = Carbon::now()->format('Y-m-d');
        $accounts = Accounts::where([['status_id','=',16],['last_update','=',$date]])->get();
        return $accounts;
    }
}