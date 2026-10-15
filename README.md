# Coinsnap for WPForms

![Coinsnap for WPForms Add-on](https://resources.coinsnap.org/products/wpforms/images/banner-772x250.png)

**Bitcoin payment add-on for WPForms — accept Bitcoin and Lightning in forms for products, donations, bookings, registrations, downloads, services and micropayment workflows.**

Coinsnap for WPForms adds Bitcoin as a payment option to WPForms Pro.

Website owners can turn existing WPForms into lightweight Bitcoin checkout experiences for donations, digital products, services, bookings, event registrations, support requests, consultations and other one-time payments.

Payments can be made with **Bitcoin via Lightning or on-chain** using **Coinsnap** or your own **BTCPay Server**.

Coinsnap is **Bitcoin payment software for merchants** and a brand of **Onlineshop24 d.o.o., Serbia**.

## Key features

- Accept **Bitcoin and Lightning payments** in WPForms
- Support for **Lightning and Bitcoin on-chain**
- Add Bitcoin payments to multiple WPForms
- Suitable for donations, products, downloads, bookings, registrations, consultations and service payments
- Works alongside existing WPForms payment methods such as Stripe or PayPal
- Support for **Coinsnap** and **BTCPay Server**
- BTCPay Server setup wizard
- Optional automatic redirect after payment
- Custom redirect after payment
- Optional **Bitcoin discount** to encourage Bitcoin payments
- Useful for low-value payments and micropayment-based form submissions
- No own Lightning node required when using Coinsnap
- **Non-custodial / self-custody** payment model
- Optional settlement to a bank account through supported Coinsnap partners
- Open source and free to install

## Requirements

Coinsnap for WPForms requires:

- A self-hosted WordPress website
- **WPForms Pro** installed and activated
- PHP **7.4 or newer**
- A Coinsnap merchant account or your own BTCPay Server

The current public WordPress.org release is **1.5.3** and is tested up to **WordPress 7.0.7**.

> **Developer note:** the current GitHub `main` branch still identifies the plugin as version **1.5.2**, tested with WordPress 6.9 and WPForms 1.10.0.2. The repository code metadata and public WordPress.org release should be synchronized separately.

## Typical use cases

Coinsnap for WPForms can be used for:

- donation forms
- fundraiser forms
- physical product orders
- digital downloads
- paid event registrations
- bookings and deposits
- consultations and meetings
- paid support requests
- premium contact forms
- pay-per-use services
- newsletter or community sign-ups with a fee
- micropayment-based anti-spam forms

## How it works

1. Install and activate **WPForms Pro**.
2. Install and activate **Coinsnap for WPForms**.
3. Create or edit a WPForms form.
4. Open the form's **Payments** section.
5. Select **Coinsnap**.
6. Enable Bitcoin payments for that form.
7. Connect Coinsnap or your own BTCPay Server.
8. Configure the payment and redirect settings.
9. Publish the form.
10. Make a test Bitcoin payment before going live.

## Coinsnap or BTCPay Server

The add-on supports two payment-provider modes:

- **Coinsnap** — for website owners who want to accept Bitcoin without operating their own Bitcoin or Lightning payment infrastructure.
- **BTCPay Server** — for users who want to connect their own self-hosted BTCPay Server.

## WPForms integration

Coinsnap integrates directly into the WPForms payment workflow.

Bitcoin can be enabled on the forms where it is needed, allowing the same WordPress site to use different payment setups for different forms.

The add-on can be used alongside WPForms' traditional payment integrations or as a standalone Bitcoin payment option.

## Bitcoin discount

The current Coinsnap WPForms product page supports an optional **Bitcoin discount**.

Merchants can use a fixed or percentage-based discount to encourage visitors to choose Bitcoin when multiple payment methods are available.

## Micropayments and anti-spam

Lightning makes very small payments practical.

This allows WPForms to support use cases such as paid contact requests, premium submissions or small anti-spam fees. A small Lightning payment can create an economic barrier for automated spam while remaining inexpensive for a legitimate user.

## Settlement

With Coinsnap, payments can be settled to a compatible Bitcoin or Lightning wallet.

One option is the [Coinsnap Wallet](https://coinsnap.io/wallet/).

Coinsnap follows a **non-custodial / self-custody** approach and does not hold merchant Bitcoin.

Depending on the merchant setup and supported partner integrations, Bitcoin payments can also be automatically converted and settled to a bank account.

## Fees

Coinsnap charges a **1% transaction fee**. The fee is handled separately through the merchant's Coinsnap balance and is not deducted from the Bitcoin payment itself.

## Installation

### Install from WordPress.org

1. Install and activate **WPForms Pro**.
2. In WordPress, go to **Plugins → Add New**.
3. Search for **Bitcoin payment for WPForms**.
4. Install and activate the Coinsnap add-on.
5. Open a WPForms form.
6. Go to **Payments → Coinsnap**.
7. Enable Coinsnap payments.
8. Connect Coinsnap or BTCPay Server.
9. Save the form.
10. Make a test payment.

### Install from GitHub

1. Download this repository as a ZIP archive.
2. Upload it through **Plugins → Add New → Upload Plugin**.
3. Install and activate the add-on.
4. Make sure WPForms Pro is installed and active.
5. Configure Coinsnap inside the WPForms payment settings.

## Resources

- [Coinsnap for WPForms product page](https://coinsnap.io/wp-add-ons/wpforms/)
- [WPForms Bitcoin User Guide](https://coinsnap.io/user-guides/wpforms-bitcoin-user-guide/)
- [WordPress Plugin Directory](https://wordpress.org/plugins/coinsnap-for-wpforms/)
- [WPForms demo](https://wpforms.coinsnap.org/)
- [Coinsnap Wallet](https://coinsnap.io/wallet/)
- [Coinsnap API documentation](https://docs.coinsnap.io/)
- [Coinsnap merchant account](https://app.coinsnap.io/register)
- [Telegram community and support](https://t.me/coinsnap)

> **Brand note:** Coinsnap is Bitcoin payment software for merchants and is not the provider of the similarly named coin identifier app "CoinSnap".

## Frequently Asked Questions

### Can WPForms accept Bitcoin payments?

Yes. Coinsnap for WPForms adds Bitcoin and Lightning payments directly to WPForms Pro forms.

### Do I need WPForms Pro?

Yes. The current plugin code requires WPForms Pro to be installed and active.

### Can customers pay with both Lightning and Bitcoin on-chain?

Yes. The integration supports both Lightning and Bitcoin on-chain.

### Can Bitcoin be offered alongside Stripe or PayPal?

Yes. Bitcoin can be offered alongside other WPForms payment methods or used as a standalone option.

### Can I use my own BTCPay Server?

Yes. BTCPay Server is supported as an alternative to Coinsnap.

### Can I use Coinsnap in multiple WPForms?

Yes. Bitcoin payments can be enabled on multiple forms.

### Does the plugin support a Bitcoin discount?

Yes. The current Coinsnap product page documents an optional fixed or percentage-based Bitcoin discount.

### Can I use the plugin for micropayments?

Yes. Lightning is suitable for small payments, including low-value form submissions and anti-spam payment use cases.

### Do I need WooCommerce?

No. WPForms can be used as a lightweight Bitcoin checkout without a shop system.

### Do I need my own Lightning node?

No. When using Coinsnap, no own Lightning node or Bitcoin payment server is required.

### Does Coinsnap hold my Bitcoin?

No. Coinsnap follows a non-custodial / self-custody approach and does not hold merchant Bitcoin.

### Can payments be settled to a bank account?

Depending on the merchant setup and supported partner integrations, Bitcoin payments can be automatically converted and settled to a bank account.

### What does Coinsnap cost?

Coinsnap charges a 1% transaction fee.

## Changelog

### 1.5.3 — 2026-05-23
- Compatibility with WordPress 7.0 tested.

### 1.5.2 — 2026-04-07
- Updated the Coinsnap Invoice client.
- Updated the Coinsnap Store client.
- Compatibility with WPForms 1.10.0.2 tested.
- Compatibility with WordPress 6.9.4 tested.

### 1.5.1 — 2026-03-07
- Compatibility with WPForms 1.9.9.3–1.9.9.4 tested.
- Updated plugin description.

### 1.5.0 — 2026-02-16
- Compatibility with WordPress 6.9.1 tested.
- Updated server connection notification system.

### 1.4.5 — 2026-02-02
- Compatibility with WPForms 1.9.9.2 tested.
- Updated PHP file protection.

### 1.4.4 — 2025-12-29
- Compatibility with WPForms 1.9.8.7 tested.
- Updated PHP file protection.

### 1.4.3 — 2025-12-05
- Updated Coinsnap Store client data handling.
- Compatibility with WordPress 6.9 tested.
- Compatibility with WPForms 1.9.8.5 tested.

### 1.4.2 — 2025-10-14
- Added handling for currencies not supported by BTCPay Server.
- Added custom redirect after payment.
- Fixed BTCPay Server webhook registration and setup-wizard issues.
- Compatibility with WPForms 1.9.8.2 tested.

### 1.4.1 — 2025-09-28
- Updated registration and webhook handling.
- Compatibility with WPForms 1.9.8.1 tested.

### 1.4.0 — 2025-08-18
- Added nonce validation to the BTCPay Server setup flow.
- Updated BTCPay Server minimum-payment calculations.
- Updated BTCPay Server events and webhook handling.
- Compatibility with WPForms 1.9.7.3 tested.

### 1.3.1 — 2025-07-10
- Improved API-key setup.
- Updated the Coinsnap WordPress library.
- Updated the WPForms Pro dependency check.
- Compatibility with WPForms 1.9.6.2 tested.

### 1.3.0 — 2025-06-23
- Improved BTCPay Server setup callback handling.
- Added webhook data storage and webhook-secret validation.
- Compatibility with WPForms 1.9.6.1 tested.

### 1.2.0 — 2025-05-30
- Added BTCPay Server as a payment gateway.
- Added the BTCPay Server setup wizard.
- Added minimum-payment validation.

### 1.1.1 — 2025-04-29
- Compatibility with WPForms 1.9.5.1 tested.
- Compatibility with WordPress 6.8 tested.

### 1.1.0 — 2025-04-07
- Added automatic redirect after payment.
- Added minimum-payment validation.
- Improved webhook error handling.

### 1.0.0 — 2024-03-05
- First public release for testing.
