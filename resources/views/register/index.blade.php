<?php $version = env('JS_VERSION', '1'); ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Experience vivo MR Glasses</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" />
    <link rel="stylesheet" href="{{ url('/assets/css/register.css?v='.$version) }}" />

    <script async src="https://www.googletagmanager.com/gtag/js?id=G-VK8R0GLFQS"></script>


    <script>
        window.dataLayer = window.dataLayer || [];

        function gtag() {
            dataLayer.push(arguments);
        }
        gtag('js', new Date());

        gtag('config', 'G-VK8R0GLFQS');
    </script>


    <!-- Google Tag Manager -->
    <script>
        (function(w, d, s, l, i) {
            w[l] = w[l] || [];
            w[l].push({
                'gtm.start': new Date().getTime(),
                event: 'gtm.js'
            });
            var f = d.getElementsByTagName(s)[0],
                j = d.createElement(s),
                dl = l != 'dataLayer' ? '&l=' + l : '';
            j.async = true;
            j.src =
                'https://www.googletagmanager.com/gtm.js?id=' + i + dl;
            f.parentNode.insertBefore(j, f);
        })(window, document, 'script', 'dataLayer', 'GTM-P3DTRG76');
    </script>
    <!-- End Google Tag Manager -->
</head>
<body class="reg-page">

