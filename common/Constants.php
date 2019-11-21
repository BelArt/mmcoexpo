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

    const PIPELINE_MMSO_SALES = 828097;
    const PIPELINE_BMSO_SALES = 1986121;
    const PIPELINE_UMSO_SALES = 828097;
    const PIPELINE_PARTNERS   = 1557001;

    const STATUS_SALES_CALL_CENTER          = 28579984; // КОЛЛ-ЦЕНТР
    const STATUS_SALES_NEW_REQUEST          = 16969435; // НОВАЯ ЗАЯВКА
    const STATUS_SALES_QUALIFICATION_PASSED = 29297107; // КВАЛИФИКАЦИЯ ПРОЙДЕНА
    const STATUS_SALES_DOCUMENTS_SENT       = 16969438; // ДОКУМЕНТЫ ОТПРАВЛЕНЫ
    const STATUS_SALES_KP_AGREED            = 16969444; // КП СОГЛАСОВАНО
    const STATUS_SALES_BILLED               = 17074543; // СЧЕТ ВЫСТАВЛЕН
    const STATUS_SALES_BILL_PAID            = 17074546; // СЧЕТ ОПЛАЧЕН
    const STATUS_SALES_PAYMENT_RECEIVED     = 17920708; // ПРЕДОПЛАТА ПОЛУЧЕНА
    const STATUS_SALES_DOCUMENTS_SIGNED     = 23227456; // ДОКУМЕНТЫ ПОДПИСАНЫ

    const STATUS_BMSO_SALES_NEW_REQUEST          = 29408416; // Новая заявка
    const STATUS_BMSO_SALES_QUALIFICATION_PASSED = 29408419; // Квалификация пройдена
    const STATUS_BMSO_SALES_DOCUMENTS_SENT       = 29408422; // Заявка отправлена
    const STATUS_BMSO_SALES_KP_AGREED            = 29408569; // Заявка согласована
    const STATUS_BMSO_SALES_DOCUMENTS_SIGNED     = 29408572; // Документы подписаны
    const STATUS_BMSO_SALES_BILLED               = 29408575; // Счет выставлен
    const STATUS_BMSO_SALES_PAYMENT_RECEIVED     = 29408578; // Предоплата получена

    const STATUS_UMSO_SALES_NEW_REQUEST          = 16969435; // Новая заявка
    const STATUS_UMSO_SALES_QUALIFICATION_PASSED = 29297107; // Квалификация пройдена
    const STATUS_UMSO_SALES_DOCUMENTS_SENT       = 16969438; // Заявка отправлена
    const STATUS_UMSO_SALES_KP_AGREED            = 16969444; // Заявка согласована
    const STATUS_UMSO_SALES_DOCUMENTS_SIGNED     = 23227456; // Документы подписаны
    const STATUS_UMSO_SALES_BILLED               = 17074543; // Счет выставлен
    const STATUS_UMSO_SALES_PAYMENT_RECEIVED     = 17920708; // Предоплата получена

    const STATUS_PARTNERS_PRIMARY_CONTACT = 23787037; // ПЕРВИЧНЫЙ КОНТАКТ
    const STATUS_PARTNERS_CONVERSATIONS   = 23787040; // ПЕРЕГОВОРЫ
    const STATUS_PARTNERS_MAKE_DECISION   = 23787043; // ПРИНИМАЮТ РЕШЕНИЕ

    const CATALOG_GOODS_ID = 4713;

    const CATALOG_GOODS_ELEMENT_EXTRA_BADGES         = 506887;
    const CATALOG_GOODS_PERFORMANCE_IN_HALL_60       = 506881;
    const CATALOG_GOODS_PERFORMANCE_IN_HALL_30       = 506883;
    const CATALOG_GOODS_PERFORMANCE_IN_HALL_15       = 506885;
    const CATALOG_GOODS_CLEANING_CARPET              = 506723;
    const CATALOG_GOODS_CLEANING_FLOOR               = 506721;
    const CATALOG_GOODS_CLEANING_WINDOWS             = 506719;
    const CATALOG_GOODS_CLEANING_FURNITURE           = 506717;
    const CATALOG_GOODS_WALL_25_05                   = 506851;
    const CATALOG_GOODS_WALL_25_075                  = 506849;
    const CATALOG_GOODS_WALL_25_10                   = 506845;
    const CATALOG_GOODS_WALL_25_15                   = 506843;
    const CATALOG_GOODS_WALL_WINDOW_25_05            = 506841;
    const CATALOG_GOODS_WALL_WINDOW_25_10            = 506839;
    const CATALOG_GOODS_WALL_CURTAIN                 = 506837;
    const CATALOG_GOODS_DOOR_BLOCK                   = 506835;
    const CATALOG_GOODS_RACK                         = 506831;
    const CATALOG_FASCIA_PANEL                       = 506829;
    const CATALOG_CHIPBOARD_SHELF                    = 506827;
    const CATALOG_DOOR_SLIDING                       = 506833;
    const CATALOG_FLOOR_LIFT                         = 506825;
    const CATALOG_EXTRA_CARPET                       = 506823;
    const CATALOG_ROUNDED_DESK                       = 506821;
    const CATALOG_ROUNDED_SHELF                      = 506819;
    const CATALOG_PODIUM_TABLE_1_1                   = 506817;
    const CATALOG_PODIUM_TABLE_1_05                  = 506815;
    const CATALOG_PODIUM_TABLE_SLIDING_DOORS         = 506813;
    const CATALOG_SHOWCASE_LOW                       = 506811;
    const CATALOG_SHOWCASE_HIGH                      = 506809;
    const CATALOG_CHAIR_SOFT                         = 506807;
    const CATALOG_CHAIR_BAR                          = 506805;
    const CATALOG_TABLE_ROUND                        = 506803;
    const CATALOG_TABLE_SQUARE                       = 506801;
    const CATALOG_TABLE_RECTANGULAR                  = 506799;
    const CATALOG_TABLE_SQUARE_GLASS                 = 506797;
    const CATALOG_TABLE_BAR                          = 506795;
    const CATALOG_SHELVING_METALLIC                  = 506793;
    const CATALOG_SHELVING_PLASTIC                   = 506791;
    const CATALOG_COFFEE_MAKER                       = 506789;
    const CATALOG_SOFA_LEATHER                       = 506787;
    const CATALOG_ARMCHAIR_LEATHER                   = 506785;
    const CATALOG_ARCHIVAL_CABINET                   = 506783;
    const CATALOG_LEAF_HOLDER_SIMPLE                 = 506781;
    const CATALOG_LEAF_HOLDER_ROTATING               = 506779;
    const CATALOG_WALL_HANGER                        = 506777;
    const CATALOG_FLOOR_HANGER                       = 506775;
    const CATALOG_PAPER_BASKET                       = 506773;
    const CATALOG_COOLER                             = 506771;
    const CATALOG_EXTRA_WATER_BOTTLE                 = 506769;
    const CATALOG_PLASMA_42                          = 506767;
    const CATALOG_PLASMA_50                          = 506765;
    const CATALOG_PLASMA_60                          = 506763;
    const CATALOG_FLOOR_STAND_PLASMA                 = 506761;
    const CATALOG_GLASS_COFFEE_TABLE                 = 506759;
    const CATALOG_SWITCHBOARD_32                     = 506867;
    const CATALOG_SOCKET_BLOCK_220                   = 506865;
    const CATALOG_SOCKET                             = 506863;
    const CATALOG_SEARCHING_MG                       = 506859;
    const CATALOG_SEARCHING_300                      = 506857;
    const CATALOG_SPOT_WALL_LAMP                     = 506855;
    const CATALOG_LUMINARIES_LAMP                    = 506853;
    const CATALOG_COLOR_PRINT_STAND                  = 506755;
    const CATALOG_COLOR_PRINT_WALL                   = 506757;
    const CATALOG_ORACA_PRINT                        = 506753;
    const CATALOG_ORACA_PRINT_STAND                  = 506751;
    const CATALOG_PRINT_CUSTOM                       = 506749;
    const CATALOG_FRIEZE_INSCRIPTION                 = 506745;
    const CATALOG_BANNER_TO_3                        = 506743;
    const CATALOG_BANNER_FROM_3                      = 506741;
    const CATALOG_MONTAGE_BANNER                     = 506739;
    const CATALOG_LOGO_ONE_COLOR_WALL                = 506735;
    const CATALOG_LOGO_MULTI_COLOR_WALL              = 506731;
    const CATALOG_LOGO_ONE_COLOR_STAND               = 506737;
    const CATALOG_LOGO_MULTI_COLOR_STAND             = 506733;
    const CATALOG_CLEANING_STAND                     = 506747;
    const CATALOG_RENT_SWITCHBOARD                   = 506861;
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
    const CF_LEAD_RECEIVED_REVENUE        = 508339;
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

    const CF_LEAD_COMPANY_PROFILE_ENUM_NEW  = 995517;
    const CF_LEAD_COMPANY_PROFILE_ENUM_DEAD = 995519;
    const CF_LEAD_COMPANY_PROFILE_ENUM_WARM = 995521;

    const CF_COMPANY_FULL_NAME                 = 508413;
    const CF_COMPANY_COMPANY_PROFILE           = 507865;
    const CF_COMPANY_COMPANY_PROFILE_ENUM_NEW  = 995195;
    const CF_COMPANY_COMPANY_PROFILE_ENUM_WARM = 995197;
    const CF_COMPANY_COMPANY_PROFILE_ENUM_DEAD = 995199;

    const QUEUE_REPS_MAX              = 3;
    const JOB_RESULT_SUCCESS          = 0;
    const JOB_RESULT_FAIL_NO_REPEAT   = 1;
    const JOB_RESULT_FAIL_WITH_REPEAT = 2;

    const TAG_BMSO_2018 = 376237;
    const TAG_UMSO_2018 = 376235;

    const DOCUMENTS_SIGNED_REPORT_TUBE = 'documents_signed_report';
    const IMPORT_LOGIC_TUBE            = 'import_logic';
}
