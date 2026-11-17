<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import BuyCreditsDialog from '@/components/credits/BuyCreditsDialog.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { index } from '@/routes/credits';
import { show } from '@/routes/generations';
import type { CreditEntry, Paginated } from '@/types';

defineProps<{
    entries: Paginated<CreditEntry>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Credits', href: index() }],
    },
});

const page = usePage();
const credits = computed(() => page.props.credits);

function local(iso: string | null): string {
    return iso === null
        ? ''
        : new Date(iso).toLocaleString(undefined, {
              dateStyle: 'medium',
              timeStyle: 'short',
          });
}
</script>

<template>
    <Head title="Credits" />

    <div class="mx-auto flex w-full max-w-3xl flex-col gap-8 p-4">
        <section
            v-if="credits"
            class="flex flex-wrap items-end justify-between gap-4 rounded-xl border p-4 md:p-6"
        >
            <div class="space-y-1">
                <p class="text-sm text-muted-foreground">Balance</p>
                <p
                    class="text-3xl font-semibold tabular-nums"
                    data-test="balance"
                >
                    {{ credits.balance }}
                </p>
                <p class="text-xs text-muted-foreground tabular-nums">
                    Exactly {{ credits.balance_exact }}
                </p>
                <p
                    v-if="credits.held_images > 0"
                    class="text-sm text-muted-foreground"
                >
                    {{ credits.held }} held for
                    {{ credits.held_images }}
                    {{ credits.held_images === 1 ? 'image' : 'images' }} in
                    progress
                </p>
            </div>
            <BuyCreditsDialog variant="default" size="default" />
        </section>

        <section class="space-y-4">
            <Heading
                variant="small"
                title="Where your credits went"
                description="Every change to your balance, newest first. An image holds the most it could cost, then releases it and is charged what it actually cost."
            />

            <p
                v-if="entries.data.length === 0"
                class="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground"
            >
                Nothing yet. Buy credits to make your first image.
            </p>

            <ul v-else class="divide-y rounded-xl border">
                <li
                    v-for="entry in entries.data"
                    :key="entry.id"
                    class="flex items-center gap-3 px-4 py-3 text-sm"
                >
                    <div class="min-w-0 flex-1">
                        <p class="font-medium">{{ entry.label }}</p>
                        <p class="truncate text-muted-foreground">
                            <Link
                                v-if="entry.generation"
                                :href="show(entry.generation.id)"
                                class="hover:underline"
                                >{{ entry.generation.prompt }}</Link
                            >
                            <template v-else>Pretend purchase</template>
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ local(entry.created_at) }}
                        </p>
                    </div>
                    <span
                        :title="entry.amount_exact"
                        :class="
                            cn(
                                'shrink-0 font-medium tabular-nums',
                                entry.amount.startsWith('+') &&
                                    'text-emerald-700 dark:text-emerald-400',
                            )
                        "
                    >
                        {{ entry.amount }}
                    </span>
                </li>
            </ul>

            <nav
                v-if="entries.meta.last_page > 1"
                class="flex items-center justify-between text-sm"
            >
                <Button
                    v-if="entries.links.prev"
                    variant="outline"
                    size="sm"
                    as-child
                >
                    <Link :href="entries.links.prev" preserve-scroll
                        >Newer</Link
                    >
                </Button>
                <span v-else></span>
                <span class="text-muted-foreground">
                    Page {{ entries.meta.current_page }} of
                    {{ entries.meta.last_page }}
                </span>
                <Button
                    v-if="entries.links.next"
                    variant="outline"
                    size="sm"
                    as-child
                >
                    <Link :href="entries.links.next" preserve-scroll
                        >Older</Link
                    >
                </Button>
                <span v-else></span>
            </nav>
        </section>
    </div>
</template>
