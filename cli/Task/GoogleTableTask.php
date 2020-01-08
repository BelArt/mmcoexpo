<?php

namespace App\User\Cli\Task;

use App\Common\Library\Amo\Amo;
use App\Common\Library\Amo\AmoException;
use App\Common\Library\Amo\AmoRestApi;
use App\Common\Model\User;
use App\User\Common\CatalogHelper;
use App\User\Common\Constants;
use Google_Client as GoogleClient;
use Google_Service_Drive as GoogleServiceDrive;
use Google_Service_Drive_DriveFile as GoogleServiceDriveDriveFile;
use Google_Service_Sheets as GoogleServiceSheets;
use Google_Service_Sheets_BatchUpdateSpreadsheetRequest as GoogleServiceSheetsBatchUpdateSpreadsheetRequest;
use Phalcon\Cache\Backend\Redis;
use Phalcon\Cli\Task;
use Phalcon\Logger\Adapter;
use Phalcon\Queue\Beanstalk;
use Phalcon\Queue\Beanstalk\Job;
use React\EventLoop\Factory as Loop;

/**
 * Класс для заполнения таблиц Google Sheets.
 *
 * @property Adapter        log
 * @property Beanstalk      queue
 * @property User           user
 * @property Redis          cache
 * @property Amo|AmoRestApi amo
 * @property GoogleClient   googleTable
 * @property array          config
 */
class GoogleTableTask extends Task
{
    const SHEET_REQUEST_TEMPLATE_TITLE          = 'Заявка ММСО';
    const SHEET_REQUEST_TEMPLATE_TITLE_4_METERS = 'Заявка ММСО 4 кв.м.';

    /**
     * Демон, который чекает тьюбу из виджета importLogic.
     *
     * @example php public/cli/index.php user:mmcoexpo:google_table:run
     */
    public function runAction()
    {
        $loop = Loop::create();
        $this->queue->watch($this->user->name . '_' . Constants::IMPORT_LOGIC_TUBE);

        $loop->addPeriodicTimer(
            1,
            function () {
                $job = $this->queue->reserve(1);
                if ($job === false) {
                    return false;
                }

                $leadId = $job->getBody();
                if (!$leadId) {
                    $this->log->error('Пришла джоба без id Сделки: ' . print_r($leadId, true));

                    return false;
                }

                $result = $this->importLogic($leadId);
                $this->processJobByResult($job, $result);

                return true;
            }
        );

        $loop->addSignal(
            SIGINT,
            $func = function () use ($loop, &$func) {
                $loop->removeSignal(SIGINT, $func);
                $loop->stop();
                $this->log->notice('Пришел сигнал ' . SIGINT);
            }
        );

        $loop->run();
    }

    /**
     * Демон, который чекает тьюбу от вебхуков amoCRM.
     *
     * @example php public/cli/index.php user:mmcoexpo:google_table:report
     */
    public function reportAction()
    {
        $loop = Loop::create();
        $this->queue->watch($this->user->name . '_' . Constants::DOCUMENTS_SIGNED_REPORT_TUBE);

        $loop->addPeriodicTimer(
            1,
            function () {
                $job = $this->queue->reserve(1);
                if ($job === false) {
                    return false;
                }

                $jobBody = $job->getBody();
                $leadId  = $jobBody['lead_id'] ?? null;
                $action  = $jobBody['action'] ?? null;
                if (!$leadId || !$action) {
                    $this->log->error('Пришла джоба без id Сделки или экшена: ' . print_r($jobBody, true));

                    return false;
                }

                if (!method_exists($this, $action)) {
                    $this->log->error('Вызван несуществующий метод в джобе: ' . print_r($jobBody, true));

                    return false;
                }

                /**
                 * @uses addLeadToReport
                 * @uses deleteLeadFromReport
                 */
                $result = $this->{$action}($leadId);
                $this->processJobByResult($job, $result);

                return true;
            }
        );

        $loop->addSignal(
            SIGINT,
            $func = function () use ($loop, &$func) {
                $loop->removeSignal(SIGINT, $func);
                $loop->stop();
                $this->log->notice('Пришел сигнал ' . SIGINT);
            }
        );

        $loop->run();
    }

    /**
     * Создает отчет по Сделки из amoCRM
     *
     * @param int $leadId Id Сделки amoCRM
     *
     * @throws AmoException
     *
     * @return int
     */
    private function importLogic(int $leadId)
    {
        $this->log->notice("Начали обрабатывать Сделку $leadId");

        $dataToInsert = $this->getDataToInsertFromLead($leadId);
        $this->log->notice("У Сделки $leadId собрали данные для записи: " . print_r($dataToInsert, true));

        $spreadSheetId = $this->copyTemplateSpreadSheet(
            $dataToInsert['spreadsheet_id'],
            $dataToInsert['spreadsheet_title']
        );
        if (!$spreadSheetId) {
            return Constants::JOB_RESULT_FAIL_WITH_REPEAT;
        }

        $this->insertData($spreadSheetId, $dataToInsert);

        $spreadSheetLink = "https://docs.google.com/spreadsheets/d/$spreadSheetId/";
        $noteId          = $this->amo->addNote(
            "Сформирована {$dataToInsert['spreadsheet_title']}: $spreadSheetLink",
            $leadId,
            $this->amo::ENTITY_TYPE_LEAD
        );
        $this->log->notice("К Сделке $leadId добавили примечание $noteId с ссылкой на таблицу $spreadSheetLink");

        return Constants::JOB_RESULT_SUCCESS;
    }

