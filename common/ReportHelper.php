<?php

namespace App\User\Common;

use App\Common\Library\Amo\Amo;
use App\Common\Library\Amo\AmoException;
use App\Common\Library\Amo\AmoRestApi;
use App\Common\Model\User;
use Phalcon\Cache\Backend\Redis;
use Phalcon\Logger\Adapter;
use Phalcon\Mvc\User\Component;

/**
 * Класс получения данных для отчетов
 *
 * @property Adapter        log
 * @property User           user
 * @property Redis          cache
 * @property Amo|AmoRestApi amo
 * @property array          config
 */
class ReportHelper extends Component
{
    /**
     * План по количеству проданных метров
     *
     * @var int
     */
    const TOP_LEVEL_REPORT_METER_NUMBER = 335;

    /**
     * План по количеству лидов
     *
     * @var int
     */
    const TOP_LEVEL_REPORT_LEADS_NUMBER = 30;

    /**
     * План по фактической выручке
     *
     * @var int
     */
    const TOP_LEVEL_REPORT_REVENUE_FACT = 3923250;

    /**
     * План по ожидаемым поступлениям выручки
     *
     * @var int
     */
    const TOP_LEVEL_REPORT_REVENUE_INCOME = 100;

    /**
     * План по объему проданных услуг по аренде
     *
     * @var int
     */
    const TOP_LEVEL_REPORT_SERVICES_RENT = 100;

    /**
     * План по объему проданных услуг по доп.услугам
     *
     * @var int
     */
    const TOP_LEVEL_REPORT_SERVICES_SERVICES = 100;

    /**
     * План по объему проданных услуг по угловой обзорности
     *
     * @var int
     */
    const TOP_LEVEL_REPORT_SERVICES_CORNER_VISIBILITY = 100;

    /**
     * План по объему проданных услуг по полуостровной обзорности
     *
     * @var int
     */
    const TOP_LEVEL_REPORT_SERVICES_PEN_VISIBILITY = 100;

    /**
     * План по объему проданных услуг по островной обзорности
     *
     * @var int
     */
    const TOP_LEVEL_REPORT_SERVICES_ISLE_VISIBILITY = 100;

    /**
     * Массив статусов для получения общего количества метров.
     *
     * @var array
     */
    const TOP_LEVEL_REPORT_METER_STATUSES_TOTAL = [
        Constants::STATUS_UMSO_SALES_DOCUMENTS_SIGNED,
        Constants::STATUS_UMSO_SALES_BILLED,
        Constants::STATUS_UMSO_SALES_PAYMENT_RECEIVED,
        Constants::STATUS_SUCCESS,
    ];

    /**
     * Массив статусов для получения количества метров в переговорах.
     *
     * @var array
     */
    const TOP_LEVEL_REPORT_METER_STATUSES_IN_NEGOTIATIONS = [
        Constants::STATUS_UMSO_SALES_KP_AGREED,
        Constants::STATUS_UMSO_SALES_DOCUMENTS_SENT,
    ];

    /**
     * Массив статусов для получения количества метров без номера стенда.
     *
     * @var array
     */
    const TOP_LEVEL_REPORT_METER_STATUSES_NO_STAND = [
        Constants::STATUS_UMSO_SALES_NEW_REQUEST,
        Constants::STATUS_UMSO_SALES_QUALIFICATION_PASSED,
    ];

    /**
     * Массив статусов для получения фактической выручки.
     *
     * @var array
     */
    const TOP_LEVEL_REPORT_REVENUE_STATUSES_FACT = [
        Constants::STATUS_UMSO_SALES_PAYMENT_RECEIVED,
        Constants::STATUS_SUCCESS,
    ];

    /**
     * Массив статусов для получения коэффициента удержания.
     *
     * @var array
     */
    const TOP_LEVEL_REPORT_RETENTION_STATUSES = [
        Constants::STATUS_UMSO_SALES_NEW_REQUEST,
        Constants::STATUS_UMSO_SALES_QUALIFICATION_PASSED,
        Constants::STATUS_UMSO_SALES_DOCUMENTS_SENT,
        Constants::STATUS_UMSO_SALES_KP_AGREED,
        Constants::STATUS_UMSO_SALES_DOCUMENTS_SIGNED,
        Constants::STATUS_UMSO_SALES_BILLED,
        Constants::STATUS_UMSO_SALES_PAYMENT_RECEIVED,
        Constants::STATUS_SUCCESS,
    ];

    /**
     * План по кластерам
     *
     * @var int
     */
    const METER_REPORT_CLUSTER = [
        "Павильон 1" => 507,
        "Павильон 2" => 180,
        "Павильон 3" => 168,
    ];

    /**
     * Массив статусов для получения данных по кластерам.
     *
     * @var array
     */
    const METER_REPORT_CLUSTER_STATUSES = [
        Constants::STATUS_UMSO_SALES_DOCUMENTS_SENT,
        Constants::STATUS_UMSO_SALES_KP_AGREED,
        Constants::STATUS_UMSO_SALES_DOCUMENTS_SIGNED,
        Constants::STATUS_UMSO_SALES_BILLED,
        Constants::STATUS_UMSO_SALES_PAYMENT_RECEIVED,
        Constants::STATUS_SUCCESS,
    ];

    /**
     * Массив шапки таблицы по кластерам.
     *
     * @var array
     */
    const METER_CLUSTER_LABELS = [
        'Наименование кластера',
        'Всего на продажу, м<sup>2</sup>',
        'В работе, м<sup>2</sup>',
        'В работе, %',
        'Не в работе, м<sup>2</sup>',
        'Не в работе, %',
    ];

