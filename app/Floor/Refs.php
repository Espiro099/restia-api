<?php

namespace App\Floor;

use App\Models\Product;
use App\Models\Station;
use App\Models\TableSession;
use App\Models\User;
use Illuminate\Support\Collection;
use MongoDB\BSON\ObjectId;

/** Traduce referencias internas (ObjectId) a los UUID públicos, con una consulta por colección (D-22). */
final class Refs
{
    /** @param array<string, array<string, string>> $maps */
    private function __construct(private readonly array $maps) {}

    public static function load(Collection $products, Collection $stations, Collection $users, Collection $sessions): self
    {
        $load = function (string $model, Collection $ids): array {
            $ids = $ids->filter()->map(fn ($id) => (string) $id)->unique()->values();
            if ($ids->isEmpty()) {
                return [];
            }

            return $model::query()->whereIn('_id', $ids->map(fn ($id) => new ObjectId($id))->all())->get(['_id', 'uuid'])
                ->mapWithKeys(fn ($m) => [(string) $m->getKey() => $m->uuid])->all();
        };

        return new self([
            'product' => $load(Product::class, $products),
            'station' => $load(Station::class, $stations),
            'user' => $load(User::class, $users),
            'session' => $load(TableSession::class, $sessions),
        ]);
    }

    public function product($id): ?string
    {
        return $this->maps['product'][(string) $id] ?? null;
    }

    public function station($id): ?string
    {
        return $this->maps['station'][(string) $id] ?? null;
    }

    public function user($id): ?string
    {
        return $this->maps['user'][(string) $id] ?? null;
    }

    public function session($id): ?string
    {
        return $this->maps['session'][(string) $id] ?? null;
    }
}
