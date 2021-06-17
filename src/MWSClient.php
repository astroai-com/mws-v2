<?php

namespace MCS;

use DateTime;
use Exception;
use MCS\MWSEndPoint;
use MCS\MWSConfig;
use League\Csv\Reader;
use League\Csv\Writer;
use SplTempFileObject;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use Spatie\ArrayToXml\ArrayToXml;

class MWSClient {

    const SIGNATURE_METHOD = 'HmacSHA256';
    const SIGNATURE_VERSION = '2';
    const DATE_FORMAT = "Y-m-d\TH:i:s.\\0\\0\\0\\Z";
    const APPLICATION_NAME = 'MCS/MwsClient';

    private $config = [
        'Seller_Id' => null,
        'Marketplace_Id' => null,
        'Access_Key_ID' => null,
        'Secret_Access_Key' => null,
        'MWSAuthToken' => null,
        'Application_Version' => '0.0.*'
    ];
    private $MarketplaceIds = [];
    protected $debugNextFeed = false;
    protected $client = NULL;

    public function __construct(array $config) {
        foreach ($config as $key => $value) {
            if (array_key_exists($key, $this->config)) {
                $this->config[$key] = $value;
            }
        }

        $required_keys = [
            'Marketplace_Id', 'Seller_Id', 'Access_Key_ID', 'Secret_Access_Key'
        ];

        foreach ($required_keys as $key) {
            if (is_null($this->config[$key])) {
                throw new Exception('Required field ' . $key . ' is not set');
            }
        }

        $this->MarketplaceIds = MWSConfig::$MwsMarkets;

        if (!isset($this->MarketplaceIds[$this->config['Marketplace_Id']])) {
            throw new Exception('Invalid Marketplace Id');
        }

        $this->config['Application_Name'] = self::APPLICATION_NAME;
        $this->config['Region_Host'] = $this->MarketplaceIds[$this->config['Marketplace_Id']];
        $this->config['Region_Url'] = 'https://' . $this->config['Region_Host'];
    }

    /**
     * Call this method to get the raw feed instead of sending it
     */
    public function debugNextFeed() {
        $this->debugNextFeed = true;
    }

    /**
     * Returns pricing information for your own offer listings, based on SKU
     * @param array  [$sku_array = []]
     * @param string [$ItemCondition = null]
     * @return array
     */
    public function GetSkuPrice($sku_array = []) {
        if (count($sku_array) > 20) {
            throw new Exception('Maximum SKU is 20');
        }

        $counter = 1;
        $query = [
            'MarketplaceId' => $this->config['Marketplace_Id']
        ];

        foreach ($sku_array as $key) {
            $query['SellerSKUList.SellerSKU.' . $counter] = $key;
            $counter++;
        }

        $response = $this->request('GetMyPriceForSKU', $query);

        if (isset($response['GetMyPriceForSKUResult'])) {
            $response = $response['GetMyPriceForSKUResult'];
            if (array_keys($response) !== range(0, count($response) - 1)) {
                $response = [$response];
            }
        } else {
            return [];
        }

        $array = [];
        foreach ($response as $product) {
            if (isset($product['@attributes']['status']) && $product['@attributes']['status'] == 'Success') {
                if (isset($product['Product']['Offers']['Offer'])) {
                    $array[$product['@attributes']['SellerSKU']] = $product['Product']['Offers']['Offer'];
                } else {
                    $array[$product['@attributes']['SellerSKU']] = [];
                }
            } else {
                $array[$product['@attributes']['SellerSKU']] = false;
            }
        }
        return $array;
    }

    /**
     * Returns pricing information for your own offer listings, based on ASIN
     * @param array [$asin_array = []]
     * @param string [$ItemCondition = null]
     * @return array
     */
    public function GetAsinPrice($asin_array = []) {
        if (count($asin_array) > 20) {
            throw new Exception('Maximum of ASIN is 20');
        }

        $counter = 1;
        $query = [
            'MarketplaceId' => $this->config['Marketplace_Id']
        ];

        foreach ($asin_array as $key) {
            $query['ASINList.ASIN.' . $counter] = $key;
            $counter++;
        }

        $response = $this->request('GetMyPriceForASIN', $query);

        if (isset($response['GetMyPriceForASINResult'])) {
            $response = $response['GetMyPriceForASINResult'];
            if (array_keys($response) !== range(0, count($response) - 1)) {
                $response = [$response];
            }
        } else {
            return [];
        }

        $array = [];
        foreach ($response as $product) {
            if (isset($product['@attributes']['status']) && $product['@attributes']['status'] == 'Success' && isset($product['Product']['Offers']['Offer'])) {
                $array[$product['@attributes']['ASIN']] = $product['Product']['Offers']['Offer'];
            } else {
                $array[$product['@attributes']['ASIN']] = false;
            }
        }
        return $array;
    }

