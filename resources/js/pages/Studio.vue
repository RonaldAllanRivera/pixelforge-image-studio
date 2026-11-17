<script setup lang="ts">
import { Head, Link, usePoll } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import GenerateForm from '@/components/studio/GenerateForm.vue';
import GenerationCard from '@/components/studio/GenerationCard.vue';
import { Button } from '@/components/ui/button';
import { studio } from '@/routes';
import type {
    Generation,
    GenerationPrefill,
    Option,
    Paginated,
    StudioLimits,
} from '@/types';

const props = defineProps<{
    generations: Paginated<Generation>;
    sizes: Option[];
    qualities: Option[];
    limits: StudioLimits;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Studio', href: studio() }],
    },
});

// Live status: reload only the history every 2 seconds, and only while something on
// this page is still running. Once everything is final (or stalled, with no job working on
// it), polling stops and the app can sleep. "rest" waits for each answer before the next
// poll, so answers can't arrive out of order; a hidden tab pauses.
const { start, stop } = usePoll(
    2000,
    { only: ['generations'] },
    { autoStart: false, mode: 'rest', background: 'pause' },
);
const hasRunning = computed(() =>
    props.generations.data.some(
        (generation) => !generation.is_final && !generation.is_stalled,
    ),
);

watch(hasRunning, (running) => (running ? start() : stop()), {
    immediate: true,
});

const prefill = ref<GenerationPrefill | null>(null);

function retry(generation: Generation): void {
    prefill.value = {
        prompt: generation.prompt,
        size: generation.size,
        quality: generation.quality,
        reference_images: generation.reference_images,
    };
    window.scrollTo({ top: 0, behavior: 'smooth' });
}
</script>

<template>
    <Head title="Studio" />

    <div class="mx-auto flex w-full max-w-3xl flex-col gap-8 p-4">
        <GenerateForm
            :sizes="sizes"
            :qualities="qualities"
            :limits="limits"
            :prefill="prefill"
        />

        <section class="space-y-4">
            <Heading
                variant="small"
                title="Your images"
                description="Newest first. Status updates by itself while an image is being made."
            />

            <p
                v-if="generations.data.length === 0"
                class="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground"
            >
                No images yet. Write a prompt above to make your first one.
            </p>

            <GenerationCard
                v-for="generation in generations.data"
                :key="generation.id"
                :generation="generation"
                @retry="retry"
            />

            <nav
                v-if="generations.meta.last_page > 1"
                class="flex items-center justify-between text-sm"
            >
                <Button
                    v-if="generations.links.prev"
                    variant="outline"
                    size="sm"
                    as-child
                >
                    <Link :href="generations.links.prev" preserve-scroll
                        >Newer</Link
                    >
                </Button>
                <span v-else></span>
                <span class="text-muted-foreground">
                    Page {{ generations.meta.current_page }} of
                    {{ generations.meta.last_page }}
                </span>
                <Button
                    v-if="generations.links.next"
                    variant="outline"
                    size="sm"
                    as-child
                >
                    <Link :href="generations.links.next" preserve-scroll
                        >Older</Link
                    >
                </Button>
                <span v-else></span>
            </nav>
        </section>
    </div>
</template>
