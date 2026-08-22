<?php /** @var array $errors */ /** @var array $old */ ?>
<?= partial("partials/back-link", ["href" => "/staff/clients", "label" => "Back to clients"]) ?>
<h1 class="mt-0">New client</h1>

<form method="post" action="<?= url('/staff/clients') ?>">
    <?= csrf_field() ?>
    <div class="card">
        <div class="form-row">
            <div class="field">
                <label for="name">Client name</label>
                <input type="text" id="name" name="name" value="<?= old($old, 'name') ?>" required>
                <?php if (isset($errors['name'])): ?><div class="error"><?= e($errors['name']) ?></div><?php endif; ?>
            </div>
            <div class="field">
                <label for="clearbooks_entity_id">Clear Books customer ID</label>
                <input type="text" id="clearbooks_entity_id" name="clearbooks_entity_id" value="<?= old($old, 'clearbooks_entity_id') ?>">
            </div>
        </div>
        <div class="form-row">
            <div class="field"><label for="address_line1">Address line 1</label><input type="text" id="address_line1" name="address_line1"></div>
            <div class="field"><label for="address_line2">Address line 2</label><input type="text" id="address_line2" name="address_line2"></div>
        </div>
        <div class="form-row">
            <div class="field"><label for="address_city">City</label><input type="text" id="address_city" name="address_city"></div>
            <div class="field"><label for="address_postcode">Postcode</label><input type="text" id="address_postcode" name="address_postcode"></div>
            <div class="field"><label for="address_country">Country</label><input type="text" id="address_country" name="address_country" value="United Kingdom"></div>
        </div>
        <div class="form-row">
            <div class="field"><label for="main_contact_name">Main contact name</label><input type="text" id="main_contact_name" name="main_contact_name"></div>
            <div class="field"><label for="main_contact_email">Main contact email</label><input type="email" id="main_contact_email" name="main_contact_email"></div>
            <div class="field"><label for="main_contact_phone">Main contact phone</label><input type="text" id="main_contact_phone" name="main_contact_phone"></div>
        </div>
        <div class="field"><label for="billing_email">Billing email</label><input type="email" id="billing_email" name="billing_email"></div>
        <div class="field"><label for="notes">Notes</label><textarea id="notes" name="notes"></textarea></div>
    </div>
    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Create client</button>
        <a href="<?= url('/staff/clients') ?>" class="btn">Cancel</a>
    </div>
</form>