    /**
     * 
     * @param DateTime $from
     * @param DateTime $to
     * @param string $type Update/Create
     * @param array $states
     * @param array $FulfillmentChannels
     * @return array
     */
    public function GetOrders(DateTime $from, DateTime $to, $type = 'Update', $status = ['Pending', 'Shipped', 'Canceled'], $FulfillmentChannels = ['MFN', 'AFN']) {
        if ($type == 'Update') {
            $query = [
                'LastUpdatedAfter' => gmdate(self::DATE_FORMAT, $from->getTimestamp()),
                'LastUpdatedBefore' => gmdate(self::DATE_FORMAT, $to->getTimestamp())
            ];
        } else {
            $query = [
                'CreatedAfter' => gmdate(self::DATE_FORMAT, $from->getTimestamp()),
                'CreatedBefore' => gmdate(self::DATE_FORMAT, $to->getTimestamp())
            ];
        }

        $counter = 1;
        foreach ($status as $st) {
            $query['OrderStatus.Status.' . $counter] = $st;
            $counter = $counter + 1;
        }

        $counter = 1;
        foreach ($FulfillmentChannels as $fulfillmentChannel) {
            $query['FulfillmentChannel.Channel.' . $counter] = $fulfillmentChannel;
            $counter = $counter + 1;
        }

        $response = $this->request('ListOrders', $query);

        if (isset($response['ListOrdersResult']['Orders']['Order'])) {
            if (isset($response['ListOrdersResult']['NextToken'])) {
                return [
                    'ListOrders' => $response['ListOrdersResult']['Orders']['Order'],
                    'NextToken' => $response['ListOrdersResult']['NextToken']
                ];
            }

            $response = $response['ListOrdersResult']['Orders']['Order'];
            if (array_keys($response) !== range(0, count($response) - 1)) {
                return [$response];
            }

            return $response;
        } else {
            return [];
        }
    }

    /**
     * Returns orders created or updated during a time frame that you specify
     * @param string $nextToken
     * @return array
     */
    public function GetOrdersByNextToken($nextToken) {
        $query = [
            'NextToken' => $nextToken
        ];

        $response = $this->request('ListOrdersByNextToken', $query);

        if (isset($response['ListOrdersByNextTokenResult']['Orders']['Order'])) {
            if (isset($response['ListOrdersByNextTokenResult']['NextToken'])) {
                return [
                    'ListOrders' => $response['ListOrdersByNextTokenResult']['Orders']['Order'],
                    'NextToken' => $response['ListOrdersByNextTokenResult']['NextToken']
                ];
            }

            $response = $response['ListOrdersByNextTokenResult']['Orders']['Order'];
            if (array_keys($response) !== range(0, count($response) - 1)) {
                return [$response];
            }
            return $response;
        } else {
            return [];
        }
    }

    /**
     * Returns an order based on the AmazonOrderId values that you specify
     * @param string $AmazonOrderId
     * @return array if the order is found, false if not
     */
    public function GetOrder($AmazonOrderId = '') {
        $response = $this->request('GetOrder', ['AmazonOrderId.Id.1' => $AmazonOrderId]);
        return isset($response['GetOrderResult']['Orders']['Order']) ? $response['GetOrderResult']['Orders']['Order'] : [];
    }

    /**
     * Get multi channel order info
     * @param string $SellerOrderId
     * @return array
     */
    public function GetMcOrder($SellerOrderId = '') {
        $response = $this->request('GetFulfillmentOrder', ['SellerFulfillmentOrderId' => $SellerOrderId]);
        return isset($response['GetFulfillmentOrderResult']) ? $response['GetFulfillmentOrderResult'] : [];
    }
    
        /**
     * Returns order list on the Amazon
     * @param array $AmazonOrderIds
     * @return array if found, false if not
     */
    public function GetOrderList($AmazonOrderIds = []) {
        if (!$AmazonOrderIds) {
            return [];
        }

        if (count($AmazonOrderIds) > 50) {
            throw new Exception('Maximum number of AmazonOrderIds is 50');
        }

        $query = [];
        foreach ($AmazonOrderIds as $k => $AmazonOrderId) {
            $num = $k + 1;
            $query['AmazonOrderId.Id.' . $num] = $AmazonOrderId;
        }
        $response = $this->request('GetOrder', $query);
        if (isset($response['GetOrderResult']['Orders']['Order'])) {
            return $response['GetOrderResult']['Orders']['Order'];
        } else {
            return false;
        }
    }

