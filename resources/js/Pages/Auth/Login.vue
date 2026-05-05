<script setup>
import { ref } from 'vue'
import { Head, Link, useForm } from '@inertiajs/vue3'

const showPassword = ref(false)

const form = useForm({
    email: '',
    password: '',
    remember: false,
})

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    })
}
</script>

<template>
    <Head title="Login — Vero Cattle Management" />

    <div class="cv-root">

        <!-- ══════════════════════════════════════
             LEFT PANEL — Brand / Hero
             Background: /images/cattle-bg.jpg
             (place file at public/images/cattle-bg.jpg)
        ══════════════════════════════════════ -->
        <div class="cv-left">

            <!-- Dark green overlay on top of the photo -->
            <div class="cv-left-overlay"></div>

            <!-- All left-panel content sits above the overlay -->
            <div class="cv-left-content">
                
                          
                        <div class="cv-login-logo-text">
                            <span class="cv-login-logo-vero">VERO</span>
                            <span class="cv-login-logo-sub">CATTLE MANAGEMENT</span>
                            <span class="cv-login-logo-divider"></span>
                             <h1 class="cv-headline">
                        Smarter Cattle Management<br />
                        <span class="cv-headline-accent">Manage your herd with confidence</span>
                                               
                    </h1>
                        </div>
              
                <!-- MIDDLE: Headline -->
                
                  
                 <!-- <div class="cv-headline-wrap">
                    <p class="cv-tagline">
                        Manage your herd with confidence using powerful<br />
                        tools for tracking, insights, and growth.
                    </p>
                </div> -->

                <!-- BOTTOM: Feature badges -->
                <div class="cv-features">

                    <!-- Herd Management -->
                    <div class="cv-feature">
                        <div class="cv-feature-icon">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none"
                                 stroke="currentColor" stroke-width="1.6"
                                 stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="8" r="3"/>
                                <path d="M6 20v-2a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v2"/>
                                <path d="M3 11c0-1.1.9-2 2-2s2 .9 2 2"/>
                                <path d="M19 11c0-1.1.9-2 2-2s2 .9 2 2"/>
                            </svg>
                        </div>
                        <div class="cv-feature-label">
                            <span>Herd</span>
                            <span>Management</span>
                        </div>
                    </div>

                    <!-- Financial Intelligence -->
                    <div class="cv-feature">
                        <div class="cv-feature-icon">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none"
                                 stroke="currentColor" stroke-width="1.6"
                                 stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/>
                                <polyline points="16 7 22 7 22 13"/>
                            </svg>
                        </div>
                        <div class="cv-feature-label">
                            <span>Financial</span>
                            <span>Intelligence</span>
                        </div>
                    </div>

                    <!-- Secure & Reliable -->
                    <div class="cv-feature">
                        <div class="cv-feature-icon">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none"
                                 stroke="currentColor" stroke-width="1.6"
                                 stroke-linecap="round" stroke-linejoin="round">
                                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                            </svg>
                        </div>
                        <div class="cv-feature-label">
                            <span>Secure &amp;</span>
                            <span>Reliable</span>
                        </div>
                    </div>

                </div><!-- /features -->

            </div><!-- /left-content -->
        </div><!-- /left panel -->

        <!-- ══════════════════════════════════════
             RIGHT PANEL — Login Form
        ══════════════════════════════════════ -->
        <div class="cv-right">
            <div class="cv-form-wrap">

                <!-- TOP: Logo image only — full logo including text is in the PNG -->
                <div class="cv-logo-wrap">
                    <img
                        :src="'/images/vero_cattle_mg_logo.png'"
                        alt="Vero Cattle Management"
                        class="cv-logo-img"
                    />
                </div>
                

                <!-- Heading -->
                <div class="cv-form-heading">
                    <h2>Welcome back</h2>
                    <p>Sign in to your Vero Cattle Management account</p>
                </div>

                <!-- Form -->
                <form @submit.prevent="submit" class="cv-form" novalidate>

                    <!-- Email -->
                    <div class="cv-field">
                        <label class="cv-label" for="login-email">EMAIL ADDRESS</label>
                        <div class="cv-input-wrap">
                            <span class="cv-input-icon cv-icon-left">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none"
                                     stroke="currentColor" stroke-width="1.8"
                                     stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
                                    <polyline points="22,6 12,13 2,6"/>
                                </svg>
                            </span>
                            <input
                                id="login-email"
                                type="email"
                                v-model="form.email"
                                autocomplete="username"
                                placeholder="Enter your email address"
                                required
                                :class="['cv-input', form.errors.email ? 'cv-input--error' : '']"
                            />
                        </div>
                        <p v-if="form.errors.email" class="cv-error">{{ form.errors.email }}</p>
                    </div>

                    <!-- Password -->
                    <div class="cv-field">
                        <div class="cv-field-row">
                            <label class="cv-label" for="login-password">PASSWORD</label>
                            <Link :href="route('password.request')" class="cv-forgot">
                                Forgot password?
                            </Link>
                        </div>
                        <div class="cv-input-wrap">
                            <span class="cv-input-icon cv-icon-left">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none"
                                     stroke="currentColor" stroke-width="1.8"
                                     stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                                    <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                                </svg>
                            </span>
                            <input
                                id="login-password"
                                :type="showPassword ? 'text' : 'password'"
                                v-model="form.password"
                                autocomplete="current-password"
                                placeholder="Enter your password"
                                required
                                :class="['cv-input cv-input--pr', form.errors.password ? 'cv-input--error' : '']"
                            />
                            <button
                                type="button"
                                class="cv-input-icon cv-icon-right cv-eye-btn"
                                @click="showPassword = !showPassword"
                                :aria-label="showPassword ? 'Hide password' : 'Show password'"
                            >
                                <svg v-if="!showPassword" width="15" height="15" viewBox="0 0 24 24"
                                     fill="none" stroke="currentColor" stroke-width="1.8"
                                     stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                    <circle cx="12" cy="12" r="3"/>
                                </svg>
                                <svg v-else width="15" height="15" viewBox="0 0 24 24"
                                     fill="none" stroke="currentColor" stroke-width="1.8"
                                     stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/>
                                    <path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/>
                                    <line x1="1" y1="1" x2="23" y2="23"/>
                                </svg>
                            </button>
                        </div>
                        <p v-if="form.errors.password" class="cv-error">{{ form.errors.password }}</p>
                    </div>

                    <!-- Remember me -->
                    <label class="cv-remember">
                        <div class="cv-checkbox-wrap">
                            <input
                                type="checkbox"
                                v-model="form.remember"
                                class="cv-checkbox-native"
                                id="login-remember"
                            />
                            <span class="cv-checkbox-custom">
                                <svg v-if="form.remember" width="10" height="10"
                                     viewBox="0 0 12 12" fill="none" stroke="white"
                                     stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                    <polyline points="2,6 5,9 10,3"/>
                                </svg>
                            </span>
                        </div>
                        <span class="cv-remember-text">Remember me for 30 days</span>
                    </label>

                    <!-- Submit -->
                    <button
                        type="submit"
                        class="cv-submit"
                        :class="{ 'cv-submit--loading': form.processing }"
                        :disabled="form.processing"
                    >
                        <span>SIGN IN TO VERO</span>
                    </button>

                    <!-- Divider -->
                    <div class="cv-divider"><span>or</span></div>

                    <!-- Contact admin -->
                    <button type="button" class="cv-admin-btn">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="1.8"
                             stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                        </svg>
                        CONTACT YOUR FARM ADMINISTRATOR
                    </button>

                </form>

                <!-- Security note -->
                <p class="cv-security">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2"
                         stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                        <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                    </svg>
                    Secure access. Your data is protected.
                </p>

            </div><!-- /form-wrap -->
        </div><!-- /right panel -->

    </div><!-- /root -->
