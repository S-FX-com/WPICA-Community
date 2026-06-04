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
        <div class="cmm-mf-hero" aria-hidden="true">
            <span class="cmm-mf-hero-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
                    <circle cx="12" cy="10" r="3"></circle>
                </svg>
            </span>
        </div>
        <h2>Find your address</h2>
        <p class="cmm-mf-subtitle">Start typing your WPI street address, then select the matching result below.</p>

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
            <label class="cmm-mf-label">
                Mobile Phone
                <input type="tel" name="mobile" autocomplete="tel" placeholder="555-555-5555">
            </label>
        </div>

        <div class="cmm-mf-section">
            <div class="cmm-mf-section-heading">
                <span class="cmm-mf-section-icon" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                        <circle cx="9" cy="7" r="4"></circle>
                        <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                        <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                    </svg>
                </span>
                <h3>Household</h3>
            </div>

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
        </div>

        <div class="cmm-mf-section">
            <div class="cmm-mf-section-heading">
                <span class="cmm-mf-section-icon" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M3 9.5 12 3l9 6.5V21a1 1 0 0 1-1 1h-5v-7h-6v7H4a1 1 0 0 1-1-1Z"></path>
                    </svg>
                </span>
                <h3>Off-Island Primary Home Address <small class="cmm-mf-optional">(optional)</small></h3>
            </div>
            <p class="cmm-mf-hint">Only complete this section if your primary residence is off-island.</p>

            <label class="cmm-mf-label">
                Street Address
                <input type="text" name="primary_street" autocomplete="address-line1">
            </label>

            <div class="cmm-mf-row cmm-mf-row-csz">
                <label class="cmm-mf-label">
                    City
                    <input type="text" name="primary_city" autocomplete="address-level2">
                </label>
                <label class="cmm-mf-label">
                    State
                    <input type="text" name="primary_state" autocomplete="address-level1" maxlength="2">
                </label>
                <label class="cmm-mf-label">
                    Postal Code
                    <input type="text" name="primary_zip" autocomplete="postal-code" maxlength="10">
                </label>
            </div>
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