    /**
     * Returns order items based on the AmazonOrderId that you specify
     * @param string $AmazonOrderId
     * @return array
     */
    public function GetOrderItems($AmazonOrderId = '') {
        if (!$AmazonOrderId) {
            throw new Exception('The query AmazonOrderId is not specified');
        }
        $response = $this->request('ListOrderItems', ['AmazonOrderId' => $AmazonOrderId]);
        $result = array_values($response['ListOrderItemsResult']['OrderItems']);
        if (isset($result[0]['QuantityOrdered'])) {
            return $result;
        } else {
            return $result[0];
        }
    }

    /**
     * Get order financial info
     */
    public function GetOrderFinance($AmazonOrderId = '') {
        $response = $this->request('ListFinancialEvents', ['AmazonOrderId' => $AmazonOrderId]);
        $result = isset($response['ListFinancialEventsResult']['FinancialEvents']) ? $response['ListFinancialEventsResult']['FinancialEvents'] : [];
        return $result;
    }

    /**
     * Get FBA shipment inbound info
     * @param array $ShipmentIds
     * @param array $ShipmentStatus
     * @param DateTime $from
     * @param DateTime $to
     * @return array
     * @throws Exception
     */
    public function ListInboundShipments($ShipmentIds = [], $ShipmentStatus = [], DateTime $from = null, DateTime $to = null) {
        if ($ShipmentIds && count($ShipmentIds) > 50) {
            throw new Exception('Maximum number of ShipmentIds is 50');
        }

        $query = [];
        foreach ($ShipmentIds as $k => $ShipmentId) {
            $num = $k + 1;
            $query['ShipmentIdList.member.' . $num] = $ShipmentId;
        }

        foreach ($ShipmentStatus as $k => $Status) {
            $num = $k + 1;
            $query['ShipmentStatusList.member.' . $num] = $Status;
        }

        if ($from) {
            $query['LastUpdatedAfter'] = gmdate(self::DATE_FORMAT, $from->getTimestamp());
        }
        if ($to) {
            $query['LastUpdatedBefore'] = gmdate(self::DATE_FORMAT, $to->getTimestamp());
        }

        $response = $this->request('ListInboundShipments', $query);
        $res = isset($response['ListInboundShipmentsResult']['ShipmentData']['member']) && $response['ListInboundShipmentsResult']['ShipmentData']['member'] ? $response['ListInboundShipmentsResult']['ShipmentData']['member'] : [];
        $list = $res ? (isset($res[0]) ? $res : [$res]) : [];
        $token = isset($response['ListInboundShipmentsResult']['NextToken']) && $response['ListInboundShipmentsResult']['NextToken'] ? $response['ListInboundShipmentsResult']['NextToken'] : '';
        return count($list) > 49 && $token ? ['list' => $list, 'token' => $token] : $list;
    }

    /**
     * Get FBA shipment info by NextToken
     */
    public function ListInboundShipmentsByNextToken($NextToken) {
        $query = [
            'NextToken' => $NextToken,
        ];

        $response = $this->request('ListInboundShipmentsByNextToken', $query);
        $list = isset($response['ListInboundShipmentsByNextTokenResult']['ShipmentData']['member']) && $response['ListInboundShipmentsByNextTokenResult']['ShipmentData']['member'] ? $response['ListInboundShipmentsByNextTokenResult']['ShipmentData']['member'] : [];
        $token = isset($response['ListInboundShipmentsByNextTokenResult']['NextToken']) && $response['ListInboundShipmentsByNextTokenResult']['NextToken'] ? $response['ListInboundShipmentsByNextTokenResult']['NextToken'] : '';
        return count($list) > 49 && $token ? ['list' => $list, 'token' => $token] : $list;
    }

    /**
     * Get SKU info of FBA shipment 
     */
    public function ListInboundShipmentItems($ShipmentId = '', DateTime $from = null, DateTime $to = null) {
        if ($ShipmentId) {
            $query = [
                "ShipmentId" => $ShipmentId
            ];
        } else {
            $query = [
                'LastUpdatedAfter' => gmdate(self::DATE_FORMAT, $from->getTimestamp()),
                'LastUpdatedBefore' => gmdate(self::DATE_FORMAT, $to->getTimestamp())
            ];
        }
        $response = $this->request("ListInboundShipmentItems", $query);
        if (isset($response['ListInboundShipmentItemsResult']['ItemData']['member'])) {
            $res = $response['ListInboundShipmentItemsResult']['ItemData']['member'];
            return isset($res[0]) ? $res : [$res];
        } else {
            return [];
        }
    }

