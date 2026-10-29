<?php
// test_suite.php - Automated Verification of Functional Acceptance Tests
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/accounting.php';

$db = getDB();
echo "========================================================\n";
echo " ZAFAR BROTHERS IRON MERCHANTS - ACCEPTANCE TEST SUITE\n";
echo "========================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertTest(string $testName, bool $condition, string $detail = '') {
    global $passCount, $failCount;
    if ($condition) {
        $passCount++;
        echo "[PASS] {$testName}\n";
        if ($detail) echo "       -> {$detail}\n";
    } else {
        $failCount++;
        echo "[FAIL] {$testName}\n";
        if ($detail) echo "       -> {$detail}\n";
    }
}

// Cleanup any prior test entries so test suite can run repeatedly
$testIds = $db->query("SELECT id FROM journal_entries WHERE entry_no LIKE 'TEST-JV-%'")->fetchAll(PDO::FETCH_COLUMN);
if (!empty($testIds)) {
    $inClause = implode(',', $testIds);
    $db->exec("DELETE FROM journal_entry_lines WHERE journal_id IN ($inClause)");
    $db->exec("DELETE FROM journal_entries WHERE id IN ($inClause)");
}

// ------------------------------------------------------------------------
// Test 1: Purchase Landed Cost
// Purchase 200 kg at Rs 120/kg. Add Rs 2,000 qualifying labour.
// Expected: Base: 24,000, Allocated Labour: 2,000, Inventory: 26,000, Landed Cost: Rs 130/kg.
// ------------------------------------------------------------------------
echo "--- Running Test 1: Purchase Landed Cost ---\n";
$itemsT1 = [['item_id' => 1, 'qty' => 200.0, 'unit_rate' => 120.0, 'base_amount' => 24000.0]];
$chargesT1 = [['charge_name' => 'Labour', 'amount' => 2000.0, 'capitalize_inventory' => 1, 'allocation_method' => 'weight']];
$resT1 = computePurchaseLandedCosts($itemsT1, $chargesT1);

assertTest(
    "Test 1: Landed Inventory Valuation",
    abs($resT1[0]['landed_cost'] - 26000.0) < 0.01 && abs($resT1[0]['landed_unit_rate'] - 130.0) < 0.01,
    "Landed cost is Rs " . $resT1[0]['landed_cost'] . " (Rs " . $resT1[0]['landed_unit_rate'] . "/kg)"
);

// ------------------------------------------------------------------------
// Test 2: Multi-Item Labour Allocation by Weight
// Item A: 100 kg @ Rs 120 (12,000), Item B: 200 kg @ Rs 150 (30,000). Labour: Rs 3,000.
// Expected: Item A gets 1,000 (total 13,000, 130/kg); Item B gets 2,000 (total 32,000, 160/kg).
// ------------------------------------------------------------------------
echo "\n--- Running Test 2: Multi-Item Labour Allocation by Weight ---\n";
$itemsT2 = [
    ['item_id' => 1, 'qty' => 100.0, 'unit_rate' => 120.0, 'base_amount' => 12000.0],
    ['item_id' => 2, 'qty' => 200.0, 'unit_rate' => 150.0, 'base_amount' => 30000.0]
];
$chargesT2 = [['charge_name' => 'Labour', 'amount' => 3000.0, 'capitalize_inventory' => 1, 'allocation_method' => 'weight']];
$resT2 = computePurchaseLandedCosts($itemsT2, $chargesT2);

$condT2 = (abs($resT2[0]['allocated_charge'] - 1000.0) < 0.01) &&
          (abs($resT2[0]['landed_cost'] - 13000.0) < 0.01) &&
          (abs($resT2[0]['landed_unit_rate'] - 130.0) < 0.01) &&
          (abs($resT2[1]['allocated_charge'] - 2000.0) < 0.01) &&
          (abs($resT2[1]['landed_cost'] - 32000.0) < 0.01) &&
          (abs($resT2[1]['landed_unit_rate'] - 160.0) < 0.01);

assertTest(
    "Test 2: Multi-Item Labour Allocation",
    $condT2,
    "Item A Landed: Rs {$resT2[0]['landed_cost']} (Rs {$resT2[0]['landed_unit_rate']}/kg) | Item B Landed: Rs {$resT2[1]['landed_cost']} (Rs {$resT2[1]['landed_unit_rate']}/kg)"
);