    /**
     * План по общему бюджету
     *
     * @var int
     */
    const BUDGET_REPORT_TOTAL_PLAN = 6084000;

    /**
     * План по бюджету по стендам
     *
     * @var int
     */
    const BUDGET_REPORT_TOTAL_STAND_PLAN = 5667000;

    /**
     * План по бюджету по дополнительным услугам
     *
     * @var int
     */
    const BUDGET_REPORT_TOTAL_SERVICES_PLAN = 417000;

    /**
     * План по бюджету теплых компаний
     *
     * @var int
     */
    const COMPANIES_REPORT_WARM_BUDGET = 4867200;

    /**
     * План по бюджету новых компаний
     *
     * @var int
     */
    const COMPANIES_REPORT_NEW_BUDGET = 1216800;

    /**
     * План по бюджету новых компаний
     *
     * @var int
     */
    const COMPANIES_REPORT_DEAD_BUDGET = 0;

    /**
     * План по количеству метров теплых компаний
     *
     * @var int
     */
    const COMPANIES_REPORT_WARM_METERS = 566;

    /**
     * План по количеству метров новых компаний
     *
     * @var int
     */
    const COMPANIES_REPORT_NEW_METERS = 243;

    /**
     * План по количеству метров мертвых компаний
     *
     * @var int
     */
    const COMPANIES_REPORT_DEAD_METERS = 0;

    /**
     * Индивидуальный план по метрам
     *
     * @var int
     */
    const TOP_LEVEL_REPORT_METERS_INDIVIDUAL_PLAN = 63;

    /**
     * Стандартный план по метрам
     *
     * @var int
     */
    const TOP_LEVEL_REPORT_METERS_STANDART_PLAN = 737;

    /**
     * Выручка теплых компаний в прошлом году
     *
     * @var int
     */
    const PREVIOUS_YEAR_LEADS_PRICE = 137438;

    /**
     * @var array
     */
    const PARTNERS_GOODS = [
        Constants::CATALOG_UMSO_PACKAGE_PARTNER_1,
        Constants::CATALOG_UMSO_PACKAGE_PARTNER_2,
    ];

    /**
     * @var array
     */
    const DELEGATES_GOODS = [
        Constants::CATALOG_UMSO_PACKAGE_DELEGATE_1,
        Constants::CATALOG_UMSO_PACKAGE_DELEGATE_2,
    ];

    /**
     * Собирает данные для отчета верхнего уровня
     *
     * @throws AmoException
     * @throws \Exception
     *
     * @return array
     */
    public function getTopLevelReportData()
    {
        $activeLeadsPipelineBMSOSales = $this->getLeadsByStatuses(
            Constants::PIPELINE_UMSO_SALES,
            array_merge($this->amo->getStatusesActive(Constants::PIPELINE_UMSO_SALES), [142])
        );

        return $this->getTopLevelData($activeLeadsPipelineBMSOSales);
    }

    /**
     * Собирает данные для отчета Метраж
     *
     * @throws AmoException
     * @throws \Exception
     *
     * @return array
     */
    public function getMeterReportData()
    {
        $activeLeadsPipelineBMSOSales = $this->getLeadsByStatuses(
            Constants::PIPELINE_UMSO_SALES,
            array_merge($this->amo->getStatusesActive(Constants::PIPELINE_UMSO_SALES), [142])
        );

        return $this->getMeterData($activeLeadsPipelineBMSOSales);
    }

    /**
     * Собирает данные для отчета Бюджет
     *
     * @throws AmoException
     * @throws \Exception
     *
     * @return array
     */
    public function getBudgetReportData()
    {
        $activeLeadsPipelineBMSOSales = $this->getLeadsByStatuses(
            Constants::PIPELINE_UMSO_SALES,
            array_merge($this->amo->getStatusesActive(Constants::PIPELINE_UMSO_SALES), [142])
        );

        return $this->getBudgetData($activeLeadsPipelineBMSOSales);
    }

    /**
     * Собирает данные для отчета Компании
     *
     * @throws AmoException
     * @throws \Exception
     *
     * @return array
     */
    public function getCompaniesReportData()
    {
        $activeLeadsPipelineBMSOSales = $this->getLeadsByStatuses(
            Constants::PIPELINE_UMSO_SALES,
            array_merge($this->amo->getStatusesActive(Constants::PIPELINE_UMSO_SALES), [142])
        );

        return $this->getCompaniesData($activeLeadsPipelineBMSOSales);
    }

