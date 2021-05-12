<?php

namespace App\User\Api\Controller;

use App\Common\Model\User;
use App\User\Common\Constants;
use Google_Client as GoogleClientApi;
use Phalcon\Cache\Backend\Redis;
use Phalcon\Logger\Adapter;
use Phalcon\Mvc\Controller;
use Phalcon\HTTP\Response;
use Phalcon\Queue\Beanstalk;

/**
 * Класс для работы с google sheets
 *
 * @property GoogleClientApi googleTable
 * @property Adapter         log
 * @property Redis           cache
 * @property Beanstalk       queue
 * @property User            user
 */
class GoogleTableController extends Controller
{
    /**
     * Генерирует access_token на основе code. Необходимо просто пройти по ссылке.
     *
     * @link https://core.mmco-expo.ru/mmcoexpo/google_table/code/tmldm0zrdkvsu0f4whhhehzozdlqzz09
     * @link http://core.dev-mmco-expo.ru/mmcoexpo/google_table/code/tmldm0zrdkvsu0f4whhhehzozdlqzz09
     *
     * @return Response
     */
    public function codeAction()
    {
        $code = $this->request->get('code');
        if ($code) {
            $this->googleTable->fetchAccessTokenWithAuthCode($code);

            print_r($this->googleTable->getAccessToken());
        } else {
            return $this->response->redirect($this->googleTable->createAuthUrl(), true);
        }

        return $this->response->setStatusCode(200, "OK");
    }

    /**
     * Контроллер для работы виджета import_logic
     *
     * @link https://core.mmco-expo.ru/mmcoexpo/google_table/import_logic/tmldm0zrdkvsu0f4whhhehzozdlqzz09
     *
     * @return bool
     */
    public function importLogicAction()
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

        $this->queue->choose($this->user->name . '_' . Constants::IMPORT_LOGIC_TUBE);
        $this->queue->put($leadId);
        $this->log->notice("Добавили Сделку $leadId в очередь для создания отчета.");

        return true;
    }
}
