<?php

namespace App\User\Api\Controller;

use App\Common\Library\Amo\Amo;
use App\Common\Library\Amo\AmoRestApi;
use App\Common\Model\User;
use App\User\Common\Constants;
use App\User\Common\ReportHelper;
use Phalcon\Http\Response;
use Phalcon\Cache\Backend\Redis;
use Phalcon\Logger\Adapter;
use Phalcon\Mvc\Controller;
use Phalcon\Queue\Beanstalk;

/**
 * Класс для работы с отчетами.
 *
 * @property Adapter        log
 * @property User           user
 * @property Redis          cache
 * @property Amo|AmoRestApi amo
 * @property array          config
 * @property ReportHelper   report
 * @property Beanstalk      queue
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

    /**
     * Собирает данные для отчета Бюджет
     *
     * @link https://core.mmco-expo.ru/mmcoexpo/report/exponents_report_update/tmldm0zrdkvsu0f4whhhehzozdlqzz09
     *
     * @return Response
     */
    public function exponentsReportUpdateAction(): bool
    {
        $this->log->notice('Получили данные из виджета: ' . print_r($this->request->get(), true));

        $leadId = (int)$this->request->get('lead_id');
        if (!$leadId) {
            $this->log->warning('В запросе отсутствует id Сделки. Выходим.');

            return false;
        }

        $hash = md5(json_encode($this->request->get()));
        if ($this->cache->exists($hash)) {
            $this->log->warning('Получили повторный запрос. Выходим.');

            return false;
        }
        $this->cache->save($hash, true, 10);

        $this->queue->choose($this->user->name . '_' . Constants::DOCUMENTS_SIGNED_REPORT_TUBE);
        $this->queue->put(
            [
                'lead_id' => $leadId,
                'action'  => 'addLeadToReport',
            ]
        );
        $this->log->notice("Добавили Сделку $leadId в очередь для выгрузки отчета.");

        return true;
    }
}
