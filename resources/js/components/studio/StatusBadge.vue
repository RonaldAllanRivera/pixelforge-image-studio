<script setup lang="ts">
import { useNow } from '@vueuse/core';
import { computed, watch } from 'vue';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import type { Generation } from '@/types';

const props = defineProps<{ generation: Generation }>();

const colors: Record<Generation['status'], string> = {
    pending:
        'bg-amber-100 text-amber-900 dark:bg-amber-500/15 dark:text-amber-300',
    queued: 'bg-muted text-muted-foreground',
    processing:
        'bg-blue-100 text-blue-900 dark:bg-blue-500/15 dark:text-blue-300',
    succeeded:
        'bg-emerald-100 text-emerald-900 dark:bg-emerald-500/15 dark:text-emerald-300',
    failed: 'bg-red-100 text-red-900 dark:bg-red-500/15 dark:text-red-300',
};

// The clock ticks only while generating, so finished cards don't re-render every second.
const { now, pause, resume } = useNow({ interval: 1000, controls: true });

const running = computed(
    () => !props.generation.is_final && !props.generation.is_stalled,
);

watch(
    () => running.value && props.generation.status === 'processing',
    (ticking) => (ticking ? resume() : pause()),
    { immediate: true },
);

// How long it has been generating, as m:ss.
const elapsed = computed(() => {
    const since = props.generation.started_at ?? props.generation.created_at;

    if (
        !running.value ||
        props.generation.status !== 'processing' ||
        since === null
    ) {
        return null;
    }

    const seconds = Math.max(
        0,
        Math.floor((now.value.getTime() - Date.parse(since)) / 1000),
    );

    return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
});
</script>

<template>
    <span
        :class="
            cn(
                'inline-flex shrink-0 items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium',
                colors[generation.status],
            )
        "
    >
        <Spinner v-if="running" class="size-3" />
        {{ generation.status_label }}
        <span v-if="elapsed" class="tabular-nums">{{ elapsed }}</span>
    </span>
</template>
