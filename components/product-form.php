<?php
/**
 * Expects: $formData (array), $errors (array), $categories, $brands,
 * $distributors, $isEdit (bool).
 */
?>
<div class="row g-3">
    <div class="col-md-4">
        <label class="form-label">SKU <span class="text-danger">*</span></label>
        <input type="text" name="sku" class="form-control <?= isset($errors['sku']) ? 'is-invalid' : '' ?>"
               value="<?= htmlspecialchars($formData['sku'] ?? '') ?>" placeholder="e.g. SK-BR-011" required>
        <?php if (isset($errors['sku'])): ?><div class="invalid-feedback"><?= htmlspecialchars($errors['sku']) ?></div><?php endif; ?>
    </div>
    <div class="col-md-8">
        <label class="form-label">Product Name <span class="text-danger">*</span></label>
        <input type="text" name="name" class="form-control <?= isset($errors['name']) ? 'is-invalid' : '' ?>"
               value="<?= htmlspecialchars($formData['name'] ?? '') ?>" required>
        <?php if (isset($errors['name'])): ?><div class="invalid-feedback"><?= htmlspecialchars($errors['name']) ?></div><?php endif; ?>
    </div>

    <div class="col-md-6">
        <label class="form-label">Category</label>
        <select name="category_id" class="form-select">
            <option value="">— None —</option>
            <?php foreach ($categories as $c): ?>
                <option value="<?= $c['id'] ?>" <?= (string) ($formData['category_id'] ?? '') === (string) $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-6">
        <label class="form-label">Brand</label>
        <select name="brand_id" class="form-select">
            <option value="">— None —</option>
            <?php foreach ($brands as $b): ?>
                <option value="<?= $b['id'] ?>" <?= (string) ($formData['brand_id'] ?? '') === (string) $b['id'] ? 'selected' : '' ?>><?= htmlspecialchars($b['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="col-12">
        <label class="form-label">Description</label>
        <textarea name="description" class="form-control" rows="2"><?= htmlspecialchars($formData['description'] ?? '') ?></textarea>
    </div>

    <div class="col-md-3">
        <label class="form-label">Cost Price (₱) <span class="text-danger">*</span></label>
        <input type="number" step="0.01" min="0" name="cost_price" class="form-control <?= isset($errors['cost_price']) ? 'is-invalid' : '' ?>"
               value="<?= htmlspecialchars($formData['cost_price'] ?? '') ?>" required>
        <?php if (isset($errors['cost_price'])): ?><div class="invalid-feedback"><?= htmlspecialchars($errors['cost_price']) ?></div><?php endif; ?>
    </div>
    <div class="col-md-3">
        <label class="form-label">Selling Price (₱) <span class="text-danger">*</span></label>
        <input type="number" step="0.01" min="0" name="selling_price" class="form-control <?= isset($errors['selling_price']) ? 'is-invalid' : '' ?>"
               value="<?= htmlspecialchars($formData['selling_price'] ?? '') ?>" required>
        <?php if (isset($errors['selling_price'])): ?><div class="invalid-feedback"><?= htmlspecialchars($errors['selling_price']) ?></div><?php endif; ?>
    </div>
    <div class="col-md-3">
        <label class="form-label">Reorder Level <span class="text-danger">*</span></label>
        <input type="number" min="0" name="reorder_level" class="form-control <?= isset($errors['reorder_level']) ? 'is-invalid' : '' ?>"
               value="<?= htmlspecialchars($formData['reorder_level'] ?? '10') ?>" required>
        <?php if (isset($errors['reorder_level'])): ?><div class="invalid-feedback"><?= htmlspecialchars($errors['reorder_level']) ?></div><?php endif; ?>
    </div>
    <div class="col-md-3">
        <label class="form-label">Expiration Date</label>
        <input type="date" name="expiration_date" class="form-control <?= isset($errors['expiration_date']) ? 'is-invalid' : '' ?>"
               value="<?= htmlspecialchars($formData['expiration_date'] ?? '') ?>">
        <?php if (isset($errors['expiration_date'])): ?><div class="invalid-feedback"><?= htmlspecialchars($errors['expiration_date']) ?></div><?php endif; ?>
        <div class="form-text">Leave blank if not applicable.</div>
    </div>

    <div class="col-md-6">
        <label class="form-label">Distributor / Supplier</label>
        <select name="distributor_id" class="form-select">
            <option value="">— None —</option>
            <?php foreach ($distributors as $d): ?>
                <option value="<?= $d['id'] ?>" <?= (string) ($formData['distributor_id'] ?? '') === (string) $d['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($d['name']) ?> (lead time <?= (int) $d['lead_time_days'] ?>d)
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-3">
        <label class="form-label">Lead Time Override (days)</label>
        <input type="number" min="0" name="lead_time_days" class="form-control" value="<?= htmlspecialchars($formData['lead_time_days'] ?? '') ?>">
        <div class="form-text">Optional — overrides distributor default.</div>
    </div>
    <div class="col-md-3">
        <label class="form-label">Status</label>
        <select name="status" class="form-select">
            <option value="active" <?= ($formData['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
            <option value="archived" <?= ($formData['status'] ?? '') === 'archived' ? 'selected' : '' ?>>Archived</option>
        </select>
    </div>

    <?php if (!$isEdit): ?>
        <div class="col-md-4">
            <label class="form-label">Initial Stock Quantity</label>
            <input type="number" min="0" name="initial_stock" class="form-control" value="<?= htmlspecialchars($formData['initial_stock'] ?? '0') ?>">
            <div class="form-text">Recorded as a Stock-In movement.</div>
        </div>
    <?php endif; ?>
</div>