</template>

<style scoped>
/* ─────────────────────────────────────────────────────────
   ROOT — full-screen two-column split
───────────────────────────────────────────────────────── */
.cv-root {
    display: flex;
    min-height: 100vh;
    font-family: 'Inter', 'Segoe UI', system-ui, sans-serif;
    background-color: #071A0E;
    color: #fff;
}

/* ─────────────────────────────────────────────────────────
   LEFT PANEL
   ⚠️  Requires: public/images/cattle-bg.jpg
   Download from:
   https://images.unsplash.com/photo-1500595046743-cd271d694e30?w=1200&q=85
   Save to: D:\My Projects\vero-cattle-mg\public\images\cattle-bg.jpg
───────────────────────────────────────────────────────── */
.cv-left {
    display: none;             /* hidden on mobile */
    position: relative;
    width: 50%;
    flex-shrink: 0;
    overflow: hidden;

    /* ── THE PHOTO — local file, no external URL ── */
    background-image: url('/images/cattle-bg.jpg');
    background-size: cover;
    background-position: center center;
    background-repeat: no-repeat;
}
@media (min-width: 768px) {
    .cv-left { display: flex; flex-direction: column; }
}

/* Dark green gradient overlay — makes the photo match the brand palette */
.cv-left-overlay {
    position: absolute;
    inset: 0;
    background: linear-gradient(
        to bottom,
        rgba(4, 18, 9, 0.80)  0%,
        rgba(4, 18, 9, 0.38)  35%,
        rgba(4, 18, 9, 0.42)  65%,
        rgba(4, 18, 9, 0.82) 100%
    );
    z-index: 1;
}

