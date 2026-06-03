<?php
/**
 * Template: native multi-step membership form.
 * Rendered by [cmm_membership_form] via CMM_Membership_Form::render_form().
 *
 * Available variables: $dues_amount, $submit_url, $nonce, $error
 *
 * Class scope: every selector is prefixed `cmm-mf-` to avoid colliding with
 * the legacy SureForms typeahead markup (which uses #cmm-address-input).
 */
if ( ! defined( 'ABSPATH' ) ) exit;
?>
<form method="post" action="<?php echo esc_url( $submit_url ); ?>" class="cmm-mf" novalidate>
    <input type="hidden" name="action"   value="cmm_membership_submit">
    <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">
    <input type="hidden" name="home_id"  value="" class="cmm-mf-home-id-input">

    <?php if ( $error ): ?>
    <div class="cmm-mf-server-error"><?php echo esc_html( $error ); ?></div>
    <?php endif; ?>

    <ol class="cmm-mf-steps" aria-label="Progress">
        <li data-step="1" class="active"><span>1</span> Address</li>
        <li data-step="2"><span>2</span> Account</li>
        <li data-step="3"><span>3</span> Details</li>
        <li data-step="4"><span>4</span> Submit</li>
    </ol>

    <!-- ============================================================== -->
    <!-- Step 1: Address                                                 -->
    <!-- ============================================================== -->
    <section class="cmm-mf-panel" data-step="1">
        <h2>Find your address</h2>
        <p class="cmm-mf-hint">Start typing your WPI street address and pick it from the list.</p>

        <label class="cmm-mf-label">
            Street Address <span class="cmm-mf-req">*</span>
            <div class="cmm-mf-typeahead">
                <input type="text"
                       class="cmm-mf-address-input"
                       autocomplete="off"
                       placeholder="e.g. 196 Pershing Blvd">
                <div class="cmm-mf-address-dropdown" hidden></div>
            </div>
        </label>

        <div class="cmm-mf-status-card" aria-live="polite"></div>

        <div class="cmm-mf-actions">
            <button type="button" class="cmm-mf-btn cmm-mf-btn-primary cmm-mf-next">Continue &rarr;</button>
        </div>
    </section>

    <!-- ============================================================== -->
    <!-- Step 2: Account                                                 -->
    <!-- ============================================================== -->
    <section class="cmm-mf-panel" data-step="2">
        <h2>Your account</h2>

        <label class="cmm-mf-label">
            Email <span class="cmm-mf-req">*</span>
            <input type="email"
                   name="email"
                   class="cmm-mf-email-input"
                   autocomplete="email"
                   inputmode="email">
        </label>

        <div class="cmm-mf-account-feedback" aria-live="polite"></div>

        <div class="cmm-mf-new-account" hidden>
            <label class="cmm-mf-label">
                Username <span class="cmm-mf-req">*</span>
                <small class="cmm-mf-hint">Letters, numbers, dots, and underscores. This becomes your login name.</small>
                <input type="text"
                       name="username"
                       class="cmm-mf-username-input"
                       autocomplete="off"
                       minlength="3"
                       pattern="[A-Za-z0-9._-]+">
            </label>
            <div class="cmm-mf-username-feedback" aria-live="polite"></div>

            <label class="cmm-mf-label">
                Password <span class="cmm-mf-req">*</span>
                <small class="cmm-mf-hint">At least 8 characters.</small>
                <input type="password"
                       name="password"
                       class="cmm-mf-password-input"
                       autocomplete="new-password"
                       minlength="8">
            </label>
            <div class="cmm-mf-password-meter" aria-live="polite"></div>
        </div>

        <div class="cmm-mf-actions">
            <button type="button" class="cmm-mf-btn cmm-mf-prev">&larr; Back</button>
            <button type="button" class="cmm-mf-btn cmm-mf-btn-primary cmm-mf-next">Continue &rarr;</button>
        </div>
    </section>

    <!-- ============================================================== -->
    <!-- Step 3: Details                                                 -->
    <!-- ============================================================== -->
    <section class="cmm-mf-panel" data-step="3">
        <h2>Your details</h2>

        <div class="cmm-mf-row">
            <label class="cmm-mf-label">
                First Name <span class="cmm-mf-req">*</span>
                <input type="text" name="first_name" autocomplete="given-name">
            </label>
            <label class="cmm-mf-label">
                Last Name <span class="cmm-mf-req">*</span>
                <input type="text" name="last_name" autocomplete="family-name">
            </label>
        </div>

        <label class="cmm-mf-label">
            Mobile Phone
            <input type="tel" name="mobile" autocomplete="tel" placeholder="555-555-5555">
        </label>

        <h3>Household</h3>

        <div class="cmm-mf-row">
            <label class="cmm-mf-label">
                Spouse First Name
                <input type="text" name="spouse_first_name">
            </label>
            <label class="cmm-mf-label">
                Spouse Last Name
                <input type="text" name="spouse_last_name">
            </label>
        </div>

        <label class="cmm-mf-label">
            Children (Names &amp; Ages)
            <small class="cmm-mf-hint">No badge charge for children under 12.</small>
            <textarea name="children" rows="3" placeholder="e.g. Bobby (5), Alice (7)"></textarea>
        </label>

        <label class="cmm-mf-checkbox">
            <input type="checkbox" name="directory_listed" value="1">
            List our household in the Community Directory
        </label>

        <h3>Off-Island Primary Home Address <small>(optional)</small></h3>
        <p class="cmm-mf-hint">Fill in only if your primary residence is off-island.</p>

        <label class="cmm-mf-label">
            Street Address
            <input type="text" name="primary_street" autocomplete="address-line1">
        </label>

        <div class="cmm-mf-row">
            <label class="cmm-mf-label">
                City
                <input type="text" name="primary_city" autocomplete="address-level2">
            </label>
            <label class="cmm-mf-label cmm-mf-narrow">
                State
                <input type="text" name="primary_state" autocomplete="address-level1" maxlength="2">
            </label>
            <label class="cmm-mf-label cmm-mf-narrow">
                Postal Code
                <input type="text" name="primary_zip" autocomplete="postal-code" maxlength="10">
            </label>
        </div>

        <div class="cmm-mf-actions">
            <button type="button" class="cmm-mf-btn cmm-mf-prev">&larr; Back</button>
            <button type="button" class="cmm-mf-btn cmm-mf-btn-primary cmm-mf-next">Continue &rarr;</button>
        </div>
    </section>

    <!-- ============================================================== -->
    <!-- Step 4: Review & Submit                                         -->
    <!-- ============================================================== -->
    <section class="cmm-mf-panel" data-step="4">
        <h2>Review &amp; Submit</h2>
        <div class="cmm-mf-review"></div>

        <div class="cmm-mf-dues-summary">
            Annual dues: <strong>$<?php echo esc_html( number_format( $dues_amount, 2 ) ); ?></strong>
            <p class="cmm-mf-hint">
                Clicking Submit activates your membership. You'll then see instructions
                for completing your dues payment.
            </p>
        </div>

        <div class="cmm-mf-actions">
            <button type="button" class="cmm-mf-btn cmm-mf-prev">&larr; Back</button>
            <button type="submit" class="cmm-mf-btn cmm-mf-btn-primary cmm-mf-submit">
                Submit Membership &rarr;
            </button>
        </div>
    </section>
</form>
