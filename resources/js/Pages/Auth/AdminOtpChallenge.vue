<script setup>
/**
 * Operator-console OTP step-up.
 *
 * Shown when a super-admin enters the console without having cleared the
 * step-up this session (EnsureAdminOtpVerified). Delegates to the shared
 * {@link TwoFactorChallengeForm}, POSTing to the core's `/admin/otp` route
 * (NOT Fortify's login challenge). On success the server sets the session flag
 * and forwards to the console.
 */
import { TwoFactorChallengeForm, AppBaseLayout } from '@dmitryisaenko/larafoundry';
</script>

<template>
    <AppBaseLayout>
        <TwoFactorChallengeForm
            action="/admin/otp"
            :title="$t('Confirm your identity')"
            :prompt="$t('Re-enter your authenticator code to open the operator console.')"
        />
    </AppBaseLayout>
</template>
