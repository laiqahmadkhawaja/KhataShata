<?php
/**
 * KhataShata - Financial Calculation Engine
 * Core financial algorithms, closing workflows, share distributions,
 * account reconciliation, and reporting metrics.
 */

class FinancialEngine {

    /**
     * Get effective ownership structure for a business as of a specific date/month
     * Returns array of participants:
     * [['partner_id' => null, 'name' => 'Owner', 'percentage' => 60.0, 'is_owner' => true], ...]
     */
    public static function getEffectiveOwnership($business_id, $date_or_month = null) {
        $db = get_db_connection();
        if ($date_or_month === null) {
            $effective_date = date('Y-m-d');
        } elseif (preg_match('/^\d{4}-\d{2}$/', $date_or_month)) {
            // End of the financial month
            $effective_date = date('Y-m-t', strtotime($date_or_month . '-01'));
        } else {
            $effective_date = date('Y-m-d', strtotime($date_or_month));
        }

        // Subquery or find latest ownership batch on or before effective_date
        $stmt = $db->prepare("
            SELECT bo.*, p.name as partner_name, p.phone as partner_phone
            FROM business_ownership bo
            LEFT JOIN partners p ON bo.partner_id = p.id
            WHERE bo.business_id = :bid 
              AND bo.is_active = 1
              AND bo.effective_date = (
                  SELECT MAX(bo2.effective_date)
                  FROM business_ownership bo2
                  WHERE bo2.business_id = :bid2 AND bo2.effective_date <= :eff_date
              )
            ORDER BY bo.partner_id IS NULL DESC, bo.percentage DESC
        ");
        $stmt->execute([
            'bid' => $business_id,
            'bid2' => $business_id,
            'eff_date' => $effective_date
        ]);
        $rows = $stmt->fetchAll();

        // If no past effective date was found, get the earliest available
        if (empty($rows)) {
            $stmt = $db->prepare("
                SELECT bo.*, p.name as partner_name, p.phone as partner_phone
                FROM business_ownership bo
                LEFT JOIN partners p ON bo.partner_id = p.id
                WHERE bo.business_id = :bid AND bo.is_active = 1
                ORDER BY bo.partner_id IS NULL DESC, bo.percentage DESC
            ");
            $stmt->execute(['bid' => $business_id]);
            $rows = $stmt->fetchAll();
        }

        $participants = [];
        foreach ($rows as $row) {
            $is_owner = is_null($row['partner_id']);
            $participants[] = [
                'id' => $row['id'],
                'partner_id' => $row['partner_id'],
                'name' => $is_owner ? 'You (Owner)' : ($row['partner_name'] ?? 'Partner #' . $row['partner_id']),
                'percentage' => (float)$row['percentage'],
                'is_owner' => $is_owner,
                'effective_date' => $row['effective_date']
            ];
        }

        return $participants;
    }

    /**
     * Validate an ownership configuration before saving
     * Must sum to exactly 100.00%, no negatives, no >100%, must include owner (partner_id = null)
     */
    public static function validateOwnership($shares) {
        $total = 0.0;
        $has_owner = false;
        $partner_ids = [];

        foreach ($shares as $item) {
            $pct = (float)($item['percentage'] ?? 0);
            $pid = $item['partner_id'] ?? null;

            if ($pct < 0 || $pct > 100) {
                return ['valid' => false, 'error' => "Percentage must be between 0 and 100."];
            }

            if (is_null($pid) || $pid === '' || $pid === 'owner') {
                if ($has_owner) {
                    return ['valid' => false, 'error' => "Duplicate owner record in ownership configuration."];
                }
                $has_owner = true;
            } else {
                if (in_array($pid, $partner_ids)) {
                    return ['valid' => false, 'error' => "Duplicate partner assignment detected."];
                }
                $partner_ids[] = $pid;
            }

            $total += $pct;
        }

        if (!$has_owner) {
            return ['valid' => false, 'error' => "The owner must be included as a participant in each business."];
        }

        // Check if sum equals 100% within 0.0001 tolerance
        if (abs($total - 100.00) > 0.001) {
            return ['valid' => false, 'error' => "Total ownership must equal exactly 100%. Current sum: " . number_format($total, 2) . "%."];
        }

        return ['valid' => true];
    }

    /**
     * Calculate business revenue for a month
     */
    public static function getBusinessRevenue($business_id, $financial_month) {
        $db = get_db_connection();
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(amount), 0.00) 
            FROM business_income 
            WHERE business_id = :bid AND financial_month = :month AND is_deleted = 0
        ");
        $stmt->execute(['bid' => $business_id, 'month' => $financial_month]);
        return (float)$stmt->fetchColumn();
    }

    /**
     * Calculate business expenses for a month
     */
    public static function getBusinessExpenses($business_id, $financial_month) {
        $db = get_db_connection();
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(amount), 0.00) 
            FROM business_expenses 
            WHERE business_id = :bid AND financial_month = :month AND is_deleted = 0
        ");
        $stmt->execute(['bid' => $business_id, 'month' => $financial_month]);
        return (float)$stmt->fetchColumn();
    }