    /**
     * Returns current transportation information about an inbound shipment
     */
    public function GetTransportContent($ShipmentId = '') {
        if (!$ShipmentId) {
            return [];
        }

        $query = ['ShipmentId' => $ShipmentId];
        $response = $this->request("GetTransportContent", $query);
        if (isset($response['GetTransportContentResult']['TransportContent']) && $response['GetTransportContentResult']['TransportContent']) {
            return $response['GetTransportContentResult']['TransportContent'];
        } else {
            return [];
        }
    }

    /**
     * Returns the parent product categories that a product belongs to, based on SellerSKU
     * @param string $SellerSKU
     * @return array if found, false if not found
     */
    public function GetProductCategoriesForSKU($SellerSKU) {
        $result = $this->request('GetProductCategoriesForSKU', [
            'MarketplaceId' => $this->config['Marketplace_Id'],
            'SellerSKU' => $SellerSKU
        ]);

        if (isset($result['GetProductCategoriesForSKUResult']['Self'])) {
            return $result['GetProductCategoriesForSKUResult']['Self'];
        } else {
            return false;
        }
    }

    /**
     * Returns the parent product categories that a product belongs to, based on ASIN
     * @param string $ASIN
     * @return array if found, false if not found
     */
    public function GetProductCategoriesForASIN($ASIN) {
        $result = $this->request('GetProductCategoriesForASIN', [
            'MarketplaceId' => $this->config['Marketplace_Id'],
            'ASIN' => $ASIN
        ]);

        if (isset($result['GetProductCategoriesForASINResult']['Self'])) {
            return $result['GetProductCategoriesForASINResult']['Self'];
        } else {
            return false;
        }
    }

    /**
     * Returns a list of products and their attributes, based on a list of ASIN, GCID, SellerSKU, UPC, EAN, ISBN, and JAN values.
     * @param array $asin_array A list of id's
     * @param string [$type = 'ASIN']  the identifier name
     * @return array
     */
    public function GetMatchingProductForId(array $asin_array, $type = 'ASIN') {
        $asin_array = array_unique($asin_array);

        if (count($asin_array) > 5) {
            throw new Exception('Maximum number of id\'s = 5');
        }

        $counter = 1;
        $array = [
            'MarketplaceId' => $this->config['Marketplace_Id'],
            'IdType' => $type
        ];

        foreach ($asin_array as $asin) {
            $array['IdList.Id.' . $counter] = $asin;
            $counter++;
        }

        $response = $this->request('GetMatchingProductForId', $array, null, true);

        $languages = [
            'de-DE', 'en-EN', 'es-ES', 'fr-FR', 'it-IT', 'en-US'
        ];

        $replace = [
            '</ns2:ItemAttributes>' => '</ItemAttributes>'
        ];

        foreach ($languages as $language) {
            $replace['<ns2:ItemAttributes xml:lang="' . $language . '">'] = '<ItemAttributes><Language>' . $language . '</Language>';
        }

        $replace['ns2:'] = '';

        $response = $this->xmlToArray(strtr($response, $replace));

        if (isset($response['GetMatchingProductForIdResult']['@attributes'])) {
            $response['GetMatchingProductForIdResult'] = [
                0 => $response['GetMatchingProductForIdResult']
            ];
        }

        $found = [];
        $not_found = [];

        if (isset($response['GetMatchingProductForIdResult']) && is_array($response['GetMatchingProductForIdResult'])) {
            foreach ($response['GetMatchingProductForIdResult'] as $result) {

                $asin = $result['@attributes']['Id'];
                if ($result['@attributes']['status'] != 'Success') {
                    $not_found[] = $asin;
                } else {
                    if (isset($result['Products']['Product']['AttributeSets'])) {
                        $products[0] = $result['Products']['Product'];
                    } else {
                        $products = $result['Products']['Product'];
                    }
                    foreach ($products as $product) {
                        $array = [];
                        if (isset($product['Identifiers']['MarketplaceASIN']['ASIN'])) {
                            $array["ASIN"] = $product['Identifiers']['MarketplaceASIN']['ASIN'];
                        }

                        foreach ($product['AttributeSets']['ItemAttributes'] as $key => $value) {
                            if (is_string($key) && is_string($value)) {
                                $array[$key] = $value;
                            }
                        }

                        if (isset($product['AttributeSets']['ItemAttributes']['Feature'])) {
                            $array['Feature'] = $product['AttributeSets']['ItemAttributes']['Feature'];
                        }

                        if (isset($product['AttributeSets']['ItemAttributes']['PackageDimensions'])) {
                            $array['PackageDimensions'] = array_map(
                                    'floatval', $product['AttributeSets']['ItemAttributes']['PackageDimensions']
                            );
                        }

                        if (isset($product['AttributeSets']['ItemAttributes']['ListPrice'])) {
                            $array['ListPrice'] = $product['AttributeSets']['ItemAttributes']['ListPrice'];
                        }

                        if (isset($product['AttributeSets']['ItemAttributes']['SmallImage'])) {
                            $image = $product['AttributeSets']['ItemAttributes']['SmallImage']['URL'];
                            $array['medium_image'] = $image;
                            $array['small_image'] = str_replace('._SL75_', '._SL50_', $image);
                            $array['large_image'] = str_replace('._SL75_', '', $image);
                            ;
                        }
                        if (isset($product['Relationships']['VariationParent']['Identifiers']['MarketplaceASIN']['ASIN'])) {
                            $array['Parentage'] = 'child';
                            $array['Relationships'] = $product['Relationships']['VariationParent']['Identifiers']['MarketplaceASIN']['ASIN'];
                        }
                        if (isset($product['Relationships']['VariationChild'])) {
                            $array['Parentage'] = 'parent';
                        }
                        if (isset($product['SalesRankings']['SalesRank'])) {
                            $array['SalesRank'] = $product['SalesRankings']['SalesRank'];
                        }
                        $found[$asin][] = $array;
                    }
                }
            }
        }

        return [
            'found' => $found,
            'not_found' => $not_found
        ];
    }

