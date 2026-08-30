<?php
declare(strict_types=1);

/**
 * AnalyticsService — Stage 4 core analytics engine.
 *
 * Reads ONLY from tables that already exist and are already populated by
 * Stage 1-3 (orders, order_items, sales, payments, inventory, products,
 * categories, brands, stock_movements). Nothing here writes data or
 * invents figures — every number is a direct aggregate of real rows, or
 * an explicitly-labeled derived calculation (e.g. average daily sales)
 * whose formula is documented in the method's docblock so it can be
 * explained plainly during a thesis defense.
 *
 * Two query shapes are used throughout, chosen deliberately:
 *
 * - ORDER-LEVEL (orderWhereClause): aggregates `orders`/`sales` directly
 *   (one row per order). Used for "Total Sales" and transaction counts,
 *   because `orders.total_amount` already reflects the order's discount —
 *   it's the actual net amount collected.
 *
 * - ITEM-LEVEL (itemWhereClause): joins in `order_items`/`products` (one
 *   row per line item). Used for anything broken down by product/category
 *   (units sold, category revenue, top SKU), and additionally supports
 *   category/brand filters that don't make sense against a whole order.
 *
 *   Item-level "revenue" uses NET_REVENUE_EXPR, not raw `line_total`.
 *   Stage 3 discounts are transaction-level only (`order_items.discount`
 *   is always 0), so a raw `SUM(line_total)` would ignore the order's
 *   discount entirely and NOT reconcile with the order-level "Total Sales"
 *   KPI. Rather than touching Stage 3's write path (out of scope here and
 *   risky this late), NET_REVENUE_EXPR prorates each order's
 *   `discount_amount` across its line items by each item's share of the
 *   order subtotal:
 *     net_line_revenue = line_total - (line_total / subtotal) * discount_amount
 *   This is an allocation, not a recorded fact — no single line item
 *   "actually" received part of the discount, Stage 3 only ever recorded
 *   one discount against the whole order. But it's the standard,
 *   defensible way to distribute an order-level discount across items,
 *   and it makes category/SKU revenue sum EXACTLY to order-level Total
 *   Sales for any given filtered order set. When an order has no discount
 *   (the common case), net_line_revenue equals line_total exactly.
 *
 * Every method returns zeros / empty arrays — never fabricated rows —
 * when there is no matching data, so calling pages can render a clean
 * empty state.
 */
final class AnalyticsService
{
    /**
     * Net, discount-prorated line revenue — see class docblock. NULLIF
     * guards a zero/null subtotal (shouldn't happen post-Stage-3, but
     * would otherwise divide by zero); COALESCE falls back to the raw
     * line_total in that edge case rather than nulling the row out.
     */
    private const NET_REVENUE_EXPR = 'COALESCE(oi.line_total - (oi.line_total / NULLIF(o.subtotal, 0)) * o.discount_amount, oi.line_total)';

    /**
     * Builds a WHERE clause + params for ORDER-LEVEL queries against
     * `orders o JOIN sales s ON s.order_id = o.id`.
     * Supported filters: date_from, date_to, customer_type, channel.
     * (category_id/brand_id are intentionally ignored here — they only
     * make sense at item level; see itemWhereClause().)
     */
    private static function orderWhereClause(array $filters): array
    {
        $where = ["o.status = 'completed'"];
        $params = [];

        if (!empty($filters['date_from'])) {
            $where[] = 'DATE(o.created_at) >= :date_from';
            $params['date_from'] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'DATE(o.created_at) <= :date_to';
            $params['date_to'] = $filters['date_to'];
        }
        if (!empty($filters['customer_type']) && in_array($filters['customer_type'], ['retail', 'reseller'], true)) {
            $where[] = 's.customer_type = :customer_type';
            $params['customer_type'] = $filters['customer_type'];
        }
        if (!empty($filters['channel']) && in_array($filters['channel'], ['physical', 'reseller', 'tiktok'], true)) {
            $where[] = 'o.channel = :channel';
            $params['channel'] = $filters['channel'];
        }

        return [implode(' AND ', $where), $params];
    }

