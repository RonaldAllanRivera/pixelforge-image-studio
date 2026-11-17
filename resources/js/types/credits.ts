// Shapes the server sends about credits (see HandleInertiaRequests and
// CreditTransactionResource). Every amount is a formatted string: the browser never
// does money math, and never sees a balance as a number.

// Shared with every signed-in page; null for guests.
export type Credits = {
    // Rounded down, so it never shows more than the user has.
    balance: string;
    balance_exact: string;
    held: string;
    held_images: number;
    // The purchase amounts on offer, in whole dollars.
    amounts: number[];
};

export type CreditType = 'purchase' | 'hold' | 'release' | 'charge';

// One entry on a generation's page: signed, like "−$0.0863".
export type CreditLine = {
    label: string;
    amount: string;
    amount_exact: string;
};

export type CreditEntry = CreditLine & {
    id: number;
    type: CreditType;
    created_at: string | null;
    generation: { id: number; prompt: string } | null;
};
