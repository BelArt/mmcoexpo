'use strict';

define(function (require) {
    /**
     * Необходимые библиотеки
     */
    const $     = require('jquery');
    const _     = require('underscore');
    const Chart = require('./libs/Chart.min.js');

    const {widget : {code : CODE, version : VERSION}} = require('json!./manifest.json');

    /**
     * Роут: аналитика -> звонки
     */
    const AREAS_ADV_SETTINGS = 'widget.advanced_settings';

    /**
     * Роут списки -> сделки
     */
    const AREAS_LEADS_LIST = 'leads.list';

    /**
     * Роут списки -> сделки (Канбан)
     */
    const AREAS_LEADS_PIPELINE = 'leads.pipeline';

    /**
     * Тайпкастинг любого значения в массив
     *
     * @param {*} value
     *
     * @returns {Array}
     */
    const castToArray = function (value) {
        return Array.isArray(value) ? value : [value];
    };

    /**
     * Получение текущего роута amoCRM
     *
     * @return {String}
     */
    const getCurrentArea = function () {
        return AMOCRM.getV3WidgetsArea();
    };

    /**
     * Преобразование Объекта JS в строкое представление
     * пригодное для вставки аттрибутов в NODE элемент
     *
     * @param obj Преобразуемый объект
     *
     * @return {string}
     */
    const objectToNodeAttrs = function objectToNodeAttrs(obj) {
        return _.reduce(
            obj,
            function (result, value, key) {
                return `${result} ${key}=${value}`;
            },
            '',
        );
    };

    /**
     * Подключает css файл если он ранее не был подключен
     *
     * @param {string} cssPath Путь до подключаемого файла
     * @param {Object} options Дополнительные опции подключения
     *
     * @return {boolean}
     */
    const includeCss = function includeCss(cssPath, options = {}) {
        if ($(`link[href="${cssPath}"]`).length) {
            return false;
        }

        const optionsToString = objectToNodeAttrs(options);

        $('head').append(`<link rel="stylesheet" href="${cssPath}" type="text/css" ${optionsToString}/>`);

        return true;
    };

    /**
     * Подключает css файл если он ранее не был подключен
     *
     * @param {...string} fileNames Имя подключаемого файла из папки css
     *
     * @return {boolean}
     */
    const includeWidgetCss = function includeWidgetCss(...fileNames) {
        fileNames.forEach(fileName => includeCss(`${CSS_PATH}${fileName}.css?v=${VERSION}`));
    };

    /**
     * Путь до виджета
     *
     * @type {string}
     */
    const WIDGET_PATH = `upl/${CODE}/widget`;

    /**
     * Путь к шаблонам
     *
     * @type {string}
     */
    const BASE_TEMPLATES = `${WIDGET_PATH}/templates/`;

    /**
     * Base URL для запросов на сервер
     *
     * @type {string}
     */
    const BASE_URL = 'https://core.mmco-expo.ru';

    /**
     * URL для получения даннных для отчета верхнего уровня
     *
     * @type {string}
     */
    const TOP_LEVEL_REPORT_URL = `${BASE_URL}/mmcoexpo/report/get_top_level_report_data/tmldm0zrdkvsu0f4whhhehzozdlqzz09`;

    /**
     * URL для получения даннных для отчета Метраж
     *
     * @type {string}
     */
    const METER_REPORT_URL = `${BASE_URL}/mmcoexpo/report/get_meter_report_data/tmldm0zrdkvsu0f4whhhehzozdlqzz09`;

    /**
     * URL для получения даннных для отчета Метраж
     *
     * @type {string}
     */
    const BUDGET_REPORT_URL = `${BASE_URL}/mmcoexpo/report/get_budget_report_data/tmldm0zrdkvsu0f4whhhehzozdlqzz09`;

    /**
     * URL для получения даннных для отчета Компании
     *
     * @type {string}
     */
    const COMPANIES_REPORT_URL = `${BASE_URL}/mmcoexpo/report/get_companies_report_data/tmldm0zrdkvsu0f4whhhehzozdlqzz09`;

    /**
     * Объект с соответствием названия отчета и URL для получения данных по нему
     *
     * @type {{top_level_report:string}}
     */
    const REPORT_URL_BY_NAME = {
        'top_level_report' : TOP_LEVEL_REPORT_URL,
        'meter_report'     : METER_REPORT_URL,
        'budget_report'    : BUDGET_REPORT_URL,
        'companies_report' : COMPANIES_REPORT_URL,
    };

    /**
     * Путь к таблицам стилей
     *
     * @type {string}
     */
    const CSS_PATH = `/${WIDGET_PATH}/css/`;

    /**
     * Получает данные для указанного отчета с сервера.
     *
     * @param reportName
     * @returns {Promise<Array>|PromiseLike<Array> | Promise<Array>}
     */
    const getReportData = function getReportData(reportName) {
        if (!reportName) {
            return Promise.resolve([]);
        }

        return $.get(REPORT_URL_BY_NAME[reportName])
            .promise()
            .then(response => response.data);
    };

    /**
     * Возвращает является ли текущий пользователь админом или нет.
     *
     * @return boolean
     */
    const isAdmin = function isAdmin() {
        const managers = AMOCRM.constant('managers');
        const user     = AMOCRM.constant('user');

        for (let key in managers) {
            if (user.login == managers[key].login) {
                return managers[key].is_admin == 'Y';
            }
        }

        return false;
    };

    const renderMenuIcon = function () {
        const $lastNavMenu = $('.nav__menu__item', '#nav_menu')
            .last();

        if ($lastNavMenu.find('.report-icon').length) {
            return true;
        }

        const $clonedMenu = $lastNavMenu.clone();

        $clonedMenu
            .data('entity', 'dashboard');
        $clonedMenu
            .find('.nav__menu__item__title')
            .text('Отчеты');
        $clonedMenu
            .find('.icon-settings')
            .removeClass('icon-settings')
            .addClass('report-icon');
        $clonedMenu
            .find('.nav__menu__item__link')
            .attr('href', `/settings/widgets/${CODE}`);

        $lastNavMenu.after($clonedMenu);
    };

    const renderReportButton = function () {
        const $menu = $('.list__top__actions .button-input__context-menu');
        $menu.attr('id', 'exponents-report');
        $menu.find('.button-input__context-menu__item.js-list-export').after(
                `<li class="button-input__context-menu__item  element__ exponents-report-button">
                    <div class="button-input__context-menu__item__inner">
                        <svg class="button-input__context-menu__item__icon svg-icon" xmlns="http://www.w3.org/2000/svg" x="0px" y="0px" width="15" height="15" viewBox="0 0 50 50" style=" fill:#000000;"><g id="surface1"><path style=" " d="M 28.90625 1.96875 C 28.863281 1.976563 28.820313 1.988281 28.78125 2 L 11.5 2 C 9.585938 2 8 3.558594 8 5.46875 L 8 43.90625 C 8 46.160156 9.867188 48 12.125 48 L 37.875 48 C 40.132813 48 42 46.160156 42 43.90625 L 42 15.1875 C 42.027344 15.054688 42.027344 14.914063 42 14.78125 L 42 14.5 C 42.007813 14.234375 41.90625 13.972656 41.71875 13.78125 L 30.21875 2.28125 C 30.027344 2.09375 29.765625 1.992188 29.5 2 L 29.1875 2 C 29.097656 1.976563 29 1.964844 28.90625 1.96875 Z M 11.5 4 L 28 4 L 28 12.34375 C 28 14.355469 29.644531 16 31.65625 16 L 40 16 L 40 43.90625 C 40 45.074219 39.054688 46 37.875 46 L 12.125 46 C 10.945313 46 10 45.074219 10 43.90625 L 10 5.46875 C 10 4.644531 10.660156 4 11.5 4 Z M 30 4.9375 L 39.0625 14 L 31.65625 14 C 30.722656 14 30 13.277344 30 12.34375 Z M 17 24 L 17 38 L 33 38 L 33 24 Z M 19 26 L 24 26 L 24 28 L 19 28 Z M 26 26 L 31 26 L 31 28 L 26 28 Z M 19 30 L 24 30 L 24 32 L 19 32 Z M 26 30 L 31 30 L 31 32 L 26 32 Z M 19 34 L 24 34 L 24 36 L 19 36 Z M 26 34 L 31 34 L 31 36 L 26 36 Z "></path></g></svg>\
                        <span class="button-input__context-menu__item__text">Отчет экспоненты</span>
                     </div>
                </li>`
             );
    };

    let currentArea = getCurrentArea();

    return function AnalyticsBlock() {
        const self = this;

        /**
         * Возвращает html на основе встроенного twig-шаблона
         *
         * @param {string}   templateName Имя шаблона
         *
         * @return {Promise}
         */
        const getTemplate = (templateName) => {
            return new Promise((resolve) => {
                self.render({
                    href      : BASE_TEMPLATES + templateName + '.twig',
                    base_path : '/',
                    v         : VERSION,
                    load      : template => resolve({
                        render : (data = {}) =>
                            template.render({
                                ...data,
                                baseTemplates : BASE_TEMPLATES,
                                i18n          : self.i18n.bind(self),
                            }),
                    }),
                })
                ;
            });
        };

        const startLoader = async function startLoader() {
            const $widgetBlock = $('#work_area_dashboard');
            if ($widgetBlock.find('.dashboard-overlay').length) {
                return true;
            }

            const loaderTpl = await getTemplate('loader');
            $widgetBlock.append(loaderTpl.render());
        };

        const hideLoader = async function hideLoader() {
            const $widgetBlock = $('#work_area_dashboard');
            const $loader      = $widgetBlock.find('.dashboard-overlay');

            $loader.remove();
        };

        const sleep = function (miliSeconds) {
            return new Promise(function (resolve) {
                setTimeout(resolve, miliSeconds);
            });
        };

        const renderReport = async function (reportName) {
            const [reportTpl, reportData] = await Promise.all([
                getTemplate(`reports/${reportName}`),
                getReportData(reportName),
            ]);

            $('.dashboard_content', '#work_area_dashboard')
                .html(reportTpl.render(reportData));

            if (reportName === 'meter_report') {
                let ctx_meter_meters            = document.getElementById('meter_meters').getContext('2d');
                let ctx_meter_percent           = document.getElementById('meter_percent').getContext('2d');
                ctx_meter_meters.canvas.height  = 400;
                ctx_meter_percent.canvas.height = 400;
                new Chart(ctx_meter_meters, {
                    type    : 'line',
                    data    : {
                        labels   : reportData.graph_meter.labels,
                        datasets : [
                            {
                                label                     : reportData.graph_meter.fact_name,
                                fill                      : false,
                                lineTension               : 0.1,
                                borderColor               : 'red', // The main line color
                                borderCapStyle            : 'square',
                                borderDash                : [], // try [5, 15] for instance
                                borderDashOffset          : 0.0,
                                borderJoinStyle           : 'miter',
                                pointBorderColor          : 'black',
                                pointBackgroundColor      : 'white',
                                pointBorderWidth          : 1,
                                pointHoverRadius          : 8,
                                pointHoverBackgroundColor : 'red',
                                pointHoverBorderColor     : 'white',
                                pointHoverBorderWidth     : 2,
                                pointRadius               : 4,
                                pointHitRadius            : 10,
                                // notice the gap in the data and the spanGaps: true
                                data                      : reportData.graph_meter.fact_data,
                                spanGaps                  : true,
                            },
                            {
                                label                     : reportData.graph_meter.plan_name,
                                fill                      : false,
                                lineTension               : 0.1,
                                borderColor               : 'blue',
                                borderCapStyle            : 'square',
                                borderDash                : [],
                                borderDashOffset          : 0.0,
                                borderJoinStyle           : 'miter',
                                pointBorderColor          : 'black',
                                pointBackgroundColor      : 'white',
                                pointBorderWidth          : 1,
                                pointHoverRadius          : 8,
                                pointHoverBackgroundColor : 'blue',
                                pointHoverBorderColor     : 'white',
                                pointHoverBorderWidth     : 2,
                                pointRadius               : 4,
                                pointHitRadius            : 10,
                                data                      : reportData.graph_meter.plan_data,
                                spanGaps                  : true,
                            },
                            {
                                label                     : reportData.graph_meter.prev_fact_name,
                                fill                      : false,
                                lineTension               : 0.1,
                                borderColor               : 'grey',
                                borderCapStyle            : 'square',
                                borderDash                : [],
                                borderDashOffset          : 0.0,
                                borderJoinStyle           : 'miter',
                                pointBorderColor          : 'black',
                                pointBackgroundColor      : 'white',
                                pointBorderWidth          : 1,
                                pointHoverRadius          : 8,
                                pointHoverBackgroundColor : 'grey',
                                pointHoverBorderColor     : 'white',
                                pointHoverBorderWidth     : 2,
                                pointRadius               : 4,
                                pointHitRadius            : 10,
                                data                      : reportData.graph_meter.prev_fact_data,
                                spanGaps                  : true,
                            },
                        ],
                    },
                    options : {
                        scales              : {
                            yAxes : [
                                {
                                    ticks : {
                                        beginAtZero : true,
                                    },
                                },
                            ],
                        },
                        responsive          : true,
                        maintainAspectRatio : false,
                    },
                });
                new Chart(ctx_meter_percent, {
                    type    : 'line',
                    data    : {
                        labels   : reportData.graph_percent.labels,
                        datasets : [
                            {
                                label                     : reportData.graph_percent.fact_name,
                                fill                      : false,
                                lineTension               : 0.1,
                                borderColor               : 'red', // The main line color
                                borderCapStyle            : 'square',
                                borderDash                : [], // try [5, 15] for instance
                                borderDashOffset          : 0.0,
                                borderJoinStyle           : 'miter',
                                pointBorderColor          : 'black',
                                pointBackgroundColor      : 'white',
                                pointBorderWidth          : 1,
                                pointHoverRadius          : 8,
                                pointHoverBackgroundColor : 'red',
                                pointHoverBorderColor     : 'white',
                                pointHoverBorderWidth     : 2,
                                pointRadius               : 4,
                                pointHitRadius            : 10,
                                // notice the gap in the data and the spanGaps: true
                                data                      : reportData.graph_percent.fact_data,
                                spanGaps                  : true,
                            },
                            {
                                label                     : reportData.graph_percent.plan_name,
                                fill                      : false,
                                lineTension               : 0.1,
                                borderColor               : 'blue',
                                borderCapStyle            : 'square',
                                borderDash                : [],
                                borderDashOffset          : 0.0,
                                borderJoinStyle           : 'miter',
                                pointBorderColor          : 'black',
                                pointBackgroundColor      : 'white',
                                pointBorderWidth          : 1,
                                pointHoverRadius          : 8,
                                pointHoverBackgroundColor : 'blue',
                                pointHoverBorderColor     : 'white',
                                pointHoverBorderWidth     : 2,
                                pointRadius               : 4,
                                pointHitRadius            : 10,
                                data                      : reportData.graph_percent.plan_data,
                                spanGaps                  : true,
                            },
                            {
                                label                     : reportData.graph_percent.prev_fact_name,
                                fill                      : false,
                                lineTension               : 0.1,
                                borderColor               : 'grey',
                                borderCapStyle            : 'square',
                                borderDash                : [],
                                borderDashOffset          : 0.0,
                                borderJoinStyle           : 'miter',
                                pointBorderColor          : 'black',
                                pointBackgroundColor      : 'white',
                                pointBorderWidth          : 1,
                                pointHoverRadius          : 8,
                                pointHoverBackgroundColor : 'grey',
                                pointHoverBorderColor     : 'white',
                                pointHoverBorderWidth     : 2,
                                pointRadius               : 4,
                                pointHitRadius            : 10,
                                data                      : reportData.graph_percent.prev_fact_data,
                                spanGaps                  : true,
                            },
                        ],
                    },
                    options : {
                        scales              : {
                            yAxes : [
                                {
                                    ticks : {
                                        beginAtZero : true,
                                    },
                                },
                            ],
                        },
                        responsive          : true,
                        maintainAspectRatio : false,
                    },
                });
            }

            if (reportName === 'budget_report') {
                let ctx_budget_stand              = document.getElementById('budget_rubles').getContext('2d');
                let ctx_budget_services           = document.getElementById('budget_percents').getContext('2d');
                ctx_budget_stand.canvas.height    = 400;
                ctx_budget_services.canvas.height = 400;
                new Chart(ctx_budget_stand, {
                    type    : 'line',
                    data    : {
                        labels   : reportData.graph_budget_rubles.labels,
                        datasets : [
                            {
                                label                     : reportData.graph_budget_rubles.fact_name,
                                fill                      : false,
                                lineTension               : 0.1,
                                borderColor               : 'red', // The main line color
                                borderCapStyle            : 'square',
                                borderDash                : [], // try [5, 15] for instance
                                borderDashOffset          : 0.0,
                                borderJoinStyle           : 'miter',
                                pointBorderColor          : 'black',
                                pointBackgroundColor      : 'white',
                                pointBorderWidth          : 1,
                                pointHoverRadius          : 8,
                                pointHoverBackgroundColor : 'red',
                                pointHoverBorderColor     : 'white',
                                pointHoverBorderWidth     : 2,
                                pointRadius               : 4,
                                pointHitRadius            : 10,
                                // notice the gap in the data and the spanGaps: true
                                data                      : reportData.graph_budget_rubles.fact_data,
                                spanGaps                  : true,
                            },
                            {
                                label                     : reportData.graph_budget_rubles.plan_name,
                                fill                      : false,
                                lineTension               : 0.1,
                                borderColor               : 'blue',
                                borderCapStyle            : 'square',
                                borderDash                : [],
                                borderDashOffset          : 0.0,
                                borderJoinStyle           : 'miter',
                                pointBorderColor          : 'black',
                                pointBackgroundColor      : 'white',
                                pointBorderWidth          : 1,
                                pointHoverRadius          : 8,
                                pointHoverBackgroundColor : 'blue',
                                pointHoverBorderColor     : 'white',
                                pointHoverBorderWidth     : 2,
                                pointRadius               : 4,
                                pointHitRadius            : 10,
                                data                      : reportData.graph_budget_rubles.plan_data,
                                spanGaps                  : true,
                            },
                        ],
                    },
                    options : {
                        scales              : {
                            yAxes : [
                                {
                                    ticks : {
                                        beginAtZero : true,
                                    },
                                },
                            ],
                        },
                        responsive          : true,
                        maintainAspectRatio : false,
                    },
                });
                new Chart(ctx_budget_services, {
                    type    : 'line',
                    data    : {
                        labels   : reportData.graph_budget_percents.labels,
                        datasets : [
                            {
                                label                     : reportData.graph_budget_percents.fact_name,
                                fill                      : false,
                                lineTension               : 0.1,
                                borderColor               : 'red', // The main line color
                                borderCapStyle            : 'square',
                                borderDash                : [], // try [5, 15] for instance
                                borderDashOffset          : 0.0,
                                borderJoinStyle           : 'miter',
                                pointBorderColor          : 'black',
                                pointBackgroundColor      : 'white',
                                pointBorderWidth          : 1,
                                pointHoverRadius          : 8,
                                pointHoverBackgroundColor : 'red',
                                pointHoverBorderColor     : 'white',
                                pointHoverBorderWidth     : 2,
                                pointRadius               : 4,
                                pointHitRadius            : 10,
                                // notice the gap in the data and the spanGaps: true
                                data                      : reportData.graph_budget_percents.fact_data,
                                spanGaps                  : true,
                            },
                            {
                                label                     : reportData.graph_budget_percents.plan_name,
                                fill                      : false,
                                lineTension               : 0.1,
                                borderColor               : 'blue',
                                borderCapStyle            : 'square',
                                borderDash                : [],
                                borderDashOffset          : 0.0,
                                borderJoinStyle           : 'miter',
                                pointBorderColor          : 'black',
                                pointBackgroundColor      : 'white',
                                pointBorderWidth          : 1,
                                pointHoverRadius          : 8,
                                pointHoverBackgroundColor : 'blue',
                                pointHoverBorderColor     : 'white',
                                pointHoverBorderWidth     : 2,
                                pointRadius               : 4,
                                pointHitRadius            : 10,
                                data                      : reportData.graph_budget_percents.plan_data,
                                spanGaps                  : true,
                            },
                        ],
                    },
                    options : {
                        scales              : {
                            yAxes : [
                                {
                                    ticks : {
                                        beginAtZero : true,
                                    },
                                },
                            ],
                        },
                        responsive          : true,
                        maintainAspectRatio : false,
                    },
                });
            }
        };

        this.callbacks = {
            settings($modalDescr) {
                $('#widget_settings__fields_wrapper', $modalDescr).hide();
            },
            init() {
                includeWidgetCss('main');

                return true;
            },
            bind_actions() {
                if (currentArea !== AREAS_ADV_SETTINGS
                    && currentArea !== AREAS_LEADS_LIST
                    && currentArea !== AREAS_LEADS_PIPELINE
                ) {
                    return true;
                }

                $(document)
                    .off(self.ns)
                    .on('change' + self.ns, 'input[name="dashboard_link"]', function () {
                        const {value : reportName} = this;

                        startLoader();
                        renderReport(reportName)
                            .then(function () {
                                hideLoader();
                            })
                            .catch(function (error) {
                                console.error(error);
                                hideLoader();
                            });
                    })
                    .on('click' + self.ns, '#exponents-report .exponents-report-button', function () {
                        window.open(
                            'https://docs.google.com/spreadsheets/d/1RfhqDbThDoso7ZaLbjilPPSDanOL2cczg0cTkMc1I9I/',
                            '_blank'
                        );
                    });
                return true;
            },
            render() {
                renderReportButton();

                // Виджет работает только для админов.
                if (!isAdmin()) {
                    return true;
                }

                currentArea = getCurrentArea();
                renderMenuIcon();

                return true;
            },
            destroy() {
            },
            advancedSettings() {
                includeWidgetCss('advanced');

                const $workArea = $('#work_area')
                    .attr('id', 'work_area_dashboard');

                getTemplate('advanced')
                    .then(function (advancedTemplate) {
                        $workArea.html(advancedTemplate.render());

                        $('.dashboard_link', '#work_area_dashboard')
                            .first()
                            .find('input')
                            .prop('checked', true)
                            .trigger('change');
                    });
            },
            onSave() {
                return true;
            },
        };

        return this;
    };
});
