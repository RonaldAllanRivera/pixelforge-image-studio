<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Button } from '@/components/ui/button';
import { login, register, studio } from '@/routes';

const page = usePage();

const promises = [
    {
        title: 'The price before you click',
        text: 'Every prompt, size and quality shows an estimate and a maximum before you generate.',
    },
    {
        title: 'Pay what it really cost',
        text: "Pixelforge's exact cost plus 25%, to the billionth of a dollar. Never more than the maximum.",
    },
    {
        title: 'Failures are free',
        text: "If Pixelforge can't make your image, you pay nothing.",
    },
];
</script>

<template>
    <Head title="Welcome" />

    <div class="flex min-h-screen flex-col bg-background text-foreground">
        <header
            class="mx-auto flex w-full max-w-5xl items-center justify-between gap-4 p-6"
        >
            <div class="flex items-center gap-2 font-semibold">
                <AppLogoIcon class="size-7 fill-current" />
                {{ page.props.name }}
            </div>
            <nav class="flex items-center gap-2">
                <Button v-if="page.props.auth.user" as-child>
                    <Link :href="studio()">Open Studio</Link>
                </Button>
                <template v-else>
                    <Button variant="ghost" as-child>
                        <Link :href="login()">Log in</Link>
                    </Button>
                    <Button as-child>
                        <Link :href="register()">Sign up</Link>
                    </Button>
                </template>
            </nav>
        </header>

        <main
            class="mx-auto flex w-full max-w-5xl flex-1 flex-col justify-center gap-12 p-6"
        >
            <div class="max-w-2xl space-y-5">
                <h1
                    class="text-4xl font-semibold tracking-tight text-balance sm:text-5xl"
                >
                    Make images with Pixelforge. Know the price first.
                </h1>
                <p class="text-lg text-muted-foreground">
                    Generate images with Pixelforge. See the price before you
                    start, pay exactly what it cost plus 25%, and never pay for
                    a failure.
                </p>
                <div class="flex flex-wrap gap-3">
                    <Button v-if="page.props.auth.user" size="lg" as-child>
                        <Link :href="studio()">Open Studio</Link>
                    </Button>
                    <template v-else>
                        <Button size="lg" as-child>
                            <Link :href="register()">Sign up free</Link>
                        </Button>
                        <Button size="lg" variant="outline" as-child>
                            <Link :href="login()">Log in</Link>
                        </Button>
                    </template>
                </div>
            </div>

            <ul class="grid gap-4 sm:grid-cols-3">
                <li
                    v-for="promise in promises"
                    :key="promise.title"
                    class="space-y-1 rounded-xl border p-5"
                >
                    <h2 class="font-medium">{{ promise.title }}</h2>
                    <p class="text-sm text-muted-foreground">
                        {{ promise.text }}
                    </p>
                </li>
            </ul>
        </main>

        <footer
            class="mx-auto w-full max-w-5xl p-6 text-sm text-muted-foreground"
        >
            <a
                href="https://github.com/RonaldAllanRivera/pixelforge-image-studio"
                class="hover:underline"
                >Source code on GitHub</a
            >
        </footer>
    </div>
</template>
