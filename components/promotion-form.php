<?php
/**
 * Expects: $formData (array), $errors (array), $selectedProductIds (array of int),
 * $allProducts (array — active products with stock/expiration info), $isEdit (bool),
 * $activateNow (bool — current checkbox state on validation failure re-render).
 */
$selectedProductIds = $selectedProductIds ?? [];
$badgeMap = ['safe' => 'badge-success', 'low' => 'badge-warning', 'critical' => 'badge-danger', 'expiring_soon' => 'badge-warning', 'expired' => 'badge-danger'];
?>
<div class="row g-3">
    <div class="col-md-8">
        <label class="form-label">Promotion Name <span class="text-danger">*</span></label>
        <input type="text" name="name" class="form-control <?= isset($errors['name']) ? 'is-invalid' : '' ?>"
               value="<?= htmlspecialchars($formData['name'] ?? '') ?>" required>
        <?php if (isset($errors['name'])): ?><div class="invalid-feedback"><?= htmlspecialchars($errors['name']) ?></div><?php endif; ?>
    </div>
    <div class="col-md-4">
        <label class="form-label">Discount Type <span class="text-danger">*</span></label>
        <select name="discount_type" class="form-select <?= isset($errors['discount_type']) ? 'is-invalid' : '' ?>" id="discountType">
            <option value="">— Select —</option>
            <option value="percentage" <?= ($formData['discount_type'] ?? '') === 'percentage' ? 'selected' : '' ?>>Percentage Discount</option>
            <option value="fixed" <?= ($formData['discount_type'] ?? '') === 'fixed' ? 'selected' : '' ?>>Fixed Discount</option>
        </select>
        <?php if (isset($errors['discount_type'])): ?><div class="invalid-feedback"><?= htmlspecialchars($errors['discount_type']) ?></div><?php endif; ?>
    </div>

    <div class="col-12">
        <label class="form-label">Description</label>
        <textarea name="description" class="form-control" rows="2"><?= htmlspecialchars($formData['description'] ?? '') ?></textarea>
    </div>

    <div class="col-md-4">
        <label class="form-label">Discount Value <span class="text-danger">*</span></label>
        <div class="input-group">
            <span class="input-group-text" id="discountValuePrefix"><?= ($formData['discount_type'] ?? '') === 'fixed' ? '₱' : '%' ?></span>
            <input type="number" step="0.01" min="0" name="discount_value" class="form-control <?= isset($errors['discount_value']) ? 'is-invalid' : '' ?>"
                   value="<?= htmlspecialchars($formData['discount_value'] ?? '') ?>" required>
        </div>
        <?php if (isset($errors['discount_value'])): ?><div class="invalid-feedback d-block"><?= htmlspecialchars($errors['discount_value']) ?></div><?php endif; ?>
        <div class="form-text">Fixed discount is applied per unit at checkout (capped at the line total).</div>
    </div>
    <div class="col-md-4">
        <label class="form-label">Start Date <span class="text-danger">*</span></label>
        <input type="date" name="start_date" class="form-control <?= isset($errors['start_date']) ? 'is-invalid' : '' ?>"
               value="<?= htmlspecialchars($formData['start_date'] ?? '') ?>" required>
        <?php if (isset($errors['start_date'])): ?><div class="invalid-feedback"><?= htmlspecialchars($errors['start_date']) ?></div><?php endif; ?>
    </div>
    <div class="col-md-4">
        <label class="form-label">End Date <span class="text-danger">*</span></label>
        <input type="date" name="end_date" class="form-control <?= isset($errors['end_date']) ? 'is-invalid' : '' ?>"
               value="<?= htmlspecialchars($formData['end_date'] ?? '') ?>" required>
        <?php if (isset($errors['end_date'])): ?><div class="invalid-feedback"><?= htmlspecialchars($errors['end_date']) ?></div><?php endif; ?>
    </div>

    <div class="col-12">
        <div class="form-check">
            <input class="form-check-input" type="checkbox" name="activate_now" id="activateNow" value="1" <?= $activateNow ? 'checked' : '' ?>>
            <label class="form-check-label" for="activateNow">
                Activate this promotion <span class="text-muted">(leave unchecked to save as Inactive/Draft)</span>
            </label>
        </div>
    </div>

    <div class="col-12">
        <hr>
        <label class="form-label">Select Products <span class="text-danger">*</span></label>
        <?php if (isset($errors['products'])): ?><div class="alert alert-danger py-2 small"><?= htmlspecialchars($errors['products']) ?></div><?php endif; ?>
        <input type="text" id="productFilter" class="form-control form-control-sm mb-2" placeholder="Search product name or SKU...">
        <div class="table-responsive" style="max-height: 340px; overflow-y: auto; border: 1px solid var(--ts-border); border-radius: var(--ts-radius);">
            <table class="table table-sm align-middle mb-0" id="productPickerTable">
                <thead class="sticky-top bg-white"><tr><th></th><th>SKU</th><th>Product</th><th>Category</th><th>Price</th><th>Stock</th><th>Expiration</th></tr></thead>
                <tbody>
                <?php foreach ($allProducts as $p): ?>
                    <tr class="product-row" data-search="<?= htmlspecialchars(strtolower($p['name'] . ' ' . $p['sku'])) ?>">
                        <td><input type="checkbox" class="form-check-input" name="product_ids[]" value="<?= $p['id'] ?>" <?= in_array((int) $p['id'], $selectedProductIds, true) ? 'checked' : '' ?>></td>
                        <td class="small text-muted"><?= htmlspecialchars($p['sku']) ?></td>
                        <td><?= htmlspecialchars($p['name']) ?></td>
                        <td class="small"><?= htmlspecialchars($p['category_name'] ?? '—') ?></td>
                        <td>₱<?= number_format((float) $p['selling_price'], 2) ?></td>
                        <td><span class="badge-status <?= $badgeMap[$p['stock_status']] ?>"><?= (int) $p['quantity_on_hand'] ?> — <?= ucfirst($p['stock_status']) ?></span></td>
                        <td>
                            <?php if ($p['expiration_status']): ?>
                                <span class="badge-status <?= $badgeMap[$p['expiration_status']] ?>"><?= str_replace('_', ' ', ucfirst($p['expiration_status'])) ?></span>
                            <?php else: ?><span class="text-muted small">N/A</span><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
document.getElementById('discountType').addEventListener('change', function () {
    document.getElementById('discountValuePrefix').textContent = this.value === 'fixed' ? '₱' : '%';
});
document.getElementById('productFilter').addEventListener('input', function () {
    const term = this.value.toLowerCase();
    document.querySelectorAll('.product-row').forEach(row => {
        row.style.display = row.dataset.search.includes(term) ? '' : 'none';
    });
});
</script>