    /**
     * Собирает данные для отчета Компании
     *
     * @param array $activeLeadsPipelineBMSOSales Массив активных Сделок amoCRM
     *
     * @throws \Exception
     *
     * @return array
     */
    private function getCompaniesData($activeLeadsPipelineBMSOSales)
    {
        $warmCompanies       = 0;
        $deadCompanies       = 0;
        $newCompanies        = 0;
        $warmCompaniesBudget = 0;
        $deadCompaniesBudget = 0;
        $newCompaniesBudget  = 0;
        $warmCompaniesMeters = 0;
        $deadCompaniesMeters = 0;
        $newCompaniesMeters  = 0;
        foreach ($activeLeadsPipelineBMSOSales as $lead) {
            $leadStatusId = $lead['status_id'];
            if (in_array($leadStatusId, self::TOP_LEVEL_REPORT_METER_STATUSES_TOTAL)
                && isset($lead['linked_company_id'])
                && $lead['linked_company_id']
            ) {
                $companyProfile  = $this->amo->getCustomFieldValue($lead, Constants::CF_LEAD_COMPANY_PROFILE);
                $leadMetersTotal = (int)$this->amo->getCustomFieldValue($lead, Constants::CF_LEAD_TOTAL_FOOTAGE);
                $leadPrice       = $lead['price'] ?? 0;
                if ($companyProfile === 'Теплая') {
                    $warmCompaniesBudget += $leadPrice;
                    $warmCompanies++;
                    if ($leadMetersTotal) {
                        $warmCompaniesMeters += $leadMetersTotal;
                    }
                } elseif ($companyProfile === 'Мертвая') {
                    $deadCompaniesBudget += $leadPrice;
                    $deadCompanies++;
                    if ($leadMetersTotal) {
                        $deadCompaniesMeters += $leadMetersTotal;
                    }
                } elseif ($companyProfile === 'Новая') {
                    $newCompaniesBudget += $leadPrice;
                    $newCompanies++;
                    if ($leadMetersTotal) {
                        $newCompaniesMeters += $leadMetersTotal;
                    }
                }
            }
        }

        $companiesInWork = $warmCompanies + $deadCompanies + $newCompanies;

        return [
            'companies' => [
                'new_companies_number'   => $newCompanies,
                'new_companies_percent'  => $this->getFactPercent($newCompanies, $companiesInWork),
                'warm_companies_number'  => $warmCompanies,
                'warm_companies_percent' => $this->getFactPercent($warmCompanies, $companiesInWork),
                'dead_companies_number'  => $deadCompanies,
                'dead_companies_percent' => $this->getFactPercent($deadCompanies, $companiesInWork),
            ],
            'budget'    => [
                'new_fact_number'   => $newCompaniesBudget,
                'new_plan'          => self::COMPANIES_REPORT_NEW_BUDGET,
                'new_fact_percent'  => $this->getFactPercent($newCompaniesBudget, self::COMPANIES_REPORT_NEW_BUDGET),
                'warm_fact_number'  => $warmCompaniesBudget,
                'warm_plan'         => self::COMPANIES_REPORT_WARM_BUDGET,
                'warm_fact_percent' => $this->getFactPercent($warmCompaniesBudget, self::COMPANIES_REPORT_WARM_BUDGET),
                'dead_fact_number'  => $deadCompaniesBudget,
                'dead_plan'         => self::COMPANIES_REPORT_DEAD_BUDGET,
                'dead_fact_percent' => $this->getFactPercent($deadCompaniesBudget, self::COMPANIES_REPORT_DEAD_BUDGET),
            ],
            'meters'    => [
                'new_fact_number'   => $newCompaniesMeters,
                'new_plan'          => self::COMPANIES_REPORT_NEW_METERS,
                'new_fact_percent'  => $this->getFactPercent($newCompaniesMeters, self::COMPANIES_REPORT_NEW_METERS),
                'warm_fact_number'  => $warmCompaniesMeters,
                'warm_plan'         => self::COMPANIES_REPORT_WARM_METERS,
                'warm_fact_percent' => $this->getFactPercent($warmCompaniesMeters, self::COMPANIES_REPORT_WARM_METERS),
                'dead_fact_number'  => $deadCompaniesMeters,
                'dead_plan'         => self::COMPANIES_REPORT_DEAD_METERS,
                'dead_fact_percent' => $this->getFactPercent($deadCompaniesMeters, self::COMPANIES_REPORT_DEAD_METERS),
            ],
        ];
    }

