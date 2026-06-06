<script setup>
/**
 * Two-factor authentication challenge (login time).
 *
 * Shown after a valid password login when 2FA is enabled. Delegates to the
 * shared {@link TwoFactorChallengeForm}, POSTing to Laravel Fortify's
 * `/two-factor-challenge` endpoint.
 */
import { TwoFactorChallengeForm, AppBaseLayout } from '@dmitryisaenko/larafoundry';
</script>

<template>
    <AppBaseLayout>
        <TwoFactorChallengeForm
            action="/two-factor-challenge"
            :title="$t('Two-factor authentication')"
            :prompt="$t('Enter the authentication code from your authenticator app.')"
        />
    </AppBaseLayout>
</template>
