<?php /** Expects: $formData, $errors */ ?>
<div class="row g-3">
    <div class="col-md-8">
        <label class="form-label">Distributor Name <span class="text-danger">*</span></label>
        <input type="text" name="name" class="form-control <?= isset($errors['name']) ? 'is-invalid' : '' ?>" value="<?= htmlspecialchars($formData['name'] ?? '') ?>" required>
        <?php if (isset($errors['name'])): ?><div class="invalid-feedback"><?= htmlspecialchars($errors['name']) ?></div><?php endif; ?>
    </div>
    <div class="col-md-4">
        <label class="form-label">Lead Time (days) <span class="text-danger">*</span></label>
        <input type="number" min="1" name="lead_time_days" class="form-control <?= isset($errors['lead_time_days']) ? 'is-invalid' : '' ?>" value="<?= htmlspecialchars($formData['lead_time_days'] ?? '7') ?>" required>
        <?php if (isset($errors['lead_time_days'])): ?><div class="invalid-feedback"><?= htmlspecialchars($errors['lead_time_days']) ?></div><?php endif; ?>
    </div>
    <div class="col-md-6">
        <label class="form-label">Contact Person</label>
        <input type="text" name="contact_person" class="form-control" value="<?= htmlspecialchars($formData['contact_person'] ?? '') ?>">
    </div>
    <div class="col-md-3">
        <label class="form-label">Phone</label>
        <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($formData['phone'] ?? '') ?>">
    </div>
    <div class="col-md-3">
        <label class="form-label">Email</label>
        <input type="email" name="email" class="form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>" value="<?= htmlspecialchars($formData['email'] ?? '') ?>">
        <?php if (isset($errors['email'])): ?><div class="invalid-feedback"><?= htmlspecialchars($errors['email']) ?></div><?php endif; ?>
    </div>
    <div class="col-md-8">
        <label class="form-label">Address</label>
        <input type="text" name="address" class="form-control" value="<?= htmlspecialchars($formData['address'] ?? '') ?>">
    </div>
    <div class="col-md-4">
        <label class="form-label">Status</label>
        <select name="status" class="form-select">
            <option value="active" <?= ($formData['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
            <option value="inactive" <?= ($formData['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
        </select>
    </div>
</div>