    /**
     * Собирает данные для отчета Бюджет
     *
     * @param array $activeLeadsPipelineBMSOSales Массив активных Сделок amoCRM
     *
     * @throws \Exception
     *
     * @return array
     */
    private function getBudgetData($activeLeadsPipelineBMSOSales)
    {
        $totalBudget         = 0;
        $totalStandBudget    = 0;
        $totalServicesBudget = 0;
        foreach ($activeLeadsPipelineBMSOSales as $lead) {
            $leadStatusId = $lead['status_id'];
            if (in_array($leadStatusId, self::TOP_LEVEL_REPORT_METER_STATUSES_TOTAL)) {
                $leadPrice = $lead['price'] ?? 0;
                if ($leadPrice) {
                    $totalBudget += $leadPrice;
                }

                $totalStandBudgetLead = (int)$this->amo->getCustomFieldValue($lead, Constants::CF_LEAD_RENT_BUDGET);
                if ($totalStandBudgetLead) {
                    $totalStandBudget += $totalStandBudgetLead;
                }

                $totalServicesBudgetLead = (int)$this->amo->getCustomFieldValue(
                    $lead,
                    Constants::CF_LEAD_SERVICES_BUDGET
                );
                if ($totalServicesBudgetLead) {
                    $totalServicesBudget += $totalServicesBudgetLead;
                }
            }
        }

        $mmsoData        = $this->user->data['dashboard'][Constants::PIPELINE_UMSO_SALES] ?? [];
        $thisSunday      = (new \DateTime('sunday this week', new \DateTimeZone(Constants::TIMEZONE)))->format('d-m-Y');
        $labels          = [];
        $budgetFact      = [];
        $percentFact     = [];
        $weekNum         = 1;
        $currentFactData = $mmsoData['budget_report']['current'] ?? [];
        foreach ($currentFactData as $sundayDate => $weekData) {
            if ($thisSunday === $sundayDate) {
                break;
            }

            $labels[]      = "Неделя$weekNum";
            $budgetFact[]  = $weekData['fact_number'];
            $percentFact[] = $weekData['fact_percent'];
            $weekNum++;
        }

        $labels[]      = "Неделя$weekNum";
        $budgetFact[]  = $totalBudget;
        $percentFact[] = $this->getFactPercent($totalBudget, self::BUDGET_REPORT_TOTAL_PLAN);

        $budgetFactPrev   = [];
        $percentFactPrev  = [];
        $previousFactData = $mmsoData['budget_report']['previous'] ?? [];
        foreach ($previousFactData as $weekNumber => $previousWeekData) {
            if ($weekNumber === $weekNum) {
                break;
            }

            $budgetFactPrev[]  = $previousWeekData['fact_number'];
            $percentFactPrev[] = $previousWeekData['fact_percent'];
        }

        $budgetPlan      = [];
        $percentPlan     = [];
        $currentPlanData = $mmsoData['budget_report']['plan'] ?? [];
        foreach ($currentPlanData as $weekNumber => $weekPlanData) {
            if ($weekNumber === $weekNum) {
                break;
            }

            $budgetPlan[]  = $weekPlanData['fact_number'];
            $percentPlan[] = $weekPlanData['fact_percent'];
        }

        return [
            'budget'                => [
                'total_fact_number'           => $totalBudget,
                'total_plan'                  => self::BUDGET_REPORT_TOTAL_PLAN,
                'total_fact_percent'          => $this->getFactPercent($totalBudget, self::BUDGET_REPORT_TOTAL_PLAN),
                'total_stand_fact_number'     => $totalStandBudget,
                'total_stand_plan'            => self::BUDGET_REPORT_TOTAL_STAND_PLAN,
                'total_stand_fact_percent'    => $this->getFactPercent(
                    $totalStandBudget,
                    self::BUDGET_REPORT_TOTAL_STAND_PLAN
                ),
                'total_services_fact_number'  => $totalServicesBudget,
                'total_services_plan'         => self::BUDGET_REPORT_TOTAL_SERVICES_PLAN,
                'total_services_fact_percent' => $this->getFactPercent(
                    $totalServicesBudget,
                    self::BUDGET_REPORT_TOTAL_SERVICES_PLAN
                ),
            ],
            'graph_budget_rubles'   => [
                'labels'         => $labels,
                'fact_name'      => 'Актуальный факт',
                'fact_data'      => $budgetFact,
                'plan_name'      => 'Актуальный план',
                'plan_data'      => $budgetPlan,
                'prev_fact_name' => 'Прошлогодний факт',
                'prev_fact_data' => $budgetFactPrev,
            ],
            'graph_budget_percents' => [
                'labels'         => $labels,
                'fact_name'      => 'Актуальный факт',
                'fact_data'      => $percentFact,
                'plan_name'      => 'Актуальный план',
                'plan_data'      => $percentPlan,
                'prev_fact_name' => 'Прошлогодний факт',
                'prev_fact_data' => $percentFactPrev,
            ],

        ];
    }

    /**
     * Собирает данные для отчета Метраж
     *
     * @param array $activeLeadsPipelineBMSOSales Массив активных Сделок amoCRM
     *
     * @throws \Exception
     * @return array
     */
    private function getMeterData($activeLeadsPipelineBMSOSales)
    {
        $metersIndividualTotal = 0;
        $metersTotal           = 0;
        $leadCluster           = [
            "Павильон 1" => 0,
            "Павильон 2" => 0,
            "Павильон 3" => 0,
        ];

        foreach ($activeLeadsPipelineBMSOSales as $lead) {
            $leadStatusId = $lead['status_id'];
            if (in_array($leadStatusId, self::TOP_LEVEL_REPORT_METER_STATUSES_TOTAL)) {
                $leadMetersTotal = (int)$this->amo->getCustomFieldValue($lead, Constants::CF_LEAD_TOTAL_FOOTAGE);
                if ($leadMetersTotal) {
                    $metersTotal += $leadMetersTotal;
                }

                $buildingType = $this->amo->getCustomFieldValue($lead, Constants::CF_LEAD_BUILDING_TYPE);
                if ($buildingType == 'Индивидуальная') {
                    $metersIndividualTotal += $leadMetersTotal;
                }
            }

            if (in_array($leadStatusId, self::METER_REPORT_CLUSTER_STATUSES)) {
                $leadClusterLead = $this->amo->getCustomFieldValue($lead, Constants::CF_LEAD_CLUSTER);
                $leadMetersTotal = (int)$this->amo->getCustomFieldValue($lead, Constants::CF_LEAD_TOTAL_FOOTAGE);
                if ($leadClusterLead && isset($leadCluster[$leadClusterLead]) && $leadMetersTotal) {
                    $leadCluster[$leadClusterLead] += $leadMetersTotal;
                }
            }
        }

        $mmsoData        = $this->user->data['dashboard'][Constants::PIPELINE_UMSO_SALES] ?? [];
        $thisSunday      = (new \DateTime('sunday this week', new \DateTimeZone(Constants::TIMEZONE)))->format('d-m-Y');
        $labels          = [];
        $meterFact       = [];
        $percentFact     = [];
        $weekNum         = 1;
        $currentFactData = $mmsoData['meters_report']['current'] ?? [];
        foreach ($currentFactData as $sundayDate => $weekData) {
            if ($thisSunday === $sundayDate) {
                break;
            }

            $labels[]      = "Неделя$weekNum";
            $meterFact[]   = $weekData['fact_number'];
            $percentFact[] = $weekData['fact_percent'];
            $weekNum++;
        }

        $labels[]      = "Неделя$weekNum";
        $meterFact[]   = $metersTotal;
        $percentFact[] = $this->getFactPercent($metersTotal, self::TOP_LEVEL_REPORT_METER_NUMBER);

        $meterFactPrev    = [];
        $percentFactPrev  = [];
        $previousFactData = $mmsoData['meters_report']['previous'] ?? [];
        foreach ($previousFactData as $weekNumber => $previousWeekData) {
            if ($weekNumber === $weekNum) {
                break;
            }

            $meterFactPrev[]   = $previousWeekData['fact_number'];
            $percentFactPrev[] = $previousWeekData['fact_percent'];
        }

        $meterPlan       = [];
        $percentPlan     = [];
        $currentPlanData = $mmsoData['meters_report']['plan'] ?? [];
        foreach ($currentPlanData as $weekNumber => $weekPlanData) {
            if ($weekNumber === $weekNum) {
                break;
            }

            $meterPlan[]   = $weekPlanData['fact_number'];
            $percentPlan[] = $weekPlanData['fact_percent'];
        }

        $clusterPercent          = [];
        $clusterNotInWorkNum     = [];
        $clusterNotInWorkPercent = [];
        foreach ($leadCluster as $leadClusterName => $leadClusterValue) {
            $clusterPercent[$leadClusterName] = $this->getFactPercent(
                $leadClusterValue,
                self::METER_REPORT_CLUSTER[$leadClusterName]
            );

            $clusterNotInWorkNum[$leadClusterName] = self::METER_REPORT_CLUSTER[$leadClusterName] - $leadClusterValue;

            $clusterNotInWorkPercent[$leadClusterName] = 100 - $clusterPercent[$leadClusterName];
        }

        return [
            'meter'            => [
                'fact_number'  => $metersTotal,
                'plan'         => self::TOP_LEVEL_REPORT_METER_NUMBER,
                'fact_percent' => $this->getFactPercent($metersTotal, self::TOP_LEVEL_REPORT_METER_NUMBER),
            ],
            'meter_individual' => $metersIndividualTotal,
            'meter_standard'   => $metersTotal - $metersIndividualTotal,
            'graph_meter'      => [
                'labels'         => $labels,
                'fact_name'      => 'Актуальный факт',
                'fact_data'      => $meterFact,
                'plan_name'      => 'Актуальный план',
                'plan_data'      => $meterPlan,
                'prev_fact_name' => 'Прошлогодний факт',
                'prev_fact_data' => $meterFactPrev,
            ],
            'graph_percent'    => [
                'labels'         => $labels,
                'fact_name'      => 'Актуальный факт',
                'fact_data'      => $percentFact,
                'plan_name'      => 'Актуальный план',
                'plan_data'      => $percentPlan,
                'prev_fact_name' => 'Прошлогодний факт',
                'prev_fact_data' => $percentFactPrev,
            ],
            'cluster'          => [
                'labels'              => self::METER_CLUSTER_LABELS,
                'in_work_number'      => $leadCluster,
                'plan'                => self::METER_REPORT_CLUSTER,
                'in_work_percent'     => $clusterPercent,
                'not_in_work_number'  => $clusterNotInWorkNum,
                'not_in_work_percent' => $clusterNotInWorkPercent,
            ],
        ];
    }