    /**
     * Добавляет данные в GoogleSpreadSheets
     *
     * @param $spreadSheetId
     * @param $dataToInsert
     *
     * @return bool
     */
    private function insertData($spreadSheetId, $dataToInsert)
    {
        $service  = new GoogleServiceSheets($this->googleTable);
        $response = $service->spreadsheets->get($spreadSheetId);
        $sheetId  = $response->getSheets()[0]->getProperties()->getSheetId();

        $range   = 'A1:AY';
        $table   = $service->spreadsheets_values->get($spreadSheetId, $range);
        $rowData = $table->values;
        if (!$rowData) {
            $this->log->error('Данные в таблице не найдены.');

            return false;
        }

        $rowsToDelete = [];
        if ($dataToInsert['total_footage'] == 4) {
            $rowData[3][2]  = $dataToInsert['company_name']; // НАИМЕНОВАНИЕ ЭКСПОНЕНТА
            $rowData[7][2]  = $dataToInsert['stand_number']; // НОМЕР СТЕНДА
            $rowData[18][3] = floor($dataToInsert['total_footage'] / 2); // КОЛИЧЕСТВО БЕЙДЖЕЙ
        } else {
            $rowData[3][2]  = $dataToInsert['company_name']; // НАИМЕНОВАНИЕ ЭКСПОНЕНТА
            $rowData[7][2]  = $dataToInsert['stand_number']; // НОМЕР СТЕНДА
            $rowData[12][2] = $dataToInsert['price_meter']; // АРЕНДА ПЛОЩАДИ ставка за 1 кв. м.
            $rowData[12][3] = $dataToInsert['total_footage']; // АРЕНДА ПЛОЩАДИ кол-во кв. м
            $rowData[12][4] = $dataToInsert['price_meter'] * $dataToInsert['total_footage']; // АРЕНДА ПЛОЩАДИ итого
            $rowData[13][2] = $dataToInsert['building_price_meter']; // ЗАСТРОЙКА ставка за 1 кв. м.
            $rowData[13][3] = $dataToInsert['total_footage']; // ЗАСТРОЙКА кол-во кв. м
            $rowData[13][4] = $dataToInsert['building_price_meter'] * $dataToInsert['total_footage']; // ЗАСТРОЙКА итого
            $rowData[17][2] = $dataToInsert['registration_fee']; // РЕГИСТРАЦИОННЫЙ ВЗНОС
            $rowData[17][3] = 1; // РЕГИСТРАЦИОННЫЙ ВЗНОС кол-во
            $rowData[17][4] = $dataToInsert['registration_fee']; // РЕГИСТРАЦИОННЫЙ ВЗНОС итого
            $rowData[18][3] = floor($dataToInsert['total_footage'] / 3); // КОЛИЧЕСТВО БЕЙДЖЕЙ

            $rowByLocation = [
                'Линейная'   => 21,
                'Угловая'    => 22,
                'Полуостров' => 23,
                'Остров'     => 24,
            ];

            $saleByLocation = [
                'Линейная'   => 0,
                'Угловая'    => 10,
                'Полуостров' => 15,
                'Остров'     => 20,
            ];

            foreach ($rowByLocation as $locationName => $locationRow) {
                if ($dataToInsert['exposition_location'] != $locationName) {
                    $rowsToDelete[] = $locationRow;
                } else {
                    $discount = $saleByLocation[$locationName] - $dataToInsert['discount'];

                    $rowData[$locationRow][4] = $rowData[12][4] * $discount / 100;
                    $rowData[$locationRow][2] = $discount . '%';
                }
            }
        }

        foreach ($rowData as $rowNumber => $rowCells) {
            if (isset($rowCells[1]) && $rowCells[1] == 'ИТОГО:') {
                $rowData[$rowNumber][3] = $dataToInsert['total_price']; // ИТОГО
            }

            $rowSerialNumber = $rowCells[0] ?? null;
            if (!$rowSerialNumber) {
                continue;
            }

            if (!isset($dataToInsert[$rowSerialNumber])) {
                $rowsToDelete[] = $rowNumber;

                continue;
            }

            $quantity  = $dataToInsert[$rowSerialNumber]['quantity'] ?? 0;
            $price     = $dataToInsert[$rowSerialNumber]['price'] ?? 0;
            $goodPrice = $quantity * $price;

            $rowData[$rowNumber][2] = $quantity;
            $rowData[$rowNumber][3] = $price;
            $rowData[$rowNumber][4] = $goodPrice;
        }

        try {
            $result = $service->spreadsheets_values->update(
                $spreadSheetId,
                $range,
                new \Google_Service_Sheets_ValueRange(['values' => $rowData]),
                ['valueInputOption' => 'USER_ENTERED']
            );
            $this->log->notice('Результат обновления таблицы: ' . print_r($result, true));
        } catch (\Exception $e) {
            $this->log->error('Ошибка при изменение данных в таблице: ' . $e->getMessage());

            return false;
        }

        $requests = [];
        foreach (array_reverse($rowsToDelete) as $row) {
            $requests[] = [
                'deleteDimension' => [
                    'range' => [
                        "sheetId"    => $sheetId,
                        "dimension"  => "ROWS",
                        "startIndex" => $row,
                        "endIndex"   => $row + 1,
                    ],
                ],
            ];
        }

        $googleBathUpdate = new GoogleServiceSheetsBatchUpdateSpreadsheetRequest();
        $googleBathUpdate->setRequests([$requests]);
        try {
            $service->spreadsheets->batchUpdate($spreadSheetId, $googleBathUpdate);
        } catch (\Exception $e) {
            $this->log->error('Ошибка изменения листов: ' . $e->getMessage());

            return false;
        }

        return true;
    }

