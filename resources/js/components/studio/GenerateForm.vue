<script setup lang="ts">
import {
    ChevronDown,
    Plus,
    RectangleHorizontal,
    RectangleVertical,
    Square,
    X,
} from '@lucide/vue';
import { useForm, useHttp, usePage } from '@inertiajs/vue3';
import { watchDebounced } from '@vueuse/core';
import type { Component } from 'vue';
import { computed, onMounted, ref, watch } from 'vue';
import BuyCreditsDialog from '@/components/credits/BuyCreditsDialog.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import { store } from '@/routes/generations';
import { quote } from '@/routes/studio';
import type { GenerationPrefill, Option, Quote, StudioLimits } from '@/types';

const props = defineProps<{
    sizes: Option[];
    qualities: Option[];
    limits: StudioLimits;
    prefill: GenerationPrefill | null;
}>();

const form = useForm({
    prompt: '',
    size: '1024x1024',
    quality: 'medium',
    reference_images: [] as string[],
});

// Bound from here: vue-tsc misreads a literal "https://" in a component's static attribute.
const referencePlaceholder = 'https://example.com/image.png';

const sizeIcons: Record<string, Component> = {
    '1024x1024': Square,
    '1536x1024': RectangleHorizontal,
    '1024x1536': RectangleVertical,
};

// Counts Unicode characters (an emoji is one), the way the server and Pixelforge count.
const characters = computed(() => Array.from(form.prompt).length);

const filledReferences = computed(() =>
    form.reference_images.filter((url) => url.trim() !== ''),
);

// The live price. The server does all the math; this only shows its answer.
const price = ref<Quote | null>(null);
const quoteRequest = useHttp<
    {
        prompt: string;
        size: string;
        quality: string;
        reference_images: string[];
    },
    Quote
>(quote(), { prompt: '', size: '', quality: '', reference_images: [] });

// The balance, shared with every page. A purchase or a settled image changes it.
const page = usePage();
const credits = computed(() => page.props.credits);

// The inputs that change the price. The price on screen is current only when it was
// worked out for exactly these; otherwise it's dimmed and Generate waits for the new one.
// The balance is one of them: the server says whether it covers each maximum, so a
// purchase or a settled image re-asks without the user touching the form.
const priceInputs = computed(() =>
    JSON.stringify([
        form.prompt,
        form.size,
        form.quality,
        filledReferences.value.length,
        credits.value?.balance,
    ]),
);
const pricedFor = ref<string | null>(null);
const priceProblem = ref<string | null>(null);
const priceIsCurrent = computed(
    () =>
        price.value !== null &&
        priceProblem.value === null &&
        pricedFor.value === priceInputs.value,
);

// The server's answer for the chosen quality. It checks again when the form is sent.
const cannotAfford = computed(
    () =>
        priceIsCurrent.value &&
        price.value?.qualities[form.quality]?.affordable === false,
);
const creditsError = computed(
    () => (form.errors as Record<string, string | undefined>).credits,
);

function refreshPrice(): void {
    const inputs = priceInputs.value;

    quoteRequest.cancel();
    quoteRequest.prompt = form.prompt;
    quoteRequest.size = form.size;
    quoteRequest.quality = form.quality;
    quoteRequest.reference_images = filledReferences.value;
    quoteRequest
        .submit()
        .then((answer) => {
            // useHttp answers a validation error (422) with nothing.
            if (answer === undefined) {
                priceProblem.value = quoteRequest.errors.prompt
                    ? `The prompt is longer than ${props.limits.prompt.toLocaleString()} characters.`
                    : "The price can't be worked out for these choices.";

                return;
            }

            price.value = answer;
            pricedFor.value = inputs;
            priceProblem.value = null;
        })
        .catch(() => {
            // A newer request replaced this one, so it will answer instead.
            if (!quoteRequest.processing) {
                priceProblem.value =
                    'The price is unavailable right now. Try again in a moment.';
            }
        });
}

onMounted(refreshPrice);
watchDebounced(priceInputs, refreshPrice, { debounce: 300 });

// "Try again" on a failed card fills the form with that generation.
watch(
    () => props.prefill,
    (prefill) => {
        if (prefill) {
            form.prompt = prefill.prompt;
            form.size = prefill.size;
            form.quality = prefill.quality;
            form.reference_images = [...prefill.reference_images];
            form.clearErrors();
        }
    },
);

function addReference(): void {
    form.reference_images.push('');
}

function removeReference(index: number): void {
    form.reference_images.splice(index, 1);
}

