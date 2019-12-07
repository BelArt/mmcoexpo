<?php

namespace App\User\Cli\Task;

use App\Common\Library\Amo\Amo;
use App\Common\Library\Amo\AmoException;
use App\Common\Library\Amo\AmoRestApi;
use App\Common\Model\User;
use App\User\Common\Constants;
use App\User\Common\ReportHelper;
use Phalcon\Cache\Backend\Redis;
use Phalcon\Cli\Task;
use Phalcon\Logger\Adapter;
use Phalcon\Mvc\Collection\Exception as MongoCollectionException;

/**
 * Класс для работы с отчетами.
 *
 * @property Adapter        log
 * @property User           user
 * @property Redis          cache
 * @property Amo|AmoRestApi amo
 * @property array          config
 * @property ReportHelper   report
 */
class ReportTask extends Task
{
    /**
     * Таска по воскресениям заносит данные в БД.
     *
     * @example php public/cli/index.php user:mmcoexpo:report:update
     *
     * @throws AmoException
     * @throws \Exception
     */
    public function updateAction()
    {
        $activeLeadsPipelineSales = $this->report->getLeadsByStatuses(
            Constants::PIPELINE_SALES,
            array_merge($this->amo->getStatusesActive(Constants::PIPELINE_SALES), [142])
        );

        $this->updateMeterReportDataAction($activeLeadsPipelineSales);
        $this->updateBudgetReportDataAction($activeLeadsPipelineSales);
    }

    /**
     * Получает данные о метраже и заносит их в БД.
     *
     * @param array $activeLeadsPipelineSales
     *
     * @throws MongoCollectionException
     * @throws \Exception
     */
    private function updateMeterReportDataAction(array $activeLeadsPipelineSales)
    {
        $metersTotal = 0;
        foreach ($activeLeadsPipelineSales as $lead) {
            $leadStatusId = $lead['status_id'];
            if (in_array($leadStatusId, (array)$this->report::TOP_LEVEL_REPORT_METER_STATUSES_TOTAL)) {
                $leadMetersTotal = (int)$this->amo->getCustomFieldValue($lead, Constants::CF_LEAD_TOTAL_FOOTAGE);
                if ($leadMetersTotal) {
                    $metersTotal += $leadMetersTotal;
                }
            }
        }

        $metersPercent = $this->report->getFactPercent($metersTotal, $this->report::TOP_LEVEL_REPORT_METER_NUMBER);
        $todayDate     = (new \DateTimeImmutable('today', new \DateTimeZone(Constants::TIMEZONE)))->format('d-m-Y');

        $this->user->data['dashboard'][Constants::PIPELINE_SALES]['meters_report']['current'][$todayDate] = [
            'fact_number'  => $metersTotal,
            'fact_percent' => $metersPercent,
        ];

        $mmsoData            = $this->user->data['dashboard'][Constants::PIPELINE_SALES] ?? [];
        $currentFactData     = $mmsoData['meters_report']['current'] ?? [];
        $previousFactData    = $mmsoData['meters_report']['previous'] ?? [];
        $metersWithPrevWeeks = 0;
        foreach ($currentFactData as $weekData) {
            $metersWithPrevWeeks += $weekData['fact_number'];
        }

        if (count($currentFactData) > 2) {
            $leftWeeks   = count($previousFactData) - count($currentFactData);
            $planNum     = ($this->report::TOP_LEVEL_REPORT_METER_NUMBER - $metersWithPrevWeeks) / $leftWeeks;
            $planPercent = $this->report->getFactPercent($planNum, $this->report::TOP_LEVEL_REPORT_METER_NUMBER);

            $this->user->data['dashboard'][Constants::PIPELINE_SALES]['meters_report']['plan'][] = [
                'fact_number'  => $planNum,
                'fact_percent' => $planPercent,
            ];
        }

        if ($this->user->save() == false) {
            $errors = null;
            foreach ($this->user->getMessages() as $message) {
                $errors .= $message . '; ';
            }

            $this->log->error('Ошибка при сохранении данных: ' . $errors);
        }

        $this->log->notice("$todayDate записали в БД факт по общей застройке: $metersTotal (м) и $metersPercent (%)");
    }

    /**
     * Получает данные о бюджете и заносит их в БД.
     *
     * @param array $activeLeadsPipelineSales
     *
     * @throws MongoCollectionException
     * @throws \Exception
     */
    private function updateBudgetReportDataAction(array $activeLeadsPipelineSales)
    {
        $totalBudget = 0;
        foreach ($activeLeadsPipelineSales as $lead) {
            $leadStatusId = $lead['status_id'];
            if (in_array($leadStatusId, (array)$this->report::TOP_LEVEL_REPORT_METER_STATUSES_TOTAL)) {
                $leadPrice = $lead['price'] ?? 0;
                if ($leadPrice) {
                    $totalBudget += $leadPrice;
                }
            }
        }

        $budgetPercent = $this->report->getFactPercent($totalBudget, $this->report::BUDGET_REPORT_TOTAL_PLAN);
        $todayDate     = (new \DateTimeImmutable('today', new \DateTimeZone(Constants::TIMEZONE)))->format('d-m-Y');

        $this->user->data['dashboard'][Constants::PIPELINE_SALES]['budget_report']['current'][$todayDate] = [
            'fact_number'  => $totalBudget,
            'fact_percent' => $budgetPercent,
        ];

        $mmsoData            = $this->user->data['dashboard'][Constants::PIPELINE_SALES] ?? [];
        $currentFactData     = $mmsoData['budget_report']['current'] ?? [];
        $previousFactData    = $mmsoData['budget_report']['previous'] ?? [];
        $budgetWithPrevWeeks = 0;
        foreach ($currentFactData as $weekData) {
            $budgetWithPrevWeeks += $weekData['fact_number'];
        }

        if (count($currentFactData) > 1) {
            $leftWeeks   = count($previousFactData) - count($currentFactData);
            $planNum     = ($this->report::BUDGET_REPORT_TOTAL_PLAN - $budgetWithPrevWeeks) / $leftWeeks;
            $planPercent = $this->report->getFactPercent($planNum, $this->report::BUDGET_REPORT_TOTAL_PLAN);

            $this->user->data['dashboard'][Constants::PIPELINE_SALES]['budget_report']['plan'][] = [
                'fact_number'  => $planNum,
                'fact_percent' => $planPercent,
            ];
        }

        if ($this->user->save() == false) {
            $errors = null;
            foreach ($this->user->getMessages() as $message) {
                $errors .= $message . '; ';
            }

            $this->log->error('Ошибка при сохранении данных: ' . $errors);
        }

        $this->log->notice("$todayDate записали в БД факт по бюджету: $totalBudget (м) и $budgetPercent (%)");
    }
}
