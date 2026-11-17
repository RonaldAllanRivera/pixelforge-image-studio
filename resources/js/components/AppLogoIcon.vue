<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <!-- A picture frame with a sun and mountains. Filled, so callers color it with fill-current. -->
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 24 24"
        :class="className"
        v-bind="$attrs"
    >
        <path
            fill-rule="evenodd"
            clip-rule="evenodd"
            d="M5 3h14a3 3 0 0 1 3 3v12a3 3 0 0 1-3 3H5a3 3 0 0 1-3-3V6a3 3 0 0 1 3-3Zm0 2a1 1 0 0 0-1 1v12a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1V6a1 1 0 0 0-1-1H5Zm3.5 3a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3ZM5.5 17.5 10 12l3 3.5 2.5-2.5 3 4.5h-13Z"
        />
    </svg>
</template>
