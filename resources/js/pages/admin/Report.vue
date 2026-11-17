<script setup lang="ts">
import { Deferred, Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';
import { report } from '@/routes/admin';
import type {
    PixelforgeComparison,
    ReportAmounts,
    ReportDay,
    ReportRange,
    ReportUser,
} from '@/types';

const props = defineProps<{
    range: ReportRange;
    days: ReportDay[];
    users: ReportUser[];
    totals: ReportAmounts;
    // Loaded after the page: our numbers show first, Pixelforge's a moment later.
    pixelforge?: PixelforgeComparison;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Admin report', href: report() }],
    },
});

const page = usePage();
const errors = computed(() => page.props.errors as Record<string, string>);

const start = ref(props.range.start);
const end = ref(props.range.end);

function show(): void {
    router.get(report.url({ query: { start: start.value, end: end.value } }));
}

// Clicking a day narrows the report to it, so the users table shows who spent what that day.
function oneDay(date: string): string {
    return report.url({ query: { start: date, end: date } });
}

const comparison = computed(() =>
    props.pixelforge?.available ? props.pixelforge : null,
);

const summary = computed(() => {
    if (!props.pixelforge) {
        return null;
    }

    if (!props.pixelforge.available) {
        return props.pixelforge.message;
    }

    const { matching_days, day_count } = props.pixelforge;

    return `${matching_days} of ${day_count} days match Pixelforge${matching_days === day_count ? ' ✓' : ''}`;
});
</script>