    /**
     * @param int $leadId
     *
     * @throws AmoException
     * @throws \Exception
     *
     * @return array
     */
    private function getDataToInsertFromLead(int $leadId)
    {
        $dataFromLead = [];

        $lead = $this->amo->getLead($leadId);
        if (!$lead) {
            return $dataFromLead;
        }

        $totalFootage = $this->amo->getCustomFieldValue($lead, Constants::CF_LEAD_TOTAL_FOOTAGE);
        $dataFromLead = [
            'total_price'          => $lead['price'] ?? 0,
            'company_name'         => $lead['company_name'] ?? null,
            'stand_number'         => $this->amo->getCustomFieldValue($lead, Constants::CF_LEAD_STAND_NUMBER) ? : 0,
            'price_meter'          => $this->amo->getCustomFieldValue($lead, Constants::CF_LEAD_PRICE_METER) ? : 0,
            'total_footage'        => $totalFootage,
            'building_price_meter' => $this->amo->getCustomFieldValue($lead, Constants::CF_LEAD_BUILDING_PRICE_METER)
                ? : 0,
            'registration_fee'     => $this->amo->getCustomFieldValue($lead, Constants::CF_LEAD_REGISTRATION_FEE) ? : 0,
            'discount'             => $this->amo->getCustomFieldValue($lead, Constants::CF_LEAD_DISCOUNT) ? : 0,
            'exposition_location'  => $this->amo->getCustomFieldValue($lead, Constants::CF_LEAD_EXPOSITION_LOCATION)
                ? : 0,
            'spreadsheet_id'       => ($totalFootage == 4)
                ? $this->config['google']['four_meters_spreadsheet_id']
                : $this->config['google']['request_spreadsheet_id'],
            'spreadsheet_title'    => ($totalFootage == 4)
                ? self::SHEET_REQUEST_TEMPLATE_TITLE_4_METERS
                : self::SHEET_REQUEST_TEMPLATE_TITLE,
        ];

        $links = [
            [
                'from'          => 'leads',
                'from_id'       => $leadId,
                'to'            => 'catalog_elements',
                'to_catalog_id' => Constants::CATALOG_GOODS_ID,
            ],
        ];

        $goodsQuantity        = [];
        $catalogElementsLinks = $this->amo->getCatalogElementsLinksListBatch($links)['links'] ?? [];
        foreach ($catalogElementsLinks as $catalogElementsLink) {
            $goodsQuantity[$catalogElementsLink['to_id']] = $catalogElementsLink['quantity'];
        }

        if (!$goodsQuantity) {
            return $dataFromLead;
        }

        $goods = CatalogHelper::getCatalogElementsWithLeads(
            $this->config['amo']['email'],
            $this->config['amo']['hash'],
            Constants::CATALOG_GOODS_ID,
            array_column($catalogElementsLinks, 'to_id')
        );
        foreach ($goods as $good) {
            $serialNumber = $this->amo->getCustomFieldValue(
                $good,
                Constants::CF_CATALOG_SERIAL_NUMBER
            );

            $dataFromLead[$serialNumber] = [
                'name'     => $good['name'],
                'price'    => $this->amo->getCustomFieldValue(
                    $good,
                    Constants::CF_CATALOG_PRICE
                ),
                'quantity' => $goodsQuantity[$good['id']] ?? 0,
            ];
        }

        return $dataFromLead;
    }

    /**
     * Copy an existing file.
     *
     * @param GoogleServiceDrive $service      Drive API service instance.
     * @param string             $originFileId ID of the origin file to copy.
     * @param string             $copyTitle    Title of the copy.
     *
     * @return GoogleServiceDriveDriveFile|null
     */
    private function copyFile($service, $originFileId, $copyTitle)
    {
        $copiedFile = new GoogleServiceDriveDriveFile();
        $copiedFile->setName($copyTitle);
        try {
            return $service->files->copy($originFileId, $copiedFile);
        } catch (\Exception $e) {
            $this->log->error("Ошибка при копировании таблицы: $e");
        }

        return null;
    }

    /**
     * Создает новую Google Таблицу.
     *
     * @param string $spreadSheetId    Id копируемого документа
     * @param string $spreadSheetTitle Название нового документа
     *
     * @return string|null
     */
    public function copyTemplateSpreadSheet(string $spreadSheetId, string $spreadSheetTitle)
    {
        $googleDriveService = new GoogleServiceDrive($this->googleTable);

        $newFile = $this->copyFile(
            $googleDriveService,
            $spreadSheetId,
            $spreadSheetTitle
        );

        if (!$newFile) {
            return null;
        }

        return (string)$newFile->getId();
    }