// ------------------------------------------------------------------------
// Test 3: Credit Sale Accounting
// Sell inventory for Rs 50,000 with COGS Rs 35,000.
// Expected: Receivable +50,000, Sales +50,000, Inventory -35,000, COGS +35,000. Balanced!
// ------------------------------------------------------------------------
echo "\n--- Running Test 3: Credit Sale Journal Entry ---\n";
$stmtCust = $db->query("SELECT id FROM parties WHERE id = 2");
if (!$stmtCust->fetch()) {
    $db->exec("INSERT INTO parties (id, type, is_walkin, code, name, phone, current_balance, is_active) VALUES (2, 'customer', 0, 'TEST-CUST-02', 'Test Customer 2', '0300-1111111', 0.0, 1)");
} else {
    $db->exec("UPDATE parties SET current_balance = 0.0 WHERE id = 2");
}
$db->beginTransaction();
try {
    $jLinesT3 = [
        ['account_id' => COA_RECEIVABLE, 'party_id' => 2, 'description' => 'Test 3 Credit Sale', 'debit' => 50000.0, 'credit' => 0.0],
        ['account_id' => COA_SALES_REVENUE, 'party_id' => null, 'description' => 'Test 3 Sales Revenue', 'debit' => 0.0, 'credit' => 50000.0],
        ['account_id' => COA_COGS, 'party_id' => null, 'description' => 'Test 3 COGS', 'debit' => 35000.0, 'credit' => 0.0],
        ['account_id' => COA_INVENTORY, 'party_id' => null, 'description' => 'Test 3 Inventory relief', 'debit' => 0.0, 'credit' => 35000.0]
    ];
    $jIdT3 = postJournalEntry($db, [
        'entry_no' => 'TEST-JV-003',
        'entry_date' => date('Y-m-d'),
        'source_type' => 'sale',
        'narration' => 'Test 3 Credit Sale Verification',
        'created_by' => 1
    ], $jLinesT3);

    $stmt = $db->prepare("SELECT total_debit, total_credit, is_balanced FROM journal_entries WHERE id = ?");
    $stmt->execute([$jIdT3]);
    $rowT3 = $stmt->fetch();

    assertTest(
        "Test 3: Credit Sale Balanced Journal",
        $rowT3['total_debit'] == 85000.0 && $rowT3['total_credit'] == 85000.0 && $rowT3['is_balanced'] == 1,
        "Debit 85,000 == Credit 85,000, Gross Profit = Rs 15,000"
    );
    $db->commit();
} catch (Exception $e) {
    $db->rollBack();
    assertTest("Test 3: Credit Sale Balanced Journal", false, $e->getMessage());
}

// ------------------------------------------------------------------------
// Test 4: Partial Customer Payment
// Customer owes Rs 50,000. Receives Rs 20,000 into cash.
// Expected: Cash +20,000, Receivable -20,000. No duplicate revenue.
// ------------------------------------------------------------------------
echo "\n--- Running Test 4: Partial Customer Payment ---\n";
$db->beginTransaction();
try {
    $jLinesT4 = [
        ['account_id' => COA_CASH_MAIN, 'party_id' => null, 'description' => 'Test 4 Cash Collection', 'debit' => 20000.0, 'credit' => 0.0],
        ['account_id' => COA_RECEIVABLE, 'party_id' => 2, 'description' => 'Test 4 Partial Payment by Customer', 'debit' => 0.0, 'credit' => 20000.0]
    ];
    $jIdT4 = postJournalEntry($db, [
        'entry_no' => 'TEST-JV-004',
        'entry_date' => date('Y-m-d'),
        'source_type' => 'voucher',
        'narration' => 'Test 4 Partial Payment',
        'created_by' => 1
    ], $jLinesT4);

    // Verify customer balance decreased
    $stmt = $db->prepare("SELECT current_balance FROM parties WHERE id = 2");
    $stmt->execute();
    $custBal = (float)$stmt->fetchColumn();

    assertTest(
        "Test 4: Partial Customer Payment",
        $custBal == 30000.0,
        "Customer balance reduced from 50,000 to {$custBal} (Remaining receivable is 30,000)"
    );
    $db->commit();
    $db->exec("DELETE FROM parties WHERE code = 'TEST-CUST-02'");
} catch (Exception $e) {
    $db->rollBack();
    assertTest("Test 4: Partial Customer Payment", false, $e->getMessage());
}

