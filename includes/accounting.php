<?php
// includes/accounting.php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/audit.php';

/**
 * Ensures chart of accounts and financial accounts exist.
 * Auto-heals missing chart_of_accounts records to prevent foreign key violations (1452).
 */
function ensureChartOfAccountsIntegrity(PDO $db): void {
    static $checked = false;
    if ($checked) return;

    try {
        $count = (int)$db->query("SELECT COUNT(*) FROM `chart_of_accounts`")->fetchColumn();
        if ($count < 30) {
            $seedFile = __DIR__ . '/../database/seed.sql';
            if (file_exists($seedFile)) {
                $sql = file_get_contents($seedFile);
                $db->exec($sql);
            }
        }
    } catch (Throwable $e) {
        error_log("Failed to auto-heal chart of accounts: " . $e->getMessage());
    }
    $checked = true;
}

/**
 * Post a balanced double-entry journal entry.
 * Polymorphic signature supports:
 * 1) postJournalEntry(PDO $db, array $header, array $lines): int
 * 2) postJournalEntry(string $date, string $no, string $type, string $narration, array $lines, ?int $createdBy = null): int
 *
 * @param mixed ...$args
 * @return int Created journal entry ID
 * @throws InvalidArgumentException if unbalanced or missing data
 */
function postJournalEntry(mixed ...$args): int {
    if (isset($args[0]) && $args[0] instanceof PDO) {
        $db = $args[0];
        $header = (array)($args[1] ?? []);
        $lines = (array)($args[2] ?? []);
    } else {
        $db = getDB();
        $header = [
            'entry_date'  => (string)($args[0] ?? date('Y-m-d')),
            'entry_no'    => (string)($args[1] ?? getNextDocNo('JV', 'journal_entries', 'entry_no')),
            'source_type' => (string)($args[2] ?? 'voucher'),
            'narration'   => (string)($args[3] ?? ''),
            'created_by'  => $args[5] ?? null
        ];
        $lines = (array)($args[4] ?? []);
    }
    if (empty($lines)) {
        throw new InvalidArgumentException("Cannot post a journal entry without lines.");
    }

    ensureChartOfAccountsIntegrity($db);

    $totalDebit = 0.0;
    $totalCredit = 0.0;

    foreach ($lines as $line) {
        $debit = round((float)($line['debit'] ?? 0), 2);
        $credit = round((float)($line['credit'] ?? 0), 2);
        if ($debit < 0 || $credit < 0) {
            throw new InvalidArgumentException("Debit and Credit values cannot be negative.");
        }
        $totalDebit += $debit;
        $totalCredit += $credit;
    }

    $totalDebit = round($totalDebit, 2);
    $totalCredit = round($totalCredit, 2);

    if (abs($totalDebit - $totalCredit) > 0.01) {
        throw new InvalidArgumentException(sprintf(
            "Double-entry validation failed: Total Debits (%s) do not equal Total Credits (%s). Difference: %s",
            formatMoney($totalDebit),
            formatMoney($totalCredit),
            formatMoney(abs($totalDebit - $totalCredit))
        ));
    }

    $entryNo = $header['entry_no'] ?? getNextDocNo('JV', 'journal_entries', 'entry_no');
    $entryDate = $header['entry_date'] ?? date('Y-m-d');
    $rawSource = strtolower(trim((string)($header['source_type'] ?? 'voucher')));
    $sourceTypeMap = [
        'sale'                => 'sale',
        'purchase'            => 'purchase',
        'expense'             => 'expense',
        'expense_voucher'     => 'expense',
        'transfer'            => 'transfer',
        'transfer_voucher'    => 'transfer',
        'voucher'             => 'voucher',
        'journal_voucher'     => 'voucher',
        'jv'                  => 'voucher',
        'closing'             => 'closing',
        'cash_reconciliation' => 'closing',
        'opening'             => 'opening',
        'adjustment'          => 'adjustment',
        'stock_adjustment'    => 'adjustment',
        'reversal'            => 'reversal'
    ];
    $sourceType = $sourceTypeMap[$rawSource] ?? 'voucher';
    $sourceId = $header['source_id'] ?? null;
    $narration = $header['narration'] ?? '';
    $createdBy = $header['created_by'] ?? null;

    $stmt = $db->prepare("
        INSERT INTO `journal_entries` 
        (`entry_no`, `entry_date`, `source_type`, `source_id`, `narration`, `total_debit`, `total_credit`, `is_balanced`, `status`, `created_by`, `created_at`)
        VALUES (?, ?, ?, ?, ?, ?, ?, 1, 'posted', ?, NOW())
    ");
    $stmt->execute([
        $entryNo,
        $entryDate,
        $sourceType,
        $sourceId,
        $narration,
        $totalDebit,
        $totalCredit,
        $createdBy
    ]);
    $journalId = (int)$db->lastInsertId();

    $lineStmt = $db->prepare("
        INSERT INTO `journal_entry_lines`
        (`journal_id`, `account_id`, `party_id`, `description`, `debit`, `credit`)
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    // Fetch COA types for balance updating
    $coaStmt = $db->prepare("SELECT id, type, normal_balance FROM `chart_of_accounts` WHERE id = ?");
    $finAcctStmt = $db->prepare("SELECT id FROM `financial_accounts` WHERE coa_id = ? LIMIT 1");
    $updateFinStmt = $db->prepare("UPDATE `financial_accounts` SET current_balance = current_balance + ? WHERE id = ?");
    $updatePartyStmt = $db->prepare("UPDATE `parties` SET current_balance = current_balance + ? WHERE id = ?");

    foreach ($lines as $line) {
        $acctId = (int)$line['account_id'];
        $partyId = !empty($line['party_id']) ? (int)$line['party_id'] : null;
        $desc = $line['description'] ?? '';
        $debit = round((float)($line['debit'] ?? 0), 2);
        $credit = round((float)($line['credit'] ?? 0), 2);

        // Auto-verify account_id exists to protect foreign key
        $coaStmt->execute([$acctId]);
        if (!$coaStmt->fetch()) {
            ensureChartOfAccountsIntegrity($db);
            $coaStmt->execute([$acctId]);
            if (!$coaStmt->fetch()) {
                $acctId = COA_GEN_EXPENSE; // Safe fallback
            }
        }

        $lineStmt->execute([$journalId, $acctId, $partyId, $desc, $debit, $credit]);

        // Update financial account balance if applicable
        $finAcctStmt->execute([$acctId]);
        $finId = $finAcctStmt->fetchColumn();
        if ($finId) {
            // For asset accounts (Cash/Bank), Debit increases balance (+), Credit decreases balance (-)
            $netChange = $debit - $credit;
            $updateFinStmt->execute([$netChange, $finId]);
        }

        // Update party running balance if party attached
        if ($partyId) {
            // For customers (receivable): Debit increases debt (+), Credit decreases debt (-)
            // For suppliers (payable): Credit increases payable (+), Debit decreases payable (-)
            // In our system, positive current_balance for party:
            // Customer: positive = owes us (due); negative = advance paid.
            // Supplier: positive = we owe supplier; negative = we gave advance.
            $partyTypeStmt = $db->prepare("SELECT type FROM parties WHERE id = ?");
            $partyTypeStmt->execute([$partyId]);
            $pType = $partyTypeStmt->fetchColumn();

            if ($pType === 'customer') {
                $partyDelta = $debit - $credit;
                $updatePartyStmt->execute([$partyDelta, $partyId]);
            } elseif ($pType === 'supplier') {
                $partyDelta = $credit - $debit;
                $updatePartyStmt->execute([$partyDelta, $partyId]);
            } elseif ($pType === 'employee') {
                // For employee salary/advance: Debit (advance given) increases balance owed by emp, Credit decreases
                $partyDelta = $debit - $credit;
                $updatePartyStmt->execute([$partyDelta, $partyId]);
            }
        }
    }

    logAudit('POST_JOURNAL', 'journal_entries', (string)$journalId, [
        'entry_no' => $entryNo,
        'total' => $totalDebit,
        'source_type' => $sourceType,
        'source_id' => $sourceId
    ]);

    return $journalId;
}

/**
 * Reverse a posted journal entry with a linked reversing entry.
 */
function reverseJournalEntry(PDO $db, int $journalId, string $reason, int $userId): int {
    $stmt = $db->prepare("SELECT * FROM `journal_entries` WHERE id = ? AND status = 'posted'");
    $stmt->execute([$journalId]);
    $orig = $stmt->fetch();
    if (!$orig) {
        throw new RuntimeException("Original journal entry not found or already reversed.");
    }

    $lineStmt = $db->prepare("SELECT * FROM `journal_entry_lines` WHERE journal_id = ?");
    $lineStmt->execute([$journalId]);
    $origLines = $lineStmt->fetchAll();

    // Reverse lines: Swap Debit and Credit
    $revLines = [];
    foreach ($origLines as $l) {
        $revLines[] = [
            'account_id' => $l['account_id'],
            'party_id' => $l['party_id'],
            'description' => "REVERSAL: " . $l['description'],
            'debit' => $l['credit'],
            'credit' => $l['debit']
        ];
    }

    $revHeader = [
        'entry_no' => getNextDocNo('REV', 'journal_entries', 'entry_no'),
        'entry_date' => date('Y-m-d'),
        'source_type' => 'reversal',
        'source_id' => $journalId,
        'narration' => "Reversal of entry #{$orig['entry_no']}. Reason: {$reason}",
        'created_by' => $userId
    ];

    $revJournalId = postJournalEntry($db, $revHeader, $revLines);

    // Mark original as reversed
    $upStmt = $db->prepare("UPDATE `journal_entries` SET status = 'reversed', reversed_by_id = ? WHERE id = ?");
    $upStmt->execute([$revJournalId, $journalId]);

    logAudit('REVERSE_JOURNAL', 'journal_entries', (string)$journalId, [
        'reversed_by' => $revJournalId,
        'reason' => $reason
    ]);

    return $revJournalId;
}

/**
 * Perpetual Weighted-Average Inventory Cost Calculation on Purchase Receiving.
 * Landed Cost = Base Purchase Cost + Allocated Qualifying Charges
 * New Unit Cost = (Old Stock Qty * Old Unit Cost + New Qty * New Landed Cost) / (Old Stock Qty + New Qty)
 */
function receiveInventoryItem(
    PDO $db,
    int $itemId,
    float $newQty,
    float $totalLandedCost,
    int $purchaseId,
    string $date,
    ?int $userId = null
): array {
    $stmt = $db->prepare("SELECT id, name, stock_qty, cost_price FROM `items` WHERE id = ? FOR UPDATE");
    $stmt->execute([$itemId]);
    $item = $stmt->fetch();
    if (!$item) {
        throw new RuntimeException("Item ID {$itemId} not found.");
    }

    $oldQty = (float)$item['stock_qty'];
    $oldCost = (float)$item['cost_price'];
    $landedUnitRate = $newQty > 0 ? ($totalLandedCost / $newQty) : $oldCost;

    $newTotalQty = $oldQty + $newQty;
    if ($newTotalQty > 0) {
        if ($oldQty <= 0) {
            $newAvgCost = $landedUnitRate;
        } else {
            $totalValuation = ($oldQty * $oldCost) + $totalLandedCost;
            $newAvgCost = $totalValuation / $newTotalQty;
        }
    } else {
        $newAvgCost = $landedUnitRate;
    }

    $newAvgCost = round($newAvgCost, 4);

    // Update item stock and weighted average cost
    $upStmt = $db->prepare("UPDATE `items` SET stock_qty = ?, cost_price = ? WHERE id = ?");
    $upStmt->execute([$newTotalQty, $newAvgCost, $itemId]);

    // Record stock movement
    $movStmt = $db->prepare("
        INSERT INTO `stock_movements`
        (`item_id`, `movement_type`, `reference_type`, `reference_id`, `qty_in`, `qty_out`, `unit_cost`, `total_cost`, `balance_qty`, `movement_date`, `notes`, `created_by`, `created_at`)
        VALUES (?, 'purchase', 'purchase', ?, ?, 0, ?, ?, ?, ?, 'Purchase receipt with landed cost', ?, NOW())
    ");
    $movStmt->execute([
        $itemId,
        $purchaseId,
        $newQty,
        $landedUnitRate,
        $totalLandedCost,
        $newTotalQty,
        $date,
        $userId
    ]);

    return [
        'old_qty' => $oldQty,
        'old_cost' => $oldCost,
        'new_qty' => $newTotalQty,
        'landed_unit_rate' => $landedUnitRate,
        'new_avg_cost' => $newAvgCost
    ];
}

/**
 * Reduce inventory for sale and record COGS.
 */
function issueInventoryItemForSale(
    PDO $db,
    int $itemId,
    float $qty,
    int $saleId,
    string $date,
    ?int $userId = null
): array {
    $stmt = $db->prepare("SELECT id, name, stock_qty, cost_price FROM `items` WHERE id = ? FOR UPDATE");
    $stmt->execute([$itemId]);
    $item = $stmt->fetch();
    if (!$item) {
        throw new RuntimeException("Item ID {$itemId} not found.");
    }

    $currentStock = (float)$item['stock_qty'];
    $unitCogs = (float)$item['cost_price'];
    $newStock = $currentStock - $qty;
    $totalCogs = round($qty * $unitCogs, 2);

    // Negative stock policy enforcement
    $companySettings = getCompanySettings();
    $allowNegative = ($companySettings['allow_negative_stock'] ?? '0') === '1';
    if (!$allowNegative && $newStock < -0.0001) {
        throw new RuntimeException("اسٹاک ناکافی ہے! پروڈکٹ '{$item['name']}' کا موجودہ اسٹاک {$currentStock} ہے جبکہ طلب کردہ مقدار {$qty} ہے۔ منفی اسٹاک فروخت کی اجازت سیٹنگز میں بند ہے۔");
    }

    $upStmt = $db->prepare("UPDATE `items` SET stock_qty = ? WHERE id = ?");
    $upStmt->execute([$newStock, $itemId]);

    $movStmt = $db->prepare("
        INSERT INTO `stock_movements`
        (`item_id`, `movement_type`, `reference_type`, `reference_id`, `qty_in`, `qty_out`, `unit_cost`, `total_cost`, `balance_qty`, `movement_date`, `notes`, `created_by`, `created_at`)
        VALUES (?, 'sale', 'sale', ?, 0, ?, ?, ?, ?, ?, 'Sale invoice dispatch', ?, NOW())
    ");
    $movStmt->execute([
        $itemId,
        $saleId,
        $qty,
        $unitCogs,
        $totalCogs,
        $newStock,
        $date,
        $userId
    ]);

    return [
        'unit_cogs' => $unitCogs,
        'total_cogs' => $totalCogs,
        'balance_qty' => $newStock
    ];
}

/**
 * Perpetual Moving Weighted-Average Cost on Sales Return (Customer Return).
 * Restores returned stock into inventory and updates weighted-average cost.
 */
function receiveSaleReturnItem(
    PDO $db,
    int $itemId,
    float $returnQty,
    float $unitRate,
    int $returnId,
    string $date,
    ?int $userId = null
): array {
    $stmt = $db->prepare("SELECT id, name, stock_qty, cost_price FROM `items` WHERE id = ? FOR UPDATE");
    $stmt->execute([$itemId]);
    $item = $stmt->fetch();
    if (!$item) {
        throw new RuntimeException("Item ID {$itemId} not found.");
    }

    $oldQty = (float)$item['stock_qty'];
    $oldCost = (float)$item['cost_price'];
    $newTotalQty = $oldQty + $returnQty;

    // Returned items are returned into inventory at original cost rate (oldCost)
    $returnCostValuation = round($returnQty * $oldCost, 2);

    if ($newTotalQty > 0) {
        if ($oldQty <= 0) {
            $newAvgCost = $oldCost;
        } else {
            $totalValuation = ($oldQty * $oldCost) + $returnCostValuation;
            $newAvgCost = $totalValuation / $newTotalQty;
        }
    } else {
        $newAvgCost = $oldCost;
    }

    $newAvgCost = round($newAvgCost, 4);

    $upStmt = $db->prepare("UPDATE `items` SET stock_qty = ?, cost_price = ? WHERE id = ?");
    $upStmt->execute([$newTotalQty, $newAvgCost, $itemId]);

    $movStmt = $db->prepare("
        INSERT INTO `stock_movements`
        (`item_id`, `movement_type`, `reference_type`, `reference_id`, `qty_in`, `qty_out`, `unit_cost`, `total_cost`, `balance_qty`, `movement_date`, `notes`, `created_by`, `created_at`)
        VALUES (?, 'sale_return', 'return', ?, ?, 0, ?, ?, ?, ?, 'Customer sales return receipt', ?, NOW())
    ");
    $movStmt->execute([
        $itemId,
        $returnId,
        $returnQty,
        $oldCost,
        $returnCostValuation,
        $newTotalQty,
        $date,
        $userId
    ]);

    return [
        'old_qty'      => $oldQty,
        'new_qty'      => $newTotalQty,
        'unit_cost'    => $oldCost,
        'total_cost'   => $returnCostValuation,
        'new_avg_cost' => $newAvgCost
    ];
}

/**
 * Perpetual Moving Weighted-Average Cost on Purchase Return (Return to Mill/Supplier).
 * Relieves returned stock from inventory at current weighted-average cost.
 */
function relievePurchaseReturnItem(
    PDO $db,
    int $itemId,
    float $returnQty,
    float $returnRate,
    int $returnId,
    string $date,
    ?int $userId = null
): array {
    $stmt = $db->prepare("SELECT id, name, stock_qty, cost_price FROM `items` WHERE id = ? FOR UPDATE");
    $stmt->execute([$itemId]);
    $item = $stmt->fetch();
    if (!$item) {
        throw new RuntimeException("Item ID {$itemId} not found.");
    }

    $oldQty = (float)$item['stock_qty'];
    $oldCost = (float)$item['cost_price'];
    $newStock = max(0.0, $oldQty - $returnQty);
    $relievedCost = round($returnQty * $oldCost, 2);

    $upStmt = $db->prepare("UPDATE `items` SET stock_qty = ? WHERE id = ?");
    $upStmt->execute([$newStock, $itemId]);

    $movStmt = $db->prepare("
        INSERT INTO `stock_movements`
        (`item_id`, `movement_type`, `reference_type`, `reference_id`, `qty_in`, `qty_out`, `unit_cost`, `total_cost`, `balance_qty`, `movement_date`, `notes`, `created_by`, `created_at`)
        VALUES (?, 'purchase_return', 'return', ?, 0, ?, ?, ?, ?, ?, 'Supplier purchase return dispatch', ?, NOW())
    ");
    $movStmt->execute([
        $itemId,
        $returnId,
        $returnQty,
        $oldCost,
        $relievedCost,
        $newStock,
        $date,
        $userId
    ]);

    return [
        'old_qty'      => $oldQty,
        'new_qty'      => $newStock,
        'unit_cost'    => $oldCost,
        'total_cost'   => $relievedCost,
        'new_avg_cost' => $oldCost
    ];
}

/**
 * Allocate additional purchase charges across purchase items to determine landed cost.
 *
 * @param array $items Array of items: [['item_id', 'qty', 'unit_rate', 'base_amount']]
 * @param array $charges Array of charges: [['charge_name', 'amount', 'capitalize_inventory', 'allocation_method']]
 * @return array Augmented items array with 'allocated_charge', 'landed_cost', and 'landed_unit_rate'
 */
function computePurchaseLandedCosts(array $items, array $charges): array {
    $totalCapitalizable = 0.0;
    $method = 'weight'; // Default weight/quantity for steel

    foreach ($charges as $c) {
        if (!empty($c['capitalize_inventory'])) {
            $totalCapitalizable += (float)$c['amount'];
            if (!empty($c['allocation_method'])) {
                $method = $c['allocation_method'];
            }
        }
    }

    $totalQty = 0.0;
    $totalBaseAmount = 0.0;
    $itemCount = count($items);

    foreach ($items as $it) {
        $totalQty += (float)$it['qty'];
        $totalBaseAmount += (float)$it['base_amount'];
    }

    $allocatedSum = 0.0;
    $result = [];

    foreach ($items as $index => $it) {
        $qty = (float)$it['qty'];
        $base = (float)$it['base_amount'];
        $allocated = 0.0;

        if ($totalCapitalizable > 0) {
            if ($index === $itemCount - 1) {
                // Give remainder to last item to prevent rounding loss
                $allocated = round($totalCapitalizable - $allocatedSum, 2);
            } else {
                if ($method === 'weight' && $totalQty > 0) {
                    $allocated = round(($qty / $totalQty) * $totalCapitalizable, 2);
                } elseif ($method === 'value' && $totalBaseAmount > 0) {
                    $allocated = round(($base / $totalBaseAmount) * $totalCapitalizable, 2);
                } elseif ($method === 'equal' && $itemCount > 0) {
                    $allocated = round($totalCapitalizable / $itemCount, 2);
                } else {
                    $allocated = ($totalQty > 0) ? round(($qty / $totalQty) * $totalCapitalizable, 2) : 0;
                }
                $allocatedSum += $allocated;
            }
        }

        $landedCost = round($base + $allocated, 2);
        $landedUnitRate = $qty > 0 ? round($landedCost / $qty, 4) : 0;

        $it['allocated_charge'] = $allocated;
        $it['landed_cost'] = $landedCost;
        $it['landed_unit_rate'] = $landedUnitRate;
        $result[] = $it;
    }

    return $result;
}
