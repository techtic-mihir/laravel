<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Sheet;

class ExcelExport implements WithMultipleSheets
{
	use Exportable;
	
    /**
    * @var Invoice $invoice
    */
    protected $excel_input;

    private $writerType = 'xlsx';

    public function __construct($excel_input)
	{
	    $this->excel_input = $excel_input;
	}

	public function sheets(): array
    {
        $data = $this->excel_input;
    	$sheets = [];

        $fixedSheets = [
            'Cover',
            'Summary',
            'Budget',
            'Tasks',
            'Control Activities',
            'Subcontrols',
            'Subcontrols & Control Activity',
            'Risk Ratings',
            'Vendors',
            'Scoring Audit Report',
            'User Report'
        ];

    	foreach ($data as $sheet_data) {
            if ($sheet_data['sheet_name'] == 'Cover') {
                $sheets[] =  new ExportCover($sheet_data);
            }

            if ($sheet_data['sheet_name'] == 'Summary') {
                $sheets[] =  new ExportSummary($sheet_data);
            }

            if ($sheet_data['sheet_name'] == 'Budget') {
                $sheets[] =  new ExportBudget($sheet_data);
            }

            if ( !in_array($sheet_data['sheet_name'], $fixedSheets) ) {
                $sheets[] =  new ExportDynamicSheet($sheet_data);
            }

            if ($sheet_data['sheet_name'] == 'Tasks') {
                $sheets[] =  new ExportTasks($sheet_data);
            }

            //export control activities sheet
            if ($sheet_data['sheet_name'] == 'Control Activities') {
                $sheets[] =  new ExportControlActivity($sheet_data);
            }

            //export subcontrols & control activity sheet
            if ($sheet_data['sheet_name'] == 'Subcontrols & Control Activity') {
                $sheets[] =  new ExportSubcontrolsControlActivity($sheet_data);
            }

            //export subcontrols sheet
            if ($sheet_data['sheet_name'] == 'Subcontrols') {
                $sheets[] =  new ExportSubcontrols($sheet_data);
            }

            //export risk ratings sheet
            if ($sheet_data['sheet_name'] == 'Risk Ratings') {
                $sheets[] =  new ExportRiskRatings($sheet_data);
            }

            //export vendors sheet
            if ($sheet_data['sheet_name'] == 'Vendors') {
                $sheets[] =  new ExportVendors($sheet_data);
            }

            //export user sheet
            if ($sheet_data['sheet_name'] == 'User Report') {
                $sheets[] =  new ExportUsers($sheet_data);
            }
        }

        return $sheets;
    }
}