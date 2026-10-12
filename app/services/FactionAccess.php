<?php
declare(strict_types=1);
/** Authenticated faction posture. This never elevates trust based on names or standings. */
final class GuristasFactionAccess
{
    public const FACTIONS = [500010=>'guristas',500011=>'angel',500001=>'caldari',500004=>'gallente',500003=>'amarr',500002=>'minmatar'];
    public static function classify(?int $factionId, bool $verified, int $corporationId=0, int $allianceId=0, array $trusted=[]): array
    {
        if (!$verified) return ['posture'=>'unknown','faction_id'=>null,'tier'=>'public'];
        $posture=$factionId === null ? 'neutral' : (self::FACTIONS[$factionId] ?? 'other');
        $corpIds=array_map('intval', $trusted['corporation_ids'] ?? []);
        $allianceIds=array_map('intval', $trusted['alliance_ids'] ?? []);
        $isTrusted=$posture==='guristas' && (($corporationId>0 && in_array($corporationId,$corpIds,true)) || ($allianceId>0 && in_array($allianceId,$allianceIds,true)));
        return ['posture'=>$posture,'faction_id'=>$factionId,'tier'=>$isTrusted?'trusted':(in_array($posture,['guristas','angel'],true)?'pirate':'public')];
    }
    public static function resolve(?array $viewer): array
    {
        if (!$viewer) return ['posture'=>'guest','faction_id'=>null,'tier'=>'public'];
        require_once __DIR__.'/EveAuth.php';
        require_once __DIR__.'/EsiCache.php';
        require_once __DIR__.'/EsiClient.php';
        eve_session();
        $id=(int)($viewer['character_id'] ?? 0);
        if ($id<1) return self::classify(null,false);
        $stamp=$_SESSION['guristas_faction_check'] ?? null;
        if (is_array($stamp) && ($stamp['character_id']??0)===$id && ($stamp['until']??0)>time() && is_array($stamp['result']??null)) return $stamp['result'];
        $result=self::classify(null,false);
        try {
            $grant=eve_authorized_esi_grant($id,eve_feature_scopes('fw')[0]);
            $config=require dirname(__DIR__,2).'/config/esi.php';
            $cache=new GuristasEsiCache(dirname(__DIR__,2).'/storage/cache/pilot/'.eve_token_table());
            $response=(new GuristasEsiClient($config,$cache))->getCharacterJson($id,'fw/stats',$grant['token'],$grant['identity']);
            if (empty($response['meta']['stale']) && is_array($response['data'])) {
                require_once __DIR__.'/PilotDataService.php';
                $fw=GuristasPilotDataService::factionWarfare($response['data']);
                $file=dirname(__DIR__,2).'/config/faction-access.local.php';
                $trust=is_file($file)?require $file:[];
                $result=self::classify($fw['faction_id'],true,(int)($viewer['corporation_id']??0),(int)($viewer['alliance_id']??0),is_array($trust)?$trust:[]);
            }
        } catch (Throwable $error) {
            // An unavailable or expired ESI response is UNKNOWN, never an enemy conviction.
            error_log('Guristas faction status unavailable for authenticated character '.$id);
        }
        $_SESSION['guristas_faction_check']=['character_id'=>$id,'until'=>time()+($result['posture']==='unknown'?60:180),'result'=>$result];
        return $result;
    }
    /** RP preview is presentation only. Never use it for authorization checks. */
    public static function previewForOwner(?array $viewer, array $actual): array
    {
        if (!$viewer || (int)($viewer['character_id'] ?? 0) < 1) return $actual;
        require_once __DIR__.'/TicketService.php';
        if ((int)$viewer['character_id'] !== tickets_owner() || tickets_owner() < 1) return $actual;
        eve_session();
        $requested=$_SESSION['guristas_faction_visual_preview'] ?? '';
        $choices=['guest','neutral','unknown','guristas','angel','caldari','gallente','amarr','minmatar'];
        if (!in_array($requested,$choices,true)) return $actual;
        if ($requested==='guest') return ['posture'=>'guest','tier'=>'public','faction_id'=>null,'preview'=>true];
        if ($requested==='unknown') return ['posture'=>'unknown','tier'=>'public','faction_id'=>null,'preview'=>true];
        $ids=array_flip(self::FACTIONS);
        return ['posture'=>$requested,'tier'=>in_array($requested,['guristas','angel'],true)?'pirate':'public',
            'faction_id'=>$ids[$requested] ?? null,'preview'=>true];
    }
    public static function permits(array $status,string $capability): bool
    {
        $posture=$status['posture']??'unknown';
        if ($capability==='public') return true;
        if ($capability==='pirate') return in_array($posture,['guristas','angel'],true);
        if ($capability==='guristas') return $posture==='guristas';
        if ($capability==='trusted') return $posture==='guristas' && ($status['tier']??'')==='trusted';
        return false;
    }
    public static function requireCapability(?array $viewer,string $capability): array
    {
        $status=self::resolve($viewer);
        if (!self::permits($status,$capability)) {
            http_response_code($viewer?403:401);
            header('Cache-Control: private, no-store');
            header('Vary: Cookie');
            throw new RuntimeException('Guristas access denied.');
        }
        return $status;
    }
}