    /**
     * Deterministic rounding allocation method for distributing profit
     * Guarantees: sum(shares) === distributable_profit exactly.
     */
    public static function allocateProfitShares($distributable_profit, $participants) {
        $distributable_profit = round((float)$distributable_profit, 2);
        if ($distributable_profit <= 0 || empty($participants)) {
            $result = [];
            foreach ($participants as $p) {
                $p['share_amount'] = 0.00;
                $result[] = $p;
            }
            return $result;
        }

        // Step 1: calculate raw base cents (paisa)
        $total_cents = (int)round($distributable_profit * 100);
        $distributed_cents = 0;
        $calculated = [];

        foreach ($participants as $idx => $p) {
            $pct = (float)$p['percentage'];
            // Floor cents
            $cents = (int)floor(($total_cents * $pct) / 100);
            $distributed_cents += $cents;
            $calculated[$idx] = [
                'participant' => $p,
                'cents' => $cents,
                'remainder' => (($total_cents * $pct) / 100) - $cents
            ];
        }

        // Step 2: Allocate residual cents (paisa) consistently starting with largest remainder or owner
        $residual = $total_cents - $distributed_cents;
        if ($residual > 0) {
            // Sort keys by remainder descending, then owner first
            uasort($calculated, function($a, $b) {
                if (abs($a['remainder'] - $b['remainder']) < 0.00001) {
                    return $b['participant']['is_owner'] <=> $a['participant']['is_owner'];
                }
                return ($b['remainder'] <=> $a['remainder']);
            });

            foreach ($calculated as $k => &$item) {
                if ($residual <= 0) break;
                $item['cents'] += 1;
                $residual--;
            }
            unset($item);
        }

        // Format final amounts
        $final_results = [];
        $sum_check = 0.00;
        foreach ($calculated as $item) {
            $amount = round($item['cents'] / 100, 2);
            $sum_check += $amount;
            $p = $item['participant'];
            $p['share_amount'] = $amount;
            $final_results[] = $p;
        }

        // Final micro-balance adjustment to owner if any discrepancy remains
        $diff = round($distributable_profit - $sum_check, 2);
        if ($diff != 0) {
            foreach ($final_results as &$p) {
                if ($p['is_owner']) {
                    $p['share_amount'] = round($p['share_amount'] + $diff, 2);
                    break;
                }
            }
            unset($p);
        }

        return $final_results;
    }

