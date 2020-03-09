<?php

namespace App\Console\Commands;

use App\Imports\Template\ChartOfAccountImport;
use App\Model\Accounting\ChartOfAccount;
use App\Model\Accounting\ChartOfAccountType;
use App\Model\Accounting\CutOff;
use App\Model\Manufacture\ManufactureFormula\ManufactureFormula;
use App\Model\Master\Branch;
use App\Model\Master\PricingGroup;
use App\Model\Master\User;
use App\Model\Master\Warehouse;
use App\Model\Project\Project;
use App\Model\Purchase\PurchaseRequest\PurchaseRequest;
use App\Model\SettingJournal;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class AlterData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'dev:alter-data';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Temporary';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $projects = Project::all();
        foreach ($projects as $project) {
            $this->line('Clone '.$project->code);
            Artisan::call('tenant:database:backup-clone', ['project_code' => strtolower($project->code)]);
            $this->line('Alter '.$project->code);
            config()->set('database.connections.tenant.database', env('DB_DATABASE').'_'.strtolower($project->code));
            DB::connection('tenant')->reconnect();

            DB::connection('tenant')->statement('SET FOREIGN_KEY_CHECKS=0;');
            DB::connection('tenant')->beginTransaction();
            $this->setData();

            SettingJournal::query()->truncate();
            ChartOfAccount::query()->truncate();
            ChartOfAccountType::query()->truncate();

            Artisan::call('db:seed', [
                '--database' => 'tenant',
                '--class' => 'ChartOfAccountTypeSeeder',
                '--force' => true,
            ]);

            Excel::import(new ChartOfAccountImport(), storage_path('template/chart_of_accounts_manufacture.xlsx'));

            Artisan::call('db:seed', [
                '--database' => 'tenant',
                '--class' => 'SettingJournalSeeder',
                '--force' => true,
            ]);

            DB::connection('tenant')->commit();
            DB::connection('tenant')->statement('SET FOREIGN_KEY_CHECKS=1;');
        }
    }

    private function setData()
    {
        $formulas = ManufactureFormula::all();
        foreach ($formulas as $formula) {
            if ($formula->form->request_approval_to == null) {
                $formula->form->request_approval_to = User::first()->id;
                $formula->form->save();
            }
        }

        $cutOffs = CutOff::all();
        foreach ($cutOffs as $cutOff) {
            if ($cutOff->form->request_approval_to == null) {
                $cutOff->form->request_approval_to = User::first()->id;
                $cutOff->form->save();
            }
        }

        $purchaseRequests = PurchaseRequest::all();
        foreach ($purchaseRequests as $purchaseRequest) {
            if ($purchaseRequest->form->request_approval_to == null) {
                $purchaseRequest->form->request_approval_to = User::first()->id;
                $purchaseRequest->form->save();
            }
        }

        if (PricingGroup::all()->count() == 0) {
            $pricingGroup = new PricingGroup;
            $pricingGroup->label = 'DEFAULT';
            $pricingGroup->save();
        }

        if (Branch::all()->count() == 0) {
            $branch = new Branch;
        } else {
            $branch = Branch::find(1);
        }

        $branch->name = 'CENTRAL';
        $branch->save();

        if (Warehouse::all()->count() == 0) {
            $warehouse = new Warehouse;
        } else {
            $warehouse = Warehouse::find(1);
        }

        $warehouse->branch_id = $branch->id;
        $warehouse->name = 'MAIN WAREHOUSE';
        $warehouse->save();

        foreach (Warehouse::all() as $warehouse) {
            $warehouse->branch_id = $branch->id;
            $warehouse->save();
        }
    }
}