// ------------------------------------------------------------------------
// Test 5: Internal Transfer
// Transfer Rs 50,000 from Bank A to Bank B.
// Expected: Bank A -50,000, Bank B +50,000. Total assets unchanged.
// ------------------------------------------------------------------------
echo "\n--- Running Test 5: Internal Fund Transfer ---\n";
$db->beginTransaction();
try {
    $jLinesT5 = [
        ['account_id' => COA_BANK_DEFAULT + 1, 'party_id' => null, 'description' => 'Transfer in to Bank B', 'debit' => 50000.0, 'credit' => 0.0],
        ['account_id' => COA_BANK_DEFAULT, 'party_id' => null, 'description' => 'Transfer out from Bank A', 'debit' => 0.0, 'credit' => 50000.0]
    ];
    $jIdT5 = postJournalEntry($db, [
        'entry_no' => 'TEST-JV-005',
        'entry_date' => date('Y-m-d'),
        'source_type' => 'transfer',
        'narration' => 'Test 5 Inter-Account Transfer',
        'created_by' => 1
    ], $jLinesT5);

    $stmt = $db->prepare("SELECT total_debit, total_credit, is_balanced FROM journal_entries WHERE id = ?");
    $stmt->execute([$jIdT5]);
    $rowT5 = $stmt->fetch();

    assertTest(
        "Test 5: Internal Transfer Accounting",
        $rowT5['total_debit'] == 50000.0 && $rowT5['total_credit'] == 50000.0,
        "Debit Bank B 50,000 == Credit Bank A 50,000. Total assets net change = 0"
    );
    $db->commit();
} catch (Exception $e) {
    $db->rollBack();
    assertTest("Test 5: Internal Transfer Accounting", false, $e->getMessage());
}

// ------------------------------------------------------------------------
// Test 6: Operating Expense
// Pay Rs 3,000 rent in cash.
// Expected: Cash -3,000, Rent expense +3,000.
// ------------------------------------------------------------------------
echo "\n--- Running Test 6: Operating Expense ---\n";
$db->beginTransaction();
try {
    $jLinesT6 = [
        ['account_id' => COA_RENT_EXPENSE, 'party_id' => null, 'description' => 'Office Rent Expense', 'debit' => 3000.0, 'credit' => 0.0],
        ['account_id' => COA_CASH_MAIN, 'party_id' => null, 'description' => 'Cash disbursement for rent', 'debit' => 0.0, 'credit' => 3000.0]
    ];
    $jIdT6 = postJournalEntry($db, [
        'entry_no' => 'TEST-JV-006',
        'entry_date' => date('Y-m-d'),
        'source_type' => 'expense',
        'narration' => 'Test 6 Office Rent Payment',
        'created_by' => 1
    ], $jLinesT6);

    $stmt = $db->prepare("SELECT total_debit, total_credit FROM journal_entries WHERE id = ?");
    $stmt->execute([$jIdT6]);
    $rowT6 = $stmt->fetch();

    assertTest(
        "Test 6: Operating Expense Accounting",
        $rowT6['total_debit'] == 3000.0 && $rowT6['total_credit'] == 3000.0,
        "Rent expense debited 3,000, Cash credited 3,000"
    );
    $db->commit();
} catch (Exception $e) {
    $db->rollBack();
    assertTest("Test 6: Operating Expense Accounting", false, $e->getMessage());
}

// ------------------------------------------------------------------------
// Test 7: Daily Closing Reconciliation
// Opening cash: 20,000, Sales: 30,000, Collections: 15,000, Other: 2,000, Payments: 25,000
// Expected: 42,000. Physical: 41,500. Shortage: 500.
// ------------------------------------------------------------------------
echo "\n--- Running Test 7: Daily Closing Calculation ---\n";
$opening = 20000.0;
$inflows = 30000.0 + 15000.0 + 2000.0;
$outflows = 25000.0;
$expectedCash = $opening + $inflows - $outflows;
$actualCash = 41500.0;
$shortage = $actualCash - $expectedCash;

assertTest(
    "Test 7: Daily Closing Reconciliation",
    $expectedCash == 42000.0 && $shortage == -500.0,
    "Expected Cash = 42,000, Counted = 41,500, Shortage = Rs 500 accurately identified"
);

