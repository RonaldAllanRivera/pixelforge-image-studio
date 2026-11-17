<script setup lang="ts">
import { ArrowLeft, ImageOff } from '@lucide/vue';
import { Head, Link, usePoll } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import StatusBadge from '@/components/studio/StatusBadge.vue';
import { Spinner } from '@/components/ui/spinner';
import { studio } from '@/routes';
import type { GenerationDetail } from '@/types';

const props = defineProps<{ generation: GenerationDetail }>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Studio', href: studio() }],
    },
});

// Same rule as the Studio: poll only while it's still running.
const { start, stop } = usePoll(
    2000,
    { only: ['generation'] },
    { autoStart: false, mode: 'rest', background: 'pause' },
);

watch(
    () => !props.generation.is_final && !props.generation.is_stalled,
    (running) => (running ? start() : stop()),
    { immediate: true },
);

const broken = ref(false);

function local(timestamp: string | null): string {
    return timestamp === null ? '—' : new Date(timestamp).toLocaleString();
}

function count(tokens: number | null): string {
    return tokens === null ? '—' : tokens.toLocaleString();
}
</script>

<template>
    <Head :title="`Generation #${generation.id}`" />

    <div class="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4">
        <Link
            :href="studio()"
            class="inline-flex items-center gap-1 text-sm text-muted-foreground hover:underline"
        >
            <ArrowLeft class="size-4" /> Back to Studio
        </Link>

        <h1 class="text-xl font-semibold">Generation #{{ generation.id }}</h1>

        <div
            class="flex aspect-square w-full max-w-md items-center justify-center self-center overflow-hidden rounded-xl border bg-muted text-muted-foreground"
        >
            <img
                v-if="generation.image_url && !broken"
                :src="generation.image_url"
                :alt="generation.prompt"
                class="size-full object-contain"
                @error="broken = true"
            />
            <p
                v-else-if="generation.is_stalled"
                class="p-6 text-center text-sm"
            >
                Still waiting on Pixelforge. We check again every hour; refresh
                the page to see news.
            </p>
            <Spinner v-else-if="!generation.is_final" class="size-6" />
            <p
                v-else-if="generation.status === 'succeeded'"
                class="flex flex-col items-center gap-2 p-6 text-center text-sm"
            >
                <ImageOff class="size-6" />
                Image expired (Pixelforge keeps images for 24 hours)
            </p>
            <p v-else class="p-6 text-center text-sm">No image</p>
        </div>

        <section class="space-y-3">
            <div class="flex flex-wrap items-center gap-3">
                <StatusBadge :generation="generation" />
                <span class="text-sm text-muted-foreground">
                    {{ generation.size_label }} ({{ generation.size }}) ·
                    {{ generation.quality_label }} quality
                </span>
            </div>
            <p class="break-words whitespace-pre-line">
                {{ generation.prompt }}
            </p>
            <div
                v-if="generation.status === 'failed'"
                class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300"
            >
                {{ generation.failure_reason }} You were not charged.
            </div>
            <div v-if="generation.reference_images.length" class="text-sm">
                <p class="text-muted-foreground">Reference images</p>
                <ul class="list-inside list-disc">
                    <li
                        v-for="url in generation.reference_images"
                        :key="url"
                        class="truncate"
                    >
                        <a
                            :href="url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="hover:underline"
                            >{{ url }}</a
                        >
                    </li>
                </ul>
            </div>
        </section>

        <section class="space-y-3">
            <h2 class="font-medium">Cost</h2>
            <div class="overflow-x-auto rounded-xl border">
                <table class="w-full text-sm tabular-nums">
                    <thead
                        class="bg-muted/50 text-left text-xs text-muted-foreground"
                    >
                        <tr>
                            <th class="px-3 py-2 font-normal">Tokens</th>
                            <th class="px-3 py-2 text-right font-normal">
                                Estimated
                            </th>
                            <th class="px-3 py-2 text-right font-normal">
                                At most
                            </th>
                            <th class="px-3 py-2 text-right font-normal">
                                Actual
                            </th>
                            <th class="px-3 py-2 text-right font-normal">
                                Per 1M
                            </th>
                            <th class="px-3 py-2 text-right font-normal">
                                Cost
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in generation.tokens"
                            :key="row.label"
                            class="border-t"
                        >
                            <td class="px-3 py-2">{{ row.label }}</td>
                            <td class="px-3 py-2 text-right">
                                {{ count(row.estimated) }}
                            </td>
                            <td class="px-3 py-2 text-right">
                                {{ count(row.maximum) }}
                            </td>
                            <td class="px-3 py-2 text-right">
                                {{ count(row.actual) }}
                            </td>
                            <td class="px-3 py-2 text-right">
                                {{ row.rate ?? '—' }}
                            </td>
                            <td class="px-3 py-2 text-right">
                                {{ row.cost ?? '—' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <dl class="grid grid-cols-2 gap-x-6 gap-y-1 text-sm tabular-nums">
                <template v-if="generation.totals">
                    <dt class="text-muted-foreground">Pixelforge's cost</dt>
                    <dd class="text-right">
                        {{ generation.totals.provider_cost }}
                    </dd>
                    <dt class="text-muted-foreground">Our fee (25%)</dt>
                    <dd class="text-right">{{ generation.totals.fee }}</dd>
                    <dt class="font-medium">You paid</dt>
                    <dd class="text-right font-medium">
                        {{ generation.totals.charge }}
                    </dd>
                </template>
                <template v-else>
                    <dt class="font-medium">You paid</dt>
                    <dd class="text-right font-medium">
                        {{ generation.actual_charge ?? 'Pending' }}
                    </dd>
                </template>
                <dt class="text-muted-foreground">Estimated</dt>
                <dd class="text-right">{{ generation.estimated_charge }}</dd>
                <dt class="text-muted-foreground">Never more than</dt>
                <dd class="text-right">{{ generation.maximum_charge }}</dd>
            </dl>
        </section>

        <section class="space-y-3">
            <h2 class="font-medium">Details</h2>
            <dl class="grid grid-cols-2 gap-x-6 gap-y-1 text-sm">
                <dt class="text-muted-foreground">Pixelforge id</dt>
                <dd class="truncate text-right font-mono text-xs">
                    {{ generation.provider_id ?? '—' }}
                </dd>
                <dt class="text-muted-foreground">Submit attempts</dt>
                <dd class="text-right">{{ generation.submit_attempts }}</dd>
                <dt class="text-muted-foreground">Created</dt>
                <dd class="text-right">{{ local(generation.created_at) }}</dd>
                <dt class="text-muted-foreground">Started</dt>
                <dd class="text-right">{{ local(generation.started_at) }}</dd>
                <dt class="text-muted-foreground">Finished</dt>
                <dd class="text-right">
                    {{ local(generation.completed_at) }}
                </dd>
            </dl>
        </section>
    </div>
</template>
