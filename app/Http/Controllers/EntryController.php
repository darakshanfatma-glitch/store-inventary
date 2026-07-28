<?php

namespace App\Http\Controllers;

use App\Models\Entry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class EntryController extends Controller
{
    public function index()
    {
        $entries = Auth::user()->entries()
            ->orderBy('date', 'desc')
            ->orderBy('id', 'desc')
            ->get();

        return Inertia::render('Ledger', [
            'entries' => $entries,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'date'   => ['required', 'date'],
            'type'   => ['required', 'in:sale,staff,expense,udhaar,shortage'],
            'amount' => ['required', 'numeric', 'min:0'],
            'mode'   => ['nullable', 'in:Cash,UPI'],
            'note'   => ['nullable', 'string', 'max:255'],
        ]);

        $request->user()->entries()->create($data);

        return back();
    }

    public function destroy(Entry $entry)
    {
        abort_unless($entry->user_id === Auth::id(), 403);

        $entry->delete();

        return back();
    }
}