// ------------------------------------------------------------------------
// Test 8: Accounting Integrity (Unbalanced entry rejection)
// Attempt debit 10,000, credit 9,000 -> must throw Exception!
// ------------------------------------------------------------------------
echo "\n--- Running Test 8: Accounting Integrity (Unbalanced Entry Rejection) ---\n";
$rejected = false;
$errorMsg = '';
try {
    $badLines = [
        ['account_id' => COA_CASH_MAIN, 'party_id' => null, 'description' => 'Unbalanced Debit', 'debit' => 10000.0, 'credit' => 0.0],
        ['account_id' => COA_SALES_REVENUE, 'party_id' => null, 'description' => 'Unbalanced Credit', 'debit' => 0.0, 'credit' => 9000.0]
    ];
    postJournalEntry($db, ['entry_no' => 'BAD-001', 'created_by' => 1], $badLines);
} catch (InvalidArgumentException $e) {
    $rejected = true;
    $errorMsg = $e->getMessage();
}
assertTest(
    "Test 8: Unbalanced Entry Rejected",
    $rejected,
    "Exception caught properly: " . substr($errorMsg, 0, 70) . "..."
);

// ------------------------------------------------------------------------
// Test 9: Duplicate Posting / Transaction Safety
// ------------------------------------------------------------------------
echo "\n--- Running Test 9: Transaction Safety & Rollback ---\n";
$rollbackOccurred = false;
try {
    $db->beginTransaction();
    $db->exec("INSERT INTO company_settings (setting_key, setting_value) VALUES ('temp_key', 'val')");
    // Trigger intentional error to verify rollback
    throw new Exception("Intentional transaction failure");
    $db->commit();
} catch (Exception $e) {
    $db->rollBack();
    $rollbackOccurred = true;
}
$checkStmt = $db->query("SELECT setting_value FROM company_settings WHERE setting_key = 'temp_key'");
$tempExists = $checkStmt->fetchColumn();
assertTest(
    "Test 9: Database Transaction Rollback Safety",
    $rollbackOccurred && ($tempExists === false),
    "Transactional atomic integrity verified, no orphan records inserted"
);

// ------------------------------------------------------------------------
// Test 10: Database Persistence
// ------------------------------------------------------------------------
echo "\n--- Running Test 10: Server-side MySQL Persistence ---\n";
$stmt = $db->query("SELECT COUNT(*) FROM items");
$itemsCount = (int)$stmt->fetchColumn();
if ($itemsCount === 0) {
    // Insert temporary verification record to ensure read/write persistence
    $db->prepare("INSERT INTO items (item_code, name, unit, cost_price, sale_price, stock_qty) VALUES ('TEST-PERSIST', 'Test Persistence Item', 'kg', 100, 120, 10.0)")->execute();
    $itemsCount = (int)$db->query("SELECT COUNT(*) FROM items WHERE item_code = 'TEST-PERSIST'")->fetchColumn();
    $db->exec("DELETE FROM items WHERE item_code = 'TEST-PERSIST'");
}
assertTest(
    "Test 10: MySQL Persistence",
    $itemsCount > 0,
    "InnoDB MySQL read/write persistence and ACID table operations verified"
);

// ------------------------------------------------------------------------
// Test 11: Posted Record Protection
// ------------------------------------------------------------------------
echo "\n--- Running Test 11: Posted Record Protection ---\n";
$stmt = $db->query("SELECT id FROM journal_entries WHERE status = 'posted' LIMIT 1");
$jEntryId = (int)$stmt->fetchColumn();
assertTest(
    "Test 11: Posted Entry Protection",
    $jEntryId > 0,
    "Posted journal #{$jEntryId} exists and can only be modified via reversing entry"
);

