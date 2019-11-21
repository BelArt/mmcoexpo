<?php

namespace App\User\Cli\Task;

use App\Common\Library\Amo\Amo;
use App\Common\Library\Amo\AmoException;
use App\Common\Library\Amo\AmoRestApi;
use App\User\Common\Constants;
use Phalcon\Cli\Task;
use Phalcon\Logger\Adapter;

/**
 * Класс для работы с каталогами amoCRM.
 *
 * @property Adapter        log
 * @property Amo|AmoRestApi amo
 * @property array          config
 */
class CatalogTask extends Task
{
    const METHOD_GET  = 'GET';
    const METHOD_POST = 'POST';

    /**
     * Id Сделок у которых есть Товары
     *
     * @var array
     */
    private $leadsIds = [];

    /**
     * Стоимость каждого товара
     *
     * @var array
     */
    private $catalogPrice = [];

    /**
     * Суммарное количество товаров в Сделках
     *
     * @var array
     */
    private $totalQuantity = [];

    /**
     * Название элементов Каталога
     *
     * @var array
     */
    private $catalogNames = [];

    /**
     * Массив статусов Сделок по их Id.
     *
     * @var array
     */
    private $leadsStatusesByIds = [];

    /**
     * Раз в час обновляет суммарные данные по каталогам.
     *
     * @example php public/cli/index.php user:mmcoexpo:catalog:recalculate
     *
     * @throws \Exception
     */
    public function recalculateAction()
    {
        $this->getLeadsData();
        $this->getLeadsIdsByCatalogElements(Constants::CATALOG_GOODS_ID);
        $this->log->notice('Получили Сделок с товарами: ' . count($this->leadsIds));

        $links = [];
        foreach ($this->leadsIds as $leadId) {
            $leadStatusId = $this->leadsStatusesByIds[$leadId] ?? null;
            if ($leadStatusId === $this->amo::STATUS_FAIL) {
                $this->log->warning("У Сделки {$leadId} Статус {$leadStatusId}, пропускаем.");

                continue;
            }

            $links[] = [
                'from'          => 'leads',
                'from_id'       => $leadId,
                'to'            => 'catalog_elements',
                'to_catalog_id' => Constants::CATALOG_GOODS_ID,
            ];
        }

        foreach (array_chunk($links, 300) as $chunkedLinks) {
            $catalogElementsLinks = $this->amo->getCatalogElementsLinksListBatch($chunkedLinks)['links'] ?? [];
            foreach ($catalogElementsLinks as $catalogElementsLink) {
                $catalogId       = $catalogElementsLink['to_id'];
                $catalogQuantity = $catalogElementsLink['quantity'];

                $this->totalQuantity[$catalogId] = ($this->totalQuantity[$catalogId] ?? 0) + $catalogQuantity;
            }
        }

        $this->log->notice('Получили общее количество по товарам: ' . print_r($this->totalQuantity, true));

        $updateCatalogElements = [];
        foreach ($this->totalQuantity as $catalogId => $totalQuantity) {
            $updateCatalogElements[] = [
                'catalog_id'    => Constants::CATALOG_GOODS_ID,
                'id'            => $catalogId,
                'name'          => $this->catalogNames[$catalogId],
                'custom_fields' => [
                    [
                        'id'     => Constants::CF_CATALOG_TOTAL_PRICE,
                        'values' => [['value' => ($this->catalogPrice[$catalogId] ?? 0) * $totalQuantity]],
                    ],
                    [
                        'id'     => Constants::CF_CATALOG_TOTAL_QUANTITY,
                        'values' => [['value' => $totalQuantity]],
                    ],
                ],
            ];
        }

        foreach (array_chunk($updateCatalogElements, 200) as $chunkedCatalogElements) {
            $this->log->notice(
                'Закидываем пакет из ' . count($chunkedCatalogElements) . ' элементов каталога на сервер.'
            );
            $response = $this->amo->setCatalogElements(
                ['request' => ['catalog_elements' => ['update' => $chunkedCatalogElements]]]
            );

            if (count($response['catalog_elements']['update']['errors'])) {
                $this->log->warning('errors: ' . print_r($response['catalog_elements']['update']['errors'], true));
            }
        }
    }

