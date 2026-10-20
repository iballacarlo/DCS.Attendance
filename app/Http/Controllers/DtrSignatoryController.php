<?php

namespace App\Http\Controllers;

use App\Models\DtrSignatory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DtrSignatoryController extends Controller
{
    public function store(Request $request)
    {
        $data = $this->validated($request);
        $this->ensureNoOverlap($data);
        $signatory = DtrSignatory::create([...$data, 'created_by' => $request->user()->id]);
        $this->audit($request, 'dtr_signatory.created', $signatory, "Added DTR signatory {$signatory->name}.");

        return back()->with('success', 'DTR signatory period added.');
    }

    public function update(Request $request, DtrSignatory $dtrSignatory)
    {
        $data = $this->validated($request);
        $this->ensureNoOverlap($data, $dtrSignatory->id);
        $dtrSignatory->update($data);
        $this->audit($request, 'dtr_signatory.updated', $dtrSignatory, "Updated DTR signatory {$dtrSignatory->name}.");

        return back()->with('success', 'DTR signatory period updated.');
    }

    public function destroy(Request $request, DtrSignatory $dtrSignatory)
    {
        $this->audit($request, 'dtr_signatory.deleted', $dtrSignatory, "Deleted DTR signatory {$dtrSignatory->name}.");
        $dtrSignatory->delete();

        return back()->with('success', 'DTR signatory removed.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'designation' => ['required', 'string', 'max:100'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ]);
    }

    private function ensureNoOverlap(array $data, ?int $exceptId = null): void
    {
        $overlap = DtrSignatory::query()->when($exceptId, fn ($query) => $query->whereKeyNot($exceptId))
            ->whereDate('effective_from', '<=', $data['effective_to'] ?: '9999-12-31')
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $data['effective_from']))
            ->exists();

        if ($overlap) {
            throw ValidationException::withMessages(['effective_from' => 'This period overlaps an existing DTR signatory period.']);
        }
    }

    private function audit(Request $request, string $action, DtrSignatory $signatory, string $description): void
    {
        DB::table('audit_trails')->insert([
            'action' => $action, 'table_name' => 'dtr_signatories', 'record_id' => $signatory->id,
            'description' => $description." By user #{$request->user()->id}.", 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
