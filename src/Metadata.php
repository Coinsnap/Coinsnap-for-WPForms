<?php
namespace WPFormsCoinsnap;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Invoice metadata for a WPForms entry: the entry ID as orderId, the form, the payment items
 * and the submitted fields. Plain values (no HTML escaping); empty values are left out.
 */
final class Metadata {

    public const PLATFORM = 'wpforms';
    public const MAX_LENGTH = 500;

    /** Never sent: files, passwords, card, captcha and hidden fields, layout and admin-only fields. */
    private const SKIP_TYPES = [
        'file-upload', 'camera', 'signature', 'password', 'captcha', 'custom-captcha', 'hidden', 'html', 'content', 'divider',
        'pagebreak', 'layout', 'repeater', 'entry-preview', 'internal-information', 'gdpr-checkbox', 'submit',
        'credit-card', 'stripe-credit-card', 'authorize_net', 'square', 'paypal-commerce',
    ];

    /** Payment fields: sent as lineItems (and the coupon as couponCodes), not as formFields. */
    private const ITEM_TYPES = ['payment-single', 'payment-multiple', 'payment-checkbox', 'payment-select'];

    /**
     * @param array $args entry_id, payment_id, form_id, form_title, order_url, page_url, post_id, version, language,
     *                    fields (WPForms' processed fields: id, type, name, value, value_raw, amount_raw, quantity,
     *                    first/last, address1/address2/city/state/postal/country)
     */
    public static function for_entry(array $args): array {
        $entryId = (string) ($args['entry_id'] ?? '');
        $meta = [];
        // The webhook matches the invoice to the entry by this ID (top-level orderId, metadata orderId / orderNumber).
        self::put($meta, 'orderId', $entryId);
        self::put($meta, 'orderNumber', $entryId);
        self::put($meta, 'entryId', $entryId);
        $paymentId = (int) ($args['payment_id'] ?? 0);
        if ($paymentId > 0) {
            $meta['paymentId'] = (string) $paymentId;
        }
        self::put($meta, 'orderUrl', $args['order_url'] ?? '');
        self::put($meta, 'formId', (string) ($args['form_id'] ?? ''));
        self::put($meta, 'formTitle', $args['form_title'] ?? '');
        self::put($meta, 'itemDesc', $args['form_title'] ?? '');
        self::put($meta, 'platform', self::PLATFORM);
        self::put($meta, 'pluginVersion', $args['version'] ?? '');
        self::put($meta, 'pageUrl', $args['page_url'] ?? '');
        $postId = (int) ($args['post_id'] ?? 0);
        if ($postId > 0) {
            $meta['postId'] = (string) $postId;
        }
        self::put($meta, 'language', $args['language'] ?? '');

        $items = [];
        $coupons = [];
        $other = [];
        foreach ((array) ($args['fields'] ?? []) as $field) {
            if (!is_array($field)) {
                continue;
            }
            $type = (string) ($field['type'] ?? '');
            if (in_array($type, self::SKIP_TYPES, true) || strpos($type, 'credit-card') !== false) {
                continue;
            }
            if (in_array($type, self::ITEM_TYPES, true)) {
                $price = (float) ($field['amount_raw'] ?? ($field['amount'] ?? 0));
                if ($price <= 0 && ($field['value_raw'] ?? '') === '') {
                    continue; // nothing chosen
                }
                $qty = isset($field['quantity']) ? (int) $field['quantity'] : 1;
                if ($qty < 1) {
                    continue;
                }
                $name = trim((string) ($field['name'] ?? ''));
                $choice = is_scalar($field['value_choice'] ?? null) ? trim((string) $field['value_choice']) : '';
                $line = [];
                self::put($line, 'name', ($choice !== '' && $choice !== $name) ? trim($name . ': ' . $choice, ': ') : $name);
                self::put($line, 'sku', (string) ($field['id'] ?? ''));
                $line['quantity'] = $qty;
                // A checkbox field's amount is already the sum of the chosen options.
                $line['price'] = self::money($price);
                $line['total'] = self::money($price * $qty);
                $items[] = $line;
                continue;
            }
            if ($type === 'payment-coupon') {
                $code = self::clean($field['value'] ?? '');
                if ($code !== null) {
                    $coupons[] = (string) $code;
                }
                continue;
            }
            if ($type === 'payment-total') {
                continue;
            }

            // Personal data. The plugin has no "send customer data" setting, so it is always sent.
            if ($type === 'name' && !isset($meta['buyerName'])) {
                self::put($meta, 'customerName', $field['value'] ?? '');
                self::put($meta, 'buyerName', $field['value'] ?? '');
                continue;
            }
            if ($type === 'address' && !isset($meta['buyerAddress1'])) {
                $parts = ['buyerAddress1' => 'address1', 'buyerAddress2' => 'address2', 'buyerCity' => 'city', 'buyerState' => 'state', 'buyerZip' => 'postal', 'buyerCountry' => 'country'];
                foreach ($parts as $key => $part) {
                    self::put($meta, $key, $field[$part] ?? '');
                }
                continue;
            }
            $value = self::clean($field['value'] ?? '');
            if ($value === null) {
                continue;
            }
            if ($type === 'email' && !isset($meta['buyerEmail'])) {
                $meta['buyerEmail'] = $value;
            } elseif ($type === 'phone' && !isset($meta['buyerPhone'])) {
                $meta['buyerPhone'] = $value;
            } elseif ($type === 'textarea' && !isset($meta['buyerMessage'])) {
                $meta['buyerMessage'] = $value;
            } else {
                $label = trim((string) ($field['name'] ?? ''));
                $label = ($label === '') ? 'Field ' . ($field['id'] ?? '') : $label;
                $other[isset($other[$label]) ? $label . ' (' . ($field['id'] ?? '') . ')' : $label] = $value;
            }
        }
        if ($items) {
            $meta['lineItems'] = $items;
        }
        if ($coupons) {
            $meta['couponCodes'] = implode(', ', $coupons);
        }
        if ($other) {
            $meta['formFields'] = $other;
        }
        return $meta;
    }