    /**
     * Returns a list of products and their attributes, ordered by relevancy, based on a search query that you specify.
     * @param string $query the open text query
     * @param string [$query_context_id = null] the identifier for the context within which the given search will be performed. see: http://docs.developer.amazonservices.com/en_US/products/Products_QueryContextIDs.html
     * @return array
     */
    public function ListMatchingProducts($query, $query_context_id = null) {

        if (trim($query) == "") {
            throw new Exception('Missing query');
        }

        $array = [
            'MarketplaceId' => $this->config['Marketplace_Id'],
            'Query' => urlencode($query),
            'QueryContextId' => $query_context_id
        ];

        $response = $this->request('ListMatchingProducts', $array, null, true);

        $languages = [
            'de-DE', 'en-EN', 'es-ES', 'fr-FR', 'it-IT', 'en-US'
        ];

        $replace = [
            '</ns2:ItemAttributes>' => '</ItemAttributes>'
        ];

        foreach ($languages as $language) {
            $replace['<ns2:ItemAttributes xml:lang="' . $language . '">'] = '<ItemAttributes><Language>' . $language . '</Language>';
        }

        $replace['ns2:'] = '';

        $response = $this->xmlToArray(strtr($response, $replace));

        if (isset($response['ListMatchingProductsResult'])) {
            return $response['ListMatchingProductsResult'];
        } else
            return ['ListMatchingProductsResult' => []];
    }

    /**
     * Returns a list of reports that were created in the previous 90 days
     * @param array [$ReportTypeList = []]
     * @return array
     */
    public function GetReportList($ReportTypeList = []) {
        $array = [];
        $counter = 1;
        if (count($ReportTypeList)) {
            foreach ($ReportTypeList as $ReportType) {
                $array['ReportTypeList.Type.' . $counter] = $ReportType;
                $counter++;
            }
        }

        return $this->request('GetReportList', $array);
    }

    /**
     * Returns your active recommendations for a specific category or for all categories for a specific marketplace
     * @param string [$RecommendationCategory = null] One of: Inventory, Selection, Pricing, Fulfillment, ListingQuality, GlobalSelling, Advertising
     * @return array/false if no result
     */
    public function ListRecommendations($RecommendationCategory = null) {
        $query = [
            'MarketplaceId' => $this->config['Marketplace_Id']
        ];

        if (!is_null($RecommendationCategory)) {
            $query['RecommendationCategory'] = $RecommendationCategory;
        }

        $result = $this->request('ListRecommendations', $query);

        if (isset($result['ListRecommendationsResult'])) {
            return $result['ListRecommendationsResult'];
        } else {
            return false;
        }
    }