    /**
     * Calculate monthly financial summary for a business
     */
    public static function getMonthlySummary($business_id, $financial_month) {
        $db = get_db_connection();

        // Check if already finalized or draft exists
        $stmt = $db->prepare("SELECT * FROM monthly_closings WHERE business_id = :bid AND financial_month = :month LIMIT 1");
        $stmt->execute(['bid' => $business_id, 'month' => $financial_month]);
        $closing = $stmt->fetch();

        $revenue = self::getBusinessRevenue($business_id, $financial_month);
        $expenses = self::getBusinessExpenses($business_id, $financial_month);
        $net_profit = round($revenue - $expenses, 2);

        $reserve = $closing ? (float)$closing['business_reserve'] : 0.00;
        $status = $closing ? $closing['status'] : 'draft';
        $distributable = max(0.00, round($net_profit - $reserve, 2));

        $participants = self::getEffectiveOwnership($business_id, $financial_month);
        $distributions = self::allocateProfitShares($distributable, $participants);

        // If finalized, fetch recorded distributions from profit_distributions to guarantee historical snapshot
        if ($closing && $closing['status'] === 'finalized') {
            $dist_stmt = $db->prepare("
                SELECT pd.*, p.name as partner_name
                FROM profit_distributions pd
                LEFT JOIN partners p ON pd.partner_id = p.id
                WHERE pd.monthly_closing_id = :cid
            ");
            $dist_stmt->execute(['cid' => $closing['id']]);
            $saved_dists = $dist_stmt->fetchAll();

            if (!empty($saved_dists)) {
                $distributions = [];
                foreach ($saved_dists as $sd) {
                    $is_owner = is_null($sd['partner_id']);
                    $distributions[] = [
                        'partner_id' => $sd['partner_id'],
                        'name' => $is_owner ? 'You (Owner)' : ($sd['partner_name'] ?? 'Partner #' . $sd['partner_id']),
                        'percentage' => (float)$sd['ownership_percentage'],
                        'is_owner' => $is_owner,
                        'share_amount' => (float)$sd['share_amount']
                    ];
                }
            }
        }

        return [
            'business_id' => $business_id,
            'financial_month' => $financial_month,
            'total_revenue' => $revenue,
            'total_expenses' => $expenses,
            'net_profit' => $net_profit,
            'business_reserve' => $reserve,
            'distributable_profit' => $distributable,
            'status' => $status,
            'closing_id' => $closing ? $closing['id'] : null,
            'finalized_at' => $closing ? $closing['finalized_at'] : null,
            'notes' => $closing ? $closing['notes'] : null,
            'distributions' => $distributions
        ];
    }

    /**
     * Finalize monthly closing for a business
     */
    public static function finalizeMonthlyClosing($business_id, $financial_month, $reserve = 0.00, $notes = '', $user_id = 1) {
        $db = get_db_connection();
        $db->beginTransaction();

        try {
            // Check current status
            $stmt = $db->prepare("SELECT * FROM monthly_closings WHERE business_id = :bid AND financial_month = :month FOR UPDATE");
            $stmt->execute(['bid' => $business_id, 'month' => $financial_month]);
            $closing = $stmt->fetch();

            if ($closing && $closing['status'] === 'finalized') {
                $db->rollBack();
                return ['success' => false, 'error' => "This month has already been finalized and locked."];
            }

            $revenue = self::getBusinessRevenue($business_id, $financial_month);
            $expenses = self::getBusinessExpenses($business_id, $financial_month);
            $net_profit = round($revenue - $expenses, 2);
            $reserve = max(0.00, round((float)$reserve, 2));
            $distributable = max(0.00, round($net_profit - $reserve, 2));

            $participants = self::getEffectiveOwnership($business_id, $financial_month);
            $validation = self::validateOwnership($participants);
            if (!$validation['valid']) {
                $db->rollBack();
                return ['success' => false, 'error' => "Ownership validation error: " . $validation['error']];
            }

            $distributions = self::allocateProfitShares($distributable, $participants);

            // Insert or update monthly_closings
            if ($closing) {
                $stmt = $db->prepare("
                    UPDATE monthly_closings 
                    SET total_revenue = :rev, total_expenses = :exp, net_profit = :np, 
                        business_reserve = :res, distributable_profit = :dist, status = 'finalized', 
                        finalized_at = NOW(), finalized_by = :fby, notes = :notes, updated_at = NOW()
                    WHERE id = :id
                ");
                $stmt->execute([
                    'rev' => $revenue,
                    'exp' => $expenses,
                    'np' => $net_profit,
                    'res' => $reserve,
                    'dist' => $distributable,
                    'fby' => $user_id,
                    'notes' => $notes,
                    'id' => $closing['id']
                ]);
                $closing_id = $closing['id'];
                // Delete previous distributions if draft existed
                $del = $db->prepare("DELETE FROM profit_distributions WHERE monthly_closing_id = :cid");
                $del->execute(['cid' => $closing_id]);
            } else {
                $stmt = $db->prepare("
                    INSERT INTO monthly_closings 
                    (user_id, business_id, financial_month, total_revenue, total_expenses, net_profit, business_reserve, distributable_profit, status, finalized_at, finalized_by, notes)
                    VALUES (:uid, :bid, :month, :rev, :exp, :np, :res, :dist, 'finalized', NOW(), :fby, :notes)
                ");
                $stmt->execute([
                    'uid' => $user_id,
                    'bid' => $business_id,
                    'month' => $financial_month,
                    'rev' => $revenue,
                    'exp' => $expenses,
                    'np' => $net_profit,
                    'res' => $reserve,
                    'dist' => $distributable,
                    'fby' => $user_id,
                    'notes' => $notes
                ]);
                $closing_id = $db->lastInsertId();
            }

            // Insert profit distributions snapshot & update partner earnings ledger
            foreach ($distributions as $d) {
                $ins_dist = $db->prepare("
                    INSERT INTO profit_distributions 
                    (monthly_closing_id, business_id, partner_id, financial_month, ownership_percentage, share_amount)
                    VALUES (:cid, :bid, :pid, :month, :pct, :amt)
                ");
                $ins_dist->execute([
                    'cid' => $closing_id,
                    'bid' => $business_id,
                    'pid' => $d['partner_id'],
                    'month' => $financial_month,
                    'pct' => $d['percentage'],
                    'amt' => $d['share_amount']
                ]);

                // Update partner ledger for partners (not owner)
                if (!$d['is_owner'] && !empty($d['partner_id'])) {
                    $pid = $d['partner_id'];
                    $earned = $d['share_amount'];

                    // Get previous closing balance for this partner
                    $prev_stmt = $db->prepare("
                        SELECT closing_balance FROM partner_earnings 
                        WHERE business_id = :bid AND partner_id = :pid AND financial_month < :month
                        ORDER BY financial_month DESC LIMIT 1
                    ");
                    $prev_stmt->execute(['bid' => $business_id, 'pid' => $pid, 'month' => $financial_month]);
                    $opening_balance = (float)$prev_stmt->fetchColumn();

                    // Calculate total payments made in this month to partner
                    $pay_stmt = $db->prepare("
                        SELECT COALESCE(SUM(amount), 0.00) FROM partner_payments 
                        WHERE business_id = :bid AND partner_id = :pid AND financial_month = :month AND is_deleted = 0
                    ");
                    $pay_stmt->execute(['bid' => $business_id, 'pid' => $pid, 'month' => $financial_month]);
                    $paid_amount = (float)$pay_stmt->fetchColumn();

                    $closing_balance = round($opening_balance + $earned - $paid_amount, 2);

                    $earning_stmt = $db->prepare("
                        INSERT INTO partner_earnings 
                        (user_id, business_id, partner_id, financial_month, opening_balance, earned_share, adjustments, paid_amount, closing_balance)
                        VALUES (:uid, :bid, :pid, :month, :open, :earned, 0.00, :paid, :close)
                        ON DUPLICATE KEY UPDATE 
                            opening_balance = VALUES(opening_balance),
                            earned_share = VALUES(earned_share),
                            paid_amount = VALUES(paid_amount),
                            closing_balance = VALUES(closing_balance)
                    ");
                    $earning_stmt->execute([
                        'uid' => $user_id,
                        'bid' => $business_id,
                        'pid' => $pid,
                        'month' => $financial_month,
                        'open' => $opening_balance,
                        'earned' => $earned,
                        'paid' => $paid_amount,
                        'close' => $closing_balance
                    ]);
                }
            }

            // Audit log
            log_audit($user_id, 'monthly_closings', $closing_id, 'finalize', null, [
                'business_id' => $business_id,
                'financial_month' => $financial_month,
                'net_profit' => $net_profit,
                'distributable' => $distributable
            ], "Finalized monthly financial closing for $financial_month");

            // Notification
            create_notification($user_id, "Monthly Closing Finalized", "Financial month $financial_month for business has been finalized and locked.", 'closing_reminder', "monthly-closing.php?business_id=$business_id&month=$financial_month");

            $db->commit();
            return ['success' => true, 'closing_id' => $closing_id];
        } catch (Exception $e) {
            $db->rollBack();
            error_log("Finalize Closing Exception: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Reopen a monthly closing (requires authorization, logs audit)
     */
    public static function reopenMonthlyClosing($closing_id, $reason, $user_id = 1) {
        $db = get_db_connection();
        $db->beginTransaction();

        try {
            $stmt = $db->prepare("SELECT * FROM monthly_closings WHERE id = :id FOR UPDATE");
            $stmt->execute(['id' => $closing_id]);
            $closing = $stmt->fetch();

            if (!$closing) {
                $db->rollBack();
                return ['success' => false, 'error' => "Closing record not found."];
            }

            $upd = $db->prepare("UPDATE monthly_closings SET status = 'reopened', notes = CONCAT(COALESCE(notes,''), '\n[Reopened on ', NOW(), ' Reason: ', :reason, ']') WHERE id = :id");
            $upd->execute(['reason' => $reason, 'id' => $closing_id]);

            log_audit($user_id, 'monthly_closings', $closing_id, 'reopen', $closing, ['status' => 'reopened'], $reason);
            create_notification($user_id, "Monthly Closing Reopened", "Closing #$closing_id for {$closing['financial_month']} was reopened. Reason: $reason", 'general');

            $db->commit();
            return ['success' => true];
        } catch (Exception $e) {
            $db->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Record partner payment settlement
     */
    public static function recordPartnerPayment($data, $user_id = 1) {
        $db = get_db_connection();
        $db->beginTransaction();

        try {
            $business_id = (int)$data['business_id'];
            $partner_id = (int)$data['partner_id'];
            $account_id = !empty($data['account_id']) ? (int)$data['account_id'] : null;
            $month = !empty($data['financial_month']) ? $data['financial_month'] : date('Y-m');
            $amount = round((float)$data['amount'], 2);
            $payment_date = $data['payment_date'] ?? date('Y-m-d');
            $method = $data['payment_method'] ?? 'Bank Transfer';
            $ref = $data['reference_no'] ?? generate_reference('PAY');
            $desc = $data['description'] ?? 'Profit share settlement';
            $attachment = $data['attachment'] ?? null;

            if ($amount <= 0) {
                $db->rollBack();
                return ['success' => false, 'error' => "Payment amount must be greater than zero."];
            }

            // Insert into partner_payments
            $stmt = $db->prepare("
                INSERT INTO partner_payments 
                (user_id, business_id, partner_id, account_id, financial_month, amount, payment_date, payment_method, reference_no, description, attachment)
                VALUES (:uid, :bid, :pid, :aid, :month, :amt, :pdate, :method, :ref, :desc, :att)
            ");
            $stmt->execute([
                'uid' => $user_id,
                'bid' => $business_id,
                'pid' => $partner_id,
                'aid' => $account_id,
                'month' => $month,
                'amt' => $amount,
                'pdate' => $payment_date,
                'method' => $method,
                'ref' => $ref,
                'desc' => $desc,
                'att' => $attachment
            ]);
            $payment_id = $db->lastInsertId();

            // If an account was used, update account balance & create transaction record
            if ($account_id) {
                self::recordAccountOutflow($account_id, $amount, 'partner_payment', 'partner_payments', $payment_id, $payment_date, $ref, "Partner payment to Partner #$partner_id", $user_id);
            }

            // Recalculate partner earnings closing balance
            self::syncPartnerEarnings($business_id, $partner_id);

            log_audit($user_id, 'partner_payments', $payment_id, 'create', null, $data, "Paid $amount to partner #$partner_id");
            $db->commit();
            return ['success' => true, 'payment_id' => $payment_id];
        } catch (Exception $e) {
            $db->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Record Owner Business Share Receipt
     * Does NOT double count business net profit.
     * Records cash inflow into personal account and reduces pending share receivable.
     */
    public static function recordOwnerShareReceipt($data, $user_id = 1) {
        $db = get_db_connection();
        $db->beginTransaction();

        try {
            $business_id = (int)$data['business_id'];
            $account_id = !empty($data['account_id']) ? (int)$data['account_id'] : null;
            $month = !empty($data['financial_month']) ? $data['financial_month'] : date('Y-m');
            $amount = round((float)$data['amount'], 2);
            $receipt_date = $data['receipt_date'] ?? date('Y-m-d');
            $method = $data['payment_method'] ?? 'Bank Transfer';
            $ref = $data['reference_no'] ?? generate_reference('REC');
            $notes = $data['notes'] ?? 'Owner share drawn from business profit';

            if ($amount <= 0) {
                $db->rollBack();
                return ['success' => false, 'error' => "Receipt amount must be greater than zero."];
            }

            $is_retained = !empty($data['is_retained']) ? 1 : 0;

            $stmt = $db->prepare("
                INSERT INTO owner_share_receipts 
                (user_id, business_id, account_id, financial_month, amount, is_retained, receipt_date, payment_method, reference_no, notes)
                VALUES (:uid, :bid, :aid, :month, :amt, :retained, :rdate, :method, :ref, :notes)
            ");
            $stmt->execute([
                'uid' => $user_id,
                'bid' => $business_id,
                'aid' => $account_id,
                'month' => $month,
                'amt' => $amount,
                'retained' => $is_retained,
                'rdate' => $receipt_date,
                'method' => $method,
                'ref' => $ref,
                'notes' => $notes
            ]);
            $receipt_id = $db->lastInsertId();

            if ($account_id && !$is_retained) {
                self::recordAccountInflow($account_id, $amount, 'owner_receipt', 'owner_share_receipts', $receipt_id, $receipt_date, $ref, "Business profit share receipt from Business #$business_id", $user_id);
            }

            log_audit($user_id, 'owner_share_receipts', $receipt_id, 'create', null, $data, "Received owner share of $amount");
            $db->commit();
            return ['success' => true, 'receipt_id' => $receipt_id];
        } catch (Exception $e) {
            $db->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Record Account Inflow
     */
    public static function recordAccountInflow($account_id, $amount, $type, $rel_table, $rel_id, $date, $ref, $desc, $user_id = 1) {
        $db = get_db_connection();
        $amount = round((float)$amount, 2);

        $stmt = $db->prepare("
            INSERT INTO account_transactions 
            (user_id, account_id, transaction_type, amount, direction, transaction_date, related_table, related_id, reference_no, description)
            VALUES (:uid, :aid, :type, :amt, 'in', :tdate, :rtable, :rid, :ref, :desc)
        ");
        $stmt->execute([
            'uid' => $user_id,
            'aid' => $account_id,
            'type' => $type,
            'amt' => $amount,
            'tdate' => $date,
            'rtable' => $rel_table,
            'rid' => $rel_id,
            'ref' => $ref,
            'desc' => $desc
        ]);

        $upd = $db->prepare("UPDATE accounts SET current_balance = current_balance + :amt, updated_at = NOW() WHERE id = :aid");
        $upd->execute(['amt' => $amount,
'aid' => $account_id]);
    }

    /**
     * Record Account Outflow
     */
    public static function recordAccountOutflow($account_id, $amount, $type, $rel_table, $rel_id, $date, $ref, $desc, $user_id = 1) {
        $db = get_db_connection();
        $amount = round((float)$amount, 2);

        $stmt = $db->prepare("
            INSERT INTO account_transactions 
            (user_id, account_id, transaction_type, amount, direction, transaction_date, related_table, related_id, reference_no, description)
            VALUES (:uid, :aid, :type, :amt, 'out', :tdate, :rtable, :rid, :ref, :desc)
        ");
        $stmt->execute([
            'uid' => $user_id,
            'aid' => $account_id,
            'type' => $type,
            'amt' => $amount,
            'tdate' => $date,
            'rtable' => $rel_table,
            'rid' => $rel_id,
            'ref' => $ref,
            'desc' => $desc
        ]);

        $upd = $db->prepare("UPDATE accounts SET current_balance = current_balance - :amt, updated_at = NOW() WHERE id = :aid");
        $upd->execute(['amt' => $amount,
'aid' => $account_id]);
    }

    /**
     * Account to Account Transfer
     */
    public static function executeAccountTransfer($from_acc, $to_acc, $amount, $date, $notes = '', $user_id = 1) {
        $db = get_db_connection();
        $db->beginTransaction();

        try {
            $amount = round((float)$amount, 2);
            if ($amount <= 0) {
                $db->rollBack();
                return ['success' => false, 'error' => "Transfer amount must be greater than zero."];
            }
            if ($from_acc == $to_acc) {
                $db->rollBack();
                return ['success' => false, 'error' => "Source and destination accounts must be different."];
            }

            $ref = generate_reference('TRF');

            $stmt = $db->prepare("
                INSERT INTO account_transfers (user_id, from_account_id, to_account_id, amount, transfer_date, reference_no, notes)
                VALUES (:uid, :from, :to, :amt, :tdate, :ref, :notes)
            ");
            $stmt->execute([
                'uid' => $user_id,
                'from' => $from_acc,
                'to' => $to_acc,
                'amt' => $amount,
                'tdate' => $date,
                'ref' => $ref,
                'notes' => $notes
            ]);
            $trans_id = $db->lastInsertId();

            self::recordAccountOutflow($from_acc, $amount, 'transfer_out', 'account_transfers', $trans_id, $date, $ref, "Transfer to Account #$to_acc: $notes", $user_id);
            self::recordAccountInflow($to_acc, $amount, 'transfer_in', 'account_transfers', $trans_id, $date, $ref, "Transfer from Account #$from_acc: $notes", $user_id);

            log_audit($user_id, 'account_transfers', $trans_id, 'create', null, ['from' => $from_acc, 'to' => $to_acc, 'amount' => $amount], "Transferred $amount between accounts");
            $db->commit();
            return ['success' => true, 'transfer_id' => $trans_id];
        } catch (Exception $e) {
            $db->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Sync and reconcile partner earnings ledger
     */
    public static function syncPartnerEarnings($business_id, $partner_id) {
        $db = get_db_connection();
        // Fetch all months ordered
        $stmt = $db->prepare("
            SELECT DISTINCT financial_month FROM (
                SELECT financial_month FROM profit_distributions WHERE business_id = :bid1 AND partner_id = :pid1
                UNION
                SELECT financial_month FROM partner_payments WHERE business_id = :bid2 AND partner_id = :pid2 AND is_deleted = 0
                UNION
                SELECT financial_month FROM business_income WHERE business_id = :bid3 AND is_deleted = 0
            ) as m WHERE financial_month IS NOT NULL AND financial_month != '' ORDER BY financial_month ASC
        ");
        $stmt->execute(['bid1' => $business_id, 'pid1' => $partner_id, 'bid2' => $business_id, 'pid2' => $partner_id, 'bid3' => $business_id]);
        $months = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $running_balance = 0.00;
        foreach ($months as $month) {
            // Earned
            $estmt = $db->prepare("SELECT COALESCE(SUM(share_amount), 0.00) FROM profit_distributions WHERE business_id = :bid AND partner_id = :pid AND financial_month = :m");
            $estmt->execute(['bid' => $business_id, 'pid' => $partner_id, 'm' => $month]);
            $earned = (float)$estmt->fetchColumn();

            // If not finalized, calculate live earned share from monthly revenue minus expenses
            if ($earned == 0.00) {
                $summary = self::getMonthlySummary($business_id, $month);
                foreach ($summary['distributions'] as $d) {
                    if (!$d['is_owner'] && (int)$d['partner_id'] === (int)$partner_id) {
                        $earned = (float)$d['share_amount'];
                        break;
                    }
                }
            }

            // Paid
            $pstmt = $db->prepare("SELECT COALESCE(SUM(amount), 0.00) FROM partner_payments WHERE business_id = :bid AND partner_id = :pid AND financial_month = :m AND is_deleted = 0");
            $pstmt->execute(['bid' => $business_id, 'pid' => $partner_id, 'm' => $month]);
            $paid = (float)$pstmt->fetchColumn();

            $opening = $running_balance;
            $closing = round($opening + $earned - $paid, 2);
            $running_balance = $closing;

            $ins = $db->prepare("
                INSERT INTO partner_earnings (user_id, business_id, partner_id, financial_month, opening_balance, earned_share, adjustments, paid_amount, closing_balance)
                VALUES (1, :bid, :pid, :m, :open, :earned, 0.00, :paid, :close)
                ON DUPLICATE KEY UPDATE 
                    opening_balance = VALUES(opening_balance), 
                    earned_share = VALUES(earned_share), 
                    paid_amount = VALUES(paid_amount), 
                    closing_balance = VALUES(closing_balance)
            ");
            $ins->execute([
                'bid' => $business_id,
                'pid' => $partner_id,
                'm' => $month,
                'open' => $opening,
                'earned' => $earned,
                'paid' => $paid,
                'close' => $closing
            ]);
        }
    }

    /**
     * Get consolidated dashboard overview statistics
     */
    public static function getDashboardMetrics($period = 'this_month', $custom_from = null, $custom_to = null, $user_id = 1) {
        $db = get_db_connection();

        // Calculate date range based on period
        $today = date('Y-m-d');
        switch ($period) {
            case 'previous_month':
                $start_date = date('Y-m-01', strtotime('first day of previous month'));
                $end_date = date('Y-m-t', strtotime('last day of previous month'));
                $month_str = date('Y-m', strtotime('first day of previous month'));
                break;
            case 'last_3_months':
                $start_date = date('Y-m-01', strtotime('-2 months'));
                $end_date = date('Y-m-t');
                $month_str = null;
                break;
            case 'last_6_months':
                $start_date = date('Y-m-01', strtotime('-5 months'));
                $end_date = date('Y-m-t');
                $month_str = null;
                break;
            case 'this_year':
                $start_date = date('Y-01-01');
                $end_date = date('Y-12-31');
                $month_str = null;
                break;
            case 'previous_year':
                $start_date = date('Y-01-01', strtotime('-1 year'));
                $end_date = date('Y-12-31', strtotime('-1 year'));
                $month_str = null;
                break;
            case 'custom':
                $start_date = $custom_from ?? date('Y-m-01');
                $end_date = $custom_to ?? date('Y-m-t');
                $month_str = null;
                break;
            case 'this_month':
            default:
                $start_date = date('Y-m-01');
                $end_date = date('Y-m-t');
                $month_str = date('Y-m');
                break;
        }

        // 1. Total Business Revenue in period
        $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0.00) FROM business_income WHERE user_id = :uid AND transaction_date BETWEEN :s AND :e AND is_deleted = 0");
        $stmt->execute(['uid' => $user_id, 's' => $start_date, 'e' => $end_date]);
        $bus_rev = (float)$stmt->fetchColumn();

        // 2. Total Business Expenses in period
        $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0.00) FROM business_expenses WHERE user_id = :uid AND date BETWEEN :s AND :e AND is_deleted = 0");
        $stmt->execute(['uid' => $user_id, 's' => $start_date, 'e' => $end_date]);
        $bus_exp = (float)$stmt->fetchColumn();

        $bus_net_profit = round($bus_rev - $bus_exp, 2);

        // 3. Owner Total Business Share (Earned from profit_distributions where partner_id is null)
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(share_amount), 0.00) 
            FROM profit_distributions pd
            JOIN monthly_closings mc ON pd.monthly_closing_id = mc.id
            WHERE mc.user_id = :uid AND pd.partner_id IS NULL AND (mc.financial_month BETWEEN :sm AND :em)
        ");
        $start_m = date('Y-m', strtotime($start_date));
        $end_m = date('Y-m', strtotime($end_date));
        $stmt->execute(['uid' => $user_id, 'sm' => $start_m, 'em' => $end_m]);
        $owner_share_earned = (float)$stmt->fetchColumn();

        // PLUS check unfinalized active months in the period to show real-time earned share
        $unfin_stmt = $db->prepare("
            SELECT b.id as business_id, b.collection_model, bi.financial_month
            FROM businesses b
            JOIN business_income bi ON b.id = bi.business_id AND bi.is_deleted = 0
            LEFT JOIN monthly_closings mc ON b.id = mc.business_id AND bi.financial_month = mc.financial_month AND mc.status = 'finalized'
            WHERE b.user_id = :uid AND mc.id IS NULL AND (bi.financial_month BETWEEN :sm AND :em)
            GROUP BY b.id, bi.financial_month
        ");
        $unfin_stmt->execute(['uid' => $user_id, 'sm' => $start_m, 'em' => $end_m]);
        $unfin_months = $unfin_stmt->fetchAll();

        $direct_owner_retained = 0.00;
        foreach ($unfin_months as $um) {
            $summary = self::getMonthlySummary($um['business_id'], $um['financial_month']);
            foreach ($summary['distributions'] as $d) {
                if ($d['is_owner']) {
                    $owner_share_earned += (float)$d['share_amount'];
                    if (($um['collection_model'] ?? 'direct') === 'direct') {
                        $direct_owner_retained += (float)$d['share_amount'];
                    }
                    break;
                }
            }
        }

        // 4. Owner Business Share Actually Received
        // For direct collection businesses, user directly collects all revenue in their cash/bank accounts,
        // so their share is automatically retained in their account.
        $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0.00) FROM owner_share_receipts WHERE user_id = :uid AND receipt_date BETWEEN :s AND :e AND is_deleted = 0");
        $stmt->execute(['uid' => $user_id, 's' => $start_date, 'e' => $end_date]);
        $recorded_owner_received = (float)$stmt->fetchColumn();
        $owner_share_received = max($recorded_owner_received, $direct_owner_retained);

        // All-time pending business receivables for owner (only for external businesses where partner owes user)
        $stmt = $db->prepare("
            SELECT 
              (SELECT COALESCE(SUM(pd.share_amount), 0.00) 
               FROM profit_distributions pd 
               JOIN monthly_closings mc ON pd.monthly_closing_id = mc.id 
               JOIN businesses b ON pd.business_id = b.id
               WHERE mc.user_id = :uid AND pd.partner_id IS NULL AND b.collection_model = 'external') -
              (SELECT COALESCE(SUM(osr.amount), 0.00) 
               FROM owner_share_receipts osr 
               JOIN businesses b2 ON osr.business_id = b2.id
               WHERE osr.user_id = :uid2 AND osr.is_deleted = 0 AND b2.collection_model = 'external')
        ");
        $stmt->execute(['uid' => $user_id, 'uid2' => $user_id]);
        $pending_receivables = max(0.00, (float)$stmt->fetchColumn());

        // 5. Personal Income (Salary, Freelance, etc.)
        $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0.00) FROM personal_income WHERE user_id = :uid AND payment_status = 'received' AND income_date BETWEEN :s AND :e AND is_deleted = 0");
        $stmt->execute(['uid' => $user_id, 's' => $start_date, 'e' => $end_date]);
        $personal_income = (float)$stmt->fetchColumn();

        // 6. Personal Expenses
        $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0.00) FROM personal_expenses WHERE user_id = :uid AND expense_date BETWEEN :s AND :e AND is_deleted = 0");
        $stmt->execute(['uid' => $user_id, 's' => $start_date, 'e' => $end_date]);
        $personal_expenses = (float)$stmt->fetchColumn();

        // 7. Personal Net Savings = Personal Income Received + Business Share Received - Personal Expenses
        $personal_savings = round(($personal_income + $owner_share_received) - $personal_expenses, 2);

        // 8. Active Businesses
        $stmt = $db->prepare("SELECT COUNT(*) FROM businesses WHERE user_id = :uid AND status = 'Active' AND is_deleted = 0");
        $stmt->execute(['uid' => $user_id]);
        $active_businesses_count = (int)$stmt->fetchColumn();

        // 9. Total Cash & Bank Balance
        $stmt = $db->prepare("SELECT COALESCE(SUM(current_balance), 0.00) FROM accounts WHERE user_id = :uid AND status = 'active' AND is_deleted = 0");
        $stmt->execute(['uid' => $user_id]);
        $total_cash_balance = (float)$stmt->fetchColumn();

        // 10. Partner Outstanding Payables (Total unpaid to partners)
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(closing_balance), 0.00) 
            FROM partner_earnings pe1
            WHERE pe1.user_id = :uid AND pe1.financial_month = (
                SELECT MAX(pe2.financial_month) 
                FROM partner_earnings pe2 
                WHERE pe2.business_id = pe1.business_id AND pe2.partner_id = pe1.partner_id
            )
        ");
        $stmt->execute(['uid' => $user_id]);
        $partner_pending_payables = (float)$stmt->fetchColumn();

        // 11. Net Worth Calculation
        // Assets: Total Cash/Bank + Investments Current Value + Assets Current Value + Loans Given Outstanding
        $stmt = $db->prepare("SELECT COALESCE(SUM(current_value), 0.00) FROM investments WHERE user_id = :uid AND status = 'active' AND is_deleted = 0");
        $stmt->execute(['uid' => $user_id]);
        $total_investments = (float)$stmt->fetchColumn();

        $stmt = $db->prepare("SELECT COALESCE(SUM(current_estimated_value), 0.00) FROM assets WHERE user_id = :uid AND is_deleted = 0");
        $stmt->execute(['uid' => $user_id]);
        $total_assets_val = (float)$stmt->fetchColumn();

        $stmt = $db->prepare("SELECT COALESCE(SUM(current_balance), 0.00) FROM loans WHERE user_id = :uid AND loan_type = 'given' AND status = 'active' AND is_deleted = 0");
        $stmt->execute(['uid' => $user_id]);
        $total_loans_given = (float)$stmt->fetchColumn();

        $stmt = $db->prepare("SELECT COALESCE(SUM(current_balance), 0.00) FROM loans WHERE user_id = :uid AND loan_type = 'borrowed' AND status = 'active' AND is_deleted = 0");
        $stmt->execute(['uid' => $user_id]);
        $total_loans_borrowed = (float)$stmt->fetchColumn();

        $net_worth = round(($total_cash_balance + $total_investments + $total_assets_val + $total_loans_given) - $total_loans_borrowed, 2);

        return [
            'period' => $period,
            'start_date' => $start_date,
            'end_date' => $end_date,
            'bus_revenue' => $bus_rev,
            'bus_expenses' => $bus_exp,
            'bus_net_profit' => $bus_net_profit,
            'owner_share_earned' => $owner_share_earned,
            'owner_share_received' => $owner_share_received,
            'pending_receivables' => $pending_receivables,
            'personal_income' => $personal_income,
            'personal_expenses' => $personal_expenses,
            'personal_savings' => $personal_savings,
            'active_businesses' => $active_businesses_count,
            'total_cash_balance' => $total_cash_balance,
            'partner_pending_payables' => $partner_pending_payables,
            'total_investments' => $total_investments,
            'total_assets_val' => $total_assets_val,
            'total_loans_given' => $total_loans_given,
            'total_loans_borrowed' => $total_loans_borrowed,
            'net_worth' => $net_worth
        ];
    }
}
