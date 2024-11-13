<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Models\Category;
use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Services\CurrencyConversionService; // Import the service

class TransactionController extends Controller
{
    protected $currencyService;

    public function __construct(CurrencyConversionService $currencyService)
    {
        $this->currencyService = $currencyService;
    }

    public function index()
    {
        $transactions = Transaction::with('category')->where('user_id', Auth::id())->get();
        $primaryCurrency = 'EUR'; // Primary currency for display

        return response()->json($transactions->map(function ($transaction) use ($primaryCurrency) {
            $convertedAmount = $this->currencyService->convertForWallet($transaction->currency, $primaryCurrency, $transaction->amount);
            return [
                'id' => $transaction->id,
                'wallet_id' => $transaction->wallet_id,
                'amount' => number_format($convertedAmount, 2),
                'currency' => $primaryCurrency,
                'description' => $transaction->description,
                'date' => $transaction->date,
                'category_name' => $transaction->category ? $transaction->category->name : null,
            ];
        }));
    }


    public function store(Request $request)
    {
        $validated = $request->validate([
            'wallet_id' => 'required|exists:wallets,id',
            'category_name' => 'required|string|max:255',
            'amount' => [
                'required',
                'numeric',
                'min:-99999999',
                'max:99999999',
                function ($attribute, $value, $fail) {
                    if ($value == 0) {
                        $fail('The amount cannot be zero.');
                    }
                },
            ],
            'description' => 'nullable|string',
            'date' => 'required|date',
        ]);

        $wallet = Wallet::where('id', $validated['wallet_id'])
                        ->where('user_id', Auth::id())
                        ->first();

        if (!$wallet) {
            return response()->json(['error' => 'Unauthorized: Wallet does not belong to the authenticated user.'], 403);
        }

        $category = Category::firstOrCreate([
            'name' => $validated['category_name'],
            'user_id' => Auth::id(),
        ]);

        $isExpense = $validated['amount'] < 0;

        // Convert transaction amount to wallet's currency if necessary
        $convertedAmount = $this->currencyService->convertForWallet('EUR', $wallet->currency, $validated['amount']);

        if ($isExpense) {
            $wallet->balance -= abs($convertedAmount);
        } else {
            $wallet->balance += $convertedAmount;
        }

        $wallet->save();

        $transaction = Transaction::create([
            'user_id' => Auth::id(),
            'category_id' => $category->id,
            'wallet_id' => $wallet->id,
            'amount' => $convertedAmount,
            'description' => $validated['description'],
            'date' => $validated['date'],
            'currency' => $wallet->currency,
        ]);

        $wallet->load(['transactions.category']);

        $walletWithTransactions = $wallet->transactions->map(function ($transaction) {
            return [
                'id' => $transaction->id,
                'amount' => $transaction->amount,
                'description' => $transaction->description,
                'date' => $transaction->date,
                'currency' => $transaction->currency,
                'category_name' => $transaction->category->name,
            ];
        });

        return response()->json([
            'success' => true,
            'message' => 'Transaction created successfully.',
            'wallet' => [
                'id' => $wallet->id,
                'name' => $wallet->name,
                'balance' => $wallet->balance,
                'currency' => $wallet->currency,
                'transactions' => $walletWithTransactions
            ]
        ], 201);
    }

    public function update(Request $request, Transaction $transaction)
    {
        // Validate the incoming request data
        $validatedData = $request->validate([
            'category_id' => 'required|exists:categories,id',
            'wallet_id' => 'required|exists:wallets,id',
            'amount' => [
                'required',
                'numeric',
                'min:-100000000',
                'max:100000000',
                function ($attribute, $value, $fail) {
                    if ($value == 0) {
                        $fail('The amount cannot be zero.');
                    }
                },
            ],
            'description' => 'nullable|string',
            'date' => 'required|date',
        ]);

        // Find the wallet based on the request
        $wallet = Wallet::findOrFail($validatedData['wallet_id']);

        // Revert the old transaction's effect on the wallet
        if ($transaction->amount < 0) {
            $wallet->balance += abs($transaction->amount);  // Undo previous expense
        } else {
            $wallet->balance -= $transaction->amount;  // Undo previous income
        }

        // Check if the new transaction amount is an expense
        if ($validatedData['amount'] < 0) {
            if ($wallet->balance >= abs($validatedData['amount'])) {
                $wallet->balance += $validatedData['amount'];  // Deduct for new expense
            } else {
                return response()->json(['error' => 'Insufficient funds in the selected wallet.'], 400);
            }
        } else {
            $wallet->balance += $validatedData['amount'];  // Add for new income
        }

        // Save the updated wallet balance
        $wallet->save();

        // Update the transaction with the new data
        $transaction->update([
            'category_id' => $validatedData['category_id'],
            'wallet_id' => $validatedData['wallet_id'], // Use the validated wallet ID
            'amount' => $validatedData['amount'],
            'description' => $validatedData['description'],
            'date' => $validatedData['date'],
        ]);

        // Get the linked wallet for response
        $linkedWallet = Wallet::find($transaction->wallet_id);

        return response()->json([
            'message' => 'Transaction updated successfully',
            'transaction' => [
                'amount' => $transaction->amount,
                'description' => $transaction->description,
                'date' => $transaction->date,
                'category_name' => $transaction->category->name, // Ensure category relationship is loaded
                'wallet' => $linkedWallet,
            ],
        ]);
    }


    public function destroy(Transaction $transaction)
{
    // Find the wallet associated with the transaction
    $wallet = Wallet::find($transaction->wallet_id);

    // Adjust the wallet balance when the transaction is deleted
    if ($transaction->amount < 0) {
        $wallet->balance += abs($transaction->amount);  // Undo expense
    } else {
        $wallet->balance -= $transaction->amount;  // Undo income
    }

    // Save the updated wallet balance
    $wallet->save();

    // Delete the transaction
    $transaction->delete();

    // Load the wallet's transactions
    $wallet->load('transactions.category');  // Eager load the transactions and their categories

    // Format the wallet's transactions, including category name
    $walletTransactions = $wallet->transactions->map(function($transaction) {
        return [
            'id' => $transaction->id,
            'amount' => $transaction->amount,
            'currency' => $transaction->currency,
            'description' => $transaction->description,
            'date' => $transaction->date,
            'category_name' => $transaction->category->name, // Include the category name
        ];
    });

    // Return the wallet, the wallet's transactions, and the deleted transaction info
    return response()->json([
        'message' => 'Transaction deleted successfully.',
        'deleted_transaction' => [
            'amount' => $transaction->amount,
            'currency' => $transaction->currency,
            'description' => $transaction->description,
            'date' => $transaction->date,
            'category_name' => $transaction->category->name,
        ],
        'wallet' => [
            'id' => $wallet->id,
            'name' => $wallet->name,
            'balance' => $wallet->balance,
            'currency' => $wallet->currency,
            'transactions' => $walletTransactions // Include the wallet's remaining transactions
        ]
    ], 200);
}

}