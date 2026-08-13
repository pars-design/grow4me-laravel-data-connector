<?php

namespace ParsDesign\Grow4MeDataConnector\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use ParsDesign\Grow4MeDataConnector\Contracts\ResourceAdapter;
use ParsDesign\Grow4MeDataConnector\Models\OutboxEvent;
use ParsDesign\Grow4MeDataConnector\Resources\ResourceRegistry;
use ParsDesign\Grow4MeDataConnector\Support\SignedCursor;

class SyncFeedController
{
    public function index(
        Request $request,
        string $resource,
        ResourceRegistry $resources,
        SignedCursor $cursors,
    ): JsonResponse {
        $adapter = $resources->get($resource);
        $limit = min(max($request->integer('limit', 100), 1), 100);
        $cursor = $request->string('cursor')->toString();

        if ($cursor !== '') {
            $decodedCursor = $cursors->decode($cursor, $resource);
            $snapshotAt = CarbonImmutable::parse($decodedCursor['snapshot_at']);
            $after = $decodedCursor['after'];
        } else {
            $snapshotAt = CarbonImmutable::now('UTC');
            $after = null;
        }

        $models = $adapter->applyCursor($adapter->query($snapshotAt), $after)
            ->limit($limit + 1)
            ->get();
        $hasMore = $models->count() > $limit;
        $pageModels = $models->take($limit)->values();
        $lastModel = $pageModels->last();

        $nextCursor = $hasMore && $lastModel instanceof Model
            ? $cursors->encode([
                'resource' => $resource,
                'snapshot_at' => $snapshotAt->toIso8601String(),
                'after' => $adapter->externalId($lastModel),
            ])
            : null;

        return response()->json([
            'snapshot_at' => $snapshotAt->toIso8601String(),
            'cursor' => $nextCursor,
            'records' => $pageModels
                ->map(fn (Model $model): array => $this->envelope($adapter, $model))
                ->all(),
        ]);
    }

    public function show(
        string $resource,
        string $externalId,
        ResourceRegistry $resources,
    ): JsonResponse {
        $adapter = $resources->get($resource);
        $model = $adapter->find($externalId);

        if (! $model instanceof Model) {
            $latestEvent = OutboxEvent::query()
                ->where('resource', $resource)
                ->where('external_id', $externalId)
                ->latest('id')
                ->first();

            abort_unless($latestEvent?->operation === 'delete', 404, 'Sync record not found.');

            return response()->json([
                'contract_version' => $adapter->contractVersion(),
                'external_id' => $externalId,
                'active' => false,
                'source_updated_at' => $latestEvent->occurred_at->toIso8601String(),
                'payload' => [],
            ]);
        }

        return response()->json($this->envelope($adapter, $model));
    }

    /**
     * @return array{contract_version: string, external_id: string, active: bool, source_updated_at: string, payload: array<string, mixed>}
     */
    private function envelope(ResourceAdapter $adapter, Model $model): array
    {
        return [
            'contract_version' => $adapter->contractVersion(),
            'external_id' => $adapter->externalId($model),
            'active' => $adapter->isActive($model),
            'source_updated_at' => $adapter->sourceUpdatedAt($model)->utc()->toIso8601String(),
            'payload' => $adapter->payload($model),
        ];
    }
}