    /**
     * Собирает данные для отчета Верхнего уровня.
     *
     * @param array $activeLeadsPipelineBMSOSales Массив активных Сделок воронки Продажи amoCRM
     *
     * @throws \Exception
     *
     * @return array
     */
    private function getTopLevelData(array $activeLeadsPipelineBMSOSales)
    {
        $metersTotal                  = 0;
        $metersIndividualTotal        = 0;
        $numberNoStand                = 0;
        $numberNegotiations           = 0;
        $inNegotiationsPrice          = 0;
        $newCompanies                 = 0;
        $warmCompanies                = 0;
        $deadCompanies                = 0;
        $leadsNumber                  = 0;
        $expectedRevenue              = 0;
        $factRevenue                  = 0;
        $revenueIncome                = 0;
        $revenueRent                  = 0;
        $revenueServices              = 0;
        $revenueCornerVisibility      = 0;
        $revenuePenVisibility         = 0;
        $revenueIsleVisibility        = 0;
        $activeLeadsWithWarmCompanies = 0;
        $thisYearLeadsPrice           = 0;

        /** @var \DateTime[] $period */
        $period                = new \DatePeriod(
            new \DateTime('monday this week'),
            new \DateInterval('P1D'),
            new \DateTime('monday next week')
        );
        $thisWeekDaysTimestamp = [];
        foreach ($period as $day) {
            $thisWeekDaysTimestamp[] = $day->getTimestamp();
        }

        foreach ($activeLeadsPipelineBMSOSales as $lead) {
            $leadStatusId = $lead['status_id'];
            if (in_array($leadStatusId, self::TOP_LEVEL_REPORT_METER_STATUSES_TOTAL)) {
                $leadMetersTotal = (int)$this->amo->getCustomFieldValue($lead, Constants::CF_LEAD_TOTAL_FOOTAGE);
                if ($leadMetersTotal) {
                    $metersTotal += $leadMetersTotal;
                }

                $buildingType = $this->amo->getCustomFieldValue($lead, Constants::CF_LEAD_BUILDING_TYPE);
                if ($buildingType == 'Индивидуальная') {
                    $metersIndividualTotal += $leadMetersTotal;
                }
            }

            if (in_array($leadStatusId, self::TOP_LEVEL_REPORT_METER_STATUSES_IN_NEGOTIATIONS)) {
                $leadMetersDesired = (int)$this->amo->getCustomFieldValue($lead, Constants::CF_LEAD_DESIRED_FOOTAGE);
                if ($leadMetersDesired) {
                    $numberNegotiations += $leadMetersDesired;
                }

                $inNegotiationsPrice += $lead['price'] ?? 0;
            }

            if (in_array($leadStatusId, self::TOP_LEVEL_REPORT_METER_STATUSES_NO_STAND)) {
                $leadMetersDesired = (int)$this->amo->getCustomFieldValue($lead, Constants::CF_LEAD_DESIRED_FOOTAGE);
                if ($leadMetersDesired) {
                    $numberNoStand += $leadMetersDesired;
                }
            }

            $leadsNumber++;

            if ($leadStatusId == Constants::STATUS_UMSO_SALES_DOCUMENTS_SIGNED) {
                $expectedRevenueLead = (int)$this->amo->getCustomFieldValue(
                    $lead,
                    Constants::CF_LEAD_EXPECTED_REVENUE_PRE
                );
                if ($expectedRevenueLead) {
                    $expectedRevenue += $expectedRevenueLead;
                }
            }

            if ($lead['status_id'] != Constants::STATUS_SUCCESS
                && $lead['status_id'] != Constants::STATUS_FAIL
                && isset($lead['linked_company_id'])
                && $lead['linked_company_id']
            ) {
                $companyProfile = $this->amo->getCustomFieldValue($lead, Constants::CF_LEAD_COMPANY_PROFILE);
                if ($companyProfile === 'Новая') {
                    $newCompanies++;
                } elseif ($companyProfile === 'Теплая') {
                    $warmCompanies++;
                } elseif ($companyProfile === 'Мертвая') {
                    $deadCompanies++;
                }

                if ($companyProfile === 'Теплая'
                    && in_array($leadStatusId, self::TOP_LEVEL_REPORT_RETENTION_STATUSES)
                ) {
                    $activeLeadsWithWarmCompanies++;
                }
            }

            if (in_array($leadStatusId, self::TOP_LEVEL_REPORT_METER_STATUSES_TOTAL)) {
                $totalStandBudgetLead = (int)$this->amo->getCustomFieldValue($lead, Constants::CF_LEAD_RENT_BUDGET);
                if ($totalStandBudgetLead) {
                    $revenueRent += $totalStandBudgetLead;
                }

                $totalServicesBudgetLead = (int)$this->amo->getCustomFieldValue(
                    $lead,
                    Constants::CF_LEAD_SERVICES_BUDGET
                );
                if ($totalServicesBudgetLead) {
                    $revenueServices += $totalServicesBudgetLead;
                }

                $expositionLocation = $this->amo->getCustomFieldValue($lead, Constants::CF_LEAD_EXPOSITION_LOCATION);
                if ($expositionLocation == 'Угловая') {
                    $revenueCornerVisibility++;
                } elseif ($expositionLocation == 'Полуостров'
                ) {
                    $revenuePenVisibility++;
                } elseif ($expositionLocation == 'Остров'
                ) {
                    $revenueIsleVisibility++;
                }

                $nextPrePaymentDate = $this->amo->getCustomFieldValue($lead, Constants::CF_LEAD_NEXT_PRE_PAYMENT_DATE);
                if (in_array((new \DateTime($nextPrePaymentDate))->getTimestamp(), $thisWeekDaysTimestamp)) {
                    $expectedRevenueLeadPre = (int)$this->amo->getCustomFieldValue(
                        $lead,
                        Constants::CF_LEAD_EXPECTED_REVENUE_PRE
                    );
                    if ($expectedRevenueLeadPre) {
                        $revenueIncome += $expectedRevenueLeadPre;
                    }
                }

                $nextPostPaymentDate = $this->amo->getCustomFieldValue(
                    $lead,
                    Constants::CF_LEAD_NEXT_POST_PAYMENT_DATE
                );
                if (in_array((new \DateTime($nextPostPaymentDate))->getTimestamp(), $thisWeekDaysTimestamp)) {
                    $expectedRevenueLeadPost = (int)$this->amo->getCustomFieldValue(
                        $lead,
                        Constants::CF_LEAD_EXPECTED_REVENUE_POST
                    );
                    if ($expectedRevenueLeadPost) {
                        $revenueIncome += $expectedRevenueLeadPost;
                    }
                }

                $companyProfile = $this->amo->getCustomFieldValue($lead, Constants::CF_LEAD_COMPANY_PROFILE);
                if ($companyProfile === 'Теплая'
                    && isset($lead['linked_company_id'])
                    && $lead['linked_company_id']) {
                    $thisYearLeadsPrice += $lead['price'] ?? 0;
                }

                $factRevenue += $lead['price'] ?? 0;
            }
        }

        $companiesInWork             = $newCompanies + $warmCompanies + $deadCompanies;
        $retentionRateNow            = $this->getNumberCompaniesInWork();
        $previousYearLeadsWithTagNum = 17; // $this->getLeadsByTagAndPipeline(
        //     Constants::TAG_UMSO_2018,
        //     Constants::PIPELINE_MMSO_SALES,
        //     [142]
        // );

        $retentionCoefficient = $this->getFactPercent(
            $activeLeadsWithWarmCompanies,
            $previousYearLeadsWithTagNum
        );

        $metersStandTotal          = $metersTotal - $metersIndividualTotal;
        $thisYearLeadsPriceAverage = $warmCompanies ? floor($thisYearLeadsPrice / $warmCompanies) : $thisYearLeadsPrice;
        $leadsPriceAverageDiff     = $thisYearLeadsPriceAverage - self::PREVIOUS_YEAR_LEADS_PRICE;

        $partnerPrice  = 0;
        $delegatePrice = 0;
        $goods         = $this->amo->getCatalogElements(Constants::CATALOG_GOODS_ID) ? : [];
        foreach ($goods as $good) {
            $goodId = (int)$good['id'] ?? null;
            if (in_array($goodId, self::PARTNERS_GOODS)) {
                $catalogPrice = $this->amo->getCustomFieldValue($good, Constants::CF_CATALOG_TOTAL_PRICE);
                if ($catalogPrice) {
                    $partnerPrice += $catalogPrice;

                    continue;
                }
            }

            if (in_array($goodId, self::DELEGATES_GOODS)) {
                $catalogPrice = $this->amo->getCustomFieldValue($good, Constants::CF_CATALOG_TOTAL_PRICE);
                if ($catalogPrice) {
                    $delegatePrice += $catalogPrice;

                    continue;
                }
            }
        }

        $revenueServices = $revenueServices - $partnerPrice - $delegatePrice;

        return [
            'meter'          => [
                'fact_number'             => $metersTotal,
                'plan'                    => self::TOP_LEVEL_REPORT_METER_NUMBER,
                'fact_percent'            => $this->getFactPercent($metersTotal, self::TOP_LEVEL_REPORT_METER_NUMBER),
                'in_negotiations'         => $numberNegotiations,
                'in_negotiations_percent' => $this->getFactPercent(
                    $numberNegotiations,
                    self::TOP_LEVEL_REPORT_METER_NUMBER
                ),
                'no_stand'                => $numberNoStand,
            ],
            'companies'      => [
                'cold_companies_number'  => $newCompanies,
                'warm_companies_number'  => $warmCompanies,
                'dead_companies_number'  => $deadCompanies,
                'companies_in_work'      => $companiesInWork,
                'cold_companies_percent' => $this->getFactPercent($newCompanies, $companiesInWork, false),
                'warm_companies_percent' => $this->getFactPercent($warmCompanies, $companiesInWork, false),
                'dead_companies_percent' => $this->getFactPercent($deadCompanies, $companiesInWork, false),
            ],
            'retention_rate' => [
                'plan'         => $previousYearLeadsWithTagNum,
                'fact_number'  => $retentionRateNow,
                'fact_percent' => $retentionCoefficient,
            ],
            'revenue'        => [
                'expected'          => [
                    'fact_number' => self::TOP_LEVEL_REPORT_REVENUE_FACT,
                ],
                'fact'              => [
                    'fact_number'  => $factRevenue,
                    'fact_percent' => $this->getFactPercent($factRevenue, self::TOP_LEVEL_REPORT_REVENUE_FACT),
                ],
                'income'            => [
                    'plan'        => self::TOP_LEVEL_REPORT_REVENUE_INCOME,
                    'fact_number' => $revenueIncome,
                ],
                'in_negotiations'   => [
                    'fact_number' => $inNegotiationsPrice,
                ],
                'rent'              => [
                    'plan'         => self::TOP_LEVEL_REPORT_SERVICES_RENT,
                    'fact_number'  => $revenueRent,
                    'fact_percent' => $this->getFactPercent($revenueRent, self::TOP_LEVEL_REPORT_SERVICES_RENT),
                ],
                'services'          => [
                    'plan'         => self::TOP_LEVEL_REPORT_SERVICES_SERVICES,
                    'fact_number'  => $revenueServices,
                    'fact_percent' => $this->getFactPercent(
                        $revenueServices,
                        self::TOP_LEVEL_REPORT_SERVICES_SERVICES
                    ),
                ],
                'corner_visibility' => [
                    'plan'         => self::TOP_LEVEL_REPORT_SERVICES_CORNER_VISIBILITY,
                    'fact_number'  => $revenueCornerVisibility,
                    'fact_percent' => $this->getFactPercent(
                        $revenueCornerVisibility,
                        self::TOP_LEVEL_REPORT_SERVICES_CORNER_VISIBILITY
                    ),
                ],
                'pen_visibility'    => [
                    'plan'         => self::TOP_LEVEL_REPORT_SERVICES_PEN_VISIBILITY,
                    'fact_number'  => $revenuePenVisibility,
                    'fact_percent' => $this->getFactPercent(
                        $revenuePenVisibility,
                        self::TOP_LEVEL_REPORT_SERVICES_PEN_VISIBILITY
                    ),
                ],
                'isle_visibility'   => [
                    'plan'         => self::TOP_LEVEL_REPORT_SERVICES_ISLE_VISIBILITY,
                    'fact_number'  => $revenueIsleVisibility,
                    'fact_percent' => $this->getFactPercent(
                        $revenueIsleVisibility,
                        self::TOP_LEVEL_REPORT_SERVICES_ISLE_VISIBILITY
                    ),
                ],
                'partner'           => [
                    'fact_number' => $partnerPrice,
                ],
                'delegate'          => [
                    'fact_number' => $delegatePrice,
                ],
            ],
            'leads'          => [
                'plan'         => self::TOP_LEVEL_REPORT_LEADS_NUMBER,
                'fact_number'  => $leadsNumber,
                'fact_percent' => $this->getFactPercent($leadsNumber, self::TOP_LEVEL_REPORT_LEADS_NUMBER),
            ],
            'stands'         => [
                'individual' => [
                    'plan'         => self::TOP_LEVEL_REPORT_METERS_INDIVIDUAL_PLAN,
                    'fact_number'  => $metersIndividualTotal,
                    'fact_percent' => $this->getFactPercent(
                        $metersIndividualTotal,
                        self::TOP_LEVEL_REPORT_METERS_INDIVIDUAL_PLAN
                    ),
                ],
                'standard'   => [
                    'plan'         => self::TOP_LEVEL_REPORT_METERS_STANDART_PLAN,
                    'fact_number'  => $metersStandTotal,
                    'fact_percent' => $this->getFactPercent(
                        $metersStandTotal,
                        self::TOP_LEVEL_REPORT_METERS_STANDART_PLAN
                    ),
                ],
            ],
            'warm_companies' => [
                'revenue_last_year'          => self::PREVIOUS_YEAR_LEADS_PRICE,
                'revenue_this_year'          => $thisYearLeadsPriceAverage,
                'revenue_difference_number'  => $leadsPriceAverageDiff,
                'revenue_difference_percent' => $this->getFactPercent(
                    $leadsPriceAverageDiff,
                    self::PREVIOUS_YEAR_LEADS_PRICE
                ),
            ],
        ];
    }