function referenceError(index: number): string | undefined {
    return (form.errors as Record<string, string | undefined>)[
        `reference_images.${index}`
    ];
}

function submit(): void {
    form.transform((data) => ({
        ...data,
        reference_images: data.reference_images.filter(
            (url) => url.trim() !== '',
        ),
    })).post(store.url(), {
        preserveScroll: true,
        onSuccess: () => form.reset('prompt', 'reference_images'),
    });
}
</script>

<template>
    <form
        class="space-y-6 rounded-xl border p-4 md:p-6"
        @submit.prevent="submit"
    >
        <div class="grid gap-2">
            <div class="flex items-baseline justify-between">
                <Label for="prompt">Prompt</Label>
                <span
                    :class="
                        cn(
                            'text-xs text-muted-foreground tabular-nums',
                            characters > limits.prompt &&
                                'text-red-600 dark:text-red-500',
                        )
                    "
                >
                    {{ characters.toLocaleString() }} /
                    {{ limits.prompt.toLocaleString() }}
                </span>
            </div>
            <textarea
                id="prompt"
                data-test="prompt"
                v-model="form.prompt"
                rows="4"
                placeholder="A red fox in the snow, watercolor"
                :aria-invalid="form.errors.prompt ? true : undefined"
                class="w-full min-w-0 rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs transition-[color,box-shadow] outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:border-destructive md:text-sm dark:bg-input/30"
            />
            <InputError :message="form.errors.prompt" />
        </div>

        <fieldset class="grid gap-2">
            <legend class="mb-2 text-sm font-medium">Size</legend>
            <div class="grid grid-cols-3 gap-2">
                <button
                    v-for="size in sizes"
                    :key="size.value"
                    type="button"
                    :aria-pressed="form.size === size.value"
                    :class="
                        cn(
                            'flex flex-col items-center gap-1 rounded-lg border p-3 text-sm transition-colors hover:bg-accent',
                            form.size === size.value &&
                                'border-primary bg-accent',
                        )
                    "
                    @click="form.size = size.value"
                >
                    <component :is="sizeIcons[size.value]" class="size-5" />
                    {{ size.label }}
                    <span class="text-xs text-muted-foreground">{{
                        size.value
                    }}</span>
                </button>
            </div>
        </fieldset>

        <fieldset class="grid gap-2">
            <legend class="mb-2 text-sm font-medium">Quality</legend>
            <div class="grid grid-cols-3 gap-2">
                <button
                    v-for="quality in qualities"
                    :key="quality.value"
                    type="button"
                    :aria-pressed="form.quality === quality.value"
                    :class="
                        cn(
                            'flex flex-col items-center gap-1 rounded-lg border p-3 text-sm transition-colors hover:bg-accent',
                            form.quality === quality.value &&
                                'border-primary bg-accent',
                        )
                    "
                    @click="form.quality = quality.value"
                >
                    {{ quality.label }}
                    <span
                        :class="
                            cn(
                                'text-xs text-muted-foreground tabular-nums',
                                !priceIsCurrent && 'opacity-50',
                            )
                        "
                    >
                        {{ price?.qualities[quality.value]?.estimated ?? '…' }}
                    </span>
                </button>
            </div>
        </fieldset>

        <div class="grid gap-2">
            <Label
                >Reference images
                <span class="font-normal text-muted-foreground"
                    >(optional)</span
                ></Label
            >
            <div
                v-for="(url, index) in form.reference_images"
                :key="index"
                class="grid gap-1"
            >
                <div class="flex items-center gap-2">
                    <Input
                        v-model="form.reference_images[index]"
                        type="url"
                        :placeholder="referencePlaceholder"
                        :aria-label="`Reference image URL ${index + 1}`"
                    />
                    <span class="shrink-0 text-xs text-muted-foreground">
                        +{{ limits.tokensPerReferenceImage }} tokens
                    </span>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        :aria-label="`Remove reference image ${index + 1}`"
                        @click="removeReference(index)"
                    >
                        <X class="size-4" />
                    </Button>
                </div>
                <InputError :message="referenceError(index)" />
            </div>
            <div>
                <Button
                    v-if="form.reference_images.length < limits.referenceImages"
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="addReference"
                >
                    <Plus class="size-4" /> Add reference image URL
                </Button>
            </div>
            <InputError :message="form.errors.reference_images" />
        </div>

        <div class="space-y-3 rounded-lg bg-muted/50 p-4">
            <p
                v-if="priceProblem"
                class="text-base text-amber-700 dark:text-amber-400"
            >
                {{ priceProblem }}
            </p>
            <p
                v-else-if="price"
                :class="
                    cn(
                        'text-base transition-opacity',
                        !priceIsCurrent && 'opacity-50',
                    )
                "
            >
                Estimated
                <strong class="tabular-nums">{{
                    price.qualities[form.quality]?.estimated
                }}</strong>
                · never more than
                <strong class="tabular-nums">{{
                    price.qualities[form.quality]?.maximum
                }}</strong>
                <span
                    v-if="!priceIsCurrent"
                    class="ml-2 text-xs text-muted-foreground"
                    >Updating…</span
                >
            </p>
            <p v-else class="text-base text-muted-foreground">
                Working out the price…
            </p>
            <p class="text-sm text-muted-foreground">
                You pay the actual cost plus 25%, never more than the maximum.
                Failed generations are free.
            </p>
            <div
                v-if="credits"
                class="flex flex-wrap items-center justify-between gap-2 border-t pt-3"
            >
                <p class="text-sm" data-test="balance">
                    Balance
                    <strong class="tabular-nums">{{ credits.balance }}</strong>
                </p>
                <BuyCreditsDialog />
            </div>

            <Collapsible v-if="price">
                <CollapsibleTrigger
                    class="flex items-center gap-1 text-sm font-medium hover:underline"
                >
                    <ChevronDown class="size-4" /> How this price is made
                </CollapsibleTrigger>
                <CollapsibleContent>
                    <div class="mt-3 overflow-x-auto">
                        <table class="w-full text-sm tabular-nums">
                            <thead
                                class="text-left text-xs text-muted-foreground"
                            >
                                <tr>
                                    <th class="py-1 pr-4 font-normal">
                                        Tokens
                                    </th>
                                    <th
                                        class="py-1 pr-4 text-right font-normal"
                                    >
                                        Estimated
                                    </th>
                                    <th
                                        class="py-1 pr-4 text-right font-normal"
                                    >
                                        At most
                                    </th>
                                    <th class="py-1 text-right font-normal">
                                        Per 1M
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="row in price.breakdown.rows"
                                    :key="row.label"
                                >
                                    <td class="py-1 pr-4">{{ row.label }}</td>
                                    <td class="py-1 pr-4 text-right">
                                        {{
                                            row.estimated_tokens.toLocaleString()
                                        }}
                                    </td>
                                    <td class="py-1 pr-4 text-right">
                                        {{
                                            row.maximum_tokens.toLocaleString()
                                        }}
                                    </td>
                                    <td class="py-1 text-right">
                                        {{ row.rate }}
                                    </td>
                                </tr>
                            </tbody>
                            <tfoot class="border-t">
                                <tr>
                                    <td class="py-1 pr-4">Pixelforge's cost</td>
                                    <td class="py-1 pr-4 text-right">
                                        {{ price.breakdown.estimated_cost }}
                                    </td>
                                    <td class="py-1 pr-4 text-right">
                                        {{ price.breakdown.maximum_cost }}
                                    </td>
                                    <td></td>
                                </tr>
                                <tr>
                                    <td class="py-1 pr-4">Our fee (25%)</td>
                                    <td class="py-1 pr-4 text-right">
                                        {{ price.breakdown.estimated_fee }}
                                    </td>
                                    <td class="py-1 pr-4 text-right">
                                        {{ price.breakdown.maximum_fee }}
                                    </td>
                                    <td></td>
                                </tr>
                                <tr class="font-medium">
                                    <td class="py-1 pr-4">You pay</td>
                                    <td class="py-1 pr-4 text-right">
                                        {{ price.breakdown.estimated_charge }}
                                    </td>
                                    <td class="py-1 pr-4 text-right">
                                        {{ price.breakdown.maximum_charge }}
                                    </td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </CollapsibleContent>
            </Collapsible>
        </div>

        <div class="space-y-2">
            <Button
                type="submit"
                data-test="generate"
                class="w-full sm:w-auto"
                :disabled="form.processing || !priceIsCurrent || cannotAfford"
            >
                <Spinner v-if="form.processing" />
                {{ cannotAfford ? 'Not enough credits' : 'Generate' }}
            </Button>
            <p
                v-if="creditsError"
                data-test="credits-error"
                class="text-sm text-red-600 dark:text-red-500"
            >
                {{ creditsError }}
            </p>
        </div>
    </form>
</template>
