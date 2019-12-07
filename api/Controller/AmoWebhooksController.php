<?php

namespace App\User\Api\Controller;

use App\Common\Library\Amo\Amo;
use App\Common\Library\Amo\AmoRestApi;
use App\Common\Model\User;
use App\User\Common\Constants;
use Phalcon\Cache\Backend\Redis;
use Phalcon\Logger\Adapter;
use Phalcon\Mvc\Controller;
use Phalcon\Queue\Beanstalk;

/**
 * Класс для обработки вебхуков amoCRM.
 *
 * @property Adapter        log
 * @property User           user
 * @property Redis          cache
 * @property Amo|AmoRestApi amo
 * @property array          config
 * @property Beanstalk      queue
 */
class AmoWebhooksController extends Controller
{
    /**
     * Массив статусов для добавления Сделки в таблицу
     *
     * @var array
     */
    const REPORT_STATUSES = [
        Constants::STATUS_SALES_DOCUMENTS_SIGNED,
        Constants::STATUS_SALES_BILLED,
        Constants::STATUS_SALES_PAYMENT_RECEIVED,
        Constants::STATUS_SUCCESS,
    ];

    /**
     * Обрабатывает вебхуки от amoCRM по смене статуса Сделок.
     *
     * @link https://core.mmco-expo.ru/mmcoexpo/amo_webhooks/lead_status/tmldm0zrdkvsu0f4whhhehzozdlqzz09
     *
     * @return bool
     */
    public function leadStatusAction()
    {
        try {
            $this->response->setStatusCode(200, 'OK')->send();

            $leadId = (int)$this->request->get('leads')['status'][0]['id'] ?? null;
            if (!$leadId) {
                $this->log->error('Получили некорректный вебхук от amoCRM: ' . print_r($this->request->get(), true));

                return false;
            }

            $hash = md5(json_encode($this->request->get()));
            if ($this->cache->exists($hash)) {
                $this->log->warning('Получили повторный запрос. Выходим.');

                return false;
            }
            $this->cache->save($hash, true, 10);

            $lead = $this->amo->getLead($leadId);
            if (!$lead) {
                $this->log->error('Не смогли получить Сделку из amoCRM по id ' . $leadId);

                return false;
            }

            $leadPipeline = (int)$lead['pipeline_id'] ?? null;
            $leadStatus   = (int)$lead['status_id'] ?? null;
            if (in_array($leadStatus, self::REPORT_STATUSES)) {
                $this->addLeadToReport($leadId);
            }

            if ($leadPipeline === Constants::PIPELINE_SALES && $leadStatus === Constants::STATUS_FAIL) {
                $this->deleteLeadFromReport($leadId);
            }

            return true;
        } catch (\Exception $e) {
            $this->log->error("Ошибка при получении данных для отчета Верхнего уровня $e");

            return false;
        }
    }

    /**
     * Добавляет в очередь сделку для выгрузки по ней данных в Google Table.
     *
     * @param int $leadId Id Сделки в amoCRM
     *
     * @return bool
     */
    private function addLeadToReport(int $leadId)
    {
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

    /**
     * Добавляет в очередь сделку для удаления ее из Google Table.
     *
     * @param int $leadId Id Сделки в amoCRM
     *
     * @return bool
     */
    private function deleteLeadFromReport(int $leadId)
    {
        $this->queue->choose($this->user->name . '_' . Constants::DOCUMENTS_SIGNED_REPORT_TUBE);
        $this->queue->put(
            [
                'lead_id' => $leadId,
                'action'  => 'deleteLeadFromReport',
            ]
        );
        $this->log->notice("Добавили Сделку $leadId в очередь для выгрузки отчета.");

        return true;
    }
}
