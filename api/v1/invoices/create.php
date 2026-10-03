<?php

/*
 * API - Invoices - Create
 * POST /api/v1/invoices/create.php
 *
 * Creates a Draft invoice, optionally with line items, in a single call.
 *
 * This mirrors the agent UI handler (agent/post/invoice.php -> add_invoice) so
 * invoice numbering, URL keys and totals stay consistent with invoices created
 * inside ITFlow. The API context does not load the company/global settings that
 * agent pages preload, so this endpoint fetches what it needs directly.
 *
 * Parameters (POST, JSON body):
 *   api_key       required - Your API key
 *   client_id     required - Target client. The key user must have access to it.
 *   date          optional - Invoice date, YYYY-MM-DD (default: today)
 *   due           optional - Due date, YYYY-MM-DD
 *                            (default: date + the client's net terms)
 *   scope         optional - Short description, max 255 chars
 *   notes         optional - Invoice notes (free text)
 *   category_id   optional - Income category id (default 0)
 *   discount      optional - Invoice discount amount (default 0)
 *   items         optional - Array of line items, each:
 *                    name        required
 *                    qty         required, must be > 0
 *                    price       required
 *                    description optional
 *                    tax_id      optional
 *                    product_id  optional (tangible products adjust stock)
 *                    item_order  optional
 *
 * Response (standard create_output.php):
 *   {"success":"True","count":"1","data":[{"insert_id":<invoice_id>}]}
 *
 * RBAC:
 *   api/v1/invoices/ maps to the module_sales permission and create.php needs
 *   level 2. enforce_api_rbac.php also validates the client_id write target
 *   against the API key user's client access before this file runs.
 */

require_once '../validate_api_key.php';

require_once '../require_post_method.php';

// require_post_method.php resolves $client_id from the body for unrestricted keys.
// The enforcer has already validated this write target against the key user's access.
$client_id = intval($client_id);

$insert_id = false;

// A usable target client is required. A missing/zero id falls through to
// create_output.php, which returns the standard failure message.
$client_exists = $client_id > 0 ? getFieldById('clients', $client_id, 'client_id') : null;