    /**
     * @param      $factNumber
     * @param      $planNumber
     * @param bool $floor
     *
     * @return float|int
     */
    public function getFactPercent($factNumber, $planNumber, $floor = true)
    {
        if (!$planNumber) {
            return 0;
        }

        $result = $factNumber * 100 / $planNumber;

        return $floor ? floor($result) : $result;
    }

    /**
     * @return int
     */
    private function getNumberCompaniesInWork()
    {
        try {
            $statuses = array_merge($this->amo->getStatusesActive(Constants::PIPELINE_UMSO_SALES), [142]);

            return $this->getDataFromAjaxFromAmo(
                null,
                null,
                Constants::PIPELINE_UMSO_SALES,
                $statuses
            );
        } catch (\Exception $e) {
            $this->log->error("Ошибка при получении активных статусов: $e");

            return 0;
        }
    }

    /**
     * Получает количество активных Сделок у переданной воронки.
     *
     * @param int   $cfId       Id поля, по которому ищем
     * @param int   $cfEnumId   Id значение поля, по которому делаем запрос
     * @param int   $pipelineId Id воронки, по которой получаем активные Сделки.
     * @param array $statuses   Массив статусов активных Сделок, по ним выборка.
     *
     * @return int
     */
    private function getDataFromAjaxFromAmo(
        int $cfId = null,
        int $cfEnumId = null,
        int $pipelineId = null,
        array $statuses = []
    ) {
        $filter = [
            'useFilter'    => 'Y',
            'element_type' => 3,
            'json'         => 1,
        ];

        if ($cfId && $cfEnumId) {
            $filter['filter']['cf'] = [$cfId => $cfEnumId];
        }

        if ($pipelineId && $statuses) {
            $filter['filter']['pipe'] = [$pipelineId => $statuses];
        }

        $url = 'https://' . $this->user->name . '.amocrm.ru/ajax/contacts/list/?'
            . 'USER_LOGIN=' . $this->config['amo']['email']
            . '&USER_HASH=' . $this->config['amo']['hash'];

        $result = $this->sendAjax($url, $filter);

        return $result['response']['summary']['persons_count'] ?? 0;
    }

