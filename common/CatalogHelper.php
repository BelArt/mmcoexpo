<?php

namespace App\User\Common;

/**
 * Класс для подсчета даты окончания проекта с учетом рабочих дней
 */
class CatalogHelper
{
    const METHOD_GET  = 'GET';
    const METHOD_POST = 'POST';

    /**
     * @var string
     */
    private static $email = '';

    /**
     * @var string
     */
    private static $key = '';

    /**
     * @param $email
     * @param $key
     * @param $catalogId
     * @param $id
     * @param $term
     *
     * @throws \Exception
     * @return array
     */
    public static function getCatalogElementsWithLeads(
        $email,
        $key,
        $catalogId,
        $id,
        $term = null
    ) {
        self::$email = $email;
        self::$key   = $key;

        $entities  = [];
        $limitRows = 100;
        do {
            $catalogElements = self::getCatalogElementsListWithLeads(
                $limitRows,
                $catalogId,
                $id,
                $term
            );
            $entities        = array_merge($entities, $catalogElements);
        } while (count($catalogElements) >= $limitRows);

        return $entities;
    }

    /**
     * @param null $limitRows
     * @param null $catalogId
     * @param null $id
     * @param null $term
     * @param null $page
     *
     * @throws \Exception
     * @return array
     */
    private static function getCatalogElementsListWithLeads(
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

        return self::curlRequest(
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
     * @throws \Exception
     * @return array
     */
    private static function curlRequest(
        $url,
        $method = 'GET',
        array $parameters = [],
        array $headers = [],
        $timeout = 30
    ) {
        if (!isset($parameters['USER_LOGIN']) && !isset($parameters['USER_HASH'])) {
            $url .= '?USER_LOGIN=' . self::$email . '&USER_HASH=' . self::$key;
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
            throw new \Exception('Запрос произвести не удалось: ' . $error);
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

            throw new \Exception($message);
        }

        return $result['_embedded']['items'] ?? [];
    }
}
