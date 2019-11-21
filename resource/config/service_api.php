<?php
/**
 * добавляем в DI необходимые заказчику сервисы
 */

use App\Common\Library\Amo\Amo;
use App\Common\Library\Amo\AmoRestApi;
use App\User\Common\ReportHelper;

return [
    ['amo', function () {
        $config = $this->get('config');

        return new Amo(
            new AmoRestApi(
                $config['amo']['domain'],
                $config['amo']['email'],
                $config['amo']['hash'],
            )
        );
    }, true],

    ['googleTable', function () {
        $config = $this->get('config');

        $client = new \Google_Client();
        $client->setClientId($config['google']['client_id']);
        $client->setClientSecret($config['google']['secret']);
        $client->setRedirectUri('http://core.dev-mmco-expo.ru/mmcoexpo/google_table/code/tmldm0zrdkvsu0f4whhhehzozdlqzz09');
        $client->setScopes(['https://www.googleapis.com/auth/spreadsheets', 'https://www.googleapis.com/auth/drive']);
        $client->setAccessType('offline');
        $client->setApprovalPrompt('force');

        return $client;
    }, true],

    ['report', function () {
        return new ReportHelper();
    }],
];
