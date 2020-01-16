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

    const CATALOG_GOODS_ELEMENT_EXTRA_BADGES                      = 506887;
    const CATALOG_MMSO_PERFORMANCE_15                             = 506885;
    const CATALOG_MMSO_PERFORMANCE_30                             = 506883;
    const CATALOG_MMSO_PERFORMANCE_60                             = 506881;
    const CATALOG_MMSO_CLEANING_FURNITURE                         = 506717;
    const CATALOG_MMSO_CLEANING_FLOOR                             = 506721;
    const CATALOG_MMSO_CLEANING_CARPET                            = 506723;
    const CATALOG_MMSO_ELECTRO_32                                 = 506867;
    const CATALOG_MMSO_SOCKET_220                                 = 506865;
    const CATALOG_MMSO_SOCKET_ALONE                               = 506863;
    const CATALOG_MMSO_SWITCHBOARD_50                             = 506861;
    const CATALOG_MMSO_PROJECTOR_150W                             = 506859;
    const CATALOG_MMSO_PROJECTOR_300W                             = 506857;
    const CATALOG_MMSO_SPOT_BRA_100W                              = 506855;
    const CATALOG_MMSO_LAMP_40W                                   = 506853;
    const CATALOG_MMSO_WALL_ELEMENT_05                            = 506851;
    const CATALOG_MMSO_WALL_ELEMENT_075                           = 506849;
    const CATALOG_MMSO_WALL_ELEMENT_1                             = 506845;
    const CATALOG_MMSO_WALL_ELEMENT_15                            = 506843;
    const CATALOG_MMSO_WALL_ELEMENT_WITH_GLASS_05                 = 506841;
    const CATALOG_MMSO_WALL_ELEMENT_WITH_GLASS_1                  = 506839;
    const CATALOG_MMSO_WALL_ELEMENT_WITH_CURTAIN                  = 506837;
    const CATALOG_MMSO_DOOR_BLOCK_WITH_STANDART_DOOR              = 506835;
    const CATALOG_MMSO_DOOR_BLOCK_WITH_SLIDING_DOOR               = 506833;
    const CATALOG_MMSO_STAND                                      = 506831;
    const CATALOG_MMSO_BOARD_LDSP_03                              = 506829;
    const CATALOG_MMSO_SHELF_LDSP_1                               = 506827;
    const CATALOG_MMSO_FLOOR_LIFT                                 = 506825;
    const CATALOG_MMSO_ADDITIONAL_CARPETING                       = 506823;
    const CATALOG_MMSO_INFORMATION_DESK_R1                        = 506821;
    const CATALOG_MMSO_INFORMATION_STAND_WITH_SHELF               = 506819;
    const CATALOG_MMSO_PODIUM_TABLE_1M                            = 506817;
    const CATALOG_MMSO_PODIUM_TABLE_05M                           = 506815;
    const CATALOG_MMSO_DOORS_PODIUM_TABLE                         = 506813;
    const CATALOG_MMSO_LOW_SHOWCASE                               = 506811;
    const CATALOG_MMSO_HIGH_SHOWCASE                              = 506809;
    const CATALOG_MMSO_SOFT_CHAIR                                 = 506807;
    const CATALOG_MMSO_BAR_CHAIR                                  = 506805;
    const CATALOG_MMSO_ROUND_TABLE                                = 506803;
    const CATALOG_MMSO_SQUARE_TABLE_67CM                          = 506801;
    const CATALOG_MMSO_RECTANGULAR_TABLE_100CM                    = 506799;
    const CATALOG_MMSO_ROUND_GLASS_TABLE                          = 506797;
    const CATALOG_MMSO_BAR_TABLE_LDSP                             = 506795;
    const CATALOG_MMSO_METAL_STAND_3_SHELF                        = 506793;
    const CATALOG_MMSO_PLASTIC_STAND_5_SHELF                      = 506791;
    const CATALOG_MMSO_COFFEE_MACHINE                             = 506789;
    const CATALOG_MMSO_LEATHER_SOFA_WHITE                         = 506787;
    const CATALOG_MMSO_LEATHER_CHAIR_WHITE                        = 506785;
    const CATALOG_MMSO_ARCHIVE_CUPBOARD                           = 506783;
    const CATALOG_MMSO_LIST_HOLDER_STANDARD                       = 506781;
    const CATALOG_MMSO_LIST_HOLDER_ROTATING                       = 506779;
    const CATALOG_MMSO_WALL_HANGER                                = 506777;
    const CATALOG_MMSO_FLOOR_HANGER                               = 506775;
    const CATALOG_MMSO_PAPER_BASKET                               = 506773;
    const CATALOG_MMSO_WATER_COOLER_19L                           = 506771;
    const CATALOG_MMSO_EXTRA_BOTTLE_WATER                         = 506769;
    const CATALOG_MMSO_TV_42                                      = 506767;
    const CATALOG_MMSO_TV_50                                      = 506765;
    const CATALOG_MMSO_TV_60                                      = 506763;
    const CATALOG_MMSO_FLOOR_STAND_TV                             = 506761;
    const CATALOG_MMSO_GLASS_COFFEE_TABLE                         = 506759;
    const CATALOG_MMSO_COLORED_PASTING_STAMP                      = 506757;
    const CATALOG_MMSO_COLORED_PASTING_STAMP_INFORMATION_DESK     = 506755;
    const CATALOG_MMSO_COLORED_PASTING_ORACAL_WALL_PANEL          = 506753;
    const CATALOG_MMSO_COLORED_PASTING_ORACAL_INFORMATION_DESK    = 506751;
    const CATALOG_MMSO_PASTING_OWN_MATERIAL                       = 506749;
    const CATALOG_MMSO_CLEANING_PANEL_FROM_MATERIAL               = 506747;
    const CATALOG_MMSO_INSCRIPTION_FRIEZE_1_LETTER                = 506745;
    const CATALOG_MMSO_BANNER_TILL_3M                             = 506743;
    const CATALOG_MMSO_BANNER_FROM_3M                             = 506741;
    const CATALOG_MMSO_BANNER_INSTALLATION                        = 506739;
    const CATALOG_MMSO_SINGLE_COLOR_LOGO_INFORMATION_DESK         = 506737;
    const CATALOG_MMSO_SINGLE_COLOR_LOGO_WALL_PANEL               = 506735;
    const CATALOG_MMSO_MULTICOLOR_COLOR_LOGO_INFORMATION_DESK     = 506733;
    const CATALOG_MMSO_MULTICOLOR_LOGO_WALL_PANEL                 = 506731;
    const CATALOG_MMSO_ONE_TIME_WINDOW_CLEANING                   = 506719;
    const CATALOG_MMSO_PRODUCING_EVENTS                           = 565137;
    const CATALOG_MMSO_ONLINE_WELCOME_PACK                        = 565139;
    const CATALOG_MMSO_SPECIAL_PROJECT_SCHOOL_KINDERGARTEN        = 565141;
    const CATALOG_MMSO_ADVERTISING_HALF_CATALOG                   = 566249;
    const CATALOG_MMSO_FULL_ADVERTISING_CATALOG                   = 566251;
    const CATALOG_MMSO_BADGE_SCANNER                              = 566381;
    const CATALOG_MMSO_RENT_SECOND_FLOOR_FOR_EDUCATION            = 574029;
    const CATALOG_MMSO_RENT_SECOND_FLOOR_FOR_ORGANIZING_COMMITTEE = 574031;

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

    const CF_COMPANY_FULL_NAME = 508413;

    const QUEUE_REPS_MAX              = 3;
    const JOB_RESULT_SUCCESS          = 0;
    const JOB_RESULT_FAIL_NO_REPEAT   = 1;
    const JOB_RESULT_FAIL_WITH_REPEAT = 2;

    const TAG_PREVIOUS_YEAR_LEADS = 427259;

    const DOCUMENTS_SIGNED_REPORT_TUBE = 'documents_signed_report';
    const IMPORT_LOGIC_TUBE            = 'import_logic';
}