    /**
     * Отправляет AJAX запрос в amoCRM.
     *
     * @param string $url
     * @param array  $filter Фильтр для запроса в amoCRM.
     *
     * @return array
     */
    private function sendAjax(string $url, array $filter = [])
    {
        $curl = \curl_init();
        \curl_setopt(
            $curl,
            \CURLOPT_URL,
            $url
        );
        \curl_setopt($curl, \CURLOPT_CONNECTTIMEOUT, 30);
        \curl_setopt(
            $curl,
            \CURLOPT_USERAGENT,
            'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/51.0.2704.103 Safari/537.36'
        );
        curl_setopt($curl, \CURLOPT_FOLLOWLOCATION, true);
        \curl_setopt($curl, \CURLOPT_HEADER, false);
        \curl_setopt(
            $curl,
            \CURLOPT_HTTPHEADER,
            [
                'X-Requested-With: XMLHttpRequest',
            ]
        );
        \curl_setopt($curl, \CURLOPT_RETURNTRANSFER, true);
        \curl_setopt($curl, \CURLOPT_POST, 1);
        \curl_setopt($curl, \CURLOPT_HEADER, false);
        \curl_setopt($curl, \CURLOPT_POSTFIELDS, http_build_query($filter));

        $response = \json_decode(\curl_exec($curl), true);

        if (!$response) {
            $this->log->error(
                'Не удалось выполнить ajax запрос: '
                . \print_r($filter, true)
                . ' на url: ' . $url
                . ' response: ' . print_r($response, true)
            );

            return [];
        }

        return $response;
    }