/* Diagonal slash edge — the angled divider between left and right panels */
.cv-left::after {
    content: '';
    position: absolute;
    top: 0;
    right: -28px;
    width: 58px;
    height: 100%;
    background-color: #0B2212;
    transform: skewX(-2.5deg);
    z-index: 10;
}

/* Left panel content — stacked top / middle / bottom */
.cv-left-content {
    position: relative;
    z-index: 3;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    height: 100%;
    padding: 3rem 3.5rem;
}

/* ── Logo ── */
.cv-logo-img {
    max-height:100px;
    max-width: 250px;
    width: 100%;
    height: auto;
    object-fit: contain;
    
    /* Drop shadow for legibility over any photo */
    filter: drop-shadow(0 2px 10px rgba(0, 0, 0, 0.60));
    margin-bottom:0rem ;
}

/* ── Headline ── */
.cv-headline {
    font-size: 2rem;
    font-weight: 600;
    line-height: 1.35;
    color: #fff;
    margin: 0 0 0.875rem;
    text-shadow: 0 1px 8px rgba(0, 0, 0, 0.55);
}
.cv-headline-accent { color: #4DBF7A; }
.cv-tagline {
    font-size: 0.875rem;
    color: rgba(255, 255, 255, 0.62);
    line-height: 1.65;
    margin: 0;
    text-shadow: 0 1px 4px rgba(0, 0, 0, 0.45);
}

/* ── Feature badges ── */
.cv-features {
    display: flex;
    gap: 1.75rem;
    flex-wrap: wrap;
}
.cv-feature {
    display: flex;
    align-items: center;
    gap: 0.625rem;
}
.cv-feature-icon {
    width: 2.5rem;
    height: 2.5rem;
    border-radius: 50%;
    border: 1.5px solid rgba(77, 191, 122, 0.38);
    background: rgba(77, 191, 122, 0.12);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #4DBF7A;
    flex-shrink: 0;
}
.cv-feature-label {
    display: flex;
    flex-direction: column;
    font-size: 0.75rem;
    color: rgba(255, 255, 255, 0.72);
    line-height: 1.4;
    text-shadow: 0 1px 3px rgba(0, 0, 0, 0.40);
}

/* ─────────────────────────────────────────────────────────
   RIGHT PANEL
───────────────────────────────────────────────────────── */
.cv-right {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 2.5rem 1.5rem;
    background-color: #0B2212;
    min-height: 100vh;
}

.cv-form-wrap {
    width: 100%;
    max-width: 400px;
    display: flex;
    flex-direction: column;
}

/* Lock icon */
.cv-icon-box-wrap {
    display: flex;
    justify-content: center;
    margin-bottom: 1.375rem;
}
.cv-icon-box {
    width: 3.25rem;
    height: 3.25rem;
    border-radius: 0.875rem;
    border: 1.5px solid rgba(77, 191, 122, 0.28);
    background: rgba(77, 191, 122, 0.08);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #4DBF7A;
}

/* Heading */
.cv-form-heading {
    text-align: center;
    margin-bottom: 1.875rem;
}
.cv-form-heading h2 {
    font-size: 1.5rem;
    font-weight: 600;
    color: #fff;
    margin: 0 0 0.375rem;
}
.cv-form-heading p {
    font-size: 0.8125rem;
    color: rgba(255, 255, 255, 0.45);
    margin: 0;
}

/* Form */
.cv-form {
    display: flex;
    flex-direction: column;
    gap: 1.125rem;
}

/* Field */
.cv-field {
    display: flex;
    flex-direction: column;
    gap: 0.3125rem;
}
.cv-field-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
}

/* Label */
.cv-label {
    font-size: 0.625rem;
    font-weight: 600;
    letter-spacing: 0.10em;
    text-transform: uppercase;
    color: rgba(255, 255, 255, 0.55);
}