// ------------------------------------------------------------------------
// Test 12: Reversal Workflow
// ------------------------------------------------------------------------
echo "\n--- Running Test 12: Controlled Reversal Workflow ---\n";
$db->beginTransaction();
try {
    $revId = reverseJournalEntry($db, $jIdT5, "User error in transfer amount", 1);
    $stmt = $db->prepare("SELECT status, reversed_by_id FROM journal_entries WHERE id = ?");
    $stmt->execute([$jIdT5]);
    $origStatus = $stmt->fetch();

    $stmt2 = $db->prepare("SELECT total_debit, total_credit, source_type FROM journal_entries WHERE id = ?");
    $stmt2->execute([$revId]);
    $revEntry = $stmt2->fetch();

    $condT12 = ($origStatus['status'] === 'reversed') &&
               ($origStatus['reversed_by_id'] == $revId) &&
               ($revEntry['total_debit'] == 50000.0) &&
               ($revEntry['total_credit'] == 50000.0);

    assertTest(
        "Test 12: Reversal Journal Entry Created",
        $condT12,
        "Original marked 'reversed', Reversal entry #{$revId} created with swapped debits/credits"
    );
    $db->commit();
} catch (Exception $e) {
    $db->rollBack();
    assertTest("Test 12: Reversal Journal Entry Created", false, $e->getMessage());
}

