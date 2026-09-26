<?php

// Guristas.net data foundation - PHP 8.0 compatible build 2

declare(strict_types=1);

final class GuristasUniverseService
{
    public const VENAL_REGION_ID = 10000015;

    /** @var GuristasEsiClient */
    private $esi;

    /** @var GuristasSourceService */
    private $sources;

    public function __construct(GuristasEsiClient $esi, GuristasSourceService $sources)
    {
        $this->esi = $esi;
        $this->sources = $sources;
    }

    public function region(int $regionId): array
    {
        $endpoint = '/universe/regions/' . $regionId . '/';
        $result = $this->esi->getJson($endpoint, [], 86400);

        return [
            'data' => $result['data'],
            'meta' => $result['meta'],
            'sources' => [$this->sources->esi($endpoint, $result['meta'])],
        ];
    }

    public function venal(): array
    {
        return $this->region(self::VENAL_REGION_ID);
    }

    public function solarSystem(int $systemId): array
    {
        $endpoint = '/universe/systems/' . $systemId . '/';
        $result = $this->esi->getJson($endpoint, ['language' => 'en'], 86400);

        return [
            'data' => $result['data'],
            'meta' => $result['meta'],
            'sources' => [$this->sources->esi($endpoint, $result['meta'])],
        ];
    }

    public function tranquilityStatus(): array
    {
        $endpoint = '/status/';
        $result = $this->esi->getJson($endpoint, [], 30);

        return [
            'data' => $result['data'],
            'meta' => $result['meta'],
            'sources' => [$this->sources->esi($endpoint, $result['meta'])],
        ];
    }
}