    /**
     * Builds a WHERE clause + params for ITEM-LEVEL queries against
     * `order_items oi JOIN orders o ON o.id = oi.order_id JOIN sales s ON s.order_id = o.id
     *  JOIN products p ON p.id = oi.product_id`.
     * Supports everything orderWhereClause() does, plus category_id/brand_id.
     */
    private static function itemWhereClause(array $filters): array
    {
        [$sql, $params] = self::orderWhereClause($filters);
        $where = [$sql];

        if (!empty($filters['category_id'])) {
            $where[] = 'p.category_id = :category_id';
            $params['category_id'] = (int) $filters['category_id'];
        }
        if (!empty($filters['brand_id'])) {
            $where[] = 'p.brand_id = :brand_id';
            $params['brand_id'] = (int) $filters['brand_id'];
        }

        return [implode(' AND ', $where), $params];
    }

    private const ITEM_BASE_FROM = 'FROM order_items oi
        JOIN orders o ON o.id = oi.order_id
        JOIN sales s ON s.order_id = o.id
        JOIN products p ON p.id = oi.product_id';

    private const ORDER_BASE_FROM = 'FROM orders o JOIN sales s ON s.order_id = o.id';

    /**
     * KPI cards for the analytics dashboard.
     *
     * total_sales / transactions / avg_transaction_value come from the
     * ORDER level (net of transaction discount — the real cash figure).
     * units_sold / avg_basket_size come from the ITEM level, restricted
     * to the same filtered order set, so "average basket size" means
     * "average units per completed transaction" exactly as specified:
     *   avg_basket_size = total units sold / number of transactions.
     * retail_revenue / reseller_revenue are order-level totals split by
     * `sales.customer_type`.
     * low_stock_count / expiring_soon_count are CURRENT inventory state
     * (not affected by the date filter — they answer "right now", not
     * "during the selected period").
     *
     * @return array{total_sales:float, transactions:int, units_sold:int,
     *   avg_basket_size:float, avg_transaction_value:float,
     *   retail_revenue:float, reseller_revenue:float,
     *   low_stock_count:int, expiring_soon_count:int, has_data:bool}
     */
    public static function kpis(array $filters): array
    {
        [$orderWhere, $orderParams] = self::orderWhereClause($filters);

        $stmt = db()->prepare(
            'SELECT COUNT(*) AS transactions, COALESCE(SUM(o.total_amount), 0) AS total_sales,
                    COALESCE(SUM(CASE WHEN s.customer_type = "retail" THEN o.total_amount ELSE 0 END), 0) AS retail_revenue,
                    COALESCE(SUM(CASE WHEN s.customer_type = "reseller" THEN o.total_amount ELSE 0 END), 0) AS reseller_revenue
             ' . self::ORDER_BASE_FROM . " WHERE $orderWhere"
        );
        $stmt->execute($orderParams);
        $orderRow = $stmt->fetch();

        // Units sold — item level, but constrained to the SAME order set as above
        // (category/brand filters, if any, narrow this further and are intentionally
        // NOT applied to total_sales/transactions above — see class docblock).
        [$itemWhere, $itemParams] = self::itemWhereClause($filters);
        $unitsStmt = db()->prepare(
            'SELECT COALESCE(SUM(oi.quantity), 0) AS units_sold, COUNT(DISTINCT oi.order_id) AS item_transactions
             ' . self::ITEM_BASE_FROM . " WHERE $itemWhere"
        );
        $unitsStmt->execute($itemParams);
        $itemRow = $unitsStmt->fetch();

        $categoryOrBrandFiltered = !empty($filters['category_id']) || !empty($filters['brand_id']);
        $transactions = $categoryOrBrandFiltered ? (int) $itemRow['item_transactions'] : (int) $orderRow['transactions'];
        $unitsSold = (int) $itemRow['units_sold'];
        $totalSales = (float) $orderRow['total_sales'];

        $avgBasketSize = $transactions > 0 ? round($unitsSold / $transactions, 2) : 0.0;
        $avgTransactionValue = $transactions > 0 ? round($totalSales / $transactions, 2) : 0.0;

        // Current inventory state — independent of the date filter.
        $lowStockCount = (int) db()->query(
            "SELECT COUNT(*) FROM inventory i JOIN products p ON p.id = i.product_id
             WHERE p.status = 'active' AND i.quantity_on_hand <= p.reorder_level"
        )->fetchColumn();
        $expiringSoonCount = (int) db()->query(
            "SELECT COUNT(*) FROM products WHERE status = 'active' AND expiration_date IS NOT NULL
             AND expiration_date >= CURDATE() AND expiration_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)"
        )->fetchColumn();