    /**
     * Добавляет или обновляет данные по Сделке из amoCRM в Google Table Экспоненты.
     *
     * @param int $leadId Id Сделки amoCRM
     *
     * @throws AmoException
     * @throws \Exception
     *
     * @return int
     */
    private function addLeadToReport(int $leadId)
    {
        $this->log->notice("Начали обрабатывать Сделку $leadId");

        $lead = $this->amo->getLead($leadId);

        $exhibitor = '';
        $companyId = $lead['linked_company_id'] ?? null;
        if ($companyId) {
            $company   = $this->amo->getCompany($companyId);
            $exhibitor = $this->amo->getCustomFieldValue($company, Constants::CF_COMPANY_FULL_NAME) ? : '';
        }

        $leadPrice = $lead['price'] ?? 0;

        $todayDate = (new \DateTime('now', new \DateTimeZone(Constants::TIMEZONE)))->format('d.m.Y');
        $standNum  = $this->amo->getCustomFieldValue($lead, Constants::CF_LEAD_STAND_NUMBER) ? : '';
        $executor  = $this->amo->getCustomFieldValue($lead, Constants::CF_LEAD_OUR_YUR_FACE) ? : '';
        $cluster   = $this->amo->getCustomFieldValue($lead, Constants::CF_LEAD_CLUSTER) ? : '';

        $contractNum   = $this->amo->getCustomFieldValue($lead, Constants::CF_LEAD_CONTRACT_NUMBER) ? : '';
        $regFee        = $this->amo->getCustomFieldValue($lead, Constants::CF_LEAD_REGISTRATION_FEE) ? : '';
        $regFeeNum     = $this->amo->getCustomFieldValue($lead, Constants::CF_LEAD_REGISTRATION_FEE_NUMBER) ? : '';
        $totalFootage  = $this->amo->getCustomFieldValue($lead, Constants::CF_LEAD_TOTAL_FOOTAGE) ? : '';
        $buildingType  = $this->amo->getCustomFieldValue($lead, Constants::CF_LEAD_BUILDING_TYPE) ? : '';
        $exposition    = $this->amo->getCustomFieldValue($lead, Constants::CF_LEAD_EXPOSITION_LOCATION) ? : '';
        $discount      = $this->amo->getCustomFieldValue($lead, Constants::CF_LEAD_DISCOUNT) ? : '';
        $actSum        = $this->amo->getCustomFieldValue($lead, Constants::CF_LEAD_ACT_SUM) ? : '';
        $companyType   = $this->amo->getCustomFieldValue($lead, Constants::CF_LEAD_COMPANY_TYPE) ? : '';
        $buildingPrice = $this->amo->getCustomFieldValue($lead, Constants::CF_LEAD_BUILDING_PRICE_METER) ? : '';
        $rentBudget    = $this->amo->getCustomFieldValue($lead, Constants::CF_LEAD_RENT_BUDGET) ? : '';
        $rentBudget    = str_replace('.', ',', $rentBudget);

        $links                = [
            'from'          => 'leads',
            'from_id'       => $leadId,
            'to'            => 'catalog_elements',
            'to_catalog_id' => Constants::CATALOG_GOODS_ID,
        ];
        $catalogElementsLinks = $this->amo->getCatalogElementsLinksListBatch([$links])['links'] ?? [];
        $catalogQuantity      = [];
        foreach ($catalogElementsLinks as $catalogElementsLink) {
            $catalogQuantity[$catalogElementsLink['to_id']] = (int)$catalogElementsLink['quantity'] ?? 0;
        }

        $extraBadgesNum                 = $catalogQuantity[Constants::CATALOG_GOODS_ELEMENT_EXTRA_BADGES] ?? '';
        $performance60Num               = $catalogQuantity[Constants::CATALOG_GOODS_PERFORMANCE_IN_HALL_60] ?? '';
        $performance30Num               = $catalogQuantity[Constants::CATALOG_GOODS_PERFORMANCE_IN_HALL_30] ?? '';
        $performance15Num               = $catalogQuantity[Constants::CATALOG_GOODS_PERFORMANCE_IN_HALL_15] ?? '';
        $catalogBmsoFloorOnWheels       = $catalogQuantity[Constants::CATALOG_BMSO_FLOOR_ON_WHEELS] ?? '';
        $catalogBmsoIlluminatedNarrow   = $catalogQuantity[Constants::CATALOG_BMSO_ILLUMINATED_CABINET_NARROW] ?? '';
        $catalogBmsoIlluminatedCabinet  = $catalogQuantity[Constants::CATALOG_BMSO_ILLUMINATED_CABINET] ?? '';
        $catalogBmsoIlluminatedLock1    = $catalogQuantity[Constants::CATALOG_BMSO_ILLUMINATED_CABINET_LOCK_1] ?? '';
        $catalogBmsoIlluminatedLight1   = $catalogQuantity[Constants::CATALOG_BMSO_ILLUMINATED_CABINET_LIGHT_1] ?? '';
        $catalogBmsoIlluminatedLock05   = $catalogQuantity[Constants::CATALOG_BMSO_ILLUMINATED_CABINET_LOCK_0_5] ?? '';
        $catalogBmsoIlluminatedLight05  = $catalogQuantity[Constants::CATALOG_BMSO_ILLUMINATED_CABINET_LIGHT_0_5] ?? '';
        $catalogBmsoReceptionDesk       = $catalogQuantity[Constants::CATALOG_BMSO_RECEPTION_DESK] ?? '';
        $catalogBmsoReceptionDeskSmall  = $catalogQuantity[Constants::CATALOG_BMSO_RECEPTION_DESK_SMALL] ?? '';
        $catalogBmsoMetalHook           = $catalogQuantity[Constants::CATALOG_BMSO_METAL_HOOK] ?? '';
        $catalogBmsoPodium              = $catalogQuantity[Constants::CATALOG_BMSO_PODIUM] ?? '';
        $catalogBmsoMeshWithBaskets     = $catalogQuantity[Constants::CATALOG_BMSO_MESH_WITH_BASKETS] ?? '';
        $catalogBmsoAdvertisingStand    = $catalogQuantity[Constants::CATALOG_BMSO_ADVERTISING_STAND] ?? '';
        $catalogBmsoCoffeeTable         = $catalogQuantity[Constants::CATALOG_BMSO_COFFEE_TABLE] ?? '';
        $catalogBmsoRoundTableGlass     = $catalogQuantity[Constants::CATALOG_BMSO_ROUND_TABLE_GLASS] ?? '';
        $catalogBmsoRoundTablePlastic   = $catalogQuantity[Constants::CATALOG_BMSO_ROUND_TABLE_PLASTIC] ?? '';
        $catalogBmsoColumnFenceTape     = $catalogQuantity[Constants::CATALOG_BMSO_COLUMN_FENCE_TAPE] ?? '';
        $catalogBmsoColumnFenceEye      = $catalogQuantity[Constants::CATALOG_BMSO_COLUMN_FENCE_EYE] ?? '';
        $catalogBmsoBarChair            = $catalogQuantity[Constants::CATALOG_BMSO_BAR_CHAIR] ?? '';
        $catalogBmsoBarChairPlastic     = $catalogQuantity[Constants::CATALOG_BMSO_BAR_CHAIR_PLASTIC] ?? '';
        $catalogBmsoBarChairFoldingGrey = $catalogQuantity[Constants::CATALOG_BMSO_BAR_CHAIR_FOLDING_GREY] ?? '';
        $catalogBmsoBannerProduction    = $catalogQuantity[Constants::CATALOG_BMSO_BANNER_PRODUCTION] ?? '';
        $catalogBmsoPastingOrakal       = $catalogQuantity[Constants::CATALOG_BMSO_PASTING_ORAKAL] ?? '';
        $catalogBmsoPastingPrintOnGlue  = $catalogQuantity[Constants::CATALOG_BMSO_PASTING_PRINT_ON_GLUE] ?? '';
        $catalogBmsoAdditionalPanel     = $catalogQuantity[Constants::CATALOG_BMSO_ADDITIONAL_SIGNS_FASCIA_PANEL] ?? '';
        $catalogBmsoPlasmaPanel42       = $catalogQuantity[Constants::CATALOG_BMSO_PLASMA_PANEL_42] ?? '';
        $catalogBmsoStandPlasmaPanel    = $catalogQuantity[Constants::CATALOG_BMSO_STAND_PLASMA_PANEL] ?? '';
        $catalogBmsoDelegate45000       = $catalogQuantity[Constants::CATALOG_BMSO_DELEGATE_45000] ?? '';
        $catalogBmsoDelegate55000       = $catalogQuantity[Constants::CATALOG_BMSO_DELEGATE_55000] ?? '';
        $catalogBmsoOptionalBadge       = $catalogQuantity[Constants::CATALOG_BMSO_OPTIONAL_BADGE] ?? '';
        $catalogBmsoPackagePartner1     = $catalogQuantity[Constants::CATALOG_BMSO_PACKAGE_PARTNER_1] ?? '';
        $catalogBmsoPackagePartner2     = $catalogQuantity[Constants::CATALOG_BMSO_PACKAGE_PARTNER_2] ?? '';
        $catalogBmsoPackagePartner3     = $catalogQuantity[Constants::CATALOG_BMSO_PACKAGE_PARTNER_3] ?? '';

        if ($catalogElementsLinks) {
            $goods = CatalogHelper::getCatalogElementsWithLeads(
                $this->config['amo']['email'],
                $this->config['amo']['hash'],
                Constants::CATALOG_GOODS_ID,
                array_column($catalogElementsLinks, 'to_id')
            );

            foreach ($goods as $good) {
                $goodName = $good['name'] ?? null;
                $goodId   = (int)$good['id'] ?? null;
                if (mb_stripos($goodName, 'Электроснабжение стенда') !== false) {
                    preg_match('/Электроснабжение стенда до\s(?<kw>\d+)\sкВт/ui', $goodName, $matches);
                    $kwName  = (int)$matches['kw'] ?? 0;
                    $kwNum   = $catalogQuantity[$good['id']] ?? 0;
                    $kwPrice = $kwNum * $this->amo->getCustomFieldValue($good, Constants::CF_CATALOG_PRICE);
                }

                if (mb_stripos($goodName, 'Интернет до') !== false) {
                    preg_match('/Интернет до\s(?<ethernet>\d+)\sМбит\/сек/ui', $goodName, $matches);
                    $ethernetName  = (int)$matches['ethernet'] ?? 0;
                    $ethernetNum   = $catalogQuantity[$good['id']] ?? 0;
                    $ethernetPrice = $ethernetNum * $this->amo->getCustomFieldValue($good, Constants::CF_CATALOG_PRICE);
                }

                switch ($goodId) {
                    case Constants::CATALOG_GOODS_PERFORMANCE_IN_HALL_15:
                        $performance15Price = $this->amo->getCustomFieldValue($good, Constants::CF_CATALOG_PRICE);

                        break;
                    case Constants::CATALOG_GOODS_PERFORMANCE_IN_HALL_30:
                        $performance30Price = $this->amo->getCustomFieldValue($good, Constants::CF_CATALOG_PRICE);

                        break;
                    case Constants::CATALOG_GOODS_PERFORMANCE_IN_HALL_60:
                        $performance60Price = $this->amo->getCustomFieldValue($good, Constants::CF_CATALOG_PRICE);

                        break;
                    case Constants::CATALOG_BMSO_FLOOR_ON_WHEELS:
                        $catalogBmsoFloorOnWheelsPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );

                        break;
                    case Constants::CATALOG_BMSO_ILLUMINATED_CABINET_NARROW:
                        $catalogBmsoIlluminatedNarrowPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );

                        break;
                    case Constants::CATALOG_BMSO_ILLUMINATED_CABINET:
                        $catalogBmsoIlluminatedPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );

                        break;
                    case Constants::CATALOG_BMSO_ILLUMINATED_CABINET_LOCK_1:
                        $catalogBmsoIlluminatedLock1Price = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );

