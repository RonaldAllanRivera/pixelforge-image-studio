<?php

namespace App\Http\Middleware;

use App\Credits\CreditLedger;
use App\Http\Requests\PurchaseCreditsRequest;
use App\Models\User;
use App\Support\Nanodollars;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    public function __construct(
        private readonly CreditLedger $ledger,
    ) {}

    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],
            'credits' => fn () => $this->credits($request->user()),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /**
     * The signed-in user's credits, as display strings only: the browser never gets money as
     * a number. The balance is rounded down, so it never shows more than the user has.
     *
     * @return array{balance: string, balance_exact: string, held: string, held_images: int, amounts: list<int>}|null
     */
    private function credits(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        $balance = $this->ledger->balance($user);
        $held = $this->ledger->held($user);

        return [
            'balance' => Nanodollars::formatRoundedDown($balance),
            'balance_exact' => Nanodollars::format($balance, 9),
            // Rounded up, like the "never more than" each hold was quoted as.
            'held' => Nanodollars::formatAtMost($held['amount_nano']),
            'held_images' => $held['images'],
            'amounts' => PurchaseCreditsRequest::AMOUNTS,
        ];
    }
}
