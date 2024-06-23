<?php

/**
 * Copyright 2022-2024 FOSSBilling
 * Copyright DeVeLab
 * SPDX-License-Identifier: Apache-2.0
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

if (file_exists(__DIR__.'/vendor/autoload.php')) {
    require __DIR__."/vendor/autoload.php";
} else {
    die("Autoload not found");
}

use BTCPayServer\Client\Invoice;
use BTCPayServer\Client\InvoiceCheckoutOptions;
use BTCPayServer\Util\PreciseNumber;
use BTCPayServer\Exception\BTCPayException;
use BTCPayServer\Client\Webhook;

class Payment_Adapter_BTCPay implements FOSSBilling\InjectionAwareInterface
{
    protected ?Pimple\Container $di = null;
    private Invoice $btcpay;
    private $config = [];

    /**
     * @param  Pimple\Container  $di
     * @return void
     */
    public function setDi(Pimple\Container $di): void
    {
        $this->di = $di;
    }

    /**
     * @return \Pimple\Container|null
     */
    public function getDi(): ?Pimple\Container
    {
        return $this->di;
    }

    /**
     * @param $config
     * @throws Payment_Exception
     */
    public function __construct($config)
    {
        $this->config = $config;
        foreach (['host_url', 'api_key', 'store_id', 'ipn_secret', 'payment_method'] as $key) {
            if (!isset($this->config[$key])) {
                throw new \Payment_Exception ('The ":pay_gateway" payment gateway is not fully configured. Please configure the :missing', [':pay_gateway' => 'BTCPay', ':missing' => $key], 4001);
            }
        }
        $this->btcpay = new Invoice($this->config['host_url'], $this->config['api_key']);
    }

    /**
     * @return array
     */
    public static function getConfig(): array
    {
        return [
            'supports_one_time_payments' => true,
            'description'                => 'BTCPay Payment Gateway',
            'logo'                       => [
                'logo'   => '/BTCPay/btcpay.png',
                'height' => '40px',
                'width'  => '65px',
            ],
            'form'                       => [
                'host_url'       => [
                    'text',
                    [
                        'label' => 'Host url :',
                    ],
                ],
                'api_key'        => [
                    'text',
                    [
                        'label' => 'Api key :',
                    ],
                ],
                'store_id'       => [
                    'text',
                    [
                        'label' => 'Store id :',
                    ],
                ],
                'ipn_secret'     => [
                    'text',
                    [
                        'label' => 'IPN webhook secret key :',
                    ],
                ],
                'tax_included'   => [
                    'text',
                    [
                        'label' => 'Tax included :',
                    ],
                ],
                'policy_speed'   => [
                    'select',
                    [
                        'multiOptions' => [
                            "SPEED_HIGH"      => "High Speed",
                            "SPEED_MEDIUM"    => "Medium Speed",
                            "SPEED_LOW"       => "Low Speed",
                            "SPEED_LOWMEDIUM" => "Low Medium Speed"
                        ],
                        'label'        => 'Policy Speed :',
                    ]
                ],
                'payment_method' => [
                    'select',
                    [
                        'multiOptions' => [
                            "BTC"          => "BTC",
                            "LTC"          => "LTC",
                            "DASH"         => "DASH",
                            "BTC,LTC"      => "BTC-LTC",
                            "BTC,DASH"     => "BTC-DASH",
                            "LTC,DASH"     => "LTC-DASH",
                            "BTC,LTC,DASH" => "BTC-LTC-DASH",
                        ],
                        'label'        => 'Payment method :',
                    ],
                ]
            ],
        ];
    }

    /**
     * @param $api_admin
     * @param $invoice_id
     * @param $subscription
     * @return string
     * @throws JsonException
     */
    public function getHtml($api_admin, $invoice_id, $subscription): string
    {
        $invoiceModel = $this->di['db']->load('Invoice', $invoice_id);

        return $this->_generateForm($invoiceModel);
    }

    /**
     * @param  Model_Invoice  $invoice
     * @return float|int
     */
    public function getAmountInCents(Model_Invoice $invoice): float|int
    {
        $invoiceService = $this->di['mod_service']('Invoice');

        return $invoiceService->getTotalWithTax($invoice) * 100;
    }

    /**
     * @param  Model_Invoice  $invoice
     * @return string
     */
    public function getInvoiceTitle(Model_Invoice $invoice): string
    {
        $invoiceItems = $this->di['db']->getAll('SELECT title from invoice_item WHERE invoice_id = :invoice_id', [':invoice_id' => $invoice->id]);

        $params = [
            ':id'    => sprintf('%05s', $invoice->nr),
            ':serie' => $invoice->serie,
            ':title' => $invoiceItems[0]['title'],
        ];
        $title = __trans('Payment for invoice :serie:id [:title]', $params);
        if ((is_countable($invoiceItems) ? count($invoiceItems) : 0) > 1) {
            $title = __trans('Payment for invoice :serie:id', $params);
        }

        return $title;
    }

    /**
     * @param $api_admin
     * @param $id
     * @param $data
     * @param $gateway_id
     * @return false|string
     */
    public function processTransaction($api_admin, $id, $data, $gateway_id): string
    {
        $payload = $data['http_raw_post_data'];
        $isValid = Webhook::isIncomingWebhookRequestValid($payload, $data['server']['HTTP_BTCPAY_SIG'], $this->config['ipn_secret']);
        if ($isValid) {
            $payloadData = json_decode($payload);
            $transaction = $this->di['db']->findOne("Transaction", "txn_id = :txn_id", [":txn_id" => $payloadData->invoiceId]);
            if ($transaction->txn_status == $payloadData->type) {
                return json_encode([
                    "code"    => "200",
                    "message" => "ok",
                ]);
            }
            $this->di['logger']->setChannel('event')->debug(sprintf("Transaction Event Type : '%s'", $payloadData->type));
            switch ($payloadData->type) {
                //case "InvoiceSettled" :
                case "InvoicePaymentSettled":
                {
                    // Instance the services we need
                    $clientService = $this->di['mod_service']('client');
                    $invoiceService = $this->di['mod_service']('Invoice');
                    // Get Invoice by transaction txn_id

                    $invoice = $this->di['db']->getExistingModelById('Invoice', $transaction->invoice_id);

                    // Update the account funds
                    $client = $this->di['db']->getExistingModelById('Client', $invoice->client_id);
                    $clientService->addFunds($client, $invoiceService->getTotalWithTax($invoice), "BTCPay transaction {$payloadData->invoiceId}", [
                        'amount'      => $invoiceService->getTotalWithTax($invoice),
                        'description' => 'Stripe transaction '.$payloadData->invoiceId,
                        'type'        => 'transaction',
                        'rel_id'      => $id,
                    ]);
                    $invoiceService->payInvoiceWithCredits($invoice);

                    $transaction->txn_status = $payloadData->type;
                    $transaction->status = "succeeded";
                    $transaction->ipn = $payload;
                    $transaction->updated_at = date('Y-m-d H:i:s');
                    $this->di['db']->store($transaction);
                    break;
                }
                case "InvoiceExpired" :
                {
                    $transaction->txn_status = $payloadData->type;
                    $transaction->status = "expired";
                    $transaction->ipn = $payload;
                    $transaction->updated_at = date('Y-m-d H:i:s');
                    $this->di['db']->store($transaction);
                    break;
                }
                default :
                    $this->di['logger']->setChannel('event')->debug(sprintf("Unknown BTCPay transaction, transaction id : '%s' ".$payloadData->invoiceId));
                    break;
            }
        } else {
            $this->di['logger']->setChannel('event')->debug(sprintf('[BTCPay] validation has failed. HTTP_BTCPAY_SIG : "%s" IPN Secret : "%s" ', $data['server']['HTTP_BTCPAY_SIG'], $this->config['ipn_secret']));
        }
        return json_encode([
            "code"    => "200",
            "message" => "ok",
        ]);
    }

    /**
     * @param  Model_Invoice  $invoice
     * @return string
     * @throws JsonException
     */
    protected function _generateForm(Model_Invoice $invoice): string
    {
        // Verify that the invoice is created and redirect
        $this->getInvoiceBTCPay($invoice);

        try {
            // Setup custom metadata. This will be visible in the invoice and can include
            // arbitrary data. Example below will show up on the invoice details page on
            // BTCPay Server.
            $metaData = [
                'buyerName'     => "{$invoice->buyer_first_name} {$invoice->buyer_last_name}",
                'buyerAddress1' => $invoice->buyer_address,
                'buyerAddress2' => '',
                'buyerCity'     => $invoice->buyer_city,
                'buyerState'    => $invoice->buyer_state,
                'buyerZip'      => $invoice->buyer_zip,
                'buyerCountry'  => $invoice->buyer_country,
                'buyerPhone'    => $invoice->buyer_phone,
                'posData'       => '',
                'itemDesc'      => $this->getInvoiceTitle($invoice),
                'itemCode'      => '',
                'physical'      => false,
                'taxIncluded'   => (float) $this->config['tax_included'] ?? 6.15,
                // tax amount (included in the total amount).
            ];
            $invoiceService = $this->di['mod_service']('Invoice');
            // Setup custom checkout options, defaults get picked from store config.
            $checkoutOptions = new InvoiceCheckoutOptions();
            $checkoutOptions
                ->setSpeedPolicy(constant(get_class($checkoutOptions).'::'.$this->config['policy_speed'] ?? "SPEED_HIGH"))
                ->setPaymentMethods(explode(",", $this->config['payment_method']))
                ->setRedirectURL($this->di['tools']->url('invoice/'.$invoice->hash));
            $request = $this->btcpay->createInvoice(
                $this->config['store_id'],
                $invoice->currency,
                PreciseNumber::parseFloat($invoiceService->getTotalWithTax($invoice), 2),
                uniqid()."#{$invoice->nr}",
                $invoice->buyer_email,
                $metaData,
                $checkoutOptions
            )->getData();
            /**
             * Store BTCPay invoice to local invoice
             */
            $this->createTransactionTxn($invoice, $request);
            /**
             * Redirect to payment screen
             */
            return '<script type="text/javascript">window.location = "'.$request['checkoutLink'].'";</script>';
        } catch (BTCPayException $e) {
            return "<code>".$e->getMessage()."</code>";
        }
    }

    /**
     * @param  Model_Invoice  $invoice
     * @return bool
     */
    protected function getInvoiceBTCPay(Model_Invoice $invoice): bool
    {
        try {
            $tx = $this->di['db']->getExistingModelById('Transaction', $invoice->id);
            if ($tx->txn_id) {
                /*
                 * Check if invoice exist
                 */
                try {
                    $btcInvoice = $this->btcpay->getInvoice($this->config['store_id'], $tx->txn_id)->getData();
                    if ($btcInvoice['status'] == "New") {
                        //Redirect to payment screen
                        $this->redirect($btcInvoice['checkoutLink']);
                    }
                } catch (BTCPayException $e) {
                }
            }
        } catch (\Exception $e) {
        }
        return false;
    }


    /**
     * @param  Model_Invoice  $invoice
     * @param  array  $request
     * @param  string  $status
     * @return bool
     */
    protected function createTransactionTxn(Model_Invoice $invoice, array $request, string $status = "pending"): bool
    {
        try {
            $invoiceService = $this->di['mod_service']('Invoice');

            $transaction = $this->di['db']->dispense('Transaction');
            $transaction->invoice_id = $invoice->id;
            $transaction->gateway_id = $invoice->gateway_id;
            $transaction->txn_id = $request['id'];
            $transaction->txn_status = $request['status'];
            $transaction->amount = PreciseNumber::parseFloat($invoiceService->getTotalWithTax($invoice));
            $transaction->currency = $invoice->currency;
            $transaction->status = $status;
            $transaction->validate_ipn = 1;
            $transaction->ipn = json_encode($request);
            $transaction->updated_at = date('Y-m-d H:i:s');
            // Store the updated transaction and use its return to indicate a success or failure.
            return $this->di['db']->store($transaction);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * @param  string  $path
     */
    protected function redirect(string $path): never
    {
        header("Location: $path");
        exit;
    }
}
