<?php

namespace App\Http\Controllers\Subscribers;

use App\Models\ContactList;
use App\Models\ContactListColumnMapping;
use Box\Spout\Common\Exception\IOException;
use Box\Spout\Common\Exception\UnsupportedTypeException;
use Box\Spout\Reader\Exception\ReaderNotOpenedException;
use Exception;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Rap2hpoutre\FastExcel\FastExcel;
use Sendportal\Base\Facades\Sendportal;
use Sendportal\Base\Http\Controllers\Subscribers\SubscribersImportController as BaseImportController;
use Sendportal\Base\Http\Requests\SubscribersImportRequest;
use Sendportal\Base\Repositories\TagTenantRepository;

class SubscribersImportController extends BaseImportController
{
        /**
         * Show the import form with contact lists.
         */
        public function show(TagTenantRepository $tagRepo): ViewContract
        {
            $workspaceId = Sendportal::currentWorkspaceId();
        
            $contactLists = ContactList::where('workspace_id', $workspaceId)
                ->withCount('subscribers')
                ->orderBy('name')
                ->get();

            // Get tags for the tag field
            $tags = $tagRepo->pluck($workspaceId, 'name', 'id');

            return view('sendportal::subscribers.import', compact('contactLists', 'tags'));
        }

    /**
     * Override: Use selected contact list and auto-detect CSV columns with mappings.
     *
     * @throws IOException
     * @throws UnsupportedTypeException
     * @throws ReaderNotOpenedException
     */
    public function store(SubscribersImportRequest $request): RedirectResponse
    {
        if ($request->file('file')->isValid()) {
            $extension = $request->file('file')->getClientOriginalExtension() ?: 'csv';
            $filename  = Str::random(16) . '.' . strtolower($extension);

            $path = $request->file('file')->storeAs('imports', $filename, 'local');

            $workspaceId = Sendportal::currentWorkspaceId();
            $filePath = Storage::disk('local')->path($path);
            $contactListId = $request->integer('contact_list_id');

            // Validate that the contact list exists and belongs to this workspace
            $contactList = ContactList::where('workspace_id', $workspaceId)
                ->findOrFail($contactListId);

            // Detect CSV columns from first row
            $columns = $this->detectColumns($filePath);

            // Always refresh list columns from the uploaded file header.
            // This keeps the list schema dynamic and in sync with the latest import.
            $contactList->update(['columns' => array_values($columns)]);

            // Create column mappings with auto-detected defaults (only new mappings)
            $this->createMappings($contactList, $columns);

            // Import subscribers and associate with ContactList
            $counter = ['created' => 0, 'updated' => 0, 'skipped' => 0];

            (new FastExcel())->import($filePath, function (array $line) use ($request, $contactList, $workspaceId, &$counter) {
                $data = $this->normalizeLine($line);

                // Skip rows without an email since email is required at DB level.
                if (empty($data['email'])) {
                    $counter['skipped']++;
                    return;
                }

                $data['tags'] = $request->get('tags') ?? [];
                $data['contact_list_id'] = $contactList->id;

                // Store all CSV row data in meta (for custom field access)
                $data['meta'] = json_encode($line);

                // Get or create subscriber
                $subscriber = $this->subscriberService->import($workspaceId, $data);

                // Ensure custom columns are persisted even though vendor model fillable is restrictive.
                DB::table('sendportal_subscribers')
                    ->where('id', $subscriber->id)
                    ->update([
                        'contact_list_id' => $contactList->id,
                        'meta' => json_encode($line),
                        'updated_at' => now(),
                    ]);

                if ($subscriber->wasRecentlyCreated) {
                    $counter['created']++;
                } else {
                    $counter['updated']++;
                }
            });

            Storage::disk('local')->delete($path);

            return redirect()->route('contact-lists.show', $contactList->id)
                ->with('success', __('Imported :created subscriber(s), updated :updated subscriber(s), skipped :skipped row(s) from contact list ":list"', [
                    'created' => $counter['created'],
                    'updated' => $counter['updated'],
                    'skipped' => $counter['skipped'],
                    'list' => $contactList->name,
                ]));
        }

        return redirect()->route('sendportal.subscribers.index')
            ->with('errors', __('The uploaded file is not valid'));
    }