<div class="container-fluid reg-wrap px-3 px-lg-5">
    <div class="row g-4">

        {{-- Hero --}}
        <div class="col-12 col-lg-5">
            <img src="{{ url('/assets/images/vivo_logo.svg') }}" alt="vivo" class="reg-logo">
            <h1 class="reg-title">Experience <span>vivo Vision</span></h1>
            <div class="row g-3 align-items-center">
                <div class="">
                    <p class="reg-lead mb-0">vivo Vision opens the door to a new era of spatial computing, where the digital and physical worlds come together. Step into breathtaking immersion with a giant, cinema-like 8K display, spatial audio and lifelike 3D experiences. Relive memories in spatial detail, explore panoramic worlds and experience entertainment like never before. With intuitive eye-and-hand interaction and an expansive multi-window workspace, work and play become more natural and limitless.</p>
                </div>
                <div class="img-cont">
                    <img src="{{ url('/assets/images/Mr_glasses_1.png') }}" alt="vivo MR Glasses" class="reg-hero-img">
                </div>
            </div>
        </div>

        {{-- Form / success --}}
        <div class="col-12 col-lg-7">
            <div class="reg-card">

                {{-- Registration form --}}
                <div id="formPanel">
                    <h2 class="reg-card-title">Register for an exclusive presentation.</h2>
                    <p class="mb-4">Please fill in your details to register.</p>

                    <div id="formAlert" class="alert alert-danger d-none" role="alert"></div>

                    <form id="regForm" novalidate>
                        <div class="row g-3">
                            <div class="col-12 col-md-6">
                                <label class="form-label" for="full_name">Full name<span class="req">*</span></label>
                                <input type="text" class="form-control" id="full_name" name="full_name" maxlength="150" placeholder="Enter your full name" autocomplete="name">
                                <div class="invalid-feedback"></div>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label" for="mobile">WhatsApp number<span class="req">*</span></label>
                                <div class="input-group has-validation">
                                    <span class="input-group-text">+91</span>
                                    <input type="tel" class="form-control" id="mobile" name="mobile" maxlength="10" inputmode="numeric" placeholder="Enter your whatsapp number" autocomplete="tel-national">
                                    <div class="invalid-feedback"></div>
                                </div>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label d-block">Do you wear glasses or have a prescription?<span class="req">*</span></label>
                                <div class="d-flex gap-4">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="glasses_yes">
                                        <label class="form-check-label" for="glasses_yes">Yes</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="glasses_no">
                                        <label class="form-check-label" for="glasses_no">No</label>
                                    </div>
                                </div>
                                <div class="text-danger small mt-1 d-none" id="glasses_error">Please select an option.</div>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label" for="city_id">City<span class="req">*</span></label>
                                <select class="form-select" id="city_id" name="city_id">
                                    <option value="">Select your city</option>
                                </select>
                                <div class="invalid-feedback"></div>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label" for="store_id">Store<span class="req">*</span></label>
                                <select class="form-select" id="store_id" name="store_id" disabled>
                                    <option value="">Select your nearest store</option>
                                </select>
                                <div class="invalid-feedback"></div>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label" for="slot_date">Select a date<span class="req">*</span></label>
                                <input type="date" class="form-control text-uppercase" id="slot_date" name="slot_date">
                                <div class="invalid-feedback"></div>
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="time_slot_id">Select a time slot<span class="req">*</span></label>
                                <select class="form-select" id="time_slot_id" name="time_slot_id" disabled>
                                    <option value="">Select store and date first</option>
                                </select>
                                <div class="invalid-feedback"></div>
                            </div>
                        </div>

                        <p class="fw-semibold mt-4 mb-2">Terms &amp; Conditions</p>
                        <div class="reg-terms">
                            <ol>
                                <li>Glasses and coloured contact lenses should not be worn while using vivo Vision. Corrective lenses, where required, will be made available at the venue, subject to availability.</li>
                                <li>vivo Vision may not be suitable for certain vision conditions, including but not limited to high myopia (greater than −10.00 power), farsightedness, and presbyopia. Consumers with any such condition, or any other eye or health condition, are advised to disclose the same to the venue's staff before use.</li>
                                <li>Consumers with mild myopia who do not otherwise wear corrective lenses may be offered low-degree lenses (−0.50 to −2.50 power), subject to availability.</li>
                                <li>XR products are head-mounted devices. Use may involve contact with hair, skin, and face, and may affect hairstyle or makeup. Consumers with skin sensitivities, allergies, open wounds, or infections in the area of contact are advised not to use the device, or to inform staff before use.</li>
                                <li>Use of XR product at the venue is at the consumer's own risk. vivo Mobile India Private Limited shall not be liable for any damage, injury, or loss, including but not limited to discomfort, motion sickness, or allergic reaction, arising from such use.</li>
                                <li>This registration is a request for a demo/experience slot only, and does not constitute a purchase, order, or a guarantee of product availability, specifications, or launch. vivo Mobile India Private Limited reserves the right to reschedule, decline, or cancel any registration, including on grounds of eligibility under these Terms, at their sole discretion.</li>
                                <li>These Terms are governed by the laws of India. vivo Mobile India Private Limited reserves the right to amend these Terms at any time; the version in force at the time of registration shall apply.</li>
                            </ol>
                            <p class="reg-disclaimer mb-0"><strong>Disclaimer:</strong> The phone number and any other personal data provided at registration will be processed solely for the purpose of confirming and facilitating this registration/appointment, and for no other purpose, unless separate consent is obtained. For more details, see vivo's <a href="https://www.vivo.com/in/content/privacy-policy" target="_blank" rel="noopener">Privacy Policy</a>.</p>
                        </div>

                        <div class="form-check mt-3">
                            <input class="form-check-input" type="checkbox" id="age_confirmed" name="age_confirmed">
                            <label class="form-check-label" for="age_confirmed">I confirm I am 18 years of age or above.<span class="req text-danger">*</span></label>
                            <div class="invalid-feedback">Please confirm you are 18 years of age or above.</div>
                        </div>

                        <div class="form-check mt-2">
                            <input class="form-check-input" type="checkbox" id="terms" name="terms">
                            <label class="form-check-label" for="terms">I have read and agree to the above Terms &amp; Conditions.<span class="req text-danger">*</span></label>
                            <div class="invalid-feedback">Please accept the Terms &amp; Conditions.</div>
                        </div>

                        <div class="text-center mt-4">
                            <button type="submit" class="btn btn-register" id="submitBtn" disabled>Register</button>
                        </div>
                    </form>
                </div>

                {{-- Success --}}
                <div id="successPanel" class="reg-success d-none">
                    <img src="{{ url('/assets/images/success_gif.gif') }}" alt="">
                    <h2>Congratulations</h2>
                    <h3>Your Slot has been successfully reserved.</h3>
                    <p>We look forward to welcoming you at vivo MR Glasses presentation. Please arrive a few minutes before your scheduled time. Confirmation has been sent to your registered mobile number.</p>
                </div>

            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var base_url = "{{ url('/') }}";

    var form      = document.getElementById('regForm');
    var alertBox  = document.getElementById('formAlert');
    var submitBtn = document.getElementById('submitBtn');
    var citySel   = document.getElementById('city_id');
    var storeSel  = document.getElementById('store_id');
    var dateInp   = document.getElementById('slot_date');
    var slotSel   = document.getElementById('time_slot_id');
    var mobileInp = document.getElementById('mobile');
    var glassesYes = document.getElementById('glasses_yes');
    var glassesNo   = document.getElementById('glasses_no');
    var glassesErr  = document.getElementById('glasses_error');
    var termsInp  = document.getElementById('terms');
    var ageInp    = document.getElementById('age_confirmed');

    var stores = [];
    var enhancedSelects = [citySel, storeSel, slotSel];

    function closeCustomSelects(except) {
        enhancedSelects.forEach(function (select) {
            if (select._regSelect && select._regSelect.wrap !== except) {
                select._regSelect.wrap.classList.remove('open');
            }
        });
    }

    function syncCustomSelect(select) {
        if (!select._regSelect) return;

        var custom = select._regSelect;
        var selected = select.options[select.selectedIndex];
        custom.toggle.textContent = selected ? selected.textContent : '';
        custom.toggle.disabled = select.disabled;
        custom.toggle.classList.toggle('is-invalid', select.classList.contains('is-invalid'));
        custom.menu.innerHTML = '';

        Array.prototype.forEach.call(select.options, function (opt) {
            var item = document.createElement('button');
            item.type = 'button';
            item.className = 'reg-select-option';
            item.textContent = opt.textContent;
            item.disabled = opt.disabled;
            item.dataset.value = opt.value;
            if (opt.selected) item.classList.add('selected');
            item.addEventListener('click', function () {
                if (item.disabled) return;
                select.value = opt.value;
                select.dispatchEvent(new Event('change', { bubbles: true }));
                closeCustomSelects();
                syncCustomSelect(select);
            });
            custom.menu.appendChild(item);
        });
    }

    function enhanceSelect(select) {
        var wrap = document.createElement('div');
        var toggle = document.createElement('button');
        var menu = document.createElement('div');

        wrap.className = 'reg-select';
        toggle.type = 'button';
        toggle.className = 'reg-select-toggle';
        menu.className = 'reg-select-menu';

        select.classList.add('reg-native-select');
        select.parentNode.insertBefore(wrap, select.nextSibling);
        wrap.appendChild(toggle);
        wrap.appendChild(menu);

        select._regSelect = { wrap: wrap, toggle: toggle, menu: menu };
        toggle.addEventListener('click', function () {
            if (select.disabled) return;
            var shouldOpen = !wrap.classList.contains('open');
            closeCustomSelects(wrap);
            wrap.classList.toggle('open', shouldOpen);
        });

        syncCustomSelect(select);
    }

    enhancedSelects.forEach(enhanceSelect);
    document.addEventListener('click', function (e) {
        if (!e.target.closest('.reg-select')) closeCustomSelects();
    });

    // "Yes"/"No" are two independent checkboxes visually, but only one may be selected at a time
    glassesYes.addEventListener('change', function () {
        if (glassesYes.checked) glassesNo.checked = false;
    });
    glassesNo.addEventListener('change', function () {
        if (glassesNo.checked) glassesYes.checked = false;
    });

    function wearsGlassesValue() {
        if (glassesYes.checked) return '1';
        if (glassesNo.checked) return '0';
        return '';
    }

    // The Register button stays disabled until both confirmation boxes are checked
    function confirmed() { return termsInp.checked && ageInp.checked; }
    termsInp.addEventListener('change', function () { submitBtn.disabled = !confirmed(); });
    ageInp.addEventListener('change', function () { submitBtn.disabled = !confirmed(); });

    // today as YYYY-MM-DD in the visitor's local time
    function today() {
        var d = new Date();
        return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    }
    dateInp.min = today();

    // Limit the calendar to the selected store's start/end dates (and never before today)
    function applyStoreDates() {
        var store = stores.filter(function (s) { return String(s.id) === storeSel.value; })[0];
        var min = today();
        var max = '';
        if (store) {
            if (store.start_date && store.start_date > min) min = store.start_date;
            max = store.end_date || '';
        }
        dateInp.min = min;
        if (max) dateInp.setAttribute('max', max); else dateInp.removeAttribute('max');
        if (dateInp.value && (dateInp.value < min || (max && dateInp.value > max))) dateInp.value = '';
    }

    function setOptions(select, placeholder, items, valueKey, labelFn) {
        select.innerHTML = '';
        var first = document.createElement('option');
        first.value = '';
        first.textContent = placeholder;
        select.appendChild(first);
        items.forEach(function (item) {
            var opt = document.createElement('option');
            opt.value = item[valueKey];
            opt.textContent = labelFn(item);
            if (item.disabled) opt.disabled = true;
            select.appendChild(opt);
        });
        syncCustomSelect(select);
    }

    function fieldOf(name) { return form.elements[name]; }

    function setError(name, message) {
        var input = fieldOf(name);
        if (!input) return;
        input.classList.add('is-invalid');
        syncCustomSelect(input);
        var fb = input.parentNode.querySelector('.invalid-feedback');
        if (fb && message) fb.textContent = message;
    }

    function clearErrors() {
        alertBox.classList.add('d-none');
        form.querySelectorAll('.is-invalid').forEach(function (el) { el.classList.remove('is-invalid'); });
        enhancedSelects.forEach(syncCustomSelect);
        glassesYes.classList.remove('is-invalid');
        glassesNo.classList.remove('is-invalid');
        glassesErr.classList.add('d-none');
    }

    // Load cities + stores
    fetch(base_url + '/api/register/options')
        .then(function (r) { return r.json(); })
        .then(function (data) {
            stores = data.stores || [];
            setOptions(citySel, 'Select your city', data.cities || [], 'id', function (c) { return c.name; });
        });

    citySel.addEventListener('change', function () {
        var list = stores.filter(function (s) { return String(s.city_id) === citySel.value; });
        setOptions(storeSel, 'Select your nearest store', list, 'id', function (s) { return s.store_name; });
        storeSel.disabled = !citySel.value;
        syncCustomSelect(storeSel);
        applyStoreDates();
        loadSlots();
    });

    storeSel.addEventListener('change', function () { applyStoreDates(); loadSlots(); });
    dateInp.addEventListener('change', loadSlots);

    function loadSlots() {
        slotSel.disabled = true;
        setOptions(slotSel, 'Select store and date first', [], 'id', function () { return ''; });

        if (!storeSel.value || !dateInp.value) return;

        setOptions(slotSel, 'Loading…', [], 'id', function () { return ''; });
        fetch(base_url + '/api/register/slots?store_id=' + encodeURIComponent(storeSel.value) + '&date=' + encodeURIComponent(dateInp.value))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var slots = (data.slots || []).map(function (s) {
                    s.disabled = s.full;
                    return s;
                });
                if (!slots.length) {
                    setOptions(slotSel, 'No slots available for this date', [], 'id', function () { return ''; });
                    return;
                }
                setOptions(slotSel, '00:00 AM - 00:00 AM', slots, 'id', function (s) {
                    var label = s.name ? s.name : s.time;
                    return s.full ? label + ' - Full' : label;
                });
                slotSel.options[0].textContent = 'Select a time slot';
                slotSel.disabled = false;
                syncCustomSelect(slotSel);
            });
    }

    mobileInp.addEventListener('input', function () {
        mobileInp.value = mobileInp.value.replace(/\D/g, '').slice(0, 10);
    });

    function validate() {
        var ok = true;
        function fail(name, message) { setError(name, message); ok = false; }

        if (!fieldOf('full_name').value.trim()) fail('full_name', 'Please enter your full name.');
        if (!/^\d{10}$/.test(mobileInp.value)) fail('mobile', 'Please enter a valid 10 digit mobile number.');
        if (!wearsGlassesValue()) {
            glassesYes.classList.add('is-invalid');
            glassesNo.classList.add('is-invalid');
            glassesErr.classList.remove('d-none');
            ok = false;
        }
        if (!citySel.value) fail('city_id', 'Please select your city.');
        if (!storeSel.value) fail('store_id', 'Please select your nearest store.');
        if (!dateInp.value) fail('slot_date', 'Please select a date.');
        else if (dateInp.value < dateInp.min || (dateInp.max && dateInp.value > dateInp.max)) fail('slot_date', 'This date is not available for the selected store.');
        if (!slotSel.value) fail('time_slot_id', 'Please select a time slot.');
        if (!ageInp.checked) fail('age_confirmed');
        if (!fieldOf('terms').checked) fail('terms');

        return ok;
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        clearErrors();
        if (!validate()) return;

        submitBtn.disabled = true;
        submitBtn.textContent = 'Registering…';

        fetch(base_url + '/api/register/submit', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({
                full_name:     fieldOf('full_name').value.trim(),
                mobile:        mobileInp.value,
                wears_glasses: wearsGlassesValue(),
                city_id:       citySel.value,
                store_id:      storeSel.value,
                slot_date:     dateInp.value,
                time_slot_id:  slotSel.value,
                age_confirmed: ageInp.checked ? 1 : 0,
                terms:         termsInp.checked ? 1 : 0
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success) {
                document.getElementById('formPanel').classList.add('d-none');
                document.getElementById('successPanel').classList.remove('d-none');
                window.scrollTo({ top: 0, behavior: 'smooth' });
                return;
            }
            var errors = data.errors || {};
            Object.keys(errors).forEach(function (name) { setError(name, errors[name][0]); });
            alertBox.textContent = data.message || 'Something went wrong. Please try again.';
            alertBox.classList.remove('d-none');
            // the chosen slot may have just filled up
            loadSlots();
        })
        .catch(function () {
            alertBox.textContent = 'Something went wrong. Please try again.';
            alertBox.classList.remove('d-none');
        })
        .then(function () {
            submitBtn.disabled = !confirmed();
            submitBtn.textContent = 'Register';
        });
    });
})();
</script>
</body>
</html>
