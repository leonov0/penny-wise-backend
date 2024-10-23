<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Models\Category;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Support\Facades\Auth;
use Smalot\PdfParser\Parser;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class TransactionController extends Controller
{
    public function index()
    {
        $transactions = Transaction::where('user_id', Auth::id())->get();

        return response()->json($transactions);
    }

    public function store(Request $request)
    {
        $request->validate([
            'category_id' => 'required|exists:categories,id',
            'amount' => 'required|numeric',
            'description' => 'nullable|string',
            'date' => 'required|date',
        ]);

        Transaction::create([
            'user_id' => Auth::id(),
            'category_id' => $request->category_id,
            'amount' => $request->amount,
            'description' => $request->description,
            'date' => $request->date,
        ]);

        return redirect()->route('transactions.index')->with('success', 'Transaction created successfully.');
    }

    public function update(Request $request, Transaction $transaction)
    {
        $request->validate([
            'category_id' => 'required|exists:categories,id',
            'amount' => 'required|numeric',
            'description' => 'nullable|string',
            'date' => 'required|date',
        ]);

        $transaction->update([
            'category_id' => $request->category_id,
            'amount' => $request->amount,
            'description' => $request->description,
            'date' => $request->date,
        ]);

        return redirect()->route('transactions.index')->with('success', 'Transaction updated successfully.');
    }

    public function destroy(Transaction $transaction)
    {
        $transaction->delete();

        return redirect()->route('transactions.index')->with('success', 'Transaction deleted successfully.');
    }

    public function export()
    {
        $transactions = Transaction::all(); 

        $fileName = 'transactions.csv';

        $response = new StreamedResponse(function () use ($transactions) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, ['ID', 'User ID', 'Description', 'Amount', 'Transaction Date']);

            foreach ($transactions as $transaction) {
                fputcsv($handle, [
                    $transaction->id,
                    $transaction->user_id,
                    $transaction->description,
                    $transaction->amount,
                    $transaction->transaction_date,
                ]);
            }

            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $fileName . '"');

        return $response;
    }




    
    public function import(Request $request)
{
    $validator = Validator::make($request->all(), [
        'csv_file' => 'required|file|mimes:csv,txt,pdf',
    ]);

    if ($validator->fails()) {
        return response()->json(['error' => 'File isn\'t chosen or wrong format'], 400);
    }

    $transactions = []; // Array to hold imported transaction data

    if ($request->hasFile('csv_file')) {
        $file = $request->file('csv_file');

        try {
            // Log the file type
            \Log::info('Uploaded File Type: ', ['type' => $file->getClientOriginalExtension()]);

            if ($file->getClientOriginalExtension() === 'csv' || $file->getClientOriginalExtension() === 'txt') {
                // Handle CSV or TXT file
                $csvData = array_map('str_getcsv', file($file->getRealPath()));
                $header = array_shift($csvData); // Optional: process headers

                \Log::info('CSV Data: ', $csvData); // Log CSV data

                foreach ($csvData as $row) {
                    // Log each row for debugging
                    \Log::info('Processing CSV Row: ', $row);

                    if (count($row) < 5) {
                        \Log::info('Skipping row due to insufficient data: ', $row);
                        continue; // Skip rows that don't have enough data
                    }

                    $user = User::find($row[0]);
                    $category = Category::find($row[1]);

                    if (!$user || !$category) {
                        \Log::info('Skipping row due to invalid user or category: ', $row);
                        continue; // Skip invalid rows
                    }

                    $transaction = Transaction::create([
                        'user_id' => $row[0],
                        'category_id' => $row[1],
                        'description' => $row[2],
                        'amount' => $row[3],
                        'transaction_date' => $row[4],
                    ]);

                    $transactions[] = $transaction; // Add created transaction to array
                }

                return response()->json(['message' => 'Transactions are successfully imported from CSV', 'transactions' => $transactions], 200);

            } elseif ($file->getClientOriginalExtension() === 'pdf') {
                // Handle PDF file
                $parser = new Parser();
                $pdfDocument = $parser->parseFile($file->getRealPath());
                $text = $pdfDocument->getText();

                \Log::info('PDF Text: ', ['text' => $text]); // Log PDF content

                // Assuming the PDF data is structured in a certain way
                $rows = explode("\n", $text); // Split text into rows

                foreach ($rows as $row) {
                    // Log each row from PDF for debugging
                    \Log::info('Processing PDF Row: ', ['row' => $row]);

                    $columns = explode(",", $row); // Assuming CSV-like format in the PDF

                    if (count($columns) < 5) {
                        \Log::info('Skipping PDF row due to insufficient data: ', $columns);
                        continue; // Skip rows that don't have enough data
                    }

                    $user = User::find($columns[0]);
                    $category = Category::find($columns[1]);

                    if (!$user || !$category) {
                        \Log::info('Skipping PDF row due to invalid user or category: ', $columns);
                        continue; // Skip invalid rows
                    }

                    $transaction = Transaction::create([
                        'user_id' => $columns[0],
                        'category_id' => $columns[1],
                        'description' => $columns[2],
                        'amount' => $columns[3],
                        'transaction_date' => $columns[4],
                    ]);

                    $transactions[] = $transaction; // Add created transaction to array
                }

                return response()->json(['message' => 'Transactions are successfully imported from PDF', 'transactions' => $transactions], 200);
            }

        } catch (\Exception $e) {
            \Log::error('Import error: ' . $e->getMessage());
            return response()->json(['error' => 'An error occurred during import: ' . $e->getMessage()], 500);
        }
    }

    return response()->json(['error' => 'The file was not uploaded'], 400);
}
}