    /**
     * Получает Сделки из amoCRM с выбранных статусов нужной воронки.
     *
     * @param int   $pipelineId
     * @param array $statusesId
     *
     * @throws AmoException
     *
     * @return array
     */
    public function getLeadsByStatuses(int $pipelineId, array $statusesId)
    {
        $result = [];
        $leads  = $this->amo->getLeads(
            null,
            null,
            null,
            null,
            $statusesId
        );

        foreach ($leads as $lead) {
            if ((int)$lead['pipeline_id'] !== $pipelineId) {
                continue;
            }

            $result[] = $lead;
        }

        unset($leads);

        return $result;
    }

    /**
     * @param int      $tagId
     * @param int|null $pipelineId
     * @param array    $statuses
     * @param string   $resultKey
     *
     * @return int|mixed
     */
    private function getLeadsByTagAndPipeline(
        int $tagId,
        int $pipelineId = null,
        array $statuses = [],
        string $resultKey = 'count'
    ) {
        $filter = [
            'useFilter'  => 'y',
            'tags_logic' => 'or',
            'tag'        => [$tagId],
            'json'       => 1,
        ];

        if ($pipelineId && $statuses) {
            $filter['filter']['pipe'] = [$pipelineId => $statuses];
        }

        $url = 'https://' . $this->user->name . '.amocrm.ru/ajax/leads/list/?'
            . 'USER_LOGIN=' . $this->config['amo']['email']
            . '&USER_HASH=' . $this->config['amo']['hash'];

        $result = $this->sendAjax($url, $filter);

        return $result['response']['summary'][$resultKey] ?? 0;
    }
}
