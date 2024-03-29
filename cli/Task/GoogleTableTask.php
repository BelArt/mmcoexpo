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
    public const SHEET_REQUEST_TEMPLATE_TITLE          = 'Заявка ММСО';
    public const SHEET_REQUEST_TEMPLATE_TITLE_4_METERS = 'Заявка ММСО 4 кв.м.';

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
            $rowData[14][2] = $dataToInsert['building_price_meter']; // ЗАСТРОЙКА ставка за 1 кв. м.
            $rowData[14][3] = $dataToInsert['total_footage']; // ЗАСТРОЙКА кол-во кв. м
            $rowData[14][4] = $dataToInsert['building_price_meter'] * $dataToInsert['total_footage']; // ЗАСТРОЙКА итого
            $rowData[18][2] = $dataToInsert['registration_fee']; // РЕГИСТРАЦИОННЫЙ ВЗНОС
            $rowData[18][3] = 1; // РЕГИСТРАЦИОННЫЙ ВЗНОС кол-во
            $rowData[18][4] = $dataToInsert['registration_fee']; // РЕГИСТРАЦИОННЫЙ ВЗНОС итого
            $rowData[19][3] = floor($dataToInsert['total_footage'] / 2); // КОЛИЧЕСТВО БЕЙДЖЕЙ

            $this->log->notice(
                'Рассчитали количетсво бейджей: '
                . $dataToInsert['total_footage'] . '/2 = ' . floor($dataToInsert['total_footage'] / 2)
            );

            $rowByLocation = [
                'Линейная'   => 22,
                'Угловая'    => 23,
                'Полуостров' => 24,
                'Остров'     => 25,
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

        $todayDate     = (new \DateTime('now', new \DateTimeZone(Constants::TIMEZONE)))->format('d.m.Y');
        $standNum      = $this->amo->getCustomFieldValue($lead, Constants::CF_LEAD_STAND_NUMBER) ? : '';
        $executor      = $this->amo->getCustomFieldValue($lead, Constants::CF_LEAD_OUR_YUR_FACE) ? : '';
        $cluster       = $this->amo->getCustomFieldValue($lead, Constants::CF_LEAD_CLUSTER) ? : '';
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
            $catalogQuantity[$catalogElementsLink['to_id']] = (int)($catalogElementsLink['quantity'] ?? 0);
        }

        $extraBadgesNum                    = $catalogQuantity[Constants::CATALOG_GOODS_ELEMENT_EXTRA_BADGES] ?? '';
        $performance60Num                  = $catalogQuantity[Constants::CATALOG_MMSO_PERFORMANCE_60] ?? '';
        $performance30Num                  = $catalogQuantity[Constants::CATALOG_MMSO_PERFORMANCE_30] ?? '';
        $performance15Num                  = $catalogQuantity[Constants::CATALOG_MMSO_PERFORMANCE_15] ?? '';
        $catalogMmsoCleaningFurniture      = $catalogQuantity[Constants::CATALOG_MMSO_CLEANING_FURNITURE] ?? '';
        $catalogMmsoCleaningFloor          = $catalogQuantity[Constants::CATALOG_MMSO_CLEANING_FLOOR] ??
            $catalogQuantity[Constants::CATALOG_MMSO_CLEANING_FLOOR_OLD_PRICE] ?? '';
        $catalogMmsoCleaningCarpet         = $catalogQuantity[Constants::CATALOG_MMSO_CLEANING_CARPET] ??
            $catalogQuantity[Constants::CATALOG_MMSO_CLEANING_CARPET_OLD_PRICE] ?? '';
        $catalogMmsoElectro32              = $catalogQuantity[Constants::CATALOG_MMSO_ELECTRO_32] ??
            $catalogQuantity[Constants::CATALOG_MMSO_ELECTRO_32_OLD_PRICE] ?? '';
        $catalogMmsoSocket220              = $catalogQuantity[Constants::CATALOG_MMSO_SOCKET_220] ??
            $catalogQuantity[Constants::CATALOG_MMSO_SOCKET_220_OLD_PRICE] ?? '';
        $catalogMmsoSocketAlone            = $catalogQuantity[Constants::CATALOG_MMSO_SOCKET_ALONE] ??
            $catalogQuantity[Constants::CATALOG_MMSO_SOCKET_ALONE_OLD_PRICE] ?? '';
        $catalogMmsoSwitchBoard50          = $catalogQuantity[Constants::CATALOG_MMSO_SWITCHBOARD_50] ??
            $catalogQuantity[Constants::CATALOG_MMSO_SWITCHBOARD_50_OLD_PRICE] ?? '';
        $catalogMmsoProjector150W          = $catalogQuantity[Constants::CATALOG_MMSO_PROJECTOR_150W] ??
            $catalogQuantity[Constants::CATALOG_MMSO_PROJECTOR_150W_OLD_PRICE] ?? '';
        $catalogMmsoProjector300W          = $catalogQuantity[Constants::CATALOG_MMSO_PROJECTOR_300W] ??
            $catalogQuantity[Constants::CATALOG_MMSO_PROJECTOR_300W_OLD_PRICE] ?? '';
        $catalogMmsoSpotBra100W            = $catalogQuantity[Constants::CATALOG_MMSO_SPOT_BRA_100W] ??
            $catalogQuantity[Constants::CATALOG_MMSO_SPOT_BRA_100W_OLD_PRICE] ?? '';
        $catalogMmsoLamp40W                = $catalogQuantity[Constants::CATALOG_MMSO_LAMP_40W] ??
            $catalogQuantity[Constants::CATALOG_MMSO_LAMP_40W_OLD_PRICE] ?? '';
        $catalogMmsoWallElement05          = $catalogQuantity[Constants::CATALOG_MMSO_WALL_ELEMENT_05] ??
            $catalogQuantity[Constants::CATALOG_MMSO_WALL_ELEMENT_05_OLD_PRICE] ?? '';
        $catalogMmsoWallElement075         = $catalogQuantity[Constants::CATALOG_MMSO_WALL_ELEMENT_075] ??
            $catalogQuantity[Constants::CATALOG_MMSO_WALL_ELEMENT_075_OLD_PRICE] ?? '';
        $catalogMmsoWallElement1           = $catalogQuantity[Constants::CATALOG_MMSO_WALL_ELEMENT_1] ??
            $catalogQuantity[Constants::CATALOG_MMSO_WALL_ELEMENT_1_OLD_PRICE] ?? '';
        $catalogMmsoWallElement15          = $catalogQuantity[Constants::CATALOG_MMSO_WALL_ELEMENT_15] ??
            $catalogQuantity[Constants::CATALOG_MMSO_WALL_ELEMENT_15_OLD_PRICE] ?? '';
        $catalogMmsoWallElementWithGlass05 = $catalogQuantity[Constants::CATALOG_MMSO_WALL_ELEMENT_WITH_GLASS_05] ??
            $catalogQuantity[Constants::CATALOG_MMSO_WALL_ELEMENT_WITH_GLASS_05_OLD_PRICE] ?? '';
        $catalogMmsoWallElementWithGlass1  = $catalogQuantity[Constants::CATALOG_MMSO_WALL_ELEMENT_WITH_GLASS_1] ??
            $catalogQuantity[Constants::CATALOG_MMSO_WALL_ELEMENT_WITH_GLASS_1_OLD_PRICE] ?? '';
        $catalogMmsoWallElementWithCurtain = $catalogQuantity[Constants::CATALOG_MMSO_WALL_ELEMENT_WITH_CURTAIN] ??
            $catalogQuantity[Constants::CATALOG_MMSO_WALL_ELEMENT_WITH_CURTAIN_OLD_PRICE] ?? '';
        $catalogMmsoDoorBlockWithDoor      = $catalogQuantity[Constants::CATALOG_MMSO_DOOR_BLOCK_WITH_STANDART_DOOR] ??
            $catalogQuantity[Constants::CATALOG_MMSO_DOOR_BLOCK_WITH_STANDART_DOOR_OLD_PRICE] ?? '';
        $catalogMmsoDoorSlidingDoor        = $catalogQuantity[Constants::CATALOG_MMSO_DOOR_BLOCK_WITH_SLIDING_DOOR] ??
            $catalogQuantity[Constants::CATALOG_MMSO_DOOR_BLOCK_WITH_SLIDING_DOOR_OLD_PRICE] ?? '';

        $catalogMmsoStand               = $catalogQuantity[Constants::CATALOG_MMSO_STAND] ??
            $catalogQuantity[Constants::CATALOG_MMSO_STAND_OLD_PRICE] ?? '';
        $catalogMmsoBoardLdsp03         = $catalogQuantity[Constants::CATALOG_MMSO_BOARD_LDSP_03] ??
            $catalogQuantity[Constants::CATALOG_MMSO_BOARD_LDSP_03_OLD_PRICE] ?? '';
        $catalogMmsoShelfLdsp1          = $catalogQuantity[Constants::CATALOG_MMSO_SHELF_LDSP_1] ??
            $catalogQuantity[Constants::CATALOG_MMSO_SHELF_LDSP_1_OLD_PRICE] ?? '';
        $catalogMmsoFloorLift           = $catalogQuantity[Constants::CATALOG_MMSO_FLOOR_LIFT] ??
            $catalogQuantity[Constants::CATALOG_MMSO_FLOOR_LIFT_OLD_PRICE] ?? '';
        $catalogMmsoAdditionalCarpeting = $catalogQuantity[Constants::CATALOG_MMSO_ADDITIONAL_CARPETING] ??
            $catalogQuantity[Constants::CATALOG_MMSO_ADDITIONAL_CARPETING_OLD_PRICE] ?? '';
        $catalogMmsoInformationDeskR1   = $catalogQuantity[Constants::CATALOG_MMSO_INFORMATION_DESK_R1] ??
            $catalogQuantity[Constants::CATALOG_MMSO_INFORMATION_DESK_R1_OLD_PRICE] ?? '';
        $catalogMmsoInformStandShelf    = $catalogQuantity[Constants::CATALOG_MMSO_INFORMATION_STAND_WITH_SHELF] ??
            $catalogQuantity[Constants::CATALOG_MMSO_INFORMATION_STAND_WITH_SHELF_OLD_PRICE] ?? '';
        $catalogMmsoPodiumTable1M       = $catalogQuantity[Constants::CATALOG_MMSO_PODIUM_TABLE_1M] ??
            $catalogQuantity[Constants::CATALOG_MMSO_PODIUM_TABLE_1M_OLD_PRICE] ?? '';
        $catalogMmsoPodiumTable05M      = $catalogQuantity[Constants::CATALOG_MMSO_PODIUM_TABLE_05M] ??
            $catalogQuantity[Constants::CATALOG_MMSO_PODIUM_TABLE_05M_OLD_PRICE] ?? '';
        $catalogMmsoDoorsPodiumTable    = $catalogQuantity[Constants::CATALOG_MMSO_DOORS_PODIUM_TABLE] ??
            $catalogQuantity[Constants::CATALOG_MMSO_DOORS_PODIUM_TABLE_OLD_PRICE] ?? '';
        $catalogMmsoLowShowcase         = $catalogQuantity[Constants::CATALOG_MMSO_LOW_SHOWCASE] ??
            $catalogQuantity[Constants::CATALOG_MMSO_LOW_SHOWCASE_OLD_PRICE] ?? '';
        $catalogMmsoHighShowcase        = $catalogQuantity[Constants::CATALOG_MMSO_HIGH_SHOWCASE] ??
            $catalogQuantity[Constants::CATALOG_MMSO_HIGH_SHOWCASE_OLD_PRICE] ?? '';
        $catalogMmsoSoftChair           = $catalogQuantity[Constants::CATALOG_MMSO_SOFT_CHAIR] ??
            $catalogQuantity[Constants::CATALOG_MMSO_SOFT_CHAIR_OLD_PRICE] ?? '';
        $catalogMmsoBarChair            = $catalogQuantity[Constants::CATALOG_MMSO_BAR_CHAIR] ?? '';
        $catalogMmsoRoundTable          = $catalogQuantity[Constants::CATALOG_MMSO_ROUND_TABLE] ??
            $catalogQuantity[Constants::CATALOG_MMSO_ROUND_TABLE_OLD_PRICE] ?? '';
        $catalogMmsoSquareTable67CM     = $catalogQuantity[Constants::CATALOG_MMSO_SQUARE_TABLE_67CM] ??
            $catalogQuantity[Constants::CATALOG_MMSO_SQUARE_TABLE_67CM_OLD_PRICE] ?? '';

        $catalogMmsoRectangularTable100CM = $catalogQuantity[Constants::CATALOG_MMSO_RECTANGULAR_TABLE_100CM] ??
            $catalogQuantity[Constants::CATALOG_MMSO_RECTANGULAR_TABLE_100CM_OLD_PRICE] ?? '';
        $catalogMmsoRoundGlassTable       = $catalogQuantity[Constants::CATALOG_MMSO_ROUND_GLASS_TABLE] ??
            $catalogQuantity[Constants::CATALOG_MMSO_ROUND_GLASS_TABLE_OLD_PRICE] ?? '';
        $catalogMmsoBarTableLdsp          = $catalogQuantity[Constants::CATALOG_MMSO_BAR_TABLE_LDSP] ??
            $catalogQuantity[Constants::CATALOG_MMSO_BAR_TABLE_LDSP_OLD_PRICE] ?? '';
        $catalogMmsoMetalStand3Shelf      = $catalogQuantity[Constants::CATALOG_MMSO_METAL_STAND_3_SHELF] ??
            $catalogQuantity[Constants::CATALOG_MMSO_METAL_STAND_3_SHELF_OLD_PRICE] ?? '';
        $catalogMmsoPlasticStand5Shelf    = $catalogQuantity[Constants::CATALOG_MMSO_PLASTIC_STAND_5_SHELF] ??
            $catalogQuantity[Constants::CATALOG_MMSO_PLASTIC_STAND_5_SHELF_OLD_PRICE] ?? '';
        $catalogMmsoCoffeeMachine         = $catalogQuantity[Constants::CATALOG_MMSO_COFFEE_MACHINE] ??
            $catalogQuantity[Constants::CATALOG_MMSO_COFFEE_MACHINE_OLD_PRICE] ?? '';
        $catalogMmsoLeatherSofaWhite      = $catalogQuantity[Constants::CATALOG_MMSO_LEATHER_SOFA_WHITE] ??
            $catalogQuantity[Constants::CATALOG_MMSO_LEATHER_SOFA_WHITE_OLD_PRICE] ?? '';
        $catalogMmsoLeatherChairWhite     = $catalogQuantity[Constants::CATALOG_MMSO_LEATHER_CHAIR_WHITE] ??
            $catalogQuantity[Constants::CATALOG_MMSO_LEATHER_CHAIR_WHITE_OLD_PRICE] ?? '';
        $catalogMmsoArchiveCupboard       = $catalogQuantity[Constants::CATALOG_MMSO_ARCHIVE_CUPBOARD] ??
            $catalogQuantity[Constants::CATALOG_MMSO_ARCHIVE_CUPBOARD_OLD_PRICE] ?? '';
        $catalogMmsoListHolderStandard    = $catalogQuantity[Constants::CATALOG_MMSO_LIST_HOLDER_STANDARD] ??
            $catalogQuantity[Constants::CATALOG_MMSO_LIST_HOLDER_STANDARD_OLD_PRICE] ?? '';
        $catalogMmsoListHolderRotating    = $catalogQuantity[Constants::CATALOG_MMSO_LIST_HOLDER_ROTATING] ??
            $catalogQuantity[Constants::CATALOG_MMSO_LIST_HOLDER_ROTATING_OLD_PRICE] ?? '';
        $catalogMmsoWallHanger            = $catalogQuantity[Constants::CATALOG_MMSO_WALL_HANGER] ??
            $catalogQuantity[Constants::CATALOG_MMSO_WALL_HANGER_OLD_PRICE] ?? '';
        $catalogMmsoFloorHanger           = $catalogQuantity[Constants::CATALOG_MMSO_FLOOR_HANGER] ??
            $catalogQuantity[Constants::CATALOG_MMSO_FLOOR_HANGER_OLD_PRICE] ?? '';
        $catalogMmsoPaperBasket           = $catalogQuantity[Constants::CATALOG_MMSO_PAPER_BASKET] ??
            $catalogQuantity[Constants::CATALOG_MMSO_PAPER_BASKET_OLD_PRICE] ?? '';
        $catalogMmsoWaterCooler19L        = $catalogQuantity[Constants::CATALOG_MMSO_WATER_COOLER_19L] ??
            $catalogQuantity[Constants::CATALOG_MMSO_WATER_COOLER_19L_OLD_PRICE] ?? '';
        $catalogMmsoExtraBottleWater      = $catalogQuantity[Constants::CATALOG_MMSO_EXTRA_BOTTLE_WATER] ??
            $catalogQuantity[Constants::CATALOG_MMSO_EXTRA_BOTTLE_WATER_OLD_PRICE] ?? '';
        $catalogMmsoTv42                  = $catalogQuantity[Constants::CATALOG_MMSO_TV_42] ??
            $catalogQuantity[Constants::CATALOG_MMSO_TV_42_OLD_PRICE] ?? '';
        $catalogMmsoTv50                  = $catalogQuantity[Constants::CATALOG_MMSO_TV_50] ??
            $catalogQuantity[Constants::CATALOG_MMSO_TV_50_OLD_PRICE] ?? '';
        $catalogMmsoTv60                  = $catalogQuantity[Constants::CATALOG_MMSO_TV_60] ??
            $catalogQuantity[Constants::CATALOG_MMSO_TV_60_OLD_PRICE] ?? '';
        $catalogMmsoFloorStandTv          = $catalogQuantity[Constants::CATALOG_MMSO_FLOOR_STAND_TV] ??
            $catalogQuantity[Constants::CATALOG_MMSO_FLOOR_STAND_TV_OLD_PRICE] ?? '';
        $catalogMmsoGlassCoffeeTable      = $catalogQuantity[Constants::CATALOG_MMSO_GLASS_COFFEE_TABLE] ??
            $catalogQuantity[Constants::CATALOG_MMSO_GLASS_COFFEE_TABLE_OLD_PRICE] ?? '';
        $catalogMmsoColoredPastingStamp   = $catalogQuantity[Constants::CATALOG_MMSO_COLORED_PASTING_STAMP] ??
            $catalogQuantity[Constants::CATALOG_MMSO_COLORED_PASTING_STAMP_OLD_PRICE] ?? '';

        $catalogMmsoColoredPastingStampInformationDesk  = $catalogQuantity[Constants::CATALOG_MMSO_COLORED_PASTING_STAMP_INFORMATION_DESK]
            ?? $catalogQuantity[Constants::CATALOG_MMSO_COLORED_PASTING_STAMP_INFORMATION_DESK_OLD_PRICE] ?? '';
        $catalogMmsoColoredPastingOracalWallPanel       = $catalogQuantity[Constants::CATALOG_MMSO_COLORED_PASTING_ORACAL_WALL_PANEL]
            ?? $catalogQuantity[Constants::CATALOG_MMSO_COLORED_PASTING_ORACAL_WALL_PANEL_OLD_PRICE] ?? '';
        $catalogMmsoColoredPastingOracalInformationDesk = $catalogQuantity[Constants::CATALOG_MMSO_COLORED_PASTING_ORACAL_INFORMATION_DESK]
            ?? $catalogQuantity[Constants::CATALOG_MMSO_COLORED_PASTING_ORACAL_INFORMATION_DESK_OLD_PRICE] ?? '';

        $catalogMmsoPastingOwnMaterial   = $catalogQuantity[Constants::CATALOG_MMSO_PASTING_OWN_MATERIAL] ??
            $catalogQuantity[Constants::CATALOG_MMSO_PASTING_OWN_MATERIAL_OLD_PRICE] ?? '';
        $catalogMmsoCleaningPanelFromMat = $catalogQuantity[Constants::CATALOG_MMSO_CLEANING_PANEL_FROM_MATERIAL] ??
            $catalogQuantity[Constants::CATALOG_MMSO_CLEANING_PANEL_FROM_MATERIAL_OLD_PRICE] ?? '';
        $catalogMmsoInscriptionFrieze1   = $catalogQuantity[Constants::CATALOG_MMSO_INSCRIPTION_FRIEZE_1_LETTER] ??
            $catalogQuantity[Constants::CATALOG_MMSO_INSCRIPTION_FRIEZE_1_LETTER_OLD_PRICE] ?? '';
        $catalogMmsoBannerTill3M         = $catalogQuantity[Constants::CATALOG_MMSO_BANNER_TILL_3M] ??
            $catalogQuantity[Constants::CATALOG_MMSO_BANNER_TILL_3M_OLD_PRICE] ?? '';
        $catalogMmsoBannerFrom3M         = $catalogQuantity[Constants::CATALOG_MMSO_BANNER_FROM_3M] ??
            $catalogQuantity[Constants::CATALOG_MMSO_BANNER_FROM_3M_OLD_PRICE] ?? '';
        $catalogMmsoBannerInstallation   = $catalogQuantity[Constants::CATALOG_MMSO_BANNER_INSTALLATION] ??
            $catalogQuantity[Constants::CATALOG_MMSO_BANNER_INSTALLATION_OLD_PRICE] ?? '';

        $catalogMmsoSingleColorLogoInformationDesk = $catalogQuantity[Constants::CATALOG_MMSO_SINGLE_COLOR_LOGO_INFORMATION_DESK]
            ?? $catalogQuantity[Constants::CATALOG_MMSO_SINGLE_COLOR_LOGO_INFORMATION_DESK_OLD_PRICE] ?? '';
        $catalogMmsoSingleColorLogoWallPanel       = $catalogQuantity[Constants::CATALOG_MMSO_SINGLE_COLOR_LOGO_WALL_PANEL]
            ?? $catalogQuantity[Constants::CATALOG_MMSO_SINGLE_COLOR_LOGO_WALL_PANEL_OLD_PRICE] ?? '';
        $catalogMmsoMultiColorLogoInformationDesk  = $catalogQuantity[Constants::CATALOG_MMSO_MULTICOLOR_COLOR_LOGO_INFORMATION_DESK]
            ?? $catalogQuantity[Constants::CATALOG_MMSO_MULTICOLOR_COLOR_LOGO_INFORMATION_DESK_OLD_PRICE] ?? '';
        $catalogMmsoMultiColorLogoWallPanel        = $catalogQuantity[Constants::CATALOG_MMSO_MULTICOLOR_LOGO_WALL_PANEL]
            ?? $catalogQuantity[Constants::CATALOG_MMSO_MULTICOLOR_LOGO_WALL_PANEL_OLD_PRICE] ?? '';
        $catalogMmsoOneTimeWindowCleaning          = $catalogQuantity[Constants::CATALOG_MMSO_ONE_TIME_WINDOW_CLEANING]
            ?? '';

        $catalogMmsoProducingEvents                  = $catalogQuantity[Constants::CATALOG_MMSO_PRODUCING_EVENTS] ??
            $catalogQuantity[Constants::CATALOG_MMSO_PRODUCING_EVENTS_OLD_PRICE] ?? '';
        $catalogMmsoOnlineWelcomePack                = $catalogQuantity[Constants::CATALOG_MMSO_ONLINE_WELCOME_PACK] ??
            '';
        $catalogMmsoSpecialProjectSchoolKindergarten = $catalogQuantity[Constants::CATALOG_MMSO_SPECIAL_PROJECT_SCHOOL_KINDERGARTEN]
            ?? '';
        $catalogMmsoAdvertisingHalfCatalog           = $catalogQuantity[Constants::CATALOG_MMSO_ADVERTISING_HALF_CATALOG]
            ?? '';
        $catalogMmsoFullAdvertisingCatalog           = $catalogQuantity[Constants::CATALOG_MMSO_FULL_ADVERTISING_CATALOG]
            ?? '';
        $catalogMmsoBadgeScanner                     = $catalogQuantity[Constants::CATALOG_MMSO_BADGE_SCANNER] ?? '';
        $catalogMmsoRentSecondFloorForEducation      = $catalogQuantity[Constants::CATALOG_MMSO_RENT_SECOND_FLOOR_FOR_EDUCATION]
            ?? '';
        $catalogMmsoRentSecondFloor                  = $catalogQuantity[Constants::CATALOG_MMSO_RENT_SECOND_FLOOR_FOR_ORGANIZING_COMMITTEE]
            ?? '';

        if ($catalogElementsLinks) {
            $goods = CatalogHelper::getCatalogElementsWithLeads(
                $this->config['amo']['email'],
                $this->config['amo']['hash'],
                Constants::CATALOG_GOODS_ID,
                array_column($catalogElementsLinks, 'to_id')
            );

            foreach ($goods as $good) {
                $goodName = $good['name'] ?? null;
                $goodId   = (int)($good['id'] ?? null);
                if (mb_stripos($goodName, 'Электроснабжение стенда') !== false) {
                    preg_match('/Электроснабжение стенда до\s(?<kw>\d+)\sкВт/ui', $goodName, $matches);
                    $kwName  = (int)($matches['kw'] ?? 0);
                    $kwNum   = $catalogQuantity[$good['id']] ?? 0;
                    $kwPrice = $kwNum * $this->amo->getCustomFieldValue($good, Constants::CF_CATALOG_PRICE);
                }

                if (mb_stripos($goodName, 'Интернет до') !== false) {
                    preg_match('/Интернет до\s(?<ethernet>\d+)\sМбит\/сек/ui', $goodName, $matches);
                    $ethernetName  = (int)($matches['ethernet'] ?? 0);
                    $ethernetNum   = $catalogQuantity[$good['id']] ?? 0;
                    $ethernetPrice = $ethernetNum * $this->amo->getCustomFieldValue($good, Constants::CF_CATALOG_PRICE);
                }

                switch ($goodId) {
                    case Constants::CATALOG_MMSO_PERFORMANCE_15:
                        $performance15Price = $this->amo->getCustomFieldValue($good, Constants::CF_CATALOG_PRICE);

                        break;
                    case Constants::CATALOG_MMSO_PERFORMANCE_30:
                        $performance30Price = $this->amo->getCustomFieldValue($good, Constants::CF_CATALOG_PRICE);

                        break;
                    case Constants::CATALOG_MMSO_PERFORMANCE_60:
                        $performance60Price = $this->amo->getCustomFieldValue($good, Constants::CF_CATALOG_PRICE);

                        break;
                    case Constants::CATALOG_MMSO_CLEANING_FURNITURE:
                        $catalogMmsoCleaningFurniturePrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );

                        break;
                    case Constants::CATALOG_MMSO_CLEANING_FLOOR:
                        $catalogMmsoCleaningFloorPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );

                        break;
                    case Constants::CATALOG_MMSO_CLEANING_CARPET:
                        $catalogMmsoCleaningCarpetPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );

                        break;
                    case Constants::CATALOG_MMSO_ELECTRO_32:
                        $catalogMmsoElectro32Price = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );

                        break;
                    case Constants::CATALOG_MMSO_SOCKET_220:
                        $catalogMmsoSocket220Price = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );

                        break;
                    case Constants::CATALOG_MMSO_SOCKET_ALONE:
                        $catalogMmsoSocketAlonePrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );

                        break;
                    case Constants::CATALOG_MMSO_SWITCHBOARD_50:
                        $catalogMmsoSwitchBoard50Price = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );

                        break;
                    case Constants::CATALOG_MMSO_PROJECTOR_150W:
                        $catalogMmsoProjector150WPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );

                        break;
                    case Constants::CATALOG_MMSO_PROJECTOR_300W:
                        $catalogMmsoProjector300WPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );

                        break;
                    case Constants::CATALOG_MMSO_SPOT_BRA_100W:
                        $catalogMmsoSpotBra100WPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );

                        break;
                    case Constants::CATALOG_MMSO_LAMP_40W:
                        $catalogMmsoLamp40WPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );

                        break;
                    case Constants::CATALOG_MMSO_WALL_ELEMENT_05:
                        $catalogMmsoWallElement05Price = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_WALL_ELEMENT_075:
                        $catalogMmsoWallElement075Price = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_WALL_ELEMENT_1:
                        $catalogMmsoWallElement1Price = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_WALL_ELEMENT_15:
                        $catalogMmsoWallElement15Price = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_WALL_ELEMENT_WITH_GLASS_05:
                        $catalogMmsoWallElementWithGlass05Price = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_WALL_ELEMENT_WITH_GLASS_1:
                        $catalogMmsoWallElementWithGlass1Price = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_WALL_ELEMENT_WITH_CURTAIN:
                        $catalogMmsoWallElementWithCurtainPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_DOOR_BLOCK_WITH_STANDART_DOOR:
                        $catalogMmsoDoorBlockWithStandartDoorPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_DOOR_BLOCK_WITH_SLIDING_DOOR:
                        $catalogMmsoDoorBlockWithSlidingDoorPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_STAND:
                        $catalogMmsoStandPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_BOARD_LDSP_03:
                        $catalogMmsoBoardLdsp03Price = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_SHELF_LDSP_1:
                        $catalogMmsoShelfLdsp1Price = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_FLOOR_LIFT:
                        $catalogMmsoFloorLiftPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_ADDITIONAL_CARPETING:
                        $catalogMmsoAdditionalCarpetingPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_INFORMATION_DESK_R1:
                        $catalogMmsoInformationDeskR1Price = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_INFORMATION_STAND_WITH_SHELF:
                        $catalogMmsoInformationStandWithShelfPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_PODIUM_TABLE_1M:
                        $catalogMmsoPodiumTable1MPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_PODIUM_TABLE_05M:
                        $catalogMmsoPodiumTable05MPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_DOORS_PODIUM_TABLE:
                        $catalogMmsoDoorsPodiumTablePrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_LOW_SHOWCASE:
                        $catalogMmsoLowShowcasePrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_HIGH_SHOWCASE:
                        $catalogMmsoHighShowcasePrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_SOFT_CHAIR:
                        $catalogMmsoSoftChairPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_BAR_CHAIR:
                        $catalogMmsoBarChairPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_ROUND_TABLE:
                        $catalogMmsoRoundTablePrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_SQUARE_TABLE_67CM:
                        $catalogMmsoSquareTable67CMPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_RECTANGULAR_TABLE_100CM:
                        $catalogMmsoRectangularTable100CMPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_ROUND_GLASS_TABLE:
                        $catalogMmsoRoundGlassTablePrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_BAR_TABLE_LDSP:
                        $catalogMmsoBarTableLdspPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_METAL_STAND_3_SHELF:
                        $catalogMmsoMetalStand3ShelfPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_PLASTIC_STAND_5_SHELF:
                        $catalogMmsoPlasticStand5ShelfPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_COFFEE_MACHINE:
                        $catalogMmsoCoffeeMachinePrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_LEATHER_SOFA_WHITE:
                        $catalogMmsoLeatherSofaWhitePrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_LEATHER_CHAIR_WHITE:
                        $catalogMmsoLeatherChairWhitePrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_ARCHIVE_CUPBOARD:
                        $catalogMmsoArchiveCupboardPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_LIST_HOLDER_STANDARD:
                        $catalogMmsoListHolderStandardPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_LIST_HOLDER_ROTATING:
                        $catalogMmsoListHolderRotatingPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_WALL_HANGER:
                        $catalogMmsoWallHangerPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_FLOOR_HANGER:
                        $catalogMmsoFloorHangerPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_PAPER_BASKET:
                        $catalogMmsoPaperBasketPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_WATER_COOLER_19L:
                        $catalogMmsoWaterCooler19LPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_EXTRA_BOTTLE_WATER:
                        $catalogMmsoExtraBottleWaterPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_TV_42:
                        $catalogMmsoTv42Price = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_TV_50:
                        $catalogMmsoTv50Price = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_TV_60:
                        $catalogMmsoTv60Price = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_FLOOR_STAND_TV:
                        $catalogMmsoFloorStandTvPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_GLASS_COFFEE_TABLE:
                        $catalogMmsoGlassCoffeeTablePrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_COLORED_PASTING_STAMP:
                        $catalogMmsoColoredPastingStampPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_COLORED_PASTING_STAMP_INFORMATION_DESK:
                        $catalogMmsoColoredPastingStampInformationDeskPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_COLORED_PASTING_ORACAL_WALL_PANEL:
                        $catalogMmsoColoredPastingOracalWallPanelPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_COLORED_PASTING_ORACAL_INFORMATION_DESK:
                        $catalogMmsoColoredPastingOracalInformationDeskPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_PASTING_OWN_MATERIAL:
                        $catalogMmsoPastingOwnMaterialPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_CLEANING_PANEL_FROM_MATERIAL:
                        $catalogMmsoCleaningPanelFromMaterialPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_INSCRIPTION_FRIEZE_1_LETTER:
                        $catalogMmsoInscriptionFrieze1LetterPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_BANNER_TILL_3M:
                        $catalogMmsoBannerTill3MPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_BANNER_FROM_3M:
                        $catalogMmsoBannerFrom3MPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_BANNER_INSTALLATION:
                        $catalogMmsoBannerInstallationPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_SINGLE_COLOR_LOGO_INFORMATION_DESK:
                        $catalogMmsoSingleColorLogoInformationDeskPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_SINGLE_COLOR_LOGO_WALL_PANEL:
                        $catalogMmsoSingleColorLogoWallPanelPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_MULTICOLOR_COLOR_LOGO_INFORMATION_DESK:
                        $catalogMmsoMultiColorLogoInformationDeskPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_MULTICOLOR_LOGO_WALL_PANEL:
                        $catalogMmsoMultiColorLogoWallPanelPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_ONE_TIME_WINDOW_CLEANING:
                        $catalogMmsoOneTimeWindowCleaningPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_PRODUCING_EVENTS:
                        $catalogMmsoProducingEventsPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_ONLINE_WELCOME_PACK:
                        $catalogMmsoOnlineWelcomePackPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_SPECIAL_PROJECT_SCHOOL_KINDERGARTEN:
                        $catalogMmsoSpecialProjectSchoolKindergartenPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_ADVERTISING_HALF_CATALOG:
                        $catalogMmsoAdvertisingHalfCatalogPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_FULL_ADVERTISING_CATALOG:
                        $catalogMmsoFullAdvertisingCatalogPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_BADGE_SCANNER:
                        $catalogMmsoBadgeScannerPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_RENT_SECOND_FLOOR_FOR_EDUCATION:
                        $catalogMmsoRentSecondFloorForEducationPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_RENT_SECOND_FLOOR_FOR_ORGANIZING_COMMITTEE:
                        $catalogMmsoRentSecondFloorPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_CLEANING_FLOOR_OLD_PRICE:
                        $catalogMmsoCleaningFloorOldPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_CLEANING_CARPET_OLD_PRICE:
                        $catalogMmsoCleaningCarpetOldPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_ELECTRO_32_OLD_PRICE:
                        $catalogMmsoElectro32OldPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_SOCKET_220_OLD_PRICE:
                        $catalogMmsoSocket220OldPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_SOCKET_ALONE_OLD_PRICE:
                        $catalogMmsoSocketAloneOldPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;


                    case Constants::CATALOG_MMSO_SWITCHBOARD_50_OLD_PRICE:
                        $catalogMmsoSwitchBoard50OldPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_PROJECTOR_150W_OLD_PRICE:
                        $catalogMmsoProjector150WOldPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_PROJECTOR_300W_OLD_PRICE:
                        $catalogMmsoProjector300WOldPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_SPOT_BRA_100W_OLD_PRICE:
                        $catalogMmsoSpotBra100WOldPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_LAMP_40W_OLD_PRICE:
                        $catalogMmsoLamp40WOldPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_WALL_ELEMENT_05_OLD_PRICE:
                        $catalogMmsoWallElement05OldPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_WALL_ELEMENT_075_OLD_PRICE:
                        $catalogMmsoWallElement075OldPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;


                    case Constants::CATALOG_MMSO_WALL_ELEMENT_1_OLD_PRICE:
                        $catalogMmsoWallElement1OldPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_WALL_ELEMENT_15_OLD_PRICE:
                        $catalogMmsoWallElement15OldPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_WALL_ELEMENT_WITH_GLASS_05_OLD_PRICE:
                        $catalogMmsoWallElementWithGlass05OldPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_WALL_ELEMENT_WITH_GLASS_1_OLD_PRICE:
                        $catalogMmsoWallElementWithGlass1OldPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_WALL_ELEMENT_WITH_CURTAIN_OLD_PRICE:
                        $catalogMmsoWallElementWithCurtainOldPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_DOOR_BLOCK_WITH_STANDART_DOOR_OLD_PRICE:
                        $catalogMmsoDoorBlockWithDoorOldPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_DOOR_BLOCK_WITH_SLIDING_DOOR_OLD_PRICE:
                        $catalogMmsoDoorBlockWithSlidingDoorOldPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;


                    case Constants::CATALOG_MMSO_STAND_OLD_PRICE:
                        $catalogMmsoStandOldPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_BOARD_LDSP_03_OLD_PRICE:
                        $catalogMmsoBoardLdsp03OldPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_SHELF_LDSP_1_OLD_PRICE:
                        $catalogMmsoShelfLdsp1OldPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_FLOOR_LIFT_OLD_PRICE:
                        $catalogMmsoFloorLiftOldPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_ADDITIONAL_CARPETING_OLD_PRICE:
                        $catalogMmsoAdditionalCarpetingOldPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_INFORMATION_DESK_R1_OLD_PRICE:
                        $catalogMmsoInformationDeskR1OldPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_INFORMATION_STAND_WITH_SHELF_OLD_PRICE:
                        $catalogMmsoInformationStandWithShelfOldPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_PODIUM_TABLE_1M_OLD_PRICE:
                        $catalogMmsoPodiumTable1MOldPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_PODIUM_TABLE_05M_OLD_PRICE:
                        $catalogMmsoPodiumTable05MOldPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_DOORS_PODIUM_TABLE_OLD_PRICE:
                        $catalogMmsoDoorsPodiumTableOldPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_LOW_SHOWCASE_OLD_PRICE:
                        $catalogMmsoLowShowcaseOldPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_HIGH_SHOWCASE_OLD_PRICE:
                        $catalogMmsoHighShowcaseOldPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_SOFT_CHAIR_OLD_PRICE:
                        $catalogMmsoSoftChairOldPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_ROUND_TABLE_OLD_PRICE:
                        $catalogMmsoRoundTableOldPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_SQUARE_TABLE_67CM_OLD_PRICE:
                        $catalogMmsoSquareTable67CMOldPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_RECTANGULAR_TABLE_100CM_OLD_PRICE:
                        $catalogMmsoRectangularTable100CMOldPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_ROUND_GLASS_TABLE_OLD_PRICE:
                        $catalogMmsoRoundGlassTableOldPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_BAR_TABLE_LDSP_OLD_PRICE:
                        $catalogMmsoBarTableLdspOldPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_METAL_STAND_3_SHELF_OLD_PRICE:
                        $catalogMmsoMetalStand3ShelfOldPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_PLASTIC_STAND_5_SHELF_OLD_PRICE:
                        $catalogMmsoPlasticStand5ShelfOldPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_COFFEE_MACHINE_OLD_PRICE:
                        $catalogMmsoCoffeeMachineOldPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_LEATHER_SOFA_WHITE_OLD_PRICE:
                        $catalogMmsoLeatherSofaWhiteOldPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_LEATHER_CHAIR_WHITE_OLD_PRICE:
                        $catalogMmsoLeatherChairWhiteOldPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_ARCHIVE_CUPBOARD_OLD_PRICE:
                        $catalogMmsoArchiveCupboardOldPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_LIST_HOLDER_STANDARD_OLD_PRICE:
                        $catalogMmsoListHolderStandardOldPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_LIST_HOLDER_ROTATING_OLD_PRICE:
                        $catalogMmsoListHolderRotatingOldPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_WALL_HANGER_OLD_PRICE:
                        $catalogMmsoWallHangerOldPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_FLOOR_HANGER_OLD_PRICE:
                        $catalogMmsoFloorHangerOldPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_PAPER_BASKET_OLD_PRICE:
                        $catalogMmsoPaperBasketOldPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_WATER_COOLER_19L_OLD_PRICE:
                        $catalogMmsoWaterCooler19LOldPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_EXTRA_BOTTLE_WATER_OLD_PRICE:
                        $catalogMmsoExtraBottleWaterOldPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_TV_42_OLD_PRICE:
                        $catalogMmsoTv42OldPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_TV_50_OLD_PRICE:
                        $catalogMmsoTv50OldPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_TV_60_OLD_PRICE:
                        $catalogMmsoTv60OldPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_FLOOR_STAND_TV_OLD_PRICE:
                        $catalogMmsoFloorStandTvOldPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_GLASS_COFFEE_TABLE_OLD_PRICE:
                        $catalogMmsoGlassCoffeeTableOldPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_COLORED_PASTING_STAMP_OLD_PRICE:
                        $catalogMmsoColoredPastingStampOldPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_COLORED_PASTING_STAMP_INFORMATION_DESK_OLD_PRICE:
                        $catalogMmsoColoredPastingStampInformationDeskOldPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_COLORED_PASTING_ORACAL_WALL_PANEL_OLD_PRICE:
                        $catalogMmsoColoredPastingOracalWallPanelOldPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_COLORED_PASTING_ORACAL_INFORMATION_DESK_OLD_PRICE:
                        $catalogMmsoColoredPastingOracalInformationDeskOldPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_PASTING_OWN_MATERIAL_OLD_PRICE:
                        $catalogMmsoPastingOwnMaterialOldPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_CLEANING_PANEL_FROM_MATERIAL_OLD_PRICE:
                        $catalogMmsoCleaningPanelFromMaterialOldPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_INSCRIPTION_FRIEZE_1_LETTER_OLD_PRICE:
                        $catalogMmsoInscriptionFrieze1LetterOldPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_BANNER_TILL_3M_OLD_PRICE:
                        $catalogMmsoBannerTill3MOldPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_BANNER_FROM_3M_OLD_PRICE:
                        $catalogMmsoBannerFrom3MOldPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_BANNER_INSTALLATION_OLD_PRICE:
                        $catalogMmsoBannerInstallationOldPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_SINGLE_COLOR_LOGO_INFORMATION_DESK_OLD_PRICE:
                        $catalogMmsoSingleColorLogoInformationDeskOldPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_SINGLE_COLOR_LOGO_WALL_PANEL_OLD_PRICE:
                        $catalogMmsoSingleColorLogoWallPanelOldPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_MULTICOLOR_COLOR_LOGO_INFORMATION_DESK_OLD_PRICE:
                        $catalogMmsoMultiColorLogoInformationDeskOldPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_MULTICOLOR_LOGO_WALL_PANEL_OLD_PRICE:
                        $catalogMmsoMultiColorLogoWallPanelOldPrice = $this->amo->getCustomFieldValue(
                            $good,
                            Constants::CF_CATALOG_PRICE
                        );
                        break;

                    case Constants::CATALOG_MMSO_PRODUCING_EVENTS_OLD_PRICE:
                        $catalogMmsoProducingEventsOldPrice = $this->amo->getCustomFieldValue(
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

        $range   = 'A1:JU';
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
            $rowData[$notEmptyRow][22] ?? '',                   // W
            $buildingPrice,                                     // X (Цена застройки, за метр)
            $rowData[$notEmptyRow][24] ?? '',                   // Y
            $rowData[$notEmptyRow][25] ?? '',                   // Z
            $companyType,                                       // AA (Тип Аренды)
            $rentBudget,                                        // AB (Аренда руб)
            $kwName ?? '',                                      // AC (Электричество, кВт)
            $kwNum ?? '',                                       // AD (множитель)
            $kwPrice ?? '',                                     // AE (Электричество, руб)
            $ethernetName ?? '',                                // AF (Интернет Мбит/сек)
            $ethernetNum ?? '',                                 // AG (множитель)
            $ethernetPrice ?? '',                               // AH (Интернет, руб)
            $performance15Num,                                  // AI (Выступление 15 минут (кол-во))
            $performance30Num,                                  // AJ (Выступление 30 минут (кол-во))
            $performance60Num,                                  // AK (Выступление 60 минут (кол-во))
            $performancePrice ? : '',                           // AL (ВЫСТУПЛЕНИЯ, всего на сумму в руб)
            $responsibleUserName,                               // AM (Отв. менеджер)
            $catalogMmsoCleaningFurniture,                      // AN (ММСО) Влажная уборка мебели
            $catalogMmsoCleaningFurniturePrice ?? '',           // AO (ММСО) Влажная уборка мебели руб
            $rowData[$notEmptyRow][41] ?? '',                   // AP
            $catalogMmsoCleaningFloor ?? '',                    // AQ (ММСО) Влажная уборка пола
            $catalogMmsoCleaningFloorPrice ?? $catalogMmsoCleaningFloorOldPrice ?? '',               // AR (ММСО) Влажная уборка пола руб
            $rowData[$notEmptyRow][44] ?? '',                   // AS
            $catalogMmsoCleaningCarpet,                         // AT (ММСО) Уборка коврового покрытия
            $catalogMmsoCleaningCarpetPrice ?? $catalogMmsoCleaningCarpetOldPrice ?? '',              // AU (ММСО) Уборка коврового покрытия руб
            $rowData[$notEmptyRow][47] ?? '',                   // AV
            $rowData[$notEmptyRow][48] ?? '',                   // AW
            $rowData[$notEmptyRow][49] ?? '',                   // AX
            $catalogMmsoElectro32,                              // AY (ММСО) Электро32
            $catalogMmsoElectro32Price ?? $catalogMmsoElectro32OldPrice ?? '',             // AZ (ММСО) Электро32 руб
            $rowData[$notEmptyRow][52] ?? '',                   // BA
            $catalogMmsoSocket220,                              // BB (ММСО) Розетки 220В
            $catalogMmsoSocket220Price ?? $catalogMmsoSocket220OldPrice ?? '',                   // BC (ММСО) Розетки 220В руб
            $rowData[$notEmptyRow][55] ?? '',                   // BD
            $catalogMmsoSocketAlone,                            // BE (ММСО) Розетка 220 B одинарная круглосуточная
            $catalogMmsoSocketAlonePrice ?? $catalogMmsoSocketAloneOldPrice ?? '',                 // BF (ММСО) Розетка 220 B одинарная круглосуточная руб
            $rowData[$notEmptyRow][58] ?? '',                   // BG (ММСО)
            $catalogMmsoSwitchBoard50,                          // BH (ММСО) Распределительный электрощит
            $catalogMmsoSwitchBoard50Price ?? $catalogMmsoSwitchBoard50OldPrice ?? '',               // BI (ММСО) Распределительный электрощит руб
            $rowData[$notEmptyRow][61] ?? '',                   // BJ
            $catalogMmsoProjector150W,                          // BK (ММСО) Прожектор МГ 150 W
            $catalogMmsoProjector150WPrice ?? $catalogMmsoProjector150WOldPrice ?? '',              // BL (ММСО) Прожектор МГ 150 W руб
            $rowData[$notEmptyRow][64] ?? '',                   // BM
            $catalogMmsoProjector300W,                          // BN (ММСО) Прожектор МГ 150 W
            $catalogMmsoProjector300WPrice ?? $catalogMmsoProjector300WOldPrice ?? '',              // BO (ММСО) Прожектор МГ 300 W руб
            $rowData[$notEmptyRow][67] ?? '',                   // BP
            $catalogMmsoSpotBra100W,                            // BQ (ММСО) Спот-бра 100W
            $catalogMmsoSpotBra100WPrice ?? $catalogMmsoSpotBra100WOldPrice ?? '',                 // BR (ММСО) Спот-бра 100W руб
            $rowData[$notEmptyRow][70] ?? '',                   // BS
            $catalogMmsoLamp40W,                                // BT (ММСО) Светильник с люминесцентной лампой 40 W
            $catalogMmsoLamp40WPrice ?? $catalogMmsoLamp40WOldPrice ?? '',                     // BU (ММСО) Светильник с люминесцентной лампой 40 W руб
            $rowData[$notEmptyRow][73] ?? '',                   // BV
            $catalogMmsoWallElement05, // BW (ММСО) Элемент стены 2,5х0,5 м
            $catalogMmsoWallElement05Price ?? $catalogMmsoWallElement05OldPrice ?? '', // BX (ММСО) Элемент стены 2,5х0,5 м
            $rowData[$notEmptyRow][76] ?? '', // BY
            $catalogMmsoWallElement075, // BZ (ММСО) Элемент стены 2,5х0,75 м
            $catalogMmsoWallElement075Price ?? $catalogMmsoWallElement075OldPrice ?? '', // CA (ММСО) Элемент стены 2,5х0,5 м
            $rowData[$notEmptyRow][79] ?? '', // CB
            $catalogMmsoWallElement1, // CC (ММСО) Элемент стены 2,5х1,0 м
            $catalogMmsoWallElement1Price ?? $catalogMmsoWallElement1OldPrice ?? '', // CD (ММСО) Элемент стены 2,5х1,0 v
            $rowData[$notEmptyRow][82] ?? '', // CE
            $catalogMmsoWallElement15, // CF (ММСО) Элемент стены 2,5х1,5 м
            $catalogMmsoWallElement15Price ?? $catalogMmsoWallElement15OldPrice ?? '', // CG (ММСО) Элемент стены 2,5х1,5 м
            $rowData[$notEmptyRow][85] ?? '', // CH
            $catalogMmsoWallElementWithGlass05, // CI (ММСО) Элемент стены со стеклом 2,5х0,5 м
            $catalogMmsoWallElementWithGlass05Price ?? $catalogMmsoWallElementWithGlass05OldPrice ?? '', // CJ (ММСО) Элемент стены со стеклом 2,5х0,5 м
            $rowData[$notEmptyRow][88] ?? '', // CK
            $catalogMmsoWallElementWithGlass1, // CL (ММСО) Элемент стены со стеклом 2,5х1,0 м
            $catalogMmsoWallElementWithGlass1Price ?? $catalogMmsoWallElementWithGlass1OldPrice ?? '', // CM (ММСО) Элемент стены со стеклом 2,5х1,0 м
            $rowData[$notEmptyRow][91] ?? '', // CN
            $catalogMmsoWallElementWithCurtain, // CO (ММСО) Элемент стены с занавеской
            $catalogMmsoWallElementWithCurtainPrice ?? $catalogMmsoWallElementWithCurtainOldPrice ?? '', // CP (ММСО) Элемент стены с занавеской
            $rowData[$notEmptyRow][94] ?? '', // CQ
            $catalogMmsoDoorBlockWithDoor, // CR (ММСО) Дверной блок (дверь распашная)
            $catalogMmsoDoorBlockWithStandartDoorPrice ?? $catalogMmsoDoorBlockWithDoorOldPrice ?? '', // CS (ММСО) Дверной блок (дверь распашная)
            $rowData[$notEmptyRow][97] ?? '', // CT
            $catalogMmsoDoorSlidingDoor ?? '', // CU (ММСО) Дверной блок с раздвижной дверью
            $catalogMmsoDoorBlockWithSlidingDoorPrice ?? $catalogMmsoDoorBlockWithSlidingDoorOldPrice ?? '', // CV (ММСО) Дверной блок с раздвижной дверью
            $rowData[$notEmptyRow][100] ?? '', // CW
            $catalogMmsoStand, // CX (ММСО) Стойка
            $catalogMmsoStandPrice ?? $catalogMmsoStandOldPrice ?? '', // CY (ММСО) Стойка
            $rowData[$notEmptyRow][103] ?? '', // CZ
            $catalogMmsoBoardLdsp03, // DA (ММСО) Фризовая панель ЛДСП (навесная), h=0,3 м
            $catalogMmsoBoardLdsp03Price ?? $catalogMmsoBoardLdsp03OldPrice ?? '', // DB (ММСО) Фризовая панель ЛДСП (навесная), h=0,3 м
            $rowData[$notEmptyRow][106] ?? '', // DC
            $catalogMmsoShelfLdsp1, // DD (ММСО) Полка ЛДСП 1 х 0,3 м (настенная)
            $catalogMmsoShelfLdsp1Price ?? $catalogMmsoShelfLdsp1OldPrice ?? '', // DE (ММСО) Полка ЛДСП 1 х 0,3 м (настенная)
            $rowData[$notEmptyRow][109] ?? '', // DF
            $catalogMmsoFloorLift, // DG (ММСО) Подъем пола на h=0,2-0,5 м (с ковровым покрытием)
            $catalogMmsoFloorLiftPrice ?? $catalogMmsoFloorLiftOldPrice ?? '', // DH (ММСО) Подъем пола на h=0,2-0,5 м (с ковровым покрытием)
            $rowData[$notEmptyRow][112] ?? '', // DI
            $catalogMmsoAdditionalCarpeting, // DJ (ММСО) Дополнительное ковровое покрытие под стенд
            $catalogMmsoAdditionalCarpetingPrice ?? $catalogMmsoAdditionalCarpetingOldPrice ?? '', // DK (ММСО) Дополнительное ковровое покрытие под стенд
            $rowData[$notEmptyRow][115] ?? '', // DL
            $catalogMmsoInformationDeskR1, // DM (ММСО) Стойка информационная закругленная R-1 м, h=1 м
            $catalogMmsoInformationDeskR1Price ?? $catalogMmsoInformationDeskR1OldPrice ?? '', // DN (ММСО) Стойка информационная закругленная R-1 м, h=1 м
            $rowData[$notEmptyRow][118] ?? '', // DO
            $catalogMmsoInformStandShelf, // DP(ММСО) Стойка информационная с внутренней полкой
            $catalogMmsoInformationStandWithShelfPrice ?? $catalogMmsoInformationStandWithShelfOldPrice ?? '', // DQ(ММСО) Стойка информационная с внутренней полкой
            $rowData[$notEmptyRow][121] ?? '', // DR
            $catalogMmsoPodiumTable1M, // DS(ММСО) Стол-подиум 1х1 м, h=0,75 м
            $catalogMmsoPodiumTable1MPrice ?? $catalogMmsoPodiumTable1MOldPrice ?? '', // DT (ММСО) Стол-подиум 1х1 м, h=0,75 м
            $rowData[$notEmptyRow][124] ?? '', // DU
            $catalogMmsoPodiumTable05M, // DV(ММСО) Стол-подиум 1х0,5 м, h=0,75 м
            $catalogMmsoPodiumTable05MPrice ?? $catalogMmsoPodiumTable05MOldPrice ?? '', // DW (ММСО) Стол-подиум 1х0,5 м, h=0,75 м
            $rowData[$notEmptyRow][127] ?? '', // DX
            $catalogMmsoDoorsPodiumTable, // DY (ММСО) Раздвижные дверцы к столу-подиуму, h=0,75 м
            $catalogMmsoDoorsPodiumTablePrice ?? $catalogMmsoDoorsPodiumTableOldPrice ?? '', // DZ (ММСО) Раздвижные дверцы к столу-подиуму, h=0,75 м
            $rowData[$notEmptyRow][130] ?? '', // EA
            $catalogMmsoLowShowcase, // EB (ММСО) Витрина низкая 1х0,5; h=1м
            $catalogMmsoLowShowcasePrice ?? $catalogMmsoLowShowcaseOldPrice ?? '', // EC (ММСО) Витрина низкая 1х0,5; h=1м
            $rowData[$notEmptyRow][133] ?? '', // ED
            $catalogMmsoHighShowcase, // EE (ММСО) Витрина высокая (2 стеклянные полки)
            $catalogMmsoHighShowcasePrice ?? $catalogMmsoHighShowcaseOldPrice ?? '', // EF (ММСО) Витрина высокая (2 стеклянные полки)
            $rowData[$notEmptyRow][136] ?? '', // EG
            $catalogMmsoSoftChair, // EH (ММСО) Стул п/мягкий
            $catalogMmsoSoftChairPrice ?? $catalogMmsoSoftChairOldPrice ?? '', // EI ((ММСО) Стул п/мягкий
            $rowData[$notEmptyRow][139] ?? '', // EJ
            $catalogMmsoBarChair, // EK (ММСО) Стул барный
            $catalogMmsoBarChairPrice ?? '', // EL (ММСО) Стул барный
            $rowData[$notEmptyRow][142] ?? '', // EM
            $catalogMmsoRoundTable, // EN (ММСО) Стол круглый D=0,7 м
            $catalogMmsoRoundTablePrice ?? $catalogMmsoRoundTableOldPrice ?? '', // EO (ММСО) Стол круглый D=0,7 м
            $rowData[$notEmptyRow][145] ?? '', // EP
            $catalogMmsoSquareTable67CM, // EQ(ММСО) Стол квадратный 67 см х 67 см
            $catalogMmsoSquareTable67CMPrice ?? $catalogMmsoSquareTable67CMOldPrice ?? '', // ER (ММСО) Стол квадратный 67 см х 67 см
            $rowData[$notEmptyRow][148] ?? '', // ES
            $catalogMmsoRectangularTable100CM, // ET (ММСО) Стол прямоугольный 100 см х70 см
            $catalogMmsoRectangularTable100CMPrice ?? $catalogMmsoRectangularTable100CMOldPrice ?? '', // EU (ММСО) Стол прямоугольный 100 см х70 см
            $rowData[$notEmptyRow][151] ?? '', // EV
            $catalogMmsoRoundGlassTable, // EW (ММСО) Стол круглый стеклянный
            $catalogMmsoRoundGlassTablePrice ?? $catalogMmsoRoundGlassTableOldPrice ?? '', // EX (ММСО) Стол круглый стеклянный
            $rowData[$notEmptyRow][154] ?? '', // EY
            $catalogMmsoBarTableLdsp, // EZ (ММСО) Стол барный ЛДСП
            $catalogMmsoBarTableLdspPrice ?? $catalogMmsoBarTableLdspOldPrice ?? '', // FA(ММСО) Стол барный ЛДСП
            $rowData[$notEmptyRow][157] ?? '', // FB
            $catalogMmsoMetalStand3Shelf, // FC (ММСО) Стеллаж металлический 1х0,5 м h=2,5 м (3 полки)
            $catalogMmsoMetalStand3ShelfPrice ?? $catalogMmsoMetalStand3ShelfOldPrice ?? '', // FD (ММСО) Стеллаж металлический 1х0,5 м h=2,5 м (3 полки)
            $rowData[$notEmptyRow][160] ?? '', // FE
            $catalogMmsoPlasticStand5Shelf, // FF (ММСО) Стеллаж пластмассовый (5 полок)
            $catalogMmsoPlasticStand5ShelfPrice ?? $catalogMmsoPlasticStand5ShelfOldPrice ?? '', // FG (ММСО) Стеллаж пластмассовый (5 полок)
            $rowData[$notEmptyRow][163] ?? '', // FH
            $catalogMmsoCoffeeMachine, // FI (ММСО) Кофеварка
            $catalogMmsoCoffeeMachinePrice ?? $catalogMmsoCoffeeMachineOldPrice ?? '', // FJ (ММСО) Кофеварка
            $rowData[$notEmptyRow][166] ?? '', // FK
            $catalogMmsoLeatherSofaWhite, // FL (ММСО) Диван кожаный белый
            $catalogMmsoLeatherSofaWhitePrice ?? $catalogMmsoLeatherSofaWhiteOldPrice ?? '', // FM (ММСО) Диван кожаный белый
            $rowData[$notEmptyRow][169] ?? '', // FN
            $catalogMmsoLeatherChairWhite, // FO (ММСО) Кресло кожаное белое
            $catalogMmsoLeatherChairWhitePrice ?? $catalogMmsoLeatherChairWhiteOldPrice ?? '', // FP (ММСО) Кресло кожаное белое
            $rowData[$notEmptyRow][172] ?? '', // FQ
            $catalogMmsoArchiveCupboard, // FR (ММСО) Шкаф архивный 1х0,5; h=1 м
            $catalogMmsoArchiveCupboardPrice ?? $catalogMmsoArchiveCupboardOldPrice ?? '', // FS (ММСО) Шкаф архивный 1х0,5; h=1 м
            $rowData[$notEmptyRow][175] ?? '', // FT
            $catalogMmsoListHolderStandard, // FU (ММСО) Листовкодержатель простой
            $catalogMmsoListHolderStandardPrice ?? $catalogMmsoListHolderStandardOldPrice ?? '', // FV (ММСО) Листовкодержатель простой
            $rowData[$notEmptyRow][178] ?? '', // FW
            $catalogMmsoListHolderRotating, // FX (ММСО) Листовкодержатель вращающийся
            $catalogMmsoListHolderRotatingPrice ?? $catalogMmsoListHolderRotatingOldPrice ?? '', // FY (ММСО) Листовкодержатель вращающийся
            $rowData[$notEmptyRow][181] ?? '', // FZ
            $catalogMmsoWallHanger, // GA (ММСО) Вешалка настенная
            $catalogMmsoWallHangerPrice ?? $catalogMmsoWallHangerOldPrice ?? '', // GB (ММСО) Вешалка настенная
            $rowData[$notEmptyRow][184] ?? '', // GC
            $catalogMmsoFloorHanger, // GG (ММСО) Вешалка напольная
            $catalogMmsoFloorHangerPrice ?? $catalogMmsoFloorHangerOldPrice ?? '', // GE (ММСО) Вешалка напольная
            $rowData[$notEmptyRow][187] ?? '', // GF
            $catalogMmsoPaperBasket, // GG (ММСО) Корзина для бумаг
            $catalogMmsoPaperBasketPrice ?? $catalogMmsoPaperBasketOldPrice ?? '', // GH (ММСО) Корзина для бумаг
            $rowData[$notEmptyRow][190] ?? '', // GI
            $catalogMmsoWaterCooler19L, // GJ (ММСО) Кулер + 1 бутылка воды (19 литров)
            $catalogMmsoWaterCooler19LPrice ?? $catalogMmsoWaterCooler19LOldPrice ?? '', // GK (ММСО) Кулер + 1 бутылка воды (19 литров)
            $rowData[$notEmptyRow][193] ?? '', // GL
            $catalogMmsoExtraBottleWater, // GM (ММСО) 1 дополнительная бутылка воды для кулера
            $catalogMmsoExtraBottleWaterPrice ?? $catalogMmsoExtraBottleWaterOldPrice ?? '', // GN (ММСО) 1 дополнительная бутылка воды для кулера
            $rowData[$notEmptyRow][196] ?? '', // GO
            $catalogMmsoTv42, // GP (ММСО) Плазменная панель 42’’
            $catalogMmsoTv42Price ?? $catalogMmsoTv42OldPrice ?? '', // GQ (ММСО) Плазменная панель 42’’
            $rowData[$notEmptyRow][199] ?? '', // GR
            $catalogMmsoTv50, // GS (ММСО) Плазменная панель 50’’
            $catalogMmsoTv50Price ?? $catalogMmsoTv50OldPrice ?? '', // GT (ММСО) Плазменная панель 50’’
            $rowData[$notEmptyRow][202] ?? '', // GU
            $catalogMmsoTv60, // GV (ММСО) Плазменная панель 60’’
            $catalogMmsoTv60Price ?? $catalogMmsoTv60OldPrice ?? '', // GW (ММСО) Плазменная панель 60’’
            $rowData[$notEmptyRow][205] ?? '', // GX
            $catalogMmsoFloorStandTv, // GY (ММСО) Напольная стойка для крепления плазменной панели
            $catalogMmsoFloorStandTvPrice ?? $catalogMmsoFloorStandTvOldPrice ?? '', // GZ (ММСО) Напольная стойка для крепления плазменной панели
            $rowData[$notEmptyRow][208] ?? '', // HA
            $catalogMmsoGlassCoffeeTable, // HB (ММСО) Журнальный стол стеклянный
            $catalogMmsoGlassCoffeeTablePrice ?? $catalogMmsoGlassCoffeeTableOldPrice ?? '', // HC (ММСО) Журнальный стол стеклянный
            $rowData[$notEmptyRow][211] ?? '', // HD
            $catalogMmsoColoredPastingStamp, // HE (ММСО) Цветная оклейка с печатью 1 стеновой панели
            $catalogMmsoColoredPastingStampPrice ?? $catalogMmsoColoredPastingStampOldPrice ?? '', // HF (ММСО) Цветная оклейка с печатью 1 стеновой панели
            $rowData[$notEmptyRow][214] ?? '', // HG
            $catalogMmsoColoredPastingStampInformationDesk, // HH (ММСО) Цветная оклейка с печатью инфостойки 1 кв.м.
            $catalogMmsoColoredPastingStampInformationDeskPrice ?? $catalogMmsoColoredPastingStampInformationDeskOldPrice ?? '', // HI (ММСО) Цветная оклейка с печатью
            $rowData[$notEmptyRow][217] ?? '', // HJ
            $catalogMmsoColoredPastingOracalWallPanel, // HK (ММСО) Оклейка ORACAL (641 М) 1 стеновой панели
            $catalogMmsoColoredPastingOracalWallPanelPrice ?? $catalogMmsoColoredPastingOracalWallPanelOldPrice ?? '', // HL (ММСО) Оклейка ORACAL (641 М) 1 стеновой панели
            $rowData[$notEmptyRow][220] ?? '', // HM
            $catalogMmsoColoredPastingOracalInformationDesk, // HN (ММСО) Оклейка ORACAL (641 М) инфостойки
            $catalogMmsoColoredPastingOracalInformationDeskPrice ?? $catalogMmsoColoredPastingOracalInformationDeskOldPrice ?? '', // HO (ММСО) Оклейка ORACAL (641 М) инфостойки
            $rowData[$notEmptyRow][223] ?? '', // HP
            $catalogMmsoPastingOwnMaterial, // HQ (ММСО) Оклейка материалами заказчика
            $catalogMmsoPastingOwnMaterialPrice ?? $catalogMmsoPastingOwnMaterialOldPrice ?? '', // HR (ММСО) Оклейка материалами заказчика
            $rowData[$notEmptyRow][226] ?? '', // HS
            $catalogMmsoCleaningPanelFromMat, // HT (ММСО) Очистка панелей от оклееных материалов заказчика
            $catalogMmsoCleaningPanelFromMaterialPrice ?? $catalogMmsoCleaningPanelFromMaterialOldPrice ?? '', // HU (ММСО) Очистка панелей от оклееных материалов
            $rowData[$notEmptyRow][229] ?? '', // HV
            $catalogMmsoInscriptionFrieze1, // HW (ММСО) Надпись на фризе h= 10 см (1 буква)
            $catalogMmsoInscriptionFrieze1LetterPrice ?? $catalogMmsoInscriptionFrieze1LetterOldPrice ?? '', // HX (ММСО) Надпись на фризе h= 10 см (1 буква)
            $rowData[$notEmptyRow][232] ?? '', // HY
            $catalogMmsoBannerTill3M, // HZ (ММСО) Баннер/сетка с печатью до 3 кв.м. (люверсы/закладные) печать и монтаж
            $catalogMmsoBannerTill3MPrice ?? $catalogMmsoBannerTill3MOldPrice ?? '', // IA (ММСО) Баннер/сетка с печатью до 3 кв.м. (люверсы/закладные)
            $rowData[$notEmptyRow][235] ?? '', // IB
            $catalogMmsoBannerFrom3M, // IC (ММСО) Баннер/сетка с печатью от 3 кв.м. (люверсы/закладные) печать и монтаж
            $catalogMmsoBannerFrom3MPrice ?? $catalogMmsoBannerFrom3MOldPrice ?? '', // ID (ММСО) Баннер/сетка с печатью от 3 кв.м. (люверсы/закладные)
            $rowData[$notEmptyRow][238] ?? '', // IE
            $catalogMmsoBannerInstallation, // IF (ММСО) Монтаж баннера/сетки заказчика
            $catalogMmsoBannerInstallationPrice ?? $catalogMmsoBannerInstallationOldPrice ?? '', // IG (ММСО) Монтаж баннера/сетки заказчика
            $rowData[$notEmptyRow][241] ?? '', // IH
            $catalogMmsoSingleColorLogoInformationDesk, // II (ММСО) Логотип одноцветный (до 1 кв.м) на инфостойке
            $catalogMmsoSingleColorLogoInformationDeskPrice ?? $catalogMmsoSingleColorLogoInformationDeskOldPrice ?? '', // IJ (ММСО) Логотип одноцветный (до 1 кв.м)
            $rowData[$notEmptyRow][244] ?? '', // IK
            $catalogMmsoSingleColorLogoWallPanel, // IL (ММСО) Логотип одноцветный (до 1 кв.м) на стеновой панели
            $catalogMmsoSingleColorLogoWallPanelPrice ?? $catalogMmsoSingleColorLogoWallPanelOldPrice ?? '', // IM (ММСО) Логотип одноцветный (до 1 кв.м)
            $rowData[$notEmptyRow][247] ?? '', // IN
            $catalogMmsoMultiColorLogoInformationDesk, // IO (ММСО) Логотип многоцветный (до 1 кв.м) на инфостойке
            $catalogMmsoMultiColorLogoInformationDeskPrice ?? $catalogMmsoMultiColorLogoInformationDeskOldPrice ?? '', // IP (ММСО) Логотип многоцветный (до 1 кв.м)
            $rowData[$notEmptyRow][250] ?? '', // IQ
            $catalogMmsoMultiColorLogoWallPanel, // IR (ММСО) Логотип многоцветный (до 1 кв.м) на стеновой панеле
            $catalogMmsoMultiColorLogoWallPanelPrice ?? $catalogMmsoMultiColorLogoWallPanelOldPrice ?? '', // IS (ММСО) Логотип многоцветный (до 1 кв.м)
            $rowData[$notEmptyRow][253] ?? '', // IT
            $catalogMmsoOneTimeWindowCleaning, // IU (ММСО) Одноразовая чистка стекол в витринах (за кв.м. поверхности)
            $catalogMmsoOneTimeWindowCleaningPrice ?? '', // IV (ММСО) Одноразовая чистка стекол в витринах
            $rowData[$notEmptyRow][256] ?? '', // IW
            $catalogMmsoProducingEvents, // IX (ММСО) ПРОДЮСИРОВАНИЕ МЕРОПРИЯТИЙ ДЕЛОВОЙ ПРОГРАММЫ
            $catalogMmsoProducingEventsPrice ?? $catalogMmsoProducingEventsOldPrice ?? '', // IY (ММСО) ПРОДЮСИРОВАНИЕ МЕРОПРИЯТИЙ ДЕЛОВОЙ ПРОГРАММЫ
            $rowData[$notEmptyRow][259] ?? '', // IZ
            $catalogMmsoOnlineWelcomePack, // JA (ММСО) ONLINE WELCOME PACK ДЛЯ ПОСЕТИТЕЛЕЙ
            $catalogMmsoOnlineWelcomePackPrice ?? '', // JB (ММСО) ONLINE WELCOME PACK ДЛЯ ПОСЕТИТЕЛЕЙ
            $rowData[$notEmptyRow][262] ?? '', // JC
            $catalogMmsoSpecialProjectSchoolKindergarten, // JD (ММСО) СПЕЦПРОЕКТ ШКОЛА/ДЕТСКИЙ САД
            $catalogMmsoSpecialProjectSchoolKindergartenPrice ?? '', // JE (ММСО) СПЕЦПРОЕКТ ШКОЛА/ДЕТСКИЙ САД
            $rowData[$notEmptyRow][265] ?? '', // JF
            $catalogMmsoAdvertisingHalfCatalog, // JG (ММСО) 1/2 рекламной полосы в каталоге
            $catalogMmsoAdvertisingHalfCatalogPrice ?? '', // JH (ММСО) 1/2 рекламной полосы в каталоге
            $rowData[$notEmptyRow][268] ?? '', // JI
            $catalogMmsoFullAdvertisingCatalog, // JJ (ММСО) 1 рекламная полоса в каталоге
            $catalogMmsoFullAdvertisingCatalogPrice ?? '', // JK (ММСО) 1 рекламная полоса в каталоге
            $rowData[$notEmptyRow][271] ?? '', // JL
            $catalogMmsoBadgeScanner, // JM (ММСО) Сканер бейджей
            $catalogMmsoBadgeScannerPrice ?? '', // JN (ММСО) Сканер бейджей
            $rowData[$notEmptyRow][274] ?? '', // JO
            $catalogMmsoRentSecondFloorForEducation, // JP (ММСО) Аренда площади на 2 этаже для образования
            $catalogMmsoRentSecondFloorForEducationPrice ?? '', // JQ  (ММСО) Аренда площади на 2 этаже для образования
            $rowData[$notEmptyRow][277] ?? '', // JR
            $catalogMmsoRentSecondFloor, // JS (ММСО) Аренда площади на 2 этаже для оргкомитета
            $catalogMmsoRentSecondFloorPrice ?? '', // JT  (ММСО) Аренда площади на 2 этаже
            $rowData[$notEmptyRow][280] ?? '', // JU
        ];
        $insertRow   = $notEmptyRow + 1;

        $this->log->notice('Подготовили данные для записи: ' . print_r($insertData, true));

        try {
            $result = $service->spreadsheets_values->update(
                $this->config['google']['documents_signed_report'],
                "A$insertRow:JU",
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
        $range         = 'A1:JU';
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
