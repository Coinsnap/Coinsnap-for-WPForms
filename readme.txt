=== Bitcoin payment for WPForms ===
Contributors: coinsnap
Tags: bitcoin, lightning, wpforms, payments, btcpay
Requires at least: 5.5
Tested up to: 7.0.7
Requires PHP: 7.4
Stable tag: 1.5.3
License: GPL2
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept Bitcoin and Lightning payments in WPForms for donations, products, bookings, downloads, services and micropayment workflows.

== Description ==

= Add Bitcoin and Lightning payments to WPForms =

Coinsnap for WPForms adds Bitcoin as a payment option to WPForms Pro.

Turn existing WPForms into lightweight Bitcoin checkout experiences for donations, products, downloads, bookings, registrations, consultations, services and other one-time payments.

Payments can be made with **Bitcoin via Lightning or on-chain** using Coinsnap or your own BTCPay Server.

Coinsnap is **Bitcoin payment software for merchants** and a brand of **Onlineshop24 d.o.o., Serbia**.

= Requirements =

* A self-hosted WordPress website
* **WPForms Pro** installed and active
* PHP 7.4 or newer
* A Coinsnap merchant account or your own BTCPay Server

= Key features =

* Accept **Bitcoin and Lightning payments** in WPForms
* Support for **Lightning and Bitcoin on-chain**
* Add Bitcoin payments to multiple WPForms
* Suitable for donations, products, downloads, bookings, registrations, consultations and service payments
* Works alongside existing WPForms payment methods such as Stripe or PayPal
* Support for **Coinsnap** and **BTCPay Server**
* BTCPay Server setup wizard
* Automatic and custom redirect options after payment
* Optional **Bitcoin discount**
* Suitable for micropayment-based form submissions
* No own Lightning node required when using Coinsnap
* Non-custodial / self-custody payment model
* Optional bank settlement through supported Coinsnap partners
* Open source and free to install

= Typical use cases =

Use Coinsnap for WPForms for:

* donation forms
* fundraiser forms
* physical product orders
* digital downloads
* paid event registrations
* bookings and deposits
* consultations and meetings
* paid support requests
* premium contact forms
* pay-per-use services
* newsletter or community sign-ups with a fee
* micropayment-based anti-spam forms

= How it works =

1. Install and activate WPForms Pro.
2. Install and activate Coinsnap for WPForms.
3. Create or edit a WPForms form.
4. Open the form's **Payments** section.
5. Select **Coinsnap**.
6. Enable Bitcoin payments for the form.
7. Connect Coinsnap or your own BTCPay Server.
8. Configure payment and redirect settings.
9. Save and publish the form.
10. Make a test Bitcoin payment.

= Coinsnap or BTCPay Server =

The add-on supports:

* **Coinsnap** — accept Bitcoin without operating your own Bitcoin or Lightning payment infrastructure.
* **BTCPay Server** — connect your own self-hosted BTCPay Server.

= Bitcoin discount =

The current Coinsnap WPForms product supports an optional **Bitcoin discount**.

A fixed or percentage-based discount can be used to encourage visitors to choose Bitcoin when multiple payment methods are available.

= Micropayments and anti-spam =

Lightning makes very small payments practical.

This allows WPForms to support paid contact requests, premium submissions or small anti-spam fees. A small Lightning payment can create an economic barrier for automated spam while remaining inexpensive for legitimate users.

= Settlement =

With Coinsnap, payments can be settled to a compatible Bitcoin or Lightning wallet.

