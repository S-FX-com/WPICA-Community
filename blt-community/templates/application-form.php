<?php
/**
 * Template: Registration / application form.
 * Drop this template on the /register/ page or use via SureForms Custom HTML.
 * The address typeahead field is wired to the REST endpoint via cmm-address-typeahead.js.
 */

if ( ! defined( 'ABSPATH' ) ) exit;
?>
<div class="cmm-application-form">

    <div class="cmm-address-field" style="position:relative;margin-bottom:16px;">
        <label for="cmm-address-input" style="display:block;margin-bottom:4px;font-weight:600;">
            Your Property Address <span style="color:#d63638;">*</span>
        </label>
        <input type="text" id="cmm-address-input" name="cmm_address_text"
               placeholder="Start typing your address…"
               autocomplete="off"
               style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;">
        <input type="hidden" id="cmm-home-id" name="cmm_home_id">

        <div id="cmm-address-dropdown"
             style="display:none;position:absolute;top:100%;left:0;right:0;
                    background:#fff;border:1px solid #ccc;border-radius:0 0 4px 4px;
                    max-height:200px;overflow-y:auto;z-index:999;">
        </div>

        <div id="cmm-address-code-display"
             style="display:none;margin-top:6px;padding:6px 10px;background:#f0f7ff;
                    border:1px solid #b0d0f0;border-radius:4px;
                    font-family:monospace;font-size:1.1em;color:#2271b1;">
        </div>
    </div>

    <!-- Standard SureForms fields follow here.
         First Name, Last Name, Email, Password, Checkbox. -->

</div>