    /**
     * Получает нужные данные по Сделкам из amoCRM
     *
     * @throws AmoException
     *
     * @return void
     */
    private function getLeadsData()
    {
        $leads = $this->amo->getLeads();
        foreach ($leads as $lead) {
            $leadId       = (int)$lead['id'] ?? null;
            $leadStatusId = (int)$lead['status_id'] ?? null;
            if (!$leadId || !$leadStatusId) {
                continue;
            }

            $this->leadsStatusesByIds[$leadId] = $leadStatusId;
        }
    }

    /**
     * @param $catalogId
     * @param $id
     * @param $term
     *
     * @throws \Exception
     */
    private function getLeadsIdsByCatalogElements($catalogId, $id = null, $term = null)
    {
        $limitOffset = 0;
        $limitRows   = 100;
        do {
            $catalogElements = $this->getCatalogElementsListWithLeads($limitOffset, $limitRows, $catalogId, $id, $term);
            if ($catalogElements) {
                foreach ($catalogElements as $catalogElement) {
                    $this->catalogNames[$catalogElement['id']] = $catalogElement['name'];

                    $catalogLinkedLeadsIds = $catalogElement['leads']['id'] ?? [];
                    if ($catalogLinkedLeadsIds) {
                        $this->leadsIds = array_values(
                            array_unique(array_merge($this->leadsIds, $catalogLinkedLeadsIds))
                        );
                    }

                    $catalogPrice = $this->amo->getCustomFieldValue(
                        $catalogElement,
                        Constants::CF_CATALOG_PRICE
                    );
                    if ($catalogPrice) {
                        $this->catalogPrice[$catalogElement['id']] = $catalogPrice;
                    }
                }
            }
            $limitOffset += $limitRows;
        } while (count($catalogElements) >= $limitRows);
    }

    /**
     * @param      $limitOffset
     * @param null $limitRows
     * @param null $catalogId
     * @param null $id
     * @param null $term
     * @param null $page
     *
     * @throws \Exception
     * @return array
     */
    private function getCatalogElementsListWithLeads(
        $limitOffset = null,
        $limitRows = null,
        $catalogId = null,
        $id = null,
        $term = null,
        $page = null
    ) {
        $parameters = [];

        if (is_null($limitRows) === false) {
            $parameters['limit_rows'] = $limitRows;
        }

        if (is_null($limitOffset) === false) {
            $parameters['limit_offset'] = $limitOffset;
        }

        if (is_null($catalogId) === false) {
            $parameters['catalog_id'] = $catalogId;
        }

        if (is_null($id) === false) {
            $parameters['id'] = $id;
        }

        if (is_null($term) === false) {
            $parameters['term'] = $term;
        }

        if (is_null($page) === false) {
            $parameters['page'] = $page;
        }

        return $this->curlRequest(
            'https://mmcoexpo.amocrm.ru/api/v2/catalog_elements/',
            self::METHOD_GET,
            $parameters
        );
    }

    /**
     * Execution of the request
     *
     * @param string $url
     * @param string $method
     * @param array  $parameters
     * @param array  $headers
     * @param int    $timeout
     *
     * @throws  \Exception
     *
     * @return array
     */
    private function curlRequest($url, $method = 'GET', array $parameters = [], array $headers = [], $timeout = 30)
    {
        if (!isset($parameters['USER_LOGIN']) && !isset($parameters['USER_HASH'])) {
            $url .= '?USER_LOGIN=' . $this->config['amo']['email'] . '&USER_HASH=' . $this->config['amo']['hash'];
        }

        if ($method == self::METHOD_GET && $parameters) {
            $url .= '&' . http_build_query($parameters);
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_USERAGENT, 'amoCRM-API-client/1.0');
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_FAILONERROR, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_HEADER, false);

        if ($headers) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        if ($method == self::METHOD_POST && $parameters) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($parameters));
        }

        $response   = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($errno) {
            $this->log->error('Запрос произвести не удалось: ' . $error);

            return [];
        }

        $result = json_decode($response, true);

        if ($statusCode != 200 && $statusCode != 204) {
            $message = 'Ошибка при работе с API amoCRM: ' .
                $result['response']['error'] . ', ' .
                'code: ' . $result['response']['error_code'] . ', ' .
                (isset($result['response']['ip']) ? 'IP: ' . $result['response']['ip'] . ', ' : '') .
                (isset($result['response']['domain']) ? 'domain: ' . $result['response']['domain'] . ', ' : '') .
                'HTTP status: ' . $statusCode . '. ' .
                'Подробней об ошибках amoCRM `https://www.amocrm.ru/developers/content/api/errors`';

            $this->log->error($message);
        }

        return $result['_embedded']['items'] ?? [];
    }
}