        return [
            'total_sales' => $totalSales,
            'transactions' => $transactions,
            'units_sold' => $unitsSold,
            'avg_basket_size' => $avgBasketSize,
            'avg_transaction_value' => $avgTransactionValue,
            'retail_revenue' => (float) $orderRow['retail_revenue'],
            'reseller_revenue' => (float) $orderRow['reseller_revenue'],
            'low_stock_count' => $lowStockCount,
            'expiring_soon_count' => $expiringSoonCount,
            'has_data' => $transactions > 0,
        ];
    }

    /**
     * Retail vs Reseller comparison — item level (NET_REVENUE_EXPR), so it
     * reflects category/brand filters the same way categoryBreakdown()/
     * topSku() do. This intentionally differs in basis from kpis()'s
     * retail_revenue/reseller_revenue (which are ORDER-level and ignore
     * category/brand filters, matching the KPI card's "whole order" total)
     * — see class docblock on why order-level and item-level revenue are
     * two distinct, individually-consistent bases. The two will match
     * exactly whenever no category/brand filter is applied.
     *
     * @return array{retail: array{revenue:float,units_sold:int,transactions:int},
     *               reseller: array{revenue:float,units_sold:int,transactions:int}}
     */
    public static function retailVsReseller(array $filters): array
    {
        [$where, $params] = self::itemWhereClause($filters);
        $stmt = db()->prepare(
            "SELECT s.customer_type,
                    COALESCE(SUM(" . self::NET_REVENUE_EXPR . "), 0) AS revenue,
                    COALESCE(SUM(oi.quantity), 0) AS units_sold,
                    COUNT(DISTINCT oi.order_id) AS transactions
             " . self::ITEM_BASE_FROM . "
             WHERE $where
             GROUP BY s.customer_type"
        );
        $stmt->execute($params);

        $result = [
            'retail' => ['revenue' => 0.0, 'units_sold' => 0, 'transactions' => 0],
            'reseller' => ['revenue' => 0.0, 'units_sold' => 0, 'transactions' => 0],
        ];
        foreach ($stmt->fetchAll() as $r) {
            if (isset($result[$r['customer_type']])) {
                $result[$r['customer_type']] = [
                    'revenue' => (float) $r['revenue'],
                    'units_sold' => (int) $r['units_sold'],
                    'transactions' => (int) $r['transactions'],
                ];
            }
        }
        return $result;
    }

    /**
     * Sales-over-time series for charting.
     * $granularity: 'daily' | 'weekly' | 'monthly'.
     * Grouped at ORDER level (see class docblock) — one row per order, so
     * revenue here is the real net total_amount, matching the KPI card.
     *
     * @return array<int, array{period:string, revenue:float, transactions:int}>
     */
    public static function salesOverTime(array $filters, string $granularity = 'daily'): array
    {
        $periodExpr = match ($granularity) {
            'weekly' => 'DATE(DATE_SUB(o.created_at, INTERVAL WEEKDAY(o.created_at) DAY))',
            'monthly' => "DATE_FORMAT(o.created_at, '%Y-%m-01')",
            default => 'DATE(o.created_at)',
        };

        [$where, $params] = self::orderWhereClause($filters);
        $stmt = db()->prepare(
            "SELECT $periodExpr AS period, COALESCE(SUM(o.total_amount), 0) AS revenue, COUNT(*) AS transactions
             " . self::ORDER_BASE_FROM . " WHERE $where GROUP BY period ORDER BY period ASC"
        );
        $stmt->execute($params);

        return array_map(static fn($r) => [
            'period' => $r['period'],
            'revenue' => (float) $r['revenue'],
            'transactions' => (int) $r['transactions'],
        ], $stmt->fetchAll());
    }

    /**
     * Category breakdown — item level, revenue = NET_REVENUE_EXPR (see class
     * docblock) so this reconciles with the order-level "Total Sales" KPI.
     * Percentage is computed against the sum of the returned rows.
     *
     * @return array<int, array{category_id:?int, category_name:string, revenue:float,
     *   units_sold:int, transactions:int, percentage:float}>
     */
    public static function categoryBreakdown(array $filters): array
    {
        [$where, $params] = self::itemWhereClause($filters);
        $stmt = db()->prepare(
            "SELECT p.category_id, COALESCE(c.name, 'Uncategorized') AS category_name,
                    COALESCE(SUM(" . self::NET_REVENUE_EXPR . "), 0) AS revenue,
                    COALESCE(SUM(oi.quantity), 0) AS units_sold,
                    COUNT(DISTINCT oi.order_id) AS transactions
             " . self::ITEM_BASE_FROM . "
             LEFT JOIN categories c ON c.id = p.category_id
             WHERE $where
             GROUP BY p.category_id, category_name
             ORDER BY revenue DESC"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $totalRevenue = array_sum(array_column($rows, 'revenue'));

        return array_map(static function ($r) use ($totalRevenue) {
            $revenue = (float) $r['revenue'];
            return [
                'category_id' => $r['category_id'] !== null ? (int) $r['category_id'] : null,
                'category_name' => $r['category_name'],
                'revenue' => $revenue,
                'units_sold' => (int) $r['units_sold'],
                'transactions' => (int) $r['transactions'],
                'percentage' => $totalRevenue > 0 ? round(($revenue / $totalRevenue) * 100, 1) : 0.0,
            ];
        }, $rows);
    }

    /**
     * Top SKU by sales — item level, revenue = NET_REVENUE_EXPR (see class
     * docblock) so this reconciles with the order-level "Total Sales" KPI.
     * $sortBy: 'revenue' | 'units'.
     * revenue_percentage is against the sum of ALL matching items in the
     * filtered period (not just the returned/limited rows), so it's a
     * true share of filtered sales even when $limit truncates the list.
     *
     * @return array<int, array{rank:int, sku:string, name:string, category_name:string,
     *   units_sold:int, revenue:float, revenue_percentage:float}>
     */
    public static function topSku(array $filters, string $sortBy = 'revenue', int $limit = 20): array
    {
        [$where, $params] = self::itemWhereClause($filters);

        $totalStmt = db()->prepare(
            "SELECT COALESCE(SUM(" . self::NET_REVENUE_EXPR . "), 0) AS total_revenue " . self::ITEM_BASE_FROM . " WHERE $where"
        );
        $totalStmt->execute($params);
        $totalRevenue = (float) $totalStmt->fetchColumn();

        $orderCol = $sortBy === 'units' ? 'units_sold' : 'revenue';
        $limit = max(1, min($limit, 100));

        $stmt = db()->prepare(
            "SELECT p.id, p.sku, p.name, COALESCE(c.name, 'Uncategorized') AS category_name,
                    COALESCE(SUM(oi.quantity), 0) AS units_sold,
                    COALESCE(SUM(" . self::NET_REVENUE_EXPR . "), 0) AS revenue
             " . self::ITEM_BASE_FROM . "
             LEFT JOIN categories c ON c.id = p.category_id
             WHERE $where
             GROUP BY p.id, p.sku, p.name, category_name
             ORDER BY $orderCol DESC
             LIMIT $limit"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $rank = 0;
        return array_map(static function ($r) use (&$rank, $totalRevenue) {
            $rank++;
            $revenue = (float) $r['revenue'];
            return [
                'rank' => $rank,
                'product_id' => (int) $r['id'],
                'sku' => $r['sku'],
                'name' => $r['name'],
                'category_name' => $r['category_name'],
                'units_sold' => (int) $r['units_sold'],
                'revenue' => $revenue,
                'revenue_percentage' => $totalRevenue > 0 ? round(($revenue / $totalRevenue) * 100, 1) : 0.0,
            ];
        }, $rows);
    }

    /**
     * Stock analysis — one row per active product.
     *
     * Average Daily Sales = units sold in [date_from, date_to] / number of days in that range
     * Estimated Stock Duration (days) = current stock / average daily sales
     *   (null/labeled "N/A" when average daily sales is 0 — never divide by zero,
     *   never invent a number when there's no recent sales activity)
     *
     * Reorder recommendation (transparent, rule-based — no ML):
     *   - no sales data in period AND stock is low/critical  -> "Low Stock" (can't estimate duration)
     *   - no sales data in period AND stock is safe           -> "Sufficient"
     *   - estimated duration <= 0                             -> "Urgent Reorder"
     *   - estimated duration < supplier lead time              -> "Reorder Recommended"
     *   - stock status is low or critical (but duration is OK) -> "Low Stock"
     *   - otherwise                                            -> "Sufficient"
     *
     * Lead time used = product.lead_time_days if set, else the product's
     * distributor's lead_time_days, else 7 (documented fallback).
     *
     * @return array<int, array> empty array if there are no active products.
     */
    /**
     * @param array $filters date_from/date_to/category_id/brand_id — same as before.
     * @param ?array $productIds When provided, restricts to exactly these product IDs
     *   (ignoring category_id/brand_id/status) instead of "all active products". Added
     *   for Stage 4D's basket analysis, which needs stock/expiration context for a
     *   specific small set of products rather than the whole catalog — reuses this
     *   method's already-tested calculation instead of duplicating it.
     */
    public static function stockAnalysis(array $filters, ?array $productIds = null): array
    {
        $dateFrom = $filters['date_from'] ?? date('Y-m-d', strtotime('-29 days'));
        $dateTo = $filters['date_to'] ?? date('Y-m-d');
        $days = max(1, (strtotime($dateTo) - strtotime($dateFrom)) / 86400 + 1);

        $params = [];
        if ($productIds !== null) {
            if (!$productIds) {
                return [];
            }
            $placeholders = implode(',', array_fill(0, count($productIds), '?'));
            $whereSql = "p.id IN ($placeholders)";
            $params = array_values($productIds);
        } else {
            $where = ["p.status = 'active'"];
            if (!empty($filters['category_id'])) {
                $where[] = 'p.category_id = :category_id';
                $params['category_id'] = (int) $filters['category_id'];
            }
            if (!empty($filters['brand_id'])) {
                $where[] = 'p.brand_id = :brand_id';
                $params['brand_id'] = (int) $filters['brand_id'];
            }
            $whereSql = implode(' AND ', $where);
        }

        // Positional (?) placeholders are used for the product_ids IN-clause since PDO
        // can't mix named and unnamed params in one statement; d_from/d_to switch to
        // positional too in that branch to match.
        $usePositional = $productIds !== null;
        $dFromKey = $usePositional ? '?' : ':d_from';
        $dToKey = $usePositional ? '?' : ':d_to';

        $stmt = db()->prepare(
            "SELECT p.id, p.sku, p.name, p.reorder_level, p.lead_time_days AS product_lead_time,
                    d.lead_time_days AS distributor_lead_time,
                    COALESCE(i.quantity_on_hand, 0) AS current_stock,
                    COALESCE((
                        SELECT SUM(oi.quantity) FROM order_items oi
                        JOIN orders o ON o.id = oi.order_id
                        WHERE oi.product_id = p.id AND o.status = 'completed'
                          AND DATE(o.created_at) BETWEEN $dFromKey AND $dToKey
                    ), 0) AS units_sold_period
             FROM products p
             LEFT JOIN inventory i ON i.product_id = p.id
             LEFT JOIN distributors d ON d.id = p.primary_distributor_id
             WHERE $whereSql
             ORDER BY p.name ASC"
        );
        if ($usePositional) {
            $stmt->execute(array_merge($params, [$dateFrom, $dateTo]));
        } else {
            $params['d_from'] = $dateFrom;
            $params['d_to'] = $dateTo;
            $stmt->execute($params);
        }
        $rows = $stmt->fetchAll();

        $results = [];
        foreach ($rows as $r) {
            $currentStock = (int) $r['current_stock'];
            $reorderLevel = (int) $r['reorder_level'];
            $unitsSold = (int) $r['units_sold_period'];
            $avgDailySales = round($unitsSold / $days, 2);
            $leadTime = (int) ($r['product_lead_time'] ?? $r['distributor_lead_time'] ?? 7);

            $estimatedDays = $avgDailySales > 0 ? round($currentStock / $avgDailySales, 1) : null;
            $stockStatus = InventoryService::stockStatus($currentStock, $reorderLevel);

            if ($estimatedDays === null) {
                $recommendation = in_array($stockStatus, ['low', 'critical'], true) ? 'Low Stock' : 'Sufficient';
            } elseif ($estimatedDays <= 0) {
                $recommendation = 'Urgent Reorder';
            } elseif ($estimatedDays < $leadTime) {
                $recommendation = 'Reorder Recommended';
            } elseif (in_array($stockStatus, ['low', 'critical'], true)) {
                $recommendation = 'Low Stock';
            } else {
                $recommendation = 'Sufficient';
            }

            $results[] = [
                'product_id' => (int) $r['id'],
                'sku' => $r['sku'],
                'name' => $r['name'],
                'current_stock' => $currentStock,
                'reorder_level' => $reorderLevel,
                'stock_status' => $stockStatus,
                'units_sold_period' => $unitsSold,
                'avg_daily_sales' => $avgDailySales,
                'estimated_days_remaining' => $estimatedDays,
                'supplier_lead_time' => $leadTime,
                'recommendation' => $recommendation,
            ];
        }

        return $results;
    }

    /**
     * Expiration analysis — current state (not date-filtered), matches
     * InventoryService::expirationStatus()'s 30-day window.
     *
     * @return array{safe:int, expiring_soon:int, expired:int, items: array}
     */
    public static function expirationAnalysis(): array
    {
        $stmt = db()->query(
            "SELECT p.id, p.sku, p.name, p.expiration_date, COALESCE(i.quantity_on_hand, 0) AS current_stock
             FROM products p LEFT JOIN inventory i ON i.product_id = p.id
             WHERE p.status = 'active' AND p.expiration_date IS NOT NULL
             ORDER BY p.expiration_date ASC"
        );
        $rows = $stmt->fetchAll();

        $counts = ['safe' => 0, 'expiring_soon' => 0, 'expired' => 0];
        $items = [];
        foreach ($rows as $r) {
            $status = InventoryService::expirationStatus($r['expiration_date']);
            if (!$status) continue;
            $counts[$status]++;
            $items[] = [
                'product_id' => (int) $r['id'], 'sku' => $r['sku'], 'name' => $r['name'],
                'expiration_date' => $r['expiration_date'], 'current_stock' => (int) $r['current_stock'],
                'status' => $status,
            ];
        }

        return array_merge($counts, ['items' => $items]);
    }

    /**
     * Fast-moving / slow-moving products for the selected period, using
     * the SAME units_sold_period figures as stockAnalysis() so the two
     * pages never disagree.
     *
     * Transparent, fixed thresholds (documented, not tuned/ML-derived):
     *   - Fast-moving: the top $limit active products by units sold in
     *     the period (units sold > 0).
     *   - Slow-moving: active products currently IN STOCK (current_stock > 0)
     *     that sold $slowThreshold units or fewer in the period (default 2).
     *     Products with zero stock are excluded from "slow-moving" since
     *     the issue there is availability, not demand.
     *
     * $precomputedStockAnalysis: pass the result of a stockAnalysis() call
     * you already made with the SAME filters, to skip re-running that
     * query. A page that displays both the full stock table and the
     * fast/slow lists (as the analytics dashboard does) should always
     * pass this — otherwise every page load runs the stock-analysis
     * query twice.
     *
     * @return array{fast_moving: array, slow_moving: array}
     */
    public static function fastSlowMoving(array $filters, int $limit = 10, int $slowThreshold = 2, ?array $precomputedStockAnalysis = null): array
    {
        $all = $precomputedStockAnalysis ?? self::stockAnalysis($filters);
        if (!$all) {
            return ['fast_moving' => [], 'slow_moving' => []];
        }

        $fast = array_filter($all, static fn($r) => $r['units_sold_period'] > 0);
        usort($fast, static fn($a, $b) => $b['units_sold_period'] <=> $a['units_sold_period']);
        $fast = array_slice(array_values($fast), 0, $limit);

        $slow = array_filter($all, static fn($r) => $r['current_stock'] > 0 && $r['units_sold_period'] <= $slowThreshold);
        usort($slow, static fn($a, $b) => $a['units_sold_period'] <=> $b['units_sold_period']);
        $slow = array_slice(array_values($slow), 0, $limit);

        return ['fast_moving' => $fast, 'slow_moving' => $slow];
    }
}