    /**
     * Returns a list of marketplaces that the seller submitting the request can sell in, and a list of participations that include seller-specific information in that marketplace
     * @return array
     */
    public function ListMarketplaceParticipations() {
        $result = $this->request('ListMarketplaceParticipations');
        if (isset($result['ListMarketplaceParticipationsResult'])) {
            return $result['ListMarketplaceParticipationsResult'];
        } else {
            return $result;
        }
    }

    /**
     * Update a SKU price info
     * @param array $StandardPrice an array containing sku as key and price as value
     * @param array $SalePrice an optional array with sku as key and value consisting of an array with key/value pairs for SalePrice, StartDate, EndDate
     * Dates in DateTime object
     * Price has to be formatted as XSD Numeric Data Type (http://www.w3schools.com/xml/schema_dtypes_numeric.asp)
     * @return array feed submission result
     */
    public function UpdateSkuPrice(array $StandardPrice, array $SalePrice = null) {

        $feed = [
            'MessageType' => 'Price',
            'Message' => []
        ];

        foreach ($StandardPrice as $sku => $price) {
            $feed['Message'][] = [
                'MessageID' => rand(),
                'Price' => [
                    'SKU' => $sku,
                    'StandardPrice' => [
                        '_value' => strval($price),
                        '_attributes' => [
                            'currency' => 'DEFAULT'
                        ]
                    ]
                ]
            ];

            if (isset($SalePrice[$sku]) && is_array($SalePrice[$sku])) {
                $feed['Message'][count($feed['Message']) - 1]['Price']['Sale'] = [
                    'StartDate' => $SalePrice[$sku]['StartDate']->format(self::DATE_FORMAT),
                    'EndDate' => $SalePrice[$sku]['EndDate']->format(self::DATE_FORMAT),
                    'SalePrice' => [
                        '_value' => strval($SalePrice[$sku]['SalePrice']),
                        '_attributes' => [
                            'currency' => 'DEFAULT'
                        ]
                    ]
                ];
            }
        }

        return $this->SubmitFeed('_POST_PRODUCT_PRICING_DATA_', $feed);
    }

    /**
     * Post to create a Multi-Channel order (_POST_FLAT_FILE_FULFILLMENT_ORDER_REQUEST_DATA_)
     * @param array $OrderItems OrderItems
     * @return array
     */
    public function CreateMcOrder($OrderItems = [], array $AppendHeader = []) {

        if (!is_array($OrderItems) || !$OrderItems) {
            throw new Exception('Wrong multi-channel order creation parameters');
        }

        $csv = Writer::createFromFileObject(new SplTempFileObject());
        $csv->setDelimiter("\t");
        $csv->setInputEncoding('iso-8859-1');

        $header = ['MerchantFulfillmentOrderID', 'DisplayableOrderID', 'DisplayableOrderDate', 'MerchantSKU', 'Quantity', 'MerchantFulfillmentOrderItemID', 'DisplayableOrderComment',
            'DeliverySLA', 'AddressName', 'AddressFieldOne', 'AddressCity', 'AddressCountryCode', 'AddressStateOrRegion', 'AddressPostalCode'];

        $AppendHeader && $header = array_merge($header, $AppendHeader);

        $csv->insertOne($header);
        foreach ($OrderItems as $item) {
            $csv->insertOne(array_values($item));
        }

        return $this->SubmitFeed('_POST_FLAT_FILE_FULFILLMENT_ORDER_REQUEST_DATA_', $csv);
    }

    /**
     * Mc order shipping confirm
     */
    public function McOrderShippingConfirm($ShippingData = []) {
        if (!$ShippingData) {
            throw new Exception('Data of order fulfillment is empty');
        }

        $csv = Writer::createFromFileObject(new SplTempFileObject());
        $csv->setDelimiter("\t");
        $csv->setInputEncoding('iso-8859-1');

        $header = ['order-id', 'order-item-id', 'quantity', 'ship-date', 'carrier-code', 'carrier-name', 'tracking-number', 'ship-method'];

        $csv->insertOne($header);
        foreach ($ShippingData as $data) {
            $csv->insertOne(array_values($data));
        }

        return $this->SubmitFeed('_POST_FLAT_FILE_FULFILLMENT_DATA_', $csv);
    }