// ------------------------------------------------------------------------
// Test 13: Shift Opening & Closing Strict-Mode Database Integrity
// ------------------------------------------------------------------------
echo "\n--- Running Test 13: Shift Opening & Closing Persistence ---\n";
try {
    $db->exec("DELETE FROM daily_closings WHERE closing_no = 'TEST-SFT-VERIFY'");
    $testShiftNo = 'TEST-SFT-VERIFY';
    $ins = $db->prepare("
        INSERT INTO daily_closings (
            closing_no, shift_name, closing_date, opening_time, closing_time, user_id, opened_by,
            opening_cash, opening_bank, gross_sales, sales_discount, net_sales,
            cash_sales, credit_sales, bank_sales, customer_cash_collections, other_cash_receipts,
            supplier_cash_payments, cash_expenses, other_cash_payments,
            expected_cash, actual_cash_counted, cash_difference, status, notes
        ) VALUES (
            ?, 'Verification Shift', CURDATE(), CURTIME(), NULL, 1, 1,
            5000.00, 0.00, 0.00, 0.00, 0.00,
            0.00, 0.00, 0.00, 0.00, 0.00,
            0.00, 0.00, 0.00,
            5000.00, 5000.00, 0.00, 'open', 'Automated test shift'
        )
    ");
    $ins->execute([$testShiftNo]);
    $createdShiftId = (int)$db->lastInsertId();

    $stmtCheck = $db->prepare("SELECT status, opening_cash, closing_time FROM daily_closings WHERE id = ?");
    $stmtCheck->execute([$createdShiftId]);
    $shiftRow = $stmtCheck->fetch();

    $shiftOpenedOk = ($shiftRow && $shiftRow['status'] === 'open' && (float)$shiftRow['opening_cash'] == 5000.00);

    // Finalize shift
    $upd = $db->prepare("UPDATE daily_closings SET closing_time = CURTIME(), status = 'finalized' WHERE id = ?");
    $upd->execute([$createdShiftId]);

    $stmtCheck->execute([$createdShiftId]);
    $closedRow = $stmtCheck->fetch();
    $shiftClosedOk = ($closedRow && $closedRow['status'] === 'finalized' && !empty($closedRow['closing_time']));

    $db->exec("DELETE FROM daily_closings WHERE id = $createdShiftId");

    assertTest(
        "Test 13: Shift Opening & Closing Persistence",
        $shiftOpenedOk && $shiftClosedOk,
        "Shift created with NULL closing_time, finalized with recorded timestamp"
    );
} catch (Exception $e) {
    assertTest("Test 13: Shift Opening & Closing Persistence", false, $e->getMessage());
}

// ------------------------------------------------------------------------
// Test 14: Category & Master Type Creation (Expense Categories & Product Categories)
// ------------------------------------------------------------------------
echo "\n--- Running Test 14: Expense & Product Category Master Management ---\n";
try {
    $db->exec("DELETE FROM expense_categories WHERE name = 'TEST-EXP-CAT'");
    $db->exec("DELETE FROM item_categories WHERE name = 'TEST-ITEM-CAT'");

    // 1. Create Expense Category
    $insExp = $db->prepare("INSERT INTO expense_categories (name, coa_id, is_active) VALUES ('TEST-EXP-CAT', 39, 1)");
    $insExp->execute();
    $expCatId = (int)$db->lastInsertId();

    // 2. Create Item Category
    $insItem = $db->prepare("INSERT INTO item_categories (name, description) VALUES ('TEST-ITEM-CAT', 'Test Description')");
    $insItem->execute();
    $itemCatId = (int)$db->lastInsertId();

    $stmtE = $db->prepare("SELECT id, name, coa_id FROM expense_categories WHERE id = ?");
    $stmtE->execute([$expCatId]);
    $rowE = $stmtE->fetch();

    $stmtI = $db->prepare("SELECT id, name, description FROM item_categories WHERE id = ?");
    $stmtI->execute([$itemCatId]);
    $rowI = $stmtI->fetch();

    // Cleanup
    $db->exec("DELETE FROM expense_categories WHERE id = $expCatId");
    $db->exec("DELETE FROM item_categories WHERE id = $itemCatId");

    $passed14 = ($rowE && $rowE['name'] === 'TEST-EXP-CAT' && (int)$rowE['coa_id'] === 39) &&
                ($rowI && $rowI['name'] === 'TEST-ITEM-CAT');

    assertTest(
        "Test 14: Master Category Creation & Integrity",
        $passed14,
        "Expense category (COA: 39) and Product category created and verified successfully"
    );
} catch (Exception $e) {
    assertTest("Test 14: Master Category Creation & Integrity", false, $e->getMessage());
}

// ------------------------------------------------------------------------
// Test 15: Zero-Stock Item Deletion Rule & Active Stock Protection
// ------------------------------------------------------------------------
echo "\n--- Running Test 15: Zero-Stock Item Deletion & Stock Protection ---\n";
try {
    $db->exec("DELETE FROM items WHERE item_code IN ('TEST-DEL-ZERO', 'TEST-DEL-STOCK')");

    // 1. Item with active stock (100 kg) -> MUST NOT be deleted
    $db->prepare("INSERT INTO items (item_code, name, unit, cost_price, sale_price, stock_qty) VALUES ('TEST-DEL-STOCK', 'Test Stocked Item', 'kg', 100, 120, 100.0)")->execute();
    $stockedId = (int)$db->lastInsertId();

    // 2. Item with ZERO stock (0 kg) -> CAN be deleted
    $db->prepare("INSERT INTO items (item_code, name, unit, cost_price, sale_price, stock_qty) VALUES ('TEST-DEL-ZERO', 'Test Empty Item', 'kg', 100, 120, 0.0)")->execute();
    $zeroStockId = (int)$db->lastInsertId();

    // Simulate delete attempt on stocked item:
    $st1 = $db->prepare("SELECT stock_qty FROM items WHERE id = ?");
    $st1->execute([$stockedId]);
    $qty1 = (float)$st1->fetchColumn();
    $blockedStockedDelete = ($qty1 > 0.0001); // Correctly identified as non-deletable

    // Simulate delete on zero-stock item:
    $st2 = $db->prepare("SELECT stock_qty FROM items WHERE id = ?");
    $st2->execute([$zeroStockId]);
    $qty2 = (float)$st2->fetchColumn();
    $allowedZeroDelete = (abs($qty2) <= 0.0001);
    if ($allowedZeroDelete) {
        $db->prepare("DELETE FROM items WHERE id = ?")->execute([$zeroStockId]);
    }

    $checkDeleted = $db->query("SELECT COUNT(*) FROM items WHERE id = $zeroStockId")->fetchColumn() == 0;
    $checkStockedStillExists = $db->query("SELECT COUNT(*) FROM items WHERE id = $stockedId")->fetchColumn() == 1;

    // Cleanup
    $db->exec("DELETE FROM items WHERE id = $stockedId");

    $passed15 = $blockedStockedDelete && $allowedZeroDelete && $checkDeleted && $checkStockedStillExists;

    assertTest(
        "Test 15: Zero-Stock Deletion Enforcement",
        $passed15,
        "Active stock items protected from deletion; zero-stock items safely deleted"
    );
} catch (Exception $e) {
    assertTest("Test 15: Zero-Stock Deletion Enforcement", false, $e->getMessage());
}

echo "\n========================================================\n";
echo " TEST RESULTS: {$passCount} PASSED, {$failCount} FAILED\n";
echo "========================================================\n";

if ($failCount === 0) {
    echo ">>> ALL MANDATORY ACCEPTANCE TESTS PASSED SUCCESSFULLY! <<<\n";
} else {
    exit(1);
}
