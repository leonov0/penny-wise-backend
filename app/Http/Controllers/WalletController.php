<?php

namespace App\Http\Controllers;

use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Services\CurrencyConversionService; // Import the service

class WalletController extends Controller
{
    protected $currencyService;

    public function __construct(CurrencyConversionService $currencyService)
    {
        $this->currencyService = $currencyService;
    }

    public function index()
    {
        // Get all wallets associated with the authenticated user
        $wallets = Wallet::where('user_id', Auth::id())->get();
        $totalBalance = 0;
        $primaryCurrency = 'EUR'; // Set your primary currency here

        $walletData = [];

        // Loop through each wallet and convert balances as necessary
        foreach ($wallets as $wallet) {
            // Convert balance to primary currency using the service
            $convertedBalance = $this->currencyService->convertForWallet($wallet->currency, $primaryCurrency, $wallet->balance);

            // Sum the converted balances
            $totalBalance += $convertedBalance;

            // Prepare data for each wallet
            $walletData[] = [
                'id' => $wallet->id,
                'name' => $wallet->name,
                'balance' => $wallet->balance,
                'currency' => $wallet->currency,
                'transactions' => $wallet->transactions->map(function ($transaction) {
                    return [
                        'id' => $transaction->id,
                        'amount' => $transaction->amount,
                        'currency' => $transaction->currency,
                        'description' => $transaction->description,
                        'date' => $transaction->date,
                        'category_name' => $transaction->category->name ?? 'No Category',
                    ];
                }),
            ];
        }

        // Prepare the response data
        return response()->json([
            'wallets' => $walletData,
            'total_balance' => round($totalBalance, 2), // Format to 2 decimal places
            'currency' => $primaryCurrency,
        ]);
    }


    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        // Validate the request data
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'balance' => [
                'required',
                'numeric',
                'min:-99999999',
                'max:99999999',
            ],
            'currency' => 'required|string|size:3',
        ]);

        try {
            $validated['user_id'] = Auth::id();

            // Create the wallet
            $wallet = Wallet::create($validated);

            // Get all wallets associated with the authenticated user
            $wallets = Wallet::where('user_id', Auth::id())->get();
            $totalBalance = 0;
            $primaryCurrency = 'EUR';

            foreach ($wallets as $w) {
                $convertedBalance = $this->currencyService->convertForWallet($w->currency, $primaryCurrency, $w->balance);
                $totalBalance += $convertedBalance;
            }
          
            // Загрузите транзакции вместе с категориями
            $wallet->load('transactions.category');
        
            // Преобразуем данные в удобный формат для ответа
            $walletData = [
                'id' => $wallet->id,
                'name' => $wallet->name,
                'balance' => $wallet->balance,
                'currency' => $wallet->currency,
                'transactions' => $wallet->transactions->map(function($transaction) {
                    return [
                        'id' => $transaction->id,
                        'amount' => $transaction->amount,
                        'currency' => $transaction->currency,
                        'description' => $transaction->description,
                        'date' => $transaction->date,
                        'category_name' => $transaction->category->name ?? 'No Category',
                    ];
                }),
            ];
            return response()->json($walletData);

        } catch (QueryException $e) {
            return response()->json(['error' => 'A wallet with this name already exists'], 409);
        }
    }

    
    
    /**
     * Display the specified resource.
     */
    public function show(Wallet $wallet)
    {
        // Ensure the wallet belongs to the authenticated user
        if ($wallet->user_id !== Auth::id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }
        
        // Загрузите транзакции вместе с категориями
        $wallet->load('transactions.category');
    
        // Преобразуем данные в удобный формат для ответа
        $walletData = [
            'id' => $wallet->id,
            'name' => $wallet->name,
            'balance' => $wallet->balance,
            'currency' => $wallet->currency,
            'transactions' => $wallet->transactions->map(function($transaction) {
                return [
                    'id' => $transaction->id,
                    'amount' => $transaction->amount,
                    'currency' => $transaction->currency,
                    'description' => $transaction->description,
                    'date' => $transaction->date,
                    'category_name' => $transaction->category->name ?? 'No Category',
                ];
            }),
        ];
        return response()->json($walletData);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Wallet $wallet)
    {
        // Ensure the wallet belongs to the authenticated user
        if ($wallet->user_id !== Auth::id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }
    
        // Validate the incoming request for name and currency
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'currency' => 'required|string|size:3',
        ]);
    
        // Update the wallet with the new name and currency
        $wallet->name = $validated['name'];
        $newCurrency = $validated['currency'];
        
        // Get the current currency of the wallet
        $currentCurrency = $wallet->currency;
    
        // Update the wallet currency
        $wallet->currency = $newCurrency;
        $wallet->save(); // Save changes
    
        // Update all associated transactions with the new currency
        foreach ($wallet->transactions as $transaction) {
            // You may need to implement a currency conversion function if applicable
            $transaction->currency = $newCurrency;
            $transaction->save(); // Save the updated transaction
        }
    
        // Load the wallet's transactions
        $wallet->load('transactions'); // Eager load the transactions relationship
    
        // Return the updated wallet with its transactions
        return response()->json([
            'wallet' => $wallet
        ]);
    }

    public function destroy(Wallet $wallet)
    {
        // Ensure the wallet belongs to the authenticated user
        if ($wallet->user_id !== Auth::id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Get the current total balance before deletion
        $currentTotalBalance = Wallet::where('user_id', Auth::id())->sum('balance');

        // Delete the wallet
        $wallet->delete();

        // Get all wallets associated with the authenticated user
        $wallets = Wallet::where('user_id', Auth::id())->get();

        // Calculate the new total balance for all wallets
        $totalBalance = $wallets->sum('balance');

        $primaryCurrency = 'EUR';

        // Return all wallets and the total balance in a JSON response
        return response()->json([
            'wallets' => $wallets,
            'total_balance' => round($totalBalance, 2), // Format the total balance to 2 decimal places
            'currency' => $primaryCurrency,
        ]);
    }
}
