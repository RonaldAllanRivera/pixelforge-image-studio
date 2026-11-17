<script setup lang="ts">
import { Wallet } from '@lucide/vue';
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/credits/purchases';

withDefaults(
    defineProps<{
        variant?: 'default' | 'outline' | 'secondary';
        size?: 'default' | 'sm';
    }>(),
    { variant: 'outline', size: 'sm' },
);

const page = usePage();
const amounts = computed(() => page.props.credits?.amounts ?? []);

const open = ref(false);
const buying = ref<number | null>(null);

// Opens in place, and keeps the page's state, so a prompt typed on the Studio survives.
function buy(amount: number): void {
    buying.value = amount;
    router.post(
        store.url(),
        { amount },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => (open.value = false),
            onFinish: () => (buying.value = null),
        },
    );
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <Button
                type="button"
                :variant="variant"
                :size="size"
                data-test="buy-credits"
            >
                <Wallet class="size-4" /> Buy credits
            </Button>
        </DialogTrigger>
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Buy credits</DialogTitle>
                <DialogDescription>
                    Pretend purchase: no payment is taken.
                </DialogDescription>
            </DialogHeader>
            <div class="grid grid-cols-3 gap-2">
                <Button
                    v-for="amount in amounts"
                    :key="amount"
                    type="button"
                    variant="outline"
                    size="lg"
                    class="tabular-nums"
                    :data-test="`buy-${amount}`"
                    :disabled="buying !== null"
                    @click="buy(amount)"
                >
                    <Spinner v-if="buying === amount" />
                    ${{ amount }}
                </Button>
            </div>
        </DialogContent>
    </Dialog>
</template>
