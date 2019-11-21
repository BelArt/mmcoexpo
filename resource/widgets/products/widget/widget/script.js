define(function (require) {
    const $ = require('jquery');
    const _ = require('underscore');

    const {widget: {code: WIDGET_CODE}} = require('json!./manifest.json');

    const EVENT_PRODUCTS_CHANGE = `productsChanged`;

    const AREA_SETTINGS = 'settings.widgets';

    const CATALOG_CF_PRICE = 505713;
    const FOUR_METERS_TOTAL_BUDGET_BMSO = 28600;

    const PRODUCT_CATALOG_ID = AMOCRM.constant('account').products.catalog_id;
    const LINK_LINKS_SET_URL = '/ajax/v1/links/set/';


    const LEAD_CF_ADDITIONAL_SERVICES_PRICE = 507627;
    const LEAD_CF_RENT_PRICE = 507629;
    const LEAD_CF_ARRANGEMENT_FEE = 507749;
    const LEAD_CF_PRICE_PER_METER = 507741;
    const LEAD_CF_TOTAL_FOOTAGE = 450651;
    const LEAD_CF_SALE = 507743;
    const LEAD_CF_BUILDING_PRICE_PER_METER = 507747;
    const LEAD_CF_EXPOSURE_LOCATION = 507769;
    const LEAD_CF_EXPOSURE_LOCATION_ENUM_LINEAR = 995011;
    const LEAD_CF_EXPOSURE_LOCATION_ENUM_ANGULAR = 995013;
    const LEAD_CF_EXPOSURE_LOCATION_ENUM_HALF_ISLAND = 995015;
    const LEAD_CF_EXPOSURE_LOCATION_ENUM_ISLAND = 995017;


    const MARKUP = {
        [LEAD_CF_EXPOSURE_LOCATION_ENUM_LINEAR]: 0,
        [LEAD_CF_EXPOSURE_LOCATION_ENUM_ANGULAR]: 10,
        [LEAD_CF_EXPOSURE_LOCATION_ENUM_HALF_ISLAND]: 15,
        [LEAD_CF_EXPOSURE_LOCATION_ENUM_ISLAND]: 20,
    };

    let leadProducts = {};

    const getCatalogElement = function getCatalogElement(elementId = [], catalogId = PRODUCT_CATALOG_ID) {
        if (!elementId || !elementId.length) {
            return Promise.resolve([]);
        }

        return $.get(
            '/ajax/v1/catalog_elements/list/',
            {
                json: true,
                catalog_id: catalogId,
                id: elementId,
            },
        )
            .promise()
            .then(response => response.response.catalog_elements);
    };

    const getCf = function getCf(cfs, whereClause) {
        return _.findWhere(cfs, whereClause) || {};
    };

    const getCfValue = function getCfValue(cf) {
        if (cf && cf.values && cf.values[0] && cf.values[0].value) {
            return cf.values[0].value;
        }

        return null;
    };

    const disableCf = function (cfId) {
        $(`input[name="CFV[${cfId}]"]`, '#card_fields').attr('readonly', true);
    };

    const getProductPrice = _.compose(
        getCfValue,
        _.partial(getCf, _, {id: CATALOG_CF_PRICE}),
    );

    const setEntityCf = function setEntityCf(cfId, value) {
        $(`input[name="CFV[${cfId}]"]`, '#card_fields').val(value)
            .trigger('input')
            .trigger('change');
    };

    const getEntityCf = function getEntityCf(cfId, value) {
        return $(`input[name="CFV[${cfId}]"]`, '#card_fields').val();
    };

    const getLinkedProducts = function getLinkedProducts() {
        return $('.catalog_products-in_card').find('form')
            .find('.catalog-fields__container')
            .toArray()
            .reduce(function (acc, product) {
                return {
                    ...acc,
                    [product.dataset.id]: +product.querySelector('.js-change-quantity').value,
                };
            }, {});
    };

    return function ProductsWidget() {
        const self = this;

        this.callbacks = {
            settings() {
                $('#widget_settings__fields_wrapper').hide();
            },
            init() {
                return true;
            },
            bind_actions() {
                const $document = $(document);

                $document
                    .off(self.ns)
                    .on('ajaxComplete' + self.ns, function onAjaxComplete(event, {responseJSON: response}, request) {
                        const isLinksUrl = request.url.startsWith(LINK_LINKS_SET_URL);
                        if (!isLinksUrl) {
                            return;
                        }

                        const isSuccessResponse = response
                            && response.response
                            && response.response.links;

                        if (!isSuccessResponse) {
                            return;
                        }

                        $document.trigger(EVENT_PRODUCTS_CHANGE);
                    })
                    .on(EVENT_PRODUCTS_CHANGE + self.ns, async function onProductsChange() {
                        const newLeadProducts = getLinkedProducts();
                        console.log('newLeadProducts', newLeadProducts);

                        const allProductIds = Object.keys({...leadProducts, ...newLeadProducts});
                        const catalogProducts = await getCatalogElement(allProductIds);
                        const productsInfo = catalogProducts.reduce(function (
                            acc,
                            product,
                        ) {
                            return {
                                ...acc,
                                [product.id]: {
                                    price: +getProductPrice(product.custom_fields || {}),
                                },
                            };
                        }, {});
                        console.log('productsInfo', productsInfo);

                        const additionalPrice = _.reduce(
                            newLeadProducts,
                            function (result, quantity, id) {
                                return result + quantity * productsInfo[id].price;
                            },
                            0,
                        );

                        setEntityCf(LEAD_CF_ADDITIONAL_SERVICES_PRICE, additionalPrice);
                    })
                    .on(
                        'change' + self.ns,
                        [
                            `input[name="CFV[${LEAD_CF_ADDITIONAL_SERVICES_PRICE}]"]`,
                            `input[name="CFV[${LEAD_CF_RENT_PRICE}]"]`,
                            `input[name="CFV[${LEAD_CF_ARRANGEMENT_FEE}]"]`,
                        ].join(', '),
                        function () {
                            const additionalServicePrice = Number(getEntityCf(LEAD_CF_ADDITIONAL_SERVICES_PRICE));
                            const rentPrice = Number(getEntityCf(LEAD_CF_RENT_PRICE));
                            const arrangementFee = Number(getEntityCf(LEAD_CF_ARRANGEMENT_FEE));
                            const sum = additionalServicePrice + rentPrice + arrangementFee;

                            console.group('budget');
                            console.group('params');
                            console.log('additionalServicePrice', additionalServicePrice);
                            console.log('rentPrice', rentPrice);
                            console.log('arrangementFee', arrangementFee);
                            console.groupEnd();
                            console.group('result');
                            console.log('sum', sum);
                            console.groupEnd();
                            console.groupEnd();

                            $('#lead_card_budget')
                                .val(sum)
                                .trigger('input')
                                .trigger('change');
                            $('input[name="lead[PRICE]"]')
                                .val(sum)
                                .trigger('change');
                        },
                    )
                    .on(
                        'change' + self.ns,
                        [
                            `input[name="CFV[${LEAD_CF_PRICE_PER_METER}]"]`,
                            `input[name="CFV[${LEAD_CF_TOTAL_FOOTAGE}]"]`,
                            `input[name="CFV[${LEAD_CF_SALE}]"]`,
                            `input[name="CFV[${LEAD_CF_BUILDING_PRICE_PER_METER}]"]`,
                            `input[name="CFV[${LEAD_CF_EXPOSURE_LOCATION}]"]`,
                        ].join(', '),
                        function () {
                            const pricePerMeter = Number(getEntityCf(LEAD_CF_PRICE_PER_METER));
                            const totalFootage = Number(getEntityCf(LEAD_CF_TOTAL_FOOTAGE));
                            const sale = Number(getEntityCf(LEAD_CF_SALE));
                            const exposureLocation = Number(getEntityCf(LEAD_CF_EXPOSURE_LOCATION));
                            const buildingPricePerMeter = Number(getEntityCf(LEAD_CF_BUILDING_PRICE_PER_METER));
                            const exposureMarkup = MARKUP[exposureLocation] || 0;

                            const totalPrice = totalFootage * pricePerMeter;

                            const totalBuildingPrice = totalFootage * buildingPricePerMeter;
                            const markup = totalPrice * (exposureMarkup - sale) / 100;
                            const result = (totalFootage == 4) ? FOUR_METERS_TOTAL_BUDGET_BMSO : (totalPrice + markup  + totalBuildingPrice);

                            console.group('LEAD_CF_RENT_PRICE');
                            console.group('params');
                            console.log('pricePerMeter', pricePerMeter);
                            console.log('totalFootage', totalFootage);
                            console.log('sale', sale);
                            console.log('exposureLocation', exposureLocation);
                            console.log('buildingPricePerMeter', buildingPricePerMeter);
                            console.groupEnd();

                            console.group('results');
                            console.log('totalPrice', totalPrice);
                            console.log('totalBuildingPrice', totalBuildingPrice);
                            console.log('markup', markup);
                            console.log('result', result);
                            console.groupEnd();
                            console.groupEnd();

                            setEntityCf(LEAD_CF_RENT_PRICE, result);
                        },
                    );

                return true;
            },
            render() {
                const currentArea = AMOCRM.getV3WidgetsArea();
                if (currentArea === AREA_SETTINGS) {
                    return true;
                }

                disableCf(LEAD_CF_ADDITIONAL_SERVICES_PRICE);
                disableCf(LEAD_CF_RENT_PRICE);

                return true;
            },
            destroy() {
            },
            onSave() {
                return true;
            },
        };

        return this;
    };
});