One option is the [Coinsnap Wallet](https://coinsnap.io/wallet/).

Coinsnap follows a non-custodial / self-custody approach and does not hold merchant Bitcoin.

Depending on the merchant setup and supported partner integrations, Bitcoin payments can also be automatically converted and settled to a bank account.

= Fees =

Coinsnap charges a **1% transaction fee**. The fee is handled separately through the merchant's Coinsnap balance and is not deducted from the Bitcoin payment.

= More information =

* Product page: https://coinsnap.io/wp-add-ons/wpforms/
* User Guide: https://coinsnap.io/user-guides/wpforms-bitcoin-user-guide/
* WordPress Plugin Directory: https://wordpress.org/plugins/coinsnap-for-wpforms/
* Demo: https://wpforms.coinsnap.org/
* GitHub: https://github.com/Coinsnap/Coinsnap-for-WPForms
* Coinsnap Wallet: https://coinsnap.io/wallet/
* Coinsnap API: https://docs.coinsnap.io/
* Coinsnap account: https://app.coinsnap.io/register

== Installation ==

1. Install and activate **WPForms Pro**.
2. In WordPress, go to **Plugins → Add New**.
3. Search for **Bitcoin payment for WPForms**.
4. Install and activate the Coinsnap add-on.
5. Open the WPForms form you want to monetize.
6. Go to **Payments → Coinsnap**.
7. Enable Coinsnap payments.
8. Connect Coinsnap or BTCPay Server.
9. Save the form.
10. Make a test Bitcoin payment.

== Frequently Asked Questions ==

= Can WPForms accept Bitcoin payments? =

Yes. Coinsnap for WPForms adds Bitcoin and Lightning payments directly to WPForms Pro forms.

= Do I need WPForms Pro? =

Yes. The current plugin code requires WPForms Pro to be installed and active.

= Can customers pay with both Lightning and Bitcoin on-chain? =

Yes. The integration supports both Lightning and Bitcoin on-chain.

= Can Bitcoin be offered alongside Stripe or PayPal? =

Yes. Bitcoin can be offered alongside other WPForms payment methods or used as a standalone option.

= Can I use my own BTCPay Server? =

Yes. BTCPay Server is supported as an alternative to Coinsnap.

= Can I use Coinsnap in multiple WPForms? =

Yes. Bitcoin payments can be enabled on multiple forms.

= Does the plugin support a Bitcoin discount? =

Yes. The current Coinsnap product documentation includes an optional fixed or percentage-based Bitcoin discount.

= Can I use the plugin for micropayments? =

Yes. Lightning is suitable for small payments, including low-value form submissions and anti-spam use cases.

= Do I need WooCommerce? =

No. WPForms can be used as a lightweight Bitcoin checkout without a shop system.

= Do I need my own Lightning node? =

No. When using Coinsnap, no own Lightning node or Bitcoin payment server is required.

= Does Coinsnap hold my Bitcoin? =

No. Coinsnap follows a non-custodial / self-custody approach and does not hold merchant Bitcoin.

= Can payments be settled to a bank account? =

Depending on the merchant setup and supported partner integrations, Bitcoin payments can be converted and settled to a bank account.

= What does Coinsnap cost? =

Coinsnap charges a 1% transaction fee.

== Screenshots ==

1. Install Bitcoin payment for WPForms from the WordPress Plugin Directory
2. WPForms Pro form builder
3. Coinsnap payment settings inside WPForms
4. Select Coinsnap or BTCPay Server
5. BTCPay Server setup wizard
6. Enable Bitcoin payments for a form
7. WPForms donation or payment form
8. Bitcoin and Lightning payment QR code
9. Successful WPForms payment

== Changelog ==

= 1.5.3 :: 2026-05-23 =
* Compatibility with WordPress 7.0 is tested.

= 1.5.2 :: 2026-04-07 =
* Updated Coinsnap Invoice client.
* Updated Coinsnap Store client.
* Compatibility with WPForms 1.10.0.2 is tested.
* Compatibility with WordPress 6.9.4 is tested.

= 1.5.1 :: 2026-03-07 =
* Compatibility with WPForms 1.9.9.3 - 1.9.9.4 is tested.
* Updated plugin description.

= 1.5.0 :: 2026-02-16 =
* Compatibility with WordPress 6.9.1 is tested.
* Updated server connection notification system.

= 1.4.5 :: 2026-02-02 =
* Compatibility with WPForms 1.9.9.2 is tested.
* Updated PHP files protection.

= 1.4.4 :: 2025-12-29 =
* Compatibility with WPForms 1.9.8.7 is tested.
* Updated PHP files protection.

= 1.4.3 :: 2025-12-05 =
* Updated Coinsnap Store client data handling.
* Compatibility with WordPress 6.9 is tested.
* Compatibility with WPForms 1.9.8.5 is tested.

= 1.4.2 :: 2025-10-14 =
* Added handling for currencies not supported by BTCPay Server.
* Added custom redirect after payment.
* Fixed BTCPay Server webhook registration and setup-wizard issues.
* Compatibility with WPForms 1.9.8.2 is tested.

= 1.4.1 :: 2025-09-28 =
* Updated registration and webhook handling.
* Compatibility with WPForms 1.9.8.1 is tested.

= 1.4.0 :: 2025-08-18 =
* Added nonce validation to the BTCPay Server setup flow.
* Updated BTCPay Server minimum-payment calculations.
* Updated BTCPay Server events and webhook handling.
* Compatibility with WPForms 1.9.7.3 is tested.

= 1.3.1 :: 2025-07-10 =
* Improved API-key setup.
* Updated Coinsnap WordPress library.
* Updated WPForms Pro dependency check.
* Compatibility with WPForms 1.9.6.2 is tested.

= 1.3.0 :: 2025-06-23 =
* Improved BTCPay Server setup callback handling.
* Added webhook data storage and webhook-secret validation.
* Compatibility with WPForms 1.9.6.1 is tested.

= 1.2.0 :: 2025-05-30 =
* Added BTCPay Server as a Bitcoin payment gateway.
* Added BTCPay Server setup wizard.
* Added minimum-payment validation.

= 1.1.1 :: 2025-04-29 =
* Compatibility with WPForms 1.9.5.1 is tested.
* Compatibility with WordPress 6.8 is tested.

= 1.1.0 :: 2025-04-07 =
* Added automatic redirect after payment.
* Added minimum-payment validation.
* Improved webhook error handling.

= 1.0.5 :: 2025-03-20 =
* Added error logging/display helpers.
* Added handling for webhooks with an empty invoice ID.

= 1.0.4 :: 2025-03-01 =
* Prevented activation when WPForms is not installed and active.
* Improved exception handling.
* Compatibility with WPForms 1.9.4.1 tested.

= 1.0.3 :: 2025-02-09 =
* Added WPForms identification to the Coinsnap server connection status.

= 1.0.2 :: 2024-12-23 =
* Fixed Coinsnap WPForms version constant naming.
* Fixed variable-type mismatches in exception handling.

= 1.0.1 :: 2024-12-10 =
* Fixed ConnectException handling.

= 1.0.0 :: 2024-03-05 =
* First public release for testing.
