<?php
// ajax/sales.php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/accounting.php';

requireAuth();
header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$db = getDB();
$user = getCurrentUser();

if ($action === 'list') {
    if (!hasPermission('bills') && !hasPermission('pos')) {
        requirePermission('bills');
    }
    $search = trim((string)($_GET['search'] ?? ''));
    $fromDate = trim((string)($_GET['from_date'] ?? ''));
    $toDate = trim((string)($_GET['to_date'] ?? ''));
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = max(10, min(100, (int)($_GET['limit'] ?? 50)));
    $offset = ($page - 1) * $limit;

    $where = ["1=1"];
    $params = [];

    if ($search !== '') {
        $where[] = "(s.invoice_no LIKE ? OR p.name LIKE ? OR p.phone LIKE ?)";
        $params[] = "%{$search}%";
        $params[] = "%{$search}%";
        $params[] = "%{$search}%";
    }

    if ($fromDate !== '') {
        $where[] = "s.sale_date >= ?";
        $params[] = $fromDate;
    }
    if ($toDate !== '') {
        $where[] = "s.sale_date <= ?";
        $params[] = $toDate;
    }

    $whereSql = implode(' AND ', $where);

    // Total count & stats
    $countStmt = $db->prepare("
        SELECT COUNT(s.id), COALESCE(SUM(s.grand_total), 0), COALESCE(SUM(s.paid_amount), 0), COALESCE(SUM(s.due_amount), 0)
        FROM sales s
        JOIN parties p ON s.customer_id = p.id
        WHERE {$whereSql}
    ");
    $countStmt->execute($params);
    $stats = $countStmt->fetch(PDO::FETCH_NUM);
    $totalRecords = (int)$stats[0];
    $totalSales = (float)$stats[1];
    $totalPaid = (float)$stats[2];
    $totalDue = (float)$stats[3];

    // Data rows
    $stmt = $db->prepare("
        SELECT 
            s.id, s.invoice_no, s.sale_date, s.sale_time, s.customer_id, p.name AS customer_name, p.phone AS customer_phone,
            s.subtotal, s.additional_charges, s.discount, s.grand_total, s.paid_amount, s.due_amount,
            s.payment_status, s.payment_method, (s.grand_total - s.total_cogs) AS profit, s.status, s.notes
        FROM sales s
        JOIN parties p ON s.customer_id = p.id
        WHERE {$whereSql}
        ORDER BY s.id DESC
        LIMIT {$limit} OFFSET {$offset}
    ");
    $stmt->execute($params);
    $bills = $stmt->fetchAll();

    jsonSuccess('Bills retrieved', [
        'bills' => $bills,
        'summary' => [
            'total_count' => $totalRecords,
            'total_sales' => $totalSales,
            'total_paid' => $totalPaid,
            'total_due' => $totalDue
        ],
        'page' => $page,
        'limit' => $limit
    ]);
}

if ($action === 'get') {
    $id = (int)($_GET['id'] ?? 0);
    $invoiceNo = trim((string)($_GET['invoice_no'] ?? ''));

    if ($id > 0) {
        $stmt = $db->prepare("
            SELECT s.*, p.name AS customer_name, p.phone AS customer_phone, p.address AS customer_address, p.current_balance AS customer_current_balance
            FROM sales s
            JOIN parties p ON s.customer_id = p.id
            WHERE s.id = ?
        ");
        $stmt->execute([$id]);
    } else {
        $stmt = $db->prepare("
            SELECT s.*, p.name AS customer_name, p.phone AS customer_phone, p.address AS customer_address, p.current_balance AS customer_current_balance
            FROM sales s
            JOIN parties p ON s.customer_id = p.id
            WHERE s.invoice_no = ?
        ");
        $stmt->execute([$invoiceNo]);
    }

    $sale = $stmt->fetch();
    if (!$sale) {
        jsonError('Bill not found.');
    }

    // Line items
    $itemStmt = $db->prepare("
        SELECT si.*, i.name AS item_name, i.unit, i.item_code
        FROM sale_items si
        JOIN items i ON si.item_id = i.id
        WHERE si.sale_id = ?
    ");
    $itemStmt->execute([$sale['id']]);
    $sale['items'] = $itemStmt->fetchAll();

    // Additional charges
    $chargeStmt = $db->prepare("SELECT * FROM sale_charges WHERE sale_id = ?");
    $chargeStmt->execute([$sale['id']]);
    $sale['charges'] = $chargeStmt->fetchAll();

    jsonSuccess('Bill details retrieved', $sale);
}

if ($action === 'create') {
    requirePermission('pos');

    $customerId = (int)($_POST['customer_id'] ?? 1);
    $itemsJson = $_POST['items'] ?? '[]';
    $items = is_array($itemsJson) ? $itemsJson : json_decode($itemsJson, true);
    $discount = max(0.0, (float)($_POST['discount'] ?? 0));
    $labourCharge = max(0.0, (float)($_POST['labour_charge'] ?? 0));
    $deliveryCharge = max(0.0, (float)($_POST['delivery_charge'] ?? 0));
    $paymentMethod = trim((string)($_POST['payment_method'] ?? 'Cash'));
    $paymentAccountId = (int)($_POST['payment_account_id'] ?? 1);
    if ($paymentAccountId <= 0) {
        $paymentAccountId = 1;
    }
    $notes = trim((string)($_POST['notes'] ?? ''));
    $paidAmountInput = $_POST['paid_amount'] ?? null;
    $custNameCustom = trim((string)($_POST['customer_name_custom'] ?? ''));
    $custPhoneCustom = trim((string)($_POST['customer_phone_custom'] ?? ''));

    if (empty($items)) {
        jsonError('Please add at least one item to the bill.');
    }

    // Customer check
    $custStmt = $db->prepare("SELECT id, name, code, current_balance, is_walkin FROM parties WHERE id = ? AND type = 'customer'");
    $custStmt->execute([$customerId]);
    $customer = $custStmt->fetch();
    if (!$customer) {
        jsonError('Selected customer is invalid.');
    }

    // Determine if selected customer is a Walk-in / Cash counter customer
    $isWalkIn = (!empty($customer['is_walkin']))
        || ((int)$customer['id'] === 1)
        || (isset($customer['code']) && strtoupper((string)$customer['code']) === 'CUST-001')
        || (stripos((string)$customer['name'], 'walk-in') !== false)
        || (stripos((string)$customer['name'], 'walk in') !== false)
        || (stripos((string)$customer['name'], 'cash customer') !== false);

    // Rule: Walk-in customers CANNOT have credit sales or unpaid dues
    if ($isWalkIn && strcasecmp($paymentMethod, 'Credit') === 0 && ($paidAmountInput === null || (float)$paidAmountInput <= 0)) {
        jsonError('Pure credit sales without advance payment are prohibited for Walk-in Customers. Please record advance payment; remaining balance will be due upon site unloading. (واک ان گاہک کے لیے کم از کم پیشگی رقم وصول کریں)');
    }

    // Start database transaction
    $db->beginTransaction();

    try {
        $saleDate = date('Y-m-d');
        $saleTime = date('H:i:s');
        $invoiceNo = getNextDocNo('BILL', 'sales', 'invoice_no');

        $subtotal = 0.0;
        $totalCogs = 0.0;
        $validatedItems = [];

        // 1. Process items and validate stock
        foreach ($items as $it) {
            $itemId = (int)($it['item_id'] ?? 0);
            $qty = (float)($it['qty'] ?? 0);
            $rate = (float)($it['rate'] ?? $it['unit_rate'] ?? 0);
            $lineDiscount = (float)($it['line_discount'] ?? 0);

            if ($itemId <= 0 || $qty <= 0 || $rate <= 0) {
                throw new InvalidArgumentException("Invalid item line: quantity and rate must be greater than zero.");
            }

            $lineTotal = round(($qty * $rate) - $lineDiscount, 2);
            $subtotal += $lineTotal;

            $validatedItems[] = [
                'item_id' => $itemId,
                'qty' => $qty,
                'unit_rate' => $rate,
                'line_discount' => $lineDiscount,
                'line_total' => $lineTotal
            ];
        }

        $additionalCharges = $labourCharge + $deliveryCharge;
        $grandTotal = round(($subtotal + $additionalCharges) - $discount, 2);

        // Paid Amount & Advance Handling (Walk-in allows advance with remaining payable upon delivery)
        if ($paidAmountInput === null || $paidAmountInput === '') {
            $paidAmount = $grandTotal;
        } else {
            $paidAmount = max(0.0, (float)$paidAmountInput);
        }

        if ($paidAmount > $grandTotal) {
            $paidAmount = $grandTotal;
        }

        $dueAmount = round($grandTotal - $paidAmount, 2);
        $paymentStatus = ($dueAmount <= 0) ? 'Paid' : (($paidAmount == 0) ? 'Unpaid' : 'Partial');

        if ($isWalkIn && $dueAmount > 0) {
            $deliveryNote = "Advance Paid: Rs " . number_format($paidAmount, 2) . " | Due on Site Unloading: Rs " . number_format($dueAmount, 2) . " (مال اتروائی پر بقیہ وصولی)";
            $notes = $notes ? ($notes . " | " . $deliveryNote) : $deliveryNote;
        }

        // 2. Insert sales header
        $saleStmt = $db->prepare("
            INSERT INTO `sales` 
            (`invoice_no`, `customer_id`, `customer_name_custom`, `customer_phone_custom`, `sale_date`, `sale_time`, `subtotal`, `labour_charge`, `delivery_charge`, `additional_charges`, `discount`, `tax_amount`, `grand_total`, `paid_amount`, `due_amount`, `payment_status`, `payment_method`, `payment_account_id`, `total_cogs`, `status`, `notes`, `created_by`, `created_at`)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0.00, ?, ?, ?, ?, ?, ?, 0.00, 'posted', ?, ?, NOW())
        ");
        $saleStmt->execute([
            $invoiceNo,
            $customerId,
            $custNameCustom ?: null,
            $custPhoneCustom ?: null,
            $saleDate,
            $saleTime,
            $subtotal,
            $labourCharge,
            $deliveryCharge,
            $additionalCharges,
            $discount,
            $grandTotal,
            $paidAmount,
            $dueAmount,
            $paymentStatus,
            $paymentMethod,
            $paymentAccountId,
            $notes,
            $user['id'] ?? null
        ]);
        $saleId = (int)$db->lastInsertId();

        // 3. Insert sale items & deduct inventory + calculate COGS
        $itemInsertStmt = $db->prepare("
            INSERT INTO `sale_items`
            (`sale_id`, `item_id`, `qty`, `unit_rate`, `line_discount`, `line_total`, `unit_cogs`, `total_cogs`)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($validatedItems as $vItem) {
            $issueResult = issueInventoryItemForSale($db, $vItem['item_id'], $vItem['qty'], $saleId, $saleDate, $user['id'] ?? null);
            $totalCogs += $issueResult['total_cogs'];

            $itemInsertStmt->execute([
                $saleId,
                $vItem['item_id'],
                $vItem['qty'],
                $vItem['unit_rate'],
                $vItem['line_discount'],
                $vItem['line_total'],
                $issueResult['unit_cogs'],
                $issueResult['total_cogs']
            ]);
        }

        // Update total COGS on sales header
        $db->prepare("UPDATE `sales` SET total_cogs = ? WHERE id = ?")->execute([$totalCogs, $saleId]);

        // 4. Record additional charges if any
        if ($labourCharge > 0) {
            $db->prepare("INSERT INTO sale_charges (sale_id, charge_name, amount) VALUES (?, 'Labour', ?)")
               ->execute([$saleId, $labourCharge]);
        }
        if ($deliveryCharge > 0) {
            $db->prepare("INSERT INTO sale_charges (sale_id, charge_name, amount) VALUES (?, 'Delivery/Freight', ?)")
               ->execute([$saleId, $deliveryCharge]);
        }

        // 5. Construct Double-Entry Journal Entry
        // Resolve payment financial account
        $finAcctStmt = $db->prepare("SELECT coa_id FROM financial_accounts WHERE id = ?");
        $finAcctStmt->execute([$paymentAccountId]);
        $paymentCoaId = (int)($finAcctStmt->fetchColumn() ?: COA_CASH_MAIN);

        $journalLines = [];

        // Debit: Paid amount to Cash/Bank Account
        if ($paidAmount > 0) {
            $journalLines[] = [
                'account_id' => $paymentCoaId,
                'party_id' => null,
                'description' => "Payment received for Bill #{$invoiceNo}",
                'debit' => $paidAmount,
                'credit' => 0.00
            ];
        }

        // Debit: Customer Receivables for unpaid due amount
        if ($dueAmount > 0) {
            $journalLines[] = [
                'account_id' => COA_RECEIVABLE,
                'party_id' => $customerId,
                'description' => "Receivable for credit sale Bill #{$invoiceNo}",
                'debit' => $dueAmount,
                'credit' => 0.00
            ];
        }

        // Debit: Discount Allowed (if any)
        if ($discount > 0) {
            $journalLines[] = [
                'account_id' => COA_SALES_DISCOUNT,
                'party_id' => null,
                'description' => "Discount allowed on Bill #{$invoiceNo}",
                'debit' => $discount,
                'credit' => 0.00
            ];
        }

        // Credit: Sales Revenue (subtotal)
        if ($subtotal > 0) {
            $journalLines[] = [
                'account_id' => COA_SALES_REVENUE,
                'party_id' => null,
                'description' => "Sales revenue for Bill #{$invoiceNo}",
                'debit' => 0.00,
                'credit' => $subtotal
            ];
        }

        // Credit: Service Income for additional charges
        if ($deliveryCharge > 0) {
            $journalLines[] = [
                'account_id' => COA_DELIVERY_INCOME,
                'party_id' => null,
                'description' => "Delivery charge recovery on Bill #{$invoiceNo}",
                'debit' => 0.00,
                'credit' => $deliveryCharge
            ];
        }
        if ($labourCharge > 0) {
            $journalLines[] = [
                'account_id' => COA_SERVICE_INCOME,
                'party_id' => null,
                'description' => "Labour/Cutting service on Bill #{$invoiceNo}",
                'debit' => 0.00,
                'credit' => $labourCharge
            ];
        }

        // Cost of Goods Sold Recognition:
        // Debit: COGS (Cost of Goods Sold)
        // Credit: Inventory (Stock on Hand)
        if ($totalCogs > 0) {
            $journalLines[] = [
                'account_id' => COA_COGS,
                'party_id' => null,
                'description' => "COGS for Bill #{$invoiceNo}",
                'debit' => $totalCogs,
                'credit' => 0.00
            ];
            $journalLines[] = [
                'account_id' => COA_INVENTORY,
                'party_id' => null,
                'description' => "Inventory reduction for Bill #{$invoiceNo}",
                'debit' => 0.00,
                'credit' => $totalCogs
            ];
        }

        $journalHeader = [
            'entry_no' => getNextDocNo('JV', 'journal_entries', 'entry_no'),
            'entry_date' => $saleDate,
            'source_type' => 'sale',
            'source_id' => $saleId,
            'narration' => "Sale invoice #{$invoiceNo} to {$customer['name']}",
            'created_by' => $user['id'] ?? null
        ];

        $journalId = postJournalEntry($db, $journalHeader, $journalLines);

        // Update sales header with journal ID
        $db->prepare("UPDATE `sales` SET journal_id = ? WHERE id = ?")->execute([$journalId, $saleId]);

        // Create Voucher record for traceability
        $db->prepare("
            INSERT INTO `vouchers`
            (`voucher_no`, `voucher_type`, `voucher_date`, `party_id`, `account_id`, `amount`, `payment_mode`, `narration`, `journal_id`, `status`, `created_by`, `created_at`)
            VALUES (?, 'RV', ?, ?, ?, ?, ?, ?, ?, 'posted', ?, NOW())
        ")->execute([
            getNextDocNo('RV', 'vouchers', 'voucher_no'),
            $saleDate,
            $customerId,
            $paymentAccountId,
            $paidAmount,
            $paymentMethod,
            "Sale Bill #{$invoiceNo} collection",
            $journalId,
            $user['id'] ?? null
        ]);

        $db->commit();

        jsonSuccess('Sale bill posted successfully!', [
            'id' => $saleId,
            'invoice_no' => $invoiceNo,
            'grand_total' => $grandTotal,
            'paid_amount' => $paidAmount,
            'due_amount' => $dueAmount,
            'customer_name' => $customer['name']
        ]);

    } catch (Exception $e) {
        $db->rollBack();
        jsonError('Failed to post bill: ' . $e->getMessage());
    }
}

if ($action === 'cancel') {
    requirePermission('bills');
    if (($user['role'] ?? '') !== 'Owner') {
        jsonError('Only Owner can cancel posted bills.');
    }

    $id = (int)($_POST['id'] ?? 0);
    $reason = trim((string)($_POST['reason'] ?? 'Customer order cancelled'));

    $stmt = $db->prepare("SELECT * FROM sales WHERE id = ? AND status = 'posted'");
    $stmt->execute([$id]);
    $sale = $stmt->fetch();
    if (!$sale) {
        jsonError('Sale bill not found or already cancelled.');
    }

    $db->beginTransaction();
    try {
        // 1. Restock items
        $itemStmt = $db->prepare("SELECT * FROM sale_items WHERE sale_id = ?");
        $itemStmt->execute([$id]);
        $items = $itemStmt->fetchAll();

        foreach ($items as $it) {
            $itemId = (int)$it['item_id'];
            $qty = (float)$it['qty'];

            $db->prepare("UPDATE items SET stock_qty = stock_qty + ? WHERE id = ?")->execute([$qty, $itemId]);

            $db->prepare("
                INSERT INTO stock_movements
                (item_id, movement_type, reference_type, reference_id, qty_in, qty_out, unit_cost, total_cost, balance_qty, movement_date, notes, created_by, created_at)
                SELECT ?, 'sale_return', 'sale_cancel', ?, ?, 0, cost_price, (? * cost_price), stock_qty, CURDATE(), 'Sale bill cancellation restock', ?, NOW()
                FROM items WHERE id = ?
            ")->execute([$itemId, $id, $qty, $qty, $user['id'] ?? null, $itemId]);
        }

        // 2. Reverse journal entry
        if (!empty($sale['journal_id'])) {
            reverseJournalEntry($db, (int)$sale['journal_id'], "Cancellation of bill #{$sale['invoice_no']}: {$reason}", (int)($user['id'] ?? 1));
        }

        // 3. Mark sale as cancelled
        $db->prepare("UPDATE sales SET status = 'cancelled', notes = CONCAT(COALESCE(notes,''), ' [CANCELLED: ', ?, ']') WHERE id = ?")
           ->execute([$reason, $id]);

        $db->commit();
        jsonSuccess("Bill #{$sale['invoice_no']} successfully cancelled and reversed.");
    } catch (Exception $e) {
        $db->rollBack();
        jsonError('Cancellation failed: ' . $e->getMessage());
    }
}

jsonError('Invalid action requested.');
