<?php
declare(strict_types=1);
require_once __DIR__ . '/EsiCache.php';
require_once __DIR__ . '/EsiClient.php';
function guri_industry_reference(): array {
    static $data;
    return $data ??= json_decode((string)file_get_contents(dirname(__DIR__) . '/data/industry-reference.json'), true, 64, JSON_THROW_ON_ERROR);
}
function guri_materials(array $blueprint, int $runs, float $me, float $facilityReduction): array {
    if ($runs < 1 || $runs > 10000 || !is_finite($me) || $me < 0 || $me > 10 || !is_finite($facilityReduction) || $facilityReduction < 0 || $facilityReduction > 90) throw new InvalidArgumentException('Invalid production inputs.');
    $result = [];
    foreach ($blueprint['materials'] as $row) $result[(int)$row['typeID']] = max($runs, (int)ceil(round($runs * $row['quantity'] * (1 - $me / 100) * (1 - $facilityReduction / 100), 2)));
    return $result;
}
/** Consume station-only depth. A partial fill must never look like the cost of the entire batch. */
function guri_order_fill(array $orders, int $station, int $quantity, bool $buyFromMarket): array {
    if ($quantity < 1 || $quantity > 1000000000) throw new InvalidArgumentException('Invalid quantity.');
    $rows = array_values(array_filter($orders, static function ($o) use ($station, $buyFromMarket) {
        return ($o['location_id'] ?? null) === $station && ($o['is_buy_order'] ?? null) === !$buyFromMarket
            && is_numeric($o['price'] ?? null) && is_finite((float)$o['price']) && $o['price'] > 0
            && is_int($o['volume_remain'] ?? null) && $o['volume_remain'] > 0;
    }));
    usort($rows, static function ($a, $b) use ($buyFromMarket) { return $buyFromMarket ? $a['price'] <=> $b['price'] : $b['price'] <=> $a['price']; });
    $remaining = $quantity; $value = 0.0; $filled = 0;
    foreach ($rows as $row) {
        $take = min($remaining, $row['volume_remain']);
        if (!$buyFromMarket && $take < min($row['min_volume'] ?? 1, $row['volume_remain'])) continue;
        $value += $take * $row['price']; $filled += $take; $remaining -= $take;
        if ($remaining === 0) break;
    }
    return ['requested'=>$quantity,'filled'=>$filled,'complete'=>$remaining === 0,'value'=>$remaining === 0 ? $value : null,'partial_value'=>$value,'average'=>$remaining === 0 ? $value / $quantity : null,'listed_volume'=>array_sum(array_column($rows,'volume_remain'))];
}
final class GuristasPublicIndustry {
    private GuristasEsiClient $esi;
    public function __construct(?GuristasEsiClient $esi = null) {
        $config = require dirname(__DIR__, 2) . '/config/esi.php';
        $this->esi = $esi ?? new GuristasEsiClient($config, new GuristasEsiCache(dirname(__DIR__, 2) . '/storage/cache/public-industry'));
    }
    public function offers(): array {
        try { $result = $this->esi->getJson('/loyalty/stores/1000437/offers', [], 3600); if (!is_array($result['data'])) throw new RuntimeException('Invalid offer response.'); return $result; }
        catch (Throwable $error) { return ['data'=>guri_industry_reference()['offers_snapshot'],'meta'=>['stale'=>true,'fetched_at'=>guri_industry_reference()['retrieved_at'],'snapshot'=>true]]; }
    }
    public function quote(string $hubKey, int $typeId, int $quantity, bool $includeHistory = false): array {
        $reference = guri_industry_reference();
        if (!isset($reference['hubs'][$hubKey],$reference['types'][$typeId]) || $quantity < 1 || $quantity > 1000000000) throw new InvalidArgumentException('Unsupported market request.');
        $hub = $reference['hubs'][$hubKey]; $path = '/markets/' . $hub['region_id'] . '/orders';
        $response = $this->esi->getJson($path, ['order_type'=>'all','type_id'=>$typeId,'page'=>1], 300);
        $pages = (int)($response['meta']['pages'] ?? 1);
        if ($pages > 20) throw new RuntimeException('Market report is too large to verify.');
        $orders = $response['data']; if (!is_array($orders)) throw new RuntimeException('Invalid order report.');
        $metas = [$response['meta']];
        for ($page = 2; $page <= $pages; $page++) {
            $next = $this->esi->getJson($path, ['order_type'=>'all','type_id'=>$typeId,'page'=>$page], 300);
            if ((int)($next['meta']['pages'] ?? 1) !== $pages || !is_array($next['data'])) throw new RuntimeException('Market pages changed. Refresh before using this quote.');
            $orders = array_merge($orders, $next['data']); $metas[] = $next['meta'];
        }
        $orders = array_values(array_filter($orders, static function ($o) use ($typeId) { return is_array($o) && ($o['type_id'] ?? null) === $typeId; }));
        $times = array_filter(array_column($metas,'fetched_at'));
        $oldest = $times ? (strtotime(min($times)) ?: 0) : 0;
        $newest = $times ? (strtotime(max($times)) ?: 0) : 0;
        $history = null;
        if ($includeHistory) {
            try {
                $report = $this->esi->getJson('/markets/' . $hub['region_id'] . '/history', ['type_id'=>$typeId], 3600);
                $today = gmdate('Y-m-d'); $start = gmdate('Y-m-d', time() - 7 * 86400);
                $days = array_values(array_filter(is_array($report['data']) ? $report['data'] : [], static function ($row) use ($today,$start) { return is_array($row) && is_string($row['date'] ?? null) && $row['date'] >= $start && $row['date'] < $today && is_int($row['volume'] ?? null) && $row['volume'] >= 0; }));
                $history = ['days'=>count($days),'average_volume'=>count($days) ? array_sum(array_column($days,'volume')) / count($days) : null,'stale'=>!empty($report['meta']['stale']),'fetched_at'=>$report['meta']['fetched_at'] ?? null];
            } catch (Throwable $error) { /* History failing does not discard a valid order quote. */ }
        }
        return ['hub'=>$hub,'type_id'=>$typeId,'quantity'=>$quantity,'buy'=>guri_order_fill($orders,$hub['station_id'],$quantity,true),'sell'=>guri_order_fill($orders,$hub['station_id'],$quantity,false),'history'=>$history,'meta'=>['stale'=>in_array(true,array_column($metas,'stale'),true) || count($times) !== count($metas) || $oldest < time() - 900 || $newest > time() + 60,'fetched_at'=>$times ? min($times) : null,'pages'=>$pages]];
    }
}
