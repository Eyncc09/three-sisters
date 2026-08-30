#!/usr/bin/env python3
"""
Three Sisters' Olshoppe — Basket Analysis / Frequent Item Mining (Stage 4D)

WHAT THIS IS
------------
A plain, transparent statistics pass over real completed-order line items,
computing standard market-basket association-rule metrics: Support,
Confidence, and Lift. It is combinatorics over real transaction data, not a
trained model and not a prediction — there is nothing here that "learns"
or generalizes beyond the exact historical transactions given to it.

WHY THIS DESIGN
---------------
- No database connection here at all. PHP already queries the DB (it has
  the connection, credentials, and ORM-ish services); it hands this script
  a plain JSON list of "baskets" (one list of product IDs per completed
  order) via STDIN, and this script hands back JSON results via STDOUT.
  This is the "simplest safe integration possible" the spec asks for: no
  Python DB driver to install, no SQL injection surface, no way for this
  process to do anything except pure computation on the data it was given.
- Every parameter (thresholds, max results) also arrives via the same JSON
  stdin payload — never via argv — so there is no command-line argument
  surface to inject through at all.
- Pairwise only (no 3+ item combinations). Documented limitation: keeps the
  result explainable ("these two products are often bought together") and
  keeps the computation O(items^2) per basket instead of exploding
  combinatorially on a larger catalog — appropriate at capstone/demo scale,
  called out explicitly rather than silently limited.

INPUT (stdin, JSON):
{
  "transactions": [[12, 47], [12], [47, 3], ...],   // one list of product IDs per completed order
  "min_support": 0.01,
  "min_confidence": 0.1,
  "min_lift": 1.0,
  "max_results": 50
}

OUTPUT (stdout, JSON):
  Success: {"status": "ok", "total_transactions": N, "pairs": [ {...}, ... ]}
  Error:   {"status": "error", "message": "..."}  (also a non-zero exit code)

Each pair object:
{
  "product_a": 12, "product_b": 47,
  "transactions_together": 30,
  "support": 0.03,
  "confidence_a_to_b": 0.30, "confidence_b_to_a": 0.15,
  "lift": 2.1
}

Note on lift: lift(A->B) and lift(B->A) are mathematically identical
( = P(A and B) / (P(A) * P(B)) ), so only one "lift" value is returned per
pair rather than two directional ones — unlike confidence, which IS
directional and is reported both ways.
"""

import sys
import json
import itertools
from collections import defaultdict


def compute_basket_analysis(transactions, min_support, min_confidence, min_lift, max_results):
    # Defensive: dedupe each basket (a product listed twice in one order must
    # only count once for "does this transaction contain this product").
    # This also protects against duplicate order_items rows for the same
    # product on the PHP side, without needing to trust that PHP already did it.
    baskets = [set(int(pid) for pid in basket) for basket in transactions if basket]
    n = len(baskets)

    if n == 0:
        return {"status": "ok", "total_transactions": 0, "pairs": []}

    item_counts = defaultdict(int)
    pair_counts = defaultdict(int)

    for basket in baskets:
        for item in basket:
            item_counts[item] += 1
        # sorted() so each unordered pair is counted exactly once as (smaller, larger)
        for a, b in itertools.combinations(sorted(basket), 2):
            pair_counts[(a, b)] += 1

    pairs = []
    for (a, b), together in pair_counts.items():
        support = together / n
        if support < min_support:
            continue

        count_a = item_counts[a]
        count_b = item_counts[b]
        # Zero-division protection: count_a/count_b can never actually be 0 here
        # (a pair count > 0 implies both items appeared at least once), but the
        # guard is kept explicit rather than assumed.
        confidence_a_to_b = (together / count_a) if count_a > 0 else 0.0
        confidence_b_to_a = (together / count_b) if count_b > 0 else 0.0

        support_a = count_a / n
        support_b = count_b / n
        # lift = P(A and B) / (P(A) * P(B)) — see docstring: symmetric, so one value.
        denom = support_a * support_b
        lift = (support / denom) if denom > 0 else 0.0

        if lift < min_lift:
            continue
        if max(confidence_a_to_b, confidence_b_to_a) < min_confidence:
            continue

        pairs.append({
            "product_a": a,
            "product_b": b,
            "transactions_together": together,
            "support": round(support, 6),
            "confidence_a_to_b": round(confidence_a_to_b, 6),
            "confidence_b_to_a": round(confidence_b_to_a, 6),
            "lift": round(lift, 6),
        })

    # Rank by lift first (strength of association), support as tiebreaker.
    pairs.sort(key=lambda p: (p["lift"], p["support"]), reverse=True)
    if max_results and max_results > 0:
        pairs = pairs[:max_results]

    return {"status": "ok", "total_transactions": n, "pairs": pairs}


def main():
    try:
        raw = sys.stdin.read()
        payload = json.loads(raw)
    except (json.JSONDecodeError, ValueError) as e:
        print(json.dumps({"status": "error", "message": f"Invalid JSON input: {e}"}))
        sys.exit(1)

    transactions = payload.get("transactions")
    if not isinstance(transactions, list):
        print(json.dumps({"status": "error", "message": "'transactions' must be a list of product-ID lists."}))
        sys.exit(1)

    try:
        min_support = float(payload.get("min_support", 0.01))
        min_confidence = float(payload.get("min_confidence", 0.1))
        min_lift = float(payload.get("min_lift", 1.0))
        max_results = int(payload.get("max_results", 50))
    except (TypeError, ValueError) as e:
        print(json.dumps({"status": "error", "message": f"Invalid threshold parameter: {e}"}))
        sys.exit(1)

    # Clamp to sane ranges rather than trusting caller-supplied thresholds outright.
    min_support = min(max(min_support, 0.0), 1.0)
    min_confidence = min(max(min_confidence, 0.0), 1.0)
    min_lift = max(min_lift, 0.0)
    max_results = min(max(max_results, 1), 200)

    try:
        result = compute_basket_analysis(transactions, min_support, min_confidence, min_lift, max_results)
    except Exception as e:  # noqa: BLE001 — deliberately broad: this must never crash with a raw traceback
        print(json.dumps({"status": "error", "message": f"Basket analysis failed: {e}"}))
        sys.exit(1)

    print(json.dumps(result))
    sys.exit(0)


if __name__ == "__main__":
    main()
