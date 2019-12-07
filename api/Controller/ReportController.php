<?php

namespace App\User\Api\Controller;

use App\Common\Library\Amo\Amo;
use App\Common\Library\Amo\AmoRestApi;
use App\Common\Model\User;
use App\User\Common\ReportHelper;
use Phalcon\Http\Response;
use Phalcon\Cache\Backend\Redis;
use Phalcon\Logger\Adapter;
use Phalcon\Mvc\Controller;

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
class ReportController extends Controller
{
    /**
     * Собирает данные для отчета верхнего уровня
     *
     * @link https://core.mmco-expo.ru/mmcoexpo/report/get_top_level_report_data/tmldm0zrdkvsu0f4whhhehzozdlqzz09
     *
     * @return Response
     */
    public function getTopLevelReportDataAction()
    {
        try {
            $topLevelMetersData = $this->report->getTopLevelReportData();
            $this->log->notice(
                'Подготовили данные для отчета верхнего уровня: ' . print_r($topLevelMetersData, true)
            );

            return $this->response->setJsonContent(
                [
                    'success' => true,
                    'data'    => $topLevelMetersData,
                    'error'   => false,
                ]
            );
        } catch (\Exception $e) {
            $this->log->error("Ошибка при получении данных для отчета Верхнего уровня $e");

            return $this->response->setJsonContent(
                [
                    'success' => false,
                    'data'    => null,
                    'error'   => 'Ошибка при получении данных для отчета Верхнего уровня.',
                ]
            );
        }
    }

    /**
     * Собирает данные для отчета Метраж
     *
     * @link https://core.mmco-expo.ru/mmcoexpo/report/get_meter_report_data/tmldm0zrdkvsu0f4whhhehzozdlqzz09
     *
     * @return Response
     */
    public function getMeterReportDataAction()
    {
        try {
            $data = $this->report->getMeterReportData();
            $this->log->notice(
                'Подготовили данные для отчета Метраж: ' . print_r($data, true)
            );

            return $this->response->setJsonContent(
                [
                    'success' => true,
                    'data'    => $data,
                    'error'   => false,
                ]
            );
        } catch (\Exception $e) {
            $this->log->error("Ошибка при получении данных для отчета Метраж $e");

            return $this->response->setJsonContent(
                [
                    'success' => false,
                    'data'    => null,
                    'error'   => 'Ошибка при получении данных для отчета Метраж.',
                ]
            );
        }
    }

    /**
     * Собирает данные для отчета Бюджет
     *
     * @link https://core.mmco-expo.ru/mmcoexpo/report/get_budget_report_data/tmldm0zrdkvsu0f4whhhehzozdlqzz09
     *
     * @return Response
     */
    public function getBudgetReportDataAction()
    {
        try {
            $data = $this->report->getBudgetReportData();
            $this->log->notice(
                'Подготовили данные для отчета Бюджет: ' . print_r($data, true)
            );

            return $this->response->setJsonContent(
                [
                    'success' => true,
                    'data'    => $data,
                    'error'   => false,
                ]
            );
        } catch (\Exception $e) {
            $this->log->error("Ошибка при получении данных для отчета Бюджет $e");

            return $this->response->setJsonContent(
                [
                    'success' => false,
                    'data'    => null,
                    'error'   => 'Ошибка при получении данных для отчета Бюджет.',
                ]
            );
        }
    }

    /**
     * Собирает данные для отчета Бюджет
     *
     * @link https://core.mmco-expo.ru/mmcoexpo/report/get_companies_report_data/tmldm0zrdkvsu0f4whhhehzozdlqzz09
     *
     * @return Response
     */
    public function getCompaniesReportDataAction()
    {
        try {
            $data = $this->report->getCompaniesReportData();
            $this->log->notice(
                'Подготовили данные для отчета Компании: ' . print_r($data, true)
            );

            return $this->response->setJsonContent(
                [
                    'success' => true,
                    'data'    => $data,
                    'error'   => false,
                ]
            );
        } catch (\Exception $e) {
            $this->log->error("Ошибка при получении данных для отчета Компании $e");

            return $this->response->setJsonContent(
                [
                    'success' => false,
                    'data'    => null,
                    'error'   => 'Ошибка при получении данных для отчета Компании.',
                ]
            );
        }
    }
}
