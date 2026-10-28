<?php
// ajax/purchases.php
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
    $search = trim((string)($_GET['search'] ?? ''));
    $fromDate = trim((string)($_GET['from_date'] ?? ''));
    $toDate = trim((string)($_GET['to_date'] ?? ''));
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = max(10, min(100, (int)($_GET['limit'] ?? 50)));
    $offset = ($page - 1) * $limit;

    $where = ["1=1"];
    $params = [];

    if ($search !== '') {
        $where[] = "(pr.purchase_no LIKE ? OR p.name LIKE ? OR pr.supplier_inv_no LIKE ?)";
        $params[] = "%{$search}%";
        $params[] = "%{$search}%";
        $params[] = "%{$search}%";
    }

    if ($fromDate !== '') {
        $where[] = "pr.purchase_date >= ?";
        $params[] = $fromDate;
    }
    if ($toDate !== '') {
        $where[] = "pr.purchase_date <= ?";
        $params[] = $toDate;
    }

    $whereSql = implode(' AND ', $where);

    $countStmt = $db->prepare("
        SELECT COUNT(pr.id), COALESCE(SUM(pr.grand_total), 0), COALESCE(SUM(pr.paid_amount), 0), COALESCE(SUM(pr.due_amount), 0)
        FROM purchases pr
        JOIN parties p ON pr.supplier_id = p.id
        WHERE {$whereSql}
    ");
    $countStmt->execute($params);
    $stats = $countStmt->fetch(PDO::FETCH_NUM);

    $stmt = $db->prepare("
        SELECT 
            pr.id, pr.purchase_no, pr.purchase_date, pr.supplier_inv_no, pr.supplier_id, p.name AS supplier_name,
            pr.subtotal, pr.additional_charges, pr.discount, pr.grand_total, pr.paid_amount, pr.due_amount,
            pr.payment_status, pr.status, pr.notes
        FROM purchases pr
        JOIN parties p ON pr.supplier_id = p.id
        WHERE {$whereSql}
        ORDER BY pr.id DESC
        LIMIT {$limit} OFFSET {$offset}
    ");
    $stmt->execute($params);
    $purchases = $stmt->fetchAll();

    jsonSuccess('Purchases retrieved', [
        'purchases' => $purchases,
        'summary' => [
            'total_count' => (int)$stats[0],
            'total_grand' => (float)$stats[1],
            'total_paid' => (float)$stats[2],
            'total_due' => (float)$stats[3]
        ],
        'page' => $page,
        'limit' => $limit
    ]);
}

if ($action === 'get') {
    $id = (int)($_GET['id'] ?? 0);
    $stmt = $db->prepare("
        SELECT pr.*, p.name AS supplier_name, p.phone AS supplier_phone, p.address AS supplier_address, p.current_balance AS supplier_current_balance
        FROM purchases pr
        JOIN parties p ON pr.supplier_id = p.id
        WHERE pr.id = ?
    ");
    $stmt->execute([$id]);
    $purchase = $stmt->fetch();
    if (!$purchase) {
        jsonError('Purchase not found.');
    }

    // Items
    $itemStmt = $db->prepare("
        SELECT pi.*, i.name AS item_name, i.unit, i.item_code
        FROM purchase_items pi
        JOIN items i ON pi.item_id = i.id
        WHERE pi.purchase_id = ?
    ");
    $itemStmt->execute([$id]);
    $purchase['items'] = $itemStmt->fetchAll();

    // Charges
    $chargeStmt = $db->prepare("SELECT * FROM purchase_charges WHERE purchase_id = ?");
    $chargeStmt->execute([$id]);
    $purchase['charges'] = $chargeStmt->fetchAll();

    jsonSuccess('Purchase details retrieved', $purchase);
}

if ($action === 'create') {
    requirePermission('purchases');

    $supplierId = (int)($_POST['supplier_id'] ?? 0);
    $purchaseDate = trim((string)($_POST['purchase_date'] ?? date('Y-m-d')));
    $supplierInvNo = trim((string)($_POST['supplier_inv_no'] ?? ''));
    $itemsJson = $_POST['items'] ?? '[]';
    $chargesJson = $_POST['charges'] ?? '[]';
    $discount = max(0.0, (float)($_POST['discount'] ?? 0));
    $paidAmountInput = $_POST['paid_amount'] ?? null;
    $paymentAccountId = (int)($_POST['payment_account_id'] ?? 1);
    $notes = trim((string)($_POST['notes'] ?? ''));

    $rawItems = is_array($itemsJson) ? $itemsJson : json_decode($itemsJson, true);
    $rawCharges = is_array($chargesJson) ? $chargesJson : json_decode($chargesJson, true);

    if (empty($rawItems)) {
        jsonError('Please add at least one item to the purchase.');
    }

    $supStmt = $db->prepare("SELECT id, name, current_balance FROM parties WHERE id = ? AND type = 'supplier'");
    $supStmt->execute([$supplierId]);
    $supplier = $supStmt->fetch();
    if (!$supplier) {
        jsonError('Selected supplier is invalid.');
    }

    $db->beginTransaction();

    try {
        $purchaseNo = getNextDocNo('PUR', 'purchases', 'purchase_no');
        $subtotal = 0.0;
        $preparedItems = [];

        foreach ($rawItems as $it) {
            $itemId = (int)($it['item_id'] ?? 0);
            $qty = (float)($it['qty'] ?? 0);
            $rate = (float)($it['unit_rate'] ?? $it['rate'] ?? 0);
            $lineDiscount = (float)($it['line_discount'] ?? 0);

            if ($itemId <= 0 || $qty <= 0 || $rate <= 0) {
                throw new InvalidArgumentException("Item quantity and rate must be greater than zero.");
            }

            $baseAmount = round(($qty * $rate) - $lineDiscount, 2);
            $subtotal += $baseAmount;

            $preparedItems[] = [
                'item_id' => $itemId,
                'qty' => $qty,
                'unit_rate' => $rate,
                'line_discount' => $lineDiscount,
                'base_amount' => $baseAmount
            ];
        }

        // Process Additional Charges & Landed Cost
        $totalCharges = 0.0;
        $totalCapitalizedCharges = 0.0;
        $preparedCharges = [];

        if (!empty($rawCharges)) {
            foreach ($rawCharges as $c) {
                $cName = trim((string)($c['charge_name'] ?? 'Labour'));
                $cAmt = max(0.0, (float)($c['amount'] ?? 0));
                $cCap = !empty($c['capitalize_inventory']) ? 1 : 0;
                $cMethod = trim((string)($c['allocation_method'] ?? 'weight'));
                $payee = trim((string)($c['payee_name'] ?? ''));

                if ($cAmt > 0) {
                    $totalCharges += $cAmt;
                    if ($cCap === 1) {
                        $totalCapitalizedCharges += $cAmt;
                    }
                    $preparedCharges[] = [
                        'charge_name' => $cName,
                        'amount' => $cAmt,
                        'payee_name' => $payee,
                        'capitalize_inventory' => $cCap,
                        'allocation_method' => $cMethod
                    ];
                }
            }
        }

        // Allocate charges across items to compute landed cost per unit
        $landedItems = computePurchaseLandedCosts($preparedItems, $preparedCharges);

        $grandTotal = round(($subtotal + $totalCharges) - $discount, 2);

        $paidAmount = ($paidAmountInput === null || $paidAmountInput === '') ? 0.0 : max(0.0, (float)$paidAmountInput);
        if ($paidAmount > $grandTotal) {
            $paidAmount = $grandTotal;
        }

        $dueAmount = round($grandTotal - $paidAmount, 2);
        $paymentStatus = ($dueAmount <= 0) ? 'Paid' : (($paidAmount == 0) ? 'Unpaid' : 'Partial');

        // 1. Insert Purchase Header
        $purStmt = $db->prepare("
            INSERT INTO `purchases`
            (`purchase_no`, `supplier_id`, `purchase_date`, `supplier_inv_no`, `warehouse`, `subtotal`, `additional_charges`, `discount`, `grand_total`, `paid_amount`, `due_amount`, `payment_status`, `payment_account_id`, `status`, `notes`, `created_by`, `created_at`)
            VALUES (?, ?, ?, ?, 'Main Yard', ?, ?, ?, ?, ?, ?, ?, ?, 'posted', ?, ?, NOW())
        ");
        $purStmt->execute([
            $purchaseNo,
            $supplierId,
            $purchaseDate,
            $supplierInvNo,
            $subtotal,
            $totalCharges,
            $discount,
            $grandTotal,
            $paidAmount,
            $dueAmount,
            $paymentStatus,
            $paymentAccountId,
            $notes,
            $user['id'] ?? null
        ]);
        $purchaseId = (int)$db->lastInsertId();

        // 2. Insert Purchase Items & Receive Inventory at Landed Cost
        $itemInsertStmt = $db->prepare("
            INSERT INTO `purchase_items`
            (`purchase_id`, `item_id`, `qty`, `unit_rate`, `line_discount`, `base_amount`, `allocated_charge`, `landed_cost`, `landed_unit_rate`)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $totalInventoryCapitalized = 0.0;
        foreach ($landedItems as $lItem) {
            $itemInsertStmt->execute([
                $purchaseId,
                $lItem['item_id'],
                $lItem['qty'],
                $lItem['unit_rate'],
                $lItem['line_discount'],
                $lItem['base_amount'],
                $lItem['allocated_charge'],
                $lItem['landed_cost'],
                $lItem['landed_unit_rate']
            ]);

            // Weighted average cost update in Inventory Engine
            receiveInventoryItem(
                $db,
                $lItem['item_id'],
                $lItem['qty'],
                $lItem['landed_cost'],
                $purchaseId,
                $purchaseDate,
                $user['id'] ?? null
            );

            $totalInventoryCapitalized += $lItem['landed_cost'];
        }

        // 3. Insert Purchase Charges
        $chargeInsertStmt = $db->prepare("
            INSERT INTO `purchase_charges`
            (`purchase_id`, `charge_name`, `amount`, `payee_name`, `capitalize_inventory`, `allocation_method`)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        foreach ($preparedCharges as $pCharge) {
            $chargeInsertStmt->execute([
                $purchaseId,
                $pCharge['charge_name'],
                $pCharge['amount'],
                $pCharge['payee_name'],
                $pCharge['capitalize_inventory'],
                $pCharge['allocation_method']
            ]);
        }

        // 4. Construct Double-Entry Journal Entry
        // Resolve payment financial account
        $finAcctStmt = $db->prepare("SELECT coa_id FROM financial_accounts WHERE id = ?");
        $finAcctStmt->execute([$paymentAccountId]);
        $paymentCoaId = (int)($finAcctStmt->fetchColumn() ?: COA_CASH_MAIN);

        $journalLines = [];

        // Debit: Inventory Account (for total capitalized inventory value, including allocated labour/freight!)
        $journalLines[] = [
            'account_id' => COA_INVENTORY,
            'party_id' => null,
            'description' => "Inventory landed acquisition for Purchase #{$purchaseNo}",
            'debit' => $totalInventoryCapitalized,
            'credit' => 0.00
        ];

        // Non-capitalized charges (if any): Debit as ordinary operating expense
        $nonCapCharges = $totalCharges - $totalCapitalizedCharges;
        if ($nonCapCharges > 0) {
            $journalLines[] = [
                'account_id' => COA_LABOUR_EXPENSE,
                'party_id' => null,
                'description' => "Uncapitalized purchase handling expense #{$purchaseNo}",
                'debit' => $nonCapCharges,
                'credit' => 0.00
            ];
        }

        // Credit: Discount received (if any)
        if ($discount > 0) {
            $journalLines[] = [
                'account_id' => COA_SALES_DISCOUNT,
                'party_id' => null,
                'description' => "Purchase discount on #{$purchaseNo}",
                'debit' => 0.00,
                'credit' => $discount
            ];
        }

        // Credit: Supplier Payable (for remaining unpaid balance)
        if ($dueAmount > 0) {
            $journalLines[] = [
                'account_id' => COA_PAYABLE,
                'party_id' => $supplierId,
                'description' => "Payable for credit purchase #{$purchaseNo} from {$supplier['name']}",
                'debit' => 0.00,
                'credit' => $dueAmount
            ];
        }

        // Credit: Cash/Bank Account (for amount paid immediately)
        if ($paidAmount > 0) {
            $journalLines[] = [
                'account_id' => $paymentCoaId,
                'party_id' => null,
                'description' => "Immediate payment for Purchase #{$purchaseNo}",
                'debit' => 0.00,
                'credit' => $paidAmount
            ];
        }

        $journalHeader = [
            'entry_no' => getNextDocNo('JV', 'journal_entries', 'entry_no'),
            'entry_date' => $purchaseDate,
            'source_type' => 'purchase',
            'source_id' => $purchaseId,
            'narration' => "Purchase #{$purchaseNo} from {$supplier['name']}",
            'created_by' => $user['id'] ?? null
        ];

        $journalId = postJournalEntry($db, $journalHeader, $journalLines);

        // Update purchase header with journal ID
        $db->prepare("UPDATE `purchases` SET journal_id = ? WHERE id = ?")->execute([$journalId, $purchaseId]);

        // Create Payment Voucher if paid immediately
        if ($paidAmount > 0) {
            $db->prepare("
                INSERT INTO `vouchers`
                (`voucher_no`, `voucher_type`, `voucher_date`, `party_id`, `account_id`, `amount`, `payment_mode`, `narration`, `journal_id`, `status`, `created_by`, `created_at`)
                VALUES (?, 'PV', ?, ?, ?, ?, 'Cash', ?, ?, 'posted', ?, NOW())
            ")->execute([
                getNextDocNo('PV', 'vouchers', 'voucher_no'),
                $purchaseDate,
                $supplierId,
                $paymentAccountId,
                $paidAmount,
                "Payment against Purchase #{$purchaseNo}",
                $journalId,
                $user['id'] ?? null
            ]);
        }

        $db->commit();

        jsonSuccess('Purchase posted successfully with landed cost calculated!', [
            'id' => $purchaseId,
            'purchase_no' => $purchaseNo,
            'total_landed_cost' => $totalInventoryCapitalized,
            'grand_total' => $grandTotal,
            'paid_amount' => $paidAmount,
            'due_amount' => $dueAmount
        ]);

    } catch (Exception $e) {
        $db->rollBack();
        jsonError('Failed to save purchase: ' . $e->getMessage());
    }
}

jsonError('Invalid action requested.');