if (!empty($client_exists)) {

    // --- Input -----------------------------------------------------------
    $date_in     = trim($_POST['date'] ?? '');
    $due_in      = trim($_POST['due'] ?? '');
    $scope       = escapeSql(substr(trim($_POST['scope'] ?? ''), 0, 255));
    $notes       = escapeSql($_POST['notes'] ?? '');
    $category_id = intval($_POST['category_id'] ?? 0);
    $discount    = floatval($_POST['discount'] ?? 0);
    $items       = (isset($_POST['items']) && is_array($_POST['items'])) ? $_POST['items'] : [];

    // Dates are accepted only as YYYY-MM-DD; anything else falls back to defaults.
    $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_in) ? escapeSql($date_in) : date('Y-m-d');
    $due  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $due_in) ? escapeSql($due_in) : '';

    // --- Company settings (not preloaded in the API context) -------------
    $settings_sql = mysqli_query($mysqli, "SELECT config_invoice_prefix, config_default_net_terms FROM settings WHERE company_id = 1 LIMIT 1");
    $settings_row = $settings_sql ? mysqli_fetch_assoc($settings_sql) : [];

    $invoice_prefix = escapeSql($settings_row['config_invoice_prefix'] ?? '');

    $company_sql = mysqli_query($mysqli, "SELECT company_currency FROM companies WHERE company_id = 1 LIMIT 1");
    $company_row = $company_sql ? mysqli_fetch_assoc($company_sql) : [];
    $currency_code = escapeSql($company_row['company_currency'] ?? 'USD');

    // Net terms drive the default due date.
    $client_net_terms = intval(getFieldById('clients', $client_id, 'client_net_terms'));
    if ($client_net_terms <= 0) {
        $client_net_terms = intval($settings_row['config_default_net_terms'] ?? 0);
    }

    // --- Invoice number (atomic, same sequence as the UI) ----------------
    mysqli_query($mysqli, "
        UPDATE settings
        SET
            config_invoice_next_number = LAST_INSERT_ID(config_invoice_next_number),
            config_invoice_next_number = config_invoice_next_number + 1
        WHERE company_id = 1
    ");

    $invoice_number = mysqli_insert_id($mysqli);

    $url_key = randomString(32);

    // --- Insert the invoice ----------------------------------------------
    $due_sql = $due !== ''
        ? "invoice_due = '$due'"
        : "invoice_due = DATE_ADD('$date', INTERVAL $client_net_terms day)";

    mysqli_query($mysqli, "INSERT INTO invoices SET
        invoice_prefix = '$invoice_prefix',
        invoice_number = $invoice_number,
        invoice_scope = '$scope',
        invoice_note = '$notes',
        invoice_date = '$date',
        $due_sql,
        invoice_discount_amount = '$discount',
        invoice_amount = -$discount,
        invoice_currency_code = '$currency_code',
        invoice_category_id = $category_id,
        invoice_status = 'Draft',
        invoice_url_key = '$url_key',
        invoice_client_id = $client_id
    ");

    $invoice_id = mysqli_insert_id($mysqli);

    if ($invoice_id) {

        logHistory('Draft', "Invoice created via API ($api_key_name)", $invoice_id);

        // --- Line items --------------------------------------------------
        // Totals start at -discount and accumulate, matching the UI handler.
        $invoice_total = 0 - $discount;
        $item_order = 0;

        foreach ($items as $item) {

            if (!is_array($item)) {
                continue;
            }

            $name        = escapeSql(substr(trim($item['name'] ?? ''), 0, 200));
            $description = escapeSql($item['description'] ?? '');
            $qty         = floatval($item['qty'] ?? 0);
            $price       = floatval($item['price'] ?? 0);
            $tax_id      = intval($item['tax_id'] ?? 0);
            $product_id  = intval($item['product_id'] ?? 0);
            $order       = isset($item['item_order']) ? intval($item['item_order']) : $item_order;

            if ($name === '' || $qty <= 0) {
                continue;
            }

            // Inventory is only adjusted for tangible products with enough stock.
            if ($product_id) {

                $product_type = escapeSql(getFieldById('products', $product_id, 'product_type'));

                if ($product_type === 'product') {

                    $stock_row = mysqli_fetch_assoc(mysqli_query(
                        $mysqli,
                        "SELECT COALESCE(SUM(stock_qty),0) AS available_stock
                         FROM product_stock
                         WHERE stock_product_id = $product_id"
                    ));

                    if (floatval($stock_row['available_stock'] ?? 0) >= $qty) {
                        mysqli_query($mysqli, "INSERT INTO product_stock
                            SET stock_qty = -$qty,
                                stock_note = 'QTY $qty - Invoice $invoice_id',
                                stock_product_id = $product_id");
                    } else {
                        // Skip the line rather than voiding the invoice; the failure is logged.
                        logAudit('API', 'Failure', "Insufficient stock for $name on invoice $invoice_prefix$invoice_number via API ($api_key_name)", $client_id, $invoice_id);
                        continue;
                    }

                }

            }

            $subtotal = $price * $qty;

            if ($tax_id > 0) {
                $tax_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT tax_percent FROM taxes WHERE tax_id = $tax_id"));
                $tax_amount = $subtotal * floatval($tax_row['tax_percent'] ?? 0) / 100;
            } else {
                $tax_amount = 0;
            }

            $total = $subtotal + $tax_amount;

            mysqli_query($mysqli, "INSERT INTO invoice_items SET
                item_name = '$name',
                item_description = '$description',
                item_quantity = $qty,
                item_price = $price,
                item_subtotal = $subtotal,
                item_tax = $tax_amount,
                item_total = $total,
                item_order = $order,
                item_tax_id = $tax_id,
                item_product_id = $product_id,
                item_invoice_id = $invoice_id
            ");

            $invoice_total += $total;
            $item_order++;

        }

        // --- Recalculate the invoice total ---------------------------------
        mysqli_query($mysqli, "UPDATE invoices SET invoice_amount = $invoice_total WHERE invoice_id = $invoice_id LIMIT 1");

        logAudit('Invoice', 'Create', "Created Invoice $invoice_prefix$invoice_number via API ($api_key_name)", $client_id, $invoice_id);

        triggerCustomAction('invoice_create', $invoice_id);

        $insert_id = $invoice_id;

    }

}

// Output - standard create response, insert_id is the new invoice_id.
require_once '../create_output.php';
