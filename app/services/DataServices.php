<?php
declare(strict_types=1);

/** Shared service construction for API routes and CLI collectors. */
final class GuristasDataServices
{
    public const HISTORY_RETENTION_HOURS = 720;
    private array $services = [];
    private ?array $config = null;

    private function config(): array
    {
        return $this->config ??= require dirname(__DIR__, 2).'/config/esi.php';
    }

    private function esi(): GuristasEsiClient
    {
        require_once __DIR__.'/EsiCache.php';
        require_once __DIR__.'/EsiClient.php';
        $config = $this->config();
        return $this->services['esi'] ??= new GuristasEsiClient(
            $config, new GuristasEsiCache($config['cache_dir'])
        );
    }

    private function sources(): GuristasSourceService
    {
        require_once __DIR__.'/SourceService.php';
        return $this->services['sources'] ??= new GuristasSourceService();
    }

    private function derivedCache(): GuristasEsiCache
    {
        require_once __DIR__.'/EsiCache.php';
        return $this->services['derived'] ??= new GuristasEsiCache($this->config()['derived_cache_dir']);
    }

    public function universe(): GuristasUniverseService
    {
        require_once __DIR__.'/UniverseService.php';
        return $this->services['universe'] ??= new GuristasUniverseService($this->esi(), $this->sources());
    }

    public function venal(): GuristasVenalService
    {
        require_once __DIR__.'/VenalService.php';
        return $this->services['venal'] ??= new GuristasVenalService($this->esi(), $this->sources(), $this->derivedCache());
    }

    public function zarzakh(): GuristasZarzakhService
    {
        require_once __DIR__.'/ZarzakhService.php';
        return $this->services['zarzakh'] ??= new GuristasZarzakhService($this->esi(), $this->sources(), $this->derivedCache());
    }

    public function frontlines(): GuristasFrontlinesService
    {
        require_once __DIR__.'/FrontlinesService.php';
        require_once __DIR__.'/EsiCache.php';
        return $this->services['frontlines'] ??= new GuristasFrontlinesService(
            $this->esi(),
            new GuristasEsiCache(dirname(__DIR__, 2).'/storage/cache/frontlines'),
            $this->derivedCache(),
            $this->config()
        );
    }

    public function stream(): GuristasStreamIntelligenceService
    {
        require_once __DIR__.'/StreamIntelligenceService.php';
        return $this->services['stream'] ??= new GuristasStreamIntelligenceService(
            $this->esi(), $this->venal(), $this->frontlines()
        );
    }

    public function history(): GuristasVenalActivityHistory
    {
        require_once __DIR__.'/VenalActivityHistory.php';
        return $this->services['history'] ??= new GuristasVenalActivityHistory(
            dirname(__DIR__, 2).'/storage/history/venal', self::HISTORY_RETENTION_HOURS
        );
    }
}