    /**
     * Returns the feed processing report and the Content-MD5 header
     * @param string $FeedSubmissionId
     * @return array
     */
    public function GetFeedSubmissionResult($FeedSubmissionId) {
        $result = $this->request('GetFeedSubmissionResult', ['FeedSubmissionId' => $FeedSubmissionId]);

        if (isset($result['Message']['ProcessingReport'])) {
            return $result['Message']['ProcessingReport'];
        } else {
            return $result;
        }
    }

    /**
     * Uploads a feed for processing by Amazon MWS
     * @param string $FeedType (http://docs.developer.amazonservices.com/en_US/feeds/Feeds_FeedType.html)
     * @param mixed $feedContent Array will be converted to xml using https://github.com/spatie/array-to-xml. Strings will not be modified.
     * @param boolean $debug Return the generated xml and don't send it to amazon
     * @return array
     */
    public function SubmitFeed($FeedType, $FeedContent, $debug = false, $options = []) {
        if (is_array($feedContent)) {
            $FeedContent = $this->arrayToXml(array_merge(['Header' => ['DocumentVersion' => 1.01, 'MerchantIdentifier' => $this->config['Seller_Id']]], $FeedContent));
        }

        if ($debug === true) {
            return $FeedContent;
        } else if ($this->debugNextFeed == true) {
            $this->debugNextFeed = false;
            return $FeedContent;
        }

        $purgeAndReplace = isset($options['PurgeAndReplace']) ? $options['PurgeAndReplace'] : false;

        $query = [
            'FeedType' => $FeedType,
            'PurgeAndReplace' => ($purgeAndReplace ? 'true' : 'false'),
            'Merchant' => $this->config['Seller_Id']
        ];

        $response = $this->request('SubmitFeed', $query, $FeedContent);
        return $response['SubmitFeedResult']['FeedSubmissionInfo'];
    }

    /**
     * Convert an array to xml
     * @param $array array to convert
     * @param $customRoot [$customRoot = 'AmazonEnvelope']
     * @return sting
     */
    private function arrayToXml(array $array, $customRoot = 'AmazonEnvelope') {
        return ArrayToXml::convert($array, $customRoot);
    }

    /**
     * Convert an xml string to an array
     * @param string $xmlstring
     * @return array
     */
    private function xmlToArray($xmlstring) {
        return json_decode(json_encode(simplexml_load_string($xmlstring)), true);
    }

    /**
     * Creates a report request and submits the request to Amazon MWS.
     * @param string $report (http://docs.developer.amazonservices.com/en_US/reports/Reports_ReportType.html)
     * @param DateTime [$StartDate = null]
     * @param EndDate [$EndDate = null]
     * @return string ReportRequestId
     */
    public function RequestReport($report, $StartDate = null, $EndDate = null) {
        $query = [
            'MarketplaceIdList.Id.1' => $this->config['Marketplace_Id'],
            'ReportType' => $report
        ];

        if (!is_null($StartDate)) {
            if (!is_a($StartDate, 'DateTime')) {
                throw new Exception('StartDate should be a DateTime object');
            } else {
                $query['StartDate'] = gmdate(self::DATE_FORMAT, $StartDate->getTimestamp());
            }
        }

        if (!is_null($EndDate)) {
            if (!is_a($EndDate, 'DateTime')) {
                throw new Exception('EndDate should be a DateTime object');
            } else {
                $query['EndDate'] = gmdate(self::DATE_FORMAT, $EndDate->getTimestamp());
            }
        }

        $result = $this->request(
                'RequestReport', $query
        );

        if (isset($result['RequestReportResult']['ReportRequestInfo']['ReportRequestId'])) {
            return $result['RequestReportResult']['ReportRequestInfo']['ReportRequestId'];
        } else {
            throw new Exception('Error trying to request report');
        }
    }

    /**
     * Get a report's content
     * @param string $ReportId
     * @return array on succes
     */
    public function GetReport($ReportId = 0) {
        $status = $this->GetReportRequestStatus($ReportId);

        if ($status !== false && $status['ReportProcessingStatus'] === '_DONE_NO_DATA_') {
            return [];
        } else if ($status !== false && $status['ReportProcessingStatus'] === '_DONE_') {
            $result = $this->request('GetReport', ['ReportId' => $status['GeneratedReportId']]);

            if (is_string($result)) {
                $csv = Reader::createFromString($result);
                $csv->setDelimiter("\t");
                $headers = $csv->fetchOne();
                $result = [];
                foreach ($csv->setOffset(1)->fetchAll() as $row) {
                    if (!$row) {
                        continue;
                    }
                    $t = [];
                    foreach ($headers as $k => $h) {
                        $t[$h] = isset($row[$k]) ? $row[$k] : '';
                    }
                    $result[] = $t;
                }
            }

            return $result;
        } else {
            return false;
        }
    }

