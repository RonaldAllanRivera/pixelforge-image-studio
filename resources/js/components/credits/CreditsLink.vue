<script setup lang="ts">
import { Wallet } from '@lucide/vue';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { index } from '@/routes/credits';

const page = usePage();
const credits = computed(() => page.props.credits);
</script>

<template>
    <Link
        v-if="credits"
        :href="index()"
        data-test="credits-link"
        class="inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-sm transition-colors hover:bg-accent"
    >
        <Wallet class="size-4 text-muted-foreground" />
        <span class="text-muted-foreground">Credits</span>
        <span class="font-medium tabular-nums">{{ credits.balance }}</span>
    </Link>
</template>
