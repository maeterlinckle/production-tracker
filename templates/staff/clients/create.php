<?php /** @var array $errors */ /** @var array $old */ ?>
<?= partial("partials/back-link", ["href" => "/staff/clients", "label" => "Back to clients"]) ?>
<h1 class="mt-0">New client</h1>

<?php /*
    Its own form, because it posts somewhere else: this fetches and hands the
    values back as prefilled input rather than creating anything. Nothing is
    saved until the form below is submitted, so what came back can be corrected
    first.
*/ ?>
<form method="post" action="<?= url('/staff/clients/from-clearbooks') ?>" class="card">
    <?= csrf_field() ?>
    <h2 class="mt-0">Start from Clear Books</h2>
    <p class="text-muted">
        If this client is already a customer in Clear Books, fetch their details rather than typing them
        again. Their customer ID is the number in the address bar when that customer is open in Clear Books.
    </p>
    <div class="action-row">
        <label for="cb_lookup">Clear Books customer ID</label>
        <input type="number" class="input-qty" id="cb_lookup" name="clearbooks_entity_id" min="1"
               value="<?= old($old, 'clearbooks_entity_id') ?>">
        <button type="submit" class="btn">Fetch from Clear Books</button>
    </div>
</form>

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
            <div class="field"><label for="address_line1">Address line 1</label><input type="text" id="address_line1" name="address_line1" value="<?= old($old, 'address_line1') ?>"></div>
            <div class="field"><label for="address_line2">Address line 2</label><input type="text" id="address_line2" name="address_line2" value="<?= old($old, 'address_line2') ?>"></div>
        </div>
        <div class="form-row">
            <div class="field"><label for="address_county">County</label><input type="text" id="address_county" name="address_county" value="<?= old($old, 'address_county') ?>"></div>
            <div class="field"><label for="address_city">City</label><input type="text" id="address_city" name="address_city" value="<?= old($old, 'address_city') ?>"></div>
            <div class="field"><label for="address_postcode">Postcode</label><input type="text" id="address_postcode" name="address_postcode" value="<?= old($old, 'address_postcode') ?>"></div>
            <div class="field"><label for="address_country">Country</label><input type="text" id="address_country" name="address_country" value="<?= old($old, 'address_country') ?: 'United Kingdom' ?>"></div>
        </div>
        <div class="form-row">
            <div class="field"><label for="main_contact_name">Main contact name</label><input type="text" id="main_contact_name" name="main_contact_name" value="<?= old($old, 'main_contact_name') ?>"></div>
            <div class="field"><label for="main_contact_email">Main contact email</label><input type="email" id="main_contact_email" name="main_contact_email" value="<?= old($old, 'main_contact_email') ?>"></div>
            <div class="field"><label for="main_contact_phone">Main contact phone</label><input type="text" id="main_contact_phone" name="main_contact_phone" value="<?= old($old, 'main_contact_phone') ?>"></div>
        </div>
        <div class="field"><label for="billing_email">Billing email</label><input type="email" id="billing_email" name="billing_email" value="<?= old($old, 'billing_email') ?>"></div>
        <div class="form-row">
            <div class="field"><label for="vat_number">VAT number</label><input type="text" id="vat_number" name="vat_number" value="<?= old($old, 'vat_number') ?>"></div>
            <div class="field"><label for="company_number">Company number</label><input type="text" id="company_number" name="company_number" value="<?= old($old, 'company_number') ?>"></div>
        </div>
        <div class="field"><label for="notes">Notes</label><textarea id="notes" name="notes"><?= old($old, 'notes') ?></textarea></div>
    </div>
    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Create client</button>
        <a href="<?= url('/staff/clients') ?>" class="btn">Cancel</a>
    </div>
</form>