    private static function money(float $value): string {
        return number_format($value, 2, '.', '');
    }

    /** Adds $value under $key unless it is empty. */
    public static function put(array &$meta, string $key, $value): void {
        $value = self::clean($value);
        if ($value !== null) {
            $meta[$key] = $value;
        }
    }

    /** A plain value for metadata, or null to leave it out (empty, not text, or a card number). */
    public static function clean($value) {
        if (is_array($value)) {
            $parts = [];
            foreach ($value as $part) {
                if (is_scalar($part) && !is_bool($part)) {
                    $part = self::clean($part);
                    if ($part !== null) {
                        $parts[] = (string) $part;
                    }
                }
            }
            return $parts ? self::cut(implode(', ', $parts)) : null;
        }
        if (is_int($value) || is_float($value)) {
            return $value;
        }
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '' || self::looks_like_card_number($value)) {
            return null;
        }
        return self::cut($value);
    }

    /** 13 to 19 digits (spaces and dashes allowed) that pass the Luhn check. */
    public static function looks_like_card_number(string $value): bool {
        $digits = preg_replace('/[\s-]/', '', $value);
        if (!is_string($digits) || !preg_match('/^\d{13,19}$/', $digits)) {
            return false;
        }
        $sum = 0;
        $double = false;
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $d = (int) $digits[$i];
            if ($double) {
                $d *= 2;
                if ($d > 9) {
                    $d -= 9;
                }
            }
            $sum += $d;
            $double = !$double;
        }
        return $sum % 10 === 0;
    }

    private static function cut(string $value): string {
        if (function_exists('mb_substr')) {
            return mb_strlen($value, 'UTF-8') > self::MAX_LENGTH ? mb_substr($value, 0, self::MAX_LENGTH, 'UTF-8') : $value;
        }
        return strlen($value) > self::MAX_LENGTH ? substr($value, 0, self::MAX_LENGTH) : $value;
    }
}
