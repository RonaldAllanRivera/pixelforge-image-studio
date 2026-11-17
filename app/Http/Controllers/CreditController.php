<?php

namespace App\Http\Controllers;

use App\Credits\CreditLedger;
use App\Http\Requests\PurchaseCreditsRequest;
use App\Http\Resources\CreditTransactionResource;
use App\Models\User;
use App\Support\Nanodollars;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The user's credits: every entry that changed their balance, and pretend purchases.
 */
final class CreditController extends Controller
{
    public function index(#[CurrentUser] User $user): Response
    {
        return Inertia::render('credits/Index', [
            'entries' => fn () => CreditTransactionResource::collection(
                $user->creditTransactions()->with('generation')->latest('id')->paginate(20),
            ),
        ]);
    }

    /**
     * A pretend purchase: no payment is taken. Back to where it was made, so a prompt typed
     * on the Studio is still there.
     */
    public function store(PurchaseCreditsRequest $request, CreditLedger $ledger, #[CurrentUser] User $user): RedirectResponse
    {
        $amount = $request->amountNano();
        $ledger->purchase($user, $amount);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Added '.Nanodollars::format($amount, 2).'. This is a pretend purchase.']);

        return back();
    }
}