                        break;
                    case Constants::CATALOG_BMSO_ILLUMINATED_CABINET_LIGHT_1:
                        $catalogBmsoIlluminatedLight1Price = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );

                        break;
                    case Constants::CATALOG_BMSO_ILLUMINATED_CABINET_LOCK_0_5:
                        $catalogBmsoIlluminatedLock05Price = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );

                        break;
                    case Constants::CATALOG_BMSO_ILLUMINATED_CABINET_LIGHT_0_5:
                        $catalogBmsoIlluminatedLight05Price = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );

                        break;
                    case Constants::CATALOG_BMSO_RECEPTION_DESK:
                        $catalogBmsoReceptionDeskPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );

                        break;
                    case Constants::CATALOG_BMSO_RECEPTION_DESK_SMALL:
                        $catalogBmsoReceptionDeskSmallPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );

                        break;
                    case Constants::CATALOG_BMSO_METAL_HOOK:
                        $catalogBmsoMetalHookPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );

                        break;
                    case Constants::CATALOG_BMSO_PODIUM:
                        $catalogBmsoPodiumPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );

                        break;
                    case Constants::CATALOG_BMSO_MESH_WITH_BASKETS:
                        $catalogBmsoMeshWithBasketsPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );

                        break;
                    case Constants::CATALOG_BMSO_ADVERTISING_STAND:
                        $catalogBmsoAdvertisingStandPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );

                        break;
                    case Constants::CATALOG_BMSO_COFFEE_TABLE:
                        $catalogBmsoCoffeeTablePrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );

                        break;
                    case Constants::CATALOG_BMSO_ROUND_TABLE_GLASS:
                        $catalogBmsoRoundTableGlassPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );

                        break;
                    case Constants::CATALOG_BMSO_ROUND_TABLE_PLASTIC:
                        $catalogBmsoRoundTablePlasticPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );

                        break;
                    case Constants::CATALOG_BMSO_COLUMN_FENCE_TAPE:
                        $catalogBmsoColumnFenceTapePrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );

                        break;
                    case Constants::CATALOG_BMSO_COLUMN_FENCE_EYE:
                        $catalogBmsoColumnFenceEyePrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );

                        break;
                    case Constants::CATALOG_BMSO_BAR_CHAIR:
                        $catalogBmsoBarChairPrice = $this->amo->getCustomFieldValue($good, Constants::CF_CATALOG_PRICE);

                        break;
                    case Constants::CATALOG_BMSO_BAR_CHAIR_PLASTIC:
                        $catalogBmsoBarChairPlasticPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );

                        break;
                    case Constants::CATALOG_BMSO_BAR_CHAIR_FOLDING_GREY:
                        $catalogBmsoBarChairFoldingPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );

                        break;
                    case Constants::CATALOG_BMSO_BANNER_PRODUCTION:
                        $catalogBmsoBannerProductionPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );

                        break;
                    case Constants::CATALOG_BMSO_PASTING_ORAKAL:
                        $catalogBmsoPastingOrakalPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );

                        break;
                    case Constants::CATALOG_BMSO_PASTING_PRINT_ON_GLUE:
                        $catalogBmsoPastingPrintPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );

                        break;
                    case Constants::CATALOG_BMSO_ADDITIONAL_SIGNS_FASCIA_PANEL:
                        $catalogBmsoAdditionalPanelPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );

                        break;
                    case Constants::CATALOG_BMSO_PLASMA_PANEL_42:
                        $catalogBmsoPlasmaPanel42Price = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );

                        break;
                    case Constants::CATALOG_BMSO_STAND_PLASMA_PANEL:
                        $catalogBmsoStandPlasmaPanelPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );

                        break;
                    case Constants::CATALOG_BMSO_DELEGATE_45000:
                        $catalogBmsoDelegate45000Price = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );

                        break;
                    case Constants::CATALOG_BMSO_DELEGATE_55000:
                        $catalogBmsoDelegate55000Price = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );

                        break;
                    case Constants::CATALOG_BMSO_OPTIONAL_BADGE:
                        $catalogBmsoOptionalBadgePrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );

                        break;
                    case Constants::CATALOG_BMSO_PACKAGE_PARTNER_1:
                        $catalogBmsoPackagePartner1Price = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );

                        break;
                    case Constants::CATALOG_BMSO_PACKAGE_PARTNER_2:
                        $catalogBmsoPackagePartner2Price = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );

                        break;
                    case Constants::CATALOG_BMSO_PACKAGE_PARTNER_3:
                        $catalogBmsoPackagePartner3Price = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );

                        break;
                }
            }
        }

        $responsibleUserName = $this->returnResponsibleUserName($lead['responsible_user_id']);

        $performancePrice = (int)$performance60Num * ($performance60Price ?? 0)
            + (int)$performance30Num * ($performance30Price ?? 0)
            + (int)$performance15Num * ($performance15Price ?? 0);

        $service = new GoogleServiceSheets($this->googleTable);

        $range   = 'A1:DC';
        $table   = $service->spreadsheets_values->get(
            $this->config['google']['documents_signed_report'],
            $range,
            ['valueRenderOption' => 'FORMULA']
        );
        $rowData = $table->values;
        if (!$rowData) {
            $this->log->error('Данные в таблице не найдены.');

            return Constants::JOB_RESULT_FAIL_NO_REPEAT;
        }

        $notEmptyRow = $this->getInsertRow($rowData, $leadId);
        $insertData  = [
            (string)$leadId,                                    // A (Id Сделки)
            $rowData[$notEmptyRow][1] ?? '',                    // B
            $todayDate,                                         // C (Дата внесения Заявки)
            $standNum,                                          // D (№ стенда)
            $executor,                                          // E ((Исполнитель, получатель)
            $cluster,                                           // F (Кластер)
            $exhibitor,                                         // G (Экспонент)
            $rowData[$notEmptyRow][7] ?? '',                    // H
            $contractNum,                                       // I (№ договора)
            $regFee,                                            // J (РЕГ взнос, руб)
            $regFeeNum,                                         // K (Кол-во рег взносов)
            $totalFootage,                                      // L (м2)
            $rowData[$notEmptyRow][12] ?? '',                   // M
            $extraBadgesNum,                                    // N (Бейджи Доп, шт)
            $rowData[$notEmptyRow][14] ?? '',                   // O ()
            $rowData[$notEmptyRow][15] ?? '',                   // P ()
            $buildingType,                                      // Q (Тип стенда)
            $exposition,                                        // R (Тип обзорности)
            $discount,                                          // S (Скидка по обзорности)
            $actSum,                                            // T (Акт на сумму, руб (сверка с первичной док))
            $rowData[$notEmptyRow][20] ?? '',                   // U
            $leadPrice,                                         // V
            $buildingPrice,                                     // W (Цена застройки, за метр)
            $rowData[$notEmptyRow][23] ?? '',                   // X
            $rowData[$notEmptyRow][24] ?? '',                   // Y
            $companyType,                                       // Z (Тип аренды)
            $rentBudget,                                        // AA (Аренда)
            $kwName ?? '',                                      // AB (Электричество, кВт)
            $kwNum ?? '',                                       // AC (множитель)
            $kwPrice ?? '',                                     // AD (Электричество, руб)
            $ethernetName ?? '',                                // AE (Интернет Мбит/сек)
            $ethernetNum ?? '',                                 // AF (множитель)
            $ethernetPrice ?? '',                               // AG (Интернет, руб)
            $performance15Num,                                  // AH (Выступление 15 минут (кол-во))
            $performance30Num,                                  // AI (Выступление 30 минут (кол-во))
            $performance60Num,                                  // AJ (Выступление 60 минут (кол-во))
            $performancePrice ? : '',                           // AK (ВЫСТУПЛЕНИЯ, всего на сумму в руб)
            $responsibleUserName,                               // AL (Отв. менеджер)
            $catalogBmsoFloorOnWheels,                          // AM (УМСО) Вешало напольное на колесах
            $catalogBmsoFloorOnWheelsPrice ?? '',               // AN (УМСО) Вешало напольное на колесах
            $catalogBmsoIlluminatedNarrow,                      // AO (УМСО) Витрина-шкафс подсветкой (узкаяс)
            $catalogBmsoIlluminatedNarrowPrice ?? '',    // AP (УМСО) Витрина-шкафс подсветкой (узкаяс)
            $catalogBmsoIlluminatedCabinet,                     // AQ (УМСО) Витрина-шкаф с подсветкой (2017х1000х500мм)
            $catalogBmsoIlluminatedPrice ?? '',          // AR (УМСО) Витрина-шкаф с подсветкой (2017х1000х500мм)
            $catalogBmsoIlluminatedLock1,                       // AS (УМСО) Витрина-шкаф h 2,5-1*0,5 с замком
            $catalogBmsoIlluminatedLock1Price ?? '',     // AT (УМСО) Витрина-шкаф h 2,5-1*0,5 с замком
            $catalogBmsoIlluminatedLight1,                      // AU (УМСО) Витрина-шкаф h 2,5-1*0,5 с подсветкой
            $catalogBmsoIlluminatedLight1Price ?? '',    // AV (УМСО) Витрина-шкаф h 2,5-1*0,5 с подсветкой
            $catalogBmsoIlluminatedLock05,                      // AW (УМСО) Витрина-шкаф h 2,5-0,5*0,5 с замком
            $catalogBmsoIlluminatedLock05Price ?? '',   // AX (УМСО) Витрина-шкаф h 2,5-0,5*0,5 с замком
            $catalogBmsoIlluminatedLight05,                     // AY (УМСО) Витрина-шкаф h 2,5-0,5*0,5 с подстветкой
            $catalogBmsoIlluminatedLight05Price ?? '',  // AZ (УМСО) Витрина-шкаф h 2,5-0,5*0,5 с подстветкой
            $catalogBmsoReceptionDesk,                          // BA (УМСО) Стойка ресепшн,(серая, h1060 мм,1620 внеш)
            $catalogBmsoReceptionDeskPrice ?? '',               // BB (УМСО) Стойка ресепшн,(серая, h1060 мм,1620 внеш)
            $catalogBmsoReceptionDeskSmall,                     // BC (УМСО) Стойка ресепшн,(серая, h1050 мм,1150 внеш)
            $catalogBmsoReceptionDeskSmallPrice ?? '',          // BD (УМСО) Стойка ресепшн,(серая, h1050 мм,1150 внеш)
            $catalogBmsoMetalHook,                              // BE (УМСО) Крючок металлический для подвеса
            $catalogBmsoMetalHookPrice ?? '',                   // BF (УМСО) Крючок металлический для подвеса
            $catalogBmsoPodium,                                 // BG (УМСО) Подиум 1х1х0,5 м
            $catalogBmsoPodiumPrice ?? '',                      // BH (УМСО) Подиум 1х1х0,5 м
            $catalogBmsoMeshWithBaskets,                        // BI (УМСО) Сетка с навесными корзинами
            $catalogBmsoMeshWithBasketsPrice ?? '',             // BJ (УМСО) Сетка с навесными корзинами
            $catalogBmsoAdvertisingStand,                       // BK (УМСО) Стойка для рекламы
            $catalogBmsoAdvertisingStandPrice ?? '',            // BL (УМСО) Стойка для рекламы
            $catalogBmsoCoffeeTable,                            // BM (УМСО) Стол журнальный (1200х700х450мм)
            $catalogBmsoCoffeeTablePrice ?? '',                 // BN (УМСО) Стол журнальный (1200х700х450мм)
            $catalogBmsoRoundTableGlass,                        // BO (УМСО) Стол круглый со стеклянной столешницей
            $catalogBmsoRoundTableGlassPrice ?? '',             // BP (УМСО) Стол круглый со стеклянной столешницей
            $catalogBmsoRoundTablePlastic,                      // BQ (УМСО) Стол пластиковый белый (800х800 мм)
            $catalogBmsoRoundTablePlasticPrice ?? '',           // BR (УМСО) Стол пластиковый белый (800х800 мм)
            $catalogBmsoColumnFenceTape,                        // BS (УМСО) Столбик ограждения (стоп-стойка с лентой)
            $catalogBmsoColumnFenceTapePrice ?? '',             // BT (УМСО) Столбик ограждения (стоп-стойка с лентой)
            $catalogBmsoColumnFenceEye,                         // BU (УМСО) Столбик ограждения (с проушиной)
            $catalogBmsoColumnFenceEyePrice ?? '',              // BV (УМСО) Столбик ограждения (с проушиной)
            $catalogBmsoBarChair,                               // BW (УМСО) Стул барный
            $catalogBmsoBarChairPrice ?? '',                    // BX (УМСО) Стул барный
            $catalogBmsoBarChairPlastic,                        // BY (УМСО) Стул пластиковый белый
            $catalogBmsoBarChairPlasticPrice ?? '',             // BZ (УМСО) Стул пластиковый белый
            $catalogBmsoBarChairFoldingGrey,                    // СA (УМСО) Стул раскладной (серый)
            $catalogBmsoBarChairFoldingPrice ?? '',         // СB (УМСО) Стул раскладной (серый)
            $catalogBmsoBannerProduction,                       // СC (УМСО) Изготовление баннера 1000*2400 мм
            $catalogBmsoBannerProductionPrice ?? '',            // СD (УМСО) Изготовление баннера 1000*2400 мм
            $catalogBmsoPastingOrakal,                          // СE (УМСО) Оклейка пленкой "Оракал" 1м2
            $catalogBmsoPastingOrakalPrice ?? '',               // СF (УМСО) Оклейка пленкой "Оракал" 1м2
            $catalogBmsoPastingPrintOnGlue,                     // СG (УМСО) Оклейка пленкой "Print on Glue"
            $catalogBmsoPastingPrintPrice ?? '',          // СH (УМСО) Оклейка пленкой "Print on Glue"
            $catalogBmsoAdditionalPanel,                        // СI (УМСО) Дополнительные знаки на фризовую панель
            $catalogBmsoAdditionalPanelPrice ?? '',  // СJ (УМСО) Дополнительные знаки на фризовую панель
            $catalogBmsoPlasmaPanel42,                          // СK (УМСО) Плазменная панель42 дюйма(1 день)
            $catalogBmsoPlasmaPanel42Price ?? '',               // СL (УМСО) Плазменная панель42 дюйма(1 день)
            $catalogBmsoStandPlasmaPanel,                       // СM (УМСО) Стойка для плазменной панели (1 день)
            $catalogBmsoStandPlasmaPanelPrice ?? '',            // СN (УМСО) Стойка для плазменной панели (1 день)
            $catalogBmsoDelegate45000,                          // СO (УМСО) Делегат 45000
            $catalogBmsoDelegate45000Price ?? '',               // СP (УМСО) Делегат 45000
            $catalogBmsoDelegate55000,                          // СQ (УМСО) Делегат 55000
            $catalogBmsoDelegate55000Price ?? '',               // СR (УМСО) Делегат 55000
            $catalogBmsoOptionalBadge,                          // СS (УМСО) Дополнительный бейдж
            $catalogBmsoOptionalBadgePrice ?? '',               // СT (УМСО) Дополнительный бейдж
            $catalogBmsoPackagePartner1,                        // СU (УМСО) Пакет Партнер №1
            $catalogBmsoPackagePartner1Price ?? '',             // CV (УМСО) Пакет Партнер №1
            $catalogBmsoPackagePartner2,                        // СW (УМСО) Пакет Партнер №2
            $catalogBmsoPackagePartner2Price ?? '',             // CX (УМСО) Пакет Партнер №2
            $catalogBmsoPackagePartner3,                        // СY (УМСО) Пакет Партнер №3
            $catalogBmsoPackagePartner3Price ?? '',             // CZ (УМСО) Пакет Партнер №3
        ];
        $insertRow   = $notEmptyRow + 1;

        $this->log->notice('Подготовили данные для записи: ' . print_r($insertData, true));

        try {
            $result = $service->spreadsheets_values->update(
                $this->config['google']['documents_signed_report'],
                "A$insertRow:DC",
                new \Google_Service_Sheets_ValueRange(['values' => [$insertData]]),
                ['valueInputOption' => 'USER_ENTERED', 'responseValueRenderOption' => 'FORMULA']
            );
            $this->log->notice('Результат обновления таблицы: ' . print_r($result, true));
        } catch (\Exception $e) {
            $this->log->error('Ошибка при изменение данных в таблице: ' . $e->getMessage());

            return Constants::JOB_RESULT_FAIL_NO_REPEAT;
        }

        return Constants::JOB_RESULT_SUCCESS;
    }

    /**
     * Удаляет строку с данными по Сделке из amoCRM в Google Table.
     *
     * @param int $leadId Id Сделки amoCRM
     *
     * @throws \Exception
     *
     * @return int
     */
    private function deleteLeadFromReport(int $leadId)
    {
        $this->log->notice("Начали обрабатывать Сделку $leadId");

        $spreadSheetId = $this->config['google']['documents_signed_report'];
        $service       = new GoogleServiceSheets($this->googleTable);
        $response      = $service->spreadsheets->get($spreadSheetId);
        $sheetId       = $response->getSheets()[0]->getProperties()->getSheetId();
        $range         = 'A1:DC';
        $table         = $service->spreadsheets_values->get(
            $spreadSheetId,
            $range,
            ['valueRenderOption' => 'FORMULA']
        );
        $rowData       = $table->values;
        if (!$rowData) {
            $this->log->error('Данные в таблице не найдены.');

            return Constants::JOB_RESULT_FAIL_NO_REPEAT;
        }

        $rowWithLead = $this->getRowWithLead($rowData, $leadId);
        if ($rowWithLead === null) {
            $this->log->notice("Сделки с ID $leadId нет в таблице. Выходим.");

            return Constants::JOB_RESULT_SUCCESS;
        }

        $this->log->notice("Будем удалять сделку с ID $leadId из строки $rowWithLead");

        $requests[] = [
            'deleteDimension' => [
                'range' => [
                    "sheetId"    => $sheetId,
                    "dimension"  => "ROWS",
                    "startIndex" => $rowWithLead,
                    "endIndex"   => $rowWithLead + 1,
                ],
            ],
        ];

        $googleBathUpdate = new GoogleServiceSheetsBatchUpdateSpreadsheetRequest();
        $googleBathUpdate->setRequests([$requests]);
        try {
            $service->spreadsheets->batchUpdate(
                $spreadSheetId,
                $googleBathUpdate
            );
        } catch (\Exception $e) {
            $this->log->error('Ошибка изменения листов: ' . $e->getMessage());

            return Constants::JOB_RESULT_FAIL_WITH_REPEAT;
        }

        return Constants::JOB_RESULT_SUCCESS;
    }

    /**
     * @param array $rowData
     * @param int   $leadId
     *
     * @return int
     */
    private function getInsertRow(array $rowData, int $leadId)
    {
        $firstEmptyRow = null;
        foreach ($rowData as $row => $cells) {
            if (in_array($row, [0, 1])) {
                continue;
            }

            if ((isset($cells[0]) && (int)$cells[0] === $leadId)) {
                return (int)$row;
            }

            if ($firstEmptyRow === null && (!isset($cells[0]) || (isset($cells[0]) && $cells[0] === ''))) {
                $firstEmptyRow = (int)$row;
            }
        }

        return $firstEmptyRow ? : count($rowData) - 1;
    }

    /**
     * @param array $rowData
     * @param int   $leadId
     *
     * @return int|null
     */
    private function getRowWithLead(array $rowData, int $leadId)
    {
        foreach ($rowData as $row => $cells) {
            if ((isset($cells[0]) && (int)$cells[0] === $leadId)) {
                return (int)$row;
            }
        }

        return null;
    }

    /**
     * Метод для повторения джобы в зависимости от результата её обработки.
     *
     * @param Job $job
     * @param int $result
     *
     * @return void
     */
    private function processJobByResult(Job $job, $result)
    {
        switch ($result) {
            case Constants::JOB_RESULT_SUCCESS:
                $this->log->notice('Джоба успешно обработана. Она удаляется.');

                $job->delete();

                break;
            case Constants::JOB_RESULT_FAIL_NO_REPEAT:
                $this->log->notice('Джоба неуспешно обработана, по причине, которая не пропадёт. Она удаляется.');

                $job->delete();

                break;
            case Constants::JOB_RESULT_FAIL_WITH_REPEAT:
                $stats = $job->stats();

                // В зависимости от количества повторов задачи помещаем в очередь или нет
                if ($stats['releases'] < Constants::QUEUE_REPS_MAX) {
                    $this->log->notice(
                        'Джоба неуспешно обработана. Уходит на повтор: ' . $stats['releases'] . '/'
                        . Constants::QUEUE_REPS_MAX
                    );

                    $job->release(100, 10 * 60);
                } else {
                    $this->log->notice('Джоба неуспешно обработана. Лимит повторения исчерпан. Она удаляется.');

                    $job->delete();
                }

                break;
        }
    }

    /**
     * Ищет имя ответственного пользователя по id в массиве всех пользователей клиента.
     *
     * @param int $responsibleUserId Id ответственного пользователя.
     *
     * @throws AmoException
     *
     * @return string
     */
    private function returnResponsibleUserName($responsibleUserId)
    {
        $accounts = $this->amo->getAccounts();
        $users    = $accounts['account']['users'];

        foreach ($users as $user) {
            if ($user['id'] == $responsibleUserId) {
                return (string)$user['name'];
            }
        }

        return '';
    }
}
