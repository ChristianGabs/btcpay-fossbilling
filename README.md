# BTCPay plugin for FOSSBilling

For FOSSBilling versions > 0.6.0

## Integration Requirements

This version requires the following:

* A working and up-to-date FOSSBilling instance
* Running BTCPay instance: [deployment guide](https://docs.btcpayserver.org/Deployment/)

## Installing the Plugin

### Manual installation

1. Download the latest release from the [releases](https://github.com/ChristianGabs/btcpay-fossbilling/releases/tag/0.1.0)
2. Create a new folder named `BTCPay` in the `/library/Payment/Adapter` directory of your FOSSBilling installation
3. Extract the archive you've downloaded in the first step into the new directory
4. Go to the "Payment gateways" page in your admin panel (under the "System" menu in the navigation bar) and find BTCPay in the "New payment gateway" tab
5. Click the cog icon next to BTCPay to install and configure BTCPay

## Plugin Configuration

After you have enabled the BTCPay plugin, the configuration steps are:

1. Enter your Host URL (for example, https://pay.example.com) without slashes.
2. Enter your API Key [Account > Manager Account > Api Keys] Permissions : [btcpay.store.canviewinvoices, btcpay.store.cancreateinvoice]
3. Enter your Store id  (Settings > General > Store Id)
4. Enter your IPN Webhook Secret Key  (Settings > Webhook > Create Webhook) [Events : A payment has been settled, An invoice has expired, An invoice has been settled, An invoice became invalid] 
5. Tax Included in meta 

## License
This FOSSBilling BTCPay Payment Gateway Integration module is open-source software licensed under the [Apache License 2.0](LICENSE).

> *Note*: This module is not officially affiliated with [FOSSBilling](https://fossbilling.org) or [BTCPay](https://btcpayserver.org/). Please refer to their respective documentation for detailed information on FOSSBilling and BTCPayServer.

