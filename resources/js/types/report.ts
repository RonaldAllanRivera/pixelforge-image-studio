// Shapes of the admin report (see Admin\ReportController). Amounts are 9-decimal strings,
// so they can be checked against Pixelforge's cost_usd by eye.

export type ReportRange = { start: string; end: string; max_days: number };

export type ReportAmounts = {
    generations: number;
    charged: string;
    cost: string;
    profit: string;
};

export type ReportDay = ReportAmounts & { date: string };

export type ReportUser = ReportAmounts & {
    name: string;
    // Masked ("al•••@gmail.com"); null for a deleted account.
    email: string | null;
};

// One day, or the whole range, as Pixelforge's GET /v1/usage counted it.
export type PixelforgeFigures = {
    generations: number;
    cost: string;
    matches: boolean;
    // Plain-English lines, empty when it matches.
    differences: string[];
};

export type PixelforgeComparison =
    | {
          available: true;
          days: Record<string, PixelforgeFigures>;
          total: PixelforgeFigures;
          matching_days: number;
          day_count: number;
      }
    | { available: false; message: string };