    /**
     * Get a report's processing status
     * @param string  $ReportId
     * @return array if the report is found
     */
    public function GetReportRequestStatus($ReportId) {
        $result = $this->request('GetReportRequestList', [
            'ReportRequestIdList.Id.1' => $ReportId
        ]);

        if (isset($result['GetReportRequestListResult']['ReportRequestInfo'])) {
            return $result['GetReportRequestListResult']['ReportRequestInfo'];
        }

        return false;
    }

    /**
     * Get a list's inventory for Amazon's fulfillment
     *
     * @param array $sku_array
     *
     * @return array
     * @throws Exception
     */
    public function ListInventorySupply($sku_array = []) {

        if (count($sku_array) > 50) {
            throw new Exception('Maximum amount of SKU\'s for this call is 50');
        }

        $counter = 1;
        $query = [
            'MarketplaceId' => $this->config['Marketplace_Id'],
            'ResponseGroup' => 'Detailed',
        ];

        foreach ($sku_array as $key) {
            $query['SellerSkus.member.' . $counter] = $key;
            $counter++;
        }

        $response = $this->request('ListInventorySupply', $query);

        $result = [];
        if (isset($response['ListInventorySupplyResult']['InventorySupplyList']['member'])) {
            foreach ($response['ListInventorySupplyResult']['InventorySupplyList']['member'] as $index => $ListInventorySupplyResult) {
                $result[$index] = $ListInventorySupplyResult;
            }
        }

        return $result;
    }

    /**
     * Request MWS
     */
    private function request($endPoint, array $query = [], $body = null, $raw = false) {
        $endPoint = MWSEndPoint::get($endPoint);

        $merge = [
            'Timestamp' => gmdate(self::DATE_FORMAT, time()),
            'AWSAccessKeyId' => $this->config['Access_Key_ID'],
            'Action' => $endPoint['action'],
            'MarketplaceId.Id.1' => $this->config['Marketplace_Id'],
            'SellerId' => $this->config['Seller_Id'],
            'MWSAuthToken' => $this->config['MWSAuthToken'],
            'SignatureMethod' => self::SIGNATURE_METHOD,
            'SignatureVersion' => self::SIGNATURE_VERSION,
            'Version' => $endPoint['date']
        ];

        $query = array_merge($merge, $query);

        if (isset($query['MarketplaceId'])) {
            unset($query['MarketplaceId.Id.1']);
        }

        try {
            $headers = [
                'Accept' => 'application/xml',
                'x-amazon-user-agent' => $this->config['Application_Name'] . '/' . $this->config['Application_Version']
            ];

            if ($endPoint['action'] === 'SubmitFeed') {
                $headers['Content-MD5'] = base64_encode(md5($body, true));
                $headers['Content-Type'] = 'text/xml; charset=iso-8859-1';
                $headers['Host'] = $this->config['Region_Host'];
                unset($query['MarketplaceId.Id.1'], $query['SellerId']);
            }

            $requestOptions = [
                'headers' => $headers,
                'body' => $body
            ];

            ksort($query);

            $hmacParamString = $endPoint['method']
                    . "\n"
                    . $this->config['Region_Host']
                    . "\n"
                    . $endPoint['path']
                    . "\n"
                    . http_build_query($query, null, '&', PHP_QUERY_RFC3986);
            $query['Signature'] = base64_encode(hash_hmac('sha256', $hmacParamString, $this->config['Secret_Access_Key'], true));

            $requestOptions['query'] = $query;

            if ($this->client === NULL) {
                $this->client = new Client();
            }

            $response = $this->client->request($endPoint['method'], $this->config['Region_Url'] . $endPoint['path'], $requestOptions);

            $body = (string) $response->getBody();

            if ($raw) {
                return $body;
            } else if (strpos(strtolower($response->getHeader('Content-Type')[0]), 'xml') !== false) {
                return $this->xmlToArray($body);
            } else {
                return $body;
            }
        } catch (BadResponseException $e) {
            if ($e->hasResponse()) {
                $message = $e->getResponse();
                $message = $message->getBody();
                if (strpos($message, '<ErrorResponse') !== false) {
                    $error = simplexml_load_string($message);
                    $message = $error->Error->Message;
                }
            } else {
                $message = 'An error occured';
            }
            throw new Exception($message);
        }
    }

    public function setClient(Client $client) {
        $this->client = $client;
    }

}