    /**
     * Detect column names from the first row of the CSV/Excel file.
     */
    private function detectColumns(string $filePath): array
    {
        try {
            $columns = [];
            (new FastExcel())->import($filePath, function (array $line) use (&$columns) {
                if (empty($columns)) {
                    $columns = array_keys($line);
                }
                return false; // Stop after first row
            });
            return $columns;
        } catch (Exception $e) {
            return ['email', 'first_name', 'last_name']; // Fallback
        }
    }

    /**
     * Create column mappings from CSV columns to merge variables.
     * Uses best-guess matching for common fields and dynamic variable names for all columns.
     */
    private function createMappings(ContactList $contactList, array $columns): void
    {
        $commonMappings = [
            'email' => 'email',
            'e-mail' => 'email',
            'address' => 'address',
            'first name' => 'first_name',
            'firstname' => 'first_name',
            'first_name' => 'first_name',
            'surname' => 'last_name',
            'last name' => 'last_name',
            'lastname' => 'last_name',
            'last_name' => 'last_name',
            'full name' => 'first_name', // Will use first_name for full name on first import
            'name' => 'name',
        ];

        foreach ($columns as $csvColumn) {
            $normalized = strtolower(trim($csvColumn));

            // Start with a dynamic variable name derived from the header.
            $mergeVar = $this->headerToVariable($csvColumn);

            // Prefer known standard variables when a common match exists.
            if (isset($commonMappings[$normalized])) {
                $mergeVar = $commonMappings[$normalized];
            } else {
                // Try partial matching for common patterns
                foreach ($commonMappings as $pattern => $var) {
                    if (str_contains($normalized, $pattern) || str_contains($pattern, $normalized)) {
                        $mergeVar = $var;
                        break;
                    }
                }
            }

            if ($mergeVar === '') {
                continue;
            }

            ContactListColumnMapping::updateOrCreate(
                ['contact_list_id' => $contactList->id, 'csv_column' => $csvColumn],
                ['merge_variable' => $mergeVar]
            );
        }
    }

    /**
     * Convert CSV header text to a safe merge variable name.
     * Example: "Serial Number" => "serial_number".
     */
    private function headerToVariable(string $header): string
    {
        $normalized = $this->normalizeHeader($header);
        $variable = str_replace(' ', '_', $normalized);
        $variable = preg_replace('/[^a-z0-9_]/', '', $variable);

        return trim((string) $variable, '_');
    }

    /**
     * Normalize imported row keys to canonical subscriber fields.
     */
    private function normalizeLine(array $line): array
    {
        $data = Arr::only($line, ['email', 'first_name', 'last_name']);

        $aliases = [];
        foreach ($line as $key => $value) {
            $normalizedKey = $this->normalizeHeader((string) $key);
            $aliases[$normalizedKey] = $value;
        }

        if (empty($data['email'])) {
            $data['email'] = $this->firstAliasValue($aliases, ['email', 'e-mail', 'email address', 'customer email', 'mail']);
        }

        // Fallback: use the first column containing "mail".
        if (empty($data['email'])) {
            foreach ($aliases as $key => $value) {
                if (str_contains($key, 'mail') && !empty($value)) {
                    $data['email'] = $value;
                    break;
                }
            }
        }

        if (empty($data['first_name'])) {
            $data['first_name'] = $this->firstAliasValue($aliases, ['first_name', 'first name', 'firstname', 'given name', 'name']);
        }

        if (empty($data['last_name'])) {
            $data['last_name'] = $this->firstAliasValue($aliases, ['last_name', 'last name', 'lastname', 'surname', 'family name']);
        }

        $data['email'] = isset($data['email']) ? trim((string) $data['email']) : null;

        return $data;
    }

    /**
     * Normalize header key names so matching works with spaces, dashes and BOM.
     */
    private function normalizeHeader(string $header): string
    {
        $header = preg_replace('/^\xEF\xBB\xBF/', '', $header);
        $header = strtolower(trim($header));
        $header = str_replace(['-', '.', '/'], ' ', $header);
        $header = preg_replace('/\s+/', ' ', $header);

        return $header;
    }

    /**
     * Return the first matching alias value from a normalized key map.
     */
    private function firstAliasValue(array $aliases, array $candidates)
    {
        foreach ($candidates as $candidate) {
            if (array_key_exists($candidate, $aliases)) {
                return $aliases[$candidate];
            }
        }

        return null;
    }
}
