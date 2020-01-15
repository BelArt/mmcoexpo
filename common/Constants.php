<?php

namespace App\User\Common;

/**
 * Класс с константами из amoCRM
 */
final class Constants
{
    const TIMEZONE = 'Europe/Moscow';

    const STATUS_SUCCESS = 142;
    const STATUS_FAIL    = 143;

    const PIPELINE_SALES = 2050204; // Продажи MMCO

    const STATUS_SALES_NEW_REQUEST          = 29852761; // Первичный контакт
    const STATUS_SALES_QUALIFICATION_PASSED = 29852764; // Квалификация пройдена
    const STATUS_SALES_DOCUMENTS_SENT       = 29852767; // Заявка отправлена
    const STATUS_SALES_KP_AGREED            = 30804361; // Заявка согласована
    const STATUS_SALES_DOCUMENTS_SIGNED     = 30804364; // Документы подписаны
    const STATUS_SALES_BILLED               = 30804367; // Счет выставлен
    const STATUS_SALES_PAYMENT_RECEIVED     = 30804370; // Предоплата получена

    const CATALOG_GOODS_ID = 4713;

    const CATALOG_GOODS_ELEMENT_EXTRA_BADGES         = 506887;
    const CATALOG_GOODS_PERFORMANCE_IN_HALL_60       = 506881;
    const CATALOG_GOODS_PERFORMANCE_IN_HALL_30       = 506883;
    const CATALOG_GOODS_PERFORMANCE_IN_HALL_15       = 506885;
    const CATALOG_BMSO_FLOOR_ON_WHEELS               = 525213;
    const CATALOG_BMSO_ILLUMINATED_CABINET_NARROW    = 525215;
    const CATALOG_BMSO_ILLUMINATED_CABINET           = 525217;
    const CATALOG_BMSO_ILLUMINATED_CABINET_LOCK_1    = 525219;
    const CATALOG_BMSO_ILLUMINATED_CABINET_LIGHT_1   = 525221;
    const CATALOG_BMSO_ILLUMINATED_CABINET_LOCK_0_5  = 525223;
    const CATALOG_BMSO_ILLUMINATED_CABINET_LIGHT_0_5 = 525225;
    const CATALOG_BMSO_RECEPTION_DESK                = 525227;
    const CATALOG_BMSO_RECEPTION_DESK_SMALL          = 525229;
    const CATALOG_BMSO_METAL_HOOK                    = 525231;
    const CATALOG_BMSO_PODIUM                        = 525233;
    const CATALOG_BMSO_MESH_WITH_BASKETS             = 525235;
    const CATALOG_BMSO_ADVERTISING_STAND             = 525237;
    const CATALOG_BMSO_COFFEE_TABLE                  = 525239;
    const CATALOG_BMSO_ROUND_TABLE_GLASS             = 525241;
    const CATALOG_BMSO_ROUND_TABLE_PLASTIC           = 525243;
    const CATALOG_BMSO_COLUMN_FENCE_TAPE             = 525245;
    const CATALOG_BMSO_COLUMN_FENCE_EYE              = 525247;
    const CATALOG_BMSO_BAR_CHAIR                     = 525249;
    const CATALOG_BMSO_BAR_CHAIR_PLASTIC             = 525251;
    const CATALOG_BMSO_BAR_CHAIR_FOLDING_GREY        = 525253;
    const CATALOG_BMSO_BANNER_PRODUCTION             = 525255;
    const CATALOG_BMSO_PASTING_ORAKAL                = 525257;
    const CATALOG_BMSO_PASTING_PRINT_ON_GLUE         = 525259;
    const CATALOG_BMSO_ADDITIONAL_SIGNS_FASCIA_PANEL = 525261;
    const CATALOG_BMSO_PLASMA_PANEL_42               = 525263;
    const CATALOG_BMSO_STAND_PLASMA_PANEL            = 525265;
    const CATALOG_BMSO_DELEGATE_45000                = 525671;
    const CATALOG_BMSO_DELEGATE_55000                = 525673;
    const CATALOG_BMSO_OPTIONAL_BADGE                = 525831;
    const CATALOG_BMSO_PACKAGE_PARTNER_1             = 525833;
    const CATALOG_BMSO_PACKAGE_PARTNER_2             = 525835;
    const CATALOG_BMSO_PACKAGE_PARTNER_3             = 525837;

    const CATALOG_UMSO_PACKAGE_PARTNER_1  = 538359;
    const CATALOG_UMSO_PACKAGE_PARTNER_2  = 538361;
    const CATALOG_UMSO_PACKAGE_DELEGATE_1 = 538363;
    const CATALOG_UMSO_PACKAGE_DELEGATE_2 = 551421;

    const CATALOG_MMSO_PERFORMANCE_15 = 506885;
    const CATALOG_MMSO_PERFORMANCE_30 = 506883;
    const CATALOG_MMSO_PERFORMANCE_60 = 506881;

    const CF_CATALOG_PRICE          = 505713;
    const CF_CATALOG_TOTAL_PRICE    = 505715;
    const CF_CATALOG_TOTAL_QUANTITY = 505717;
    const CF_CATALOG_SERIAL_NUMBER  = 507767;

    const CF_LEAD_STAND_NUMBER            = 430229;
    const CF_LEAD_PRICE_METER             = 507741;
    const CF_LEAD_TOTAL_FOOTAGE           = 450651;
    const CF_LEAD_DESIRED_FOOTAGE         = 145559;
    const CF_LEAD_BUILDING_PRICE_METER    = 507747;
    const CF_LEAD_REGISTRATION_FEE        = 507749;
    const CF_LEAD_EXPOSITION_LOCATION     = 507769;
    const CF_LEAD_COMPANY_PROFILE         = 508337;
    const CF_LEAD_EXPECTED_REVENUE_PRE    = 508343;
    const CF_LEAD_EXPECTED_REVENUE_POST   = 508693;
    const CF_LEAD_NEXT_PRE_PAYMENT_DATE   = 508341;
    const CF_LEAD_NEXT_POST_PAYMENT_DATE  = 508691;
    const CF_LEAD_CLUSTER                 = 429881;
    const CF_LEAD_RENT_BUDGET             = 507629;
    const CF_LEAD_SERVICES_BUDGET         = 507627;
    const CF_LEAD_BUILDING_TYPE           = 508429;
    const CF_LEAD_REGISTRATION_FEE_NUMBER = 508543;
    const CF_LEAD_OUR_YUR_FACE            = 508545;
    const CF_LEAD_CONTRACT_NUMBER         = 508547;
    const CF_LEAD_DISCOUNT                = 507743;
    const CF_LEAD_ACT_SUM                 = 508549;
    const CF_LEAD_COMPANY_TYPE            = 429873;

    const CF_COMPANY_FULL_NAME                 = 508413;

    const QUEUE_REPS_MAX              = 3;
    const JOB_RESULT_SUCCESS          = 0;
    const JOB_RESULT_FAIL_NO_REPEAT   = 1;
    const JOB_RESULT_FAIL_WITH_REPEAT = 2;

    const TAG_PREVIOUS_YEAR_LEADS = 427259;

    const DOCUMENTS_SIGNED_REPORT_TUBE = 'documents_signed_report';
    const IMPORT_LOGIC_TUBE            = 'import_logic';
}
