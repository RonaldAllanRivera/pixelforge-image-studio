// Shapes the server sends (see app/Http/Resources and QuoteController).
// Every amount is a formatted string: the browser never does money math.

import type { CreditLine } from './credits';

export type GenerationStatus =
    | 'pending'
    | 'queued'
    | 'processing'
    | 'succeeded'
    | 'failed';

export type Generation = {
    id: number;
    prompt: string;
    size: string;
    size_label: string;
    quality: string;
    quality_label: string;
    reference_images: string[];
    status: GenerationStatus;
    status_label: string;
    is_final: boolean;
    // Unfinished with no news for 15 minutes: no job is working on it, so stop polling.
    is_stalled: boolean;
    image_url: string | null;
    image_expired: boolean;
    failure_reason: string | null;
    estimated_charge: string;
    maximum_charge: string;
    actual_charge: string | null;
    created_at: string | null;
    started_at: string | null;
    completed_at: string | null;
};

export type TokenRow = {
    label: string;
    estimated: number | null;
    maximum: number | null;
    actual: number | null;
    rate: string | null;
    cost: string | null;
};

export type GenerationDetail = Generation & {
    provider_id: string | null;
    submit_attempts: number;
    tokens: TokenRow[];
    totals: { provider_cost: string; fee: string; charge: string } | null;
    // Held, released and charged, oldest first. Empty for generations made before balances.
    credits: CreditLine[];
};

export type Option = { value: string; label: string };

export type StudioLimits = {
    prompt: number;
    referenceImages: number;
    tokensPerReferenceImage: number;
};

export type Quote = {
    characters: number;
    // The user's balance, rounded down.
    balance: string;
    // affordable: the balance covers the maximum. Decided on the server.
    qualities: Record<
        string,
        { estimated: string; maximum: string; affordable: boolean }
    >;
    breakdown: {
        rows: {
            label: string;
            estimated_tokens: number;
            maximum_tokens: number;
            rate: string;
        }[];
        estimated_cost: string;
        maximum_cost: string;
        estimated_fee: string;
        maximum_fee: string;
        estimated_charge: string;
        maximum_charge: string;
    };
};

export type GenerationPrefill = {
    prompt: string;
    size: string;
    quality: string;
    reference_images: string[];
};

export type Paginated<T> = {
    data: T[];
    links: {
        first: string | null;
        last: string | null;
        prev: string | null;
        next: string | null;
    };
    meta: {
        current_page: number;
        last_page: number;
        total: number;
        from: number | null;
        to: number | null;
    };
};
