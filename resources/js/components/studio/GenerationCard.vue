<script setup lang="ts">
import { Clock, ImageOff, TriangleAlert } from '@lucide/vue';
import { Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import StatusBadge from '@/components/studio/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { show } from '@/routes/generations';
import type { Generation } from '@/types';

defineProps<{ generation: Generation }>();

defineEmits<{ retry: [generation: Generation] }>();

// Set when the image fails to load, for example because its link expired early.
const broken = ref(false);
</script>

<template>
    <article class="flex gap-4 rounded-xl border p-3">
        <Link
            :href="show(generation.id)"
            class="flex size-24 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-muted text-muted-foreground sm:size-28"
        >
            <img
                v-if="generation.image_url && !broken"
                :src="generation.image_url"
                :alt="generation.prompt"
                loading="lazy"
                class="size-full object-cover"
                @error="broken = true"
            />
            <Clock v-else-if="generation.is_stalled" class="size-6" />
            <Spinner v-else-if="!generation.is_final" class="size-5" />
            <TriangleAlert
                v-else-if="generation.status === 'failed'"
                class="size-6"
            />
            <span
                v-else
                class="flex flex-col items-center gap-1 px-2 text-center text-xs"
                title="Pixelforge keeps images for 24 hours"
            >
                <ImageOff class="size-5" />
                Image expired
            </span>
        </Link>

        <div class="min-w-0 flex-1 space-y-2">
            <div class="flex items-start justify-between gap-2">
                <Link
                    :href="show(generation.id)"
                    class="line-clamp-2 font-medium break-words hover:underline"
                >
                    {{ generation.prompt }}
                </Link>
                <StatusBadge :generation="generation" />
            </div>

            <p class="text-xs text-muted-foreground">
                {{ generation.size_label }} · {{ generation.quality_label }}
                <template v-if="generation.created_at">
                    · {{ new Date(generation.created_at).toLocaleString() }}
                </template>
            </p>

            <dl class="flex gap-6 text-sm">
                <div>
                    <dt class="text-xs text-muted-foreground">Estimated</dt>
                    <dd class="tabular-nums">
                        {{ generation.estimated_charge }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">Actual</dt>
                    <dd class="tabular-nums">
                        {{ generation.actual_charge ?? 'Pending' }}
                    </dd>
                </div>
            </dl>

            <p
                v-if="generation.is_stalled"
                class="text-sm text-amber-700 dark:text-amber-400"
            >
                Still waiting on Pixelforge. We check again every hour; refresh
                the page to see news.
            </p>

            <div
                v-if="generation.status === 'failed'"
                class="flex flex-wrap items-center gap-x-3 gap-y-2 text-sm"
            >
                <p class="text-red-700 dark:text-red-400">
                    {{ generation.failure_reason }} You were not charged.
                </p>
                <Button
                    size="sm"
                    variant="outline"
                    @click="$emit('retry', generation)"
                >
                    Try again
                </Button>
            </div>
        </div>
    </article>
</template>