<template>
    <Head title="Admin report" />

    <div class="mx-auto flex w-full max-w-5xl min-w-0 flex-col gap-8 p-4">
        <Heading
            title="Admin report"
            description="What users were charged, what Pixelforge charged us, and the profit, for each UTC day and each user. Each day is checked against Pixelforge's own usage report."
        />

        <form class="flex flex-wrap items-end gap-3" @submit.prevent="show">
            <div class="grid gap-1">
                <Label for="report-start">From</Label>
                <Input
                    id="report-start"
                    v-model="start"
                    type="date"
                    data-test="report-start"
                    class="w-auto"
                />
            </div>
            <div class="grid gap-1">
                <Label for="report-end">To</Label>
                <Input
                    id="report-end"
                    v-model="end"
                    type="date"
                    data-test="report-end"
                    class="w-auto"
                />
            </div>
            <Button type="submit" data-test="report-show">Show</Button>
            <p class="w-full text-xs text-muted-foreground">
                UTC days, both included, at most {{ range.max_days }} days.
            </p>
            <InputError class="w-full" :message="errors.start" />
            <InputError class="w-full" :message="errors.end" />
        </form>

        <section class="grid grid-cols-2 gap-3 md:grid-cols-4">
            <div class="rounded-xl border p-4">
                <p class="text-xs text-muted-foreground">Charged to users</p>
                <p class="mt-1 font-medium tabular-nums">
                    {{ totals.charged }}
                </p>
            </div>
            <div class="rounded-xl border p-4">
                <p class="text-xs text-muted-foreground">
                    Pixelforge cost (our records)
                </p>
                <p class="mt-1 font-medium tabular-nums">{{ totals.cost }}</p>
            </div>
            <div class="rounded-xl border p-4">
                <p class="text-xs text-muted-foreground">Profit</p>
                <p class="mt-1 font-medium tabular-nums">
                    {{ totals.profit }}
                </p>
            </div>
            <div class="rounded-xl border p-4">
                <p class="text-xs text-muted-foreground">Checked against</p>
                <Deferred data="pixelforge">
                    <template #fallback>
                        <Skeleton class="mt-2 h-4 w-32" />
                    </template>
                    <p
                        data-test="matches-summary"
                        :class="
                            cn(
                                'mt-1 text-sm font-medium',
                                comparison === null &&
                                    'text-amber-700 dark:text-amber-400',
                            )
                        "
                    >
                        {{ summary }}
                    </p>
                </Deferred>
            </div>
        </section>

        <section class="min-w-0 space-y-3">
            <Heading
                variant="small"
                title="Each day"
                description="Newest first. Click a day to see who spent what that day."
            />
            <div class="overflow-x-auto rounded-xl border">
                <table class="w-full text-sm tabular-nums">
                    <thead class="bg-muted/50 text-left text-xs">
                        <tr>
                            <th class="px-3 py-2 font-medium">Day (UTC)</th>
                            <th class="px-3 py-2 text-right font-medium">
                                Images
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                Charged
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                Pixelforge cost
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                Profit
                            </th>
                            <th class="px-3 py-2 font-medium">Pixelforge</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="day in days" :key="day.date">
                            <td class="px-3 py-2 whitespace-nowrap">
                                <Link
                                    :href="oneDay(day.date)"
                                    :data-test="`day-${day.date}`"
                                    class="underline-offset-4 hover:underline"
                                    >{{ day.date }}</Link
                                >
                            </td>
                            <td class="px-3 py-2 text-right">
                                {{ day.generations }}
                            </td>
                            <td class="px-3 py-2 text-right">
                                {{ day.charged }}
                            </td>
                            <td class="px-3 py-2 text-right">
                                {{ day.cost }}
                            </td>
                            <td class="px-3 py-2 text-right">
                                {{ day.profit }}
                            </td>
                            <td class="px-3 py-2">
                                <Skeleton
                                    v-if="pixelforge === undefined"
                                    class="h-4 w-20"
                                />
                                <span
                                    v-else-if="comparison === null"
                                    class="text-muted-foreground"
                                    >—</span
                                >
                                <span
                                    v-else-if="
                                        comparison.days[day.date]?.matches
                                    "
                                    class="whitespace-nowrap text-emerald-700 dark:text-emerald-400"
                                    >✓ Matches</span
                                >
                                <ul
                                    v-else
                                    class="min-w-56 space-y-0.5 text-xs text-red-600 dark:text-red-500"
                                >
                                    <li
                                        v-for="line in comparison.days[day.date]
                                            ?.differences"
                                        :key="line"
                                    >
                                        {{ line }}
                                    </li>
                                </ul>
                            </td>
                        </tr>
                    </tbody>
                    <tfoot class="border-t font-medium">
                        <tr>
                            <td class="px-3 py-2">Total</td>
                            <td class="px-3 py-2 text-right">
                                {{ totals.generations }}
                            </td>
                            <td class="px-3 py-2 text-right">
                                {{ totals.charged }}
                            </td>
                            <td class="px-3 py-2 text-right">
                                {{ totals.cost }}
                            </td>
                            <td class="px-3 py-2 text-right">
                                {{ totals.profit }}
                            </td>
                            <td class="px-3 py-2">
                                <span
                                    v-if="comparison?.total.matches"
                                    class="whitespace-nowrap text-emerald-700 dark:text-emerald-400"
                                    >✓ Matches</span
                                >
                                <span
                                    v-else-if="comparison"
                                    class="text-xs whitespace-nowrap text-muted-foreground"
                                    >Pixelforge
                                    {{ comparison.total.generations }}
                                    images,
                                    {{ comparison.total.cost }}</span
                                >
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <p class="text-xs text-muted-foreground">
                Pixelforge counts a generation on the UTC day it finished. A
                generation that finished in the last few seconds may not match
                until its webhook arrives.
            </p>
        </section>

        <section class="min-w-0 space-y-3">
            <Heading
                variant="small"
                title="Each user"
                :description="`${range.start} to ${range.end}. Emails are masked.`"
            />
            <p
                v-if="users.length === 0"
                class="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground"
            >
                No finished images in this range.
            </p>
            <div v-else class="overflow-x-auto rounded-xl border">
                <table class="w-full text-sm tabular-nums">
                    <thead class="bg-muted/50 text-left text-xs">
                        <tr>
                            <th class="px-3 py-2 font-medium">User</th>
                            <th class="px-3 py-2 text-right font-medium">
                                Images
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                Charged
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                Pixelforge cost
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                Profit
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="(user, index) in users"
                            :key="`${index}-${user.email}`"
                        >
                            <td class="px-3 py-2">
                                <p class="whitespace-nowrap">{{ user.name }}</p>
                                <p
                                    v-if="user.email"
                                    class="text-xs text-muted-foreground"
                                >
                                    {{ user.email }}
                                </p>
                            </td>
                            <td class="px-3 py-2 text-right">
                                {{ user.generations }}
                            </td>
                            <td class="px-3 py-2 text-right">
                                {{ user.charged }}
                            </td>
                            <td class="px-3 py-2 text-right">
                                {{ user.cost }}
                            </td>
                            <td class="px-3 py-2 text-right">
                                {{ user.profit }}
                            </td>
                        </tr>
                    </tbody>
                    <tfoot class="border-t font-medium">
                        <tr>
                            <td class="px-3 py-2">Total</td>
                            <td class="px-3 py-2 text-right">
                                {{ totals.generations }}
                            </td>
                            <td class="px-3 py-2 text-right">
                                {{ totals.charged }}
                            </td>
                            <td class="px-3 py-2 text-right">
                                {{ totals.cost }}
                            </td>
                            <td class="px-3 py-2 text-right">
                                {{ totals.profit }}
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </section>
    </div>
</template>
