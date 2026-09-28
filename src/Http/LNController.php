<?php

namespace LiveNetworks\LnStarter\Http;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use LiveNetworks\LnStarter\DTOs\Message;
use LiveNetworks\LnStarter\Exceptions\VersionConflictException;
use LiveNetworks\LnStarter\Support\RecordSerializer;

class LNController extends Controller
{
    protected string $view;

    /**
     * Get the current authenticated user
     */
    protected function user()
    {
        return request()->user();
    }

    /**
     * Authorize an action using Gate
     */
    protected function authorize(string $ability, $arguments = []): void
    {
        Gate::authorize($ability, $arguments);
    }

    /**
     * Check if user can perform action
     */
    protected function can(string $ability, $arguments = []): bool
    {
        return Gate::allows($ability, $arguments);
    }

    /**
     * Set the Blade view for this response
     */
    protected function view(string $view): static
    {
        $this->view = $view;
        return $this;
    }

    /**
     * Smart response based on request mode:
     *
     * - data mode → raw JSON of $content (no envelope; toasts are client-side)
     * - ajax mode → Blade rendered through _ajax (JSON sections + message)
     * - full mode → full Blade page
     */
    protected function respondWith($content, ?Message $message = null)
    {
        if (ResponseMode::isData(request())) {
            return response()->json($content);
        }

        return view($this->view, [
            'response' => [
                'message' => $message,
                'content' => $content,
            ],
            'message'  => $message,
        ]);
    }

    /**
     * Data-mode write result: the raw record JSON, shaped via
     * ProvidesRecord::toRecord() (single source). In ajax/full modes falls
     * back to the standard dual-mode view render carrying $message.
     */
    protected function respondWithRecord($record, ?Message $message = null, int $status = 200)
    {
        if (ResponseMode::isData(request())) {
            return response()->json(RecordSerializer::toArray($record), $status);
        }

        return $this->respondWith($record, $message);
    }

    /**
     * Data-mode sync feed: { data: [...], deleted: [...], synced_at: <ts> }.
     * v1 may ignore incremental $since and return the full set with an empty
     * deleted list; the signature already carries tombstones for later. In
     * ajax/full modes falls back to a view render of the collection.
     */
    protected function respondWithSync(iterable $records, array $deleted = [], ?int $syncedAt = null)
    {
        if (ResponseMode::isData(request())) {
            $data = [];
            foreach ($records as $record) {
                $data[] = RecordSerializer::toArray($record);
            }

            return response()->json([
                'data'      => $data,
                'deleted'   => array_values($deleted),
                'synced_at' => $syncedAt ?? now()->getTimestamp(),
            ]);
        }

        return $this->respondWith($records);
    }

    /**
     * Data-mode delete result: { ok: true, id: <id> }. In ajax/full modes
     * falls back to the standard view render carrying $message.
     */
    protected function respondWithDeleted($id, ?Message $message = null)
    {
        if (ResponseMode::isData(request())) {
            return response()->json(['ok' => true, 'id' => is_numeric($id) ? (int) $id : $id]);
        }

        return $this->respondWith(null, $message);
    }

    /**
     * Opt-in optimistic-locking guard. When $expectedVersion is null (client
     * sent no expected_version) this is a no-op. Otherwise a mismatch against
     * the record's version column throws VersionConflictException, rendered
     * centrally as HTTP 409 with the server's current record in data mode.
     */
    protected function guardVersion($record, ?int $expectedVersion, string $column = 'version'): void
    {
        if ($expectedVersion === null) {
            return;
        }

        if ((int) $record->{$column} !== $expectedVersion) {
            throw new VersionConflictException($record, $column);
        }
    }
}