/* Forgot */
.cv-forgot {
    font-size: 0.75rem;
    color: #4DBF7A;
    text-decoration: none;
    transition: color 0.15s;
}
.cv-forgot:hover { color: #fff; }

/* Input wrapper */
.cv-input-wrap {
    position: relative;
    display: flex;
    align-items: center;
}

/* Icons */
.cv-input-icon {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    color: rgba(255, 255, 255, 0.25);
    pointer-events: none;
    display: flex;
    align-items: center;
}
.cv-icon-left  { left: 0.875rem; }
.cv-icon-right { right: 0.875rem; }
.cv-eye-btn {
    pointer-events: all;
    cursor: pointer;
    background: none;
    border: none;
    padding: 0;
    color: rgba(255, 255, 255, 0.30);
    transition: color 0.15s;
}
.cv-eye-btn:hover { color: rgba(255, 255, 255, 0.65); }

/* Input */
.cv-input {
    width: 100%;
    background: rgba(255, 255, 255, 0.04);
    border: 1px solid rgba(77, 191, 122, 0.22);
    border-radius: 0.5625rem;
    padding: 0.75rem 0.875rem 0.75rem 2.5rem;
    font-size: 0.875rem;
    color: #fff;
    font-family: inherit;
    outline: none;
    transition: border-color 0.15s, box-shadow 0.15s, background-color 0.15s;
}
.cv-input::placeholder { color: rgba(255, 255, 255, 0.18); }
.cv-input:focus,
.cv-input:focus-visible {
    border-color: rgba(77, 191, 122, 0.55);
    box-shadow: 0 0 0 3px rgba(77, 191, 122, 0.12);
    background: rgba(255, 255, 255, 0.06);
}
.cv-input--pr    { padding-right: 2.5rem; }
.cv-input--error { border-color: rgba(192, 57, 43, 0.60) !important; }

/* Error */
.cv-error {
    font-size: 0.75rem;
    color: #F87171;
    margin: 0;
}

/* Remember */
.cv-remember {
    display: flex;
    align-items: center;
    gap: 0.625rem;
    cursor: pointer;
}
.cv-checkbox-wrap { position: relative; flex-shrink: 0; }
.cv-checkbox-native {
    position: absolute;
    opacity: 0;
    width: 1rem;
    height: 1rem;
    cursor: pointer;
    margin: 0;
}
.cv-checkbox-custom {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 1.0625rem;
    height: 1.0625rem;
    border-radius: 0.25rem;
    border: 1.5px solid rgba(77, 191, 122, 0.40);
    background: rgba(255, 255, 255, 0.04);
    transition: border-color 0.15s, background-color 0.15s;
}
.cv-checkbox-native:checked ~ .cv-checkbox-custom {
    border-color: #4DBF7A;
    background: #1A6B3A;
}
.cv-remember-text {
    font-size: 0.8125rem;
    color: rgba(255, 255, 255, 0.60);
}

/* Submit */
.cv-submit {
    width: 100%;
    background: #E8830A;
    color: #fff;
    border: none;
    border-radius: 0.5625rem;
    padding: 0.875rem;
    font-size: 0.75rem;
    font-weight: 700;
    letter-spacing: 0.10em;
    text-transform: uppercase;
    cursor: pointer;
    font-family: inherit;
    transition: background-color 0.15s, box-shadow 0.15s, transform 0.1s;
    position: relative;
}
.cv-submit:hover:not(:disabled) {
    background: #C46D08;
    box-shadow: 0 4px 16px rgba(232, 131, 10, 0.35);
}
.cv-submit:active:not(:disabled) { transform: translateY(1px); }
.cv-submit:disabled { opacity: 0.6; cursor: not-allowed; }
.cv-submit--loading span { opacity: 0; }
.cv-submit--loading::after {
    content: '';
    position: absolute;
    inset: 0;
    margin: auto;
    width: 1.125rem;
    height: 1.125rem;
    border: 2px solid rgba(255, 255, 255, 0.30);
    border-top-color: #fff;
    border-radius: 50%;
    animation: cv-spin 0.65s linear infinite;
}
@keyframes cv-spin { to { transform: rotate(360deg); } }

/* Divider */
.cv-divider {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    color: rgba(255, 255, 255, 0.18);
    font-size: 0.8125rem;
}
.cv-divider::before,
.cv-divider::after {
    content: '';
    flex: 1;
    border-top: 1px solid rgba(255, 255, 255, 0.10);
}

/* Contact admin */
.cv-admin-btn {
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.625rem;
    background: transparent;
    border: 1px solid rgba(77, 191, 122, 0.25);
    border-radius: 0.5625rem;
    padding: 0.75rem;
    font-size: 0.6875rem;
    font-weight: 600;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: rgba(255, 255, 255, 0.55);
    cursor: pointer;
    font-family: inherit;
    transition: border-color 0.15s, color 0.15s, background-color 0.15s;
}
.cv-admin-btn:hover {
    border-color: rgba(77, 191, 122, 0.50);
    color: #4DBF7A;
    background: rgba(77, 191, 122, 0.06);
}

/* Security note */
.cv-security {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.375rem;
    margin-top: 1.5rem;
    font-size: 0.6875rem;
    color: rgba(255, 255, 255, 0.22);
}

.cv-login-logo-vero {
    font-size: 2.5rem;
    font-weight: 700;
    letter-spacing: 0.18em;
    color: #fff;
    line-height: 1;
}
.cv-login-logo-sub {
    font-size: 1rem;
    font-weight: 500;
    letter-spacing: 0.22em;
    text-transform: uppercase;
    color: var(--cv-green-bright, #4DBF7A);
    line-height: 1;
}
.cv-login-logo-divider {
    display: block;
    width: 15rem;
    height: 2px;
    background: var(--cv-amber, #E8830A);
    margin-top: 0.375rem;
    border-radius: 1px;
    margin-bottom: 0rem;
}
.cv-login-logo-text {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

</style>